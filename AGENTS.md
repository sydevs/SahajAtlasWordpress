# Sahaj Atlas — WordPress plugin (developer guide)

Guidance for AI coding agents — Claude Code, OpenAI Codex, Cursor, and other
AGENTS.md-compatible tools — working in this repository.

> `CLAUDE.md` is a symlink to this file, so both tool ecosystems read one guide that cannot
> drift. Claude-specific configuration (settings, the workflow plugin) stays in `.claude/`.

A WordPress plugin that installs the **Sahaj Atlas** widget — a map of free meditation classes —
onto a site, and makes its pages indexable.

**Read `docs/brief.md` and `docs/implementation-plan.md` before writing code.** They carry facts
that live in two other repos and are not derivable from here.

## Why this plugin exists

WordPress strips `<script>` from saved post content via `wp_kses` for **every role below
Administrator, and for every Site Administrator on multisite**. A plugin's own output never passes
through `wp_kses`, so a plugin is the only reliable install path. **13 of the 29 known client
domains are self-hosted WordPress.**

## Who uses it

**One non-technical local volunteer per site**, in 13 different national organisations. This is the
single most important fact about the design. It is why there is a diagnostics panel, why setup is a
button rather than instructions, why updates must be automatic, and why the configuration surface is
one setting.

## Principles — acceptance criteria, not aspirations

1. **Prefer a WordPress core API over anything you would write.** Every line here is a line somebody
   maintains against a WP release they cannot see.
2. **No build step.** No npm, no webpack, no `node_modules` in the shipped plugin. Confirmed
   achievable — see the plan's "The block — no build step".
3. **No framework, no service container, no abstraction layer.** Seven responsibilities, listed in
   the plan. If an eighth appears, question it.
4. **Configuration is a cost.** The permitted surface is *one* site setting (the API key) and *two*
   per-embed attributes (`map`, `atlas`). **Adding one needs a use case somebody actually hit.**
5. **Fail loudly to the admin, never to the visitor.**
6. **Do not reimplement anything the widget already does** — routing, i18n, layout, errors,
   reporting are all its job.

## Decisions already taken — do not relitigate

See the table in `docs/implementation-plan.md`. The load-bearing ones:

- **The plugin owns ONE atlas page per site**, created by a settings-screen button. Not a block the
  volunteer places — four of the nine surveyed sites use a page builder where a block never appears.
- **In-content embeds** (a single class, a registration form, map-less) are a *separate, secondary*
  feature: shortcode **and** block.
- **Editor shows a static placeholder.** The widget cannot upgrade inside the editor's iframe.
- **Take over SEO on atlas pages**, do not feed the site's SEO plugin.
- **Public repo + GitHub Releases + Plugin Update Checker.** Manual zip upload gives no updates at
  all, which fails the requirement on its own.
- **Multisite is in play.** Per-site options (`get_option`), never `get_site_option`.

## Traps already paid for

- ⚠ **`auto.js` is an ES module.** `wp_enqueue_script_module()` only — `wp_enqueue_script` with
  `defer` is a hard break, not a fallback.
- ⚠ **Print an explicit `<sahaj-atlas></sahaj-atlas>`.** Core prints script modules in the footer on
  classic themes and in `<head>` on block themes, and the loader *refuses* `<head>`; an element that
  already exists is adopted wherever it is, so where the script tag lands stops mattering.
  ⚠ **Where the ELEMENT sits now matters, though, and it used to not.** A contained map draws in its
  element's box, so both templates print it in the flow *after* the header. `wp_body_open` was right
  while the map was a fixed overlay and is wrong now — it would put the atlas above the header. The
  `wp_footer` hook keeps the print as a last-resort fallback for a theme that runs neither template.
  The old transform-ancestor hazard went with it: a contained map establishes its own containing
  block.
- ⚠ **SIZE `<sahaj-atlas>` — this inverted with SahajAtlasWeb#170 and the old rule is now wrong.**
  Giving the element `display: block` and a **definite height** is the opt-in for a *contained* map:
  it draws inside that box, in its own stacking context, and the site header above it survives. It
  is also never asked the compact-card question. Unsized, the map is `position: fixed; inset: 0` and
  covers whatever is on the page — which is why the Atlas page had no header until #170.
