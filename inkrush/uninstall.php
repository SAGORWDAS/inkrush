<?php
/**
 * Runs when the plugin is deleted from WordPress.
 *
 * Plugin data is removed only if the administrator turned on the
 * "Delete all plugin data on uninstall" setting. WordPress posts are never deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'zzzc_tick' );
wp_clear_scheduled_hook( 'zzzc_daily_maintenance' );

$zzzc_settings = get_option( 'zzzc_settings', array() );

if ( empty( $zzzc_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$zzzc_tables = array( 'categories', 'source_items', 'article_jobs', 'article_content', 'publishing_queue', 'slots', 'generation_usage', 'events', 'post_links', 'prompt_versions', 'jobs', 'locks', 'image_history', 'image_runs', 'authors' );

foreach ( $zzzc_tables as $zzzc_table ) {
	// The table name is built from a fixed list above.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}zzzc_{$zzzc_table}" ); // phpcs:ignore WordPress.DB
}

$zzzc_options = array( 'zzzc_settings', 'zzzc_state', 'zzzc_secret', 'zzzc_db_version', 'zzzc_heartbeat', 'zzzc_cron_token', 'zzzc_active_prompts', 'zzzc_model_check' );

foreach ( $zzzc_options as $zzzc_option ) {
	delete_option( $zzzc_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_zzzc\_%' OR option_name LIKE '\_transient\_timeout\_zzzc\_%'" ); // phpcs:ignore WordPress.DB
