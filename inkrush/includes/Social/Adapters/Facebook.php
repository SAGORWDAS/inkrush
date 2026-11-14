<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSecrets;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Facebook Pages, through the Meta Graph API.
 *
 * Only Pages. Publishing to a personal Facebook profile is not something the Graph API offers,
 * and this adapter does not pretend otherwise: the authorisation asks which Pages the
 * administrator holds a role on, and only those can be connected.
 *
 * Each Page has its own access token, which is what the Graph API wants for publishing to it.
 * Authorising once therefore produces one connected account per Page, each independently
 * selectable, which is how more than one Page is supported.
 *
 * Before any of this works, the Meta application needs App Review for the pages_* permissions
 * and Business Verification. Until then it can only publish to Pages owned by the developer
 * account itself. That requirement is stated on the Social Media page rather than discovered
 * through a failure.
 */
final class Facebook extends MetaBase {

	public static function platform(): string {
		return Platforms::FACEBOOK;
	}

	protected function graph_host(): string {
		return 'graph.facebook.com';
	}

	protected function authorize_endpoint(): string {
		return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth';
	}

	protected function scopes(): array {
		return array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'business_management' );
	}

	/* ---- Connecting ---- */

	/**
	 * Finishes the authorisation and saves one account per Page it granted access to.
	 */
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

		// A long-lived user token, so listing the Pages keeps working and a Page token taken
		// from it does not expire in an hour.
		$long = $this->exchange_for_long_lived( $exchange['token'] );
		$user_token = $long['token'];

		$pages = $this->list_pages( $user_token );
		if ( ! $pages['ok'] ) {
			return $fail( $pages['message'] );
		}
		if ( ! $pages['pages'] ) {
			return $fail( 'The authorisation worked, but this account does not hold a role on any Facebook Page that can be published to. Facebook does not allow publishing to a personal profile through its API. Create a Page, or ask for a role on one, then connect again.' );
		}

		$ids   = array();
		$names = array();
		foreach ( $pages['pages'] as $page ) {
			$account_id = SocialAccounts::save(
				array(
					'platform'         => self::platform(),
					'external_id'      => (string) $page['id'],
					'account_name'     => (string) $page['name'],
					'account_handle'   => '' !== (string) $page['username'] ? '@' . $page['username'] : '',
					'account_type'     => 'page',
					// The Page's own token, which is what publishing to a Page needs.
					'access_token'     => (string) $page['token'],
					'token_expires_at' => $page['expires'] > 0 ? Clock::mysql( Clock::now() + $page['expires'] ) : null,
					'scopes'           => implode( ',', $this->scopes() ),
					'extra'            => array(
						'page_id'    => (string) $page['id'],
						'tasks'      => (array) $page['tasks'],
						'authorised' => Clock::mysql(),
					),
				)
			);
			if ( $account_id ) {
				$ids[]   = $account_id;
				$names[] = (string) $page['name'];
			}
		}

		if ( ! $ids ) {
			return $fail( 'The Pages could not be saved.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf(
				/* translators: %s: list of Facebook Page names. */
				'Connected %1$d Facebook %2$s: %3$s.',
				count( $ids ),
				1 === count( $ids ) ? 'Page' : 'Pages',
				implode( ', ', $names )
			),
			'account_ids' => $ids,
		);
	}

	/**
	 * The Pages this authorisation can publish to.
	 *
	 * A Page is offered only when the role includes creating content. Offering one that cannot be
	 * published to would mean a connection that looks fine and fails at the first post.
	 *
	 * @return array{ok:bool, message:string, pages:array[]}
	 */
	private function list_pages( string $user_token ): array {
		$out = array(
			'ok'      => false,
			'message' => '',
			'pages'   => array(),
		);

		$response = $this->authed(
			$this->graph() . '/me/accounts?fields=id,name,username,access_token,tasks&limit=100',
			$user_token
		);

		if ( ! $response['ok'] ) {
			$out['message'] = 'The list of Pages could not be read. ' . SocialHttp::describe( $response );
			return $out;
		}

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $page ) {
			$token = (string) ( $page['access_token'] ?? '' );
			$tasks = (array) ( $page['tasks'] ?? array() );
			if ( '' === $token ) {
				continue;
			}
			// CREATE_CONTENT is the role that allows publishing. MANAGE implies it.
			if ( $tasks && ! array_intersect( array( 'CREATE_CONTENT', 'MANAGE' ), $tasks ) ) {
				continue;
			}
			Secrets::guard( $token );
			$out['pages'][] = array(
				'id'       => (string) ( $page['id'] ?? '' ),
				'name'     => (string) ( $page['name'] ?? '' ),
				'username' => (string) ( $page['username'] ?? '' ),
				'token'    => $token,
				'tasks'    => $tasks,
				// A Page token taken from a long-lived user token does not itself expire.
				'expires'  => 0,
			);
		}

		$out['ok'] = true;
		return $out;
	}

	public function test( array $account ): array {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $credentials['reason'],
			);
		}

		$page_id  = (string) $account['external_id'];
		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( $page_id ) . '?fields=id,name,fan_count,can_post',
			$credentials['access_token']
		);

		if ( ! $response['ok'] ) {
			$result = $this->classify( $response, 'checking the connection' );
			return array(
				'ok'      => false,
				'message' => $result->message,
			);
		}

		$name  = (string) ( $response['json']['name'] ?? $account['account_name'] );
		$fans  = isset( $response['json']['fan_count'] ) ? (int) $response['json']['fan_count'] : null;
		$extra = null !== $fans ? sprintf( ' It has %s followers.', number_format_i18n( $fans ) ) : '';

		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected to the Page %1$s. The access token works.%2$s', $name, $extra ),
		);
	}

	/* ---- Publishing ---- */

	/**
	 * Publishes to the Page.
	 *
	 * With a picture, the post is created as a photo with the text as its caption, which is what
	 * puts the picture at full size in the feed. Facebook fetches the picture from this site by
	 * address, so it is given the address rather than the bytes.
	 *
	 * Without a picture, the article is posted as a link, so the feed still shows a preview.
	 */
	public function publish( array $row, array $payload ): SocialResult {
		$account     = (array) $payload['account'];
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'Facebook: ' . $credentials['reason'] );
		}

		$token   = $credentials['access_token'];
		$page_id = (string) $account['external_id'];
		$text    = $this->compose( $payload );
		$media   = (array) ( $payload['media'] ?? array() );
		$url     = trim( (string) ( $payload['url'] ?? '' ) );

		if ( '' === trim( $text ) ) {
			return SocialResult::failed( 'empty_post', 'Facebook: there is no text to publish.' );
		}

		if ( ! empty( $media['ok'] ) ) {
			$fields = array(
				'url'       => (string) $media['url'],
				'caption'   => $text,
				'published' => 'true',
			);
			if ( '' !== (string) ( $media['alt'] ?? '' ) ) {
				$fields['alt_text_custom'] = (string) $media['alt'];
			}

			$response = $this->authed(
				$this->graph() . '/' . rawurlencode( $page_id ) . '/photos',
				$token,
				array(
					'method'  => 'POST',
					'form'    => $fields,
					'timeout' => 90,
				)
			);

			if ( ! $response['ok'] ) {
				return $this->classify( $response, 'publishing the photo' );
			}

			// post_id identifies the feed post; id identifies the photo. The feed post is what a
			// reader opens, so it is preferred when Facebook returns both.
			$post_id = (string) ( $response['json']['post_id'] ?? '' );
			$photo   = (string) ( $response['json']['id'] ?? '' );
			$chosen  = '' !== $post_id ? $post_id : $photo;

			if ( '' === $chosen ) {
				return SocialResult::unknown( 'no_identifier', 'Facebook accepted the photo but returned no identifier, so the post was not confirmed and was not sent again.' );
			}

			return SocialResult::published( $chosen, $this->post_url( $chosen ) );
		}

		$fields = array( 'message' => $text );
		if ( '' !== $url && SocialSettings::includes_url() ) {
			$fields['link'] = $url;
		}

		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( $page_id ) . '/feed',
			$token,
			array(
				'method'  => 'POST',
				'form'    => $fields,
				'timeout' => 60,
			)
		);

		if ( ! $response['ok'] ) {
			return $this->classify( $response, 'publishing the post' );
		}

		$id = (string) ( $response['json']['id'] ?? '' );
		if ( '' === $id ) {
			return SocialResult::unknown( 'no_identifier', 'Facebook accepted the post but returned no identifier, so it was not confirmed and was not sent again.' );
		}

		return SocialResult::published( $id, $this->post_url( $id ) );
	}

	protected function post_url( string $external_id ): string {
		return '' === $external_id ? '' : 'https://www.facebook.com/' . rawurlencode( $external_id );
	}

	/**
	 * Asks Facebook whether a post it may have created exists, by looking through the Page's
	 * recent posts for the text that was being sent.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Facebook: ' . $credentials['reason'] );
		}

		$since    = ( strtotime( (string) ( $row['updated_at'] ?? '' ) ) ?: Clock::now() ) - 1800;
		$response = $this->authed(
			$this->graph() . '/' . rawurlencode( (string) $account['external_id'] ) . '/posts?fields=id,message,created_time&limit=25&since=' . $since,
			$credentials['access_token']
		);

		if ( ! $response['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Facebook could not be asked for the Page\'s recent posts. ' . SocialHttp::describe( $response ) );
		}

		$needle = $this->fingerprint( (string) $row['caption'] );
		if ( '' === $needle ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Facebook: there is no saved text to compare the recent posts against.' );
		}

		foreach ( (array) ( $response['json']['data'] ?? array() ) as $post ) {
			if ( $this->fingerprint( (string) ( $post['message'] ?? '' ) ) === $needle ) {
				$id = (string) ( $post['id'] ?? '' );
				return SocialResult::published( $id, $this->post_url( $id ) );
			}
		}

		return SocialResult::retry( 'not_found_on_platform', 'The Page has no recent post matching this one, so the earlier attempt did not create it and it can be sent again.' );
	}

	private function fingerprint( string $text ): string {
		$text = preg_replace( '#https?://\S+#', '', $text );
		$text = preg_replace( '/[^0-9a-z ]/', ' ', strtolower( (string) $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : substr( $text, 0, 120 );
	}
}
