<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Splits model output into a title and a body, from HTML or Markdown.
 * Removes prompt labels such as "H2:" and every image element.
 */
final class ContentParser {

	/**
	 * @return array{title:string, body:string, preamble:string, format:string}
	 */
	public static function parse( string $raw ): array {
		$text = trim( $raw );

		if ( preg_match( '/^```[a-zA-Z]*\s*\n(.*?)\n?```\s*$/s', $text, $fence ) ) {
			$text = trim( $fence[1] );
		}

		$text = (string) preg_replace( '/<!doctype[^>]*>/i', '', $text );
		$text = (string) preg_replace( '#<head\b.*?</head>#is', '', $text );
		$text = (string) preg_replace( '#</?(html|body)\b[^>]*>#i', '', $text );
		$text = trim( $text );

		$format = preg_match( '/<(h[1-6]|p|table|ul|ol)\b/i', $text ) ? 'html' : 'markdown';
		if ( 'markdown' === $format ) {
			$text = self::markdown_to_html( $text );
		}

		return array_merge( self::split( $text ), array( 'format' => $format ) );
	}

	private static function split( string $html ): array {
		$out = array(
			'title'    => '',
			'body'     => '',
			'preamble' => '',
		);

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="zzzc-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$doc->encoding = 'UTF-8';

		$root = $doc->getElementById( 'zzzc-root' );
		if ( ! $root ) {
			return $out;
		}

		$xpath = new \DOMXPath( $doc );

		foreach ( array( 'img', 'figure', 'picture', 'svg', 'iframe', 'script', 'style', 'video', 'audio', 'source', 'object', 'embed' ) as $tag ) {
			$nodes = array();
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				$nodes[] = $node;
			}
			foreach ( $nodes as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Find the title: the first h1, or a short first line when there is no h1.
		$title_node = null;
		foreach ( $root->getElementsByTagName( 'h1' ) as $candidate ) {
			$title_node = $candidate;
			break;
		}

		$children = array();
		foreach ( $root->childNodes as $child ) {
			$children[] = $child;
		}

		if ( $title_node ) {
			$preamble = array();
			foreach ( $children as $child ) {
				if ( self::holds( $child, $title_node ) ) {
					break;
				}
				$piece = trim( (string) $child->textContent );
				if ( '' !== $piece ) {
					$preamble[] = $piece;
				}
				$root->removeChild( $child );
			}
			$out['preamble'] = implode( ' ', $preamble );
			$out['title']    = self::clean_label( self::inline_text( $title_node ) );
			$title_node->parentNode->removeChild( $title_node );
		} else {
			foreach ( $children as $child ) {
				$piece = trim( (string) $child->textContent );
				if ( '' === $piece ) {
					continue;
				}
				$is_block = $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), array( 'table', 'ul', 'ol' ), true );
				if ( ! $is_block && mb_strlen( $piece ) <= 200 ) {
					$out['title'] = self::clean_label( $piece );
					$root->removeChild( $child );
				}
				break;
			}
		}

		// Further h1 elements become h2, because the title has its own field.
		$extra = array();
		foreach ( $root->getElementsByTagName( 'h1' ) as $node ) {
			$extra[] = $node;
		}
		foreach ( $extra as $node ) {
			self::rename( $doc, $node, 'h2' );
		}

		self::clean_headings( $doc, $xpath, $root );

		$body = '';
		foreach ( $root->childNodes as $child ) {
			$body .= $doc->saveHTML( $child );
		}
		$out['body'] = trim( $body );

