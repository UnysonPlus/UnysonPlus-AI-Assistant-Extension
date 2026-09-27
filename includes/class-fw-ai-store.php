<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Reads and writes a post's page-builder tree, and keeps the AI revision history.
 *
 * Writing follows the storage rules the builder relies on (see the AI Dev Kit's
 * building-pages.md → "How the storage works"):
 *   1. Delete the flat `..:json` / `..:builder_active` keys and the `fw_options['page-builder']`
 *      aggregate first — a stale value in either makes the page render nothing.
 *   2. Write BOTH storages (flat keys + aggregate), wp_slash()ed.
 *   3. Update the posts row with $wpdb, never wp_update_post() (its save_post handler re-syncs
 *      and can wipe the value).
 *   4. Never render during the write request (the render cache is flushed back on shutdown).
 *
 * Revisions: before every AI write the current tree is stored as one `_upw_ai_revision` post
 * meta row ({ time, user, ability, note, json }). The newest MAX_REVISIONS are kept per post.
 */
class FW_AI_Store {

	const META_JSON     = 'fw:opt:ext:pb:page-builder:json';
	const META_ACTIVE   = 'fw:opt:ext:pb:page-builder:builder_active';
	const META_REVISION = '_upw_ai_revision';
	const MAX_REVISIONS = 20;

	/**
	 * Sandboxed trees, keyed by post id. While a post is sandboxed every read and write goes to
	 * this in-memory copy instead of the database — used by the builder panel, where the AI edits
	 * the tree the person has open (unsaved changes included) and the result is handed back to the
	 * builder, which records it as one undoable step and saves it on Update like a manual edit.
	 *
	 * @var array<int, array>
	 */
	private static $sandbox = array();

	/** @var array[] Writes made to sandboxed trees during this request: { ability, note }. */
	private static $log = array();

	/* ------------------------------------------------------------------ *
	 * Sandbox
	 * ------------------------------------------------------------------ */

	/**
	 * @param int   $post_id
	 * @param array $tree
	 */
	public static function sandbox( $post_id, array $tree ) {
		self::$sandbox[ (int) $post_id ] = $tree;
	}

	/**
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_sandboxed( $post_id ) {
		return isset( self::$sandbox[ (int) $post_id ] );
	}

	/**
	 * @return array[] The sandbox write log (and clears it).
	 */
	public static function take_log() {
		$log       = self::$log;
		self::$log = array();
		return $log;
	}

	/* ------------------------------------------------------------------ *
	 * Read
	 * ------------------------------------------------------------------ */

	/**
	 * @param int $post_id
	 * @return array The tree (assoc arrays), [] when the page has no builder content.
	 */
	public static function get_tree( $post_id ) {
		if ( isset( self::$sandbox[ (int) $post_id ] ) ) {
			return self::$sandbox[ (int) $post_id ];
		}
		$json = get_post_meta( $post_id, self::META_JSON, true );
		if ( ! is_string( $json ) || $json === '' ) {
			$fw   = get_post_meta( $post_id, 'fw_options', true );
			$json = is_array( $fw ) && isset( $fw['page-builder']['json'] ) ? (string) $fw['page-builder']['json'] : '';
		}
		$tree = $json !== '' ? json_decode( $json, true ) : array();
		return is_array( $tree ) ? $tree : array();
	}

	/**
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_builder_active( $post_id ) {
		if ( isset( self::$sandbox[ (int) $post_id ] ) ) {
			return true;
		}
		$fw = get_post_meta( $post_id, 'fw_options', true );
		if ( is_array( $fw ) && isset( $fw['page-builder']['builder_active'] ) ) {
			return (bool) $fw['page-builder']['builder_active'];
		}
		return (bool) get_post_meta( $post_id, self::META_ACTIVE, true );
	}

	/* ------------------------------------------------------------------ *
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * Snapshot the current tree, then store $tree.
	 *
	 * @param int    $post_id
	 * @param array  $tree
	 * @param string $ability The ability name making the change (recorded on the revision).
	 * @param string $note    A short human description of the change.
	 * @return int|WP_Error The revision id (meta id) that undoes this write.
	 */
	public static function save_tree( $post_id, array $tree, $ability, $note = '' ) {
		if ( isset( self::$sandbox[ (int) $post_id ] ) ) {
			// Undo is the builder's own history in sandbox mode, so no revision is stored.
			self::$sandbox[ (int) $post_id ] = $tree;
			self::$log[] = array( 'ability' => (string) $ability, 'note' => (string) $note );
			return 0;
		}
		$rev = self::snapshot( $post_id, $ability, $note );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		self::write( $post_id, $tree );
		return $rev;
	}

