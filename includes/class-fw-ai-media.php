<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Image help: find images in the Media Library, look at them, write their alt text / title / caption,
 * and set featured images.
 *
 *   list-media          search the library (text, "missing alt text", unattached), with where each image
 *                       is used — the context good alt text depends on.
 *   view-media          returns the images THEMSELVES (resized JPEG, ≤ 6 per call) so a model that can see
 *                       writes alt text from what is in the picture, not from its file name. Over MCP they
 *                       travel as image content (FW_AI_MCP turns the `_images` key into content items);
 *                       a model that cannot see still gets the title, file name and where it is used.
 *   update-media        alt text, title, caption, description for up to 50 images at once, snapshotted
 *                       first (one undo_change reverts the batch).
 *   set-featured-image  a post's featured image (0 removes it), snapshotted first.
 *
 * To place a library image in a page element, use the id and url from list-media as the element's image
 * value: { attachment_id, url, alt }.
 */
class FW_AI_Media {

	const VIEW_MAX   = 6;
	const VIEW_SIDE  = 768;
	const VIEW_LARGE = 1568;
	const BATCH_MAX  = 50;

	public static function register() {
		fw_ai_register_ability( 'list-media', array(
			'label'       => __( 'List Media Library images', 'fw' ),
			'description' => __( 'Images in the Media Library, newest first: id, url, title, alt text, caption, file name, size and the pages that use each one. search matches title, file name, caption and alt text; missing_alt: true lists only images without alt text; unattached: true only images not attached to a post. Use the id + url as an element\'s image value { attachment_id, url, alt } to place one on a page.', 'fw' ),
			'input'       => array(
				'search'      => array( 'type' => 'string' ),
				'missing_alt' => array( 'type' => 'boolean', 'default' => false ),
				'unattached'  => array( 'type' => 'boolean', 'default' => false ),
				'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				'offset'      => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
			'permission'  => 'upload_files',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'list_media' ),
		) );

		fw_ai_register_ability( 'view-media', array(
			'label'       => __( 'Look at images', 'fw' ),
			'description' => __( 'Returns up to 6 Media Library images themselves (resized) plus their details, so you can see what each one shows — use it before writing alt text or choosing an image for a section. Pass ids from list_media.', 'fw' ),
			'input'       => array(
				'ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => self::VIEW_MAX ),
				'size' => array( 'type' => 'string', 'enum' => array( 'normal', 'large' ), 'default' => 'normal', 'description' => 'large (up to 1568px) to read the layout and text of a screenshot or sketch.' ),
			),
			'required'    => array( 'ids' ),
			'permission'  => 'upload_files',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'view_media' ),
		) );

		fw_ai_register_ability( 'extract-colors', array(
			'label'       => __( 'Measure an image\'s colours', 'fw' ),
			'description' => __( 'The main colours of a Media Library image (a logo, a photo, a screenshot) MEASURED from its pixels — or read from an SVG\'s code — with hex values, each colour\'s share of the image, and its contrast against white and black text (4.5 or more is readable body text). Use it before building a colour palette from a logo: exact values, not guesses from looking.', 'fw' ),
			'input'       => array(
				'media_id' => array( 'type' => 'integer' ),
				'max'      => array( 'type' => 'integer', 'minimum' => 2, 'maximum' => 12, 'default' => 6 ),
			),
			'required'    => array( 'media_id' ),
			'permission'  => 'upload_files',
			'readonly'    => true,
			'execute'     => array( __CLASS__, 'extract_colors' ),
		) );

