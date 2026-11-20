<?php
namespace ZZZCopy\Images;

use ZZZCopy\OpenRouter;
use ZZZCopy\Settings;
use ZZZCopy\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the AI model (the one set on the AI Settings page) what a picture for an article must show,
 * and which image searches find it. From 1.11.10.
 *
 * The word rules in ArticleAnalyzer guess the subject, and every wrong picture so far came from a
 * wrong guess: "November" as the subject of a Doha film festival story, "Halo" matching a halo
 * around the sun. The model reads the whole article and names its core instead.
 *
 * Whatever the model says is checked against the article before it is used: every search word
 * and every name must be in the article's own text, so the model cannot send the search somewhere
 * the story is not. When the call fails, or nothing it said survives the checks, the plugin's own
 * searches are used, so a post never waits on this step.
 */
final class ImageQueries {

	/** One small request per article. The answer is a short JSON object. */
	private const MAX_TOKENS = 700;

	/** How much of the article the model reads, in characters. The core is in the first part. */
	private const ARTICLE_CHARS = 6000;

	private const CACHE_SECONDS = 259200;

	/** Words a search may have even when the article does not use them. */
	private const ALLOWED_EXTRA = array( 'the', 'of', 'and', 'in', 'at', 'on', 'for', 'a', 'an', 'de', 'la', 'le', 'du', 'von', 'van', 'al', 'el' );

	public static function prompt(): string {
		return 'You choose search words to find a real photograph for a news article, in a free photo library of Flickr and Wikimedia Commons pictures (Openverse). '
			. 'The library matches words in each picture\'s title and tags, and only has pictures of real, well-known people, places, events, organisations and products. '
			. "Read the article, decide what it is about at its core, and reply with JSON only, no other text, in this form:\n"
			. '{"core":"...","names":["..."],"searches":["..."],"avoid":["..."],"place":"..."}' . "\n"
			. "Rules:\n"
			. "- core: one short line saying what the article is about.\n"
			. "- names: 1 to 3 proper names that a correct picture would be of, the most central first: the person, event, organisation, product, building or place the story is about. Copy each exactly as the article writes it. Never a date, month, weekday, number or common word.\n"
			. "- searches: 3 to 5 searches, the most exact first. Each is 1 to 4 words, using only words that are in the article. Put a name of two or more words in double quotes, like \"Doha Film Festival\". Start with the central name. Then the name with one word of context from the article (for a video game called Halo: \"Halo\" Xbox). End with a broader search that still names the subject, such as its organisation, venue or city. Never search a date, a month or a common word on its own.\n"
			. "- avoid: up to 5 words that would label a picture of something else with the same name, which the article does not use. For the Halo video game: sun, optics, saint. Empty when the names are unambiguous.\n"
			. "- place: the city, or else the country, where the story happens, written as in the article, or \"\" when it does not say.";
	}

