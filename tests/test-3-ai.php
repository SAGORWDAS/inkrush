<?php
use ZZZCopy\Automation;
use ZZZCopy\ContentParser;
use ZZZCopy\Db;
use ZZZCopy\Generation;
use ZZZCopy\Lifecycle;
use ZZZCopy\OpenRouter;
use ZZZCopy\Prompts;
use ZZZCopy\Settings;
use ZZZCopy\TagParser;
use ZZZCopy\Usage;
use ZZZCopy\Validator;

function good_body(): string {
	$p  = str_repeat( 'This sentence gives readers one clear fact about the plan. Short lines help. ', 12 );
	$out = "<p>$p</p><p>$p</p><h2>First part</h2><p>$p</p><p>$p</p><h2>Second part</h2><ul><li>One</li><li>Two</li></ul><p>$p</p>";
	$out .= '<h3>Dates</h3><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>';
	$out .= "<h4>Reader notes</h4><p>$p</p><p>$p</p><h2>Trending FAQ</h2><p>When? Soon, officials said.</p>";
	return $out;
}

function twenty_tags(): array {
	return array_map( static fn( $i ) => "tag$i", range( 1, 20 ) );
}

t(
	'M12',
	'Parser separates title and body from HTML',
	static function () {
		$parsed = ContentParser::parse( "<h1>A Big Headline</h1>\n<p>First para with café and “quotes”.</p><h2>Next</h2><p>More.</p>" );
		t_eq( 'A Big Headline', $parsed['title'] );
		t_not_contains( '<h1', $parsed['body'], 'title not repeated in body' );
		t_contains( 'café', $parsed['body'], 'UTF-8 survives' );
		t_contains( '“quotes”', $parsed['body'] );
		t_contains( '<h2>Next</h2>', $parsed['body'] );
		t_eq( '', $parsed['preamble'] );
		t_eq( 'html', $parsed['format'] );
	}
);

t(
	'M12',
	'Parser handles Markdown with table and lists, code fences, labels and preambles',
	static function () {
		$md = "# Title Here\n\nOpening **bold** para.\n\n## Part\n\n- one\n- two\n\n### Table\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n#### More\n\nText [link](https://example.com) end.\n\nTrending FAQ:\n\nQ and A.";
		$parsed = ContentParser::parse( $md );
		t_eq( 'Title Here', $parsed['title'] );
		t_eq( 'markdown', $parsed['format'] );
		t_contains( '<strong>bold</strong>', $parsed['body'] );
		t_contains( '<ul><li>one</li><li>two</li></ul>', $parsed['body'] );
		t_contains( '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>', $parsed['body'] );
		t_contains( '<h2>Trending FAQ</h2>', $parsed['body'], 'bare FAQ line becomes a heading' );

		$fenced = ContentParser::parse( "```html\n<h1>Fenced</h1><p>Body.</p>\n```" );
		t_eq( 'Fenced', $fenced['title'] );

		$labels = ContentParser::parse( "H1: Labeled Title\n\nPara.\n\nH2: Section name\n\nText.\n\nH3:\n\nMore." );
		t_eq( 'Labeled Title', $labels['title'] );
		t_contains( '<h2>Section name</h2>', $labels['body'] );
		t_not_contains( 'H3:', $labels['body'], 'bare label removed' );

		$html_labels = ContentParser::parse( '<h1>H1: Tagged</h1><h2>H2: Inner</h2><p>x</p><p>H3:</p>' );
		t_eq( 'Tagged', $html_labels['title'] );
		t_contains( '<h2>Inner</h2>', $html_labels['body'] );

		$pre = ContentParser::parse( '<p>Sure, here you go:</p><h1>T</h1><p>B</p>' );
		t_eq( 'Sure, here you go:', $pre['preamble'], 'preamble is captured so validation can fail it' );

		$no_h1 = ContentParser::parse( "A plain first line title\n\nThen the body paragraph follows here." );
		t_eq( 'A plain first line title', $no_h1['title'] );

		$extra = ContentParser::parse( '<h1>One</h1><p>x</p><h1>Two</h1><p>y</p>' );
		t_eq( 'One', $extra['title'] );
		t_contains( '<h2>Two</h2>', $extra['body'], 'second h1 demoted' );
	}
);

