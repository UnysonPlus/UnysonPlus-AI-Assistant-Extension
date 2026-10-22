<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ extensions, from the AI: see what is available / installed / active, and install, activate
 * or deactivate one — the same Extensions manager calls as Unyson+ → Extensions, so downloads,
 * parent extensions and requirements behave exactly as they do there.
 *
 *   list-extensions    read: every extension the manager knows, with installed / active state.
 *   manage-extension   write, administrators only, two steps: without confirm it only says what would
 *                      happen (to tell the person); with confirm: true it does it. It never deactivates
 *                      the AI Assistant itself or an extension the AI Assistant needs (the connection
 *                      the AI is using would go with it).
 *
 * Installing downloads into the site, so it needs direct file access (no FTP credentials) — the same
 * condition as the Extensions screen's own one-click install.
 */
class FW_AI_Extensions {

	/** Never deactivated from here: the AI Assistant and what it requires. */
	const PROTECTED_EXTS = array( 'ai-assistant', 'shortcodes', 'page-builder', 'builder' );

	public static function register() {
		fw_ai_register_ability( 'list-extensions', array(
			'label'       => __( 'List Unyson+ extensions', 'fw' ),
			'description' => __( 'Every Unyson+ extension the Extensions manager knows: its name, what it does, and whether it is installed and active. Use it before manage_extension, and when a tool says an extension is missing (e.g. "The Site Converter extension is not active").', 'fw' ),
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'list_extensions' ),
		) );

		fw_ai_register_ability( 'manage-extension', array(
			'label'       => __( 'Install, activate or deactivate an extension', 'fw' ),
			'description' => __( 'Installs (downloads and activates), activates or deactivates one Unyson+ extension, exactly like Unyson+ → Extensions. TWO STEPS: without confirm it changes nothing and says what would happen — tell the person and ask; call again with confirm: true only after they agree. extension = the slug from list_extensions (e.g. "site-converter"). The AI Assistant and the extensions it needs cannot be deactivated from here.', 'fw' ),
			'input'       => array(
				'extension' => array( 'type' => 'string' ),
				'action'    => array( 'type' => 'string', 'enum' => array( 'install', 'activate', 'deactivate' ) ),
				'confirm'   => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'    => array( 'extension', 'action' ),
			'permission'  => 'manage_options',
			'execute'     => array( __CLASS__, 'manage' ),
		) );
	}

	/**
	 * @return _FW_Extensions_Manager|null
	 */
	private static function manager() {
		$ext = fw()->extensions;
		return ( is_object( $ext ) && isset( $ext->manager ) && is_object( $ext->manager ) ) ? $ext->manager : null;
	}

