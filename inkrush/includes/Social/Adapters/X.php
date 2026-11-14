<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialMedia;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSecrets;

defined( 'ABSPATH' ) || exit;

/**
 * X, through the official X API v2.
 *
 * Note the cost before relying on this platform. Posting is not available on the Free tier in any
 * useful quantity: that tier allows only a very small number of posts a month and is meant for
 * trying the API out. Daily distribution needs at least the Basic tier, which X bills monthly.
 * This is stated on the Social Media page because it is a real dependency, not a detail.
 *
 * Authorisation is OAuth 2.0 with a proof key for code exchange, as a confidential client. The
 * refresh token X issues with the offline.access permission is used to renew access without the
 * administrator having to authorise again, and X replaces the refresh token on each renewal, so
 * the new one is always saved over the old one.
 *
 * A picture is uploaded as bytes rather than fetched by X, so the site does not need to be
 * reachable from the internet for this platform.
 */
final class X extends Base {

	private const API = 'https://api.x.com';

	public static function platform(): string {
		return Platforms::X;
	}

	private function scopes(): array {
		return array( 'tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access' );
	}

	/* ---- Connecting ---- */

	/**
	 * X requires a proof key for code exchange even for a confidential client, so the challenge
	 * is sent here and the verifier is kept with the authorisation state until the callback.
	 */
	public function authorize_url( string $state, string $redirect_uri ): string {
		if ( ! SocialSecrets::has_app( self::platform() ) ) {
			return '';
		}

		$challenge = (string) get_transient( 'zzzc_social_pkce_' . hash( 'sha256', $state ) );
		if ( '' === $challenge ) {
			return '';
		}

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => SocialSecrets::client_id( self::platform() ),
				'redirect_uri'          => $redirect_uri,
				'scope'                 => implode( ' ', $this->scopes() ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'https://x.com/i/oauth2/authorize'
		);
	}

