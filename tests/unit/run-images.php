<?php
/**
 * Image subject and relevance tests, built from what actually happened on the owner's site.
 *
 * Run: php tests/unit/run-images.php
 *
 * Every case below is a real one taken from the logs of 2026-11-07 and 2026-11-08, so these
 * prove the specific failures are fixed rather than testing the fix in the abstract:
 *
 *  - The 10:00 post on 2026-11-07 published an article about Dana White being offered the press
 *    secretary job, illustrated with "Boy feeling Barack Obama's hair.jpg", which scored 81.
 *  - The 16:00 post on 2026-11-08 was skipped after both articles written for it failed to find
 *    an image: one searched "Ghana and China", the other "Measure Achievers Challengers Aspirants
 *    Share".
 *
 * These need no database and no HTTP.
 */

require __DIR__ . '/stubs.php';

use ZZZCopy\Images\ArticleAnalyzer;
use ZZZCopy\Images\LicenseChecker;
use ZZZCopy\Images\CandidateScorer;
use ZZZCopy\Images\ImageQueries;
use ZZZCopy\Images\Openverse;
use ZZZCopy\Settings;

$GLOBALS['stub_options']['zzzc_settings'] = array(
	'image_query_count'     => 4,
	'image_min_width'       => 1200,
	'image_min_height'      => 675,
	'image_min_score'       => 65,
	'image_max_file_mb'     => 15,
	'image_preferred_ratio' => 1.78,
	'emdash_policy'         => 'replace',
);

/** A Commons candidate, shaped as the pipeline receives it. */
function candidate( string $title, string $description, string $categories, array $extra = array() ): array {
	return array_merge(
		array(
			'page_id'     => 1,
			'title'       => $title,
			'mime'        => 'image/jpeg',
			'width'       => 3000,
			'height'      => 1687,
			'size'        => 2000000,
			'file_url'    => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Example.jpg',
			'extmetadata' => array(
				'ObjectName'       => array( 'value' => $title ),
				'ImageDescription' => array( 'value' => $description ),
				'Categories'       => array( 'value' => $categories ),
			),
		),
		$extra
	);
}

/* =====================================================================
   Case 1: the 10:00 post on 2026-11-07
   "Trump offers press secretary position to UFC head Dana White"
   ===================================================================== */

$dana_title = 'Trump Offers Press Secretary Role to UFC Head Dana White';
$dana_head  = 'Trump offers press secretary position to UFC head Dana White';
// Written the way the real article was: the man is called "White" after first mention, and the
// building is named repeatedly. That collision is what went wrong.
$dana_body  = '<p>President Donald Trump said he had offered the role of press secretary to Dana White, '
	. 'the head of the UFC. White has run the mixed martial arts company for more than two decades and '
	. 'has appeared alongside Trump at events. White said he was considering the offer.</p>'
	. '<p>The press secretary speaks for the administration from the White House briefing room. The White House '
	. 'has not confirmed a date. Staff at the White House were told on Friday. White would replace the current '
	. 'press secretary. Trump praised White as a negotiator.</p>';
$dana_tags  = array( 'Dana White', 'Donald Trump', 'press secretary', 'UFC', 'White House' );

$dana = ArticleAnalyzer::analyze( $dana_title, $dana_body, $dana_tags, $dana_head, 'Politics', 'NBC News' );

echo "Case 1 subjects: main=" . var_export( $dana['main'], true ) . "\n";
echo "Case 1 queries: " . implode( ' | ', $dana['queries'] ) . "\n";

// The person the article is actually about must be a subject, and must not have been swallowed
// by the building. This is the merge bug: the word "White" used to be credited to "White House".
$dana_entities_lc = array_map( 'mb_strtolower', $dana['entities'] );
u_true(
	in_array( 'dana white', $dana_entities_lc, true ),
	'Case 1: "Dana White" survives as a subject rather than being absorbed into "White House"'
);
u_false(
	0 === strcasecmp( (string) $dana['main'], 'White House' ),
	'Case 1: the main subject is not the building'
);
u_true(
	count( $dana['queries'] ) >= 2,
	'Case 1: more than one search is planned'
);

// The picture that was actually published. Its labels genuinely do match a White House article:
// that is the point. It is still a photograph of Barack Obama and a child.
$obama = candidate(
	"File:Boy feeling Barack Obama's hair.jpg",
	'A young boy touches the hair of President Barack Obama in the Oval Office of the White House.',
	'Barack Obama in 2009|White House|Official White House photographs|Children in the White House'
);

$obama_score = CandidateScorer::score( $obama, $dana );
echo "Case 1 Obama photo scores: {$obama_score['total']}\n";

$obama_failure = CandidateScorer::mandatory_failure( $obama, $dana, 'Politics' );
u_true(
	'' !== $obama_failure,
	'Case 1: the Obama photograph is refused outright, whatever it scores'
);
u_true(
	false !== stripos( $obama_failure, 'Barack Obama' ),
	'Case 1: the reason names the person the picture is of: ' . $obama_failure
);

// The protection that was switched off. The article mentions the White House, the picture is
// filed under the White House, and the picture shows a child. Mentioning a building must not
// make a photograph of a child fair to use.
$child = candidate(
	'File:Schoolchildren visiting the West Wing.jpg',
	'A group of schoolchildren on a tour.',
	'White House|Children'
);
u_true(
	'' !== CandidateScorer::mandatory_failure( $child, $dana, 'Politics' ),
	'Case 1: a photograph of children is still refused although the article names the White House'
);

// A correct picture must still be accepted, or the fix would simply block everything.
$right = candidate(
	'File:Dana White at UFC 300 press conference.jpg',
	'Dana White speaking at a press conference.',
	'Dana White|Mixed martial arts'
);
u_is(
	'',
	CandidateScorer::mandatory_failure( $right, $dana, 'Politics' ),
	'Case 1: a photograph of Dana White is accepted'
);
u_true(
	CandidateScorer::score( $right, $dana )['total'] >= 65,
	'Case 1: and it scores above the minimum'
);

// A photograph of Trump is also right, because the article is about him too.
$trump = candidate(
	'File:Donald Trump speaking in 2026.jpg',
	'President Donald Trump at a rally.',
	'Donald Trump'
);
u_is(
	'',
	CandidateScorer::mandatory_failure( $trump, $dana, 'Politics' ),
	'Case 1: a photograph of Donald Trump is accepted'
);

