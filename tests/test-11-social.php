<?php
/**
 * Social media distribution (1.10.0).
 *
 * These run against a real WordPress site with the local stand-in for the six platforms, so the
 * real request shapes, the real job queue and the real database constraints are exercised.
 *
 * The behaviour that needs no site, such as byte offsets and character budgets, is covered by
 * tests/unit/run-unit.php instead, which runs anywhere PHP does.
 */

use ZZZCopy\Automation;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Jobs;
use ZZZCopy\Lifecycle;
use ZZZCopy\Runner;
use ZZZCopy\Settings;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialManager;
use ZZZCopy\Social\SocialQueue;
use ZZZCopy\Social\SocialSecrets;
use ZZZCopy\Social\SocialSettings;

/* ---------- Helpers for these tests ---------- */

/**
 * Connects one platform with a credential, as if an administrator had authorised it, and
 * switches it on. Returns the account id.
 */
function social_connect( string $platform, bool $enable = true ): int {
	SocialSecrets::set_app( $platform, 'mock-client-id', 'mock-client-secret' );

	$external = array(
		Platforms::FACEBOOK  => '111222333',
		Platforms::INSTAGRAM => '17841400000000000',
		Platforms::X         => '1500000000',
		Platforms::LINKEDIN  => 'urn:li:person:mockmember123',
		Platforms::THREADS   => '7700000000000',
		Platforms::BLUESKY   => 'did:plc:mock1234567890',
	);

	$account_id = SocialAccounts::save(
		array(
			'platform'       => $platform,
			'external_id'    => $external[ $platform ],
			'account_name'   => 'Mock News',
			'account_handle' => '@mocknews',
			'account_type'   => Platforms::LINKEDIN === $platform ? 'member' : 'account',
			'access_token'   => 'mock-access-token-for-' . $platform . '-1234567890',
			'refresh_token'  => 'mock-refresh-token-' . $platform,
			'scopes'         => 'mock',
			'extra'          => array(
				'username'        => 'mocknews',
				'handle'          => 'mocknews.bsky.social',
				'did'             => 'did:plc:mock1234567890',
				'page_id'         => '111222333',
				'ig_user_id'      => '17841400000000000',
				'threads_user_id' => '7700000000000',
				'author_urn'      => 'urn:li:person:mockmember123',
			),
		)
	);

	Settings::update( array( 'social_enabled' => 1, 'social_auto_distribute' => 1 ) );
	SocialSettings::save_platforms(
		array(
			$platform => array(
				'enabled'    => $enable ? 1 : 0,
				'account_id' => $account_id,
			),
		)
	);
	SocialSettings::flush_cache();

	return $account_id;
}

/** Sets how the stand-in platforms behave. */
function social_mock( array $values ): void {
	mock_state( $values );
}

/** Publishes one article through the plugin and returns its WordPress post id. */
function social_publish_article(): int {
	$category = ready_plugin( array( feed_item( 'social-one' ) ), 'publish' );
	$job_id   = make_job_from_url( $category, ZZZT_MOCK . '/article/social-one' );
	run_jobs_only( 14 );

	global $wpdb;
	$post_id = (int) $wpdb->get_var( 'SELECT wp_post_id FROM ' . Db::table( 'post_links' ) . ' WHERE article_job_id = ' . (int) $job_id );
	return $post_id;
}

/** One social post row for a post and platform. */
function social_row( int $post_id, string $platform ): ?array {
	foreach ( SocialQueue::for_post( $post_id ) as $row ) {
		if ( $row['platform'] === $platform ) {
			return $row;
		}
	}
	return null;
}

/** Runs the worker until the social queue settles. */
function social_run( int $limit = 14 ): void {
	global $wpdb;
	$jobs = Db::table( 'jobs' );
	for ( $i = 0; $i < $limit; $i++ ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET run_at = %s WHERE status = 'pending'", Clock::mysql( Clock::now() - 1 ) ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'social_posts' ) . ' SET retry_at = %s WHERE retry_at IS NOT NULL', Clock::mysql( Clock::now() - 1 ) ) );
		Runner::tick( 'cli' );
	}
}

/** What the stand-in platform was asked to do. */
function social_requests( string $platform = '' ): array {
	$file = __DIR__ . '/mock-state/social.jsonl';
	if ( ! is_file( $file ) ) {
		return array();
	}
	$out = array();
	foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) {
		$row = json_decode( trim( $line ), true );
		if ( is_array( $row ) && ( '' === $platform || $row['platform'] === $platform ) ) {
			$out[] = $row;
		}
	}
	return $out;
}

function social_clear_requests(): void {
	@unlink( __DIR__ . '/mock-state/social.jsonl' );
}

/* ---------- Setup and settings ---------- */

