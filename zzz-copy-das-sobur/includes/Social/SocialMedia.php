<?php
namespace ZZZCopy\Social;

use ZZZCopy\Http;
use ZZZCopy\Images\Attribution;
use ZZZCopy\Images\ImagePipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The featured image, prepared for one platform.
 *
 * There is no second image search here. The picture is the one the existing Wikimedia Commons
 * pipeline already chose and WordPress already holds, checked with the same ImagePipeline test
 * the publisher uses, so a post is never sent with an image the site cannot actually serve.
 *
 * Nothing is re-encoded, cropped or resized. The owner's rule is that a CC BY-SA image must not
 * be edited, and resizing one would create a derivative work and bring the share-alike condition
 * into play. So when the full-size image does not meet a platform's limits, this class looks
 * through the sizes WordPress itself generated when the image was imported and picks the largest
 * one that does fit. Those files already exist for every image on the site; choosing between them
 * creates nothing new.
 *
 * When no available size fits, the image is not sent. A platform that can publish without one
 * publishes text only, and a platform that requires one is skipped for that article with the
 * reason recorded. An image is never invented and a post is never sent claiming an image that
 * did not upload.
 */
final class SocialMedia {

	/**
	 * Works out what image, if any, this platform can be sent for this post.
	 *
	 * @param int    $post_id  The published WordPress post.
	 * @param string $platform One of the six.
	 * @return array{ok:bool, reason:string, fatal:bool, attachment_id:int, url:string, path:string,
	 *               mime:string, bytes:int, width:int, height:int, alt:string, size:string}
	 */
	public static function resolve( int $post_id, string $platform ): array {
		$out = array(
			'ok'            => false,
			'reason'        => '',
			'fatal'         => false,
			'attachment_id' => 0,
			'url'           => '',
			'path'          => '',
			'mime'          => '',
			'bytes'         => 0,
			'width'         => 0,
			'height'        => 0,
			'alt'           => '',
			'size'          => '',
		);

		if ( ! SocialSettings::uses_featured_image() ) {
			$out['reason'] = 'Using featured images is switched off in the social distribution settings.';
			return $out;
		}

		// The same check the publisher makes, so the two can never disagree about whether an
		// image is usable. It covers a missing attachment, a missing file and unfinished
		// WordPress processing.
		$problem = ImagePipeline::image_problem( $post_id );
		if ( '' !== $problem ) {
			$out['reason'] = $problem;
			return $out;
		}

		$attachment_id         = (int) get_post_thumbnail_id( $post_id );
		$out['attachment_id']  = $attachment_id;
		$out['alt']            = self::alt_text( $attachment_id, $post_id );

		$needs_public = Platforms::needs_public_image_url( $platform );
		if ( $needs_public ) {
			$public = self::site_is_publicly_reachable();
			if ( '' !== $public ) {
				$out['reason'] = $public;
				$out['fatal']  = true;
				return $out;
			}
		}

		$candidate = self::best_size( $attachment_id, $platform );
		if ( ! $candidate['ok'] ) {
			$out['reason'] = $candidate['reason'];
			return $out;
		}

		return array_merge( $out, $candidate, array( 'ok' => true, 'reason' => '' ) );
	}