/* =====================================================================
   Case 2: the 16:00 post on 2026-11-08, first article
   "Ghana, China partnership must promote industrialisation"
   Searched: "Ghana and China | Ghana and China minister | ..."
   ===================================================================== */

$ghana_title = 'Ghana and China Partnership Must Promote Industrialisation';
$ghana_head  = 'Ghana, China partnership must promote industrialisation, technology transfer';
$ghana_body  = '<p>The partnership between Ghana and China must promote industrialisation and the transfer of '
	. 'technology, a minister has said. Ghana has sought investment in manufacturing. China is among the largest '
	. 'investors in Ghana. The remarks were made in Accra. Ghana exports raw materials and China processes them.</p>';
$ghana_tags  = array( 'Ghana', 'China', 'industrialisation', 'Accra', 'technology transfer' );

$ghana = ArticleAnalyzer::analyze( $ghana_title, $ghana_body, $ghana_tags, $ghana_head, 'Technology', 'Ghana Business News' );

echo "Case 2 queries: " . implode( ' | ', $ghana['queries'] ) . "\n";

// "Ghana and China" is two countries, not one name. Searching Commons for the phrase found
// nothing usable: 60 files checked, best score 55.
foreach ( $ghana['queries'] as $query ) {
	u_false(
		false !== stripos( $query, 'Ghana and China' ),
		'Case 2: no search joins the two countries into one phrase (' . $query . ')'
	);
}
u_true(
	in_array( 'Ghana', $ghana['entities'], true ) || in_array( 'China', $ghana['entities'], true ),
	'Case 2: the countries are subjects in their own right'
);

$ghana_first = mb_strtolower( (string) ( $ghana['queries'][0] ?? '' ) );
u_true(
	false !== strpos( $ghana_first, 'ghana' ) || false !== strpos( $ghana_first, 'china' ),
	'Case 2: the first search is for a country, not a phrase that matches nothing'
);

// A photograph of Accra should now be reachable and acceptable.
$accra = candidate(
	'File:Accra skyline from the harbour.jpg',
	'A view of the skyline of Accra, Ghana.',
	'Accra|Ghana|Cities in Ghana'
);
u_is(
	'',
	CandidateScorer::mandatory_failure( $accra, $ghana, 'Technology' ),
	'Case 2: a photograph of Accra is accepted'
);

/* =====================================================================
   Case 3: the 16:00 post on 2026-11-08, second article
   "EY Report Finds AI in Customer Service Driving Significant Outcomes"
   Searched: "Measure Achievers Challengers Aspirants Share | ..." and found 0 files.
   ===================================================================== */

$ey_title = 'EY Report Finds AI in Customer Service Driving Significant Outcomes';
$ey_head  = 'EY Report Finds AI in Customer Service Driving Significant Outcomes';
// The chart labels that became the subject. They are capitalized mid-sentence, as chart labels
// are, and none of them names anything.
$ey_body  = '<p>A report by EY India has found that artificial intelligence in customer service is driving '
	. 'outcomes. The report groups companies into four bands. Measure Achievers Challengers Aspirants Share '
	. 'of respondents reported gains. EY surveyed companies across India. The report says adoption is uneven.</p>';
$ey_tags  = array( 'EY', 'artificial intelligence', 'customer service', 'India' );

$ey = ArticleAnalyzer::analyze( $ey_title, $ey_body, $ey_tags, $ey_head, 'Technology', 'Asianet Newsable' );

echo "Case 3 queries: " . implode( ' | ', $ey['queries'] ) . "\n";

u_false(
	false !== stripos( implode( ' ', $ey['queries'] ), 'Measure Achievers Challengers Aspirants' ),
	'Case 3: the chart labels are not used as the subject'
);

/* =====================================================================
   The merge rule on its own
   ===================================================================== */

// The rule that broke: a surname beats a name that merely begins with the same word.
$merge_body = '<p>Russell Brand appeared in court. Brand denied the allegations. Brand will return in May. '
	. 'The Brand Institute was not involved.</p>';
$merge      = ArticleAnalyzer::analyze( 'Russell Brand Appears in Court', $merge_body, array( 'Russell Brand' ), 'Russell Brand appears in court', 'Crime', 'BBC' );
u_true(
	0 === strcasecmp( (string) $merge['main'], 'Russell Brand' ),
	'Merge: a surname still joins its full name when there is one clear owner (got: ' . $merge['main'] . ')'
);

/* =====================================================================
   The person check on its own
   ===================================================================== */

u_true( CandidateScorer::looks_like_a_person( 'Barack Obama' ), 'A two-word name looks like a person' );
u_true( CandidateScorer::looks_like_a_person( 'Dana White' ), 'So does Dana White' );
u_false( CandidateScorer::looks_like_a_person( 'White House' ), 'A building does not' );
u_false( CandidateScorer::looks_like_a_person( 'Oak Street Health' ), 'Nor does an organisation' );
u_false( CandidateScorer::looks_like_a_person( 'Ghana' ), 'Nor does a single word' );
u_false( CandidateScorer::looks_like_a_person( 'UFC' ), 'Nor does an abbreviation' );

$titles = CandidateScorer::person_names_in_title( candidate( "File:Boy feeling Barack Obama's hair.jpg", '', '' ) );
u_true( in_array( 'Barack Obama', $titles, true ), 'The person in a file title is found' );

// Real file titles from the owner's own logs that were correct, and must keep working.
u_is(
	array(),
	CandidateScorer::person_names_in_title( candidate( 'File:Poundland and Pandora, Briggate, Leeds (30th May 2014).JPG', '', '' ) ),
	'A shopfront photograph is not read as naming a person'
);
u_is(
	array(),
	CandidateScorer::person_names_in_title( candidate( 'File:Parsons Bl 89th Av td (2023-10-20) 01 - Moda Apartments Oak Street Health.jpg', '', '' ) ),
	'A street photograph is not read as naming a person'
);
u_true(
	in_array( 'Taylor Swift', CandidateScorer::person_names_in_title( candidate( 'File:Taylor Swift GMA (8114067517).jpg', '', '' ) ), true ),
	'A photograph of Taylor Swift is read as naming her'
);

/* =====================================================================
   What 1.9.1 fixed, which must keep working
   ===================================================================== */

