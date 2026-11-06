<?php
/**
 * Test entry point. Loads real WordPress against the test_ table prefix.
 *
 * Usage: ZZZC_TEST=1 ZZZC_WP=<site dir> php tests/run.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$site = getenv( 'ZZZC_WP' );
if ( ! $site || ! is_file( $site . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set ZZZC_WP to the WordPress directory.\n" );
	exit( 2 );
}

define( 'WP_INSTALLING_TESTS', true );
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'localhost:8090';
$_SERVER['REQUEST_URI'] = '/';

// The WordPress install step needs this before wp-load when tables do not exist yet.
if ( ! defined( 'WP_INSTALLING' ) && getenv( 'ZZZC_TEST_INSTALL' ) ) {
	define( 'WP_INSTALLING', true );
}

require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'ZZZ Test', 'zzzadmin', 'test@example.test', false, '', wp_generate_password( 24 ) );
	update_option( 'permalink_structure', '' );
	echo "Installed test WordPress.\n";
}
if ( getenv( 'ZZZC_TEST_INSTALL' ) ) {
	exit( 0 );
}

$plugin_file = 'zzz-copy-das-sobur/zzz-copy-das-sobur.php';
if ( ! is_plugin_active( $plugin_file ) ) {
	$activated = activate_plugin( $plugin_file );
	if ( is_wp_error( $activated ) ) {
		fwrite( STDERR, 'Activation failed: ' . $activated->get_error_message() . "\n" );
		exit( 3 );
	}
	// The plugin was not active when this request began. WordPress loads it on the next request; do that here.
	require_once WP_PLUGIN_DIR . '/' . $plugin_file;
	ZZZCopy\Plugin::boot();
}

add_filter( 'zzzc_now', static fn( $now ) => $GLOBALS['zzzt_now'] ?? $now );
add_filter( 'zzzc_http_allowed_hosts', static fn() => array( '127.0.0.1:8091' ) );
add_filter( 'zzzc_openrouter_base_url', static fn() => ZZZT_MOCK . '/openrouter/api/v1' );
add_filter( 'zzzc_commons_api_url', static fn() => ZZZT_MOCK . '/commons/api.php' );
add_filter( 'zzzc_commons_media_hosts', static fn() => array( '127.0.0.1:8091' ) );

require __DIR__ . '/lib.php';

// Administrator for screens, and the post author.
$admin = get_user_by( 'login', 'zzzadmin' );
if ( ! $admin ) {
	$uid   = wp_create_user( 'zzzadmin', wp_generate_password( 24 ), 'test@example.test' );
	$admin = new WP_User( $uid );
	$admin->set_role( 'administrator' );
}
$GLOBALS['zzzt_admin'] = (int) $admin->ID;

$started = microtime( true );

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	echo "\n=== " . basename( $file ) . " ===\n";
	require $file;
}

$results = $GLOBALS['zzzt_results'];
$counts  = array( 'PASS' => 0, 'FAIL' => 0, 'ERROR' => 0 );
foreach ( $results as $row ) {
	$counts[ $row['status'] ]++;
}

$report = array(
	'wordpress' => get_bloginfo( 'version' ),
	'php'       => PHP_VERSION,
	'database'  => $GLOBALS['wpdb']->db_server_info(),
	'seconds'   => round( microtime( true ) - $started, 1 ),
	'counts'    => $counts,
	'tests'     => $results,
);
file_put_contents( __DIR__ . '/results.json', json_encode( $report, JSON_PRETTY_PRINT ) );

printf( "\n%d passed, %d failed, %d errors in %.1fs (WordPress %s, PHP %s, %s)\n", $counts['PASS'], $counts['FAIL'], $counts['ERROR'], $report['seconds'], $report['wordpress'], $report['php'], $report['database'] );
exit( $counts['FAIL'] + $counts['ERROR'] > 0 ? 1 : 0 );
