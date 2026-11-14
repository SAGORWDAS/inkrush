<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Exact prompt storage with version history.
 *
 * Prompt text is never sanitized, trimmed or corrected. The one normalization is that CRLF line
 * endings become LF, because browsers change every line ending to CRLF when a form is submitted.
 * Without it, saving an unchanged prompt would look like an edit.
 */
final class Prompts {

	public const ARTICLE = 'article';
	public const TAG     = 'tag';
	public const SYSTEM  = 'system';
	public const SOCIAL  = 'social';

	private const OPTION = 'zzzc_active_prompts';

	private const DEFAULT_FILES = array(
		self::ARTICLE => 'article-prompt.txt',
		self::TAG     => 'tag-prompt.txt',
		self::SYSTEM  => 'system-instruction.txt',
		self::SOCIAL  => 'social-prompt.txt',
	);

	public static function default_text( string $type ): string {
		return (string) file_get_contents( ZZZC_DIR . 'defaults/' . self::DEFAULT_FILES[ $type ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function seed_defaults(): void {
		$active = get_option( self::OPTION, array() );
		foreach ( array_keys( self::DEFAULT_FILES ) as $type ) {
			if ( empty( $active[ $type ] ) ) {
				self::save( $type, self::default_text( $type ), 0 );
			}
		}
	}

	/**
	 * Saves a prompt. Creates a new version only when the text changed.
	 *
	 * @return int|\WP_Error Version id.
	 */
	public static function save( string $type, string $text, int $user_id ) {
		global $wpdb;

		if ( ! isset( self::DEFAULT_FILES[ $type ] ) ) {
			return new \WP_Error( 'invalid_type', 'Unknown prompt type.' );
		}

		$text = str_replace( "\r\n", "\n", $text );

		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'empty_prompt', 'The prompt cannot be empty.' );
		}

		$sha     = hash( 'sha256', $text );
		$current = self::active( $type );
		if ( $current && $current['content_sha256'] === $sha ) {
			return (int) $current['id'];
		}

		// A direct query, so WordPress does not strip characters from the text.
		$table    = Db::table( 'prompt_versions' );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (prompt_type, content, content_sha256, created_by, created_at) VALUES (%s, %s, %s, %d, %s)", // phpcs:ignore WordPress.DB
				$type,
				$text,
				$sha,
				$user_id,
				Clock::mysql()
			)
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'The prompt could not be saved to the database.' );
		}

		$version_id = (int) $wpdb->insert_id;

		// Confirm what the database holds is byte-for-byte what was submitted.
		$stored = self::version( $version_id );
		if ( ! $stored || ! hash_equals( $sha, hash( 'sha256', (string) $stored['content'] ) ) ) {
			$wpdb->delete( $table, array( 'id' => $version_id ) );
			return new \WP_Error( 'db_altered', 'The database changed the prompt text while saving it, so it was not saved. Check that the plugin tables use the utf8mb4 character set.' );
		}

		$active          = get_option( self::OPTION, array() );
		$active[ $type ] = $version_id;
		update_option( self::OPTION, $active, false );

		return $version_id;
	}

	public static function active( string $type ): ?array {
		$active = get_option( self::OPTION, array() );
		if ( empty( $active[ $type ] ) ) {
			return null;
		}
		return self::version( (int) $active[ $type ] );
	}

	public static function active_id( string $type ): int {
		$active = get_option( self::OPTION, array() );
		return (int) ( $active[ $type ] ?? 0 );
	}

	public static function active_text( string $type ): string {
		$row = self::active( $type );
		return $row ? (string) $row['content'] : '';
	}

	public static function version( int $id ): ?array {
		global $wpdb;
		$table = Db::table( 'prompt_versions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function history( string $type, int $limit = 20 ): array {
		global $wpdb;
		$table = Db::table( 'prompt_versions' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, content_sha256, created_by, created_at, CHAR_LENGTH(content) AS chars FROM {$table} WHERE prompt_type = %s ORDER BY id DESC LIMIT %d", $type, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Makes an earlier version the active one, without changing its text.
	 */
	public static function restore( string $type, int $version_id ): bool {
		$row = self::version( $version_id );
		if ( ! $row || $row['prompt_type'] !== $type ) {
			return false;
		}
		$active          = get_option( self::OPTION, array() );
		$active[ $type ] = $version_id;
		update_option( self::OPTION, $active, false );
		return true;
	}
}
