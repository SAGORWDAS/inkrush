<?php
namespace ZZZCopy\Walter;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Lifecycle;
use ZZZCopy\Locks;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;
use ZZZCopy\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Humanizes the one article chosen for a post, between the AI writing it and the post going out.
 *
 * Owner's decision of 2026-11-16: only an article that is about to be published is humanized, so
 * three a day on the owner's site, never the spare articles. It runs twice at most:
 *  - In Past-Hour Posting, as soon as the post's article has its image (PastHour::prepare_image).
 *  - Just before the post is created (Publisher::run_publish), if it has not run yet or Walter
 *    was busy the first time. That is also when it runs outside Past-Hour Posting.
 *
 * A post is never held back for Walter. If Walter is not connected, is out of words, is down, or
 * every group fails the fact check, the post goes out with the AI's wording, and the log says so.
 * The AI's version is kept in original_body.
 */
final class Humanizer {

	public const DONE      = 'done';
	public const PARTIAL   = 'partial';
	public const UNCHANGED = 'unchanged';
	public const SKIPPED   = 'skipped';
	public const RETRY     = 'retry';
	public const FAILED    = 'failed';

	/** Walter allows 25 pieces in one batch call. */
	private const BATCH_ITEMS = 25;

	/** Used when Walter has not said how many words one request may hold. Pro allows 1,500. */
	private const DEFAULT_REQUEST_WORDS = 1500;

	/** The balance is read again when the saved one is older than this. */
	private const BALANCE_MAX_AGE = 1800;

	public static function enabled(): bool {
		return (bool) Settings::get( 'walter_enabled' ) && WalterClient::connected();
	}