	/**
	 * Picks the largest image size WordPress already holds for this attachment that meets every
	 * one of the platform's requirements: media type, file size, and aspect ratio where the
	 * platform sets one.
	 *
	 * @return array{ok:bool, reason:string, url:string, path:string, mime:string, bytes:int,
	 *               width:int, height:int, size:string}
	 */
	private static function best_size( int $attachment_id, string $platform ): array {
		$allowed_mime = (array) Platforms::value( $platform, 'image_mime', array( 'image/jpeg' ) );
		$max_bytes    = (int) Platforms::value( $platform, 'image_max_bytes', 5242880 );
		$min_ratio    = (float) Platforms::value( $platform, 'image_min_ratio', 0 );
		$max_ratio    = (float) Platforms::value( $platform, 'image_max_ratio', 0 );

		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		$dir  = (string) get_attached_file( $attachment_id );
		$base = $dir ? dirname( $dir ) : '';

		$options = array();

		// The original first, then every generated size, largest first.
		$options[] = array(
			'size'   => 'full',
			'path'   => $dir,
			'url'    => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
		);

		$sizes = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		uasort(
			$sizes,
			static function ( $a, $b ) {
				return (int) ( $b['width'] ?? 0 ) <=> (int) ( $a['width'] ?? 0 );
			}
		);
		foreach ( $sizes as $name => $size ) {
			if ( empty( $size['file'] ) || '' === $base ) {
				continue;
			}
			$options[] = array(
				'size'   => (string) $name,
				'path'   => $base . '/' . (string) $size['file'],
				'url'    => (string) wp_get_attachment_image_url( $attachment_id, (string) $name ),
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
			);
		}

		$reasons = array();

		foreach ( $options as $option ) {
			if ( '' === $option['path'] || ! is_readable( $option['path'] ) || '' === $option['url'] ) {
				continue;
			}

			$mime = (string) ( wp_check_filetype( $option['path'] )['type'] ?? '' );
			if ( '' === $mime ) {
				continue;
			}
			if ( ! in_array( $mime, $allowed_mime, true ) ) {
				$reasons['mime'] = sprintf(
					'%1$s accepts only %2$s, and the featured image is %3$s.',
					Platforms::label( $platform ),
					implode( ' or ', array_map( array( __CLASS__, 'mime_label' ), $allowed_mime ) ),
					self::mime_label( $mime )
				);
				continue;
			}

			$bytes = (int) filesize( $option['path'] );
			if ( $bytes < 1 ) {
				continue;
			}
			if ( $bytes > $max_bytes ) {
				$reasons['bytes'] = sprintf(
					'%1$s accepts images up to %2$s, and the smallest size WordPress holds for this image is %3$s. The image was not resized, because editing it is not allowed under the owner\'s image rules.',
					Platforms::label( $platform ),
					size_format( $max_bytes ),
					size_format( $bytes )
				);
				continue;
			}

			if ( $min_ratio > 0 && $max_ratio > 0 && $option['height'] > 0 ) {
				$ratio = $option['width'] / $option['height'];
				if ( $ratio < $min_ratio || $ratio > $max_ratio ) {
					$reasons['ratio'] = sprintf(
						'%1$s accepts a shape between %2$.2f and %3$.2f wide for each 1 high, and this image is %4$.2f. It was not cropped, because editing it is not allowed under the owner\'s image rules.',
						Platforms::label( $platform ),
						$min_ratio,
						$max_ratio,
						$ratio
					);
					continue;
				}
			}

			return array(
				'ok'     => true,
				'reason' => '',
				'size'   => $option['size'],
				'path'   => $option['path'],
				'url'    => $option['url'],
				'mime'   => $mime,
				'bytes'  => $bytes,
				'width'  => $option['width'],
				'height' => $option['height'],
			);
		}

		return array(
			'ok'     => false,
			'reason' => $reasons ? implode( ' ', $reasons ) : 'No size of the featured image could be read from the server.',
			'size'   => '',
			'path'   => '',
			'url'    => '',
			'mime'   => '',
			'bytes'  => 0,
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * The image's bytes, for the platforms that take the contents rather than fetching a URL.
	 * Returns an empty string when the file cannot be read, which the caller must treat as a
	 * failed upload rather than publishing without the image.
	 */
	public static function bytes( array $media ): string {
		$path = (string) ( $media['path'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $bytes ? '' : (string) $bytes;
	}

	/**
	 * Alt text for the image, so the post is readable to people using a screen reader.
	 * The attachment's own alt text comes first, then its title, then the article's title.
	 */
	public static function alt_text( int $attachment_id, int $post_id ): string {
		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$alt = trim( (string) get_the_title( $attachment_id ) );
		}
		if ( '' === $alt ) {
			$alt = trim( (string) get_the_title( $post_id ) );
		}

		// Name the creator where the credit records one, which is both clearer for the reader
		// and consistent with the credit the site itself shows under the picture.
		$credit = get_post_meta( $post_id, Attribution::META, true );
		if ( is_array( $credit ) && '' !== trim( (string) ( $credit['creator'] ?? '' ) ) ) {
			$alt .= sprintf( ' (photograph by %s)', trim( (string) $credit['creator'] ) );
		}

		return mb_substr( $alt, 0, 950 );
	}

	/**
	 * Whether this site can be reached from the internet, which the platforms that fetch the
	 * image by address need. Returns an empty string when it can, or the reason when it cannot.
	 *
	 * This is checked rather than assumed, because on a local or private site Instagram, Facebook
	 * and Threads would simply report a vague failure after several attempts, and the real cause
	 * would never be clear from the log.
	 */
	public static function site_is_publicly_reachable(): string {
		$home = home_url( '/' );
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return 'This site has no web address, so a platform cannot fetch the featured image.';
		}
		if ( 'localhost' === $host || '.local' === substr( $host, -6 ) || '.test' === substr( $host, -5 ) || '.localhost' === substr( $host, -10 ) ) {
			return sprintf( 'This site runs at %s, which the platform cannot reach from the internet, so it cannot fetch the featured image.', $host );
		}

		// The same private-network test the rest of the plugin uses.
		$check = Http::check_url( $home );
		if ( ! $check['ok'] && 'private_address' === $check['reason'] ) {
			return sprintf( 'This site\'s address %s resolves to a private network address, which the platform cannot reach, so it cannot fetch the featured image.', $host );
		}

		return '';
	}

	private static function mime_label( string $mime ): string {
		$map = array(
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
			'image/webp' => 'WebP',
		);
		return $map[ $mime ] ?? $mime;
	}
}
