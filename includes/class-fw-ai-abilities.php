<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Registers the AI Assistant's abilities with the WordPress Abilities API.
 *
 * Every ability is `meta.public` (so REST and MCP clients can list and run it) and carries
 * `annotations` hints. Permissions are ordinary WordPress capabilities for the CURRENT user,
 * so an agent connected with an Application Password can do exactly what that user can.
 *
 * Categories:
 *   unysonplus-site  — read-only: site info, element catalog, page outline, presets, content
 *   unysonplus-build — writes to a page's builder tree (each one snapshots a revision first)
 *   unysonplus-undo  — revision history + restore
 */
class FW_AI_Abilities {

	/** Post statuses create-page accepts. */
	const STATUSES = array( 'draft', 'publish', 'private', 'pending' );

	/**
	 * Theme Settings preset lists exposed by list-presets: option id => default-seeding function.
	 */
	const PRESETS = array(
		'theme_colors'          => 'unysonplus_default_color_presets',
		'button_colors'         => 'unysonplus_default_button_color_presets',
		'button_sizes'          => 'unysonplus_default_button_size_presets',
		'border_presets'        => 'unysonplus_default_border_presets',
		'section_style_presets' => '',
		'typography_presets'    => '',
	);

	public static function register_categories() {
		wp_register_ability_category( 'unysonplus-site', array(
			'label'       => __( 'UnysonPlus — site & content', 'fw' ),
			'description' => __( 'Read the site, its page-builder elements, pages, presets and published content.', 'fw' ),
		) );
		wp_register_ability_category( 'unysonplus-build', array(
			'label'       => __( 'UnysonPlus — page building', 'fw' ),
			'description' => __( 'Create pages and change their page-builder content. Every change is validated and can be undone.', 'fw' ),
		) );
		wp_register_ability_category( 'unysonplus-undo', array(
			'label'       => __( 'UnysonPlus — revisions', 'fw' ),
			'description' => __( 'List and restore the revisions saved before each AI change.', 'fw' ),
		) );
	}

	public static function register() {
		$read = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );

		/* ---------------- Read ---------------- */

