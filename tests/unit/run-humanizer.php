<?php
/**
 * Tests for the Walter Writes humanizer's text rules (1.12.0).
 *
 * Run: php tests/unit/run-humanizer.php
 *
 * The "Walter answers" below are the real answers Walter's humanizer gave on 2026-11-16 to the
 * test paragraphs used while building this, through the web app and through its MCP server.
 * Each one changed a fact, so each must be refused. The paragraphs are stand-ins, not articles
 * from the owner's site.
 *
 * These need no database and no HTTP.
 */

require __DIR__ . '/stubs.php';

use ZZZCopy\Walter\HumanizeText;
use ZZZCopy\Walter\Humanizer;
use ZZZCopy\Walter\WalterClient;

$p1 = 'City officials announced on Monday that the new riverside park will open to the public next spring. The project, which cost about $12 million, includes walking trails, a playground and a small outdoor stage for community events.';
$p2 = 'The decision comes after years of requests from residents, who have long argued that the eastern side of Riverton lacks green space. Officials said construction is expected to begin in March and will be carried out in three phases.';
$both = $p1 . "\n\n" . $p2;

/* ---- Real Walter answers that changed facts are refused ---- */

$web = 'City leaders stated yesterday they plan to officially open the riverfront park in early Spring of this year. At an estimated cost of approximately twelve million dollars, the development includes walkways, a children\'s play area, and an outdoor performance area for future neighborhood events.';
u_true( '' !== HumanizeText::fact_check( $p1, $web ), 'Web app answer: "Monday" became "yesterday", "$12 million" became words, refused' );

$batch = 'Beginning with the announcement by city authorities on Monday; the parks along the river are scheduled to open this spring. The project cost approximately $12 million. A portion of this money was spent on creating walkways through the area; another part of it was used to create an area where children can play. And yet, another portion of that money was allocated toward building a small outdoor stage for use during community functions.';
$why   = HumanizeText::fact_check( $both, implode( "\n\n", HumanizeText::clean( $batch ) ) );
u_true( '' !== $why, 'Batch answer that dropped the whole Riverton paragraph is refused' );

$mayor_in  = 'Mayor Jane Holloway said local business owners expect the park to bring more visitors to nearby shops and cafes, and that the city will review parking around the site before it opens to the public next year.';
$mayor_out = '"Local merchants are expecting this new development will increase visitation to their businesses," said Mayor Jane Holloway, adding that the city plans to look at parking around the site before it opens to the public next year.';
u_true( false !== strpos( HumanizeText::fact_check( $mayor_in, $mayor_out ), 'quote' ), 'An invented quote is refused' );

