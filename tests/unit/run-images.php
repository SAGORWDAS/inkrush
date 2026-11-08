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
use ZZZCopy\Images\CandidateScorer;
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

exit( u_report( 'InkRush image subject and relevance: real cases from the logs' ) );
