<?php
use ZZZCopy\Articles;
use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Discovery;
use ZZZCopy\Events;
use ZZZCopy\Extractor;
use ZZZCopy\FeedParser;
use ZZZCopy\Generation;
use ZZZCopy\Images\ImagePipeline;
use ZZZCopy\Jobs;
use ZZZCopy\Lifecycle;
use ZZZCopy\PastHour;
use ZZZCopy\Publisher;
use ZZZCopy\Retrieval;
use ZZZCopy\Schedule;
use ZZZCopy\Schema;
use ZZZCopy\Settings;

const PH_DAY = '2026-11-05';

/**
 * The plugin with Past-Hour Posting on, as it is on a real site, and only Business enabled.
 * Pages state no date unless a test asks, because the mock's real clock differs from the test clock.
 */
function ph_ready( array $settings = array() ): int {
	$category = ready_plugin( array(), 'publish' );
	Settings::update(
		array_merge(
			array(
				'past_hour_enabled'      => 1,
				'past_hour_max_age_minutes' => 60,
				'past_hour_prep_minutes' => 60,
				'daily_ai_limit'         => 500,
			),
			$settings
		)
	);
	ph_feeds( array() );
	Automation::patch( array( 'run_state' => Automation::RUNNING ) );
	return $category;
}

function ph_feeds( array $feeds, array $extra = array() ): void {
	mock_state( array_merge( array( 'feeds' => $feeds, 'page_date' => '' ), $extra ) );
}

function ph_add_category( string $name, string $feed ): int {
	global $wpdb;
	$table = Db::table( 'categories' );
	$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s", $name ) );
	$wpdb->update( $table, array( 'enabled' => 1, 'source_mode' => 'feeds', 'feed_urls' => ZZZT_MOCK . '/' . $feed, 'topic_urls' => '' ), array( 'id' => $id ) );
	return $id;
}

function ph_time( string $time ): int {
	return ( new DateTimeImmutable( PH_DAY . ' ' . $time, new DateTimeZone( '+10:30' ) ) )->getTimestamp();
}

function ph_story( string $slug, string $published, array $extra = array() ): array {
	return array_merge(
		array(
			'title'       => 'Story about ' . str_replace( '-', ' ', $slug ) . ' ' . md5( $slug ),
			'link'        => ZZZT_MOCK . '/article/' . $slug,
			'guid'        => 'guid-' . $slug,
			'pubDate'     => gmdate( 'r', ph_time( $published ) ),
			'description' => 'Summary of ' . $slug,
		),
		$extra
	);
}

function ph_run( string $time, int $ticks = 14 ): void {
	set_local_now( PH_DAY . ' ' . $time );
	run_ticks( $ticks );
}

function ph_slot( string $time = '10:00' ): array {
	global $wpdb;
	return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table( 'slots' ) . ' WHERE slot_key = %s', PH_DAY . 'T' . $time ), ARRAY_A );
}

function ph_article( string $time = '10:00' ): array {
	return (array) Lifecycle::job( (int) ph_slot( $time )['article_job_id'] );
}

function ph_log(): string {
	return implode( "\n", array_reverse( array_column( Events::query( array( 'stage' => 'past_hour' ), 200 ), 'message' ) ) );
}

function ph_ai_calls(): int {
	return count( mock_requests() );
}

/**
 * Adds a story as if a feed had already been read, without running anything.
 */
function ph_item( int $category, string $slug, string $published ): int {
	global $wpdb;
	$url = ZZZT_MOCK . '/article/' . $slug;
	$wpdb->insert(
		Db::table( 'source_items' ),
		array(
			'category_id'   => $category,
			'adapter'       => 'publisher_feed',
			'dedupe_key'    => sha1( $url ),
			'headline'      => 'Story about ' . $slug . ' ' . md5( $slug ),
			'headline_norm' => \ZZZCopy\Dedupe::normalize_headline( 'Story about ' . $slug . ' ' . md5( $slug ) ),
			'publisher'     => 'Mock News',
			'publisher_url' => ZZZT_MOCK,
			'source_url'    => $url,
			'resolved_url'  => $url,
			'url_hash'      => sha1( \ZZZCopy\Dedupe::normalize_url( $url ) ),
			'published_at'  => gmdate( 'Y-m-d H:i:s', ph_time( $published ) ),
			'discovered_at' => Clock::mysql(),
			'status'        => 'DISCOVERED',
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * Runs one waiting job of one type, so a test can stop part way through writing an article.
 */
function ph_step( string $type ): void {
	$job = Jobs::claim( array( $type ), 'test-worker', 300 );
	t_true( $job, "a $type job is waiting" );
	switch ( $type ) {
		case Jobs::RETRIEVE:
			Retrieval::handle( $job );
			break;
		case Jobs::GENERATE:
			Generation::handle_generate( $job );
			break;
		case Jobs::TAGS:
			Generation::handle_tags( $job );
			break;
		case Jobs::VALIDATE:
			Generation::handle_validate( $job );
			break;
	}
	Jobs::complete( $job );
}

function ph_write_article(): void {
	foreach ( array( Jobs::RETRIEVE, Jobs::GENERATE, Jobs::TAGS, Jobs::VALIDATE ) as $type ) {
		ph_step( $type );
	}
}

/* ---- Scenario 1 ---- */

t(
	'PH-1',
	'Scenario 1: Past-Hour Posting is on by default on a fresh install, with a limited preparation time',
	static function () {
		t_eq( 1, Settings::defaults()['past_hour_enabled'] );
		t_eq( 120, Settings::defaults()['past_hour_prep_minutes'] );
		t_eq( 120, Settings::defaults()['past_hour_max_age_minutes'] );

		// A real fresh install, without the test harness turning it off.
		delete_option( 'zzzc_settings' );
		Settings::flush_cache();
		Settings::ensure_defaults();
		t_true( PastHour::enabled(), 'on after a fresh install' );
		t_eq( 120, (int) Settings::get( 'past_hour_prep_minutes' ) );
		t_eq( 120, PastHour::max_age_minutes(), 'the 6-hour gap leaves the 2-hour window' );

		t_true( is_wp_error( Settings::sanitize( 'past_hour_prep_minutes', 14 ) ), 'too short' );
		t_true( is_wp_error( Settings::sanitize( 'past_hour_prep_minutes', 181 ) ), 'longer than the longest story age' );
		t_eq( 180, Settings::sanitize( 'past_hour_prep_minutes', '180' ) );
		t_eq( 30, Settings::sanitize( 'past_hour_prep_minutes', '30' ) );
		t_eq( 0, Settings::sanitize( 'past_hour_enabled', '' ), 'it can be turned off' );
	}
);

/* ---- Scenario 2 ---- */

t(
	'PH-2',
	'Scenario 2: a fresh story is found, written and published at the post time, with its ages recorded',
	static function () {
		$category = ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'fresh-one', '09:10:00' ), ph_story( 'older-one', '09:06:00' ) ) ) );

		ph_run( '09:15:05' );

		$slot    = ph_slot();
		$article = ph_article();
		t_eq( Lifecycle::QUEUED, $article['status'], (string) ( $article['error_message'] ?? '' ) );
		t_eq( (int) $slot['article_job_id'], (int) $article['id'] );
		t_contains( 'fresh-one', (string) Lifecycle::item( (int) $article['source_item_id'] )['source_url'], 'the newest story was chosen' );
		t_eq( PastHour::READY, $slot['prep_status'] );
		t_eq( 305, (int) $slot['age_at_selection'], 'age when selected' );
		t_eq( $category, (int) $slot['selected_category_id'] );
		t_eq( 0, (int) $slot['fallback_used'] );
		t_eq( 1, db_count( 'article_jobs' ), 'only the selected story is written' );
		t_eq( array(), posts_with_job(), 'not before its time' );

		ph_run( '09:59:50' );
		t_eq( array(), posts_with_job(), 'still not before its time' );
		t_eq( 1, db_count( 'article_jobs' ) );
		t_eq( 2, ph_ai_calls(), 'one article and its tags' );

		ph_run( '10:00:20' );
		t_eq( Lifecycle::PUBLISHED, job_status( (int) $article['id'] ) );
		$slot = ph_slot();
		t_eq( Schedule::FILLED, $slot['status'] );
		t_eq( 3020, (int) $slot['age_at_publish'], 'age just before publishing' );
		t_eq( 1, count( posts_with_job() ) );
		t_eq( 'publish', get_post_status( (int) posts_with_job()[0] ) );

		$log = ph_log();
		t_contains( 'Preparation started for the 10:00 post', $log );
		t_contains( 'Search 2 of 4 for the 10:00 post', $log );
		t_contains( 'Sources: Google News 0, publisher feeds 1', $log );
		t_contains( 'Selected for the 10:00 post', $log );
		t_contains( 'Newest publisher feed story, of 2 eligible', $log );
		t_contains( 'Article ready for the 10:00 post', $log );
		t_contains( 'Final freshness check for the 10:00 post: the story is 50 minutes old. It passed.', $log );
	}
);