t(
	'AC-17,M25',
	'Parser removes every image form, and script tags are stripped too',
	static function () {
		$parsed = ContentParser::parse( '<h1>T</h1><p>Before<img src="https://x/y.jpg" onerror="z()">after</p><figure><img src="a"><figcaption>cap</figcaption></figure><picture><source srcset="b"></picture><svg></svg><iframe src="c"></iframe><script>evil()</script><p>End.</p>' );
		foreach ( array( '<img', '<figure', '<picture', '<source', '<svg', '<iframe', '<script', 'evil' ) as $bad ) {
			t_not_contains( $bad, $parsed['body'], $bad );
		}
		t_contains( 'Before', $parsed['body'] );

		$md = ContentParser::parse( "# T\n\n![alt](https://x/y.png)\n\nText ![inline](https://x/z.png) here." );
		t_not_contains( 'x/y.png', $md['body'] );
		t_not_contains( '<img', $md['body'] );
		t_not_contains( 'x/z.png', $md['body'] );
	}
);

t(
	'M13',
	'Tag parsing: comma split, trim, case-insensitive de-duplication, lead-ins and numbering',
	static function () {
		t_eq( array( 'a', 'b', 'c' ), TagParser::parse( 'a, b ,  c' ) );
		t_eq( array( 'Alpha', 'beta' ), TagParser::parse( 'Alpha, alpha, ALPHA, beta,, ,' ) );
		t_eq( array( 'one', 'two' ), TagParser::parse( 'Here are 20 tags: one, two' ) );
		t_eq( array( 'one', 'two' ), TagParser::parse( 'Tags: one, two.' ) );
		t_eq( array( 'x', 'y', 'z' ), TagParser::parse( "1. x\n2. y\n- z" ) );
		t_eq( array( 'quoted', 'hash' ), TagParser::parse( '"quoted", #hash' ) );
		t_eq( array( 'safe' ), TagParser::parse( '<b>safe</b>' ) );
		t_eq( 20, count( TagParser::parse( implode( ',', twenty_tags() ) ) ) );
		t_eq( array( 'Café tag', 'রাজনীতি' ), TagParser::parse( 'Café tag, রাজনীতি' ), 'non-Latin kept' );
	}
);

t(
	'Validation',
	'A complete article passes, and metrics stay in diagnostics',
	static function () {
		$result = Validator::validate( 'Good Title', good_body(), 'stop', '', twenty_tags() );
		t_true( $result['pass'], 'pass: ' . json_encode( $result['errors'] ) );
		t_eq( array(), $result['errors'] );
		t_true( $result['metrics']['word_count'] > 600 );
		t_true( $result['metrics']['faq'] );
		t_eq( 1, $result['metrics']['tables'] );
		t_eq( 1, $result['metrics']['lists'] );
		t_eq( 1, $result['metrics']['h3'] );
		t_eq( 1, $result['metrics']['h4'] );
		t_true( is_float( $result['metrics']['reading_ease'] ), 'reading ease measured' );
	}
);

