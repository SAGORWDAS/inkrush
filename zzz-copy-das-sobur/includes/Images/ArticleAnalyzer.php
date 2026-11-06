<?php
namespace ZZZCopy\Images;

use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what an article is about from its full text, using rules and no AI.
 * Reads the title, source headline, headings, body and tags.
 */
final class ArticleAnalyzer {

	private const STOP = array( 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'has', 'have', 'had', 'its', 'his', 'her', 'their', 'after', 'before', 'over', 'into', 'about', 'new', 'not', 'but', 'you', 'your', 'our', 'will', 'can', 'may', 'more', 'than', 'what', 'why', 'how', 'who', 'when', 'where', 'says', 'said', 'now', 'out', 'off', 'amid', 'against', 'report', 'reports', 'latest', 'today', 'news', 'update', 'here', 'know', 'need', 'could', 'would', 'should', 'being', 'been', 'they', 'them', 'also', 'plans', 'plan', 'first', 'next', 'year', 'years', 'week', 'month', 'day' );

	/** Capitalized words that begin a sentence or heading and do not name anything. */
	private const NOT_NAMES = array( 'The', 'A', 'An', 'And', 'But', 'For', 'With', 'After', 'Before', 'How', 'Why', 'What', 'When', 'Where', 'Who', 'This', 'That', 'These', 'Those', 'It', 'In', 'On', 'At', 'As', 'By', 'To', 'Of', 'If', 'New', 'Trending', 'FAQ', 'Here', 'They', 'We', 'You', 'Our', 'Their', 'His', 'Her', 'Its', 'Some', 'Many', 'Most', 'More', 'Key', 'Top', 'Stage', 'Date' );

	private const NEW_PLACES = array( 'York', 'Jersey', 'Zealand', 'Delhi', 'Orleans', 'Mexico', 'Hampshire', 'England', 'South', 'Brunswick', 'Guinea', 'Caledonia', 'Haven' );

	public static function tokens( string $text ): array {
		$text = mb_strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		preg_match_all( '/[\p{L}\p{N}]{3,}/u', $text, $match );
		return array_values( array_diff( $match[0], self::STOP ) );
	}

	/**
	 * Runs of capitalized words: the likely people, companies, products and places.
	 */
	private static function names( string $text ): array {
		$found = array();
		preg_match_all( '/\p{Lu}[\p{L}\p{N}\'’&.\-]*(?:\s+(?:(?:of|de|la|the|and|van|von)\s+)?\p{Lu}[\p{L}\p{N}\'’&.\-]*)*/u', $text, $match );
		foreach ( $match[0] as $phrase ) {
			$words = preg_split( '/\s+/u', trim( $phrase, " .'’-" ) ) ?: array();
			while ( $words && in_array( $words[0], self::NOT_NAMES, true ) ) {
				// "New" is part of a place name in "New York", but only a describing word in "New iPhone".
				if ( 'New' === $words[0] && isset( $words[1] ) && in_array( $words[1], self::NEW_PLACES, true ) ) {
					break;
				}
				array_shift( $words );
			}
			// "Russell Brand's" names Russell Brand.
			$clean = (string) preg_replace( '/[\'’]s$/u', '', implode( ' ', $words ) );
			if ( mb_strlen( $clean ) >= 3 ) {
				$found[] = $clean;
			}
		}
		return $found;
	}

	/**
	 * Whether most longer words in a line are capitalized. Capital letters then say nothing about what is a name.
	 */
	private static function is_title_case( string $text ): bool {
		preg_match_all( '/\p{L}[\p{L}\p{N}\'’\-]{3,}/u', $text, $match );
		$words = $match[0];
		if ( count( $words ) < 3 ) {
			return false;
		}
		$capitalized = 0;
		foreach ( $words as $word ) {
			$capitalized += preg_match( '/^\p{Lu}/u', $word );
		}
		return $capitalized / count( $words ) >= 0.75;
	}

	/**
	 * The pattern for a whole word or phrase, so "Ali" does not match inside "quality".
	 */
	private static function word_pattern( string $phrase, string $flags = 'u' ): string {
		return '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/' . $flags;
	}

