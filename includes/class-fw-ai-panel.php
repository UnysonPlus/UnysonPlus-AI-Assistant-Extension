<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The builder assistant panel: a chat panel in the backend page builder and the Live Page Editor.
 *
 * The AI works on the tree the person has OPEN (unsaved edits included), never on the database:
 * the panel posts the current tree, the abilities run against an FW_AI_Store sandbox of it, and the
 * changed tree comes back to the browser, which applies it as ONE step on the builder's own
 * undo history. Nothing is saved until the person presses Update / Save — exactly like a manual edit.
 *
 * Two model backends:
 *   wp    — WordPress's AI Client with a provider key from Settings → Connectors (WP 7+). Runs the
 *           tool loop in this request.
 *   local — development hosts only: runs a command-line AI agent installed on this machine (the
 *           command template is set on the AI Assistant screen) in the background, pointed at the
 *           extension's MCP endpoint with a one-off session header. The MCP handler sandboxes the
 *           session's tree the same way, and the panel polls for the result.
 *
 *   POST /wp-json/unysonplus-ai/v1/panel/run      { post_id, tree, message, history[] }
 *   POST /wp-json/unysonplus-ai/v1/site/run       { message, history[] }   (site-wide assistant)
 *   GET  /wp-json/unysonplus-ai/v1/panel/status   ?session=…     (local backend only)
 *
 * The SITE-WIDE assistant (an ✦ AI Assistant item in the admin bar, on every admin screen that is
 * not a builder screen) has every unysonplus ability — Theme Settings, presets, new pages, templates,
 * URL conversion — and works on the real site, not a sandbox: pages it creates are drafts, Theme
 * Settings changes are live and undoable. Each successful write is reported back as a step with a link.
 */
class FW_AI_Panel {

	const OPTION_BACKEND   = 'upw_ai_panel_backend';   // auto | wp | local | off
	const OPTION_LOCAL_CMD = 'upw_ai_local_agent_cmd';
	const SESSION_PREFIX   = 'upw_ai_ps_';
	const SESSION_TTL      = 1800;
	const LOCAL_TIMEOUT    = 600;
	const MAX_ROUNDS       = 16;

	/** Abilities the panel may use (no create-page / revisions: undo is the builder's own history). */
	const TOOLS = array(
		'site-info', 'list-elements', 'describe-element', 'get-page', 'list-presets',
		'search-content', 'get-content', 'render-check', 'list-templates', 'apply-template', 'insert-items', 'update-element', 'move-element', 'remove-element',
	);

	/**
	 * The builder panel's tool slugs: its own plus those extensions registered with 'panel' => true.
	 *
	 * @return string[]
	 */
	public static function page_tools() {
		wp_get_abilities(); // Make sure extension abilities are registered.
		return array_values( array_unique( array_merge( self::TOOLS, FW_AI_Toolkit::panel_tools() ) ) );
	}

