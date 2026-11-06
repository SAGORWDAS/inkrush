<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Article generation, tag generation and validation jobs.
 *
 * Each article job uses the prompt versions saved on it when it was created, so later prompt
 * edits do not change a job already under way.
 */
final class Generation {

	/**
	 * The source information placed before the exact saved article prompt, whose first words are "Based on the above".
	 */
	public static function source_pack( array $item, string $category_name ): string {
		$lines = array(
			'Source headline: ' . wp_strip_all_tags( (string) $item['headline'] ),
			'Publisher: ' . (string) $item['publisher'],
			'Source URL: ' . (string) ( $item['canonical_url'] ?: $item['resolved_url'] ?: $item['source_url'] ),
		);
		if ( ! empty( $item['published_at'] ) ) {
			$lines[] = 'Published: ' . $item['published_at'] . ' UTC';
		}
		if ( ! empty( $item['source_author'] ) ) {
			$lines[] = 'Author: ' . $item['source_author'];
		}
		$lines[] = 'Category: ' . $category_name;
		if ( ! empty( $item['search_context'] ) ) {
			$lines[] = 'Search context: ' . $item['search_context'];
		}
		$lines[] = '';
		$lines[] = 'Source text:';
		$lines[] = (string) $item['source_text'];
		return implode( "\n", $lines );
	}

