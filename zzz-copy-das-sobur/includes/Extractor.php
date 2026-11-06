<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls article text and metadata out of a publisher page.
 */
final class Extractor {

	private const NOISE = '/(^|[\s_\-])(ad|ads|advert|advertisement|sponsor|sponsored|promo|share|social|related|recommend|recommended|newsletter|subscribe-box|signup|comment|comments|sidebar|footer|nav|navigation|menu|breadcrumb|cookie|consent|popup|modal|outbrain|taboola)([\s_\-]|$)/i';

	private const PAYWALL_TEXT = '/(subscribe to (continue|read)|subscribers? only|already a subscriber|sign in to (continue|read)|log in to (continue|read)|this (article|content) is for subscribers|start your (free )?trial to|create a free account to continue|you have reached your (free )?(article )?limit)/i';

	private const PAYWALL_CLASS = '/(paywall|meteredcontent|piano-offer|subscriber-only|premium-content|regwall)/i';

	/**
	 * @return array{title:string, author:string, published:int, published_stated:int, canonical:string, text:string, word_count:int, paywall:bool}
	 */
	public static function extract( string $html, string $base_url = '' ): array {
		$out = array(
			'title'      => '',
			'author'     => '',
			'published'  => 0,
			'published_stated' => 0,
			'canonical'  => '',
			'text'       => '',
			'word_count' => 0,
			'paywall'    => false,
		);

		if ( '' === trim( $html ) ) {
			return $out;
		}

		$doc = self::load( $html );
		if ( ! $doc ) {
			return $out;
		}
		$xpath = new \DOMXPath( $doc );

		$jsonld                = self::read_json_ld( $xpath );
		$out['title']          = self::meta( $xpath, 'og:title' ) ?: (string) ( $jsonld['headline'] ?? '' ) ?: self::first_text( $xpath, '//h1' ) ?: self::first_text( $xpath, '//title' );
		$out['author']         = self::meta( $xpath, 'author' ) ?: self::meta( $xpath, 'article:author' ) ?: (string) ( $jsonld['author'] ?? '' );
		$date                  = self::meta( $xpath, 'article:published_time' ) ?: (string) ( $jsonld['datePublished'] ?? '' ) ?: self::first_attr( $xpath, '//time[@datetime]', 'datetime' );
		$out['published']      = FeedParser::parse_date( $date );
		$out['published_stated'] = FeedParser::parse_date( self::meta( $xpath, 'article:published_time' ) ?: (string) ( $jsonld['datePublished'] ?? '' ) );
		$out['canonical']      = self::first_attr( $xpath, '//link[translate(@rel,"CANONICAL","canonical")="canonical"]', 'href' ) ?: self::meta( $xpath, 'og:url' );
		if ( '' !== $out['canonical'] && '' !== $base_url ) {
			$out['canonical'] = Http::absolute_url( $out['canonical'], $base_url );
		}

		$tier           = strtolower( self::meta( $xpath, 'article:content_tier' ) );
		$out['paywall'] = in_array( $tier, array( 'locked', 'metered' ), true ) || ! empty( $jsonld['paywalled'] );

		$raw_html_lower = substr( $html, 0, 2000000 );
		if ( preg_match( self::PAYWALL_CLASS, $raw_html_lower ) && preg_match( self::PAYWALL_TEXT, wp_strip_all_tags( $raw_html_lower ) ) ) {
			$out['paywall'] = true;
		}

		$text = '';
		if ( ! empty( $jsonld['articleBody'] ) ) {
			$text = self::normalize( FeedParser::clean_text( (string) $jsonld['articleBody'] ) );
		}

		self::remove_noise( $doc, $xpath );
		$from_html = self::main_text( $xpath );

		// Use whichever source gives more text. Pages often shorten one of them.
		if ( self::count_words( $from_html ) > self::count_words( $text ) ) {
			$text = $from_html;
		}

		$out['text']       = $text;
		$out['word_count'] = self::count_words( $text );

		return $out;
	}

