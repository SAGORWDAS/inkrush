<?php
namespace ZZZCopy\Social\Adapters;

use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialContentGenerator;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialResult;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * What every platform adapter has in common.
 *
 * Each platform publishes differently enough that the differences belong in its own class, but
 * the rules that must never vary belong here, written once:
 *
 * - A result is only ever "published" when the platform returned an identifier for the post.
 *   No adapter may report success without one.
 * - A request that may have arrived but whose answer was not seen returns UNKNOWN, never RETRY.
 *   UNKNOWN sends the post to be reconciled. This is what stops a timeout turning into two posts.
 * - A rate limit defers without using an attempt, and waits as long as the platform asked.
 * - Text is assembled to the platform's limit before it is sent, never trimmed by the platform.
 */
abstract class Base {

	/** Which of the six this adapter is. */
	abstract public static function platform(): string;

	/**
	 * Sends the post.
	 *
	 * @param array $row     The social post row.
	 * @param array $payload text, hashtags, url, media, account.
	 */
	abstract public function publish( array $row, array $payload ): SocialResult;

	/* ---- Connecting. Overridden by the platforms that support each way. ---- */

	/**
	 * Whether this platform is connected by sending the administrator to the platform to
	 * authorise, rather than by entering a credential on a form.
	 */
	public function uses_oauth(): bool {
		return 'app_password' !== (string) Platforms::value( static::platform(), 'auth' );
	}

	/**
	 * Where to send the administrator to authorise. An empty string when the platform is not
	 * connected this way or the developer application has not been set up yet.
	 */
	public function authorize_url( string $state, string $redirect_uri ): string {
		return '';
	}

	/**
	 * Finishes an authorisation and saves whatever accounts it granted access to.
	 *
	 * @param array  $params       The query values the platform sent back, already checked for state.
	 * @param string $redirect_uri The same callback address used to start it.
	 * @return array{ok:bool, message:string, account_ids:int[]}
	 */
	public function complete_oauth( array $params, string $redirect_uri ): array {
		return array(
			'ok'          => false,
			'message'     => sprintf( '%s is not connected by authorising on the platform.', Platforms::label( static::platform() ) ),
			'account_ids' => array(),
		);
	}

	/**
	 * Connects from values typed on a form, for a platform that works that way.
	 *
	 * @return array{ok:bool, message:string, account_ids:int[]}
	 */
	public function connect_with_form( array $input ): array {
		return array(
			'ok'          => false,
			'message'     => sprintf( '%s is not connected by entering a credential.', Platforms::label( static::platform() ) ),
			'account_ids' => array(),
		);
	}

	/**
	 * Asks the platform who this account is, to confirm the credential still works.
	 *
	 * @return array{ok:bool, message:string}
	 */
	abstract public function test( array $account ): array;

	/**
	 * Renews the credential where the platform allows it.
	 *
	 * @return array{ok:bool, changed:bool, message:string}
	 */
	public function refresh( array $account ): array {
		return array(
			'ok'      => true,
			'changed' => false,
			'message' => sprintf( '%s does not renew credentials automatically. Reconnect the account when it expires.', Platforms::label( static::platform() ) ),
		);
	}

	/**
	 * Asks the platform whether a post whose outcome is unknown actually exists.
	 *
	 * The default answer is that it cannot be told, which leaves the post to be looked at by a
	 * person rather than risking a duplicate. A platform whose API can answer overrides this.
	 */
	public function reconcile( array $row, array $account ): SocialResult {
		return SocialResult::unknown(
			'cannot_reconcile',
			sprintf(
				'%s cannot be asked whether this post exists, so it was not sent again. Check the account and either retry it or cancel it.',
				Platforms::label( static::platform() )
			)
		);
	}

	/* ---- Shared helpers ---- */

	protected function version(): string {
		return Platforms::version( static::platform() );
	}

	/**
	 * The decrypted credentials for the account this post belongs to.
	 */
	protected function credentials( array $account ): array {
		return SocialAccounts::credentials( (int) $account['id'] );
	}

	/**
	 * Makes a request to this platform.
	 */
	protected function request( string $url, array $args = array() ): array {
		return SocialHttp::request( static::platform(), $url, $args );
	}

	/**
	 * A request carrying a bearer token, which is how most of these APIs authenticate.
	 */
	protected function authed( string $url, string $token, array $args = array() ): array {
		$args['headers'] = array_merge(
			array( 'Authorization' => 'Bearer ' . $token ),
			(array) ( $args['headers'] ?? array() )
		);
		return $this->request( $url, $args );
	}

	/**
	 * The final text of the post: the written text, then the hashtags, then the article address
	 * where the platform makes one clickable in the text.
	 *
	 * The result is always within the platform's hard limit. When it would not be, the written
	 * text is shortened and the hashtags and the address are kept, because losing the address
	 * would leave the reader with no way to the article.
	 */
	protected function compose( array $payload ): string {
		$platform  = static::platform();
		$text      = trim( (string) ( $payload['text'] ?? '' ) );
		$hashtags  = trim( (string) ( $payload['hashtags'] ?? '' ) );
		$url       = trim( (string) ( $payload['url'] ?? '' ) );
		$link_mode = (string) Platforms::value( $platform, 'link_mode' );
		$limit     = (int) Platforms::value( $platform, 'hard_text_limit', 280 );

		$in_text = SocialSettings::includes_url() && '' !== $url
			&& in_array( $link_mode, array( Platforms::LINK_TEXT, Platforms::LINK_FACET ), true );

		// What the address costs. X charges every link the same fixed width whatever its length.
		$link_cost = 0;
		if ( $in_text ) {
			$weight    = (int) Platforms::value( $platform, 'link_weight', 0 );
			$link_cost = ( $weight > 0 ? $weight : $this->length( $url ) ) + 1;
		}

		$tag_cost = '' !== $hashtags ? $this->length( $hashtags ) + 2 : 0;
		$room     = $limit - $link_cost - $tag_cost;

		if ( $room < 20 ) {
			// The hashtags alone are crowding the post out, so they go rather than the article.
			$hashtags = '';
			$tag_cost = 0;
			$room     = $limit - $link_cost;
		}

		if ( $this->length( $text ) > $room ) {
			$text = SocialContentGenerator::clip( $text, $room );
		}

		$parts = array( $text );
		if ( '' !== $hashtags ) {
			$parts[] = $hashtags;
		}
		$composed = trim( implode( "\n\n", array_filter( $parts ) ) );

		if ( $in_text ) {
			$composed = trim( $composed . "\n" . $url );
		}

		return $composed;
	}

