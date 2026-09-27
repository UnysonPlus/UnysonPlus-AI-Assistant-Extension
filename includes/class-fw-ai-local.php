<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The local command-line agent backend (development hosts only): runs the command template saved
 * on the AI Assistant screen in the background and collects its output.
 *
 * Each run gets a folder under the system temp dir (never under uploads — the MCP config can hold a
 * password) with mcp.json, prompt.txt, and — once the command finishes — out.txt + done.txt.
 */
class FW_AI_Local {

	/**
	 * @return bool Whether a command is configured and may run on this host.
	 */
	public static function ready() {
		return FW_AI_MCP::is_local_host()
			&& trim( (string) get_option( FW_AI_Panel::OPTION_LOCAL_CMD, '' ) ) !== ''
			&& function_exists( 'popen' );
	}

	/**
	 * Start the agent in the background.
	 *
	 * @param array  $mcp_config The MCP config written to {mcp_config} (array( 'mcpServers' => … )).
	 * @param string $prompt     Written to {prompt_file}.
	 * @return string|WP_Error The run folder.
	 */
	public static function spawn( array $mcp_config, $prompt ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'upw_ai_local_off', 'The local agent command is not available on this host.' );
		}
		self::sweep();
		$dir = trailingslashit( get_temp_dir() ) . 'upw-ai-' . strtolower( wp_generate_password( 20, false ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'upw_ai_tmp', 'Could not create a temporary folder for the agent.' );
		}
		if ( ! $mcp_config ) {
			$mcp_config = array( 'mcpServers' => new stdClass() );
		}
		file_put_contents( $dir . '/mcp.json', wp_json_encode( $mcp_config, JSON_UNESCAPED_SLASHES ) );
		file_put_contents( $dir . '/prompt.txt', (string) $prompt );

		$cmd  = strtr( (string) get_option( FW_AI_Panel::OPTION_LOCAL_CMD, '' ), array(
			'{mcp_config}'  => '"' . $dir . DIRECTORY_SEPARATOR . 'mcp.json"',
			'{prompt_file}' => '"' . $dir . DIRECTORY_SEPARATOR . 'prompt.txt"',
		) );
		$out  = $dir . DIRECTORY_SEPARATOR . 'out.txt';
		$done = $dir . DIRECTORY_SEPARATOR . 'done.txt';
		if ( stripos( PHP_OS, 'WIN' ) === 0 ) {
			$line = 'cd /d "' . $dir . '" && (' . $cmd . ') > "' . $out . '" 2>&1 & echo done> "' . $done . '"';
			pclose( popen( 'start "" /B cmd /S /C "' . $line . '"', 'r' ) );
		} else {
			$line = '( cd ' . escapeshellarg( $dir ) . ' && ' . $cmd . ' > ' . escapeshellarg( $out ) . ' 2>&1; echo done > ' . escapeshellarg( $done ) . ' ) > /dev/null 2>&1 &';
			pclose( popen( $line, 'r' ) );
		}
		return $dir;
	}

	/**
	 * @param string $dir
	 * @return bool
	 */
	public static function finished( $dir ) {
		return file_exists( $dir . '/done.txt' );
	}

	/**
	 * @param string $dir
	 * @return string The command's output (trimmed).
	 */
	public static function output( $dir ) {
		return trim( (string) @file_get_contents( $dir . '/out.txt' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Remove run folders nobody collected (the browser was closed before the answer arrived).
	 * Runs are capped well below 30 minutes, so anything older is abandoned.
	 */
	public static function sweep() {
		foreach ( (array) glob( trailingslashit( get_temp_dir() ) . 'upw-ai-*', GLOB_ONLYDIR ) as $dir ) {
			if ( $dir && time() - (int) @filemtime( $dir ) > 30 * MINUTE_IN_SECONDS ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::cleanup( $dir );
			}
		}
		// Temporary builder-panel passwords whose run was never collected.
		$user = get_current_user_id();
		if ( $user && class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user ) as $pw ) {
				if ( strpos( (string) $pw['name'], FW_Extension_AI_Assistant::APP_PASSWORD_NAME ) === 0
					&& strpos( (string) $pw['name'], '(temporary)' ) !== false
					&& time() - (int) $pw['created'] > 30 * MINUTE_IN_SECONDS ) {
					WP_Application_Passwords::delete_application_password( $user, $pw['uuid'] );
				}
			}
		}
	}

	/**
	 * @param string $dir
	 */
	public static function cleanup( $dir ) {
		if ( strpos( basename( (string) $dir ), 'upw-ai-' ) !== 0 ) {
			return;
		}
		foreach ( array( 'mcp.json', 'prompt.txt', 'out.txt', 'done.txt' ) as $f ) {
			@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
