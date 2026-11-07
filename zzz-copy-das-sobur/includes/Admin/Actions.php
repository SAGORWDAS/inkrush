<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Articles;
use ZZZCopy\AuthorSchedules;
use ZZZCopy\Authors;
use ZZZCopy\AutoStop;
use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Events;
use ZZZCopy\Images\ImageHistory;
use ZZZCopy\Images\ImagePipeline;
use ZZZCopy\Lifecycle;
use ZZZCopy\Plugin;
use ZZZCopy\Prompts;
use ZZZCopy\RestTick;
use ZZZCopy\Schedule;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every admin form. Each action checks capability and nonce before doing anything.
 */
final class Actions {

	private const HANDLERS = array(
		'start', 'stop', 'pause_research', 'pause_publishing', 'resume',
		'save_category', 'delete_category', 'duplicate_category', 'move_category', 'toggle_category', 'save_source_rules',
		'save_ai', 'save_key', 'clear_key', 'restore_prompt',
		'save_schedule', 'save_auto_stop', 'regenerate_token',
		'save_publishing', 'author_access', 'save_author_mode', 'save_author_schedule', 'save_images', 'release_image', 'retry_image',
		'retry_article', 'skip_article',
		'export_logs',
	);

	public static function register(): void {
		foreach ( self::HANDLERS as $action ) {
			add_action( 'admin_post_zzzc_' . $action, array( __CLASS__, 'dispatch' ) );
		}
	}

	public static function url_field( string $action ): void {
		echo '<input type="hidden" name="action" value="zzzc_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'zzzc_' . $action );
	}

	public static function dispatch(): void {
		$hook   = current_action();
		$action = substr( $hook, strlen( 'admin_post_zzzc_' ) );

		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zzz-copy-das-sobur' ), 403 );
		}
		check_admin_referer( 'zzzc_' . $action );

		if ( ! in_array( $action, self::HANDLERS, true ) ) {
			wp_die( 'Unknown action.', 400 );
		}

