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
use ZZZCopy\Social\SocialSecrets;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * LinkedIn, through the official Posts API.
 *
 * LinkedIn is the platform where permissions differ most from one account to the next, so nothing
 * is assumed. After authorising, the account itself is offered, and LinkedIn is then asked which
 * organisations this person actually administers. Only those are offered as well. An organisation
 * the account cannot post for is never listed, because a connection that looks right and fails at
 * the first post is worse than one that was never offered.
 *
 * Posting as a person needs the Share on LinkedIn product, which is self-serve. Posting as an
 * organisation needs the Community Management API, which LinkedIn reviews case by case and can
 * refuse. That difference is stated on the Social Media page.
 *
 * Two LinkedIn particulars shape the code below. The API version is a date that moves every
 * month and must be sent with every request, so it is a setting rather than a constant. And the
 * post text is not plain text: it is LinkedIn's "little text" format, in which fifteen characters
 * are reserved and must be escaped, or the post is silently cut short at the first one of them.
 */
final class LinkedIn extends Base {

	private const API = 'https://api.linkedin.com';

	/**
	 * The characters LinkedIn reserves in post text. Each must be preceded by a backslash or the
	 * post is cut off where the first one appears. The backslash is escaped first, so escaping is
	 * never applied twice to the same character.
	 */
	private const RESERVED = array( '\\', '|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '#', '*', '_', '~' );

	public static function platform(): string {
		return Platforms::LINKEDIN;
	}

	private function scopes(): array {
		return array( 'openid', 'profile', 'w_member_social', 'r_organization_social', 'w_organization_social' );
	}

	private function headers( string $token ): array {
		return array(
			'Authorization'             => 'Bearer ' . $token,
			'LinkedIn-Version'          => $this->version(),
			'X-Restli-Protocol-Version' => '2.0.0',
		);
	}

	/* ---- Connecting ---- */

	public function authorize_url( string $state, string $redirect_uri ): string {
		if ( ! SocialSecrets::has_app( self::platform() ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => SocialSecrets::client_id( self::platform() ),
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
				'scope'         => rawurlencode( implode( ' ', $this->scopes() ) ),
			),
			'https://www.linkedin.com/oauth/v2/authorization'
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

		$secret = SocialSecrets::client_secret( self::platform() );
		if ( '' === $secret ) {
			return $fail( 'The LinkedIn application secret is not saved, or cannot be decrypted because the site\'s salts changed. Save it again on the Social Media page.' );
		}

		$response = $this->request(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'method' => 'POST',
				'form'   => array(
					'grant_type'    => 'authorization_code',
					'code'          => (string) ( $params['code'] ?? '' ),
					'redirect_uri'  => $redirect_uri,
					'client_id'     => SocialSecrets::client_id( self::platform() ),
					'client_secret' => $secret,
				),
			)
		);

		if ( ! $response['ok'] ) {
			return $fail( 'LinkedIn did not grant a token. ' . SocialHttp::describe( $response ) );
		}

		$token   = (string) ( $response['json']['access_token'] ?? '' );
		$refresh = (string) ( $response['json']['refresh_token'] ?? '' );
		$expires = (int) ( $response['json']['expires_in'] ?? 0 );
		$granted = (string) ( $response['json']['scope'] ?? '' );

		if ( '' === $token ) {
			return $fail( 'LinkedIn returned no access token.' );
		}
		Secrets::guard( $token );
		Secrets::guard( $refresh );

		$ids   = array();
		$names = array();

		// The person themselves, when the permission to post as them was granted.
		if ( false !== strpos( $granted, 'w_member_social' ) || '' === $granted ) {
			$member = $this->save_member( $token, $refresh, $expires, $granted );
			if ( $member['ok'] ) {
				$ids[]   = $member['account_id'];
				$names[] = $member['label'];
			}
		}

		// The organisations this account actually administers, which LinkedIn is asked for
		// rather than assumed.
		if ( false !== strpos( $granted, 'organization' ) || '' === $granted ) {
			foreach ( $this->organisations( $token ) as $organisation ) {
				$account_id = SocialAccounts::save(
					array(
						'platform'         => self::platform(),
						'external_id'      => $organisation['urn'],
						'account_name'     => $organisation['name'],
						'account_handle'   => '' !== $organisation['vanity'] ? '@' . $organisation['vanity'] : '',
						'account_type'     => 'organization',
						'access_token'     => $token,
						'refresh_token'    => $refresh,
						'token_expires_at' => $expires > 0 ? Clock::mysql( Clock::now() + $expires ) : null,
						'scopes'           => $granted,
						'extra'            => array(
							'author_urn' => $organisation['urn'],
							'role'       => $organisation['role'],
							'authorised' => Clock::mysql(),
						),
					)
				);
				if ( $account_id ) {
					$ids[]   = $account_id;
					$names[] = $organisation['name'] . ' (organisation)';
				}
			}
		}

		if ( ! $ids ) {
			return $fail( 'LinkedIn authorised the application, but no profile or organisation could be connected. Check that the application has the Share on LinkedIn product for posting as yourself, or the Community Management API for posting as an organisation, and that this account holds an administrator role on the Page.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf( 'Connected %1$d LinkedIn %2$s: %3$s.', count( $ids ), 1 === count( $ids ) ? 'account' : 'accounts', implode( ', ', $names ) ),
			'account_ids' => $ids,
		);
	}

	/**
	 * Saves the authorising person as an account to post as.
	 *
	 * @return array{ok:bool, account_id:int, label:string}
	 */
	private function save_member( string $token, string $refresh, int $expires, string $granted ): array {
		$out = array(
			'ok'         => false,
			'account_id' => 0,
			'label'      => '',
		);

		$me = $this->request(
			self::API . '/v2/userinfo',
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);

		if ( ! $me['ok'] ) {
			return $out;
		}

		$sub  = (string) ( $me['json']['sub'] ?? '' );
		$name = trim( (string) ( $me['json']['name'] ?? '' ) );
		if ( '' === $sub ) {
			return $out;
		}

		$account_id = SocialAccounts::save(
			array(
				'platform'         => self::platform(),
				'external_id'      => 'urn:li:person:' . $sub,
				'account_name'     => '' !== $name ? $name : 'LinkedIn profile',
				'account_type'     => 'member',
				'access_token'     => $token,
				'refresh_token'    => $refresh,
				'token_expires_at' => $expires > 0 ? Clock::mysql( Clock::now() + $expires ) : null,
				'scopes'           => $granted,
				'extra'            => array(
					'author_urn' => 'urn:li:person:' . $sub,
					'authorised' => Clock::mysql(),
				),
			)
		);

		if ( ! $account_id ) {
			return $out;
		}

		return array(
			'ok'         => true,
			'account_id' => $account_id,
			'label'      => ( '' !== $name ? $name : 'LinkedIn profile' ) . ' (profile)',
		);
	}

	/**
	 * The organisations this account may post for.
	 *
	 * @return array[]
	 */
	private function organisations( string $token ): array {
		$response = $this->request(
			self::API . '/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED&count=50',
			array( 'headers' => $this->headers( $token ) )
		);

		if ( ! $response['ok'] ) {
			return array();
		}

		$out = array();
		foreach ( (array) ( $response['json']['elements'] ?? array() ) as $element ) {
			$urn = (string) ( $element['organization'] ?? '' );
			if ( '' === $urn ) {
				continue;
			}
			$details = $this->organisation_details( $urn, $token );
			$out[]   = array(
				'urn'    => $urn,
				'name'   => '' !== $details['name'] ? $details['name'] : $urn,
				'vanity' => $details['vanity'],
				'role'   => (string) ( $element['role'] ?? 'ADMINISTRATOR' ),
			);
		}
		return $out;
	}

	/**
	 * @return array{name:string, vanity:string}
	 */
	private function organisation_details( string $urn, string $token ): array {
		$id       = (string) substr( strrchr( $urn, ':' ) ?: '', 1 );
		$fallback = array(
			'name'   => '',
			'vanity' => '',
		);
		if ( '' === $id ) {
			return $fallback;
		}

		$response = $this->request(
			self::API . '/rest/organizations/' . rawurlencode( $id ) . '?fields=localizedName,vanityName',
			array( 'headers' => $this->headers( $token ) )
		);

		if ( ! $response['ok'] ) {
			return $fallback;
		}

		return array(
			'name'   => (string) ( $response['json']['localizedName'] ?? '' ),
			'vanity' => (string) ( $response['json']['vanityName'] ?? '' ),
		);
	}

	/**
	 * Renews access. LinkedIn issues a refresh token only to applications it has approved for
	 * them, so this says plainly when there is none rather than failing obscurely.
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
				'message' => 'This LinkedIn account has no refresh token. LinkedIn issues one only to applications approved for it, so this connection must be renewed by reconnecting the account. An access token lasts about 60 days.',
			);
		}

		$secret = SocialSecrets::client_secret( self::platform() );
		if ( '' === $secret ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'The LinkedIn application secret is not available.',
			);
		}

		$response = $this->request(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'method' => 'POST',
				'form'   => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $credentials['refresh_token'],
					'client_id'     => SocialSecrets::client_id( self::platform() ),
					'client_secret' => $secret,
				),
			)
		);

		if ( ! $response['ok'] ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'The LinkedIn credential could not be renewed. ' . SocialHttp::describe( $response ),
			);
		}

		$token   = (string) ( $response['json']['access_token'] ?? '' );
		$expires = (int) ( $response['json']['expires_in'] ?? 0 );
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'changed' => false,
				'message' => 'LinkedIn did not return a renewed credential.',
			);
		}

		Secrets::guard( $token );
		SocialAccounts::update_tokens(
			(int) $account['id'],
			$token,
			(string) ( $response['json']['refresh_token'] ?? '' ),
			$expires > 0 ? Clock::mysql( Clock::now() + $expires ) : null
		);

		return array(
			'ok'      => true,
			'changed' => true,
			'message' => sprintf( 'The LinkedIn credential was renewed and now lasts about %d days.', max( 1, (int) round( $expires / DAY_IN_SECONDS ) ) ),
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

		$token = $credentials['access_token'];
		$urn   = $this->author_urn( $account );

		if ( 'organization' === (string) $account['account_type'] ) {
			$id       = (string) substr( strrchr( $urn, ':' ) ?: '', 1 );
			$response = $this->request(
				self::API . '/rest/organizations/' . rawurlencode( $id ) . '?fields=localizedName',
				array( 'headers' => $this->headers( $token ) )
			);
		} else {
			$response = $this->request(
				self::API . '/v2/userinfo',
				array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
			);
		}

		if ( ! $response['ok'] ) {
			$result  = $this->classify( $response, 'checking the connection' );
			$message = $result->message;
			if ( 426 === (int) $response['code'] || false !== stripos( SocialHttp::describe( $response ), 'version' ) ) {
				$message .= sprintf( ' The API version being sent is %s. LinkedIn retires versions on a timetable, so set a current one in the LinkedIn card on this page.', $this->version() );
			}
			return array(
				'ok'      => false,
				'message' => $message,
			);
		}

		$expires = (string) ( $account['token_expires_at'] ?? '' );
		$note    = '';
		if ( '' !== $expires ) {
			$days = (int) floor( ( strtotime( $expires ) - Clock::now() ) / DAY_IN_SECONDS );
			$note = $days > 0
				? sprintf( ' The credential lasts another %d days.', $days )
				: ' The credential has expired and the account needs reconnecting.';
		}

		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected as %1$s. The access token and the API version %2$s both work.%3$s', SocialAccounts::label( $account ), $this->version(), $note ),
		);
	}

	/* ---- Publishing ---- */

	public function publish( array $row, array $payload ): SocialResult {
		$account     = (array) $payload['account'];
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'LinkedIn: ' . $credentials['reason'] );
		}

		$token  = $credentials['access_token'];
		$author = $this->author_urn( $account );
		if ( '' === $author ) {
			return SocialResult::failed( 'no_author', 'LinkedIn: this account has no author reference saved, so it cannot be posted as. Reconnect it.' );
		}

		$commentary = $this->commentary( $payload );
		if ( '' === trim( $commentary ) ) {
			return SocialResult::failed( 'empty_post', 'LinkedIn: there is no text to publish.' );
		}

		$media = (array) ( $payload['media'] ?? array() );
		$url   = trim( (string) ( $payload['url'] ?? '' ) );

		$post = array(
			'author'                    => $author,
			'commentary'                => $commentary,
			'visibility'                => 'PUBLIC',
			'distribution'              => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                 => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);

		// A picture is uploaded and attached. Without one the article is attached as a link, so
		// the post still carries a preview a reader can open.
		if ( ! empty( $media['ok'] ) ) {
			$bytes = SocialMedia::bytes( $media );
			if ( '' === $bytes ) {
				return SocialResult::retry( 'image_unreadable', 'LinkedIn: the featured image could not be read from the server, so nothing was sent.' );
			}

			$uploaded = $this->upload_image( $bytes, (string) $media['mime'], $author, $token );
			if ( ! $uploaded['ok'] ) {
				return $uploaded['result'];
			}

			$post['content'] = array(
				'media' => array(
					'id'      => $uploaded['urn'],
					'altText' => SocialContentGenerator::clip( (string) ( $media['alt'] ?? '' ), 400 ),
				),
			);
		} elseif ( '' !== $url && SocialSettings::includes_url() ) {
			$post['content'] = array(
				'article' => array_filter(
					array(
						'source'      => $url,
						'title'       => SocialContentGenerator::clip( (string) ( $payload['link_title'] ?? '' ), 400 ),
						'description' => SocialContentGenerator::clip( (string) ( $payload['link_description'] ?? '' ), 4000 ),
					)
				),
			);
		}

		$response = $this->request(
			self::API . '/rest/posts',
			array(
				'method'  => 'POST',
				'headers' => $this->headers( $token ),
				'json'    => $post,
				'timeout' => 60,
			)
		);

		if ( ! $response['ok'] ) {
			return $this->classify( $response, 'publishing the post' );
		}

		// LinkedIn returns the new post's reference in a header rather than the body.
		$id = trim( (string) ( $response['headers']['x-restli-id'] ?? '' ) );
		if ( '' === $id ) {
			$id = (string) ( $response['json']['id'] ?? '' );
		}
		if ( '' === $id ) {
			return SocialResult::unknown( 'no_identifier', 'LinkedIn accepted the post but returned no reference for it, so it was not confirmed and was not sent again.' );
		}

		return SocialResult::published( $id, $this->post_url( $id ) );
	}

