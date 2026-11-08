<?php
/**
 * Local stand-in for publishers and the OpenRouter API, reached over real HTTP.
 * Run: php -S 127.0.0.1:8091 mock-server.php
 *
 * Behavior is set through mock-state/state.json. Every OpenRouter request is recorded in
 * mock-state/requests.jsonl so tests can check what was really sent.
 */

$dir = __DIR__ . '/mock-state';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}
$state_file = $dir . '/state.json';
$state      = is_file( $state_file ) ? ( json_decode( (string) file_get_contents( $state_file ), true ) ?: array() ) : array();

function zzzc_save_state( array $state ): void {
	global $state_file;
	file_put_contents( $state_file, json_encode( $state ), LOCK_EX );
}

function zzzc_next_mode( string $key, string $fallback ): string {
	global $state;
	if ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) && $state[ $key ] ) {
		$mode = array_shift( $state[ $key ] );
		zzzc_save_state( $state );
		return (string) $mode;
	}
	return is_string( $state[ $key ] ?? null ) ? $state[ $key ] : $fallback;
}

function zzzc_json( int $code, array $data, array $headers = array() ): void {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	foreach ( $headers as $name => $value ) {
		header( "$name: $value" );
	}
	echo json_encode( $data );
	exit;
}

function zzzc_long_text( string $seed, int $paragraphs = 8 ): string {
	// Text differs by seed, so separate stories are not near-copies of each other.
	$vocabulary = array( 'harbour', 'council', 'budget', 'railway', 'clinic', 'teachers', 'farmers', 'rainfall', 'turbines', 'museum', 'tenants', 'freight', 'nurses', 'bridges', 'orchards', 'voters', 'pilots', 'miners', 'bakers', 'ferries', 'glaciers', 'courts', 'libraries', 'vaccines', 'exports', 'wages', 'droughts', 'startups', 'stadiums', 'reservoirs' );
	$verbs      = array( 'reviewed', 'delayed', 'funded', 'expanded', 'questioned', 'approved', 'measured', 'rebuilt', 'studied', 'announced' );
	mt_srand( crc32( $seed ) );
	$out = '<p>Officials confirmed the figures on Tuesday.</p>' . "\n";
	for ( $i = 1; $i <= $paragraphs; $i++ ) {
		$sentences = array();
		for ( $s = 0; $s < 8; $s++ ) {
			$words = array();
			for ( $w = 0; $w < 9; $w++ ) {
				$words[] = 0 === $w % 3 ? $verbs[ mt_rand( 0, 9 ) ] : $vocabulary[ mt_rand( 0, 29 ) ];
			}
			$sentences[] = ucfirst( implode( ' ', $words ) ) . '.';
		}
		$out .= '<p>' . implode( ' ', $sentences ) . '</p>' . "\n";
	}
	mt_srand();
	return $out;
}

function zzzc_article_html( string $title, string $variant = 'good' ): string {
	$para = static function ( string $seed, int $count = 1 ): string {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= "<p>This section explains $seed in plain words. The agency said the plan starts next month. Staff will get new guidance first. Readers should check the published schedule, and they can compare the dates in the table below. The change affects budgets, hiring and the order of the work ahead. Short steps come first. Longer projects follow once the first reviews end, and the agency will report results each quarter so that people can follow the progress over time.</p>\n";
		}
		return $out;
	};

	$body  = "<h1>$title</h1>\n";
	$body .= $para( 'the opening facts', 2 );
	$body .= "<h2>What changed this week</h2>\n" . $para( 'what changed', 2 );
	$body .= "<h2>Steps to take now</h2>\n<ul><li>Read the published schedule first.</li><li>Check which regions are named.</li><li>Note the next review date.</li></ul>\n" . $para( 'the steps', 2 );
	$body .= "<h3>Key dates at a glance</h3>\n<table><thead><tr><th>Stage</th><th>Date</th></tr></thead><tbody><tr><td>Guidance</td><td>Next month</td></tr><tr><td>Review</td><td>Next quarter</td></tr></tbody></table>\n";
	$body .= "<h4>What this means for readers</h4>\n" . $para( 'reader impact', 2 );
	$body .= "<h2>Trending FAQ</h2>\n<p><strong>When does it start?</strong> Next month, the agency said.</p><p><strong>Who is affected?</strong> Staff in the named regions.</p>\n";

	switch ( $variant ) {
		case 'with_image':
			return str_replace( '<h2>What changed', '<img src="https://example.com/photo.jpg" alt="x"><figure><img src="https://example.com/2.jpg"></figure><h2>What changed', $body );
		case 'preamble':
			return "Sure, here is the article you asked for:\n\n" . $body;
		case 'no_table':
			return (string) preg_replace( '#<h3>.*?</table>#s', '', $body );
		case 'short':
			return "<h1>$title</h1><p>Too short.</p><table><tr><th>a</th><th>b</th></tr><tr><td>1</td><td>2</td></tr></table><h2>Trending FAQ</h2><p>None.</p>";
		case 'closing':
			return $body . "<p>Let me know if you would like any changes to this article.</p>";
		case 'emdash':
			return str_replace( 'The agency said', 'The agency — in a statement — said', $body );
		case 'error_text':
			return "<h1>Error</h1><p>As an AI language model, I cannot complete this request.</p>" . $body;
	}
	return $body;
}

