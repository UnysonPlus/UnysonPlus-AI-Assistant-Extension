<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What the assistant knows about WHERE the person is, and what it suggests there.
 *
 * The panel is opened on a builder page or on an admin screen. For each, this builds:
 *   key         — the conversation key (post:<id> / screen:<id>), so a saved conversation belongs to it
 *   label       — a human name for the place ("Settings → General", "the page builder for “About”")
 *   facts       — short, real statements about it (the tagline, how many pages lack an SEO description …),
 *                 sent with every request so "what should I change here?" is about this place
 *   suggestions — up to four starter requests, chosen by rules from those facts — never invented by a
 *                 model: instant, free, and only things the tools can actually do
 *
 * Also registers `update-site-identity` (site title, tagline, site icon), which the Settings → General
 * suggestions need and nothing else covered.
 */
class FW_AI_Context {

	const CACHE_TTL = 600;

	public static function init() {
		add_action( 'fw_ai_assistant_register_abilities', array( __CLASS__, 'register_abilities' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Context
	 * ------------------------------------------------------------------ */

	/**
	 * @param WP_Screen|null $screen
	 * @return array { key, label, facts[], suggestions[] }
	 */
	public static function for_screen( $screen ) {
		$id  = $screen instanceof WP_Screen ? (string) $screen->id : '';
		$out = array( 'key' => 'screen:' . ( $id !== '' ? $id : 'admin' ), 'label' => '', 'facts' => array(), 'suggestions' => array() );

		if ( $id === 'dashboard' ) {
			$out['label'] = 'the Dashboard';
			self::site_overview( $out );
		} elseif ( $id === 'options-general' ) {
			$out['label'] = 'Settings → General';
			self::general_settings( $out );
		} elseif ( $id === 'edit-page' ) {
			$out['label'] = 'the Pages list';
			self::pages_list( $out );
		} elseif ( $id === 'nav-menus' ) {
			$out['label'] = 'Appearance → Menus';
			self::menus( $out );
		} elseif ( strpos( $id, 'fw-settings' ) !== false ) {
			$out['label'] = 'Theme Settings';
			self::theme_settings( $out );
		} elseif ( $id === 'edit-product' && post_type_exists( 'product' ) ) {
			$out['label'] = 'the Products list';
			self::products( $out );
		} else {
			$out['label'] = $screen instanceof WP_Screen && get_admin_page_title() ? wp_strip_all_tags( get_admin_page_title() ) : 'the WordPress admin';
			self::site_overview( $out );
		}
		$out['suggestions'] = array_slice( array_values( array_unique( array_filter( $out['suggestions'] ) ) ), 0, 4 );
		return $out;
	}

	/**
	 * The builder page the panel is on.
	 *
	 * @param int $post_id
	 * @return array { key, label, facts[], suggestions[] }
	 */
	public static function for_post( $post_id ) {
		$post  = get_post( $post_id );
		$title = $post && $post->post_status !== 'auto-draft' ? get_the_title( $post ) : '';
		$out   = array(
			'key'         => 'post:' . (int) $post_id,
			'label'       => $title !== '' ? sprintf( 'the page builder for "%s"', $title ) : 'the page builder for a new page',
			'facts'       => array(),
			'suggestions' => array(),
		);
		$tree  = $post ? FW_AI_Store::get_tree( (int) $post_id ) : array();
		$roots = count( $tree );
		$tags  = array();
		array_walk_recursive( $tree, function ( $v, $k ) use ( &$tags ) {
			if ( $k === 'shortcode' && is_string( $v ) ) {
				$tags[ $v ] = true;
			}
		} );

		if ( $roots === 0 ) {
			$out['facts'][]       = 'The saved page is empty.';
			$out['suggestions'][] = 'Build a starter layout: a hero, three feature cards and a call to action';
			$out['suggestions'][] = 'Add an introduction section about what we do';
		} else {
			$out['facts'][] = sprintf( 'The saved page has %d top-level section(s).', $roots );
			if ( empty( $tags['button'] ) ) {
				$out['facts'][]       = 'It has no button (no call to action).';
				$out['suggestions'][] = 'Add a call to action at the end of the page';
			}
			if ( empty( $tags['accordion'] ) ) {
				$out['suggestions'][] = 'Add a FAQ section with four questions';
			}
			$out['suggestions'][] = 'Check this page for problems and fix what you find';
			$out['suggestions'][] = 'Rewrite the headings to sound more confident';
		}

		$seo = self::seo_page( (int) $post_id );
		if ( $seo !== null && $post && $post->post_status === 'publish' ) {
			if ( $seo ) {
				$out['facts'][] = 'SEO: ' . implode( ' ', $seo );
				array_unshift( $out['suggestions'], 'Write an SEO title and description for this page' );
			} else {
				$out['facts'][] = 'SEO: the title and description look fine.';
			}
		}
		$out['suggestions'] = array_slice( array_values( array_unique( $out['suggestions'] ) ), 0, 4 );
		return $out;
	}

	/**
	 * The context as text for the model's instructions.
	 *
	 * @param array $ctx
	 * @return string
	 */
	public static function text( array $ctx ) {
		if ( empty( $ctx['label'] ) ) {
			return '';
		}
		$lines = array( 'Where the person is right now: ' . $ctx['label'] . '.' );
		foreach ( (array) $ctx['facts'] as $f ) {
			$lines[] = '- ' . $f;
		}
		$lines[] = 'When they ask what to do or for ideas, base your answer on this place and these facts.';
		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ *
	 * Per-screen rules
	 * ------------------------------------------------------------------ */

	private static function site_overview( array &$out ) {
		$pages   = wp_count_posts( 'page' );
		$publish = (int) ( $pages->publish ?? 0 );
		$drafts  = (int) ( $pages->draft ?? 0 );
		$out['facts'][] = sprintf( 'The site "%s" has %d published page(s) and %d draft(s).', self::site_name(), $publish, $drafts );

		$tagline = self::tagline();
		if ( $tagline === '' || stripos( $tagline, 'Just another WordPress site' ) !== false ) {
			$out['facts'][]       = 'The tagline is empty or still the WordPress default.';
			$out['suggestions'][] = 'Write a tagline for the site and apply it';
		}
		if ( $publish < 3 ) {
			$out['suggestions'][] = 'Create a draft Home page with a hero, services and a call to action';
		}
		if ( self::has_ability( 'menus-create' ) && ! self::primary_menu_assigned() ) {
			$out['facts'][]       = 'No menu is assigned to the main navigation location.';
			$out['suggestions'][] = 'Build the main menu from the published pages';
		}
		$out['suggestions'][] = 'Give the site a warm colour palette and friendly fonts';
		$out['suggestions'][] = 'Create a draft About page with our story, values and team';
	}

	private static function general_settings( array &$out ) {
		$name    = self::site_name();
		$tagline = self::tagline();
		$out['facts'][] = sprintf( 'Site title: "%s".', $name );
		$out['facts'][] = $tagline !== '' ? sprintf( 'Tagline: "%s".', $tagline ) : 'The tagline is empty.';
		$icon = (int) get_option( 'site_icon' );
		$logo = (int) get_theme_mod( 'custom_logo' );
		$out['facts'][] = $icon ? 'A site icon is set.' : 'No site icon is set.';
		if ( $tagline === '' || stripos( $tagline, 'Just another WordPress site' ) !== false ) {
			$out['suggestions'][] = sprintf( 'Write a tagline for %s and apply it', $name );
		} else {
			$out['suggestions'][] = 'Suggest three sharper taglines, then apply the best one';
		}
		$out['suggestions'][] = 'Check that the site title and tagline read well together';
		if ( ! $icon && $logo ) {
			$out['facts'][]       = 'The theme has a logo in the Media Library (attachment ' . $logo . ').';
			$out['suggestions'][] = 'Use the site logo as the site icon';
		}
	}

	private static function pages_list( array &$out ) {
		$pages   = wp_count_posts( 'page' );
		$out['facts'][] = sprintf( '%d published page(s), %d draft(s).', (int) ( $pages->publish ?? 0 ), (int) ( $pages->draft ?? 0 ) );
		$missing = self::seo_missing();
		if ( $missing !== null ) {
			$out['facts'][] = sprintf( '%d published page(s) have no written SEO description.', $missing );
			if ( $missing ) {
				$out['suggestions'][] = sprintf( 'Write SEO titles and descriptions for the %d page(s) missing one', $missing );
			}
		}
		$have = array_map( 'strtolower', wp_list_pluck( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 200 ) ), 'post_name' ) );
		$labels = array( 'contact' => 'Contact', 'about' => 'About', 'faq' => 'FAQ' );
		foreach ( array( 'contact' => 'Create a draft Contact page with a contact form', 'about' => 'Create a draft About page with our story, values and team', 'faq' => 'Create a draft FAQ page with six common questions' ) as $slug => $idea ) {
			$found = false;
			foreach ( $have as $h ) {
				if ( strpos( $h, $slug ) !== false ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$out['facts'][]       = sprintf( 'There is no %s page.', $labels[ $slug ] );
				$out['suggestions'][] = $idea;
			}
		}
		$out['suggestions'][] = 'Check every published page for problems and list what to fix';
	}

	private static function menus( array &$out ) {
		$menus = wp_get_nav_menus();
		$out['facts'][] = sprintf( '%d menu(s) exist.', count( $menus ) );
		if ( ! self::has_ability( 'menus-create' ) ) {
			$out['facts'][] = 'Menu tools need the Mega Menu extension, which is not active.';
			return;
		}
		if ( ! self::primary_menu_assigned() ) {
			$out['facts'][]       = 'No menu is assigned to the main navigation location.';
			$out['suggestions'][] = 'Build the main menu from the published pages and show it in the header';
		}
		$in_menu = array();
		foreach ( $menus as $m ) {
			foreach ( (array) wp_get_nav_menu_items( $m->term_id ) as $item ) {
				if ( $item->object === 'page' ) {
					$in_menu[ (int) $item->object_id ] = true;
				}
			}
		}
		$missing = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 200 ) ) as $p ) {
			if ( empty( $in_menu[ $p->ID ] ) && (int) get_option( 'page_on_front' ) !== $p->ID ) {
				$missing[] = get_the_title( $p );
			}
		}
		if ( $missing ) {
			$out['facts'][]       = sprintf( '%d published page(s) are in no menu: %s.', count( $missing ), implode( ', ', array_slice( $missing, 0, 8 ) ) );
			$out['suggestions'][] = sprintf( 'Add the %d published page(s) that are in no menu to the main menu', count( $missing ) );
		}
		$out['suggestions'][] = 'Order the main menu the way a first-time visitor would look for things';
	}

