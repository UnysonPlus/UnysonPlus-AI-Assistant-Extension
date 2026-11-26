<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI for layout (roadmap 7e): proposals for ONE section, previewed on the Live Editor canvas before anything is kept.
 *
 *  - responsive: the tablet and phone layout of a section designed on desktop. The model returns per-item changes to
 *    the section's per-device options only (the `responsive` option types: { base = phone, md = tablet from 768px,
 *    lg = desktop from 992px }, plus hide-on-device), so the content cannot change.
 *  - variations: a few alternative designs of the section, as whole section nodes that keep its content.
 *
 * The site never calls an AI here. POST /panel/layout with phase "brief" returns the prompt; the editor sends it
 * through whichever AI the panel is connected to (the same paths as a chat message) and posts the raw answer back
 * with phase "parse", which checks it against the real option schemas and returns only proposals that pass.
 */
class FW_AI_Layout {

	const KINDS = array( 'responsive', 'variations' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/layout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest' ),
			'permission_callback' => function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'post_id' );
				return $id && get_post( $id ) && current_user_can( 'edit_post', $id );
			},
			'args'                => array(
				'post_id' => array( 'type' => 'integer', 'required' => true ),
				'phase'   => array( 'type' => 'string', 'enum' => array( 'brief', 'parse' ), 'required' => true ),
				'kind'    => array( 'type' => 'string', 'enum' => self::KINDS, 'required' => true ),
				'node'    => array( 'type' => 'object', 'required' => true ),
				'count'   => array( 'type' => 'integer', 'default' => 3 ),
				'raw'     => array( 'type' => 'string', 'default' => '' ),
				// The type of the item that holds the section ('' = the page root), for placement checks.
				'parent'  => array( 'type' => 'string', 'default' => '' ),
			),
		) );
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest( WP_REST_Request $r ) {
		$node = json_decode( wp_json_encode( $r->get_param( 'node' ) ), true );
		if ( ! is_array( $node ) || empty( $node['type'] ) ) {
			return new WP_Error( 'upw_ai_layout_node', 'node must be one builder item.', array( 'status' => 400 ) );
		}
		$kind  = (string) $r->get_param( 'kind' );
		$count = max( 1, min( 4, (int) $r->get_param( 'count' ) ) );
		if ( $r->get_param( 'phase' ) === 'brief' ) {
			return rest_ensure_response( self::brief( $kind, $node, $count ) );
		}
		return rest_ensure_response( self::parse( $kind, $node, (string) $r->get_param( 'raw' ), sanitize_key( (string) $r->get_param( 'parent' ) ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * The prompt
	 * ------------------------------------------------------------------ */

	/**
	 * @return array { system, user, message } message = both, for paths that take one text.
	 */
	public static function brief( $kind, array $node, $count ) {
		$system = 'You are a web layout designer working inside the Unyson+ page builder for WordPress. '
			. 'You answer with ONE JSON object and nothing else: no prose, no code fence, and you do not call any tool.';

		if ( $kind === 'responsive' ) {
			$items = array();
			self::collect_responsive( $node, '', $items );
			$user = "This section of a page was designed on a desktop screen. Propose $count different ways to lay it out on TABLET (768-991px wide) and PHONE (under 768px).\n\n"
				. "Per-device values are mobile-first: {\"base\": phone and up, \"md\": tablet and up, \"lg\": desktop and up}. An empty value inherits the next smaller device. "
				. "So the section's desktop look must stay as it is: when you set `base` or `md` to something new, put the value the section uses now into `lg` (and `md` when the tablet should keep it). "
				. "When an option's value now is empty on every device (the theme default), leave it alone: no `lg` value can bring the default back, so a change to it would also change the desktop and is discarded.\n\n"
				. "Good phone layouts: side-by-side items stack (direction column), images come before or after the text as reads best, gaps shrink, centred text where it helps, and purely decorative items may be hidden on phone (responsive_hide). "
				. "Tablet often keeps two items side by side. Make the proposals really differ (for example: stack everything / two-up on tablet then stack / image first vs text first).\n\n"
				. "These are the section's items you may change, with each per-device option, its value now and what it accepts:\n"
				. wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n"
				. "Change ONLY the options listed above, addressed by the item's id. Answer exactly in this shape:\n"
				. '{"proposals":[{"label":"2-4 words","why":"one short sentence","changes":[{"id":"<item id>","atts":{"<option id>":{"base":…,"md":…,"lg":…}}}]}]}';
		} else {
			$tags = array();
			self::collect_tags( $node, $tags );
			$schema = array();
			foreach ( array_keys( $tags ) as $tag ) {
				$schema[ $tag ] = self::compact_schema( $tag );
			}
			$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $schema_json ) > 24000 ) {
				$schema_json = substr( $schema_json, 0, 24000 ) . '…(truncated)';
			}
			$user = "Propose $count alternative designs of this page section. Each one keeps ALL of the section's content (every heading, text, image, button and link, word for word) but presents it differently: "
				. "a different arrangement (columns, order, alignment, grid vs row, image side), spacing, backgrounds, card styles or emphasis. Make the $count designs clearly different from the original and from each other, and keep them tasteful and readable.\n\n"
				. "Rules: the answer's `node` is ONE complete builder item that replaces the section (the same JSON shape as the original). "
				. "Layout items are {\"type\":\"flexbox|section|column|row|container\",\"atts\":{…},\"_items\":[…]}; elements are {\"type\":\"simple\",\"shortcode\":\"<tag>\",\"atts\":{…},\"_items\":[]}. "
				. "A section holds only columns (each with a `width` such as 1_2, 1_3, 2_3, 1_1); a flexbox holds anything. "
				. "Use only the element tags already in the section and the option ids listed below; leave out `unique_id` (new ids are given). "
				. "Prefer the site's presets (preset and colour options) over hand-set colours.\n\n"
				. "The section now:\n" . wp_json_encode( self::strip_ids( $node ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n"
				. "Options per type (id: type [choices]):\n" . $schema_json . "\n\n"
				. "Answer exactly in this shape:\n"
				. '{"proposals":[{"label":"2-4 words","why":"one short sentence","node":{…}}]}';
		}
		return array( 'system' => $system, 'user' => $user, 'message' => $system . "\n\n" . $user );
	}

	/** The section's items with their per-device options (value now + what each accepts). */
	private static function collect_responsive( array $node, $path, array &$out ) {
		$tag  = $node['type'] === 'simple' ? (string) ( $node['shortcode'] ?? '' ) : (string) $node['type'];
		$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
		$opts = array();
		foreach ( self::responsive_leaves( $tag ) as $id => $opt ) {
			$row = array( 'now' => array_key_exists( $id, $atts ) ? $atts[ $id ] : ( $opt['value'] ?? null ) );
			if ( ! empty( $opt['label'] ) && is_string( $opt['label'] ) ) {
				$row['label'] = wp_strip_all_tags( $opt['label'] );
			}
			if ( $opt['type'] === 'checkboxes' ) {
				$row['accepts'] = 'a map of { "hide-xs": true (hide on phone), "hide-sm": true (hide on tablet), "hide-md": true (hide on desktop) }';
			} else {
				$inner = isset( $opt['inner'] ) && is_array( $opt['inner'] ) ? $opt['inner'] : array();
				$it    = (string) ( $inner['type'] ?? '' );
				$acc   = $it;
				$ch    = FW_AI_Schema::flat_choices( $inner['choices'] ?? array() );
				if ( $ch ) {
					$acc .= ' one of ' . implode( '|', array_keys( $ch ) );
				} elseif ( $it === 'switch' ) {
					$acc .= ' "yes"|"no"';
				} elseif ( $it === 'unit-input' ) {
					$acc .= ' {"value":"<number>","unit":"px|%|rem|em"}';
				}
				$row['accepts'] = 'per device: ' . $acc;
			}
			$opts[ $id ] = $row;
		}
		if ( $opts && ! empty( $atts['unique_id'] ) ) {
			$entry = array( 'id' => (string) $atts['unique_id'], 'path' => $path === '' ? 'section' : $path, 'type' => $tag, 'options' => $opts );
			if ( $node['type'] === 'column' && isset( $node['width'] ) ) {
				$entry['column_width_desktop'] = (string) $node['width'];
			}
			if ( $node['type'] === 'simple' ) {
				$entry['what'] = self::summary( $atts );
			}
			$out[] = $entry;
		}
		foreach ( (array) ( $node['_items'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) && ! empty( $child['type'] ) ) {
				self::collect_responsive( $child, $path === '' ? (string) $i : $path . '.' . $i, $out );
			}
		}
	}

	/** Per-device leaves of a type: options of type `responsive`, and the hide-on-device checkboxes. */
	private static function responsive_leaves( $tag ) {
		$out = array();
		foreach ( FW_AI_Schema::leaves( $tag ) as $id => $pair ) {
			$opt = $pair[0];
			if ( ( $opt['type'] ?? '' ) === 'responsive' || $id === 'responsive_hide' ) {
				$out[ $id ] = $opt;
			}
		}
		return $out;
	}

	/** A few words of an element's visible text, so the model knows which item is the image and which the heading. */
	private static function summary( array $atts ) {
		$bits = array();
		foreach ( $atts as $k => $v ) {
			if ( is_string( $v ) && $v !== '' && ! preg_match( '/(^|_)(id|class|url|link|css)$/', $k ) && strlen( wp_strip_all_tags( $v ) ) > 2 ) {
				$bits[] = wp_html_excerpt( wp_strip_all_tags( $v ), 50, '…' );
			}
			if ( count( $bits ) >= 2 ) {
				break;
			}
		}
		return implode( ' / ', $bits );
	}

	private static function collect_tags( array $node, array &$tags ) {
		$tag = $node['type'] === 'simple' ? (string) ( $node['shortcode'] ?? '' ) : (string) $node['type'];
		if ( $tag !== '' ) {
			$tags[ $tag ] = true;
		}
		// The layout types a redesign may switch to.
		foreach ( array( 'flexbox', 'section', 'column' ) as $t ) {
			$tags[ $t ] = true;
		}
		foreach ( (array) ( $node['_items'] ?? array() ) as $child ) {
			if ( is_array( $child ) && ! empty( $child['type'] ) ) {
				self::collect_tags( $child, $tags );
			}
		}
	}

	/** id => "type [choice|choice]" for the design-relevant options of a type (no effects, no advanced tab). */
	private static function compact_schema( $tag ) {
		$out = array();
		foreach ( FW_AI_Schema::leaves( $tag ) as $id => $pair ) {
			list( $opt, $tab ) = $pair;
			if ( preg_match( '/advanced|animation/i', (string) $tab ) || in_array( $id, array( 'unique_id', 'custom_css', 'custom_class', 'custom_id' ), true ) ) {
				continue;
			}
			$t = (string) $opt['type'];
			if ( in_array( $t, array( 'html', 'html-fixed', 'html-full', 'hidden', 'unique' ), true ) ) {
				continue;
			}
			$s  = $t;
			$ch = isset( $opt['choices'] ) ? FW_AI_Schema::flat_choices( $opt['choices'] ) : array();
			if ( ! $ch && $t === 'responsive' && isset( $opt['inner']['choices'] ) ) {
				$s .= '(per device {base,md,lg})';
				$ch = FW_AI_Schema::flat_choices( $opt['inner']['choices'] );
			}
			if ( $ch ) {
				$keys = array_keys( $ch );
				$s   .= ' [' . implode( '|', array_slice( $keys, 0, 24 ) ) . ( count( $keys ) > 24 ? '|…' : '' ) . ']';
			}
			$out[ $id ] = $s;
		}
		return $out;
	}

	/** The node without unique_id values (the model must not reuse them; validation gives fresh ones). */
	private static function strip_ids( array $node ) {
		if ( isset( $node['atts'] ) && is_array( $node['atts'] ) ) {
			unset( $node['atts']['unique_id'] );
		}
		if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
			foreach ( $node['_items'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$node['_items'][ $i ] = self::strip_ids( $child );
				}
			}
		}
		return $node;
	}

	/* ------------------------------------------------------------------ *
	 * The answer
	 * ------------------------------------------------------------------ */

	/**
	 * @return array { proposals: [ { label, why, node } ], dropped: [ reasons ] }
	 */
	public static function parse( $kind, array $node, $raw, $parent = '' ) {
		$data    = self::json_from( $raw );
		$list    = ( is_array( $data ) && isset( $data['proposals'] ) && is_array( $data['proposals'] ) ) ? $data['proposals'] : array();
		$out     = array();
		$dropped = array();
		foreach ( array_slice( $list, 0, 4 ) as $n => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$label = wp_html_excerpt( sanitize_text_field( (string) ( $p['label'] ?? '' ) ), 40, '…' );
			$label = $label !== '' ? $label : sprintf( 'Option %d', $n + 1 );
			$why   = wp_html_excerpt( sanitize_text_field( (string) ( $p['why'] ?? '' ) ), 200, '…' );
			$errors = array();
			if ( $kind === 'responsive' ) {
				$new = self::apply_changes( $node, (array) ( $p['changes'] ?? array() ), $errors );
				if ( $new === null ) {
					$dropped[] = $label . ': ' . ( $errors ? implode( ' ', array_slice( $errors, 0, 2 ) ) : 'it changed nothing.' );
					continue;
				}
			} else {
				$cand = isset( $p['node'] ) && is_array( $p['node'] ) ? $p['node'] : null;
				if ( ! $cand ) {
					$dropped[] = $label . ': no node.';
					continue;
				}
				$new  = FW_AI_Schema::validate_node( self::strip_ids( $cand ), $parent, 'node', $errors );
				$new  = json_decode( wp_json_encode( $new ), true );
				$lost = self::lost_text( $node, $new );
				if ( $lost ) {
					$errors[] = 'it dropped content: "' . implode( '", "', array_slice( $lost, 0, 3 ) ) . '".';
				}
				if ( $errors ) {
					$dropped[] = $label . ': ' . implode( ' ', array_slice( $errors, 0, 2 ) );
					continue;
				}
			}
			$out[] = array( 'label' => $label, 'why' => $why, 'node' => $new );
		}
		return array( 'proposals' => $out, 'dropped' => $dropped );
	}

	/** The first JSON object in a model's answer (code fences and stray prose tolerated). */
	private static function json_from( $raw ) {
		$t = trim( (string) $raw );
		$t = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $t );
		$j = json_decode( $t, true );
		if ( is_array( $j ) ) {
			return $j;
		}
		$a = strpos( $t, '{' );
		$b = strrpos( $t, '}' );
		if ( $a !== false && $b > $a ) {
			$j = json_decode( substr( $t, $a, $b - $a + 1 ), true );
		}
		return is_array( $j ) ? $j : null;
	}

	/**
	 * Merge per-item per-device changes into a copy of the section. Only per-device options are accepted, each
	 * checked against its option; anything else is an error. Null when nothing valid changed.
	 */
	private static function apply_changes( array $node, array $changes, array &$errors ) {
		$by_id = array();
		foreach ( $changes as $c ) {
			if ( is_array( $c ) && ! empty( $c['id'] ) && isset( $c['atts'] ) && is_array( $c['atts'] ) ) {
				$by_id[ (string) $c['id'] ] = isset( $by_id[ (string) $c['id'] ] ) ? array_merge( $by_id[ (string) $c['id'] ], $c['atts'] ) : $c['atts'];
			}
		}
		$applied = 0;
		$node    = self::walk_apply( $node, $by_id, $errors, $applied );
		return $applied ? $node : null;
	}

	private static function walk_apply( array $node, array $by_id, array &$errors, &$applied ) {
		$id = isset( $node['atts']['unique_id'] ) ? (string) $node['atts']['unique_id'] : '';
		if ( $id !== '' && isset( $by_id[ $id ] ) ) {
			$tag     = $node['type'] === 'simple' ? (string) ( $node['shortcode'] ?? '' ) : (string) $node['type'];
			$allowed = self::responsive_leaves( $tag );
			foreach ( $by_id[ $id ] as $k => $v ) {
				if ( ! isset( $allowed[ $k ] ) ) {
					$errors[] = "$tag.$k is not a per-device option.";
					continue;
				}
				$e = array();
				if ( $k === 'responsive_hide' ) {
					$v = is_array( $v ) ? array_intersect_key( array_map( 'boolval', $v ), array_flip( array( 'hide-xs', 'hide-sm', 'hide-md' ) ) ) : array();
					$v = array_filter( $v );
				} else {
					if ( ! is_array( $v ) ) {
						$errors[] = "$tag.$k must be {base, md, lg}.";
						continue;
					}
					$v = array_intersect_key( $v, array_flip( array( 'base', 'md', 'lg', 'xl' ) ) );
					$cur = isset( $node['atts'][ $k ] ) && is_array( $node['atts'][ $k ] ) ? $node['atts'][ $k ] : (array) ( $allowed[ $k ]['value'] ?? array() );
					$v   = array_merge( $cur, $v );
					// The desktop must render exactly as before. An empty `lg` inherits the smaller device, so a new
					// tablet / phone value would leak up to desktop: pin the desktop value, or, when desktop used the
					// theme default (empty, which no `lg` can restore), refuse the change.
					$was = self::effective( $cur, 'lg' );
					if ( self::effective( $v, 'lg' ) !== $was ) {
						if ( self::is_blank( $was ) ) {
							$errors[] = "$tag.$k: skipped, it would also change the desktop layout.";
							continue;
						}
						$v['lg'] = $was;
					}
					if ( isset( $cur['xl'] ) && ! self::is_blank( $cur['xl'] ) ) {
						$v['xl'] = $cur['xl'];
					}
					FW_AI_Schema::check_deep( $allowed[ $k ], $v, "$tag.$k", $e );
				}
				if ( $e ) {
					$errors = array_merge( $errors, $e );
					continue;
				}
				$node['atts'][ $k ] = $v;
				$applied++;
			}
		}
		if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
			foreach ( $node['_items'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$node['_items'][ $i ] = self::walk_apply( $child, $by_id, $errors, $applied );
				}
			}
		}
		return $node;
	}

	/** The value a mobile-first {base, md, lg, xl} value has at one device (the nearest set one at or below it). */
	private static function effective( array $v, $device ) {
		$order = array( 'base', 'md', 'lg', 'xl' );
		for ( $i = array_search( $device, $order, true ); $i >= 0; $i-- ) {
			if ( isset( $v[ $order[ $i ] ] ) && ! self::is_blank( $v[ $order[ $i ] ] ) ) {
				return $v[ $order[ $i ] ];
			}
		}
		return '';
	}

	/** '' / null / an empty unit-input ({value:''}) / a popover with no preset all mean "not set". */
	private static function is_blank( $v ) {
		if ( $v === null || $v === '' || $v === array() ) {
			return true;
		}
		if ( is_array( $v ) && array_key_exists( 'value', $v ) && count( $v ) <= 2 && ( $v['value'] === '' || $v['value'] === null ) ) {
			return true;
		}
		return false;
	}

	/** Visible text of the original's elements that the proposal no longer contains anywhere. */
	private static function lost_text( array $orig, array $new ) {
		$texts = array();
		self::texts( $orig, $texts );
		$all = array();
		self::strings( $new, $all );
		$hay = ' ' . implode( ' ', $all ) . ' ';
		$lost = array();
		foreach ( $texts as $t ) {
			if ( strpos( $hay, $t ) === false ) {
				$lost[] = wp_html_excerpt( $t, 40, '…' );
			}
		}
		return $lost;
	}

	/** Every string in a node, normalised the same way as texts(). */
	private static function strings( $v, array &$out ) {
		if ( is_string( $v ) ) {
			$out[] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $v ) ) ) );
		} elseif ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				self::strings( $x, $out );
			}
		}
	}

	private static function texts( array $node, array &$out ) {
		if ( ( $node['type'] ?? '' ) === 'simple' && isset( $node['atts'] ) && is_array( $node['atts'] ) ) {
			foreach ( $node['atts'] as $k => $v ) {
				if ( ! is_string( $v ) || preg_match( '/(^|_)(id|class|css|url|link|href|target|rel|icon|style|preset|align|size|tag)$/', $k ) ) {
					continue;
				}
				$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $v ) ) ) );
				// Words only: a value that is a token (a colour, a choice, a number) is a setting, not content.
				if ( strlen( $t ) >= 4 && preg_match( '/[a-z]{2,}\s+[a-z]/i', $t ) ) {
					// Compare a stable middle slice, so re-wrapped HTML or punctuation at the ends does not count as lost.
					$out[] = strlen( $t ) > 24 ? substr( $t, 0, 24 ) : $t;
				}
			}
		}
		foreach ( (array) ( $node['_items'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				self::texts( $child, $out );
			}
		}
	}
}