t(
	'Validation',
	'Each defect is caught with its own error',
	static function () {
		$body = good_body();
		$cases = array(
			'missing_title'       => array( '', $body, 'stop', '' ),
			'empty_body'          => array( 'T', '', 'stop', '' ),
			'body_too_short'      => array( 'T', '<p>Too short.</p><table><tr><th>a</th><th>b</th></tr><tr><td>1</td><td>2</td></tr></table><h2>FAQ</h2><p>x.</p>', 'stop', '' ),
			'truncated'           => array( 'T', $body, 'length', '' ),
			'preamble'            => array( 'T', $body, 'stop', 'Sure, here is your article' ),
			'title_commentary'    => array( "Here's the article you requested", $body, 'stop', '' ),
			'missing_table'       => array( 'T', str_replace( array( '<table>', '</table>' ), array( '<div>', '</div>' ), $body ), 'stop', '' ),
			'malformed_table'     => array( 'T', str_replace( '<td>2</td>', '', $body ), 'stop', '' ),
			'missing_faq'         => array( 'T', str_replace( 'Trending FAQ', 'Other part', $body ), 'stop', '' ),
			'closing_commentary'  => array( 'T', $body . '<p>Let me know if you would like any changes.</p>', 'stop', '' ),
			'provider_error_text' => array( 'T', '<p>As an AI language model, I cannot do that.</p>' . $body, 'stop', '' ),
			'printed_metric'      => array( 'T', $body . '<p>Flesch Kincaid score: 88.</p>', 'stop', '' ),
			'empty_section'       => array( 'T', str_replace( '<h4>Reader notes</h4>', '<h4>Reader notes</h4><h4>Empty next</h4>', $body ), 'stop', '' ),
			'image_found'         => array( 'T', $body . '<img src="x">', 'stop', '' ),
		);
		foreach ( $cases as $code => $args ) {
			$result = Validator::validate( $args[0], $args[1], $args[2], $args[3], twenty_tags() );
			t_false( $result['pass'], $code . ' should fail' );
			t_true( in_array( $code, array_column( $result['errors'], 0 ), true ), "$code expected, got " . json_encode( array_column( $result['errors'], 0 ) ) );
		}

		$cut = str_replace( 'When? Soon, officials said.', 'When? Soon, officials said that the', $body );
		t_true( in_array( 'truncated', array_column( Validator::validate( 'T', $cut, 'stop', '', twenty_tags() )['errors'], 0 ), true ), 'mid-sentence ending' );

		$few = Validator::validate( 'T', $body, 'stop', '', array( 'a', 'b' ) );
		t_true( $few['tag_only_failure'], 'tag count alone' );
		t_false( $few['pass'] );

		// A news story may quote these phrases later on, and an AI company is not a refusal.
		$news = str_repeat( 'Plain opening words here for the reader today. ', 20 ) . 'The firm, as an AI company, said an internal server error hit users.';
		$ok   = Validator::validate( 'T', '<p>' . $news . '</p>' . $body, 'stop', '', twenty_tags() );
		t_false( in_array( 'provider_error_text', array_column( $ok['errors'], 0 ), true ), 'no false alarm' );

		Settings::update( array( 'emdash_policy' => 'hold' ) );
		t_true( in_array( 'em_dash', array_column( Validator::validate( 'T', $body . '<p>A — B.</p>', 'stop', '', twenty_tags() )['errors'], 0 ), true ), 'hold policy' );
		Settings::update( array( 'emdash_policy' => 'allow' ) );
		t_true( in_array( 'em_dash', array_column( Validator::validate( 'T', $body . '<p>A — B.</p>', 'stop', '', twenty_tags() )['warnings'], 0 ), true ), 'allow policy warns' );
	}
);

t(
	'M14',
	'Target misses are warnings: the word and readability goals do not block an article',
	static function () {
		$result = Validator::validate( 'Title', good_body(), 'stop', '', twenty_tags() );
		$codes  = array_column( $result['warnings'], 0 );
		t_true( in_array( 'word_target', $codes, true ), 'word target warning: ' . json_encode( $codes ) );
		t_true( $result['pass'] );
		$score = Validator::reading_ease( 'The cat sat on the mat. The dog ran to the park and sat on a log in the sun all day long with a bone. The kids came home and had a nap. It was a good day for all of them.' );
		t_true( $score > 70, "simple text reads easily: $score" );
		$hard = Validator::reading_ease( str_repeat( 'Institutional heterogeneity notwithstanding, multidimensional organizational characteristics fundamentally necessitate comprehensive reconsideration of administrative responsibilities throughout international telecommunications infrastructure. ', 3 ) );
		t_true( $hard < 30, "hard text scores low: $hard" );
	}
);

