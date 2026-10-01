/**
 * Checks what `assets/atlas-page.js` decides, given a geometry.
 *
 * The three wp-playground lanes never execute this file: `pnpm lint` parses PHP, `pnpm test` runs
 * PHP, and `pnpm test:render` matches the HTML a server returned. So the one script the plugin
 * ships had no lane at all, and the header-overlap bug (#40) had to be reported from a browser.
 *
 * ⚠ This lane stubs the geometry. It therefore proves what the script *decides* — which header it
 * reads, and the arithmetic it does — and never what a browser *lays out*. The stub's layout rule
 * is one line (`elementTop = base + max(parentMargin, offset)`); a real theme's layout is not.
 * Overlap in a real theme is the browser lane's job (#38). Do not grow this file into a layout
 * model: a second line of layout here is a second thing that can be wrong in our favour.
 *
 *   node tests/measure.mjs
 */

import { readFile } from 'node:fs/promises'
import { createContext, runInContext } from 'node:vm'

const SOURCE = await readFile( new URL( '../assets/atlas-page.js', import.meta.url ), 'utf8' )

let failures = 0

/**
 * @param {string} label
 * @param {boolean} condition
 * @param {string} [detail]
 */
function ok( label, condition, detail = '' ) {
	if ( condition ) {
		console.log( `  ok    ${ label }` )
		return
	}
	failures += 1
	console.log( `  FAIL  ${ label }${ detail ? `\n        ${ detail }` : '' }` )
}

/**
 * Runs the real script against one fake page, and settles it the way a browser would.
 *
 * A header's `top` and `height` are what `getBoundingClientRect()` reports: viewport pixels for a
 * `fixed` header, which is why a scrolled scene leaves them alone, and document pixels for every
 * other kind, which the stub converts. `inside` puts the header within the atlas element — the
 * widget's own markup — and `after` puts it below the atlas in document order.
 *
 * @param {object} scene
 * @param {number} scene.inflowTop Where the flow alone puts the atlas element.
 * @param {number} [scene.parentMargin] A parent top margin that collapses with the element's own.
 * @param {number} [scene.scrollY]
 * @param {Array<{position: string, top: number, height: number, inside?: boolean, after?: boolean}>} [scene.headers]
 * @param {() => void} [mutate] Changes the scene once it has settled, to settle it again.
 */
function run( scene, mutate ) {
	const headers = scene.headers ?? []
	const parentMargin = scene.parentMargin ?? 0
	const scrollY = scene.scrollY ?? 0
	const base = scene.inflowTop - parentMargin
	const props = new Map()
	const frames = []
	const bodyElement = {}
	const listeners = { DOMContentLoaded: [], load: [], resize: [] }
	let observer = null
	// `wp_enqueue_script()` puts the script in `<head>`: it runs before the atlas element and the
	// body element exist, and only `DOMContentLoaded` brings them into being.
	let parsed = false

	const offset = () => Number.parseFloat( props.get( '--sahaj-atlas-offset' ) ?? '0' )
	const elementTop = () => base + Math.max( parentMargin, offset() )

	const element = {
		contains: ( node ) => node.inside === true,
		getBoundingClientRect: () => ( { top: elementTop() - scrollY } ),
	}

	const nodes = headers.map( ( header ) => ( {
		inside: header.inside === true,
		position: header.position,
		contains: () => false,
		// 4 is DOCUMENT_POSITION_FOLLOWING: the atlas element follows this header.
		compareDocumentPosition: () => ( header.after ? 2 : 4 ),
		getBoundingClientRect: () => {
			const shift = header.position === 'fixed' ? 0 : scrollY
			return { top: header.top - shift, bottom: header.top + header.height - shift }
		},
	} ) )

	const context = createContext( {
		Node: { DOCUMENT_POSITION_FOLLOWING: 4, DOCUMENT_POSITION_PRECEDING: 2 },
		document: {
			documentElement: { style: { setProperty: ( name, value ) => props.set( name, value ) } },
			get body() {
				return parsed ? bodyElement : null
			},
			addEventListener: ( name, callback ) => listeners[ name ]?.push( callback ),
			querySelector: ( selector ) => ( parsed && selector === 'sahaj-atlas' ? element : null ),
			querySelectorAll: () => ( parsed ? nodes : [] ),
		},
		window: {
			scrollY,
			getComputedStyle: ( node ) => ( { position: node.position } ),
			requestAnimationFrame: ( callback ) => frames.push( callback ),
			addEventListener: ( name, callback ) => listeners[ name ]?.push( callback ),
			ResizeObserver: class {
				constructor( callback ) {
					observer = callback
				}
				observe() {}
			},
		},
	} )

	// A browser re-measures because a written property changed the layout, which the observer sees.
	// One pass here is one such frame, and the page has settled when a pass writes nothing new.
	function settle() {
		let passes = 0
		for ( ; passes < 12; passes++ ) {
			const before = JSON.stringify( [ ...props ] )
			observer?.()
			while ( frames.length ) {
				frames.shift()()
			}
			if ( JSON.stringify( [ ...props ] ) === before ) {
				return passes
			}
		}
		return passes
	}

	runInContext( SOURCE, context, { filename: 'assets/atlas-page.js' } )

	const headWrites = props.size

	parsed = true
	listeners.DOMContentLoaded.forEach( ( callback ) => callback() )
	listeners.load.forEach( ( callback ) => callback() )

	let passes = settle()

	if ( mutate ) {
		mutate()
		passes += settle()
	}

	return {
		top: props.get( '--sahaj-atlas-top' ),
		offset: props.get( '--sahaj-atlas-offset' ),
		passes,
		headWrites,
		observed: observer !== null,
	}
}

