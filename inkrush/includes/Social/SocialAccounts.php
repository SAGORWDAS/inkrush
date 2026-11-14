<?php
namespace ZZZCopy\Social;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Connected social media accounts and their credentials.
 *
 * Tokens are encrypted by Secrets before they are written and are never returned by the ordinary
 * read methods. get() and all() hand back a row with the token columns removed, so a token cannot
 * reach a page, a script or an AJAX response through an oversight. Only credentials(), which the
 * adapters call on the server immediately before a request, decrypts anything, and it registers
 * what it decrypts with Secrets so the value is redacted from anything logged afterwards.
 */
final class SocialAccounts {

	public const CONNECTED = 'connected';
	public const EXPIRED   = 'expired';
	public const REVOKED   = 'revoked';
	public const ERROR     = 'error';

	/** Columns that hold a secret and must never leave this class in readable form. */
	private const SECRET_COLUMNS = array( 'access_token', 'refresh_token' );

	private static function table(): string {
		return Db::table( 'social_accounts' );
	}

	/* ---- Reading ---- */

	/**
	 * One account, with its token columns removed. Null when it does not exist.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::safe( $row ) : null;
	}

	/**
	 * Accounts for one platform, or every account when no platform is given.
	 * Token columns are removed from every row.
	 *
	 * @return array[]
	 */
	public static function all( string $platform = '' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' !== $platform ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE platform = %s ORDER BY account_name ASC, id ASC", $platform ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY platform ASC, account_name ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return array_map( array( __CLASS__, 'safe' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Looks an account up by the identifier the platform itself uses.
	 */
	public static function by_external( string $platform, string $external_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE platform = %s AND external_id = %s', $platform, $external_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::safe( $row ) : null;
	}

	public static function count_connected(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', self::CONNECTED ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Removes the token columns and decodes the stored extra values.
	 * Everything that leaves this class for display passes through here.
	 */
	public static function safe( array $row ): array {
		foreach ( self::SECRET_COLUMNS as $column ) {
			$row[ 'has_' . $column ] = ! empty( $row[ $column ] ) ? 1 : 0;
			unset( $row[ $column ] );
		}
		$extra         = json_decode( (string) ( $row['extra'] ?? '' ), true );
		$row['extra']  = is_array( $extra ) ? $extra : array();
		$row['id']     = (int) ( $row['id'] ?? 0 );
		$row['status'] = (string) ( $row['status'] ?? self::ERROR );
		return $row;
	}

	/**
	 * The decrypted credentials for one account, for a server-side API call only.
	 *
	 * Each value found is registered with Secrets, so if it ever appears in an error message,
	 * a response body or a log line later in this request, it is replaced with [REDACTED].
	 *
	 * @return array{ok:bool, reason:string, access_token:string, refresh_token:string, row:array}
	 */
	public static function credentials( int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array(
			'ok'            => false,
			'reason'        => '',
			'access_token'  => '',
			'refresh_token' => '',
			'row'           => array(),
		);

		if ( ! $row ) {
			$out['reason'] = 'This account is no longer connected.';
			return $out;
		}

		$out['row']           = self::safe( $row );
		$out['access_token']  = Secrets::decrypt_value( (string) $row['access_token'] );
		$out['refresh_token'] = Secrets::decrypt_value( (string) $row['refresh_token'] );

		Secrets::guard( $out['access_token'] );
		Secrets::guard( $out['refresh_token'] );

		if ( '' === $out['access_token'] ) {
			$out['reason'] = ! empty( $row['access_token'] )
				? 'The saved credential for this account cannot be decrypted, usually because the WordPress salts or ZZZCOPY_SECRET_KEY changed. Reconnect the account.'
				: 'This account has no saved credential. Reconnect it.';
			return $out;
		}

		$out['ok'] = true;
		return $out;
	}

	/* ---- Writing ---- */

	/**
	 * Saves a connected account, replacing the credentials of one already connected with the same
	 * platform and external identifier rather than adding a second row for it. This is what makes
	 * Reconnect safe: the account keeps its id, so the settings pointing at it and its publishing
	 * history stay intact.
	 *
	 * @param array $data platform, external_id, account_name, account_handle, account_type,
	 *                    access_token, refresh_token, token_expires_at, refresh_expires_at,
	 *                    scopes, extra.
	 * @return int The account id, or 0 when it could not be saved.
	 */
	public static function save( array $data ): int {
		global $wpdb;

		$platform    = (string) ( $data['platform'] ?? '' );
		$external_id = (string) ( $data['external_id'] ?? '' );
		if ( ! Platforms::exists( $platform ) || '' === $external_id ) {
			return 0;
		}

		$now      = Clock::mysql();
		$existing = self::by_external( $platform, $external_id );

		$fields = array(
			'platform'           => $platform,
			'external_id'        => substr( $external_id, 0, 128 ),
			'account_name'       => substr( sanitize_text_field( (string) ( $data['account_name'] ?? '' ) ), 0, 191 ),
			'account_handle'     => substr( sanitize_text_field( (string) ( $data['account_handle'] ?? '' ) ), 0, 191 ),
			'account_type'       => substr( sanitize_text_field( (string) ( $data['account_type'] ?? '' ) ), 0, 40 ),
			'access_token'       => Secrets::encrypt_value( (string) ( $data['access_token'] ?? '' ) ),
			'refresh_token'      => Secrets::encrypt_value( (string) ( $data['refresh_token'] ?? '' ) ),
			'token_expires_at'   => $data['token_expires_at'] ?? null,
			'refresh_expires_at' => $data['refresh_expires_at'] ?? null,
			'scopes'             => substr( (string) ( $data['scopes'] ?? '' ), 0, 2000 ),
			'extra'              => wp_json_encode( is_array( $data['extra'] ?? null ) ? $data['extra'] : array() ),
			'status'             => self::CONNECTED,
			'status_reason'      => '',
			'connected_by'       => (int) get_current_user_id(),
			'updated_at'         => $now,
		);

		if ( $existing ) {
			// An empty refresh token must not wipe a good one, because several platforms issue it
			// only on the first authorisation and not again on a refresh.
			if ( '' === (string) ( $data['refresh_token'] ?? '' ) ) {
				unset( $fields['refresh_token'], $fields['refresh_expires_at'] );
			}
			$wpdb->update( self::table(), $fields, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		$fields['created_at'] = $now;
		$wpdb->insert( self::table(), $fields );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Replaces the credentials after a token refresh, without touching anything else.
	 */
	public static function update_tokens( int $id, string $access_token, string $refresh_token = '', ?string $expires_at = null ): void {
		global $wpdb;
		$fields = array(
			'access_token'  => Secrets::encrypt_value( $access_token ),
			'status'        => self::CONNECTED,
			'status_reason' => '',
			'updated_at'    => Clock::mysql(),
		);
		if ( '' !== $refresh_token ) {
			$fields['refresh_token'] = Secrets::encrypt_value( $refresh_token );
		}
		if ( null !== $expires_at ) {
			$fields['token_expires_at'] = $expires_at;
		}
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Records that an account can no longer be used, with the reason an administrator needs.
	 * The row is kept, so reconnecting restores it in place.
	 */
	public static function mark_status( int $id, string $status, string $reason = '' ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'        => $status,
				'status_reason' => substr( $reason, 0, 255 ),
				'updated_at'    => Clock::mysql(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Records the result of a connection test.
	 */
	public static function mark_checked( int $id, bool $ok, string $message ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'last_check_at'      => Clock::mysql(),
				'last_check_ok'      => $ok ? 1 : 0,
				'last_check_message' => substr( Secrets::redact( $message ), 0, 255 ),
				'updated_at'         => Clock::mysql(),
			),
			array( 'id' => $id )
		);
	}

	public static function mark_published( int $id ): void {
		global $wpdb;
		$now = Clock::mysql();
		$wpdb->update(
			self::table(),
			array(
				'last_published_at' => $now,
				'updated_at'        => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Removes an account and its stored credentials.
	 *
	 * Publishing history is deliberately kept: a social post already published stays in the
	 * history with the account's name, because disconnecting here does not and must not remove
	 * anything already published on the platform itself.
	 *
	 * @return bool True when an account was removed.
	 */
	public static function disconnect( int $id ): bool {
		global $wpdb;
		$account = self::get( $id );
		if ( ! $account ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'id' => $id ) );
		SocialSettings::forget_account( $id );
		SocialQueue::orphan_account( $id, self::label( $account ) );

		Events::log(
			'social',
			'info',
			sprintf( 'Disconnected the %1$s account %2$s. Its saved credentials were deleted. Posts already published on %1$s were not changed.', Platforms::label( (string) $account['platform'] ), self::label( $account ) ),
			array( 'context' => array( 'platform' => $account['platform'] ) )
		);
		return true;
	}

	/* ---- Choosing an account to publish through ---- */

	/**
	 * The account a platform publishes through: the one chosen in the settings when it is still
	 * connected, or the only connected account when exactly one exists. Null when the choice is
	 * ambiguous or nothing is connected, so publishing never guesses between two accounts.
	 */
	public static function chosen( string $platform ): ?array {
		$chosen = (int) SocialSettings::platform( $platform, 'account_id' );
		if ( $chosen > 0 ) {
			$account = self::get( $chosen );
			if ( $account && self::CONNECTED === $account['status'] ) {
				return $account;
			}
			if ( $account ) {
				// Connected once, but expired or revoked since. Return it so the caller can
				// report exactly why, rather than silently publishing through another account.
				return $account;
			}
		}
		$connected = array_values(
			array_filter(
				self::all( $platform ),
				static function ( array $row ): bool {
					return self::CONNECTED === $row['status'];
				}
			)
		);
		return 1 === count( $connected ) ? $connected[0] : null;
	}

	/**
	 * Whether a platform has an account in a state that can publish.
	 *
	 * @param string $platform   One of the six.
	 * @param int    $account_id Check this account, or 0 to check the chosen one.
	 */
	public static function publishable( string $platform, int $account_id = 0 ): bool {
		$account = $account_id > 0 ? self::get( $account_id ) : self::chosen( $platform );
		return $account && $account['platform'] === $platform && self::CONNECTED === $account['status'];
	}

	/**
	 * Accounts whose access token expires within the given number of days, so it can be
	 * refreshed before a post fails because of it.
	 *
	 * @return array[]
	 */
	public static function expiring( int $days ): array {
		global $wpdb;
		$cutoff = Clock::mysql( Clock::now() + ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s AND token_expires_at IS NOT NULL AND token_expires_at <= %s', self::CONNECTED, $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'safe' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How an account is named on screen and in the log.
	 */
	public static function label( array $account ): string {
		$name   = trim( (string) ( $account['account_name'] ?? '' ) );
		$handle = trim( (string) ( $account['account_handle'] ?? '' ) );
		if ( '' !== $name && '' !== $handle && $name !== $handle ) {
			return $name . ' (' . $handle . ')';
		}
		if ( '' !== $name ) {
			return $name;
		}
		if ( '' !== $handle ) {
			return $handle;
		}
		return 'Account ' . (int) ( $account['id'] ?? 0 );
	}

	public static function status_label( string $status ): string {
		$map = array(
			self::CONNECTED => 'Connected',
			self::EXPIRED   => 'Needs reconnecting',
			self::REVOKED   => 'Access withdrawn',
			self::ERROR     => 'Problem',
		);
		return $map[ $status ] ?? 'Unknown';
	}
}
