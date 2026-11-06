<?php
namespace ZZZCopy\Images;

use ZZZCopy\Http;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads an approved image, checks the real file, and adds it to the Media Library.
 * Uses the plugin's safe fetcher, which refuses any host other than the approved Wikimedia media host.
 */
final class MediaImporter {

	private const TYPES = array(
		IMAGETYPE_JPEG => array( 'image/jpeg', 'jpg' ),
		IMAGETYPE_PNG  => array( 'image/png', 'png' ),
		IMAGETYPE_WEBP => array( 'image/webp', 'webp' ),
	);

	/**
	 * Downloads to a temporary file and validates it.
	 *
	 * @return array{ok:bool, temporary:bool, retry_after:int, reason:string, path:string, sha256:string, phash:string, width:int, height:int, ext:string, mime:string}
	 */
	public static function download( array $candidate ): array {
		$out = array(
			'ok'          => false,
			'temporary'   => false,
			'retry_after' => 0,
			'reason'      => '',
			'path'        => '',
			'sha256'      => '',
			'phash'       => '',
			'width'       => 0,
			'height'      => 0,
			'ext'         => '',
			'mime'        => '',
		);

		// The resized copy when Commons offers one. Size checks below use the downloaded file itself.
		$url      = (string) ( $candidate['download'] ?? '' ) ?: (string) $candidate['file_url'];
		$original = $url === (string) $candidate['file_url'];
		if ( ! Commons::is_media_url( $url ) ) {
			$out['reason'] = 'The file address is not on an approved Wikimedia host.';
			return $out;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp = wp_tempnam( 'zzzc-image' );
		if ( ! $temp ) {
			$out['reason']    = 'A temporary file could not be created.';
			$out['temporary'] = true;
			return $out;
		}

		$max      = (int) Settings::get( 'image_max_file_mb' ) * 1048576;
		$response = Http::get(
			$url,
			array(
				'accept'     => 'image/jpeg,image/png,image/webp',
				'timeout'    => 90,
				'max_bytes'  => $max + 1,
				'stream_to'  => $temp,
				'only_hosts' => Commons::media_hosts(),
			)
		);

		$fail = static function ( string $reason, bool $temporary = false, int $retry_after = 0 ) use ( &$out, $temp ) {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
			$out['reason']      = $reason;
			$out['temporary']   = $temporary;
			$out['retry_after'] = $retry_after;
			return $out;
		};

		if ( '' !== $response['error'] ) {
			return $fail( 'Download failed: ' . $response['error'], 0 === strpos( $response['error'], 'network' ) );
		}
		if ( 429 === $response['code'] || $response['code'] >= 500 ) {
			return $fail( sprintf( 'Download failed with HTTP %d.', $response['code'] ), true, (int) $response['retry_after'] );
		}
		if ( ! $response['ok'] ) {
			return $fail( sprintf( 'Download failed with HTTP %d.', $response['code'] ) );
		}

		$size = (int) filesize( $temp );
		if ( $size <= 0 ) {
			return $fail( 'The downloaded file is empty.', true );
		}
		if ( $size > $max ) {
			return $fail( 'The file is over the size limit.' );
		}

		// Read the real type and size from the file itself, not from what the server claimed.
		$info = @getimagesize( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $info ) || ! isset( self::TYPES[ $info[2] ] ) ) {
			return $fail( 'The downloaded file is not a valid JPEG, PNG or WebP image.' );
		}
		if ( (int) $info[0] < (int) Settings::get( 'image_min_width' ) || (int) $info[1] < (int) Settings::get( 'image_min_height' ) ) {
			return $fail( sprintf( 'The real image is too small: %1$d by %2$d pixels.', $info[0], $info[1] ) );
		}

		// GD accepts a cut-off JPEG and fills the missing part with gray, so completeness is checked separately.
		// The stated size is for the original. A resized copy is checked by its own end marker instead.
		if ( ! self::is_complete( $temp, (int) $info[2], $original ? (int) ( $candidate['size'] ?? 0 ) : 0 ) ) {
			return $fail( 'The image file is incomplete.' );
		}

		// WordPress must decode the image to make its sizes. Refuse one that would exhaust this server's memory.
		$fits_memory = self::fits_memory( (int) $info[0], (int) $info[1] );
		if ( ! $fits_memory && ! extension_loaded( 'imagick' ) ) {
			return $fail( sprintf( 'The image is too large for this server to process: %1$d by %2$d pixels.', $info[0], $info[1] ) );
		}

		// The damage, sharpness and look-alike checks need GD and enough memory to decode the file.
		if ( $fits_memory && function_exists( 'imagecreatefromstring' ) ) {
			$pixels = self::load_small( $temp );
			if ( null === $pixels ) {
				return $fail( 'The image file is damaged and could not be read.' );
			}
			if ( self::is_blurry( $pixels ) ) {
				imagedestroy( $pixels );
				return $fail( 'The image failed the sharpness check.' );
			}
			$out['phash'] = self::dhash( $pixels );
			imagedestroy( $pixels );
		}

		$out['ok']     = true;
		$out['path']   = $temp;
		$out['sha256'] = (string) hash_file( 'sha256', $temp );
		$out['width']  = (int) $info[0];
		$out['height'] = (int) $info[1];
		$out['mime']   = self::TYPES[ $info[2] ][0];
		$out['ext']    = self::TYPES[ $info[2] ][1];
		return $out;
	}

