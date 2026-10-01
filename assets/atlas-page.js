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
 * properties, and repeats this on resize. If this file fails to load, `assets/atlas-page.css`
 * falls back to full viewport height. The page still works. The header then scrolls above the map.
 */
( function () {
	var root = document.documentElement
	var lastTop = null
	var lastOffset = null
	var offset = 0
	var queued = false
	var observer = null

	/**
	 * How far down the page an out-of-flow site header reaches, in document pixels.
	 *
	 * ⚠ This does guess which element is the header, and the guess is deliberately narrow: the
	 * first `header`, `#masthead` or `.site-header` above the atlas, and only when it is out of
	 * flow. Nothing else needs guessing — an in-flow header, an admin bar, a notice or a
	 * breadcrumb strip all push the atlas element down, so measuring the element's own box covers
	 * them. An out-of-flow header measures as zero height there, which left the map under it.
	 *
	 * @param {Element} element The atlas element.
	 * @param {number} elementTop The element's current top, in document pixels.
	 * @return {number} The document pixel the header covers down to, or 0 when it covers nothing.
	 */
	function coveredTo( element, elementTop ) {
		var candidates = document.querySelectorAll( 'header, #masthead, .site-header' )
		var candidate
		var position
		var rect
		var scroll
		var i

		for ( i = 0; i < candidates.length; i++ ) {
			candidate = candidates[ i ]

			// A header the atlas sits inside, or one the widget printed, is not above the atlas.
			if ( candidate.contains( element ) || element.contains( candidate ) ) {
				continue
			}

			if ( ! ( candidate.compareDocumentPosition( element ) & Node.DOCUMENT_POSITION_FOLLOWING ) ) {
				continue
			}

			position = window.getComputedStyle( candidate ).position

			// The first header above the atlas decides. A second one is some other theme's idea of
			// a header, and reading it would move the atlas for a strip that never covered it.
			if ( position !== 'fixed' && position !== 'absolute' ) {
				return 0
			}

			rect = candidate.getBoundingClientRect()

			// ⚠ A fixed header holds its viewport band at every scroll position, so its viewport
			// rect already is its document band. An absolute one scrolls with the page, and only
			// `+ scrollY` makes the two comparable — and the result independent of scroll, which it
			// has to be, because this runs on resize and nothing re-runs it when the page scrolls.
			scroll = position === 'fixed' ? 0 : window.scrollY

			// A bar pinned below the atlas's top edge covers content, not the atlas's own start.
			// `position: fixed; bottom: 0` toolbars are common, and offsetting for one would leave
			// a map one viewport tall with its top half blank.
			if ( rect.top + scroll > elementTop ) {
				return 0
			}

			return Math.max( 0, Math.round( rect.bottom + scroll ) )
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

		/*
		 * ⚠ Additive, and measured against the element's *current* top, which already carries the
		 * offset written last time. Remembering an in-flow top and adding to that instead
		 * double-counts the moment a parent's own top margin collapses with ours and swallows part
		 * of the shift. This form walks to the right answer from either side: a header that grows
		 * pushes the atlas further down over the next frames, and one that shrinks on a narrow
		 * viewport pulls it back up.
		 */
		offset = Math.max( 0, offset + coveredTo( element, top ) - top )

		// Writing these values on every call would re-trigger the observer below. Changing the
		// element's height or its offset also changes the document's height.
		if ( top === lastTop && offset === lastOffset ) {
			return
		}

		lastTop = top
		lastOffset = offset
		root.style.setProperty( '--sahaj-atlas-top', top + 'px' )
		root.style.setProperty( '--sahaj-atlas-offset', offset + 'px' )
	}

	function schedule() {
		if ( queued ) {
			return
		}

		queued = true
		window.requestAnimationFrame( measure )
	}

	function start() {
		measure()

		/*
		 * A header can grow later: a lazy-loaded logo, a cookie banner, or a menu that wraps. This
		 * moves the atlas down, and no resize event fires for it. Settling the offset above needs
		 * the same frames.
		 *
		 * ⚠ Attached here, not once at load. `wp_enqueue_script()` puts this file in `<head>`, where
		 * `document.body` is still null, so attaching it there skipped the observer silently on
		 * every page view.
		 */
		if ( ! observer && window.ResizeObserver && document.body ) {
			observer = new window.ResizeObserver( schedule )
			observer.observe( document.body )
		}
	}

	start()

	document.addEventListener( 'DOMContentLoaded', start )
	window.addEventListener( 'load', start )
	window.addEventListener( 'resize', schedule )
} )()
