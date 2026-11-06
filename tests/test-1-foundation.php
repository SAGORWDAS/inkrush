<?php
use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Db;
use ZZZCopy\Discovery;
use ZZZCopy\Lifecycle;
use ZZZCopy\Prompts;
use ZZZCopy\Schedule;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

t(
	'M1',
	'Plugin is active, tables exist and the header has the exact name',
	static function () {
		global $wpdb;
		t_true( is_plugin_active( 'zzz-copy-das-sobur/zzz-copy-das-sobur.php' ), 'active' );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/zzz-copy-das-sobur/zzz-copy-das-sobur.php', false, false );
		t_eq( 'InkRush', $data['Name'] );
		foreach ( array( 'categories', 'source_items', 'article_jobs', 'article_content', 'publishing_queue', 'slots', 'generation_usage', 'events', 'post_links', 'prompt_versions', 'jobs', 'locks' ) as $table ) {
			t_eq( Db::table( $table ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::table( $table ) ) ), $table );
		}
	}
);

t(
	'M2',
	'Admin menu shows the exact plugin name',
	static function () {
		global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages;
		$menu = array();
		$submenu = array();
		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ZZZCopy\Admin\Menu::add_menu();
		$labels = array_column( $menu, 0 );
		t_true( in_array( 'InkRush', $labels, true ), 'menu label' );
		$items = array_column( $submenu['zzzc-dashboard'], 0 );
		foreach ( array( 'Dashboard', 'News Sources', 'AI Settings', 'Schedule', 'Publishing', 'Articles', 'Usage', 'Logs' ) as $label ) {
			t_true( in_array( $label, $items, true ), $label );
		}
	}
);

t(
	'AC-01,M3,M4',
	"The owner's 21 categories exist in order, with the supplied topic URLs where they apply, and can be edited, added, disabled, duplicated, reordered and deleted",
	static function () {
		$rows = Categories::all();
		t_eq(
			array( 'Technology', 'World', 'Entertainment', 'Celebrity', 'Business', 'Politics', 'Science', 'Sports', 'Medical', 'Insurance', 'War & Conflict', 'Crime', 'Celebrity News', 'Myths & Legends', 'Coupon Blogs', 'Referral Programs & Blogs', 'Fitness', 'Gaming', 'Health & Wellness', 'Finance & Retirement', 'Technology & Consumer News' ),
			array_column( $rows, 'name' )
		);

		$expected = array(
			'Business'          => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRGx6TVdZU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'Technology'        => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRGRqTVhZU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'Entertainment'     => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNREpxYW5RU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'Sports'            => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRFp1ZEdvU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'Science'           => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRFp0Y1RjU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'Health & Wellness' => 'https://news.google.com/topics/CAAqIQgKIhtDQkFTRGdvSUwyMHZNR3QwTlRFU0FtVnVLQUFQAQ?hl=en-US&gl=US&ceid=US%3Aen',
		);
		foreach ( $rows as $row ) {
			t_eq( $expected[ $row['name'] ] ?? '', (string) $row['topic_urls'], $row['name'] . ' topic' );
			t_true( '' !== (string) $row['feed_urls'], $row['name'] . ' has a publisher feed' );
			t_true( '' !== (string) $row['topic_urls'] . (string) $row['search_queries'], $row['name'] . ' has a Google source' );
			t_true( Discovery::has_usable_source( $row ), $row['name'] . ' is usable' );
			t_eq( 1, (int) $row['enabled'], $row['name'] . ' enabled' );
		}

		$id = (int) $rows[0]['id'];

		$input = array(
			'name'              => 'Markets',
			'enabled'           => 1,
			'weight'            => 5,
			'source_mode'       => 'both',
			'topic_urls'        => 'https://news.google.com/topics/ABC?hl=en-US',
			'search_queries'    => "stocks\nbonds",
			'feed_urls'         => 'https://example.com/feed.xml',
			'required_keywords' => 'market',
			'excluded_keywords' => 'crypto',
			'recency'           => '1h',
			'language'          => 'en-AU',
			'region'            => 'AU',
			'active_days'       => array( '1', '2' ),
		);
		$clean = Categories::sanitize_input( $input );
		t_eq( array(), $clean['errors'] );
		Categories::update( $id, $clean['data'] );
		Settings::flush_cache();
		$saved = Categories::get( $id );
		t_eq( 'Markets', $saved['name'] );
		t_eq( "stocks\nbonds", $saved['search_queries'] );
		t_eq( '1h', $saved['recency'] );
		t_eq( 'AU', $saved['region'] );
		t_eq( '1,2', $saved['active_days'] );

		$bad = Categories::sanitize_input( array_merge( $input, array( 'topic_urls' => 'https://evil.example/topics/x' ) ) );
		t_true( isset( $bad['errors']['topic_urls'] ), 'non-Google topic URL rejected' );
		$bad = Categories::sanitize_input( array_merge( $input, array( 'name' => '' ) ) );
		t_true( isset( $bad['errors']['name'] ), 'empty name rejected' );

		$new = Categories::insert( array( 'name' => 'Added', 'sort_order' => 99 ) );
		t_eq( 22, count( Categories::all() ) );

		Categories::update( $new, array( 'enabled' => 0 ) );
		t_false( in_array( 'Added', array_column( Categories::enabled(), 'name' ), true ), 'disabled' );

		$copy = Categories::duplicate( $id );
		t_eq( 'Markets (copy)', Categories::get( $copy )['name'] );
		t_eq( 0, (int) Categories::get( $copy )['enabled'], 'copy starts disabled' );

		$second = (int) Categories::all()[1]['id'];
		Categories::move( $second, -1 );
		t_eq( $second, (int) Categories::all()[0]['id'], 'moved up' );

		Categories::soft_delete( $copy );
		t_false( in_array( $copy, array_map( 'intval', array_column( Categories::all(), 'id' ) ), true ), 'deleted' );
		t_true( null !== Categories::get( $copy ), 'record kept for history' );
	}
);