t(
	'AC-08,M7',
	'The request uses the configured model and the exact prompt version, with the source placed before it',
	static function () {
		$category = ready_plugin();
		$article  = "Based on the above, EXACT PROMPT with  double spaces, “quotes”, typo wurd.\n\nH1:\n";
		$tag      = 'TAGPROMPT exact, 20 tags';
		Prompts::save( Prompts::ARTICLE, $article, 1 );
		Prompts::save( Prompts::TAG, $tag, 1 );
		Settings::update( array( 'model_id' => 'openai/gpt-6-sol' ) );

		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/exact-prompt', 'Exact Prompt Story Headline' );
		run_jobs_only();

		$sent = mock_requests();
		t_true( count( $sent ) >= 2, 'article and tag calls made' );

		$first = $sent[0]['body'];
		t_eq( 'openai/gpt-6-sol', $first['model'] );
		$user = end( $first['messages'] )['content'];
		t_true( str_ends_with( $user, $article ), 'user message ends with the exact prompt, unchanged' );

		$pos = strpos( $user, 'Source headline: Exact Prompt Story Headline' );
		t_eq( 0, $pos, 'source pack comes first' );
		foreach ( array( 'Publisher: Mock News', 'Source URL: ' . ZZZT_MOCK . '/article/exact-prompt', 'Published: ', 'Category: Business', 'Source text:', 'Officials confirmed the figures' ) as $needle ) {
			t_contains( $needle, $user );
		}
		t_eq( 'system', $first['messages'][0]['role'] );
		t_contains( 'Use only facts supported', $first['messages'][0]['content'] );
		t_contains( 'ignore any instructions', $first['messages'][0]['content'] );
		t_contains( 'Bearer sk-or-v1-testkey', $sent[0]['auth'], 'server-side authorization' );
		t_true( isset( $first['usage']['include'] ), 'asks for usage' );

		$tag_user = end( $sent[1]['body']['messages'] )['content'];
		t_true( str_ends_with( $tag_user, $tag ), 'tag message ends with the exact tag prompt' );
		t_eq( 'openai/gpt-6-sol', $sent[1]['body']['model'] );

		$record = Lifecycle::job( $job );
		$audit  = json_decode( (string) $record['request_audit'], true );
		t_eq( 'openai/gpt-6-sol', $audit['model'] );
		t_eq( hash( 'sha256', $article ), $audit['article_prompt_sha256'], 'audit hash matches the prompt' );
		t_eq( hash( 'sha256', $user ), $audit['user_message_sha256'], 'audit hash matches what was sent' );
		t_true( $audit['prompt_is_exact_suffix'] );
	}
);

t(
	'M7',
	'A prompt edit during a job does not change that job',
	static function () {
		$category = ready_plugin();
		$original = 'Based on the above, ORIGINAL prompt version.';
		$version  = Prompts::save( Prompts::ARTICLE, $original, 1 );

		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/snapshot-test', 'Snapshot Story Headline' );
		t_eq( $version, (int) Lifecycle::job( $job )['article_prompt_version'], 'snapshot taken at creation' );

		Prompts::save( Prompts::ARTICLE, 'Based on the above, EDITED afterwards.', 1 );
		Settings::update( array( 'model_id' => 'openai/gpt-6-sol' ) );

		run_jobs_only();

		$user = end( mock_requests()[0]['body']['messages'] )['content'];
		t_true( str_ends_with( $user, $original ), 'the job used its saved version' );
		t_not_contains( 'EDITED', $user );
		t_eq( 'openai/gpt-6-luna', mock_requests()[0]['body']['model'], 'and its saved model' );
	}
);

t(
	'M12,M13,AC-09',
	'A full run through retrieval, AI, tags and validation reaches the queue',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/full-run', 'Full Run Story Headline' );
		run_jobs_only();

		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		$content = Lifecycle::content( $job );
		t_eq( 'New report: Full Run Story Headline', $content['title'] );
		t_not_contains( '<h1', $content['body'] );
		t_eq( 20, (int) $content['tag_count'] );
		t_eq( 20, count( json_decode( $content['tags_json'], true ) ) );
		$verdict = json_decode( $content['validation_result'], true );
		t_true( $verdict['pass'] );
		t_eq( 1, db_count( 'publishing_queue', "queue_status = 'QUEUED'" ) );
	}
);

t(
	'AI errors',
	'Invalid key pauses AI, shows a clear error, and makes no repeated calls',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/badkey', 'Bad Key Story' );
		mock_state( array( 'article_mode' => '401' ) );

		run_jobs_only();

		t_eq( '', '' );
		t_eq( Automation::ERROR, Automation::display_state() );
		t_eq( 'invalid_key', Automation::get( 'ai_block_code' ) );
		t_contains( 'rejected the API key', (string) Automation::get( 'ai_block_message' ) );
		t_eq( 1, count( mock_requests() ), 'one call only' );
		t_eq( Lifecycle::AI_PENDING, job_status( $job ), 'kept for later' );

		mock_state( array() );
		Automation::resume( 'all' );
		run_ticks();
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'continues after Resume with no duplicate job' );
	}
);

t(
	'AI errors',
	'No credits pauses AI, and publishing can still run',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/nocredit', 'No Credit Story' );
		mock_state( array( 'article_mode' => '402' ) );
		run_jobs_only();
		t_eq( 'insufficient_credits', Automation::get( 'ai_block_code' ) );
		t_contains( 'credits', (string) Automation::get( 'ai_block_message' ) );
		t_false( in_array( 'generate', ZZZCopy\Runner::allowed_types(), true ), 'AI jobs are excluded' );
		t_true( in_array( 'discover', ZZZCopy\Runner::allowed_types(), true ), 'research continues' );
	}
);

