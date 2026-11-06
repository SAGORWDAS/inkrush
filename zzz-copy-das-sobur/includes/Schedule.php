<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Publishing slots, timezone handling and category rotation.
 */
final class Schedule {

	public const PENDING  = 'PENDING';
	public const FILLED   = 'FILLED';
	public const DEFERRED = 'DEFERRED';
	public const MISSED   = 'MISSED';
	public const SKIPPED  = 'SKIPPED';

	public static function timezone(): \DateTimeZone {
		$value = (string) Settings::get( 'timezone' );
		try {
			return new \DateTimeZone( $value ?: '+10:30' );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( '+10:30' );
		}
	}

	public static function now_local(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( self::timezone() );
	}

	public static function today(): string {
		return self::now_local()->format( 'Y-m-d' );
	}

	/**
	 * Local calendar day containing a timestamp, in the configured timezone.
	 */
	public static function local_day( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( self::timezone() )->format( 'Y-m-d' );
	}

	/**
	 * UTC start and end of a local day.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function day_bounds_utc( string $local_date ): array {
		$tz    = self::timezone();
		$start = new \DateTimeImmutable( $local_date . ' 00:00:00', $tz );
		$end   = $start->modify( '+1 day' );
		return array( gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ), gmdate( 'Y-m-d H:i:s', $end->getTimestamp() - 1 ) );
	}

	/**
	 * Slot start times for a local date, spaced in wall-clock time.
	 * Slots that would cross midnight are not created.
	 *
	 * @return \DateTimeImmutable[] Indexed by slot number.
	 */
	public static function slot_times( string $local_date ): array {
		$tz       = self::timezone();
		$target   = (int) Settings::get( 'daily_target' );
		$interval = (int) Settings::get( 'interval_hours' );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', (string) Settings::get( 'first_time' ) ) );

		$times = array();
		for ( $i = 0; $i < $target; $i++ ) {
			$minutes = $hour * 60 + $minute + $i * $interval * 60;
			if ( $minutes >= 1440 ) {
				break;
			}
			$times[ $i ] = new \DateTimeImmutable( sprintf( '%s %02d:%02d:00', $local_date, (int) floor( $minutes / 60 ), $minutes % 60 ), $tz );
		}
		return $times;
	}

	/**
	 * How many of the requested daily slots fit before midnight.
	 */
	public static function slots_that_fit(): int {
		return count( self::slot_times( self::today() ) );
	}

	/**
	 * Creates today's and tomorrow's slots if they do not exist yet.
	 */
	public static function ensure_slots(): void {
		$today    = self::now_local();
		$tomorrow = $today->modify( '+1 day' );
		foreach ( array( $today, $tomorrow ) as $date ) {
			self::ensure_day( $date->format( 'Y-m-d' ) );
		}
	}

