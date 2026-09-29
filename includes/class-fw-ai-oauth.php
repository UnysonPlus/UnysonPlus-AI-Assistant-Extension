<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * OAuth 2.1 sign-in for the MCP server, so AI apps that connect to remote MCP servers only through a
 * browser sign-in (web connectors) can connect with "Sign in to your site" instead of a pasted password.
 *
 * What the MCP authorization spec asks of a server, all served by this class:
 *   - Protected Resource Metadata (RFC 9728): REST  …/oauth/protected-resource, and
 *     <site>/.well-known/oauth-protected-resource[/…]; a 401 from the MCP endpoint points at it through
 *     `WWW-Authenticate: Bearer resource_metadata="…"`.
 *   - Authorization Server Metadata (RFC 8414): <site>/.well-known/oauth-authorization-server and
 *     <site>/.well-known/openid-configuration (the second is the form a client reaches on a site that
 *     lives in a sub-folder), plus REST …/oauth/authorization-server.
 *   - Dynamic Client Registration (RFC 7591): POST …/oauth/register. Public clients only (no secret):
 *     every client must use PKCE.
 *   - Authorization endpoint: a consent screen inside wp-admin (admin.php?page=fw-ai-authorize), so
 *     WordPress's own login protects it; the person chooses Read only / Read and write.
 *   - Token endpoint: POST …/oauth/token — authorization_code (PKCE S256, single-use codes that live
 *     10 minutes) and refresh_token (rotated on every use).
 *   - Revocation (RFC 7009): POST …/oauth/revoke; and a per-app Revoke on the settings screen.
 *
 * Tokens are random, stored only as SHA-256 hashes (option upw_ai_oauth_tokens), bound to the user who
 * allowed them, and accepted only on this extension's REST routes. Access tokens last an hour, refresh
 * tokens 30 days. A token's scope (mcp:read / mcp:write) narrows what it can do; the site's own
 * "Outside AI programs" switch still applies on top.
 */
class FW_AI_OAuth {

	const OPTION_CLIENTS = 'upw_ai_oauth_clients';
	const OPTION_TOKENS  = 'upw_ai_oauth_tokens';
	const CODE_PREFIX    = 'upw_ai_oac_';
	const AUTH_PAGE      = 'fw-ai-authorize';
	const ACCESS_TTL     = HOUR_IN_SECONDS;
	const REFRESH_TTL    = 30 * DAY_IN_SECONDS;
	const CODE_TTL       = 600;
	const MAX_CLIENTS    = 200;
	const SCOPES         = array( 'mcp:read', 'mcp:write' );

