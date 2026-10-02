/**
 * The browser lane: the real widget, in real themes, in a real browser.
 *
 * The render lane reads HTML. It cannot see what a theme's CSS, the sizing script, or the widget
 * do to that HTML once a browser runs it. Every defect found live on shrimataji.org (#36,
 * SahajAtlasWeb#235, SahajAtlasWeb#236) was of that kind. This lane boots one WordPress per cell
 * with a theme installed from wordpress.org, loads the production widget in Chromium, and
 * measures.
 *
 *   SAHAJ_ATLAS_TEST_KEY=… pnpm test:browser [--only astra,mesmerize] [--list]
 *
 * ⚠ This lane needs the network, unlike the other three: wordpress.org for the theme zip, and
 * sahajatlas.com plus cloud.sydevelopers.com for the widget and its reads. PHP still makes no
 * outbound call. The same `tests/no-network.php` mu-plugin refuses it, and the last assertion of
 * every cell proves it.
 *
 * ⚠ The key is a published `sahaj-atlas-client` key for the test client in SahajCloud. It reaches
 * only the browser: PHP never sends it, because PHP is offline. The lane stubs `clients/me` (so the
 * record carries no brand colours, the harsher case, and names this server as the canonical embed)
 * and `clients/report` (so a test run records no embed). Every other read is real.
 *
 * ⚠ Chromium needs software GL flags, or Mapbox refuses to mount. See the launch at the end.
 */

import { spawn } from 'node:child_process'
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'

import { chromium } from 'playwright-core'

const NETWORK_LOG = '.test-network.txt'
const SCREENSHOTS = 'tests/screenshots'
const PAGE = '/find-a-class/'
const BASE_PORT = 8830

/**
 * The sentence the widget logs when it chooses the compact card (`docs/embedding.md`, "Entering the
 * compact card logs a console warning"). The lane reads the widget's decision from it instead of
 * recomputing the widget's floors, which are the widget's to change.
 */
const COMPACT = 'showing a compact card'

/**
 * `compactAllowed`: a landscape phone is 390px tall, under the widget's 420px floor, so the card is
 * a legitimate answer there. The lane then exercises the card's overlay instead of failing.
 */
const VIEWPORTS = [
  { name: 'laptop', width: 1280, height: 720 },
  { name: 'small-laptop', width: 1366, height: 650 },
  { name: 'phone', width: 390, height: 844 },
  { name: 'phone-landscape', width: 844, height: 390, compactAllowed: true },
]

/**
 * Where the widget comes from. Production, by decision: the lane tests what sites get. A local
 * build could be served instead by routing `https://sahajatlas.com/**` to `../SahajAtlasWeb/dist`,
 * once that build points at the production API.
 */
const WIDGET_ORIGIN = 'https://sahajatlas.com'

// ── The cells ────────────────────────────────────────────────────────────────────────────────

/**
 * One mu-plugin per hostile condition. Each emulates something a real site does to the page, so
 * the lane records what the widget does under it. A cell whose outcome is a known exposure carries
 * `known`, and its failures are reported but do not fail the run.
 */
const MU = {
  /** Autoptimize "inline and defer CSS", LiteSpeed "load CSS asynchronously". */
  asyncCss: `<?php
add_filter( 'style_loader_tag', function ( $tag, $handle ) {
	return 'sahaj-atlas-page' === $handle ? str_replace( "media='all'", "media='print' onload=\\"this.media='all'\\"", $tag ) : $tag;
}, 10, 2 );`,
  /** A theme whose header is fixed over the page. */
  fixedHeader: `<?php
add_action( 'wp_head', function () {
	echo '<style>header:first-of-type,#masthead,#page-top{position:fixed!important;top:0;left:0;right:0;z-index:100;background:#fff}</style>';
} );`,
  /** The 62.5% root font size many themes set so 1rem is 10px. */
  rootFont: `<?php
add_action( 'wp_head', function () {
	echo '<style>html{font-size:62.5%}</style>';
} );`,
  /** A content wrapper that forms a stacking context, under a header that outranks it. */
  zIndexWrapper: `<?php
add_action( 'wp_head', function () {
	echo '<style>body>div{position:relative;z-index:1} header:first-of-type,#masthead{position:sticky;top:0;z-index:100;background:#fff}</style>';
} );`,
  /** An "HTML5 cleanup" snippet, or an optimizer, that drops the module type. */
  stripModule: `<?php
add_filter( 'wp_script_attributes', function ( $attributes ) {
	if ( isset( $attributes['id'] ) && 'sahaj-atlas-js-module' === $attributes['id'] ) {
		unset( $attributes['type'] );
	}
	return $attributes;
} );`,
}

/**
 * A host page for the in-content compact card: a map embed in a 300px column, under the width
 * floor, so the widget shows the card and its button opens the overlay (SahajAtlasWeb#235).
 */
const SIDEBAR_PAGE = `wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Sidebar host','post_name'=>'sidebar-host','post_content'=>'<div style="width:300px">[sahaj_atlas map="true"]</div>'));`

