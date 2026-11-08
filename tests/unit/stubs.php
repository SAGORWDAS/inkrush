<?php
/**
 * Just enough WordPress to exercise the parts of the social code that are pure logic.
 *
 * These are the pieces where a mistake is silent rather than loud: a byte offset that is one out,
 * a character budget that forgets the link, an escape that is applied twice. They need no
 * database and no HTTP, so they can be run anywhere PHP is, including on a machine with no
 * WordPress install. The full suite in test-11-social.php covers everything else and needs a
 * real site.
 *
 * Nothing here is part of the plugin or the release ZIP.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ZZZC_VERSION', 'test' );
define( 'ZZZC_DIR', dirname( __DIR__, 1 ) . '/../zzz-copy-das-sobur/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'AUTH_KEY', 'test-auth-key-for-unit-tests-only' );
define( 'SECURE_AUTH_SALT', 'test-salt-for-unit-tests-only' );

$GLOBALS['stub_options'] = array();

function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['stub_options'] ) ? $GLOBALS['stub_options'][ $name ] : $fallback;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['stub_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( ! array_key_exists( $name, $GLOBALS['stub_options'] ) ) {
		$GLOBALS['stub_options'][ $name ] = $value;
	}
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['stub_options'][ $name ] );
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( (string) $text );
	return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
}

function sanitize_text_field( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) );
}

function sanitize_textarea_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max ?: PHP_INT_MAX );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0x0fff ) | 0x4000, random_int( 0, 0x3fff ) | 0x8000, random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function absint( $value ) {
	return abs( (int) $value );
}

function size_format( $bytes, $decimals = 0 ) {
	return number_format( $bytes / 1048576, $decimals ) . ' MB';
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function get_current_user_id() {
	return 1;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['stub_options'][ '_t_' . $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return $GLOBALS['stub_options'][ '_t_' . $key ] ?? false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['stub_options'][ '_t_' . $key ] );
	return true;
}

function add_filter( $hook, $fn, $priority = 10, $args = 1 ) {
	return true;
}

function add_action( $hook, $fn, $priority = 10, $args = 1 ) {
	return true;
}

function is_ssl() {
	return true;
}

function timezone_identifiers_list_stub() {
	return timezone_identifiers_list();
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// The plugin's own autoloader, pointed at the source folder.
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

/* ---- A very small test runner ---- */

$GLOBALS['unit_pass'] = 0;
$GLOBALS['unit_fail'] = array();

function u_is( $expected, $actual, string $label ): void {
	if ( $expected === $actual ) {
		$GLOBALS['unit_pass']++;
		return;
	}
	$GLOBALS['unit_fail'][] = sprintf(
		"%s\n        expected: %s\n        actual:   %s",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

function u_true( $actual, string $label ): void {
	u_is( true, (bool) $actual, $label );
}

function u_false( $actual, string $label ): void {
	u_is( false, (bool) $actual, $label );
}

function u_report( string $title ): int {
	printf( "\n%s\n%s\n", $title, str_repeat( '-', strlen( $title ) ) );
	printf( "passed: %d\nfailed: %d\n", $GLOBALS['unit_pass'], count( $GLOBALS['unit_fail'] ) );
	foreach ( $GLOBALS['unit_fail'] as $failure ) {
		echo "\n  FAIL  " . $failure . "\n";
	}
	return $GLOBALS['unit_fail'] ? 1 : 0;
}
