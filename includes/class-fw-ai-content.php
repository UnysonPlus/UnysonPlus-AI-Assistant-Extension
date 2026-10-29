<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Content for a site built elsewhere — what a deploy needs beyond pages, so nobody uploads a seed plugin.
 * Each call is a BATCH (≤ 50), matched by a stable key so a re-run updates instead of duplicating, and
 * saved as ONE change undo_change reverts.
 *
 *   content-upsert      posts of any public type (custom ones too), matched by slug: title, content,
 *                       excerpt, status, date, order, author (login), terms, featured image (media id or
 *                       uploads-relative file), post meta, Unyson+ post options (fw_set_db_post_option).
 *   user-upsert         users matched by login: email, names, url, role (never one that can manage the
 *                       site), user meta, profile photo (→ user meta wp_metronet_image_id).
 *   menu-set            a nav menu by name with nested items (relative urls → this site), assigned to theme
 *                       locations. Re-running replaces the menu's items (the old ones come back on undo).
 *   short-links-import  rows in the Short Links extension's import format, through its own importer.
 *
 * File references ({ file: "2018/10/x.png" }) point at files already in uploads — send them first with
 * upload_media keep_path.
 */
class FW_AI_Content {

	const BATCH = 50;

	/** User meta an AI may never set: capabilities, levels, sessions, passwords. */
	const USER_META_DENY = '/(capabilit|user_level|session_tokens|_password|user_pass|wp_user-settings|application_passwords)/i';

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}
		$ref = array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'file' => array( 'type' => 'string' ) ) );

		fw_ai_register_ability( 'content-upsert', array(
			'label'       => __( 'Create or update posts of any type', 'fw' ),
			'description' => __( 'Creates or updates up to 50 posts of any public post type (post, page, custom types such as review), each matched by type + slug so running it again updates instead of duplicating. items: [{ type, slug, title, status (publish|draft|pending|private, default draft), date ("Y-m-d H:i:s"), menu_order, excerpt, content (HTML; "/wp-content/…" links are made absolute), author (a user login), terms: { taxonomy: [slugs or names] }, featured_image: { id } or { file: "2018/10/x.png" }, meta: { key: value } (keys starting with "_" are refused), fw_options: { option_id: value } (Unyson+ post options, e.g. review_options) }]. For builder pages use create_page instead. Saved as one change undo_change reverts (created posts are trashed, updated ones restored).', 'fw' ),
			'input'       => array(
				'items' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::BATCH, 'items' => array( 'type' => 'object', 'properties' => array(
					'type' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string' ), 'date' => array( 'type' => 'string' ), 'menu_order' => array( 'type' => 'integer' ),
					'excerpt' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'author' => array( 'type' => 'string' ),
					'terms' => array( 'type' => 'object' ), 'featured_image' => $ref, 'meta' => array( 'type' => 'object' ), 'fw_options' => array( 'type' => 'object' ),
				), 'required' => array( 'type', 'slug' ) ) ),
			),
			'required'    => array( 'items' ),
			'permission'  => 'edit_others_posts',
			'execute'     => array( __CLASS__, 'content_upsert' ),
		) );

		fw_ai_register_ability( 'user-upsert', array(
			'label'       => __( 'Create or update users', 'fw' ),
			'description' => __( 'Creates or updates up to 50 users, matched by login (authors, reviewers). users: [{ login, email, display_name, first_name, last_name, url, description, role (any role that cannot manage the site; default author), meta: { key: value }, photo: { id } or { file: "2020/01/jane.jpg" } }]. New users get a random password and no notification email. Administrator-level roles are refused, and meta keys for capabilities, sessions and passwords are refused. The photo is stored as user meta wp_metronet_image_id. Saved as one change undo_change reverts (created users are deleted, their content moved to you).', 'fw' ),
			'input'       => array(
				'users' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::BATCH, 'items' => array( 'type' => 'object', 'properties' => array(
					'login' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string' ), 'display_name' => array( 'type' => 'string' ),
					'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ), 'role' => array( 'type' => 'string' ), 'meta' => array( 'type' => 'object' ), 'photo' => $ref,
				), 'required' => array( 'login' ) ) ),
			),
			'required'    => array( 'users' ),
			'permission'  => 'create_users',
			'execute'     => array( __CLASS__, 'user_upsert' ),
		) );

		fw_ai_register_ability( 'menu-set', array(
			'label'       => __( 'Create or replace a navigation menu', 'fw' ),
			'description' => __( 'Creates the navigation menu called name (or replaces the items of the existing one) and assigns it to theme locations. items: [{ id (your own key), parent (another item\'s id, or empty), title, url ("/about/" is made absolute for this site) }], in display order. locations: theme location slugs (unknown ones are reported). Saved as one change undo_change reverts (the previous items and locations come back).', 'fw' ),
			'input'       => array(
				'name'      => array( 'type' => 'string' ),
				'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'items'     => array( 'type' => 'array', 'maxItems' => 200, 'items' => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => array( 'string', 'integer' ) ), 'parent' => array( 'type' => array( 'string', 'integer', 'null' ) ),
					'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ),
				), 'required' => array( 'title', 'url' ) ) ),
			),
			'required'    => array( 'name', 'items' ),
			'permission'  => 'edit_theme_options',
			'execute'     => array( __CLASS__, 'menu_set' ),
		) );

		if ( class_exists( 'FW_Short_Links_Transfer' ) ) {
			fw_ai_register_ability( 'short-links-import', array(
				'label'       => __( 'Import short links', 'fw' ),
				'description' => __( 'Imports up to 50 short links through the Short Links extension\'s own importer. rows: [{ slug, target_url, redirect_type (301|302|307), title, notes, nofollow, sponsored, categories: [names], clicks, uniques, … }] (its import format). Existing slugs are skipped, so a re-run only fills gaps. import_counts: true keeps the click / unique totals. Saved as one change undo_change reverts (the links it created are deleted, and a cleared prefix comes back).', 'fw' ),
				'input'       => array(
					'rows'          => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::BATCH, 'items' => array( 'type' => 'object' ) ),
					'import_counts' => array( 'type' => 'boolean', 'default' => false ),
					'clear_prefix'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'Blank the Short Links prefix first — for slugs that already carry their own path (go/…, play/…). Part of the same undo.' ),
				),
				'required'    => array( 'rows' ),
				'permission'  => 'manage_options',
				'execute'     => array( __CLASS__, 'short_links_import' ),
			) );
		}
	}

	/* ---------- shared ---------- */

	/**
	 * A media reference { id } or { file } → attachment id (0 when it cannot be resolved).
	 *
	 * @param mixed  $ref
	 * @param string $title
	 * @param array  $created Collects attachments created here (for undo).
	 * @return int
	 */
	private static function media_ref( $ref, $title, array &$created ) {
		if ( ! is_array( $ref ) ) {
			return 0;
		}
		if ( ! empty( $ref['id'] ) ) {
			return get_post_type( (int) $ref['id'] ) === 'attachment' ? (int) $ref['id'] : 0;
		}
		if ( ! empty( $ref['file'] ) && class_exists( 'FW_AI_Files' ) ) {
			$a = FW_AI_Files::attachment_for_file( (string) $ref['file'], $title );
			if ( $a ) {
				if ( $a['created'] ) {
					$created[] = $a['id'];
				}
				return (int) $a['id'];
			}
		}
		return 0;
	}

	/* ---------- content-upsert ---------- */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function content_upsert( $in ) {
		$items = array_slice( (array) ( $in['items'] ?? array() ), 0, self::BATCH );
		$plan  = array();
		// Validate everything first, so a bad item never leaves a half-applied batch.
		foreach ( $items as $n => $it ) {
			$type = sanitize_key( (string) ( $it['type'] ?? '' ) );
			$pto  = get_post_type_object( $type );
			if ( ! $pto || ! $pto->public || in_array( $type, array( 'attachment', 'fw-portfolio-hidden' ), true ) ) {
				return new WP_Error( 'upw_ai_content', 'Item ' . ( $n + 1 ) . ': "' . $type . '" is not a public post type on this site.' );
			}
			if ( ! current_user_can( $pto->cap->edit_others_posts ) || ! current_user_can( $pto->cap->publish_posts ) ) {
				return new WP_Error( 'upw_ai_content', 'Item ' . ( $n + 1 ) . ': your account cannot publish ' . $pto->labels->name . '.' );
			}
			$slug = sanitize_title( (string) ( $it['slug'] ?? '' ) );
			if ( $slug === '' ) {
				return new WP_Error( 'upw_ai_content', 'Item ' . ( $n + 1 ) . ': slug is needed (it is how a re-run finds the same post).' );
			}
			foreach ( array_keys( (array) ( $it['meta'] ?? array() ) ) as $k ) {
				if ( strpos( (string) $k, '_' ) === 0 ) {
					return new WP_Error( 'upw_ai_content', 'Item ' . ( $n + 1 ) . ': meta key "' . $k . '" is protected (keys starting with "_" belong to WordPress or plugins).' );
				}
			}
			foreach ( array_keys( (array) ( $it['terms'] ?? array() ) ) as $tax ) {
				if ( ! is_object_in_taxonomy( $type, (string) $tax ) ) {
					return new WP_Error( 'upw_ai_content', 'Item ' . ( $n + 1 ) . ': "' . $tax . '" is not a taxonomy of ' . $type . '.' );
				}
			}
			$plan[] = array( $it, $type, $slug, $pto->hierarchical ? get_page_by_path( $slug, OBJECT, $type ) : get_page_by_path( $slug, OBJECT, $type ) );
		}

		// One undo for the batch: existing posts' fields, meta, terms and thumbnails as they are now.
		$spec = array( 'post_fields' => array(), 'post_meta' => array(), 'post_terms' => array() );
		foreach ( $plan as $p ) {
			if ( $p[3] ) {
				$id = (int) $p[3]->ID;
				$spec['post_fields'][ $id ] = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'menu_order' );
				$spec['post_meta'][ $id ]   = array_merge( array( '_thumbnail_id', 'fw_options' ), array_keys( (array) ( $p[0]['meta'] ?? array() ) ) );
				if ( ! empty( $p[0]['terms'] ) ) {
					$spec['post_terms'][ $id ] = array_keys( (array) $p[0]['terms'] );
				}
			}
		}
		$undo = fw_ai_snapshot( $spec, 'unysonplus/content-upsert', 'Content: ' . count( $plan ) . ' item(s)' );

		$created_posts = array();
		$created_media = array();
		$out           = array();
		foreach ( $plan as $p ) {
			list( $it, $type, $slug, $existing ) = $p;
			$args = array( 'post_type' => $type, 'post_name' => $slug );
			if ( $existing ) {
				$args['ID'] = (int) $existing->ID;
			}
			foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'menu_order' => 'menu_order' ) as $k => $f ) {
				if ( isset( $it[ $k ] ) ) {
					$args[ $f ] = $it[ $k ];
				}
			}
			if ( isset( $it['content'] ) ) {
				$args['post_content'] = str_replace( array( '"/wp-content/', "'/wp-content/" ), array( '"' . home_url( '/wp-content/' ), "'" . home_url( '/wp-content/' ) ), (string) $it['content'] );
			}
			$status = sanitize_key( (string) ( $it['status'] ?? '' ) );
			if ( in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				$args['post_status'] = $status;
			} elseif ( ! $existing ) {
				$args['post_status'] = 'draft';
			}
			if ( ! empty( $it['date'] ) && strtotime( (string) $it['date'] ) ) {
				$args['post_date']     = gmdate( 'Y-m-d H:i:s', strtotime( (string) $it['date'] ) );
				$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
			}
			if ( ! empty( $it['author'] ) ) {
				$u = get_user_by( 'login', (string) $it['author'] );
				if ( $u ) {
					$args['post_author'] = $u->ID;
				}
			}
			$id = $existing ? wp_update_post( wp_slash( $args ), true ) : wp_insert_post( wp_slash( $args ), true );
			if ( is_wp_error( $id ) ) {
				$out[] = array( 'slug' => $slug, 'type' => $type, 'error' => $id->get_error_message() );
				continue;
			}
			if ( ! $existing ) {
				$created_posts[] = (int) $id;
			}
			foreach ( (array) ( $it['terms'] ?? array() ) as $tax => $terms ) {
				$ids = array();
				foreach ( (array) $terms as $t ) {
					$term = get_term_by( 'slug', sanitize_title( (string) $t ), $tax ) ?: get_term_by( 'name', (string) $t, $tax );
					if ( ! $term ) {
						$made = wp_insert_term( (string) $t, $tax, array( 'slug' => sanitize_title( (string) $t ) ) );
						$term = is_wp_error( $made ) ? null : get_term( $made['term_id'], $tax );
					}
					if ( $term ) {
						$ids[] = (int) $term->term_id;
					}
				}
				wp_set_object_terms( $id, $ids, $tax );
			}
			if ( isset( $it['featured_image'] ) ) {
				$att = self::media_ref( $it['featured_image'], (string) ( $it['title'] ?? $slug ), $created_media );
				if ( $att ) {
					set_post_thumbnail( $id, $att );
				}
			}
			foreach ( (array) ( $it['meta'] ?? array() ) as $k => $v ) {
				update_post_meta( $id, sanitize_key( (string) $k ), wp_slash( $v ) );
			}
			if ( ! empty( $it['fw_options'] ) && function_exists( 'fw_set_db_post_option' ) ) {
				foreach ( (array) $it['fw_options'] as $k => $v ) {
					fw_set_db_post_option( $id, (string) $k, $v );
				}
			}
			$out[] = array( 'slug' => $slug, 'type' => $type, 'id' => (int) $id, 'created' => ! $existing, 'url' => get_permalink( $id ) );
		}
		// Record what this batch created, so the same undo also removes it.
		self::amend_snapshot( $undo, array( 'created_posts' => $created_posts, 'created_attachments' => $created_media ) );
		return array( 'ok' => true, 'items' => $out, 'created' => count( $created_posts ), 'updated' => count( $out ) - count( $created_posts ), 'undo_revision_id' => $undo );
	}

	/**
	 * Add created ids to a snapshot taken before the work (an undo must remove what the batch made).
	 *
	 * @param int   $id
	 * @param array $add { created_posts?, created_attachments?, created_users?, created_menus?, created_short_links? }
	 */
	private static function amend_snapshot( $id, array $add ) {
		$list = (array) get_option( FW_AI_Toolkit::OPTION_REVISIONS, array() );
		foreach ( $list as &$r ) {
			if ( (int) $r['id'] === (int) $id ) {
				foreach ( $add as $k => $ids ) {
					$r['values'][ $k ] = array_values( array_unique( array_merge( (array) ( $r['values'][ $k ] ?? array() ), array_map( 'intval', (array) $ids ) ) ) );
				}
			}
		}
		unset( $r );
		update_option( FW_AI_Toolkit::OPTION_REVISIONS, $list, false );
	}

	/* ---------- user-upsert ---------- */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function user_upsert( $in ) {
		$users = array_slice( (array) ( $in['users'] ?? array() ), 0, self::BATCH );
		foreach ( $users as $n => $u ) {
			$role = (string) ( $u['role'] ?? 'author' );
			$obj  = get_role( $role );
			if ( ! $obj ) {
				return new WP_Error( 'upw_ai_users', 'User ' . ( $n + 1 ) . ': there is no role "' . $role . '".' );
			}
			foreach ( array( 'manage_options', 'promote_users', 'edit_users', 'create_users', 'activate_plugins', 'edit_theme_options', 'unfiltered_html' ) as $cap ) {
				if ( ! empty( $obj->capabilities[ $cap ] ) ) {
					return new WP_Error( 'upw_ai_users', 'User ' . ( $n + 1 ) . ': the role "' . $role . '" can manage the site, so it cannot be given from here. Use Users → Add New.' );
				}
			}
			$existing = get_user_by( 'login', (string) ( $u['login'] ?? '' ) );
			if ( $existing && user_can( $existing, 'manage_options' ) ) {
				return new WP_Error( 'upw_ai_users', 'User ' . ( $n + 1 ) . ': "' . $u['login'] . '" is an administrator and is not changed from here.' );
			}
			foreach ( array_keys( (array) ( $u['meta'] ?? array() ) ) as $k ) {
				if ( preg_match( self::USER_META_DENY, (string) $k ) || strpos( (string) $k, $GLOBALS['wpdb']->prefix ) === 0 ) {
					return new WP_Error( 'upw_ai_users', 'User ' . ( $n + 1 ) . ': meta key "' . $k . '" is protected.' );
				}
			}
		}

		$spec = array( 'user_fields' => array(), 'user_meta' => array() );
		foreach ( $users as $u ) {
			$e = get_user_by( 'login', (string) $u['login'] );
			if ( $e ) {
				$spec['user_fields'][ $e->ID ] = array( 'user_email', 'user_url', 'display_name', 'first_name', 'last_name', 'description', 'role' );
				$spec['user_meta'][ $e->ID ]   = array_merge( array( 'wp_metronet_image_id' ), array_keys( (array) ( $u['meta'] ?? array() ) ) );
			}
		}
		$undo = fw_ai_snapshot( $spec, 'unysonplus/user-upsert', 'Users: ' . count( $users ) );

		$created = array();
		$media   = array();
		$out     = array();
		foreach ( $users as $u ) {
			$login = sanitize_user( (string) $u['login'], true );
			$e     = get_user_by( 'login', $login );
			$data  = array();
			foreach ( array( 'email' => 'user_email', 'url' => 'user_url', 'display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name', 'description' => 'description' ) as $k => $f ) {
				if ( isset( $u[ $k ] ) ) {
					$data[ $f ] = $u[ $k ];
				}
			}
			$data['role'] = (string) ( $u['role'] ?? ( $e ? ( $e->roles ? reset( $e->roles ) : 'author' ) : 'author' ) );
			if ( $e ) {
				$data['ID'] = $e->ID;
				$id         = wp_update_user( $data );
			} else {
				if ( empty( $data['user_email'] ) ) {
					$out[] = array( 'login' => $login, 'error' => 'email is needed for a new user.' );
					continue;
				}
				$data['user_login'] = $login;
				$data['user_pass']  = wp_generate_password( 24 );
				$id                 = wp_insert_user( $data ); // no notification email is sent
			}
			if ( is_wp_error( $id ) ) {
				$out[] = array( 'login' => $login, 'error' => $id->get_error_message() );
				continue;
			}
			if ( ! $e ) {
				$created[] = (int) $id;
			}
			foreach ( (array) ( $u['meta'] ?? array() ) as $k => $v ) {
				update_user_meta( $id, (string) $k, $v );
			}
			if ( isset( $u['photo'] ) ) {
				$att = self::media_ref( $u['photo'], (string) ( $u['display_name'] ?? $login ), $media );
				if ( $att ) {
					update_user_meta( $id, 'wp_metronet_image_id', $att );
				}
			}
			$out[] = array( 'login' => $login, 'id' => (int) $id, 'created' => ! $e );
		}
		self::amend_snapshot( $undo, array( 'created_users' => $created, 'created_attachments' => $media ) );
		return array( 'ok' => true, 'users' => $out, 'undo_revision_id' => $undo );
	}

	/* ---------- menu-set ---------- */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function menu_set( $in ) {
		$name = trim( wp_strip_all_tags( (string) ( $in['name'] ?? '' ) ) );
		if ( $name === '' ) {
			return new WP_Error( 'upw_ai_menu', 'name is needed.' );
		}
		$registered = get_registered_nav_menus();
		$locations  = array();
		$unknown    = array();
		foreach ( (array) ( $in['locations'] ?? array() ) as $loc ) {
			if ( isset( $registered[ (string) $loc ] ) ) {
				$locations[] = (string) $loc;
			} else {
				$unknown[] = (string) $loc;
			}
		}
		$menu     = wp_get_nav_menu_object( $name );
		$old      = $menu ? wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) : array();
		$old_ids  = array_map( function ( $p ) {
			return (int) $p->ID;
		}, (array) $old );
		$undo     = fw_ai_snapshot( array( 'options' => array( 'theme_mods_' . get_stylesheet() ), 'trashed_posts' => $old_ids ), 'unysonplus/menu-set', 'Menu: ' . $name );
		$created  = array();
		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				return new WP_Error( 'upw_ai_menu', 'Could not create the menu: ' . $menu_id->get_error_message() );
			}
			self::amend_snapshot( $undo, array( 'created_menus' => array( $menu_id ) ) );
		} else {
			$menu_id = (int) $menu->term_id;
			foreach ( $old_ids as $oid ) {
				wp_trash_post( $oid ); // undo brings these back
			}
		}
		$map = array();
		$pos = 0;
		foreach ( (array) ( $in['items'] ?? array() ) as $it ) {
			$url    = (string) ( $it['url'] ?? '' );
			$url    = strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ? home_url( $url ) : $url;
			$parent = isset( $it['parent'] ) && $it['parent'] !== '' && $it['parent'] !== null && isset( $map[ (string) $it['parent'] ] ) ? $map[ (string) $it['parent'] ] : 0;
			$iid    = wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'     => wp_strip_all_tags( (string) $it['title'] ),
				'menu-item-url'       => esc_url_raw( $url ),
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => ++$pos,
				'menu-item-parent-id' => $parent,
			) );
			if ( ! is_wp_error( $iid ) ) {
				$created[] = (int) $iid;
				if ( isset( $it['id'] ) ) {
					$map[ (string) $it['id'] ] = (int) $iid;
				}
			}
		}
		self::amend_snapshot( $undo, array( 'created_posts' => $created ) );
		if ( $locations ) {
			$locs = (array) get_theme_mod( 'nav_menu_locations', array() );
			foreach ( $locations as $l ) {
				$locs[ $l ] = $menu_id;
			}
			set_theme_mod( 'nav_menu_locations', $locs );
		}
		return array(
			'ok'                 => true,
			'menu_id'            => $menu_id,
			'items'              => count( $created ),
			'replaced'           => count( $old_ids ),
			'locations'          => $locations,
			'unknown_locations'  => $unknown,
			'available_locations' => $unknown ? array_keys( $registered ) : null,
			'undo_revision_id'   => $undo,
		);
	}

	/* ---------- short-links-import ---------- */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function short_links_import( $in ) {
		if ( ! class_exists( 'FW_Short_Links_Transfer' ) || ! class_exists( 'FW_Short_Links_Repository' ) ) {
			return new WP_Error( 'upw_ai_links', 'The Short Links extension is not active (manage_extension can turn it on).' );
		}
		$rows = array();
		foreach ( array_slice( (array) ( $in['rows'] ?? array() ), 0, self::BATCH ) as $r ) {
			$row = FW_Short_Links_Transfer::normalise( (array) $r );
			if ( ! empty( $row['slug'] ) && ! empty( $row['target_url'] ) ) {
				$rows[] = $row;
			}
		}
		if ( ! $rows ) {
			return new WP_Error( 'upw_ai_links', 'No usable rows: each needs slug and target_url.' );
		}
		// Which slugs are new, so the undo deletes exactly those.
		$new = array();
		foreach ( $rows as $row ) {
			if ( ! FW_Short_Links_Repository::find_by_slug( $row['slug'] ) ) {
				$new[] = $row['slug'];
			}
		}
		// The extension's settings are one option, so the prefix change rides on the same undo.
		$undo = fw_ai_snapshot( array( 'options' => ! empty( $in['clear_prefix'] ) ? array( 'fw_ext_settings_options:short-links' ) : array() ), 'unysonplus/short-links-import', 'Short links: ' . count( $rows ) . ' row(s)' );
		$prefix_was = null;
		if ( ! empty( $in['clear_prefix'] ) && function_exists( 'fw_get_db_ext_settings_option' ) ) {
			$prefix_was = (string) fw_get_db_ext_settings_option( 'short-links', 'link_prefix', '' );
			if ( $prefix_was !== '' ) {
				fw_set_db_ext_settings_option( 'short-links', 'link_prefix', '' );
				flush_rewrite_rules( false );
			}
		}
		$job  = FW_Short_Links_Transfer::create_job( $rows, array( 'policy' => 'skip', 'import_counts' => ! empty( $in['import_counts'] ), 'source' => 'file', 'label' => 'AI Assistant' ) );
		for ( $i = 0; $i < 200 && is_array( $job ) && $job['phase'] !== 'done'; $i++ ) {
			$job = FW_Short_Links_Transfer::step( $job['id'] );
		}
		if ( is_wp_error( $job ) ) {
			return new WP_Error( 'upw_ai_links', 'The import stopped: ' . $job->get_error_message() );
		}
		$ids = array();
		foreach ( $new as $slug ) {
			$l = FW_Short_Links_Repository::find_by_slug( $slug );
			if ( $l ) {
				$ids[] = (int) $l->id;
			}
		}
		self::amend_snapshot( $undo, array( 'created_short_links' => $ids ) );
		return array( 'ok' => true, 'result' => $job['result'], 'errors' => array_slice( (array) $job['errors'], 0, 20 ), 'prefix_cleared' => $prefix_was !== null && $prefix_was !== '' ? $prefix_was : null, 'undo_revision_id' => $undo );
	}
}
FW_AI_Content::init();
