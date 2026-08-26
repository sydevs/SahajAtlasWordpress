/**
 * Tell the stylesheet how much room the theme's header took.
 *
 * ⚠ **This exists because the height cannot be written in CSS.** The atlas should fill the screen
 * below the site header, and no theme tells us how tall its header is — it differs per theme, per
 * breakpoint, and changes when a menu wraps to a second line. A flex or grid layout would express
 * it, but only if the element and the header were siblings we controlled, and on a block theme the
 * element sits inside the theme's own `.wp-site-blocks` wrapper.
 *
 * Deliberately small and dependency-free: read the element's offset, write it as a custom property,
 * repeat on resize. If this file never loads, `assets/atlas-page.css` falls back to the full
 * viewport height — a usable page, with the header scrolled above the map.
 */
( function () {
	var root = document.documentElement
	var last = null
	var queued = false

	function measure() {
		queued = false

		var element = document.querySelector( 'sahaj-atlas' )

		if ( ! element ) {
			return
		}

		/*
		 * ⚠ The element's own box, not the header's — we do not know which element the header is,
		 * and a theme may have several things above the atlas (an admin bar, a notice, a breadcrumb
		 * strip). Measuring from below covers all of them at once.
		 *
		 * A `position: fixed` header is out of flow and measures zero here, so the map would sit
		 * under it. That is rare, it is visible immediately, and detecting it would mean guessing
		 * which element the header is — the thing this approach exists to avoid.
		 */
		var top = Math.max( 0, Math.round( element.getBoundingClientRect().top + window.scrollY ) )

		// Writing unconditionally would re-enter through the observer below, since changing the
		// element's height changes the document's.
		if ( top === last ) {
			return
		}

		last = top
		root.style.setProperty( '--sahaj-atlas-top', top + 'px' )
	}

	function schedule() {
		if ( queued ) {
			return
		}

		queued = true
		window.requestAnimationFrame( measure )
	}

	measure()

	document.addEventListener( 'DOMContentLoaded', measure )
	window.addEventListener( 'load', measure )
	window.addEventListener( 'resize', schedule )

	// A header that grows later — a lazily-loaded logo, a cookie banner, a wrapping menu — moves the
	// atlas down without a resize event.
	if ( window.ResizeObserver && document.body ) {
		new window.ResizeObserver( schedule ).observe( document.body )
	}
} )()
