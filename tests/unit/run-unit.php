<?php
/**
 * Unit tests for the social distribution logic that needs no database and no HTTP.
 *
 * Run: php tests/unit/run-unit.php
 *
 * These check the places where an error would be quiet: character budgets, the byte offsets
 * Bluesky needs for its links, LinkedIn's reserved-character escaping, hashtag cleaning, the
 * idempotency key, credential redaction and the token cipher. The behaviour that needs a real
 * WordPress site is covered by tests/test-11-social.php instead.
 */

require __DIR__ . '/stubs.php';

use ZZZCopy\Secrets;
use ZZZCopy\Social\Adapters\Bluesky;
use ZZZCopy\Social\Adapters\LinkedIn;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialContentGenerator;
use ZZZCopy\Social\SocialHttp;
use ZZZCopy\Social\SocialQueue;
use ZZZCopy\Social\SocialSettings;

/* ---- The platform registry ---- */

u_is( 6, count( Platforms::ORDER ), 'There are exactly six platforms' );
u_is( 6, count( Platforms::all() ), 'Every platform in the order has a definition' );

foreach ( Platforms::ORDER as $platform ) {
	$row = Platforms::get( $platform );
	u_true( is_array( $row ) && '' !== $row['label'], "$platform has a label" );
	u_true( (int) $row['text_limit'] > 0, "$platform has a text limit" );
	u_true( (int) $row['hard_text_limit'] >= (int) $row['text_limit'], "$platform hard limit is not below its working limit" );
	u_true( is_array( $row['requires'] ) && ! empty( $row['requires']['cost'] ), "$platform documents its cost" );
	u_true( ! empty( $row['sends'] ), "$platform documents what is sent to it" );
	u_true( is_array( $row['image_mime'] ) && $row['image_mime'], "$platform lists accepted image types" );
}

u_false( Platforms::exists( 'mastodon' ), 'A platform that is not supported is not claimed' );
u_true( Platforms::needs_image( Platforms::INSTAGRAM ), 'Instagram requires a picture' );
u_false( Platforms::needs_image( Platforms::X ), 'X does not require a picture' );
u_true( Platforms::needs_public_image_url( Platforms::INSTAGRAM ), 'Instagram fetches the picture by address' );
u_false( Platforms::needs_public_image_url( Platforms::BLUESKY ), 'Bluesky takes the picture as bytes' );

// X reserves room for the link, because it charges every link a fixed 23 characters.
u_is( 280, Platforms::text_budget( Platforms::X, false ), 'X has its full 280 with no link' );
u_is( 256, Platforms::text_budget( Platforms::X, true ), 'X reserves 24 characters for a link and a space' );
// Instagram makes no link clickable, so no room is reserved for one.
u_is( 2200, Platforms::text_budget( Platforms::INSTAGRAM, true ), 'Instagram reserves nothing for a link' );

/* ---- Shortening text ---- */

u_is( 'short', SocialContentGenerator::clip( 'short', 100 ), 'Text within the limit is untouched' );
u_is( '', SocialContentGenerator::clip( '', 100 ), 'Empty text stays empty' );

$long    = 'The council approved the plan on Tuesday after a long debate about funding and timing';
$clipped = SocialContentGenerator::clip( $long, 40 );
u_true( mb_strlen( $clipped ) <= 40, 'Clipped text is within the limit' );
u_false( str_contains( $clipped, '  ' ), 'Clipped text has no double space' );
u_true( str_starts_with( $long, $clipped ), 'Clipped text is a prefix of the original, so no word is invented' );
u_false( str_ends_with( $clipped, ',' ), 'Clipped text does not end on a comma' );

// A word is not cut in half when there is a sensible break.
u_is( 'The council approved the plan on', SocialContentGenerator::clip( $long, 34 ), 'Clipping breaks at a space' );

/* ---- Tidying what the model returned ---- */

$GLOBALS['stub_options']['zzzc_settings'] = array( 'emdash_policy' => 'replace' );

u_is(
	'The vote passed.',
	SocialContentGenerator::tidy( 'The vote passed. https://example.test/article' ),
	'A web address the model wrote is removed, because the plugin adds the real one'
);
u_is(
	'Funding was agreed.',
	SocialContentGenerator::tidy( 'Funding was agreed. #Budget #Council' ),
	'Hashtags left in the text are removed, because they belong in their own list'
);
u_is(
	'A - B',
	SocialContentGenerator::tidy( "A \xE2\x80\x94 B" ),
	'An em dash becomes a hyphen, following the plugin\'s existing setting'
);
u_is(
	'Plain text.',
	SocialContentGenerator::tidy( '<strong>Plain</strong> text.' ),
	'Markup is stripped'
);

/* ---- Hashtags ---- */