/**
 * @param {string} label
 * @param {object} scene
 * @param {{top: string, offset: string}} expected
 * @param {() => void} [mutate]
 */
function check( label, scene, expected, mutate ) {
	const actual = run( scene, mutate )
	ok(
		label,
		actual.top === expected.top && actual.offset === expected.offset,
		`top ${ actual.top } offset ${ actual.offset }, wanted top ${ expected.top } offset ${ expected.offset }`
	)
	return actual
}

console.log( '\nout-of-flow headers' )

// The browser lane's `fixed-header` cell: header bottom 100, element top 0 (#40).
check(
	'a fixed header pushes the atlas below its bottom edge',
	{ inflowTop: 0, headers: [ { position: 'fixed', top: 0, height: 100 } ] },
	{ top: '100px', offset: '100px' }
)

check(
	'an absolute header does too — a transparent builder header',
	{ inflowTop: 0, headers: [ { position: 'absolute', top: 0, height: 100 } ] },
	{ top: '100px', offset: '100px' }
)

check(
	'and the answer does not depend on the scroll position',
	{ inflowTop: 0, scrollY: 500, headers: [ { position: 'fixed', top: 0, height: 100 } ] },
	{ top: '100px', offset: '100px' }
)

check(
	'a fixed header below the admin bar clears both',
	{ inflowTop: 32, headers: [ { position: 'fixed', top: 32, height: 100 } ] },
	{ top: '132px', offset: '100px' }
)

console.log( '\nheaders that need no offset' )

check(
	'an in-flow header measures as today',
	{ inflowTop: 80, headers: [ { position: 'static', top: 0, height: 80 } ] },
	{ top: '80px', offset: '0px' }
)

check(
	'a sticky header measures as today',
	{ inflowTop: 80, headers: [ { position: 'sticky', top: 0, height: 80 } ] },
	{ top: '80px', offset: '0px' }
)

check( 'a page with no header at all', { inflowTop: 0 }, { top: '0px', offset: '0px' } )

check(
	'a fixed bar pinned to the bottom of the viewport is not a header',
	{ inflowTop: 0, headers: [ { position: 'fixed', top: 660, height: 60 } ] },
	{ top: '0px', offset: '0px' }
)

check(
	"a header inside the atlas element is the widget's own",
	{ inflowTop: 0, headers: [ { position: 'fixed', top: 0, height: 300, inside: true } ] },
	{ top: '0px', offset: '0px' }
)

check(
	'a fixed header below the atlas covers content, not the atlas',
	{ inflowTop: 0, headers: [ { position: 'fixed', top: 0, height: 100, after: true } ] },
	{ top: '0px', offset: '0px' }
)

check(
	'the first header above the atlas decides',
	{
		inflowTop: 80,
		headers: [
			{ position: 'static', top: 0, height: 80 },
			{ position: 'fixed', top: 0, height: 300 },
		],
	},
	{ top: '80px', offset: '0px' }
)

console.log( '\nthe lifecycle' )

// Everything above settles only because the script is re-measured. Both of these were silently
// false before #40: the observer never attached, so one wrapped menu left the map under the header.
const lifecycle = run( { inflowTop: 0, headers: [ { position: 'fixed', top: 0, height: 100 } ] } )
ok( 'nothing is measured while the script is still in the head', lifecycle.headWrites === 0 )
ok( 'the body observer attaches once the body exists', lifecycle.observed )

console.log( '\nsettling' )

// A parent top margin that collapses with ours moves the element by less than the offset we wrote.
// The script has to walk the rest of the way instead of counting the shortfall twice.
const collapsing = check(
	'a parent top margin that swallows part of the shift still settles at the header bottom',
	{ inflowTop: 60, parentMargin: 60, headers: [ { position: 'fixed', top: 0, height: 100 } ] },
	{ top: '100px', offset: '100px' }
)
ok( 'and settles within a few frames', collapsing.passes < 8, `took ${ collapsing.passes } passes` )

// A menu that wrapped to a second line, then unwraps: the offset has to come back down.
const shrinking = { inflowTop: 0, headers: [ { position: 'fixed', top: 0, height: 100 } ] }
check(
	'a header that shrinks pulls the atlas back up',
	shrinking,
	{ top: '60px', offset: '60px' },
	() => {
		shrinking.headers[ 0 ].height = 60
	}
)

console.log( failures ? `\n${ failures } failing\n` : '\nall passed\n' )
process.exit( failures ? 1 : 0 )
