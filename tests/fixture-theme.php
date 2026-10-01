<?php
/**
 * Serves the `mesmerize` fixture theme to a request carrying `?sahaj_fixture_theme=mesmerize`.
 *
 * The classic render run writes this as an mu-plugin. One server then covers both the plain
 * classic theme and a hero theme, whose `header.php` prints a hero the Atlas page must not show.
 * Filtering both names is how core's own theme preview swaps a theme for one request.
 *
 * @package SahajAtlas
 */

if ( isset( $_GET['sahaj_fixture_theme'] ) && 'mesmerize' === $_GET['sahaj_fixture_theme'] ) {
	$sahaj_fixture_theme = function () {
		return 'mesmerize';
	};

	add_filter( 'template', $sahaj_fixture_theme );
	add_filter( 'stylesheet', $sahaj_fixture_theme );
}
