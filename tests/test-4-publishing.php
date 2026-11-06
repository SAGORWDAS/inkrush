<?php
use ZZZCopy\Articles;
use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Health;
use ZZZCopy\Jobs;
use ZZZCopy\Lifecycle;
use ZZZCopy\Locks;
use ZZZCopy\Maintenance;
use ZZZCopy\Planner;
use ZZZCopy\Publisher;
use ZZZCopy\RestTick;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;

/**
 * Builds a queued, validated article ready to publish.
 */
function queued_article( int $category, string $slug ): int {
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

function slot_rows( string $date ): array {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::table( 'slots' ) . ' WHERE local_date = %s ORDER BY slot_index', $date ), ARRAY_A );
}

function posts_with_job(): array {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Publisher::META_JOB ) );
}

t(
	'AC-12,M18',
	'Default schedule: 10:00, 16:00 and 22:00 at UTC+10:30, with correct UTC times',
	static function () {
		set_local_now( '2026-11-05 09:00:00' );
		$times = Schedule::slot_times( '2026-11-05' );
		t_eq( 3, count( $times ) );
		t_eq( array( '10:00', '16:00', '22:00' ), array_map( static fn( $x ) => $x->format( 'H:i' ), array_values( $times ) ) );
		t_eq( '2026-11-04 23:30', gmdate( 'Y-m-d H:i', $times[0]->getTimestamp() ), '10:00 is 23:30 UTC the day before' );
		t_eq( '2026-11-05 05:30', gmdate( 'Y-m-d H:i', $times[1]->getTimestamp() ) );
		t_eq( '2026-11-05 11:30', gmdate( 'Y-m-d H:i', $times[2]->getTimestamp() ) );
		t_eq( 21600, $times[1]->getTimestamp() - $times[0]->getTimestamp(), 'six hours apart' );
		t_eq( 'UTC+10:30', Schedule::zone_label() );
	}
);

t(
	'AC-12,M18',
	'Schedule settings are honored, and slots that would cross midnight are not created',
	static function () {
		Settings::update( array( 'daily_target' => 4, 'interval_hours' => 3, 'first_time' => '08:30', 'timezone' => '-05:00' ) );
		$GLOBALS['zzzt_now'] = strtotime( '2026-06-15 12:00:00 UTC' );
		$times = Schedule::slot_times( '2026-06-15' );
		t_eq( array( '08:30', '11:30', '14:30', '17:30' ), array_map( static fn( $x ) => $x->format( 'H:i' ), array_values( $times ) ) );
		t_eq( '2026-06-15 13:30', gmdate( 'Y-m-d H:i', $times[0]->getTimestamp() ) );

		Settings::update( array( 'daily_target' => 5, 'interval_hours' => 6, 'first_time' => '10:00' ) );
		t_eq( 3, Schedule::slots_that_fit(), 'only those before midnight' );
	}
);

t(
	'AC-12',
	'Daylight saving: slots keep their wall-clock time in a named zone, and a fixed offset never moves',
	static function () {
		Settings::update( array( 'timezone' => 'Australia/Lord_Howe' ) );

		// Lord Howe summer is +11:00, winter +10:30.
		$summer = Schedule::slot_times( '2026-01-15' );
		$winter = Schedule::slot_times( '2026-07-15' );
		t_eq( '10:00', $summer[0]->format( 'H:i' ) );
		t_eq( '+11:00', $summer[0]->format( 'P' ) );
		t_eq( '10:00', $winter[0]->format( 'H:i' ) );
		t_eq( '+10:30', $winter[0]->format( 'P' ) );
		t_eq( '2026-01-14 23:00', gmdate( 'Y-m-d H:i', $summer[0]->getTimestamp() ) );
		t_eq( '2026-07-14 23:30', gmdate( 'Y-m-d H:i', $winter[0]->getTimestamp() ) );

		// Across the change on 2026-04-05, slot times stay at 10, 16 and 22 locally.
		$change = Schedule::slot_times( '2026-04-05' );
		t_eq( array( '10:00', '16:00', '22:00' ), array_map( static fn( $x ) => $x->format( 'H:i' ), array_values( $change ) ) );

		// A US zone where the day has 23 hours.
		Settings::update( array( 'timezone' => 'America/New_York', 'first_time' => '01:00', 'interval_hours' => 2, 'daily_target' => 3 ) );
		$spring = Schedule::slot_times( '2026-03-08' );
		t_eq( array( '01:00', '03:00', '05:00' ), array_map( static fn( $x ) => $x->format( 'H:i' ), array_values( $spring ) ) );
		list( $start, $end ) = Schedule::day_bounds_utc( '2026-03-08' );
		t_eq( 23 * 3600 - 1, strtotime( $end . ' UTC' ) - strtotime( $start . ' UTC' ), '23-hour local day' );

		Settings::update( array( 'timezone' => '+10:30', 'first_time' => '10:00', 'interval_hours' => 6 ) );
		$a = Schedule::slot_times( '2026-01-15' );
		$b = Schedule::slot_times( '2026-07-15' );
		t_eq( gmdate( 'H:i', $a[0]->getTimestamp() ), gmdate( 'H:i', $b[0]->getTimestamp() ), 'fixed offset does not move' );
	}
);

t(
	'M18',
	'Starting mid-day does not count earlier slots as due, and the day continues at the next slot',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 12:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		$rows = slot_rows( '2026-11-05' );
		t_eq( 'MISSED', $rows[0]['status'], '10:00 had already passed' );
		t_contains( 'already passed', $rows[0]['status_reason'] );
		t_eq( 'PENDING', $rows[1]['status'] );
		t_eq( 'PENDING', $rows[2]['status'] );

		$one = queued_article( $category, 'midday-one' );
		$two = queued_article( $category, 'midday-two' );

		set_local_now( '2026-11-05 13:00:00' );
		Publisher::tick();
		t_eq( 0, Publisher::posts_on_day( '2026-11-05' ), 'no post before the next slot' );

		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ) );
		set_local_now( '2026-11-05 16:45:00' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'the earlier slot is not caught up' );
	}
);

t(
	'M12',
	'A title nested in a wrapper element is found, and text before it is still reported',
	static function () {
		$parsed = ZZZCopy\ContentParser::parse( '<p>Note first.</p><div><h1>Nested Title</h1><p>Body text.</p></div>' );
		t_eq( 'Nested Title', $parsed['title'] );
		t_eq( 'Note first.', $parsed['preamble'] );
		t_contains( 'Body text.', $parsed['body'] );
	}
);

t(
	'M19',
	'Round robin rotation: no repeat in a day while alternatives exist, and it continues the next day',
	static function () {
		ready_plugin();
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Db::table( 'categories' ) . ' SET enabled = 1' );

		set_local_now( '2026-11-05 09:00:00' );
		Schedule::ensure_slots();

		$day1 = array_map( 'intval', array_column( slot_rows( '2026-11-05' ), 'category_id' ) );
		$day2 = array_map( 'intval', array_column( slot_rows( '2026-11-06' ), 'category_id' ) );
		t_eq( 3, count( array_unique( $day1 ) ), 'three different categories on day 1' );
		t_eq( 3, count( array_unique( $day2 ) ), 'three different on day 2' );
		t_eq( 6, count( array_unique( array_merge( $day1, $day2 ) ) ), 'all six rotate before any repeats' );

		// Ensuring again does not change what was planned.
		Schedule::ensure_slots();
		t_eq( $day1, array_map( 'intval', array_column( slot_rows( '2026-11-05' ), 'category_id' ) ) );
		t_eq( 6, db_count( 'slots' ) );
	}
);

