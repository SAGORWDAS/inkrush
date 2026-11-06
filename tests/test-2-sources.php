<?php
use ZZZCopy\Categories;
use ZZZCopy\ContentParser;
use ZZZCopy\Db;
use ZZZCopy\Dedupe;
use ZZZCopy\Discovery;
use ZZZCopy\Extractor;
use ZZZCopy\FeedParser;
use ZZZCopy\Http;
use ZZZCopy\Lifecycle;
use ZZZCopy\Planner;
use ZZZCopy\PublisherResolver;
use ZZZCopy\Retrieval;
use ZZZCopy\Robots;
use ZZZCopy\Settings;

t(
	'AC-05,M5',
	'Recency filters: past hour, 24 hours, week and custom range are applied to story dates',
	static function () {
		$now = 1800000000;
		$GLOBALS['zzzt_now'] = $now;

		$check = static fn( array $category, int $age ) => Discovery::recency_check( $category, $now - $age )['ok'];

		$hour = array( 'recency' => '1h' );
		t_true( $check( $hour, 3599 ), '59m59s passes 1h' );
		t_false( $check( $hour, 3601 ), '60m01s fails 1h' );

		$day = array( 'recency' => '24h' );
		t_true( $check( $day, 86399 ), 'inside 24h' );
		t_false( $check( $day, 86401 ), 'outside 24h' );

		$week = array( 'recency' => '7d' );
		t_true( $check( $week, 604799 ), 'inside week' );
		t_false( $check( $week, 604801 ), 'outside week' );

		$custom = array(
			'recency'     => 'custom',
			'custom_from' => gmdate( 'Y-m-d H:i:s', $now - 5000 ),
			'custom_to'   => gmdate( 'Y-m-d H:i:s', $now - 1000 ),
		);
		t_true( $check( $custom, 3000 ), 'inside custom' );
		t_false( $check( $custom, 500 ), 'after custom' );
		t_false( $check( $custom, 6000 ), 'before custom' );

		$no_date = Discovery::recency_check( $day, 0 );
		t_false( $no_date['ok'], 'missing date fails' );
		t_contains( 'no publication time', $no_date['reason'] );
	}
);

t(
	'AC-05,M5',
	'The filter really applied is recorded, and unsupported filters are disclosed',
	static function () {
		$category = array(
			'id'             => 1,
			'source_mode'    => 'both',
			'topic_urls'     => 'https://news.google.com/topics/ABC123?hl=en-US&gl=US&ceid=US%3Aen',
			'search_queries' => 'solar power',
			'feed_urls'      => 'https://example.com/feed.xml',
			'recency'        => '1h',
			'language'       => 'en-US',
			'region'         => 'US',
		);
		$plan = Discovery::fetch_plan( $category );
		t_eq( 3, count( $plan ) );

		t_eq( 'https://news.google.com/rss/topics/ABC123?hl=en-US&gl=US&ceid=US:en', $plan[0]['url'], 'topic to RSS' );
		t_contains( 'no date filter', $plan[0]['filter'], 'topic feeds disclose the limit' );

		t_contains( 'q=solar%20power%20when%3A1h', $plan[1]['url'], 'search operator' );
		t_contains( 'when:1h', $plan[1]['filter'] );

		t_contains( 'no date filter', $plan[2]['filter'], 'publisher feeds disclose the limit' );

		$category['recency']     = 'custom';
		$category['custom_from'] = '2026-03-10 00:00:00';
		$category['custom_to']   = '2026-03-12 00:00:00';
		$built = Discovery::search_url( 'x', $category );
		t_contains( 'after%3A2026-03-09', $built['url'] );
		t_contains( 'before%3A2026-03-13', $built['url'] );
		t_contains( 'whole days', $built['filter'] );

		Settings::update( array( 'google_rss_enabled' => 0 ) );
		$plan = Discovery::fetch_plan( array_merge( $category, array( 'recency' => '1h' ) ) );
		t_eq( 1, count( $plan ), 'Google off leaves publisher feeds' );
		t_eq( Discovery::FEED, $plan[0]['adapter'] );

		t_eq( 0, count( Discovery::fetch_plan( array_merge( $category, array( 'feed_urls' => '' ) ) ) ), 'no usable source' );
	}
);

