<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Files and site structure, from the AI — what a from-scratch build needs beyond pages and settings,
 * for an AI that has no terminal (the chat panel, a desktop AI app connected over MCP).
 *
 *   upload-media       an image / SVG / video / PDF from a URL or from base64 data into the Media Library,
 *                      with title, alt text and caption. WordPress's own file-type checks decide what is
 *                      accepted; an SVG goes through the framework's sanitiser first. Undo deletes it.
 *   child-theme-files  list / read / write / delete files in the ACTIVE CHILD THEME only. Writes are two
 *                      steps (preview, then confirm), every write backs the file up first and undo
 *                      restores it byte for byte. PHP: needs theme-editing rights, a site where file
 *                      editing is on, the "let the AI write PHP" switch (off by default), and a clean
 *                      syntax check before anything is saved.
 *   install-theme      install or update a theme from a .zip URL through WordPress's own installer — the
 *                      same action as Appearance → Themes → Upload Theme. Two steps; reports the version
 *                      it replaced. No undo (re-install the older package instead).
 *   reading-settings   front page / posts page, posts per page and the permalink structure. Undoable.
 *
 * Principle: nothing beyond what the account can already do in wp-admin. The parent theme, plugins and
 * running code stay out of reach of the file tools.
 */
class FW_AI_Files {

	/** Site-wide switch: may the AI write PHP files in the child theme? Off unless an administrator turns it on. */
	const OPTION_PHP = 'upw_ai_theme_php';

	const MAX_UPLOAD = 20971520; // 20 MB
	const MAX_FILE   = 524288;   // 512 KB per theme file (every write keeps a backup copy)
	const MAX_LIST   = 500;