t(
	'Categories',
	'Upgrading from 1.0 adds the missing categories, leaves existing ones untouched and is not repeated',
	static function () {
		global $wpdb;
		$table = Db::table( 'categories' );

		// Recreate a 1.0 site: the original six, one of them edited by the owner, plus their own category.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		foreach ( array( 'Business', 'Technology', 'Entertainment', 'Sports', 'Science', 'Health' ) as $i => $name ) {
			Categories::insert( array( 'name' => $name, 'sort_order' => $i + 1, 'feed_urls' => 'https://old.example/' . $i ) );
		}
		$mine = Categories::insert( array( 'name' => 'My Own Niche', 'sort_order' => 7, 'search_queries' => 'mine', 'enabled' => 0 ) );
		Settings::update( array( 'category_buffer' => 2 ) );
		update_option( 'zzzc_db_version', '1', true );

		ZZZCopy\Schema::maybe_upgrade();
		Settings::flush_cache();

		t_eq( ZZZCopy\Schema::DB_VERSION, get_option( 'zzzc_db_version' ) );
		t_eq( 'publish', Settings::get( 'publish_mode' ), 'upgraded site uses Automatic Publish' );
		t_eq( 1, Settings::get( 'category_auto_create' ), 'upgraded site creates WordPress categories' );
		$names = array_column( Categories::all(), 'name' );
		t_eq( 23, count( $names ), '7 existing plus the 16 missing' );
		t_true( in_array( 'War & Conflict', $names, true ), 'new one added' );
		t_true( in_array( 'Health', $names, true ), 'old category is kept' );
		t_true( in_array( 'Health & Wellness', $names, true ), 'and the new one is added beside it' );

		$business = $wpdb->get_row( "SELECT * FROM {$table} WHERE name = 'Business'", ARRAY_A );
		t_eq( 'https://old.example/0', $business['feed_urls'], 'an existing category is not overwritten' );
		t_eq( 0, (int) Categories::get( $mine )['enabled'], "the owner's own category is untouched" );
		t_eq( 1, Settings::get( 'category_buffer' ), 'buffer moved from the old default' );

		// A deleted category is not brought back.
		$crime = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE name = 'Crime'" );
		Categories::soft_delete( $crime );
		t_eq( 0, Categories::add_missing_seeds(), 'nothing to add, and deleted ones stay deleted' );
		t_eq( 22, count( Categories::all() ) );

		// A buffer the owner chose is kept.
		update_option( 'zzzc_db_version', '1', true );
		Settings::update( array( 'category_buffer' => 5 ) );
		ZZZCopy\Schema::maybe_upgrade();
		Settings::flush_cache();
		t_eq( 5, Settings::get( 'category_buffer' ) );
	}
);

