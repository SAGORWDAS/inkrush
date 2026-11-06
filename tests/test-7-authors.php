<?php
use ZZZCopy\Admin\Actions;
use ZZZCopy\Admin\Menu;
use ZZZCopy\Admin\Pages;
use ZZZCopy\Articles;
use ZZZCopy\Authors;
use ZZZCopy\Automation;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Lifecycle;
use ZZZCopy\Planner;
use ZZZCopy\Publisher;
use ZZZCopy\Schedule;
use ZZZCopy\Schema;
use ZZZCopy\Settings;

/**
 * Only users a plugin administrator authorises are credited with posts. Nobody gets access by role.
 */

function au_user( string $role = 'author', string $name = '' ): int {
	$login = 'zzzau' . wp_rand( 100000, 999999 );
	return (int) wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password(),
			'user_email'   => $login . '@example.test',
			'display_name' => $name ?: 'Writer ' . $login,
			'role'         => $role,
		)
	);
}

function au_log( string $stage = 'access' ): string {
	return implode( "\n", array_reverse( array_column( Events::query( array( 'stage' => $stage ), 200 ), 'message' ) ) );
}

function au_as_admin( callable $fn ) {
	$before = get_current_user_id();
	wp_set_current_user( $GLOBALS['zzzt_admin'] );
	$result = $fn();
	wp_set_current_user( $before );
	return $result;
}

function au_page( string $method, array $get = array() ): string {
	$saved = $_GET;
	$_GET  = $get;
	$html  = au_as_admin(
		static function () use ( $method ) {
			ob_start();
			Pages::$method();
			return (string) ob_get_clean();
		}
	);
	$_GET  = $saved;
	return $html;
}

function au_die_throws(): void {
	add_filter(
		'wp_die_handler',
		static fn() => static function ( $message ) {
			throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'die' ) );
		}
	);
}

/** A queued article in the earlier workflow, with the 10:00 post ahead. */
function au_queued( string $slug, string $mode = 'publish' ): int {
	$category = ready_plugin( array(), $mode );
	set_local_now( '2026-11-05 09:00:00' );
	Automation::patch( array( 'run_state' => Automation::RUNNING ) );
	Schedule::ensure_slots();
	return queued_article( $category, $slug );
}

t(
	'AU-1',
	'Nobody is authorised automatically: a fresh install has no author, Automation cannot start, and an administrator gets no access by role',
	static function () {
		global $wpdb;
		t_eq( 0, Settings::defaults()['post_author'], 'no author on a fresh install' );
		$wpdb->query( 'DELETE FROM ' . Db::table( 'authors' ) );
		Settings::update( array( 'post_author' => 0 ) );
		ready_plugin();

		t_eq( Authors::NONE, Authors::status( $GLOBALS['zzzt_admin'] ), 'an administrator is not authorised by their role' );
		t_eq( array(), Authors::active_ids() );
		t_contains( 'No post author is chosen', implode( ' ', Automation::config_problems() ) );
		t_true( is_array( Automation::start( false ) ), 'Automation does not start' );
		t_eq( 0, Publisher::author_id(), 'no fallback to the first administrator' );

		$admin2 = au_user( 'administrator', 'Second Admin' );
		$result = Settings::update( array( 'post_author' => $admin2 ) );
		t_contains( 'Only an active author can be chosen', (string) ( $result['errors']['post_author'] ?? '' ) );
		t_eq( 0, (int) Settings::get( 'post_author' ), 'not saved' );

		$caps_before = get_userdata( $admin2 )->allcaps;
		au_as_admin(
			static function () use ( $admin2 ) {
				Authors::change( array( $admin2 ), Authors::ACTIVE );
			}
		);
		t_eq( array(), Settings::update( array( 'post_author' => $admin2 ) )['errors'] );
		t_eq( '', Authors::publishing_problem() );
		t_eq( $admin2, Publisher::author_id() );
		clean_user_cache( $admin2 );
		t_eq( array( 'administrator' ), array_values( get_userdata( $admin2 )->roles ), 'the WordPress role is unchanged' );
		t_eq( $caps_before, get_userdata( $admin2 )->allcaps, 'no capability was added or removed' );

		Settings::update( array( 'post_author' => $GLOBALS['zzzt_admin'] ) );
		wp_delete_user( $admin2 );
	}
);