t(
	'PH-3',
	'A story from before the window is not written, even though it is under 60 minutes old when the search runs',
	static function () {
		ph_ready();
		// 10 minutes old at 09:00, but 70 minutes old at 10:00, so writing it would waste the AI call.
		ph_feeds( array( 'feed' => array( ph_story( 'too-early', '08:50:00' ) ) ) );
		ph_run( '09:00:10' );
		t_eq( 1, db_count( 'source_items', "status = 'DISCOVERED'" ), 'found' );
		t_eq( 0, db_count( 'article_jobs' ), 'not started' );
		t_eq( 0, ph_ai_calls() );

		// Too close to the limit to start, since the worker may run a minute late.
		ph_feeds( array( 'feed' => array( ph_story( 'at-the-edge', '09:02:00' ) ) ) );
		ph_run( '09:15:05' );
		t_eq( 0, db_count( 'article_jobs' ) );

		// Never older as a fallback: the skipped post stays empty.
		ph_run( '10:00:20' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( 0, ph_ai_calls() );
		t_eq( array(), posts_with_job() );
	}
);

/* ---- Scenario 3 ---- */

t(
	'PH-4',
	'Scenario 3: nothing at first, one search every 15 minutes, and a story appearing during a retry is used',
	static function () {
		ph_ready();
		ph_run( '09:00:10' );
		t_eq( 1, db_count( 'jobs', "job_type = 'discover'" ), 'the first search' );
		t_eq( PastHour::SEARCHING, ph_slot()['prep_status'] );

		ph_run( '09:07:00' );
		ph_run( '09:14:59' );
		t_eq( 1, db_count( 'jobs', "job_type = 'discover'" ), 'no extra searches between times' );

		ph_run( '09:15:00' );
		t_eq( 2, db_count( 'jobs', "job_type = 'discover'" ), 'the retry' );
		t_eq( 0, db_count( 'article_jobs' ) );

		ph_feeds( array( 'feed' => array( ph_story( 'late-news', '09:25:00' ) ) ) );
		ph_run( '09:30:00' );
		t_eq( 3, db_count( 'jobs', "job_type = 'discover'" ) );
		t_eq( Lifecycle::QUEUED, ph_article()['status'] );

		ph_run( '09:45:00' );
		t_eq( 3, db_count( 'jobs', "job_type = 'discover'" ), 'no more searches once a story is chosen' );

		ph_run( '10:00:30' );
		t_eq( Schedule::FILLED, ph_slot()['status'] );
	}
);

/* ---- Scenario 4 ---- */

t(
	'PH-5',
	'Scenario 4: other categories are tried only from 09:30, one at a time, and the first with a story ends the search',
	static function () {
		$business = ph_ready();
		$science  = ph_add_category( 'Science', 'feed-science.xml' );
		$sports   = ph_add_category( 'Sports', 'feed-sports.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );

		$feeds = array( 'feed-science.xml' => array( ph_story( 'science-news', '09:12:00' ) ), 'feed-sports.xml' => array( ph_story( 'sports-news', '09:14:00' ) ) );
		ph_feeds( $feeds );

		$searched = static function ( int $category ): int {
			return db_count( 'jobs', "job_type = 'discover' AND unique_key LIKE 'ph:%:{$category}:%'" );
		};

		ph_run( '09:00:10' );
		ph_run( '09:15:00' );
		t_eq( $business, (int) ph_slot()['category_id'] );
		t_eq( 2, $searched( $business ), 'the planned category' );
		t_eq( 0, $searched( $science ) + $searched( $sports ), 'others wait until 09:30' );
		t_eq( 0, db_count( 'article_jobs' ) );

		// In sort order, Science comes before Sports after Business.
		ph_run( '09:30:00' );
		$slot    = ph_slot();
		$article = ph_article();
		t_eq( 3, $searched( $business ), 'the planned category is still searched first' );
		t_eq( 1, db_count( 'article_jobs' ) );
		t_true( (int) $slot['fallback_used'], 'fallback recorded' );
		$first = (int) $slot['selected_category_id'];
		t_true( in_array( $first, array( $science, $sports ), true ) );
		t_eq( 1, $searched( $science ) + $searched( $sports ), 'the search stopped at the first category with a story' );
		t_eq( $first, (int) $article['category_id'] );
		t_contains( 'Category fallback', ph_log() );
		t_contains( 'because the planned category Business had no eligible story', ph_log() );

		ph_run( '10:00:30' );
		t_eq( Lifecycle::PUBLISHED, job_status( (int) $article['id'] ) );
		$terms = get_the_category( (int) posts_with_job()[0] );
		t_eq( Categories::name( $first ), $terms[0]->name, 'the post is filed under the category the story came from' );
	}
);

t(
	'PH-6',
	'The planned category is preferred over a newer story elsewhere, and "use another category" being off is respected',
	static function () {
		$business = ph_ready();
		$science  = ph_add_category( 'Science', 'feed-science.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ), 'category_fallback' => 0 ) );
		ph_feeds( array( 'feed-science.xml' => array( ph_story( 'science-news', '09:12:00' ) ) ) );

		ph_run( '09:30:00' );
		ph_run( '09:45:00' );
		t_eq( 0, db_count( 'jobs', "job_type = 'discover' AND unique_key LIKE 'ph:%:{$science}:%'" ), 'fallback is off' );
		ph_run( '10:00:30' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );

		// With fallback on, both have stories already stored, and the planned one wins at 15:45.
		Settings::update( array( 'category_fallback' => 1 ) );
		ph_item( $science, 'newer-elsewhere', '15:40:00' );
		ph_item( $business, 'older-planned', '15:10:00' );
		global $wpdb;
		$wpdb->update( Db::table( 'slots' ), array( 'category_id' => $business ), array( 'slot_key' => PH_DAY . 'T16:00' ) );
		set_local_now( PH_DAY . ' 15:45:00' );
		PastHour::tick();
		t_eq( $business, (int) ph_article( '16:00' )['category_id'] );
		t_eq( 0, (int) ph_slot( '16:00' )['fallback_used'] );
	}
);

/* ---- Scenario 5 ---- */

t(
	'PH-7',
	'Scenario 5: no category has news, so the post is skipped with the exact reason and nothing is written',
	static function () {
		$business = ph_ready();
		ph_add_category( 'Science', 'feed-science.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		// Old news is present, and must never be used.
		ph_feeds( array( 'feed' => array( ph_story( 'old-news', '07:00:00' ) ), 'feed-science.xml' => array( ph_story( 'older-news', '06:00:00' ) ) ) );

		foreach ( array( '09:00:10', '09:15:00', '09:30:00', '09:45:00' ) as $time ) {
			ph_run( $time );
		}
		t_eq( 0, db_count( 'article_jobs' ) );
		t_contains( 'older than the 60-minute limit', (string) ph_item_reason() );

		ph_run( '10:00:20' );
		$slot = ph_slot();
		t_eq( Schedule::SKIPPED, $slot['status'] );
		t_contains( 'No usable story published since 09:05 was found in 2 categories', $slot['status_reason'] );
		t_contains( 'Skipped the 10:00 post: No usable story published since 09:05 was found in 2 categories', ph_log() );
		t_eq( 0, ph_ai_calls(), 'no article was written' );
		t_eq( array(), posts_with_job() );

		$events = Events::query( array( 'status' => Schedule::SKIPPED ), 5 );
		t_eq( 1, count( $events ) );
		t_eq( 'warning', $events[0]['severity'] );
	}
);

function ph_item_reason(): string {
	global $wpdb;
	return (string) $wpdb->get_var( 'SELECT status_reason FROM ' . Db::table( 'source_items' ) . " WHERE status = 'FILTERED_OUT' LIMIT 1" );
}

/* ---- Scenario 6 ---- */

t(
	'PH-8',
	'Scenario 6: a story that goes over 60 minutes during writing is dropped, and no more is spent on it',
	static function () {
		$category = ph_ready();
		ph_item( $category, 'slow-article', '09:06:00' );
		set_local_now( PH_DAY . ' 09:50:00' );
		Schedule::ensure_slots();
		PastHour::tick();
		$id = (int) ph_slot()['article_job_id'];
		t_true( $id, 'selected' );

		ph_step( Jobs::RETRIEVE );
		ph_step( Jobs::GENERATE );
		t_eq( 1, ph_ai_calls() );
		t_eq( Lifecycle::GENERATED, job_status( $id ) );

		// The writing ran long, and the worker comes back at 10:07, when the story is 61 minutes old.
		set_local_now( PH_DAY . ' 10:07:00' );
		ph_step( Jobs::TAGS );
		t_eq( Lifecycle::SKIPPED, job_status( $id ) );
		t_eq( 1, ph_ai_calls(), 'no tags were paid for' );
		t_contains( '61 minutes old', (string) Lifecycle::job( $id )['error_message'] );

		// The queued story went stale, so nothing is left for this post.
		Publisher::tick();
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( array(), posts_with_job() );
		t_eq( 0, db_count( 'jobs', "job_type = 'validate'" ), 'no later step ran' );
	}
);

t(
	'PH-9',
	'While there is time, a story that stops qualifying is replaced by another one that does',
	static function () {
		$category = ph_ready();
		$first    = ph_item( $category, 're-dated-story', '09:35:00' );
		$second   = ph_item( $category, 'replacement', '09:20:00' );
		set_local_now( PH_DAY . ' 09:40:00' );
		Schedule::ensure_slots();
		PastHour::tick();
		$id = (int) ph_slot()['article_job_id'];
		t_eq( $first, (int) Lifecycle::job( $id )['source_item_id'] );
		ph_step( Jobs::RETRIEVE );

		// The source's date turns out to be much older.
		global $wpdb;
		$wpdb->update( Db::table( 'source_items' ), array( 'published_at' => gmdate( 'Y-m-d H:i:s', ph_time( '08:30:00' ) ) ), array( 'id' => $first ) );
		set_local_now( PH_DAY . ' 09:41:00' );
		PastHour::tick();

		t_eq( Lifecycle::SKIPPED, job_status( $id ) );
		$slot = ph_slot();
		t_eq( 2, (int) $slot['stories_tried'] );
		t_eq( $second, (int) $slot['selected_item_id'], 'the replacement' );
		t_eq( $second, (int) Lifecycle::job( (int) $slot['article_job_id'] )['source_item_id'] );
		t_eq( 'FILTERED_OUT', Lifecycle::item( $first )['status'] );
		t_contains( 'Story no longer used', ph_log() );
	}
);

t(
	'PH-10',
	'An article page dated older than its feed entry is rejected before any AI call, and a newer or unstated page date changes nothing',
	static function () {
		$category = ph_ready();
		ph_item( $category, 'bumped-story', '09:30:00' );
		$real = ph_item( $category, 'honest-story', '09:20:00' );
		ph_feeds( array(), array( 'page_dates' => array( 'bumped-story' => gmdate( 'c', ph_time( '06:00:00' ) ), 'honest-story' => gmdate( 'c', ph_time( '09:55:00' ) ) ) ) );

		set_local_now( PH_DAY . ' 09:35:00' );
		Schedule::ensure_slots();
		PastHour::tick();
		$id = (int) ph_slot()['article_job_id'];
		ph_step( Jobs::RETRIEVE );
		t_eq( Lifecycle::SKIPPED, job_status( $id ), 'the page shows the feed re-dated an old story' );
		t_eq( 0, ph_ai_calls() );

		PastHour::tick();
		$next = (int) ph_slot()['article_job_id'];
		t_eq( $real, (int) Lifecycle::job( $next )['source_item_id'] );
		ph_step( Jobs::RETRIEVE );
		t_eq( Lifecycle::AI_PENDING, job_status( $next ), 'a later page date is ignored' );
		t_eq( gmdate( 'Y-m-d H:i:s', ph_time( '09:20:00' ) ), Lifecycle::item( $real )['published_at'] );

		// A <time> tag can belong to anything on the page, so only a date stated for the article counts.
		$html = '<html><head></head><body><time datetime="2020-01-01T00:00:00Z">Old</time><article><p>Text</p></article></body></html>';
		$read = Extractor::extract( $html );
		t_true( $read['published'] > 0, 'the page date for other uses' );
		t_eq( 0, $read['published_stated'], 'not treated as the date of this article' );
	}
);

/* ---- Scenario 7 ---- */

t(
	'PH-11',
	'Scenario 7: a ready article is not published when the story is over 60 minutes old at publishing',
	static function () {
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'ready-story', '09:06:00' ) ) ) );
		ph_run( '09:15:00' );
		$id = (int) ph_slot()['article_job_id'];
		t_eq( Lifecycle::QUEUED, job_status( $id ) );
		$paid = ph_ai_calls();

		// The worker was down until 10:07, inside the grace period but 61 minutes after the story.
		ph_run( '10:07:00' );
		t_eq( Lifecycle::SKIPPED, job_status( $id ) );
		$slot = ph_slot();
		t_eq( Schedule::SKIPPED, $slot['status'] );
		t_contains( '61 minutes old', $slot['status_reason'] );
		t_eq( 3660, (int) $slot['age_at_publish'] );
		t_eq( array(), posts_with_job(), 'nothing went out' );
		t_eq( $paid, ph_ai_calls(), 'and no new article was written' );
		t_eq( Lifecycle::SKIPPED, queue_row( $id )['queue_status'] );
	}
);

