<?php
/**
 * The module's front-end output. It shares `sahaj_atlas_render_embed()` with the shortcode and the
 * block, and adds nothing of its own.
 *
 * ⚠ This markup does NOT pass through `wp_kses`, and must not. `safecss_filter_attr()`'s property
 * allowlist has no `display`, so it silently reduces `display:block;…;aspect-ratio:1/1` to the rest
 * and leaves an unsized element — which the widget reads as its full-window opt-in.
 * `blocks/embed/render.php` carries the whole story.
 *
 * @package SahajAtlas
 *
 * @var object $settings The module's settings, merged with the form defaults by Beaver Builder.
 */

defined( 'ABSPATH' ) || exit;

echo sahaj_atlas_render_embed( sahaj_atlas_normalize_attrs( (array) $settings, 'beaver-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see the note above.
