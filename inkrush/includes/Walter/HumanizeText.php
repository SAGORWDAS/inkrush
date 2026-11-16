<?php
namespace ZZZCopy\Walter;

defined( 'ABSPATH' ) || exit;

/**
 * The text side of humanizing: which paragraphs are sent to Walter, how they are grouped, how
 * Walter's text is cleaned, and the fact check that decides whether it may replace the original.
 *
 * Needs no database and no HTTP, so every rule here has a unit test.
 *
 * Why the checks are strict: in tests on 2026-11-16, Walter's humanizer, asked to keep numbers and
 * names, turned "next spring" into "this spring" and "Monday" into "yesterday", wrote "$12 million"
 * as "twelve million dollars", dropped a whole paragraph, dropped a real quote, and put an invented
 * quote in a mayor's mouth. It also ignored HTML and started its answer with "Beginning of Text".
 * So only plain paragraphs without quotes are sent, and a group whose facts did not all survive
 * keeps the AI's wording.
 */
final class HumanizeText {

	/** Walter refuses a piece under 50 words. A little margin, since it counts words its own way. */
	public const MIN_GROUP_WORDS = 60;

	/** Paragraphs are joined until a group has this many words. */
	public const TARGET_GROUP_WORDS = 130;

	/** A group never grows past this, so one bad answer costs little of the article. */
	public const MAX_GROUP_WORDS = 300;

	/** A paragraph shorter than this is a label, a source line or a short answer, and is left alone. */
	public const MIN_PARAGRAPH_WORDS = 15;

	private const TIME_WORDS = array(
		'today', 'yesterday', 'tomorrow', 'tonight', 'next', 'last', 'ago', 'previous', 'previously', 'earlier', 'later', 'soon', 'recently', 'currently', 'now',
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'weekend',
		'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
		'spring', 'summer', 'autumn', 'fall', 'winter', 'morning', 'afternoon', 'evening', 'night', 'week', 'month', 'year', 'decade',
	);

	private const NUMBER_WORDS = array(
		'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
		'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
		'hundred', 'hundreds', 'thousand', 'thousands', 'million', 'millions', 'billion', 'billions', 'trillion', 'dozen', 'dozens', 'half', 'quarter',
		'first', 'second', 'third', 'fourth', 'fifth', 'once', 'twice', 'double', 'triple', 'percent', 'per',
	);

	private const NEGATIONS = array( 'not', 'no', 'never', 'none', 'nobody', 'nothing', 'neither', 'nor', 'without', 'cannot', 'nowhere' );

	/** Who said it matters in news: a claim must stay attributed. One of these may replace another. */
	private const ATTRIBUTION = array( 'said', 'says', 'according', 'told', 'reported', 'reports', 'reportedly', 'announced', 'announces', 'stated', 'states', 'confirmed', 'confirms', 'added', 'explained', 'noted', 'wrote' );

	/** How certain a claim is. Each of these must stay. */
	private const HEDGES = array( 'alleged', 'allegedly', 'claimed', 'claims', 'denied', 'denies', 'estimated', 'expected', 'expects', 'plans', 'planned', 'may', 'might', 'could', 'would', 'will', 'should', 'likely', 'unlikely', 'possible', 'possibly', 'about', 'nearly', 'almost', 'around', 'approximately', 'roughly', 'least', 'more', 'less', 'fewer', 'over', 'under', 'up' );

	/** Words that start a sentence often enough that a capital there says nothing about a name. */
	private const LEAD_WORDS = array( 'the', 'a', 'an', 'this', 'that', 'these', 'those', 'it', 'its', 'in', 'on', 'at', 'for', 'and', 'but', 'or', 'as', 'with', 'by', 'from', 'after', 'before', 'while', 'when', 'if', 'he', 'she', 'they', 'we', 'i', 'you', 'there', 'their', 'his', 'her', 'our', 'some', 'many', 'most', 'more', 'other', 'others', 'one', 'both', 'each', 'still', 'also', 'however', 'meanwhile', 'officials', 'experts', 'critics', 'analysts', 'residents', 'people' );

