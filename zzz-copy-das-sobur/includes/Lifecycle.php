<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Article job states and the only transitions allowed between them.
 */
final class Lifecycle {

	public const RETRIEVAL_PENDING = 'RETRIEVAL_PENDING';
	public const RETRIEVED         = 'RETRIEVED';
	public const PARTIAL           = 'PARTIAL';
	public const BLOCKED           = 'BLOCKED';
	public const RETRIEVAL_FAILED  = 'RETRIEVAL_FAILED';
	public const DUPLICATE         = 'DUPLICATE';
	public const AI_PENDING        = 'AI_PENDING';
	public const AI_RUNNING        = 'AI_RUNNING';
	public const AI_FAILED         = 'AI_FAILED';
	public const GENERATED         = 'GENERATED';
	public const VALIDATING        = 'VALIDATING';
	public const VALIDATED         = 'VALIDATED';
	public const VALIDATION_FAILED = 'VALIDATION_FAILED';
	public const HELD              = 'HELD';
	public const QUEUED            = 'QUEUED';
	public const SCHEDULED         = 'SCHEDULED';
	public const PUBLISHING        = 'PUBLISHING';
	public const DRAFT_CREATED     = 'DRAFT_CREATED';
	public const PUBLISHED         = 'PUBLISHED';
	public const PUBLISH_FAILED    = 'PUBLISH_FAILED';
	public const SKIPPED           = 'SKIPPED';
	public const CANCELLED         = 'CANCELLED';
	public const ARCHIVED          = 'ARCHIVED';

	/** States counted as "in the pipeline" when deciding how many more stories to start. */
	public const PIPELINE = array( self::RETRIEVAL_PENDING, self::RETRIEVED, self::AI_PENDING, self::AI_RUNNING, self::GENERATED, self::VALIDATING, self::VALIDATED, self::QUEUED, self::SCHEDULED, self::PUBLISHING );

	/** States an administrator may need to look at. */
	public const ATTENTION = array( self::PARTIAL, self::BLOCKED, self::RETRIEVAL_FAILED, self::AI_FAILED, self::VALIDATION_FAILED, self::HELD, self::PUBLISH_FAILED );

	/** Story states treated as live for duplicate checks. */
	public const LIVE = array( self::RETRIEVAL_PENDING, self::RETRIEVED, self::AI_PENDING, self::AI_RUNNING, self::GENERATED, self::VALIDATING, self::VALIDATED, self::QUEUED, self::SCHEDULED, self::PUBLISHING, self::DRAFT_CREATED, self::PUBLISHED, self::ARCHIVED );

	private const TRANSITIONS = array(
		self::RETRIEVAL_PENDING => array( self::RETRIEVED, self::PARTIAL, self::BLOCKED, self::RETRIEVAL_FAILED, self::DUPLICATE, self::SKIPPED, self::CANCELLED ),
		self::RETRIEVED         => array( self::AI_PENDING, self::DUPLICATE, self::SKIPPED, self::CANCELLED ),
		self::PARTIAL           => array( self::RETRIEVAL_PENDING, self::HELD, self::SKIPPED ),
		self::BLOCKED           => array( self::RETRIEVAL_PENDING, self::HELD, self::SKIPPED ),
		self::RETRIEVAL_FAILED  => array( self::RETRIEVAL_PENDING, self::SKIPPED ),
		self::AI_PENDING        => array( self::AI_RUNNING, self::AI_FAILED, self::SKIPPED, self::CANCELLED ),
		self::AI_RUNNING        => array( self::GENERATED, self::AI_FAILED, self::AI_PENDING, self::SKIPPED ),
		self::AI_FAILED         => array( self::AI_PENDING, self::SKIPPED ),
		self::GENERATED         => array( self::VALIDATING, self::AI_PENDING, self::AI_FAILED, self::HELD, self::SKIPPED ),
		self::VALIDATING        => array( self::VALIDATED, self::VALIDATION_FAILED, self::HELD, self::AI_PENDING, self::SKIPPED ),
		self::VALIDATED         => array( self::QUEUED, self::DUPLICATE, self::SKIPPED ),
		self::VALIDATION_FAILED => array( self::AI_PENDING, self::HELD, self::SKIPPED ),
		self::HELD              => array( self::RETRIEVAL_PENDING, self::AI_PENDING, self::SKIPPED ),
		self::QUEUED            => array( self::SCHEDULED, self::PUBLISHING, self::PUBLISH_FAILED, self::DUPLICATE, self::SKIPPED ),
		self::SCHEDULED         => array( self::QUEUED, self::PUBLISHING, self::SKIPPED ),
		self::PUBLISHING        => array( self::PUBLISHED, self::DRAFT_CREATED, self::PUBLISH_FAILED, self::QUEUED, self::DUPLICATE, self::SKIPPED ),
		self::PUBLISH_FAILED    => array( self::QUEUED, self::SKIPPED ),
		self::DRAFT_CREATED     => array( self::ARCHIVED ),
		self::PUBLISHED         => array( self::ARCHIVED ),
		self::DUPLICATE         => array( self::SKIPPED ),
		self::SKIPPED           => array(),
		self::CANCELLED         => array(),
		self::ARCHIVED          => array(),
	);

	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function all_statuses(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Moves an article job to a new state. Fails if the move is invalid or another worker moved it first.
	 *
	 * @param int    $job_id Article job id.
	 * @param string $to     Target state.
	 * @param array  $fields Extra article_jobs columns to update.
	 */
	public static function transition( int $job_id, string $to, array $fields = array() ): bool {
		global $wpdb;
		$table = Db::table( 'article_jobs' );
		$from  = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job_id ) ); // phpcs:ignore WordPress.DB

		if ( ! $from || ! self::can( $from, $to ) ) {
			Events::log(
				'scheduler',
				'warning',
				sprintf( 'Blocked an invalid state change from %s to %s.', $from ?: 'unknown', $to ),
				array(
					'article_job_id' => $job_id,
					'error_code'     => 'invalid_transition',
				)
			);
			return false;
		}

		$fields['status']     = $to;
		$fields['updated_at'] = Clock::mysql();

		$updated = $wpdb->update(
			$table,
			$fields,
			array(
				'id'     => $job_id,
				'status' => $from,
			)
		);

		return 1 === (int) $updated;
	}

	public static function job( int $job_id ): ?array {
		global $wpdb;
		$table = Db::table( 'article_jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function item( int $item_id ): ?array {
		global $wpdb;
		$table = Db::table( 'source_items' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function content( int $job_id ): ?array {
		global $wpdb;
		$table = Db::table( 'article_content' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE article_job_id = %d", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}
}