t(
	'PH-12',
	'The final check runs after the image step: time lost finding an image stops the post, and the image is given back',
	static function () {
		$category = ph_ready();
		ph_item( $category, 'slow-image', '09:06:00' );
		set_local_now( PH_DAY . ' 09:50:00' );
		Schedule::ensure_slots();
		PastHour::tick();
		$id = (int) ph_slot()['article_job_id'];
		ph_write_article();
		t_eq( Lifecycle::QUEUED, job_status( $id ) );
		mock_state( array( 'page_date' => '', 'commons_pages' => array( cpage( 8101, 'Harbour bridge view' ) ) ) );

		// The image was not found during preparation. At 10:00:30 the story is fine, and the search takes 10 minutes.
		$slow = static function ( $args ) {
			$GLOBALS['zzzt_now'] = ph_time( '10:10:30' );
			return $args;
		};
		add_filter( 'http_request_args', $slow );
		set_local_now( PH_DAY . ' 10:00:30' );
		Publisher::tick();
		remove_filter( 'http_request_args', $slow );

		t_eq( Lifecycle::SKIPPED, job_status( $id ), (string) Lifecycle::job( $id )['error_message'] );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( array(), posts_with_job(), 'no post, not even a draft' );
		t_contains( 'It failed.', ph_log() );
		$rows = image_rows();
		t_eq( 'released', $rows[0]['status'], 'the image can be used by another post' );
		t_eq( 0, (int) $rows[0]['attachment_id'] );
		t_eq( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_zzzc_image_source', 'fields' => 'ids' ) ), 'no leftover file' );
	}
);

/* ---- Scenario 8 ---- */

t(
	'PH-13',
	'Scenario 8: a skipped post stays skipped, its story is not used later, and the next post is unaffected',
	static function () {
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'stale-later', '09:06:00' ) ) ) );
		ph_run( '09:15:00' );
		$old = (int) ph_slot()['article_job_id'];
		ph_run( '10:07:00' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );

		t_contains( 'cannot be retried', (string) Articles::retry( $old ), 'an administrator retry cannot bring it back' );

		// Later ticks with fresh news do nothing for the closed post.
		ph_feeds( array( 'feed' => array( ph_story( 'stale-later', '09:06:00' ), ph_story( 'after-the-time', '10:20:00' ) ) ) );
		foreach ( array( '10:25:00', '10:45:00', '12:00:00' ) as $time ) {
			ph_run( $time );
		}
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( array(), posts_with_job() );
		t_eq( 1, db_count( 'article_jobs' ), 'nothing written for it' );

		// The 10:20 story is too old for the 16:00 post too, so only news from then on counts.
		ph_feeds( array( 'feed' => array( ph_story( 'after-the-time', '10:20:00' ), ph_story( 'afternoon-news', '15:20:00' ) ) ) );
		ph_run( '15:30:00' );
		ph_run( '16:00:30' );
		t_eq( Schedule::FILLED, ph_slot( '16:00' )['status'] );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( 1, count( posts_with_job() ) );
		t_contains( 'afternoon-news', (string) Lifecycle::item( (int) ph_article( '16:00' )['source_item_id'] )['source_url'] );

		// A post held over by the earlier workflow is not caught up either.
		global $wpdb;
		$wpdb->update( Db::table( 'slots' ), array( 'status' => Schedule::DEFERRED ), array( 'slot_key' => PH_DAY . 'T22:00' ) );
		ph_run( '22:01:00' );
		t_eq( Schedule::SKIPPED, ph_slot( '22:00' )['status'] );
		t_contains( 'never catches up', ph_slot( '22:00' )['status_reason'] );
		t_eq( 1, count( posts_with_job() ) );
	}
);

