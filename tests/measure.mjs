/**
 * Checks what `assets/atlas-page.js` decides, given a geometry.
 *
 * The three wp-playground lanes never execute this file: `pnpm lint` parses PHP, `pnpm test` runs
 * PHP, and `pnpm test:render` matches the HTML a server returned. So the one script the plugin
 * ships has no lane, which is why a header overlapping the map (#40) had to be reported from a
 * browser.
 *
 * ⚠ The geometry here is stubbed, so this lane proves what the script *decides* — which header it
 * reads, and the arithmetic it does — never what a browser *lays out*. The stub's layout rule is
 * one line; a real theme's layout is not. Keep an assertion about real overlap out of it.
 *
 * ⚠ This is nonetheless the only CI coverage the one shipped script has, so it retires on a
 * condition, not on a ticket: when a browser lane runs in CI and covers the same decisions. #38's
 * lane (PR #44) adds `pnpm test:browser` alone — not to `pnpm test:all`, not to the workflow, and
 * it needs a key and the network — so it does not meet that condition today.
 *
 *   node tests/measure.mjs
 */

import { readFile } from 'node:fs/promises'
import { createContext, runInContext } from 'node:vm'

const SOURCE = await readFile(new URL('../assets/atlas-page.js', import.meta.url), 'utf8')
const EMBED = await readFile(new URL('../includes/embed.php', import.meta.url), 'utf8')

/**
 * Where the plugin enqueues this script today, read from the enqueue itself.
 *
 * ⚠ Read, never written down. A scene that hard-codes a load position keeps passing after the
 * enqueue moves, while modelling a page the plugin no longer serves. Every scene below defaults to
 * this value, and the lifecycle section runs both positions explicitly.
 */
const IN_FOOTER = (() => {
  const match = /wp_enqueue_script\(\s*'sahaj-atlas-page',[^;]*?,\s*(true|false)\s*\)/.exec(EMBED)

  if (!match) {
    console.error("includes/embed.php no longer enqueues 'sahaj-atlas-page' in a shape this lane can read.")
    process.exit(2)
  }

  return match[1] === 'true'
})()

/** A browser settles the offset over frames. Past this many, the script is looping, not settling. */
const FRAME_BUDGET = 12

let failures = 0

/**
 * @param {string} label
 * @param {boolean} condition
 * @param {string} [detail]
 */
function ok(label, condition, detail = '') {
  if (condition) {
    console.log(`  ok    ${label}`)
    return
  }
  failures += 1
  console.log(`  FAIL  ${label}${detail ? `\n        ${detail}` : ''}`)
}

/**
 * Runs the real script against one fake page, and settles it the way a browser would.
 *
 * A header's `top` and `height` are document pixels, except on a `fixed` one, which stays in
 * viewport pixels — so a scrolled scene leaves those alone. `hidden` gives it the empty box a
 * responsive theme's out-of-breakpoint header reports, `inside` puts the header within the atlas
 * element as the widget's own markup, `wraps` puts the atlas inside the header, and `after` puts
 * the header below the atlas in document order.
 *
 * The element's top follows one rule: `base + max(parentMargin, offset)`, where `base` is
 * `inflowTop` less the parent margin. A `parentMargin` therefore models the margin collapsing that
 * swallows part of the shift the script asked for.
 *
 * @param {object} scene
 * @param {number} scene.inflowTop Where the flow alone puts the atlas element.
 * @param {number} [scene.parentMargin] A parent top margin that collapses with the element's own.
 * @param {number} [scene.scrollY]
 * @param {number} [scene.innerHeight] The viewport height. 720 unless a scene cares.
 * @param {boolean} [scene.inFooter] Where the script is loaded. The shipped position unless a scene says.
 * @param {Array<{position: string, top: number, height: number, hidden?: boolean, inside?: boolean, wraps?: boolean, after?: boolean}>} [scene.headers]
 * @param {() => void} [mutate] Changes the scene once it has settled, to settle it again.
 */
