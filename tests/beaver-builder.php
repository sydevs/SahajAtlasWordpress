<?php
/**
 * The Beaver Builder module: finding it in a layout, and staying silent without the builder.
 *
 * ⚠ Beaver Builder is not installed in this lane, and installing it would only test it. What is
 * ours is the walk over its layout data — which node wins, and which are skipped — so
 * `sahaj_atlas_find_bb_module()` takes the visibility test as an argument and these fixtures pass
 * their own.
 *
 * ⚠ The fixtures below assume layout data is a flat array of node objects keyed by node id, each
 * naming its `parent`, its `position`, and — for a module — its module slug in `settings->type`.
 * Verified against Beaver Builder Lite 2.11.0.6: `FLBuilderModel::get_layout_data()` returns
 * `_fl_builder_data` unchanged in shape, `FLBuilderModel::get_nodes()` reads exactly those three
 * properties, `FLBuilder::render_module_editor_content()` reads `settings->type`, and the
 * row → column-group → column → module nesting is `FLBuilder::render_editor_content()`'s own walk.
 *
 * @package SahajAtlas
 */

/**
 * One layout node.
 *
 * @param string $type     Node type: `row`, `column-group`, `column` or `module`.
 * @param string $parent   The parent node's id, or an empty string at the layout's root.
 * @param int    $position Sort order among its siblings.
 * @param array  $settings Node settings. A module names its slug in `type`.
 * @return object
 */
function sahaj_bb_node( $type, $parent, $position = 0, $settings = array() ) {
	return (object) array(
		'type'     => $type,
		'parent'   => $parent,
		'position' => $position,
		'settings' => (object) $settings,
	);
}

/**
 * One module node of ours.
 *
 * ⚠ The slug is spelled here as a literal, not as `SAHAJ_ATLAS_BB_MODULE`, and that is the point.
 * This is the string already sitting in every layout a volunteer has saved, so the fixture pins the
 * stored format: change the constant's value and these assertions fail, which is the orphaning the
 * constant's own note warns about. Reading the constant here would silently follow it instead.
 *
 * @param string $parent   The parent node's id.
 * @param int    $position Sort order among its siblings.
 * @param array  $settings Extra settings, over the module's slug.
 * @return object
 */
function sahaj_bb_module( $parent, $position = 0, $settings = array() ) {
	return sahaj_bb_node( 'module', $parent, $position, array_merge( array( 'type' => 'sahaj-atlas' ), $settings ) );
}

/**
 * `sahaj_atlas_find_bb_module()` with this lane's visibility test supplied.
 *
 * @param array $nodes Layout data.
 * @return array|null
 */
function sahaj_bb_find( $nodes ) {
	return sahaj_atlas_find_bb_module( $nodes, 'sahaj_bb_visible' );
}

/**
 * A fresh row → column-group → column → module layout, the deepest nesting the builder offers.
 *
 * ⚠ Built per call, never copied from a shared fixture. Copying the array copies the handles and
 * not the nodes, so hiding a node in one copy hides it in every other — which is a fixture that
 * passes whatever the walk does.
 *
 * @param array $extra Extra settings per level, keyed `row`, `column` or `module`.
 * @return array
 */
function sahaj_bb_nested_layout( $extra = array() ) {
	$at = function ( $level ) use ( $extra ) {
		return isset( $extra[ $level ] ) ? $extra[ $level ] : array();
	};

	return array(
		'row'    => sahaj_bb_node( 'row', '', 0, $at( 'row' ) ),
		'group'  => sahaj_bb_node( 'column-group', 'row' ),
		'column' => sahaj_bb_node( 'column', 'group', 0, $at( 'column' ) ),
		'module' => sahaj_bb_module( 'column', 0, $at( 'module' ) ),
	);
}

/**
 * The visibility rule, in the only shape this walk depends on.
 *
 * `FLBuilderModel::node_has_visibility_rules()` is this test: a node carries a rule when
 * `visibility_display` is set and not empty. Whether that rule then hides the node is Beaver
 * Builder's to decide, and `sahaj_atlas_find_bb_module()` asks rather than deciding.
 *
 * @param object $node A layout node.
 * @return bool
 */