	/**
	 * The messages sent to the model.
	 */
	public static function messages( string $title, string $headline, string $body, array $tags ): array {
		$text = self::plain( $body );
		if ( mb_strlen( $text ) > self::ARTICLE_CHARS ) {
			$text = mb_substr( $text, 0, self::ARTICLE_CHARS ) . ' ...';
		}
		$user = 'Title: ' . self::plain( $title ) . "\n"
			. ( '' !== trim( $headline ) && trim( $headline ) !== trim( $title ) ? 'Source headline: ' . self::plain( $headline ) . "\n" : '' )
			. ( $tags ? 'Tags: ' . implode( ', ', array_map( 'strval', $tags ) ) . "\n" : '' )
			. "Article:\n" . $text;
		return array(
			array(
				'role'    => 'system',
				'content' => self::prompt(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	private static function plain( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#</(p|h[1-6]|li|tr)>#i', "$0\n", $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[ \t]+/u', ' ', (string) preg_replace( '/\n\s*\n+/u', "\n", $text ) ) );
	}

	/**
	 * Asks the model, once per article. The answer is kept for three days, so a retry of the image
	 * step does not pay again.
	 *
	 * @return array{ok:bool, core:string, names:array, searches:array, avoid:array, place:string, error:string, cached:bool}
	 */
	public static function for_article( int $article_job_id, string $title, string $headline, string $body, array $tags ): array {
		$key = 'zzzc_image_ai_' . md5( $article_job_id . '|' . $title );
		if ( $article_job_id > 0 ) {
			$saved = get_transient( $key );
			if ( is_array( $saved ) && ! empty( $saved['ok'] ) ) {
				$saved['cached'] = true;
				return $saved;
			}
		}

		$model = (string) Settings::get( 'model_id' );
		if ( '' === $model ) {
			return self::failed( 'No AI model is set on the AI Settings page.' );
		}
		$result = OpenRouter::chat( self::messages( $title, $headline, $body, $tags ), $model, self::MAX_TOKENS );
		Usage::record( 'image_search', $article_job_id, $result );
		if ( ! $result['ok'] ) {
			return self::failed( (string) $result['message'] );
		}

		$checked = self::check( (string) $result['content'], $title . ' . ' . $headline . ' . ' . implode( ' . ', array_map( 'strval', $tags ) ) . ' . ' . self::plain( $body ) );
		if ( $checked['ok'] && $article_job_id > 0 ) {
			set_transient( $key, $checked, self::CACHE_SECONDS );
		}
		return $checked;
	}

	private static function failed( string $error ): array {
		return array(
			'ok'       => false,
			'core'     => '',
			'names'    => array(),
			'searches' => array(),
			'avoid'    => array(),
			'place'    => '',
			'error'    => $error,
			'cached'   => false,
		);
	}

	/**
	 * Reads the model's answer and keeps only what the article supports.
	 */
	public static function check( string $reply, string $article ): array {
		$start = strpos( $reply, '{' );
		$end   = strrpos( $reply, '}' );
		$data  = false !== $start && false !== $end && $end > $start ? json_decode( substr( $reply, $start, $end - $start + 1 ), true ) : null;
		if ( ! is_array( $data ) ) {
			return self::failed( 'The AI reply was not the JSON that was asked for.' );
		}

		// Punctuation is ignored on both sides, so "Halo Campaign Evolved" is found in "Halo: Campaign Evolved".
		$flat = static function ( string $words ): string {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\'’&\-]+/u', ' ', mb_strtolower( $words ) ) ) );
		};
		$text = ' ' . $flat( html_entity_decode( wp_strip_all_tags( $article ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' ';
		$in   = static function ( string $phrase ) use ( $text, $flat ): bool {
			$phrase = $flat( $phrase );
			return '' !== $phrase && 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?:s|es|\'s|’s)?(?![\p{L}\p{N}])/u', $text );
		};
		$list = static function ( $value ): array {
			return is_array( $value ) ? array_values( array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), static function ( $item ) {
				return '' !== trim( $item );
			} ) ) : array();
		};

		$names = array();
		foreach ( array_slice( $list( $data['names'] ?? array() ), 0, 3 ) as $name ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( '"', '“', '”' ), '', $name ) ) );
			if ( mb_strlen( $name ) >= 2 && mb_strlen( $name ) <= 60 && $in( $name ) && ! self::is_date( $name ) ) {
				$names[] = $name;
			}
		}

		$searches = array();
		$seen     = array();
		foreach ( array_slice( $list( $data['searches'] ?? array() ), 0, 6 ) as $search ) {
			$search = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( '“', '”' ), '"', $search ) ) );
			$search = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s"\'’&.\-]+/u', ' ', $search ) ) );
			$search = trim( (string) preg_replace( '/"\s*([^"]*?)\s*"/u', '"$1"', $search ) );
			if ( '' === $search || mb_strlen( $search ) > 80 || 0 !== substr_count( $search, '"' ) % 2 ) {
				continue;
			}
			// Each quoted phrase, and every other word, must be in the article.
			$supported = true;
			preg_match_all( '/"([^"]+)"/u', $search, $quoted );
			foreach ( $quoted[1] as $phrase ) {
				if ( ! $in( $phrase ) ) {
					$supported = false;
				}
			}
			$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/"[^"]*"/u', ' ', $search ) ) ) ?: array();
			$loose = array();
			foreach ( $words as $word ) {
				$word = trim( (string) $word, ".-'’&" );
				if ( '' === $word ) {
					continue;
				}
				$loose[] = $word;
				if ( ! in_array( mb_strtolower( $word ), self::ALLOWED_EXTRA, true ) && ! $in( $word ) ) {
					$supported = false;
				}
			}
			if ( ! $supported ) {
				continue;
			}
			// A date, a number or a very short word finds anything. A search of nothing else is dropped.
			if ( ! $quoted[1] ) {
				$useful = array_filter(
					$loose,
					static function ( $word ) {
						return mb_strlen( $word ) >= 3 && ! ctype_digit( $word ) && ! self::is_date( $word );
					}
				);
				if ( ! $useful ) {
					continue;
				}
			}
			$key = mb_strtolower( (string) preg_replace( '/\s+/u', ' ', $search ) );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$searches[]   = $search;
			}
		}

		$avoid = array();
		foreach ( array_slice( $list( $data['avoid'] ?? array() ), 0, 5 ) as $word ) {
			$word = trim( mb_strtolower( (string) preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $word ) ) );
			$word = trim( (string) preg_replace( '/\s+/u', ' ', $word ) );
			// A word the article uses itself would refuse the right picture.
			if ( mb_strlen( $word ) >= 3 && substr_count( $word, ' ' ) < 3 && ! $in( $word ) ) {
				$avoid[] = $word;
			}
		}

		$place = trim( str_replace( array( '"', '“', '”' ), '', (string) ( is_scalar( $data['place'] ?? '' ) ? ( $data['place'] ?? '' ) : '' ) ) );
		if ( '' !== $place && ( mb_strlen( $place ) > 40 || ! $in( $place ) ) ) {
			$place = '';
		}

		if ( ! $searches ) {
			$out          = self::failed( 'None of the AI searches used the article\'s own words.' );
			$out['names'] = $names;
			return $out;
		}

		return array(
			'ok'       => true,
			'core'     => mb_substr( trim( wp_strip_all_tags( (string) ( is_scalar( $data['core'] ?? '' ) ? ( $data['core'] ?? '' ) : '' ) ) ), 0, 200 ),
			'names'    => $names,
			'searches' => $searches,
			'avoid'    => array_values( array_unique( $avoid ) ),
			'place'    => $place,
			'error'    => '',
			'cached'   => false,
		);
	}

	private static function is_date( string $word ): bool {
		return 1 === preg_match( '/^(january|february|march|april|may|june|july|august|september|october|november|december|monday|tuesday|wednesday|thursday|friday|saturday|sunday|today|yesterday|tomorrow)(\s+\d{1,4})?$|^\d{1,4}(\s+\w+)?$/iu', trim( $word ) );
	}

	/**
	 * Puts the model's answer to use in the analysis the scorer reads.
	 *
	 * The model's searches come first, and the plugin's own fill any searches left over, so the
	 * total stays within "Searches for each article". The model's first name becomes the main
	 * subject, and its names lead the subjects, so a file must name them to stop the searching
	 * early and to be chosen before files that only share words with the story.
	 */
	public static function apply( array $analysis, array $ai ): array {
		if ( empty( $ai['ok'] ) ) {
			return $analysis;
		}

		$limit   = max( 1, (int) Settings::get( 'image_query_count' ) );
		$queries = array();
		$seen    = array();
		foreach ( array_merge( (array) $ai['searches'], (array) $analysis['queries'] ) as $query ) {
			$words = preg_split( '/\s+/u', mb_strtolower( trim( str_replace( '"', ' ', (string) $query ) ) ) ) ?: array();
			sort( $words );
			$key = implode( ' ', $words );
			if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$queries[]    = (string) $query;
			}
		}
		$analysis['queries'] = array_slice( $queries, 0, $limit );

		$names = (array) $ai['names'];
		if ( $names ) {
			$analysis['main']     = (string) $names[0];
			$entities             = array_merge( $names, (array) $analysis['entities'] );
			$analysis['entities'] = array_values( array_unique( $entities ) );
		}
		$analysis['names'] = $names;
		$analysis['avoid'] = (array) $ai['avoid'];
		$analysis['core']  = (string) $ai['core'];

		if ( '' !== (string) $ai['place'] ) {
			$place                = (string) $ai['place'];
			$quoted               = false !== strpos( $place, ' ' ) ? '"' . $place . '"' : $place;
			$analysis['place']    = $place;
			$analysis['fallback'] = array( $quoted . ' skyline', $quoted . ' cityscape' );
		}
		return $analysis;
	}
}
