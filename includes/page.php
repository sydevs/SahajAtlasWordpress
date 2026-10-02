<?php
/**
 * The Atlas page: creating it, recognising it, and rendering it.
 *
 * The plugin owns exactly one page per site. This is the design, not a simplified version of a
 * block-based one: the atlas needs the whole viewport, and four of the nine surveyed client sites
 * build pages with Elementor, WPBakery or Beaver Builder, where a Gutenberg block never appears.
 * Owning the page means no block to place, no page builder to fight, one widget per page by
 * design, and one clear target for the path router.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** The template slug, used by both the block-theme and classic-theme paths. */
define( 'SAHAJ_ATLAS_TEMPLATE', 'sahaj-atlas-page' );

/**
 * @return int The Atlas page's id, or 0 when no page exists yet.
 */
function sahaj_atlas_page_id() {
	return (int) get_option( SAHAJ_ATLAS_OPTION_PAGE, 0 );
}

/**
 * Is this request the Atlas page?
 *
 * ⚠ This compares the id, not the template. A volunteer who duplicates the page creates a second
 * post with the same template meta. Only one of them is the real Atlas page, and the duplicate
 * must not enqueue a second widget. Diagnostics reports duplicates on its own.
 *
 * @param int|null $post_id Optional post to test instead of the current one.
 * @return bool
 */
function sahaj_atlas_is_atlas_page( $post_id = null ) {
	$id = sahaj_atlas_page_id();

	if ( 0 === $id ) {
		return false;
	}

	if ( null === $post_id ) {
		if ( ! is_page() ) {
			return false;
		}

		$post_id = get_queried_object_id();
	}

	return (int) $post_id === $id;
}

/**
 * Is the Atlas page present and publicly viewable?
 *
 * @return bool
 */
function sahaj_atlas_page_is_healthy() {
	$id = sahaj_atlas_page_id();

	if ( 0 === $id ) {
		return false;
	}

	$post = get_post( $id );

	return $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status;
}

/**
 * Register the page template with whichever of core's two systems the theme uses.
 *
 * Runs on `init` because `register_block_template()` takes a translated title.
 */
