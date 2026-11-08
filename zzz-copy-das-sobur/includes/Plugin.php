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
		Social\SocialManager::register();

		if ( is_admin() ) {
			Admin\Menu::register();
			Admin\Actions::register();
			Admin\SocialActions::register();
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
	 * Whether the background worker has any reason to run.
	 *
	 * News automation is one reason. Social distribution is a second, independent one: its posts
	 * belong to articles already published on the website, so they must keep going out after a
	 * Stop. Were this to look only at automation, stopping automation would silently strand every
	 * social post that was already queued.
	 */
	public static function worker_wanted(): bool {
		return Automation::is_active() || Social\SocialSettings::enabled();
	}

	/**
	 * Matches the recurring worker schedule to whether the worker is wanted. Called whenever
	 * either reason changes, so the schedule is never left running with nothing to do, nor
	 * cleared while work is still queued.
	 */
	public static function sync_schedule(): void {
		if ( self::worker_wanted() ) {
			if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
				wp_schedule_event( time() + 60, 'zzzc_minute', self::TICK_HOOK );
			}
			return;
		}
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	/**
	 * Restores the recurring schedule if another process removed it while the worker is wanted.
	 */
	public static function self_heal_schedule(): void {
		// Admin and cron requests only, so visitor page views gain no database query from this plugin.
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		if ( self::worker_wanted() && ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 60, 'zzzc_minute', self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}
}
