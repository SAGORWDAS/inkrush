<?php
use ZZZCopy\Articles;
use ZZZCopy\Automation;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Images\Attribution;
use ZZZCopy\Images\CandidateScorer;
use ZZZCopy\Images\Commons;
use ZZZCopy\Images\ImageHistory;
use ZZZCopy\Images\ImagePipeline;
use ZZZCopy\Images\LicenseChecker;
use ZZZCopy\Images\MediaImporter;
use ZZZCopy\Lifecycle;
use ZZZCopy\Publisher;
use ZZZCopy\Schedule;
use ZZZCopy\Settings;

/**
 * Builds one page of a Commons API reply, pointing at the mock media host.
 */
function cpage( int $id, string $name, array $o = array() ): array {
	$o = array_merge(
		array(
			'w'           => 1600,
			'h'           => 900,
			'seed'        => $name,
			'q'           => 80,
			'kind'        => '',
			'short'       => 'CC BY 4.0',
			'code'        => 'cc-by-4.0',
			'lurl'        => 'https://creativecommons.org/licenses/by/4.0',
			'artist'      => '<a href="https://commons.wikimedia.org/wiki/User:Jane_Doe">Jane Doe</a>',
			'attribution' => 'true',
			'copyrighted' => 'True',
			'mime'        => 'image/jpeg',
			'size'        => 0,
			'description' => 'A view of the harbour.',
			'extra'       => array(),
		),
		$o
	);

	$meta = array(
		'LicenseShortName'    => $o['short'],
		'License'             => $o['code'],
		'LicenseUrl'          => $o['lurl'],
		'Artist'              => $o['artist'],
		'AttributionRequired' => $o['attribution'],
		'Copyrighted'         => $o['copyrighted'],
		'ImageDescription'    => $o['description'],
		'ObjectName'          => $name,
	) + $o['extra'];

	$extmetadata = array();
	foreach ( $meta as $key => $value ) {
		$extmetadata[ $key ] = array( 'value' => $value );
	}

	$file = ZZZT_MOCK . '/commons/file/' . rawurlencode( str_replace( ' ', '_', $name ) ) . '.jpg?' . http_build_query( array( 'w' => $o['w'], 'h' => $o['h'], 'seed' => $o['seed'], 'q' => $o['q'], 'kind' => $o['kind'] ) );

	return array(
		'pageid'    => $id,
		'title'     => 'File:' . $name . '.jpg',
		'imageinfo' => array(
			array(
				'url'            => $o['file_url'] ?? $file,
				'thumburl'       => $o['thumb'] ?? $o['file_url'] ?? $file,
				'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:' . rawurlencode( str_replace( ' ', '_', $name ) ) . '.jpg',
				'width'          => $o['w'],
				'height'         => $o['h'],
				'size'           => $o['size'],
				'mime'           => $o['mime'],
				'extmetadata'    => $extmetadata,
			),
		),
	);
}

function pd_page( int $id, string $name ): array {
	return cpage( $id, $name, array( 'short' => 'Public domain', 'code' => 'pd', 'lurl' => '', 'artist' => '', 'attribution' => 'false', 'copyrighted' => 'False' ) );
}

function cc0_page( int $id, string $name ): array {
	return cpage( $id, $name, array( 'short' => 'CC0', 'code' => 'cc0', 'lurl' => 'https://creativecommons.org/publicdomain/zero/1.0/deed.en', 'artist' => '', 'attribution' => 'false', 'copyrighted' => 'True' ) );
}

/**
 * A plugin ready to publish, with images on. Scoring is covered by its own test, so the minimum is low here.
 */
function images_ready( array $pages, string $mode = 'publish', array $settings = array() ): int {
	$category = ready_plugin( array(), $mode );
	set_local_now( '2026-11-05 09:00:00' );
	Automation::patch( array( 'run_state' => Automation::RUNNING ) );
	Schedule::ensure_slots();
	Settings::update(
		array_merge(
			array(
				'image_min_score' => 1,
			),
			$settings
		)
	);
	mock_state( array( 'commons_pages' => $pages ) );
	return $category;
}

function publish_at_slot( string $time = '10:00:30', string $date = '2026-11-05' ): void {
	set_local_now( "$date $time" );
	Schedule::ensure_slots();
	Publisher::tick();
}

function post_of_job( int $job ): int {
	return Publisher::find_existing_post( Lifecycle::job( $job ) );
}

function image_rows(): array {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Db::table( 'image_history' ) . ' ORDER BY id', ARRAY_A );
}

function queue_row( int $job ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table( 'publishing_queue' ) . ' WHERE article_job_id = %d', $job ), ARRAY_A );
}

function make_due_now( int $job ): void {
	global $wpdb;
	$wpdb->update( Db::table( 'publishing_queue' ), array( 'retry_at' => null ), array( 'article_job_id' => $job ) );
}

/**
 * Makes WordPress treat this as the public page of one article, after its head has been printed.
 */
function view_single( int $post_id ): void {
	global $wp_query, $wp_the_query, $post;
	$wp_query     = new WP_Query( array( 'p' => $post_id, 'post_type' => 'post', 'post_status' => 'any' ) );
	$wp_the_query = $wp_query;
	$wp_query->the_post();
	$GLOBALS['wp_actions']['template_redirect'] = 1;
	$shown = new ReflectionProperty( Attribution::class, 'shown' );
	$shown->setAccessible( true );
	$shown->setValue( null, array() );
}

function view_reset(): void {
	wp_reset_query();
	$GLOBALS['wp_query']->init();
	unset( $GLOBALS['wp_actions']['template_redirect'] );
}

/**
 * Publishes one article whose only image choice is $pages, and checks the result.
 */
function publish_one( array $pages, array $settings = array() ): array {
	$category = images_ready( $pages, 'publish', $settings );
	$job      = queued_article( $category, 'one-' . wp_rand() );
	publish_at_slot();
	return array( $job, post_of_job( $job ) );
}

function assert_no_image_for( array $page, string $reason_part ): void {
	list( $job, $post_id ) = publish_one( array( $page ) );
	t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'the article is held, not published without an image' );
	t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
	t_eq( 0, $post_id, 'no post was made' );
	t_eq( 0, count( mock_requests( 'commons/file' ) ), 'file was never downloaded' );
	$run = ImagePipeline::get_run( $job );
	t_eq( 'none', $run['status'] );
	t_contains( $reason_part, (string) $run['candidates'] );
}

t(
	'IMG-1',
	'A CC BY image is imported, set as featured, verified, and its full credit is stored',
	static function () {
		list( $job, $post_id ) = publish_one( array( cpage( 101, 'Harbour bridge view' ) ) );

		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_eq( 'publish', get_post_status( $post_id ) );
		$attachment = (int) get_post_thumbnail_id( $post_id );
		t_true( $attachment > 0, 'featured image set' );
		t_eq( 'attachment', get_post_type( $attachment ) );
		t_true( wp_attachment_is_image( $attachment ), 'in the Media Library as an image' );
		t_true( (bool) wp_get_attachment_metadata( $attachment ), 'WordPress made its image sizes' );
		t_true( '' !== (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ), 'alt text saved' );

		$rows = image_rows();
		t_eq( 1, count( $rows ) );
		t_eq( ImageHistory::ASSIGNED, $rows[0]['status'] );
		t_eq( 101, (int) $rows[0]['commons_page_id'] );
		t_eq( $post_id, (int) $rows[0]['wp_post_id'] );
		t_eq( $attachment, (int) $rows[0]['attachment_id'] );
		t_eq( 'CC BY 4.0', $rows[0]['license'] );
		t_eq( 'Jane Doe', $rows[0]['creator'] );
		t_eq( 64, strlen( (string) $rows[0]['sha256'] ), 'SHA-256 stored' );
		t_eq( 16, strlen( (string) $rows[0]['phash'] ), 'perceptual hash stored' );

		$credit = get_post_meta( $post_id, Attribution::META, true );
		t_eq( 'Jane Doe', $credit['creator'] );
		t_eq( 'https://commons.wikimedia.org/wiki/User:Jane_Doe', $credit['creator_url'] );
		t_contains( '/licenses/by/4.0', $credit['license_url'] );
		t_contains( 'commons.wikimedia.org/wiki/File:', $credit['commons_url'] );
		t_eq( 'None', $credit['modifications'] );
		t_true( '' !== $credit['downloaded_at'] );

		t_eq( 'assigned', ImagePipeline::get_run( $job )['status'] );
		t_true( count( Events::query( array( 'stage' => 'image' ), 20 ) ) >= 2, 'operations are logged' );
	}
);

t(
	'IMG-2',
	'A verified public domain image is assigned',
	static function () {
		list( , $post_id ) = publish_one( array( pd_page( 102, 'Old harbour engraving photo' ) ) );
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( 'Public domain', image_rows()[0]['license'] );
	}
);

t(
	'IMG-3',
	'A CC0 image is assigned',
	static function () {
		list( , $post_id ) = publish_one( array( cc0_page( 103, 'Harbour at dawn' ) ) );
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( 'CC0', image_rows()[0]['license'] );
		t_contains( 'publicdomain/zero', image_rows()[0]['license_url'] );
	}
);

t(
	'IMG-4',
	'With CC BY-SA turned off, it is rejected, the file is not downloaded, and the article is held',
	static function () {
		Settings::update( array( 'image_allow_share_alike' => 0 ) );
		assert_no_image_for( cpage( 104, 'Harbour share alike', array( 'short' => 'CC BY-SA 4.0', 'code' => 'cc-by-sa-4.0', 'lurl' => 'https://creativecommons.org/licenses/by-sa/4.0' ) ), 'not on the approved list' );
	}
);

t(
	'IMG-5',
	'CC BY-NC is rejected',
	static function () {
		assert_no_image_for( cpage( 105, 'Harbour noncommercial', array( 'short' => 'CC BY-NC 4.0', 'code' => 'cc-by-nc-4.0', 'lurl' => 'https://creativecommons.org/licenses/by-nc/4.0' ) ), 'not on the approved list' );
	}
);

t(
	'IMG-6',
	'CC BY-ND is rejected',
	static function () {
		assert_no_image_for( cpage( 106, 'Harbour noderivs', array( 'short' => 'CC BY-ND 4.0', 'code' => 'cc-by-nd-4.0', 'lurl' => 'https://creativecommons.org/licenses/by-nd/4.0' ) ), 'not on the approved list' );
	}
);

t(
	'IMG-7',
	'Unknown, conflicting, restricted and creator-less licenses are rejected',
	static function () {
		$check = static function ( array $options ) {
			$page = cpage( 1, 'x', $options );
			$meta = array();
			foreach ( $page['imageinfo'][0]['extmetadata'] as $key => $value ) {
				$meta[ $key ] = $value;
			}
			return LicenseChecker::check( $meta );
		};
		t_false( $check( array( 'short' => '', 'code' => '' ) )['ok'], 'no license' );
		t_false( $check( array( 'short' => 'Some custom license', 'code' => 'custom' ) )['ok'], 'unknown license' );
		t_false( $check( array( 'short' => 'GFDL', 'code' => 'gfdl' ) )['ok'], 'GFDL' );
		t_false( $check( array( 'short' => 'Fair use', 'code' => 'fair use' ) )['ok'], 'fair use' );
		t_false( $check( array( 'code' => 'cc-by-sa-4.0' ) )['ok'], 'short name and code disagree' );
		t_false( $check( array( 'lurl' => 'https://creativecommons.org/licenses/by-sa/4.0' ) )['ok'], 'address disagrees' );
		t_false( $check( array( 'lurl' => 'https://evil.example/licenses/by/4.0' ) )['ok'], 'address not on creativecommons.org' );
		t_false( $check( array( 'artist' => '' ) )['ok'], 'CC BY with no creator' );
		t_false( $check( array( 'artist' => 'Unknown author' ) )['ok'], 'CC BY with unknown creator' );
		t_false( $check( array( 'extra' => array( 'Restrictions' => 'trademarked' ) ) )['ok'], 'restricted' );
		t_false( $check( array( 'extra' => array( 'Restrictions' => 'personality' ) ) )['ok'], 'personality rights' );
		t_false( $check( array( 'extra' => array( 'NonFree' => 'true' ) ) )['ok'], 'not free' );
		t_false( $check( array( 'short' => 'Public domain', 'code' => 'pd', 'copyrighted' => 'True', 'lurl' => '' ) )['ok'], 'public domain but copyrighted' );
		t_true( $check( array() )['ok'], 'a correct CC BY passes' );
	}
);

