<?php
/**
 * The Beaver Builder module: a third entry point into the shared in-content renderer.
 *
 * ⚠ Loaded only from `sahaj_atlas_load_bb_module()`, behind a `class_exists( 'FLBuilder' )` guard.
 * The class declaration below needs `FLBuilderModule` to exist, so requiring this file on a site
 * without Beaver Builder is a fatal error.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * One more way into `sahaj_atlas_render_embed()`, with no logic of its own.
 */
class Sahaj_Atlas_BB_Module extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'          => __( 'Sahaj Atlas', 'sahaj-atlas' ),
				'description'   => __( 'Shows the map of classes.', 'sahaj-atlas' ),
				'category'      => __( 'Sahaj Atlas', 'sahaj-atlas' ),
				'icon'          => 'location.svg',
				/*
				 * ⚠ The slug is what a saved layout stores in `settings->type`, so
				 * `sahaj_atlas_find_bb_module()` matches this exact string, and changing it orphans
				 * every module a volunteer already placed. It is passed rather than left to
				 * `FLBuilderModule::__construct()`, which would derive it from this file's name.
				 */
				'slug'          => SAHAJ_ATLAS_BB_MODULE,
				/*
				 * ⚠ `dir` and `url` are passed, never left to Beaver Builder. Its fallback rewrites
				 * ABSPATH to `home_url()`, which names the wrong URL whenever WordPress sits in a
				 * subdirectory or the plugin directory is a symlink. `includes/frontend.php` is
				 * loaded through `dir`.
				 */
				'dir'           => SAHAJ_ATLAS_DIR . 'modules/sahaj-atlas/',
				'url'           => SAHAJ_ATLAS_URL . 'modules/sahaj-atlas/',
				/*
				 * ⚠ Never export. On publish, Beaver Builder renders every exportable module into
				 * `post_content` and strips `style="…"` on the way
				 * (`FLBuilder::render_editor_content()`). The exported `<sahaj-atlas>` would lose
				 * `display:block` and its `aspect-ratio`, and an unsized element is the widget's
				 * opt-in to `position: fixed; inset: 0` — a full-viewport overlay the moment Beaver
				 * Builder is deactivated and `post_content` is all that is left. It would also put
				 * a second element on the page beside any shortcode, and the widget refuses the
				 * second one.
				 */
				'editor_export' => false,
			)
		);
	}
}

/*
 * The same three options as `[sahaj_atlas]` and the block, carrying the block editor's own labels
 * and help text word for word (`blocks/embed/editor.js`), so the translation template gains no
 * near-duplicates for a volunteer to tell apart.
 */
FLBuilder::register_module(
	'Sahaj_Atlas_BB_Module',
	array(
		'general' => array(
			'title'    => __( 'Atlas', 'sahaj-atlas' ),
			'sections' => array(
				'general' => array(
					'title'  => '',
					'fields' => array(
						'atlas' => array(
							'type'  => 'text',
							'label' => __( 'Open at (optional)', 'sahaj-atlas' ),
							'help'  => __( 'A country or city such as /gb or /gb/london, one class such as /gb/london/1234, or /gb/london/1234/register for its sign-up form. You can also paste its address from sahajatlas.com.', 'sahaj-atlas' ),
						),
						'map'   => array(
							'type'    => 'select',
							'label'   => __( 'Show the map', 'sahaj-atlas' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Show the map', 'sahaj-atlas' ),
								'0' => __( 'List only', 'sahaj-atlas' ),
							),
							'help'    => __( 'On by default. The map takes the full width, and visitors scroll past it as usual — two fingers move the map. In a column narrower than 360px it shows a button that opens the map instead.', 'sahaj-atlas' ),
						),
						'ratio' => array(
							'type'        => 'text',
							'label'       => __( 'Shape (optional)', 'sahaj-atlas' ),
							'placeholder' => '1:1',
							'help'        => __( 'Width to height, such as 16:9 or 4:3. Empty is a square.', 'sahaj-atlas' ),
						),
					),
				),
			),
		),
	)
);