function sahaj_bb_visible( $node ) {
	return ! isset( $node->settings->visibility_display ) || '' === $node->settings->visibility_display;
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A Beaver Builder module is found wherever the builder nests it' );

$sahaj_bb_found = sahaj_bb_find( sahaj_bb_nested_layout( array( 'module' => array( 'atlas' => '/gb/london' ) ) ) );

sahaj_is( 'row → column-group → column → module is found', 'beaver-builder', $sahaj_bb_found['source'] );
sahaj_is( 'and carries the module\'s route', '/gb/london', $sahaj_bb_found['atlas'] );

sahaj_is(
	'a layout with no module of ours is no embed',
	null,
	sahaj_bb_find(
		array(
			'row'    => sahaj_bb_node( 'row', '' ),
			'column' => sahaj_bb_node( 'column', 'row' ),
			'module' => sahaj_bb_node( 'module', 'column', 0, array( 'type' => 'rich-text' ) ),
		)
	)
);

sahaj_is( 'and neither is an empty layout', null, sahaj_bb_find( array() ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The first module in the layout wins, by position and not by storage order' );

/*
 * ⚠ The keys are deliberately out of document order. Layout data is a map, and the order a node
 * was written into it is not the order Beaver Builder renders it — `position` is. The widget
 * refuses a second element, so "first" has to mean the one the visitor sees first.
 */
$sahaj_bb_two = array(
	'second' => sahaj_bb_module( 'row', 1, array( 'atlas' => '/fr' ) ),
	'first'  => sahaj_bb_module( 'row', 0, array( 'atlas' => '/gb' ) ),
	'row'    => sahaj_bb_node( 'row', '' ),
);

sahaj_is( 'the earlier of two modules wins', '/gb', sahaj_bb_find( $sahaj_bb_two )['atlas'] );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A hidden module is not an embed' );

/*
 * ⚠ A visibility rule means Beaver Builder prints no element for that node. Counting it anyway
 * would enqueue `auto.js`, and the widget would then find nothing to mount and report it.
 */
sahaj_is(
	'a module a visibility rule hides is skipped',
	null,
	sahaj_bb_find( sahaj_bb_nested_layout( array( 'module' => array( 'visibility_display' => 'logged_in' ) ) ) )
);

sahaj_is(
	'and so is a visible module inside a hidden column',
	null,
	sahaj_bb_find( sahaj_bb_nested_layout( array( 'column' => array( 'visibility_display' => 'logged_out' ) ) ) )
);

sahaj_is(
	'a hidden row takes its whole subtree with it',
	null,
	sahaj_bb_find( sahaj_bb_nested_layout( array( 'row' => array( 'visibility_display' => 'logged_out' ) ) ) )
);

/*
 * ⚠ The hidden node must not take a later sibling down with it. An early `return null` inside the
 * loop, instead of `continue`, passes every assertion above and fails only this one.
 */
$sahaj_bb_after_hidden = array(
	'row'    => sahaj_bb_node( 'row', '' ),
	'hidden' => sahaj_bb_module( 'row', 0, array( 'atlas' => '/fr', 'visibility_display' => 'logged_in' ) ),
	'shown'  => sahaj_bb_module( 'row', 1, array( 'atlas' => '/gb' ) ),
);

sahaj_is( 'the module after a hidden one still wins', '/gb', sahaj_bb_find( $sahaj_bb_after_hidden )['atlas'] );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A module\'s settings behave exactly as the shortcode\'s attributes do' );

/*
 * All three settings, reaching the normalizer through a module node — settings arrive as an object
 * there, and a select writes `'0'` where a shortcode writes `"false"`. The sanitizers themselves
 * are `tests/run.php`'s; what is asserted here is that a module's settings reach them.
 */
$sahaj_bb_settings = sahaj_bb_find(
	array(
		'module' => sahaj_bb_module(
			'',
			0,
			array(
				'atlas' => 'https://sahajatlas.com/gb/london',
				'map'   => '0',
				'ratio' => 'wide',
			)
		),
	)
);

sahaj_is( 'a pasted sahajatlas.com address becomes a route', '/gb/london', $sahaj_bb_settings['atlas'] );
sahaj_ok( 'the select\'s "0" asks for the list alone', false === $sahaj_bb_settings['map'] );
sahaj_is( 'and an invalid shape falls back to a square', '1/1', $sahaj_bb_settings['ratio'] );

sahaj_ok(
	'a module with no map setting shows the map',
	true === sahaj_bb_find( array( 'module' => sahaj_bb_module( '' ) ) )['map']
);

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Without Beaver Builder the plugin loads none of it' );

sahaj_ok( 'the builder is absent in this lane', ! class_exists( 'FLBuilder' ) && ! class_exists( 'FLBuilderModel' ) );

sahaj_atlas_load_bb_module();

sahaj_ok( 'loading the module is a no-op, and declares no class', ! class_exists( 'Sahaj_Atlas_BB_Module' ) );
sahaj_ok( 'no builder is editing', false === sahaj_atlas_bb_editing() );

/*
 * ⚠ The builder re-renders a dragged module over `admin-ajax.php`, where `is_admin()` is true and
 * every branch of `FLBuilderModel::is_builder_active()` is skipped. Its own payload key is the only
 * signal left, and without it the volunteer gets the "only one atlas" notice as they drop the
 * module. No Beaver Builder is installed here, so this asserts the half that answers without it.
 */
add_filter( 'wp_doing_ajax', '__return_true' );
$_POST['fl_builder_data'] = array( 'action' => 'render_new_module' );

sahaj_ok( 'a Beaver Builder ajax render is an editor canvas', true === sahaj_atlas_bb_editing() );

unset( $_POST['fl_builder_data'] );

sahaj_ok( 'and another ajax request is not', false === sahaj_atlas_bb_editing() );

remove_filter( 'wp_doing_ajax', '__return_true' );

$sahaj_bb_post = get_post( sahaj_atlas_page_id() );

sahaj_ok( 'no post is built with the builder', false === sahaj_atlas_bb_enabled( $sahaj_bb_post ) );
sahaj_is( 'and no post has a builder layout', null, sahaj_atlas_bb_embed( $sahaj_bb_post ) );

/*
 * ⚠ That predicate is what holds every site without Beaver Builder on the block and shortcode
 * scans, because `sahaj_atlas_resolve_embed()` returns the builder's answer — null included — the
 * moment it is true. A `class_exists` dropped from it would take `post_content` out of the resolve
 * on every site at once, and the lane has no builder to put back.
 */

// ---------------------------------------------------------------------------------------------

sahaj_group( 'An editor canvas shows the block editor\'s placeholder, not the widget' );

$sahaj_bb_map_placeholder = sahaj_atlas_editor_placeholder( sahaj_atlas_normalize_attrs( array(), 'beaver-builder' ) );

sahaj_ok( 'the placeholder names the plugin', false !== strpos( $sahaj_bb_map_placeholder, 'Sahaj Atlas' ) );
sahaj_ok( 'a map embed says so', false !== strpos( $sahaj_bb_map_placeholder, 'Shows the map of classes.' ) );
sahaj_ok( 'and prints no element for the widget to mount', false === strpos( $sahaj_bb_map_placeholder, '<sahaj-atlas' ) );

sahaj_ok(
	'a list embed says so instead',
	false !== strpos(
		sahaj_atlas_editor_placeholder( sahaj_atlas_normalize_attrs( array( 'map' => 'false' ), 'beaver-builder' ) ),
		'Shows the list of classes.'
	)
);

/*
 * ⚠ A route is not character-restricted. `sahaj_atlas_clean_route()` enforces a leading slash and
 * refuses `//` and `/\\`, and passes quotes and angle brackets through untouched — they are
 * harmless on the script URL core escapes, and the widget's own problem beyond that. So the
 * placeholder is what has to escape the route, and it is the one value here a volunteer types.
 */
sahaj_ok(
	'a route is escaped into the placeholder',
	false === strpos(
		sahaj_atlas_editor_placeholder( array( 'map' => true, 'atlas' => '/gb"><script>x</script>', 'ratio' => '1/1', 'source' => 'beaver-builder' ) ),
		'<script>'
	)
);
