<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Database tables, versioned migrations and first-run seeds.
 */
final class Schema {

	public const DB_VERSION = '11';

	public static function maybe_upgrade(): void {
		if ( get_option( 'zzzc_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		$previous = (string) get_option( 'zzzc_db_version', '' );

		self::create_tables();
		Settings::ensure_defaults();
		Prompts::seed_defaults();
		Categories::seed_defaults();

		if ( '' !== $previous && version_compare( $previous, '2', '<' ) ) {
			self::upgrade_to_2();
		}
		if ( '' !== $previous && version_compare( $previous, '3', '<' ) ) {
			self::upgrade_to_3();
		}
		if ( '' !== $previous && version_compare( $previous, '5', '<' ) ) {
			self::upgrade_to_5();
		}
		if ( '' !== $previous && version_compare( $previous, '6', '<' ) ) {
			self::upgrade_to_6();
		}
		if ( '' !== $previous && version_compare( $previous, '7', '<' ) ) {
			self::upgrade_to_7();
		}
		if ( '' !== $previous && version_compare( $previous, '8', '<' ) ) {
			self::upgrade_to_8();
		}
		if ( '' !== $previous && version_compare( $previous, '9', '<' ) ) {
			self::upgrade_to_9();
		}
		if ( '' !== $previous && version_compare( $previous, '10', '<' ) ) {
			self::upgrade_to_10();
		}
		if ( '' !== $previous && version_compare( $previous, '11', '<' ) ) {
			self::upgrade_to_11();
		}

		// Autoloaded, because this one small value is read on every request.
		update_option( 'zzzc_db_version', self::DB_VERSION, true );
	}

	/**
	 * Version 2: the owner's full category list, and one ready article per category.
	 * With many categories, a spare article would go stale before its category came round again.
	 */
	private static function upgrade_to_2(): void {
		$added = Categories::add_missing_seeds();
		if ( 2 === (int) Settings::get( 'category_buffer' ) ) {
			Settings::update( array( 'category_buffer' => 1 ) );
		}
		Events::log( 'config', 'info', sprintf( 'Upgraded plugin data. %d starting categories added. Existing categories were not changed.', $added ) );
	}

	/**
	 * Version 3: the owner chose Automatic Publish and automatic WordPress categories as the setup.
	 * Automation is left in whatever state it was in, and a per-category mode is not changed.
	 */
	private static function upgrade_to_3(): void {
		Settings::update(
			array(
				'publish_mode'         => 'publish',
				'category_auto_create' => 1,
			)
		);
		Events::log( 'config', 'info', 'Upgraded plugin data. Publishing mode is now Automatic Publish, and missing WordPress categories are created automatically.' );
	}

	/**
	 * Version 5: posts that already have an image get a copy of its credit in their text.
	 */
	private static function upgrade_to_5(): void {
		$checked = Images\Attribution::save_all();
		if ( $checked > 0 ) {
			Events::log( 'config', 'info', sprintf( 'Upgraded plugin data. Image credits were saved into the text of %d posts.', $checked ) );
		}
	}

	/**
	 * Version 6: Past-Hour Posting. New columns come from create_tables, and the new default turns it on.
	 */
	private static function upgrade_to_6(): void {
		Events::log( 'config', 'info', 'Upgraded plugin data. Past-Hour Posting is now on. Turn it off on the Schedule page to keep the earlier workflow. Articles already queued wait in the queue and are not used while it is on.' );
	}

	/**
	 * Version 7: starting categories with only a Google topic address get a Google search too,
	 * because topic feeds rarely have news under an hour old.
	 */
	private static function upgrade_to_7(): void {
		$changed = Categories::add_missing_searches();
		if ( $changed ) {
			Events::log( 'config', 'info', sprintf( 'Upgraded plugin data. A Google News search was added to: %s. You can edit or remove it on the News Sources page.', implode( ', ', $changed ) ) );
		}
	}

	/**
	 * Version 8: every post needs a featured image, so the settings that allowed a post without one are removed.
	 */
	private static function upgrade_to_8(): void {
		$stored = get_option( 'zzzc_settings', array() );
		if ( is_array( $stored ) ) {
			unset( $stored['images_enabled'], $stored['image_assign'], $stored['image_fallback'] );
			update_option( 'zzzc_settings', $stored, false );
			Settings::flush_cache();
		}
		Events::log( 'config', 'info', 'Upgraded plugin data. Every post now needs a featured image. An article with no usable image is held and not published. Posts already published are not changed.' );
	}

	/**
	 * Version 9: Past-Hour Posting uses stories up to 120 minutes old. A preparation time still on
	 * the old default of 60 minutes moves to the new default of 120. One the owner changed is kept.
	 */
	private static function upgrade_to_9(): void {
		$stored = get_option( 'zzzc_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$prep   = (int) ( $stored['past_hour_prep_minutes'] ?? 60 );
		if ( 60 === $prep ) {
			$prep = 120;
		}
		$stored['past_hour_prep_minutes']    = $prep;
		$stored['past_hour_max_age_minutes'] = 120;
		update_option( 'zzzc_settings', $stored, false );
		Settings::flush_cache();
		Events::log( 'config', 'info', sprintf( 'Upgraded plugin data. Past-Hour Posting now uses stories up to 120 minutes old, and preparation starts %d minutes before each post. Both can be changed on the Schedule page.', $prep ) );
	}

	/**
	 * Version 10: only authorised authors are credited with posts. The user already credited, the
	 * chosen Post author or else the first administrator, becomes the one active author, so posting
	 * carries on. Nobody else is given access.
	 */
	private static function upgrade_to_10(): void {
		$user_id = (int) Settings::get( 'post_author' );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			$admins  = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'fields'  => 'ID',
				)
			);
			$user_id = $admins ? (int) $admins[0] : 0;
		}
		if ( ! $user_id ) {
			Events::log( 'config', 'warning', 'Upgraded plugin data. Only authorised authors are now credited with posts, and no user could be found to authorise. Grant access on the Authors page and choose a Post author on the Publishing page.' );
			return;
		}
		Authors::insert_active( $user_id );
		$stored = get_option( 'zzzc_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['post_author'] = $user_id;
		update_option( 'zzzc_settings', $stored, false );
		Settings::flush_cache();
		Events::log( 'config', 'info', sprintf( 'Upgraded plugin data. Only authorised authors are now credited with posts. %s, who was already credited, is now the one authorised author and the Post author. Nobody else was given access. Manage authors on the Authors page.', Authors::user_label( $user_id ) ), array( 'author_user_id' => $user_id ) );
	}

	/**
	 * Version 11: each author can have their own schedule, used only in Multiple users mode.
	 * New columns come from create_tables. The mode stays Single user, so nothing changes until the owner switches.
	 */
	private static function upgrade_to_11(): void {
		Events::log( 'config', 'info', 'Upgraded plugin data. Authors can now have their own schedules in Multiple users mode, on the Authors page. Posting stays in Single user mode, exactly as before, until you switch.' );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'zzzc_';

		$tables = array();

		$tables[] = "CREATE TABLE {$p}categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  weight int(11) NOT NULL DEFAULT 1,
  source_mode varchar(20) NOT NULL DEFAULT 'both',
  topic_urls text NULL,
  search_queries text NULL,
  feed_urls text NULL,
  required_keywords text NULL,
  excluded_keywords text NULL,
  recency varchar(10) NOT NULL DEFAULT '24h',
  custom_from datetime NULL,
  custom_to datetime NULL,
  language varchar(20) NOT NULL DEFAULT 'en-US',
  region varchar(10) NOT NULL DEFAULT 'US',
  active_days varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  wp_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  publish_mode varchar(10) NOT NULL DEFAULT '',
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY enabled_order (enabled,sort_order)
) $charset;";

		$tables[] = "CREATE TABLE {$p}source_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  adapter varchar(30) NOT NULL,
  dedupe_key char(40) NOT NULL,
  story_id varchar(191) NOT NULL DEFAULT '',
  headline text NOT NULL,
  headline_norm varchar(255) NOT NULL DEFAULT '',
  publisher varchar(191) NOT NULL DEFAULT '',
  publisher_url varchar(512) NOT NULL DEFAULT '',
  source_url text NOT NULL,
  resolved_url text NULL,
  resolve_method varchar(40) NOT NULL DEFAULT '',
  canonical_url text NULL,
  url_hash char(40) NOT NULL DEFAULT '',
  canonical_hash char(40) NOT NULL DEFAULT '',
  published_at datetime NULL,
  snippet text NULL,
  discovered_at datetime NOT NULL,
  search_context text NULL,
  effective_filter varchar(255) NOT NULL DEFAULT '',
  status varchar(30) NOT NULL,
  status_reason varchar(255) NOT NULL DEFAULT '',
  duplicate_of bigint(20) unsigned NOT NULL DEFAULT 0,
  retrieval_status varchar(20) NOT NULL DEFAULT '',
  source_text longtext NULL,
  source_author varchar(191) NOT NULL DEFAULT '',
  source_word_count int(11) NOT NULL DEFAULT 0,
  content_fingerprint varchar(16) NOT NULL DEFAULT '',
  retrieved_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY status_discovered (status,discovered_at),
  KEY category_status (category_id,status),
  KEY url_hash (url_hash),
  KEY canonical_hash (canonical_hash),
  KEY discovered_at (discovered_at),
  KEY retrieved_at (retrieved_at)
) $charset;";

