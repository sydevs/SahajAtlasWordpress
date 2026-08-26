<?php
/**
 * Keeping thirteen installs current, from GitHub Releases.
 *
 * ⚠ **This is why the repo is public.** A private repo needs an authentication token baked into
 * every install — thirteen copies of a credential we cannot rotate, on sites we do not control.
 * A GPL plugin distributed to third parties is publishable source anyway.
 *
 * ⚠ **The slug must be identical in three places** — the release zip's top-level directory, the
 * installed folder under `wp-content/plugins/`, and the third argument below. When they disagree
 * WordPress installs the update as a *second plugin* beside the first, and the site then runs two
 * copies which each refuse the other's `<sahaj-atlas>` element. `.github/workflows/release.yml`
 * builds the zip with that directory name for exactly this reason; GitHub's own automatic
 * "Source code (zip)" wraps everything in `SahajAtlasWordpress-<tag>/` and must never be the
 * download the README points at.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** The one place the slug is written. */
define( 'SAHAJ_ATLAS_SLUG', 'sahaj-atlas' );

/**
 * Wire up the update checker.
 *
 * ⚠ Instantiated from `init` (via `sahaj_atlas_init()`), not from an `admin_*` hook — WP-CLI runs
 * no admin hooks, so an admin-only checker makes `wp plugin update` blind to our releases, which is
 * the one channel a remote helper can use on somebody else's site.
 */
function sahaj_atlas_register_updates() {
	$library = SAHAJ_ATLAS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	// Absent in a source checkout that has not vendored it; the plugin still works, it just cannot
	// self-update. Failing loudly here would break the site over a convenience.
	if ( ! file_exists( $library ) ) {
		return;
	}

	require_once $library;

	if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/sydevs/SahajAtlasWordpress/',
		SAHAJ_ATLAS_FILE,
		SAHAJ_ATLAS_SLUG
	);

	$api = $checker->getVcsApi();

	if ( $api ) {
		// Releases, not tags: a tag exists the moment it is pushed, so tag-based checking would
		// offer an update before CI has attached the built zip — and PUC would then fall back to
		// GitHub's auto-generated source archive, which is the wrongly-named one above.
		$api->enableReleaseAssets( '/^sahaj-atlas.*\.zip$/i' );
	}
}
