<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a publisher's own article address for a story known only by headline and publisher.
 *
 * Google News RSS links do not redirect to the publisher, and decoding them needs an undocumented
 * internal Google call, which this plugin does not use. Instead it looks for the same headline in
 * the publisher's own feeds and news sitemaps. Not every story can be matched, and an unmatched
 * story is recorded as NOT_FOUND rather than guessed.
 */
final class PublisherResolver {

	private const COMMON_PATHS = array( '/feed', '/rss', '/rss.xml', '/feed.xml', '/atom.xml' );

	/** Set when a request failed in a way that may clear, so a miss is not treated as final or remembered. */
	private static bool $trouble = false;

	/**
	 * @return array{url:string, method:string, score:float, reason:string, temporary:bool}
	 */
	public static function resolve( string $headline, string $publisher_url ): array {
		self::$trouble = false;

		$parts = wp_parse_url( $publisher_url );
		if ( empty( $parts['host'] ) ) {
			return self::miss( 'The source gave no publisher address.' );
		}
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		$sources = self::feed_addresses( $origin );
		if ( ! $sources ) {
			return self::miss( 'The publisher has no feed or news sitemap this plugin can read.' );
		}

		$threshold = (float) Settings::get( 'resolver_similarity' );
		$wanted    = Dedupe::normalize_headline( $headline );
		$best      = null;

		foreach ( $sources as $source ) {
			foreach ( self::entries( $source ) as $entry ) {
				$title = '' !== $entry['title'] ? $entry['title'] : self::title_from_slug( $entry['link'] );
				$score = Dedupe::similarity( $wanted, Dedupe::normalize_headline( $title ) );
				if ( $score >= $threshold && ( null === $best || $score > $best['score'] ) ) {
					$best = array(
						'url'       => $entry['link'],
						'method'    => 'publisher_feed_match',
						'score'     => $score,
						'reason'    => '',
						'temporary' => false,
					);
				}
			}
			if ( $best && $best['score'] >= 0.95 ) {
				break;
			}
		}

		return $best ?: self::miss( sprintf( 'No headline in the publisher feeds matched at the %.2f threshold.', $threshold ) );
	}

	private static function miss( string $reason ): array {
		return array(
			'url'       => '',
			'method'    => '',
			'score'     => 0.0,
			'reason'    => $reason,
			'temporary' => self::$trouble,
		);
	}

	/**
	 * Robots check that notes when robots.txt could not be read right now.
	 */
	private static function may_fetch( string $url ): bool {
		$status = Robots::status( $url );
		if ( Robots::UNAVAILABLE === $status ) {
			self::$trouble = true;
		}
		return Robots::ALLOWED === $status;
	}

	/**
	 * A request that notes when it failed in a way that may clear.
	 */
	private static function fetch( string $url, array $args = array() ): array {
		$response = Http::get( $url, $args );
		if ( Http::is_temporary( $response ) ) {
			self::$trouble = true;
		}
		return $response;
	}

	/**
	 * Words from the last part of an article address, used when a sitemap gives no title.
	 */
	private static function title_from_slug( string $url ): string {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$slug = (string) preg_replace( '/\.[a-z]{2,5}$/i', '', basename( $path ) );
		return str_replace( array( '-', '_' ), ' ', $slug );
	}

	/**
	 * Feed and sitemap addresses for a publisher, cached for a day.
	 */
	public static function feed_addresses( string $origin ): array {
		$key    = 'zzzc_feeds_' . md5( strtolower( $origin ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();

		foreach ( Robots::sitemaps( $origin ) as $sitemap ) {
			if ( preg_match( '/news|latest|recent|rss|feed/i', $sitemap ) ) {
				$found[] = $sitemap;
			}
		}

		if ( self::may_fetch( $origin . '/' ) ) {
			$home = self::fetch( $origin . '/' );
			if ( $home['ok'] ) {
				foreach ( self::feeds_in_html( $home['body'], $origin ) as $feed ) {
					$found[] = $feed;
				}
			}
		}

		if ( ! $found && ! self::$trouble ) {
			foreach ( self::COMMON_PATHS as $path ) {
				$url = $origin . $path;
				if ( ! self::may_fetch( $url ) ) {
					continue;
				}
				$probe = self::fetch( $url, array( 'timeout' => 10 ) );
				if ( $probe['ok'] ) {
					$parsed = FeedParser::parse( $probe['body'] );
					if ( $parsed['ok'] && in_array( $parsed['kind'], array( 'rss', 'atom' ), true ) ) {
						$found[] = $url;
						break;
					}
				}
			}
		}

		$found = array_slice( array_values( array_unique( $found ) ), 0, 5 );
		// After a failure the list may be incomplete, so it is not kept for a whole day.
		if ( ! self::$trouble ) {
			set_transient( $key, $found, DAY_IN_SECONDS );
		}
		return $found;
	}

	public static function feeds_in_html( string $html, string $origin ): array {
		$feeds = array();
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( ! preg_match( '/(rss|atom)\+xml/i', $tag ) ) {
					continue;
				}
				if ( preg_match( '/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $href ) ) {
					$feeds[] = Http::absolute_url( html_entity_decode( $href[1], ENT_QUOTES ), $origin . '/' );
				}
			}
		}
		return $feeds;
	}

	/**
	 * Entries of a feed or sitemap, cached for 30 minutes.
	 */
	private static function entries( string $url ): array {
		$key    = 'zzzc_entries_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$entries = array();
		if ( self::may_fetch( $url ) ) {
			$response = self::fetch( $url, array( 'accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.5' ) );
			if ( $response['ok'] ) {
				$parsed = FeedParser::parse( $response['body'] );
				if ( 'sitemapindex' === $parsed['kind'] ) {
					$children = array_values(
						array_filter(
							$parsed['children'],
							static function ( $child ) {
								return (bool) preg_match( '/news|latest|recent/i', $child );
							}
						)
					);
					foreach ( array_slice( $children, 0, 2 ) as $child ) {
						$entries = array_merge( $entries, self::entries( $child ) );
					}
				} else {
					foreach ( array_slice( $parsed['items'], 0, 1000 ) as $item ) {
						if ( '' !== $item['link'] ) {
							$entries[] = array(
								'title' => (string) $item['title'],
								'link'  => (string) $item['link'],
							);
						}
					}
				}
			}
		}

		if ( ! self::$trouble ) {
			set_transient( $key, $entries, 30 * MINUTE_IN_SECONDS );
		}
		return $entries;
	}
}