t(
	'Categories',
	'Names with "&" work end to end: form, WordPress category, repeated lookups and the post',
	static function () {
		$clean = Categories::sanitize_input(
			array(
				'name'           => 'Food & Drink <b>',
				'enabled'        => 1,
				'search_queries' => 'food & drink',
				'active_days'    => array( '1', '2', '3', '4', '5', '6', '7' ),
			)
		);
		t_eq( array(), $clean['errors'] );
		t_contains( 'Food & Drink', $clean['data']['name'], 'ampersand is kept' );
		t_not_contains( '<b>', $clean['data']['name'], 'markup is removed' );

		global $wpdb;
		$war = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'categories' ) . " WHERE name = 'War & Conflict'", ARRAY_A );

		Settings::update( array( 'category_auto_create' => 0 ) );
		t_true( is_wp_error( Categories::resolve_term( $war ) ), 'no creation when the option is off' );
		t_contains( 'War & Conflict', implode( ' ', Automation::config_problems() ), 'named in the setup list' );
		t_eq( 1, count( array_filter( Automation::config_problems(), static fn( $p ) => false !== strpos( $p, 'no matching WordPress category' ) ) ), 'one line, not 21' );

		Settings::update( array( 'category_auto_create' => 1 ) );
		$first  = Categories::resolve_term( $war );
		t_true( is_int( $first ), 'created: ' . ( is_wp_error( $first ) ? $first->get_error_message() : '' ) );
		$second = Categories::resolve_term( $war );
		t_eq( $first, $second, 'the same term the second time, not a duplicate or an error' );

		Settings::update( array( 'category_auto_create' => 0 ) );
		t_true( Categories::resolve_term_readonly( $war ), 'found by name afterwards' );
		t_eq( $first, Categories::resolve_term( $war ), 'found without auto-create' );

		$found = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'include' => array( $first ) ) );
		t_eq( 'war-conflict', $found[0]->slug );
		wp_delete_term( $first, 'category' );

		$query = Discovery::search_url( 'food & drink', array( 'recency' => '24h', 'language' => 'en-US', 'region' => 'US' ) );
		t_contains( 'q=food%20%26%20drink', $query['url'], 'ampersand is encoded in a search' );
	}
);