t(
	'AC-03,M9',
	'A model that disappears later is not swapped for another',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/gone-model', 'Gone Model Story' );
		mock_state( array( 'article_mode' => '404' ) );
		run_jobs_only();
		t_eq( 'model_unavailable', Automation::get( 'ai_block_code' ) );
		t_contains( 'not replaced', (string) Automation::get( 'ai_block_message' ) );
		$models = array_unique( array_map( static fn( $r ) => $r['body']['model'], mock_requests() ) );
		t_eq( array( 'openai/gpt-6-luna' ), array_values( $models ), 'only the configured model was tried' );
	}
);

t(
	'AI errors',
	'429 waits for Retry-After, and temporary errors retry and then succeed',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/ratelimit', 'Rate Limit Story' );
		mock_state( array( 'article_mode' => array( '429', '500', 'empty', 'ok' ) ) );

		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => time() ) );
		Settings::update( array( 'ai_max_retries' => 6 ) );
		ZZZCopy\Runner::tick( 'cli' );

		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table( 'jobs' ) . ' WHERE unique_key = %s', 'generate:' . $job ), ARRAY_A );
		$delay = strtotime( $row['run_at'] . ' UTC' ) - time();
		t_eq( 'pending', $row['status'] );
		t_true( $delay >= 60, "at least Retry-After or backoff, got $delay" );
		t_eq( Lifecycle::AI_PENDING, job_status( $job ) );

		run_ticks( 10 );
		t_eq( Lifecycle::QUEUED, job_status( $job ), 'recovered' );
		t_eq( 4, db_count( 'generation_usage', "purpose = 'article'" ), 'each attempt recorded' );
		t_eq( 3, db_count( 'generation_usage', "purpose = 'article' AND success = 0" ) );
	}
);

t(
	'AI errors',
	'Persistent provider failure stops at the limit and is marked failed',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/alwaysdown', 'Always Down Story' );
		mock_state( array( 'article_mode' => '503' ) );
		run_jobs_only( 8 );
		t_eq( Lifecycle::AI_FAILED, job_status( $job ) );
		t_eq( 3, count( mock_requests() ), 'exactly the configured attempts' );
		t_eq( 'provider_error', Lifecycle::job( $job )['error_code'] );
	}
);

t(
	'AI errors',
	'Network timeout and unreadable replies are retried, and errors inside a 200 are understood',
	static function () {
		$category = ready_plugin();
		// 10 seconds is the lowest timeout the setting allows. The mock hangs for 12.
		Settings::update( array( 'ai_timeout' => 10, 'ai_max_retries' => 4 ) );
		t_eq( 10, Settings::get( 'ai_timeout' ) );
		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/timeouts', 'Timeout Story' );
		mock_state( array( 'article_mode' => array( 'hang', 'malformed', 'error_in_200', 'ok' ) ) );
		run_jobs_only( 8 );
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		$codes = array_filter( array_column( $GLOBALS['wpdb']->get_results( 'SELECT error_code FROM ' . Db::table( 'generation_usage' ) . ' ORDER BY id', ARRAY_A ), 'error_code' ) );
		t_eq( array( 'network_error', 'malformed_response', 'provider_error' ), array_values( $codes ) );
	}
);

t(
	'AI errors',
	'Moderation refusal fails that article only and does not pause AI',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/flagged', 'Flagged Story' );
		mock_state( array( 'article_mode' => '403' ) );
		run_jobs_only();
		t_eq( Lifecycle::AI_FAILED, job_status( $job ) );
		t_eq( '', Automation::get( 'ai_block_code' ) );
		t_eq( 1, count( mock_requests() ), 'not retried' );
	}
);

