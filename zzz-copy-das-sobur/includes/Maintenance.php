<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the retention settings once a day. WordPress posts are never touched.
 */
final class Maintenance {

	public static function daily(): void {
		global $wpdb;
		$now = Clock::now();

		$items   = Db::table( 'source_items' );
		$events  = Db::table( 'events' );
		$jobs    = Db::table( 'jobs' );
		$article = Db::table( 'article_jobs' );
		$slots   = Db::table( 'slots' );

		// Stored publisher text is removed once its article has moved on.
		$source_cutoff = Clock::mysql( $now - (int) Settings::get( 'retention_source_days' ) * DAY_IN_SECONDS );
		$cleared       = $wpdb->query( $wpdb->prepare( "UPDATE {$items} SET source_text = NULL WHERE source_text IS NOT NULL AND retrieved_at < %s", $source_cutoff ) ); // phpcs:ignore WordPress.DB

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE status IN ('FILTERED_OUT','DUPLICATE') AND discovered_at < %s", $source_cutoff ) ); // phpcs:ignore WordPress.DB

		if ( PastHour::enabled() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE status = 'DISCOVERED' AND discovered_at < %s", $source_cutoff ) ); // phpcs:ignore WordPress.DB
		}

		$event_cutoff = Clock::mysql( $now - (int) Settings::get( 'retention_events_days' ) * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE created_at < %s", $event_cutoff ) ); // phpcs:ignore WordPress.DB

		// Diagnostics from failed articles are cleared. The records stay for history.
		$failed_cutoff = Clock::mysql( $now - (int) Settings::get( 'retention_failed_days' ) * DAY_IN_SECONDS );
		$in            = Db::in_strings( array_merge( Lifecycle::ATTENTION, array( Lifecycle::SKIPPED, Lifecycle::CANCELLED, Lifecycle::DUPLICATE ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$article} SET raw_response = NULL WHERE status IN {$in} AND updated_at < %s", $failed_cutoff ) ); // phpcs:ignore WordPress.DB

		$job_cutoff = Clock::mysql( $now - 14 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status IN ('done','failed','cancelled') AND updated_at < %s", $job_cutoff ) ); // phpcs:ignore WordPress.DB

		$slot_cutoff = gmdate( 'Y-m-d', $now - 180 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$slots} WHERE local_date < %s", $slot_cutoff ) ); // phpcs:ignore WordPress.DB

		Events::log( 'maintenance', 'info', sprintf( 'Daily maintenance finished. Stored source text cleared from %d records.', (int) $cleared ) );
	}
}
