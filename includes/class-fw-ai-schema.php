<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The element catalog + schema validator.
 *
 * Everything here is read from the LIVE shortcode definitions (options.php + the
 * `fw_shortcode_get_options` filter, which is how Animation Engine effect options are
 * injected), never from a hand-copied list, so the AI's view of an element cannot drift
 * from what the builder actually saves.
 *
 * Tree shape (same as a builder-saved page):
 *   layout node  { type: section|column|flexbox|container|row, atts:{…}, _items:[…], width? }
 *   element node { type: simple, shortcode: <tag>, atts:{…}, _items:[] }
 */
class FW_AI_Schema {

	/** Layout item types and what they may contain / sit in. */
	const LAYOUT_TYPES = array( 'section', 'column', 'flexbox', 'container', 'row' );

	/** Top-level item types (a page is a list of these). */
	const ROOT_TYPES = array( 'section', 'flexbox', 'container' );

	/**
	 * Injected Animation Engine effect option ids. Hidden from describe() unless asked for,
	 * because they add hundreds of leaves to every element.
	 */
	const EFFECT_IDS = array(
		'gsap_motion', 'scroll_keyframes', 'scroll_reveal', 'parallax', 'interaction', 'interaction__2',
		'text_effect', 'physics', 'marquee', 'motion_path', 'confetti', 'flip_card', 'scroll_text_highlight',
		'animation',
	);

	/** Leaf option types whose value must be one of their `choices`. */
	const CHOICE_TYPES = array( 'select', 'short-select', 'radio', 'image-picker', 'select-multiple', 'checkboxes', 'radio-text' );

	/** Per-request memo of flattened leaves, keyed by item type / shortcode tag. */
	private static $leaves = array();

	/* ------------------------------------------------------------------ *
	 * Catalog
	 * ------------------------------------------------------------------ */

	/**
	 * @return FW_Extension_Shortcodes|null
	 */
	private static function shortcodes() {
		$ext = fw_ext( 'shortcodes' );
		return $ext ? $ext : null;
	}

	/**
	 * Every insertable item: layout types first, then page-builder elements.
	 *
	 * @param string $category Optional builder-tab filter (case-insensitive substring).
	 * @return array[]
	 */
	public static function list_elements( $category = '' ) {
		$out = array();

		$layout_notes = array(
			'section'   => __( 'Classic full-width band. Contains column items only.', 'fw' ),
			'column'    => __( 'Classic grid column inside a section/row. Set `width` (1_1, 1_2, 1_3, 2_3, 1_4, 3_4, 1_5, 5_6 …). Contains elements or flexboxes.', 'fw' ),
			'flexbox'   => __( 'Modern Div (recommended). atts.html_tag = div|section|article|…, atts.display = flex|grid|block, atts.grid_columns for grids. Contains elements or nested flexboxes directly.', 'fw' ),
			'container' => __( 'Width-constrained wrapper.', 'fw' ),
			'row'       => __( 'Row of columns inside a section.', 'fw' ),
		);
		foreach ( self::LAYOUT_TYPES as $type ) {
			if ( ! self::shortcode_exists( $type ) ) {
				continue;
			}
			$out[] = array(
				'kind'        => 'layout',
				'type'        => $type,
				'title'       => ucfirst( $type ),
				'description' => $layout_notes[ $type ],
				'category'    => 'Layout',
			);
		}

		$sc = self::shortcodes();
		if ( ! $sc ) {
			return $out;
		}
		foreach ( (array) $sc->get_builder_data() as $tag => $data ) {
			$tab = is_string( $data['tab'] ?? null ) ? $data['tab'] : '';
			if ( $category !== '' && stripos( $tab, $category ) === false ) {
				continue;
			}
			$out[] = array(
				'kind'        => 'element',
				'type'        => 'simple',
				'shortcode'   => (string) $tag,
				'title'       => wp_strip_all_tags( (string) ( $data['title'] ?? $tag ) ),
				'description' => wp_strip_all_tags( (string) ( $data['description'] ?? '' ) ),
				'category'    => wp_strip_all_tags( $tab ),
			);
		}
		return $out;
	}

	/**
	 * @param string $tag
	 * @return bool
	 */
	public static function shortcode_exists( $tag ) {
		$sc = self::shortcodes();
		return $sc && $sc->get_shortcode( $tag );
	}