// An outlet the article quotes, or was published by, is not what it is about.
$outlet_body = '<p>A man was arrested in Toshima after an attack on his roommate, Nippon News Network reported. '
	. 'The Tokyo Metropolitan Police said the man was held on suspicion of attempted murder.</p>';
$outlet      = ArticleAnalyzer::analyze(
	'Man Arrested After Attack on Roommate in Toshima',
	$outlet_body,
	array( 'Tokyo', 'arrest' ),
	'Man arrested for attempted murder after zip-tying, stabbing roommate in Toshima apartment',
	'Crime',
	'The Tokyo Reporter'
);
$outlet_all = mb_strtolower( implode( ' ', $outlet['queries'] ) . ' ' . implode( ' ', $outlet['entities'] ) );
u_false( false !== strpos( $outlet_all, 'tokyo reporter' ), '1.9.1: the publisher is not a subject' );
u_false( false !== strpos( $outlet_all, 'nippon news network' ), '1.9.1: a quoted outlet is not a subject' );

// A word that only begins a sentence is not a name.
$sentence_body = '<p>Poundland will close stores after a deal with Gordon Brothers. Is the chain finished? '
	. 'Poundland employs 16,000 people.</p>';
$sentence      = ArticleAnalyzer::analyze(
	'Poundland to Close Stores After Gordon Brothers Deal',
	$sentence_body,
	array( 'Poundland', 'Gordon Brothers' ),
	'Poundland collapse puts 16,000 jobs at risk',
	'Business',
	'BBC'
);
$sentence_all = implode( ' ', $sentence['queries'] );
u_false( false !== stripos( $sentence_all, 'Is Poundland' ), '1.9.1: "Is" does not begin a name' );
u_false( (bool) preg_match( '/\b000\b/', $sentence_all ), '1.9.1: a plain number is not searched' );
u_true( false !== stripos( $sentence_all, 'Poundland' ), '1.9.1: the real subject is still searched' );

// A full stop ends a name: "Apple. What" is two sentences.
$stop_body = '<p>The apps are coming, says Apple. What they will do is not clear. Apple said the Phone Duo '
	. 'will ship next year. Apple has not given a date.</p>';
$stop      = ArticleAnalyzer::analyze(
	'The Duo Apps Are Coming, Says Apple',
	$stop_body,
	array( 'Apple', 'Phone Duo' ),
	'The Duo apps are coming, says Apple',
	'Technology',
	'techcrunch.com'
);
u_false( false !== stripos( implode( ' ', $stop['queries'] ), 'Apple What' ), '1.9.1: "Apple. What" is not one name' );

/* =====================================================================
   Pictures that were chosen correctly before, and must still be
   ===================================================================== */

$cvs = ArticleAnalyzer::analyze(
	'CVS Plans Pharmacy Changes at Oak Street Health',
	'<p>CVS said it would change how its pharmacy benefit managers work. Oak Street Health clinics are part of the plan. CVS runs thousands of stores.</p>',
	array( 'CVS', 'Oak Street Health', 'pharmacy' ),
	'CVS plans pharmacy changes',
	'Business',
	'Reuters'
);
$oak = candidate(
	'File:Parsons Bl 89th Av td (2023-10-20) 01 - Moda Apartments Oak Street Health.jpg',
	'Moda Apartments and an Oak Street Health clinic.',
	'Oak Street Health|Queens'
);
u_is( '', CandidateScorer::mandatory_failure( $oak, $cvs, 'Business' ), 'The CVS street photograph is still accepted' );

$swift = ArticleAnalyzer::analyze(
	'Knicks Defend Taylor Swift After Fan Comments',
	'<p>The New York Knicks defended Taylor Swift after players called her fandom performative. Swift attended a game.</p>',
	array( 'Taylor Swift', 'Knicks' ),
	"Knicks defend Taylor Swift after players name her 'fakest celebrity fan'",
	'Gaming',
	'BBC'
);
$swift_photo = candidate( 'File:Taylor Swift GMA (8114067517).jpg', 'Taylor Swift performing.', 'Taylor Swift' );
u_is( '', CandidateScorer::mandatory_failure( $swift_photo, $swift, 'Gaming' ), 'The Taylor Swift photograph is still accepted' );
u_true( CandidateScorer::score( $swift_photo, $swift )['total'] >= 65, 'And still scores above the minimum' );

/* =====================================================================
   Headline-first searching
   The owner's rule: search for the people and subjects the headline names,
   starting with the two of them together.
   https://rostrace.com/trump-says-he-offered-ufc-president-dana-white-the-white-house-press-secretary-job/
   ===================================================================== */

// The title as the site actually published it, which names the building as well as the man.
$published = ArticleAnalyzer::analyze(
	'Trump Says He Offered UFC President Dana White the White House Press Secretary Job',
	$dana_body,
	$dana_tags,
	$dana_head,
	'Politics',
	'NBC News'
);
echo "Published title queries: " . implode( ' | ', $published['queries'] ) . "\n";

u_is(
	'"Donald Trump" "Dana White"',
	$published['queries'][0] ?? '',
	'The first search is the two people of the headline, together'
);
u_false(
	false !== stripos( $published['queries'][0] ?? '', 'White House' ),
	'And it never pairs a man with a building that repeats his name'
);
u_false(
	false !== stripos( implode( ' | ', $published['queries'] ), 'President Donald Trump' ),
	'The office is not searched for alongside the man'
);

// A story about a person and a club pairs them, because a picture of her at their game is
// exactly the illustration the story wants. Her own name is still searched, so a pair that finds
// nothing costs the article nothing: at least two searches always run.
u_is(
	'"Taylor Swift" Knicks',
	$swift['queries'][0] ?? '',
	'A person and the club in the same headline are searched together first'
);
u_true(
	in_array( '"Taylor Swift"', $swift['queries'], true ),
	'And the person alone is still searched, so the pair can safely find nothing'
);

// Two bare words are searched one at a time, because Commons wants every word of a search.
// From 1.11.8 a one-word subject carries a word of context from the tags, as "Ghana Accra".
u_true(
	0 === stripos( $ghana['queries'][0] ?? '', 'Ghana' ) && false === stripos( $ghana['queries'][0] ?? '', 'China' ),
	'Two one-word subjects are not demanded together'
);

// A lower-case tag is a subject, not somebody's name, so it is never half of a pair.
u_is( '"Customer Service"', $ey['queries'][0] ?? '', 'A concept is searched on its own terms' );

/* ---- Choosing between two pictures that score alike ---- */

