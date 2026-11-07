<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Global settings stored in one option, with validation for every value.
 */
final class Settings {

	private const OPTION = 'zzzc_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			// Schedule.
			'timezone'                   => '+10:30',
			'daily_target'               => 3,
			'interval_hours'             => 6,
			'first_time'                 => '10:00',
			'daily_cap'                  => 3,
			'cap_scope'                  => 'all',
			'catchup'                    => 'next',
			'slot_grace_minutes'         => 30,
			'catchup_gap_minutes'        => 30,
			'rotation_mode'              => 'round_robin',
			'rotation_pool'              => array(),
			'slot_categories'            => array(),
			'no_repeat'                  => 1,
			'category_fallback'          => 1,
			'discovery_interval_minutes' => 60,
			'category_buffer'            => 1,
			'research_lead_hours'        => 4,
			'daily_ai_limit'             => 12,
			'max_article_age_hours'      => 48,
			'past_hour_enabled'          => 1,
			'past_hour_prep_minutes'     => 120,
			'past_hour_max_age_minutes'  => 120,
			'auto_stop_days'             => 0,
			'auto_stop_posts'            => 0,
			// Publishing.
			'image_query_count'          => 4,
			'image_candidates_per_query' => 20,
			'image_min_width'            => 1200,
			'image_min_height'           => 675,
			'image_preferred_ratio'      => 1.78,
			'image_min_score'            => 65,
			'image_max_file_mb'          => 15,
			'image_max_attempts'         => 3,
			'image_api_gap_seconds'      => 3,
			'image_attribution'          => 'all',
			'image_allow_share_alike'    => 1,
			'publish_mode'               => 'publish',
			'tag_mode'                   => 'create',
			'category_auto_create'       => 1,
			'attribution'                => 'meta',
			'post_author'                => 0,
			// Single user: every post is credited to the Post author. Multiple users: each author's own schedule.
			'author_mode'                => 'single',
			// AI.
			'model_id'                   => 'openai/gpt-6-luna',
			'ai_timeout'                 => 120,
			'ai_max_retries'             => 3,
			'max_output_tokens'          => 8000,
			'tag_policy'                 => 'trim_extra',
			'emdash_policy'              => 'replace',
			'min_article_words'          => 600,
			// Source retrieval.
			'google_rss_enabled'         => 1,
			'min_source_words'           => 250,
			'partial_min_words'          => 80,
			'max_source_chars'           => 30000,
			'http_timeout'               => 20,
			'host_gap_seconds'           => 5,
			'google_gap_seconds'         => 30,
			'fetch_max_retries'          => 3,
			'max_items_per_fetch'        => 30,
			// Duplicate detection.
			'headline_similarity'        => 0.75,
			'content_similarity'         => 0.85,
			'resolver_similarity'        => 0.70,
			'dedupe_window_days'         => 14,
			// Retention.
			'retention_source_days'      => 30,
			'retention_events_days'      => 90,
			'retention_failed_days'      => 30,
			'delete_data_on_uninstall'   => 0,
			// Worker.
			'run_budget_seconds'         => 50,
		);
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
		self::$cache = null;
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Validates and saves the given values. Unknown keys are ignored.
	 *
	 * @param array $values Raw values, already unslashed.
	 * @return array{changed:string[], errors:array<string,string>}
	 */
	public static function update( array $values ): array {
		$current = self::all();
		$next    = $current;
		$changed = array();
		$errors  = array();

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				continue;
			}
			$result = self::sanitize( $key, $value );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}
			if ( $current[ $key ] !== $result ) {
				$changed[] = $key;
			}
			$next[ $key ] = $result;
		}

		// Preparing earlier than the story age limit only finds stories too old to use.
		$prep = 'past_hour_prep_minutes';
		$age  = 'past_hour_max_age_minutes';
		if ( $next[ $prep ] > $next[ $age ] && in_array( $prep, $changed, true ) ) {
			$errors[ $prep ] = sprintf( 'Must be no more than the maximum story age, %d minutes. An earlier start only finds stories too old to use.', $next[ $age ] );
			$next[ $prep ]   = $current[ $prep ];
			$changed         = array_values( array_diff( $changed, array( $prep ) ) );
		}
		if ( $next[ $prep ] > $next[ $age ] ) {
			$errors[ $age ] = sprintf( 'Must be at least the preparation time, %d minutes. Lower the preparation time first, or both together.', $next[ $prep ] );
			$next[ $age ]   = $current[ $age ];
			$changed        = array_values( array_diff( $changed, array( $age ) ) );
		}

		if ( $changed ) {
			update_option( self::OPTION, $next, false );
			self::$cache = null;
		}

		return array(
			'changed' => $changed,
			'errors'  => $errors,
		);
	}

	/**
	 * @return mixed|\WP_Error
	 */
	public static function sanitize( string $key, $value ) {
		$ranges = array(
			'image_query_count'          => array( 3, 5 ),
			'image_candidates_per_query' => array( 5, 50 ),
			'image_min_width'            => array( 600, 10000 ),
			'image_min_height'           => array( 300, 10000 ),
			'image_min_score'            => array( 1, 100 ),
			'image_max_file_mb'          => array( 1, 100 ),
			'image_max_attempts'         => array( 1, 10 ),
			'image_api_gap_seconds'      => array( 0, 60 ),
			'daily_target'               => array( 1, 48 ),
			'interval_hours'             => array( 1, 24 ),
			'daily_cap'                  => array( 1, 96 ),
			'slot_grace_minutes'         => array( 1, 720 ),
			'catchup_gap_minutes'        => array( 0, 720 ),
			'discovery_interval_minutes' => array( 5, 1440 ),
			'category_buffer'            => array( 1, 20 ),
			'research_lead_hours'        => array( 1, 48 ),
			'daily_ai_limit'             => array( 1, 500 ),
			'max_article_age_hours'      => array( 1, 720 ),
			'past_hour_prep_minutes'     => array( 15, 180 ),
			'past_hour_max_age_minutes'  => array( 60, 180 ),
			'auto_stop_days'             => array( 0, 365 ),
			'auto_stop_posts'            => array( 0, 10000 ),
			'post_author'                => array( 0, PHP_INT_MAX ),
			'ai_timeout'                 => array( 10, 600 ),
			'ai_max_retries'             => array( 1, 10 ),
			'max_output_tokens'          => array( 256, 200000 ),
			'min_article_words'          => array( 50, 20000 ),
			'min_source_words'           => array( 20, 20000 ),
			'partial_min_words'          => array( 1, 20000 ),
			'max_source_chars'           => array( 1000, 500000 ),
			'http_timeout'               => array( 3, 120 ),
			'host_gap_seconds'           => array( 0, 3600 ),
			'google_gap_seconds'         => array( 1, 3600 ),
			'fetch_max_retries'          => array( 1, 10 ),
			'max_items_per_fetch'        => array( 1, 200 ),
			'dedupe_window_days'         => array( 1, 365 ),
			'retention_source_days'      => array( 1, 3650 ),
			'retention_events_days'      => array( 1, 3650 ),
			'retention_failed_days'      => array( 1, 3650 ),
			'run_budget_seconds'         => array( 10, 280 ),
		);

		if ( isset( $ranges[ $key ] ) ) {
			if ( ! is_numeric( $value ) || (int) $value != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons
				return new \WP_Error( 'invalid', 'Enter a whole number.' );
			}
			$number = (int) $value;
			if ( $number < $ranges[ $key ][0] || $number > $ranges[ $key ][1] ) {
				return new \WP_Error( 'invalid', sprintf( 'Enter a number from %d to %d.', $ranges[ $key ][0], $ranges[ $key ][1] ) );
			}
			if ( 'post_author' === $key && $number && $number !== (int) self::get( 'post_author' ) && ! Authors::is_active( $number ) ) {
				return new \WP_Error( 'invalid', 'Only an active author can be chosen. Grant access on the Authors page first.' );
			}
			return $number;
		}

		$choices = array(
			'cap_scope'     => array( 'all', 'published' ),
			'catchup'       => array( 'next', 'skip' ),
			'rotation_mode' => array( 'round_robin', 'weighted', 'per_slot' ),
			'publish_mode'  => array( 'draft', 'publish' ),
			'tag_mode'      => array( 'create', 'reuse' ),
			'attribution'   => array( 'meta', 'visible' ),
			'tag_policy'    => array( 'trim_extra', 'strict' ),
			'emdash_policy' => array( 'replace', 'hold', 'allow' ),
			'image_attribution' => array( 'all', 'required' ),
			'author_mode'   => array( 'single', 'multiple' ),
		);

		if ( isset( $choices[ $key ] ) ) {
			if ( ! in_array( $value, $choices[ $key ], true ) ) {
				return new \WP_Error( 'invalid', 'Choose one of the listed options.' );
			}
			return $value;
		}

		switch ( $key ) {
			case 'image_preferred_ratio':
				if ( ! is_numeric( $value ) || (float) $value < 1 || (float) $value > 3 ) {
					return new \WP_Error( 'invalid', 'Enter a ratio from 1.00 to 3.00. For example, 16:9 is 1.78.' );
				}
				return round( (float) $value, 2 );

			case 'image_allow_share_alike':
			case 'past_hour_enabled':
			case 'no_repeat':
			case 'category_fallback':
			case 'category_auto_create':
			case 'google_rss_enabled':
			case 'delete_data_on_uninstall':
				return empty( $value ) ? 0 : 1;

			case 'timezone':
				$timezone = trim( (string) $value );
				if ( ! self::is_valid_timezone( $timezone ) ) {
					return new \WP_Error( 'invalid', 'Enter a timezone name such as Australia/Adelaide, or a fixed offset such as +10:30.' );
				}
				return $timezone;

			case 'first_time':
				$time = trim( (string) $value );
				if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
					return new \WP_Error( 'invalid', 'Enter a 24-hour time such as 10:00.' );
				}
				return $time;

			case 'model_id':
				$model = trim( (string) $value );
				if ( ! preg_match( '/^[A-Za-z0-9._\-]+\/[A-Za-z0-9._:\-]+$/', $model ) ) {
					return new \WP_Error( 'invalid', 'Enter an OpenRouter model identifier such as openai/gpt-6-luna. A web page address is not a model identifier.' );
				}
				return $model;

			case 'headline_similarity':
			case 'content_similarity':
			case 'resolver_similarity':
				if ( ! is_numeric( $value ) || (float) $value < 0.3 || (float) $value > 1 ) {
					return new \WP_Error( 'invalid', 'Enter a value from 0.30 to 1.00.' );
				}
				return round( (float) $value, 2 );

			case 'rotation_pool':
				return array_values( array_unique( array_map( 'absint', (array) $value ) ) );

			case 'slot_categories':
				$map = array();
				foreach ( (array) $value as $index => $category_id ) {
					$map[ absint( $index ) ] = absint( $category_id );
				}
				return $map;
		}

		return new \WP_Error( 'invalid', 'Unknown setting.' );
	}

	public static function is_valid_timezone( string $timezone ): bool {
		if ( '' === $timezone ) {
			return false;
		}
		if ( preg_match( '/^[+-](0\d|1[0-4]):[0-5]\d$/', $timezone ) ) {
			return true;
		}
		return in_array( $timezone, timezone_identifiers_list(), true );
	}
}