// Case is preserved exactly as given, never corrected. Changing it would alter a proper noun,
// and "#SouthAustralia" and "#southaustralia" are not the same tag to a reader.
u_is( '#budget #council', SocialSettings::clean_hashtags( 'budget council', Platforms::FACEBOOK ), 'Hashtags gain a hash and keep the case they were given' );
u_is( '#SouthAustralia', SocialSettings::clean_hashtags( 'SouthAustralia', Platforms::FACEBOOK ), 'Capitalisation in a hashtag is preserved' );
u_is( '#Budget', SocialSettings::clean_hashtags( '#Budget #budget', Platforms::FACEBOOK ), 'A repeated hashtag is only used once, keeping the first spelling' );
u_is( '', SocialSettings::clean_hashtags( '2026 123', Platforms::FACEBOOK ), 'A hashtag of digits alone is refused' );
u_is( '#One', SocialSettings::clean_hashtags( 'One Two Three Four', Platforms::THREADS ), 'Threads gets only one hashtag, because it uses only the first' );
u_is( '#One #Two #Three', SocialSettings::clean_hashtags( 'One Two Three Four Five', Platforms::X ), 'X gets at most three hashtags' );
u_is( '#SouthAustralia', SocialSettings::clean_hashtags( 'South-Australia!', Platforms::FACEBOOK ), 'Punctuation is removed from inside a hashtag' );
u_is( '', SocialSettings::clean_hashtags( 'anything', Platforms::BLUESKY . '-unknown' ), 'A platform with no hashtag allowance gets none' );

/* ---- LinkedIn's reserved characters ---- */

$linkedin = new LinkedIn();

u_is(
	'Plain text with no reserved characters',
	$linkedin->escape( 'Plain text with no reserved characters' ),
	'Text with nothing reserved is unchanged'
);
u_is(
	'The vote \\(42 to 11\\) passed',
	$linkedin->escape( 'The vote (42 to 11) passed' ),
	'Parentheses are escaped, which is what stops LinkedIn cutting the post short'
);
u_is(
	'A \\\\ B',
	$linkedin->escape( 'A \\ B' ),
	'A backslash is escaped once, not twice'
);
u_is(
	'Costs \\~5m and \\*rose\\* \\[sic\\]',
	$linkedin->escape( 'Costs ~5m and *rose* [sic]' ),
	'Every reserved character is escaped'
);

// Escaping must be applied once. Escaping an already escaped string would double the backslashes,
// which LinkedIn would print literally.
$once  = $linkedin->escape( 'Vote (42)' );
$twice = $linkedin->escape( $once );
u_false( $once === $twice, 'Escaping twice does change the text, which is why it is only ever done once' );

/* ---- Bluesky facets: the byte offsets of links and hashtags ---- */

$bluesky = new Bluesky();

$text   = 'Council approves plan https://example.test/news';
$facets = $bluesky->facets( $text );
u_is( 1, count( $facets ), 'One link is found' );
u_is( 'app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type'], 'It is marked up as a link' );
u_is( 'https://example.test/news', $facets[0]['features'][0]['uri'], 'The link address is right' );
u_is( 22, $facets[0]['index']['byteStart'], 'The link starts at the right byte' );
u_is( strlen( $text ), $facets[0]['index']['byteEnd'], 'The link ends at the right byte' );
u_is(
	'https://example.test/news',
	substr( $text, $facets[0]['index']['byteStart'], $facets[0]['index']['byteEnd'] - $facets[0]['index']['byteStart'] ),
	'The byte range cut from the text is exactly the link'
);

// The offsets are byte offsets, not character offsets. Text before the link that contains
// multi-byte characters is where a character-based offset would silently be wrong.
$accented = 'Council café plan https://example.test/news';
$facets   = $bluesky->facets( $accented );
u_is(
	'https://example.test/news',
	substr( $accented, $facets[0]['index']['byteStart'], $facets[0]['index']['byteEnd'] - $facets[0]['index']['byteStart'] ),
	'With a multi-byte character before it, the byte range is still exactly the link'
);
u_false(
	$facets[0]['index']['byteStart'] === mb_strpos( $accented, 'https' ),
	'The byte offset and the character offset differ here, which is the case a character offset would get wrong'
);

// Trailing punctuation belongs to the sentence, not the address.
$stop   = 'Read it at https://example.test/news.';
$facets = $bluesky->facets( $stop );
u_is( 'https://example.test/news', $facets[0]['features'][0]['uri'], 'A full stop after a link is not part of the link' );