	/**
	 * How long a piece of text counts as on this platform.
	 *
	 * Most count characters. Bluesky counts graphemes, so an emoji or an accented letter made
	 * from two code points counts once rather than twice.
	 */
	protected function length( string $text ): int {
		if ( Platforms::BLUESKY === static::platform() && function_exists( 'grapheme_strlen' ) ) {
			$length = grapheme_strlen( $text );
			if ( false !== $length && null !== $length ) {
				return (int) $length;
			}
		}
		return (int) mb_strlen( $text );
	}

	/**
	 * Turns a failed request into the right outcome.
	 *
	 * The important distinction is between a request that certainly never arrived, which is safe
	 * to try again, and one that may have arrived, which is not. Everything else follows from
	 * what the platform said.
	 *
	 * @param array  $response From SocialHttp.
	 * @param string $stage    Where it failed, for the message: "uploading the image" and so on.
	 */
	protected function classify( array $response, string $stage = 'publishing' ): SocialResult {
		$detail = SocialHttp::describe( $response );
		$code   = (int) $response['code'];
		$label  = Platforms::label( static::platform() );

		// The platform never heard the request. Trying again cannot duplicate anything.
		if ( '' !== (string) $response['error'] && SocialHttp::definitely_not_sent( $response ) ) {
			return SocialResult::retry( 'network', sprintf( 'Could not reach %1$s while %2$s. The request did not arrive, so it is safe to try again. Detail: %3$s', $label, $stage, $detail ) );
		}

		// The platform was reached but the answer was not seen. It may have acted on the request.
		if ( '' !== (string) $response['error'] && 0 === strpos( (string) $response['error'], 'network' ) ) {
			return SocialResult::unknown( 'no_answer', sprintf( 'The request to %1$s was sent while %2$s but no answer came back, so it is not known whether the post was created. It was not sent again. Detail: %3$s', $label, $stage, $detail ) );
		}

		if ( 429 === $code ) {
			$wait = (int) $response['retry_after'];
			if ( $wait < 1 ) {
				$wait = (int) $response['rate_reset'];
			}
			$wait = $wait > 0 ? min( 21600, $wait ) : 900;
			return SocialResult::defer( 'rate_limited', sprintf( '%1$s rate limit reached. Waiting about %2$d minutes before trying again, as %1$s asked. Detail: %3$s', $label, (int) ceil( $wait / 60 ), $detail ), $wait );
		}

		if ( 401 === $code ) {
			return SocialResult::failed( 'auth_expired', sprintf( '%1$s refused the saved credential while %2$s. Reconnect the account on the Social Media page. Detail: %3$s', $label, $stage, $detail ) );
		}

		if ( 403 === $code ) {
			return SocialResult::failed( 'permission_denied', sprintf( '%1$s refused this request while %2$s. The account may not have the permission this needs, or the application may not have been approved for it. Detail: %3$s', $label, $stage, $detail ) );
		}

		if ( 404 === $code ) {
			return SocialResult::failed( 'not_found', sprintf( '%1$s could not find what this request referred to while %2$s. Detail: %3$s', $label, $stage, $detail ) );
		}

		if ( 408 === $code || $code >= 500 ) {
			return SocialResult::retry( 'platform_error', sprintf( '%1$s had a temporary problem while %2$s. Detail: %3$s', $label, $stage, $detail ) );
		}

		if ( 0 === $code ) {
			return SocialResult::unknown( 'no_answer', sprintf( 'The request to %1$s while %2$s gave no response code, so it is not known whether it succeeded. Detail: %3$s', $label, $stage, $detail ) );
		}

		// A request the platform understood and refused. Sending it again unchanged will fail again.
		return SocialResult::failed( 'rejected', sprintf( '%1$s rejected this request while %2$s. Detail: %3$s', $label, $stage, $detail ) );
	}

	/**
	 * Checks the platform's own published cap before sending, where it has one, using what this
	 * plugin has recorded. This is a courtesy check that avoids a certain rejection; the
	 * platform's own count is still the one that decides.
	 */
	protected function within_own_limit( array $account ): ?SocialResult {
		$limit = Platforms::publish_limit_24h( static::platform() );
		if ( $limit < 1 ) {
			return null;
		}
		$used = \ZZZCopy\Social\SocialQueue::published_last_24h( (int) $account['id'] );
		if ( $used < $limit ) {
			return null;
		}
		return SocialResult::defer(
			'own_limit_reached',
			sprintf( '%1$s allows %2$d posts per account in 24 hours, and this account has already had %3$d. Waiting an hour before trying again.', Platforms::label( static::platform() ), $limit, $used ),
			3600
		);
	}
}