	public static function count_words( string $text ): int {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	private static function load( string $html ): ?\DOMDocument {
		if ( ! preg_match( '//u', $html ) ) {
			$charset = 'ISO-8859-1';
			if ( preg_match( '/charset=["\']?([A-Za-z0-9_\-]+)/i', substr( $html, 0, 4096 ), $match ) ) {
				$charset = $match[1];
			}
			$converted = @mb_convert_encoding( $html, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$html      = false === $converted ? $html : $converted;
		}

		// The text is UTF-8 by now, so a charset declared in the page must not make the parser decode it again.
		$html = (string) preg_replace( '/(<meta\b[^>]*charset=["\']?)[A-Za-z0-9_\-]+/i', '$1UTF-8', $html );

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $doc : null;
	}

	private static function meta( \DOMXPath $xpath, string $name ): string {
		$query = sprintf( '//meta[@property="%1$s" or @name="%1$s"]', $name );
		return trim( self::first_attr( $xpath, $query, 'content' ) );
	}

	private static function first_attr( \DOMXPath $xpath, string $query, string $attr ): string {
		$nodes = $xpath->query( $query );
		if ( $nodes && $nodes->length ) {
			$node = $nodes->item( 0 );
			if ( $node instanceof \DOMElement ) {
				return trim( $node->getAttribute( $attr ) );
			}
		}
		return '';
	}

	private static function first_text( \DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );
		if ( $nodes && $nodes->length ) {
			return self::normalize( (string) $nodes->item( 0 )->textContent );
		}
		return '';
	}

	private static function read_json_ld( \DOMXPath $xpath ): array {
		$out   = array();
		$nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( ! $nodes ) {
			return $out;
		}
		foreach ( $nodes as $node ) {
			$data = json_decode( trim( (string) $node->textContent ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
			foreach ( $list as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$type = $entry['@type'] ?? '';
				$type = is_array( $type ) ? implode( ' ', $type ) : (string) $type;
				if ( ! preg_match( '/Article|Report|Posting/i', $type ) ) {
					continue;
				}
				foreach ( array( 'headline', 'datePublished', 'articleBody' ) as $field ) {
					if ( empty( $out[ $field ] ) && ! empty( $entry[ $field ] ) && is_string( $entry[ $field ] ) ) {
						$out[ $field ] = $entry[ $field ];
					}
				}
				if ( empty( $out['author'] ) && ! empty( $entry['author'] ) ) {
					$author = $entry['author'];
					if ( isset( $author[0] ) && is_array( $author[0] ) ) {
						$author = $author[0];
					}
					if ( is_array( $author ) && ! empty( $author['name'] ) ) {
						$out['author'] = (string) $author['name'];
					} elseif ( is_string( $author ) ) {
						$out['author'] = $author;
					}
				}
				if ( isset( $entry['isAccessibleForFree'] ) ) {
					$free = $entry['isAccessibleForFree'];
					if ( false === $free || 'false' === strtolower( (string) $free ) || 'False' === $free ) {
						$out['paywalled'] = true;
					}
				}
			}
		}
		return $out;
	}

	private static function remove_noise( \DOMDocument $doc, \DOMXPath $xpath ): void {
		$remove = array();
		foreach ( array( 'script', 'style', 'noscript', 'iframe', 'svg', 'button', 'figure', 'figcaption', 'template' ) as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $node ) {
				$remove[] = $node;
			}
		}
		// Some sites wrap a whole article in one of these, so a block of real text is kept.
		foreach ( array( 'nav', 'header', 'footer', 'aside', 'form' ) as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $node ) {
				if ( ! self::holds_article_text( $node ) ) {
					$remove[] = $node;
				}
			}
		}
		$marked = $xpath->query( '//*[@class or @id or @role]' );
		if ( $marked ) {
			foreach ( $marked as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$label = $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' );
				$role  = $node->getAttribute( 'role' );
				$tag   = strtolower( $node->tagName );
				if ( in_array( $tag, array( 'html', 'body', 'article', 'main' ), true ) ) {
					continue;
				}
				if ( ( preg_match( self::NOISE, $label ) || in_array( $role, array( 'navigation', 'banner', 'complementary', 'contentinfo' ), true ) ) && ! self::holds_article_text( $node ) ) {
					$remove[] = $node;
				}
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Whether a block has real paragraphs of text. A name like "content--with-share" must not
	 * remove the article itself, while real share bars and menus have almost no paragraph text.
	 */
	private static function holds_article_text( \DOMElement $node ): bool {
		$paragraphs = $node->getElementsByTagName( 'p' );
		if ( $paragraphs->length < 3 ) {
			return false;
		}
		$words = 0;
		foreach ( $paragraphs as $paragraph ) {
			$words += self::count_words( (string) $paragraph->textContent );
		}
		return $words >= 120;
	}

	/**
	 * Finds the main text: a marked article container first, otherwise the block with the most paragraph text.
	 */
	private static function main_text( \DOMXPath $xpath ): string {
		$marked = '';
		foreach ( array( '//*[@itemprop="articleBody"]', '//article', '//main' ) as $query ) {
			$nodes = $xpath->query( $query );
			if ( ! $nodes || ! $nodes->length ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$text = self::collect_blocks( $xpath, $node );
				if ( strlen( $text ) > strlen( $marked ) ) {
					$marked = $text;
				}
			}
			if ( self::count_words( $marked ) >= 50 ) {
				break;
			}
		}

		// A marked container can hold only a teaser, so compare it with the densest block of paragraphs.
		$dense = self::densest_block( $xpath );
		return self::count_words( $marked ) * 2 >= self::count_words( $dense ) ? $marked : $dense;
	}

	private static function densest_block( \DOMXPath $xpath ): string {
		$paragraphs = $xpath->query( '//p' );
		if ( ! $paragraphs ) {
			return '';
		}
		$scores  = array();
		$parents = array();
		foreach ( $paragraphs as $paragraph ) {
			$parent = $paragraph->parentNode;
			if ( ! $parent ) {
				continue;
			}
			$id             = spl_object_id( $parent );
			$parents[ $id ] = $parent;
			$scores[ $id ]  = ( $scores[ $id ] ?? 0 ) + strlen( trim( (string) $paragraph->textContent ) );
		}
		if ( ! $scores ) {
			return '';
		}
		arsort( $scores );
		return self::collect_blocks( $xpath, $parents[ array_key_first( $scores ) ] );
	}

	private static function collect_blocks( \DOMXPath $xpath, \DOMNode $root ): string {
		$blocks = array();
		$nodes  = $xpath->query( './/p | .//h2 | .//h3 | .//h4 | .//li | .//blockquote', $root );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$text = self::normalize( (string) $node->textContent );
				if ( '' === $text ) {
					continue;
				}
				// Skip one-line link lists and very short fragments.
				if ( in_array( strtolower( $node->nodeName ), array( 'li' ), true ) && self::count_words( $text ) < 4 ) {
					continue;
				}
				$blocks[] = $text;
			}
		}
		return implode( "\n\n", array_values( array_unique( $blocks ) ) );
	}

	private static function normalize( string $text ): string {
		return trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', (string) preg_replace( '/\s*\n\s*/u', ' ', $text ) ) );
	}
}
