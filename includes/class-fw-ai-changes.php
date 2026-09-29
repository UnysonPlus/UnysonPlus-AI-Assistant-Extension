<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ → AI Changes: every change the AI made to the site, newest first, with a way to undo each.
 *
 * It reads the three places the assistant already records a change before making it, so nothing new is
 * stored here:
 *   page     — a page-builder snapshot (post meta _upw_ai_revision, FW_AI_Store): the page as it was
 *   settings — a Theme Settings snapshot (option upw_ai_settings_revisions, FW_AI_Settings)
 *   other    — an extension snapshot (option upw_ai_change_revisions, FW_AI_Toolkit): menus, forms,
 *              SEO, products, site identity …
 *
 * Every undo snapshots the current state first, so the undo shows up here too and can be redone.
 * Changes the chat panel makes inside the page builder are applied UNSAVED and are not listed: the
 * builder's own Undo covers them until the person presses Update.
 */
class FW_AI_Changes {

	const PAGE_SLUG = 'fw-ai-changes';
	const LIMIT     = 150;

	/** @var string */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
	}

	/**
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	public static function menu() {
		self::$hook = (string) add_submenu_page(
			FW_Extension_AI_Assistant::PARENT_SLUG,
			__( 'AI Changes', 'fw' ),
			__( 'AI Changes', 'fw' ),
			'edit_pages',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'handle' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Collect
	 * ------------------------------------------------------------------ */

	/**
	 * @return array[] { kind, id, time, user, ability, note, undo_record, post_id?, newest?, where, where_url }
	 */
	public static function collect() {
		$out = array_merge( self::pages(), self::settings(), self::others() );
		// An undo is recorded as "Before restoring revision N" / "Before undoing change N": name what it undid.
		$by = array();
		foreach ( $out as $r ) {
			$by[ $r['kind'] . ':' . $r['id'] ] = $r;
		}
		// Which row each undo reverses; a row is "undone" while the undo that reversed it is not itself reversed.
		$target = array();
		foreach ( $out as $r ) {
			if ( $r['undo_record'] && preg_match( '/(?:restoring revision|undoing change) (\d+)/', $r['note'], $m ) ) {
				$target[ $r['kind'] . ':' . $r['id'] ] = $r['kind'] . ':' . $m[1];
			}
		}
		$reversed = array_flip( $target );
		foreach ( $out as &$r ) {
			$key          = $r['kind'] . ':' . $r['id'];
			$undoer       = $reversed[ $key ] ?? null;
			$r['undone']  = $undoer !== null && ! isset( $reversed[ $undoer ] );
			if ( $r['undo_record'] && preg_match( '/(?:restoring revision|undoing change) (\d+)/', $r['note'], $m ) ) {
				$t = $by[ $r['kind'] . ':' . $m[1] ] ?? null;
				$r['note'] = $t && ! $t['undo_record']
					? sprintf( /* translators: %s: the change that was undone */ __( 'Undid: %s', 'fw' ), $t['note'] !== '' ? $t['note'] : self::ability_label( $t['ability'] ) )
					: ( $t ? __( 'Redid a change', 'fw' ) : __( 'Undid an earlier change', 'fw' ) );
			}
		}
		unset( $r );
		usort( $out, function ( $a, $b ) {
			return ( $b['time'] <=> $a['time'] ) ?: ( $b['id'] <=> $a['id'] );
		} );
		return array_slice( $out, 0, self::LIMIT );
	}

	private static function pages() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
			FW_AI_Store::META_REVISION, self::LIMIT
		) );
		$out    = array();
		$newest = array();
		foreach ( $rows as $r ) {
			$meta = get_metadata_by_mid( 'post', (int) $r->meta_id );
			if ( ! $meta || ! is_array( $meta->meta_value ) || ! get_post( (int) $r->post_id ) ) {
				continue;
			}
			$v   = $meta->meta_value;
			$pid = (int) $r->post_id;
			$out[] = array(
				'kind'        => 'page',
				'id'          => (int) $r->meta_id,
				'post_id'     => $pid,
				'time'        => (int) ( $v['time'] ?? 0 ),
				'user'        => (int) ( $v['user'] ?? 0 ),
				'ability'     => (string) ( $v['ability'] ?? '' ),
				'note'        => (string) ( $v['note'] ?? '' ),
				'undo_record' => ( $v['ability'] ?? '' ) === 'unysonplus/undo',
				'newest'      => ! isset( $newest[ $pid ] ),
				'where'       => get_the_title( $pid ) ?: __( '(no title)', 'fw' ),
				'where_url'   => (string) get_edit_post_link( $pid, 'raw' ),
			);
			$newest[ $pid ] = true;
		}
		return $out;
	}

	private static function settings() {
		$out = array();
		foreach ( (array) get_option( FW_AI_Settings::OPTION_REVISIONS, array() ) as $r ) {
			$keys  = array_keys( (array) ( $r['values'] ?? array() ) );
			$out[] = array(
				'kind'        => 'settings',
				'id'          => (int) $r['id'],
				'time'        => (int) ( $r['time'] ?? 0 ),
				'user'        => (int) ( $r['user'] ?? 0 ),
				'ability'     => (string) ( $r['ability'] ?? '' ),
				'note'        => (string) ( $r['note'] ?? '' ),
				'undo_record' => ( $r['ability'] ?? '' ) === 'unysonplus/undo-theme-settings',
				'where'       => __( 'Theme Settings', 'fw' ) . ( $keys ? ': ' . implode( ', ', array_slice( $keys, 0, 4 ) ) . ( count( $keys ) > 4 ? ' …' : '' ) : '' ),
				'where_url'   => admin_url( 'admin.php?page=' . ( function_exists( 'fw' ) ? fw()->backend->_get_settings_page_slug() : 'fw-settings' ) ),
			);
		}
		return $out;
	}

	private static function others() {
		$out = array();
		foreach ( (array) get_option( FW_AI_Toolkit::OPTION_REVISIONS, array() ) as $r ) {
			$v     = (array) ( $r['values'] ?? array() );
			$where = self::toolkit_where( $v );
			$out[] = array(
				'kind'        => 'other',
				'id'          => (int) $r['id'],
				'time'        => (int) ( $r['time'] ?? 0 ),
				'user'        => (int) ( $r['user'] ?? 0 ),
				'ability'     => (string) ( $r['ability'] ?? '' ),
				'note'        => (string) ( $r['note'] ?? '' ),
				'undo_record' => ( $r['ability'] ?? '' ) === 'unysonplus/undo-change',
				'where'       => $where[0],
				'where_url'   => $where[1],
			);
		}
		return $out;
	}

	/**
	 * What an extension snapshot touched, as a label and a link.
	 *
	 * @param array $v
	 * @return array { label, url }
	 */
	private static function toolkit_where( array $v ) {
		$posts = array_merge(
			(array) ( $v['created_posts'] ?? array() ),
			(array) ( $v['trashed_posts'] ?? array() ),
			array_keys( (array) ( $v['post_fields'] ?? array() ) ),
			array_keys( (array) ( $v['post_meta'] ?? array() ) ),
			array_keys( (array) ( $v['post_terms'] ?? array() ) )
		);
		foreach ( $posts as $pid ) {
			$post = get_post( (int) $pid );
			if ( $post && $post->post_type !== 'nav_menu_item' ) {
				$type = get_post_type_object( $post->post_type );
				return array( ( $type ? $type->labels->singular_name . ': ' : '' ) . ( get_the_title( $post ) ?: __( '(no title)', 'fw' ) ), (string) get_edit_post_link( $post->ID, 'raw' ) );
			}
			if ( $post ) {
				return array( __( 'Menus', 'fw' ), admin_url( 'nav-menus.php' ) );
			}
		}
		if ( ! empty( $v['created_menus'] ) ) {
			return array( __( 'Menus', 'fw' ), admin_url( 'nav-menus.php' ) );
		}
		$opts = array_keys( (array) ( $v['options'] ?? array() ) );
		if ( array_intersect( $opts, array( 'blogname', 'blogdescription', 'site_icon' ) ) ) {
			return array( __( 'Settings, General', 'fw' ), admin_url( 'options-general.php' ) );
		}
		if ( array_intersect( $opts, array( 'nav_menu_locations', 'theme_mods_' . get_stylesheet() ) ) ) {
			return array( __( 'Menus', 'fw' ), admin_url( 'nav-menus.php' ) );
		}
		return array( __( 'Site settings', 'fw' ), '' );
	}

	/* ------------------------------------------------------------------ *
	 * Undo / redo
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $row
	 * @return bool
	 */
	private static function can_act( array $row ) {
		return $row['kind'] === 'page' ? current_user_can( 'edit_post', (int) $row['post_id'] ) : current_user_can( 'manage_options' );
	}

	public static function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['upw_ai_change'] ) ) {
			return;
		}
		check_admin_referer( 'upw_ai_change' );
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$id   = (int) ( $_POST['id'] ?? 0 );
		$row  = null;
		foreach ( self::collect() as $r ) {
			if ( $r['kind'] === $kind && $r['id'] === $id ) {
				$row = $r;
				break;
			}
		}
		$back = wp_get_referer() ?: self::url();
		if ( ! $row ) {
			wp_safe_redirect( add_query_arg( 'upw_ai_msg', 'missing', $back ) );
			exit;
		}
		if ( ! self::can_act( $row ) ) {
			wp_die( esc_html__( 'You are not allowed to undo this change.', 'fw' ), 403 );
		}
		if ( $kind === 'page' ) {
			$res = FW_AI_Store::restore( (int) $row['post_id'], $id );
		} elseif ( $kind === 'settings' ) {
			$res = FW_AI_Settings::undo( $id );
		} else {
			$res = FW_AI_Toolkit::restore( $id );
		}
		wp_safe_redirect( add_query_arg( 'upw_ai_msg', is_wp_error( $res ) ? 'failed' : ( $row['undo_record'] ? 'redone' : 'undone' ), $back ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Screen
	 * ------------------------------------------------------------------ */

	public static function render() {
		$rows   = self::collect();
		$filter = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$counts = array( '' => count( $rows ), 'page' => 0, 'settings' => 0, 'other' => 0 );
		foreach ( $rows as $r ) {
			$counts[ $r['kind'] ]++;
		}
		if ( $filter && isset( $counts[ $filter ] ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $filter ) {
				return $r['kind'] === $filter;
			} ) );
		}
		$msg      = isset( $_GET['upw_ai_msg'] ) ? sanitize_key( wp_unslash( $_GET['upw_ai_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'undone'  => array( 'success', __( 'Change undone. The state it replaced was saved first, so you can redo it from the top of the list.', 'fw' ) ),
			'redone'  => array( 'success', __( 'Change redone.', 'fw' ) ),
			'failed'  => array( 'error', __( 'That change could not be undone.', 'fw' ) ),
			'missing' => array( 'warning', __( 'That change is no longer in the history (only the most recent changes are kept).', 'fw' ) ),
		);
		$labels = array(
			''         => __( 'All', 'fw' ),
			'page'     => __( 'Pages', 'fw' ),
			'settings' => __( 'Theme Settings', 'fw' ),
			'other'    => __( 'Other', 'fw' ),
		);
		?>
		<div class="wrap upw-ai-changes">
			<h1><?php esc_html_e( 'AI Changes', 'fw' ); ?></h1>
			<p class="upw-ai-changes__lede"><?php esc_html_e( 'Every change the AI made to your site, newest first. Each one was saved before it was made, so you can undo it here; an undo is saved too, so it can be redone.', 'fw' ); ?></p>

			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $labels as $k => $label ) {
					$links[] = sprintf(
						'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>',
						esc_url( $k ? self::url( array( 'kind' => $k ) ) : self::url() ),
						$filter === $k ? ' class="current" aria-current="page"' : '',
						esc_html( $label ),
						(int) $counts[ $k ]
					);
				}
				echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</ul>

			<?php if ( ! $rows ) : ?>
				<div class="upw-ai-changes__empty">
					<h2><?php esc_html_e( 'No AI changes yet', 'fw' ); ?></h2>
					<p><?php esc_html_e( 'When the AI Assistant or an outside AI program changes your site, each change appears here with a way to undo it.', 'fw' ); ?></p>
					<p><?php esc_html_e( 'Changes the chat panel makes inside the page builder are not saved until you press Update, so the builder\'s own Undo covers them.', 'fw' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped upw-ai-changes__table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'When', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What changed', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'By', 'fw' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'fw' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$user   = $r['user'] ? get_userdata( $r['user'] ) : null;
						$label  = self::action_label( $r );
						$when   = $r['time'] ? sprintf( /* translators: %s: time span */ __( '%s ago', 'fw' ), human_time_diff( $r['time'] ) ) : '';
						$note   = $r['note'] !== '' ? $r['note'] : self::ability_label( $r['ability'] );
						?>
						<tr class="<?php echo $r['undo_record'] ? 'is-undo' : ''; ?>">
							<td class="upw-ai-changes__when"><time datetime="<?php echo esc_attr( gmdate( 'c', $r['time'] ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['time'] ) ); ?>"><?php echo esc_html( $when ); ?></time></td>
							<td>
								<?php echo esc_html( $note ); ?>
							</td>
							<td><?php echo $r['where_url'] ? '<a href="' . esc_url( $r['where_url'] ) . '">' . esc_html( $r['where'] ) . '</a>' : esc_html( $r['where'] ); ?></td>
							<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
							<td class="upw-ai-changes__action">
								<?php if ( ! empty( $r['undone'] ) ) : ?>
									<span class="upw-ai-changes__muted"><?php esc_html_e( 'Undone', 'fw' ); ?></span>
								<?php elseif ( self::can_act( $r ) ) : ?>
									<form method="post" action="<?php echo esc_url( self::url( $filter ? array( 'kind' => $filter ) : array() ) ); ?>">
										<?php wp_nonce_field( 'upw_ai_change' ); ?>
										<input type="hidden" name="upw_ai_change" value="1">
										<input type="hidden" name="kind" value="<?php echo esc_attr( $r['kind'] ); ?>">
										<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
										<button type="submit" class="button button-small" onclick="return confirm( <?php echo esc_attr( wp_json_encode( self::confirm_text( $r ) ) ); ?> )"><?php echo esc_html( $label ); ?></button>
									</form>
								<?php else : ?>
									<span class="upw-ai-changes__muted"><?php esc_html_e( 'Administrators only', 'fw' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="upw-ai-changes__muted"><?php esc_html_e( 'Kept: the latest 20 changes per page, 20 Theme Settings changes and 30 other changes. Changes the chat panel makes in the page builder are undone with the builder\'s own Undo until you press Update.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>
		<style>
			.upw-ai-changes { max-width: 72rem; }
			.upw-ai-changes__lede { max-width: 70ch; font-size: 14px; color: var(--upa-text2, #3c434a); }
			.upw-ai-changes .subsubsub { float: none; margin: 0 0 .75rem; }
			.upw-ai-changes__table td { vertical-align: middle; }
			.upw-ai-changes__when { white-space: nowrap; color: var(--upa-text2, #3c434a); }
			.upw-ai-changes__action { text-align: right; white-space: nowrap; }
			.upw-ai-changes__action form { margin: 0; }
			.upw-ai-changes__tag { display: inline-block; padding: 0 .4rem; border-radius: 4px; background: var(--upa-panel2, #f0f0f1); color: var(--upa-text2, #3c434a); font-size: 12px; font-weight: 600; }
			.upw-ai-changes__muted { color: var(--upa-text3, #50575e); }
			.upw-ai-changes__empty { padding: 1.5rem 1.25rem; border: 1px solid var(--upa-border, #dcdcde); border-radius: var(--upa-radius-lg, 8px); background: var(--upa-panel, #fff); max-width: 60ch; }
			.upw-ai-changes__empty h2 { margin-top: 0; font-size: 15px; }
		</style>
		<?php
	}

	/**
	 * @param array $r
	 * @return string
	 */
	private static function action_label( array $r ) {
		if ( $r['undo_record'] ) {
			return __( 'Redo', 'fw' );
		}
		if ( $r['kind'] === 'page' && empty( $r['newest'] ) ) {
			return __( 'Restore page to before this', 'fw' );
		}
		return __( 'Undo', 'fw' );
	}

	/**
	 * @param array $r
	 * @return string
	 */
	private static function confirm_text( array $r ) {
		if ( $r['undo_record'] ) {
			return __( 'Redo this change (put back what the undo removed)?', 'fw' );
		}
		if ( $r['kind'] === 'page' && empty( $r['newest'] ) ) {
			return __( 'Restore this page to how it was before this change? Later AI changes to this page are rolled back too. The current page is saved first, so this can be redone.', 'fw' );
		}
		if ( $r['kind'] === 'settings' ) {
			return __( 'Undo this Theme Settings change? The settings it touched go back to their earlier values, and the change is live immediately.', 'fw' );
		}
		return __( 'Undo this change?', 'fw' );
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private static function ability_label( $name ) {
		$a = function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		return $a ? $a->get_label() : ( $name !== '' ? $name : __( 'A change', 'fw' ) );
	}
}
