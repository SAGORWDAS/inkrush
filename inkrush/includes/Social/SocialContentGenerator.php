<?php
namespace ZZZCopy\Social;

use ZZZCopy\OpenRouter;
use ZZZCopy\Prompts;
use ZZZCopy\Secrets;
use ZZZCopy\Settings;
use ZZZCopy\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the post text for each platform.
 *
 * This uses the plugin's existing OpenRouter client, key, model setting, timeout, error handling
 * and usage recording. No second AI provider is introduced, and the owner's article and tag
 * prompts are never touched: the instruction used here is its own prompt type, seeded from
 * defaults/social-prompt.txt and editable with the same version history as the others.
 *
 * Captions are written from the article the site has already published, never from the original
 * publisher's page, so the post can only repeat what the site itself says.
 *
 * One request covers the platforms asked for, because they share the same article and the same
 * instruction, and a single request costs less than six. Asking for one platform is therefore
 * also a single request, which is what regenerating one caption does: the other five are left
 * exactly as they were.
 */
final class SocialContentGenerator {

	/**
	 * Writes posts for the given platforms.
	 *
	 * @param array    $article   title, body, url, category, tags, keywords, image_alt.
	 * @param string[] $platforms Which platforms to write for.
	 * @return array{ok:bool, code:string, message:string, transient:bool, retry_after:int,
	 *               posts:array<string, array{text:string, hashtags:string, source:string}>}
	 */
	public static function generate( array $article, array $platforms ): array {
		$out = array(
			'ok'          => false,
			'code'        => '',
			'message'     => '',
			'transient'   => false,
			'retry_after' => 0,
			'posts'       => array(),
		);

		$platforms = array_values(
			array_filter(
				$platforms,
				static function ( $platform ): bool {
					return Platforms::exists( (string) $platform );
				}
			)
		);
		if ( ! $platforms ) {
			$out['code']    = 'no_platforms';
			$out['message'] = 'No platform was given to write a post for.';
			return $out;
		}

		// With caption writing switched off, every platform gets the plain fallback built from
		// the article's own title. Nothing is sent to OpenRouter and nothing is charged.
		if ( ! SocialSettings::generates_captions() ) {
			foreach ( $platforms as $platform ) {
				$out['posts'][ $platform ] = self::fallback( $article, $platform );
			}
			$out['ok'] = true;
			return $out;
		}

		if ( ! Secrets::has_api_key() ) {
			$out['code']    = 'no_api_key';
			$out['message'] = 'No OpenRouter API key is saved, so post text cannot be written. Add it on the AI Settings page.';
			return $out;
		}

		$instruction = Prompts::active_text( Prompts::SOCIAL );
		if ( '' === trim( $instruction ) ) {
			$instruction = Prompts::default_text( Prompts::SOCIAL );
		}

		$messages = self::messages( $article, $platforms, $instruction );
		$result   = OpenRouter::chat( $messages, (string) Settings::get( 'model_id' ), self::max_tokens( $platforms ) );

		Usage::record( 'social', (int) ( $article['article_job_id'] ?? 0 ), $result );

		if ( ! $result['ok'] ) {
			$out['code']        = (string) $result['code'];
			$out['message']     = (string) $result['message'];
			$out['transient']   = (bool) $result['transient'];
			$out['retry_after'] = (int) $result['retry_after'];
			return $out;
		}

		$parsed = self::parse( (string) $result['content'], $platforms );
		if ( ! $parsed ) {
			$out['code']      = 'unreadable_reply';
			$out['message']   = 'The model\'s reply could not be read as the requested JSON, so no post text was saved.';
			$out['transient'] = true;
			return $out;
		}

		foreach ( $platforms as $platform ) {
			if ( isset( $parsed[ $platform ] ) ) {
				$out['posts'][ $platform ] = $parsed[ $platform ];
				continue;
			}
			// The model left a platform out. That platform falls back rather than going out empty.
			$out['posts'][ $platform ] = self::fallback( $article, $platform );
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Builds the request. The saved instruction is included unchanged, with the platform details
	 * and the article placed before it.
	 */
	public static function messages( array $article, array $platforms, string $instruction ): array {
		$body = wp_strip_all_tags( (string) ( $article['body'] ?? '' ) );
		$body = (string) preg_replace( '/\s+/u', ' ', $body );
		// Enough of the article to be accurate, bounded so a long article cannot run the request up.
		$body = mb_substr( trim( $body ), 0, 9000 );

		$lines = array(
			'ARTICLE AS PUBLISHED ON THE WEBSITE',
			'',
			'Title: ' . wp_strip_all_tags( (string) ( $article['title'] ?? '' ) ),
		);
		if ( ! empty( $article['category'] ) ) {
			$lines[] = 'Category: ' . (string) $article['category'];
		}
		if ( ! empty( $article['tags'] ) ) {
			$lines[] = 'Tags: ' . implode( ', ', array_slice( (array) $article['tags'], 0, 20 ) );
		}
		if ( ! empty( $article['keywords'] ) ) {
			$lines[] = 'Keywords: ' . implode( ', ', array_slice( (array) $article['keywords'], 0, 15 ) );
		}
		if ( ! empty( $article['image_alt'] ) ) {
			$lines[] = 'The post will carry this picture: ' . (string) $article['image_alt'];
		}
		$lines[] = '';
		$lines[] = 'Article text:';
		$lines[] = $body;
		$lines[] = '';
		$lines[] = 'PLATFORMS';
		$lines[] = '';

		foreach ( $platforms as $platform ) {
			$lines[] = self::platform_block( $platform, (string) ( $article['url'] ?? '' ) );
			$lines[] = '';
		}

		$system = 'You write short social media posts from a news article that has already been published. You never add a fact that is not in the article. You reply with JSON only.';

		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => implode( "\n", $lines ) . "\n" . $instruction,
			),
		);
	}

