<?php
namespace ZZZCopy\Images;

use ZZZCopy\Http;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wikimedia Commons API client. Metadata comes only from the official API.
 * Search results are cached, requests are paced, and rate limits are respected.
 */
final class Commons {

	private const CACHE_SECONDS = 21600;

	/** The copy that is downloaded. A standard Commons width, so it is ready at once. The original can be 10 times larger. */
	public const DOWNLOAD_WIDTH = 1920;

	/** The small copy the admin screens show. Commons refuses widths that are not standard, such as 640. */
	private const PREVIEW_WIDTH = 330;

	/** The longest the worker will sleep for Wikimedia. A longer wait is left to a later attempt. */
	private const LONGEST_SLEEP = 15;

	/** Errors the API reports in a normal reply that clear by themselves. */
	private const TEMPORARY_API_ERRORS = array( 'ratelimited', 'maxlag', 'readonly' );

	public static function api_url(): string {
		return (string) apply_filters( 'zzzc_commons_api_url', 'https://commons.wikimedia.org/w/api.php' );
	}

	/**
	 * The only hosts images may be downloaded from.
	 */
	public static function media_hosts(): array {
		return (array) apply_filters( 'zzzc_commons_media_hosts', array( 'upload.wikimedia.org', 'thumb.wikimedia.org' ) );
	}

	/**
	 * Waits out a short pacing gap. Called from the background worker, and bounded.
	 *
	 * @return int Seconds still to wait. When above zero, no request may be sent yet.
	 */
	private static function pace(): int {
		$host = (string) wp_parse_url( self::api_url(), PHP_URL_HOST );
		$gap  = (int) Settings::get( 'image_api_gap_seconds' );
		$wait = Http::host_wait( $host, $gap );
		if ( $wait > 0 && $wait <= self::LONGEST_SLEEP ) {
			sleep( $wait );
			$wait = Http::host_wait( $host, $gap );
		}
		return $wait;
	}

