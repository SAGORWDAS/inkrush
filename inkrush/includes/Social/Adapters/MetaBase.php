<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSecrets;

defined( 'ABSPATH' ) || exit;

/**
 * What Facebook, Instagram and Threads share.
 *
 * All three are Meta APIs. They use the same authorisation shape, report errors in the same way,
 * and the two that publish pictures do it with the same two-step container process. Those parts
 * are here. What differs, which host to call, which permissions to ask for, what a post is made
 * of, stays in each platform's own class.
 *
 * Meta error codes matter more than HTTP status here, because Meta answers with HTTP 400 for
 * conditions as different as an expired token, a rate limit and a malformed request. Treating
 * them all as a permanent rejection would strand posts that only needed to wait, which is why
 * the codes are read below.
 */
abstract class MetaBase extends Base {

	/** Which host this platform's Graph calls go to. */
	abstract protected function graph_host(): string;

	/** Where the administrator is sent to authorise. */
	abstract protected function authorize_endpoint(): string;

	/** The permissions this platform needs. */
	abstract protected function scopes(): array;

	protected function graph(): string {
		return 'https://' . $this->graph_host() . '/' . $this->version();
	}

	public function authorize_url( string $state, string $redirect_uri ): string {
		if ( ! SocialSecrets::has_app( static::platform() ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'client_id'     => SocialSecrets::client_id( static::platform() ),
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
				'response_type' => 'code',
				'scope'         => implode( ',', $this->scopes() ),
			),
			$this->authorize_endpoint()
		);
	}