	/**
	 * Names in a title or heading. In Title Case, a capitalized run is cut down to names the article's
	 * running text confirms. With none confirmed, as with a headline alone, the start of each part is kept.
	 *
	 * @param string   $text  A title, headline or heading.
	 * @param string[] $known Names found in the article's running text.
	 */
	private static function line_names( string $text, array $known ): array {
		$runs = self::names( $text );
		if ( ! self::is_title_case( $text ) ) {
			return $runs;
		}
		usort(
			$known,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		$found = array();
		foreach ( $runs as $run ) {
			$found = array_merge( $found, self::split_run( $run, $known ) );
		}
		return $found;
	}

	private static function split_run( string $run, array $known ): array {
		$words = preg_split( '/\s+/u', $run ) ?: array();
		if ( count( $words ) <= 2 ) {
			return array( $run );
		}

		$confirmed = array();
		$rest      = $run;
		foreach ( $known as $name ) {
			$pattern = self::word_pattern( $name, 'iu' );
			if ( preg_match( $pattern, $rest ) ) {
				$confirmed[] = $name;
				$rest        = (string) preg_replace( $pattern, ' | ', $rest );
			}
		}
		if ( $confirmed ) {
			return $confirmed;
		}

		// Nothing to confirm against: split at connecting words, then keep the start of each part.
		$parts = array( array() );
		foreach ( $words as $index => $word ) {
			$capitalized = 1 === preg_match( '/^\p{Lu}/u', $word );
			$new_place   = 'New' === $word && in_array( $words[ $index + 1 ] ?? '', self::NEW_PLACES, true );
			if ( $capitalized && ! $new_place && ( in_array( $word, self::NOT_NAMES, true ) || in_array( mb_strtolower( $word ), self::STOP, true ) ) ) {
				$parts[] = array();
				continue;
			}
			$parts[ count( $parts ) - 1 ][] = $word;
		}

		$found = array();
		foreach ( $parts as $part ) {
			$kept     = array();
			$capitals = 0;
			foreach ( $part as $word ) {
				$kept[]    = $word;
				$capitals += preg_match( '/^\p{Lu}/u', $word );
				if ( 2 === $capitals ) {
					break;
				}
			}
			$name = implode( ' ', $kept );
			if ( mb_strlen( $name ) >= 3 ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * Whether a single capitalized word is really an ordinary word that begins some sentences,
	 * such as "Police" in an article that also says "police" at least as often.
	 */
	private static function is_common_word( string $name, string $prose ): bool {
		$lower = mb_strtolower( $name );
		if ( false !== strpos( $name, ' ' ) || $lower === $name ) {
			return false;
		}
		$plain = (int) preg_match_all( self::word_pattern( $lower ), $prose );
		return $plain > 0 && $plain >= (int) preg_match_all( self::word_pattern( $name ), $prose );
	}

	/**
	 * @param string   $title    Generated title.
	 * @param string   $body     Generated body HTML.
	 * @param string[] $tags     Generated tags.
	 * @param string   $headline Source headline.
	 * @param string   $category News category name.
	 * @return array{main:string, entities:string[], keywords:string[], concepts:string[], queries:string[]}
	 */
	public static function analyze( string $title, string $body, array $tags, string $headline, string $category ): array {
		$plain_body = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $body ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title_text = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$head_text  = html_entity_decode( wp_strip_all_tags( $headline ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tag_blob   = mb_strtolower( implode( ' ', $tags ) );

		// Running text is the reliable guide to names, because headings are often in Title Case.
		$headings   = array();
		$prose_html = (string) preg_replace_callback(
			'#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is',
			static function ( $match ) use ( &$headings ) {
				$headings[] = html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return ' . ';
			},
			$body
		);
		$prose = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $prose_html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Preview image search passes the headline as the first line of the text.
		$prose       = str_ireplace( array_values( array_filter( array( $title_text, $head_text ), 'strlen' ) ), ' . ', $prose );
		$prose_names = self::names( $prose );
		$known       = array_values( array_unique( $prose_names ) );

		$scores = array();
		foreach ( array_merge( self::line_names( $title_text, $known ), self::line_names( $head_text, $known ) ) as $name ) {
			$scores[ $name ] = ( $scores[ $name ] ?? 0 ) + 5;
		}
		foreach ( $prose_names as $name ) {
			$scores[ $name ] = ( $scores[ $name ] ?? 0 ) + 1;
		}
		foreach ( $headings as $heading ) {
			foreach ( self::line_names( $heading, $known ) as $name ) {
				$scores[ $name ] = ( $scores[ $name ] ?? 0 ) + 1;
			}
		}
		// A short form on its own, such as "Brand" for "Russell Brand", counts toward the full name.
		// Otherwise an article that mostly says "Brand" would search for the word "Brand".
		foreach ( array_keys( $scores ) as $short ) {
			$short = (string) $short;
			if ( false !== strpos( $short, ' ' ) ) {
				continue;
			}
			$full = '';
			foreach ( array_keys( $scores ) as $long ) {
				$long = (string) $long;
				if ( false !== strpos( $long, ' ' ) && preg_match( self::word_pattern( $short ), $long ) && ( '' === $full || $scores[ $long ] > $scores[ $full ] ) ) {
					$full = $long;
				}
			}
			if ( '' !== $full ) {
				$scores[ $full ] += $scores[ $short ];
				unset( $scores[ $short ] );
			}
		}

		foreach ( array_keys( $scores ) as $name ) {
			if ( self::is_common_word( (string) $name, $prose ) ) {
				unset( $scores[ $name ] );
				continue;
			}
			if ( false !== mb_strpos( $tag_blob, mb_strtolower( $name ) ) ) {
				$scores[ $name ] += 2;
			}
			$scores[ $name ] += min( 3, substr_count( $name, ' ' ) );
		}
		$scores = array_filter(
			$scores,
			static function ( $score, $name ) use ( $title_text, $head_text ) {
				return $score >= 3 || false !== mb_stripos( $title_text . ' ' . $head_text, $name );
			},
			ARRAY_FILTER_USE_BOTH
		);
		arsort( $scores );
		$entities = array_map( 'strval', array_slice( array_keys( $scores ), 0, 6 ) );
		$main     = $entities[0] ?? '';

		// The AI tags confirm names. A tag of two or more words that is a cleaner form of the subject
		// becomes the subject, such as "Russell Brand" for "Comedian Russell Brand", or for "Brand"
		// when the article itself also says "Russell Brand".
		$article_text = $title_text . ' . ' . $head_text . ' . ' . $plain_body;
		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' === $main || false === strpos( $tag, ' ' ) || 0 === strcasecmp( $tag, $main ) ) {
				continue;
			}
			$inside = preg_match( self::word_pattern( $tag, 'iu' ), $main ) || preg_match( self::word_pattern( $main, 'iu' ), $tag );
			if ( $inside && preg_match( self::word_pattern( $tag, 'iu' ), $article_text, $written ) ) {
				$main     = $written[0];
				$entities = array_slice( array_values( array_unique( array_merge( array( $main ), array_slice( $entities, 1 ) ) ) ), 0, 6 );
				break;
			}
		}
		$tags_lc = array_map(
			static function ( $tag ) {
				return mb_strtolower( trim( (string) $tag ) );
			},
			$tags
		);

		// The second name to search with. A single word must be one of the tags, because a word such as
		// "Channel" from "Channel 4" finds unrelated files.
		$second = '';
		foreach ( array_slice( $entities, 1 ) as $entity ) {
			if ( false !== mb_stripos( $main, $entity ) ) {
				continue;
			}
			if ( false !== strpos( $entity, ' ' ) || in_array( mb_strtolower( $entity ), $tags_lc, true ) ) {
				$second = $entity;
				break;
			}
		}

		$body_counts = array_count_values( self::tokens( $plain_body ) );
		arsort( $body_counts );

		$keywords = array_values( array_unique( self::tokens( $title_text . ' ' . $head_text ) ) );
		usort(
			$keywords,
			static function ( $a, $b ) use ( $body_counts ) {
				return ( $body_counts[ $b ] ?? 0 ) <=> ( $body_counts[ $a ] ?? 0 );
			}
		);
		$keywords = array_slice( $keywords, 0, 8 );

		$concepts = array_values(
			array_unique(
				array_merge(
					array_slice( array_keys( $body_counts ), 0, 8 ),
					array_map( 'mb_strtolower', array_slice( $tags, 0, 8 ) )
				)
			)
		);

		$queries = array();
		$main_lc = mb_strtolower( $main );
		$visual  = '';
		foreach ( $keywords as $word ) {
			if ( false === mb_strpos( $main_lc, $word ) ) {
				$visual = $word;
				break;
			}
		}

		if ( '' !== $main ) {
			$queries[] = $main;
			if ( '' !== $visual ) {
				$queries[] = $main . ' ' . $visual;
			}
			if ( '' !== $second ) {
				$queries[] = $main . ' ' . $second;
				$queries[] = $second;
			}
		}
		foreach ( $tags as $tag ) {
			if ( false !== mb_stripos( $title_text, (string) $tag ) && mb_strlen( (string) $tag ) >= 4 ) {
				$queries[] = (string) $tag;
				break;
			}
		}
		if ( $keywords ) {
			$queries[] = implode( ' ', array_slice( $keywords, 0, 4 ) );
			$queries[] = implode( ' ', array_slice( $keywords, 0, 2 ) ) . ' ' . $category;
		}

		$seen   = array();
		$unique = array();
		foreach ( $queries as $query ) {
			$query = trim( (string) preg_replace( '/[^\p{L}\p{N}\s\'’&.\-]+/u', ' ', $query ) );
			$key   = mb_strtolower( $query );
			if ( '' !== $query && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$unique[]     = $query;
			}
		}

		return array(
			'main'     => $main,
			'entities' => $entities,
			'keywords' => $keywords,
			'concepts' => $concepts,
			'queries'  => array_slice( $unique, 0, (int) Settings::get( 'image_query_count' ) ),
		);
	}
}