t(
	'AU-2',
	'Access can be granted, paused, resumed and revoked, one user or many, and each change records who made it',
	static function () {
		$a     = au_user( 'author', 'Ann Writer' );
		$b     = au_user( 'contributor', 'Bob Writer' );
		$c     = au_user( 'subscriber', 'Cal Reader' );
		$admin = Authors::user_label( $GLOBALS['zzzt_admin'] );
		au_as_admin(
			static function () use ( $a, $b, $c, $admin ) {
				$result = Authors::change( array( $a, $b, $a ), Authors::ACTIVE );
				t_eq( array( $a, $b ), $result['changed'], 'each user once' );
				t_eq( array(), $result['errors'] );
				$row = Authors::row( $a );
				t_eq( $GLOBALS['zzzt_admin'], (int) $row['granted_by'] );
				t_false( empty( $row['granted_at'] ) );
				t_contains( $admin . ' granted access to ' . Authors::user_label( $a ) . '.', au_log() );

				Authors::change( array( $a ), Authors::PAUSED );
				t_eq( Authors::PAUSED, Authors::status( $a ) );
				t_contains( $admin . ' paused access for ' . Authors::user_label( $a ), au_log() );
				Authors::change( array( $a ), Authors::ACTIVE );
				t_contains( $admin . ' resumed access for ' . Authors::user_label( $a ), au_log() );

				Authors::change( array( $b ), Authors::REVOKED );
				t_eq( Authors::REVOKED, Authors::status( $b ) );
				t_contains( $admin . ' revoked access for ' . Authors::user_label( $b ), au_log() );
				$count = Events::count( array( 'stage' => 'access' ) );
				t_eq( array(), Authors::change( array( $b ), Authors::REVOKED )['changed'], 'no change' );
				t_eq( $count, Events::count( array( 'stage' => 'access' ) ), 'and nothing logged' );

				t_contains( 'is not authorised, so there is nothing to pause', implode( ' ', Authors::change( array( $c ), Authors::PAUSED )['errors'] ) );
				t_eq( Authors::NONE, Authors::status( $c ) );
				t_contains( 'User 999999 does not exist', implode( ' ', Authors::change( array( 999999 ), Authors::ACTIVE )['errors'] ) );
				t_contains( 'at most 200', implode( ' ', Authors::change( range( 1, 201 ), Authors::ACTIVE )['errors'] ) );
				t_contains( 'Unknown access status', implode( ' ', Authors::change( array( $a ), 'owner' )['errors'] ) );

				Settings::update( array( 'post_author' => $a ) );
				Authors::change( array( $a ), Authors::PAUSED );
				t_contains( 'They are the Post author, so posts are held', au_log() );
			}
		);
		Settings::update( array( 'post_author' => $GLOBALS['zzzt_admin'] ) );
		foreach ( array( $a, $b, $c ) as $user ) {
			wp_delete_user( $user );
		}
	}
);

t(
	'AU-3',
	'Each post is credited to the active Post author, and the post, its link and the log all record it',
	static function () {
		global $wpdb;
		$writer = au_user( 'author', 'Cara Writer' );
		$job    = au_queued( 'credited-story' );
		au_as_admin(
			static function () use ( $writer ) {
				Authors::change( array( $writer ), Authors::ACTIVE );
			}
		);
		t_eq( array(), Settings::update( array( 'post_author' => $writer ) )['errors'] );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ) );
		$post = (int) posts_with_job()[0];
		t_eq( $writer, (int) get_post_field( 'post_author', $post ) );
		t_eq( $writer, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT author_user_id FROM ' . Db::table( 'post_links' ) . ' WHERE wp_post_id = %d', $post ) ) );
		t_contains( 'credited to Cara Writer', au_log( 'publishing' ) );
		t_eq( 1, Authors::credited_posts( array( $writer ) )[ $writer ] ?? 0 );
		t_eq( 2, Events::count( array( 'user' => $writer ) ), 'the grant and the post' );
		t_not_contains( 'published by hand', au_log( 'publishing' ), 'the worker\'s own publishing is not counted as by hand' );

		Settings::update( array( 'post_author' => $GLOBALS['zzzt_admin'] ) );
		wp_delete_user( $writer, $GLOBALS['zzzt_admin'] );
	}
);

