<?php
/**
 * One of several processes racing to reserve the same image. Started by test-5-images.php.
 * Arguments: start time, candidate JSON, license JSON, article job id. Prints the reserved id.
 */

define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'localhost:8090';
$_SERVER['REQUEST_URI'] = '/';

require getenv( 'ZZZC_WP' ) . '/wp-load.php';

$start = (float) $argv[1];
while ( microtime( true ) < $start ) {
	usleep( 500 );
}

echo (int) ZZZCopy\Images\ImageHistory::reserve( json_decode( $argv[2], true ), json_decode( $argv[3], true ), (int) $argv[4], 'Race' );
