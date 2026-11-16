<?php
/**
 * Tests for the Walter Writes humanizer's text rules (1.12.0, whole article since 1.12.2).
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
$why   = HumanizeText::fact_check( $both, HumanizeText::clean_answer( $batch ) );
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

/* ---- Cleaning Walter's answer and the repaired parts ---- */

u_is( "Park Opening\n\nMonday city leaders spoke.", HumanizeText::clean_answer( "Beginning of Text \nPark Opening\n\nMonday city leaders spoke." ), '"Beginning of Text" is removed and paragraphs are kept' );
u_is( 'Bold words here.', HumanizeText::clean_answer( '<p>**Bold** words here.</p>' ), 'HTML and Markdown emphasis are removed' );
u_is( 'The council met.', HumanizeText::clean_part( "– The council\n met." ), 'Seen 2026-11-16: a leading "– " is removed, lines joined' );
u_is( 'How the vote went', HumanizeText::clean_part( '## How the vote went' ), 'A heading mark is removed' );

/* ---- Which parts are sent: everything but block quotes, source lines and figures alone ---- */

$long  = str_repeat( 'The council met for several hours to discuss the budget and its effect on local schools. ', 3 );
$body  = "<h2>Budget</h2>\n<p>$long</p>\n<blockquote><p>$long</p></blockquote>\n<p>\"We are ready,\" the mayor said after the council met.</p>\n<p>See <a href=\"https://example.com\">the report</a> for details.</p>\n<p>Source: Example News</p>\n<ul><li>One item in the list</li></ul>\n<table><tr><td>Cell text</td><td>2027</td></tr></table>\n<p>Read <span class=\"x\">this</span> part.</p>\n<p>$long</p>";
$blocks = HumanizeText::blocks( $body );
u_is( $body, implode( '', array_column( $blocks, 'html' ) ), 'Joining the blocks gives the body back exactly' );
$why = array();
foreach ( $blocks as $block ) {
	if ( '' !== $block['tag'] ) {
		$why[] = $block['why'];
	}
}
u_is( array( '', '', 'inside a blockquote', '', '', 'a label or source line', '', '', 'no words, only figures', 'has markup other than links and bold or italic words', '' ), $why, 'Headings, quotes, links, list items and table cells are sent; block quotes, source lines, bare figures and other markup are not' );
u_is( array( array( 'html' => '<a href="https://example.com">the report</a>', 'text' => 'the report' ) ), $blocks[8]['inline'], 'A link is kept with its words' );

$units = HumanizeText::units( 'Council Approves the School Budget', $blocks );
u_is( array( 'title', 'heading', 'paragraph', 'paragraph', 'paragraph', 'item', 'cell', 'paragraph' ), array_column( $units, 'kind' ), 'Title, heading, paragraphs, the list item and the table cell, in order' );
u_is( array( 'the report' ), $units[4]['keep'], 'The link\'s words must survive' );

$items = HumanizeText::items( $units );
u_is( 1, count( $items ), 'A short article is one piece' );
$text = HumanizeText::item_text( $units, $items[0] );
u_true( 0 === strpos( $text, "# Council Approves the School Budget\n\n## Budget\n\n" ), 'The title and headings are sent as Markdown headings' );
u_true( false !== strpos( $text, "\n\n- One item in the list\n\n" ), 'A list item is sent as one' );

// A long article is cut at headings into pieces Walter accepts.
$para  = str_repeat( 'Officials said the plan was approved after a long meeting with residents. ', 8 ); // 96 words
$long_body = '';
for ( $s = 1; $s <= 6; $s++ ) {
	$long_body .= "<h2>Section number $s</h2><p>$para</p><p>$para</p>";
}
$u2 = HumanizeText::units( '', HumanizeText::blocks( $long_body ) );
$i2 = HumanizeText::items( $u2 );
u_true( count( $i2 ) >= 2, 'A 1,170-word article becomes several pieces' );
$ok = true;
foreach ( $i2 as $item ) {
	$ok = $ok && 'heading' === $u2[ $item[0] ]['kind'] && HumanizeText::item_words( $u2, $item ) <= HumanizeText::MAX_ITEM_WORDS;
}
u_true( $ok, 'Each piece starts at a heading and stays under the maximum' );
u_is( array(), HumanizeText::items( HumanizeText::units( '', HumanizeText::blocks( '<p>Too short to send at all.</p>' ) ) ), 'Under 55 words in all: nothing is sent' );