t(
	'Discovery',
	'RSS, Atom and sitemaps parse, and hostile XML is refused',
	static function () {
		$rss = '<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item><title>Big News &amp; More - The Daily</title><link>https://news.google.com/rss/articles/CBMiabc?oc=5</link><guid isPermaLink="false">CBMiabc</guid><pubDate>Wed, 04 Nov 2026 14:07:52 GMT</pubDate><description>&lt;a href="x"&gt;Linked&lt;/a&gt; text</description><source url="https://thedaily.example">The Daily</source></item></channel></rss>';
		$parsed = FeedParser::parse( $rss );
		t_eq( 'rss', $parsed['kind'] );
		$item = $parsed['items'][0];
		t_eq( 'Big News & More - The Daily', $item['title'] );
		t_eq( 'The Daily', $item['source_name'] );
		t_eq( 'https://thedaily.example', $item['source_url'] );
		t_eq( 'Linked text', $item['snippet'] );
		t_true( $item['published'] > 0 );

		$normal = Discovery::normalize_entry( $item, Discovery::GOOGLE );
		t_eq( 'Big News & More', $normal['headline'], 'publisher suffix removed' );
		t_eq( 'CBMiabc', $normal['story_id'] );

		$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom story</title><link rel="alternate" href="https://a.example/1"/><id>id-1</id><updated>2026-11-04T10:00:00Z</updated><summary>Sum</summary></entry></feed>';
		$parsed = FeedParser::parse( $atom );
		t_eq( 'atom', $parsed['kind'] );
		t_eq( 'https://a.example/1', $parsed['items'][0]['link'] );

		$map = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"><url><loc>https://a.example/story</loc><news:news><news:publication_date>2026-11-04</news:publication_date><news:title>Sitemap story</news:title></news:news></url></urlset>';
		$parsed = FeedParser::parse( $map );
		t_eq( 'Sitemap story', $parsed['items'][0]['title'] );

		$xxe = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><item><title>&xxe;</title></item></channel></rss>';
		t_false( FeedParser::parse( $xxe )['ok'], 'DOCTYPE refused' );
		t_false( FeedParser::parse( 'not xml at all' )['ok'], 'garbage refused' );
	}
);

t(
	'Discovery',
	'A real fetch through the plugin stores new stories and applies keyword filters',
	static function () {
		$category = ready_plugin(
			array(
				feed_item( 'alpha-story', 'Alpha budget plan announced today' ),
				feed_item( 'beta-story', 'Beta crypto scandal grows quickly' ),
				feed_item( 'old-story', 'Old harvest report from last month', 3 * DAY_IN_SECONDS ),
				array( 'title' => 'Undated flood warning story', 'link' => ZZZT_MOCK . '/article/undated', 'guid' => 'g-undated' ),
			)
		);
		Categories::update( $category, array( 'excluded_keywords' => "crypto\ngambling" ) );

		$cat     = Categories::get( $category );
		$fetched = Discovery::fetch( Discovery::FEED, ZZZT_MOCK . '/feed.xml' );
		t_eq( 'ok', $fetched['status'] );
		t_eq( 4, count( $fetched['items'] ) );

		$counts = Discovery::ingest( $cat, $fetched['items'], Discovery::FEED, 'Publisher feed', 'test filter' );
		t_eq( 1, $counts['new'], 'one story passes' );
		t_eq( 3, $counts['filtered'], 'keyword, age and missing date' );

		global $wpdb;
		$reasons = $wpdb->get_col( 'SELECT status_reason FROM ' . Db::table( 'source_items' ) . " WHERE status = 'FILTERED_OUT'" );
		$joined  = implode( ' | ', $reasons );
		t_contains( 'excluded keyword: crypto', $joined );
		t_contains( 'no publication time', $joined );
		t_contains( 'outside the 24h filter', $joined );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'source_items' ) . " WHERE status = 'DISCOVERED'", ARRAY_A );
		t_eq( 'Alpha budget plan announced today', $row['headline'] );
		t_eq( '127.0.0.1', $row['publisher'] );
		t_eq( ZZZT_MOCK . '/article/alpha-story', $row['source_url'] );
		t_true( ! empty( $row['published_at'] ), 'publication time' );
		t_true( ! empty( $row['discovered_at'] ), 'discovery time' );
		t_eq( 'Summary of alpha-story', $row['snippet'] );
		t_eq( 'test filter', $row['effective_filter'] );
		t_eq( 'Publisher feed', $row['search_context'] );

		// Required keywords.
		Categories::update( $category, array( 'excluded_keywords' => '', 'required_keywords' => 'energy' ) );
		$reason = Discovery::filter_reason( Categories::get( $category ), array( 'headline' => 'A plain headline', 'snippet' => 'text', 'published' => time() ) );
		t_contains( 'required keyword', (string) $reason );
	}
);

