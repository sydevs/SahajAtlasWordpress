/**
 * End-to-end render checks: boot a real WordPress, fetch the Atlas page, and check the markup.
 *
 * The PHP suite (`pnpm test`) covers every decision the plugin makes. This file covers the one
 * thing that suite cannot: whether those decisions add up to a working page, once WordPress, a
 * theme, and core's script loader have all had their say.
 *
 * Both theme kinds run here, because the plugin takes a different code path for each:
 * `register_block_template()` on a block theme, `template_include` on a classic one. The classic
 * path has one specific silent failure mode worth pinning down. `get_header()` on a block theme
 * falls through to `wp-includes/theme-compat/header.php`, which prints a complete second
 * `<!DOCTYPE html><html><head>` with 2010-era markup and no visible error. Counting doctypes is
 * what catches it.
 *
 *   pnpm test:render
 */

import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

/** Each theme run uses a fixed port. A failed run then leaves nothing to guess about. */
const RUNS = [
  { theme: 'block', port: 8801, blueprint: 'tests/render-blueprint.json', mounts: [] },
  {
    theme: 'classic',
    port: 8802,
    blueprint: 'tests/render-classic.json',
    mounts: ['./tests/fixtures/sahaj-classic:/wordpress/wp-content/themes/sahaj-classic'],
  },
]

const PAGE = '/find-a-class/'
const DEEP = '/find-a-class/gb/london'
const MISSING = '/no-such-page-anywhere/'

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
 * Waits for the Atlas page, not for `/`.
 *
 * ⚠ WordPress answers `/` the moment the server starts, before the blueprint creates the page.
 * So polling `/` reports ready too early, and every assertion then runs against a 404. This cost
 * a debugging round on the classic run. The readiness signal has to be the thing under test.
 *
 * @param {number} port
 */
