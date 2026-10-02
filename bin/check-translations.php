<?php
/**
 * CI helper: every translatable string of both plugins must exist in its German .po file.
 *
 * Usage: php bin/check-translations.php
 */

$root = dirname( __DIR__ );

$targets = array(
	array(
		'label'   => 'Woo Wholesale Pro',
		'domain'  => 'woo-wholesale',
		'po'      => $root . '/languages/woo-wholesale-de_DE.po',
		'scan'    => $root,
		'exclude' => array( '/languages/', '/bin/', '/build/', '/dist/', '/partner-plugin/' ),
	),
	array(
		'label'   => 'Woo Wholesale Partner',
		'domain'  => 'woo-wholesale-partner',
		'po'      => $root . '/partner-plugin/languages/woo-wholesale-partner-de_DE.po',
		'scan'    => $root . '/partner-plugin',
		'exclude' => array( '/languages/', '/build/', '/dist/' ),
	),
);

/**
 * Read the msgid => msgstr pairs of a .po file.
 *
 * @param string $path Path to the .po file.
 * @return array
 */
function wwpro_read_po( $path ) {
	if ( ! file_exists( $path ) ) {
		return array();
	}

	preg_match_all(
		'/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m',
		(string) file_get_contents( $path ),
		$matches,
		PREG_SET_ORDER
	);

	$translated = array();

	foreach ( $matches as $entry ) {
		$id = stripcslashes( $entry[1] );
		if ( '' === $id ) {
			continue;
		}
		$translated[ $id ] = stripcslashes( $entry[2] );
	}

	return $translated;
}

/**
 * Collect every translatable string of a plugin.
 *
 * @param string   $dir      Directory to scan.
 * @param string   $domain   Text domain.
 * @param string[] $excludes Path fragments to skip.
 * @return array msgid => file
 */
function wwpro_collect_strings( $dir, $domain, array $excludes ) {
	$pattern = "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|esc_html_x|esc_attr_x)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*(?:'(?:[^'\\\\]|\\\\.)*'\\s*,\\s*)?'" . preg_quote( $domain, '/' ) . "'/";

	$found    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		$path = str_replace( '\\', '/', $file->getPathname() );

		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		foreach ( $excludes as $exclude ) {
			if ( false !== strpos( $path, $exclude ) ) {
				continue 2;
			}
		}

		preg_match_all( $pattern, (string) file_get_contents( $path ), $matches );

		foreach ( $matches[1] as $raw ) {
			$found[ str_replace( "\\'", "'", $raw ) ] = $path;
		}
	}

	return $found;
}

$failed = false;

foreach ( $targets as $target ) {
	$translated = wwpro_read_po( $target['po'] );
	$strings    = wwpro_collect_strings( $target['scan'], $target['domain'], $target['exclude'] );

	$missing = array();
	$empty   = array();

	foreach ( $strings as $id => $path ) {
		if ( ! array_key_exists( $id, $translated ) ) {
			$missing[ $id ] = $path;
		} elseif ( '' === $translated[ $id ] ) {
			$empty[ $id ] = $path;
		}
	}

	if ( $missing || $empty ) {
		$failed = true;

		echo "--- {$target['label']} ({$target['domain']}) ---\n";

		foreach ( $missing as $id => $path ) {
			echo "MISSING: {$id}  ({$path})\n";
		}
		foreach ( $empty as $id => $path ) {
			echo "EMPTY:   {$id}  ({$path})\n";
		}

		continue;
	}

	echo "{$target['label']}: translation coverage OK (" . count( $strings ) . ' strings used, ' . count( $translated ) . " translated).\n";
}

exit( $failed ? 1 : 0 );
