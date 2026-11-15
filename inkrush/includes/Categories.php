<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * News category configuration.
 */
final class Categories {

	/**
	 * Publisher feeds added in 1.11.2, after the 10:00 post of 2026-11-15 was skipped because both
	 * Business feeds had nothing new and the Google News stories could not be read.
	 *
	 * Each was checked on 2026-11-15 with this plugin's own FeedParser, Robots rules and Extractor:
	 * its robots.txt allows the feed and the articles, and its newest three articles were read with
	 * 250 words or more and no paywall. Refused then: Fortune, Business Insider and The Verge
	 * (paywall), Forbes and Sky News (HTTP 403), Ars Technica (HTTP 405), The Register and Fox
	 * Business (robots.txt), NPR (connection reset) and The Independent (no stories read).
	 */
	public const ADDED_FEEDS = array(
		'Business'   => "https://www.theguardian.com/business/rss\nhttps://www.abc.net.au/news/feed/51892/rss.xml\nhttps://theconversation.com/au/business/articles.atom\nhttps://www.cbsnews.com/latest/rss/moneywatch",
		'Technology' => "https://www.engadget.com/rss.xml\nhttps://www.techradar.com/rss\nhttps://www.theguardian.com/technology/rss\nhttps://www.cnbc.com/id/19854910/device/rss/rss.html\nhttps://gizmodo.com/feed\nhttps://9to5mac.com/feed/\nhttps://www.wired.com/feed/rss\nhttps://www.zdnet.com/news/rss.xml\nhttps://www.technologyreview.com/feed/\nhttps://www.itnews.com.au/RSS/rss.ashx",
	);

	/**
	 * Publisher feeds added in 1.11.3 to the other categories, checked on 2026-11-15 the same way
	 * as ADDED_FEEDS. A feed passed when robots.txt allows it and its articles, and at least two of
	 * its newest three articles were read with 250 words or more and none showed a paywall.
	 *
	 * Refused then: Al Jazeera (robots.txt refuses the articles), ABC World 52278 and two Guardian
	 * crime feeds (HTTP 404), The Hollywood Reporter, Deadline, Live Science, Men's Health and
	 * Runner's World (paywall), People (HTTP 402), Just Jared, The Hill, GameSpot, Heritage Daily and
	 * PropertyCasualty360 (HTTP 403), CBS Entertainment (video pages), CNBC Personal Finance and
	 * Insurance Business (under 250 words), Self (feed two years old), Ancient Origins and The Krazy
	 * Coupon Lady (no stories read). Insurance, Crime, Myths & Legends and Fitness gained nothing.
	 */
	public const FEEDS_1_11_3 = array(
		'World'                      => "https://www.cbsnews.com/latest/rss/world\nhttps://www.france24.com/en/rss\nhttps://rss.dw.com/rdf/rss-en-all",
		'Entertainment'              => 'https://www.theguardian.com/culture/rss',
		'Celebrity'                  => 'https://www.eonline.com/syndication/feeds/rssfeeds/topstories.xml',
		'Politics'                   => "https://www.cbsnews.com/latest/rss/politics\nhttps://www.theguardian.com/politics/rss\nhttps://www.theguardian.com/australia-news/australian-politics/rss",
		'Science'                    => "https://www.theguardian.com/science/rss\nhttps://feeds.bbci.co.uk/news/science_and_environment/rss.xml",
		'Sports'                     => "https://www.theguardian.com/sport/rss\nhttps://www.cbssports.com/rss/headlines/\nhttps://www.abc.net.au/news/feed/45924/rss.xml",
		'Medical'                    => "https://www.theguardian.com/society/health/rss\nhttps://www.cbsnews.com/latest/rss/health",
		'War & Conflict'             => "https://www.militarytimes.com/arc/outboundfeeds/rss/\nhttps://breakingdefense.com/feed/",
		'Celebrity News'             => "https://pagesix.com/feed/\nhttps://www.usmagazine.com/feed/",
		'Coupon Blogs'               => "https://hip2save.com/feed/\nhttps://www.moneysavingmom.com/feed/",
		'Referral Programs & Blogs'  => "https://thepointsguy.com/feed/\nhttps://www.nerdwallet.com/blog/feed/",
		'Gaming'                     => "https://www.pcgamer.com/rss/\nhttps://kotaku.com/rss\nhttps://feeds.feedburner.com/ign/all",
		'Health & Wellness'          => "https://www.theguardian.com/society/health/rss\nhttps://www.cbsnews.com/latest/rss/health\nhttps://medicalxpress.com/rss-feed/",
		'Finance & Retirement'       => 'https://www.theguardian.com/money/rss',
		'Technology & Consumer News' => "https://www.techradar.com/rss\nhttps://9to5mac.com/feed/\nhttps://gizmodo.com/feed",
	);

