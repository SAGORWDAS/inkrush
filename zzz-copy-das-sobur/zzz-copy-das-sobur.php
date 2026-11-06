<?php
/**
 * Plugin Name:       InkRush
 * Description:       Discover. Create. Publish. AI-powered WordPress news automation: finds stories, reads accessible publisher text, writes articles through OpenRouter and creates WordPress posts on a schedule, with Wikimedia Commons featured images. Developed by Sagor Das (das.sagorwork@gmail.com).
 * Version:           1.8.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Sagor Das
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zzz-copy-das-sobur
 */

defined( 'ABSPATH' ) || exit;

define( 'ZZZC_VERSION', '1.8.0' );
define( 'ZZZC_FILE', __FILE__ );
define( 'ZZZC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZZZC_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'ZZZCopy\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( 'ZZZCopy\\' ) ) );
		$path     = ZZZC_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'ZZZCopy\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZZZCopy\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ZZZCopy\\Plugin', 'boot' ) );
