<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Knowledge for the Chat AI channel: what the visitor assistant may answer from besides the site's
 * published pages.
 *
 *   1. Facts the owner types (Chat → Settings → AI assistant → "Facts for the assistant", Markdown).
 *   2. Markdown / text files the owner uploads on the same tab, kept as text in the database
 *      (option upw_ai_knowledge_files, not autoloaded), never as files a browser could open.
 *   3. The business details the site already has: name and tagline, the SEO extension's local
 *      business address, opening hours and price range, and the contact channels switched on in Chat.
 *
 * Facts and files are split into sections at their Markdown headings (long sections into ~1,500
 * character pieces); a question picks the best-matching sections by keyword, the same way pages are
 * picked. When all the knowledge is short (under SMALL_ALL characters) it is always sent whole.
 * Everything here is treated as public: the assistant may repeat any of it to any visitor.
 */
class FW_AI_Knowledge {

	const OPTION_FILES = 'upw_ai_knowledge_files';
	const MAX_FILE     = 204800;  // 200 KB a file
	const MAX_TOTAL    = 1048576; // 1 MB in all
	const MAX_FILES    = 20;
	const PIECE        = 1500;    // characters per section piece
	const BUDGET       = 6000;    // knowledge characters sent with one question
	const SMALL_ALL    = 3000;    // below this, all knowledge goes with every question

	/* ------------------------------------------------------------------ *
	 * Files
	 * ------------------------------------------------------------------ */

	/** @return array[] { id, name, text, bytes, added } */
	public static function files() {
		$files = get_option( self::OPTION_FILES, array() );
		return is_array( $files ) ? array_values( array_filter( $files, 'is_array' ) ) : array();
	}

	/**
	 * @param array[] $files
	 */
	public static function save_files( array $files ) {
		update_option( self::OPTION_FILES, array_values( $files ), false );
	}

