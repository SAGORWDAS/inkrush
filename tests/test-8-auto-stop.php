<?php
use ZZZCopy\Admin\Actions;
use ZZZCopy\AutoStop;
use ZZZCopy\Automation;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Lifecycle;
use ZZZCopy\Locks;
use ZZZCopy\Publisher;
use ZZZCopy\Runner;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;

/**
 * Automation can stop by itself after a number of days or published posts, counted from the last Start.
 */

function as_log( string $stage = 'control' ): string {
	return implode( "\n", array_reverse( array_column( Events::query( array( 'stage' => $stage ), 200 ), 'message' ) ) );
}

/** Two queued articles in the earlier workflow, with automation started at 09:00 and posts at 10:00 and 16:00. */
function as_two_queued( string $mode = 'publish' ): array {
	$category = ready_plugin( array(), $mode );
	set_local_now( '2026-11-05 09:00:00' );
	Automation::patch( array( 'run_state' => Automation::RUNNING, 'started_at' => Clock::now() ) );
	Schedule::ensure_slots();
	$a = queued_article( $category, 'auto-stop-first-' . wp_rand() );
	$b = queued_article( $category, 'auto-stop-second-' . wp_rand() );
	set_local_now( '2026-11-05 09:00:00' );
	return array( $a, $b );
}

function as_statuses( array $jobs ): array {
	$out = array_map( 'job_status', $jobs );
	sort( $out );
	return $out;
}

function as_published_count(): int {
	return db_count( 'post_links', "post_status = 'publish'" );
}

t(
	'AS-1',
	'Off by default: both limits are 0, nothing stops by itself, and the worker runs as before',
	static function () {
		t_eq( 0, (int) Settings::get( 'auto_stop_days' ) );
		t_eq( 0, (int) Settings::get( 'auto_stop_posts' ) );
		t_false( AutoStop::enabled() );
		t_contains( 'Off. Automation runs until you press Stop.', AutoStop::summary() );

		ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'started_at' => Clock::now() - 400 * DAY_IN_SECONDS ) );
		global $wpdb;
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert( Db::table( 'post_links' ), array( 'article_job_id' => 900 + $i, 'job_uid' => wp_generate_uuid4(), 'wp_post_id' => 0, 'post_status' => 'publish', 'local_day' => '2026-11-05', 'created_at' => Clock::mysql() ) );
		}
		t_eq( '', AutoStop::reason() );
		t_false( AutoStop::check() );
		$summary = Runner::tick( 'cli' );
		t_true( $summary['ran'], 'the worker ran' );
		t_eq( Automation::RUNNING, Automation::get( 'run_state' ) );
		t_not_contains( 'stopped automatically', as_log() );
	}
);

t(
	'AS-2',
	'The post limit stops automation right after the post that reaches it, like the Stop button: the next article stays queued, the tick is cancelled and no later post is made',
	static function () {
		list( $a, $b ) = as_two_queued();
		t_eq( array(), Settings::update( array( 'auto_stop_posts' => 1 ) )['errors'] );
		wp_schedule_event( time() + 60, 'zzzc_minute', 'zzzc_tick' );
		t_contains( 'after 1 published post (0 so far)', AutoStop::summary() );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( array( Lifecycle::PUBLISHED, Lifecycle::QUEUED ), as_statuses( array( $a, $b ) ) );
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ) );
		t_false( wp_next_scheduled( 'zzzc_tick' ), 'future ticks are cancelled' );
		t_contains( 'Automation stopped automatically: 1 post was published since automation started, which reaches the limit of 1. Queued articles and history were kept.', as_log() );
		t_contains( '1 post was published', Automation::automatic_stop_reason() );

		set_local_now( '2026-11-05 16:00:30' );
		t_eq( 'stopped', Runner::tick( 'cli' )['reason'] );
		Publisher::tick();
		t_eq( 1, as_published_count(), 'no post after the limit' );
		t_eq( array( Lifecycle::PUBLISHED, Lifecycle::QUEUED ), as_statuses( array( $a, $b ) ), 'the second article is kept' );
	}
);

t(
	'AS-3',
	'Drafts do not count toward the post limit',
	static function () {
		list( $a, $b ) = as_two_queued( 'draft' );
		Settings::update( array( 'auto_stop_posts' => 1 ) );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_true( in_array( Lifecycle::DRAFT_CREATED, as_statuses( array( $a, $b ) ), true ) );
		t_eq( Automation::RUNNING, Automation::get( 'run_state' ) );
		t_contains( '(0 so far)', AutoStop::summary() );
	}
);

