<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * The background worker. Each tick is one short, bounded PHP run.
 *
 * Ticks come from a server cron job (recommended) or from WP-Cron as a fallback.
 * Nothing here depends on a browser session.
 */
final class Runner {

	private const LOCK = 'runner';

	/**
	 * @param string $source server, cli or wp-cron.
	 * @return array Summary of the run.
	 */
	public static function tick( string $source ): array {
		$summary = array(
			'ran'    => false,
			'source' => $source,
			'jobs'   => 0,
			'reason' => '',
		);

		if ( ! Automation::is_active() ) {
			$summary['reason'] = 'stopped';
			return $summary;
		}

		$budget = (int) Settings::get( 'run_budget_seconds' );
		$ttl    = $budget + (int) Settings::get( 'ai_timeout' ) + 420;
		$owner  = Locks::acquire( self::LOCK, $ttl );
		if ( ! $owner ) {
			Health::beat( $source );
			$summary['reason'] = 'another_worker_running';
			return $summary;
		}

		ignore_user_abort( true );
		$started = microtime( true );

		try {
			Health::beat( $source );

			if ( AutoStop::check() ) {
				$summary['reason'] = 'auto_stopped';
				return $summary;
			}

			if ( Automation::STARTING === Automation::get( 'run_state' ) ) {
				Automation::patch( array( 'run_state' => Automation::RUNNING ) );
				Events::log( 'control', 'info', 'Background worker confirmed. Automation is running.', array( 'status' => Automation::RUNNING ) );
			}

			Schedule::ensure_slots();
			Jobs::recover_stale();
			Publisher::recover_interrupted();

			$state = Automation::state();
			if ( ! $state['paused_research'] ) {
				Planner::tick();
			}

			Publisher::tick();

			while ( microtime( true ) - $started < $budget ) {
				// Safe checkpoint: a Stop or Pause takes effect before the next job starts.
				if ( ! Automation::is_active() || AutoStop::check() ) {
					break;
				}
				$job = Jobs::claim( self::allowed_types(), $owner, $ttl );
				if ( ! $job ) {
					break;
				}
				self::execute( $job );
				$summary['jobs']++;
				if ( Automation::is_active() ) {
					Publisher::tick();
				}
			}

			$summary['ran'] = true;
		} catch ( \Throwable $e ) {
			Events::log(
				'scheduler',
				'error',
				'The background worker stopped on an unexpected error: ' . $e->getMessage(),
				array( 'error_code' => 'worker_exception' )
			);
			$summary['reason'] = 'exception';
		} finally {
			Locks::release( self::LOCK, $owner );
			Health::beat( $source );
		}

		return $summary;
	}

	/**
	 * Job types allowed by the current pause settings.
	 */
	public static function allowed_types(): array {
		$state = Automation::state();
		$types = array( Jobs::USAGE_FETCH );
		if ( ! $state['paused_research'] ) {
			$types[] = Jobs::DISCOVER;
			$types[] = Jobs::RETRIEVE;
		}
		if ( '' === $state['ai_block_code'] ) {
			$types[] = Jobs::GENERATE;
			$types[] = Jobs::TAGS;
		}
		$types[] = Jobs::VALIDATE;
		return $types;
	}

	private static function execute( array $job ): void {
		$limit = (int) Settings::get( 'ai_timeout' ) + 90;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		try {
			switch ( $job['job_type'] ) {
				case Jobs::DISCOVER:
					$result = Discovery::handle( $job );
					break;
				case Jobs::RETRIEVE:
					$result = Retrieval::handle( $job );
					break;
				case Jobs::GENERATE:
					$result = Generation::handle_generate( $job );
					break;
				case Jobs::TAGS:
					$result = Generation::handle_tags( $job );
					break;
				case Jobs::VALIDATE:
					$result = Generation::handle_validate( $job );
					break;
				case Jobs::USAGE_FETCH:
					$result = Usage::handle_fetch( $job );
					break;
				default:
					$result = JobResult::fail( 'unknown_job', 'Unknown job type: ' . $job['job_type'] );
			}
		} catch ( \Throwable $e ) {
			$result = JobResult::retry( Jobs::backoff( (int) $job['attempts'] ), 'exception', 'Unexpected error: ' . $e->getMessage() );
		}

		self::apply_result( $job, $result );
	}

	private static function apply_result( array $job, JobResult $result ): void {
		switch ( $result->outcome ) {
			case JobResult::DONE:
				Jobs::complete( $job );
				return;

			case JobResult::DEFER:
				Jobs::defer( $job, $result->delay, $result->message );
				return;

			case JobResult::RETRY:
				if ( Jobs::retry( $job, $result->delay, $result->code . ': ' . $result->message ) ) {
					Events::log(
						self::stage_for( $job['job_type'] ),
						'warning',
						sprintf( 'Will retry in about %d seconds: %s', $result->delay, $result->message ),
						array(
							'error_code'     => $result->code,
							'attempt'        => (int) $job['attempts'],
							'article_job_id' => (int) ( $job['payload']['article_job_id'] ?? 0 ),
						)
					);
					return;
				}
				// Attempts are used up, so fall through to the failure path.
				// no break.

			case JobResult::FAIL:
			default:
				Jobs::fail( $job, $result->code . ': ' . $result->message );
				self::on_job_exhausted( $job['job_type'], $job['payload'], $result->code, $result->message );
		}
	}

	/**
	 * Marks the article record when its job has failed for good.
	 */
	public static function on_job_exhausted( string $type, array $payload, string $code, string $message ): void {
		$article_job_id = (int) ( $payload['article_job_id'] ?? 0 );
		$fields         = array(
			'error_code'    => $code,
			'error_message' => Secrets::redact( $message ),
		);

		if ( ! $article_job_id ) {
			Events::log( self::stage_for( $type ), 'error', $message, array( 'error_code' => $code, 'category_id' => (int) ( $payload['category_id'] ?? 0 ) ) );
			return;
		}

		$job = Lifecycle::job( $article_job_id );
		if ( ! $job ) {
			return;
		}

		$target = null;
		if ( Jobs::RETRIEVE === $type ) {
			$target = Lifecycle::RETRIEVAL_FAILED;
		} elseif ( Jobs::GENERATE === $type || Jobs::TAGS === $type ) {
			$target = Lifecycle::AI_FAILED;
		} elseif ( Jobs::VALIDATE === $type ) {
			$target = Lifecycle::HELD;
		}

		if ( $target && $job['status'] !== $target && Lifecycle::can( $job['status'], $target ) ) {
			Lifecycle::transition( $article_job_id, $target, $fields );
		}

		Events::log(
			self::stage_for( $type ),
			'error',
			$message,
			array(
				'error_code'     => $code,
				'status'         => (string) $target,
				'article_job_id' => $article_job_id,
				'category_id'    => (int) $job['category_id'],
				'source_item_id' => (int) $job['source_item_id'],
			)
		);
	}

	private static function stage_for( string $type ): string {
		$map = array(
			Jobs::DISCOVER    => 'discovery',
			Jobs::RETRIEVE    => 'retrieval',
			Jobs::GENERATE    => 'ai',
			Jobs::TAGS        => 'ai',
			Jobs::VALIDATE    => 'validation',
			Jobs::USAGE_FETCH => 'ai',
		);
		return $map[ $type ] ?? 'scheduler';
	}
}