t(
	'AU-4',
	'A paused or revoked author is never credited: the article is held, research waits, and Retry publishes it once they are active again',
	static function () {
		$job = au_queued( 'held-for-author' );
		au_as_admin(
			static function () {
				Authors::change( array( $GLOBALS['zzzt_admin'] ), Authors::PAUSED );
			}
		);
		t_contains( 'is paused on the Authors page', Authors::publishing_problem() );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		$row = Lifecycle::job( $job );
		t_eq( Lifecycle::PUBLISH_FAILED, $row['status'] );
		t_eq( 'author_required', $row['error_code'] );
		t_contains( 'Not published: The post author', (string) $row['error_message'] );
		t_eq( 0, count( posts_with_job() ), 'no post was made' );
		t_contains( 'Held, not published', au_log( 'publishing' ) );

		$alert = au_page( 'dashboard' );
		t_contains( 'Posts are not being published', $alert );

		// No AI is spent on research that could not be published.
		set_local_now( '2026-11-05 12:30:00' );
		Automation::patch( array( 'last_discovery_at' => 0 ) );
		Planner::tick();
		t_eq( 0, db_count( 'jobs', "job_type = 'discover'" ), 'research waits' );

		au_as_admin(
			static function () {
				Authors::change( array( $GLOBALS['zzzt_admin'] ), Authors::REVOKED );
			}
		);
		t_contains( 'no longer has access', Authors::publishing_problem() );
		Planner::tick();
		t_eq( 0, db_count( 'jobs', "job_type = 'discover'" ) );

		au_as_admin(
			static function () {
				Authors::change( array( $GLOBALS['zzzt_admin'] ), Authors::ACTIVE );
			}
		);
		Planner::tick();
		t_true( db_count( 'jobs', "job_type = 'discover'" ) > 0, 'research starts again' );

		t_true( true === Articles::retry( $job ), 'retry' );
		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISHED, job_status( $job ), 'published once the author is active' );
	}
);

t(
	'AU-5',
	'A deleted WordPress user shows as User missing, is recorded in Logs, and is never credited',
	static function () {
		$writer = au_user( 'author', 'Dee Writer' );
		$job    = au_queued( 'deleted-author' );
		au_as_admin(
			static function () use ( $writer ) {
				Authors::change( array( $writer ), Authors::ACTIVE );
			}
		);
		Settings::update( array( 'post_author' => $writer ) );
		wp_delete_user( $writer );
		Authors::flush_labels();

		t_eq( Authors::MISSING, Authors::status( $writer ) );
		t_contains( "WordPress user {$writer} was deleted", au_log() );
		t_contains( 'They were the Post author', au_log() );
		t_contains( 'no longer exists in WordPress', Authors::publishing_problem() );
		t_false( in_array( $writer, Authors::active_ids(), true ) );

		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::PUBLISH_FAILED, job_status( $job ) );
		t_eq( 0, count( posts_with_job() ) );

		$page = au_page( 'authors' );
		t_contains( 'Deleted users', $page );
		t_contains( "User {$writer} (deleted)", $page );
		Settings::update( array( 'post_author' => $GLOBALS['zzzt_admin'] ) );
	}
);

