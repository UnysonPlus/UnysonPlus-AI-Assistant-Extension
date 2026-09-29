<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * render-check: renders a page's builder tree server-side and reports what a person would notice.
 *
 * Works on FW_AI_Store::get_tree(), so inside the builder panel it checks the SANDBOXED tree (the
 * version being edited), not the saved one. Each element is rendered on its own so a problem can
 * be pinned to its `path`.
 *
 * Errors (the page is visibly broken):
 *   - an element that throws, prints PHP warnings, or leaves raw [shortcode] text
 *   - an element that renders nothing at all
 *   - an <img> with no src, or pointing at a missing local upload
 * Warnings (the page looks unfinished):
 *   - an element whose main visual (icon / image / file option named after the element) is empty —
 *     e.g. an icon_box with no icon leaves an empty gap above its title
 *   - text options still showing their default (a button labelled "Submit")
 *   - links that go nowhere (href "#" or empty)
 *   - layout items with nothing inside
 *   - heading outline problems (more than one h1, skipped levels)
 *
 * Filter `fw_ai_assistant_visual_atts` ( array $atts, string $tag ) adds/removes the "main visual"
 * option ids an element must fill.
 */
class FW_AI_Check {

	/** Option types that hold an icon or a media file. */
	const VISUAL_TYPES = array( 'icon', 'icon-v2', 'icon-v3', 'upload', 'multi-upload', 'svg-code' );

	/** Text-like option types whose default should normally be replaced. */
	const TEXT_TYPES = array( 'text', 'short-text', 'textarea', 'wp-editor' );

	/**
	 * @param int $post_id
	 * @return array
	 */
	public static function run( $post_id ) {
		$tree   = FW_AI_Store::get_tree( $post_id );
		$issues = array();
		$stats  = array( 'sections' => count( $tree ), 'elements' => 0, 'images' => 0, 'links' => 0 );
		$heads  = array();

		$prev_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		self::walk( $tree, array(), $issues, $stats, $heads );

		$GLOBALS['post'] = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $prev_post ) {
			setup_postdata( $prev_post );
		} else {
			wp_reset_postdata();
		}

		// Heading outline across the page.
		$h1 = count( array_filter( $heads, static function ( $h ) {
			return $h['level'] === 1;
		} ) );
		if ( $h1 > 1 ) {
			$issues[] = self::issue( 'warning', '', '', sprintf( 'The page has %d h1 headings; keep one (the main title) and use h2 for sections.', $h1 ) );
		}
		$last = 0;
		foreach ( $heads as $h ) {
			if ( $last && $h['level'] > $last + 1 ) {
				$issues[] = self::issue( 'warning', $h['path'], $h['tag'], sprintf( 'Heading jumps from h%d to h%d ("%s"); use h%d.', $last, $h['level'], $h['text'], $last + 1 ) );
			}
			$last = $h['level'];
		}

		// One line per distinct problem (an element can repeat the same link, say).
		$seen   = array();
		$issues = array_values( array_filter( $issues, static function ( $i ) use ( &$seen ) {
			$key = $i['severity'] . '|' . $i['path'] . '|' . $i['message'];
			if ( isset( $seen[ $key ] ) ) {
				return false;
			}
			return $seen[ $key ] = true;
		} ) );

		$errors   = count( array_filter( $issues, static function ( $i ) {
			return $i['severity'] === 'error';
		} ) );
		$warnings = count( $issues ) - $errors;

