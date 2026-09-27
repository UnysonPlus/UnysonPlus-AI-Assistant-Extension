<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Settings for the AI: describe, validated update, preset upsert, undo.
 *
 * The schema comes from fw()->theme->get_settings_options() — the same arrays the Theme Settings
 * screen renders, extension-injected tabs included — so validation cannot drift from the form.
 *
 * Writes go through fw_set_db_settings_option(); the theme's generated CSS (and, for font changes,
 * the Google Fonts link) is then rebuilt directly — see saved() for why the form's
 * `fw_settings_form_saved` hook is deliberately NOT fired. Before every write the previous values of the touched settings are snapshotted
 * into the `upw_ai_settings_revisions` option (newest 20), which `undo_theme_settings` restores.
 *
 * Theme Settings are site-wide and live the moment they are written, so these abilities are offered
 * to MCP agents (Read & write mode) and the site-wide assistant, but not to the page-scoped builder panel.
 *
 * Manual-edit guard (shared with the Site Converter): the converter fingerprints every settings value it
 * writes (option fw_sc_settings_fingerprint) and the AI does the same for its own writes
 * (upw_ai_settings_fingerprint). A key whose stored value no longer matches the fingerprint of whoever
 * last wrote it has been edited BY HAND since — the AI skips it unless `force` is passed (after asking
 * the person). Keys with no fingerprint have no known automated writer and are not protected. The AI
 * deliberately does NOT touch the converter's fingerprints, so a later re-conversion treats an AI fix
 * like a hand edit and leaves it alone.
 */
class FW_AI_Settings {

	const OPTION_REVISIONS = 'upw_ai_settings_revisions';

	/** Fingerprints of the values the AI last wrote, per settings key (same scheme as the converter). */
	const OPTION_FINGERPRINT = 'upw_ai_settings_fingerprint';

	/** The Site Converter's fingerprints of what the last conversion wrote. */
	const CONVERTER_FINGERPRINT = 'fw_sc_settings_fingerprint';
	const MAX_REVISIONS    = 20;

	/** Option types that are UI only (no stored value worth exposing). */
	const UI_TYPES = array( 'html', 'html-full', 'html-fixed', 'preset-loader' );

	/** Keys a preset row may use for its display name, in order of preference. */
	const NAME_KEYS = array( 'name', 'color_name', 'preset_name', 'title', 'label' );

	/** @var array|null id => array( option, path ) */
	private static $leaves = null;

	/* ------------------------------------------------------------------ *
	 * Schema
	 * ------------------------------------------------------------------ */

	/**
	 * @return array id => array( option, breadcrumb )
	 */
	public static function leaves() {
		if ( self::$leaves === null ) {
			self::$leaves = array();
			$options      = fw()->theme->get_settings_options();
			self::walk( is_array( $options ) ? $options : array(), array(), self::$leaves );
		}
		return self::$leaves;
	}

	/**
	 * @param array    $options
	 * @param string[] $path
	 * @param array    $out
	 */
	private static function walk( array $options, array $path, array &$out ) {
		foreach ( $options as $id => $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			if ( is_int( $id ) ) {
				self::walk( $opt, $path, $out );
				continue;
			}
			if ( isset( $opt['options'] ) && is_array( $opt['options'] ) ) {
				$next = $path;
				if ( in_array( $opt['type'] ?? '', array( 'tab', 'box' ), true ) && ! empty( $opt['title'] ) ) {
					$next[] = wp_strip_all_tags( (string) $opt['title'] );
				}
				self::walk( $opt['options'], $next, $out );
				continue;
			}
			if ( empty( $opt['type'] ) || in_array( $opt['type'], self::UI_TYPES, true ) ) {
				continue;
			}
			$out[ $id ] = array( $opt, implode( ' › ', array_unique( $path ) ) );
		}
	}

	/**
	 * Inner leaves of a container-ish option (multi / multi-inline / addable-box / addable-popup).
	 *
	 * @param array $opt
	 * @return array id => option
	 */
	private static function inner_leaves( array $opt ) {
		foreach ( array( 'inner-options', 'box-options', 'popup-options' ) as $k ) {
			if ( ! empty( $opt[ $k ] ) && is_array( $opt[ $k ] ) ) {
				$tmp = array();
				self::walk( $opt[ $k ], array(), $tmp );
				$out = array();
				foreach ( $tmp as $id => $pair ) {
					$out[ $id ] = $pair[0];
				}
				return $out;
			}
		}
		return array();
	}

