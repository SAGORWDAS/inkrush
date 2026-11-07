<?php
use ZZZCopy\Admin\Actions;
use ZZZCopy\Admin\Pages;
use ZZZCopy\AuthorSchedules;
use ZZZCopy\Authors;
use ZZZCopy\Automation;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Lifecycle;
use ZZZCopy\Locks;
use ZZZCopy\PastHour;
use ZZZCopy\Planner;
use ZZZCopy\Publisher;
use ZZZCopy\Schedule;
use ZZZCopy\Schema;
use ZZZCopy\Settings;

/**
 * Multiple users mode: each authorised author posts on their own schedule and is credited with those posts.
 * This file runs before test-4 and test-7, so it has its own helpers.
 */

const MA_DAY = '2026-11-05'; // A Thursday.

function ma_admin( callable $fn ) {
	$before = get_current_user_id();
	wp_set_current_user( $GLOBALS['zzzt_admin'] );
	$result = $fn();
	wp_set_current_user( $before );
	return $result;
}

function ma_user( string $name, bool $grant = true ): int {
	$login = 'zzzma' . wp_rand( 100000, 999999 );
	$id    = (int) wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password(),
			'user_email'   => $login . '@example.test',
			'display_name' => $name,
			'role'         => 'author',
		)
	);
	if ( $grant ) {
		ma_admin(
			static function () use ( $id ) {
				Authors::change( array( $id ), Authors::ACTIVE );
			}
		);
	}
	return $id;
}

function ma_status( int $user_id, string $status ): void {
	ma_admin(
		static function () use ( $user_id, $status ) {
			Authors::change( array( $user_id ), $status );
		}
	);
}

function ma_input( array $over = array() ): array {
	return array_merge(
		array(
			'enabled'     => '1',
			'timezone'    => '+10:30',
			'days'        => array( '1', '2', '3', '4', '5', '6', '7' ),
			'times'       => '',
			'per_time'    => '1',
			'categories'  => array(),
			'from'        => '',
			'until'       => '',
			'skip_dates'  => '',
			'limit_day'   => '0',
			'limit_week'  => '0',
			'limit_month' => '0',
			'missed_rule' => 'next',
		),
		$over
	);
}

function ma_save( int $user_id, array $over ): array {
	return ma_admin(
		static function () use ( $user_id, $over ) {
			return AuthorSchedules::save( $user_id, ma_input( $over ) );
		}
	);
}

function ma_ok( int $user_id, array $over ): array {
	$result = ma_save( $user_id, $over );
	t_eq( array(), $result['errors'], 'schedule saved' );
	return $result;
}

function ma_mode( string $mode ): string {
	return (string) ma_admin(
		static function () use ( $mode ) {
			return AuthorSchedules::switch_mode( $mode );
		}
	);
}

function ma_multi(): void {
	t_eq( '', ma_mode( AuthorSchedules::MULTIPLE ), 'switched to Multiple users mode' );
	t_true( AuthorSchedules::multiple() );
}

function ma_slots( string $date = MA_DAY, int $author = 0 ): array {
	global $wpdb;
	$sql = 'SELECT * FROM ' . Db::table( 'slots' ) . ' WHERE local_date = %s AND ' . ( $author ? 'author_user_id = ' . (int) $author : 'author_user_id > 0' ) . ' ORDER BY due_at ASC, id ASC';
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $date ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/** A written article waiting in the queue, as test-4's queued_article() makes one. */
function ma_queued( int $category, string $slug ): int {
	$saved = $GLOBALS['zzzt_now'];
	$job   = make_job_from_url( $category, ZZZT_MOCK . '/article/' . $slug, 'Headline ' . md5( $slug ) . ' ' . $slug );
	Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => Clock::now() ) );
	Settings::update( array( 'daily_ai_limit' => 500 ) );
	$paused = Automation::get( 'paused_publishing' );
	Automation::patch( array( 'paused_publishing' => 1 ) );
	run_ticks( 8 );
	Automation::patch( array( 'paused_publishing' => $paused ) );
	t_eq( Lifecycle::QUEUED, job_status( $job ), "article $slug queued: " . json_encode( Lifecycle::job( $job )['error_message'] ) );
	$GLOBALS['zzzt_now'] = $saved;
	return $job;
}

function ma_posts(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC", Publisher::META_JOB ) ) );
}

function ma_post_authors(): array {
	return array_map(
		static function ( $post_id ) {
			return (int) get_post_field( 'post_author', $post_id );
		},
		ma_posts()
	);
}

function ma_log( string $stage ): string {
	return implode( "\n", array_reverse( array_column( Events::query( array( 'stage' => $stage ), 300 ), 'message' ) ) );
}

function ma_at( string $time, string $date = MA_DAY ): void {
	set_local_now( $date . ' ' . $time );
}

/** Enables a second category with its own feed. */
function ma_category( string $name, string $feed ): int {
	global $wpdb;
	$table = Db::table( 'categories' );
	$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s", $name ) );
	$wpdb->update( $table, array( 'enabled' => 1, 'source_mode' => 'feeds', 'feed_urls' => ZZZT_MOCK . '/' . $feed, 'topic_urls' => '' ), array( 'id' => $id ) );
	return $id;
}

function ma_page( string $method, array $get = array() ): string {
	$saved = $_GET;
	$_GET  = $get;
	$html  = ma_admin(
		static function () use ( $method ) {
			ob_start();
			Pages::$method();
			return (string) ob_get_clean();
		}
	);
	$_GET  = $saved;
	return $html;
}

/** Sends an admin form through the real handler. Returns where it redirected and the message stored. */
function ma_submit( string $action, array $post, string $nonce = '' ): array {
	$_POST    = $post;
	$_REQUEST = array( '_wpnonce' => $nonce ?: wp_create_nonce( 'zzzc_' . $action ) );
	$location = '';
	try {
		do_action( 'admin_post_zzzc_' . $action );
	} catch ( RuntimeException $e ) {
		$location = $e->getMessage();
	}
	$_POST    = array();
	$_REQUEST = array();
	return array( $location, (array) get_transient( 'zzzc_flash_' . get_current_user_id() ) );
}

function ma_cleanup( array $users ): void {
	wp_set_current_user( 0 );
	foreach ( $users as $user_id ) {
		if ( get_userdata( $user_id ) ) {
			wp_delete_user( $user_id );
		}
	}
	Authors::flush_labels();
}

