<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Outbound fetching with protection against requests to private networks (SSRF),
 * manual redirect checks, size limits and per-host pacing.
 */
final class Http {

	private const MAX_REDIRECTS = 5;
	private const MAX_BYTES     = 3145728;

	public static function user_agent(): string {
		return 'ZZZCopyDasSobur/' . ZZZC_VERSION . ' (+' . home_url( '/' ) . ')';
	}

	/**
	 * Hosts allowed despite resolving to private addresses. Empty in production;
	 * only code on the site can add one, using this filter.
	 */
	private static function allowed_hosts(): array {
		return (array) apply_filters( 'zzzc_http_allowed_hosts', array() );
	}

	/**
	 * Checks that a URL is safe to request.
	 *
	 * @return array{ok:bool, reason:string, host:string, port:int, ips:string[]}
	 */
	public static function check_url( string $url ): array {
		$fail = static function ( string $reason ): array {
			return array(
				'ok'     => false,
				'reason' => $reason,
				'host'   => '',
				'port'   => 0,
				'ips'    => array(),
			);
		};

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return $fail( 'invalid_url' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $fail( 'unsupported_scheme' );
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return $fail( 'credentials_in_url' );
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( in_array( $host . ':' . $port, self::allowed_hosts(), true ) ) {
			return array(
				'ok'     => true,
				'reason' => '',
				'host'   => $host,
				'port'   => $port,
				'ips'    => array(),
			);
		}

		if ( ! in_array( $port, array( 80, 443 ), true ) ) {
			return $fail( 'blocked_port' );
		}

		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return $fail( 'dns_failed' );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return $fail( 'private_address' );
			}
		}