/**
 * Hand the Atlas page to Elementor's "Elementor Full Width" template — header, content, footer, no
 * theme page title. Its slug is `elementor_header_footer`; `elementor_canvas` is the other one, and
 * drops the header and footer this cell exists to keep (#39).
 */
const ELEMENTOR_FULL_WIDTH = `update_post_meta(sahaj_atlas_page_id(), '_wp_page_template', 'elementor_header_footer');`

/**
 * Seed the `clients/me` answer a `panel` cell needs.
 *
 * ⚠ Only the status panel reads this record, and PHP is offline in this lane — an unseeded panel
 * load makes an unstubbed request that `tests/no-network.php` records, which fails every cell's
 * SahajCloud invariant. The slot comes from `sahaj_atlas_client_slot()` rather than a copy of its
 * key rule, so a change there cannot leave this seeding a slot nobody reads.
 */
const PANEL_SEED = `set_transient(sahaj_atlas_client_slot(sahaj_atlas_api_key()), array('name'=>'Browser lane','allowedDomains'=>'','canonical'=>array('enabled'=>true,'embed'=>sahaj_atlas_mount_key())), 3600);`

/**
 * @typedef {object} Cell
 * @property {string} name
 * @property {string} [theme]        wordpress.org slug, installed and activated
 * @property {string[]} [plugins]    wordpress.org slugs, installed and activated
 * @property {string} [wp]           WordPress version, when the fleet floor is too old
 * @property {Record<string,string>} [mu]  mu-plugins to write, name → PHP
 * @property {boolean} [sidebar]     also check the in-content compact card
 * @property {boolean} [login]       load the page as admin, with the admin bar
 * @property {string} [seed]         extra PHP, appended to the shared seed
 * @property {'template'|'content'} [render]  which print must render the element; `template` default
 * @property {boolean} [panel]       also read the status panel, as the volunteer sees it
 * @property {string} [band]         a selector for the band the Atlas page switches off, which must
 *                                   match nothing there while a header still does
 * @property {string} [known]        why this cell is expected to fail today
 */

/** @type {Cell[]} */
const CELLS = [
  // Every free theme the fleet runs (#37's scan), plus the two bundled block themes.
  { name: 'astra', theme: 'astra', sidebar: true },
  { name: 'mesmerize', theme: 'mesmerize' },
  { name: 'popularfx', theme: 'popularfx' },
  // The four themes whose band `sahaj_atlas_quiet_theme_bands()` switches off (#37). Each selector
  // is the band's own markup, read from that theme's source — never the box it sits in, which the
  // theme prints either way.
  { name: 'oceanwp', theme: 'oceanwp', band: 'header.page-header' },
  { name: 'esotera', theme: 'esotera', band: '#header-image-main .header-image' },
  { name: 'fluida', theme: 'fluida', band: '#header-image-main .header-image' },
  { name: 'seva-lite', theme: 'seva-lite', band: 'header.page-header' },
  { name: 'enigma', theme: 'enigma' },
  { name: 'twentytwenty', theme: 'twentytwenty' },
  { name: 'twentytwentyfour', theme: 'twentytwentyfour' },
  { name: 'twentytwentyfive', theme: 'twentytwentyfive' },
  // Page builders, on the WordPress they require.
  { name: 'elementor', theme: 'astra', plugins: ['elementor'], wp: '6.8', sidebar: true },
  // The builder renders the Atlas page with its own template, so the element comes from the content
  // area and the theme's footer stays below it (#39).
  { name: 'elementor-full-width', theme: 'astra', plugins: ['elementor'], wp: '6.8', seed: ELEMENTOR_FULL_WIDTH, render: 'content', panel: true },
  { name: 'beaver-builder', theme: 'astra', plugins: ['beaver-builder-lite-version'], wp: '6.8' },
  // Hostile conditions, each on a theme that passes clean.
  { name: 'async-css', theme: 'astra', mu: { 'async-css': MU.asyncCss } },
  { name: 'fixed-header', theme: 'twentytwenty', mu: { 'fixed-header': MU.fixedHeader } },
  { name: 'root-font', theme: 'astra', mu: { 'root-font': MU.rootFont } },
  { name: 'zindex-wrapper', theme: 'astra', mu: { 'zindex-wrapper': MU.zIndexWrapper }, sidebar: true },
  { name: 'admin-bar', theme: 'astra', login: true },
  // The page is still broken here — only diagnostics now says so, which is the whole fix (#39).
  { name: 'strip-module', theme: 'astra', mu: { 'strip-module': MU.stripModule }, panel: true, known: 'a classic script cannot run the loader; the status panel reports it, the page stays blank (#39)' },
]

// ── The harness ──────────────────────────────────────────────────────────────────────────────

const args = process.argv.slice(2)
const only = args.includes('--only') ? (args[args.indexOf('--only') + 1] ?? '').split(',').filter(Boolean) : null
const unknown = only?.filter((name) => !CELLS.some((cell) => cell.name === name)) ?? []