$both = candidate(
	'File:Donald Trump and Dana White at UFC 302.jpg',
	'President Donald Trump with Dana White at a UFC event.',
	'Donald Trump|Dana White|UFC'
);
$one  = candidate(
	'File:Dana White 2019.jpg',
	'Dana White speaking.',
	'Dana White'
);
u_true(
	CandidateScorer::subjects_matched( $both, $published ) > CandidateScorer::subjects_matched( $one, $published ),
	'A picture of both people counts more of the article subjects than a picture of one'
);
u_is( '', CandidateScorer::mandatory_failure( $both, $published, 'Politics' ), 'And it passes the rules' );
/* =====================================================================
   1.10.5: words that open a sentence, a heading or a table cell are not names
   From the owner's logs of 2026-11-09 to 14, where "No. Nvidia",
   "Check Nvidia", "Use Kuaishou", "Which Baltic" and "Laser Technology
   Which UNICROSS" were each sent to Commons as a search. The headlines
   are the real ones. The titles and bodies are stand-ins, because the
   log does not keep the article text.
   ===================================================================== */

$joined = static function ( array $analysis ): string {
	return implode( ' | ', $analysis['queries'] ) . ' | ' . implode( ' | ', $analysis['entities'] );
};

// "No." answers a question, and the next sentence begins with the company.
$nvidia = ArticleAnalyzer::analyze(
	'Nvidia Employees Ask for Jensen Huang to Return',
	'<p>Nvidia employees have signed a petition asking for Jensen Huang to return. Has the board replied? No. Nvidia has not commented. '
	. 'Was Huang asked? No. Nvidia said nothing. Huang resigned this week, and Nvidia staff keep signing.</p>',
	array( 'Nvidia', 'Jensen Huang', 'petition' ),
	'Nvidia employees demand second chance for Jensen Huang, return petition gets over 1,600 comments',
	'Technology',
	'India Today'
);
u_false( false !== stripos( $joined( $nvidia ), 'No. Nvidia' ), '1.10.5: "No. Nvidia" is not one name' );
u_true( in_array( 'Nvidia', $nvidia['entities'], true ), '1.10.5: Nvidia is the subject instead' );
u_true( false !== stripos( $joined( $nvidia ), 'Jensen Huang' ), '1.10.5: Jensen Huang is still searched' );

// A table cell, a list item and a paragraph each end what came before them.
$cells = ArticleAnalyzer::analyze(
	'Kuaishou Technology Shares Fall as Sales Rise',
	'<p>Kuaishou Technology shares fell 60% while sales rose.</p>'
	. '<table><tr><th>Use</th><th>Kuaishou</th></tr><tr><td>Check</td><td>Kuaishou</td></tr></table>'
	. '<ul><li>Margin: Use</li><li>Kuaishou said its margin narrowed</li></ul>'
	. '<p><strong>Quick Check</strong></p><p>Kuaishou expects more spending.</p>',
	array( 'Kuaishou', 'Kuaishou Technology', 'margin' ),
	'Kuaishou Technology Fell 60% While Sales Rose',
	'Technology',
	'Simply Wall St'
);
u_false( false !== stripos( $joined( $cells ), 'Use Kuaishou' ), '1.10.5: two table cells are not one name' );
u_false( false !== stripos( $joined( $cells ), 'Check Kuaishou' ), '1.10.5: two paragraphs are not one name' );
u_true( false !== stripos( $cells['queries'][0] ?? '', 'Kuaishou' ), '1.10.5: Kuaishou is searched first' );

// A question heading in Title Case, with nothing in the text to confirm its words against.
$which = ArticleAnalyzer::analyze(
	'Students to Learn Production Skills',
	'<p>Students will learn production skills on new machines this term.</p>'
	. '<h2>Which UNICROSS Students Will Train First</h2><p>The first group starts this month.</p>',
	array( 'UNICROSS', 'laser technology' ),
	'UNICROSS Students to Acquire Modern Production Skills with New Laser Technology',
	'Technology',
	'Cross River State News'
);
u_false( false !== stripos( $joined( $which ), 'Which' ), '1.10.5: "Which" does not begin a name' );
u_true( false !== stripos( $joined( $which ), 'UNICROSS' ), '1.10.5: UNICROSS is still searched' );

// Names that look like the words above are kept whole.
$capcom = ArticleAnalyzer::analyze(
	'Resident Evil Remake Gets Update From Jun Takeuchi',
	'<p>Jun Takeuchi of Capcom gave an update on the Resident Evil remake. Takeuchi said work continues.</p>',
	array( 'Resident Evil', 'Jun Takeuchi', 'Capcom' ),
	'Resident Evil 1 Remake Gets Official Update From Capcom',
	'Entertainment',
	'screenrant.com'
);
u_true( false !== stripos( $joined( $capcom ), 'Jun Takeuchi' ), '1.10.5: "Jun" is a first name, not a month' );

/* =====================================================================
   1.11.0: the personality notice, and square pictures
   The owner's decisions of 2026-11-14, after the log showed all 37
   files found for Jensen Huang and all 40 for Golshifteh Farahani
   refused, most of them for "personality".
   ===================================================================== */

$license = static function ( string $restrictions ): array {
	return LicenseChecker::check(
		array(
			'LicenseShortName'    => array( 'value' => 'CC BY 4.0' ),
			'License'             => array( 'value' => 'cc-by-4.0' ),
			'LicenseUrl'          => array( 'value' => 'https://creativecommons.org/licenses/by/4.0' ),
			'Artist'              => array( 'value' => 'Example Photographer' ),
			'AttributionRequired' => array( 'value' => 'true' ),
			'Restrictions'        => array( 'value' => $restrictions ),
		)
	);
};
u_true( $license( '' )['ok'], '1.11.0: a correct CC BY passes' );
u_true( $license( 'personality' )['ok'], '1.11.0: the personality notice does not refuse a file' );
u_false( $license( 'trademarked' )['ok'], '1.11.0: a trademark restriction still refuses it' );
u_false( $license( 'personality|trademarked' )['ok'], '1.11.0: and so does a trademark beside the personality notice' );
u_true( false !== strpos( $license( 'personality|trademarked' )['reason'], 'trademarked' ), '1.11.0: the reason names the restriction' );
u_false( $license( 'insignia' )['ok'], '1.11.0: any other restriction still refuses it' );

