<?php
namespace ZZZCopy\Walter;

defined( 'ABSPATH' ) || exit;

/**
 * The text side of humanizing: which parts of the article are sent to Walter, how they are put
 * together, how the AI's fact repair is asked for and read, the fact check that decides whether a
 * part may replace the original, and putting the article back together.
 *
 * Needs no database and no HTTP, so every rule here has a unit test.
 *
 * Since 1.12.2 (owner's request: "every words, heading, all will be humanized") the whole article
 * goes to Walter: the title, headings, list items, table cells (since 1.12.3) and paragraphs, quotes and links included. In tests
 * on 2026-11-16 Walter changed facts in every section ("46 residents" became "over 40 citizens",
 * details and a quote were made up), so its answer then goes to the site's own AI model, which is
 * asked to keep Walter's wording and put every fact back as the original had it. Each part is then
 * checked here on its own, and a part that still differs keeps the AI's wording.
 */
final class HumanizeText {

	/** Walter refuses a piece under 50 words. A little margin, since it counts words its own way. */
	public const MIN_ITEM_WORDS = 55;

	/** A piece is cut at the next heading once it has this many words. */
	public const TARGET_ITEM_WORDS = 350;

	/** A piece never grows past this, so it fits Walter's per-request limit with others. */
	public const MAX_ITEM_WORDS = 700;

	/** Parts this short (in words) may change length more: "Paying for the park" can become "How the park is paid for". */
	private const SHORT_PART_WORDS = 12;

	private const INLINE_TAGS = 'a|strong|em|b|i';

	private const TIME_WORDS = array(
		'today', 'yesterday', 'tomorrow', 'tonight', 'next', 'last', 'ago', 'previous', 'previously', 'earlier', 'later', 'soon', 'recently', 'currently', 'now',
		'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'weekend',
		'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
		'spring', 'summer', 'autumn', 'fall', 'winter', 'morning', 'afternoon', 'evening', 'night', 'week', 'month', 'year', 'decade',
	);

	/** Small numbers written as words count as their figures: Walter wrote "2 million" as "two million". */
	private const DIGIT_WORDS = array(
		'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
		'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'thirteen' => '13', 'fourteen' => '14', 'fifteen' => '15', 'sixteen' => '16', 'seventeen' => '17',
		'eighteen' => '18', 'nineteen' => '19', 'twenty' => '20', 'thirty' => '30', 'forty' => '40', 'fifty' => '50', 'sixty' => '60', 'seventy' => '70',
		'eighty' => '80', 'ninety' => '90',
	);

	private const NUMBER_WORDS = array(
		'hundred', 'hundreds', 'thousand', 'thousands', 'million', 'millions', 'billion', 'billions', 'trillion', 'dozen', 'dozens', 'half', 'quarter',
		'first', 'second', 'third', 'fourth', 'fifth', 'once', 'twice', 'double', 'triple', 'percent', 'per',
	);

	private const NEGATIONS = array( 'not', 'no', 'never', 'none', 'nobody', 'nothing', 'neither', 'nor', 'without', 'cannot', 'nowhere' );

	/** Who said it matters in news: a claim must stay attributed. One of these may replace another. */
	private const ATTRIBUTION = array( 'said', 'says', 'according', 'told', 'reported', 'reports', 'reportedly', 'announced', 'announces', 'stated', 'states', 'confirmed', 'confirms', 'added', 'explained', 'noted', 'wrote' );

	/**
	 * How certain a claim is. Each kind must stay; a word may give way to another of the same kind
	 * ("about" to "approximately").
	 */
	private const HEDGES = array(
		'alleged' => 'alleged', 'allegedly' => 'alleged', 'claimed' => 'claimed', 'claims' => 'claimed', 'denied' => 'denied', 'denies' => 'denied',
		'estimated' => 'estimated', 'estimate' => 'estimated', 'estimates' => 'estimated', 'expected' => 'expected', 'expects' => 'expected', 'expect' => 'expected',
		'plans' => 'plan', 'planned' => 'plan', 'plan' => 'plan', 'scheduled' => 'plan',
		'may' => 'may', 'might' => 'may', 'could' => 'may', 'possible' => 'may', 'possibly' => 'may',
		'would' => 'would', 'will' => 'will', 'should' => 'should', 'likely' => 'likely', 'unlikely' => 'unlikely',
		'about' => 'about', 'around' => 'about', 'approximately' => 'about', 'roughly' => 'about',
		'nearly' => 'nearly', 'almost' => 'nearly', 'least' => 'least', 'more' => 'more', 'over' => 'more', 'less' => 'less', 'fewer' => 'less', 'under' => 'less',
	);