	/** File types the child-theme tool works with. Anything else (.htaccess, .phar, executables) is refused. */
	const TEXT_EXT   = array( 'css', 'js', 'mjs', 'json', 'php', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'twig' );
	const BINARY_EXT = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf' );

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register' ) );
		// Undoing a permalink change must rebuild the rewrite rules, as saving one does.
		add_action( 'fw_ai_assistant_change_restored', function ( $rev ) {
			if ( isset( $rev['values']['options']['permalink_structure'] ) ) {
				flush_rewrite_rules( false );
			}
		} );
	}

	/**
	 * @return bool
	 */
	public static function php_allowed() {
		return get_option( self::OPTION_PHP, 'no' ) === 'yes';
	}

	public static function register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		fw_ai_register_ability( 'upload-media', array(
			'label'       => __( 'Upload a file to the Media Library', 'fw' ),
			'description' => __( 'Adds an image, SVG, video, audio file or PDF to the Media Library, from a public URL (url) or from file data (data: base64, or a data: URI, plus filename). Set title, alt (one short sentence saying what the image shows) and caption; attach_to attaches it to a post. WordPress decides which file types are allowed and says why when it refuses one; an SVG is cleaned of scripts first. Returns the id and url — use { attachment_id, url, alt } as an element\'s image value. Saved as a change undo_change reverts (it deletes the upload).', 'fw' ),
			'input'       => array(
				'url'       => array( 'type' => 'string', 'description' => 'A public http(s) address of the file.' ),
				'data'      => array( 'type' => 'string', 'description' => 'The file itself as base64 (or a data: URI), instead of url.' ),
				'filename'  => array( 'type' => 'string', 'description' => 'File name with extension, e.g. logo.svg. Needed with data; optional with url.' ),
				'title'     => array( 'type' => 'string' ),
				'alt'       => array( 'type' => 'string' ),
				'caption'   => array( 'type' => 'string' ),
				'attach_to' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'keep_path' => array( 'type' => 'string', 'description' => 'Store at exactly this uploads-relative path, e.g. "2018/10/logo.png", so content embedding /wp-content/uploads/2018/10/logo.png keeps working. Re-sending the same file is a no-op; a different file at that path is refused.' ),
			),
			'permission'  => 'upload_files',
			'execute'     => array( __CLASS__, 'upload_media' ),
		) );

		fw_ai_register_ability( 'child-theme-files', array(
			'label'       => __( 'Child theme files', 'fw' ),
			'description' => __( 'Lists, reads, writes or deletes files in the ACTIVE CHILD THEME only (style.css, JS, JSON, templates, images, functions.php). path is relative to the child theme, e.g. "style.css" or "assets/js/site.js"; anything outside it (the parent theme, plugins, "..") is refused. action list (optionally under path) / read / write (content as text, or data as base64 for an image or font) / delete. write and delete are TWO STEPS: without confirm nothing changes and you get what would change — tell the person, and call again with confirm: true after they agree. Every write backs the file up first; undo_change restores it exactly. PHP files: only for accounts that can edit themes, on sites where file editing is on and an administrator has allowed the AI to write PHP (AI Assistant → Settings); a file with a syntax error is refused before saving. Prefer Theme Settings → Custom CSS and the page tools for styling; use this for what only a theme file can do. No child theme yet? create_child_theme makes one.', 'fw' ),
			'input'       => array(
				'action'  => array( 'type' => 'string', 'enum' => array( 'list', 'read', 'write', 'delete' ) ),
				'path'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string', 'description' => 'write: the WHOLE new file content (text files).' ),
				'data'    => array( 'type' => 'string', 'description' => 'write: base64 content for an image or font, instead of content.' ),
				'confirm' => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'    => array( 'action' ),
			'permission'  => 'edit_theme_options',
			'execute'     => array( __CLASS__, 'theme_files' ),
		) );

		fw_ai_register_ability( 'install-theme', array(
			'label'       => __( 'Install or update a theme', 'fw' ),
			'description' => __( 'Installs a theme, or updates an installed one, from a .zip through WordPress\'s own installer — the same as Appearance → Themes → Add New → Upload Theme. The .zip comes from url, OR as file data in pieces: upload_id (your own id, 6–64 letters, digits, - or _), chunk_index (0-based), chunk_count, filename, data (base64, up to 2 MB a piece). Pieces before the last only store and report progress; the last one assembles the package and continues like a url call, and a later call with just upload_id (+ confirm) reuses it. A GitHub tag archive (folder "<Repo>-<tag>") whose theme name matches an installed theme updates that theme. TWO STEPS: without confirm nothing changes; you get the theme\'s name and version in the package and the version it would replace — tell the person, and call again with confirm: true after they agree. activate: true also switches the site to it (undoable with undo_change). A broken package leaves the installed theme as it was. The install itself has no undo: to go back, install the older package.', 'fw' ),
			'input'       => array(
				'url'         => array( 'type' => 'string', 'description' => 'A public http(s) address of the theme .zip.' ),
				'upload_id'   => array( 'type' => 'string', 'description' => 'Your id for a package sent as data in pieces.' ),
				'chunk_index' => array( 'type' => 'integer', 'minimum' => 0 ),
				'chunk_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60 ),
				'filename'    => array( 'type' => 'string' ),
				'data'        => array( 'type' => 'string', 'description' => 'One piece of the .zip, base64.' ),
				'activate'    => array( 'type' => 'boolean', 'default' => false ),
				'confirm'     => array( 'type' => 'boolean', 'default' => false ),
			),
			'permission'  => 'install_themes',
			'execute'     => array( __CLASS__, 'install_theme' ),
		) );

		fw_ai_register_ability( 'reading-settings', array(
			'label'       => __( 'Front page, posts page and permalinks', 'fw' ),
			'description' => __( 'Reads and sets what the home page shows and how addresses look (Settings → Reading and Permalinks). With no arguments it only returns the current values. front_page: a page id to use as the home page, or 0 for the latest posts; posts_page: the page id that lists posts (0 for none); posts_per_page; permalink_structure, e.g. "/%postname%/" (the usual choice) or "" for plain ?p=123 addresses. Saved as a change undo_change reverts.', 'fw' ),
			'input'       => array(
				'front_page'          => array( 'type' => 'integer', 'minimum' => 0 ),
				'posts_page'          => array( 'type' => 'integer', 'minimum' => 0 ),
				'posts_per_page'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				'permalink_structure' => array( 'type' => 'string' ),
			),
			'permission'  => 'manage_options',
			'execute'     => array( __CLASS__, 'reading_settings' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * upload-media
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function upload_media( $in ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$url  = trim( (string) ( $in['url'] ?? '' ) );
		$data = trim( (string) ( $in['data'] ?? '' ) );
		$name = sanitize_file_name( (string) ( $in['filename'] ?? '' ) );
		if ( ( $url === '' ) === ( $data === '' ) ) {
			return new WP_Error( 'upw_ai_upload', 'Give either url or data (with filename), not both or neither.' );
		}

		if ( $url !== '' ) {
			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'upw_ai_upload', 'url must be a public http(s) address.' );
			}
			if ( $name === '' ) {
				$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			}
			$tmp = download_url( $url, 60 ); // wp_safe_remote_get: refuses local / private addresses
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'upw_ai_upload', 'Could not download the file: ' . $tmp->get_error_message() );
			}
			if ( filesize( $tmp ) > self::MAX_UPLOAD ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_upload', 'The file is larger than 20 MB.' );
			}
		} else {
			if ( preg_match( '#^data:([a-z0-9.+/-]+);base64,#i', $data, $m ) ) {
				$data = substr( $data, strlen( $m[0] ) );
			}
			if ( $name === '' ) {
				return new WP_Error( 'upw_ai_upload', 'filename (with its extension) is needed with data.' );
			}
			$bin = base64_decode( preg_replace( '/\s+/', '', $data ), true );
			if ( $bin === false || $bin === '' ) {
				return new WP_Error( 'upw_ai_upload', 'data is not valid base64.' );
			}
			if ( strlen( $bin ) > self::MAX_UPLOAD ) {
				return new WP_Error( 'upw_ai_upload', 'The file is larger than 20 MB.' );
			}
			$tmp = wp_tempnam( $name );
			file_put_contents( $tmp, $bin );
		}
		if ( $name === '' || strpos( $name, '.' ) === false ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_upload', 'The file name has no extension, so its type is unknown. Pass filename, e.g. photo.jpg.' );
		}

		// SVG: same rule as the media uploader (administrators by default), cleaned here so it never
		// depends on another extension's upload filter being active.
		$is_svg = (bool) preg_match( '/\.svgz?$/i', $name );
		if ( $is_svg ) {
			$allowed = function_exists( 'fw_upw_svg_allowed' ) ? fw_upw_svg_allowed() : current_user_can( 'manage_options' );
			if ( ! $allowed || ! function_exists( 'fw_upw_sanitize_svg' ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_upload', 'SVG uploads are limited to administrators on this site.' );
			}
			$clean = fw_upw_sanitize_svg( (string) file_get_contents( $tmp ) );
			if ( $clean === '' ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'upw_ai_upload', 'This SVG could not be cleaned of scripts, so it was refused.' );
			}
			if ( stripos( $clean, '<?xml' ) === false ) {
				$clean = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $clean;
			}
			file_put_contents( $tmp, $clean );
			$mimes = function ( $m ) {
				$m['svg'] = 'image/svg+xml';
				return $m;
			};
			add_filter( 'upload_mimes', $mimes );
		}

		// keep_path: store the file at exactly that uploads path ("2018/10/x.png"), so content that embeds it
		// by URL keeps working without rewriting. Refuses "..", and refuses to replace a DIFFERENT file.
		$keep = trim( str_replace( '\\', '/', (string) ( $in['keep_path'] ?? '' ) ), '/' );
		if ( $keep !== '' ) {
			$res = self::store_at_path( $tmp, $keep, sanitize_text_field( (string) ( $in['title'] ?? '' ) ) );
			if ( $is_svg ) {
				remove_filter( 'upload_mimes', $mimes );
			}
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			list( $id, $created ) = $res;
			if ( isset( $in['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $in['alt'] ) );
			}
			if ( isset( $in['caption'] ) ) {
				wp_update_post( array( 'ID' => $id, 'post_excerpt' => wp_kses_post( (string) $in['caption'] ) ) );
			}
			$url = wp_get_attachment_url( $id );
			return array(
				'ok'               => true,
				'id'               => $id,
				'url'              => $url,
				'path'             => $keep,
				'unchanged'        => ! $created,
				'image_value'      => array( 'attachment_id' => (string) $id, 'url' => $url, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				'undo_revision_id' => $created ? fw_ai_snapshot( array( 'created_attachments' => array( $id ) ), 'unysonplus/upload-media', 'Uploaded ' . $keep ) : null,
			);
		}

		$title = sanitize_text_field( (string) ( $in['title'] ?? '' ) );
		$id    = media_handle_sideload(
			array( 'name' => $name, 'tmp_name' => $tmp ),
			absint( $in['attach_to'] ?? 0 ),
			$title !== '' ? $title : null
		);
		if ( $is_svg ) {
			remove_filter( 'upload_mimes', $mimes );
		}
		if ( is_wp_error( $id ) ) {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'upw_ai_upload', 'WordPress refused the file: ' . $id->get_error_message() );
		}

		if ( isset( $in['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $in['alt'] ) );
		}
		if ( isset( $in['caption'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => wp_kses_post( (string) $in['caption'] ) ) );
		}
		$undo = fw_ai_snapshot( array( 'created_attachments' => array( $id ) ), 'unysonplus/upload-media', 'Uploaded ' . $name );
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'ok'               => true,
			'id'               => $id,
			'url'              => wp_get_attachment_url( $id ),
			'mime'             => get_post_mime_type( $id ),
			'width'            => (int) ( $meta['width'] ?? 0 ),
			'height'           => (int) ( $meta['height'] ?? 0 ),
			'alt'              => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'image_value'      => array( 'attachment_id' => (string) $id, 'url' => wp_get_attachment_url( $id ), 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'undo_revision_id' => $undo,
		);
	}

	/**
	 * Put a file at an exact uploads-relative path and make sure it has a Media Library entry.
	 *
	 * @param string $src   A local temporary file.
	 * @param string $rel   "2018/10/x.png"
	 * @param string $title
	 * @return array|WP_Error [ attachment id, created? ]
	 */
	private static function store_at_path( $src, $rel, $title = '' ) {
		if ( strpos( $rel, '..' ) !== false || preg_match( '#(^|/)\.|[:\0]#', $rel ) || ! preg_match( '#^[A-Za-z0-9._/@ ()+-]+$#', $rel ) ) {
			return new WP_Error( 'upw_ai_upload', 'keep_path must be a plain uploads-relative path like "2018/10/logo.png".' );
		}
		$check = wp_check_filetype_and_ext( $src, wp_basename( $rel ) );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'upw_ai_upload', 'WordPress refused the file: this file type is not allowed (' . wp_basename( $rel ) . ').' );
		}
		$up   = wp_get_upload_dir();
		$dest = trailingslashit( $up['basedir'] ) . $rel;
		if ( is_file( $dest ) ) {
			if ( md5_file( $dest ) !== md5_file( $src ) ) {
				return new WP_Error( 'upw_ai_upload', 'A different file already exists at ' . $rel . '; it was not replaced.' );
			}
			$id = self::attachment_for_file( $rel, $title );
			return $id ? array( $id['id'], $id['created'] ) : new WP_Error( 'upw_ai_upload', 'The file exists but could not be added to the Media Library.' );
		}
		wp_mkdir_p( dirname( $dest ) );
		if ( ! @copy( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$err = error_get_last();
			return new WP_Error( 'upw_ai_upload', 'Could not write ' . $rel . ' (' . ( $err ? $err['message'] : 'no reason given' ) . ').' );
		}
		$id = self::attachment_for_file( $rel, $title );
		return $id ? array( $id['id'], true ) : new WP_Error( 'upw_ai_upload', 'The file was stored but could not be added to the Media Library.' );
	}

	/**
	 * The attachment for a file already in uploads ("2018/10/x.png"), creating it (and its image sizes)
	 * the first time. Shared by keep_path and the content tools' { file } references.
	 *
	 * @param string $rel
	 * @param string $title
	 * @return array|null { id, created }
	 */
	public static function attachment_for_file( $rel, $title = '' ) {
		global $wpdb;
		$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( $rel === '' || strpos( $rel, '..' ) !== false ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $rel ) );
		if ( $id ) {
			return array( 'id' => $id, 'created' => false );
		}
		$path = trailingslashit( wp_get_upload_dir()['basedir'] ) . $rel;
		if ( ! is_file( $path ) ) {
			return null;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$type = wp_check_filetype( $path );
		$id   = wp_insert_attachment( array( 'post_mime_type' => $type['type'], 'post_title' => $title !== '' ? $title : preg_replace( '/\.[^.]+$/', '', wp_basename( $rel ) ), 'post_status' => 'inherit' ), $path );
		if ( ! $id || is_wp_error( $id ) ) {
			return null;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		return array( 'id' => (int) $id, 'created' => true );
	}

	/* ------------------------------------------------------------------ *
	 * child-theme-files
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve a child-theme-relative path, or explain why not.
	 *
	 * @param string $rel
	 * @param bool   $allow_dir
	 * @return array|WP_Error { abs, rel, ext }
	 */
	private static function resolve( $rel, $allow_dir = false ) {
		if ( get_stylesheet() === get_template() ) {
			return new WP_Error( 'upw_ai_files', 'The active theme is not a child theme, and the file tools only work inside one (a parent theme is replaced by its updates). create_child_theme makes one.' );
		}
		$base = wp_normalize_path( untrailingslashit( get_stylesheet_directory() ) );
		$rel  = trim( str_replace( '\\', '/', (string) $rel ) );
		$rel  = ltrim( preg_replace( '#/+#', '/', $rel ), '/' );
		if ( $rel === '' ) {
			return $allow_dir ? array( 'abs' => $base, 'rel' => '', 'ext' => '' ) : new WP_Error( 'upw_ai_files', 'path is needed.' );
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( $seg === '..' || $seg === '.' || $seg === '' || $seg[0] === '.' ) {
				return new WP_Error( 'upw_ai_files', 'Refused: "' . $rel . '" is outside the child theme or a hidden file. Paths are relative to the child theme folder.' );
			}
		}
		if ( preg_match( '#^[a-z]:#i', $rel ) || strpos( $rel, ':' ) !== false || strpos( $rel, "\0" ) !== false ) {
			return new WP_Error( 'upw_ai_files', 'Refused: give a path relative to the child theme, e.g. "style.css".' );
		}
		$abs = $base . '/' . $rel;
		// A folder that exists must really be inside the child theme (no symbolic link leading out).
		$dir = is_dir( $abs ) ? $abs : dirname( $abs );
		while ( ! is_dir( $dir ) && strlen( $dir ) > strlen( $base ) ) {
			$dir = dirname( $dir );
		}
		$real = realpath( $dir );
		$rb   = realpath( $base );
		if ( $real === false || $rb === false || strpos( wp_normalize_path( $real ) . '/', wp_normalize_path( $rb ) . '/' ) !== 0 ) {
			return new WP_Error( 'upw_ai_files', 'Refused: "' . $rel . '" leads outside the child theme.' );
		}
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( ! ( $allow_dir && is_dir( $abs ) ) && ! in_array( $ext, array_merge( self::TEXT_EXT, self::BINARY_EXT ), true ) ) {
			return new WP_Error( 'upw_ai_files', 'Refused: ".' . $ext . '" files are not handled by this tool. Allowed: ' . implode( ', ', array_merge( self::TEXT_EXT, self::BINARY_EXT ) ) . '.' );
		}
		return array( 'abs' => $abs, 'rel' => $rel, 'ext' => $ext );
	}

	/**
	 * Why PHP may not be touched here, or '' when it may.
	 *
	 * @param bool $write
	 * @return string
	 */
	private static function php_refusal( $write ) {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return 'This site turns file editing off (DISALLOW_FILE_EDIT), so PHP files cannot be ' . ( $write ? 'changed' : 'read' ) . ' here.';
		}
		if ( ! current_user_can( 'edit_themes' ) ) {
			return 'Your account cannot edit theme files, so PHP files cannot be ' . ( $write ? 'changed' : 'read' ) . ' here.';
		}
		if ( $write && ! self::php_allowed() ) {
			return 'Writing PHP is switched off. An administrator can allow it under Unyson+ → AI Assistant → Settings → Who can use it ("Let the AI write PHP files in the child theme").';
		}
		return '';
	}

	/**
	 * A PHP syntax check that needs no command line: the tokenizer's full parse mode.
	 *
	 * @param string $code
	 * @return string '' when it parses, else the error.
	 */
	private static function php_syntax_error( $code ) {
		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return 'This server cannot check PHP syntax, so PHP writes are refused.';
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return 'PHP syntax error on line ' . $e->getLine() . ': ' . $e->getMessage();
		} catch ( \Throwable $e ) {
			return 'PHP could not be parsed: ' . $e->getMessage();
		}
		return '';
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function theme_files( $in ) {
		$action = (string) ( $in['action'] ?? '' );
		$p      = self::resolve( (string) ( $in['path'] ?? '' ), $action === 'list' );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$theme = wp_get_theme();
		$ctx   = array( 'child_theme' => $theme->get_stylesheet(), 'parent_theme' => $theme->get_template() );

		if ( $action === 'list' ) {
			$root = is_dir( $p['abs'] ) ? $p['abs'] : dirname( $p['abs'] );
			$base = wp_normalize_path( untrailingslashit( get_stylesheet_directory() ) );
			$out  = array();
			$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) ), '/' );
				if ( preg_match( '#(^|/)(\.|node_modules/)#', $rel ) ) {
					continue;
				}
				$out[] = array( 'path' => $rel, 'size' => $f->getSize(), 'modified' => gmdate( 'c', $f->getMTime() ) );
				if ( count( $out ) >= self::MAX_LIST ) {
					break;
				}
			}
			usort( $out, function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			} );
			return $ctx + array( 'files' => $out );
		}

		$is_php = $p['ext'] === 'php';
		if ( $action === 'read' ) {
			if ( $is_php && ( $why = self::php_refusal( false ) ) ) {
				return new WP_Error( 'upw_ai_files', $why );
			}
			if ( ! is_file( $p['abs'] ) ) {
				return new WP_Error( 'upw_ai_files', '"' . $p['rel'] . '" does not exist. action list shows the files.' );
			}
			$size = filesize( $p['abs'] );
			if ( in_array( $p['ext'], self::BINARY_EXT, true ) ) {
				return $ctx + array( 'path' => $p['rel'], 'size' => $size, 'binary' => true, 'note' => 'A binary file; its contents are not returned.' );
			}
			if ( $size > self::MAX_FILE ) {
				return new WP_Error( 'upw_ai_files', '"' . $p['rel'] . '" is larger than 512 KB.' );
			}
			return $ctx + array( 'path' => $p['rel'], 'size' => $size, 'content' => (string) file_get_contents( $p['abs'] ) );
		}

		if ( $action !== 'write' && $action !== 'delete' ) {
			return new WP_Error( 'upw_ai_files', 'action must be list, read, write or delete.' );
		}
		if ( $is_php && ( $why = self::php_refusal( true ) ) ) {
			return new WP_Error( 'upw_ai_files', $why );
		}
		if ( $action === 'delete' && $p['rel'] === 'style.css' ) {
			return new WP_Error( 'upw_ai_files', 'style.css identifies the child theme to WordPress, so it cannot be deleted.' );
		}
		$exists  = is_file( $p['abs'] );
		$current = $exists ? (string) file_get_contents( $p['abs'] ) : '';

		if ( $action === 'delete' ) {
			if ( ! $exists ) {
				return new WP_Error( 'upw_ai_files', '"' . $p['rel'] . '" does not exist.' );
			}
			if ( empty( $in['confirm'] ) ) {
				return $ctx + array( 'ok' => false, 'preview' => true, 'message' => 'Nothing changed yet. This would delete "' . $p['rel'] . '" (' . strlen( $current ) . ' bytes; a backup is kept and undo_change brings it back). Ask the person, then call again with confirm: true.' );
			}
			$undo = fw_ai_snapshot( array( 'files' => array( $p['abs'] ) ), 'unysonplus/child-theme-files', 'Deleted ' . $ctx['child_theme'] . '/' . $p['rel'] );
			wp_delete_file( $p['abs'] );
			return $ctx + array( 'ok' => true, 'deleted' => $p['rel'], 'undo_revision_id' => $undo );
		}

		// write
		$binary = in_array( $p['ext'], self::BINARY_EXT, true );
		if ( $binary ) {
			$new = base64_decode( preg_replace( '/\s+/', '', preg_replace( '#^data:[^,]*,#', '', (string) ( $in['data'] ?? '' ) ) ), true );
			if ( $new === false || $new === '' ) {
				return new WP_Error( 'upw_ai_files', 'An image or font is written from data (base64).' );
			}
		} else {
			if ( ! array_key_exists( 'content', $in ) ) {
				return new WP_Error( 'upw_ai_files', 'write needs content: the WHOLE new file (read it first to change part of it).' );
			}
			$new = (string) $in['content'];
		}
		if ( strlen( $new ) > self::MAX_FILE ) {
			return new WP_Error( 'upw_ai_files', 'Files written here are limited to 512 KB.' );
		}
		if ( $is_php && ( $err = self::php_syntax_error( $new ) ) ) {
			return new WP_Error( 'upw_ai_files', 'Not saved — ' . $err );
		}
		if ( $p['ext'] === 'svg' && function_exists( 'fw_upw_sanitize_svg' ) ) {
			$new = fw_upw_sanitize_svg( $new );
			if ( $new === '' ) {
				return new WP_Error( 'upw_ai_files', 'Not saved — the SVG could not be cleaned of scripts.' );
			}
		}
		if ( $p['ext'] === 'json' && json_decode( $new ) === null && trim( $new ) !== 'null' ) {
			return new WP_Error( 'upw_ai_files', 'Not saved — the JSON does not parse: ' . json_last_error_msg() );
		}
		if ( $p['rel'] === 'style.css' && ! preg_match( '/^[ \t\/*#@]*Template:\s*' . preg_quote( get_template(), '/' ) . '\s*$/mi', $new ) ) {
			return new WP_Error( 'upw_ai_files', 'Not saved — style.css must keep its "Template: ' . get_template() . '" header line, or WordPress would no longer see this as a child theme.' );
		}
		if ( $exists && $new === $current ) {
			return $ctx + array( 'ok' => true, 'unchanged' => true, 'message' => 'The file already has this content. Nothing changed.' );
		}

		if ( empty( $in['confirm'] ) ) {
			$summary = $binary
				? ( $exists ? 'replace' : 'create' ) . ' "' . $p['rel'] . '" (' . strlen( $new ) . ' bytes)'
				: self::diff_summary( $p['rel'], $exists, $current, $new );
			return $ctx + array(
				'ok'      => false,
				'preview' => true,
				'message' => 'Nothing changed yet. This would ' . $summary . '. ' . ( $exists ? 'The current file is backed up and undo_change restores it. ' : '' ) . 'Ask the person, then call again with the same content and confirm: true.',
			);
		}

		// A host that refuses PHP file writes from the AI's request: the checked content waits for an
		// administrator's Install in wp-admin (FW_AI_Queue), which runs this same write from there.
		if ( $is_php && class_exists( 'FW_AI_Queue' ) && FW_AI_Queue::must_queue() ) {
			$job = FW_AI_Queue::add(
				'theme_file',
				sprintf( 'PHP file from your AI app: %s %s/%s', $exists ? 'change' : 'create', $ctx['child_theme'], $p['rel'] ),
				array( 'file' => FW_AI_Queue::keep_file( '', $new ), 'path' => $p['rel'] ),
				'file:' . $ctx['child_theme'] . '/' . $p['rel'],
				'edit_themes'
			);
			return $ctx + FW_AI_Queue::status( $job );
		}

		$undo = fw_ai_snapshot( array( 'files' => array( $p['abs'] ) ), 'unysonplus/child-theme-files', ( $exists ? 'Changed ' : 'Created ' ) . $ctx['child_theme'] . '/' . $p['rel'] );
		wp_mkdir_p( dirname( $p['abs'] ) );
		if ( file_put_contents( $p['abs'], $new ) === false ) {
			return new WP_Error( 'upw_ai_files', 'The server did not allow the file to be written (folder permissions).' );
		}
		if ( $p['rel'] === 'style.css' ) {
			wp_clean_themes_cache();
		}
		return $ctx + array(
			'ok'               => true,
			'written'          => $p['rel'],
			'bytes'            => strlen( $new ),
			'created'          => ! $exists,
			'message'          => 'Saved; it is live on the next page load (a browser may need a hard refresh for CSS / JS).',
			'undo_revision_id' => $undo,
		);
	}

	/**
	 * "change style.css: 3 lines added, 1 removed (+ … / - …)" — enough to tell the person what changes.
	 *
	 * @return string
	 */
	private static function diff_summary( $rel, $exists, $old, $new ) {
		if ( ! $exists ) {
			return 'create "' . $rel . '" (' . ( substr_count( $new, "\n" ) + 1 ) . ' lines)';
		}
		$a       = preg_split( '/\r\n|\n|\r/', $old );
		$b       = preg_split( '/\r\n|\n|\r/', $new );
		$added   = array_values( array_diff( $b, $a ) );
		$removed = array_values( array_diff( $a, $b ) );
		$sample  = function ( $lines, $mark ) {
			$lines = array_slice( array_filter( array_map( 'trim', $lines ), 'strlen' ), 0, 3 );
			return $lines ? ' ' . $mark . ' ' . implode( ' ' . $mark . ' ', array_map( function ( $l ) {
				return wp_html_excerpt( $l, 80, '…' );
			}, $lines ) ) : '';
		};
		return 'change "' . $rel . '": ' . count( $added ) . ' line(s) added, ' . count( $removed ) . ' removed;' . $sample( $added, '+' ) . $sample( $removed, '−' );
	}

	/* ------------------------------------------------------------------ *
	 * install-theme
	 * ------------------------------------------------------------------ */

	/**
	 * Read the theme a package contains (folder name, style.css headers) without installing it.
	 *
	 * @param string $zip
	 * @return array|WP_Error { slug, name, version, template }
	 */
	private static function peek_package( $zip ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'slug' => '', 'name' => '', 'version' => '', 'template' => '' );
		}
		$z = new ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			return new WP_Error( 'upw_ai_theme_pkg', 'The file is not a valid .zip.' );
		}
		$css = null;
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$n = str_replace( '\\', '/', (string) $z->getNameIndex( $i ) );
			if ( preg_match( '#^([^/]+/)?style\.css$#i', $n ) && ( $css === null || substr_count( $n, '/' ) < substr_count( $css, '/' ) ) ) {
				$css = $n;
			}
		}
		if ( $css === null ) {
			$z->close();
			return new WP_Error( 'upw_ai_theme_pkg', 'This .zip is not a theme: it has no style.css at its top level.' );
		}
		$head = substr( (string) $z->getFromName( $css ), 0, 8192 );
		$z->close();
		$get = function ( $h ) use ( $head ) {
			return preg_match( '/^[ \t\/*#@]*' . $h . ':(.*)$/mi', $head, $m ) ? trim( $m[1] ) : '';
		};
		if ( $get( 'Theme Name' ) === '' ) {
			return new WP_Error( 'upw_ai_theme_pkg', 'This .zip is not a theme: its style.css has no "Theme Name" header.' );
		}
		$slug = strpos( $css, '/' ) !== false ? strstr( $css, '/', true ) : '';
		return array( 'slug' => $slug, 'name' => $get( 'Theme Name' ), 'version' => $get( 'Version' ), 'template' => $get( 'Template' ) );
	}

	/**
	 * Try to create, then overwrite, a throwaway .php file in wp-content/upgrade and in the themes folder,
	 * and remove it. Reports each step's result and the operating system's reason when one fails.
	 *
	 * @return array
	 */
	private static function php_write_probe() {
		$out = array();
		foreach ( array( 'upgrade' => WP_CONTENT_DIR . '/upgrade', 'themes' => get_theme_root() ) as $where => $dir ) {
			wp_mkdir_p( $dir );
			$f = trailingslashit( $dir ) . 'upw-probe-' . wp_generate_password( 8, false ) . '.php';
			$r = array();
			foreach ( array( 'create', 'overwrite' ) as $step ) {
				$ok = @file_put_contents( $f, "<?php // probe\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				$e  = error_get_last();
				$r[ $step ] = $ok !== false ? 'ok' : 'failed: ' . ( $e ? $e['message'] : 'no reason given' );
				if ( $ok === false ) {
					break;
				}
			}
			$txt          = trailingslashit( $dir ) . 'upw-probe-' . wp_generate_password( 8, false ) . '.txt';
			$r['txt']     = @file_put_contents( $txt, 'probe' ) !== false ? 'ok' : 'failed'; // phpcs:ignore
			foreach ( array( $f, $txt ) as $x ) {
				if ( is_file( $x ) ) {
					@unlink( $x ); // phpcs:ignore
				}
			}
			$out[ $where ] = $r;
		}
		return $out;
	}

	/**
	 * Names of the callbacks on some hooks (for diagnosis).
	 *
	 * @param string[] $hooks
	 * @return array
	 */
	private static function hook_names( array $hooks ) {
		global $wp_filter;
		$out = array();
		foreach ( $hooks as $h ) {
			if ( empty( $wp_filter[ $h ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $h ]->callbacks as $cbs ) {
				foreach ( $cbs as $cb ) {
					$fn = $cb['function'];
					if ( is_string( $fn ) ) {
						$name = $fn;
					} elseif ( is_array( $fn ) ) {
						$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1];
					} else {
						try {
							$rf   = new ReflectionFunction( $fn );
							$name = 'closure@' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $rf->getFileName() ) ) . ':' . $rf->getStartLine();
						} catch ( Exception $e ) {
							$name = 'closure';
						}
					}
					$out[ $h ][] = $name;
				}
			}
		}
		return $out;
	}

	/* ---------- a file sent as data, in pieces ---------- */

	const CHUNK_MAX   = 2097152;   // 2 MB of decoded data per piece
	const PACKAGE_MAX = 52428800;  // 50 MB per package
	const CHUNK_TTL   = 7200;      // pieces / assembled packages left behind are removed after 2 hours

	/**
	 * The folder holding one upload's pieces: uploads/unysonplus/tmp/upload-<id>/ (no web access).
	 *
	 * @param string $uid
	 * @return string|WP_Error
	 */
	private static function chunks_dir( $uid ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{6,64}$/', (string) $uid ) ) {
			return new WP_Error( 'upw_ai_chunks', 'upload_id must be 6–64 letters, digits, - or _.' );
		}
		$base = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'tmp' )['path'] : wp_upload_dir()['basedir'] . '/unysonplus/tmp';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			@file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		// Leftovers of abandoned uploads.
		foreach ( (array) glob( $base . '/upload-*', GLOB_ONLYDIR ) as $old ) {
			if ( $old && time() - (int) @filemtime( $old ) > self::CHUNK_TTL ) {
				self::rmdir_r( $old );
			}
		}
		return $base . '/upload-' . $uid;
	}

	private static function rmdir_r( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			if ( $f && is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Remove an upload's pieces and assembled package. */
	public static function chunks_clear( $uid ) {
		$dir = self::chunks_dir( $uid );
		if ( ! is_wp_error( $dir ) && is_dir( $dir ) ) {
			self::rmdir_r( $dir );
		}
	}

	/**
	 * Store one piece; when every piece is in, assemble them. Returns the path of a temporary COPY of the
	 * assembled file (the caller may delete it), progress { ok, stored, received, of } while pieces are
	 * still missing, or an error. A call with only upload_id reuses an assembled package.
	 *
	 * @param array $in
	 * @return string|array|WP_Error
	 */
	private static function chunked_package( $in ) {
		$uid = (string) ( $in['upload_id'] ?? '' );
		$dir = self::chunks_dir( $uid );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$whole = $dir . '/package.bin';
		if ( isset( $in['data'] ) && $in['data'] !== '' ) {
			$count = (int) ( $in['chunk_count'] ?? 0 );
			$index = (int) ( $in['chunk_index'] ?? -1 );
			if ( $count < 1 || $count > 60 || $index < 0 || $index >= $count ) {
				return new WP_Error( 'upw_ai_chunks', 'chunk_count must be 1–60 and chunk_index 0 … chunk_count−1.' );
			}
			$bin = base64_decode( preg_replace( '/\s+/', '', preg_replace( '#^data:[^,]*,#', '', (string) $in['data'] ) ), true );
			if ( $bin === false || $bin === '' ) {
				return new WP_Error( 'upw_ai_chunks', 'data is not valid base64.' );
			}
			if ( strlen( $bin ) > self::CHUNK_MAX ) {
				return new WP_Error( 'upw_ai_chunks', 'A piece is limited to 2 MB of data; send smaller pieces.' );
			}
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/count', (string) $count );
			file_put_contents( sprintf( '%s/%03d.part', $dir, $index ), $bin );
			if ( is_file( $whole ) ) {
				wp_delete_file( $whole ); // a re-sent piece invalidates an earlier assembly
			}
			$have = array();
			for ( $i = 0; $i < $count; $i++ ) {
				if ( is_file( sprintf( '%s/%03d.part', $dir, $i ) ) ) {
					$have[] = $i;
				}
			}
			if ( count( $have ) < $count ) {
				return array( 'ok' => true, 'stored' => $index, 'received' => count( $have ), 'of' => $count, 'missing' => array_values( array_diff( range( 0, $count - 1 ), $have ) ), 'message' => 'Piece stored. Send the rest; the last one installs (or previews).' );
			}
			// All in: assemble.
			$out  = fopen( $whole, 'wb' );
			$size = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$part  = sprintf( '%s/%03d.part', $dir, $i );
				$size += filesize( $part );
				if ( $size > self::PACKAGE_MAX ) {
					fclose( $out );
					self::rmdir_r( $dir );
					return new WP_Error( 'upw_ai_chunks', 'The package is larger than 50 MB.' );
				}
				fwrite( $out, (string) file_get_contents( $part ) );
				wp_delete_file( $part );
			}
			fclose( $out );
		}
		if ( ! is_file( $whole ) ) {
			return new WP_Error( 'upw_ai_chunks', 'No complete package for upload_id "' . $uid . '" (pieces expire after 2 hours). Send the pieces again.' );
		}
		$tmp = wp_tempnam( 'upw-package.zip' );
		copy( $whole, $tmp );
		return $tmp;
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function install_theme( $in ) {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'upw_ai_install_theme' ) ) {
			return new WP_Error( 'upw_ai_theme', 'This site turns file changes off (DISALLOW_FILE_MODS), so themes cannot be installed or updated here.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$url = trim( (string) ( $in['url'] ?? '' ) );
		$uid = (string) ( $in['upload_id'] ?? '' );
		$qkey = 'theme:' . ( $uid !== '' ? $uid : $url );
		// A package already waiting for wp-admin approval: report where it stands.
		if ( $uid !== '' && ( ! isset( $in['data'] ) || $in['data'] === '' ) && class_exists( 'FW_AI_Queue' ) && ! FW_AI_Queue::$running ) {
			$job = FW_AI_Queue::find( $qkey );
			if ( $job ) {
				return FW_AI_Queue::status( $job );
			}
		}
		if ( ! empty( $in['queued_package'] ) && class_exists( 'FW_AI_Queue' ) && FW_AI_Queue::$running ) {
			// Approved in wp-admin: install the package kept when it was queued.
			if ( ! is_file( (string) $in['queued_package'] ) ) {
				return new WP_Error( 'upw_ai_theme', 'The waiting package is gone.' );
			}
			$tmp = wp_tempnam( 'upw-package.zip' );
			copy( (string) $in['queued_package'], $tmp );
		} elseif ( $url !== '' ) {
			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'upw_ai_theme', 'url must be a public http(s) address of a .zip.' );
			}
			$tmp = download_url( $url, 120 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'upw_ai_theme', 'Could not download the package: ' . $tmp->get_error_message() );
			}
		} elseif ( $uid !== '' ) {
			$tmp = self::chunked_package( $in );
			if ( is_wp_error( $tmp ) || is_array( $tmp ) ) {
				return $tmp; // an error, or "piece n of m stored"
			}
		} else {
			return new WP_Error( 'upw_ai_theme', 'Give url, or the package as data with upload_id, chunk_index and chunk_count.' );
		}
		$pkg = self::peek_package( $tmp );
		if ( is_wp_error( $pkg ) ) {
			wp_delete_file( $tmp );
			return $pkg;
		}
		// Which installed theme this package updates. A GitHub tag archive unpacks to "<Repo>-<tag>", not to
		// the theme's own folder: match it to an installed theme by name, so it updates that theme instead
		// of installing a second copy beside it.
		$target = $pkg['slug'];
		if ( $target === '' || ! wp_get_theme( $target )->exists() ) {
			foreach ( wp_get_themes() as $stylesheet => $t ) {
				if ( $pkg['name'] !== '' && strcasecmp( (string) $t->get( 'Name' ), $pkg['name'] ) === 0 ) {
					$target = (string) $stylesheet;
					break;
				}
			}
		}
		$rename_to = ( $target !== '' && $target !== $pkg['slug'] ) ? $target : '';
		if ( $rename_to ) {
			$pkg['folder_in_package'] = $pkg['slug'];
			$pkg['slug']              = $target;
		}
		$installed = $pkg['slug'] !== '' ? wp_get_theme( $pkg['slug'] ) : null;
		$old       = ( $installed && $installed->exists() ) ? (string) $installed->get( 'Version' ) : '';
		$what      = ( $old !== '' ? 'update "' . $pkg['name'] . '" from ' . $old . ' to ' . ( $pkg['version'] ?: 'the packaged version' ) : 'install "' . ( $pkg['name'] ?: 'the theme in the package' ) . '"' . ( $pkg['version'] ? ' ' . $pkg['version'] : '' ) )
			. ( $pkg['template'] ? ' (a child theme of ' . $pkg['template'] . ')' : '' )
			. ( ! empty( $in['activate'] ) ? ' and switch the site to it' : '' );

		if ( empty( $in['confirm'] ) ) {
			wp_delete_file( $tmp );
			$active = ( $old !== '' && in_array( $pkg['slug'], array( get_stylesheet(), get_template() ), true ) ) ? ' It is the theme the site is using now, so the change is live at once.' : '';
			return array(
				'ok'          => false,
				'preview'     => true,
				'theme'       => $pkg,
				'old_version' => $old,
				'message'     => 'Nothing changed yet. This would ' . $what . '.' . $active . ' An install has no undo (to go back, install the older package). Ask the person, then call again with confirm: true.',
			);
		}

		// Some managed hosts refuse to create .php files from the AI's (REST) request but allow it from
		// wp-admin: keep the verified package and let an administrator approve it there (FW_AI_Queue).
		if ( class_exists( 'FW_AI_Queue' ) && FW_AI_Queue::must_queue() ) {
			$job = FW_AI_Queue::add(
				'install_theme',
				sprintf( 'Theme from your AI app: %s', ucfirst( $what ) ),
				array( 'file' => FW_AI_Queue::keep_file( $tmp ), 'activate' => ! empty( $in['activate'] ) ),
				$qkey,
				'install_themes'
			);
			wp_delete_file( $tmp );
			if ( $uid !== '' ) {
				self::chunks_clear( $uid );
			}
			return FW_AI_Queue::status( $job ) + array( 'theme' => $pkg, 'old_version' => $old );
		}

		// Connect the way wp-admin and the background updater do: credentials from wp-config (FS_METHOD,
		// FTP_* constants) are picked up silently; a host that would have to ASK for them is refused.
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, false, null );
		ob_end_clean();
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'upw_ai_theme', 'This server needs FTP credentials to write files, so themes can only be installed from Appearance → Themes.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$run = function () use ( $tmp ) {
			// The upgrader deletes the package after unpacking; work on a copy so a retry has one.
			$pkg = wp_tempnam( 'upw-theme.zip' );
			copy( $tmp, $pkg );
			$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $pkg, array( 'overwrite_package' => true, 'clear_update_cache' => true ) );
			if ( is_file( $pkg ) ) {
				wp_delete_file( $pkg );
			}
			return array( $upgrader, $result );
		};
		// A tag archive's "<Repo>-<tag>" folder is renamed to the installed theme's folder while unpacking
		// (WordPress's own hook for exactly this), so the install overwrites that theme.
		$rename = null;
		if ( $rename_to ) {
			$rename = function ( $source ) use ( $rename_to ) {
				global $wp_filesystem;
				if ( is_wp_error( $source ) || ! is_object( $wp_filesystem ) ) {
					return $source;
				}
				$to = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $rename_to . '/';
				if ( untrailingslashit( $source ) === untrailingslashit( $to ) ) {
					return $source;
				}
				return $wp_filesystem->move( $source, $to, true ) ? $to : new WP_Error( 'upw_ai_theme_rename', 'Could not rename the package folder to ' . $rename_to . '.' );
			};
			add_filter( 'upgrader_source_selection', $rename, 1 );
		}
		list( $upgrader, $result ) = $run();
		$retried = false;
		// Some hosts fail to write files while unpacking with ZipArchive ("Could not copy file") where
		// WordPress's other unzipper, PclZip, works: try once more with it before giving up.
		// The failure can come back as an error object OR as a plain false with the reason only in the
		// installer's messages (seen on a managed host), so look at both.
		$copy_failed = is_wp_error( $result )
			? in_array( $result->get_error_code(), array( 'copy_failed_ziparchive', 'incompatible_archive', 'copy_failed_pclzip' ), true )
			: ( ! $result && preg_match( '/could not copy file/i', implode( ' ', array_map( 'wp_strip_all_tags', (array) $upgrader->skin->get_upgrade_messages() ) ) ) );
		if ( $copy_failed ) {
			add_filter( 'unzip_file_use_ziparchive', '__return_false' );
			list( $upgrader, $result ) = $run();
			remove_filter( 'unzip_file_use_ziparchive', '__return_false' );
			$retried = true;
		}
		if ( $rename ) {
			remove_filter( 'upgrader_source_selection', $rename, 1 );
		}
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( $uid !== '' && ! is_wp_error( $result ) && $result ) {
			self::chunks_clear( $uid ); // installed: the stored package is no longer needed
		}
		if ( is_wp_error( $result ) || ! $result ) {
			global $wp_filesystem;
			$msg  = is_wp_error( $result ) ? $result->get_error_message() . ' (' . $result->get_error_code() . ( $result->get_error_data() ? ': ' . wp_json_encode( $result->get_error_data() ) : '' ) . ')' : implode( ' ', array_map( 'wp_strip_all_tags', (array) $upgrader->skin->get_upgrade_messages() ) );
			$diag = array(
				'filesystem'        => is_object( $wp_filesystem ) ? $wp_filesystem->method : get_filesystem_method(),
				'upgrade_writable'  => wp_is_writable( WP_CONTENT_DIR . '/upgrade' ) || wp_is_writable( WP_CONTENT_DIR ),
				'themes_writable'   => wp_is_writable( get_theme_root() ),
				'retried_with_pclzip' => $retried,
				// Can this request create / overwrite a PHP file at all? Separates "the host refuses PHP
				// writes from a web request" from a package or unzip problem.
				'php_write_test'      => self::php_write_probe(),
				// Code on the site that hooks into the installer (a host's must-use plugin can veto it).
				'installer_hooks'     => self::hook_names( array( 'upgrader_pre_install', 'upgrader_source_selection', 'upgrader_post_install', 'upgrader_pre_download', 'unzip_file', 'pre_unzip_file' ) ),
			);
			return new WP_Error( 'upw_ai_theme', 'The theme was not installed, and the installed theme was left as it was. ' . $msg . ' Details: ' . wp_json_encode( $diag ) );
		}
		wp_clean_themes_cache();
		$slug  = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : $pkg['slug'];
		$theme = wp_get_theme( $slug );
		$out   = array(
			'ok'          => true,
			'theme'       => $slug,
			'name'        => (string) $theme->get( 'Name' ),
			'version'     => (string) $theme->get( 'Version' ),
			'old_version' => $old,
			'message'     => ( $old !== '' ? 'Updated from ' . $old . ' to ' . $theme->get( 'Version' ) : 'Installed ' . $theme->get( 'Version' ) ) . '.',
		);
		if ( ! empty( $in['activate'] ) && $slug !== get_stylesheet() ) {
			if ( ! current_user_can( 'switch_themes' ) ) {
				$out['message'] .= ' Not activated: your account cannot switch themes.';
			} elseif ( ! $theme->exists() || $theme->errors() ) {
				$out['message'] .= ' Not activated: WordPress reports the theme as broken (' . ( $theme->errors() ? $theme->errors()->get_error_message() : 'missing' ) . ').';
			} else {
				$out['undo_revision_id'] = fw_ai_snapshot( array( 'options' => array( 'template', 'stylesheet', 'current_theme' ) ), 'unysonplus/install-theme', 'Switched the site to ' . $slug );
				switch_theme( $slug );
				$out['activated'] = true;
				$out['message']  .= ' The site now uses it (undo_change switches back).';
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * reading-settings
	 * ------------------------------------------------------------------ */

	/**
	 * @return array
	 */
	private static function reading_now() {
		$front = get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0;
		$posts = (int) get_option( 'page_for_posts' );
		return array(
			'front_page'          => $front,
			'front_page_title'    => $front ? get_the_title( $front ) : 'latest posts',
			'posts_page'          => $posts,
			'posts_page_title'    => $posts ? get_the_title( $posts ) : '',
			'posts_per_page'      => (int) get_option( 'posts_per_page' ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function reading_settings( $in ) {
		$in      = (array) $in;
		$changes = array();
		foreach ( array( 'front_page', 'posts_page' ) as $k ) {
			if ( isset( $in[ $k ] ) && (int) $in[ $k ] > 0 ) {
				$post = get_post( (int) $in[ $k ] );
				if ( ! $post || $post->post_type !== 'page' || $post->post_status !== 'publish' ) {
					return new WP_Error( 'upw_ai_reading', $k . ' must be the id of a PUBLISHED page.' );
				}
			}
		}
		if ( isset( $in['front_page'], $in['posts_page'] ) && (int) $in['front_page'] > 0 && (int) $in['front_page'] === (int) $in['posts_page'] ) {
			return new WP_Error( 'upw_ai_reading', 'The front page and the posts page must be different pages.' );
		}
		if ( isset( $in['front_page'] ) ) {
			$changes['show_on_front'] = (int) $in['front_page'] > 0 ? 'page' : 'posts';
			$changes['page_on_front'] = (int) $in['front_page'];
		}
		if ( isset( $in['posts_page'] ) ) {
			$changes['page_for_posts'] = (int) $in['posts_page'];
		}
		if ( isset( $in['posts_per_page'] ) ) {
			$changes['posts_per_page'] = max( 1, min( 100, (int) $in['posts_per_page'] ) );
		}
		if ( isset( $in['permalink_structure'] ) ) {
			$s = trim( (string) $in['permalink_structure'] );
			if ( $s !== '' ) {
				$s = '/' . trim( preg_replace( '#/+#', '/', $s ), '/' ) . '/';
				if ( ! preg_match( '/%[a-z_]+%/', $s ) ) {
					return new WP_Error( 'upw_ai_reading', 'permalink_structure needs at least one tag, e.g. "/%postname%/".' );
				}
			}
			$changes['permalink_structure'] = sanitize_option( 'permalink_structure', $s );
		}
		if ( ! $changes ) {
			return array( 'ok' => true, 'current' => self::reading_now() );
		}
		$undo = fw_ai_snapshot( array( 'options' => array_keys( $changes ) ), 'unysonplus/reading-settings', 'Reading / permalink settings' );
		foreach ( $changes as $k => $v ) {
			update_option( $k, $v );
		}
		if ( isset( $changes['permalink_structure'] ) ) {
			flush_rewrite_rules( false );
		}
		return array( 'ok' => true, 'current' => self::reading_now(), 'undo_revision_id' => $undo );
	}
}