t(
	'MA-1',
	'Single user mode is the default, and upgrading to version 11 keeps slots, the Post author and posting exactly as before',
	static function () {
		global $wpdb;
		t_eq( 'single', Settings::defaults()['author_mode'] );
		t_false( AuthorSchedules::multiple() );
		t_eq( '11', Schema::DB_VERSION );

		$category = ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$slots  = Db::table( 'slots' );
		$read   = static function () use ( $wpdb, $slots ) {
			return $wpdb->get_results( "SELECT slot_key, due_at, category_id, status FROM {$slots} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
		};
		$before = $read();
		t_eq( array( MA_DAY . 'T10:00', MA_DAY . 'T16:00', MA_DAY . 'T22:00' ), array_slice( array_column( $before, 'slot_key' ), 0, 3 ) );

		// A version 10 site: no author columns yet.
		$authors = Db::table( 'authors' );
		$wpdb->query( "ALTER TABLE {$slots} DROP INDEX author_date" );
		$wpdb->query( "ALTER TABLE {$slots} DROP COLUMN author_user_id" );
		$wpdb->query( "ALTER TABLE {$authors} DROP COLUMN sched_enabled, DROP COLUMN sched_times, DROP COLUMN limit_week" );
		update_option( 'zzzc_db_version', '10', true );
		Schema::maybe_upgrade();

		t_eq( '11', get_option( 'zzzc_db_version' ) );
		t_true( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$slots} LIKE 'author_user_id'" ), 'slots have an author' );
		foreach ( array( 'sched_enabled', 'sched_times', 'limit_week', 'missed_rule', 'sched_timezone' ) as $column ) {
			t_true( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$authors} LIKE '{$column}'" ), "authors.$column" );
		}
		t_eq( $before, $read(), 'existing slots unchanged' );
		t_eq( 0, db_count( 'slots', 'author_user_id <> 0' ) );
		t_eq( (int) $GLOBALS['zzzt_admin'], (int) Settings::get( 'post_author' ), 'the Post author is kept' );
		t_eq( 'single', Settings::get( 'author_mode' ) );
		t_contains( 'Posting stays in Single user mode, exactly as before, until you switch.', ma_log( 'config' ) );

		// A saved author schedule changes nothing while Single user mode is on.
		$writer = ma_user( 'Una Single' );
		ma_ok( $writer, array( 'times' => '11:00' ) );
		Schedule::rebuild_future();
		t_eq( 0, db_count( 'slots', 'author_user_id > 0' ), 'no author times are planned' );
		t_eq( 3, db_count( 'slots', "author_user_id = 0 AND local_date = '" . MA_DAY . "'" ) );

		$job = ma_queued( $category, 'single-mode-post' );
		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( array( (int) $GLOBALS['zzzt_admin'] ), ma_post_authors(), 'credited to the Post author' );
		t_eq( Schedule::FILLED, $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$slots} WHERE slot_key = %s", MA_DAY . 'T10:00' ) ) ); // phpcs:ignore WordPress.DB
		t_true( Lifecycle::QUEUED !== job_status( $job ) );
		ma_cleanup( array( $writer ) );
	}
);

t(
	'MA-2',
	'Schedule values are checked: times, timezone, weekdays, dates, posts at each time, limits and the missed rule, each with a clear message',
	static function () {
		ma_at( '08:00:00' );
		$check = static function ( array $over ): array {
			return AuthorSchedules::validate( ma_input( $over ) )['errors'];
		};

		t_eq( array(), $check( array( 'times' => '09:00, 13:30 18:00' ) ) );
		t_eq( array( '09:05', '13:30', '18:00' ), AuthorSchedules::validate( ma_input( array( 'times' => '18:00,9:05;13:30' ) ) )['schedule']['times'], 'sorted, with two digits' );
		t_contains( 'These are not 24-hour times such as 09:30: 25:00, 9am.', (string) ( $check( array( 'times' => '25:00, 9am' ) )['times'] ?? '' ) );
		t_contains( 'Enter at least one post time', (string) ( $check( array( 'times' => '' ) )['times'] ?? '' ) );
		t_eq( array(), $check( array( 'times' => '', 'enabled' => '' ) ), 'a schedule switched off needs no times' );
		$many = implode( ',', array_map( static fn( $h ) => sprintf( '%02d:00', $h ), range( 0, 23 ) ) ) . ',12:30';
		t_contains( 'Enter at most 24 post times.', (string) ( $check( array( 'times' => $many ) )['times'] ?? '' ) );
		t_contains( '09:00 and 09:14 are less than 15 minutes apart', (string) ( $check( array( 'times' => '09:00, 09:14' ) )['times'] ?? '' ) );
		t_eq( array(), $check( array( 'times' => '09:00, 09:15' ) ), 'exactly 15 minutes is allowed' );
		t_contains( '23:50 and 00:01 are less than 15 minutes apart', (string) ( $check( array( 'times' => '00:01, 23:50' ) )['times'] ?? '' ), 'across midnight' );

		t_contains( 'Enter a timezone name such as Asia/Dhaka', (string) ( $check( array( 'times' => '09:00', 'timezone' => 'Mars/Base' ) )['timezone'] ?? '' ) );
		t_eq( array(), $check( array( 'times' => '09:00', 'timezone' => 'Asia/Dhaka' ) ) );
		t_eq( array(), $check( array( 'times' => '09:00', 'timezone' => '-05:00' ) ) );
		t_contains( 'Choose at least one day of the week.', (string) ( $check( array( 'times' => '09:00', 'days' => array() ) )['days'] ?? '' ) );
		t_eq( array( '1', '7' ), AuthorSchedules::validate( ma_input( array( 'times' => '09:00', 'days' => array( '7', '1', '9' ) ) ) )['schedule']['days'], 'unknown days are dropped' );

		foreach ( array( '0', '6', 'x', '' ) as $bad ) {
			t_contains( 'Enter a number from 1 to 5.', (string) ( $check( array( 'times' => '09:00', 'per_time' => $bad ) )['per_time'] ?? '' ), "per time $bad" );
		}
		t_eq( 5, AuthorSchedules::validate( ma_input( array( 'times' => '09:00', 'per_time' => '5' ) ) )['schedule']['per_time'] );

		t_contains( 'Enter a date such as 2026-12-31', (string) ( $check( array( 'times' => '09:00', 'from' => '2026-02-30' ) )['from'] ?? '' ) );
		t_contains( 'The Until date must be on or after the From date.', (string) ( $check( array( 'times' => '09:00', 'from' => '2026-12-10', 'until' => '2026-12-01' ) )['until'] ?? '' ) );
		t_eq( array(), $check( array( 'times' => '09:00', 'from' => '2026-12-01', 'until' => '2026-12-01' ) ), 'a one-day range' );
		t_contains( 'These are not dates such as 2026-12-25: tomorrow.', (string) ( $check( array( 'times' => '09:00', 'skip_dates' => '2026-12-25, tomorrow' ) )['skip_dates'] ?? '' ) );
		foreach ( array( '-1', '1001', '2.5' ) as $bad ) {
			t_contains( 'Enter a number from 0 to 1000. 0 means no limit.', (string) ( $check( array( 'times' => '09:00', 'limit_week' => $bad ) )['limit_week'] ?? '' ), "limit $bad" );
		}
		t_contains( 'Choose one of the listed options.', (string) ( $check( array( 'times' => '09:00', 'missed_rule' => 'later' ) )['missed_rule'] ?? '' ) );
		t_contains( 'no longer exists', (string) ( $check( array( 'times' => '09:00', 'categories' => array( 999999 ) ) )['categories'] ?? '' ) );

		$stranger = ma_user( 'Sam Stranger', false );
		t_contains( 'Grant access first', (string) ( ma_save( $stranger, array( 'times' => '09:00' ) )['errors']['user'] ?? '' ), 'only an authorised author has a schedule' );

		$writer = ma_user( 'Ann Valid' );
		ma_ok( $writer, array( 'times' => '09:00' ) );
		t_true( (bool) ma_save( $writer, array( 'times' => '09:00, 09:05' ) )['errors'] );
		t_eq( array( '09:00' ), AuthorSchedules::get( $writer )['times'], 'a refused save keeps the schedule as it was' );
		ma_cleanup( array( $stranger, $writer ) );
	}
);

t(
	'MA-3',
	'A time within 15 minutes of another author is refused on save, across timezones and a daylight-saving change, and too many times together give a warning',
	static function () {
		ma_at( '08:00:00' );
		$weekdays = array( '1', '2', '3', '4', '5' );
		$ava      = ma_user( 'Ava Early' );
		$ben      = ma_user( 'Ben Later' );
		ma_ok( $ava, array( 'times' => '10:00', 'days' => $weekdays ) );

		$result = ma_save( $ben, array( 'times' => '10:14' ) );
		$text   = (string) ( $result['errors']['times'] ?? '' );
		t_contains( '10:14 on ', $text );
		t_contains( '(+10:30) is 14 minutes from Ava Early (', $text, 'names the other author' );
		t_contains( "'s 10:00 (+10:30). Leave at least 15 minutes between any two post times.", $text );
		t_false( AuthorSchedules::get( $ben )['enabled'], 'not saved' );
		ma_ok( $ben, array( 'times' => '10:15' ) );

		// 05:35 in Dhaka is 10:05 here.
		$cal    = ma_user( 'Cal Dhaka' );
		$text   = (string) ( ma_save( $cal, array( 'timezone' => 'Asia/Dhaka', 'times' => '05:35' ) )['errors']['times'] ?? '' );
		t_contains( '(Asia/Dhaka) is 5 minutes from Ava Early (', $text );
		ma_ok( $cal, array( 'timezone' => 'Asia/Dhaka', 'times' => '05:00' ) );

		// Weekdays and weekends do not meet.
		$dee = ma_user( 'Dee Weekend' );
		t_eq( array(), ma_save( $dee, array( 'times' => '10:00', 'days' => array( '6', '7' ) ) )['errors'], 'same time, other days' );

		// A paused author's times do not block anyone, and the same author's own times are checked too.
		ma_status( $dee, Authors::PAUSED );
		t_contains( '10:00 and 10:10 are less than 15 minutes apart', (string) ( ma_save( $ben, array( 'times' => '10:15, 10:00, 10:10' ) )['errors']['times'] ?? '' ) );

		// London moves its clocks on 29 March 2026: 09:00 there is 09:00 UTC, then 08:00 UTC.
		ma_at( '12:00:00', '2026-03-27' );
		$eve = ma_user( 'Eve London' );
		ma_ok( $eve, array( 'timezone' => 'Europe/London', 'times' => '09:00' ) );
		$fay  = ma_user( 'Fay Fixed' );
		$text = (string) ( ma_save( $fay, array( 'timezone' => '+00:00', 'times' => '08:10' ) )['errors']['times'] ?? '' );
		t_contains( '08:10 on 2026-03-29 (+00:00) is 10 minutes from Eve London (', $text, 'found after the change' );
		t_contains( "'s 09:00 (Europe/London)", $text );

		ma_status( $eve, Authors::PAUSED );
		$result = ma_ok( $fay, array( 'timezone' => '+00:00', 'times' => '08:10' ) );
		// Friday 27 March: Ava 10:00, Ben 10:15, Cal 09:30 and Fay 18:40 here.
		t_contains( 'On 2026-03-27 the authors have 4 post times together, but the site allows 3 posts a day (Maximum posts per day on the Schedule page). Posts after the first 3 are not made.', implode( ' ', $result['warnings'] ) );
		Settings::update( array( 'daily_cap' => 4 ) );
		t_eq( array(), AuthorSchedules::capacity_warnings() );
		ma_cleanup( array( $ava, $ben, $cal, $dee, $eve, $fay ) );
	}
);

t(
	'MA-4',
	'Times are planned per author in the author\'s timezone, follow weekdays, From and Until and dates to skip, and an author added mid-day gets only the times left',
	static function () {
		global $wpdb;
		ready_plugin();
		ma_at( '07:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$weekdays = array( '1', '2', '3', '4', '5' );
		$ada      = ma_user( 'Ada Local' );
		$bo       = ma_user( 'Bo Dhaka' );
		ma_ok( $ada, array( 'times' => '09:00, 15:00', 'days' => $weekdays ) );
		ma_ok( $bo, array( 'timezone' => 'Asia/Dhaka', 'times' => '08:00' ) );
		ma_multi();
		Schedule::ensure_slots();

		$rows = ma_slots();
		t_eq( array( '2026-11-04 22:30:00', '2026-11-05 02:00:00', '2026-11-05 04:30:00' ), array_column( $rows, 'due_at' ), '09:00 here, 08:00 in Dhaka (12:30 here), 15:00 here' );
		t_eq( array( $ada, $bo, $ada ), array_map( 'intval', array_column( $rows, 'author_user_id' ) ) );
		t_eq( MA_DAY . 'T22:30u' . $ada . '#1', $rows[0]['slot_key'] );
		t_eq( array( 'PENDING', 'PENDING', 'PENDING' ), array_column( $rows, 'status' ) );
		t_eq( 0, db_count( 'slots', "author_user_id = 0 AND status = 'PENDING'" ), 'no site slots in Multiple users mode' );
		t_eq( 3, count( ma_slots( '2026-11-06' ) ), 'tomorrow is planned too' );

		$on = static function ( int $user, string $date ): int {
			return count( AuthorSchedules::occurrences_on_site_day( AuthorSchedules::get( $user ), $date ) );
		};
		t_eq( 0, $on( $ada, '2026-11-07' ), 'Ada does not post on Saturday' );
		t_eq( 1, $on( $bo, '2026-11-07' ) );

		ma_ok( $ada, array( 'times' => '09:00, 15:00', 'days' => $weekdays, 'from' => '2026-11-06', 'until' => '2026-11-20', 'skip_dates' => '2026-11-10' ) );
		t_eq( 0, $on( $ada, '2026-11-05' ), 'before From' );
		t_eq( 2, $on( $ada, '2026-11-06' ) );
		t_eq( 0, $on( $ada, '2026-11-10' ), 'a date to skip' );
		t_eq( 2, $on( $ada, '2026-11-20' ), 'Until is included' );
		t_eq( 0, $on( $ada, '2026-11-23' ), 'after Until' );
		t_eq( array( $bo ), array_map( 'intval', array_column( ma_slots(), 'author_user_id' ) ), 'saving planned the coming times again' );
		t_eq( 'Ada Local', explode( ' (', Authors::user_label( $ada ) )[0] );

		// Dates are the author's own: 2026-11-06 in Dhaka, 12:30 here.
		ma_ok( $bo, array( 'timezone' => 'Asia/Dhaka', 'times' => '08:00', 'skip_dates' => '2026-11-06' ) );
		t_eq( 0, $on( $bo, '2026-11-06' ) );
		t_eq( 1, $on( $bo, '2026-11-07' ) );

		ma_at( '13:00:00' );
		$cy = ma_user( 'Cy Midday' );
		ma_ok( $cy, array( 'times' => '10:00, 14:00, 20:00' ) );
		t_eq( array( '2026-11-05 03:30:00', '2026-11-05 09:30:00' ), array_column( ma_slots( MA_DAY, $cy ), 'due_at' ), 'only 14:00 and 20:00 are left today' );
		Schedule::ensure_slots();
		t_eq( 2, count( ma_slots( MA_DAY, $cy ) ), 'the next run adds nothing' );
		t_eq( 3, count( ma_slots( '2026-11-06', $cy ) ), 'all three tomorrow' );
		t_eq( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'slots' ) . " WHERE author_user_id = {$cy} AND status = 'MISSED'" ), 'nothing was marked missed for times before they joined' );
		ma_cleanup( array( $ada, $bo, $cy ) );
	}
);