/* ---- The AI's fact repair ---- */

$messages = HumanizeText::repair_messages( array_slice( $units, 0, 2 ), 'Walter text' );
u_is( 2, count( $messages ), 'A system and a user message' );
u_true( false !== strpos( $messages[1]['content'], '"id": "t"' ) && false !== strpos( $messages[1]['content'], "rewritten:\nWalter text" ), 'The parts and Walter\'s text are both in the request' );
u_is( array( 't' => 'New title', 'b1' => 'Text' ), HumanizeText::parse_repair( "Sure:\n```json\n{\"parts\":[{\"id\":\"t\",\"text\":\"New title\"},{\"id\":\"b1\",\"text\":\"Text\"}]}\n```" ), 'The JSON is read even with words and fences around it' );
u_is( array(), HumanizeText::parse_repair( 'no json here' ), 'An unreadable answer gives nothing' );

/* ---- Checking each repaired part ---- */

$quote_unit = array( 'kind' => 'paragraph', 'text' => '"This is the biggest investment in public space this city has made in a generation," Mayor Jane Holloway said after the vote.', 'keep' => array() );
u_is( '', HumanizeText::check_part( $quote_unit, 'After the vote, Mayor Jane Holloway said: “This is the biggest investment in public space this city has made in a generation.”', $quote_unit['text'] ), 'A quote kept word for word passes, curly or straight' );
u_true( false !== strpos( HumanizeText::check_part( $quote_unit, '"This is the largest investment in public space this city has made in decades," Mayor Jane Holloway said after the vote.', $quote_unit['text'] ), 'quote' ), 'Seen 2026-11-16: a quote reworded by Walter is refused' );
$heading = array( 'kind' => 'heading', 'text' => 'Paying for the park', 'keep' => array() );
u_is( '', HumanizeText::check_part( $heading, 'How the park will be paid for', 'Paying for the park' ), 'A reworded heading passes' );
u_true( '' !== HumanizeText::check_part( $heading, 'Paying for the park. It costs a lot.', 'Paying for the park' ), 'A heading turned into sentences is refused' );
$title_unit = array( 'kind' => 'title', 'text' => 'Riverton Approves $12.4 Million Riverside Park', 'keep' => array() );
$story      = "Riverton Approves \$12.4 Million Riverside Park\n\nThe council in Riverton voted to build the park.";
u_is( '', HumanizeText::check_part( $title_unit, 'Riverton Backs $12.4 Million Riverside Park', $story ), 'A title-case word that is not a name may change' );
u_true( false !== strpos( HumanizeText::check_part( $title_unit, 'City Backs $12.4 Million Riverside Park', $story ), 'Riverton' ), 'A real name in a title must stay' );
$link_unit = array( 'kind' => 'paragraph', 'text' => 'Residents can see the full plans at the city\'s website or at City Hall.', 'keep' => array( 'the city\'s website' ) );
u_true( false !== strpos( HumanizeText::check_part( $link_unit, 'The full plans are on the city website and at City Hall for residents to see.', $link_unit['text'] ), 'link' ), 'A part that lost its link words is refused' );
u_is( '', HumanizeText::check_part( $link_unit, 'The full plans are on the city\'s website and at City Hall for residents to see.', $link_unit['text'] ), 'Kept link words pass' );