t(
	'AC-06,M11',
	'Duplicates are caught by URL, tracking variants, canonical, story id, headline and text',
	static function () {
		$category = ready_plugin();
		$cat      = Categories::get( $category );

		t_eq( 'site.example/a/b', Dedupe::normalize_url( 'https://www.site.example/a/b/?utm_source=x&utm_medium=y#frag' ) );
		t_eq( 'site.example/a?id=2', Dedupe::normalize_url( 'http://m.site.example/a?fbclid=z&id=2' ) );
		t_eq( 'site.example/a', Dedupe::normalize_url( 'https://amp.site.example/a/amp/' ) );

		t_eq( 'big storm hits coast', Dedupe::normalize_headline( 'Big Storm Hits Coast! - The Daily Paper' ) );

		$base = array(
			'title'       => 'Council approves new river bridge funding for next year',
			'link'        => 'https://pub.example/river-bridge',
			'guid'        => 'story-1',
			'published'   => time() - 100,
			'snippet'     => 's',
			'source_name' => 'Pub',
			'source_url'  => 'https://pub.example',
			'author'      => '',
		);

		$first = Discovery::ingest( $cat, array( $base ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $first['new'] );

		$again = Discovery::ingest( $cat, array( $base ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $again['seen'], 'same story id is ignored' );
		t_eq( 1, db_count( 'source_items' ), 'not stored twice' );

		$tracking = array_merge( $base, array( 'guid' => 'story-2', 'link' => 'https://www.pub.example/river-bridge/?utm_source=rss', 'title' => 'Totally different words here about gardening tips' ) );
		$result   = Discovery::ingest( $cat, array( $tracking ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $result['duplicate'], 'same URL with tracking' );

		$headline = array_merge( $base, array( 'guid' => 'story-3', 'link' => 'https://other.example/x', 'title' => 'Council approves new river bridge funding for next year - Other News' ) );
		$result   = Discovery::ingest( $cat, array( $headline ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $result['duplicate'], 'similar headline from another site' );

		global $wpdb;
		$logged = $wpdb->get_col( 'SELECT message FROM ' . Db::table( 'events' ) . " WHERE stage = 'dedupe'" );
		t_eq( 2, count( $logged ), 'each duplicate decision is logged' );
		t_contains( 'Similar headline (score', implode( ' ', $logged ), 'with score and threshold' );

		$different = array_merge( $base, array( 'guid' => 'story-4', 'link' => 'https://other.example/y', 'title' => 'Regional farmers report strong wheat harvest this autumn' ) );
		$result    = Discovery::ingest( $cat, array( $different ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $result['new'], 'a different story passes' );

		Settings::update( array( 'headline_similarity' => 1.0 ) );
		$near = array_merge( $base, array( 'guid' => 'story-5', 'link' => 'https://other.example/z', 'title' => 'Council approves new river bridge funding for 2027' ) );
		$result = Discovery::ingest( $cat, array( $near ), Discovery::FEED, 'c', 'f' );
		t_eq( 1, $result['new'], 'threshold setting is honored' );
	}
);

t(
	'AC-06,M11',
	'Text similarity detects a near-copy and separates unrelated text',
	static function () {
		$sentence = static function ( int $seed, int $count ): string {
			$words = array( 'river', 'bridge', 'council', 'budget', 'mayor', 'election', 'school', 'hospital', 'farmer', 'market', 'storm', 'coast', 'energy', 'grid', 'train', 'station', 'court', 'ruling', 'museum', 'artist' );
			mt_srand( $seed );
			$out = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$out[] = $words[ mt_rand( 0, 19 ) ];
			}
			mt_srand();
			return implode( ' ', $out );
		};
		$text_a = $sentence( 1, 600 );
		$near   = $text_a . ' ' . 'with a small extra closing line from another outlet';
		$text_b = $sentence( 2, 600 );

		$a = Dedupe::fingerprint( $text_a );
		t_eq( 16, strlen( $a ) );
		t_true( Dedupe::fingerprint_similarity( $a, Dedupe::fingerprint( $near ) ) >= 0.9, 'near copy scores high' );
		t_true( Dedupe::fingerprint_similarity( $a, Dedupe::fingerprint( $text_b ) ) < 0.75, 'unrelated scores low' );
		t_eq( '', Dedupe::fingerprint( 'too short to hash' ) );
	}
);

t(
	'AC-07,M10',
	'Each source failure is held or recorded, and no AI request is made for it',
	static function () {
		$category = ready_plugin();

		$cases = array(
			array( '/partial', Lifecycle::PARTIAL, Retrieval::PARTIAL ),
			array( '/paywalled', Lifecycle::BLOCKED, Retrieval::PAYWALLED ),
			array( '/payment', Lifecycle::BLOCKED, Retrieval::PAYWALLED ),
			array( '/blocked', Lifecycle::BLOCKED, Retrieval::BLOCKED ),
			array( '/gone', Lifecycle::BLOCKED, Retrieval::NOT_FOUND ),
			array( '/private/secret', Lifecycle::BLOCKED, Retrieval::BLOCKED ),
			array( '/to-private', Lifecycle::BLOCKED, Retrieval::BLOCKED ),
			array( '/empty', Lifecycle::RETRIEVAL_FAILED, Retrieval::ERROR ),
			array( '/malformed', Lifecycle::RETRIEVAL_FAILED, Retrieval::ERROR ),
			array( '/binary', Lifecycle::RETRIEVAL_FAILED, Retrieval::ERROR ),
		);

		$ids = array();
		foreach ( $cases as $case ) {
			$ids[ $case[0] ] = make_job_from_url( $category, ZZZT_MOCK . $case[0] );
		}

		run_jobs_only( 25 );

		foreach ( $cases as $case ) {
			$job  = Lifecycle::job( $ids[ $case[0] ] );
			$item = Lifecycle::item( (int) $job['source_item_id'] );
			t_eq( $case[1], $job['status'], $case[0] . ' job status (' . $job['error_code'] . ': ' . $job['error_message'] . ')' );
			t_eq( $case[2], $item['retrieval_status'], $case[0] . ' retrieval status' );
			t_true( in_array( $item['source_text'], array( null, '' ), true ), $case[0] . ' stored no source text' );
		}

		t_eq( 0, count( mock_requests() ), 'no AI call for any failed source' );
		t_eq( 0, db_count( 'article_content' ), 'no article written' );
		t_eq( 0, db_count( 'post_links' ), 'no post created' );
	}
);

t(
	'Retries',
	'HTTP 429 honors Retry-After, 5xx retries with backoff, and both stop at the limit',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		$limited  = make_job_from_url( $category, ZZZT_MOCK . '/limited' );
		$broken   = make_job_from_url( $category, ZZZT_MOCK . '/broken' );

		ZZZCopy\Automation::patch( array( 'run_state' => ZZZCopy\Automation::RUNNING, 'last_discovery_at' => time() ) );
		ZZZCopy\Runner::tick( 'cli' );

		$jobs = Db::table( 'jobs' );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE unique_key = %s", 'retrieve:' . $limited ), ARRAY_A );
		t_eq( 'pending', $row['status'] );
		t_eq( 1, (int) $row['attempts'] );
		$delay = strtotime( $row['run_at'] . ' UTC' ) - time();
		t_true( $delay >= 100 && $delay <= 125, "waits for Retry-After, got $delay" );
		t_eq( Retrieval::RATE_LIMITED, Lifecycle::item( (int) Lifecycle::job( $limited )['source_item_id'] )['retrieval_status'] );

		// The cool-down is correct behavior. Clear it between runs so the attempt limit can be reached here.
		for ( $i = 0; $i < 8; $i++ ) {
			delete_transient( 'zzzc_host_' . md5( '127.0.0.1' ) );
			run_ticks( 1 );
		}

		t_eq( Lifecycle::RETRIEVAL_FAILED, job_status( $limited ), 'gives up after the attempt limit' );
		t_eq( Lifecycle::RETRIEVAL_FAILED, job_status( $broken ), 'gives up after the attempt limit' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE unique_key = %s", 'retrieve:' . $broken ), ARRAY_A );
		t_eq( 'failed', $row['status'] );
		t_eq( 3, (int) $row['attempts'], 'exact attempt limit' );
	}
);

t(
	'Retrieval',
	'A readable page yields metadata, clean text and a move to AI',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/to-article' );

		$article = Lifecycle::job( $job );
		$result  = Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) );
		t_eq( 'done', $result->outcome );
		t_eq( Lifecycle::AI_PENDING, job_status( $job ) );

		$item = Lifecycle::item( (int) $article['source_item_id'] );
		t_eq( Retrieval::SUCCESS, $item['retrieval_status'] );
		t_eq( ZZZT_MOCK . '/article/redirected-target', $item['canonical_url'], 'canonical read from the page after a redirect' );
		t_eq( 'Pat Reporter', $item['source_author'] );
		t_true( (int) $item['source_word_count'] >= 250, 'words counted' );
		t_contains( 'Officials confirmed the figures', (string) $item['source_text'] );
		t_not_contains( 'Buy things now', (string) $item['source_text'], 'ads removed' );
		t_not_contains( 'Menu item', (string) $item['source_text'], 'navigation removed' );
		t_not_contains( 'Related stories list', (string) $item['source_text'], 'related block removed' );
		t_not_contains( 'Copyright', (string) $item['source_text'], 'footer removed' );
		t_eq( 16, strlen( (string) $item['content_fingerprint'] ) );
	}
);

t(
	'Retrieval',
	'Source threshold is configurable',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'min_source_words' => 5000 ) );
		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/short-for-threshold' );
		Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) );
		t_eq( Lifecycle::PARTIAL, job_status( $job ), 'below the raised threshold' );

		Settings::update( array( 'min_source_words' => 100, 'partial_min_words' => 50 ) );
		$job = make_job_from_url( $category, ZZZT_MOCK . '/partial' );
		Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) );
		t_eq( Lifecycle::AI_PENDING, job_status( $job ), 'above the lowered threshold' );
	}
);

