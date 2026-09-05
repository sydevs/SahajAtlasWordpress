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
