<?php
/**
 * Minimal test harness. No dependencies. Runs inside a real WordPress install.
 */

use ZZZCopy\Automation;
use ZZZCopy\Db;
use ZZZCopy\Schema;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

const ZZZT_MOCK = 'http://127.0.0.1:8091';

$GLOBALS['zzzt_results'] = array();
$GLOBALS['zzzt_now']     = null;

class ZZZT_Fail extends Exception {}

function t_eq( $expected, $actual, string $label = '' ): void {
	if ( $expected !== $actual ) {
		throw new ZZZT_Fail( ( $label ? "$label: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function t_true( $value, string $label = '' ): void {
	if ( true !== (bool) $value ) {
		throw new ZZZT_Fail( ( $label ?: 'expected true' ) . ', got ' . var_export( $value, true ) );
	}
}

function t_false( $value, string $label = '' ): void {
	if ( false !== (bool) $value ) {
		throw new ZZZT_Fail( ( $label ?: 'expected false' ) . ', got ' . var_export( $value, true ) );
	}
}

function t_contains( string $needle, string $haystack, string $label = '' ): void {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new ZZZT_Fail( ( $label ? "$label: " : '' ) . "did not find '$needle' in '" . substr( $haystack, 0, 400 ) . "'" );
	}
}

function t_not_contains( string $needle, string $haystack, string $label = '' ): void {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new ZZZT_Fail( ( $label ? "$label: " : '' ) . "found forbidden '$needle'" );
	}
}

/**
 * Registers and runs one test against a clean plugin state.
 *
 * @param string   $id    Requirement ids this covers.
 * @param string   $name  What is being checked.
 * @param callable $fn    Test body.
 */
function t( string $id, string $name, callable $fn ): void {
	$filter = getenv( 'ZZZT_FILTER' );
	if ( $filter && false === stripos( $id . ' ' . $name, $filter ) ) {
		return;
	}

	zzzt_reset();
	$start   = microtime( true );
	$status  = 'PASS';
	$message = '';
	$php_issues = array();

	set_error_handler(
		static function ( $severity, $text, $file, $line ) use ( &$php_issues ) {
			if ( false !== strpos( str_replace( '\\', '/', $file ), 'zzz-copy-das-sobur' ) && ( $severity & ( E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE ) ) ) {
				$php_issues[] = basename( $file ) . ":$line $text";
			}
			return false;
		}
	);

	try {
		$fn();
		if ( $php_issues ) {
			throw new ZZZT_Fail( 'PHP issue: ' . implode( ' | ', array_slice( $php_issues, 0, 3 ) ) );
		}
	} catch ( ZZZT_Fail $e ) {
		$status  = 'FAIL';
		$message = $e->getMessage();
	} catch ( Throwable $e ) {
		$status  = 'ERROR';
		$message = get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
	restore_error_handler();

	$GLOBALS['zzzt_results'][] = array(
		'id'      => $id,
		'name'    => $name,
		'status'  => $status,
		'message' => $message,
		'ms'      => (int) ( ( microtime( true ) - $start ) * 1000 ),
	);
	printf( "%-5s %-16s %s%s\n", $status, $id, $name, $message ? "\n      -> $message" : '' );
}

/**
 * Puts the plugin back to a fresh install state.
 */
function zzzt_reset(): void {
	global $wpdb;
	$GLOBALS['zzzt_now'] = null;
	remove_all_actions( 'zzzc_after_post_created' );
	remove_all_filters( 'wp_insert_post_empty_content' );

	foreach ( array( 'categories', 'source_items', 'article_jobs', 'article_content', 'publishing_queue', 'slots', 'generation_usage', 'events', 'post_links', 'prompt_versions', 'jobs', 'locks', 'image_history', 'image_runs', 'authors' ) as $table ) {
		// TRUNCATE can time out on a database lock, so DELETE is used. The plugin never runs TRUNCATE.
		$wpdb->query( 'DELETE FROM ' . Db::table( $table ) );
		$wpdb->query( 'ALTER TABLE ' . Db::table( $table ) . ' AUTO_INCREMENT = 1' );
	}
	foreach ( array( 'zzzc_settings', 'zzzc_state', 'zzzc_secret', 'zzzc_active_prompts', 'zzzc_heartbeat', 'zzzc_model_check', 'zzzc_cron_token' ) as $option ) {
		delete_option( $option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_%'" );
	wp_cache_flush();
	Settings::flush_cache();
	Schema::install();

	Settings::update(
		array(
			'host_gap_seconds'   => 0,
			'google_gap_seconds' => 1,
			'ai_timeout'         => 5,
			// Every post needs an image. The mock Commons gives a fresh, usable photo to any search, and
			// scoring has its own tests in test-5-images.php, so the minimum score is low here.
			'image_min_score'       => 1,
			// Earlier test files cover the workflow with Past-Hour Posting off. test-6-past-hour.php turns it on.
			'past_hour_enabled'     => 0,
			'image_api_gap_seconds' => 0,
		)
	);

	wp_clear_scheduled_hook( 'zzzc_tick' );

	// Posts are credited only to an active author. The test administrator is the one, as on an upgraded site.
	ZZZCopy\Authors::insert_active( (int) $GLOBALS['zzzt_admin'] );
	Settings::update( array( 'post_author' => (int) $GLOBALS['zzzt_admin'] ) );
	ZZZCopy\Authors::flush_labels();

	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zzzc_image_source'" ) as $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
	}

	$posts = get_posts(
		array(
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_zzzc_job_uid',
		)
	);
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_content_filtered LIKE 'zzzc:%'" ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	foreach ( get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
		wp_delete_term( (int) $term_id, 'post_tag' );
	}

	// WordPress refuses to delete the default category, so this leaves only that one.
	foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
		wp_delete_term( (int) $term_id, 'category' );
	}

	mock_state( array() );
	@unlink( __DIR__ . '/mock-state/requests.jsonl' );
}

function mock_state( array $state ): void {
	if ( ! is_dir( __DIR__ . '/mock-state' ) ) {
		mkdir( __DIR__ . '/mock-state' );
	}
	file_put_contents( __DIR__ . '/mock-state/state.json', json_encode( $state ), LOCK_EX );
}

function mock_requests( string $path = 'chat/completions' ): array {
	$file = __DIR__ . '/mock-state/requests.jsonl';
	if ( ! is_file( $file ) ) {
		return array();
	}
	$out = array();
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$row = json_decode( $line, true );
		if ( $row && $row['path'] === $path ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * A fixed moment: the given local time in the plugin timezone.
 */
function set_local_now( string $local, string $zone = '+10:30' ): int {
	$ts                  = ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp();
	$GLOBALS['zzzt_now'] = $ts;
	return $ts;
}

function clear_now(): void {
	$GLOBALS['zzzt_now'] = null;
}

/**
 * Makes the plugin ready to start, using only the first category with a local feed.
 */
function ready_plugin( array $items = array(), string $publish_mode = 'draft' ): int {
	Secrets::set_api_key( 'sk-or-v1-testkey-abcdef123456' );
	Settings::update(
		array(
			'publish_mode'         => $publish_mode,
			'category_auto_create' => 1,
		)
	);

	global $wpdb;
	$table = Db::table( 'categories' );
	$first = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE name = 'Business'" );
	$wpdb->query( "UPDATE {$table} SET enabled = 0" );
	$wpdb->update(
		$table,
		array(
			'enabled'     => 1,
			'source_mode' => 'feeds',
			'feed_urls'   => ZZZT_MOCK . '/feed.xml',
			'topic_urls'  => '',
		),
		array( 'id' => $first )
	);

	mock_state( array( 'feeds' => array( 'feed' => $items ) ) );
	return $first;
}

function feed_item( string $slug, string $title = '', int $age_seconds = 600 ): array {
	return array(
		'title'       => $title ?: 'Story about ' . str_replace( '-', ' ', $slug ) . ' ' . md5( $slug ),
		'link'        => ZZZT_MOCK . '/article/' . $slug,
		'guid'        => 'guid-' . $slug,
		'pubDate'     => gmdate( 'r', ( $GLOBALS['zzzt_now'] ?? time() ) - $age_seconds ),
		'description' => 'Summary of ' . $slug,
	);
}

/**
 * Runs worker ticks until jobs stop being due or the limit is reached.
 * Jobs waiting on a retry delay are pulled forward when $pull_forward is true.
 */
function run_ticks( int $limit = 12, bool $pull_forward = true ): void {
	global $wpdb;
	$jobs = Db::table( 'jobs' );
	for ( $i = 0; $i < $limit; $i++ ) {
		if ( $pull_forward ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET run_at = %s WHERE status = 'pending' AND job_type <> 'usage_fetch'", \ZZZCopy\Clock::mysql( \ZZZCopy\Clock::now() - 1 ) ) );
		}
		$summary = \ZZZCopy\Runner::tick( 'cli' );
		if ( ! $summary['ran'] && 'stopped' === $summary['reason'] ) {
			return;
		}
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'pending' AND job_type <> 'usage_fetch'" );
		if ( 0 === $pending && 0 === (int) $summary['jobs'] ) {
			return;
		}
	}
}

function db_count( string $table, string $where = '1=1' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( $table ) . " WHERE $where" );
}

function job_status( int $id ): string {
	$job = \ZZZCopy\Lifecycle::job( $id );
	return $job ? (string) $job['status'] : 'NONE';
}

function make_job_from_url( int $category_id, string $url, string $headline = '' ): int {
	$record = array(
		'category_id'   => $category_id,
		'adapter'       => 'publisher_feed',
		'dedupe_key'    => sha1( $url . wp_rand() ),
		'headline'      => $headline ?: 'Headline for ' . $url . ' ' . wp_rand(),
		'headline_norm' => \ZZZCopy\Dedupe::normalize_headline( $headline ?: 'unique ' . md5( $url . wp_rand() ) ),
		'publisher'     => 'Mock News',
		'publisher_url' => ZZZT_MOCK,
		'source_url'    => $url,
		'resolved_url'  => $url,
		'url_hash'      => sha1( \ZZZCopy\Dedupe::normalize_url( $url ) ),
		'published_at'  => \ZZZCopy\Clock::mysql( \ZZZCopy\Clock::now() - 600 ),
		'discovered_at' => \ZZZCopy\Clock::mysql(),
		'status'        => 'DISCOVERED',
	);
	global $wpdb;
	$wpdb->insert( Db::table( 'source_items' ), $record );
	$record['id'] = (int) $wpdb->insert_id;
	return \ZZZCopy\Planner::create_article_job( $record );
}

function run_jobs_only( int $limit = 10 ): void {
	// Runs queued jobs without the discovery planner adding more.
	Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => \ZZZCopy\Clock::now() ) );
	Settings::update( array( 'daily_ai_limit' => 500 ) );
	run_ticks( $limit );
}