	/**
	 * Raw write (no snapshot). Callers go through save_tree() / restore().
	 *
	 * @param int   $post_id
	 * @param array $tree
	 */
	private static function write( $post_id, array $tree ) {
		$json = wp_json_encode( self::objectify( $tree ) );

		delete_post_meta( $post_id, self::META_JSON );
		delete_post_meta( $post_id, self::META_ACTIVE );
		$fw = get_post_meta( $post_id, 'fw_options', true );
		if ( is_array( $fw ) ) {
			unset( $fw['page-builder'] );
			update_post_meta( $post_id, 'fw_options', wp_slash( $fw ) );
		}

		// Shortcode markup as post_content (search / fallback), written straight to the row.
		$content = '';
		$ot      = fw()->backend->option_type( 'page-builder' );
		if ( $ot && method_exists( $ot, 'json_to_shortcodes' ) ) {
			$content = (string) $ot->json_to_shortcodes( $json );
		}
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		update_post_meta( $post_id, self::META_JSON, wp_slash( $json ) );
		update_post_meta( $post_id, self::META_ACTIVE, true );
		$fw = get_post_meta( $post_id, 'fw_options', true );
		if ( ! is_array( $fw ) ) {
			$fw = array();
		}
		$fw['page-builder'] = array( 'json' => $json, 'builder_active' => true );
		update_post_meta( $post_id, 'fw_options', wp_slash( $fw ) );

		if ( class_exists( 'FW_Cache' ) ) {
			try {
				FW_Cache::del( 'fw:ext:page-builder:json-to-shortcodes/' . $post_id );
			} catch ( Exception $e ) {
				// Nothing cached — fine.
			}
		}

		/** Fires after the AI Assistant stores a page-builder tree on a post. */
		do_action( 'fw_ai_assistant_tree_saved', $post_id, $tree );
	}

	/**
	 * Empty `atts` must encode as {} (the builder reads them as objects), and json_decode
	 * turned every stored {} into []. Walk the tree and put the objects back.
	 *
	 * @param array $items
	 * @return array
	 */
	private static function objectify( array $items ) {
		foreach ( $items as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( ! isset( $node['atts'] ) || ( is_array( $node['atts'] ) && ! $node['atts'] ) ) {
				$node['atts'] = new stdClass();
			}
			if ( isset( $node['_items'] ) && is_array( $node['_items'] ) ) {
				$node['_items'] = self::objectify( $node['_items'] );
			} else {
				$node['_items'] = array();
			}
		}
		unset( $node );
		return $items;
	}

	/* ------------------------------------------------------------------ *
	 * Revisions
	 * ------------------------------------------------------------------ */

	/**
	 * @param int    $post_id
	 * @param string $ability
	 * @param string $note
	 * @return int|WP_Error Meta id of the new revision.
	 */
	public static function snapshot( $post_id, $ability, $note = '' ) {
		$row = array(
			'time'    => time(),
			'user'    => get_current_user_id(),
			'ability' => (string) $ability,
			'note'    => wp_html_excerpt( (string) $note, 200, '…' ),
			'active'  => self::is_builder_active( $post_id ),
			'json'    => wp_json_encode( self::get_tree( $post_id ) ),
			'content' => (string) get_post_field( 'post_content', $post_id, 'raw' ),
		);
		$id = add_post_meta( $post_id, self::META_REVISION, wp_slash( $row ) );
		if ( ! $id ) {
			return new WP_Error( 'upw_ai_snapshot_failed', 'Could not save a revision, so the change was not made.' );
		}
		self::prune( $post_id );
		return (int) $id;
	}