t(
	'MA-5',
	'With 2 posts at one time, 2 slots are planned and filled one after another for the same author',
	static function () {
		$category = ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$ari = ma_user( 'Ari Double' );
		ma_ok( $ari, array( 'times' => '10:00', 'per_time' => '2' ) );
		ma_multi();
		Schedule::ensure_slots();
		$rows = ma_slots();
		t_eq( 2, count( $rows ) );
		t_eq( array( MA_DAY . 'T23:30u' . $ari . '#1', MA_DAY . 'T23:30u' . $ari . '#2' ), array_column( $rows, 'slot_key' ) );
		t_eq( $rows[0]['due_at'], $rows[1]['due_at'] );

		ma_queued( $category, 'double-one' );
		ma_queued( $category, 'double-two' );
		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( 1, count( ma_posts() ), 'one post per run' );
		ma_at( '10:01:30' );
		Publisher::tick();
		t_eq( 2, count( ma_posts() ), 'the second right after' );
		t_eq( array( $ari, $ari ), ma_post_authors() );
		t_eq( array( 'FILLED', 'FILLED' ), array_column( ma_slots(), 'status' ) );
		ma_cleanup( array( $ari ) );
	}
);

t(
	'MA-6',
	'An author\'s categories limit the category of their times, the article chosen, the fallback and the research',
	static function () {
		$business = ready_plugin();
		$tech     = ma_category( 'Technology', 'feed-tech.xml' );
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$tia = ma_user( 'Tia Tech' );
		ma_ok( $tia, array( 'times' => '10:00, 13:00, 16:00, 19:00', 'categories' => array( (string) $tech ) ) );
		ma_multi();
		Schedule::ensure_slots();
		t_eq( array( $tech, $tech, $tech, $tech ), array_map( 'intval', array_column( ma_slots(), 'category_id' ) ), 'every time uses her category, though rotation would also pick Business' );
		t_eq( array( $tech ), array_map( 'intval', array_column( Schedule::pool_for_date( MA_DAY, $tia ), 'id' ) ) );
		t_eq( 2, count( Schedule::pool_for_date( MA_DAY ) ), 'the site pool has both' );
		t_eq( array( $tech ), Planner::planned_category_ids(), 'only Technology is researched' );

		$biz = ma_queued( $business, 'biz-only' );
		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( array(), ma_posts(), 'a Business article is not posted for an author of Technology only, even with the fallback on' );
		t_eq( 1, (int) Settings::get( 'category_fallback' ) );
		t_eq( Lifecycle::QUEUED, job_status( $biz ) );

		$story = ma_queued( $tech, 'tech-story' );
		ma_at( '10:05:00' );
		Publisher::tick();
		t_eq( 1, count( ma_posts() ) );
		t_true( Lifecycle::QUEUED !== job_status( $story ), 'the Technology article was used' );
		t_eq( Lifecycle::QUEUED, job_status( $biz ), 'the Business article still waits' );
		ma_cleanup( array( $tia ) );
	}
);

