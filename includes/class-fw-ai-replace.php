<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Find and replace text across the whole site, preview first.
 *
 * Where it looks: the page-builder content of every post that has it (pages, posts, templates, any post
 * type), post titles and excerpts, and Theme Settings text (footer copyright, top-bar phone number …).
 *
 * What it never touches: option values that are not words a visitor reads — ids, classes, CSS, colours,
 * icons, images, videos, fonts, animation settings, sizes — and, unless include_links is set, links and
 * URLs (a phone number or e-mail address in a tel: / mailto: link is the usual reason to set it). In
 * HTML text only the text between tags changes, never a tag or an attribute.
 *
 * Two steps, so nothing changes unseen: a call without `apply` is the preview — every change with its
 * page, field and a before / after snippet — and returns a plan code; calling again with apply: <plan>
 * makes exactly those changes. A page edited in between (its match count differs) is skipped rather
 * than changed blind. Every page is snapshotted (FW_AI_Store::save_tree), titles and excerpts are one
 * toolkit snapshot, Theme Settings one settings revision — all listed on AI Changes with Undo.
 */
class FW_AI_Replace {

	const PLAN_PREFIX = 'upw_ai_rp_';
	const PLAN_TTL    = 1800;
	const MAX_POSTS   = 500;
	const MAX_ROWS    = 60;

	/** Keys whose values are settings, not visitor-facing words (matched on each key of the path). */
	const TECH_KEY = '/^(?:_?id|unique_id|.*_id|class(?:es)?|.*_class|.*css.*|.*colou?r.*|.*gradient.*|anchor|html_tag|tag|.*icon.*|.*image.*|img|.*video.*|.*lottie.*|.*rive.*|.*svg.*|src|.*font.*|.*animation.*|.*motion.*|.*effect.*|style|.*_style|attr.*|.*attr|width|height|.*_size|size|.*align.*|layout|type|.*preset.*|.*breakpoint.*|.*shape.*|.*mask.*|code|js|json|data|.*filter.*|.*shadow.*|.*border.*|.*radius.*|.*padding.*|.*margin.*|.*spacing.*|.*opacity.*|.*z_index.*|.*ratio.*|target|rel|.*target|.*easing.*|.*duration.*|.*delay.*|.*speed.*|mode|.*_mode|variant|.*_variant|source|.*source|format|.*format|display|.*gap.*|justify.*|.*direction.*|.*wrap.*|order|flex.*|grid.*|.*overflow.*|visibility|cursor|.*transition.*|.*transform.*|.*_fit|.*sticky.*|unit|.*_unit|.*_width|.*_height|max_.*|min_.*|.*visible.*|.*hide.*|.*show_.*|.*enabled?|.*toggle.*|.*loop.*|.*autoplay.*|.*_count|count|.*_number|.*items_per.*)$/i';

	/**
	 * Groups skipped whole: areas that hold no visitor-facing words. Deliberately narrow — a group key
	 * like "copyright_columns" can hold the footer text, so TECH_KEY applies only to single values.
	 */
	const TECH_GROUP = '/^(?:.*icon.*|.*image.*|img|.*video.*|.*lottie.*|.*rive.*|.*svg.*|.*css.*|.*font.*|.*typography.*|.*animation.*|.*motion.*|.*colou?r.*|.*gradient.*|.*border.*|.*padding.*|.*margin.*|.*spacing.*|.*shadow.*|.*filter.*|.*mask.*|.*shape.*|attr.*|.*attr)$/i';

	/** Keys that hold a link: skipped unless include_links. */
	const LINK_KEY = '/^(?:link|.*_link|url|.*_url|href|.*href|mailto|tel|phone_link)$/i';

