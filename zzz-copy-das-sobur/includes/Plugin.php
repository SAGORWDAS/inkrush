<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	public const CAP       = 'manage_options';
	public const TICK_HOOK = 'zzzc_tick';
	public const DAILY_HOOK = 'zzzc_daily_maintenance';

	public static function boot(): void {
		Schema::maybe_upgrade();

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'on_wp_cron_tick' ) );
		add_action( self::DAILY_HOOK, array( Maintenance::class, 'daily' ) );
		add_action( 'rest_api_init', array( RestTick::class, 'register' ) );
		add_action( 'init', array( __CLASS__, 'self_heal_schedule' ) );
		add_action( 'wp_after_insert_post', array( Publisher::class, 'guard_image' ), 99, 4 );
		add_action( 'deleted_user', array( Authors::class, 'on_deleted_user' ), 10, 1 );
		add_action( 'set_user_role', array( Authors::class, 'on_role_change' ), 10, 3 );
		Images\Attribution::register();

		if ( is_admin() ) {
			Admin\Menu::register();
			Admin\Actions::register();
			Admin\Ajax::register();
		}
	}

	public static function activate(): void {
		if ( is_multisite() && is_network_admin() ) {
			wp_die( esc_html__( 'InkRush supports a single website. Activate it on one site, not network-wide.', 'zzz-copy-das-sobur' ) );
		}
		foreach ( array( 'dom', 'libxml', 'mbstring' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				deactivate_plugins( plugin_basename( ZZZC_FILE ) );
				/* translators: %s: PHP extension name. */
				wp_die( esc_html( sprintf( __( 'InkRush needs the PHP extension "%s". Ask your host to enable it.', 'zzz-copy-das-sobur' ), $extension ) ) );
			}
		}
		Schema::install();
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Stops scheduled work but keeps posts, queue and history. Image credits stay in the post text.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		Automation::on_plugin_deactivated();
		Images\Attribution::save_all();
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['zzzc_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (InkRush)',
		);
		return $schedules;
	}

	public static function on_wp_cron_tick(): void {
		Runner::tick( 'wp-cron' );
	}

	/**
	 * Restores the recurring schedule if another process removed it while automation should run.
	 */
	public static function self_heal_schedule(): void {
		// Admin and cron requests only, so visitor page views gain no database query from this plugin.
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		if ( Automation::is_active() && ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 60, 'zzzc_minute', self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}
}