t(
	'MA-7',
	'Each post is credited to the author whose time it is, not to the Post author, including after a crash',
	static function () {
		global $wpdb;
		$category = ready_plugin( array(), 'publish' );
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$abe = ma_user( 'Abe First' );
		$bea = ma_user( 'Bea Second' );
		ma_ok( $abe, array( 'times' => '10:00' ) );
		ma_ok( $bea, array( 'times' => '10:30' ) );
		ma_multi();
		Schedule::ensure_slots();
		ma_queued( $category, 'credit-one' );
		ma_queued( $category, 'credit-two' );

		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( array( $abe ), ma_post_authors() );
		$links = Db::table( 'post_links' );
		t_eq( $abe, (int) $wpdb->get_var( "SELECT author_user_id FROM {$links} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB
		t_eq( (int) $GLOBALS['zzzt_admin'], (int) Settings::get( 'post_author' ), 'the Post author setting is not what decides' );
		t_contains( 'credited to ' . Authors::user_label( $abe ), ma_log( 'publishing' ) );

		add_action(
			'zzzc_after_post_created',
			static function () {
				throw new RuntimeException( 'Simulated PHP timeout right after the post was created' );
			}
		);
		ma_at( '10:30:30' );
		try {
			Publisher::tick();
			throw new ZZZT_Fail( 'the simulated crash did not fire' );
		} catch ( RuntimeException $e ) {
			t_contains( 'Simulated', $e->getMessage() );
		}
		remove_all_actions( 'zzzc_after_post_created' );
		$wpdb->query( 'DELETE FROM ' . Db::table( 'locks' ) );
		t_eq( array( $abe, $bea ), ma_post_authors(), 'the post was made for Bea before the crash' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'publishing_queue' ) . ' SET lock_expires = %s WHERE queue_status = %s', gmdate( 'Y-m-d H:i:s', time() - 5 ), Lifecycle::PUBLISHING ) );
		Publisher::recover_interrupted();
		t_eq( array( $abe, $bea ), ma_post_authors(), 'still Bea after recovery' );
		t_eq( 'publish', get_post_status( ma_posts()[1] ) );
		t_eq( $bea, (int) $wpdb->get_var( "SELECT author_user_id FROM {$links} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB
		t_eq( 2, db_count( 'post_links' ) );
		ma_cleanup( array( $abe, $bea ) );
	}
);

t(
	'MA-8',
	'A paused, revoked or deleted author\'s time is not used: the article waits for another author, no AI is spent for them, and the check is repeated right before the post',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$ann = ma_user( 'Ann Paused' );
		$bob = ma_user( 'Bob Later' );
		ma_ok( $ann, array( 'times' => '10:00' ) );
		ma_ok( $bob, array( 'times' => '16:00' ) );
		ma_multi();
		Schedule::ensure_slots();
		$job = ma_queued( $category, 'waits-for-bob' );

		ma_status( $ann, Authors::PAUSED );
		t_contains( 'Their post times are not used until they are active again. Articles already written stay ready for other authors.', ma_log( 'access' ) );
		t_eq( '', Authors::publishing_problem(), 'Bob can still post' );
		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( array(), ma_posts() );
		$slot = ma_slots( MA_DAY, $ann )[0];
		t_eq( Schedule::MISSED, $slot['status'] );
		t_contains( 'is paused on the Authors page', (string) $slot['status_reason'] );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'the article is not held' );
		t_contains( 'The post time at 2026-11-05 10:00 UTC+10:30 was not used: ' . Authors::user_label( $ann ) . ' is paused on the Authors page.', ma_log( 'publishing' ) );
		$event = Events::query( array( 'stage' => 'publishing' ), 1 )[0];
		t_eq( 'author_unavailable', $event['error_code'] );
		t_eq( $ann, (int) $event['author_user_id'] );

		// Nobody who can post soon: no research, so no AI is spent.
		ma_at( '12:30:00' );
		ma_status( $bob, Authors::REVOKED );
		t_eq( array(), Planner::planned_category_ids() );
		t_contains( 'Multiple users mode is on, but no active author has a schedule', Authors::publishing_problem() );
		Automation::patch( array( 'last_discovery_at' => 0 ) );
		Planner::tick();
		t_eq( 0, db_count( 'jobs', "job_type = 'discover'" ), 'research waits' );
		ma_status( $bob, Authors::ACTIVE );
		t_eq( array( $category ), Planner::planned_category_ids() );

		// Checked again right before the post: Bob's time was claimed, and he is paused before the post is
		// made. The worker's own step is called directly, as nothing else runs between the two checks.
		$queue    = Db::table( 'publishing_queue' );
		$bobs     = ma_slots( MA_DAY, $bob )[0];
		$queue_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$queue} WHERE article_job_id = %d", $job ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET queue_status = %s, slot_id = %d, attempts = 1 WHERE id = %d", Lifecycle::PUBLISHING, (int) $bobs['id'], $queue_id ) ); // phpcs:ignore WordPress.DB
		Lifecycle::transition( $job, Lifecycle::PUBLISHING );
		ma_status( $bob, Authors::PAUSED );
		$step = new ReflectionMethod( Publisher::class, 'run_publish' );
		$step->setAccessible( true );
		$step->invoke( null, $job, $queue_id, 1 );
		t_eq( array(), ma_posts(), 'nothing is credited to a paused author' );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'back in the queue' );
		t_eq( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$queue} WHERE article_job_id = %d", $job ) ) ); // phpcs:ignore WordPress.DB
		t_eq( Schedule::MISSED, ma_slots( MA_DAY, $bob )[0]['status'] );
		t_contains( 'The article went back to the queue for another post time.', ma_log( 'publishing' ) );

		// A deleted author.
		$cid = ma_user( 'Cid Gone' );
		ma_ok( $cid, array( 'times' => '18:00' ) );
		wp_delete_user( $cid );
		Authors::flush_labels();
		t_eq( Authors::MISSING, Authors::status( $cid ) );
		t_false( in_array( $cid, AuthorSchedules::ready_ids(), true ) );
		ma_at( '18:00:30' );
		Publisher::tick();
		t_eq( array(), ma_posts() );
		t_contains( 'no longer exists in WordPress', (string) ma_slots( MA_DAY, $cid )[0]['status_reason'] );
		ma_cleanup( array( $ann, $bob ) );
	}
);

