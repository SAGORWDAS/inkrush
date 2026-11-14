<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the model's tag reply into a clean list.
 */
final class TagParser {

	public const REQUIRED = 20;

	/**
	 * @return string[] Unique, sanitized tags in the order given.
	 */
	public static function parse( string $raw ): array {
		$text = trim( wp_strip_all_tags( $raw ) );

		// Drop a lead-in such as "Here are 20 tags:" or "Tags:".
		$text = (string) preg_replace( '/^[^,\n]{0,80}\b(tags?|keywords?)\b[^,\n:]{0,40}:\s*/iu', '', $text );

		// Newlines, semicolons and bullets separate tags as well as commas do.
		$text = (string) preg_replace( '/[\r\n;]+/', ',', $text );

		$seen = array();
		$tags = array();
		foreach ( explode( ',', $text ) as $piece ) {
			$tag = trim( $piece );
			$tag = (string) preg_replace( '/^(\d+[.)]\s*|[-*•#]+\s*)/u', '', $tag );
			$tag = trim( $tag, " \t\"'“”‘’`*_." );
			$tag = sanitize_text_field( $tag );
			if ( '' === $tag || mb_strlen( $tag ) > 100 ) {
				continue;
			}
			$key = mb_strtolower( $tag );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$tags[]       = $tag;
		}
		return $tags;
	}
}
