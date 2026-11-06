<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Checks generated articles before they can be queued.
 *
 * Errors hold or retry the article. Warnings and metrics stay in diagnostics. The word count and
 * readability score are measured here only and are never written into the article.
 */
final class Validator {

	private const PREAMBLE = '/^\s*(here(\'|’)?s|here is|here are|sure[,!. ]|certainly[,!. ]|of course[,!. ]|absolutely[,!. ]|below is|okay[,!. ]|i have written|i\'ve written)/iu';

	private const CLOSING = '/(let me know|would you like( me)?|i hope this|feel free to ask|if you need (any )?(more|further|additional)|do you want me to|i can also)/iu';

	private const PROVIDER_ERROR = '/(as an ai (language )?(model|assistant)\b|i (cannot|can\'t|am unable to|\'m unable to) (fulfil|fulfill|help|write|complete|provide|assist)|rate limit exceeded|upstream (connect )?error|internal server error|maximum context length|invalid api key|insufficient credits)/iu';

	private const PRINTED_METRIC = '/(flesch|readability score|word count\s*[:=]|\b\d[\d,]* words\b\s*$)/ium';

	/**
	 * @param string   $title         Generated title.
	 * @param string   $body          Generated body HTML.
	 * @param string   $finish_reason Provider finish reason.
	 * @param string   $preamble      Text found before the title.
	 * @param string[] $tags          Parsed tags.
	 * @return array{pass:bool, errors:array, warnings:array, metrics:array, tag_only_failure:bool}
	 */
	public static function validate( string $title, string $body, string $finish_reason, string $preamble, array $tags ): array {
		$errors     = array();
		$warnings   = array();
		$tag_errors = array();

		$plain_title = trim( wp_strip_all_tags( $title ) );
		if ( '' === $plain_title ) {
			$errors[] = array( 'missing_title', 'No title was found in the response.' );
		} elseif ( mb_strlen( $plain_title ) > 250 ) {
			$errors[] = array( 'title_too_long', 'The title is too long to be a headline, so the response is probably malformed.' );
		} elseif ( preg_match( self::PREAMBLE, $plain_title ) ) {
			$errors[] = array( 'title_commentary', 'The title line looks like commentary rather than a headline.' );
		}

		if ( '' !== trim( $preamble ) ) {
			$errors[] = array( 'preamble', 'The response had text before the title.' );
		}

		$structure  = self::inspect( $body );
		$plain_body = $structure['text'];
		$words      = Extractor::count_words( $plain_body );

		if ( '' === trim( $plain_body ) ) {
			$errors[] = array( 'empty_body', 'The article body is empty.' );
		} else {
			$min = (int) Settings::get( 'min_article_words' );
			if ( $words < $min ) {
				$errors[] = array( 'body_too_short', sprintf( 'The body has %1$d words. The minimum accepted is %2$d.', $words, $min ) );
			}

			if ( in_array( strtolower( $finish_reason ), array( 'length', 'max_tokens' ), true ) ) {
				$errors[] = array( 'truncated', 'The model stopped because it reached the output token limit, so the article is cut off.' );
			} elseif ( self::looks_truncated( $structure['last_text'], $structure['last_is_structure'] ) ) {
				$errors[] = array( 'truncated', 'The article appears to stop mid-sentence.' );
			}

			if ( preg_match( self::PREAMBLE, $structure['first_text'] ) ) {
				$errors[] = array( 'preamble', 'The body opens with commentary addressed to the reader of the prompt.' );
			}
			if ( preg_match( self::CLOSING, $structure['last_text'] ) ) {
				$errors[] = array( 'closing_commentary', 'The article ends with commentary or a question for the prompt writer.' );
			}
			// Only the opening is scanned, because a news story may quote these phrases later on.
			if ( preg_match( self::PROVIDER_ERROR, $plain_title . "\n" . mb_substr( $plain_body, 0, 400 ) ) ) {
				$errors[] = array( 'provider_error_text', 'The response contains refusal or provider error text.' );
			}
			if ( preg_match( self::PRINTED_METRIC, $plain_body ) ) {
				$errors[] = array( 'printed_metric', 'The article prints a readability score or word count, which the prompt forbids.' );
			}
			if ( false !== stripos( $body, '<img' ) ) {
				$errors[] = array( 'image_found', 'The article contains an image element.' );
			}
		}

		if ( ! $structure['tables'] ) {
			$errors[] = array( 'missing_table', 'The requested table is missing.' );
		} elseif ( ! $structure['valid_table'] ) {
			$errors[] = array( 'malformed_table', 'The table is malformed: it needs a header, at least one data row and matching cell counts.' );
		}

		if ( ! $structure['faq'] ) {
			$errors[] = array( 'missing_faq', 'The requested Trending FAQ section is missing.' );
		}

		foreach ( $structure['empty_headings'] as $heading ) {
			$errors[] = array( 'empty_section', sprintf( 'The section "%s" has no content.', $heading ) );
		}

		if ( $structure['h2'] < 2 || $structure['h2'] > 4 ) {
			$warnings[] = array( 'h2_count', sprintf( 'Found %d H2 sections. The prompt asks for two or three, plus the FAQ.', $structure['h2'] ) );
		}
		if ( ! $structure['h3'] ) {
			$warnings[] = array( 'no_h3', 'No H3 heading was found.' );
		}
		if ( ! $structure['h4'] ) {
			$warnings[] = array( 'no_h4', 'No H4 heading was found.' );
		}
		if ( 1 !== $structure['lists'] ) {
			$warnings[] = array( 'list_count', sprintf( 'Found %d lists outside the FAQ. The prompt asks for exactly one.', $structure['lists'] ) );
		}
		if ( $words < 1300 ) {
			$warnings[] = array( 'word_target', sprintf( 'The body has %d words. The prompt asks for about 1,500.', $words ) );
		}

		$reading = self::reading_ease( $plain_body );
		if ( null !== $reading && ( $reading < 85 || $reading > 90 ) ) {
			$warnings[] = array( 'readability_target', sprintf( 'Measured Flesch reading ease is %.1f. The prompt asks for 85 to 90. This is an estimate and is not a guarantee.', $reading ) );
		}
		if ( false !== mb_strpos( $plain_title . $plain_body, '—' ) ) {
			if ( 'allow' === Settings::get( 'emdash_policy' ) ) {
				$warnings[] = array( 'em_dash', 'The article contains the character the prompt forbids.' );
			} else {
				$errors[] = array( 'em_dash', 'The article contains the character the prompt forbids.' );
			}
		}

		$tag_count = count( $tags );
		if ( TagParser::REQUIRED !== $tag_count ) {
			$tag_errors[] = array( 'tag_count', sprintf( 'Found %1$d usable tags. %2$d are required.', $tag_count, TagParser::REQUIRED ) );
		}

		return array(
			'pass'             => ! $errors && ! $tag_errors,
			'errors'           => array_merge( $errors, $tag_errors ),
			'warnings'         => $warnings,
			'tag_only_failure' => ! $errors && (bool) $tag_errors,
			'metrics'          => array(
				'word_count'    => $words,
				'reading_ease'  => $reading,
				'h2'            => $structure['h2'],
				'h3'            => $structure['h3'],
				'h4'            => $structure['h4'],
				'lists'         => $structure['lists'],
				'tables'        => $structure['tables'],
				'faq'           => $structure['faq'],
				'tag_count'     => $tag_count,
				'finish_reason' => $finish_reason,
			),
		);
	}