t(
	'MA-9',
	'Daily, weekly and monthly limits, counted in the author\'s own calendar with weeks from Monday, stop their times and their research',
	static function () {
		$category = ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$lia = ma_user( 'Lia Limits' );
		ma_ok( $lia, array( 'times' => '10:00, 12:00, 14:00', 'limit_day' => '1', 'limit_week' => '2', 'limit_month' => '3' ) );
		ma_multi();
		Schedule::ensure_slots();
		ma_queued( $category, 'limit-a' );
		ma_queued( $category, 'limit-b' );

		ma_at( '10:00:30' );
		Publisher::tick();
		t_eq( 1, count( ma_posts() ) );
		ma_at( '12:00:30' );
		t_eq( array(), Planner::planned_category_ids(), 'no research for an author at a limit' );
		Publisher::tick();
		t_eq( 1, count( ma_posts() ), 'one a day' );
		$slot = ma_slots( MA_DAY, $lia )[1];
		t_eq( Schedule::MISSED, $slot['status'] );
		t_contains( 'has reached their daily limit of 1 posts.', (string) $slot['status_reason'] );

		// Friday: a new day. Saturday: the week has 2 already.
		ma_at( '09:00:00', '2026-11-06' );
		Schedule::ensure_slots();
		ma_at( '10:00:30', '2026-11-06' );
		Publisher::tick();
		t_eq( 2, count( ma_posts() ), 'a new day' );
		ma_at( '09:00:00', '2026-11-07' );
		Schedule::ensure_slots();
		ma_queued( $category, 'limit-c' );
		ma_at( '10:00:30', '2026-11-07' );
		Publisher::tick();
		t_eq( 2, count( ma_posts() ) );
		t_contains( 'has reached their weekly limit of 2 posts.', (string) ma_slots( '2026-11-07', $lia )[0]['status_reason'] );

		// Monday starts a new week, and the month allows a third.
		foreach ( array( '2026-11-08', '2026-11-09' ) as $date ) {
			ma_at( '09:00:00', $date );
			Schedule::ensure_slots();
		}
		ma_queued( $category, 'limit-d' );
		ma_at( '10:00:30', '2026-11-09' );
		Publisher::tick();
		t_eq( 3, count( ma_posts() ), 'a new week' );
		ma_at( '09:00:00', '2026-11-10' );
		Schedule::ensure_slots();
		ma_at( '10:00:30', '2026-11-10' );
		Publisher::tick();
		t_eq( 3, count( ma_posts() ) );
		t_contains( 'has reached their monthly limit of 3 posts.', (string) ma_slots( '2026-11-10', $lia )[0]['status_reason'] );
		t_eq( array( 'day' => array( 'used' => 0, 'limit' => 1 ), 'week' => array( 'used' => 1, 'limit' => 2 ), 'month' => array( 'used' => 3, 'limit' => 3 ) ), AuthorSchedules::usage( $lia ) );

		// Drafts do not count when the site counts published posts only.
		Settings::update( array( 'cap_scope' => 'published' ) );
		t_eq( 0, AuthorSchedules::used( $lia, AuthorSchedules::get( $lia ), 'month', Clock::now() ) );
		Settings::update( array( 'cap_scope' => 'all' ) );

		// The author's own calendar: 03:00 UTC on Monday 9 November is still Sunday at -05:00.
		$schedule = AuthorSchedules::validate( ma_input( array( 'times' => '09:00', 'timezone' => '-05:00' ) ) )['schedule'];
		$at       = strtotime( '2026-11-09 03:00:00 UTC' );
		$bounds   = static function ( string $period ) use ( $schedule, $at ): string {
			return implode( ' to ', array_map( static fn( $ts ) => gmdate( 'Y-m-d H:i:s', $ts ), AuthorSchedules::period( $schedule, $period, $at ) ) );
		};
		t_eq( '2026-11-08 05:00:00 to 2026-11-09 04:59:59', $bounds( 'day' ) );
		t_eq( '2026-11-02 05:00:00 to 2026-11-09 04:59:59', $bounds( 'week' ), 'Monday to Sunday' );
		t_eq( '2026-11-01 05:00:00 to 2026-12-01 04:59:59', $bounds( 'month' ) );
		ma_cleanup( array( $lia ) );
	}
);