		return array(
			'post_id'  => (int) $post_id,
			'ok'       => $errors === 0,
			'summary'  => $issues
				? sprintf( '%d error(s), %d warning(s) across %d element(s).', $errors, $warnings, $stats['elements'] )
				: sprintf( 'No problems found in %d element(s).', $stats['elements'] ),
			'stats'    => $stats,
			'headings' => array_map( static function ( $h ) {
				return 'h' . $h['level'] . ' ' . $h['text'];
			}, $heads ),
			'issues'   => $issues,
		);
	}

	/**
	 * @param array $items
	 * @param int[] $prefix
	 * @param array $issues
	 * @param array $stats
	 * @param array $heads
	 */
	private static function walk( array $items, array $prefix, array &$issues, array &$stats, array &$heads ) {
		foreach ( array_values( $items ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$idx  = array_merge( $prefix, array( $i ) );
			$path = FW_AI_Store::path_str( $idx );
			$type = (string) ( $node['type'] ?? '' );
			$kids = isset( $node['_items'] ) && is_array( $node['_items'] ) ? $node['_items'] : array();

			if ( $type === 'simple' ) {
				$stats['elements']++;
				self::check_element( $node, $path, $issues, $stats, $heads );
				continue;
			}
			if ( $type === 'global_section' ) {
				continue; // A reference: its content lives in the snippet (checked there).
			}
			if ( ! in_array( $type, FW_AI_Schema::LAYOUT_TYPES, true ) ) {
				// A special builder item (e.g. a contact form): check it like an element.
				$stats['elements']++;
				self::check_element( $node + array( 'shortcode' => str_replace( '-', '_', $type ) ), $path, $issues, $stats, $heads );
				continue;
			}
			if ( ! $kids ) {
				// An empty layout item that carries visible styling (a background, border, height,
				// custom CSS / class) is decoration on purpose; a bare one is a leftover.
				if ( ! self::is_styled( isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array() ) ) {
					$issues[] = self::issue( 'warning', $path, $type, 'Empty ' . $type . ' — add content or remove it.' );
				}
				continue;
			}
			self::walk( $kids, $idx, $issues, $stats, $heads );
		}
	}

	/**
	 * @param array  $node
	 * @param string $path
	 * @param array  $issues
	 * @param array  $stats
	 * @param array  $heads
	 */
	private static function check_element( array $node, $path, array &$issues, array &$stats, array &$heads ) {
		$tag  = (string) ( $node['shortcode'] ?? '' );
		$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();

		// --- Options: main visual + untouched default text -------------------------------------
		$leaves = FW_AI_Schema::leaves( $tag );
		foreach ( self::visual_atts( $tag, $leaves ) as $id ) {
			if ( self::is_empty_value( $atts[ $id ] ?? ( $leaves[ $id ][0]['value'] ?? null ) ) ) {
				$issues[] = self::issue( 'warning', $path, $tag, sprintf( 'No %s set — the %s shows an empty space where it should be.', str_replace( '_', ' ', $id ), str_replace( '_', ' ', $tag ) ) );
			}
		}
		$defaults = array(); // id => default text still in place; reported only if it actually renders.
		foreach ( $leaves as $id => $pair ) {
			$opt = $pair[0];
			if ( ! in_array( $opt['type'], self::TEXT_TYPES, true ) || ! isset( $opt['value'] ) || ! is_string( $opt['value'] ) ) {
				continue;
			}
			// Only visible copy: Content-tab (or tab-less) options whose default reads as words.
			if ( $pair[1] !== '' && stripos( $pair[1], 'content' ) === false ) {
				continue;
			}
			$default = trim( wp_strip_all_tags( $opt['value'] ) );
			if ( $default === '' || strlen( $default ) > 80 || ! preg_match( '/[A-Za-z]{3,}/', $default )
				|| in_array( strtolower( $default ), array( 'auto', 'inherit', 'none', 'normal', 'default' ), true ) ) {
				continue;
			}
			$current = array_key_exists( $id, $atts ) ? trim( wp_strip_all_tags( (string) ( is_scalar( $atts[ $id ] ) ? $atts[ $id ] : '' ) ) ) : $default;
			if ( $current === $default && $default !== '#' ) {
				$defaults[ $id ] = $default;
			}
		}

		// --- Render --------------------------------------------------------------------------
		$html = self::render( $node, $error );
		if ( $error !== '' ) {
			$issues[] = self::issue( 'error', $path, $tag, $error );
			return;
		}
		if ( preg_match( '/(Fatal error|Warning|Notice|Deprecated)<\/b>:|\b(Fatal error|Warning|Notice): .+ in .+ on line \d+/i', $html ) ) {
			$issues[] = self::issue( 'error', $path, $tag, 'The element prints a PHP error message.' );
		}
		if ( preg_match( '/\[\/?([a-z_][a-z0-9_-]*)[\s\]]/i', wp_strip_all_tags( $html ), $m ) && shortcode_exists( $m[1] ) ) {
			$issues[] = self::issue( 'error', $path, $tag, sprintf( 'Raw shortcode text "[%s" is visible on the page.', $m[1] ) );
		}

		$dom = self::dom( $html );
		if ( ! $dom ) {
			return;
		}
		$xp    = new DOMXPath( $dom );
		$text  = trim( preg_replace( '/\s+/u', ' ', (string) $dom->documentElement->textContent ) );
		$media = $xp->query( '//img | //svg | //video | //iframe | //canvas | //picture | //object | //audio | //i[@class] | //span[contains(@class,"icon")]' )->length;
		$spacer = (bool) preg_match( '/spacer|divider|separator|anchor|shape|scroll|cursor|marker|gap|space/', $tag );
		// Markup with no text (a styled dot, a line) is decoration; only an element that outputs no
		// markup at all inside the render wrapper is "nothing".
		$wrapper = $xp->query( '/*/*' )->item( 0 );
		$markup  = $wrapper ? $xp->query( './/*', $wrapper )->length : 0;
		if ( ! $spacer && $text === '' && $media === 0 && $markup === 0 ) {
			$issues[] = self::issue( 'error', $path, $tag, 'Renders nothing visible — fill its content or remove it.' );
		}
		foreach ( $defaults as $id => $default ) {
			if ( stripos( $text, $default ) !== false ) {
				$issues[] = self::issue( 'warning', $path, $tag, sprintf( '%s still shows its default text "%s".', ucfirst( str_replace( '_', ' ', $id ) ), $default ) );
			}
		}

		foreach ( $xp->query( '//img' ) as $img ) {
			$stats['images']++;
			$src = trim( (string) $img->getAttribute( 'src' ) );
			if ( $src === '' && trim( (string) $img->getAttribute( 'data-src' ) ) === '' ) {
				$issues[] = self::issue( 'error', $path, $tag, 'An image has no source.' );
			} elseif ( ! self::local_file_exists( $src ) ) {
				$issues[] = self::issue( 'error', $path, $tag, sprintf( 'Image file not found: %s', $src ) );
			}
		}
		foreach ( $xp->query( '//a' ) as $a ) {
			$stats['links']++;
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( $href === '' || $href === '#' ) {
				$label = trim( preg_replace( '/\s+/u', ' ', (string) $a->textContent ) );
				$issues[] = self::issue( 'warning', $path, $tag, sprintf( 'Link%s goes nowhere (href "%s").', $label !== '' ? ' "' . wp_html_excerpt( $label, 40, '…' ) . '"' : '', $href ) );
			}
		}
		foreach ( $xp->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $h ) {
			$heads[] = array(
				'level' => (int) substr( $h->nodeName, 1 ),
				'text'  => wp_html_excerpt( trim( preg_replace( '/\s+/u', ' ', (string) $h->textContent ) ), 60, '…' ),
				'path'  => $path,
				'tag'   => $tag,
			);
		}
	}

	/**
	 * Option ids holding the element's main visual: an icon / media option whose first word is
	 * part of the element's own name (icon_box.icon, image_box.image, lottie.lottie_file,
	 * before_after.before_image) — optional decorations such as button.icon do not qualify.
	 *
	 * @param string $tag
	 * @param array  $leaves
	 * @return string[]
	 */
	private static function visual_atts( $tag, array $leaves ) {
		$words = explode( '_', str_replace( '-', '_', $tag ) );
		$ids   = array();
		foreach ( $leaves as $id => $pair ) {
			if ( ! in_array( $pair[0]['type'], self::VISUAL_TYPES, true ) ) {
				continue;
			}
			$first = strtok( (string) $id, '_' );
			if ( in_array( $first, $words, true ) ) {
				$ids[] = (string) $id;
			}
		}
		/** Filters the option ids an element must fill for its main visual (icon / image / file), checked by render-check. */
		return (array) apply_filters( 'fw_ai_assistant_visual_atts', $ids, $tag );
	}

	/**
	 * Whether a layout item's atts give it a visible look of its own.
	 *
	 * @param array $atts
	 * @return bool
	 */
	private static function is_styled( array $atts ) {
		$styled = false;
		$walk   = static function ( $value, $trail ) use ( &$walk, &$styled ) {
			if ( $styled ) {
				return;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					$walk( $v, $trail . '/' . $k );
				}
				return;
			}
			if ( ! is_scalar( $value ) || in_array( strtolower( trim( (string) $value ) ), array( '', 'no', 'none', '0', 'px', 'vh', '%', 'false' ), true ) ) {
				return;
			}
			if ( preg_match( '#(css|class|border|shadow|/custom$|src|url|stops/|height/.*value|/width/.*value|overlay|shape|divider|pattern)#i', $trail ) ) {
				$styled = true;
			}
		};
		foreach ( $atts as $k => $v ) {
			if ( $k !== 'unique_id' ) {
				$walk( $v, (string) $k );
			}
		}
		return $styled;
	}

	/**
	 * An icon / media value counts as empty when none of its identifying leaves (class, url, id, char,
	 * src, svg, code, file, name) holds anything.
	 *
	 * @param mixed $v
	 * @return bool
	 */
	private static function is_empty_value( $v ) {
		if ( $v === null || $v === false ) {
			return true;
		}
		if ( is_scalar( $v ) ) {
			return trim( (string) $v ) === '';
		}
		if ( ! is_array( $v ) ) {
			return false;
		}
		$found = false;
		array_walk_recursive( $v, static function ( $leaf, $key ) use ( &$found ) {
			if ( $found || ! is_scalar( $leaf ) || trim( (string) $leaf ) === '' || $leaf === false ) {
				return;
			}
			if ( is_int( $key ) || preg_match( '/class|url|src|svg|code|file|name|attachment|icon|char|^id$/i', (string) $key ) ) {
				$found = true;
			}
		} );
		return ! $found;
	}

	/**
	 * Render one element through the page-builder's own shortcode conversion.
	 *
	 * @param array  $node
	 * @param string $error Set to a message when rendering throws.
	 * @return string
	 */
	private static function render( array $node, &$error ) {
		$error = '';
		$ot    = fw()->backend->option_type( 'page-builder' );
		if ( ! $ot || ! method_exists( $ot, 'json_to_shortcodes' ) ) {
			$error = 'The page builder is not available to render with.';
			return '';
		}
		// A lone element is wrapped in a section by the builder's items-corrector; that wrapper is
		// harmless for the checks below.
		$wrapped = array( array( 'type' => 'flexbox', 'atts' => new stdClass(), '_items' => array( $node ) ) );
		ob_start();
		try {
			$html = do_shortcode( (string) $ot->json_to_shortcodes( wp_json_encode( $wrapped ) ) );
		} catch ( Throwable $e ) {
			$html  = '';
			$error = 'Rendering failed: ' . $e->getMessage();
		}
		$echoed = (string) ob_get_clean();
		return $echoed . $html;
	}

	/**
	 * @param string $html
	 * @return DOMDocument|null
	 */
	private static function dom( $html ) {
		if ( trim( $html ) === '' || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom->documentElement ? $dom : null;
	}

	/**
	 * For an uploads URL, whether the file exists on disk. Remote / non-upload URLs pass.
	 *
	 * @param string $src
	 * @return bool
	 */
	private static function local_file_exists( $src ) {
		$uploads = wp_get_upload_dir();
		$base    = set_url_scheme( $uploads['baseurl'], 'http' );
		$url     = set_url_scheme( strtok( $src, '?' ), 'http' );
		if ( strpos( $url, $base ) !== 0 ) {
			return true;
		}
		return file_exists( $uploads['basedir'] . rawurldecode( substr( $url, strlen( $base ) ) ) );
	}

	/**
	 * @param string $severity
	 * @param string $path
	 * @param string $element
	 * @param string $message
	 * @return array
	 */
	private static function issue( $severity, $path, $element, $message ) {
		return array(
			'severity' => $severity,
			'path'     => $path,
			'element'  => $element,
			'message'  => $message,
		);
	}
}
