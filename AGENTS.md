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
- The plugin exposes three configuration settings: a key to paste, a checkbox, and where the
  Atlas page opens.

## Principles — acceptance criteria, not goals

1. Prefer a WordPress core API over custom code — every custom line is a line someone must
   maintain against a WordPress release they cannot see.
2. Ship no build step: no npm, no webpack, no `node_modules`. See "The block — no build step" in
   the implementation plan.
3. Use no framework, service container, or abstraction layer. The plugin has seven
   responsibilities, listed in the implementation plan — question an eighth.
4. Treat configuration as a cost. The plugin allows three site settings (the API key, the
   Atlas page's description opt-out, and where the Atlas page opens) and three per-embed attributes
   (`map`, `atlas`, `ratio`). Add a setting only for a use case someone actually hit — #16 is the shape
   that qualifies, and the national sites asking to open at their own country is the third. The
   bar does not move because a fourth one would be convenient.
5. Fail loudly to the admin, never to the visitor.
6. Do not reimplement anything the widget already does — routing, translation, layout, errors,
   and reporting are its job.

## Decisions already taken — do not relitigate

`docs/implementation-plan.md` has the full decision table. The load-bearing decisions:

- The plugin owns one atlas page per site, created by a settings-screen button. The volunteer
  does not place a block — 4 of the 9 surveyed sites use a page builder where a block never
  appears.
- In-content embeds are a separate, secondary feature, shipped as a shortcode, a block and a
  Beaver Builder module — the module because one client site builds with it and asked (#63).
  They show the map by default (owner, 2026-10-05), at the column's full width and square (width to
  height 1:1; `ratio` changes it, owner 2026-10-06), capped at 80% of the screen, and send `gestures=cooperative` so the page scrolls past them. `map="false"`
  gives the list, a single class, or its registration form.
- The editor shows a static placeholder — the widget cannot upgrade to a live map inside its
  iframe.
- The plugin takes over SEO on atlas pages, instead of feeding the site's own SEO plugin. The
  Atlas page's root view is included, by default, with one checkbox to hand it back — the one
  atlas URL a host's own SEO plugin has ever seen.
- The plugin ships as a public repo with GitHub Releases and Plugin Update Checker, since a
  manual zip upload gives no updates at all.
- Multisite is in scope: the plugin uses per-site `get_option`, never `get_site_option`.

## Traps already paid for

Each entry states the trap. Where a line number is given, the inline `⚠` comment there carries
the full story.

1. `auto.js` is an ES module. Use `wp_enqueue_script_module()`, not `wp_enqueue_script()` with
   `defer`. See embed.php:70.
2. Print an explicit `<sahaj-atlas></sahaj-atlas>` element. Core prints script modules in the
   footer on classic themes, and in `<head>` on block themes — but the loader refuses `<head>`.
   Core adopts an existing element wherever it sits, so the script tag's own position no longer
   matters.
3. Element placement now matters, though it did not before. A contained map draws inside its own
   element's box, so both templates print it in the page flow, right after the header. Do not use
   `wp_body_open` — that was correct only for the old fixed-overlay map, and now it would place
   the atlas above the header. This also fixes the old transform-ancestor hazard, since a contained
   map creates its own containing block.
   Three prints exist, and the first to run wins: the plugin's template, then `the_content` for a
   template this plugin did not supply, then `wp_footer`. The last one lands after the theme's
   footer, where the sizing script measures the element's top at the document's full height and the
   map computes to nothing — so it is a last resort, and diagnostics turns red on it rather than
   reporting the page healthy. A caller that runs `the_content` without rendering the page body,
   `wp_trim_excerpt()` above all, must not spend the one print. See sahaj-atlas.php:129, embed.php:441,467.
4. Size `<sahaj-atlas>` with `display: block` and a definite height. This opts into a contained
   map: it draws inside its own box and stacking context, so the site header survives, and the map
   skips the compact-card question. SahajAtlasWeb#170 inverted the old rule — an unsized element
   now becomes `position: fixed; inset: 0` and covers the page, which is why the Atlas page had no
   header before #170.
5. `min-height` is not a height. Use a definite height and `display: block` instead — a custom
   element defaults to `inline` and cannot size itself. See embed.php:560, render.mjs:401,
   run.php:143.
6. Never run `wp_kses()` on markup this plugin generates. `safecss_filter_attr()`'s property
   allowlist has no `display` property, so it silently reduced `display:block;height:520px` to
   `height:520px`, breaking the block path while the shortcode path stayed fine. Sanitize only
   content someone else authored — our own markup takes no caller input.
7. Never call `get_header()` on a block theme — it falls through to theme-compat and prints a
   duplicate, 2010-era `<!DOCTYPE html>`. See atlas-page.php:14,20,31.
8. `get_footer()` is not `wp_footer()`. Always fire `wp_footer()` instead. See atlas-page.php:8,
   sahaj-atlas.php:129.
9. `redirect_canonical()` 301s deep links back to the page root. Suppress it when the **path**
   route is set, never on every atlas route — the contract publishes `/?p=42&atlas=…` mounts, and
   core's 301 to the pretty permalink is what carries a query-routed visitor to the canonical URL,
   `?atlas=` intact. The reason is not a site-wide cost: `?atlas=` is refused off the Atlas page
   already. See routing.php:116,121,127.
10. No translated string may run before `init` (WordPress 6.7) — not at file scope, in an
    activation hook, or on `plugins_loaded`. See sahaj-atlas.php:22.
11. One `_wp_page_template` value serves both theme kinds. Core strips the suffix automatically,
    so do not branch to "fix" it. See page.php:275.
12. A front-page atlas refuses path routing, or it would turn the host's own 404 page into the
    atlas. See routing.php:227, tests/contract.php:130.
13. An empty sitemap must return a 404, never an empty `<urlset>` or an index line. See
    sitemap.php:121, tests/sitemap.php:82.
14. `allowedDomains` splits on newlines, not commas. An empty list allows every origin — the
    documented default, not a refusal. Treat each entry as an exact host, never a wildcard suffix,
    and mirror `parseAllowedDomains()` / `isHostAllowed()` in SahajCloud instead of re-deriving
    them. See diagnostics.php:296,954, tests/domains.php.
15. Publish sitemap URLs only for this host. A shared key, or a mis-set `canonical.embed`, can add
    a foreign one. The same guard decides whether the root view may take the Atlas page over at
    all: a root answer whose canonical names another domain is a failed fetch, not a tag to drop,
    because `rel_canonical` is gone by the time the tag is printed. A region may canonicalise
    elsewhere. The root may not. See sitemap.php:227,249, seo.php:89,147, tests/sitemap.php:20.
16. Suppress the host's SEO plugin only after a successful fetch. Suppressing first, then finding
    the endpoint unreachable, leaves the page with no metadata at all — worse than leaving the
    original, generic metadata in place.
17. `sahaj_atlas_current_route()` answers for both routing shapes, so everything keyed on it —
    the SEO takeover, the `<title>` filter, the JSON-LD, the crawlable body — follows a
    query-routed deep link too. Reading `?atlas=` stays gated on the Atlas page: the parameter is
    one anyone can append to any URL on the site, and an ungated read would let an arbitrary page
    claim an atlas route's canonical. See routing.php:164,170,174.
18. A bare view route — `/search`, `/calendar`, `/filters`, `/online`, `/share` — is a view of the
    atlas root, and the endpoint answers it with the root document. Decide that from `type` in the
    answer. Never keep a copy of that segment list here; it drifts the day a view is added
    upstream, the same reason `sahaj_atlas_seo_locale()` keeps no copy of the locale list. See
    seo.php:83.
19. An empty route on the Atlas page is the root view, not "no route". That includes a `?atlas=`
    the sanitiser refused, which falls back to the root rather than to nothing — the page is the
    root view either way, and the refused value must reach neither the endpoint nor the canonical.
    See seo.php:60, tests/seo.php:303.
20. A non-empty `pre_get_document_title` return short-circuits `wp_get_document_title()` before
    every sanitising step below it — core's own `esc_html()` included — and
    `_wp_render_title_tag()` echoes the result raw. Escape inside the filter, or nothing does.
    See seo.php:229.
21. Enqueue `assets/atlas-page.js` in the footer. `<head>` has neither the element it measures nor
    `document.body`, so both the first measure and the observer are lost there — silently. See
    embed.php:598, atlas-page.js:160.
22. A header the theme takes out of flow occupies nothing for the element's own top to measure, so
    `assets/atlas-page.js` measures that header too, and the offset it writes is a **margin**, never
    padding. The offset walks to its answer over several frames, and asks for them itself — no
    resize event fires for a margin it wrote, and the body observer is not guaranteed to be
    attached. Two bounds are load-bearing. The offset is clamped, or a theme that refuses the margin
    spins the measurement loop forever. And a header reaching past half the screen is refused
    outright, since handing that much away drops the atlas under the widget's map floor and the
    visitor gets the compact card — which is the defect #35 fixed, by another route. See
    atlas-page.css:31, atlas-page.js:26,64,86,122,127,138.
23. Owning `<title>` takes a priority *and* a printer. Yoast registers
    `pre_get_document_title` at 15, with a callback that takes no argument and so discards
    whatever ran before it — any priority below the highest vendor's loses. It also removes core's
    three title printers and emits `<title>` from its own `wpseo_head` presenter, so silencing
    that presenter leaves a classic theme with no `<title>` element at all. Both halves are
    invisible from inside this plugin: the page returns 200 with a full `<head>` either way. See
    seo.php:102,188, tests/fixtures/fake-yoast.php.
24. Wrapping a string in `__()` delivers nothing on its own. Three separate pieces have to be in
    place before any locale sees a translation, and none of them implies the others. `Domain Path`
    is a hint to tooling and loads nothing. `load_plugin_textdomain()` is what makes the registry
    look inside the plugin at all — without it only `WP_LANG_DIR/plugins/` is searched, where a
    wordpress.org language pack would land, and this plugin ships from GitHub Releases.
    `wp_set_script_translations()` covers a second, independent reader: `wp.i18n` reads a `.json`
    keyed on an md5 of the script's path, never the `.mo`. Each piece fails silently in English.
    See sahaj-atlas.php:108, embed.php:630, tests/i18n.php.
25. The sitemap's address follows the permalink shape, and two shapes break it. An `index.php`
    structure needs the prefix core keeps in `$wp_rewrite->root`, and cannot publish a `Sitemap:`
    line at all, so check 6 warns and names the line to paste. A plain structure can serve nothing,
    so both index entries and the `robots.txt` line are withheld — `/?robots=1` reaches that filter
    with no rewrite rules, so it needs the guard too. The serve guard still needs none. Keep one
    composer, and one `sahaj_atlas_sitemap_is_servable()` beside it — anything that spells either
    answer a second time is free to disagree with it. See sitemap.php:62,77,86,287,333,
    diagnostics.php:545,564.
26. A Beaver Builder module must set `'editor_export' => false`. On publish, Beaver Builder renders
    every exportable module into `post_content` and strips `style="…"` on the way, so the exported
    element loses `display:block` and its `aspect-ratio` — and by trap 4 an unsized element covers
    the whole viewport. Nothing shows while the builder is active, because the layout is what
    renders; the overlay appears the day someone deactivates the plugin that was holding it back.
    It also leaves a second element beside any shortcode. See modules/sahaj-atlas/sahaj-atlas.php:42.
27. Dropping a theme's hero does not reclaim the space it held, so the fix is two of the theme's
    own switches: the filter that yields no image, and the class whose rule releases the box. Which
    switch, and why not the shorter route, are both load-bearing. See page.php:193,218.
28. The resolve on `template_redirect` sees only `post_content` and our own Beaver Builder module.
    A shortcode in a builder's text module, Elementor's shortcode widget, a sidebar or a
    theme-builder template renders all the same, so it claims the page as it renders — but only on
    a request that resolved nothing, and only while core's script-module print is still ahead.
    Before that, such a page printed nothing and told its editor another atlas "came first". See
    embed.php:85,91, shortcode.php:58.

## Sitemaps

Sitemaps ship (SahajCloud#651 supplied the endpoint that #650 requested). The plugin serves one
sitemap file, `/sahaj-atlas-sitemap.xml`, instead of four SEO-plugin adapters we mostly cannot
test — untested integration code tends to look correct and fail live. Each SEO system just points
at our file. `robots.txt` is the load-bearing line, read by every crawler regardless of which SEO
plugin runs. The Yoast and Rank Math index entries are only a convenience.

Load-bearing, and not always writable. Core serves a virtual `robots.txt` only with rewriting on
and WordPress at the domain root, and never over a real file. Diagnostics check 6 names each case
and the line to paste; the ⚠ on `sahaj_atlas_robots_txt()` is the pointer back.

## Testing

Four lanes run on `@wp-playground/cli` (PHP in WebAssembly). This needs no Docker and no system
PHP. The fifth runs the one shipped script in plain node.

| Lane | Command | Covers |
| --- | --- | --- |
| Browser | `pnpm test:browser` | The production widget in Chromium, in every free theme the fleet runs, plus page builders and hostile conditions. Local only; needs the network, `SAHAJ_ATLAS_TEST_KEY` (the "Sahaj Atlas (Local Test Key)" client's key, from a SahajCloud admin) in `.env.claude.local`, and Chromium (`pnpm exec playwright-core install chromium`, once). `--only <cell,…>` runs a subset, `--list` names them. `SAHAJ_ATLAS_WIDGET_FROM=<preview origin>` judges a SahajAtlasWeb PR's Cloudflare preview instead of production. |

The browser lane is the one that sees what a theme's CSS,
the sizing script and the widget do to the page once a browser runs it, which is where every
live defect so far has been (#36, SahajAtlasWeb#235, SahajAtlasWeb#236). It prints three verdicts: `FAIL`
is the plugin's, and fails the run; `WIDGET` is a finding about the production widget, SahajAtlasWeb's to
settle — check its open tickets before filing; `KNOWN` is a fit check in a cell whose exposure is already ticketed. Invariants —
the page is served, PHP never reaches SahajCloud — fail in every cell. Screenshots land in
`tests/screenshots/`, gitignored.

A new assertion is not finished until it has failed once. Reintroduce the real defect, watch it
fail, then restore the fix. This step matters: the first version of `tests/lint.php` passed every
file, including broken ones, and three early render assertions passed against a 404 page.

More traps apply here. Their inline `⚠` comments carry the full detail.

- Run one lane at a time. `pnpm test` deletes `.test-network.txt`, the log every lane's
  no-network assertion reads, so a lane running beside it fails that assertion for no reason.
- Every lane refuses outbound HTTP by default, through an mu-plugin each blueprint writes. A lane
  that needs an answer stubs it or seeds the transient; an unstubbed call is recorded and fails the
  run. Never reach the real endpoint to make a lane pass. A request to the instance's own host is
  let through, and is not a way out of it — the diagnostics loopback check reads this server's own
  Atlas page. See tests/no-network.php. The browser lane is the exception for the **browser** only:
  the theme comes from wordpress.org and the widget from production, while PHP stays offline and
  the cell's last assertion proves it. See tests/browser.mjs.

Traps that bind only while writing or changing a test live in `tests/AGENTS.md`.

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
- **A `.po` file is the only translation anyone commits.**
- Pass every value reaching markup through the escape its sink wants: `esc_attr()` for an
  attribute, `esc_url()` for a URL, `esc_html()` for text — the `<title>` element included. The one
  exception is the SEO endpoint's `jsonLd` value, already escaped on arrival — echo it raw, never
  re-encode it.
- **Merging a version bump to `main` is the release.** Change the version only in a release PR,
  and never push a tag to cut one.