if (only && (only.length === 0 || unknown.length)) {
  console.error(`--only names no cell${unknown.length ? `: ${unknown.join(', ')}` : ''}. Run with --list to see them.`)
  process.exit(2)
}

const cells = only ? CELLS.filter((c) => only.includes(c.name)) : CELLS

if (args.includes('--list')) {
  for (const cell of CELLS) console.log(`${cell.name.padEnd(18)} ${cell.theme ?? ''} ${cell.plugins?.join(',') ?? ''} ${cell.known ? '(known failure)' : ''}`)
  process.exit(0)
}

const key = process.env.SAHAJ_ATLAS_TEST_KEY ?? (await readDotEnv('SAHAJ_ATLAS_TEST_KEY'))

if (!key) {
  console.error('SAHAJ_ATLAS_TEST_KEY is not set: export it, or put it in .env.claude.local.')
  process.exit(2)
}

let failures = 0
let widgetFindings = 0
let knownFailures = 0

/**
 * Three kinds of assertion.
 * - `ok` is an invariant: the page is served, PHP never reaches SahajCloud, no error panel. It
 *   fails the run in every cell, `known` or not.
 * - `fit` judges how the plugin's page fits the theme: boot, the card or the interface, the slot,
 *   the header, which print rendered the element, and what the status panel says about it. A cell
 *   marked `known` reports these as KNOWN, because its exposure is ticketed.
 * - `widget` judges the production widget: host CSS reaching in, portals, its overlay. It is a
 *   SahajAtlasWeb ticket, never a red plugin lane, so it is counted and printed only.
 *
 * @typedef {(label: string, condition: boolean, detail?: string) => void} Check
 * @type {{ok: Check, fit: Check, widget: Check}}
 */
let scope = { ok: () => {}, fit: () => {}, widget: () => {} }

/**
 * @param {string} name The file next to this one whose `SAHAJ_ATLAS_TEST_KEY=` line holds the key.
 */
