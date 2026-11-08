<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu and assets.
 */
final class Menu {

	public const PAGES = array(
		'zzzc-dashboard'  => 'Dashboard',
		'zzzc-sources'    => 'News Sources',
		'zzzc-ai'         => 'AI Settings',
		'zzzc-schedule'   => 'Schedule',
		'zzzc-publishing' => 'Publishing',
		'zzzc-authors'    => 'Authors',
		'zzzc-images'     => 'Images',
		'zzzc-social'     => 'Social Media',
		'zzzc-social-posts' => 'Social Publishing',
		'zzzc-articles'   => 'Articles',
		'zzzc-usage'      => 'Usage',
		'zzzc-logs'       => 'Logs',
	);

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add_menu(): void {
		add_menu_page(
			'InkRush',
			'InkRush',
			Plugin::CAP,
			'zzzc-dashboard',
			array( Pages::class, 'dashboard' ),
			'dashicons-media-text',
			58
		);

		$callbacks = array(
			'zzzc-dashboard'  => 'dashboard',
			'zzzc-sources'    => 'sources',
			'zzzc-ai'         => 'ai',
			'zzzc-schedule'   => 'schedule',
			'zzzc-publishing' => 'publishing',
			'zzzc-authors'    => 'authors',
			'zzzc-images'     => 'images',
			'zzzc-social'     => 'social',
			'zzzc-social-posts' => 'social_posts',
			'zzzc-articles'   => 'articles',
			'zzzc-usage'      => 'usage',
			'zzzc-logs'       => 'logs',
		);

		foreach ( self::PAGES as $slug => $label ) {
			add_submenu_page( 'zzzc-dashboard', $label . ' | InkRush', $label, Plugin::CAP, $slug, array( Pages::class, $callbacks[ $slug ] ) );
		}
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'zzzc-' ) ) {
			return;
		}
		wp_enqueue_style( 'zzzc-admin', ZZZC_URL . 'assets/admin.css', array(), ZZZC_VERSION );
		wp_enqueue_script( 'zzzc-admin', ZZZC_URL . 'assets/admin.js', array(), ZZZC_VERSION, true );
		wp_localize_script(
			'zzzc-admin',
			'zzzcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zzzc_ajax' ),
			)
		);
	}
}