function run(scene, mutate) {
  const headers = scene.headers ?? []
  const parentMargin = scene.parentMargin ?? 0
  const scrollY = scene.scrollY ?? 0
  const innerHeight = scene.innerHeight ?? 720
  const base = scene.inflowTop - parentMargin
  const props = new Map()
  const frames = []
  const observers = []
  const listeners = { DOMContentLoaded: [], load: [], resize: [] }
  let writes = 0
  // In `<head>` the script runs before the atlas element and the body element exist, and only
  // `DOMContentLoaded` brings them into being. In the footer both are already there.
  let parsed = scene.inFooter ?? IN_FOOTER

  const offset = () => Number.parseFloat(props.get('--sahaj-atlas-offset') ?? '0')
  const elementTop = () => base + Math.max(parentMargin, offset())

  const element = {
    getBoundingClientRect: () => ({ top: elementTop() - scrollY }),
  }

  const nodes = headers.map((header) => ({
    position: header.position,
    contains: () => header.wraps === true,
    // What a browser answers for "where is the atlas, relative to this header": 4 is FOLLOWING,
    // 2 is PRECEDING, 8 is CONTAINS, 16 is CONTAINED_BY.
    compareDocumentPosition: () => {
      if (header.inside) return 2 | 8
      if (header.wraps) return 4 | 16
      return header.after ? 2 : 4
    },
    getBoundingClientRect: () => {
      if (header.hidden) return { top: 0, bottom: 0, height: 0 }
      const shift = header.position === 'fixed' ? 0 : scrollY
      return { top: header.top - shift, bottom: header.top + header.height - shift, height: header.height }
    },
  }))

  const context = createContext({
    Node: { DOCUMENT_POSITION_FOLLOWING: 4 },
    document: {
      documentElement: {
        style: {
          setProperty: (name, value) => {
            writes += 1
            props.set(name, value)
          },
        },
      },
      get body() {
        return parsed ? {} : null
      },
      addEventListener: (name, callback) => listeners[name]?.push(callback),
      querySelector: (selector) => (parsed && selector === 'sahaj-atlas' ? element : null),
      querySelectorAll: () => (parsed ? nodes : []),
    },
    window: {
      scrollY,
      innerHeight,
      getComputedStyle: (node) => ({ position: node.position }),
      requestAnimationFrame: (callback) => frames.push(callback),
      addEventListener: (name, callback) => listeners[name]?.push(callback),
      ResizeObserver: class {
        constructor(callback) {
          this.callback = callback
        }
        observe() {
          observers.push(this.callback)
        }
      },
    },
  })

  // A browser re-measures on the frames the script asks for, and on anything the body observer
  // sees. One pass here is one such frame, and the page has settled when a pass writes nothing.
  function settle() {
    let passes = 0
    for (; passes < FRAME_BUDGET; passes++) {
      const before = writes
      observers.forEach((callback) => callback())
      // One pass is one frame. A re-measure the script asks for runs on the *next* pass, so a
      // script that never stops asking spends the budget rather than hanging the lane.
      frames.splice(0, frames.length).forEach((callback) => callback())
      if (writes === before) return passes
    }
    return passes
  }

  runInContext(SOURCE, context, { filename: 'assets/atlas-page.js' })

  const headWrites = writes

  parsed = true
  listeners.DOMContentLoaded.forEach((callback) => callback())
  listeners.load.forEach((callback) => callback())

  let passes = settle()

  if (mutate) {
    mutate()
    // A viewport change is what makes a menu wrap or unwrap, so take the resize path here.
    listeners.resize.forEach((callback) => callback())
    passes = settle()
  }

  return {
    top: props.get('--sahaj-atlas-top'),
    offset: props.get('--sahaj-atlas-offset'),
    passes,
    headWrites,
  }
}

/**
 * @param {string} label
 * @param {object} page
 * @param {{top: string, offset: string}} expected
 * @param {() => void} [mutate]
 */
function scene(label, page, expected, mutate) {
  const actual = run(page, mutate)
  ok(
    label,
    actual.top === expected.top && actual.offset === expected.offset,
    `top ${actual.top} offset ${actual.offset}, wanted top ${expected.top} offset ${expected.offset}`
  )
  return actual
}

console.log('\nout-of-flow headers')

// The browser lane's `fixed-header` cell: header bottom 100, element top 0 (#40).
scene(
  'a fixed header pushes the atlas below its bottom edge',
  { inflowTop: 0, headers: [{ position: 'fixed', top: 0, height: 100 }] },
  { top: '100px', offset: '100px' }
)

scene(
  'an absolute header does too — a transparent builder header',
  { inflowTop: 0, headers: [{ position: 'absolute', top: 0, height: 100 }] },
  { top: '100px', offset: '100px' }
)

scene(
  'and the answer does not depend on the scroll position',
  { inflowTop: 0, scrollY: 500, headers: [{ position: 'fixed', top: 0, height: 100 }] },
  { top: '100px', offset: '100px' }
)

scene(
  'a fixed header below the admin bar clears both',
  { inflowTop: 32, headers: [{ position: 'fixed', top: 32, height: 100 }] },
  { top: '132px', offset: '100px' }
)

scene(
  'an absolute header is read in document pixels, so a scrolled page agrees',
  { inflowTop: 0, scrollY: 500, headers: [{ position: 'absolute', top: 0, height: 100 }] },
  { top: '100px', offset: '100px' }
)

scene(
  'a header floating below the top edge is cleared to its own bottom',
  { inflowTop: 0, headers: [{ position: 'fixed', top: 16, height: 80 }] },
  { top: '96px', offset: '96px' }
)

