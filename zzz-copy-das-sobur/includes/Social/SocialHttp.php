<?php
namespace ZZZCopy\Social;

use ZZZCopy\Http;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Requests to the social platforms' own APIs.
 *
 * The plugin's Http class is built for fetching untrusted publisher pages: GET only, with
 * redirects inspected hop by hop. These are different requests. They go only to a small list of
 * hosts the plugin knows, they carry a credential, and most of them are POSTs. So they are made
 * here instead, with the parts that still matter kept:
 *
 * - The host must be one of the platform's own. A stored value that has been tampered with
 *   cannot make the site call somewhere else.
 * - The address is checked for a private or internal network, reusing Http's check, so a
 *   platform host that resolves inside the network is refused.
 * - Redirects are not followed. An API that answers with a redirect is a problem to report,
 *   not something to chase while carrying an access token.
 * - Every error, body and header that leaves this class has already passed through redaction.
 */
final class SocialHttp {

	/** Hosts each platform is allowed to be called on, including the hosts they redirect work to. */
	private const HOSTS = array(
		Platforms::FACEBOOK  => array( 'graph.facebook.com', 'www.facebook.com' ),
		Platforms::INSTAGRAM => array( 'graph.facebook.com', 'www.facebook.com' ),
		Platforms::X         => array( 'api.x.com', 'api.twitter.com', 'upload.twitter.com', 'twitter.com' ),
		Platforms::LINKEDIN  => array( 'api.linkedin.com', 'www.linkedin.com' ),
		Platforms::THREADS   => array( 'graph.threads.net', 'www.threads.net', 'threads.net' ),
		Platforms::BLUESKY   => array( 'bsky.social', 'bsky.network', 'public.api.bsky.app' ),
	);

	/**
	 * Makes one request.
	 *
	 * @param string $platform One of the six, used to decide which hosts are allowed.
	 * @param string $url      Full address.
	 * @param array  $args     method, headers, json, form, body, timeout, allow_hosts, files.
	 * @return array{ok:bool, code:int, body:string, json:array, headers:array, error:string,
	 *               retry_after:int, rate_remaining:int, rate_reset:int}
	 */
	public static function request( string $platform, string $url, array $args = array() ): array {
		$out = array(
			'ok'             => false,
			'code'           => 0,
			'body'           => '',
			'json'           => array(),
			'headers'        => array(),
			'error'          => '',
			'retry_after'    => 0,
			'rate_remaining' => -1,
			'rate_reset'     => 0,
		);

		// Both of these exist so the adapters can be pointed at a local stand-in for the platform
		// in the test suite, in the same way the OpenRouter and Wikimedia Commons clients already
		// are. On a real site no code adds these filters, so the addresses and the allowed hosts
		// are exactly the ones written above.
		$url     = (string) apply_filters( 'zzzc_social_api_url', $url, $platform );
		$allowed = isset( $args['allow_hosts'] ) ? (array) $args['allow_hosts'] : ( self::HOSTS[ $platform ] ?? array() );
		$allowed = (array) apply_filters( 'zzzc_social_allowed_hosts', $allowed, $platform );
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( ! $allowed || ! self::host_allowed( $host, $allowed ) ) {
			$out['error'] = 'unsafe_url:host_not_allowed';
			return $out;
		}

		// Same private-network check the rest of the plugin uses, so an API host that resolves
		// to an internal address is refused rather than requested.
		$check = Http::check_url( $url );
		if ( ! $check['ok'] ) {
			$out['error'] = ( 'dns_failed' === $check['reason'] ? 'network:' : 'unsafe_url:' ) . $check['reason'];
			return $out;
		}

		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$headers = (array) ( $args['headers'] ?? array() );
		$body    = null;

		if ( isset( $args['json'] ) ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $args['json'] );
		} elseif ( isset( $args['form'] ) ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = is_array( $args['form'] ) ? http_build_query( $args['form'] ) : (string) $args['form'];
		} elseif ( isset( $args['body'] ) ) {
			$body = $args['body'];
		}

		$request = array(
			'method'              => $method,
			'timeout'             => (int) ( $args['timeout'] ?? max( 20, (int) Settings::get( 'http_timeout' ) ) ),
			'redirection'         => 0,
			'user-agent'          => Http::user_agent(),
			'headers'             => $headers,
			'reject_unsafe_urls'  => false,
			'limit_response_size' => 2097152,
		);
		if ( null !== $body ) {
			$request['body'] = $body;
		}

		$response = wp_remote_request( $url, $request );

		if ( is_wp_error( $response ) ) {
			$out['error'] = 'network:' . Secrets::redact( $response->get_error_message() );
			return $out;
		}

		$out['code'] = (int) wp_remote_retrieve_response_code( $response );
		$out['body'] = Secrets::redact( substr( (string) wp_remote_retrieve_body( $response ), 0, 200000 ) );

		$decoded      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$out['json']  = is_array( $decoded ) ? $decoded : array();
		$out['ok']    = $out['code'] >= 200 && $out['code'] < 300;

		$out['retry_after'] = Http::parse_retry_after( self::header( $response, 'retry-after' ) );

		// Each platform names its rate limit headers differently.
		foreach ( array( 'x-rate-limit-remaining', 'x-app-usage', 'ratelimit-remaining', 'x-ratelimit-remaining' ) as $name ) {
			$value = self::header( $response, $name );
			if ( '' !== $value && is_numeric( $value ) ) {
				$out['rate_remaining'] = (int) $value;
				break;
			}
		}
		foreach ( array( 'x-rate-limit-reset', 'ratelimit-reset', 'x-ratelimit-reset' ) as $name ) {
			$value = self::header( $response, $name );
			if ( '' !== $value && is_numeric( $value ) ) {
				$reset = (int) $value;
				// Some platforms send a moment in time, others a number of seconds to wait.
				$out['rate_reset'] = $reset > time() - 86400 && $reset > 100000 ? max( 0, $reset - time() ) : $reset;
				break;
			}
		}

