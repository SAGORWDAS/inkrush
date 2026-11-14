<?php
namespace ZZZCopy\Social;

use ZZZCopy\Events;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Social distribution preferences.
 *
 * The global switches live in the plugin's existing settings option, validated by Settings, so
 * they follow exactly the same conventions as every other setting. The per-platform choices are
 * one value per platform rather than one flat key, which that option cannot hold, so they live
 * in their own option and are validated here.
 */
final class SocialSettings {

	private const OPTION = 'zzzc_social_platforms';

	public const APPROVAL_AUTOMATIC = 'automatic';
	public const APPROVAL_MANUAL    = 'manual';

	public const WHEN_IMMEDIATE = 'immediate';
	public const WHEN_DELAY     = 'delay';
	public const WHEN_TIME      = 'time';

	private static ?array $cache = null;

	/**
	 * The global switches added to the plugin's settings option.
	 * Distribution is off until the administrator turns it on, so an upgrade never starts
	 * posting to social media on its own.
	 */
	public static function global_defaults(): array {
		return array(
			'social_enabled'             => 0,
			'social_auto_distribute'     => 1,
			'social_use_featured_image'  => 1,
			'social_generate_captions'   => 1,
			'social_generate_hashtags'   => 1,
			'social_platform_specific'   => 1,
			'social_history_enabled'     => 1,
			'social_approval_mode'       => self::APPROVAL_AUTOMATIC,
			'social_when'                => self::WHEN_IMMEDIATE,
			'social_delay_minutes'       => 0,
			'social_on_manual_publish'   => 0,
			'social_republish_on_edit'   => 0,
			'social_max_attempts'        => 4,
			'social_include_url'         => 1,
			'social_cta'                 => 'Full story: link in bio.',
			'social_retention_days'      => 365,
		);
	}

	/** Numeric ranges for the global switches, merged into Settings' own table. */
	public static function global_ranges(): array {
		return array(
			'social_delay_minutes'  => array( 0, 1440 ),
			'social_max_attempts'   => array( 1, 10 ),
			'social_retention_days' => array( 7, 3650 ),
		);
	}

	/** Fixed choices for the global switches, merged into Settings' own table. */
	public static function global_choices(): array {
		return array(
			'social_approval_mode' => array( self::APPROVAL_AUTOMATIC, self::APPROVAL_MANUAL ),
			'social_when'          => array( self::WHEN_IMMEDIATE, self::WHEN_DELAY, self::WHEN_TIME ),
		);
	}

	/** Global switches that are on or off. */
	public static function global_flags(): array {
		return array(
			'social_enabled',
			'social_auto_distribute',
			'social_use_featured_image',
			'social_generate_captions',
			'social_generate_hashtags',
			'social_platform_specific',
			'social_history_enabled',
			'social_on_manual_publish',
			'social_republish_on_edit',
			'social_include_url',
		);
	}

	/* ---- Global switches ---- */

	public static function enabled(): bool {
		return 1 === (int) Settings::get( 'social_enabled' );
	}

	public static function auto_distribute(): bool {
		return self::enabled() && 1 === (int) Settings::get( 'social_auto_distribute' );
	}

	public static function needs_approval(): bool {
		return self::APPROVAL_MANUAL === (string) Settings::get( 'social_approval_mode' );
	}

	public static function generates_captions(): bool {
		return 1 === (int) Settings::get( 'social_generate_captions' );
	}

	public static function generates_hashtags(): bool {
		return 1 === (int) Settings::get( 'social_generate_hashtags' );
	}

	public static function uses_featured_image(): bool {
		return 1 === (int) Settings::get( 'social_use_featured_image' );
	}

	public static function includes_url(): bool {
		return 1 === (int) Settings::get( 'social_include_url' );
	}

	public static function max_attempts(): int {
		return max( 1, (int) Settings::get( 'social_max_attempts' ) );
	}

	public static function call_to_action(): string {
		$text = trim( (string) Settings::get( 'social_cta' ) );
		return '' !== $text ? $text : 'Full story: link in bio.';
	}

	/**
	 * Seconds to wait before a newly queued post is published, from the global timing choice.
	 * A specific date and time is held per article, not here, so this covers only the first two.
	 */
	public static function delay_seconds(): int {
		if ( self::WHEN_DELAY !== (string) Settings::get( 'social_when' ) ) {
			return 0;
		}
		return max( 0, (int) Settings::get( 'social_delay_minutes' ) ) * 60;
	}

	/* ---- Per-platform choices ---- */

	public static function platform_defaults(): array {
		return array(
			'enabled'     => 0,
			'account_id'  => 0,
			'api_version' => '',
			'hashtags'    => '',
			'cta'         => '',
		);
	}

