<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Who may use the AI Assistant, and a log of what it was asked.
 *
 * Roles: option upw_ai_roles lists the roles allowed to use the assistant (the builder panel, the
 * site-wide chat, outside AI programs over MCP, web sign-in). Empty = everyone whose capabilities
 * already allow the action (the behaviour before this setting). Administrators are always allowed, so
 * nobody can lock the site out of its own settings. One REST filter enforces it for every route in the
 * extension's namespace (OAuth discovery and token routes excepted — they authenticate nobody), and the
 * panel / admin-bar entry points hide themselves.
 *
 * Usage log: option upw_ai_usage_log, the newest 500 requests — when, who, where, through what, and
 * the first 200 characters of what was asked (or, for an outside program, the tool it called). Admin
 * only (Unyson+ → AI Usage); can be switched off (upw_ai_usage_on) and cleared.
 */
class FW_AI_Access {

	const OPTION_ROLES = 'upw_ai_roles';
	const OPTION_LOG   = 'upw_ai_usage_log';
	const OPTION_ON    = 'upw_ai_usage_on';
	const MAX_LOG      = 500;
	const PAGE_SLUG    = 'fw-ai-usage';

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'gate' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
	}

	/* ------------------------------------------------------------------ *
	 * Roles
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[] Allowed role slugs (empty = no restriction).
	 */
	public static function roles() {
		$r = get_option( self::OPTION_ROLES, array() );
		return is_array( $r ) ? array_values( array_filter( array_map( 'sanitize_key', $r ) ) ) : array();
	}

	/**
	 * @param int|null $user_id Default: the current user.
	 * @return bool
	 */
	public static function can_use( $user_id = null ) {
		$user = $user_id === null ? wp_get_current_user() : get_userdata( (int) $user_id );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		$allowed = self::roles();
		return ! $allowed || (bool) array_intersect( (array) $user->roles, $allowed );
	}

	/**
	 * `rest_request_before_callbacks`: refuse the assistant's routes to roles that may not use it, and
	 * log the requests that start work.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response
	 * @param array                           $handler
	 * @param WP_REST_Request                 $request
	 * @return mixed
	 */
	public static function gate( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$route = $request->get_route();
		$ns    = '/' . FW_AI_MCP::REST_NS . '/';
		if ( strpos( $route, $ns ) !== 0 || strpos( $route, $ns . 'oauth/' ) === 0 ) {
			return $response;
		}
		if ( is_wp_error( $response ) || ! is_user_logged_in() ) {
			return $response;
		}
		if ( ! self::can_use() ) {
			return new WP_Error( 'upw_ai_role', __( 'Your role is not allowed to use the AI Assistant on this site. Ask an administrator.', 'fw' ), array( 'status' => 403 ) );
		}
		self::log_request( substr( $route, strlen( $ns ) ), $request );
		return $response;
	}

	/* ------------------------------------------------------------------ *
	 * Usage log
	 * ------------------------------------------------------------------ */

	public static function logging() {
		return get_option( self::OPTION_ON, 'yes' ) !== 'no';
	}

	/**
	 * @param string          $route Route inside the namespace ("site/run", "mcp" …).
	 * @param WP_REST_Request $r
	 */
	private static function log_request( $route, WP_REST_Request $r ) {
		if ( ! self::logging() ) {
			return;
		}
		$pid = (int) $r->get_param( 'post_id' );
		switch ( $route ) {
			case 'panel/run':
				$entry = array( 'via' => 'panel', 'where' => $pid, 'text' => (string) $r->get_param( 'message' ) );
				break;
			case 'site/run':
				$entry = array( 'via' => 'site', 'where' => 0, 'text' => (string) $r->get_param( 'message' ) );
				break;
			case 'panel/local/start':
				$entry = array(
					'via'   => ( $r->get_param( 'agent' ) ? 'agent' : 'local' ) . ( $r->get_param( 'mode' ) === 'site' ? '-site' : '' ),
					'where' => $r->get_param( 'mode' ) === 'site' ? 0 : $pid,
					'text'  => (string) $r->get_param( 'message' ),
				);
				break;
			case 'mcp':
				// The panel's own tool calls carry its session header; only outside programs are logged here.
				$body = $r->get_json_params();
				if ( $r->get_header( 'x_upw_ai_session' ) || ! is_array( $body ) || ( $body['method'] ?? '' ) !== 'tools/call' ) {
					return;
				}
				$args  = (array) ( $body['params']['arguments'] ?? array() );
				$entry = array( 'via' => 'mcp', 'where' => (int) ( $args['post_id'] ?? 0 ), 'text' => (string) ( $body['params']['name'] ?? '' ), 'tool' => true );
				break;
			default:
				return;
		}
		$log   = self::entries();
		$log[] = array(
			'time'  => time(),
			'user'  => get_current_user_id(),
			'via'   => $entry['via'],
			'where' => (int) $entry['where'],
			'text'  => mb_substr( wp_strip_all_tags( $entry['text'] ), 0, 200 ),
			'tool'  => ! empty( $entry['tool'] ),
		);
		if ( count( $log ) > self::MAX_LOG ) {
			$log = array_slice( $log, -self::MAX_LOG );
		}
		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array[] Oldest first.
	 */
	public static function entries() {
		$l = get_option( self::OPTION_LOG, array() );
		return is_array( $l ) ? $l : array();
	}

	/* ------------------------------------------------------------------ *
	 * AI Usage screen
	 * ------------------------------------------------------------------ */

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	public static function menu() {
		$hook = add_submenu_page( FW_Extension_AI_Assistant::PARENT_SLUG, __( 'AI Usage', 'fw' ), __( 'AI Usage', 'fw' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle' ) );
		}
	}

	public static function handle() {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['upw_ai_visitor_log_clear'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'upw_ai_visitor_log_clear' );
			delete_option( FW_AI_Visitor::OPTION_LOG );
			wp_safe_redirect( add_query_arg( 'cleared', 1, self::url() ) );
			exit;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['upw_ai_usage_clear'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'upw_ai_usage_clear' );
			delete_option( self::OPTION_LOG );
			wp_safe_redirect( add_query_arg( 'cleared', 1, self::url() ) );
			exit;
		}
	}

	/**
	 * @param string $via
	 * @return string
	 */
	private static function via_label( $via ) {
		$map = array(
			'panel'      => __( 'Builder chat', 'fw' ),
			'site'       => __( 'Site chat', 'fw' ),
			'local'      => __( 'Builder chat (local AI)', 'fw' ),
			'local-site' => __( 'Site chat (local AI)', 'fw' ),
			'agent'      => __( 'Builder chat (AI Dev Kit agent)', 'fw' ),
			'agent-site' => __( 'Site chat (AI Dev Kit agent)', 'fw' ),
			'mcp'        => __( 'Outside AI program', 'fw' ),
		);
		return $map[ $via ] ?? $via;
	}

	public static function render() {
		$all   = array_reverse( self::entries() );
		$since = time() - 30 * DAY_IN_SECONDS;
		$month = array_filter( $all, function ( $e ) use ( $since ) {
			return (int) $e['time'] >= $since;
		} );
		$by_user = array();
		foreach ( $month as $e ) {
			$u = (int) $e['user'];
			if ( ! isset( $by_user[ $u ] ) ) {
				$by_user[ $u ] = array( 'requests' => 0, 'tools' => 0, 'last' => 0 );
			}
			$by_user[ $u ][ $e['tool'] ? 'tools' : 'requests' ]++;
			$by_user[ $u ]['last'] = max( $by_user[ $u ]['last'], (int) $e['time'] );
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap upw-ai-usage">
			<h1><?php esc_html_e( 'AI Usage', 'fw' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of entries kept, 2: link to the settings */
					esc_html__( 'What the AI Assistant was asked, newest first (the last %1$d requests are kept). Who may use it is set under %2$s.', 'fw' ),
					(int) self::MAX_LOG,
					'<a href="' . esc_url( FW_Extension_AI_Assistant::get_page_url() . '#upw-ai-access' ) . '">' . esc_html__( 'AI Assistant → Advanced → Who can use it', 'fw' ) . '</a>'
				);
				?>
			</p>
			<?php if ( ! self::logging() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'The usage log is switched off, so new requests are not recorded.', 'fw' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'The usage log was cleared.', 'fw' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Last 30 days', 'fw' ); ?></h2>
			<?php if ( ! $by_user ) : ?>
				<p><?php esc_html_e( 'No requests yet.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:48rem">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Person', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Chat requests', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Outside program tool calls', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last used', 'fw' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $by_user as $uid => $row ) : $u = get_userdata( $uid ); ?>
						<tr>
							<td><?php echo esc_html( $u ? $u->display_name : '#' . $uid ); ?></td>
							<td><?php echo (int) $row['requests']; ?></td>
							<td><?php echo (int) $row['tools']; ?></td>
							<td><?php echo esc_html( date_i18n( $fmt, $row['last'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent requests', 'fw' ); ?></h2>
			<?php if ( ! $all ) : ?>
				<p><?php esc_html_e( 'Nothing recorded yet.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col" style="width:11rem"><?php esc_html_e( 'When', 'fw' ); ?></th>
						<th scope="col" style="width:10rem"><?php esc_html_e( 'Who', 'fw' ); ?></th>
						<th scope="col" style="width:13rem"><?php esc_html_e( 'Through', 'fw' ); ?></th>
						<th scope="col" style="width:12rem"><?php esc_html_e( 'Where', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Asked', 'fw' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $all, 0, 100 ) as $e ) : $u = get_userdata( (int) $e['user'] ); ?>
						<tr>
							<td><?php echo esc_html( date_i18n( $fmt, (int) $e['time'] ) ); ?></td>
							<td><?php echo esc_html( $u ? $u->display_name : '#' . (int) $e['user'] ); ?></td>
							<td><?php echo esc_html( self::via_label( $e['via'] ) ); ?></td>
							<td>
								<?php if ( $e['where'] && get_post( $e['where'] ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $e['where'] ) ); ?>"><?php echo esc_html( get_the_title( $e['where'] ) ); ?></a>
								<?php else : ?>
									<?php echo $e['via'] === 'mcp' ? '—' : esc_html__( 'Whole site', 'fw' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo $e['tool'] ? '<code>' . esc_html( $e['text'] ) . '</code>' : esc_html( $e['text'] !== '' ? $e['text'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:1rem">
					<?php wp_nonce_field( 'upw_ai_usage_clear' ); ?>
					<button type="submit" name="upw_ai_usage_clear" value="1" class="button" onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Clear the whole usage log?', 'fw' ) ) ); ?> )"><?php esc_html_e( 'Clear the log', 'fw' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( class_exists( 'FW_AI_Visitor' ) ) { FW_AI_Visitor::render_usage(); } ?>
		</div>
		<?php
	}
}
