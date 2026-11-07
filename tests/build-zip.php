<?php
/**
 * Builds the release ZIP with forward-slash paths, so it installs on any host.
 * The file is named after the brand, inkrush-{version}.zip. The folder inside stays zzz-copy-das-sobur,
 * the plugin's technical name, so uploading it replaces an installed copy instead of adding a second one.
 * Usage: php -d extension=zip tests/build-zip.php
 */

$root    = dirname( __DIR__ );
$source  = $root . '/zzz-copy-das-sobur';
$version = '1.9.0';
$dist    = $root . '/dist';

if ( ! is_dir( $dist ) ) {
	mkdir( $dist );
}

$target = "$dist/inkrush-$version.zip";
@unlink( $target );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	exit( "Could not create the ZIP.\n" );
}

$count = 0;
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $source ) + 1 ) );
	$zip->addFile( $file->getPathname(), 'zzz-copy-das-sobur/' . $relative );
	$count++;
}
$zip->close();

printf( "%s\n%d files, %d bytes\nsha256 %s\n", $target, $count, filesize( $target ), hash_file( 'sha256', $target ) );