// Hashtags.
$tagged = 'Budget agreed #Council #SouthAustralia';
$facets = $bluesky->facets( $tagged );
u_is( 2, count( $facets ), 'Both hashtags are found' );
u_is( 'app.bsky.richtext.facet#tag', $facets[0]['features'][0]['$type'], 'A hashtag is marked up as a tag' );
u_is( 'Council', $facets[0]['features'][0]['tag'], 'The tag is stored without its hash' );
u_is(
	'#Council',
	substr( $tagged, $facets[0]['index']['byteStart'], $facets[0]['index']['byteEnd'] - $facets[0]['index']['byteStart'] ),
	'The hashtag byte range includes the hash'
);

u_is( array(), $bluesky->facets( 'No links and no tags here' ), 'Plain text produces no facets' );

/* ---- The post address Bluesky posts get ---- */

u_is(
	'https://bsky.app/profile/news.bsky.social/post/3kabc123',
	$bluesky->post_url( 'at://did:plc:xyz/app.bsky.feed.post/3kabc123', 'news.bsky.social' ),
	'A readable address is built from the protocol address'
);
u_is( '', $bluesky->post_url( '', 'news.bsky.social' ), 'No address is invented without a record' );

/* ---- The idempotency key ---- */

$key = SocialQueue::key( 42, Platforms::X, 7 );
u_is( 40, strlen( $key ), 'The key is a 40 character digest' );
u_is( $key, SocialQueue::key( 42, Platforms::X, 7 ), 'The same article, platform and account always give the same key' );
u_false( $key === SocialQueue::key( 43, Platforms::X, 7 ), 'A different article gives a different key' );
u_false( $key === SocialQueue::key( 42, Platforms::FACEBOOK, 7 ), 'A different platform gives a different key' );
u_false( $key === SocialQueue::key( 42, Platforms::X, 8 ), 'A different account gives a different key' );

/* ---- Whether a failed request can be retried without risking a duplicate ---- */

$refused = array(
	'ok'    => false,
	'code'  => 0,
	'error' => 'network:cURL error 7: Failed to connect to api.x.com port 443',
);
u_true( SocialHttp::definitely_not_sent( $refused ), 'A refused connection never arrived, so it is safe to retry' );

$timeout = array(
	'ok'    => false,
	'code'  => 0,
	'error' => 'network:cURL error 28: Operation timed out after 30000 milliseconds',
);
u_false( SocialHttp::definitely_not_sent( $timeout ), 'A timeout may have arrived, so it is NOT safe to retry' );

$dns = array(
	'ok'    => false,
	'code'  => 0,
	'error' => 'network:cURL error 6: Could not resolve host: api.x.com',
);
u_true( SocialHttp::definitely_not_sent( $dns ), 'A name that would not resolve never arrived' );

$rejected = array(
	'ok'    => false,
	'code'  => 400,
	'error' => '',
);
u_false( SocialHttp::definitely_not_sent( $rejected ), 'A request the platform answered certainly arrived' );

$unsafe = array(
	'ok'    => false,
	'code'  => 0,
	'error' => 'unsafe_url:host_not_allowed',
);
u_true( SocialHttp::definitely_not_sent( $unsafe ), 'A request that was never made cannot have arrived' );

u_true( SocialHttp::is_temporary( array( 'code' => 503, 'error' => '' ) ), 'A 503 is temporary' );
u_true( SocialHttp::is_temporary( array( 'code' => 429, 'error' => '' ) ), 'A 429 is temporary' );
u_false( SocialHttp::is_temporary( array( 'code' => 400, 'error' => '' ) ), 'A 400 is not temporary' );

/* ---- Credentials never reaching a log ---- */

$GLOBALS['stub_options']['zzzc_secret'] = array();

u_is(
	'Failed with [REDACTED]',
	Secrets::redact( 'Failed with EAABwzLixnjYBO1234567890abcdefghij' ),
	'A Meta access token is redacted'
);
u_is(
	'Session [REDACTED] expired',
	Secrets::redact( 'Session eyJhbGciOiJIUzI1NiIs.eyJzdWIiOiIxMjM0NTY3.SflKxwRJSMeKKF2QT4f expired' ),
	'A JSON web token is redacted'
);
u_is(
	'{"access_token":"[REDACTED]"}',
	Secrets::redact( '{"access_token":"abc123def456ghi"}' ),
	'A field named like a token is redacted'
);
u_is(
	'Bearer [REDACTED]',
	Secrets::redact( 'Bearer abcdef1234567890' ),
	'A bearer credential is redacted'
);
u_is(
	'https://api.test/cb?code=[REDACTED]',
	Secrets::redact( 'https://api.test/cb?code=4%2F0AVHEtk7abc123' ),
	'An authorisation code in an address is redacted'
);
u_is(
	'{"app_password":"[REDACTED]"}',
	Secrets::redact( '{"app_password":"abcd-efgh-ijkl-mnop"}' ),
	'An app password is redacted'
);