t(
	'PH-14',
	'Articles queued before Past-Hour Posting was turned on are not published, and preparation that never ran skips the post',
	static function () {
		$category = ready_plugin( array(), 'publish' );
		set_local_now( PH_DAY . ' 08:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		mock_state( array( 'page_date' => '' ) );
		$old = queued_article( $category, 'queued-before' );

		Settings::update( array( 'past_hour_enabled' => 1 ) );
		// The worker first comes back after the post time, so nothing was prepared.
		ph_run( '10:01:00' );
		t_eq( Lifecycle::QUEUED, job_status( $old ), 'left in the queue' );
		t_eq( array(), posts_with_job() );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_contains( 'Preparation did not run', ph_slot()['status_reason'] );
	}
);

/* ---- Scenario 9 ---- */

t(
	'PH-15',
	'Scenario 9: repeated searches and workers cause one article per post, one post per article, and no shared story',
	static function () {
		ph_ready( array( 'daily_target' => 3, 'interval_hours' => 1, 'daily_cap' => 3 ) );
		ph_feeds( array( 'feed' => array( ph_story( 'only-story', '09:10:00' ) ) ) );

		foreach ( array( '09:15:00', '09:16:00', '09:30:00', '09:45:00', '09:50:00', '09:59:00' ) as $time ) {
			ph_run( $time );
		}
		t_eq( 1, db_count( 'article_jobs' ), 'one article' );
		t_eq( 2, ph_ai_calls(), 'one article and its tags' );
		t_eq( 1, count( array_filter( array_column( Events::query( array( 'stage' => 'past_hour' ), 200 ), 'message' ), static fn( $m ) => false !== strpos( $m, 'Selected for' ) ) ) );

		// Both publishing paths at once at 10:00.
		set_local_now( PH_DAY . ' 10:00:30' );
		Publisher::tick();
		Publisher::tick();
		run_ticks( 3 );
		t_eq( 1, count( posts_with_job() ), 'one post' );

		// The 11:00 post prepares at the same time as the worker sees the same feed. Its story is already used.
		ph_run( '10:15:00' );
		ph_run( '10:45:00' );
		t_eq( 1, db_count( 'article_jobs' ) );
		ph_run( '11:00:30' );
		t_eq( Schedule::SKIPPED, ph_slot( '11:00' )['status'], 'too old for the next post anyway' );
		t_eq( 1, count( posts_with_job() ) );

		// A second story for the 12:00 post, and a re-run of the same fetch adds nothing.
		ph_feeds( array( 'feed' => array( ph_story( 'next-story', '11:20:00' ) ) ) );
		ph_run( '11:30:00' );
		ph_run( '11:31:00' );
		t_eq( 2, db_count( 'article_jobs' ) );
		$jobs = db_count( 'jobs', "job_type = 'retrieve'" );
		t_eq( 2, $jobs );
		ph_run( '12:00:30' );
		t_eq( 2, count( posts_with_job() ) );
	}
);

t(
	'PH-16',
	'Failed stories are replaced without AI spending, and each post writes at most 2 articles',
	static function () {
		$category = ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'blocked-page', '09:12:00', array( 'link' => ZZZT_MOCK . '/blocked' ) ), ph_story( 'good-page', '09:10:00' ) ) ) );
		ph_run( '09:15:00' );
		$slot = ph_slot();
		t_eq( 2, (int) $slot['stories_tried'], 'the refused page, then the next newest' );
		t_contains( 'good-page', (string) Lifecycle::item( (int) $slot['selected_item_id'] )['source_url'] );
		t_eq( Lifecycle::QUEUED, ph_article()['status'] );
		t_eq( 2, ph_ai_calls(), 'the refused page cost nothing' );
		t_contains( 'The chosen story could not be used (BLOCKED)', ph_log() );
		t_eq( 0, db_count( 'article_jobs', "status = 'BLOCKED'" ), 'closed, since it cannot be retried while this is on' );
		t_eq( 1, db_count( 'article_jobs', "error_code = 'http_403'" ), 'its reason is kept' );

		// Two written articles already failed for the 16:00 post, so a third is not started.
		global $wpdb;
		$slot_id = (int) ph_slot( '16:00' )['id'];
		foreach ( array( 'written-a', 'written-b' ) as $slug ) {
			$job = make_job_from_url( $category, ZZZT_MOCK . '/article/' . $slug );
			$wpdb->update( Db::table( 'article_jobs' ), array( 'slot_id' => $slot_id, 'ai_attempts' => 1, 'status' => Lifecycle::HELD ), array( 'id' => $job ) );
		}
		ph_item( $category, 'third-story', '15:20:00' );
		set_local_now( PH_DAY . ' 15:30:00' );
		PastHour::tick();
		t_eq( 0, (int) ph_slot( '16:00' )['article_job_id'] );
		t_contains( 'The 2 articles written for this post could not be used', ph_slot( '16:00' )['status_reason'] );
	}
);

