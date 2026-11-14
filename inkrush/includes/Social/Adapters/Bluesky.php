<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Clock;
use ZZZCopy\Secrets;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialMedia;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Bluesky, through the AT Protocol.
 *
 * This is the one platform that needs no developer application, no review and no payment. It is
 * connected with an app password, which Bluesky provides for exactly this purpose under Settings,
 * Privacy and security, App passwords. An app password can be revoked on its own without changing
 * the account password, which is why the protocol offers it and why InkRush asks for nothing else.
 *
 * The account password itself is refused. A value that does not have the shape of an app password
 * is rejected before any request is made, so a mistyped main password is never sent anywhere.
 *
 * Publishing is three steps: open a session, upload the picture as a blob, then write the post
 * record. The post text carries real link and hashtag markup, because on the AT Protocol a link
 * is not detected from the text: the post must say which byte range is a link, which is what the
 * facets below do.
 */
final class Bluesky extends Base {

	private const SERVICE = 'https://bsky.social';

	public static function platform(): string {
		return Platforms::BLUESKY;
	}

	public function uses_oauth(): bool {
		return false;
	}

	/* ---- Connecting ---- */

	/**
	 * Connects with a handle and an app password.
	 *
	 * @param array $input handle, app_password.
	 * @return array{ok:bool, message:string, account_ids:int[]}
	 */
	public function connect_with_form( array $input ): array {
		$fail = static function ( string $message ): array {
			return array(
				'ok'          => false,
				'message'     => $message,
				'account_ids' => array(),
			);
		};

		$handle   = strtolower( trim( (string) ( $input['handle'] ?? '' ) ) );
		$password = trim( (string) ( $input['app_password'] ?? '' ) );
		$handle   = ltrim( $handle, '@' );

		if ( '' === $handle || '' === $password ) {
			return $fail( 'Enter your Bluesky handle and an app password.' );
		}
		if ( ! preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/', $handle ) ) {
			return $fail( 'Enter your full Bluesky handle, including the domain, such as example.bsky.social.' );
		}

		// An app password is four groups of four letters separated by hyphens. Refusing anything
		// else keeps an account password from being sent, even by accident.
		if ( ! preg_match( '/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/i', $password ) ) {
			return $fail( 'That does not look like a Bluesky app password. An app password looks like xxxx-xxxx-xxxx-xxxx. Create one in Bluesky under Settings, Privacy and security, App passwords. Do not enter your account password: InkRush does not need it and will not accept it.' );
		}

		Secrets::guard( $password );

		$session = $this->create_session( $handle, $password );
		if ( ! $session['ok'] ) {
			return $fail( $session['message'] );
		}

		$account_id = SocialAccounts::save(
			array(
				'platform'       => self::platform(),
				'external_id'    => $session['did'],
				'account_name'   => '' !== $session['display_name'] ? $session['display_name'] : $session['handle'],
				'account_handle' => '@' . $session['handle'],
				'account_type'   => 'account',
				// The app password is the lasting credential: the session tokens it produces are
				// short lived, so a new session is opened for each run rather than stored.
				'access_token'   => $password,
				'scopes'         => 'app password',
				'extra'          => array(
					'did'     => $session['did'],
					'handle'  => $session['handle'],
					'service' => self::SERVICE,
				),
			)
		);

		if ( ! $account_id ) {
			return $fail( 'The account could not be saved.' );
		}

		return array(
			'ok'          => true,
			'message'     => sprintf( 'Connected to Bluesky as @%s.', $session['handle'] ),
			'account_ids' => array( $account_id ),
		);
	}