	/**
	 * Whether the whole file arrived. Commons states the exact size of every file, which is the reliable test.
	 * Without a stated size, the format's end marker is looked for. Real files can have padding after that marker.
	 */
	private static function is_complete( string $path, int $type, int $declared_size ): bool {
		$size = (int) filesize( $path );
		if ( $declared_size > 0 ) {
			return $size === $declared_size;
		}

		$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( IMAGETYPE_JPEG === $type ) {
			return false !== strrpos( $data, "\xFF\xD9" );
		}
		if ( IMAGETYPE_PNG === $type ) {
			return false !== strrpos( $data, 'IEND' );
		}
		if ( IMAGETYPE_WEBP === $type && strlen( $data ) >= 12 ) {
			$riff = unpack( 'V', substr( $data, 4, 4 ) );
			return (int) $riff[1] + 8 <= $size;
		}
		return false;
	}

	/**
	 * Whether decoding an image of this size leaves enough free memory.
	 */
	private static function fits_memory( int $width, int $height ): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		// About 4 bytes a pixel, plus working room for the resized copies WordPress makes.
		return memory_get_usage() + $width * $height * 4 * 1.8 < $limit;
	}

	/**
	 * Loads a small grayscale copy. Returns null if the file cannot be decoded.
	 *
	 * @return \GdImage|null
	 */
	private static function load_small( string $path ) {
		$image = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $image ) {
			return null;
		}
		$small = imagescale( $image, 256, 144 );
		imagedestroy( $image );
		if ( ! $small ) {
			return null;
		}
		imagefilter( $small, IMG_FILTER_GRAYSCALE );
		return $small;
	}

	/**
	 * Sharpness estimate: variance of the Laplacian on a small copy. Approximate, so the limit is low
	 * and only clearly soft images are rejected.
	 */
	private static function is_blurry( $image ): bool {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		if ( $width < 32 || $height < 32 ) {
			return false;
		}
		$sum   = 0.0;
		$sqr   = 0.0;
		$count = 0;
		$gray  = static function ( int $x, int $y ) use ( $image ): int {
			return imagecolorat( $image, $x, $y ) & 0xFF;
		};
		for ( $y = 1; $y < $height - 1; $y += 2 ) {
			for ( $x = 1; $x < $width - 1; $x += 2 ) {
				$value = $gray( $x - 1, $y ) + $gray( $x + 1, $y ) + $gray( $x, $y - 1 ) + $gray( $x, $y + 1 ) - 4 * $gray( $x, $y );
				$sum  += $value;
				$sqr  += $value * $value;
				$count++;
			}
		}
		$mean     = $sum / max( 1, $count );
		$variance = $sqr / max( 1, $count ) - $mean * $mean;
		return $variance < (float) apply_filters( 'zzzc_image_sharpness_limit', 15.0 );
	}

	/**
	 * Difference hash: stays close when an image is resized or recompressed.
	 */
	private static function dhash( $image ): string {
		if ( imagesx( $image ) < 32 ) {
			return '';
		}
		$small = imagescale( $image, 9, 8, IMG_BICUBIC );
		if ( ! $small ) {
			return '';
		}
		$bits = '';
		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 8; $x++ ) {
				$bits .= ( imagecolorat( $small, $x, $y ) & 0xFF ) > ( imagecolorat( $small, $x + 1, $y ) & 0xFF ) ? '1' : '0';
			}
		}
		imagedestroy( $small );
		$hex = '';
		foreach ( str_split( $bits, 4 ) as $nibble ) {
			$hex .= dechex( (int) bindec( $nibble ) );
		}
		return $hex;
	}

	/**
	 * Adds a validated file to the Media Library. Removes the temporary file either way.
	 *
	 * @return int|\WP_Error Attachment id.
	 */
	public static function sideload( string $path, string $ext, array $history ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$base = sanitize_file_name( (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $history['image_title'] ) );
		$base = substr( $base ?: 'commons-image', 0, 120 );

		$file = array(
			'name'     => $base . '.' . $ext,
			'tmp_name' => $path,
		);

		$title         = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $history['image_title'] );
		$attachment_id = media_handle_sideload( $file, 0, $title );

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $title );
		update_post_meta(
			(int) $attachment_id,
			'_zzzc_image_source',
			array(
				'creator'         => (string) $history['creator'],
				'creator_url'     => (string) $history['creator_url'],
				'license'         => (string) $history['license'],
				'license_version' => (string) $history['license_version'],
				'license_url'     => (string) $history['license_url'],
				'file_url'        => (string) $history['file_url'],
				'commons_url'     => (string) $history['commons_url'],
				'modifications'   => (string) $history['modifications'],
			)
		);

		return (int) $attachment_id;
	}
}