t(
	'PH-17',
	'No article is started while publishing is paused, AI is blocked, or the daily limits are reached, and the reason is recorded',
	static function () {
		$category = ph_ready();
		ph_item( $category, 'wanted-story', '09:10:00' );
		set_local_now( PH_DAY . ' 09:15:00' );
		Schedule::ensure_slots();

		Automation::patch( array( 'paused_publishing' => 1 ) );
		PastHour::tick();
		t_eq( 0, db_count( 'article_jobs' ) );
		t_contains( 'Publishing is paused', ph_slot()['status_reason'] );
		Automation::patch( array( 'paused_publishing' => 0, 'ai_block_code' => 'no_credits', 'ai_block_message' => 'No credits.' ) );
		PastHour::tick();
		t_eq( 0, db_count( 'article_jobs' ) );
		t_contains( 'No credits', ph_slot()['status_reason'] );
		Automation::patch( array( 'ai_block_code' => '', 'ai_block_message' => '' ) );

		Settings::update( array( 'daily_cap' => 1 ) );
		global $wpdb;
		$wpdb->insert( Db::table( 'post_links' ), array( 'article_job_id' => 999, 'job_uid' => 'x', 'wp_post_id' => 999, 'post_status' => 'publish', 'local_day' => PH_DAY, 'category_id' => $category, 'created_at' => Clock::mysql() ) );
		PastHour::tick();
		t_eq( 0, db_count( 'article_jobs' ) );
		t_contains( 'daily maximum', ph_slot()['status_reason'] );

		ph_run( '10:00:30' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_contains( 'daily maximum', ph_slot()['status_reason'] );
		t_eq( 0, ph_ai_calls() );
	}
);

/* ---- Scenario 10 ---- */

t(
	'PH-18',
	'Scenario 10: turned off, the earlier workflow runs: hourly research, older stories, the buffer and the queue',
	static function () {
		$category = ph_ready();
		Settings::update( array( 'past_hour_enabled' => 0 ) );
		ph_feeds( array( 'feed' => array( ph_story( 'five-hours-old', '02:00:00' ) ) ) );

		$query = Discovery::search_url( 'x', Categories::get( $category ) );
		t_contains( 'when%3A1d', $query['url'] );
		t_contains( '1d', $query['filter'] );

		ph_run( '07:00:00' );
		t_eq( 0, db_count( 'jobs', "unique_key LIKE 'ph:%'" ), 'none of the new searches' );
		t_true( db_count( 'jobs', "unique_key LIKE 'discover:%'" ) > 0, 'the hourly research' );
		$id = (int) Lifecycle::job( 1 )['id'];
		t_eq( Lifecycle::QUEUED, job_status( $id ), 'the buffer filled ahead of time' );
		t_eq( 0, (int) Lifecycle::job( $id )['slot_id'] );

		ph_run( '10:00:30' );
		t_eq( Lifecycle::PUBLISHED, job_status( $id ), 'an eight-hour-old story still publishes' );
		t_eq( Schedule::FILLED, ph_slot()['status'] );
		t_eq( '', ph_slot()['prep_status'] );
		t_contains( 'Off.', ph_dashboard() );
	}
);

t(
	'PH-19',
	'Turned off part way through, an article already prepared simply joins the earlier workflow, and retries work again',
	static function () {
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'in-progress', '09:10:00' ) ) ) );
		ph_run( '09:15:00' );
		$id = (int) ph_slot()['article_job_id'];
		t_eq( Lifecycle::QUEUED, job_status( $id ) );

		Settings::update( array( 'past_hour_enabled' => 0 ) );
		ph_run( '10:20:00' );
		t_eq( Lifecycle::PUBLISHED, job_status( $id ), 'published by the earlier rules, with no 60-minute limit, at 70 minutes old' );
		t_eq( 1, count( posts_with_job() ) );

		Settings::update( array( 'past_hour_enabled' => 1 ) );
		t_contains( 'cannot be retried', (string) Articles::retry( $id ) );
		Settings::update( array( 'past_hour_enabled' => 0 ) );
		t_contains( 'cannot be retried', (string) Articles::retry( $id ), 'the normal message for a published article' );
	}
);

/* ---- Dates and sources ---- */

t(
	'PH-20',
	'All sources follow the same rules: real publication dates only, and Google asked for the last hour',
	static function () {
		$category = ph_ready();
		$row      = Categories::get( $category );
		$row['recency'] = '7d';
		$query = Discovery::search_url( 'x', $row );
		t_contains( 'when%3A1h', $query['url'], 'a category set to 7 days still asks for the last hour' );

		set_local_now( PH_DAY . ' 09:30:00' );
		$check = static function ( array $normal ) use ( $row ) {
			return Discovery::filter_reason( $row, array_merge( array( 'headline' => 'A', 'snippet' => 'B', 'date_reliable' => true ), $normal ) );
		};
		t_eq( null, $check( array( 'published' => ph_time( '09:00:00' ) ) ), 'exactly 30 minutes' );
		t_eq( null, $check( array( 'published' => ph_time( '08:30:00' ) ) ), 'exactly 60 minutes' );
		t_contains( 'older than the 60-minute limit', (string) $check( array( 'published' => ph_time( '08:29:59' ) ) ) );
		t_contains( 'no publication time', (string) $check( array( 'published' => 0 ) ), 'no date at all' );
		t_eq( null, $check( array( 'published' => ph_time( '09:34:00' ) ) ), 'a clock a few minutes ahead' );
		t_contains( 'in the future', (string) $check( array( 'published' => ph_time( '09:40:00' ) ) ) );
		t_contains( 'only an updated time', (string) $check( array( 'published' => ph_time( '09:20:00' ), 'date_reliable' => false ) ) );

		$atom = FeedParser::parse( '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Only updated</title><id>1</id><link href="http://x.test/1"/><updated>2026-11-04T23:00:00Z</updated></entry><entry><title>Published</title><id>2</id><link href="http://x.test/2"/><published>2026-11-04T23:00:00Z</published><updated>2026-11-04T23:05:00Z</updated></entry></feed>' );
		t_false( $atom['items'][0]['date_reliable'], 'Atom with only an updated date' );
		t_true( $atom['items'][1]['date_reliable'], 'Atom with a published date' );
		t_eq( strtotime( '2026-11-04T23:00:00Z' ), $atom['items'][1]['published'], 'and it uses the published date' );
	}
);

t(
	'PH-21',
	'Preparation time can be changed, the plugin timezone sets the clock, and a short preparation can use other categories at once',
	static function () {
		$business = ph_ready( array( 'past_hour_prep_minutes' => 30 ) );
		ph_add_category( 'Science', 'feed-science.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		ph_feeds( array( 'feed-science.xml' => array( ph_story( 'science-news', '09:20:00' ) ) ) );

		ph_run( '09:29:59' );
		t_eq( 0, db_count( 'jobs', "job_type = 'discover'" ), 'not yet' );
		t_true( empty( ph_slot()['prep_started_at'] ) );
		ph_run( '09:30:00' );
		t_false( empty( ph_slot()['prep_started_at'] ) );
		t_eq( 1, db_count( 'article_jobs' ), 'the story was found on the first try' );
		t_eq( gmdate( 'Y-m-d H:i:s', ph_time( '09:30:00' ) ), gmdate( 'Y-m-d H:i:s', PastHour::prep_start( ph_slot() ) ) );

		// Another timezone moves the post times, and the rule stays 60 minutes before each.
		zzzt_reset();
		ph_ready( array( 'timezone' => 'UTC' ) );
		$GLOBALS['zzzt_now'] = strtotime( PH_DAY . ' 09:00:10 UTC' );
		run_ticks( 3 );
		global $wpdb;
		$slot = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'slots' ) . " WHERE prep_started_at IS NOT NULL", ARRAY_A );
		t_eq( PH_DAY . ' 10:00:00', $slot['due_at'], '10:00 UTC' );
		t_eq( strtotime( PH_DAY . ' 09:00:00 UTC' ), PastHour::prep_start( $slot ) );
	}
);

t(
	'PH-22',
	'The image is found during preparation and reused at publishing, and while Wikimedia is down the post waits, then is skipped, never going out without an image',
	static function () {
		ph_ready();
		$pages = array( cpage( 8201, 'Harbour bridge view' ) );
		ph_feeds( array( 'feed' => array( ph_story( 'with-image', '09:10:00' ) ) ), array( 'commons_pages' => $pages ) );
		ph_run( '09:15:00' );
		ph_run( '09:16:00' );
		t_eq( PastHour::READY, ph_slot()['prep_status'] );
		$searches = count( mock_requests( 'commons/api' ) );
		t_true( $searches > 0, 'searched before the post time' );
		$id = (int) ph_slot()['article_job_id'];
		t_eq( 'ready', ImagePipeline::get_run( $id )['status'] );

		ph_run( '10:00:20' );
		t_eq( Lifecycle::PUBLISHED, job_status( $id ) );
		t_true( has_post_thumbnail( (int) posts_with_job()[0] ), 'it has its image' );
		t_eq( $searches, count( mock_requests( 'commons/api' ) ), 'and did not search again' );

		// Wikimedia is down for the whole preparation of another post.
		zzzt_reset();
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'no-image-today', '09:10:00' ) ) ), array( 'commons_mode' => '500', 'commons_pages' => $pages ) );
		ph_run( '09:15:00' );
		$next = (int) ph_slot()['article_job_id'];
		t_eq( Lifecycle::QUEUED, job_status( $next ) );
		t_false( PastHour::READY === ph_slot()['prep_status'], 'still waiting on the image' );
		t_true( (bool) ph_slot()['image_retry_at'], 'and it knows when to try again' );

		ph_run( '10:00:20' );
		t_eq( 0, count( posts_with_job() ), 'it does not go out without an image' );
		ph_run( '10:31:00' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'], 'and the post is skipped once its time has passed' );
		t_eq( 0, count( posts_with_job() ) );
	}
);

t(
	'PH-23',
	'With an image required and none found, the post is skipped, and failed content is never published',
	static function () {
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'needs-image', '09:10:00' ) ) ), array( 'commons_pages' => array() ) );
		ph_run( '09:15:00' );
		ph_run( '10:00:20' );
		ph_run( '10:01:00' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_contains( 'No usable image', ph_slot()['status_reason'] );
		t_eq( array(), posts_with_job() );
	}
);