	/**
	 * Opens a session. Returns the short-lived tokens, which are never stored.
	 *
	 * @return array{ok:bool, message:string, jwt:string, refresh:string, did:string, handle:string, display_name:string, response:array}
	 */
	private function create_session( string $handle, string $app_password ): array {
		$out = array(
			'ok'           => false,
			'message'      => '',
			'jwt'          => '',
			'refresh'      => '',
			'did'          => '',
			'handle'       => $handle,
			'display_name' => '',
			'response'     => array(),
		);

		$response = $this->request(
			self::SERVICE . '/xrpc/com.atproto.server.createSession',
			array(
				'method' => 'POST',
				'json'   => array(
					'identifier' => $handle,
					'password'   => $app_password,
				),
			)
		);

		$out['response'] = $response;

		if ( ! $response['ok'] ) {
			$error = (string) ( $response['json']['error'] ?? '' );
			if ( 'AuthenticationRequired' === $error || 401 === (int) $response['code'] ) {
				$out['message'] = 'Bluesky did not accept that handle and app password. Check the handle, and create a fresh app password if you are unsure of it.';
				return $out;
			}
			if ( 'AuthFactorTokenRequired' === $error ) {
				$out['message'] = 'This Bluesky account asks for a second factor when signing in. Use an app password rather than the account password: an app password does not ask for one.';
				return $out;
			}
			$out['message'] = 'Bluesky could not be signed in to. ' . SocialHttp::describe( $response );
			return $out;
		}

		$json          = $response['json'];
		$out['jwt']     = (string) ( $json['accessJwt'] ?? '' );
		$out['refresh'] = (string) ( $json['refreshJwt'] ?? '' );
		$out['did']     = (string) ( $json['did'] ?? '' );
		$out['handle']  = (string) ( $json['handle'] ?? $handle );

		if ( '' === $out['jwt'] || '' === $out['did'] ) {
			$out['message'] = 'Bluesky signed in but did not return the values needed to publish.';
			return $out;
		}

		Secrets::guard( $out['jwt'] );
		Secrets::guard( $out['refresh'] );

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Opens a session for a saved account.
	 *
	 * @return array{ok:bool, message:string, jwt:string, did:string, handle:string}
	 */
	private function session_for( array $account ): array {
		$credentials = $this->credentials( $account );
		if ( ! $credentials['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $credentials['reason'],
				'jwt'     => '',
				'did'     => '',
				'handle'  => '',
			);
		}

		$handle  = (string) ( $account['extra']['handle'] ?? ltrim( (string) $account['account_handle'], '@' ) );
		$session = $this->create_session( $handle, $credentials['access_token'] );

		return array(
			'ok'      => $session['ok'],
			'message' => $session['message'],
			'jwt'     => $session['jwt'],
			'did'     => '' !== $session['did'] ? $session['did'] : (string) $account['external_id'],
			'handle'  => $session['handle'],
		);
	}

	public function test( array $account ): array {
		$session = $this->session_for( $account );
		if ( ! $session['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $session['message'],
			);
		}

		$profile = $this->authed(
			self::SERVICE . '/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode( $session['did'] ),
			$session['jwt']
		);

		if ( ! $profile['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 'Signed in, but the profile could not be read. ' . SocialHttp::describe( $profile ),
			);
		}

		$posts = (int) ( $profile['json']['postsCount'] ?? 0 );
		return array(
			'ok'      => true,
			'message' => sprintf( 'Connected as @%1$s. The app password works and the account has %2$s posts.', $session['handle'], number_format_i18n( $posts ) ),
		);
	}

	/* ---- Publishing ---- */

	public function publish( array $row, array $payload ): SocialResult {
		$account = (array) $payload['account'];

		$session = $this->session_for( $account );
		if ( ! $session['ok'] ) {
			return SocialResult::failed( 'auth_expired', 'Bluesky: ' . $session['message'] );
		}

		$text  = $this->compose( $payload );
		$media = (array) ( $payload['media'] ?? array() );
		$url   = trim( (string) ( $payload['url'] ?? '' ) );

		// The picture, uploaded as a blob. A post may go out without one, but never with a
		// picture that only half uploaded.
		$thumb = null;
		if ( ! empty( $media['ok'] ) ) {
			$bytes = SocialMedia::bytes( $media );
			if ( '' === $bytes ) {
				return SocialResult::retry( 'image_unreadable', 'Bluesky: the featured image could not be read from the server, so nothing was sent.' );
			}

			$upload = SocialHttp::upload(
				self::platform(),
				self::SERVICE . '/xrpc/com.atproto.repo.uploadBlob',
				$bytes,
				(string) $media['mime'],
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . $session['jwt'] ),
					'timeout' => 60,
				)
			);

			if ( ! $upload['ok'] ) {
				$result = $this->classify( $upload, 'uploading the picture' );
				// Nothing has been posted yet, so an unclear upload is safe to try again.
				return SocialResult::UNKNOWN === $result->outcome
					? SocialResult::retry( 'image_upload_failed', $result->message )
					: $result;
			}

			$blob = $upload['json']['blob'] ?? null;
			if ( ! is_array( $blob ) ) {
				return SocialResult::retry( 'image_upload_failed', 'Bluesky accepted the picture but did not return a reference to it, so nothing was sent.' );
			}
			$thumb = $blob;
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'langs'     => array( 'en' ),
		);

		$facets = $this->facets( $text );
		if ( $facets ) {
			$record['facets'] = $facets;
		}

		// A link card carries the headline, a line of description and the picture, which is how a
		// news link is meant to look on Bluesky. The picture goes in the card rather than as a
		// separate attachment, because a post cannot carry both.
		if ( '' !== $url && SocialSettings::includes_url() ) {
			$external = array(
				'uri'         => $url,
				'title'       => \ZZZCopy\Social\SocialContentGenerator::clip( (string) ( $payload['link_title'] ?? '' ), 300 ),
				'description' => \ZZZCopy\Social\SocialContentGenerator::clip( (string) ( $payload['link_description'] ?? '' ), 1000 ),
			);
			if ( $thumb ) {
				$external['thumb'] = $thumb;
			}
			$record['embed'] = array(
				'$type'    => 'app.bsky.embed.external',
				'external' => $external,
			);
		} elseif ( $thumb ) {
			$record['embed'] = array(
				'$type'  => 'app.bsky.embed.images',
				'images' => array(
					array(
						'image' => $thumb,
						'alt'   => (string) ( $media['alt'] ?? '' ),
					),
				),
			);
		}