	/**
	 * Humanizes the article if that has not been done.
	 *
	 * @param int  $job_id      The article.
	 * @param bool $last_chance True right before the post is created: Walter being busy then means
	 *                          the post goes out as it is.
	 */
	public static function run( int $job_id, bool $last_chance ): void {
		if ( ! Settings::get( 'walter_enabled' ) ) {
			return;
		}
		$content = Lifecycle::content( $job_id );
		$job     = Lifecycle::job( $job_id );
		if ( ! $content || ! $job ) {
			return;
		}
		$status = (string) ( $content['humanize_status'] ?? '' );
		if ( '' !== $status && ! ( self::RETRY === $status && $last_chance ) ) {
			return;
		}
		$ctx = array(
			'article_job_id' => $job_id,
			'category_id'    => (int) $job['category_id'],
			'source_item_id' => (int) $job['source_item_id'],
		);

		if ( ! WalterClient::connected() ) {
			if ( WalterClient::needs_reconnect() ) {
				self::record( $job_id, self::SKIPPED, 'Walter needs to be connected again on the AI Settings page.', 0, $ctx, 'warning' );
			}
			return; // Never connected: humanizing is simply not set up.
		}

		$lock = Locks::acquire( 'humanize_' . $job_id, 900 );
		if ( ! $lock ) {
			return;
		}
		// Walter can take up to the timeout for each call; the worker's own run budget is shorter.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) Settings::get( 'walter_timeout' ) * 3 + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			self::humanize( $job_id, $content, $last_chance, $ctx );
		} catch ( \Throwable $e ) {
			// Humanizing is optional. Nothing here may stop the post from going out.
			self::record( $job_id, self::FAILED, 'Humanizing stopped on an unexpected error: ' . $e->getMessage() . ' The post goes out with the AI\'s wording.', 0, $ctx, 'error' );
		} finally {
			Locks::release( 'humanize_' . $job_id, $lock );
		}
	}

	private static function humanize( int $job_id, array $content, bool $last_chance, array $ctx ): void {
		$body   = (string) $content['body'];
		$blocks = HumanizeText::blocks( $body );
		$groups = HumanizeText::groups( $blocks );
		if ( ! $groups ) {
			self::record( $job_id, self::UNCHANGED, 'No plain paragraph of 60 words or more without a quote, so nothing was sent to Walter.', 0, $ctx );
			return;
		}

		$allowance = self::allowance();
		if ( is_string( $allowance ) ) {
			self::record( $job_id, self::SKIPPED, $allowance, 0, $ctx, 'warning' );
			return;
		}

		// The first groups, as many as this post's share of the words allows.
		$chosen = array();
		$words  = 0;
		foreach ( $groups as $group ) {
			$size = HumanizeText::count_words( HumanizeText::group_text( $blocks, $group ) );
			if ( $words + $size > $allowance ) {
				break;
			}
			$chosen[] = $group;
			$words   += $size;
		}
		if ( ! $chosen ) {
			self::record( $job_id, self::SKIPPED, sprintf( 'This post\'s share of the Walter words (%d) is smaller than one piece Walter accepts.', $allowance ), 0, $ctx, 'warning' );
			return;
		}

		$article  = HumanizeText::plain( $body );
		$accepted = array();
		$refused  = array();
		$used     = 0;
		$failure  = null;

		foreach ( self::batches( $blocks, $chosen ) as $batch ) {
			$items = array();
			foreach ( $batch as $number => $group ) {
				$text  = HumanizeText::group_text( $blocks, $group );
				$names = array_slice( HumanizeText::names( $text ), 0, 100 );
				$item  = array(
					'id'   => 'g' . $number,
					'text' => $text,
				);
				if ( $names ) {
					$item['preserve'] = array(
						'entities' => $names,
						'numbers'  => true,
						'urls'     => true,
					);
				}
				$items[] = $item;
			}
			$result = WalterClient::call(
				'walter_batch_humanize',
				array(
					'items'    => $items,
					'defaults' => array(
						'mode'          => (string) Settings::get( 'walter_mode' ),
						'reading_level' => (string) Settings::get( 'walter_reading_level' ),
						'tone'          => 'neutral, factual news reporting',
						'audience'      => 'general news readers',
						'preserve'      => array(
							'numbers' => true,
							'urls'    => true,
						),
						'output_format' => 'plain',
						'disallowed'    => array( '—' ),
					),
				),
				(int) Settings::get( 'walter_timeout' )
			);
			if ( ! $result['ok'] ) {
				$failure = $result;
				break;
			}
			WalterClient::note_balance( $result['words_left'] );

			foreach ( (array) ( $result['data']['results'] ?? array() ) as $answer ) {
				$number = (int) substr( (string) ( $answer['id'] ?? '' ), 1 );
				if ( ! isset( $batch[ $number ] ) ) {
					continue;
				}
				$group    = $batch[ $number ];
				$original = HumanizeText::group_text( $blocks, $group );
				if ( 'success' !== ( $answer['status'] ?? '' ) ) {
					$refused[] = 'Walter: ' . (string) ( $answer['error']['message'] ?? 'no text came back' );
					continue;
				}
				$paragraphs = HumanizeText::clean( (string) ( $answer['humanized_text'] ?? '' ) );
				$why        = HumanizeText::fact_check( $original, implode( "\n\n", $paragraphs ), $article );
				if ( '' !== $why ) {
					$refused[] = $why;
					continue;
				}
				$accepted[ $group[0] ] = array( $group, HumanizeText::fit_paragraphs( $paragraphs, count( $group ) ) );
				$used                 += HumanizeText::count_words( $original );
			}
		}

		if ( null !== $failure && ! $accepted ) {
			if ( $failure['temporary'] && ! $last_chance ) {
				self::record( $job_id, self::RETRY, $failure['message'] . ' Tried again just before the post goes out.', 0, $ctx, 'warning' );
			} else {
				self::record( $job_id, self::FAILED, $failure['message'] . ' The post goes out with the AI\'s wording.', 0, $ctx, 'warning' );
			}
			return;
		}

		if ( ! $accepted ) {
			self::record( $job_id, self::UNCHANGED, 'Walter\'s versions did not pass the fact check, so the AI\'s wording is kept: ' . self::summary( $refused ), 0, $ctx, 'warning' );
			return;
		}

		$new_body = HumanizeText::rebuild( $blocks, $accepted );
		$problem  = self::new_validation_errors( $content, $new_body );
		if ( '' !== $problem ) {
			self::record( $job_id, self::UNCHANGED, 'The humanized article failed a check the original passes (' . $problem . '), so the AI\'s wording is kept.', 0, $ctx, 'warning' );
			return;
		}

		global $wpdb;
		$wpdb->update(
			Db::table( 'article_content' ),
			array(
				'body'          => $new_body,
				'original_body' => $body,
				'word_count'    => HumanizeText::count_words( HumanizeText::plain( $new_body ) ),
			),
			array( 'article_job_id' => $job_id )
		);

		$total   = count( $groups );
		$status  = count( $accepted ) === $total ? self::DONE : self::PARTIAL;
		$message = sprintf( 'Humanized with Walter: %1$d of %2$d paragraph groups, %3$d words.', count( $accepted ), $total, $used );
		if ( count( $chosen ) < $total ) {
			$message .= sprintf( ' %d groups were not sent, to spread the plan\'s words over the month.', $total - count( $chosen ) );
		}
		if ( $refused ) {
			$message .= ' Kept the AI\'s wording where Walter\'s failed the fact check: ' . self::summary( $refused );
		}
		if ( null !== $failure ) {
			$message .= ' Walter stopped part way: ' . $failure['message'];
		}
		self::record( $job_id, $status, $message, $used, $ctx );
	}

	/**
	 * Splits the chosen groups into calls that stay inside Walter's limits.
	 *
	 * @return array<int, array<int, int[]>> Each call's groups, keyed by a number unique to the article.
	 */
	private static function batches( array $blocks, array $groups ): array {
		$limit   = (int) ( WalterClient::data()['max_words'] ?? 0 );
		$limit   = (int) floor( ( $limit > 0 ? $limit : self::DEFAULT_REQUEST_WORDS ) * 0.9 );
		$batches = array();
		$current = array();
		$words   = 0;
		foreach ( $groups as $number => $group ) {
			$size = HumanizeText::count_words( HumanizeText::group_text( $blocks, $group ) );
			if ( $current && ( $words + $size > $limit || count( $current ) >= self::BATCH_ITEMS ) ) {
				$batches[] = $current;
				$current   = array();
				$words     = 0;
			}
			$current[ $number ] = $group;
			$words             += $size;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		return $batches;
	}

	/**
	 * How many words this post may use: the words left in the Walter plan, shared evenly over the
	 * posts left before it renews, and never more than the per-article maximum.
	 *
	 * @return int|string The allowance, or why there is none.
	 */
	private static function allowance() {
		$data = WalterClient::data();
		if ( ! isset( $data['credits'] ) || null === $data['credits'] || Clock::now() - Clock::to_timestamp( $data['checked_at'] ?? null ) > self::BALANCE_MAX_AGE ) {
			$account = WalterClient::account();
			if ( is_wp_error( $account ) ) {
				return 'Walter\'s word balance could not be read: ' . $account->get_error_message();
			}
			$data = WalterClient::data();
		}
		$credits = (int) ( $data['credits'] ?? 0 );
		$allow   = self::allowance_for( $credits, Schedule::today(), (int) Settings::get( 'walter_renewal_day' ), (int) Settings::get( 'daily_target' ), (int) Settings::get( 'walter_max_words' ) );
		if ( $allow < HumanizeText::MIN_GROUP_WORDS ) {
			return sprintf( 'The Walter plan has %d words left, too few to share with the posts before it renews.', $credits );
		}
		return $allow;
	}

	/**
	 * The share of the words for one post. Pure, so it has a unit test.
	 *
	 * @param int    $credits     Words left in the plan.
	 * @param string $today       Local date, Y-m-d.
	 * @param int    $renewal_day Day of the month the plan renews, 1 to 31.
	 * @param int    $per_day     Posts a day.
	 * @param int    $cap         Most words for one article.
	 */
	public static function allowance_for( int $credits, string $today, int $renewal_day, int $per_day, int $cap ): int {
		$days = self::days_until_renewal( $today, $renewal_day );
		$left = max( 1, $days * max( 1, $per_day ) );
		return max( 0, min( $cap, intdiv( max( 0, $credits ), $left ) ) );
	}

	/** Days from today until the plan renews, at least 1. On the renewal day itself, a whole month. */
	public static function days_until_renewal( string $today, int $renewal_day ): int {
		$now  = new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$day  = max( 1, min( 31, $renewal_day ) );
		$this_month = $now->modify( 'first day of this month' );
		$date = $this_month->setDate( (int) $this_month->format( 'Y' ), (int) $this_month->format( 'm' ), min( $day, (int) $this_month->format( 't' ) ) );
		if ( $date <= $now ) {
			$next = $now->modify( 'first day of next month' );
			$date = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), min( $day, (int) $next->format( 't' ) ) );
		}
		return max( 1, (int) $now->diff( $date )->days );
	}

	/**
	 * Validation errors the humanized body has and the original does not. Empty when there are none.
	 */
	private static function new_validation_errors( array $content, string $new_body ): string {
		$meta   = json_decode( (string) $content['validation_result'], true ) ?: array();
		$tags   = json_decode( (string) $content['tags_json'], true ) ?: array();
		$before = Validator::validate( (string) $content['title'], (string) $content['body'], (string) $content['finish_reason'], (string) ( $meta['preamble'] ?? '' ), $tags );
		$after  = Validator::validate( (string) $content['title'], $new_body, (string) $content['finish_reason'], (string) ( $meta['preamble'] ?? '' ), $tags );
		$new    = array_diff( array_column( $after['errors'], 0 ), array_column( $before['errors'], 0 ) );
		return implode( ', ', $new );
	}

	private static function summary( array $reasons ): string {
		$counts = array_count_values( $reasons );
		$parts  = array();
		foreach ( array_slice( $counts, 0, 4, true ) as $reason => $count ) {
			$parts[] = $reason . ( $count > 1 ? ' (' . $count . ' times)' : '' );
		}
		return implode( '; ', $parts ) . '.';
	}

	private static function record( int $job_id, string $status, string $note, int $words, array $ctx, string $severity = 'info' ): void {
		global $wpdb;
		$wpdb->update(
			Db::table( 'article_content' ),
			array(
				'humanize_status' => $status,
				'humanize_note'   => substr( $note, 0, 255 ),
				'humanized_words' => $words,
				'humanized_at'    => Clock::mysql(),
			),
			array( 'article_job_id' => $job_id )
		);
		Events::log( 'humanizer', $severity, $note, array_merge( $ctx, array( 'status' => strtoupper( $status ), 'error_code' => in_array( $status, array( self::DONE, self::PARTIAL ), true ) ? '' : 'humanize_' . $status ) ) );
	}

	/** A short label for the Articles page. */
	public static function label( array $content ): string {
		switch ( (string) ( $content['humanize_status'] ?? '' ) ) {
			case self::DONE:
				return 'Humanized';
			case self::PARTIAL:
				return 'Partly humanized';
			case self::UNCHANGED:
				return 'Not humanized (fact check)';
			case self::SKIPPED:
				return 'Not humanized (skipped)';
			case self::RETRY:
				return 'Waiting for Walter';
			case self::FAILED:
				return 'Not humanized (Walter failed)';
		}
		return '';
	}
}