- ⚠ **`min-height` is not a height, and this was a live defect.** The widget fills its element with
  `height: 100%`, which needs a *definite* height to resolve against; `min-height` sizes the element
  on screen and leaves the widget nothing to fill, so it **refuses the box and covers the browser
  window**. `display: block` matters as much — a custom element is `display: inline` and cannot take
  a height at all.
- ⚠ **Do not `wp_kses()` markup this plugin generated.** `safecss_filter_attr()`'s property
  allowlist has no `display`, so it silently reduced `display:block;height:520px` to `height:520px`
  and left the block path rendering an unsized element while the shortcode path was fine. Sanitize
  content somebody else authored; our own element markup has no caller input in it.
- ⚠ **`get_header()` must never run on a block theme.** It falls through to
  `wp-includes/theme-compat/header.php` and emits a second, 2010-era `<!DOCTYPE html>`.
- ⚠ **`get_footer()` ≠ `wp_footer()`.** Skip the former; the latter must always fire.
- ⚠ **`redirect_canonical()` 301s every deep link back to the page root.** Suppress it when our route
  var is set.
- ⚠ **Nothing translated may run before `init`** (WP 6.7). Not at file scope, not in
  `register_activation_hook`, not on `plugins_loaded`.
- ⚠ **One `_wp_page_template` value serves both theme kinds**, though it reads like it cannot: a
  classic theme needs `sahaj-atlas-page.php`, a block theme matches the suffix-less slug. Core runs
  every candidate through `_strip_template_file_suffix()` in `resolve_block_template()`. Verified in
  the core source and asserted end to end. Do not "fix" it by branching.
- ⚠ **A front-page atlas cannot use path routing, and refusing is correct.** With no path prefix,
  "everything below the atlas page" is every URL on the site, and half of those resolve *after*
  `parse_request` — the plugin would turn the host's 404 page into the atlas. The shared URL
  contract does publish root-mount canonicals, so this is a shape SahajCloud can be configured to
  emit: the refusal is only safe because the diagnostics panel says why.
- ⚠ **An empty sitemap is a 404, never an empty `<urlset>`, and no index line at all.** Empty means
  the fetch failed or ownership is not set up — both temporary. A valid-but-empty sitemap tells a
  crawler we affirmatively have nothing, and an index entry pointing at a 404 is a broken link
  handed straight to one. Both are the state a site is in for five minutes after any failed fetch.
- ⚠ **`allowedDomains` is NEWLINE-separated and an EMPTY list ALLOWS every origin.** Both were
  guessed wrong first, and the panel is what a volunteer trusts instead of emailing us, so a
  confident wrong red is worse than no check. The field is a textarea (commas merely tolerated), so
  a comma-only split read a real two-domain client as one impossible domain; and empty is the
  documented backward-compatible "allow all" upstream, not a refusal. `*.example.org` matches
  subdomains but never the apex, and a bare `example.org` matches that host alone — treating every
  entry as a suffix silently grants the wildcard the operator did not write. Mirror
  `parseAllowedDomains()` / `isHostAllowed()` in SahajCloud `src/plugins/usage/originEnforcement.ts`;
  do not re-derive them.
- ⚠ **Publish only URLs on THIS host.** The endpoint answers what the *client* owns, and an owned
  subtree is not by definition served from the domain asking — one key shared between two sites, or
  a mis-set `canonical.embed`, puts a foreign host in the list, and a sitemap naming another domain
  is a cross-site claim.
- ⚠ **Suppress the host's SEO plugin only AFTER a successful fetch.** Suppressing first and then
  finding the endpoint unreachable leaves the page with no metadata at all — strictly worse than the
  generic metadata being replaced.

## What is built, and what is not

Everything in "Responsibilities" below is implemented and covered. Run the whole gate with
`pnpm test:all` — syntax check, the PHP suite, then the render checks.

| Module | Does |
| --- | --- |
| `includes/embed.php` | Resolves the page's ONE embed, builds the script URL, prints the element |
| `assets/atlas-page.{css,js}` | Sizes the element below the theme's header — the contained-map opt-in |
| `includes/page.php` | Owns the Atlas page and both template paths |
| `includes/routing.php` | `parse_request` matching + canonical-redirect suppression |
| `includes/shortcode.php` | `[sahaj_atlas]`, sharing the block's render body |
| `includes/settings.php` | The one option, the screen, the create-page button |
| `includes/diagnostics.php` | The four checks |
| `includes/seo.php` | The metadata takeover and crawlable body content |
| `includes/sitemap.php` | `/sahaj-atlas-sitemap.xml`, `robots.txt`, and the SEO-plugin index lines |
| `includes/updates.php` | Plugin Update Checker against GitHub Releases |