async function readDotEnv(name) {
  const text = await readFile('.env.claude.local', 'utf8').catch(() => '')
  const line = text.split('\n').find((l) => l.startsWith(`${name}=`))

  return line ? line.slice(name.length + 1).trim().replace(/^["']|["']$/g, '') : ''
}

/**
 * The seed every lane shares: permalinks, the key, the SEO answers, then the Atlas page last.
 * Read from the classic blueprint rather than copied, so the three lanes cannot drift apart.
 */
async function seed(extra = '') {
  const blueprint = JSON.parse(await readFile('tests/render-classic.json', 'utf8'))
  const code = blueprint.steps.find((step) => step.step === 'runPHP').code

  return code.replace("'demo-key-abc'", JSON.stringify(key).replace(/"/g, "'")) + ' ' + extra
}

/** @param {Cell} cell */
async function blueprint(cell) {
  const steps = [
    {
      step: 'writeFile',
      path: '/wordpress/wp-content/mu-plugins/sahaj-atlas-no-network.php',
      data: "<?php require '/wordpress/wp-content/plugins/sahaj-atlas/tests/no-network.php';",
    },
  ]

  for (const [name, php] of Object.entries(cell.mu ?? {})) {
    steps.push({ step: 'writeFile', path: `/wordpress/wp-content/mu-plugins/sahaj-lane-${name}.php`, data: php })
  }

  if (cell.theme) {
    steps.push({ step: 'installTheme', themeData: { resource: 'wordpress.org/themes', slug: cell.theme }, options: { activate: true } })
  }

  for (const slug of cell.plugins ?? []) {
    steps.push({ step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug }, options: { activate: true } })
  }

  steps.push({ step: 'activatePlugin', pluginPath: 'sahaj-atlas/sahaj-atlas.php' })
  steps.push({ step: 'runPHP', code: await seed([cell.sidebar ? SIDEBAR_PAGE : '', cell.panel ? PANEL_SEED : '', cell.seed ?? ''].join(' ')) })

  // ⚠ The versions live here, not in the `--php`/`--wp` flags: given a blueprint, `server` ignores
  // the flags (see tests/render.mjs). Without this key every cell ran WordPress 7.1 on PHP 8.5.
  return { preferredVersions: { php: '7.4', wp: cell.wp ?? '6.7' }, steps }
}

/**
 * @param {number} port
 * @param {string} path
 * @param {() => boolean} gaveUp  true once the server has exited, so a failed blueprint fails fast
 */
async function waitFor(port, path, gaveUp) {
  for (let attempt = 0; attempt < 120 && !gaveUp(); attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}${path}`)

      if (response.status === 200) return true
    } catch {
      // Not listening yet.
    }
    await sleep(2000)
  }

  return false
}

/**
 * The record the widget boots from, standing in for `clients/me`.
 * - No colours, deliberately: a record with colours re-ran the theme adoption and hid
 *   SahajAtlasWeb#235 for months.
 * - `allowedDomains: 'localhost'` is also what keeps the widget's analytics off
 *   (SahajAtlasWeb `src/views/FullInterface.tsx`, `useAnalytics`), so a run records no pageview.
 * - `canonical.embed` names this server, so `routing=path` is honoured as on a real Atlas page.
 *
 * The key the browser sends belongs to the "Sahaj Atlas (Local Test Key)" client in SahajCloud's
 * Clients collection. Ask a SahajCloud admin for it.
 *
 * @param {number} port
 */
function clientRecord(port) {
  return {
    user: {
      id: 1,
      name: 'Browser lane',
      allowedDomains: 'localhost',
      clientId: 'lane',
      region: null,
      canonical: { enabled: true, embed: `127.0.0.1:${port}/find-a-class` },
    },
  }
}

/**
 * Stub the two SahajCloud calls the lane must not make for real. Every other read goes through.
 *
 * @param {import('playwright-core').BrowserContext} context
 * @param {number} port
 */
async function stubApi(context, port) {
  const headers = { 'access-control-allow-origin': '*', 'content-type': 'application/json' }

  await context.route('**/clients/me*', (route) => route.fulfill({ status: 200, headers, body: JSON.stringify(clientRecord(port)) }))
  await context.route('**/clients/report*', (route) => route.fulfill({ status: 200, headers, body: '{"ok":true}' }))
}

/**
 * What the lane measures on a page. Plugin verdicts read layout and the widget's documented surface
 * only: `<sahaj-atlas>`, its `.sy-atlas` scope, its console warnings and its readiness marker.
 * Widget internals (`[data-vaul-drawer]` and the like) feed WIDGET findings, never a plugin FAIL,
 * because the widget comes unpinned from production.
 *
 * Runs inside the page. Returns plain data so the assertions can print the numbers they judged.
 */
function measure() {
  const element = document.querySelector('sahaj-atlas')

  if (!element) return { element: null }

  const rect = element.getBoundingClientRect()
  const scope = element.querySelector('.sy-atlas')
  const probe = (selector) => {
    const node = scope?.querySelector(selector)

    return node ? getComputedStyle(node) : null
  }
  const headers = [...document.querySelectorAll('header, #page-top, #masthead, .site-header, [data-elementor-type="header"], #wpadminbar')]
    .filter((node) => !element.contains(node))
    .map((node) => node.getBoundingClientRect())
    .filter((box) => box.height > 0 && box.top < rect.top + 1)
  const headerBottom = headers.length ? Math.max(...headers.map((box) => box.bottom)) : null
  const input = scope?.querySelector('input')
  const inputBox = input?.getBoundingClientRect()
  const hitAtInput = inputBox ? document.elementFromPoint(inputBox.x + inputBox.width / 2, inputBox.y + inputBox.height / 2) : null
  const strayPortals = [...document.querySelectorAll('[data-vaul-drawer], [data-sy-expanded], [role="dialog"][id^="radix-"]')].filter((node) => !scope?.contains(node)).length
  const icon = probe('svg path')

  return {
    element: {
      left: Math.round(rect.left),
      top: Math.round(rect.top),
      width: Math.round(rect.width),
      height: Math.round(rect.height),
    },
    viewport: { width: window.innerWidth, height: window.innerHeight },
    offset: getComputedStyle(document.documentElement).getPropertyValue('--sahaj-atlas-top').trim(),
    headerBottom: headerBottom === null ? null : Math.round(headerBottom),
    scope: !!scope,
    ready: document.documentElement.getAttribute('data-sahaj-atlas-ready'),
    errorPanel: !!scope?.querySelector('[role="alert"]'),
    strayPortals,
    htmlClass: document.documentElement.className,
    htmlVars: document.documentElement.getAttribute('style') ?? '',
    inputHitInScope: !!hitAtInput && !!scope?.contains(hitAtInput),
    fonts: {
      h2: probe('h2')?.fontFamily ?? null,
      button: probe('button')?.fontFamily ?? null,
      input: probe('input')?.fontFamily ?? null,
      a: probe('a')?.fontFamily ?? null,
    },
    listStyle: probe('li')?.listStyleType ?? null,
    iconFill: icon ? icon.fill : null,
    drawerWidth: scope?.querySelector('[data-vaul-drawer]')?.getBoundingClientRect().width ?? null,
  }
}

/**
 * @param {import('playwright-core').Page} page
 * @param {string} name
 */
function collectConsole(page, name) {
  /** @type {string[]} */
  const lines = []

  page.on('console', (message) => {
    const text = message.text()

    // Software-GL chatter and Mapbox's own deprecation notice say nothing about the host page.
    if (/GL Driver Message|`light` root property/.test(text)) return
    lines.push(`[${message.type()}] ${text.slice(0, 300)}`)
  })
  page.on('pageerror', (error) => lines.push(`[pageerror] ${error.message.slice(0, 300)}`))
  page.on('response', (response) => {
    const url = response.url()

    // The IP lookup behind "classes near you" rate-limits a lane that opens many pages. It says
    // nothing about the host page, and the widget degrades without it.
    if (response.status() >= 400 && !/ipwho\.is/.test(url)) lines.push(`[response] ${response.status()} ${url.slice(0, 200)}`)
  })

  return {
    lines,
    widget: () => lines.filter((line) => line.includes('[sahaj-atlas]')),
    errors: () => lines.filter((line) => /^\[(error|pageerror|response)\]/.test(line) && !/Failed to load resource/.test(line)),
    dump: () => (lines.length ? `\n        ${name}: ` + lines.join('\n        ') : ''),
  }
}

/**
 * @param {import('playwright-core').BrowserContext} context
 * @param {string} base
 */
async function login(context, base) {
  const page = await context.newPage()

  await page.goto(`${base}/wp-login.php`)
  await page.fill('#user_login', 'admin')
  await page.fill('#user_pass', 'password')
  await page.click('#wp-submit')
  await page.waitForURL(/wp-admin/, { timeout: 60000 })
  await page.close()
}

/**
 * The Atlas page, at one viewport.
 *
 * @param {import('playwright-core').Browser} browser
 * @param {Cell} cell
 * @param {{name: string, width: number, height: number}} viewport
 * @param {number} port
 */
async function checkAtlasPage(browser, cell, viewport, port) {
  const base = `http://127.0.0.1:${port}`
  const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } })
  const label = `${cell.name} @ ${viewport.name}`

  try {
    await stubApi(context, port)

    if (cell.login) await login(context, base)

    const page = await context.newPage()
    const log = collectConsole(page, label)

    await page.goto(base + PAGE, { waitUntil: 'load', timeout: 90000 })

    const booted = await page.waitForSelector('sahaj-atlas .sy-atlas', { timeout: 45000 }).then(() => true, () => false)

    // The map and the feed settle after the scope exists. The readiness marker is the last thing
    // the widget writes, so wait for it, then a beat for layout.
    if (booted) await page.waitForFunction(() => document.documentElement.hasAttribute('data-sahaj-atlas-ready'), null, { timeout: 30000 }).catch(() => {})
    await sleep(2500)

    let m = await page.evaluate(measure)

    if (m.scope && !m.inputHitInScope) {
      await sleep(1500)
      m = await page.evaluate(measure)
    }

    await page.screenshot({ path: join(SCREENSHOTS, `${cell.name}-${viewport.name}.png`) }).catch(() => {})

    const { ok, fit, widget } = scope

    fit(`${label}: the widget booted inside <sahaj-atlas>`, booted && m.scope, booted ? 'no .sy-atlas' : `no boot${log.dump()}`)

    if (!booted || !m.scope) return

    const compact = log.widget().some((line) => line.includes(COMPACT))
    const otherWarnings = log.widget().filter((line) => !line.includes(COMPACT))

    // The plugin's own contract (`assets/atlas-page.css`): the element fills the screen below the
    // header, to the viewport's bottom edge.
    fit(
      `${label}: the element runs to the bottom of the screen`,
      Math.abs(m.element.top + m.element.height - m.viewport.height) <= 2 && m.element.height > 0,
      `${m.element.width}×${m.element.height} at top ${m.element.top}, offset ${m.offset}, viewport ${m.viewport.height}`,
    )
    fit(`${label}: the widget logged nothing else`, otherWarnings.length === 0, otherWarnings.join(' | '))

    if (compact && viewport.compactAllowed) {
      console.log(`  info  ${label}: the compact card, as a ${viewport.height}px screen allows (${m.element.height}px below the header)`)
      await checkOverlay(page, `${label} overlay`, join(SCREENSHOTS, `${cell.name}-${viewport.name}-overlay.png`))
    } else {
      fit(`${label}: the widget kept the full interface`, !compact, `${m.element.height}px below the header at top ${m.element.top}${log.dump()}`)
      fit(`${label}: the search box is on top`, m.inputHitInScope, 'elementFromPoint at the search box lands outside the widget')

      if (m.element.top > 0.2 * m.viewport.height) {
        console.log(`  info  ${label}: ${m.element.top}px of a ${m.viewport.height}px screen sits above the map; a shorter screen will get the compact card`)
      }
    }

    ok(`${label}: no error panel`, !m.errorPanel, log.dump())
    fit(
      `${label}: the header sits above the element`,
      m.headerBottom === null || m.headerBottom <= m.element.top + 1,
      m.headerBottom === null ? 'no header found' : `header bottom ${m.headerBottom}, element top ${m.element.top}`,
    )

    if (cell.band) {
      const bands = await page.evaluate((selector) => document.querySelectorAll(selector).length, cell.band)

      fit(`${label}: the theme's band is switched off`, bands === 0, `${bands} × \`${cell.band}\` still above the map`)

      // ⚠ The header check above passes when no header is found at all, which is the one failure
      // switching a band off can cause. With the band gone, a header found here is the masthead —
      // two of these four bands are themselves a `<header>`, so this only reads as the masthead
      // once the assertion above holds.
      fit(`${label}: and its masthead outlived the band`, m.headerBottom !== null, `no header above the element at top ${m.element.top}`)
    }

    fit(`${label}: no script error`, log.errors().length === 0, log.errors().join(' | '))
    widget(`${label}: every drawer and dialog is inside the widget`, m.strayPortals === 0, `${m.strayPortals} outside`)
    widget(`${label}: <html> carries no theme class or brand vars`, !/\b(light|dark)\b/.test(m.htmlClass) && !m.htmlVars.includes('--primary'), `class="${m.htmlClass}" style="${m.htmlVars.slice(0, 80)}"`)
    widget(`${label}: the widget's typeface survives the theme`, Object.values(m.fonts).every((font) => font === null || font.includes('Atlas Rethink Sans')), JSON.stringify(m.fonts))
    widget(`${label}: and its lists carry no bullets`, m.listStyle === null || m.listStyle === 'none', String(m.listStyle))
    widget(`${label}: and its icons are not filled`, m.iconFill === null || m.iconFill === 'none', String(m.iconFill))
    widget(
      `${label}: the side panel keeps its width`,
      m.drawerWidth === null || viewport.width < 768 || m.drawerWidth >= 340,
      `${Math.round(m.drawerWidth)}px — a host root font size scales the panel's rem width`,
    )
  } finally {
    await context.close()
  }
}