	/**
	 * Splits an article body into top-level blocks.
	 *
	 * @return array<int, array{html:string, text:string, words:int, eligible:bool, why:string}>
	 *   Every piece of the body in order. Joining their html gives the body back exactly.
	 */
	public static function blocks( string $body ): array {
		$blocks = array();
		$offset = 0;
		if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array( self::other( $body ) );
		}
		foreach ( $matches as $match ) {
			$start = (int) $match[0][1];
			if ( $start > $offset ) {
				$blocks[] = self::other( substr( $body, $offset, $start - $offset ) );
			}
			$html  = $match[0][0];
			$inner = $match[1][0];
			$why   = self::not_eligible( $inner, substr( $body, 0, $start ) );
			$text  = self::plain( $inner );
			$blocks[] = array(
				'html'     => $html,
				'text'     => $text,
				'words'    => self::count_words( $text ),
				'eligible' => '' === $why,
				'why'      => $why,
			);
			$offset = $start + strlen( $html );
		}
		if ( $offset < strlen( $body ) ) {
			$blocks[] = self::other( substr( $body, $offset ) );
		}
		return $blocks;
	}

	private static function other( string $html ): array {
		return array(
			'html'     => $html,
			'text'     => '',
			'words'    => 0,
			'eligible' => false,
			'why'      => 'not a paragraph',
		);
	}

	/**
	 * Why a paragraph is kept exactly as written. Empty when it can be sent.
	 *
	 * @param string $inner  The paragraph's inner HTML.
	 * @param string $before Everything in the body before it, to see what it sits inside.
	 */
	public static function not_eligible( string $inner, string $before ): string {
		foreach ( array( 'blockquote', 'li', 'table', 'figure', 'aside', 'details' ) as $tag ) {
			$open  = preg_match_all( '/<' . $tag . '\b/i', $before );
			$close = preg_match_all( '/<\/' . $tag . '\s*>/i', $before );
			if ( $open > $close ) {
				return 'inside a ' . $tag;
			}
		}
		if ( false !== strpos( $inner, '<' ) ) {
			return 'has links or formatting';
		}
		$text = self::plain( $inner );
		if ( preg_match( '/["“”«»]/u', $text ) ) {
			return 'has a quote';
		}
		if ( preg_match( '/https?:|www\./i', $text ) ) {
			return 'has an address';
		}
		if ( preg_match( '/^(source|sources|read more|related|editor\'s note|update|q\.|a\.|q:|a:)\b/i', $text ) ) {
			return 'a label or source line';
		}
		if ( self::count_words( $text ) < self::MIN_PARAGRAPH_WORDS ) {
			return 'too short';
		}
		return '';
	}

	/**
	 * Groups paragraphs that sit next to each other, so each piece sent has at least 60 words.
	 *
	 * @return array<int, int[]> Lists of block indexes. A paragraph that cannot reach 60 words with
	 *                           its neighbours is in no group and stays as written.
	 */
	public static function groups( array $blocks ): array {
		$groups = array();
		$sizes  = array();
		$cur    = array();
		$words  = 0;
		$touch  = false; // Whether the group being built follows straight on from the last one.

		$flush = static function ( bool $next_touches ) use ( &$groups, &$sizes, &$cur, &$words, &$touch ): void {
			if ( $cur ) {
				$last = count( $groups ) - 1;
				if ( $words >= self::MIN_GROUP_WORDS ) {
					$groups[] = $cur;
					$sizes[]  = $words;
				} elseif ( $touch && $last >= 0 && $sizes[ $last ] + $words <= self::MAX_GROUP_WORDS ) {
					$groups[ $last ] = array_merge( $groups[ $last ], $cur );
					$sizes[ $last ] += $words;
				}
			}
			$cur   = array();
			$words = 0;
			$touch = $next_touches;
		};

		foreach ( $blocks as $index => $block ) {
			if ( ! $block['eligible'] ) {
				if ( '' !== trim( (string) $block['html'] ) ) {
					$flush( false );
				}
				continue; // Whitespace between paragraphs does not separate them.
			}
			if ( $cur && ( $words + (int) $block['words'] > self::MAX_GROUP_WORDS || $words >= self::TARGET_GROUP_WORDS ) ) {
				$flush( true );
			}
			$cur[]  = $index;
			$words += (int) $block['words'];
		}
		$flush( false );
		return $groups;
	}

	/** The plain text of a group, paragraphs separated by a blank line. */
	public static function group_text( array $blocks, array $group ): string {
		$parts = array();
		foreach ( $group as $index ) {
			$parts[] = $blocks[ $index ]['text'];
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Walter's answer as plain paragraphs, or an empty list when nothing usable came back.
	 *
	 * @return string[]
	 */
	public static function clean( string $output ): array {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]+>/', "\n\n", $output ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		// Seen on 2026-11-16: "Beginning of Text" in front of the answer.
		$text = (string) preg_replace( '/^\s*(beginning|start) of (the )?text\s*[:.\-]?\s*/i', '', $text );
		$text = (string) preg_replace( '/\s*(end) of (the )?text\s*[:.]?\s*$/i', '', $text );
		// Markdown emphasis and heading marks.
		$text = (string) preg_replace( '/(\*\*|__)(.+?)\1/u', '$2', $text );
		$text = (string) preg_replace( '/^\s{0,3}#{1,6}\s+/mu', '', $text );

		$out = array();
		foreach ( preg_split( '/\n\s*\n/u', $text ) as $paragraph ) {
			$paragraph = trim( (string) preg_replace( '/\s+/u', ' ', $paragraph ) );
			if ( '' !== $paragraph ) {
				$out[] = $paragraph;
			}
		}
		return $out;
	}

	/**
	 * Whether Walter's version may replace the original.
	 *
	 * @param string $original  The plain text sent.
	 * @param string $rewritten The cleaned text that came back, paragraphs joined by blank lines.
	 * @param string $article   The whole original article as plain text, to tell a name the article
	 *                          already uses from one Walter made up.
	 * @return string Why it may not. Empty when it may.
	 */
	public static function fact_check( string $original, string $rewritten, string $article = '' ): string {
		$a = self::count_words( $original );
		$b = self::count_words( $rewritten );
		if ( $b < 1 ) {
			return 'nothing came back';
		}
		$ratio = $b / max( 1, $a );
		if ( $ratio < 0.75 ) {
			return sprintf( 'too much was left out (%1$d words became %2$d)', $a, $b );
		}
		if ( $ratio > 1.4 ) {
			return sprintf( 'too much was added (%1$d words became %2$d)', $a, $b );
		}

		// Only paragraphs without quotes are sent, so any quote in the answer was made up.
		if ( preg_match( '/["“”«»]/u', $rewritten ) ) {
			return 'a quote was added';
		}

		$missing = self::missing( self::numbers( $original ), self::numbers( $rewritten ) );
		if ( $missing ) {
			return 'a number changed or went missing: ' . implode( ', ', array_slice( $missing, 0, 3 ) );
		}
		$added = self::missing( self::numbers( $rewritten ), self::numbers( $original ) );
		if ( $added ) {
			return 'a number was added: ' . implode( ', ', array_slice( $added, 0, 3 ) );
		}

		foreach ( array( 'time' => self::TIME_WORDS, 'number' => self::NUMBER_WORDS ) as $label => $list ) {
			$before = self::words_from( $original, $list );
			$after  = self::words_from( $rewritten, $list );
			$gone   = self::missing( $before, $after );
			if ( $gone ) {
				return sprintf( 'a %1$s word changed or went missing: %2$s', $label, implode( ', ', array_slice( $gone, 0, 3 ) ) );
			}
			$new = array_values( array_diff( array_unique( $after ), $before ) );
			if ( $new ) {
				return sprintf( 'a %1$s word was added: %2$s', $label, implode( ', ', array_slice( $new, 0, 3 ) ) );
			}
		}

		if ( count( self::words_from( $original, self::NEGATIONS ) ) + self::count_nt( $original ) !== count( self::words_from( $rewritten, self::NEGATIONS ) ) + self::count_nt( $rewritten ) ) {
			return 'a "not" or "no" was added or removed, which can turn the meaning around';
		}

		if ( count( self::words_from( $rewritten, self::ATTRIBUTION ) ) < count( self::words_from( $original, self::ATTRIBUTION ) ) ) {
			return 'a "said" went missing, so a claim may now read as fact';
		}
		$hedges = array_values( array_diff( array_unique( self::words_from( $original, self::HEDGES ) ), self::words_from( $rewritten, self::HEDGES ) ) );
		if ( $hedges ) {
			return 'how certain it is changed: "' . implode( '", "', array_slice( $hedges, 0, 3 ) ) . '" went missing';
		}

		$names = self::missing_names( $original, $rewritten );
		if ( $names ) {
			return 'a name went missing: ' . implode( ', ', array_slice( $names, 0, 3 ) );
		}
		$invented = self::invented_names( $rewritten, '' !== $article ? $article : $original );
		if ( $invented ) {
			return 'a name was added that the article does not use: ' . implode( ', ', array_slice( $invented, 0, 3 ) );
		}

		if ( preg_match( '/https?:|www\./i', $rewritten ) ) {
			return 'an address was added';
		}
		if ( false !== strpos( $rewritten, '—' ) ) {
			return 'it uses the "—" dash this site does not allow';
		}
		if ( preg_match( '/\b(as an ai|humaniz|rewritten text|here is|here\'s the)\b/i', $rewritten ) ) {
			return 'it talks about the rewriting instead of the news';
		}
		return '';
	}

	/** Figures, normalised: "$12 million" gives "12", "1,500" gives "1500", "3.5%" gives "3.5". */
	public static function numbers( string $text ): array {
		preg_match_all( '/\d+(?:[.,]\d+)*/u', $text, $m );
		$out = array();
		foreach ( $m[0] as $number ) {
			// A comma or point followed by exactly three digits groups thousands; any other point is a decimal.
			$number = (string) preg_replace( '/[.,](?=\d{3}(?!\d))/', '', $number );
			$out[]  = str_replace( ',', '.', $number );
		}
		return $out;
	}

	/** Every listed word in the text, lower case, each time it appears. */
	private static function words_from( string $text, array $list ): array {
		preg_match_all( "/[\p{L}']+/u", mb_strtolower( $text ), $m );
		return array_values( array_intersect( $m[0], $list ) );
	}

	private static function count_nt( string $text ): int {
		return (int) preg_match_all( "/n['’]t\b/iu", $text );
	}

	/** Items of $need not found in $have, counting repeats. */
	private static function missing( array $need, array $have ): array {
		$left = array_count_values( $have );
		$out  = array();
		foreach ( $need as $item ) {
			if ( ! empty( $left[ $item ] ) ) {
				--$left[ $item ];
			} else {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Capitalised words that are names, not just the first word of a sentence.
	 *
	 * @return string[]
	 */
	public static function names( string $text ): array {
		$out = array();
		foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) as $sentence ) {
			preg_match_all( "/\b\p{Lu}[\p{L}'’\-]*/u", (string) $sentence, $m, PREG_OFFSET_CAPTURE );
			foreach ( $m[0] as $hit ) {
				$word = rtrim( (string) $hit[0], "'’-" );
				if ( mb_strlen( $word ) < 2 ) {
					continue;
				}
				if ( 0 === (int) $hit[1] && ( in_array( mb_strtolower( $word ), self::LEAD_WORDS, true ) || ! self::looks_like_name( $word, $text ) ) ) {
					continue;
				}
				$out[] = $word;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** A sentence's first word is a name when the text also uses it capitalised mid-sentence, or it is all capitals. */
	private static function looks_like_name( string $word, string $text ): bool {
		if ( mb_strtoupper( $word ) === $word ) {
			return true;
		}
		return (bool) preg_match( '/[^.!?]\s+' . preg_quote( $word, '/' ) . '\b/u', $text ) && ! in_array( mb_strtolower( $word ), self::TIME_WORDS, true );
	}

	private static function missing_names( string $original, string $rewritten ): array {
		$out = array();
		foreach ( self::names( $original ) as $name ) {
			if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/u', $rewritten ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	private static function invented_names( string $rewritten, string $source ): array {
		$out = array();
		foreach ( self::names( $rewritten ) as $name ) {
			if ( in_array( mb_strtolower( $name ), self::TIME_WORDS, true ) ) {
				continue;
			}
			if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/iu', $source ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Puts accepted groups back into the body.
	 *
	 * @param array $blocks   From blocks().
	 * @param array $accepted Group's first block index => [ block indexes, cleaned paragraphs ].
	 */
	public static function rebuild( array $blocks, array $accepted ): string {
		$skip = array();
		$html = '';
		foreach ( $blocks as $index => $block ) {
			if ( isset( $skip[ $index ] ) ) {
				continue;
			}
			if ( isset( $accepted[ $index ] ) ) {
				list( $group, $paragraphs ) = $accepted[ $index ];
				foreach ( $group as $member ) {
					$skip[ $member ] = true;
				}
				$parts = array();
				foreach ( $paragraphs as $paragraph ) {
					$parts[] = '<p>' . htmlspecialchars( $paragraph, ENT_NOQUOTES, 'UTF-8' ) . '</p>';
				}
				$html .= implode( "\n", $parts );
				// The new paragraphs take the first one's place; the whitespace between the old ones stays.
				continue;
			}
			$html .= $block['html'];
		}
		return $html;
	}

	/**
	 * Walter's paragraphs for a group: its own split when the count matches, otherwise one per
	 * original paragraph by sentences, or all in one when there are too few sentences.
	 *
	 * @return string[]
	 */
	public static function fit_paragraphs( array $paragraphs, int $wanted ): array {
		if ( count( $paragraphs ) === $wanted || $wanted < 2 ) {
			return $wanted < 2 ? array( implode( ' ', $paragraphs ) ) : $paragraphs;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+(?=\p{Lu})/u', implode( ' ', $paragraphs ) );
		if ( count( $sentences ) < $wanted ) {
			return array( implode( ' ', $paragraphs ) );
		}
		$per = (int) ceil( count( $sentences ) / $wanted );
		$out = array();
		foreach ( array_chunk( $sentences, $per ) as $chunk ) {
			$out[] = implode( ' ', $chunk );
		}
		return $out;
	}

	public static function plain( string $html ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]+>/', ' ', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function count_words( string $text ): int {
		return (int) preg_match_all( "/[\p{L}\p{N}][\p{L}\p{N}'’.,\-]*/u", $text );
	}
}