		$tables[] = "CREATE TABLE {$p}article_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_uid char(36) NOT NULL,
  source_item_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(30) NOT NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  ai_attempts int(11) NOT NULL DEFAULT 0,
  next_retry_at datetime NULL,
  article_prompt_version bigint(20) unsigned NOT NULL DEFAULT 0,
  tag_prompt_version bigint(20) unsigned NOT NULL DEFAULT 0,
  system_prompt_version bigint(20) unsigned NOT NULL DEFAULT 0,
  model_id varchar(191) NOT NULL DEFAULT '',
  error_code varchar(60) NOT NULL DEFAULT '',
  error_message text NULL,
  raw_response mediumtext NULL,
  request_audit text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_uid (job_uid),
  UNIQUE KEY source_item_id (source_item_id),
  KEY status_updated (status,updated_at),
  KEY category_status (category_id,status),
  KEY slot_id (slot_id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}article_content (
  article_job_id bigint(20) unsigned NOT NULL,
  title text NOT NULL,
  body longtext NOT NULL,
  finish_reason varchar(40) NOT NULL DEFAULT '',
  tags_json text NULL,
  tag_count int(11) NOT NULL DEFAULT 0,
  validation_result longtext NULL,
  word_count int(11) NOT NULL DEFAULT 0,
  readability float NULL,
  generated_at datetime NULL,
  validated_at datetime NULL,
  PRIMARY KEY  (article_job_id),
  KEY generated_at (generated_at),
  KEY validated_at (validated_at)
) $charset;";

