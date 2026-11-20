<?php
namespace ZZZCopy\Images;

use ZZZCopy\Http;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Openverse API client, the only image source from 1.11.10. Openverse (run by WordPress.org)
 * searches openly licensed pictures from Flickr and Wikimedia Commons in one place.
 *
 * Only files kept on Flickr's or Wikimedia's own servers are used, so a download can only ever
 * come from those two hosts. Each file is shaped like the Commons files of earlier versions,
 * with the license and labels in an "extmetadata" record, so the license check, the scorer and
 * the history work unchanged.
 *
 * Searches are cached, paced, and stop at once when Openverse asks the plugin to slow down.
 * Without an account, Openverse allows 20 searches a minute and 200 a day (checked 2026-11-20).
 */
final class Openverse {

	private const CACHE_SECONDS = 21600;

	/** Openverse refuses more than 20 results a page without an account. */
	private const MAX_PAGE = 20;

	/** The copy of a Wikimedia file that is downloaded. A standard Commons width. */
	public const DOWNLOAD_WIDTH = 1920;

	/**
	 * Flickr pictures reach Openverse at most 1,024 pixels wide, so a stricter minimum than this
	 * would refuse every Flickr photograph. The admin minimum still applies when it is lower.
	 */
	public const LARGEST_MIN_WIDTH  = 1024;
	public const LARGEST_MIN_HEIGHT = 576;

	private const LONGEST_SLEEP = 15;

	/** Each try waits this long. Openverse can take 20 seconds to answer a search it has not seen before. */
	private const SEARCH_TIMEOUT = 30;

	public static function api_url(): string {
		return (string) apply_filters( 'zzzc_openverse_api_url', 'https://api.openverse.org/v1/images/' );
	}

	/**
	 * The only hosts images may be downloaded from.
	 */
	public static function media_hosts(): array {
		return (array) apply_filters( 'zzzc_image_media_hosts', array( 'upload.wikimedia.org', 'live.staticflickr.com' ) );
	}

	public static function min_width(): int {
		return min( (int) Settings::get( 'image_min_width' ), self::LARGEST_MIN_WIDTH );
	}

	public static function min_height(): int {
		return min( (int) Settings::get( 'image_min_height' ), self::LARGEST_MIN_HEIGHT );
	}

	private static function host(): string {
		return (string) wp_parse_url( self::api_url(), PHP_URL_HOST );
	}

	/**
	 * @return int Seconds still to wait. When above zero, no request may be sent yet.
	 */
	private static function pace(): int {
		$gap  = (int) Settings::get( 'image_api_gap_seconds' );
		$wait = Http::host_wait( self::host(), $gap );
		if ( $wait > 0 && $wait <= self::LONGEST_SLEEP ) {
			sleep( $wait );
			$wait = Http::host_wait( self::host(), $gap );
		}
		return $wait;
	}

	private static function result( array $fields = array() ): array {
		return array_merge(
			array(
				'ok'          => false,
				'items'       => array(),
				'temporary'   => false,
				'retry_after' => 0,
				'error'       => '',
				'cached'      => false,
				'not_sent'    => false,
			),
			$fields
		);
	}

