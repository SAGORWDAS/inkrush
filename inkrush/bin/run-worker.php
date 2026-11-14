<?php
/**
 * Runs one worker tick from the command line. For server cron:
 *
 *   * * * * * php /path/to/wp-content/plugins/zzz-copy-das-sobur/bin/run-worker.php
 *
 * It refuses to run from a web request.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'This script runs from the command line only.' );
}

$zzzc_dir = __DIR__;
while ( ! file_exists( $zzzc_dir . '/wp-load.php' ) ) {
	$zzzc_parent = dirname( $zzzc_dir );
	if ( $zzzc_parent === $zzzc_dir ) {
		fwrite( STDERR, "Could not find wp-load.php above this plugin.\n" );
		exit( 1 );
	}
	$zzzc_dir = $zzzc_parent;
}

// Keep the bundled WP-Cron spawner out of this process. The tick below does the work.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

require $zzzc_dir . '/wp-load.php';

if ( ! class_exists( 'ZZZCopy\\Runner' ) ) {
	fwrite( STDERR, "InkRush is not active on this site.\n" );
	exit( 1 );
}

$zzzc_summary = ZZZCopy\Runner::tick( 'cli' );
echo wp_json_encode( $zzzc_summary ), "\n";