u_true( false !== strpos( HumanizeText::fact_check( $p1, str_replace( 'next spring', 'this spring', $p1 ) ), 'time word' ), '"next spring" to "this spring" is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p1, str_replace( '$12 million', '$21 million', $p1 ) ), 'number' ), 'A changed figure is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p2, str_replace( 'lacks', 'does not lack', $p2 ) ), '"not"' ), 'An added "not" is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p2, str_replace( 'Officials said construction', 'Construction', $p2 ) ), 'said' ), 'Dropping "said" is refused: the claim must stay attributed' );
u_true( false !== strpos( HumanizeText::fact_check( $p1, str_replace( 'about $12', '$12', $p1 ) ), 'certain' ), 'Dropping "about" from a figure is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p2, str_replace( 'Riverton', 'the city', $p2 ) ), 'name' ), 'A dropped name is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p2, str_replace( 'the eastern side', 'the eastern side near Lake Brennan', $p2 ), $p2 ), 'name was added' ), 'An invented name is refused' );
u_true( '' !== HumanizeText::fact_check( $p1, 'Short.' ), 'An answer that lost most of the words is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $p1, str_replace( 'public next', 'public — next', $p1 ) ), 'dash' ), 'The forbidden dash is refused' );

/* ---- A faithful rewrite passes ---- */

$good = 'On Monday, city officials said the new riverside park will open to the public next spring. The project cost about $12 million and includes walking trails, a playground and a small outdoor stage that will host community events.';
u_is( '', HumanizeText::fact_check( $p1, $good ), 'A rewrite that keeps every fact passes' );
$good2 = 'Residents have spent years asking for it, saying the eastern side of Riverton lacks green space. Officials said construction is expected to begin in March, and the work will be carried out in three phases.';
u_is( '', HumanizeText::fact_check( $p2, $good2 ), 'Reordered sentences with the same facts pass' );
u_is( '', HumanizeText::fact_check( 'Sales rose to 1,500 units, or 3.5 percent more than the 2025 total, the company said in a statement on its quarterly results and outlook for buyers.', 'The company said in a statement that sales rose to 1500 units, 3.5 percent more than the 2025 total, as it shared quarterly results and its outlook for buyers.' ), '"1,500" and "1500" are the same number' );

/* ---- Cleaning Walter's answer ---- */

u_is( array( 'Park Opening', 'Monday city leaders spoke.' ), HumanizeText::clean( "Beginning of Text \nPark Opening\n\nMonday city leaders spoke." ), '"Beginning of Text" is removed and paragraphs are kept' );
u_is( array( 'Bold words here.' ), HumanizeText::clean( '<p>**Bold** words here.</p>' ), 'HTML and Markdown emphasis are removed' );
u_is( array(), HumanizeText::clean( "  \n\n " ), 'An empty answer gives no paragraphs' );

/* ---- Which paragraphs are sent ---- */

$long  = str_repeat( 'The council met for several hours to discuss the budget and its effect on local schools. ', 3 );
$body  = "<h2>Budget</h2>\n<p>$long</p>\n<p>$long</p>\n<blockquote><p>$long</p></blockquote>\n<p>\"We are ready,\" the mayor said after the council met for several hours to discuss the budget and schools.</p>\n<p>See <a href=\"https://example.com\">the report</a> for the council budget details and its effect on schools in the district.</p>\n<p>Source: Example News</p>\n<ul><li>One</li></ul>\n<p>$long</p>";
$blocks = HumanizeText::blocks( $body );
u_is( $body, implode( '', array_column( $blocks, 'html' ) ), 'Joining the blocks gives the body back exactly' );
$why = array();
foreach ( $blocks as $block ) {
	if ( '' !== $block['text'] ) {
		$why[] = $block['why'];
	}
}
u_is( array( '', '', 'inside a blockquote', 'has a quote', 'has links or formatting', 'a label or source line', '' ), $why, 'Quotes, links, block quotes and source lines are never sent' );

$groups = HumanizeText::groups( $blocks );
u_is( 1, count( $groups ), 'The two touching paragraphs (96 words) form one group' );
u_is( 2, count( $groups[0] ), 'It holds both of them' );
u_false( in_array( count( $blocks ) - 1, array_merge( ...$groups ), true ), 'The last paragraph (48 words) has no neighbour to join and is not sent' );

$short = HumanizeText::blocks( '<p>Twenty words or so are in this paragraph, which is too short to send to Walter on its own at all.</p><h2>X</h2><p>Another short paragraph sits here alone after a heading, with too few words to be sent.</p>' );
u_is( array(), HumanizeText::groups( $short ), 'Paragraphs that cannot reach 60 words with a neighbour are not sent' );

/* ---- Putting the answer back ---- */

$accepted = array( $groups[0][0] => array( $groups[0], array( 'First new paragraph.', 'Second new paragraph & more.' ) ) );
$rebuilt  = HumanizeText::rebuild( $blocks, $accepted );
u_true( false !== strpos( $rebuilt, "<h2>Budget</h2>\n<p>First new paragraph.</p>\n<p>Second new paragraph &amp; more.</p>" ), 'Accepted paragraphs replace the originals in place, escaped' );
u_true( false !== strpos( $rebuilt, '<blockquote>' ) && false !== strpos( $rebuilt, 'Source: Example News' ), 'Everything else stays as it was' );
u_is( 1, substr_count( $rebuilt, 'First new paragraph' ), 'The new text appears once' );

u_is( array( 'A one. A two.', 'B one. B two.' ), HumanizeText::fit_paragraphs( array( 'A one. A two. B one. B two.' ), 2 ), 'A merged answer is split back by sentences' );
u_is( array( 'Only one sentence here.' ), HumanizeText::fit_paragraphs( array( 'Only one sentence here.' ), 2 ), 'Too few sentences: one paragraph' );

/* ---- Numbers and names ---- */

u_is( array( '12', '1500', '3.5', '2026' ), HumanizeText::numbers( '$12 million, 1,500 people, 3.5% and 2026' ), 'Figures are read without their separators' );
u_true( in_array( 'Riverton', HumanizeText::names( $p2 ), true ), 'A place name mid-sentence is a name' );
u_false( in_array( 'The', HumanizeText::names( $p2 ), true ), 'A sentence\'s first word is not' );

/* ---- Sharing the plan's words over the month ---- */

u_is( 13, Humanizer::days_until_renewal( '2026-11-16', 29 ), '16 November to the 29th is 13 days' );
u_is( 30, Humanizer::days_until_renewal( '2026-11-29', 29 ), 'On the renewal day the next one is a month away' );
u_is( 27, Humanizer::days_until_renewal( '2027-02-01', 31 ), 'Day 31 in February is its last day, the 28th' );
u_is( 1377, Humanizer::allowance_for( 53711, '2026-11-16', 29, 3, 1500 ), 'The owner\'s 53,711 words over 39 posts: 1,377 each' );
u_is( 1500, Humanizer::allowance_for( 70000, '2026-11-29', 29, 1, 1500 ), 'Never more than the per-article maximum' );
u_is( 0, Humanizer::allowance_for( 0, '2026-11-16', 29, 3, 1500 ), 'No words left: nothing' );

/* ---- Reading Walter's answers ---- */

$sse = "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"{}\"}]},\"jsonrpc\":\"2.0\",\"id\":4}\n\n";
u_true( isset( WalterClient::parse_rpc( $sse )['result'] ), 'An event-stream answer is read' );
u_true( isset( WalterClient::parse_rpc( '{"jsonrpc":"2.0","id":1,"error":{"code":-1,"message":"x"}}' )['error'] ), 'A plain JSON answer is read' );
u_is( array(), WalterClient::parse_rpc( '<html>Cloudflare</html>' ), 'A page that is not an answer gives nothing' );

exit( u_report( 'InkRush Walter humanizer: text rules' ) );