t(
	'S-01',
	'The new tables exist and distribution is off until it is switched on',
	function () {
		global $wpdb;
		foreach ( array( 'social_accounts', 'social_posts' ) as $table ) {
			$name = Db::table( $table );
			t_eq( $name, $wpdb->get_var( "SHOW TABLES LIKE '{$name}'" ), "table $table exists" );
		}
		t_eq( '12', \ZZZCopy\Schema::DB_VERSION, 'database version is 12' );
		t_false( SocialSettings::enabled(), 'distribution is off on a fresh install' );
		t_eq( array(), SocialSettings::active_platforms(), 'no platform is active on a fresh install' );
	}
);

t(
	'S-02',
	'An upgrade from the previous version changes no existing data',
	function () {
		$category = ready_plugin( array( feed_item( 'upgrade-check' ) ), 'publish' );
		$job_id   = make_job_from_url( $category, ZZZT_MOCK . '/article/upgrade-check' );
		run_jobs_only( 14 );

		$before = db_count( 'article_jobs' ) . '|' . db_count( 'post_links' ) . '|' . db_count( 'source_items' );

		// Re-run the migration as an upgrade from 11.
		update_option( 'zzzc_db_version', '11' );
		\ZZZCopy\Schema::maybe_upgrade();

		$after = db_count( 'article_jobs' ) . '|' . db_count( 'post_links' ) . '|' . db_count( 'source_items' );
		t_eq( $before, $after, 'article, post and source records are untouched by the upgrade' );
		t_eq( '12', get_option( 'zzzc_db_version' ), 'the database version moved to 12' );
		t_false( SocialSettings::enabled(), 'the upgrade does not switch distribution on' );
		t_eq( job_status( $job_id ), 'PUBLISHED', 'the existing article is still published' );
	}
);

t(
	'S-03',
	'A platform cannot be switched on without a connected account',
	function () {
		Settings::update( array( 'social_enabled' => 1 ) );
		$result = SocialSettings::save_platforms( array( Platforms::X => array( 'enabled' => 1 ) ) );
		t_true( isset( $result['errors'][ Platforms::X . '_enabled' ] ), 'switching it on is refused' );
		t_false( SocialSettings::platform_enabled( Platforms::X ), 'it stays off' );
	}
);

t(
	'S-04',
	'An account credential is stored encrypted and never returned by an ordinary read',
	function () {
		$account_id = social_connect( Platforms::BLUESKY );

		global $wpdb;
		$stored = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'social_accounts' ) . ' WHERE id = ' . $account_id, ARRAY_A );
		t_not_contains( 'mock-access-token', (string) $stored['access_token'], 'the credential is not stored in plain text' );
		t_true( strlen( (string) $stored['access_token'] ) > 20, 'something was stored' );

		$safe = SocialAccounts::get( $account_id );
		t_false( isset( $safe['access_token'] ), 'an ordinary read returns no credential column' );
		t_false( isset( $safe['refresh_token'] ), 'an ordinary read returns no refresh credential' );
		t_eq( 1, (int) $safe['has_access_token'], 'it reports that a credential exists' );

		$credentials = SocialAccounts::credentials( $account_id );
		t_true( $credentials['ok'], 'the credential can be decrypted for a server-side call' );
		t_contains( 'mock-access-token', $credentials['access_token'], 'and it round-trips exactly' );
	}
);

/* ---------- When distribution happens, and when it must not ---------- */

t(
	'S-10',
	'A published article is queued for every platform that is switched on',
	function () {
		social_connect( Platforms::BLUESKY );
		social_connect( Platforms::X );
		$post_id = social_publish_article();

		t_true( $post_id > 0, 'the article was published to the website' );
		$rows = SocialQueue::for_post( $post_id );
		t_eq( 2, count( $rows ), 'one record per platform' );
		foreach ( $rows as $row ) {
			t_true( in_array( $row['platform'], array( Platforms::BLUESKY, Platforms::X ), true ), 'the records are for the platforms that are on' );
		}
	}
);

t(
	'S-11',
	'A draft article is never distributed',
	function () {
		social_connect( Platforms::BLUESKY );
		$category = ready_plugin( array( feed_item( 'draft-one' ) ), 'draft' );
		$job_id   = make_job_from_url( $category, ZZZT_MOCK . '/article/draft-one' );
		run_jobs_only( 14 );

		t_eq( 'DRAFT_CREATED', job_status( $job_id ), 'the article was created as a draft' );
		t_eq( 0, db_count( 'social_posts' ), 'nothing was queued for social media' );
	}
);

