<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Actions waiting for an administrator's click in wp-admin.
 *
 * Some managed hosts refuse to CREATE .php files from a REST request — which is how an AI app reaches the
 * site — while allowing it from a wp-admin request (Appearance → Themes → Upload works there). Installing a
 * theme, creating a child theme or writing a PHP file in it then cannot happen inside the AI's call. Rather
 * than fail, the action is QUEUED: the verified package / file is kept, the AI is told it is waiting, and
 * the next administrator to open wp-admin sees a notice naming it, with Install and Discard. Install runs
 * the very same code from that wp-admin request. The AI reads the outcome afterwards.
 *
 * Nothing runs on a page visit by itself: the click is the person's consent, and they see what it is.
 */
class FW_AI_Queue {

	const OPTION = 'upw_ai_queue';
	const ACTION = 'upw_ai_queue';
	const TTL    = 604800; // a week

	/** @var bool True while a queued action runs (so the action does not queue itself again). */
	public static $running = false;

	/** @var bool|null Per-request cache of the PHP-write check. */
	private static $php_ok = null;

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Can this request create a .php file in the themes folder? (Checked once per request, by trying.)
	 *
	 * @return bool
	 */
	public static function php_writable() {
		if ( self::$php_ok !== null ) {
			return self::$php_ok;
		}
		$f  = trailingslashit( get_theme_root() ) . 'upw-probe-' . wp_generate_password( 8, false ) . '.php';
		$ok = @file_put_contents( $f, "<?php // probe\n" ) !== false; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( is_file( $f ) ) {
			@unlink( $f ); // phpcs:ignore
		}
		/** Filters whether this request may create .php files (a host integration, or a test, can say no). */
		return self::$php_ok = (bool) apply_filters( 'fw_ai_php_writable', $ok );
	}

	/**
	 * Should an action that creates PHP files wait for wp-admin? (Not when it is already running from there.)
	 *
	 * @return bool
	 */
	public static function must_queue() {
		return ! self::$running && ! self::php_writable();
	}

	/** @return array */
	private static function all() {
		$list = (array) get_option( self::OPTION, array() );
		$now  = time();
		foreach ( $list as $id => $j ) {
			if ( $now - (int) $j['created'] > self::TTL ) {
				if ( ! empty( $j['payload']['file'] ) && is_file( $j['payload']['file'] ) ) {
					wp_delete_file( $j['payload']['file'] );
				}
				unset( $list[ $id ] );
			}
		}
		return $list;
	}

	private static function save( array $list ) {
		update_option( self::OPTION, $list, false );
	}

	/**
	 * Queue an action.
	 *
	 * @param string $kind    install_theme | child_theme | theme_file
	 * @param string $label   What the person is asked to approve, in plain words.
	 * @param array  $payload Arguments for the run (a 'file' is deleted when the job ends).
	 * @param string $key     The caller's id for it (e.g. the upload_id), to read the status later.
	 * @param string $cap     Capability the approving user needs.
	 * @return array The job.
	 */
	public static function add( $kind, $label, array $payload, $key, $cap ) {
		$list = self::all();
		foreach ( $list as $id => $j ) {
			if ( $j['key'] === $key && $j['status'] === 'queued' ) {
				if ( ! empty( $j['payload']['file'] ) && $j['payload']['file'] !== ( $payload['file'] ?? '' ) && is_file( $j['payload']['file'] ) ) {
					wp_delete_file( $j['payload']['file'] );
				}
				unset( $list[ $id ] ); // a re-send replaces the waiting one
			}
		}
		$id          = wp_generate_password( 12, false );
		$list[ $id ] = array(
			'id'      => $id,
			'kind'    => $kind,
			'label'   => $label,
			'payload' => $payload,
			'key'     => (string) $key,
			'cap'     => $cap,
			'user'    => get_current_user_id(),
			'created' => time(),
			'status'  => 'queued',
			'result'  => null,
		);
		self::save( $list );
		return $list[ $id ];
	}

	/**
	 * The newest job for a key, or null.
	 *
	 * @param string $key
	 * @return array|null
	 */
	public static function find( $key ) {
		$found = null;
		foreach ( self::all() as $j ) {
			if ( $j['key'] === (string) $key && ( ! $found || $j['created'] > $found['created'] ) ) {
				$found = $j;
			}
		}
		return $found;
	}

	/**
	 * What an AI call returns for a job.
	 *
	 * @param array $j
	 * @return array
	 */
	public static function status( array $j ) {
		$msg = array(
			'queued'    => 'Waiting: this host does not let the AI connection create PHP files, so an administrator has to approve it in wp-admin. The next time one opens the dashboard, a notice offers Install / Discard. Ask the person to open wp-admin and press Install, then check again.',
			'done'      => 'Done in wp-admin.',
			'failed'    => 'It was approved in wp-admin but did not succeed.',
			'discarded' => 'An administrator discarded it in wp-admin.',
		);
		return array(
			'ok'      => $j['status'] === 'done',
			'queued'  => $j['status'] === 'queued',
			'status'  => $j['status'],
			'job'     => $j['id'],
			'action'  => $j['label'],
			'message' => $msg[ $j['status'] ] ?? '',
			'result'  => $j['result'],
		);
	}

	/* ---------- wp-admin ---------- */