	/**
	 * Turns the code the platform sent back into an access token.
	 *
	 * @return array{ok:bool, message:string, token:string, expires_in:int}
	 */
	protected function exchange_code( string $code, string $redirect_uri ): array {
		$out = array(
			'ok'         => false,
			'message'    => '',
			'token'      => '',
			'expires_in' => 0,
		);

		$secret = SocialSecrets::client_secret( static::platform() );
		if ( '' === $secret ) {
			$out['message'] = sprintf( 'The %s application secret is not saved, or cannot be decrypted because the site\'s salts changed. Save it again on the Social Media page.', Platforms::label( static::platform() ) );
			return $out;
		}

		$response = $this->request(
			$this->graph() . '/oauth/access_token',
			array(
				'method' => 'POST',
				'form'   => array(
					'client_id'     => SocialSecrets::client_id( static::platform() ),
					'client_secret' => $secret,
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( ! $response['ok'] ) {
			$out['message'] = 'The authorisation could not be completed. ' . SocialHttp::describe( $response );
			return $out;
		}

		$out['token'] = (string) ( $response['json']['access_token'] ?? '' );
		if ( '' === $out['token'] ) {
			$out['message'] = 'The platform completed the authorisation but returned no access token.';
			return $out;
		}

		Secrets::guard( $out['token'] );
		$out['expires_in'] = (int) ( $response['json']['expires_in'] ?? 0 );
		$out['ok']         = true;
		return $out;
	}

	/**
	 * Trades a short-lived token for a long-lived one, which is what makes a connection last
	 * about 60 days rather than a couple of hours.
	 *
	 * @return array{ok:bool, token:string, expires_in:int, message:string}
	 */
	protected function exchange_for_long_lived( string $token, string $grant = 'fb_exchange_token' ): array {
		$out = array(
			'ok'         => false,
			'token'      => $token,
			'expires_in' => 0,
			'message'    => '',
		);

		$secret = SocialSecrets::client_secret( static::platform() );
		if ( '' === $secret ) {
			$out['message'] = 'The application secret is not available, so a long-lived token could not be obtained.';
			return $out;
		}

		$args = array(
			'grant_type'    => 'fb_exchange_token' === $grant ? 'fb_exchange_token' : $grant,
			'client_id'     => SocialSecrets::client_id( static::platform() ),
			'client_secret' => $secret,
		);
		if ( 'fb_exchange_token' === $grant ) {
			$args['fb_exchange_token'] = $token;
		} else {
			$args['access_token'] = $token;
		}

		$response = $this->request( $this->graph() . '/oauth/access_token?' . http_build_query( $args ) );

		if ( ! $response['ok'] ) {
			$out['message'] = 'A long-lived token could not be obtained, so the connection may need renewing sooner. ' . SocialHttp::describe( $response );
			return $out;
		}

		$long = (string) ( $response['json']['access_token'] ?? '' );
		if ( '' !== $long ) {
			Secrets::guard( $long );
			$out['token'] = $long;
		}
		$out['expires_in'] = (int) ( $response['json']['expires_in'] ?? 0 );
		$out['ok']         = true;
		return $out;
	}

	/* ---- The two-step container process used by Instagram and Threads ---- */

	/**
	 * Creates a media container, which is the platform's way of accepting a post's contents
	 * before it is published.
	 *
	 * @return array{ok:bool, id:string, result:SocialResult|null}
	 */
	protected function create_container( string $endpoint, array $fields, string $token ): array {
		$response = $this->authed(
			$endpoint,
			$token,
			array(
				'method'  => 'POST',
				'form'    => $fields,
				'timeout' => 60,
			)
		);

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'preparing the post' );
			// Nothing is published by creating a container, so an unclear answer here is safe to
			// try again: at worst an unused container is left behind, which the platform discards.
			if ( SocialResult::UNKNOWN === $result->outcome ) {
				$result = SocialResult::retry( 'container_failed', $result->message );
			}
			return array(
				'ok'     => false,
				'id'     => '',
				'result' => $result,
			);
		}

		$id = (string) ( $response['json']['id'] ?? '' );
		if ( '' === $id ) {
			return array(
				'ok'     => false,
				'id'     => '',
				'result' => SocialResult::retry( 'container_failed', 'The platform did not return a reference for the prepared post, so nothing was published.' ),
			);
		}

		return array(
			'ok'     => true,
			'id'     => $id,
			'result' => null,
		);
	}

	/**
	 * Waits for a container to finish. The platform fetches the picture from this site in the
	 * background, so a container is not ready the instant it is created.
	 *
	 * Publishing is never attempted on a container that is not FINISHED, because the platform
	 * would reject it and the attempt would be wasted.
	 *
	 * @return array{ok:bool, status:string, result:SocialResult|null}
	 */
	protected function await_container( string $container_id, string $token, int $attempts = 5 ): array {
		$status = '';

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = $this->authed( $this->graph() . '/' . rawurlencode( $container_id ) . '?fields=status_code,status', $token );

			if ( ! $response['ok'] ) {
				return array(
					'ok'     => false,
					'status' => '',
					'result' => $this->classify( $response, 'checking the prepared post' ),
				);
			}

			$status = strtoupper( (string) ( $response['json']['status_code'] ?? '' ) );

			if ( 'FINISHED' === $status ) {
				return array(
					'ok'     => true,
					'status' => $status,
					'result' => null,
				);
			}

			if ( 'ERROR' === $status || 'EXPIRED' === $status ) {
				$detail = (string) ( $response['json']['status'] ?? '' );
				return array(
					'ok'     => false,
					'status' => $status,
					'result' => SocialResult::retry(
						'container_error',
						sprintf(
							'%1$s could not prepare the post. This is usually because it could not fetch the featured image from this site. %2$s',
							Platforms::label( static::platform() ),
							Secrets::redact( $detail )
						)
					),
				);
			}

			// IN_PROGRESS. Give the platform a moment, a little longer each time.
			if ( $attempt < $attempts ) {
				sleep( min( 8, 2 * $attempt ) );
			}
		}

		// Still not ready. Nothing has been published, so this waits rather than failing.
		return array(
			'ok'     => false,
			'status' => $status,
			'result' => SocialResult::defer(
				'container_pending',
				sprintf( '%1$s is still preparing the post. It will be checked again shortly.', Platforms::label( static::platform() ) ),
				180
			),
		);
	}

