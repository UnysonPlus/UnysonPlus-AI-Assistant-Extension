<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'AI Assistant (Beta)', 'fw' );
$manifest['slug']        = 'unysonplus-ai-assistant';
$manifest['description'] = __(
	'Lets an AI model build and edit page-builder pages through safe, schema-checked actions registered with the WordPress Abilities API. Every change is validated against the real element options and snapshotted first, so it can be undone. Ships inactive — activate it here when you want it.',
	'fw'
);

$manifest['version']     = '1.0.1';
$manifest['display']     = true;
$manifest['standalone']  = true;

// Extension-card icon (resolved relative to this extension folder).
$manifest['thumbnail']   = 'thumbnail.svg';

/**
 * Requires the page builder: every ability reads or writes the page-builder tree and
 * validates it against the shortcodes' option schemas.
 */
$manifest['requirements'] = [
	'extensions' => [
		'shortcodes'   => [],
		'page-builder' => [],
	],
];

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-AI-Assistant-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-AI-Assistant-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '6.9'; // Abilities API (wp_register_ability) landed in 6.9

/**
 * Changelog
 * ---------
 * 1.0.1 - MCP access. A built-in MCP server (Streamable HTTP, JSON responses) at
 *         /wp-json/unysonplus-ai/v1/mcp serves the unysonplus abilities as MCP tools, so
 *         any MCP-capable AI agent can build and edit pages. Access is Off / Read-only /
 *         Read & write (option upw_ai_mcp_mode, default Off); agents sign in with an
 *         Application Password and act as that user. New screen Unyson+ > AI Assistant
 *         shows status, the access mode, and "Connect an agent", which creates a
 *         dedicated Application Password and shows the connection details once. On a
 *         plain-HTTP local development host (localhost, *.local, *.test) Application
 *         Passwords are enabled so an agent can connect; filter
 *         fw_ai_assistant_allow_local_app_passwords to opt out. Abilities also carry
 *         meta.mcp.public for the WordPress MCP adapter.
 *
 * 1.0.0 - First beta. Registers the unysonplus abilities with the WordPress Abilities
 *         API: site info, element catalog and option schemas, page outline, presets,
 *         published-content search, and create / insert / update / move / remove page
 *         items, each validated against the live element options and snapshotted as a
 *         revision first (list-revisions, undo).
 */