t(
	'S-12',
	'Publishing a draft by hand distributes it only when that setting is on',
	function () {
		social_connect( Platforms::BLUESKY );
		$category = ready_plugin( array( feed_item( 'manual-one' ) ), 'draft' );
		make_job_from_url( $category, ZZZT_MOCK . '/article/manual-one' );
		run_jobs_only( 14 );

		global $wpdb;
		$post_id = (int) $wpdb->get_var( 'SELECT wp_post_id FROM ' . Db::table( 'post_links' ) . ' LIMIT 1' );
		t_true( $post_id > 0, 'there is a draft post' );

		// The setting is off by default.
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		t_eq( 0, db_count( 'social_posts' ), 'with the setting off, publishing by hand distributes nothing' );

		// With it on, the same thing does distribute.
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		Settings::update( array( 'social_on_manual_publish' => 1 ) );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		t_eq( 1, db_count( 'social_posts' ), 'with the setting on, it is distributed' );
	}
);

t(
	'S-13',
	'A scheduled post is distributed when it actually goes public, not before',
	function () {
		social_connect( Platforms::BLUESKY );

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'A scheduled article',
				'post_content' => 'The council agreed the plan on Tuesday.',
				'post_status'  => 'future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'post_type'    => 'post',
			)
		);
		// Recorded as a plugin post, which is the only kind that is distributed.
		$GLOBALS['wpdb']->insert(
			Db::table( 'post_links' ),
			array(
				'article_job_id' => 991,
				'job_uid'        => wp_generate_uuid4(),
				'wp_post_id'     => (int) $post_id,
				'post_status'    => 'future',
				'local_day'      => gmdate( 'Y-m-d' ),
				'created_at'     => Clock::mysql(),
			)
		);

		t_eq( 0, db_count( 'social_posts' ), 'a scheduled post is not distributed while it waits' );

		wp_publish_post( (int) $post_id );
		t_eq( 1, db_count( 'social_posts' ), 'it is distributed once it is public' );
	}
);

t(
	'S-14',
	'An article already distributed is not distributed again when it is edited',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		t_eq( 1, db_count( 'social_posts' ), 'one record to begin with' );

		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'A correction was made to this article.' ) );
		t_eq( 1, db_count( 'social_posts' ), 'editing it creates no second record' );
	}
);

t(
	'S-15',
	'Deleting an article leaves published social posts alone and cancels the rest',
	function () {
		social_connect( Platforms::BLUESKY );
		social_connect( Platforms::X );
		$post_id = social_publish_article();

		$bluesky = social_row( $post_id, Platforms::BLUESKY );
		SocialQueue::mark_published( (int) $bluesky['id'], 'at://mock/post/1', 'https://bsky.app/mock' );

		wp_delete_post( $post_id, true );

		$rows      = SocialQueue::for_post( $post_id );
		$published = 0;
		$cancelled = 0;
		foreach ( $rows as $row ) {
			if ( SocialQueue::PUBLISHED === $row['status'] ) {
				$published++;
			}
			if ( SocialQueue::CANCELLED === $row['status'] ) {
				$cancelled++;
			}
		}
		t_eq( 1, $published, 'the published post stays recorded as published' );
		t_eq( 1, $cancelled, 'the one not yet published is cancelled' );
		t_contains( 'at://mock/post/1', (string) $rows[0]['external_post_id'] . (string) $rows[1]['external_post_id'], 'the published post keeps its identifier' );
	}
);

/* ---------- Independence from news automation ---------- */

t(
	'S-20',
	'Queued social posts still publish after automation is stopped',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );
		$post_id = social_publish_article();

		// Stop automation, exactly as the Stop button does.
		Automation::stop();
		t_false( Automation::is_active(), 'automation is stopped' );

		social_run( 14 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::PUBLISHED, (string) $row['status'], 'the social post was published although automation is stopped' );
		t_contains( 'at://', (string) $row['external_post_id'], 'the platform returned an identifier' );
	}
);

t(
	'S-21',
	'The worker keeps its schedule after a stop while distribution is on',
	function () {
		social_connect( Platforms::BLUESKY );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		\ZZZCopy\Plugin::sync_schedule();

		Automation::stop();
		t_true( (bool) wp_next_scheduled( \ZZZCopy\Plugin::TICK_HOOK ), 'the worker is still scheduled, so queued social posts can go out' );

		// With distribution off as well, nothing is wanted and the schedule is cleared.
		Settings::update( array( 'social_enabled' => 0 ) );
		\ZZZCopy\Plugin::sync_schedule();
		t_false( (bool) wp_next_scheduled( \ZZZCopy\Plugin::TICK_HOOK ), 'with both off the schedule is cleared, as before' );
	}
);

t(
	'S-22',
	'Social job types are allowed when automation is stopped, and news types are not',
	function () {
		social_connect( Platforms::BLUESKY );
		Automation::stop();

		$types = Runner::allowed_types();
		t_true( in_array( Jobs::SOCIAL_PUBLISH, $types, true ), 'social publishing is allowed' );
		t_true( in_array( Jobs::SOCIAL_RECONCILE, $types, true ), 'reconciliation is allowed' );
		t_false( in_array( Jobs::DISCOVER, $types, true ), 'news discovery is not' );
		t_false( in_array( Jobs::GENERATE, $types, true ), 'article writing is not' );
	}
);