t(
	'M12',
	'Malformed output retries with the same prompt, then is held and never queued',
	static function () {
		foreach ( array( 'preamble', 'no_table', 'short', 'closing', 'truncated', 'error_text' ) as $mode ) {
			zzzt_reset();
			$category = ready_plugin();
			$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/bad-' . $mode, 'Bad Output Story ' . $mode );
			mock_state( array( 'article_mode' => $mode ) );
			run_jobs_only( 15 );

			t_eq( Lifecycle::VALIDATION_FAILED, job_status( $job ), $mode );
			t_eq( 0, db_count( 'publishing_queue' ), $mode . ' never queued' );
			t_eq( 3, db_count( 'generation_usage', "purpose = 'article'" ), $mode . ' used the attempt limit' );

			$article_prompts = array_map(
				static fn( $r ) => end( $r['body']['messages'] )['content'],
				array_values( array_filter( mock_requests(), static fn( $r ) => 'system' === $r['body']['messages'][0]['role'] ) )
			);
			t_eq( 1, count( array_unique( $article_prompts ) ), $mode . ' same message each retry' );
		}
	}
);

t(
	'M12',
	'A bad first answer followed by a good one proceeds normally',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/second-try', 'Second Try Story' );
		mock_state( array( 'article_mode' => array( 'no_table', 'ok' ) ) );
		run_jobs_only( 15 );
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		t_eq( 2, (int) Lifecycle::job( $job )['ai_attempts'] );
	}
);

t(
	'M13',
	'Tag policy: fewer than 20 retries then holds, more than 20 keeps 20, and duplicates are removed',
	static function () {
		$category = ready_plugin();

		$few = make_job_from_url( $category, ZZZT_MOCK . '/article/few-tags', 'Few Tags Story' );
		mock_state( array( 'tag_mode' => 'few' ) );
		run_jobs_only( 10 );
		t_eq( Lifecycle::HELD, job_status( $few ), 'flagged' );
		t_contains( 'tag_count', Lifecycle::job( $few )['error_code'] );
		t_contains( '7 usable tags', Lifecycle::job( $few )['error_message'] );
		t_eq( 0, db_count( 'publishing_queue' ), 'not queued' );
		t_eq( 3, db_count( 'generation_usage', "purpose = 'tags'" ), 'retried to the limit' );

		$many = make_job_from_url( $category, ZZZT_MOCK . '/article/many-tags', 'Many Tags Story Different' );
		mock_state( array( 'tag_mode' => 'many' ) );
		run_jobs_only( 10 );
		t_eq( Lifecycle::QUEUED, job_status( $many ), 'trimmed' );
		t_eq( 20, (int) Lifecycle::content( $many )['tag_count'] );

		Settings::update( array( 'tag_policy' => 'strict' ) );
		$strict = make_job_from_url( $category, ZZZT_MOCK . '/article/strict-tags', 'Strict Tags Article Unique' );
		mock_state( array( 'tag_mode' => 'many' ) );
		run_jobs_only( 10 );
		t_eq( Lifecycle::HELD, job_status( $strict ), 'strict policy holds' );

		Settings::update( array( 'tag_policy' => 'trim_extra' ) );
		$dupes = make_job_from_url( $category, ZZZT_MOCK . '/article/dupe-tags', 'Duplicate Tag Words Elsewhere' );
		mock_state( array( 'tag_mode' => array( 'dupes', 'ok' ) ) );
		run_jobs_only( 10 );
		t_eq( Lifecycle::QUEUED, job_status( $dupes ), 'retried after duplicates left 18' );
	}
);

t(
	'M25',
	'A forbidden dash is replaced under the default policy and noted in diagnostics',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/dash-story', 'Dash Story Headline' );
		mock_state( array( 'article_mode' => 'emdash' ) );
		run_jobs_only();
		t_eq( Lifecycle::QUEUED, job_status( $job ) );
		$content = Lifecycle::content( $job );
		t_not_contains( '—', $content['body'] );
		t_true( json_decode( $content['validation_result'], true )['em_dash_replaced'], 'noted' );
	}
);

t(
	'AC-15,M23',
	'Provider-reported tokens and cost are recorded',
	static function () {
		$category = ready_plugin();
		$job      = make_job_from_url( $category, ZZZT_MOCK . '/article/usage-story', 'Usage Story Headline' );
		run_jobs_only();

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'generation_usage' ) . " WHERE purpose = 'article'", ARRAY_A );
		t_eq( 1200, (int) $row['input_tokens'] );
		t_eq( 800, (int) $row['output_tokens'] );
		t_eq( 2000, (int) $row['total_tokens'] );
		t_eq( '0.00052000', $row['cost_usd'] );
		t_eq( 'response', $row['cost_source'] );
		t_contains( 'gen-test-', $row['generation_id'] );
		t_contains( 'req-', $row['request_id'] );
		t_eq( 'openai/gpt-6-luna', $row['model'] );
		t_contains( '"cost":0.00052', (string) $row['raw_usage'], 'raw usage kept' );

		$totals = Usage::totals();
		t_eq( 2, (int) $totals['requests'], 'article and tags' );
		t_eq( 4000, (int) $totals['total_tokens'] );
		t_eq( '0.00104000', $totals['cost_usd'] );
	}
);