	/**
	 * @param array $opt
	 * @return array Describable row for one option (recursing one level into inner options).
	 */
	private static function describe_option( $id, array $opt, $deep = true ) {
		$row = array( 'id' => (string) $id, 'type' => (string) $opt['type'] );
		if ( ! empty( $opt['label'] ) && is_string( $opt['label'] ) ) {
			$row['label'] = wp_strip_all_tags( $opt['label'] );
		}
		if ( ! empty( $opt['desc'] ) && is_string( $opt['desc'] ) ) {
			$row['desc'] = wp_html_excerpt( wp_strip_all_tags( $opt['desc'] ), 220, '…' );
		}
		if ( in_array( $opt['type'], FW_AI_Schema::CHOICE_TYPES, true ) ) {
			$choices = FW_AI_Schema::flat_choices( $opt['choices'] ?? array() );
			if ( $choices ) {
				$row['choices'] = count( $choices ) > 60 ? array_slice( $choices, 0, 60, true ) : $choices;
			}
		}
		if ( $opt['type'] === 'switch' ) {
			$row['choices'] = array( $opt['left-choice']['value'] ?? false, $opt['right-choice']['value'] ?? true );
		}
		if ( array_key_exists( 'value', $opt ) ) {
			$row['default'] = $opt['value'];
		}
		if ( $deep ) {
			$inner = self::inner_leaves( $opt );
			if ( $inner ) {
				$row['inner_options'] = array();
				foreach ( $inner as $iid => $iopt ) {
					$row['inner_options'][] = self::describe_option( $iid, $iopt, false );
				}
			}
		}
		return $row;
	}

