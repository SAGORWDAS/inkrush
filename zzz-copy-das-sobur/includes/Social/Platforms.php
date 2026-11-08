<?php
namespace ZZZCopy\Social;

defined( 'ABSPATH' ) || exit;

/**
 * The six social platforms InkRush can distribute to, and the facts about each one.
 *
 * This is the only place these facts are written down. The Social Media page, the caption
 * generator, the adapters, the validation rules and the documentation all read them from here,
 * so a limit can never be right on one screen and wrong on another.
 *
 * Every limit below comes from the platform's own published API documentation. Where a platform
 * needs an approved application, a paid plan or a particular kind of account, that is recorded
 * in `requires` and shown to the administrator before they try to connect.
 */
final class Platforms {

	public const FACEBOOK  = 'facebook';
	public const INSTAGRAM = 'instagram';
	public const X         = 'x';
	public const LINKEDIN  = 'linkedin';
	public const THREADS   = 'threads';
	public const BLUESKY   = 'bluesky';

	/** Display order on every screen. */
	public const ORDER = array( self::FACEBOOK, self::INSTAGRAM, self::X, self::LINKEDIN, self::THREADS, self::BLUESKY );

	/**
	 * How a platform wants the article link.
	 *
	 * text      The URL goes in the post text and is clickable.
	 * facet     The URL goes in the text and the adapter marks it up so it becomes a link.
	 * attached  The URL is sent as its own field, not inside the text.
	 * none      The platform does not make URLs in the text clickable, so a call to action is used.
	 */
	public const LINK_TEXT     = 'text';
	public const LINK_FACET    = 'facet';
	public const LINK_ATTACHED = 'attached';
	public const LINK_NONE     = 'none';

