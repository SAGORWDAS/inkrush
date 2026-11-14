<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSecrets;

defined( 'ABSPATH' ) || exit;

/**
 * Threads, through the official Threads API.
 *
 * Threads is a Meta API but not a Facebook one. It has its own host, its own authorisation
 * address, its own permissions and its own user identifier, and a Threads profile is not reached
 * through a Facebook Page. So this adapter shares Meta's container process and error codes while
 * pointing everything else at Threads itself.
 *
 * Threads counts hashtags strictly: a post carries at most one topic tag, and sending several
 * achieves nothing. The platform registry says one, and the caption generator is told one, so
 * only the single most relevant tag is sent.
 */
final class Threads extends MetaBase {

	public static function platform(): string {
		return Platforms::THREADS;
	}

	protected function graph_host(): string {
		return 'graph.threads.net';
	}

	protected function authorize_endpoint(): string {
		return 'https://threads.net/oauth/authorize';
	}

	protected function scopes(): array {
		return array( 'threads_basic', 'threads_content_publish' );
	}

	/**
	 * Threads separates its permissions with spaces rather than commas, and asks for the
	 * response type in its own way.
	 */
	public function authorize_url( string $state, string $redirect_uri ): string {
		if ( ! SocialSecrets::has_app( self::platform() ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'client_id'     => SocialSecrets::client_id( self::platform() ),
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
				'response_type' => 'code',
				'scope'         => rawurlencode( implode( ',', $this->scopes() ) ),
			),
			$this->authorize_endpoint()
		);
	}

	/* ---- Connecting ---- */

