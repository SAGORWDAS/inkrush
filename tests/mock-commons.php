<?php
/**
 * A usable CC BY photo, each with its own picture, for tests that do not name their pages.
 */
function zzzc_default_page( int $id ): array {
	$name = 'City street view ' . $id;
	$file = 'http://' . $_SERVER['HTTP_HOST'] . '/commons/file/City_street_view_' . $id . '.jpg?' . http_build_query( array( 'w' => 1600, 'h' => 900, 'seed' => 'street' . $id ) );
	$meta = array(
		'LicenseShortName'    => 'CC BY 4.0',
		'License'             => 'cc-by-4.0',
		'LicenseUrl'          => 'https://creativecommons.org/licenses/by/4.0',
		'Artist'              => '<a href="https://commons.wikimedia.org/wiki/User:Jane_Doe">Jane Doe</a>',
		'AttributionRequired' => 'true',
		'Copyrighted'         => 'True',
		'ImageDescription'    => 'A street in the city centre.',
		'ObjectName'          => $name,
	);
	$extmetadata = array();
	foreach ( $meta as $key => $value ) {
		$extmetadata[ $key ] = array( 'value' => $value );
	}
	return array(
		'pageid'    => $id,
		'title'     => 'File:' . $name . '.jpg',
		'imageinfo' => array(
			array(
				'url'            => $file,
				'thumburl'       => $file,
				'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:City_street_view_' . $id . '.jpg',
				'width'          => 1600,
				'height'         => 900,
				'size'           => 0,
				'mime'           => 'image/jpeg',
				'extmetadata'    => $extmetadata,
			),
		),
	);
}

/**
 * Local stand-in for the Wikimedia Commons API and its media host.
 * Included by mock-server.php. Pages come from the "commons_pages" key of the mock state.
 */

if ( '/commons/api.php' === $uri ) {
	file_put_contents(
		$dir . '/requests.jsonl',
		json_encode(
			array(
				'path'   => 'commons/api',
				'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'search' => $_GET['gsrsearch'] ?? '',
				'ns'     => $_GET['gsrnamespace'] ?? '',
			)
		) . "\n",
		FILE_APPEND | LOCK_EX
	);
	$mode = zzzc_next_mode( 'commons_mode', 'ok' );
	if ( '429' === $mode ) {
		zzzc_json( 429, array( 'error' => array( 'code' => 'ratelimited' ) ), array( 'Retry-After' => '120' ) );
	}
	if ( '500' === $mode ) {
		zzzc_json( 500, array( 'error' => array( 'code' => 'internal' ) ) );
	}
	if ( 'ratelimited_body' === $mode ) {
		zzzc_json( 200, array( 'error' => array( 'code' => 'ratelimited', 'info' => 'You have exceeded your rate limit.' ) ) );
	}
	if ( 'malformed' === $mode ) {
		header( 'Content-Type: application/json' );
		echo '{not json';
		exit;
	}
	// A test can give one exact search its own pages.
	$asked = (string) preg_replace( '/^filetype:bitmap( filew:>\d+ fileh:>\d+)? /', '', (string) ( $_GET['gsrsearch'] ?? '' ) );
	if ( isset( $state['commons_by_search'][ $asked ] ) ) {
		zzzc_json( 200, array( 'query' => array( 'pages' => $state['commons_by_search'][ $asked ] ) ) );
	}
	// A test that names its pages gets exactly those. Otherwise each search gets 6 new, usable photos,
	// so every test article can have its own image even when results are cached.
	if ( ! array_key_exists( 'commons_pages', $state ) ) {
		$counter = $dir . '/commons-counter.txt';
		$next    = (int) @file_get_contents( $counter );
		$pages   = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$pages[] = zzzc_default_page( 500000 + $next + $i );
		}
		file_put_contents( $counter, (string) ( $next + 6 ), LOCK_EX );
		zzzc_json( 200, array( 'query' => array( 'pages' => $pages ) ) );
	}
	zzzc_json( 200, array( 'query' => array( 'pages' => $state['commons_pages'] ) ) );}

if ( 0 === strpos( $uri, '/commons/file/' ) ) {
	file_put_contents( $dir . '/requests.jsonl', json_encode( array( 'path' => 'commons/file', 'uri' => $_SERVER['REQUEST_URI'] ) ) . "\n", FILE_APPEND | LOCK_EX );

	$mode = zzzc_next_mode( 'file_mode', 'ok' );
	if ( '429' === $mode ) {
		http_response_code( 429 );
		header( 'Retry-After: 120' );
		exit;
	}
	if ( '500' === $mode ) {
		http_response_code( 500 );
		exit;
	}

	$kind = $_GET['kind'] ?? '';
	if ( 'redirect' === $kind ) {
		header( 'Location: http://localhost:8091/commons/file/elsewhere.jpg' );
		http_response_code( 302 );
		exit;
	}
	if ( 'html' === $kind ) {
		header( 'Content-Type: text/html' );
		echo '<html><body>Not a picture</body></html>';
		exit;
	}
	if ( 'padded' === $kind ) {
		$pad = str_repeat( "\0", 100000 );
	}
	if ( 'corrupt' === $kind ) {
		header( 'Content-Type: image/jpeg' );
		echo "\xFF\xD8\xFF" . str_repeat( 'garbage', 500 );
		exit;
	}

	$width  = (int) ( $_GET['w'] ?? 1600 );
	$height = (int) ( $_GET['h'] ?? 900 );
	$image  = imagecreatetruecolor( $width, $height );
	if ( 'flat' === $kind ) {
		imagefill( $image, 0, 0, 0x808080 );
	} else {
		mt_srand( crc32( (string) ( $_GET['seed'] ?? $uri ) ) );
		$block = 48;
		for ( $y = 0; $y < $height; $y += $block ) {
			for ( $x = 0; $x < $width; $x += $block ) {
				imagefilledrectangle( $image, $x, $y, $x + $block, $y + $block, mt_rand( 0, 0xFFFFFF ) );
			}
		}
	}
	ob_start();
	imagejpeg( $image, null, (int) ( $_GET['q'] ?? 80 ) );
	$bytes = (string) ob_get_clean();
	if ( 'truncated' === $kind ) {
		$bytes = substr( $bytes, 0, 2000 );
	}
	header( 'Content-Type: image/jpeg' );
	echo $bytes . ( $pad ?? '' );
	exit;
}
