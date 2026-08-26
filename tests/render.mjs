/**
 * End-to-end render checks: boot a real WordPress, fetch the Atlas page, assert the markup.
 *
 * The PHP suite (`npm test`) covers every decision the plugin makes. This covers the one thing it
 * cannot: whether those decisions produce a working page once WordPress, a theme and core's script
 * loader have all had their say. Both theme kinds run, because the plugin takes a **different code
 * path for each** — `register_block_template()` on a block theme, `template_include` on a classic
 * one — and the classic path has a specific silent failure mode worth pinning: `get_header()` on a
 * block theme falls through to `wp-includes/theme-compat/header.php`, which emits a complete second
 * `<!DOCTYPE html><html><head>` with 2010-era markup and no error a reader would notice. Counting
 * doctypes is what catches it.
 *
 *   npm run test:render
 */

import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

/** Ports are fixed per theme so a failed run leaves nothing to guess about. */
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
 * Wait for the ATLAS page, not for `/`.
 *
 * ⚠ WordPress answers `/` the moment the server is up, which is *before* the blueprint has created
 * the page — so polling `/` reports ready and every assertion then runs against a 404. That cost a
 * debugging round on the classic run; the readiness signal has to be the thing under test.
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
    // Pinned to the fleet's floor. ⚠ `preferredVersions` inside a blueprint is IGNORED by the
    // `server` command — it booted PHP 8.3 / WordPress latest until these flags were passed.
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
    // The header is only safe to render because the element is SIZED: an unsized map embed is
    // `position: fixed; inset: 0` and paints straight over it. The two assertions belong together
    // — either alone passes on the broken page.
    ok('the site header renders', run.theme === 'classic' ? html.includes('site-header') : /<header/.test(html))
    ok('the sizing stylesheet is loaded', html.includes('assets/atlas-page.css'))
    ok('and the measurement script', html.includes('assets/atlas-page.js'))

    // ⚠ The element must come AFTER the header in the flow. A contained map draws where its
    // element sits, so printing it at `wp_body_open` — which is what the plugin used to do — puts
    // the atlas above the header instead of below it.
    const headerAt = html.search(/<header/)
    const elementAt = html.search(/<sahaj-atlas[\s>]/)

    ok('with the element below the header', headerAt >= 0 && elementAt > headerAt, `header ${headerAt}, element ${elementAt}`)

    // The loader is a real ES module — its first statement is a top-level `import`, which is a
    // SyntaxError in a classic script. A `<script>` without `type="module"` is a hard break, not a
    // degraded experience.
    const script = html.match(/<script[^>]*src="([^"]*auto\.js[^"]*)"[^>]*>/)

    ok('the loader is enqueued', Boolean(script), html.includes('auto.js') ? 'found, but not as a script src' : 'absent')
    ok('as a module', Boolean(script) && /type="module"/.test(script[0]))

    const src = (script?.[1] ?? '').replace(/&amp;/g, '&')

    ok('carrying the API key', src.includes('key=demo-key-abc'), src)
    ok('claiming path routing', src.includes('routing=path'), src)
    ok('and no locale — the page\'s <html lang> is the source', !src.includes('locale='), src)
    ok('and no version query core would have appended', !src.includes('ver='), src)

    const deep = await fetch(base + DEEP)
    const deepHtml = await deep.text()

    ok('a deep link is served', deep.status === 200, `status ${deep.status}`)
    ok('by the same page', (deepHtml.match(/<sahaj-atlas[\s>]/g) ?? []).length === 1)

    // The plugin claims a subtree, not the site. If this regresses, every typo on the site becomes
    // the atlas and the host loses their 404 page.
    const missing = await fetch(base + MISSING)

    ok('an unrelated missing URL still 404s', missing.status === 404, `status ${missing.status}`)

    // ── The sitemap ────────────────────────────────────────────────────────────────────────────
    // The cache is seeded by the blueprint, so this exercises the serving path — `parse_request`
    // interception, the headers and the document — without a real API key. The fetch itself is
    // `wp_remote_get` and a transient, and is covered by the PHP suite's rules for the answer.
    const sitemap = await fetch(`${base}/sahaj-atlas-sitemap.xml`)
    const xml = await sitemap.text()

    ok('the sitemap is served', sitemap.status === 200, `status ${sitemap.status}`)
    ok('as XML', (sitemap.headers.get('content-type') ?? '').includes('xml'), sitemap.headers.get('content-type') ?? '')

    // ⚠ A sitemap must not itself be indexed — it is a machine file, and one that turns up in
    // results is a page of raw XML with the site's name on it.
    ok('and not itself indexed', (sitemap.headers.get('x-robots-tag') ?? '').includes('noindex'), sitemap.headers.get('x-robots-tag') ?? '')

    ok('listing the atlas URLs', (xml.match(/<loc>/g) ?? []).length === 2, xml.slice(0, 200))
    ok('with no WordPress page markup in it', !xml.includes('<html') && !xml.includes('<!doctype'), xml.slice(0, 120))

    // ⚠ The whole discovery path for the atlas: nothing on the site links into these routes, so a
    // crawler learns they exist here or not at all.
    const robots = await (await fetch(`${base}/robots.txt`)).text()

    ok('robots.txt points at it', /Sitemap:\s*\S*sahaj-atlas-sitemap\.xml/.test(robots), robots.trim())

    // ── In-content embeds ──────────────────────────────────────────────────────────────────────
    // A shortcode and a block are two different resolution paths (`sahaj_atlas_embed_from_shortcode`
    // scans the raw content; `sahaj_atlas_find_block` walks the parsed block tree), so one working
    // says nothing about the other. Neither is reachable from the PHP suite, which never renders a
    // post.
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

      // An in-content embed is map-LESS, so unlike the Atlas page it needs a height: an unsized
      // custom element is an inline box of zero height and looks like it did not render at all.
      // ⚠ `height`, never `min-height`. The widget fills its element with `height: 100%`, which
      // needs a definite height to resolve against — `min-height` leaves it nothing to fill, so it
      // refuses the box and covers the browser window instead. The plugin shipped `min-height`
      // until SahajAtlasWeb#170 wrote the rule down.
      const style = tag?.[0] ?? ''

      ok(`${kind}: the element is sized`, /[^-]height:\s*\d/.test(style), style || 'no element')
      ok(`${kind}: with a definite height, not min-height`, !/min-height/.test(style), style)
      ok(`${kind}: and display:block, which a custom element needs to take one`, /display:\s*block/.test(style), style)
      ok(`${kind}: the loader asks for no map`, loader.includes('map=false'), loader)
      ok(`${kind}: and carries the route`, loader.includes('atlas=%2Fgb%2Flondon%2F1204') || loader.includes('atlas=/gb/london/1204'), loader)

      // ⚠ An in-content embed must NEVER claim path routing: the server only serves the subtree
      // under the Atlas page, so a deep link from a widget on an article would 404.
      ok(`${kind}: and never claims path routing`, !loader.includes('routing=path'), loader)

      // The surrounding content still has to be there — a render callback that swallowed the post
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