t(
	'IMG-8',
	'A low resolution, portrait, oversized or wrong-type file is rejected',
	static function () {
		$analysis = array( 'main' => '', 'entities' => array(), 'keywords' => array( 'harbour' ), 'concepts' => array(), 'queries' => array() );
		$rule     = static function ( array $options ) use ( $analysis ) {
			$page = cpage( 1, 'Harbour', $options );
			$item = array(
				'page_id'     => 1,
				'title'       => $page['title'],
				'file_url'    => $page['imageinfo'][0]['url'],
				'thumb_url'   => '',
				'page_url'    => '',
				'width'       => $page['imageinfo'][0]['width'],
				'height'      => $page['imageinfo'][0]['height'],
				'size'        => $page['imageinfo'][0]['size'],
				'mime'        => $page['imageinfo'][0]['mime'],
				'extmetadata' => $page['imageinfo'][0]['extmetadata'],
			);
			return CandidateScorer::mandatory_failure( $item, $analysis, 'Business' );
		};
		t_contains( 'Too small', $rule( array( 'w' => 1000, 'h' => 600 ) ) );
		t_contains( 'Too small', $rule( array( 'w' => 1600, 'h' => 600 ) ) );
		t_contains( 'Not a landscape', $rule( array( 'w' => 1600, 'h' => 2000 ) ) );
		t_contains( 'MB limit', $rule( array( 'size' => 30000000 ) ) );
		t_contains( 'file type', $rule( array( 'mime' => 'image/svg+xml' ) ) );
		t_contains( 'file type', $rule( array( 'mime' => 'image/gif' ) ) );
		t_contains( 'approved Wikimedia host', $rule( array( 'file_url' => 'https://evil.example/a.jpg' ) ) );
		t_eq( '', $rule( array() ), 'a good file passes' );

		assert_no_image_for( cpage( 108, 'Tiny harbour', array( 'w' => 800, 'h' => 450 ) ), 'Too small' );
	}
);

t(
	'IMG-9',
	'A previously used image is never reused, even across categories and days',
	static function () {
		$page     = cpage( 109, 'Harbour bridge view' );
		$category = images_ready( array( $page ) );
		$first    = queued_article( $category, 'first-use' );
		publish_at_slot();
		t_true( has_post_thumbnail( post_of_job( $first ) ) );

		global $wpdb;
		$other = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::table( 'categories' ) . " WHERE name <> 'Business' LIMIT 1" );
		$wpdb->update( Db::table( 'categories' ), array( 'enabled' => 1, 'source_mode' => 'feeds', 'feed_urls' => ZZZT_MOCK . '/feed.xml', 'topic_urls' => '' ), array( 'id' => $other ) );

		set_local_now( '2026-11-06 09:00:00' );
		Schedule::ensure_slots();
		$second = queued_article( $other, 'second-use' );
		publish_at_slot( '10:00:30', '2026-11-06' );

		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $second ), 'the second article is held, because its only image was used already' );
		t_eq( 0, post_of_job( $second ), 'no post for it' );
		t_contains( 'permanently recorded', (string) ImagePipeline::get_run( $second )['candidates'] );
		t_eq( 1, count( image_rows() ) );
	}
);

t(
	'IMG-10',
	'After deleting the post and the Media Library file, the image stays reserved until an admin releases it',
	static function () {
		$page                  = cpage( 110, 'Harbour bridge view' );
		list( $job, $post_id ) = publish_one( array( $page ) );
		$attachment            = (int) get_post_thumbnail_id( $post_id );
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment, true );

		$rows = image_rows();
		t_eq( ImageHistory::ASSIGNED, $rows[0]['status'], 'record survives deletion' );

		$category = (int) Lifecycle::job( $job )['category_id'];
		set_local_now( '2026-11-05 15:00:00' );
		$next = queued_article( $category, 'after-delete' );
		publish_at_slot( '16:00:30' );
		t_false( has_post_thumbnail( post_of_job( $next ) ), 'still blocked after deletion' );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		t_true( ImageHistory::release_by_admin( (int) $rows[0]['id'] ) );
		wp_set_current_user( 0 );
		$row = ImageHistory::get( (int) $rows[0]['id'] );
		t_eq( ImageHistory::RELEASED, $row['status'] );
		t_contains( 'Released by administrator', (string) $row['release_log'], 'audit trail' );
		t_contains( 'released image reservation', Events::query( array( 'stage' => 'image' ), 1 )[0]['message'] );

		set_local_now( '2026-11-05 21:00:00' );
		$after = queued_article( $category, 'after-release' );
		delete_transient( 'zzzc_commons_x' );
		publish_at_slot( '22:00:30' );
		t_true( has_post_thumbnail( post_of_job( $after ) ), 'usable again only after release' );
	}
);

t(
	'IMG-11',
	'Two simultaneous jobs: separate PHP processes race, and exactly one gets the image',
	static function () {
		$candidate = array( 'page_id' => 111, 'title' => 'File:Race.jpg', 'file_url' => ZZZT_MOCK . '/commons/file/race.jpg', 'page_url' => 'https://commons.wikimedia.org/wiki/File:Race.jpg' );
		$license   = array( 'creator' => 'A', 'creator_url' => '', 'license' => 'CC0', 'version' => '1.0', 'url' => '' );
		$start     = microtime( true ) + 6;
		$workers   = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$command = array( PHP_BINARY, __DIR__ . '/reserve-worker.php', (string) $start, wp_json_encode( $candidate ), wp_json_encode( $license ), (string) $i );
			$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, __DIR__ );
			t_true( is_resource( $process ), 'worker started' );
			$workers[] = array( $process, $pipes );
		}
		$results = array();
		foreach ( $workers as $worker ) {
			$results[] = trim( (string) stream_get_contents( $worker[1][1] ) );
			proc_close( $worker[0] );
		}
		t_true( microtime( true ) > $start, 'workers really waited for the start time' );
		$winners = array_filter( $results, static fn( $r ) => (int) $r > 0 );
		t_eq( 1, count( $winners ), 'one winner: ' . implode( ',', $results ) );
		foreach ( $results as $result ) {
			t_true( is_numeric( $result ), 'worker output: ' . $result );
		}
		t_eq( 1, count( image_rows() ) );

		// The database itself refuses a second row for the same image, whatever the plugin does.
		global $wpdb;
		$table    = Db::table( 'image_history' );
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (commons_page_id, filename_norm, file_url_hash, status, updated_at) VALUES (111, 'other', 'other', 'reserved', %s)", Clock::mysql() ) );
		$wpdb->suppress_errors( $suppress );
		t_false( $inserted, 'unique key rejects the copy' );
	}
);

t(
	'IMG-12',
	'Corrupted, truncated, flat, redirected and non-image files are refused, and the next file is used',
	static function () {
		$pages = array(
			cpage( 201, 'Harbour file corrupt', array( 'kind' => 'corrupt' ) ),
			cpage( 202, 'Harbour file truncated', array( 'kind' => 'truncated' ) ),
			cpage( 203, 'Harbour file flat', array( 'kind' => 'flat' ) ),
			cpage( 204, 'Harbour file redirect', array( 'kind' => 'redirect' ) ),
			cpage( 205, 'Harbour file webpage', array( 'kind' => 'html' ) ),
			cpage( 206, 'Harbour file good' ),
		);
		// The worker tries at most five files for one article, so these five bad ones use up its turns.
		$category = images_ready( array_slice( $pages, 0, 5 ) );
		$job      = queued_article( $category, 'bad-files' );
		publish_at_slot();

		t_false( has_post_thumbnail( post_of_job( $job ) ), 'no bad file was used' );
		$rows = image_rows();
		t_eq( 5, count( $rows ) );
		foreach ( $rows as $row ) {
			t_eq( ImageHistory::REJECTED, $row['status'], $row['image_title'] . ': ' . $row['status_reason'] );
			t_eq( 0, (int) $row['attachment_id'], 'nothing imported' );
		}
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'the article is held, not published without an image' );
		t_eq( 0, count( posts_with_job() ) );
	}
);

t(
	'IMG-12b',
	'When the best file is corrupted, the next good file is used',
	static function () {
		list( , $post_id ) = publish_one(
			array(
				cpage( 207, 'Harbour bridge one', array( 'kind' => 'corrupt', 'w' => 4000, 'h' => 2250 ) ),
				cpage( 208, 'Harbour bridge two' ),
			)
		);
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( 2, count( image_rows() ) );
		t_eq( ImageHistory::REJECTED, image_rows()[0]['status'] );
		t_eq( ImageHistory::ASSIGNED, image_rows()[1]['status'] );
	}
);

t(
	'IMG-13',
	'A failed download waits and retries without regenerating the article, its tags, or a second post',
	static function () {
		$category = images_ready( array( cpage( 301, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'download-fail' );
		$ai_calls = count( mock_requests() );
		$before   = Lifecycle::content( $job );

		mock_state( array( 'commons_pages' => array( cpage( 301, 'Harbour bridge view' ) ), 'file_mode' => array( '429', 'ok' ) ) );
		publish_at_slot();

		t_eq( Lifecycle::QUEUED, job_status( $job ), 'back in the queue' );
		t_eq( 0, count( posts_with_job() ), 'no post was created' );
		$queue = queue_row( $job );
		t_true( Clock::to_timestamp( $queue['retry_at'] ) >= Clock::now() + 120, 'waits at least as long as Retry-After' );
		t_eq( 0, (int) $queue['attempts'], 'not counted as a publish failure' );
		t_eq( 'waiting', ImagePipeline::get_run( $job )['status'] );
		t_eq( ImageHistory::RELEASED, image_rows()[0]['status'], 'the temporary reservation was given back' );

		// While the wait is on, nothing else runs for this article.
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		t_eq( 1, count( mock_requests( 'commons/file' ) ), 'no download during the wait' );

		make_due_now( $job );
		Publisher::tick();

		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );
		t_eq( 1, count( posts_with_job() ), 'one post' );
		t_eq( 1, count( image_rows() ), 'one image record' );
		t_eq( $ai_calls, count( mock_requests() ), 'no AI request was made' );
		$after = Lifecycle::content( $job );
		t_eq( $before['body'], $after['body'], 'article not regenerated' );
		t_eq( $before['tags_json'], $after['tags_json'], 'tags not regenerated' );
	}
);

t(
	'IMG-13b',
	'A Commons rate limit stops the searches and honors Retry-After; attempts are bounded',
	static function () {
		$category = images_ready( array( cpage( 302, 'Harbour bridge view' ) ), 'publish', array( 'image_max_attempts' => 2 ) );
		$job      = queued_article( $category, 'rate-limit' );
		mock_state( array( 'commons_pages' => array(), 'commons_mode' => '429' ) );

		publish_at_slot();
		t_eq( 1, count( mock_requests( 'commons/api' ) ), 'stopped after the first 429' );
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		t_true( Clock::to_timestamp( queue_row( $job )['retry_at'] ) >= Clock::now() + 120 );

		make_due_now( $job );
		delete_transient( 'zzzc_host_' . md5( '127.0.0.1' ) );
		Publisher::tick();

		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'gives up on the image after the limit, and the article is held' );
		t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
		t_eq( 0, count( posts_with_job() ), 'nothing was published' );
		t_eq( 'none', ImagePipeline::get_run( $job )['status'] );
		t_true( (int) ImagePipeline::get_run( $job )['searches'] >= 1 );
	}
);

t(
	'IMG-14',
	'A failed assignment is logged, the post stays a draft, and Retry image reuses the same file',
	static function () {
		$category = images_ready( array( cpage( 401, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'assign-fail' );

		$break = static function ( $check, $object_id, $key ) {
			return '_thumbnail_id' === $key ? false : $check;
		};
		add_filter( 'update_post_metadata', $break, 10, 3 );
		add_filter( 'add_post_metadata', $break, 10, 3 );
		publish_at_slot();
		remove_filter( 'update_post_metadata', $break, 10 );
		remove_filter( 'add_post_metadata', $break, 10 );

		$post_id = post_of_job( $job );
		t_true( $post_id > 0, 'the draft was made' );
		t_eq( 'draft', get_post_status( $post_id ), 'but it did not go public without its image' );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'the article tries again later' );
		t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
		t_false( has_post_thumbnail( $post_id ) );
		t_eq( '', (string) get_post_meta( $post_id, Attribution::META, true ), 'no credit with no image' );
		t_eq( 'failed', ImagePipeline::get_run( $job )['status'] );
		t_contains( 'did not save the featured image', Events::query( array( 'stage' => 'image', 'severity' => 'error' ), 1 )[0]['message'] );
		$held = image_rows()[0];
		t_eq( ImageHistory::IMPORTED, $held['status'], 'still held for this article' );

		$downloads = count( mock_requests( 'commons/file' ) );
		t_eq( true, ImagePipeline::retry_for_article( $job ) );
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( (int) $held['attachment_id'], (int) get_post_thumbnail_id( $post_id ), 'same file' );
		t_eq( $downloads, count( mock_requests( 'commons/file' ) ), 'not downloaded again' );
		t_eq( ImageHistory::ASSIGNED, image_rows()[0]['status'] );
		t_eq( 1, count( posts_with_job() ) );

		make_due_now( $job );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ), 'with its image, it goes public' );
		t_eq( 'publish', get_post_status( $post_id ) );
		t_eq( 1, count( posts_with_job() ), 'still one post' );
	}
);