		return $out;
	}

	/**
	 * Whether a node is, or contains, another node.
	 */
	private static function holds( \DOMNode $outer, \DOMNode $inner ): bool {
		for ( $node = $inner; $node; $node = $node->parentNode ) {
			if ( $node === $outer ) {
				return true;
			}
		}
		return false;
	}

	private static function inline_text( \DOMElement $node ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
	}

	private static function rename( \DOMDocument $doc, \DOMElement $node, string $tag ): void {
		$new = $doc->createElement( $tag );
		while ( $node->firstChild ) {
			$new->appendChild( $node->firstChild );
		}
		$node->parentNode->replaceChild( $new, $node );
	}

	/**
	 * Removes prompt labels such as "H2:" and turns a bare "Trending FAQ:" line into a heading.
	 */
	private static function clean_headings( \DOMDocument $doc, \DOMXPath $xpath, \DOMElement $root ): void {
		$nodes = $xpath->query( './/h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//p', $root );
		$list  = array();
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$list[] = $node;
			}
		}
		foreach ( $list as $node ) {
			$text = trim( (string) $node->textContent );
			if ( preg_match( '/^H[1-6]\s*(\([^)]*\))?\s*:?\s*$/i', $text ) ) {
				$node->parentNode->removeChild( $node );
				continue;
			}
			$is_p = 'p' === strtolower( $node->nodeName );
			if ( preg_match( '/^H([2-6])\s*(\([^)]*\))?\s*[:\-]\s*(.+)$/iu', $text, $match ) ) {
				$new = $doc->createElement( 'h' . $match[1] );
				$new->appendChild( $doc->createTextNode( trim( $match[3] ) ) );
				$node->parentNode->replaceChild( $new, $node );
				continue;
			}
			if ( $is_p && preg_match( '/^(trending\s+)?(faqs?|frequently asked questions)\s*:?$/iu', $text ) ) {
				$new = $doc->createElement( 'h2' );
				$new->appendChild( $doc->createTextNode( rtrim( $text, ': ' ) ) );
				$node->parentNode->replaceChild( $new, $node );
			}
		}
	}

	private static function clean_label( string $text ): string {
		$text = (string) preg_replace( '/^\s*(\*\*|__)?\s*(H1|Title|Headline)\s*:\s*/i', '', $text );
		return trim( $text, " \t\n\r*_#" );
	}

	/**
	 * Converts the Markdown forms that models commonly return. All text is escaped first.
	 */
	public static function markdown_to_html( string $markdown ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $markdown ) ?: array();
		$html  = array();
		$para  = array();
		$count = count( $lines );

		$flush = static function () use ( &$para, &$html ) {
			if ( $para ) {
				$html[] = '<p>' . self::inline_markdown( implode( ' ', $para ) ) . '</p>';
				$para   = array();
			}
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$line = rtrim( $lines[ $i ] );
			$trim = trim( $line );

			if ( '' === $trim ) {
				$flush();
				continue;
			}

			if ( preg_match( '/^!\[[^\]]*\]\([^)]*\)$/', $trim ) ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $match ) ) {
				$flush();
				$level  = strlen( $match[1] );
				$label  = (string) preg_replace( '/^H[1-6]\s*(\([^)]*\))?\s*:\s*/i', '', $match[2] );
				$html[] = sprintf( '<h%1$d>%2$s</h%1$d>', $level, self::inline_markdown( $label ) );
				continue;
			}

			$stripped = trim( $trim, '*_ ' );
			if ( preg_match( '/^H([1-6])\s*(\([^)]*\))?\s*:\s*(.*)$/i', $stripped, $match ) ) {
				$flush();
				if ( '' !== trim( $match[3] ) ) {
					$html[] = sprintf( '<h%1$d>%2$s</h%1$d>', (int) $match[1], self::inline_markdown( trim( $match[3] ) ) );
				}
				continue;
			}

			if ( false !== strpos( $trim, '|' ) && $i + 1 < $count && preg_match( '/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim( $lines[ $i + 1 ] ) ) ) {
				$flush();
				$rows   = array( self::table_cells( $trim ) );
				$i     += 2;
				while ( $i < $count && false !== strpos( $lines[ $i ], '|' ) && '' !== trim( $lines[ $i ] ) ) {
					$rows[] = self::table_cells( trim( $lines[ $i ] ) );
					$i++;
				}
				$i--;
				$table = '<table><thead><tr>';
				foreach ( $rows[0] as $cell ) {
					$table .= '<th>' . self::inline_markdown( $cell ) . '</th>';
				}
				$table .= '</tr></thead><tbody>';
				foreach ( array_slice( $rows, 1 ) as $row ) {
					$table .= '<tr>';
					foreach ( $row as $cell ) {
						$table .= '<td>' . self::inline_markdown( $cell ) . '</td>';
					}
					$table .= '</tr>';
				}
				$html[] = $table . '</tbody></table>';
				continue;
			}

			if ( preg_match( '/^([-*+]|\d+[.)])\s+/', $trim, $marker ) ) {
				$flush();
				$tag   = is_numeric( $marker[1][0] ) ? 'ol' : 'ul';
				$items = array();
				while ( $i < $count && preg_match( '/^\s*([-*+]|\d+[.)])\s+(.*)$/', $lines[ $i ], $item ) ) {
					$items[] = '<li>' . self::inline_markdown( trim( $item[2] ) ) . '</li>';
					$i++;
				}
				$i--;
				$html[] = "<{$tag}>" . implode( '', $items ) . "</{$tag}>";
				continue;
			}

			if ( preg_match( '/^(\*\*|__)(.+)\1:?$/', $trim, $match ) && mb_strlen( $match[2] ) <= 200 && ! $html && ! $para ) {
				$html[] = '<h1>' . self::inline_markdown( $match[2] ) . '</h1>';
				continue;
			}

			$para[] = $trim;
		}
		$flush();

		return implode( "\n", $html );
	}

	private static function table_cells( string $line ): array {
		$line = trim( $line );
		$line = trim( $line, '|' );
		return array_map( 'trim', explode( '|', $line ) );
	}

	private static function inline_markdown( string $text ): string {
		$text = esc_html( $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/__(.+?)__/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text );
		$text = (string) preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $text );
		return (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $match ) {
				return '<a href="' . esc_url( $match[2] ) . '">' . $match[1] . '</a>';
			},
			$text
		);
	}
}