	/**
	 * All six platforms with their identity, limits and requirements.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		return array(

			self::FACEBOOK => array(
				'label'            => 'Facebook Pages',
				'account_noun'     => 'Page',
				'api'              => 'Meta Graph API',
				'api_version'      => 'v26.0',
				'host'             => 'graph.facebook.com',
				'auth'             => 'oauth2',
				'text_limit'       => 5000,
				'hard_text_limit'  => 63206,
				'hashtag_limit'    => 10,
				'link_mode'        => self::LINK_TEXT,
				'image'            => 'optional',
				'image_max_bytes'  => 10485760,
				'image_mime'       => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'image_public_url' => true,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'long_lived',
				'tone'             => 'Warm and conversational, written for a general audience. A short hook, then the key fact.',
				'requires'         => array(
					'app'        => 'A Meta app of type Business, in the Meta for Developers dashboard.',
					'products'   => 'Add the Facebook Login product and set this site\'s callback as a Valid OAuth Redirect URI.',
					'scopes'     => 'pages_show_list, pages_read_engagement, pages_manage_posts, business_management',
					'account'    => 'A Facebook Page you hold a role on. Personal profiles cannot be published to through the API, and InkRush does not attempt it.',
					'approval'   => 'App Review for the pages_* permissions, plus Business Verification, before any Page other than your own test Pages can be used. Expect several days.',
					'cost'       => 'No charge for publishing.',
					'rate_limit' => 'Platform rate limiting is calculated per app and per Page. InkRush posts a few times a day, far below the limit, and backs off when Meta returns code 4, 17, 32 or 613.',
					'limits'     => 'Only Pages. No personal profiles, no Groups.',
				),
				'sends'            => 'The post text InkRush generated, your article\'s web address, and the featured image\'s web address so Meta can fetch it.',
			),

			self::INSTAGRAM => array(
				'label'            => 'Instagram',
				'account_noun'     => 'Professional account',
				'api'              => 'Instagram Content Publishing API',
				'api_version'      => 'v26.0',
				'host'             => 'graph.facebook.com',
				'auth'             => 'oauth2',
				'text_limit'       => 2200,
				'hard_text_limit'  => 2200,
				'hashtag_limit'    => 30,
				'link_mode'        => self::LINK_NONE,
				'image'            => 'required',
				'image_max_bytes'  => 8388608,
				'image_mime'       => array( 'image/jpeg' ),
				'image_public_url' => true,
				'image_min_ratio'  => 0.8,
				'image_max_ratio'  => 1.91,
				'publish_limit_24h' => 50,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'long_lived',
				'tone'             => 'Visual and immediate. One striking line about the picture or the moment, then the point of the story.',
				'requires'         => array(
					'app'        => 'The same Meta app as Facebook, with the Instagram Graph API product added.',
					'products'   => 'Instagram Graph API.',
					'scopes'     => 'instagram_basic, instagram_content_publish, pages_show_list, pages_read_engagement',
					'account'    => 'An Instagram Business or Creator account linked to a Facebook Page you hold a role on. Personal Instagram accounts are not eligible.',
					'approval'   => 'App Review for instagram_content_publish, plus Business Verification.',
					'cost'       => 'No charge for publishing.',
					'rate_limit' => '50 published posts per account in any rolling 24 hours, counted by Meta. InkRush reads the remaining allowance before it publishes.',
					'limits'     => 'A post must carry an image, and Instagram fetches that image from a public web address, so the site cannot be private or password protected. A web address in the caption is not clickable, so InkRush writes a call to action pointing at the link in your bio instead.',
				),
				'sends'            => 'The caption InkRush generated and the featured image\'s web address so Instagram can fetch it. Your article address is not placed in the caption, because Instagram would not make it clickable.',
			),

			self::X => array(
				'label'            => 'X',
				'account_noun'     => 'Account',
				'api'              => 'X API v2',
				'api_version'      => '2',
				'host'             => 'api.x.com',
				'auth'             => 'oauth2_pkce',
				'text_limit'       => 280,
				'hard_text_limit'  => 280,
				'hashtag_limit'    => 3,
				'link_mode'        => self::LINK_TEXT,
				'link_weight'      => 23,
				'image'            => 'optional',
				'image_max_bytes'  => 5242880,
				'image_mime'       => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'image_public_url' => false,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'refresh_token',
				'tone'             => 'Tight and factual. News-wire brevity, no padding, no hype.',
				'requires'         => array(
					'app'        => 'A project and app in the X Developer Portal, with OAuth 2.0 and a Confidential client.',
					'products'   => 'User authentication set up, with this site\'s callback as a Callback URI.',
					'scopes'     => 'tweet.read, tweet.write, users.read, media.write, offline.access',
					'account'    => 'Any X account that can post. The account must authorise the app.',
					'approval'   => 'No review for posting, but the access tier decides whether posting is possible at all.',
					'cost'       => 'PAID. The Free tier allows only a very small number of posts per month and is not usable for daily distribution. Posting at InkRush\'s volume needs at least the Basic tier, which is billed monthly by X. Check the current price in the Developer Portal before you rely on this platform.',
					'rate_limit' => 'Per-app and per-user post limits apply over 24 hours and over 15 minutes. InkRush reads the x-rate-limit-reset header and waits.',
					'limits'     => 'A link counts as 23 characters towards the 280, whatever its real length. InkRush reserves that space.',
				),
				'sends'            => 'The post text InkRush generated, your article\'s web address, and the featured image\'s bytes, uploaded directly.',
			),

			self::LINKEDIN => array(
				'label'            => 'LinkedIn',
				'account_noun'     => 'Profile or organisation',
				'api'              => 'LinkedIn Posts API',
				'api_version'      => '202609',
				'host'             => 'api.linkedin.com',
				'auth'             => 'oauth2',
				'text_limit'       => 2900,
				'hard_text_limit'  => 3000,
				'hashtag_limit'    => 5,
				'link_mode'        => self::LINK_ATTACHED,
				'image'            => 'optional',
				'image_max_bytes'  => 10485760,
				'image_mime'       => array( 'image/jpeg', 'image/png' ),
				'image_public_url' => false,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'refresh_token',
				'tone'             => 'Professional and measured. Why the story matters, without jargon or salesmanship.',
				'requires'         => array(
					'app'        => 'An app in the LinkedIn Developer Portal, linked to a LinkedIn Page you administer.',
					'products'   => 'Share on LinkedIn for posting as a person. Community Management API for posting as an organisation.',
					'scopes'     => 'w_member_social for a personal profile. w_organization_social and r_organization_social for a Page. openid and profile to identify the account.',
					'account'    => 'Posting as an organisation needs an ADMINISTRATOR or CONTENT_ADMIN role on that Page. Not every account has the same permissions, so InkRush asks LinkedIn which organisations the connected account may post for, and offers only those.',
					'approval'   => 'Share on LinkedIn is self-serve. The Community Management API needs LinkedIn\'s approval, which is reviewed case by case and can be refused.',
					'cost'       => 'No charge for publishing.',
					'rate_limit' => 'A daily per-app and per-member post limit applies. LinkedIn returns 429 with a Retry-After header, which InkRush honours.',
					'limits'     => 'Access tokens last 60 days; a refresh token is issued only to approved apps. InkRush warns you 7 days before a token expires.',
				),
				'sends'            => 'The post text InkRush generated, your article\'s web address as the attached link, and the featured image\'s bytes, uploaded directly.',
			),

			self::THREADS => array(
				'label'            => 'Threads',
				'account_noun'     => 'Threads profile',
				'api'              => 'Threads API',
				'api_version'      => 'v1.0',
				'host'             => 'graph.threads.net',
				'auth'             => 'oauth2',
				'text_limit'       => 500,
				'hard_text_limit'  => 500,
				'hashtag_limit'    => 1,
				'publish_limit_24h' => 250,
				'link_mode'        => self::LINK_TEXT,
				'image'            => 'optional',
				'image_max_bytes'  => 8388608,
				'image_mime'       => array( 'image/jpeg', 'image/png' ),
				'image_public_url' => true,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'long_lived',
				'tone'             => 'Direct and human, like telling a colleague. Plain words, one idea.',
				'requires'         => array(
					'app'        => 'A Meta app with the Threads API use case added. This is separate from the Facebook app settings, although it can be the same app.',
					'products'   => 'Threads API, with this site\'s callback as a Redirect Callback URL.',
					'scopes'     => 'threads_basic, threads_content_publish',
					'account'    => 'A Threads profile. It is reached through its own Threads user identifier, not through a Facebook Page.',
					'approval'   => 'App Review for threads_content_publish before any profile other than your own test profile can be used.',
					'cost'       => 'No charge for publishing.',
					'rate_limit' => '250 published posts per profile in any rolling 24 hours, and Threads counts hashtags strictly: only one is used per post.',
					'limits'     => 'Threads accepts at most one hashtag per post, so InkRush sends the single most relevant one. An image is fetched from a public web address.',
				),
				'sends'            => 'The post text InkRush generated, your article\'s web address, and the featured image\'s web address so Threads can fetch it.',
			),

			self::BLUESKY => array(
				'label'            => 'Bluesky',
				'account_noun'     => 'Account',
				'api'              => 'AT Protocol',
				'api_version'      => 'xrpc',
				'host'             => 'bsky.social',
				'auth'             => 'app_password',
				'text_limit'       => 300,
				'hard_text_limit'  => 300,
				'hashtag_limit'    => 3,
				'link_mode'        => self::LINK_FACET,
				'image'            => 'optional',
				'image_max_bytes'  => 976560,
				'image_mime'       => array( 'image/jpeg', 'image/png' ),
				'image_public_url' => false,
				'alt_text'         => true,
				'multi_account'    => true,
				'token_refresh'    => 'session',
				'tone'             => 'Plain and slightly informal, no marketing voice. Straight to the fact.',
				'requires'         => array(
					'app'        => 'None. Bluesky needs no developer application.',
					'products'   => 'None.',
					'scopes'     => 'Not applicable. An app password carries the account\'s own permissions.',
					'account'    => 'Any Bluesky account. Create an app password in Bluesky under Settings, Privacy and security, App passwords. Never enter your main account password: InkRush will not accept one knowingly and does not need one.',
					'approval'   => 'None.',
					'cost'       => 'No charge.',
					'rate_limit' => 'Writes are limited per account per hour and per day. InkRush honours the ratelimit-reset header.',
					'limits'     => 'The 300 character limit counts graphemes, not bytes, so an emoji counts as one. Images must be under 976.56 KB, so InkRush re-encodes the featured image when it is larger.',
				),
				'sends'            => 'The post text InkRush generated, your article\'s web address as a link and as a link card, and the featured image\'s bytes, uploaded directly.',
			),
		);
	}

	public static function exists( string $platform ): bool {
		return in_array( $platform, self::ORDER, true );
	}

	/**
	 * One platform's facts, or null when the name is not one of the six.
	 */
	public static function get( string $platform ): ?array {
		$all = self::all();
		return $all[ $platform ] ?? null;
	}