	/**
	 * Uploads a picture: LinkedIn is asked where to put it, then the bytes are sent there.
	 *
	 * @return array{ok:bool, urn:string, result:SocialResult|null}
	 */
	private function upload_image( string $bytes, string $mime, string $author, string $token ): array {
		$initialise = $this->request(
			self::API . '/rest/images?action=initializeUpload',
			array(
				'method'  => 'POST',
				'headers' => $this->headers( $token ),
				'json'    => array(
					'initializeUploadRequest' => array( 'owner' => $author ),
				),
				'timeout' => 45,
			)
		);

		if ( ! $initialise['ok'] ) {
			$result = $this->classify( $initialise, 'preparing to upload the picture' );
			if ( SocialResult::UNKNOWN === $result->outcome ) {
				$result = SocialResult::retry( 'image_upload_failed', $result->message );
			}
			return array(
				'ok'     => false,
				'urn'    => '',
				'result' => $result,
			);
		}

		$value      = (array) ( $initialise['json']['value'] ?? array() );
		$upload_url = (string) ( $value['uploadUrl'] ?? '' );
		$urn        = (string) ( $value['image'] ?? '' );

		if ( '' === $upload_url || '' === $urn ) {
			return array(
				'ok'     => false,
				'urn'    => '',
				'result' => SocialResult::retry( 'image_upload_failed', 'LinkedIn did not say where to upload the picture, so nothing was sent.' ),
			);
		}

		// The address LinkedIn gives is on its own domain, and only its own domain is accepted.
		$sent = SocialHttp::upload(
			self::platform(),
			$upload_url,
			$bytes,
			$mime,
			array(
				'method'      => 'PUT',
				'headers'     => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout'     => 120,
				'allow_hosts' => array( 'linkedin.com', 'licdn.com' ),
			)
		);

		if ( ! $sent['ok'] ) {
			$result = $this->classify( $sent, 'uploading the picture' );
			if ( SocialResult::UNKNOWN === $result->outcome ) {
				$result = SocialResult::retry( 'image_upload_failed', $result->message );
			}
			return array(
				'ok'     => false,
				'urn'    => '',
				'result' => $result,
			);
		}

		return array(
			'ok'     => true,
			'urn'    => $urn,
			'result' => null,
		);
	}

