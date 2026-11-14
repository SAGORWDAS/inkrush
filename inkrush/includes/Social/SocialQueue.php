<?php
namespace ZZZCopy\Social;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The record of every social post InkRush has been asked to make.
 *
 * This table holds state and history. The work itself runs through the plugin's existing job
 * queue, exactly as publishing_queue holds an article's publishing state while a job in the
 * jobs table does the publishing. There is no second worker and no second scheduler.
 *
 * One row is one article on one platform through one account, identified by an idempotency key.
 * The key is unique in the database, so asking twice cannot produce two posts, whether the second
 * ask comes from a retry, a double save, two workers overlapping or the administrator pressing a
 * button twice.
 */
final class SocialQueue {

	/** Created, caption not generated yet. */
	public const PENDING = 'pending';
	/** Caption ready, waiting for the administrator in Manual approval mode. */
	public const AWAITING = 'awaiting';
	/** Approved and waiting for its time to come. */
	public const SCHEDULED = 'scheduled';
	/** A worker is publishing it now. */
	public const PUBLISHING = 'publishing';
	/** The platform confirmed it, and the external identifier is stored. */
	public const PUBLISHED = 'published';
	/** The attempt ended without a clear answer, so it must be checked before being tried again. */
	public const RECONCILING = 'reconciling';
	/** Attempts used up, or refused for a reason that will not change. */
	public const FAILED = 'failed';
	/** Cancelled by the administrator before it was published. */
	public const CANCELLED = 'cancelled';
	/** Not attempted, because a requirement could not be met, such as a required image. */
	public const SKIPPED = 'skipped';

	/** States that are still going to happen. */
	public const OPEN = array( self::PENDING, self::AWAITING, self::SCHEDULED, self::PUBLISHING, self::RECONCILING );

	/** States that will never change again without the administrator asking. */
	public const CLOSED = array( self::PUBLISHED, self::FAILED, self::CANCELLED, self::SKIPPED );

	private static function table(): string {
		return Db::table( 'social_posts' );
	}

	/* ---- Identity ---- */

	/**
	 * The key that makes publishing idempotent: one WordPress post, one platform, one account.
	 *
	 * The article job id is deliberately not part of it. What must never be duplicated is a post
	 * about this published article on this account, and the WordPress post is the thing the
	 * reader ends up at, whichever article job produced it.
	 */
	public static function key( int $wp_post_id, string $platform, int $account_id ): string {
		return sha1( 'zzzc-social-v1|' . $wp_post_id . '|' . $platform . '|' . $account_id );
	}

	/* ---- Creating ---- */

	/**
	 * Adds a row for one platform, if there is not one already.
	 *
	 * @param array $data wp_post_id, article_job_id, platform, account_id, account_label, origin,
	 *                    status, scheduled_for, link_url, max_attempts.
	 * @return array{id:int, created:bool} The row's id, and whether this call is what created it.
	 */
	public static function add( array $data ): array {
		global $wpdb;

		$wp_post_id = (int) ( $data['wp_post_id'] ?? 0 );
		$platform   = (string) ( $data['platform'] ?? '' );
		$account_id = (int) ( $data['account_id'] ?? 0 );
		$key        = self::key( $wp_post_id, $platform, $account_id );
		$now        = Clock::mysql();

		// INSERT IGNORE against the unique key, so two workers racing cannot both create a row.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (idempotency_key, article_job_id, wp_post_id, platform, account_id, account_label, origin, status, approved, max_attempts, scheduled_for, link_url, created_at, updated_at)
				VALUES (%s, %d, %d, %s, %d, %s, %s, %s, %d, %d, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB
				$key,
				(int) ( $data['article_job_id'] ?? 0 ),
				$wp_post_id,
				$platform,
				$account_id,
				substr( (string) ( $data['account_label'] ?? '' ), 0, 191 ),
				(string) ( $data['origin'] ?? 'auto' ),
				(string) ( $data['status'] ?? self::PENDING ),
				! empty( $data['approved'] ) ? 1 : 0,
				max( 1, (int) ( $data['max_attempts'] ?? SocialSettings::max_attempts() ) ),
				$data['scheduled_for'] ?? null,
				(string) ( $data['link_url'] ?? '' ),
				$now,
				$now
			)
		);

		if ( 1 === (int) $inserted ) {
			return array(
				'id'      => (int) $wpdb->insert_id,
				'created' => true,
			);
		}