t(
	'AC-16,Security',
	'SSRF checks refuse private networks, unsafe schemes and credentials',
	static function () {
		remove_all_filters( 'zzzc_http_allowed_hosts' );

		$refused = array(
			'http://127.0.0.1/'                => 'private_address',
			'http://localhost/'                => 'private_address',
			'http://10.0.0.5/'                 => 'private_address',
			'http://172.16.0.1/'               => 'private_address',
			'http://192.168.1.1/'              => 'private_address',
			'http://169.254.169.254/latest/'   => 'private_address',
			'http://[::1]/'                    => 'private_address',
			'http://100.64.0.1/'               => 'private_address',
			'file:///etc/passwd'               => 'invalid_url',
			'ftp://example.com/x'              => 'unsupported_scheme',
			'gopher://example.com/'            => 'unsupported_scheme',
			'http://user:pw@example.com/'      => 'credentials_in_url',
			'http://example.com:8080/'         => 'blocked_port',
			'not a url'                        => 'invalid_url',
		);
		foreach ( $refused as $url => $reason ) {
			$check = Http::check_url( $url );
			t_false( $check['ok'], $url );
			t_eq( $reason, $check['reason'], $url );
		}

		$result = Http::get( 'http://127.0.0.1:8091/article/should-not-load' );
		t_contains( 'unsafe_url', $result['error'], 'private address is not fetched' );

		add_filter( 'zzzc_http_allowed_hosts', static fn() => array( '127.0.0.1:8091' ) );

		$hop = Http::get( ZZZT_MOCK . '/to-private' );
		t_contains( 'unsafe_url', $hop['error'], 'redirect to a metadata address is refused' );

		$ok = Http::get( ZZZT_MOCK . '/to-article' );
		t_true( $ok['ok'], 'safe redirect is followed' );
		t_eq( ZZZT_MOCK . '/article/redirected-target', $ok['final_url'] );

		t_true( Http::is_public_ip( '8.8.8.8' ) );
		t_false( Http::is_public_ip( '::ffff:127.0.0.1' ), 'mapped IPv6' );
		t_false( Http::is_public_ip( 'fd00::1' ), 'unique local IPv6' );
		t_false( Http::is_public_ip( 'fe80::1' ), 'link local IPv6' );
	}
);