t(
	'M19',
	'Rotation modes: per slot, weighted, pools, disabled categories and active days',
	static function () {
		ready_plugin();
		global $wpdb;
		$table = Db::table( 'categories' );
		$wpdb->query( "UPDATE {$table} SET enabled = 1" );
		$all = array_map( 'intval', array_column( Categories::all(), 'id' ) );

		// Explicit per-slot.
		Settings::update( array( 'rotation_mode' => 'per_slot', 'slot_categories' => array( 0 => $all[4], 1 => 0, 2 => $all[2] ) ) );
		t_eq( $all[4], Schedule::pick_category( '2026-11-05', 0, array() ), 'slot 1 explicit' );
		t_eq( $all[2], Schedule::pick_category( '2026-11-05', 2, array() ), 'slot 3 explicit' );
		t_true( in_array( Schedule::pick_category( '2026-11-05', 1, array( $all[4] ) ), $all, true ), 'slot 2 rotates' );

		// Explicit category that is disabled is not used.
		Categories::update( $all[4], array( 'enabled' => 0 ) );
		t_true( $all[4] !== Schedule::pick_category( '2026-11-05', 0, array() ), 'disabled is not chosen' );
		Categories::update( $all[4], array( 'enabled' => 1 ) );

		// Pool limits the choice.
		Settings::update( array( 'rotation_mode' => 'round_robin', 'slot_categories' => array(), 'rotation_pool' => array( $all[0], $all[1] ) ) );
		$seen = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$seen[] = Schedule::pick_category( '2026-11-05', 0, array() );
		}
		t_eq( array( $all[0], $all[1] ), array_values( array_unique( array_merge( array( $all[0], $all[1] ), $seen ) ) ), 'only pool members' );
		t_eq( 2, count( array_unique( $seen ) ), 'both used' );

		// No repeat when others remain, repeat allowed when not.
		$repeat = Schedule::pick_category( '2026-11-05', 2, array( $all[0], $all[1] ) );
		t_true( in_array( $repeat, array( $all[0], $all[1] ), true ), 'repeats only when none are left' );

		// Weighted never repeats within the used list, and respects weight.
		Settings::update( array( 'rotation_mode' => 'weighted', 'rotation_pool' => array() ) );
		Categories::update( $all[0], array( 'weight' => 100 ) );
		$counts = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$pick = Schedule::pick_category( '2026-11-05', 0, array( $all[5] ) );
			t_true( $pick !== $all[5], 'used category excluded' );
			$counts[ $pick ] = ( $counts[ $pick ] ?? 0 ) + 1;
		}
		t_true( $counts[ $all[0] ] > 300, 'heavy weight wins most: ' . $counts[ $all[0] ] );

		// Active days. 2026-11-05 is a Thursday (4).
		Settings::update( array( 'rotation_mode' => 'round_robin' ) );
		foreach ( $all as $id ) {
			Categories::update( $id, array( 'active_days' => '1' ) );
		}
		Categories::update( $all[3], array( 'active_days' => '4' ) );
		t_eq( array( $all[3] ), array_map( 'intval', array_column( Schedule::pool_for_date( '2026-11-05' ), 'id' ) ), 'only Thursday category' );
	}
);

t(
	'AC-09,AC-10,AC-17,M14,M15,M25',
	'Draft mode creates a draft with every field mapped correctly and its featured image',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		mock_state( array( 'article_mode' => 'with_image' ) );

		$job = queued_article( $category, 'draft-mode' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();

		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ) );
		$posts = posts_with_job();
		t_eq( 1, count( $posts ) );
		$post = get_post( (int) $posts[0] );

		t_eq( 'draft', $post->post_status );
		$content = Lifecycle::content( $job );
		t_eq( wp_strip_all_tags( $content['title'] ), $post->post_title, 'title in post_title' );
		t_contains( 'New report:', $post->post_title );
		t_contains( '<h2>What changed this week</h2>', $post->post_content, 'body in post_content' );
		t_not_contains( $post->post_title, $post->post_content, 'title not in the body' );
		t_contains( '<table', $post->post_content, 'table survives' );
		t_contains( '<ul>', $post->post_content, 'list survives' );
		t_contains( '<h3>', $post->post_content );
		t_contains( '<h4>', $post->post_content );

		$terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		t_eq( 20, count( $terms ), '20 tags in post_tag' );

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		t_eq( array( 'Business' ), $categories, 'category in category taxonomy' );

		t_not_contains( '<img', $post->post_content, 'image element removed' );
		t_not_contains( 'photo.jpg', $post->post_content );
		t_true( (int) get_post_thumbnail_id( $post->ID ) > 0, 'every post has a featured image, a draft too' );
		t_eq( 1, count( get_attached_media( '', $post->ID ) ), 'its only attachment is the featured image' );

		$item = Lifecycle::item( (int) Lifecycle::job( $job )['source_item_id'] );
		t_eq( Lifecycle::job( $job )['job_uid'], get_post_meta( $post->ID, Publisher::META_JOB, true ), 'job id in meta' );
		t_eq( $item['canonical_url'], get_post_meta( $post->ID, '_zzzc_source_url', true ) );
		t_eq( 'Mock News', get_post_meta( $post->ID, '_zzzc_publisher', true ) );
		t_eq( 'openai/gpt-6-luna', get_post_meta( $post->ID, '_zzzc_model', true ) );
		t_not_contains( 'Source:', $post->post_content, 'attribution stays out of the body by default' );

		t_eq( 1, db_count( 'post_links', "post_status = 'draft' AND local_day = '2026-11-05'" ) );
		t_eq( 'FILLED', slot_rows( '2026-11-05' )[0]['status'] );
	}
);

t(
	'AC-10,M16',
	'Automatic mode publishes with no approval, and a category can override the mode',
	static function () {
		$category = ready_plugin( array(), 'publish' );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		$job = queued_article( $category, 'publish-mode' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();

		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		$post = get_post( (int) posts_with_job()[0] );
		t_eq( 'publish', $post->post_status );
		t_eq( 20, count( wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) ) ) );
		t_true( '0000-00-00 00:00:00' !== $post->post_date_gmt, 'has a publish date' );

		Categories::update( $category, array( 'publish_mode' => 'draft' ) );
		t_eq( 'draft', Publisher::target_status( Lifecycle::job( $job ) ), 'category override' );
	}
);

t(
	'AC-10,M16',
	"Owner's setup out of the box: only the API key is needed, nothing posts before Start, then articles go live in new WordPress categories",
	static function () {
		$defaults = Settings::defaults();
		t_eq( 'publish', $defaults['publish_mode'] );
		t_eq( 1, $defaults['category_auto_create'] );
		t_eq( 'publish', Settings::get( 'publish_mode' ), 'a fresh install uses Automatic Publish' );
		t_eq( array( 'Add your OpenRouter API key on the AI Settings page.' ), Automation::config_problems(), 'the key is the only thing needed' );

		global $wpdb;
		$war = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::table( 'categories' ) . " WHERE name = 'War & Conflict'" );
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'name' => 'War & Conflict', 'fields' => 'ids' ) ) as $old_term ) {
			wp_delete_term( (int) $old_term, 'category' );
		}

		ZZZCopy\Secrets::set_api_key( 'sk-or-v1-testkey-abcdef123456' );
		t_eq( array(), Automation::config_problems(), 'nothing else is needed after the key' );

		$wpdb->query( 'UPDATE ' . Db::table( 'categories' ) . ' SET enabled = 0' );
		Categories::update( $war, array( 'enabled' => 1, 'source_mode' => 'feeds', 'feed_urls' => ZZZT_MOCK . '/feed.xml', 'topic_urls' => '', 'search_queries' => '' ) );
		set_local_now( '2026-11-05 09:00:00' );
		mock_state( array( 'feeds' => array( 'feed' => array( feed_item( 'default-setup', 'Default setup story reaches the live site' ) ) ) ) );
		t_eq( true, Automation::start() );
		run_ticks( 8 );

		t_eq( 1, db_count( 'publishing_queue', "queue_status = 'QUEUED'" ), 'article ready' );
		t_eq( 0, count( posts_with_job() ), 'no post before its slot' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();

		$posts = posts_with_job();
		t_eq( 1, count( $posts ) );
		t_eq( 'publish', get_post_status( (int) $posts[0] ), 'live with no approval' );
		$names = wp_get_post_categories( (int) $posts[0], array( 'fields' => 'names' ) );
		t_eq( 'War & Conflict', html_entity_decode( (string) $names[0] ), 'in a WordPress category created automatically' );
		t_eq( 1, count( wp_get_post_categories( (int) $posts[0] ) ) );

		// A failed article is still held, even in Automatic Publish.
		$held = make_job_from_url( $war, ZZZT_MOCK . '/article/still-held-story', 'Malformed Output Keeps Waiting Here' );
		mock_state( array( 'article_mode' => 'no_table' ) );
		Settings::update( array( 'daily_ai_limit' => 500 ) );
		run_ticks( 12 );
		t_eq( Lifecycle::VALIDATION_FAILED, job_status( $held ), 'not published' );
		t_eq( 1, count( posts_with_job() ), 'no second post' );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ob_start();
		ZZZCopy\Admin\Pages::publishing();
		$html = (string) ob_get_clean();
		t_true( (bool) preg_match( '/<option value="publish" selected=\'selected\'>Automatic Publish: publish with no approval/', $html ), 'the page shows the mode' );
	}
);