/**
 * The in-content compact card: a map embed in a 300px column. The card is the right answer there.
 * Its button must open the overlay inside the widget, with its controls reachable
 * (SahajAtlasWeb#235).
 *
 * @param {import('playwright-core').Browser} browser
 * @param {Cell} cell
 * @param {number} port
 */
async function checkSidebar(browser, cell, port) {
  const base = `http://127.0.0.1:${port}`
  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const label = `${cell.name} @ sidebar`

  try {
    await stubApi(context, port)

    const page = await context.newPage()
    const log = collectConsole(page, label)
    const { fit } = scope

    await page.goto(`${base}/sidebar-host/`, { waitUntil: 'load', timeout: 90000 })

    await page.waitForSelector('sahaj-atlas .sy-atlas', { timeout: 45000 }).catch(() => {})
    await sleep(2000)

    const card = log.widget().some((line) => line.includes(COMPACT))

    fit(`${label}: a 300px column shows the compact card`, card, log.dump())

    if (!card) return

    await checkOverlay(page, label, join(SCREENSHOTS, `${cell.name}-sidebar-overlay.png`))
  } finally {
    await context.close()
  }
}

/**
 * Press the compact card's button and judge the overlay it opens. Its chrome must be inside the
 * widget and reachable, or the visitor gets a bare map with no way out (SahajAtlasWeb#235).
 *
 * @param {import('playwright-core').Page} page
 * @param {string} label
 * @param {string} screenshot
 */