	/**
	 * The post text in LinkedIn's little text format.
	 *
	 * The written text is escaped once, so none of the fifteen reserved characters can cut the
	 * post short. The hashtags are then added in the template form LinkedIn requires to make
	 * them into real topic links, which is why they are not part of the escaped text.
	 */
	private function commentary( array $payload ): string {
		$text     = trim( (string) ( $payload['text'] ?? '' ) );
		$hashtags = trim( (string) ( $payload['hashtags'] ?? '' ) );
		$limit    = (int) Platforms::value( self::platform(), 'hard_text_limit', 3000 );

		$tags = array();
		if ( '' !== $hashtags ) {
			foreach ( preg_split( '/\s+/', $hashtags, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $tag ) {
				$clean = preg_replace( '/[^0-9A-Za-z_]/', '', (string) $tag );
				if ( '' !== $clean ) {
					// The form LinkedIn needs for a hashtag to become a topic link.
					$tags[] = '{hashtag|\\#|' . $clean . '}';
				}
			}
		}

		$tail = $tags ? "\n\n" . implode( ' ', $tags ) : '';

		// The escaped text is longer than what it came from, and LinkedIn counts what is sent,
		// so the text is shortened first and escaped afterwards, then checked once more.
		$room = $limit - mb_strlen( $tail );
		if ( mb_strlen( $text ) > $room ) {
			$text = SocialContentGenerator::clip( $text, max( 20, $room ) );
		}

		$escaped = $this->escape( $text );
		while ( mb_strlen( $escaped . $tail ) > $limit && mb_strlen( $text ) > 20 ) {
			$text    = SocialContentGenerator::clip( $text, (int) ( mb_strlen( $text ) * 0.9 ) );
			$escaped = $this->escape( $text );
		}

		return trim( $escaped . $tail );
	}

	/**
	 * Escapes LinkedIn's reserved characters. The backslash goes first, so a character is never
	 * escaped twice.
	 */
	public function escape( string $text ): string {
		foreach ( self::RESERVED as $character ) {
			$text = str_replace( $character, '\\' . $character, $text );
		}
		return $text;
	}

	/**
	 * What LinkedIn posts as: the person or the organisation.
	 */
	private function author_urn( array $account ): string {
		$saved = trim( (string) ( $account['extra']['author_urn'] ?? '' ) );
		if ( '' !== $saved ) {
			return $saved;
		}
		$external = (string) $account['external_id'];
		return 0 === strpos( $external, 'urn:li:' ) ? $external : '';
	}

	public function post_url( string $id ): string {
		return '' === $id ? '' : 'https://www.linkedin.com/feed/update/' . rawurlencode( $id ) . '/';
	}

	/**
	 * LinkedIn's Posts API cannot be searched by content, so a post whose outcome is unknown
	 * cannot be confirmed automatically. Rather than risk a second post on the same article, this
	 * says so and leaves it for a person to decide, which the dashboard offers buttons for.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		return SocialResult::unknown(
			'cannot_reconcile',
			'LinkedIn does not offer a way to search its posts for this one, so whether it was created could not be confirmed. It was not sent again. Open the account to see whether the post is there, then either cancel this one or retry it from the Social Publishing page.'
		);
	}
}