$cell = array( 'kind' => 'cell', 'text' => 'State grant awarded in 2025', 'keep' => array() );
u_is( '', HumanizeText::check_part( $cell, 'Grant from the state, awarded in 2025', 'State grant awarded in 2025' ), 'A reworded table cell with the same facts passes' );
u_true( false !== strpos( HumanizeText::check_part( $cell, 'State grant awarded in 2024', 'State grant awarded in 2025' ), 'number' ), 'A table cell with a changed year is refused' );
$table = HumanizeText::blocks( '<table><tr><th>Source</th><th>Amount</th></tr><tr><td>State grant</td><td>$8 million</td></tr></table>' );
u_is( array( 'cell', 'cell', 'cell', 'cell' ), array_column( array_filter( $table, static function ( $b ) { return $b['eligible']; } ), 'kind' ), 'Every table cell with words is sent, headers too' );
u_is( '<table><tr><th>Where from</th><th>Amount</th></tr><tr><td>State grant</td><td>$8 million</td></tr></table>', HumanizeText::rebuild( $table, array( 1 => 'Where from' ) ), 'A cell goes back in its own tag; the table keeps its shape' );

/* ---- Putting the article back together ---- */

u_is( 'The full plans are on <a href="https://example.com/plans">the city\'s website</a> &amp; at City Hall.', HumanizeText::render_inner( 'The full plans are on the city\'s website & at City Hall.', array( array( 'html' => '<a href="https://example.com/plans">the city\'s website</a>', 'text' => 'the city\'s website' ) ) ), 'The link goes back around the same words, the rest is escaped' );
u_is( null, HumanizeText::render_inner( 'No link words here.', array( array( 'html' => '<a href="x">gone</a>', 'text' => 'gone' ) ) ), 'Missing link words: not put back' );
$rebuilt = HumanizeText::rebuild( $blocks, array( 0 => 'The budget', 2 => 'New &amp; better.' ) );
u_true( 0 === strpos( $rebuilt, "<h2>The budget</h2>\n<p>New &amp; better.</p>\n<blockquote>" ), 'Accepted parts go back in their own tags' );
u_true( false !== strpos( $rebuilt, 'Source: Example News' ) && false !== strpos( $rebuilt, '<td>2027</td>' ), 'Everything else stays as it was' );

/* ---- Numbers and names ---- */

u_is( array( '12', '1500', '3.5', '2026' ), HumanizeText::numbers( '$12 million, 1,500 people, 3.5% and 2026' ), 'Figures are read without their separators' );
$wetland = 'City engineers estimated the wetland could hold about 2 million gallons of stormwater during heavy rain, which officials said would help Harbor Street businesses.';
u_is( '', HumanizeText::fact_check( $wetland, 'City engineers estimated the wetland could hold approximately two million gallons of stormwater in heavy rain, which officials said would help Harbor Street businesses.' ), 'Seen 2026-11-16: "2 million" as "two million", and "about" as "approximately", pass' );
u_true( false !== strpos( HumanizeText::fact_check( $wetland, str_replace( 'about 2', 'about three', $wetland ) ), 'number' ), '"2" as "three" is refused' );
u_true( false !== strpos( HumanizeText::fact_check( $wetland, str_replace( 'could hold', 'holds', $wetland ) ), 'certain' ), '"could hold" as "holds" is refused' );
u_true( in_array( 'Riverton', HumanizeText::names( $p2 ), true ), 'A place name mid-sentence is a name' );
u_false( in_array( 'The', HumanizeText::names( $p2 ), true ), 'A sentence\'s first word is not' );

/* ---- Reading Walter's answers ---- */

$sse = "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"{}\"}]},\"jsonrpc\":\"2.0\",\"id\":4}\n\n";
u_true( isset( WalterClient::parse_rpc( $sse )['result'] ), 'An event-stream answer is read' );
u_true( isset( WalterClient::parse_rpc( '{"jsonrpc":"2.0","id":1,"error":{"code":-1,"message":"x"}}' )['error'] ), 'A plain JSON answer is read' );
u_is( array(), WalterClient::parse_rpc( '<html>Cloudflare</html>' ), 'A page that is not an answer gives nothing' );

exit( u_report( 'InkRush Walter humanizer: text rules' ) );