/* ---- Screens, Logs and upgrades ---- */

/**
 * Only the Past-Hour Posting card of the Dashboard.
 */
function ph_card( string $html ): string {
	$start = (int) strpos( $html, '<h3>Past-Hour Posting</h3>' );
	return substr( $html, $start, (int) strpos( $html, '</section>', $start ) - $start );
}

function ph_dashboard(): string {
	wp_set_current_user( $GLOBALS['zzzt_admin'] );
	ob_start();
	ZZZCopy\Admin\Pages::dashboard_live();
	$html = (string) ob_get_clean();
	wp_set_current_user( 0 );
	return $html;
}

t(
	'PH-24',
	'The Dashboard shows the status, times, story, ages, category, fallback, processing and result of each post',
	static function () {
		$business = ph_ready();
		$science  = ph_add_category( 'Science', 'feed-science.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		set_local_now( PH_DAY . ' 09:00:10' );
		Schedule::ensure_slots();

		$html = ph_dashboard();
		t_contains( 'Past-Hour Posting', $html );
		t_contains( '<strong>On.</strong>', $html );
		foreach ( array( 'Post time', 'Preparation starts', 'Headline', 'Source', 'Published', 'Age when selected', 'Age before publishing', 'Category', 'Fallback used', 'Processing', 'Result' ) as $column ) {
			t_contains( '<th>' . $column . '</th>', $html );
		}
		t_contains( '2026-11-05 10:00', $html );
		t_contains( '2026-11-05 09:00', $html, 'preparation start' );
		t_contains( 'Waiting for preparation to start', $html );

		ph_feeds( array( 'feed-science.xml' => array( ph_story( 'science-news', '09:12:00', array( 'title' => 'Science story for the Dashboard' ) ) ) ) );
		ph_run( '09:30:00' );
		$html = ph_dashboard();
		t_contains( 'Science story for the Dashboard', $html );
		t_contains( '2026-11-05 09:12', $html, 'publication time' );
		t_contains( '18 min', $html, 'age when selected' );
		t_contains( 'Science', $html );
		t_contains( 'Yes, planned Business', $html );
		t_contains( 'Article ready, waiting for the post time', $html );

		ph_run( '10:00:30' );
		$html = ph_dashboard();
		t_contains( '48 min', $html, 'age before publishing' );
		t_contains( '<td>Published</td>', $html );

		// A skipped post shows why. The planned category for 16:00 is whatever rotation gave it.
		ph_feeds( array() );
		ph_run( '15:45:00' );
		ph_run( '16:00:30' );
		$html = ph_dashboard();
		t_contains( 'Skipped: No usable story', $html );
		t_eq( $science, (int) ph_slot()['selected_category_id'] );
	}
);

t(
	'PH-25',
	'The Schedule page has both settings and they save, and the Logs filter has the new stage',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ph_ready();
		ob_start();
		ZZZCopy\Admin\Pages::schedule();
		$page = (string) ob_get_clean();
		t_contains( 'name="past_hour_enabled"', $page );
		t_contains( 'name="past_hour_prep_minutes"', $page );
		t_contains( 'Use Past-Hour Posting', $page );

		ob_start();
		ZZZCopy\Admin\Pages::logs();
		$logs = (string) ob_get_clean();
		t_contains( 'past_hour', $logs );
		wp_set_current_user( 0 );

		t_eq( array(), Settings::update( array( 'past_hour_enabled' => 0, 'past_hour_prep_minutes' => 45 ) )['errors'] );
		t_false( PastHour::enabled() );
		t_eq( 45, (int) Settings::get( 'past_hour_prep_minutes' ) );
		t_true( isset( Settings::update( array( 'past_hour_prep_minutes' => 181 ) )['errors']['past_hour_prep_minutes'] ), 'a bad value is refused' );
		t_eq( 45, (int) Settings::get( 'past_hour_prep_minutes' ) );

		// A schedule change does not remove a post already being prepared.
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'keep-this', '09:10:00' ) ) ) );
		ph_run( '09:15:00' );
		$before = ph_slot();
		t_true( (int) $before['article_job_id'] );
		Schedule::rebuild_future();
		t_eq( (int) $before['id'], (int) ph_slot()['id'], 'the same post' );
		t_eq( (int) $before['article_job_id'], (int) ph_slot()['article_job_id'] );
	}
);

