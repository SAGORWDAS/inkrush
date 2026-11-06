<?php
/**
 * Real-internet check of the source pipeline, with no API key, no AI calls and no requests to Google.
 *
 * Part A: the plugin's publisher-feed adapter and retrieval job against the seeded feeds.
 * Part B: Google News RSS data saved earlier is replayed from disk. Its stories go through
 *         the publisher lookup and retrieval, which contact publishers only.
 * Part C: OpenRouter's real replies to a made-up key, and its public model list.
 *
 * Usage: php tests/live-sources.php <site dir> <saved xml dir>
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'localhost:8090';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

use ZZZCopy\Categories;
use ZZZCopy\Discovery;
use ZZZCopy\FeedParser;
use ZZZCopy\Http;
use ZZZCopy\Lifecycle;
use ZZZCopy\OpenRouter;
use ZZZCopy\Planner;
use ZZZCopy\Retrieval;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

global $wpdb;
$p = $wpdb->prefix . 'zzzc_';

// No request to Google can leave this script.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== stripos( (string) wp_parse_url( $url, PHP_URL_HOST ), 'google.com' ) ) {
			throw new RuntimeException( 'Blocked a request to Google: ' . $url );
		}
		return $pre;
	},
	10,
	3
);

Settings::update( array( 'host_gap_seconds' => 2, 'dedupe_window_days' => 14 ) );

function zzzl_retrieve( int $job_id ): array {
	for ( $i = 0; $i < 6; $i++ ) {
		$result = Retrieval::handle( array( 'id' => 0, 'payload' => array( 'article_job_id' => $job_id ), 'attempts' => 3, 'max_attempts' => 3 ) );
		if ( 'defer' !== $result->outcome ) {
			break;
		}
		sleep( max( 1, min( 5, $result->delay ) ) );
	}
	$job  = Lifecycle::job( $job_id );
	$item = Lifecycle::item( (int) $job['source_item_id'] );
	return array( $job, $item, $result );
}

function zzzl_row( array $job, array $item, $outcome ): string {
	$host = (string) wp_parse_url( (string) ( $item['resolved_url'] ?: $item['publisher_url'] ), PHP_URL_HOST );
	return sprintf( "%-18s %-16s %-24s words=%-5d %s | %s", $job['status'], $item['retrieval_status'] ?: '-', substr( $job['error_code'] ?: ( 'retry' === $outcome ? 'temporary' : '' ), 0, 24 ), (int) $item['source_word_count'], substr( $host, 0, 28 ), mb_substr( (string) $item['headline'], 0, 56 ) );
}

$tally = array();

echo "=== A. Publisher feeds, through the plugin's own adapter ===\n";
$feeds_ok = 0;
foreach ( Categories::all() as $category ) {
	foreach ( array_filter( explode( "\n", (string) $category['feed_urls'] ) ) as $feed ) {
		$host = (string) wp_parse_url( $feed, PHP_URL_HOST );
		delete_transient( 'zzzc_host_' . md5( $host ) );
		$fetched = Discovery::fetch( Discovery::FEED, $feed );
		printf( "%-14s %-30s feed=%s items=%d %s\n", $category['name'], $host, $fetched['status'], count( $fetched['items'] ), $fetched['code'] );
		if ( 'ok' !== $fetched['status'] || ! $fetched['items'] ) {
			continue;
		}
		$feeds_ok++;
		$counts = Discovery::ingest( $category, array_slice( $fetched['items'], 0, 3 ), Discovery::FEED, 'Publisher feed', 'live check' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}source_items WHERE status = 'DISCOVERED' AND category_id = %d ORDER BY id DESC LIMIT 1", $category['id'] ), ARRAY_A );
		foreach ( $rows as $row ) {
			$job_id = Planner::create_article_job( $row );
			list( $job, $item, $result ) = zzzl_retrieve( $job_id );
			echo '   ', zzzl_row( $job, $item, $result->outcome ), "\n";
			$tally['feed'][ $job['status'] ] = ( $tally['feed'][ $job['status'] ] ?? 0 ) + 1;
			if ( Lifecycle::AI_PENDING === $job['status'] && ! isset( $sample ) ) {
				$sample = $item;
			}
		}
	}
}

echo "\n=== B. Saved Google News RSS data, replayed from disk ===\n";
$map = array( 'Business', 'Technology', 'Entertainment', 'Sports', 'Science', 'Health' );
foreach ( $map as $index => $name ) {
	$file = $argv[2] . "/gn_$name.xml";
	if ( ! is_file( $file ) ) {
		echo "$name: saved file missing\n";
		continue;
	}
	$parsed   = FeedParser::parse( (string) file_get_contents( $file ) );
	$category = null;
	foreach ( Categories::all() as $row ) {
		if ( $row['name'] === $name ) {
			$category = $row;
		}
	}
	$first  = $parsed['items'][0] ?? array();
	$normal = $first ? Discovery::normalize_entry( $first, Discovery::GOOGLE ) : array();
	printf( "%-14s kind=%s items=%d google-link=%s publisher=%s source=%s\n", $name, $parsed['kind'], count( $parsed['items'] ), Discovery::is_google_news_link( (string) ( $first['link'] ?? '' ) ) ? 'yes' : 'no', $normal['publisher'] ?? '', $normal['publisher_url'] ?? '' );

	// Dates in the saved files are from earlier today, so these pass a one-week check.
	$category['recency'] = '7d';
	$counts              = Discovery::ingest( $category, array_slice( $parsed['items'], 0, 3 ), Discovery::GOOGLE, 'Google News topic (saved data)', 'live check' );
	$rows                = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}source_items WHERE status = 'DISCOVERED' AND category_id = %d AND adapter = %s ORDER BY id ASC LIMIT 3", $category['id'], Discovery::GOOGLE ), ARRAY_A );
	foreach ( $rows as $row ) {
		$job_id = Planner::create_article_job( $row );
		list( $job, $item, $result ) = zzzl_retrieve( $job_id );
		echo '   ', zzzl_row( $job, $item, $result->outcome ), "\n";
		$tally['google'][ $job['status'] ] = ( $tally['google'][ $job['status'] ] ?? 0 ) + 1;
		if ( Lifecycle::AI_PENDING === $job['status'] && ! isset( $sample ) ) {
			$sample = $item;
		}
	}
}

echo "\n=== Totals ===\n", wp_json_encode( $tally ), "\nFeeds readable: $feeds_ok\n";
if ( isset( $sample ) ) {
	$text = (string) $sample['source_text'];
	echo "\nSample of extracted text from ", $sample['publisher'], ' (', (int) $sample['source_word_count'], " words), first 300 characters, for checking quality only:\n", mb_substr( $text, 0, 300 ), "\n";
}

echo "\n=== C. OpenRouter real replies, using a made-up key and no credits ===\n";
Secrets::set_api_key( 'sk-or-v1-not-a-real-key-000000000000' );
$check = OpenRouter::verify_setup();
echo 'verify_setup: ok=', var_export( $check['ok'], true ), ' code=', $check['code'], "\n   ", $check['message'], "\n";
$chat = OpenRouter::chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), 'openai/gpt-6-luna', 16 );
echo 'chat: http=', $chat['http'], ' code=', $chat['code'], ' block=', var_export( $chat['block'], true ), "\n   ", $chat['message'], "\n";
echo 'key absent from stored reply: ', var_export( false === strpos( $chat['raw'], 'not-a-real-key' ), true ), "\n";
Secrets::clear_api_key();

$models = wp_remote_get( OpenRouter::base_url() . '/models', array( 'timeout' => 30 ) );
$data   = json_decode( (string) wp_remote_retrieve_body( $models ), true );
$found  = array_values( array_filter( (array) ( $data['data'] ?? array() ), static fn( $m ) => 'openai/gpt-6-luna' === ( $m['id'] ?? '' ) ) );
echo 'public model list: http=', wp_remote_retrieve_response_code( $models ), ' models=', count( (array) ( $data['data'] ?? array() ) ), ' openai/gpt-6-luna present=', var_export( (bool) $found, true ), "\n";
if ( $found ) {
	echo '   listed price per token: ', wp_json_encode( array( 'prompt' => $found[0]['pricing']['prompt'] ?? null, 'completion' => $found[0]['pricing']['completion'] ?? null ) ), ' context=', $found[0]['context_length'] ?? '', "\n";
}
