<?php
namespace ZZZCopy\Images;

use ZZZCopy\Clock;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the image credit on the public article page, once, directly below the featured image.
 *
 * Most themes print the image through post_thumbnail_html. Page builders such as Elementor may not,
 * so the article text adds the credit at the top instead when the image filter has not run.
 *
 * A copy of the credit is also saved at the end of the post text, so it stays on the page if this
 * plugin is deactivated or removed. While the plugin runs, that copy is taken out before display.
 */
final class Attribution {

	public const META = '_zzzc_image_credit';

	/** Marks the copy of the credit saved in the post text. */
	private const SAVED_CLASS = 'zzzc-image-credit-saved';

	private static array $shown = array();

	public static function register(): void {
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'after_thumbnail' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'into_content' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'print_style' ), 50 );
	}

	/**
	 * Builds the permanent credit record for a post.
	 */
	public static function from_record( array $record ): array {
		return array(
			'title'           => (string) ( $record['image_title'] ?? '' ),
			'creator'         => (string) $record['creator'],
			'creator_url'     => (string) $record['creator_url'],
			'license'         => (string) $record['license'],
			'license_version' => (string) $record['license_version'],
			'license_url'     => (string) $record['license_url'],
			'commons_url'     => (string) $record['commons_url'],
			'file_url'        => (string) $record['file_url'],
			'source'          => 'Wikimedia Commons',
			'modifications'   => (string) $record['modifications'],
			'downloaded_at'   => (string) ( $record['downloaded_at'] ?? Clock::mysql() ),
			'attachment_id'   => (int) $record['attachment_id'],
		);
	}

	public static function is_complete( array $credit ): bool {
		if ( '' === (string) ( $credit['license'] ?? '' ) || '' === (string) ( $credit['commons_url'] ?? '' ) ) {
			return false;
		}
		if ( 0 === strpos( (string) $credit['license'], 'CC BY' ) ) {
			return '' !== (string) ( $credit['creator'] ?? '' ) && '' !== (string) ( $credit['license_url'] ?? '' );
		}
		return true;
	}

	/**
	 * The file's title for the credit. Credits saved before version 1.3.2 have none, so it comes from the file page address.
	 */
	private static function title_of( array $credit ): string {
		$title = trim( (string) ( $credit['title'] ?? '' ) );
		if ( '' === $title ) {
			$path  = (string) wp_parse_url( (string) ( $credit['commons_url'] ?? '' ), PHP_URL_PATH );
			$title = rawurldecode( substr( (string) strrchr( '/' . $path, '/' ), 1 ) );
		}
		$title = (string) preg_replace( '/^File:/i', '', $title );
		$title = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $title );
		return trim( str_replace( '_', ' ', $title ) );
	}

	/**
	 * The credit markup. All values are escaped here.
	 */
	public static function html( array $credit ): string {
		return '<p class="zzzc-image-credit">' . self::line( $credit ) . '</p>';
	}

	/**
	 * The words and links of the credit.
	 */
	private static function line( array $credit ): string {
		$creator = trim( (string) ( $credit['creator'] ?? '' ) );
		$name    = '' !== $creator ? $creator : 'Creator unknown';
		$who     = '' !== (string) ( $credit['creator_url'] ?? '' ) && '' !== $creator
			? sprintf( '<a href="%1$s" rel="nofollow noopener" target="_blank">%2$s</a>', esc_url( (string) $credit['creator_url'] ), esc_html( $name ) )
			: esc_html( $name );

		$source = sprintf( '<a href="%1$s" rel="nofollow noopener" target="_blank">Wikimedia Commons</a>', esc_url( (string) $credit['commons_url'] ) );

		$license_name = esc_html( (string) $credit['license'] );
		$license      = '' !== (string) ( $credit['license_url'] ?? '' )
			? sprintf( '<a href="%1$s" rel="license nofollow noopener" target="_blank">%2$s</a>', esc_url( (string) $credit['license_url'] ), $license_name )
			: $license_name;

		$title = self::title_of( $credit );
		$label = '' !== $title ? '&ldquo;' . esc_html( $title ) . '&rdquo;, ' : '';

		$line = sprintf( 'File photo: %1$s%2$s / %3$s. License: %4$s.', $label, $who, $source, $license );

		$modified = trim( (string) ( $credit['modifications'] ?? '' ) );
		if ( '' !== $modified && 'None' !== $modified ) {
			$line .= ' Modified: ' . esc_html( $modified );
		}

		return $line;
	}

	/**
	 * Whether a saved credit should show under the current settings.
	 */
	private static function should_show( $credit ): bool {
		if ( ! is_array( $credit ) || ! self::is_complete( $credit ) ) {
			return false;
		}
		return 'required' !== Settings::get( 'image_attribution' ) || 0 === strpos( (string) $credit['license'], 'CC BY' );
	}

	/**
	 * The credit for a post, or an empty string when none should show.
	 */
	private static function credit_for( int $post_id ): string {
		$credit = get_post_meta( $post_id, self::META, true );
		return self::should_show( $credit ) ? self::html( $credit ) : '';
	}

	/**
	 * Removes the saved copy of the credit from post text.
	 */
	private static function strip_saved( string $content ): string {
		if ( false === strpos( $content, self::SAVED_CLASS ) ) {
			return $content;
		}
		return (string) preg_replace( '#\s*<p\b[^>]*\b' . self::SAVED_CLASS . '\b[^>]*>.*?</p>#is', '', $content );
	}

	/**
	 * Saves a copy of the post's credit at the end of its text, replacing any earlier copy.
	 *
	 * This writes to the database directly, so a credit does not change the post's modified date,
	 * add a revision, or set off other plugins' save actions. The values are escaped by line().
	 */
	public static function save_to_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$credit = get_post_meta( $post_id, self::META, true );
		$text   = self::strip_saved( (string) $post->post_content );
		if ( self::should_show( $credit ) ) {
			$text = rtrim( $text ) . "\n" . '<p class="zzzc-image-credit ' . self::SAVED_CLASS . '">' . self::line( $credit ) . '</p>';
		}
		if ( $text === $post->post_content ) {
			return true;
		}

		global $wpdb;
		$saved = $wpdb->update( $wpdb->posts, array( 'post_content' => $text ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		return false !== $saved;
	}

	/**
	 * Saves the credit into posts that do not have a copy yet, such as those from before version 1.3.2.
	 *
	 * @return int Posts checked.
	 */
	public static function save_all( int $limit = 1000 ): int {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_content NOT LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB
				self::META,
				'%' . $wpdb->esc_like( self::SAVED_CLASS ) . '%',
				$limit
			)
		);
		foreach ( $ids as $post_id ) {
			self::save_to_post( (int) $post_id );
		}
		return count( $ids );
	}

	/**
	 * Only on the article's own page, once WordPress has begun printing it.
	 *
	 * Block themes build the page before wp_head runs, so that hook cannot mark the start.
	 * SEO plugins read the content during wp_head and earlier to make descriptions, so those times are excluded.
	 */
	private static function right_place( int $post_id ): bool {
		if ( is_admin() || is_feed() || ! is_singular( 'post' ) || $post_id <= 0 || (int) get_queried_object_id() !== $post_id ) {
			return false;
		}
		if ( ! did_action( 'template_redirect' ) || doing_action( 'template_redirect' ) || doing_action( 'wp_head' ) ) {
			return false;
		}
		return ! isset( self::$shown[ $post_id ] );
	}

	public static function after_thumbnail( $html, $post_id ) {
		$post_id = (int) $post_id;
		if ( '' === (string) $html || ! self::right_place( $post_id ) ) {
			return $html;
		}
		$credit = self::credit_for( $post_id );
		if ( '' === $credit ) {
			return $html;
		}
		self::$shown[ $post_id ] = true;
		return $html . $credit;
	}

	/**
	 * The fallback for themes and builders that print the featured image another way.
	 */
	public static function into_content( $content ) {
		$content = self::strip_saved( (string) $content );
		$post_id = (int) get_the_ID();
		if ( doing_filter( 'get_the_excerpt' ) || ! self::right_place( $post_id ) ) {
			return $content;
		}
		$credit = self::credit_for( $post_id );
		if ( '' === $credit ) {
			return $content;
		}
		self::$shown[ $post_id ] = true;
		return $credit . $content;
	}

	public static function print_style(): void {
		if ( ! is_singular( 'post' ) || ! get_post_meta( (int) get_queried_object_id(), self::META, true ) ) {
			return;
		}
		echo '<style id="zzzc-image-credit">.zzzc-image-credit{display:block;margin:.4em 0 1.2em;font-size:.8em;line-height:1.4;opacity:.8;word-break:break-word}p.zzzc-image-credit a{display:inline!important;height:auto!important;text-decoration:underline}</style>' . "\n";
	}
}