t(
	'Publishing',
	'Options: visible attribution, reuse-only tags, category mapping and no auto-create',
	static function () {
		$category = ready_plugin();
		$term     = wp_insert_term( 'Finance Desk ' . wp_rand(), 'category' );
		Categories::update( $category, array( 'wp_term_id' => (int) $term['term_id'] ) );
		Settings::update( array( 'attribution' => 'visible', 'tag_mode' => 'reuse', 'category_auto_create' => 0 ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		$job = queued_article( $category, 'options-story' );

		$tags = json_decode( Lifecycle::content( $job )['tags_json'], true );
		wp_insert_term( $tags[0], 'post_tag' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();

		$post = get_post( (int) posts_with_job()[0] );
		t_contains( 'Source: <a href="http://127.0.0.1:8091/article/options-story"', $post->post_content, 'visible source line' );
		t_eq( array( (int) $term['term_id'] ), array_map( 'intval', wp_get_post_categories( $post->ID ) ), 'mapped category' );
		t_eq( array( $tags[0] ), wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) ), 'only the existing tag' );

		// No mapping and no auto-create: a clear failure and no post.
		$other = Categories::insert( array( 'name' => 'Unmapped Zone', 'sort_order' => 50 ) );
		t_true( is_wp_error( Categories::resolve_term( Categories::get( $other ) ) ) );
		t_contains( 'Unmapped Zone', implode( ' ', ZZZCopy\Automation::config_problems() ), 'Start would explain it' );
	}
);

t(
	'AC-11,M17',
	'The daily cap is never exceeded, however many articles are ready',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'catchup_gap_minutes' => 0, 'daily_cap' => 2 ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		for ( $i = 1; $i <= 5; $i++ ) {
			queued_article( $category, "cap-story-$i" );
		}

		foreach ( array( '10:00:30', '10:01:30', '16:00:30', '16:01:30', '22:00:30', '22:01:30', '23:59:00' ) as $time ) {
			set_local_now( "2026-11-05 $time" );
			Publisher::tick();
			t_true( Publisher::posts_on_day( '2026-11-05' ) <= 2, 'never over the cap at ' . $time );
		}

		t_eq( 2, Publisher::posts_on_day( '2026-11-05' ), 'stopped at the cap' );
		t_eq( 'MISSED', slot_rows( '2026-11-05' )[2]['status'], 'the remaining slot is closed' );
		t_contains( 'cap', slot_rows( '2026-11-05' )[2]['status_reason'] );
		t_eq( 3, db_count( 'publishing_queue', "queue_status = 'QUEUED'" ), 'others wait' );
	}
);

t(
	'AC-11,M17',
	'The cap resets exactly at local midnight in UTC+10:30, not at UTC midnight',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'catchup_gap_minutes' => 0 ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		for ( $i = 1; $i <= 5; $i++ ) {
			queued_article( $category, "midnight-story-$i" );
		}

		foreach ( array( '10:00:30', '16:00:30', '22:00:30' ) as $time ) {
			set_local_now( "2026-11-05 $time" );
			Publisher::tick();
		}
		t_eq( 3, Publisher::posts_on_day( '2026-11-05' ), 'three posts' );
		t_true( Publisher::cap_reached(), 'cap reached' );

		// UTC midnight (10:30 local) has long passed. It must make no difference.
		set_local_now( '2026-11-05 23:59:59' );
		t_eq( '2026-11-05', Schedule::today() );
		Publisher::tick();
		t_eq( 3, Publisher::posts_on_day( '2026-11-05' ), 'still three at 23:59:59' );

		set_local_now( '2026-11-06 00:00:00' );
		t_eq( '2026-11-06', Schedule::today(), 'a new local day' );
		t_false( Publisher::cap_reached(), 'cap is clear on the new day' );
		t_eq( 0, Publisher::posts_on_day( '2026-11-06' ) );
		Schedule::ensure_slots();
		Publisher::tick();
		t_eq( 0, Publisher::posts_on_day( '2026-11-06' ), 'no slot is due at midnight, so nothing publishes' );

		set_local_now( '2026-11-06 10:00:30' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-06' ), 'next day publishes at its slot' );
		t_eq( 3, Publisher::posts_on_day( '2026-11-05' ), 'previous day is unchanged' );
	}
);

t(
	'AC-11',
	'Cap scope: drafts and published are counted separately, and "published only" ignores drafts',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'cap_scope' => 'published', 'daily_cap' => 1, 'catchup_gap_minutes' => 0 ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		queued_article( $category, 'scope-one' );
		queued_article( $category, 'scope-two' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05', 'draft' ) );
		t_eq( 0, Publisher::cap_count_today(), 'drafts do not count under this scope' );
		t_false( Publisher::cap_reached() );

		Settings::update( array( 'cap_scope' => 'all' ) );
		t_eq( 1, Publisher::cap_count_today(), 'drafts count under the default scope' );
		t_true( Publisher::cap_reached() );
	}
);

t(
	'AC-13,M22',
	'A timeout after the post is created does not create a second post on retry',
	static function () {
		$category = ready_plugin( array(), 'publish' );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job = queued_article( $category, 'timeout-story' );

		add_action(
			'zzzc_after_post_created',
			static function () {
				throw new RuntimeException( 'Simulated PHP timeout right after the post was created' );
			}
		);

		set_local_now( '2026-11-05 10:00:30' );
		try {
			Publisher::tick();
			throw new ZZZT_Fail( 'the simulated crash did not fire' );
		} catch ( RuntimeException $e ) {
			t_contains( 'Simulated', $e->getMessage() );
		}
		Locks::release( 'publish', '' );
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Db::table( 'locks' ) );

		t_eq( Lifecycle::PUBLISHING, job_status( $job ), 'left mid-way, as a crash would' );
		t_eq( 1, count( posts_with_job() ), 'the post exists' );
		$post_id = (int) posts_with_job()[0];
		t_eq( 'draft', get_post_status( $post_id ), 'not public until finished' );
		t_eq( 0, db_count( 'post_links' ), 'mapping not written yet' );

		remove_all_actions( 'zzzc_after_post_created' );

		// A retry before the lock expires leaves it alone.
		Publisher::recover_interrupted();
		t_eq( Lifecycle::PUBLISHING, job_status( $job ), 'lock still held' );
		t_eq( 1, count( posts_with_job() ) );

		// The lock expires and the worker recovers.
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . Db::table( 'publishing_queue' ) . ' SET lock_expires = %s', gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
		Publisher::recover_interrupted();

		t_eq( Lifecycle::PUBLISHED, job_status( $job ), 'completed' );
		t_eq( 1, count( posts_with_job() ), 'still one post' );
		t_eq( $post_id, (int) posts_with_job()[0], 'the same post' );
		t_eq( 'publish', get_post_status( $post_id ) );
		t_eq( 20, count( wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ) ), 'tags finished' );
		t_eq( 1, db_count( 'post_links' ), 'one mapping' );
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'counted once' );
	}
);

t(
	'AC-13,M22',
	'A crash before meta is written still finds the post, and a lost mapping is not duplicated',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job    = queued_article( $category, 'identifier-story' );
		$record = Lifecycle::job( $job );

		// Only the row exists, with the identifier saved in the same insert.
		$post_id = wp_insert_post(
			array(
				'post_title'            => 'Half finished',
				'post_content'          => 'x',
				'post_status'           => 'draft',
				'post_content_filtered' => 'zzzc:' . $record['job_uid'],
			)
		);
		t_eq( $post_id, Publisher::find_existing_post( $record ), 'found by row identifier' );

		update_post_meta( $post_id, Publisher::META_JOB, $record['job_uid'] );
		$wpdb->update( $wpdb->posts, array( 'post_content_filtered' => '' ), array( 'ID' => $post_id ) );
		t_eq( $post_id, Publisher::find_existing_post( $record ), 'found by meta' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ) );
		t_eq( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Publisher::META_JOB, $record['job_uid'] ) ), 'one post for this job' );
		t_eq( $post_id, (int) $wpdb->get_var( 'SELECT wp_post_id FROM ' . Db::table( 'post_links' ) . ' WHERE article_job_id = ' . $job ), 'adopted the existing post' );
	}
);

