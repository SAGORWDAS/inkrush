<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Optional limits that stop automation by themselves: after a number of days,
 * or after a number of published posts, whichever comes first.
 *
 * Both are counted from the last press of Start Automation. Each is off at 0.
 * Reaching a limit works like pressing Stop: future jobs are cancelled, a step
 * already running finishes, and the queue and history are kept.
 */
final class AutoStop {

	public static function days(): int {
		return (int) Settings::get( 'auto_stop_days' );
	}

	public static function posts(): int {
		return (int) Settings::get( 'auto_stop_posts' );
	}

	public static function enabled(): bool {
		return self::days() > 0 || self::posts() > 0;
	}

	/**
	 * When the current run started, or 0 when it is not known.
	 */
	public static function started_at(): int {
		return (int) Automation::get( 'started_at', 0 );
	}

	/**
	 * When the days limit is reached, or 0 when there is none.
	 */
	public static function stop_time(): int {
		$started = self::started_at();
		if ( self::days() <= 0 || ! $started ) {
			return 0;
		}
		return $started + self::days() * DAY_IN_SECONDS;
	}

	/**
	 * Posts this plugin published since the last Start. Drafts do not count.
	 */
	public static function published_since_start(): int {
		global $wpdb;
		$started = self::started_at();
		if ( ! $started ) {
			return 0;
		}
		$table = Db::table( 'post_links' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_status = 'publish' AND created_at >= %s", // phpcs:ignore WordPress.DB
				Clock::mysql( $started )
			)
		);
	}

	/**
	 * Why automation should stop now, or an empty string when no limit is reached.
	 */
	public static function reason(): string {
		if ( ! self::enabled() || ! self::started_at() ) {
			return '';
		}
		$limit = self::posts();
		if ( $limit > 0 ) {
			$published = self::published_since_start();
			if ( $published >= $limit ) {
				return sprintf(
					'%1$d %2$s published since automation started, which reaches the limit of %3$d.',
					$published,
					1 === $published ? 'post was' : 'posts were',
					$limit
				);
			}
		}
		$stop_time = self::stop_time();
		if ( $stop_time && Clock::now() >= $stop_time ) {
			return sprintf(
				'%1$d %2$s passed since automation started on %3$s, which reaches the limit.',
				self::days(),
				1 === self::days() ? 'day has' : 'days have',
				Schedule::format_local( Clock::mysql( self::started_at() ) )
			);
		}
		return '';
	}

	/**
	 * Stops automation when a limit is reached.
	 *
	 * @return bool True when automation was stopped by this call.
	 */
	public static function check(): bool {
		if ( ! self::enabled() || ! Automation::is_active() ) {
			return false;
		}
		$reason = self::reason();
		if ( '' === $reason ) {
			return false;
		}
		Automation::stop_automatically( $reason );
		return true;
	}

	/**
	 * One line describing the limits and how far the current run has got.
	 */
	public static function summary(): string {
		if ( ! self::enabled() ) {
			return 'Off. Automation runs until you press Stop.';
		}
		$parts     = array();
		$active    = Automation::is_active() && self::started_at();
		$stop_time = self::stop_time();
		if ( self::posts() > 0 ) {
			$parts[] = $active
				? sprintf( 'after %1$d published %2$s (%3$d so far)', self::posts(), 1 === self::posts() ? 'post' : 'posts', self::published_since_start() )
				: sprintf( 'after %1$d published %2$s', self::posts(), 1 === self::posts() ? 'post' : 'posts' );
		}
		if ( self::days() > 0 ) {
			$parts[] = $active && $stop_time
				? sprintf( 'on %s', Schedule::format_local( Clock::mysql( $stop_time ) ) )
				: sprintf( 'after %1$d %2$s', self::days(), 1 === self::days() ? 'day' : 'days' );
		}
		$text = 'Stops ' . implode( ', or ', $parts );
		if ( count( $parts ) > 1 ) {
			$text .= ', whichever comes first';
		}
		return $text . ( $active ? '.' : ', counted from when you press Start Automation.' );
	}
}
