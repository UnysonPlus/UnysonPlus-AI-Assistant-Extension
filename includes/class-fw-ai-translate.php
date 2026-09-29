<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Translate a page into a DRAFT COPY — the original is never touched.
 *
 *   get-page-text   every piece of visitor-facing text on a page (title, excerpt, and each text value in
 *                   the builder content, or the classic content) as { key, text } — the same rules as
 *                   replace-text decide what is text (FW_AI_Replace::TECH_KEY / TECH_GROUP / LINK_KEY),
 *                   so ids, CSS, colours, links and settings never reach the model.
 *   translate-page  { post_id, language, translations: [{ key, text }] } → a new draft with the same
 *                   layout, settings and images, and the translated text in place. Keys left out keep
 *                   the original text (reported). Undo trashes the draft. With Polylang active and a
 *                   lang_code, the draft is set to that language and linked as the page's translation.
 *
 * The model translates; the tools make sure the layout survives and nothing else changes.
 */
class FW_AI_Translate {

	const MAX_STRINGS = 1500;

	/** Post meta never copied to the translation. */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_upw_import_hash', FW_AI_Store::META_REVISION );

	public static function register() {
		fw_ai_register_ability( 'get-page-text', array(
			'label'       => __( 'Get a page\'s text for translation', 'fw' ),
			'description' => __( 'Every piece of visitor-facing text on a page as a list of { key, text }: the title, the excerpt and each text in the page content (never ids, CSS, colours, links or settings). Translate each text and pass the list to translate_page with the same keys.', 'fw' ),
			'input'       => array( 'post_id' => array( 'type' => 'integer' ) ),
			'required'    => array( 'post_id' ),
			'permission'  => 'edit_post',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'get_text' ),
		) );