t(
	'S-23',
	'Research being paused does not stop social publishing',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );
		$post_id = social_publish_article();

		Automation::pause_research();
		social_run( 14 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::PUBLISHED, (string) $row['status'], 'it was published while research is paused' );
	}
);

/* ---------- Publishing on each platform ---------- */

foreach ( array(
	Platforms::FACEBOOK  => '111222333_999888777',
	Platforms::INSTAGRAM => '18000000000000000',
	Platforms::X         => '1800000000000000002',
	Platforms::LINKEDIN  => 'urn:li:share:7100000000000000003',
	Platforms::THREADS   => '18800000000000001',
	Platforms::BLUESKY   => 'at://did:plc:mock1234567890/app.bsky.feed.post/3kmockrkey01',
) as $social_platform => $social_expected_id ) {

	t(
		'S-30 ' . $social_platform,
		'Publishes to ' . Platforms::label( $social_platform ) . ' and records the identifier it returned',
		function () use ( $social_platform, $social_expected_id ) {
			social_clear_requests();
			social_connect( $social_platform );
			social_mock( array( 'social_mode' => 'ok' ) );

			$post_id = social_publish_article();
			social_run( 14 );

			$row = social_row( $post_id, $social_platform );
			t_true( is_array( $row ), 'there is a record' );
			t_eq( SocialQueue::PUBLISHED, (string) $row['status'], 'it is recorded as published' );
			t_eq( $social_expected_id, (string) $row['external_post_id'], 'the identifier is the one the platform returned' );
			t_true( '' !== trim( (string) $row['caption'] ), 'post text was written and saved' );

			$requests = social_requests( $social_platform );
			t_true( count( $requests ) > 0, 'the platform was actually called' );

			// A credential was sent, and the whole value never reaches the log.
			$authed = false;
			foreach ( $requests as $request ) {
				if ( '' !== (string) $request['auth'] ) {
					$authed = true;
				}
			}
			t_true( $authed, 'the requests carried a credential' );
		}
	);
}

t(
	'S-31',
	'One platform failing leaves the others published',
	function () {
		social_connect( Platforms::BLUESKY );
		social_connect( Platforms::X );
		// Bluesky is published first in platform order, then X is refused.
		social_mock( array( 'social_mode' => array( 'ok', 'permission', 'permission', 'permission', 'permission' ) ) );

		$post_id = social_publish_article();
		social_run( 16 );

		$bluesky = social_row( $post_id, Platforms::BLUESKY );
		$x       = social_row( $post_id, Platforms::X );

		t_eq( SocialQueue::PUBLISHED, (string) $bluesky['status'], 'Bluesky is published' );
		t_eq( SocialQueue::FAILED, (string) $x['status'], 'X failed' );
		t_eq( 'permission_denied', (string) $x['error_code'], 'the reason is recorded against X only' );
		t_eq( '', (string) $bluesky['error_code'], 'nothing was recorded against Bluesky' );
	}
);

t(
	'S-32',
	'A platform failure never changes the article',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'permission' ) );

		$category = ready_plugin( array( feed_item( 'fail-social' ) ), 'publish' );
		$job_id   = make_job_from_url( $category, ZZZT_MOCK . '/article/fail-social' );
		run_jobs_only( 14 );
		social_run( 14 );

		t_eq( 'PUBLISHED', job_status( $job_id ), 'the article is still published' );
		global $wpdb;
		$post_id = (int) $wpdb->get_var( 'SELECT wp_post_id FROM ' . Db::table( 'post_links' ) . ' WHERE article_job_id = ' . (int) $job_id );
		t_eq( 'publish', get_post_status( $post_id ), 'the WordPress post is still public' );
	}
);

/* ---------- Duplicate prevention and idempotency ---------- */

t(
	'S-40',
	'Asking twice for the same article and account creates only one record',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		t_eq( 1, db_count( 'social_posts' ), 'one record' );

		$again = SocialManager::enqueue_for_post( $post_id, array( 'origin' => 'manual' ) );
		t_eq( 1, db_count( 'social_posts' ), 'asking again creates no second record' );
		t_true( isset( $again['skipped'][ Platforms::BLUESKY ] ), 'and it says why' );
	}
);

t(
	'S-41',
	'The idempotency key is unique in the database',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		global $wpdb;
		$wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Db::table( 'social_posts' ) . ' (idempotency_key, wp_post_id, platform, account_id, status, created_at, updated_at) VALUES (%s, %d, %s, %d, %s, %s, %s)',
				$row['idempotency_key'],
				$post_id,
				Platforms::BLUESKY,
				(int) $row['account_id'],
				SocialQueue::PENDING,
				Clock::mysql(),
				Clock::mysql()
			)
		);
		$wpdb->suppress_errors( false );
		t_eq( 0, (int) $inserted, 'the database refuses a second row with the same key' );
	}
);