	private static function looks_truncated( string $last_text, bool $last_is_structure ): bool {
		if ( $last_is_structure || '' === $last_text ) {
			return false;
		}
		return ! preg_match( '/[.!?:;)\]"\'’”]\s*$/u', $last_text );
	}

	/**
	 * Reads the structure of the body HTML.
	 */
	public static function inspect( string $body ): array {
		$out = array(
			'text'              => '',
			'first_text'        => '',
			'last_text'         => '',
			'last_is_structure' => false,
			'h2'                => 0,
			'h3'                => 0,
			'h4'                => 0,
			'lists'             => 0,
			'tables'            => 0,
			'valid_table'       => false,
			'faq'               => false,
			'empty_headings'    => array(),
		);

		if ( '' === trim( $body ) ) {
			return $out;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="zzzc-root">' . $body . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementById( 'zzzc-root' );
		if ( ! $root ) {
			return $out;
		}

		$out['text'] = trim( (string) preg_replace( '/\s+/u', ' ', self::text_with_breaks( $root ) ) );

		$elements = array();
		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof \DOMElement && '' !== trim( (string) $child->textContent ) ) {
				$elements[] = $child;
			}
		}

		$in_faq = false;
		foreach ( $elements as $index => $element ) {
			$tag = strtolower( $element->tagName );

			if ( preg_match( '/^h([2-6])$/', $tag, $match ) ) {
				$level = (int) $match[1];
				if ( isset( $out[ 'h' . $level ] ) ) {
					$out[ 'h' . $level ]++;
				}
				$text = trim( (string) $element->textContent );
				if ( preg_match( '/\b(faqs?|frequently asked)\b/iu', $text ) ) {
					$out['faq'] = true;
					$in_faq     = true;
				} elseif ( $level <= 2 ) {
					$in_faq = false;
				}

				$next = $elements[ $index + 1 ] ?? null;
				if ( ! $next ) {
					$out['empty_headings'][] = $text;
				} elseif ( preg_match( '/^h([2-6])$/', strtolower( $next->tagName ), $next_match ) && (int) $next_match[1] <= $level ) {
					$out['empty_headings'][] = $text;
				}
			}

			if ( ! $in_faq && ( 'ul' === $tag || 'ol' === $tag ) ) {
				$out['lists']++;
			}
		}