$shape = static function ( int $width, int $height ) use ( $published ): string {
	return CandidateScorer::mandatory_failure(
		candidate( 'File:Dana White 2019.jpg', 'Dana White speaking.', 'Dana White', array( 'width' => $width, 'height' => $height ) ),
		$published,
		'Politics'
	);
};
u_is( '', $shape( 3000, 1687 ), '1.11.0: a landscape picture passes, as before' );
u_is( '', $shape( 2000, 2000 ), '1.11.0: a square picture passes' );
u_true( false !== strpos( $shape( 1600, 2000 ), 'portrait' ), '1.11.0: a portrait picture is still refused' );
/* =====================================================================
   1.11.0: a Title Case headline names only what the article confirms
   In Title Case every word has a capital, so "Sales Rose", "Remake
   Gets" and "Formal Cooperation" each looked like a name and were sent
   to Commons. The headlines are the real ones from the owner's logs of
   2026-11-12 to 14. The bodies are stand-ins.
   ===================================================================== */

u_false( false !== stripos( $joined( $cells ), 'Sales Rose' ), '1.11.0: "Sales Rose" is a verb phrase, not a name' );
u_false( false !== stripos( $joined( $cells ), 'Sales Rise' ), '1.11.0: nor is "Sales Rise"' );
u_false( false !== stripos( $joined( $capcom ), 'Remake Gets' ), '1.11.0: nor is "Remake Gets"' );
u_true( in_array( 'Jun Takeuchi', $capcom['entities'], true ), '1.11.0: a name the article uses is still a subject' );

$talks = ArticleAnalyzer::analyze(
	'John Strategy Club and Emberstone Capital Prepare for Formal Cooperation Talks',
	'<p>John Strategy Club and Emberstone Capital have moved closer to a partnership. The two firms will hold formal cooperation talks next month. '
	. 'Emberstone Capital said the partnership would cover research. John Strategy Club has not commented.</p>',
	array( 'John Strategy Club', 'Emberstone Capital', 'partnership' ),
	'John Strategy Club and Emberstone Capital Advance Strategic Partnership Discussions Prepare for Formal Cooperation Talks',
	'Technology',
	'TechBullion'
);
u_false( false !== stripos( $joined( $talks ), 'Formal Cooperation' ), '1.11.0: "Formal Cooperation" is not a name' );
u_false( false !== stripos( $joined( $talks ), 'Advance Strategic' ), '1.11.0: nor is "Advance Strategic"' );
u_true( in_array( 'Emberstone Capital', $talks['entities'], true ), '1.11.0: the two firms are still the subjects' );
u_true( in_array( 'John Strategy Club', $talks['entities'], true ), '1.11.0: both of them' );

// A headline with no article text behind it, as in the preview search, is read as it was before.
$alone = ArticleAnalyzer::analyze( 'Kuaishou Technology Shares Fall', '', array(), 'Kuaishou Technology Shares Fall', 'Technology' );
u_true( false !== stripos( implode( ' | ', $alone['queries'] ), 'Kuaishou Technology' ), '1.11.0: a headline alone still gives a search' );

/* =====================================================================
   1.11.7: the 22:00 post on 2026-11-15
   "Halo: Campaign Evolved Adds Firefight in a Free Winter Update" was
   published with a photograph of a solar halo, which scored 68.
   ===================================================================== */

$halo = ArticleAnalyzer::analyze(
	'Halo: Campaign Evolved Adds Firefight in a Free Winter Update',
	'<p>Halo: Campaign Evolved is set to add Firefight in a free winter update, Xbox has confirmed. The cooperative mode puts squads of up to four Spartans against waves of enemies.</p>'
	. '<p>Firefight debuted in Halo 3: ODST and returned in Halo: Reach. Players at Xbox FanFest in Seattle tried the mode first.</p>',
	array( 'Halo', 'Halo campaign', 'Firefight', 'Xbox', 'Halo Studios', 'winter update', 'co-op' ),
	'Halo: Firefight Officially Announced For Winter 2026',
	'Entertainment',
	'screenrant.com'
);
$sun_halo = candidate(
	'File:Halo, sun dogs and sun pillar.jpg',
	'Halo, sun dogs and sun pillar - Sandomierz (Poland)',
	'Parhelia|Sun pillars|Self-published work|Images from Wiki Science Competition 2019 in Poland|General category images from Wiki Science Competition 2019|Solar halos|Uploaded via Campaign:wsc-pl',
	array( 'width' => 3264, 'height' => 1836 )
);
u_false( false !== strpos( CandidateScorer::label_text( $sun_halo ), 'campaign' ), '1.11.7: an upload-campaign category is not part of the labels' );
u_is( array( 'halo' ), CandidateScorer::matched_words( $sun_halo, $halo ), '1.11.7: the solar halo shares only the word "halo"' );
u_true( '' !== CandidateScorer::mandatory_failure( $sun_halo, $halo, 'Entertainment' ), '1.11.7: so the solar halo photograph is refused' );

$halo_event = candidate(
	'File:Halo 4 launch at the Microsoft Store.jpg',
	'Halo 4 launch event, Xbox.',
	'Halo (franchise)|Xbox|Microsoft Store|Uploaded via Campaign:example'
);
u_is( '', CandidateScorer::mandatory_failure( $halo_event, $halo, 'Entertainment' ), '1.11.7: a Halo game photograph that also says Xbox is accepted' );

// 1.11.8: "Halo" alone found 352,599 Commons files, mostly sun halos; "Halo Xbox" found 135, mostly the game.
$halo_lc = array_map( 'strtolower', $halo['queries'] );
u_true( in_array( 'halo xbox', $halo_lc, true ), '1.11.8: the one-word subject "Halo" is searched with "Xbox" from the tags' );
u_false( in_array( 'halo', $halo_lc, true ), '1.11.8: "Halo" is not searched on its own while other searches are left' );
u_false( in_array( 'firefight', $halo_lc, true ), '1.11.8: nor is "Firefight", which finds firefighting' );

// With no tag to give context, a one-word subject is still searched, so no post loses its search.
$plain = ArticleAnalyzer::analyze( 'Nvidia Shares Fall After Earnings', '<p>Nvidia shares fell after earnings. Nvidia said demand stayed strong.</p>', array( 'Nvidia', 'earnings', 'stocks' ), 'Nvidia shares fall', 'Business', 'CNBC' );
u_true( in_array( 'Nvidia', $plain['queries'], true ), '1.11.8: a one-word subject with no context tag is searched as before' );