	/** Words that start a sentence often enough that a capital there says nothing about a name. */
	private const LEAD_WORDS = array( 'the', 'a', 'an', 'this', 'that', 'these', 'those', 'it', 'its', 'in', 'on', 'at', 'for', 'and', 'but', 'or', 'as', 'with', 'by', 'from', 'after', 'before', 'while', 'when', 'if', 'he', 'she', 'they', 'we', 'i', 'you', 'there', 'their', 'his', 'her', 'our', 'some', 'many', 'most', 'more', 'other', 'others', 'one', 'both', 'each', 'still', 'also', 'however', 'meanwhile', 'officials', 'experts', 'critics', 'analysts', 'residents', 'people', 'how', 'what', 'why', 'who', 'where', 'which', 'is', 'are', 'can', 'will', 'does', 'do' );

	/**
	 * Splits an article body into blocks: paragraphs, headings and list items, and everything else.
	 *
	 * @return array<int, array{html:string, open:string, tag:string, kind:string, text:string, words:int, eligible:bool, why:string, inline:array}>
	 *   Every piece of the body in order. Joining their html gives the body back exactly.
	 */
	public static function blocks( string $body ): array {
		$blocks = array();
		$offset = 0;
		if ( ! preg_match_all( '/(<(p|h[1-6]|li|td|th)\b[^>]*>)(.*?)<\/\2\s*>/is', $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array( self::other( $body ) );
		}
		foreach ( $matches as $match ) {
			$start = (int) $match[0][1];
			if ( $start < $offset ) {
				continue; // Inside a block already taken, such as a paragraph in a list item.
			}
			if ( $start > $offset ) {
				$blocks[] = self::other( substr( $body, $offset, $start - $offset ) );
			}
			$html   = $match[0][0];
			$tag    = strtolower( $match[2][0] );
			$inner  = $match[3][0];
			$inline = self::inline( $inner );
			$text   = self::plain( $inner );
			$why    = self::not_eligible( $inner, substr( $body, 0, $start ), $inline );
			$kind   = 'p' === $tag ? 'paragraph' : ( 'li' === $tag ? 'item' : ( in_array( $tag, array( 'td', 'th' ), true ) ? 'cell' : 'heading' ) );
			if ( '' === $why && ! preg_match( '/\p{L}/u', $text ) ) {
				$why = 'no words, only figures'; // "$8 million" can be rewritten; "2027" or "12.4%" cannot.
			}
			$blocks[] = array(
				'html'     => $html,
				'open'     => $match[1][0],
				'tag'      => $tag,
				'kind'     => $kind,
				'text'     => $text,
				'words'    => self::count_words( $text ),
				'eligible' => '' === $why,
				'why'      => $why,
				'inline'   => (array) $inline,
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
			'open'     => '',
			'tag'      => '',
			'kind'     => '',
			'text'     => '',
			'words'    => 0,
			'eligible' => false,
			'why'      => 'not text',
			'inline'   => array(),
		);
	}

	/**
	 * Links and bold or italic words inside a block, each as its HTML and its text. They come back
	 * in place only if their text is still there word for word.
	 *
	 * @return array<int, array{html:string, text:string}>|null Null when the block has other markup.
	 */
	public static function inline( string $inner ): ?array {
		$out  = array();
		$rest = (string) preg_replace_callback(
			'/<(' . self::INLINE_TAGS . ')\b[^>]*>([^<]*)<\/\1\s*>/i',
			static function ( $m ) use ( &$out ) {
				$out[] = array(
					'html' => $m[0],
					'text' => self::plain( $m[2] ),
				);
				return '';
			},
			$inner
		);
		if ( false !== strpos( $rest, '<' ) ) {
			return null;
		}
		foreach ( $out as $element ) {
			if ( '' === $element['text'] ) {
				return null;
			}
		}
		return $out;
	}

	/**
	 * Why a block is kept exactly as written. Empty when it can be sent.
	 *
	 * @param string     $inner  The block's inner HTML.
	 * @param string     $before Everything in the body before it, to see what it sits inside.
	 * @param array|null $inline From inline().
	 */
	public static function not_eligible( string $inner, string $before, ?array $inline ): string {
		// Tables are humanized cell by cell (owner's request, 1.12.3). A block quote is someone's own words.
		foreach ( array( 'blockquote', 'figure', 'aside', 'details', 'pre', 'code' ) as $tag ) {
			$open  = preg_match_all( '/<' . $tag . '\b/i', $before );
			$close = preg_match_all( '/<\/' . $tag . '\s*>/i', $before );
			if ( $open > $close ) {
				return 'inside a ' . $tag;
			}
		}
		if ( null === $inline ) {
			return 'has markup other than links and bold or italic words';
		}
		$text = self::plain( $inner );
		if ( '' === $text ) {
			return 'empty';
		}
		if ( preg_match( '/https?:|www\./i', $text ) ) {
			return 'has an address';
		}
		if ( preg_match( '/^(source|sources|read more|related|editor\'s note|update|image|photo|credit)\b\s*[:\-]/i', $text ) ) {
			return 'a label or source line';
		}
		return '';
	}

	/**
	 * The parts of the article to humanize, in order: the title first, then every eligible block.
	 *
	 * @return array<int, array{id:string, kind:string, block:int, text:string, keep:string[], words:int}>
	 */
	public static function units( string $title, array $blocks ): array {
		$units = array();
		$title = trim( $title );
		if ( '' !== $title && preg_match( '/\p{L}/u', $title ) ) {
			$units[] = array(
				'id'    => 't',
				'kind'  => 'title',
				'block' => -1,
				'text'  => $title,
				'keep'  => array(),
				'words' => self::count_words( $title ),
			);
		}
		foreach ( $blocks as $index => $block ) {
			if ( ! $block['eligible'] ) {
				continue;
			}
			$units[] = array(
				'id'    => 'b' . $index,
				'kind'  => $block['kind'],
				'block' => $index,
				'text'  => $block['text'],
				'keep'  => array_values( array_unique( array_column( $block['inline'], 'text' ) ) ),
				'words' => $block['words'],
			);
		}
		return $units;
	}

	/**
	 * The units joined into pieces Walter accepts, in article order. A piece is cut at a heading
	 * once it is long enough, so each piece reads as whole sections.
	 *
	 * @return array<int, int[]> Pieces, each a list of unit positions.
	 */
	public static function items( array $units ): array {
		$items = array();
		$sizes = array();
		$cur   = array();
		$words = 0;
		foreach ( $units as $position => $unit ) {
			$starts_section = in_array( $unit['kind'], array( 'heading', 'title' ), true );
			if ( $cur && ( $words + $unit['words'] > self::MAX_ITEM_WORDS || ( $starts_section && $words >= self::TARGET_ITEM_WORDS ) ) ) {
				$items[] = $cur;
				$sizes[] = $words;
				$cur     = array();
				$words   = 0;
			}
			$cur[]  = $position;
			$words += $unit['words'];
		}
		if ( $cur ) {
			$last = count( $items ) - 1;
			if ( $words >= self::MIN_ITEM_WORDS ) {
				$items[] = $cur;
			} elseif ( $last >= 0 ) {
				// A short tail joins the piece before it; Walter accepts up to 5,000 words in one.
				$items[ $last ] = array_merge( $items[ $last ], $cur );
			}
		}
		return $items;
	}

	/** Words in a piece, as Walter counts them against the plan (the text only). */
	public static function item_words( array $units, array $item ): int {
		$sum = 0;
		foreach ( $item as $position ) {
			$sum += $units[ $position ]['words'];
		}
		return $sum;
	}

	/** The Markdown sent to Walter for a piece: "#" title, "##" headings, "-" list items. */
	public static function item_text( array $units, array $item ): string {
		$parts = array();
		foreach ( $item as $position ) {
			$unit = $units[ $position ];
			switch ( $unit['kind'] ) {
				case 'title':
					$parts[] = '# ' . $unit['text'];
					break;
				case 'heading':
					$parts[] = '## ' . $unit['text'];
					break;
				case 'item':
				case 'cell':
					$parts[] = '- ' . $unit['text'];
					break;
				default:
					$parts[] = $unit['text'];
			}
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * The request to the site's AI model that puts Walter's wording back onto the article's parts
	 * with every fact as the original had it.
	 *
	 * @param array  $units  The units sent.
	 * @param string $walter Walter's answers for them, in order.
	 */
	public static function repair_messages( array $units, string $walter ): array {
		$original = array();
		foreach ( $units as $unit ) {
			$row = array(
				'id'   => $unit['id'],
				'kind' => $unit['kind'],
				'text' => $unit['text'],
			);
			if ( $unit['keep'] ) {
				$row['keep'] = $unit['keep'];
			}
			$original[] = $row;
		}
		$rules = implode(
			"\n",
			array(
				'You get a news article split into numbered parts ("original") and a human-sounding rewrite of the whole article ("rewritten"). The rewrite reads well but changed some facts.',
				'For every part of the original, return the matching words of the rewrite, corrected so that every fact is exactly as in the original.',
				'Rules:',
				'1. Keep the rewrite\'s wording, sentence order and style. Change only what is needed to make the facts match.',
				'2. Facts are: every number, amount, percentage and date; days, months, seasons and times ("next spring" is not "this spring"); names of people, places and organisations; job titles; who said or did what; how certain something is (said, may, could, about, expected, alleged); and every "not" or "no".',
				'3. Remove any detail, claim, name, number or quote that the original part does not have.',
				'4. Words inside quotation marks must be exactly as in the original, word for word, and attributed to the same person.',
				'5. Every phrase listed under "keep" must appear in that part exactly as written.',
				'6. A title or heading stays a short title or heading; a list item stays one item; a table cell stays one short cell. Give each part its own text and never move a sentence to another part.',
				'7. If the rewrite has nothing for a part, return that part\'s original text unchanged.',
				'8. Plain text only: no Markdown, no HTML, no "#" or "-" in front, and never the "—" dash.',
				'Answer with only this JSON and nothing else: {"parts":[{"id":"<id>","text":"<corrected text>"}]} with every id exactly once.',
			)
		);
		return array(
			array(
				'role'    => 'system',
				'content' => 'You are a careful news copy editor. You correct facts in a rewritten article so they match the original. You never write new content.',
			),
			array(
				'role'    => 'user',
				'content' => $rules . "\n\noriginal:\n" . wp_json_encode( $original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n\nrewritten:\n" . $walter,
			),
		);
	}

	/**
	 * The repaired parts from the model's answer.
	 *
	 * @return array<string, string> Part id => text. Empty when the answer is not readable.
	 */
	public static function parse_repair( string $content ): array {
		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return array();
		}
		$data = json_decode( substr( $content, $start, $end - $start + 1 ), true );
		$out  = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['parts'] ?? array() ) : array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['id'], $part['text'] ) && is_string( $part['text'] ) ) {
				$out[ (string) $part['id'] ] = $part['text'];
			}
		}
		return $out;
	}

