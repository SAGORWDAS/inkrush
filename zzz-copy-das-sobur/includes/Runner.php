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
			'social' => 0,
			'reason' => '',
		);

		$automation = Automation::is_active();
		$social     = Social\SocialSettings::enabled();

		// Social distribution runs on its own. An article that is already published on the website
		// has nothing left to do with news discovery, so its social posts must still go out when
		// automation is paused, stopped or has stopped itself. The worker therefore keeps running
		// while either side has work, and each side decides for itself what it may do.
		if ( ! $automation && ! $social ) {
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

			Jobs::recover_stale();

			if ( $automation && AutoStop::check() ) {
				// Automation has stopped itself. Social distribution carries on below, because
				// its queued posts belong to articles that are already on the website.
				$automation        = false;
				$summary['reason'] = 'auto_stopped';
			}

			if ( $automation ) {
				if ( Automation::STARTING === Automation::get( 'run_state' ) ) {
					Automation::patch( array( 'run_state' => Automation::RUNNING ) );
					Events::log( 'control', 'info', 'Background worker confirmed. Automation is running.', array( 'status' => Automation::RUNNING ) );
				}

				Schedule::ensure_slots();
				Publisher::recover_interrupted();

				$state = Automation::state();
				if ( ! $state['paused_research'] ) {
					Planner::tick();
				}

				Publisher::tick();
			}

			if ( $social ) {
				// Turns anything due into jobs, and recovers social posts a stopped worker left
				// mid-flight. Never throws into the automation path above.
				$summary['social'] = Social\SocialManager::tick();
			}

			while ( microtime( true ) - $started < $budget ) {
				// Safe checkpoint, read fresh each time round: a Stop or Pause takes effect
				// before the next job starts, and takes effect only on the automation jobs.
				// An empty list means neither side has anything it is allowed to run.
				$types = self::allowed_types();
				if ( ! $types ) {
					break;
				}
				$job = Jobs::claim( $types, $owner, $ttl );
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
	 * Job types allowed right now.
	 *
	 * The news automation types are allowed only while automation is active and not paused, which
	 * is exactly as before. The social types are decided separately and are not affected by the
	 * automation state at all, so a social post already queued still goes out after a Stop.
	 * Caption generation is the one exception: it is the only social job that spends money at
	 * OpenRouter, so it respects an AI block such as an invalid key or exhausted credits.
	 */
	public static function allowed_types(): array {
		$state = Automation::state();
		$types = array();

		if ( Automation::is_active() ) {
			$types[] = Jobs::USAGE_FETCH;
			if ( ! $state['paused_research'] ) {
				$types[] = Jobs::DISCOVER;
				$types[] = Jobs::RETRIEVE;
			}
			if ( '' === $state['ai_block_code'] ) {
				$types[] = Jobs::GENERATE;
				$types[] = Jobs::TAGS;
			}
			$types[] = Jobs::VALIDATE;
		}

		if ( Social\SocialSettings::enabled() ) {
			foreach ( Jobs::social_types() as $type ) {
				if ( Jobs::SOCIAL_GENERATE === $type && '' !== (string) $state['ai_block_code'] ) {
					continue;
				}
				$types[] = $type;
			}
		}

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
				case Jobs::SOCIAL_GENERATE:
					$result = Social\SocialManager::handle_generate( $job );
					break;
				case Jobs::SOCIAL_PUBLISH:
					$result = Social\SocialManager::handle_publish( $job );
					break;
				case Jobs::SOCIAL_TOKEN:
					$result = Social\SocialManager::handle_refresh_token( $job );
					break;
				case Jobs::SOCIAL_RECONCILE:
					$result = Social\SocialManager::handle_reconcile( $job );
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
		// A social job that has run out of attempts is recorded against its own social post, and
		// must never change the state of the article. The article is already published on the
		// website, and a platform being unavailable says nothing about the article itself.
		if ( in_array( $type, Jobs::social_types(), true ) ) {
			Social\SocialManager::on_job_exhausted( $type, $payload, $code, $message );
			return;
		}

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
			Jobs::DISCOVER        => 'discovery',
			Jobs::RETRIEVE        => 'retrieval',
			Jobs::GENERATE        => 'ai',
			Jobs::TAGS            => 'ai',
			Jobs::VALIDATE        => 'validation',
			Jobs::USAGE_FETCH     => 'ai',
			Jobs::SOCIAL_GENERATE => Social\SocialLogger::STAGE,
			Jobs::SOCIAL_PUBLISH  => Social\SocialLogger::STAGE,
			Jobs::SOCIAL_TOKEN    => Social\SocialLogger::STAGE,
			Jobs::SOCIAL_RECONCILE => Social\SocialLogger::STAGE,
		);
		return $map[ $type ] ?? 'scheduler';
	}
}
