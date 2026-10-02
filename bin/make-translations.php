<?php
/**
 * Build the German language files of both plugins.
 *
 * The de_DE .po file is the source of truth for the wording. Everything else -
 * the locale variants (de_AT, de_CH, de_CH_informal, de_DE_formal), the compiled
 * .mo files and the .l10n.php files WordPress 6.5+ prefers - is generated from
 * it, so a new string only has to be translated once.
 *
 * New strings are picked up from bin/translations-de-<domain>.php.
 *
 * Usage: php bin/make-translations.php
 */

$root = dirname( __DIR__ );

$targets = array(
	array(
		'name'    => 'Woo Wholesale Pro',
		'domain'  => 'woo-wholesale',
		'dir'     => $root,
		'lang'    => $root . '/languages',
		'exclude' => array( '/languages/', '/bin/', '/build/', '/dist/', '/partner-plugin/' ),
	),
	array(
		'name'    => 'Woo Wholesale Partner',
		'domain'  => 'woo-wholesale-partner',
		'dir'     => $root . '/partner-plugin',
		'lang'    => $root . '/partner-plugin/languages',
		'exclude' => array( '/languages/', '/build/', '/dist/' ),
	),
);

$locales = array( 'de_DE', 'de_DE_formal', 'de_AT', 'de_CH', 'de_CH_informal' );

/**
 * Read msgid => msgstr pairs of a .po file, keeping their order.
 *
 * @param string $path Path.
 * @return array
 */
function wwpro_po_pairs( $path ) {
	if ( ! file_exists( $path ) ) {
		return array();
	}

	preg_match_all(
		'/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m',
		(string) file_get_contents( $path ),
		$matches,
		PREG_SET_ORDER
	);

	$pairs = array();

	foreach ( $matches as $entry ) {
		$id = stripcslashes( $entry[1] );
		if ( '' === $id ) {
			continue;
		}
		$pairs[ $id ] = stripcslashes( $entry[2] );
	}

	return $pairs;
}

/**
 * Collect translatable strings of a plugin in a deterministic order.
 *
 * @param string   $dir      Directory.
 * @param string   $domain   Text domain.
 * @param string[] $excludes Path fragments to skip.
 * @return string[]
 */
function wwpro_strings( $dir, $domain, array $excludes ) {
	$pattern = "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|esc_html_x|esc_attr_x)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*(?:'(?:[^'\\\\]|\\\\.)*'\\s*,\\s*)?'" . preg_quote( $domain, '/' ) . "'/";

	$files    = array();
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

		$files[] = $path;
	}

	sort( $files );

	$found = array();

	foreach ( $files as $path ) {
		preg_match_all( $pattern, (string) file_get_contents( $path ), $matches );

		foreach ( $matches[1] as $raw ) {
			$id = str_replace( "\\'", "'", $raw );
			if ( ! in_array( $id, $found, true ) ) {
				$found[] = $id;
			}
		}
	}

	return $found;
}

/**
 * Escape a string for a .po file.
 *
 * @param string $value Value.
 * @return string
 */
function wwpro_po_escape( $value ) {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\\"', '\\n', '\\t', '' ),
		(string) $value
	);
}

/**
 * Compile a translation map into the binary .mo format.
 *
 * @param array  $messages msgid => msgstr (without the header entry).
 * @param string $header   Header value of the empty msgid.
 * @return string
 */
function wwpro_build_mo( array $messages, $header ) {
	$entries = array( '' => $header );

	foreach ( $messages as $id => $text ) {
		if ( '' === $id || '' === $text ) {
			continue;
		}
		$entries[ $id ] = $text;
	}

	// gettext expects the originals sorted by byte value.
	$ids = array_keys( $entries );
	usort( $ids, 'strcmp' );

	$count   = count( $ids );
	$o_table = 28;
	$t_table = $o_table + 8 * $count;
	$data    = $t_table + 8 * $count;

	$originals    = '';
	$translations = '';
	$strings      = '';
	$offset       = $data;

	foreach ( $ids as $id ) {
		$originals .= pack( 'VV', strlen( $id ), $offset );
		$strings   .= $id . "\0";
		$offset    += strlen( $id ) + 1;
	}

	foreach ( $ids as $id ) {
		$text          = $entries[ $id ];
		$translations .= pack( 'VV', strlen( $text ), $offset );
		$strings      .= $text . "\0";
		$offset       += strlen( $text ) + 1;
	}

	return pack( 'VVVVVVV', 0x950412de, 0, $count, $o_table, $t_table, 0, $data )
		. $originals . $translations . $strings;
}

/**
 * Render the .l10n.php file WordPress 6.5+ loads first.
 *
 * @param array  $messages msgid => msgstr.
 * @param string $domain   Text domain.
 * @param string $locale   Locale.
 * @param string $project  Project-Id-Version.
 * @return string
 */