	/** @var array[] Successful unysonplus writes during this request: { ability, note, url }. */
	private static $activity = array();

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'record_activity' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 95 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_live_editor' ), 50 );
	}

	/* ------------------------------------------------------------------ *
	 * Backends
	 * ------------------------------------------------------------------ */

	/**
	 * @return bool Whether WordPress's AI Client can generate text with a configured provider.
	 */
	public static function wp_backend_ready() {
		static $ready = null;
		if ( $ready === null ) {
			$ready = false;
			if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai()
				&& class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
				$ok    = wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
				$ready = ( true === $ok );
			}
		}
		return $ready;
	}

	/**
	 * @return bool Whether the local command-line agent backend is configured and allowed here.
	 */
	public static function local_backend_ready() {
		return FW_AI_Local::ready()
			&& function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
	}

	/**
	 * @return string wp | local | '' (none available)
	 */
	public static function backend() {
		$pref = (string) get_option( self::OPTION_BACKEND, 'auto' );
		if ( $pref === 'off' ) {
			return '';
		}
		if ( ( $pref === 'wp' || $pref === 'auto' ) && self::wp_backend_ready() ) {
			return 'wp';
		}
		if ( ( $pref === 'local' || $pref === 'auto' ) && self::local_backend_ready() ) {
			return 'local';
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Activity (what the site-wide assistant changed)
	 * ------------------------------------------------------------------ */

	/**
	 * `wp_after_execute_ability`: remember each successful unysonplus WRITE, with a link to what it
	 * changed, so the reply can list it.
	 *
	 * @param string $name
	 * @param mixed  $input
	 * @param mixed  $result
	 */
	public static function record_activity( $name, $input, $result ) {
		if ( strpos( (string) $name, 'unysonplus/' ) !== 0 || is_wp_error( $result ) ) {
			return;
		}
		$ability = wp_get_ability( $name );
		$ann     = $ability ? (array) $ability->get_meta_item( 'annotations', array() ) : array();
		if ( ! empty( $ann['readonly'] ) ) {
			return;
		}
		$note = is_array( $result ) && ! empty( $result['message'] ) ? (string) $result['message'] : ( $ability ? $ability->get_label() : $name );
		$url  = '';
		if ( is_array( $result ) && ! empty( $result['post_id'] ) ) {
			$url = (string) get_edit_post_link( (int) $result['post_id'], 'raw' );
			$note .= ' — ' . get_the_title( (int) $result['post_id'] );
		} elseif ( is_array( $result ) && ( isset( $result['changed'] ) || isset( $result['preset'] ) || isset( $result['restored'] ) ) ) {
			$url = admin_url( 'admin.php?page=fw-settings' );
		}
		self::$activity[] = array(
			'ability' => (string) $name,
			'note'    => wp_html_excerpt( wp_strip_all_tags( $note ), 200, '…' ),
			'url'     => $url,
		);
	}

	/**
	 * @return array[] The activity log (and clears it).
	 */
	public static function take_activity() {
		$a              = self::$activity;
		self::$activity = array();
		return $a;
	}

	/* ------------------------------------------------------------------ *
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $hook
	 */
	public static function enqueue_admin( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$post = get_post();
			if ( $post && current_user_can( 'edit_post', $post->ID ) && self::builder_type( $post->post_type ) ) {
				self::enqueue( $post->ID, 'builder' );
				return;
			}
		}
		if ( current_user_can( 'edit_pages' ) && get_option( self::OPTION_BACKEND, 'auto' ) !== 'off' ) {
			self::enqueue( 0, 'site' );
		}
	}

	/**
	 * An ✦ AI Assistant item in the admin bar (every admin screen) that opens the panel.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_pages' ) || get_option( self::OPTION_BACKEND, 'auto' ) === 'off' ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'upw-ai-assistant',
			'title' => '<span class="ab-icon" aria-hidden="true" style="font-size:16px;line-height:1.9">✦</span><span class="ab-label">' . esc_html__( 'AI Assistant', 'fw' ) . '</span>',
			'href'  => '#',
			'meta'  => array( 'title' => __( 'Ask the AI Assistant (Beta)', 'fw' ) ),
		) );
	}

	/**
	 * The Live Page Editor shell is a front-end request that enqueues `fw-live-editor`.
	 */
	public static function enqueue_live_editor() {
		if ( ! wp_script_is( 'fw-live-editor', 'enqueued' ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		self::enqueue( $post->ID, 'live' );
	}

	/**
	 * @param int    $post_id
	 * @param string $host builder | live
	 */
	private static function enqueue( $post_id, $host ) {
		$ext = fw_ext( 'ai-assistant' );
		$ver = $ext->manifest->get_version();
		wp_enqueue_style( 'upw-ai-panel', $ext->get_uri( '/static/css/panel.css' ), array(), $ver );
		// Head for the builder: the script must be listening before the builder fires its init event.
		wp_enqueue_script( 'upw-ai-panel', $ext->get_uri( '/static/js/panel.js' ), array( 'jquery' ), $ver, $host === 'live' );
		$backend = self::backend();
		wp_localize_script( 'upw-ai-panel', 'upwAiPanel', array(
			'host'     => $host,
			'postId'   => (int) $post_id,
			'backend'  => $backend,
			'runUrl'   => rest_url( FW_AI_MCP::REST_NS . ( $host === 'site' ? '/site/run' : '/panel/run' ) ),
			'pollUrl'  => rest_url( FW_AI_MCP::REST_NS . '/panel/status' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'setupUrl' => FW_Extension_AI_Assistant::get_page_url(),
			'l10n'     => array(
				'title'       => __( 'AI Assistant', 'fw' ),
				'beta'        => __( 'Beta', 'fw' ),
				'open'        => __( 'AI Assistant (Beta)', 'fw' ),
				'placeholder' => __( 'Ask for a change — e.g. "Add a pricing section with three plans"', 'fw' ),
				'send'        => __( 'Send', 'fw' ),
				'working'     => __( 'Working on it…', 'fw' ),
				'undo'        => __( 'Undo this change', 'fw' ),
				'undone'      => __( 'Change undone.', 'fw' ),
				'noChange'    => __( 'No changes were made to the page.', 'fw' ),
				'applied'     => __( 'Applied to the builder — press Update / Save to keep it.', 'fw' ),
				'error'       => __( 'Something went wrong:', 'fw' ),
				'noBackend'   => __( 'No AI model is connected yet. Add a provider key under Settings → Connectors, or set up an agent on the AI Assistant screen.', 'fw' ),
				'setup'       => __( 'Open AI Assistant settings', 'fw' ),
				'starters'    => array(
					__( 'Add a pricing section with three plans', 'fw' ),
					__( 'Add a FAQ section with five questions', 'fw' ),
					__( 'Rewrite the headings to sound more confident', 'fw' ),
				),
				'stale'       => __( 'The page changed while the assistant was working, so its result was not applied. Please ask again.', 'fw' ),
				'check'       => __( 'Page check:', 'fw' ),
				'change'      => __( 'change', 'fw' ),
				'changes'     => __( 'changes', 'fw' ),
				'siteTitle'   => __( 'AI Assistant — whole site', 'fw' ),
				'sitePlaceholder' => __( 'Ask for anything on your site — e.g. "Create a draft About page with our story and team"', 'fw' ),
				'siteStarters'    => array(
					__( 'Create a draft About page with our story, values and team', 'fw' ),
					__( 'Give the site a warm colour palette and friendly fonts', 'fw' ),
					__( 'Add a rounded "Pill" button style and use it for calls to action', 'fw' ),
				),
				'siteDone'    => __( 'Done. New pages stay drafts until you publish them; Theme Settings changes are live — ask me to undo them if needed.', 'fw' ),
				'siteNoChange' => __( 'Nothing on the site was changed.', 'fw' ),
				'open_link'   => __( 'Open', 'fw' ),
			),
		) );
	}

	/**
	 * @param string $post_type
	 * @return bool
	 */
	private static function builder_type( $post_type ) {
		if ( ! function_exists( 'fw_ext_page_builder_get_supported_post_types' ) ) {
			return $post_type === 'page';
		}
		$types = (array) fw_ext_page_builder_get_supported_post_types();
		return isset( $types[ $post_type ] ) || in_array( $post_type, $types, true );
	}

	/* ------------------------------------------------------------------ *
	 * REST
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_run' ),
			'permission_callback' => function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'post_id' );
				return $id && get_post( $id ) && current_user_can( 'edit_post', $id );
			},
			'args'                => array(
				'post_id' => array( 'type' => 'integer', 'required' => true ),
				'message' => array( 'type' => 'string', 'required' => true ),
				'tree'    => array( 'type' => 'array', 'default' => array() ),
				'history' => array( 'type' => 'array', 'default' => array() ),
			),
		) );
		register_rest_route( FW_AI_MCP::REST_NS, '/site/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_site_run' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
			'args'                => array(
				'message' => array( 'type' => 'string', 'required' => true ),
				'history' => array( 'type' => 'array', 'default' => array() ),
			),
		) );
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_status' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'session' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_run( WP_REST_Request $r ) {
		$post_id = (int) $r->get_param( 'post_id' );
		$message = trim( (string) $r->get_param( 'message' ) );
		$tree    = (array) $r->get_param( 'tree' );
		$history = self::clean_history( (array) $r->get_param( 'history' ) );
		if ( $message === '' ) {
			return new WP_Error( 'upw_ai_empty', 'Empty message.', array( 'status' => 400 ) );
		}

		switch ( self::backend() ) {
			case 'wp':
				return rest_ensure_response( self::run_wp( $post_id, $tree, $message, $history ) );
			case 'local':
				return rest_ensure_response( self::start_local( $post_id, $tree, $message, $history ) );
		}
		return new WP_Error( 'upw_ai_no_backend', 'No AI model is connected. Add a provider key under Settings → Connectors, or configure an agent on the AI Assistant screen.', array( 'status' => 503 ) );
	}

	/**
	 * The site-wide assistant.
	 *
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_site_run( WP_REST_Request $r ) {
		$message = trim( (string) $r->get_param( 'message' ) );
		$history = self::clean_history( (array) $r->get_param( 'history' ) );
		if ( $message === '' ) {
			return new WP_Error( 'upw_ai_empty', 'Empty message.', array( 'status' => 400 ) );
		}
		switch ( self::backend() ) {
			case 'wp':
				self::take_activity();
				$reply = self::run_loop( self::site_abilities(), self::site_instructions(), $history, $message );
				if ( is_wp_error( $reply ) ) {
					return $reply;
				}
				$steps = self::take_activity();
				return rest_ensure_response( array(
					'status'  => 'done',
					'reply'   => $reply !== '' ? $reply : ( $steps ? 'Done.' : 'I could not finish that — please try rephrasing.' ),
					'changed' => (bool) $steps,
					'steps'   => $steps,
				) );
			case 'local':
				return rest_ensure_response( self::start_local( 0, array(), $message, $history, 'site' ) );
		}
		return new WP_Error( 'upw_ai_no_backend', 'No AI model is connected. Add a provider key under Settings → Connectors, or configure an agent on the AI Assistant screen.', array( 'status' => 503 ) );
	}

	/**
	 * Every unysonplus ability, for the site-wide assistant.
	 *
	 * @return string[]
	 */
	public static function site_abilities() {
		$out = array();
		foreach ( wp_get_abilities() as $a ) {
			if ( strpos( $a->get_name(), 'unysonplus/' ) === 0 ) {
				$out[] = $a->get_name();
			}
		}
		return $out;
	}

	/**
	 * @return string
	 */
	private static function site_instructions() {
		$user = wp_get_current_user();
		return implode( "\n", array(
			'You are the UnysonPlus AI Assistant for the WordPress site "' . wp_strip_all_tags( get_bloginfo( 'name' ) ) . '" (' . home_url( '/' ) . '), talking to ' . $user->display_name . ' inside the WordPress admin.',
			'You can read and change the whole site through the unysonplus tools: Theme Settings, design presets, pages, templates. Start with site_info.',
			'Work outside-in when building: the design system first (describe_theme_settings, update_theme_settings, save_preset), then pages (create_page, apply_template or insert_items section by section), then render_check each page you built and fix what it reports.',
			'Before placing an element, call describe_element and use its exact option ids — for list options, only the inner_options keys.',
			'New pages are drafts unless the person asks you to publish. Theme Settings changes are LIVE immediately; mention that, and that undo_theme_settings can revert them.',
			'Ask before anything destructive: removing content they wrote, replacing a page, or convert_url (which replaces pages and activates a new child theme) — only call convert_url with confirm: true after they explicitly agree in this conversation.',
			'If a request is ambiguous, make sensible choices and say what you chose rather than asking many questions.',
			'When done, reply in a few short sentences: what you changed, and anything they should check or do next.',
		) );
	}

	/**
	 * @param array $history
	 * @return array[] { role: user|assistant, text }
	 */
	private static function clean_history( array $history ) {
		$out = array();
		foreach ( array_slice( $history, -12 ) as $h ) {
			if ( ! is_array( $h ) || empty( $h['text'] ) ) {
				continue;
			}
			$out[] = array(
				'role' => ( $h['role'] ?? '' ) === 'assistant' ? 'assistant' : 'user',
				'text' => wp_html_excerpt( (string) $h['text'], 4000, '…' ),
			);
		}
		return $out;
	}

	/**
	 * System instructions + the current outline, shared by both backends.
	 *
	 * @param int   $post_id
	 * @param array $tree
	 * @return string
	 */
	private static function instructions( $post_id, array $tree ) {
		$post = get_post( $post_id );
		return implode( "\n", array(
			'You are the UnysonPlus AI Assistant inside the page builder, editing the page "' . get_the_title( $post ) . '" (post_id ' . $post_id . ').',
			'You edit the version the person has OPEN; your changes are applied to their builder and they press Update to keep them, so act directly — do not ask for confirmation for additive changes.',
			'Always pass post_id ' . $post_id . '. Use the `path` values from the outline below (or get_page) to address items.',
			'Before placing or changing an element, call describe_element for it: unknown option ids are rejected. Set the options that carry the visual (e.g. an icon_box icon, an image) — an unset one renders as an empty gap.',
			'New sections: {type:"flexbox", atts:{html_tag:"section", display:"block"}, _items:[…]}; columns of cards: a flexbox with atts.display "grid" and grid_columns.',
			'Style buttons and cards with Theme Settings presets (list_presets) instead of per-element colours.',
			'Ask before removing content the person wrote.',
			'After your changes, call render_check and fix every error and warning it reports for the items you added or changed. Then reply in one to three short sentences saying what you changed.',
			'',
			'Current page outline (JSON):',
			(string) wp_json_encode( FW_AI_Store::outline( $tree ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Backend: WordPress AI Client
	 * ------------------------------------------------------------------ */

	/**
	 * @param int    $post_id
	 * @param array  $tree
	 * @param string $message
	 * @param array  $history
	 * @return array|WP_Error
	 */
	private static function run_wp( $post_id, array $tree, $message, array $history ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		FW_AI_Store::sandbox( $post_id, $tree );
		FW_AI_Store::take_log();

		$abilities = array();
		foreach ( self::page_tools() as $slug ) {
			$abilities[] = 'unysonplus/' . $slug;
		}
		$reply = self::run_loop( $abilities, self::instructions( $post_id, $tree ), $history, $message );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$steps = FW_AI_Store::take_log();
		$check = $steps ? FW_AI_Check::run( $post_id ) : null;
		return array(
			'check'   => $check,
			'status'  => 'done',
			'reply'   => $reply !== '' ? $reply : ( $steps ? 'Done.' : 'I could not finish that — please try rephrasing.' ),
			'changed' => (bool) $steps,
			'tree'    => $steps ? FW_AI_Store::get_tree( $post_id ) : null,
			'steps'   => $steps,
		);
	}

	/**
	 * The WordPress AI Client tool loop.
	 *
	 * @param string[] $abilities Ability names the model may call.
	 * @param string   $system
	 * @param array    $history
	 * @param string   $message
	 * @return string|WP_Error The final reply text.
	 */
	private static function run_loop( array $abilities, $system, array $history, $message ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$resolver = new WP_AI_Client_Ability_Function_Resolver( ...$abilities );

		$messages = array();
		foreach ( $history as $h ) {
			$part       = new \WordPress\AiClient\Messages\DTO\MessagePart( $h['text'] );
			$messages[] = $h['role'] === 'assistant'
				? new \WordPress\AiClient\Messages\DTO\ModelMessage( array( $part ) )
				: new \WordPress\AiClient\Messages\DTO\UserMessage( array( $part ) );
		}
		$messages[] = new \WordPress\AiClient\Messages\DTO\UserMessage( array( new \WordPress\AiClient\Messages\DTO\MessagePart( $message ) ) );

		$reply = '';
		for ( $round = 0; $round < self::MAX_ROUNDS * 2; $round++ ) {
			$result = wp_ai_client_prompt( $messages )
				->using_system_instruction( $system )
				->using_abilities( ...$abilities )
				->using_max_tokens( 4096 )
				->generate_text_result();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$msg        = $result->toMessage();
			$messages[] = $msg;
			if ( $resolver->has_ability_calls( $msg ) ) {
				$messages[] = $resolver->execute_abilities( $msg );
				continue;
			}
			foreach ( $msg->getParts() as $part ) {
				if ( $part->getType()->isText() && ! $part->getChannel()->isThought() ) {
					$reply .= (string) $part->getText();
				}
			}
			break;
		}
		return trim( $reply );
	}

	/* ------------------------------------------------------------------ *
	 * Backend: local command-line agent (development hosts)
	 * ------------------------------------------------------------------ */

	/**
	 * @param int    $post_id
	 * @param array  $tree
	 * @param string $message
	 * @param array  $history
	 * @return array|WP_Error
	 */
	private static function start_local( $post_id, array $tree, $message, array $history, $mode = 'page' ) {
		$user = wp_get_current_user();
		$pw   = WP_Application_Passwords::create_new_application_password( $user->ID, array(
			'name' => FW_Extension_AI_Assistant::APP_PASSWORD_NAME . ( $mode === 'site' ? ' — site assistant' : ' — builder panel' ) . ' (temporary)',
		) );
		if ( is_wp_error( $pw ) ) {
			return $pw;
		}

		$session = strtolower( wp_generate_password( 24, false ) );
		$config  = array( 'mcpServers' => array( 'unysonplus' => array(
			'type'    => 'http',
			'url'     => FW_AI_MCP::endpoint(),
			'headers' => array(
				'Authorization'    => 'Basic ' . base64_encode( $user->user_login . ':' . $pw[0] ),
				'X-UPW-AI-Session' => $session,
			),
		) ) );

		$prompt = ( $mode === 'site' ? self::site_instructions() : self::instructions( $post_id, $tree ) ) . "\n\nUse only the unysonplus MCP tools.\n";
		if ( $history ) {
			$prompt .= "\nConversation so far:\n";
			foreach ( $history as $h ) {
				$prompt .= ( $h['role'] === 'assistant' ? 'Assistant: ' : 'User: ' ) . $h['text'] . "\n";
			}
		}
		$prompt .= "\nRequest: " . $message . "\n";

		// The session must exist before the agent's first MCP request arrives.
		$data = array(
			'mode'    => $mode,
			'user'    => $user->ID,
			'post_id' => (int) $post_id,
			'tree'    => $tree,
			'steps'   => array(),
			'status'  => 'running',
			'started' => time(),
			'dir'     => '',
			'app_pw'  => $pw[1]['uuid'],
		);
		self::put_session( $session, $data );

		$dir = FW_AI_Local::spawn( $config, $prompt );
		if ( is_wp_error( $dir ) ) {
			WP_Application_Passwords::delete_application_password( $user->ID, $pw[1]['uuid'] );
			delete_transient( self::SESSION_PREFIX . $session );
			return $dir;
		}
		$data        = self::get_session( $session );
		$data['dir'] = $dir;
		self::put_session( $session, $data );

		return array( 'status' => 'running', 'session' => $session );
	}

	/**
	 * Session data for the MCP handler (X-UPW-AI-Session), or null.
	 *
	 * @param string $session
	 * @return array|null
	 */
	public static function get_session( $session ) {
		$session = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $session ) );
		if ( $session === '' ) {
			return null;
		}
		$data = get_transient( self::SESSION_PREFIX . $session );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $session
	 * @param array  $data
	 */
	public static function put_session( $session, array $data ) {
		set_transient( self::SESSION_PREFIX . $session, $data, self::SESSION_TTL );
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_status( WP_REST_Request $r ) {
		$session = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $r->get_param( 'session' ) ) );
		$data    = self::get_session( $session );
		if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
			return new WP_Error( 'upw_ai_no_session', 'Unknown or expired session.', array( 'status' => 404 ) );
		}
		if ( $data['status'] !== 'running' ) {
			return rest_ensure_response( $data['result'] );
		}

		$done    = $data['dir'] !== '' && FW_AI_Local::finished( $data['dir'] );
		$expired = time() - (int) $data['started'] > self::LOCAL_TIMEOUT;
		if ( ! $done && ! $expired ) {
			return rest_ensure_response( array(
				'status' => 'running',
				'steps'  => $data['steps'],
			) );
		}

		$reply = $done ? FW_AI_Local::output( $data['dir'] ) : '';
		$ok    = $done && $reply !== '';
		$check = null;
		if ( $data['steps'] && ( $data['mode'] ?? 'page' ) !== 'site' ) {
			// Every build reply carries a render check of the tree the agent produced.
			FW_AI_Store::sandbox( (int) $data['post_id'], (array) $data['tree'] );
			$check = FW_AI_Check::run( (int) $data['post_id'] );
		}
		$result = array(
			'check'   => $check,
			'status'  => $ok ? 'done' : 'error',
			'reply'   => $ok ? wp_html_excerpt( $reply, 4000, '…' ) : ( $expired ? 'The agent did not finish within 10 minutes.' : 'The agent exited without a reply. Check the command on the AI Assistant screen.' ),
			'changed' => (bool) $data['steps'],
			'tree'    => $data['steps'] ? $data['tree'] : null,
			'steps'   => $data['steps'],
		);

		// Clean up: the temporary password, the files, and keep only the result.
		WP_Application_Passwords::delete_application_password( (int) $data['user'], (string) $data['app_pw'] );
		FW_AI_Local::cleanup( $data['dir'] );
		$data['status'] = 'finished';
		$data['result'] = $result;
		$data['tree']   = array();
		self::put_session( $session, $data );

		return rest_ensure_response( $result );
	}
}
