/**
 * Tells the stylesheet how tall the theme's header is, and how much of it hangs over the atlas.
 *
 * ⚠ CSS alone cannot measure this. The atlas must fill the screen below the site header. No theme
 * states its own header height. Header height varies by theme, by breakpoint, and when a menu
 * wraps to a second line. A flex or grid layout could solve this if the atlas element and the
 * header were sibling elements under this plugin's control. On a block theme, the atlas element
 * sits inside the theme's own `.wp-site-blocks` wrapper instead.
 *
 * This script stays small and has no dependencies. It reads two offsets, writes them to custom
 * properties, and repeats that whenever the page resizes or anything above the atlas grows. If this
 * file fails to load, `assets/atlas-page.css` falls back to full viewport height. The page still
 * works. The header then scrolls above the map.
 */
( function () {
	var root = document.documentElement
	var publishedTop = null
	var publishedOffset = null
	var offset = 0
	var queued = false
	var observing = false

	/**
	 * How far down the page an out-of-flow site header reaches, in document pixels.
	 *
	 * ⚠ This guesses which element is the header, deliberately narrowly: the first `header`,
	 * `#masthead`, `.site-header` or Elementor theme-builder header above the atlas, and only when
	 * that one is out of flow. Elementor needs its own selector: a theme-builder header renders in
	 * a plain `div`, so none of the other three reach it, and a transparent Elementor header is one
	 * of the cases #40 reports.
	 *
	 * Everything else above the atlas — an in-flow header, the admin bar, a notice, a breadcrumb
	 * strip — pushes the element down, so the element's own box already measures it. An
	 * out-of-flow header measures as nothing there, which is what left the map underneath it.
	 *
	 * @param {Element} element The atlas element.
	 * @return {number} The document pixel the header covers down to, or 0 when it covers nothing.
	 */
	function coveredTo( element ) {
		var candidates = document.querySelectorAll( 'header, #masthead, .site-header, [data-elementor-type="header"]' )
		var band = Math.round( window.innerHeight / 2 )
		var candidate
		var position
		var rect
		var bottom
		var i

		for ( i = 0; i < candidates.length; i++ ) {
			candidate = candidates[ i ]

			// A header wrapping the whole page is not a header above the atlas. One the widget
			// printed inside the atlas fails the test below instead, which reports a header that
			// the atlas both precedes and contains.
			if ( candidate.contains( element ) ) {
				continue
			}

			if ( ! ( candidate.compareDocumentPosition( element ) & Node.DOCUMENT_POSITION_FOLLOWING ) ) {
				continue
			}

			rect = candidate.getBoundingClientRect()

			// ⚠ Skipped, not decided from. A responsive theme prints its mobile header first and
			// hides it above its breakpoint, so the first candidate is routinely a box with no
			// height — and deciding from that one leaves the visible header below it unmeasured.
			if ( rect.height === 0 ) {
				continue
			}

			position = window.getComputedStyle( candidate ).position

			// The first header above the atlas decides. A second one is some other theme's idea of
			// a header, and reading it would move the atlas for a strip that never covered it.
			if ( position !== 'fixed' && position !== 'absolute' ) {
				return 0
			}

			// ⚠ A fixed header holds its viewport band at every scroll position, so that band is
			// already its document band. An absolute one scrolls with the page, and `+ scrollY`
			// makes the two comparable — and the answer independent of scroll, which it must be,
			// since nothing re-measures when the page scrolls.
			bottom = Math.round( rect.bottom + ( position === 'fixed' ? 0 : window.scrollY ) )

			/*
			 * ⚠ A band reaching past half the screen is not a header this page can clear. Handing
			 * that much away leaves the atlas too short for the widget to draw a map in, and it
			 * answers with the compact card instead — so a covered but full-height map is the better
			 * failure, and the one the page had before. The same test rejects a
			 * `position: fixed; bottom: 0` toolbar, whose band is the bottom of the screen.
			 */
			if ( bottom > band ) {
				return 0
			}

			return Math.max( 0, bottom )
		}

		return 0
	}

	function measure() {
		queued = false

		var element = document.querySelector( 'sahaj-atlas' )

		if ( ! element ) {
			return
		}

		var top = Math.max( 0, Math.round( element.getBoundingClientRect().top + window.scrollY ) )
		var covered = coveredTo( element )

		// Writing a value that did not change would re-trigger the observer below. The element's
		// margin and its height each change the document's height.
		if ( top !== publishedTop ) {
			publishedTop = top
			root.style.setProperty( '--sahaj-atlas-top', top + 'px' )
		}

		/*
		 * ⚠ Additive, against the element's *current* top, which already carries the offset written
		 * last time. Measuring an in-flow top and adding to that instead double-counts the moment a
		 * parent's own top margin collapses with ours and swallows part of the shift. This form
		 * converges from either side, over the next few frames.
		 *
		 * ⚠ The clamp is what bounds it. A theme whose own CSS beats this margin leaves the element
		 * where it was, and an unclamped sum would then climb by the header's height every frame for
		 * as long as the tab stays open. The offset never needs to exceed the header's bottom edge.
		 */
		offset = Math.max( 0, Math.min( covered, offset + covered - top ) )

		if ( offset !== publishedOffset ) {
			publishedOffset = offset
			root.style.setProperty( '--sahaj-atlas-offset', offset + 'px' )

			/*
			 * ⚠ The additive form above walks to its answer over several frames, and this is what
			 * supplies them. Nothing else does: no resize event fires for a margin this script
			 * wrote, and the body observer below is the one thing on this page that is not
			 * guaranteed to be attached. The clamp is what ends the walk.
			 */
			schedule()
		}
	}

	function schedule() {
		if ( queued ) {
			return
		}

		queued = true
		window.requestAnimationFrame( measure )
	}

	/*
	 * A header can grow later: a lazy-loaded logo, a cookie banner, or a menu that wraps. This moves
	 * the atlas down, and no resize event fires for it.
	 *
	 * ⚠ Attach on the first call that finds a body, not once at load. An optimiser plugin that hoists
	 * this file into `<head>` runs it before `document.body` exists, and a missed attach is silent.
	 */
	function start() {
		measure()

		if ( observing || ! window.ResizeObserver || ! document.body ) {
			return
		}

		observing = true
		new window.ResizeObserver( schedule ).observe( document.body )
	}

	start()

	document.addEventListener( 'DOMContentLoaded', start )
	window.addEventListener( 'load', start )
	window.addEventListener( 'resize', schedule )
} )()