$uri    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];

/* ---------- OpenRouter ---------- */

if ( 0 === strpos( $uri, '/openrouter/api/v1/' ) ) {
	$path   = substr( $uri, strlen( '/openrouter/api/v1/' ) );
	$auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	$raw    = (string) file_get_contents( 'php://input' );
	$body   = json_decode( $raw, true ) ?: array();

	file_put_contents(
		$dir . '/requests.jsonl',
		json_encode(
			array(
				'path'   => $path,
				'method' => $method,
				'auth'   => $auth,
				'query'  => $_SERVER['QUERY_STRING'] ?? '',
				'body'   => $body,
			)
		) . "\n",
		FILE_APPEND | LOCK_EX
	);

	if ( 'key' === $path ) {
		$mode = zzzc_next_mode( 'key_mode', 'ok' );
		if ( 'invalid' === $mode ) {
			zzzc_json( 401, array( 'error' => array( 'code' => 401, 'message' => 'No auth credentials found' ) ) );
		}
		zzzc_json( 200, array( 'data' => array( 'label' => 'test-key', 'usage' => 0 ) ) );
	}

	if ( 'models' === $path ) {
		$models = $state['models'] ?? array( 'openai/gpt-6-luna', 'openai/gpt-6-sol' );
		zzzc_json( 200, array( 'data' => array_map( static fn( $id ) => array( 'id' => $id ), $models ) ) );
	}

	if ( 'generation' === $path ) {
		$mode = zzzc_next_mode( 'generation_mode', 'ok' );
		if ( 'missing' === $mode ) {
			zzzc_json( 404, array( 'error' => array( 'code' => 404, 'message' => 'Generation not found' ) ) );
		}
		zzzc_json( 200, array( 'data' => array( 'id' => $_GET['id'] ?? '', 'total_cost' => 0.00123, 'tokens_prompt' => 1000, 'tokens_completion' => 500 ) ) );
	}

	if ( 'chat/completions' === $path ) {
		$user_text = '';
		foreach ( (array) ( $body['messages'] ?? array() ) as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$user_text = (string) $message['content'];
			}
		}
		$is_tags = false !== strpos( $user_text, 'provide tags' ) || false !== strpos( $user_text, 'TAGPROMPT' );

		$mode = zzzc_next_mode( $is_tags ? 'tag_mode' : 'article_mode', 'ok' );

		$errors = array(
			'401' => array( 401, 'Invalid key' ),
			'402' => array( 402, 'Insufficient credits' ),
			'404' => array( 404, 'No endpoints found for this model' ),
			'429' => array( 429, 'Rate limited' ),
			'500' => array( 500, 'Upstream failure' ),
			'503' => array( 503, 'Provider down' ),
			'403' => array( 403, 'Flagged by moderation' ),
		);
		if ( isset( $errors[ $mode ] ) ) {
			zzzc_json( $errors[ $mode ][0], array( 'error' => array( 'code' => $errors[ $mode ][0], 'message' => $errors[ $mode ][1] ) ), '429' === $mode ? array( 'Retry-After' => '77' ) : array() );
		}
		if ( 'error_in_200' === $mode ) {
			zzzc_json( 200, array( 'error' => array( 'code' => 502, 'message' => 'Provider returned error' ) ) );
		}
		if ( 'malformed' === $mode ) {
			http_response_code( 200 );
			header( 'Content-Type: application/json' );
			echo '{not json';
			exit;
		}
		if ( 'hang' === $mode ) {
			sleep( 12 );
			zzzc_json( 200, array( 'choices' => array() ) );
		}
		if ( 'empty' === $mode ) {
			zzzc_json( 200, array( 'id' => 'gen-empty', 'choices' => array( array( 'message' => array( 'content' => '' ), 'finish_reason' => 'stop' ) ) ) );
		}

		$counter = (int) ( $state['gen_counter'] ?? 0 ) + 1;
		$state['gen_counter'] = $counter;
		zzzc_save_state( $state );

		$finish = 'stop';
		if ( $is_tags ) {
			$count = 20;
			if ( 'few' === $mode ) {
				$count = 7;
			} elseif ( 'many' === $mode ) {
				$count = 25;
			}
			$tags = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$tags[] = "testtag$counter-$i";
			}
			$content = implode( ', ', $tags );
			if ( 'dupes' === $mode ) {
				$content = implode( ', ', array_merge( array_slice( $tags, 0, 18 ), array( 'TESTTAG' . $counter . '-1', 'testtag' . $counter . '-2' ) ) );
			}
		} else {
			$headline = 'Untitled story';
			if ( preg_match( '/Source headline: (.+)/', $user_text, $match ) ) {
				$headline = trim( $match[1] );
			}
			$title = 'New report: ' . $headline;

			if ( 'markdown' === $mode ) {
				$content = "# $title\n\nFirst paragraph with facts.\n\n" . str_repeat( "This line adds source details to the piece. The agency said work starts soon. People can check the schedule online today.\n\n", 60 ) . "## Section one\n\n- One item here\n- Second item here\n\n### Dates\n\n| Stage | Date |\n|---|---|\n| Start | Soon |\n\n#### Readers\n\nA closing paragraph for readers ends here.\n\n## Trending FAQ\n\nWhen does it start? Soon, officials said.";
			} elseif ( 'truncated' === $mode ) {
				$content = zzzc_article_html( $title );
				$finish  = 'length';
			} else {
				$content = zzzc_article_html( $title, $mode );
			}
		}

		$usage = array(
			'prompt_tokens'     => 1200,
			'completion_tokens' => 800,
			'total_tokens'      => 2000,
			'cost'              => 0.00052,
		);
		if ( ! empty( $state['omit_cost'] ) ) {
			unset( $usage['cost'] );
		}

		zzzc_json(
			200,
			array(
				'id'      => 'gen-test-' . $counter,
				'model'   => $body['model'] ?? '',
				'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $content ), 'finish_reason' => $finish ) ),
				'usage'   => $usage,
			),
			array( 'X-Request-Id' => 'req-' . $counter )
		);
	}

	zzzc_json( 404, array( 'error' => array( 'code' => 404, 'message' => 'Unknown path' ) ) );
}

