<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The build docs, for an AI app working through the MCP server.
 *
 *   build-docs   read: the AI Dev Kit's build guidance (how to build a site, a page, a form, the CRM;
 *                each element, extension and Theme Settings tab), one Markdown file at a time
 *
 * A coding agent clones the AI Dev Kit and reads its docs from disk. An AI app connected through MCP
 * has no disk, so this serves the same files from the kit's public repository, cached on the site.
 * The element and option facts the tools already return (list-elements, describe-element,
 * describe-theme-settings) stay the source of truth for shapes; the docs add the rules and the order.
 *
 * Filter `fw_ai_build_docs_base` changes where the files come from (a fork, a pinned tag).
 */
class FW_AI_Docs {

	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const MAX_CHARS = 60000;

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register' ) );
	}

	/** @return string Base URL of the kit's docs folder, with a trailing slash. */
	public static function base() {
		/** Filters the base URL the build-docs ability reads the AI Dev Kit docs from. */
		return trailingslashit( (string) apply_filters( 'fw_ai_build_docs_base', 'https://raw.githubusercontent.com/UnysonPlus/UnysonPlus-AI-Dev-Kit/main/docs/' ) );
	}

	public static function register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		fw_ai_register_ability( 'build-docs', array(
			'label'       => __( 'Read the build docs', 'fw' ),
			'description' => 'The Unyson+ build guidance (the AI Dev Kit docs), one Markdown file at a time. Call with no path first: it returns the index and a reading order for building a site. Then pass path (relative to the docs folder, e.g. "building-pages.md", "theme-settings/header.md", "extensions/crm.md", "shortcodes/contact-form.md"). Read the doc for an element, extension or Theme Settings tab before using it. The docs are written for coding agents too: skip anything that needs files, a terminal or WP-CLI and use the matching tool instead (create_page, insert_items, update_theme_settings, crm_save_lists …). For exact option shapes, describe_element and describe_theme_settings are authoritative.',
			'input'       => array(
				'path' => array( 'type' => 'string' ),
			),
			'permission'  => 'edit_posts',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'read' ),
		) );
	}

	/**
	 * @param array|null $in
	 * @return array|WP_Error
	 */
	public static function read( $in = null ) {
		$path = trim( (string) ( is_array( $in ) ? ( $in['path'] ?? '' ) : '' ), '/ ' );

		if ( '' === $path ) {
			$index = self::fetch( 'README.md' );
			if ( is_wp_error( $index ) ) {
				return $index;
			}
			return array(
				'path'          => 'README.md',
				'reading_order' => array(
					'site-build-protocol.md (the "Fresh build" section: the order of work)',
					'build-reference.md (Part 2, fresh builds)',
					'building-pages.md (how pages are put together)',
					'theme-settings/README.md, then the tab you are changing',
					'the doc of each element (shortcodes/…) and extension (extensions/…) you use',
				),
				'content'       => $index,
			);
		}

		if ( false !== strpos( $path, '..' ) || ! preg_match( '#^[a-z0-9][a-z0-9/_.-]*\.md$#i', $path ) ) {
			return new WP_Error( 'fw_ai_docs_path', 'path must be a .md file inside the docs folder, e.g. "building-pages.md" or "extensions/forms.md". Call build_docs with no path for the index.' );
		}

		$text = self::fetch( $path );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return array( 'path' => $path, 'content' => $text );
	}

	/**
	 * One docs file, from the transient cache or the repository.
	 *
	 * @param string $path
	 * @return string|WP_Error
	 */
	private static function fetch( $path ) {
		$url = self::base() . $path;
		$key = 'fw_ai_docs_' . md5( $url );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$res  = wp_remote_get( $url, array( 'timeout' => 15 ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

		if ( 404 === $code ) {
			return new WP_Error( 'fw_ai_docs_missing', sprintf( 'There is no doc at "%s". Call build_docs with no path for the index.', $path ) );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'fw_ai_docs_unreachable', 'The build docs could not be fetched from this site (its server may block outgoing requests). Carry on with list_elements, describe_element and describe_theme_settings, which describe every element and setting.' );
		}

		$text = (string) wp_remote_retrieve_body( $res );
		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS ) . "\n\n[… truncated: this doc is longer than " . self::MAX_CHARS . ' characters.]';
		}
		set_transient( $key, $text, self::CACHE_TTL );

		return $text;
	}
}
FW_AI_Docs::init();