async function waitForAtlasPage(port) {
  for (let attempt = 0; attempt < 90; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}${PAGE}`)
      if (response.status === 200) return true
    } catch {
      // Server not listening yet.
    }
    await sleep(2000)
  }
  return false
}

/**
 * @param {{theme: string, port: number, blueprint: string, mounts: string[]}} run
 */
async function check(run) {
  console.log(`\n${run.theme} theme`)

  const args = [
    'wp-playground-cli',
    'server',
    '--blueprint',
    run.blueprint,
    // Pinned to the fleet's floor. ⚠ The `server` command ignores `preferredVersions` inside a
    // blueprint. Without these flags, it boots PHP 8.3 and the latest WordPress instead.
    '--php',
    '7.4',
    '--wp',
    '6.7',
    '--mount',
    '.:/wordpress/wp-content/plugins/sahaj-atlas',
    ...run.mounts.flatMap((mount) => ['--mount', mount]),
    '--port',
    String(run.port),
  ]

  const server = spawn('npx', args, { stdio: 'ignore' })

  try {
    if (!(await waitForAtlasPage(run.port))) {
      ok(`${run.theme}: the Atlas page is served`, false, 'timed out waiting for a 200')
      return
    }

    const base = `http://127.0.0.1:${run.port}`
    const html = await (await fetch(base + PAGE)).text()

    ok('exactly one document', (html.match(/<!doctype/gi) ?? []).length === 1)
    ok('exactly one <sahaj-atlas> element', (html.match(/<sahaj-atlas[\s>]/g) ?? []).length === 1)
    ok('no site footer', !/site-footer|wp-block-template-part[^"]*footer/.test(html))

    // ── The contained map (SahajAtlasWeb#170) ──────────────────────────────────────────────────
    // The header is safe to render only because the element is sized. An unsized map embed
    // becomes `position: fixed; inset: 0` and paints straight over the header. These two
    // assertions belong together. Either one alone would pass on the broken page.
    ok('the site header renders', run.theme === 'classic' ? html.includes('site-header') : /<header/.test(html))
    ok('the sizing stylesheet is loaded', html.includes('assets/atlas-page.css'))
    ok('and the measurement script', html.includes('assets/atlas-page.js'))

    // ⚠ The element must come after the header in the page flow. A contained map draws wherever
    // its element sits. Printing the element at `wp_body_open`, which the plugin used to do,
    // puts the atlas above the header instead of below it.
    const headerAt = html.search(/<header/)
    const elementAt = html.search(/<sahaj-atlas[\s>]/)

    ok('with the element below the header', headerAt >= 0 && elementAt > headerAt, `header ${headerAt}, element ${elementAt}`)

    // The loader is a real ES module. Its first statement is a top-level `import`, which is a
    // SyntaxError in a classic script. A `<script>` tag without `type="module"` breaks the page
    // completely. It does not just degrade the experience.
    const script = html.match(/<script[^>]*src="([^"]*auto\.js[^"]*)"[^>]*>/)

    ok('the loader is enqueued', Boolean(script), html.includes('auto.js') ? 'found, but not as a script src' : 'absent')
    ok('as a module', Boolean(script) && /type="module"/.test(script[0]))

    const src = (script?.[1] ?? '').replace(/&amp;/g, '&')

    ok('carrying the API key', src.includes('key=demo-key-abc'), src)
    ok('claiming path routing', src.includes('routing=path'), src)
    ok('and no locale — the page\'s <html lang> is the source', !src.includes('locale='), src)
    ok('and no version query core would have appended', !src.includes('ver='), src)

    // ── The root view's metadata (SahajCloud#739) ──────────────────────────────────────────────
    // The blueprint seeds the answer, so this exercises the takeover itself: the suppression, the
    // head block, and the crawlable children, without a real API key. Which routes get asked
    // about, and what happens when nothing answers, are the PHP suite's job.
    //
    // ⚠ This page is the one a host links from its own nav, and it had no metadata of its own
    // until #739. Every assertion below reads the page a crawler gets, not the one a browser
    // assembles: the widget replaces these children the moment it boots.
    ok('the root view takes its title from the atlas', /<title>\s*Free meditation classes near you\s*<\/title>/.test(html))
    ok('with a description of its own', html.includes('name="description" content="Find a free meditation class near you."'))
    ok('a canonical of its own', new RegExp(`<link rel="canonical" href="${base}/find-a-class"`).test(html))
    ok('one hreflang row per locale the answer carries', (html.match(/rel="alternate" hreflang=/g) ?? []).length === 2)
    ok('and Open Graph tags', html.includes('property="og:title" content="Free meditation classes near you"'))

    // ⚠ The producer escapes this value for a `<script>` element nothing downstream sanitizes, so
    // the plugin echoes it raw. `esc_html` here would leave a crawler `&quot;`-laden text, and
    // re-encoding would double-escape it — both still render a script tag, so only the payload shows it.
    ok(
      'the JSON-LD is echoed exactly as it arrived',
      html.includes('<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Sahaj Atlas"}]}</script>'),
    )

    ok(
      'and the element carries crawlable content for a visitor with no JavaScript',
      /<sahaj-atlas><section><h1>Free meditation classes near you<\/h1><p>Every class is free/.test(html),
    )

    const deep = await fetch(base + DEEP)
    const deepHtml = await deep.text()

    ok('a deep link is served', deep.status === 200, `status ${deep.status}`)
    ok('by the same page', (deepHtml.match(/<sahaj-atlas[\s>]/g) ?? []).length === 1)

    // The plugin claims a subtree, not the whole site. If this regresses, every typo on the site
    // becomes the atlas, and the host loses their 404 page.
    const missing = await fetch(base + MISSING)

    ok('an unrelated missing URL still 404s', missing.status === 404, `status ${missing.status}`)

    // ── The sitemap ────────────────────────────────────────────────────────────────────────────
    // The blueprint seeds the cache, so this exercises the serving path: `parse_request`
    // interception, the headers, and the document, all without a real API key. The fetch itself
    // is `wp_remote_get` and a transient. The PHP suite's rules for the answer cover that part.
    const sitemap = await fetch(`${base}/sahaj-atlas-sitemap.xml`)
    const xml = await sitemap.text()

    ok('the sitemap is served', sitemap.status === 200, `status ${sitemap.status}`)
    ok('as XML', (sitemap.headers.get('content-type') ?? '').includes('xml'), sitemap.headers.get('content-type') ?? '')

    // ⚠ A sitemap must never be indexed itself. It is a machine file. One that turns up in
    // search results is a page of raw XML with the site's name on it.
    ok('and not itself indexed', (sitemap.headers.get('x-robots-tag') ?? '').includes('noindex'), sitemap.headers.get('x-robots-tag') ?? '')

    ok('listing the atlas URLs', (xml.match(/<loc>/g) ?? []).length === 2, xml.slice(0, 200))
    ok('with no WordPress page markup in it', !xml.includes('<html') && !xml.includes('<!doctype'), xml.slice(0, 120))

    // ⚠ This is the whole discovery path for the atlas. Nothing on the site links into these
    // routes. A crawler learns they exist here, or not at all.
    const robots = await (await fetch(`${base}/robots.txt`)).text()

    ok('robots.txt points at it', /Sitemap:\s*\S*sahaj-atlas-sitemap\.xml/.test(robots), robots.trim())

    // ── In-content embeds ──────────────────────────────────────────────────────────────────────
    // A shortcode and a block use two different resolution paths.
    // `sahaj_atlas_embed_from_shortcode` scans the raw content. `sahaj_atlas_find_block` walks
    // the parsed block tree. So one working says nothing about the other. Neither path is
    // reachable from the PHP suite, because that suite never renders a post.
    for (const [kind, slug] of [
      ['shortcode', '/shortcode-host/'],
      ['block', '/block-host/'],
    ]) {
      const page = await fetch(base + slug)
      const body = await page.text()
      const tag = body.match(/<sahaj-atlas[^>]*>/)
      const loader = (body.match(/<script[^>]*src="([^"]*auto\.js[^"]*)"/) ?? [])[1]?.replace(/&amp;/g, '&') ?? ''

      ok(`${kind}: the page renders`, page.status === 200, `status ${page.status}`)
      ok(`${kind}: exactly one element`, (body.match(/<sahaj-atlas[\s>]/g) ?? []).length === 1)

      // An in-content embed has no map, so unlike the Atlas page it needs a height. An unsized
      // custom element is an inline box of zero height, and looks like it did not render at all.
      // ⚠ `height`, never `min-height`. The widget fills its element with `height: 100%`, which
      // needs a definite height to resolve against. `min-height` leaves nothing to fill, so the
      // widget refuses the box and covers the browser window instead. The plugin shipped
      // `min-height` until SahajAtlasWeb#170 wrote this rule down.
      const style = tag?.[0] ?? ''

      ok(`${kind}: the element is sized`, /[^-]height:\s*\d/.test(style), style || 'no element')
      ok(`${kind}: with a definite height, not min-height`, !/min-height/.test(style), style)
      ok(`${kind}: and display:block, which a custom element needs to take one`, /display:\s*block/.test(style), style)
      ok(`${kind}: the loader asks for no map`, loader.includes('map=false'), loader)
      ok(`${kind}: and carries the route`, loader.includes('atlas=%2Fgb%2Flondon%2F1204') || loader.includes('atlas=/gb/london/1204'), loader)

      // ⚠ An in-content embed must never claim path routing. The server only serves the subtree
      // under the Atlas page. A deep link from a widget on an article would 404.
      ok(`${kind}: and never claims path routing`, !loader.includes('routing=path'), loader)

      // The surrounding content still has to render. A render callback that swallowed the post
      // would pass every assertion above.
      if (kind === 'shortcode') {
        ok('shortcode: the rest of the content survives', body.includes('Before.') && body.includes('After.'))
      }
    }
  } finally {
    server.kill('SIGTERM')
    await sleep(1500)
  }
}

for (const run of RUNS) {
  await check(run)
}

console.log(`\n${failures} failure(s)`)
process.exit(failures > 0 ? 1 : 0)