t(
	'Security',
	'robots.txt rules: agent groups, longest match and wildcards',
	static function () {
		$parsed = Robots::parse( "User-agent: *\nDisallow: /private/\nAllow: /private/open\nDisallow: /*.pdf\$\nSitemap: https://x.example/news.xml\n\nUser-agent: Other\nDisallow: /\n" );
		$rules  = $parsed['rules'];
		t_true( Robots::path_allowed( $rules, '/public' ) );
		t_false( Robots::path_allowed( $rules, '/private/x' ) );
		t_true( Robots::path_allowed( $rules, '/private/open/x' ), 'longer allow wins' );
		t_false( Robots::path_allowed( $rules, '/a/b.pdf' ), 'wildcard with end anchor' );
		t_true( Robots::path_allowed( $rules, '/a/b.pdf?x=1' ) );
		t_eq( array( 'https://x.example/news.xml' ), $parsed['sitemaps'] );

		$specific = Robots::parse( "User-agent: *\nDisallow: /\n\nUser-agent: ZZZCopyDasSobur\nAllow: /\n" );
		t_true( Robots::path_allowed( $specific['rules'], '/x' ), 'own group wins over *' );

		t_true( Robots::allowed( ZZZT_MOCK . '/article/x' ), 'live: allowed path' );
		t_false( Robots::allowed( ZZZT_MOCK . '/private/secret' ), 'live: disallowed path' );
		t_true( Robots::allowed( ZZZT_MOCK . '/private/open' ), 'live: allow override' );
	}
);

