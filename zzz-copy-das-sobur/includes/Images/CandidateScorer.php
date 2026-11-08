<?php
namespace ZZZCopy\Images;

use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Scores files against an article and applies the mandatory rules.
 *
 * This reads the labels on a file, not the picture. It cannot see what an image shows,
 * so it is deliberately strict: a doubtful image is rejected, and the post goes without one.
 */
final class CandidateScorer {

	private const ADULT = '/\b(nude|nudes|nudity|naked|nudist|topless|erotic|erotica|porn|pornography|lingerie|genitals?|penis|vagina|breasts?|sexual|sex|fetish|bdsm|striptease)\b/iu';

	private const GRAPHIC = '/\b(corpses?|dead bod(y|ies)|cadavers?|gore|gory|beheaded|decapitated|execution|autopsy|mutilated|massacre victims?|lynching|hanged)\b/iu';

	private const WATERMARK = '/\b(watermark(ed|s)?|stock photo|shutterstock|getty images|alamy|istock|dreamstime)\b/iu';

	private const NOT_A_PHOTO = '/\b(chart|graph|diagram|infographic|logo|logos|coat of arms|flag of|icon|seal of|map of|locator map|screenshot|postage stamp|stamps?|banknote|scanned|document|letterhead|patent|poster|cartoon|clip ?art|percent of|food pyramid|statistics)\b/iu';

	private const PEOPLE = '/\b(portrait|portraits|headshot|people|person|persons|\bmen\b|\bwomen\b|man|woman|boy|girl|crowd|selfie|group photo|wedding|family|living people|\d{4} births)\b/iu';

	private const MINORS = '/\b(child|children|kid|kids|baby|babies|infant|infants|toddler|schoolchildren|minor|minors|teen|teens|teenager|teenagers)\b/iu';

	private const VICTIMS = '/\b(patient|patients|victim|victims|suspect|suspects|prisoner|prisoners|mugshot|arrested|wounded|injured)\b/iu';

	/** Words alone that describe a group of people, not one subject. */
	private const GROUPS = '/^(police|officers?|officials?|doctors?|nurses?|workers?|residents?|students?|teachers?|soldiers?|troops|protesters?|voters?|investors?|customers?|fans|parents?|researchers?|experts?|witnesses|neighbors|neighbours)$/iu';

	/** Categories where a wrongly used photo of a person could do real harm. */
	private const SENSITIVE_CATEGORY = '/\b(crime|war|conflict|medical|health)\b/iu';

	/**
	 * Last words that make a capitalized run a place, a building or an organisation rather than a
	 * person. "Oak Street Health" and "Apple Store TRX" name things; "Barack Obama" names someone.
	 */
	private const NOT_PERSON_WORDS = array(
		'street', 'avenue', 'av', 'road', 'lane', 'drive', 'boulevard', 'square', 'bridge', 'tower',
		'building', 'house', 'hall', 'castle', 'church', 'cathedral', 'temple', 'mosque', 'museum',
		'gallery', 'library', 'park', 'garden', 'gardens', 'beach', 'river', 'lake', 'bay', 'island',
		'mountain', 'valley', 'forest', 'station', 'airport', 'port', 'harbour', 'harbor', 'stadium',
		'arena', 'theatre', 'theater', 'hospital', 'clinic', 'school', 'college', 'university',
		'academy', 'institute', 'hotel', 'store', 'shop', 'market', 'mall', 'centre', 'center',
		'apartments', 'health', 'bank', 'company', 'corporation', 'group', 'holdings', 'ltd',
		'limited', 'inc', 'plc', 'llc', 'gmbh', 'county', 'city', 'town', 'village', 'state',
		'province', 'district', 'region', 'republic', 'kingdom', 'union', 'federation', 'council',
		'ministry', 'department', 'agency', 'office', 'bureau', 'commission', 'committee', 'party',
		'club', 'society', 'association', 'foundation', 'trust', 'force', 'army', 'navy', 'police',
		'railway', 'line', 'route', 'highway', 'festival', 'conference', 'summit', 'games',
		'championship', 'cup', 'league', 'series', 'show', 'awards', 'museum', 'zoo',
	);