/* =====================================================================
   1.12.4: the 22:00 post on 2026-11-17
   "Doha Film Festival 2026 to Open November 19 With 15 Competition
   Films and More Guests" was published with a close-up of purple
   mushrooms, File:De Famberhorst 08-11-2021. (d.j.b) 03.jpg, at 77.
   The title, headline, tags and file labels are the real ones; the
   body is a shortened copy of the published article.
   ===================================================================== */

$doha_tags = array( 'Cinema Alkhaleej', 'Creative Excellence Award', 'Doha cultural events', 'Doha Film Festival 2026', 'Doha Film Institute', 'Doha Grand Prix', 'film festival jury', 'film festival lineup', 'film industry development', 'Gulf cinema', 'international film competition', 'international filmmakers', 'Made In Qatar', 'Middle Eastern cinema', 'Palestinian filmmakers', 'Qatar cinema', 'Qatar film production', 'Sara Ishaq', 'The Station', 'Yemeni film' );
$doha      = ArticleAnalyzer::analyze(
	'Doha Film Festival 2026 to Open November 19 With 15 Competition Films and More Guests',
	'<p>The Doha Film Festival is set to open on November 19 with 15 films in its International Competition. Doha Film Institute chief executive Fatma Al Remaihi says the festival will feature more films and guests than last year. The event continues through November 27.</p>'
	. '<h2>Doha’s festival plans reflect both uncertainty and a wider cultural calendar</h2>'
	. '<p>Al Remaihi told Deadline that the Doha Film Institute planned for three possible formats. The festival is scheduled alongside other major events in Doha: the Fashion Trust Arabia Awards on November 28 and the Formula 1 Grand Prix from November 27 to 28.</p>'
	. '<table><tr><td>Doha Film Festival</td><td>November 19–27</td></tr><tr><td>Formula 1 Grand Prix in Doha</td><td>November 27–28</td></tr></table>'
	. '<h2>International Competition puts regional stories and festival-circuit films in focus</h2>'
	. '<p>The opening film is The Station, a Yemen-set drama by Sara Ishaq, backed by the Doha Film Institute. The festival is scheduled to open on November 19 and close on November 27.</p>'
	. '<h2>References</h2><p>Goodfellow M. Festival In Focus: Doha Gears Up For Lift-Off Against Geopolitical Odds. Deadline.</p>',
	$doha_tags,
	'Festival In Focus: Doha Gears Up For Lift-Off Against Geopolitical Odds: “It’s Not Smaller, We Have More Films & Guests”',
	'Entertainment',
	'Deadline'
);
echo 'Doha queries: ' . implode( ' | ', $doha['queries'] ) . "\n";
$doha_q = strtolower( implode( ' | ', $doha['queries'] ) );

u_false( in_array( 'November', $doha['entities'], true ), '1.12.4: a month is not one of the subjects' );
u_is( 'Doha Film Festival', $doha['main'], '1.12.4: the main subject is the festival, without the year from its tag' );
u_false( false !== strpos( $doha_q, 'november' ), '1.12.4: no search uses "November"' );
u_false( false !== strpos( $doha_q, 'yemeni film' ), '1.12.4: "Yemeni film" is a kind of thing, not a name to pair with anything' );
u_is( '"Doha Film Festival"', $doha['queries'][0] ?? '', '1.12.4: the first search is the festival, as an exact phrase' );
u_true( false !== strpos( $doha_q, '"doha film institute"' ), '1.12.4: the institute is searched too, as a phrase' );
u_false( in_array( 'november', $doha['keywords'], true ), '1.12.4: a month is not a keyword either' );
u_is( 'Doha', $doha['place'], '1.12.4: the story happens in Doha' );
u_is( array( 'Doha skyline', 'Doha cityscape' ), $doha['fallback'], '1.12.4: so a view of Doha is the last resort' );

$mushroom = candidate(
	'File:De Famberhorst 08-11-2021. (d.j.b) 03.jpg',
	'Laccaria amethystina (amethyst deceiver) Focus stack of 20 photos.',
	'Self-published work|Laccaria amethystina in the Netherlands|Close-up photographs of mushrooms|Created with Helicon Focus|Netherlands photographs taken on 2021-11-08|2021 in De Fryske Marren|Fungi of Friesland in November|Focus stacking images by User:Famberhorst|Focus stacking images of mushrooms|Fungi of De Famberhorst|Focus stacked image',
	array( 'width' => 4766, 'height' => 2979 )
);
u_false( false !== strpos( CandidateScorer::label_text( $mushroom ), 'focus' ), '1.12.4: "Focus stacking" is how the photograph was made, so it is not a label' );
u_is( array(), CandidateScorer::matched_words( $mushroom, $doha ), '1.12.4: the mushroom photograph shares no article word' );
u_true( false !== stripos( CandidateScorer::mandatory_failure( $mushroom, $doha, 'Entertainment' ), 'never mentions' ), '1.12.4: and is refused as nature the article never mentions' );

// Two stray headline words, with nothing from the subject, are not enough on their own.
$stray = candidate( 'File:Guests in focus at the open day.jpg', 'Guests at an open day.', 'Open days|Visitors' );
u_true( '' !== CandidateScorer::mandatory_failure( $stray, $doha, 'Entertainment' ), '1.12.4: a file sharing only headline words, not the subject, is refused' );

// A real festival photograph is still accepted.
$festival = candidate( 'File:Red carpet at the Doha Film Festival.jpg', 'Guests arrive at the Doha Film Festival opening.', 'Doha Film Festival|Film festivals in Qatar' );
u_is( '', CandidateScorer::mandatory_failure( $festival, $doha, 'Entertainment' ), '1.12.4: a Doha Film Festival photograph is accepted' );

