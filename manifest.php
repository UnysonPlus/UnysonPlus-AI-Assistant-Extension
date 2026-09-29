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

$manifest['version']     = '1.0.30';
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
 * 1.0.29 - Chat visitor extras. Team hours (Chat Button → "AI assistant: team hours", lines such as
 *          "Mon-Fri 09:00-17:00" or "Sat 10am-2pm" in the site's time zone): the visitor model is told
 *          whether the team is in, and a hand-off while it is away carries a note saying when it is back
 *          (also on the daily-limit reply). Optional conversation log (off by default): questions and
 *          answers kept 30 days (option upw_ai_visitor_log, 1000 max) with a per-day anonymous tag
 *          instead of anything identifying, reviewed and cleared on Unyson+ → AI Usage, plus a suggested
 *          paragraph under Settings → Privacy while it is on. Monthly numbers (upw_ai_visitor_stats):
 *          messages answered, hand-offs and estimated tokens, and — with the optional prices per million
 *          input / output tokens — a cost estimate and projection for the month, shown on AI Usage and
 *          next to the daily limit.
 *
 * 1.0.28 - Who can use it, and a usage log (FW_AI_Access). Advanced → Who can use it: tick the roles
 *          allowed to use the assistant (option upw_ai_roles; none ticked = everyone whose capabilities
 *          allow it, as before; administrators always). One rest_request_before_callbacks filter refuses
 *          every route in the extension's namespace to other roles — the chat, local AI sessions, saved
 *          conversations and outside programs over MCP (403 upw_ai_role); OAuth discovery stays public
 *          and the web sign-in screen refuses such users; the panel and admin-bar item are not loaded
 *          for them. Unyson+ → AI Usage (manage_options) lists what was asked — when, who, through
 *          which channel, where, and the first 200 characters, or the tool an outside program called
 *          (the panel's own tool calls are not logged) — with per-person counts for 30 days; newest
 *          500 kept (option upw_ai_usage_log), can be switched off and cleared. Reset clears both.
 *
 * 1.0.27 - "More ideas". A starter button in the panel asks the AI itself for four specific things it
 *          could do on the current page or screen (the rule-based ideas stay, instant and free); the
 *          chat shows the short label, not the instruction behind it. Any reply line starting with
 *          "→ " now renders as a button that sends that request with one click.
 *
 * 1.0.26 - Brand kit from a logo. New ability extract-colors (read): the main colours of a Media Library
 *          image MEASURED from its pixels (GD, transparent pixels ignored, near shades merged) or read
 *          from an SVG's code, each with its share of the image and its WCAG contrast against white and
 *          black text. The assistant's instructions add the brand-kit workflow: look at the logo,
 *          measure it, build a palette with readable text pairs and a font pairing, show it, and only
 *          after the person agrees apply it through the existing Theme Settings and preset abilities
 *          (all undoable).
 *
 * 1.0.25 - Build a page from a screenshot or sketch. The chat panel takes an image (attach button,
 *          paste or drop; users who can upload files): it goes to the Media Library through the REST
 *          API and the message names its id, so the AI looks at it with view-media (new size: large,
 *          up to 1568px) and builds a matching draft page from real elements in the site's own design.
 *          Icons given as a bare name ("leaf"), "lucide/leaf", a font class or a single character are
 *          now normalized to the icon object (FW_AI_Schema::normalize_icon, also on update-element);
 *          anything else is rejected with an example — a string used to save and render nothing.
 *          Local AI through the AI Dev Kit runs the agent as a background job the panel polls
 *          (capture service 1.11.83+; an older kit still answers directly): one long request was
 *          dropped on multi-minute builds and the browser's retry ran the agent twice.
 *
 * 1.0.24 - Translate a page into a draft copy. New abilities (FW_AI_Translate): get-page-text lists a
 *          page's visitor-facing text as { key, text } (title, excerpt, each builder text value, or the
 *          classic content — the replace-text rules decide what is text, now also skipping layout keys
 *          such as display / gap / justify / flex / grid / sizes, CSS lengths and single lowercase
 *          tokens), and translate-page creates a NEW DRAFT with the same layout, settings, meta, terms
 *          and featured image and the translated text in place (HTML sanitized, missing {{placeholders}}
 *          and [shortcodes] reported, keys left out keep the original). The original is never changed;
 *          undo_change trashes the copy. With Polylang and a lang_code the copy is set to that language
 *          and linked as the translation.
 *
 * 1.0.23 - Image help. New abilities (FW_AI_Media, registered through the toolkit): list-media (search
 *          the Media Library, missing_alt / unattached filters, with the pages that use each image),
 *          view-media (up to 6 images returned THEMSELVES, resized to 768px JPEG, so a model that can
 *          see writes alt text from the picture — sent only over MCP, where FW_AI_MCP now turns a
 *          result's `_images` into MCP image content), update-media (alt text, title, caption,
 *          description for up to 50 images as one undoable change) and set-featured-image (0
 *          removes it; undoable). list-media, update-media and set-featured-image are also in the
 *          local-AI site tool set.
 *
 * 1.0.22 - Find and replace text across the site, preview first. New ability unysonplus/replace-text
 *          (FW_AI_Replace; also in the local-AI site tool set): changes text everywhere a visitor
 *          reads it — page-builder content of every post type (drafts too), titles, excerpts, custom
 *          menu labels and Theme Settings text — but never ids, CSS, colours, icons, images or other
 *          settings, links only with include_links, and in HTML only the text between tags. A call
 *          without apply changes nothing and returns every change (before / after) plus a plan code;
 *          apply: <plan> (30 minutes, one use, same user) makes exactly those changes, skipping any
 *          place edited since. Pages are saved through the usual revisions, titles as one extension
 *          snapshot, Theme Settings as one settings revision (hand-edited settings are left alone),
 *          so AI Changes lists and undoes all of it. match_case and whole_word default on; the
 *          preview also says when the site title or tagline contain the text. Also: a preview or a
 *          call that did nothing is no longer listed as a change under the reply, and replies render
 *          tables, italics, numbered lists and headings.
 *
 * 1.0.21 - Visual check against a source site. New ability unysonplus/visual-check (read-only;
 *          also in the local-AI site tool set): renders a source / reference page and a page of
 *          this site (post_id, drafts included, or url) in a real browser through the capture
 *          service's POST /verify with lens "both" (service 1.11.78+), and answers how different
 *          they look (overall and the worst strips, heights) plus, section by section, what is
 *          missing, moved, restyled or re-gridded (FW_AI_Visual::summarize trims it for a model).
 *          Drafts are opened through a one-post preview token (upw_ai_view, 15 minutes, noindex).
 *          When the server cannot reach the service (a live site), the ability returns the request
 *          and the chat panel's browser runs it against the kit, then passes the answer back as
 *          `measured`. device: desktop, tablet or mobile. Also: replies from local AI and the
 *          subscription agent keep their line breaks (lists and paragraphs), live and restored.
 *
 * 1.0.19 - Web sign-in (OAuth 2.1) for the MCP server, so AI apps that connect to remote MCP
 *          servers through a browser sign-in need only the Server URL. FW_AI_OAuth serves the
 *          discovery documents (/.well-known/oauth-protected-resource and
 *          /.well-known/oauth-authorization-server, plus openid-configuration and REST copies under
 *          unysonplus-ai/v1/oauth), dynamic client registration (public clients, https or
 *          localhost redirects), a consent screen in wp-admin (Allow / Deny, Read and write or Read
 *          only; allowing turns outside access on for an administrator), the token endpoint
 *          (authorization code with PKCE S256, single-use 10-minute codes; refresh tokens rotated on
 *          use) and RFC 7009 revocation. Tokens are stored as SHA-256 hashes, last 1 hour (refresh
 *          30 days) and are accepted only on this extension's REST routes; a read-only token gets
 *          only the Read tools. A signed-out MCP request now answers 401 with WWW-Authenticate
 *          pointing at the metadata, before the Off check, so the sign-in can start. Settings: "Apps
 *          signed in with your account" with Sign out, under Outside AI programs. Passwords still work.
 *
 * 1.0.18 - AI Changes screen (Unyson+ > AI Changes). Every change the AI made to the site, newest
 *          first, from the three places a change is already recorded before it is made: page-
 *          builder snapshots (post meta _upw_ai_revision), Theme Settings snapshots and extension
 *          snapshots (menus, forms, SEO, products, site identity). Each row says when, what, where
 *          (linked) and who, with Undo; older page rows offer "Restore page to before this"; an
 *          undo is itself recorded and shows as "Undid: ..." with Redo, and the change it reversed
 *          shows as Undone. Filters: Pages, Theme Settings, Other. Page rows need edit_post, the
 *          rest manage_options. Linked from the settings screen and from site-wide chat replies.
 *
 * 1.0.16 - Settings screen redesigned for newcomers. It opens with one live status (Ready, Not
 *          connected yet or Turned off, naming the AI that answers; with local AI it runs the same
 *          check as the chat panel through window.upwAiAssistant) and an "Open the AI Assistant"
 *          button, then one recommended way to connect (the AI Dev Kit, with an AI subscription or a free model)
 *          plus the provider-key alternative, then the panel position. The model choice, outside AI
 *          programs over MCP (renamed from "Connect an agent"), the abilities list and a new Reset
 *          (action reset: every option on the screen back to its default, MCP access Off, optionally
 *          the user's saved conversations) sit under Advanced. Each form now saves only the fields
 *          it sends, and the chat panel's one-off passwords are no longer listed as connections.
 *
 * 1.0.13 - Subscription agent through the AI Dev Kit, and a "Connected: …" line. When the kit on the
 *          editor's computer has a command-line AI agent signed in (its /health reports the agent
 *          backend), the local-AI mode hands the whole request to that agent instead of the
 *          local model: panel/local/start with agent: true opens a session, issues a temporary
 *          Application Password and returns the MCP connection + prompt; the kit's new
 *          POST /local-ai/agent runs `claude -p` against the site's MCP endpoint (only that
 *          server's tools allowed), which works for hosted sites too; finish deletes the
 *          password. The panel now always says which AI answers — "Connected: …" naming the subscription AI for the
 *          kit or a claude agent command, the provider for the WordPress AI Client, the model
 *          name for a local model.
 *
 * 1.0.12 - Knows where you are, and remembers the conversation. The panel now sends where
 *          the person is — a builder page (its typed title, never "Auto Draft", section count,
 *          whether it has a call to action, its SEO state) or an admin screen (Dashboard,
 *          Settings > General, Pages, Menus, Theme Settings, Products) with a few real facts
 *          about it (FW_AI_Context) — so "what should I change here?" is about that place. Its
 *          starter ideas are chosen by rules from those facts (instant, free, only things the
 *          tools can do), e.g. "Write SEO descriptions for the 4 pages missing one"; queued
 *          suggestions from other extensions still show first. Conversations are saved per user
 *          and per page / screen in user meta upw_ai_chats (FW_AI_History: last 30 messages, 30
 *          days, 60 places), restored on reload and sent back to the model; "New chat" clears
 *          one. Restored replies show as applied earlier (no Undo button). New ability
 *          update-site-identity: site title, tagline and site icon, snapshotted for undo_change.
 *
 * 1.0.9 - Local AI on this computer (free). A new AI model choice, "browser": the assistant
 *         panel's JavaScript runs the tool loop itself, talking to a local model on the
 *         EDITOR'S computer — the AI Dev Kit's capture service (POST /local-ai/tool-chat) or
 *         a local model runner directly — and to this site's MCP endpoint (cookie auth + X-UPW-AI-Session)
 *         for the tools. A hosted server can never reach the editor's localhost; the browser
 *         can, so this works on live sites. The server only opens / closes the sandbox session
 *         (POST panel/local/start, panel/local/finish). The model answers each turn with one
 *         JSON action ({tool, arguments} or {reply}) constrained by the runner's `format` — native
 *         tool calls from small models were dropped whenever a long argument had one stray
 *         token. Small models also get a trimmed tool set, validated section recipes (FAQ,
 *         feature cards, call to action, text), stray non-object entries stripped from item
 *         lists, and automatic nudges when they stop half way or skip fixing what
 *         render_check found. Automatic
 *         falls back to it when no provider key or local agent is set up; the panel checks for
 *         a model when opened and explains the setup when none answers. Options
 *         upw_ai_browser_url (default http://localhost:8787) and upw_ai_browser_model.
 *         Also: render_check recognises emoji icons (the "char" key) as set.
 *
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