	/**
	 * Proper names in a file's own title that look like a person rather than a place or an
	 * organisation. Two or three capitalized words, all letters, not ending in a word that makes
	 * the run a thing.
	 *
	 * The file title is used rather than the whole label text, because a title describes what the
	 * picture shows, while the categories describe everything it is filed under.
	 *
	 * @return string[]
	 */
	public static function person_names_in_title( array $candidate ): array {
		$title = (string) preg_replace( '/^File:/i', '', (string) $candidate['title'] );
		$title = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $title );
		$title = str_replace( array( '_', '-' ), ' ', $title );
		// Anything in brackets is a photographer's reference or a date, not what is shown.
		$title = (string) preg_replace( '/\([^)]*\)/u', ' ', $title );

		// Whole runs, with no upper bound, so a long run is seen for what it is. Checking a window
		// of two or three words inside a longer run would read "Moda Apartments Oak Street Health"
		// as a person called "Moda Apartments Oak".
		preg_match_all( '/\p{Lu}[\p{Ll}\'’]+(?:\s+\p{Lu}[\p{Ll}\'’]+)*/u', $title, $match );

		$found = array();
		foreach ( $match[0] as $run ) {
			$run   = (string) preg_replace( '/[\'’]s$/u', '', trim( $run ) );
			$words = preg_split( '/\s+/u', $run ) ?: array();
			// A person's name is two or three words. Longer is a place or an organisation.
			if ( count( $words ) < 2 || count( $words ) > 3 ) {
				continue;
			}
			// A two-letter part is an abbreviation, as in "Parsons Bl" for a boulevard.
			$short = false;
			foreach ( $words as $word ) {
				if ( mb_strlen( (string) $word ) < 3 ) {
					$short = true;
				}
			}
			if ( $short ) {
				continue;
			}
			$last = mb_strtolower( (string) end( $words ) );
			if ( in_array( $last, self::NOT_PERSON_WORDS, true ) ) {
				continue;
			}
			// A run that begins with a describing word, such as "Boy feeling", is not a name.
			$first = mb_strtolower( (string) $words[0] );
			if ( in_array( $first, self::NOT_PERSON_WORDS, true ) ) {
				continue;
			}
			$found[] = $run;
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether the article mentions this name anywhere: its subjects, its tags or its own words.
	 */
	private static function article_mentions( string $name, array $analysis ): bool {
		$haystacks = array_merge(
			(array) ( $analysis['entities'] ?? array() ),
			(array) ( $analysis['concepts'] ?? array() ),
			(array) ( $analysis['keywords'] ?? array() ),
			array( (string) ( $analysis['main'] ?? '' ), (string) ( $analysis['text'] ?? '' ) )
		);

		foreach ( $haystacks as $haystack ) {
			$haystack = (string) $haystack;
			if ( '' === trim( $haystack ) ) {
				continue;
			}
			if ( self::has_phrase( mb_strtolower( $haystack ), $name ) ) {
				return true;
			}
		}

		// A surname on its own is enough: an article about "Dana White" that calls him "White"
		// still mentions the person in a photograph titled "Dana White at UFC 300".
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$last  = (string) end( $words );
		if ( mb_strlen( $last ) >= 4 ) {
			foreach ( $haystacks as $haystack ) {
				$haystack = (string) $haystack;
				if ( '' !== trim( $haystack ) && self::has_phrase( mb_strtolower( $haystack ), $last ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * All the words Commons has about a file.
	 */
	public static function label_text( array $candidate ): string {
		$meta   = (array) $candidate['extmetadata'];
		$fields = array();
		foreach ( array( 'ObjectName', 'ImageDescription', 'Categories' ) as $name ) {
			$value = $meta[ $name ]['value'] ?? '';
			if ( is_scalar( $value ) ) {
				$fields[] = str_replace( '|', ' ', (string) $value );
			}
		}
		$title = (string) preg_replace( '/^File:/i', '', (string) $candidate['title'] );
		$title = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $title );
		$text  = str_replace( array( '_', '-' ), ' ', $title ) . ' ' . implode( ' ', $fields );
		return mb_strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Whether the labels contain a whole word or phrase, or its plural. "Ali" does not match "quality".
	 */
	private static function has_phrase( string $text, string $phrase ): bool {
		$phrase = mb_strtolower( trim( $phrase ) );
		return '' !== $phrase && 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . 's?(?![\p{L}\p{N}])/u', $text );
	}

	/**
	 * Whether an entity names a specific subject. A word for a kind of person, such as "Patients" or
	 * "Police", does not, so a photo labeled with it is not "about them".
	 */
	/**
	 * Whether a name looks like a person rather than a place, a building or an organisation.
	 * Two or three capitalized words, none of which makes the name a thing.
	 */
	public static function looks_like_a_person( string $name ): bool {
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		if ( count( $words ) < 2 || count( $words ) > 3 ) {
			return false;
		}
		foreach ( $words as $word ) {
			if ( ! preg_match( '/^\p{Lu}[\p{Ll}\'’]+$/u', (string) $word ) ) {
				return false;
			}
			if ( in_array( mb_strtolower( (string) $word ), self::NOT_PERSON_WORDS, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function names_a_subject( string $entity ): bool {
		return ! preg_match( self::MINORS, $entity )
			&& ! preg_match( self::VICTIMS, $entity )
			&& ! preg_match( self::PEOPLE, $entity )
			&& ! preg_match( self::GROUPS, trim( $entity ) );
	}

	/**
	 * Rules that no score can override, other than license and history.
	 *
	 * @return string Reason for rejection, or empty when it passes.
	 */
	public static function mandatory_failure( array $candidate, array $analysis, string $category_name ): string {
		if ( ! in_array( $candidate['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return 'The file type is not allowed: ' . $candidate['mime'] . '.';
		}
		if ( (int) $candidate['width'] < (int) Settings::get( 'image_min_width' ) || (int) $candidate['height'] < (int) Settings::get( 'image_min_height' ) ) {
			return sprintf( 'Too small: %1$d by %2$d pixels.', $candidate['width'], $candidate['height'] );
		}
		if ( (int) $candidate['width'] <= (int) $candidate['height'] ) {
			return 'Not a landscape image.';
		}
		if ( (int) $candidate['size'] > (int) Settings::get( 'image_max_file_mb' ) * 1048576 ) {
			return sprintf( 'The file is over the %d MB limit.', Settings::get( 'image_max_file_mb' ) );
		}
		if ( ! Commons::is_media_url( (string) $candidate['file_url'] ) ) {
			return 'The file address is not on an approved Wikimedia host.';
		}

		$text = self::label_text( $candidate );

		if ( preg_match( self::ADULT, $text ) ) {
			return 'The labels suggest adult content.';
		}
		if ( preg_match( self::GRAPHIC, $text ) ) {
			return 'The labels suggest graphic content.';
		}
		if ( preg_match( self::WATERMARK, $text ) ) {
			return 'The labels suggest a watermark or stock image.';
		}
		if ( preg_match( self::NOT_A_PHOTO, $text ) ) {
			return 'The labels suggest a chart, logo, map, scan or similar, not a photograph.';
		}

		// A picture whose own title names a person the article never mentions is the wrong picture,
		// however well the rest of its labels happen to match. A photograph titled "Boy feeling
		// Barack Obama's hair" is filed under the White House, so for an article about the White
		// House press secretary it matches on keywords, on an entity and on concepts, and scores
		// highly. It is still a photograph of Barack Obama and a child, and the article is about
		// neither. This is checked before anything else about people, because it is the clearest
		// signal that the picture is of someone else entirely.
		foreach ( self::person_names_in_title( $candidate ) as $person ) {
			if ( ! self::article_mentions( $person, $analysis ) ) {
				return sprintf( 'The picture is of %s, who the article does not mention.', $person );
			}
		}

		// Whether the article is about the people this picture shows. Only a person counts here.
		// An article mentioning a place or an organisation that the picture is also filed under,
		// such as "White House", does not make a photograph of a child in it fair to use, so a
		// match on something that is not a person does not switch the protections below off.
		$about_them = false;
		foreach ( (array) $analysis['entities'] as $entity ) {
			$entity = (string) $entity;
			if ( self::names_a_subject( $entity ) && self::looks_like_a_person( $entity ) && self::has_phrase( $text, $entity ) ) {
				$about_them = true;
				break;
			}
		}

		if ( ! $about_them ) {
			if ( preg_match( self::MINORS, $text ) ) {
				return 'The labels mention children, and the article is not about them.';
			}
			if ( preg_match( self::VICTIMS, $text ) ) {
				return 'The labels mention patients, victims or suspects, and the article is not about them.';
			}
			if ( preg_match( self::PEOPLE, $text ) ) {
				return 'The labels suggest identifiable people, and the article is not about them.';
			}
			if ( preg_match( self::SENSITIVE_CATEGORY, $category_name ) && preg_match( '/\b(people|person|persons|men|women)\b/iu', $text ) ) {
				return 'A sensitive category needs an image of the article subject itself.';
			}
		}

		return '';
	}

	/**
	 * Scores relevance and quality out of 100.
	 *
	 * @return array{total:int, parts:array}
	 */
	public static function score( array $candidate, array $analysis ): array {
		$text = self::label_text( $candidate );

		$keywords = (array) $analysis['keywords'];
		$matched  = 0;
		foreach ( $keywords as $word ) {
			if ( preg_match( '/\b' . preg_quote( (string) $word, '/' ) . '\b/iu', $text ) ) {
				$matched++;
			}
		}
		$subject = $keywords ? (int) round( 35 * min( 1, $matched / min( 3, count( $keywords ) ) ) ) : 0;

		$entity   = 0;
		$entities = (array) $analysis['entities'];
		if ( '' !== (string) $analysis['main'] && self::has_phrase( $text, (string) $analysis['main'] ) ) {
			$entity = 20;
		} else {
			foreach ( $entities as $name ) {
				if ( self::has_phrase( $text, (string) $name ) ) {
					$entity = 10;
					break;
				}
			}
		}

		$concept_hits = 0;
		foreach ( (array) $analysis['concepts'] as $concept ) {
			if ( self::has_phrase( $text, (string) $concept ) ) {
				$concept_hits++;
			}
		}
		$concept = (int) min( 15, $concept_hits * 5 );

		$width   = (int) $candidate['width'];
		$height  = (int) $candidate['height'];
		$quality = $width >= 3000 ? 10 : ( $width >= 1600 ? 7 : 4 );
		$assess  = (string) ( $candidate['extmetadata']['Assessments']['value'] ?? '' );
		if ( '' !== trim( $assess ) ) {
			$quality += 5;
		}

		$ratio     = $height > 0 ? $width / $height : 0;
		$preferred = (float) Settings::get( 'image_preferred_ratio' );
		$fit       = max( 0, 5 - (int) round( abs( $ratio - $preferred ) * 6 ) );
		$shape     = $fit + ( $width >= 1600 && $height >= 900 ? 5 : 2 );

		$context = preg_match( '/\b(drawing|illustration|painting|engraving|sketch|artwork|render|rendering|model)\b/iu', $text ) ? 0 : 5;

		$parts = array(
			'subject' => $subject,
			'entity'  => $entity,
			'concept' => $concept,
			'quality' => min( 15, $quality ),
			'shape'   => min( 10, $shape ),
			'context' => $context,
		);

		return array(
			'total' => (int) array_sum( $parts ),
			'parts' => $parts,
		);
	}
}
