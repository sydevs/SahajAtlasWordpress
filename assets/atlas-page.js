/**
 * Tells the stylesheet how tall the theme's header is.
 *
 * ⚠ CSS alone cannot measure this. The atlas must fill the screen below the site header. No theme
 * states its own header height. Header height varies by theme, by breakpoint, and when a menu
 * wraps to a second line. A flex or grid layout could solve this if the atlas element and the
 * header were sibling elements under this plugin's control. On a block theme, the atlas element
 * sits inside the theme's own `.wp-site-blocks` wrapper instead.
 *
 * This script stays small and has no dependencies. It reads the element's offset, writes it to a
 * custom property, and repeats this on resize. If this file fails to load, `assets/atlas-page.css`
 * falls back to full viewport height. The page still works. The header then scrolls above the map.
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
		 * ⚠ This measures the atlas element's own box, not the header's box. This script does not
		 * know which element is the header. A theme may stack several things above the atlas: an
		 * admin bar, a notice, a breadcrumb strip. Measuring from below the atlas element covers all
		 * of them at once.
		 *
		 * A `position: fixed` header sits out of flow and measures as zero height here. The map then
		 * sits under that header. This case is rare and visible right away. Detecting it would mean
		 * guessing which element is the header — the exact guess this approach avoids.
		 */
		var top = Math.max( 0, Math.round( element.getBoundingClientRect().top + window.scrollY ) )

		// Writing this value on every call would re-trigger the observer below. Changing the
		// element's height also changes the document's height.
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

	// A header can grow later: a lazy-loaded logo, a cookie banner, or a menu that wraps. This
	// moves the atlas down. No resize event fires for this change.
	if ( window.ResizeObserver && document.body ) {
		new window.ResizeObserver( schedule ).observe( document.body )
	}
} )()