	/**
	 * Searches Openverse for pictures that may be used commercially and changed.
	 *
	 * @return array{ok:bool, items:array, temporary:bool, retry_after:int, error:string, cached:bool, not_sent:bool}
	 */
	public static function search( string $query, ?int $limit = null ): array {
		$limit = max( 1, min( self::MAX_PAGE, $limit ?? (int) Settings::get( 'image_candidates_per_query' ) ) );
		$key   = 'zzzc_openverse_' . md5( $query . '|' . $limit );
		$saved = get_transient( $key );
		if ( is_array( $saved ) ) {
			return self::result(
				array(
					'ok'     => true,
					'items'  => $saved,
					'cached' => true,
				)
			);
		}

		$wait = self::pace();
		if ( $wait > 0 ) {
			return self::result(
				array(
					'temporary'   => true,
					'retry_after' => $wait,
					'error'       => sprintf( 'Waiting for Openverse, which asked the plugin to slow down. Nothing was sent. About %d seconds are left.', $wait ),
					'not_sent'    => true,
				)
			);
		}

		$url = add_query_arg(
			array(
				'q'            => rawurlencode( $query ),
				'license_type' => 'commercial,modification',
				'page_size'    => $limit,
				'mature'       => 'false',
			),
			self::api_url()
		);

		// Openverse often answers a search it has not seen before with HTTP 504 after 12 to 20
		// seconds, and the same search a moment later with results. Live on 2026-11-20, 4 of 10
		// first tries timed out and every one succeeded by the third. Tries stop after 45 seconds,
		// so the image step stays well inside the worker's time.
		$response = null;
		$started  = microtime( true );
		for ( $try = 1; $try <= 3; $try++ ) {
			$response = Http::get(
				$url,
				array(
					'accept'    => 'application/json',
					'timeout'   => self::SEARCH_TIMEOUT,
					'max_bytes' => 2097152,
				)
			);
			$slow = 0 === strpos( (string) $response['error'], 'network' ) || in_array( (int) $response['code'], array( 502, 504 ), true );
			if ( ! $slow || 3 === $try || microtime( true ) - $started > 45 ) {
				break;
			}
			sleep( 2 );
		}

		if ( '' !== $response['error'] ) {
			return self::result(
				array(
					'error'     => 'Openverse: ' . $response['error'],
					'temporary' => 0 === strpos( $response['error'], 'network' ),
				)
			);
		}
		if ( 429 === $response['code'] ) {
			$retry = max( 60, (int) $response['retry_after'] );
			Http::host_cooldown( self::host(), $retry );
			return self::result(
				array(
					'error'       => 'Openverse asked the plugin to slow down (HTTP 429). Without an account it allows 20 searches a minute and 200 a day.',
					'temporary'   => true,
					'retry_after' => $retry,
				)
			);
		}
		if ( ! $response['ok'] ) {
			return self::result(
				array(
					'error'     => sprintf( 'Openverse returned HTTP %d.', $response['code'] ),
					'temporary' => $response['code'] >= 500,
				)
			);
		}

		$data = json_decode( (string) $response['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return self::result(
				array(
					'error'     => 'Openverse returned a reply that could not be read.',
					'temporary' => true,
				)
			);
		}

		$items = array();
		foreach ( $data['results'] as $result ) {
			$item = self::normalize( (array) $result );
			if ( $item ) {
				$items[] = $item;
			}
		}

		set_transient( $key, $items, self::CACHE_SECONDS );
		return self::result(
			array(
				'ok'    => true,
				'items' => $items,
			)
		);
	}

	/**
	 * A whole number for the history table, which keeps one row per picture. Openverse names a
	 * picture with a UUID. Commons page numbers are far below 2^40, so they cannot collide.
	 */
	public static function number_for( string $id ): int {
		return (int) ( hexdec( substr( sha1( 'openverse:' . strtolower( $id ) ), 0, 13 ) ) + 1099511627776 );
	}

	/**
	 * The Commons resized copy of a Wikimedia original, or '' when the original is small enough.
	 */
	private static function wikimedia_resized( string $url, int $width ): string {
		if ( $width <= self::DOWNLOAD_WIDTH || ! preg_match( '~^https://upload\.wikimedia\.org/wikipedia/commons/([0-9a-f])/([0-9a-f]{2})/([^/?#]+)$~', $url, $match ) ) {
			return '';
		}
		return sprintf( 'https://upload.wikimedia.org/wikipedia/commons/thumb/%1$s/%2$s/%3$s/%4$dpx-%3$s', $match[1], $match[2], $match[3], self::DOWNLOAD_WIDTH );
	}