t(
	'IMG-14b',
	'With "hold" set, no usable image keeps the article out of public view, and Retry works',
	static function () {
		$category = images_ready( array( cpage( 402, 'Harbour noderivs', array( 'short' => 'CC BY-ND 4.0', 'code' => 'cc-by-nd-4.0', 'lurl' => 'https://creativecommons.org/licenses/by-nd/4.0' ) ) ), 'publish', array( 'image_fallback' => 'hold' ) );
		$job      = queued_article( $category, 'hold-it' );
		publish_at_slot();

		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ) );
		t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
		t_eq( 0, count( posts_with_job() ), 'nothing public' );

		mock_state( array( 'commons_pages' => array( cpage( 403, 'Harbour bridge view' ) ) ) );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_commons%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_commons%'" );
		wp_cache_flush();
		t_eq( true, Articles::retry( $job ) );
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		publish_at_slot( '16:00:30' );

		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );
	}
);

t(
	'IMG-15,17',
	'The credit shows once, directly after the image, with working creator, source and license links',
	static function () {
		list( , $post_id ) = publish_one( array( cpage( 501, 'Harbour bridge view' ) ) );

		view_single( $post_id );
		$html = get_the_post_thumbnail( $post_id, 'large' );
		t_eq( 1, preg_match( '#<img[^>]+>\s*<p class="zzzc-image-credit">#', $html ), 'credit directly follows the image: ' . $html );
		t_contains( 'File photo:', $html );
		t_contains( '<a href="https://commons.wikimedia.org/wiki/User:Jane_Doe"', $html, 'creator link' );
		t_contains( '>Jane Doe</a>', $html );
		t_contains( '<a href="https://commons.wikimedia.org/wiki/File:Harbour_bridge_view.jpg"', $html, 'file page link' );
		t_contains( '>Wikimedia Commons</a>', $html );
		t_contains( '<a href="https://creativecommons.org/licenses/by/4.0"', $html, 'exact license link' );
		t_contains( '>CC BY 4.0</a>', $html );
		t_not_contains( 'Modified:', $html, 'no changes means no change note' );

		t_not_contains( 'zzzc-image-credit', get_the_post_thumbnail( $post_id, 'large' ), 'only once on the page' );
		t_not_contains( 'zzzc-image-credit', apply_filters( 'the_content', '<p>Body</p>' ), 'not repeated in the text' );
		view_reset();
	}
);

t(
	'IMG-15b',
	'A theme or builder that skips the thumbnail filter still shows the credit once, at the top of the text',
	static function () {
		list( , $post_id ) = publish_one( array( cpage( 502, 'Harbour bridge view' ) ) );
		view_single( $post_id );
		$content = apply_filters( 'the_content', '<p>Body</p>' );
		t_eq( 0, strpos( trim( $content ), '<p class="zzzc-image-credit">' ), 'first thing in the text: ' . $content );
		t_not_contains( 'zzzc-image-credit', apply_filters( 'the_content', '<p>Body</p>' ), 'once' );
		view_reset();
	}
);

t(
	'IMG-18',
	'Modification details show when recorded, and values are escaped',
	static function () {
		$html = Attribution::html(
			array(
				'creator'       => '<script>alert(1)</script>',
				'creator_url'   => 'javascript:alert(1)',
				'license'       => 'CC BY 4.0',
				'license_url'   => 'https://creativecommons.org/licenses/by/4.0',
				'commons_url'   => 'https://commons.wikimedia.org/wiki/File:X.jpg',
				'modifications' => 'Cropped <b>to 16:9</b>',
			)
		);
		t_contains( 'Modified: Cropped &lt;b&gt;to 16:9&lt;/b&gt;', $html );
		t_not_contains( '<script', $html );
		t_not_contains( 'javascript:', $html );
	}
);

t(
	'IMG-18b',
	'Creator-less CC0 and public domain credits are honest; "required only" hides them',
	static function () {
		list( , $post_id ) = publish_one( array( pd_page( 503, 'Old harbour engraving photo' ) ) );
		view_single( $post_id );
		$html = get_the_post_thumbnail( $post_id, 'large' );
		t_contains( 'Creator unknown', $html );
		t_contains( 'License: Public domain', $html );
		view_reset();

		Settings::update( array( 'image_attribution' => 'required' ) );
		view_single( $post_id );
		t_not_contains( 'zzzc-image-credit', get_the_post_thumbnail( $post_id, 'large' ) );
		view_reset();
	}
);

t(
	'IMG-19',
	'No credit on blog listings, feeds, other posts, or while the page head is built',
	static function () {
		list( , $post_id ) = publish_one( array( cpage( 504, 'Harbour bridge view' ) ) );
		$other             = wp_insert_post( array( 'post_title' => 'Other', 'post_status' => 'publish', 'post_content' => 'x' ) );
		$thumb             = get_post_thumbnail_id( $post_id );

		// The blog listing page: the image shows, with no credit.
		global $wp_query, $wp_the_query;
		$wp_query     = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 5 ) );
		$wp_the_query = $wp_query;
		$GLOBALS['wp_actions']['template_redirect'] = 1;
		t_false( is_singular() );
		t_not_contains( 'zzzc-image-credit', get_the_post_thumbnail( $post_id, 'medium' ), 'blog listing' );
		view_reset();

		// A related-posts widget on a different article's page.
		view_single( (int) $other );
		t_not_contains( 'zzzc-image-credit', get_the_post_thumbnail( $post_id, 'medium' ), 'someone else\'s thumbnail' );
		view_reset();

		// SEO plugins read the text while the head is printing.
		view_single( $post_id );
		unset( $GLOBALS['wp_actions']['template_redirect'] );
		t_not_contains( 'zzzc-image-credit', apply_filters( 'the_content', '<p>Body</p>' ), 'before the head' );
		view_reset();

		view_single( $post_id );
		$wp_query->is_feed = true;
		t_not_contains( 'zzzc-image-credit', apply_filters( 'the_content', '<p>Body</p>' ), 'feed' );
		view_reset();

		wp_delete_post( (int) $other, true );
		t_true( $thumb > 0 );
	}
);

t(
	'IMG-20',
	'Draft mode: a draft gets the image and credit',
	static function () {
		$category = images_ready( array( cpage( 601, 'Harbour bridge view' ) ), 'draft' );
		$job      = queued_article( $category, 'draft-image' );
		publish_at_slot();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ) );
		$post_id = post_of_job( $job );
		t_eq( 'draft', get_post_status( $post_id ) );
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( 'Jane Doe', get_post_meta( $post_id, Attribution::META, true )['creator'] );
	}
);

