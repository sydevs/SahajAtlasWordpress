/**
 * The PHP and WordPress versions a lane boots. A blueprint's own `preferredVersions` is the
 * default, and the environment overrides it, so `pnpm test` is the floor lane unless a run asks
 * otherwise:
 *
 *   SAHAJ_ATLAS_PHP=8.3 SAHAJ_ATLAS_WP=latest pnpm test
 *
 * ⚠ The versions have to ride in the blueprint's `preferredVersions`. Under @wp-playground/cli 3.1
 * `server` and `run-blueprint` ignore `--php` and `--wp` when given a blueprint, so a flag changes
 * nothing and the lane reports on the wrong runtime without saying so.
 *
 * Run as a script, it writes a copy of a blueprint with the versions applied and prints the path:
 *
 *   node tests/versions.mjs tests/blueprint.json
 */
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// Reached only by a blueprint that names no version of its own. All three in tests/ name both.
const FLOOR = { php: '7.4', wp: '6.7' }

/**
 * @param {{php?: string, wp?: string}} [preferred] The blueprint's own `preferredVersions`.
 * @returns {{php: string, wp: string}}
 */
function versions(preferred = {}) {
  return {
    php: process.env.SAHAJ_ATLAS_PHP || preferred.php || FLOOR.php,
    wp: process.env.SAHAJ_ATLAS_WP || preferred.wp || FLOOR.wp,
  }
}

/**
 * @param {string} blueprintPath
 * @returns {{path: string, php: string, wp: string}} A temporary copy, and the versions it names.
 */
export function blueprintFor(blueprintPath) {
  const blueprint = JSON.parse(readFileSync(blueprintPath, 'utf8'))
  const applied = versions(blueprint.preferredVersions)
  blueprint.preferredVersions = applied
  const out = join(mkdtempSync(join(tmpdir(), 'sahaj-atlas-')), basename(blueprintPath))
  writeFileSync(out, JSON.stringify(blueprint))
  return { path: out, ...applied }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  console.log(blueprintFor(process.argv[2]).path)
}