t(
	'MA-10',
	'The site\'s Maximum posts per day and Maximum article generations per day stay ceilings above every author',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'daily_cap' => 2 ) );
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$kim = ma_user( 'Kim One' );
		$lee = ma_user( 'Lee Two' );
		ma_ok( $kim, array( 'times' => '10:00, 11:00' ) );
		$result = ma_ok( $lee, array( 'times' => '10:30, 11:30' ) );
		t_contains( 'the authors have 4 post times together, but the site allows 2 posts a day', implode( ' ', $result['warnings'] ) );
		ma_multi();
		Schedule::ensure_slots();
		foreach ( array( 'cap-1', 'cap-2', 'cap-3' ) as $slug ) {
			ma_queued( $category, $slug );
		}
		foreach ( array( '10:00:30', '10:30:30', '11:00:30' ) as $time ) {
			ma_at( $time );
			Publisher::tick();
		}
		t_eq( array( $kim, $lee ), ma_post_authors() );
		t_eq( Schedule::MISSED, ma_slots( MA_DAY, $kim )[1]['status'] );
		t_contains( 'The daily cap has been reached.', (string) ma_slots( MA_DAY, $kim )[1]['status_reason'] );

		// Three articles were written today already, so one more is allowed.
		Settings::update( array( 'daily_ai_limit' => db_count( 'generation_usage', "purpose = 'article'" ) + 1 ) );
		$first  = make_job_from_url( $category, ZZZT_MOCK . '/article/ma-limit-one', 'Limit Story One Here' );
		$second = make_job_from_url( $category, ZZZT_MOCK . '/article/ma-limit-two', 'Completely Other Words Second' );
		Automation::patch( array( 'last_discovery_at' => Clock::now(), 'paused_publishing' => 1 ) );
		run_ticks( 8, false );
		t_eq( Lifecycle::AI_PENDING, job_status( $second ), 'the generation limit still applies' );
		t_true( Lifecycle::AI_PENDING !== job_status( $first ) );
		ma_cleanup( array( $kim, $lee ) );
	}
);

t(
	'MA-11',
	'A missed time follows the author\'s own rule: published at that author\'s next time, or skipped',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'catchup' => 'skip', 'catchup_gap_minutes' => 0 ) );
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$nia = ma_user( 'Nia Next' );
		$sol = ma_user( 'Sol Skip' );
		ma_ok( $nia, array( 'times' => '10:00, 12:00', 'missed_rule' => 'next' ) );
		ma_ok( $sol, array( 'times' => '10:30, 12:30', 'missed_rule' => 'skip' ) );
		ma_multi();
		Schedule::ensure_slots();

		ma_at( '10:31:00' );
		Publisher::tick();
		ma_at( '11:01:00' );
		Publisher::tick();
		t_eq( Schedule::DEFERRED, ma_slots( MA_DAY, $nia )[0]['status'], 'kept for her next time, though the site rule is skip' );
		t_eq( Schedule::MISSED, ma_slots( MA_DAY, $sol )[0]['status'] );

		ma_at( '11:05:00' );
		ma_queued( $category, 'missed-one' );
		ma_queued( $category, 'missed-two' );
		ma_at( '11:10:00' );
		Publisher::tick();
		t_eq( array(), ma_posts(), 'not before her next time' );

		ma_at( '12:00:30' );
		Publisher::tick();
		ma_at( '12:00:40' );
		Publisher::tick();
		t_eq( array( $nia, $nia ), ma_post_authors(), 'her 12:00 time, then the one she missed' );
		t_eq( array( 'FILLED', 'FILLED' ), array_column( ma_slots( MA_DAY, $nia ), 'status' ) );
		t_eq( Schedule::MISSED, ma_slots( MA_DAY, $sol )[0]['status'], 'never caught up' );
		ma_cleanup( array( $nia, $sol ) );
	}
);

t(
	'MA-12',
	'Switching modes needs an active author with a schedule, plans the coming times again, never keeps both, and is logged with who switched',
	static function () {
		ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		t_eq( 3, db_count( 'slots', "author_user_id = 0 AND local_date = '" . MA_DAY . "'" ) );

		t_contains( 'Give at least one active author a schedule first', ma_mode( AuthorSchedules::MULTIPLE ) );
		t_false( AuthorSchedules::multiple() );
		t_contains( 'Choose one of the listed options.', ma_mode( 'both' ) );

		$max = ma_user( 'Max Switch' );
		ma_ok( $max, array( 'times' => '11:00, 17:00' ) );
		ma_multi();
		t_eq( 0, db_count( 'slots', "author_user_id = 0 AND status IN ('PENDING','DEFERRED')" ), 'the site\'s own times are not used' );
		t_eq( 2, count( ma_slots( MA_DAY, $max ) ) );
		t_eq( 2, count( ma_slots( '2026-11-06', $max ) ) );
		t_contains( Authors::user_label( (int) $GLOBALS['zzzt_admin'] ) . ' switched posting to Multiple users mode', ma_log( 'access' ) );
		t_eq( '', ma_mode( AuthorSchedules::MULTIPLE ), 'switching to the same mode changes nothing' );

		// Max's 11:00 has passed; it is closed, and his 17:00 is removed, when switching back.
		ma_at( '11:45:00' );
		t_eq( '', ma_mode( AuthorSchedules::SINGLE ) );
		t_false( AuthorSchedules::multiple() );
		t_eq( 0, db_count( 'slots', "author_user_id > 0 AND status IN ('PENDING','DEFERRED')" ), 'no author time stays open' );
		t_contains( 'The posting mode was changed before this time was used.', (string) ma_slots( MA_DAY, $max )[0]['status_reason'] );
		t_eq( 2, db_count( 'slots', "author_user_id = 0 AND local_date = '" . MA_DAY . "' AND status = 'PENDING'" ), '16:00 and 22:00 are planned again' );
		t_contains( 'switched posting to Single user mode', ma_log( 'access' ) );
		ma_cleanup( array( $max ) );
	}
);