	/**
	 * Whether $tag is an element that can sit in the builder as a `simple` item.
	 *
	 * @param string $tag
	 * @return bool
	 */
	public static function is_builder_element( $tag ) {
		$sc = self::shortcodes();
		return $sc && (bool) $sc->get_shortcode_builder_data( $tag );
	}

	/* ------------------------------------------------------------------ *
	 * Options → flat leaves
	 * ------------------------------------------------------------------ */

	/**
	 * Flattened leaf options for a layout type or element tag.
	 *
	 * @param string $tag
	 * @return array id => array( option, tab title )
	 */
	public static function leaves( $tag ) {
		if ( isset( self::$leaves[ $tag ] ) ) {
			return self::$leaves[ $tag ];
		}
		$leaves = array();
		$sc     = self::shortcodes();
		$code   = $sc ? $sc->get_shortcode( $tag ) : null;
		if ( $code ) {
			$options = $code->get_options();
			if ( is_array( $options ) ) {
				self::walk( $options, '', $leaves );
			}
		}
		return self::$leaves[ $tag ] = $leaves;
	}

	/**
	 * Recursive container walk (tab / box / group / any container with `options`).
	 *
	 * @param array  $options
	 * @param string $tab     Title of the enclosing tab.
	 * @param array  $leaves  Collected output.
	 */
	private static function walk( array $options, $tab, array &$leaves ) {
		foreach ( $options as $id => $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			if ( is_int( $id ) ) {
				// Nested option arrays merged in with a numeric key.
				self::walk( $opt, $tab, $leaves );
				continue;
			}
			if ( isset( $opt['options'] ) && is_array( $opt['options'] ) ) {
				$next = ( ( $opt['type'] ?? '' ) === 'tab' && ! empty( $opt['title'] ) ) ? wp_strip_all_tags( (string) $opt['title'] ) : $tab;
				self::walk( $opt['options'], $next, $leaves );
				continue;
			}
			if ( empty( $opt['type'] ) ) {
				continue;
			}
			$leaves[ $id ] = array( $opt, $tab );
		}
	}

