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
 * Three model backends:
 *   wp      — WordPress's AI Client with a provider key from Settings → Connectors (WP 7+). Runs the
 *             tool loop in this request.
 *   local   — development hosts only: runs a command-line AI agent installed on this machine (the
 *             command template is set on the AI Assistant screen) in the background, pointed at the
 *             extension's MCP endpoint with a one-off session header. The MCP handler sandboxes the
 *             session's tree the same way, and the panel polls for the result.
 *   browser — free local AI on the EDITOR'S computer: the panel's JavaScript runs the tool loop itself,
 *             talking to a local model (the AI Dev Kit's capture service, or Ollama directly) on
 *             localhost — which the browser can reach even when this site is hosted elsewhere — and to
 *             this site's MCP endpoint (cookie auth + the session header) for the tools. The server
 *             only opens and closes the session; it never talks to the model.
 *
 *   POST /wp-json/unysonplus-ai/v1/panel/run           { post_id, tree, message, history[] }
 *   POST /wp-json/unysonplus-ai/v1/site/run            { message, history[] }   (site-wide assistant)
 *   GET  /wp-json/unysonplus-ai/v1/panel/status        ?session=…     (local backend only)
 *   POST /wp-json/unysonplus-ai/v1/panel/local/start   { post_id, tree, mode }  (browser backend)
 *   POST /wp-json/unysonplus-ai/v1/panel/local/finish  { session, reply, error } (browser backend)
 *
 * The SITE-WIDE assistant (an ✦ AI Assistant item in the admin bar, on every admin screen that is
 * not a builder screen) has every unysonplus ability — Theme Settings, presets, new pages, templates,
 * URL conversion — and works on the real site, not a sandbox: pages it creates are drafts, Theme
 * Settings changes are live and undoable. Each successful write is reported back as a step with a link.
 */
class FW_AI_Panel {

	const OPTION_BACKEND   = 'upw_ai_panel_backend';   // auto | wp | local | browser | off
	const OPTION_BROWSER_URL   = 'upw_ai_browser_url';   // the local AI address, as seen from the editor's browser
	const OPTION_BROWSER_MODEL = 'upw_ai_browser_model'; // optional model tag; blank = the kit's pick
	const BROWSER_URL_DEFAULT  = 'http://localhost:8787';
	const BROWSER_ROUNDS       = 14;
	const OPTION_LOCAL_CMD = 'upw_ai_local_agent_cmd';
	const OPTION_POSITION  = 'upw_ai_panel_position';  // bottom-right | bottom-left | beside-sidebar
	const POSITIONS        = array( 'bottom-right', 'bottom-left', 'beside-sidebar' );
	const POSITION_DEFAULT = 'bottom-right';
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
	 * Smaller tool sets for the browser (local model) backend: a 4–14B model picks tools far more
	 * reliably from a short list, and every tool's schema costs context it does not have much of.
	 */
	const BROWSER_PAGE_TOOLS = array(
		'get-page', 'list-elements', 'describe-element', 'list-presets', 'list-templates', 'apply-template',
		'insert-items', 'update-element', 'move-element', 'remove-element', 'render-check',
	);
	const BROWSER_SITE_TOOLS = array(
		'site-info', 'list-elements', 'describe-element', 'list-presets', 'list-templates', 'apply-template',
		'create-page', 'get-page', 'insert-items', 'update-element', 'remove-element', 'render-check',
		'describe-theme-settings', 'update-theme-settings', 'undo-theme-settings', 'update-site-identity',
		'visual-check', 'replace-text', 'list-media', 'update-media', 'set-featured-image', 'extract-colors',
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

	/** @var array Where this request came from: { title: the title typed so far, context: FW_AI_Context::text(), focus: the Live Editor's selected element }. */
	private static $place = array( 'title' => '', 'context' => '', 'focus' => '' );

	/**
	 * @param WP_REST_Request $r
	 */
	private static function take_place( WP_REST_Request $r ) {
		self::$place = array(
			'title'   => wp_html_excerpt( trim( wp_strip_all_tags( (string) $r->get_param( 'title' ) ) ), 200, '' ),
			'context' => self::clip( (string) $r->get_param( 'context' ), 3000 ),
			// The element the Live Editor has selected (its "Ask AI about this"
			// button), so "this"/"here" in the message resolve to that item. Plain
			// text sentence built client-side; capped and tag-stripped here.
			'focus'   => wp_html_excerpt( trim( wp_strip_all_tags( (string) $r->get_param( 'focus' ) ) ), 600, '…' ),
		);
	}

	/**
	 * @return string The context block for the instructions ('' when none).
	 */
	private static function place_text() {
		$out = self::$place['context'] !== '' ? "\n\n" . self::$place['context'] : '';
		if ( self::$place['focus'] !== '' ) {
			$out .= "\n\n" . self::$place['focus'];
		}
		return $out;
	}

	/** @var array[] Successful unysonplus writes during this request: { ability, note, url }. */
	private static $activity = array();

	/**
	 * @internal ajax: the launcher has pulsed for these suggestion ids; do not pulse for them again.
	 *
	 * Failing quietly is right here: this is cosmetic bookkeeping, and an error would only ever produce one
	 * extra pulse. It must never interrupt whatever the user is actually doing.
	 */
	public static function _ajax_suggestions_seen() {
		check_ajax_referer( 'upw_ai_suggestions' );
		if ( ! current_user_can( 'edit_posts' ) || ! class_exists( 'FW_AI_Suggestions' ) ) { wp_send_json_error( array(), 403 ); }
		$ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		FW_AI_Suggestions::mark_seen( array_map( 'sanitize_key', $ids ) );
		wp_send_json_success();
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'record_activity' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 95 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		// The launcher tells us which suggestion ids it has drawn attention to, so it never does it twice.
		add_action( 'wp_ajax_upw_ai_suggestions_seen', array( __CLASS__, '_ajax_suggestions_seen' ) );
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
	 * The server cannot see whether a local model is running on the editor's computer, so "browser" is
	 * chosen explicitly — or as Automatic's last resort, where the panel then checks for it and explains
	 * the setup when nothing answers.
	 *
	 * @return string wp | local | browser | '' (off)
	 */
	/**
	 * A reply trimmed to $max characters with tags stripped but LINE BREAKS KEPT (wp_html_excerpt()
	 * collapses them, which ran every list and paragraph of a reply into one line).
	 *
	 * @param string $text
	 * @param int    $max
	 * @return string
	 */
	public static function clip( $text, $max ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max ) ) . '…' : $text;
	}

	public static function backend() {
		$pref = (string) get_option( self::OPTION_BACKEND, 'auto' );
		if ( $pref === 'off' ) {
			return '';
		}
		if ( $pref === 'browser' ) {
			return 'browser';
		}
		if ( ( $pref === 'wp' || $pref === 'auto' ) && self::wp_backend_ready() ) {
			return 'wp';
		}
		if ( ( $pref === 'local' || $pref === 'auto' ) && self::local_backend_ready() ) {
			return 'local';
		}
		return $pref === 'auto' ? 'browser' : '';
	}

	/**
	 * The "Connected: …" line at the top of the panel, so the person knows which AI is answering. The
	 * browser backend writes its own line once it has found what runs on the editor's computer.
	 *
	 * @param string $backend
	 * @return string
	 */
	private static function backend_label( $backend ) {
		if ( $backend === 'wp' ) {
			return __( 'Connected: your AI provider, through WordPress (Settings → Connectors).', 'fw' );
		}
		if ( $backend === 'local' ) {
			return stripos( (string) get_option( self::OPTION_LOCAL_CMD, '' ), 'claude' ) !== false
				? __( 'Connected: Claude — Claude Code on this computer.', 'fw' )
				: __( 'Connected: the AI agent command on this computer.', 'fw' );
		}
		return '';
	}

	/**
	 * @return string The local AI address the editor's browser talks to.
	 */
	public static function browser_url() {
		$url = trim( (string) get_option( self::OPTION_BROWSER_URL, '' ) );
		return $url !== '' ? untrailingslashit( $url ) : self::BROWSER_URL_DEFAULT;
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
		// A preview (replace_text without apply) or a call that did
		// nothing is not a change, so it is not listed as one.
		if ( is_array( $result ) && ( ! empty( $result['preview'] ) || ( array_key_exists( 'ok', $result ) && ! $result['ok'] ) ) ) {
			return;
		}
		$note = is_array( $result ) && ! empty( $result['message'] ) ? (string) $result['message'] : ( $ability ? $ability->get_label() : $name );
		$url  = '';
		if ( is_array( $result ) && ! empty( $result['post_id'] ) ) {
			$url = (string) get_edit_post_link( (int) $result['post_id'], 'raw' );
			$note .= ' — ' . get_the_title( (int) $result['post_id'] );
		} elseif ( $name === 'unysonplus/replace-text' ) {
			$url = FW_AI_Changes::url();
		} elseif ( $name === 'unysonplus/update-site-identity' ) {
			$url = admin_url( 'options-general.php' );
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
			if ( $post && current_user_can( 'edit_post', $post->ID ) && self::builder_type( $post->post_type ) && FW_AI_Access::can_use() ) {
				self::enqueue( $post->ID, 'builder' );
				return;
			}
		}
		if ( current_user_can( 'edit_pages' ) && get_option( self::OPTION_BACKEND, 'auto' ) !== 'off' && FW_AI_Access::can_use() ) {
			self::enqueue( 0, 'site' );
		}
	}

	/**
	 * An ✦ AI Assistant item in the admin bar (every admin screen) that opens the panel.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_pages' ) || get_option( self::OPTION_BACKEND, 'auto' ) === 'off' || ! FW_AI_Access::can_use() ) {
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
	 * Where the panel sits: bottom-right (default), bottom-left, or beside-sidebar (in the backend
	 * builder, anchored left of the right-hand sidebar so the Publish box stays clear; elsewhere
	 * bottom-right).
	 *
	 * @return string
	 */
	public static function position() {
		$p = (string) get_option( self::OPTION_POSITION, self::POSITION_DEFAULT );
		return in_array( $p, self::POSITIONS, true ) ? $p : self::POSITION_DEFAULT;
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
		$ctx     = $host === 'site'
			? FW_AI_Context::for_screen( function_exists( 'get_current_screen' ) ? get_current_screen() : null )
			: FW_AI_Context::for_post( (int) $post_id );
		wp_localize_script( 'upw-ai-panel', 'upwAiPanel', array(
			'host'     => $host,
			'context'  => FW_AI_Context::text( $ctx ),
			// Starter IDEAS for this screen / page, chosen by rules from its real state (FW_AI_Context).
			'ideas'    => $ctx['suggestions'],
			'history'  => array(
				'key'      => $ctx['key'],
				'saved'    => FW_AI_History::get( $ctx['key'] ),
				'url'      => rest_url( FW_AI_MCP::REST_NS . '/panel/history' ),
				'clearUrl' => rest_url( FW_AI_MCP::REST_NS . '/panel/history/clear' ),
			),
			'position' => self::position(),
			'postId'   => (int) $post_id,
			'backend'  => $backend,
			'backendLabel' => self::backend_label( $backend ),
			'runUrl'   => rest_url( FW_AI_MCP::REST_NS . ( $host === 'site' ? '/site/run' : '/panel/run' ) ),
			'pollUrl'  => rest_url( FW_AI_MCP::REST_NS . '/panel/status' ),
			'local'    => array(
				'url'       => self::browser_url(),
				'model'     => (string) get_option( self::OPTION_BROWSER_MODEL, '' ),
				'startUrl'  => rest_url( FW_AI_MCP::REST_NS . '/panel/local/start' ),
				'finishUrl' => rest_url( FW_AI_MCP::REST_NS . '/panel/local/finish' ),
				'mcpUrl'    => FW_AI_MCP::endpoint(),
				'rounds'    => self::BROWSER_ROUNDS,
			),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			// Images attached in the chat go to the Media Library (the AI looks at them with view_media).
			'mediaUrl' => current_user_can( 'upload_files' ) ? rest_url( 'wp/v2/media' ) : '',
			// SUGGESTIONS queued by other extensions (the queue lives in core, so it is readable whether or
			// not anything is listening when they are written -- see framework/includes/ai-suggestions.php).
			'suggestions' => class_exists( 'FW_AI_Suggestions' ) ? array_map( function ( $row ) {
				return array( 'id' => $row['id'], 'title' => $row['title'], 'prompt' => $row['prompt'] );
			}, FW_AI_Suggestions::for_user() ) : array(),
			// Ids this user's launcher has not drawn attention to yet. The launcher pulses ONCE per id:
			// pulsing on every admin page load for as long as a suggestion lives is the nag we are avoiding.
			'unseen'   => class_exists( 'FW_AI_Suggestions' ) ? FW_AI_Suggestions::unseen_for_user() : array(),
			'seenUrl'  => admin_url( 'admin-ajax.php' ),
			'seenNonce' => wp_create_nonce( 'upw_ai_suggestions' ),
			// Only for people who can open the settings screen (manage_options); others would get "not allowed".
			'setupUrl' => current_user_can( 'manage_options' ) ? FW_Extension_AI_Assistant::get_page_url() : '',
			'changesUrl' => FW_AI_Changes::url(),
			'l10n'     => array(
				'title'       => __( 'AI Assistant', 'fw' ),
				'beta'        => __( 'Beta', 'fw' ),
				'open'        => __( 'AI Assistant (Beta)', 'fw' ),
				'placeholder' => __( 'Ask for a change — e.g. "Add a pricing section with three plans"', 'fw' ),
				'send'        => __( 'Send', 'fw' ),
				'attach'      => __( 'Attach an image (a screenshot or sketch to build from)', 'fw' ),
				'moreIdeas'   => __( 'More ideas', 'fw' ),
				'moreIdeasPrompt' => __( 'Look at where I am (this page or screen) and suggest 4 specific, useful things you could do here with your tools — based on what is actually here, not generic advice. Write each as ONE line starting with "→ " and phrased as a request I could send you, e.g. "→ Add a FAQ section with 5 questions about pricing". Put one short sentence before the list and nothing after it. Do not change anything yet.', 'fw' ),
				'attachRemove' => __( 'Remove the image', 'fw' ),
				'attachDefault' => __( 'Build a draft page that looks like this image.', 'fw' ),
				'attachUploading' => __( 'Uploading the image…', 'fw' ),
				'attachFailed' => __( 'The image could not be uploaded:', 'fw' ),
				'attachType'  => __( 'Attach a PNG, JPEG, WebP or GIF image.', 'fw' ),
				// Element focus chip (Live Editor's "Ask AI about this").
				'editing'     => __( 'Editing', 'fw' ),
				'clearFocus'  => __( 'Clear selection', 'fw' ),
				'askAbout'    => __( 'Ask about this', 'fw' ),
				'thisElement' => __( 'this element', 'fw' ),
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
				'allChanges'  => __( 'See all AI changes', 'fw' ),
				'clear'       => __( 'New chat', 'fw' ),
				'clearTitle'  => __( 'Clear this conversation and start a new one', 'fw' ),
				'earlier'     => __( 'Applied earlier. To reverse it, use the builder\'s revisions or ask me to undo it.', 'fw' ),
				/* translators: %s: local model name, e.g. qwen3:8b */
				'localReady'  => __( 'Local AI on this computer: %s — free and private, but slower and less capable than a cloud model. Best for one section at a time.', 'fw' ),
				'localNone'   => __( 'No AI model is connected. For free AI on this computer, start the UnysonPlus AI Dev Kit (it runs a local model with Ollama) and reopen this panel — or add a provider key under Settings → Connectors.', 'fw' ),
				'localNoModel' => __( 'The AI Dev Kit is running but has no model downloaded yet. Open its dashboard (http://localhost:4600) → Settings → Local AI models and pull Qwen3 8B (or Qwen3 4B on a smaller PC).', 'fw' ),
				'localOllamaDown' => __( 'The AI Dev Kit is running but Ollama is not. Restart the kit with start-converter.bat.', 'fw' ),
				'localOldKit' => __( 'Your AI Dev Kit is too old for the AI Assistant — update it (capture service 1.11.60 or newer), or set the Local AI address to Ollama (http://localhost:11434).', 'fw' ),
				'localSlow'   => __( 'The local model took more than five minutes to answer and was stopped. Try a smaller request, or a smaller model (Qwen3 4B) on this computer.', 'fw' ),
				'localClaude' => __( 'Connected: Claude — your Claude subscription, through the AI Dev Kit on this computer.', 'fw' ),
				'claudeWorking' => __( 'Claude is working', 'fw' ),
				'localSafari' => __( 'Safari does not let web pages talk to programs on this computer. Use Chrome, Edge or Firefox for local AI.', 'fw' ),
				'localRetry'  => __( 'Check again', 'fw' ),
				'localSettings' => __( 'AI Assistant settings', 'fw' ),
				/* translators: %s: tool name */
				'localUsing'  => __( 'Using %s', 'fw' ),
				'localThinking' => __( 'Thinking', 'fw' ),
				'localTooMany' => __( 'Stopped after too many steps. Whatever was done so far is below.', 'fw' ),
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
				'title'   => array( 'type' => 'string', 'default' => '' ),
				'context' => array( 'type' => 'string', 'default' => '' ),
				'focus'   => array( 'type' => 'string', 'default' => '' ),
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
				'context' => array( 'type' => 'string', 'default' => '' ),
			),
		) );
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/local/start', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_local_start' ),
			'permission_callback' => function ( WP_REST_Request $r ) {
				if ( $r->get_param( 'mode' ) === 'site' ) {
					return current_user_can( 'edit_pages' );
				}
				$id = (int) $r->get_param( 'post_id' );
				return $id && get_post( $id ) && current_user_can( 'edit_post', $id );
			},
			'args'                => array(
				'mode'    => array( 'type' => 'string', 'enum' => array( 'page', 'site' ), 'default' => 'page' ),
				'post_id' => array( 'type' => 'integer', 'default' => 0 ),
				'tree'    => array( 'type' => 'array', 'default' => array() ),
				'title'   => array( 'type' => 'string', 'default' => '' ),
				'context' => array( 'type' => 'string', 'default' => '' ),
				'focus'   => array( 'type' => 'string', 'default' => '' ),
				// agent: true = Claude Code on the editor's computer (via the AI Dev Kit) runs the whole
				// request against the MCP endpoint, so it gets a temporary Application Password + the prompt.
				'agent'   => array( 'type' => 'boolean', 'default' => false ),
				'message' => array( 'type' => 'string', 'default' => '' ),
				'history' => array( 'type' => 'array', 'default' => array() ),
			),
		) );
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/local/finish', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_local_finish' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'session' => array( 'type' => 'string', 'required' => true ),
				'reply'   => array( 'type' => 'string', 'default' => '' ),
				'error'   => array( 'type' => 'string', 'default' => '' ),
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
		self::take_place( $r );
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
			case 'browser':
				return new WP_Error( 'upw_ai_browser_backend', 'The local AI backend runs in the browser — use /panel/local/start.', array( 'status' => 400 ) );
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
		self::take_place( $r );
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
			'Building or restyling a site follows the UnysonPlus site-build protocol, in THIS order — never jump to page sections first:',
			'  1. Colours: the Color Presets (theme_colors) by name — Primary, Secondary, Accent … (save_preset type theme_colors).',
			'  2. Typography: typography (heading_font, body family / size / line-height / colour, and the h1–h6 scale).',
			'  3. Container width: general_layout.layout_container_width — every section depends on it.',
			'  4. Buttons, boxes, section styles as presets (save_preset) — elements then use presets, never per-element colours.',
			'  5. Header (logo lockup, navigation, the call-to-action) and footer (widget columns, not just a copyright line) — build the navigation with menus_create and put it in the primary location with menus_assign.',
			'  6. THEN the pages: create_page, apply_template where a template fits, insert_items section by section with real elements (never a raw HTML code block when an element exists); forms with forms_add.',
			'  7. Ship check: render_check every page you built and fix what it reports; the menu links every new page; each page has a proper SEO title and description (seo_update_page) when the SEO tools are available.',
			'Use native options (Theme Settings, element options, presets) before any custom CSS; misc_custom_css is a last resort for something no option expresses — say when you used it.',
			'To reproduce an EXISTING website, use convert_url (capture first) rather than rebuilding it by hand — only after the person agrees.',
			'To see how a page still differs from a source or reference site, use visual_check (source_url + post_id): it lists, section by section, what is missing, moved or restyled. Fix those and run it again.',
			'To change the same text in many places (a company name, phone number, price), use replace_text: preview first, show the person what will change, and apply the plan only after they agree.',
			'For images: list_media finds them (missing_alt: true for images without alt text) and shows where each is used; look at them with view_media before describing them, then save alt text in batches with update_media. To use a library image in an element, set its image value to { attachment_id, url, alt }.',
			'To translate a page, call get_page_text, translate every text (keep HTML tags, {{placeholders}}, [shortcodes], URLs and brand names), then translate_page: it creates a draft copy and never changes the original.',
			'To build a page from a screenshot or sketch (an attached image or a Media Library id): look at it with view_media (size: "large"), list its sections top to bottom, then create a DRAFT page and build it section by section with real elements (describe_element first), using the words from the picture (placeholder text only where it is unreadable) and the site\'s own colours, fonts and presets unless the person asks to match the picture\'s design. Run render_check at the end and say what you could not reproduce.',
			'Brand kit from a logo: view_media to see it and extract_colors for its exact colours; build a palette (primary, secondary, accent, dark text, light background; text on each colour at 4.5:1 contrast or better — lighten or darken a logo colour when needed), choose a heading + body font pair that suits the logo from the fonts describe_theme_settings offers, and SHOW the kit to the person before applying. After they agree, apply it with update_theme_settings (theme colours, typography) and save_preset (buttons), then say that undo_theme_settings reverts it.',
			'Before placing an element, call describe_element and use its exact option ids — for list options, only the inner_options keys.',
			'New pages are drafts unless the person asks you to publish. Theme Settings changes are LIVE immediately; mention that, and that undo_theme_settings can revert them.',
			'Ask before anything destructive: removing content they wrote, replacing a page, or convert_url (which replaces pages and activates a new child theme) — only call convert_url with confirm: true after they explicitly agree in this conversation.',
			'If a request is ambiguous, make sensible choices and say what you chose rather than asking many questions.',
			'Settings → General (site title, tagline, site icon): update_site_identity.',
			'When done, reply in a few short sentences: what you changed, and anything they should check or do next.',
		) ) . self::place_text();
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
				'text' => self::clip( (string) $h['text'], 4000 ),
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
		$post  = get_post( $post_id );
		$title = self::$place['title'] !== '' ? self::$place['title'] : ( $post && $post->post_status !== 'auto-draft' ? get_the_title( $post ) : '' );
		$name  = $title !== '' ? 'the page "' . $title . '"' : 'a new page that has no title yet';
		return implode( "\n", array(
			'You are the UnysonPlus AI Assistant inside the page builder, editing ' . $name . ' (post_id ' . $post_id . ').',
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
		) ) . self::place_text();
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

		$prompt = self::agent_prompt( $mode, $post_id, $tree, $message, $history );

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
	 * The one-shot prompt for a command-line agent (the local agent command, or Claude Code run by the
	 * AI Dev Kit on the editor's computer): instructions, the conversation so far, the request.
	 *
	 * @param string $mode    page | site
	 * @param int    $post_id
	 * @param array  $tree
	 * @param string $message
	 * @param array  $history
	 * @return string
	 */
	private static function agent_prompt( $mode, $post_id, array $tree, $message, array $history ) {
		$prompt = ( $mode === 'site' ? self::site_instructions() : self::instructions( $post_id, $tree ) ) . "\n\nUse only the unysonplus MCP tools.\n";
		if ( $history ) {
			$prompt .= "\nConversation so far:\n";
			foreach ( $history as $h ) {
				$prompt .= ( $h['role'] === 'assistant' ? 'Assistant: ' : 'User: ' ) . $h['text'] . "\n";
			}
		}
		return $prompt . "\nRequest: " . $message . "\n";
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
			'reply'   => $ok ? self::clip( $reply, 4000 ) : ( $expired ? 'The agent did not finish within 10 minutes.' : 'The agent exited without a reply. Check the command on the AI Assistant screen.' ),
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

	/* ------------------------------------------------------------------ *
	 * Backend: local AI in the editor's browser
	 * ------------------------------------------------------------------ */

	/**
	 * Opens a browser-backend session: the same sandbox the local agent gets, keyed by a one-off id the
	 * panel sends to the MCP endpoint as X-UPW-AI-Session, limited to the small-model tool set. Returns
	 * the instructions for the model; the panel fetches the tools with MCP tools/list.
	 *
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_local_start( WP_REST_Request $r ) {
		if ( self::backend() !== 'browser' ) {
			return new WP_Error( 'upw_ai_not_browser', 'The builder assistant is not set to local AI.', array( 'status' => 400 ) );
		}
		self::take_place( $r );
		$mode    = $r->get_param( 'mode' ) === 'site' ? 'site' : 'page';
		$post_id = $mode === 'site' ? 0 : (int) $r->get_param( 'post_id' );
		$tree    = $mode === 'site' ? array() : (array) $r->get_param( 'tree' );
		$session = strtolower( wp_generate_password( 24, false ) );
		if ( $r->get_param( 'agent' ) ) {
			return self::start_browser_agent( $session, $mode, $post_id, $tree, $r );
		}
		self::put_session( $session, array(
			'mode'    => $mode,
			'kind'    => 'browser',
			'tools'   => $mode === 'site' ? self::BROWSER_SITE_TOOLS : self::BROWSER_PAGE_TOOLS,
			'user'    => get_current_user_id(),
			'post_id' => $post_id,
			'tree'    => $tree,
			'steps'   => array(),
			'status'  => 'running',
			'started' => time(),
			'dir'     => '',
			'app_pw'  => '',
		) );
		return rest_ensure_response( array(
			'session' => $session,
			'system'  => $mode === 'site' ? self::browser_site_instructions() : self::instructions( $post_id, $tree ) . "\n\n" . self::browser_rules(),
		) );
	}

	/**
	 * Claude Code on the editor's computer, run by the AI Dev Kit: a session with the panel's usual tool
	 * set (a Claude-class model does not need the small-model limits), a temporary Application Password
	 * the agent authenticates with, and the full prompt. The panel hands both to the kit; finish deletes
	 * the password (and FW_AI_Local::sweep() removes any a closed tab left behind, after 30 minutes).
	 *
	 * @param string          $session
	 * @param string          $mode
	 * @param int             $post_id
	 * @param array           $tree
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	private static function start_browser_agent( $session, $mode, $post_id, array $tree, WP_REST_Request $r ) {
		$message = trim( (string) $r->get_param( 'message' ) );
		if ( $message === '' ) {
			return new WP_Error( 'upw_ai_empty', 'Empty message.', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			return new WP_Error( 'upw_ai_no_app_passwords', 'Application Passwords are turned off on this site, so Claude cannot connect to it. They need HTTPS (or a local development site).', array( 'status' => 400 ) );
		}
		FW_AI_Local::sweep();
		$user = wp_get_current_user();
		$pw   = WP_Application_Passwords::create_new_application_password( $user->ID, array(
			'name' => FW_Extension_AI_Assistant::APP_PASSWORD_NAME . ( $mode === 'site' ? ' — site assistant' : ' — builder panel' ) . ' via the AI Dev Kit (temporary)',
		) );
		if ( is_wp_error( $pw ) ) {
			return $pw;
		}
		self::put_session( $session, array(
			'mode'    => $mode,
			'kind'    => 'browser',
			'agent'   => true,
			'user'    => $user->ID,
			'post_id' => $post_id,
			'tree'    => $tree,
			'steps'   => array(),
			'status'  => 'running',
			'started' => time(),
			'dir'     => '',
			'app_pw'  => $pw[1]['uuid'],
		) );
		return rest_ensure_response( array(
			'session' => $session,
			'mcp'     => array(
				'url'     => FW_AI_MCP::endpoint(),
				'headers' => array(
					'Authorization'    => 'Basic ' . base64_encode( $user->user_login . ':' . $pw[0] ),
					'X-UPW-AI-Session' => $session,
				),
			),
			'prompt'  => self::agent_prompt( $mode, $post_id, $tree, $message, self::clean_history( (array) $r->get_param( 'history' ) ) ),
		) );
	}

	/**
	 * Closes a browser-backend session and returns the same result shape as the other backends (the
	 * render check, the changed tree, the steps). Changes made before an error are still returned, so
	 * the person can keep or undo them.
	 *
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_local_finish( WP_REST_Request $r ) {
		$session = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $r->get_param( 'session' ) ) );
		$data    = self::get_session( $session );
		if ( ! $data || ( $data['kind'] ?? '' ) !== 'browser' || (int) $data['user'] !== get_current_user_id() ) {
			return new WP_Error( 'upw_ai_no_session', 'Unknown or expired session.', array( 'status' => 404 ) );
		}
		$reply = trim( (string) $r->get_param( 'reply' ) );
		$error = trim( (string) $r->get_param( 'error' ) );
		$site  = ( $data['mode'] ?? 'page' ) === 'site';
		$check = null;
		if ( $data['steps'] && ! $site ) {
			FW_AI_Store::sandbox( (int) $data['post_id'], (array) $data['tree'] );
			$check = FW_AI_Check::run( (int) $data['post_id'] );
		}
		if ( $error !== '' && ! $data['steps'] ) {
			$result = array( 'status' => 'error', 'reply' => wp_html_excerpt( $error, 600, '…' ) );
		} else {
			if ( $error !== '' ) {
				$reply = $error;
			}
			$result = array(
				'check'   => $check,
				'status'  => 'done',
				'reply'   => $reply !== '' ? self::clip( $reply, 4000 ) : ( $data['steps'] ? 'Done.' : 'I could not finish that — please try rephrasing, or ask for one smaller change at a time.' ),
				'changed' => (bool) $data['steps'],
				'tree'    => ( $data['steps'] && ! $site ) ? $data['tree'] : null,
				'steps'   => $data['steps'],
			);
		}
		if ( ! empty( $data['app_pw'] ) ) {
			WP_Application_Passwords::delete_application_password( (int) $data['user'], (string) $data['app_pw'] );
		}
		delete_transient( self::SESSION_PREFIX . $session );
		return rest_ensure_response( $result );
	}

	/**
	 * Extra rules for a small local model, appended to the page instructions.
	 *
	 * @return string
	 */
	private static function browser_rules() {
		return implode( "\n", array(
			'You are running as a small local model with a short memory, so:',
			'- Make ONE change at a time: call a tool, read its result, then decide the next step.',
			'- Never stop to announce what you will do next — just call the next tool. Reply in words only when the whole request is done.',
			'- A new section needs its content in the SAME insert_items call: the section with its heading and elements in _items.',
			'- Prefer apply_template (list_templates first) over building a section by hand when a template fits.',
			'- If a tool returns an error, read it, fix exactly what it names and try again — do not repeat the same call.',
			'- Keep text short and real (no lorem ipsum). Finish with render_check, then a one-sentence reply.',
			'',
			self::browser_recipes(),
		) );
	}

	/**
	 * Ready-made, validated section shapes for a small model to copy instead of exploring schemas
	 * (every one passes render_check as written). Change the text, the number of items and the emoji;
	 * keep the structure and the option names.
	 *
	 * @return string
	 */
	private static function browser_recipes() {
		$h       = function ( $title, $sub = '' ) {
			$a = array( 'title' => $title, 'heading' => 'h2' );
			if ( $sub !== '' ) {
				$a['subtitle'] = $sub;
			}
			return array( 'type' => 'simple', 'shortcode' => 'special_heading', 'atts' => $a );
		};
		$section = function ( array $items ) {
			return array( 'type' => 'flexbox', 'atts' => array( 'html_tag' => 'section', 'display' => 'block' ), '_items' => $items );
		};
		$card    = function ( $emoji, $title ) {
			return array( 'type' => 'simple', 'shortcode' => 'icon_box', 'atts' => array( 'icon' => array( 'type' => 'emoji', 'char' => $emoji ), 'title' => $title, 'content' => '<p>One short sentence.</p>' ) );
		};
		$recipes = array(
			'FAQ'                       => $section( array(
				$h( 'Frequently asked questions' ),
				array( 'type' => 'simple', 'shortcode' => 'accordion', 'atts' => array( 'tabs' => array(
					array( 'tab_title' => 'Question one?', 'tab_content' => '<p>Answer one.</p>' ),
					array( 'tab_title' => 'Question two?', 'tab_content' => '<p>Answer two.</p>' ),
				) ) ),
			) ),
			'Feature cards (3 columns)' => $section( array(
				$h( 'Why choose us', 'A short line under the heading.' ),
				array( 'type' => 'flexbox', 'atts' => array( 'display' => 'grid', 'grid_columns' => '3' ), '_items' => array( $card( '🎈', 'Feature one' ), $card( '🎉', 'Feature two' ), $card( '✨', 'Feature three' ) ) ),
			) ),
			'Call to action'            => $section( array(
				$h( 'Ready to get started?', 'One persuasive line.' ),
				array( 'type' => 'simple', 'shortcode' => 'button', 'atts' => array( 'label' => 'Get in touch', 'link' => '/contact/' ) ),
			) ),
			'Text section'              => $section( array(
				$h( 'Our story' ),
				array( 'type' => 'simple', 'shortcode' => 'text_block', 'atts' => array( 'text' => '<p>Two or three short paragraphs.</p>' ) ),
			) ),
		);
		$out = array(
			'RECIPES — to add a section at the end of the page, call insert_items with exactly two arguments: {"post_id": <id>, "items": [ <one recipe> ]}. Copy a recipe exactly, changing only the text, the emoji and how many questions / cards there are. You do not need describe_element for these.',
		);
		foreach ( $recipes as $name => $item ) {
			$out[] = $name . ': ' . wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return implode( "\n", $out );
	}

	/**
	 * The site-wide instructions for the browser backend (its smaller tool set).
	 *
	 * @return string
	 */
	private static function browser_site_instructions() {
		return implode( "\n", array(
			'You are the UnysonPlus AI Assistant for the WordPress site "' . wp_strip_all_tags( get_bloginfo( 'name' ) ) . '" (' . home_url( '/' ) . '), running as a small local model.',
			'Your tools can: read the site (site_info), create draft pages (create_page) and fill them (list_templates + apply_template, insert_items with elements from describe_element), and read or change Theme Settings (describe_theme_settings, update_theme_settings; undo_theme_settings reverts).',
			'Work ONE step at a time: call a tool, read its result, then decide the next step. If a tool returns an error, fix exactly what it names.',
			'Never stop to announce what you will do next — just call the next tool. Reply in words only when the whole request is done.',
			'New pages: create_page with a title, then insert_items section by section (the recipes below).',
			'New pages are drafts. Theme Settings changes are live immediately — say so.',
			'Ask before removing content the person wrote.',
			'Site title, tagline and site icon: update_site_identity.',
			'When done, reply in one to three short sentences saying what you changed.',
			'',
			self::browser_recipes(),
		) ) . self::place_text();
	}
}