**Sitemaps ship** (SahajCloud#651 supplied the enumeration endpoint that #650 asked for). ⚠ **The
plugin serves ONE sitemap of its own, at `/sahaj-atlas-sitemap.xml`, rather than feeding four SEO
plugins' sitemap systems.** An adapter per system means four renderer APIs — Yoast's registered
callback writing XML through a global, AIOSEO's `stdClass` rows, Rank Math's own shape, core's
`WP_Sitemaps_Provider` subclass — of which three cannot be exercised here at all. Integration code
written from memory against an API nobody has to hand is the sort that looks right and 500s on
somebody's live site. Serving our own file means the document is fully covered, and each system then
only has to *point* at it, which is a string in every case. **`robots.txt` is the load-bearing
line**: every crawler reads it, with any SEO plugin or none, so the Yoast and Rank Math index entries
are a convenience rather than the discovery path.

## Testing

Three lanes, all on `@wp-playground/cli` — PHP in WebAssembly, so no Docker and no system PHP.

| Lane | Command | Covers |
| --- | --- | --- |
| Syntax | `pnpm lint` | `token_get_all(…, TOKEN_PARSE)` over every PHP file |
| Behaviour | `pnpm test` | The behaviour suite, in a booted WordPress 6.7 / PHP 7.4 |
| Render | `pnpm test:render` | Real HTTP against a real server, per theme kind |

⚠ **A new assertion is not finished until it has FAILED.** Reintroduce the defect at the site that
would really cause it, watch it go red, then restore. Every guard added so far has been through
this, and it is not paranoia — the *first* version of `tests/lint.php` passed every file including
deliberately broken ones, and three of the first render assertions passed against a page that was a
404.

⚠ **`run-blueprint`'s `runPHP` step discards stdout.** A fatal surfaces only as `exit code 255` with
stdout and stderr both empty. `tests/bootstrap.php` buffers everything and writes it through the
(bidirectional) mount, and `tests/report.php` reads it back. Do not add an `echo` and expect to see
it.

⚠ **`preferredVersions` in a blueprint is ignored by the `server` command** — pass `--php` / `--wp`.
It silently booted PHP 8.3 / WordPress latest while the file asked for 7.4 / 6.7.

⚠ **The plugin must be activated by a blueprint STEP, not by `activate_plugin()` after
`wp-load.php`.** The latter loads the plugin's file when `init` has already fired, so
`sahaj_atlas_init()` never runs and every registration is silently absent — the suite then tests a
half-loaded plugin and reports four unrelated-looking failures.

## Where the truth lives

| | |
| --- | --- |
| Host-facing widget contract | `docs/embedding.md` in **sydevs/SahajAtlasWeb** — the source of truth |
| What changes under hosts | `CHANGELOG.md` in SahajAtlasWeb |
| Loader behaviour | `src/loader/index.ts` in SahajAtlasWeb |
| Slot / compact-card rules | `src/lib/embed-slot.ts` in SahajAtlasWeb |
| SEO endpoint | `GET /api/atlas/seo` — SahajCloud PR #646; response types in `src/endpoints/responseTypes.ts` |
| Sitemap enumeration | `GET /api/atlas/sitemap` — SahajCloud PR #651 |
| Shared URL contract | `src/lib/atlas/atlas-url-contract.json` in **sydevs/SahajCloud** (public; CI diffs the raw URL, no token) |

**Ask rather than infer** where these docs and the code disagree. The code is what ships.

## Conventions

- **Plugin slug is `sahaj-atlas`** and must be byte-identical in three places: the release zip's
  top-level directory, the installed folder, and Plugin Update Checker's third argument. Verified
  free on wordpress.org 2026-08-25.
- Text domain `sahaj-atlas`, matching the slug.
- PHP follows WordPress coding standards (real tabs). `.editorconfig` covers it.
- Every attribute reaching markup goes through `esc_attr()` / `esc_url()`. The one exception is the
  SEO endpoint's `jsonLd`, which arrives **already escaped** — echo it raw, never re-encode it.