	/** Walter's answer without the oddities it adds: "Beginning of Text", HTML, emphasis. */
	public static function clean_answer( string $output ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $output );
		$text = (string) preg_replace( '/<[^>]+>/', ' ', $text );
		// Seen on 2026-11-16: "Beginning of Text" in front of the answer.
		$text = (string) preg_replace( '/^\s*(beginning|start) of (the )?text\s*[:.\-]?\s*/i', '', $text );
		$text = (string) preg_replace( '/\s*(end) of (the )?text\s*[:.]?\s*$/i', '', $text );
		$text = (string) preg_replace( '/(\*\*|__)(.+?)\1/u', '$2', $text );
		return trim( $text );
	}

	/** One repaired part as plain text on one line, without any heading, list or dash mark in front. */
	public static function clean_part( string $text ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]+>/', ' ', $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/(\*\*|__)(.+?)\1/u', '$2', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		// Seen on 2026-11-16: a paragraph that came back starting with "– ".
		return trim( (string) preg_replace( '/^(#{1,6}|[\-–—•*])\s+/u', '', $text ) );
	}

	/**
	 * Whether a repaired part may replace the original.
	 *
	 * @param array  $unit    From units().
	 * @param string $new     The repaired text, from clean_part().
	 * @param string $article The whole original article as plain text, title included.
	 * @return string Why it may not. Empty when it may.
	 */
	public static function check_part( array $unit, string $new, string $article ): string {
		foreach ( $unit['keep'] as $phrase ) {
			if ( false === strpos( $new, $phrase ) ) {
				return 'a link or bold text changed: ' . $phrase;
			}
		}
		if ( in_array( $unit['kind'], array( 'title', 'heading' ), true ) && preg_match( '/[.!?]\s+\p{Lu}/u', $new ) && ! preg_match( '/[.!?]\s+\p{Lu}/u', $unit['text'] ) ) {
			return 'a heading became sentences';
		}
		// In a title or heading every word may start with a capital, so only words the article itself
		// uses as names must survive there.
		$names = in_array( $unit['kind'], array( 'title', 'heading' ), true ) ? self::prose_names( $article ) : null;
		return self::fact_check( $unit['text'], $new, $article, true, $names );
	}

	/** Names used in the article's sentences; titles, headings and table text are left out. */
	public static function prose_names( string $article ): array {
		$out = array();
		foreach ( preg_split( '/\n\s*\n/u', $article ) as $chunk ) {
			$chunk = trim( (string) $chunk );
			if ( preg_match( '/[.!?]["”’)]?$/u', $chunk ) ) {
				$out = array_merge( $out, self::names( $chunk ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The article as plain text, one block per chunk, title first: for the name checks. */
	public static function article_text( string $title, array $blocks ): string {
		$parts = array( $title );
		foreach ( $blocks as $block ) {
			$text = '' !== $block['tag'] ? $block['text'] : self::plain( $block['html'] );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Whether a rewritten text keeps every fact of the original.
	 *
	 * @param string $original    The original plain text.
	 * @param string $rewritten   The new plain text.
	 * @param string $article     The whole original article as plain text, to tell a name the
	 *                            article already uses from one that was made up.
	 * @param bool   $with_quotes True when the original may hold quotes: each must come back word for
	 *                            word and none may be added. False: any quote mark was made up.
	 * @param array|null $names   For title case text: the only words that count as names. Null: read them from $original.
	 * @return string Why it may not replace the original. Empty when it may.
	 */
	public static function fact_check( string $original, string $rewritten, string $article = '', bool $with_quotes = false, ?array $names = null ): string {
		$a = self::count_words( $original );
		$b = self::count_words( $rewritten );
		if ( $b < 1 ) {
			return 'nothing came back';
		}
		$ratio = $b / max( 1, $a );
		$short = $a <= self::SHORT_PART_WORDS;
		if ( $ratio < ( $short ? 0.5 : 0.75 ) ) {
			return sprintf( 'too much was left out (%1$d words became %2$d)', $a, $b );
		}
		if ( $ratio > ( $short ? 2.0 : 1.4 ) ) {
			return sprintf( 'too much was added (%1$d words became %2$d)', $a, $b );
		}

		if ( $with_quotes ) {
			$before = self::quotes( $original );
			$after  = self::quotes( $rewritten );
			$lost   = self::missing( $before, $after );
			if ( $lost ) {
				return 'a quote was changed or left out';
			}
			if ( self::missing( $after, $before ) ) {
				return 'a quote was added';
			}
		} elseif ( preg_match( '/["“”«»]/u', $rewritten ) ) {
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
		$kept   = array_unique( self::hedge_kinds( $rewritten ) );
		$hedges = array();
		foreach ( self::words_from( $original, array_keys( self::HEDGES ) ) as $word ) {
			if ( ! in_array( self::HEDGES[ $word ], $kept, true ) ) {
				$hedges[] = $word;
			}
		}
		if ( $hedges ) {
			return 'how certain it is changed: "' . implode( '", "', array_slice( array_unique( $hedges ), 0, 3 ) ) . '" went missing';
		}

		$lost = self::missing_names( $original, $rewritten, $names );
		if ( $lost ) {
			return 'a name went missing: ' . implode( ', ', array_slice( $lost, 0, 3 ) );
		}
		// Title case gives no sign of a new name; there the AI's repair is trusted to remove one.
		$invented = null !== $names ? array() : self::invented_names( $rewritten, '' !== $article ? $article : $original );
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

	/**
	 * The words inside quotation marks, normalised (curly and straight quotes, spacing, case of the
	 * first letter), so a quote can be compared word for word.
	 *
	 * @return string[]
	 */
	public static function quotes( string $text ): array {
		$text = str_replace( array( '“', '”', '„', '«', '»' ), '"', $text );
		$text = str_replace( '’', "'", $text );
		preg_match_all( '/"([^"]+)"/u', $text, $m );
		$out = array();
		foreach ( $m[1] as $quote ) {
			$quote = trim( (string) preg_replace( '/\s+/u', ' ', $quote ), " \t,." );
			if ( '' !== $quote ) {
				$out[] = mb_strtolower( $quote );
			}
		}
		return $out;
	}

	/**
	 * Figures, normalised: "$12 million" gives "12", "1,500" gives "1500", "3.5%" gives "3.5", and
	 * "two" gives "2".
	 */
	public static function numbers( string $text ): array {
		preg_match_all( '/\d+(?:[.,]\d+)*/u', $text, $m );
		$out = array();
		foreach ( $m[0] as $number ) {
			// A comma or point followed by exactly three digits groups thousands; any other point is a decimal.
			$number = (string) preg_replace( '/[.,](?=\d{3}(?!\d))/', '', $number );
			$out[]  = str_replace( ',', '.', $number );
		}
		foreach ( self::words_from( $text, array_keys( self::DIGIT_WORDS ) ) as $word ) {
			$out[] = self::DIGIT_WORDS[ $word ];
		}
		return $out;
	}

	/** The kinds of hedge in a text, each time one appears. */
	private static function hedge_kinds( string $text ): array {
		$out = array();
		foreach ( self::words_from( $text, array_keys( self::HEDGES ) ) as $word ) {
			$out[] = self::HEDGES[ $word ];
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
		foreach ( preg_split( '/(?<=[.!?:])\s+|["“”]\s*/u', $text ) as $sentence ) {
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

	private static function missing_names( string $original, string $rewritten, ?array $known = null ): array {
		$out  = array();
		$have = self::names( $original );
		if ( null !== $known ) {
			// Title case: the article's known names that this text uses.
			$have = array();
			foreach ( $known as $name ) {
				if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/u', $original ) ) {
					$have[] = $name;
				}
			}
		}
		foreach ( $have as $name ) {
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
	 * A block's new inner HTML: the text escaped, with its links and bold or italic words put back
	 * around the same words.
	 *
	 * @return string|null Null when one of them can no longer be found.
	 */
	public static function render_inner( string $text, array $inline ): ?string {
		$html = htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
		$from = 0;
		foreach ( $inline as $element ) {
			$needle = htmlspecialchars( $element['text'], ENT_NOQUOTES, 'UTF-8' );
			$at     = strpos( $html, $needle, $from );
			if ( false === $at ) {
				$at = strpos( $html, $needle );
				if ( false === $at ) {
					return null;
				}
			}
			$html = substr( $html, 0, $at ) . $element['html'] . substr( $html, $at + strlen( $needle ) );
			$from = $at + strlen( $element['html'] );
		}
		return $html;
	}

	/**
	 * Puts accepted blocks back into the body, each in its own tag with its own attributes.
	 *
	 * @param array $blocks From blocks().
	 * @param array $inner  Block index => new inner HTML.
	 */
	public static function rebuild( array $blocks, array $inner ): string {
		$html = '';
		foreach ( $blocks as $index => $block ) {
			$html .= isset( $inner[ $index ] ) ? $block['open'] . $inner[ $index ] . '</' . $block['tag'] . '>' : $block['html'];
		}
		return $html;
	}

	public static function plain( string $html ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]+>/', ' ', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+([,.;:!?])/u', '$1', (string) preg_replace( '/\s+/u', ' ', $text ) );
		return trim( $text );
	}

	public static function count_words( string $text ): int {
		return (int) preg_match_all( "/[\p{L}\p{N}][\p{L}\p{N}'’.,\-]*/u", $text );
	}
}