t(
	'MA-13',
	'Start Automation in Multiple users mode asks for a scheduled author instead of the Post author',
	static function () {
		ready_plugin();
		Settings::update( array( 'post_author' => 0, 'author_mode' => 'multiple' ) );
		$problems = implode( ' ', Automation::config_problems() );
		t_contains( 'Multiple users mode is on, but no active author has a schedule. On the Authors page, open Schedule next to an author, switch it on and add a post time, or switch back to Single user mode.', $problems );
		t_not_contains( 'No post author is chosen', $problems );
		t_true( is_array( Automation::start( false ) ), 'does not start' );

		$ola = ma_user( 'Ola Ready' );
		ma_ok( $ola, array( 'times' => '10:00' ) );
		$problems = implode( ' ', Automation::config_problems() );
		t_not_contains( 'Multiple users mode is on', $problems );
		t_not_contains( 'post author', strtolower( $problems ) );
		t_true( true === Automation::start( false ), 'starts with no Post author chosen: ' . $problems );

		ma_status( $ola, Authors::PAUSED );
		t_contains( 'Multiple users mode is on, but no active author has a schedule', implode( ' ', Automation::config_problems() ) );
		Settings::update( array( 'author_mode' => 'single' ) );
		t_contains( 'No post author is chosen', implode( ' ', Automation::config_problems() ), 'Single user mode is as before' );
		ma_cleanup( array( $ola ) );
	}
);