t(
	'AC-13',
	'Two workers cannot publish the same item, and the database refuses a second mapping',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job = queued_article( $category, 'lock-story' );

		// Another worker holds the publishing lock.
		$held = Locks::acquire( 'publish', 60 );
		t_true( $held, 'lock taken' );
		t_false( Locks::acquire( 'publish', 60 ), 'a second holder is refused' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'nothing happens while locked' );

		Locks::release( 'publish', $held );
		Publisher::tick();
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ) );
		t_eq( 1, count( posts_with_job() ) );

		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert(
			Db::table( 'post_links' ),
			array(
				'article_job_id' => $job,
				'job_uid'        => 'x',
				'wp_post_id'     => 999,
				'post_status'    => 'draft',
				'local_day'      => '2026-11-05',
				'created_at'     => Clock::mysql(),
			)
		);
		$wpdb->suppress_errors( $suppress );
		t_false( $second, 'unique index refuses a duplicate mapping' );

		// An expired lock can be taken over.
		$wpdb->insert( Db::table( 'locks' ), array( 'lock_name' => 'old', 'owner' => 'dead', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ) );
		t_true( Locks::acquire( 'old', 60 ), 'expired lock is recoverable' );
	}
);

t(
	'Publishing',
	'A WordPress post error retries and then stops at PUBLISH_FAILED, and can be retried by hand',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job = queued_article( $category, 'wp-error-story' );
		// The image is found first, because the broken filter below also stops Media Library uploads.
		set_local_now( '2026-11-05 09:30:00' );
		Publisher::tick();
		t_eq( 'ready', ZZZCopy\Images\ImagePipeline::get_run( $job )['status'] );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$queue = Db::table( 'publishing_queue' );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'first failure goes back to the queue' );
		t_eq( 1, (int) $wpdb->get_var( "SELECT attempts FROM {$queue}" ) );
		t_true( null !== $wpdb->get_var( "SELECT retry_at FROM {$queue}" ), 'with a backoff' );
		t_true( db_count( 'events', "stage = 'publishing' AND severity = 'warning'" ) > 0, 'logged' );

		$wpdb->query( "UPDATE {$queue} SET retry_at = NULL" );
		Publisher::tick();
		$wpdb->query( "UPDATE {$queue} SET retry_at = NULL" );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'stops at the limit' );
		t_eq( 0, count( posts_with_job() ) );

		remove_all_filters( 'wp_insert_post_empty_content' );

		t_eq( true, Articles::retry( $job ), 'retry by hand' );
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ), 'succeeds' );
		t_eq( 1, count( posts_with_job() ) );
	}
);

t(
	'M18',
	'Catch-up "publish next available": an empty slot is deferred, then filled at the next slot time',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( 'PENDING', slot_rows( '2026-11-05' )[0]['status'], 'waits during grace' );

		set_local_now( '2026-11-05 10:40:00' );
		Publisher::tick();
		t_eq( 'DEFERRED', slot_rows( '2026-11-05' )[0]['status'] );
		t_contains( 'No validated article', slot_rows( '2026-11-05' )[0]['status_reason'] );

		$saved = $GLOBALS['zzzt_now'];
		queued_article( $category, 'catchup-one' );
		queued_article( $category, 'catchup-two' );

		// An article being ready does not trigger a post before the next slot.
		set_local_now( '2026-11-05 15:00:00' );
		Publisher::tick();
		t_eq( 0, Publisher::posts_on_day( '2026-11-05' ), 'waits for the next slot time' );

		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'current slot first' );
		t_eq( 'FILLED', slot_rows( '2026-11-05' )[1]['status'] );

		set_local_now( '2026-11-05 16:10:00' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'minimum gap holds' );

		set_local_now( '2026-11-05 16:31:00' );
		Publisher::tick();
		t_eq( 2, Publisher::posts_on_day( '2026-11-05' ), 'then the deferred slot' );
		t_eq( 'FILLED', slot_rows( '2026-11-05' )[0]['status'] );
	}
);

t(
	'M18',
	'Catch-up "skip": a missed slot stays missed, and an unfilled slot closes when the day ends',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'catchup' => 'skip' ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		set_local_now( '2026-11-05 10:40:00' );
		Publisher::tick();
		t_eq( 'MISSED', slot_rows( '2026-11-05' )[0]['status'] );

		queued_article( $category, 'skip-policy-one' );

		set_local_now( '2026-11-05 15:00:00' );
		Publisher::tick();
		t_eq( 0, Publisher::posts_on_day( '2026-11-05' ), 'no catch-up' );

		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'next slot publishes normally' );
		t_eq( 'MISSED', slot_rows( '2026-11-05' )[0]['status'], 'stays missed' );

		set_local_now( '2026-11-06 00:10:00' );
		Publisher::tick();
		t_eq( 'MISSED', slot_rows( '2026-11-05' )[2]['status'], 'closed at day end' );
		t_contains( 'day ended', slot_rows( '2026-11-05' )[2]['status_reason'] );
	}
);

t(
	'M18',
	'A worker outage past the grace period defers the slot with a clear reason, and cannot exceed the cap',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'catchup_gap_minutes' => 0 ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		for ( $i = 1; $i <= 5; $i++ ) {
			queued_article( $category, "outage-story-$i" );
		}

		// The worker comes back at 23:00, after all three slots.
		set_local_now( '2026-11-05 23:00:00' );
		Publisher::tick();
		$rows = slot_rows( '2026-11-05' );
		t_eq( 'FILLED', $rows[0]['status'], 'the oldest slot catches up first' );
		t_eq( 'DEFERRED', $rows[1]['status'] );
		t_contains( 'worker was not running', $rows[1]['status_reason'] );
		t_eq( 1, Publisher::posts_on_day( '2026-11-05' ), 'only one post on recovery, not a burst' );
		for ( $i = 0; $i < 10; $i++ ) {
			Publisher::tick();
		}
		t_true( Publisher::posts_on_day( '2026-11-05' ) <= 3, 'never over the cap after an outage' );
	}
);

t(
	'Publishing',
	'Old queued articles are skipped, and the same-category article is preferred with fallback when empty',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$old = queued_article( $category, 'stale-story' );
		$wpdb->update( Db::table( 'publishing_queue' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', Clock::now() - 100 * HOUR_IN_SECONDS ) ), array( 'article_job_id' => $old ) );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::SKIPPED, job_status( $old ), 'too old to publish' );
		t_eq( 0, count( posts_with_job() ) );

		$other = Categories::insert( array( 'name' => 'Backup Cat', 'sort_order' => 60, 'feed_urls' => 'https://x.example/f' ) );
		$wpdb->update( Db::table( 'slots' ), array( 'category_id' => $other ), array( 'slot_index' => 0, 'local_date' => '2026-11-05' ) );

		$saved_now = $GLOBALS['zzzt_now'];
		$job       = queued_article( $category, 'fallback-story' );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ), 'fallback fills the slot' );
		t_eq( array( 'Business' ), wp_get_post_categories( (int) posts_with_job()[0], array( 'fields' => 'names' ) ), 'under its own category' );

		Settings::update( array( 'category_fallback' => 0 ) );
		$wpdb->update( Db::table( 'slots' ), array( 'category_id' => $other ), array( 'slot_index' => 1, 'local_date' => '2026-11-05' ) );
		$no = queued_article( $category, 'waits-for-own-category' );
		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $no ), 'without fallback it waits' );
	}
);

t(
	'M11',
	'Duplicates are checked before queue entry and again before the post is created',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		$first = queued_article( $category, 'dup-check-first' );

		// A second story that passed early checks and generated the same title.
		$second      = make_job_from_url( $category, ZZZT_MOCK . '/article/dup-check-second', 'Words Entirely Different Here' );
		$record      = Lifecycle::job( $second );
		$first_title = Lifecycle::content( $first )['title'];
		$wpdb->insert(
			Db::table( 'article_content' ),
			array(
				'article_job_id' => $second,
				'title'          => $first_title,
				'body'           => '<p>x</p>',
				'tags_json'      => '[]',
			)
		);
		$item      = Lifecycle::item( (int) $record['source_item_id'] );
		$content   = Lifecycle::content( $second );
		$duplicate = ZZZCopy\Dedupe::check_before_publish( $record, $item, $content, true );
		t_true( null !== $duplicate, 'caught against the queue' );
		t_contains( 'Generated title', $duplicate['reason'] );
		t_true( null === ZZZCopy\Dedupe::check_before_publish( $record, $item, $content, false ), 'the queued one is not yet a post' );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $first ) );

		$duplicate = ZZZCopy\Dedupe::check_before_publish( $record, $item, $content, false );
		t_true( null !== $duplicate, 'caught against the existing post' );

		// Same at publish time: a queued item that matches a post is marked, with no post made.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'article_jobs' ) . " SET status = 'QUEUED' WHERE id = %d", $second ) );
		$wpdb->insert( Db::table( 'publishing_queue' ), array( 'article_job_id' => $second, 'category_id' => $category, 'queue_status' => 'QUEUED', 'created_at' => Clock::mysql(), 'updated_at' => Clock::mysql() ) );
		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::DUPLICATE, job_status( $second ) );
		t_eq( 1, count( posts_with_job() ), 'no second post' );
	}
);