t(
	'Categories',
	'A category added later is used only once it is ready, and its status says why not',
	static function () {
		ready_plugin();
		global $wpdb;
		Settings::update( array( 'category_auto_create' => 0 ) );

		$table = Db::table( 'categories' );
		$new   = Categories::insert( array( 'name' => 'Brand New Niche ' . wp_rand(), 'sort_order' => 99 ) );
		$row   = Categories::get( $new );

		t_eq( array( 'No source', 'No WordPress category' ), Categories::readiness( $row ) );

		$pool = static fn() => array_map( 'intval', array_column( ZZZCopy\Schedule::pool_for_date( '2026-11-05' ), 'id' ) );
		t_false( in_array( $new, $pool(), true ), 'not chosen with no source or category' );

		Categories::update( $new, array( 'search_queries' => 'new niche' ) );
		t_eq( array( 'No WordPress category' ), Categories::readiness( Categories::get( $new ) ) );
		t_false( in_array( $new, $pool(), true ), 'still waiting' );

		$term = wp_insert_term( 'Mapped Target ' . wp_rand(), 'category' );
		Categories::update( $new, array( 'wp_term_id' => (int) $term['term_id'] ) );
		t_eq( array(), Categories::readiness( Categories::get( $new ) ) );
		t_true( in_array( $new, $pool(), true ), 'joins the rotation when ready' );

		// The picker never returns a category that is not ready, even when set on a slot.
		$blocked = Categories::insert( array( 'name' => 'Not Ready Niche ' . wp_rand(), 'sort_order' => 100 ) );
		Settings::update( array( 'slot_categories' => array( 0 => $blocked ), 'rotation_mode' => 'per_slot' ) );
		t_true( $blocked !== ZZZCopy\Schedule::pick_category( '2026-11-05', 0, array() ), 'explicit slot falls back' );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ob_start();
		ZZZCopy\Admin\Pages::sources();
		$html = (string) ob_get_clean();
		t_contains( 'No source, No WordPress category', $html, 'the page shows the problem' );
		t_contains( '<td>Ready</td>', $html, 'and which are ready' );
		t_contains( 'Add category', $html, 'adding more stays open' );
		wp_delete_term( (int) $term['term_id'], 'category' );
	}
);

t(
	'AC-02,M6',
	'Default article prompt matches the supplied text in every checked detail',
	static function () {
		$text = Prompts::active_text( Prompts::ARTICLE );
		t_eq( Prompts::default_text( Prompts::ARTICLE ), $text, 'stored equals default file' );

		t_eq( 0, strpos( $text, 'Based on the above, Write a search engine optimized content of approximately 1,500 words Proper news of 1500+ words News article on and make it trustworthy.' ), 'opening' );
		t_contains( "I need you to get the title H1 and then I'll be very happy if you could do:\n\nH1:\n\n2 paragraphs and must provide high quality user search intent to fulfill content.\n\nH2 (2-3)\n\n4 paragraphs with not less than 200 words Distributed among those two to three H2s, No more and no less than one of them should be a bullet point or numbering.\n\nH3:\n\nThis paragraph should be a table format.\n\nH4:\n\n2 paragraphs and must provide high quality user search intent to fulfill content Must be well-structured\n\nTrending FAQ:\n\nUse only credible data sources and It is a must", $text, 'structure lines' );
		t_contains( 'add subheadings where appropriate.Please only output', $text, 'missing space is kept' );
		t_contains( 'please do not use “—” this sign anywhere', $text, 'dash sign and curly quotes' );
		t_contains( 'score of 85–90', $text, 'en dash' );
		t_eq( 3, substr_count( $text, 'It is a must to get a readability Flesch Kincaid score of 85–90.' ) + 0 === 3 ? 3 : substr_count( $text, 'It is a must to get a readability Flesch Kincaid score of 85–90.' ), 'repeated sentences kept' );
		t_true( str_ends_with( $text, 'I need no Flesch–Kincaid readability test or no wordcount.' ), 'ending' );

		t_eq( 'provide tags for the writing in paragraph format separated by comma 20 tags', Prompts::active_text( Prompts::TAG ) );
	}
);