// The last resort: a view of Doha passes the place rules, a picture of something else in Doha does not.
$view_of_doha = array_merge( $doha, array( 'view_of' => 'Doha' ) );
$skyline      = candidate( 'File:Doha Skyline 10.jpg', 'Skyline of Doha at dusk.', 'Skylines of Doha|West Bay, Doha' );
u_is( '', CandidateScorer::mandatory_failure( $skyline, $view_of_doha, 'Entertainment' ), '1.12.4: a Doha skyline is accepted as a view of the place' );
$doha_bird = candidate( 'File:Doha corniche birds.jpg', 'Birds on the corniche in Doha.', 'Birds of Qatar|Doha Corniche' );
u_true( '' !== CandidateScorer::mandatory_failure( $doha_bird, $view_of_doha, 'Entertainment' ), '1.12.4: a picture of birds in Doha is still refused' );
$doha_mall = candidate( 'File:Doha mall interior.jpg', 'A shopping mall in Doha.', 'Shopping malls in Doha' );
u_true( '' !== CandidateScorer::mandatory_failure( $doha_mall, $view_of_doha, 'Entertainment' ), '1.12.4: a picture that is not a general view of Doha is refused' );
u_true( '' !== CandidateScorer::mandatory_failure( $skyline, array_merge( $doha, array( 'view_of' => 'Dubai' ) ), 'Entertainment' ), '1.12.4: a skyline of another city is refused' );

// A month that is also a name keeps its meaning outside a date.
$april = ArticleAnalyzer::analyze( 'April Ryan Joins Newsroom on June 3', '<p>April Ryan will join the newsroom on June 3. April Ryan said she was pleased. Ryan covered the White House.</p>', array( 'April Ryan', 'journalism' ), 'April Ryan joins newsroom', 'Politics', 'Axios' );
u_is( 'April Ryan', $april['main'], '1.12.4: "April Ryan" is still a name' );
u_false( false !== stripos( implode( ' | ', $april['queries'] ), 'June' ), '1.12.4: but "June 3" is a date, and not searched' );

// "in Halo 3" names a game, not a place, so Halo has no view to fall back on.
u_is( '', $halo['place'], '1.12.4: a game is not taken for a place' );

/* =====================================================================
   1.11.10: the AI names what the picture must show, and Openverse
   (Flickr and Wikimedia Commons) is the only image source.
   ===================================================================== */

$doha_article = 'Doha Film Festival 2026 to Open November 19 With 15 Competition Films and More Guests . '
	. implode( ' . ', $doha_tags ) . ' . The Doha Film Festival is set to open on November 19 with 15 films in its International Competition. '
	. 'Doha Film Institute chief executive Fatma Al Remaihi says the festival will feature more films and guests. The opening film is The Station, a Yemen-set drama by Sara Ishaq. '
	. 'The festival is scheduled alongside other major events in Doha.';

// A reply as a model writes it: in a code fence, with one search that is only a date, one with a
// festival the article never mentions, and an "avoid" word the article uses itself.
$reply = "```json\n" . json_encode(
	array(
		'core'     => 'The Doha Film Festival 2026 opens on November 19 with 15 competition films.',
		'names'    => array( 'Doha Film Festival', 'November 19', 'Cannes Film Festival' ),
		'searches' => array( '"Doha Film Festival"', 'November 19', '"Doha Film Institute"', '"Cannes Film Festival" Doha', '“Sara Ishaq” film', 'Doha festival' ),
		'avoid'    => array( 'mushroom', 'film', 'fungi' ),
		'place'    => 'Doha',
	)
) . "\n```";
$plan = ImageQueries::check( $reply, $doha_article );
u_true( $plan['ok'], '1.11.10: a JSON reply in a code fence is read' );
u_is( array( 'Doha Film Festival' ), $plan['names'], '1.11.10: names must be in the article, and a date is never a name' );
u_is( array( '"Doha Film Festival"', '"Doha Film Institute"', '"Sara Ishaq" film', 'Doha festival' ), $plan['searches'], '1.11.10: a date alone, and a festival the article never names, are not searched' );
u_is( array( 'mushroom', 'fungi' ), $plan['avoid'], '1.11.10: "film" is in the article, so it cannot refuse pictures' );
u_is( 'Doha', $plan['place'], '1.11.10: the place is kept when the article names it' );

u_false( ImageQueries::check( 'Sorry, I cannot help with that.', $doha_article )['ok'], '1.11.10: a reply that is not JSON is not used' );
u_false( ImageQueries::check( '{"searches":["Cannes red carpet"],"names":[]}', $doha_article )['ok'], '1.11.10: when no search uses the article\'s words, the plugin\'s own searches run' );
u_is( 'Lagos', ImageQueries::check( '{"searches":["Doha"],"place":"Lagos"}', 'Lagos and Doha' )['place'], '1.11.10: a place the article names is kept' );
u_is( '', ImageQueries::check( '{"searches":["Doha"],"place":"Paris"}', 'Doha only' )['place'], '1.11.10: a place the article never names is dropped' );


$applied = ImageQueries::apply( $doha, $plan );
u_is( $plan['searches'], $applied['queries'], '1.11.10: the AI searches run first, and fill the 4 searches here' );
u_is( 4, count( $applied['queries'] ), '1.11.10: the plugin\'s own searches fill the rest, within the limit' );
u_is( 'Doha Film Festival', $applied['main'], '1.11.10: the AI\'s first name is the main subject' );
u_is( array( 'Doha skyline', 'Doha cityscape' ), $applied['fallback'], '1.11.10: the AI\'s place gives the view to fall back on' );
u_is( $doha['queries'], ImageQueries::apply( $doha, array( 'ok' => false ) )['queries'], '1.11.10: when the AI fails, nothing changes' );


// The avoid list refuses a different thing of the same name.
$halo_plan = ImageQueries::check( '{"core":"Halo game update","names":["Halo"],"searches":["\"Halo: Campaign Evolved\"","Halo Xbox","Halo Firefight"],"avoid":["sun","solar","optics"],"place":""}', 'Halo: Campaign Evolved adds Firefight, Xbox has confirmed. Halo 3.' );
u_is( array( '"Halo Campaign Evolved"', 'Halo Xbox', 'Halo Firefight' ), $halo_plan['searches'], '1.11.10: the Halo searches name the game' );
$halo_ai = ImageQueries::apply( $halo, $halo_plan );
u_true( false !== strpos( CandidateScorer::mandatory_failure( $sun_halo, $halo_ai, 'Entertainment' ), '"sun"' ), '1.11.10: the solar halo is refused for its "sun" label' );
u_is( '', CandidateScorer::mandatory_failure( $halo_event, $halo_ai, 'Entertainment' ), '1.11.10: the Halo game photograph still passes' );

