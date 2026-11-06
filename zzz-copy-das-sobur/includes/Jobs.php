<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Durable background job queue in a plugin table. No third-party scheduler.
 *
 * Workers claim one job at a time with a lease. If a worker dies, the lease expires
 * and the job becomes runnable again.
 */
final class Jobs {

	public const DISCOVER    = 'discover';
	public const RETRIEVE    = 'retrieve';
	public const GENERATE    = 'generate';
	public const TAGS        = 'tags';
	public const VALIDATE    = 'validate';
	public const USAGE_FETCH = 'usage_fetch';

	/**
	 * Adds a job. With a unique key, a job already waiting or running is left alone,
	 * and a finished one is reset so it can run again.
	 */
	public static function enqueue( string $type, array $payload, ?string $unique_key = null, int $delay = 0, int $max_attempts = 3 ): void {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$now   = Clock::mysql();
		$run   = Clock::mysql( Clock::now() + $delay );
		$json  = wp_json_encode( $payload );

		if ( null === $unique_key ) {
			$wpdb->insert(
				$table,
				array(
					'job_type'     => $type,
					'payload'      => $json,
					'status'       => 'pending',
					'max_attempts' => $max_attempts,
					'run_at'       => $run,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			return;
		}

		$sql = "INSERT INTO {$table} (job_type, unique_key, payload, status, attempts, max_attempts, run_at, created_at, updated_at)
			VALUES (%s, %s, %s, 'pending', 0, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				payload = IF(status IN ('done','failed','cancelled'), VALUES(payload), payload),
				attempts = IF(status IN ('done','failed','cancelled'), 0, attempts),
				max_attempts = IF(status IN ('done','failed','cancelled'), VALUES(max_attempts), max_attempts),
				run_at = IF(status IN ('done','failed','cancelled'), VALUES(run_at), run_at),
				last_error = IF(status IN ('done','failed','cancelled'), NULL, last_error),
				updated_at = VALUES(updated_at),
				status = IF(status IN ('done','failed','cancelled'), 'pending', status)";

		$wpdb->query( $wpdb->prepare( $sql, $type, $unique_key, $json, $max_attempts, $run, $now, $now ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Claims the next due job of an allowed type.
	 *
	 * @param string[] $types         Allowed job types.
	 * @param string   $owner         Worker identifier.
	 * @param int      $lease_seconds Lease length.
	 */
	public static function claim( array $types, string $owner, int $lease_seconds ): ?array {
		global $wpdb;
		if ( ! $types ) {
			return null;
		}
		$table = Db::table( 'jobs' );
		$now   = Clock::mysql();
		$in    = Db::in_strings( $types );

		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND run_at <= %s AND job_type IN {$in} ORDER BY run_at ASC, id ASC LIMIT 5", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( $candidates as $candidate ) {
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'running', lease_owner = %s, lease_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB
					$owner,
					gmdate( 'Y-m-d H:i:s', time() + $lease_seconds ),
					$now,
					(int) $candidate['id']
				)
			);
			if ( 1 === (int) $claimed ) {
				$row            = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $candidate['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$row['payload'] = json_decode( (string) $row['payload'], true ) ?: array();
				return $row;
			}
		}
		return null;
	}

	public static function complete( array $job ): void {
		self::set( $job, array( 'status' => 'done', 'lease_owner' => '', 'lease_until' => null ) );
	}

	public static function fail( array $job, string $error ): void {
		self::set(
			$job,
			array(
				'status'      => 'failed',
				'lease_owner' => '',
				'lease_until' => null,
				'last_error'  => Secrets::redact( $error ),
			)
		);
	}

	/**
	 * Schedules another attempt. Returns false when attempts are used up.
	 */
	public static function retry( array $job, int $delay, string $error ): bool {
		if ( (int) $job['attempts'] >= (int) $job['max_attempts'] ) {
			return false;
		}
		self::set(
			$job,
			array(
				'status'      => 'pending',
				'lease_owner' => '',
				'lease_until' => null,
				'run_at'      => Clock::mysql( Clock::now() + $delay ),
				'last_error'  => Secrets::redact( $error ),
			)
		);
		return true;
	}

	/**
	 * Puts a job back without using up an attempt, for example while a host cool-down applies.
	 */
	public static function defer( array $job, int $delay, string $reason = '' ): void {
		self::set(
			$job,
			array(
				'status'      => 'pending',
				'lease_owner' => '',
				'lease_until' => null,
				'attempts'    => max( 0, (int) $job['attempts'] - 1 ),
				'run_at'      => Clock::mysql( Clock::now() + $delay ),
				'last_error'  => Secrets::redact( $reason ),
			)
		);
	}

	private static function set( array $job, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = Clock::mysql();
		$wpdb->update( Db::table( 'jobs' ), $fields, array( 'id' => (int) $job['id'] ) );
	}

	/**
	 * Makes jobs from dead workers runnable again. Returns the number recovered.
	 */
	public static function recover_stale(): int {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'running' AND lease_until < %s LIMIT 50", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $row ) {
			$exhausted = (int) $row['attempts'] >= (int) $row['max_attempts'];
			$wpdb->update(
				$table,
				array(
					'status'      => $exhausted ? 'failed' : 'pending',
					'lease_owner' => '',
					'lease_until' => null,
					'last_error'  => 'Worker stopped before finishing this job.',
					'updated_at'  => Clock::mysql(),
				),
				array( 'id' => (int) $row['id'] )
			);
			$payload = json_decode( (string) $row['payload'], true ) ?: array();
			Events::log(
				'scheduler',
				'warning',
				sprintf( 'Recovered a stale %s job from a stopped worker.', $row['job_type'] ),
				array(
					'status'         => $exhausted ? 'failed' : 'pending',
					'error_code'     => 'stale_job',
					'article_job_id' => (int) ( $payload['article_job_id'] ?? 0 ),
					'attempt'        => (int) $row['attempts'],
				)
			);
			if ( $exhausted ) {
				Runner::on_job_exhausted( $row['job_type'], $payload, 'worker_stopped', 'The worker stopped repeatedly before finishing this job.' );
			}
		}
		return count( $rows );
	}

	public static function counts(): array {
		global $wpdb;
		$table = Db::table( 'jobs' );
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Exponential backoff with jitter, in seconds.
	 */
	public static function backoff( int $attempt, int $base = 60, int $cap = 3600 ): int {
		$delay = min( $cap, $base * ( 2 ** max( 0, $attempt - 1 ) ) );
		return (int) $delay + wp_rand( 0, (int) ceil( $delay * 0.25 ) );
	}
}
