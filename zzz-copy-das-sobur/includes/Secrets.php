<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted storage for the OpenRouter API key, plus log redaction.
 *
 * The encryption key comes from the ZZZCOPY_SECRET_KEY constant in wp-config.php when defined,
 * so the database alone cannot reveal the API key. Otherwise it is derived from the WordPress salts.
 */
final class Secrets {

	private const OPTION = 'zzzc_secret';

	private static function encryption_key(): string {
		if ( defined( 'ZZZCOPY_SECRET_KEY' ) && '' !== (string) ZZZCOPY_SECRET_KEY ) {
			$material = (string) ZZZCOPY_SECRET_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		}
		return hash( 'sha256', 'zzzc-secret-v1|' . $material, true );
	}

	public static function uses_external_key(): bool {
		return defined( 'ZZZCOPY_SECRET_KEY' ) && '' !== (string) ZZZCOPY_SECRET_KEY;
	}

	public static function set_api_key( string $api_key ): void {
		$api_key = trim( $api_key );
		update_option(
			self::OPTION,
			array(
				'cipher' => self::encrypt( $api_key ),
				'last4'  => substr( $api_key, -4 ),
				'set_at' => Clock::mysql(),
			),
			false
		);
	}

	public static function clear_api_key(): void {
		delete_option( self::OPTION );
	}

	public static function has_api_key(): bool {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && ! empty( $stored['cipher'] );
	}

	/**
	 * Returns the decrypted key for server-side API calls only. Empty when missing or unreadable.
	 */
	public static function get_api_key(): string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['cipher'] ) ) {
			return '';
		}
		return (string) self::decrypt( (string) $stored['cipher'] );
	}

	/**
	 * Display hint such as "••••abcd". Never the full key.
	 */
	public static function masked(): string {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['last4'] ) ) {
			return '';
		}
		return '••••••••' . $stored['last4'];
	}

	private static function encrypt( string $plain ): string {
		$key = self::encryption_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return 'o1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function decrypt( string $stored ): ?string {
		$key  = self::encryption_key();
		$kind = substr( $stored, 0, 3 );
		$raw  = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw ) {
			return null;
		}
		if ( 's1:' === $kind && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
			return false === $plain ? null : $plain;
		}
		if ( 'o1:' === $kind && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	/**
	 * Removes API keys and authorization values from text before it is logged or stored.
	 */
	public static function redact( string $text ): string {
		$key = self::get_api_key();
		if ( '' !== $key ) {
			$text = str_replace( $key, '[REDACTED]', $text );
		}
		$text = (string) preg_replace( '/sk-or-[A-Za-z0-9_\-]{6,}/', '[REDACTED]', $text );
		$text = (string) preg_replace( '/Bearer\s+[A-Za-z0-9._\-]{6,}/i', 'Bearer [REDACTED]', $text );
		$text = (string) preg_replace( '/("?authorization"?\s*[:=]\s*)"?[^",\s}]+"?/i', '$1"[REDACTED]"', $text );
		return $text;
	}
}