t(
	'AC-02,M6,M7',
	'Prompts with unusual content are stored and returned byte for byte',
	static function () {
		$hard = "\n  Leading newline and spaces.\tTab. Trailing spaces   \n" .
			"Quotes \" ' “ ” ‘ ’ and dashes – — and emoji 📰🧪.\n" .
			"Percent %s %d %% and backslashes \\ \\n \\\\ and {braces} and \$dollar.\n" .
			"HTML <script>alert(1)</script> <b>bold</b> &amp; &lt; entities.\n" .
			"Non-Latin: বাংলা العربية 日本語. SQL: ' OR 1=1; -- DROP TABLE x;\n" .
			"misspeled wurds,no space.After period  double  spaces\n\n\n";

		$id = Prompts::save( Prompts::ARTICLE, $hard, 1 );
		t_true( is_int( $id ), 'saved' );
		t_eq( bin2hex( $hard ), bin2hex( Prompts::active_text( Prompts::ARTICLE ) ), 'bytes' );

		// A form post slashes values and browsers send CRLF. Both are reversed exactly.
		$submitted = str_replace( "\n", "\r\n", $hard );
		$id2       = Prompts::save( Prompts::ARTICLE, wp_unslash( wp_slash( $submitted ) ), 1 );
		t_eq( $id, $id2, 'same text after form round trip makes no new version' );
		t_eq( bin2hex( $hard ), bin2hex( Prompts::active_text( Prompts::ARTICLE ) ), 'bytes after round trip' );

		$id3 = Prompts::save( Prompts::ARTICLE, 'A new version', 1 );
		t_true( $id3 > $id, 'new version created' );
		t_eq( $hard, (string) Prompts::version( $id )['content'], 'old version kept' );
		t_true( Prompts::restore( Prompts::ARTICLE, $id ), 'restore' );
		t_eq( $hard, Prompts::active_text( Prompts::ARTICLE ), 'restored' );

		t_true( is_wp_error( Prompts::save( Prompts::TAG, "  \n ", 1 ) ), 'empty refused' );
	}
);

t(
	'AC-03',
	'Start refuses when configuration is missing, and starts persistent processing when ready',
	static function () {
		t_eq( Automation::NOT_CONFIGURED, Automation::display_state() );
		$result = Automation::start();
		t_true( is_array( $result ), 'start refused' );
		t_contains( 'OpenRouter API key', implode( ' ', $result ) );
		t_eq( Automation::NOT_CONFIGURED, Automation::display_state(), 'state unchanged' );

		ready_plugin();
		t_eq( Automation::STOPPED, Automation::display_state(), 'ready and stopped' );

		$result = Automation::start();
		t_eq( true, $result, 'start accepted' );
		t_eq( Automation::STARTING, Automation::display_state() );
		t_true( wp_next_scheduled( 'zzzc_tick' ), 'recurring schedule created' );

		$summary = ZZZCopy\Runner::tick( 'cli' );
		t_true( $summary['ran'], 'worker ran' );
		t_eq( Automation::RUNNING, Automation::display_state(), 'running after the first heartbeat' );
		t_true( ZZZCopy\Health::status()['last_beat'] > 0, 'heartbeat recorded' );
	}
);

t(
	'AC-03,M9',
	'Start refuses an unavailable model, shows an error and does not substitute another',
	static function () {
		ready_plugin();
		Settings::update( array( 'model_id' => 'openai/does-not-exist' ) );
		$result = Automation::start();
		t_true( is_array( $result ), 'refused' );
		t_contains( 'not available', $result[0] );
		t_contains( 'not replaced', $result[0] );
		t_eq( 'openai/does-not-exist', Settings::get( 'model_id' ), 'setting unchanged' );

		Settings::update( array( 'model_id' => 'openai/gpt-6-luna' ) );
		mock_state( array( 'key_mode' => 'invalid' ) );
		$result = Automation::start();
		t_true( is_array( $result ), 'bad key refused' );
		t_contains( 'rejected the API key', $result[0] );
	}
);