t(
	'IMG-21',
	'Automatic mode: the post is not public until its image and credit are in place',
	static function () {
		$category = images_ready( array( cpage( 602, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'auto-image' );

		$seen = array();
		$spy  = static function ( $unused, $object_id, $key ) use ( &$seen ) {
			if ( '_thumbnail_id' === $key ) {
				$seen[] = get_post_status( $object_id );
			}
		};
		add_action( 'added_post_meta', $spy, 10, 3 );
		publish_at_slot();
		remove_action( 'added_post_meta', $spy, 10 );

		t_eq( array( 'draft' ), $seen, 'still a draft when the image was set' );
		t_eq( 'publish', get_post_status( post_of_job( $job ) ) );
	}
);

t(
	'IMG-22',
	'The image is found while the article waits in the queue, and reused at the slot with no second search',
	static function () {
		$category = images_ready( array( cpage( 603, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'wait-for-slot' );
		t_eq( 0, count( mock_requests( 'commons/api' ) ), 'no searches while publishing was paused' );

		set_local_now( '2026-11-05 09:59:00' );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'not due yet' );
		t_eq( 'ready', ImagePipeline::get_run( $job )['status'], 'but its image is ready' );
		t_eq( 0, count( posts_with_job() ), 'and nothing is public' );
		$searches = count( mock_requests( 'commons/api' ) );
		t_true( $searches >= 1 );
		$first = mock_requests( 'commons/api' )[0];
		t_contains( 'ZZZ', $first['ua'], 'identifies itself: ' . $first['ua'] );
		t_contains( 'filetype', urldecode( $first['search'] ) );
		t_eq( '6', $first['ns'], 'file namespace' );

		publish_at_slot();
		t_eq( 'PUBLISHED', job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );
		t_eq( $searches, count( mock_requests( 'commons/api' ) ), 'no second search at the slot' );
	}
);

t(
	'IMG-23',
	'Three posts a day stays three: the daily cap is not used by images, and no search runs once it is met',
	static function () {
		$category = images_ready( array( cpage( 701, 'Harbour bridge one' ), cpage( 702, 'Harbour bridge two' ), cpage( 703, 'Harbour bridge three' ), cpage( 704, 'Harbour bridge four' ) ) );
		$jobs     = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$jobs[] = queued_article( $category, "cap-$i" );
		}
		foreach ( array( '10:00:30', '16:00:30', '22:00:30', '22:40:00', '23:30:00' ) as $time ) {
			publish_at_slot( $time );
		}
		t_eq( 3, count( posts_with_job() ), 'exactly three posts' );
		t_eq( 3, db_count( 'image_runs' ), 'exactly three image runs' );
		$images = 0;
		foreach ( posts_with_job() as $post_id ) {
			$images += has_post_thumbnail( (int) $post_id ) ? 1 : 0;
		}
		t_eq( 3, $images );
		$ids = array_unique( array_column( image_rows(), 'commons_page_id' ) );
		t_eq( 3, count( $ids ), 'three different images' );
	}
);

t(
	'IMG-24',
	'Stop and Pause publishing still hold: no image search runs',
	static function () {
		$category = images_ready( array( cpage( 705, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'paused' );

		Automation::patch( array( 'paused_publishing' => 1 ) );
		publish_at_slot();
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		t_eq( 0, count( mock_requests( 'commons/api' ) ), 'paused' );

		Automation::patch( array( 'paused_publishing' => 0, 'run_state' => Automation::STOPPED ) );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		t_eq( 0, count( mock_requests( 'commons/api' ) ), 'stopped' );
	}
);

t(
	'IMG-25',
	'Crash after the post is created: recovery finishes the same post with the same image',
	static function () {
		$category = images_ready( array( cpage( 801, 'Harbour bridge view' ), cpage( 802, 'Harbour bridge alt' ) ) );
		$job      = queued_article( $category, 'crash' );

		$crash = static function () {
			throw new RuntimeException( 'simulated crash' );
		};
		add_action( 'zzzc_after_post_created', $crash );
		try {
			publish_at_slot();
		} catch ( RuntimeException $e ) {
			t_eq( 'simulated crash', $e->getMessage() );
		}
		remove_action( 'zzzc_after_post_created', $crash );

		t_eq( Lifecycle::PUBLISHING, job_status( $job ) );
		t_eq( 1, count( image_rows() ), 'one image held' );
		$attachment = (int) image_rows()[0]['attachment_id'];

		global $wpdb;
		$wpdb->update( Db::table( 'publishing_queue' ), array( 'lock_expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'article_job_id' => $job ) );
		Publisher::recover_interrupted();

		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_eq( 1, db_count( 'post_links' ), 'one post link' );
		t_eq( 1, count( image_rows() ), 'still one image' );
		t_eq( $attachment, (int) get_post_thumbnail_id( post_of_job( $job ) ), 'same attachment' );
	}
);

t(
	'IMG-26',
	'A crash during download gives the reservation back on the next attempt',
	static function () {
		$category = images_ready( array( cpage( 803, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'crash-early' );
		$page     = cpage( 803, 'Harbour bridge view' );
		$info     = $page['imageinfo'][0];
		ImageHistory::reserve(
			array( 'page_id' => 803, 'title' => $page['title'], 'file_url' => $info['url'], 'page_url' => $info['descriptionurl'] ),
			array( 'creator' => 'Jane Doe', 'creator_url' => '', 'license' => 'CC BY 4.0', 'version' => '4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0' ),
			$job,
			'Interrupted'
		);
		publish_at_slot();
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'the interrupted reservation did not block its own article' );
		t_eq( 1, count( image_rows() ) );
	}
);

t(
	'IMG-27',
	'The same picture under another name is refused: exact copy, and a re-saved copy',
	static function () {
		$category = images_ready( array( cpage( 901, 'Harbour bridge view', array( 'seed' => 'twin' ) ) ) );
		$first    = queued_article( $category, 'twin-one' );
		publish_at_slot();
		t_true( has_post_thumbnail( post_of_job( $first ) ) );

		global $wpdb;
		$clear = static function () use ( $wpdb ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_commons%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_commons%'" );
			wp_cache_flush();
		};

		$clear();
		mock_state( array( 'commons_pages' => array( cpage( 902, 'Harbour exact copy', array( 'seed' => 'twin' ) ) ) ) );
		set_local_now( '2026-11-05 15:00:00' );
		$second = queued_article( $category, 'twin-two' );
		publish_at_slot( '16:00:30' );
		t_false( has_post_thumbnail( post_of_job( $second ) ), 'exact copy refused' );
		t_eq( ImageHistory::REJECTED, image_rows()[1]['status'] );
		t_contains( 'same picture', image_rows()[1]['status_reason'] );

		$clear();
		mock_state( array( 'commons_pages' => array( cpage( 903, 'Harbour resaved copy', array( 'seed' => 'twin', 'q' => 60 ) ) ) ) );
		set_local_now( '2026-11-05 21:00:00' );
		$third = queued_article( $category, 'twin-three' );
		publish_at_slot( '22:00:30' );
		t_false( has_post_thumbnail( post_of_job( $third ) ), 're-saved copy refused by perceptual hash' );
		t_true( image_rows()[2]['sha256'] !== image_rows()[0]['sha256'], 'bytes really differ' );
		t_eq( ImageHistory::REJECTED, image_rows()[2]['status'] );
	}
);

t(
	'IMG-28',
	'Label rules refuse adult, graphic, watermarked, non-photo, children and victim files; score has a floor',
	static function () {
		$analysis = array( 'main' => 'Harbour', 'entities' => array( 'Harbour' ), 'keywords' => array( 'harbour', 'bridge', 'repairs' ), 'concepts' => array( 'port' ), 'queries' => array() );
		$rule     = static function ( string $name, string $description = 'A view.', array $analysis_override = array(), string $category = 'Business' ) use ( $analysis ) {
			$item = array(
				'page_id'     => 1,
				'title'       => "File:$name.jpg",
				'file_url'    => ZZZT_MOCK . '/commons/file/a.jpg',
				'thumb_url'   => '',
				'page_url'    => '',
				'width'       => 1600,
				'height'      => 900,
				'size'        => 100000,
				'mime'        => 'image/jpeg',
				'extmetadata' => array( 'ImageDescription' => array( 'value' => $description ) ),
			);
			return CandidateScorer::mandatory_failure( $item, array_merge( $analysis, $analysis_override ), $category );
		};
		$none = array( 'entities' => array(), 'main' => '' );
		t_contains( 'adult', $rule( 'Nude Yoga pose outdoor' ) );
		t_contains( 'graphic', $rule( 'Road accident', 'A bloody corpse at the scene' ) );
		t_contains( 'watermark', $rule( 'Beach', 'Watermarked stock photo' ) );
		t_contains( 'chart', $rule( 'Company logo' ) );
		t_contains( 'chart', $rule( 'Sales chart 2020' ) );
		t_contains( 'children', $rule( 'School children playing', 'Children at school', $none ) );
		t_contains( 'patients', $rule( 'Hospital patient', 'A patient in a ward', $none ) );
		t_contains( 'people', $rule( 'Portrait of a man', 'Portrait photo', $none ) );
		t_eq( '', $rule( 'Harbour bridge at dawn', 'A view of the bridge.', $none ), 'a plain scene passes' );

		$good  = array( 'title' => 'File:Harbour bridge repairs port.jpg', 'width' => 3200, 'height' => 1800, 'extmetadata' => array() );
		$other = array( 'title' => 'File:Mountain lake.jpg', 'width' => 1300, 'height' => 800, 'extmetadata' => array() );
		$high  = CandidateScorer::score( $good, $analysis );
		$low   = CandidateScorer::score( $other, $analysis );
		t_true( $high['total'] >= 60, 'relevant file scores high: ' . json_encode( $high ) );
		t_true( $low['total'] < 60, 'unrelated file scores low: ' . json_encode( $low ) );
		t_true( $high['total'] <= 100 );

		// A relevant-sounding file below the configured minimum is not used.
		list( $job, $post_id ) = publish_one( array( cpage( 904, 'Mountain lake' ) ), array( 'image_min_score' => 100 ) );
		t_false( has_post_thumbnail( $post_id ) );
		t_contains( 'below the minimum', (string) ImagePipeline::get_run( $job )['candidates'] );
	}
);

t(
	'IMG-29',
	'Old saved settings that allowed a post without an image are ignored: images cannot be turned off',
	static function () {
		$old = static function () {
			update_option( 'zzzc_settings', array_merge( (array) get_option( 'zzzc_settings' ), array( 'images_enabled' => 0, 'image_assign' => 0, 'image_fallback' => 'publish' ) ), false );
			Settings::flush_cache();
		};

		$category = images_ready( array( cpage( 905, 'Harbour noderivs', array( 'short' => 'CC BY-ND 4.0', 'code' => 'cc-by-nd-4.0', 'lurl' => 'https://creativecommons.org/licenses/by-nd/4.0' ) ) ) );
		$old();
		$job = queued_article( $category, 'old-switches' );
		publish_at_slot();
		t_true( count( mock_requests( 'commons/api' ) ) >= 1, 'it still searched' );
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'with no usable image, the article is held' );
		t_eq( 0, count( posts_with_job() ) );

		zzzt_reset();
		$category = images_ready( array( cpage( 906, 'Harbour bridge alt' ) ) );
		$old();
		$job = queued_article( $category, 'old-assign-off' );
		publish_at_slot();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'the image is set as the featured image' );
	}
);

t(
	'IMG-29b',
	'Upgrading removes the old image switches, adds the retry column, and says so',
	static function () {
		global $wpdb;
		$wpdb->query( 'ALTER TABLE ' . Db::table( 'image_runs' ) . ' DROP COLUMN auto_retried' );
		update_option( 'zzzc_settings', array_merge( (array) get_option( 'zzzc_settings' ), array( 'images_enabled' => 0, 'image_assign' => 0, 'image_fallback' => 'publish', 'daily_cap' => 5 ) ), false );
		Settings::flush_cache();
		update_option( 'zzzc_db_version', '7', true );
		ZZZCopy\Schema::maybe_upgrade();

		t_eq( ZZZCopy\Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		$after = (array) get_option( 'zzzc_settings' );
		foreach ( array( 'images_enabled', 'image_assign', 'image_fallback' ) as $key ) {
			t_false( array_key_exists( $key, $after ), "$key removed" );
		}
		t_eq( 5, (int) Settings::get( 'daily_cap' ), 'other settings are kept' );
		t_true( (bool) $wpdb->get_var( 'SHOW COLUMNS FROM ' . Db::table( 'image_runs' ) . " LIKE 'auto_retried'" ) );
		t_contains( 'Every post now needs a featured image', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );
	}
);

t(
	'IMG-30',
	'Admin screens show image settings, tools, history, status and totals with no PHP notices',
	static function () {
		list( $job, $post_id ) = publish_one( array( cpage( 907, 'Harbour bridge view' ) ) );
		wp_set_current_user( $GLOBALS['zzzt_admin'] );

		ob_start();
		ZZZCopy\Admin\Pages::images();
		$html = (string) ob_get_clean();
		t_contains( 'Required on every post', $html );
		foreach ( array( 'images_enabled', 'image_assign', 'image_fallback' ) as $gone ) {
			t_not_contains( 'name="' . $gone . '"', $html, 'no switch to turn images off' );
		}
		foreach ( array( 'image_allow_share_alike', 'image_query_count', 'image_candidates_per_query', 'image_min_width', 'image_min_height', 'image_preferred_ratio', 'image_min_score', 'image_attribution', 'image_max_attempts' ) as $field ) {
			t_contains( 'name="' . $field . '"', $html );
		}
		t_contains( 'zzzc-test-commons', $html );
		t_contains( 'zzzc-image-search', $html );
		t_contains( 'Harbour bridge view', $html, 'history row' );
		t_contains( 'zzzc_release_image', $html );
		t_contains( 'data-confirm', $html, 'release asks for confirmation' );
		t_contains( '_wpnonce', $html );

		$_GET = array();
		ob_start();
		ZZZCopy\Admin\Pages::articles();
		$list = (string) ob_get_clean();
		t_contains( '<th>Image</th>', $list );
		t_contains( 'Jane Doe, CC BY 4.0', $list );
		t_contains( 'zzzc-thumb', $list );

		$_GET = array( 'view' => (string) $job );
		ob_start();
		ZZZCopy\Admin\Pages::articles();
		$detail = (string) ob_get_clean();
		$_GET   = array();
		t_contains( 'Featured image', $detail );
		t_contains( 'Files checked', $detail );

		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		$dash = (string) ob_get_clean();
		t_contains( 'Images added today', $dash );
		t_contains( 'Posts with no image today', $dash );
		t_contains( 'Featured images', $dash );
		t_contains( 'Held: no usable image', $dash );

		ob_start();
		ZZZCopy\Admin\Pages::usage();
		$usage = (string) ob_get_clean();
		t_contains( 'Set as featured', $usage );
		t_contains( 'no AI is used for images', $usage );

		$stats = ImagePipeline::stats();
		t_eq( 1, $stats['assigned'] );
		t_eq( 1, $stats['downloaded'] );
		t_true( $stats['searches'] >= 1 );
		t_true( $stats['checked'] >= 1 );
		wp_set_current_user( 0 );
		t_true( $post_id > 0 );
	}
);

t(
	'IMG-31',
	'Retry image is offered for a post published before images were required, which stays published, and a subscriber cannot use the admin actions',
	static function () {
		global $wpdb;
		list( $job, $post_id ) = publish_one( array( cpage( 908, 'Harbour bridge first' ) ) );
		// Made to look like a post from an earlier version: public, with no image and no image record.
		$old_image = (int) get_post_thumbnail_id( $post_id );
		delete_post_thumbnail( $post_id );
		wp_delete_attachment( $old_image, true );
		$wpdb->query( 'DELETE FROM ' . Db::table( 'image_runs' ) );
		$wpdb->query( 'DELETE FROM ' . Db::table( 'image_history' ) );
		t_false( has_post_thumbnail( $post_id ) );
		t_eq( 'publish', get_post_status( $post_id ), 'it stays published' );
		t_eq( array( $post_id ), array_map( 'intval', array_column( ImagePipeline::posts_without_image(), 'wp_post_id' ) ) );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		$_GET = array();
		ob_start();
		ZZZCopy\Admin\Pages::articles();
		$list = (string) ob_get_clean();
		t_contains( 'zzzc_retry_image', $list );
		ob_start();
		ZZZCopy\Admin\Pages::images();
		$images = (string) ob_get_clean();
		t_contains( 'Published posts with no image', $images );
		t_contains( 'zzzc_retry_image', $images, 'listed on the Images page with its button' );
		ob_start();
		ZZZCopy\Admin\Pages::dashboard();
		t_contains( 'Published posts with no image</dt><dd>1', (string) ob_get_clean() );

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_commons%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_commons%'" );
		wp_cache_flush();
		mock_state( array( 'commons_pages' => array( cpage( 909, 'Harbour bridge view' ) ) ) );
		t_eq( true, ImagePipeline::retry_for_article( $job ) );
		t_true( has_post_thumbnail( $post_id ) );
		t_eq( 'Harbour bridge view.jpg', image_rows()[0]['image_title'] );
		t_contains( 'already has', (string) ImagePipeline::retry_for_article( $job ) );

		$subscriber = wp_create_user( 'zzzsub' . wp_rand(), wp_generate_password(), 'sub' . wp_rand() . '@example.test' );
		wp_set_current_user( $subscriber );
		t_false( current_user_can( ZZZCopy\Plugin::CAP ) );
		wp_set_current_user( 0 );
		wp_delete_user( $subscriber );
	}
);

t(
	'IMG-32',
	'Only approved hosts can supply an image file, and bad replies from the API are handled',
	static function () {
		t_true( Commons::is_media_url( ZZZT_MOCK . '/a.jpg' ) );
		t_false( Commons::is_media_url( 'https://evil.example/a.jpg' ) );
		t_false( MediaImporter::download( array( 'file_url' => 'https://evil.example/a.jpg' ) )['ok'] );

		remove_all_filters( 'zzzc_commons_media_hosts' );
		t_true( Commons::is_media_url( 'https://upload.wikimedia.org/wikipedia/commons/a/a.jpg' ), 'the real media host' );
		t_false( Commons::is_media_url( 'https://upload.wikimedia.org.evil.example/a.jpg' ) );
		t_false( Commons::is_media_url( 'http://169.254.169.254/latest/' ) );
		add_filter( 'zzzc_commons_media_hosts', static fn() => array( '127.0.0.1:8091' ) );

		mock_state( array( 'commons_mode' => 'malformed' ) );
		$bad = Commons::search( 'Malformed reply test' );
		t_false( $bad['ok'] );
		t_true( $bad['temporary'] );

		mock_state( array( 'commons_mode' => '500' ) );
		$down = Commons::search( 'Server error test' );
		t_false( $down['ok'] );
		t_true( $down['temporary'] );

		mock_state( array( 'commons_pages' => array( cpage( 910, 'Harbour bridge view' ) ) ) );
		$ok = Commons::search( 'Cached reply test' );
		t_true( $ok['ok'] );
		t_eq( 1, count( $ok['items'] ) );
		$calls = count( mock_requests( 'commons/api' ) );
		t_true( Commons::search( 'Cached reply test' )['cached'], 'second search comes from the cache' );
		t_eq( $calls, count( mock_requests( 'commons/api' ) ) );
	}
);

t(
	'IMG-33',
	'Article analysis keeps place names, finds the subject, and makes 3 to 5 searches',
	static function () {
		$analysis = ZZZCopy\Images\ArticleAnalyzer::analyze( 'New York City Marathon draws record runners', '<p>The New York City Marathon drew record runners.</p>', array(), 'New York City Marathon draws record runners', 'Fitness' );
		t_eq( 'New York City Marathon', $analysis['main'] );
		t_true( count( $analysis['queries'] ) >= 3 && count( $analysis['queries'] ) <= 5, json_encode( $analysis['queries'] ) );

		$phone = ZZZCopy\Images\ArticleAnalyzer::analyze( 'New iPhone goes on sale', '<p>Apple sells the iPhone.</p>', array(), 'New iPhone goes on sale', 'Technology' );
		t_not_contains( 'New iPhone', (string) $phone['main'], 'a describing word is dropped' );

		t_eq( 65, (int) Settings::defaults()['image_min_score'], 'default minimum score' );
	}
);

t(
	'IMG-34',
	'A file with padding after the picture is accepted, and a download smaller than Commons states is refused',
	static function () {
		list( , $post_id ) = publish_one(
			array(
				cpage( 911, 'Harbour bridge short', array( 'size' => 5000000, 'w' => 4000, 'h' => 2250 ) ),
				cpage( 912, 'Harbour bridge padded', array( 'kind' => 'padded' ) ),
			)
		);
		t_eq( ImageHistory::REJECTED, image_rows()[0]['status'], 'size differs from what Commons stated' );
		t_contains( 'incomplete', image_rows()[0]['status_reason'] );
		t_eq( ImageHistory::ASSIGNED, image_rows()[1]['status'], 'padding is not damage: ' . image_rows()[1]['status_reason'] );
		t_true( has_post_thumbnail( $post_id ) );

		t_true( Commons::is_preview_url( 'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/a.jpg/960px-a.jpg' ), 'preview host' );
		t_false( Commons::is_media_url( 'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/a.jpg/960px-a.jpg' ), 'never a download host' );
		t_false( Commons::is_preview_url( 'https://evil.example/a.jpg' ) );
	}
);

t(
	'IMG-35',
	'A DNS failure during the Commons search waits and retries, and does not end the image attempt',
	static function () {
		$category = images_ready( array( cpage( 921, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'dns-search' );

		$broken = static function () {
			return 'https://no-such-host.invalid/w/api.php';
		};
		add_filter( 'zzzc_commons_api_url', $broken, 20 );
		publish_at_slot();
		remove_filter( 'zzzc_commons_api_url', $broken, 20 );

		t_eq( Lifecycle::QUEUED, job_status( $job ), 'back in the queue' );
		t_eq( 0, count( posts_with_job() ), 'no post without trying again' );
		t_eq( 'waiting', ImagePipeline::get_run( $job )['status'] );

		make_due_now( $job );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'the image was found on the next attempt' );
	}
);

t(
	'IMG-36',
	'A DNS failure during a download gives the image back, and does not block it forever',
	static function () {
		$category = images_ready( array( cpage( 922, 'Harbour bridge view', array( 'file_url' => 'https://no-such-host.invalid/a.jpg' ) ) ) );
		$job      = queued_article( $category, 'dns-download' );

		$hosts = static function ( $list ) {
			return array_merge( (array) $list, array( 'no-such-host.invalid' ) );
		};
		add_filter( 'zzzc_commons_media_hosts', $hosts, 20 );
		publish_at_slot();
		remove_filter( 'zzzc_commons_media_hosts', $hosts, 20 );

		t_eq( ImageHistory::RELEASED, image_rows()[0]['status'], 'not rejected: ' . image_rows()[0]['status_reason'] );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'the article waits' );
		t_eq( 0, count( posts_with_job() ), 'no post yet' );
	}
);

t(
	'IMG-37',
	'Title Case headlines give real names, not the whole headline',
	static function () {
		$analyze = static function ( string $title, string $body ) {
			return ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, array(), $title, 'Business' );
		};

		$title = 'Ryan Gosling Says He Will Star In Next Marvel Film After Long Break';
		$with  = $analyze( $title, '<p>Ryan Gosling said he will star in the next Marvel film. He spoke in Los Angeles.</p><h2>What This Means For Fans Of The Studio</h2><p>The studio has not commented.</p>' );
		t_eq( 'Ryan Gosling', $with['main'] );
		t_eq( 'Ryan Gosling', $with['queries'][0], 'the first search is the subject' );
		t_true( in_array( 'Marvel', $with['entities'], true ), wp_json_encode( $with['entities'] ) );
		foreach ( $with['entities'] as $entity ) {
			t_true( substr_count( $entity, ' ' ) <= 3, 'not a whole headline: ' . $entity );
		}

		$place = $analyze( 'New York City Marathon Draws Record Number Of Runners', '<p>The New York City Marathon drew a record number of runners.</p>' );
		t_eq( 'New York City Marathon', $place['main'], 'a long real name is kept when the text confirms it' );

		$alone = 'Federal Reserve Holds Interest Rates Steady As Inflation Cools';
		t_eq( 'Federal Reserve', $analyze( $alone, $alone )['main'], 'a headline alone keeps its opening name' );

		$sentence = $analyze( 'Ryan Gosling says he will star in the next Marvel film', '<p>The actor spoke on Monday.</p>' );
		t_true( in_array( 'Ryan Gosling', $sentence['entities'], true ), 'ordinary headlines work as before' );
		t_true( in_array( 'Marvel', $sentence['entities'], true ) );
	}
);

t(
	'IMG-38',
	'An ordinary word or a piece of a word cannot make a people photo count as being about the article',
	static function () {
		$rule = static function ( string $description, array $entities ) {
			return CandidateScorer::mandatory_failure(
				array(
					'page_id'     => 1,
					'title'       => 'File:Scene.jpg',
					'file_url'    => ZZZT_MOCK . '/commons/file/a.jpg',
					'thumb_url'   => '',
					'page_url'    => '',
					'width'       => 1600,
					'height'      => 900,
					'size'        => 100000,
					'mime'        => 'image/jpeg',
					'extmetadata' => array( 'ImageDescription' => array( 'value' => $description ) ),
				),
				array( 'main' => '', 'entities' => $entities ),
				'Crime'
			);
		};

		t_contains( 'patients', $rule( 'Patients in a hospital ward', array( 'Patients' ) ), 'the rule word itself does not count' );
		t_contains( 'patients', $rule( 'Police arrest a suspect', array( 'Police' ) ), 'a group word does not count' );
		t_contains( 'people', $rule( 'A high quality portrait of a man', array( 'Ali' ) ), 'a name inside another word does not count' );
		t_eq( '', $rule( 'Ali speaking, portrait', array( 'Ali' ) ), 'the named person still counts' );
		t_eq( '', $rule( 'Ryan Gosling at a premiere, portrait', array( 'Ryan Gosling' ) ), 'a full name still counts' );

		$common = static function ( string $body ) {
			return ZZZCopy\Images\ArticleAnalyzer::analyze( 'Hospital waits grow', $body, array(), 'Hospital waits grow', 'Medical' )['entities'];
		};
		t_false( in_array( 'Patients', $common( '<p>Patients waited. Patients left. Patients returned. Other patients stayed, and more patients came, as patients do.</p>' ), true ), 'also used in lowercase, so an ordinary word' );
		t_true( in_array( 'Target', $common( '<p>Target said it will hire. Target stores open early. Target also said shoppers can target deals.</p>' ), true ), 'a company stays even if its name is also a common word' );

		$score = static function ( string $title, array $analysis ) {
			$blank = array( 'main' => '', 'entities' => array(), 'keywords' => array(), 'concepts' => array() );
			return CandidateScorer::score( array( 'title' => "File:$title.jpg", 'width' => 1600, 'height' => 900, 'extmetadata' => array() ), array_merge( $blank, $analysis ) )['parts'];
		};
		t_eq( 0, $score( 'High quality harbour', array( 'main' => 'Ali' ) )['entity'], 'no credit for a name inside another word' );
		t_eq( 20, $score( 'Ali at the harbour', array( 'main' => 'Ali' ) )['entity'] );
		t_eq( 5, $score( 'Marathon runners', array( 'concepts' => array( 'runner' ) ) )['concept'], 'a plural still matches' );
	}
);

t(
	'IMG-39',
	'While publishing is paused, recovery after a crash does not publish, and it finishes after Resume',
	static function () {
		$category = images_ready( array( cpage( 923, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'crash-paused' );

		$crash = static function () {
			throw new RuntimeException( 'simulated crash' );
		};
		add_action( 'zzzc_after_post_created', $crash );
		try {
			publish_at_slot();
		} catch ( RuntimeException $e ) {
			t_eq( 'simulated crash', $e->getMessage() );
		}
		remove_action( 'zzzc_after_post_created', $crash );

		$post_id = post_of_job( $job );
		t_eq( 'draft', get_post_status( $post_id ), 'left as a draft by the crash' );

		global $wpdb;
		$wpdb->update( Db::table( 'publishing_queue' ), array( 'lock_expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'article_job_id' => $job ) );
		$wpdb->query( 'DELETE FROM ' . Db::table( 'locks' ) );

		Automation::patch( array( 'paused_publishing' => 1 ) );
		Publisher::recover_interrupted();
		t_eq( Lifecycle::PUBLISHING, job_status( $job ), 'recovery waits' );
		t_eq( 'draft', get_post_status( $post_id ), 'not public while paused' );

		ZZZCopy\Runner::tick( 'cli' );
		t_eq( 'draft', get_post_status( $post_id ), 'a full worker run does not publish it either' );

		Automation::patch( array( 'paused_publishing' => 0 ) );
		Publisher::recover_interrupted();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ), 'finished after Resume' );
		t_eq( 'publish', get_post_status( $post_id ) );
		t_eq( 1, count( posts_with_job() ), 'still one post' );
	}
);

t(
	'IMG-40',
	'The credit names the file, including on older posts that saved no title, and the title is escaped',
	static function () {
		list( , $post_id ) = publish_one( array( cpage( 931, 'Harbour bridge view' ) ) );
		$saved             = get_post_meta( $post_id, Attribution::META, true );
		t_true( '' !== $saved['title'], 'the title is kept with the credit' );

		view_single( $post_id );
		t_contains( 'File photo: &ldquo;Harbour bridge view', get_the_post_thumbnail( $post_id, 'large' ) );
		view_reset();

		$base = array(
			'creator'     => 'Jane Doe',
			'license'     => 'CC BY 4.0',
			'license_url' => 'https://creativecommons.org/licenses/by/4.0',
			'commons_url' => 'https://commons.wikimedia.org/wiki/File:Old_Harbour_%C3%89tude.jpg',
		);
		t_contains( '&ldquo;Old Harbour Étude&rdquo;, Jane Doe', Attribution::html( $base ), 'from the address of an older credit' );
		t_contains( '&ldquo;&lt;b&gt;Bold&lt;/b&gt;&rdquo;', Attribution::html( array_merge( $base, array( 'title' => 'File:<b>Bold</b>.jpg' ) ) ), 'escaped' );
	}
);

t(
	'IMG-41',
	'The credit stays when the plugin is deactivated, and shows only once while it runs',
	static function () {
		list( , $post_id ) = publish_one( array( cpage( 932, 'Harbour bridge view' ) ) );
		$raw               = get_post( $post_id )->post_content;
		t_eq( 1, substr_count( $raw, 'zzzc-image-credit-saved' ), 'a copy is in the post text' );
		t_contains( 'File photo:', $raw );
		t_contains( 'creativecommons.org', $raw, 'with its license link' );

		// While the plugin runs: once, below the image.
		view_single( $post_id );
		$thumb   = get_the_post_thumbnail( $post_id, 'large' );
		$content = apply_filters( 'the_content', $raw );
		t_eq( 1, substr_count( $thumb . $content, 'zzzc-image-credit' ), 'once on the page: ' . $thumb . $content );
		view_reset();

		// With the plugin's filters gone, as after deactivating, the text still carries it.
		remove_filter( 'the_content', array( Attribution::class, 'into_content' ), 5 );
		remove_filter( 'post_thumbnail_html', array( Attribution::class, 'after_thumbnail' ), 20 );
		$left = apply_filters( 'the_content', get_post( $post_id )->post_content );
		Attribution::register();
		t_contains( 'File photo:', $left, 'still credited' );
		t_contains( 'Wikimedia Commons', $left );

		// Not left in feeds or excerpts while the plugin runs.
		t_not_contains( 'zzzc-image-credit', apply_filters( 'the_content', $raw ), 'not in other contexts' );

		// Saving again replaces the copy.
		t_true( Attribution::save_to_post( $post_id ) );
		t_eq( 1, substr_count( get_post( $post_id )->post_content, 'zzzc-image-credit-saved' ), 'never two copies' );
	}
);

t(
	'IMG-42',
	'Older posts get the copy on upgrade and deactivation, without changing their dates',
	static function () {
		global $wpdb;
		list( , $post_id ) = publish_one( array( cpage( 933, 'Harbour bridge view' ) ) );

		// Make it look like a post from before 1.3.2.
		$body = preg_replace( '#\s*<p\b[^>]*zzzc-image-credit-saved[^>]*>.*?</p>#is', '', get_post( $post_id )->post_content );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $body, 'post_modified_gmt' => '2026-01-02 03:04:05' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		t_not_contains( 'zzzc-image-credit-saved', get_post( $post_id )->post_content );

		$revisions = count( wp_get_post_revisions( $post_id ) );

		update_option( 'zzzc_db_version', '4', true );
		ZZZCopy\Schema::maybe_upgrade();
		t_eq( ZZZCopy\Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		t_eq( 1, substr_count( get_post( $post_id )->post_content, 'zzzc-image-credit-saved' ), 'added on upgrade' );
		t_eq( '2026-01-02 03:04:05', get_post( $post_id )->post_modified_gmt, 'the modified date is unchanged' );
		t_eq( $revisions, count( wp_get_post_revisions( $post_id ) ), 'no new revision' );

		$wpdb->update( $wpdb->posts, array( 'post_content' => $body ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		ZZZCopy\Plugin::deactivate();
		t_eq( 1, substr_count( get_post( $post_id )->post_content, 'zzzc-image-credit-saved' ), 'added on deactivation' );
		t_eq( 0, Attribution::save_all(), 'nothing left to do' );

		// A license that needs no credit stays out when the owner chose "required only".
		Settings::update( array( 'image_attribution' => 'required' ) );
		$credit            = get_post_meta( $post_id, Attribution::META, true );
		$credit['license'] = 'CC0';
		update_post_meta( $post_id, Attribution::META, $credit );
		t_true( Attribution::save_to_post( $post_id ) );
		t_not_contains( 'zzzc-image-credit-saved', get_post( $post_id )->post_content );
	}
);

/**
 * Clears the wait recorded for the local stand-in for Commons.
 */
function clear_commons_wait(): void {
	delete_transient( 'zzzc_host_' . md5( '127.0.0.1' ) );
}

t(
	'IMG-43',
	'A long wait from Wikimedia is respected: nothing is sent, and a short wait is slept through',
	static function () {
		images_ready( array( cpage( 941, 'Harbour bridge view' ) ) );

		$cached = Commons::search( 'already cached search' );
		t_true( $cached['ok'] );
		t_eq( 1, count( mock_requests( 'commons/api' ) ) );

		ZZZCopy\Http::host_cooldown( '127.0.0.1', 300 );
		$blocked = Commons::search( 'a new search' );
		t_false( $blocked['ok'] );
		t_true( $blocked['temporary'] );
		t_true( $blocked['not_sent'] );
		t_true( $blocked['retry_after'] > 250 && $blocked['retry_after'] <= 300, 'reports the wait: ' . $blocked['retry_after'] );
		t_eq( 1, count( mock_requests( 'commons/api' ) ), 'nothing was sent during the wait' );

		t_true( Commons::search( 'already cached search' )['ok'], 'saved results still work' );
		t_contains( 'Nothing was sent', Commons::test_connection()['message'], 'the admin test says why' );

		ZZZCopy\Http::host_cooldown( '127.0.0.1', 2 );
		$started = time();
		t_true( Commons::search( 'after a short wait' )['ok'], 'a short wait is slept through' );
		t_true( time() - $started >= 1, 'it did wait' );
		t_eq( 2, count( mock_requests( 'commons/api' ) ), 'and then sent the search' );
	}
);

t(
	'IMG-44',
	'An article waits out a long Wikimedia wait without using up an attempt, then gets its image',
	static function () {
		$category = images_ready( array( cpage( 942, 'Harbour bridge view' ) ), 'publish', array( 'image_max_attempts' => 1 ) );
		$job      = queued_article( $category, 'long-wait' );

		ZZZCopy\Http::host_cooldown( '127.0.0.1', 300 );
		publish_at_slot();

		t_eq( Lifecycle::QUEUED, job_status( $job ), 'back in the queue' );
		t_eq( 0, count( mock_requests( 'commons/api' ) ), 'nothing was sent' );
		t_eq( 0, count( posts_with_job() ), 'no post yet' );
		t_true( Clock::to_timestamp( queue_row( $job )['retry_at'] ) >= Clock::now() + 250, 'waits at least that long' );
		$run = ImagePipeline::get_run( $job );
		t_eq( 'waiting', $run['status'] );
		t_eq( 0, (int) $run['attempts'], 'no attempt used' );
		t_eq( 0, (int) $run['searches'], 'no search counted' );

		make_due_now( $job );
		clear_commons_wait();
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'the only attempt was still there to use' );
	}
);

t(
	'IMG-45',
	'A rate limit partway through the searches does not end the image attempt when nothing suitable has been found yet',
	static function () {
		$category = images_ready( array() );
		$job      = queued_article( $category, 'mid-search' );
		mock_state( array( 'commons_pages' => array( cpage( 943, 'Population chart of the city' ) ), 'commons_mode' => array( 'ok', '429' ) ) );

		publish_at_slot();
		t_eq( 2, count( mock_requests( 'commons/api' ) ), 'stopped at the rate limit' );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'the article waits' );
		t_eq( 0, count( posts_with_job() ), 'it did not publish without an image' );
		t_eq( 'waiting', ImagePipeline::get_run( $job )['status'], 'not given up' );
		t_true( Clock::to_timestamp( queue_row( $job )['retry_at'] ) >= Clock::now() + 110, 'honors Retry-After' );

		mock_state( array( 'commons_pages' => array( cpage( 944, 'Harbour bridge view' ) ) ) );
		make_due_now( $job );
		clear_commons_wait();
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'a missed search found the image' );
	}
);

t(
	'IMG-45b',
	'A rate limit after a file already taken waits too, and attempts stay bounded',
	static function () {
		$page     = cpage( 945, 'Harbour bridge view' );
		$info     = $page['imageinfo'][0];
		$category = images_ready( array(), 'publish', array( 'image_max_attempts' => 2 ) );
		$job      = queued_article( $category, 'taken' );
		ImageHistory::reserve(
			array( 'page_id' => 945, 'title' => $page['title'], 'file_url' => $info['url'], 'page_url' => $info['descriptionurl'] ),
			array( 'creator' => 'Jane Doe', 'creator_url' => '', 'license' => 'CC BY 4.0', 'version' => '4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0' ),
			99999,
			'Another article'
		);
		mock_state( array( 'commons_pages' => array( $page ), 'commons_mode' => array( 'ok', '429' ) ) );

		publish_at_slot();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'waits for the missed searches' );
		t_eq( 'waiting', ImagePipeline::get_run( $job )['status'] );
		t_eq( 1, (int) ImagePipeline::get_run( $job )['attempts'] );

		// The rate limit does not clear, so the attempts run out and the article is not stuck.
		mock_state( array( 'commons_pages' => array( $page ), 'commons_mode' => '429' ) );
		make_due_now( $job );
		clear_commons_wait();
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'held, never published without an image' );
		t_eq( 0, count( posts_with_job() ) );
		t_contains( 'did not clear', (string) ImagePipeline::get_run( $job )['reason'] );
	}
);

t(
	'IMG-45c',
	'A good file found before the rate limit is used straight away',
	static function () {
		$category = images_ready( array() );
		$job      = queued_article( $category, 'found-first' );
		mock_state( array( 'commons_pages' => array( cpage( 946, 'Harbour bridge view' ) ), 'commons_mode' => array( 'ok', '429' ) ) );

		publish_at_slot();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );
	}
);

t(
	'IMG-46',
	'A rate limit reported inside a normal reply counts as a rate limit',
	static function () {
		images_ready( array() );
		mock_state( array( 'commons_mode' => 'ratelimited_body' ) );

		$limited = Commons::search( 'rate limited in the reply' );
		t_false( $limited['ok'] );
		t_true( $limited['temporary'] );
		t_true( $limited['retry_after'] >= 60 );
		t_contains( 'exceeded your rate limit', $limited['error'] );
		t_true( Commons::search( 'the next search' )['not_sent'], 'the wait applies to the next search' );
		t_eq( 1, count( mock_requests( 'commons/api' ) ) );
	}
);

/**
 * Page options for a correct CC BY-SA file.
 */
function share_alike( string $version = '4.0' ): array {
	return array( 'short' => "CC BY-SA $version", 'code' => "cc-by-sa-$version", 'lurl' => "https://creativecommons.org/licenses/by-sa/$version" );
}

t(
	'IMG-47',
	'A CC BY-SA image is imported and fully credited, and turning it off later leaves the post as it is',
	static function () {
		t_eq( 1, (int) Settings::get( 'image_allow_share_alike' ), 'on by default' );

		list( $job, $post_id ) = publish_one( array( cpage( 951, 'Harbour bridge view', share_alike() ) ) );
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( $post_id ), 'featured image set' );
		t_eq( 'CC BY-SA 4.0', image_rows()[0]['license'] );

		$credit = get_post_meta( $post_id, Attribution::META, true );
		t_eq( 'CC BY-SA 4.0', $credit['license'] );
		t_contains( '/licenses/by-sa/4.0', $credit['license_url'] );
		t_eq( 'Jane Doe', $credit['creator'] );

		view_single( $post_id );
		$html = get_the_post_thumbnail( $post_id, 'large' );
		t_contains( '>CC BY-SA 4.0</a>', $html, 'names the license' );
		t_contains( 'creativecommons.org/licenses/by-sa/4.0', $html, 'links the license' );
		t_contains( 'Jane Doe', $html );
		view_reset();
		t_contains( 'creativecommons.org/licenses/by-sa/4.0', get_post( $post_id )->post_content, 'the saved copy too' );

		// "Required only" still shows it, because this license requires a credit.
		Settings::update( array( 'image_attribution' => 'required' ) );
		view_single( $post_id );
		t_contains( '>CC BY-SA 4.0</a>', get_the_post_thumbnail( $post_id, 'large' ), 'required credit' );
		view_reset();

		Settings::update( array( 'image_attribution' => 'all', 'image_allow_share_alike' => 0 ) );
		t_true( has_post_thumbnail( $post_id ), 'turning it off leaves published images alone' );
		view_single( $post_id );
		t_contains( '>CC BY-SA 4.0</a>', get_the_post_thumbnail( $post_id, 'large' ), 'and their credit' );
		view_reset();
	}
);

t(
	'IMG-48',
	'CC BY-SA gets the same strict checks as CC BY, and related licenses stay refused',
	static function () {
		$check = static function ( array $options ) {
			return LicenseChecker::check( cpage( 1, 'x', $options )['imageinfo'][0]['extmetadata'] );
		};

		foreach ( array( '1.0', '2.0', '2.5', '3.0', '4.0' ) as $version ) {
			$good = $check( share_alike( $version ) );
			t_true( $good['ok'], "version $version: " . $good['reason'] );
			t_eq( 'by-sa', $good['family'] );
			t_eq( "CC BY-SA $version", $good['license'] );
			t_eq( $version, $good['version'] );
		}
		t_true( $check( array( 'short' => 'CC BY-SA 2.0 uk', 'code' => 'cc-by-sa-2.0-uk', 'lurl' => 'https://creativecommons.org/licenses/by-sa/2.0/uk/' ) )['ok'], 'a country version' );
		t_true( $check( array( 'short' => 'CC-BY-SA-3.0', 'code' => 'cc-by-sa-3.0', 'lurl' => 'https://creativecommons.org/licenses/by-sa/3.0' ) )['ok'], 'written with dashes' );

		t_false( $check( array_merge( share_alike(), array( 'artist' => '' ) ) )['ok'], 'no creator' );
		t_false( $check( array_merge( share_alike(), array( 'artist' => 'Unknown author' ) ) )['ok'], 'unknown creator' );
		t_false( $check( array_merge( share_alike(), array( 'code' => 'cc-by-4.0' ) ) )['ok'], 'name and code disagree' );
		t_false( $check( array_merge( share_alike(), array( 'code' => 'cc-by-sa-3.0' ) ) )['ok'], 'versions disagree' );
		t_false( $check( array_merge( share_alike(), array( 'lurl' => 'https://creativecommons.org/licenses/by/4.0' ) ) )['ok'], 'address is for CC BY' );
		t_false( $check( array_merge( share_alike(), array( 'lurl' => 'https://evil.example/licenses/by-sa/4.0' ) ) )['ok'], 'address not on creativecommons.org' );
		t_false( $check( array_merge( share_alike(), array( 'lurl' => '' ) ) )['ok'], 'no address' );
		t_false( $check( array_merge( share_alike(), array( 'extra' => array( 'Restrictions' => 'trademarked' ) ) ) )['ok'], 'restricted' );
		t_false( $check( array_merge( share_alike(), array( 'extra' => array( 'NonFree' => 'true' ) ) ) )['ok'], 'not free' );

		// A CC BY name cannot borrow a share-alike code or address, or the other way round.
		t_false( $check( array( 'short' => 'CC BY 4.0', 'code' => 'cc-by-sa-4.0' ) )['ok'], 'CC BY name with a share-alike code' );
		t_false( $check( array( 'short' => 'CC BY 4.0', 'lurl' => 'https://creativecommons.org/licenses/by-sa/4.0' ) )['ok'], 'CC BY name with a share-alike address' );

		foreach ( array( 'CC BY-NC-SA 4.0' => 'cc-by-nc-sa-4.0', 'CC BY-SA-NC 4.0' => 'cc-by-sa-nc-4.0', 'CC BY-ND 4.0' => 'cc-by-nd-4.0', 'CC SA 1.0' => 'cc-sa-1.0' ) as $short => $code ) {
			t_false( $check( array( 'short' => $short, 'code' => $code, 'lurl' => 'https://creativecommons.org/licenses/by-sa/4.0' ) )['ok'], $short );
		}

		Settings::update( array( 'image_allow_share_alike' => 0 ) );
		t_false( $check( share_alike() )['ok'], 'refused when turned off' );
		t_contains( 'not on the approved list', $check( share_alike() )['reason'] );
		t_true( $check( array() )['ok'], 'CC BY is not affected by the setting' );
	}
);

t(
	'IMG-49',
	'The people rule still applies to CC BY-SA files',
	static function () {
		assert_no_image_for( cpage( 952, 'Portrait of a man', share_alike() ), 'people' );
	}
);

t(
	'IMG-50',
	'The CC BY-SA box is on the Images page, with its warning about editing',
	static function () {
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ob_start();
		ZZZCopy\Admin\Pages::images();
		$page = ob_get_clean();
		t_contains( 'name="image_allow_share_alike"', $page );
		t_contains( 'Accept CC BY-SA images', $page );
		t_contains( 'Do not edit these images', $page );
		wp_set_current_user( 0 );
	}
);

t(
	'IMG-51',
	'A name written with \'s, or later by surname alone, still searches for the full name',
	static function () {
		$title = 'Russell Brand Case Raises Fresh Questions Over Misogyny and TV Safeguarding';
		$body  = "<p>Concerns about Russell Brand's behaviour were raised inside television long before allegations became public. Workers say their concerns did not lead to action.</p>"
			. "<p>The issue is not only what happened during Brand's television career. Reviews by the BBC, Channel 4 and Banijay UK found gaps.</p>"
			. "<h2>Early warnings did not lead to visible action</h2><p>Naomi Harvey recruited audience members for Big Brother's Big Mouth, the Channel 4 show hosted by Brand. Brand asked a runner for phone numbers. Brand denies wrongdoing, and Brand's lawyers declined to comment.</p>";
		$tags  = array( 'Russell Brand', 'Channel 4', 'safeguarding', 'television' );
		$found = ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, $tags, $title, 'Crime' );
		t_eq( 'Russell Brand', $found['main'], wp_json_encode( $found['entities'] ) );
		t_eq( 'Russell Brand', $found['queries'][0], 'the first search is the full name' );
		t_false( in_array( 'Brand', $found['queries'], true ), wp_json_encode( $found['queries'] ) );
		t_false( in_array( 'Brand', $found['entities'], true ), 'the surname alone is counted toward the full name' );
		t_true( in_array( "Big Brother's Big Mouth", $found['entities'], true ), "'s inside a name is kept" );

		$plain = ZZZCopy\Images\ArticleAnalyzer::analyze( 'Collins defends her vote', '<p>Susan Collins said the vote will wait. Collins spoke in Maine.</p>', array(), 'Collins defends her vote', 'Politics' );
		t_eq( 'Susan Collins', $plain['main'], wp_json_encode( $plain['entities'] ) );
	}
);

/**
 * Gives a queued article known text, so the searches it makes are known.
 */
function set_article_text( int $job, string $title, string $body, array $tags ): void {
	global $wpdb;
	$wpdb->update( Db::table( 'article_content' ), array( 'title' => $title, 'body' => $body, 'tags_json' => wp_json_encode( $tags ) ), array( 'article_job_id' => $job ) );
	$wpdb->update( Db::table( 'source_items' ), array( 'headline' => $title ), array( 'id' => (int) Lifecycle::job( $job )['source_item_id'] ) );
}

t(
	'IMG-52',
	'A resized copy is downloaded when Commons offers one, and the admin preview uses a small standard width',
	static function () {
		$thumb = ZZZT_MOCK . '/commons/file/thumb/1920px-Harbour_big.jpg?' . http_build_query( array( 'w' => 1920, 'h' => 1080, 'seed' => 'big' ) );
		list( $job, $post_id ) = publish_one( array( cpage( 951, 'Harbour bridge big', array( 'w' => 6000, 'h' => 3375, 'size' => 5600000, 'thumb' => $thumb ) ) ) );

		t_true( has_post_thumbnail( $post_id ), 'used, although the stated byte size is for the original' );
		$files = mock_requests( 'commons/file' );
		t_eq( 1, count( $files ) );
		t_contains( '1920px-', (string) $files[0]['uri'], 'the resized copy was downloaded' );
		t_eq( 1920, (int) wp_get_attachment_metadata( get_post_thumbnail_id( $post_id ) )['width'] );
		t_contains( 'w=6000', (string) image_rows()[0]['file_url'], 'the history keeps the original address, so reuse is still caught' );
		t_contains( '330px-', (string) ImagePipeline::get_run( $job )['candidates'], 'the preview is a small standard width' );

		// A file no wider than the copy is downloaded as it is, and its stated size is still checked.
		zzzt_reset();
		list( , $small ) = publish_one( array( cpage( 952, 'Harbour bridge padded size', array( 'kind' => 'padded', 'size' => 999999 ) ) ) );
		t_false( has_post_thumbnail( $small ), 'an original whose size differs from the stated size is still refused' );
	}
);

t(
	'IMG-53',
	'Searches ask Commons only for large enough files, and stop once a strong match is found',
	static function () {
		$title = 'Sydney Harbour Bridge reopens after repairs';
		$body  = '<p>The Sydney Harbour Bridge reopens to traffic after repairs. Repairs took a year. Traffic is back on the bridge.</p>';
		$tags  = array( 'Sydney Harbour Bridge', 'repairs' );

		$category = images_ready( array( cpage( 961, 'Sydney Harbour Bridge reopens', array( 'description' => 'The Sydney Harbour Bridge reopens to traffic after repairs.' ) ) ) );
		$job      = queued_article( $category, 'strong-match' );
		set_article_text( $job, $title, $body, $tags );
		publish_at_slot();

		$searches = mock_requests( 'commons/api' );
		t_eq( 1, count( $searches ), 'one search was enough' );
		$sent = urldecode( (string) $searches[0]['search'] );
		t_contains( 'filew:>1199', $sent );
		t_contains( 'fileh:>674', $sent );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );
		t_contains( 'stopped after 1 of', implode( ' ', array_column( Events::query( array( 'stage' => 'image' ), 20 ), 'message' ) ) );

		// A weak match does not stop the searching.
		zzzt_reset();
		$category = images_ready( array( cpage( 962, 'Harbour at night', array( 'description' => 'Boats at night.' ) ) ) );
		$job      = queued_article( $category, 'weak-match' );
		set_article_text( $job, $title, $body, $tags );
		publish_at_slot();
		t_true( count( mock_requests( 'commons/api' ) ) >= 3, 'all the searches ran: ' . count( mock_requests( 'commons/api' ) ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'a weaker file that passes is still used' );

		// A changed minimum size changes the filter.
		zzzt_reset();
		$category = images_ready( array( cpage( 963, 'Sydney Harbour Bridge reopens', array( 'description' => 'The Sydney Harbour Bridge reopens.', 'w' => 2000, 'h' => 1125 ) ) ), 'publish', array( 'image_min_width' => 1600, 'image_min_height' => 900 ) );
		$job      = queued_article( $category, 'bigger-minimum' );
		set_article_text( $job, $title, $body, $tags );
		publish_at_slot();
		t_contains( 'filew:>1599 fileh:>899', urldecode( (string) mock_requests( 'commons/api' )[0]['search'] ) );
	}
);

t(
	'IMG-54',
	'The AI tags confirm names: a cleaner tag becomes the subject, and a single word is searched alone only when it is a tag',
	static function () {
		$analyze = static function ( string $title, string $body, array $tags ) {
			return ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, $tags, $title, 'Entertainment' );
		};

		$titled = $analyze( 'Comedian Russell Brand Speaks Out', '<p>Comedian Russell Brand spoke on Monday. Brand said he would appeal.</p>', array( 'Russell Brand', 'comedy' ) );
		t_eq( 'Russell Brand', $titled['main'], wp_json_encode( $titled['entities'] ) );
		t_eq( 'Russell Brand', $titled['queries'][0] );

		$case = $analyze(
			'Russell Brand Case Raises Fresh Questions Over Misogyny and TV Safeguarding',
			"<p>Concerns about Russell Brand's behaviour were raised long ago. Reviews by the BBC, Channel 4 and Banijay UK found gaps.</p><p>Brand hosted a Channel 4 show, and Channel 4 said it had acted.</p>",
			array( 'Russell Brand', 'Channel 4', 'safeguarding' )
		);
		t_false( in_array( 'Channel', $case['queries'], true ), 'a word cut from "Channel 4" is not searched alone: ' . wp_json_encode( $case['queries'] ) );
		t_false( in_array( 'Russell Brand Channel', $case['queries'], true ) );

		$title = 'Ryan Gosling Says He Will Star In Next Marvel Film After Long Break';
		$body  = '<p>Ryan Gosling said he will star in the next Marvel film. He spoke in Los Angeles.</p><p>The studio has not commented.</p>';
		t_true( in_array( 'Marvel', $analyze( $title, $body, array( 'Marvel', 'film' ) )['queries'], true ), 'a single word that is also a tag is searched' );
		t_false( in_array( 'Marvel', $analyze( $title, $body, array() )['queries'], true ), 'not without the tag' );
	}
);

/**
 * Forgets saved Commons search results, as 6 hours passing would.
 */
function clear_commons_results(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zzzc\\_commons%' OR option_name LIKE '\\_transient\\_timeout\\_zzzc\\_commons%'" );
	wp_cache_flush();
}

function image_errors(): string {
	return implode( ' | ', array_column( Events::query( array( 'severity' => 'error' ), 50 ), 'message' ) );
}

t(
	'IMG-55',
	'A post from this plugin cannot go public by hand, or on a schedule, without a featured image that loads. Other posts are not affected',
	static function () {
		$category = images_ready( array( cpage( 955, 'Harbour bridge view' ) ), 'draft' );
		$job      = queued_article( $category, 'by-hand' );
		publish_at_slot();
		$post_id = post_of_job( $job );
		$image   = (int) get_post_thumbnail_id( $post_id );
		t_eq( 'draft', get_post_status( $post_id ) );
		t_true( $image > 0, 'the draft has its image' );

		delete_post_thumbnail( $post_id );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		t_eq( 'draft', get_post_status( $post_id ), 'publishing it by hand with no image keeps it a draft' );
		t_contains( 'kept as a draft', image_errors() );

		set_post_thumbnail( $post_id, $image );
		$file = (string) get_attached_file( $image );
		rename( $file, $file . '.gone' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		t_eq( 'draft', get_post_status( $post_id ), 'an image whose file is gone from the server does not count' );
		t_contains( 'missing from the server', image_errors() );
		rename( $file . '.gone', $file );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		t_eq( 'publish', get_post_status( $post_id ), 'with a working image it goes public' );

		// Editing a post that is already public leaves it public, such as a post from before this version.
		delete_post_thumbnail( $post_id );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited title' ) );
		t_eq( 'publish', get_post_status( $post_id ) );

		$scheduled = wp_insert_post( array( 'post_title' => 'Scheduled', 'post_type' => 'post', 'post_status' => 'future', 'post_date' => '2030-01-01 10:00:00', 'post_content' => 'Text', 'post_content_filtered' => 'zzzc:scheduled-test' ) );
		wp_publish_post( $scheduled );
		t_eq( 'draft', get_post_status( $scheduled ), 'WordPress does not publish a scheduled one with no image either' );

		$own = wp_insert_post( array( 'post_title' => 'The owner\'s own post', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'Text' ) );
		t_eq( 'publish', get_post_status( $own ), 'posts not made by this plugin are not touched' );
		wp_delete_post( $own, true );
	}
);

t(
	'IMG-56',
	'If the featured image file is gone at publishing, the post stays a draft, and it goes out once the file is back',
	static function () {
		$category = images_ready( array( cpage( 956, 'Harbour bridge view' ) ) );
		$job      = queued_article( $category, 'file-gone' );
		set_local_now( '2026-11-05 09:30:00' );
		Publisher::tick();
		t_eq( 'ready', ImagePipeline::get_run( $job )['status'], 'the image was imported ahead of the slot' );

		$gone = static function ( $file ) {
			return $file . '.gone';
		};
		add_filter( 'get_attached_file', $gone );
		publish_at_slot();
		remove_filter( 'get_attached_file', $gone );

		$post_id = post_of_job( $job );
		t_eq( 'draft', get_post_status( $post_id ), 'not public' );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'it tries again' );
		t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
		t_contains( 'missing from the server', (string) Lifecycle::job( $job )['error_message'] );

		make_due_now( $job );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ), 'with the file back, it publishes' );
		t_eq( 'publish', get_post_status( $post_id ) );
		t_eq( 1, count( posts_with_job() ) );
	}
);

