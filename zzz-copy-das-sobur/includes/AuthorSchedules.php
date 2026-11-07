<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Each author's own schedule, used only in Multiple users mode.
 *
 * A schedule has weekdays, an optional date range, dates to skip, post times in the author's own
 * timezone, how many posts at each time, the categories the author writes about, and limits per day,
 * week and month. The site's Maximum posts per day and daily AI limit stay above every author.
 * Access and limits are checked again right before each post, on the server.
 */
final class AuthorSchedules {

	public const SINGLE   = 'single';
	public const MULTIPLE = 'multiple';

	/** No two post times, by anyone, may be closer than this. */
	public const MIN_GAP_MINUTES = 15;

	public const MAX_TIMES    = 24;
	public const MAX_PER_TIME = 5;
	public const MAX_LIMIT    = 1000;
	public const MAX_SKIP     = 366;

	/** How far ahead conflicts are looked for. Two weeks covers different daylight-saving dates. */
	public const CONFLICT_DAYS = 14;

	public static function multiple(): bool {
		return self::MULTIPLE === Settings::get( 'author_mode' );
	}

	/**
	 * The schedule stored for a user, with defaults when none was saved.
	 */
	public static function get( int $user_id ): array {
		return self::from_row( Authors::row( $user_id ) );
	}

	public static function from_row( ?array $row ): array {
		$row = $row ?: array();
		return array(
			'enabled'     => ! empty( $row['sched_enabled'] ),
			'timezone'    => (string) ( $row['sched_timezone'] ?? '' ),
			'days'        => self::list_of_days( (string) ( $row['sched_days'] ?? '1,2,3,4,5,6,7' ) ),
			'times'       => self::json_list( $row['sched_times'] ?? null ),
			'per_time'    => max( 1, (int) ( $row['sched_per_time'] ?? 1 ) ),
			'categories'  => array_map( 'intval', self::json_list( $row['sched_categories'] ?? null ) ),
			'from'        => (string) ( $row['sched_from'] ?? '' ),
			'until'       => (string) ( $row['sched_until'] ?? '' ),
			'skip_dates'  => self::json_list( $row['sched_skip_dates'] ?? null ),
			'limit_day'   => (int) ( $row['limit_day'] ?? 0 ),
			'limit_week'  => (int) ( $row['limit_week'] ?? 0 ),
			'limit_month' => (int) ( $row['limit_month'] ?? 0 ),
			'missed_rule' => 'skip' === ( $row['missed_rule'] ?? 'next' ) ? 'skip' : 'next',
		);
	}

	private static function json_list( $value ): array {
		$list = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : array();
		return is_array( $list ) ? array_values( $list ) : array();
	}

	private static function list_of_days( string $value ): array {
		return array_values( array_intersect( array( '1', '2', '3', '4', '5', '6', '7' ), explode( ',', $value ) ) );
	}

	public static function timezone( array $schedule ): \DateTimeZone {
		$name = '' !== $schedule['timezone'] ? $schedule['timezone'] : (string) Settings::get( 'timezone' );
		try {
			return new \DateTimeZone( $name ?: '+10:30' );
		} catch ( \Exception $e ) {
			return Schedule::timezone();
		}
	}

	public static function zone_name( array $schedule ): string {
		return '' !== $schedule['timezone'] ? $schedule['timezone'] : (string) Settings::get( 'timezone' );
	}

	/**
	 * A schedule that can produce posts: switched on, with at least one day and one time.
	 */
	public static function usable( array $schedule ): bool {
		return $schedule['enabled'] && $schedule['days'] && $schedule['times'];
	}

	/**
	 * Active authors with a usable schedule.
	 *
	 * @return int[]
	 */
	public static function ready_ids(): array {
		$out = array();
		foreach ( Authors::active_ids() as $user_id ) {
			if ( self::usable( self::get( $user_id ) ) ) {
				$out[] = $user_id;
			}
		}
		return $out;
	}

	/* ---- Validation and saving ---- */