t(
	'PH-26',
	'Upgrading adds the new columns, turns the feature on, keeps existing settings, and records it',
	static function () {
		global $wpdb;
		$slots = Db::table( 'slots' );
		$jobs  = Db::table( 'article_jobs' );
		$wpdb->query( "ALTER TABLE {$slots} DROP COLUMN prep_status, DROP COLUMN age_at_publish" );
		$wpdb->query( "ALTER TABLE {$jobs} DROP COLUMN slot_id" );

		// A site on the last version, with its own saved settings and no value for the new ones.
		$saved = get_option( 'zzzc_settings' );
		unset( $saved['past_hour_enabled'], $saved['past_hour_prep_minutes'] );
		$saved['daily_cap'] = 7;
		update_option( 'zzzc_settings', $saved, false );
		Settings::flush_cache();
		update_option( 'zzzc_db_version', '5', true );

		Schema::maybe_upgrade();

		t_eq( Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		t_true( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$slots} LIKE 'prep_status'" ) );
		t_true( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$slots} LIKE 'age_at_publish'" ) );
		t_true( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$jobs} LIKE 'slot_id'" ) );
		t_true( PastHour::enabled(), 'on after an upgrade' );
		t_eq( 7, (int) Settings::get( 'daily_cap' ), 'existing settings are kept' );
		t_contains( 'Past-Hour Posting is now on', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );

		Settings::update( array( 'past_hour_enabled' => 0 ) );
	}
);

t(
	'PH-27',
	'Unused stories do not pile up forever, and stale searches for a closed post are dropped',
	static function () {
		$category = ph_ready();
		ph_item( $category, 'never-used', '09:00:00' );
		set_local_now( PH_DAY . ' 09:00:00' );
		global $wpdb;
		$wpdb->update( Db::table( 'source_items' ), array( 'discovered_at' => gmdate( 'Y-m-d H:i:s', ph_time( '09:00:00' ) - 40 * DAY_IN_SECONDS ) ), array( 'status' => 'DISCOVERED' ) );
		ZZZCopy\Maintenance::daily();
		t_eq( 0, db_count( 'source_items' ) );

		// A fetch held up past the post time is not made.
		ph_run( '09:30:00' );
		$before = db_count( 'source_items' );
		ph_feeds( array( 'feed' => array( ph_story( 'late-fetch', '09:40:00' ) ) ) );
		Schedule::ensure_slots();
		$job = array(
			'attempts' => 1,
			'payload'  => array(
				'category_id' => $category,
				'adapter'     => Discovery::FEED,
				'url'         => ZZZT_MOCK . '/feed.xml',
				'context'     => 'Publisher feed',
				'filter'      => 'x',
				'slot_id'     => (int) ph_slot()['id'],
			),
		);
		set_local_now( PH_DAY . ' 10:00:05' );
		Discovery::handle( $job );
		t_eq( $before, db_count( 'source_items' ), 'nothing was fetched' );
	}
);

t(
	'PH-28',
	'Categories with a Google topic also have a search covering the story age, and an upgrade adds it without touching what the owner changed',
	static function () {
		$names  = array( 'Technology', 'Entertainment', 'Business', 'Science', 'Sports', 'Health & Wellness' );
		$by     = static function ( string $name ): array {
			foreach ( Categories::all() as $row ) {
				if ( $name === $row['name'] ) {
					return $row;
				}
			}
			return array();
		};
		$search = static function ( array $category ): array {
			return array_values(
				array_filter(
					Discovery::fetch_plan( $category ),
					static function ( $fetch ) {
						return 0 === strpos( $fetch['context'], 'Google News search' );
					}
				)
			);
		};

		Settings::update( array( 'past_hour_enabled' => 1 ) );
		foreach ( $names as $name ) {
			$found = $search( $by( $name ) );
			t_eq( 1, count( $found ), $name . ' has a search' );
			t_contains( 'when%3A2h', $found[0]['url'], $name . ' asks for the last 2 hours, the default story age' );
		}

		// Turned off, the search follows the category's own recency setting.
		Settings::update( array( 'past_hour_enabled' => 0 ) );
		t_contains( '1d', $search( $by( 'Business' ) )[0]['url'] );

		// A site on the earlier version, where the owner has changed two of the six.
		foreach ( $names as $name ) {
			Categories::update( (int) $by( $name )['id'], array( 'search_queries' => '' ) );
		}
		Categories::update( (int) $by( 'Sports' )['id'], array( 'search_queries' => 'cricket' ) );
		Categories::update( (int) $by( 'Science' )['id'], array( 'topic_urls' => 'https://news.google.com/topics/mine' ) );
		update_option( 'zzzc_db_version', '6', true );

		Schema::maybe_upgrade();

		foreach ( array( 'Technology' => 'technology news', 'Entertainment' => 'entertainment news', 'Business' => 'business news', 'Health & Wellness' => 'health news' ) as $name => $query ) {
			t_eq( $query, $by( $name )['search_queries'], $name . ' got its search' );
		}
		t_eq( 'cricket', $by( 'Sports' )['search_queries'], 'the search the owner wrote is kept' );
		t_eq( '', $by( 'Science' )['search_queries'], 'a category with its topic changed is left alone' );
		t_contains( 'A Google News search was added to: Technology, Entertainment, Business, Health & Wellness', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );

		// A search the owner removes later does not come back.
		Categories::update( (int) $by( 'Business' )['id'], array( 'search_queries' => '' ) );
		Schema::maybe_upgrade();
		t_eq( '', $by( 'Business' )['search_queries'] );
	}
);

t(
	'PH-29',
	'A story from a publisher feed is tried before a newer Google News story, and a Google story is used when no feed has one',
	static function () {
		global $wpdb;
		$items    = Db::table( 'source_items' );
		$category = ph_ready();
		set_local_now( PH_DAY . ' 09:30:00' );
		$feed   = ph_item( $category, 'from-a-feed', '09:10:00' );
		$google = ph_item( $category, 'from-google', '09:25:00' );
		$wpdb->update( $items, array( 'adapter' => Discovery::GOOGLE ), array( 'id' => $google ) );

		ph_run( '09:30:05' );
		t_eq( $feed, (int) ph_slot()['selected_item_id'], 'the older feed story goes first' );
		t_contains( 'Newest publisher feed story, of 2 eligible', ph_log() );

		zzzt_reset();
		$category = ph_ready();
		set_local_now( PH_DAY . ' 09:30:00' );
		$google = ph_item( $category, 'only-google', '09:25:00' );
		$wpdb->update( $items, array( 'adapter' => Discovery::GOOGLE ), array( 'id' => $google ) );

		ph_run( '09:30:05' );
		t_eq( $google, (int) ph_slot()['selected_item_id'] );
		t_contains( 'Newest Google News story, of 1 eligible', ph_log() );
	}
);

t(
	'PH-30',
	'The Dashboard does not show a dropped story as the chosen one, and says when preparation is already under way',
	static function () {
		$category = ph_ready();
		set_local_now( PH_DAY . ' 08:30:00' );
		Schedule::ensure_slots();
		t_contains( '09:00 UTC+10:30 (preparing the next post)', ph_dashboard(), 'before preparation, its start time' );

		ph_item( $category, 'bumped-story', '09:30:00' );
		ph_feeds( array(), array( 'page_dates' => array( 'bumped-story' => gmdate( 'c', ph_time( '06:00:00' ) ) ) ) );
		set_local_now( PH_DAY . ' 09:35:00' );
		PastHour::tick();
		t_true( (int) ph_slot()['selected_item_id'], 'chosen' );
		t_contains( 'bumped-story', ph_card( ph_dashboard() ) );

		ph_step( Jobs::RETRIEVE );
		$slot = ph_slot();
		t_eq( 0, (int) $slot['article_job_id'] );
		t_eq( 0, (int) $slot['selected_item_id'], 'the dropped story is no longer the chosen one' );
		t_eq( null, $slot['age_at_selection'] );
		$html = ph_dashboard();
		t_not_contains( 'bumped-story', ph_card( $html ), 'the Logs list on the Dashboard still names it, which is right' );
		t_contains( 'Now, preparing the post at 2026-11-05 10:00 UTC+10:30', $html );
		t_not_contains( '09:00 UTC+10:30 (preparing the next post)', $html );
	}
);

t(
	'PH-31',
	'A story with no usable image gives way to another story, and the post goes out with an image',
	static function () {
		ph_ready();
		ph_feeds( array( 'feed' => array( ph_story( 'no-photo', '09:10:00' ) ) ), array( 'commons_pages' => array() ) );
		ph_run( '09:15:00' );
		t_contains( 'No usable image', ph_item_reason(), 'the first story is set aside' );
		t_eq( 0, (int) ph_slot()['article_job_id'], 'and the post looks for another' );

		ph_feeds( array( 'feed' => array( ph_story( 'no-photo', '09:10:00' ), ph_story( 'has-photo', '09:20:00' ) ) ), array( 'commons_pages' => array( cpage( 8301, 'Harbour bridge view' ) ) ) );
		clear_commons_results();
		ph_run( '09:31:00' );
		ph_run( '10:00:20' );
		t_eq( 1, count( posts_with_job() ) );
		t_true( has_post_thumbnail( (int) posts_with_job()[0] ), 'with its image' );
		t_contains( 'has photo', (string) Lifecycle::item( (int) ph_article()['source_item_id'] )['headline'], 'from the second story' );
	}
);

/* ---- The 2-hour window ---- */

t(
	'PH-32',
	'Stories up to 2 hours old are used by default: preparation starts at 08:00 for a 10:00 post, and a story from 08:20 goes out',
	static function () {
		$business = ph_ready( array( 'past_hour_max_age_minutes' => 120, 'past_hour_prep_minutes' => 120 ) );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		t_eq( 120, PastHour::max_age_minutes() );
		t_eq( 120, PastHour::prep_minutes() );

		ph_run( '07:59:59' );
		t_eq( 0, db_count( 'jobs', "job_type = 'discover'" ), 'not before 08:00' );
		t_eq( gmdate( 'Y-m-d H:i:s', ph_time( '08:00:00' ) ), gmdate( 'Y-m-d H:i:s', PastHour::prep_start( ph_slot() ) ) );

		// A story from 07:50 would be 130 minutes old at 10:00, so it is not written.
		ph_feeds( array( 'feed' => array( ph_story( 'too-old-for-two-hours', '07:50:00' ) ) ) );
		ph_run( '08:00:10' );
		t_false( empty( ph_slot()['prep_started_at'] ), 'preparation started' );
		t_eq( 0, db_count( 'article_jobs' ), 'the 07:50 story is not written' );

		ph_feeds( array( 'feed' => array( ph_story( 'two-hour-story', '08:20:00' ) ) ) );
		ph_run( '08:30:00' );
		t_eq( 1, db_count( 'article_jobs' ), 'found by the 08:30 search' );
		$article = ph_article();

		ph_run( '10:00:30' );
		t_eq( Lifecycle::PUBLISHED, job_status( (int) $article['id'] ), 'published at 100 minutes old' );
		t_true( has_post_thumbnail( (int) posts_with_job()[0] ), 'with its image' );
		t_eq( 1, db_count( 'article_jobs' ), 'one article for one post' );
	}
);

t(
	'PH-33',
	'With a 2-hour window other categories are tried from halfway, 60 minutes before the post, not 30',
	static function () {
		$business = ph_ready( array( 'past_hour_max_age_minutes' => 120, 'past_hour_prep_minutes' => 120 ) );
		$science  = ph_add_category( 'Science', 'feed-science.xml' );
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		ph_feeds( array( 'feed-science.xml' => array( ph_story( 'science-two-hours', '08:10:00' ) ) ) );

		$searched = static function ( int $category ): int {
			return db_count( 'jobs', "job_type = 'discover' AND unique_key LIKE 'ph:%:{$category}:%'" );
		};

		ph_run( '08:00:10' );
		ph_run( '08:15:00' );
		ph_run( '08:45:00' );
		t_true( $searched( $business ) >= 3, 'the planned category is searched every 15 minutes' );
		t_eq( 0, $searched( $science ), 'others wait until 09:00' );
		t_eq( 0, db_count( 'article_jobs' ) );

		ph_run( '09:00:00' );
		t_eq( 1, $searched( $science ), 'tried at 09:00' );
		t_eq( 1, db_count( 'article_jobs' ) );
		t_true( (int) ph_slot()['fallback_used'], 'fallback recorded' );
		t_eq( $science, (int) ph_article()['category_id'] );

		ph_run( '10:00:30' );
		t_eq( Lifecycle::PUBLISHED, job_status( (int) ph_article()['id'] ), 'the 08:10 story goes out at 110 minutes old' );
	}
);

t(
	'PH-34',
	'The story age and preparation time are checked together, and neither is longer than the gap between posts',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ph_ready();
		t_eq( 120, Settings::defaults()['past_hour_max_age_minutes'] );
		t_true( is_wp_error( Settings::sanitize( 'past_hour_max_age_minutes', 59 ) ), 'under an hour' );
		t_true( is_wp_error( Settings::sanitize( 'past_hour_max_age_minutes', 181 ) ), 'over 3 hours' );
		t_eq( 180, Settings::sanitize( 'past_hour_max_age_minutes', '180' ) );

		// ph_ready sets both to 60.
		$result = Settings::update( array( 'past_hour_prep_minutes' => 90 ) );
		t_contains( 'no more than the maximum story age, 60 minutes', (string) ( $result['errors']['past_hour_prep_minutes'] ?? '' ) );
		t_eq( 60, (int) Settings::get( 'past_hour_prep_minutes' ), 'not saved' );

		$result = Settings::update( array( 'past_hour_max_age_minutes' => 150, 'past_hour_prep_minutes' => 150 ) );
		t_eq( array(), $result['errors'], 'both together are fine' );
		t_eq( 150, PastHour::prep_minutes() );

		$result = Settings::update( array( 'past_hour_max_age_minutes' => 90 ) );
		t_contains( 'at least the preparation time, 150 minutes', (string) ( $result['errors']['past_hour_max_age_minutes'] ?? '' ) );
		t_eq( 150, (int) Settings::get( 'past_hour_max_age_minutes' ), 'not saved' );

		$result = Settings::update( array( 'past_hour_max_age_minutes' => 90, 'past_hour_prep_minutes' => 90 ) );
		t_eq( array(), $result['errors'], 'lowering both together is fine' );

		// The gap between posts is the upper limit for both.
		Settings::update( array( 'past_hour_max_age_minutes' => 120, 'past_hour_prep_minutes' => 120, 'interval_hours' => 1 ) );
		t_eq( 60, PastHour::max_age_minutes(), 'an hourly schedule allows stories up to 60 minutes old' );
		t_eq( 60, PastHour::prep_minutes() );
		ob_start();
		ZZZCopy\Admin\Pages::schedule();
		$page = (string) ob_get_clean();
		t_contains( 'name="past_hour_max_age_minutes"', $page );
		t_contains( 'Maximum story age (minutes)', $page );
		t_contains( 'The interval between posts is 1 hours, so stories may be at most 60 minutes old', $page );

		Settings::update( array( 'interval_hours' => 6 ) );
		t_eq( 120, PastHour::max_age_minutes(), 'the 6-hour gap leaves the 2-hour window as it is' );
		ob_start();
		ZZZCopy\Admin\Pages::schedule();
		t_false( false !== strpos( (string) ob_get_clean(), 'The interval between posts is' ), 'no note when nothing is capped' );
		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		t_contains( 'Preparation starts 120 minutes before each post. Every story must be no more than 120 minutes old', (string) ob_get_clean() );
		wp_set_current_user( 0 );
	}
);

