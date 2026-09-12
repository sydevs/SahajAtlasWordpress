# Sahaj Atlas — WordPress plugin (developer guide)

Guidance for AI agents in this repository, including Claude Code, OpenAI Codex, and Cursor.

> `CLAUDE.md` is a symlink to this file, so every tool reads one guide that cannot drift.
> Claude-only configuration stays under `.claude/`.

This plugin installs the Sahaj Atlas widget — a map of free meditation classes — on a WordPress
site, and makes its pages indexable by search engines.

`docs/implementation-plan.md` has background context this file does not cover. Read it only if
the code and comments do not answer your question.

## Why this plugin exists

WordPress strips `<script>` tags from saved post content through `wp_kses`. This applies to every
role below Administrator, and to every Site Administrator on a multisite network. A plugin's own
output never passes through `wp_kses`, so a plugin is the only reliable way to install the widget.
13 of the 29 known client domains run self-hosted WordPress.

## Who uses it

One non-technical local volunteer manages each site, across 13 national organisations. This fact
drives the whole design:

- The plugin has a diagnostics panel.
- Setup is one button, not a set of instructions.
- Updates run automatically.
- The plugin exposes only one configuration setting.

## Principles — acceptance criteria, not goals

1. Prefer a WordPress core API over custom code — every custom line is a line someone must
   maintain against a WordPress release they cannot see.
2. Ship no build step: no npm, no webpack, no `node_modules`. See "The block — no build step" in
   the implementation plan.
3. Use no framework, service container, or abstraction layer. The plugin has seven
   responsibilities, listed in the implementation plan — question an eighth.
4. Treat configuration as a cost. The plugin allows one site setting (the API key) and two
   per-embed attributes (`map`, `atlas`). Add a setting only for a use case someone actually hit.
5. Fail loudly to the admin, never to the visitor.
6. Do not reimplement anything the widget already does — routing, translation, layout, errors,
   and reporting are its job.

## Decisions already taken — do not relitigate

`docs/implementation-plan.md` has the full decision table. The load-bearing decisions:

- The plugin owns one atlas page per site, created by a settings-screen button. The volunteer
  does not place a block — 4 of the 9 surveyed sites use a page builder where a block never
  appears.
- In-content embeds are a separate, secondary feature: a single class, no map, with an optional
  registration form, shipped as both a shortcode and a block.
- The editor shows a static placeholder — the widget cannot upgrade to a live map inside its
  iframe.
- The plugin takes over SEO on atlas pages, instead of feeding the site's own SEO plugin.
- The plugin ships as a public repo with GitHub Releases and Plugin Update Checker, since a
  manual zip upload gives no updates at all.
- Multisite is in scope: the plugin uses per-site `get_option`, never `get_site_option`.

## Traps already paid for

Each entry states the trap. Where a line number is given, the inline `⚠` comment there carries
the full story.

1. `auto.js` is an ES module. Use `wp_enqueue_script_module()`, not `wp_enqueue_script()` with
   `defer`. See embed.php:48.
2. Print an explicit `<sahaj-atlas></sahaj-atlas>` element. Core prints script modules in the
   footer on classic themes, and in `<head>` on block themes — but the loader refuses `<head>`.
   Core adopts an existing element wherever it sits, so the script tag's own position no longer
   matters.
3. Element placement now matters, though it did not before. A contained map draws inside its own
   element's box, so both templates print it in the page flow, right after the header. Do not use
   `wp_body_open` — that was correct only for the old fixed-overlay map, and now it would place
   the atlas above the header. The `wp_footer` hook stays as a fallback print, for a theme that
   runs neither template. This also fixes the old transform-ancestor hazard, since a contained map
   creates its own containing block.
4. Size `<sahaj-atlas>` with `display: block` and a definite height. This opts into a contained
   map: it draws inside its own box and stacking context, so the site header survives, and the map
   skips the compact-card question. SahajAtlasWeb#170 inverted the old rule — an unsized element
   now becomes `position: fixed; inset: 0` and covers the page, which is why the Atlas page had no
   header before #170.
5. `min-height` is not a height. Use a definite height and `display: block` instead — a custom
   element defaults to `inline` and cannot size itself. See embed.php:342, render.mjs:233,
   run.php:143.
6. Never run `wp_kses()` on markup this plugin generates. `safecss_filter_attr()`'s property
   allowlist has no `display` property, so it silently reduced `display:block;height:520px` to
   `height:520px`, breaking the block path while the shortcode path stayed fine. Sanitize only
   content someone else authored — our own markup takes no caller input.
7. Never call `get_header()` on a block theme — it falls through to theme-compat and prints a
   duplicate, 2010-era `<!DOCTYPE html>`. See atlas-page.php:14,20,31.
8. `get_footer()` is not `wp_footer()`. Always fire `wp_footer()` instead. See atlas-page.php:8,
   sahaj-atlas.php:93.
9. `redirect_canonical()` 301s deep links back to the page root. Suppress it when the **path**
   route is set, never on every atlas route — a query-routed URL is the page's own permalink plus
   a parameter, so core has nothing to strip, and widening the filter would switch a core
   behaviour off for any page load carrying `?atlas=`. See routing.php:116,121.
10. No translated string may run before `init` (WordPress 6.7) — not at file scope, in an
    activation hook, or on `plugins_loaded`. See sahaj-atlas.php:22.
11. One `_wp_page_template` value serves both theme kinds. Core strips the suffix automatically,
    so do not branch to "fix" it. See page.php:191.
12. A front-page atlas refuses path routing, or it would turn the host's own 404 page into the
    atlas. See routing.php:210, tests/contract.php:130.
13. An empty sitemap must return a 404, never an empty `<urlset>` or an index line. See
    sitemap.php:81, tests/sitemap.php:82.
