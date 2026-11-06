<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Decides when to look for stories and which discovered stories to start processing.
 * Research runs independently of publishing, so articles are ready before their slots.
 */
final class Planner {

	public static function tick(): void {
		if ( PastHour::enabled() ) {
			PastHour::tick();
			return;
		}
		// No AI is spent on articles that could not be credited to anyone.
		if ( '' !== Authors::publishing_problem() ) {
			return;
		}
		self::maybe_enqueue_discovery();
		self::promote_candidates();
	}

	/**
	 * Categories with an open slot coming up soon. Research waits until then, so a story is not
	 * chosen today and published as news tomorrow.
	 *
	 * @return int[]
	 */
	public static function planned_category_ids(): array {
		global $wpdb;
		$table = Db::table( 'slots' );
		$until = Clock::now() + (int) Settings::get( 'research_lead_hours' ) * HOUR_IN_SECONDS;
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT category_id FROM {$table} WHERE status IN ('PENDING','DEFERRED') AND category_id > 0 AND local_date >= %s AND due_at <= %s", Schedule::today(), Clock::mysql( $until ) ) ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', $ids );
	}

	/**
	 * Enabled categories whose ready and in-progress articles are below the buffer.
	 */
	public static function categories_needing_articles(): array {
		$planned = self::planned_category_ids();
		$buffer  = (int) Settings::get( 'category_buffer' );
		$out     = array();
		foreach ( Categories::enabled() as $category ) {
			if ( ! in_array( (int) $category['id'], $planned, true ) ) {
				continue;
			}
			if ( self::pipeline_count( (int) $category['id'] ) < $buffer ) {
				$out[] = $category;
			}
		}
		return $out;
	}

	public static function pipeline_count( int $category_id ): int {
		global $wpdb;
		$table = Db::table( 'article_jobs' );
		$in    = Db::in_strings( Lifecycle::PIPELINE );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE category_id = %d AND status IN {$in}", $category_id ) ); // phpcs:ignore WordPress.DB
	}

	private static function maybe_enqueue_discovery(): void {
		$interval = (int) Settings::get( 'discovery_interval_minutes' ) * 60;
		$last     = (int) Automation::get( 'last_discovery_at', 0 );
		$now      = Clock::now();
		if ( $now - $last < $interval ) {
			return;
		}

		$categories = self::categories_needing_articles();
		if ( $categories ) {
			$bucket = (int) floor( $now / max( 60, $interval ) );
			foreach ( $categories as $category ) {
				foreach ( Discovery::fetch_plan( $category ) as $index => $fetch ) {
					Jobs::enqueue(
						Jobs::DISCOVER,
						array(
							'category_id' => (int) $category['id'],
							'adapter'     => $fetch['adapter'],
							'url'         => $fetch['url'],
							'context'     => $fetch['context'],
							'filter'      => $fetch['filter'],
						),
						sprintf( 'discover:%d:%d:%d', $category['id'], $index, $bucket ),
						0,
						(int) Settings::get( 'fetch_max_retries' )
					);
				}
			}
			// Only stamped when something was looked for, so research starts as soon as a slot comes close.
			Automation::set( 'last_discovery_at', $now );
		}
	}

	/**
	 * Starts processing the newest suitable stories while a category is below its buffer.
	 * Stories that fail later stop counting, so the next candidate starts on a later tick.
	 */
	private static function promote_candidates(): void {
		global $wpdb;
		$items = Db::table( 'source_items' );

		foreach ( self::categories_needing_articles() as $category ) {
			$category_id = (int) $category['id'];
			$open        = (int) Settings::get( 'category_buffer' ) - self::pipeline_count( $category_id );

			while ( $open > 0 ) {
				if ( Usage::ai_articles_today() >= (int) Settings::get( 'daily_ai_limit' ) ) {
					return;
				}

				$item = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$items} WHERE category_id = %d AND status = 'DISCOVERED' ORDER BY published_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB
						$category_id
					),
					ARRAY_A
				);
				if ( ! $item ) {
					break;
				}

				$check = Discovery::recency_check( $category, $item['published_at'] ? Clock::to_timestamp( $item['published_at'] ) : 0 );
				if ( ! $check['ok'] ) {
					Discovery::mark_item( (int) $item['id'], 'FILTERED_OUT', 'No longer inside the recency filter: ' . $check['reason'] );
					continue;
				}

				$duplicate = Dedupe::check_candidate( $item );
				if ( $duplicate ) {
					Dedupe::record_item_duplicate( $item, $duplicate );
					continue;
				}

				self::create_article_job( $item );
				$open--;
			}
		}
	}

	/**
	 * Creates the processing record, with snapshots of the current prompts and model.
	 */
	public static function create_article_job( array $item ): int {
		global $wpdb;
		$now = Clock::mysql();

		$inserted = $wpdb->insert(
			Db::table( 'article_jobs' ),
			array(
				'job_uid'                => wp_generate_uuid4(),
				'source_item_id'         => (int) $item['id'],
				'category_id'            => (int) $item['category_id'],
				'status'                 => Lifecycle::RETRIEVAL_PENDING,
				'article_prompt_version' => Prompts::active_id( Prompts::ARTICLE ),
				'tag_prompt_version'     => Prompts::active_id( Prompts::TAG ),
				'system_prompt_version'  => Prompts::active_id( Prompts::SYSTEM ),
				'model_id'               => (string) Settings::get( 'model_id' ),
				'created_at'             => $now,
				'updated_at'             => $now,
			)
		);

		if ( ! $inserted ) {
			return 0;
		}

		$job_id = (int) $wpdb->insert_id;
		Discovery::mark_item( (int) $item['id'], 'PROMOTED', '' );

		Jobs::enqueue(
			Jobs::RETRIEVE,
			array( 'article_job_id' => $job_id ),
			'retrieve:' . $job_id,
			0,
			(int) Settings::get( 'fetch_max_retries' )
		);

		Events::log(
			'queue',
			'info',
			'Story selected for processing: ' . wp_strip_all_tags( (string) $item['headline'] ),
			array(
				'status'         => Lifecycle::RETRIEVAL_PENDING,
				'article_job_id' => $job_id,
				'source_item_id' => (int) $item['id'],
				'category_id'    => (int) $item['category_id'],
			)
		);

		return $job_id;
	}
}
