<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A child theme, from the AI.
 *
 *   create-child-theme   write, administrators only, two steps like manage-extension: without confirm it
 *                        only says what would happen; with confirm: true it writes a minimal child theme of
 *                        the Unyson+ theme (style.css + functions.php) and activates it
 *
 * Most builds never need one: Theme Settings, presets, pages, Theme Builder layouts, menus, forms, the
 * CRM and Custom CSS all live in the database and survive theme updates. A child theme is for custom
 * PHP or files. Theme Settings carry over because they are keyed by the theme MANIFEST id, which a child
 * without its own manifest inherits; WordPress's own theme mods (menu locations, logo) are keyed by the
 * stylesheet, so the active theme's are copied to the new child on its first activation.
 */
class FW_AI_Theme {

	const PARENT = 'unysonplus-theme';

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		fw_ai_register_ability( 'create-child-theme', array(
			'label'       => __( 'Create a child theme', 'fw' ),
			'description' => 'Creates and activates a child theme of the Unyson+ theme. Usually NOT needed: styling belongs in Theme Settings, presets and Custom CSS, which already survive theme updates. Use it only when the site needs custom code. TWO STEPS: without confirm it changes nothing and says what would happen (tell the person and ask); call again with confirm: true after they agree. name = the theme name shown in Appearance > Themes (e.g. the business name). Theme Settings, menus and the logo carry over. Not undoable with undo_change; the person can switch back in Appearance > Themes.',
			'input'       => array(
				'name'    => array( 'type' => 'string', 'minLength' => 1 ),
				'confirm' => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'    => array( 'name' ),
			'permission'  => 'switch_themes',
			'execute'     => array( __CLASS__, 'create' ),
		) );
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function create( $in ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			return new WP_Error( 'fw_ai_theme_cap', 'Only an administrator who can install themes can create a child theme.' );
		}
		if ( ! wp_get_theme( self::PARENT )->exists() ) {
			return new WP_Error( 'fw_ai_theme_parent', 'The Unyson+ theme is not installed, so there is nothing to make a child of. Ask the person to run the setup on the Connect AI page first.' );
		}

		$name = sanitize_text_field( (string) $in['name'] );
		$slug = sanitize_title( $name );
		if ( '' === $slug ) {
			return new WP_Error( 'fw_ai_theme_name', 'name must contain letters or numbers.' );
		}
		if ( self::PARENT === $slug ) {
			$slug .= '-child';
		}

		$existing = wp_get_theme( $slug );
		if ( $existing->exists() && self::PARENT !== $existing->get_template() ) {
			return new WP_Error( 'fw_ai_theme_taken', sprintf( 'A different theme already uses the folder "%s". Choose another name.', $slug ) );
		}

		$active = get_stylesheet();
		if ( empty( $in['confirm'] ) ) {
			return array(
				'ok'      => true,
				'confirm' => false,
				'message' => sprintf(
					'Nothing changed yet. This would %s the child theme "%s" (folder %s) and switch the site to it from "%s". Theme Settings, menus and the logo carry over. Ask the person, then call again with confirm: true.',
					$existing->exists() ? 'activate the existing' : 'create',
					$name,
					$slug,
					wp_get_theme( $active )->get( 'Name' )
				),
			);
		}

		if ( ! $existing->exists() ) {
			$written = self::write( $slug, $name );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			wp_clean_themes_cache();
		}

		// WordPress keys theme mods by stylesheet: hand the active theme's (menus, logo) to a child that
		// has none yet, so switching does not empty the menu locations.
		$active_mods = get_option( 'theme_mods_' . $active );
		if ( $active !== $slug && is_array( $active_mods ) && false === get_option( 'theme_mods_' . $slug ) ) {
			update_option( 'theme_mods_' . $slug, $active_mods );
		}

		switch_theme( $slug );

		return array(
			'ok'      => true,
			'message' => sprintf( 'The site now uses the child theme "%s" (%s). Switch back any time in Appearance > Themes.', $name, $slug ),
			'theme'   => $slug,
		);
	}

	/**
	 * @param string $slug
	 * @param string $name
	 * @return true|WP_Error
	 */
	private static function write( $slug, $name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return new WP_Error( 'fw_ai_theme_fs', 'This server does not allow writing theme files directly, so the child theme cannot be created from here. The person can create it by hand (Appearance > Themes needs a zip with style.css naming "Template: unysonplus-theme").' );
		}
		global $wp_filesystem;

		$dir = trailingslashit( get_theme_root() ) . $slug;
		if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'fw_ai_theme_write', 'Could not create the theme folder.' );
		}

		$style = "/*\n"
			. 'Theme Name:   ' . str_replace( '*/', '', $name ) . "\n"
			. "Description:  Child theme of the Unyson+ theme for this site's own code. Styling belongs in Theme Settings and Custom CSS.\n"
			. 'Template:     ' . self::PARENT . "\n"
			. "Version:      1.0.0\n"
			. "License:      GNU General Public License v2 or later\n"
			. 'Text Domain:  ' . $slug . "\n"
			. "*/\n\n/* This stylesheet loads last, so rules here override the parent theme. */\n";

		$functions = "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); }\n"
			. "/**\n * " . str_replace( '*/', '', $name ) . " child theme.\n *\n"
			. " * The Unyson+ theme enqueues this theme's style.css as `child-style` and loads it last; the\n"
			. " * fallback below only runs under an older parent that does not.\n */\n\n"
			. "add_action( 'wp_enqueue_scripts', function () {\n"
			. "\tif ( wp_style_is( 'child-style', 'enqueued' ) || wp_style_is( 'child-style', 'registered' ) ) {\n"
			. "\t\treturn;\n\t}\n"
			. "\twp_enqueue_style( 'child-style', get_stylesheet_uri(), array( 'parent-style' ), wp_get_theme()->get( 'Version' ) );\n"
			. "}, 20 );\n";

		if ( ! $wp_filesystem->put_contents( $dir . '/style.css', $style, FS_CHMOD_FILE )
			|| ! $wp_filesystem->put_contents( $dir . '/functions.php', $functions, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'fw_ai_theme_write', 'Could not write the theme files.' );
		}

		return true;
	}
}
FW_AI_Theme::init();