	/**
	 * @return array
	 */
	public static function list_extensions() {
		$m = self::manager();
		if ( ! $m ) {
			return new WP_Error( 'upw_ai_ext', 'The Extensions manager is not available on this site.' );
		}
		$available = (array) $m->get_available_extensions();
		$installed = (array) $m->get_installed_extensions();
		$active    = array_keys( (array) fw()->extensions->get_all() );
		$out       = array();
		foreach ( array_unique( array_merge( array_keys( $available ), array_keys( $installed ) ) ) as $slug ) {
			$a = $available[ $slug ] ?? array();
			$i = $installed[ $slug ] ?? array();
			if ( isset( $a['display'] ) && ! $a['display'] && ! $i ) {
				continue;
			}
			$out[] = array(
				'slug'        => $slug,
				'name'        => (string) $m->get_extension_title( $slug ),
				'description' => wp_strip_all_tags( (string) ( $a['description'] ?? ( $i['manifest']['description'] ?? '' ) ) ),
				'installed'   => (bool) $i,
				'active'      => in_array( $slug, $active, true ),
			);
		}
		usort( $out, function ( $x, $y ) {
			return strcasecmp( $x['name'], $y['name'] );
		} );
		return array( 'extensions' => $out );
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function manage( $in ) {
		$m = self::manager();
		if ( ! $m ) {
			return new WP_Error( 'upw_ai_ext', 'The Extensions manager is not available on this site.' );
		}
		$slug   = sanitize_key( (string) ( $in['extension'] ?? '' ) );
		$action = (string) ( $in['action'] ?? '' );
		$name   = (string) $m->get_extension_title( $slug );
		$available = (array) $m->get_available_extensions();
		$installed = (array) $m->get_installed_extensions();
		$active    = array_keys( (array) fw()->extensions->get_all() );
		$is_inst   = isset( $installed[ $slug ] );
		$is_act    = in_array( $slug, $active, true );

		if ( $slug === '' || ( ! $is_inst && ! isset( $available[ $slug ] ) ) ) {
			return new WP_Error( 'upw_ai_ext', 'Unknown extension "' . $slug . '". Call list_extensions for the slugs.' );
		}
		if ( $action === 'deactivate' && in_array( $slug, self::PROTECTED_EXTS, true ) ) {
			return new WP_Error( 'upw_ai_ext', $slug === 'ai-assistant' ? 'The AI Assistant cannot switch itself off (this connection would end). Use Unyson+ → Extensions.' : $name . ' cannot be deactivated from here: the AI Assistant depends on it. Use Unyson+ → Extensions.' );
		}
		// Nothing to do: say so plainly instead of erroring.
		if ( ( $action === 'install' && $is_inst && $is_act ) || ( $action === 'activate' && $is_act ) || ( $action === 'deactivate' && ! $is_act ) ) {
			return array( 'ok' => true, 'extension' => $slug, 'name' => $name, 'message' => $name . ' is already ' . ( $action === 'deactivate' ? 'inactive' : 'installed and active' ) . '. Nothing changed.' );
		}
		if ( $action === 'install' && $is_inst ) {
			$action = 'activate'; // installed but inactive: activating is what "install" means to the person
		}
		if ( $action === 'activate' && ! $is_inst ) {
			$action = 'install';
		}

		if ( empty( $in['confirm'] ) ) {
			$what = array(
				'install'    => 'download ' . $name . ' from the Unyson+ extension library and activate it (any extension it requires is installed too)',
				'activate'   => 'activate ' . $name,
				'deactivate' => 'deactivate ' . $name . ' (its settings and content are kept; activating it again brings everything back)',
			);
			return array(
				'ok'      => false,
				'preview' => true,
				'action'  => $action,
				'message' => 'Nothing changed yet. This would ' . $what[ $action ] . '. Ask the person, and call manage_extension again with confirm: true only after they agree.',
			);
		}

		if ( $action === 'install' ) {
			// The download needs WordPress's file helpers (admin-only includes) and direct file access.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! WP_Filesystem() || ! FW_WP_Filesystem::has_direct_access( fw_get_framework_directory( '/extensions' ) ) ) {
				return new WP_Error( 'upw_ai_ext', 'This server needs FTP credentials to install files, so extensions can only be installed from Unyson+ → Extensions.' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$r = $m->install_extensions( array( $slug => array() ), array( 'cancel_on_error' => true ) );
		} elseif ( $action === 'activate' ) {
			$r = $m->activate_extensions( array( $slug => array() ), true );
		} else {
			$r = $m->deactivate_extensions( array( $slug => array() ), true );
		}
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'upw_ai_ext', $name . ': ' . $r->get_error_message() );
		}
		if ( is_array( $r ) ) {
			foreach ( $r as $res ) {
				if ( is_wp_error( $res ) ) {
					return new WP_Error( 'upw_ai_ext', $name . ': ' . $res->get_error_message() );
				}
			}
		}
		$done = array( 'install' => 'installed and activated', 'activate' => 'activated', 'deactivate' => 'deactivated' );
		return array(
			'ok'        => true,
			'extension' => $slug,
			'name'      => $name,
			'action'    => $action,
			'message'   => $name . ' ' . $done[ $action ] . '. The change takes effect from the next request; its tools (if any) appear after a fresh tools/list.',
		);
	}
}