	/* ------------------------------------------------------------------ *
	 * The ability
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in { find, replace, match_case?, whole_word?, include_links?, post_types?, post_ids?, apply?, skip_posts? }
	 * @return array|WP_Error
	 */
	public static function run( $in ) {
		if ( ! empty( $in['apply'] ) ) {
			return self::apply( (string) $in['apply'], array_map( 'intval', (array) ( $in['skip_posts'] ?? array() ) ) );
		}
		$o = self::options( $in );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		$scan = self::scan( $o );

		$rows = array();
		foreach ( $scan['changes'] as $c ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			$rows[] = $c;
		}
		$total = count( $scan['changes'] );
		if ( ! $total ) {
			return array(
				'ok'      => true,
				'matches' => 0,
				'also'    => self::identity_note( $o ),
				'message' => sprintf( 'No text matches "%s"%s. Nothing to change.', $o['find'], $o['whole_word'] ? ' as a whole word' : '' ) . ( $scan['truncated'] ? ' (only the first ' . self::MAX_POSTS . ' posts were searched)' : '' ),
			);
		}

		$token = wp_generate_password( 20, false );
		set_transient( self::PLAN_PREFIX . $token, array(
			'user'     => get_current_user_id(),
			'options'  => $o,
			'expected' => $scan['expected'],
		), self::PLAN_TTL );

		$places = array();
		foreach ( $scan['expected'] as $where => $n ) {
			$places[] = array( 'where' => $scan['labels'][ $where ] ?? $where, 'count' => $n ) + ( strpos( $where, 'post:' ) === 0 ? array( 'post_id' => (int) substr( $where, 5 ) ) : array() );
		}
		return array(
			'ok'           => true,
			'preview'      => true,
			'find'         => $o['find'],
			'replace'      => $o['replace'],
			'matches'      => $total,
			'places'       => $places,
			'changes'      => $rows,
			'more_changes' => max( 0, $total - count( $rows ) ),
			'plan'         => $token,
			'also'         => self::identity_note( $o ),
			'skipped_by_rule' => $scan['skipped'] ? $scan['skipped'] . ' match(es) in links / URLs were left alone (pass include_links: true to change those too, e.g. a phone number in tel: links).' : '',
			'next'         => 'Nothing has changed yet. Show the person this preview (how many places, and a few examples) and ask. Only after they agree, call replace_text with apply: "' . $token . '" (valid 30 minutes); add skip_posts: [ids] to leave some pages out.',
		);
	}

