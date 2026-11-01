<?php
/**
 * Zips a directory with forward-slash paths under a top folder: php zip.php <dir> <zip> <folder>.
 * Used by build.sh (runs in the composer:2 image, which has the zip extension).
 *
 * @package Tassyir\WooCommerce
 */

if ( 4 !== $argc ) {
	fwrite( STDERR, "usage: php zip.php <dir> <zip> <folder>\n" );
	exit( 1 );
}
list( , $tassyir_dir, $tassyir_zip_path, $tassyir_folder ) = $argv;

$tassyir_zip = new ZipArchive();
if ( true !== $tassyir_zip->open( $tassyir_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "cannot create $tassyir_zip_path\n" );
	exit( 1 );
}
$tassyir_base  = realpath( $tassyir_dir );
$tassyir_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tassyir_base, FilesystemIterator::SKIP_DOTS ) );
$tassyir_count = 0;
foreach ( $tassyir_files as $tassyir_file ) {
	$tassyir_relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $tassyir_file->getPathname(), strlen( $tassyir_base ) + 1 ) );
	$tassyir_zip->addFile( $tassyir_file->getPathname(), $tassyir_folder . '/' . $tassyir_relative );
	++$tassyir_count;
}
$tassyir_zip->close();
echo "$tassyir_count files -> $tassyir_zip_path\n";
