# Tests — traps for writing one

> `CLAUDE.md` is a symlink to this file.

The root `AGENTS.md` Testing section comes first: the lanes, the "failed once" rule, one lane
at a time, and the no-network guard. The traps below matter only while writing or changing a
test. Their inline `⚠` comments carry the full detail. Paths are from the repo root.

- Headless Chromium has no WebGL without software-GL flags, and Mapbox then refuses to mount,
  which reads exactly like the widget failing to boot. The browser lane passes the flags. See
  tests/browser.mjs.
- The measure lane proves what the script decides, never what a browser lays out. Its geometry is
  stubbed, so an assertion about real overlap belongs in the browser lane (#38, PR #44). That lane
  retires this one only once it covers the same decisions on every PR. The lane reads the script's
  load position out of `includes/embed.php`, so a fixture cannot keep modelling a page the plugin
  stopped serving. See `tests/measure.mjs`.
- The browser lane's cells run one after another, so CI deals them out with `--shard k/n`, the
  count set in `.github/workflows/browser.yml`. A cell's port comes from its place in its own
  shard's list. A new cell needs no workflow change, but a slow one lengthens a shard; `--list`
  and the `time` lines show where. Both CI timeouts are derived from the shard count, so changing it moves them too.
- Every browser-lane cell runs the render lane's shared seed first, so a cell seeding its own host
  page needs a slug no page in that seed holds. `wp_insert_post()` answers a duplicate with
  `<slug>-2` and says nothing, and the cell then measures whichever page kept the slug (#100). See
  `BB_MODULE_PAGE` in `tests/browser.mjs`.
- The lane's CI summary is `browser-summary.txt`, which the lane appends to as it runs; the workflow
  prints that file and derives nothing from stdout. A shard the step timeout kills has its verdicts
  on disk already, so a new verdict kind has to be written there, or CI will not show it.
- wp-playground-cli discards stdout when a step fails. See `tests/bootstrap.php`.
- Pin versions with the blueprint's `preferredVersions`. That key is each lane's default, and
  `tests/versions.mjs` overrides it only when `SAHAJ_ATLAS_PHP` or `SAHAJ_ATLAS_WP` is set, so
  editing a blueprint changes the lane it belongs to and nothing else. A `--php` or `--wp` flag
  changes nothing. Under @wp-playground/cli 3.1, `server` ignores `--php` and `--wp` when given a
  blueprint, and a blueprint without the key boots the latest WordPress on PHP 8.5. See `check()`
  in `tests/render.mjs`.
- A command with no blueprint takes the CLI's own default instead, PHP 8.3, not the fleet's floor.
  `pnpm lint` and `pnpm test`'s report fallback are the two, and both pass `--php 7.4`. A
  syntax lane on 8.3 passes `?->` and every other PHP 8 construct a client site cannot parse.
- Activate the plugin through a blueprint step. Do not call `activate_plugin()` after
  `wp-load.php`. See `tests/bootstrap.php`.
- `$_GET` is already slashed when a plugin reads it, so a fixture that assigns a raw value tests a
  request WordPress never delivers. Set it through `sahaj_set_query_route()` in `tests/run.php`.
- `update_option( 'permalink_structure', … )` does not reach `WP_Rewrite`, which caches the
  structure in `init()`. Set it through `sahaj_set_permalink_structure()` in `tests/run.php`.
- The SEO endpoint's answers serve every lane from one file: `tests/fixtures/seo-answer.php`, a
  region route and a root. The PHP suite requires it, and both render blueprints require it from the
  mounted plugin. Three copies of an upstream response shape diverge the first time that shape
  changes. Seed the root under every route the plugin asks about — the endpoint answers `/` and a
  bare view route alike, but the cache is keyed on the route as asked.
- The zip lane (`tests/zip.mjs`) mounts the unzipped plugin, so `tests/` is absent from it. Anything
  a blueprint needs from `tests/` is mounted from the source tree at `/sahaj-tests`, never required
  from the plugin directory. A test that must see a file the zip lacks is the point of that lane.