t(
	'S-42',
	'A published post is never sent again, however often a retry is asked for',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );
		$post_id = social_publish_article();
		social_run( 14 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::PUBLISHED, (string) $row['status'], 'it is published' );

		social_clear_requests();
		$retry = SocialManager::retry( (int) $row['id'] );
		t_false( $retry['ok'], 'a retry is refused' );
		t_contains( 'published', strtolower( (string) $retry['message'] ), 'and it says why' );

		social_run( 6 );
		t_eq( 0, count( social_requests( Platforms::BLUESKY ) ), 'nothing further was sent to the platform' );
	}
);

t(
	'S-43',
	'A worker that stops mid-publish leaves the post to be checked, not retried',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		// A claim that has expired, which is what a stopped worker leaves behind.
		global $wpdb;
		$wpdb->update(
			Db::table( 'social_posts' ),
			array(
				'status'        => SocialQueue::PUBLISHING,
				'claimed_by'    => 'dead-worker',
				'claim_expires' => Clock::mysql( Clock::now() - 600 ),
			),
			array( 'id' => (int) $row['id'] )
		);

		t_eq( 1, SocialQueue::recover_stale(), 'the stale record is recovered' );
		$after = SocialQueue::get( (int) $row['id'] );
		t_eq( SocialQueue::RECONCILING, (string) $after['status'], 'it goes to be checked rather than straight back to publishing' );
		t_eq( 'worker_stopped', (string) $after['error_code'], 'with the reason recorded' );
	}
);

/* ---------- An unclear outcome is reconciled, never resent ---------- */

t(
	'S-50',
	'A request that times out is checked against the platform instead of being resent',
	function () {
		social_connect( Platforms::BLUESKY );
		Settings::update( array( 'http_timeout' => 3 ) );
		// The platform accepts the request but never answers.
		social_mock( array( 'social_mode' => array( 'ok', 'hang' ) ) );

		$post_id = social_publish_article();
		social_run( 4 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::RECONCILING, (string) $row['status'], 'the post is being checked' );
		t_eq( '', (string) $row['external_post_id'], 'it is not claimed as published' );
		t_false( SocialQueue::FAILED === (string) $row['status'], 'nor is it called a failure' );
	}
);

t(
	'S-51',
	'Reconciliation finds a post that did reach the platform and does not send it again',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		SocialQueue::set_caption( (int) $row['id'], 'Council approves the transport plan', '', 'ai' );
		SocialQueue::mark_reconciling( (int) $row['id'], 'no_answer', 'No answer came back.' );

		// The platform reports the post as existing.
		social_mock(
			array(
				'social_reconcile' => 'exists',
				'social_last_text' => 'Council approves the transport plan',
			)
		);

		social_clear_requests();
		social_run( 6 );

		$after = SocialQueue::get( (int) $row['id'] );
		t_eq( SocialQueue::PUBLISHED, (string) $after['status'], 'it is recorded as published' );
		t_contains( 'at://', (string) $after['external_post_id'], 'with the identifier the platform reported' );

		foreach ( social_requests( Platforms::BLUESKY ) as $request ) {
			t_false( '/xrpc/com.atproto.repo.createRecord' === $request['path'], 'no second post was created' );
		}
	}
);

t(
	'S-52',
	'Reconciliation that finds nothing allows the post to be sent again',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		SocialQueue::set_caption( (int) $row['id'], 'Council approves the transport plan', '', 'ai' );
		SocialQueue::mark_reconciling( (int) $row['id'], 'no_answer', 'No answer came back.' );

		social_mock( array( 'social_reconcile' => 'missing', 'social_mode' => 'ok' ) );
		social_run( 10 );

		$after = SocialQueue::get( (int) $row['id'] );
		t_eq( SocialQueue::PUBLISHED, (string) $after['status'], 'it was sent again and published, because the platform did not have it' );
	}
);

/* ---------- Retries, rate limits and credentials ---------- */

t(
	'S-60',
	'A rate limit waits as the platform asked and uses no attempt',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => array( '429' ) ) );

		$post_id = social_publish_article();
		// One pass only, so the wait is not skipped over.
		Runner::tick( 'cli' );
		Runner::tick( 'cli' );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::SCHEDULED, (string) $row['status'], 'it is waiting' );
		t_eq( 'rate_limited', (string) $row['error_code'], 'with the reason recorded' );
		t_eq( 0, (int) $row['attempts'], 'no attempt was used up by a rate limit' );
		t_true( ! empty( $row['retry_at'] ), 'a time to try again was set' );
	}
);