	/**
	 * One platform's block in the request: its key, its limit, its tone and what it does with
	 * links and hashtags. These come from the platform registry, so the limit the model is told
	 * is always the limit the plugin then checks against.
	 */
	private static function platform_block( string $platform, string $url ): string {
		$with_link = SocialSettings::includes_url() && '' !== $url;
		$budget    = Platforms::text_budget( $platform, $with_link );
		$hashtags  = SocialSettings::generates_hashtags() ? (int) Platforms::value( $platform, 'hashtag_limit', 0 ) : 0;
		$link_mode = (string) Platforms::value( $platform, 'link_mode' );

		$lines = array(
			'--- Platform key: ' . $platform . ' (' . Platforms::label( $platform ) . ')',
			'Character limit for "text": ' . $budget,
			'Tone: ' . (string) Platforms::value( $platform, 'tone', 'Plain and factual.' ),
			'Hashtags: ' . ( $hashtags > 0 ? 'give up to ' . $hashtags : 'give none, use an empty list' ),
		);

		if ( ! $with_link ) {
			$lines[] = 'Links: do not write any web address.';
		} elseif ( Platforms::LINK_NONE === $link_mode ) {
			$lines[] = 'Links: a web address in the text is not clickable on this platform, so do not write one. End with this call to action instead: ' . self::call_to_action( $platform );
		} elseif ( Platforms::LINK_ATTACHED === $link_mode ) {
			$lines[] = 'Links: the article is attached to the post as its own link preview, so do not write the address in the text.';
		} else {
			$lines[] = 'Links: the plugin adds the article address after your text, and the limit above already allows for it. Do not write the address yourself.';
		}

		if ( Platforms::needs_image( $platform ) ) {
			$lines[] = 'This platform always carries the picture, so the text may refer to it.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * The call to action for a platform where a link cannot be clicked. The platform's own
	 * wording is used when the administrator set one, otherwise the global one.
	 */
	public static function call_to_action( string $platform ): string {
		$own = trim( (string) SocialSettings::platform( $platform, 'cta' ) );
		return '' !== $own ? $own : SocialSettings::call_to_action();
	}

	/**
	 * Reads the model's JSON reply.
	 *
	 * @return array<string, array{text:string, hashtags:string, source:string}>|null
	 */
	public static function parse( string $content, array $platforms ): ?array {
		$json = self::extract_json( $content );
		if ( ! is_array( $json ) ) {
			return null;
		}

		$out = array();
		foreach ( $platforms as $platform ) {
			$entry = $json[ $platform ] ?? null;
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$text = trim( (string) ( $entry['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}

			$tags = array();
			foreach ( (array) ( $entry['hashtags'] ?? array() ) as $tag ) {
				$tags[] = (string) $tag;
			}

			$out[ $platform ] = array(
				'text'     => self::tidy( $text ),
				'hashtags' => SocialSettings::generates_hashtags()
					? SocialSettings::clean_hashtags( implode( ' ', $tags ), $platform )
					: '',
				'source'   => 'ai',
			);
		}

		return $out ?: null;
	}

	/**
	 * Finds the JSON object in a reply, allowing for a model that wraps it in a code fence or
	 * adds a sentence around it despite being asked not to.
	 *
	 * @return array|null
	 */
	private static function extract_json( string $content ) {
		$content = trim( $content );

		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $content, $match ) ) {
			$decoded = json_decode( $match[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Removes the things the instruction asks the model not to do, in case it does them anyway.
	 * Only formatting is touched here. No word is ever changed, because that could change a fact.
	 */
	public static function tidy( string $text ): string {
		$text = wp_strip_all_tags( $text );
		// Any web address the model wrote despite being told not to. The plugin adds the real one.
		$text = (string) preg_replace( '#\bhttps?://\S+#i', '', $text );
		// Hashtags belong in their own list, so one left in the text is removed rather than doubled.
		$text = (string) preg_replace( '/(^|\s)#[0-9A-Za-z_]+/u', '$1', $text );
		if ( 'replace' === (string) Settings::get( 'emdash_policy' ) ) {
			$text = str_replace( array( "\xE2\x80\x94", "\xE2\x80\x93" ), '-', $text );
		}
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = (string) preg_replace( '/ +([.,!?;:])/', '$1', $text );
		return trim( $text );
	}

	/**
	 * The post text used when the AI is switched off, is blocked, or left a platform out.
	 *
	 * It is the article's own title and nothing else, because a fallback must not add anything
	 * the article does not say. It is not clickbait, it is accurate, and it is always within the
	 * platform's limit.
	 */
	public static function fallback( array $article, string $platform ): array {
		$title     = trim( wp_strip_all_tags( (string) ( $article['title'] ?? '' ) ) );
		$url       = (string) ( $article['url'] ?? '' );
		$with_link = SocialSettings::includes_url() && '' !== $url;
		$budget    = Platforms::text_budget( $platform, $with_link );

		$text = $title;

		if ( Platforms::LINK_NONE === (string) Platforms::value( $platform, 'link_mode' ) ) {
			$cta  = self::call_to_action( $platform );
			$room = $budget - ( mb_strlen( $cta ) + 1 );
			if ( $room > 20 ) {
				$text = self::clip( $title, $room ) . "\n" . $cta;
			}
		}

		return array(
			'text'     => self::clip( $text, $budget ),
			'hashtags' => SocialSettings::generates_hashtags()
				? SocialSettings::clean_hashtags( (string) SocialSettings::platform( $platform, 'hashtags' ), $platform )
				: '',
			'source'   => 'fallback',
		);
	}

	/**
	 * Shortens text to a limit without cutting a word in half, and without leaving a dangling
	 * fragment that could change the meaning of a sentence.
	 */
	public static function clip( string $text, int $limit ): string {
		$text = trim( $text );
		if ( $limit < 1 || mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > (int) ( $limit * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n,;:-" );
	}

	/**
	 * Room for the reply. Enough for every platform asked for, plus the JSON around them.
	 */
	private static function max_tokens( array $platforms ): int {
		$characters = 0;
		foreach ( $platforms as $platform ) {
			$characters += (int) Platforms::value( $platform, 'text_limit', 280 ) + 200;
		}
		// Roughly four characters to a token, with room to spare, and a floor and a ceiling.
		return max( 900, min( 8000, (int) ceil( $characters / 3 ) + 400 ) );
	}
}
