<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Past-Hour Posting: each post uses one story, chosen shortly before its post time, that is still
 * no older than the maximum story age (120 minutes by default) when it goes out. Without such a story
 * the post is skipped for good.
 *
 * The age limit is measured at the post time. So with 120 minutes a 10:00 post can only use a story
 * published after 08:00, however early the search starts. A story from 07:30 would pass an 08:00
 * search and then fail at 10:00, after paying for an article that could never go out.
 *
 * Neither the age limit nor the preparation time is ever longer than the gap between posts.
 *
 * This replaces the article buffer and hourly research while it is on. Finding, reading, writing,
 * validating and images all use the existing code. Turning it off restores the earlier workflow.
 */
final class PastHour {

	/** Time between searches while no story has been found. */
	public const RETRY_EVERY = 900;

	/** Stories this close to the limit are not started, because a worker run may be a minute or two late. */
	private const MARGIN = 300;

	/** A publisher's clock may run slightly ahead. A date further ahead than this is not believed. */
	private const FUTURE_TOLERANCE = 300;

	/**
	 * AI spending guard for one post, when the setting is missing.
	 *
	 * The setting exists because two was too few in practice. On 2026-11-08 the 16:00 post was
	 * skipped after both of its articles were written and validated and then found no usable
	 * image, leaving the slot empty although two perfectly good articles had been paid for.
	 * The day's own limit, Maximum article generations per day, still caps the real spending.
	 */
	private const MAX_AI_ARTICLES = 3;

	/** Politeness guard for one post, since each story tried means fetching a publisher page. */
	private const MAX_STORIES = 8;

	public const SEARCHING = 'SEARCHING';
	public const PREPARING = 'PREPARING';
	public const READY     = 'READY';

	public static function enabled(): bool {
		return (bool) Settings::get( 'past_hour_enabled' );
	}

	/**
	 * Whether this article is bound by these rules. An article made while this was on follows
	 * the earlier rules if it is later turned off.
	 */
	public static function applies( array $article ): bool {
		return self::enabled() && (int) ( $article['slot_id'] ?? 0 ) > 0;
	}

	/**
	 * The age limit at publishing, in minutes. It never widens, and is never longer than the gap between posts.
	 * In Multiple users mode the site's interval is not used, so only the setting itself limits it.
	 */
	public static function max_age_minutes(): int {
		if ( AuthorSchedules::multiple() ) {
			return (int) Settings::get( 'past_hour_max_age_minutes' );
		}
		return min( (int) Settings::get( 'past_hour_max_age_minutes' ), (int) Settings::get( 'interval_hours' ) * 60 );
	}

	/**
	 * How long before each post preparation starts, in minutes. Never longer than the age limit.
	 */
	public static function prep_minutes(): int {
		return min( (int) Settings::get( 'past_hour_prep_minutes' ), self::max_age_minutes() );
	}

	private static function max_age(): int {
		return self::max_age_minutes() * 60;
	}

	private static function prep_seconds(): int {
		return self::prep_minutes() * 60;
	}

	/** Other categories are searched only in the second half of the age limit before the post. */
	private static function fallback_within(): int {
		return (int) ( self::max_age() / 2 );
	}

	public static function prep_start( array $slot ): int {
		return Clock::to_timestamp( $slot['due_at'] ) - self::prep_seconds();
	}

	/**
	 * The oldest publication time a story can have and still be chosen for this slot.
	 */
	private static function earliest_story( array $slot ): int {
		return Clock::to_timestamp( $slot['due_at'] ) - self::max_age() + self::MARGIN;
	}