t(
	'IMG-57',
	'A slot takes the article whose image is ready, and the next one gets its image straight after',
	static function () {
		$category = images_ready( array( cpage( 957, 'Harbour bridge one' ), cpage( 958, 'Harbour bridge two' ) ) );
		$older    = queued_article( $category, 'older-ready' );
		set_local_now( '2026-11-05 09:30:00' );
		Publisher::tick();
		t_eq( 'ready', ImagePipeline::get_run( $older )['status'] );

		$newer = queued_article( $category, 'newer-no-image-yet' );
		t_eq( null, ImagePipeline::get_run( $newer ) );
		publish_at_slot();
		t_eq( Lifecycle::PUBLISHED, job_status( $older ), 'the article with its image ready goes first' );
		t_eq( Lifecycle::QUEUED, job_status( $newer ) );
		t_eq( 'ready', ImagePipeline::get_run( $newer )['status'], 'the next one gets its image straight after' );
	}
);

t(
	'IMG-58',
	'A held article gets one more image search 6 hours later, and only one',
	static function () {
		$category = images_ready( array() );
		$job      = queued_article( $category, 'retry-later' );
		set_local_now( '2026-11-05 09:30:00' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'held before its slot' );
		t_eq( 'image_required', Lifecycle::job( $job )['error_code'] );
		t_contains( 'runs once more in 6 hours', image_errors() );
		t_eq( 0, count( posts_with_job() ) );
		t_eq( 1, ImagePipeline::held_count() );

		set_local_now( '2026-11-05 15:00:00' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'not before 6 hours' );

		mock_state( array( 'commons_pages' => array( cpage( 959, 'Harbour bridge view' ) ) ) );
		clear_commons_results();
		set_local_now( '2026-11-05 15:31:00' );
		Publisher::tick();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'searched again 6 hours later' );
		t_eq( 'ready', ImagePipeline::get_run( $job )['status'], 'and found an image' );
		t_eq( 1, (int) ImagePipeline::get_run( $job )['auto_retried'] );
		publish_at_slot( '16:00:30' );
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		t_true( has_post_thumbnail( post_of_job( $job ) ) );

		// Held a second time, it waits for a person.
		zzzt_reset();
		$category = images_ready( array() );
		$job      = queued_article( $category, 'retry-once' );
		set_local_now( '2026-11-05 09:30:00' );
		Publisher::tick();
		clear_commons_results();
		set_local_now( '2026-11-05 15:31:00' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'held again' );
		set_local_now( '2026-11-05 22:00:00' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ), 'no second automatic retry' );
		t_eq( 1, db_count( 'events', "message LIKE 'A held article gets its one automatic image search%'" ) );
		t_eq( true, ZZZCopy\Articles::retry( $job ), 'a person can still retry it' );
	}
);