scene(
  "a theme's hidden mobile header does not decide for the visible one below it",
  {
    inflowTop: 0,
    headers: [
      { position: 'fixed', top: 0, height: 0, hidden: true },
      { position: 'fixed', top: 0, height: 100 },
    ],
  },
  { top: '100px', offset: '100px' }
)

console.log('\nheaders that need no offset')

scene(
  'an in-flow header measures as today',
  { inflowTop: 80, headers: [{ position: 'static', top: 0, height: 80 }] },
  { top: '80px', offset: '0px' }
)

scene(
  'a sticky header measures as today',
  { inflowTop: 80, headers: [{ position: 'sticky', top: 0, height: 80 }] },
  { top: '80px', offset: '0px' }
)

scene('a page with no header at all', { inflowTop: 0 }, { top: '0px', offset: '0px' })

scene(
  'a fixed bar pinned to the bottom of the viewport is not a header',
  { inflowTop: 0, headers: [{ position: 'fixed', top: 660, height: 60 }] },
  { top: '0px', offset: '0px' }
)

// Giving half the screen away leaves the atlas too short for the widget to draw a map in, and it
// answers with the compact card. A covered but full-height map is the better failure.
scene(
  'a header deeper than half the screen keeps the full-height map it had',
  { inflowTop: 0, innerHeight: 720, headers: [{ position: 'fixed', top: 0, height: 420 }] },
  { top: '0px', offset: '0px' }
)

scene(
  "a header inside the atlas element is the widget's own",
  { inflowTop: 0, headers: [{ position: 'fixed', top: 0, height: 300, inside: true }] },
  { top: '0px', offset: '0px' }
)

scene(
  'a header wrapping the whole page is not above the atlas',
  { inflowTop: 0, headers: [{ position: 'fixed', top: 0, height: 300, wraps: true }] },
  { top: '0px', offset: '0px' }
)

scene(
  'a fixed header below the atlas covers content, not the atlas',
  { inflowTop: 0, headers: [{ position: 'fixed', top: 0, height: 100, after: true }] },
  { top: '0px', offset: '0px' }
)

scene(
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

console.log('\nthe load position')

// Every scene above ran at the position `includes/embed.php` enqueues. Both are run here, so
// neither is only assumed, and the decision must come out the same from either. Attaching the body
// observer is #41's; this lane asks only that the script needs nothing from it to decide.
for (const inFooter of [false, true]) {
  const where = inFooter ? 'the footer' : 'the head'
  const lane = run({ inflowTop: 0, inFooter, headers: [{ position: 'fixed', top: 0, height: 100 }] })

  ok(
    `loaded in ${where}, the atlas clears the header`,
    lane.top === '100px' && lane.offset === '100px',
    `top ${lane.top} offset ${lane.offset}`
  )
  ok(
    `loaded in ${where}, the parse-time pass writes ${inFooter ? 'the offset' : 'nothing'}`,
    inFooter ? lane.headWrites > 0 : lane.headWrites === 0,
    `${lane.headWrites} write(s) before the document parsed`
  )
}

console.log(`  info  includes/embed.php loads the script in ${IN_FOOTER ? 'the footer' : 'the head'}`)

console.log('\nsettling')

// A parent top margin that collapses with ours moves the element by less than the offset we wrote.
// The script has to walk the rest of the way instead of counting the shortfall twice, and it asks
// for the frames to walk it on.
const collapsing = scene(
  'a parent top margin that swallows part of the shift still settles at the header bottom',
  { inflowTop: 60, parentMargin: 60, headers: [{ position: 'fixed', top: 0, height: 100 }] },
  { top: '100px', offset: '100px' }
)
ok(
  'and settles in a few frames, not the frame budget',
  collapsing.passes < FRAME_BUDGET / 2,
  `took ${collapsing.passes} passes`
)

// A theme whose own CSS beats the margin never moves the element. An unclamped offset would climb
// by the header's height every frame, for as long as the tab stays open.
const immovable = run({
  inflowTop: 0,
  parentMargin: 1e9,
  headers: [{ position: 'fixed', top: 0, height: 100 }],
})
ok(
  'an element the theme pins in place stops the offset at the header bottom',
  immovable.offset === '100px' && immovable.passes < FRAME_BUDGET,
  `offset ${immovable.offset} after ${immovable.passes} passes`
)

// A menu that wrapped to a second line, then unwraps: the offset has to come back down.
const shrinking = { inflowTop: 0, headers: [{ position: 'fixed', top: 0, height: 100 }] }
scene('a header that shrinks pulls the atlas back up', shrinking, { top: '60px', offset: '60px' }, () => {
  shrinking.headers[0].height = 60
})

console.log(`\n${failures} failure(s)`)
process.exit(failures > 0 ? 1 : 0)
