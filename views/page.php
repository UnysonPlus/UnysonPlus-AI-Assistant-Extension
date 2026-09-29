<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ → AI Assistant.
 *
 * Newcomer first. The screen opens with ONE status (is the chat panel connected to an AI, and which),
 * then ONE recommended way to connect, then where the panel appears. Everything technical (the model
 * choice, the local AI address, the development agent command, outside AI programs over MCP and their
 * connection passwords, the abilities list, reset) sits under "Advanced".
 *
 * The status is checked live where it can only be known in the browser: with the "Local AI on this
 * computer" backend, the page asks the same question the chat panel asks (window.upwAiAssistant.findLocal,
 * from panel.js) and shows the answer.
 *
 * @var FW_Extension_AI_Assistant $ext
 * @var array|null                $new_connection
 * @var array[]                   $notices
 */

$supported = $ext->is_supported();
$mode      = $supported ? FW_AI_MCP::mode() : 'off';
$endpoint  = $supported ? FW_AI_MCP::endpoint() : '';
$app_pw_ok = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
$ai_client = function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
$kit_url   = 'https://github.com/UnysonPlus/UnysonPlus-AI-Dev-Kit';
$guide_url = admin_url( 'admin.php?page=fw-site-converter' );

$form_open = static function ( $action, $extra = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( FW_Extension_AI_Assistant::get_page_url() ); ?>"<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<input type="hidden" name="upw_ai_action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( 'upw_ai_' . $action ); ?>
	<?php
};

