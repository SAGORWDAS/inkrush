<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Automation;
use ZZZCopy\Categories;
use ZZZCopy\Discovery;
use ZZZCopy\Events;
use ZZZCopy\Generation;
use ZZZCopy\Images\ArticleAnalyzer;
use ZZZCopy\Images\Commons;
use ZZZCopy\Images\ImagePipeline;
use ZZZCopy\OpenRouter;
use ZZZCopy\Plugin;
use ZZZCopy\Publisher;
use ZZZCopy\Schedule;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Background requests from the admin screens. No response ever includes the API key.
 */
final class Ajax {

	public static function register(): void {
		foreach ( array( 'test_connection', 'test_search', 'test_generation', 'save_test_draft', 'live_status', 'test_commons', 'test_image_search' ) as $action ) {
			add_action( 'wp_ajax_zzzc_' . $action, array( __CLASS__, $action ) );
		}
	}

	public static function test_commons(): void {
		self::guard();
		$check = Commons::test_connection();
		Events::log( 'image', $check['ok'] ? 'info' : 'warning', 'Commons connection test: ' . $check['message'] );
		if ( $check['ok'] ) {
			wp_send_json_success( array( 'message' => $check['message'] ) );
		}
		wp_send_json_error( array( 'message' => $check['message'] ) );
	}