		return array(
			'ok'     => true,
			'reason' => '',
			'host'   => $host,
			'port'   => $port,
			'ips'    => $ips,
		);
	}

	private static function resolve( string $host ): array {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	public static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		// IPv6 is decided on the address bytes, because the same address can be written many ways,
		// including with a dotted IPv4 part. The PHP flags do not catch all of these on every version.
		if ( 16 === strlen( $packed ) ) {
			$first  = ord( $packed[0] );
			$second = ord( $packed[1] );
			// ::/64 holds the forms that carry an IPv4 address, such as ::ffff:127.0.0.1.
			if ( str_repeat( "\0", 8 ) === substr( $packed, 0, 8 ) ) {
				return false;
			}
			// Unique local (fc00::/7) and link-local (fe80::/10).
			return 0xfc !== ( $first & 0xfe ) && ! ( 0xfe === $first && 0x80 === ( $second & 0xc0 ) );
		}
		// Ranges not covered by the PHP flags on every version.
		$blocked = array( '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24' );
		$long    = ip2long( $ip );
		foreach ( $blocked as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$mask               = -1 << ( 32 - (int) $bits );
			if ( ( $long & $mask ) === ( ip2long( $net ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Seconds until this host may be contacted again. Zero when clear.
	 */
	public static function host_wait( string $host, int $gap ): int {
		$key  = 'zzzc_host_' . md5( strtolower( $host ) );
		$next = (int) get_transient( $key );
		$real = time();
		if ( $next > $real ) {
			return $next - $real;
		}
		if ( $gap > 0 ) {
			set_transient( $key, $real + $gap, max( 60, $gap * 2 ) );
		}
		return 0;
	}

	/**
	 * Applies a cool-down after a rate limit response.
	 */
	public static function host_cooldown( string $host, int $seconds ): void {
		$seconds = max( 1, $seconds );
		set_transient( 'zzzc_host_' . md5( strtolower( $host ) ), time() + $seconds, $seconds + 60 );
	}

	/**
	 * GET with a safety check on every redirect hop.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional: timeout, accept, max_bytes.
	 * @return array{ok:bool, code:int, body:string, headers:array, final_url:string, error:string, retry_after:int}
	 */
	public static function get( string $url, array $args = array() ): array {
		$result = array(
			'ok'          => false,
			'code'        => 0,
			'body'        => '',
			'headers'     => array(),
			'final_url'   => $url,
			'error'       => '',
			'retry_after' => 0,
		);

		$current = $url;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$check = self::check_url( $current );
			if ( ! $check['ok'] ) {
				// A name that cannot be looked up right now is a network problem, so callers may try again.
				// Nothing is requested either way, so this does not weaken the safety check.
				$result['error'] = ( 'dns_failed' === $check['reason'] ? 'network:' : 'unsafe_url:' ) . $check['reason'];
				return $result;
			}

			// When a caller names the only hosts it trusts, every redirect hop must stay on them.
			if ( isset( $args['only_hosts'] ) ) {
				$hop_host = strtolower( (string) wp_parse_url( $current, PHP_URL_HOST ) );
				$hop_port = wp_parse_url( $current, PHP_URL_PORT );
				$hop_full = $hop_host . ( $hop_port ? ':' . $hop_port : '' );
				if ( ! in_array( $hop_host, (array) $args['only_hosts'], true ) && ! in_array( $hop_full, (array) $args['only_hosts'], true ) ) {
					$result['error'] = 'unsafe_url:host_not_allowed';
					return $result;
				}
			}

			$pin = null;
			if ( $check['ips'] ) {
				$pin = static function ( $handle ) use ( $check ) {
					// Connect only to the address that passed the check, so DNS cannot change between check and fetch.
					if ( defined( 'CURLOPT_RESOLVE' ) && ! filter_var( $check['host'], FILTER_VALIDATE_IP ) ) {
						$ip = $check['ips'][0];
						$ip = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
						curl_setopt( $handle, CURLOPT_RESOLVE, array( $check['host'] . ':' . $check['port'] . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
				};
				add_action( 'http_api_curl', $pin );
			}

			$request = array(
				'timeout'             => (int) ( $args['timeout'] ?? Settings::get( 'http_timeout' ) ),
				'redirection'         => 0,
				'user-agent'          => self::user_agent(),
				'limit_response_size' => (int) ( $args['max_bytes'] ?? self::MAX_BYTES ),
				'reject_unsafe_urls'  => false,
				'headers'             => array( 'Accept' => (string) ( $args['accept'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ) ),
			);
			if ( ! empty( $args['stream_to'] ) ) {
				$request['stream']   = true;
				$request['filename'] = (string) $args['stream_to'];
			}

			$response = wp_remote_get( $current, $request );

			if ( $pin ) {
				remove_action( 'http_api_curl', $pin );
			}

			if ( is_wp_error( $response ) ) {
				$result['error']     = 'network:' . $response->get_error_message();
				$result['final_url'] = $current;
				return $result;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( $code >= 300 && $code < 400 && '' !== $location ) {
				$next = self::absolute_url( $location, $current );
				if ( 0 === strpos( $current, 'https://' ) && 0 === strpos( $next, 'http://' ) ) {
					$result['code']      = $code;
					$result['error']     = 'unsafe_url:https_downgrade';
					$result['final_url'] = $current;
					return $result;
				}
				$current = $next;
				continue;
			}

			$result['code']        = $code;
			$result['body']        = (string) wp_remote_retrieve_body( $response );
			$content_type          = wp_remote_retrieve_header( $response, 'content-type' );
			$result['headers']     = array( 'content-type' => is_array( $content_type ) ? (string) reset( $content_type ) : (string) $content_type );
			$result['final_url']   = $current;
			$result['ok']          = $code >= 200 && $code < 300;
			$result['retry_after'] = self::parse_retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
			return $result;
		}

		$result['error'] = 'too_many_redirects';
		return $result;
	}

	/**
	 * Whether a result from get() may succeed if tried again later.
	 */
	public static function is_temporary( array $response ): bool {
		return 0 === strpos( (string) $response['error'], 'network' ) || 429 === (int) $response['code'] || (int) $response['code'] >= 500;
	}

	public static function parse_retry_after( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return min( 86400, (int) $value );
		}
		$ts = strtotime( $value );
		return $ts ? max( 0, min( 86400, $ts - time() ) ) : 0;
	}

	public static function absolute_url( string $location, string $base ): string {
		$location = trim( $location );
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$parts  = wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
		return $origin . $path . $location;
	}
}
