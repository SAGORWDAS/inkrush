<?php
namespace ZZZCopy\Social;

use ZZZCopy\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Social distribution entries in the plugin's existing activity log.
 *
 * Everything goes to the events table under the stage "social", so the Logs page, its filters,
 * its export and its retention rules all work on these entries with no change. The platform and
 * the social post id are carried in the entry's context, and the platform's name is put at the
 * front of the message, so every line says which platform, which article and which publishing
 * job it is about, as the owner's requirements ask.
 *
 * Nothing written here is a credential. Messages pass through the same redaction as the rest of
 * the plugin, and the adapters register each token they use so it is stripped even if a platform
 * echoes it back in an error.
 */
final class SocialLogger {

	public const STAGE = 'social';

	/**
	 * @param string $severity info, warning or error.
	 * @param string $platform One of the six, or an empty string for a message about all of them.
	 * @param string $message  What happened, in plain words.
	 * @param array  $ctx      Optional: social_post_id, account_id, article_job_id, wp_post_id,
	 *                         error_code, status, attempt, context.
	 */
	public static function log( string $severity, string $platform, string $message, array $ctx = array() ): void {
		$label  = '' !== $platform ? Platforms::label( $platform ) . ': ' : '';
		$extra  = is_array( $ctx['context'] ?? null ) ? $ctx['context'] : array();
		$detail = array_merge(
			$extra,
			array_filter(
				array(
					'platform'       => $platform,
					'social_post_id' => (int) ( $ctx['social_post_id'] ?? 0 ),
					'account_id'     => (int) ( $ctx['account_id'] ?? 0 ),
				)
			)
		);

		Events::log(
			self::STAGE,
			$severity,
			$label . $message,
			array(
				'status'         => (string) ( $ctx['status'] ?? '' ),
				'error_code'     => (string) ( $ctx['error_code'] ?? '' ),
				'article_job_id' => (int) ( $ctx['article_job_id'] ?? 0 ),
				'wp_post_id'     => (int) ( $ctx['wp_post_id'] ?? 0 ),
				'attempt'        => (int) ( $ctx['attempt'] ?? 0 ),
				'context'        => $detail,
			)
		);
	}

	public static function info( string $platform, string $message, array $ctx = array() ): void {
		self::log( 'info', $platform, $message, $ctx );
	}

	public static function warning( string $platform, string $message, array $ctx = array() ): void {
		self::log( 'warning', $platform, $message, $ctx );
	}

	public static function error( string $platform, string $message, array $ctx = array() ): void {
		self::log( 'error', $platform, $message, $ctx );
	}

	/**
	 * Context drawn from one social post row, so a caller does not have to assemble it.
	 */
	public static function context( array $row, array $extra = array() ): array {
		return array_merge(
			array(
				'social_post_id' => (int) ( $row['id'] ?? 0 ),
				'account_id'     => (int) ( $row['account_id'] ?? 0 ),
				'article_job_id' => (int) ( $row['article_job_id'] ?? 0 ),
				'wp_post_id'     => (int) ( $row['wp_post_id'] ?? 0 ),
				'attempt'        => (int) ( $row['attempts'] ?? 0 ),
				'status'         => (string) ( $row['status'] ?? '' ),
			),
			$extra
		);
	}
}
