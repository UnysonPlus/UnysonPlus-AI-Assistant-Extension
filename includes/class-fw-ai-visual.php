<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Visual check: compare a page of this site with a source / reference page, the way a person would by
 * putting the two side by side. The capture service (the AI Dev Kit on the editor\'s computer) renders
 * both in a real browser and measures them two ways, which answer different questions:
 *
 *   - bands:    both pages sliced into horizontal strips and pixel-diffed — "how far apart do they
 *               look, and where roughly?" One number per strip, so it points, it does not explain.
 *   - sections: the pages paired section by section, and inside each pair the elements matched by
 *               their text / image file — "which section lost an item, moved a heading, changed its
 *               column count, changed colour?" This is the list an AI can act on.
 *
 * The raw answers are large; summarize() trims them to what fits in a model's context (worst strips,
 * sections that have findings, a few findings each).
 *
 * Reaching the service: the WordPress server calls it when it can (a site on the same computer). A live
 * site cannot reach the editor's computer, so the ability then answers `reason: service_unreachable` with
 * the exact request; the chat panel's local-AI loop runs in the editor's browser, which CAN reach it, so
 * it sends that request itself and calls the ability again with `measured` for the summary.
 *
 * Drafts: the service browses as a signed-out visitor, so a draft gets a preview link that works for
 * 15 minutes for that one post only (upw_ai_view token, posts_results shows it as published for that
 * request, noindex + no-cache).
 */
class FW_AI_Visual {

	const PREVIEW_PREFIX = 'upw_ai_pv_';
	const PREVIEW_TTL    = 900;
	const WIDTHS         = array( 'desktop' => 1440, 'tablet' => 834, 'mobile' => 390 );

	/** @var int Post id a valid preview token unlocked on this request. */
	private static $preview_post = 0;

