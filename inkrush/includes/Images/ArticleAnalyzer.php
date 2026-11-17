<?php
namespace ZZZCopy\Images;

use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what an article is about from its full text, using rules and no AI.
 * Reads the title, source headline, headings, body and tags.
 */
final class ArticleAnalyzer {

	private const STOP = array( 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'has', 'have', 'had', 'its', 'his', 'her', 'their', 'after', 'before', 'over', 'into', 'about', 'new', 'not', 'but', 'you', 'your', 'our', 'will', 'can', 'may', 'more', 'than', 'what', 'why', 'how', 'who', 'when', 'where', 'says', 'said', 'now', 'out', 'off', 'amid', 'against', 'report', 'reports', 'latest', 'today', 'news', 'update', 'here', 'know', 'need', 'could', 'would', 'should', 'being', 'been', 'they', 'them', 'also', 'plans', 'plan', 'first', 'next', 'year', 'years', 'week', 'month', 'day',
		// Dates say when, not what. "November" was the only real word a photograph of mushrooms filed
		// under "Fungi of Friesland in November" shared with a film festival story (1.12.4). "March"
		// is left in, because a march is also a protest, and "may" is already a stop word.
		'january', 'february', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/** Month names, for finding dates such as "November 19" and "19 November". */
	private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

	/** Capitalized words that begin a sentence or heading and do not name anything. */
	private const NOT_NAMES = array( 'The', 'A', 'An', 'And', 'But', 'For', 'With', 'After', 'Before', 'How', 'Why', 'What', 'When', 'Where', 'Who', 'This', 'That', 'These', 'Those', 'It', 'In', 'On', 'At', 'As', 'By', 'To', 'Of', 'If', 'New', 'Trending', 'FAQ', 'Here', 'They', 'We', 'You', 'Our', 'Their', 'His', 'Her', 'Its', 'Some', 'Many', 'Most', 'More', 'Key', 'Top', 'Stage', 'Date', 'Is', 'Are', 'Was', 'Were', 'Can', 'Could', 'Would', 'Should', 'Does', 'Do', 'Did', 'Has', 'Have', 'Had', 'However', 'Meanwhile', 'While', 'Despite', 'Although', 'According', 'There', 'Then', 'So', 'Yet', 'Also', 'Still',
		// From the owner's logs of 2026-11-13 and 14: "Check Nvidia", "Use Kuaishou", "Which Baltic",
		// "No. Nvidia" and "Through Nov" were each searched as if they were a name.
		'Which', 'Whose', 'Whom', 'Check', 'Use', 'Using', 'Through', 'During', 'Since', 'Until', 'Between', 'Among', 'About', 'Against', 'Into', 'From', 'No', 'Not', 'Yes', 'Let', 'Just', 'Only', 'Even', 'Today', 'Yesterday', 'Tomorrow',
		// Short months that are nobody's name. "Jan", "Mar" and "Jun" are left out, because they are.
		'Feb', 'Apr', 'Jul', 'Aug', 'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
		// Full months and weekdays that are nobody's name. April, May, June, July and August can be,
		// so those are only set aside when they are part of a date (see undate()). From the owner's log
		// of 2026-11-17, where "November" became the subject of a film festival story.
		'January', 'February', 'March', 'September', 'October', 'November', 'December',
		'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * Last words of a news outlet's name. An outlet the article quotes or was published by is not what it is about.
	 */
	private const OUTLET_WORDS = array( 'News', 'Network', 'Reporter', 'Times', 'Post', 'Herald', 'Tribune', 'Journal', 'Gazette', 'Telegraph', 'Chronicle', 'Press', 'Broadcasting', 'Daily', 'Wire' );

	private const NEW_PLACES = array( 'York', 'Jersey', 'Zealand', 'Delhi', 'Orleans', 'Mexico', 'Hampshire', 'England', 'South', 'Brunswick', 'Guinea', 'Caledonia', 'Haven' );

	/**
	 * Text with each date's month in lower case, so "November 19" or "19 June" is not read as a name.
	 * A month on its own keeps its capital, because "June" or "April" alone may be somebody's name.
	 */
	private static function undate( string $text ): string {
		$lower = static function ( array $match ): string {
			return mb_strtolower( $match[0] );
		};
		$text = (string) preg_replace_callback( '/(?<![\p{L}\p{N}])(?:' . self::MONTHS . ')(?=\s+\d{1,4}(?!\p{L}))/u', $lower, $text );
		return (string) preg_replace_callback( '/(?<=\d\s)(?:' . self::MONTHS . ')(?![\p{L}\p{N}])/u', $lower, $text );
	}

	/**
	 * Whether a tag or subject is written as a name: every word capitalized, a number, or a joining
	 * word such as "of". "Doha Film Institute" and "Sara Ishaq" are names. "Yemeni film" is a kind of
	 * thing, and was paired with "November" as a search in the owner's log of 2026-11-17.
	 */
	private static function looks_like_name( string $name ): bool {
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		if ( ! $words || ! preg_match( '/^\p{Lu}/u', (string) $words[0] ) ) {
			return false;
		}
		foreach ( $words as $word ) {
			if ( ! preg_match( '/^(\p{Lu}|\p{N})/u', $word ) && ! in_array( mb_strtolower( $word ), array( 'of', 'de', 'la', 'the', 'van', 'von', 'al', 'el', 'bin', 'and', '&' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function tokens( string $text ): array {
		$text = mb_strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		preg_match_all( '/[\p{L}\p{N}]{3,}/u', $text, $match );
		// A number alone, such as "000" from "10,000" or a year, finds unrelated files.
		$words = array_filter(
			$match[0],
			static function ( $word ) {
				return ! preg_match( '/^\p{N}+$/u', $word );
			}
		);
		return array_values( array_diff( $words, self::STOP ) );
	}

	/**
	 * A name without a leading "The", for comparing outlet names.
	 */
	private static function bare( string $name ): string {
		return mb_strtolower( trim( (string) preg_replace( '/^the\s+/iu', '', trim( $name ) ) ) );
	}

	/**
	 * Whether a name is the publisher's, or looks like a news outlet's, such as "Nippon News Network".
	 */
	private static function is_outlet( string $name, string $publisher ): bool {
		if ( '' !== trim( $publisher ) && self::bare( $name ) === self::bare( $publisher ) ) {
			return true;
		}
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		return count( $words ) >= 2 && in_array( end( $words ), self::OUTLET_WORDS, true );
	}

	/**
	 * Whether a capitalized word only begins a sentence or heading. A short word keeps its full stop
	 * inside a run, as "No." does in "No. Nvidia", so the stop is ignored. An initial such as "A." in
	 * "A. Lange" is part of a name and is left alone.
	 */
	private static function not_a_name( string $word ): bool {
		if ( preg_match( '/^\p{Lu}\.$/u', $word ) ) {
			return false;
		}
		return in_array( rtrim( $word, '.' ), self::NOT_NAMES, true );
	}

	/**
	 * Runs of capitalized words: the likely people, companies, products and places.
	 */
	private static function names( string $text ): array {
		$found = array();
		// "and" is deliberately not a connector. "Ghana and China" is two subjects, not one name, and
		// searching Commons for the whole phrase finds nothing. The genuine name particles stay, so
		// "Bank of the West" and "Ludwig van Beethoven" are still read as one name.
		preg_match_all( '/\p{Lu}[\p{L}\p{N}\'’&.\-]*(?:\s+(?:(?:of|de|la|the|van|von)\s+)?\p{Lu}[\p{L}\p{N}\'’&.\-]*)*/u', $text, $match );
		foreach ( $match[0] as $phrase ) {
			// "Apple. What" is the end of one sentence and the start of the next. A longer word with
			// a full stop ends the name, while "U.S." and "St." do not.
			$groups = array( array() );
			foreach ( preg_split( '/\s+/u', trim( $phrase ) ) ?: array() as $word ) {
				$groups[ count( $groups ) - 1 ][] = $word;
				// A longer word with a full stop ends the name. So does a short run of capitals,
				// because "the UFC. White has run" is two sentences, not a name called "UFC. White".
				// "U.S." and "St." are not ended: the first has inner stops, the second is not capitals.
				$sentence_end = preg_match( '/^[\p{L}\p{N}\'’&\-]{4,}\.$/u', $word )
					|| preg_match( '/^\p{Lu}{2,}\.$/u', $word );
				if ( $sentence_end ) {
					$groups[] = array();
				}
			}
			foreach ( $groups as $group ) {
				$words = preg_split( '/\s+/u', trim( implode( ' ', $group ), " .'’-" ) ) ?: array();
				while ( $words && self::not_a_name( $words[0] ) ) {
					// "New" is part of a place name in "New York", but only a describing word in "New iPhone".
					if ( 'New' === $words[0] && isset( $words[1] ) && in_array( $words[1], self::NEW_PLACES, true ) ) {
						break;
					}
					array_shift( $words );
				}
				while ( $words && self::not_a_name( (string) end( $words ) ) ) {
					array_pop( $words );
				}
				// "Russell Brand's" names Russell Brand.
				$clean = (string) preg_replace( '/[\'’]s$/u', '', implode( ' ', $words ) );
				if ( mb_strlen( $clean ) >= 3 ) {
					$found[] = $clean;
				}
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
			// In an ordinary sentence the first word has a capital anyway. On its own it counts as a name only
			// when the running text also has it, as in "Apple sells…" but not "Man arrested…".
			if ( $runs && $known && false === strpos( $runs[0], ' ' ) && 0 === mb_strpos( ltrim( $text ), $runs[0] ) && ! in_array( $runs[0], $known, true ) ) {
				array_shift( $runs );
			}
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
		// With no running text to check against, as with a headline alone, a short run is kept as it is.
		if ( ! $known && count( $words ) <= 2 ) {
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
		// In Title Case every word has a capital, so capitals say nothing. When the article's own
		// text and tags confirm none of these words as a name, they are not one: "Sales Rose",
		// "Remake Gets" and "Formal Cooperation" were each searched for on Commons (1.11.0).
		if ( $known ) {
			return array();
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
	 * Words that describe what somebody is, not who they are. Commons matches every word of a
	 * search, so "President Donald Trump" asks for files labelled with the office as well as the
	 * man, and finds fewer of him than "Donald Trump" does.
	 */
	private const HONORIFICS = array(
		'president', 'vice', 'prime', 'minister', 'chancellor', 'senator', 'sen', 'representative',
		'rep', 'congressman', 'congresswoman', 'governor', 'gov', 'mayor', 'secretary', 'ambassador',
		'commissioner', 'chief', 'sheriff', 'detective', 'officer', 'captain', 'general', 'colonel',
		'sergeant', 'judge', 'justice', 'professor', 'prof', 'doctor', 'dr', 'mr', 'mrs', 'ms',
		'miss', 'sir', 'dame', 'lord', 'lady', 'king', 'queen', 'prince', 'princess', 'pope',
		'cardinal', 'bishop', 'coach', 'manager', 'boss', 'founder', 'ceo', 'chairman', 'chairwoman',
		'director', 'star', 'actor', 'actress', 'singer', 'rapper', 'comedian', 'author',
		'journalist', 'presenter', 'host', 'billionaire', 'tycoon', 'mogul',
	);

	/**
	 * A name with the describing words taken out, so it names only the person.
	 * "President Donald Trump" becomes "Donald Trump". A name made only of such words is left alone.
	 */
	private static function strip_honorifics( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$kept  = array();
		foreach ( $words as $word ) {
			if ( ! in_array( mb_strtolower( rtrim( $word, '.' ) ), self::HONORIFICS, true ) ) {
				$kept[] = $word;
			}
		}
		return $kept ? implode( ' ', $kept ) : trim( $name );
	}

	/** Whether two names share any whole word, as "Dana White" and "White House" share "White". */
	private static function shares_a_word( string $one, string $two ): bool {
		foreach ( preg_split( '/\s+/u', $one ) ?: array() as $word ) {
			if ( mb_strlen( $word ) >= 3 && preg_match( self::word_pattern( $word, 'iu' ), $two ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The two subjects the headline names, as one search.
	 *
	 * A story about two people is best illustrated by a picture of the two of them, and that is
	 * what a reader expects, so this is searched before anything else. Three things keep it honest:
	 *
	 * - The tags are read before the subjects, because a tag gives a name in full where news writing
	 *   does not: the headline says "Trump" where the tag says "Donald Trump". A tag counts when its
	 *   last word, the surname, is in the title or headline.
	 * - Describing words are dropped, because Commons matches every word of a search.
	 * - Two names that share a word are never paired. "Dana White" and "White House" are not two
	 *   subjects but one subject and a building that happens to repeat its word, and pairing them
	 *   would ask Commons for a photograph that cannot exist.
	 *
	 * Only a name the article itself vouches for may go in: the main subject, or one of the
	 * generated tags. A capitalised run from the body such as "Finds AI", which is a verb and an
	 * abbreviation rather than anybody's name, is not paired with anything. A tag written in lower
	 * case is a subject and not a name, so "customer service" is searched on its own terms and
	 * never demanded alongside something else.
	 *
	 * @param string[] $entities  Subjects, best first.
	 * @param string[] $tags      Generated tags.
	 * @param string   $lines     Title and source headline.
	 * @param string   $publisher The outlet that published the story.
	 * @param string   $main      The main subject, which needs no tag to vouch for it.
	 * @return string Empty unless the headline names two separate subjects, one of them a full name.
	 */
	private static function headline_pair( array $entities, array $tags, string $lines, string $publisher, string $main ): string {
		$vouched = array();
		$concept = array();
		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' === $tag ) {
				continue;
			}
			$vouched[ mb_strtolower( $tag ) ] = true;
			if ( $tag === mb_strtolower( $tag ) ) {
				$concept[ mb_strtolower( $tag ) ] = true;
			}
		}

		$candidates = array();
		foreach ( array_merge( $tags, $entities ) as $name ) {
			$name  = self::strip_honorifics( trim( (string) $name ) );
			$words = preg_split( '/\s+/u', $name ) ?: array();
			$last  = (string) end( $words );
			$key   = mb_strtolower( $name );
			if ( mb_strlen( $name ) < 3 || isset( $candidates[ $key ] ) || self::is_outlet( $name, $publisher ) ) {
				continue;
			}
			if ( isset( $concept[ $key ] ) || ( ! isset( $vouched[ $key ] ) && 0 !== strcasecmp( $name, $main ) ) ) {
				continue;
			}
			// A name, not an ordinary word, and one the title or headline really refers to.
			if ( ! self::looks_like_name( $name ) || ! preg_match( self::word_pattern( $last, 'iu' ), $lines ) ) {
				continue;
			}
			$at                  = mb_stripos( $lines, $last );
			$candidates[ $key ] = array(
				'name'  => $name,
				'at'    => false === $at ? PHP_INT_MAX : $at,
				'words' => count( $words ),
			);
		}

		// Full names first, then whichever the headline names earliest. A story names its people
		// before it names the things around them, so headline order is the article's own ranking.
		$order = array_values( $candidates );
		usort(
			$order,
			static function ( array $a, array $b ) {
				$one = ( $b['words'] > 1 ? 1 : 0 ) <=> ( $a['words'] > 1 ? 1 : 0 );
				return 0 !== $one ? $one : $a['at'] <=> $b['at'];
			}
		);

		$pair = array();
		foreach ( $order as $row ) {
			foreach ( $pair as $taken ) {
				if ( self::shares_a_word( $row['name'], $taken ) ) {
					continue 2;
				}
			}
			$pair[] = $row['name'];
			if ( 2 === count( $pair ) ) {
				break;
			}
		}

		// One of the two has to be a full name of its own. Two bare words, such as "Ghana" and
		// "China", are better searched one at a time than demanded together.
		$full = false;
		foreach ( $pair as $name ) {
			$full = $full || false !== strpos( $name, ' ' );
		}
		return 2 === count( $pair ) && $full ? implode( ' ', $pair ) : '';
	}
	/**
	 * A one-word subject with a word of context, for searching.
	 *
	 * The context is the first tag that is one capitalized word, other than the article's subjects,
	 * that the article itself uses, such as "Xbox" for "Halo". Without one, a longer tag that contains
	 * the word, such as "Halo Studios", is used instead.
	 *
	 * @param string[] $tags  Generated tags, in their order.
	 * @param string[] $names The main and second subjects.
	 * @return string Empty when the article offers no context.
	 */
	private static function with_context( string $word, array $tags, array $names, string $article_text, string $publisher ): string {
		$names_lc = array_map( 'mb_strtolower', $names );
		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( false !== strpos( $tag, ' ' ) || mb_strlen( $tag ) < 3 || ! preg_match( '/^\p{Lu}/u', $tag ) ) {
				continue;
			}
			if ( in_array( mb_strtolower( $tag ), $names_lc, true ) || self::is_outlet( $tag, $publisher ) || in_array( mb_strtolower( $tag ), self::STOP, true ) ) {
				continue;
			}
			if ( preg_match( self::word_pattern( $tag, 'iu' ), $article_text ) ) {
				return $word . ' ' . $tag;
			}
		}
		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( false !== strpos( $tag, ' ' ) && preg_match( self::word_pattern( $word, 'iu' ), $tag ) && preg_match( '/^\p{Lu}/u', $tag )
				&& ! self::is_outlet( $tag, $publisher ) && preg_match( self::word_pattern( $tag, 'iu' ), $article_text ) ) {
				return $tag;
			}
		}
		return '';
	}

	/** A name for a search, as a phrase when it has more than one word. */
	private static function quoted( string $name ): string {
		return false !== strpos( $name, ' ' ) ? '"' . $name . '"' : $name;
	}

	/**
	 * Where the story happens: the subject or tag the article most often says something is "in",
	 * as in "events in Doha". Empty when the article never says.
	 *
	 * @param string[] $entities Subjects, best first.
	 * @param string[] $tags     Generated tags.
	 */
	private static function place( array $entities, array $tags, string $plain_body, string $publisher ): string {
		$best  = '';
		$count = 0;
		foreach ( array_merge( $entities, $tags ) as $name ) {
			$name = trim( (string) $name );
			if ( mb_strlen( $name ) < 3 || ! self::looks_like_name( $name ) || self::is_outlet( $name, $publisher ) || substr_count( $name, ' ' ) > 2 ) {
				continue;
			}
			// "in Halo 3" or "in Halo: Reach" is a game, not a place, so a number or a colon and a
			// title after it does not count. "in Doha: the festival…" still does.
			$found = (int) preg_match_all( '/(?<![\p{L}\p{N}])in\s+(?:the\s+)?' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}]|\s*\d|:\s*\p{Lu})/u', $plain_body );
			// Said twice at least, so one passing phrase does not make a place. More mentions win.
			// Between equals, the shorter name is more likely the place itself.
			if ( $found < 2 ) {
				continue;
			}
			if ( $found > $count || ( $found === $count && mb_strlen( $name ) < mb_strlen( $best ) ) ) {
				$best  = $name;
				$count = $found;
			}
		}
		return $best;
	}

	/**
	 * @param string   $title    Generated title.
	 * @param string   $body     Generated body HTML.
	 * @param string[] $tags     Generated tags.
	 * @param string   $headline Source headline.
	 * @param string   $category  News category name.
	 * @param string   $publisher The outlet that published the story, when known.
	 * @return array{main:string, entities:string[], keywords:string[], concepts:string[], queries:string[]}
	 */
	public static function analyze( string $title, string $body, array $tags, string $headline, string $category, string $publisher = '' ): array {
		$title      = self::undate( $title );
		$headline   = self::undate( $headline );
		$body       = self::undate( $body );
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
		// A name never runs from one paragraph, list item or table cell into the next. Without a break
		// here, a cell that says "Check" beside a cell that begins "Nvidia" reads as a name, "Check Nvidia".
		$prose_html = (string) preg_replace( '#</(?:p|li|td|th|tr|dt|dd|div|blockquote|caption|figcaption)\s*>|<br\s*/?>#i', ' . ', $prose_html );
		$prose = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $prose_html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Preview image search passes the headline as the first line of the text.
		$prose       = str_ireplace( array_values( array_filter( array( $title_text, $head_text ), 'strlen' ) ), ' . ', $prose );
		$prose_names = self::names( $prose );
		$known       = array_values( array_unique( $prose_names ) );
		// A tag is a subject the article vouches for, so it confirms a headline's words too. It is
		// taken as the title or headline writes it, so the tag "customer service" confirms "Customer
		// Service" in a Title Case headline. A tag the headline does not use counts when it has a capital.
		$lines = $title_text . ' . ' . $head_text;
		foreach ( $known ? $tags : array() as $tag ) {
			$tag = trim( (string) $tag );
			if ( mb_strlen( $tag ) < 3 ) {
				continue;
			}
			if ( preg_match( self::word_pattern( $tag, 'iu' ), $lines, $written ) ) {
				$tag = $written[0];
			}
			if ( preg_match( '/^\p{Lu}/u', $tag ) && ! in_array( $tag, $known, true ) ) {
				$known[] = $tag;
			}
		}

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
		// The publisher, or an outlet the article quotes, is not what it is about. It stays when the title or
		// headline names it, as in a story about that outlet. This runs first, so "Tokyo" is not counted toward
		// "Tokyo Reporter" and lost with it.
		$lines_lc = mb_strtolower( $title_text . ' . ' . $head_text );
		foreach ( array_keys( $scores ) as $name ) {
			$name = (string) $name;
			if ( self::is_outlet( $name, $publisher ) && false === mb_strpos( $lines_lc, self::bare( $name ) ) ) {
				unset( $scores[ $name ] );
			}
		}
		// A short form on its own, such as "Brand" for "Russell Brand", counts toward the full name.
		// Otherwise an article that mostly says "Brand" would search for the word "Brand".
		//
		// Which full name it belongs to has to be decided carefully. In a story about Dana White and
		// the White House, the word "White" appears in both, and crediting it to the wrong one both
		// inflates the wrong subject and destroys the evidence for the right one. So:
		//
		// - A short form that is the LAST word of a name is a surname, which is how English news
		//   refers back to a person: "Dana White ... White said". That beats a name where the word
		//   merely begins it, such as "White House".
		// - When the short form sits in the same position in two or more names, there is no way to
		//   tell which was meant, so it is left alone rather than guessed at.
		foreach ( array_keys( $scores ) as $short ) {
			$short = (string) $short;
			if ( false !== strpos( $short, ' ' ) ) {
				continue;
			}

			$surname = array();
			$other   = array();
			foreach ( array_keys( $scores ) as $long ) {
				$long = (string) $long;
				if ( false === strpos( $long, ' ' ) || ! preg_match( self::word_pattern( $short ), $long ) ) {
					continue;
				}
				$words = preg_split( '/\s+/u', $long ) ?: array();
				if ( 0 === strcasecmp( (string) end( $words ), $short ) ) {
					$surname[] = $long;
				} else {
					$other[] = $long;
				}
			}

			// A surname match wins outright. Only when there is none does a name that merely
			// contains the word count, and only when exactly one does.
			$candidates = $surname ?: $other;
			if ( 1 !== count( $candidates ) ) {
				continue;
			}

			$full            = $candidates[0];
			$scores[ $full ] += $scores[ $short ];
			unset( $scores[ $short ] );
		}

		// A run of four or more capitalized words is only a name when the title, the headline or a
		// tag says so. Left unchecked, a row of chart labels in the text, such as "Measure
		// Achievers Challengers Aspirants Share", becomes the article's subject and is searched
		// for as a phrase, which finds nothing at all. A genuine long name, such as a force or a
		// council the article is about, is named in the title, the headline or the tags.
		$confirming = mb_strtolower( $title_text . ' . ' . $head_text . ' . ' . implode( ' . ', $tags ) );
		foreach ( array_keys( $scores ) as $name ) {
			$name = (string) $name;
			if ( substr_count( trim( $name ), ' ' ) >= 3 && false === mb_strpos( $confirming, mb_strtolower( $name ) ) ) {
				unset( $scores[ $name ] );
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
			if ( '' === $main || false === strpos( $tag, ' ' ) || 0 === strcasecmp( $tag, $main ) || self::is_outlet( $tag, $publisher ) ) {
				continue;
			}
			$inside = preg_match( self::word_pattern( $tag, 'iu' ), $main ) || preg_match( self::word_pattern( $main, 'iu' ), $tag );
			// A tag that only adds a year, as "Doha Film Festival 2026" does, is not a cleaner name.
			// Commons labels an event by its name far more often than by one year of it.
			$extra = trim( (string) preg_replace( self::word_pattern( $main, 'iu' ), ' ', $tag ) );
			if ( $inside && '' !== $extra && preg_match( '/^[\p{N}\s]+$/u', $extra ) ) {
				continue;
			}
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

		// The owner's rule: search for the people and subjects the headline is actually about,
		// before anything drawn from the body. A picture of both of them is the most relevant one a
		// story about the two of them can have.
		$pair = self::headline_pair( $entities, $tags, $title_text . ' . ' . $head_text, $publisher, $main );
		if ( '' !== $pair ) {
			$queries[] = $pair;
		}
		if ( '' !== $main ) {
			$queries[] = $main;
			if ( '' !== $visual ) {
				$queries[] = $main . ' ' . $visual;
			}
			if ( '' !== $second ) {
				$queries[] = $main . ' ' . self::strip_honorifics( $second );
				$queries[] = self::strip_honorifics( $second );
			}
		}
		$names    = array_values( array_filter( array( $main, $second ), 'strlen' ) );
		$is_about = static function ( string $query ) use ( $names ): bool {
			foreach ( $names as $name ) {
				if ( false !== mb_stripos( $query, $name ) || false !== mb_stripos( $name, $query ) ) {
					return true;
				}
			}
			return false;
		};

		// Searches that do not rely on the two names. Other names first, such as a place, then a tag
		// from the title, then plain words. One of them always runs, so a wrong subject cannot use every search.
		$others = array();
		foreach ( array_slice( $entities, 1 ) as $entity ) {
			// As for the second name, a single word must be one of the tags.
			if ( false !== strpos( $entity, ' ' ) || in_array( mb_strtolower( $entity ), $tags_lc, true ) ) {
				$others[] = $entity;
			}
		}
		foreach ( $tags as $tag ) {
			$tag = (string) $tag;
			if ( false !== mb_stripos( $title_text, $tag ) && mb_strlen( $tag ) >= 4 && ! self::is_outlet( $tag, $publisher ) && ! $is_about( $tag ) ) {
				$queries[] = $tag;
				$others[]  = $tag;
				break;
			}
		}
		if ( $keywords ) {
			$queries[] = implode( ' ', array_slice( $keywords, 0, 4 ) );
			$queries[] = implode( ' ', array_slice( $keywords, 0, 2 ) ) . ' ' . $category;
		}
		$name_words = self::tokens( implode( ' ', $names ) );
		$plain      = array_values( array_diff( $keywords, $name_words ) );
		if ( $plain ) {
			$others[] = implode( ' ', array_slice( $plain, 0, 2 ) ) . ' ' . $category;
		}

		// A one-word subject is searched with a word of context, and on its own only last. Many
		// one-word names are also ordinary words, and Commons searches every sense of them: "Halo"
		// alone finds 350,000 files, mostly of light around the sun, while "Halo Xbox" finds the
		// game. "Firefight" alone finds firefighting. From the owner's log of 2026-11-15.
		$bare = array();
		foreach ( $queries as $index => $query ) {
			$query = trim( (string) $query );
			if ( false !== strpos( $query, ' ' ) || ! in_array( mb_strtolower( $query ), array_map( 'mb_strtolower', $names ), true ) ) {
				continue;
			}
			$longer = self::with_context( $query, $tags, $names, $article_text, $publisher );
			if ( '' !== $longer ) {
				$queries[ $index ] = $longer;
				$bare[]            = $query;
			}
		}
		$queries = array_merge( $queries, $bare );

		$clean  = static function ( string $query ): string {
			return trim( (string) preg_replace( '/[^\p{L}\p{N}\s\'’&.\-]+/u', ' ', $query ) );
		};
		$seen   = array();
		$unique = array();
		foreach ( $queries as $query ) {
			$query = $clean( (string) $query );
			// Commons matches every word in any order, so "Halo Xbox" and "Xbox Halo" are one search.
			$words = preg_split( '/\s+/u', mb_strtolower( $query ) ) ?: array();
			sort( $words );
			$key = implode( ' ', $words );
			if ( '' !== $query && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$unique[]     = $query;
			}
		}

		$limit  = (int) Settings::get( 'image_query_count' );
		$picked = array_slice( $unique, 0, $limit );
		if ( $names && count( $picked ) >= 2 && count( $picked ) === count( array_filter( $picked, $is_about ) ) ) {
			$picked_lc = array_map( 'mb_strtolower', $picked );
			foreach ( $others as $other ) {
				$other = $clean( (string) $other );
				if ( '' !== $other && ! $is_about( $other ) && ! in_array( mb_strtolower( $other ), $picked_lc, true ) ) {
					$picked[ count( $picked ) - 1 ] = $other;
					break;
				}
			}
		}

		// A name of two or more words is searched as a phrase. Commons otherwise matches the words
		// anywhere in a file's labels: "Sara Ishaq" found 244,000 files, mostly paintings of Isaac,
		// while "\"Sara Ishaq\"" finds the director (Commons, 2026-11-17). Longest names first, so
		// "Doha Film Institute" is quoted whole and not as "Doha Film" inside it.
		$phrases = array();
		foreach ( array_merge( $entities, array( $main ), $tags ) as $name ) {
			$name = trim( (string) $name );
			if ( false !== strpos( $name, ' ' ) && self::looks_like_name( $name ) && ! self::is_outlet( $name, $publisher ) ) {
				$phrases[ mb_strtolower( $name ) ] = $name;
			}
		}
		usort(
			$phrases,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		foreach ( $picked as $index => $query ) {
			foreach ( $phrases as $phrase ) {
				$query = (string) preg_replace( '/(?<!["\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?!["\p{L}\p{N}])/iu', '"$0"', $query, 1 );
			}
			$picked[ $index ] = $query;
		}

		$place = self::place( $entities, $tags, $plain_body, $publisher );

		return array(
			'main'     => $main,
			'entities' => $entities,
			'keywords' => $keywords,
			'concepts' => $concepts,
			'queries'  => $picked,
			// The tags, which name what the article is about in the article's own terms.
			'tags'     => array_values( array_filter( array_map( 'strval', $tags ), 'strlen' ) ),
			// Where the story happens, and searches for a general picture of it. Used only when no
			// picture of the subject itself can be used, so a post is not skipped for want of one.
			'place'    => $place,
			'fallback' => '' === $place ? array() : array( self::quoted( $place ) . ' skyline', self::quoted( $place ) . ' cityscape' ),
			// The article's own words, so a candidate can be checked against everything the
			// article actually says, not only against the handful of subjects drawn from it.
			// Bounded, because it is compared against for every file found.
			'text'     => mb_strtolower( mb_substr( $title_text . ' . ' . $head_text . ' . ' . $plain_body, 0, 20000 ) ),
		);
	}
}