t(
	'Discovery,Resolver',
	'A Google-style link is resolved through the publisher feed, and is not guessed when there is no match',
	static function () {
		mock_state(
			array(
				'feeds' => array(
					'feed' => array(
						array( 'title' => 'City opens new tram line after long delay', 'link' => ZZZT_MOCK . '/article/tram-line', 'pubDate' => gmdate( 'r' ) ),
						array( 'title' => 'Other unrelated bakery story here', 'link' => ZZZT_MOCK . '/article/bakery', 'pubDate' => gmdate( 'r' ) ),
					),
				),
			)
		);

		$hit = PublisherResolver::resolve( 'City opens new tram line after long delay', ZZZT_MOCK );
		t_eq( ZZZT_MOCK . '/article/tram-line', $hit['url'] );
		t_eq( 'publisher_feed_match', $hit['method'] );

		$miss = PublisherResolver::resolve( 'Completely different headline about astronomy today', ZZZT_MOCK );
		t_eq( '', $miss['url'], 'no match gives no address' );
		t_contains( 'No headline', $miss['reason'] );

		// Full job from a Google-style record.
		$category = ready_plugin();
		mock_state( array( 'feeds' => array( 'feed' => array( array( 'title' => 'City opens new tram line after long delay', 'link' => ZZZT_MOCK . '/article/tram-line', 'pubDate' => gmdate( 'r' ) ) ) ) ) );
		delete_transient( 'zzzc_entries_' . md5( ZZZT_MOCK . '/feed.xml' ) );

		$entry = array(
			'title'       => 'City opens new tram line after long delay - Mock Daily',
			'link'        => 'https://news.google.com/rss/articles/CBMiXYZ?oc=5',
			'guid'        => 'CBMiXYZ',
			'published'   => time() - 100,
			'snippet'     => 'x',
			'source_name' => 'Mock Daily',
			'source_url'  => ZZZT_MOCK,
			'author'      => '',
		);
		$counts = Discovery::ingest( Categories::get( $category ), array( $entry ), Discovery::GOOGLE, 'Google News topic', 'f' );
		t_eq( 1, $counts['new'] );

		global $wpdb;
		$item = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'source_items' ) . ' LIMIT 1', ARRAY_A );
		t_eq( 'City opens new tram line after long delay', $item['headline'] );
		t_eq( null, $item['resolved_url'], 'Google link is not treated as the publisher address' );

		$job = Planner::create_article_job( $item );
		Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) );
		$item = Lifecycle::item( (int) $item['id'] );
		t_eq( ZZZT_MOCK . '/article/tram-line', $item['resolved_url'] );
		t_eq( Lifecycle::AI_PENDING, job_status( $job ) );

		// No match means NOT_FOUND and no AI.
		$entry['guid']  = 'CBMiNOPE';
		$entry['title'] = 'Nothing in the feed matches this astronomy headline - Mock Daily';
		Discovery::ingest( Categories::get( $category ), array( $entry ), Discovery::GOOGLE, 'Google News topic', 'f' );
		$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'source_items' ) . " WHERE story_id = 'CBMiNOPE'", ARRAY_A );
		$job = Planner::create_article_job( $row );
		Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) );
		t_eq( Lifecycle::BLOCKED, job_status( $job ) );
		t_eq( Retrieval::NOT_FOUND, Lifecycle::item( (int) $row['id'] )['retrieval_status'] );
	}
);

t(
	'Extractor',
	'Extractor handles JSON-LD, paywall signs, encodings and page chrome',
	static function () {
		$long = str_repeat( 'Word ', 400 );
		$html = '<html><head><title>T</title><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"NewsArticle","headline":"LD Head","datePublished":"2026-11-01T10:00:00Z","author":{"@type":"Person","name":"Ann Writer"},"articleBody":"' . $long . '"}]}</script></head><body><p>x</p></body></html>';
		$out  = Extractor::extract( $html, 'https://a.example/x' );
		t_eq( 'LD Head', $out['title'] );
		t_eq( 'Ann Writer', $out['author'] );
		t_true( $out['word_count'] >= 400, 'articleBody used' );
		t_true( $out['published'] > 0 );
		t_false( $out['paywall'] );

		$paid = Extractor::extract( '<html><head><meta property="article:content_tier" content="locked"></head><body><p>Short</p></body></html>' );
		t_true( $paid['paywall'], 'content tier' );

		$latin = mb_convert_encoding( '<html><head><meta charset="iso-8859-1"></head><body><article><p>' . str_repeat( 'Café crème résumé text here. ', 30 ) . '</p></article></body></html>', 'ISO-8859-1', 'UTF-8' );
		t_contains( 'Café crème', Extractor::extract( $latin )['text'], 'encoding converted' );

		$fallback = '<html><body><div class="wrap"><div id="content"><p>' . str_repeat( 'Main story text sits here in this block. ', 40 ) . '</p><p>' . str_repeat( 'More of the story continues. ', 30 ) . '</p></div><div><p>Tiny</p></div></div></body></html>';
		t_contains( 'Main story text', Extractor::extract( $fallback )['text'], 'block with most text' );

		t_eq( '', Extractor::extract( '' )['text'] );
	}
);