t(
	'AS-4',
	'Both limits count from the last Start, and count again from 0 after a new Start, which works exactly as before',
	static function () {
		list( $a, $b ) = as_two_queued();
		Settings::update( array( 'auto_stop_posts' => 1 ) );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ) );

		$started = set_local_now( '2026-11-05 11:00:00' );
		t_true( true === Automation::start( false ), 'Start works with limits set' );
		t_eq( Automation::STARTING, Automation::get( 'run_state' ) );
		t_eq( $started, (int) Automation::get( 'started_at' ) );
		t_true( (bool) wp_next_scheduled( 'zzzc_tick' ), 'Start schedules the worker as before' );
		t_contains( 'Automation started.', as_log() );
		t_eq( '', Automation::automatic_stop_reason(), 'the old automatic stop is not shown after a new Start' );
		t_contains( '(0 so far)', AutoStop::summary(), 'the post published before this Start does not count' );
		t_eq( '', AutoStop::reason() );

		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( array( Lifecycle::PUBLISHED, Lifecycle::PUBLISHED ), as_statuses( array( $a, $b ) ) );
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ), 'the first post after the new Start reaches the limit again' );
	}
);

t(
	'AS-5',
	'The days limit stops the worker 24 hours per day after Start, even while paused, before any job runs',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		t_true( true === Automation::start( false ) );
		Settings::update( array( 'auto_stop_days' => 1 ) );
		Automation::pause_research();
		Automation::pause_publishing();
		t_contains( 'Stops on 2026-11-06 09:00 UTC+10:30.', AutoStop::summary() );

		set_local_now( '2026-11-06 08:59:59' );
		$summary = Runner::tick( 'cli' );
		t_true( $summary['ran'], 'not yet' );
		t_true( Automation::is_active() );

		$job     = make_job_from_url( $category, ZZZT_MOCK . '/article/auto-stop-days' );
		$pending = db_count( 'jobs', "status = 'pending'" );
		set_local_now( '2026-11-06 09:00:00' );
		$summary = Runner::tick( 'cli' );
		t_eq( 'auto_stopped', $summary['reason'] );
		t_false( $summary['ran'] );
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ) );
		t_eq( $pending, db_count( 'jobs', "status = 'pending'" ), 'no job ran' );
		t_true( $job > 0 );
		t_contains( 'Automation stopped automatically: 1 day has passed since automation started on', as_log() );
		$owner = Locks::acquire( 'runner', 60 );
		t_true( (bool) $owner, 'the worker lock was released' );
		Locks::release( 'runner', $owner );
		t_eq( 'stopped', Runner::tick( 'cli' )['reason'] );
	}
);

t(
	'AS-6',
	'With both limits set, whichever comes first stops automation, and the summary says so',
	static function () {
		list( $a, $b ) = as_two_queued();
		Settings::update( array( 'auto_stop_posts' => 5, 'auto_stop_days' => 2 ) );
		$text = AutoStop::summary();
		t_contains( 'Stops after 5 published posts (0 so far), or on 2026-11-07 09:00 UTC+10:30', $text );
		t_contains( 'whichever comes first', $text );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Automation::RUNNING, Automation::get( 'run_state' ), '1 of 5 posts' );
		t_contains( '(1 so far)', AutoStop::summary() );

		set_local_now( '2026-11-07 09:00:00' );
		t_eq( 'auto_stopped', Runner::tick( 'cli' )['reason'] );
		t_contains( '2 days have passed', Automation::automatic_stop_reason() );

		Automation::patch( array( 'run_state' => Automation::STOPPED ) );
		t_contains( 'counted from when you press Start Automation', AutoStop::summary() );
	}
);

t(
	'AS-7',
	'The limits accept whole numbers from 0 to 365 days and 0 to 10000 posts, and nothing else',
	static function () {
		foreach ( array( -1, 366, 'abc', '1.5' ) as $bad ) {
			t_true( isset( Settings::update( array( 'auto_stop_days' => $bad ) )['errors']['auto_stop_days'] ), "days $bad refused" );
		}
		foreach ( array( -1, 10001, 'many' ) as $bad ) {
			t_true( isset( Settings::update( array( 'auto_stop_posts' => $bad ) )['errors']['auto_stop_posts'] ), "posts $bad refused" );
		}
		t_eq( 0, (int) Settings::get( 'auto_stop_days' ), 'nothing invalid was saved' );
		t_eq( array(), Settings::update( array( 'auto_stop_days' => 365, 'auto_stop_posts' => 10000 ) )['errors'] );
		t_eq( 365, Settings::get( 'auto_stop_days' ) );
		t_eq( 10000, Settings::get( 'auto_stop_posts' ) );
		t_eq( array(), Settings::update( array( 'auto_stop_days' => '0', 'auto_stop_posts' => '0' ) )['errors'] );
		t_false( AutoStop::enabled() );
	}
);