	public static function init() {
		add_filter( 'posts_results', array( __CLASS__, 'preview_posts' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ *
	 * Draft preview for the renderer
	 * ------------------------------------------------------------------ */

	/**
	 * A URL the capture service can open for this post, published or not.
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	public static function view_url( WP_Post $post ) {
		if ( $post->post_status === 'publish' && $post->post_password === '' ) {
			return get_permalink( $post );
		}
		$token = wp_generate_password( 32, false );
		set_transient( self::PREVIEW_PREFIX . hash( 'sha256', $token ), (int) $post->ID, self::PREVIEW_TTL );
		$base = add_query_arg( $post->post_type === 'page' ? 'page_id' : 'p', $post->ID, home_url( '/' ) );
		if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			$base = add_query_arg( array( 'post_type' => $post->post_type, 'p' => $post->ID ), home_url( '/' ) );
		}
		return add_query_arg( 'upw_ai_view', $token, $base );
	}

	/**
	 * `posts_results`: on the main query, a valid token shows its one post as if published.
	 *
	 * @param WP_Post[] $posts
	 * @param WP_Query  $query
	 * @return WP_Post[]
	 */
	public static function preview_posts( $posts, $query ) {
		if ( empty( $_GET['upw_ai_view'] ) || ! $query->is_main_query() || is_admin() || count( $posts ) !== 1 ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $posts;
		}
		$id = (int) get_transient( self::PREVIEW_PREFIX . hash( 'sha256', (string) wp_unslash( $_GET['upw_ai_view'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || (int) $posts[0]->ID !== $id ) {
			return $posts;
		}
		$posts[0]->post_status   = 'publish';
		$posts[0]->post_password = '';
		self::$preview_post      = $id;
		add_action( 'send_headers', 'nocache_headers' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'show_admin_bar', '__return_false' );
		return $posts;
	}

	/* ------------------------------------------------------------------ *
	 * The ability
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in { source_url, post_id?, url?, device?, measured? }
	 * @return array|WP_Error
	 */
	public static function check( $in ) {
		$source = esc_url_raw( trim( (string) ( $in['source_url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( $source === '' ) {
			return new WP_Error( 'upw_ai_visual_source', 'source_url must be an http(s) address: the page to compare against.' );
		}
		$device = isset( self::WIDTHS[ $in['device'] ?? '' ] ) ? $in['device'] : 'desktop';

		if ( ! empty( $in['post_id'] ) ) {
			$post = get_post( (int) $in['post_id'] );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'upw_ai_visual_post', 'No page with that post_id that you can edit.' );
			}
			$target = self::view_url( $post );
			$label  = get_the_title( $post ) . ' (' . $post->post_status . ')';
		} else {
			$target = esc_url_raw( trim( (string) ( $in['url'] ?? home_url( '/' ) ) ), array( 'http', 'https' ) );
			if ( wp_parse_url( $target, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new WP_Error( 'upw_ai_visual_url', 'url must be a page of this site (or pass post_id).' );
			}
			$label = $target;
		}

		$request = array(
			'source_url'    => $source,
			'converted_url' => $target,
			'lens'          => 'both',
			'width'         => self::WIDTHS[ $device ],
		);

		// The browser already measured (the panel's local-AI loop): only summarize.
		if ( ! empty( $in['measured'] ) && is_array( $in['measured'] ) ) {
			return self::summarize( $in['measured'], $source, $label, $device );
		}

		$svc  = FW_AI_Build::capture_service_url();
		$resp = wp_remote_post( $svc . '/verify', array(
			'timeout' => 300,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $request ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'             => false,
				'reason'         => 'service_unreachable',
				'message'        => 'The capture service (AI Dev Kit) is not reachable from this server. It runs on the editor\'s computer, so a live site cannot call it: run the check from the chat panel with local AI (the browser calls the kit), or on a site on the same computer. Start the kit if it is not running. If you have the tool measure_pages (the AI Dev Kit on the editor\'s computer), call it with the fields of verify_request.body, then call visual_check again with the same arguments plus measured = its result.',
				'verify_request' => array( 'path' => '/verify', 'body' => $request ),
			);
		}
		$raw = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $raw ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			$err = is_array( $raw ) && ! empty( $raw['error'] ) ? $raw['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
			return new WP_Error( 'upw_ai_visual_failed', 'The capture service could not compare the pages: ' . $err . ( wp_remote_retrieve_response_code( $resp ) === 200 ? '' : ' (update the AI Dev Kit if this is an older version).' ) );
		}
		return self::summarize( $raw, $source, $label, $device );
	}

	/**
	 * Trim the service's answer to what a model can use.
	 *
	 * @param array  $raw    { bands, sections } (lens both), or a single lens.
	 * @param string $source
	 * @param string $label
	 * @param string $device
	 * @return array
	 */
	public static function summarize( array $raw, $source, $label, $device ) {
		$bands    = isset( $raw['bands'] ) && is_array( $raw['bands'] ) && isset( $raw['bands']['bands'] ) ? $raw['bands'] : ( isset( $raw['overall_drift_pct'] ) ? $raw : null );
		$sections = isset( $raw['sections'] ) && is_array( $raw['sections'] ) && isset( $raw['sections']['findings'] ) ? $raw['sections'] : ( isset( $raw['findings'] ) ? $raw : null );

		$out = array(
			'ok'       => true,
			'compared' => array( 'source' => $source, 'page' => $label, 'device' => $device ),
		);

		if ( $bands ) {
			if ( isset( $bands['ok'] ) && ! $bands['ok'] && ! empty( $bands['error'] ) ) {
				return array( 'ok' => false, 'reason' => 'render_failed', 'message' => (string) $bands['error'] );
			}
			$rows = (array) ( $bands['bands'] ?? array() );
			usort( $rows, function ( $a, $b ) {
				return ( $b['drift_pct'] ?? 0 ) <=> ( $a['drift_pct'] ?? 0 );
			} );
			$out['look'] = array(
				'overall_difference_pct' => $bands['overall_drift_pct'] ?? null,
				'height'                 => array(
					'source_px'    => $bands['source']['height'] ?? null,
					'this_page_px' => $bands['converted']['height'] ?? null,
					'delta_pct'    => $bands['height_delta_pct'] ?? null,
				),
				'most_different_strips'  => array_map( function ( $r ) {
					return array( 'from_y' => $r['y0'] ?? 0, 'to_y' => $r['y1'] ?? 0, 'difference_pct' => $r['drift_pct'] ?? 0 );
				}, array_slice( $rows, 0, 3 ) ),
			);
		}

		if ( $sections ) {
			$list = array();
			$n    = 0;
			foreach ( (array) ( $sections['sections'] ?? array() ) as $s ) {
				$n++;
				$f = (array) ( $s['findings'] ?? array() );
				if ( ! $f ) {
					continue;
				}
				$row = array(
					'order'    => $n,
					'section'  => (string) ( $s['id'] ?? '' ),
					'findings' => array_map( array( __CLASS__, 'finding' ), array_slice( $f, 0, 8 ) ),
				);
				if ( count( $f ) > 8 ) {
					$row['more'] = count( $f ) - 8;
				}
				if ( ! empty( $s['missing'] ) ) {
					$row['missing'] = true;
				} elseif ( isset( $s['srcH'], $s['convH'] ) ) {
					$row['height_px'] = array( 'source' => $s['srcH'], 'this_page' => $s['convH'] );
				}
				$list[] = $row;
				if ( count( $list ) >= 12 ) {
					break;
				}
			}
			$out['sections'] = array(
				'total_findings'   => (int) ( $sections['total'] ?? count( (array) ( $sections['findings'] ?? array() ) ) ),
				'by_kind'          => (array) ( $sections['byKind'] ?? array() ),
				'sections_checked' => $n,
				'with_findings'    => $list,
			);
		}

		$out['how_to_read'] = 'Sections are paired in page order ("order" 1 = the first section of the source, "chrome:header" / "chrome:footer" = header and footer, which live in the theme, not the page). Kinds: section-missing (the whole section is absent), missing / img-missing / icon-missing (an item of the source is absent here), extra* (here but not in the source), moved (same item, different place), grid-cols (a repeated group changed its column count), text / type / ink / skin (wording, font, colour, background differ), gap (spacing differs), height-delta (the section is much taller or shorter). Fix the page with get_page / update_element / insert_items, then run visual_check again. overall_difference_pct under about 10 is usually a close match (fonts and images anti-alias differently); section findings are the list to act on.';
		return $out;
	}

	/**
	 * One finding, trimmed: its kind plus the few fields that say what and where.
	 *
	 * @param mixed $f
	 * @return array
	 */
	private static function finding( $f ) {
		if ( ! is_array( $f ) ) {
			return array( 'kind' => 'note', 'text' => mb_substr( (string) $f, 0, 160 ) );
		}
		$keep = array();
		foreach ( array( 'kind', 'text', 'note', 'key', 'to', 'count', 'source', 'converted', 'dx', 'dy', 'delta_pct', 'diffs' ) as $k ) {
			if ( ! isset( $f[ $k ] ) ) {
				continue;
			}
			$v = $f[ $k ];
			if ( is_string( $v ) ) {
				$v = mb_substr( $v, 0, 120 );
			} elseif ( is_array( $v ) ) {
				$j = wp_json_encode( $v );
				$v = strlen( $j ) > 200 ? substr( $j, 0, 200 ) . '…' : $v;
			}
			$keep[ $k ] = $v;
		}
		return $keep;
	}
}
