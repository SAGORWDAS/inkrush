<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Plugin;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialLogger;
use ZZZCopy\Social\SocialManager;
use ZZZCopy\Social\SocialQueue;
use ZZZCopy\Social\SocialSecrets;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Every form on the social media screens.
 *
 * Each one checks the administrator's capability and the form's nonce before it does anything,
 * which is the same guard the rest of the plugin's forms use.
 *
 * The one exception is the authorisation callback, and it has to be. A platform redirects the
 * administrator's browser back here with its own query values, and it has no way to know this
 * site's nonce, so there cannot be one. In its place the callback checks the capability, and
 * checks the one-time state value this site issued: that value is random, is held for ten
 * minutes, is tied to the platform and to the administrator who started the authorisation, and
 * is used up on first use. An authorisation that arrives without the exact value this site
 * issued is refused and nothing is saved.
 */
final class SocialActions {

	/** Forms guarded by capability and nonce, in the usual way. */
	private const HANDLERS = array(
		'social_settings',
		'social_app',
		'social_connect',
		'social_connect_form',
		'social_disconnect',
		'social_approve',
		'social_retry',
		'social_cancel',
		'social_caption',
		'social_regenerate',
		'social_distribute',
	);

	public static function register(): void {
		foreach ( self::HANDLERS as $action ) {
			add_action( 'admin_post_zzzc_' . $action, array( __CLASS__, 'dispatch' ) );
		}
		// The authorisation callback. See the note above on why this one has no nonce.
		add_action( 'admin_post_zzzc_social_callback', array( __CLASS__, 'callback' ) );
	}

	public static function dispatch(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_zzzc_' ) );

		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zzz-copy-das-sobur' ), 403 );
		}
		if ( ! in_array( $action, self::HANDLERS, true ) ) {
			wp_die( 'Unknown action.', 400 );
		}
		check_admin_referer( 'zzzc_' . $action );

