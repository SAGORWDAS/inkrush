<?php
/**
 * Prints facts from the local site's database, for checks during browser testing.
 * Usage: php tests/inspect-site.php <site dir> <what>
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'localhost:8090';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

global $wpdb;
$p    = $wpdb->prefix . 'zzzc_';
$what = $argv[2] ?? 'state';

switch ( $what ) {
	case 'prompts':
		foreach ( $wpdb->get_results( "SELECT id, prompt_type, content_sha256, CHAR_LENGTH(content) AS chars, LENGTH(content) AS bytes FROM {$p}prompt_versions ORDER BY id", ARRAY_A ) as $row ) {
			echo implode( ' | ', $row ), "\n";
		}
		echo 'active: ', json_encode( get_option( 'zzzc_active_prompts' ) ), "\n";
		foreach ( array( 'article-prompt.txt', 'tag-prompt.txt' ) as $file ) {
			echo $file, ' sha256 ', hash_file( 'sha256', WP_PLUGIN_DIR . '/zzz-copy-das-sobur/defaults/' . $file ), "\n";
		}
		break;

	case 'prompt-hex':
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT content FROM {$p}prompt_versions WHERE id = %d", (int) $argv[3] ), ARRAY_A );
		echo json_encode( $row['content'], JSON_UNESCAPED_UNICODE ), "\n";
		break;

	case 'state':
		echo json_encode( ZZZCopy\Automation::state() ), "\n";
		echo 'display: ', ZZZCopy\Automation::display_state(), "\n";
		echo 'next tick: ', wp_next_scheduled( 'zzzc_tick' ) ? gmdate( 'c', wp_next_scheduled( 'zzzc_tick' ) ) : 'none', "\n";
		foreach ( array( 'source_items', 'article_jobs', 'publishing_queue', 'post_links', 'generation_usage', 'events', 'jobs' ) as $t ) {
			echo $t, ': ', $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$t}" ), "\n";
		}
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$p}article_jobs GROUP BY status", ARRAY_A ) as $row ) {
			echo '  job ', $row['status'], ': ', $row['c'], "\n";
		}
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$p}source_items GROUP BY status", ARRAY_A ) as $row ) {
			echo '  item ', $row['status'], ': ', $row['c'], "\n";
		}
		foreach ( $wpdb->get_results( "SELECT status, job_type, COUNT(*) AS c FROM {$p}jobs GROUP BY status, job_type", ARRAY_A ) as $row ) {
			echo '  queue job ', $row['job_type'], ' ', $row['status'], ': ', $row['c'], "\n";
		}
		foreach ( $wpdb->get_results( "SELECT slot_key, status, category_id, status_reason FROM {$p}slots ORDER BY due_at", ARRAY_A ) as $row ) {
			echo '  slot ', implode( ' | ', $row ), "\n";
		}
		break;

	case 'posts':
		foreach ( $wpdb->get_results( "SELECT l.article_job_id, l.wp_post_id, l.post_status, l.local_day, p.post_title, p.post_status AS live_status FROM {$p}post_links l LEFT JOIN {$wpdb->posts} p ON p.ID = l.wp_post_id ORDER BY l.id", ARRAY_A ) as $row ) {
			echo implode( ' | ', $row ), "\n";
			$id = (int) $row['wp_post_id'];
			echo '   tags: ', count( wp_get_post_terms( $id, 'post_tag', array( 'fields' => 'names' ) ) ), ' cats: ', implode( ',', wp_get_post_categories( $id, array( 'fields' => 'names' ) ) ), ' thumb: ', (int) get_post_thumbnail_id( $id ), ' src: ', get_post_meta( $id, '_zzzc_source_url', true ), "\n";
		}
		break;

	case 'events':
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT created_at, stage, severity, status, error_code, message FROM {$p}events ORDER BY id DESC LIMIT %d", (int) ( $argv[3] ?? 25 ) ), ARRAY_A ) as $row ) {
			echo implode( ' | ', array_map( static fn( $v ) => mb_substr( (string) $v, 0, 220 ), $row ) ), "\n";
		}
		break;

	case 'usage':
		foreach ( $wpdb->get_results( "SELECT purpose, model, success, http_status, error_code, input_tokens, output_tokens, total_tokens, cost_usd, cost_source, generation_id, created_at FROM {$p}generation_usage ORDER BY id", ARRAY_A ) as $row ) {
			echo implode( ' | ', array_map( 'strval', $row ) ), "\n";
		}
		break;

	case 'attention':
		foreach ( $wpdb->get_results( "SELECT j.id, j.status, j.error_code, LEFT(j.error_message, 160) AS msg, i.publisher, LEFT(i.headline, 70) AS headline, i.retrieval_status, LEFT(i.resolved_url, 80) AS url FROM {$p}article_jobs j JOIN {$p}source_items i ON i.id = j.source_item_id ORDER BY j.id", ARRAY_A ) as $row ) {
			echo implode( ' | ', array_map( 'strval', $row ) ), "\n";
		}
		break;
}