	/**
	 * Shows which files would pass for some text. Nothing is reserved, downloaded or saved.
	 */
	public static function test_image_search(): void {
		self::guard();
		$text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['text'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => 'Enter a headline or some article text first.' ) );
		}
		$category = Categories::get( absint( $_POST['category_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$name     = $category ? (string) $category['name'] : '';
		$lines    = preg_split( '/\R/', trim( $text ) );
		$analysis = ArticleAnalyzer::analyze( (string) $lines[0], $text, array(), (string) $lines[0], $name );

		$items    = array();
		$error    = '';
		$searched = array();
		foreach ( $analysis['queries'] as $query ) {
			$result     = Commons::search( (string) $query );
			$searched[] = (string) $query;
			if ( ! $result['ok'] ) {
				$error = $result['error'];
				if ( $result['retry_after'] > 0 ) {
					break;
				}
				continue;
			}
			foreach ( $result['items'] as $item ) {
				$items[ $item['page_id'] ] = $item;
			}
		}

		$reviewed = ImagePipeline::review( array_values( $items ), $analysis, $name );
		wp_send_json_success(
			array(
				'queries'    => $searched,
				'error'      => $error,
				'checked'    => count( $reviewed['all'] ),
				'passed'     => count( $reviewed['eligible'] ),
				'candidates' => ImagePipeline::summaries( $reviewed['all'] ),
			)
		);
	}

	private static function guard(): void {
		check_ajax_referer( 'zzzc_ajax', 'nonce' );
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to do this.' ), 403 );
		}
	}

	public static function test_connection(): void {
		self::guard();
		if ( ! Secrets::has_api_key() ) {
			wp_send_json_error( array( 'message' => 'Save an API key first.' ) );
		}
		$check = OpenRouter::verify_setup();
		if ( $check['ok'] ) {
			if ( 'network_error' !== $check['code'] ) {
				Automation::clear_ai_block();
			}
			Events::log( 'ai', 'info', 'API connection test passed for model ' . Settings::get( 'model_id' ) . '.' );
			wp_send_json_success( array( 'message' => $check['message'] ) );
		}
		Events::log( 'ai', 'warning', 'API connection test failed: ' . $check['message'], array( 'error_code' => $check['code'] ) );
		wp_send_json_error( array( 'message' => $check['message'] ) );
	}

	/**
	 * Shows what a category's sources return now, without storing anything.
	 */
	public static function test_search(): void {
		self::guard();
		$category = Categories::get( absint( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $category ) {
			wp_send_json_error( array( 'message' => 'Category not found.' ) );
		}

		$plan = Discovery::fetch_plan( $category );
		if ( ! $plan ) {
			wp_send_json_error( array( 'message' => 'This category has no usable source.' ) );
		}

		$results = array();
		foreach ( array_slice( $plan, 0, 4 ) as $fetch ) {
			$host = (string) wp_parse_url( $fetch['url'], PHP_URL_HOST );
			delete_transient( 'zzzc_host_' . md5( strtolower( $host ) ) );
			$fetched = Discovery::fetch( $fetch['adapter'], $fetch['url'] );
			$stories = array();
			$passed  = 0;
			foreach ( $fetched['items'] as $entry ) {
				$normal = Discovery::normalize_entry( $entry, $fetch['adapter'] );
				$reject = Discovery::filter_reason( $category, $normal );
				if ( ! $reject ) {
					$passed++;
				}
				if ( count( $stories ) < 5 ) {
					$stories[] = array(
						'headline'  => $normal['headline'],
						'publisher' => $normal['publisher'],
						'published' => $normal['published'] ? Schedule::format_local( gmdate( 'Y-m-d H:i:s', $normal['published'] ) ) : 'No date',
						'result'    => $reject ?: 'Passes filters',
					);
				}
			}
			$results[] = array(
				'context' => $fetch['context'],
				'filter'  => $fetch['filter'],
				'status'  => $fetched['status'],
				'message' => $fetched['message'],
				'total'   => count( $fetched['items'] ),
				'passed'  => $passed,
				'stories' => $stories,
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public static function test_generation(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$headline  = sanitize_text_field( wp_unslash( $_POST['headline'] ?? '' ) );
		$publisher = sanitize_text_field( wp_unslash( $_POST['publisher'] ?? '' ) );
		$url       = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$text      = sanitize_textarea_field( wp_unslash( $_POST['source_text'] ?? '' ) );
		// phpcs:enable

		if ( '' === $headline || '' === $text ) {
			wp_send_json_error( array( 'message' => 'Enter a sample headline and source text.' ) );
		}
		if ( ! Secrets::has_api_key() ) {
			wp_send_json_error( array( 'message' => 'Save an API key first.' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 2 * (int) Settings::get( 'ai_timeout' ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$result = Generation::test_generation( $headline, $publisher, $url, $text );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		set_transient(
			'zzzc_test_gen_' . get_current_user_id(),
			array(
				'title' => $result['title'],
				'body'  => $result['body'],
				'tags'  => $result['tags'],
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'message'  => $result['message'],
				'title'    => $result['title'],
				'body'     => wp_kses_post( $result['body'] ),
				'tags'     => $result['tags'],
				'errors'   => array_column( $result['verdict']['errors'], 1 ),
				'warnings' => array_column( $result['verdict']['warnings'], 1 ),
				'metrics'  => $result['verdict']['metrics'],
				'usage'    => array(
					'model'         => $result['model'],
					'generation_id' => $result['generation_id'],
					'input'         => $result['usage']['prompt_tokens'] ?? null,
					'output'        => $result['usage']['completion_tokens'] ?? null,
					'cost'          => $result['usage']['cost'] ?? null,
				),
			)
		);
	}

	public static function save_test_draft(): void {
		self::guard();
		$saved = get_transient( 'zzzc_test_gen_' . get_current_user_id() );
		if ( ! is_array( $saved ) ) {
			wp_send_json_error( array( 'message' => 'Run a test generation first.' ) );
		}
		$post_id = Publisher::create_test_draft( (string) $saved['title'], (string) $saved['body'], (array) $saved['tags'] );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => 'Test draft created. It does not count against the daily cap.',
				'url'     => get_edit_post_link( (int) $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Refreshes the dashboard figures without reloading the page.
	 */
	public static function live_status(): void {
		self::guard();
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * The refreshed figures, plus the state label shown above them.
	 */
	public static function status_payload(): array {
		ob_start();
		Pages::dashboard_live();
		$html  = (string) ob_get_clean();
		$state = Automation::display_state();

		return array(
			'html'        => $html,
			'state_label' => Pages::state_label( $state ),
			'state_class' => 'is-' . strtolower( $state ),
		);
	}
}
