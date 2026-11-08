<?php
namespace ZZZCopy\Social;

defined( 'ABSPATH' ) || exit;

/**
 * What an adapter did, or could not do.
 *
 * This is deliberately separate from JobResult. A job can be retried freely, but a publishing
 * attempt cannot: sending the same post twice is worse than not sending it at all. So this class
 * has an outcome JobResult does not have, UNKNOWN, for an attempt that may or may not have
 * reached the platform. UNKNOWN never retries. It sends the post to be reconciled, which asks
 * the platform what actually happened before anything else is sent.
 */
final class SocialResult {

	/** The platform confirmed the post and returned an identifier for it. */
	public const PUBLISHED = 'published';
	/** Nothing was published, the cause may pass, and an attempt is used up. */
	public const RETRY = 'retry';
	/** Nothing was published, and no attempt is used up: a rate limit or a cool-down. */
	public const DEFER = 'defer';
	/** Nothing was published and nothing will change without the administrator acting. */
	public const FAILED = 'failed';
	/** It is not known whether the post was published. It must be checked, never resent. */
	public const UNKNOWN = 'unknown';

	public string $outcome;
	public string $code;
	public string $message;
	public int $delay;
	public string $external_id;
	public string $external_url;
	/** An identifier for work in progress on the platform, such as a media container. */
	public string $remote_ref;

	private function __construct( string $outcome, string $code = '', string $message = '', int $delay = 0, string $external_id = '', string $external_url = '', string $remote_ref = '' ) {
		$this->outcome      = $outcome;
		$this->code         = $code;
		$this->message      = $message;
		$this->delay        = $delay;
		$this->external_id  = $external_id;
		$this->external_url = $external_url;
		$this->remote_ref   = $remote_ref;
	}

	/**
	 * A confirmed publication. The identifier must be one the platform returned: an adapter
	 * may never invent one, and may never report success without it.
	 */
	public static function published( string $external_id, string $external_url = '' ): self {
		return new self( self::PUBLISHED, '', '', 0, $external_id, $external_url );
	}

	public static function retry( string $code, string $message, int $delay = 0 ): self {
		return new self( self::RETRY, $code, $message, $delay );
	}

	public static function defer( string $code, string $message, int $delay ): self {
		return new self( self::DEFER, $code, $message, max( 1, $delay ) );
	}

	public static function failed( string $code, string $message ): self {
		return new self( self::FAILED, $code, $message );
	}

	/**
	 * The attempt may have reached the platform.
	 *
	 * @param string $remote_ref Anything that helps find the post afterwards, such as a media
	 *                           container id, or an empty string when there is nothing to go on.
	 */
	public static function unknown( string $code, string $message, string $remote_ref = '' ): self {
		return new self( self::UNKNOWN, $code, $message, 0, '', '', $remote_ref );
	}

	public function is_published(): bool {
		return self::PUBLISHED === $this->outcome && '' !== $this->external_id;
	}
}
