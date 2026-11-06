<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Small helper for plugin table names.
 */
final class Db {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'zzzc_' . $name;
	}

	/**
	 * Builds an IN (...) list of quoted strings for a prepared query.
	 *
	 * @param string[] $values Values to place in the list.
	 */
	public static function in_strings( array $values ): string {
		global $wpdb;
		if ( ! $values ) {
			return "('')";
		}
		$quoted = array_map(
			static function ( $value ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $value );
			},
			$values
		);
		return '(' . implode( ',', $quoted ) . ')';
	}
}