	/**
	 * @param int $post_id
	 */
	private static function prune( $post_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
			$post_id, self::META_REVISION
		) );
		foreach ( array_slice( $ids, self::MAX_REVISIONS ) as $old ) {
			delete_metadata_by_mid( 'post', (int) $old );
		}
	}

	/**
	 * @param int $post_id
	 * @return array Newest first, without the stored JSON.
	 */
	public static function list_revisions( $post_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
			$post_id, self::META_REVISION
		) );
		$out = array();
		foreach ( $rows as $r ) {
			$meta = get_metadata_by_mid( 'post', (int) $r->meta_id );
			if ( ! $meta || ! is_array( $meta->meta_value ) ) {
				continue;
			}
			$v     = $meta->meta_value;
			$out[] = array(
				'revision_id' => (int) $r->meta_id,
				'time'        => gmdate( 'c', (int) $v['time'] ),
				'user'        => (int) $v['user'],
				'ability'     => (string) $v['ability'],
				'note'        => (string) $v['note'],
				'items'       => count( (array) json_decode( (string) $v['json'], true ) ),
			);
		}
		return $out;
	}

	/**
	 * Restore a revision (default: the newest). The current state is snapshotted first,
	 * so an undo can itself be undone.
	 *
	 * @param int      $post_id
	 * @param int|null $revision_id
	 * @return array|WP_Error { restored: revision id, undo_revision_id }
	 */
	public static function restore( $post_id, $revision_id = null ) {
		if ( ! $revision_id ) {
			$list = self::list_revisions( $post_id );
			if ( ! $list ) {
				return new WP_Error( 'upw_ai_no_revisions', 'This page has no AI revisions to restore.' );
			}
			$revision_id = $list[0]['revision_id'];
		}
		$meta = get_metadata_by_mid( 'post', (int) $revision_id );
		if ( ! $meta || (int) $meta->post_id !== (int) $post_id || $meta->meta_key !== self::META_REVISION || ! is_array( $meta->meta_value ) ) {
			return new WP_Error( 'upw_ai_bad_revision', 'That revision does not belong to this page.' );
		}
		$v    = $meta->meta_value;
		$tree = json_decode( (string) $v['json'], true );

		$undo = self::snapshot( $post_id, 'unysonplus/undo', sprintf( 'Before restoring revision %d', $revision_id ) );
		if ( is_wp_error( $undo ) ) {
			return $undo;
		}

		if ( empty( $v['active'] ) && ! $tree ) {
			// The page was not a builder page before the AI touched it: put it back that way.
			delete_post_meta( $post_id, self::META_JSON );
			delete_post_meta( $post_id, self::META_ACTIVE );
			$fw = get_post_meta( $post_id, 'fw_options', true );
			if ( is_array( $fw ) ) {
				unset( $fw['page-builder'] );
				update_post_meta( $post_id, 'fw_options', wp_slash( $fw ) );
			}
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => (string) ( $v['content'] ?? '' ) ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		} else {
			self::write( $post_id, is_array( $tree ) ? $tree : array() );
		}

		return array(
			'restored'         => (int) $revision_id,
			'undo_revision_id' => (int) $undo,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Paths — "0.2.1" = root item 0 → its child 2 → its child 1.
	 * "id:<unique_id>" addresses an item by its atts.unique_id instead.
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve a path / id reference to an index array.
	 *
	 * @param array  $tree
	 * @param string $ref
	 * @return int[]|WP_Error
	 */
	public static function resolve( array $tree, $ref ) {
		$ref = trim( (string) $ref );
		if ( $ref === '' ) {
			return new WP_Error( 'upw_ai_bad_path', 'Empty path.' );
		}
		if ( strpos( $ref, 'id:' ) === 0 ) {
			$found = self::find_id( $tree, substr( $ref, 3 ), array() );
			return $found !== null ? $found : new WP_Error( 'upw_ai_bad_path', "No item with unique_id \"$ref\"." );
		}
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $ref ) ) {
			return new WP_Error( 'upw_ai_bad_path', "Bad path \"$ref\" — use dotted indexes like 0.1.2 (from unysonplus/get-page) or id:<unique_id>." );
		}
		$idx   = array_map( 'intval', explode( '.', $ref ) );
		$level = $tree;
		foreach ( $idx as $depth => $i ) {
			if ( ! isset( $level[ $i ] ) ) {
				return new WP_Error( 'upw_ai_bad_path', "Path \"$ref\" does not exist (no item $i at depth $depth)." );
			}
			$level = isset( $level[ $i ]['_items'] ) && is_array( $level[ $i ]['_items'] ) ? $level[ $i ]['_items'] : array();
		}
		return $idx;
	}

	/**
	 * @param array  $items
	 * @param string $id
	 * @param int[]  $prefix
	 * @return int[]|null
	 */
	private static function find_id( array $items, $id, array $prefix ) {
		foreach ( $items as $i => $node ) {
			$here = array_merge( $prefix, array( (int) $i ) );
			if ( isset( $node['atts']['unique_id'] ) && (string) $node['atts']['unique_id'] === $id ) {
				return $here;
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				$found = self::find_id( $node['_items'], $id, $here );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * @param int[] $idx
	 * @return string
	 */
	public static function path_str( array $idx ) {
		return implode( '.', $idx );
	}

	/**
	 * Reference to a node inside the tree.
	 *
	 * @param array $tree
	 * @param int[] $idx
	 * @return array Reference.
	 */
	public static function &node( array &$tree, array $idx ) {
		$ref =& $tree;
		$last = count( $idx ) - 1;
		foreach ( $idx as $d => $i ) {
			if ( $d === $last ) {
				$ref =& $ref[ $i ];
				break;
			}
			$ref =& $ref[ $i ]['_items'];
		}
		return $ref;
	}

	/**
	 * Reference to the `_items` list that $idx points INTO (root for []).
	 *
	 * @param array $tree
	 * @param int[] $idx Path of the container, [] for the page root.
	 * @return array Reference.
	 */
	public static function &children( array &$tree, array $idx ) {
		if ( ! $idx ) {
			return $tree;
		}
		$node =& self::node( $tree, $idx );
		if ( ! isset( $node['_items'] ) || ! is_array( $node['_items'] ) ) {
			$node['_items'] = array();
		}
		return $node['_items'];
	}

	/* ------------------------------------------------------------------ *
	 * Outline — a compact view of the tree for a model to read.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $items
	 * @param int[] $prefix
	 * @return array
	 */
	public static function outline( array $items, array $prefix = array() ) {
		$out = array();
		foreach ( array_values( $items ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$idx  = array_merge( $prefix, array( $i ) );
			$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
			$row  = array(
				'path' => self::path_str( $idx ),
				'type' => (string) ( $node['type'] ?? '' ),
			);
			if ( ! empty( $node['shortcode'] ) ) {
				$row['shortcode'] = (string) $node['shortcode'];
			}
			if ( ! empty( $atts['unique_id'] ) ) {
				$row['unique_id'] = (string) $atts['unique_id'];
			}
			if ( isset( $node['width'] ) ) {
				$row['width'] = (string) $node['width'];
			}
			if ( ( $node['type'] ?? '' ) === 'flexbox' ) {
				$row['html_tag'] = (string) ( $atts['html_tag'] ?? 'div' );
				$row['display']  = (string) ( $atts['display'] ?? 'flex' );
			}
			$label = self::label( $atts );
			if ( $label !== '' ) {
				$row['label'] = $label;
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				$row['items'] = self::outline( $node['_items'], $idx );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * First human-readable text found in the atts (title, heading, content …).
	 *
	 * @param array $atts
	 * @return string
	 */
	private static function label( array $atts ) {
		foreach ( array( 'title', 'heading_text', 'text', 'label', 'content', 'subtitle', 'desc' ) as $k ) {
			if ( isset( $atts[ $k ] ) && is_string( $atts[ $k ] ) && trim( wp_strip_all_tags( $atts[ $k ] ) ) !== '' ) {
				return wp_html_excerpt( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $atts[ $k ] ) ) ), 80, '…' );
			}
		}
		return '';
	}
}
