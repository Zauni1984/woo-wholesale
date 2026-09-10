<?php
/**
 * CI helper: every translatable string in the plugin must exist in the German .po file.
 *
 * Usage: php bin/check-translations.php
 */

$root = dirname( __DIR__ );
$po   = file_get_contents( $root . '/languages/woo-wholesale-de_DE.po' );

preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $po, $m, PREG_SET_ORDER );
$translated = array();
foreach ( $m as $entry ) {
	$id = stripcslashes( $entry[1] );
	if ( '' === $id ) {
		continue;
	}
	$translated[ $id ] = stripcslashes( $entry[2] );
}

$pattern = "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|esc_html_x|esc_attr_x)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*(?:'(?:[^'\\\\]|\\\\.)*'\\s*,\\s*)?'woo-wholesale'/";
$missing = array();
$empty   = array();

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$path = $file->getPathname();
	if ( 'php' !== $file->getExtension() || false !== strpos( $path, '/languages/' ) || false !== strpos( $path, '/bin/' ) || false !== strpos( $path, '/build/' ) ) {
		continue;
	}
	preg_match_all( $pattern, file_get_contents( $path ), $found );
	foreach ( $found[1] as $raw ) {
		$id = str_replace( "\\'", "'", $raw );
		if ( ! array_key_exists( $id, $translated ) ) {
			$missing[ $id ] = $path;
		} elseif ( '' === $translated[ $id ] ) {
			$empty[ $id ] = $path;
		}
	}
}

if ( $missing || $empty ) {
	foreach ( $missing as $id => $path ) {
		echo "MISSING: {$id}  ({$path})\n";
	}
	foreach ( $empty as $id => $path ) {
		echo "EMPTY:   {$id}  ({$path})\n";
	}
	exit( 1 );
}

echo 'Translation coverage OK (' . count( $translated ) . " strings).\n";
