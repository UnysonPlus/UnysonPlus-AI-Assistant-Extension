<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Settings → Outside AI programs → "Check the connection": can an AI app on the internet actually reach
 * this site's connector? Five checks, each with what to do when it fails:
 *
 *   permalinks  "Plain" permalinks hide /wp-json/ addresses (offers a one-click switch to "Post name")
 *   access      outside programs are allowed at all (the access setting above)
 *   inside      the connector answers tools/list in this request, as the current user
 *   outside     a request from outside, without sign-in, reaches it and is asked to sign in
 *   sign-in     the OAuth discovery documents AI apps look for are served at /.well-known/
 *   https       the site has a public https:// address
 *
 * (These came from the former one-click "Connect AI" page; the rest of that page — switching the theme,
 * turning on extensions — is done from the Themes and Extensions screens, or by the AI when a job needs it.)
 */
class FW_AI_Connection {

	/** @var array|null The result of a check run in this request (the page renders it). */
	public static $result = null;

	private static function ok( $text ) {
		return array( 'level' => 'ok', 'text' => $text );
	}

	private static function warn( $text, $fix = '', $action = '' ) {
		return array( 'level' => 'warn', 'text' => $text, 'fix' => $fix, 'action' => $action );
	}

	private static function fail( $text, $fix = '', $action = '' ) {
		return array( 'level' => 'fail', 'text' => $text, 'fix' => $fix, 'action' => $action );
	}

	/**
	 * @return string The connector address.
	 */
	public static function endpoint() {
		return rest_url( 'unysonplus-ai/v1/mcp' );
	}

