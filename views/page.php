<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ → AI Assistant.
 *
 * Status, MCP access mode, "connect an agent" (creates an Application Password and shows the
 * connection details ONCE), the list of connections this screen created, and the abilities
 * an agent can use.
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

$form_open = static function ( $action ) {
	?>
	<form method="post" action="<?php echo esc_url( FW_Extension_AI_Assistant::get_page_url() ); ?>">
		<input type="hidden" name="upw_ai_action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( 'upw_ai_' . $action ); ?>
	<?php
};

$status_row = static function ( $ok, $label, $detail ) {
	?>
	<tr>
		<th scope="row"><?php echo esc_html( $label ); ?></th>
		<td>
			<span class="dashicons <?php echo $ok ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="color:<?php echo $ok ? '#00a32a' : '#dba617'; ?>"></span>
			<?php echo wp_kses_post( $detail ); ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap fw-ext-ai-assistant">

	<h1>
		<?php esc_html_e( 'AI Assistant', 'fw' ); ?>
		<span style="display:inline-block;vertical-align:middle;margin-left:.5em;padding:.15em .55em;border-radius:3px;background:#f0b849;color:#1d2327;font-size:12px;font-weight:600;line-height:1.6;letter-spacing:.02em;text-transform:uppercase;"
		      title="<?php esc_attr_e( 'This extension is in its trial stage. Every AI change can be undone, but keep backups of anything important.', 'fw' ); ?>">
			<?php esc_html_e( 'Beta', 'fw' ); ?>
		</span>
	</h1>

	<p class="description" style="margin:-.4em 0 1.2em;max-width:52em">
		<?php esc_html_e( 'Lets an AI agent read this site and build or edit page-builder pages through safe, schema-checked actions. Every change is validated against the real element options and saved as a revision first, so it can be undone.', 'fw' ); ?>
	</p>

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
	?>

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
		<div class="card" style="border-left:4px solid #00a32a;max-width:60em">
			<div>
				<h2 style="padding-left:0"><?php esc_html_e( 'Connection created — copy these details now', 'fw' ); ?></h2>
				<p><strong><?php esc_html_e( 'This is the only time the password is shown.', 'fw' ); ?></strong>
					<?php esc_html_e( 'Anyone with it can act on this site as you, so treat it like your login password.', 'fw' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Paste into your AI agent\'s MCP server settings, or add a remote HTTP MCP server with the URL and Authorization header above.', 'fw' ); ?></p>
						</td></tr>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width:60em">
		<div>
			<h2 style="padding-left:0"><?php esc_html_e( 'Status', 'fw' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$status_row( true, __( 'Abilities', 'fw' ), sprintf(
					/* translators: %d: number of abilities */
					esc_html__( '%d UnysonPlus abilities registered.', 'fw' ),
					count( array_filter( array_keys( wp_get_abilities() ), static function ( $n ) {
						return strpos( $n, 'unysonplus/' ) === 0;
					} ) )
				) );
				$status_row(
					$app_pw_ok,
					__( 'Application Passwords', 'fw' ),
					$app_pw_ok
						? ( is_ssl() || 'local' === wp_get_environment_type()
							? esc_html__( 'Available — agents can sign in.', 'fw' )
							: esc_html__( 'Available (enabled for this local development host).', 'fw' ) )
						: esc_html__( 'Not available. WordPress only offers them over HTTPS or on a site whose environment type is "local". Serve the site over HTTPS to connect an agent.', 'fw' )
				);
				$status_row(
					$mode !== 'off',
					__( 'MCP access', 'fw' ),
					$mode === 'off' ? esc_html__( 'Off — agents are refused.', 'fw' ) : ( $mode === 'read' ? esc_html__( 'Read-only.', 'fw' ) : esc_html__( 'Read & write.', 'fw' ) )
				);
				$wp_ready = FW_AI_Panel::wp_backend_ready();
				$status_row(
					$wp_ready,
					__( 'WordPress AI Client', 'fw' ),
					$wp_ready
						? esc_html__( 'Ready — a provider key is set under Settings → Connectors.', 'fw' )
						: ( $ai_client
							? sprintf(
								/* translators: %s: link to the Connectors screen */
								esc_html__( 'No provider key yet — add one under %s to use the builder assistant with it.', 'fw' ),
								'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings → Connectors', 'fw' ) . '</a>'
							)
							: esc_html__( 'Not available on this WordPress version (WordPress 7 or newer).', 'fw' ) )
				);
				$active_backend = FW_AI_Panel::backend();
				$status_row(
					$active_backend !== '',
					__( 'Builder assistant', 'fw' ),
					$active_backend === 'wp'
						? esc_html__( 'On — using the WordPress AI Client.', 'fw' )
						: ( $active_backend === 'local'
							? esc_html__( 'On — using the local agent command (development only).', 'fw' )
							: esc_html__( 'Not connected — see "Builder assistant" below.', 'fw' ) )
				);
				?>
			</table>
		</div>
	</div>

	<?php
	$panel_pref = (string) get_option( FW_AI_Panel::OPTION_BACKEND, 'auto' );
	$local_cmd  = (string) get_option( FW_AI_Panel::OPTION_LOCAL_CMD, '' );
	$panel_pos  = FW_AI_Panel::position();
	$is_local   = FW_AI_MCP::is_local_host();
	?>
	<div class="card" style="max-width:60em">
		<div>
			<h2 style="padding-left:0"><?php esc_html_e( 'Builder assistant', 'fw' ); ?></h2>
			<p><?php esc_html_e( 'An "AI Assistant" button in the page builder and the Live Page Editor. It edits the version you have open; nothing is saved until you press Update, and every AI change is one step on the builder\'s Undo.', 'fw' ); ?></p>
			<?php $form_open( 'save_panel' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="upw-ai-panel-backend"><?php esc_html_e( 'AI model', 'fw' ); ?></label></th>
						<td>
							<select id="upw-ai-panel-backend" name="panel_backend">
								<option value="auto" <?php selected( $panel_pref, 'auto' ); ?>><?php esc_html_e( 'Automatic — WordPress AI Client if a key is set, else the local agent', 'fw' ); ?></option>
								<option value="wp" <?php selected( $panel_pref, 'wp' ); ?>><?php esc_html_e( 'WordPress AI Client (Settings → Connectors)', 'fw' ); ?></option>
								<?php if ( $is_local ) : ?>
									<option value="local" <?php selected( $panel_pref, 'local' ); ?>><?php esc_html_e( 'Local agent command (development only)', 'fw' ); ?></option>
								<?php endif; ?>
								<option value="off" <?php selected( $panel_pref, 'off' ); ?>><?php esc_html_e( 'Off — hide the builder assistant', 'fw' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="upw-ai-panel-position"><?php esc_html_e( 'Panel position', 'fw' ); ?></label></th>
						<td>
							<select id="upw-ai-panel-position" name="panel_position">
								<option value="bottom-right" <?php selected( $panel_pos, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'fw' ); ?></option>
								<option value="bottom-left" <?php selected( $panel_pos, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'fw' ); ?></option>
								<option value="beside-sidebar" <?php selected( $panel_pos, 'beside-sidebar' ); ?>><?php esc_html_e( 'Beside the sidebar — keeps the Publish box clear in the page builder', 'fw' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Where the AI Assistant button and panel sit, in the page builder, the Live Page Editor and the rest of wp-admin.', 'fw' ); ?></p>
						</td>
					</tr>
					<?php if ( $is_local ) : ?>
						<tr>
							<th scope="row"><label for="upw-ai-local-cmd"><?php esc_html_e( 'Local agent command', 'fw' ); ?></label></th>
							<td>
								<input type="text" id="upw-ai-local-cmd" name="local_cmd" class="large-text code" value="<?php echo esc_attr( $local_cmd ); ?>"
									placeholder="<?php esc_attr_e( 'your-agent --mcp-config {mcp_config} < {prompt_file}', 'fw' ); ?>">
								<p class="description">
									<?php esc_html_e( 'For a development machine with no provider key: a command-line AI agent installed here that can use an MCP server config file. The web server runs it in the background as its own user.', 'fw' ); ?><br>
									<?php esc_html_e( 'Placeholders: {mcp_config} — path to a one-off MCP config pointing at this site; {prompt_file} — path to a text file with the instructions and request. The command\'s output becomes the reply.', 'fw' ); ?><br>
									<?php esc_html_e( 'Only shown and only used on a local development host (localhost, *.local, *.test).', 'fw' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<p><?php submit_button( __( 'Save', 'fw' ), 'secondary', 'submit', false ); ?></p>
			</form>
		</div>
	</div>

	<div class="card" style="max-width:60em">
		<div>
			<h2 style="padding-left:0"><?php esc_html_e( 'MCP access', 'fw' ); ?></h2>
			<p><?php esc_html_e( 'Connect any MCP-capable AI agent to this site. The agent acts as the WordPress user whose connection password it uses, so it can only do what that user can do.', 'fw' ); ?></p>
			<?php $form_open( 'save_mode' ); ?>
				<fieldset>
					<label style="display:block;margin:.4em 0"><input type="radio" name="mcp_mode" value="off" <?php checked( $mode, 'off' ); ?>>
						<strong><?php esc_html_e( 'Off', 'fw' ); ?></strong> — <?php esc_html_e( 'no agent can connect.', 'fw' ); ?></label>
					<label style="display:block;margin:.4em 0"><input type="radio" name="mcp_mode" value="read" <?php checked( $mode, 'read' ); ?>>
						<strong><?php esc_html_e( 'Read-only', 'fw' ); ?></strong> — <?php esc_html_e( 'agents can inspect pages, elements and presets but change nothing.', 'fw' ); ?></label>
					<label style="display:block;margin:.4em 0"><input type="radio" name="mcp_mode" value="write" <?php checked( $mode, 'write' ); ?>>
						<strong><?php esc_html_e( 'Read & write', 'fw' ); ?></strong> — <?php esc_html_e( 'agents can also create pages and edit their content (every change can be undone).', 'fw' ); ?></label>
				</fieldset>
				<p><?php submit_button( __( 'Save', 'fw' ), 'secondary', 'submit', false ); ?></p>
			</form>
			<p><strong><?php esc_html_e( 'Server URL:', 'fw' ); ?></strong> <code><?php echo esc_html( $endpoint ); ?></code></p>
		</div>
	</div>

	<div class="card" style="max-width:60em">
		<div>
			<h2 style="padding-left:0"><?php esc_html_e( 'Connect an agent', 'fw' ); ?></h2>
			<?php if ( ! $app_pw_ok ) : ?>
				<p><?php esc_html_e( 'Application Passwords are not available on this site, so an agent cannot sign in yet (see Status).', 'fw' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Creates a dedicated Application Password for your account and shows the connection details once. Create one per agent or device so each can be revoked on its own.', 'fw' ); ?></p>
				<?php $form_open( 'create_password' ); ?>
					<label for="upw-ai-agent-label"><?php esc_html_e( 'Label (optional)', 'fw' ); ?></label>
					<input type="text" id="upw-ai-agent-label" name="agent_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Work laptop', 'fw' ); ?>" maxlength="60">
					<?php submit_button( __( 'Create connection password', 'fw' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php $connections = $ext->get_connections(); ?>
			<?php if ( $connections ) : ?>
				<h3><?php esc_html_e( 'Your connections', 'fw' ); ?></h3>
				<table class="widefat striped" style="max-width:56em">
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Created', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'fw' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $connections as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['name'] ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $c['created'] ) ); ?></td>
							<td><?php echo $c['last_used'] ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $c['last_used'] ) ) : '—'; ?></td>
							<td style="text-align:right">
								<?php $form_open( 'revoke_password' ); ?>
									<input type="hidden" name="uuid" value="<?php echo esc_attr( $c['uuid'] ); ?>">
									<button type="submit" class="button button-link-delete"
										onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Revoke this connection? The agent using it will be signed out immediately.', 'fw' ) ) ); ?> )"><?php esc_html_e( 'Revoke', 'fw' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="card" style="max-width:60em">
		<div>
			<h2 style="padding-left:0"><?php esc_html_e( 'Abilities', 'fw' ); ?></h2>
			<p><?php esc_html_e( 'What a connected agent can do. In Read-only mode only the rows marked Read are offered.', 'fw' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Tool', 'fw' ); ?></th>
					<th><?php esc_html_e( 'What it does', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Kind', 'fw' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( wp_get_abilities() as $a ) : ?>
					<?php
					if ( strpos( $a->get_name(), 'unysonplus/' ) !== 0 ) {
						continue;
					}
					$ann = (array) $a->get_meta_item( 'annotations', array() );
					?>
					<tr>
						<td><code><?php echo esc_html( str_replace( '-', '_', substr( $a->get_name(), 11 ) ) ); ?></code></td>
						<td><strong><?php echo esc_html( $a->get_label() ); ?></strong><br><span class="description"><?php echo esc_html( wp_html_excerpt( $a->get_description(), 160, '…' ) ); ?></span></td>
						<td><?php echo ! empty( $ann['readonly'] ) ? esc_html__( 'Read', 'fw' ) : ( ! empty( $ann['destructive'] ) ? esc_html__( 'Write (destructive)', 'fw' ) : esc_html__( 'Write', 'fw' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

</div>