		// A redirect is reported, not followed, because these requests carry a credential.
		if ( $out['code'] >= 300 && $out['code'] < 400 ) {
			$out['ok']    = false;
			$out['error'] = 'unexpected_redirect';
		}

		$out['headers'] = array(
			'content-type' => self::header( $response, 'content-type' ),
			'location'     => Secrets::redact( self::header( $response, 'location' ) ),
			// LinkedIn returns a newly created post's reference here rather than in the body.
			'x-restli-id'  => self::header( $response, 'x-restli-id' ),
		);

		return $out;
	}

	/**
	 * Uploads raw bytes, for the platforms that take an image's contents rather than fetching it.
	 *
	 * @param string $platform    One of the six.
	 * @param string $url         Where to send it.
	 * @param string $bytes       The image itself.
	 * @param string $mime        Its media type.
	 * @param array  $args        method, headers, timeout, field, filename, allow_hosts.
	 */
	public static function upload( string $platform, string $url, string $bytes, string $mime, array $args = array() ): array {
		$field = (string) ( $args['field'] ?? '' );

		if ( '' === $field ) {
			// The whole body is the image, which is how LinkedIn and the AT Protocol take it.
			$headers                   = (array) ( $args['headers'] ?? array() );
			$headers['Content-Type']   = $mime;
			$headers['Content-Length'] = (string) strlen( $bytes );
			return self::request(
				$platform,
				$url,
				array(
					'method'      => (string) ( $args['method'] ?? 'POST' ),
					'headers'     => $headers,
					'body'        => $bytes,
					'timeout'     => (int) ( $args['timeout'] ?? 60 ),
					'allow_hosts' => $args['allow_hosts'] ?? null,
				)
			);
		}

		// A multipart form, which is how X takes it.
		$boundary = 'zzzc' . wp_generate_password( 24, false, false );
		$filename = (string) ( $args['filename'] ?? 'image' );
		$fields   = (array) ( $args['fields'] ?? array() );

		$payload = '';
		foreach ( $fields as $name => $value ) {
			$payload .= "--{$boundary}\r\n";
			$payload .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
			$payload .= (string) $value . "\r\n";
		}
		$payload .= "--{$boundary}\r\n";
		$payload .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . "\"\r\n";
		$payload .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$payload .= $bytes . "\r\n";
		$payload .= "--{$boundary}--\r\n";

		$headers                 = (array) ( $args['headers'] ?? array() );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		return self::request(
			$platform,
			$url,
			array(
				'method'      => 'POST',
				'headers'     => $headers,
				'body'        => $payload,
				'timeout'     => (int) ( $args['timeout'] ?? 60 ),
				'allow_hosts' => $args['allow_hosts'] ?? null,
			)
		);
	}

	/**
	 * Whether a host is one of the allowed ones, or a subdomain of one.
	 */
	private static function host_allowed( string $host, array $allowed ): bool {
		if ( '' === $host ) {
			return false;
		}
		foreach ( $allowed as $candidate ) {
			$candidate = strtolower( (string) $candidate );
			if ( $host === $candidate || substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate ) {
				return true;
			}
		}
		return false;
	}

	private static function header( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? (string) reset( $value ) : (string) $value;
	}

	/**
	 * Whether a failed request might succeed if it is tried again.
	 */
	public static function is_temporary( array $response ): bool {
		return 0 === strpos( (string) $response['error'], 'network' )
			|| 429 === (int) $response['code']
			|| (int) $response['code'] >= 500;
	}

	/**
	 * Whether a failure means the request never reached the platform, so retrying it cannot
	 * produce a duplicate. A connection that was refused or a name that would not resolve is
	 * safe to retry. A request that timed out is not: it may have arrived and been acted on.
	 */
	public static function definitely_not_sent( array $response ): bool {
		$error = (string) $response['error'];
		if ( 0 === strpos( $error, 'unsafe_url' ) ) {
			return true;
		}
		if ( 0 !== strpos( $error, 'network' ) ) {
			// The platform answered, so the request certainly arrived.
			return false;
		}
		$lower = strtolower( $error );
		foreach ( array( 'could not resolve', 'couldn\'t resolve', 'name or service not known', 'connection refused', 'could not connect', 'failed to connect', 'no route to host' ) as $phrase ) {
			if ( false !== strpos( $lower, $phrase ) ) {
				return true;
			}
		}
		// Anything else, a timeout above all, may have been received.
		return false;
	}

	/**
	 * A short, readable description of a failed request, safe to store and show.
	 */
	public static function describe( array $response ): string {
		if ( '' !== (string) $response['error'] ) {
			return (string) $response['error'];
		}
		$json = $response['json'];
		foreach ( array( array( 'error', 'message' ), array( 'error_description' ), array( 'message' ), array( 'detail' ), array( 'title' ), array( 'errors', 0, 'message' ) ) as $path ) {
			$value = $json;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return sprintf( 'HTTP %d. %s', (int) $response['code'], Secrets::redact( substr( $value, 0, 300 ) ) );
			}
		}
		return sprintf( 'HTTP %d. %s', (int) $response['code'], Secrets::redact( substr( (string) $response['body'], 0, 200 ) ) );
	}
}