t(
	'AC-15,M23',
	'A missing cost is fetched from the generation endpoint, and stays Unavailable if it cannot be read',
	static function () {
		global $wpdb;
		$category = ready_plugin();
		mock_state( array( 'omit_cost' => true, 'feeds' => array() ) );

		$job = make_job_from_url( $category, ZZZT_MOCK . '/article/nocost-story', 'No Cost Story' );
		run_jobs_only();

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Db::table( 'generation_usage' ) . ' ORDER BY id', ARRAY_A );
		t_eq( null, $rows[0]['cost_usd'], 'not invented' );
		t_eq( 'unavailable', $rows[0]['cost_source'] );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'jobs' ) . " SET run_at = %s WHERE job_type = 'usage_fetch'", gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
		ZZZCopy\Runner::tick( 'cli' );

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Db::table( 'generation_usage' ) . ' ORDER BY id', ARRAY_A );
		t_eq( '0.00123000', $rows[0]['cost_usd'], 'from the provider' );
		t_eq( 'generation_api', $rows[0]['cost_source'] );

		// Provider cannot supply it.
		zzzt_reset();
		$category = ready_plugin();
		mock_state( array( 'omit_cost' => true, 'generation_mode' => 'missing' ) );
		make_job_from_url( $category, ZZZT_MOCK . '/article/unavailable-cost', 'Unavailable Cost Story' );
		run_jobs_only();
		for ( $i = 0; $i < 6; $i++ ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'jobs' ) . " SET run_at = %s WHERE job_type = 'usage_fetch' AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
			ZZZCopy\Runner::tick( 'cli' );
		}
		t_eq( 0, db_count( 'generation_usage', 'cost_usd IS NOT NULL' ), 'stays null' );
		$totals = Usage::totals();
		t_eq( null, $totals['cost_usd'], 'totals do not invent a number' );

		wp_set_current_user( $GLOBALS['zzzt_admin'] );
		ob_start();
		ZZZCopy\Admin\Pages::usage();
		$html = (string) ob_get_clean();
		t_contains( 'Unavailable', $html, 'the screen says so' );
	}
);

t(
	'AI limit',
	'The daily AI limit stops further generations',
	static function () {
		$category = ready_plugin();
		Settings::update( array( 'daily_ai_limit' => 1 ) );
		$first  = make_job_from_url( $category, ZZZT_MOCK . '/article/limit-one', 'Limit Story One Here' );
		$second = make_job_from_url( $category, ZZZT_MOCK . '/article/limit-two', 'Completely Other Words Second' );

		Automation::patch( array( 'run_state' => Automation::RUNNING, 'last_discovery_at' => time() ) );
		run_ticks( 8, false );

		t_eq( 1, db_count( 'generation_usage', "purpose = 'article'" ), 'stopped at the limit' );
		t_eq( Lifecycle::AI_PENDING, job_status( $second ), 'waits for the next day' );
	}
);

t(
	'Classification',
	'OpenRouter status codes map to the right recovery action',
	static function () {
		$map = array(
			401 => array( 'invalid_key', true, false ),
			402 => array( 'insufficient_credits', true, false ),
			404 => array( 'model_unavailable', true, false ),
			403 => array( 'permission_denied', false, false ),
			408 => array( 'provider_error', false, true ),
			429 => array( 'rate_limited', false, true ),
			500 => array( 'provider_error', false, true ),
			502 => array( 'provider_error', false, true ),
			503 => array( 'provider_error', false, true ),
			400 => array( 'request_rejected', false, false ),
		);
		foreach ( $map as $code => $expected ) {
			$c = OpenRouter::classify( $code, '' );
			t_eq( $expected[0], $c['code'], (string) $code );
			t_eq( $expected[1], $c['block'], "$code block" );
			t_eq( $expected[2], $c['transient'], "$code transient" );
		}
		t_eq( 'model_unavailable', OpenRouter::classify( 400, 'xyz is not a valid model ID' )['code'] );
	}
);
