<?php
/**
 * Serves a fixture theme to a request carrying `?sahaj_fixture_theme=<name>`.
 *
 * The classic render run writes this as an mu-plugin. One server then covers the plain classic
 * theme, a hero theme (`mesmerize`), whose `header.php` prints a hero the Atlas page must not show,
 * and a classic theme with no `header.php` at all (`headerless`). Filtering both names is how core's
 * own theme preview swaps a theme for one request.
 *
 * @package SahajAtlas
 */

if ( isset( $_GET['sahaj_fixture_theme'] ) && in_array( $_GET['sahaj_fixture_theme'], array( 'mesmerize', 'headerless' ), true ) ) {
	$sahaj_fixture_theme_name = $_GET['sahaj_fixture_theme'];

	$sahaj_fixture_theme = function () use ( $sahaj_fixture_theme_name ) {
		return $sahaj_fixture_theme_name;
	};

	add_filter( 'template', $sahaj_fixture_theme );
	add_filter( 'stylesheet', $sahaj_fixture_theme );
}
