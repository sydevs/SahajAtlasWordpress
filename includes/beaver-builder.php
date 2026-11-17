<?php
/**
 * Beaver Builder: loading the module, and finding it in a saved layout.
 *
 * Beaver Builder keeps its layout in post meta, not in `post_content`, so neither the block scan
 * nor the shortcode scan in `includes/embed.php` can see a module a volunteer placed. This file is
 * the branch that can.
 *
 * ⚠ Always loaded, on every site. Only the module class is behind a guard — everything here must
 * answer on a site with no Beaver Builder, because `sahaj_atlas_bb_editing()` is asked on every
 * request.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * The module's slug, which is what a saved layout stores in a module node's `settings->type`.
 *
 * ⚠ One spelling, read by the module's registration and by the finder below. A second would be
 * free to disagree, silently: the module registers, the volunteer places it, and the page serves no
 * atlas. Deliberately not `SAHAJ_ATLAS_SLUG`, which holds the same string for an unrelated reason —
 * renaming the plugin folder must not orphan a saved layout.
 */
define( 'SAHAJ_ATLAS_BB_MODULE', 'sahaj-atlas' );

/**
 * Load the module, when Beaver Builder is running.
 *
 * ⚠ On `init`, from `sahaj_atlas_init()` at the default priority 10. Beaver Builder loads its own
 * modules at `init:2`, and anything registering before that is overwritten by the sweep.
 */
function sahaj_atlas_load_bb_module() {
	if ( ! class_exists( 'FLBuilder' ) ) {
		return;
	}

	require_once SAHAJ_ATLAS_DIR . 'modules/sahaj-atlas/sahaj-atlas.php';
}

/**
 * Whether this post's content is a Beaver Builder layout, rather than `post_content`.
 *
 * ⚠ This is what makes the builder branch decisive in `sahaj_atlas_resolve_embed()`, so it answers
 * the enabled question alone — whether a layout then holds a module of ours is a separate answer.
 *
 * @param WP_Post $post The post being rendered.
 * @return bool
 */
function sahaj_atlas_bb_enabled( $post ) {
	return class_exists( 'FLBuilderModel' ) && (bool) FLBuilderModel::is_builder_enabled( $post->ID );
}

/**
 * The embed this post's Beaver Builder layout asks for, or null.
 *
 * @param WP_Post $post The post being rendered.
 * @return array|null
 */
function sahaj_atlas_bb_embed( $post ) {
	if ( ! sahaj_atlas_bb_enabled( $post ) ) {
		return null;
	}

	$nodes = FLBuilderModel::get_layout_data( 'published', $post->ID );

	return is_array( $nodes ) ? sahaj_atlas_find_bb_module( $nodes ) : null;
}

/**
 * The first visible Sahaj Atlas module in a Beaver Builder layout, normalized, or null.
 *
 * Layout data is a flat map of node objects keyed by node id, each naming its `parent`. So this
 * groups them by parent once, then walks from the root down and takes the first match in document
 * order — the order Beaver Builder renders in, and the one the "only one atlas" rule has to agree
 * with.
 *
 * ⚠ Kept free of every `FL*` call, with the visibility test passed in, so `tests/run.php` can walk
 * fixture nodes in a lane where Beaver Builder is not installed — the same lane that proves the
 * guards above are real, which a stub class in its place would make unassertable. Beaver Builder's
 * own `is_node_visible()` is the default, and stays its rule to own rather than one copied here.
 *
 * @param array         $nodes      Layout data: node objects, keyed by node id.
 * @param callable|null $is_visible Whether one node is visible. Defaults to Beaver Builder's rule.
 * @return array|null
 */
function sahaj_atlas_find_bb_module( array $nodes, $is_visible = null ) {
	$children = array();

	foreach ( $nodes as $id => $node ) {
		if ( is_object( $node ) ) {
			$children[ (string) ( isset( $node->parent ) ? $node->parent : '' ) ][ $id ] = $node;
		}
	}

	return sahaj_atlas_first_bb_module(
		$children,
		'',
		null === $is_visible ? array( 'FLBuilderModel', 'is_node_visible' ) : $is_visible
	);
}

/**
 * One parent's children, deepest-first, for `sahaj_atlas_find_bb_module()`.
 *
 * ⚠ A node a visibility rule hides is skipped along with everything under it. Beaver Builder
 * renders neither, so counting one would enqueue `auto.js` for an element the page never prints.
 *
 * @param array    $children   Nodes grouped by their parent's id.
 * @param string   $parent     Whose children to walk. Empty is the layout's root.
 * @param callable $is_visible Whether one node is visible.
 * @return array|null
 */
function sahaj_atlas_first_bb_module( array $children, $parent, $is_visible ) {
	if ( ! isset( $children[ $parent ] ) ) {
		return null;
	}

	// `position` orders siblings, and the stored map's own order is not it.
	foreach ( wp_list_sort( $children[ $parent ], 'position', 'ASC', true ) as $id => $node ) {
		if ( ! call_user_func( $is_visible, $node ) ) {
			continue;
		}

		if ( isset( $node->type, $node->settings->type ) && 'module' === $node->type && SAHAJ_ATLAS_BB_MODULE === $node->settings->type ) {
			return sahaj_atlas_normalize_attrs( (array) $node->settings, 'beaver-builder' );
		}

		// Rows hold modules and column groups, a column group holds columns, and a column holds
		// either again. Descending by parent id follows all of it without naming any of it.
		$found = sahaj_atlas_first_bb_module( $children, (string) $id, $is_visible );

		if ( null !== $found ) {
			return $found;
		}
	}

	return null;
}

/**
 * Whether Beaver Builder is rendering this request into its own editor canvas.
 *
 * The widget cannot run usefully inside one, for the reason `blocks/embed/editor.js` records for
 * the block editor. Both in-content callers ask this directly: a second builder earns a name for
 * the general rule when there is a second term to put under it.
 *
 * ⚠ Two requests, not one. Opening the builder loads the front-end page with `?fl_builder`, which
 * `is_builder_active()` answers for. Dragging a module in re-renders only that module over
 * `admin-ajax.php`, where `is_admin()` is true — so every branch of `is_builder_active()` is
 * skipped and it answers false. Without the second test the volunteer gets "only one atlas can
 * appear on a page" the moment they drop the module, because no embed is resolved for an admin
 * request. `fl_builder_data` is Beaver Builder's own payload key (`FLBuilderModel::get_post_data()`).
 *
 * ⚠ Deliberately not memoized, though `is_builder_active()` caches nothing on a visitor request
 * and this is asked once for the request plus once per in-content embed. A pinned answer is a
 * stale answer for any caller running before `$_POST` is read, and it would make one of the two
 * branches unassertable in a single test run. Two `current_user_can()` walks are the cheaper cost.
 *
 * @return bool
 */
function sahaj_atlas_bb_editing() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence only; nothing is read.
	return ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() )
		|| ( wp_doing_ajax() && isset( $_POST['fl_builder_data'] ) );
}
