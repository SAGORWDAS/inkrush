<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Layered duplicate detection, in this order:
 *
 * 1. Canonical URL, exact match.
 * 2. Normalized URL (no scheme, www, tracking parameters, fragment or trailing slash).
 * 3. Publisher or feed story identifier.
 * 4. Normalized headline similarity (Dice coefficient on word tokens).
 * 5. Text similarity: 64-bit SimHash over three-word shingles, compared by Hamming distance.
 *    Only used when a text has at least 100 words, because short texts give unreliable hashes.
 *
 * Stories that failed (blocked, filtered, skipped) are not compared, so another publisher's
 * accessible copy of the same story can still be used.
 */
final class Dedupe {

	private const TRACKING = array( 'gclid', 'fbclid', 'ocid', 'cmpid', 'cmp', 'mc_cid', 'mc_eid', 'ref', 'ref_src', 'smid', 'smtyp', 'taid', 'guccounter', 'oc' );

	public static function normalize_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( empty( $parts['host'] ) ) {
			return strtolower( trim( $url ) );
		}
		$host = preg_replace( '/^(www|m|amp)\./', '', strtolower( $parts['host'] ) );
		$path = rtrim( $parts['path'] ?? '', '/' );
		$path = (string) preg_replace( '#/amp$#', '', $path );

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $values );
			foreach ( array_keys( $values ) as $key ) {
				if ( 0 === strpos( strtolower( (string) $key ), 'utm_' ) || in_array( strtolower( (string) $key ), self::TRACKING, true ) ) {
					unset( $values[ $key ] );
				}
			}
			ksort( $values );
			$query = $values ? '?' . http_build_query( $values ) : '';
		}

		return $host . $path . $query;
	}

	public static function normalize_headline( string $headline ): string {
		$text = html_entity_decode( $headline, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text );
		$text = (string) preg_replace( '/\s+[|\-–—]\s+[^|\-–—]{2,40}$/u', '', $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Dice similarity between word sets, from 0 to 1.
	 */
	public static function similarity( string $a, string $b ): float {
		$ta = array_unique( array_filter( explode( ' ', $a ), 'strlen' ) );
		$tb = array_unique( array_filter( explode( ' ', $b ), 'strlen' ) );
		if ( ! $ta || ! $tb ) {
			return 0.0;
		}
		$common = count( array_intersect( $ta, $tb ) );
		return ( 2 * $common ) / ( count( $ta ) + count( $tb ) );
	}

	/**
	 * Hex SimHash of a text. Empty when the text is too short to compare reliably.
	 */
	public static function fingerprint( string $text ): string {
		$words = preg_split( '/\s+/u', mb_strtolower( trim( (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text ) ) ) ) ?: array();
		$count = count( $words );
		if ( $count < 100 ) {
			return '';
		}
		$limit   = min( $count - 2, 3000 );
		$vectors = array_fill( 0, 64, 0 );
		for ( $i = 0; $i < $limit; $i++ ) {
			$hash = substr( md5( $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ] ), 0, 16 );
			for ( $nibble = 0; $nibble < 16; $nibble++ ) {
				$value = hexdec( $hash[ $nibble ] );
				for ( $bit = 0; $bit < 4; $bit++ ) {
					$vectors[ $nibble * 4 + $bit ] += ( $value >> $bit ) & 1 ? 1 : -1;
				}
			}
		}
		$out = '';
		for ( $nibble = 0; $nibble < 16; $nibble++ ) {
			$value = 0;
			for ( $bit = 0; $bit < 4; $bit++ ) {
				if ( $vectors[ $nibble * 4 + $bit ] > 0 ) {
					$value |= 1 << $bit;
				}
			}
			$out .= dechex( $value );
		}
		return $out;
	}

	/**
	 * Share of matching bits between two fingerprints, from 0 to 1.
	 */
	public static function fingerprint_similarity( string $a, string $b ): float {
		if ( 16 !== strlen( $a ) || 16 !== strlen( $b ) ) {
			return 0.0;
		}
		$distance = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$xor = hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] );
			while ( $xor ) {
				$distance += $xor & 1;
				$xor     >>= 1;
			}
		}
		return 1 - ( $distance / 64 );
	}

	/**
	 * Other live stories inside the comparison window, newest first.
	 */
	private static function live_rows( int $exclude_item_id ): array {
		global $wpdb;
		$items  = Db::table( 'source_items' );
		$jobs   = Db::table( 'article_jobs' );
		$since  = Clock::mysql( Clock::now() - (int) Settings::get( 'dedupe_window_days' ) * DAY_IN_SECONDS );
		$in     = Db::in_strings( Lifecycle::LIVE );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.story_id, i.headline_norm, i.url_hash, i.canonical_hash, i.content_fingerprint
				FROM {$items} i LEFT JOIN {$jobs} j ON j.source_item_id = i.id
				WHERE i.discovered_at >= %s AND i.id <> %d
					AND ( i.status = 'DISCOVERED' OR ( i.status = 'PROMOTED' AND j.status IN {$in} ) )
				ORDER BY i.id ASC LIMIT 2000", // phpcs:ignore WordPress.DB
				$since,
				$exclude_item_id
			),
			ARRAY_A
		);
	}

	/**
	 * Compares a story with all live stories.
	 *
	 * @return array{reason:string, duplicate_of:int, score:float}|null
	 */
	public static function check_candidate( array $item ): ?array {
		$threshold  = (float) Settings::get( 'headline_similarity' );
		$content_t  = (float) Settings::get( 'content_similarity' );
		$norm       = (string) ( $item['headline_norm'] ?? self::normalize_headline( (string) $item['headline'] ) );
		$url_hash   = (string) ( $item['url_hash'] ?? '' );
		$canon      = (string) ( $item['canonical_hash'] ?? '' );
		$story      = (string) ( $item['story_id'] ?? '' );
		$print      = (string) ( $item['content_fingerprint'] ?? '' );
		$best_title = null;

		foreach ( self::live_rows( (int) ( $item['id'] ?? 0 ) ) as $row ) {
			$other = (int) $row['id'];
			if ( '' !== $canon && ( $canon === $row['canonical_hash'] || $canon === $row['url_hash'] ) ) {
				return array( 'reason' => 'Same canonical URL', 'duplicate_of' => $other, 'score' => 1.0 );
			}
			if ( '' !== $url_hash && ( $url_hash === $row['url_hash'] || $url_hash === $row['canonical_hash'] ) ) {
				return array( 'reason' => 'Same normalized URL', 'duplicate_of' => $other, 'score' => 1.0 );
			}
			if ( '' !== $story && $story === $row['story_id'] ) {
				return array( 'reason' => 'Same publisher story identifier', 'duplicate_of' => $other, 'score' => 1.0 );
			}
			if ( '' !== $print && '' !== $row['content_fingerprint'] ) {
				$score = self::fingerprint_similarity( $print, $row['content_fingerprint'] );
				if ( $score >= $content_t ) {
					return array(
						'reason'       => sprintf( 'Similar article text (score %.2f, threshold %.2f)', $score, $content_t ),
						'duplicate_of' => $other,
						'score'        => $score,
					);
				}
			}
			$score = self::similarity( $norm, (string) $row['headline_norm'] );
			if ( $score >= $threshold && ( null === $best_title || $score > $best_title['score'] ) ) {
				$best_title = array(
					'reason'       => sprintf( 'Similar headline (score %.2f, threshold %.2f)', $score, $threshold ),
					'duplicate_of' => $other,
					'score'        => $score,
				);
			}
		}

		return $best_title;
	}

	public static function record_item_duplicate( array $item, array $duplicate ): void {
		Discovery::mark_item( (int) $item['id'], 'DUPLICATE', $duplicate['reason'], (int) $duplicate['duplicate_of'] );
		Events::log(
			'dedupe',
			'info',
			sprintf( 'Duplicate of story #%d. %s. Headline: %s', $duplicate['duplicate_of'], $duplicate['reason'], wp_strip_all_tags( (string) $item['headline'] ) ),
			array(
				'status'         => 'DUPLICATE',
				'source_item_id' => (int) $item['id'],
				'category_id'    => (int) $item['category_id'],
				'context'        => $duplicate,
			)
		);
	}

	/**
	 * Final check before a WordPress post is created: compares the source and generated title
	 * with articles already queued, drafted or published.
	 *
	 * @return array{reason:string, duplicate_of:int, score:float}|null
	 */
	public static function check_before_publish( array $job, array $item, array $content, bool $include_queued = false ): ?array {
		global $wpdb;
		$jobs_table    = Db::table( 'article_jobs' );
		$items_table   = Db::table( 'source_items' );
		$content_table = Db::table( 'article_content' );
		$threshold     = (float) Settings::get( 'headline_similarity' );
		$content_t     = (float) Settings::get( 'content_similarity' );
		$since         = Clock::mysql( Clock::now() - (int) Settings::get( 'dedupe_window_days' ) * DAY_IN_SECONDS );
		$statuses      = array( Lifecycle::DRAFT_CREATED, Lifecycle::PUBLISHED, Lifecycle::PUBLISHING );
		if ( $include_queued ) {
			$statuses[] = Lifecycle::QUEUED;
			$statuses[] = Lifecycle::SCHEDULED;
		}
		$in            = Db::in_strings( $statuses );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.id AS job_id, j.source_item_id, i.url_hash, i.canonical_hash, i.headline_norm, i.content_fingerprint, c.title
				FROM {$jobs_table} j
				JOIN {$items_table} i ON i.id = j.source_item_id
				LEFT JOIN {$content_table} c ON c.article_job_id = j.id
				WHERE j.status IN {$in} AND j.id <> %d AND j.updated_at >= %s
				ORDER BY j.id DESC LIMIT 1000", // phpcs:ignore WordPress.DB
				(int) $job['id'],
				$since
			),
			ARRAY_A
		);

		$title_norm = self::normalize_headline( (string) $content['title'] );

		foreach ( $rows as $row ) {
			$other = (int) $row['source_item_id'];
			if ( '' !== (string) $item['canonical_hash'] && $item['canonical_hash'] === $row['canonical_hash'] ) {
				return array( 'reason' => 'Same canonical URL as an existing post', 'duplicate_of' => $other, 'score' => 1.0 );
			}
			if ( '' !== (string) $item['url_hash'] && $item['url_hash'] === $row['url_hash'] ) {
				return array( 'reason' => 'Same URL as an existing post', 'duplicate_of' => $other, 'score' => 1.0 );
			}
			$score = self::similarity( (string) $item['headline_norm'], (string) $row['headline_norm'] );
			if ( $score >= $threshold ) {
				return array( 'reason' => sprintf( 'Source headline similar to an existing post (score %.2f)', $score ), 'duplicate_of' => $other, 'score' => $score );
			}
			$score = self::similarity( $title_norm, self::normalize_headline( (string) $row['title'] ) );
			if ( $score >= $threshold ) {
				return array( 'reason' => sprintf( 'Generated title similar to an existing post (score %.2f)', $score ), 'duplicate_of' => $other, 'score' => $score );
			}
			if ( '' !== (string) $item['content_fingerprint'] && '' !== (string) $row['content_fingerprint'] ) {
				$score = self::fingerprint_similarity( (string) $item['content_fingerprint'], (string) $row['content_fingerprint'] );
				if ( $score >= $content_t ) {
					return array( 'reason' => sprintf( 'Source text similar to an existing post (score %.2f)', $score ), 'duplicate_of' => $other, 'score' => $score );
				}
			}
		}

		return null;
	}
}