	/**
	 * A loopback / private address an AI app on the internet cannot reach.
	 *
	 * @return bool
	 */
	public static function is_private_host() {
		$host = strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '[]' ) );
		if ( $host === 'localhost' || preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Run every check.
	 *
	 * @return array { level, items[] }
	 */
	public static function run() {
		$items = array();

		if ( (string) get_option( 'permalink_structure' ) === '' ) {
			$items[] = self::fail(
				__( 'The site uses "Plain" web addresses, which hide the connector address from AI apps.', 'fw' ),
				__( 'Switch to "Post name" (/sample-page/). Existing links keep working.', 'fw' ),
				'fix_permalinks'
			);
		}

		$mode = get_option( FW_AI_MCP::OPTION_MODE, 'off' );
		if ( $mode === 'off' ) {
			$items[] = self::fail( __( 'Outside AI programs are switched off.', 'fw' ), __( 'Choose "Read only" or "Read and write" above and press Save access.', 'fw' ) );
		} elseif ( $mode === 'read' ) {
			$items[] = self::warn( __( 'Outside AI programs may read but not change anything.', 'fw' ), __( 'Choose "Read and write" above if you want an AI app to build or edit pages.', 'fw' ) );
		} else {
			$items[] = self::ok( __( 'Outside AI programs may read and write (each one still needs your approval when it signs in).', 'fw' ) );
		}

		$count = self::internal_tools_count();
		$items[] = ( is_int( $count ) && $count > 0 )
			? self::ok( sprintf( _n( 'The connector answers: %d tool is ready.', 'The connector answers: %d tools are ready.', $count, 'fw' ), $count ) )
			: self::fail( sprintf( __( 'The connector did not answer inside the site (%s).', 'fw' ), is_string( $count ) ? $count : __( 'no tools', 'fw' ) ), __( 'Check the access setting above, then check again.', 'fw' ) );

		$items[] = self::loopback_endpoint();
		$items[] = self::loopback_discovery();

		if ( self::is_private_host() ) {
			$items[] = self::warn( __( 'This site runs on your own computer (a local address), so AI apps on the internet cannot reach it.', 'fw' ), __( 'Use a site with a public https:// address — your live site or a staging copy at your host. An AI app on this same computer (e.g. through the AI Dev Kit) still works.', 'fw' ) );
		} elseif ( wp_parse_url( home_url(), PHP_URL_SCHEME ) !== 'https' ) {
			$items[] = self::warn( __( 'This site does not use https://. AI app connectors need a public https:// address.', 'fw' ), __( 'Turn on SSL in your hosting control panel, switch the site address to https:// under Settings → General, then check again.', 'fw' ) );
		} else {
			$items[] = self::ok( __( 'The site has a public https:// address.', 'fw' ) );
		}

		$level = 'ok';
		foreach ( $items as $i ) {
			if ( $i['level'] === 'fail' ) {
				$level = 'fail';
				break;
			}
			if ( $i['level'] === 'warn' ) {
				$level = 'warn';
			}
		}
		return array( 'level' => $level, 'items' => $items, 'at' => time() );
	}

	/**
	 * "Plain" → "Post name", with the rewrite rules rebuilt (and .htaccess written on Apache).
	 *
	 * @return array A check item.
	 */
	public static function fix_permalinks() {
		if ( (string) get_option( 'permalink_structure' ) !== '' ) {
			return self::ok( __( 'Already using readable web addresses.', 'fw' ) );
		}
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( true );
		if ( (string) get_option( 'permalink_structure' ) === '' ) {
			return self::fail( __( 'The permalink setting could not be saved.', 'fw' ), __( 'Open Settings → Permalinks, choose "Post name" and press Save Changes.', 'fw' ) );
		}
		return self::ok( __( 'Switched from "Plain" to "Post name" (/sample-page/).', 'fw' ) );
	}

	/**
	 * @return int|string Tool count, or a short reason.
	 */
	private static function internal_tools_count() {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return 'REST API unavailable';
		}
		$req = new WP_REST_Request( 'POST', '/unysonplus-ai/v1/mcp' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() ) ) );
		$res  = rest_do_request( $req );
		$data = $res->get_data();
		if ( $res->get_status() !== 200 ) {
			return 'HTTP ' . $res->get_status() . ( is_array( $data ) && ! empty( $data['message'] ) ? ': ' . wp_strip_all_tags( (string) $data['message'] ) : '' );
		}
		return isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ? count( $data['result']['tools'] ) : 'unexpected answer';
	}

	private static function loopback_args() {
		return array(
			'timeout'     => 15,
			'redirection' => 3,
			/** Same filter Site Health uses for its loopback requests. */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json' ),
		);
	}

	/** The connector address, called from outside without signing in: 401 = reachable. */
	private static function loopback_endpoint() {
		$args         = self::loopback_args();
		$args['body'] = wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ) );
		$res          = wp_remote_post( self::endpoint(), $args );
		$unverified   = __( 'Your host may block a site from calling itself — that is common and usually harmless. Try connecting anyway.', 'fw' );
		if ( is_wp_error( $res ) ) {
			return self::warn( sprintf( __( 'Could not check the connector address from the server (%s).', 'fw' ), wp_strip_all_tags( $res->get_error_message() ) ), $unverified );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$err  = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';
		if ( $code === 401 && $err === 'upw_ai_mcp_auth' ) {
			return self::ok( __( 'The connector address answers from outside and asks for sign-in, as it should.', 'fw' ) );
		}
		if ( $code === 200 || $code === 202 ) {
			return self::ok( __( 'The connector address answers from outside.', 'fw' ) );
		}
		if ( $code === 404 ) {
			return self::fail( __( 'The connector address was not found (404) when called from outside.', 'fw' ), __( 'Something is hiding the WordPress REST API (addresses under /wp-json/). Check security / firewall plugins (a "disable REST API" setting) and your host\'s firewall.', 'fw' ) );
		}
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return self::fail( sprintf( __( 'The connector address is blocked from outside (HTTP %d%s).', 'fw' ), $code, $err !== '' ? ', ' . $err : '' ), __( 'A security plugin or firewall is blocking the REST API for visitors who are not signed in. Allow everything under /wp-json/unysonplus-ai/ in it.', 'fw' ) );
		}
		if ( ! is_array( $body ) ) {
			return self::warn( sprintf( __( 'The connector address answered with a web page instead of data (HTTP %d).', 'fw' ), $code ), __( 'A firewall, a "coming soon" / maintenance plugin or a server cache may be answering instead of WordPress.', 'fw' ) );
		}
		return self::warn( sprintf( __( 'The connector address answered from outside with HTTP %d.', 'fw' ), $code ), $unverified );
	}

	/** The OAuth discovery documents the AI Assistant serves at the site root. */
	private static function loopback_discovery() {
		$fails = array();
		$any   = false;
		foreach ( array( 'oauth-protected-resource', 'oauth-authorization-server' ) as $doc ) {
			$res = wp_remote_get( home_url( '/.well-known/' . $doc ), self::loopback_args() );
			if ( is_wp_error( $res ) ) {
				return self::warn( sprintf( __( 'Could not check the sign-in details from the server (%s).', 'fw' ), wp_strip_all_tags( $res->get_error_message() ) ), __( 'Your host may block a site from calling itself — that is common and usually harmless. Try connecting anyway.', 'fw' ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$key  = $doc === 'oauth-protected-resource' ? 'resource' : 'authorization_endpoint';
			if ( $code === 200 && is_array( $json ) && ! empty( $json[ $key ] ) ) {
				$any = true;
			} else {
				$fails[] = '/.well-known/' . $doc . ' (HTTP ' . $code . ')';
			}
		}
		if ( ! $fails ) {
			return self::ok( __( 'The sign-in details AI apps look for are published.', 'fw' ) );
		}
		return self::warn(
			sprintf( __( 'Some sign-in details AI apps look for could not be read: %s.', 'fw' ), implode( ', ', $fails ) ),
			$any ? __( 'Try connecting anyway. If the app cannot find the sign-in page, ask your host to let /.well-known/ addresses through to WordPress.', 'fw' )
				: __( 'Your server answers /.well-known/ addresses itself instead of passing them to WordPress. Ask your host to let /.well-known/oauth-* through, or try connecting anyway.', 'fw' )
		);
	}

	/**
	 * The box under "Outside AI programs".
	 *
	 * @param callable $form_open The settings view's form opener.
	 * @return void
	 */
	public static function render( $form_open ) {
		$r     = self::$result;
		$icons = array( 'ok' => '✓', 'warn' => '!', 'fail' => '✗' );
		?>
		<div class="upw-ai-check" id="upw-ai-check">
			<p><strong><?php esc_html_e( 'Check the connection', 'fw' ); ?></strong>
				<span class="upw-ai-muted"><?php esc_html_e( 'Tests whether an AI app on the internet can reach this site, and says what to fix if not.', 'fw' ); ?></span></p>
			<?php if ( $r ) : ?>
				<ul class="upw-ai-check-list">
					<?php foreach ( $r['items'] as $i ) : ?>
						<li class="is-<?php echo esc_attr( $i['level'] ); ?>"><span class="upw-ai-check-ico" aria-hidden="true"><?php echo esc_html( $icons[ $i['level'] ] ); ?></span>
							<span><?php echo esc_html( $i['text'] ); ?>
								<?php if ( ! empty( $i['fix'] ) ) : ?><br><span class="upw-ai-muted"><?php echo esc_html( $i['fix'] ); ?></span><?php endif; ?>
								<?php if ( ( $i['action'] ?? '' ) === 'fix_permalinks' ) : ?>
									<?php $form_open( 'fix_permalinks', ' class="upw-ai-inline-form"' ); ?>
										<?php submit_button( __( 'Switch to "Post name"', 'fw' ), 'secondary small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php $form_open( 'check_connection', ' class="upw-ai-inline-form"' ); ?>
				<?php submit_button( $r ? __( 'Check again', 'fw' ) : __( 'Check the connection', 'fw' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<style>
			.upw-ai-check{margin:1rem 0;padding:.75rem 1rem;border:1px solid #dcdcde;border-radius:6px;background:#fff}
			.upw-ai-check-list{margin:.5rem 0 .75rem;list-style:none;padding:0}
			.upw-ai-check-list li{display:flex;gap:.6rem;align-items:flex-start;margin:.35rem 0}
			.upw-ai-check-ico{flex:0 0 1.3rem;height:1.3rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700}
			.upw-ai-check-list .is-ok .upw-ai-check-ico{background:#00a32a}
			.upw-ai-check-list .is-warn .upw-ai-check-ico{background:#dba617}
			.upw-ai-check-list .is-fail .upw-ai-check-ico{background:#d63638}
			.upw-ai-check-list .upw-ai-inline-form{margin-top:.35rem}
		</style>
		<?php
	}
}