t(
	'AC-14,M21',
	'Pause Research stops discovery and retrieval while publishing continues, and the state persists',
	static function () {
		$category = ready_plugin( array( feed_item( 'pause-a' ) ) );
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$queued = queued_article( $category, 'pause-research-ready' );

		$pending = make_job_from_url( $category, ZZZT_MOCK . '/article/pause-waiting', 'Paused Waiting Headline' );

		Automation::pause_research();
		wp_cache_flush();
		t_eq( 1, (int) Automation::state()['paused_research'], 'persisted' );
		t_eq( Automation::PAUSED, Automation::display_state() );

		$types = ZZZCopy\Runner::allowed_types();
		t_false( in_array( Jobs::DISCOVER, $types, true ) );
		t_false( in_array( Jobs::RETRIEVE, $types, true ) );
		t_true( in_array( Jobs::GENERATE, $types, true ), 'prepared articles can finish' );

		Automation::patch( array( 'last_discovery_at' => 0 ) );
		set_local_now( '2026-11-05 10:00:30' );
		ZZZCopy\Runner::tick( 'cli' );

		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $pending ), 'retrieval did not run' );
		t_eq( 0, (int) Automation::get( 'last_discovery_at' ), 'discovery did not run' );
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $queued ), 'publishing still works' );

		Automation::resume( 'research' );
		ZZZCopy\Runner::tick( 'cli' );
		t_true( job_status( $pending ) !== Lifecycle::RETRIEVAL_PENDING, 'resumed' );
	}
);

t(
	'AC-14,M21',
	'Pause Publishing stops post creation while research and AI continue',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$queued = queued_article( $category, 'pause-publish-ready' );

		Automation::pause_publishing();
		$research = make_job_from_url( $category, ZZZT_MOCK . '/article/pause-publish-research', 'Research Keeps Moving Forward' );

		set_local_now( '2026-11-05 10:00:30' );
		Automation::patch( array( 'last_discovery_at' => Clock::now() ) );
		run_ticks( 8 );

		t_eq( Lifecycle::QUEUED, job_status( $queued ), 'not published' );
		t_eq( 0, count( posts_with_job() ) );
		t_eq( Lifecycle::QUEUED, job_status( $research ), 'research and AI filled the queue' );
		t_eq( 2, db_count( 'publishing_queue', "queue_status = 'QUEUED'" ) );

		Automation::resume( 'publishing' );
		Publisher::tick();
		t_eq( 1, count( posts_with_job() ), 'publishes after resume, one per run' );

		// Independence: pausing one does not change the other.
		Automation::pause_research();
		t_eq( 0, (int) Automation::get( 'paused_publishing' ) );
		Automation::pause_publishing();
		Automation::resume( 'research' );
		t_eq( 1, (int) Automation::get( 'paused_publishing' ), 'still paused' );
		t_eq( 0, (int) Automation::get( 'paused_research' ) );
	}
);

t(
	'AC-14,M21',
	'Stop cancels future work, keeps records, and a job claimed earlier does not start new ones',
	static function () {
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		$result = Automation::start();
		t_eq( true, $result );
		ZZZCopy\Runner::tick( 'cli' );

		$queued  = queued_article( $category, 'stop-ready' );
		$waiting = make_job_from_url( $category, ZZZT_MOCK . '/article/stop-waiting', 'Stop Waiting Story Words' );

		Automation::stop();
		t_false( wp_next_scheduled( 'zzzc_tick' ), 'recurring schedule cancelled' );
		t_eq( Automation::STOPPED, Automation::display_state() );

		set_local_now( '2026-11-05 10:00:30' );
		$summary = ZZZCopy\Runner::tick( 'cli' );
		t_false( $summary['ran'], 'worker refuses to run' );
		t_eq( 'stopped', $summary['reason'] );

		t_eq( Lifecycle::QUEUED, job_status( $queued ), 'queued article kept' );
		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $waiting ), 'waiting article kept' );
		t_eq( 0, count( posts_with_job() ) );

		// Start again with no duplicates.
		$before = db_count( 'jobs' );
		t_eq( true, Automation::start() );
		ZZZCopy\Runner::tick( 'cli' );
		t_eq( 1, db_count( 'jobs', "unique_key = 'retrieve:$waiting'" ), 'no duplicate job' );
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $queued ), 'continues' );
		t_eq( 1, count( posts_with_job() ) );
	}
);

t(
	'AC-14',
	'Stop takes effect at the next safe checkpoint during a worker run',
	static function () {
		$category = ready_plugin();
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => time() ) );
		$a = make_job_from_url( $category, ZZZT_MOCK . '/article/checkpoint-a', 'Checkpoint First Story Words' );
		$b = make_job_from_url( $category, ZZZT_MOCK . '/article/checkpoint-b', 'Another Item Entirely Second' );

		// The first job's event triggers Stop, as an administrator pressing it mid-run would.
		$hook = static function ( $value, $option ) {
			return $value;
		};
		add_filter(
			'http_response',
			$stopper = static function ( $response ) {
				Automation::stop();
				return $response;
			}
		);

		$summary = ZZZCopy\Runner::tick( 'cli' );
		remove_filter( 'http_response', $stopper );

		t_eq( 1, $summary['jobs'], 'finishes the step in progress and starts no more' );
		t_true( ( Lifecycle::RETRIEVAL_PENDING === job_status( $a ) ) !== ( Lifecycle::RETRIEVAL_PENDING === job_status( $b ) ), 'one ran, one still waits' );
	}
);

t(
	'M27',
	'Stale running jobs are recovered and shown, and a dead worker is reported',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => time() ) );
		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/stale-worker', 'Stale Worker Story Words' );

		$claimed = Jobs::claim( array( Jobs::RETRIEVE ), 'dead-worker', 600 );
		t_eq( 'retrieve:' . $job, $claimed['unique_key'] );
		t_eq( null, Jobs::claim( array( Jobs::RETRIEVE ), 'other', 600 ), 'leased job cannot be claimed twice' );

		$wpdb->update( Db::table( 'jobs' ), array( 'lease_until' => gmdate( 'Y-m-d H:i:s', time() - 30 ) ), array( 'id' => $claimed['id'] ) );

		t_eq( 1, Health::status()['stale_jobs'], 'visible on the dashboard' );

		t_eq( 1, Jobs::recover_stale(), 'recovered' );
		t_eq( 0, Health::status()['stale_jobs'] );
		t_true( db_count( 'events', "error_code = 'stale_job'" ) > 0, 'logged' );

		run_ticks( 3 );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'finished after recovery' );

		// Recovery of an AI job that was cut off mid-call.
		$ai = make_job_from_url( $category, ZZZT_MOCK . '/article/ai-cutoff', 'Cut Off During Model Call' );
		ZZZCopy\Retrieval::handle( array( 'payload' => array( 'article_job_id' => $ai ), 'attempts' => 1 ) );
		Lifecycle::transition( $ai, Lifecycle::AI_RUNNING );
		t_eq( Lifecycle::AI_RUNNING, job_status( $ai ) );
		run_ticks( 6 );
		t_eq( Lifecycle::QUEUED, job_status( $ai ), 'a job left mid-call resumes' );

		// A worker that just started is not reported as stalled before its first beat.
		delete_option( 'zzzc_heartbeat' );
		Automation::patch( array( 'started_at' => time() ) );
		wp_cache_flush();
		t_false( Health::status()['stale'], 'no false alarm right after Start' );
		Automation::patch( array( 'started_at' => time() - 600 ) );
		t_true( Health::status()['stale'], 'but a worker that never beats is flagged' );

		// Heartbeat.
		update_option( 'zzzc_heartbeat', array( 'at' => time() - 600, 'source' => 'wp-cron' ), false );
		wp_cache_flush();
		t_true( Health::status()['stale'], 'stalled worker is flagged' );
		t_false( Health::status()['server_cron'], 'no server cron seen' );

		Health::beat( 'server' );
		$status = Health::status();
		t_false( $status['stale'] );
		t_true( $status['server_cron'] );
		t_eq( 'server', $status['source'] );

		Automation::stop();
		update_option( 'zzzc_heartbeat', array( 'at' => time() - 6000, 'source' => 'cli' ), false );
		wp_cache_flush();
		t_false( Health::status()['stale'], 'no alarm when stopped on purpose' );
	}
);