	/**
	 * @param string $id     One setting id for its full schema + current value; empty for the index.
	 * @param string $search Filter the index by id / label / section.
	 * @return array|WP_Error
	 */
	public static function describe( $id = '', $search = '' ) {
		$leaves = self::leaves();
		if ( $id !== '' ) {
			if ( ! isset( $leaves[ $id ] ) ) {
				return new WP_Error( 'upw_ai_unknown_setting', sprintf( 'Unknown Theme Settings id "%s". Call describe_theme_settings without an id for the index.', $id ) );
			}
			list( $opt, $path ) = $leaves[ $id ];
			return array(
				'section' => $path,
				'option'  => self::describe_option( $id, $opt ),
				'current' => fw_get_db_settings_option( $id ),
				'notes'   => __( 'update_theme_settings merges an object value into the current one (lists are replaced whole), so send only the keys you change. Colour values are usually {predefined:"<preset slug>", custom:"#hex"} — prefer a Color Preset slug over a raw hex. For button / box / section styles use save_preset.', 'fw' ),
			);
		}
		$groups = array();
		foreach ( $leaves as $lid => $pair ) {
			list( $opt, $path ) = $pair;
			$label = isset( $opt['label'] ) && is_string( $opt['label'] ) ? wp_strip_all_tags( $opt['label'] ) : '';
			if ( $search !== '' && stripos( $lid . ' ' . $label . ' ' . $path, $search ) === false ) {
				continue;
			}
			$groups[ $path ][] = array_filter( array( 'id' => (string) $lid, 'type' => (string) $opt['type'], 'label' => $label ) );
		}
		$out = array();
		foreach ( $groups as $path => $items ) {
			$out[] = array( 'section' => $path, 'settings' => $items );
		}
		return array(
			'sections' => $out,
			'notes'    => __( 'Call describe_theme_settings with an id for its full schema and current value before changing it.', 'fw' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Validation + merge
	 * ------------------------------------------------------------------ */

	/**
	 * @param array  $opt
	 * @param mixed  $value
	 * @param string $path
	 * @param array  $errors
	 */
	private static function validate( array $opt, $value, $path, array &$errors ) {
		$type = (string) $opt['type'];
		// Custom preset / picker types without an inner schema: shape-check only.
		if ( ! FW_AI_Schema::inner_leaves( $opt ) && preg_match( '/presets$|^multi-picker$|^background/', $type ) ) {
			if ( ! is_array( $value ) ) {
				$errors[] = "$path: expected an object / list like the current value.";
			}
			return;
		}
		FW_AI_Schema::check_deep( $opt, $value, $path, $errors );
	}

	/**
	 * Deep merge: objects merge key by key, lists and scalars are replaced.
	 *
	 * @param mixed $base
	 * @param mixed $patch
	 * @return mixed
	 */
	private static function merge( $base, $patch ) {
		if ( is_array( $base ) && is_array( $patch ) && self::is_assoc( $base ) && self::is_assoc( $patch ) ) {
			foreach ( $patch as $k => $v ) {
				$base[ $k ] = array_key_exists( $k, $base ) ? self::merge( $base[ $k ], $v ) : $v;
			}
			return $base;
		}
		return $patch;
	}

	/**
	 * @param mixed $a
	 * @return bool
	 */
	private static function is_assoc( $a ) {
		return is_array( $a ) && ( ! $a || array_keys( $a ) !== range( 0, count( $a ) - 1 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * @param array  $values id => value
	 * @param bool   $merge  Merge object values into the current ones.
	 * @param string $ability
	 * @param string $note
	 * @return array|WP_Error
	 */
	public static function update( array $values, $merge = true, $ability = 'unysonplus/update-theme-settings', $note = '', $force = false ) {
		$leaves  = self::leaves();
		$errors  = array();
		$next    = array();
		$skipped = array();
		$edited  = $force ? array() : self::hand_edited_keys();
		foreach ( $values as $id => $value ) {
			if ( ! isset( $leaves[ $id ] ) ) {
				$errors[] = sprintf( '%s: unknown Theme Settings id (see describe_theme_settings).', $id );
				continue;
			}
			if ( isset( $edited[ $id ] ) ) {
				$skipped[] = array(
					'id'     => (string) $id,
					'reason' => 'Edited by hand since it was last written automatically. Ask the person before changing it, then retry with force: true.',
				);
				continue;
			}
			$value = json_decode( wp_json_encode( $value ), true );
			$opt   = $leaves[ $id ][0];
			$new   = $merge ? self::merge( fw_get_db_settings_option( $id ), $value ) : $value;
			// Validate what was sent (a merge result also carries untouched stored keys).
			self::validate( $opt, $value, (string) $id, $errors );
			$next[ $id ] = $new;
		}
		if ( $errors ) {
			return new WP_Error( 'upw_ai_invalid', 'Nothing was changed. Fix these and retry: ' . implode( ' | ', array_slice( $errors, 0, 25 ) ), array( 'status' => 400, 'errors' => $errors ) );
		}
		if ( ! $next ) {
			if ( $skipped ) {
				return array(
					'ok'      => false,
					'changed' => array(),
					'skipped' => $skipped,
					'message' => 'Nothing was changed: every setting was edited by hand (see skipped).',
				);
			}
			return new WP_Error( 'upw_ai_nothing', 'Pass at least one setting in values.' );
		}

		$old_all = (array) fw_get_db_settings_option();
		$before  = array();
		foreach ( array_keys( $next ) as $id ) {
			$before[ $id ] = fw_get_db_settings_option( $id );
		}
		$rev = self::snapshot( $before, $ability, $note !== '' ? $note : 'Changed ' . implode( ', ', array_keys( $next ) ) );

		foreach ( $next as $id => $value ) {
			fw_set_db_settings_option( $id, $value );
		}
		// The theme builds its CSS from these values; if that fails, the values are not safe to keep.
		try {
			self::saved( $old_all );
		} catch ( Throwable $e ) {
			foreach ( $before as $id => $value ) {
				fw_set_db_settings_option( $id, $value );
			}
			try {
				self::saved( $old_all );
			} catch ( Throwable $e2 ) {
				// Already back to the previous values; nothing more to do.
			}
			return new WP_Error(
				'upw_ai_settings_rejected',
				'Nothing was changed: the theme could not build its styles with these values (' . $e->getMessage() . '). Check the value shapes with describe_theme_settings and retry.',
				array( 'status' => 400 )
			);
		}
		self::record_fingerprints( array_keys( $next ) );

		$out = array(
			'ok'               => true,
			'changed'          => array_keys( $next ),
			'undo_revision_id' => $rev,
			'message'          => 'Saved and live on the site (generated CSS rebuilt).',
		);
		if ( $skipped ) {
			$out['skipped'] = $skipped;
		}
		return $out;
	}

	/**
	 * Upsert one preset row (by name) in a Theme Settings preset list.
	 *
	 * @param string $type   Setting id of the list (button_colors, border_presets …).
	 * @param string $name   Preset name (matched case-insensitively).
	 * @param array  $values Keys to set on the row (merged into the existing row or a template).
	 * @return array|WP_Error
	 */
	public static function save_preset( $type, $name, array $values, $force = false ) {
		$leaves = self::leaves();
		if ( ! isset( $leaves[ $type ] ) ) {
			return new WP_Error( 'upw_ai_unknown_setting', sprintf( 'Unknown preset list "%s". See list_presets for the available types.', $type ) );
		}
		$name = trim( wp_strip_all_tags( $name ) );
		if ( $name === '' ) {
			return new WP_Error( 'upw_ai_empty', 'A preset needs a name.' );
		}
		$rows = fw_get_db_settings_option( $type, array() );
		if ( empty( $rows ) && isset( FW_AI_Abilities::PRESETS[ $type ] ) && FW_AI_Abilities::PRESETS[ $type ] && function_exists( FW_AI_Abilities::PRESETS[ $type ] ) ) {
			$rows = call_user_func( FW_AI_Abilities::PRESETS[ $type ] ); // Seed the defaults first, like the form does.
		}
		$rows = array_values( (array) $rows );

		$name_key = '';
		foreach ( self::NAME_KEYS as $k ) {
			if ( ( $rows && is_array( $rows[0] ) && array_key_exists( $k, $rows[0] ) ) || isset( self::inner_leaves( $leaves[ $type ][0] )[ $k ] ) ) {
				$name_key = $k;
				break;
			}
		}
		if ( $name_key === '' ) {
			return new WP_Error( 'upw_ai_no_name_key', sprintf( 'Could not tell which field names a "%s" preset.', $type ) );
		}

		$values = json_decode( wp_json_encode( $values ), true );
		unset( $values[ $name_key ] );
		$index = null;
		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) && strcasecmp( (string) ( $row[ $name_key ] ?? '' ), $name ) === 0 ) {
				$index = $i;
				break;
			}
		}

		if ( $index !== null ) {
			$rows[ $index ] = self::merge( $rows[ $index ], $values );
			$action         = 'updated';
		} else {
			$template = $rows && is_array( $rows[0] ) ? $rows[0] : array();
			$row      = self::merge( $template, $values );
			$row[ $name_key ] = $name;
			if ( array_key_exists( 'id', $row ) ) {
				$row['id'] = self::next_id( $rows );
			}
			if ( array_key_exists( 'slug', $row ) ) {
				$row['slug'] = sanitize_title( $name );
			}
			$rows[] = $row;
			$index  = count( $rows ) - 1;
			$action = 'created';
		}

		$res = self::update( array( $type => $rows ), false, 'unysonplus/save-preset', sprintf( '%s %s preset "%s"', ucfirst( $action ), $type, $name ), $force );
		if ( is_wp_error( $res ) || empty( $res['ok'] ) ) {
			return $res;
		}
		return $res + array(
			'preset' => $rows[ $index ],
			'action' => $action,
		);
	}

	/**
	 * A new row id in the style of the existing ones ("b000000006" after "b000000005").
	 *
	 * @param array $rows
	 * @return string
	 */
	private static function next_id( array $rows ) {
		$prefix = '';
		$width  = 0;
		$max    = 0;
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && isset( $r['id'] ) && preg_match( '/^([a-z_-]*)(\d+)$/i', (string) $r['id'], $m ) ) {
				$prefix = $m[1];
				$width  = max( $width, strlen( $m[2] ) );
				$max    = max( $max, (int) $m[2] );
			}
		}
		if ( $width ) {
			return $prefix . str_pad( (string) ( $max + 1 ), $width, '0', STR_PAD_LEFT );
		}
		return substr( md5( uniqid( '', true ) ), 0, 10 );
	}

	/* ------------------------------------------------------------------ *
	 * Manual-edit guard
	 * ------------------------------------------------------------------ */

	/**
	 * @param mixed $value
	 * @return string The same fingerprint the Site Converter uses.
	 */
	private static function fingerprint( $value ) {
		return md5( (string) wp_json_encode( $value ) );
	}

	/**
	 * Keys edited by hand since an automated writer (the AI, else the converter) last wrote them.
	 *
	 * @return array key => true
	 */
	public static function hand_edited_keys() {
		$ai   = (array) get_option( self::OPTION_FINGERPRINT, array() );
		$conv = (array) get_option( self::CONVERTER_FINGERPRINT, array() );
		$out  = array();
		foreach ( array_unique( array_merge( array_keys( $ai ), array_keys( $conv ) ) ) as $key ) {
			$stored = fw_get_db_settings_option( $key, null );
			if ( null === $stored ) {
				continue;
			}
			$print = self::fingerprint( $stored );
			// Still exactly what either automated writer left: not a hand edit.
			if ( ( isset( $ai[ $key ] ) && $ai[ $key ] === $print ) || ( isset( $conv[ $key ] ) && $conv[ $key ] === $print ) ) {
				continue;
			}
			$out[ $key ] = true;
		}
		return $out;
	}

	/**
	 * @param string[] $keys
	 */
	private static function record_fingerprints( array $keys ) {
		$prints = (array) get_option( self::OPTION_FINGERPRINT, array() );
		foreach ( $keys as $key ) {
			$prints[ $key ] = self::fingerprint( fw_get_db_settings_option( $key, null ) );
		}
		update_option( self::OPTION_FINGERPRINT, $prints, false );
	}

	/* ------------------------------------------------------------------ *
	 * Revisions
	 * ------------------------------------------------------------------ */

	/**
	 * @param array  $before id => previous value
	 * @param string $ability
	 * @param string $note
	 * @return int
	 */
	private static function snapshot( array $before, $ability, $note ) {
		$list = (array) get_option( self::OPTION_REVISIONS, array() );
		$id   = $list ? (int) max( array_column( $list, 'id' ) ) + 1 : 1;
		array_unshift( $list, array(
			'id'      => $id,
			'time'    => time(),
			'user'    => get_current_user_id(),
			'ability' => (string) $ability,
			'note'    => wp_html_excerpt( (string) $note, 200, '…' ),
			'values'  => $before,
		) );
		update_option( self::OPTION_REVISIONS, array_slice( $list, 0, self::MAX_REVISIONS ), false );
		return $id;
	}

	/**
	 * @return array Newest first, without the stored values.
	 */
	public static function list_revisions() {
		$out = array();
		foreach ( (array) get_option( self::OPTION_REVISIONS, array() ) as $r ) {
			$out[] = array(
				'revision_id' => (int) $r['id'],
				'time'        => gmdate( 'c', (int) $r['time'] ),
				'ability'     => (string) $r['ability'],
				'note'        => (string) $r['note'],
				'settings'    => array_keys( (array) $r['values'] ),
			);
		}
		return $out;
	}

	/**
	 * Put back the values a revision recorded (the newest by default). The current values are
	 * snapshotted first, so the undo can itself be undone.
	 *
	 * @param int|null $revision_id
	 * @return array|WP_Error
	 */
	public static function undo( $revision_id = null ) {
		$list = (array) get_option( self::OPTION_REVISIONS, array() );
		$rev  = null;
		foreach ( $list as $r ) {
			if ( $revision_id === null || (int) $r['id'] === (int) $revision_id ) {
				$rev = $r;
				break;
			}
		}
		if ( ! $rev ) {
			return new WP_Error( 'upw_ai_no_revisions', 'No such Theme Settings revision.' );
		}
		$old_all = (array) fw_get_db_settings_option();
		$current = array();
		foreach ( array_keys( (array) $rev['values'] ) as $id ) {
			$current[ $id ] = fw_get_db_settings_option( $id );
		}
		$undo = self::snapshot( $current, 'unysonplus/undo-theme-settings', sprintf( 'Before restoring revision %d', $rev['id'] ) );
		foreach ( (array) $rev['values'] as $id => $value ) {
			fw_set_db_settings_option( $id, $value );
		}
		self::record_fingerprints( array_keys( (array) $rev['values'] ) );
		self::saved( $old_all );
		return array(
			'ok'               => true,
			'restored'         => (int) $rev['id'],
			'settings'         => array_keys( (array) $rev['values'] ),
			'undo_revision_id' => $undo,
		);
	}

	/**
	 * Rebuild what is derived from the settings — WITHOUT firing `fw_settings_form_saved`.
	 *
	 * That hook's listeners (identity sync, Google Fonts processing) re-run storage_save on stored
	 * values as a side effect, which has corrupted Theme Settings when fired from code (the Site
	 * Converter avoids it for the same reason). Instead, like the converter: recompile the theme's
	 * generated CSS directly (it only reads stored values), and rebuild the Google Fonts link when a
	 * font setting changed.
	 *
	 * @param array $old_all
	 */
	private static function saved( array $old_all ) {
		if ( function_exists( 'unysonplus_hf_regenerate_css' ) ) {
			unysonplus_hf_regenerate_css();
		}
		$new_all = (array) fw_get_db_settings_option();
		foreach ( $new_all as $id => $value ) {
			if ( preg_match( '/typograph|font/i', (string) $id ) && wp_json_encode( $value ) !== wp_json_encode( $old_all[ $id ] ?? null ) ) {
				if ( function_exists( '_action_theme_process_google_fonts' ) ) {
					_action_theme_process_google_fonts();
				}
				break;
			}
		}
		/** Fires after the AI Assistant changes Theme Settings (old values, new values). */
		do_action( 'fw_ai_assistant_settings_saved', $old_all, $new_all );
	}
}