t(
	'IMG-59',
	'When a strong match ends the searching but its file cannot be used, the other searches run and their file is used',
	static function () {
		$title  = 'Sydney Harbour Bridge reopens after repairs';
		$body   = '<p>The Sydney Harbour Bridge reopens to traffic after repairs. Repairs took a year. Traffic is back on the bridge.</p>';
		$strong = cpage( 971, 'Sydney Harbour Bridge reopens', array( 'description' => 'The Sydney Harbour Bridge reopens to traffic after repairs.', 'kind' => 'corrupt' ) );
		$other  = cpage( 972, 'Harbour at night', array( 'description' => 'Boats at night.' ) );

		$category = images_ready( array( $other ) );
		mock_state( array( 'commons_pages' => array( $other ), 'commons_by_search' => array( 'Sydney Harbour Bridge' => array( $strong ) ) ) );
		$job = queued_article( $category, 'strong-but-broken' );
		set_article_text( $job, $title, $body, array( 'Sydney Harbour Bridge', 'repairs' ) );
		publish_at_slot();

		$log = implode( ' | ', array_column( Events::query( array( 'stage' => 'image' ), 30 ), 'message' ) );
		t_contains( 'stopped after 1 of', $log );
		t_contains( 'remaining searches run now', $log );
		t_true( count( mock_requests( 'commons/api' ) ) >= 2, 'the other searches ran' );
		t_true( has_post_thumbnail( post_of_job( $job ) ), 'and their file was used' );
		$assigned = array_values( array_filter( image_rows(), static function ( $row ) {
			return ImageHistory::ASSIGNED === $row['status'];
		} ) );
		t_eq( 972, (int) $assigned[0]['commons_page_id'] );
	}
);

