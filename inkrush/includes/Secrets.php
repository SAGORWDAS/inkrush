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

	/**
	 * Encrypts one value for storage in a plugin table column, using the same scheme as the
	 * API key above. Used for social media access and refresh tokens, which are per-row and
	 * so cannot live in the single option this class otherwise manages.
	 *
	 * @param string $plain Value to protect. An empty string is stored as an empty string.
	 */
	public static function encrypt_value( string $plain ): string {
		return '' === $plain ? '' : self::encrypt( $plain );
	}

	/**
	 * Reverses encrypt_value. Returns an empty string when the value is missing or unreadable,
	 * which happens when the WordPress salts or ZZZCOPY_SECRET_KEY changed after it was stored.
	 */
	public static function decrypt_value( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		return (string) self::decrypt( $stored );
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
	 * Exact secret values held in memory during this request, so they are removed from any text
	 * logged afterwards. Social media tokens live in table rows rather than in one option, and
	 * reading every one of them on every log call would be wasteful, so an adapter registers
	 * the token it is about to use and it is redacted from then on.
	 *
	 * @var string[]
	 */
	private static array $guarded = array();

	/**
	 * Registers one secret value for redaction for the rest of this request.
	 * Short values are ignored, because redacting them would mangle ordinary text.
	 */
	public static function guard( string $value ): void {
		$value = trim( $value );
		if ( strlen( $value ) >= 12 && ! in_array( $value, self::$guarded, true ) ) {
			self::$guarded[] = $value;
		}
	}

	/**
	 * Removes API keys and authorization values from text before it is logged or stored.
	 */
	public static function redact( string $text ): string {
		$key = self::get_api_key();
		if ( '' !== $key ) {
			$text = str_replace( $key, '[REDACTED]', $text );
		}
		foreach ( self::$guarded as $secret ) {
			$text = str_replace( $secret, '[REDACTED]', $text );
		}
		$text = (string) preg_replace( '/sk-or-[A-Za-z0-9_\-]{6,}/', '[REDACTED]', $text );
		$text = (string) preg_replace( '/Bearer\s+[A-Za-z0-9._\-]{6,}/i', 'Bearer [REDACTED]', $text );
		$text = (string) preg_replace( '/("?authorization"?\s*[:=]\s*)"?[^",\s}]+"?/i', '$1"[REDACTED]"', $text );

		// Social media credentials. Meta user and page tokens, JSON web tokens from the AT Protocol,
		// and any field named like a token, secret, password or authorization code.
		$text = (string) preg_replace( '/\bEAA[A-Za-z0-9]{20,}/', '[REDACTED]', $text );
		$text = (string) preg_replace( '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/', '[REDACTED]', $text );
		$text = (string) preg_replace(
			'/("?(?:access_token|refresh_token|accessJwt|refreshJwt|id_token|client_secret|app_secret|app_password|password|code_verifier|oauth_token_secret)"?\s*[:=]\s*)"?[^",\s}&]+"?/i',
			'$1"[REDACTED]"',
			$text
		);
		$text = (string) preg_replace( '/([?&](?:access_token|refresh_token|client_secret|code|code_verifier)=)[^&\s"]+/i', '$1[REDACTED]', $text );
		return $text;
	}
}