		$tables = $root->getElementsByTagName( 'table' );
		foreach ( $tables as $table ) {
			$out['tables']++;
			if ( self::table_is_valid( $table ) ) {
				$out['valid_table'] = true;
			}
		}

		if ( $elements ) {
			$out['first_text'] = trim( (string) $elements[0]->textContent );
			$last              = $elements[ count( $elements ) - 1 ];
			$out['last_text']  = trim( (string) $last->textContent );
			$out['last_is_structure'] = in_array( strtolower( $last->tagName ), array( 'table', 'ul', 'ol' ), true );
		}

		return $out;
	}

	private static function text_with_breaks( \DOMNode $node ): string {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->nodeValue;
			} elseif ( $child->hasChildNodes() ) {
				$text .= ' ' . self::text_with_breaks( $child ) . ' ';
			}
		}
		return $text;
	}

	private static function table_is_valid( \DOMElement $table ): bool {
		$rows = $table->getElementsByTagName( 'tr' );
		if ( $rows->length < 2 ) {
			return false;
		}
		$expected = null;
		foreach ( $rows as $row ) {
			$cells = 0;
			foreach ( $row->childNodes as $cell ) {
				if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$span   = (int) $cell->getAttribute( 'colspan' );
					$cells += max( 1, $span );
				}
			}
			if ( $cells < 2 ) {
				return false;
			}
			if ( null === $expected ) {
				$expected = $cells;
			} elseif ( $cells !== $expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Flesch reading ease, for diagnostics only. Null for very short text.
	 */
	public static function reading_ease( string $text ): ?float {
		$sentences = preg_split( '/[.!?]+(\s|$)/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words     = preg_split( '/\s+/u', trim( (string) preg_replace( '/[^\p{L}\p{N}\s\']/u', ' ', $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $sentences || ! $words || count( $words ) < 30 ) {
			return null;
		}
		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += self::syllables( $word );
		}
		$score = 206.835 - 1.015 * ( count( $words ) / count( $sentences ) ) - 84.6 * ( $syllables / count( $words ) );
		return round( $score, 1 );
	}

	private static function syllables( string $word ): int {
		$word = strtolower( (string) preg_replace( '/[^a-z]/i', '', $word ) );
		if ( '' === $word ) {
			return 1;
		}
		$word  = (string) preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word  = (string) preg_replace( '/^y/', '', $word );
		$count = preg_match_all( '/[aeiouy]{1,2}/', $word );
		return max( 1, (int) $count );
	}
}
