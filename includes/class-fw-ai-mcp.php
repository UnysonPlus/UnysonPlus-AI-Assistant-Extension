<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A minimal, stateless MCP server (Streamable HTTP transport, JSON responses) that serves
 * the `unysonplus/*` abilities as MCP tools.
 *
 *   POST /wp-json/unysonplus-ai/v1/mcp     JSON-RPC 2.0 (single message or batch)
 *
 * Methods: initialize, ping, tools/list, tools/call, and the notifications a client sends
 * (answered with 202 and no body). Authentication is WordPress's own REST auth — an agent
 * sends an Application Password as HTTP Basic auth, and every tool runs as that user, so the
 * abilities' capability checks apply unchanged.
 *
 * Access is controlled by the `upw_ai_mcp_mode` option: off (default) | read | write.
 * In `read` mode only abilities annotated readonly are listed and callable.
 */
class FW_AI_MCP {

	const REST_NS     = 'unysonplus-ai/v1';
	const OPTION_MODE = 'upw_ai_mcp_mode';

	/** @var bool True while a tools/call runs (a tool may then return images as MCP content). */
	private static $calling = false;

	/**
	 * @return bool Whether the current ability call came through this MCP server.
	 */
	public static function is_calling() {
		return self::$calling;
	}
	const MODES       = array( 'off', 'read', 'write' );

	/** Protocol revisions this server speaks, newest first. */
	const PROTOCOLS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/** @var array|null The builder-panel session of the current request, if any. */
	private static $session = null;

