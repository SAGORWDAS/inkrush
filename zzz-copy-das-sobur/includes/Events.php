<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Structured activity log with secret redaction.
 */
final class Events {

	public const STAGES = array( 'discovery', 'filtering', 'dedupe', 'retrieval', 'ai', 'validation', 'queue', 'image', 'publishing', 'past_hour', 'access', 'scheduler', 'control', 'config', 'maintenance' );

	/**
	 * @param string $stage    One of self::STAGES.
	 * @param string $severity info, warning or error.
	 * @param string $message  Short diagnostic text.
	 * @param array  $ctx      Optional: status, error_code, category_id, source_item_id, article_job_id, wp_post_id, author_user_id, attempt, context.
	 */
	public static function log( string $stage, string $severity, string $message, array $ctx = array() ): void {
		global $wpdb;

		$actor = 0;
		if ( function_exists( 'get_current_user_id' ) ) {
			$actor = (int) get_current_user_id();
		}

		$context = isset( $ctx['context'] ) ? Secrets::redact( (string) wp_json_encode( $ctx['context'] ) ) : null;

		$wpdb->insert(
			Db::table( 'events' ),
			array(
				'created_at'     => Clock::mysql(),
				'stage'          => $stage,
				'severity'       => $severity,
				'status'         => (string) ( $ctx['status'] ?? '' ),
				'error_code'     => (string) ( $ctx['error_code'] ?? '' ),
				'message'        => Secrets::redact( $message ),
				'category_id'    => (int) ( $ctx['category_id'] ?? 0 ),
				'source_item_id' => (int) ( $ctx['source_item_id'] ?? 0 ),
				'article_job_id' => (int) ( $ctx['article_job_id'] ?? 0 ),
				'wp_post_id'     => (int) ( $ctx['wp_post_id'] ?? 0 ),
				'actor_user_id'  => $actor,
				'author_user_id' => (int) ( $ctx['author_user_id'] ?? 0 ),
				'attempt'        => (int) ( $ctx['attempt'] ?? 0 ),
				'context'        => $context,
			)
		);
	}

	/**
	 * Builds a WHERE clause from log filters.
	 *
	 * @param array $f Filters: from, to (local dates are converted by the caller to UTC), category_id, stage, status, severity, job, post, user (who acted or who was credited).
	 */
	public static function where( array $f ): string {
		global $wpdb;
		$where = array( '1=1' );
		if ( ! empty( $f['from'] ) ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $f['from'] );
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $f['to'] );
		}
		if ( ! empty( $f['category_id'] ) ) {
			$where[] = $wpdb->prepare( 'category_id = %d', $f['category_id'] );
		}
		if ( ! empty( $f['stage'] ) ) {
			$where[] = $wpdb->prepare( 'stage = %s', $f['stage'] );
		}
		if ( ! empty( $f['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $f['status'] );
		}
		if ( ! empty( $f['severity'] ) ) {
			$where[] = $wpdb->prepare( 'severity = %s', $f['severity'] );
		}
		if ( ! empty( $f['job'] ) ) {
			$where[] = $wpdb->prepare( 'article_job_id = %d', $f['job'] );
		}
		if ( ! empty( $f['post'] ) ) {
			$where[] = $wpdb->prepare( 'wp_post_id = %d', $f['post'] );
		}
		if ( ! empty( $f['user'] ) ) {
			$where[] = $wpdb->prepare( '( actor_user_id = %d OR author_user_id = %d )', $f['user'], $f['user'] );
		}
		return implode( ' AND ', $where );
	}

	public static function query( array $filters, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = Db::table( 'events' );
		$where = self::where( $filters );
		// $where is built only from prepared fragments above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function count( array $filters ): int {
		global $wpdb;
		$table = Db::table( 'events' );
		$where = self::where( $filters );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}
}
