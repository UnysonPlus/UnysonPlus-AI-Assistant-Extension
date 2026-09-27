<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site-building helpers for the AI: templates (Template Library + the builder's own saved templates)
 * and whole-site conversion (Site Converter).
 *
 * Template ids:
 *   lib:<slug>                 — Template Library (bundled, installed, or available to install)
 *   saved:<full|section|column>:<md5> — a template saved from the builder ("Save as template")
 */
class FW_AI_Build {

	/** Builder saved-template option prefixes, by kind. */
	const SAVED_PREFIX = array(
		'full'    => 'fw:bt:f:page-builder:',
		'section' => 'fw:bt:s:page-builder:',
		'column'  => 'fw:bt:c:page-builder:',
	);

	/* ------------------------------------------------------------------ *
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $kind   full | section | column | '' for all.
	 * @param string $search Title / category filter.
	 * @return array
	 */
	public static function list_templates( $kind = '', $search = '' ) {
		$out = array();

		if ( function_exists( 'fw_tpl_lib_installer_items' ) ) {
			foreach ( (array) fw_tpl_lib_installer_items() as $slug => $t ) {
				$out[] = array(
					'id'          => 'lib:' . ( $t['slug'] ?? $slug ),
					'title'       => (string) ( $t['title'] ?? $slug ),
					'kind'        => (string) ( $t['kind'] ?? '' ),
					'category'    => (string) ( $t['category'] ?? '' ),
					'description' => wp_html_excerpt( (string) ( $t['description'] ?? '' ), 160, '…' ),
					'state'       => (string) ( $t['state'] ?? '' ),
				);
			}
		}

		global $wpdb;
		foreach ( self::SAVED_PREFIX as $k => $prefix ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
				$wpdb->esc_like( $prefix ) . '%'
			) );
			foreach ( $rows as $r ) {
				$v = maybe_unserialize( $r->option_value );
				if ( ! is_array( $v ) || empty( $v['json'] ) ) {
					continue;
				}
				$out[] = array(
					'id'       => 'saved:' . $k . ':' . substr( $r->option_name, strlen( $prefix ) ),
					'title'    => (string) ( $v['title'] ?? '' ),
					'kind'     => $k,
					'category' => __( 'Saved in this site', 'fw' ),
					'state'    => 'saved',
				);
			}
		}

		return array_values( array_filter( $out, static function ( $t ) use ( $kind, $search ) {
			if ( $kind !== '' && $t['kind'] !== $kind ) {
				return false;
			}
			return $search === '' || stripos( $t['title'] . ' ' . $t['category'] . ' ' . ( $t['description'] ?? '' ), $search ) !== false;
		} ) );
	}

	/**
	 * @param string $id
	 * @return array|WP_Error { kind, title, items }
	 */
	public static function get_template( $id ) {
		if ( strpos( $id, 'lib:' ) === 0 ) {
			$slug = sanitize_title( substr( $id, 4 ) );
			if ( ! function_exists( 'fw_tpl_lib_registered_templates' ) ) {
				return new WP_Error( 'upw_ai_no_library', 'The Template Library extension is not active.' );
			}
			$reg = fw_tpl_lib_registered_templates();
			if ( ! isset( $reg[ $slug ] ) ) {
				// Available in the catalog but not downloaded yet: install it first (admins only).
				if ( ! function_exists( 'fw_tpl_lib_install' ) || ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'upw_ai_tpl_not_installed', 'That template is not installed, and installing it needs an administrator.' );
				}
				$done = fw_tpl_lib_install( $slug );
				if ( is_wp_error( $done ) ) {
					return $done;
				}
				// fw_tpl_lib_registered_templates() caches for the request, so read the fresh install directly.
				$meta = function_exists( 'fw_tpl_lib_installed_meta' ) ? fw_tpl_lib_installed_meta( $slug ) : null;
				$kind = ( $meta && isset( $meta['kind'] ) ) ? (string) $meta['kind'] : 'section';
				$json = function_exists( 'fw_tpl_lib__read_envelope_json' )
					? fw_tpl_lib__read_envelope_json( trailingslashit( fw_tpl_lib_install_dir() ) . $slug . '/template.json', $kind )
					: '';
				if ( $json === '' ) {
					return new WP_Error( 'upw_ai_tpl_missing', 'The template installed but could not be read.' );
				}
				$reg[ $slug ] = array(
					'title' => ( $meta && isset( $meta['title'] ) ) ? (string) $meta['title'] : $slug,
					'kind'  => $kind,
					'json'  => $json,
				);
			}
			$t    = $reg[ $slug ];
			$kind = (string) ( $t['kind'] ?? 'section' );
			$json = (string) ( $t['json'] ?? '' );
			$name = (string) ( $t['title'] ?? $slug );
		} elseif ( preg_match( '/^saved:(full|section|column):([a-f0-9]+)$/', $id, $m ) ) {
			$v = get_option( self::SAVED_PREFIX[ $m[1] ] . $m[2] );
			if ( ! is_array( $v ) || empty( $v['json'] ) ) {
				return new WP_Error( 'upw_ai_tpl_missing', 'No saved template with that id.' );
			}
			$kind = $m[1];
			$json = (string) $v['json'];
			$name = (string) ( $v['title'] ?? '' );
		} else {
			return new WP_Error( 'upw_ai_bad_tpl', 'Template ids look like lib:<slug> or saved:<kind>:<id> — see list_templates.' );
		}

		$tree = json_decode( $json, true );
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'upw_ai_tpl_broken', 'The template\'s content could not be read.' );
		}
		// A section / column template is one item; a full template is a list of items.
		$items = array_keys( $tree ) === range( 0, count( $tree ) - 1 ) ? $tree : array( $tree );
		return array( 'kind' => $kind, 'title' => $name, 'items' => self::fresh_ids( $items ) );
	}

	/**
	 * New unique_ids throughout, so the same template can be inserted twice (like the builder does).
	 *
	 * @param array $items
	 * @return array
	 */
	private static function fresh_ids( array $items ) {
		foreach ( $items as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['atts'] ) && is_array( $node['atts'] ) && isset( $node['atts']['unique_id'] ) ) {
				$node['atts']['unique_id'] = FW_AI_Schema::unique_id();
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				$node['_items'] = self::fresh_ids( $node['_items'] );
			}
		}
		unset( $node );
		return $items;
	}

	/**
	 * Insert (or, for a full template, optionally replace the page with) a template.
	 *
	 * Template content is trusted as-is (it may carry options from older element versions), so only
	 * PLACEMENT is checked: page-root items must be root types, a column must go into a section / row.
	 *
	 * @param array $in { post_id, template_id, parent_path?, position?, replace? }
	 * @return array|WP_Error
	 */
	public static function apply_template( array $in ) {
		$post_id = (int) $in['post_id'];
		$tpl     = self::get_template( (string) $in['template_id'] );
		if ( is_wp_error( $tpl ) ) {
			return $tpl;
		}
		$tree = FW_AI_Store::get_tree( $post_id );

		if ( ! empty( $in['replace'] ) ) {
			if ( $tpl['kind'] !== 'full' ) {
				return new WP_Error( 'upw_ai_tpl_replace', 'Only a full-page template can replace the page; insert this one instead.' );
			}
			$rev = FW_AI_Store::save_tree( $post_id, $tpl['items'], 'unysonplus/apply-template', sprintf( 'Replaced the page with template "%s"', $tpl['title'] ) );
			return is_wp_error( $rev ) ? $rev : self::result( $post_id, $rev, sprintf( 'Replaced the page with "%s".', $tpl['title'] ) );
		}

		$parent_idx  = array();
		$parent_type = '';
		if ( ! empty( $in['parent_path'] ) ) {
			$parent_idx = FW_AI_Store::resolve( $tree, (string) $in['parent_path'] );
			if ( is_wp_error( $parent_idx ) ) {
				return $parent_idx;
			}
			$parent_type = (string) ( FW_AI_Store::node( $tree, $parent_idx )['type'] ?? '' );
		}
		foreach ( $tpl['items'] as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			if ( $parent_type === '' && ! in_array( $type, FW_AI_Schema::ROOT_TYPES, true ) ) {
				return new WP_Error( 'upw_ai_tpl_place', sprintf( 'This %s template needs a parent_path (a %s cannot sit at the page root).', $tpl['kind'], $type ) );
			}
			if ( $type === 'column' && ! in_array( $parent_type, array( 'section', 'row' ), true ) ) {
				return new WP_Error( 'upw_ai_tpl_place', 'A column template must go into a section or row (parent_path).' );
			}
		}

		$list =& FW_AI_Store::children( $tree, $parent_idx );
		$pos  = isset( $in['position'] ) ? max( 0, min( (int) $in['position'], count( $list ) ) ) : count( $list );
		array_splice( $list, $pos, 0, $tpl['items'] );
		unset( $list );

		$rev = FW_AI_Store::save_tree( $post_id, $tree, 'unysonplus/apply-template', sprintf( 'Inserted template "%s"', $tpl['title'] ) );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		$paths = array();
		foreach ( array_keys( $tpl['items'] ) as $k ) {
			$paths[] = FW_AI_Store::path_str( array_merge( $parent_idx, array( $pos + $k ) ) );
		}
		return self::result( $post_id, $rev, sprintf( 'Inserted "%s".', $tpl['title'] ), array( 'inserted_paths' => $paths ) );
	}

	/**
	 * @param int    $post_id
	 * @param int    $rev
	 * @param string $message
	 * @param array  $extra
	 * @return array
	 */
	private static function result( $post_id, $rev, $message, array $extra = array() ) {
		return array_merge( array(
			'ok'               => true,
			'message'          => $message . ' Replace its placeholder text and images with the site\'s own content, then run render_check.',
			'post_id'          => (int) $post_id,
			'undo_revision_id' => (int) $rev,
		), $extra, array( 'outline' => FW_AI_Store::outline( FW_AI_Store::get_tree( $post_id ) ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Site conversion
	 * ------------------------------------------------------------------ */

	/**
	 * @return string The capture service base URL (the Site Converter's default).
	 */
	public static function capture_service_url() {
		/** Filters the capture service URL the AI Assistant's convert-url ability renders pages with. */
		return untrailingslashit( (string) apply_filters( 'fw_ai_assistant_capture_service_url', 'http://localhost:8787' ) );
	}

	/**
	 * Convert a URL into this site with the Site Converter (the same pipeline as its admin screen):
	 * a new child theme, Theme Settings and pages. Rendered by the capture service when it is running
	 * (full browser render), otherwise from the raw HTML.
	 *
	 * @param array $in { url, confirm?, dry_run? }
	 * @return array|WP_Error
	 */
	public static function convert_url( array $in ) {
		$ext = fw_ext( 'site-converter' );
		if ( ! $ext || ! method_exists( $ext, 'run_url_conversion' ) ) {
			return new WP_Error( 'upw_ai_no_converter', 'The Site Converter extension is not active.' );
		}
		$url = esc_url_raw( trim( (string) $in['url'] ) );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'upw_ai_bad_url', 'Pass a full http(s) URL.' );
		}
		$dry = ! empty( $in['dry_run'] );
		if ( ! $dry && empty( $in['confirm'] ) ) {
			return new WP_Error( 'upw_ai_confirm', 'Converting replaces pages with the same slugs, can set the front page, and generates and ACTIVATES a new child theme. Tell the user exactly that, get their explicit agreement, then retry with confirm: true (or use dry_run: true to test without changing the site).' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$opts     = array( 'dry_run' => $dry );
		$rendered = false;
		$svc      = self::capture_service_url();
		$health   = wp_remote_get( $svc . '/health', array( 'timeout' => 3 ) );
		if ( ! is_wp_error( $health ) && (int) wp_remote_retrieve_response_code( $health ) === 200 ) {
			$cap = wp_remote_get( $svc . '/capture?single=1&html=1&url=' . rawurlencode( $url ), array( 'timeout' => 240 ) );
			if ( ! is_wp_error( $cap ) && (int) wp_remote_retrieve_response_code( $cap ) === 200 ) {
				$html = (string) wp_remote_retrieve_body( $cap );
				if ( stripos( $html, '<html' ) !== false || stripos( $html, '<body' ) !== false ) {
					$opts['rendered_html'] = $html;
					$rendered              = true;
				}
			}
		}

		$res = $ext->run_url_conversion( $url, $opts );
		if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
			return new WP_Error( 'upw_ai_convert_failed', 'The conversion failed: ' . ( is_array( $res ) ? (string) $res['error'] : 'unknown error' ) );
		}
		return array(
			'ok'            => true,
			'dry_run'       => $dry,
			'rendered_with' => $rendered ? 'capture service (full browser render)' : 'raw HTML fetch (start the capture service for client-rendered sites)',
			'theme'         => (string) $res['theme_name'],
			'theme_slug'    => (string) $res['theme_slug'],
			'activated'     => (bool) $res['activated'],
			'pages_created' => (int) $res['pages_created'],
			'home_url'      => (string) $res['home_url'],
			'next'          => $dry ? 'Dry run only — nothing on the site changed.' : 'Run site_info to see the new pages, then render_check each one and fix what it reports.',
		);
	}
}