14. `allowedDomains` splits on newlines, not commas. An empty list allows every origin — the
    documented default, not a refusal. Treat each entry as an exact host, never a wildcard suffix,
    and mirror `parseAllowedDomains()` / `isHostAllowed()` in SahajCloud instead of re-deriving
    them. See diagnostics.php:262,398, tests/domains.php.
15. Publish sitemap URLs only for this host. A shared key, or a mis-set `canonical.embed`, can add
    a foreign one. See sitemap.php:181, tests/sitemap.php:20.
16. Suppress the host's SEO plugin only after a successful fetch. Suppressing first, then finding
    the endpoint unreachable, leaves the page with no metadata at all — worse than leaving the
    original, generic metadata in place.
17. `sahaj_atlas_current_route()` answers for both routing shapes, so everything keyed on it —
    the SEO takeover, the `<title>` filter, the JSON-LD, the crawlable body — follows a
    query-routed deep link too. Reading `?atlas=` stays gated on the Atlas page: the parameter is
    one anyone can append to any URL on the site, and an ungated read would let an arbitrary page
    claim an atlas route's canonical. See routing.php:158,164,168.

## What is built

Every module below is implemented and tested. `pnpm test:all` runs the full gate: the syntax
check, the PHP suite, then the render checks.

| Module | Does |
| --- | --- |
| `includes/embed.php` | Resolves the page's one embed, builds the script URL, prints the element |
| `assets/atlas-page.{css,js}` | Sizes the element below the theme's header — the contained-map opt-in |
| `includes/page.php` | Owns the Atlas page and both template paths |
| `includes/routing.php` | Matches `parse_request`, reads `?atlas=`, and suppresses the canonical redirect |
| `includes/shortcode.php` | Runs `[sahaj_atlas]`, sharing the block's render body |
| `includes/settings.php` | Holds the one option, the settings screen, and the create-page button |
| `includes/diagnostics.php` | Runs the four checks |
| `includes/seo.php` | Takes over metadata and renders crawlable body content |
| `includes/sitemap.php` | Serves `/sahaj-atlas-sitemap.xml`, `robots.txt`, and the SEO-plugin index lines |
| `includes/updates.php` | Runs Plugin Update Checker against GitHub Releases |

Sitemaps ship (SahajCloud#651 supplied the endpoint that #650 requested). The plugin serves one
sitemap file, `/sahaj-atlas-sitemap.xml`, instead of four SEO-plugin adapters we mostly cannot
test — untested integration code tends to look correct and fail live. Each SEO system just points
at our file. `robots.txt` is the load-bearing line, read by every crawler regardless of which SEO
plugin runs. The Yoast and Rank Math index entries are only a convenience.

## Testing

Three lanes run on `@wp-playground/cli` (PHP in WebAssembly). This needs no Docker and no system
PHP.

| Lane | Command | Covers |
| --- | --- | --- |
| Syntax | `pnpm lint` | `token_get_all(…, TOKEN_PARSE)` over every PHP file |
| Behaviour | `pnpm test` | The behaviour suite, in a booted WordPress 6.7 / PHP 7.4 |
| Render | `pnpm test:render` | Real HTTP requests against a real server, per theme kind |

A new assertion is not finished until it has failed once. Reintroduce the real defect, watch it
fail, then restore the fix. This step matters: the first version of `tests/lint.php` passed every
file, including broken ones, and three early render assertions passed against a 404 page.

Three more traps apply here. Their inline `⚠` comments carry the full detail.

- wp-playground-cli discards stdout when a step fails. See tests/bootstrap.php:5.
- The `server` command ignores `preferredVersions`. Pass `--php` and `--wp` directly instead. See
  tests/render.mjs:86.
- Activate the plugin through a blueprint step. Do not call `activate_plugin()` after
  `wp-load.php`. See tests/bootstrap.php:42.
- `$_GET` is already slashed when a plugin reads it, so a fixture that assigns a raw value tests a
  request WordPress never delivers. Set it through `sahaj_set_query_route()`. See
  tests/run.php:242.
- One SEO endpoint answer serves every lane: `tests/fixtures/seo-answer.php`. The PHP suite requires
  it, and both render blueprints require it from the mounted plugin. Three copies of an upstream
  response shape diverge the first time that shape changes.

## Where the truth lives

| | |
| --- | --- |
| Host-facing widget contract | `docs/embedding.md` in sydevs/SahajAtlasWeb — the source of truth |
| What changes for hosts | `CHANGELOG.md` in SahajAtlasWeb |
| Loader behaviour | `src/loader/index.ts` in SahajAtlasWeb |
| Slot and compact-card rules | `src/lib/embed-slot.ts` in SahajAtlasWeb |
| SEO endpoint | `GET /api/atlas/seo` — SahajCloud PR #646, response types in `src/endpoints/responseTypes.ts` |
| Sitemap enumeration | `GET /api/atlas/sitemap` — SahajCloud PR #651 |
| Shared URL contract | `src/lib/atlas/atlas-url-contract.json` in sydevs/SahajCloud — public, CI diffs the raw file, no token needed |

Ask, do not guess, when these docs and the code disagree. The code is what ships.

## Conventions

- The plugin slug is `sahaj-atlas`, byte-identical in three places: the release zip's top-level
  directory, the installed folder, and Plugin Update Checker's third argument. Confirmed free on
  wordpress.org as of 2026-08-25.
- The text domain is `sahaj-atlas`, matching the slug.
- PHP follows WordPress coding standards, with real tabs — `.editorconfig` enforces this.
- Pass every attribute reaching markup through `esc_attr()` or `esc_url()`. The one exception is
  the SEO endpoint's `jsonLd` value, already escaped on arrival — echo it raw, never re-encode it.