t(
	'Jobs',
	'A job that keeps crashing stops at its limit, and only one worker runs at a time',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => time() ) );
		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/crashy', 'Crashy Story Words Here' );

		for ( $i = 0; $i < 3; $i++ ) {
			$row = Jobs::claim( array( Jobs::RETRIEVE ), 'worker', 600 );
			t_true( null !== $row, 'claim ' . $i );
			$wpdb->update( Db::table( 'jobs' ), array( 'lease_until' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $row['id'] ) );
			Jobs::recover_stale();
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'jobs' ) . " SET run_at = %s WHERE status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
		}
		t_eq( null, Jobs::claim( array( Jobs::RETRIEVE ), 'worker', 600 ), 'no more attempts' );
		t_eq( Lifecycle::RETRIEVAL_FAILED, job_status( $job ), 'marked failed' );

		// Single worker.
		$owner = Locks::acquire( 'runner', 60 );
		$summary = ZZZCopy\Runner::tick( 'cli' );
		t_false( $summary['ran'] );
		t_eq( 'another_worker_running', $summary['reason'] );
		Locks::release( 'runner', $owner );

		t_true( Jobs::backoff( 1 ) >= 60 && Jobs::backoff( 1 ) <= 75, 'backoff 1' );
		t_true( Jobs::backoff( 3 ) >= 240 && Jobs::backoff( 3 ) <= 300, 'backoff grows' );
		t_true( Jobs::backoff( 20 ) <= 4500, 'backoff is capped' );
	}
);

t(
	'M21',
	'Retry item and Skip item work and respect the current state',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/blocked', 'Blocked Then Retried Story' );
		run_jobs_only( 3 );
		t_eq( Lifecycle::BLOCKED, job_status( $job ) );

		t_eq( true, Articles::retry( $job ) );
		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $job ) );
		run_ticks( 3 );
		t_eq( Lifecycle::BLOCKED, job_status( $job ), 'source still blocks it, and it is not invented' );

		t_eq( true, Articles::skip( $job ) );
		t_eq( Lifecycle::SKIPPED, job_status( $job ) );
		t_true( is_string( Articles::retry( $job ) ), 'a skipped article cannot be retried' );

		// AI block is respected on retry.
		$failed = make_job_from_url( $category, ZZZT_MOCK . '/article/retry-ai', 'Retry When AI Is Blocked' );
		mock_state( array( 'article_mode' => '403' ) );
		run_jobs_only( 4 );
		t_eq( Lifecycle::AI_FAILED, job_status( $failed ) );
		Automation::block_ai( 'invalid_key', 'Bad key' );
		$answer = Articles::retry( $failed );
		t_true( is_string( $answer ), 'refused' );
		t_contains( 'paused', (string) $answer );
		Automation::clear_ai_block();
		t_eq( true, Articles::retry( $failed ) );
		mock_state( array() );
		run_ticks( 6 );
		t_eq( Lifecycle::QUEUED, job_status( $failed ), 'succeeds after the retry' );

		t_true( is_string( Articles::retry( 99999 ) ), 'unknown id' );
	}
);

t(
	'AC-18,M26',
	'Every pipeline stage and control action is logged, and logs filter and redact',
	static function () {
		set_local_now( '2026-11-05 09:30:00' );
		$category = ready_plugin( array( feed_item( 'logged-story' ) ), 'publish' );
		t_eq( true, Automation::start() );
		Automation::patch( array( 'last_discovery_at' => 0 ) );
		run_ticks( 10 );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();

		foreach ( array( 'control', 'discovery', 'queue', 'retrieval', 'ai', 'validation', 'publishing' ) as $stage ) {
			t_true( db_count( 'events', "stage = '$stage'" ) > 0, "stage $stage logged" );
		}

		$job_id = (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . Db::table( 'article_jobs' ) . ' LIMIT 1' );
		t_true( count( Events::query( array( 'job' => $job_id ) ) ) >= 5, 'by job id' );
		t_true( count( Events::query( array( 'stage' => 'publishing' ) ) ) >= 1, 'by stage' );
		t_eq( array(), Events::query( array( 'severity' => 'error' ) ), 'by severity, no errors here' );
		t_true( count( Events::query( array( 'category_id' => $category ) ) ) >= 1, 'by category' );
		$post = (int) $GLOBALS['wpdb']->get_var( 'SELECT wp_post_id FROM ' . Db::table( 'post_links' ) );
		t_eq( 2, count( Events::query( array( 'post' => $post ) ) ), 'by post id: the post and its featured image' );
		t_true( count( Events::query( array( 'from' => '2026-11-01 00:00:00', 'to' => '2026-11-30 00:00:00' ) ) ) >= 5, 'date range includes' );
		t_eq( 0, count( Events::query( array( 'from' => '2030-01-01 00:00:00' ) ) ), 'date range excludes' );
		t_true( count( Events::query( array( 'status' => 'PUBLISHED' ) ) ) >= 1, 'by status' );

		Events::log( 'ai', 'error', 'Failure with Bearer abcdef123456789 and sk-or-v1-testkey-abcdef123456 inside' );
		$row = Events::query( array( 'severity' => 'error' ), 1 )[0];
		t_not_contains( 'abcdef123456789', $row['message'] );
		t_not_contains( 'sk-or-v1', $row['message'] );
		t_contains( '[REDACTED]', $row['message'] );

		t_eq( "'=SUM(A1)", ZZZCopy\Admin\Actions::csv_safe( '=SUM(A1)' ), 'formula injection blocked in the export' );
		t_eq( 'plain', ZZZCopy\Admin\Actions::csv_safe( 'plain' ) );
	}
);

t(
	'AC-16,M24',
	'The API key never appears in logs, stored responses, usage, jobs or any admin page',
	static function () {
		global $wpdb;
		$secret   = 'sk-or-v1-testkey-abcdef123456';
		$category = ready_plugin();
		t_eq( $secret, ZZZCopy\Secrets::get_api_key() );

		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/secret-scan', 'Secret Scan Story Words' );
		mock_state( array( 'article_mode' => array( '401' ) ) );
		run_jobs_only();
		mock_state( array() );
		Automation::resume( 'all' );
		run_ticks();

		foreach ( array( 'events', 'jobs', 'article_jobs', 'generation_usage', 'source_items', 'article_content', 'publishing_queue' ) as $table ) {
			$dump = json_encode( $wpdb->get_results( 'SELECT * FROM ' . Db::table( $table ), ARRAY_A ) );
			t_not_contains( 'testkey', (string) $dump, $table );
		}

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		foreach ( array( 'dashboard', 'sources', 'ai', 'schedule', 'publishing', 'articles', 'usage', 'logs' ) as $page ) {
			ob_start();
			ZZZCopy\Admin\Pages::$page();
			$html = (string) ob_get_clean();
			t_not_contains( 'testkey', $html, $page . ' page' );
			t_not_contains( 'abcdef123456"', $html, $page . ' page' );
		}

		ob_start();
		ZZZCopy\Admin\Pages::ai();
		$html = (string) ob_get_clean();
		t_contains( '••••••••3456', $html, 'masked hint' );
		t_true( (bool) preg_match( '/id="api_key" name="api_key" value=""/', $html ), 'the field is never filled in' );

		// What the page gives to JavaScript.
		ZZZCopy\Admin\Menu::assets( 'zzz_page_zzzc-ai' );
		$localized = wp_scripts()->get_data( 'zzzc-admin', 'data' );
		t_not_contains( 'testkey', (string) $localized, 'localized script' );
		t_not_contains( 'sk-or', (string) $localized, 'localized script' );
	}
);