t(
	'S-61',
	'A temporary platform problem is retried, and gives up after the set number of attempts',
	function () {
		social_connect( Platforms::BLUESKY );
		Settings::update( array( 'social_max_attempts' => 2 ) );
		social_mock( array( 'social_mode' => '500' ) );

		$post_id = social_publish_article();
		social_run( 16 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::FAILED, (string) $row['status'], 'it ends as failed' );
		t_eq( 2, (int) $row['attempts'], 'after exactly the attempts allowed' );
		t_eq( 'platform_error', (string) $row['error_code'], 'with the platform problem recorded' );
	}
);

t(
	'S-62',
	'A refused credential marks the account so the administrator can see it',
	function () {
		$account_id = social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_token_mode' => 'bad_password' ) );

		$post_id = social_publish_article();
		social_run( 10 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::FAILED, (string) $row['status'], 'the post failed' );
		t_eq( 'auth_expired', (string) $row['error_code'], 'because of the credential' );

		$account = SocialAccounts::get( $account_id );
		t_eq( SocialAccounts::EXPIRED, (string) $account['status'], 'and the account is marked as needing reconnecting' );
	}
);

t(
	'S-63',
	'A credential near expiry is queued to be renewed',
	function () {
		$account_id = social_connect( Platforms::THREADS );

		global $wpdb;
		$wpdb->update(
			Db::table( 'social_accounts' ),
			array( 'token_expires_at' => Clock::mysql( Clock::now() + ( 2 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $account_id )
		);

		t_eq( 1, count( SocialAccounts::expiring( 7 ) ), 'the account is seen as expiring' );

		SocialManager::tick();
		t_eq( 1, db_count( 'jobs', "job_type = 'social_refresh_token'" ), 'a renewal job was queued' );
	}
);

t(
	'S-64',
	'A token renewal replaces the saved credential',
	function () {
		$account_id = social_connect( Platforms::THREADS );
		$before     = SocialAccounts::credentials( $account_id )['access_token'];

		$result = SocialManager::adapter( Platforms::THREADS )->refresh( SocialAccounts::get( $account_id ) );
		t_true( $result['ok'], 'the renewal succeeded' );
		t_true( $result['changed'], 'and it changed the credential' );

		$after = SocialAccounts::credentials( $account_id )['access_token'];
		t_false( $before === $after, 'the saved credential is the new one' );
	}
);

/* ---------- Pictures ---------- */

t(
	'S-70',
	'A platform that requires a picture is skipped when there is none it can use',
	function () {
		social_connect( Platforms::INSTAGRAM );
		$post_id = social_publish_article();

		// Take the featured image away, as a missing file would.
		delete_post_thumbnail( $post_id );

		social_run( 10 );

		$row = social_row( $post_id, Platforms::INSTAGRAM );
		t_eq( SocialQueue::SKIPPED, (string) $row['status'], 'Instagram is skipped rather than failed' );
		t_eq( 'image_required', (string) $row['error_code'], 'with the reason recorded' );
		t_eq( 0, count( social_requests( Platforms::INSTAGRAM ) ), 'and nothing was sent' );
	}
);

t(
	'S-71',
	'A post is never published claiming a picture whose upload failed',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'image_fail' ) );

		$post_id = social_publish_article();
		social_run( 6 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_false( SocialQueue::PUBLISHED === (string) $row['status'], 'it was not published' );
		foreach ( social_requests( Platforms::BLUESKY ) as $request ) {
			t_false( '/xrpc/com.atproto.repo.createRecord' === $request['path'], 'no post was created after the picture failed' );
		}
	}
);

t(
	'S-72',
	'A platform that fetches the picture by address refuses a site it cannot reach',
	function () {
		$reason = \ZZZCopy\Social\SocialMedia::site_is_publicly_reachable();
		// The test site runs on localhost, which no platform can reach.
		t_true( '' !== $reason, 'the problem is reported rather than left to fail at the platform' );
		t_contains( 'reach', strtolower( $reason ), 'and it says what the problem is' );
	}
);

/* ---------- Approval, scheduling and editing ---------- */

t(
	'S-80',
	'With approval on, a post waits with its text ready and is not sent',
	function () {
		social_connect( Platforms::BLUESKY );
		Settings::update( array( 'social_approval_mode' => 'manual' ) );

		$post_id = social_publish_article();
		social_run( 10 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::AWAITING, (string) $row['status'], 'it is waiting for approval' );
		t_true( '' !== trim( (string) $row['caption'] ), 'its text is ready to read' );
		t_eq( 0, count( social_requests( Platforms::BLUESKY ) ), 'nothing was sent to the platform' );

		// Approving it sends it.
		social_mock( array( 'social_mode' => 'ok' ) );
		t_true( SocialManager::approve( (int) $row['id'] ), 'it can be approved' );
		social_run( 10 );

		$after = SocialQueue::get( (int) $row['id'] );
		t_eq( SocialQueue::PUBLISHED, (string) $after['status'], 'and then it is published' );
	}
);

