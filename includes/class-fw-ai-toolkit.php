<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The toolkit other UnysonPlus extensions use to give the AI Assistant abilities of their own.
 *
 * An extension adds its abilities from its own code, so its knowledge stays in its own repo:
 *
 *     add_action( 'fw_ai_assistant_register_abilities', function () {
 *         fw_ai_register_ability( 'seo-update-page', array(
 *             'label'       => __( 'Update a page's SEO', 'fw' ),
 *             'description' => 'What it does and when to use it — written for the AI.',
 *             'input'       => array( 'post_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ) ),
 *             'required'    => array( 'post_id' ),
 *             'permission'  => 'edit_post',            // a capability (checked against input.post_id when present) or a callable
 *             'execute'     => 'my_seo_update_page',   // callable( array $input ): array|WP_Error
 *             'readonly'    => false,
 *             'destructive' => false,
 *             'panel'       => true,                   // also offer it in the builder panel (page-scoped abilities only)
 *         ) );
 *     } );
 *
 * The hook only fires when the AI Assistant is active (and WordPress has the Abilities API), so an
 * extension needs no guard beyond hooking it. Every ability registered here:
 *   - is named `unysonplus/<slug>` and so appears automatically in the MCP server and the site-wide
 *     assistant (and in the builder panel with 'panel' => true);
 *   - gets a JSON-Schema input (unknown keys rejected), MCP exposure and read/write/destructive hints.
 *
 * Undo: before a write, call fw_ai_snapshot( array( 'options' => array( … ), 'post_meta' => array( $post_id => array( … ) ) ), $ability, $note )
 * and return its id as `undo_revision_id`; the generic `undo-change` ability restores it.
 */
class FW_AI_Toolkit {

	const OPTION_REVISIONS = 'upw_ai_change_revisions';
	const MAX_REVISIONS    = 30;

	/** @var string[] Slugs registered with 'panel' => true. */
	private static $panel_tools = array();

	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'fire' ), 20 );
	}

	/**
	 * Let extensions register their abilities (after the AI Assistant's own).
	 */
	public static function fire() {
		/**
		 * Fires when extensions should register their AI abilities with fw_ai_register_ability().
		 * Only fires while the AI Assistant extension is active on WordPress 6.9+.
		 */
		do_action( 'fw_ai_assistant_register_abilities' );
	}

	/**
	 * @param string $slug Lowercase, dashes (the ability becomes unysonplus/<slug>).
	 * @param array  $args See the class docblock.
	 * @return WP_Ability|null
	 */
	public static function register( $slug, array $args ) {
		$slug = sanitize_key( str_replace( '/', '-', (string) $slug ) );
		if ( $slug === '' || empty( $args['execute'] ) || ! is_callable( $args['execute'] ) ) {
			_doing_it_wrong( __METHOD__, 'fw_ai_register_ability() needs a slug and a callable execute.', '1.0.6' );
			return null;
		}
		$readonly = ! empty( $args['readonly'] );
		$input    = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();

		$ability_args = array(
			'label'               => (string) ( $args['label'] ?? $slug ),
			'description'         => (string) ( $args['description'] ?? '' ),
			'category'            => (string) ( $args['category'] ?? ( $readonly ? 'unysonplus-site' : 'unysonplus-build' ) ),
			'permission_callback' => self::permission( $args['permission'] ?? 'manage_options' ),
			'execute_callback'    => $args['execute'],
			'meta'                => array(
				'public'      => true,
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => $readonly,
					'destructive' => ! empty( $args['destructive'] ),
					'idempotent'  => ! empty( $args['idempotent'] ) || $readonly,
				),
			),
		);
		if ( $input ) {
			$schema = array(
				'type'                 => 'object',
				'properties'           => $input,
				'additionalProperties' => false,
			);
			if ( ! empty( $args['required'] ) ) {
				$schema['required'] = array_values( (array) $args['required'] );
			} else {
				$schema['default'] = array();
			}
			$ability_args['input_schema'] = $schema;
		}

		$ability = wp_register_ability( 'unysonplus/' . $slug, $ability_args );
		if ( $ability && ! empty( $args['panel'] ) ) {
			self::$panel_tools[] = $slug;
		}
		return $ability;
	}

	/**
	 * A permission callback from a capability (checked per post when the input has post_id) or a callable.
	 *
	 * @param string|callable $perm
	 * @return callable
	 */
	private static function permission( $perm ) {
		// A STRING is always a capability — never a function name: 'edit_post' is both a capability
		// and WordPress's edit_post() function, and calling that one dies with "not allowed".
		if ( ! is_string( $perm ) && is_callable( $perm ) ) {
			return $perm;
		}
		$cap = (string) $perm;
		return function ( $in = null ) use ( $cap ) {
			$id = is_array( $in ) && isset( $in['post_id'] ) ? (int) $in['post_id'] : 0;
			if ( $id ) {
				if ( ! get_post( $id ) ) {
					return new WP_Error( 'upw_ai_no_post', 'No post with that post_id.', array( 'status' => 404 ) );
				}
				return current_user_can( $cap, $id );
			}
			return current_user_can( $cap );
		};
	}

	/**
	 * @return string[] Slugs extensions registered for the builder panel.
	 */
	public static function panel_tools() {
		return array_values( array_unique( self::$panel_tools ) );
	}

	/* ------------------------------------------------------------------ *
	 * Generic snapshots (options + post meta) for undo
	 * ------------------------------------------------------------------ */

	/**
	 * Save the current values of some options and / or post meta keys before changing them.
	 *
	 * @param array  $spec    { options?: string[], post_meta?: { <post_id>: string[] },
	 *                          created_posts?: int[] (undo trashes them), trashed_posts?: int[] (undo restores them),
	 *                          created_menus?: int[] (nav menu term ids; undo deletes the menu — not re-creatable),
	 *                          post_fields?: { <post_id>: [ post_title | post_content | post_excerpt | post_status | post_name | menu_order | post_parent ] },
	 *                          post_terms?: { <post_id>: [ taxonomy, … ] } }
	 * @param string $ability
	 * @param string $note
	 * @return int Revision id (return it as undo_revision_id).
	 */
	public static function snapshot( array $spec, $ability, $note = '' ) {
		$values = array(
			'options'       => array(),
			'post_meta'     => array(),
			'created_posts' => array_map( 'intval', (array) ( $spec['created_posts'] ?? array() ) ),
			'trashed_posts' => array_map( 'intval', (array) ( $spec['trashed_posts'] ?? array() ) ),
			'created_menus' => array_map( 'intval', (array) ( $spec['created_menus'] ?? array() ) ),
			'post_fields'   => array(),
			'post_terms'    => array(),
		);
		foreach ( (array) ( $spec['post_fields'] ?? array() ) as $post_id => $fields ) {
			$post = get_post( (int) $post_id );
			if ( $post ) {
				foreach ( (array) $fields as $f ) {
					if ( in_array( $f, array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'menu_order', 'post_parent' ), true ) ) {
						$values['post_fields'][ (int) $post_id ][ $f ] = $post->$f;
					}
				}
			}
		}
		foreach ( (array) ( $spec['post_terms'] ?? array() ) as $post_id => $taxonomies ) {
			foreach ( (array) $taxonomies as $tax ) {
				$ids = wp_get_object_terms( (int) $post_id, (string) $tax, array( 'fields' => 'ids' ) );
				$values['post_terms'][ (int) $post_id ][ (string) $tax ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			}
		}
		foreach ( (array) ( $spec['options'] ?? array() ) as $name ) {
			$v = get_option( (string) $name, null );
			$values['options'][ (string) $name ] = array( 'exists' => $v !== null, 'value' => $v );
		}
		foreach ( (array) ( $spec['post_meta'] ?? array() ) as $post_id => $keys ) {
			foreach ( (array) $keys as $key ) {
				$exists = metadata_exists( 'post', (int) $post_id, (string) $key );
				$values['post_meta'][ (int) $post_id ][ (string) $key ] = array(
					'exists' => $exists,
					'value'  => $exists ? get_post_meta( (int) $post_id, (string) $key, true ) : null,
				);
			}
		}
		$list = (array) get_option( self::OPTION_REVISIONS, array() );
		$id   = $list ? (int) max( array_column( $list, 'id' ) ) + 1 : 1;
		array_unshift( $list, array(
			'id'      => $id,
			'time'    => time(),
			'user'    => get_current_user_id(),
			'ability' => (string) $ability,
			'note'    => wp_html_excerpt( (string) $note, 200, '…' ),
			'values'  => $values,
		) );
		update_option( self::OPTION_REVISIONS, array_slice( $list, 0, self::MAX_REVISIONS ), false );
		return $id;
	}

	/**
	 * @return array Newest first, without the stored values.
	 */
	public static function list_changes() {
		$out = array();
		foreach ( (array) get_option( self::OPTION_REVISIONS, array() ) as $r ) {
			$out[] = array(
				'revision_id' => (int) $r['id'],
				'time'        => gmdate( 'c', (int) $r['time'] ),
				'ability'     => (string) $r['ability'],
				'note'        => (string) $r['note'],
			);
		}
		return $out;
	}

	/**
	 * Restore a snapshot (the newest by default); the current values are snapshotted first.
	 *
	 * @param int|null $id
	 * @return array|WP_Error
	 */
	public static function restore( $id = null ) {
		$rev = null;
		foreach ( (array) get_option( self::OPTION_REVISIONS, array() ) as $r ) {
			if ( $id === null || (int) $r['id'] === (int) $id ) {
				$rev = $r;
				break;
			}
		}
		if ( ! $rev ) {
			return new WP_Error( 'upw_ai_no_revisions', 'No such change to undo.' );
		}
		$v    = (array) $rev['values'];
		$spec = array( 'options' => array_keys( (array) ( $v['options'] ?? array() ) ), 'post_meta' => array(), 'post_fields' => array(), 'post_terms' => array() );
		foreach ( (array) ( $v['post_meta'] ?? array() ) as $post_id => $keys ) {
			$spec['post_meta'][ $post_id ] = array_keys( (array) $keys );
		}
		foreach ( (array) ( $v['post_fields'] ?? array() ) as $post_id => $fields ) {
			$spec['post_fields'][ $post_id ] = array_keys( (array) $fields );
		}
		foreach ( (array) ( $v['post_terms'] ?? array() ) as $post_id => $taxes ) {
			$spec['post_terms'][ $post_id ] = array_keys( (array) $taxes );
		}
		// Undoing a creation trashes the post, so undoing the undo must bring it back (and vice versa).
		$spec['trashed_posts'] = (array) ( $v['created_posts'] ?? array() );
		$spec['created_posts'] = (array) ( $v['trashed_posts'] ?? array() );
		$undo = self::snapshot( $spec, 'unysonplus/undo-change', sprintf( 'Before undoing change %d', $rev['id'] ) );

		foreach ( (array) ( $v['created_posts'] ?? array() ) as $pid ) {
			if ( get_post( (int) $pid ) ) {
				wp_trash_post( (int) $pid );
			}
		}
		foreach ( (array) ( $v['created_menus'] ?? array() ) as $menu_id ) {
			if ( is_nav_menu( (int) $menu_id ) ) {
				wp_delete_nav_menu( (int) $menu_id );
			}
		}
		// Since WP 5.6 an untrashed post comes back as a DRAFT; restore its previous status instead
		// (a draft menu item, for one, silently disappears from its menu).
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		foreach ( (array) ( $v['trashed_posts'] ?? array() ) as $pid ) {
			if ( get_post_status( (int) $pid ) === 'trash' ) {
				wp_untrash_post( (int) $pid );
			}
		}
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );

		foreach ( (array) ( $v['options'] ?? array() ) as $name => $o ) {
			if ( ! empty( $o['exists'] ) ) {
				update_option( $name, $o['value'] );
			} else {
				delete_option( $name );
			}
		}
		foreach ( (array) ( $v['post_meta'] ?? array() ) as $post_id => $keys ) {
			foreach ( (array) $keys as $key => $m ) {
				if ( ! empty( $m['exists'] ) ) {
					update_post_meta( (int) $post_id, $key, wp_slash( $m['value'] ) );
				} else {
					delete_post_meta( (int) $post_id, $key );
				}
			}
			clean_post_cache( (int) $post_id );
		}
		foreach ( (array) ( $v['post_fields'] ?? array() ) as $post_id => $fields ) {
			if ( get_post( (int) $post_id ) && $fields ) {
				wp_update_post( wp_slash( array( 'ID' => (int) $post_id ) + (array) $fields ) );
			}
		}
		foreach ( (array) ( $v['post_terms'] ?? array() ) as $post_id => $taxes ) {
			foreach ( (array) $taxes as $tax => $ids ) {
				wp_set_object_terms( (int) $post_id, array_map( 'intval', (array) $ids ), (string) $tax );
			}
		}

		// The framework caches post / settings options per request; drop it so reads see the restored values.
		if ( class_exists( 'FW_Cache' ) ) {
			FW_Cache::clear();
		}

		/** Fires after the AI Assistant undoes a change (so an extension can flush caches). */
		do_action( 'fw_ai_assistant_change_restored', $rev );
		return array(
			'ok'               => true,
			'restored'         => (int) $rev['id'],
			'note'             => (string) $rev['note'],
			'undo_revision_id' => $undo,
		);
	}
}

if ( ! function_exists( 'fw_ai_register_ability' ) ) :
	/**
	 * Register an AI ability for the AI Assistant — call inside `fw_ai_assistant_register_abilities`.
	 * See FW_AI_Toolkit for the arguments.
	 *
	 * @param string $slug
	 * @param array  $args
	 * @return WP_Ability|null
	 */
	function fw_ai_register_ability( $slug, array $args ) {
		return FW_AI_Toolkit::register( $slug, $args );
	}
endif;

if ( ! function_exists( 'fw_ai_snapshot' ) ) :
	/**
	 * Snapshot options / post meta before an AI write; returns the revision id undo-change restores.
	 *
	 * @param array  $spec    { options?: string[], post_meta?: { <post_id>: string[] } }
	 * @param string $ability
	 * @param string $note
	 * @return int
	 */
	function fw_ai_snapshot( array $spec, $ability, $note = '' ) {
		return FW_AI_Toolkit::snapshot( $spec, $ability, $note );
	}
endif;