	/**
	 * Add uploaded files (an entry of $_FILES with multiple files) and remove the ids asked for.
	 *
	 * @param array    $upload
	 * @param string[] $remove ids
	 * @return string[] messages for the person
	 */
	public static function handle_upload( $upload, array $remove ) {
		$files = self::files();
		$msgs  = array();

		if ( $remove ) {
			$before = count( $files );
			$files  = array_values( array_filter( $files, static function ( $f ) use ( $remove ) {
				return ! in_array( (string) $f['id'], $remove, true );
			} ) );
			if ( $before !== count( $files ) ) {
				/* translators: %d: number of files */
				$msgs[] = sprintf( _n( '%d knowledge file removed.', '%d knowledge files removed.', $before - count( $files ), 'fw' ), $before - count( $files ) );
			}
		}

		$names = isset( $upload['name'] ) ? (array) $upload['name'] : array();
		$total = array_sum( wp_list_pluck( $files, 'bytes' ) );
		$added = 0;
		foreach ( $names as $i => $name ) {
			$tmp   = (array) $upload['tmp_name'];
			$errs  = (array) $upload['error'];
			$name  = sanitize_file_name( (string) $name );
			if ( $name === '' || (int) ( $errs[ $i ] ?? 4 ) === UPLOAD_ERR_NO_FILE ) {
				continue;
			}
			if ( (int) ( $errs[ $i ] ?? 0 ) !== UPLOAD_ERR_OK || empty( $tmp[ $i ] ) || ! is_uploaded_file( $tmp[ $i ] ) ) {
				/* translators: %s: file name */
				$msgs[] = sprintf( __( '%s could not be uploaded.', 'fw' ), $name );
				continue;
			}
			if ( ! preg_match( '/\.(md|markdown|txt)$/i', $name ) ) {
				/* translators: %s: file name */
				$msgs[] = sprintf( __( '%s was skipped: only .md, .markdown and .txt files can be added.', 'fw' ), $name );
				continue;
			}
			$text  = (string) file_get_contents( $tmp[ $i ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$text  = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
			$bytes = strlen( $text );
			if ( $bytes > self::MAX_FILE ) {
				/* translators: %s: file name */
				$msgs[] = sprintf( __( '%s was skipped: a file can be up to 200 KB.', 'fw' ), $name );
				continue;
			}
			if ( ! wp_check_invalid_utf8( $text ) && $text !== '' ) {
				/* translators: %s: file name */
				$msgs[] = sprintf( __( '%s was skipped: save it as UTF-8 text.', 'fw' ), $name );
				continue;
			}
			// A file with the same name replaces the old one.
			$files = array_values( array_filter( $files, static function ( $f ) use ( $name, &$total ) {
				if ( strcasecmp( (string) $f['name'], $name ) === 0 ) {
					$total -= (int) $f['bytes'];
					return false;
				}
				return true;
			} ) );
			if ( count( $files ) >= self::MAX_FILES || $total + $bytes > self::MAX_TOTAL ) {
				/* translators: %s: file name */
				$msgs[] = sprintf( __( '%s was skipped: the knowledge is full (up to 20 files and 1 MB in all).', 'fw' ), $name );
				continue;
			}
			$files[] = array(
				'id'    => strtolower( wp_generate_password( 12, false ) ),
				'name'  => $name,
				'text'  => wp_strip_all_tags( $text ),
				'bytes' => $bytes,
				'added' => gmdate( 'Y-m-d H:i:s' ),
			);
			$total += $bytes;
			$added++;
		}
		if ( $added ) {
			/* translators: %d: number of files */
			$msgs[] = sprintf( _n( '%d knowledge file added.', '%d knowledge files added.', $added, 'fw' ), $added );
		}
		self::save_files( $files );
		return $msgs;
	}

	/* ------------------------------------------------------------------ *
	 * Sections
	 * ------------------------------------------------------------------ */

	/** @return string The "Facts for the assistant" box. */
	private static function facts_text() {
		$chat = fw_ext( 'chat' );
		return $chat ? trim( wp_strip_all_tags( (string) $chat->get_option( 'chat_ai_knowledge', '' ) ) ) : '';
	}

	/**
	 * Every knowledge section: { title, text }. Cached per content.
	 *
	 * @return array[]
	 */
	public static function sections() {
		$sources = array();
		$facts   = self::facts_text();
		if ( $facts !== '' ) {
			$sources[ __( 'Facts', 'fw' ) ] = $facts;
		}
		foreach ( self::files() as $f ) {
			$sources[ preg_replace( '/\.(md|markdown|txt)$/i', '', (string) $f['name'] ) ] = (string) $f['text'];
		}
		if ( ! $sources ) {
			return array();
		}
		$key = 'upw_ai_kn2_' . md5( wp_json_encode( $sources ) ); // bump the prefix when the splitting changes
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$out = array();
		foreach ( $sources as $source => $text ) {
			foreach ( self::split( $text ) as $part ) {
				$out[] = array(
					'title' => $part['heading'] !== '' ? $source . ' › ' . $part['heading'] : $source,
					'text'  => $part['text'],
				);
			}
		}
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/**
	 * Markdown → sections at headings; long sections → pieces at paragraph breaks.
	 *
	 * @param string $text
	 * @return array[] { heading, text }
	 */
	public static function split( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$parts = array();
		$head  = '';
		$body  = array();
		$flush = function () use ( &$parts, &$head, &$body ) {
			$t = trim( implode( "\n", $body ) );
			if ( $t !== '' ) { // a heading with nothing under it adds nothing
				foreach ( self::pieces( $t ) as $p ) {
					$parts[] = array( 'heading' => $head, 'text' => $p );
				}
			}
			$body = array();
		};
		foreach ( explode( "\n", $text ) as $line ) {
			if ( preg_match( '/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m ) ) {
				$flush();
				$head = trim( $m[1] );
				continue;
			}
			$body[] = $line;
		}
		$flush();
		return $parts;
	}

	/**
	 * @param string $text
	 * @return string[]
	 */
	private static function pieces( $text ) {
		if ( mb_strlen( $text ) <= self::PIECE ) {
			return array( $text );
		}
		$out = array();
		$cur = '';
		foreach ( preg_split( '/\n\s*\n/', $text ) as $para ) {
			if ( $cur !== '' && mb_strlen( $cur ) + mb_strlen( $para ) > self::PIECE ) {
				$out[] = $cur;
				$cur   = '';
			}
			$cur .= ( $cur !== '' ? "\n\n" : '' ) . $para;
			while ( mb_strlen( $cur ) > self::PIECE * 2 ) {
				$out[] = mb_substr( $cur, 0, self::PIECE );
				$cur   = mb_substr( $cur, self::PIECE );
			}
		}
		if ( trim( $cur ) !== '' ) {
			$out[] = $cur;
		}
		return $out;
	}

	/**
	 * The sections that best match the question's keywords (all of them when the knowledge is small).
	 *
	 * @param string[] $words keywords, lower case
	 * @return array[] { title, text }
	 */
	public static function find( array $words ) {
		$sections = self::sections();
		if ( ! $sections ) {
			return array();
		}
		$all = array_sum( array_map( static function ( $s ) {
			return mb_strlen( $s['text'] );
		}, $sections ) );
		if ( $all <= self::SMALL_ALL ) {
			return $sections;
		}
		$score = array();
		foreach ( $sections as $i => $s ) {
			$title = mb_strtolower( $s['title'] );
			$body  = mb_strtolower( $s['text'] );
			$n     = 0;
			foreach ( $words as $w ) {
				$stem = self::stem( $w );
				if ( mb_strpos( $title, $stem ) !== false ) {
					$n += 3;
				}
				$n += min( 3, substr_count( $body, $stem ) );
			}
			if ( $n > 0 ) {
				$score[ $i ] = $n;
			}
		}
		arsort( $score );
		$out    = array();
		$budget = self::BUDGET;
		foreach ( array_keys( $score ) as $i ) {
			$len = mb_strlen( $sections[ $i ]['text'] );
			if ( $len > $budget ) {
				continue;
			}
			$out[]   = $sections[ $i ];
			$budget -= $len;
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A crude stem so "prices" finds "price" and "booking" finds "book".
	 *
	 * @param string $w
	 * @return string
	 */
	private static function stem( $w ) {
		foreach ( array( 'ings', 'ing', 'ies', 'es', 's', 'ed' ) as $suffix ) {
			if ( mb_strlen( $w ) > mb_strlen( $suffix ) + 3 && mb_substr( $w, -mb_strlen( $suffix ) ) === $suffix ) {
				return mb_substr( $w, 0, -mb_strlen( $suffix ) );
			}
		}
		return $w;
	}

	/* ------------------------------------------------------------------ *
	 * Business details the site already has
	 * ------------------------------------------------------------------ */

	/** @return string Plain-text business details, or ''. */
	public static function business() {
		$chat = fw_ext( 'chat' );
		if ( $chat && $chat->get_option( 'chat_ai_use_business', 'yes' ) === 'no' ) {
			return '';
		}
		$lines = array();
		$name  = wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		$tag   = wp_strip_all_tags( html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ) );
		if ( $name !== '' ) {
			$lines[] = 'Name: ' . $name . ( $tag !== '' ? ' (' . $tag . ')' : '' );
		}
		$lines[] = 'Website: ' . home_url( '/' );

		if ( class_exists( 'FW_SEO_Entities' ) && method_exists( 'FW_SEO_Entities', 'business' ) ) {
			$b    = FW_SEO_Entities::business();
			$addr = implode( ', ', array_filter( array( $b['street'] ?? '', $b['city'] ?? '', $b['region'] ?? '', $b['postcode'] ?? '', $b['country'] ?? '' ) ) );
			if ( $addr !== '' ) {
				$lines[] = 'Address: ' . $addr;
			}
			$days  = array( 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' );
			$hours = array();
			foreach ( $days as $k => $label ) {
				if ( ! empty( $b[ $k ] ) ) {
					$hours[] = $label . ' ' . $b[ $k ];
				}
			}
			if ( $hours ) {
				$lines[] = 'Opening hours (site time zone): ' . implode( '; ', $hours );
			}
			if ( ! empty( $b['price'] ) ) {
				$lines[] = 'Price range: ' . $b['price'];
			}
		}

		if ( $chat ) {
			$map = array(
				'wa'    => array( 'chat_wa_enable', 'chat_wa_number', 'WhatsApp: +' ),
				'sms'   => array( 'chat_sms_enable', 'chat_sms_number', 'Phone / text: ' ),
				'email' => array( 'chat_email_enable', 'chat_email_address', 'Email: ' ),
				'fb'    => array( 'chat_fb_enable', 'chat_fb_username', 'Facebook Messenger: m.me/' ),
				'tg'    => array( 'chat_tg_enable', 'chat_tg_username', 'Telegram: @' ),
			);
			foreach ( $map as $k => $m ) {
				$v = trim( (string) $chat->get_option( $m[1], '' ) );
				if ( $chat->get_option( $m[0] ) === 'yes' && $v !== '' ) {
					$lines[] = $m[2] . ltrim( $k === 'wa' ? preg_replace( '/\D+/', '', $v ) : $v, '@/' );
				}
			}
		}
		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/* ------------------------------------------------------------------ *
	 * Import / Export (the Chat JSON file)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $data
	 * @return array
	 */
	public static function export( $data ) {
		$data['ai_knowledge_files'] = array_map( static function ( $f ) {
			return array( 'name' => $f['name'], 'text' => $f['text'], 'added' => $f['added'] );
		}, self::files() );
		return $data;
	}

	/**
	 * Knowledge files come in with the settings (JSON only, "Also replace the settings").
	 *
	 * @param array $data
	 * @param bool  $with_settings
	 */
	public static function import( $data, $with_settings ) {
		if ( ! $with_settings || empty( $data['ai_knowledge_files'] ) || ! is_array( $data['ai_knowledge_files'] ) ) {
			return;
		}
		$files = array();
		$total = 0;
		foreach ( $data['ai_knowledge_files'] as $f ) {
			$name = sanitize_file_name( (string) ( $f['name'] ?? '' ) );
			$text = wp_strip_all_tags( (string) ( $f['text'] ?? '' ) );
			$bytes = strlen( $text );
			if ( $name === '' || $bytes > self::MAX_FILE || $total + $bytes > self::MAX_TOTAL || count( $files ) >= self::MAX_FILES ) {
				continue;
			}
			$files[] = array(
				'id'    => strtolower( wp_generate_password( 12, false ) ),
				'name'  => $name,
				'text'  => $text,
				'bytes' => $bytes,
				'added' => (string) ( $f['added'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			);
			$total += $bytes;
		}
		self::save_files( $files );
	}
}