		fw_ai_register_ability( 'translate-page', array(
			'label'       => __( 'Create a translated copy of a page', 'fw' ),
			'description' => __( 'Creates a NEW DRAFT copy of a page (same layout, settings and images) with the text replaced by translations: translations = [{ key, text }] using the keys from get_page_text. Translate every key; keep HTML tags, {{placeholders}}, [shortcodes], URLs, brand and product names as they are. language is the language name for the title and notes ("French"); lang_code (e.g. "fr") also links the copy as a translation when a multilingual plugin that supports it is active. The original page is not changed; undo_change trashes the copy.', 'fw' ),
			'input'       => array(
				'post_id'      => array( 'type' => 'integer' ),
				'language'     => array( 'type' => 'string' ),
				'lang_code'    => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'translations' => array(
					'type'     => 'array',
					'maxItems' => self::MAX_STRINGS,
					'items'    => array(
						'type'       => 'object',
						'properties' => array( 'key' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ) ),
						'required'   => array( 'key', 'text' ),
					),
				),
			),
			'required'    => array( 'post_id', 'language', 'translations' ),
			'permission'  => 'edit_post',
			'execute'     => array( __CLASS__, 'translate' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function get_text( $in ) {
		$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return new WP_Error( 'upw_ai_translate', 'No post with that post_id.' );
		}
		$strings = self::collect( $post );
		return array(
			'post_id'  => $post->ID,
			'title'    => $post->post_title,
			'site_language' => get_bloginfo( 'language' ),
			'count'    => count( $strings ),
			'strings'  => $strings,
			'next'     => 'Translate every text (keep HTML tags, {{placeholders}}, [shortcodes], URLs, brand and product names), then call translate_page with post_id, language and translations: [{ key, text }] using these keys.',
		);
	}

	/**
	 * @param WP_Post $post
	 * @return array[] { key, text }
	 */
	private static function collect( WP_Post $post ) {
		$out = array();
		foreach ( array( 'title' => $post->post_title, 'excerpt' => $post->post_excerpt ) as $k => $v ) {
			if ( self::is_text( $v ) ) {
				$out[] = array( 'key' => 't:' . $k, 'text' => $v );
			}
		}
		if ( FW_AI_Store::is_builder_active( $post->ID ) ) {
			self::walk_tree( FW_AI_Store::get_tree( $post->ID ), array(), $out );
		} elseif ( self::is_text( $post->post_content ) ) {
			$out[] = array( 'key' => 't:content', 'text' => $post->post_content );
		}
		return array_slice( $out, 0, self::MAX_STRINGS );
	}

	/**
	 * @param array $items
	 * @param int[] $prefix
	 * @param array $out
	 */
	private static function walk_tree( array $items, array $prefix, array &$out ) {
		foreach ( $items as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$idx = array_merge( $prefix, array( $i ) );
			if ( ! empty( $node['atts'] ) && is_array( $node['atts'] ) ) {
				self::walk_atts( $node['atts'], array(), FW_AI_Store::path_str( $idx ), $out );
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				self::walk_tree( $node['_items'], $idx, $out );
			}
		}
	}

	/**
	 * @param mixed    $value
	 * @param string[] $keys
	 * @param string   $path
	 * @param array    $out
	 */
	private static function walk_atts( $value, array $keys, $path, array &$out ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( ! is_int( $k ) && ( preg_match( is_array( $v ) ? FW_AI_Replace::TECH_GROUP : FW_AI_Replace::TECH_KEY, (string) $k ) || preg_match( FW_AI_Replace::LINK_KEY, (string) $k ) ) ) {
					continue;
				}
				self::walk_atts( $v, array_merge( $keys, array( (string) $k ) ), $path, $out );
			}
			return;
		}
		if ( is_string( $value ) && self::is_text( $value ) ) {
			$out[] = array( 'key' => 'b:' . $path . '|' . implode( '.', $keys ), 'text' => $value );
		}
	}

	/**
	 * Words a visitor reads: has a letter, and is not a link, a colour, a number or a JSON setting.
	 *
	 * @param mixed $v
	 * @return bool
	 */
	private static function is_text( $v ) {
		if ( ! is_string( $v ) || trim( $v ) === '' || ! preg_match( '/\p{L}/u', wp_strip_all_tags( $v ) ) ) {
			return false;
		}
		if ( preg_match( '#^\s*(?:https?://|mailto:|tel:|/|\#|www\.)#i', $v ) ) {
			return false;
		}
		if ( preg_match( '/^\s*[\[{]/', $v ) && json_decode( $v ) !== null ) {
			return false;
		}
		// Single lowercase tokens are settings, not words ("yes", "block", "between", "h2", "fa-star").
		if ( preg_match( '/^[a-z0-9_-]+$/', trim( $v ) ) ) {
			return false;
		}
		// CSS lengths and lists of them ("[50px]", "1.5rem", "10px 20px").
		if ( preg_match( '/^[\s\[\]\(\),]*(?:-?[\d.]+\s*(?:px|em|rem|%|vh|vw|vmin|vmax|s|ms|deg|fr|ch|ex)?[\s\[\]\(\),]*)+$/i', $v ) ) {
			return false;
		}
		return ! in_array( strtolower( trim( $v ) ), array( 'yes', 'no', 'none', 'left', 'right', 'center', 'top', 'bottom', 'auto', 'full', 'true', 'false', 'default', 'inherit', 'normal', 'bold', 'solid', 'cover', 'contain' ), true );
	}

	/* ------------------------------------------------------------------ *
	 * Writing the copy
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function translate( $in ) {
		$src = get_post( (int) ( $in['post_id'] ?? 0 ) );
		if ( ! $src ) {
			return new WP_Error( 'upw_ai_translate', 'No post with that post_id.' );
		}
		$language = trim( sanitize_text_field( (string) ( $in['language'] ?? '' ) ) );
		if ( $language === '' ) {
			return new WP_Error( 'upw_ai_translate', 'Pass language, e.g. "French".' );
		}
		$map = array();
		foreach ( (array) ( $in['translations'] ?? array() ) as $t ) {
			if ( isset( $t['key'], $t['text'] ) ) {
				$map[ (string) $t['key'] ] = (string) $t['text'];
			}
		}
		$source   = self::collect( $src );
		$known    = wp_list_pluck( $source, 'text', 'key' );
		$unknown  = array_values( array_diff( array_keys( $map ), array_keys( $known ) ) );
		$missing  = array_values( array_diff( array_keys( $known ), array_keys( $map ) ) );
		$warnings = array();
		if ( ! array_intersect_key( $map, $known ) ) {
			return new WP_Error( 'upw_ai_translate', 'None of the keys match this page. Call get_page_text for post_id ' . $src->ID . ' and use its keys.' );
		}
		// Keep what must not change inside a text.
		foreach ( $map as $key => $text ) {
			if ( ! isset( $known[ $key ] ) ) {
				continue;
			}
			$orig = $known[ $key ];
			if ( strpos( $orig, '<' ) !== false ) {
				$map[ $key ] = wp_kses_post( $text );
			} else {
				$map[ $key ] = wp_strip_all_tags( $text );
			}
			preg_match_all( '/\{\{[^}]+\}\}|\[[a-z_-]+[^\]]*\]/i', $orig, $keep );
			foreach ( $keep[0] as $token ) {
				if ( strpos( $map[ $key ], $token ) === false ) {
					$warnings[] = $key . ': "' . $token . '" is missing from the translation';
				}
			}
		}

		// The copy.
		$title = $map['t:title'] ?? $src->post_title . ' (' . $language . ')';
		$code  = sanitize_key( (string) ( $in['lang_code'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $in['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $title ) . ( $code !== '' ? '-' . $code : '' );
		}
		$new_id = wp_insert_post( wp_slash( array(
			'post_type'    => $src->post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_excerpt' => $map['t:excerpt'] ?? $src->post_excerpt,
			'post_content' => $map['t:content'] ?? $src->post_content,
			'post_parent'  => $src->post_parent,
			'menu_order'   => $src->menu_order,
			'post_author'  => get_current_user_id(),
		) ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		foreach ( get_post_meta( $src->ID ) as $k => $vals ) {
			if ( in_array( $k, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( $vals as $v ) {
				add_post_meta( $new_id, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			if ( $tax === 'language' || $tax === 'post_translations' ) {
				continue; // the multilingual plugin sets these below
			}
			$terms = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}

		$applied = 0;
		if ( FW_AI_Store::is_builder_active( $src->ID ) ) {
			$tree = FW_AI_Store::get_tree( $src->ID );
			foreach ( $map as $key => $text ) {
				if ( strpos( $key, 'b:' ) !== 0 || ! isset( $known[ $key ] ) ) {
					continue;
				}
				list( $path, $att ) = array_pad( explode( '|', substr( $key, 2 ), 2 ), 2, '' );
				if ( self::set_in_tree( $tree, $path, $att, $text ) ) {
					$applied++;
				}
			}
			$saved = FW_AI_Store::save_tree( $new_id, $tree, 'unysonplus/translate-page', 'Translated copy (' . $language . ')' );
			if ( is_wp_error( $saved ) ) {
				wp_delete_post( $new_id, true );
				return $saved;
			}
		}
		$applied += count( array_intersect_key( $map, array_flip( array( 't:title', 't:excerpt', 't:content' ) ) ) );

		$linked = '';
		if ( $code !== '' && function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) ) {
			pll_set_post_language( $new_id, $code );
			$group          = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( $src->ID ) : array();
			$group[ $code ] = $new_id;
			if ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $src->ID ) ) {
				$group[ pll_get_post_language( $src->ID ) ] = $src->ID;
			}
			pll_save_post_translations( $group );
			$linked = 'Linked as the ' . $language . ' translation in the multilingual plugin.';
		}

		$rev = FW_AI_Toolkit::snapshot( array( 'created_posts' => array( $new_id ) ), 'unysonplus/translate-page', 'Translated copy of "' . get_the_title( $src ) . '" (' . $language . ')' );
		$out = array(
			'ok'               => true,
			'post_id'          => $new_id,
			'status'           => 'draft',
			'title'            => $title,
			'edit_url'         => get_edit_post_link( $new_id, 'raw' ),
			'preview_url'      => get_preview_post_link( $new_id ),
			'translated'       => $applied,
			'of'               => count( $known ),
			'undo_revision_id' => $rev,
			'message'          => sprintf( 'Created a %s draft of "%s" with %d of %d texts translated. The original page is unchanged.', $language, get_the_title( $src ), $applied, count( $known ) ),
		);
		if ( $missing ) {
			$out['kept_original'] = array_slice( $missing, 0, 40 );
		}
		if ( $unknown ) {
			$out['unknown_keys'] = array_slice( $unknown, 0, 20 );
		}
		if ( $warnings ) {
			$out['warnings'] = array_slice( $warnings, 0, 20 );
		}
		if ( $linked !== '' ) {
			$out['linked'] = $linked;
		}
		return $out;
	}

	/**
	 * @param array  $tree
	 * @param string $path Node path "0.2.1".
	 * @param string $att  Att key path "items.0.title".
	 * @param string $text
	 * @return bool
	 */
	private static function set_in_tree( array &$tree, $path, $att, $text ) {
		$idx = array_map( 'intval', explode( '.', $path ) );
		$ref =& $tree;
		foreach ( $idx as $d => $i ) {
			if ( ! isset( $ref[ $i ] ) || ! is_array( $ref[ $i ] ) ) {
				return false;
			}
			if ( $d < count( $idx ) - 1 ) {
				$ref =& $ref[ $i ]['_items'];
			} else {
				$ref =& $ref[ $i ]['atts'];
			}
		}
		foreach ( explode( '.', $att ) as $k ) {
			if ( ! is_array( $ref ) || ! array_key_exists( ctype_digit( $k ) ? (int) $k : $k, $ref ) ) {
				return false;
			}
			$ref =& $ref[ ctype_digit( $k ) ? (int) $k : $k ];
		}
		if ( ! is_string( $ref ) ) {
			return false;
		}
		$ref = $text;
		return true;
	}
}
