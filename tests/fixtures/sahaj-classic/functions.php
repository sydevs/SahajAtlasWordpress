<?php
/**
 * A deliberately minimal CLASSIC theme, for the half of the plugin no block theme exercises.
 *
 * ⚠ Not a bundled theme, on purpose. WordPress 6.7 bundles only block themes back to Twenty
 * Twenty-Four, and pulling Twenty Twenty-One from wordpress.org makes the test suite depend on the
 * network and on a third party's future releases. What the plugin's classic path actually needs
 * from a theme is `get_header()`, `wp_head()`, `wp_body_open()` and `wp_footer()` — so the fixture
 * provides exactly those and nothing that could mask a defect.
 *
 * @package SahajAtlas
 */

add_theme_support( 'title-tag' );