	private static function theme_settings( array &$out ) {
		$out['facts'][]       = 'Theme Settings changes are live immediately and can be undone.';
		$out['suggestions'][] = 'Give the site a colour palette that suits ' . self::site_name();
		$out['suggestions'][] = 'Pick a heading and body font pair that feels friendly and readable';
		$out['suggestions'][] = 'Add a rounded "Pill" button style and use it for calls to action';
		$out['suggestions'][] = 'Tighten the container width and section spacing for easier reading';
	}

	private static function products( array &$out ) {
		$counts = wp_count_posts( 'product' );
		$out['facts'][] = sprintf( '%d published product(s).', (int) ( $counts->publish ?? 0 ) );
		if ( ! self::has_ability( 'woo-save-product' ) ) {
			return;
		}
		$empty = 0;
		foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 200 ) ) as $p ) {
			if ( trim( wp_strip_all_tags( $p->post_content ) ) === '' ) {
				$empty++;
			}
		}
		if ( $empty ) {
			$out['facts'][]       = sprintf( '%d product(s) have no description.', $empty );
			$out['suggestions'][] = sprintf( 'Write descriptions for the %d product(s) that have none', $empty );
		}
		$out['suggestions'][] = 'Add a "Best Seller" ribbon to the three most popular products';
		$out['suggestions'][] = 'Create a draft product from a short description I give you';
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** The site title / tagline as plain text (get_bloginfo() returns them HTML-escaped). */
	private static function site_name() {
		return trim( wp_strip_all_tags( html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function tagline() {
		return trim( wp_strip_all_tags( html_entity_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function has_ability( $slug ) {
		// wp_has_ability(), not wp_get_ability(): since WP 6.9 the latter raises a _doing_it_wrong notice for an
		// ability that is not registered (e.g. the menu tools while Mega Menu is inactive).
		return function_exists( 'wp_has_ability' ) && wp_has_ability( 'unysonplus/' . $slug );
	}

	private static function primary_menu_assigned() {
		$locations = get_nav_menu_locations();
		foreach ( array_keys( (array) get_registered_nav_menus() ) as $loc ) {
			if ( ! empty( $locations[ $loc ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SEO hints for one page, or null when the SEO extension is not active.
	 *
	 * @param int $post_id
	 * @return string[]|null
	 */
	private static function seo_page( $post_id ) {
		if ( ! function_exists( 'fw_ext_seo_ai_page' ) || ! $post_id ) {
			return null;
		}
		$s = fw_ext_seo_ai_page( $post_id );
		$h = (array) ( $s['hints'] ?? array() );
		if ( ( $s['effective']['description']['source'] ?? '' ) === 'auto' ) {
			$h[] = 'The description is auto-generated from the content.';
		}
		return $h;
	}

	/**
	 * How many published pages have no written SEO description (cached), or null without the SEO extension.
	 *
	 * @return int|null
	 */
	private static function seo_missing() {
		if ( ! function_exists( 'fw_ext_seo_ai_page' ) ) {
			return null;
		}
		$cached = get_transient( 'upw_ai_ctx_seo_missing' );
		if ( $cached !== false ) {
			return (int) $cached;
		}
		$n = 0;
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids' ) ) as $id ) {
			$s   = fw_ext_seo_ai_page( $id );
			$src = (string) ( $s['effective']['description']['source'] ?? '' );
			$val = (string) ( $s['effective']['description']['value'] ?? '' );
			if ( $val === '' || $src === 'auto' ) {
				$n++;
			}
		}
		set_transient( 'upw_ai_ctx_seo_missing', $n, self::CACHE_TTL );
		return $n;
	}

	/* ------------------------------------------------------------------ *
	 * update-site-identity
	 * ------------------------------------------------------------------ */

	public static function register_abilities() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}
		fw_ai_register_ability( 'update-site-identity', array(
			'label'       => __( 'Update the site title, tagline or icon', 'fw' ),
			'description' => 'Sets WordPress\'s own site identity (Settings → General): title, tagline, and the site icon (a square image already in the Media Library, by attachment id; 0 removes it). Pass only what should change. Live immediately; undo_change reverts it.',
			'input'       => array(
				'title'   => array( 'type' => 'string' ),
				'tagline' => array( 'type' => 'string' ),
				'icon_id' => array( 'type' => 'integer' ),
			),
			'permission'  => 'manage_options',
			'execute'     => array( __CLASS__, 'update_identity' ),
		) );
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function update_identity( $in ) {
		$in      = (array) $in;
		$changes = array();
		$errors  = array();
		if ( array_key_exists( 'title', $in ) ) {
			$t = trim( wp_strip_all_tags( (string) $in['title'] ) );
			if ( $t === '' ) {
				$errors[] = 'title cannot be empty.';
			} elseif ( mb_strlen( $t ) > 80 ) {
				$errors[] = 'title is longer than 80 characters.';
			} else {
				$changes['blogname'] = $t;
			}
		}
		if ( array_key_exists( 'tagline', $in ) ) {
			$t = trim( wp_strip_all_tags( (string) $in['tagline'] ) );
			if ( mb_strlen( $t ) > 160 ) {
				$errors[] = 'tagline is longer than 160 characters.';
			} else {
				$changes['blogdescription'] = $t;
			}
		}
		if ( array_key_exists( 'icon_id', $in ) ) {
			$id = (int) $in['icon_id'];
			if ( $id && ! wp_attachment_is_image( $id ) ) {
				$errors[] = 'icon_id is not an image in the Media Library.';
			} else {
				$changes['site_icon'] = $id;
			}
		}
		if ( $errors ) {
			return new WP_Error( 'upw_ai_identity_invalid', 'Nothing was changed: ' . implode( ' ', $errors ), array( 'status' => 400 ) );
		}
		if ( ! $changes ) {
			return new WP_Error( 'upw_ai_identity_empty', 'Pass at least one of title, tagline, icon_id.', array( 'status' => 400 ) );
		}
		$labels = array( 'blogname' => 'title', 'blogdescription' => 'tagline', 'site_icon' => 'site icon' );
		$rev    = fw_ai_snapshot( array( 'options' => array_keys( $changes ) ), 'unysonplus/update-site-identity', 'Site ' . implode( ', ', array_intersect_key( $labels, $changes ) ) );
		foreach ( $changes as $opt => $v ) {
			update_option( $opt, $v );
		}
		return array(
			'ok'               => true,
			'message'          => 'Updated the site ' . implode( ', ', array_intersect_key( $labels, $changes ) ) . '.',
			'updated'          => $changes,
			'undo_revision_id' => (int) $rev,
		);
	}
}
