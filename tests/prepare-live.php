<?php
/**
 * Clears mock data from the local site and sets small, cheap limits for the live test.
 * Usage: php tests/prepare-live.php <site dir>
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'localhost:8090';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

global $wpdb;
$p = $wpdb->prefix . 'zzzc_';

foreach ( $wpdb->get_col( "SELECT wp_post_id FROM {$p}post_links" ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
foreach ( get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
	wp_delete_term( (int) $term_id, 'post_tag' );
}
foreach ( array( 'source_items', 'article_jobs', 'article_content', 'publishing_queue', 'slots', 'generation_usage', 'events', 'post_links', 'jobs', 'locks' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$p}{$table}" );
}
delete_option( 'zzzc_heartbeat' );
delete_option( 'zzzc_model_check' );
delete_option( 'zzzc_state' );
ZZZCopy\Secrets::clear_api_key();
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_%'" );

// Business back to its supplied values, and first in the order.
ZZZCopy\Categories::update(
	1,
	array(
		'source_mode'       => 'both',
		'topic_urls'        => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRGx6TVdZU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
		'feed_urls'         => "https://feeds.bbci.co.uk/news/business/rss.xml\nhttps://www.cnbc.com/id/10001147/device/rss/rss.html",
		'excluded_keywords' => '',
	)
);
ZZZCopy\Categories::move( 1, -1 );

$local = new DateTimeImmutable( 'now', new DateTimeZone( '+10:30' ) );
$first = $local->modify( '+12 minutes' )->format( 'H:i' );

$result = ZZZCopy\Settings::update(
	array(
		'first_time'          => $first,
		'interval_hours'      => 1,
		'daily_target'        => 2,
		'daily_cap'           => 2,
		'category_buffer'     => 1,
		'daily_ai_limit'      => 4,
		'rotation_pool'       => array(),
		'catchup_gap_minutes' => 30,
		'publish_mode'        => 'draft',
	)
);
ZZZCopy\Schedule::rebuild_future();
echo 'first slot ', $first, ' UTC+10:30. AI limit 4 articles. Errors: ', wp_json_encode( $result['errors'] ), "\n";
echo 'categories: ', implode( ', ', array_column( ZZZCopy\Categories::all(), 'name' ) ), "\n";
echo 'key present: ', var_export( ZZZCopy\Secrets::has_api_key(), true ), "\n";