t(
	'Extractor',
	'An article inside a block named like page chrome is kept, while real chrome is removed',
	static function () {
		$para = static fn( int $n ): string => '<p>' . str_repeat( "The council published part $n of the full report on the bridge today. ", 6 ) . '</p>';
		$body = $para( 1 ) . $para( 2 ) . $para( 3 );
		$html = '<html><body><nav class="site-menu"><a href="/">Home</a> <a href="/x">Menu item</a></nav><div class="share-bar"><p>Share this story</p></div>'
			. '<div class="entry-content single__content--with-share">' . $body . '</div>'
			. '<div class="related-stories"><p>Other story link</p></div></body></html>';
		$out = Extractor::extract( $html );
		t_contains( 'The council published', $out['text'], 'article kept' );
		t_true( $out['word_count'] >= 120, 'full text' );
		t_not_contains( 'Share this story', $out['text'], 'share bar removed' );
		t_not_contains( 'Other story link', $out['text'], 'related block removed' );
		t_not_contains( 'Menu item', $out['text'], 'menu removed' );

		// A whole page wrapped in a form, as some site builders do.
		$wrapped = '<html><body><form id="aspnetForm"><div>' . $body . '</div></form></body></html>';
		t_contains( 'The council published', Extractor::extract( $wrapped )['text'], 'form wrapper kept' );

		// A marked container holding a teaser loses to the real article body.
		$teaser = '<html><body><main><p>' . str_repeat( 'Short teaser words here now. ', 12 ) . '</p></main><div class="story">' . $body . $para( 4 ) . $para( 5 ) . '</div></body></html>';
		t_contains( 'The council published', Extractor::extract( $teaser )['text'], 'densest block wins over a teaser' );
	}
);

t(
	'Extractor',
	'Canonical links are made absolute',
	static function () {
		$out = Extractor::extract( '<html><head><link rel="canonical" href="/stories/1"></head><body></body></html>', 'https://a.example/x?y=1' );
		t_eq( 'https://a.example/stories/1', $out['canonical'] );
		t_eq( 'https://a.example/b/c', Http::absolute_url( 'c', 'https://a.example/b/d' ) );
		t_eq( 'https://z.example/q', Http::absolute_url( '//z.example/q', 'https://a.example/' ) );
		t_eq( 120, Http::parse_retry_after( '120' ) );
		t_eq( 0, Http::parse_retry_after( '' ) );
	}
);

/**
 * Runs a callback while every outbound request fails, as in a brief outage.
 */
function with_network_down( callable $callback ) {
	$down = static function () {
		return new WP_Error( 'http_request_failed', 'Simulated outage' );
	};
	add_filter( 'pre_http_request', $down );
	try {
		return $callback();
	} finally {
		remove_filter( 'pre_http_request', $down );
	}
}

/**
 * Forgets what is remembered about a publisher, as happens when the short wait after an outage ends.
 */
function forget_robots(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%zzzc\\_robots\\_%'" );
	wp_cache_flush();
}

t(
	'NET-1',
	'A name that cannot be looked up is a temporary network problem, and real refusals stay refusals',
	static function () {
		$result = Http::get( 'https://no-such-host.invalid/x' );
		t_eq( 'network:dns_failed', $result['error'] );
		t_true( Http::is_temporary( $result ), 'can be tried again' );
		t_false( Http::check_url( 'https://no-such-host.invalid/x' )['ok'], 'still not requested' );

		$private = Http::get( 'http://169.254.169.254/latest/' );
		t_contains( 'unsafe_url', $private['error'] );
		t_false( Http::is_temporary( $private ), 'a refusal is final' );

		t_true( Http::is_temporary( array( 'error' => '', 'code' => 429 ) ) );
		t_true( Http::is_temporary( array( 'error' => '', 'code' => 503 ) ) );
		t_false( Http::is_temporary( array( 'error' => '', 'code' => 404 ) ) );
	}
);

t(
	'NET-2',
	'An unreadable robots.txt retries the story instead of blocking it, and a real refusal still blocks it',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/outage-story', 'Outage story headline here' );
		$claim    = array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 );

		$robots = with_network_down( static fn() => Robots::status( ZZZT_MOCK . '/article/outage-story' ) );
		t_eq( Robots::UNAVAILABLE, $robots );
		t_false( Robots::allowed( ZZZT_MOCK . '/article/outage-story' ), 'nothing is fetched while it is unreadable' );

		$result = Retrieval::handle( $claim );
		t_eq( ZZZCopy\JobResult::RETRY, $result->outcome );
		t_eq( 'robots_unavailable', $result->code );
		t_true( $result->delay > 15 * MINUTE_IN_SECONDS, 'waits until robots.txt will be read again' );
		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $job ), 'not blocked' );

		forget_robots();
		Retrieval::handle( $claim );
		t_false( Lifecycle::RETRIEVAL_PENDING === job_status( $job ), 'moves on once readable' );
		t_false( Lifecycle::BLOCKED === job_status( $job ), 'and is not blocked' );

		$refused = make_job_from_url( $category, ZZZT_MOCK . '/private/secret', 'A story the publisher disallows' );
		Retrieval::handle( array( 'payload' => array( 'article_job_id' => $refused ), 'attempts' => 1 ) );
		t_eq( Lifecycle::BLOCKED, job_status( $refused ) );
		t_eq( 'robots_disallowed', Lifecycle::job( $refused )['error_code'] );

		// When the attempts run out, the story shows as failed and can be retried.
		$stuck = make_job_from_url( $category, ZZZT_MOCK . '/article/still-down', 'A story that never becomes readable' );
		ZZZCopy\Runner::on_job_exhausted( ZZZCopy\Jobs::RETRIEVE, array( 'article_job_id' => $stuck ), 'robots_unavailable', 'Still unreadable.' );
		t_eq( Lifecycle::RETRIEVAL_FAILED, job_status( $stuck ) );
	}
);