function sahaj_atlas_register_page_template() {
	if ( wp_is_block_theme() && function_exists( 'register_block_template' ) ) {
		/*
		 * Block themes (WP 6.7+). Core renders this through `template-canvas.php`, which already
		 * emits the doctype and calls `wp_head()`, `wp_body_open()` and `wp_footer()`. So listing
		 * the header part, and leaving out the footer part, is the whole page — every hook stays
		 * intact, with no hand-written HTML.
		 *
		 * ⚠ The header part exists because SahajAtlasWeb#170 landed. Before it, the map was
		 * `position: fixed; inset: 0` with no `z-index`. A site header either vanished under it, or
		 * floated over the widget's own controls, so the page shipped with no header instead of a
		 * broken one. `assets/atlas-page.css` now gives the element a height, the opt-in for a
		 * contained map, and that is what makes the header work. Remove that stylesheet, and this
		 * header reverts to being painted over.
		 *
		 * A theme with no `header` part renders nothing for it. That is the pre-#170 page.
		 */
		register_block_template(
			'sahaj-atlas//' . SAHAJ_ATLAS_TEMPLATE,
			array(
				'title'       => __( 'Sahaj Atlas (full screen)', 'sahaj-atlas' ),
				'description' => __( 'The site header, then the atlas. No footer.', 'sahaj-atlas' ),
				'post_types'  => array( 'page' ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:sahaj-atlas/page /-->',
			)
		);

		return;
	}

	add_filter( 'theme_page_templates', 'sahaj_atlas_offer_page_template' );
}

/**
 * Offer the template in the classic page-attributes dropdown.
 *
 * @param array $templates Existing templates.
 * @return array
 */
function sahaj_atlas_offer_page_template( $templates ) {
	$templates[ SAHAJ_ATLAS_TEMPLATE . '.php' ] = __( 'Sahaj Atlas (full screen)', 'sahaj-atlas' );

	return $templates;
}

/**
 * Swap in the plugin's template for the Atlas page on a classic theme.
 *
 * `locate_template()` will not find our file in the theme, so core falls through to `page.php`.
 * This function replaces that. Block themes are handled by `register_block_template()` above, and
 * pass through here untouched.
 *
 * @param string $template The template core resolved.
 * @return string
 */
function sahaj_atlas_template_include( $template ) {
	if ( ! sahaj_atlas_is_atlas_page() ) {
		return $template;
	}

	if ( wp_is_block_theme() && function_exists( 'register_block_template' ) ) {
		return $template;
	}

	return SAHAJ_ATLAS_DIR . 'templates/atlas-page.php';
}

/**
 * Which header variant the classic Atlas page asks the theme for.
 *
 * Some themes print a hero image in `header.php`. The atlas fills the screen below the header, so
 * a hero leaves it too short for the map, and the widget shows a single button instead.
 *
 * ⚠ Ask for the theme's own hero-less variant. Do not hide the hero with CSS: the rest of the
 * header is laid out for its variant. Core falls back to `header.php` when the variant is missing.
 * `get_stylesheet()`, not `get_template()`: a child theme's own `header.php` may carry changes the
 * parent's variant would drop, so a child theme keeps it. Add a theme only after checking its
 * source.
 *
 * ⚠ This lever reaches only the plugin's own classic template, which is the only caller of
 * `get_header()`. A theme that ships no variant answers at `sahaj_atlas_quiet_theme_bands()`
 * instead, which runs on every render path — block themes and a template this plugin did not
 * supply included.
 *
 * @return string|null The header name, or null for the theme's default `header.php`.
 */
function sahaj_atlas_header_name() {
	// Mesmerize's `header-small.php` is `header.php` without the `.header-wrapper` hero, and puts
	// the nav bar in flow. In `header.php` the nav is `position: absolute` over the hero.
	return in_array( get_stylesheet(), array( 'mesmerize', 'mesmerize-pro' ), true ) ? 'small' : null;
}

/**
 * Switch a theme's decorative band off on the Atlas page, through the theme's own switch.
 *
 * A hero image or a page-title strip starves the map, as `sahaj_atlas_header_name()` describes.
 * Only decoration the theme itself lets a page turn off may go. Branding and navigation never do.
 *
 * ⚠ A hook the theme publishes, never a CSS rule of ours over the theme's own: the rest of the
 * header is laid out around whatever the theme decided to print, so hiding one piece of it leaves
 * the others misplaced.
 *
 * ⚠ Not gated on the active theme. A filter nobody applies costs nothing, and a gate is one more
 * list to drift. Every name below is read from that theme's source, and `pnpm test:browser`
 * measures the free editions. A Plus edition is covered only where it kept the filter, and 4 fleet
 * sites run Esotera Plus — confirm it there before relying on it.
 */
function sahaj_atlas_quiet_theme_bands() {
	if ( ! sahaj_atlas_is_atlas_page() ) {
		return;
	}

	/*
	 * Esotera and Fluida — Cryout themes, 5 fleet sites. Two switches, and neither works alone, so
	 * they are registered together: the image, and the box it left behind.
	 *
	 * ⚠ Filter the image away. Do not remove the `cryout_headerimage_hook` action that prints it,
	 * though that is the shorter route: Esotera's `esotera-over-menu` body class, on by default,
	 * lifts the masthead out of flow and recolours its text to read against the image, and the theme
	 * adds that class only when this same filter yields one. Remove the action instead, and the menu
	 * stays light-on-light with nothing behind it.
	 */
	if ( defined( '_CRYOUT_THEME_NAME' ) ) {
		add_filter( _CRYOUT_THEME_NAME . '_header_image_url', '__return_false' );
		add_filter( 'body_class', 'sahaj_atlas_cryout_release_class' );
	}

	// OceanWP's page-title strip. The theme gates it on this filter for its own distraction-free
	// modes, so a page without the strip is a shape OceanWP already ships.
	add_filter( 'ocean_display_page_header', '__return_false' );

	// Seva Lite's page-title strip, breadcrumbs included.
	add_filter( 'seva_lite_page_title', '__return_false' );
}

/**
 * Release the height a Cryout theme reserves for its hero, whether or not a hero prints.
 *
 * ⚠ Dropping the image buys nothing on its own: Esotera's default `esotera-cropped-headerimage`
 * gives `#header-image-main-inside` a definite 550px. This class is the theme's own release for
 * that box — its stylesheet carries the rule, and its PHP never writes the class — so switching it
 * on is the theme's mechanism, not a rule of ours.
 *
 * ⚠ Registered by `sahaj_atlas_quiet_theme_bands()`, never from file scope. That is what keeps the
 * two halves from disagreeing: the class is added exactly where the image was dropped.
 *
 * @param array $classes Body classes.
 * @return array
 */
function sahaj_atlas_cryout_release_class( $classes ) {
	$classes[] = _CRYOUT_THEME_NAME . '-metahide-headerimg';

	return $classes;
}

/**
 * Mark the Atlas page, for `assets/atlas-page.css` and for the loopback probe.
 *
 * ⚠ Gated on the page, not on the template, and that is what makes the sizing survive a template
 * this plugin did not supply (#39). `sahaj_atlas_read_page()` reads the class back to tell an Atlas
 * page that rendered without its element from an address something else answered entirely.
 *
 * @param array $classes Body classes.
 * @return array
 */
function sahaj_atlas_body_class( $classes ) {
	if ( sahaj_atlas_is_atlas_page() ) {
		$classes[] = 'sahaj-atlas-page';
	}

	return $classes;
}

/**
 * Create the Atlas page, from the settings screen's button.
 *
 * ⚠ Never create this page on activation. Two reasons apply. First, a hard rule: activation runs
 * before `init`, and WordPress 6.7 raises `_doing_it_wrong` for a translated string produced that
 * early, so a page with a translated title cannot be created there at all. Second, manners: a
 * plugin that silently creates content on activation leaves the admin something to find and undo.
 *
 * @return int|WP_Error The page id.
 */
function sahaj_atlas_create_page() {
	if ( sahaj_atlas_page_is_healthy() ) {
		return sahaj_atlas_page_id();
	}

	$id = wp_insert_post(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => __( 'Find a meditation class', 'sahaj-atlas' ),
			'post_name'      => sanitize_title( __( 'find-a-class', 'sahaj-atlas' ) ),
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	/*
	 * ⚠ One meta value serves both theme kinds, though it reads like it should not. A classic theme
	 * needs the `.php` filename, which is what `theme_page_templates` offers. A block theme matches
	 * by template slug, which has no suffix. Core reconciles the two: `resolve_block_template()`
	 * runs every candidate through `_strip_template_file_suffix()` before comparing, so
	 * `sahaj-atlas-page.php` matches the registered `sahaj-atlas//sahaj-atlas-page`. This is
	 * verified in the core source, and end to end on both WordPress 6.7 (the fleet's floor) and the
	 * current version — see `tests/render.mjs`, which asserts the block run renders `wp-site-blocks`
	 * with no template parts, not the classic file. Storing the suffix-less slug instead would break
	 * classic themes. Do not branch to "fix" this.
	 */
	update_post_meta( $id, '_wp_page_template', SAHAJ_ATLAS_TEMPLATE . '.php' );
	update_option( SAHAJ_ATLAS_OPTION_PAGE, (int) $id );

	return (int) $id;
}

/**
 * Pages carrying our template that are not the Atlas page.
 *
 * A duplicated page renders the template and would enqueue a second widget on its own URL. That is
 * not fatal — each page has one widget — but it is two atlases on one site fighting for the same
 * canonical, so the settings screen reports them.
 *
 * @return int[]
 */
function sahaj_atlas_stray_pages() {
	$found = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts'      => 20,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'   => '_wp_page_template',
					'value' => SAHAJ_ATLAS_TEMPLATE . '.php',
				),
			),
		)
	);

	return array_values( array_diff( array_map( 'intval', $found ), array( sahaj_atlas_page_id() ) ) );
}