	/**
	 * Adds feeds to every live category with this exact name, after the ones it has. A feed
	 * already listed, in any case or with or without a trailing slash, is not added twice.
	 *
	 * @return int How many feeds were added in all.
	 */
	public static function add_feeds( string $name, string $feeds ): int {
		global $wpdb;
		$table = Db::table( 'categories' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, feed_urls FROM {$table} WHERE name = %s AND deleted_at IS NULL", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$added = 0;
		foreach ( (array) $rows as $row ) {
			$current = (string) $row['feed_urls'];
			$merged  = self::merge_feeds( $current, $feeds );
			$count   = self::count_lines( $merged ) - self::count_lines( $current );
			if ( $count > 0 ) {
				$wpdb->update(
					$table,
					array(
						'feed_urls'  => $merged,
						'updated_at' => Clock::mysql(),
					),
					array( 'id' => (int) $row['id'] )
				);
				$added += $count;
			}
		}
		return $added;
	}

	/**
	 * The current feeds, then each new one not already among them. Compared without case or a
	 * trailing slash.
	 */
	public static function merge_feeds( string $current, string $more ): string {
		$key  = static function ( string $url ): string {
			return rtrim( strtolower( trim( $url ) ), '/' );
		};
		$list = self::lines_of( $current );
		$seen = array_map( $key, $list );
		foreach ( self::lines_of( $more ) as $url ) {
			if ( ! in_array( $key( $url ), $seen, true ) ) {
				$list[] = $url;
				$seen[] = $key( $url );
			}
		}
		return implode( "\n", $list );
	}

	private static function lines_of( string $text ): array {
		return array_values( array_filter( preg_split( '/\s+/', trim( $text ) ) ?: array(), 'strlen' ) );
	}

	private static function count_lines( string $text ): int {
		return count( self::lines_of( $text ) );
	}

	/**
	 * Feeds that can never give a usable story, removed in 1.11.4.
	 *
	 * iHeartPublix was in the starting list for Coupon Blogs. On 2026-11-15 its newest posts had
	 * 111, 121 and 126 words, under min_source_words (250), so every story from it fails. The
	 * category keeps Hip2Save and Money Saving Mom from 1.11.3.
	 */
	public const REMOVED_FEEDS = array(
		'Coupon Blogs' => 'https://www.iheartpublix.com/feed/',
	);

	/**
	 * Feeds added in 1.11.5 to the four categories that gained nothing in 1.11.3, checked on
	 * 2026-11-15 the same way: robots.txt allows the feed and its articles, and at least two of the
	 * newest three articles were read with 250 words or more and none showed a paywall.
	 *
	 * Refused then: Business Insurance and Insurance Business AU (under 250 words), Coverager,
	 * Courthouse News, The Marshall Project, Law&Crime, World History Encyclopedia, Greek Reporter and
	 * Men's Journal (HTTP 403), dig-in, Atlas Obscura, Arkeonews and Mythology Planet (no stories
	 * read), Ancient Origins, T Nation, BarBend and Garage Gym Reviews (HTTP 404), HistoryExtra
	 * (paywall, and robots.txt refuses its articles), CrimeOnline (pages of 20 words), Oxygen, The
	 * Vintage News, Breaking Muscle, Nerd Fitness, TrainingPeaks and CoachWeb (no story in weeks, or
	 * dates years old), iRunFar (robots.txt), ThinkAdvisor, Insurance Journal and Stack (certificate
	 * errors from the testing machine). NBC News and New York Post Metro passed but are general news,
	 * so they are not added to Crime.
	 */
	public const FEEDS_1_11_5 = array(
		'Insurance'       => "https://www.artemis.bm/feed/\nhttps://www.carriermanagement.com/feed/\nhttps://www.reinsurancene.ws/feed/",
		'Crime'           => "https://nypost.com/tag/crime/feed/\nhttps://www.theguardian.com/us-news/us-crime/rss\nhttps://www.thetrace.org/feed/",
		'Myths & Legends' => "https://www.thecollector.com/feed/\nhttps://www.labrujulaverde.com/en/feed/",
		'Fitness'         => "https://www.theguardian.com/lifeandstyle/fitness/rss\nhttps://www.mindbodygreen.com/rss/feed.xml",
	);

	/**
	 * Feeds removed in 1.11.5. Live Science's articles are all marked as behind a paywall, so every
	 * story from it fails as paywalled. Defense News's newest items were video pages of 19 and 30
	 * words. Each category keeps the feeds added in 1.11.3 or 1.11.5.
	 */
	public const REMOVED_FEEDS_1_11_5 = array(
		'Myths & Legends' => 'https://www.livescience.com/feeds.xml',
		'War & Conflict'  => 'https://www.defensenews.com/arc/outboundfeeds/rss/',
	);

	/**
	 * Removes feeds from every live category with this exact name. Compared without case or a
	 * trailing slash. A feed the owner did not list is left alone, as is every other feed.
	 *
	 * @return int How many feeds were removed in all.
	 */
	public static function remove_feeds( string $name, string $feeds ): int {
		global $wpdb;
		$table   = Db::table( 'categories' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, feed_urls FROM {$table} WHERE name = %s AND deleted_at IS NULL", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$key     = static function ( string $url ): string {
			return rtrim( strtolower( trim( $url ) ), '/' );
		};
		$drop    = array_map( $key, self::lines_of( $feeds ) );
		$removed = 0;
		foreach ( (array) $rows as $row ) {
			$current = self::lines_of( (string) $row['feed_urls'] );
			$kept    = array_values(
				array_filter(
					$current,
					static function ( $url ) use ( $key, $drop ) {
						return ! in_array( $key( $url ), $drop, true );
					}
				)
			);
			if ( count( $kept ) < count( $current ) ) {
				$wpdb->update(
					$table,
					array(
						'feed_urls'  => implode( "\n", $kept ),
						'updated_at' => Clock::mysql(),
					),
					array( 'id' => (int) $row['id'] )
				);
				$removed += count( $current ) - count( $kept );
			}
		}
		return $removed;
	}

	/**
	 * The owner's starting list, in the owner's order. All of it can be edited, and more can be added.
	 *
	 * Each publisher feed was checked against its robots.txt, and its newest article could be read.
	 * Categories with few suitable feeds use a one-week recency window, so they find something.
	 * Google topic feeds rarely have news under an hour old, so a category with a topic also has a search.
	 */
	private const SEEDS = array(
		'Technology'                 => array(
			'topic' => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRGRqTVhZU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'technology news',
			'feeds' => "https://feeds.bbci.co.uk/news/technology/rss.xml\nhttps://techcrunch.com/feed/",
		),
		'World'                      => array(
			'query' => 'world news',
			'feeds' => "https://feeds.bbci.co.uk/news/world/rss.xml\nhttps://www.theguardian.com/world/rss",
		),
		'Entertainment'              => array(
			'topic' => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNREpxYW5RU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'entertainment news',
			'feeds' => "https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml\nhttps://variety.com/feed/",
		),
		'Celebrity'                  => array(
			'query' => 'celebrity',
			'feeds' => "https://pagesix.com/feed/\nhttps://www.usmagazine.com/feed/",
		),
		'Business'                   => array(
			'topic' => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRGx6TVdZU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'business news',
			'feeds' => "https://feeds.bbci.co.uk/news/business/rss.xml\nhttps://www.cnbc.com/id/10001147/device/rss/rss.html",
		),
		'Politics'                   => array(
			'query' => 'politics',
			'feeds' => "https://feeds.bbci.co.uk/news/politics/rss.xml\nhttps://www.theguardian.com/us-news/us-politics/rss",
		),
		'Science'                    => array(
			'topic' => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRFp0Y1RjU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'science news',
			'feeds' => "https://www.sciencedaily.com/rss/top/science.xml\nhttps://phys.org/rss-feed/",
		),
		'Sports'                     => array(
			'topic' => 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRFp1ZEdvU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'sports news',
			'feeds' => "https://feeds.bbci.co.uk/sport/rss.xml\nhttps://www.espn.com/espn/rss/news",
		),
		'Medical'                    => array(
			'query' => 'medical research',
			'feeds' => "https://medicalxpress.com/rss-feed/\nhttps://www.sciencedaily.com/rss/health_medicine.xml",
		),
		'Insurance'                  => array(
			'query' => 'insurance news',
			'feeds' => "https://www.insurancejournal.com/rss/news/\nhttps://www.claimsjournal.com/rss/news/",
		),
		'War & Conflict'             => array(
			'query' => 'war conflict',
			'feeds' => 'https://warontherocks.com/feed/',
		),
		'Crime'                      => array(
			'query' => 'crime news',
			'feeds' => "https://www.cbsnews.com/latest/rss/crime\nhttps://www.theguardian.com/uk/ukcrime/rss",
		),
		'Celebrity News'             => array(
			'query' => 'celebrity news',
			'feeds' => "https://www.eonline.com/syndication/feeds/rssfeeds/topstories.xml\nhttps://www.etonline.com/news/rss",
		),
		'Myths & Legends'            => array(
			'query'    => 'mythology legends',
			'feeds'    => '',
			'recency'  => '7d',
			'required' => "myth\nlegend\nancient\nfolklore",
		),
		'Coupon Blogs'               => array(
			'query'   => 'coupons deals',
			'feeds'   => '',
			'recency' => '7d',
		),
		'Referral Programs & Blogs'  => array(
			'query'   => 'referral program',
			'feeds'   => "https://prefinery.com/blog/feed/\nhttps://www.doctorofcredit.com/feed/",
			'recency' => '7d',
		),
		'Fitness'                    => array(
			'query'   => 'fitness exercise',
			'feeds'   => 'https://www.outsideonline.com/feed/',
			'recency' => '7d',
		),
		'Gaming'                     => array(
			'query' => 'video games',
			'feeds' => "https://www.polygon.com/rss/index.xml\nhttps://www.eurogamer.net/feed",
		),
		'Health & Wellness'          => array(
			'topic' => 'https://news.google.com/topics/CAAqIQgKIhtDQkFTRGdvSUwyMHZNR3QwTlRFU0FtVnVLQUFQAQ?hl=en-US&gl=US&ceid=US%3Aen',
			'query' => 'health news',
			'feeds' => "https://feeds.bbci.co.uk/news/health/rss.xml\nhttps://www.sciencedaily.com/rss/top/health.xml",
		),
		'Finance & Retirement'       => array(
			'query' => 'retirement personal finance',
			'feeds' => "https://www.kiplinger.com/feeds.xml\nhttps://www.nerdwallet.com/blog/feed/",
		),
		'Technology & Consumer News' => array(
			'query' => 'consumer technology',
			'feeds' => "https://www.engadget.com/rss.xml\nhttps://www.cnet.com/rss/news/",
		),
	);

	private static function seed_row( string $name, array $seed, int $order ): array {
		return array(
			'name'              => $name,
			'sort_order'        => $order,
			'topic_urls'        => (string) ( $seed['topic'] ?? '' ),
			'search_queries'    => (string) ( $seed['query'] ?? '' ),
			'feed_urls'         => self::merge_feeds( (string) ( $seed['feeds'] ?? '' ), ( self::ADDED_FEEDS[ $name ] ?? '' ) . "\n" . ( self::FEEDS_1_11_3[ $name ] ?? '' ) . "\n" . ( self::FEEDS_1_11_5[ $name ] ?? '' ) ),
			'recency'           => (string) ( $seed['recency'] ?? '24h' ),
			'required_keywords' => (string) ( $seed['required'] ?? '' ),
		);
	}

	public static function seed_defaults(): void {
		global $wpdb;
		$table = Db::table( 'categories' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) { // phpcs:ignore WordPress.DB
			return;
		}
		$order = 1;
		foreach ( self::SEEDS as $name => $seed ) {
			self::insert( self::seed_row( $name, $seed, $order++ ) );
		}
	}

	/**
	 * For an existing install: adds starting categories it does not have yet.
	 * Categories already there, edited or not, are left exactly as they are.
	 *
	 * @return int Number added.
	 */
	public static function add_missing_seeds(): int {
		global $wpdb;
		$table    = Db::table( 'categories' );
		$existing = array_map( 'mb_strtolower', $wpdb->get_col( "SELECT name FROM {$table}" ) ); // phpcs:ignore WordPress.DB
		$added    = 0;
		foreach ( self::SEEDS as $name => $seed ) {
			if ( in_array( mb_strtolower( $name ), $existing, true ) ) {
				continue;
			}
			self::insert( self::seed_row( $name, $seed, self::next_sort_order() ) );
			$added++;
		}
		return $added;
	}

	/**
	 * For an existing install: gives a starting category with only a topic address its starting search.
	 * A category whose topic address or search the owner has changed is left exactly as it is.
	 *
	 * @return string[] Names of the categories changed.
	 */
	public static function add_missing_searches(): array {
		$changed = array();
		foreach ( self::all() as $category ) {
			$seed = self::SEEDS[ $category['name'] ] ?? array();
			if ( empty( $seed['topic'] ) || empty( $seed['query'] ) ) {
				continue;
			}
			if ( '' === trim( (string) $category['search_queries'] ) && $seed['topic'] === trim( (string) $category['topic_urls'] ) ) {
				self::update( (int) $category['id'], array( 'search_queries' => $seed['query'] ) );
				$changed[] = (string) $category['name'];
			}
		}
		return $changed;
	}

	/**
	 * What stops a category from being used. Empty when it is ready.
	 *
	 * @return string[]
	 */
	public static function readiness( array $category ): array {
		$problems = array();
		if ( ! Discovery::has_usable_source( $category ) ) {
			$problems[] = 'No source';
		}
		if ( ! self::resolve_term_readonly( $category ) ) {
			$problems[] = 'No WordPress category';
		}
		return $problems;
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = Clock::mysql();
		$wpdb->insert(
			Db::table( 'categories' ),
			array_merge(
				array(
					'enabled'    => 1,
					'weight'     => 1,
					'created_at' => $now,
					'updated_at' => $now,
				),
				$data
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = Clock::mysql();
		$wpdb->update( Db::table( 'categories' ), $data, array( 'id' => $id ) );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Db::table( 'categories' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function name( int $id ): string {
		$row = self::get( $id );
		return $row ? (string) $row['name'] : '';
	}

	/**
	 * Categories in display order, without deleted ones.
	 */
	public static function all(): array {
		global $wpdb;
		$table = Db::table( 'categories' );
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function enabled(): array {
		return array_values(
			array_filter(
				self::all(),
				static function ( $row ) {
					return (int) $row['enabled'] === 1;
				}
			)
		);
	}

	/**
	 * Keeps the record so past articles still show their category.
	 */
	public static function soft_delete( int $id ): void {
		self::update(
			$id,
			array(
				'deleted_at' => Clock::mysql(),
				'enabled'    => 0,
			)
		);
	}

	public static function duplicate( int $id ): int {
		$row = self::get( $id );
		if ( ! $row ) {
			return 0;
		}
		unset( $row['id'], $row['created_at'], $row['updated_at'], $row['deleted_at'] );
		$row['name']       = $row['name'] . ' (copy)';
		$row['enabled']    = 0;
		$row['sort_order'] = self::next_sort_order();
		return self::insert( $row );
	}

	public static function next_sort_order(): int {
		global $wpdb;
		$table = Db::table( 'categories' );
		return 1 + (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Moves a category one place up or down.
	 *
	 * @param int $id        Category id.
	 * @param int $direction -1 for up, 1 for down.
	 */
	public static function move( int $id, int $direction ): void {
		$rows  = self::all();
		$index = null;
		foreach ( $rows as $i => $row ) {
			if ( (int) $row['id'] === $id ) {
				$index = $i;
				break;
			}
		}
		$swap = null === $index ? null : $index + $direction;
		if ( null === $swap || ! isset( $rows[ $swap ] ) ) {
			return;
		}
		$moved          = $rows[ $index ];
		$rows[ $index ] = $rows[ $swap ];
		$rows[ $swap ]  = $moved;
		foreach ( $rows as $order => $row ) {
			self::update( (int) $row['id'], array( 'sort_order' => $order + 1 ) );
		}
	}

	/**
	 * Cleans category form input.
	 *
	 * @param array $input Raw, unslashed form values.
	 * @return array{data:array, errors:array<string,string>}
	 */
	public static function sanitize_input( array $input ): array {
		$errors = array();
		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = 'Enter a category name.';
		}

		$topic_urls = self::clean_url_lines( (string) ( $input['topic_urls'] ?? '' ), $errors, 'topic_urls', 'news.google.com' );
		$feed_urls  = self::clean_url_lines( (string) ( $input['feed_urls'] ?? '' ), $errors, 'feed_urls' );

		$recency = (string) ( $input['recency'] ?? '24h' );
		if ( ! in_array( $recency, array( '1h', '24h', '7d', 'custom' ), true ) ) {
			$recency = '24h';
		}

		$custom_from = self::parse_local_datetime( (string) ( $input['custom_from'] ?? '' ) );
		$custom_to   = self::parse_local_datetime( (string) ( $input['custom_to'] ?? '' ) );
		if ( 'custom' === $recency ) {
			if ( ! $custom_from || ! $custom_to ) {
				$errors['custom_from'] = 'Enter both a start and an end date and time for a custom range.';
			} elseif ( $custom_from >= $custom_to ) {
				$errors['custom_from'] = 'The start of the custom range must be before the end.';
			}
		}

		$language = preg_replace( '/[^A-Za-z\-]/', '', (string) ( $input['language'] ?? 'en-US' ) );
		$region   = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $input['region'] ?? 'US' ) ) );

		$mode = (string) ( $input['source_mode'] ?? 'both' );
		if ( ! in_array( $mode, array( 'both', 'google', 'feeds' ), true ) ) {
			$mode = 'both';
		}

		$publish_mode = (string) ( $input['publish_mode'] ?? '' );
		if ( ! in_array( $publish_mode, array( '', 'draft', 'publish' ), true ) ) {
			$publish_mode = '';
		}

		$days = array_values( array_intersect( array_map( 'strval', (array) ( $input['active_days'] ?? array() ) ), array( '1', '2', '3', '4', '5', '6', '7' ) ) );
		if ( ! $days ) {
			$errors['active_days'] = 'Choose at least one day.';
		}

		return array(
			'data'   => array(
				'name'              => $name,
				'enabled'           => empty( $input['enabled'] ) ? 0 : 1,
				'weight'            => max( 1, min( 100, (int) ( $input['weight'] ?? 1 ) ) ),
				'source_mode'       => $mode,
				'topic_urls'        => $topic_urls,
				'search_queries'    => self::clean_lines( (string) ( $input['search_queries'] ?? '' ) ),
				'feed_urls'         => $feed_urls,
				'required_keywords' => self::clean_lines( (string) ( $input['required_keywords'] ?? '' ) ),
				'excluded_keywords' => self::clean_lines( (string) ( $input['excluded_keywords'] ?? '' ) ),
				'recency'           => $recency,
				'custom_from'       => $custom_from,
				'custom_to'         => $custom_to,
				'language'          => $language ?: 'en-US',
				'region'            => $region ?: 'US',
				'active_days'       => implode( ',', $days ),
				'wp_term_id'        => absint( $input['wp_term_id'] ?? 0 ),
				'publish_mode'      => $publish_mode,
			),
			'errors' => $errors,
		);
	}

	private static function clean_lines( string $text ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $text ) ?: array();
		$lines = array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' );
		return implode( "\n", $lines );
	}

	private static function clean_url_lines( string $text, array &$errors, string $field, string $required_host = '' ): string {
		$clean = array();
		foreach ( preg_split( '/\s+/', trim( $text ) ) ?: array() as $url ) {
			if ( '' === $url ) {
				continue;
			}
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			$host   = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! $host ) {
				$errors[ $field ] = sprintf( 'This is not a valid web address: %s', $url );
				continue;
			}
			if ( $required_host && strtolower( (string) $host ) !== $required_host ) {
				$errors[ $field ] = sprintf( 'Topic addresses must be on %s: %s', $required_host, $url );
				continue;
			}
			$clean[] = esc_url_raw( $url );
		}
		return implode( "\n", $clean );
	}

	/**
	 * Converts a local datetime-local value in the plugin timezone to UTC.
	 */
	private static function parse_local_datetime( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			$date = new \DateTimeImmutable( $value, Schedule::timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}

	/**
	 * Whether a WordPress category can be found or created, without changing anything.
	 */
	public static function resolve_term_readonly( array $category ): bool {
		$term_id = (int) $category['wp_term_id'];
		if ( $term_id > 0 ) {
			$term = get_term( $term_id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return true;
			}
		}
		return get_term_by( 'name', $category['name'], 'category' ) || Settings::get( 'category_auto_create' );
	}

	/**
	 * Finds or creates the WordPress category for a plugin category.
	 *
	 * @return int|\WP_Error Term id.
	 */
	public static function resolve_term( array $category ) {
		$term_id = (int) $category['wp_term_id'];
		if ( $term_id > 0 ) {
			$term = get_term( $term_id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		$existing = get_term_by( 'name', $category['name'], 'category' );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		if ( Settings::get( 'category_auto_create' ) ) {
			$created = wp_insert_term( $category['name'], 'category' );
			if ( is_wp_error( $created ) ) {
				// WordPress stores "&" as "&amp;", so a name lookup can miss a term that does exist.
				$existing_id = (int) $created->get_error_data( 'term_exists' );
				return $existing_id > 0 ? $existing_id : $created;
			}
			return (int) $created['term_id'];
		}

		return new \WP_Error(
			'category_unmapped',
			sprintf( 'News category "%s" has no matching WordPress category. Map one on the Publishing page or allow automatic creation.', $category['name'] )
		);
	}
}