t(
	'IMG-60',
	'The news outlet that published or is quoted is not the subject of the image searches',
	static function () {
		$title = 'Man arrested for attempted murder after zip-tying, stabbing roommate in Toshima apartment';
		$body  = '<p>According to Nippon News Network, Taro Exampleson, 34, tied up his roommate and stabbed him in their apartment in Toshima Ward. Nippon News Network reported that Exampleson admitted the allegations.</p>'
			. '<p>The Tokyo Reporter said Tokyo Metropolitan Police arrested Taro Exampleson on Thursday. Nippon News Network said the roommate survived. The Tokyo Reporter added that police are investigating.</p>';
		$tags  = array( 'The Tokyo Reporter', 'Nippon News Network', 'Taro Exampleson', 'Toshima', 'stabbing', 'roommate' );
		$found = ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, $tags, $title, 'Crime', 'The Tokyo Reporter' );
		$all   = wp_json_encode( array( $found['main'], $found['entities'], $found['queries'] ) );
		t_not_contains( 'Nippon News Network', $all, 'a quoted outlet is not searched' );
		t_not_contains( 'Tokyo Reporter', $all, 'the publisher is not searched' );
		t_true( in_array( $found['main'], array( 'Taro Exampleson', 'Toshima Ward' ), true ), 'the subject is the person or the place: ' . $all );
		t_eq( $found['main'], $found['queries'][0] );
		t_false( in_array( 'Man', $found['entities'], true ), 'the word that starts the headline is not a name: ' . $all );

		// Without the publisher's name, an outlet's own name that ends like one is still left out.
		$unnamed = ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, array(), $title, 'Crime' );
		t_not_contains( 'Nippon News Network', wp_json_encode( $unnamed['queries'] ) );

		// A story about an outlet still searches for it, because the headline names it.
		$about = ZZZCopy\Images\ArticleAnalyzer::analyze( 'Fox News host leaves the network', '<p>Fox News said the host will leave. Fox News did not say why.</p>', array( 'Fox News' ), 'Fox News host leaves the network', 'Entertainment', 'Fox News' );
		t_eq( 'Fox News', $about['main'], wp_json_encode( $about['entities'] ) );
	}
);

