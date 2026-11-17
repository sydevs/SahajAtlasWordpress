/**
 * Boots the zip a site would install, not the source tree, and renders a page that uses it.
 *
 *   node tests/zip.mjs build/sahaj-atlas-<version>.zip
 *
 * ⚠ Every other lane mounts the working tree, so a file `.distignore` wrongly leaves out of the zip
 * passes all of them and then fatals every site at its next automatic update. `package.sh` only
 * checks that three files exist. This lane unzips the real artifact, mounts it at
 * `wp-content/plugins/sahaj-atlas`, and fails on a PHP error from the plugin or a missing element.
 *
 * `tests/` is not in the zip, so `no-network.php` is mounted from the source tree at /sahaj-tests.
 * The page is a shortcode page: the Atlas page would make the SEO fetch, which needs seeded answers
 * that live in tests/ too, and the shortcode exercises the same plugin load.
 */
import { spawn, execFileSync } from 'node:child_process'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'

const zip = process.argv[2]
if (!zip) {
  console.error('usage: node tests/zip.mjs <plugin zip>')
  process.exit(2)
}

const PORT = 8803
const PAGE = '/?pagename=zip-host'
const dir = mkdtempSync(join(tmpdir(), 'sahaj-atlas-zip-'))
execFileSync('unzip', ['-q', resolve(zip), '-d', dir])

let failures = 0
function ok(label, condition, detail = '') {
  if (condition) return console.log(`  ok    ${label}`)
  failures += 1
  console.log(`  FAIL  ${label}${detail ? `\n        ${detail}` : ''}`)
}

const server = spawn(
  'npx',
  [
    'wp-playground-cli',
    'server',
    '--blueprint',
    'tests/zip-blueprint.json',
    '--mount',
    `${join(dir, 'sahaj-atlas')}:/wordpress/wp-content/plugins/sahaj-atlas`,
    '--mount',
    './tests:/sahaj-tests',
    '--port',
    String(PORT),
  ],
  { stdio: 'ignore' },
)

try {
  console.log(`\nthe built zip (${zip})`)
  // Ready is the page itself, which exists once the blueprint's last step has run. A 500 is an
  // answer too: a plugin that fatals on load never serves the page, so waiting for a 200 would
  // spend the timeout and report nothing. Playground's own 'not ready yet' 500 is the boot, and does not count.
  let html
  let crashes = 0
  for (let attempt = 0; attempt < 60 && html === undefined && crashes < 2; attempt += 1) {
    try {
      const candidate = await fetch(`http://127.0.0.1:${PORT}${PAGE}`)
      const body = await candidate.text()
      if (candidate.status === 200 && body.includes('Before.')) html = body
      else if (candidate.status >= 500 && !body.includes('not ready yet')) {
        crashes += 1
        if (crashes === 2) html = body
      } else crashes = 0
    } catch {
      // Server not listening yet.
    }
    if (html === undefined) await sleep(2000)
  }
  ok('the page is served', html !== undefined && html.includes('Before.'), html ? html.replace(/\s+/g, ' ').slice(0, 300) : 'timed out: the instance never served the page, so the blueprint failed, usually activating a plugin that fatals on load')

  if (html) {
    ok('no critical error', !html.includes('critical error'))
    ok('no PHP error from the plugin', !/(Fatal error|Parse error|Warning|Notice|Deprecated)[^\n]*sahaj-atlas/i.test(html), html.match(/(Fatal error|Warning|Notice|Deprecated)[^\n]*/)?.[0])
    ok('the <sahaj-atlas> element', /<sahaj-atlas[\s>]/.test(html))
    ok('the loader script tag', /<script[^>]*src="[^"]*auto\.js/.test(html))
  }
} finally {
  server.kill()
}

console.log(`\n${failures} failure(s)`)
process.exit(failures ? 1 : 0)