t(
	'AC-16,Security',
	'Non-administrators are refused on pages, actions, AJAX and the worker endpoint needs its token',
	static function () {
		$editor = wp_insert_user( array( 'user_login' => 'zzzeditor' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => wp_rand() . '@example.test', 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		t_false( current_user_can( ZZZCopy\Plugin::CAP ), 'editors lack the capability' );

		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'die' ) );
		} );
		add_filter( 'wp_die_ajax_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'die' ) );
		} );

		foreach ( array( 'dashboard', 'ai', 'logs' ) as $page ) {
			$caught = false;
			try {
				ZZZCopy\Admin\Pages::$page();
			} catch ( RuntimeException $e ) {
				$caught = true;
			}
			t_true( $caught, "$page refuses an editor" );
		}

		add_action( 'admin_post_zzzc_stop', array( ZZZCopy\Admin\Actions::class, 'dispatch' ) );
		$caught = false;
		try {
			do_action( 'admin_post_zzzc_stop' );
		} catch ( RuntimeException $e ) {
			$caught = true;
			t_contains( 'permission', $e->getMessage() );
		}
		t_true( $caught, 'action refuses an editor' );

		// Administrator without a valid nonce.
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		$_REQUEST['_wpnonce'] = 'wrong';
		$caught               = false;
		try {
			do_action( 'admin_post_zzzc_stop' );
		} catch ( RuntimeException $e ) {
			$caught = true;
		}
		unset( $_REQUEST['_wpnonce'] );
		t_true( $caught, 'bad nonce refused' );

		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_die_ajax_handler' );

		// Worker endpoint.
		$request = new WP_REST_Request( 'POST', '/zzzc/v1/tick' );
		t_false( RestTick::authorize( $request ), 'no token' );
		$request->set_header( 'X-ZZZC-Token', 'wrong-token' );
		t_false( RestTick::authorize( $request ), 'wrong token' );
		$request->set_header( 'X-ZZZC-Token', RestTick::token() );
		t_true( RestTick::authorize( $request ), 'right token' );
		$old = RestTick::token();
		RestTick::regenerate();
		$request->set_header( 'X-ZZZC-Token', $old );
		t_false( RestTick::authorize( $request ), 'old token stops working' );
		t_true( strlen( RestTick::token() ) >= 40, 'long token' );
		$response = RestTick::handle();
		t_true( $response->get_data()['ok'] );
	}
);

t(
	'Admin',
	'All screens render without PHP warnings, in empty and populated states',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		$render = static function ( string $label ) {
			foreach ( array( 'dashboard', 'sources', 'ai', 'schedule', 'publishing', 'articles', 'usage', 'logs' ) as $page ) {
				ob_start();
				ZZZCopy\Admin\Pages::$page();
				$html = (string) ob_get_clean();
				t_contains( 'InkRush', $html, "$label $page" );
				t_contains( 'zzzc-page-title', $html, "$label $page" );
			}
		};
		$render( 'empty' );

		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job = queued_article( $category, 'admin-render' );
		make_job_from_url( $category, ZZZT_MOCK . '/blocked', 'Blocked For Admin View' );
		run_ticks( 3 );
		$render( 'populated' );

		// Article detail, category edit forms and filtered lists.
		$_GET['view'] = (string) $job;
		ob_start();
		ZZZCopy\Admin\Pages::articles();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );
		t_contains( 'Article record', $html );
		t_contains( 'Request record', $html );
		t_contains( 'Internal diagnostics', $html );
		t_not_contains( '<script', $html, 'no script in the preview' );

		$_GET['edit'] = (string) $category;
		ob_start();
		ZZZCopy\Admin\Pages::sources();
		$html = (string) ob_get_clean();
		unset( $_GET['edit'] );
		t_contains( 'Edit category', $html );

		$_GET['edit'] = 'new';
		ob_start();
		ZZZCopy\Admin\Pages::sources();
		ob_end_clean();
		unset( $_GET['edit'] );

		foreach ( array( 'attention', 'queue', 'PUBLISHED' ) as $status ) {
			$_GET['status'] = $status;
			ob_start();
			ZZZCopy\Admin\Pages::articles();
			ob_end_clean();
		}
		unset( $_GET['status'] );

		// Controls shown match the state.
		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		$html = (string) ob_get_clean();
		t_contains( 'Stop Automation', $html );
		t_contains( 'Pause Research', $html );
		t_contains( 'Pause Publishing', $html );
		t_not_contains( 'Start Automation', $html );

		Automation::pause_research();
		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		$html = (string) ob_get_clean();
		t_contains( 'Resume Research', $html );
		t_contains( '>Resume<', $html );

		Automation::stop();
		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		$html = (string) ob_get_clean();
		t_contains( 'Start Automation', $html );
		t_not_contains( 'Stop Automation', $html );
	}
);

t(
	'Admin',
	'The background refresh reports the current state, so Starting changes to Running without a reload',
	static function () {
		ready_plugin();
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		Automation::patch( array( 'run_state' => Automation::STARTING, 'last_discovery_at' => time() ) );

		$payload = ZZZCopy\Admin\Ajax::status_payload();
		t_eq( 'Starting', $payload['state_label'] );
		t_eq( 'is-starting', $payload['state_class'] );

		ZZZCopy\Runner::tick( 'cli' );

		$payload = ZZZCopy\Admin\Ajax::status_payload();
		t_eq( 'Running', $payload['state_label'], 'updated after the first worker run' );
		t_eq( 'is-running', $payload['state_class'] );
		t_contains( 'Worker', $payload['html'], 'figures are still included' );

		Automation::pause_research();
		t_eq( 'Paused', ZZZCopy\Admin\Ajax::status_payload()['state_label'] );
		Automation::stop();
		t_eq( 'Stopped', ZZZCopy\Admin\Ajax::status_payload()['state_label'] );
	}
);

t(
	'Admin',
	'Timezone is shown next to times on the Schedule page, and a slot overflow warning appears',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ob_start();
		ZZZCopy\Admin\Pages::schedule();
		$html = (string) ob_get_clean();
		t_contains( '10:00 UTC+10:30', $html );
		t_contains( '16:00 UTC+10:30', $html );
		t_contains( '22:00 UTC+10:30', $html );

		Settings::update( array( 'daily_target' => 6 ) );
		ob_start();
		ZZZCopy\Admin\Pages::schedule();
		$html = (string) ob_get_clean();
		t_contains( 'Only 3 of 6 daily slots fit', $html );
	}
);

t(
	'Retention',
	'Daily maintenance applies retention and never touches posts',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();
		$job  = queued_article( $category, 'retention-story' );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		$post = (int) posts_with_job()[0];
		$item = (int) Lifecycle::job( $job )['source_item_id'];
		t_true( '' !== (string) Lifecycle::item( $item )['source_text'], 'text is present' );

		$old = gmdate( 'Y-m-d H:i:s', Clock::now() - 400 * DAY_IN_SECONDS );
		$wpdb->update( Db::table( 'source_items' ), array( 'retrieved_at' => $old ), array( 'id' => $item ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'events' ) . ' SET created_at = %s', $old ) );

		Maintenance::daily();

		t_eq( null, Lifecycle::item( $item )['source_text'], 'source text removed' );
		t_eq( 1, db_count( 'events', "stage = 'maintenance'" ), 'old logs removed, and the run was logged' );
		t_eq( 'draft', get_post_status( $post ), 'the post is untouched' );
		t_eq( 1, db_count( 'post_links' ), 'mapping kept' );
	}
);

t(
	'M28',
	'Deactivation stops scheduled work and keeps everything; uninstall removes data only when chosen',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		set_local_now( '2026-11-05 09:00:00' );
		t_eq( true, Automation::start() );
		ZZZCopy\Runner::tick( 'cli' );
		$job = queued_article( $category, 'deactivate-story' );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		$post = (int) posts_with_job()[0];
		t_true( wp_next_scheduled( 'zzzc_tick' ), 'scheduled' );

		ZZZCopy\Plugin::deactivate();

		t_false( wp_next_scheduled( 'zzzc_tick' ), 'worker schedule cleared' );
		t_false( wp_next_scheduled( 'zzzc_daily_maintenance' ), 'maintenance schedule cleared' );
		t_eq( Automation::STOPPED, Automation::get( 'run_state' ), 'stopped' );
		t_eq( 'draft', get_post_status( $post ), 'post kept' );
		t_eq( 1, db_count( 'article_jobs' ), 'history kept' );
		t_eq( 1, db_count( 'post_links' ), 'mapping kept' );
		t_true( db_count( 'events' ) > 0, 'logs kept' );
		t_true( '' !== Settings::get( 'model_id' ), 'settings kept' );

		$table_exists = static fn() => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::table( 'events' ) ) );

		// Uninstall with the default setting keeps data.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'zzz-copy-das-sobur/zzz-copy-das-sobur.php' );
		}
		include WP_PLUGIN_DIR . '/zzz-copy-das-sobur/uninstall.php';
		t_true( $table_exists(), 'default uninstall keeps tables' );
		t_true( false !== get_option( 'zzzc_settings', false ), 'default uninstall keeps settings' );

		// With the option on, plugin data goes and the post stays.
		Settings::update( array( 'delete_data_on_uninstall' => 1 ) );
		include WP_PLUGIN_DIR . '/zzz-copy-das-sobur/uninstall.php';
		t_false( $table_exists(), 'tables removed' );
		t_eq( false, get_option( 'zzzc_settings', false ), 'settings removed' );
		t_eq( 'draft', get_post_status( $post ), 'the post is never deleted' );

		// Restore for later tests.
		delete_option( 'zzzc_db_version' );
		Settings::flush_cache();
		ZZZCopy\Schema::install();
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'zzzc_daily_maintenance' );
		t_true( $table_exists(), 'reinstall works' );
		wp_delete_post( $post, true );
	}
);

