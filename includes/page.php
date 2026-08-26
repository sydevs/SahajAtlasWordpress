<?php
/**
 * The Atlas page: creating it, recognising it, and rendering it.
 *
 * The plugin owns exactly one page per site. That is not a simplification of a block-based design,
 * it is the design: the atlas wants the viewport, and four of the nine surveyed client sites build
 * their pages with Elementor, WPBakery or Beaver Builder, where a Gutenberg block never appears.
 * Owning the page means no block to place, no builder to fight, one widget per page by
 * construction, and an unambiguous target for the path router.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** The template slug, used by both the block-theme and classic-theme paths. */
define( 'SAHAJ_ATLAS_TEMPLATE', 'sahaj-atlas-page' );

/**
 * The Atlas page's id, or 0.
 *
 * @return int
 */
function sahaj_atlas_page_id() {
	return (int) get_option( SAHAJ_ATLAS_OPTION_PAGE, 0 );
}

/**
 * Is this request the Atlas page?
 *
 * ⚠ Compares the id, not the template. A volunteer who duplicates the page produces a second post
 * carrying the same template meta; only one of them is *the* Atlas page, and the duplicate must not
 * enqueue a second widget. Diagnostics reports duplicates separately.
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
		 * emits the doctype and calls `wp_head()`, `wp_body_open()` and `wp_footer()` — so omitting
		 * the footer template part is the entire "no footer" requirement, with every hook intact
		 * and not one line of hand-written HTML.
		 *
		 * ⚠ The header part is deliberately absent for now. The map renders `position: fixed;
		 * inset: 0` with no `z-index`, so a site header either disappears under it or floats over
		 * the widget's own controls. SahajAtlasWeb#169 tracks making map mode containable; when it
		 * lands, add the header part back here — a one-line change, which is why the page is built
		 * this way rather than around the limitation.
		 */
		register_block_template(
			'sahaj-atlas//' . SAHAJ_ATLAS_TEMPLATE,
			array(
				'title'       => __( 'Sahaj Atlas (full screen)', 'sahaj-atlas' ),
				'description' => __( 'Fills the window with the atlas. No footer.', 'sahaj-atlas' ),
				'post_types'  => array( 'page' ),
				'content'     => '<!-- wp:html --><!-- The element is printed at wp_body_open. --><!-- /wp:html -->',
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
 * `locate_template()` will not find our file in the theme, so core falls through to `page.php`;
 * this replaces it. Block themes are handled by `register_block_template()` above and fall through
 * here untouched.
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
 * Mark the Atlas page for styling, and for the diagnostics loopback probe to recognise.
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
 * ⚠ **Never on activation.** Two reasons, and the first is a hard rule: activation runs before
 * `init`, and WordPress 6.7 raises `_doing_it_wrong` for a translated string produced that early —
 * so a page with a translated title cannot be created there at all. The second is manners: a plugin
 * that silently creates content on activation leaves the admin something to find and undo.
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
	 * ⚠ **One meta value serves BOTH theme kinds, and it reads like it should not.** A classic theme
	 * needs the `.php` filename, which is what `theme_page_templates` offers; a block theme matches
	 * by template SLUG, which has no suffix. Core reconciles them: `resolve_block_template()` runs
	 * every candidate through `_strip_template_file_suffix()` before comparing, so
	 * `sahaj-atlas-page.php` matches the registered `sahaj-atlas//sahaj-atlas-page`. Verified in the
	 * core source and end to end on both WordPress 6.7 (the fleet's floor) and current — see
	 * `tests/render.mjs`, which asserts the block run renders `wp-site-blocks` with no template parts
	 * rather than the classic file. Storing the suffix-less slug instead would break classic themes.
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
