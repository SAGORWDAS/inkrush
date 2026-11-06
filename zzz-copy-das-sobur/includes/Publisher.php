<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Fills publishing slots by creating WordPress posts.
 *
 * Post creation is idempotent. The post is first saved as a draft with the job identifier stored in
 * the same database row, then finished. A retry after a crash finds that post and completes it
 * rather than creating another.
 */
final class Publisher {

	/** True while the worker itself makes a post public. */
	private static $worker_publishing = false;

	private const LOCK       = 'publish';
	public const META_JOB    = '_zzzc_job_uid';

	/** A held article is searched again once, after this long, when the saved Commons results have expired. */
	public const HELD_RETRY_SECONDS = 21600;

	public static function enqueue( array $article ): void {
		global $wpdb;
		$now   = Clock::mysql();
		$table = Db::table( 'publishing_queue' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (article_job_id, category_id, queue_status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB
				(int) $article['id'],
				(int) $article['category_id'],
				Lifecycle::QUEUED,
				$now,
				$now
			)
		);

		Lifecycle::transition( (int) $article['id'], Lifecycle::QUEUED );

		Events::log(
			'queue',
			'info',
			'Article added to the publishing queue.',
			array(
				'status'         => Lifecycle::QUEUED,
				'article_job_id' => (int) $article['id'],
				'category_id'    => (int) $article['category_id'],
			)
		);
	}

	public static function tick(): void {
		$state = Automation::state();
		if ( ! Automation::is_active() || $state['paused_publishing'] ) {
			return;
		}
		$owner = Locks::acquire( self::LOCK, 600 );
		if ( ! $owner ) {
			return;
		}
		try {
			self::process();
			if ( ! PastHour::enabled() ) {
				self::prepare_next_image();
			}
		} finally {
			Locks::release( self::LOCK, $owner );
		}
	}