t(
	'AU-6',
	'A WordPress role change is recorded and changes nothing here, and granting access never changes a role',
	static function () {
		$writer = au_user( 'author', 'Eli Writer' );
		$other  = au_user( 'author', 'Fay Writer' );
		au_as_admin(
			static function () use ( $writer ) {
				Authors::change( array( $writer ), Authors::ACTIVE );
			}
		);
		t_eq( array( 'author' ), array_values( get_userdata( $writer )->roles ) );

		( new WP_User( $writer ) )->set_role( 'subscriber' );
		t_contains( 'changed from author to subscriber. Their access to this plugin did not change.', au_log() );
		t_true( Authors::is_active( $writer ), 'still active' );

		$count = Events::count( array( 'stage' => 'access' ) );
		( new WP_User( $other ) )->set_role( 'editor' );
		t_eq( $count, Events::count( array( 'stage' => 'access' ) ), 'users not on the list are not recorded' );

		wp_delete_user( $writer );
		wp_delete_user( $other );
	}
);

t(
	'AU-7',
	'The Authors page lists username, name, email and role, can be searched and filtered, and only plugin administrators can open it',
	static function () {
		t_eq( 'Authors', Menu::PAGES['zzzc-authors'] );
		// A name no earlier run can have left behind.
		$tag  = 'Findme' . wp_rand( 100000, 999999 );
		$eve = au_user( 'author', $tag . ' Eve' );
		$finn = au_user( 'subscriber', $tag . ' Finn' );
		au_as_admin(
			static function () use ( $eve ) {
				Authors::change( array( $eve ), Authors::ACTIVE );
			}
		);

		$page = au_page( 'authors', array( 's' => $tag ) );
		t_contains( get_userdata( $eve )->user_login, $page );
		t_contains( $tag . ' Eve', $page );
		t_contains( get_userdata( $eve )->user_email, $page );
		t_contains( '<td>Author</td>', $page );
		t_contains( '<span class="zzzc-tag">Active</span>', $page );
		t_contains( '<span class="zzzc-tag">Not authorised</span>', $page );
		t_contains( $tag . ' Finn', $page );
		t_contains( 'Their WordPress role lets them edit and delete published posts credited to them.', $page );
		t_contains( 'name="single" value="pause:' . $eve . '"', $page );
		t_contains( 'name="single" value="grant:' . $finn . '"', $page );
		t_contains( 'name="user_ids[]"', $page );
		t_not_contains( 'test@example.test', $page, 'other users are not listed' );

		$subscribers = au_page( 'authors', array( 's' => $tag, 'role' => 'subscriber' ) );
		t_contains( $tag . ' Finn', $subscribers );
		t_not_contains( $tag . ' Eve', $subscribers );
		$active = au_page( 'authors', array( 's' => $tag, 'access' => 'active' ) );
		t_contains( $tag . ' Eve', $active );
		t_not_contains( $tag . ' Finn', $active );
		$none = au_page( 'authors', array( 's' => $tag, 'access' => 'none' ) );
		t_contains( $tag . ' Finn', $none );
		t_not_contains( $tag . ' Eve', $none );
		t_contains( 'No users match.', au_page( 'authors', array( 'access' => 'paused' ) ) );

		// The Post author list has only active authors.
		$publishing = au_page( 'publishing' );
		t_contains( 'None chosen', $publishing );
		t_contains( $tag . ' Eve', $publishing );
		t_not_contains( $tag . ' Finn', $publishing );
		t_not_contains( 'First administrator', $publishing );

		$editor = au_user( 'editor' );
		wp_set_current_user( $editor );
		au_die_throws();
		$caught = false;
		try {
			ob_start();
			Pages::authors();
		} catch ( RuntimeException $e ) {
			$caught = true;
		}
		ob_end_clean();
		t_true( $caught, 'an editor cannot open it' );
		remove_all_filters( 'wp_die_handler' );
		wp_set_current_user( 0 );

		foreach ( array( $eve, $finn, $editor ) as $user ) {
			wp_delete_user( $user );
		}
	}
);

