<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Saved assistant conversations: per user, per place (a builder page, or an admin screen), so a refresh —
 * or another browser — picks the conversation up where it was, and the model gets the earlier turns.
 *
 * Stored in one user-meta row (upw_ai_chats): { <key>: { updated, messages: [ {role, text, steps?, at} ] } }.
 * Kept small on purpose: the last MAX_MESSAGES per place, places untouched for MAX_AGE are dropped, at
 * most MAX_PLACES places. Only text and the "what changed" lines are kept — never page trees — so a
 * restored reply cannot offer "Undo this change" (the builder's own undo history is reset by a reload
 * too); it is shown as applied earlier.
 *
 *   POST /wp-json/unysonplus-ai/v1/panel/history        { key, items[] }  append a finished turn
 *   POST /wp-json/unysonplus-ai/v1/panel/history/clear  { key }           start fresh
 */
class FW_AI_History {

	const META         = 'upw_ai_chats';
	const MAX_MESSAGES = 30;
	const MAX_AGE      = 2592000; // 30 days
	const MAX_PLACES   = 60;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$perm = function () {
			return current_user_can( 'edit_posts' );
		};
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/history', array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $r ) {
				return rest_ensure_response( array( 'ok' => self::append( (string) $r->get_param( 'key' ), (array) $r->get_param( 'items' ) ) ) );
			},
			'permission_callback' => $perm,
			'args'                => array(
				'key'   => array( 'type' => 'string', 'required' => true ),
				'items' => array( 'type' => 'array', 'required' => true ),
			),
		) );
		register_rest_route( FW_AI_MCP::REST_NS, '/panel/history/clear', array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $r ) {
				self::clear( (string) $r->get_param( 'key' ) );
				return rest_ensure_response( array( 'ok' => true ) );
			},
			'permission_callback' => $perm,
			'args'                => array(
				'key' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	/**
	 * @param string $key post:<id> | screen:<id>
	 * @return string '' when invalid
	 */
	public static function clean_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		return preg_match( '/^(post:\d+|screen:[a-z0-9_\-]{1,100})$/', $key ) ? $key : '';
	}

	/**
	 * @param string $key
	 * @param int    $user_id 0 = current user
	 * @return array[] { role, text, steps?, at }
	 */
	public static function get( $key, $user_id = 0 ) {
		$key = self::clean_key( $key );
		if ( $key === '' ) {
			return array();
		}
		$all = self::all( $user_id );
		return isset( $all[ $key ]['messages'] ) ? array_values( (array) $all[ $key ]['messages'] ) : array();
	}

	/**
	 * @param string $key
	 * @param array  $items
	 * @return bool
	 */
	public static function append( $key, array $items ) {
		$key = self::clean_key( $key );
		if ( $key === '' ) {
			return false;
		}
		$clean = array();
		foreach ( array_slice( $items, 0, 4 ) as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['text'] ) ) {
				continue;
			}
			$row = array(
				'role' => ( $m['role'] ?? '' ) === 'assistant' ? 'assistant' : 'user',
				'text' => FW_AI_Panel::clip( (string) $m['text'], 6000 ),
				'at'   => time(),
			);
			$steps = array();
			foreach ( array_slice( (array) ( $m['steps'] ?? array() ), 0, 20 ) as $s ) {
				if ( is_array( $s ) && ! empty( $s['note'] ) ) {
					$steps[] = array(
						'note' => wp_html_excerpt( wp_strip_all_tags( (string) $s['note'] ), 200, '…' ),
						'url'  => esc_url_raw( (string) ( $s['url'] ?? '' ) ),
					);
				}
			}
			if ( $steps ) {
				$row['steps'] = $steps;
			}
			$clean[] = $row;
		}
		if ( ! $clean ) {
			return false;
		}
		$all = self::all();
		$msgs = isset( $all[ $key ]['messages'] ) ? (array) $all[ $key ]['messages'] : array();
		$all[ $key ] = array(
			'updated'  => time(),
			'messages' => array_slice( array_merge( $msgs, $clean ), -self::MAX_MESSAGES ),
		);
		self::save( $all );
		return true;
	}

	/**
	 * @param string $key
	 */
	public static function clear( $key ) {
		$key = self::clean_key( $key );
		$all = self::all();
		if ( $key !== '' && isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			self::save( $all );
		}
	}

	/**
	 * Every saved place for a user, with expired ones dropped.
	 *
	 * @param int $user_id
	 * @return array
	 */
	private static function all( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$all     = $user_id ? get_user_meta( $user_id, self::META, true ) : array();
		$all     = is_array( $all ) ? $all : array();
		$cutoff  = time() - self::MAX_AGE;
		foreach ( $all as $k => $v ) {
			if ( ! is_array( $v ) || (int) ( $v['updated'] ?? 0 ) < $cutoff ) {
				unset( $all[ $k ] );
			}
		}
		return $all;
	}

	/**
	 * @param array $all
	 */
	private static function save( array $all ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		if ( count( $all ) > self::MAX_PLACES ) {
			uasort( $all, function ( $a, $b ) {
				return (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 );
			} );
			$all = array_slice( $all, 0, self::MAX_PLACES, true );
		}
		update_user_meta( $user_id, self::META, $all );
	}
}
