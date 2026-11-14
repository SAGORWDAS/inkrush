<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Reads robots.txt following RFC 9309: most specific user-agent group, then longest matching rule.
 */
final class Robots {

	private const TOKEN = 'zzzcopydassobur';

	public const ALLOWED     = 'allowed';
	public const DISALLOWED  = 'disallowed';
	public const UNAVAILABLE = 'unavailable';

	/** How long an unreadable robots.txt is remembered before it is read again. */
	private const UNAVAILABLE_TTL = 15 * MINUTE_IN_SECONDS;

	/** How long a caller should wait after UNAVAILABLE, so the next try reads robots.txt again. */
	public const RETRY_SECONDS = self::UNAVAILABLE_TTL + MINUTE_IN_SECONDS;

	/**
	 * Whether the plugin may fetch a URL. An unreadable robots.txt counts as no.
	 */
	public static function allowed( string $url ): bool {
		return self::ALLOWED === self::status( $url );
	}

	/**
	 * What robots.txt says about a URL. UNAVAILABLE means it could not be read right now, so nothing
	 * may be fetched yet, but the publisher has not refused and it is worth asking again later.
	 */
	public static function status( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return self::DISALLOWED;
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path   = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$rules  = self::rules_for_origin( $origin );

		if ( 'all' === $rules ) {
			return self::ALLOWED;
		}
		if ( 'none' === $rules ) {
			return self::UNAVAILABLE;
		}
		return self::path_allowed( $rules, $path ) ? self::ALLOWED : self::DISALLOWED;
	}

	/**
	 * Sitemap addresses listed in robots.txt, if it could be read.
	 */
	public static function sitemaps( string $origin ): array {
		$cached = get_transient( self::cache_key( $origin ) );
		if ( ! is_array( $cached ) ) {
			self::rules_for_origin( $origin );
			$cached = get_transient( self::cache_key( $origin ) );
		}
		return is_array( $cached ) ? (array) ( $cached['sitemaps'] ?? array() ) : array();
	}

	/**
	 * @return array|string Rule list, or "all" or "none".
	 */
	private static function rules_for_origin( string $origin ) {
		$key    = self::cache_key( $origin );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['rules'];
		}

		$response = Http::get(
			$origin . '/robots.txt',
			array(
				'accept'    => 'text/plain,*/*;q=0.5',
				'max_bytes' => 512000,
				'timeout'   => 10,
			)
		);

		$sitemaps = array();
		$ttl      = DAY_IN_SECONDS;

		if ( $response['code'] >= 500 || '' !== $response['error'] ) {
			// Server problem or no answer: treat as disallowed for now and check again soon (RFC 9309).
			$rules = 'none';
			$ttl   = self::UNAVAILABLE_TTL;
		} elseif ( 429 === $response['code'] ) {
			$rules = 'none';
			$ttl   = self::UNAVAILABLE_TTL;
		} elseif ( $response['code'] >= 400 ) {
			// No robots.txt means no restrictions.
			$rules = 'all';
		} else {
			$parsed   = self::parse( $response['body'] );
			$rules    = $parsed['rules'];
			$sitemaps = $parsed['sitemaps'];
		}

		set_transient(
			$key,
			array(
				'rules'    => $rules,
				'sitemaps' => $sitemaps,
			),
			$ttl
		);

		return $rules;
	}

	private static function cache_key( string $origin ): string {
		return 'zzzc_robots_' . md5( strtolower( $origin ) );
	}

	/**
	 * Picks the group for this plugin's token, otherwise the "*" group.
	 *
	 * @return array{rules:array, sitemaps:string[]}
	 */
	public static function parse( string $text ): array {
		$groups   = array();
		$sitemaps = array();
		$agents   = array();
		$rules    = array();
		$in_rules = false;

		$flush = static function () use ( &$groups, &$agents, &$rules ) {
			foreach ( $agents as $agent ) {
				$groups[ $agent ] = array_merge( $groups[ $agent ] ?? array(), $rules );
			}
		};

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'sitemap' === $field ) {
				$sitemaps[] = $value;
			} elseif ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$flush();
					$agents   = array();
					$rules    = array();
					$in_rules = false;
				}
				$agents[] = strtolower( $value );
			} elseif ( 'allow' === $field || 'disallow' === $field ) {
				$in_rules = true;
				if ( '' !== $value ) {
					$rules[] = array(
						'allow' => 'allow' === $field,
						'path'  => $value,
					);
				}
			}
		}
		$flush();

		return array(
			'rules'    => $groups[ self::TOKEN ] ?? ( $groups['*'] ?? array() ),
			'sitemaps' => $sitemaps,
		);
	}

	public static function path_allowed( array $rules, string $path ): bool {
		$best_length = -1;
		$allowed     = true;
		foreach ( $rules as $rule ) {
			if ( self::matches( $rule['path'], $path ) ) {
				$length = strlen( $rule['path'] );
				if ( $length > $best_length || ( $length === $best_length && $rule['allow'] ) ) {
					$best_length = $length;
					$allowed     = $rule['allow'];
				}
			}
		}
		return $allowed;
	}

	private static function matches( string $pattern, string $path ): bool {
		$regex = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $pattern, '#' ) ) . '#';
		return 1 === preg_match( $regex, $path );
	}
}