	/**
	 * Shapes one Openverse result as the rest of the image code expects.
	 */
	public static function normalize( array $result ): ?array {
		$url    = (string) ( $result['url'] ?? '' );
		$id     = (string) ( $result['id'] ?? '' );
		$source = strtolower( (string) ( $result['source'] ?? '' ) );
		if ( '' === $url || '' === $id || ! in_array( $source, array( 'flickr', 'wikimedia' ), true ) || ! self::is_media_url( $url ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		)[ $ext ] ?? '';

		$width  = (int) ( $result['width'] ?? 0 );
		$height = (int) ( $result['height'] ?? 0 );
		$label  = trim( html_entity_decode( (string) ( $result['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$label  = (string) preg_replace( '/^File:/i', '', $label );

		// The title keeps one row per picture in the history. A Wikimedia file keeps its own file
		// name, so a file used through Commons before 1.11.10 is still recognised. Many Flickr
		// photographs share a title, such as "Doha Film Festival", so the photo number is added.
		if ( 'wikimedia' === $source ) {
			$title = 'File:' . rawurldecode( basename( $path ) );
		} else {
			$photo = preg_match( '#/(\d+)/?$#', (string) ( $result['foreign_landing_url'] ?? '' ), $number ) ? $number[1] : substr( $id, 0, 8 );
			$title = ( '' !== $label ? $label : 'Flickr photo' ) . ' (Flickr ' . $photo . ')';
		}

		// Tags people gave the picture. Tags with an accuracy were guessed by a machine, such as
		// "people" on a picture of a stage, and are left out, so they cannot trigger the rules.
		$tags = array();
		foreach ( (array) ( $result['tags'] ?? array() ) as $tag ) {
			if ( is_array( $tag ) && null === ( $tag['accuracy'] ?? null ) && '' !== trim( (string) ( $tag['name'] ?? '' ) ) ) {
				$tags[] = trim( (string) $tag['name'] );
			}
		}

		$license = strtolower( (string) ( $result['license'] ?? '' ) );
		$version = (string) ( $result['license_version'] ?? '' );
		if ( 'cc0' === $license ) {
			$short = 'CC0';
			$code  = 'cc0';
		} elseif ( 'pdm' === $license ) {
			$short = 'Public domain';
			$code  = 'pd';
		} elseif ( '' !== $license ) {
			$short = 'CC ' . strtoupper( $license ) . ( '' !== $version ? ' ' . $version : '' );
			$code  = 'cc-' . $license . ( '' !== $version ? '-' . $version : '' );
		} else {
			$short = '';
			$code  = '';
		}

		$creator     = trim( (string) ( $result['creator'] ?? '' ) );
		$creator_url = esc_url_raw( (string) ( $result['creator_url'] ?? '' ) );
		$artist      = '' !== $creator && '' !== $creator_url ? '<a href="' . esc_url( $creator_url ) . '">' . esc_html( $creator ) . '</a>' : esc_html( $creator );

		$resized = 'wikimedia' === $source ? self::wikimedia_resized( $url, $width ) : '';

		return array(
			'page_id'     => self::number_for( $id ),
			'openverse_id' => $id,
			'source'      => $source,
			'title'       => $title,
			'file_url'    => $url,
			'download'    => '' !== $resized ? $resized : $url,
			'thumb_url'   => (string) ( $result['thumbnail'] ?? '' ),
			'page_url'    => esc_url_raw( (string) ( $result['foreign_landing_url'] ?? '' ) ),
			'width'       => $width,
			'height'      => $height,
			// The stated size is only checked for the original file, not for the resized copy.
			'size'        => '' === $resized ? (int) ( $result['filesize'] ?? 0 ) : 0,
			'mime'        => $mime,
			'extmetadata' => array(
				'ObjectName'          => array( 'value' => $label ),
				'ImageDescription'    => array( 'value' => '' ),
				'Categories'          => array( 'value' => implode( '|', $tags ) ),
				'LicenseShortName'    => array( 'value' => $short ),
				'License'             => array( 'value' => $code ),
				'LicenseUrl'          => array( 'value' => (string) ( $result['license_url'] ?? '' ) ),
				'Artist'              => array( 'value' => $artist ),
				'UsageTerms'          => array( 'value' => 'pd' === $code ? 'Public Domain Mark' : '' ),
				'AttributionRequired' => array( 'value' => in_array( $code, array( 'cc0', 'pd' ), true ) ? 'false' : 'true' ),
			),
		);
	}

	/**
	 * Whether a URL is an https address on an approved media host, or an approved test host.
	 */
	public static function is_media_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$full = $host . ( $port ? ':' . $port : '' );
		foreach ( self::media_hosts() as $allowed ) {
			if ( $allowed === $host || $allowed === $full ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Addresses the admin screens may show as small previews. Files are only ever downloaded from media_hosts().
	 */
	public static function is_preview_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return self::is_media_url( $url ) || $host === self::host();
	}

	/**
	 * A picture's name without the Flickr photo number that keeps history rows apart.
	 */
	public static function display_title( string $title ): string {
		return trim( (string) preg_replace( '/\s*\(Flickr [0-9a-z]+\)$/i', '', $title ) );
	}

	/**
	 * The name shown in a credit for a file's page address.
	 */
	public static function source_name( string $page_url ): string {
		$host = strtolower( (string) wp_parse_url( $page_url, PHP_URL_HOST ) );
		if ( 'flickr.com' === $host || '.flickr.com' === substr( $host, -11 ) ) {
			return 'Flickr';
		}
		return 'Wikimedia Commons';
	}

	/**
	 * A small request to confirm the API can be reached.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function test_connection(): array {
		$result = self::search( 'Earth', 5 );
		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 'Openverse could not be reached: ' . $result['error'],
			);
		}
		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected to Openverse. A test search returned %d usable files from Flickr and Wikimedia Commons.', count( $result['items'] ) ),
		);
	}
}