t(
	'M8',
	'Connection test reports success, bad keys and missing models clearly',
	static function () {
		ready_plugin();
		$ok = ZZZCopy\OpenRouter::verify_setup();
		t_true( $ok['ok'] );
		t_contains( 'openai/gpt-6-luna', $ok['message'] );

		$missing = ZZZCopy\OpenRouter::verify_setup( 'openai/nope' );
		t_false( $missing['ok'] );
		t_eq( 'model_unavailable', $missing['code'] );

		mock_state( array( 'key_mode' => 'invalid' ) );
		$bad = ZZZCopy\OpenRouter::verify_setup();
		t_eq( 'invalid_key', $bad['code'] );
	}
);

t(
	'Settings',
	'Settings validate input and persist through a reload',
	static function () {
		$result = Settings::update(
			array(
				'daily_target' => 'abc',
				'first_time'   => '25:00',
				'timezone'     => 'Mars/Base',
				'model_id'     => 'https://openrouter.ai/openai/gpt-6-luna',
				'publish_mode' => 'nonsense',
				'daily_cap'    => 5,
			)
		);
		foreach ( array( 'daily_target', 'first_time', 'timezone', 'model_id', 'publish_mode' ) as $key ) {
			t_true( isset( $result['errors'][ $key ] ), $key . ' rejected' );
		}
		t_eq( array( 'daily_cap' ), $result['changed'] );

		Settings::flush_cache();
		wp_cache_flush();
		t_eq( 5, Settings::get( 'daily_cap' ), 'persisted' );
		t_eq( 'openai/gpt-6-luna', Settings::get( 'model_id' ), 'bad model URL did not overwrite' );

		foreach ( array( '+10:30', '-05:00', 'Australia/Lord_Howe', 'UTC' ) as $zone ) {
			t_true( Settings::is_valid_timezone( $zone ), $zone );
		}
	}
);

t(
	'M12',
	'State machine rejects invalid transitions and logs them',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/state-machine' );

		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $job ) );
		t_false( Lifecycle::transition( $job, Lifecycle::PUBLISHED ), 'pending to published is invalid' );
		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $job ), 'unchanged' );
		t_true( db_count( 'events', "error_code = 'invalid_transition'" ) > 0, 'logged' );

		t_true( Lifecycle::can( Lifecycle::QUEUED, Lifecycle::PUBLISHING ) );
		t_false( Lifecycle::can( Lifecycle::PUBLISHED, Lifecycle::QUEUED ), 'published cannot go back' );
		t_false( Lifecycle::can( Lifecycle::SKIPPED, Lifecycle::QUEUED ), 'skipped is final' );
		t_false( Lifecycle::can( Lifecycle::BLOCKED, Lifecycle::AI_PENDING ), 'blocked source cannot reach AI directly' );
	}
);

t(
	'AC-16',
	'API key is encrypted at rest and only a masked hint is available',
	static function () {
		global $wpdb;
		$key = 'sk-or-v1-supersecretvalue-1234567890';
		Secrets::set_api_key( $key );
		$raw = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'zzzc_secret'" );
		t_not_contains( $key, $raw, 'database row' );
		t_not_contains( 'supersecret', $raw, 'database row' );
		t_eq( $key, Secrets::get_api_key(), 'decrypts for the server' );
		t_eq( '••••••••7890', Secrets::masked() );
		t_not_contains( 'supersecret', Secrets::masked() );

		t_eq( 'x [REDACTED] y', Secrets::redact( "x $key y" ) );
		t_eq( 'Bearer [REDACTED]', Secrets::redact( 'Bearer abc123def456' ) );
		t_not_contains( 'sk-or-other', Secrets::redact( 'leaked sk-or-otherkey123456 here' ) );

		// A key that can no longer be decrypted is reported, not used.
		update_option( 'zzzc_secret', array( 'cipher' => 's1:' . base64_encode( str_repeat( 'x', 60 ) ), 'last4' => 'abcd' ), false );
		t_eq( '', Secrets::get_api_key() );
		t_contains( 'cannot be decrypted', implode( ' ', Automation::config_problems() ) );
	}
);
