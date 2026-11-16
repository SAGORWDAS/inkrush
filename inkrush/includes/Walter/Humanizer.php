<?php
namespace ZZZCopy\Walter;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Lifecycle;
use ZZZCopy\Locks;
use ZZZCopy\OpenRouter;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;
use ZZZCopy\Usage;
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
 * Since 1.12.2 the whole article goes to Walter (title, headings, list items, paragraphs), then the
 * site's AI model puts back every fact Walter changed, keeping Walter's wording, and each part is
 * checked on its own (HumanizeText::check_part()). A part that still differs keeps the AI's wording.
 *
 * A post is never held back for Walter. If Walter is not connected, is out of words, is down, or
 * nothing passes the fact check, the post goes out with the AI's wording, and the log says so.
 * The AI's version is kept in original_title and original_body.
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

	/**
	 * Owner's decision of 2026-11-16 ("strict, save words"): Walter charges for text the fact check
	 * refuses, so after this many articles in a row with nothing accepted, sending stops for the rest
	 * of the local day. The next day one article is tried; if it fails too, the rest of that day is
	 * paused again. One accepted section ends the pause.
	 */
	public const MISSES_TO_PAUSE = 3;

	public const GUARD_OPTION = 'zzzc_walter_guard';

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
		// Walter can take up to its timeout for each call, then the AI's fact repair up to its own.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) Settings::get( 'walter_timeout' ) * 3 + (int) Settings::get( 'ai_timeout' ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			self::humanize( $job_id, $content, $last_chance, $ctx );
			// Walter does not send the balance with its answers, so it is read again (this uses no words).
			if ( WalterClient::connected() ) {
				WalterClient::account();
			}
		} catch ( \Throwable $e ) {
			// Humanizing is optional. Nothing here may stop the post from going out.
			self::record( $job_id, self::FAILED, 'Humanizing stopped on an unexpected error: ' . $e->getMessage() . ' The post goes out with the AI\'s wording.', 0, $ctx, 'error' );
		} finally {
			Locks::release( 'humanize_' . $job_id, $lock );
		}
	}

	private static function humanize( int $job_id, array $content, bool $last_chance, array $ctx ): void {
		$title  = (string) $content['title'];
		$body   = (string) $content['body'];
		$blocks = HumanizeText::blocks( $body );
		$units  = HumanizeText::units( $title, $blocks );
		$items  = HumanizeText::items( $units );
		if ( ! $items ) {
			self::record( $job_id, self::UNCHANGED, 'The article has too little text (55 words in all) to send to Walter.', 0, $ctx );
			return;
		}
		$total = count( $units );

		$guard = self::guard();
		if ( self::paused( $guard, Schedule::today() ) ) {
			self::record( $job_id, self::SKIPPED, sprintf( 'Not sent to Walter today: nothing of the last %d articles passed the fact check, so sending is paused until tomorrow to save words.', (int) $guard['misses'] ), 0, $ctx );
			return;
		}

		// Walter's answer from an earlier try whose fact repair did not finish: its words are already spent.
		$saved_key = 'zzzc_walter_answer_' . $job_id;
		$hash      = md5( $title . "\n" . $body );
		$saved     = get_transient( $saved_key );
		if ( is_array( $saved ) && ( $saved['hash'] ?? '' ) === $hash && ! empty( $saved['answers'] ) ) {
			$answers = (array) $saved['answers'];
			$spent   = (int) ( $saved['spent'] ?? 0 );
		} else {
			$walter = self::ask_walter( $job_id, $units, $items, $last_chance, $ctx );
			if ( null === $walter ) {
				return;
			}
			list( $answers, $spent ) = $walter;
			set_transient(
				$saved_key,
				array(
					'hash'    => $hash,
					'answers' => $answers,
					'spent'   => $spent,
				),
				2 * HOUR_IN_SECONDS
			);
		}

		// The units Walter answered for, in article order, and its text for them.
		$sent   = array();
		$texts  = array();
		foreach ( $answers as $number => $text ) {
			foreach ( $items[ $number ] ?? array() as $position ) {
				$sent[] = $units[ $position ];
			}
			$texts[] = $text;
		}
		if ( ! $sent ) {
			delete_transient( $saved_key );
			self::record( $job_id, self::FAILED, 'Walter\'s answer did not match the article any more. The post goes out with the AI\'s wording.', 0, $ctx, 'warning' );
			return;
		}

		// The site's AI model puts every fact back as the original had it, keeping Walter's wording.
		$result = OpenRouter::chat( HumanizeText::repair_messages( $sent, implode( "\n\n", $texts ) ), (string) Settings::get( 'model_id' ), (int) Settings::get( 'max_output_tokens' ) );
		Usage::record( 'humanize', $job_id, $result );
		$parts = $result['ok'] ? HumanizeText::parse_repair( (string) $result['content'] ) : array();
		if ( ! $parts ) {
			$why = $result['ok'] ? 'The AI\'s fact check of Walter\'s text could not be read.' : 'The AI could not check Walter\'s text: ' . rtrim( (string) $result['message'], '. ' ) . '.';
			if ( ! $last_chance ) {
				self::record( $job_id, self::RETRY, $why . ' Tried again just before the post goes out, with the same Walter text.', 0, $ctx, 'warning' );
			} else {
				delete_transient( $saved_key );
				self::record( $job_id, self::FAILED, $why . ' The post goes out with the AI\'s wording.', 0, $ctx, 'warning' );
			}
			return;
		}
		delete_transient( $saved_key );

		// Each part is checked on its own against its own original.
		$article   = HumanizeText::article_text( $title, $blocks );
		$inner    = array();
		$new_title = $title;
		$accepted = 0;
		$same     = 0;
		$refused  = array();
		foreach ( $sent as $unit ) {
			$new = HumanizeText::clean_part( (string) ( $parts[ $unit['id'] ] ?? '' ) );
			if ( '' === $new ) {
				$refused[] = 'the fact check left a part out';
				continue;
			}
			if ( $new === $unit['text'] ) {
				++$same;
				continue;
			}
			$why = HumanizeText::check_part( $unit, $new, $article );
			if ( '' !== $why ) {
				$refused[] = $why;
				continue;
			}
			if ( 'title' === $unit['kind'] ) {
				$new_title = $new;
			} else {
				$html = HumanizeText::render_inner( $new, $blocks[ $unit['block'] ]['inline'] );
				if ( null === $html ) {
					$refused[] = 'a link could not be put back';
					continue;
				}
				$inner[ $unit['block'] ] = $html;
			}
			++$accepted;
		}

		// Words were spent: remember whether anything came of them.
		$guard = self::next_guard( $guard, Schedule::today(), $accepted > 0 );
		update_option( self::GUARD_OPTION, $guard, false );

		if ( ! $accepted ) {
			$message = sprintf( 'Nothing of Walter\'s version (%d words) passed the fact check, so the AI\'s wording is kept', $spent ) . ( $refused ? ': ' . self::summary( $refused ) : '.' );
			if ( self::paused( $guard, Schedule::today() ) ) {
				$message = sprintf( 'Sending to Walter is paused until tomorrow: %d articles in a row came back with nothing that passed. ', (int) $guard['misses'] ) . $message;
			}
			self::record( $job_id, self::UNCHANGED, $message, 0, $ctx, 'warning' );
			return;
		}

		$new_body = HumanizeText::rebuild( $blocks, $inner );
		$problem  = self::new_validation_errors( $content, $new_title, $new_body );
		if ( '' !== $problem ) {
			self::record( $job_id, self::UNCHANGED, 'The humanized article failed a check the original passes (' . $problem . '), so the AI\'s wording is kept.', 0, $ctx, 'warning' );
			return;
		}

		global $wpdb;
		$wpdb->update(
			Db::table( 'article_content' ),
			array(
				'title'          => $new_title,
				'body'           => $new_body,
				'original_title' => $title,
				'original_body'  => $body,
				'word_count'     => HumanizeText::count_words( HumanizeText::plain( $new_body ) ),
			),
			array( 'article_job_id' => $job_id )
		);

		$status  = $accepted === $total ? self::DONE : self::PARTIAL;
		$message = sprintf( 'Humanized with Walter: %1$d of %2$d parts (title, headings, list items, paragraphs), %3$d Walter words.', $accepted, $total, $spent );
		if ( count( $sent ) < $total ) {
			$message .= sprintf( ' %d were not sent, to spread the plan\'s words over the month.', $total - count( $sent ) );
		}
		if ( $same ) {
			$message .= sprintf( ' %d came back the same.', $same );
		}
		if ( $refused ) {
			$message .= ' Kept the AI\'s wording where a fact still differed: ' . self::summary( $refused );
		}
		self::record( $job_id, $status, $message, $spent, $ctx );
	}

	/**
	 * Sends the pieces this post's share of the words allows to Walter.
	 *
	 * @return array|null [ piece number => Walter's cleaned text, words spent ], or null when nothing
	 *                    came back (already recorded).
	 */
	private static function ask_walter( int $job_id, array $units, array $items, bool $last_chance, array $ctx ): ?array {
		$allowance = self::allowance();
		if ( is_string( $allowance ) ) {
			self::record( $job_id, self::SKIPPED, $allowance, 0, $ctx, 'warning' );
			return null;
		}
		// The first pieces, as many as this post's share of the words allows.
		$chosen = array();
		$words  = 0;
		foreach ( $items as $number => $item ) {
			$size = HumanizeText::item_words( $units, $item );
			if ( $words + $size > $allowance ) {
				break;
			}
			$chosen[ $number ] = $item;
			$words            += $size;
		}
		if ( ! $chosen ) {
			self::record( $job_id, self::SKIPPED, sprintf( 'This post\'s share of the Walter words (%d) is smaller than the first piece of the article.', $allowance ), 0, $ctx, 'warning' );
			return null;
		}

		$answers = array();
		$spent   = 0;
		$failure = null;
		$problem = array();
		foreach ( self::batches( $units, $chosen ) as $batch ) {
			$payload = array();
			foreach ( $batch as $number => $item ) {
				$text  = HumanizeText::item_text( $units, $item );
				$names = array_slice( HumanizeText::names( $text ), 0, 100 );
				$entry = array(
					'id'   => 'g' . $number,
					'text' => $text,
				);
				if ( $names ) {
					$entry['preserve'] = array(
						'entities' => $names,
						'numbers'  => true,
						'urls'     => true,
					);
				}
				$payload[] = $entry;
			}
			$result = WalterClient::call(
				'walter_batch_humanize',
				array(
					'items'    => $payload,
					'defaults' => array(
						'mode'          => (string) Settings::get( 'walter_mode' ),
						'reading_level' => (string) Settings::get( 'walter_reading_level' ),
						'tone'          => 'neutral, factual news reporting',
						'audience'      => 'general news readers',
						'preserve'      => array(
							'numbers' => true,
							'urls'    => true,
						),
						'structure'     => array(
							'keep_headings' => true,
							'keep_lists'    => true,
						),
						'output_format' => 'markdown',
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
				$text = HumanizeText::clean_answer( (string) ( $answer['humanized_text'] ?? '' ) );
				if ( 'success' !== ( $answer['status'] ?? '' ) || '' === $text ) {
					$problem[] = 'Walter: ' . (string) ( $answer['error']['message'] ?? 'no text came back' );
					continue;
				}
				$answers[ $number ] = $text;
				$spent             += HumanizeText::item_words( $units, $batch[ $number ] );
			}
		}
		ksort( $answers );

		if ( ! $answers ) {
			if ( null !== $failure && $failure['temporary'] && ! $last_chance ) {
				self::record( $job_id, self::RETRY, $failure['message'] . ' Tried again just before the post goes out.', 0, $ctx, 'warning' );
			} else {
				$why = null !== $failure ? $failure['message'] : self::summary( $problem );
				self::record( $job_id, self::FAILED, $why . ' The post goes out with the AI\'s wording.', 0, $ctx, 'warning' );
			}
			return null;
		}
		return array( $answers, $spent );
	}

	/**
	 * Splits the chosen pieces into calls that stay inside Walter's limits.
	 *
	 * @return array<int, array<int, int[]>> Each call's pieces, keyed by piece number.
	 */
	private static function batches( array $units, array $items ): array {
		$limit   = (int) ( WalterClient::data()['max_words'] ?? 0 );
		$limit   = (int) floor( ( $limit > 0 ? $limit : self::DEFAULT_REQUEST_WORDS ) * 0.9 );
		$batches = array();
		$current = array();
		$words   = 0;
		foreach ( $items as $number => $item ) {
			$size = HumanizeText::item_words( $units, $item );
			if ( $current && ( $words + $size > $limit || count( $current ) >= self::BATCH_ITEMS ) ) {
				$batches[] = $current;
				$current   = array();
				$words     = 0;
			}
			$current[ $number ] = $item;
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
		if ( $allow < HumanizeText::MIN_ITEM_WORDS ) {
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

	/** The saved count of articles in a row with nothing accepted, and the day sending was paused. */
	public static function guard(): array {
		$guard = get_option( self::GUARD_OPTION, array() );
		$guard = is_array( $guard ) ? $guard : array();
		return array(
			'misses'    => max( 0, (int) ( $guard['misses'] ?? 0 ) ),
			'paused_on' => (string) ( $guard['paused_on'] ?? '' ),
		);
	}

	/** True when nothing should be sent to Walter today. Pure, so it has a unit test. */
	public static function paused( array $guard, string $today ): bool {
		return (int) ( $guard['misses'] ?? 0 ) >= self::MISSES_TO_PAUSE && $today === (string) ( $guard['paused_on'] ?? '' );
	}

	/** The guard after an article that spent words. Pure, so it has a unit test. */
	public static function next_guard( array $guard, string $today, bool $accepted ): array {
		if ( $accepted ) {
			return array(
				'misses'    => 0,
				'paused_on' => '',
			);
		}
		$misses = (int) ( $guard['misses'] ?? 0 ) + 1;
		return array(
			'misses'    => $misses,
			'paused_on' => $misses >= self::MISSES_TO_PAUSE ? $today : '',
		);
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
	private static function new_validation_errors( array $content, string $new_title, string $new_body ): string {
		$meta   = json_decode( (string) $content['validation_result'], true ) ?: array();
		$tags   = json_decode( (string) $content['tags_json'], true ) ?: array();
		$before = Validator::validate( (string) $content['title'], (string) $content['body'], (string) $content['finish_reason'], (string) ( $meta['preamble'] ?? '' ), $tags );
		$after  = Validator::validate( $new_title, $new_body, (string) $content['finish_reason'], (string) ( $meta['preamble'] ?? '' ), $tags );
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