		fw_ai_register_ability( 'update-media', array(
			'label'       => __( 'Update image details', 'fw' ),
			'description' => __( 'Sets alt text, title, caption and/or description on up to 50 Media Library images: items: [{ id, alt?, title?, caption?, description? }]. Alt text describes what the image shows for someone who cannot see it, in one short sentence without "image of"; leave it empty ("") only for purely decorative images. Saved as one change that undo_change reverts.', 'fw' ),
			'input'       => array(
				'items' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::BATCH_MAX,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'alt'         => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'caption'     => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
						'required'   => array( 'id' ),
					),
				),
			),
			'required'    => array( 'items' ),
			'permission'  => 'upload_files',
			'idempotent'  => true,
			'execute'     => array( __CLASS__, 'update_media' ),
		) );

		fw_ai_register_ability( 'set-featured-image', array(
			'label'       => __( 'Set a featured image', 'fw' ),
			'description' => __( 'Sets the featured image of a post or page to a Media Library image (media_id from list_media), or removes it with media_id 0. Undoable with undo_change.', 'fw' ),
			'input'       => array(
				'post_id'  => array( 'type' => 'integer' ),
				'media_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
			'required'    => array( 'post_id', 'media_id' ),
			'permission'  => 'edit_post',
			'idempotent'  => true,
			'execute'     => array( __CLASS__, 'set_featured' ),
		) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @param array $in
	 * @return array
	 */
	public static function list_media( $in ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => min( 50, max( 1, (int) ( $in['limit'] ?? 20 ) ) ),
			'offset'         => max( 0, (int) ( $in['offset'] ?? 0 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $in['search'] ) ) {
			$args['s'] = (string) $in['search'];
		}
		if ( ! empty( $in['unattached'] ) ) {
			$args['post_parent'] = 0;
		}
		if ( ! empty( $in['missing_alt'] ) ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			);
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $p ) {
			$items[] = self::describe( $p );
		}
		return array(
			'total'  => (int) $q->found_posts,
			'offset' => $args['offset'],
			'items'  => $items,
		);
	}

	/**
	 * @param WP_Post $p
	 * @return array
	 */
	private static function describe( WP_Post $p ) {
		$meta = wp_get_attachment_metadata( $p->ID );
		$src  = wp_get_attachment_image_src( $p->ID, 'medium_large' );
		$row  = array(
			'id'       => $p->ID,
			'url'      => (string) wp_get_attachment_url( $p->ID ),
			'preview'  => $src ? $src[0] : '',
			'title'    => $p->post_title,
			'alt'      => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
			'caption'  => $p->post_excerpt,
			'file'     => wp_basename( (string) get_attached_file( $p->ID ) ),
			'size'     => ! empty( $meta['width'] ) ? $meta['width'] . '×' . $meta['height'] : '',
			'uploaded' => get_the_date( 'Y-m-d', $p ),
		);
		$used = self::used_on( $p->ID );
		if ( $used ) {
			$row['used_on'] = $used;
		}
		return $row;
	}

	/**
	 * Pages whose builder content or featured image uses the attachment (the context for its alt text).
	 *
	 * @param int $id
	 * @return array[] { id, title, as }
	 */
	private static function used_on( $id ) {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.post_id, m.meta_key FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE p.post_type NOT IN ('revision','attachment') AND p.post_status NOT IN ('trash','auto-draft') AND (
			   ( m.meta_key = %s AND m.meta_value LIKE %s ) OR ( m.meta_key = '_thumbnail_id' AND m.meta_value = %s ) )
			 LIMIT 6",
			FW_AI_Store::META_JSON,
			'%"attachment_id":"' . $wpdb->esc_like( (string) $id ) . '"%',
			(string) $id
		) );
		foreach ( $rows as $r ) {
			$out[] = array( 'id' => (int) $r->post_id, 'title' => get_the_title( (int) $r->post_id ), 'as' => $r->meta_key === '_thumbnail_id' ? 'featured image' : 'in the page content' );
		}
		return $out;
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function view_media( $in ) {
		$ids    = array_slice( array_unique( array_map( 'intval', (array) ( $in['ids'] ?? array() ) ) ), 0, self::VIEW_MAX );
		$items  = array();
		$images = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || $p->post_type !== 'attachment' || ! wp_attachment_is_image( $id ) ) {
				$items[] = array( 'id' => $id, 'error' => 'not an image in the Media Library' );
				continue;
			}
			$row = self::describe( $p );
			// Pictures travel only over MCP (as image content); elsewhere they would land in the
			// model's text as base64.
			$img = FW_AI_MCP::is_calling() ? self::encode( $id, ( $in['size'] ?? '' ) === 'large' ? self::VIEW_LARGE : self::VIEW_SIDE ) : null;
			if ( $img ) {
				$row['image'] = 'attached below as image ' . ( count( $images ) + 1 );
				$images[]     = $img;
			} else {
				$row['image'] = FW_AI_MCP::is_calling() ? 'could not be read; judge from the title, file name and where it is used' : 'pictures are only sent to AI programs connected over MCP; judge from the title, file name and where it is used';
			}
			$items[] = $row;
		}
		return array(
			'items'   => $items,
			'note'    => 'The images follow in the same order. If you cannot see images, write alt text only from the title, file name, caption and the pages that use each one, and say so.',
			'_images' => $images,
		);
	}

	/**
	 * A small JPEG of the attachment, base64.
	 *
	 * @param int $id
	 * @return array|null { mime, data }
	 */
	private static function encode( $id, $side = self::VIEW_SIDE ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( $side, $side, false );
			$editor->set_quality( $side > self::VIEW_SIDE ? 80 : 72 );
			$tmp  = trailingslashit( get_temp_dir() ) . 'upw-ai-view-' . wp_generate_password( 12, false ) . '.jpg'; // wp_tempnam() is admin-only
			$save = $editor->save( $tmp, 'image/jpeg' );
			if ( ! is_wp_error( $save ) && ! empty( $save['path'] ) && file_exists( $save['path'] ) ) {
				$data = base64_encode( (string) file_get_contents( $save['path'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				@unlink( $save['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return array( 'mime' => 'image/jpeg', 'data' => $data );
			}
		}
		// No editor for this format: send the original when it is small and a common web type.
		$mime = (string) get_post_mime_type( $id );
		if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) && filesize( $file ) <= 1500000 ) {
			return array( 'mime' => $mime, 'data' => base64_encode( (string) file_get_contents( $file ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return null;
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function extract_colors( $in ) {
		$id   = (int) ( $in['media_id'] ?? 0 );
		$max  = min( 12, max( 2, (int) ( $in['max'] ?? 6 ) ) );
		$file = $id && get_post_type( $id ) === 'attachment' ? get_attached_file( $id ) : '';
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'upw_ai_media', 'No Media Library file with that media_id.' );
		}
		$mime   = (string) get_post_mime_type( $id );
		$counts = array();
		if ( $mime === 'image/svg+xml' || preg_match( '/\.svg$/i', $file ) ) {
			// Vector logo: the colours are in the code; weight each by how often it is used.
			$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			preg_match_all( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $svg, $m );
			foreach ( $m[1] as $h ) {
				if ( strlen( $h ) === 3 ) {
					$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
				}
				$counts[ strtolower( $h ) ] = ( $counts[ strtolower( $h ) ] ?? 0 ) + 1;
			}
			$source = 'svg code';
		} else {
			if ( ! function_exists( 'imagecreatefromstring' ) ) {
				return new WP_Error( 'upw_ai_media', 'This server has no image library (GD) to measure colours.' );
			}
			$im = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore
			if ( ! $im ) {
				return new WP_Error( 'upw_ai_media', 'The image could not be read (format not supported by this server).' );
			}
			$w  = imagesx( $im );
			$h  = imagesy( $im );
			$sw = min( 120, $w );
			$sh = max( 1, (int) round( $h * $sw / max( 1, $w ) ) );
			$sm = imagecreatetruecolor( $sw, $sh );
			imagealphablending( $sm, false );
			imagesavealpha( $sm, true );
			imagecopyresampled( $sm, $im, 0, 0, 0, 0, $sw, $sh, $w, $h );
			for ( $y = 0; $y < $sh; $y++ ) {
				for ( $x = 0; $x < $sw; $x++ ) {
					$c = imagecolorat( $sm, $x, $y );
					if ( ( ( $c >> 24 ) & 0x7F ) > 90 ) {
						continue; // transparent: not part of the logo
					}
					// 5 bits per channel: close shades count together.
					$k = ( ( ( $c >> 16 ) & 0xF8 ) << 16 ) | ( ( ( $c >> 8 ) & 0xF8 ) << 8 ) | ( $c & 0xF8 );
					$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
				}
			}
			imagedestroy( $sm );
			imagedestroy( $im );
			$hex = array();
			foreach ( $counts as $k => $n ) {
				$hex[ sprintf( '%02x%02x%02x', min( 255, ( ( $k >> 16 ) & 0xFF ) + 4 ), min( 255, ( ( $k >> 8 ) & 0xFF ) + 4 ), min( 255, ( $k & 0xFF ) + 4 ) ) ] = $n;
			}
			$counts = $hex;
			$source = 'pixels';
		}
		if ( ! $counts ) {
			return new WP_Error( 'upw_ai_media', 'No colours found (a fully transparent image, or an SVG without hex colours).' );
		}
		arsort( $counts );
		// Merge near-identical colours into the most common one.
		$groups = array();
		foreach ( $counts as $hex => $n ) {
			$rgb = sscanf( $hex, '%02x%02x%02x' );
			foreach ( $groups as &$g ) {
				if ( abs( $g['rgb'][0] - $rgb[0] ) + abs( $g['rgb'][1] - $rgb[1] ) + abs( $g['rgb'][2] - $rgb[2] ) < 60 ) {
					$g['n'] += $n;
					continue 2;
				}
			}
			unset( $g );
			$groups[] = array( 'hex' => $hex, 'rgb' => $rgb, 'n' => $n );
		}
		usort( $groups, function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		} );
		$total = array_sum( wp_list_pluck( $groups, 'n' ) );
		$out   = array();
		foreach ( array_slice( $groups, 0, $max ) as $g ) {
			list( $r, $gg, $b ) = $g['rgb'];
			$lum   = self::luminance( $r, $gg, $b );
			$max_c = max( $r, $gg, $b );
			$min_c = min( $r, $gg, $b );
			$out[] = array(
				'hex'            => '#' . $g['hex'],
				'share_pct'      => round( 100 * $g['n'] / max( 1, $total ), 1 ),
				'kind'           => $max_c - $min_c < 20 ? ( $lum > 0.85 ? 'white / near-white' : ( $lum < 0.03 ? 'black / near-black' : 'grey' ) ) : 'colour',
				'contrast_white' => round( ( 1.05 ) / ( $lum + 0.05 ), 2 ),
				'contrast_black' => round( ( $lum + 0.05 ) / 0.05, 2 ),
			);
		}
		return array(
			'media_id' => $id,
			'measured' => $source,
			'colors'   => $out,
			'note'     => 'share_pct = how much of the image is that colour (backgrounds count too: a logo on white shows a large white share). Pick brand colours from the "colour" entries; for text on a colour use whichever of white / black has the higher contrast, and keep body text at 4.5 or more.',
		);
	}

	/** WCAG relative luminance of an sRGB colour. */
	private static function luminance( $r, $g, $b ) {
		$f = function ( $c ) {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $f( $r ) + 0.7152 * $f( $g ) + 0.0722 * $f( $b );
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function update_media( $in ) {
		$map   = array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' );
		$plan  = array();
		$spec  = array( 'post_fields' => array(), 'post_meta' => array() );
		$error = array();
		foreach ( array_slice( (array) ( $in['items'] ?? array() ), 0, self::BATCH_MAX ) as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( ! $id || get_post_type( $id ) !== 'attachment' ) {
				$error[] = $id . ': not a Media Library item';
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$error[] = $id . ': you cannot edit this item';
				continue;
			}
			$fields = array();
			foreach ( $map as $key => $field ) {
				if ( isset( $item[ $key ] ) ) {
					$fields[ $field ] = $key === 'description' ? wp_kses_post( (string) $item[ $key ] ) : sanitize_text_field( (string) $item[ $key ] );
				}
			}
			$alt = isset( $item['alt'] ) ? sanitize_text_field( (string) $item['alt'] ) : null;
			if ( ! $fields && $alt === null ) {
				continue;
			}
			if ( $fields ) {
				$spec['post_fields'][ $id ] = array_keys( $fields );
			}
			if ( $alt !== null ) {
				$spec['post_meta'][ $id ] = array( '_wp_attachment_image_alt' );
			}
			$plan[ $id ] = array( $fields, $alt );
		}
		if ( $error && ! $plan ) {
			return new WP_Error( 'upw_ai_media', 'Nothing was changed: ' . implode( '; ', $error ) );
		}
		if ( ! $plan ) {
			return new WP_Error( 'upw_ai_media', 'Pass at least one of alt, title, caption or description per item.' );
		}
		$rev = FW_AI_Toolkit::snapshot( $spec, 'unysonplus/update-media', sprintf( _n( 'Image details (%d image)', 'Image details (%d images)', count( $plan ), 'fw' ), count( $plan ) ) );
		foreach ( $plan as $id => $p ) {
			if ( $p[0] ) {
				wp_update_post( wp_slash( array( 'ID' => $id ) + $p[0] ) );
			}
			if ( $p[1] !== null ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $p[1] ) );
			}
		}
		$out = array(
			'ok'               => true,
			'updated'          => array_keys( $plan ),
			'undo_revision_id' => $rev,
			'message'          => sprintf( 'Updated %d image(s) in the Media Library.', count( $plan ) ),
		);
		if ( $error ) {
			$out['not_changed'] = $error;
		}
		return $out;
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	public static function set_featured( $in ) {
		$pid = (int) ( $in['post_id'] ?? 0 );
		$mid = (int) ( $in['media_id'] ?? 0 );
		if ( ! get_post( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			return new WP_Error( 'upw_ai_media', 'No post with that post_id that you can edit.' );
		}
		if ( ! post_type_supports( get_post_type( $pid ), 'thumbnail' ) ) {
			return new WP_Error( 'upw_ai_media', 'This post type has no featured image.' );
		}
		if ( $mid && ! wp_attachment_is_image( $mid ) ) {
			return new WP_Error( 'upw_ai_media', 'media_id is not an image in the Media Library.' );
		}
		$rev = FW_AI_Toolkit::snapshot( array( 'post_meta' => array( $pid => array( '_thumbnail_id' ) ) ), 'unysonplus/set-featured-image', 'Featured image — ' . get_the_title( $pid ) );
		if ( $mid ) {
			set_post_thumbnail( $pid, $mid );
		} else {
			delete_post_thumbnail( $pid );
		}
		return array(
			'ok'               => true,
			'post_id'          => $pid,
			'featured_image'   => $mid ? (string) wp_get_attachment_url( $mid ) : '',
			'undo_revision_id' => $rev,
			'message'          => $mid ? 'Featured image set.' : 'Featured image removed.',
		);
	}
}
