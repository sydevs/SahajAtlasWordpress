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
  retires this one only once it runs in CI and covers the same decisions; it is local-only, so it
  does not. The lane reads the script's load position out of `includes/embed.php`, so a fixture
  cannot keep modelling a page the plugin stopped serving. See tests/measure.mjs:9,13,30.
- wp-playground-cli discards stdout when a step fails. See tests/bootstrap.php:5.
- Pin versions with the blueprint's `preferredVersions`. That key is each lane's default, and
  `tests/versions.mjs` overrides it only when `SAHAJ_ATLAS_PHP` or `SAHAJ_ATLAS_WP` is set, so
  editing a blueprint changes the lane it belongs to and nothing else. A `--php` or `--wp` flag
  changes nothing. Under @wp-playground/cli 3.1, `server` ignores `--php` and `--wp` when given a
  blueprint, and a blueprint without the key boots the latest WordPress on PHP 8.5. See
  tests/render.mjs:110.
- A command with no blueprint takes the CLI's own default instead, PHP 8.3, not the fleet's 7.4
  floor. `pnpm lint` and `pnpm test`'s report fallback are the two, and both pass `--php 7.4`. A
  syntax lane on 8.3 passes `?->` and every other PHP 8 construct a client site cannot parse.
- Activate the plugin through a blueprint step. Do not call `activate_plugin()` after
  `wp-load.php`. See tests/bootstrap.php:42.
- `$_GET` is already slashed when a plugin reads it, so a fixture that assigns a raw value tests a
  request WordPress never delivers. Set it through `sahaj_set_query_route()`. See
  tests/run.php:387.
- `update_option( 'permalink_structure', … )` does not reach `WP_Rewrite`, which caches the
  structure in `init()`. Set it through `sahaj_set_permalink_structure()`. See tests/run.php:409.
- The SEO endpoint's answers serve every lane from one file: `tests/fixtures/seo-answer.php`, a
  region route and a root. The PHP suite requires it, and both render blueprints require it from the
  mounted plugin. Three copies of an upstream response shape diverge the first time that shape
  changes. Seed the root under every route the plugin asks about — the endpoint answers `/` and a
  bare view route alike, but the cache is keyed on the route as asked.