t(
	'AS-8',
	'The Schedule page has its own Automatic stop form: saving checks the nonce, does not rebuild the schedule, logs the change, and warns when a limit is already reached',
	static function () {
		ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'started_at' => Clock::now() ) );

		$html = au_page( 'schedule' );
		t_contains( '<h3>Automatic stop</h3>', $html );
		t_contains( 'name="auto_stop_days"', $html );
		t_contains( 'name="auto_stop_posts"', $html );
		t_contains( 'value="zzzc_save_auto_stop"', $html );
		t_contains( 'Save automatic stop', $html );
		t_contains( 'Off. Automation runs until you press Stop.', $html );

		add_action( 'admin_post_zzzc_save_auto_stop', array( Actions::class, 'dispatch' ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect ' . $location );
			}
		);
		au_die_throws();
		$submit = static function ( array $post, string $nonce = '' ): array {
			$_POST    = $post;
			$_REQUEST = array( '_wpnonce' => $nonce ?: wp_create_nonce( 'zzzc_save_auto_stop' ) );
			$location = '';
			try {
				do_action( 'admin_post_zzzc_save_auto_stop' );
			} catch ( RuntimeException $e ) {
				$location = $e->getMessage();
			}
			$flash = (array) get_transient( 'zzzc_flash_' . get_current_user_id() );
			delete_transient( 'zzzc_flash_' . get_current_user_id() );
			return array( $location, $flash );
		};

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		list( $location, $flash ) = $submit( array( 'auto_stop_days' => '0', 'auto_stop_posts' => '3' ) );
		t_eq( 'success', $flash['type'] ?? '' );
		t_contains( 'page=zzzc-schedule', $location );
		t_eq( 3, Settings::get( 'auto_stop_posts' ) );
		t_contains( 'Automatic stop changed: Stops after 3 published posts (0 so far).', as_log( 'config' ) );
		t_not_contains( 'Schedule settings changed', as_log( 'config' ), 'the schedule is not rebuilt' );

		list( , $flash ) = $submit( array( 'auto_stop_days' => '400', 'auto_stop_posts' => '3' ) );
		t_eq( 'error', $flash['type'] ?? '' );
		t_true( isset( $flash['fields']['auto_stop_days'] ) );

		list( $location ) = $submit( array( 'auto_stop_days' => '0', 'auto_stop_posts' => '0' ), 'wrong' );
		t_contains( 'wp_die', $location, 'a bad nonce is refused' );
		t_eq( 3, Settings::get( 'auto_stop_posts' ) );

		global $wpdb;
		$wpdb->insert( Db::table( 'post_links' ), array( 'article_job_id' => 991, 'job_uid' => wp_generate_uuid4(), 'wp_post_id' => 0, 'post_status' => 'publish', 'local_day' => '2026-11-05', 'created_at' => Clock::mysql() ) );
		list( , $flash ) = $submit( array( 'auto_stop_days' => '0', 'auto_stop_posts' => '1' ) );
		t_eq( 'warning', $flash['type'] ?? '' );
		t_contains( 'already reached, so automation stops on the next worker run', (string) ( $flash['text'] ?? '' ) );
		t_eq( Automation::RUNNING, Automation::get( 'run_state' ), 'saving does not stop it by itself' );
		t_eq( 'auto_stopped', Runner::tick( 'cli' )['reason'] );

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
	}
);

t(
	'AS-9',
	'The Dashboard shows the limits, says when automation stopped by itself and why, and stops saying so after a new Start or a manual Stop',
	static function () {
		list( $a, $b ) = as_two_queued();
		Settings::update( array( 'auto_stop_posts' => 1 ) );
		$html = au_page( 'dashboard' );
		t_contains( '<dt>Automatic stop</dt><dd>Stops after 1 published post (0 so far).</dd>', $html );
		t_not_contains( 'stopped by itself', $html );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		$html = au_page( 'dashboard' );
		t_contains( 'Automation stopped by itself on', $html );
		t_contains( '1 post was published since automation started, which reaches the limit of 1.', $html );
		t_contains( 'Start Automation', $html );

		set_local_now( '2026-11-05 11:00:00' );
		Automation::start( false );
		t_not_contains( 'stopped by itself', au_page( 'dashboard' ) );
		Automation::stop();
		t_contains( 'Automation stopped.', as_log() );
		t_eq( '', Automation::automatic_stop_reason(), 'a manual Stop is not shown as automatic' );
		t_not_contains( 'stopped by itself', au_page( 'dashboard' ) );
	}
);

t(
	'AS-10',
	'A limit reached partway through a worker run stops it at the next safe checkpoint, before another job starts',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		t_true( true === Automation::start( false ) );
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => Clock::now() + DAY_IN_SECONDS ) );
		Settings::update( array( 'auto_stop_days' => 1 ) );
		make_job_from_url( $category, ZZZT_MOCK . '/article/auto-stop-mid-run' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'jobs' ) . ' SET run_at = %s', Clock::mysql( Clock::now() ) ) );

		// One second before the limit when the run begins. The clock passes it while the run is under way.
		set_local_now( '2026-11-06 08:59:59' );
		$moved = false;
		$tick  = static function ( $query ) use ( &$moved ) {
			if ( ! $moved && false !== strpos( $query, 'lock_expires <' ) ) {
				$moved                = true;
				$GLOBALS['zzzt_now'] += 2;
			}
			return $query;
		};
		add_filter( 'query', $tick );
		$pending = db_count( 'jobs', "status = 'pending'" );
		$summary = Runner::tick( 'cli' );
		remove_filter( 'query', $tick );

		t_true( $moved, 'the clock moved during the run' );
		t_true( $pending > 0, 'a job was waiting' );
		t_eq( 0, (int) $summary['jobs'], 'no job started after the limit' );
		t_eq( $pending, db_count( 'jobs', "status = 'pending'" ) );
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ) );
		t_contains( '1 day has passed', Automation::automatic_stop_reason() );
	}
);
