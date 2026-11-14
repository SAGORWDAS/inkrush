<?php
/**
 * Tests for the check that an article was cut off part way through.
 *
 * Run: php tests/unit/run-validator.php
 *
 * In the owner site's log for 2026-11-09 to 14, "The article appears to stop mid-sentence" sent 15
 * articles back to be written again, and each one is a paid generation. The log does not keep the
 * article text, so the endings below are stand-ins for what a finished article can end with. They
 * are not the endings of those 15 articles.
 *
 * These need no database and no HTTP.
 */

require __DIR__ . '/stubs.php';

use ZZZCopy\Validator;

$GLOBALS['stub_options']['zzzc_settings'] = array(
	'min_article_words' => 300,
	'emdash_policy'     => 'replace',
);

$sentences = str_repeat( 'This sentence gives readers one clear fact about the plan. Short lines help. ', 12 );
$start     = "<p>$sentences</p><p>$sentences</p><h2>First part</h2><p>$sentences</p><h2>Second part</h2><ul><li>One</li><li>Two</li></ul><p>$sentences</p>"
	. '<h3>Dates</h3><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
	. "<h4>Reader notes</h4><p>$sentences</p><h2>Trending FAQ</h2>";
$tags      = array_map(
	static function ( $number ) {
		return "tag$number";
	},
	range( 1, 20 )
);

/** Whether an article ending this way, with this finish reason, is reported as cut off. */
$cut_off = static function ( string $ending, string $finish_reason ) use ( $start, $tags ): bool {
	$result = Validator::validate( 'A Title', $start . $ending, $finish_reason, '', $tags );
	return in_array( 'truncated', array_column( $result['errors'], 0 ), true );
};

/* ---- Unchanged: these were reported before, and still are ---- */

u_false( $cut_off( '<p>When? Soon, officials said.</p>', 'stop' ), 'A finished article passes' );
u_true( $cut_off( '<p>When? Soon, officials said.</p>', 'length' ), 'The output token limit is always a cut' );
u_true( $cut_off( '<p>When? Soon, officials said that the</p>', 'stop' ), 'Ending on "the" is a cut, even when the model stopped itself' );
u_true( $cut_off( '<p>The company said it would review the plan,</p>', 'stop' ), 'Ending on a comma is a cut' );
u_true( $cut_off( '<p>The plan covers three areas (</p>', 'stop' ), 'Ending on an open bracket is a cut' );
u_true( $cut_off( '<p>The vote is expected and</p>', 'stop' ), 'Ending on "and" is a cut' );
u_true( $cut_off( '<p>Shares rose 12 percent on Friday</p>', '' ), 'With no finish reason, any unpunctuated ending is a cut, as before' );
u_true( $cut_off( '<p>Shares rose 12 percent on Friday</p>', 'content_filter' ), 'And with a finish reason that is not a normal stop' );

/* ---- Changed in 1.11.0: the model ended the article itself, and the ending is a whole one ---- */

u_false( $cut_off( '<p>Source: Variety</p>', 'stop' ), '1.11.0: a source line is an ending, not a cut' );
u_false( $cut_off( '<p>Shares closed up 12%</p>', 'stop' ), '1.11.0: a figure is an ending' );
u_false( $cut_off( '<p>The film opens on 31 March 2028</p>', 'stop' ), '1.11.0: a date is an ending' );
u_false( $cut_off( '<p>More details are expected next week…</p>', 'stop' ), '1.11.0: an ellipsis character closes a sentence' );
u_false( $cut_off( '<p>The story is still developing</p>', 'STOP' ), '1.11.0: the finish reason is read without regard to case' );
u_false( $cut_off( '<p>The story is still developing</p>', 'end_turn' ), '1.11.0: "end_turn" is a normal stop too' );

/* ---- Changed in 1.11.0: a list or table inside a wrapper is still a list or table ---- */

u_false( $cut_off( '<p>Q. When?</p><div><ul><li>Soon</li><li>Next year</li></ul></div>', '' ), '1.11.0: a list inside a div ends the article as a list does' );
u_false( $cut_off( '<section><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table></section>', '' ), '1.11.0: so does a table inside a section' );
u_true( $cut_off( '<div><p>The vote is expected on Friday</p></div>', '' ), 'A paragraph inside a div is still checked as a paragraph' );

exit( u_report( 'InkRush article validation: the cut-off check' ) );