function wwpro_build_l10n( array $messages, $domain, $locale, $project ) {
	$pairs = array();

	foreach ( $messages as $id => $text ) {
		if ( '' === $text ) {
			continue;
		}
		$pairs[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $id ) . "'=>'"
			. str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $text ) . "'";
	}

	return "<?php\nreturn ['domain'=>'" . $domain . "','plural-forms'=>'nplurals=2; plural=(n != 1);','language'=>'"
		. $locale . "','project-id-version'=>'" . $project . "','messages'=>[" . implode( ',', $pairs ) . "]];\n";
}

$version  = '1.1.0';
$exit     = 0;
$today    = '2026-10-02 12:00+0000';

foreach ( $targets as $target ) {
	$po_path = $target['lang'] . '/' . $target['domain'] . '-de_DE.po';

	$existing   = wwpro_po_pairs( $po_path );
	$dictionary = array();
	$dict_path  = __DIR__ . '/translations-de-' . $target['domain'] . '.php';

	if ( file_exists( $dict_path ) ) {
		$dictionary = (array) require $dict_path;
	}

	$strings  = wwpro_strings( $target['dir'], $target['domain'], $target['exclude'] );
	$messages = array();
	$missing  = array();

	// Keep the order of the existing file, then append what is new.
	foreach ( $existing as $id => $text ) {
		if ( in_array( $id, $strings, true ) ) {
			$messages[ $id ] = $text;
		}
	}

	foreach ( $strings as $id ) {
		if ( isset( $messages[ $id ] ) ) {
			continue;
		}

		if ( isset( $dictionary[ $id ] ) && '' !== $dictionary[ $id ] ) {
			$messages[ $id ] = $dictionary[ $id ];
			continue;
		}

		$missing[] = $id;
	}

	if ( ! empty( $missing ) ) {
		echo "{$target['name']}: " . count( $missing ) . " strings without a German translation:\n";
		foreach ( $missing as $id ) {
			echo "  - {$id}\n";
		}
		$exit = 1;
		continue;
	}

	if ( ! is_dir( $target['lang'] ) && ! mkdir( $target['lang'], 0775, true ) ) {
		echo "{$target['name']}: cannot create {$target['lang']}\n";
		$exit = 1;
		continue;
	}

	$project = $target['name'] . ' ' . $version;

	foreach ( $locales as $locale ) {
		$header = "Project-Id-Version: {$project}\n"
			. "Report-Msgid-Bugs-To: https://github.com/zauni1984/woo-wholesale/issues\n"
			. "POT-Creation-Date: {$today}\n"
			. "PO-Revision-Date: {$today}\n"
			. "Last-Translator: \n"
			. "Language-Team: \n"
			. "Language: {$locale}\n"
			. "MIME-Version: 1.0\n"
			. "Content-Type: text/plain; charset=UTF-8\n"
			. "Content-Transfer-Encoding: 8bit\n"
			. "Plural-Forms: nplurals=2; plural=(n != 1);\n"
			. "X-Domain: {$target['domain']}\n";

		$po  = "# Copyright (C) 2026 Stefan Zaunreither\n";
		$po .= "# This file is distributed under the MIT license.\n";
		$po .= "msgid \"\"\nmsgstr \"\"\n";

		foreach ( explode( "\n", trim( $header ) ) as $line ) {
			$po .= '"' . wwpro_po_escape( $line ) . '\\n"' . "\n";
		}

		foreach ( $messages as $id => $text ) {
			$po .= "\nmsgid \"" . wwpro_po_escape( $id ) . "\"\n";
			$po .= 'msgstr "' . wwpro_po_escape( $text ) . "\"\n";
		}

		$base = $target['lang'] . '/' . $target['domain'] . '-' . $locale;

		file_put_contents( $base . '.po', $po );
		file_put_contents( $base . '.mo', wwpro_build_mo( $messages, $header ) );
		file_put_contents( $base . '.l10n.php', wwpro_build_l10n( $messages, $target['domain'], $locale, $project ) );
	}

	// The template keeps every string with an empty translation, so other
	// languages can be started from it.
	$pot  = "# Copyright (C) 2026 Stefan Zaunreither\n";
	$pot .= "# This file is distributed under the MIT license.\n";
	$pot .= "msgid \"\"\nmsgstr \"\"\n";

	$pot_header = "Project-Id-Version: {$project}\n"
		. "Report-Msgid-Bugs-To: https://github.com/zauni1984/woo-wholesale/issues\n"
		. "POT-Creation-Date: {$today}\n"
		. "MIME-Version: 1.0\n"
		. "Content-Type: text/plain; charset=UTF-8\n"
		. "Content-Transfer-Encoding: 8bit\n"
		. "X-Domain: {$target['domain']}\n";

	foreach ( explode( "\n", trim( $pot_header ) ) as $line ) {
		$pot .= '"' . wwpro_po_escape( $line ) . '\\n"' . "\n";
	}

	foreach ( array_keys( $messages ) as $id ) {
		$pot .= "\nmsgid \"" . wwpro_po_escape( $id ) . "\"\n";
		$pot .= "msgstr \"\"\n";
	}

	file_put_contents( $target['lang'] . '/' . $target['domain'] . '.pot', $pot );

	echo "{$target['name']}: " . count( $messages ) . ' strings written for ' . count( $locales ) . " locales.\n";
}

exit( $exit );