	/**
	 * A valid builder-panel session named by the X-UPW-AI-Session header: running, and started
	 * by the user this request authenticated as.
	 *
	 * @param WP_REST_Request $request
	 * @return array|null { id, data }
	 */
	private static function session( WP_REST_Request $request ) {
		$id = (string) $request->get_header( 'x_upw_ai_session' );
		if ( $id === '' || ! is_user_logged_in() || ! class_exists( 'FW_AI_Panel' ) ) {
			return null;
		}
		$data = FW_AI_Panel::get_session( $id );
		if ( ! $data || $data['status'] !== 'running' || (int) $data['user'] !== get_current_user_id() ) {
			return null;
		}
		return array( 'id' => preg_replace( '/[^a-z0-9]/', '', strtolower( $id ) ), 'data' => $data );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_empty_202' ), 10, 4 );
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'allow_local_app_passwords' ) );
	}

	/**
	 * @return string off|read|write
	 */
	public static function mode() {
		$m = (string) get_option( self::OPTION_MODE, 'off' );
		return in_array( $m, self::MODES, true ) ? $m : 'off';
	}

	/**
	 * @return string The endpoint URL agents connect to.
	 */
	public static function endpoint() {
		return rest_url( self::REST_NS . '/mcp' );
	}

	/**
	 * Application Passwords are only offered over HTTPS or on a `local` environment. A
	 * development site served over plain HTTP on a loopback / reserved-for-testing host
	 * (localhost, 127.0.0.1, *.local, *.test, *.localhost) is local in practice, so allow
	 * them there too. Filter `fw_ai_assistant_allow_local_app_passwords` to opt out.
	 *
	 * @param bool $available
	 * @return bool
	 */
	public static function allow_local_app_passwords( $available ) {
		if ( $available ) {
			return true;
		}
		/** Filters whether the AI Assistant enables Application Passwords on a plain-HTTP local development host. */
		return (bool) apply_filters( 'fw_ai_assistant_allow_local_app_passwords', self::is_local_host() );
	}

	/**
	 * @return bool
	 */
	public static function is_local_host() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true )
			|| (bool) preg_match( '/\.(local|test|localhost)$/', $host );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NS, '/mcp', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			array(
				// No server-initiated stream: the spec lets a server answer GET with 405.
				'methods'             => 'GET',
				'callback'            => function () {
					return new WP_REST_Response( array( 'message' => 'This MCP server does not offer an SSE stream; POST JSON-RPC messages.' ), 405 );
				},
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission( $request = null ) {
		// A builder-panel session (local agent backend) is allowed whatever the MCP mode: the
		// person started it from the builder, and it can only touch that session's sandbox.
		if ( $request instanceof WP_REST_Request && self::session( $request ) ) {
			return true;
		}
		// Signed out comes first: a 401 starts an app's web sign-in, whose consent screen can turn access on.
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'upw_ai_mcp_auth', 'Sign in: use OAuth (discovery at ' . FW_AI_OAuth::resource_metadata_url() . ') or an Application Password (HTTP Basic auth).', array( 'status' => 401 ) );
		}
		if ( self::mode() === 'off' ) {
			return new WP_Error( 'upw_ai_mcp_off', 'Access for outside AI programs is turned off. Enable it under Unyson+ → AI Assistant → Advanced → Outside AI programs.', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'upw_ai_mcp_forbidden', 'This user cannot edit content.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( self::error( null, -32700, 'Parse error' ), 400 );
		}

		$session = self::session( $request );
		$site    = $session && ( $session['data']['mode'] ?? 'page' ) === 'site';
		if ( $session ) {
			self::$session = $session;
			if ( ! $site ) {
				// Builder panel: the agent edits a sandbox of the tree the person has open.
				FW_AI_Store::sandbox( $session['data']['post_id'], (array) $session['data']['tree'] );
			}
			FW_AI_Store::take_log();
			FW_AI_Panel::take_activity();
		}

		$batch    = array_keys( $body ) === range( 0, count( $body ) - 1 ) && $body;
		$messages = $batch ? $body : array( $body );
		$replies  = array();
		foreach ( $messages as $msg ) {
			$reply = self::dispatch( is_array( $msg ) ? $msg : array() );
			if ( $reply !== null ) {
				$replies[] = $reply;
			}
		}

		if ( $session ) {
			// Persist the sandboxed tree + the writes made, for the panel's status poll.
			$data = FW_AI_Panel::get_session( $session['id'] );
			if ( $site ) {
				$data['steps'] = array_merge( (array) $data['steps'], FW_AI_Panel::take_activity() );
			} else {
				$data['tree']  = FW_AI_Store::get_tree( $session['data']['post_id'] );
				$data['steps'] = array_merge( (array) $data['steps'], FW_AI_Store::take_log() );
			}
			FW_AI_Panel::put_session( $session['id'], $data );
		}

		if ( ! $replies ) {
			return new WP_REST_Response( null, 202 ); // Only notifications / responses were sent.
		}
		$response = new WP_REST_Response( $batch ? $replies : $replies[0], 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Send a 202 with an empty body (the REST server would otherwise print "null").
	 *
	 * @param bool             $served
	 * @param WP_HTTP_Response $result
	 * @param WP_REST_Request  $request
	 * @param WP_REST_Server   $server
	 * @return bool
	 */
	public static function serve_empty_202( $served, $result, $request, $server ) {
		if ( ! $served && $result instanceof WP_HTTP_Response && $result->get_status() === 202 && $result->get_data() === null
			&& strpos( (string) $request->get_route(), '/' . self::REST_NS . '/mcp' ) === 0 ) {
			return true;
		}
		return $served;
	}

	/**
	 * @param array $msg One JSON-RPC message.
	 * @return array|null Reply, or null for a notification / response.
	 */
	private static function dispatch( array $msg ) {
		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;
		$method = isset( $msg['method'] ) && is_string( $msg['method'] ) ? $msg['method'] : '';
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( $method === '' ) {
			return $has_id && ( isset( $msg['result'] ) || isset( $msg['error'] ) ) ? null : self::error( $id, -32600, 'Invalid Request' );
		}
		if ( ! $has_id ) {
			return null; // notifications/initialized, notifications/cancelled, …
		}

		switch ( $method ) {
			case 'initialize':
				return self::ok( $id, self::initialize( $params ) );
			case 'ping':
				return self::ok( $id, new stdClass() );
			case 'tools/list':
				return self::ok( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				return self::call( $id, $params );
			case 'resources/list':
				return self::ok( $id, array( 'resources' => array() ) );
			case 'prompts/list':
				return self::ok( $id, array( 'prompts' => array() ) );
		}
		return self::error( $id, -32601, "Method not found: $method" );
	}

	/**
	 * @param array $params
	 * @return array
	 */
	private static function initialize( array $params ) {
		$asked   = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version = in_array( $asked, self::PROTOCOLS, true ) ? $asked : self::PROTOCOLS[0];
		$ext     = fw_ext( 'ai-assistant' );

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => array(
				'name'    => 'unysonplus',
				'title'   => 'UnysonPlus AI Assistant (Beta) — ' . get_bloginfo( 'name' ),
				'version' => $ext ? $ext->manifest->get_version() : '1.0.0',
			),
			'instructions'    => implode( "\n", array(
				'You are connected to a WordPress site built with the UnysonPlus page builder.',
				'Start with site_info. Before placing an element call describe_element for it: unknown option ids are rejected.',
				'Pages are trees of layout items (flexbox / section / column) holding elements (type "simple" + shortcode). Use the `path` values from get_page to address items.',
				'New pages are drafts unless the user asks otherwise. Every write saves a revision; undo reverts the last one.',
				'After building, call render_check and fix every error and warning it reports before telling the user you are done.',
				'Building a whole site, follow this order: (1) colours (theme_colors presets by name), (2) typography (heading font, body, h1–h6 scale), (3) container width (general_layout.layout_container_width), (4) button / box / section presets (save_preset), (5) header and footer, with the navigation from menus_create + menus_assign, (6) THEN pages — create_page, apply_template or insert_items section by section with real elements, forms with forms_add, (7) render_check every page, link every page in the menu, set SEO titles / descriptions when the SEO tools exist. Native options and presets before any custom CSS. Theme Settings changes are live immediately (undo_theme_settings reverts them); other extension changes revert with undo_change.',
				'To reproduce an existing website, use convert_url — only after the user explicitly agrees, because it replaces pages and activates a new child theme.',
				'To see how a page still differs from a source or reference site, use visual_check (source_url + post_id): it lists, section by section, what is missing, moved or restyled. Fix those and run it again.',
				'To change the same text in many places (a company name, phone number, price), use replace_text: preview first, show the person what will change, and apply the plan only after they agree.',
				'For images: list_media finds them (missing_alt: true for images without alt text) and shows where each is used; look at them with view_media before describing them, then save alt text in batches with update_media. To use a library image in an element, set its image value to { attachment_id, url, alt }.',
				'To translate a page, call get_page_text, translate every text (keep HTML tags, {{placeholders}}, [shortcodes], URLs and brand names), then translate_page: it creates a draft copy and never changes the original.',
				'To build a page from a screenshot or sketch (an attached image or a Media Library id): look at it with view_media (size: "large"), list its sections top to bottom, then create a DRAFT page and build it section by section with real elements (describe_element first), using the words from the picture (placeholder text only where it is unreadable) and the site\'s own colours, fonts and presets unless the person asks to match the picture\'s design. Run render_check at the end and say what you could not reproduce.',
				'Brand kit from a logo: view_media to see it and extract_colors for its exact colours; build a palette (primary, secondary, accent, dark text, light background; text on each colour at 4.5:1 contrast or better — lighten or darken a logo colour when needed), choose a heading + body font pair that suits the logo from the fonts describe_theme_settings offers, and SHOW the kit to the person before applying. After they agree, apply it with update_theme_settings (theme colours, typography) and save_preset (buttons), then say that undo_theme_settings reverts it.',
				'Style buttons and cards with Theme Settings presets (list_presets) rather than per-element colors.',
				self::mode() === 'read' || FW_AI_OAuth::read_only() ? 'This connection is READ-ONLY: write tools are not available.' : '',
			) ),
		);
	}

	/**
	 * The abilities this connection may use, keyed by MCP tool name.
	 *
	 * @return WP_Ability[]
	 */
	private static function abilities() {
		$read_only = ! self::$session && ( self::mode() === 'read' || FW_AI_OAuth::read_only() );
		$out       = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( strpos( $name, 'unysonplus/' ) !== 0 ) {
				continue;
			}
			$slug = substr( $name, strlen( 'unysonplus/' ) );
			if ( self::$session && ! empty( self::$session['data']['tools'] ) ) {
				if ( ! in_array( $slug, (array) self::$session['data']['tools'], true ) ) {
					continue; // A session with its own tool list (the browser / local-model backend).
				}
			} elseif ( self::$session && ( self::$session['data']['mode'] ?? 'page' ) !== 'site'
				&& ! in_array( $slug, FW_AI_Panel::page_tools(), true ) ) {
				continue; // A builder-panel session gets the panel's tool set; a site session gets them all.
			}
			$ann = (array) $ability->get_meta_item( 'annotations', array() );
			if ( $read_only && empty( $ann['readonly'] ) ) {
				continue;
			}
			$out[ str_replace( '-', '_', substr( $name, strlen( 'unysonplus/' ) ) ) ] = $ability;
		}
		return $out;
	}

	/**
	 * @return array MCP tool descriptors.
	 */
	private static function tools() {
		$tools = array();
		foreach ( self::abilities() as $tool => $ability ) {
			$schema = $ability->get_input_schema();
			if ( ! $schema ) {
				$schema = array( 'type' => 'object', 'properties' => new stdClass() );
			} else {
				unset( $schema['default'] );
				if ( empty( $schema['properties'] ) ) {
					$schema['properties'] = new stdClass();
				}
			}
			$ann     = (array) $ability->get_meta_item( 'annotations', array() );
			$tools[] = array(
				'name'        => $tool,
				'title'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'inputSchema' => $schema,
				'annotations' => array(
					'title'           => $ability->get_label(),
					'readOnlyHint'    => ! empty( $ann['readonly'] ),
					'destructiveHint' => ! empty( $ann['destructive'] ),
					'idempotentHint'  => ! empty( $ann['idempotent'] ),
					'openWorldHint'   => false,
				),
			);
		}
		return $tools;
	}

	/**
	 * @param mixed $id
	 * @param array $params
	 * @return array
	 */
	private static function call( $id, array $params ) {
		$tool      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$abilities = self::abilities();
		if ( ! isset( $abilities[ $tool ] ) ) {
			return self::error( $id, -32602, "Unknown tool: $tool" );
		}
		$ability = $abilities[ $tool ];
		$args    = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		self::$calling = true;
		$result        = $ability->execute( $ability->get_input_schema() ? $args : null );
		self::$calling = false;

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$text = $result->get_error_message();
			if ( is_array( $data ) && ! empty( $data['errors'] ) ) {
				$text .= "\n- " . implode( "\n- ", (array) $data['errors'] );
			}
			return self::ok( $id, array(
				'content' => array( array( 'type' => 'text', 'text' => $text ) ),
				'isError' => true,
			) );
		}

		// A tool may return pictures for the model to look at (view_media): `_images` becomes MCP image
		// content after the JSON text, and is kept out of the text and structuredContent.
		$images = array();
		if ( is_array( $result ) && isset( $result['_images'] ) ) {
			$images = (array) $result['_images'];
			unset( $result['_images'] );
		}
		$out = array(
			'content' => array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
			'isError' => false,
		);
		foreach ( $images as $img ) {
			if ( is_array( $img ) && ! empty( $img['data'] ) ) {
				$out['content'][] = array( 'type' => 'image', 'data' => (string) $img['data'], 'mimeType' => (string) ( $img['mime'] ?? 'image/jpeg' ) );
			}
		}
		if ( is_array( $result ) && $result && array_keys( $result ) !== range( 0, count( $result ) - 1 ) ) {
			$out['structuredContent'] = $result;
		}
		return self::ok( $id, $out );
	}

	/**
	 * @param mixed $id
	 * @param mixed $result
	 * @return array
	 */
	private static function ok( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	/**
	 * @param mixed  $id
	 * @param int    $code
	 * @param string $message
	 * @return array
	 */
	private static function error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}
}