	/** @var array|null The token row that authenticated this request. */
	private static $token = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'bearer_user' ), 30 );
		add_action( 'parse_request', array( __CLASS__, 'well_known' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'challenge_header' ), 10, 3 );
		add_filter( 'rest_exposed_cors_headers', function ( $h ) {
			$h[] = 'WWW-Authenticate';
			return $h;
		} );
	}

	/* ------------------------------------------------------------------ *
	 * URLs and metadata
	 * ------------------------------------------------------------------ */

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function authorize_url() {
		return admin_url( 'admin.php?page=' . self::AUTH_PAGE );
	}

	private static function rest( $path ) {
		return rest_url( FW_AI_MCP::REST_NS . '/oauth/' . $path );
	}

	public static function resource_metadata_url() {
		return self::rest( 'protected-resource' );
	}

	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => self::rest( 'token' ),
			'registration_endpoint'                 => self::rest( 'register' ),
			'revocation_endpoint'                   => self::rest( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => self::SCOPES,
			'service_documentation'                 => 'https://docs.unysonplus.com/extensions/ai-assistant/mcp-server',
		);
	}

	public static function resource_metadata() {
		return array(
			'resource'                 => FW_AI_MCP::endpoint(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::SCOPES,
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => wp_strip_all_tags( html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ) ),
		);
	}

	/**
	 * `parse_request`: answer the /.well-known discovery documents (they must live at the site root, so
	 * they cannot be REST routes). Accepts the RFC forms with a path suffix and the sub-folder form.
	 *
	 * @param WP $wp
	 */
	public static function well_known( $wp ) {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( strpos( $path, '/.well-known/' ) === false ) {
			return;
		}
		if ( ! preg_match( '#^(?:' . preg_quote( $home, '#' ) . ')?/\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)(?:/.*)?$#', $path, $m ) ) {
			return;
		}
		$data = $m[1] === 'oauth-protected-resource' ? self::resource_metadata() : self::server_metadata();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: max-age=300' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * A 401 from the MCP endpoint tells the client where to sign in (RFC 9728 §5.1).
	 *
	 * @param WP_HTTP_Response $result
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return WP_HTTP_Response
	 */
	public static function challenge_header( $result, $server, $request ) {
		if ( $result instanceof WP_HTTP_Response && $result->get_status() === 401 && $request instanceof WP_REST_Request
			&& $request->get_route() === '/' . FW_AI_MCP::REST_NS . '/mcp' ) {
			$result->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . self::resource_metadata_url() . '", scope="mcp:write"' );
		}
		return $result;
	}

	/* ------------------------------------------------------------------ *
	 * REST: metadata, registration, token, revocation
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		$ns   = FW_AI_MCP::REST_NS;
		$open = '__return_true';
		register_rest_route( $ns, '/oauth/protected-resource', array( 'methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
			return rest_ensure_response( self::resource_metadata() );
		} ) );
		register_rest_route( $ns, '/oauth/authorization-server', array( 'methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
			return rest_ensure_response( self::server_metadata() );
		} ) );
		register_rest_route( $ns, '/oauth/register', array( 'methods' => 'POST', 'permission_callback' => $open, 'callback' => array( __CLASS__, 'rest_register' ) ) );
		register_rest_route( $ns, '/oauth/token', array( 'methods' => 'POST', 'permission_callback' => $open, 'callback' => array( __CLASS__, 'rest_token' ) ) );
		register_rest_route( $ns, '/oauth/revoke', array( 'methods' => 'POST', 'permission_callback' => $open, 'callback' => array( __CLASS__, 'rest_revoke' ) ) );
	}

	private static function oauth_error( $error, $description, $status = 400 ) {
		$r = new WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/**
	 * A redirect URI a client may register: https anywhere, or http on the loopback host (desktop apps).
	 *
	 * @param string $uri
	 * @return bool
	 */
	private static function allowed_redirect( $uri ) {
		$p = wp_parse_url( (string) $uri );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) || strlen( (string) $uri ) > 500 ) {
			return false;
		}
		if ( $p['scheme'] === 'https' ) {
			return true;
		}
		return $p['scheme'] === 'http' && in_array( strtolower( $p['host'] ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response
	 */
	public static function rest_register( WP_REST_Request $r ) {
		$body = $r->get_json_params();
		$body = is_array( $body ) ? $body : $r->get_params();
		$uris = array_values( array_filter( (array) ( $body['redirect_uris'] ?? array() ), 'is_string' ) );
		if ( ! $uris || count( $uris ) > 10 ) {
			return self::oauth_error( 'invalid_redirect_uri', 'Register one to ten redirect_uris.' );
		}
		foreach ( $uris as $u ) {
			if ( ! self::allowed_redirect( $u ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'Redirect URIs must use https (or http on localhost): ' . $u );
			}
		}
		$method = (string) ( $body['token_endpoint_auth_method'] ?? 'none' );
		if ( $method !== 'none' ) {
			return self::oauth_error( 'invalid_client_metadata', 'Only public clients are supported (token_endpoint_auth_method "none", with PKCE).' );
		}
		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			// Drop registrations that never led to a sign-in, oldest first.
			uasort( $clients, function ( $a, $b ) {
				return (int) $a['created'] <=> (int) $b['created'];
			} );
			foreach ( $clients as $id => $c ) {
				if ( empty( $c['used'] ) ) {
					unset( $clients[ $id ] );
				}
				if ( count( $clients ) < self::MAX_CLIENTS ) {
					break;
				}
			}
			if ( count( $clients ) >= self::MAX_CLIENTS ) {
				return self::oauth_error( 'temporarily_unavailable', 'Too many registered apps.', 503 );
			}
		}
		$id   = 'upw_' . wp_generate_password( 24, false );
		$name = trim( wp_strip_all_tags( (string) ( $body['client_name'] ?? '' ) ) );
		$clients[ $id ] = array(
			'name'          => $name !== '' ? mb_substr( $name, 0, 100 ) : __( 'An AI app', 'fw' ),
			'redirect_uris' => $uris,
			'created'       => time(),
			'used'          => 0,
		);
		update_option( self::OPTION_CLIENTS, $clients, false );
		$res = new WP_REST_Response( array(
			'client_id'                  => $id,
			'client_id_issued_at'        => time(),
			'client_name'                => $clients[ $id ]['name'],
			'redirect_uris'              => $uris,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
		), 201 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response
	 */
	public static function rest_token( WP_REST_Request $r ) {
		$grant  = (string) $r->get_param( 'grant_type' );
		$client = (string) $r->get_param( 'client_id' );
		if ( $grant === 'authorization_code' ) {
			$code = (string) $r->get_param( 'code' );
			$key  = self::CODE_PREFIX . hash( 'sha256', $code );
			$row  = $code !== '' ? get_transient( $key ) : false;
			delete_transient( $key ); // single use, whatever happens next
			if ( ! is_array( $row ) ) {
				return self::oauth_error( 'invalid_grant', 'The authorization code is invalid, used or expired.' );
			}
			if ( $client !== '' && $client !== $row['client'] ) {
				return self::oauth_error( 'invalid_grant', 'The code was issued to a different client.' );
			}
			if ( (string) $r->get_param( 'redirect_uri' ) !== '' && (string) $r->get_param( 'redirect_uri' ) !== $row['redirect_uri'] ) {
				return self::oauth_error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
			}
			$verifier = (string) $r->get_param( 'code_verifier' );
			if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || ! hash_equals( $row['challenge'], self::s256( $verifier ) ) ) {
				return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			return self::issue( (int) $row['user'], (string) $row['client'], (string) $row['scope'] );
		}
		if ( $grant === 'refresh_token' ) {
			$tokens = self::tokens();
			$hash   = hash( 'sha256', (string) $r->get_param( 'refresh_token' ) );
			$row    = $tokens[ $hash ] ?? null;
			if ( ! $row || $row['type'] !== 'refresh' || (int) $row['exp'] < time() || ( $client !== '' && $client !== $row['client'] ) ) {
				return self::oauth_error( 'invalid_grant', 'The refresh token is invalid or expired.' );
			}
			unset( $tokens[ $hash ] ); // rotation: a refresh token works once
			update_option( self::OPTION_TOKENS, $tokens, false );
			$user = get_userdata( (int) $row['user'] );
			if ( ! $user ) {
				return self::oauth_error( 'invalid_grant', 'The account no longer exists.' );
			}
			$scope = (string) $r->get_param( 'scope' ) === 'mcp:read' ? 'mcp:read' : (string) $row['scope'];
			return self::issue( (int) $row['user'], (string) $row['client'], $scope );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	/**
	 * @param WP_REST_Request $r
	 * @return WP_REST_Response
	 */
	public static function rest_revoke( WP_REST_Request $r ) {
		$tokens = self::tokens();
		$hash   = hash( 'sha256', (string) $r->get_param( 'token' ) );
		if ( isset( $tokens[ $hash ] ) ) {
			unset( $tokens[ $hash ] );
			update_option( self::OPTION_TOKENS, $tokens, false );
		}
		return new WP_REST_Response( null, 200 ); // RFC 7009: 200 whether or not the token existed
	}

	private static function s256( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Issue an access + refresh token pair.
	 */
	private static function issue( $user_id, $client, $scope ) {
		$access  = 'upwat_' . wp_generate_password( 40, false );
		$refresh = 'upwrt_' . wp_generate_password( 48, false );
		$tokens  = self::tokens();
		$now     = time();
		$tokens[ hash( 'sha256', $access ) ]  = array( 'type' => 'access', 'user' => $user_id, 'client' => $client, 'scope' => $scope, 'exp' => $now + self::ACCESS_TTL, 'created' => $now );
		$tokens[ hash( 'sha256', $refresh ) ] = array( 'type' => 'refresh', 'user' => $user_id, 'client' => $client, 'scope' => $scope, 'exp' => $now + self::REFRESH_TTL, 'created' => $now );
		update_option( self::OPTION_TOKENS, $tokens, false );
		$clients = self::clients();
		if ( isset( $clients[ $client ] ) ) {
			$clients[ $client ]['used'] = $now;
			update_option( self::OPTION_CLIENTS, $clients, false );
		}
		$res = new WP_REST_Response( array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		), 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	public static function clients() {
		$c = get_option( self::OPTION_CLIENTS, array() );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * @return array hash → row, expired rows dropped.
	 */
	private static function tokens() {
		$t   = get_option( self::OPTION_TOKENS, array() );
		$t   = is_array( $t ) ? $t : array();
		$now = time();
		foreach ( $t as $h => $row ) {
			if ( ! is_array( $row ) || (int) ( $row['exp'] ?? 0 ) < $now ) {
				unset( $t[ $h ] );
			}
		}
		return $t;
	}

	/**
	 * The apps a user has signed in, for the settings screen.
	 *
	 * @param int $user_id
	 * @return array[] { client, name, scope, since, last }
	 */
	public static function apps_for_user( $user_id ) {
		$clients = self::clients();
		$out     = array();
		foreach ( self::tokens() as $row ) {
			if ( (int) $row['user'] !== (int) $user_id ) {
				continue;
			}
			$c = (string) $row['client'];
			if ( ! isset( $out[ $c ] ) ) {
				$out[ $c ] = array( 'client' => $c, 'name' => $clients[ $c ]['name'] ?? __( 'An AI app', 'fw' ), 'scope' => $row['scope'], 'since' => (int) $row['created'], 'last' => (int) ( $clients[ $c ]['last'] ?? 0 ) );
			}
			$out[ $c ]['since'] = min( $out[ $c ]['since'], (int) $row['created'] );
			if ( $row['scope'] === 'mcp:write' ) {
				$out[ $c ]['scope'] = 'mcp:write';
			}
		}
		return array_values( $out );
	}

	/**
	 * Sign an app out for one user (every token it holds for them).
	 *
	 * @param int    $user_id
	 * @param string $client
	 */
	public static function revoke_app( $user_id, $client ) {
		$t = self::tokens();
		foreach ( $t as $h => $row ) {
			if ( (int) $row['user'] === (int) $user_id && (string) $row['client'] === (string) $client ) {
				unset( $t[ $h ] );
			}
		}
		update_option( self::OPTION_TOKENS, $t, false );
	}

	/* ------------------------------------------------------------------ *
	 * Bearer authentication (this extension's REST routes only)
	 * ------------------------------------------------------------------ */

	/**
	 * `determine_current_user`: a valid Bearer access token signs the request in as its user — only on
	 * this extension's REST namespace, never elsewhere.
	 *
	 * @param int|false $user_id
	 * @return int|false
	 */
	public static function bearer_user( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( strpos( $uri, FW_AI_MCP::REST_NS ) === false ) {
			return $user_id;
		}
		$auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		if ( $auth === '' && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $k => $v ) {
				if ( strtolower( (string) $k ) === 'authorization' ) {
					$auth = (string) $v;
				}
			}
		}
		if ( stripos( $auth, 'Bearer ' ) !== 0 ) {
			return $user_id;
		}
		$tokens = self::tokens();
		$row    = $tokens[ hash( 'sha256', trim( substr( $auth, 7 ) ) ) ] ?? null;
		if ( ! $row || $row['type'] !== 'access' || ! get_userdata( (int) $row['user'] ) ) {
			return $user_id;
		}
		self::$token = $row;
		// Remember when the app was last used (at most once a minute).
		$clients = self::clients();
		$c       = (string) $row['client'];
		if ( isset( $clients[ $c ] ) && time() - (int) ( $clients[ $c ]['last'] ?? 0 ) > 60 ) {
			$clients[ $c ]['last'] = time();
			update_option( self::OPTION_CLIENTS, $clients, false );
		}
		return (int) $row['user'];
	}

	/**
	 * @return bool Whether this request came with a read-only token.
	 */
	public static function read_only() {
		return self::$token !== null && self::$token['scope'] === 'mcp:read';
	}

	/* ------------------------------------------------------------------ *
	 * The consent screen (authorization endpoint)
	 * ------------------------------------------------------------------ */

	public static function menu() {
		// A page with no menu entry: reachable by URL, behind WordPress's own login.
		$hook = add_submenu_page( 'fw-ai-hidden', __( 'Allow an AI app', 'fw' ), '', 'edit_posts', self::AUTH_PAGE, array( __CLASS__, 'render_consent' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_consent' ) );
		}
	}

	/**
	 * Validate the authorization request. Errors about the client or redirect URI are shown, never
	 * redirected (the redirect target is not trusted yet).
	 *
	 * @param array $q
	 * @return array { ok, error?, redirect_error?, client?, ... }
	 */
	private static function check_request( array $q ) {
		$clients = self::clients();
		$id      = (string) ( $q['client_id'] ?? '' );
		if ( class_exists( 'FW_AI_Access' ) && is_user_logged_in() && ! FW_AI_Access::can_use() ) {
			return array( 'ok' => false, 'error' => __( 'Your role is not allowed to use the AI Assistant on this site, so you cannot connect an app to it. Ask an administrator.', 'fw' ) );
		}
		if ( ! isset( $clients[ $id ] ) ) {
			return array( 'ok' => false, 'error' => __( 'This app is not registered with this site. Start the connection again from the app.', 'fw' ) );
		}
		$client   = $clients[ $id ];
		$redirect = (string) ( $q['redirect_uri'] ?? '' );
		if ( $redirect === '' && count( $client['redirect_uris'] ) === 1 ) {
			$redirect = $client['redirect_uris'][0];
		}
		if ( ! in_array( $redirect, $client['redirect_uris'], true ) ) {
			return array( 'ok' => false, 'error' => __( 'The app asked to return to an address it did not register. For your safety the request was stopped.', 'fw' ) );
		}
		$base = array( 'client_id' => $id, 'client' => $client, 'redirect_uri' => $redirect, 'state' => (string) ( $q['state'] ?? '' ) );
		if ( ( $q['response_type'] ?? '' ) !== 'code' ) {
			return $base + array( 'ok' => false, 'redirect_error' => 'unsupported_response_type' );
		}
		if ( empty( $q['code_challenge'] ) || ( $q['code_challenge_method'] ?? '' ) !== 'S256' || ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', (string) $q['code_challenge'] ) ) {
			return $base + array( 'ok' => false, 'redirect_error' => 'invalid_request' );
		}
		$scope = in_array( 'mcp:write', preg_split( '/\s+/', (string) ( $q['scope'] ?? 'mcp:write' ) ), true ) || empty( $q['scope'] ) ? 'mcp:write' : 'mcp:read';
		return $base + array( 'ok' => true, 'challenge' => (string) $q['code_challenge'], 'scope' => $scope );
	}

	private static function back_to_client( $redirect, array $args ) {
		$args['iss'] = self::issuer();
		wp_redirect( add_query_arg( array_map( 'rawurlencode', array_filter( $args, 'strlen' ) ), $redirect ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- the registered redirect URI
		exit;
	}

	public static function handle_consent() {
		$q   = wp_unslash( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? $_POST : $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$req = self::check_request( (array) $q );
		if ( ! $req['ok'] && isset( $req['redirect_error'] ) ) {
			self::back_to_client( $req['redirect_uri'], array( 'error' => $req['redirect_error'], 'state' => $req['state'] ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $req['ok'] ) {
			return; // render_consent shows the screen or the error
		}
		check_admin_referer( 'upw_ai_oauth_consent' );
		if ( empty( $_POST['allow'] ) ) {
			self::back_to_client( $req['redirect_uri'], array( 'error' => 'access_denied', 'state' => $req['state'] ) );
		}
		$scope = ( $_POST['access'] ?? '' ) === 'read' ? 'mcp:read' : 'mcp:write';
		if ( FW_AI_MCP::mode() === 'off' ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Outside AI programs are turned off on this site. Ask an administrator to turn them on.', 'fw' ), 403 );
			}
			update_option( FW_AI_MCP::OPTION_MODE, $scope === 'mcp:read' ? 'read' : 'write', false );
		}
		$code = wp_generate_password( 48, false );
		set_transient( self::CODE_PREFIX . hash( 'sha256', $code ), array(
			'client'       => $req['client_id'],
			'user'         => get_current_user_id(),
			'redirect_uri' => $req['redirect_uri'],
			'challenge'    => $req['challenge'],
			'scope'        => $scope,
		), self::CODE_TTL );
		self::back_to_client( $req['redirect_uri'], array( 'code' => $code, 'state' => $req['state'] ) );
	}

	public static function render_consent() {
		$q    = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$req  = self::check_request( (array) $q );
		$user = wp_get_current_user();
		?>
		<div class="wrap upw-ai-consent">
			<?php if ( ! $req['ok'] ) : ?>
				<h1><?php esc_html_e( 'This sign-in cannot continue', 'fw' ); ?></h1>
				<p><?php echo esc_html( $req['error'] ?? __( 'The request was not valid.', 'fw' ) ); ?></p>
			<?php else : ?>
				<?php
				$host  = (string) wp_parse_url( $req['redirect_uri'], PHP_URL_HOST );
				$off   = FW_AI_MCP::mode() === 'off';
				$admin = current_user_can( 'manage_options' );
				?>
				<div class="upw-ai-consent__card">
					<h1><?php echo esc_html( sprintf( /* translators: %s: app name */ __( 'Allow %s to use this site?', 'fw' ), $req['client']['name'] ) ); ?></h1>
					<p class="upw-ai-consent__lede">
						<?php
						printf(
							/* translators: 1: site name, 2: user display name */
							esc_html__( 'It will work on %1$s through the AI Assistant\'s tools, as %2$s, and can do only what your account can do. Every change it makes is saved first and can be undone.', 'fw' ),
							'<strong>' . esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) ) . '</strong>',
							'<strong>' . esc_html( $user->display_name ) . '</strong>'
						);
						?>
					</p>
					<form method="post">
						<?php wp_nonce_field( 'upw_ai_oauth_consent' ); ?>
						<?php foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' ) as $k ) : ?>
							<?php if ( isset( $q[ $k ] ) ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $q[ $k ] ); ?>">
							<?php endif; ?>
						<?php endforeach; ?>
						<fieldset class="upw-ai-consent__access">
							<legend><?php esc_html_e( 'What it may do', 'fw' ); ?></legend>
							<label><input type="radio" name="access" value="write" <?php checked( $req['scope'], 'mcp:write' ); ?>> <strong><?php esc_html_e( 'Read and write:', 'fw' ); ?></strong> <?php esc_html_e( 'look at the site, and create and edit pages and settings', 'fw' ); ?></label>
							<label><input type="radio" name="access" value="read" <?php checked( $req['scope'], 'mcp:read' ); ?>> <strong><?php esc_html_e( 'Read only:', 'fw' ); ?></strong> <?php esc_html_e( 'look at the site, change nothing', 'fw' ); ?></label>
						</fieldset>
						<?php if ( $off ) : ?>
							<p class="upw-ai-consent__note"><?php echo $admin ? esc_html__( 'Outside AI programs are turned off on this site. Allowing turns them on.', 'fw' ) : esc_html__( 'Outside AI programs are turned off on this site, so only an administrator can allow this.', 'fw' ); ?></p>
						<?php endif; ?>
						<p class="upw-ai-consent__return"><?php echo esc_html( sprintf( /* translators: %s: host name */ __( 'You will return to %s.', 'fw' ), $host ) ); ?></p>
						<p class="upw-ai-consent__buttons">
							<button type="submit" name="allow" value="1" class="button button-primary button-hero"<?php disabled( $off && ! $admin ); ?>><?php esc_html_e( 'Allow', 'fw' ); ?></button>
							<button type="submit" name="deny" value="1" class="button button-hero"><?php esc_html_e( 'Deny', 'fw' ); ?></button>
						</p>
						<p class="upw-ai-consent__muted">
							<?php
							printf(
								/* translators: %s: link to the AI Assistant settings */
								esc_html__( 'You can sign the app out at any time under %s.', 'fw' ),
								current_user_can( 'manage_options' )
									? '<a href="' . esc_url( FW_Extension_AI_Assistant::get_page_url() . '#upw-ai-outside' ) . '">' . esc_html__( 'Unyson+ → AI Assistant → Advanced → Outside AI programs', 'fw' ) . '</a>'
									: esc_html__( 'Unyson+ → AI Assistant', 'fw' )
							);
							?>
						</p>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<style>
			.upw-ai-consent { max-width: 36rem; margin: 3rem auto; }
			.upw-ai-consent__card { padding: 1.75rem 2rem; background: var(--upa-panel, #fff); border: 1px solid var(--upa-border, #dcdcde); border-radius: var(--upa-radius-lg, 8px); }
			.upw-ai-consent h1 { margin: 0 0 .5rem; font-size: 20px; line-height: 1.3; }
			.upw-ai-consent__lede { font-size: 14px; color: var(--upa-text2, #3c434a); }
			.upw-ai-consent__access { margin: 1.25rem 0 .75rem; }
			.upw-ai-consent__access legend { font-weight: 600; margin-bottom: .4rem; }
			.upw-ai-consent__access label { display: block; margin: .4rem 0; }
			.upw-ai-consent__note { padding: .6rem .8rem; border-radius: 6px; background: var(--upa-panel2, #f6f7f7); }
			.upw-ai-consent__return, .upw-ai-consent__muted { color: var(--upa-text3, #50575e); }
			.upw-ai-consent__buttons { display: flex; gap: .75rem; margin: 1.25rem 0 1rem; }
		</style>
		<?php
	}
}