/* ---------- Social media platforms ---------- */

require __DIR__ . '/mock-social.php';

/* ---------- Publisher ---------- */

header( 'X-Mock: publisher' );

if ( '/robots.txt' === $uri ) {
	header( 'Content-Type: text/plain' );
	echo "User-agent: *\nDisallow: /private/\nAllow: /private/open\n\nUser-agent: BadBot\nDisallow: /\n";
	exit;
}

if ( '/' === $uri ) {
	header( 'Content-Type: text/html' );
	echo '<html><head><title>Mock News</title><link rel="alternate" type="application/rss+xml" href="/feed.xml"></head><body>Home</body></html>';
	exit;
}

if ( '/feed.xml' === $uri || 0 === strpos( $uri, '/feed-' ) ) {
	$feed_key = '/feed.xml' === $uri ? 'feed' : substr( $uri, 1 );
	$items    = $state['feeds'][ $feed_key ] ?? array();
	header( 'Content-Type: application/rss+xml' );
	echo '<?xml version="1.0"?><rss version="2.0"><channel><title>Mock</title>';
	foreach ( $items as $item ) {
		$date = isset( $item['pubDate'] ) ? '<pubDate>' . htmlspecialchars( $item['pubDate'] ) . '</pubDate>' : '';
		echo '<item><title>' . htmlspecialchars( $item['title'] ) . '</title><link>' . htmlspecialchars( $item['link'] ) . '</link><guid>' . htmlspecialchars( $item['guid'] ?? $item['link'] ) . '</guid>' . $date . '<description>' . htmlspecialchars( $item['description'] ?? 'Summary text.' ) . '</description></item>';
	}
	echo '</channel></rss>';
	exit;
}

