<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Ledger of every OpenRouter call. Cost is only what the provider reports, never an estimate.
 * When no cost is reported the value stays NULL and the screen shows "Unavailable".
 */
final class Usage {

	public static function record( string $purpose, int $article_job_id, array $result ): int {
		global $wpdb;
		$usage = (array) $result['usage'];

		$input  = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null;
		$output = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null;
		$total  = isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : null;
		$cost   = isset( $usage['cost'] ) && is_numeric( $usage['cost'] ) ? (float) $usage['cost'] : null;

		$row = array(
			'article_job_id' => $article_job_id,
			'purpose'        => $purpose,
			'generation_id'  => (string) $result['generation_id'],
			'request_id'     => substr( (string) $result['request_id'], 0, 191 ),
			'model'          => (string) $result['model'],
			'success'        => $result['ok'] ? 1 : 0,
			'http_status'    => (int) $result['http'],
			'error_code'     => (string) $result['code'],
			'cost_source'    => null === $cost ? 'unavailable' : 'response',
			'raw_usage'      => $usage ? Secrets::redact( (string) wp_json_encode( $usage ) ) : null,
			'created_at'     => Clock::mysql(),
		);
		if ( null !== $input ) {
			$row['input_tokens'] = $input;
		}
		if ( null !== $output ) {
			$row['output_tokens'] = $output;
		}
		if ( null !== $total ) {
			$row['total_tokens'] = $total;
		}
		if ( null !== $cost ) {
			$row['cost_usd'] = sprintf( '%.8F', $cost );
		}

		$wpdb->insert( Db::table( 'generation_usage' ), $row );
		$id = (int) $wpdb->insert_id;

		if ( null === $cost && '' !== $row['generation_id'] && $result['ok'] ) {
			Jobs::enqueue( Jobs::USAGE_FETCH, array( 'usage_id' => $id, 'generation_id' => $row['generation_id'] ), 'usage:' . $id, 30, 4 );
		}

		return $id;
	}

	/**
	 * Job handler: asks OpenRouter for a generation's reported cost.
	 */
	public static function handle_fetch( array $job ): JobResult {
		global $wpdb;
		$data = OpenRouter::generation( (string) $job['payload']['generation_id'] );
		if ( ! $data ) {
			return JobResult::retry( Jobs::backoff( (int) $job['attempts'], 60, 900 ), 'generation_not_ready', 'Generation statistics are not available yet.' );
		}

		$update = array();
		if ( isset( $data['total_cost'] ) && is_numeric( $data['total_cost'] ) ) {
			$update['cost_usd']    = sprintf( '%.8F', (float) $data['total_cost'] );
			$update['cost_source'] = 'generation_api';
		}
		if ( isset( $data['tokens_prompt'] ) ) {
			$update['input_tokens'] = (int) $data['tokens_prompt'];
		}
		if ( isset( $data['tokens_completion'] ) ) {
			$update['output_tokens'] = (int) $data['tokens_completion'];
		}
		if ( isset( $update['input_tokens'], $update['output_tokens'] ) ) {
			$update['total_tokens'] = $update['input_tokens'] + $update['output_tokens'];
		}
		if ( $update ) {
			$wpdb->update( Db::table( 'generation_usage' ), $update, array( 'id' => (int) $job['payload']['usage_id'] ) );
		}
		return JobResult::done();
	}

	/**
	 * Article generation attempts started in the current local day.
	 */
	public static function ai_articles_today(): int {
		global $wpdb;
		$table = Db::table( 'generation_usage' );
		list( $start, $end ) = Schedule::day_bounds_utc( Schedule::today() );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE purpose = 'article' AND created_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Totals for a period. Cost is NULL when no row in the period has a reported cost.
	 */
	public static function totals( ?string $from = null, ?string $to = null ): array {
		global $wpdb;
		$table = Db::table( 'generation_usage' );
		$where = '1=1';
		if ( $from && $to ) {
			$where = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from, $to );
		}
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS requests, SUM(success = 1) AS succeeded, SUM(success = 0) AS failed,
				SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(total_tokens) AS total_tokens,
				SUM(cost_usd) AS cost_usd, SUM(success = 1 AND cost_usd IS NULL) AS uncosted
			FROM {$table} WHERE {$where}", // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return $row ?: array();
	}

	public static function latest(): ?array {
		global $wpdb;
		$table = Db::table( 'generation_usage' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function history( int $limit, int $offset ): array {
		global $wpdb;
		$table = Db::table( 'generation_usage' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function history_count(): int {
		global $wpdb;
		$table = Db::table( 'generation_usage' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