t(
	'MA-14',
	'Past-Hour Posting in Multiple users mode searches only the author\'s categories, credits the author, and uses the story age as set',
	static function () {
		$business = ready_plugin( array(), 'publish' );
		$tech     = ma_category( 'Technology', 'feed-tech.xml' );
		Settings::update(
			array(
				'past_hour_enabled'         => 1,
				'past_hour_max_age_minutes' => 60,
				'past_hour_prep_minutes'    => 60,
				'daily_ai_limit'            => 500,
			)
		);
		$story = static function ( string $slug, string $published ): array {
			return array(
				'title'       => 'Story about ' . str_replace( '-', ' ', $slug ) . ' ' . md5( $slug ),
				'link'        => ZZZT_MOCK . '/article/' . $slug,
				'guid'        => 'guid-' . $slug,
				'pubDate'     => gmdate( 'r', ( new DateTimeImmutable( MA_DAY . ' ' . $published, new DateTimeZone( '+10:30' ) ) )->getTimestamp() ),
				'description' => 'Summary of ' . $slug,
			);
		};
		mock_state(
			array(
				'feeds'     => array(
					'feed'     => array( $story( 'ma-business-fresh', '09:12:00' ) ),
					'feed-tech.xml' => array( $story( 'ma-tech-fresh', '09:10:00' ) ),
				),
				'page_date' => '',
			)
		);
		ma_at( '08:30:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$pia = ma_user( 'Pia Past' );
		ma_ok( $pia, array( 'times' => '10:00, 12:00', 'categories' => array( (string) $tech ) ) );
		ma_multi();

		ma_at( '09:15:05' );
		run_ticks( 14 );
		$slot = ma_slots()[0];
		t_eq( $tech, (int) $slot['selected_category_id'], 'Technology was searched' );
		t_eq( 1, db_count( 'article_jobs' ), 'only her story is written' );
		t_eq( 0, db_count( 'source_items', 'category_id = ' . $business ), 'Business was never searched for her' );

		ma_at( '10:00:20' );
		run_ticks( 14 );
		t_eq( array( $pia ), ma_post_authors() );
		t_eq( Schedule::FILLED, ma_slots()[0]['status'] );

		// Nothing new in Technology for 12:00. The other categories searched late in preparation are hers only,
		// so the fresh Business story is never used for her.
		mock_state(
			array(
				'feeds'     => array(
					'feed'      => array( $story( 'ma-business-later', '11:20:00' ) ),
					'feed-tech.xml' => array(),
				),
				'page_date' => '',
			)
		);
		foreach ( array( '11:15:05', '11:31:05', '11:46:05' ) as $time ) {
			ma_at( $time );
			run_ticks( 14 );
		}
		t_eq( 0, db_count( 'source_items', 'category_id = ' . $business ), 'Business is not searched for her, even late' );
		t_eq( 1, db_count( 'article_jobs' ), 'nothing else is written' );
		ma_at( '12:00:20' );
		run_ticks( 14 );
		t_eq( array( $pia ), ma_post_authors(), 'no second post' );
		t_eq( Schedule::SKIPPED, ma_slots()[1]['status'] );

		// The interval between the site's own slots does not shorten the story age.
		Settings::update( array( 'interval_hours' => 1, 'past_hour_max_age_minutes' => 120 ) );
		t_eq( 120, PastHour::max_age_minutes() );
		Settings::update( array( 'author_mode' => 'single' ) );
		t_eq( 60, PastHour::max_age_minutes(), 'Single user mode is as before' );
		ma_cleanup( array( $pia ) );
	}
);

t(
	'MA-15',
	'The Authors page has the Posting mode card and schedule form, both save through checked forms, and other screens show the author',
	static function () {
		ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$quin = ma_user( 'Quin Form' );
		$rex  = ma_user( 'Rex Plain' );
		foreach ( array( 'save_author_mode', 'save_author_schedule' ) as $action ) {
			add_action( 'admin_post_zzzc_' . $action, array( Actions::class, 'dispatch' ) );
		}
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect ' . $location );
			}
		);
		add_filter(
			'wp_die_handler',
			static fn() => static function ( $message ) {
				throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'die' ) );
			}
		);

		$find = array( 's' => get_userdata( $quin )->user_login );
		$page = ma_page( 'authors', $find );
		t_contains( '<h3>Posting mode</h3>', $page );
		t_contains( 'name="author_mode" value="single" checked', $page );
		t_contains( 'name="author_mode" value="multiple"', $page );
		t_contains( 'No active author has a schedule yet.', $page );
		t_contains( 'name="action" value="zzzc_save_author_mode"', $page );
		t_contains( '<th>Schedule</th>', $page );
		t_contains( 'schedule=' . $quin . '#zzzc-author-schedule', $page );

		$form = ma_page( 'authors', array( 'schedule' => (string) $quin ) );
		t_contains( 'id="zzzc-author-schedule"', $form );
		t_contains( 'Schedule of Quin Form (', $form );
		t_contains( 'name="action" value="zzzc_save_author_schedule"', $form );
		t_contains( 'name="user_id" value="' . $quin . '"', $form );
		t_contains( 'name="times" value=""', $form );
		t_contains( 'value="+10:30"', $form, 'the site timezone is filled in' );
		t_contains( 'Single user mode is on, so this schedule is saved but not used yet.', $form );
		t_contains( 'Only an active or paused author can have a schedule.', ma_page( 'authors', array( 'schedule' => '999999' ) ) );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		$post = array_merge( ma_input( array( 'times' => '10:00, 10:05', 'limit_week' => '2' ) ), array( 'user_id' => (string) $quin ) );
		list( $location, $flash ) = ma_submit( 'save_author_schedule', $post );
		t_eq( 'error', $flash['type'] ?? '' );
		t_contains( 'page=zzzc-authors', $location );
		t_contains( 'schedule=' . $quin, $location );
		t_contains( 'less than 15 minutes apart', (string) ( $flash['fields']['times'] ?? '' ) );
		$again = ma_page( 'authors', array( 'schedule' => (string) $quin ) );
		t_contains( 'name="times" value="10:00, 10:05"', $again, 'what was typed is shown again' );
		t_contains( '<p class="zzzc-field-error">10:00 and 10:05 are less than 15 minutes apart', $again );

		$post['times'] = '10:00, 16:00';
		list( , $flash ) = ma_submit( 'save_author_schedule', $post );
		t_eq( 'success', $flash['type'] ?? '' );
		t_contains( 'Schedule saved for Quin Form (', (string) $flash['text'] );
		t_contains( 'It is used once Multiple users mode is switched on.', (string) $flash['text'] );
		t_eq( array( '10:00', '16:00' ), AuthorSchedules::get( $quin )['times'] );
		delete_transient( 'zzzc_flash_' . get_current_user_id() );
		$saved = ma_page( 'authors', array( 'schedule' => (string) $quin ) );
		t_contains( 'name="times" value="10:00, 16:00"', $saved );
		t_contains( 'Used this week, Monday to Sunday: 0 of 2.', $saved );
		t_contains( 'Last saved ', $saved );
		t_contains( 'On: 10:00, 16:00 (+10:30)', ma_page( 'authors', $find ) );

		list( , $flash ) = ma_submit( 'save_author_mode', array( 'author_mode' => 'multiple' ) );
		t_eq( 'success', $flash['type'] ?? '' );
		t_contains( 'Multiple users mode is on.', (string) $flash['text'] );
		t_true( AuthorSchedules::multiple() );

		$schedule = ma_page( 'schedule' );
		t_contains( 'Multiple users mode is on, so each author posts at the times on their own schedule.', $schedule );
		t_contains( '<th>Author</th>', $schedule );
		t_contains( '<td>' . esc_html( Authors::user_label( $quin ) ) . '</td>', $schedule );
		t_not_contains( 'daily slots fit before midnight', $schedule );
		t_contains( 'Multiple users mode is on, so this setting is not used: each post is credited to the author whose time it is.', ma_page( 'publishing' ) );
		t_contains( 'by ' . esc_html( Authors::user_label( $quin ) ), ma_page( 'dashboard' ), 'the next post shows its author' );

		// Refused without the nonce or the capability.
		list( $location ) = ma_submit( 'save_author_mode', array( 'author_mode' => 'single' ), 'wrong' );
		t_contains( 'wp_die', $location );
		t_true( AuthorSchedules::multiple() );
		$editor = ma_user( 'Ed Editor', false );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );
		list( $location ) = ma_submit( 'save_author_schedule', array_merge( $post, array( 'times' => '11:00' ) ) );
		t_contains( 'permission', $location, 'an editor is refused' );
		t_eq( array( '10:00', '16:00' ), AuthorSchedules::get( $quin )['times'] );
		list( $location ) = ma_submit( 'save_author_mode', array( 'author_mode' => 'single' ) );
		t_contains( 'permission', $location );
		t_true( AuthorSchedules::multiple() );

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		ma_cleanup( array( $quin, $rex, $editor ) );
	}
);

t(
	'MA-16',
	'Logs name the author on schedule changes, posts and times not used, and can be filtered by that person',
	static function () {
		$category = ready_plugin();
		ma_at( '09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		$sky = ma_user( 'Sky Logged' );
		$tom = ma_user( 'Tom Paused' );
		ma_ok( $sky, array( 'times' => '10:00', 'limit_day' => '3' ) );
		ma_ok( $tom, array( 'times' => '10:30', 'missed_rule' => 'skip', 'days' => array( '4' ) ) );
		t_contains( Authors::user_label( (int) $GLOBALS['zzzt_admin'] ) . ' saved the schedule of ' . Authors::user_label( $sky ) . ': every day at 10:00 (+10:30); all enabled categories; at most 3 a day; a missed time is published at their next time.', ma_log( 'access' ) );
		t_contains( 'saved the schedule of ' . Authors::user_label( $tom ) . ': Thu at 10:30 (+10:30); all enabled categories; a missed time is skipped.', ma_log( 'access' ) );
		ma_multi();
		Schedule::ensure_slots();
		ma_queued( $category, 'logged-post' );

		ma_at( '10:00:30' );
		Publisher::tick();
		ma_status( $tom, Authors::PAUSED );
		ma_at( '10:30:30' );
		Publisher::tick();

		$publishing = ma_log( 'publishing' );
		t_contains( 'credited to ' . Authors::user_label( $sky ), $publishing );
		t_contains( 'The post time at 2026-11-05 10:30 UTC+10:30 was not used: ' . Authors::user_label( $tom ) . ' is paused on the Authors page.', $publishing );
		t_true( Events::count( array( 'user' => $sky ) ) >= 2, 'the schedule save and the post' );

		$page = ma_page( 'logs', array( 'user' => (string) $tom ) );
		t_contains( 'saved the schedule of ' . esc_html( Authors::user_label( $tom ) ), $page );
		t_contains( 'was not used: ' . esc_html( Authors::user_label( $tom ) ), $page );
		t_not_contains( 'credited to ' . esc_html( Authors::user_label( $sky ) ), $page, 'only entries about Tom' );
		ma_cleanup( array( $sky, $tom ) );
	}
);