	/**
	 * Builds the messages. The saved prompt text is included unchanged.
	 */
	public static function article_messages( string $system, string $source_pack, string $article_prompt ): array {
		$messages = array();
		if ( '' !== trim( $system ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $source_pack . "\n\n" . $article_prompt,
		);
		return $messages;
	}

	public static function tag_messages( string $title, string $body, string $tag_prompt ): array {
		return array(
			array(
				'role'    => 'user',
				'content' => $title . "\n\n" . wp_strip_all_tags( $body ) . "\n\n" . $tag_prompt,
			),
		);
	}

	/**
	 * Applies the forbidden-character policy from the owner's prompt.
	 *
	 * @return array{0:string,1:bool} Text, and whether it was changed.
	 */
	public static function apply_emdash_policy( string $text ): array {
		if ( 'replace' !== Settings::get( 'emdash_policy' ) || false === mb_strpos( $text, '—' ) ) {
			return array( $text, false );
		}
		$fixed = (string) preg_replace( '/\s*—\s*/u', ', ', $text );
		return array( $fixed, true );
	}

	public static function handle_generate( array $job ): JobResult {
		global $wpdb;
		$article_job_id = (int) $job['payload']['article_job_id'];
		$article        = Lifecycle::job( $article_job_id );

		// AI_RUNNING here means an earlier worker stopped mid-call and this job was recovered.
		if ( ! $article || ! in_array( $article['status'], array( Lifecycle::AI_PENDING, Lifecycle::AI_RUNNING ), true ) || PastHour::stop_if_unwanted( $article ) ) {
			return JobResult::done();
		}

		if ( Usage::ai_articles_today() >= (int) Settings::get( 'daily_ai_limit' ) ) {
			return JobResult::defer( 1800, 'The daily AI article limit has been reached.' );
		}

		$item = Lifecycle::item( (int) $article['source_item_id'] );
		if ( ! $item || '' === trim( (string) $item['source_text'] ) ) {
			// No source text means no article. Nothing is written from the headline alone.
			Lifecycle::transition( $article_job_id, Lifecycle::AI_FAILED, array( 'error_code' => 'source_missing', 'error_message' => 'The source text is no longer stored.' ) );
			return JobResult::done();
		}

		$article_prompt = Prompts::version( (int) $article['article_prompt_version'] );
		$system_prompt  = Prompts::version( (int) $article['system_prompt_version'] );
		if ( ! $article_prompt ) {
			return JobResult::fail( 'prompt_missing', 'The prompt version saved on this job no longer exists.' );
		}

		if ( Lifecycle::AI_PENDING === $article['status'] ) {
			Lifecycle::transition( $article_job_id, Lifecycle::AI_RUNNING );
		}

		$category = Categories::get( (int) $article['category_id'] );
		$pack     = self::source_pack( $item, $category ? (string) $category['name'] : '' );
		$messages = self::article_messages( $system_prompt ? (string) $system_prompt['content'] : '', $pack, (string) $article_prompt['content'] );

		$wpdb->update(
			Db::table( 'article_jobs' ),
			array(
				'request_audit' => wp_json_encode(
					array(
						'model'                  => $article['model_id'],
						'article_prompt_version' => (int) $article['article_prompt_version'],
						'article_prompt_sha256'  => hash( 'sha256', (string) $article_prompt['content'] ),
						'system_prompt_version'  => (int) $article['system_prompt_version'],
						'user_message_sha256'    => hash( 'sha256', $messages[ count( $messages ) - 1 ]['content'] ),
						'prompt_is_exact_suffix' => str_ends_with( $messages[ count( $messages ) - 1 ]['content'], (string) $article_prompt['content'] ),
						'sent_at'                => Clock::mysql(),
					)
				),
				'ai_attempts'   => (int) $article['ai_attempts'] + 1,
			),
			array( 'id' => $article_job_id )
		);

		$result = OpenRouter::chat( $messages, (string) $article['model_id'], (int) Settings::get( 'max_output_tokens' ) );
		Usage::record( 'article', $article_job_id, $result );

		if ( ! $result['ok'] ) {
			return self::handle_ai_error( $job, $article_job_id, $result, Lifecycle::AI_RUNNING );
		}

		$parsed = ContentParser::parse( $result['content'] );
		list( $title, $title_changed ) = self::apply_emdash_policy( $parsed['title'] );
		list( $body, $body_changed )   = self::apply_emdash_policy( $parsed['body'] );

		$wpdb->query(
			$wpdb->prepare(
				'REPLACE INTO ' . Db::table( 'article_content' ) . ' (article_job_id, title, body, finish_reason, tags_json, tag_count, validation_result, word_count, generated_at) VALUES (%d, %s, %s, %s, %s, 0, %s, %d, %s)', // phpcs:ignore WordPress.DB
				$article_job_id,
				$title,
				$body,
				$result['finish_reason'],
				'[]',
				wp_json_encode(
					array(
						'preamble'         => $parsed['preamble'],
						'format'           => $parsed['format'],
						'em_dash_replaced' => $title_changed || $body_changed,
					)
				),
				Extractor::count_words( wp_strip_all_tags( $body ) ),
				Clock::mysql()
			)
		);

		$wpdb->update( Db::table( 'article_jobs' ), array( 'raw_response' => mb_substr( $result['raw'], 0, 60000 ) ), array( 'id' => $article_job_id ) );

		Lifecycle::transition(
			$article_job_id,
			Lifecycle::GENERATED,
			array(
				'error_code'    => '',
				'error_message' => '',
			)
		);

		Events::log(
			'ai',
			'info',
			sprintf( 'Article generated with %s. Generation id %s.', $result['model'], $result['generation_id'] ?: 'not returned' ),
			array(
				'status'         => Lifecycle::GENERATED,
				'article_job_id' => $article_job_id,
				'category_id'    => (int) $article['category_id'],
				'source_item_id' => (int) $article['source_item_id'],
				'attempt'        => (int) $article['ai_attempts'] + 1,
			)
		);

		Jobs::enqueue( Jobs::TAGS, array( 'article_job_id' => $article_job_id ), 'tags:' . $article_job_id, 0, (int) Settings::get( 'ai_max_retries' ) );

		return JobResult::done();
	}

	public static function handle_tags( array $job ): JobResult {
		global $wpdb;
		$article_job_id = (int) $job['payload']['article_job_id'];
		$article        = Lifecycle::job( $article_job_id );
		if ( ! $article || Lifecycle::GENERATED !== $article['status'] || PastHour::stop_if_unwanted( $article ) ) {
			return JobResult::done();
		}

		$content    = Lifecycle::content( $article_job_id );
		$tag_prompt = Prompts::version( (int) $article['tag_prompt_version'] );
		if ( ! $content || ! $tag_prompt ) {
			return JobResult::fail( 'prompt_missing', 'The article content or tag prompt version is missing.' );
		}

		$messages = self::tag_messages( (string) $content['title'], (string) $content['body'], (string) $tag_prompt['content'] );
		$result   = OpenRouter::chat( $messages, (string) $article['model_id'], 512 );
		Usage::record( 'tags', $article_job_id, $result );

		if ( ! $result['ok'] ) {
			return self::handle_ai_error( $job, $article_job_id, $result, Lifecycle::GENERATED );
		}

		$tags    = TagParser::parse( $result['content'] );
		$count   = count( $tags );
		$trimmed = false;

		if ( $count > TagParser::REQUIRED && 'trim_extra' === Settings::get( 'tag_policy' ) ) {
			$tags    = array_slice( $tags, 0, TagParser::REQUIRED );
			$trimmed = true;
		}

		$wpdb->update(
			Db::table( 'article_content' ),
			array(
				'tags_json' => wp_json_encode( $tags, JSON_UNESCAPED_UNICODE ),
				'tag_count' => count( $tags ),
			),
			array( 'article_job_id' => $article_job_id )
		);

		if ( TagParser::REQUIRED !== count( $tags ) && (int) $job['attempts'] < (int) $job['max_attempts'] ) {
			return JobResult::retry( 30, 'tag_count', sprintf( 'The model returned %1$d usable tags. %2$d are required.', count( $tags ), TagParser::REQUIRED ) );
		}

		Events::log(
			'ai',
			TagParser::REQUIRED === count( $tags ) ? 'info' : 'warning',
			$trimmed ? sprintf( 'Tags generated: %1$d returned, the first %2$d kept.', $count, TagParser::REQUIRED ) : sprintf( 'Tags generated: %d usable.', count( $tags ) ),
			array(
				'article_job_id' => $article_job_id,
				'category_id'    => (int) $article['category_id'],
				'status'         => Lifecycle::GENERATED,
			)
		);

		Jobs::enqueue( Jobs::VALIDATE, array( 'article_job_id' => $article_job_id ), 'validate:' . $article_job_id, 0, 3 );

		return JobResult::done();
	}

	public static function handle_validate( array $job ): JobResult {
		global $wpdb;
		$article_job_id = (int) $job['payload']['article_job_id'];
		$article        = Lifecycle::job( $article_job_id );
		if ( ! $article || ! in_array( $article['status'], array( Lifecycle::GENERATED, Lifecycle::VALIDATING ), true ) || PastHour::stop_if_unwanted( $article ) ) {
			return JobResult::done();
		}

		if ( Lifecycle::GENERATED === $article['status'] ) {
			Lifecycle::transition( $article_job_id, Lifecycle::VALIDATING );
		}

		$content = Lifecycle::content( $article_job_id );
		if ( ! $content ) {
			Lifecycle::transition( $article_job_id, Lifecycle::HELD, array( 'error_code' => 'content_missing', 'error_message' => 'The generated content is missing.' ) );
			return JobResult::done();
		}

		$meta     = json_decode( (string) $content['validation_result'], true ) ?: array();
		$tags     = json_decode( (string) $content['tags_json'], true ) ?: array();
		$verdict  = Validator::validate( (string) $content['title'], (string) $content['body'], (string) $content['finish_reason'], (string) ( $meta['preamble'] ?? '' ), $tags );
		$verdict  = array_merge( $meta, $verdict );
		$reading  = $verdict['metrics']['reading_ease'];

		$update = array(
			'validation_result' => wp_json_encode( $verdict ),
			'word_count'        => (int) $verdict['metrics']['word_count'],
			'validated_at'      => Clock::mysql(),
		);
		if ( null !== $reading ) {
			$update['readability'] = $reading;
		}
		$wpdb->update( Db::table( 'article_content' ), $update, array( 'article_job_id' => $article_job_id ) );

		$context = array(
			'article_job_id' => $article_job_id,
			'category_id'    => (int) $article['category_id'],
			'source_item_id' => (int) $article['source_item_id'],
		);

		if ( ! $verdict['pass'] ) {
			$messages = implode( ' ', array_column( $verdict['errors'], 1 ) );
			$codes    = implode( ',', array_column( $verdict['errors'], 0 ) );
			$fields   = array(
				'error_code'    => substr( $codes, 0, 60 ),
				'error_message' => $messages,
			);

			if ( $verdict['tag_only_failure'] ) {
				Lifecycle::transition( $article_job_id, Lifecycle::HELD, $fields );
				Events::log( 'validation', 'warning', 'Held: ' . $messages, array_merge( $context, array( 'status' => Lifecycle::HELD, 'error_code' => $codes ) ) );
				return JobResult::done();
			}

			// The same exact prompt is sent again. Nothing about it is changed for the retry.
			if ( (int) $article['ai_attempts'] < (int) Settings::get( 'ai_max_retries' ) ) {
				Lifecycle::transition( $article_job_id, Lifecycle::AI_PENDING, $fields );
				Events::log( 'validation', 'warning', 'Validation failed, generating again with the same prompt: ' . $messages, array_merge( $context, array( 'status' => Lifecycle::AI_PENDING, 'error_code' => $codes, 'attempt' => (int) $article['ai_attempts'] ) ) );
				Jobs::enqueue( Jobs::GENERATE, array( 'article_job_id' => $article_job_id ), 'generate:' . $article_job_id, 60, (int) Settings::get( 'ai_max_retries' ) );
				return JobResult::done();
			}

			Lifecycle::transition( $article_job_id, Lifecycle::VALIDATION_FAILED, $fields );
			Events::log( 'validation', 'error', 'Held for review, retries used up: ' . $messages, array_merge( $context, array( 'status' => Lifecycle::VALIDATION_FAILED, 'error_code' => $codes ) ) );
			return JobResult::done();
		}

		Lifecycle::transition( $article_job_id, Lifecycle::VALIDATED, array( 'error_code' => '', 'error_message' => '' ) );
		Events::log( 'validation', 'info', 'Validation passed.', array_merge( $context, array( 'status' => Lifecycle::VALIDATED ) ) );

		$item      = Lifecycle::item( (int) $article['source_item_id'] );
		$duplicate = Dedupe::check_before_publish( $article, $item, $content, true );
		if ( $duplicate ) {
			Lifecycle::transition( $article_job_id, Lifecycle::DUPLICATE, array( 'error_code' => 'duplicate', 'error_message' => $duplicate['reason'] ) );
			Dedupe::record_item_duplicate( $item, $duplicate );
			return JobResult::done();
		}

		Publisher::enqueue( $article );

		return JobResult::done();
	}

	/**
	 * Applies the recovery rules for a failed OpenRouter call.
	 */
	private static function handle_ai_error( array $job, int $article_job_id, array $result, string $current_status ): JobResult {
		global $wpdb;

		$wpdb->update(
			Db::table( 'article_jobs' ),
			array(
				'error_code'    => $result['code'],
				'error_message' => $result['message'],
				'raw_response'  => mb_substr( (string) $result['raw'], 0, 20000 ),
			),
			array( 'id' => $article_job_id )
		);

		$back_to_pending = Lifecycle::AI_RUNNING === $current_status;

		if ( $result['block'] ) {
			if ( $back_to_pending ) {
				Lifecycle::transition( $article_job_id, Lifecycle::AI_PENDING );
			}
			Automation::block_ai( $result['code'], $result['message'] );
			return JobResult::defer( 300, $result['message'] );
		}

		if ( $result['transient'] ) {
			if ( $back_to_pending ) {
				Lifecycle::transition( $article_job_id, Lifecycle::AI_PENDING );
			}
			$delay = max( $result['retry_after'], Jobs::backoff( (int) $job['attempts'] ) );
			return JobResult::retry( $delay, $result['code'], $result['message'] );
		}

		return JobResult::fail( $result['code'], $result['message'] );
	}

	/**
	 * Generates from pasted sample text for the "Test generation" button. No queue records are changed.
	 *
	 * @return array{ok:bool, message:string, title:string, body:string, tags:array, verdict:array, usage:array, model:string, generation_id:string}
	 */
	public static function test_generation( string $headline, string $publisher, string $url, string $source_text ): array {
		$item = array(
			'headline'       => $headline,
			'publisher'      => $publisher,
			'source_url'     => $url,
			'canonical_url'  => $url,
			'resolved_url'   => $url,
			'source_text'    => $source_text,
			'search_context' => 'Test generation',
		);
		$pack   = self::source_pack( $item, 'Test' );
		$model  = (string) Settings::get( 'model_id' );
		$result = OpenRouter::chat(
			self::article_messages( Prompts::active_text( Prompts::SYSTEM ), $pack, Prompts::active_text( Prompts::ARTICLE ) ),
			$model,
			(int) Settings::get( 'max_output_tokens' )
		);
		Usage::record( 'test', 0, $result );

		$out = array(
			'ok'            => false,
			'message'       => '',
			'title'         => '',
			'body'          => '',
			'tags'          => array(),
			'verdict'       => array(),
			'usage'         => $result['usage'],
			'model'         => $result['model'],
			'generation_id' => $result['generation_id'],
		);

		if ( ! $result['ok'] ) {
			$out['message'] = $result['message'];
			return $out;
		}

		$parsed       = ContentParser::parse( $result['content'] );
		$out['title'] = self::apply_emdash_policy( $parsed['title'] )[0];
		$out['body']  = self::apply_emdash_policy( $parsed['body'] )[0];

		$tag_result = OpenRouter::chat( self::tag_messages( $out['title'], $out['body'], Prompts::active_text( Prompts::TAG ) ), $model, 512 );
		Usage::record( 'test', 0, $tag_result );
		if ( $tag_result['ok'] ) {
			$out['tags'] = array_slice( TagParser::parse( $tag_result['content'] ), 0, 'trim_extra' === Settings::get( 'tag_policy' ) ? TagParser::REQUIRED : 200 );
		}

		$out['verdict'] = Validator::validate( $out['title'], $out['body'], $result['finish_reason'], $parsed['preamble'], $out['tags'] );
		$out['ok']      = true;
		$out['message'] = $out['verdict']['pass'] ? 'Generated and passed validation.' : 'Generated, but validation found problems.';
		return $out;
	}
}