require __DIR__ . '/mock-commons.php';

if ( preg_match( '#^/article/([a-z0-9\-]+)#', $uri, $match ) ) {
	$slug = $match[1];
	header( 'Content-Type: text/html; charset=utf-8' );
	$canonical = 'http://127.0.0.1:8091/article/' . $slug;
	// A test can set the date the page states, or leave it out with an empty value.
	$page_date = $state['page_dates'][ $slug ] ?? $state['page_date'] ?? gmdate( 'c' );
	$date_tag  = '' !== $page_date ? '<meta property="article:published_time" content="' . $page_date . '">' : '';
	echo '<!doctype html><html><head><title>' . htmlspecialchars( $slug ) . ' | Mock News</title><link rel="canonical" href="' . $canonical . '"><meta property="og:title" content="Headline for ' . htmlspecialchars( $slug ) . '"><meta name="author" content="Pat Reporter">' . $date_tag . '</head><body><nav><a href="/">Home</a> <a href="/x">Menu item</a></nav><div class="ad-slot">Buy things now</div><article><h1>Headline for ' . htmlspecialchars( $slug ) . '</h1>' . zzzc_long_text( $slug ) . '</article><aside class="related">Related stories list</aside><footer>Copyright</footer></body></html>';
	exit;
}

switch ( $uri ) {
	case '/partial':
		header( 'Content-Type: text/html' );
		echo '<html><body><article><h1>Short</h1><p>' . str_repeat( 'Only a short accessible part of the story here today. ', 12 ) . '</p></article></body></html>';
		exit;
	case '/empty':
		header( 'Content-Type: text/html' );
		echo '<html><body><div>Nothing here</div></body></html>';
		exit;
	case '/paywalled':
		header( 'Content-Type: text/html' );
		echo '<html><head><script type="application/ld+json">{"@type":"NewsArticle","isAccessibleForFree":false,"headline":"Locked"}</script></head><body><article><h1>Locked</h1><p>The first lines of the story appear here for everyone to read.</p><div class="paywall">Subscribe to continue reading.</div></article></body></html>';
		exit;
	case '/payment':
		http_response_code( 402 );
		echo 'Payment required';
		exit;
	case '/blocked':
		http_response_code( 403 );
		echo 'Forbidden';
		exit;
	case '/gone':
		http_response_code( 404 );
		echo 'Not found';
		exit;
	case '/limited':
		http_response_code( 429 );
		header( 'Retry-After: 120' );
		echo 'Slow down';
		exit;
	case '/broken':
		http_response_code( 500 );
		echo 'Server error';
		exit;
	case '/binary':
		header( 'Content-Type: application/pdf' );
		echo '%PDF-1.4';
		exit;
	case '/malformed':
		header( 'Content-Type: text/html' );
		echo '<<<html><body><p>unclosed <b>tags <div><span>';
		exit;
	case '/private/secret':
		header( 'Content-Type: text/html' );
		echo '<html><body><article>' . zzzc_long_text( 'private' ) . '</article></body></html>';
		exit;
	case '/to-private':
		header( 'Location: http://169.254.169.254/latest/meta-data/' );
		http_response_code( 302 );
		exit;
	case '/to-article':
		header( 'Location: /article/redirected-target' );
		http_response_code( 301 );
		exit;
	case '/not-a-feed':
		header( 'Content-Type: text/html' );
		echo '<html><body>Not a feed</body></html>';
		exit;
}

http_response_code( 404 );
echo 'Unknown mock path';