	/**
	 * The site title and tagline are not changed here (they feed the browser tab, feeds and SEO tags, and
	 * have their own tool) — but say so when they contain the text, or the rename looks unfinished.
	 *
	 * @param array $o
	 * @return string
	 */
	private static function identity_note( array $o ) {
		$hit = array();
		foreach ( array( 'blogname' => 'site title', 'blogdescription' => 'tagline' ) as $opt => $name ) {
			if ( preg_match( self::regex( $o ), html_entity_decode( (string) get_option( $opt ), ENT_QUOTES, 'UTF-8' ) ) ) {
				$hit[] = $name;
			}
		}
		return $hit ? 'The ' . implode( ' and ', $hit ) . ' also contain it; this tool does not change them — use update_site_identity (ask the person first).' : '';
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	private static function options( $in ) {
		$find = (string) ( $in['find'] ?? '' );
		if ( mb_strlen( trim( $find ) ) < 2 ) {
			return new WP_Error( 'upw_ai_replace_find', 'find must be at least 2 characters.' );
		}
		if ( ! array_key_exists( 'replace', $in ) ) {
			return new WP_Error( 'upw_ai_replace_to', 'Pass replace (the new text; an empty string removes the text).' );
		}
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['post_types'] ?? array() ) ) ) );
		return array(
			'find'          => $find,
			'replace'       => (string) $in['replace'],
			'match_case'    => ! isset( $in['match_case'] ) || (bool) $in['match_case'],
			'whole_word'    => ! isset( $in['whole_word'] ) || (bool) $in['whole_word'],
			'include_links' => ! empty( $in['include_links'] ),
			'post_types'    => $types,
			'post_ids'      => array_values( array_filter( array_map( 'intval', (array) ( $in['post_ids'] ?? array() ) ) ) ),
			'settings'      => ! isset( $in['include_theme_settings'] ) || (bool) $in['include_theme_settings'],
		);
	}

	/* ------------------------------------------------------------------ *
	 * Scanning (the preview and the apply share it)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $o
	 * @param bool  $write Build the new values too (apply).
	 * @return array { changes, expected, labels, skipped, truncated, trees, fields, settings }
	 */
	private static function scan( array $o, $write = false ) {
		$out = array( 'changes' => array(), 'expected' => array(), 'labels' => array(), 'skipped' => 0, 'truncated' => false, 'trees' => array(), 'fields' => array(), 'settings' => array() );

		foreach ( self::posts( $o, $out['truncated'] ) as $post ) {
			if ( $post->post_type === 'nav_menu_item' ? ! current_user_can( 'edit_theme_options' ) : ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$key   = 'post:' . $post->ID;
			$label = $post->post_type === 'nav_menu_item'
				? 'Menu item "' . $post->post_title . '"'
				: ( get_the_title( $post ) !== '' ? get_the_title( $post ) : '#' . $post->ID ) . ' (' . $post->post_type . ', ' . $post->post_status . ')';
			$count = 0;

			foreach ( array( 'post_title' => 'title', 'post_excerpt' => 'excerpt' ) as $field => $name ) {
				$new = self::replace_value( (string) $post->$field, $o, $n, $skip, false );
				$out['skipped'] += $skip;
				if ( $n ) {
					$count += $n;
					$out['changes'][] = self::row( $label, $post->ID, $name, (string) $post->$field, $new, $o );
					$out['fields'][ $post->ID ][ $field ] = $new;
				}
			}

			if ( FW_AI_Store::is_builder_active( $post->ID ) ) {
				$tree = FW_AI_Store::get_tree( $post->ID );
				$hits = array();
				$tree = self::walk_tree( $tree, array(), $o, $hits, $out['skipped'] );
				foreach ( $hits as $h ) {
					$count += $h['n'];
					$out['changes'][] = self::row( $label, $post->ID, $h['field'], $h['before'], $h['after'], $o, $h['path'] );
				}
				if ( $hits && $write ) {
					$out['trees'][ $post->ID ] = $tree;
				}
			}

			if ( $count ) {
				$out['expected'][ $key ] = $count;
				$out['labels'][ $key ]   = $label;
			}
		}

		if ( $o['settings'] && ! $o['post_ids'] && current_user_can( 'edit_theme_options' ) && class_exists( 'FW_AI_Settings' ) ) {
			$count = 0;
			foreach ( array_keys( FW_AI_Settings::leaves() ) as $id ) {
				$value = fw_get_db_settings_option( $id );
				if ( ! is_string( $value ) && ! is_array( $value ) ) {
					continue;
				}
				if ( preg_match( self::TECH_GROUP, (string) $id ) ) {
					continue;
				}
				$hits  = array();
				$value = self::walk_value( $value, array( (string) $id ), $o, $hits, $out['skipped'] );
				foreach ( $hits as $h ) {
					$count += $h['n'];
					$out['changes'][] = self::row( 'Theme Settings', 0, $h['field'], $h['before'], $h['after'], $o );
				}
				if ( $hits ) {
					$out['settings'][ $id ] = $value;
				}
			}
			if ( $count ) {
				$out['expected']['settings'] = $count;
				$out['labels']['settings']   = 'Theme Settings';
			}
		}
		return $out;
	}

	/**
	 * @param array $o
	 * @param bool  $truncated
	 * @return WP_Post[]
	 */
	private static function posts( array $o, &$truncated ) {
		$types = $o['post_types'] ? $o['post_types'] : array_values( array_diff( get_post_types( array( 'show_ui' => true ) ), array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'nav_menu_item' ) ) );
		if ( ! $o['post_types'] && current_user_can( 'edit_theme_options' ) ) {
			$types[] = 'nav_menu_item'; // menu labels are visitor-facing too (only custom labels hold text)
		}
		$args  = array(
			'post_type'        => $types,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'   => self::MAX_POSTS + 1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		);
		if ( $o['post_ids'] ) {
			$args['post__in']       = $o['post_ids'];
			$args['posts_per_page'] = count( $o['post_ids'] );
		}
		$posts = get_posts( $args );
		if ( count( $posts ) > self::MAX_POSTS ) {
			$truncated = true;
			$posts     = array_slice( $posts, 0, self::MAX_POSTS );
		}
		return $posts;
	}

	/**
	 * @param array $items
	 * @param int[] $prefix
	 * @param array $o
	 * @param array $hits
	 * @param int   $skipped
	 * @return array The tree with replacements made.
	 */
	private static function walk_tree( array $items, array $prefix, array $o, array &$hits, &$skipped ) {
		foreach ( $items as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$idx  = array_merge( $prefix, array( $i ) );
			$path = FW_AI_Store::path_str( $idx );
			if ( ! empty( $node['atts'] ) && is_array( $node['atts'] ) ) {
				$local = array();
				$node['atts'] = self::walk_value( $node['atts'], array(), $o, $local, $skipped );
				foreach ( $local as $h ) {
					$h['path']  = $path;
					$h['field'] = ( ! empty( $node['shortcode'] ) ? $node['shortcode'] : (string) ( $node['type'] ?? '' ) ) . ' → ' . $h['field'];
					$hits[]     = $h;
				}
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				$node['_items'] = self::walk_tree( $node['_items'], $idx, $o, $hits, $skipped );
			}
			$items[ $i ] = $node;
		}
		return $items;
	}

	/**
	 * Replace in every text leaf of a value, skipping technical keys.
	 *
	 * @param mixed    $value
	 * @param string[] $keys  Key path so far.
	 * @param array    $o
	 * @param array    $hits
	 * @param int      $skipped
	 * @return mixed
	 */
	private static function walk_value( $value, array $keys, array $o, array &$hits, &$skipped ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( ! is_int( $k ) && preg_match( is_array( $v ) ? self::TECH_GROUP : self::TECH_KEY, (string) $k ) ) {
					continue;
				}
				$value[ $k ] = self::walk_value( $v, array_merge( $keys, array( (string) $k ) ), $o, $hits, $skipped );
			}
			return $value;
		}
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}
		$named   = array_values( array_filter( $keys, function ( $k ) {
			return ! ctype_digit( $k );
		} ) );
		$is_link = $named && preg_match( self::LINK_KEY, end( $named ) );
		$new     = self::replace_value( $value, $o, $n, $skip, $is_link );
		$skipped += $skip;
		if ( $n ) {
			$hits[] = array( 'field' => implode( '.', $keys ), 'before' => $value, 'after' => $new, 'n' => $n );
		}
		return $new;
	}

	/**
	 * @param string $value
	 * @param array  $o
	 * @param int    $n      Replacements made.
	 * @param int    $skip   Matches left alone because they are in a link.
	 * @param bool   $is_link The field holds a link.
	 * @return string
	 */
	private static function replace_value( $value, array $o, &$n, &$skip, $is_link ) {
		$n    = 0;
		$skip = 0;
		$re   = self::regex( $o );
		if ( ! preg_match( $re, $value ) && ! preg_match( self::regex( $o, true ), $value ) ) {
			return $value;
		}
		$looks_link = (bool) preg_match( '#^\s*(?:https?://|mailto:|tel:|/|\#|www\.)#i', $value );
		if ( ( $is_link || $looks_link ) && ! $o['include_links'] ) {
			$skip = preg_match_all( $re, $value );
			return $value;
		}
		if ( preg_match( '/^\s*[\[{]/', $value ) && json_decode( $value ) !== null ) {
			return $value; // a JSON setting, not text
		}
		if ( strpos( $value, '<' ) === false || $is_link || $looks_link ) {
			return self::sub( $value, $o, $n );
		}
		// HTML: change the text between tags only, never a tag or an attribute.
		$parts = preg_split( '/(<[^>]*>)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( $part === '' || $part[0] === '<' ) {
				continue;
			}
			$c          = 0;
			$parts[ $i ] = self::sub( $part, $o, $c );
			$n         += $c;
		}
		return implode( '', $parts );
	}

	/**
	 * @param string $text
	 * @param array  $o
	 * @param int    $n
	 * @return string
	 */
	private static function sub( $text, array $o, &$n ) {
		$n    = 0;
		$text = preg_replace_callback( self::regex( $o ), function () use ( $o ) {
			return $o['replace'];
		}, $text, -1, $c1 );
		$n += (int) $c1;
		// The same words stored HTML-escaped ("Tom &amp; Jerry").
		$esc = esc_html( $o['find'] );
		if ( $esc !== $o['find'] ) {
			$text = preg_replace_callback( self::regex( $o, true ), function () use ( $o ) {
				return esc_html( $o['replace'] );
			}, $text, -1, $c2 );
			$n += (int) $c2;
		}
		return $text;
	}

	/**
	 * @param array $o
	 * @param bool  $escaped Match the HTML-escaped form of find.
	 * @return string
	 */
	private static function regex( array $o, $escaped = false ) {
		$f  = preg_quote( $escaped ? esc_html( $o['find'] ) : $o['find'], '/' );
		$re = $o['whole_word'] ? '(?<![\p{L}\p{N}_])' . $f . '(?![\p{L}\p{N}_])' : $f;
		return '/' . $re . '/u' . ( $o['match_case'] ? '' : 'i' );
	}

	/**
	 * A preview row: where, and a short before / after around the first match.
	 */
	private static function row( $label, $post_id, $field, $before, $after, array $o, $path = '' ) {
		$b   = wp_strip_all_tags( $before );
		$a   = wp_strip_all_tags( $after );
		$pos = 0;
		if ( preg_match( self::regex( $o ), $b, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = strlen( substr( $b, 0, $m[0][1] ) );
			$pos = mb_strlen( substr( $b, 0, $pos ) );
		}
		$start = max( 0, $pos - 40 );
		$row   = array(
			'where'  => $label,
			'field'  => $field,
			'before' => ( $start ? '…' : '' ) . mb_substr( $b, $start, 110 ) . ( mb_strlen( $b ) > $start + 110 ? '…' : '' ),
			'after'  => ( $start ? '…' : '' ) . mb_substr( $a, $start, 110 + max( 0, mb_strlen( $a ) - mb_strlen( $b ) ) ) . ( mb_strlen( $a ) > $start + 110 ? '…' : '' ),
		);
		if ( $post_id ) {
			$row['post_id'] = (int) $post_id;
		}
		if ( $path !== '' ) {
			$row['path'] = $path;
		}
		return $row;
	}

	/* ------------------------------------------------------------------ *
	 * Apply
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $token
	 * @param int[]  $skip_posts
	 * @return array|WP_Error
	 */
	private static function apply( $token, array $skip_posts ) {
		$plan = get_transient( self::PLAN_PREFIX . preg_replace( '/[^A-Za-z0-9]/', '', $token ) );
		if ( ! is_array( $plan ) || (int) $plan['user'] !== get_current_user_id() ) {
			return new WP_Error( 'upw_ai_replace_plan', 'That plan is unknown or expired (plans last 30 minutes). Run replace_text again without apply to get a fresh preview.' );
		}
		$o    = $plan['options'];
		$scan = self::scan( $o, true );
		$note = sprintf( 'Replace “%s” with “%s”', mb_substr( $o['find'], 0, 60 ), mb_substr( $o['replace'], 0, 60 ) );

		$done    = array();
		$skipped = array();
		$fields  = array();
		foreach ( $plan['expected'] as $where => $expected ) {
			$now = $scan['expected'][ $where ] ?? 0;
			$pid = strpos( $where, 'post:' ) === 0 ? (int) substr( $where, 5 ) : 0;
			if ( $pid && in_array( $pid, $skip_posts, true ) ) {
				$skipped[] = array( 'where' => $scan['labels'][ $where ] ?? $where, 'reason' => 'left out as asked' );
				continue;
			}
			if ( $now !== $expected ) {
				$skipped[] = array( 'where' => $scan['labels'][ $where ] ?? $where, 'reason' => sprintf( 'changed since the preview (%d matches then, %d now); preview again to include it', $expected, $now ) );
				continue;
			}
			if ( $pid ) {
				$entry = array( 'where' => $scan['labels'][ $where ], 'post_id' => $pid, 'count' => $now, 'url' => get_edit_post_link( $pid, 'raw' ) );
				if ( isset( $scan['trees'][ $pid ] ) ) {
					$rev = FW_AI_Store::save_tree( $pid, $scan['trees'][ $pid ], 'unysonplus/replace-text', $note );
					if ( is_wp_error( $rev ) ) {
						$skipped[] = array( 'where' => $scan['labels'][ $where ], 'reason' => $rev->get_error_message() );
						continue;
					}
					$entry['undo_revision_id'] = $rev;
				}
				if ( isset( $scan['fields'][ $pid ] ) ) {
					$fields[ $pid ] = $scan['fields'][ $pid ];
				}
				$done[] = $entry;
			} elseif ( $where === 'settings' && $scan['settings'] ) {
				$res = FW_AI_Settings::update( $scan['settings'], false, 'unysonplus/replace-text', $note );
				if ( is_wp_error( $res ) || empty( $res['ok'] ) ) {
					$skipped[] = array( 'where' => 'Theme Settings', 'reason' => is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['message'] ?? 'not saved' ) );
					continue;
				}
				$entry = array( 'where' => 'Theme Settings', 'count' => $now, 'undo_revision_id' => $res['undo_revision_id'] );
				if ( ! empty( $res['skipped'] ) ) {
					$entry['not_changed'] = $res['skipped'];
				}
				$done[] = $entry;
			}
		}

		if ( $fields ) {
			$spec = array( 'post_fields' => array() );
			foreach ( $fields as $pid => $f ) {
				$spec['post_fields'][ $pid ] = array_keys( $f );
			}
			$rev = FW_AI_Toolkit::snapshot( $spec, 'unysonplus/replace-text', $note . ' (titles / excerpts)' );
			foreach ( $fields as $pid => $f ) {
				wp_update_post( wp_slash( array( 'ID' => $pid ) + $f ) );
			}
			foreach ( $done as &$d ) {
				if ( isset( $d['post_id'], $fields[ $d['post_id'] ] ) ) {
					$d['title_undo_change_id'] = $rev;
				}
			}
			unset( $d );
		}
		delete_transient( self::PLAN_PREFIX . $token );

		$total = array_sum( wp_list_pluck( $done, 'count' ) );
		return array(
			'ok'       => (bool) $done,
			'replaced' => $total,
			'changed'  => $done,
			'skipped'  => $skipped,
			'message'  => $done
				? sprintf( 'Replaced %d match(es) in %d place(s). Every page was saved as a revision first; all of it is listed on AI Changes, where each can be undone.', $total, count( $done ) )
				: 'Nothing was changed.',
		);
	}
}
