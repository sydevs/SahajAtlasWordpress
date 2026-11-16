/**
 * The PHP and WordPress versions a lane boots. The floor is the default, so `pnpm test` is the
 * floor lane unless a run asks otherwise:
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

export const FLOOR = { php: '7.4', wp: '6.7' }

/** @returns {{php: string, wp: string}} */
export function versions() {
  return {
    php: process.env.SAHAJ_ATLAS_PHP || FLOOR.php,
    wp: process.env.SAHAJ_ATLAS_WP || FLOOR.wp,
  }
}

/**
 * @param {string} blueprintPath
 * @returns {string} A temporary copy whose `preferredVersions` are this run's.
 */
export function blueprintFor(blueprintPath) {
  const blueprint = JSON.parse(readFileSync(blueprintPath, 'utf8'))
  blueprint.preferredVersions = versions()
  const out = join(mkdtempSync(join(tmpdir(), 'sahaj-atlas-')), basename(blueprintPath))
  writeFileSync(out, JSON.stringify(blueprint))
  return out
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  console.log(blueprintFor(process.argv[2]))
}