	/**
	 * Flatten `choices` (optgroups included) to value => label.
	 *
	 * @param mixed $choices
	 * @return array
	 */
	private static function flat_choices( $choices ) {
		$out = array();
		if ( ! is_array( $choices ) ) {
			return $out;
		}
		foreach ( $choices as $k => $v ) {
			if ( is_array( $v ) && isset( $v['choices'] ) && is_array( $v['choices'] ) ) {
				$out += self::flat_choices( $v['choices'] );
			} elseif ( is_array( $v ) ) {
				// image-picker style: value => array( small/large/label … )
				$out[ (string) $k ] = isset( $v['label'] ) ? (string) $v['label'] : (string) $k;
			} else {
				$out[ (string) $k ] = wp_strip_all_tags( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * The option schema of one layout type or element, shaped for a model to read.
	 *
	 * @param string $tag
	 * @param bool   $include_effects
	 * @return array|WP_Error
	 */
	public static function describe( $tag, $include_effects = false ) {
		$is_layout = in_array( $tag, self::LAYOUT_TYPES, true );
		if ( ! $is_layout && ! self::is_builder_element( $tag ) ) {
			return new WP_Error( 'upw_ai_unknown_element', sprintf( 'Unknown element "%s". Call unysonplus/list-elements for valid tags.', $tag ) );
		}

		$sc      = self::shortcodes();
		$data    = $is_layout ? array() : (array) $sc->get_shortcode_builder_data( $tag );
		$options = array();
		foreach ( self::leaves( $tag ) as $id => $pair ) {
			if ( ! $include_effects && in_array( $id, self::EFFECT_IDS, true ) ) {
				continue;
			}
			list( $opt, $tab ) = $pair;
			$row = array(
				'id'   => (string) $id,
				'type' => (string) $opt['type'],
			);
			if ( $tab !== '' ) {
				$row['tab'] = $tab;
			}
			if ( ! empty( $opt['label'] ) && is_string( $opt['label'] ) ) {
				$row['label'] = wp_strip_all_tags( $opt['label'] );
			}
			if ( ! empty( $opt['desc'] ) && is_string( $opt['desc'] ) ) {
				$row['desc'] = wp_html_excerpt( wp_strip_all_tags( $opt['desc'] ), 200, '…' );
			}
			if ( in_array( $opt['type'], self::CHOICE_TYPES, true ) ) {
				$choices = self::flat_choices( $opt['choices'] ?? array() );
				if ( $choices ) {
					$row['choices'] = count( $choices ) > 60 ? array_slice( $choices, 0, 60, true ) + array( '…' => '(truncated)' ) : $choices;
				}
			}
			if ( $opt['type'] === 'switch' ) {
				$row['choices'] = array_values( self::switch_values( $opt ) );
			}
			if ( array_key_exists( 'value', $opt ) ) {
				$row['default'] = $opt['value'];
			}
			$options[] = $row;
		}

		return array(
			'type'        => $is_layout ? $tag : 'simple',
			'shortcode'   => $is_layout ? null : $tag,
			'title'       => $is_layout ? ucfirst( $tag ) : wp_strip_all_tags( (string) ( $data['title'] ?? $tag ) ),
			'description' => $is_layout ? '' : wp_strip_all_tags( (string) ( $data['description'] ?? '' ) ),
			'options'     => $options,
			'notes'       => __( 'Set only the atts you need; omitted atts use their defaults. Unknown att ids are rejected. Styling a button or card belongs on a Theme Settings preset (see unysonplus/list-presets), not on the element.', 'fw' ),
		);
	}

	/**
	 * @param array $opt A switch option.
	 * @return array The two accepted values.
	 */
	private static function switch_values( array $opt ) {
		$vals = array();
		foreach ( array( 'left-choice', 'right-choice' ) as $side ) {
			if ( isset( $opt[ $side ]['value'] ) ) {
				$vals[] = $opt[ $side ]['value'];
			}
		}
		return $vals ? $vals : array( false, true );
	}

	/* ------------------------------------------------------------------ *
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validate + normalise a list of items that will sit under $parent_type.
	 *
	 * @param mixed  $items
	 * @param string $parent_type '' for the page root, else the containing item's type.
	 * @param string $path        Path prefix, for error messages.
	 * @param array  $errors      Collected errors (by reference).
	 * @return array Normalised items.
	 */
	public static function validate_items( $items, $parent_type, $path, array &$errors ) {
		if ( ! is_array( $items ) ) {
			$errors[] = sprintf( '%s: expected an array of items.', $path === '' ? 'root' : $path );
			return array();
		}
		$out = array();
		foreach ( array_values( $items ) as $i => $node ) {
			$p     = $path === '' ? (string) $i : $path . '.' . $i;
			$out[] = self::validate_node( $node, $parent_type, $p, $errors );
		}
		return $out;
	}

	/**
	 * @param mixed  $node
	 * @param string $parent_type
	 * @param string $path
	 * @param array  $errors
	 * @return array
	 */
	public static function validate_node( $node, $parent_type, $path, array &$errors ) {
		if ( is_object( $node ) ) {
			$node = json_decode( wp_json_encode( $node ), true );
		}
		if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
			$errors[] = "$path: every item needs a string `type` (section, column, flexbox, container, row or simple).";
			return array();
		}
		$type = $node['type'];
		$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();

		// Placement rules.
		if ( $parent_type === '' && ! in_array( $type, self::ROOT_TYPES, true ) ) {
			$errors[] = "$path: a `$type` cannot sit at the page root; wrap it in a flexbox (atts.html_tag = section) or a section.";
		}
		if ( $type === 'column' && ! in_array( $parent_type, array( 'section', 'row' ), true ) ) {
			$errors[] = "$path: a column must sit inside a section or row.";
		}
		if ( $parent_type === 'section' && ! in_array( $type, array( 'column', 'row' ), true ) ) {
			$errors[] = "$path: a section may only contain columns (or rows). Use a flexbox band to hold elements directly.";
		}

		if ( $type === 'simple' ) {
			$tag = isset( $node['shortcode'] ) && is_string( $node['shortcode'] ) ? $node['shortcode'] : '';
			if ( $tag === '' || ! self::is_builder_element( $tag ) ) {
				$errors[] = sprintf( '%s: unknown element "%s". Call unysonplus/list-elements for valid tags.', $path, $tag );
				return $node;
			}
			$atts = self::validate_atts( $tag, $atts, $path, $errors );
			if ( empty( $atts['unique_id'] ) ) {
				$atts['unique_id'] = self::unique_id();
			}
			return array(
				'type'      => 'simple',
				'shortcode' => $tag,
				'_items'    => array(),
				'atts'      => (object) $atts,
			);
		}

		if ( ! in_array( $type, self::LAYOUT_TYPES, true ) ) {
			$errors[] = "$path: unknown item type \"$type\".";
			return $node;
		}

		$atts = self::validate_atts( $type, $atts, $path, $errors );
		if ( $type === 'flexbox' && empty( $atts['unique_id'] ) ) {
			$atts['unique_id'] = self::unique_id();
		}
		$clean = array(
			'type'   => $type,
			'atts'   => (object) $atts,
			'_items' => self::validate_items( $node['_items'] ?? array(), $type, $path, $errors ),
		);
		if ( $type === 'column' ) {
			$width = isset( $node['width'] ) ? (string) $node['width'] : '1_1';
			if ( ! preg_match( '/^[1-9]_[1-9][0-9]?$/', $width ) ) {
				$errors[] = "$path: column width \"$width\" is invalid (use e.g. 1_1, 1_2, 1_3, 2_3, 1_4, 3_4).";
			}
			$clean['width'] = $width;
		}
		return $clean;
	}

	/**
	 * Check atts against the tag's leaf options. Unknown ids and out-of-range choices are
	 * errors (the model can correct them); everything else is passed through as given.
	 *
	 * @param string $tag
	 * @param array  $atts
	 * @param string $path
	 * @param array  $errors
	 * @return array
	 */
	public static function validate_atts( $tag, array $atts, $path, array &$errors ) {
		$leaves = self::leaves( $tag );
		if ( ! $leaves ) {
			return $atts; // An option-less element/layout: nothing to check against.
		}
		$unknown = array();
		foreach ( $atts as $id => $value ) {
			if ( $id === 'unique_id' ) {
				continue;
			}
			if ( ! isset( $leaves[ $id ] ) ) {
				$unknown[] = (string) $id;
				continue;
			}
			$opt = $leaves[ $id ][0];
			$msg = self::check_value( $opt, $value );
			if ( $msg ) {
				$errors[] = "$path ($tag.$id): $msg";
			}
		}
		if ( $unknown ) {
			$errors[] = sprintf(
				'%s (%s): unknown att(s) %s. Call unysonplus/describe-element for "%s" to see valid ids.',
				$path, $tag, implode( ', ', $unknown ), $tag
			);
		}
		return $atts;
	}

	/**
	 * @param array $opt
	 * @param mixed $value
	 * @return string '' when valid, else the problem.
	 */
	private static function check_value( array $opt, $value ) {
		$type = (string) $opt['type'];

		if ( in_array( $type, array( 'select', 'short-select', 'radio', 'image-picker' ), true ) ) {
			$choices = self::flat_choices( $opt['choices'] ?? array() );
			if ( $choices && ! is_array( $value ) && ! array_key_exists( (string) $value, $choices ) ) {
				return sprintf( '"%s" is not an allowed value (allowed: %s).', is_scalar( $value ) ? (string) $value : gettype( $value ), implode( ', ', array_slice( array_keys( $choices ), 0, 30 ) ) );
			}
			return '';
		}
		if ( $type === 'switch' ) {
			$allowed = self::switch_values( $opt );
			foreach ( $allowed as $a ) {
				if ( $a === $value || ( is_scalar( $a ) && is_scalar( $value ) && (string) $a === (string) $value ) ) {
					return '';
				}
			}
			return sprintf( 'a switch accepts only %s.', wp_json_encode( $allowed ) );
		}
		if ( in_array( $type, array( 'text', 'textarea', 'wp-editor', 'short-text' ), true ) && ! is_scalar( $value ) && $value !== null ) {
			return 'expected a string.';
		}
		// Structured values (unit inputs, colours, spacing, multi-pickers…): a scalar where
		// the default is an array is the one shape mistake worth catching.
		if ( isset( $opt['value'] ) && is_array( $opt['value'] ) && $opt['value'] && is_scalar( $value ) && $value !== '' ) {
			return sprintf( 'expected an object shaped like the default %s.', wp_json_encode( $opt['value'] ) );
		}
		return '';
	}

	/**
	 * @return string A 13-char id like the builder mints.
	 */
	public static function unique_id() {
		return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 13 );
	}
}