t(
	'S-81',
	'A scheduled social post is not published before its time, and can be cancelled',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );

		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		global $wpdb;
		$wpdb->update(
			Db::table( 'social_posts' ),
			array( 'scheduled_for' => Clock::mysql( Clock::now() + 7200 ) ),
			array( 'id' => (int) $row['id'] )
		);

		// Only the job is pulled forward, not the scheduled time.
		$jobs = Db::table( 'jobs' );
		for ( $i = 0; $i < 4; $i++ ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET run_at = %s WHERE status = 'pending'", Clock::mysql( Clock::now() - 1 ) ) );
			Runner::tick( 'cli' );
		}

		$after = SocialQueue::get( (int) $row['id'] );
		t_false( SocialQueue::PUBLISHED === (string) $after['status'], 'it was not published before its time' );

		$cancel = SocialManager::cancel( (int) $row['id'] );
		t_true( $cancel['ok'], 'it can be cancelled before publication' );
		t_eq( SocialQueue::CANCELLED, (string) SocialQueue::get( (int) $row['id'] )['status'], 'and it is recorded as cancelled' );
	}
);

t(
	'S-82',
	'Editing one platform\'s text leaves the other platforms alone',
	function () {
		social_connect( Platforms::BLUESKY );
		social_connect( Platforms::X );
		Settings::update( array( 'social_approval_mode' => 'manual' ) );

		$post_id = social_publish_article();
		social_run( 10 );

		$bluesky = social_row( $post_id, Platforms::BLUESKY );
		$x       = social_row( $post_id, Platforms::X );
		$x_text  = (string) $x['caption'];

		$result = SocialManager::edit_caption( (int) $bluesky['id'], 'A caption written by hand.', 'Council' );
		t_true( $result['ok'], 'the edit was accepted' );

		t_eq( 'A caption written by hand.', (string) SocialQueue::get( (int) $bluesky['id'] )['caption'], 'the Bluesky text changed' );
		t_eq( 'manual', (string) SocialQueue::get( (int) $bluesky['id'] )['caption_source'], 'and is recorded as written by hand' );
		t_eq( $x_text, (string) SocialQueue::get( (int) $x['id'] )['caption'], 'the X text is untouched' );
	}
);

t(
	'S-83',
	'Text longer than the platform allows is refused rather than silently cut',
	function () {
		social_connect( Platforms::BLUESKY );
		$post_id = social_publish_article();
		$row     = social_row( $post_id, Platforms::BLUESKY );

		$result = SocialManager::edit_caption( (int) $row['id'], str_repeat( 'a', 400 ), '' );
		t_false( $result['ok'], 'it is refused' );
		t_contains( '300', (string) $result['message'], 'and the limit is named' );
	}
);

t(
	'S-84',
	'The text sent is within the platform\'s limit, counting the link and the hashtags',
	function () {
		social_connect( Platforms::X );
		social_mock( array( 'social_mode' => 'ok' ) );
		social_clear_requests();

		$post_id = social_publish_article();
		social_run( 12 );

		$sent = '';
		foreach ( social_requests( Platforms::X ) as $request ) {
			if ( '/2/tweets' === $request['path'] ) {
				$sent = (string) ( $request['fields']['text'] ?? '' );
			}
		}
		t_true( '' !== $sent, 'something was sent to X' );
		t_true( mb_strlen( $sent ) <= 280, 'and it is within 280 characters: ' . mb_strlen( $sent ) );
	}
);

/* ---------- Several accounts, and several posts at once ---------- */

t(
	'S-90',
	'With two accounts on one platform and none chosen, nothing is published',
	function () {
		social_connect( Platforms::BLUESKY );
		SocialAccounts::save(
			array(
				'platform'     => Platforms::BLUESKY,
				'external_id'  => 'did:plc:second000000',
				'account_name' => 'Second account',
				'access_token' => 'mock-access-token-second-1234567890',
			)
		);
		SocialSettings::save_platforms( array( Platforms::BLUESKY => array( 'account_id' => 0 ) ) );
		SocialSettings::flush_cache();

		t_eq( null, SocialAccounts::chosen( Platforms::BLUESKY ), 'no account is chosen, and none is guessed at' );
		t_false( SocialAccounts::publishable( Platforms::BLUESKY ), 'so the platform cannot publish' );

		$post_id = social_publish_article();
		t_eq( 0, count( SocialQueue::for_post( $post_id ) ), 'nothing was queued' );
	}
);