	/**
	 * Every platform's saved choices, with defaults filled in.
	 */
	public static function platforms(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$out    = array();
			foreach ( Platforms::ORDER as $platform ) {
				$row              = is_array( $stored[ $platform ] ?? null ) ? $stored[ $platform ] : array();
				$out[ $platform ] = array_merge( self::platform_defaults(), $row );
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	/**
	 * One saved value for one platform.
	 *
	 * @return mixed
	 */
	public static function platform( string $platform, string $key ) {
		$all = self::platforms();
		return $all[ $platform ][ $key ] ?? ( self::platform_defaults()[ $key ] ?? null );
	}

	public static function platform_enabled( string $platform ): bool {
		return Platforms::exists( $platform ) && 1 === (int) self::platform( $platform, 'enabled' );
	}

	/**
	 * The platforms that are switched on, distribution is on, and that have a usable account.
	 * This is the list the distribution engine works from.
	 *
	 * @return string[]
	 */
	public static function active_platforms(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		$out = array();
		foreach ( Platforms::ORDER as $platform ) {
			if ( self::platform_enabled( $platform ) && SocialAccounts::publishable( $platform ) ) {
				$out[] = $platform;
			}
		}
		return $out;
	}

	/**
	 * Saves per-platform choices. Unknown platforms and unknown keys are ignored, and an
	 * account that does not belong to the platform it is chosen for is refused.
	 *
	 * @param array $input Raw values, already unslashed, as platform => array of values.
	 * @return array{changed:string[], errors:array<string,string>}
	 */
	public static function save_platforms( array $input ): array {
		$current = self::platforms();
		$next    = $current;
		$changed = array();
		$errors  = array();

		foreach ( $input as $platform => $values ) {
			$platform = (string) $platform;
			if ( ! Platforms::exists( $platform ) || ! is_array( $values ) ) {
				continue;
			}

			$row = $current[ $platform ];

			if ( array_key_exists( 'enabled', $values ) ) {
				$row['enabled'] = empty( $values['enabled'] ) ? 0 : 1;
			}

			if ( array_key_exists( 'account_id', $values ) ) {
				$account_id = absint( $values['account_id'] );
				if ( $account_id > 0 ) {
					$account = SocialAccounts::get( $account_id );
					if ( ! $account || $account['platform'] !== $platform ) {
						$errors[ $platform . '_account_id' ] = 'Choose an account connected to this platform.';
						$account_id                          = (int) $row['account_id'];
					}
				}
				$row['account_id'] = $account_id;
			}

			if ( array_key_exists( 'api_version', $values ) ) {
				$version = trim( (string) $values['api_version'] );
				if ( '' !== $version && ! preg_match( '/^[A-Za-z0-9._\-]{1,20}$/', $version ) ) {
					$errors[ $platform . '_api_version' ] = 'Enter a version as the platform writes it, such as v26.0 or 202609, or leave it empty for the version this release was built against.';
				} else {
					$row['api_version'] = $version;
				}
			}

			if ( array_key_exists( 'hashtags', $values ) ) {
				$row['hashtags'] = self::clean_hashtags( (string) $values['hashtags'], $platform );
			}

			if ( array_key_exists( 'cta', $values ) ) {
				$row['cta'] = sanitize_text_field( (string) $values['cta'] );
			}

			// A platform cannot be switched on with no account to publish through.
			if ( 1 === (int) $row['enabled'] && ! SocialAccounts::publishable( $platform, (int) $row['account_id'] ) ) {
				$errors[ $platform . '_enabled' ] = sprintf( 'Connect a %s account before switching this platform on.', Platforms::label( $platform ) );
				$row['enabled']                   = 0;
			}

			if ( $row !== $current[ $platform ] ) {
				$changed[] = $platform;
			}
			$next[ $platform ] = $row;
		}

		if ( $changed ) {
			update_option( self::OPTION, $next, false );
			self::$cache = null;
			Events::log(
				'social',
				'info',
				sprintf( 'Social platform settings saved for: %s.', implode( ', ', array_map( array( Platforms::class, 'label' ), $changed ) ) ),
				array( 'context' => array( 'platforms' => $changed ) )
			);
		}

		return array(
			'changed' => $changed,
			'errors'  => $errors,
		);
	}

	/**
	 * Normalises a list of extra hashtags the administrator always wants on one platform.
	 * Returns them space separated, each with one leading hash, within the platform's limit.
	 */
	public static function clean_hashtags( string $raw, string $platform ): string {
		$limit = max( 0, (int) Platforms::value( $platform, 'hashtag_limit', 0 ) );
		if ( 0 === $limit ) {
			return '';
		}
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$out   = array();
		foreach ( $parts as $part ) {
			$tag = preg_replace( '/[^0-9A-Za-z_]/u', '', (string) $part );
			if ( '' === $tag || ctype_digit( $tag ) ) {
				continue;
			}
			$key = strtolower( $tag );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = '#' . $tag;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Forgets a disconnected account, so no platform is left pointing at a row that is gone.
	 */
	public static function forget_account( int $account_id ): void {
		$current = self::platforms();
		$next    = $current;
		$touched = false;
		foreach ( $next as $platform => $row ) {
			if ( (int) $row['account_id'] === $account_id ) {
				$next[ $platform ]['account_id'] = 0;
				$next[ $platform ]['enabled']    = 0;
				$touched                         = true;
			}
		}
		if ( $touched ) {
			update_option( self::OPTION, $next, false );
			self::$cache = null;
		}
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}
}