t(
	'AU-8',
	'Grant, pause and revoke are checked on the server: a row button, a bulk choice, refused without the nonce or the capability, and the filters are kept',
	static function () {
		$a = au_user( 'author' );
		$b = au_user( 'author' );
		add_action( 'admin_post_zzzc_author_access', array( Actions::class, 'dispatch' ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect ' . $location );
			}
		);
		au_die_throws();
		$submit = static function ( array $post, string $nonce = '' ): array {
			$_POST    = $post;
			$_REQUEST = array( '_wpnonce' => $nonce ?: wp_create_nonce( 'zzzc_author_access' ) );
			$location = '';
			try {
				do_action( 'admin_post_zzzc_author_access' );
			} catch ( RuntimeException $e ) {
				$location = $e->getMessage();
			}
			$flash = (array) get_transient( 'zzzc_flash_' . get_current_user_id() );
			delete_transient( 'zzzc_flash_' . get_current_user_id() );
			return array( $location, $flash );
		};

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		list( $location, $flash ) = $submit( array( 'bulk_action' => 'grant', 'user_ids' => array( (string) $a, (string) $b ), 'return_s' => 'zzzau', 'return_access' => 'none' ) );
		t_eq( 'success', $flash['type'] ?? '' );
		t_contains( '2 users changed', (string) ( $flash['text'] ?? '' ) );
		t_true( Authors::is_active( $a ) && Authors::is_active( $b ) );
		t_contains( 'page=zzzc-authors', $location );
		t_contains( 's=zzzau', $location, 'the search is kept' );
		t_contains( 'access=none', $location );

		$submit( array( 'single' => 'revoke:' . $b, 'bulk_action' => 'grant', 'user_ids' => array( (string) $a ) ) );
		t_eq( Authors::REVOKED, Authors::status( $b ), 'the row button is what counts' );
		t_eq( Authors::ACTIVE, Authors::status( $a ) );

		list( , $flash ) = $submit( array( 'bulk_action' => '', 'user_ids' => array( (string) $a ) ) );
		t_contains( 'Choose Grant access', (string) ( $flash['text'] ?? '' ) );
		list( , $flash ) = $submit( array( 'bulk_action' => 'pause' ) );
		t_contains( 'Select at least one user', (string) ( $flash['text'] ?? '' ) );
		t_eq( Authors::ACTIVE, Authors::status( $a ) );

		list( $location ) = $submit( array( 'single' => 'pause:' . $a ), 'wrong' );
		t_contains( 'wp_die', $location, 'a bad nonce is refused' );
		t_eq( Authors::ACTIVE, Authors::status( $a ) );

		$editor = au_user( 'editor' );
		wp_set_current_user( $editor );
		list( $location ) = $submit( array( 'single' => 'pause:' . $a ) );
		t_contains( 'permission', $location, 'an editor is refused' );
		t_eq( Authors::ACTIVE, Authors::status( $a ) );

		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		foreach ( array( $a, $b, $editor ) as $user ) {
			wp_delete_user( $user );
		}
	}
);

t(
	'AU-9',
	'With Past-Hour Posting, no story is chosen and no AI is spent while there is no active author, and the post is skipped',
	static function () {
		$business = ph_ready();
		Settings::update( array( 'slot_categories' => array( 0 => $business ) ) );
		au_as_admin(
			static function () {
				Authors::change( array( $GLOBALS['zzzt_admin'] ), Authors::PAUSED );
			}
		);
		ph_feeds( array( 'feed' => array( ph_story( 'no-author-story', '09:10:00' ) ) ) );
		ph_run( '09:15:00' );
		ph_run( '09:30:00' );
		t_eq( 0, db_count( 'article_jobs' ), 'no story chosen' );
		t_eq( 0, ph_ai_calls(), 'no AI spent' );
		t_contains( 'is paused on the Authors page', (string) ph_slot()['status_reason'] );

		ph_run( '10:00:30' );
		t_eq( Schedule::SKIPPED, ph_slot()['status'] );
		t_eq( 0, count( posts_with_job() ) );
	}
);