	public function complete_oauth( array $params, string $redirect_uri ): array {
		$fail = static function ( string $message ): array {
			return array(
				'ok'          => false,
				'message'     => $message,
				'account_ids' => array(),
			);
		};

		$verifier = (string) ( $params['code_verifier'] ?? '' );
		if ( '' === $verifier ) {
			return $fail( 'The authorisation could not be completed because the one-time verifier for this attempt was not found. It may have expired. Start the connection again.' );
		}

		$secret = SocialSecrets::client_secret( self::platform() );
		if ( '' === $secret ) {
			return $fail( 'The X application secret is not saved, or cannot be decrypted because the site\'s salts changed. Save it again on the Social Media page.' );
		}

		$token = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) ( $params['code'] ?? '' ),
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $verifier,
			)
		);

		if ( ! $token['ok'] ) {
			return $fail( $token['message'] );
		}

		$me = $this->authed( self::API . '/2/users/me?user.fields=username,name,verified', $token['access_token'] );
		if ( ! $me['ok'] ) {
			return $fail( 'X authorised the application but the account could not be read. ' . SocialHttp::describe( $me ) );
		}

		$data     = (array) ( $me['json']['data'] ?? array() );
		$user_id  = (string) ( $data['id'] ?? '' );
		$username = (string) ( $data['username'] ?? '' );

		if ( '' === $user_id ) {
			return $fail( 'X did not return the account identifier needed to publish.' );
		}

		$account_id = SocialAccounts::save(
			array(
				'platform'         => self::platform(),
				'external_id'      => $user_id,
				'account_name'     => (string) ( $data['name'] ?? $username ),
				'account_handle'   => '' !== $username ? '@' . $username : '',
				'account_type'     => 'account',
				'access_token'     => $token['access_token'],
				'refresh_token'    => $token['refresh_token'],
				'token_expires_at' => $token['expires_in'] > 0 ? Clock::mysql( Clock::now() + $token['expires_in'] ) : null,
				'scopes'           => implode( ' ', $this->scopes() ),
				'extra'            => array(
					'username'   => $username,
					'authorised' => Clock::mysql(),
				),
			)
		);

		if ( ! $account_id ) {
			return $fail( 'The X account could not be saved.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf( 'Connected to X as %s.', '' !== $username ? '@' . $username : $user_id ),
			'account_ids' => array( $account_id ),
		);
	}

	/**
	 * A token request. A confidential client authenticates with its identifier and secret in a
	 * basic authorisation header, which is what X expects here.
	 *
	 * @return array{ok:bool, message:string, access_token:string, refresh_token:string, expires_in:int}
	 */
	private function token_request( array $form ): array {
		$out = array(
			'ok'            => false,
			'message'       => '',
			'access_token'  => '',
			'refresh_token' => '',
			'expires_in'    => 0,
		);

		$client_id = SocialSecrets::client_id( self::platform() );
		$secret    = SocialSecrets::client_secret( self::platform() );
		if ( '' === $secret ) {
			$out['message'] = 'The X application secret is not available.';
			return $out;
		}

		$form['client_id'] = $client_id;

		$response = $this->request(
			self::API . '/2/oauth2/token',
			array(
				'method'  => 'POST',
				'form'    => $form,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				),
			)
		);

		if ( ! $response['ok'] ) {
			$out['message'] = 'X did not grant a token. ' . SocialHttp::describe( $response );
			return $out;
		}

		$out['access_token']  = (string) ( $response['json']['access_token'] ?? '' );
		$out['refresh_token'] = (string) ( $response['json']['refresh_token'] ?? '' );
		$out['expires_in']    = (int) ( $response['json']['expires_in'] ?? 0 );

		if ( '' === $out['access_token'] ) {
			$out['message'] = 'X returned no access token.';
			return $out;
		}

		Secrets::guard( $out['access_token'] );
		Secrets::guard( $out['refresh_token'] );

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Renews access with the refresh token. X issues a new refresh token each time and retires
	 * the old one, so both are saved together.
	 */
	public function refresh( array $account ): array {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => $credentials['reason'],
			);
		}

		if ( '' === $credentials['refresh_token'] ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'This X account has no refresh token saved, which happens when the offline.access permission was not granted. Reconnect the account to renew it automatically in future.',
			);
		}

		$token = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $credentials['refresh_token'],
			)
		);

		if ( ! $token['ok'] ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'The X credential could not be renewed. ' . $token['message'],
			);
		}

		SocialAccounts::update_tokens(
			(int) $account['id'],
			$token['access_token'],
			$token['refresh_token'],
			$token['expires_in'] > 0 ? Clock::mysql( Clock::now() + $token['expires_in'] ) : null
		);

		return array(
			'ok'      => true,
			'changed' => true,
			'message' => sprintf( 'The X credential was renewed and now lasts about %d hours.', max( 1, (int) round( $token['expires_in'] / HOUR_IN_SECONDS ) ) ),
		);
	}

	public function test( array $account ): array {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $credentials['reason'],
			);
		}

		$response = $this->authed( self::API . '/2/users/me?user.fields=username,name', $credentials['access_token'] );

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'checking the connection' );
			// An access tier that does not permit posting is the single most likely cause here,
			// and it is worth naming rather than leaving as a bare refusal.
			if ( in_array( $result->code, array( 'permission_denied', 'rejected' ), true ) ) {
				$result = SocialResult::failed(
					$result->code,
					$result->message . ' If this account and application are correct, check the access tier in the X Developer Portal: posting is not available on the Free tier in any useful quantity.'
				);
			}
			return array(
				'ok'      => false,
				'message' => $result->message,
			);
		}

		$username = (string) ( $response['json']['data']['username'] ?? '' );
		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected as %s. The access token works. This test reads the account only; whether posting is allowed depends on the application\'s access tier.', '' !== $username ? '@' . $username : (string) $account['external_id'] ),
		);
	}

	/* ---- Publishing ---- */

	public function publish( array $row, array $payload ): SocialResult {
		$account     = (array) $payload['account'];
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'X: ' . $credentials['reason'] );
		}

		$token = $credentials['access_token'];
		$text  = $this->compose( $payload );
		$media = (array) ( $payload['media'] ?? array() );

		if ( '' === trim( $text ) ) {
			return SocialResult::failed( 'empty_post', 'X: there is no text to publish.' );
		}

		$body = array( 'text' => $text );

		// The picture is uploaded first and attached by reference. A post is never sent claiming
		// a picture whose upload did not succeed.
		if ( ! empty( $media['ok'] ) ) {
			$bytes = SocialMedia::bytes( $media );
			if ( '' === $bytes ) {
				return SocialResult::retry( 'image_unreadable', 'X: the featured image could not be read from the server, so nothing was sent.' );
			}

			$uploaded = $this->upload_media( $bytes, (string) $media['mime'], $token );
			if ( ! $uploaded['ok'] ) {
				return $uploaded['result'];
			}

			if ( '' !== (string) ( $media['alt'] ?? '' ) ) {
				// Alt text is a courtesy to readers using a screen reader. Failing to set it is
				// not a reason to abandon the post, so the outcome is not checked.
				$this->set_alt_text( $uploaded['media_id'], (string) $media['alt'], $token );
			}

			$body['media'] = array( 'media_ids' => array( $uploaded['media_id'] ) );
		}

		$response = $this->authed(
			self::API . '/2/tweets',
			$token,
			array(
				'method'  => 'POST',
				'json'    => $body,
				'timeout' => 45,
			)
		);

		if ( ! $response['ok'] ) {
			// X reports a duplicate post as a refusal. That is not a failure worth reporting as
			// one: the post exists, which is the outcome that was wanted.
			$detail = strtolower( SocialHttp::describe( $response ) );
			if ( false !== strpos( $detail, 'duplicate' ) ) {
				return SocialResult::unknown( 'duplicate_refused', 'X refused this as a duplicate of something already posted, so it was not sent again. The existing post will be looked for.' );
			}
			return $this->classify( $response, 'publishing the post' );
		}

		$id = (string) ( $response['json']['data']['id'] ?? '' );
		if ( '' === $id ) {
			return SocialResult::unknown( 'no_identifier', 'X accepted the post but returned no identifier, so it was not confirmed and was not sent again.' );
		}

		$username = (string) ( $account['extra']['username'] ?? ltrim( (string) $account['account_handle'], '@' ) );
		return SocialResult::published( $id, $this->post_url( $id, $username ) );
	}

	/**
	 * Uploads a picture and returns the reference X gives it.
	 *
	 * @return array{ok:bool, media_id:string, result:SocialResult|null}
	 */
	private function upload_media( string $bytes, string $mime, string $token ): array {
		$response = SocialHttp::upload(
			self::platform(),
			self::API . '/2/media/upload',
			$bytes,
			$mime,
			array(
				'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
				'field'    => 'media',
				'filename' => 'featured.' . ( 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' ) ),
				'fields'   => array( 'media_category' => 'tweet_image' ),
				'timeout'  => 90,
			)
		);

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'uploading the picture' );
			// Nothing is posted by uploading, so an unclear answer here is safe to try again.
			if ( SocialResult::UNKNOWN === $result->outcome ) {
				$result = SocialResult::retry( 'image_upload_failed', $result->message );
			}
			return array(
				'ok'       => false,
				'media_id' => '',
				'result'   => $result,
			);
		}

		// X has returned this under different names across versions of the API.
		$json     = $response['json'];
		$media_id = (string) ( $json['data']['id'] ?? ( $json['id'] ?? ( $json['media_id_string'] ?? '' ) ) );

		if ( '' === $media_id ) {
			return array(
				'ok'       => false,
				'media_id' => '',
				'result'   => SocialResult::retry( 'image_upload_failed', 'X accepted the picture but returned no reference to it, so nothing was sent.' ),
			);
		}

		return array(
			'ok'       => true,
			'media_id' => $media_id,
			'result'   => null,
		);
	}

	private function set_alt_text( string $media_id, string $alt, string $token ): void {
		$this->authed(
			self::API . '/2/media/metadata',
			$token,
			array(
				'method'  => 'POST',
				'json'    => array(
					'id'            => $media_id,
					'metadata'      => array(
						'alt_text' => array( 'text' => mb_substr( $alt, 0, 1000 ) ),
					),
				),
				'timeout' => 20,
			)
		);
	}

	public function post_url( string $id, string $username = '' ): string {
		if ( '' === $id ) {
			return '';
		}
		$who = '' !== $username ? rawurlencode( $username ) : 'i';
		return 'https://x.com/' . $who . '/status/' . rawurlencode( $id );
	}

	/**
	 * Asks X whether a post it may have created exists, by reading the account's recent posts.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'X: ' . $credentials['reason'] );
		}

		$response = $this->authed(
			self::API . '/2/users/' . rawurlencode( (string) $account['external_id'] ) . '/tweets?max_results=20&tweet.fields=created_at',
			$credentials['access_token']
		);

		if ( ! $response['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'X could not be asked for the account\'s recent posts. ' . SocialHttp::describe( $response ) );
		}

		$needle = $this->fingerprint( (string) $row['caption'] );
		if ( '' === $needle ) {
			return SocialResult::unknown( 'cannot_reconcile', 'X: there is no saved text to compare the recent posts against.' );
		}

		$since    = ( strtotime( (string) ( $row['updated_at'] ?? '' ) ) ?: Clock::now() ) - 1800;
		$username = (string) ( $account['extra']['username'] ?? '' );

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $post ) {
			$when = strtotime( (string) ( $post['created_at'] ?? '' ) );
			if ( $when && $when < $since ) {
				continue;
			}
			if ( $this->fingerprint( (string) ( $post['text'] ?? '' ) ) === $needle ) {
				$id = (string) ( $post['id'] ?? '' );
				return SocialResult::published( $id, $this->post_url( $id, $username ) );
			}
		}

		return SocialResult::retry( 'not_found_on_platform', 'X has no recent post matching this one, so the earlier attempt did not create it and it can be sent again.' );
	}

	private function fingerprint( string $text ): string {
		// X shortens every link, so addresses are removed before comparing.
		$text = preg_replace( '#https?://\S+#', '', $text );
		$text = preg_replace( '/[^0-9a-z ]/', ' ', strtolower( (string) $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : substr( $text, 0, 90 );
	}
}