		$existing = self::by_key( $key );
		return array(
			'id'      => $existing ? (int) $existing['id'] : 0,
			'created' => false,
		);
	}

	/* ---- Reading ---- */

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate( $row ) : null;
	}

	public static function by_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE idempotency_key = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Every social post for one WordPress post, in platform order.
	 *
	 * @return array[]
	 */
	public static function for_post( int $wp_post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wp_post_id = %d ORDER BY platform ASC, id ASC', $wp_post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows waiting for the administrator to approve them.
	 *
	 * @return array[]
	 */
	public static function awaiting_approval( int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY id ASC LIMIT %d', self::AWAITING, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	private static function hydrate( array $row ): array {
		$media              = json_decode( (string) ( $row['media_json'] ?? '' ), true );
		$row['media']       = is_array( $media ) ? $media : array();
		$row['id']          = (int) $row['id'];
		$row['wp_post_id']  = (int) $row['wp_post_id'];
		$row['account_id']  = (int) $row['account_id'];
		$row['attempts']    = (int) $row['attempts'];
		$row['approved']    = (int) $row['approved'];
		return $row;
	}

	/**
	 * Whether a platform already has a confirmed post for this article, so nothing should be
	 * sent again. Checked immediately before every publish attempt.
	 */
	public static function already_published( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, external_post_id FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return false;
		}
		return self::PUBLISHED === $row['status'] && '' !== (string) $row['external_post_id'];
	}

	/* ---- Changing state ---- */

	private static function set( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_at'] = Clock::mysql();
		return 1 === (int) $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Saves a caption against a row, without changing where it is in the workflow.
	 *
	 * @param string $source ai, manual or fallback.
	 */
	public static function set_caption( int $id, string $caption, string $hashtags, string $source ): bool {
		return self::set(
			$id,
			array(
				'caption'              => $caption,
				'hashtags'             => substr( $hashtags, 0, 255 ),
				'caption_source'       => substr( $source, 0, 10 ),
				'caption_generated_at' => Clock::mysql(),
			)
		);
	}

	public static function set_media( int $id, array $media ): bool {
		return self::set( $id, array( 'media_json' => wp_json_encode( $media ) ) );
	}

	public static function set_status( int $id, string $status, array $fields = array() ): bool {
		return self::set( $id, array_merge( $fields, array( 'status' => $status ) ) );
	}

	/**
	 * Marks a row as being published now, and refuses when another worker already holds it or it
	 * has already been published. This, with the unique key, is what prevents a duplicate post.
	 *
	 * @return bool True when this caller may go ahead and publish.
	 */
	public static function begin_attempt( int $id, string $owner, int $lease_seconds ): bool {
		global $wpdb;
		$now   = Clock::mysql();
		$until = Clock::mysql( Clock::now() + max( 60, $lease_seconds ) );

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = %s, claimed_by = %s, claim_expires = %s, attempts = attempts + 1, updated_at = %s
				WHERE id = %d
				  AND external_post_id = ''
				  AND status IN (%s, %s, %s)
				  AND ( claim_expires IS NULL OR claim_expires < %s )", // phpcs:ignore WordPress.DB
				self::PUBLISHING,
				$owner,
				$until,
				$now,
				$id,
				self::PENDING,
				self::SCHEDULED,
				self::AWAITING,
				$now
			)
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Records a confirmed publication. Called only with an identifier the platform returned.
	 */
	public static function mark_published( int $id, string $external_post_id, string $external_post_url ): bool {
		$now = Clock::mysql();
		$ok  = self::set(
			$id,
			array(
				'status'            => self::PUBLISHED,
				'external_post_id'  => substr( $external_post_id, 0, 191 ),
				'external_post_url' => $external_post_url,
				'published_at'      => $now,
				'claimed_by'        => '',
				'claim_expires'     => null,
				'error_code'        => '',
				'error_message'     => null,
			)
		);
		$row = self::get( $id );
		if ( $row && (int) $row['account_id'] ) {
			SocialAccounts::mark_published( (int) $row['account_id'] );
		}
		return $ok;
	}

	/**
	 * Schedules another attempt. Returns false when the attempts are used up.
	 */
	public static function mark_retry( int $id, int $delay, string $code, string $message ): bool {
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		if ( (int) $row['attempts'] >= (int) $row['max_attempts'] ) {
			return false;
		}
		return self::set(
			$id,
			array(
				'status'        => self::SCHEDULED,
				'retry_at'      => Clock::mysql( Clock::now() + max( 1, $delay ) ),
				'claimed_by'    => '',
				'claim_expires' => null,
				'error_code'    => substr( $code, 0, 60 ),
				'error_message' => Secrets::redact( $message ),
			)
		);
	}

	/**
	 * Puts a row back without using up an attempt, for a rate limit or a cool-down.
	 */
	public static function mark_deferred( int $id, int $delay, string $code, string $message ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET attempts = GREATEST(attempts - 1, 0) WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		return self::set(
			$id,
			array(
				'status'        => self::SCHEDULED,
				'retry_at'      => Clock::mysql( Clock::now() + max( 1, $delay ) ),
				'claimed_by'    => '',
				'claim_expires' => null,
				'error_code'    => substr( $code, 0, 60 ),
				'error_message' => Secrets::redact( $message ),
			)
		);
	}

	public static function mark_failed( int $id, string $code, string $message ): bool {
		return self::set(
			$id,
			array(
				'status'        => self::FAILED,
				'claimed_by'    => '',
				'claim_expires' => null,
				'error_code'    => substr( $code, 0, 60 ),
				'error_message' => Secrets::redact( $message ),
			)
		);
	}

	/**
	 * Marks a row as needing to be checked against the platform.
	 *
	 * Used when an attempt ended without a clear answer: the request timed out, the connection
	 * dropped, or the platform returned something unreadable. The platform may well have accepted
	 * the post, so it must never be retried blindly, and it must not be called a failure either.
	 */
	public static function mark_reconciling( int $id, string $code, string $message, string $remote_id = '' ): bool {
		$fields = array(
			'status'        => self::RECONCILING,
			'claimed_by'    => '',
			'claim_expires' => null,
			'error_code'    => substr( $code, 0, 60 ),
			'error_message' => Secrets::redact( $message ),
			'retry_at'      => Clock::mysql( Clock::now() + 120 ),
		);
		if ( '' !== $remote_id ) {
			$fields['media_remote_id'] = substr( $remote_id, 0, 191 );
		}
		return self::set( $id, $fields );
	}

	public static function cancel( int $id ): bool {
		$row = self::get( $id );
		if ( ! $row || in_array( $row['status'], self::CLOSED, true ) ) {
			return false;
		}
		return self::set(
			$id,
			array(
				'status'        => self::CANCELLED,
				'claimed_by'    => '',
				'claim_expires' => null,
			)
		);
	}

	public static function skip( int $id, string $code, string $message ): bool {
		return self::set(
			$id,
			array(
				'status'        => self::SKIPPED,
				'claimed_by'    => '',
				'claim_expires' => null,
				'error_code'    => substr( $code, 0, 60 ),
				'error_message' => Secrets::redact( $message ),
			)
		);
	}

	/**
	 * Approves one row for publishing, in Manual approval mode.
	 */
	public static function approve( int $id, ?string $scheduled_for = null ): bool {
		$row = self::get( $id );
		if ( ! $row || ! in_array( $row['status'], array( self::AWAITING, self::PENDING ), true ) ) {
			return false;
		}
		return self::set(
			$id,
			array(
				'approved'      => 1,
				'status'        => self::SCHEDULED,
				'scheduled_for' => $scheduled_for,
				'retry_at'      => null,
			)
		);
	}

	/**
	 * Rows a worker took and never finished, because the process was stopped or timed out.
	 *
	 * They go to reconciling rather than straight back to scheduled, because a post whose
	 * request was already sent may exist on the platform.
	 *
	 * @return int How many were recovered.
	 */
	public static function recover_stale(): int {
		global $wpdb;
		$now  = Clock::mysql();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE status = %s AND claim_expires IS NOT NULL AND claim_expires < %s LIMIT 50', self::PUBLISHING, $now ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( (array) $rows as $row ) {
			self::mark_reconciling(
				(int) $row['id'],
				'worker_stopped',
				'The worker stopped before this post finished. It will be checked against the platform before anything is sent again, so a post that was accepted is not sent twice.'
			);
		}
		return count( (array) $rows );
	}

	/**
	 * Rows that are due to be worked on now: approved, their time reached, not held by a worker.
	 *
	 * @return array[]
	 */
	public static function due( int $limit = 20 ): array {
		global $wpdb;
		$now = Clock::mysql();
		$in  = Db::in_strings( array( self::PENDING, self::SCHEDULED, self::RECONCILING ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE status IN {$in}
				  AND ( scheduled_for IS NULL OR scheduled_for <= %s )
				  AND ( retry_at IS NULL OR retry_at <= %s )
				  AND ( claim_expires IS NULL OR claim_expires < %s )
				ORDER BY COALESCE(scheduled_for, retry_at, created_at) ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB
				$now,
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/* ---- Housekeeping ---- */

	/**
	 * Keeps the history readable after an account is disconnected. The rows are not deleted,
	 * because they record what was published, and anything still waiting is cancelled, because
	 * there is no longer a credential to publish it with.
	 */
	public static function orphan_account( int $account_id, string $label ): void {
		global $wpdb;
		$in = Db::in_strings( self::OPEN );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = %s, error_code = 'account_disconnected', error_message = %s, claimed_by = '', claim_expires = NULL, updated_at = %s
				WHERE account_id = %d AND status IN {$in}", // phpcs:ignore WordPress.DB
				self::CANCELLED,
				'The account was disconnected before this post was published.',
				Clock::mysql(),
				$account_id
			)
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET account_label = %s WHERE account_id = %d AND account_label = %s', $label, $account_id, '' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Records that the WordPress post behind these rows was deleted.
	 *
	 * Nothing is removed from any platform: a post already published stays published, which is
	 * what the owner asked for. Anything not yet published is cancelled, because there is no
	 * longer an article for a reader to be sent to.
	 */
	public static function on_post_deleted( int $wp_post_id ): int {
		global $wpdb;
		$in      = Db::in_strings( self::OPEN );
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = %s, error_code = 'article_deleted', error_message = %s, claimed_by = '', claim_expires = NULL, updated_at = %s
				WHERE wp_post_id = %d AND status IN {$in}", // phpcs:ignore WordPress.DB
				self::CANCELLED,
				'The WordPress article was deleted before this post was published. Posts already published on social media were not changed.',
				Clock::mysql(),
				$wp_post_id
			)
		);
		return (int) $changed;
	}

	/* ---- Figures for the dashboard ---- */

	/**
	 * How many rows are in each state.
	 *
	 * @return array<string,int>
	 */
	public static function counts( array $filters = array() ): array {
		global $wpdb;
		$where = self::where( $filters );
		$rows  = $wpdb->get_results( 'SELECT status, COUNT(*) AS c FROM ' . self::table() . " WHERE {$where} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array_fill_keys( array( self::PENDING, self::AWAITING, self::SCHEDULED, self::PUBLISHING, self::PUBLISHED, self::RECONCILING, self::FAILED, self::CANCELLED, self::SKIPPED ), 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['c'];
		}
		$out['total'] = array_sum( $out );
		return $out;
	}

	/**
	 * Builds a WHERE clause for the history table from the dashboard filters.
	 *
	 * @param array $f platform, status, account_id, wp_post_id, from, to.
	 */
	public static function where( array $f ): string {
		global $wpdb;
		$where = array( '1=1' );
		if ( ! empty( $f['platform'] ) && Platforms::exists( (string) $f['platform'] ) ) {
			$where[] = $wpdb->prepare( 'platform = %s', $f['platform'] );
		}
		if ( ! empty( $f['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $f['status'] );
		}
		if ( ! empty( $f['account_id'] ) ) {
			$where[] = $wpdb->prepare( 'account_id = %d', $f['account_id'] );
		}
		if ( ! empty( $f['wp_post_id'] ) ) {
			$where[] = $wpdb->prepare( 'wp_post_id = %d', $f['wp_post_id'] );
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $f['from'] );
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $f['to'] );
		}
		return implode( ' AND ', $where );
	}

	/**
	 * One page of the history table.
	 *
	 * @return array[]
	 */
	public static function query( array $filters, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$where = self::where( $filters );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	public static function count( array $filters ): int {
		global $wpdb;
		$where = self::where( $filters );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * How many posts this account published in the last 24 hours, to compare against the
	 * platform's own published cap before sending another.
	 */
	public static function published_last_24h( int $account_id ): int {
		global $wpdb;
		$since = Clock::mysql( Clock::now() - DAY_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE account_id = %d AND status = %s AND published_at >= %s', $account_id, self::PUBLISHED, $since ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Removes old closed rows, leaving everything still in progress alone.
	 */
	public static function prune( int $days ): int {
		global $wpdb;
		$cutoff  = Clock::mysql( Clock::now() - ( max( 7, $days ) * DAY_IN_SECONDS ) );
		$in      = Db::in_strings( array( self::FAILED, self::CANCELLED, self::SKIPPED ) );
		$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN {$in} AND updated_at < %s LIMIT 500", $cutoff ) ); // phpcs:ignore WordPress.DB
		return (int) $removed;
	}

	public static function status_label( string $status ): string {
		$map = array(
			self::PENDING     => 'Preparing',
			self::AWAITING    => 'Waiting for approval',
			self::SCHEDULED   => 'Scheduled',
			self::PUBLISHING  => 'Publishing',
			self::PUBLISHED   => 'Published',
			self::RECONCILING => 'Being checked',
			self::FAILED      => 'Failed',
			self::CANCELLED   => 'Cancelled',
			self::SKIPPED     => 'Skipped',
		);
		return $map[ $status ] ?? $status;
	}
}