		$created = $this->authed(
			self::SERVICE . '/xrpc/com.atproto.repo.createRecord',
			$session['jwt'],
			array(
				'method'  => 'POST',
				'json'    => array(
					'repo'       => $session['did'],
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				),
				'timeout' => 45,
			)
		);

		if ( ! $created['ok'] ) {
			return $this->classify( $created, 'creating the post' );
		}

		$uri = (string) ( $created['json']['uri'] ?? '' );
		if ( '' === $uri ) {
			// Bluesky answered without the post's identity. It may well exist, so this is checked
			// rather than treated as a success or sent again.
			return SocialResult::unknown( 'no_identifier', 'Bluesky accepted the post but did not return its address, so it was not confirmed and was not sent again.' );
		}

		return SocialResult::published( $uri, $this->post_url( $uri, $session['handle'] ) );
	}

	/**
	 * Marks up the links and hashtags in the text.
	 *
	 * The AT Protocol does not scan the text for them: a post must state the exact byte range of
	 * each one. The offsets below are byte offsets into the UTF-8 text, which is what the
	 * protocol expects and what PHP's string functions already count in.
	 *
	 * @return array[]
	 */
	public function facets( string $text ): array {
		$facets = array();

		if ( preg_match_all( '#https?://[^\s<>"\')\]]+#', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$link  = (string) $match[0];
				$start = (int) $match[1];
				// Trailing punctuation belongs to the sentence, not to the address.
				$trimmed = rtrim( $link, '.,;:!?' );
				$facets[] = array(
					'index'    => array(
						'byteStart' => $start,
						'byteEnd'   => $start + strlen( $trimmed ),
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#link',
							'uri'   => $trimmed,
						),
					),
				);
			}
		}

		if ( preg_match_all( '/(?<=^|\s)#([A-Za-z][0-9A-Za-z_]*)/', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$whole    = (string) $match[0];
				$start    = (int) $match[1];
				$facets[] = array(
					'index'    => array(
						'byteStart' => $start,
						'byteEnd'   => $start + strlen( $whole ),
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#tag',
							'tag'   => (string) $matches[1][ $index ][0],
						),
					),
				);
			}
		}

		return $facets;
	}

	/**
	 * The address a person can open, built from the record's protocol address.
	 */
	public function post_url( string $uri, string $handle ): string {
		$key = (string) substr( strrchr( $uri, '/' ) ?: '', 1 );
		if ( '' === $key || '' === $handle ) {
			return '';
		}
		return 'https://bsky.app/profile/' . rawurlencode( $handle ) . '/post/' . rawurlencode( $key );
	}

	/**
	 * Asks Bluesky whether a post that could not be confirmed exists.
	 *
	 * The account's own recent posts are read and compared with what was going to be sent. This
	 * is possible here because the protocol lets a repository be listed directly.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		$session = $this->session_for( $account );
		if ( ! $session['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Bluesky: ' . $session['message'] );
		}

		$listed = $this->authed(
			self::SERVICE . '/xrpc/com.atproto.repo.listRecords?repo=' . rawurlencode( $session['did'] ) . '&collection=app.bsky.feed.post&limit=25',
			$session['jwt']
		);

		if ( ! $listed['ok'] ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Bluesky could not be asked for its recent posts. ' . SocialHttp::describe( $listed ) );
		}

		$needle = $this->fingerprint( (string) $row['caption'] );
		if ( '' === $needle ) {
			return SocialResult::unknown( 'cannot_reconcile', 'Bluesky: there is no saved text to compare the recent posts against.' );
		}

		$since = strtotime( (string) ( $row['updated_at'] ?? '' ) ) ?: ( Clock::now() - HOUR_IN_SECONDS );

		foreach ( (array) ( $listed['json']['records'] ?? array() ) as $record ) {
			$text    = (string) ( $record['value']['text'] ?? '' );
			$created = strtotime( (string) ( $record['value']['createdAt'] ?? '' ) );
			if ( $created && $created < $since - 900 ) {
				continue;
			}
			if ( $this->fingerprint( $text ) === $needle ) {
				$uri = (string) ( $record['uri'] ?? '' );
				return SocialResult::published( $uri, $this->post_url( $uri, $session['handle'] ) );
			}
		}

		return SocialResult::retry( 'not_found_on_platform', 'Bluesky has no recent post matching this one, so the earlier attempt did not reach it and it can be sent again.' );
	}

	/**
	 * A comparable form of a post's text, used only to recognise the plugin's own post.
	 */
	private function fingerprint( string $text ): string {
		$text = preg_replace( '#https?://\S+#', '', $text );
		$text = preg_replace( '/[^0-9a-z ]/', ' ', strtolower( (string) $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : substr( $text, 0, 120 );
	}
}
