<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Administrator actions on one article: retry and skip.
 */
final class Articles {

	/**
	 * Requeues a failed or held article after checking its current state.
	 *
	 * @return true|string True, or a message saying why it cannot be retried.
	 */
	public static function retry( int $article_job_id ) {
		global $wpdb;
		$job = Lifecycle::job( $article_job_id );
		if ( ! $job ) {
			return 'This article no longer exists.';
		}

		if ( PastHour::enabled() ) {
			return sprintf( 'While Past-Hour Posting is on, each post uses only a story under %d minutes old, so an earlier article cannot be retried. Turn it off on the Schedule page to retry this one.', PastHour::max_age_minutes() );
		}

		$status = $job['status'];
		$queue  = Db::table( 'publishing_queue' );

		if ( in_array( $status, array( Lifecycle::BLOCKED, Lifecycle::PARTIAL, Lifecycle::RETRIEVAL_FAILED ), true ) ) {
			Lifecycle::transition( $article_job_id, Lifecycle::RETRIEVAL_PENDING, array( 'error_code' => '', 'error_message' => '' ) );
			Jobs::enqueue( Jobs::RETRIEVE, array( 'article_job_id' => $article_job_id ), 'retrieve:' . $article_job_id, 0, (int) Settings::get( 'fetch_max_retries' ) );
		} elseif ( in_array( $status, array( Lifecycle::AI_FAILED, Lifecycle::VALIDATION_FAILED, Lifecycle::HELD ), true ) ) {
			$item = Lifecycle::item( (int) $job['source_item_id'] );
			if ( ! $item || '' === trim( (string) $item['source_text'] ) ) {
				if ( Lifecycle::HELD === $status ) {
					Lifecycle::transition( $article_job_id, Lifecycle::RETRIEVAL_PENDING, array( 'error_code' => '', 'error_message' => '' ) );
					Jobs::enqueue( Jobs::RETRIEVE, array( 'article_job_id' => $article_job_id ), 'retrieve:' . $article_job_id, 0, (int) Settings::get( 'fetch_max_retries' ) );
					Events::log( 'control', 'info', 'Administrator retried the article.', array( 'article_job_id' => $article_job_id, 'status' => Lifecycle::RETRIEVAL_PENDING ) );
					return true;
				}
				return 'The source text is no longer stored, so no article can be generated. Skip this item.';
			}
			if ( '' !== Automation::get( 'ai_block_code', '' ) ) {
				return 'AI jobs are paused: ' . Automation::get( 'ai_block_message', '' );
			}
			if ( Lifecycle::VALIDATION_FAILED === $status ) {
				$wpdb->update( Db::table( 'article_jobs' ), array( 'ai_attempts' => 0 ), array( 'id' => $article_job_id ) );
			}
			Lifecycle::transition( $article_job_id, Lifecycle::AI_PENDING, array( 'error_code' => '', 'error_message' => '' ) );
			Jobs::enqueue( Jobs::GENERATE, array( 'article_job_id' => $article_job_id ), 'generate:' . $article_job_id, 0, (int) Settings::get( 'ai_max_retries' ) );
		} elseif ( Lifecycle::PUBLISH_FAILED === $status ) {
			Images\ImagePipeline::reset_run( $article_job_id );
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
				array( 'article_job_id' => $article_job_id )
			);
		} else {
			return sprintf( 'An article in state %s cannot be retried.', $status );
		}

		Events::log( 'control', 'info', 'Administrator retried the article.', array( 'article_job_id' => $article_job_id, 'category_id' => (int) $job['category_id'] ) );
		return true;
	}

	/**
	 * @return true|string
	 */
	public static function skip( int $article_job_id, string $reason = 'Skipped by administrator.' ) {
		global $wpdb;
		$job = Lifecycle::job( $article_job_id );
		if ( ! $job ) {
			return 'This article no longer exists.';
		}
		if ( ! Lifecycle::can( $job['status'], Lifecycle::SKIPPED ) ) {
			return sprintf( 'An article in state %s cannot be skipped.', $job['status'] );
		}
		Lifecycle::transition( $article_job_id, Lifecycle::SKIPPED, array( 'error_code' => 'skipped', 'error_message' => $reason ) );
		$wpdb->update(
			Db::table( 'publishing_queue' ),
			array(
				'queue_status' => Lifecycle::SKIPPED,
				'updated_at'   => Clock::mysql(),
			),
			array( 'article_job_id' => $article_job_id )
		);
		Images\ImagePipeline::release_unused( $article_job_id );
		Events::log( 'control', 'info', 'Administrator skipped the article: ' . $reason, array( 'article_job_id' => $article_job_id, 'category_id' => (int) $job['category_id'], 'status' => Lifecycle::SKIPPED ) );
		return true;
	}

	/**
	 * Article list for the admin screen.
	 */
	public static function listing( string $status, int $limit, int $offset ): array {
		global $wpdb;
		$jobs  = Db::table( 'article_jobs' );
		$items = Db::table( 'source_items' );
		$cats  = Db::table( 'categories' );
		$cont  = Db::table( 'article_content' );
		$links = Db::table( 'post_links' );
		$runs  = Db::table( 'image_runs' );
		$hist  = Db::table( 'image_history' );

		$where = self::where( $status );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.id, j.job_uid, j.status, j.error_code, j.error_message, j.updated_at, j.ai_attempts, i.headline, i.publisher, i.retrieval_status, c.name AS category, a.title, a.tag_count, l.wp_post_id,
				r.status AS image_status, r.reason AS image_reason, h.creator AS image_creator, h.license AS image_license, h.commons_url AS image_source
				FROM {$jobs} j
				LEFT JOIN {$items} i ON i.id = j.source_item_id
				LEFT JOIN {$cats} c ON c.id = j.category_id
				LEFT JOIN {$cont} a ON a.article_job_id = j.id
				LEFT JOIN {$links} l ON l.article_job_id = j.id
				LEFT JOIN {$runs} r ON r.article_job_id = j.id
				LEFT JOIN {$hist} h ON h.id = r.history_id
				WHERE {$where}
				ORDER BY j.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	public static function listing_count( string $status ): int {
		global $wpdb;
		$jobs  = Db::table( 'article_jobs' );
		$where = self::where( $status );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs} j WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}

	private static function where( string $status ): string {
		global $wpdb;
		if ( 'attention' === $status ) {
			return 'j.status IN ' . Db::in_strings( Lifecycle::ATTENTION );
		}
		if ( 'queue' === $status ) {
			return 'j.status IN ' . Db::in_strings( array( Lifecycle::QUEUED, Lifecycle::SCHEDULED, Lifecycle::PUBLISHING ) );
		}
		if ( in_array( $status, Lifecycle::all_statuses(), true ) ) {
			return $wpdb->prepare( 'j.status = %s', $status );
		}
		return '1=1';
	}
}
