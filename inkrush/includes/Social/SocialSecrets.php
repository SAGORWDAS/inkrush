<?php
namespace ZZZCopy\Social;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The developer application credentials for each platform, and the one-time values that keep an
 * authorisation safe from being tampered with.
 *
 * A client secret is encrypted with the plugin's existing Secrets scheme, the same one that
 * protects the OpenRouter key, so the database alone cannot reveal it. It is never shown again
 * after it is saved: the screen shows only its last four characters, and no page, script or
 * AJAX response ever receives the whole value.
 *
 * The authorisation state is a random value held for ten minutes and usable once. The platform
 * sends it back with the authorisation, and an authorisation that arrives without the exact value
 * this site issued is refused. That is what stops someone else's authorisation, or a link an
 * administrator was tricked into opening, from attaching an account to this site.
 */
final class SocialSecrets {

	private const APPS_OPTION = 'zzzc_social_apps';
	private const STATE_TTL   = 600;

	/* ---- Application credentials ---- */

	/**
	 * Saves the application credentials for one platform.
	 *
	 * @param string $platform      One of the six.
	 * @param string $client_id     The application's public identifier.
	 * @param string $client_secret The application's secret. An empty value keeps the saved one.
	 */
	public static function set_app( string $platform, string $client_id, string $client_secret ): bool {
		if ( ! Platforms::exists( $platform ) ) {
			return false;
		}

		$all           = self::apps();
		$current       = is_array( $all[ $platform ] ?? null ) ? $all[ $platform ] : array();
		$client_id     = trim( $client_id );
		$client_secret = trim( $client_secret );

		$row = array(
			'client_id' => $client_id,
			'cipher'    => (string) ( $current['cipher'] ?? '' ),
			'last4'     => (string) ( $current['last4'] ?? '' ),
			'set_at'    => (string) ( $current['set_at'] ?? '' ),
		);

		if ( '' !== $client_secret ) {
			$row['cipher'] = Secrets::encrypt_value( $client_secret );
			$row['last4']  = substr( $client_secret, -4 );
			$row['set_at'] = Clock::mysql();
		}

		$all[ $platform ] = $row;
		update_option( self::APPS_OPTION, $all, false );
		return true;
	}

	public static function clear_app( string $platform ): void {
		$all = self::apps();
		unset( $all[ $platform ] );
		update_option( self::APPS_OPTION, $all, false );
	}

	private static function apps(): array {
		$all = get_option( self::APPS_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function client_id( string $platform ): string {
		$all = self::apps();
		return trim( (string) ( $all[ $platform ]['client_id'] ?? '' ) );
	}

	/**
	 * The decrypted client secret, for a server-side token request only. It is registered with
	 * Secrets, so it is redacted from anything logged for the rest of this request.
	 */
	public static function client_secret( string $platform ): string {
		$all    = self::apps();
		$cipher = (string) ( $all[ $platform ]['cipher'] ?? '' );
		if ( '' === $cipher ) {
			return '';
		}
		$secret = Secrets::decrypt_value( $cipher );
		Secrets::guard( $secret );
		return $secret;
	}

	/**
	 * Whether this platform has everything it needs to start an authorisation.
	 */
	public static function has_app( string $platform ): bool {
		$all = self::apps();
		$row = is_array( $all[ $platform ] ?? null ) ? $all[ $platform ] : array();
		return '' !== trim( (string) ( $row['client_id'] ?? '' ) ) && '' !== (string) ( $row['cipher'] ?? '' );
	}

	/**
	 * A display hint for the saved secret, such as "••••••••abcd". Never the whole value.
	 */
	public static function masked_secret( string $platform ): string {
		$all   = self::apps();
		$last4 = (string) ( $all[ $platform ]['last4'] ?? '' );
		return '' === $last4 ? '' : '••••••••' . $last4;
	}

	public static function app_saved_at( string $platform ): string {
		$all = self::apps();
		return (string) ( $all[ $platform ]['set_at'] ?? '' );
	}

	/**
	 * Whether the saved secret can still be decrypted. It cannot when the WordPress salts or
	 * ZZZCOPY_SECRET_KEY changed after it was saved, and saying so plainly saves an
	 * administrator from chasing an authorisation failure that has nothing to do with the
	 * platform.
	 */
	public static function app_is_readable( string $platform ): bool {
		return ! self::has_app( $platform ) || '' !== self::client_secret( $platform );
	}

	/* ---- Authorisation state ---- */

	/**
	 * Issues a one-time value for an authorisation and remembers what it was for.
	 *
	 * @param string $platform One of the six.
	 * @param array  $context  Anything the callback needs, such as the user who started it.
	 * @return string The value to send to the platform.
	 */
	public static function issue_state( string $platform, array $context = array() ): string {
		$state = wp_generate_password( 40, false, false );
		set_transient(
			self::state_key( $state ),
			array_merge(
				$context,
				array(
					'platform' => $platform,
					'user_id'  => (int) get_current_user_id(),
					'issued'   => Clock::now(),
				)
			),
			self::STATE_TTL
		);
		return $state;
	}

	/**
	 * Checks a returned value and uses it up, so the same authorisation cannot be replayed.
	 *
	 * The platform is read from what this site recorded when it issued the value, never from the
	 * query the platform sent back, so a callback cannot claim to be for a platform other than
	 * the one whose authorisation was actually started.
	 *
	 * @return array|null The context it was issued with, including its platform, or null when the
	 *                    value is unknown, expired, already used, or was issued to a different
	 *                    administrator.
	 */
	public static function consume_state( string $state ): ?array {
		$state = trim( $state );
		if ( '' === $state || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $state ) ) {
			return null;
		}

		$key    = self::state_key( $state );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		// Used once, whatever happens next, so the same authorisation cannot be replayed.
		delete_transient( $key );

		if ( ! Platforms::exists( (string) ( $stored['platform'] ?? '' ) ) ) {
			return null;
		}
		// The authorisation must come back to the administrator who started it.
		if ( (int) ( $stored['user_id'] ?? 0 ) !== (int) get_current_user_id() ) {
			return null;
		}
		if ( Clock::now() - (int) ( $stored['issued'] ?? 0 ) > self::STATE_TTL ) {
			return null;
		}

		return $stored;
	}

	private static function state_key( string $state ): string {
		// The value itself is not used as the key, so the key cannot be guessed from a referrer
		// or a server log that recorded the address.
		return 'zzzc_social_state_' . hash( 'sha256', $state );
	}

	/**
	 * A fresh code verifier for the platforms that use proof key for code exchange, and its
	 * challenge. The verifier is kept with the state and never leaves the server.
	 *
	 * @return array{verifier:string, challenge:string}
	 */
	public static function pkce_pair(): array {
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return array(
			'verifier'  => $verifier,
			'challenge' => $challenge,
		);
	}

	/**
	 * The one address every platform sends an authorisation back to.
	 *
	 * This exact address must be entered in each platform's developer settings as an allowed
	 * callback, which is why it is shown on the Social Media page ready to copy.
	 */
	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=zzzc_social_callback' );
	}
}
