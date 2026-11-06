<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * One source of current time, so schedule boundaries can be tested.
 */
final class Clock {

	public static function now(): int {
		return (int) apply_filters( 'zzzc_now', time() );
	}

	/**
	 * UTC datetime string for database columns.
	 */
	public static function mysql( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ?? self::now() );
	}

	public static function to_timestamp( ?string $mysql_utc ): int {
		if ( ! $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return 0;
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return false === $ts ? 0 : $ts;
	}
}
