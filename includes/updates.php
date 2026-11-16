<?php
/**
 * Keeping thirteen installs current, from GitHub Releases.
 *
 * ⚠ This is why the repo is public. A private repo needs an authentication token baked into every
 * install — thirteen copies of a credential nobody can rotate, on sites nobody here controls. A
 * GPL plugin distributed to third parties is publishable source anyway.
 *
 * ⚠ The slug must be identical in three places: the release zip's top-level directory, the
 * installed folder under `wp-content/plugins/`, and the third argument below. When they disagree,
 * WordPress installs the update as a second plugin beside the first, and the site then runs two
 * copies that each refuse the other's `<sahaj-atlas>` element. `.github/workflows/release.yml`
 * builds the zip with that directory name for exactly this reason. GitHub's own automatic "Source
 * code (zip)" wraps everything in `SahajAtlasWordpress-<tag>/` instead, and must never be the
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
 * ⚠ This runs from `init` (through `sahaj_atlas_init()`), not from an `admin_*` hook. WP-CLI runs
 * no admin hooks, so an admin-only checker would make `wp plugin update` blind to our releases —
 * the one channel a remote helper can use on somebody else's site.
 */
function sahaj_atlas_register_updates() {
	$library = SAHAJ_ATLAS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	// This library is absent in a source checkout with no vendored dependencies. The plugin still
	// works, but cannot self-update. Failing loudly here would break the site over a convenience.
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
		// Only a release carrying the built zip offers an update. Without the zip, or from a bare
		// tag, PUC would install GitHub's auto-generated source archive: no translations, and the
		// tests and docs shipped to every site. REQUIRE alone is not enough, because PUC then falls
		// through to the latest tag, so the filter drops every strategy but the latest release.
		//
		sahaj_atlas_configure_release_api( $api );
		add_filter(
			$checker->getUniqueName( 'vcs_update_detection_strategies' ),
			function ( $strategies ) use ( $api ) {
				return array_intersect_key( $strategies, array( $api::STRATEGY_LATEST_RELEASE => true ) );
			}
		);
	}
}

/**
 * Tell the GitHub API which releases may be offered: ones with the built zip, and past the hold.
 *
 * @param object $api The checker's `GitHubApi`.
 */
function sahaj_atlas_configure_release_api( $api ) {
	// ⚠ Read both constants off the instance. Naming the class spells the vendored minor
	// version in a literal, and `load-v5p6.php` aliases no version-free `Vcs\Api`, so the next
	// vendor bump would make this a `Class not found` fatal on `init` across thirteen sites.
	$api->enableReleaseAssets( '/^sahaj-atlas.*\.zip$/i', $api::REQUIRE_RELEASE_ASSETS );

	// ⚠ The staging is a release filter, not a `pre_inject_update` one. By then the update carries
	// only `created_at`, the date of the tagged commit, and no body to read a marker from. The filter
	// sees the whole release and, by skipping a held one, falls through to the newest older release,
	// so a site on the previous version is simply offered nothing. A missing method means a vendor
	// bump took it away: no staging, today's behaviour.
	if ( method_exists( $api, 'setReleaseFilter' ) ) {
		$api->setReleaseFilter( 'sahaj_atlas_release_filter' );
	}
}

/**
 * Days a release waits before a site that is not a canary is offered it.
 *
 * Define `SAHAJ_ATLAS_UPDATE_HOLD_DAYS` in wp-config.php, or filter `sahaj_atlas_update_hold_days`.
 * Anything that is not a non-negative number means no hold: a bad value must never strand updates.
 *
 * @return int Seconds.
 */
function sahaj_atlas_update_hold_seconds() {
	$days = defined( 'SAHAJ_ATLAS_UPDATE_HOLD_DAYS' ) ? SAHAJ_ATLAS_UPDATE_HOLD_DAYS : 3;
	$days = apply_filters( 'sahaj_atlas_update_hold_days', $days );

	if ( ! is_numeric( $days ) || $days <= 0 ) {
		return 0;
	}

	return (int) round( $days * DAY_IN_SECONDS );
}

/**
 * Whether this site takes a release the moment it is published.
 *
 * Define `SAHAJ_ATLAS_CANARY` as `true` in wp-config.php. A constant, not a setting: the owner
 * picks the canary once, and a fourth setting on a volunteer's screen is a cost (see AGENTS.md).
 *
 * @return bool
 */
function sahaj_atlas_is_canary() {
	return (bool) apply_filters( 'sahaj_atlas_is_canary', defined( 'SAHAJ_ATLAS_CANARY' ) && SAHAJ_ATLAS_CANARY );
}

/**
 * Whether a published release is offered to this site yet.
 *
 * ⚠ Every doubt answers true. A release whose date cannot be read, or that claims to be from the
 * future, must still be offered: the alternative is a site that never updates again, silently.
 *
 * A release whose notes carry `[skip-hold]` alone on a line is offered at once, for a fix that
 * cannot wait. GitHub's `published_at` is when it went live; `created_at` is the tagged commit's
 * date, so it is not used.
 *
 * @param mixed $release GitHub's release object.
 * @param int   $now     Unix time.
 * @param int   $hold    Seconds a release waits.
 * @param bool  $canary  Whether this site takes releases at once.
 * @return bool
 */
function sahaj_atlas_release_is_offered( $release, $now, $hold, $canary ) {
	if ( $canary || $hold <= 0 || ! is_object( $release ) ) {
		return true;
	}

	if ( isset( $release->body ) && is_string( $release->body )
		&& preg_match( '/^[ \t]*\[skip-hold\][ \t]*\r?$/mi', $release->body ) ) {
		return true;
	}

	$published = isset( $release->published_at ) && is_string( $release->published_at )
		? strtotime( $release->published_at )
		: false;

	if ( false === $published || $published > $now + DAY_IN_SECONDS ) {
		return true;
	}

	return $now - $published >= $hold;
}

/**
 * PUC's release filter. See `sahaj_atlas_configure_release_api()`.
 *
 * @param string $version Release version, without the `v`.
 * @param object $release GitHub's release object.
 * @return bool
 */
function sahaj_atlas_release_filter( $version, $release ) {
	try {
		return sahaj_atlas_release_is_offered( $release, time(), sahaj_atlas_update_hold_seconds(), sahaj_atlas_is_canary() );
	} catch ( \Throwable $e ) {
		return true;
	}
}