		self::ability( 'site-info', 'unysonplus-site', array(
			'label'               => __( 'Site info', 'fw' ),
			'description'         => __( 'Start here. Returns the site name, tagline, URL, WordPress version, active theme, active UnysonPlus extensions, the post types the page builder supports, and up to 100 pages (id, title, status, url, whether each is a builder page).', 'fw' ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => array( __CLASS__, 'site_info' ),
			'annotations'         => $read,
		) );

		self::ability( 'list-elements', 'unysonplus-site', array(
			'label'               => __( 'List page-builder elements', 'fw' ),
			'description'         => __( 'Every layout type (section, column, flexbox …) and element (special_heading, text_block, button, icon_box …) that can be placed on a page, with a one-line description and builder category. Optional `category` filters by category name.', 'fw' ),
			'input_schema'        => self::schema( array(
				'category' => array( 'type' => 'string', 'description' => 'Case-insensitive category filter, e.g. "Content".' ),
			) ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => function ( $in ) {
				return array( 'elements' => FW_AI_Schema::list_elements( (string) ( $in['category'] ?? '' ) ) );
			},
			'annotations'         => $read,
		) );

		self::ability( 'describe-element', 'unysonplus-site', array(
			'label'               => __( 'Describe an element', 'fw' ),
			'description'         => __( 'The option schema of one element or layout type: each att id with its type, label, allowed choices and default. Read this before setting atts — unknown att ids are rejected by the write abilities. Animation Engine effect options are left out unless include_effects is true.', 'fw' ),
			'input_schema'        => self::schema( array(
				'element'         => array( 'type' => 'string', 'description' => 'A shortcode tag (e.g. "button") or a layout type (e.g. "flexbox").' ),
				'include_effects' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'element' ) ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Schema::describe( (string) $in['element'], ! empty( $in['include_effects'] ) );
			},
			'annotations'         => $read,
		) );

		self::ability( 'get-page', 'unysonplus-site', array(
			'label'               => __( 'Get a page', 'fw' ),
			'description'         => __( 'A page\'s builder content. detail "outline" (default) returns the tree with a `path` for every item (use it with the write abilities), its type/shortcode, unique_id and a short text label. detail "full" returns the raw tree including every att.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id' => array( 'type' => 'integer' ),
				'detail'  => array( 'type' => 'string', 'enum' => array( 'outline', 'full' ), 'default' => 'outline' ),
			), array( 'post_id' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( __CLASS__, 'get_page' ),
			'annotations'         => $read,
		) );

		self::ability( 'list-presets', 'unysonplus-site', array(
			'label'               => __( 'List Theme Settings presets', 'fw' ),
			'description'         => __( 'The design-system presets in Theme Settings — color presets, button color and size presets, box (border) presets, section styles and typography presets — with their names and ids. Style buttons and cards by choosing one of these instead of setting colors on each element.', 'fw' ),
			'input_schema'        => self::schema( array(
				'type' => array( 'type' => 'string', 'enum' => array_keys( self::PRESETS ), 'description' => 'Only this preset list. Omit for all.' ),
			) ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => array( __CLASS__, 'list_presets' ),
			'annotations'         => $read,
		) );

		self::ability( 'search-content', 'unysonplus-site', array(
			'label'               => __( 'Search published content', 'fw' ),
			'description'         => __( 'Full-text search over PUBLISHED, non-password-protected pages, posts and other public post types. Returns id, title, type, excerpt and url for up to 10 matches. Safe for visitor-facing use.', 'fw' ),
			'input_schema'        => self::schema( array(
				'query' => array( 'type' => 'string', 'minLength' => 2 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5 ),
			), array( 'query' ) ),
			'permission_callback' => '__return_true',
			'execute_callback'    => array( __CLASS__, 'search_content' ),
			'annotations'         => $read,
		) );

		self::ability( 'get-content', 'unysonplus-site', array(
			'label'               => __( 'Get published content', 'fw' ),
			'description'         => __( 'The plain-text body (up to 20,000 characters) of one PUBLISHED, non-password-protected page, post or product, by post_id or url. Safe for visitor-facing use.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id' => array( 'type' => 'integer' ),
				'url'     => array( 'type' => 'string' ),
			) ),
			'permission_callback' => '__return_true',
			'execute_callback'    => array( __CLASS__, 'get_content' ),
			'annotations'         => $read,
		) );

		/* ---------------- Build ---------------- */

		$items_schema = array(
			'type'        => 'array',
			'description' => 'Builder items. Layout: {type:"flexbox"|"section"|"column"|"container"|"row", atts:{…}, _items:[…], width?:"1_2"}. Element: {type:"simple", shortcode:"<tag>", atts:{…}}. Page-root items must be section, flexbox or container; a section holds only columns; elements go inside a column or flexbox. Recommended band: {type:"flexbox", atts:{html_tag:"section", display:"block"}, _items:[…]}.',
			'items'       => array( 'type' => 'object' ),
		);

		self::ability( 'create-page', 'unysonplus-build', array(
			'label'               => __( 'Create a page', 'fw' ),
			'description'         => __( 'Creates a new page-builder page (a draft unless status says otherwise) and optionally fills it with items. Returns the post id, edit and preview links, and its outline.', 'fw' ),
			'input_schema'        => self::schema( array(
				'title'     => array( 'type' => 'string', 'minLength' => 1 ),
				'status'    => array( 'type' => 'string', 'enum' => self::STATUSES, 'default' => 'draft' ),
				'post_type' => array( 'type' => 'string', 'default' => 'page' ),
				'slug'      => array( 'type' => 'string' ),
				'items'     => $items_schema,
			), array( 'title' ) ),
			'permission_callback' => array( __CLASS__, 'can_create' ),
			'execute_callback'    => array( __CLASS__, 'create_page' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'insert-items', 'unysonplus-build', array(
			'label'               => __( 'Insert items', 'fw' ),
			'description'         => __( 'Inserts one or more validated items into a page — at the page root (a new section/band) or inside an existing layout item given by parent_path. position is the index to insert at (default: the end). A revision is saved first.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id'         => array( 'type' => 'integer' ),
				'items'           => $items_schema,
				'parent_path'     => array( 'type' => 'string', 'description' => 'Path of the containing item (from get-page), or omit for the page root.' ),
				'position'        => array( 'type' => 'integer', 'minimum' => 0 ),
				'convert_classic' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Required when the page has classic (non-builder) content, which the builder will replace.' ),
			), array( 'post_id', 'items' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( __CLASS__, 'insert_items' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'update-element', 'unysonplus-build', array(
			'label'               => __( 'Update an item\'s options', 'fw' ),
			'description'         => __( 'Sets atts on one item (element or layout) by path. atts are merged over the current ones unless replace is true; set an att to null to reset it to its default. For a column, width changes its width. A revision is saved first.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id' => array( 'type' => 'integer' ),
				'path'    => array( 'type' => 'string' ),
				'atts'    => array( 'type' => 'object' ),
				'width'   => array( 'type' => 'string' ),
				'replace' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'post_id', 'path' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( __CLASS__, 'update_element' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		) );

		self::ability( 'move-element', 'unysonplus-build', array(
			'label'               => __( 'Move an item', 'fw' ),
			'description'         => __( 'Moves an item (with its children) to another position — in the same container or a different one (to_parent omitted = page root). position is the index in the destination AFTER the item has been taken out. A revision is saved first.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id'   => array( 'type' => 'integer' ),
				'path'      => array( 'type' => 'string' ),
				'to_parent' => array( 'type' => 'string' ),
				'position'  => array( 'type' => 'integer', 'minimum' => 0 ),
			), array( 'post_id', 'path', 'position' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( __CLASS__, 'move_element' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'remove-element', 'unysonplus-build', array(
			'label'               => __( 'Remove an item', 'fw' ),
			'description'         => __( 'Deletes one item and everything inside it. A revision is saved first, so unysonplus/undo brings it back. Confirm with the user before removing content they wrote.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id' => array( 'type' => 'integer' ),
				'path'    => array( 'type' => 'string' ),
			), array( 'post_id', 'path' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( __CLASS__, 'remove_element' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		) );

		/* ---------------- Undo ---------------- */

		self::ability( 'render-check', 'unysonplus-site', array(
			'label'               => __( 'Check the rendered page', 'fw' ),
			'description'         => __( 'Renders the page and reports problems a visitor would notice, each with the item path: errors (elements that render nothing, fail, print PHP errors or raw shortcode text, broken images) and warnings (an element whose main icon/image is empty — e.g. an icon_box with no icon shows an empty gap —, text still at its default like a "Submit" button, links to "#", empty layout items, heading-level problems). Call it after every build and fix what it reports before telling the user you are done.', 'fw' ),
			'input_schema'        => self::schema( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Check::run( (int) $in['post_id'] );
			},
			'annotations'         => $read,
		) );

		/* ---------------- Site design (Theme Settings, presets, templates, conversion) ---------------- */

		self::ability( 'describe-theme-settings', 'unysonplus-site', array(
			'label'               => __( 'Describe Theme Settings', 'fw' ),
			'description'         => __( 'Without an id: the index of every Theme Settings option (id, type, label) grouped by section (General › Layout, Components › Buttons, Header › Main Header …); `search` filters it. With an id: that option\'s full schema (inner options, choices, defaults) and its current value. Read this before update_theme_settings.', 'fw' ),
			'input_schema'        => self::schema( array(
				'id'     => array( 'type' => 'string' ),
				'search' => array( 'type' => 'string' ),
			) ),
			'permission_callback' => self::cap( 'edit_theme_options' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Settings::describe( (string) ( $in['id'] ?? '' ), (string) ( $in['search'] ?? '' ) );
			},
			'annotations'         => $read,
		) );

		self::ability( 'update-theme-settings', 'unysonplus-build', array(
			'label'               => __( 'Update Theme Settings', 'fw' ),
			'description'         => __( 'Changes site-wide Theme Settings — colours, typography, layout, header, footer … — given as { values: { <setting id>: <value> } }. Object values are MERGED into the current value (send only the keys you change; lists are replaced whole) unless merge is false. Validated against the settings schema; the previous values are saved so undo_theme_settings can restore them. Changes are LIVE on the site immediately. Settings the person edited by hand since the converter or the AI last wrote them are SKIPPED (listed under skipped) — ask them, then retry with force: true. For button / box / section styles use save_preset.', 'fw' ),
			'input_schema'        => self::schema( array(
				'values' => array( 'type' => 'object' ),
				'merge'  => array( 'type' => 'boolean', 'default' => true ),
				'force'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'Also change settings the person edited by hand (reported under skipped otherwise). Only after they agree.' ),
			), array( 'values' ) ),
			'permission_callback' => self::cap( 'edit_theme_options' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Settings::update( (array) $in['values'], ! isset( $in['merge'] ) || $in['merge'], 'unysonplus/update-theme-settings', '', ! empty( $in['force'] ) );
			},
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		) );

		self::ability( 'save-preset', 'unysonplus-build', array(
			'label'               => __( 'Create or update a design preset', 'fw' ),
			'description'         => __( 'Creates or updates ONE named preset in a Theme Settings preset list — type is the list id from list_presets (button_colors, button_sizes, border_presets (box presets), section_style_presets, theme_colors, typography_presets). values are merged into the existing preset of that name, or into a copy of the first preset for a new one; call describe_theme_settings with the type as id to see a preset\'s fields. Elements then use the preset by name, so every element wearing it changes together.', 'fw' ),
			'input_schema'        => self::schema( array(
				'type'   => array( 'type' => 'string' ),
				'name'   => array( 'type' => 'string', 'minLength' => 1 ),
				'values' => array( 'type' => 'object', 'default' => array() ),
				'force'  => array( 'type' => 'boolean', 'default' => false ),
			), array( 'type', 'name' ) ),
			'permission_callback' => self::cap( 'edit_theme_options' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Settings::save_preset( (string) $in['type'], (string) $in['name'], (array) ( $in['values'] ?? array() ), ! empty( $in['force'] ) );
			},
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		) );

		self::ability( 'list-templates', 'unysonplus-site', array(
			'label'               => __( 'List templates', 'fw' ),
			'description'         => __( 'Premade page-builder templates: the Template Library (bundled, installed, or available to install) and templates saved in this site. kind is full (a whole page), section or column. Use apply_template to put one on a page.', 'fw' ),
			'input_schema'        => self::schema( array(
				'kind'   => array( 'type' => 'string', 'enum' => array( 'full', 'section', 'column' ) ),
				'search' => array( 'type' => 'string' ),
			) ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => function ( $in ) {
				return array( 'templates' => FW_AI_Build::list_templates( (string) ( $in['kind'] ?? '' ), (string) ( $in['search'] ?? '' ) ) );
			},
			'annotations'         => $read,
		) );

		self::ability( 'apply-template', 'unysonplus-build', array(
			'label'               => __( 'Apply a template', 'fw' ),
			'description'         => __( 'Inserts a template (id from list_templates) into a page — at the root, or inside parent_path for a column template — at position (default: the end). A full-page template can instead replace the whole page with replace: true. Library templates not yet downloaded are installed first (administrators). Replace the template\'s placeholder text and images afterwards. A revision is saved first.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id'     => array( 'type' => 'integer' ),
				'template_id' => array( 'type' => 'string' ),
				'parent_path' => array( 'type' => 'string' ),
				'position'    => array( 'type' => 'integer', 'minimum' => 0 ),
				'replace'     => array( 'type' => 'boolean', 'default' => false ),
			), array( 'post_id', 'template_id' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => array( 'FW_AI_Build', 'apply_template' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'convert-url', 'unysonplus-build', array(
			'label'               => __( 'Convert a website into this site', 'fw' ),
			'description'         => __( 'Runs the Site Converter on a URL: generates and ACTIVATES a child theme with the source\'s design, writes Theme Settings, and creates or REPLACES pages with the same slugs (it can also set the front page). Uses the capture service for a full browser render when it is running. Never run it without the user\'s explicit agreement: the first call without confirm: true only explains the impact; dry_run: true tests the pipeline without changing the site.', 'fw' ),
			'input_schema'        => self::schema( array(
				'url'     => array( 'type' => 'string' ),
				'confirm' => array( 'type' => 'boolean', 'default' => false ),
				'dry_run' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'url' ) ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) && current_user_can( 'switch_themes' );
			},
			'execute_callback'    => array( 'FW_AI_Build', 'convert_url' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		) );

		self::ability( 'list-settings-revisions', 'unysonplus-undo', array(
			'label'               => __( 'List Theme Settings revisions', 'fw' ),
			'description'         => __( 'The Theme Settings values saved before each AI change, newest first (the newest 20 are kept).', 'fw' ),
			'permission_callback' => self::cap( 'edit_theme_options' ),
			'execute_callback'    => function () {
				return array( 'revisions' => FW_AI_Settings::list_revisions() );
			},
			'annotations'         => $read,
		) );

		self::ability( 'undo-theme-settings', 'unysonplus-undo', array(
			'label'               => __( 'Undo a Theme Settings change', 'fw' ),
			'description'         => __( 'Restores the Theme Settings an AI change touched to their previous values — the newest change by default, or revision_id. The current values are saved first, so the undo can itself be undone.', 'fw' ),
			'input_schema'        => self::schema( array( 'revision_id' => array( 'type' => 'integer' ) ) ),
			'permission_callback' => self::cap( 'edit_theme_options' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Settings::undo( isset( $in['revision_id'] ) ? (int) $in['revision_id'] : null );
			},
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'list-changes', 'unysonplus-undo', array(
			'label'               => __( 'List other AI changes', 'fw' ),
			'description'         => __( 'Changes made through abilities other extensions add (SEO, Theme Builder …), newest first, each undoable with undo_change. Page content uses list_revisions; Theme Settings use list_settings_revisions.', 'fw' ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => function () {
				return array( 'changes' => FW_AI_Toolkit::list_changes() );
			},
			'annotations'         => $read,
		) );

		self::ability( 'undo-change', 'unysonplus-undo', array(
			'label'               => __( 'Undo another AI change', 'fw' ),
			'description'         => __( 'Restores what an extension ability changed (the newest change by default, or revision_id from list_changes / the ability\'s undo_revision_id). The current values are saved first, so it can itself be undone.', 'fw' ),
			'input_schema'        => self::schema( array( 'revision_id' => array( 'type' => 'integer' ) ) ),
			'permission_callback' => self::cap( 'edit_posts' ),
			'execute_callback'    => function ( $in ) {
				return FW_AI_Toolkit::restore( isset( $in['revision_id'] ) ? (int) $in['revision_id'] : null );
			},
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );

		self::ability( 'list-revisions', 'unysonplus-undo', array(
			'label'               => __( 'List AI revisions', 'fw' ),
			'description'         => __( 'The revisions saved before each AI change to a page, newest first (the newest 20 are kept).', 'fw' ),
			'input_schema'        => self::schema( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => function ( $in ) {
				return array( 'revisions' => FW_AI_Store::list_revisions( (int) $in['post_id'] ) );
			},
			'annotations'         => $read,
		) );

		self::ability( 'undo', 'unysonplus-undo', array(
			'label'               => __( 'Undo an AI change', 'fw' ),
			'description'         => __( 'Restores a page to a saved revision — the newest one by default, i.e. undoes the last AI change. The current state is saved as a revision first, so an undo can itself be undone.', 'fw' ),
			'input_schema'        => self::schema( array(
				'post_id'     => array( 'type' => 'integer' ),
				'revision_id' => array( 'type' => 'integer' ),
			), array( 'post_id' ) ),
			'permission_callback' => self::post_cap( 'edit_post' ),
			'execute_callback'    => function ( $in ) {
				$post_id = (int) $in['post_id'];
				$r       = FW_AI_Store::restore( $post_id, isset( $in['revision_id'] ) ? (int) $in['revision_id'] : null );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return $r + array( 'outline' => FW_AI_Store::outline( FW_AI_Store::get_tree( $post_id ) ) );
			},
			'annotations'         => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Registration helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $slug
	 * @param string $category
	 * @param array  $args
	 */
	private static function ability( $slug, $category, array $args ) {
		$annotations = $args['annotations'];
		unset( $args['annotations'] );
		wp_register_ability( 'unysonplus/' . $slug, array_merge( $args, array(
			'category' => $category,
			'meta'     => array(
				'public'      => true,
				'mcp'         => array( 'public' => true ), // Picked up by the WordPress MCP adapter too.
				'annotations' => $annotations,
			),
		) ) );
	}

	/**
	 * @param array $props
	 * @param array $required
	 * @return array JSON Schema for an object input.
	 */
	private static function schema( array $props, array $required = array() ) {
		$s = array(
			'type'                 => 'object',
			'properties'           => $props,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$s['required'] = $required;
		} else {
			$s['default'] = array();
		}
		return $s;
	}

	/**
	 * @param string $cap
	 * @return Closure
	 */
	private static function cap( $cap ) {
		return function () use ( $cap ) {
			return current_user_can( $cap );
		};
	}

	/**
	 * A per-post capability check on input.post_id.
	 *
	 * @param string $cap
	 * @return Closure
	 */
	private static function post_cap( $cap ) {
		return function ( $in ) use ( $cap ) {
			$id = isset( $in['post_id'] ) ? (int) $in['post_id'] : 0;
			if ( ! $id || ! get_post( $id ) ) {
				return new WP_Error( 'upw_ai_no_post', 'No post with that post_id.', array( 'status' => 404 ) );
			}
			return current_user_can( $cap, $id );
		};
	}

	/* ------------------------------------------------------------------ *
	 * Read callbacks
	 * ------------------------------------------------------------------ */

	public static function site_info() {
		$active = array();
		foreach ( (array) fw()->extensions->get_all() as $name => $ext ) {
			$active[] = (string) $name;
		}
		$types = function_exists( 'fw_ext_page_builder_get_supported_post_types' ) ? array_keys( (array) fw_ext_page_builder_get_supported_post_types() ) : array( 'page' );

		$pages = array();
		foreach ( get_posts( array(
			'post_type'   => 'page',
			'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'numberposts' => 100,
			'orderby'     => 'menu_order title',
			'order'       => 'ASC',
		) ) as $p ) {
			$pages[] = array(
				'id'      => $p->ID,
				'title'   => get_the_title( $p ),
				'status'  => $p->post_status,
				'url'     => get_permalink( $p ),
				'builder' => FW_AI_Store::is_builder_active( $p->ID ),
			);
		}

		$theme = wp_get_theme();
		return array(
			'name'               => get_bloginfo( 'name' ),
			'tagline'            => get_bloginfo( 'description' ),
			'url'                => home_url( '/' ),
			'wordpress'          => get_bloginfo( 'version' ),
			'theme'              => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'parent_theme'       => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'child_theme_note'   => $theme->parent()
				? 'The active theme is a child theme. Its own stylesheet can override Theme Settings (fonts, colours, header/footer styling) — after changing those, check the rendered page, and tell the user if the child theme overrides them.'
				: null,
			'unysonplus'         => defined( 'FW_VERSION' ) ? FW_VERSION : ( function_exists( 'fw' ) ? fw()->manifest->get_version() : '' ),
			'active_extensions'  => $active,
			'builder_post_types' => $types,
			'front_page_id'      => (int) get_option( 'page_on_front' ),
			'pages'              => $pages,
			'assistant'          => array(
				'status' => 'beta',
				'tips'   => array(
					'Call list-elements, then describe-element for each element you plan to use.',
					'Prefer flexbox bands ({type:"flexbox", atts:{html_tag:"section", display:"block"}}) for new sections.',
					'Style buttons and cards with Theme Settings presets (list-presets) instead of per-element colors.',
					'Every write saves a revision; undo reverts the last change.',
				),
			),
		);
	}

	public static function get_page( $in ) {
		$post_id = (int) $in['post_id'];
		$post    = get_post( $post_id );
		$tree    = FW_AI_Store::get_tree( $post_id );
		$active  = FW_AI_Store::is_builder_active( $post_id );
		$out     = array(
			'post_id'         => $post_id,
			'title'           => get_the_title( $post ),
			'status'          => $post->post_status,
			'post_type'       => $post->post_type,
			'url'             => get_permalink( $post ),
			'builder_active'  => $active,
			'classic_content' => ! $active && trim( (string) $post->post_content ) !== '',
			'items'           => ( ( $in['detail'] ?? 'outline' ) === 'full' ) ? $tree : FW_AI_Store::outline( $tree ),
		);
		$lock = self::lock_warning( $post_id );
		if ( $lock ) {
			$out['warning'] = $lock;
		}
		return $out;
	}

	public static function list_presets( $in ) {
		$only = isset( $in['type'] ) ? (string) $in['type'] : '';
		$out  = array();
		foreach ( self::PRESETS as $id => $seed ) {
			if ( $only !== '' && $only !== $id ) {
				continue;
			}
			$rows = function_exists( 'fw_get_db_settings_option' ) ? fw_get_db_settings_option( $id, array() ) : array();
			if ( empty( $rows ) && $seed && function_exists( $seed ) ) {
				$rows = call_user_func( $seed );
			}
			$list = array();
			foreach ( (array) $rows as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$item = array();
				foreach ( array( 'id', 'slug', 'name', 'color_name', 'preset_name', 'title', 'color' ) as $k ) {
					if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && $row[ $k ] !== '' ) {
						$item[ $k ] = (string) $row[ $k ];
					}
				}
				if ( ! isset( $item['id'] ) && is_string( $key ) ) {
					$item['id'] = $key;
				}
				$list[] = $item;
			}
			$out[ $id ] = $list;
		}
		return array( 'presets' => $out );
	}

	public static function search_content( $in ) {
		$q = new WP_Query( array(
			's'                   => (string) $in['query'],
			'post_type'           => array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) ),
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => min( 10, max( 1, (int) ( $in['limit'] ?? 5 ) ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'      => $p->ID,
				'title'   => get_the_title( $p ),
				'type'    => $p->post_type,
				'excerpt' => wp_html_excerpt( self::plain_text( $p ), 300, '…' ),
				'url'     => get_permalink( $p ),
			);
		}
		return array( 'results' => $out );
	}

	public static function get_content( $in ) {
		$id = isset( $in['post_id'] ) ? (int) $in['post_id'] : 0;
		if ( ! $id && ! empty( $in['url'] ) ) {
			$id = url_to_postid( (string) $in['url'] );
		}
		$p = $id ? get_post( $id ) : null;
		if ( ! $p || $p->post_status !== 'publish' || post_password_required( $p ) || ! is_post_type_viewable( $p->post_type ) ) {
			return new WP_Error( 'upw_ai_not_found', 'No published content found for that post_id / url.', array( 'status' => 404 ) );
		}
		return array(
			'id'    => $p->ID,
			'title' => get_the_title( $p ),
			'type'  => $p->post_type,
			'url'   => get_permalink( $p ),
			'text'  => wp_html_excerpt( self::plain_text( $p ), 20000, '…' ),
		);
	}

	/**
	 * Plain text of a post: its (shortcode) content rendered, tags and whitespace stripped.
	 * Read-only requests only — rendering primes the builder's request cache.
	 *
	 * @param WP_Post $p
	 * @return string
	 */
	public static function plain_text( WP_Post $p ) {
		$html = do_shortcode( (string) $p->post_content );
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Write callbacks
	 * ------------------------------------------------------------------ */

	public static function can_create( $in ) {
		$type = get_post_type_object( (string) ( $in['post_type'] ?? 'page' ) );
		if ( ! $type ) {
			return new WP_Error( 'upw_ai_bad_type', 'Unknown post_type.' );
		}
		$status = (string) ( $in['status'] ?? 'draft' );
		$cap    = $status === 'publish' || $status === 'private' ? $type->cap->publish_posts : $type->cap->edit_posts;
		return current_user_can( $cap );
	}

	public static function create_page( $in ) {
		$post_type = (string) ( $in['post_type'] ?? 'page' );
		if ( ! self::builder_supports( $post_type ) ) {
			return new WP_Error( 'upw_ai_no_builder', "The page builder is not enabled for post type \"$post_type\"." );
		}
		$errors = array();
		$items  = FW_AI_Schema::validate_items( $in['items'] ?? array(), '', '', $errors );
		if ( $errors ) {
			return self::invalid( $errors );
		}

		$id = wp_insert_post( array(
			'post_type'   => $post_type,
			'post_status' => in_array( $in['status'] ?? '', self::STATUSES, true ) ? $in['status'] : 'draft',
			'post_title'  => sanitize_text_field( (string) $in['title'] ),
			'post_name'   => isset( $in['slug'] ) ? sanitize_title( (string) $in['slug'] ) : '',
		), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$rev = FW_AI_Store::save_tree( $id, $items, 'unysonplus/create-page', 'Created page' );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		return self::result( $id, $rev, 'Created page.' );
	}

	public static function insert_items( $in ) {
		$post_id = (int) $in['post_id'];
		$guard   = self::classic_guard( $post_id, ! empty( $in['convert_classic'] ) );
		if ( $guard ) {
			return $guard;
		}
		$tree = FW_AI_Store::get_tree( $post_id );

		$parent_idx  = array();
		$parent_type = '';
		if ( isset( $in['parent_path'] ) && $in['parent_path'] !== '' ) {
			$parent_idx = FW_AI_Store::resolve( $tree, $in['parent_path'] );
			if ( is_wp_error( $parent_idx ) ) {
				return $parent_idx;
			}
			$parent      = FW_AI_Store::node( $tree, $parent_idx );
			$parent_type = (string) ( $parent['type'] ?? '' );
			if ( ! in_array( $parent_type, FW_AI_Schema::LAYOUT_TYPES, true ) ) {
				return new WP_Error( 'upw_ai_bad_parent', 'parent_path must point at a layout item (section, column, flexbox, container or row), not an element.' );
			}
		}

		$errors = array();
		$prefix = $parent_idx ? FW_AI_Store::path_str( $parent_idx ) : '';
		$items  = FW_AI_Schema::validate_items( $in['items'], $parent_type, $prefix, $errors );
		if ( $errors ) {
			return self::invalid( $errors );
		}
		if ( ! $items ) {
			return new WP_Error( 'upw_ai_empty', 'items is empty.' );
		}

		$list =& FW_AI_Store::children( $tree, $parent_idx );
		$pos  = isset( $in['position'] ) ? max( 0, min( (int) $in['position'], count( $list ) ) ) : count( $list );
		array_splice( $list, $pos, 0, $items );
		unset( $list );

		$rev = FW_AI_Store::save_tree( $post_id, $tree, 'unysonplus/insert-items', sprintf( 'Inserted %d item(s)', count( $items ) ) );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		$paths = array();
		foreach ( array_keys( $items ) as $k ) {
			$paths[] = FW_AI_Store::path_str( array_merge( $parent_idx, array( $pos + $k ) ) );
		}
		return self::result( $post_id, $rev, sprintf( 'Inserted %d item(s).', count( $items ) ), array( 'inserted_paths' => $paths ) );
	}

	public static function update_element( $in ) {
		$post_id = (int) $in['post_id'];
		$tree    = FW_AI_Store::get_tree( $post_id );
		$idx     = FW_AI_Store::resolve( $tree, $in['path'] );
		if ( is_wp_error( $idx ) ) {
			return $idx;
		}
		$node =& FW_AI_Store::node( $tree, $idx );
		$type = (string) ( $node['type'] ?? '' );
		$tag  = $type === 'simple' ? (string) ( $node['shortcode'] ?? '' ) : $type;

		$current = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
		$given   = isset( $in['atts'] ) ? (array) json_decode( wp_json_encode( $in['atts'] ), true ) : array();
		$atts    = ! empty( $in['replace'] ) ? array( 'unique_id' => $current['unique_id'] ?? FW_AI_Schema::unique_id() ) : $current;
		foreach ( $given as $k => $v ) {
			if ( $v === null ) {
				unset( $atts[ $k ] );
			} else {
				$atts[ $k ] = $v;
			}
		}

		$errors = array();
		$check  = array_diff_key( $given, array_filter( $given, 'is_null' ) );
		FW_AI_Schema::validate_atts( $tag, $check, FW_AI_Store::path_str( $idx ), $errors );
		if ( isset( $in['width'] ) ) {
			if ( $type !== 'column' ) {
				$errors[] = 'width only applies to a column.';
			} elseif ( ! preg_match( '/^[1-9]_[1-9][0-9]?$/', (string) $in['width'] ) ) {
				$errors[] = 'width must look like 1_2, 1_3, 2_3 ….';
			} else {
				$node['width'] = (string) $in['width'];
			}
		}
		if ( $errors ) {
			return self::invalid( $errors );
		}
		if ( ! $given && ! isset( $in['width'] ) ) {
			return new WP_Error( 'upw_ai_nothing', 'Nothing to change — pass atts and/or width.' );
		}
		$node['atts'] = $atts;
		unset( $node );

		$rev = FW_AI_Store::save_tree( $post_id, $tree, 'unysonplus/update-element', sprintf( 'Updated %s at %s', $tag, FW_AI_Store::path_str( $idx ) ) );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		return self::result( $post_id, $rev, 'Updated.', array( 'path' => FW_AI_Store::path_str( $idx ) ) );
	}

	public static function move_element( $in ) {
		$post_id = (int) $in['post_id'];
		$tree    = FW_AI_Store::get_tree( $post_id );
		$from    = FW_AI_Store::resolve( $tree, $in['path'] );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		$moving = FW_AI_Store::node( $tree, $from );
		$dest   = array();
		if ( isset( $in['to_parent'] ) && $in['to_parent'] !== '' ) {
			$dest = FW_AI_Store::resolve( $tree, $in['to_parent'] );
			if ( is_wp_error( $dest ) ) {
				return $dest;
			}
			if ( array_slice( $dest, 0, count( $from ) ) === $from ) {
				return new WP_Error( 'upw_ai_bad_move', 'Cannot move an item into itself.' );
			}
		}
		$dest_type = $dest ? (string) ( FW_AI_Store::node( $tree, $dest )['type'] ?? '' ) : '';
		if ( $dest && ! in_array( $dest_type, FW_AI_Schema::LAYOUT_TYPES, true ) ) {
			return new WP_Error( 'upw_ai_bad_parent', 'to_parent must point at a layout item.' );
		}
		$errors = array();
		FW_AI_Schema::validate_node( $moving, $dest_type, 'moved item', $errors );
		if ( $errors ) {
			return self::invalid( $errors );
		}

		$src_list =& FW_AI_Store::children( $tree, array_slice( $from, 0, -1 ) );
		array_splice( $src_list, (int) end( $from ), 1 );
		unset( $src_list );

		// Taking the item out shifts later siblings (and their descendants) up by one: when
		// the destination runs through such a sibling, adjust that index.
		$dest_idx = $dest;
		$depth    = count( $from ) - 1;
		if ( count( $dest_idx ) > $depth && array_slice( $dest_idx, 0, $depth ) === array_slice( $from, 0, $depth ) && $dest_idx[ $depth ] > $from[ $depth ] ) {
			$dest_idx[ $depth ]--;
		}
		$list =& FW_AI_Store::children( $tree, $dest_idx );
		$pos  = max( 0, min( (int) $in['position'], count( $list ) ) );
		array_splice( $list, $pos, 0, array( $moving ) );
		unset( $list );

		$rev = FW_AI_Store::save_tree( $post_id, $tree, 'unysonplus/move-element', sprintf( 'Moved item %s', $in['path'] ) );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		return self::result( $post_id, $rev, 'Moved.', array( 'new_path' => FW_AI_Store::path_str( array_merge( $dest_idx, array( $pos ) ) ) ) );
	}

	public static function remove_element( $in ) {
		$post_id = (int) $in['post_id'];
		$tree    = FW_AI_Store::get_tree( $post_id );
		$idx     = FW_AI_Store::resolve( $tree, $in['path'] );
		if ( is_wp_error( $idx ) ) {
			return $idx;
		}
		$node  = FW_AI_Store::node( $tree, $idx );
		$label = (string) ( $node['shortcode'] ?? $node['type'] ?? 'item' );
		$list  =& FW_AI_Store::children( $tree, array_slice( $idx, 0, -1 ) );
		array_splice( $list, (int) end( $idx ), 1 );
		unset( $list );

		$rev = FW_AI_Store::save_tree( $post_id, $tree, 'unysonplus/remove-element', sprintf( 'Removed %s at %s', $label, FW_AI_Store::path_str( $idx ) ) );
		if ( is_wp_error( $rev ) ) {
			return $rev;
		}
		return self::result( $post_id, $rev, sprintf( 'Removed %s. unysonplus/undo restores it.', $label ) );
	}

	/* ------------------------------------------------------------------ *
	 * Write helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $post_type
	 * @return bool
	 */
	private static function builder_supports( $post_type ) {
		if ( ! function_exists( 'fw_ext_page_builder_get_supported_post_types' ) ) {
			return $post_type === 'page';
		}
		$types = (array) fw_ext_page_builder_get_supported_post_types();
		return isset( $types[ $post_type ] ) || in_array( $post_type, $types, true );
	}

	/**
	 * Refuse to overwrite classic content unless asked.
	 *
	 * @param int  $post_id
	 * @param bool $confirmed
	 * @return WP_Error|null
	 */
	private static function classic_guard( $post_id, $confirmed ) {
		if ( FW_AI_Store::is_builder_active( $post_id ) || $confirmed ) {
			return null;
		}
		if ( trim( (string) get_post_field( 'post_content', $post_id, 'raw' ) ) === '' ) {
			return null;
		}
		return new WP_Error( 'upw_ai_classic_content', 'This page has classic (non-builder) content that the page builder will replace. Ask the user, then retry with convert_classic: true. unysonplus/undo restores the classic content.' );
	}

	/**
	 * @param int $post_id
	 * @return string '' or a warning that someone has the page open.
	 */
	private static function lock_warning( $post_id ) {
		if ( FW_AI_Store::is_sandboxed( $post_id ) ) {
			return ''; // The builder panel: the person holding the lock is the one asking.
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$user = wp_check_post_lock( $post_id );
		if ( ! $user ) {
			return '';
		}
		$u = get_userdata( $user );
		return sprintf( '%s currently has this page open in the editor; saving there will overwrite AI changes.', $u ? $u->display_name : 'Another user' );
	}

	/**
	 * @param string[] $errors
	 * @return WP_Error
	 */
	private static function invalid( array $errors ) {
		return new WP_Error(
			'upw_ai_invalid',
			'The items did not validate — nothing was changed. Fix these and retry: ' . implode( ' | ', array_slice( $errors, 0, 25 ) ),
			array( 'status' => 400, 'errors' => $errors )
		);
	}

	/**
	 * Standard write result.
	 *
	 * @param int    $post_id
	 * @param int    $rev
	 * @param string $message
	 * @param array  $extra
	 * @return array
	 */
	private static function result( $post_id, $rev, $message, array $extra = array() ) {
		$out = array_merge( array(
			'ok'               => true,
			'message'          => $message,
			'post_id'          => (int) $post_id,
			'undo_revision_id' => (int) $rev,
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
			'preview_url'      => get_preview_post_link( $post_id ),
		), $extra, array(
			'outline' => FW_AI_Store::outline( FW_AI_Store::get_tree( $post_id ) ),
		) );
		$lock = self::lock_warning( $post_id );
		if ( $lock ) {
			$out['warning'] = $lock;
		}
		return $out;
	}
}