	/**
	 * Publishes a finished container.
	 */
	protected function publish_container( string $endpoint, string $container_id, string $token ): SocialResult {
		$response = $this->authed(
			$endpoint,
			$token,
			array(
				'method'  => 'POST',
				'form'    => array( 'creation_id' => $container_id ),
				'timeout' => 60,
			)
		);

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'publishing the prepared post' );
			// This is the step that creates the post, so an unclear answer must be checked
			// against the platform rather than repeated. The container reference is carried
			// through, because it is what identifies the post if it was in fact created.
			if ( SocialResult::UNKNOWN === $result->outcome ) {
				return SocialResult::unknown( $result->code, $result->message, $container_id );
			}
			return $result;
		}

		$id = (string) ( $response['json']['id'] ?? '' );
		if ( '' === $id ) {
			return SocialResult::unknown( 'no_identifier', 'The platform accepted the post but returned no identifier for it, so it was not confirmed and was not sent again.', $container_id );
		}

		return SocialResult::published( $id, $this->post_url( $id ) );
	}

	/**
	 * A readable address for a published post, where the platform's identifier allows one to be
	 * built. An empty string otherwise, which simply means no link is shown in the history.
	 */
	protected function post_url( string $external_id ): string {
		return '';
	}

	/**
	 * Reads how much of the platform's publishing allowance is left for this account.
	 *
	 * @param string $endpoint The platform's publishing limit endpoint.
	 * @param string $field    The field holding the count used.
	 * @return int Posts used in the last 24 hours, or -1 when it could not be read.
	 */
	protected function read_publishing_limit( string $endpoint, string $token, string $field = 'quota_usage' ): int {
		$response = $this->authed( $endpoint, $token );
		if ( ! $response['ok'] ) {
			return -1;
		}
		$row = $response['json']['data'][0] ?? null;
		if ( ! is_array( $row ) || ! isset( $row[ $field ] ) ) {
			return -1;
		}
		return (int) $row[ $field ];
	}

	/**
	 * Meta's own error codes, which say more than the HTTP status does.
	 */
	protected function classify( array $response, string $stage = 'publishing' ): SocialResult {
		$error = $response['json']['error'] ?? null;

		if ( is_array( $error ) ) {
			$code    = (int) ( $error['code'] ?? 0 );
			$sub     = (int) ( $error['error_subcode'] ?? 0 );
			$detail  = Secrets::redact( substr( (string) ( $error['error_user_msg'] ?? ( $error['message'] ?? '' ) ), 0, 300 ) );
			$label   = Platforms::label( static::platform() );

			// The credential is no longer good. 190 covers expiry, a changed password and a
			// withdrawn authorisation; the subcode says which, but the remedy is the same.
			if ( 190 === $code || 102 === $code || in_array( $sub, array( 458, 460, 463, 464, 467, 492 ), true ) ) {
				return SocialResult::failed(
					'auth_expired',
					sprintf( '%1$s no longer accepts the saved credential while %2$s. Reconnect the account on the Social Media page. %3$s', $label, $stage, $detail )
				);
			}

			// Too many requests, or the account's own publishing allowance is used up.
			if ( in_array( $code, array( 4, 17, 32, 80004, 613 ), true ) ) {
				$wait = (int) $response['retry_after'];
				$wait = $wait > 0 ? min( 21600, $wait ) : 1800;
				return SocialResult::defer(
					'rate_limited',
					sprintf( '%1$s is limiting requests for this application or account. Waiting about %2$d minutes. %3$s', $label, (int) ceil( $wait / 60 ), $detail ),
					$wait
				);
			}
			if ( 9 === $code || 1404006 === $sub ) {
				return SocialResult::defer(
					'own_limit_reached',
					sprintf( '%1$s says this account has reached its publishing limit for now. Waiting an hour. %2$s', $label, $detail ),
					3600
				);
			}

			// A transient platform problem, which Meta reports as code 1 or 2.
			if ( 1 === $code || 2 === $code ) {
				return SocialResult::retry(
					'platform_error',
					sprintf( '%1$s had a temporary problem while %2$s. %3$s', $label, $stage, $detail )
				);
			}

			// A missing permission. The application has probably not been approved for it.
			if ( 10 === $code || ( $code >= 200 && $code <= 299 ) ) {
				return SocialResult::failed(
					'permission_denied',
					sprintf( '%1$s refused this because the account or the application does not have the permission it needs. Check the permissions and whether the application has been through App Review. %2$s', $label, $detail )
				);
			}

			if ( 0 !== $code ) {
				return SocialResult::failed(
					'rejected',
					sprintf( '%1$s rejected this request while %2$s (code %3$d). %4$s', $label, $stage, $code, $detail )
				);
			}
		}

		return parent::classify( $response, $stage );
	}
}
