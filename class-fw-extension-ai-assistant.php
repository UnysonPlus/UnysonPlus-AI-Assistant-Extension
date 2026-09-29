<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant (Beta).
 *
 * Registers a set of UnysonPlus abilities with the WordPress Abilities API (WP 6.9+) so an
 * AI model can read the site and build / edit page-builder pages through validated,
 * undoable actions. The abilities are the single write path; front-ends (MCP agents, the
 * builder panel, the Chat AI channel) are thin clients over them.
 *
 *   includes/class-fw-ai-schema.php    element catalog, option schemas, tree validation
 *   includes/class-fw-ai-store.php     builder-tree read/write, AI revisions, paths, outline
 *   includes/class-fw-ai-abilities.php ability categories + abilities
 *   includes/class-fw-ai-mcp.php       MCP server endpoint (tools = the abilities)
 *   includes/class-fw-ai-panel.php     builder / Live Editor chat panel (REST + backends)
 *   includes/class-fw-ai-check.php     render-check
 *   includes/class-fw-ai-local.php     local command-line agent runner (development hosts)
 *   includes/class-fw-ai-visitor.php   the Chat extension's AI channel for visitors
 *   includes/class-fw-ai-settings.php  Theme Settings: describe, update, presets, undo
 *   includes/class-fw-ai-build.php     templates + whole-site conversion
 *   includes/class-fw-ai-toolkit.php   fw_ai_register_ability() / fw_ai_snapshot() for other extensions
 *   static/                            the panel's JS + CSS
 *   views/page.php                     Unyson+ → AI Assistant (status, MCP access, connect an agent)
 *
 * On WordPress without the Abilities API the extension loads, shows its admin page with the
 * reason, and registers nothing else.
 */
class FW_Extension_AI_Assistant extends FW_Extension {

	/** The Unyson+ top-level menu registered by the extensions manager. */
	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-ai-assistant';

	/** Name prefix of the Application Passwords this screen creates. */
	const APP_PASSWORD_NAME = 'UnysonPlus AI Assistant';

	/** @var string|null */
	private $page_hook;

	/** @var array|null A just-created connection (shown once, never stored). */
	private $new_connection;

	/** @var string[] Notices for this page load. */
	private $notices = array();