	private static function ensure_day( string $local_date ): void {
		global $wpdb;
		$table = Db::table( 'slots' );

		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE local_date = %s", $local_date ) ); // phpcs:ignore WordPress.DB
		if ( $existing > 0 ) {
			return;
		}

		$used = array();
		foreach ( self::slot_times( $local_date ) as $index => $time ) {
			$category_id = self::pick_category( $local_date, (int) $index, $used );
			if ( $category_id ) {
				$used[] = $category_id;
			}
			// A slot already in the past when first planned, such as when starting mid-day, was never really open.
			$passed = $time->getTimestamp() <= Clock::now();
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (slot_key, local_date, slot_index, due_at, category_id, status, status_reason, updated_at) VALUES (%s, %s, %d, %s, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB
					$local_date . 'T' . $time->format( 'H:i' ),
					$local_date,
					(int) $index,
					gmdate( 'Y-m-d H:i:s', $time->getTimestamp() ),
					$category_id,
					$passed ? self::MISSED : self::PENDING,
					$passed ? 'This time had already passed when the day was planned.' : '',
					Clock::mysql()
				)
			);
		}
	}

	/**
	 * Removes unused future slots after a schedule change, then rebuilds them.
	 */
	public static function rebuild_future(): void {
		global $wpdb;
		$table = Db::table( 'slots' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND due_at > %s AND prep_started_at IS NULL", self::PENDING, Clock::mysql() ) ); // phpcs:ignore WordPress.DB
		self::fill_gaps();
	}

	/**
	 * Adds slots missing after a rebuild, without touching slots already used or passed.
	 */
	private static function fill_gaps(): void {
		global $wpdb;
		$table = Db::table( 'slots' );
		$now   = self::now_local();
		foreach ( array( $now, $now->modify( '+1 day' ) ) as $date ) {
			$local_date = $date->format( 'Y-m-d' );
			$used       = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT category_id FROM {$table} WHERE local_date = %s AND category_id > 0", $local_date ) ) ); // phpcs:ignore WordPress.DB
			foreach ( self::slot_times( $local_date ) as $index => $time ) {
				if ( $time->getTimestamp() <= Clock::now() ) {
					continue;
				}
				$category_id = self::pick_category( $local_date, (int) $index, $used );
				if ( $category_id ) {
					$used[] = $category_id;
				}
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (slot_key, local_date, slot_index, due_at, category_id, status, updated_at) VALUES (%s, %s, %d, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB
						$local_date . 'T' . $time->format( 'H:i' ),
						$local_date,
						(int) $index,
						gmdate( 'Y-m-d H:i:s', $time->getTimestamp() ),
						$category_id,
						self::PENDING,
						Clock::mysql()
					)
				);
			}
		}
	}

	/**
	 * Chooses the category for a slot.
	 *
	 * @param string $local_date Local date of the slot.
	 * @param int    $index      Slot number on that day.
	 * @param int[]  $used       Categories already chosen that day.
	 */
	public static function pick_category( string $local_date, int $index, array $used ): int {
		$mode  = (string) Settings::get( 'rotation_mode' );
		$pool  = self::pool_for_date( $local_date );
		if ( ! $pool ) {
			return 0;
		}

		$slot_map = (array) Settings::get( 'slot_categories' );
		$explicit = (int) ( $slot_map[ $index ] ?? 0 );
		if ( $explicit ) {
			foreach ( $pool as $row ) {
				if ( (int) $row['id'] === $explicit ) {
					return $explicit;
				}
			}
		}

		$candidates = $pool;
		if ( Settings::get( 'no_repeat' ) ) {
			$unused = array_values(
				array_filter(
					$pool,
					static function ( $row ) use ( $used ) {
						return ! in_array( (int) $row['id'], $used, true );
					}
				)
			);
			if ( $unused ) {
				$candidates = $unused;
			}
		}

		if ( 'weighted' === $mode ) {
			return self::pick_weighted( $candidates );
		}

		// Round-robin, also the fallback for per-slot mode when a slot has no explicit category.
		$pointer = (int) Automation::get( 'rotation_pointer', 0 );
		$ids     = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$pool
		);
		$start   = array_search( $pointer, $ids, true );
		$start   = false === $start ? -1 : $start;
		$count   = count( $ids );
		$allowed = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$candidates
		);
		for ( $step = 1; $step <= $count; $step++ ) {
			$candidate = $ids[ ( $start + $step ) % $count ];
			if ( in_array( $candidate, $allowed, true ) ) {
				Automation::set( 'rotation_pointer', $candidate );
				return $candidate;
			}
		}
		return $ids[0];
	}

	private static function pick_weighted( array $candidates ): int {
		$total = 0;
		foreach ( $candidates as $row ) {
			$total += max( 1, (int) $row['weight'] );
		}
		$roll = wp_rand( 1, max( 1, $total ) );
		foreach ( $candidates as $row ) {
			$roll -= max( 1, (int) $row['weight'] );
			if ( $roll <= 0 ) {
				return (int) $row['id'];
			}
		}
		return (int) $candidates[0]['id'];
	}

	/**
	 * Enabled categories allowed on a date, limited to the rotation pool when one is set.
	 */
	public static function pool_for_date( string $local_date ): array {
		$weekday = ( new \DateTimeImmutable( $local_date, self::timezone() ) )->format( 'N' );
		$pool    = array_map( 'intval', (array) Settings::get( 'rotation_pool' ) );
		$out     = array();
		foreach ( Categories::enabled() as $row ) {
			if ( ! in_array( $weekday, explode( ',', (string) $row['active_days'] ), true ) ) {
				continue;
			}
			if ( $pool && ! in_array( (int) $row['id'], $pool, true ) ) {
				continue;
			}
			// A category added while automation runs waits here until it has a source and a WordPress category.
			if ( Categories::readiness( $row ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Next slot still waiting to be filled.
	 */
	public static function next_open_slot(): ?array {
		global $wpdb;
		$table = Db::table( 'slots' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND due_at >= %s ORDER BY due_at ASC LIMIT 1", self::PENDING, Clock::mysql() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function format_local( ?string $mysql_utc, bool $with_zone = true ): string {
		$ts = Clock::to_timestamp( $mysql_utc );
		if ( ! $ts ) {
			return '';
		}
		$local = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( self::timezone() );
		return $local->format( 'Y-m-d H:i' ) . ( $with_zone ? ' ' . self::zone_label( $local ) : '' );
	}

	public static function zone_label( ?\DateTimeImmutable $at = null ): string {
		$at     = $at ?: self::now_local();
		$offset = $at->format( 'P' );
		$name   = (string) Settings::get( 'timezone' );
		return preg_match( '/^[+-]/', $name ) ? 'UTC' . $offset : $name . ' (UTC' . $offset . ')';
	}
}
