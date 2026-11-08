<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialContentGenerator;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialMedia;
use ZZZCopy\Social\SocialResult;

defined( 'ABSPATH' ) || exit;

/**
 * Instagram, through the Instagram Content Publishing API.
 *
 * Only Business and Creator accounts linked to a Facebook Page are eligible. A personal Instagram
 * account cannot be published to through any official API, so this adapter finds the eligible
 * accounts by asking each authorised Page which Instagram account is linked to it, and offers
 * only those.
 *
 * Two things about Instagram shape this adapter:
 *
 * A post must have a picture. There is no text-only post. So when no usable picture is available
 * the post is skipped with the reason recorded, rather than published as something it is not.
 *
 * Instagram fetches that picture from a public web address. It does not accept the bytes. A site
 * that cannot be reached from the internet therefore cannot publish here at all, which is checked
 * before anything is sent rather than left to fail opaquely.
 *
 * A web address in a caption is not clickable on Instagram, so none is written. The caption ends
 * with the call to action set in the settings, pointing at the link in the profile's biography.
 */
final class Instagram extends MetaBase {

	public static function platform(): string {
		return Platforms::INSTAGRAM;
	}

	protected function graph_host(): string {
		return 'graph.facebook.com';
	}

	protected function authorize_endpoint(): string {
		return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth';
	}

	protected function scopes(): array {
		return array( 'instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement' );
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

		$exchange = $this->exchange_code( (string) ( $params['code'] ?? '' ), $redirect_uri );
		if ( ! $exchange['ok'] ) {
			return $fail( $exchange['message'] );
		}

		$long       = $this->exchange_for_long_lived( $exchange['token'] );
		$user_token = $long['token'];

		// Each Page is asked which Instagram professional account is linked to it. That link is
		// what makes an account eligible, and the Page's token is what publishing to it uses.
		$response = $this->authed(
			$this->graph() . '/me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url,followers_count}&limit=100',
			$user_token
		);

		if ( ! $response['ok'] ) {
			return $fail( 'The list of Instagram accounts could not be read. ' . SocialHttp::describe( $response ) );
		}

		$ids   = array();
		$names = array();

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $page ) {
			$linked = $page['instagram_business_account'] ?? null;
			$token  = (string) ( $page['access_token'] ?? '' );
			if ( ! is_array( $linked ) || empty( $linked['id'] ) || '' === $token ) {
				continue;
			}
			Secrets::guard( $token );

			$username   = (string) ( $linked['username'] ?? '' );
			$account_id = SocialAccounts::save(
				array(
					'platform'       => self::platform(),
					'external_id'    => (string) $linked['id'],
					'account_name'   => (string) ( $linked['name'] ?? $username ),
					'account_handle' => '' !== $username ? '@' . $username : '',
					'account_type'   => 'professional',
					'access_token'   => $token,
					'scopes'         => implode( ',', $this->scopes() ),
					'extra'          => array(
						'ig_user_id'  => (string) $linked['id'],
						'page_id'     => (string) ( $page['id'] ?? '' ),
						'page_name'   => (string) ( $page['name'] ?? '' ),
						'username'    => $username,
						'authorised'  => Clock::mysql(),
					),
				)
			);

			if ( $account_id ) {
				$ids[]   = $account_id;
				$names[] = '' !== $username ? '@' . $username : (string) $linked['id'];
			}
		}

