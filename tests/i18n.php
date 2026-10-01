<?php
/**
 * Checks that a translation installed in the plugin can actually be read.
 *
 * ⚠ Every string in the admin UI was wrapped for translation, and for a year none of them could be
 * translated: no `Domain Path`, no `load_plugin_textdomain()`, no `wp_set_script_translations()`.
 * The plugin returned 200 and the panel read correctly in English, so nothing anywhere went red
 * (#17). These assertions are the only thing that notices.
 *
 * ⚠ The PHP half and the editor half are two independent readers. PHP reads the `.mo`.
 * `wp.i18n` reads a `.json` that core looks up under a different name, in a lookup this plugin can
 * get wrong on its own. Asserting one proves nothing about the other.
 *
 * @package SahajAtlas
 */

sahaj_group( 'A translation installed in the plugin is found' );

/**
 * Builds the bytes of a `.mo` file.
 *
 * ⚠ Written here rather than committed as a binary fixture: the strings a reader needs are the
 * point, and `wp i18n make-mo` is not available inside the Playground. The format is two parallel
 * length/offset tables followed by the string data. The hash table is optional and omitted — core's
 * reader does a linear scan.
 *
 * @param array<string,string> $entries msgid => msgstr. The empty msgid carries the metadata.
 * @return string
 */
function sahaj_test_mo_bytes( array $entries ) {
	ksort( $entries );

	$originals    = '';
	$translations = '';
	$otable       = '';
	$ttable       = '';
	$count        = count( $entries );
	$obase        = 28 + 16 * $count;

	foreach ( array_keys( $entries ) as $msgid ) {
		$otable    .= pack( 'VV', strlen( $msgid ), $obase + strlen( $originals ) );
		$originals .= $msgid . "\0";
	}

	$tbase = $obase + strlen( $originals );

	foreach ( $entries as $msgstr ) {
		$ttable       .= pack( 'VV', strlen( $msgstr ), $tbase + strlen( $translations ) );
		$translations .= $msgstr . "\0";
	}

	return pack( 'VVVVVVV', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, $obase )
		. $otable . $ttable . $originals . $translations;
}

$sahaj_lang_dir = SAHAJ_ATLAS_DIR . 'languages';

/*
 * ⚠ This is the name `wp i18n make-json` writes and the name core asks for: the text domain, the
 * locale, and an md5 of the script's path relative to the plugin directory. Core derives that
 * relative path from the registered `src`, in `load_script_textdomain()`. Move `editor.js` and both
 * sides change together — but only if this line is what pins them.
 */
$sahaj_json_file = $sahaj_lang_dir . '/sahaj-atlas-fr_FR-' . md5( 'blocks/embed/editor.js' ) . '.json';
$sahaj_mo_file   = $sahaj_lang_dir . '/sahaj-atlas-fr_FR.mo';

wp_mkdir_p( $sahaj_lang_dir );

file_put_contents(
	$sahaj_mo_file,
	sahaj_test_mo_bytes(
		array(
			''            => "Content-Type: text/plain; charset=UTF-8\nLanguage: fr_FR\nPlural-Forms: nplurals=2; plural=(n != 1);\n",
			'Sahaj Atlas' => 'Atlas Sahaj',
			'Clean URLs'  => 'URL propres',
		)
	)
);

file_put_contents(
	$sahaj_json_file,
	wp_json_encode(
		array(
			'domain'      => 'messages',
			'locale_data' => array(
				'messages' => array(
					''      => array(
						'domain'       => 'messages',
						'lang'         => 'fr_FR',
						'plural-forms' => 'nplurals=2; plural=(n != 1);',
					),
					'Atlas' => array( 'Atlas (fr)' ),
				),
			),
		)
	)
);

/*
 * ⚠ This is the one assertion here that fails when the call to `sahaj_atlas_load_textdomain()`
 * leaves `sahaj_atlas_init()`. Everything below calls the loader directly, so none of it would
 * notice. `load_plugin_textdomain()` registers its directory with the textdomain registry even when
 * it finds no file for the current locale, and the registry keeps that from the real `init` run.
 *
 * The directory did not exist when `init` ran, and the registry caches one listing per directory for
 * an hour, so the stale empty listing goes first.
 */
wp_cache_delete( md5( trailingslashit( $sahaj_lang_dir ) ), 'translation_files' );

sahaj_is(
	'init told the registry where this plugin keeps its translations',
	trailingslashit( $sahaj_lang_dir ),
	$GLOBALS['wp_textdomain_registry']->get( 'sahaj-atlas', 'fr_FR' )
);

$sahaj_force_french = function () {
	return 'fr_FR';
};

// `load_plugin_textdomain()` reads `determine_locale()`; so does core's script-translation lookup.
// One filter covers both halves.
add_filter( 'determine_locale', $sahaj_force_french, 99 );
unload_textdomain( 'sahaj-atlas' );
$sahaj_loaded = sahaj_atlas_load_textdomain();

sahaj_is(
	'declares Domain Path, which is what tooling reads',
	'/languages',
	get_plugin_data( SAHAJ_ATLAS_FILE, false, false )['DomainPath']
);

sahaj_ok( 'finds a translation file in the plugin, not only in WP_LANG_DIR', $sahaj_loaded );
sahaj_is( 'translates an admin string', 'Atlas Sahaj', __( 'Sahaj Atlas', 'sahaj-atlas' ) );
sahaj_is( 'and one from the diagnostics panel', 'URL propres', __( 'Clean URLs', 'sahaj-atlas' ) );

sahaj_is(
	'names the text domain on the editor script',
	'sahaj-atlas',
	wp_scripts()->registered['sahaj-atlas-editor']->textdomain
);
sahaj_is(
	'and points it at the plugin\'s own languages directory',
	untrailingslashit( $sahaj_lang_dir ),
	untrailingslashit( (string) wp_scripts()->registered['sahaj-atlas-editor']->translations_path )
);

// The inline script core prints before `editor.js`. False means the editor gets English, whatever
// is installed.
$sahaj_js_translations = wp_scripts()->print_translations( 'sahaj-atlas-editor', false );

sahaj_ok( 'prints the editor\'s translations', is_string( $sahaj_js_translations ) && '' !== $sahaj_js_translations );
sahaj_ok(
	'and they carry the translated string',
	is_string( $sahaj_js_translations ) && false !== strpos( $sahaj_js_translations, 'Atlas (fr)' )
);

remove_filter( 'determine_locale', $sahaj_force_french, 99 );
unload_textdomain( 'sahaj-atlas' );
@unlink( $sahaj_mo_file );
@unlink( $sahaj_json_file );
@rmdir( $sahaj_lang_dir );
