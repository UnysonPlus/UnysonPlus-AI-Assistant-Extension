<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'AI Assistant (Beta)', 'fw' );
$manifest['slug']        = 'unysonplus-ai-assistant';
$manifest['description'] = __(
	'Lets an AI model build and edit page-builder pages through safe, schema-checked actions registered with the WordPress Abilities API — from an AI Assistant panel in the builder, or from any MCP-capable AI agent. Every change is validated against the real element options and snapshotted first, so it can be undone. Ships inactive — activate it here when you want it.',
	'fw'
);

$manifest['version']     = '1.0.8';
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
 * 1.0.8 - Panel position setting (AI Assistant settings → Builder assistant): bottom right
 *         (the new default), bottom left (measured clear of the wp-admin menu, whatever its
 *         width), or beside the sidebar (the previous behaviour — anchored left of the
 *         builder's right-hand column so the Publish box stays clear). Option
 *         upw_ai_panel_position. Also: the input box no longer turns white under the admin
 *         skin, and quotes in the placeholder no longer cut it short.
 *
 * 1.0.7 -Toolkit + checks for the extension batch. fw_ai_snapshot() also covers
 *         created_menus, post_fields, post_terms; undo restores untrashed posts to
 *         their previous status and clears the framework cache. A 'permission'
 *         string is always a capability. The validator accepts Global Section
 *         references and [snippet] at the page root; render-check treats special
 *         builder items (contact forms) as elements and skips snippet references.
 *         The site-wide assistant and MCP instructions now follow the site-build
 *         protocol order (colours, typography, container width, presets, header /
 *         footer with menus, then pages, then the ship check).
 *
 * 1.0.6 - Site-wide assistant + extension toolkit. An "AI Assistant" item in the
 *         admin bar opens a chat on every admin screen with every ability (new
 *         pages as drafts, Theme Settings live and undoable), listing each change
 *         with a link. fw_ai_register_ability() + fw_ai_snapshot() let any extension
 *         register its own abilities (hook fw_ai_assistant_register_abilities),
 *         with generic undo (list-changes / undo-change, incl. created posts).
 *         Theme Settings writes now honour the Site Converter's manual-edit
 *         fingerprints (hand-edited groups are skipped unless force), reject values
 *         of the wrong shape and roll back if the theme cannot build its CSS.
 *         Validation now checks inside list / nested options (accordion items …)
 *         and describe-element shows their keys. Creating a connection switches MCP
 *         access on; the builder panel keeps clear of the Publish box; new progress
 *         indicator; replies render bold / lists / links.
 *
 * 1.0.5 - Site-building abilities. describe-theme-settings (index of every Theme Settings
 *         option by section, or one option's schema + current value), update-theme-settings
 *         (validated against the live settings schema, object values merged), save-preset
 *         (create / update one named button, box, section, colour or typography preset),
 *         list-settings-revisions + undo-theme-settings (every change snapshotted first),
 *         list-templates + apply-template (Template Library, installed on demand, and the
 *         builder's saved templates; also in the builder panel), and convert-url (the Site
 *         Converter, rendered by the capture service when it runs; refuses without an
 *         explicit confirm). Settings writes rebuild the theme's generated CSS directly
 *         instead of firing the settings-saved hook, like the Site Converter. site-info now
 *         flags a child theme whose stylesheet can override Theme Settings.
 *
 * 1.0.4 - Chat AI channel for visitors. With the Chat extension active, Theme Settings >
 *         Site-wide UX > Chat Button gains an "AI assistant (Beta)" channel that answers
 *         visitors from the site's PUBLISHED pages. The visitor's model gets no tools: the
 *         server picks the relevant pages itself (keyword search, published and not
 *         password-protected, minus the owner's exclusions) and passes their text as
 *         quoted data; the model can only answer. It cites the pages it used, and when it
 *         cannot answer or a person is needed it hands off to the site's other chat
 *         channels with the question pre-filled. Guarded by a nonce, a per-visitor rate
 *         limit and a site-wide daily cap. Uses the WordPress AI Client, or the local
 *         agent command on a development host. Also: the local agent runner now sweeps
 *         abandoned run folders and temporary passwords.
 *
 * 1.0.3 - Render check + verify loop. New ability unysonplus/render-check renders the
 *         page (the sandboxed tree inside the builder panel) element by element and
 *         reports, with item paths, errors (renders nothing, throws, PHP messages, raw
 *         shortcode text, missing image files) and warnings (a main icon / image left
 *         empty, default text still showing, links to "#", bare empty layout items,
 *         heading-level problems). The panel and MCP instructions tell the model to run
 *         it and fix what it reports, and every panel reply that changed the page carries
 *         a check result. Filter fw_ai_assistant_visual_atts extends the "main visual"
 *         options per element.
 *
 * 1.0.2 - Builder assistant panel. An "AI Assistant (Beta)" button in the backend page
 *         builder and the Live Page Editor opens a chat panel. The AI edits the tree the
 *         person has OPEN (unsaved edits included) through a sandboxed copy of it, and the
 *         result is applied as ONE step on the host's own undo history, unsaved until
 *         Update / Save; each reply also offers "Undo this change". Two model backends:
 *         the WordPress AI Client (a provider key under Settings > Connectors, WP 7+), or,
 *         on a local development host only, a command-line agent installed on the machine
 *         (command template with {mcp_config} / {prompt_file} placeholders) that the web
 *         server runs in the background against the MCP endpoint with a one-off session
 *         header and a temporary Application Password, both removed when it finishes.
 *
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