t(
	'PH-35',
	'Google is asked for the whole window, in whole hours, and each story date is still checked here',
	static function () {
		$category = ph_ready( array( 'past_hour_max_age_minutes' => 120, 'past_hour_prep_minutes' => 120 ) );
		$row      = Categories::get( $category );
		$row['recency'] = '24h';
		$search   = Discovery::search_url( 'x', $row );
		t_contains( 'when%3A2h', $search['url'] );
		t_contains( 'when:2h', $search['filter'] );

		Settings::update( array( 'past_hour_max_age_minutes' => 150 ) );
		t_contains( 'when%3A3h', Discovery::search_url( 'x', $row )['url'], '150 minutes is rounded up to 3 hours' );

		set_local_now( PH_DAY . ' 10:00:00' );
		$check = static function ( int $published ) use ( $row ) {
			return Discovery::filter_reason( $row, array( 'headline' => 'A', 'snippet' => 'B', 'date_reliable' => true, 'published' => $published ) );
		};
		t_eq( null, $check( ph_time( '07:30:00' ) ), 'exactly 150 minutes' );
		t_contains( 'older than the 150-minute limit', (string) $check( ph_time( '07:29:59' ) ) );

		Settings::update( array( 'past_hour_enabled' => 0 ) );
		t_contains( 'when%3A1d', Discovery::search_url( 'x', $row )['url'], 'off: the category filter applies again' );
	}
);

t(
	'PH-36',
	'Upgrading moves a 60-minute preparation time to 120 and adds the 120-minute story age, but keeps a time the owner chose',
	static function () {
		$saved = get_option( 'zzzc_settings' );
		unset( $saved['past_hour_max_age_minutes'] );
		$saved['past_hour_prep_minutes'] = 60;
		update_option( 'zzzc_settings', $saved, false );
		Settings::flush_cache();
		update_option( 'zzzc_db_version', '8', true );
		Schema::maybe_upgrade();

		t_eq( Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		t_eq( 120, (int) Settings::get( 'past_hour_prep_minutes' ) );
		t_eq( 120, (int) Settings::get( 'past_hour_max_age_minutes' ) );
		t_contains( 'now uses stories up to 120 minutes old, and preparation starts 120 minutes before each post', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );

		$saved = get_option( 'zzzc_settings' );
		unset( $saved['past_hour_max_age_minutes'] );
		$saved['past_hour_prep_minutes'] = 45;
		update_option( 'zzzc_settings', $saved, false );
		Settings::flush_cache();
		update_option( 'zzzc_db_version', '8', true );
		Schema::maybe_upgrade();
		t_eq( 45, (int) Settings::get( 'past_hour_prep_minutes' ), 'the owner\'s own time is kept' );
		t_eq( 120, (int) Settings::get( 'past_hour_max_age_minutes' ) );

		// A second run of the upgrade changes nothing.
		Schema::maybe_upgrade();
		t_eq( 45, (int) Settings::get( 'past_hour_prep_minutes' ) );
	}
);

t(
	'PH-37',
	'The Schedule form saves the story age, and a preparation time longer than it is marked on the form',
	static function () {
		ph_ready();
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		add_action( 'admin_post_zzzc_save_schedule', array( ZZZCopy\Admin\Actions::class, 'dispatch' ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect ' . $location );
			}
		);
		$submit = static function ( array $changes ): array {
			$_POST    = array_merge( Settings::all(), $changes );
			$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'zzzc_save_schedule' ) );
			try {
				do_action( 'admin_post_zzzc_save_schedule' );
			} catch ( RuntimeException $e ) {
				t_contains( 'page=zzzc-schedule', $e->getMessage() );
			}
			$flash = (array) get_transient( 'zzzc_flash_' . get_current_user_id() );
			delete_transient( 'zzzc_flash_' . get_current_user_id() );
			return $flash;
		};

		$flash = $submit( array( 'past_hour_max_age_minutes' => '120', 'past_hour_prep_minutes' => '120' ) );
		t_eq( 'success', $flash['type'] ?? '', 'saved' );
		t_eq( 120, (int) Settings::get( 'past_hour_max_age_minutes' ) );
		t_eq( 120, (int) Settings::get( 'past_hour_prep_minutes' ) );

		$flash = $submit( array( 'past_hour_prep_minutes' => '150' ) );
		t_eq( 'error', $flash['type'] ?? '' );
		t_true( isset( $flash['fields']['past_hour_prep_minutes'] ), 'the field is marked' );
		t_eq( 120, (int) Settings::get( 'past_hour_prep_minutes' ), 'not saved' );

		remove_all_filters( 'wp_redirect' );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
	}
);
