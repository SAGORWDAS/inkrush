<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Finds stories. Two source types, each behind the same fetch plan so either can be replaced:
 *
 * - google_rss: Google News RSS feeds for topics and searches. Google offers no official API. Feed
 *   links are Google addresses that do not redirect to the publisher, so PublisherResolver finds
 *   the original article. The /rss/ path is not on Google's robots.txt allow list; the site owner
 *   chose to use it anyway, can turn it off, and the settings page says so.
 * - publisher_feed: RSS or Atom feeds from publishers. Robots rules are checked before each fetch.
 */
final class Discovery {

	public const GOOGLE = 'google_rss';
	public const FEED   = 'publisher_feed';

	public static function has_usable_source( array $category ): bool {
		return (bool) self::fetch_plan( $category );
	}

	/**
	 * The fetches needed for a category, with the filter each one really applies.
	 *
	 * @return array[] Each: adapter, url, context, filter.
	 */
	public static function fetch_plan( array $category ): array {
		$plan = array();
		$mode = (string) $category['source_mode'];

		if ( 'feeds' !== $mode && Settings::get( 'google_rss_enabled' ) ) {
			foreach ( self::lines( (string) $category['topic_urls'] ) as $topic_url ) {
				$rss = self::topic_to_rss( $topic_url, $category );
				if ( $rss ) {
					$plan[] = array(
						'adapter' => self::GOOGLE,
						'url'     => $rss,
						'context' => 'Google News topic',
						'filter'  => 'Recency checked here against each story date (Google topic feeds have no date filter)',
					);
				}
			}
			foreach ( self::lines( (string) $category['search_queries'] ) as $query ) {
				$built  = self::search_url( $query, $category );
				$plan[] = array(
					'adapter' => self::GOOGLE,
					'url'     => $built['url'],
					'context' => 'Google News search: ' . $query,
					'filter'  => $built['filter'],
				);
			}
		}

		if ( 'google' !== $mode ) {
			foreach ( self::lines( (string) $category['feed_urls'] ) as $feed_url ) {
				$plan[] = array(
					'adapter' => self::FEED,
					'url'     => $feed_url,
					'context' => 'Publisher feed',
					'filter'  => 'Recency checked here against each story date (publisher feeds have no date filter)',
				);
			}
		}

		return $plan;
	}