		call_user_func( array( __CLASS__, 'do_' . $action ) );
	}

	private static function redirect( string $page, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stores a message for the next page view of this user.
	 *
	 * @param string $type   success, error or warning.
	 * @param string $text   Message.
	 * @param array  $fields Field errors.
	 * @param array  $old    Submitted values to show again in the form.
	 */
	public static function flash( string $type, string $text, array $fields = array(), array $old = array() ): void {
		set_transient(
			'zzzc_flash_' . get_current_user_id(),
			array(
				'type'   => $type,
				'text'   => $text,
				'fields' => $fields,
				'old'    => $old,
			),
			120
		);
	}

	private static function post( string $key, $fallback = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce checked in dispatch(); each caller sanitizes.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
	}

	/* ---- Automation controls ---- */

	private static function do_start(): void {
		$result = Automation::start();
		if ( true === $result ) {
			self::flash( 'success', 'Automation is starting. It will show Running after the first worker run. It keeps going after you close this page or log out.' );
		} else {
			self::flash( 'error', 'Automation did not start. Fix the following: ' . implode( ' ', $result ) );
		}
		self::redirect( 'zzzc-dashboard' );
	}

	private static function do_stop(): void {
		Automation::stop();
		self::flash( 'success', 'Automation stopped. Future jobs are cancelled. Queued articles and history were kept.' );
		self::redirect( 'zzzc-dashboard' );
	}

	private static function do_pause_research(): void {
		Automation::pause_research();
		self::flash( 'success', 'Research paused. Queued articles can still be published.' );
		self::redirect( 'zzzc-dashboard' );
	}

	private static function do_pause_publishing(): void {
		Automation::pause_publishing();
		self::flash( 'success', 'Publishing paused. Research and article writing can continue to fill the queue.' );
		self::redirect( 'zzzc-dashboard' );
	}

	private static function do_resume(): void {
		$scope = sanitize_key( (string) self::post( 'scope', 'all' ) );
		$scope = in_array( $scope, array( 'all', 'research', 'publishing' ), true ) ? $scope : 'all';
		Automation::resume( $scope );
		self::flash( 'success', 'Resumed.' );
		self::redirect( 'zzzc-dashboard' );
	}

	/* ---- News sources ---- */

	private static function do_save_category(): void {
		$id     = absint( self::post( 'id', 0 ) );
		$input  = (array) self::post( 'category', array() );
		$result = Categories::sanitize_input( $input );

		if ( $result['errors'] ) {
			self::flash( 'error', 'The category was not saved. Check the marked fields.', $result['errors'], $input );
			self::redirect( 'zzzc-sources', array( 'edit' => $id ?: 'new' ) );
		}

		if ( $id ) {
			Categories::update( $id, $result['data'] );
			Events::log( 'config', 'info', sprintf( 'Category "%s" was changed.', $result['data']['name'] ), array( 'category_id' => $id ) );
		} else {
			$result['data']['sort_order'] = Categories::next_sort_order();
			$id                           = Categories::insert( $result['data'] );
			Events::log( 'config', 'info', sprintf( 'Category "%s" was added.', $result['data']['name'] ), array( 'category_id' => $id ) );
		}

		Schedule::rebuild_future();

		$issues = Categories::readiness( (array) Categories::get( $id ) );
		if ( $issues ) {
			$fixes = array();
			if ( in_array( 'No source', $issues, true ) ) {
				$fixes[] = 'Edit it to add a topic address, search query or publisher feed.';
			}
			if ( in_array( 'No WordPress category', $issues, true ) ) {
				$fixes[] = 'Map a WordPress category on the Publishing page, or allow automatic creation there.';
			}
			self::flash( 'warning', 'Category saved, but it will not be used yet. ' . implode( ' ', $fixes ) );
		} else {
			self::flash( 'success', 'Category saved.' );
		}
		self::redirect( 'zzzc-sources' );
	}

	private static function do_delete_category(): void {
		$id   = absint( self::post( 'id', 0 ) );
		$name = Categories::name( $id );
		Categories::soft_delete( $id );
		Schedule::rebuild_future();
		Events::log( 'config', 'info', sprintf( 'Category "%s" was deleted. Past articles keep their records.', $name ), array( 'category_id' => $id ) );
		self::flash( 'success', 'Category deleted.' );
		self::redirect( 'zzzc-sources' );
	}

	private static function do_duplicate_category(): void {
		$id  = absint( self::post( 'id', 0 ) );
		$new = Categories::duplicate( $id );
		Events::log( 'config', 'info', 'A category was duplicated.', array( 'category_id' => $new ) );
		self::flash( 'success', 'Category duplicated. The copy starts disabled.' );
		self::redirect( 'zzzc-sources' );
	}

	private static function do_move_category(): void {
		$direction = 'up' === self::post( 'direction' ) ? -1 : 1;
		Categories::move( absint( self::post( 'id', 0 ) ), $direction );
		Events::log( 'config', 'info', 'Category order was changed.' );
		self::redirect( 'zzzc-sources' );
	}

	private static function do_toggle_category(): void {
		$id  = absint( self::post( 'id', 0 ) );
		$row = Categories::get( $id );
		if ( $row ) {
			$enabled = (int) $row['enabled'] ? 0 : 1;
			Categories::update( $id, array( 'enabled' => $enabled ) );
			Schedule::rebuild_future();
			Events::log( 'config', 'info', sprintf( 'Category "%1$s" was %2$s.', $row['name'], $enabled ? 'enabled' : 'disabled' ), array( 'category_id' => $id ) );
		}
		self::redirect( 'zzzc-sources' );
	}

	private static function do_save_source_rules(): void {
		$values = array( 'google_rss_enabled' => self::post( 'google_rss_enabled', 0 ) );
		foreach ( array( 'min_source_words', 'partial_min_words', 'max_source_chars', 'http_timeout', 'host_gap_seconds', 'google_gap_seconds', 'fetch_max_retries', 'max_items_per_fetch', 'headline_similarity', 'content_similarity', 'resolver_similarity', 'dedupe_window_days' ) as $key ) {
			$values[ $key ] = self::post( $key );
		}
		self::save_settings( $values, 'zzzc-sources' );
	}

	/* ---- AI settings ---- */

	private static function do_save_key(): void {
		$key = trim( (string) self::post( 'api_key' ) );
		if ( '' === $key ) {
			self::flash( 'warning', 'No key was entered, so the saved key was left unchanged.' );
			self::redirect( 'zzzc-ai' );
		}
		Secrets::set_api_key( $key );
		Automation::clear_ai_block();
		Events::log( 'config', 'info', 'The OpenRouter API key was replaced.' );
		self::flash( 'success', 'API key saved. Use Test API Connection to check it.' );
		self::redirect( 'zzzc-ai' );
	}

	private static function do_clear_key(): void {
		Secrets::clear_api_key();
		Events::log( 'config', 'info', 'The OpenRouter API key was removed.' );
		self::flash( 'success', 'API key removed.' );
		self::redirect( 'zzzc-ai' );
	}

	private static function do_save_ai(): void {
		$user = get_current_user_id();
		$errs = array();

		// Prompt text is saved exactly as submitted. It is deliberately not sanitized.
		foreach ( array( 'article_prompt' => Prompts::ARTICLE, 'tag_prompt' => Prompts::TAG, 'system_prompt' => Prompts::SYSTEM ) as $field => $type ) {
			$before = Prompts::active_id( $type );
			$saved  = Prompts::save( $type, (string) self::post( $field ), $user );
			if ( is_wp_error( $saved ) ) {
				$errs[ $field ] = $saved->get_error_message();
			} elseif ( $saved !== $before ) {
				Events::log( 'config', 'info', sprintf( 'The %1$s prompt was changed. New version %2$d.', $type, $saved ), array( 'context' => array( 'prompt_version' => $saved ) ) );
			}
		}

		$old_model = (string) Settings::get( 'model_id' );
		$values    = array();
		foreach ( array( 'model_id', 'ai_timeout', 'ai_max_retries', 'max_output_tokens', 'tag_policy', 'emdash_policy', 'min_article_words' ) as $key ) {
			$values[ $key ] = self::post( $key );
		}
		$result = Settings::update( $values );

		if ( in_array( 'model_id', $result['changed'], true ) ) {
			Events::log( 'config', 'info', sprintf( 'The model was changed from %1$s to %2$s.', $old_model, Settings::get( 'model_id' ) ) );
			Automation::clear_ai_block();
		}
		if ( $result['changed'] ) {
			Events::log( 'config', 'info', 'AI settings changed: ' . implode( ', ', $result['changed'] ) );
		}

		$errs = array_merge( $errs, $result['errors'] );
		if ( $errs ) {
			self::flash( 'error', 'Some values were not saved. Check the marked fields.', $errs );
		} else {
			self::flash( 'success', 'AI settings saved.' );
		}
		self::redirect( 'zzzc-ai' );
	}

	private static function do_restore_prompt(): void {
		$type    = sanitize_key( (string) self::post( 'prompt_type' ) );
		$version = absint( self::post( 'version', 0 ) );
		if ( Prompts::restore( $type, $version ) ) {
			Events::log( 'config', 'info', sprintf( 'The %1$s prompt was restored to version %2$d.', $type, $version ) );
			self::flash( 'success', sprintf( 'Prompt version %d is active again.', $version ) );
		} else {
			self::flash( 'error', 'That prompt version could not be restored.' );
		}
		self::redirect( 'zzzc-ai' );
	}

	/* ---- Schedule ---- */

	private static function do_save_schedule(): void {
		$values = array(
			'past_hour_enabled' => self::post( 'past_hour_enabled', 0 ),
			'no_repeat'         => self::post( 'no_repeat', 0 ),
			'category_fallback' => self::post( 'category_fallback', 0 ),
			'rotation_pool'     => (array) self::post( 'rotation_pool', array() ),
			'slot_categories'   => (array) self::post( 'slot_categories', array() ),
		);
		foreach ( array( 'past_hour_prep_minutes', 'past_hour_max_age_minutes', 'daily_target', 'interval_hours', 'first_time', 'timezone', 'daily_cap', 'cap_scope', 'catchup', 'slot_grace_minutes', 'catchup_gap_minutes', 'rotation_mode', 'discovery_interval_minutes', 'category_buffer', 'daily_ai_limit', 'research_lead_hours', 'max_article_age_hours', 'run_budget_seconds' ) as $key ) {
			$values[ $key ] = self::post( $key );
		}
		$result = Settings::update( $values );
		if ( $result['changed'] ) {
			Schedule::rebuild_future();
			Events::log( 'config', 'info', 'Schedule settings changed: ' . implode( ', ', $result['changed'] ) );
		}
		self::finish_settings( $result, 'zzzc-schedule' );
	}

	private static function do_save_auto_stop(): void {
		$result = Settings::update(
			array(
				'auto_stop_days'  => self::post( 'auto_stop_days' ),
				'auto_stop_posts' => self::post( 'auto_stop_posts' ),
			)
		);
		if ( $result['changed'] ) {
			Events::log( 'config', 'info', 'Automatic stop changed: ' . AutoStop::summary() );
		}
		$reason = AutoStop::reason();
		if ( ! $result['errors'] && Automation::is_active() && '' !== $reason ) {
			self::flash( 'warning', 'Saved. This limit is already reached, so automation stops on the next worker run, within about a minute: ' . $reason );
			self::redirect( 'zzzc-schedule' );
		}
		self::finish_settings( $result, 'zzzc-schedule' );
	}

	private static function do_regenerate_token(): void {
		RestTick::regenerate();
		Events::log( 'config', 'info', 'The server cron token was replaced.' );
		self::flash( 'success', 'New token created. Update your server cron job with it.' );
		self::redirect( 'zzzc-schedule' );
	}

	/* ---- Publishing ---- */

	private static function do_save_publishing(): void {
		$values = array(
			'category_auto_create'     => self::post( 'category_auto_create', 0 ),
			'delete_data_on_uninstall' => self::post( 'delete_data_on_uninstall', 0 ),
		);
		foreach ( array( 'publish_mode', 'tag_mode', 'attribution', 'post_author', 'retention_source_days', 'retention_events_days', 'retention_failed_days' ) as $key ) {
			$values[ $key ] = self::post( $key );
		}

		foreach ( (array) self::post( 'map', array() ) as $category_id => $row ) {
			$category_id = absint( $category_id );
			if ( ! $category_id || ! is_array( $row ) ) {
				continue;
			}
			$mode = (string) ( $row['publish_mode'] ?? '' );
			Categories::update(
				$category_id,
				array(
					'wp_term_id'   => absint( $row['wp_term_id'] ?? 0 ),
					'publish_mode' => in_array( $mode, array( 'draft', 'publish' ), true ) ? $mode : '',
				)
			);
		}

		$result = Settings::update( $values );
		Events::log( 'config', 'info', 'Publishing settings saved. Publish mode: ' . Settings::get( 'publish_mode' ) . '. Changed: ' . ( implode( ', ', $result['changed'] ) ?: 'category mapping only' ) );
		self::finish_settings( $result, 'zzzc-publishing' );
	}

	/* ---- Articles ---- */

	private static function do_save_images(): void {
		$values = array(
			'image_allow_share_alike' => self::post( 'image_allow_share_alike', 0 ),
		);
		foreach ( array( 'image_query_count', 'image_candidates_per_query', 'image_min_width', 'image_min_height', 'image_preferred_ratio', 'image_min_score', 'image_max_file_mb', 'image_max_attempts', 'image_api_gap_seconds', 'image_attribution' ) as $key ) {
			$values[ $key ] = self::post( $key );
		}
		self::save_settings( $values, 'zzzc-images' );
	}

	private static function do_release_image(): void {
		if ( ImageHistory::release_by_admin( absint( self::post( 'id', 0 ) ) ) ) {
			self::flash( 'warning', 'The image reservation was released and written to the log. A future article can now use this image.' );
		} else {
			self::flash( 'error', 'This image reservation could not be released.' );
		}
		self::redirect( 'zzzc-images' );
	}

	private static function do_retry_image(): void {
		$id     = absint( self::post( 'id', 0 ) );
		$result = ImagePipeline::retry_for_article( $id );
		if ( true === $result ) {
			self::flash( 'success', 'The featured image was added to the post.' );
		} else {
			self::flash( 'error', 'No image was added: ' . $result );
		}
		self::redirect( 'zzzc-articles', array( 'view' => $id ) );
	}

	private static function do_retry_article(): void {
		$id     = absint( self::post( 'id', 0 ) );
		$result = Articles::retry( $id );
		if ( true === $result ) {
			self::flash( 'success', 'The article will be processed again on the next worker run.' );
		} else {
			self::flash( 'error', $result );
		}
		self::redirect( 'zzzc-articles', array( 'status' => sanitize_text_field( (string) self::post( 'return_status', '' ) ) ) );
	}

	private static function do_skip_article(): void {
		$id     = absint( self::post( 'id', 0 ) );
		$result = Articles::skip( $id );
		if ( true === $result ) {
			self::flash( 'success', 'The article was skipped and will not be published.' );
		} else {
			self::flash( 'error', $result );
		}
		self::redirect( 'zzzc-articles', array( 'status' => sanitize_text_field( (string) self::post( 'return_status', '' ) ) ) );
	}

	/* ---- Authors ---- */

	/**
	 * Grants, pauses or revokes access for one user (a row button) or the selected users (bulk).
	 * The capability and nonce are checked in dispatch().
	 */
	private static function do_author_access(): void {
		$map    = array(
			'grant'  => Authors::ACTIVE,
			'pause'  => Authors::PAUSED,
			'revoke' => Authors::REVOKED,
		);
		$single = (string) self::post( 'single', '' );
		if ( '' !== $single ) {
			$parts  = explode( ':', $single, 2 );
			$action = (string) $parts[0];
			$ids    = array( absint( $parts[1] ?? 0 ) );
		} else {
			$action = (string) self::post( 'bulk_action', '' );
			$ids    = array_map( 'absint', (array) self::post( 'user_ids', array() ) );
		}
		$ids = array_values( array_filter( $ids ) );

		$return = array();
		foreach ( array( 's', 'role', 'access', 'paged' ) as $key ) {
			$value = sanitize_text_field( (string) self::post( 'return_' . $key, '' ) );
			if ( '' !== $value ) {
				$return[ $key ] = $value;
			}
		}

		if ( ! isset( $map[ $action ] ) ) {
			self::flash( 'error', 'Choose Grant access, Pause or Revoke access.' );
			self::redirect( 'zzzc-authors', $return );
			return;
		}
		if ( ! $ids ) {
			self::flash( 'error', 'Select at least one user.' );
			self::redirect( 'zzzc-authors', $return );
			return;
		}

		$result = Authors::change( $ids, $map[ $action ] );
		$count  = count( $result['changed'] );
		$text   = $count ? sprintf( '%1$d %2$s changed.', $count, 1 === $count ? 'user' : 'users' ) : 'Nothing changed.';
		if ( $result['errors'] ) {
			self::flash( $count ? 'warning' : 'error', $text . ' ' . implode( ' ', $result['errors'] ) );
		} else {
			self::flash( 'success', $text );
		}
		self::redirect( 'zzzc-authors', $return );
	}

	private static function do_save_author_mode(): void {
		$mode    = sanitize_key( (string) self::post( 'author_mode', '' ) );
		$before  = (string) Settings::get( 'author_mode' );
		$problem = AuthorSchedules::switch_mode( $mode );
		if ( '' !== $problem ) {
			self::flash( 'error', 'The posting mode was not changed. ' . $problem, array( 'author_mode' => $problem ) );
		} elseif ( $before === $mode ) {
			self::flash( 'success', 'Nothing changed.' );
		} elseif ( AuthorSchedules::MULTIPLE === $mode ) {
			$warnings = AuthorSchedules::capacity_warnings();
			self::flash( $warnings ? 'warning' : 'success', trim( 'Multiple users mode is on. Each active author posts at their own times, and the Post author setting is not used. ' . implode( ' ', $warnings ) ) );
		} else {
			self::flash( 'success', 'Single user mode is on. Every post is credited to the Post author, at the times on the Schedule page.' );
		}
		self::redirect( 'zzzc-authors' );
	}

	private static function do_save_author_schedule(): void {
		$user_id = absint( self::post( 'user_id', 0 ) );
		$input   = array(
			'enabled'     => '1' === (string) self::post( 'enabled', '' ) ? '1' : '',
			'timezone'    => sanitize_text_field( (string) self::post( 'timezone', '' ) ),
			'days'        => array_map( 'sanitize_key', (array) self::post( 'days', array() ) ),
			'times'       => sanitize_textarea_field( (string) self::post( 'times', '' ) ),
			'per_time'    => sanitize_text_field( (string) self::post( 'per_time', '1' ) ),
			'categories'  => array_map( 'absint', (array) self::post( 'categories', array() ) ),
			'from'        => sanitize_text_field( (string) self::post( 'from', '' ) ),
			'until'       => sanitize_text_field( (string) self::post( 'until', '' ) ),
			'skip_dates'  => sanitize_textarea_field( (string) self::post( 'skip_dates', '' ) ),
			'limit_day'   => sanitize_text_field( (string) self::post( 'limit_day', '0' ) ),
			'limit_week'  => sanitize_text_field( (string) self::post( 'limit_week', '0' ) ),
			'limit_month' => sanitize_text_field( (string) self::post( 'limit_month', '0' ) ),
			'missed_rule' => sanitize_key( (string) self::post( 'missed_rule', 'next' ) ),
		);

		$result = AuthorSchedules::save( $user_id, $input );
		if ( $result['errors'] ) {
			self::flash( 'error', 'The schedule was not saved. Fix the fields marked below.' . ( isset( $result['errors']['user'] ) ? ' ' . $result['errors']['user'] : '' ), $result['errors'], $input );
			self::redirect( 'zzzc-authors', $user_id ? array( 'schedule' => $user_id ) : array() );
			return;
		}

		$text = 'Schedule saved for ' . Authors::user_label( $user_id ) . '.';
		if ( ! AuthorSchedules::multiple() ) {
			$text .= ' It is used once Multiple users mode is switched on.';
		}
		if ( $result['warnings'] ) {
			self::flash( 'warning', $text . ' ' . implode( ' ', $result['warnings'] ) );
		} else {
			self::flash( 'success', $text );
		}
		self::redirect( 'zzzc-authors', array( 'schedule' => $user_id ) );
	}

	/* ---- Logs ---- */

	private static function do_export_logs(): void {
		$filters = Pages::log_filters( (array) self::post( 'filters', array() ) );
		$rows    = Events::query( $filters, 20000, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::export_filename() . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at_utc', 'stage', 'severity', 'status', 'error_code', 'message', 'category_id', 'source_item_id', 'article_job_id', 'wp_post_id', 'actor_user_id', 'author_user_id', 'attempt' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array( $row['id'], $row['created_at'], $row['stage'], $row['severity'], $row['status'], $row['error_code'], $row['message'], $row['category_id'], $row['source_item_id'], $row['article_job_id'], $row['wp_post_id'], $row['actor_user_id'], $row['author_user_id'], $row['attempt'] )
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The name the log export downloads under, with the UTC time it was made.
	 */
	public static function export_filename(): string {
		return 'inkrush-logs-' . gmdate( 'Ymd-His' ) . '.csv';
	}

	/**
	 * Stops spreadsheet programs from treating a logged value as a formula.
	 */
	public static function csv_safe( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/* ---- Helpers ---- */

	private static function save_settings( array $values, string $page ): void {
		$result = Settings::update( $values );
		if ( $result['changed'] ) {
			Events::log( 'config', 'info', 'Settings changed: ' . implode( ', ', $result['changed'] ) );
		}
		self::finish_settings( $result, $page );
	}

	private static function finish_settings( array $result, string $page ): void {
		if ( $result['errors'] ) {
			self::flash( 'error', 'Some values were not saved. Check the marked fields.', $result['errors'] );
		} else {
			self::flash( 'success', 'Settings saved.' );
		}
		self::redirect( $page );
	}
}
