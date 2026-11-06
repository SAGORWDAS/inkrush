<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Worker heartbeat and stale-job reporting.
 */
final class Health {

	private const OPTION = 'zzzc_heartbeat';

	/** No heartbeat for this long while automation is active means the worker has stalled. */
	private const STALE_AFTER = 300;

	public static function beat( string $source ): void {
		$real = time();
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();

		$data['at']     = $real;
		$data['source'] = $source;
		if ( 'server' === $source || 'cli' === $source ) {
			$data['server_at'] = $real;
		}
		update_option( self::OPTION, $data, false );
	}

	public static function status(): array {
		global $wpdb;
		wp_cache_delete( self::OPTION, 'options' );
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		$real = time();
		$at   = (int) ( $data['at'] ?? 0 );

		$jobs       = Db::table( 'jobs' );
		$queue      = Db::table( 'publishing_queue' );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$stale_jobs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'running' AND lease_until < %s", $now ) ); // phpcs:ignore WordPress.DB
		$stale_pub  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE queue_status = %s AND lock_expires < %s", Lifecycle::PUBLISHING, $now ) ); // phpcs:ignore WordPress.DB

		$active = Automation::is_active();
		$server = (int) ( $data['server_at'] ?? 0 );

		// A worker that just started has not had time to beat yet, so count from whichever is later.
		$since = max( $at, (int) Automation::get( 'started_at', 0 ) );

		return array(
			'last_beat'     => $at,
			'source'        => (string) ( $data['source'] ?? '' ),
			'age'           => $at ? $real - $at : null,
			'stale'         => $active && $real - $since > self::STALE_AFTER,
			'stale_jobs'    => $stale_jobs + $stale_pub,
			'server_cron'   => $server && $real - $server <= self::STALE_AFTER,
			'server_at'     => $server,
			'active'        => $active,
		);
	}
}