	private static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ?: array() ), 'strlen' ) );
	}

	/**
	 * Turns a Google News topic page address into its RSS address. Returns null if it is not a topic address.
	 */
	public static function topic_to_rss( string $topic_url, array $category ): ?string {
		$parts = wp_parse_url( $topic_url );
		if ( empty( $parts['path'] ) || ! preg_match( '#/topics/([A-Za-z0-9_\-]+)#', $parts['path'], $match ) ) {
			return null;
		}
		return 'https://news.google.com/rss/topics/' . $match[1] . '?' . self::locale_query( $category );
	}

	private static function locale_query( array $category ): string {
		$language = (string) ( $category['language'] ?: 'en-US' );
		$region   = (string) ( $category['region'] ?: 'US' );
		$short    = strtolower( explode( '-', $language )[0] );
		return 'hl=' . rawurlencode( $language ) . '&gl=' . rawurlencode( $region ) . '&ceid=' . rawurlencode( $region ) . ':' . rawurlencode( $short );
	}

	/**
	 * Builds a Google News search feed address with date operators.
	 *
	 * @return array{url:string, filter:string}
	 */
	public static function search_url( string $query, array $category ): array {
		$operator = '';
		$filter   = '';
		switch ( PastHour::enabled() ? 'past_hour' : $category['recency'] ) {
			case 'past_hour':
				// Whole hours, rounded up, so no story inside the age limit is left out.
				$hours    = (int) ceil( PastHour::max_age_minutes() / 60 );
				$operator = ' when:' . $hours . 'h';
				$filter   = 'Google operator when:' . $hours . 'h, then checked here against each story date';
				break;
			case '1h':
				$operator = ' when:1h';
				$filter   = 'Google operator when:1h, then checked here against each story date';
				break;
			case '24h':
				$operator = ' when:1d';
				$filter   = 'Google operator when:1d, then checked here against each story date';
				break;
			case '7d':
				$operator = ' when:7d';
				$filter   = 'Google operator when:7d, then checked here against each story date';
				break;
			case 'custom':
				$from = Clock::to_timestamp( $category['custom_from'] );
				$to   = Clock::to_timestamp( $category['custom_to'] );
				if ( $from && $to ) {
					// These operators work by whole day, so the exact range is enforced here too.
					$operator = ' after:' . gmdate( 'Y-m-d', $from - DAY_IN_SECONDS ) . ' before:' . gmdate( 'Y-m-d', $to + DAY_IN_SECONDS );
				}
				$filter = 'Google after: and before: operators (whole days only), then the exact range checked here';
				break;
		}
		return array(
			'url'    => 'https://news.google.com/rss/search?q=' . rawurlencode( $query . $operator ) . '&' . self::locale_query( $category ),
			'filter' => $filter,
		);
	}

	public static function is_google_news_link( string $url ): bool {
		return 'news.google.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Job handler: fetches one feed and stores new candidates.
	 */
	public static function handle( array $job ): JobResult {
		$payload  = $job['payload'];
		$category = Categories::get( (int) $payload['category_id'] );
		if ( ! $category || ! empty( $category['deleted_at'] ) || ! (int) $category['enabled'] ) {
			return JobResult::done();
		}

		// A search for one post, which has since found a story or passed its time.
		if ( ! empty( $payload['slot_id'] ) && ! PastHour::search_needed( (int) $payload['slot_id'] ) ) {
			return JobResult::done();
		}

		$adapter = (string) $payload['adapter'];
		if ( self::GOOGLE === $adapter && ! Settings::get( 'google_rss_enabled' ) ) {
			return JobResult::done();
		}

		$fetched = self::fetch( $adapter, (string) $payload['url'] );

		if ( 'wait' === $fetched['status'] ) {
			return JobResult::defer( $fetched['delay'], 'Waiting for the host pacing limit.' );
		}

		if ( 'ok' !== $fetched['status'] ) {
			$message = sprintf( 'Discovery failed for %s: %s', $category['name'], $fetched['message'] );
			if ( $fetched['retry'] ) {
				return JobResult::retry( max( $fetched['delay'], Jobs::backoff( (int) $job['attempts'] ) ), $fetched['code'], $message );
			}
			Events::log(
				'discovery',
				'warning',
				$message,
				array(
					'error_code'  => $fetched['code'],
					'category_id' => (int) $category['id'],
				)
			);
			return JobResult::done();
		}

		$counts = self::ingest( $category, $fetched['items'], $adapter, (string) $payload['context'], (string) $payload['filter'] );

		Events::log(
			'discovery',
			'info',
			sprintf(
				'%s: %s returned %d stories. New %d, filtered %d, duplicates %d, seen before %d. Effective filter: %s',
				$category['name'],
				$payload['context'],
				count( $fetched['items'] ),
				$counts['new'],
				$counts['filtered'],
				$counts['duplicate'],
				$counts['seen'],
				$payload['filter']
			),
			array(
				'category_id' => (int) $category['id'],
				'status'      => 'DISCOVERED',
			)
		);

		return JobResult::done();
	}

	/**
	 * Fetches and parses a feed without storing anything. Also used by "Test search".
	 *
	 * @return array{status:string, items:array, code:string, message:string, retry:bool, delay:int}
	 */
	public static function fetch( string $adapter, string $url ): array {
		$out = array(
			'status'  => 'error',
			'items'   => array(),
			'code'    => '',
			'message' => '',
			'retry'   => false,
			'delay'   => 0,
		);

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$gap  = self::GOOGLE === $adapter ? (int) Settings::get( 'google_gap_seconds' ) : (int) Settings::get( 'host_gap_seconds' );
		$wait = Http::host_wait( $host, $gap );
		if ( $wait > 0 ) {
			$out['status'] = 'wait';
			$out['delay']  = $wait;
			return $out;
		}

		// Robots rules apply to every publisher feed. The Google News RSS exception is documented above.
		if ( self::FEED === $adapter ) {
			$robots = Robots::status( $url );
			if ( Robots::UNAVAILABLE === $robots ) {
				$out['code']    = 'robots_unavailable';
				$out['message'] = 'The publisher robots.txt could not be read right now, so this feed was not fetched.';
				$out['retry']   = true;
				$out['delay']   = Robots::RETRY_SECONDS;
				return $out;
			}
			if ( Robots::ALLOWED !== $robots ) {
				$out['code']    = 'robots_disallowed';
				$out['message'] = 'The publisher robots.txt does not allow fetching this feed.';
				return $out;
			}
		}

		$response = Http::get( $url, array( 'accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.5' ) );

		if ( '' !== $response['error'] ) {
			$out['code']    = 0 === strpos( $response['error'], 'unsafe_url' ) ? 'unsafe_url' : 'network_error';
			$out['message'] = $response['error'];
			$out['retry']   = 'network_error' === $out['code'];
			return $out;
		}

		if ( 429 === $response['code'] ) {
			$delay = max( 60, $response['retry_after'] );
			Http::host_cooldown( $host, $delay );
			$out['code']    = 'rate_limited';
			$out['message'] = 'The source returned HTTP 429 (rate limited).';
			$out['retry']   = true;
			$out['delay']   = $delay;
			return $out;
		}

		if ( ! $response['ok'] ) {
			$out['code']    = 'http_' . $response['code'];
			$out['message'] = sprintf( 'The source returned HTTP %d.', $response['code'] );
			$out['retry']   = $response['code'] >= 500;
			return $out;
		}

		$parsed = FeedParser::parse( $response['body'] );
		if ( ! $parsed['ok'] || ! in_array( $parsed['kind'], array( 'rss', 'atom' ), true ) ) {
			$out['code']    = 'not_a_feed';
			$out['message'] = 'The response was not a readable RSS or Atom feed.';
			return $out;
		}

		$out['status'] = 'ok';
		$out['items']  = $parsed['items'];
		return $out;
	}

	/**
	 * Applies filters and stores candidates. A story seen before is ignored.
	 *
	 * @return array{new:int, filtered:int, duplicate:int, seen:int}
	 */
	public static function ingest( array $category, array $items, string $adapter, string $context, string $filter ): array {
		global $wpdb;
		$table  = Db::table( 'source_items' );
		$counts = array(
			'new'       => 0,
			'filtered'  => 0,
			'duplicate' => 0,
			'seen'      => 0,
		);

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['published'] <=> $a['published'];
			}
		);
		$items = array_slice( $items, 0, (int) Settings::get( 'max_items_per_fetch' ) );

		foreach ( $items as $entry ) {
			$normal = self::normalize_entry( $entry, $adapter );
			if ( '' === $normal['headline'] || '' === $normal['link'] ) {
				continue;
			}

			$dedupe_key = sha1( '' !== $normal['story_id'] ? $normal['story_id'] : Dedupe::normalize_url( $normal['link'] ) );
			$exists     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dedupe_key = %s", $dedupe_key ) ); // phpcs:ignore WordPress.DB
			if ( $exists ) {
				$counts['seen']++;
				continue;
			}

			$record = array(
				'category_id'      => (int) $category['id'],
				'adapter'          => $adapter,
				'dedupe_key'       => $dedupe_key,
				'story_id'         => substr( $normal['story_id'], 0, 191 ),
				'headline'         => $normal['headline'],
				'headline_norm'    => substr( Dedupe::normalize_headline( $normal['headline'] ), 0, 255 ),
				'publisher'        => substr( $normal['publisher'], 0, 191 ),
				'publisher_url'    => substr( $normal['publisher_url'], 0, 512 ),
				'source_url'       => $normal['link'],
				'resolved_url'     => self::is_google_news_link( $normal['link'] ) ? null : $normal['link'],
				'url_hash'         => self::is_google_news_link( $normal['link'] ) ? '' : sha1( Dedupe::normalize_url( $normal['link'] ) ),
				'published_at'     => $normal['published'] ? gmdate( 'Y-m-d H:i:s', $normal['published'] ) : null,
				'snippet'          => $normal['snippet'],
				'discovered_at'    => Clock::mysql(),
				'search_context'   => $context,
				'effective_filter' => substr( $filter, 0, 255 ),
				'source_author'    => substr( $normal['author'], 0, 191 ),
				'status'           => 'DISCOVERED',
			);

			$reject = self::filter_reason( $category, $normal );
			if ( $reject ) {
				$record['status']        = 'FILTERED_OUT';
				$record['status_reason'] = substr( $reject, 0, 255 );
				$counts['filtered']++;
				$wpdb->insert( $table, $record );
				continue;
			}

			$wpdb->insert( $table, $record );
			$item_id = (int) $wpdb->insert_id;
			if ( ! $item_id ) {
				continue;
			}
			$record['id'] = $item_id;

			$duplicate = Dedupe::check_candidate( $record );
			if ( $duplicate ) {
				Dedupe::record_item_duplicate( $record, $duplicate );
				$counts['duplicate']++;
				continue;
			}

			$counts['new']++;
		}

		return $counts;
	}

	/**
	 * Normalizes a feed entry, including Google's "Headline - Publisher" title format.
	 */
	public static function normalize_entry( array $entry, string $adapter ): array {
		$headline  = (string) $entry['title'];
		$publisher = (string) $entry['source_name'];
		$link      = (string) $entry['link'];

		if ( self::GOOGLE === $adapter ) {
			if ( '' !== $publisher ) {
				$suffix = ' - ' . $publisher;
				if ( substr( $headline, -strlen( $suffix ) ) === $suffix ) {
					$headline = substr( $headline, 0, -strlen( $suffix ) );
				}
			}
		} elseif ( '' === $publisher ) {
			$publisher = preg_replace( '/^www\./', '', (string) wp_parse_url( $link, PHP_URL_HOST ) );
		}

		$publisher_url = (string) $entry['source_url'];
		if ( '' === $publisher_url && ! self::is_google_news_link( $link ) ) {
			$parts         = wp_parse_url( $link );
			$publisher_url = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		}

		return array(
			'headline'      => trim( $headline ),
			'publisher'     => $publisher,
			'publisher_url' => $publisher_url,
			'link'          => $link,
			'story_id'      => (string) $entry['guid'],
			'published'     => (int) $entry['published'],
			'date_reliable' => (bool) ( $entry['date_reliable'] ?? true ),
			'snippet'       => (string) $entry['snippet'],
			'author'        => (string) ( $entry['author'] ?? '' ),
		);
	}

	/**
	 * Why a story fails the category filters. Null when it passes.
	 */
	public static function filter_reason( array $category, array $normal ): ?string {
		$haystack = mb_strtolower( $normal['headline'] . ' ' . $normal['snippet'] );

		foreach ( self::lines( (string) $category['excluded_keywords'] ) as $word ) {
			if ( false !== mb_strpos( $haystack, mb_strtolower( $word ) ) ) {
				return 'Contains excluded keyword: ' . $word;
			}
		}

		$required = self::lines( (string) $category['required_keywords'] );
		if ( $required ) {
			$found = false;
			foreach ( $required as $word ) {
				if ( false !== mb_strpos( $haystack, mb_strtolower( $word ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return 'Does not contain a required keyword.';
			}
		}

		if ( PastHour::enabled() && empty( $normal['date_reliable'] ) && ! empty( $normal['published'] ) ) {
			return 'The feed gives only an updated time, not a publication time, so the date is not reliable.';
		}

		$check = self::recency_check( $category, (int) $normal['published'] );
		return $check['ok'] ? null : $check['reason'];
	}

	/**
	 * Checks a story date against the category recency filter.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function recency_check( array $category, int $published ): array {
		if ( ! $published ) {
			return array(
				'ok'     => false,
				'reason' => 'The source gave no publication time, so the recency filter cannot be confirmed.',
			);
		}

		if ( PastHour::enabled() ) {
			return PastHour::recency_check( $published );
		}

		$now = Clock::now();
		switch ( $category['recency'] ) {
			case '1h':
				$from = $now - HOUR_IN_SECONDS;
				$to   = $now + HOUR_IN_SECONDS;
				break;
			case '7d':
				$from = $now - WEEK_IN_SECONDS;
				$to   = $now + HOUR_IN_SECONDS;
				break;
			case 'custom':
				$from = Clock::to_timestamp( $category['custom_from'] );
				$to   = Clock::to_timestamp( $category['custom_to'] );
				break;
			case '24h':
			default:
				$from = $now - DAY_IN_SECONDS;
				$to   = $now + HOUR_IN_SECONDS;
		}

		if ( $published < $from || $published > $to ) {
			return array(
				'ok'     => false,
				'reason' => sprintf( 'Published %s UTC, outside the %s filter.', gmdate( 'Y-m-d H:i', $published ), $category['recency'] ),
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	public static function mark_item( int $item_id, string $status, string $reason, int $duplicate_of = 0 ): void {
		global $wpdb;
		$wpdb->update(
			Db::table( 'source_items' ),
			array(
				'status'        => $status,
				'status_reason' => substr( $reason, 0, 255 ),
				'duplicate_of'  => $duplicate_of,
			),
			array( 'id' => $item_id )
		);
	}
}