t(
	'NET-3',
	'A publisher feed is retried when its robots.txt is unreadable, and is refused when it disallows',
	static function () {
		$fetched = with_network_down( static fn() => Discovery::fetch( Discovery::FEED, ZZZT_MOCK . '/feed.xml' ) );
		t_eq( 'robots_unavailable', $fetched['code'] );
		t_true( $fetched['retry'] );
		t_eq( Robots::RETRY_SECONDS, $fetched['delay'] );

		forget_robots();
		$refused = Discovery::fetch( Discovery::FEED, ZZZT_MOCK . '/private/feed.xml' );
		t_eq( 'robots_disallowed', $refused['code'] );
		t_false( $refused['retry'] );
	}
);

t(
	'NET-4',
	'An outage while matching a Google story is retried and is not remembered as "no feed"',
	static function () {
		$category = ready_plugin();
		$headline = 'City opens new tram line after long delay';
		mock_state( array( 'feeds' => array( 'feed' => array( array( 'title' => $headline, 'link' => ZZZT_MOCK . '/article/tram-line', 'pubDate' => gmdate( 'r' ) ) ) ) ) );

		$miss = with_network_down( static fn() => PublisherResolver::resolve( $headline, ZZZT_MOCK ) );
		t_eq( '', $miss['url'] );
		t_true( $miss['temporary'], 'an outage is not a final answer' );
		t_false( get_transient( 'zzzc_feeds_' . md5( strtolower( ZZZT_MOCK ) ) ), 'the empty feed list is not kept for a day' );

		$entry = array(
			'title'       => $headline . ' - Mock Daily',
			'link'        => 'https://news.google.com/rss/articles/CBMiOutage?oc=5',
			'guid'        => 'CBMiOutage',
			'published'   => time() - 100,
			'snippet'     => 'x',
			'source_name' => 'Mock Daily',
			'source_url'  => ZZZT_MOCK,
			'author'      => '',
		);
		Discovery::ingest( Categories::get( $category ), array( $entry ), Discovery::GOOGLE, 'Google News topic', 'f' );
		global $wpdb;
		$item = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'source_items' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$job  = Planner::create_article_job( $item );

		$result = with_network_down( static fn() => Retrieval::handle( array( 'payload' => array( 'article_job_id' => $job ), 'attempts' => 1 ) ) );
		t_eq( ZZZCopy\JobResult::RETRY, $result->outcome );
		t_eq( 'resolver_unavailable', $result->code );
		t_eq( Lifecycle::RETRIEVAL_PENDING, job_status( $job ), 'not blocked' );

		forget_robots();
		$hit = PublisherResolver::resolve( $headline, ZZZT_MOCK );
		t_eq( ZZZT_MOCK . '/article/tram-line', $hit['url'], 'found once the publisher answers' );
		t_false( $hit['temporary'] );

		$no_match = PublisherResolver::resolve( 'Completely different headline about astronomy today', ZZZT_MOCK );
		t_eq( '', $no_match['url'] );
		t_false( $no_match['temporary'], 'a real miss stays final' );
	}
);

t(
	'NET-5',
	'A private address is refused however it is written in IPv6, and public addresses still pass',
	static function () {
		$refused = array(
			'::ffff:127.0.0.1',
			'::FFFF:10.0.0.1',
			'::ffff:169.254.169.254',
			'::ffff:7f00:1',
			'0:0:0:0:0:ffff:127.0.0.1',
			'0:0:0:0:0:ffff:7f00:1',
			'::127.0.0.1',
			'::ffff:0:127.0.0.1',
			'::1',
			'::',
			'fc00::1',
			'fd12:3456::1',
			'FD00::1',
			'fe80::1',
			'febf::1',
			'127.0.0.1',
			'10.0.0.1',
			'169.254.169.254',
			'100.64.0.1',
			'not-an-address',
		);
		foreach ( $refused as $ip ) {
			t_false( Http::is_public_ip( $ip ), $ip );
		}

		foreach ( array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2a00:1450:4001::1' ) as $ip ) {
			t_true( Http::is_public_ip( $ip ), $ip );
		}

		foreach ( array( 'http://[::ffff:127.0.0.1]/', 'http://[0:0:0:0:0:ffff:7f00:1]/', 'http://[::127.0.0.1]/' ) as $url ) {
			t_false( Http::check_url( $url )['ok'], $url );
			t_contains( 'unsafe_url', Http::get( $url )['error'], $url );
		}
	}
);
