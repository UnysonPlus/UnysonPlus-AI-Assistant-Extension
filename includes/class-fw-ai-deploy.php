<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Putting a whole package on the site from the AI — so a site edited live never needs an FTP upload:
 *
 *   install-plugin  install or update a plugin from a .zip (a url, or file data sent in pieces) through
 *                   WordPress's own installer — the same action as Plugins → Add New → Upload Plugin, which
 *                   replaces an installed plugin of the same folder. Two steps; reports the version it
 *                   replaces. On a host that refuses PHP files from the AI's request, it waits in wp-admin
 *                   for an administrator's Install click (FW_AI_Queue), like a theme. A plugin that was
 *                   active stays active. No undo: to go back, install the older package.
 *   upload-scene    put a Kinetic Scene's web export (scene.json + its pictures, footage and sounds, as a
 *                   .zip) into uploads/unysonplus/scenes/<name>/, where a Kinetic Scene element plays it
 *                   from. Media and data files only (never code). Replacing a scene keeps the previous
 *                   folder as a backup.
 *
 * Both reuse FW_AI_Files' upload in pieces (upload_id, chunk_index, chunk_count, data).
 */
class FW_AI_Deploy {

	/** File types a scene folder may hold: its data, pictures, footage, sounds, fonts and 3D models. */
	const SCENE_EXT = array( 'json', 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif', 'svg', 'mp4', 'webm', 'mov', 'm4v', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'woff', 'woff2', 'ttf', 'otf', 'glb', 'gltf', 'bin', 'riv', 'lottie', 'vtt', 'txt', 'md' );

	const SCENE_MAX_FILES = 2000;

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		fw_ai_register_ability( 'install-plugin', array(
			'label'       => __( 'Install or update a plugin', 'fw' ),
			'description' => __( 'Installs a plugin, or updates an installed one, from a .zip through WordPress\'s own installer — the same as Plugins → Add New → Upload Plugin, which replaces the installed plugin in the same folder. The .zip comes from url, OR as file data in pieces: upload_id (your own id, 6–64 letters, digits, - or _), chunk_index (0-based), chunk_count, filename, data (base64, up to 2 MB a piece; 50 MB in all). Pieces before the last only store and report progress; the last one checks the package and continues like a url call, and a later call with just upload_id (+ confirm) reuses it. TWO STEPS: without confirm nothing changes; you get the plugin\'s name and version in the package and the version it would replace — tell the person, and call again with confirm: true after they agree. On a host that only allows new code from wp-admin, the confirmed install WAITS there: the next administrator to open the dashboard sees a notice with Install / Discard; call again with just upload_id to read the outcome. A plugin that was active stays active. A broken package leaves the installed plugin as it was. There is no undo: to go back, install the older package.', 'fw' ),
			'input'       => array(
				'url'         => array( 'type' => 'string', 'description' => 'A public http(s) address of the plugin .zip.' ),
				'upload_id'   => array( 'type' => 'string', 'description' => 'Your id for a package sent as data in pieces.' ),
				'chunk_index' => array( 'type' => 'integer', 'minimum' => 0 ),
				'chunk_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60 ),
				'filename'    => array( 'type' => 'string' ),
				'data'        => array( 'type' => 'string', 'description' => 'One piece of the .zip, base64.' ),
				'confirm'     => array( 'type' => 'boolean', 'default' => false ),
			),
			'permission'  => 'install_plugins',
			'execute'     => array( __CLASS__, 'install_plugin' ),
		) );

		fw_ai_register_ability( 'upload-scene', array(
			'label'       => __( 'Upload a Kinetic Scene', 'fw' ),
			'description' => __( 'Puts a Kinetic Scene\'s web export (Kinetic Scenes → Export → For a web page: scene.json with its pictures, footage and sounds, as a .zip) into wp-content/uploads/unysonplus/scenes/<name>/, the folder a Kinetic Scene element plays it from. Export it with the base address this returns ("https://<site>/wp-content/uploads/unysonplus/scenes/<name>/") so its links point there. The .zip comes from url or as data in pieces (upload_id, chunk_index, chunk_count, data: base64, up to 2 MB a piece, 50 MB in all), like install_theme. Only data and media files are accepted (json, pictures, footage, sounds, fonts, 3D models; never code), and scene.json must be at the top of the .zip (or inside its one folder). name: letters, digits and dashes. TWO STEPS: without confirm nothing changes; you get the file count, the size and whether a scene of that name is already there. With confirm: true it is written; an existing scene is replaced and its previous folder kept as a backup (the result says where). Returns the scene.json address to give the Kinetic Scene element.', 'fw' ),
			'input'       => array(
				'name'        => array( 'type' => 'string', 'description' => 'The scene folder name, e.g. "homepage-hero".' ),
				'url'         => array( 'type' => 'string', 'description' => 'A public http(s) address of the scene .zip.' ),
				'upload_id'   => array( 'type' => 'string' ),
				'chunk_index' => array( 'type' => 'integer', 'minimum' => 0 ),
				'chunk_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60 ),
				'filename'    => array( 'type' => 'string' ),
				'data'        => array( 'type' => 'string', 'description' => 'One piece of the .zip, base64.' ),
				'confirm'     => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'    => array( 'name' ),
			'permission'  => 'upload_files',
			'execute'     => array( __CLASS__, 'upload_scene' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * shared
	 * ------------------------------------------------------------------ */

	/**
	 * The package for a call: downloaded from url, assembled from pieces, or kept by a queued job.
	 * Returns a temporary file path (the caller deletes it), progress while pieces are missing, or an error.
	 *
	 * @param array  $in
	 * @param string $code Error code.
	 * @return string|array|WP_Error
	 */
	private static function package( $in, $code ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! empty( $in['queued_package'] ) && class_exists( 'FW_AI_Queue' ) && FW_AI_Queue::$running ) {
			if ( ! is_file( (string) $in['queued_package'] ) ) {
				return new WP_Error( $code, 'The waiting package is gone.' );
			}
			$tmp = wp_tempnam( 'upw-package.zip' );
			copy( (string) $in['queued_package'], $tmp );
			return $tmp;
		}
		$url = trim( (string) ( $in['url'] ?? '' ) );
		if ( $url !== '' ) {
			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error( $code, 'url must be a public http(s) address of a .zip.' );
			}
			$tmp = download_url( $url, 120 );
			return is_wp_error( $tmp ) ? new WP_Error( $code, 'Could not download the package: ' . $tmp->get_error_message() ) : $tmp;
		}
		if ( (string) ( $in['upload_id'] ?? '' ) !== '' ) {
			return FW_AI_Files::chunked_package( $in );
		}
		return new WP_Error( $code, 'Give url, or the package as data with upload_id, chunk_index and chunk_count.' );
	}

	/**
	 * The entries of a .zip, or an error.
	 *
	 * @param string $zip
	 * @param string $code
	 * @return array|WP_Error list of { name, size }
	 */
	private static function entries( $zip, $code ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( $code, 'This server cannot read .zip files (the PHP zip extension is missing).' );
		}
		$z = new ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			return new WP_Error( $code, 'The package is not a valid .zip.' );
		}
		$out = array();
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$st = $z->statIndex( $i );
			if ( $st ) {
				$out[] = array( 'name' => str_replace( '\\', '/', (string) $st['name'] ), 'size' => (int) $st['size'], 'index' => $i );
			}
		}
		$z->close();
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * install-plugin
	 * ------------------------------------------------------------------ */

	/**
	 * The plugin in a package: its folder, main file, name and version.
	 *
	 * @param string $zip
	 * @return array|WP_Error
	 */
	private static function peek_plugin( $zip ) {
		$list = self::entries( $zip, 'upw_ai_plugin' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$z = new ZipArchive();
		$z->open( $zip );
		$found = null;
		foreach ( $list as $e ) {
			// The main file sits directly inside the package's one folder: <folder>/<file>.php.
			if ( ! preg_match( '#^([^/]+)/([^/]+\.php)$#', $e['name'], $m ) ) {
				continue;
			}
			$head = (string) $z->getFromIndex( $e['index'], 8192 );
			if ( ! preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $n ) ) {
				continue;
			}
			$version = preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $v ) ? trim( $v[1] ) : '';
			$found   = array( 'folder' => $m[1], 'file' => $m[1] . '/' . $m[2], 'name' => trim( $n[1] ), 'version' => $version, 'files' => count( $list ) );
			break;
		}
		$z->close();
		return $found ?: new WP_Error( 'upw_ai_plugin', 'No plugin found in the package: it needs one folder with the plugin\'s main .php file (the one with a "Plugin Name:" header) directly inside it.' );
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function install_plugin( $in ) {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'upw_ai_install_plugin' ) ) {
			return new WP_Error( 'upw_ai_plugin', 'This site turns file changes off (DISALLOW_FILE_MODS), so plugins cannot be installed or updated here.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$uid  = (string) ( $in['upload_id'] ?? '' );
		$qkey = 'plugin:' . ( $uid !== '' ? $uid : (string) ( $in['url'] ?? '' ) );
		// A package already waiting for wp-admin: report where it stands.
		if ( $uid !== '' && ( ! isset( $in['data'] ) || $in['data'] === '' ) && class_exists( 'FW_AI_Queue' ) && ! FW_AI_Queue::$running ) {
			$job = FW_AI_Queue::find( $qkey );
			if ( $job ) {
				return FW_AI_Queue::status( $job );
			}
		}
		$tmp = self::package( $in, 'upw_ai_plugin' );
		if ( is_wp_error( $tmp ) || is_array( $tmp ) ) {
			return $tmp;
		}
		$pkg = self::peek_plugin( $tmp );
		if ( is_wp_error( $pkg ) ) {
			wp_delete_file( $tmp );
			return $pkg;
		}
		$all    = get_plugins();
		$old    = isset( $all[ $pkg['file'] ] ) ? (string) $all[ $pkg['file'] ]['Version'] : '';
		$active = $old !== '' && ( is_plugin_active( $pkg['file'] ) || is_plugin_active_for_network( $pkg['file'] ) );
		$what   = $old !== ''
			? 'update "' . $pkg['name'] . '" from ' . $old . ' to ' . ( $pkg['version'] ?: 'the packaged version' )
			: 'install "' . $pkg['name'] . '"' . ( $pkg['version'] ? ' ' . $pkg['version'] : '' ) . ' (not activated)';

		if ( empty( $in['confirm'] ) ) {
			wp_delete_file( $tmp );
			return array(
				'ok'          => false,
				'preview'     => true,
				'plugin'      => $pkg,
				'old_version' => $old,
				'message'     => 'Nothing changed yet. This would ' . $what . '.' . ( $active ? ' It is active, so the change is live at once.' : '' )
					. ( $old !== '' && $pkg['version'] !== '' && version_compare( $pkg['version'], $old, '<' ) ? ' Note: the package is OLDER than the installed version.' : '' )
					. ' An install has no undo (to go back, install the older package). Ask the person, then call again with confirm: true.',
			);
		}

		if ( class_exists( 'FW_AI_Queue' ) && FW_AI_Queue::must_queue() ) {
			$job = FW_AI_Queue::add(
				'install_plugin',
				sprintf( 'Plugin from your AI app: %s', ucfirst( $what ) ),
				array( 'file' => FW_AI_Queue::keep_file( $tmp ) ),
				$qkey,
				'install_plugins',
				! empty( $pkg['folder'] ) ? 'plugin:' . $pkg['folder'] : ''
			);
			wp_delete_file( $tmp );
			if ( $uid !== '' ) {
				FW_AI_Files::chunks_clear( $uid );
			}
			return FW_AI_Queue::status( $job ) + array( 'plugin' => $pkg, 'old_version' => $old );
		}

		ob_start();
		$creds = request_filesystem_credentials( '', '', false, false, null );
		ob_end_clean();
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_plugin', 'This server needs FTP credentials to write files, so plugins can only be installed from Plugins → Add New.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// One install at a time: a second one overlapping a first that is still running (its request timed out at the
		// proxy while PHP went on) once left a site with no plugin at all.
		if ( get_transient( 'upw_ai_plugin_installing' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_plugin_busy', 'Another plugin install is still running on this site. Wait a minute, then check the plugin list before trying again.' );
		}
		set_transient( 'upw_ai_plugin_installing', time(), 10 * MINUTE_IN_SECONDS );
		// Replacing an installed plugin: unpack beside it, check it, then swap the two folders by renaming (instant),
		// so the site is never without the plugin while files are being copied.
		if ( $old !== '' && ! empty( $pkg['folder'] ) && 'direct' === get_filesystem_method() ) {
			$swapped = self::swap_plugin( $tmp, $pkg );
			if ( true === $swapped || is_wp_error( $swapped ) ) {
				delete_transient( 'upw_ai_plugin_installing' );
				if ( is_file( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				if ( is_wp_error( $swapped ) ) {
					return $swapped;
				}
				if ( $uid !== '' ) {
					FW_AI_Files::chunks_clear( $uid );
				}
				wp_clean_plugins_cache( true );
				if ( $active && ! is_plugin_active( $pkg['file'] ) ) {
					activate_plugin( $pkg['file'], '', is_multisite() && is_plugin_active_for_network( $pkg['file'] ), true );
				}
				$now = get_plugins();
				$ver = isset( $now[ $pkg['file'] ] ) ? (string) $now[ $pkg['file'] ]['Version'] : $pkg['version'];
				return array( 'ok' => true, 'plugin' => $pkg['file'], 'name' => $pkg['name'], 'version' => $ver, 'old_version' => $old, 'active' => is_plugin_active( $pkg['file'] ), 'swapped' => true, 'message' => 'Updated from ' . $old . ' to ' . $ver . ' (swapped in place).' );
			}
			// null: the swap could not be prepared (nothing was changed); fall back to WordPress's own installer.
		}
		$run = function () use ( $tmp ) {
			$copy = wp_tempnam( 'upw-plugin.zip' );
			copy( $tmp, $copy );
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			// overwrite_package: replace the installed plugin of the same folder, as Upload Plugin's
			// "Replace current with uploaded" does. The plugin stays in the active list throughout.
			$result = $upgrader->install( $copy, array( 'overwrite_package' => true, 'clear_update_cache' => true ) );
			if ( is_file( $copy ) ) {
				wp_delete_file( $copy );
			}
			return array( $upgrader, $result );
		};
		list( $upgrader, $result ) = $run();
		$copy_failed = is_wp_error( $result )
			? in_array( $result->get_error_code(), array( 'copy_failed_ziparchive', 'incompatible_archive', 'copy_failed_pclzip' ), true )
			: ( ! $result && preg_match( '/could not copy file/i', implode( ' ', array_map( 'wp_strip_all_tags', (array) $upgrader->skin->get_upgrade_messages() ) ) ) );
		if ( $copy_failed ) {
			add_filter( 'unzip_file_use_ziparchive', '__return_false' );
			list( $upgrader, $result ) = $run();
			remove_filter( 'unzip_file_use_ziparchive', '__return_false' );
		}
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		delete_transient( 'upw_ai_plugin_installing' );
		if ( is_wp_error( $result ) || ! $result ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() . ' (' . $result->get_error_code() . ')' : implode( ' ', array_map( 'wp_strip_all_tags', (array) $upgrader->skin->get_upgrade_messages() ) );
			return new WP_Error( 'upw_ai_plugin', 'The plugin was not installed. ' . $msg );
		}
		if ( $uid !== '' ) {
			FW_AI_Files::chunks_clear( $uid );
		}
		wp_clean_plugins_cache( true );
		$file = $upgrader->plugin_info() ?: $pkg['file'];
		// Keep it on: a plugin that was active before stays active (nothing in this path should switch it
		// off, but a host's code or a renamed main file could).
		if ( $active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', is_multisite() && is_plugin_active_for_network( $file ), true );
		}
		$now = get_plugins();
		$ver = isset( $now[ $file ] ) ? (string) $now[ $file ]['Version'] : $pkg['version'];
		return array(
			'ok'          => true,
			'plugin'      => $file,
			'name'        => $pkg['name'],
			'version'     => $ver,
			'old_version' => $old,
			'active'      => is_plugin_active( $file ),
			'message'     => ( $old !== '' ? 'Updated from ' . $old . ' to ' . $ver : 'Installed ' . $ver . ' (not activated)' ) . '.',
		);
	}

	/**
	 * Replace an installed plugin without a gap: unzip the package into wp-content/upgrade, check its main file, then
	 * rename the installed folder away and the new one into place (and back if the second rename fails). Extensions
	 * the site had that the package does not ship (downloaded through the Extensions manager) are carried over, as the
	 * plugin-update hooks do. The previous folder is kept in wp-content/upgrade until the next swap.
	 *
	 * @return true|WP_Error|null  true = swapped; WP_Error = refused or failed (the installed plugin is in place);
	 *                             null = could not prepare (nothing changed; use the normal installer).
	 */
	private static function swap_plugin( $zip, array $pkg ) {
		global $wp_filesystem;
		$folder = (string) $pkg['folder'];
		$dest   = WP_PLUGIN_DIR . '/' . $folder;
		$base   = WP_CONTENT_DIR . '/upgrade';
		if ( ! is_dir( $dest ) || ! wp_mkdir_p( $base ) ) {
			return null;
		}
		$stamp = gmdate( 'YmdHis' );
		$work  = $base . '/upw-plugin-new-' . $stamp;
		$res   = unzip_file( $zip, $work );
		if ( is_wp_error( $res ) ) {
			self::rmdir_r( $work );
			return null;
		}
		$new = $work . '/' . $folder;
		if ( ! is_file( $new . '/' . basename( $pkg['file'] ) ) ) {
			self::rmdir_r( $work );
			return new WP_Error( 'upw_ai_plugin', 'The package has no ' . $pkg['file'] . ' inside; nothing was changed.' );
		}
		// Extensions the site has and the package does not: carry them over.
		$ext_old = $dest . '/framework/extensions';
		$ext_new = $new . '/framework/extensions';
		if ( is_dir( $ext_old ) && is_dir( $ext_new ) ) {
			foreach ( (array) scandir( $ext_old ) as $e ) {
				if ( '.' === $e || '..' === $e || ! is_dir( $ext_old . '/' . $e ) || file_exists( $ext_new . '/' . $e ) ) {
					continue;
				}
				copy_dir( $ext_old . '/' . $e, $ext_new . '/' . $e );
			}
		}
		// Only the latest backup is kept.
		foreach ( (array) glob( $base . '/upw-plugin-old-*' ) as $prev ) {
			self::rmdir_r( $prev );
		}
		$backup = $base . '/upw-plugin-old-' . $stamp;
		if ( ! @rename( $dest, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::rmdir_r( $work );
			return null;
		}
		if ( ! @rename( $new, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rename( $backup, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::rmdir_r( $work );
			return new WP_Error( 'upw_ai_plugin', 'The new plugin could not be moved into place; the installed one was put back. Nothing changed.' );
		}
		self::rmdir_r( $work );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * upload-scene
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function upload_scene( $in ) {
		$name = strtolower( trim( (string) ( $in['name'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/', $name ) ) {
			return new WP_Error( 'upw_ai_scene', 'name must be letters, digits and dashes (up to 63), e.g. "homepage-hero".' );
		}
		$dirs = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'scenes' ) : array( 'path' => wp_upload_dir()['basedir'] . '/unysonplus/scenes', 'url' => wp_upload_dir()['baseurl'] . '/unysonplus/scenes' );
		$dest = $dirs['path'] . '/' . $name;
		$base = $dirs['url'] . '/' . $name . '/';

		// Only the name: say where it would go (the address to export with) and whether it exists.
		if ( empty( $in['url'] ) && empty( $in['upload_id'] ) ) {
			return array( 'ok' => true, 'name' => $name, 'base' => $base, 'exists' => is_file( $dest . '/scene.json' ), 'message' => 'Export the scene with this base address, then send its .zip with upload_id and pieces.' );
		}

		$tmp = self::package( $in, 'upw_ai_scene' );
		if ( is_wp_error( $tmp ) || is_array( $tmp ) ) {
			return $tmp;
		}
		$list = self::entries( $tmp, 'upw_ai_scene' );
		if ( is_wp_error( $list ) ) {
			wp_delete_file( $tmp );
			return $list;
		}
		// Strip the one folder a .zip of a folder has (my-scene/scene.json → scene.json).
		$files = array_values( array_filter( $list, function ( $e ) {
			return substr( $e['name'], -1 ) !== '/';
		} ) );
		$strip = '';
		$names = array_column( $files, 'name' );
		if ( ! in_array( 'scene.json', $names, true ) ) {
			foreach ( $names as $n ) {
				if ( preg_match( '#^([^/]+)/scene\.json$#', $n, $m ) ) {
					$strip = $m[1] . '/';
					break;
				}
			}
			if ( $strip === '' ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_scene', 'No scene.json at the top of the .zip (or inside its one folder). Send the web export folder.' );
			}
		}
		if ( count( $files ) > self::SCENE_MAX_FILES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_scene', 'The scene has more than ' . self::SCENE_MAX_FILES . ' files.' );
		}
		$plan    = array();
		$refused = array();
		$size    = 0;
		foreach ( $files as $e ) {
			$rel = $e['name'];
			if ( $strip !== '' ) {
				if ( strpos( $rel, $strip ) !== 0 ) {
					continue; // outside the scene's folder (e.g. __MACOSX/)
				}
				$rel = substr( $rel, strlen( $strip ) );
			}
			$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			$bad = $rel === '' || strpos( $rel, '..' ) !== false || $rel[0] === '/' || preg_match( '#(^|/)\.#', $rel ) || ! preg_match( '#^[A-Za-z0-9._ /()+-]+$#', $rel ) || ! in_array( $ext, self::SCENE_EXT, true );
			if ( $bad ) {
				$refused[] = $rel;
				continue;
			}
			$plan[] = array( 'index' => $e['index'], 'rel' => $rel, 'ext' => $ext );
			$size  += $e['size'];
		}
		if ( $refused ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_scene', 'Nothing was written. The scene may only hold data and media files with plain names; these are not allowed: ' . implode( ', ', array_slice( $refused, 0, 10 ) ) . ( count( $refused ) > 10 ? ' …' : '' ) );
		}
		$exists = is_dir( $dest );

		if ( empty( $in['confirm'] ) ) {
			wp_delete_file( $tmp );
			return array(
				'ok'      => false,
				'preview' => true,
				'name'    => $name,
				'files'   => count( $plan ),
				'bytes'   => $size,
				'exists'  => $exists,
				'base'    => $base,
				'message' => 'Nothing changed yet. This would ' . ( $exists ? 'REPLACE the scene "' . $name . '" (its current folder is kept as a backup)' : 'add the scene "' . $name . '"' ) . ': ' . count( $plan ) . ' files, ' . size_format( $size ) . '. Call again with the same upload_id (no data) and confirm: true.',
			);
		}

		// Write into a fresh folder beside the scene, then swap it in, so a page never plays half a scene.
		$z = new ZipArchive();
		if ( $z->open( $tmp ) !== true ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_scene', 'The package is not a valid .zip.' );
		}
		$work = $dirs['path'] . '/.incoming-' . $name . '-' . wp_generate_password( 6, false, false );
		wp_mkdir_p( $work );
		foreach ( $plan as $p ) {
			$data = $z->getFromIndex( $p['index'] );
			if ( $data === false ) {
				$z->close();
				self::rmdir_r( $work );
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_scene', 'Could not read ' . $p['rel'] . ' from the package. Nothing was changed.' );
			}
			if ( $p['ext'] === 'svg' ) {
				if ( ! function_exists( 'fw_upw_sanitize_svg' ) ) {
					continue; // no sanitiser here: leave SVGs out rather than serve them unchecked
				}
				$data = fw_upw_sanitize_svg( (string) $data );
			}
			$to = $work . '/' . $p['rel'];
			wp_mkdir_p( dirname( $to ) );
			if ( false === file_put_contents( $to, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$z->close();
				self::rmdir_r( $work );
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_scene', 'This server did not let the file ' . $p['rel'] . ' be written. Nothing was changed.' );
			}
		}
		$z->close();
		wp_delete_file( $tmp );

		$backup = '';
		if ( $exists ) {
			$tmpdir = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'tmp' )['path'] : wp_upload_dir()['basedir'] . '/unysonplus/tmp';
			wp_mkdir_p( $tmpdir );
			// One backup per scene: the previous one goes.
			foreach ( (array) glob( $tmpdir . '/scene-backup-' . $name . '-*', GLOB_ONLYDIR ) as $old ) {
				self::rmdir_r( $old );
			}
			$backup = $tmpdir . '/scene-backup-' . $name . '-' . gmdate( 'Ymd-His' );
			if ( ! @rename( $dest, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::rmdir_r( $work );
				return new WP_Error( 'upw_ai_scene', 'Could not move the current scene aside. Nothing was changed.' );
			}
		}
		if ( ! @rename( $work, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $backup !== '' ) {
				@rename( $backup, $dest ); // phpcs:ignore
			}
			self::rmdir_r( $work );
			return new WP_Error( 'upw_ai_scene', 'Could not put the new scene in place. The previous one was left as it was.' );
		}
		if ( $uid = (string) ( $in['upload_id'] ?? '' ) ) {
			FW_AI_Files::chunks_clear( $uid );
		}
		return array(
			'ok'      => true,
			'name'    => $name,
			'files'   => count( $plan ),
			'bytes'   => $size,
			'scene'   => $base . 'scene.json',
			'base'    => $base,
			'backup'  => $backup !== '' ? str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', wp_normalize_path( $backup ) ) : '',
			'message' => ( $exists ? 'Replaced' : 'Added' ) . ' the scene "' . $name . '" (' . count( $plan ) . ' files). Its address: ' . $base . 'scene.json' . ( $backup !== '' ? '. The previous folder is kept as a backup until the next replace.' : '.' ),
		);
	}

	/** Remove a folder and everything in it. */
	private static function rmdir_r( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
