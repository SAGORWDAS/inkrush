<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Parses RSS, Atom and sitemap documents safely, with no network access while parsing.
 */
final class FeedParser {

	/**
	 * @return array{ok:bool, kind:string, items:array, children:string[]}
	 */
	public static function parse( string $xml ): array {
		$out = array(
			'ok'       => false,
			'kind'     => '',
			'items'    => array(),
			'children' => array(),
		);

		$xml = ltrim( $xml, "\xEF\xBB\xBF \t\r\n" );
		if ( '' === $xml || false !== stripos( substr( $xml, 0, 2000 ), '<!DOCTYPE' ) ) {
			return $out;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $doc->documentElement ) {
			return $out;
		}

		$root = strtolower( $doc->documentElement->localName );

		if ( 'rss' === $root || 'rdf' === $root ) {
			$out['kind']  = 'rss';
			$out['items'] = self::parse_rss( $doc );
			$out['ok']    = true;
		} elseif ( 'feed' === $root ) {
			$out['kind']  = 'atom';
			$out['items'] = self::parse_atom( $doc );
			$out['ok']    = true;
		} elseif ( 'urlset' === $root ) {
			$out['kind']  = 'sitemap';
			$out['items'] = self::parse_urlset( $doc );
			$out['ok']    = true;
		} elseif ( 'sitemapindex' === $root ) {
			$out['kind'] = 'sitemapindex';
			foreach ( $doc->getElementsByTagNameNS( '*', 'loc' ) as $loc ) {
				$out['children'][] = trim( $loc->textContent );
			}
			$out['ok'] = true;
		}

		return $out;
	}

	private static function parse_rss( \DOMDocument $doc ): array {
		$items = array();
		foreach ( $doc->getElementsByTagName( 'item' ) as $node ) {
			$source_name = '';
			$source_url  = '';
			$source      = $node->getElementsByTagName( 'source' )->item( 0 );
			if ( $source ) {
				$source_name = trim( $source->textContent );
				$source_url  = trim( $source->getAttribute( 'url' ) );
			}
			$date = self::child_text( $node, 'pubDate' );
			if ( '' === $date ) {
				$date = self::child_text_ns( $node, 'date' );
			}
			$author = self::child_text_ns( $node, 'creator' );
			if ( '' === $author ) {
				$author = self::child_text( $node, 'author' );
			}
			$items[] = array(
				'title'       => self::clean_text( self::child_text( $node, 'title' ) ),
				'link'        => trim( self::child_text( $node, 'link' ) ),
				'guid'        => trim( self::child_text( $node, 'guid' ) ),
				'published'   => self::parse_date( $date ),
				'snippet'     => self::clean_text( self::child_text( $node, 'description' ) ),
				'source_name' => self::clean_text( $source_name ),
				'source_url'  => $source_url,
				'author'      => self::clean_text( $author ),
			);
		}
		return $items;
	}

	private static function parse_atom( \DOMDocument $doc ): array {
		$items = array();
		foreach ( $doc->getElementsByTagNameNS( '*', 'entry' ) as $node ) {
			$link = '';
			foreach ( $node->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'link' === $child->localName ) {
					$rel = $child->getAttribute( 'rel' );
					if ( '' === $rel || 'alternate' === $rel ) {
						$link = trim( $child->getAttribute( 'href' ) );
						break;
					}
				}
			}
			$date     = self::child_text_ns( $node, 'published' );
			$reliable = '' !== $date;
			if ( '' === $date ) {
				$date = self::child_text_ns( $node, 'updated' );
			}
			$summary = self::child_text_ns( $node, 'summary' );
			if ( '' === $summary ) {
				$summary = self::child_text_ns( $node, 'content' );
			}
			$items[] = array(
				'title'       => self::clean_text( self::child_text_ns( $node, 'title' ) ),
				'link'        => $link,
				'guid'        => trim( self::child_text_ns( $node, 'id' ) ),
				'published'   => self::parse_date( $date ),
				'date_reliable' => $reliable,
				'snippet'     => self::clean_text( $summary ),
				'source_name' => '',
				'source_url'  => '',
				'author'      => '',
			);
		}
		return $items;
	}

	private static function parse_urlset( \DOMDocument $doc ): array {
		$items = array();
		foreach ( $doc->getElementsByTagNameNS( '*', 'url' ) as $node ) {
			$loc = self::child_text_ns( $node, 'loc' );
			if ( '' === $loc ) {
				continue;
			}
			$title = '';
			$date  = '';
			foreach ( $node->getElementsByTagNameNS( '*', 'title' ) as $title_node ) {
				$title = self::clean_text( $title_node->textContent );
				break;
			}
			foreach ( $node->getElementsByTagNameNS( '*', 'publication_date' ) as $date_node ) {
				$date = trim( $date_node->textContent );
				break;
			}
			if ( '' === $date ) {
				$date = self::child_text_ns( $node, 'lastmod' );
			}
			$items[] = array(
				'title'       => $title,
				'link'        => trim( $loc ),
				'guid'        => '',
				'published'   => self::parse_date( $date ),
				'snippet'     => '',
				'source_name' => '',
				'source_url'  => '',
				'author'      => '',
			);
		}
		return $items;
	}

	private static function child_text( \DOMElement $node, string $name ): string {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $child->nodeName === $name ) {
				return (string) $child->textContent;
			}
		}
		return '';
	}

	private static function child_text_ns( \DOMElement $node, string $local ): string {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $child->localName === $local ) {
				return (string) $child->textContent;
			}
		}
		return '';
	}

	public static function clean_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function parse_date( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$ts = strtotime( $value );
		return false === $ts ? 0 : $ts;
	}
}
