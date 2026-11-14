<?php
namespace ZZZCopy\Images;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Permanent record of every image used. A row stays when its post or attachment is deleted.
 * Unique database keys stop two jobs from reserving one image. Only an administrator can release one.
 */
final class ImageHistory {

	public const RESERVED = 'reserved';
	public const IMPORTED = 'imported';
	public const ASSIGNED = 'assigned';
	public const REJECTED = 'rejected';
	public const RELEASED = 'released';

	public static function normalize_filename( string $title ): string {
		$name = (string) preg_replace( '/^File:/i', '', $title );
		$name = mb_strtolower( str_replace( array( '_', ' ' ), '-', trim( $name ) ) );
		return mb_substr( (string) preg_replace( '/-+/', '-', $name ), 0, 190 );
	}

	public static function url_hash( string $url ): string {
		return sha1( strtolower( trim( (string) preg_replace( '/^https?:/i', '', $url ) ) ) );
	}

	/**
	 * Finds a blocking record: any status other than released.
	 *
	 * @return array|null The record, when this image cannot be used.
	 */
	public static function find_block( array $candidate ): ?array {
		global $wpdb;
		$table = Db::table( 'image_history' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status <> %s AND ( commons_page_id = %d OR filename_norm = %s OR file_url_hash = %s ) LIMIT 1", // phpcs:ignore WordPress.DB
				self::RELEASED,
				(int) $candidate['page_id'],
				self::normalize_filename( (string) $candidate['title'] ),
				self::url_hash( (string) $candidate['file_url'] )
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Reserves an image for one article. Safe when several workers try at once.
	 *
	 * @return int Record id, or 0 when another article already holds it.
	 */
	public static function reserve( array $candidate, array $license, int $article_job_id, string $article_title ): int {
		global $wpdb;
		$table = Db::table( 'image_history' );
		$now   = Clock::mysql();

		$fields = array(
			'commons_url'    => (string) $candidate['page_url'],
			'file_url'       => (string) $candidate['file_url'],
			'article_job_id' => $article_job_id,
			'article_title'  => $article_title,
			'image_title'    => (string) preg_replace( '/^File:/i', '', (string) $candidate['title'] ),
			'creator'        => (string) $license['creator'],
			'creator_url'    => (string) $license['creator_url'],
			'license'        => (string) $license['license'],
			'license_version' => (string) $license['version'],
			'license_url'    => (string) $license['url'],
			'status'         => self::RESERVED,
			'status_reason'  => '',
			'reserved_at'    => $now,
			'updated_at'     => $now,
		);

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (commons_page_id, filename_norm, file_url_hash, commons_url, file_url, article_job_id, article_title, image_title, creator, creator_url, license, license_version, license_url, status, reserved_at, updated_at) VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB
				(int) $candidate['page_id'],
				self::normalize_filename( (string) $candidate['title'] ),
				self::url_hash( (string) $candidate['file_url'] ),
				$fields['commons_url'],
				$fields['file_url'],
				$article_job_id,
				$article_title,
				$fields['image_title'],
				$fields['creator'],
				$fields['creator_url'],
				$fields['license'],
				$fields['license_version'],
				$fields['license_url'],
				self::RESERVED,
				$now,
				$now
			)
		);
		$wpdb->suppress_errors( $suppress );

		if ( 1 === (int) $inserted ) {
			return (int) $wpdb->insert_id;
		}

		// A row exists. It can be taken only if an administrator released it, and only by one job.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND commons_page_id = %d", self::RELEASED, (int) $candidate['page_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return 0;
		}
		$fields['wp_post_id']    = 0;
		$fields['attachment_id'] = 0;
		$updated                 = $wpdb->update(
			$table,
			$fields,
			array(
				'id'     => (int) $row['id'],
				'status' => self::RELEASED,
			)
		);
		return 1 === (int) $updated ? (int) $row['id'] : 0;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Db::table( 'image_history' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = Clock::mysql();
		$wpdb->update( Db::table( 'image_history' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Gives back a temporary reservation, such as after a network failure, so it can be tried later.
	 */
	public static function release_temporary( int $id, string $reason ): void {
		self::append_log( $id, 'Temporary reservation released: ' . $reason );
		self::update(
			$id,
			array(
				'status'        => self::RELEASED,
				'status_reason' => mb_substr( $reason, 0, 255 ),
			)
		);
	}

	/**
	 * Tidies up after an interrupted attempt for this article.
	 * A reservation with no file is given back. An image already imported is returned for reuse.
	 *
	 * @return array|null The imported record to reuse.
	 */
	public static function recover_for_article( int $article_job_id ): ?array {
		global $wpdb;
		$table = Db::table( 'image_history' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE article_job_id = %d AND status IN (%s, %s) ORDER BY id DESC", $article_job_id, self::RESERVED, self::IMPORTED ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$reuse = null;
		foreach ( $rows as $row ) {
			if ( self::RESERVED === $row['status'] ) {
				self::release_temporary( (int) $row['id'], 'The earlier attempt was interrupted.' );
			} elseif ( ! $reuse && (int) $row['attachment_id'] && get_post( (int) $row['attachment_id'] ) ) {
				$reuse = $row;
			}
		}
		return $reuse;
	}

	/**
	 * Blocks a file that turned out to be bad or a copy of one already used.
	 */
	public static function reject( int $id, string $reason ): void {
		self::update(
			$id,
			array(
				'status'        => self::REJECTED,
				'status_reason' => mb_substr( $reason, 0, 255 ),
			)
		);
	}

	/**
	 * Administrator action. Requires confirmation in the screen, and is written to the audit log.
	 */
	public static function release_by_admin( int $id ): bool {
		$row = self::get( $id );
		if ( ! $row || self::RELEASED === $row['status'] ) {
			return false;
		}
		self::append_log( $id, sprintf( 'Released by administrator user %d.', get_current_user_id() ) );
		self::update(
			$id,
			array(
				'status'        => self::RELEASED,
				'status_reason' => 'Released by an administrator.',
			)
		);
		// Releasing the reservation is not enough on its own. The article that was holding this
		// image has it recorded on its own image run as ready to use, and the publisher reuses a
		// ready run without searching again, so without this the article would publish with the
		// very image that was just released. An image already on a published post is left alone:
		// releasing a reservation must not pull the picture off something already live.
		$searching_again = false;
		if ( (int) $row['article_job_id'] && ! (int) $row['wp_post_id'] ) {
			$searching_again = ImagePipeline::forget_released_image( (int) $row['article_job_id'], (int) $row['id'] );
		}

		Events::log(
			'image',
			'warning',
			sprintf(
				'Administrator released image reservation: %1$s. It can now be used again.%2$s',
				(string) $row['image_title'],
				$searching_again ? ' The article it was held for will search for another image.' : ''
			),
			array(
				'article_job_id' => (int) $row['article_job_id'],
				'wp_post_id'     => (int) $row['wp_post_id'],
				'status'         => self::RELEASED,
			)
		);
		return true;
	}

	private static function append_log( int $id, string $message ): void {
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}
		$log   = json_decode( (string) $row['release_log'], true ) ?: array();
		$log[] = array(
			'at'      => Clock::mysql(),
			'message' => $message,
		);
		self::update( $id, array( 'release_log' => wp_json_encode( $log ) ) );
	}

	/**
	 * Another record with the same exact file contents, or one that looks the same.
	 *
	 * @return array|null
	 */
	public static function find_same_file( int $exclude_id, string $sha256, string $phash ): ?array {
		global $wpdb;
		$table = Db::table( 'image_history' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE sha256 = %s AND id <> %d AND status <> %s LIMIT 1", $sha256, $exclude_id, self::RELEASED ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			return $row;
		}
		if ( 16 !== strlen( $phash ) ) {
			return null;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, phash, image_title, status FROM {$table} WHERE phash <> '' AND id <> %d AND status <> %s ORDER BY id DESC LIMIT 5000", $exclude_id, self::RELEASED ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $other ) {
			if ( \ZZZCopy\Dedupe::fingerprint_similarity( $phash, (string) $other['phash'] ) >= 0.95 ) {
				return $other;
			}
		}
		return null;
	}

	public static function recent( int $limit, int $offset ): array {
		global $wpdb;
		$table = Db::table( 'image_history' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function count(): int {
		global $wpdb;
		$table = Db::table( 'image_history' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