async function checkOverlay(page, label, screenshot) {
  const { widget } = scope

  // The card is "a heading and one button" (`docs/embedding.md`), so its one button is the press.
  // Everything after it is the widget's overlay, read through its internals: WIDGET verdicts only.
  const pressed = await page
    .locator('sahaj-atlas .sy-atlas button')
    .first()
    .click({ timeout: 15000 })
    .then(() => true, (error) => String(error.message).split('\n')[0])
  const opened = pressed === true && (await page.waitForSelector('[data-sy-expanded]', { timeout: 45000 }).then(() => true, () => false))

  widget(`${label}: the card's button opens the overlay`, opened, pressed === true ? '' : pressed)

  if (!opened) return

  await page.waitForFunction(() => document.querySelector('[data-sy-expanded] [data-vaul-drawer]'), null, { timeout: 30000 }).catch(() => {})
  await sleep(2000)

  const m = await page.evaluate(() => {
    const dialog = document.querySelector('[data-sy-expanded]')
    const close = [...dialog.querySelectorAll('button')].find((b) => /close/i.test(b.getAttribute('aria-label') ?? ''))
    const hit = (node) => {
      if (!node) return false
      const box = node.getBoundingClientRect()
      const top = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)

      return !!top && dialog.contains(top)
    }

    return {
      inScope: !!dialog.closest('.sy-atlas'),
      position: getComputedStyle(dialog).position,
      close: hit(close),
      drawer: hit(dialog.querySelector('[data-vaul-drawer]')),
    }
  })

  await page.screenshot({ path: screenshot }).catch(() => {})

  // Widget-side, all three: where the overlay lands and what outranks its chrome are the
  // widget's stacking to settle (SahajAtlasWeb#234), not the plugin's page.
  widget(`${label}: the overlay is inside the widget`, m.inScope && m.position === 'fixed', `inScope=${m.inScope} position=${m.position}`)
  widget(`${label}: its close button is on top`, m.close, 'elementFromPoint at × lands outside the overlay')
  widget(`${label}: and so is its drawer`, m.drawer, 'elementFromPoint in the drawer lands outside the overlay')

  await page.keyboard.press('Escape')
  await sleep(800)

  widget(`${label}: Escape closes it`, !(await page.$('[data-sy-expanded]')))
}