	public function complete_oauth( array $params, string $redirect_uri ): array {
		$fail = static function ( string $message ): array {
			return array(
				'ok'          => false,
				'message'     => $message,
				'account_ids' => array(),
			);
		};

		$code   = (string) ( $params['code'] ?? '' );
		$secret = SocialSecrets::client_secret( self::platform() );
		if ( '' === $secret ) {
			return $fail( 'The Threads application secret is not saved, or cannot be decrypted because the site\'s salts changed. Save it again on the Social Media page.' );
		}

		// Threads takes the short-lived token from its own endpoint rather than the Graph one.
		$response = $this->request(
			'https://graph.threads.net/oauth/access_token',
			array(
				'method' => 'POST',
				'form'   => array(
					'client_id'     => SocialSecrets::client_id( self::platform() ),
					'client_secret' => $secret,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
				),
			)
		);

		if ( ! $response['ok'] ) {
			return $fail( 'The Threads authorisation could not be completed. ' . SocialHttp::describe( $response ) );
		}

		$short = (string) ( $response['json']['access_token'] ?? '' );
		if ( '' === $short ) {
			return $fail( 'Threads completed the authorisation but returned no access token.' );
		}
		Secrets::guard( $short );

		// A long-lived token, so the connection lasts about 60 days rather than an hour.
		$long       = $this->long_lived( $short );
		$token      = $long['token'];
		$expires_in = $long['expires_in'];

		$profile = $this->authed(
			$this->graph() . '/me?fields=id,username,name,threads_profile_picture_url',
			$token
		);

		if ( ! $profile['ok'] ) {
			return $fail( 'Threads authorised the application but the profile could not be read. ' . SocialHttp::describe( $profile ) );
		}

		$threads_id = (string) ( $profile['json']['id'] ?? '' );
		$username   = (string) ( $profile['json']['username'] ?? '' );
		if ( '' === $threads_id ) {
			return $fail( 'Threads did not return the profile identifier needed to publish.' );
		}

		$account_id = SocialAccounts::save(
			array(
				'platform'         => self::platform(),
				'external_id'      => $threads_id,
				'account_name'     => (string) ( $profile['json']['name'] ?? $username ),
				'account_handle'   => '' !== $username ? '@' . $username : '',
				'account_type'     => 'profile',
				'access_token'     => $token,
				'token_expires_at' => $expires_in > 0 ? Clock::mysql( Clock::now() + $expires_in ) : null,
				'scopes'           => implode( ',', $this->scopes() ),
				'extra'            => array(
					'threads_user_id' => $threads_id,
					'username'        => $username,
					'authorised'      => Clock::mysql(),
				),
			)
		);

		if ( ! $account_id ) {
			return $fail( 'The Threads profile could not be saved.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf( 'Connected to Threads as %s.', '' !== $username ? '@' . $username : $threads_id ),
			'account_ids' => array( $account_id ),
		);
	}

	/**
	 * Exchanges a short-lived token for a long-lived one.
	 *
	 * @return array{token:string, expires_in:int}
	 */
	private function long_lived( string $short ): array {
		$secret   = SocialSecrets::client_secret( self::platform() );
		$response = $this->request(
			'https://graph.threads.net/access_token?' . http_build_query(
				array(
					'grant_type'    => 'th_exchange_token',
					'client_secret' => $secret,
					'access_token'  => $short,
				)
			)
		);

		if ( ! $response['ok'] ) {
			return array(
				'token'      => $short,
				'expires_in' => 0,
			);
		}

		$long = (string) ( $response['json']['access_token'] ?? '' );
		if ( '' !== $long ) {
			Secrets::guard( $long );
		}

		return array(
			'token'      => '' !== $long ? $long : $short,
			'expires_in' => (int) ( $response['json']['expires_in'] ?? 0 ),
		);
	}

	/**
	 * Threads refreshes a long-lived token for another 60 days, provided it is at least 24 hours
	 * old and has not already expired.
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

		$response = $this->request(
			'https://graph.threads.net/refresh_access_token?' . http_build_query(
				array(
					'grant_type'   => 'th_refresh_token',
					'access_token' => $credentials['access_token'],
				)
			)
		);

		if ( ! $response['ok'] ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'The Threads credential could not be renewed. ' . SocialHttp::describe( $response ),
			);
		}

		$token = (string) ( $response['json']['access_token'] ?? '' );
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'Threads did not return a renewed credential.',
			);
		}

		Secrets::guard( $token );
		$expires_in = (int) ( $response['json']['expires_in'] ?? 0 );

		SocialAccounts::update_tokens(
			(int) $account['id'],
			$token,
			'',
			$expires_in > 0 ? Clock::mysql( Clock::now() + $expires_in ) : null
		);

		return array(
			'ok'      => true,
			'changed' => true,
			'message' => sprintf( 'The Threads credential was renewed and now lasts about %d days.', max( 1, (int) round( $expires_in / DAY_IN_SECONDS ) ) ),
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

		$response = $this->authed( $this->graph() . '/me?fields=id,username', $credentials['access_token'] );

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'checking the connection' );
			return array(
				'ok'      => false,
				'message' => $result->message,
			);
		}

		$username = (string) ( $response['json']['username'] ?? '' );
		$used     = $this->used_today( (string) $account['external_id'], $credentials['access_token'] );
		$limit    = Platforms::publish_limit_24h( self::platform() );
		$quota    = $used >= 0 ? sprintf( ' %1$d of the %2$d posts Threads allows in 24 hours have been used.', $used, $limit ) : '';

		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected as %1$s. The access token works.%2$s', '' !== $username ? '@' . $username : (string) $account['external_id'], $quota ),
		);
	}

	/**
	 * How many posts Threads counts against this profile in the last 24 hours.
	 *
	 * Note that asking this question itself counts as a request against the application's rate
	 * limit, so it is asked when testing a connection and before publishing, not repeatedly.
	 */
	private function used_today( string $threads_user_id, string $token ): int {
		return $this->read_publishing_limit(
			$this->graph() . '/' . rawurlencode( $threads_user_id ) . '/threads_publishing_limit?fields=quota_usage',
			$token
		);
	}

	/* ---- Publishing ---- */

	public function publish( array $row, array $payload ): SocialResult {
		$account     = (array) $payload['account'];
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'Threads: ' . $credentials['reason'] );
		}