		call_user_func( array( __CLASS__, 'do_' . $action ) );
	}

	private static function post( string $key, $fallback = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce checked in dispatch(); each caller sanitizes.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
	}

	private static function redirect( string $page, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---- Settings ---- */

	/**
	 * Saves the global switches, the per-platform choices, or both, depending on which form
	 * was submitted. A form sends only its own fields, so only those are touched.
	 */
	private static function do_social_settings(): void {
		$changed = array();
		$errors  = array();

		// The global switches, when the global form was the one submitted. An unticked checkbox
		// is not sent by the browser at all, so each one is read as off unless it arrives.
		if ( null !== self::post( 'social_enabled', null ) || null !== self::post( 'social_approval_mode', null ) ) {
			$values = array();
			foreach ( SocialSettings::global_flags() as $flag ) {
				$values[ $flag ] = self::post( $flag, 0 );
			}
			foreach ( array( 'social_approval_mode', 'social_when', 'social_delay_minutes', 'social_max_attempts', 'social_retention_days', 'social_cta' ) as $key ) {
				$sent = self::post( $key, null );
				if ( null !== $sent ) {
					$values[ $key ] = $sent;
				}
			}

			$was    = SocialSettings::enabled();
			$result = Settings::update( $values );
			$errors = $result['errors'];
			$changed = $result['changed'];

			// Turning distribution on or off changes whether the worker is needed at all, which
			// matters when news automation is stopped: without this the worker would not run.
			if ( in_array( 'social_enabled', $changed, true ) ) {
				Plugin::sync_schedule();
				SocialLogger::info(
					'',
					SocialSettings::enabled()
						? 'Social media distribution was switched on.'
						: 'Social media distribution was switched off. Nothing further is published, and queued posts wait until it is switched on again.'
				);
			}
			unset( $was );
		}

		// The per-platform choices.
		$platforms = self::post( 'platforms', array() );
		if ( is_array( $platforms ) && $platforms ) {
			$result  = SocialSettings::save_platforms( $platforms );
			$errors  = array_merge( $errors, $result['errors'] );
			$changed = array_merge( $changed, $result['changed'] );
			Plugin::sync_schedule();
		}

		if ( $errors ) {
			Actions::flash( 'error', 'Some values were not saved. Check the marked fields.', $errors );
		} elseif ( $changed ) {
			Actions::flash( 'success', 'Settings saved.' );
		} else {
			Actions::flash( 'success', 'Nothing was changed.' );
		}

		self::redirect( 'zzzc-social' );
	}

	/**
	 * Saves one platform's developer application credentials.
	 */
	private static function do_social_app(): void {
		$platform = sanitize_text_field( (string) self::post( 'platform' ) );
		if ( ! Platforms::exists( $platform ) ) {
			Actions::flash( 'error', 'Unknown platform.' );
			self::redirect( 'zzzc-social' );
		}

		$client_id = sanitize_text_field( (string) self::post( 'client_id' ) );
		// Not sanitized as text: a secret may contain characters that sanitizing would remove,
		// and it is stored encrypted rather than printed anywhere.
		$secret = trim( (string) self::post( 'client_secret' ) );

		if ( '' === $client_id ) {
			Actions::flash( 'error', 'Enter the application identifier.', array( $platform . '_client_id' => 'This is required.' ) );
			self::redirect( 'zzzc-social' );
		}
		if ( '' === $secret && ! SocialSecrets::has_app( $platform ) ) {
			Actions::flash( 'error', 'Enter the application secret.', array( $platform . '_client_secret' => 'This is required the first time.' ) );
			self::redirect( 'zzzc-social' );
		}

		SocialSecrets::set_app( $platform, $client_id, $secret );

		SocialLogger::info(
			$platform,
			sprintf( 'The developer application settings were saved%s.', '' !== $secret ? ', including a new secret' : '' )
		);

		Actions::flash( 'success', sprintf( '%s application settings saved. You can now connect an account.', Platforms::label( $platform ) ) );
		self::redirect( 'zzzc-social' );
	}

	/* ---- Connecting ---- */

	/**
	 * Starts an authorisation by sending the administrator to the platform.
	 */
	private static function do_social_connect(): void {
		$platform = sanitize_text_field( (string) self::post( 'platform' ) );
		$adapter  = Platforms::exists( $platform ) ? SocialManager::adapter( $platform ) : null;

		if ( ! $adapter ) {
			Actions::flash( 'error', 'Unknown platform.' );
			self::redirect( 'zzzc-social' );
		}
		if ( ! SocialSecrets::has_app( $platform ) ) {
			Actions::flash( 'error', sprintf( 'Save the %s application identifier and secret first.', Platforms::label( $platform ) ) );
			self::redirect( 'zzzc-social' );
		}

		$state = SocialSecrets::issue_state( $platform );

		// X requires a proof key for code exchange. The verifier stays on the server, kept with
		// this one attempt, and the challenge derived from it goes to the platform.
		if ( 'oauth2_pkce' === (string) Platforms::value( $platform, 'auth' ) ) {
			$pair = SocialSecrets::pkce_pair();
			set_transient( 'zzzc_social_pkce_' . hash( 'sha256', $state ), $pair['challenge'], 600 );
			set_transient( 'zzzc_social_verifier_' . hash( 'sha256', $state ), $pair['verifier'], 600 );
		}

		$url = $adapter->authorize_url( $state, SocialSecrets::callback_url() );
		if ( '' === $url ) {
			Actions::flash( 'error', sprintf( '%s could not be opened for authorisation. Check the application settings.', Platforms::label( $platform ) ) );
			self::redirect( 'zzzc-social' );
		}

		SocialLogger::info( $platform, 'An administrator started connecting an account.' );

		// Leaving the site for the platform's own authorisation page.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Deliberately leaving the site for the platform.
		exit;
	}

	/**
	 * Where every platform sends the administrator back to.
	 */
	public static function callback(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zzz-copy-das-sobur' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- A platform cannot send a nonce. The one-time state value below takes its place; see the note on this class.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		$reason   = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error_description'] ) ) : '';
		// phpcs:enable

		if ( '' === $state ) {
			Actions::flash( 'error', 'That authorisation could not be accepted, because it did not carry the one-time value this site issued. Nothing was saved. Start the connection again from this page.' );
			self::redirect( 'zzzc-social' );
		}

		// Which platform this was for is taken from what this site recorded when it issued the
		// one-time value, never from the query, so the query cannot name a different platform
		// than the one whose authorisation was actually started. The value is used up here.
		$context  = SocialSecrets::consume_state( $state );
		$platform = null !== $context ? (string) $context['platform'] : '';

		if ( null === $context ) {
			SocialLogger::warning( '', 'An authorisation came back with a one-time value this site did not issue, or one that had already been used or had expired. Nothing was saved.', array( 'error_code' => 'bad_oauth_state' ) );
			Actions::flash( 'error', 'That authorisation could not be accepted. Its one-time value was unknown, already used, or more than ten minutes old. Nothing was saved. Start the connection again.' );
			self::redirect( 'zzzc-social' );
		}

		// The administrator refused on the platform, or the platform refused.
		if ( '' !== $error ) {
			SocialLogger::info( $platform, sprintf( 'The authorisation was not completed: %1$s. %2$s', $error, $reason ), array( 'error_code' => 'oauth_declined' ) );
			Actions::flash(
				'error',
				sprintf(
					'%1$s did not complete the authorisation: %2$s %3$s',
					Platforms::label( $platform ),
					$error,
					$reason
				)
			);
			self::redirect( 'zzzc-social' );
		}

		if ( '' === $code ) {
			Actions::flash( 'error', sprintf( '%s sent no authorisation code back, so nothing was connected.', Platforms::label( $platform ) ) );
			self::redirect( 'zzzc-social' );
		}

		$adapter = SocialManager::adapter( $platform );
		if ( ! $adapter ) {
			Actions::flash( 'error', 'Unknown platform.' );
			self::redirect( 'zzzc-social' );
		}

		$params = array( 'code' => $code );

		// The verifier for this one attempt, used once and then removed.
		$verifier_key = 'zzzc_social_verifier_' . hash( 'sha256', $state );
		$verifier     = (string) get_transient( $verifier_key );
		if ( '' !== $verifier ) {
			$params['code_verifier'] = $verifier;
		}
		delete_transient( $verifier_key );
		delete_transient( 'zzzc_social_pkce_' . hash( 'sha256', $state ) );

		$result = $adapter->complete_oauth( $params, SocialSecrets::callback_url() );

		if ( empty( $result['ok'] ) ) {
			SocialLogger::error( $platform, 'Connecting an account did not succeed. ' . (string) $result['message'], array( 'error_code' => 'oauth_failed' ) );
			Actions::flash( 'error', (string) $result['message'] );
			self::redirect( 'zzzc-social' );
		}

		foreach ( (array) $result['account_ids'] as $account_id ) {
			$account = SocialAccounts::get( (int) $account_id );
			if ( $account ) {
				SocialLogger::info(
					$platform,
					sprintf( 'Connected the account %s. Its credentials are stored encrypted.', SocialAccounts::label( $account ) ),
					array( 'account_id' => (int) $account_id )
				);
			}
		}

		Actions::flash( 'success', (string) $result['message'] . ' Switch the platform on in its card to start publishing to it.' );
		self::redirect( 'zzzc-social' );
	}

	/**
	 * Connects a platform that takes a credential on a form, rather than authorising.
	 */
	private static function do_social_connect_form(): void {
		$platform = sanitize_text_field( (string) self::post( 'platform' ) );
		$adapter  = Platforms::exists( $platform ) ? SocialManager::adapter( $platform ) : null;

		if ( ! $adapter ) {
			Actions::flash( 'error', 'Unknown platform.' );
			self::redirect( 'zzzc-social' );
		}

		$result = $adapter->connect_with_form(
			array(
				'handle' => sanitize_text_field( (string) self::post( 'handle' ) ),
				// Not sanitized as text, because an app password is a credential rather than
				// content. It is checked for the right shape by the adapter and stored encrypted.
				'app_password' => trim( (string) self::post( 'app_password' ) ),
			)
		);

		if ( empty( $result['ok'] ) ) {
			SocialLogger::warning( $platform, 'Connecting an account did not succeed. ' . (string) $result['message'], array( 'error_code' => 'connect_failed' ) );
			Actions::flash( 'error', (string) $result['message'] );
			self::redirect( 'zzzc-social' );
		}

		foreach ( (array) $result['account_ids'] as $account_id ) {
			$account = SocialAccounts::get( (int) $account_id );
			if ( $account ) {
				SocialLogger::info(
					$platform,
					sprintf( 'Connected the account %s. The app password is stored encrypted.', SocialAccounts::label( $account ) ),
					array( 'account_id' => (int) $account_id )
				);
			}
		}

		Actions::flash( 'success', (string) $result['message'] . ' Switch the platform on in its card to start publishing to it.' );
		self::redirect( 'zzzc-social' );
	}

	private static function do_social_disconnect(): void {
		$account_id = absint( self::post( 'account_id' ) );
		$account    = $account_id ? SocialAccounts::get( $account_id ) : null;

		if ( ! $account ) {
			Actions::flash( 'error', 'That account is no longer connected.' );
			self::redirect( 'zzzc-social' );
		}

		$label = SocialAccounts::label( $account );
		SocialAccounts::disconnect( $account_id );

		Actions::flash(
			'success',
			sprintf(
				'%s was disconnected and its saved credentials were deleted. Anything not yet published was cancelled. Posts already published on the platform were not changed, and nothing was deleted there.',
				$label
			)
		);
		self::redirect( 'zzzc-social' );
	}

	/* ---- Posts ---- */

	private static function do_social_approve(): void {
		$id = absint( self::post( 'social_post_id' ) );
		if ( SocialManager::approve( $id ) ) {
			Actions::flash( 'success', 'Approved. It will be published shortly.' );
		} else {
			Actions::flash( 'error', 'That post could not be approved. It may already have been published, cancelled or approved.' );
		}
		self::redirect( 'zzzc-social-posts' );
	}

	private static function do_social_retry(): void {
		$result = SocialManager::retry( absint( self::post( 'social_post_id' ) ) );
		Actions::flash( empty( $result['ok'] ) ? 'error' : 'success', (string) $result['message'] );
		self::redirect( 'zzzc-social-posts' );
	}

	private static function do_social_cancel(): void {
		$result = SocialManager::cancel( absint( self::post( 'social_post_id' ) ) );
		Actions::flash( empty( $result['ok'] ) ? 'error' : 'success', (string) $result['message'] );
		self::redirect( 'zzzc-social-posts' );
	}

	private static function do_social_caption(): void {
		$result = SocialManager::edit_caption(
			absint( self::post( 'social_post_id' ) ),
			(string) self::post( 'caption' ),
			(string) self::post( 'hashtags' )
		);
		Actions::flash( empty( $result['ok'] ) ? 'error' : 'success', (string) $result['message'] );
		self::redirect( 'zzzc-social-posts' );
	}

	private static function do_social_regenerate(): void {
		$result = SocialManager::regenerate_caption( absint( self::post( 'social_post_id' ) ) );
		Actions::flash( empty( $result['ok'] ) ? 'error' : 'success', (string) $result['message'] );
		self::redirect( 'zzzc-social-posts' );
	}

	/**
	 * Distributes one article from the Articles page, to the platforms chosen there.
	 */
	private static function do_social_distribute(): void {
		$post_id   = absint( self::post( 'wp_post_id' ) );
		$platforms = array_map( 'sanitize_text_field', (array) self::post( 'platforms', array() ) );
		$review    = ! empty( self::post( 'review', 0 ) );

		if ( ! $post_id ) {
			Actions::flash( 'error', 'No article was given.' );
			self::redirect( 'zzzc-articles' );
		}

		$platforms = array_values(
			array_filter(
				$platforms,
				static function ( $platform ): bool {
					return Platforms::exists( (string) $platform );
				}
			)
		);

		if ( ! $platforms ) {
			Actions::flash( 'error', 'Choose at least one platform.' );
			self::redirect( 'zzzc-articles', array( 'job' => SocialManager::article_job_for_post( $post_id ) ) );
		}

		$result = SocialManager::enqueue_for_post(
			$post_id,
			array(
				'origin'    => SocialManager::ORIGIN_MANUAL,
				'platforms' => $platforms,
				// Ticking "let me read it" overrides the automatic setting for this article only.
				'approved'  => ! $review,
			)
		);

		Actions::flash(
			empty( $result['ok'] ) ? 'error' : 'success',
			(string) $result['message'] . ( ! empty( $result['ok'] ) && $review ? ' The text will be written and will then wait for you on the Social Publishing page.' : '' )
		);

		self::redirect( 'zzzc-articles', array( 'job' => SocialManager::article_job_for_post( $post_id ) ) );
	}
}