	private static function process(): void {
		global $wpdb;
		$slots_table = Db::table( 'slots' );
		$now         = Clock::now();
		$today       = Schedule::today();

		self::close_past_days( $today );
		self::expire_stale_articles();

		if ( PastHour::enabled() ) {
			self::process_past_hour( $today, $now );
			return;
		}

		$due = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$slots_table} WHERE local_date = %s AND status IN ('PENDING','DEFERRED') AND due_at <= %s ORDER BY due_at ASC", // phpcs:ignore WordPress.DB
				$today,
				Clock::mysql( $now )
			),
			ARRAY_A
		);
		if ( ! $due ) {
			return;
		}

		if ( self::cap_reached() ) {
			foreach ( $due as $slot ) {
				self::set_slot( (int) $slot['id'], Schedule::MISSED, 'The daily cap has been reached.' );
			}
			Events::log( 'publishing', 'info', 'A due slot was closed because the daily cap has been reached.', array( 'status' => Schedule::MISSED ) );
			return;
		}

		$grace   = (int) Settings::get( 'slot_grace_minutes' ) * 60;
		$catchup = (string) Settings::get( 'catchup' );

		// First: current slots, still inside their grace period.
		foreach ( $due as $index => $slot ) {
			if ( Schedule::PENDING !== $slot['status'] ) {
				continue;
			}
			$age = $now - Clock::to_timestamp( $slot['due_at'] );
			if ( $age > $grace ) {
				$reason = self::slot_miss_reason( $slot );
				if ( 'skip' === $catchup ) {
					self::set_slot( (int) $slot['id'], Schedule::MISSED, $reason );
					Events::log( 'publishing', 'warning', 'Slot missed: ' . $reason, array( 'status' => Schedule::MISSED, 'category_id' => (int) $slot['category_id'] ) );
				} else {
					self::set_slot( (int) $slot['id'], Schedule::DEFERRED, $reason );
					Events::log( 'publishing', 'warning', 'Slot deferred to the next slot time: ' . $reason, array( 'status' => Schedule::DEFERRED, 'category_id' => (int) $slot['category_id'] ) );
				}
				$due[ $index ]['status'] = 'next' === $catchup ? Schedule::DEFERRED : Schedule::MISSED;
				continue;
			}
			$queue_row = self::pick( $slot );
			if ( $queue_row ) {
				self::publish( $queue_row, $slot );
				return;
			}
		}

		if ( 'next' !== $catchup ) {
			return;
		}

		// Then: deferred slots, once a later slot time has arrived and the minimum gap has passed.
		$latest_due = Clock::to_timestamp(
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(due_at) FROM {$slots_table} WHERE local_date = %s AND due_at <= %s", // phpcs:ignore WordPress.DB
					$today,
					Clock::mysql( $now )
				)
			)
		);
		if ( $now - self::last_post_time() < (int) Settings::get( 'catchup_gap_minutes' ) * 60 ) {
			return;
		}
		foreach ( $due as $slot ) {
			if ( Schedule::DEFERRED !== $slot['status'] || Clock::to_timestamp( $slot['due_at'] ) >= $latest_due ) {
				continue;
			}
			$queue_row = self::pick( $slot );
			if ( $queue_row ) {
				self::publish( $queue_row, $slot );
			}
			return;
		}
	}

	/**
	 * Each post uses only the article prepared for it, or is skipped. Nothing here catches up later.
	 */
	private static function process_past_hour( string $today, int $now ): void {
		global $wpdb;
		$table = Db::table( 'slots' );
		$due   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE local_date = %s AND status IN ('PENDING','DEFERRED') AND due_at <= %s ORDER BY due_at ASC", // phpcs:ignore WordPress.DB
				$today,
				Clock::mysql( $now )
			),
			ARRAY_A
		);
		foreach ( $due as $slot ) {
			$queue_row = PastHour::queue_row_for_due_slot( $slot );
			if ( $queue_row ) {
				self::publish( $queue_row, $slot );
				return;
			}
		}
	}

	private static function slot_miss_reason( array $slot ): string {
		if ( self::pick( $slot ) ) {
			return 'The slot time passed while the worker was not running.';
		}
		return 'No validated article was ready at the slot time.';
	}

	private static function close_past_days( string $today ): void {
		global $wpdb;
		$table = Db::table( 'slots' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, status_reason = %s, updated_at = %s WHERE status IN ('PENDING','DEFERRED') AND local_date < %s", // phpcs:ignore WordPress.DB
				Schedule::MISSED,
				'The day ended before this slot was filled.',
				Clock::mysql(),
				$today
			)
		);
	}

	private static function expire_stale_articles(): void {
		global $wpdb;
		$table  = Db::table( 'publishing_queue' );
		$cutoff = Clock::mysql( Clock::now() - (int) Settings::get( 'max_article_age_hours' ) * HOUR_IN_SECONDS );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE queue_status = %s AND created_at < %s LIMIT 50", Lifecycle::QUEUED, $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			if ( Lifecycle::transition( (int) $row['article_job_id'], Lifecycle::SKIPPED, array( 'error_code' => 'stale', 'error_message' => 'The article was queued too long and is no longer fresh news.' ) ) ) {
				$wpdb->update( $table, array( 'queue_status' => Lifecycle::SKIPPED, 'updated_at' => Clock::mysql() ), array( 'id' => (int) $row['id'] ) );
				Images\ImagePipeline::release_unused( (int) $row['article_job_id'] );
				Events::log(
					'queue',
					'info',
					'Skipped a queued article that is no longer fresh.',
					array(
						'status'         => Lifecycle::SKIPPED,
						'article_job_id' => (int) $row['article_job_id'],
						'category_id'    => (int) $row['category_id'],
					)
				);
			}
		}
	}

	/**
	 * Finds the article for a slot: its planned category first, then another category if allowed.
	 */
	private static function pick( array $slot ): ?array {
		global $wpdb;
		$table = Db::table( 'publishing_queue' );
		$runs  = Db::table( 'image_runs' );
		$now   = Clock::mysql();
		// An article whose image is already found comes first, so a post time rarely waits for a search.
		$ready = "COALESCE( r.status IN ('ready','assigned'), 0 ) DESC";

		if ( (int) $slot['category_id'] ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT q.* FROM {$table} q LEFT JOIN {$runs} r ON r.article_job_id = q.article_job_id WHERE q.queue_status = %s AND q.category_id = %d AND ( q.retry_at IS NULL OR q.retry_at <= %s ) ORDER BY {$ready}, q.id DESC LIMIT 1", // phpcs:ignore WordPress.DB
					Lifecycle::QUEUED,
					(int) $slot['category_id'],
					$now
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
			if ( ! Settings::get( 'category_fallback' ) ) {
				return null;
			}
		}

		// Prefer a category that was not used most recently, so one niche does not repeat.
		$links = Db::table( 'post_links' );
		$last  = (int) $wpdb->get_var( "SELECT category_id FROM {$links} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT q.* FROM {$table} q LEFT JOIN {$runs} r ON r.article_job_id = q.article_job_id WHERE q.queue_status = %s AND ( q.retry_at IS NULL OR q.retry_at <= %s ) ORDER BY ( q.category_id = %d ) ASC, {$ready}, q.id DESC LIMIT 1", // phpcs:ignore WordPress.DB
				Lifecycle::QUEUED,
				$now,
				$last
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Posts counted against the daily cap for a local date.
	 */
	public static function posts_on_day( string $local_date, ?string $status = null ): int {
		global $wpdb;
		$table = Db::table( 'post_links' );
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE local_day = %s AND post_status = %s", $local_date, $status ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE local_day = %s", $local_date ) ); // phpcs:ignore WordPress.DB
	}

	public static function cap_count_today(): int {
		$today = Schedule::today();
		return 'published' === Settings::get( 'cap_scope' ) ? self::posts_on_day( $today, 'publish' ) : self::posts_on_day( $today );
	}

	public static function cap_reached(): bool {
		return self::cap_count_today() >= (int) Settings::get( 'daily_cap' );
	}

	private static function last_post_time(): int {
		global $wpdb;
		$table = Db::table( 'post_links' );
		return Clock::to_timestamp( (string) $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	private static function set_slot( int $slot_id, string $status, string $reason = '', int $article_job_id = 0 ): void {
		global $wpdb;
		$fields = array(
			'status'        => $status,
			'status_reason' => substr( $reason, 0, 255 ),
			'updated_at'    => Clock::mysql(),
		);
		if ( $article_job_id ) {
			$fields['article_job_id'] = $article_job_id;
		}
		$wpdb->update( Db::table( 'slots' ), $fields, array( 'id' => $slot_id ) );
	}

	/**
	 * Publishes one queue item for a slot.
	 */
	private static function publish( array $queue_row, array $slot ): void {
		global $wpdb;
		$table      = Db::table( 'publishing_queue' );
		$lock_owner = wp_generate_uuid4();
		$now        = Clock::mysql();

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET queue_status = %s, lock_owner = %s, lock_expires = %s, slot_id = %d, due_at = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND queue_status = %s", // phpcs:ignore WordPress.DB
				Lifecycle::PUBLISHING,
				$lock_owner,
				gmdate( 'Y-m-d H:i:s', time() + 900 ),
				(int) $slot['id'],
				$slot['due_at'],
				$now,
				(int) $queue_row['id'],
				Lifecycle::QUEUED
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}

		$article_job_id = (int) $queue_row['article_job_id'];
		if ( ! Lifecycle::transition( $article_job_id, Lifecycle::PUBLISHING ) ) {
			$wpdb->update( $table, array( 'queue_status' => Lifecycle::QUEUED, 'lock_owner' => '', 'lock_expires' => null ), array( 'id' => (int) $queue_row['id'] ) );
			return;
		}

		self::run_publish( $article_job_id, (int) $queue_row['id'], (int) $queue_row['attempts'] + 1 );
	}

	/**
	 * Creates or finishes the post for a job in the PUBLISHING state.
	 */
	private static function run_publish( int $article_job_id, int $queue_id, int $attempt ): void {
		global $wpdb;
		$table   = Db::table( 'publishing_queue' );
		$job     = Lifecycle::job( $article_job_id );
		$item    = $job ? Lifecycle::item( (int) $job['source_item_id'] ) : null;
		$content = $job ? Lifecycle::content( $article_job_id ) : null;

		$context = array(
			'article_job_id' => $article_job_id,
			'category_id'    => $job ? (int) $job['category_id'] : 0,
			'source_item_id' => $job ? (int) $job['source_item_id'] : 0,
			'attempt'        => $attempt,
		);

		if ( ! $job || ! $item || ! $content ) {
			self::publish_failed( $article_job_id, $queue_id, 'record_missing', 'The article record is incomplete.', $attempt, $context );
			return;
		}

		$post_id = self::find_existing_post( $job );

		if ( ! $post_id ) {
			$duplicate = Dedupe::check_before_publish( $job, $item, $content );
			if ( $duplicate ) {
				Lifecycle::transition( $article_job_id, Lifecycle::DUPLICATE, array( 'error_code' => 'duplicate', 'error_message' => $duplicate['reason'] ) );
				$wpdb->update( $table, array( 'queue_status' => Lifecycle::DUPLICATE, 'lock_owner' => '', 'lock_expires' => null, 'updated_at' => Clock::mysql() ), array( 'id' => $queue_id ) );
				Dedupe::record_item_duplicate( $item, $duplicate );
				Images\ImagePipeline::release_unused( $article_job_id );
				return;
			}

			if ( self::cap_reached() ) {
				Lifecycle::transition( $article_job_id, Lifecycle::QUEUED );
				$wpdb->update( $table, array( 'queue_status' => Lifecycle::QUEUED, 'lock_owner' => '', 'lock_expires' => null, 'slot_id' => 0, 'updated_at' => Clock::mysql() ), array( 'id' => $queue_id ) );
				return;
			}
		}

		// Only an authorised, active author is ever credited. Checked right before each post, so a change
		// on the Authors page applies at once. A post already public before a crash is only finished off.
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			$credited = $post_id ? (int) get_post_field( 'post_author', $post_id ) : Authors::credited_user();
			$problem  = Authors::problem( $credited );
			if ( '' !== $problem ) {
				self::hold_for_author( $job, $queue_id, $problem, $context, $credited );
				return;
			}
		}

		// Usually found before the slot already. If not, it is found now. A post never goes on without one.
		$image = Images\ImagePipeline::prepare( $job, $item, $content );

		if ( Images\ImagePipeline::RETRY === $image['action'] ) {
			// A temporary image problem. The article waits, is not regenerated, and this is not a publish failure.
			Lifecycle::transition( $article_job_id, Lifecycle::QUEUED );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET queue_status = %s, lock_owner = '', lock_expires = NULL, slot_id = 0, attempts = GREATEST(attempts - 1, 0), retry_at = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB
					Lifecycle::QUEUED,
					Clock::mysql( Clock::now() + (int) $image['delay'] ),
					Clock::mysql(),
					$queue_id
				)
			);
			return;
		}

		if ( Images\ImagePipeline::HOLD === $image['action'] ) {
			self::hold_for_image( $job, $queue_id, (string) $image['reason'], $context );
			return;
		}

		// The last freshness check. A post that already went public before a crash is only finished off.
		if ( PastHour::applies( $job ) && ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) ) {
			$stale = PastHour::final_check( $job, $item );
			if ( '' !== $stale ) {
				PastHour::final_check_failed( $job, $post_id, $stale );
				return;
			}
		}

		if ( ! $post_id ) {
			$created = self::create_post( $job, $item, $content );
			if ( is_wp_error( $created ) ) {
				self::publish_failed( $article_job_id, $queue_id, $created->get_error_code(), $created->get_error_message(), $attempt, $context );
				return;
			}
			$post_id = (int) $created;
		}

		do_action( 'zzzc_after_post_created', $post_id, $job );

		$finished = self::finish_post( $post_id, $job, $content, $image );
		if ( is_wp_error( $finished ) ) {
			self::publish_failed( $article_job_id, $queue_id, $finished->get_error_code(), $finished->get_error_message(), $attempt, $context );
			return;
		}

		self::record_success( $post_id, $job, $queue_id, $context );
	}

	/**
	 * Looks for a post already made for this job.
	 */
	public static function find_existing_post( array $job ): int {
		global $wpdb;
		$links  = Db::table( 'post_links' );
		$linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$links} WHERE article_job_id = %d", (int) $job['id'] ) ); // phpcs:ignore WordPress.DB
		if ( $linked && get_post( $linked ) ) {
			return $linked;
		}

		$by_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_JOB, (string) $job['job_uid'] ) );
		if ( $by_meta && get_post( $by_meta ) ) {
			return $by_meta;
		}

		// The identifier is also in post_content_filtered, written in the same row as the post itself.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_content_filtered = %s LIMIT 1", 'zzzc:' . $job['job_uid'] ) );
	}

	/**
	 * @return int|\WP_Error Post id.
	 */
	private static function create_post( array $job, array $item, array $content ) {
		$category = Categories::get( (int) $job['category_id'] );
		if ( ! $category ) {
			return new \WP_Error( 'category_missing', 'The news category for this article no longer exists.' );
		}
		$term_id = Categories::resolve_term( $category );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		$body = wp_kses_post( (string) $content['body'] );
		$body = (string) preg_replace( '#<img\b[^>]*>#i', '', $body );

		if ( 'visible' === Settings::get( 'attribution' ) ) {
			$source_url = (string) ( $item['canonical_url'] ?: $item['resolved_url'] ?: $item['source_url'] );
			$body      .= "\n" . sprintf(
				'<p class="zzzc-source">Source: <a href="%1$s" rel="nofollow noopener">%2$s</a></p>',
				esc_url( $source_url ),
				esc_html( (string) $item['publisher'] )
			);
		}

		$post = array(
			'post_title'            => wp_strip_all_tags( (string) $content['title'] ),
			'post_content'          => $body,
			'post_content_filtered' => 'zzzc:' . $job['job_uid'],
			'post_status'           => 'draft',
			'post_type'             => 'post',
			'post_author'           => self::author_id(),
			'post_category'         => array( (int) $term_id ),
			'meta_input'            => array(
				self::META_JOB            => (string) $job['job_uid'],
				'_zzzc_source_url'        => (string) ( $item['canonical_url'] ?: $item['resolved_url'] ?: $item['source_url'] ),
				'_zzzc_publisher'         => (string) $item['publisher'],
				'_zzzc_source_published'  => (string) $item['published_at'],
				'_zzzc_model'             => (string) $job['model_id'],
				'_zzzc_prompt_version'    => (int) $job['article_prompt_version'],
			),
		);

		$post_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! $post_id ) {
			return new \WP_Error( 'post_not_created', 'WordPress did not create the post.' );
		}
		return (int) $post_id;
	}

	/**
	 * The author credited with the next post: the Post author, only while they are an active author.
	 * There is no fallback, so nobody is ever credited without being authorised.
	 */
	public static function author_id(): int {
		$user_id = Authors::credited_user();
		return Authors::is_active( $user_id ) ? $user_id : 0;
	}

	/**
	 * Assigns tags and sets the final status. Safe to run more than once.
	 *
	 * @return true|\WP_Error
	 */
	private static function finish_post( int $post_id, array $job, array $content, array $image = array() ) {
		$tags = json_decode( (string) $content['tags_json'], true ) ?: array();

		if ( 'reuse' === Settings::get( 'tag_mode' ) ) {
			$tags = array_values(
				array_filter(
					$tags,
					static function ( $tag ) {
						return (bool) term_exists( $tag, 'post_tag' );
					}
				)
			);
		}

		$assigned = wp_set_post_terms( $post_id, $tags, 'post_tag', false );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		update_post_meta( $post_id, self::META_JOB, (string) $job['job_uid'] );

		// The image and its credit are set while the post is still a draft. Without an image that loads,
		// the post stays a draft and the article is held.
		if ( Images\ImagePipeline::USE !== ( $image['action'] ?? '' ) ) {
			return new \WP_Error( 'image_required', 'Every post needs a featured image, and none was chosen.' );
		}
		$assigned_image = Images\ImagePipeline::assign( $post_id, $job, (int) $image['history_id'], (int) $image['attachment_id'] );
		if ( is_wp_error( $assigned_image ) ) {
			Images\ImagePipeline::record_failure( $job, $post_id, $assigned_image->get_error_message() );
			return new \WP_Error( 'image_required', 'The featured image could not be set: ' . $assigned_image->get_error_message() );
		}
		$problem = Images\ImagePipeline::image_problem( $post_id );
		// The image stays this article's, so the next attempt checks the same file again.
		if ( '' !== $problem ) {
			return new \WP_Error( 'image_required', $problem );
		}

		$target = self::target_status( $job );
		$post   = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_missing', 'The post disappeared while publishing.' );
		}

		// A post someone has already moved on, such as to the trash, is left as it is.
		if ( 'draft' === $post->post_status && 'publish' === $target ) {
			self::$worker_publishing = true;
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				),
				true
			);
			self::$worker_publishing = false;
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		return true;
	}

	public static function target_status( array $job ): string {
		$category = Categories::get( (int) $job['category_id'] );
		if ( $category && in_array( $category['publish_mode'], array( 'draft', 'publish' ), true ) ) {
			return $category['publish_mode'];
		}
		return 'publish' === Settings::get( 'publish_mode' ) ? 'publish' : 'draft';
	}

	private static function record_success( int $post_id, array $job, int $queue_id, array $context ): void {
		global $wpdb;
		$links_table = Db::table( 'post_links' );
		$post_status = (string) get_post_status( $post_id );
		$author      = (int) get_post_field( 'post_author', $post_id );
		$now         = Clock::mysql();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$links_table} (article_job_id, job_uid, wp_post_id, post_status, local_day, category_id, author_user_id, created_at) VALUES (%d, %s, %d, %s, %s, %d, %d, %s)", // phpcs:ignore WordPress.DB
				(int) $job['id'],
				(string) $job['job_uid'],
				$post_id,
				$post_status,
				Schedule::today(),
				(int) $job['category_id'],
				$author,
				$now
			)
		);

		$final = 'publish' === $post_status ? Lifecycle::PUBLISHED : Lifecycle::DRAFT_CREATED;
		Lifecycle::transition(
			(int) $job['id'],
			$final,
			array(
				'error_code'    => '',
				'error_message' => '',
			)
		);

		$queue = Db::table( 'publishing_queue' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT slot_id FROM {$queue} WHERE id = %d", $queue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$wpdb->update(
			$queue,
			array(
				'queue_status' => $final,
				'lock_owner'   => '',
				'lock_expires' => null,
				'updated_at'   => $now,
			),
			array( 'id' => $queue_id )
		);
		if ( $row && (int) $row['slot_id'] ) {
			self::set_slot( (int) $row['slot_id'], Schedule::FILLED, '', (int) $job['id'] );
		}

		Events::log(
			'publishing',
			'info',
			sprintf( 'WordPress post %1$d created with status %2$s, credited to %3$s.', $post_id, $post_status, Authors::user_label( $author ) ),
			array_merge(
				$context,
				array(
					'status'         => $final,
					'wp_post_id'     => $post_id,
					'author_user_id' => $author,
				)
			)
		);

		if ( 'publish' === $post_status ) {
			AutoStop::check();
		}
	}

	private static function publish_failed( int $article_job_id, int $queue_id, string $code, string $message, int $attempt, array $context ): void {
		global $wpdb;
		$table = Db::table( 'publishing_queue' );
		$max   = 3;

		if ( $attempt < $max ) {
			$delay = Jobs::backoff( $attempt, 120, 1800 );
			Lifecycle::transition( $article_job_id, Lifecycle::QUEUED, array( 'error_code' => $code, 'error_message' => $message ) );
			$wpdb->update(
				$table,
				array(
					'queue_status' => Lifecycle::QUEUED,
					'lock_owner'   => '',
					'lock_expires' => null,
					'retry_at'     => Clock::mysql( Clock::now() + $delay ),
					'slot_id'      => 0,
					'updated_at'   => Clock::mysql(),
				),
				array( 'id' => $queue_id )
			);
			Events::log( 'publishing', 'warning', sprintf( 'Post creation failed, will retry in about %1$d seconds: %2$s', $delay, $message ), array_merge( $context, array( 'status' => Lifecycle::QUEUED, 'error_code' => $code ) ) );
			return;
		}

		Lifecycle::transition( $article_job_id, Lifecycle::PUBLISH_FAILED, array( 'error_code' => $code, 'error_message' => $message ) );
		$wpdb->update(
			$table,
			array(
				'queue_status' => Lifecycle::PUBLISH_FAILED,
				'lock_owner'   => '',
				'lock_expires' => null,
				'updated_at'   => Clock::mysql(),
			),
			array( 'id' => $queue_id )
		);
		Events::log( 'publishing', 'error', 'Post creation failed: ' . $message, array_merge( $context, array( 'status' => Lifecycle::PUBLISH_FAILED, 'error_code' => $code ) ) );
	}

	/**
	 * An article whose author is not authorised is never published. It waits in Failed or held,
	 * and can be retried once an author is active again.
	 */
	private static function hold_for_author( array $job, int $queue_id, string $reason, array $context, int $credited ): void {
		global $wpdb;
		Lifecycle::transition( (int) $job['id'], Lifecycle::PUBLISH_FAILED, array( 'error_code' => 'author_required', 'error_message' => 'Not published: ' . $reason ) );
		$wpdb->update( Db::table( 'publishing_queue' ), array( 'queue_status' => Lifecycle::PUBLISH_FAILED, 'lock_owner' => '', 'lock_expires' => null, 'updated_at' => Clock::mysql() ), array( 'id' => $queue_id ) );
		Events::log( 'publishing', 'error', 'Held, not published: ' . $reason, array_merge( $context, array( 'status' => Lifecycle::PUBLISH_FAILED, 'error_code' => 'author_required', 'author_user_id' => $credited ) ) );
	}

	/**
	 * An article with no usable image is never published. It waits in Failed or held.
	 */
	private static function hold_for_image( array $job, int $queue_id, string $reason, array $context ): void {
		global $wpdb;
		$article_job_id = (int) $job['id'];
		Lifecycle::transition( $article_job_id, Lifecycle::PUBLISH_FAILED, array( 'error_code' => 'image_required', 'error_message' => 'No usable image, so this article is not published: ' . $reason ) );
		$wpdb->update( Db::table( 'publishing_queue' ), array( 'queue_status' => Lifecycle::PUBLISH_FAILED, 'lock_owner' => '', 'lock_expires' => null, 'updated_at' => Clock::mysql() ), array( 'id' => $queue_id ) );
		$run   = Images\ImagePipeline::get_run( $article_job_id );
		$again = ! PastHour::applies( $job ) && ! (int) ( $run['auto_retried'] ?? 0 );
		Events::log( 'publishing', 'error', 'Held: no usable image, so this article is not published.' . ( $again ? ' Its image search runs once more in 6 hours.' : '' ), array_merge( $context, array( 'status' => Lifecycle::PUBLISH_FAILED, 'error_code' => 'image_required' ) ) );
	}

	/**
	 * Finds the image for one queued article before its slot, so the articles waiting for a post time
	 * already have one. Also gives one held article its single automatic retry.
	 */
	private static function prepare_next_image(): void {
		global $wpdb;
		// Nothing more posts today, or nothing can post at all, so no search is spent now.
		if ( self::cap_reached() || '' !== Authors::publishing_problem() ) {
			return;
		}
		self::retry_held_image();

		$queue = Db::table( 'publishing_queue' );
		$runs  = Db::table( 'image_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT q.* FROM {$queue} q LEFT JOIN {$runs} r ON r.article_job_id = q.article_job_id WHERE q.queue_status = %s AND ( q.retry_at IS NULL OR q.retry_at <= %s ) AND ( r.status IS NULL OR r.status NOT IN ('ready','assigned','none') ) ORDER BY q.id DESC LIMIT 1", // phpcs:ignore WordPress.DB
				Lifecycle::QUEUED,
				Clock::mysql()
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}

		$job     = Lifecycle::job( (int) $row['article_job_id'] );
		$item    = $job ? Lifecycle::item( (int) $job['source_item_id'] ) : null;
		$content = $job ? Lifecycle::content( (int) $job['id'] ) : null;
		if ( ! $job || ! $item || ! $content || Lifecycle::QUEUED !== $job['status'] ) {
			// Looked at again later, so one broken record cannot hold up the others.
			$wpdb->update( $queue, array( 'retry_at' => Clock::mysql( Clock::now() + HOUR_IN_SECONDS ) ), array( 'id' => (int) $row['id'] ) );
			return;
		}

		$image = Images\ImagePipeline::prepare( $job, $item, $content );
		if ( Images\ImagePipeline::RETRY === $image['action'] ) {
			$wpdb->update( $queue, array( 'retry_at' => Clock::mysql( Clock::now() + (int) $image['delay'] ), 'updated_at' => Clock::mysql() ), array( 'id' => (int) $row['id'] ) );
		} elseif ( Images\ImagePipeline::HOLD === $image['action'] ) {
			self::hold_for_image(
				$job,
				(int) $row['id'],
				(string) $image['reason'],
				array(
					'article_job_id' => (int) $job['id'],
					'category_id'    => (int) $job['category_id'],
					'source_item_id' => (int) $job['source_item_id'],
				)
			);
		}
	}

	/**
	 * Commons results are saved for 6 hours, so 6 hours after a hold a new search can find new files.
	 * This happens once for each article. After that only Retry on the Articles page tries again.
	 */
	private static function retry_held_image(): void {
		global $wpdb;
		$queue = Db::table( 'publishing_queue' );
		$jobs  = Db::table( 'article_jobs' );
		$runs  = Db::table( 'image_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT q.* FROM {$queue} q JOIN {$jobs} j ON j.id = q.article_job_id JOIN {$runs} r ON r.article_job_id = q.article_job_id WHERE q.queue_status = %s AND j.status = %s AND j.error_code = %s AND r.auto_retried = 0 AND r.updated_at <= %s ORDER BY q.id DESC LIMIT 1", // phpcs:ignore WordPress.DB
				Lifecycle::PUBLISH_FAILED,
				Lifecycle::PUBLISH_FAILED,
				'image_required',
				Clock::mysql( Clock::now() - self::HELD_RETRY_SECONDS )
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}
		$article_job_id = (int) $row['article_job_id'];
		Images\ImagePipeline::reset_run( $article_job_id, true );
		Lifecycle::transition( $article_job_id, Lifecycle::QUEUED, array( 'error_code' => '', 'error_message' => '' ) );
		$wpdb->update(
			$queue,
			array(
				'queue_status' => Lifecycle::QUEUED,
				'attempts'     => 0,
				'retry_at'     => null,
				'lock_owner'   => '',
				'lock_expires' => null,
				'updated_at'   => Clock::mysql(),
			),
			array( 'id' => (int) $row['id'] )
		);
		Events::log( 'image', 'info', 'A held article gets its one automatic image search, 6 hours later.', array( 'article_job_id' => $article_job_id, 'category_id' => (int) $row['category_id'], 'status' => Lifecycle::QUEUED ) );
	}

	/**
	 * A post from this plugin never goes public without a featured image that loads, even when a person
	 * publishes its draft by hand or WordPress publishes a scheduled one. Posts already public are left alone.
	 */
	public static function guard_image( int $post_id, $post, $update, $post_before ): void {
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
			return;
		}
		if ( ! self::is_plugin_post( $post ) ) {
			return;
		}
		$problem = Images\ImagePipeline::image_problem( $post_id );
		if ( '' === $problem ) {
			if ( ! self::$worker_publishing ) {
				self::log_outside_publish( $post );
			}
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		Events::log( 'publishing', 'error', 'A post from this plugin was kept as a draft, because every post needs a featured image: ' . $problem, array( 'wp_post_id' => $post_id, 'status' => 'draft', 'error_code' => 'image_required' ) );
	}

	/**
	 * Records who made one of this plugin's posts public, when it was not the worker.
	 */
	private static function log_outside_publish( \WP_Post $post ): void {
		$user   = (int) get_current_user_id();
		$author = (int) $post->post_author;
		$text   = $user
			? sprintf( 'Post %1$d was published by hand by %2$s. It is credited to %3$s.', $post->ID, Authors::user_label( $user ), Authors::user_label( $author ) )
			: sprintf( 'Post %1$d was published by WordPress, not by this plugin\'s worker, for example on a schedule. It is credited to %2$s.', $post->ID, Authors::user_label( $author ) );
		if ( $author && ! Authors::is_active( $author ) ) {
			$text .= ' That user is not an active author on the Authors page.';
		}
		Events::log( 'publishing', 'info', $text, array( 'wp_post_id' => $post->ID, 'status' => 'publish', 'author_user_id' => $author ) );
	}

	private static function is_plugin_post( \WP_Post $post ): bool {
		return 0 === strpos( (string) $post->post_content_filtered, 'zzzc:' )
			|| '' !== (string) get_post_meta( $post->ID, self::META_JOB, true )
			|| '' !== (string) get_post_meta( $post->ID, '_zzzc_test', true );
	}

	/**
	 * Recovers items left in PUBLISHING by a worker that stopped.
	 * If the post exists it is finished. Otherwise the item goes back to the queue.
	 * While publishing is paused this waits, because finishing a post would make it public.
	 */
	public static function recover_interrupted(): void {
		if ( Automation::get( 'paused_publishing' ) ) {
			return;
		}

		global $wpdb;
		$table = Db::table( 'publishing_queue' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE queue_status = %s AND lock_expires < %s LIMIT 20", Lifecycle::PUBLISHING, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $row ) {
			$article_job_id = (int) $row['article_job_id'];
			$job            = Lifecycle::job( $article_job_id );
			if ( ! $job ) {
				continue;
			}

			Events::log(
				'publishing',
				'warning',
				'Recovering an article whose worker stopped during post creation.',
				array(
					'article_job_id' => $article_job_id,
					'category_id'    => (int) $job['category_id'],
					'error_code'     => 'stale_publish',
					'status'         => Lifecycle::PUBLISHING,
				)
			);

			if ( self::find_existing_post( $job ) ) {
				$wpdb->update( $table, array( 'lock_expires' => gmdate( 'Y-m-d H:i:s', time() + 900 ) ), array( 'id' => (int) $row['id'] ) );
				self::run_publish( $article_job_id, (int) $row['id'], (int) $row['attempts'] );
			} else {
				Lifecycle::transition( $article_job_id, Lifecycle::QUEUED );
				$wpdb->update(
					$table,
					array(
						'queue_status' => Lifecycle::QUEUED,
						'lock_owner'   => '',
						'lock_expires' => null,
						'updated_at'   => Clock::mysql(),
					),
					array( 'id' => (int) $row['id'] )
				);
			}
		}
	}

	/**
	 * Creates a draft from a test generation. Marked as a test and not counted against the cap.
	 *
	 * @return int|\WP_Error
	 */
	public static function create_test_draft( string $title, string $body, array $tags ) {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => wp_strip_all_tags( $title ),
					'post_content' => (string) preg_replace( '#<img\b[^>]*>#i', '', wp_kses_post( $body ) ),
					'post_status'  => 'draft',
					'post_type'    => 'post',
					'post_author'  => get_current_user_id() ?: self::author_id(),
					'meta_input'   => array( '_zzzc_test' => 1 ),
				)
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			wp_set_post_terms( (int) $post_id, $tags, 'post_tag', false );
			Events::log( 'publishing', 'info', sprintf( 'Test draft %d created.', (int) $post_id ), array( 'wp_post_id' => (int) $post_id, 'status' => 'draft' ) );
		}
		return $post_id;
	}
}