	/**
	 * @internal
	 */
	public function _init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		}

		if ( ! $this->is_supported() ) {
			return;
		}

		require_once $this->get_path( '/includes/class-fw-ai-schema.php' );
		require_once $this->get_path( '/includes/class-fw-ai-store.php' );
		require_once $this->get_path( '/includes/class-fw-ai-abilities.php' );
		require_once $this->get_path( '/includes/class-fw-ai-mcp.php' );
		require_once $this->get_path( '/includes/class-fw-ai-panel.php' );
		require_once $this->get_path( '/includes/class-fw-ai-check.php' );
		require_once $this->get_path( '/includes/class-fw-ai-local.php' );
		require_once $this->get_path( '/includes/class-fw-ai-visitor.php' );
		require_once $this->get_path( '/includes/class-fw-ai-settings.php' );
		require_once $this->get_path( '/includes/class-fw-ai-build.php' );
		require_once $this->get_path( '/includes/class-fw-ai-toolkit.php' );
		require_once $this->get_path( '/includes/class-fw-ai-context.php' );
		require_once $this->get_path( '/includes/class-fw-ai-history.php' );
		require_once $this->get_path( '/includes/class-fw-ai-changes.php' );
		require_once $this->get_path( '/includes/class-fw-ai-oauth.php' );
		require_once $this->get_path( '/includes/class-fw-ai-visual.php' );
		require_once $this->get_path( '/includes/class-fw-ai-replace.php' );
		require_once $this->get_path( '/includes/class-fw-ai-media.php' );
		require_once $this->get_path( '/includes/class-fw-ai-translate.php' );
		require_once $this->get_path( '/includes/class-fw-ai-access.php' );

		add_action( 'wp_abilities_api_categories_init', array( 'FW_AI_Abilities', 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( 'FW_AI_Abilities', 'register' ) );
		FW_AI_MCP::init();
		FW_AI_Panel::init();
		FW_AI_Visitor::init();
		FW_AI_Toolkit::init();
		FW_AI_Context::init();
		FW_AI_History::init();
		FW_AI_Changes::init();
		FW_AI_OAuth::init();
		FW_AI_Access::init();
		FW_AI_Visual::init();
		add_action( 'fw_ai_assistant_register_abilities', array( 'FW_AI_Media', 'register' ) );
		add_action( 'fw_ai_assistant_register_abilities', array( 'FW_AI_Translate', 'register' ) );
	}

	/**
	 * Whether this WordPress install can run the assistant.
	 *
	 * @return bool
	 */
	public function is_supported() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * @return string
	 */
	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/* ------------------------------------------------------------------ *
	 * Admin page
	 * ------------------------------------------------------------------ */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		$this->page_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'AI Assistant (Beta)', 'fw' ),
			__( 'AI Assistant', 'fw' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
		if ( $this->page_hook ) {
			add_action( 'load-' . $this->page_hook, array( $this, '_handle_post' ) );
		}
	}

	/**
	 * Form submissions are handled on this page's own load hook, so a just-created password
	 * can be shown in the same response without ever being written to the database.
	 *
	 * @internal
	 */
	public function _handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['upw_ai_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['upw_ai_action'] ) );
		check_admin_referer( 'upw_ai_' . $action );

		if ( 'save_access' === $action ) {
			$valid = array_keys( wp_roles()->get_names() );
			$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ?? array() ) ), $valid ) );
			update_option( FW_AI_Access::OPTION_ROLES, $roles, false );
			update_option( FW_AI_Access::OPTION_ON, empty( $_POST['usage_log'] ) ? 'no' : 'yes', false );
			$this->notices[] = array( 'success', __( 'Access saved.', 'fw' ) );
			return;
		}

		if ( 'save_mode' === $action && $this->is_supported() ) {
			$mode = sanitize_key( wp_unslash( $_POST['mcp_mode'] ?? 'off' ) );
			update_option( FW_AI_MCP::OPTION_MODE, in_array( $mode, FW_AI_MCP::MODES, true ) ? $mode : 'off', false );
			$this->notices[] = array( 'success', __( 'MCP access saved.', 'fw' ) );
			return;
		}

		if ( 'save_panel' === $action && $this->is_supported() ) {
			if ( isset( $_POST['panel_backend'] ) ) {
				$backend = sanitize_key( wp_unslash( $_POST['panel_backend'] ) );
				update_option( FW_AI_Panel::OPTION_BACKEND, in_array( $backend, array( 'auto', 'wp', 'local', 'browser', 'off' ), true ) ? $backend : 'auto', false );
			}
			// The local agent command only exists on development hosts; it is run by the web server,
			// so it is never accepted (or kept) on a public host.
			if ( FW_AI_MCP::is_local_host() && isset( $_POST['local_cmd'] ) ) {
				$cmd = trim( str_replace( array( "\r", "\n" ), ' ', (string) wp_unslash( $_POST['local_cmd'] ) ) );
				update_option( FW_AI_Panel::OPTION_LOCAL_CMD, $cmd, false );
			}
			// The local AI address is used by the editor's BROWSER, so localhost is the normal value here.
			if ( isset( $_POST['browser_url'] ) ) {
				$burl = trim( (string) wp_unslash( $_POST['browser_url'] ) );
				$burl = ( $burl !== '' && preg_match( '#^https?://\S+$#i', $burl ) ) ? untrailingslashit( esc_url_raw( $burl, array( 'http', 'https' ) ) ) : '';
				update_option( FW_AI_Panel::OPTION_BROWSER_URL, $burl, false );
			}
			if ( isset( $_POST['browser_model'] ) ) {
				$bmodel = trim( (string) wp_unslash( $_POST['browser_model'] ) );
				update_option( FW_AI_Panel::OPTION_BROWSER_MODEL, preg_match( '#^[A-Za-z0-9._:/-]{1,80}$#', $bmodel ) ? $bmodel : '', false );
			}
			if ( isset( $_POST['panel_position'] ) ) {
				$position = sanitize_key( wp_unslash( $_POST['panel_position'] ) );
				update_option( FW_AI_Panel::OPTION_POSITION, in_array( $position, FW_AI_Panel::POSITIONS, true ) ? $position : FW_AI_Panel::POSITION_DEFAULT, false );
			}
			$this->notices[] = array( 'success', isset( $_POST['panel_backend'] ) ? __( 'AI model settings saved.', 'fw' ) : __( 'Saved.', 'fw' ) );
			return;
		}

		if ( 'reset' === $action && $this->is_supported() ) {
			// Back to a fresh install: Automatic model choice, default address and panel position, the local
			// agent command cleared, and outside AI programs (MCP) switched off. Connections are revoked one by
			// one in their own list, never in bulk from here.
			foreach ( array( FW_AI_Panel::OPTION_BACKEND, FW_AI_Panel::OPTION_BROWSER_URL, FW_AI_Panel::OPTION_BROWSER_MODEL, FW_AI_Panel::OPTION_POSITION, FW_AI_Panel::OPTION_LOCAL_CMD ) as $opt ) {
				delete_option( $opt );
			}
			update_option( FW_AI_MCP::OPTION_MODE, 'off', false );
			delete_option( FW_AI_Access::OPTION_ROLES );
			delete_option( FW_AI_Access::OPTION_ON );
			if ( ! empty( $_POST['clear_chats'] ) && class_exists( 'FW_AI_History' ) ) {
				delete_user_meta( get_current_user_id(), FW_AI_History::META );
			}
			$this->notices[] = array( 'success', ! empty( $_POST['clear_chats'] )
				? __( 'AI Assistant settings reset to their defaults, and your saved conversations cleared.', 'fw' )
				: __( 'AI Assistant settings reset to their defaults.', 'fw' ) );
			return;
		}

		if ( 'create_password' === $action && $this->is_supported() ) {
			$user = wp_get_current_user();
			if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
				$this->notices[] = array( 'error', __( 'Application Passwords are not available for your account on this site.', 'fw' ) );
				return;
			}
			$label   = sanitize_text_field( wp_unslash( $_POST['agent_label'] ?? '' ) );
			$created = WP_Application_Passwords::create_new_application_password( $user->ID, array(
				'name' => trim( self::APP_PASSWORD_NAME . ( $label !== '' ? ' — ' . $label : '' ) ),
			) );
			if ( is_wp_error( $created ) ) {
				$this->notices[] = array( 'error', $created->get_error_message() );
				return;
			}
			$password             = WP_Application_Passwords::chunk_password( $created[0] );
			$this->new_connection = array(
				'user'     => $user->user_login,
				'password' => $password,
				'header'   => 'Basic ' . base64_encode( $user->user_login . ':' . str_replace( ' ', '', $password ) ),
			);
			if ( FW_AI_MCP::mode() === 'off' ) {
				// Creating a connection is a clear "I want an agent to connect": turn access on rather than
				// leaving a password that is refused with a 403 (Read-only is one click away below).
				update_option( FW_AI_MCP::OPTION_MODE, 'write', false );
				$this->notices[] = array( 'success', __( 'Connection created, and MCP access switched on (Read & write) so the agent can connect. Change it to Read-only below if the agent should only look.', 'fw' ) );
			}
			return;
		}

		if ( 'revoke_password' === $action ) {
			$uuid = sanitize_text_field( wp_unslash( $_POST['uuid'] ?? '' ) );
			$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
			if ( $item && strpos( (string) $item['name'], self::APP_PASSWORD_NAME ) === 0 ) {
				WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
				$this->notices[] = array( 'success', __( 'Connection revoked.', 'fw' ) );
			}
		}

		if ( 'revoke_app' === $action ) {
			FW_AI_OAuth::revoke_app( get_current_user_id(), sanitize_text_field( wp_unslash( $_POST['client'] ?? '' ) ) );
			$this->notices[] = array( 'success', __( 'The app was signed out.', 'fw' ) );
		}
	}

	/**
	 * The Application Passwords this screen created for the current user.
	 *
	 * @return array[]
	 */
	public function get_connections() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}
		// One-off passwords the chat panel issues for a single request are not connections: they are
		// deleted when the request finishes, and any a closed tab left behind are swept here.
		if ( class_exists( 'FW_AI_Local' ) ) {
			FW_AI_Local::sweep();
		}
		$out = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $item ) {
			if ( strpos( (string) $item['name'], self::APP_PASSWORD_NAME ) === 0 && strpos( (string) $item['name'], '(temporary)' ) === false ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @internal
	 */
	public function render_page() {
		echo $this->render_view( 'page', array(
			'ext'            => $this,
			'new_connection' => $this->new_connection,
			'notices'        => $this->notices,
		) );
	}
}