/**
 * Read the status panel the way the volunteer does, and judge the two loopback rows.
 *
 * ⚠ The panel's own fetch of `clients/me` is refused here — PHP is offline in this lane — so every
 * row that needs the client record is idle. Only the two rows that read this server's own page have
 * anything to say, and `tests/no-network.php` lets that one request through.
 *
 * @param {import('playwright-core').Browser} browser
 * @param {Cell} cell
 * @param {number} port
 */
async function checkPanel(browser, cell, port) {
  const base = `http://127.0.0.1:${port}`
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
  const label = `${cell.name} @ panel`

  try {
    await stubApi(context, port)
    await login(context, base)

    const page = await context.newPage()

    await page.goto(`${base}/wp-admin/options-general.php?page=sahaj-atlas`, { waitUntil: 'load', timeout: 90000 })
    await page.screenshot({ path: join(SCREENSHOTS, `${cell.name}-panel.png`), fullPage: true }).catch(() => {})

    const rows = await page.evaluate(() =>
      [...document.querySelectorAll('table.widefat tbody tr')].map((row) => ({
        glyph: row.children[0]?.textContent.trim() ?? '',
        label: row.children[1]?.textContent.trim() ?? '',
        detail: row.children[2]?.textContent.trim() ?? '',
      })),
    )

    const { fit } = scope
    const placement = rows.find((row) => /placement/i.test(row.label))
    const script = rows.find((row) => /script/i.test(row.label))

    fit(`${label}: both loopback rows are shown`, !!placement && !!script, rows.map((row) => `${row.glyph} ${row.label}`).join(' | '))

    if (!placement || !script) return

    // ⚠ `!` on both rows means this server could not fetch its own page, which is a harness
    // condition and not a verdict about the plugin. Say so and judge nothing, rather than reporting
    // a red the code did not earn.
    if (placement.glyph === '!' && script.glyph === '!') {
      console.log(`  info  ${label}: the loopback fetch did not run here — ${placement.detail}`)
      return
    }

    // The element's print path, as the panel reports it rather than as the HTML shows it.
    const expected = cell.render === 'content' ? /content area/i : /full-screen/i

    fit(`${label}: placement is green`, placement.glyph === '✓', `${placement.glyph} ${placement.detail}`)
    fit(`${label}: and names the ${cell.render ?? 'template'} path`, expected.test(placement.detail), placement.detail)

    // ⚠ The one cell whose page is genuinely broken is the one whose panel must be red. A green row
    // there is the silence SahajAtlasWeb#239 handed to the plugin to break.
    if (cell.name === 'strip-module') {
      fit(`${label}: the script row is red`, script.glyph === '✗', `${script.glyph} ${script.detail}`)
    } else {
      fit(`${label}: the script row is green`, script.glyph === '✓', `${script.glyph} ${script.detail}`)
    }
  } finally {
    await context.close()
  }
}

/**
 * @param {import('playwright-core').Browser} browser
 * @param {Cell} cell
 * @param {number} index
 */
