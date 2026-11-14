<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the original article, classifies the result and checks for duplicates by text.
 *
 * A story without enough accessible text never reaches the AI step. Nothing is invented to fill a gap.
 */
final class Retrieval {

	public const SUCCESS      = 'SUCCESS';
	public const PARTIAL      = 'PARTIAL';
	public const BLOCKED      = 'BLOCKED';
	public const PAYWALLED    = 'PAYWALLED';
	public const NOT_FOUND    = 'NOT_FOUND';
	public const RATE_LIMITED = 'RATE_LIMITED';
	public const ERROR        = 'ERROR';

	public static function handle( array $job ): JobResult {
		$article_job_id = (int) $job['payload']['article_job_id'];
		$article        = Lifecycle::job( $article_job_id );
		if ( ! $article || Lifecycle::RETRIEVAL_PENDING !== $article['status'] || PastHour::stop_if_unwanted( $article ) ) {
			return JobResult::done();
		}

		$item = Lifecycle::item( (int) $article['source_item_id'] );
		if ( ! $item ) {
			return JobResult::fail( 'missing_source', 'The source record no longer exists.' );
		}

		$url = (string) $item['resolved_url'];

		$pace_host = (string) wp_parse_url( '' !== $url ? $url : (string) $item['publisher_url'], PHP_URL_HOST );
		if ( '' !== $pace_host ) {
			$wait = Http::host_wait( $pace_host, (int) Settings::get( 'host_gap_seconds' ) );
			if ( $wait > 0 ) {
				return JobResult::defer( $wait, 'Waiting for the host pacing limit.' );
			}
		}

		if ( '' === $url ) {
			$resolved = PublisherResolver::resolve( (string) $item['headline'], (string) $item['publisher_url'] );
			if ( '' === $resolved['url'] ) {
				if ( ! empty( $resolved['temporary'] ) ) {
					return JobResult::retry( Robots::RETRY_SECONDS, 'resolver_unavailable', 'The publisher could not be reached to look up this story, so it will be tried again.' );
				}
				self::finish( $article, $item, self::NOT_FOUND, Lifecycle::BLOCKED, 'publisher_url_unresolved', $resolved['reason'] );
				return JobResult::done();
			}
			$url = $resolved['url'];
			self::update_item(
				(int) $item['id'],
				array(
					'resolved_url'   => $url,
					'resolve_method' => $resolved['method'],
					'url_hash'       => sha1( Dedupe::normalize_url( $url ) ),
				)
			);
			$item['resolved_url'] = $url;
			$item['url_hash']     = sha1( Dedupe::normalize_url( $url ) );

			$duplicate = Dedupe::check_candidate( $item );
			if ( $duplicate ) {
				self::mark_duplicate( $article, $item, $duplicate );
				return JobResult::done();
			}
		}

		$robots = Robots::status( $url );
		if ( Robots::UNAVAILABLE === $robots ) {
			return JobResult::retry( Robots::RETRY_SECONDS, 'robots_unavailable', 'The publisher robots.txt could not be read right now, so the page was not fetched. It will be tried again.' );
		}
		if ( Robots::ALLOWED !== $robots ) {
			self::finish( $article, $item, self::BLOCKED, Lifecycle::BLOCKED, 'robots_disallowed', 'The publisher robots.txt does not allow fetching this page.' );
			return JobResult::done();
		}

		$response = Http::get( $url );

		if ( '' !== $response['error'] ) {
			if ( 0 === strpos( $response['error'], 'unsafe_url' ) ) {
				self::finish( $article, $item, self::BLOCKED, Lifecycle::BLOCKED, 'unsafe_url', 'The address was refused by the safety check: ' . $response['error'] );
				return JobResult::done();
			}
			return JobResult::retry( Jobs::backoff( (int) $job['attempts'] ), 'network_error', $response['error'] );
		}

		$code = (int) $response['code'];

		if ( 429 === $code ) {
			$delay = max( 60, $response['retry_after'] );
			Http::host_cooldown( (string) wp_parse_url( $url, PHP_URL_HOST ), $delay );
			self::update_item( (int) $item['id'], array( 'retrieval_status' => self::RATE_LIMITED ) );
			return JobResult::retry( $delay, 'rate_limited', 'The publisher returned HTTP 429.' );
		}
		if ( $code >= 500 ) {
			return JobResult::retry( Jobs::backoff( (int) $job['attempts'] ), 'http_' . $code, sprintf( 'The publisher returned HTTP %d.', $code ) );
		}
		if ( 402 === $code ) {
			self::finish( $article, $item, self::PAYWALLED, Lifecycle::BLOCKED, 'paywalled', 'The publisher returned HTTP 402 (payment required).' );
			return JobResult::done();
		}
		if ( 401 === $code || 403 === $code || 451 === $code ) {
			self::finish( $article, $item, self::BLOCKED, Lifecycle::BLOCKED, 'http_' . $code, sprintf( 'The publisher refused access with HTTP %d.', $code ) );
			return JobResult::done();
		}
		if ( 404 === $code || 410 === $code ) {
			self::finish( $article, $item, self::NOT_FOUND, Lifecycle::BLOCKED, 'http_' . $code, sprintf( 'The page was not found (HTTP %d).', $code ) );
			return JobResult::done();
		}
		if ( ! $response['ok'] ) {
			self::finish( $article, $item, self::ERROR, Lifecycle::RETRIEVAL_FAILED, 'http_' . $code, sprintf( 'Unexpected HTTP %d from the publisher.', $code ) );
			return JobResult::done();
		}

		$type = strtolower( (string) ( $response['headers']['content-type'] ?? '' ) );
		if ( '' !== $type && false === strpos( $type, 'html' ) && false === strpos( $type, 'xml' ) ) {
			self::finish( $article, $item, self::ERROR, Lifecycle::RETRIEVAL_FAILED, 'not_html', 'The address did not return a web page.' );
			return JobResult::done();
		}

		$extracted = Extractor::extract( $response['body'], $response['final_url'] );

		$canonical = '' !== $extracted['canonical'] ? $extracted['canonical'] : $response['final_url'];
		$fields    = array(
			'canonical_url'  => $canonical,
			'canonical_hash' => sha1( Dedupe::normalize_url( $canonical ) ),
			'retrieved_at'   => Clock::mysql(),
		);
		if ( '' !== $extracted['author'] && '' === (string) $item['source_author'] ) {
			$fields['source_author'] = substr( $extracted['author'], 0, 191 );
		}
		if ( $extracted['published'] && ! $item['published_at'] ) {
			$fields['published_at'] = gmdate( 'Y-m-d H:i:s', $extracted['published'] );
		}
		if ( PastHour::applies( $article ) ) {
			$older = PastHour::older_page_date( $item, (int) $extracted['published_stated'] );
			if ( $older ) {
				$fields['published_at'] = gmdate( 'Y-m-d H:i:s', $older );
			}
		}
		self::update_item( (int) $item['id'], $fields );
		$item = array_merge( $item, $fields );

		if ( PastHour::stop_if_unwanted( $article ) ) {
			return JobResult::done();
		}

		$min_words     = (int) Settings::get( 'min_source_words' );
		$partial_words = (int) Settings::get( 'partial_min_words' );
		$words         = $extracted['word_count'];

		if ( $words < $min_words && $extracted['paywall'] ) {
			self::finish( $article, $item, self::PAYWALLED, Lifecycle::BLOCKED, 'paywalled', sprintf( 'The page is behind a paywall. Only %d words were accessible.', $words ) );
			return JobResult::done();
		}
		if ( $words < $partial_words ) {
			self::finish( $article, $item, self::ERROR, Lifecycle::RETRIEVAL_FAILED, 'no_article_text', sprintf( 'No usable article text was found (%d words).', $words ) );
			return JobResult::done();
		}
		if ( $words < $min_words ) {
			self::update_item( (int) $item['id'], array( 'source_word_count' => $words ) );
			self::finish( $article, $item, self::PARTIAL, Lifecycle::PARTIAL, 'insufficient_source', sprintf( 'Only %1$d words were accessible. The minimum is %2$d, so the story is held and no article is written.', $words, $min_words ) );
			return JobResult::done();
		}

		$text        = mb_substr( $extracted['text'], 0, (int) Settings::get( 'max_source_chars' ) );
		$fingerprint = Dedupe::fingerprint( $text );

		self::update_item(
			(int) $item['id'],
			array(
				'source_text'         => $text,
				'source_word_count'   => $words,
				'content_fingerprint' => $fingerprint,
				'retrieval_status'    => self::SUCCESS,
			)
		);
		$item['content_fingerprint'] = $fingerprint;

		$duplicate = Dedupe::check_candidate( $item );
		if ( $duplicate ) {
			self::mark_duplicate( $article, $item, $duplicate );
			return JobResult::done();
		}

		Lifecycle::transition( $article_job_id, Lifecycle::RETRIEVED );
		Events::log(
			'retrieval',
			'info',
			sprintf( 'Retrieved %d words from %s.', $words, (string) $item['publisher'] ),
			array(
				'status'         => self::SUCCESS,
				'article_job_id' => $article_job_id,
				'source_item_id' => (int) $item['id'],
				'category_id'    => (int) $item['category_id'],
			)
		);

		Lifecycle::transition( $article_job_id, Lifecycle::AI_PENDING );
		Jobs::enqueue( Jobs::GENERATE, array( 'article_job_id' => $article_job_id ), 'generate:' . $article_job_id, 0, (int) Settings::get( 'ai_max_retries' ) );

		return JobResult::done();
	}

	private static function finish( array $article, array $item, string $retrieval_status, string $job_status, string $code, string $message ): void {
		self::update_item( (int) $item['id'], array( 'retrieval_status' => $retrieval_status ) );
		Lifecycle::transition(
			(int) $article['id'],
			$job_status,
			array(
				'error_code'    => $code,
				'error_message' => $message,
			)
		);
		Events::log(
			'retrieval',
			'warning',
			$message . ' Story: ' . wp_strip_all_tags( (string) $item['headline'] ),
			array(
				'status'         => $retrieval_status,
				'error_code'     => $code,
				'article_job_id' => (int) $article['id'],
				'source_item_id' => (int) $item['id'],
				'category_id'    => (int) $item['category_id'],
			)
		);
	}

	private static function mark_duplicate( array $article, array $item, array $duplicate ): void {
		Lifecycle::transition(
			(int) $article['id'],
			Lifecycle::DUPLICATE,
			array(
				'error_code'    => 'duplicate',
				'error_message' => $duplicate['reason'],
			)
		);
		Dedupe::record_item_duplicate( $item, $duplicate );
	}

	private static function update_item( int $item_id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'source_items' ), $fields, array( 'id' => $item_id ) );
	}
}