// Openverse results, shaped as the live API returned them on 2026-11-20.
$flickr_raw = array(
	'id'                  => 'c1100267-2d8f-4162-b896-4b8cfb0e2267',
	'title'               => 'Doha Film Festival',
	'foreign_landing_url' => 'https://www.flickr.com/photos/97189192@N00/6291964975',
	'url'                 => 'https://live.staticflickr.com/6117/6291964975_7cf57d7038_b.jpg',
	'creator'             => 'isapisa',
	'creator_url'         => 'https://www.flickr.com/photos/97189192@N00',
	'license'             => 'by-sa',
	'license_version'     => '2.0',
	'license_url'         => 'https://creativecommons.org/licenses/by-sa/2.0/',
	'source'              => 'flickr',
	'width'               => 1024,
	'height'              => 768,
	'thumbnail'           => 'https://api.openverse.org/v1/images/c1100267-2d8f-4162-b896-4b8cfb0e2267/thumb/',
	'tags'                => array(
		array( 'name' => 'doha', 'accuracy' => null ),
		array( 'name' => 'filmfestival', 'accuracy' => null ),
		array( 'name' => 'katara', 'accuracy' => null ),
		array( 'name' => 'people', 'accuracy' => 0.97 ),
	),
);
$flickr = Openverse::normalize( $flickr_raw );
u_is( 'Doha Film Festival (Flickr 6291964975)', $flickr['title'], '1.11.10: a Flickr title carries its photo number, so two photos with one title stay apart' );
u_is( 'image/jpeg', $flickr['mime'], '1.11.10: the type comes from the file name' );
u_true( $flickr['page_id'] > 1099511627776, '1.11.10: the history number cannot collide with a Commons page number' );
u_is( $flickr['page_id'], Openverse::normalize( array_merge( $flickr_raw, array( 'id' => strtoupper( $flickr_raw['id'] ) ) ) )['page_id'], '1.11.10: the number is stable' );
u_false( false !== strpos( CandidateScorer::label_text( $flickr ), 'people' ), '1.11.10: a machine-guessed tag is not a label' );
$flickr_license = LicenseChecker::check( $flickr['extmetadata'] );
u_true( $flickr_license['ok'], '1.11.10: a Flickr CC BY-SA 2.0 photo passes the license check' );
u_is( 'isapisa', $flickr_license['creator'], '1.11.10: with its creator named' );
u_is( '', CandidateScorer::mandatory_failure( $flickr, $applied, 'Entertainment' ), '1.11.10: the real Doha Film Festival photo from Flickr is accepted, at 1,024 pixels wide' );
u_true( CandidateScorer::subjects_matched( $flickr, $applied ) > 0, '1.11.10: and it names the subject, so it ends the searching' );
u_is( 1024, Openverse::min_width(), '1.11.10: a 1,200-pixel minimum is used as 1,024, the widest Flickr gives' );

$nc = Openverse::normalize( array( 'id' => 'x1', 'title' => 'Doha', 'url' => 'https://live.staticflickr.com/1/2_b.jpg', 'source' => 'flickr', 'license' => 'by-nc', 'license_version' => '2.0', 'license_url' => 'https://creativecommons.org/licenses/by-nc/2.0/', 'creator' => 'A', 'width' => 1024, 'height' => 768 ) );
u_false( LicenseChecker::check( $nc['extmetadata'] )['ok'], '1.11.10: a non-commercial license is refused even if Openverse returns it' );
$pdm = Openverse::normalize( array( 'id' => 'x2', 'title' => 'Old harbour', 'url' => 'https://live.staticflickr.com/1/3_b.jpg', 'source' => 'flickr', 'license' => 'pdm', 'license_version' => '1.0', 'license_url' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'creator' => '', 'width' => 1024, 'height' => 768 ) );
u_true( LicenseChecker::check( $pdm['extmetadata'] )['ok'], '1.11.10: a public domain mark is accepted' );
$cc0 = Openverse::normalize( array( 'id' => 'x3', 'title' => 'Sea', 'url' => 'https://live.staticflickr.com/1/4_b.jpg', 'source' => 'flickr', 'license' => 'cc0', 'license_version' => '1.0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'creator' => '', 'width' => 1024, 'height' => 768 ) );
u_true( LicenseChecker::check( $cc0['extmetadata'] )['ok'], '1.11.10: CC0 is accepted' );

$wiki = Openverse::normalize( array( 'id' => 'w1', 'title' => 'Doha skyline (4913228969)', 'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ad/Doha_skyline_%284913228969%29.jpg', 'foreign_landing_url' => 'https://commons.wikimedia.org/w/index.php?curid=1', 'source' => 'wikimedia', 'license' => 'by-sa', 'license_version' => '2.0', 'license_url' => 'https://creativecommons.org/licenses/by-sa/2.0/', 'creator' => 'Someone', 'width' => 4032, 'height' => 3024, 'filesize' => 6371059 ) );
u_is( 'File:Doha_skyline_(4913228969).jpg', $wiki['title'], '1.11.10: a Wikimedia file keeps its file name, so one used through Commons before is still recognised' );
u_is( 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ad/Doha_skyline_%284913228969%29.jpg/1920px-Doha_skyline_%284913228969%29.jpg', $wiki['download'], '1.11.10: a large Wikimedia original is downloaded at 1,920 pixels' );
u_is( 0, $wiki['size'], '1.11.10: the original\'s size is not checked against the smaller copy' );
u_is( null, Openverse::normalize( array( 'id' => 'm1', 'title' => 'Vase', 'url' => 'https://ids.si.edu/ids/a.jpg', 'source' => 'smithsonian_x', 'license' => 'cc0' ) ), '1.11.10: files kept on other hosts are not used' );
u_is( null, Openverse::normalize( array( 'id' => 'm2', 'title' => 'Doha', 'url' => 'https://live.staticflickr.com.evil.example/a.jpg', 'source' => 'flickr', 'license' => 'by' ) ), '1.11.10: a look-alike host is refused' );
u_is( 'Flickr', Openverse::source_name( 'https://www.flickr.com/photos/1/2' ), '1.11.10: a Flickr photo is credited to Flickr' );
u_is( 'Wikimedia Commons', Openverse::source_name( 'https://commons.wikimedia.org/wiki/File:A.jpg' ), '1.11.10: and a Commons file to Wikimedia Commons' );

u_is( 'Doha Film Festival', Openverse::display_title( $flickr['title'] ), '1.11.10: the photo number is left out of the alt text and the credit' );

exit( u_report( 'InkRush image subject and relevance: real cases from the logs' ) );