// The form that was just submitted, so its section stays open after the reload.
$posted        = isset( $_POST['upw_ai_action'] ) ? sanitize_key( wp_unslash( $_POST['upw_ai_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$open_advanced = $new_connection || in_array( $posted, array( 'save_panel', 'save_mode', 'create_password', 'revoke_password', 'revoke_app', 'save_access', 'reset' ), true );
?>
<div class="wrap upw-ai-settings">

	<h1 class="upw-ai-title">
		<?php esc_html_e( 'AI Assistant', 'fw' ); ?>
		<span class="upw-ai-beta" title="<?php esc_attr_e( 'This extension is in its trial stage. Every AI change can be undone, but keep backups of anything important.', 'fw' ); ?>"><?php esc_html_e( 'Beta', 'fw' ); ?></span>
	</h1>
	<p class="upw-ai-lede"><?php esc_html_e( 'Chat with an AI that builds and edits your pages. Open it from "AI Assistant" in the top bar on any admin screen, or from the page builder. Every change it makes can be undone.', 'fw' ); ?></p>

	<?php foreach ( (array) $notices as $n ) : ?>
		<div class="notice notice-<?php echo esc_attr( $n[0] ); ?> is-dismissible"><p><?php echo esc_html( $n[1] ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( ! $supported ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			printf(
				/* translators: %s: WordPress version */
				esc_html__( 'The AI Assistant needs the WordPress Abilities API (WordPress 6.9 or newer). This site runs WordPress %s.', 'fw' ),
				esc_html( get_bloginfo( 'version' ) )
			);
			?>
		</p></div>
	</div>
		<?php
		return;
	endif;

	$backend  = FW_AI_Panel::backend();
	$pref     = (string) get_option( FW_AI_Panel::OPTION_BACKEND, 'auto' );
	$local_cmd = (string) get_option( FW_AI_Panel::OPTION_LOCAL_CMD, '' );

	// What the server already knows. The browser backend is only known in the browser: "checking".
	if ( $backend === 'wp' ) {
		$state  = 'ready';
		$title  = __( 'Ready', 'fw' );
		$detail = __( 'The assistant answers with your AI provider, set up under Settings, Connectors. Each request is billed by that provider.', 'fw' );
	} elseif ( $backend === 'local' ) {
		$state  = 'ready';
		$title  = __( 'Ready', 'fw' );
		$detail = stripos( $local_cmd, 'claude' ) !== false
			? __( 'The assistant answers with Claude, through Claude Code on this computer (development site).', 'fw' )
			: __( 'The assistant answers with the AI agent command set up under Advanced (development site).', 'fw' );
	} elseif ( $backend === 'browser' ) {
		$state  = 'checking';
		$title  = __( 'Checking…', 'fw' );
		$detail = __( 'Looking for the AI Dev Kit on this computer.', 'fw' );
	} else {
		$state  = 'off';
		$title  = __( 'Turned off', 'fw' );
		$detail = __( 'The chat panel is hidden. Choose an AI model under Advanced to turn it back on.', 'fw' );
	}
	$texts = array(
		'checking'   => __( 'Checking…', 'fw' ),
		'checkingD'  => __( 'Looking for the AI Dev Kit on this computer.', 'fw' ),
		'ready'      => __( 'Ready', 'fw' ),
		'claude'     => __( 'The assistant answers with Claude, your Claude subscription, through the AI Dev Kit on this computer.', 'fw' ),
		/* translators: %s: a local model name, e.g. qwen3:8b */
		'model'      => __( 'The assistant answers with %s, a free model running on this computer through the AI Dev Kit. It is slower and less capable than Claude, so ask for one section or change at a time.', 'fw' ),
		'none'       => __( 'Not connected yet', 'fw' ),
		'noneD'      => __( 'The chat panel cannot find an AI. Follow the steps below; it takes a few minutes, once.', 'fw' ),
		'noPanel'    => __( 'Reload this page, then check again.', 'fw' ),
	);
	?>

	<section class="upw-ai-status" data-state="<?php echo esc_attr( $state ); ?>" data-backend="<?php echo esc_attr( $backend ); ?>"
		data-texts="<?php echo esc_attr( wp_json_encode( $texts ) ); ?>" aria-labelledby="upw-ai-status-title">
		<span class="upw-ai-status__dot" aria-hidden="true"></span>
		<div class="upw-ai-status__body" role="status" aria-live="polite">
			<h2 class="upw-ai-status__title" id="upw-ai-status-title"><?php echo esc_html( $title ); ?></h2>
			<p class="upw-ai-status__detail"><?php echo esc_html( $detail ); ?></p>
		</div>
		<div class="upw-ai-status__actions">
			<button type="button" class="button button-primary" data-upw-open<?php echo $state === 'ready' ? '' : ' hidden'; ?>><?php esc_html_e( 'Open the AI Assistant', 'fw' ); ?></button>
			<button type="button" class="button" data-upw-recheck<?php echo $backend === 'browser' ? '' : ' hidden'; ?>><?php esc_html_e( 'Check again', 'fw' ); ?></button>
		</div>
	</section>

	<p class="upw-ai-history-link">
		<?php
		printf(
			/* translators: %s: link to the AI Changes screen */
			esc_html__( 'Every change the AI makes is saved first and can be undone: see %s.', 'fw' ),
			'<a href="' . esc_url( FW_AI_Changes::url() ) . '">' . esc_html__( 'AI Changes', 'fw' ) . '</a>'
		);
		?>
	</p>

	<details class="upw-ai-section upw-ai-connect"<?php echo $state === 'ready' ? '' : ' open'; ?>>
		<summary><h2><?php echo $state === 'ready' ? esc_html__( 'Other ways to connect an AI', 'fw' ) : esc_html__( 'Connect an AI', 'fw' ); ?></h2></summary>

		<div class="upw-ai-path">
			<h3><?php esc_html_e( 'Use your Claude subscription, or free AI, with the AI Dev Kit', 'fw' ); ?> <span class="upw-ai-tag"><?php esc_html_e( 'Recommended', 'fw' ); ?></span></h3>
			<p class="upw-ai-muted"><?php esc_html_e( 'A small app you run on this computer. Your browser talks to it directly, so it works on any site, including a hosted one, and nothing goes through a third party.', 'fw' ); ?></p>
			<ol class="upw-ai-steps">
				<li>
					<strong><?php esc_html_e( 'Get the AI Dev Kit and start it.', 'fw' ); ?></strong>
					<?php
					printf(
						/* translators: 1: download link, 2: setup guide link */
						esc_html__( 'Download it from %1$s, then run start-converter.bat (Windows) or start-converter.command (macOS) and leave the window open. First time? The %2$s walks through it.', 'fw' ),
						'<a href="' . esc_url( $kit_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'GitHub', 'fw' ) . '</a>',
						'<a href="' . esc_url( $guide_url ) . '">' . esc_html__( 'Site Converter setup guide', 'fw' ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Choose the AI it uses.', 'fw' ); ?></strong>
					<?php esc_html_e( 'With a Claude subscription: install Claude Code and type claude in a terminal once to sign in. Without one: open the kit\'s dashboard (it opens by itself) and download the free Qwen3 8B model.', 'fw' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Come back and check.', 'fw' ); ?></strong>
					<?php esc_html_e( 'Press Check again above. The status turns green and names the AI it found. Use Chrome, Edge or Firefox, and allow it if the browser asks about devices on your network.', 'fw' ); ?>
				</li>
			</ol>
			<?php if ( $backend !== 'browser' && $backend !== '' ) : ?>
				<p class="upw-ai-note"><?php esc_html_e( 'This site uses another AI right now, so the kit is only used if you choose "Local AI on this computer" under Advanced.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $ai_client ) : ?>
			<div class="upw-ai-path">
				<h3><?php esc_html_e( 'Or use an AI provider key', 'fw' ); ?></h3>
				<p class="upw-ai-muted">
					<?php
					printf(
						/* translators: %s: link to the Connectors screen */
						esc_html__( 'Add a key from an AI provider under %s and the assistant uses it automatically. Each request is billed by the provider.', 'fw' ),
						'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings, Connectors', 'fw' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>
	</details>

	<section class="upw-ai-section">
		<h2><?php esc_html_e( 'Where the assistant appears', 'fw' ); ?></h2>
		<?php $form_open( 'save_panel', ' class="upw-ai-inline-form"' ); ?>
			<label for="upw-ai-panel-position"><?php esc_html_e( 'Button and panel position', 'fw' ); ?></label>
			<?php $panel_pos = FW_AI_Panel::position(); ?>
			<select id="upw-ai-panel-position" name="panel_position">
				<option value="bottom-right" <?php selected( $panel_pos, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'fw' ); ?></option>
				<option value="bottom-left" <?php selected( $panel_pos, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'fw' ); ?></option>
				<option value="beside-sidebar" <?php selected( $panel_pos, 'beside-sidebar' ); ?>><?php esc_html_e( 'Beside the sidebar (keeps the Publish box clear)', 'fw' ); ?></option>
			</select>
			<?php submit_button( __( 'Save position', 'fw' ), 'secondary', 'submit', false ); ?>
		</form>
	</section>

	<details class="upw-ai-section upw-ai-advanced"<?php echo $open_advanced ? ' open' : ''; ?>>
		<summary><h2><?php esc_html_e( 'Advanced', 'fw' ); ?></h2> <span class="upw-ai-muted"><?php esc_html_e( 'AI model choice, outside AI programs, what the AI can do, reset. You do not need any of this to use the chat panel.', 'fw' ); ?></span></summary>

		<?php
		$b_url    = (string) get_option( FW_AI_Panel::OPTION_BROWSER_URL, '' );
		$b_model  = (string) get_option( FW_AI_Panel::OPTION_BROWSER_MODEL, '' );
		$is_local = FW_AI_MCP::is_local_host();
		?>
		<div class="upw-ai-sub">
			<h3><?php esc_html_e( 'AI model', 'fw' ); ?></h3>
			<?php $form_open( 'save_panel' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="upw-ai-panel-backend"><?php esc_html_e( 'Which AI answers', 'fw' ); ?></label></th>
						<td>
							<select id="upw-ai-panel-backend" name="panel_backend">
								<option value="auto" <?php selected( $pref, 'auto' ); ?>><?php esc_html_e( 'Automatic (recommended)', 'fw' ); ?></option>
								<option value="browser" <?php selected( $pref, 'browser' ); ?>><?php esc_html_e( 'Local AI on this computer (AI Dev Kit or Ollama)', 'fw' ); ?></option>
								<option value="wp" <?php selected( $pref, 'wp' ); ?>><?php esc_html_e( 'AI provider key (Settings, Connectors)', 'fw' ); ?></option>
								<?php if ( $is_local ) : ?>
									<option value="local" <?php selected( $pref, 'local' ); ?>><?php esc_html_e( 'Agent command (development sites only)', 'fw' ); ?></option>
								<?php endif; ?>
								<option value="off" <?php selected( $pref, 'off' ); ?>><?php esc_html_e( 'Off (hide the chat panel)', 'fw' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Automatic uses a provider key when one is set, then the agent command on a development site, and otherwise the AI Dev Kit on the computer of whoever opens the panel (Claude when it is signed in there, else its free model).', 'fw' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upw-ai-browser-url"><?php esc_html_e( 'Local AI address', 'fw' ); ?></label></th>
						<td>
							<input type="url" id="upw-ai-browser-url" name="browser_url" class="regular-text code" value="<?php echo esc_attr( $b_url ); ?>" placeholder="<?php echo esc_attr( FW_AI_Panel::BROWSER_URL_DEFAULT ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty for the AI Dev Kit. To use Ollama without the kit, enter http://localhost:11434 and allow this site in Ollama\'s OLLAMA_ORIGINS setting.', 'fw' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upw-ai-browser-model"><?php esc_html_e( 'Local model', 'fw' ); ?></label></th>
						<td>
							<input type="text" id="upw-ai-browser-model" name="browser_model" class="regular-text code" value="<?php echo esc_attr( $b_model ); ?>" placeholder="<?php esc_attr_e( 'Leave empty for the kit\'s choice', 'fw' ); ?>">
							<p class="description"><?php esc_html_e( 'Only for the free local model, and only if you want a specific one, such as qwen3:8b.', 'fw' ); ?></p>
						</td>
					</tr>
					<?php if ( $is_local ) : ?>
						<tr>
							<th scope="row"><label for="upw-ai-local-cmd"><?php esc_html_e( 'Agent command', 'fw' ); ?></label></th>
							<td>
								<input type="text" id="upw-ai-local-cmd" name="local_cmd" class="large-text code" value="<?php echo esc_attr( $local_cmd ); ?>"
									placeholder="<?php esc_attr_e( 'your-agent --mcp-config {mcp_config} < {prompt_file}', 'fw' ); ?>">
								<p class="description"><?php esc_html_e( 'Development sites only (localhost, *.local, *.test): a command-line AI agent the web server runs in the background. {mcp_config} is a one-off MCP config pointing at this site; {prompt_file} holds the instructions and request. The command\'s output becomes the reply.', 'fw' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<p><?php submit_button( __( 'Save AI model settings', 'fw' ), 'secondary', 'submit', false ); ?></p>
			</form>
		</div>

		<div class="upw-ai-sub" id="upw-ai-outside">
			<h3><?php esc_html_e( 'Outside AI programs', 'fw' ); ?></h3>
			<p><?php esc_html_e( 'Lets an AI program outside WordPress, such as Claude Code in a terminal or Claude Desktop, work on this site directly over MCP. You give it instructions in that program. This is separate from the chat panel, which does not need it.', 'fw' ); ?></p>

			<?php if ( $new_connection ) : ?>
				<?php
				$config = array(
					'mcpServers' => array(
						'unysonplus' => array(
							'type'    => 'http',
							'url'     => $endpoint,
							'headers' => array( 'Authorization' => $new_connection['header'] ),
						),
					),
				);
				?>
				<div class="upw-ai-created">
					<h4><?php esc_html_e( 'Connection password created. Copy these details now.', 'fw' ); ?></h4>
					<p><strong><?php esc_html_e( 'This is the only time the password is shown.', 'fw' ); ?></strong>
						<?php esc_html_e( 'Anyone with it can act on this site as you, so keep it private, like your login password.', 'fw' ); ?></p>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Server URL', 'fw' ); ?></th>
							<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select()"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Username', 'fw' ); ?></th>
							<td><input type="text" class="regular-text code" readonly value="<?php echo esc_attr( $new_connection['user'] ); ?>" onclick="this.select()"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Password', 'fw' ); ?></th>
							<td><input type="text" class="regular-text code" readonly value="<?php echo esc_attr( $new_connection['password'] ); ?>" onclick="this.select()"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Authorization header', 'fw' ); ?></th>
							<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $new_connection['header'] ); ?>" onclick="this.select()"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'MCP client config (JSON)', 'fw' ); ?></th>
							<td>
								<textarea class="large-text code" rows="10" readonly onclick="this.select()"><?php echo esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Paste this into your AI program\'s MCP server settings, or add a remote HTTP MCP server with the URL and Authorization header above. "Last used" below fills in once the program connects.', 'fw' ); ?></p>
							</td></tr>
					</table>
				</div>
			<?php endif; ?>

			<?php $form_open( 'save_mode' ); ?>
				<fieldset class="upw-ai-radios">
					<legend><?php esc_html_e( 'Access for outside AI programs', 'fw' ); ?></legend>
					<label><input type="radio" name="mcp_mode" value="off" <?php checked( $mode, 'off' ); ?>>
						<strong><?php esc_html_e( 'Off', 'fw' ); ?></strong> <span class="upw-ai-muted"><?php esc_html_e( 'no outside program can connect', 'fw' ); ?></span></label>
					<label><input type="radio" name="mcp_mode" value="read" <?php checked( $mode, 'read' ); ?>>
						<strong><?php esc_html_e( 'Read only', 'fw' ); ?></strong> <span class="upw-ai-muted"><?php esc_html_e( 'it can look at pages and settings but change nothing', 'fw' ); ?></span></label>
					<label><input type="radio" name="mcp_mode" value="write" <?php checked( $mode, 'write' ); ?>>
						<strong><?php esc_html_e( 'Read and write', 'fw' ); ?></strong> <span class="upw-ai-muted"><?php esc_html_e( 'it can also create and edit pages (every change can be undone)', 'fw' ); ?></span></label>
				</fieldset>
				<p><?php submit_button( __( 'Save access', 'fw' ), 'secondary', 'submit', false ); ?>
					<span class="upw-ai-muted upw-ai-endpoint"><?php esc_html_e( 'Server URL:', 'fw' ); ?> <code><?php echo esc_html( $endpoint ); ?></code></span></p>
			</form>

			<?php if ( ! $app_pw_ok ) : ?>
				<p class="upw-ai-note"><?php esc_html_e( 'Outside programs sign in with an Application Password, and WordPress only offers those over HTTPS (or on a local development site). Serve this site over HTTPS to connect one.', 'fw' ); ?></p>
			<?php else : ?>
				<?php $form_open( 'create_password', ' class="upw-ai-inline-form"' ); ?>
					<label for="upw-ai-agent-label"><?php esc_html_e( 'Name this connection (optional)', 'fw' ); ?></label>
					<input type="text" id="upw-ai-agent-label" name="agent_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Claude Code on my laptop', 'fw' ); ?>" maxlength="60">
					<?php submit_button( __( 'Create a connection password', 'fw' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php $connections = $ext->get_connections(); ?>
			<?php if ( $connections ) : ?>
				<table class="widefat striped upw-ai-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Your connection passwords', 'fw' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Connection', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last used', 'fw' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'fw' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $connections as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['name'] ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $c['created'] ) ); ?></td>
							<td><?php echo $c['last_used'] ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $c['last_used'] ) ) : esc_html__( 'Never', 'fw' ); ?></td>
							<td class="upw-ai-table__action">
								<?php $form_open( 'revoke_password' ); ?>
									<input type="hidden" name="uuid" value="<?php echo esc_attr( $c['uuid'] ); ?>">
									<button type="submit" class="button button-link-delete"
										onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Revoke this connection? The program using it is signed out immediately.', 'fw' ) ) ); ?> )"><?php esc_html_e( 'Revoke', 'fw' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php $apps = FW_AI_OAuth::apps_for_user( get_current_user_id() ); ?>
			<h4><?php esc_html_e( 'Apps signed in with your account', 'fw' ); ?></h4>
			<p class="upw-ai-muted"><?php esc_html_e( 'AI apps that support web sign-in connect with just the Server URL above: they open a page here where you choose Allow, with no password to copy. They stay signed in for up to 30 days of inactivity.', 'fw' ); ?></p>
			<?php if ( $apps ) : ?>
				<table class="widefat striped upw-ai-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Apps signed in with your account', 'fw' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'App', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Access', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Signed in', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last used', 'fw' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'fw' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $apps as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['name'] ); ?></td>
							<td><?php echo $a['scope'] === 'mcp:read' ? esc_html__( 'Read only', 'fw' ) : esc_html__( 'Read and write', 'fw' ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $a['since'] ) ); ?></td>
							<td><?php echo $a['last'] ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $a['last'] ) ) : esc_html__( 'Never', 'fw' ); ?></td>
							<td class="upw-ai-table__action">
								<?php $form_open( 'revoke_app' ); ?>
									<input type="hidden" name="client" value="<?php echo esc_attr( $a['client'] ); ?>">
									<button type="submit" class="button button-link-delete"
										onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Sign this app out? It stops working on this site immediately.', 'fw' ) ) ); ?> )"><?php esc_html_e( 'Sign out', 'fw' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="upw-ai-muted"><?php esc_html_e( 'None yet.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="upw-ai-sub" id="upw-ai-access">
			<h3><?php esc_html_e( 'Who can use it', 'fw' ); ?></h3>
			<?php $chosen = FW_AI_Access::roles(); ?>
			<p><?php esc_html_e( 'Choose the roles that may use the AI Assistant: the chat in the builder and on admin screens, and connecting outside AI programs. Leave all unticked to let everyone who can edit content use it. Administrators can always use it.', 'fw' ); ?></p>
			<?php $form_open( 'save_access' ); ?>
				<fieldset class="upw-ai-radios">
					<legend class="screen-reader-text"><?php esc_html_e( 'Roles that may use the AI Assistant', 'fw' ); ?></legend>
					<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
						<?php if ( $slug === 'administrator' ) { continue; } ?>
						<label><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $chosen, true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p><label><input type="checkbox" name="usage_log" value="1" <?php checked( FW_AI_Access::logging() ); ?>>
					<?php esc_html_e( 'Keep a usage log (who asked what, the newest 500 requests)', 'fw' ); ?></label>
					— <a href="<?php echo esc_url( FW_AI_Access::url() ); ?>"><?php esc_html_e( 'see AI Usage', 'fw' ); ?></a></p>
				<p><?php submit_button( __( 'Save access', 'fw' ), 'secondary', 'submit', false ); ?></p>
			</form>
		</div>

		<?php
		$abilities = array_filter( wp_get_abilities(), static function ( $a ) {
			return strpos( $a->get_name(), 'unysonplus/' ) === 0;
		} );
		?>
		<div class="upw-ai-sub">
			<details class="upw-ai-abilities">
				<summary><h3>
					<?php
					/* translators: %d: number of abilities */
					printf( esc_html__( 'What the AI can do (%d abilities)', 'fw' ), count( $abilities ) );
					?>
				</h3></summary>
				<p class="upw-ai-muted"><?php esc_html_e( 'This list comes from your active extensions; turning an extension on adds its abilities. Nothing here needs setting. For outside programs in Read only mode, only the Read rows are offered.', 'fw' ); ?></p>
				<table class="widefat striped upw-ai-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Ability', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it does', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind', 'fw' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $abilities as $a ) : ?>
						<?php $ann = (array) $a->get_meta_item( 'annotations', array() ); ?>
						<tr>
							<td><strong><?php echo esc_html( $a->get_label() ); ?></strong><br><code><?php echo esc_html( str_replace( '-', '_', substr( $a->get_name(), 11 ) ) ); ?></code></td>
							<td><?php echo esc_html( wp_html_excerpt( $a->get_description(), 180, '…' ) ); ?></td>
							<td><?php echo ! empty( $ann['readonly'] ) ? esc_html__( 'Read', 'fw' ) : ( ! empty( $ann['destructive'] ) ? esc_html__( 'Write (can remove content)', 'fw' ) : esc_html__( 'Write', 'fw' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</div>

		<div class="upw-ai-sub upw-ai-reset">
			<h3><?php esc_html_e( 'Reset', 'fw' ); ?></h3>
			<p><?php esc_html_e( 'Puts every setting on this screen back to how a fresh install has it: Automatic AI model, the default local AI address, the panel at the bottom right, no agent command, and outside AI programs switched off. Connection passwords stay until you revoke them above, and every change the AI made to your site stays (undo those from the pages\' revisions).', 'fw' ); ?></p>
			<?php $form_open( 'reset', ' class="upw-ai-inline-form"' ); ?>
				<label><input type="checkbox" name="clear_chats" value="1"> <?php esc_html_e( 'Also clear my saved conversations', 'fw' ); ?></label>
				<button type="submit" class="button" onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Reset the AI Assistant settings to their defaults?', 'fw' ) ) ); ?> )"><?php esc_html_e( 'Reset AI Assistant settings', 'fw' ); ?></button>
			</form>
		</div>
	</details>

</div>

<style>
	.upw-ai-settings {
		--ai-panel: var(--upa-panel, #fff);
		--ai-panel2: var(--upa-panel2, #f6f7f7);
		--ai-text: var(--upa-text, #1d2327);
		--ai-text2: var(--upa-text2, #3c434a);
		--ai-muted: var(--upa-text3, #50575e);
		--ai-border: var(--upa-border, #dcdcde);
		--ai-radius: var(--upa-radius-lg, 8px);
		--ai-ok: var(--upa-green, #00a32a);
		--ai-ok-text: var(--upa-green-text, #00701a);
		--ai-ok-soft: var(--upa-green-soft, #edfaef);
		--ai-warn: var(--upa-amber, #dba617);
		--ai-accent-soft: var(--upa-accent-soft, #f0f6fc);
		max-width: 60rem;
	}
	.upw-ai-settings .upw-ai-title { display: flex; align-items: center; gap: .5rem; }
	.upw-ai-settings .upw-ai-beta {
		padding: .1rem .5rem; border-radius: 4px; background: #f0b849; color: #1d2327;
		font-size: 12px; font-weight: 600; line-height: 1.6; text-transform: uppercase;
	}
	.upw-ai-settings .upw-ai-lede { margin: .25rem 0 1.25rem; max-width: 65ch; font-size: 14px; color: var(--ai-text2); }
	.upw-ai-settings .upw-ai-muted { color: var(--ai-muted); }
	.upw-ai-settings .upw-ai-history-link { margin: -.25rem 0 1rem; color: var(--ai-text2); }
	.upw-ai-settings .upw-ai-note { margin: .75rem 0 0; padding: .6rem .8rem; border-radius: 6px; background: var(--ai-panel2); color: var(--ai-text2); }

	/* Status: the one thing a newcomer needs first. The dot is decoration; the title says it in words. */
	.upw-ai-settings .upw-ai-status {
		display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 1rem;
		margin: 0 0 1rem; padding: 1.1rem 1.25rem;
		background: var(--ai-panel); border: 1px solid var(--ai-border); border-radius: var(--ai-radius);
		transition: background-color .2s ease-out, border-color .2s ease-out;
	}
	.upw-ai-settings .upw-ai-status__dot {
		width: 12px; height: 12px; border-radius: 50%; background: var(--ai-muted);
		box-shadow: 0 0 0 4px color-mix(in srgb, var(--ai-muted) 18%, transparent);
	}
	.upw-ai-settings .upw-ai-status[data-state="ready"] { background: var(--ai-ok-soft); border-color: color-mix(in srgb, var(--ai-ok) 35%, var(--ai-border)); }
	.upw-ai-settings .upw-ai-status[data-state="ready"] .upw-ai-status__dot { background: var(--ai-ok); box-shadow: 0 0 0 4px color-mix(in srgb, var(--ai-ok) 20%, transparent); }
	.upw-ai-settings .upw-ai-status[data-state="none"] .upw-ai-status__dot { background: var(--ai-warn); box-shadow: 0 0 0 4px color-mix(in srgb, var(--ai-warn) 22%, transparent); }
	.upw-ai-settings .upw-ai-status[data-state="checking"] .upw-ai-status__dot { animation: upw-ai-pulse 1.2s ease-in-out infinite; }
	.upw-ai-settings .upw-ai-status__title { margin: 0; padding: 0; font-size: 16px; font-weight: 600; color: var(--ai-text); }
	.upw-ai-settings .upw-ai-status[data-state="ready"] .upw-ai-status__title { color: var(--ai-ok-text); }
	.upw-ai-settings .upw-ai-status__detail { margin: .2rem 0 0; max-width: 65ch; color: var(--ai-text2); }
	.upw-ai-settings .upw-ai-status__actions { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }
	.upw-ai-settings [hidden] { display: none !important; }
	@keyframes upw-ai-pulse { 50% { opacity: .35; } }
	@media (prefers-reduced-motion: reduce) {
		.upw-ai-settings .upw-ai-status { transition: none; }
		.upw-ai-settings .upw-ai-status[data-state="checking"] .upw-ai-status__dot { animation: none; }
	}

	/* Sections: one surface each, no nested cards. */
	.upw-ai-settings .upw-ai-section {
		margin: 0 0 1rem; padding: 1rem 1.25rem;
		background: var(--ai-panel); border: 1px solid var(--ai-border); border-radius: var(--ai-radius);
	}
	.upw-ai-settings .upw-ai-section h2 { margin: 0; padding: 0; font-size: 15px; font-weight: 600; }
	.upw-ai-settings section.upw-ai-section h2 { margin-bottom: .75rem; }
	.upw-ai-settings details > summary { cursor: pointer; list-style: none; display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .75rem; }
	.upw-ai-settings details > summary::-webkit-details-marker { display: none; }
	.upw-ai-settings details > summary::before {
		content: ""; align-self: center; width: 7px; height: 7px; margin-right: .1rem;
		border-right: 2px solid var(--ai-muted); border-bottom: 2px solid var(--ai-muted);
		transform: rotate(-45deg); transition: transform .15s ease-out;
	}
	.upw-ai-settings details[open] > summary::before { transform: rotate(45deg); }
	.upw-ai-settings details > summary:focus-visible { outline: 2px solid var(--upa-accent, #2271b1); outline-offset: 3px; border-radius: 4px; }
	.upw-ai-settings details[open] > summary { margin-bottom: .75rem; }
	.upw-ai-settings .upw-ai-advanced > summary .upw-ai-muted { flex-basis: 100%; padding-left: 1.1rem; font-size: 13px; }

	.upw-ai-settings .upw-ai-path + .upw-ai-path { margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid var(--ai-border); }
	.upw-ai-settings .upw-ai-path h3 { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: 0 0 .3rem; font-size: 14px; }
	.upw-ai-settings .upw-ai-path > p { margin: 0 0 .6rem; max-width: 70ch; }
	.upw-ai-settings .upw-ai-tag { padding: .05rem .45rem; border-radius: 999px; background: var(--ai-ok-soft); color: var(--ai-ok-text); font-size: 12px; font-weight: 600; }
	.upw-ai-settings .upw-ai-steps { margin: .5rem 0 0 1.4rem; max-width: 70ch; }
	.upw-ai-settings .upw-ai-steps li { margin: 0 0 .55rem; padding-left: .2rem; line-height: 1.55; }
	.upw-ai-settings .upw-ai-steps li::marker { font-weight: 600; color: var(--ai-text); }

	.upw-ai-settings .upw-ai-inline-form { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; margin: .5rem 0; }
	.upw-ai-settings .upw-ai-sub + .upw-ai-sub { margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid var(--ai-border); }
	.upw-ai-settings .upw-ai-sub h3 { margin: 0 0 .4rem; font-size: 14px; }
	.upw-ai-settings .upw-ai-sub > p { max-width: 70ch; }
	.upw-ai-settings .upw-ai-sub .form-table { margin-top: 0; }
	.upw-ai-settings .upw-ai-radios { margin: .25rem 0; }
	.upw-ai-settings .upw-ai-radios legend { font-weight: 600; margin-bottom: .35rem; }
	.upw-ai-settings .upw-ai-radios label { display: block; margin: .35rem 0; }
	.upw-ai-settings .upw-ai-radios strong::after { content: ":"; margin-right: .2rem; }
	.upw-ai-settings .upw-ai-endpoint { margin-left: .75rem; }
	.upw-ai-settings .upw-ai-created { margin: .75rem 0 1rem; padding: .9rem 1rem; border: 1px solid color-mix(in srgb, var(--ai-ok) 40%, var(--ai-border)); border-radius: 6px; background: var(--ai-ok-soft); }
	.upw-ai-settings .upw-ai-created h4 { margin: 0 0 .4rem; font-size: 14px; }
	.upw-ai-settings .upw-ai-table { margin-top: .75rem; }
	.upw-ai-settings .upw-ai-table__action { text-align: right; }
	.upw-ai-settings .upw-ai-abilities > summary h3 { margin: 0; }

	@media (max-width: 782px) {
		.upw-ai-settings .upw-ai-status { grid-template-columns: auto 1fr; }
		.upw-ai-settings .upw-ai-status__actions { grid-column: 1 / -1; justify-content: flex-start; }
		.upw-ai-settings .upw-ai-endpoint { display: block; margin: .5rem 0 0; }
	}
</style>

<script>
( function () {
	var box = document.querySelector( '.upw-ai-settings .upw-ai-status' );
	if ( ! box ) { return; }
	var t = {};
	try { t = JSON.parse( box.getAttribute( 'data-texts' ) || '{}' ); } catch ( e ) {}
	var title = box.querySelector( '.upw-ai-status__title' );
	var detail = box.querySelector( '.upw-ai-status__detail' );
	var openBtn = box.querySelector( '[data-upw-open]' );
	var recheck = box.querySelector( '[data-upw-recheck]' );
	var connect = document.querySelector( '.upw-ai-settings .upw-ai-connect' );

	function show( state, heading, text ) {
		box.setAttribute( 'data-state', state );
		title.textContent = heading;
		detail.textContent = text;
		openBtn.hidden = state !== 'ready';
		if ( connect && state === 'none' ) { connect.open = true; }
	}

	// Only the browser backend has to be asked here; the server already knows the others.
	function check() {
		if ( box.getAttribute( 'data-backend' ) !== 'browser' ) { return; }
		var api = window.upwAiAssistant;
		if ( ! api || ! api.findLocal ) {
			show( 'none', t.none, t.noPanel );
			return;
		}
		show( 'checking', t.checking, t.checkingD );
		api.findLocal().then( function ( f ) {
			show( 'ready', t.ready, f.claude ? t.claude : String( t.model || '%s' ).replace( '%s', f.model ) );
		}, function () {
			show( 'none', t.none, t.noneD );
		} );
	}

	openBtn.addEventListener( 'click', function () {
		if ( window.upwAiAssistant && window.upwAiAssistant.open ) { window.upwAiAssistant.open(); }
	} );
	recheck.addEventListener( 'click', check );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', check );
	} else {
		check();
	}
}() );
</script>