	/**
	 * Searches Commons.
	 *
	 * @return array{ok:bool, items:array, temporary:bool, retry_after:int, error:string, cached:bool, not_sent:bool}
	 */
	public static function search( string $query, ?int $limit = null ): array {
		$limit = $limit ?? (int) Settings::get( 'image_candidates_per_query' );
		// Commons leaves out files smaller than the minimum size, so all the results can be used.
		$search = sprintf( 'filetype:bitmap filew:>%1$d fileh:>%2$d %3$s', (int) Settings::get( 'image_min_width' ) - 1, (int) Settings::get( 'image_min_height' ) - 1, $query );
		$key    = 'zzzc_commons_' . md5( $search . '|' . $limit );
		$saved = get_transient( $key );
		if ( is_array( $saved ) ) {
			return array(
				'ok'          => true,
				'items'       => $saved,
				'temporary'   => false,
				'retry_after' => 0,
				'error'       => '',
				'cached'      => true,
				'not_sent'    => false,
			);
		}

		$wait = self::pace();
		if ( $wait > 0 ) {
			return array(
				'ok'          => false,
				'items'       => array(),
				'temporary'   => true,
				'retry_after' => $wait,
				'error'       => sprintf( 'Waiting for Wikimedia Commons, which asked the plugin to slow down. Nothing was sent. About %d seconds are left.', $wait ),
				'cached'      => false,
				'not_sent'    => true,
			);
		}

		$url = add_query_arg(
			array(
				'action'        => 'query',
				'generator'     => 'search',
				'gsrsearch'     => rawurlencode( $search ),
				'gsrnamespace'  => 6,
				'gsrlimit'      => $limit,
				'prop'          => 'imageinfo',
				'iiprop'        => rawurlencode( 'url|size|mime|extmetadata' ),
				'iiurlwidth'    => self::DOWNLOAD_WIDTH,
				'format'        => 'json',
				'formatversion' => 2,
			),
			self::api_url()
		);

		$response = Http::get(
			$url,
			array(
				'accept'    => 'application/json',
				'timeout'   => 20,
				'max_bytes' => 5242880,
			)
		);

		$out = array(
			'ok'          => false,
			'items'       => array(),
			'temporary'   => false,
			'retry_after' => 0,
			'error'       => '',
			'cached'      => false,
			'not_sent'    => false,
		);

		if ( '' !== $response['error'] ) {
			$out['error']     = $response['error'];
			$out['temporary'] = 0 === strpos( $response['error'], 'network' );
			return $out;
		}
		if ( 429 === $response['code'] || 503 === $response['code'] ) {
			$out['error']       = sprintf( 'Wikimedia Commons asked the plugin to slow down (HTTP %d).', $response['code'] );
			$out['temporary']   = true;
			$out['retry_after'] = max( 60, (int) $response['retry_after'] );
			Http::host_cooldown( (string) wp_parse_url( self::api_url(), PHP_URL_HOST ), $out['retry_after'] );
			return $out;
		}
		if ( ! $response['ok'] ) {
			$out['error']     = sprintf( 'Wikimedia Commons returned HTTP %d.', $response['code'] );
			$out['temporary'] = $response['code'] >= 500;
			return $out;
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			$out['error']     = 'Wikimedia Commons returned a reply that could not be read.';
			$out['temporary'] = true;
			return $out;
		}
		if ( isset( $data['error'] ) ) {
			$out['error'] = 'Wikimedia Commons error: ' . sanitize_text_field( (string) ( $data['error']['info'] ?? $data['error']['code'] ?? 'unknown' ) );
			if ( in_array( (string) ( $data['error']['code'] ?? '' ), self::TEMPORARY_API_ERRORS, true ) ) {
				$out['temporary']   = true;
				$out['retry_after'] = max( 60, (int) $response['retry_after'] );
				Http::host_cooldown( (string) wp_parse_url( self::api_url(), PHP_URL_HOST ), $out['retry_after'] );
			}
			return $out;
		}

		foreach ( (array) ( $data['query']['pages'] ?? array() ) as $page ) {
			$item = self::normalize( (array) $page );
			if ( $item ) {
				$out['items'][] = $item;
			}
		}

		$out['ok'] = true;
		set_transient( $key, $out['items'], self::CACHE_SECONDS );
		return $out;
	}

	private static function normalize( array $page ): ?array {
		$info = $page['imageinfo'][0] ?? null;
		if ( ! is_array( $info ) || empty( $info['url'] ) || empty( $page['pageid'] ) ) {
			return null;
		}
		// Commons makes a copy at a standard width. Commons gives back the original when it is no wider.
		$resized = (string) ( $info['thumburl'] ?? '' );
		return array(
			'page_id'     => (int) $page['pageid'],
			'title'       => (string) ( $page['title'] ?? '' ),
			'file_url'    => (string) $info['url'],
			'download'    => '' !== $resized ? $resized : (string) $info['url'],
			'thumb_url'   => str_replace( '/' . self::DOWNLOAD_WIDTH . 'px-', '/' . self::PREVIEW_WIDTH . 'px-', $resized ),
			'page_url'    => (string) ( $info['descriptionurl'] ?? '' ),
			'width'       => (int) ( $info['width'] ?? 0 ),
			'height'      => (int) ( $info['height'] ?? 0 ),
			'size'        => (int) ( $info['size'] ?? 0 ),
			'mime'        => (string) ( $info['mime'] ?? '' ),
			'extmetadata' => (array) ( $info['extmetadata'] ?? array() ),
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
		return self::is_media_url( $url ) || 'thumb.wikimedia.org' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
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
				'message' => 'Wikimedia Commons could not be reached: ' . $result['error'],
			);
		}
		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected to Wikimedia Commons. A test search returned %d files.', count( $result['items'] ) ),
		);
	}
}