	/**
	 * Checks the values from the schedule form.
	 *
	 * @return array{schedule:array, errors:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$errors = array();
		$out    = self::from_row( null );

		$out['enabled'] = ! empty( $input['enabled'] );

		$timezone = trim( (string) ( $input['timezone'] ?? '' ) );
		if ( '' === $timezone || ! Settings::is_valid_timezone( $timezone ) ) {
			$errors['timezone'] = 'Enter a timezone name such as Asia/Dhaka, or a fixed offset such as +06:00.';
		} else {
			$out['timezone'] = $timezone;
		}

		$days = array_values( array_intersect( array( '1', '2', '3', '4', '5', '6', '7' ), array_map( 'strval', (array) ( $input['days'] ?? array() ) ) ) );
		$out['days'] = $days;
		if ( $out['enabled'] && ! $days ) {
			$errors['days'] = 'Choose at least one day of the week.';
		}

		$times = array();
		$bad   = array();
		foreach ( preg_split( '/[\s,;]+/', (string) ( $input['times'] ?? '' ) ) as $time ) {
			if ( '' === $time ) {
				continue;
			}
			if ( preg_match( '/^(\d{1,2}):([0-5]\d)$/', $time, $m ) && (int) $m[1] <= 23 ) {
				$times[] = sprintf( '%02d:%s', (int) $m[1], $m[2] );
			} else {
				$bad[] = $time;
			}
		}
		$times = array_values( array_unique( $times ) );
		sort( $times );
		if ( $bad ) {
			$errors['times'] = sprintf( 'These are not 24-hour times such as 09:30: %s.', implode( ', ', array_slice( $bad, 0, 5 ) ) );
		} elseif ( count( $times ) > self::MAX_TIMES ) {
			$errors['times'] = sprintf( 'Enter at most %d post times.', self::MAX_TIMES );
		} elseif ( $out['enabled'] && ! $times ) {
			$errors['times'] = 'Enter at least one post time, such as 09:00.';
		} else {
			$close = self::close_times( $times );
			if ( $close ) {
				$errors['times'] = sprintf( '%1$s and %2$s are less than %3$d minutes apart. Leave at least %3$d minutes between post times.', $close[0], $close[1], self::MIN_GAP_MINUTES );
			}
		}
		$out['times'] = $times;

		$per_time = (string) ( $input['per_time'] ?? '1' );
		if ( ! ctype_digit( $per_time ) || (int) $per_time < 1 || (int) $per_time > self::MAX_PER_TIME ) {
			$errors['per_time'] = sprintf( 'Enter a number from 1 to %d.', self::MAX_PER_TIME );
		} else {
			$out['per_time'] = (int) $per_time;
		}

		$known = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			Categories::all()
		);
		$categories = array_values( array_unique( array_map( 'absint', (array) ( $input['categories'] ?? array() ) ) ) );
		$unknown    = array_diff( $categories, $known );
		if ( $unknown ) {
			$errors['categories'] = 'One of the chosen categories no longer exists. Reload the page and choose again.';
		}
		$out['categories'] = array_values( array_intersect( $categories, $known ) );

		foreach ( array( 'from', 'until' ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( '' === $value ) {
				$out[ $key ] = '';
				continue;
			}
			if ( ! self::is_date( $value ) ) {
				$errors[ $key ] = 'Enter a date such as 2026-12-31, or leave it empty.';
				continue;
			}
			$out[ $key ] = $value;
		}
		if ( '' !== $out['from'] && '' !== $out['until'] && $out['from'] > $out['until'] ) {
			$errors['until'] = 'The Until date must be on or after the From date.';
		}

		$skip     = array();
		$bad_skip = array();
		foreach ( preg_split( '/[\s,;]+/', (string) ( $input['skip_dates'] ?? '' ) ) as $date ) {
			if ( '' === $date ) {
				continue;
			}
			if ( self::is_date( $date ) ) {
				$skip[] = $date;
			} else {
				$bad_skip[] = $date;
			}
		}
		$skip = array_values( array_unique( $skip ) );
		sort( $skip );
		if ( $bad_skip ) {
			$errors['skip_dates'] = sprintf( 'These are not dates such as 2026-12-25: %s.', implode( ', ', array_slice( $bad_skip, 0, 5 ) ) );
		} elseif ( count( $skip ) > self::MAX_SKIP ) {
			$errors['skip_dates'] = sprintf( 'Enter at most %d dates to skip.', self::MAX_SKIP );
		}
		$out['skip_dates'] = $skip;

		foreach ( array( 'limit_day', 'limit_week', 'limit_month' ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '0' ) );
			$value = '' === $value ? '0' : $value;
			if ( ! ctype_digit( $value ) || (int) $value > self::MAX_LIMIT ) {
				$errors[ $key ] = sprintf( 'Enter a number from 0 to %d. 0 means no limit.', self::MAX_LIMIT );
				continue;
			}
			$out[ $key ] = (int) $value;
		}

		$rule = (string) ( $input['missed_rule'] ?? 'next' );
		if ( ! in_array( $rule, array( 'next', 'skip' ), true ) ) {
			$errors['missed_rule'] = 'Choose one of the listed options.';
		} else {
			$out['missed_rule'] = $rule;
		}

		return array(
			'schedule' => $out,
			'errors'   => $errors,
		);
	}

	private static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Two of one author's times closer than the minimum gap, including across midnight.
	 *
	 * @param string[] $times Sorted HH:MM.
	 * @return string[] The two times, or an empty array.
	 */
	public static function close_times( array $times ): array {
		$minutes = array_map(
			static function ( $time ) {
				list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
				return $h * 60 + $m;
			},
			$times
		);
		$count = count( $minutes );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( $minutes[ $i ] - $minutes[ $i - 1 ] < self::MIN_GAP_MINUTES ) {
				return array( $times[ $i - 1 ], $times[ $i ] );
			}
		}
		if ( $count > 1 && $minutes[0] + 1440 - $minutes[ $count - 1 ] < self::MIN_GAP_MINUTES ) {
			return array( $times[ $count - 1 ], $times[0] );
		}
		return array();
	}

	/**
	 * Validates, checks for clashes with other authors, and saves. Future unused slots are rebuilt.
	 *
	 * @return array{errors:array<string,string>, warnings:string[]}
	 */
	public static function save( int $user_id, array $input ): array {
		global $wpdb;
		$out = array(
			'errors'   => array(),
			'warnings' => array(),
		);
		$row = Authors::row( $user_id );
		if ( ! $row || ! get_userdata( $user_id ) ) {
			$out['errors']['user'] = 'Only an authorised author can have a schedule. Grant access first.';
			return $out;
		}

		$checked = self::validate( $input );
		if ( $checked['errors'] ) {
			$out['errors'] = $checked['errors'];
			return $out;
		}
		$schedule = $checked['schedule'];

		$clashes = self::conflicts( $user_id, $schedule );
		if ( $clashes ) {
			$out['errors']['times'] = implode( ' ', $clashes );
			return $out;
		}

		$wpdb->update(
			Db::table( 'authors' ),
			array(
				'sched_enabled'    => $schedule['enabled'] ? 1 : 0,
				'sched_timezone'   => $schedule['timezone'],
				'sched_days'       => implode( ',', $schedule['days'] ),
				'sched_times'      => wp_json_encode( $schedule['times'] ),
				'sched_per_time'   => $schedule['per_time'],
				'sched_categories' => wp_json_encode( $schedule['categories'] ),
				'sched_from'       => '' !== $schedule['from'] ? $schedule['from'] : null,
				'sched_until'      => '' !== $schedule['until'] ? $schedule['until'] : null,
				'sched_skip_dates' => wp_json_encode( $schedule['skip_dates'] ),
				'limit_day'        => $schedule['limit_day'],
				'limit_week'       => $schedule['limit_week'],
				'limit_month'      => $schedule['limit_month'],
				'missed_rule'      => $schedule['missed_rule'],
				'sched_changed_by' => (int) get_current_user_id(),
				'sched_changed_at' => Clock::mysql(),
			),
			array( 'user_id' => $user_id )
		);

		$actor = (int) get_current_user_id();
		Events::log(
			'access',
			'info',
			sprintf( '%1$s saved the schedule of %2$s: %3$s', $actor ? Authors::user_label( $actor ) : 'The plugin', Authors::user_label( $user_id ), self::summary( $schedule ) ),
			array( 'author_user_id' => $user_id )
		);

		if ( self::multiple() ) {
			Schedule::rebuild_future();
		}
		$out['warnings'] = self::capacity_warnings();
		return $out;
	}

	/**
	 * One line describing a schedule, for logs and screens.
	 */
	public static function summary( array $schedule ): string {
		if ( ! $schedule['enabled'] ) {
			return 'off.';
		}
		$names = array( '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun' );
		$days  = count( $schedule['days'] ) === 7 ? 'every day' : implode( ', ', array_map(
			static function ( $d ) use ( $names ) {
				return $names[ $d ];
			},
			$schedule['days']
		) );
		$parts = array( sprintf( '%1$s at %2$s (%3$s)', $days, implode( ', ', $schedule['times'] ), self::zone_name( $schedule ) ) );
		if ( $schedule['per_time'] > 1 ) {
			$parts[] = sprintf( '%d posts at each time', $schedule['per_time'] );
		}
		if ( '' !== $schedule['from'] || '' !== $schedule['until'] ) {
			$parts[] = sprintf( 'from %1$s until %2$s', $schedule['from'] ?: 'now', $schedule['until'] ?: 'no end date' );
		}
		if ( $schedule['skip_dates'] ) {
			$parts[] = sprintf( '%d dates skipped', count( $schedule['skip_dates'] ) );
		}
		$parts[] = $schedule['categories'] ? sprintf( 'categories: %s', implode( ', ', array_filter( array_map( array( Categories::class, 'name' ), $schedule['categories'] ) ) ) ) : 'all enabled categories';
		$limits = array();
		foreach ( array( 'limit_day' => 'a day', 'limit_week' => 'a week', 'limit_month' => 'a month' ) as $key => $label ) {
			if ( $schedule[ $key ] ) {
				$limits[] = sprintf( '%1$d %2$s', $schedule[ $key ], $label );
			}
		}
		if ( $limits ) {
			$parts[] = 'at most ' . implode( ', ', $limits );
		}
		$parts[] = 'skip' === $schedule['missed_rule'] ? 'a missed time is skipped' : 'a missed time is published at their next time';
		return implode( '; ', $parts ) . '.';
	}

	/* ---- When posts happen ---- */

	/**
	 * Whether the author posts on a date of their own calendar.
	 */
	public static function date_allowed( array $schedule, string $author_date ): bool {
		$weekday = ( new \DateTimeImmutable( $author_date, self::timezone( $schedule ) ) )->format( 'N' );
		if ( ! in_array( $weekday, $schedule['days'], true ) ) {
			return false;
		}
		if ( '' !== $schedule['from'] && $author_date < $schedule['from'] ) {
			return false;
		}
		if ( '' !== $schedule['until'] && $author_date > $schedule['until'] ) {
			return false;
		}
		return ! in_array( $author_date, $schedule['skip_dates'], true );
	}

	/**
	 * Post times between two moments, in order.
	 *
	 * @return array<int,array{ts:int, time:string, date:string}>
	 */
	public static function occurrences( array $schedule, int $from, int $to ): array {
		if ( ! self::usable( $schedule ) || $to < $from ) {
			return array();
		}
		$tz    = self::timezone( $schedule );
		$day   = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 )->modify( '-1 day' );
		$last  = ( new \DateTimeImmutable( '@' . $to ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$out   = array();
		$guard = 0;
		while ( $day->format( 'Y-m-d' ) <= $last && $guard++ < 800 ) {
			$date = $day->format( 'Y-m-d' );
			if ( self::date_allowed( $schedule, $date ) ) {
				foreach ( $schedule['times'] as $time ) {
					$at = new \DateTimeImmutable( $date . ' ' . $time . ':00', $tz );
					$ts = $at->getTimestamp();
					if ( $ts >= $from && $ts <= $to ) {
						$out[ $ts ] = array(
							'ts'   => $ts,
							'time' => $time,
							'date' => $date,
						);
					}
				}
			}
			$day = $day->modify( '+1 day' );
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Post times that fall on a day of the site calendar, which the daily maximum counts.
	 */
	public static function occurrences_on_site_day( array $schedule, string $site_date ): array {
		list( $start, $end ) = Schedule::day_bounds_utc( $site_date );
		return self::occurrences( $schedule, Clock::to_timestamp( $start ), Clock::to_timestamp( $end ) );
	}

	/**
	 * Times in this schedule that come closer than the minimum gap to another active author's times
	 * over the next two weeks.
	 *
	 * @return string[] Readable messages, at most three.
	 */
	public static function conflicts( int $user_id, array $schedule ): array {
		if ( ! self::usable( $schedule ) ) {
			return array();
		}
		$from   = Clock::now();
		$to     = $from + self::CONFLICT_DAYS * DAY_IN_SECONDS;
		$events = array();
		foreach ( self::occurrences( $schedule, $from, $to ) as $o ) {
			$events[] = array( $o['ts'], $user_id, $o, $schedule );
		}
		if ( ! $events ) {
			return array();
		}
		foreach ( Authors::active_ids() as $other ) {
			if ( $other === $user_id ) {
				continue;
			}
			$theirs = self::get( $other );
			foreach ( self::occurrences( $theirs, $from, $to ) as $o ) {
				$events[] = array( $o['ts'], $other, $o, $theirs );
			}
		}
		usort(
			$events,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		// Sorted by time, if one of this author's times is too close to another author's, some
		// neighbouring pair of this author and another author is at least as close.
		$out   = array();
		$seen  = array();
		$count = count( $events );
		for ( $i = 1; $i < $count && count( $out ) < 3; $i++ ) {
			list( $ts_a, $user_a, $occ_a, $sched_a ) = $events[ $i - 1 ];
			list( $ts_b, $user_b, $occ_b, $sched_b ) = $events[ $i ];
			if ( $user_a === $user_b || ( $user_a !== $user_id && $user_b !== $user_id ) || $ts_b - $ts_a >= self::MIN_GAP_MINUTES * 60 ) {
				continue;
			}
			$mine   = $user_a === $user_id ? array( $occ_a, $sched_a ) : array( $occ_b, $sched_b );
			$other  = $user_a === $user_id ? array( $user_b, $occ_b, $sched_b ) : array( $user_a, $occ_a, $sched_a );
			$key    = $mine[0]['time'] . '|' . $other[0] . '|' . $other[1]['time'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = sprintf(
				'%1$s on %2$s (%3$s) is %4$d minutes from %5$s\'s %6$s (%7$s). Leave at least %8$d minutes between any two post times.',
				$mine[0]['time'],
				$mine[0]['date'],
				self::zone_name( $mine[1] ),
				(int) round( abs( $ts_b - $ts_a ) / 60 ),
				Authors::user_label( $other[0] ),
				$other[1]['time'],
				self::zone_name( $other[2] ),
				self::MIN_GAP_MINUTES
			);
		}
		return $out;
	}

	/**
	 * Warns when the authors' times together exceed the site's Maximum posts per day on a coming day.
	 *
	 * @return string[]
	 */
	public static function capacity_warnings(): array {
		$cap   = (int) Settings::get( 'daily_cap' );
		$today = Schedule::now_local();
		for ( $i = 0; $i < 7; $i++ ) {
			$date  = $today->modify( '+' . $i . ' day' )->format( 'Y-m-d' );
			$total = 0;
			foreach ( self::ready_ids() as $user_id ) {
				$schedule = self::get( $user_id );
				$total   += count( self::occurrences_on_site_day( $schedule, $date ) ) * $schedule['per_time'];
			}
			if ( $total > $cap ) {
				return array( sprintf( 'On %1$s the authors have %2$d post times together, but the site allows %3$d posts a day (Maximum posts per day on the Schedule page). Posts after the first %3$d are not made.', $date, $total, $cap ) );
			}
		}
		return array();
	}

	/* ---- Limits ---- */

	/**
	 * Start and end of the author's day, week (Monday to Sunday) or month containing a moment.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function period( array $schedule, string $period, int $at ): array {
		$local = ( new \DateTimeImmutable( '@' . $at ) )->setTimezone( self::timezone( $schedule ) )->setTime( 0, 0 );
		if ( 'week' === $period ) {
			$start = $local->modify( '-' . ( (int) $local->format( 'N' ) - 1 ) . ' days' );
			$end   = $start->modify( '+7 days' );
		} elseif ( 'month' === $period ) {
			$start = $local->modify( 'first day of this month' );
			$end   = $start->modify( '+1 month' );
		} else {
			$start = $local;
			$end   = $local->modify( '+1 day' );
		}
		return array( $start->getTimestamp(), $end->getTimestamp() - 1 );
	}

	/**
	 * Posts credited to the author in a period. Drafts count unless the site counts published posts only.
	 */
	public static function used( int $user_id, array $schedule, string $period, int $at ): int {
		global $wpdb;
		list( $start, $end ) = self::period( $schedule, $period, $at );
		$table = Db::table( 'post_links' );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE author_user_id = %d AND created_at BETWEEN %s AND %s";
		$args  = array( $user_id, Clock::mysql( $start ), Clock::mysql( $end ) );
		if ( 'published' === Settings::get( 'cap_scope' ) ) {
			$sql   .= ' AND post_status = %s';
			$args[] = 'publish';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Why another post now would break one of the author's limits. Empty when it would not.
	 */
	public static function limit_problem( int $user_id, array $schedule, int $at ): string {
		$labels = array(
			'day'   => 'daily',
			'week'  => 'weekly',
			'month' => 'monthly',
		);
		foreach ( $labels as $period => $label ) {
			$limit = (int) $schedule[ 'limit_' . $period ];
			if ( $limit && self::used( $user_id, $schedule, $period, $at ) >= $limit ) {
				return sprintf( '%1$s has reached their %2$s limit of %3$d posts.', Authors::user_label( $user_id ), $label, $limit );
			}
		}
		return '';
	}

	/**
	 * Why a slot cannot be used for its author right now. Empty when it can.
	 */
	public static function slot_problem( array $slot, ?int $at = null ): string {
		$user_id = (int) ( $slot['author_user_id'] ?? 0 );
		if ( ! $user_id ) {
			return 'This time was planned before Multiple users mode was switched on, so it has no author.';
		}
		$status = Authors::status( $user_id );
		if ( Authors::ACTIVE !== $status ) {
			$why = array(
				Authors::PAUSED  => 'is paused on the Authors page',
				Authors::REVOKED => 'no longer has access',
				Authors::MISSING => 'no longer exists in WordPress',
			);
			return sprintf( '%1$s %2$s.', Authors::user_label( $user_id ), $why[ $status ] ?? 'is not authorised' );
		}
		$schedule = self::get( $user_id );
		if ( ! self::usable( $schedule ) ) {
			return sprintf( 'The schedule of %s is switched off.', Authors::user_label( $user_id ) );
		}
		return self::limit_problem( $user_id, $schedule, $at ?? Clock::now() );
	}

	/**
	 * The author's categories that may be used, limited to those given. All of them when none are chosen.
	 *
	 * @param int[] $category_ids
	 * @return int[]
	 */
	public static function allowed_categories( int $user_id, array $category_ids ): array {
		$mine = self::get( $user_id )['categories'];
		if ( ! $mine ) {
			return array_values( $category_ids );
		}
		return array_values( array_intersect( $category_ids, $mine ) );
	}

	/**
	 * How much of each limit is used now, for the schedule form.
	 *
	 * @return array<string,array{used:int,limit:int}>
	 */
	public static function usage( int $user_id ): array {
		$schedule = self::get( $user_id );
		$out      = array();
		foreach ( array( 'day', 'week', 'month' ) as $period ) {
			$out[ $period ] = array(
				'used'  => self::used( $user_id, $schedule, $period, Clock::now() ),
				'limit' => (int) $schedule[ 'limit_' . $period ],
			);
		}
		return $out;
	}

	/* ---- Mode ---- */

	/**
	 * Switches between Single user and Multiple users mode. Future times not used yet are planned again.
	 *
	 * @return string Empty when switched, otherwise why not.
	 */
	public static function switch_mode( string $mode ): string {
		global $wpdb;
		if ( ! in_array( $mode, array( self::SINGLE, self::MULTIPLE ), true ) ) {
			return 'Choose one of the listed options.';
		}
		if ( Settings::get( 'author_mode' ) === $mode ) {
			return '';
		}
		if ( self::MULTIPLE === $mode && ! self::ready_ids() ) {
			return 'Give at least one active author a schedule first: open Schedule next to their name, switch it on, and add a post time.';
		}
		Settings::update( array( 'author_mode' => $mode ) );

		// Times planned for the other mode are closed, so nothing is posted twice or to the wrong person.
		// Unused future times are removed, so the same times can be planned again if the mode is switched back.
		$table = Db::table( 'slots' );
		$other = self::MULTIPLE === $mode ? 'author_user_id = 0' : 'author_user_id > 0';
		$now   = Clock::mysql();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('PENDING','DEFERRED') AND due_at > %s AND prep_started_at IS NULL AND {$other}", $now ) ); // phpcs:ignore WordPress.DB
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, status_reason = %s, updated_at = %s WHERE status IN ('PENDING','DEFERRED') AND {$other}", // phpcs:ignore WordPress.DB
				Schedule::SKIPPED,
				'The posting mode was changed before this time was used.',
				$now
			)
		);
		Schedule::rebuild_future();

		$actor = (int) get_current_user_id();
		Events::log(
			'access',
			'info',
			sprintf(
				'%1$s switched posting to %2$s.',
				$actor ? Authors::user_label( $actor ) : 'The plugin',
				self::MULTIPLE === $mode ? 'Multiple users mode: each authorised author posts on their own schedule' : 'Single user mode: every post is credited to the Post author'
			)
		);
		return '';
	}
}