	public static function notice() {
		$jobs = array_filter( self::all(), function ( $j ) {
			return $j['status'] === 'queued' && current_user_can( $j['cap'] );
		} );
		$done = isset( $_GET['upw_ai_queue'] ) ? sanitize_key( wp_unslash( $_GET['upw_ai_queue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only
		if ( $done !== '' ) {
			$all = self::all();
			foreach ( $all as $j ) {
				if ( strtolower( $j['id'] ) === $done ) {
					$ok = $j['status'] === 'done';
					echo '<div class="notice ' . ( $ok ? 'notice-success' : ( $j['status'] === 'discarded' ? 'notice-info' : 'notice-error' ) ) . ' is-dismissible"><p><strong>' . esc_html( $j['label'] ) . ':</strong> '
						. esc_html( $j['status'] === 'discarded' ? __( 'discarded. Nothing was changed.', 'fw' ) : ( is_array( $j['result'] ) ? (string) ( $j['result']['message'] ?? '' ) : (string) $j['result'] ) ) . '</p></div>';
				}
			}
		}
		if ( ! $jobs ) {
			return;
		}
		echo '<div class="notice notice-warning upa-keep" id="upw-ai-queue-notice"><p><strong>' . esc_html__( 'Your AI app is waiting for your approval', 'fw' ) . '</strong> — '
			. esc_html__( 'this host only allows these changes from wp-admin, so they wait for your click. Nothing has changed yet.', 'fw' ) . '</p><ul style="margin:.25rem 0 .75rem">';
		foreach ( $jobs as $j ) {
			$url = function ( $decision ) use ( $j ) {
				return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&job=' . rawurlencode( $j['id'] ) . '&decision=' . $decision ), self::ACTION . $j['id'] );
			};
			echo '<li style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap"><span>' . esc_html( $j['label'] ) . ' <span style="color:#646970">(' . esc_html( human_time_diff( (int) $j['created'] ) ) . ' ' . esc_html__( 'ago', 'fw' ) . ')</span></span>'
				. ' <a class="button button-primary button-small" href="' . esc_url( $url( 'run' ) ) . '">' . esc_html__( 'Install', 'fw' ) . '</a>'
				. ' <a class="button button-small" href="' . esc_url( $url( 'discard' ) ) . '">' . esc_html__( 'Discard', 'fw' ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	public static function handle() {
		$id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		check_admin_referer( self::ACTION . $id );
		$list = self::all();
		if ( empty( $list[ $id ] ) || $list[ $id ]['status'] !== 'queued' ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url() );
			exit;
		}
		$j = $list[ $id ];
		if ( ! current_user_can( $j['cap'] ) ) {
			wp_die( esc_html__( 'Your account cannot approve this.', 'fw' ) );
		}
		if ( ( $_GET['decision'] ?? '' ) === 'run' ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked above
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			self::$running = true;
			$r             = self::run( $j );
			self::$running = false;
			$j['status']   = is_wp_error( $r ) ? 'failed' : 'done';
			$j['result']   = is_wp_error( $r ) ? array( 'message' => $r->get_error_message() ) : $r;
		} else {
			$j['status'] = 'discarded';
		}
		if ( ! empty( $j['payload']['file'] ) && is_file( $j['payload']['file'] ) ) {
			wp_delete_file( $j['payload']['file'] );
		}
		$j['payload']['file'] = '';
		$j['done_by']         = get_current_user_id();
		$j['done_at']         = time();
		$list                 = self::all();
		$list[ $id ]          = $j;
		self::save( $list );
		wp_safe_redirect( add_query_arg( 'upw_ai_queue', rawurlencode( strtolower( $id ) ), wp_get_referer() ?: admin_url() ) );
		exit;
	}

	/**
	 * Run a job with the same code the AI call would have run.
	 *
	 * @param array $j
	 * @return array|WP_Error
	 */
	private static function run( array $j ) {
		$p = (array) $j['payload'];
		if ( $j['kind'] === 'install_theme' ) {
			return FW_AI_Files::install_theme( array( 'queued_package' => (string) $p['file'], 'activate' => ! empty( $p['activate'] ), 'confirm' => true ) );
		}
		if ( $j['kind'] === 'child_theme' ) {
			return FW_AI_Theme::create( array( 'name' => (string) $p['name'], 'confirm' => true ) );
		}
		if ( $j['kind'] === 'theme_file' ) {
			$content = is_file( (string) $p['file'] ) ? (string) file_get_contents( (string) $p['file'] ) : null;
			if ( $content === null ) {
				return new WP_Error( 'upw_ai_queue', 'The waiting file content is gone.' );
			}
			return FW_AI_Files::theme_files( array( 'action' => 'write', 'path' => (string) $p['path'], 'content' => $content, 'confirm' => true ) );
		}
		return new WP_Error( 'upw_ai_queue', 'Unknown action.' );
	}

	/**
	 * Keep a payload file for a queued job (outside the web root's reach: uploads/unysonplus/tmp, denied).
	 *
	 * @param string $src  A file to copy, or '' when $data is given.
	 * @param string $data Contents to store instead.
	 * @return string The stored path.
	 */
	public static function keep_file( $src, $data = null ) {
		$base = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'tmp' )['path'] : wp_upload_dir()['basedir'] . '/unysonplus/tmp';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			@file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		$dest = $base . '/queued-' . wp_generate_password( 16, false ) . '.bin';
		if ( $data !== null ) {
			file_put_contents( $dest, $data );
		} else {
			copy( $src, $dest );
		}
		return $dest;
	}
}