	/**
	 * One value for one platform, with a fallback when the platform does not define it.
	 *
	 * @param string $platform One of the six.
	 * @param string $key      Key from the registry above.
	 * @param mixed  $fallback Returned when the platform or key is unknown.
	 * @return mixed
	 */
	public static function value( string $platform, string $key, $fallback = null ) {
		$row = self::get( $platform );
		return $row[ $key ] ?? $fallback;
	}

	public static function label( string $platform ): string {
		return (string) self::value( $platform, 'label', $platform );
	}

	/**
	 * The API version this platform is called with.
	 *
	 * Meta retires a Graph API version about two years after release, and LinkedIn expects a
	 * YYYYMM value that moves every month, so neither can be left to a fixed value in the code
	 * for the life of the plugin. The value saved on the Social Media page wins, then the
	 * filter, then the version this release was written against and last confirmed.
	 */
	public static function version( string $platform ): string {
		$saved = SocialSettings::platform( $platform, 'api_version' );
		$saved = is_string( $saved ) ? trim( $saved ) : '';
		if ( '' !== $saved ) {
			return $saved;
		}
		return (string) apply_filters( 'zzzc_social_api_version', (string) self::value( $platform, 'api_version', '' ), $platform );
	}

	/**
	 * Whether the administrator may set this platform's API version themselves. Only the two
	 * platforms that rotate their versions on a timetable need it.
	 */
	public static function version_is_editable( string $platform ): bool {
		return in_array( $platform, array( self::FACEBOOK, self::INSTAGRAM, self::LINKEDIN, self::THREADS ), true );
	}

	/**
	 * How many posts the platform itself allows per account per 24 hours, or 0 when the
	 * platform publishes no such figure.
	 */
	public static function publish_limit_24h( string $platform ): int {
		return (int) self::value( $platform, 'publish_limit_24h', 0 );
	}

	/**
	 * Characters available for the post text, after reserving room for the article link
	 * where the platform counts a link as a fixed width.
	 */
	public static function text_budget( string $platform, bool $with_link ): int {
		$limit = (int) self::value( $platform, 'text_limit', 280 );
		if ( ! $with_link || self::LINK_TEXT !== self::value( $platform, 'link_mode' ) ) {
			return $limit;
		}
		$weight = (int) self::value( $platform, 'link_weight', 0 );
		// One space before the link, plus the link itself.
		return max( 40, $limit - ( $weight > 0 ? $weight + 1 : 0 ) );
	}

	public static function needs_image( string $platform ): bool {
		return 'required' === self::value( $platform, 'image' );
	}

	/**
	 * Whether this platform fetches the image from a public web address, rather than
	 * receiving its bytes. Those platforms cannot publish from a private or local site.
	 */
	public static function needs_public_image_url( string $platform ): bool {
		return (bool) self::value( $platform, 'image_public_url', false );
	}
}