async function run(browser, cell, index) {
  const port = BASE_PORT + index
  const dir = join(tmpdir(), 'sahaj-atlas-browser')
  const file = join(dir, `${cell.name}.json`)

  await mkdir(dir, { recursive: true })
  await writeFile(file, JSON.stringify(await blueprint(cell), null, 1))
  await rm(NETWORK_LOG, { force: true })

  console.log(`\n${cell.name}${cell.known ? '  (known failure: ' + cell.known + ')' : ''}`)

  let cellFailures = 0

  const report = (verdict, label, detail) => console.log(`  ${verdict} ${label}${detail ? `\n        ${detail}` : ''}`)

  scope = {
    ok(label, condition, detail = '') {
      if (condition) return console.log(`  ok    ${label}`)
      failures += 1
      report('FAIL ', label, detail)
    },
    fit(label, condition, detail = '') {
      if (condition) return console.log(`  ok    ${label}`)
      cellFailures += 1
      report(cell.known ? 'KNOWN' : 'FAIL ', label, detail)
    },
    widget(label, condition, detail = '') {
      if (condition) {
        console.log(`  ok    ${label}`)
        return
      }
      widgetFindings += 1
      console.log(`  WIDGET ${label}${detail ? `\n        ${detail}` : ''}`)
    },
  }

  const serverLog = join(dir, `${cell.name}.log`)
  const server = spawn(
    'npx',
    [
      'wp-playground-cli',
      'server',
      '--blueprint',
      file,
      '--mount',
      '.:/wordpress/wp-content/plugins/sahaj-atlas',
      '--port',
      String(port),
    ],
    { stdio: ['ignore', 'pipe', 'pipe'] },
  )
  let exited = null
  const output = []

  server.stdout.on('data', (chunk) => output.push(chunk))
  server.stderr.on('data', (chunk) => output.push(chunk))
  server.on('exit', (code) => (exited = code))
  server.on('error', (error) => (exited = error.message))

  try {
    if (!(await waitFor(port, PAGE, () => exited !== null))) {
      await writeFile(serverLog, Buffer.concat(output))
      scope.ok(
        `${cell.name}: the Atlas page is served`,
        false,
        exited !== null ? `the server exited (${exited}); its output is in ${serverLog}` : `timed out waiting for a 200; server output in ${serverLog}`,
      )
      return
    }

    const probe = await fetch(`http://127.0.0.1:${port}${PAGE}`)
    const served = await probe.text()
    const generator = served.match(/<meta name="generator" content="WordPress ([^"]+)"/)?.[1] ?? '?'

    console.log(`  info  WordPress ${generator}, ${probe.headers.get('x-powered-by') ?? 'PHP ?'}`)

    // ── Which print rendered the element (#39) ──────────────────────────────────────────────────
    // Read from the HTML, before a browser runs any of it. A cell whose template this plugin does
    // not supply must come from the content area, above the footer it keeps; every other cell must
    // still come from the plugin's own template.
    {
      const want = cell.render ?? 'template'
      const elementAt = served.search(/<sahaj-atlas[\s>]/)
      // ⚠ Markup only. Astra's inline CSS in <head> names `.site-footer`, so a bare match finds the
      // footer before the element on every page, and finds one where the theme printed none.
      const footerAt = served.search(/<footer[\s>]|<[a-z]+\s[^>]*class="[^"]*(?:wp-block-template-part[^"]*footer|site-footer)/)

      scope.fit(`${cell.name}: printed from the ${want} path`, served.includes(`data-sahaj-atlas-render="${want}"`), (served.match(/<sahaj-atlas[^>]*>/) ?? [])[0] ?? 'no element')

      if (want === 'content') {
        scope.fit(`${cell.name}: the theme's footer is kept`, footerAt >= 0, served.slice(-300))
        scope.fit(`${cell.name}: with the element above it`, elementAt >= 0 && elementAt < footerAt, `element ${elementAt}, footer ${footerAt}`)
      }
    }

    for (const viewport of VIEWPORTS) {
      await checkAtlasPage(browser, cell, viewport, port).catch((error) => scope.ok(`${cell.name} @ ${viewport.name}: the check ran to the end`, false, String(error.message).split('\n')[0]))
    }

    if (cell.sidebar) await checkSidebar(browser, cell, port).catch((error) => scope.ok(`${cell.name} @ sidebar: the check ran to the end`, false, String(error.message).split('\n')[0]))

    if (cell.panel) await checkPanel(browser, cell, port).catch((error) => scope.ok(`${cell.name} @ panel: the check ran to the end`, false, String(error.message).split('\n')[0]))

    const escaped = await readFile(NETWORK_LOG, 'utf8').catch(() => null)

    const ours = (escaped ?? '').split('\n').filter((url) => /sahaj-atlas\.invalid|sydevelopers/.test(url))
    const theirs = (escaped ?? '').split('\n').filter((url) => url && !ours.includes(url))

    // The invariant is SahajCloud, the call that once ran on every test run (#28). Core and other
    // plugins, Plugin Update Checker among them, are refused all the same and only named.
    scope.ok(`${cell.name}: the plugin's PHP never reached SahajCloud`, escaped !== null && ours.length === 0, escaped === null ? 'no log — the mu-plugin never loaded' : ours.join(' '))

    if (theirs.length) console.log(`  info  ${cell.name}: core or another plugin tried ${new Set(theirs.map((url) => new URL(url).host)).size} host(s), refused: ${[...new Set(theirs.map((url) => new URL(url).host))].join(', ')}`)
  } finally {
    server.kill('SIGTERM')
    await sleep(1500)

    if (cell.known) knownFailures += cellFailures
    else failures += cellFailures
  }
}

await mkdir(SCREENSHOTS, { recursive: true })

/**
 * ⚠ Headless Chromium has no WebGL unless it is given software GL. Mapbox then refuses to mount
 * ("Map is not supported by this browser"), which reads exactly like the widget failing to boot.
 * The browser is not downloaded by `pnpm install`: run `pnpm exec playwright-core install chromium`
 * once on a fresh machine.
 */
const browser = await chromium
  .launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] })
  .catch((error) => {
    console.error(`Chromium did not launch. Run \`pnpm exec playwright-core install chromium\` once.\n${error.message.split('\n')[0]}`)
    process.exit(2)
  })

try {
  for (const [index, cell] of cells.entries()) {
    await run(browser, cell, index)
  }
} finally {
  await browser.close()
}

console.log(`\n${failures} failure(s), ${knownFailures} known, ${widgetFindings} widget finding(s) (SahajAtlasWeb's to settle; check its open tickets before filing)`)
process.exit(failures > 0 ? 1 : 0)