	private static function slot( int $slot_id ): ?array {
		global $wpdb;
		$table = Db::table( 'slots' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $slot_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	private static function update_slot( int $slot_id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = Clock::mysql();
		$wpdb->update( Db::table( 'slots' ), $fields, array( 'id' => $slot_id ) );
	}

	private static function clock_time( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( Schedule::timezone() )->format( 'H:i' );
	}

	private static function post_time( array $slot ): string {
		return self::clock_time( Clock::to_timestamp( $slot['due_at'] ) );
	}

	private static function minutes( int $seconds ): string {
		$minutes = (int) floor( $seconds / 60 );
		return 1 === $minutes ? '1 minute' : $minutes . ' minutes';
	}

	private static function log( string $severity, string $message, array $ctx = array() ): void {
		Events::log( 'past_hour', $severity, $message, $ctx );
	}

	/* ---- Freshness ---- */

	/**
	 * The window for a story date while this is on, used when a feed is read.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function recency_check( int $published ): array {
		$now = Clock::now();
		if ( $published > $now + self::FUTURE_TOLERANCE ) {
			return array(
				'ok'     => false,
				'reason' => sprintf( 'Published %s UTC, which is in the future, so the date is not reliable.', gmdate( 'Y-m-d H:i', $published ) ),
			);
		}
		if ( $published < $now - self::max_age() ) {
			return array(
				'ok'     => false,
				'reason' => sprintf( 'Published %1$s UTC, older than the %2$d-minute limit of Past-Hour Posting.', gmdate( 'Y-m-d H:i', $published ), self::max_age_minutes() ),
			);
		}
		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Why this story cannot go out in this slot. Empty when it can.
	 * The age is measured at the post time, or now if that has already passed.
	 */
	public static function check_story( array $item, array $slot ): string {
		$published = Clock::to_timestamp( $item['published_at'] ?? null );
		if ( ! $published ) {
			return 'The story has no reliable publication time.';
		}
		$now = Clock::now();
		if ( $published > $now + self::FUTURE_TOLERANCE ) {
			return sprintf( 'The story is dated %s, which is in the future, so the date is not reliable.', Schedule::format_local( $item['published_at'] ) );
		}
		$due = Clock::to_timestamp( $slot['due_at'] );
		$at  = max( $now, $due );
		if ( $at - $published > self::max_age() ) {
			return sprintf( 'The story was published at %1$s, so it would be %2$d minutes old at %3$s. The limit is %4$d minutes.', Schedule::format_local( $item['published_at'] ), (int) ceil( ( $at - $published ) / 60 ), self::clock_time( $at ), self::max_age_minutes() );
		}
		return '';
	}

	/**
	 * The article page's own date, when it is older than the feed's. Feeds can re-date an old story.
	 */
	public static function older_page_date( array $item, int $page_date ): int {
		$feed_date = Clock::to_timestamp( $item['published_at'] ?? null );
		return $page_date > 0 && $page_date < $feed_date ? $page_date : 0;
	}

	/**
	 * Checked at each step of writing an article, so no more is spent on a story that cannot be used.
	 *
	 * @return bool True when the article was stopped.
	 */
	public static function stop_if_unwanted( array $article ): bool {
		if ( ! self::applies( $article ) ) {
			return false;
		}
		$slot = self::slot( (int) $article['slot_id'] );
		if ( ! $slot || Schedule::PENDING !== $slot['status'] ) {
			$reason = 'Its post time has closed.';
		} elseif ( (int) $slot['article_job_id'] !== (int) $article['id'] ) {
			$reason = 'Another story has taken its place.';
		} else {
			$reason = self::check_story( (array) Lifecycle::item( (int) $article['source_item_id'] ), $slot );
		}
		if ( '' === $reason ) {
			return false;
		}
		self::drop_story( $article, $reason );
		return true;
	}

	/**
	 * Stops using a story and frees its slot, which then looks for another if there is still time.
	 */
	private static function drop_story( array $article, string $reason, string $code = 'not_fresh' ): void {
		global $wpdb;
		$article_job_id = (int) $article['id'];
		self::retire_article( $article, $reason, $code );
		$item_id = (int) $article['source_item_id'];
		if ( $item_id ) {
			Discovery::mark_item( $item_id, 'FILTERED_OUT', $reason );
		}
		$table = Db::table( 'slots' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET article_job_id = 0, selected_item_id = 0, selected_category_id = 0, fallback_used = 0, age_at_selection = NULL, prep_status = %s, status_reason = %s, updated_at = %s WHERE id = %d AND article_job_id = %d", // phpcs:ignore WordPress.DB
				self::SEARCHING,
				substr( $reason, 0, 255 ),
				Clock::mysql(),
				(int) $article['slot_id'],
				$article_job_id
			)
		);
		self::log(
			'warning',
			'Story no longer used: ' . $reason,
			array(
				'status'         => 'STORY_REJECTED',
				'error_code'     => $code,
				'article_job_id' => $article_job_id,
				'category_id'    => (int) $article['category_id'],
				'source_item_id' => $item_id,
			)
		);
	}

	/**
	 * Ends an article that will not be published. Failed articles cannot be retried while this is
	 * on, so they are closed instead of waiting for an administrator.
	 */
	private static function retire_article( array $article, string $reason, string $code = 'not_fresh' ): void {
		global $wpdb;
		$article_job_id = (int) $article['id'];
		$fields         = array();
		if ( '' === (string) $article['error_message'] ) {
			$fields = array(
				'error_code'    => $code,
				'error_message' => $reason,
			);
		}
		if ( Lifecycle::can( (string) $article['status'], Lifecycle::SKIPPED ) ) {
			Lifecycle::transition( $article_job_id, Lifecycle::SKIPPED, $fields );
		}
		$wpdb->update(
			Db::table( 'publishing_queue' ),
			array(
				'queue_status' => Lifecycle::SKIPPED,
				'lock_owner'   => '',
				'lock_expires' => null,
				'updated_at'   => Clock::mysql(),
			),
			array( 'article_job_id' => $article_job_id )
		);
		Images\ImagePipeline::release_unused( $article_job_id );
	}

	/* ---- Preparing before the post time ---- */

	/**
	 * Called on each worker run, in place of the article buffer and hourly research.
	 */
	public static function tick(): void {
		global $wpdb;
		$table = Db::table( 'slots' );
		$now   = Clock::now();
		$slots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND due_at > %s AND due_at <= %s AND author_user_id " . ( AuthorSchedules::multiple() ? '> 0' : '= 0' ) . ' ORDER BY due_at ASC', // phpcs:ignore WordPress.DB
				Schedule::PENDING,
				Clock::mysql( $now ),
				Clock::mysql( $now + self::prep_seconds() )
			),
			ARRAY_A
		);
		foreach ( $slots as $slot ) {
			self::prepare_slot( $slot );
		}
	}

	private static function prepare_slot( array $slot ): void {
		$slot_id = (int) $slot['id'];

		if ( empty( $slot['prep_started_at'] ) ) {
			self::update_slot(
				$slot_id,
				array(
					'prep_started_at' => Clock::mysql(),
					'prep_status'     => self::SEARCHING,
				)
			);
			self::log(
				'info',
				sprintf( 'Preparation started for the %1$s post. Planned category: %2$s. Only stories published from %3$s can be used.', self::post_time( $slot ), Categories::name( (int) $slot['category_id'] ) ?: 'none', self::clock_time( self::earliest_story( $slot ) ) ),
				array(
					'status'      => self::SEARCHING,
					'category_id' => (int) $slot['category_id'],
				)
			);
			$slot = (array) self::slot( $slot_id );
		}

		// One article at a time for each post, so repeated searches can never start a second one.
		if ( self::watch_article( $slot ) ) {
			return;
		}
		$slot = (array) self::slot( $slot_id );

		$blocked = self::why_not_selecting( $slot );
		if ( '' !== $blocked ) {
			self::note( $slot, $blocked );
			return;
		}

		$categories = self::search_scope( $slot );
		if ( self::select_story( $slot, $categories ) ) {
			return;
		}
		self::search( $slot, $categories );
	}

	/**
	 * Follows the article in progress.
	 *
	 * @return bool True while the slot still has a usable article.
	 */
	private static function watch_article( array $slot ): bool {
		$article_job_id = (int) $slot['article_job_id'];
		if ( ! $article_job_id ) {
			return false;
		}
		$article = Lifecycle::job( $article_job_id );
		if ( ! $article ) {
			self::update_slot( (int) $slot['id'], array( 'article_job_id' => 0, 'prep_status' => self::SEARCHING ) );
			return false;
		}

		if ( ! in_array( $article['status'], Lifecycle::PIPELINE, true ) ) {
			$reason = sprintf( 'The chosen story could not be used (%1$s): %2$s', $article['status'], (string) $article['error_message'] );
			self::log(
				'warning',
				$reason,
				array(
					'status'         => (string) $article['status'],
					'article_job_id' => $article_job_id,
					'category_id'    => (int) $article['category_id'],
					'source_item_id' => (int) $article['source_item_id'],
				)
			);
			self::retire_article( $article, $reason );
			self::update_slot(
				(int) $slot['id'],
				array(
					'article_job_id'       => 0,
					'selected_item_id'     => 0,
					'selected_category_id' => 0,
					'fallback_used'        => 0,
					'age_at_selection'     => null,
					'prep_status'          => self::SEARCHING,
					'status_reason'        => substr( $reason, 0, 255 ),
				)
			);
			return false;
		}

		$item   = (array) Lifecycle::item( (int) $article['source_item_id'] );
		$reason = self::check_story( $item, $slot );
		if ( '' !== $reason ) {
			self::drop_story( $article, $reason );
			return false;
		}

		if ( Lifecycle::QUEUED === $article['status'] ) {
			self::prepare_image( $slot, $article, $item );
		}
		return true;
	}

	/**
	 * Finds the image before the post time, so a slow image search cannot age the story.
	 */
	private static function prepare_image( array $slot, array $article, array $item ): void {
		if ( self::READY === $slot['prep_status'] ) {
			return;
		}
		$retry_at = Clock::to_timestamp( $slot['image_retry_at'] );
		$content  = Lifecycle::content( (int) $article['id'] );
		if ( ! $content || ( $retry_at && $retry_at > Clock::now() ) ) {
			return;
		}

		$image = Images\ImagePipeline::prepare( $article, $item, $content );
		if ( Images\ImagePipeline::RETRY === $image['action'] ) {
			self::update_slot( (int) $slot['id'], array( 'image_retry_at' => Clock::mysql( Clock::now() + (int) $image['delay'] ) ) );
			return;
		}
		// Every post needs an image, so a story without a usable one gives way to another while there is time.
		if ( Images\ImagePipeline::USE !== $image['action'] ) {
			self::drop_story( $article, 'No usable image: ' . (string) ( $image['reason'] ?? '' ), 'no_image' );
			return;
		}

		self::update_slot(
			(int) $slot['id'],
			array(
				'prep_status'    => self::READY,
				'image_retry_at' => null,
				'status_reason'  => '',
			)
		);
		self::log(
			'info',
			sprintf( 'Article ready for the %1$s post, with its image.', self::post_time( $slot ) ),
			array(
				'status'         => self::READY,
				'article_job_id' => (int) $article['id'],
				'category_id'    => (int) $article['category_id'],
				'source_item_id' => (int) $article['source_item_id'],
			)
		);
	}

	/**
	 * Reasons no new article can be started for this post right now. Empty when one can.
	 */
	private static function why_not_selecting( array $slot ): string {
		global $wpdb;
		if ( Automation::get( 'paused_publishing' ) ) {
			return 'Publishing is paused, so no article is written for this post.';
		}
		$author = AuthorSchedules::multiple() ? AuthorSchedules::slot_problem( $slot, Clock::to_timestamp( $slot['due_at'] ) ) : Authors::publishing_problem();
		if ( '' !== $author ) {
			return $author . ' No article is written for this post.';
		}
		if ( Publisher::cap_reached() ) {
			return 'The daily maximum number of posts has been reached.';
		}
		$block = (string) Automation::get( 'ai_block_message', '' );
		if ( '' !== (string) Automation::get( 'ai_block_code', '' ) ) {
			return 'AI jobs are paused: ' . $block;
		}
		if ( Usage::ai_articles_today() >= (int) Settings::get( 'daily_ai_limit' ) ) {
			return 'The daily AI article limit has been reached.';
		}
		if ( (int) $slot['stories_tried'] >= self::MAX_STORIES ) {
			return sprintf( 'The %d stories tried for this post could not be used.', self::MAX_STORIES );
		}
		$jobs    = Db::table( 'article_jobs' );
		$written = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs} WHERE slot_id = %d AND ai_attempts > 0", (int) $slot['id'] ) ); // phpcs:ignore WordPress.DB
		$allowed = self::max_articles();
		if ( $written >= $allowed ) {
			return sprintf( 'The %d articles written for this post could not be used.', $allowed );
		}
		return '';
	}

	/**
	 * How many articles may be written for one post before it is given up on.
	 */
	public static function max_articles(): int {
		$setting = (int) Settings::get( 'past_hour_max_articles' );
		return $setting > 0 ? $setting : self::MAX_AI_ARTICLES;
	}

	private static function note( array $slot, string $text ): void {
		if ( (string) $slot['status_reason'] === substr( $text, 0, 255 ) ) {
			return;
		}
		self::update_slot( (int) $slot['id'], array( 'status_reason' => substr( $text, 0, 255 ) ) );
		self::log( 'info', sprintf( 'The %1$s post: %2$s', self::post_time( $slot ), $text ), array( 'category_id' => (int) $slot['category_id'] ) );
	}

	/**
	 * Which categories this post may use at this moment.
	 *
	 * @return array{planned:int, others:int[]} planned is 0 when the planned category cannot be used.
	 */
	private static function search_scope( array $slot ): array {
		$planned = (int) $slot['category_id'];
		$ids     = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			Schedule::pool_for_date( (string) $slot['local_date'], AuthorSchedules::multiple() ? (int) ( $slot['author_user_id'] ?? 0 ) : 0 )
		);
		$usable  = in_array( $planned, $ids, true );

		// Others follow the planned category in the rotation order, so each gets its turn.
		$position = array_search( $planned, $ids, true );
		$others   = false === $position ? $ids : array_merge( array_slice( $ids, $position + 1 ), array_slice( $ids, 0, $position ) );

		$open = ! $usable || Clock::to_timestamp( $slot['due_at'] ) - Clock::now() <= self::fallback_within();
		if ( $usable && ! Settings::get( 'category_fallback' ) ) {
			$open = false;
		}

		return array(
			'planned' => $usable ? $planned : 0,
			'others'  => $open ? $others : array(),
		);
	}

	/**
	 * Chooses the newest usable story already found, from the planned category first.
	 * Within a category, publisher feeds come before Google News. A Google story's page often cannot be
	 * found or read, so trying those first would use up the post's tries on stories that fail.
	 */
	private static function select_story( array $slot, array $categories ): bool {
		$scopes = array();
		if ( $categories['planned'] ) {
			$scopes[] = array( $categories['planned'] );
		}
		if ( $categories['others'] ) {
			$scopes[] = $categories['others'];
		}

		foreach ( $scopes as $ids ) {
			$found = self::eligible_items( $slot, $ids );
			foreach ( $found as $item ) {
				$duplicate = Dedupe::check_candidate( $item );
				if ( $duplicate ) {
					Dedupe::record_item_duplicate( $item, $duplicate );
					continue;
				}
				if ( self::start_article( $slot, $item, count( $found ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function eligible_items( array $slot, array $category_ids ): array {
		global $wpdb;
		$table = Db::table( 'source_items' );
		$in    = '(' . implode( ',', array_map( 'intval', $category_ids ) ) . ')';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'DISCOVERED' AND category_id IN {$in} AND published_at >= %s AND published_at <= %s ORDER BY adapter = %s DESC, published_at DESC, id DESC LIMIT 20", // phpcs:ignore WordPress.DB
				Clock::mysql( self::earliest_story( $slot ) ),
				Clock::mysql( Clock::now() + self::FUTURE_TOLERANCE ),
				Discovery::FEED
			),
			ARRAY_A
		);
	}

	private static function start_article( array $slot, array $item, int $candidates ): bool {
		global $wpdb;
		$article_job_id = Planner::create_article_job( $item );
		if ( ! $article_job_id ) {
			return false;
		}

		$age      = max( 0, Clock::now() - Clock::to_timestamp( $item['published_at'] ) );
		$category = (int) $item['category_id'];
		$fallback = $category !== (int) $slot['category_id'];

		$wpdb->update( Db::table( 'article_jobs' ), array( 'slot_id' => (int) $slot['id'] ), array( 'id' => $article_job_id ) );
		self::update_slot(
			(int) $slot['id'],
			array(
				'article_job_id'       => $article_job_id,
				'selected_item_id'     => (int) $item['id'],
				'selected_category_id' => $category,
				'fallback_used'        => $fallback ? 1 : 0,
				'age_at_selection'     => $age,
				'stories_tried'        => (int) $slot['stories_tried'] + 1,
				'prep_status'          => self::PREPARING,
				'status_reason'        => '',
			)
		);

		self::log(
			'info',
			sprintf(
				'Selected for the %1$s post: "%2$s" from %3$s, in %4$s. Published %5$s, %6$s old. Newest %7$s story, of %8$d eligible.%9$s',
				self::post_time( $slot ),
				wp_strip_all_tags( (string) $item['headline'] ),
				(string) $item['publisher'],
				Categories::name( $category ),
				Schedule::format_local( $item['published_at'] ),
				self::minutes( $age ),
				Discovery::FEED === $item['adapter'] ? 'publisher feed' : 'Google News',
				$candidates,
				$fallback ? ' Category fallback used, because the planned category ' . ( Categories::name( (int) $slot['category_id'] ) ?: '(none)' ) . ' had no eligible story.' : ''
			),
			array(
				'status'         => self::PREPARING,
				'article_job_id' => $article_job_id,
				'category_id'    => $category,
				'source_item_id' => (int) $item['id'],
			)
		);
		return true;
	}

	/**
	 * Runs the search for this quarter hour, then moves through other categories one at a time.
	 */
	private static function search( array $slot, array $categories ): void {
		$slot_id = (int) $slot['id'];
		$now     = Clock::now();
		$round   = (int) floor( max( 0, $now - self::prep_start( $slot ) ) / self::RETRY_EVERY ) + 1;
		$queue   = self::id_list( (string) $slot['fallback_queue'] );
		$fields  = array();

		if ( $round > (int) $slot['search_round'] ) {
			$fields['search_round']   = $round;
			$fields['last_search_at'] = Clock::mysql( $now );
			if ( $categories['planned'] ) {
				self::search_category( $slot, $categories['planned'], $round, sprintf( 'Search %1$d of %2$d', $round, (int) ceil( self::prep_seconds() / self::RETRY_EVERY ) ) );
			}
			if ( ! $queue ) {
				$queue = $categories['others'];
			}
		}

		// The next category is searched only after the last one found nothing, so a found story ends this.
		if ( $queue && $categories['others'] && ! self::searches_running( $slot_id ) ) {
			while ( $queue ) {
				$next = (int) array_shift( $queue );
				if ( self::search_category( $slot, $next, max( $round, (int) $slot['search_round'] ), sprintf( 'Category fallback, %d more to try after this', count( $queue ) ) ) ) {
					break;
				}
			}
		}

		$fields['fallback_queue'] = implode( ',', $queue );
		$fields['status_reason']  = substr( self::nothing_found_reason( $slot ), 0, 255 );
		self::update_slot( $slot_id, $fields );
	}

	/**
	 * Adds the fetches for one category. Each knows its slot, so it is dropped if no longer needed.
	 */
	private static function search_category( array $slot, int $category_id, int $round, string $label ): bool {
		global $wpdb;
		$category = Categories::get( $category_id );
		$plan     = $category ? Discovery::fetch_plan( $category ) : array();
		if ( ! $plan ) {
			return false;
		}

		$window = sprintf( 'Past-Hour Posting: published from %1$s, for the %2$s post', self::clock_time( self::earliest_story( $slot ) ), self::post_time( $slot ) );
		$google = 0;
		foreach ( $plan as $index => $fetch ) {
			$google += Discovery::GOOGLE === $fetch['adapter'] ? 1 : 0;
			Jobs::enqueue(
				Jobs::DISCOVER,
				array(
					'category_id' => $category_id,
					'adapter'     => $fetch['adapter'],
					'url'         => $fetch['url'],
					'context'     => $fetch['context'],
					'filter'      => $window,
					'slot_id'     => (int) $slot['id'],
				),
				sprintf( 'ph:%d:%d:%d:%d', (int) $slot['id'], $category_id, $index, $round ),
				0,
				(int) Settings::get( 'fetch_max_retries' )
			);
		}

		$table = Db::table( 'slots' );
		$done  = self::id_list( (string) $wpdb->get_var( $wpdb->prepare( "SELECT categories_searched FROM {$table} WHERE id = %d", (int) $slot['id'] ) ) ); // phpcs:ignore WordPress.DB
		if ( ! in_array( $category_id, $done, true ) ) {
			$done[] = $category_id;
			self::update_slot( (int) $slot['id'], array( 'categories_searched' => implode( ',', $done ) ) );
		}

		self::log(
			'info',
			sprintf(
				'%1$s for the %2$s post: searching %3$s at %4$s. Window: stories published from %5$s. Sources: Google News %6$d, publisher feeds %7$d.',
				$label,
				self::post_time( $slot ),
				(string) $category['name'],
				self::clock_time( Clock::now() ),
				self::clock_time( self::earliest_story( $slot ) ),
				$google,
				count( $plan ) - $google
			),
			array(
				'status'      => self::SEARCHING,
				'category_id' => $category_id,
				'attempt'     => $round,
			)
		);
		return true;
	}

	private static function searches_running( int $slot_id ): bool {
		global $wpdb;
		$table = Db::table( 'jobs' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE unique_key LIKE %s AND status IN ('pending','running')", $wpdb->esc_like( 'ph:' . $slot_id . ':' ) . '%' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether a fetch queued for this post is still worth making.
	 */
	public static function search_needed( int $slot_id ): bool {
		$slot = self::slot( $slot_id );
		return self::enabled() && $slot && Schedule::PENDING === $slot['status'] && Clock::to_timestamp( $slot['due_at'] ) > Clock::now() && ! (int) $slot['article_job_id'];
	}

	private static function id_list( string $text ): array {
		return array_values( array_filter( array_map( 'intval', explode( ',', $text ) ) ) );
	}

	/**
	 * The exact reason no story was used, in plain words for the Logs and the Dashboard.
	 */
	private static function nothing_found_reason( array $slot ): string {
		global $wpdb;
		$slot     = (array) self::slot( (int) $slot['id'] );
		$searched = self::id_list( (string) $slot['categories_searched'] );
		$since    = self::clock_time( self::earliest_story( $slot ) );

		if ( ! $searched ) {
			$text = sprintf( 'No story published since %s was found, because no category could be searched.', $since );
		} elseif ( 1 === count( $searched ) ) {
			$text = sprintf( 'No usable story published since %1$s was found in %2$s.', $since, Categories::name( $searched[0] ) );
		} else {
			$text = sprintf( 'No usable story published since %1$s was found in %2$d categories.', $since, count( $searched ) );
		}

		if ( (int) $slot['stories_tried'] > 0 ) {
			$jobs = Db::table( 'article_jobs' );
			$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT error_message FROM {$jobs} WHERE slot_id = %d ORDER BY id DESC LIMIT 1", (int) $slot['id'] ) ); // phpcs:ignore WordPress.DB
			$text = sprintf( '%1$d stories tried, none could be used. Last problem: %2$s', (int) $slot['stories_tried'], $last );
		}
		return $text;
	}

	/* ---- At the post time ---- */

	/**
	 * Decides what happens to a slot whose time has come.
	 *
	 * @return array|null The queue row to publish now, if any. Otherwise the slot waits or was closed.
	 */
	public static function queue_row_for_due_slot( array $slot ): ?array {
		global $wpdb;

		if ( Schedule::DEFERRED === $slot['status'] ) {
			self::skip_slot( $slot, 'This post was waiting for a later time, and Past-Hour Posting never catches up.' );
			return null;
		}
		if ( AuthorSchedules::multiple() ) {
			$problem = AuthorSchedules::slot_problem( $slot );
			if ( '' !== $problem ) {
				self::skip_slot( $slot, $problem );
				return null;
			}
		}
		if ( empty( $slot['prep_started_at'] ) ) {
			self::skip_slot( $slot, 'Preparation did not run before this time, because the worker was not running or research was paused.' );
			return null;
		}

		$article = (int) $slot['article_job_id'] ? Lifecycle::job( (int) $slot['article_job_id'] ) : null;
		if ( ! $article ) {
			$note = (string) $slot['status_reason'];
			self::skip_slot( $slot, '' !== $note ? $note : self::nothing_found_reason( $slot ) );
			return null;
		}
		if ( Lifecycle::PUBLISHING === $article['status'] ) {
			return null;
		}
		if ( ! in_array( $article['status'], Lifecycle::PIPELINE, true ) ) {
			$reason = sprintf( 'The chosen story could not be used (%1$s): %2$s', $article['status'], (string) $article['error_message'] );
			self::retire_article( $article, $reason );
			self::skip_slot( $slot, $reason );
			return null;
		}

		if ( Publisher::cap_reached() ) {
			self::retire_article( $article, 'The daily maximum number of posts has been reached.' );
			self::skip_slot( $slot, 'The daily maximum number of posts has been reached.' );
			return null;
		}

		$reason = self::check_story( (array) Lifecycle::item( (int) $article['source_item_id'] ), $slot );
		if ( '' !== $reason ) {
			self::record_final_age( $slot, $article );
			self::retire_article( $article, $reason );
			self::skip_slot( $slot, $reason );
			return null;
		}

		$late = Clock::now() - Clock::to_timestamp( $slot['due_at'] );
		if ( $late > (int) Settings::get( 'slot_grace_minutes' ) * 60 ) {
			$reason = sprintf( 'The article was not ready within %d minutes of the post time.', (int) Settings::get( 'slot_grace_minutes' ) );
			self::retire_article( $article, $reason );
			self::skip_slot( $slot, $reason );
			return null;
		}

		if ( Lifecycle::QUEUED !== $article['status'] ) {
			return null;
		}

		$queue = Db::table( 'publishing_queue' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue} WHERE article_job_id = %d", (int) $article['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row || ( $row['retry_at'] && Clock::to_timestamp( $row['retry_at'] ) > Clock::now() ) ) {
			return null;
		}
		return $row;
	}

	private static function record_final_age( array $slot, array $article ): int {
		$item = (array) Lifecycle::item( (int) $article['source_item_id'] );
		$age  = max( 0, Clock::now() - Clock::to_timestamp( $item['published_at'] ?? null ) );
		self::update_slot( (int) $slot['id'], array( 'age_at_publish' => $age ) );
		return $age;
	}

	/**
	 * The last check, made after the image step and immediately before the post is created.
	 *
	 * @return string Why the story cannot go out. Empty when it can.
	 */
	public static function final_check( array $article, array $item ): string {
		$slot = self::slot( (int) $article['slot_id'] );
		if ( ! $slot ) {
			return 'The post time for this article no longer exists.';
		}
		$age    = self::record_final_age( $slot, $article );
		$reason = self::check_story( $item, $slot );
		self::log(
			'' === $reason ? 'info' : 'warning',
			sprintf( 'Final freshness check for the %1$s post: the story is %2$s old. %3$s', self::post_time( $slot ), self::minutes( $age ), '' === $reason ? 'It passed.' : 'It failed. ' . $reason ),
			array(
				'status'         => '' === $reason ? 'FRESH' : 'NOT_FRESH',
				'article_job_id' => (int) $article['id'],
				'category_id'    => (int) $article['category_id'],
				'source_item_id' => (int) $article['source_item_id'],
			)
		);
		return $reason;
	}

	/**
	 * The story failed the final check, so nothing goes out. A draft from an interrupted run is removed.
	 */
	public static function final_check_failed( array $article, int $post_id, string $reason ): void {
		if ( $post_id && 'draft' === get_post_status( $post_id ) ) {
			wp_trash_post( $post_id );
		}
		self::retire_article( $article, $reason );
		$slot = self::slot( (int) $article['slot_id'] );
		if ( $slot ) {
			self::skip_slot( $slot, $reason );
		}
	}

	/**
	 * Closes a post for good. Nothing ever moves a slot out of SKIPPED, so it is never caught up.
	 */
	private static function skip_slot( array $slot, string $reason ): void {
		$article_job_id = (int) $slot['article_job_id'];
		if ( $article_job_id ) {
			$article = Lifecycle::job( $article_job_id );
			if ( $article && in_array( $article['status'], Lifecycle::PIPELINE, true ) ) {
				self::retire_article( $article, $reason );
			}
		}
		self::update_slot(
			(int) $slot['id'],
			array(
				'status'        => Schedule::SKIPPED,
				'status_reason' => substr( $reason, 0, 255 ),
				'prep_status'   => '',
			)
		);
		self::log(
			'warning',
			sprintf( 'Skipped the %1$s post: %2$s', self::post_time( $slot ), $reason ),
			array(
				'status'      => Schedule::SKIPPED,
				'error_code'  => 'post_skipped',
				'category_id' => (int) $slot['category_id'],
			)
		);
	}

	/* ---- For the Dashboard ---- */

	/**
	 * Today's posts, and tomorrow's first when none are still to come today.
	 */
	public static function dashboard_rows(): array {
		global $wpdb;
		$slots    = Db::table( 'slots' );
		$items    = Db::table( 'source_items' );
		$jobs     = Db::table( 'article_jobs' );
		$today    = Schedule::today();
		$tomorrow = Schedule::now_local()->modify( '+1 day' )->format( 'Y-m-d' );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, i.headline, i.publisher, i.published_at, j.status AS article_status
				FROM {$slots} s
				LEFT JOIN {$items} i ON i.id = s.selected_item_id
				LEFT JOIN {$jobs} j ON j.id = s.article_job_id
				WHERE s.local_date IN (%s, %s) AND s.author_user_id " . ( AuthorSchedules::multiple() ? '> 0' : '= 0' ) . ' ORDER BY s.due_at ASC', // phpcs:ignore WordPress.DB
				$today,
				$tomorrow
			),
			ARRAY_A
		);

		$open_today = false;
		$out        = array();
		foreach ( $rows as $row ) {
			if ( $today === $row['local_date'] ) {
				$open_today = $open_today || Schedule::PENDING === $row['status'];
				$out[]      = $row;
			} elseif ( ! $open_today ) {
				$out[] = $row;
				break;
			}
		}
		return $out;
	}

	public static function processing_label( array $row ): string {
		if ( Schedule::PENDING !== $row['status'] ) {
			return 'Finished';
		}
		if ( self::READY === $row['prep_status'] ) {
			return 'Article ready, waiting for the post time';
		}
		if ( self::PREPARING === $row['prep_status'] ) {
			$labels = array(
				Lifecycle::RETRIEVAL_PENDING => 'Reading the source',
				Lifecycle::RETRIEVED         => 'Reading the source',
				Lifecycle::AI_PENDING        => 'Writing the article',
				Lifecycle::AI_RUNNING        => 'Writing the article',
				Lifecycle::GENERATED         => 'Writing the tags',
				Lifecycle::VALIDATING        => 'Checking the article',
				Lifecycle::VALIDATED         => 'Checking the article',
				Lifecycle::QUEUED            => 'Finding the image',
				Lifecycle::PUBLISHING        => 'Publishing',
			);
			return $labels[ (string) $row['article_status'] ] ?? 'Preparing';
		}
		if ( self::SEARCHING === $row['prep_status'] ) {
			return sprintf( 'Searching, %1$d of %2$d searches started', (int) $row['search_round'], (int) ceil( self::prep_seconds() / self::RETRY_EVERY ) );
		}
		return 'Waiting for preparation to start';
	}

	public static function result_label( array $row ): string {
		switch ( $row['status'] ) {
			case Schedule::FILLED:
				return Lifecycle::DRAFT_CREATED === $row['article_status'] ? 'Draft created' : 'Published';
			case Schedule::SKIPPED:
				return 'Skipped: ' . $row['status_reason'];
			case Schedule::MISSED:
				return 'Missed: ' . $row['status_reason'];
			case Schedule::DEFERRED:
				return 'Waiting to be skipped';
		}
		return '' !== (string) $row['status_reason'] ? 'Not yet: ' . $row['status_reason'] : 'Not yet';
	}
}
