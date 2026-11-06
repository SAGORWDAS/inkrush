<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint a server cron job can call to run the worker. Requires the secret token.
 */
final class RestTick {

	private const OPTION = 'zzzc_cron_token';

	public static function register(): void {
		register_rest_route(
			'zzzc/v1',
			'/tick',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	public static function token(): string {
		$token = (string) get_option( self::OPTION, '' );
		if ( '' === $token ) {
			$token = self::regenerate();
		}
		return $token;
	}

	public static function regenerate(): string {
		$token = wp_generate_password( 48, false, false );
		update_option( self::OPTION, $token, false );
		return $token;
	}

	public static function authorize( \WP_REST_Request $request ): bool {
		$given = (string) $request->get_header( 'x-zzzc-token' );
		if ( '' === $given ) {
			$given = (string) $request->get_param( 'token' );
		}
		return '' !== $given && hash_equals( self::token(), $given );
	}

	public static function handle(): \WP_REST_Response {
		$summary = Runner::tick( 'server' );
		return new \WP_REST_Response(
			array(
				'ok'    => true,
				'state' => Automation::display_state(),
				'ran'   => $summary['ran'],
				'jobs'  => $summary['jobs'],
			)
		);
	}
}
