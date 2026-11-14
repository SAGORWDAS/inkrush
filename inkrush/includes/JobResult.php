<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * What a job handler wants done with its job.
 */
final class JobResult {

	public const DONE  = 'done';
	public const RETRY = 'retry';
	public const DEFER = 'defer';
	public const FAIL  = 'fail';

	public string $outcome;
	public int $delay;
	public string $code;
	public string $message;

	private function __construct( string $outcome, int $delay = 0, string $code = '', string $message = '' ) {
		$this->outcome = $outcome;
		$this->delay   = $delay;
		$this->code    = $code;
		$this->message = $message;
	}

	public static function done(): self {
		return new self( self::DONE );
	}

	public static function retry( int $delay, string $code, string $message ): self {
		return new self( self::RETRY, $delay, $code, $message );
	}

	/**
	 * Try later without using up an attempt.
	 */
	public static function defer( int $delay, string $message = '' ): self {
		return new self( self::DEFER, $delay, '', $message );
	}

	public static function fail( string $code, string $message ): self {
		return new self( self::FAIL, 0, $code, $message );
	}
}
