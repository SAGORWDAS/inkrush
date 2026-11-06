<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent automation state and the Start, Stop, Pause and Resume controls.
 */
final class Automation {

	public const NOT_CONFIGURED = 'NOT_CONFIGURED';
	public const STOPPED        = 'STOPPED';
	public const STARTING       = 'STARTING';
	public const RUNNING        = 'RUNNING';
	public const PAUSED         = 'PAUSED';
	public const ERROR          = 'ERROR';

	private const OPTION = 'zzzc_state';

	/**
	 * Reads directly from the database so a long-running worker sees a Stop at once,
	 * even with a persistent object cache.
	 */
	public static function state(): array {
		global $wpdb;
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );
		$value = $raw ? maybe_unserialize( $raw ) : array();
		return array_merge(
			array(
				'run_state'          => self::STOPPED,
				'paused_research'    => 0,
				'paused_publishing'  => 0,
				'ai_block_code'      => '',
				'ai_block_message'   => '',
				'last_discovery_at'  => 0,
				'rotation_pointer'   => 0,
				'started_at'         => 0,
				'auto_stop_reason'   => '',
				'auto_stopped_at'    => 0,
			),
			is_array( $value ) ? $value : array()
		);
	}

	public static function get( string $key, $fallback = null ) {
		$state = self::state();
		return $state[ $key ] ?? $fallback;
	}

	public static function set( string $key, $value ): void {
		self::patch( array( $key => $value ) );
	}

	public static function patch( array $values ): void {
		$state = array_merge( self::state(), $values );
		update_option( self::OPTION, $state, false );
		wp_cache_delete( self::OPTION, 'options' );
	}

	public static function is_active(): bool {
		return in_array( self::get( 'run_state' ), array( self::STARTING, self::RUNNING ), true );
	}

	/**
	 * State shown to the administrator.
	 */
	public static function display_state(): string {
		$state = self::state();
		if ( ! in_array( $state['run_state'], array( self::STARTING, self::RUNNING ), true ) ) {
			return self::config_problems() ? self::NOT_CONFIGURED : self::STOPPED;
		}
		if ( '' !== $state['ai_block_code'] ) {
			return self::ERROR;
		}
		if ( self::STARTING === $state['run_state'] ) {
			return self::STARTING;
		}
		if ( $state['paused_research'] || $state['paused_publishing'] ) {
			return self::PAUSED;
		}
		return self::RUNNING;
	}

	/**
	 * Required configuration that is missing or invalid. Empty when ready.
	 *
	 * @return string[] Actionable messages.
	 */
	public static function config_problems(): array {
		$problems = array();

		if ( ! Secrets::has_api_key() ) {
			$problems[] = 'Add your OpenRouter API key on the AI Settings page.';
		} elseif ( '' === Secrets::get_api_key() ) {
			$problems[] = 'The saved OpenRouter API key cannot be decrypted, usually because the WordPress salts or ZZZCOPY_SECRET_KEY changed. Enter the key again on the AI Settings page.';
		}
		if ( '' === (string) Settings::get( 'model_id' ) ) {
			$problems[] = 'Enter an OpenRouter model identifier on the AI Settings page.';
		}
		if ( '' === trim( Prompts::active_text( Prompts::ARTICLE ) ) ) {
			$problems[] = 'Enter the article prompt on the AI Settings page.';
		}
		if ( '' === trim( Prompts::active_text( Prompts::TAG ) ) ) {
			$problems[] = 'Enter the tag prompt on the AI Settings page.';
		}
		if ( ! Settings::is_valid_timezone( (string) Settings::get( 'timezone' ) ) ) {
			$problems[] = 'Enter a valid timezone on the Schedule page.';
		}
		$author = Authors::publishing_problem();
		if ( '' !== $author ) {
			$problems[] = $author;
		}

		$categories = Categories::enabled();
		if ( ! $categories ) {
			$problems[] = 'Enable at least one category on the News Sources page.';
		}
		$no_source = array();
		$no_term   = array();
		foreach ( $categories as $category ) {
			if ( ! Discovery::has_usable_source( $category ) ) {
				$no_source[] = $category['name'];
			}
			if ( ! Categories::resolve_term_readonly( $category ) ) {
				$no_term[] = $category['name'];
			}
		}
		if ( $no_source ) {
			$problems[] = sprintf( 'These categories have no usable source: %s. Add a topic address, search query or publisher feed, or disable them.', implode( ', ', $no_source ) );
		}
		if ( $no_term ) {
			$problems[] = sprintf( 'These categories have no matching WordPress category: %s. On the Publishing page, tick "Create a WordPress category when none matches", or map each one.', implode( ', ', $no_term ) );
		}

		return $problems;
	}

	/**
	 * Validates configuration and starts background processing.
	 *
	 * @return true|string[] True on success, otherwise the problems found.
	 */
	public static function start( bool $verify_model = true ) {
		$problems = self::config_problems();
		if ( $problems ) {
			return $problems;
		}

		if ( $verify_model ) {
			$check = OpenRouter::verify_setup();
			if ( ! $check['ok'] ) {
				return array( $check['message'] );
			}
		}

		self::patch(
			array(
				'run_state'        => self::STARTING,
				'ai_block_code'    => '',
				'ai_block_message' => '',
				'started_at'       => Clock::now(),
			)
		);

		wp_clear_scheduled_hook( Plugin::TICK_HOOK );
		wp_schedule_event( time() + 5, 'zzzc_minute', Plugin::TICK_HOOK );

		Schedule::ensure_slots();

		Events::log( 'control', 'info', 'Automation started.', array( 'status' => self::STARTING ) );
		return true;
	}

	/**
	 * Cancels future recurring work. A job already running finishes its current step.
	 * Queue items and article records stay in place.
	 */
	public static function stop(): void {
		self::patch( array( 'run_state' => self::STOPPED ) );
		wp_clear_scheduled_hook( Plugin::TICK_HOOK );
		Events::log( 'control', 'info', 'Automation stopped.', array( 'status' => self::STOPPED ) );
	}

	/**
	 * Stops like the Stop button, because a limit set on the Schedule page was reached.
	 */
	public static function stop_automatically( string $reason ): void {
		self::patch(
			array(
				'run_state'        => self::STOPPED,
				'auto_stop_reason' => $reason,
				'auto_stopped_at'  => Clock::now(),
			)
		);
		wp_clear_scheduled_hook( Plugin::TICK_HOOK );
		Events::log( 'control', 'info', 'Automation stopped automatically: ' . $reason . ' Queued articles and history were kept.', array( 'status' => self::STOPPED ) );
	}

	/**
	 * Why the current stop happened by itself, or an empty string when it did not.
	 */
	public static function automatic_stop_reason(): string {
		$state = self::state();
		if ( self::STOPPED !== $state['run_state'] || ! $state['auto_stopped_at'] || (int) $state['auto_stopped_at'] < (int) $state['started_at'] ) {
			return '';
		}
		return (string) $state['auto_stop_reason'];
	}

	public static function pause_research(): void {
		self::patch( array( 'paused_research' => 1 ) );
		Events::log( 'control', 'info', 'Research paused. Discovery and source retrieval will not run.', array( 'status' => self::PAUSED ) );
	}

	public static function pause_publishing(): void {
		self::patch( array( 'paused_publishing' => 1 ) );
		Events::log( 'control', 'info', 'Publishing paused. No WordPress posts will be created.', array( 'status' => self::PAUSED ) );
	}

	/**
	 * @param string $scope all, research or publishing.
	 */
	public static function resume( string $scope = 'all' ): void {
		$patch = array();
		if ( 'all' === $scope || 'research' === $scope ) {
			$patch['paused_research'] = 0;
		}
		if ( 'all' === $scope || 'publishing' === $scope ) {
			$patch['paused_publishing'] = 0;
		}
		if ( 'all' === $scope ) {
			$patch['ai_block_code']    = '';
			$patch['ai_block_message'] = '';
		}
		self::patch( $patch );
		Events::log( 'control', 'info', sprintf( 'Resumed (%s).', $scope ), array( 'status' => self::RUNNING ) );
	}

	/**
	 * Stops AI calls after a problem retrying cannot fix, such as a bad key or no credits.
	 */
	public static function block_ai( string $code, string $message ): void {
		self::patch(
			array(
				'ai_block_code'    => $code,
				'ai_block_message' => $message,
			)
		);
		Events::log(
			'ai',
			'error',
			'AI jobs paused: ' . $message,
			array(
				'error_code' => $code,
				'status'     => self::ERROR,
			)
		);
	}

	public static function clear_ai_block(): void {
		if ( '' !== self::get( 'ai_block_code', '' ) ) {
			self::patch(
				array(
					'ai_block_code'    => '',
					'ai_block_message' => '',
				)
			);
			Events::log( 'ai', 'info', 'AI jobs can run again.' );
		}
	}

	public static function on_plugin_deactivated(): void {
		if ( self::is_active() ) {
			self::patch( array( 'run_state' => self::STOPPED ) );
			Events::log( 'control', 'info', 'Plugin deactivated. Scheduled work stopped. Posts and history were kept.', array( 'status' => self::STOPPED ) );
		}
	}
}
