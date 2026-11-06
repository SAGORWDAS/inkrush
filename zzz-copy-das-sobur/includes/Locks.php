<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Named locks in the database, with expiry so a crashed worker cannot hold one forever.
 */
final class Locks {

	/**
	 * @return string|false Owner token when acquired.
	 */
	public static function acquire( string $name, int $ttl_seconds ) {
		global $wpdb;
		$table   = Db::table( 'locks' );
		$owner   = wp_generate_uuid4();
		$real    = time();
		$expires = gmdate( 'Y-m-d H:i:s', $real + $ttl_seconds );
		$now     = gmdate( 'Y-m-d H:i:s', $real );

		$previous = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (lock_name, owner, expires_at) VALUES (%s, %s, %s)", $name, $owner, $expires ) ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $previous );

		if ( 1 === (int) $inserted ) {
			return $owner;
		}

		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET owner = %s, expires_at = %s WHERE lock_name = %s AND expires_at < %s", $owner, $expires, $name, $now ) ); // phpcs:ignore WordPress.DB

		return 1 === (int) $updated ? $owner : false;
	}

	public static function release( string $name, string $owner ): void {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'locks' ),
			array(
				'lock_name' => $name,
				'owner'     => $owner,
			)
		);
	}

	public static function is_held( string $name ): bool {
		global $wpdb;
		$table = Db::table( 'locks' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE lock_name = %s AND expires_at >= %s", $name, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB
	}
}