t(
	'AU-10',
	'Publishing one of the plugin\'s drafts outside the worker records who did it',
	static function () {
		$job = au_queued( 'hand-published', 'draft' );
		set_local_now( '2026-11-05 10:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $job ) );
		$post  = (int) posts_with_job()[0];
		$admin = Authors::user_label( $GLOBALS['zzzt_admin'] );

		au_as_admin(
			static function () use ( $post ) {
				wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
			}
		);
		t_eq( 'publish', get_post_status( $post ) );
		t_contains( "Post {$post} was published by hand by {$admin}. It is credited to {$admin}.", au_log( 'publishing' ) );

		$second = queued_article( (int) Lifecycle::job( $job )['category_id'], 'published-by-wordpress' );
		set_local_now( '2026-11-05 16:00:30' );
		Publisher::tick();
		t_eq( Lifecycle::DRAFT_CREATED, job_status( $second ) );
		$other = (int) Publisher::find_existing_post( Lifecycle::job( $second ) );
		wp_set_current_user( 0 );
		wp_update_post( array( 'ID' => $other, 'post_status' => 'publish' ) );
		t_contains( "Post {$other} was published by WordPress, not by this plugin's worker", au_log( 'publishing' ) );
	}
);

t(
	'AU-11',
	'Upgrading authorises only the user already credited, or else the first administrator, and nobody else',
	static function () {
		global $wpdb;
		$table  = Db::table( 'authors' );
		$writer = au_user( 'editor', 'Gus Writer' );
		$upgrade = static function ( int $post_author ) use ( $wpdb, $table ) {
			$wpdb->query( "DELETE FROM {$table}" );
			$stored                = get_option( 'zzzc_settings' );
			$stored['post_author'] = $post_author;
			update_option( 'zzzc_settings', $stored, false );
			Settings::flush_cache();
			update_option( 'zzzc_db_version', '9', true );
			Schema::maybe_upgrade();
		};

		$upgrade( $writer );
		t_eq( Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		t_eq( array( $writer ), Authors::ids(), 'only the Post author' );
		t_eq( Authors::NONE, Authors::status( $GLOBALS['zzzt_admin'] ), 'administrators are not added' );
		t_eq( $writer, (int) Settings::get( 'post_author' ) );
		t_contains( 'Gus Writer (', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );
		t_contains( 'is now the one authorised author and the Post author. Nobody else was given access.', implode( ' | ', array_column( Events::query( array( 'stage' => 'config' ), 10 ), 'message' ) ) );

		$first = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) )[0];
		$upgrade( 0 );
		t_eq( array( $first ), Authors::ids(), '"First administrator" becomes that one user' );
		t_eq( $first, (int) Settings::get( 'post_author' ) );

		$gone = au_user( 'author' );
		wp_delete_user( $gone );
		$upgrade( $gone );
		t_eq( array( $first ), Authors::ids(), 'a deleted Post author falls back to the first administrator' );

		wp_delete_user( $writer );
		Authors::insert_active( $GLOBALS['zzzt_admin'] );
		Settings::update( array( 'post_author' => $GLOBALS['zzzt_admin'] ) );
	}
);

t(
	'AU-12',
	'Logs show names instead of numbers and the credited author, and can be filtered by person',
	static function () {
		$writer = au_user( 'author', 'Hana Writer' );
		au_as_admin(
			static function () use ( $writer ) {
				Authors::change( array( $writer ), Authors::ACTIVE );
			}
		);
		Events::log( 'control', 'info', 'An unrelated entry with nobody named.' );
		t_eq( 1, Events::count( array( 'user' => $writer ) ) );
		t_true( Events::count( array( 'user' => $GLOBALS['zzzt_admin'] ) ) >= 1, 'what the administrator did' );

		$page = au_page( 'logs', array( 'user' => (string) $writer ) );
		t_contains( '<th>Author</th>', $page );
		t_contains( 'granted access to Hana Writer', $page );
		t_not_contains( 'An unrelated entry with nobody named.', $page, 'only entries about this person' );
		t_contains( '<td>' . esc_html( Authors::user_label( $GLOBALS['zzzt_admin'] ) ) . '</td>', $page, 'the name of who acted' );
		t_contains( '<option value="' . $writer . '" selected=\'selected\'>', $page, 'the person filter' );
		wp_delete_user( $writer );
	}
);
