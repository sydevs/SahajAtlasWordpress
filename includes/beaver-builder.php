<?php
/**
 * Beaver Builder: loading the module, finding it in a saved layout, and the editor's placeholder.
 *
 * Beaver Builder keeps its layout in post meta, not in `post_content`, so neither the block scan
 * nor the shortcode scan in `includes/embed.php` can see a module a volunteer placed. This file is
 * the branch that can.
 *
 * ⚠ Always loaded, on every site. Only the module class is behind a guard — everything here must
 * answer on a site with no Beaver Builder, because `sahaj_atlas_render_embed()` asks it on every
 * in-content embed.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * The module's slug, which is what a saved layout stores in a module node's `settings->type`.
 *
 * ⚠ Written once, read by the module's registration and by the finder below. Two spellings of it
 * are free to disagree, and the disagreement is silent: the module registers, the volunteer places
 * it, and the page serves no atlas.
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
 * The embed this post's Beaver Builder layout asks for, or null.
 *
 * @param WP_Post $post The post being rendered.
 * @return array|null
 */
function sahaj_atlas_bb_embed( $post ) {
	if ( ! class_exists( 'FLBuilderModel' ) || ! FLBuilderModel::is_builder_enabled( $post->ID ) ) {
		return null;
	}

	$nodes = FLBuilderModel::get_layout_data( 'published', $post->ID );

	return is_array( $nodes ) ? sahaj_atlas_find_bb_module( $nodes ) : null;
}

/**
 * The first visible Sahaj Atlas module in a Beaver Builder layout, normalized, or null.
 *
 * Layout data is a flat map of node objects keyed by node id, each naming its `parent`, so this
 * walks it from the root down and takes the first match in document order — the same order Beaver
 * Builder renders in, and the one the "only one atlas" rule has to agree with.
 *
 * ⚠ A node a visibility rule hides is skipped along with everything under it. Beaver Builder
 * renders neither, so counting one would enqueue `auto.js` for an element the page never prints.
 *
 * ⚠ Kept free of every `FL*` call, with the visibility test passed in, so `tests/run.php` can walk
 * fixture nodes in a lane where Beaver Builder is not installed. Beaver Builder's own
 * `is_node_visible()` is the default, and is its rule to own, not a rule to copy here.
 *
 * @param array         $nodes      Layout data: node objects, keyed by node id.
 * @param callable|null $is_visible Whether one node is visible. Defaults to Beaver Builder's rule.
 * @param string        $parent     Whose children to walk. Empty is the layout's root.
 * @return array|null
 */
function sahaj_atlas_find_bb_module( array $nodes, $is_visible = null, $parent = '' ) {
	if ( null === $is_visible ) {
		$is_visible = array( 'FLBuilderModel', 'is_node_visible' );
	}

	$children = array();

	foreach ( $nodes as $id => $node ) {
		if ( is_object( $node ) && $parent === (string) ( isset( $node->parent ) ? $node->parent : '' ) ) {
			$children[ $id ] = $node;
		}
	}

	// `FLBuilderModel::order_nodes()`, which is the position difference and nothing else.
	uasort(
		$children,
		function ( $left, $right ) {
			return ( isset( $left->position ) ? (int) $left->position : 0 )
				- ( isset( $right->position ) ? (int) $right->position : 0 );
		}
	);

	foreach ( $children as $id => $node ) {
		if ( ! call_user_func( $is_visible, $node ) ) {
			continue;
		}

		if ( isset( $node->type, $node->settings->type ) && 'module' === $node->type && SAHAJ_ATLAS_BB_MODULE === $node->settings->type ) {
			return sahaj_atlas_normalize_attrs( (array) $node->settings, 'beaver-builder' );
		}

		// Rows hold modules and column groups, a column group holds columns, and a column holds
		// either again. Descending by parent id follows all of it without naming any of it.
		$found = sahaj_atlas_find_bb_module( $nodes, $is_visible, (string) $id );

		if ( null !== $found ) {
			return $found;
		}
	}

	return null;
}

/**
 * Whether Beaver Builder's editor is open on this request.
 *
 * @return bool
 */
function sahaj_atlas_bb_editing() {
	return class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active();
}

/**
 * The static placeholder the builder's canvas shows in place of the widget.
 *
 * ⚠ The same decision the block editor took, for the same reason: the widget cannot run usefully
 * inside an editor canvas, and booting a third-party widget on every editor load spends its
 * network calls and analytics on nobody. The wording is `blocks/embed/editor.js`'s, word for word,
 * so a volunteer sees one placeholder whichever editor they opened.
 *
 * @param array $embed A normalized embed.
 * @return string
 */
function sahaj_atlas_bb_placeholder( $embed ) {
	if ( '' !== $embed['atlas'] ) {
		$instructions = __( 'Opens at: ', 'sahaj-atlas' ) . $embed['atlas'];
	} elseif ( $embed['map'] ) {
		$instructions = __( 'Shows the map of classes.', 'sahaj-atlas' );
	} else {
		$instructions = __( 'Shows the list of classes. Set a place to open a country, a city or one class instead.', 'sahaj-atlas' );
	}

	return '<div class="sahaj-atlas-placeholder" style="padding:2em;text-align:center;border:1px dashed currentColor">'
		. '<strong>' . esc_html__( 'Sahaj Atlas', 'sahaj-atlas' ) . '</strong><br />'
		. esc_html( $instructions ) . '</div>';
}
