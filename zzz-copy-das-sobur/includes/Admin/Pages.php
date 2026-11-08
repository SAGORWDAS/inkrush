<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Articles;
use ZZZCopy\AuthorSchedules;
use ZZZCopy\Authors;
use ZZZCopy\AutoStop;
use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Discovery;
use ZZZCopy\Events;
use ZZZCopy\Health;
use ZZZCopy\Images\ImageHistory;
use ZZZCopy\Images\ImagePipeline;
use ZZZCopy\Lifecycle;
use ZZZCopy\PastHour;
use ZZZCopy\Plugin;
use ZZZCopy\Prompts;
use ZZZCopy\Publisher;
use ZZZCopy\RestTick;
use ZZZCopy\Schedule;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;
use ZZZCopy\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the admin screens. All output is escaped.
 */
final class Pages {

	private static array $flash = array();

	/* ---- Shared layout ---- */

	public static function open( string $title, string $current ): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'zzz-copy-das-sobur' ), 403 );
		}

		$key         = 'zzzc_flash_' . get_current_user_id();
		$stored      = get_transient( $key );
		self::$flash = is_array( $stored ) ? $stored : array();
		if ( self::$flash ) {
			delete_transient( $key );
		}

		echo '<div class="wrap zzzc"><h1 class="zzzc-title">InkRush <span class="zzzc-tagline">Discover. Create. Publish.</span></h1><nav class="zzzc-nav" aria-label="Plugin sections">';
		foreach ( Menu::PAGES as $slug => $label ) {
			printf(
				'<a href="%1$s"%3$s>%2$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_html( $label ),
				$slug === $current ? ' class="is-current" aria-current="page"' : ''
			);
		}
		echo '</nav><h2 class="zzzc-page-title">' . esc_html( $title ) . '</h2>';

		if ( self::$flash ) {
			printf( '<div class="zzzc-notice is-%1$s" role="status">%2$s</div>', esc_attr( self::$flash['type'] ), esc_html( self::$flash['text'] ) );
		}
	}

	public static function close(): void {
		echo '<p class="zzzc-footer">InkRush. Developed by: Sagor Das. Contact: <a href="mailto:das.sagorwork@gmail.com">das.sagorwork@gmail.com</a></p></div>';
	}

	public static function field_error( string $key ): void {
		if ( ! empty( self::$flash['fields'][ $key ] ) ) {
			echo '<p class="zzzc-field-error">' . esc_html( self::$flash['fields'][ $key ] ) . '</p>';
		}
	}

	public static function action_button( string $action, string $label, array $hidden = array(), string $class = '', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-inline"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		Actions::url_field( $action );
		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '<button type="submit" class="zzzc-btn ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function number_row( string $key, string $label, string $help = '', string $step = '1' ): void {
		printf(
			'<div class="zzzc-field"><label for="%1$s">%2$s</label><input type="number" step="%5$s" id="%1$s" name="%1$s" value="%3$s">%4$s',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) Settings::get( $key ) ),
			$help ? '<p class="zzzc-help">' . esc_html( $help ) . '</p>' : '',
			esc_attr( $step )
		);
		self::field_error( $key );
		echo '</div>';
	}

	/**
	 * Says how the daily generation limit stands against what a day of posts could use.
	 *
	 * Each post may have several articles written for it, because a story that turns out to be
	 * unusable gives way to another while there is still time, and every attempt counts against the
	 * limit. One generation is held back for each post still to come, so no post can be left with
	 * nothing by an earlier one. Two cases are still worth saying out loud: a limit too small to
	 * give every post of the day one article, and a limit too small for them all to retry.
	 */
	public static function ai_limit_note(): void {
		if ( ! PastHour::enabled() ) {
			return;
		}
		$slots = AuthorSchedules::multiple() ? count( Schedule::author_slots( Schedule::today() ) ) : Schedule::slots_that_fit();
		$each  = PastHour::max_articles();
		$need  = $slots * $each;
		$limit = (int) Settings::get( 'daily_ai_limit' );
		if ( $slots < 1 || $limit >= $need ) {
			return;
		}

		if ( $limit < $slots ) {
			echo '<div class="zzzc-field"><p class="zzzc-help"><strong>Too low for today.</strong> ' . esc_html(
				sprintf(
					'Today has %1$d post times and the limit is %2$d, so there is not one article for each. The earliest posts give way to the later ones, and the first of the day may write nothing at all. Raise it to at least %1$d.',
					$slots,
					$limit
				)
			) . '</p></div>';
			return;
		}

		echo '<div class="zzzc-field"><p class="zzzc-help">' . esc_html(
			sprintf(
				'Today has %1$d post times, each allowed up to %2$d articles, which is %3$d generations in all. At %4$d there is enough for every post to write one, which is held back for it, but not enough for them all to try again. Raise it to %3$d for the full allowance.',
				$slots,
				$each,
				$need,
				$limit
			)
		) . '</p></div>';
	}

	public static function select_row( string $key, string $label, array $options, string $help = '' ): void {
		printf( '<div class="zzzc-field"><label for="%1$s">%2$s</label><select id="%1$s" name="%1$s">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $value => $text ) {
			printf( '<option value="%1$s"%3$s>%2$s</option>', esc_attr( (string) $value ), esc_html( $text ), selected( (string) Settings::get( $key ), (string) $value, false ) );
		}
		echo '</select>';
		if ( $help ) {
			echo '<p class="zzzc-help">' . esc_html( $help ) . '</p>';
		}
		self::field_error( $key );
		echo '</div>';
	}

	public static function check_row( string $key, string $label, string $help = '' ): void {
		printf(
			'<div class="zzzc-field zzzc-check"><label><input type="checkbox" name="%1$s" value="1"%3$s> %2$s</label>%4$s</div>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( (int) Settings::get( $key ), 1, false ),
			$help ? '<p class="zzzc-help">' . esc_html( $help ) . '</p>' : ''
		);
	}

	public static function pagination( string $page, int $total, int $per_page, int $current, array $args = array() ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="zzzc-pages" aria-label="Pages"><span>' . esc_html( sprintf( 'Page %1$d of %2$d (%3$d items)', $current, $pages, $total ) ) . '</span>';
		if ( $current > 1 ) {
			echo '<a class="zzzc-btn" href="' . esc_url( add_query_arg( array_merge( $args, array( 'page' => $page, 'paged' => $current - 1 ) ), admin_url( 'admin.php' ) ) ) . '">Previous</a>';
		}
		if ( $current < $pages ) {
			echo '<a class="zzzc-btn" href="' . esc_url( add_query_arg( array_merge( $args, array( 'page' => $page, 'paged' => $current + 1 ) ), admin_url( 'admin.php' ) ) ) . '">Next</a>';
		}
		echo '</nav>';
	}

	public static function request_string( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters on a capability-checked page.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;
	}

	public static function state_label( string $state ): string {
		$labels = array(
			Automation::NOT_CONFIGURED => 'Not configured',
			Automation::STOPPED        => 'Stopped',
			Automation::STARTING       => 'Starting',
			Automation::RUNNING        => 'Running',
			Automation::PAUSED         => 'Paused',
			Automation::ERROR          => 'Error',
		);
		return $labels[ $state ] ?? $state;
	}

	/* ---- Dashboard ---- */

	public static function dashboard(): void {
		self::open( 'Dashboard', 'zzzc-dashboard' );

		$state   = Automation::state();
		$active  = Automation::is_active();
		$display = Automation::display_state();

		echo '<section class="zzzc-card zzzc-controls"><div class="zzzc-state-row"><span class="zzzc-state is-' . esc_attr( strtolower( $display ) ) . '">' . esc_html( self::state_label( $display ) ) . '</span>';
		if ( $active ) {
			if ( $state['paused_research'] ) {
				echo '<span class="zzzc-tag">Research paused</span>';
			}
			if ( $state['paused_publishing'] ) {
				echo '<span class="zzzc-tag">Publishing paused</span>';
			}
		}
		echo '</div><div class="zzzc-buttons">';

		if ( ! $active ) {
			self::action_button( 'start', 'Start Automation', array(), 'is-primary' );
		} else {
			self::action_button( 'stop', 'Stop Automation', array(), '', 'Stop automation? Future jobs are cancelled. Queued articles and history are kept.' );
			if ( ! $state['paused_research'] ) {
				self::action_button( 'pause_research', 'Pause Research' );
			} else {
				self::action_button( 'resume', 'Resume Research', array( 'scope' => 'research' ) );
			}
			if ( ! $state['paused_publishing'] ) {
				self::action_button( 'pause_publishing', 'Pause Publishing' );
			} else {
				self::action_button( 'resume', 'Resume Publishing', array( 'scope' => 'publishing' ) );
			}
			if ( $state['paused_research'] || $state['paused_publishing'] || '' !== $state['ai_block_code'] ) {
				self::action_button( 'resume', 'Resume', array( 'scope' => 'all' ), 'is-primary' );
			}
		}
		echo '</div>';

		if ( '' !== $state['ai_block_code'] ) {
			echo '<p class="zzzc-alert">' . esc_html( 'AI jobs are paused: ' . $state['ai_block_message'] ) . '</p>';
		}

		$auto_stop = Automation::automatic_stop_reason();
		if ( '' !== $auto_stop && ! $active ) {
			echo '<p class="zzzc-alert">' . esc_html( sprintf( 'Automation stopped by itself on %1$s. %2$s Press Start Automation to run again. The limits then count again from 0.', Schedule::format_local( Clock::mysql( (int) $state['auto_stopped_at'] ) ), $auto_stop ) ) . '</p>';
		}

		$author_problem = Authors::publishing_problem();
		if ( '' !== $author_problem && $active ) {
			echo '<p class="zzzc-alert">' . esc_html( 'Posts are not being published: ' . $author_problem ) . '</p>';
		}

		$problems = Automation::config_problems();
		if ( $problems && ! $active ) {
			echo '<div class="zzzc-setup"><strong>Needed before you can start:</strong><ul>';
			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '</section><div id="zzzc-live" data-live="1">';
		self::dashboard_live();
		echo '</div>';
		self::close();
	}

	/**
	 * The part of the dashboard that refreshes in the background.
	 */
	public static function dashboard_live(): void {
		global $wpdb;
		$today = Schedule::today();
		list( $start, $end ) = Schedule::day_bounds_utc( $today );

		$items   = Db::table( 'source_items' );
		$jobs    = Db::table( 'article_jobs' );
		$content = Db::table( 'article_content' );
		$queue   = Db::table( 'publishing_queue' );

		$count_items = static function ( string $condition ) use ( $wpdb, $items, $start, $end ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE discovered_at BETWEEN %s AND %s AND {$condition}", $start, $end ) ); // phpcs:ignore WordPress.DB
		};

		$discovered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE discovered_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
		$filtered   = $count_items( "status = 'FILTERED_OUT'" );
		$duplicates = $count_items( "status = 'DUPLICATE'" );

		$retrieved_ok   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE retrieved_at BETWEEN %s AND %s AND retrieval_status = 'SUCCESS'", $start, $end ) ); // phpcs:ignore WordPress.DB
		$retrieved_fail = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} i JOIN {$jobs} j ON j.source_item_id = i.id WHERE j.updated_at BETWEEN %s AND %s AND j.status IN ('BLOCKED','PARTIAL','RETRIEVAL_FAILED')", $start, $end ) ); // phpcs:ignore WordPress.DB

		$generated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$content} WHERE generated_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
		$validated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$content} c JOIN {$jobs} j ON j.id = c.article_job_id WHERE c.validated_at BETWEEN %s AND %s AND j.status IN ('VALIDATED','QUEUED','SCHEDULED','PUBLISHING','DRAFT_CREATED','PUBLISHED')", $start, $end ) ); // phpcs:ignore WordPress.DB

		$waiting   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE queue_status = %s", Lifecycle::QUEUED ) ); // phpcs:ignore WordPress.DB
		$attention = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $jobs . ' WHERE status IN ' . Db::in_strings( Lifecycle::ATTENTION ) ); // phpcs:ignore WordPress.DB

		$drafts    = Publisher::posts_on_day( $today, 'draft' );
		$published = Publisher::posts_on_day( $today, 'publish' );

		$image_stats = ImagePipeline::stats( $start, $end );
		$links_table = Db::table( 'post_links' );
		$no_image    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$links_table} l LEFT JOIN {$wpdb->postmeta} m ON m.post_id = l.wp_post_id AND m.meta_key = '_thumbnail_id' WHERE l.local_day = %s AND m.meta_id IS NULL", $today ) ); // phpcs:ignore WordPress.DB

		$kpis = array(
			array( 'Target today', (string) Publisher::cap_count_today() . ' of ' . (int) Settings::get( 'daily_target' ) ),
			array( 'Drafts created today', $drafts ),
			array( 'Published today', $published ),
			array( 'Waiting in queue', $waiting ),
			array( 'Failed or held', $attention ),
			array( 'Discovered today', $discovered ),
			array( 'Filtered out', $filtered ),
			array( 'Duplicates', $duplicates ),
			array( 'Sources retrieved', $retrieved_ok ),
			array( 'Sources not usable', $retrieved_fail ),
			array( 'Generated', $generated ),
			array( 'Validated', $validated ),
			array( 'Images added today', $image_stats['assigned'] ),
			array( 'Posts with no image today', $no_image ),
		);

		echo '<section class="zzzc-kpis" aria-label="Today">';
		foreach ( $kpis as $kpi ) {
			echo '<div class="zzzc-kpi"><span class="zzzc-kpi-value">' . esc_html( (string) $kpi[1] ) . '</span><span class="zzzc-kpi-label">' . esc_html( $kpi[0] ) . '</span></div>';
		}
		echo '</section>';

		$health   = Health::status();
		$next     = Schedule::next_open_slot();
		$active   = Automation::is_active();
		$research = Automation::get( 'paused_research' );
		$last_d   = (int) Automation::get( 'last_discovery_at', 0 );
		$next_d   = $last_d ? $last_d + (int) Settings::get( 'discovery_interval_minutes' ) * 60 : 0;

		echo '<div class="zzzc-columns"><section class="zzzc-card"><h3>Worker</h3><dl class="zzzc-dl">';

		$beat = $health['last_beat'] ? gmdate( 'Y-m-d H:i:s', $health['last_beat'] ) : '';
		echo '<dt>Last heartbeat</dt><dd>' . esc_html( $beat ? Schedule::format_local( $beat ) . ' (' . $health['source'] . ')' : 'None yet' ) . '</dd>';
		echo '<dt>Worker health</dt><dd>';
		if ( ! $health['wanted'] ) {
			echo 'Not running, because automation is stopped and social distribution is off.';
		} elseif ( ! $active ) {
			echo 'Running for social distribution only, because automation is stopped.';
		} elseif ( $health['stale'] ) {
			echo '<strong>Stalled.</strong> No heartbeat in the last 5 minutes. Check your server cron job (see the Schedule page).';
		} else {
			echo 'Healthy';
		}
		echo '</dd>';
		echo '<dt>Automatic stop</dt><dd>' . esc_html( AutoStop::summary() ) . '</dd>';
		echo '<dt>Stale jobs</dt><dd>' . esc_html( (string) $health['stale_jobs'] ) . '</dd>';
		echo '<dt>Server cron</dt><dd>' . ( $health['server_cron'] ? 'Active' : 'Not detected. Scheduling relies on site visits until a server cron job is set up.' ) . '</dd>';
		echo '<dt>Next publish slot</dt><dd>';
		if ( $active && $next ) {
			$category = Categories::name( (int) $next['category_id'] );
			$who      = (int) ( $next['author_user_id'] ?? 0 ) ? ', by ' . Authors::user_label( (int) $next['author_user_id'] ) : '';
			echo esc_html( Schedule::format_local( $next['due_at'] ) . ( $category || $who ? ' (' . ( $category ?: 'any category' ) . $who . ')' : '' ) );
		} else {
			echo esc_html( $active ? 'None planned' : 'Not scheduled' );
		}
		echo '</dd><dt>Next discovery</dt><dd>';
		if ( ! $active ) {
			echo 'Not scheduled';
		} elseif ( $research ) {
			echo 'Paused';
		} elseif ( PastHour::enabled() ) {
			if ( ! $next ) {
				echo 'None planned';
			} elseif ( PastHour::prep_start( $next ) <= Clock::now() ) {
				echo esc_html( 'Now, preparing the post at ' . Schedule::format_local( $next['due_at'] ) );
			} else {
				echo esc_html( Schedule::format_local( gmdate( 'Y-m-d H:i:s', PastHour::prep_start( $next ) ) ) . ' (preparing the next post)' );
			}
		} elseif ( $next_d ) {
			echo esc_html( Schedule::format_local( gmdate( 'Y-m-d H:i:s', $next_d ) ) );
		} else {
			echo 'On the next worker run';
		}
		echo '</dd></dl></section>';

		$totals = Usage::totals( $start, $end );
		echo '<section class="zzzc-card"><h3>OpenRouter today</h3><dl class="zzzc-dl">';
		echo '<dt>Requests</dt><dd>' . esc_html( (string) (int) ( $totals['requests'] ?? 0 ) ) . '</dd>';
		echo '<dt>Tokens</dt><dd>' . esc_html( number_format( (int) ( $totals['total_tokens'] ?? 0 ) ) ) . '</dd>';
		echo '<dt>Reported cost</dt><dd>' . esc_html( self::money( $totals ) ) . '</dd>';
		echo '</dl><p><a href="' . esc_url( admin_url( 'admin.php?page=zzzc-usage' ) ) . '">View usage</a></p></section></div>';

		self::past_hour_card();

		$recent = Events::query( array(), 12 );
		$errors = Events::query( array( 'severity' => 'error' ), 5 );

		if ( $errors ) {
			echo '<section class="zzzc-card"><h3>Recent errors</h3>';
			self::events_table( $errors, false );
			echo '</section>';
		}

		echo '<section class="zzzc-card"><h3>Featured images</h3><p>' . esc_html( 'Required. Every post gets a featured image from Wikimedia Commons, found ' . ( PastHour::enabled() ? 'while its article is prepared' : 'as soon as its article is ready' ) . '. An article with no usable image is not published.' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-images' ) ) . '">Image settings and history</a></p>';
		$held    = ImagePipeline::held_count();
		$missing = count( ImagePipeline::posts_without_image( 100 ) );
		echo '<dl class="zzzc-dl"><dt>Held: no usable image</dt><dd>' . esc_html( (string) $held ) . ( $held ? ' <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-articles' ) ) . '">See Failed or held</a>' : '' ) . '</dd><dt>Published posts with no image</dt><dd>' . esc_html( (string) $missing ) . ( $missing ? ' (from before images were required) <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-images' ) ) . '">List them</a>' : '' ) . '</dd></dl>';
		$image_events = Events::query( array( 'stage' => 'image' ), 5 );
		if ( $image_events ) {
			self::events_table( $image_events, false );
		} else {
			echo '<p class="zzzc-empty">No image activity yet.</p>';
		}
		echo '</section>';

		echo '<section class="zzzc-card"><h3>Recent activity</h3>';
		self::events_table( $recent, false );
		echo '</section>';
	}

	/**
	 * What each of today's posts is doing, and what its story was.
	 */
	private static function past_hour_card(): void {
		echo '<section class="zzzc-card"><h3>Past-Hour Posting</h3>';
		if ( ! PastHour::enabled() ) {
			echo '<p><strong>Off.</strong> Posts follow the earlier workflow. <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-schedule' ) ) . '">Schedule settings</a></p></section>';
			return;
		}
		echo '<p><strong>On.</strong> ' . esc_html( sprintf( 'Preparation starts %1$d minutes before each post. Every story must be no more than %2$d minutes old when it goes out, or the post is skipped.', PastHour::prep_minutes(), PastHour::max_age_minutes() ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-schedule' ) ) . '">Schedule settings</a></p>';

		$rows = PastHour::dashboard_rows();
		if ( ! $rows ) {
			echo '<p class="zzzc-empty">No posts planned.</p></section>';
			return;
		}

		$age = static function ( $seconds ): string {
			return null === $seconds ? '' : (int) floor( (int) $seconds / 60 ) . ' min';
		};

		echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Post time</th><th>Preparation starts</th><th>Headline</th><th>Source</th><th>Published</th><th>Age when selected</th><th>Age before publishing</th><th>Category</th><th>Fallback used</th><th>Processing</th><th>Result</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$planned  = Categories::name( (int) $row['category_id'] );
			$selected = (int) $row['selected_category_id'] ? Categories::name( (int) $row['selected_category_id'] ) : $planned;
			$cells    = array(
				Schedule::format_local( $row['due_at'], false ),
				Schedule::format_local( gmdate( 'Y-m-d H:i:s', PastHour::prep_start( $row ) ), false ),
				wp_strip_all_tags( (string) $row['headline'] ),
				(string) $row['publisher'],
				Schedule::format_local( $row['published_at'], false ),
				$age( $row['age_at_selection'] ),
				$age( $row['age_at_publish'] ),
				$selected,
				(int) $row['fallback_used'] ? 'Yes, planned ' . ( $planned ?: 'none' ) : 'No',
				PastHour::processing_label( $row ),
				PastHour::result_label( $row ),
			);
			echo '<tr>';
			foreach ( $cells as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function money( array $totals ): string {
		if ( null === ( $totals['cost_usd'] ?? null ) ) {
			return (int) ( $totals['requests'] ?? 0 ) ? 'Unavailable' : '$0.00';
		}
		$text = '$' . number_format( (float) $totals['cost_usd'], 4 );
		if ( (int) ( $totals['uncosted'] ?? 0 ) > 0 ) {
			$text .= sprintf( ' (%d requests with no reported cost)', (int) $totals['uncosted'] );
		}
		return $text;
	}

	private static function events_table( array $rows, bool $full ): void {
		if ( ! $rows ) {
			echo '<p class="zzzc-empty">Nothing recorded yet.</p>';
			return;
		}
		echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Time</th><th>Stage</th><th>Severity</th><th>Status</th><th>Message</th>';
		if ( $full ) {
			echo '<th>Attempt</th><th>Job</th><th>Post</th><th>By user</th><th>Author</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td class="zzzc-nowrap" title="' . esc_attr( $row['created_at'] . ' UTC' ) . '">' . esc_html( Schedule::format_local( $row['created_at'], false ) ) . '</td>';
			echo '<td>' . esc_html( $row['stage'] ) . '</td><td><span class="zzzc-sev is-' . esc_attr( $row['severity'] ) . '">' . esc_html( $row['severity'] ) . '</span></td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['message'] ) . ( $row['error_code'] ? ' <code>' . esc_html( $row['error_code'] ) . '</code>' : '' ) . '</td>';
			if ( $full ) {
				echo '<td>' . esc_html( (string) ( $row['attempt'] ?: '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( (int) $row['article_job_id'] ?: '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( (int) $row['wp_post_id'] ?: '' ) ) . '</td>';
				echo '<td>' . esc_html( Authors::user_label( (int) $row['actor_user_id'] ) ) . '</td>';
				echo '<td>' . esc_html( Authors::user_label( (int) ( $row['author_user_id'] ?? 0 ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ---- News sources ---- */

	public static function sources(): void {
		self::open( 'News Sources', 'zzzc-sources' );

		$edit = self::request_string( 'edit' );
		if ( '' !== $edit ) {
			self::category_form( 'new' === $edit ? 0 : absint( $edit ) );
			self::close();
			return;
		}

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h3>Categories</h3><a class="zzzc-btn is-primary" href="' . esc_url( admin_url( 'admin.php?page=zzzc-sources&edit=new' ) ) . '">Add category</a></div>';
		echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Order</th><th>Category</th><th>Enabled</th><th>Status</th><th>Sources</th><th>Recency</th><th>Locale</th><th>Weight</th><th>Actions</th></tr></thead><tbody>';

		$rows = Categories::all();
		foreach ( $rows as $index => $row ) {
			$plan    = Discovery::fetch_plan( $row );
			$google  = count( array_filter( $plan, static fn( $p ) => Discovery::GOOGLE === $p['adapter'] ) );
			$feeds   = count( $plan ) - $google;
			$id      = (int) $row['id'];
			echo '<tr><td class="zzzc-nowrap">';
			if ( $index > 0 ) {
				self::action_button( 'move_category', 'Up', array( 'id' => $id, 'direction' => 'up' ), 'is-small' );
			}
			if ( $index < count( $rows ) - 1 ) {
				self::action_button( 'move_category', 'Down', array( 'id' => $id, 'direction' => 'down' ), 'is-small' );
			}
			echo '</td><td><strong>' . esc_html( $row['name'] ) . '</strong></td><td>';
			self::action_button( 'toggle_category', (int) $row['enabled'] ? 'Enabled' : 'Disabled', array( 'id' => $id ), 'is-small' . ( (int) $row['enabled'] ? ' is-on' : '' ) );
			$issues = Categories::readiness( $row );
			echo '</td><td>' . ( $issues ? '<strong>' . esc_html( implode( ', ', $issues ) ) . '</strong>' : 'Ready' ) . '</td><td>' . esc_html( sprintf( '%1$d Google, %2$d feeds', $google, $feeds ) ) . '</td><td>' . esc_html( self::recency_label( $row['recency'] ) ) . '</td><td>' . esc_html( $row['language'] . ' / ' . $row['region'] ) . '</td><td>' . esc_html( (string) $row['weight'] ) . '</td><td class="zzzc-nowrap">';
			echo '<a class="zzzc-btn is-small" href="' . esc_url( admin_url( 'admin.php?page=zzzc-sources&edit=' . $id ) ) . '">Edit</a> ';
			echo '<button type="button" class="zzzc-btn is-small" data-test-search="' . esc_attr( (string) $id ) . '">Test search</button> ';
			self::action_button( 'duplicate_category', 'Duplicate', array( 'id' => $id ), 'is-small' );
			self::action_button( 'delete_category', 'Delete', array( 'id' => $id ), 'is-small', sprintf( 'Delete category "%s"? Past articles keep their records.', $row['name'] ) );
			echo '</td></tr><tr class="zzzc-result-row" id="zzzc-search-' . esc_attr( (string) $id ) . '" hidden><td colspan="9"></td></tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="zzzc-card"><h3>How stories are found, and the limits</h3><ul class="zzzc-list">';
		echo '<li><strong>Google News has no official API.</strong> The plugin reads its RSS feeds. Google can change or block them at any time, and publisher feeds keep working if that happens.</li>';
		echo '<li><strong>Robots rules.</strong> The /rss/ path is not on the allow list in the news.google.com robots.txt for general bots. The site owner chose to use it anyway. Turn it off below to stop all requests to Google News. Every publisher page and feed is checked against its own robots.txt.</li>';
		echo '<li><strong>Publisher links.</strong> Google RSS links do not lead straight to the publisher. The plugin looks for the same headline in the publisher\'s own feeds and news sitemaps. Stories it cannot match are recorded as not found, not guessed.</li>';
		echo '<li><strong>Date filters.</strong> Topic feeds and publisher feeds have no date filter, so the plugin checks each story date itself. Google search feeds also use when:, after: and before:. The filter applied to each fetch is recorded in Logs.</li>';
		echo '<li><strong>Paywalls and blocks.</strong> Paywalled or blocked pages are recorded and skipped. They are never bypassed, and no article is written from a headline alone.</li>';
		echo '</ul></section>';

		echo '<section class="zzzc-card"><h3>Source and duplicate rules</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1">';
		Actions::url_field( 'save_source_rules' );
		echo '<div class="zzzc-grid">';
		self::check_row( 'google_rss_enabled', 'Use Google News RSS', 'Turn this off to use publisher feeds only.' );
		self::number_row( 'min_source_words', 'Minimum source words', 'Below this, no article is written.' );
		self::number_row( 'partial_min_words', 'Partial source words', 'From here up to the minimum, the story is held as Partial.' );
		self::number_row( 'max_source_chars', 'Maximum source characters', 'Longer text is cut before it is sent to the model.' );
		self::number_row( 'http_timeout', 'Fetch timeout (seconds)' );
		self::number_row( 'host_gap_seconds', 'Gap between requests to one publisher (seconds)' );
		self::number_row( 'google_gap_seconds', 'Gap between requests to Google News (seconds)' );
		self::number_row( 'fetch_max_retries', 'Fetch attempts' );
		self::number_row( 'max_items_per_fetch', 'Stories kept per fetch' );
		self::number_row( 'headline_similarity', 'Headline similarity threshold', '0.30 to 1.00. Higher means only closer matches count as duplicates.', '0.01' );
		self::number_row( 'content_similarity', 'Text similarity threshold', '0.30 to 1.00.', '0.01' );
		self::number_row( 'resolver_similarity', 'Publisher headline match threshold', '0.30 to 1.00.', '0.01' );
		self::number_row( 'dedupe_window_days', 'Duplicate check window (days)' );
		echo '</div>';
		self::save_bar();
		echo '</form></section>';

		self::close();
	}

	public static function save_bar( string $label = 'Save changes' ): void {
		echo '<div class="zzzc-save"><button type="submit" class="zzzc-btn is-primary">' . esc_html( $label ) . '</button><span class="zzzc-unsaved" hidden>Unsaved changes</span></div>';
	}

	private static function recency_label( string $value ): string {
		$labels = array(
			'1h'     => 'Past hour',
			'24h'    => 'Past 24 hours',
			'7d'     => 'Past week',
			'custom' => 'Custom range',
		);
		return $labels[ $value ] ?? $value;
	}

	private static function category_form( int $id ): void {
		$row = $id ? Categories::get( $id ) : null;
		$row = $row ?: array(
			'name'              => '',
			'enabled'           => 1,
			'weight'            => 1,
			'source_mode'       => 'both',
			'topic_urls'        => '',
			'search_queries'    => '',
			'feed_urls'         => '',
			'required_keywords' => '',
			'excluded_keywords' => '',
			'recency'           => '24h',
			'custom_from'       => null,
			'custom_to'         => null,
			'language'          => 'en-US',
			'region'            => 'US',
			'active_days'       => '1,2,3,4,5,6,7',
			'wp_term_id'        => 0,
			'publish_mode'      => '',
		);

		$old = (array) ( self::$flash['old'] ?? array() );
		$val = static fn( string $key ) => array_key_exists( $key, $old ) ? $old[ $key ] : ( $row[ $key ] ?? '' );

		$local = static function ( ?string $utc ): string {
			$ts = Clock::to_timestamp( $utc );
			return $ts ? ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( Schedule::timezone() )->format( 'Y-m-d\TH:i' ) : '';
		};

		echo '<section class="zzzc-card"><h3>' . esc_html( $id ? 'Edit category' : 'Add category' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1">';
		Actions::url_field( 'save_category' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><div class="zzzc-grid">';

		echo '<div class="zzzc-field"><label for="c-name">Category name</label><input id="c-name" type="text" name="category[name]" value="' . esc_attr( (string) $val( 'name' ) ) . '" required>';
		self::field_error( 'name' );
		echo '</div>';

		echo '<div class="zzzc-field zzzc-check"><label><input type="checkbox" name="category[enabled]" value="1"' . checked( (int) $val( 'enabled' ), 1, false ) . '> Enabled</label></div>';

		echo '<div class="zzzc-field"><label for="c-mode">Sources to use</label><select id="c-mode" name="category[source_mode]">';
		foreach ( array( 'both' => 'Google News and publisher feeds', 'google' => 'Google News only', 'feeds' => 'Publisher feeds only' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $val( 'source_mode' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';

		echo '<div class="zzzc-field"><label for="c-weight">Weight</label><input id="c-weight" type="number" min="1" max="100" name="category[weight]" value="' . esc_attr( (string) $val( 'weight' ) ) . '"><p class="zzzc-help">Used by weighted rotation. Higher is chosen more often.</p></div>';
		echo '</div>';

		$textareas = array(
			'topic_urls'        => array( 'Google News topic addresses', 'One per line. These pages do not guarantee full article text.' ),
			'search_queries'    => array( 'Search queries', 'One per line. Each runs as a Google News search.' ),
			'feed_urls'         => array( 'Publisher RSS or Atom feeds', 'One per line.' ),
			'required_keywords' => array( 'Required keywords', 'One per line. A story must contain at least one. Leave empty for no requirement.' ),
			'excluded_keywords' => array( 'Excluded keywords', 'One per line. A story containing any of these is filtered out.' ),
		);
		foreach ( $textareas as $key => $meta ) {
			echo '<div class="zzzc-field"><label for="c-' . esc_attr( $key ) . '">' . esc_html( $meta[0] ) . '</label><textarea id="c-' . esc_attr( $key ) . '" name="category[' . esc_attr( $key ) . ']" rows="3">' . esc_textarea( (string) $val( $key ) ) . '</textarea><p class="zzzc-help">' . esc_html( $meta[1] ) . '</p>';
			self::field_error( $key );
			echo '</div>';
		}

		echo '<div class="zzzc-grid"><div class="zzzc-field"><label for="c-recency">Recency filter</label><select id="c-recency" name="category[recency]">';
		foreach ( array( '1h', '24h', '7d', 'custom' ) as $value ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $val( 'recency' ), $value, false ) . '>' . esc_html( self::recency_label( $value ) ) . '</option>';
		}
		echo '</select></div>';

		$from = array_key_exists( 'custom_from', $old ) ? (string) $old['custom_from'] : $local( $row['custom_from'] );
		$to   = array_key_exists( 'custom_to', $old ) ? (string) $old['custom_to'] : $local( $row['custom_to'] );
		echo '<div class="zzzc-field"><label for="c-from">Custom start</label><input id="c-from" type="datetime-local" name="category[custom_from]" value="' . esc_attr( $from ) . '"><p class="zzzc-help">' . esc_html( Schedule::zone_label() ) . '</p>';
		self::field_error( 'custom_from' );
		echo '</div><div class="zzzc-field"><label for="c-to">Custom end</label><input id="c-to" type="datetime-local" name="category[custom_to]" value="' . esc_attr( $to ) . '"></div>';

		echo '<div class="zzzc-field"><label for="c-lang">Language</label><input id="c-lang" type="text" name="category[language]" value="' . esc_attr( (string) $val( 'language' ) ) . '"><p class="zzzc-help">For example en-US.</p></div>';
		echo '<div class="zzzc-field"><label for="c-region">Region</label><input id="c-region" type="text" name="category[region]" value="' . esc_attr( (string) $val( 'region' ) ) . '"><p class="zzzc-help">For example US.</p></div>';
		echo '</div>';

		$days_value = $val( 'active_days' );
		$days       = is_array( $days_value ) ? array_map( 'strval', $days_value ) : explode( ',', (string) $days_value );
		echo '<fieldset class="zzzc-field"><legend>Days this category can be chosen</legend><div class="zzzc-days">';
		foreach ( array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' ) as $number => $label ) {
			echo '<label><input type="checkbox" name="category[active_days][]" value="' . esc_attr( (string) $number ) . '"' . checked( in_array( (string) $number, $days, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		self::field_error( 'active_days' );
		echo '</fieldset>';

		echo '<input type="hidden" name="category[wp_term_id]" value="' . esc_attr( (string) (int) $val( 'wp_term_id' ) ) . '"><input type="hidden" name="category[publish_mode]" value="' . esc_attr( (string) $val( 'publish_mode' ) ) . '">';

		self::save_bar( 'Save category' );
		echo ' <a class="zzzc-btn" href="' . esc_url( admin_url( 'admin.php?page=zzzc-sources' ) ) . '">Cancel</a></form></section>';
	}

	/* ---- AI settings ---- */

	public static function ai(): void {
		self::open( 'AI Settings', 'zzzc-ai' );

		echo '<section class="zzzc-card"><h3>OpenRouter connection</h3>';
		$masked = Secrets::masked();
		echo '<p>Saved key: <strong>' . esc_html( $masked ?: 'None' ) . '</strong></p>';
		if ( ! Secrets::uses_external_key() ) {
			echo '<p class="zzzc-help">For stronger protection, define <code>ZZZCOPY_SECRET_KEY</code> in wp-config.php. The key is then encrypted with a secret kept outside the database. Enter the API key again after adding it.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form zzzc-key-form">';
		Actions::url_field( 'save_key' );
		echo '<div class="zzzc-field"><label for="api_key">' . esc_html( $masked ? 'Replace API key' : 'API key' ) . '</label><input type="password" id="api_key" name="api_key" value="" autocomplete="new-password" spellcheck="false"></div><button type="submit" class="zzzc-btn is-primary">Save key</button></form>';
		echo '<div class="zzzc-buttons"><button type="button" class="zzzc-btn" id="zzzc-test-connection">Test API Connection</button>';
		if ( $masked ) {
			self::action_button( 'clear_key', 'Remove key', array(), '', 'Remove the saved API key? AI jobs will fail until a new key is saved.' );
		}
		echo '</div><p class="zzzc-result" id="zzzc-connection-result" role="status"></p></section>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1"><section class="zzzc-card"><h3>Model and limits</h3>';
		Actions::url_field( 'save_ai' );
		echo '<div class="zzzc-grid"><div class="zzzc-field"><label for="model_id">Model identifier</label><input type="text" id="model_id" name="model_id" value="' . esc_attr( (string) Settings::get( 'model_id' ) ) . '" spellcheck="false"><p class="zzzc-help">Used exactly as entered. It is never swapped for another model.</p>';
		self::field_error( 'model_id' );
		echo '</div>';
		self::number_row( 'ai_timeout', 'Request timeout (seconds)' );
		self::number_row( 'ai_max_retries', 'Maximum attempts' );
		self::number_row( 'max_output_tokens', 'Output token limit' );
		self::number_row( 'min_article_words', 'Minimum article words', 'Shorter articles fail validation. The target itself comes from your prompt.' );
		self::select_row(
			'tag_policy',
			'If more than 20 tags come back',
			array(
				'trim_extra' => 'Keep the first 20',
				'strict'     => 'Retry, then hold the article',
			),
			'If fewer than 20 come back, the plugin retries and then holds the article.'
		);
		self::select_row(
			'emdash_policy',
			'If the forbidden “—” appears',
			array(
				'replace' => 'Replace it with a comma and note this in diagnostics',
				'hold'    => 'Fail validation',
				'allow'   => 'Allow it and warn',
			)
		);
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>Prompts</h3><p class="zzzc-help">Prompts are saved exactly as you enter them and are not corrected. Each change creates a new version, and jobs keep the version they started with.</p>';
		foreach (
			array(
				array( 'article_prompt', Prompts::ARTICLE, 'Article prompt', 'Sent after the source information.', 14 ),
				array( 'tag_prompt', Prompts::TAG, 'Tag prompt', 'Sent after the finished article.', 3 ),
				array( 'system_prompt', Prompts::SYSTEM, 'Accuracy and format instruction', 'Sent separately as the system message, so your article prompt stays unchanged. It tells the model to use only the source, to ignore instructions inside source text and to format in HTML.', 6 ),
			) as $prompt
		) {
			echo '<div class="zzzc-field"><label for="' . esc_attr( $prompt[0] ) . '">' . esc_html( $prompt[2] ) . '</label>';
			// The newline right after the tag stops browsers dropping a leading newline in the saved text.
			echo '<textarea id="' . esc_attr( $prompt[0] ) . '" name="' . esc_attr( $prompt[0] ) . '" rows="' . esc_attr( (string) $prompt[4] ) . '" spellcheck="false">' . "\n" . esc_textarea( Prompts::active_text( $prompt[1] ) ) . '</textarea><p class="zzzc-help">' . esc_html( $prompt[3] ) . ' Active version: ' . esc_html( (string) Prompts::active_id( $prompt[1] ) ) . '.</p>';
			self::field_error( $prompt[0] );
			echo '</div>';
		}
		self::save_bar( 'Save AI settings' );
		echo '</section></form>';

		echo '<section class="zzzc-card"><h3>Prompt history</h3><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Prompt</th><th>Version</th><th>Saved</th><th>Characters</th><th>SHA-256</th><th></th></tr></thead><tbody>';
		foreach ( array( Prompts::ARTICLE, Prompts::TAG, Prompts::SYSTEM ) as $type ) {
			$active = Prompts::active_id( $type );
			foreach ( Prompts::history( $type, 5 ) as $row ) {
				echo '<tr><td>' . esc_html( $type ) . '</td><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( Schedule::format_local( $row['created_at'], false ) ) . '</td><td>' . esc_html( (string) $row['chars'] ) . '</td><td><code>' . esc_html( substr( (string) $row['content_sha256'], 0, 12 ) ) . '</code></td><td>';
				if ( (int) $row['id'] === $active ) {
					echo 'Active';
				} else {
					self::action_button( 'restore_prompt', 'Restore', array( 'prompt_type' => $type, 'version' => (int) $row['id'] ), 'is-small' );
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table></div></section>';

		echo '<section class="zzzc-card"><h3>Test generation</h3><p class="zzzc-help">Runs your saved prompts on sample text. This makes real OpenRouter calls, which count in Usage. Nothing is queued.</p>';
		echo '<div class="zzzc-grid"><div class="zzzc-field"><label for="t-headline">Sample headline</label><input type="text" id="t-headline"></div><div class="zzzc-field"><label for="t-publisher">Publisher</label><input type="text" id="t-publisher"></div><div class="zzzc-field"><label for="t-url">Source address</label><input type="url" id="t-url"></div></div>';
		echo '<div class="zzzc-field"><label for="t-text">Source text</label><textarea id="t-text" rows="8"></textarea></div>';
		echo '<div class="zzzc-buttons"><button type="button" class="zzzc-btn is-primary" id="zzzc-test-generation">Run test generation</button><button type="button" class="zzzc-btn" id="zzzc-test-draft" hidden>Create test draft</button></div><div class="zzzc-result" id="zzzc-generation-result" role="status"></div></section>';

		self::close();
	}

	/* ---- Schedule ---- */

	public static function schedule(): void {
		self::open( 'Schedule', 'zzzc-schedule' );

		$categories = Categories::enabled();
		$fits       = Schedule::slots_that_fit();
		$target     = (int) Settings::get( 'daily_target' );

		$multi = AuthorSchedules::multiple();
		echo '<section class="zzzc-card"><h3>Today\'s slots</h3>';
		if ( $multi ) {
			echo '<p>' . esc_html( 'Multiple users mode is on, so each author posts at the times on their own schedule. Daily target, Interval, First publishing time, When a slot has no article, Categories in rotation and the slot categories below are not used: each author\'s own categories are used instead. Maximum posts per day, Maximum article generations per day and the other settings still apply.' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-authors' ) ) . '">Authors page</a></p>';
			$planned = Schedule::author_slots( Schedule::today() );
			if ( ! $planned ) {
				echo '<p class="zzzc-empty">No author times are planned for today.</p>';
			} else {
				echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Slot</th><th>Local time</th><th>UTC</th><th>Author</th><th>Category</th><th>Status</th></tr></thead><tbody>';
				foreach ( $planned as $index => $slot ) {
					$status = ucfirst( strtolower( (string) $slot['status'] ) ) . ( ! empty( $slot['status_reason'] ) ? ': ' . $slot['status_reason'] : '' );
					echo '<tr><td>' . esc_html( (string) ( $index + 1 ) ) . '</td><td>' . esc_html( Schedule::format_local( (string) $slot['due_at'] ) ) . '</td><td>' . esc_html( substr( (string) $slot['due_at'], 0, 16 ) ) . '</td><td>' . esc_html( Authors::user_label( (int) $slot['author_user_id'] ) ) . '</td><td>' . esc_html( Categories::name( (int) $slot['category_id'] ) ?: 'Any of theirs' ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '</section>';
		} else {
			if ( $fits < $target ) {
				echo '<p class="zzzc-alert">' . esc_html( sprintf( 'Only %1$d of %2$d daily slots fit before midnight with this start time and interval. Reduce the interval or use an earlier start time.', $fits, $target ) ) . '</p>';
			}
			echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Slot</th><th>Local time</th><th>UTC</th></tr></thead><tbody>';
			foreach ( Schedule::slot_times( Schedule::today() ) as $index => $time ) {
				echo '<tr><td>' . esc_html( (string) ( $index + 1 ) ) . '</td><td>' . esc_html( $time->format( 'H:i' ) . ' ' . Schedule::zone_label( $time ) ) . '</td><td>' . esc_html( gmdate( 'Y-m-d H:i', $time->getTimestamp() ) ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1">';
		Actions::url_field( 'save_schedule' );

		echo '<section class="zzzc-card"><h3>Publishing schedule</h3><div class="zzzc-grid">';
		self::number_row( 'daily_target', 'Daily target' );
		self::number_row( 'interval_hours', 'Interval (hours)' );

		echo '<div class="zzzc-field"><label for="first_time">First publishing time</label><input type="time" id="first_time" name="first_time" value="' . esc_attr( (string) Settings::get( 'first_time' ) ) . '">';
		self::field_error( 'first_time' );
		echo '</div>';

		echo '<div class="zzzc-field"><label for="timezone">Timezone</label><input type="text" id="timezone" name="timezone" list="zzzc-zones" value="' . esc_attr( (string) Settings::get( 'timezone' ) ) . '" spellcheck="false"><datalist id="zzzc-zones">';
		foreach ( timezone_identifiers_list() as $zone ) {
			echo '<option value="' . esc_attr( $zone ) . '"></option>';
		}
		echo '</datalist><p class="zzzc-help">A name such as Australia/Lord_Howe follows daylight saving. A fixed offset such as +10:30 never changes.</p>';
		self::field_error( 'timezone' );
		echo '</div>';

		self::number_row( 'daily_cap', 'Maximum posts per day', 'Never exceeded, counted by local calendar day.' );
		self::select_row(
			'cap_scope',
			'What counts toward the maximum',
			array(
				'all'       => 'Drafts and published posts',
				'published' => 'Published posts only',
			),
			'Drafts and published posts are always shown separately on the Dashboard.'
		);
		self::select_row(
			'catchup',
			'When a slot has no article',
			array(
				'next' => 'Publish next available: try again at the next slot time',
				'skip' => 'Skip missed slot',
			)
		);
		self::number_row( 'slot_grace_minutes', 'Slot grace period (minutes)', 'How long after its time a slot keeps waiting for an article.' );
		self::number_row( 'catchup_gap_minutes', 'Minimum gap before a catch-up post (minutes)' );
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>Past-Hour Posting</h3><div class="zzzc-grid">';
		self::check_row( 'past_hour_enabled', 'Use Past-Hour Posting', 'Each post uses one story that is no older than the maximum story age below when it goes out. If no such story exists, the post is skipped and never caught up. Applies to all enabled categories. While this is on, the recency filters, catch-up and the Research settings below other than the generation limit do not apply. Turn it off for the earlier workflow.' );
		self::number_row( 'past_hour_max_age_minutes', 'Maximum story age (minutes)', 'From 60 to 180. Measured at the post time: with 120, a 10:00 post can only use a story published after about 08:05. Never longer than the interval between posts.' );
		self::number_row( 'past_hour_prep_minutes', 'Start preparing before each post (minutes)', 'From 15 to 180, and no more than the maximum story age. Searches run every 15 minutes until a story is found. Other categories are searched in the second half of that time, from 60 minutes before a post with a 120-minute age.' );
		self::number_row( 'past_hour_max_articles', 'Articles to write before giving up on a post (1 to 5)', 'An article that is written and then cannot be used, usually because no image passed, uses one of these. Raising it gives a post another chance at the cost of another AI article. Maximum article generations per day still caps the whole day.' );
		if ( PastHour::max_age_minutes() < (int) Settings::get( 'past_hour_max_age_minutes' ) ) {
			echo '<p class="zzzc-help"><strong>' . esc_html( sprintf( 'The interval between posts is %1$d hours, so stories may be at most %2$d minutes old, and preparation starts at most %3$d minutes before each post.', (int) Settings::get( 'interval_hours' ), PastHour::max_age_minutes(), PastHour::prep_minutes() ) ) . '</strong></p>';
		}
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>Category rotation</h3><div class="zzzc-grid">';
		self::select_row(
			'rotation_mode',
			'Rotation mode',
			array(
				'round_robin' => 'Round robin',
				'weighted'    => 'Weighted random',
				'per_slot'    => 'Choose per slot, round robin for the rest',
			)
		);
		self::check_row( 'no_repeat', 'Avoid repeating a category on the same day when others are available' );
		self::check_row( 'category_fallback', 'If a slot\'s category has no article ready, use another category\'s article' );
		echo '</div>';

		$pool = array_map( 'intval', (array) Settings::get( 'rotation_pool' ) );
		echo '<fieldset class="zzzc-field"><legend>Categories in rotation</legend><p class="zzzc-help">Leave all unticked to use every enabled category.</p><div class="zzzc-days">';
		foreach ( $categories as $row ) {
			echo '<label><input type="checkbox" name="rotation_pool[]" value="' . esc_attr( (string) $row['id'] ) . '"' . checked( in_array( (int) $row['id'], $pool, true ), true, false ) . '> ' . esc_html( $row['name'] ) . '</label>';
		}
		echo '</div></fieldset>';

		$map = (array) Settings::get( 'slot_categories' );
		echo '<div class="zzzc-grid">';
		foreach ( Schedule::slot_times( Schedule::today() ) as $index => $time ) {
			$field = 'slot-' . (int) $index;
			echo '<div class="zzzc-field"><label for="' . esc_attr( $field ) . '">' . esc_html( sprintf( 'Slot %1$d (%2$s)', $index + 1, $time->format( 'H:i' ) ) ) . '</label><select id="' . esc_attr( $field ) . '" name="slot_categories[' . esc_attr( (string) $index ) . ']"><option value="0">Rotation chooses</option>';
			foreach ( $categories as $row ) {
				echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( (int) ( $map[ $index ] ?? 0 ), (int) $row['id'], false ) . '>' . esc_html( $row['name'] ) . '</option>';
			}
			echo '</select></div>';
		}
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>Research</h3><div class="zzzc-grid">';
		self::number_row( 'discovery_interval_minutes', 'Discovery every (minutes)', 'Runs separately from publishing.' );
		self::number_row( 'research_lead_hours', 'Start research before a slot (hours)', 'A category is researched only when its next slot is this close, so its news is still fresh when it publishes.' );
		self::number_row( 'category_buffer', 'Articles to keep ready per category' );
		self::number_row( 'daily_ai_limit', 'Maximum article generations per day', 'A spending guard. Counts every generation attempt.' );
		self::ai_limit_note();
		self::number_row( 'max_article_age_hours', 'Skip queued articles older than (hours)', 'Old news is not published.' );
		self::number_row( 'run_budget_seconds', 'Worker run length (seconds)', 'A worker run stops starting new jobs after this time.' );
		echo '</div>';
		self::save_bar( 'Save schedule' );
		echo '</section></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1">';
		Actions::url_field( 'save_auto_stop' );
		echo '<section class="zzzc-card" id="auto-stop"><h3>Automatic stop</h3><p class="zzzc-help">Stop automation by itself after a number of days or published posts, whichever comes first. It stops exactly like the Stop button: future jobs are cancelled, a step already running finishes, and queued articles and history are kept. Both limits count from the last time you pressed Start Automation, and count again from 0 at the next Start. Enter 0 for no limit.</p>';
		echo '<p><strong>Now:</strong> ' . esc_html( AutoStop::summary() ) . '</p><div class="zzzc-grid">';
		self::number_row( 'auto_stop_days', 'Stop after this many days', 'From 0 to 365. Counted in full days of 24 hours from the Start.' );
		self::number_row( 'auto_stop_posts', 'Stop after this many published posts', 'From 0 to 10000. Only posts this plugin publishes count. Drafts do not, so in Draft mode only the days limit can stop automation.' );
		echo '</div>';
		self::save_bar( 'Save automatic stop' );
		echo '</section></form>';

		$token = RestTick::token();
		$rest  = rest_url( 'zzzc/v1/tick' );
		$cli   = ZZZC_DIR . 'bin/run-worker.php';

		echo '<section class="zzzc-card"><h3>Server cron</h3><p>WordPress\'s built-in cron only runs when someone visits the site. For reliable timing, add one of these to your server\'s cron, set to run every minute.</p>';
		echo '<p><strong>With PHP on the command line (preferred):</strong></p><pre class="zzzc-pre">* * * * * php ' . esc_html( $cli ) . ' &gt;/dev/null 2&gt;&amp;1</pre>';
		echo '<p><strong>With a web request:</strong></p><pre class="zzzc-pre">* * * * * curl -s -m 170 -X POST -H "X-ZZZC-Token: ' . esc_html( $token ) . '" ' . esc_html( $rest ) . ' &gt;/dev/null 2&gt;&amp;1</pre>';
		echo '<p class="zzzc-help">Treat this token like a password. It can only trigger a worker run.</p>';
		self::action_button( 'regenerate_token', 'Create new token', array(), '', 'Create a new token? Cron jobs using the old one will stop working.' );
		echo '</section>';

		self::close();
	}

	/* ---- Publishing ---- */

	public static function publishing(): void {
		self::open( 'Publishing', 'zzzc-publishing' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes="1">';
		Actions::url_field( 'save_publishing' );

		echo '<section class="zzzc-card"><h3>Post creation</h3><div class="zzzc-grid">';
		self::select_row(
			'publish_mode',
			'Publishing mode',
			array(
				'draft'   => 'Draft: create drafts for review',
				'publish' => 'Automatic Publish: publish with no approval',
			),
			'Articles that fail source or validation checks are held in both modes.'
		);
		self::select_row(
			'tag_mode',
			'Tags',
			array(
				'create' => 'Reuse matching tags and create missing ones',
				'reuse'  => 'Only use tags that already exist',
			)
		);
		self::select_row(
			'attribution',
			'Source attribution',
			array(
				'meta'    => 'Store in post data only',
				'visible' => 'Store in post data and add a visible source line',
			),
			'The source address and publisher are always kept with the post.'
		);

		$current = (int) Settings::get( 'post_author' );
		$choices = Authors::active_ids();
		if ( $current && ! in_array( $current, $choices, true ) ) {
			$choices[] = $current;
		}
		echo '<div class="zzzc-field"><label for="post_author">Post author</label><select id="post_author" name="post_author"><option value="0">None chosen</option>';
		foreach ( $choices as $user_id ) {
			$state = Authors::status( $user_id );
			echo '<option value="' . esc_attr( (string) $user_id ) . '"' . selected( $current, $user_id, false ) . '>' . esc_html( Authors::user_label( $user_id ) . ( Authors::ACTIVE === $state ? '' : ' (' . Authors::status_label( $state ) . ')' ) ) . '</option>';
		}
		echo '</select><p class="zzzc-help">Only active authors can be chosen. Grant access on the <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-authors' ) ) . '">Authors page</a>. The author is checked again right before each post.' . ( AuthorSchedules::multiple() ? ' <strong>Multiple users mode is on, so this setting is not used: each post is credited to the author whose time it is.</strong>' : ' Used in Single user mode. In Multiple users mode each post is credited to the author whose time it is.' ) . '</p>';
		self::field_error( 'post_author' );
		echo '</div>';

		self::check_row( 'category_auto_create', 'Create a WordPress category when none matches' );
		echo '</div></section>';

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		echo '<section class="zzzc-card"><h3>WordPress category mapping</h3><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>News category</th><th>WordPress category</th><th>Mode for this category</th></tr></thead><tbody>';
		foreach ( Categories::all() as $row ) {
			$id = (int) $row['id'];
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td><select aria-label="' . esc_attr( 'WordPress category for ' . $row['name'] ) . '" name="map[' . esc_attr( (string) $id ) . '][wp_term_id]"><option value="0">Same name</option>';
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( (int) $row['wp_term_id'], (int) $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
				}
			}
			echo '</select></td><td><select aria-label="' . esc_attr( 'Mode for ' . $row['name'] ) . '" name="map[' . esc_attr( (string) $id ) . '][publish_mode]">';
			foreach ( array( '' => 'Use the global mode', 'draft' => 'Draft', 'publish' => 'Automatic Publish' ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $row['publish_mode'], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="zzzc-card"><h3>Data retention and removal</h3><div class="zzzc-grid">';
		self::number_row( 'retention_source_days', 'Keep publisher text and rejected stories (days)' );
		self::number_row( 'retention_events_days', 'Keep logs (days)' );
		self::number_row( 'retention_failed_days', 'Keep raw diagnostics of failed articles (days)' );
		self::check_row( 'delete_data_on_uninstall', 'Delete all plugin data when the plugin is deleted', 'Off by default. Deactivating never deletes data, and WordPress posts are never deleted.' );
		echo '</div>';
		self::save_bar( 'Save publishing settings' );
		echo '</section></form>';

		echo '<section class="zzzc-card"><h3>Images</h3><p>Featured images come from Wikimedia Commons and have their own settings. Any image element in generated text is still removed. <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-images' ) ) . '">Open Images</a></p></section>';

		self::close();
	}

	/* ---- Images ---- */

	/**
	 * Who this plugin may credit with its posts. WordPress roles are shown but never changed.
	 */
	public static function authors(): void {
		self::open( 'Authors', 'zzzc-authors' );

		$search   = self::request_string( 's' );
		$role     = self::request_string( 'role' );
		$access   = self::request_string( 'access' );
		$paged    = max( 1, absint( self::request_string( 'paged', '1' ) ) );
		$per_page = 50;
		$roles    = wp_roles()->get_names();
		if ( ! isset( $roles[ $role ] ) ) {
			$role = '';
		}
		$filters = array(
			''               => 'Everyone',
			'authorised'     => 'Authorised, any status',
			Authors::ACTIVE  => 'Active',
			Authors::PAUSED  => 'Paused',
			Authors::REVOKED => 'Revoked',
			Authors::NONE    => 'Not authorised',
		);
		if ( ! isset( $filters[ $access ] ) ) {
			$access = '';
		}

		$credited = Authors::credited_user();
		$counts   = Authors::counts();
		echo '<section class="zzzc-card"><h3>Who can be credited with posts</h3>';
		echo '<p>' . esc_html( sprintf( 'Active: %1$d. Paused: %2$d. Revoked: %3$d.', $counts[ Authors::ACTIVE ], $counts[ Authors::PAUSED ], $counts[ Authors::REVOKED ] ) ) . '</p>';
		echo '<p>' . esc_html( 'Post author: ' . ( $credited ? Authors::user_label( $credited ) . ', ' . Authors::status_label( Authors::status( $credited ) ) : 'none chosen' ) . '.' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-publishing' ) ) . '">Publishing settings</a></p>';
		$problem = Authors::publishing_problem();
		if ( '' !== $problem ) {
			echo '<p class="zzzc-alert">' . esc_html( $problem ) . '</p>';
		}
		echo '<p class="zzzc-help">Nobody is authorised automatically, whatever their WordPress role, and this page never changes WordPress roles. The author is checked right before each post, so a change here applies at once. A held article can be retried on the Articles page.</p></section>';

		self::author_mode_card();
		$schedule_id = absint( self::request_string( 'schedule' ) );
		if ( $schedule_id ) {
			self::author_schedule_form( $schedule_id );
		}

		echo '<section class="zzzc-card"><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="zzzc-form"><input type="hidden" name="page" value="zzzc-authors"><div class="zzzc-grid">';
		echo '<div class="zzzc-field"><label for="a-s">Search</label><input id="a-s" type="search" name="s" value="' . esc_attr( $search ) . '"><p class="zzzc-help">Username, name or email.</p></div>';
		echo '<div class="zzzc-field"><label for="a-role">WordPress role</label><select id="a-role" name="role"><option value="">Any role</option>';
		foreach ( $roles as $key => $name ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $role, $key, false ) . '>' . esc_html( translate_user_role( $name ) ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="zzzc-field"><label for="a-access">Access</label><select id="a-access" name="access">';
		foreach ( $filters as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $access, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div></div><button type="submit" class="zzzc-btn is-primary">Filter</button> <a class="zzzc-btn" href="' . esc_url( admin_url( 'admin.php?page=zzzc-authors' ) ) . '">Clear</a></form></section>';

		$args  = array(
			'number'      => $per_page,
			'paged'       => $paged,
			'orderby'     => 'login',
			'order'       => 'ASC',
			'count_total' => true,
		);
		$empty = false;
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}
		if ( '' !== $role ) {
			$args['role'] = $role;
		}
		if ( in_array( $access, Authors::STATUSES, true ) || 'authorised' === $access ) {
			$ids = Authors::ids( 'authorised' === $access ? '' : $access );
			if ( $ids ) {
				$args['include'] = $ids;
			} else {
				$empty = true;
			}
		} elseif ( Authors::NONE === $access ) {
			$ids = Authors::ids();
			if ( $ids ) {
				$args['exclude'] = $ids;
			}
		}
		$query = $empty ? null : new \WP_User_Query( $args );
		$users = $query ? (array) $query->get_results() : array();
		$total = $query ? (int) $query->get_total() : 0;
		$ids   = array_map(
			static function ( $user ) {
				return (int) $user->ID;
			},
			$users
		);
		$rows  = Authors::rows( $ids );
		$posts = Authors::credited_posts( $ids );

		echo '<section class="zzzc-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-confirm-revoke="Revoke access? Posts will no longer be credited to the users chosen, and articles waiting for them are held.">';
		Actions::url_field( 'author_access' );
		foreach ( array( 's' => $search, 'role' => $role, 'access' => $access, 'paged' => $paged > 1 ? (string) $paged : '' ) as $key => $value ) {
			echo '<input type="hidden" name="return_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}

		if ( ! $users ) {
			echo '<p class="zzzc-empty">No users match.</p></form></section>';
		} else {
			echo '<div class="zzzc-buttons"><label class="screen-reader-text" for="a-bulk">Bulk action</label><select id="a-bulk" name="bulk_action"><option value="">Bulk action</option><option value="grant">Grant access</option><option value="pause">Pause</option><option value="revoke">Revoke access</option></select> <button type="submit" class="zzzc-btn">Apply to selected</button></div>';
			echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th><input type="checkbox" data-check-all="1" aria-label="Select all users on this page"></th><th>Username</th><th>Name</th><th>Email</th><th>WordPress role</th><th>Access</th><th>Granted</th><th>Posts credited</th><th>Note</th><th>Schedule</th><th>Change</th></tr></thead><tbody>';
			foreach ( $users as $user ) {
				$id     = (int) $user->ID;
				$row    = $rows[ $id ] ?? null;
				$status = $row ? (string) $row['status'] : Authors::NONE;
				$names  = array();
				foreach ( (array) $user->roles as $key ) {
					$names[] = isset( $roles[ $key ] ) ? translate_user_role( $roles[ $key ] ) : $key;
				}
				$notes = array();
				if ( $credited === $id ) {
					$notes[] = 'Post author.';
				}
				if ( Authors::ACTIVE === $status || Authors::PAUSED === $status ) {
					$warning = Authors::edit_warning( $user );
					if ( '' !== $warning ) {
						$notes[] = $warning;
					}
				}
				$granted = $row && ! empty( $row['granted_at'] ) ? Schedule::format_local( (string) $row['granted_at'], false ) . ( (int) $row['granted_by'] ? ' by ' . Authors::user_label( (int) $row['granted_by'] ) : '' ) : '';

				echo '<tr><td><input type="checkbox" name="user_ids[]" value="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( 'Select ' . $user->user_login ) . '"></td>';
				echo '<td>' . esc_html( $user->user_login ) . '</td><td>' . esc_html( $user->display_name ) . '</td><td>' . esc_html( $user->user_email ) . '</td>';
				echo '<td>' . esc_html( $names ? implode( ', ', $names ) : 'None' ) . '</td>';
				echo '<td><span class="zzzc-tag">' . esc_html( Authors::status_label( $status ) ) . '</span></td>';
				echo '<td>' . esc_html( $granted ) . '</td><td>' . esc_html( (string) ( $posts[ $id ] ?? 0 ) ) . '</td><td>' . esc_html( implode( ' ', $notes ) ) . '</td><td>';
				if ( Authors::ACTIVE === $status || Authors::PAUSED === $status ) {
					$plan = AuthorSchedules::from_row( $row );
					echo esc_html( AuthorSchedules::usable( $plan ) ? sprintf( 'On: %1$s (%2$s)', implode( ', ', $plan['times'] ), AuthorSchedules::zone_name( $plan ) ) : 'Off' ) . ' <a class="zzzc-btn" href="' . esc_url( admin_url( 'admin.php?page=zzzc-authors&schedule=' . $id ) ) . '#zzzc-author-schedule" aria-label="' . esc_attr( 'Schedule of ' . $user->user_login ) . '">Schedule</a>';
				}
				echo '</td><td class="zzzc-nowrap">';
				$buttons = array(
					Authors::NONE    => array( 'grant' => 'Grant access' ),
					Authors::REVOKED => array( 'grant' => 'Grant access' ),
					Authors::ACTIVE  => array(
						'pause'  => 'Pause',
						'revoke' => 'Revoke',
					),
					Authors::PAUSED  => array(
						'grant'  => 'Resume',
						'revoke' => 'Revoke',
					),
				);
				foreach ( $buttons[ $status ] ?? array() as $action => $label ) {
					echo '<button type="submit" class="zzzc-btn" name="single" value="' . esc_attr( $action . ':' . $id ) . '">' . esc_html( $label ) . '</button> ';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div></form>';
			self::pagination( 'zzzc-authors', $total, $per_page, $paged, array_filter( array( 's' => $search, 'role' => $role, 'access' => $access ), 'strlen' ) );
			echo '</section>';
		}

		$missing = array_values(
			array_filter(
				Authors::ids(),
				static function ( $user_id ) {
					return ! get_userdata( $user_id );
				}
			)
		);
		if ( $missing ) {
			echo '<section class="zzzc-card"><h3>Deleted users</h3><p>' . esc_html( 'These users had access and were later deleted from WordPress. Nothing can be credited to them: ' . implode( ', ', array_map( array( Authors::class, 'user_label' ), $missing ) ) . '.' ) . '</p></section>';
		}

		self::close();
	}

	/**
	 * Authors page: choose Single user or Multiple users mode.
	 */
	private static function author_mode_card(): void {
		$mode  = AuthorSchedules::multiple() ? AuthorSchedules::MULTIPLE : AuthorSchedules::SINGLE;
		$ready = AuthorSchedules::ready_ids();
		echo '<section class="zzzc-card"><h3>Posting mode</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes>';
		Actions::url_field( 'save_author_mode' );
		$choices = array(
			AuthorSchedules::SINGLE   => array( 'Single user mode', 'Every post is credited to the Post author on the Publishing page, at the times on the Schedule page. This is how the plugin has always worked.' ),
			AuthorSchedules::MULTIPLE => array( 'Multiple users mode', 'Each active author with a schedule posts at their own times, in their own timezone, and is credited with those posts. The Post author, Daily target, Interval, First publishing time, Categories in rotation and slot categories are not used. Maximum posts per day and Maximum article generations per day still apply to the whole site.' ),
		);
		echo '<fieldset class="zzzc-field"><legend>Who posts, and when</legend>';
		foreach ( $choices as $value => $text ) {
			echo '<div class="zzzc-check"><label><input type="radio" name="author_mode" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . '> ' . esc_html( $text[0] ) . '</label><p class="zzzc-help">' . esc_html( $text[1] ) . '</p></div>';
		}
		self::field_error( 'author_mode' );
		echo '</fieldset>';
		echo '<p>' . esc_html( $ready ? 'Authors with a schedule ready: ' . implode( ', ', array_map( array( Authors::class, 'user_label' ), $ready ) ) . '.' : 'No active author has a schedule yet. Open Schedule next to an active author below, switch it on and add a post time.' ) . '</p>';
		if ( AuthorSchedules::MULTIPLE === $mode ) {
			foreach ( AuthorSchedules::capacity_warnings() as $warning ) {
				echo '<p class="zzzc-alert">' . esc_html( $warning ) . '</p>';
			}
		}
		echo '<p class="zzzc-help">Switching closes the times already planned for the other mode that were not used yet, and plans the coming times again. Articles already written stay ready. Every switch is written to the Logs with who made it.</p>';
		self::save_bar( 'Save posting mode' );
		echo '</form></section>';
	}

	/**
	 * Authors page: the schedule form for one authorised author.
	 */
	private static function author_schedule_form( int $user_id ): void {
		$row  = Authors::row( $user_id );
		$user = get_userdata( $user_id );
		echo '<section class="zzzc-card" id="zzzc-author-schedule">';
		if ( ! $row || ! $user || ! in_array( (string) $row['status'], array( Authors::ACTIVE, Authors::PAUSED ), true ) ) {
			echo '<h3>Schedule</h3><p class="zzzc-empty">Only an active or paused author can have a schedule. Grant access first.</p></section>';
			return;
		}
		$saved = AuthorSchedules::from_row( $row );
		$old   = ! empty( self::$flash['fields'] ) && is_array( self::$flash['old'] ?? null ) ? self::$flash['old'] : null;
		$val   = static function ( string $key ) use ( $old, $saved ) {
			if ( null !== $old && array_key_exists( $key, $old ) ) {
				return $old[ $key ];
			}
			if ( 'times' === $key || 'skip_dates' === $key ) {
				return implode( ', ', $saved[ $key ] );
			}
			if ( 'timezone' === $key ) {
				return AuthorSchedules::zone_name( $saved );
			}
			return $saved[ $key ];
		};

		echo '<h3>' . esc_html( 'Schedule of ' . Authors::user_label( $user_id ) ) . '</h3>';
		if ( Authors::PAUSED === (string) $row['status'] ) {
			echo '<p class="zzzc-alert">This author is paused. Their times are not used until they are resumed.</p>';
		}
		if ( ! AuthorSchedules::multiple() ) {
			echo '<p class="zzzc-help">Single user mode is on, so this schedule is saved but not used yet. Switch to Multiple users mode above to use it.</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-form" data-track-changes>';
		Actions::url_field( 'save_author_schedule' );
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';

		echo '<div class="zzzc-field zzzc-check"><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $val( 'enabled' ) ), true, false ) . '> Post on this schedule</label><p class="zzzc-help">Switched off, the author keeps their access but no times are planned for them.</p></div>';

		$text_field = static function ( string $key, string $label, string $help, string $type = 'text', string $extra = '' ) use ( $val ) {
			printf(
				'<div class="zzzc-field"><label for="as-%1$s">%2$s</label><input type="%5$s" id="as-%1$s" name="%1$s" value="%3$s"%6$s>%4$s',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) $val( $key ) ),
				$help ? '<p class="zzzc-help">' . esc_html( $help ) . '</p>' : '',
				esc_attr( $type ),
				$extra // Fixed attribute text, never user input.
			);
			self::field_error( $key );
			echo '</div>';
		};
		echo '<div class="zzzc-grid">';
		$text_field( 'timezone', 'Timezone', 'The author\'s own timezone, such as Europe/London, or a fixed offset such as +06:00. Their times, dates and limits all follow it. The site timezone is filled in until you change it.' );
		$text_field( 'times', 'Post times', sprintf( '24-hour times separated by commas, such as 09:00, 13:30, 18:00. Up to %1$d times, at least %2$d minutes apart, and at least %2$d minutes from any other author\'s time.', AuthorSchedules::MAX_TIMES, AuthorSchedules::MIN_GAP_MINUTES ) );
		$text_field( 'per_time', 'Posts at each time', sprintf( 'From 1 to %d. Several posts at one time go out one after another.', AuthorSchedules::MAX_PER_TIME ), 'number', ' min="1" max="' . (int) AuthorSchedules::MAX_PER_TIME . '" step="1"' );
		$text_field( 'from', 'From', 'Optional. The first date, in the author\'s calendar, on which they post.', 'date' );
		$text_field( 'until', 'Until', 'Optional. The last date on which they post.', 'date' );
		echo '</div>';

		$days = array_map( 'strval', (array) $val( 'days' ) );
		echo '<fieldset class="zzzc-field"><legend>Days of the week</legend><div class="zzzc-days">';
		foreach ( array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' ) as $number => $label ) {
			echo '<label><input type="checkbox" name="days[]" value="' . esc_attr( (string) $number ) . '"' . checked( in_array( (string) $number, $days, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		self::field_error( 'days' );
		echo '</fieldset>';

		echo '<div class="zzzc-field"><label for="as-skip_dates">Dates to skip</label><textarea id="as-skip_dates" name="skip_dates" rows="2">' . esc_textarea( (string) $val( 'skip_dates' ) ) . '</textarea><p class="zzzc-help">Optional. Dates such as 2026-12-25, separated by commas. The author does not post on them.</p>';
		self::field_error( 'skip_dates' );
		echo '</div>';

		$chosen = array_map( 'intval', (array) $val( 'categories' ) );
		echo '<fieldset class="zzzc-field"><legend>Categories</legend><p class="zzzc-help">Leave all unticked to use every enabled category. Only articles from the ticked categories are posted for this author.</p><div class="zzzc-days">';
		foreach ( Categories::all() as $category ) {
			echo '<label><input type="checkbox" name="categories[]" value="' . esc_attr( (string) $category['id'] ) . '"' . checked( in_array( (int) $category['id'], $chosen, true ), true, false ) . '> ' . esc_html( $category['name'] . ( (int) $category['enabled'] ? '' : ' (disabled)' ) ) . '</label>';
		}
		echo '</div>';
		self::field_error( 'categories' );
		echo '</fieldset>';

		$usage   = AuthorSchedules::usage( $user_id );
		$periods = array(
			'day'   => array( 'limit_day', 'Most posts a day', 'today' ),
			'week'  => array( 'limit_week', 'Most posts a week', 'this week, Monday to Sunday' ),
			'month' => array( 'limit_month', 'Most posts a month', 'this month' ),
		);
		echo '<div class="zzzc-grid">';
		foreach ( $periods as $period => $meta ) {
			$used = $usage[ $period ];
			$text_field( $meta[0], $meta[1], sprintf( '0 means no limit. Used %1$s: %2$d%3$s.', $meta[2], $used['used'], $used['limit'] ? ' of ' . $used['limit'] : '' ), 'number', ' min="0" max="' . (int) AuthorSchedules::MAX_LIMIT . '" step="1"' );
		}
		echo '</div>';

		$rule = (string) $val( 'missed_rule' );
		echo '<div class="zzzc-field"><label for="as-missed_rule">When a time is missed</label><select id="as-missed_rule" name="missed_rule">';
		foreach ( array( 'next' => 'Publish at the author\'s next time today', 'skip' => 'Skip it' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $rule, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="zzzc-help">A time is missed when no article was ready in time. Limits count posts the same way as What counts toward the maximum on the Schedule page.</p>';
		self::field_error( 'missed_rule' );
		echo '</div>';

		if ( ! empty( $row['sched_changed_at'] ) ) {
			echo '<p class="zzzc-help">' . esc_html( 'Last saved ' . Schedule::format_local( (string) $row['sched_changed_at'] ) . ( (int) $row['sched_changed_by'] ? ' by ' . Authors::user_label( (int) $row['sched_changed_by'] ) : '' ) . ': ' . AuthorSchedules::summary( $saved ) ) . '</p>';
		}

		self::save_bar( 'Save schedule' );
		echo ' <a class="zzzc-btn" href="' . esc_url( admin_url( 'admin.php?page=zzzc-authors' ) ) . '">Close</a></form></section>';
	}

	public static function images(): void {
		self::open( 'Images', 'zzzc-images' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-track-changes>';
		Actions::url_field( 'save_images' );

		echo '<section class="zzzc-card"><h3>Automatic featured images</h3><p class="zzzc-help">As soon as an article is ready, the plugin searches Wikimedia Commons, checks the license and labels of each file, and sets the best one as the featured image. These checks follow rules and read file labels. They do not look at the picture, and no AI is used.</p><div class="zzzc-grid">';
		echo '<div class="zzzc-field"><label>Featured image</label><p>Required on every post, and cannot be turned off. A post goes public only when its featured image is set and its file opens on the server, including when a person publishes one of this plugin\'s drafts by hand.</p></div>';
		echo '<div class="zzzc-field"><label>When no image can be used</label><p>The article is not published. It waits in Failed or held, and its image search runs once more 6 hours later. With Past-Hour Posting on, another story is chosen instead, while there is time.</p></div>';
		echo '<div class="zzzc-field"><label>Image source</label><p>Wikimedia Commons</p></div>';
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>Search and quality</h3><div class="zzzc-grid">';
		self::number_row( 'image_query_count', 'Searches for each article', 'From 3 to 5.' );
		self::number_row( 'image_candidates_per_query', 'Files checked for each search' );
		self::number_row( 'image_min_width', 'Minimum width (pixels)' );
		self::number_row( 'image_min_height', 'Minimum height (pixels)' );
		self::number_row( 'image_preferred_ratio', 'Preferred width-to-height shape', '16:9 is 1.78.', '0.01' );
		self::number_row( 'image_min_score', 'Minimum relevance score (out of 100)', 'Files below this score are not used. A high score never overrides a license or safety rule.' );
		self::number_row( 'image_max_file_mb', 'Largest file to download (MB)' );
		self::number_row( 'image_max_attempts', 'Attempts for each article', 'Used for temporary problems, such as Wikimedia being busy.' );
		self::number_row( 'image_api_gap_seconds', 'Seconds between Wikimedia requests', 'Wikimedia limits fast requests. Keep this at 3 or more.' );
		echo '</div></section>';

		echo '<section class="zzzc-card"><h3>License and credit</h3><div class="zzzc-grid">';
		self::select_row(
			'image_attribution',
			'Show the credit line',
			array(
				'all'      => 'On every image',
				'required' => 'Only when the license requires it (CC BY and CC BY-SA)',
			)
		);
		echo '<div class="zzzc-field"><label>Credit placement</label><p>Directly below the featured image, once. If a theme or page builder prints the image another way, the credit shows at the top of the article text.</p></div>';
		self::check_row( 'image_allow_share_alike', 'Accept CC BY-SA images', 'Finds images for many more stories. Do not edit these images: an edited version must be shared under the same license. Turning this off does not change images already used.' );
		echo '<div class="zzzc-field"><label>Licenses accepted</label><p>CC0, public domain and CC BY, plus CC BY-SA when the box above is ticked. Non-commercial, no-derivatives, unknown and conflicting licenses are always refused.</p></div>';
		echo '<div class="zzzc-field"><label>Repeat prevention</label><p>Always on. An image is never used twice, even after its post or Media Library file is deleted. Only the Release button below can change this.</p></div>';
		echo '</div>';
		self::save_bar( 'Save image settings' );
		echo '</section></form>';

		echo '<section class="zzzc-card"><h3>Test tools</h3><div class="zzzc-buttons"><button type="button" class="zzzc-btn" id="zzzc-test-commons">Test Wikimedia connection</button> <span id="zzzc-commons-result" role="status"></span></div>';
		echo '<div class="zzzc-field"><label for="zzzc-image-text">Headline or article text</label><textarea id="zzzc-image-text" rows="5"></textarea></div>';
		echo '<div class="zzzc-field"><label for="zzzc-image-category">Category</label><select id="zzzc-image-category"><option value="0">None</option>';
		foreach ( Categories::all() as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $row['name'] ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="zzzc-buttons"><button type="button" class="zzzc-btn" id="zzzc-image-search">Preview image search</button></div><p class="zzzc-help">The preview shows the score, license, creator and result of each file. Nothing is reserved, downloaded or saved.</p><div id="zzzc-image-results" role="status"></div></section>';

		$missing = ImagePipeline::posts_without_image( 20 );
		if ( $missing ) {
			echo '<section class="zzzc-card"><h3>Published posts with no image</h3><p class="zzzc-help">These were published before images were required. They stay published. Retry image searches Wikimedia Commons again, with every rule.</p><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Post</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $missing as $row ) {
				echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( (int) $row['wp_post_id'] ) ) . '">' . esc_html( get_the_title( (int) $row['wp_post_id'] ) ) . '</a></td><td>';
				self::retry_image_button( (int) $row['article_job_id'], (int) $row['wp_post_id'] );
				echo '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}

		$stats = ImagePipeline::stats();
		echo '<section class="zzzc-card"><h3>Image totals</h3><dl class="zzzc-dl">';
		self::image_stat_rows( $stats );
		echo '</dl></section>';

		$paged    = max( 1, absint( self::request_string( 'paged', '1' ) ) );
		$per_page = 25;
		$rows     = ImageHistory::recent( $per_page, ( $paged - 1 ) * $per_page );

		echo '<section class="zzzc-card"><h3>Image history</h3><p class="zzzc-help">This is the permanent record of images held or used. Release an image only if you are sure it may be used again.</p>';
		if ( $rows ) {
			echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Picture</th><th>Status</th><th>File</th><th>Creator</th><th>License</th><th>Article</th><th>Post</th><th>Reserved</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$attachment = (int) $row['attachment_id'];
				echo '<tr><td>';
				if ( $attachment ) {
					echo wp_get_attachment_image( $attachment, array( 80, 45 ), false, array( 'class' => 'zzzc-thumb', 'alt' => '' ) );
				}
				echo '</td><td><span class="zzzc-tag">' . esc_html( $row['status'] ) . '</span>';
				if ( '' !== (string) $row['status_reason'] ) {
					echo '<br><span class="zzzc-help">' . esc_html( (string) $row['status_reason'] ) . '</span>';
				}
				echo '</td><td><a href="' . esc_url( (string) $row['commons_url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( (string) $row['image_title'] ) . '</a></td><td>' . esc_html( (string) $row['creator'] ) . '</td><td>' . esc_html( (string) $row['license'] ) . '</td><td>';
				echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'zzzc-articles', 'view' => (int) $row['article_job_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( (string) $row['article_title'] ) . '</a></td><td>' . esc_html( (string) ( (int) $row['wp_post_id'] ?: '' ) ) . '</td><td class="zzzc-nowrap">' . esc_html( Schedule::format_local( $row['reserved_at'], false ) ) . '</td><td>';
				if ( ImageHistory::RELEASED !== $row['status'] ) {
					self::action_button( 'release_image', 'Release', array( 'id' => (int) $row['id'] ), 'is-small', 'Release this image? A future article will be able to use it again, which may repeat a picture on your site. This is written to the log.' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			self::pagination( 'zzzc-images', ImageHistory::count(), $per_page, $paged );
		} else {
			echo '<p class="zzzc-empty">No images yet.</p>';
		}
		echo '</section>';

		echo '<section class="zzzc-card"><h3>Image log</h3>';
		self::events_table( Events::query( array( 'stage' => 'image' ), 15 ), false );
		echo '</section>';

		self::close();
	}

	private static function image_stat_rows( array $stats ): void {
		$labels = array(
			'searches'   => 'Searches',
			'checked'    => 'Files checked',
			'downloaded' => 'Files downloaded',
			'assigned'   => 'Images set as featured',
			'no_image'   => 'Articles with no suitable image',
			'failed'     => 'Assignment failures',
		);
		foreach ( $labels as $key => $label ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) (int) $stats[ $key ] ) . '</dd>';
		}
	}

	private static function image_label( string $status ): string {
		$labels = array(
			'assigned'  => 'Added',
			'ready'     => 'Ready',
			'searching' => 'Searching',
			'waiting'   => 'Waiting to retry',
			'none'      => 'No image',
			'failed'    => 'Failed',
			'pending'   => 'Waiting',
		);
		return $labels[ $status ] ?? '';
	}

	/* ---- Articles ---- */

	public static function articles(): void {
		self::open( 'Articles', 'zzzc-articles' );

		$detail = absint( self::request_string( 'view' ) );
		if ( $detail ) {
			self::article_detail( $detail );
			self::close();
			return;
		}

		$status   = self::request_string( 'status' );
		$paged    = max( 1, absint( self::request_string( 'paged', '1' ) ) );
		$per_page = 25;

		echo '<nav class="zzzc-filter-links">';
		foreach ( array( '' => 'All', 'queue' => 'Queue', 'attention' => 'Failed or held', Lifecycle::PUBLISHED => 'Published', Lifecycle::DRAFT_CREATED => 'Drafts', Lifecycle::SKIPPED => 'Skipped' ) as $value => $label ) {
			echo '<a class="zzzc-btn is-small' . ( $status === (string) $value ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'zzzc-articles', 'status' => $value ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</nav><section class="zzzc-card">';

		$rows  = Articles::listing( $status, $per_page, ( $paged - 1 ) * $per_page );
		$total = Articles::listing_count( $status );

		if ( ! $rows ) {
			echo '<p class="zzzc-empty">No articles here yet.</p>';
		} else {
			echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Job</th><th>Status</th><th>Title or headline</th><th>Category</th><th>Publisher</th><th>Updated</th><th>Post</th><th>Image</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				echo '<tr><td>' . esc_html( (string) $id ) . '</td><td><span class="zzzc-tag">' . esc_html( $row['status'] ) . '</span></td><td><a href="' . esc_url( add_query_arg( array( 'page' => 'zzzc-articles', 'view' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( wp_strip_all_tags( (string) ( $row['title'] ?: $row['headline'] ) ) ) . '</a>';
				if ( $row['error_message'] ) {
					echo '<br><span class="zzzc-help">' . esc_html( (string) $row['error_message'] ) . '</span>';
				}
				echo '</td><td>' . esc_html( (string) $row['category'] ) . '</td><td>' . esc_html( (string) $row['publisher'] ) . '</td><td class="zzzc-nowrap">' . esc_html( Schedule::format_local( $row['updated_at'], false ) ) . '</td><td>';
				if ( $row['wp_post_id'] ) {
					$link = get_edit_post_link( (int) $row['wp_post_id'] );
					echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $row['wp_post_id'] ) . '</a>' : esc_html( (string) $row['wp_post_id'] );
				}
				echo '</td><td>';
				self::image_cell( $row );
				echo '</td><td class="zzzc-nowrap">';
				self::article_actions( $row['status'], $id, $status );
				self::retry_image_button( $id, (int) $row['wp_post_id'] );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			self::pagination( 'zzzc-articles', $total, $per_page, $paged, array( 'status' => $status ) );
		}
		echo '</section>';

		self::close();
	}

	private static function article_actions( string $status, int $id, string $return_status ): void {
		if ( in_array( $status, Lifecycle::ATTENTION, true ) ) {
			self::action_button( 'retry_article', 'Retry', array( 'id' => $id, 'return_status' => $return_status ), 'is-small' );
		}
		if ( Lifecycle::can( $status, Lifecycle::SKIPPED ) ) {
			self::action_button( 'skip_article', 'Skip', array( 'id' => $id, 'return_status' => $return_status ), 'is-small', 'Skip this article? It will not be published.' );
		}
	}

	/**
	 * The Articles table cell: thumbnail, status, creator, license and source.
	 */
	private static function image_cell( array $row ): void {
		$post_id = (int) $row['wp_post_id'];
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			echo get_the_post_thumbnail( $post_id, array( 80, 45 ), array( 'class' => 'zzzc-thumb', 'alt' => '' ) );
		}
		$label = self::image_label( (string) $row['image_status'] );
		if ( '' !== $label ) {
			echo '<span class="zzzc-tag">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== (string) $row['image_license'] ) {
			echo '<br><span class="zzzc-help">' . esc_html( trim( $row['image_creator'] . ', ' . $row['image_license'], ', ' ) ) . '</span>';
			echo '<br><a class="zzzc-help" href="' . esc_url( (string) $row['image_source'] ) . '" rel="noopener noreferrer" target="_blank">Source</a>';
		} elseif ( '' !== (string) $row['image_reason'] ) {
			echo '<br><span class="zzzc-help">' . esc_html( (string) $row['image_reason'] ) . '</span>';
		}
	}

	/**
	 * Offered only for a post that exists and has no featured image.
	 */
	private static function retry_image_button( int $id, int $post_id ): void {
		if ( $post_id && get_post( $post_id ) && ! has_post_thumbnail( $post_id ) ) {
			self::action_button( 'retry_image', 'Retry image', array( 'id' => $id ), 'is-small' );
		}
	}

	private static function article_image_card( int $id, int $post_id ): void {
		$run = ImagePipeline::get_run( $id );
		if ( ! $run ) {
			return;
		}
		echo '<section class="zzzc-card"><h3>Featured image</h3>';
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			echo get_the_post_thumbnail( $post_id, 'medium', array( 'class' => 'zzzc-image-large', 'alt' => '' ) );
		}
		echo '<dl class="zzzc-dl"><dt>Status</dt><dd>' . esc_html( self::image_label( (string) $run['status'] ) ) . '</dd>';
		if ( '' !== (string) $run['reason'] ) {
			echo '<dt>Reason</dt><dd>' . esc_html( (string) $run['reason'] ) . '</dd>';
		}
		$history = (int) $run['history_id'] ? ImageHistory::get( (int) $run['history_id'] ) : null;
		if ( $history ) {
			echo '<dt>File</dt><dd><a href="' . esc_url( (string) $history['commons_url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( (string) $history['image_title'] ) . '</a></dd>';
			echo '<dt>Creator</dt><dd>' . esc_html( (string) $history['creator'] ?: 'Not stated' ) . '</dd>';
			echo '<dt>License</dt><dd>' . esc_html( (string) $history['license'] ) . '</dd>';
		}
		$queries = json_decode( (string) $run['queries'], true ) ?: array();
		if ( $queries ) {
			echo '<dt>Searches</dt><dd>' . esc_html( implode( ' | ', $queries ) ) . '</dd>';
		}
		echo '<dt>Attempts</dt><dd>' . esc_html( (string) (int) $run['attempts'] ) . '</dd></dl><div class="zzzc-buttons">';
		self::retry_image_button( $id, $post_id );
		echo '</div>';

		$candidates = json_decode( (string) $run['candidates'], true ) ?: array();
		if ( $candidates ) {
			echo '<p><strong>Files checked</strong></p><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>File</th><th>Size</th><th>License</th><th>Creator</th><th>Score</th><th>Result</th></tr></thead><tbody>';
			foreach ( $candidates as $candidate ) {
				echo '<tr><td><a href="' . esc_url( (string) $candidate['page_url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( (string) $candidate['title'] ) . '</a></td><td class="zzzc-nowrap">' . esc_html( (string) $candidate['size'] ) . '</td><td>' . esc_html( (string) $candidate['license'] ) . '</td><td>' . esc_html( (string) $candidate['creator'] ) . '</td><td>' . esc_html( (string) (int) $candidate['score'] ) . '</td><td>' . esc_html( (string) $candidate['rejected'] ?: 'Passed' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';
	}

	private static function article_detail( int $id ): void {
		$job = Lifecycle::job( $id );
		if ( ! $job ) {
			echo '<p class="zzzc-empty">Article not found.</p>';
			return;
		}
		$item    = Lifecycle::item( (int) $job['source_item_id'] );
		$content = Lifecycle::content( $id );
		$link    = Publisher::find_existing_post( $job );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=zzzc-articles' ) ) . '">Back to Articles</a></p><section class="zzzc-card"><h3>Article record</h3><dl class="zzzc-dl">';
		$rows = array(
			'Job id'                 => $job['job_uid'],
			'Status'                 => $job['status'],
			'Category'               => Categories::name( (int) $job['category_id'] ),
			'Model'                  => $job['model_id'],
			'Article prompt version' => $job['article_prompt_version'],
			'Tag prompt version'     => $job['tag_prompt_version'],
			'AI attempts'            => $job['ai_attempts'],
			'Error'                  => trim( $job['error_code'] . ' ' . $job['error_message'] ),
			'Created'                => Schedule::format_local( $job['created_at'] ),
			'Updated'                => Schedule::format_local( $job['updated_at'] ) . ' (' . $job['updated_at'] . ' UTC)',
			'WordPress post'         => $link ?: '',
		);
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
			}
		}
		echo '</dl><div class="zzzc-buttons">';
		self::article_actions( $job['status'], $id, '' );
		echo '</div></section>';

		self::article_image_card( $id, (int) $link );

		if ( $item ) {
			echo '<section class="zzzc-card"><h3>Source</h3><dl class="zzzc-dl">';
			$source_url = (string) ( $item['canonical_url'] ?: $item['resolved_url'] );
			$rows       = array(
				'Headline'         => wp_strip_all_tags( (string) $item['headline'] ),
				'Publisher'        => $item['publisher'],
				'Published'        => Schedule::format_local( $item['published_at'] ),
				'Found through'    => $item['search_context'],
				'Filter applied'   => $item['effective_filter'],
				'Retrieval result' => $item['retrieval_status'],
				'Source words'     => $item['source_word_count'],
				'Address found by' => $item['resolve_method'],
			);
			foreach ( $rows as $label => $value ) {
				if ( '' !== (string) $value ) {
					echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
				}
			}
			if ( '' !== $source_url ) {
				echo '<dt>Source address</dt><dd><a href="' . esc_url( $source_url ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $source_url ) . '</a></dd>';
			}
			echo '</dl></section>';
		}

		if ( $content ) {
			$verdict = json_decode( (string) $content['validation_result'], true ) ?: array();
			$tags    = json_decode( (string) $content['tags_json'], true ) ?: array();

			echo '<section class="zzzc-card"><h3>Validation</h3>';
			if ( ! empty( $verdict['metrics'] ) ) {
				echo '<p class="zzzc-help">' . esc_html( sprintf( 'Internal diagnostics, never printed in the article: %1$d words, reading ease %2$s, %3$d tags.', (int) $verdict['metrics']['word_count'], null === $verdict['metrics']['reading_ease'] ? 'n/a' : (string) $verdict['metrics']['reading_ease'], (int) $verdict['metrics']['tag_count'] ) ) . '</p>';
			}
			foreach ( array( 'errors' => 'Errors', 'warnings' => 'Warnings' ) as $key => $label ) {
				if ( ! empty( $verdict[ $key ] ) ) {
					echo '<p><strong>' . esc_html( $label ) . '</strong></p><ul class="zzzc-list">';
					foreach ( $verdict[ $key ] as $entry ) {
						echo '<li>' . esc_html( (string) $entry[1] ) . '</li>';
					}
					echo '</ul>';
				}
			}
			echo '</section>';

			echo '<section class="zzzc-card"><h3>' . esc_html( wp_strip_all_tags( (string) $content['title'] ) ) . '</h3><div class="zzzc-preview">' . wp_kses_post( (string) $content['body'] ) . '</div>';
			echo '<p><strong>Tags (' . esc_html( (string) count( $tags ) ) . '):</strong> ' . esc_html( implode( ', ', $tags ) ) . '</p></section>';
		}

		if ( ! empty( $job['request_audit'] ) ) {
			echo '<section class="zzzc-card"><h3>Request record</h3><pre class="zzzc-pre">' . esc_html( (string) wp_json_encode( json_decode( (string) $job['request_audit'], true ), JSON_PRETTY_PRINT ) ) . '</pre></section>';
		}

		// The social posts for this article, and the buttons to distribute it. Added after
		// everything that was already here, so the existing article view is unchanged.
		SocialPages::article_card( $id, $link );

		echo '<section class="zzzc-card"><h3>History</h3>';
		self::events_table( Events::query( array( 'job' => $id ), 50 ), true );
		echo '</section>';
	}

	/* ---- Usage ---- */

	public static function usage(): void {
		self::open( 'Usage', 'zzzc-usage' );

		list( $today_start, $today_end ) = Schedule::day_bounds_utc( Schedule::today() );
		$month_start = Schedule::day_bounds_utc( Schedule::now_local()->format( 'Y-m-01' ) )[0];

		$columns = array(
			'Today'      => Usage::totals( $today_start, $today_end ),
			'This month' => Usage::totals( $month_start, $today_end ),
			'All time'   => Usage::totals(),
		);

		echo '<section class="zzzc-card"><h3>OpenRouter totals</h3><p class="zzzc-help">Cost is what OpenRouter reported. It is never estimated. "Unavailable" means no cost was reported.</p><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Period</th><th>Requests</th><th>Failed</th><th>Input tokens</th><th>Output tokens</th><th>Total tokens</th><th>Reported cost (USD)</th></tr></thead><tbody>';
		foreach ( $columns as $label => $row ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) (int) ( $row['requests'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['failed'] ?? 0 ) ) . '</td><td>' . esc_html( number_format( (int) ( $row['input_tokens'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format( (int) ( $row['output_tokens'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format( (int) ( $row['total_tokens'] ?? 0 ) ) ) . '</td><td>' . esc_html( self::money( $row ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="zzzc-card"><h3>Images</h3><p class="zzzc-help">Wikimedia Commons is free and no AI is used for images, so images add no cost here.</p><div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Period</th><th>Searches</th><th>Files checked</th><th>Downloaded</th><th>Set as featured</th><th>No suitable image</th><th>Failures</th></tr></thead><tbody>';
		$image_periods = array(
			'Today'      => ImagePipeline::stats( $today_start, $today_end ),
			'This month' => ImagePipeline::stats( $month_start, $today_end ),
			'All time'   => ImagePipeline::stats(),
		);
		foreach ( $image_periods as $label => $stats ) {
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( array( 'searches', 'checked', 'downloaded', 'assigned', 'no_image', 'failed' ) as $key ) {
				echo '<td>' . esc_html( (string) (int) $stats[ $key ] ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';

		$latest = Usage::latest();
		echo '<section class="zzzc-card"><h3>Latest generation</h3>';
		if ( $latest ) {
			echo '<dl class="zzzc-dl"><dt>Time</dt><dd>' . esc_html( Schedule::format_local( $latest['created_at'] ) ) . '</dd><dt>Model</dt><dd>' . esc_html( $latest['model'] ) . '</dd><dt>Generation id</dt><dd>' . esc_html( $latest['generation_id'] ?: 'Not returned' ) . '</dd><dt>Request id</dt><dd>' . esc_html( $latest['request_id'] ?: 'Not returned' ) . '</dd><dt>Result</dt><dd>' . esc_html( (int) $latest['success'] ? 'Succeeded' : 'Failed: ' . $latest['error_code'] ) . '</dd></dl>';
		} else {
			echo '<p class="zzzc-empty">No requests yet.</p>';
		}
		echo '</section>';

		$paged    = max( 1, absint( self::request_string( 'paged', '1' ) ) );
		$per_page = 25;
		$rows     = Usage::history( $per_page, ( $paged - 1 ) * $per_page );

		echo '<section class="zzzc-card"><h3>Request history</h3>';
		if ( $rows ) {
			echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Time</th><th>Purpose</th><th>Job</th><th>Model</th><th>Result</th><th>Input</th><th>Output</th><th>Total</th><th>Cost</th><th>Generation id</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td class="zzzc-nowrap">' . esc_html( Schedule::format_local( $row['created_at'], false ) ) . '</td><td>' . esc_html( $row['purpose'] ) . '</td><td>' . esc_html( (string) ( (int) $row['article_job_id'] ?: '' ) ) . '</td><td>' . esc_html( $row['model'] ) . '</td><td>' . esc_html( (int) $row['success'] ? 'OK' : ( $row['error_code'] ?: 'Failed' ) ) . '</td><td>' . esc_html( (string) ( $row['input_tokens'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['output_tokens'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['total_tokens'] ?? '' ) ) . '</td><td>' . esc_html( null === $row['cost_usd'] ? 'Unavailable' : '$' . number_format( (float) $row['cost_usd'], 6 ) ) . '</td><td><code>' . esc_html( $row['generation_id'] ) . '</code></td></tr>';
			}
			echo '</tbody></table></div>';
			self::pagination( 'zzzc-usage', Usage::history_count(), $per_page, $paged );
		} else {
			echo '<p class="zzzc-empty">No requests yet.</p>';
		}
		echo '</section>';

		self::close();
	}

	/* ---- Logs ---- */

	/**
	 * Converts submitted log filters to the form the query needs.
	 */
	public static function log_filters( array $input ): array {
		$out = array();
		$tz  = Schedule::timezone();
		foreach ( array( 'from' => '00:00:00', 'to' => '23:59:59' ) as $key => $time ) {
			$value = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$out[ $key ] = gmdate( 'Y-m-d H:i:s', ( new \DateTimeImmutable( $value . ' ' . $time, $tz ) )->getTimestamp() );
			}
		}
		foreach ( array( 'category_id', 'job', 'post', 'user' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$out[ $key ] = absint( $input[ $key ] );
			}
		}
		foreach ( array( 'stage', 'status', 'severity' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		return $out;
	}

	public static function logs(): void {
		self::open( 'Logs', 'zzzc-logs' );

		$raw = array();
		foreach ( array( 'from', 'to', 'category_id', 'stage', 'status', 'severity', 'job', 'post', 'user' ) as $key ) {
			$raw[ $key ] = self::request_string( $key );
		}
		$filters  = self::log_filters( $raw );
		$paged    = max( 1, absint( self::request_string( 'paged', '1' ) ) );
		$per_page = 50;

		echo '<section class="zzzc-card"><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="zzzc-form"><input type="hidden" name="page" value="zzzc-logs"><div class="zzzc-grid">';
		echo '<div class="zzzc-field"><label for="l-from">From</label><input id="l-from" type="date" name="from" value="' . esc_attr( $raw['from'] ) . '"></div>';
		echo '<div class="zzzc-field"><label for="l-to">To</label><input id="l-to" type="date" name="to" value="' . esc_attr( $raw['to'] ) . '"></div>';

		echo '<div class="zzzc-field"><label for="l-cat">Category</label><select id="l-cat" name="category_id"><option value="">All</option>';
		foreach ( Categories::all() as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $raw['category_id'], (string) $row['id'], false ) . '>' . esc_html( $row['name'] ) . '</option>';
		}
		echo '</select></div>';

		echo '<div class="zzzc-field"><label for="l-stage">Stage</label><select id="l-stage" name="stage"><option value="">All</option>';
		foreach ( Events::STAGES as $stage ) {
			echo '<option value="' . esc_attr( $stage ) . '"' . selected( $raw['stage'], $stage, false ) . '>' . esc_html( $stage ) . '</option>';
		}
		echo '</select></div>';

		echo '<div class="zzzc-field"><label for="l-sev">Severity</label><select id="l-sev" name="severity"><option value="">All</option>';
		foreach ( array( 'info', 'warning', 'error' ) as $severity ) {
			echo '<option value="' . esc_attr( $severity ) . '"' . selected( $raw['severity'], $severity, false ) . '>' . esc_html( $severity ) . '</option>';
		}
		echo '</select></div>';

		echo '<div class="zzzc-field"><label for="l-status">Status</label><input id="l-status" type="text" name="status" value="' . esc_attr( $raw['status'] ) . '"></div>';
		echo '<div class="zzzc-field"><label for="l-job">Job id</label><input id="l-job" type="number" name="job" value="' . esc_attr( $raw['job'] ) . '"></div>';
		echo '<div class="zzzc-field"><label for="l-post">Post id</label><input id="l-post" type="number" name="post" value="' . esc_attr( $raw['post'] ) . '"></div>';
		echo '<div class="zzzc-field"><label for="l-user">Person</label><select id="l-user" name="user"><option value="">Anyone</option>';
		foreach ( Authors::ids() as $user_id ) {
			echo '<option value="' . esc_attr( (string) $user_id ) . '"' . selected( $raw['user'], (string) $user_id, false ) . '>' . esc_html( Authors::user_label( $user_id ) ) . '</option>';
		}
		echo '</select><p class="zzzc-help">Authors on the Authors page. Shows what they did and what was credited to them.</p></div>';
		echo '</div><button type="submit" class="zzzc-btn is-primary">Apply filters</button> <a class="zzzc-btn" href="' . esc_url( admin_url( 'admin.php?page=zzzc-logs' ) ) . '">Clear</a></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zzzc-inline zzzc-export">';
		Actions::url_field( 'export_logs' );
		foreach ( $raw as $key => $value ) {
			echo '<input type="hidden" name="filters[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="zzzc-btn">Export CSV</button></form></section>';

		$total = Events::count( $filters );
		echo '<section class="zzzc-card">';
		self::events_table( Events::query( $filters, $per_page, ( $paged - 1 ) * $per_page ), true );
		self::pagination( 'zzzc-logs', $total, $per_page, $paged, array_filter( $raw, 'strlen' ) );
		echo '<p class="zzzc-help">Times are in ' . esc_html( Schedule::zone_label() ) . '. Hover over a time to see UTC.</p></section>';

		self::close();
	}

	/* ---- Social media ---- */

	/**
	 * InkRush, Social Media: the six connection cards and the distribution settings.
	 * The screen itself is in SocialPages, so this file keeps to the original screens.
	 */
	public static function social(): void {
		SocialPages::accounts();
	}

	/**
	 * InkRush, Social Publishing: the figures, the approval queue and the publishing history.
	 */
	public static function social_posts(): void {
		SocialPages::publishing();
	}
}