t(
	'S-91',
	'Six platforms give six independent jobs for one article',
	function () {
		foreach ( Platforms::ORDER as $platform ) {
			social_connect( $platform );
		}
		social_mock( array( 'social_mode' => 'ok' ) );

		$post_id = social_publish_article();
		t_eq( 6, count( SocialQueue::for_post( $post_id ) ), 'six records, one per platform' );

		social_run( 1 );
		SocialManager::tick();
		t_true( db_count( 'jobs', "job_type = 'social_publish'" ) > 1, 'several independent publishing jobs were queued' );
	}
);

t(
	'S-92',
	'Disconnecting an account deletes its credentials and keeps its published history',
	function () {
		$account_id = social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );
		$post_id = social_publish_article();
		social_run( 12 );

		$row = social_row( $post_id, Platforms::BLUESKY );
		t_eq( SocialQueue::PUBLISHED, (string) $row['status'], 'the post was published' );

		t_true( SocialAccounts::disconnect( $account_id ), 'the account was disconnected' );
		t_eq( 0, db_count( 'social_accounts', 'id = ' . $account_id ), 'the account row and its credentials are gone' );

		$after = SocialQueue::get( (int) $row['id'] );
		t_eq( SocialQueue::PUBLISHED, (string) $after['status'], 'the published history is kept' );
		t_contains( 'at://', (string) $after['external_post_id'], 'with the identifier of what was published' );
		t_false( SocialSettings::platform_enabled( Platforms::BLUESKY ), 'and the platform was switched off' );
	}
);

/* ---------- Authorisation safety ---------- */

t(
	'S-95',
	'An authorisation state is accepted once and only for the platform that issued it',
	function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );

		$state = SocialSecrets::issue_state( Platforms::X, array( 'note' => 'test' ) );
		t_true( strlen( $state ) >= 20, 'a long random value is issued' );

		$first = SocialSecrets::consume_state( $state );
		t_true( is_array( $first ), 'it is accepted once' );
		t_eq( Platforms::X, (string) $first['platform'], 'and it names the platform that issued it' );

		t_eq( null, SocialSecrets::consume_state( $state ), 'the same value is refused a second time' );
		t_eq( null, SocialSecrets::consume_state( 'a-value-this-site-never-issued-0000' ), 'an unknown value is refused' );
	}
);

t(
	'S-96',
	'An application secret is stored encrypted and shown only as its last four characters',
	function () {
		SocialSecrets::set_app( Platforms::X, 'client-123', 'super-secret-value-abcd' );

		$stored = get_option( 'zzzc_social_apps' );
		t_not_contains( 'super-secret-value', (string) wp_json_encode( $stored ), 'the secret is not stored in plain text' );
		t_eq( 'client-123', SocialSecrets::client_id( Platforms::X ), 'the identifier is stored as given' );
		t_eq( 'super-secret-value-abcd', SocialSecrets::client_secret( Platforms::X ), 'the secret round-trips for a server-side call' );
		t_eq( '••••••••abcd', SocialSecrets::masked_secret( Platforms::X ), 'only the last four characters are shown' );

		// Saving with an empty secret keeps the one already saved.
		SocialSecrets::set_app( Platforms::X, 'client-456', '' );
		t_eq( 'super-secret-value-abcd', SocialSecrets::client_secret( Platforms::X ), 'an empty value does not wipe the saved secret' );
		t_eq( 'client-456', SocialSecrets::client_id( Platforms::X ), 'but the identifier was updated' );
	}
);

t(
	'S-97',
	'No credential appears in the activity log',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'ok' ) );
		$post_id = social_publish_article();
		social_run( 12 );

		global $wpdb;
		$text = (string) $wpdb->get_var( 'SELECT GROUP_CONCAT(CONCAT_WS(" ", message, COALESCE(context, ""))) FROM ' . Db::table( 'events' ) );

		t_not_contains( 'mock-access-token', $text, 'no access credential in the log' );
		t_not_contains( 'mock-refresh-token', $text, 'no refresh credential in the log' );
		t_not_contains( 'mock-client-secret', $text, 'no application secret in the log' );
		t_true( false !== strpos( $text, 'Bluesky' ), 'but the platform is named, so the log is still useful' );
	}
);

t(
	'S-98',
	'Every social log entry names its platform, its article and its job',
	function () {
		social_connect( Platforms::BLUESKY );
		social_mock( array( 'social_mode' => 'permission' ) );
		$post_id = social_publish_article();
		social_run( 12 );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Db::table( 'events' ) . " WHERE stage = 'social' AND severity IN ('warning','error')", ARRAY_A );
		t_true( count( $rows ) > 0, 'there is at least one problem recorded' );

		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context'], true ) ?: array();
			t_true( ! empty( $context['platform'] ), 'the entry names its platform' );
			t_true( (int) $row['wp_post_id'] > 0 || ! empty( $context['social_post_id'] ), 'and names its article or its publishing job' );
		}
	}
);