		$tables[] = "CREATE TABLE {$p}publishing_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  article_job_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  due_at datetime NULL,
  queue_status varchar(30) NOT NULL,
  slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  retry_at datetime NULL,
  lock_owner varchar(64) NOT NULL DEFAULT '',
  lock_expires datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY article_job_id (article_job_id),
  KEY status_due (queue_status,due_at),
  KEY category_status (category_id,queue_status)
) $charset;";

		$tables[] = "CREATE TABLE {$p}slots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_key varchar(64) NOT NULL,
  local_date date NOT NULL,
  slot_index int(11) NOT NULL,
  due_at datetime NOT NULL,
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'PENDING',
  status_reason varchar(255) NOT NULL DEFAULT '',
  article_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  prep_status varchar(20) NOT NULL DEFAULT '',
  prep_started_at datetime NULL,
  search_round int(11) NOT NULL DEFAULT 0,
  last_search_at datetime NULL,
  fallback_queue text NULL,
  categories_searched text NULL,
  stories_tried int(11) NOT NULL DEFAULT 0,
  selected_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  selected_category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  fallback_used tinyint(1) NOT NULL DEFAULT 0,
  age_at_selection int(11) NULL,
  age_at_publish int(11) NULL,
  image_retry_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slot_key (slot_key),
  KEY status_due (status,due_at),
  KEY local_date (local_date),
  KEY author_date (author_user_id,local_date)
) $charset;";

		$tables[] = "CREATE TABLE {$p}generation_usage (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  article_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  purpose varchar(20) NOT NULL,
  generation_id varchar(191) NOT NULL DEFAULT '',
  request_id varchar(191) NOT NULL DEFAULT '',
  model varchar(191) NOT NULL DEFAULT '',
  success tinyint(1) NOT NULL DEFAULT 0,
  http_status int(11) NOT NULL DEFAULT 0,
  error_code varchar(60) NOT NULL DEFAULT '',
  input_tokens int(11) NULL,
  output_tokens int(11) NULL,
  total_tokens int(11) NULL,
  cost_usd decimal(16,8) NULL,
  cost_source varchar(20) NOT NULL DEFAULT 'unavailable',
  raw_usage text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY purpose_created (purpose,created_at),
  KEY generation_id (generation_id),
  KEY article_job_id (article_job_id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  stage varchar(30) NOT NULL,
  severity varchar(10) NOT NULL,
  status varchar(30) NOT NULL DEFAULT '',
  error_code varchar(60) NOT NULL DEFAULT '',
  message text NOT NULL,
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  article_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempt int(11) NOT NULL DEFAULT 0,
  context text NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY stage_created (stage,created_at),
  KEY severity_created (severity,created_at),
  KEY article_job_id (article_job_id),
  KEY category_id (category_id),
  KEY author_user_id (author_user_id),
  KEY wp_post_id (wp_post_id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}authors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  granted_at datetime NULL,
  changed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  changed_at datetime NOT NULL,
  sched_enabled tinyint(1) NOT NULL DEFAULT 0,
  sched_timezone varchar(64) NOT NULL DEFAULT '',
  sched_days varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  sched_times text NULL,
  sched_per_time int(11) NOT NULL DEFAULT 1,
  sched_categories text NULL,
  sched_from date NULL,
  sched_until date NULL,
  sched_skip_dates text NULL,
  limit_day int(11) NOT NULL DEFAULT 0,
  limit_week int(11) NOT NULL DEFAULT 0,
  limit_month int(11) NOT NULL DEFAULT 0,
  missed_rule varchar(10) NOT NULL DEFAULT 'next',
  sched_changed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  sched_changed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  KEY status (status)
) $charset;";

		$tables[] = "CREATE TABLE {$p}post_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  article_job_id bigint(20) unsigned NOT NULL,
  job_uid char(36) NOT NULL,
  wp_post_id bigint(20) unsigned NOT NULL,
  post_status varchar(20) NOT NULL,
  local_day date NOT NULL,
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY article_job_id (article_job_id),
  KEY author_user_id (author_user_id),
  KEY local_day_status (local_day,post_status),
  KEY wp_post_id (wp_post_id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}prompt_versions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  prompt_type varchar(20) NOT NULL,
  content longtext NOT NULL,
  content_sha256 char(64) NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_created (prompt_type,id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_type varchar(30) NOT NULL,
  unique_key varchar(191) NULL,
  payload text NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  max_attempts int(11) NOT NULL DEFAULT 3,
  run_at datetime NOT NULL,
  lease_owner varchar(64) NOT NULL DEFAULT '',
  lease_until datetime NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_key (unique_key),
  KEY status_run (status,run_at),
  KEY type_status_run (job_type,status,run_at),
  KEY status_lease (status,lease_until)
) $charset;";

		$tables[] = "CREATE TABLE {$p}image_history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  commons_page_id bigint(20) unsigned NOT NULL,
  filename_norm varchar(191) NOT NULL,
  file_url_hash char(40) NOT NULL,
  commons_url text NOT NULL,
  file_url text NOT NULL,
  sha256 char(64) NOT NULL DEFAULT '',
  phash varchar(16) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  article_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  article_title text NULL,
  image_title text NULL,
  creator text NULL,
  creator_url text NULL,
  license varchar(64) NOT NULL DEFAULT '',
  license_version varchar(16) NOT NULL DEFAULT '',
  license_url text NULL,
  source varchar(32) NOT NULL DEFAULT 'wikimedia_commons',
  modifications varchar(191) NOT NULL DEFAULT 'None',
  status varchar(20) NOT NULL DEFAULT 'reserved',
  status_reason varchar(255) NOT NULL DEFAULT '',
  reserved_at datetime NOT NULL,
  downloaded_at datetime NULL,
  assigned_at datetime NULL,
  release_log text NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY commons_page_id (commons_page_id),
  UNIQUE KEY filename_norm (filename_norm),
  UNIQUE KEY file_url_hash (file_url_hash),
  KEY sha256 (sha256),
  KEY status_assigned (status,assigned_at),
  KEY wp_post_id (wp_post_id)
) $charset;";

		$tables[] = "CREATE TABLE {$p}image_runs (
  article_job_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  searches int(11) NOT NULL DEFAULT 0,
  candidates_checked int(11) NOT NULL DEFAULT 0,
  queries text NULL,
  candidates longtext NULL,
  history_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason text NULL,
  auto_retried tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (article_job_id),
  KEY status_updated (status,updated_at)
) $charset;";

		$tables[] = "CREATE TABLE {$p}locks (
  lock_name varchar(64) NOT NULL,
  owner varchar(64) NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (lock_name)
) $charset;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}
}