		if ( ! $ids ) {
			return $fail( 'The authorisation worked, but no eligible Instagram account was found. An account must be a Business or Creator account and must be linked to a Facebook Page you hold a role on. A personal Instagram account cannot be published to through Instagram\'s API. Convert the account in the Instagram app under Settings, Account type and tools, link it to your Page, then connect again.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf( 'Connected %1$d Instagram %2$s: %3$s.', count( $ids ), 1 === count( $ids ) ? 'account' : 'accounts', implode( ', ', $names ) ),
			'account_ids' => $ids,
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

		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( (string) $account['external_id'] ) . '?fields=id,username,followers_count,media_count',
			$credentials['access_token']
		);

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'checking the connection' );
			return array(
				'ok'      => false,
				'message' => $result->message,
			);
		}

		$username = (string) ( $response['json']['username'] ?? '' );
		$used     = $this->remaining( (string) $account['external_id'], $credentials['access_token'] );
		$limit    = Platforms::publish_limit_24h( self::platform() );
		$quota    = $used >= 0 ? sprintf( ' %1$d of the %2$d posts Instagram allows in 24 hours have been used.', $used, $limit ) : '';

		// A site Instagram cannot reach is the most common reason publishing fails here, and it
		// is worth saying at the moment the connection is tested rather than at the first post.
		$reachable = SocialMedia::site_is_publicly_reachable();
		$warning   = '' !== $reachable ? ' Note: ' . $reachable : '';

		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected as %1$s. The access token works.%2$s%3$s', '' !== $username ? '@' . $username : (string) $account['external_id'], $quota, $warning ),
		);
	}

	/**
	 * How many posts this account has published through the API in the last 24 hours, as
	 * Instagram itself counts them. Returns -1 when it cannot be read.
	 */
	private function remaining( string $ig_user_id, string $token ): int {
		return $this->read_publishing_limit(
			$this->graph() . '/' . rawurlencode( $ig_user_id ) . '/content_publishing_limit?fields=quota_usage',
			$token
		);
	}

	/* ---- Publishing ---- */

	public function publish( array $row, array $payload ): SocialResult {
		$account     = (array) $payload['account'];
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'Instagram: ' . $credentials['reason'] );
		}

		$media = (array) ( $payload['media'] ?? array() );
		if ( empty( $media['ok'] ) ) {
			// Instagram has no text-only post, so there is nothing valid to send.
			return SocialResult::failed(
				'image_required',
				'Instagram: every post must have a picture, and this article has no picture Instagram can accept. ' . (string) ( $media['reason'] ?? '' )
			);
		}

		$token      = $credentials['access_token'];
		$ig_user_id = (string) $account['external_id'];

		// Instagram's own count is the one that decides, so it is asked rather than guessed.
		$used  = $this->remaining( $ig_user_id, $token );
		$limit = Platforms::publish_limit_24h( self::platform() );
		if ( $used >= 0 && $limit > 0 && $used >= $limit ) {
			return SocialResult::defer(
				'own_limit_reached',
				sprintf( 'Instagram allows %1$d posts per account in 24 hours and this account has used %2$d. Waiting an hour.', $limit, $used ),
				3600
			);
		}

		$caption = $this->caption( $payload );

		$container = $this->create_container(
			$this->graph() . '/' . rawurlencode( $ig_user_id ) . '/media',
			array(
				'image_url' => (string) $media['url'],
				'caption'   => $caption,
				'alt_text'  => SocialContentGenerator::clip( (string) ( $media['alt'] ?? '' ), 1000 ),
			),
			$token
		);

		if ( ! $container['ok'] ) {
			return $container['result'];
		}

		// Instagram fetches the picture in the background, so the container is not ready at once.
		// Publishing before it is FINISHED would simply be refused.
		$ready = $this->await_container( $container['id'], $token );
		if ( ! $ready['ok'] ) {
			return $ready['result'];
		}

		return $this->publish_container(
			$this->graph() . '/' . rawurlencode( $ig_user_id ) . '/media_publish',
			$container['id'],
			$token
		);
	}

	/**
	 * The caption: the written text, the call to action, then the hashtags.
	 *
	 * No web address is written, because Instagram does not make one in a caption clickable, and
	 * printing one that a reader cannot use would be worse than not printing it.
	 */
	private function caption( array $payload ): string {
		$text     = trim( (string) ( $payload['text'] ?? '' ) );
		$hashtags = trim( (string) ( $payload['hashtags'] ?? '' ) );
		$limit    = (int) Platforms::value( self::platform(), 'hard_text_limit', 2200 );
		$cta      = SocialContentGenerator::call_to_action( self::platform() );

		// The call to action is added only when the written text does not already end with it.
		$needs_cta = '' !== $cta && false === stripos( $text, rtrim( $cta, '.' ) );

		$tail = '';
		if ( $needs_cta ) {
			$tail .= "\n\n" . $cta;
		}
		if ( '' !== $hashtags ) {
			$tail .= "\n\n" . $hashtags;
		}

		$room = $limit - mb_strlen( $tail );
		if ( mb_strlen( $text ) > $room ) {
			$text = SocialContentGenerator::clip( $text, max( 20, $room ) );
		}

		return trim( $text . $tail );
	}

	protected function post_url( string $external_id ): string {
		// Instagram returns the media's identifier, not its shortcode, and the shortcode is what
		// a web address needs. The permalink is read separately, so nothing is guessed here.
		return '';
	}

	/**
	 * Reads the public address of a published post, so the history can link to it.
	 */
	public function permalink( string $media_id, array $account ): string {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return '';
		}
		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( $media_id ) . '?fields=permalink',
			$credentials['access_token']
		);
		return $response['ok'] ? (string) ( $response['json']['permalink'] ?? '' ) : '';
	}

	/**
	 * Asks Instagram whether a post it may have created exists.
	 *
	 * The container reference is checked first, because it is exact: a container that has been
	 * published reports the identifier of the post it became. Only when there is no container
	 * reference does this fall back to comparing recent captions.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Instagram: ' . $credentials['reason'] );
		}

		$token  = $credentials['access_token'];
		$needle = $this->fingerprint( (string) $row['caption'] );

		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( (string) $account['external_id'] ) . '/media?fields=id,caption,permalink,timestamp&limit=25',
			$token
		);

		if ( ! $response['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Instagram could not be asked for its recent posts. ' . SocialHttp::describe( $response ) );
		}

		$since = ( strtotime( (string) ( $row['updated_at'] ?? '' ) ) ?: Clock::now() ) - 1800;

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $post ) {
			$when = strtotime( (string) ( $post['timestamp'] ?? '' ) );
			if ( $when && $when < $since ) {
				continue;
			}
			if ( '' !== $needle && $this->fingerprint( (string) ( $post['caption'] ?? '' ) ) === $needle ) {
				return SocialResult::published( (string) ( $post['id'] ?? '' ), (string) ( $post['permalink'] ?? '' ) );
			}
		}

		return SocialResult::retry( 'not_found_on_platform', 'Instagram has no recent post matching this one, so the earlier attempt did not create it and it can be prepared again.' );
	}

	private function fingerprint( string $text ): string {
		$text = preg_replace( '/(^|\s)#[0-9A-Za-z_]+/u', ' ', $text );
		$text = preg_replace( '/[^0-9a-z ]/', ' ', strtolower( (string) $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : substr( $text, 0, 120 );
	}
}