		$token      = $credentials['access_token'];
		$threads_id = (string) $account['external_id'];
		$text       = $this->compose( $payload );
		$media      = (array) ( $payload['media'] ?? array() );

		if ( '' === trim( $text ) ) {
			return SocialResult::failed( 'empty_post', 'Threads: there is no text to publish.' );
		}

		$used  = $this->used_today( $threads_id, $token );
		$limit = Platforms::publish_limit_24h( self::platform() );
		if ( $used >= 0 && $limit > 0 && $used >= $limit ) {
			return SocialResult::defer(
				'own_limit_reached',
				sprintf( 'Threads allows %1$d posts per profile in 24 hours and this profile has used %2$d. Waiting an hour.', $limit, $used ),
				3600
			);
		}

		$fields = array(
			'text'       => $text,
			'media_type' => 'TEXT',
		);

		// Threads fetches the picture from this site, so it is given the address. Without a
		// usable picture the post still goes out, as text, which Threads allows.
		if ( ! empty( $media['ok'] ) ) {
			$fields['media_type'] = 'IMAGE';
			$fields['image_url']  = (string) $media['url'];
			if ( '' !== (string) ( $media['alt'] ?? '' ) ) {
				$fields['alt_text'] = \ZZZCopy\Social\SocialContentGenerator::clip( (string) $media['alt'], 1000 );
			}
		}

		$container = $this->create_container(
			$this->graph() . '/' . rawurlencode( $threads_id ) . '/threads',
			$fields,
			$token
		);

		if ( ! $container['ok'] ) {
			return $container['result'];
		}

		// A text post is ready at once; one with a picture needs the picture fetched first.
		if ( 'IMAGE' === $fields['media_type'] ) {
			$ready = $this->await_container( $container['id'], $token );
			if ( ! $ready['ok'] ) {
				return $ready['result'];
			}
		}

		$result = $this->publish_container(
			$this->graph() . '/' . rawurlencode( $threads_id ) . '/threads_publish',
			$container['id'],
			$token
		);

		// Threads can give the post's own address, which is better than nothing in the history.
		if ( $result->is_published() && '' === $result->external_url ) {
			$permalink = $this->permalink( $result->external_id, $token );
			if ( '' !== $permalink ) {
				return SocialResult::published( $result->external_id, $permalink );
			}
		}

		return $result;
	}

	/**
	 * The public address of a published post.
	 */
	public function permalink( string $post_id, string $token ): string {
		$response = $this->authed( $this->graph() . '/' . rawurlencode( $post_id ) . '?fields=permalink', $token );
		return $response['ok'] ? (string) ( $response['json']['permalink'] ?? '' ) : '';
	}

	/**
	 * Asks Threads whether a post it may have created exists.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Threads: ' . $credentials['reason'] );
		}

		$token    = $credentials['access_token'];
		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( (string) $account['external_id'] ) . '/threads?fields=id,text,permalink,timestamp&limit=25',
			$token
		);

		if ( ! $response['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Threads could not be asked for its recent posts. ' . SocialHttp::describe( $response ) );
		}

		$needle = $this->fingerprint( (string) $row['caption'] );
		$since  = ( strtotime( (string) ( $row['updated_at'] ?? '' ) ) ?: Clock::now() ) - 1800;

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $post ) {
			$when = strtotime( (string) ( $post['timestamp'] ?? '' ) );
			if ( $when && $when < $since ) {
				continue;
			}
			if ( '' !== $needle && $this->fingerprint( (string) ( $post['text'] ?? '' ) ) === $needle ) {
				return SocialResult::published( (string) ( $post['id'] ?? '' ), (string) ( $post['permalink'] ?? '' ) );
			}
		}

		return SocialResult::retry( 'not_found_on_platform', 'Threads has no recent post matching this one, so the earlier attempt did not create it and it can be prepared again.' );
	}

	private function fingerprint( string $text ): string {
		$text = preg_replace( '#https?://\S+#', '', $text );
		$text = preg_replace( '/[^0-9a-z ]/', ' ', strtolower( (string) $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : substr( $text, 0, 100 );
	}
}
