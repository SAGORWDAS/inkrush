<?php
use ZZZCopy\Http;
use ZZZCopy\OpenRouter;
use ZZZCopy\Plugin;

/**
 * The plugin is presented as InkRush, while every compatibility-sensitive identifier keeps its original value.
 */

const BR_PAGES = array( 'dashboard', 'sources', 'ai', 'schedule', 'publishing', 'authors', 'images', 'articles', 'usage', 'logs' );

function br_old_names(): array {
	return array( 'ZZZ Copy', 'Das-Sobur', 'Das Sobur', 'InkRush Bot' );
}

t(
	'BR-1',
	'The Plugins page shows InkRush by Sagor Das, with the tagline, while the file, slug and text domain are unchanged',
	static function () {
		$file = WP_PLUGIN_DIR . '/zzz-copy-das-sobur/zzz-copy-das-sobur.php';
		t_true( is_plugin_active( 'zzz-copy-das-sobur/zzz-copy-das-sobur.php' ), 'same plugin file and slug' );
		$data = get_plugin_data( $file, false, false );
		t_eq( 'InkRush', $data['Name'] );
		t_eq( 'Sagor Das', $data['Author'] );
		t_eq( 'zzz-copy-das-sobur', $data['TextDomain'] );
		t_eq( ZZZC_VERSION, $data['Version'] );
		t_true( 0 === strpos( $data['Description'], 'Discover. Create. Publish.' ), 'tagline first' );
		t_contains( 'das.sagorwork@gmail.com', $data['Description'] );

		// No file shipped with the plugin carries the earlier name or a variation.
		$found = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/zzz-copy-das-sobur', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $path ) {
			$text = (string) file_get_contents( (string) $path );
			foreach ( br_old_names() as $old ) {
				if ( false !== strpos( $text, $old ) ) { // Case-sensitive: the slug zzz-copy-das-sobur is kept on purpose.
					$found[] = basename( (string) $path ) . ': ' . $old;
				}
			}
		}
		t_eq( array(), $found, 'earlier name in plugin files' );
	}
);

t(
	'BR-2',
	'The menu and page titles say InkRush, while every page slug is unchanged',
	static function () {
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ZZZCopy\Admin\Menu::add_menu();
		$top = array_values( array_filter( $menu, static fn ( $m ) => 'zzzc-dashboard' === $m[2] ) );
		t_eq( 1, count( $top ), 'one top-level menu with the original slug' );
		t_eq( 'InkRush', $top[0][0] );
		t_eq( 'InkRush', $top[0][3], 'top-level page title' );
		$slugs = array_column( $submenu['zzzc-dashboard'], 2 );
		t_eq( array( 'zzzc-dashboard', 'zzzc-sources', 'zzzc-ai', 'zzzc-schedule', 'zzzc-publishing', 'zzzc-authors', 'zzzc-images', 'zzzc-articles', 'zzzc-usage', 'zzzc-logs' ), $slugs );
		foreach ( $submenu['zzzc-dashboard'] as $item ) {
			t_eq( $item[0] . ' | InkRush', $item[3], 'page title of ' . $item[2] );
		}
		$schedules = wp_get_schedules();
		t_true( isset( $schedules['zzzc_minute'] ), 'schedule key unchanged' );
		t_eq( 'Every minute (InkRush)', $schedules['zzzc_minute']['display'] );
		t_eq( 'zzzc_tick', Plugin::TICK_HOOK );
	}
);

t(
	'BR-3',
	'Every admin page shows InkRush with the tagline at the top and the developer attribution at the bottom',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ready_plugin();
		foreach ( BR_PAGES as $page ) {
			ob_start();
			ZZZCopy\Admin\Pages::$page();
			$html = (string) ob_get_clean();
			t_contains( '<h1 class="zzzc-title">InkRush <span class="zzzc-tagline">Discover. Create. Publish.</span></h1>', $html, $page );
			t_contains( '<p class="zzzc-footer">InkRush. Developed by: Sagor Das. Contact: <a href="mailto:das.sagorwork@gmail.com">das.sagorwork@gmail.com</a></p></div>', $html, $page );
			t_eq( 1, substr_count( $html, 'zzzc-footer' ), "one footer on $page" );
			t_true( substr( rtrim( $html ), -6 ) === '</div>', "page still closes its wrapper on $page" );
			foreach ( br_old_names() as $old ) {
				t_not_contains( $old, $html, "$page shows $old" );
			}
		}
	}
);

t(
	'BR-4',
	'OpenRouter sees the app name InkRush, while the user agent and robots token keep their original names',
	static function () {
		ready_plugin();
		$seen = array();
		$grab = static function ( $args, $url ) use ( &$seen ) {
			$seen[] = array( $url, $args );
			return $args;
		};
		add_filter( 'http_request_args', $grab, 10, 2 );
		OpenRouter::verify_setup();
		remove_filter( 'http_request_args', $grab, 10 );

		$openrouter = array_values( array_filter( $seen, static fn ( $s ) => 0 === strpos( $s[0], OpenRouter::base_url() ) ) );
		t_true( count( $openrouter ) > 0, 'OpenRouter was called' );
		foreach ( $openrouter as $request ) {
			t_eq( 'InkRush', $request[1]['headers']['X-Title'] ?? '', 'X-Title for ' . $request[0] );
			t_eq( home_url( '/' ), $request[1]['headers']['HTTP-Referer'] ?? '', 'referer unchanged' );
		}

		t_eq( 'ZZZCopyDasSobur/' . ZZZC_VERSION . ' (+' . home_url( '/' ) . ')', Http::user_agent() );
		$token = ( new ReflectionClassConstant( ZZZCopy\Robots::class, 'TOKEN' ) )->getValue();
		t_eq( 'zzzcopydassobur', $token );
	}
);

t(
	'BR-5',
	'The log export downloads as inkrush-logs-…csv, and the release ZIP is named inkrush-{version}.zip with the original folder inside',
	static function () {
		$before = gmdate( 'Ymd-His' );
		$name   = ZZZCopy\Admin\Actions::export_filename();
		$after  = gmdate( 'Ymd-His' );
		t_true( 1 === preg_match( '/^inkrush-logs-(\d{8}-\d{6})\.csv$/', $name, $m ), "export name $name" );
		t_true( $m[1] >= $before && $m[1] <= $after, 'export name carries the UTC time' );

		$actions = (string) file_get_contents( WP_PLUGIN_DIR . '/zzz-copy-das-sobur/includes/Admin/Actions.php' );
		t_contains( 'filename="\' . self::export_filename() . \'"', $actions, 'the export uses that name' );
		t_not_contains( 'zzz-copy-logs', $actions );

		// The build script: brand file name, the version of the plugin, and the unchanged folder inside.
		$build = str_replace( "\r\n", "\n", (string) file_get_contents( __DIR__ . '/build-zip.php' ) );
		t_contains( '$target = "$dist/inkrush-$version.zip";', $build );
		t_contains( "\$version = '" . ZZZC_VERSION . "';", $build, 'build version matches the plugin' );
		t_contains( "\$zip->addFile( \$file->getPathname(), 'zzz-copy-das-sobur/' . \$relative );", $build, 'folder inside unchanged' );
	}
);