t(
	'IMG-61',
	'Words that begin a sentence and plain numbers are not searched as names',
	static function () {
		$analyze = static function ( string $title, string $body ) {
			return ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, array(), $title, 'Business' );
		};

		$shop = $analyze( 'Is Poundland closing its stores? 10,000 jobs at risk', '<p>Is Poundland closing? Poundland said 10,000 jobs are safe, and 10,000 more may follow. Gordon Brothers bought Poundland stores in 2025.</p>' );
		t_eq( 'Poundland', $shop['main'], wp_json_encode( $shop['entities'] ) );
		foreach ( $shop['queries'] as $query ) {
			t_false( 0 === strpos( $query, 'Is ' ), 'no search starts with "Is": ' . $query );
			t_false( (bool) preg_match( '/(^|\s)\d+(\s|$)/', $query ), 'no plain number is searched: ' . $query );
		}

		$apps = $analyze( 'The Duo apps are coming', '<p>The apps are coming, says Apple. What the apps will do is not known. Apple said more is coming. Apple did not give a date.</p>' );
		$all  = wp_json_encode( array( $apps['entities'], $apps['queries'] ) );
		t_not_contains( 'What', $all, 'a sentence after a name is not part of it' );
		t_true( in_array( 'Apple', $apps['entities'], true ), $all );

		$next = $analyze( 'The Duo apps are coming', '<p>The apps are coming, says Apple. Customers will have to wait. Apple said more is coming. Apple did not give a date.</p>' );
		t_not_contains( 'Customers', wp_json_encode( array( $next['entities'], $next['queries'] ) ), 'the next sentence is not joined to the name' );

		$asked = $analyze( 'The Duo apps are coming', '<p>Reporters asked Apple What comes next. Apple said more is coming. Apple did not give a date.</p>' );
		t_not_contains( 'What', wp_json_encode( array( $asked['entities'], $asked['queries'] ) ), 'a sentence word at the end of a name is dropped' );

		t_eq( 'Will Smith', $analyze( 'Will Smith returns to the stage', '<p>Will Smith returned to the stage on Monday.</p>' )['main'], 'a name that begins like a sentence word is kept' );
	}
);

t(
	'IMG-62',
	'At least one search is not about the main name, so a wrong subject cannot use up every search',
	static function () {
		$title = 'Taro Exampleson arrested over apartment stabbing';
		$body  = '<p>Taro Exampleson was arrested by Tokyo Metropolitan Police in Toshima Ward. Tokyo Metropolitan Police said Taro Exampleson stabbed a roommate in Toshima Ward.</p>';
		$found = ZZZCopy\Images\ArticleAnalyzer::analyze( $title, $body, array( 'Taro Exampleson', 'Tokyo Metropolitan Police' ), $title, 'Crime' );
		$all   = wp_json_encode( array( $found['entities'], $found['queries'] ) );
		t_eq( 'Taro Exampleson', $found['queries'][0], $all );
		t_eq( (int) Settings::get( 'image_query_count' ), count( $found['queries'] ) );
		t_true( in_array( 'Toshima Ward', $found['queries'], true ), 'the place is searched too: ' . $all );

		// With no other name, plain words from the headline are searched instead.
		$alone = ZZZCopy\Images\ArticleAnalyzer::analyze( $title, '<p>Taro Exampleson was arrested by Tokyo Metropolitan Police. Tokyo Metropolitan Police said Taro Exampleson stabbed a roommate.</p>', array( 'Taro Exampleson', 'Tokyo Metropolitan Police' ), $title, 'Crime' );
		$other = array_filter(
			$alone['queries'],
			static function ( $query ) {
				return false === stripos( $query, 'Taro Exampleson' ) && false === stripos( $query, 'Tokyo Metropolitan Police' );
			}
		);
		t_true( count( $other ) >= 1, 'a search without either name: ' . wp_json_encode( $alone['queries'] ) );
	}
);

t(
	'IMG-63',
	'The publisher\'s name is not searched in the real image step, and a no-image log gives the best score reached',
	static function () {
		global $wpdb;
		$title = 'Sydney Harbour Bridge reopens after repairs';
		$body  = '<p>Harbour Insider reported that the Sydney Harbour Bridge reopens to traffic after repairs. Harbour Insider said repairs took a year.</p>';

		$category = images_ready( array( cpage( 981, 'Field of flowers', array( 'description' => 'Flowers in a field.' ) ) ), 'publish', array( 'image_min_score' => 99 ) );
		$job      = queued_article( $category, 'outlet-and-score' );
		set_article_text( $job, $title, $body, array( 'Harbour Insider', 'Sydney Harbour Bridge' ) );
		$wpdb->update( Db::table( 'source_items' ), array( 'publisher' => 'Harbour Insider' ), array( 'id' => (int) Lifecycle::job( $job )['source_item_id'] ) );
		publish_at_slot();

		$sent = implode( ' | ', array_map( static function ( $row ) {
			return urldecode( (string) $row['search'] );
		}, mock_requests( 'commons/api' ) ) );
		t_contains( 'Sydney Harbour Bridge', $sent );
		t_not_contains( 'Harbour Insider', $sent, 'the publisher is not searched' );

		$log = implode( ' | ', array_column( Events::query( array( 'stage' => 'image' ), 30 ), 'message' ) );
		t_contains( 'below the minimum of 99', $log, $log );
		t_contains( 'The best score was', $log );
		t_not_contains( 'score N', $log, 'the numbers are shown' );
	}
);