t(
	'M25,AC-17',
	'The plugin source contains no image generation, no other third-party services and no external assets',
	static function () {
		$dir   = WP_PLUGIN_DIR . '/zzz-copy-das-sobur';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		$hosts = array();
		foreach ( $files as $file ) {
			if ( ! preg_match( '/\.(php|js|css)$/', (string) $file ) ) {
				continue;
			}
			$code = (string) file_get_contents( (string) $file );
			foreach ( array( 'media_sideload_image', 'download_url(', 'wp_insert_attachment', 'images/generations', 'dall-e', 'unsplash', 'as_schedule', 'action_scheduler', 'fonts.googleapis', 'cdn.', 'jsdelivr', 'cloudflare', 'composer', 'vendor/autoload' ) as $forbidden ) {
				t_not_contains( $forbidden, $code, basename( (string) $file ) );
			}
			if ( preg_match_all( '#https?://([a-z0-9.\-]+\.[a-z]{2,})#i', $code, $found ) ) {
				foreach ( $found[1] as $host ) {
					$hosts[ strtolower( $host ) ] = true;
				}
			}
		}
		$allowed = array( 'commons.wikimedia.org', 'upload.wikimedia.org', 'creativecommons.org', 'openrouter.ai', 'news.google.com', 'feeds.bbci.co.uk', 'www.theguardian.com', 'techcrunch.com', 'variety.com', 'pagesix.com', 'www.usmagazine.com', 'www.cnbc.com', 'www.sciencedaily.com', 'phys.org', 'www.espn.com', 'medicalxpress.com', 'www.insurancejournal.com', 'www.claimsjournal.com', 'www.defensenews.com', 'warontherocks.com', 'www.cbsnews.com', 'www.eonline.com', 'www.etonline.com', 'www.livescience.com', 'www.iheartpublix.com', 'prefinery.com', 'www.doctorofcredit.com', 'www.outsideonline.com', 'www.polygon.com', 'www.eurogamer.net', 'www.kiplinger.com', 'www.nerdwallet.com', 'www.engadget.com', 'www.cnet.com', 'www.gnu.org' );
		$extra   = array_diff( array_keys( $hosts ), $allowed );
		t_eq( array(), array_values( $extra ), 'only the agreed outside hosts appear in code' );

		t_false( is_dir( $dir . '/vendor' ), 'no bundled third-party code' );
	}
);

t(
	'End to end',
	'From Start to a published post with no admin session, through discovery, AI and slot time',
	static function () {
		$category = ready_plugin( array( feed_item( 'e2e-one', 'Regional transit agency adds night buses' ), feed_item( 'e2e-two', 'Coastal towns prepare harbour repairs plan' ) ), 'publish' );
		set_local_now( '2026-11-05 08:00:00' );
		Settings::update( array( 'category_buffer' => 2 ) );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		t_eq( true, Automation::start() );

		// The administrator leaves. All further work has no user.
		wp_set_current_user( 0 );
		t_eq( 0, get_current_user_id() );

		$feed = mock_state(
			array(
				'feeds' => array(
					'feed' => array(
						feed_item( 'e2e-one', 'Regional transit agency adds night buses' ),
						feed_item( 'e2e-two', 'Coastal towns prepare harbour repairs plan' ),
					),
				),
			)
		);

		run_ticks( 12 );

		t_eq( Automation::RUNNING, Automation::display_state() );
		t_eq( 2, db_count( 'source_items', "status = 'PROMOTED'" ), 'stories started' );
		t_eq( 2, db_count( 'publishing_queue', "queue_status = 'QUEUED'" ), 'articles ready ahead of the slot' );
		t_eq( 0, count( posts_with_job() ), 'nothing published early' );

		set_local_now( '2026-11-05 10:00:20' );
		run_ticks( 3 );

		t_eq( 1, count( posts_with_job() ), 'one post for the first slot' );
		$post = get_post( (int) posts_with_job()[0] );
		t_eq( 'publish', $post->post_status );
		t_contains( 'New report:', $post->post_title );
		t_eq( array( 'Business' ), wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) );

		// Re-reading the same feed creates nothing new.
		set_local_now( '2026-11-05 12:00:00' );
		$before = db_count( 'source_items' );
		Automation::patch( array( 'last_discovery_at' => 0 ) );
		run_ticks( 4 );
		t_eq( $before, db_count( 'source_items' ), 'repeated feed adds nothing' );
		t_eq( 1, count( posts_with_job() ), 'and no extra post' );

		set_local_now( '2026-11-05 16:00:20' );
		run_ticks( 2 );
		t_eq( 2, count( posts_with_job() ), 'second slot' );
		t_eq( 2, Publisher::posts_on_day( '2026-11-05', 'publish' ) );

		t_eq( 4, db_count( 'generation_usage', 'success = 1' ), 'usage for two articles and two tag sets' );
		t_true( ZZZCopy\Usage::totals()['cost_usd'] > 0, 'cost recorded' );
	}
);

t(
	'PLAN-1',
	'Research waits until a slot is close, so news is not chosen today and published tomorrow',
	static function () {
		global $wpdb;
		$discover = "job_type = '" . Jobs::DISCOVER . "'";

		set_local_now( '2026-11-05 05:00:00' );
		$category = ready_plugin();
		Automation::patch( array( 'run_state' => Automation::RUNNING ) );
		Schedule::ensure_slots();

		t_eq( array(), Planner::planned_category_ids(), 'the 10:00 slot is 5 hours away' );
		Planner::tick();
		t_eq( 0, db_count( 'jobs', $discover ), 'nothing is looked for yet' );
		t_eq( 0, (int) Automation::get( 'last_discovery_at', 0 ), 'the discovery timer has not started' );

		set_local_now( '2026-11-05 06:30:00' );
		t_eq( array( $category ), Planner::planned_category_ids(), 'the 10:00 slot is now close' );
		Planner::tick();
		t_true( db_count( 'jobs', $discover ) > 0, 'research starts as soon as the slot is close' );
		t_true( (int) Automation::get( 'last_discovery_at', 0 ) > 0 );

		// The last slot of the day has gone: tomorrow's first slot is too far away.
		$wpdb->update( Db::table( 'slots' ), array( 'status' => 'PUBLISHED' ), array( 'local_date' => '2026-11-05' ) );
		set_local_now( '2026-11-05 23:00:00' );
		Schedule::ensure_slots();
		t_eq( array(), Planner::planned_category_ids(), 'tomorrow is not researched tonight' );

		set_local_now( '2026-11-06 06:30:00' );
		Schedule::ensure_slots();
		t_eq( array( $category ), Planner::planned_category_ids(), 'it is researched tomorrow morning' );

		// A late slot still waiting to catch up counts, however old its time is.
		$wpdb->update( Db::table( 'slots' ), array( 'status' => 'PUBLISHED' ), array( 'local_date' => '2026-11-06' ) );
		$wpdb->update( Db::table( 'slots' ), array( 'status' => 'DEFERRED' ), array( 'local_date' => '2026-11-06', 'slot_index' => 0 ) );
		set_local_now( '2026-11-06 13:00:00' );
		t_eq( array( $category ), Planner::planned_category_ids(), 'a deferred slot' );
	}
);

t(
	'PLAN-2',
	'The research lead time can be changed, and it stays within its limits',
	static function () {
		set_local_now( '2026-11-05 05:00:00' );
		$category = ready_plugin();
		Schedule::ensure_slots();
		t_eq( 4, (int) Settings::get( 'research_lead_hours' ), 'the default' );

		Settings::update( array( 'research_lead_hours' => 6 ) );
		t_eq( array( $category ), Planner::planned_category_ids(), 'a longer lead reaches the 10:00 slot' );

		Settings::update( array( 'research_lead_hours' => 1 ) );
		t_eq( array(), Planner::planned_category_ids(), 'a shorter lead does not' );
	}
);