// A token held in memory is redacted even when the platform echoes it back in prose.
Secrets::guard( 'sekrit-token-value-12345' );
u_is(
	'Platform said: [REDACTED] is wrong',
	Secrets::redact( 'Platform said: sekrit-token-value-12345 is wrong' ),
	'A token registered for this request is redacted wherever it appears'
);
u_is( 'short', Secrets::redact( 'short' ), 'Ordinary short text is left alone' );

/* ---- The token cipher ---- */

$plain  = 'EAABwzLixnjYBO-a-realistic-looking-page-token-0123456789';
$cipher = Secrets::encrypt_value( $plain );
u_false( $plain === $cipher, 'An encrypted token does not contain its plain value' );
u_false( str_contains( $cipher, 'realistic' ), 'No part of the plain token survives in the stored value' );
u_is( $plain, Secrets::decrypt_value( $cipher ), 'A token survives a round trip through the cipher' );
u_is( '', Secrets::encrypt_value( '' ), 'An empty token stays empty rather than encrypting to noise' );
u_is( '', Secrets::decrypt_value( '' ), 'An empty stored value reads back as empty' );
u_is( '', Secrets::decrypt_value( 'o1:not-really-encrypted' ), 'A value that cannot be decrypted reads back as empty, not as rubbish' );
u_false( Secrets::encrypt_value( $plain ) === Secrets::encrypt_value( $plain ), 'Encrypting the same token twice gives different stored values' );

/* ---- Parsing the model's reply ---- */

$reply = '{"x":{"text":"Council approves the plan","hashtags":["#Council","#Budget"]},"facebook":{"text":"The council has approved the plan.","hashtags":["#Council"]}}';
$posts = SocialContentGenerator::parse( $reply, array( Platforms::X, Platforms::FACEBOOK ) );
u_is( 2, count( $posts ), 'Both platforms are read from the reply' );
u_is( 'Council approves the plan', $posts[ Platforms::X ]['text'], 'The text is read' );
u_is( '#Council #Budget', $posts[ Platforms::X ]['hashtags'], 'The hashtags are read and cleaned' );
u_is( 'ai', $posts[ Platforms::X ]['source'], 'The text is recorded as coming from the AI' );

// A model that wraps its JSON in a code fence despite being asked not to.
$fenced = "Here you go:\n```json\n{\"x\":{\"text\":\"Short post\",\"hashtags\":[]}}\n```";
$posts  = SocialContentGenerator::parse( $fenced, array( Platforms::X ) );
u_true( is_array( $posts ) && 'Short post' === $posts[ Platforms::X ]['text'], 'JSON inside a code fence is still read' );

u_is( null, SocialContentGenerator::parse( 'I cannot help with that.', array( Platforms::X ) ), 'A reply with no JSON is refused rather than guessed at' );
u_is( null, SocialContentGenerator::parse( '{"x":{"text":""}}', array( Platforms::X ) ), 'An empty post is refused' );

// A platform the model left out is simply absent, so the caller can fall back for it.
$posts = SocialContentGenerator::parse( '{"x":{"text":"Only X","hashtags":[]}}', array( Platforms::X, Platforms::BLUESKY ) );
u_is( 1, count( $posts ), 'A platform the model left out is not invented' );
u_false( isset( $posts[ Platforms::BLUESKY ] ), 'The missing platform is absent rather than empty' );

/* ---- The fallback text, used when the AI is off or unavailable ---- */

$GLOBALS['stub_options']['zzzc_settings'] = array(
	'social_include_url'       => 1,
	'social_generate_hashtags' => 0,
	'social_cta'               => 'Full story: link in bio.',
	'emdash_policy'            => 'replace',
);

$article  = array(
	'title' => 'Council approves the long-delayed transport plan after a lengthy debate on funding',
	'url'   => 'https://example.test/news/transport-plan',
);
$fallback = SocialContentGenerator::fallback( $article, Platforms::X );
u_true( mb_strlen( $fallback['text'] ) <= Platforms::text_budget( Platforms::X, true ), 'The fallback fits X, with room left for the link' );
u_true( str_starts_with( $article['title'], $fallback['text'] ), 'The fallback is the article\'s own headline and adds nothing' );
u_is( 'fallback', $fallback['source'], 'The fallback is recorded as such, not as AI text' );

$instagram = SocialContentGenerator::fallback( $article, Platforms::INSTAGRAM );
u_true( str_contains( $instagram['text'], 'link in bio' ), 'The Instagram fallback carries the call to action instead of an address' );
u_false( str_contains( $instagram['text'], 'https://' ), 'The Instagram fallback contains no web address' );

exit( u_report( 'InkRush social distribution: unit tests' ) );
