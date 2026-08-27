> **Status — 2026-08-25: Phases 1 and 2 are implemented and pushed.**
>
> This file is the plan as written *before* the work, kept for its reasoning — why the owned page
> beat block-and-shortcode, why override beat feeding an SEO plugin, what the fleet survey found.
> **It is not a status document, and where it disagrees with the code the code is right.**
> `CLAUDE.md` carries what was actually built, the traps found while building it, and how to run
> the three test lanes.
>
> Two deliberate departures from this plan, both argued in `CLAUDE.md`:
>
> - **Sitemaps are not implemented.** `/api/atlas/seo` answers one route at a time and nothing
>   enumerates them; composing the URLs here would be a second implementation of the canonical rule.
>   Tracked as SahajCloud#650.
> - **A front-page atlas refuses path routing.** The shared URL contract publishes root-mount
>   canonicals, so it is a shape the CMS can emit — but serving it means claiming every URL on the
>   site. The diagnostics panel reports it instead.

---

# SahajAtlasWordpress — implementation plan (stage C5)

## Context

Thirteen of the twenty-nine known Sahaj Atlas client domains are self-hosted WordPress. **They cannot
install the widget by pasting its snippet**: WordPress strips `<script>` from saved content via
`wp_kses` for every role below Administrator, and for *every* Site Administrator on multisite. A
plugin's own output never passes through `wp_kses`, so a plugin is the only reliable install path.

This is **stage C5** of the white-label & SEO programme
(`SahajAtlasWeb/.claude/reports/whitelabel-seo-program.md`). Every other stage has merged, including
SahajCloud#646 (the `/api/atlas/seo` endpoint) on 2026-08-25, so **nothing blocks this work**. C5
carries more of the programme's value than any other remaining ticket: it is both the install path
and the SEO path for 13 sites.

Full background: `SahajAtlasWeb/.claude/reports/wordpress-plugin-plan.md` (the brief this plan
implements). The host-facing widget contract is `SahajAtlasWeb/docs/embedding.md` — **the source of
truth; read it rather than any summary.**

---

## Step 0a — the repo, before anything else

⚠ **No plugin code lands in SahajAtlasWeb.** The only change to that repo is the upstream ticket in
Step 0b. Everything else is a new, separate project.

1. **Create `sydevs/SahajAtlasWordpress`** — **public** (required: a private repo forces an
   unrotatable GitHub token into 13 installs we do not control, and a GPL plugin distributed to third
   parties is publishable source anyway). Confirmed not to exist yet: `gh api` returns 404 today.
   ```
   gh repo create sydevs/SahajAtlasWordpress --public \
     --description "WordPress plugin for embedding the Sahaj Atlas" --clone
   ```
2. **Clone it to `~/Documents/WeMeditate/SahajAtlasWordpress`**, alongside the sibling projects.
3. **Copy the brief into it** as `docs/brief.md` — it is currently gitignored inside SahajAtlasWeb
   (`.claude/reports/`), so a fresh session in the new repo cannot see it. Its §10 "where the truth
   lives" table is the pointer back to the widget contract.
4. **Fix the plugin slug now: `sahaj-atlas`.** It must be identical in three places — the zip's
   top-level directory, the installed folder, and Plugin Update Checker's third argument — or updates
   install a second copy instead of replacing the first. Check it does not collide with an existing
   wordpress.org plugin.
5. Add the standard scaffolding: `.gitignore`, GPL-2.0-or-later `LICENSE`, `readme.txt` (from day one
   — PUC surfaces its changelog in the update dialog), and a `CLAUDE.md` recording the decisions
   below so they are not relitigated.

---

## The shape of it

The original brief proposed a block + shortcode that a volunteer places on a page of their choosing.
**That was rejected in planning, correctly.** The atlas wants the viewport, and four of the nine
sites surveyed build pages with Elementor, WPBakery or Beaver Builder, where a Gutenberg block never
appears. So:

**The plugin owns one Atlas page per site.** A settings-screen button creates a real WordPress page;
the plugin supplies its rendering — full-viewport atlas, no footer, and the site header **once the
widget can coexist with one** (Step 0). No block to place, no builder to fight, one widget per page
guaranteed by construction, and path routing has an unambiguous target.

**In-content embeds are a separate, secondary feature**: a shortcode *and* a block for a single event
or a registration form, map-less, inside normal content.

---

## Decisions taken in planning — do not relitigate

| | Decision |
| --- | --- |
| Audience | **Non-technical local volunteers**, one per site. Drives everything below. |
| Atlas pages per site | **One.** The plugin owns it. |
| Page chrome | **Site header, no footer** — but the widget cannot yet coexist with a header, so an upstream ticket comes first and the header lands after it. See Step 0. |
| In-content embeds | **Shortcode + block**, map-less, for an event or registration form. |
| Editor preview | **Static placeholder.** Never boot the widget in the editor. |
| Setup | A **button in settings** creates the atlas page. Never automatic on activation. |
| API key | Issued by hand — the setup screen must say **who to ask**. |
| Diagnostics | **Yes, a status panel.** With this audience it is the difference between self-service and 13 support emails. |
| Distribution | **Public repo + GitHub Releases + Plugin Update Checker.** |
| URL ↔ CMS match | Plugin reports; a human nominates in SahajCloud; **the panel warns on mismatch.** |
| SEO conflicts | **Take over on atlas pages only.** |
| Existing embeds | **Document only** — installation instructions say to remove an old atlas embed first. The plugin does not detect or touch it. |
| Multisite | **At least one site is multisite.** Settings must be per-site, never network-wide. |
| Release scope | **Phase 1 (install) ships first.** SEO follows. |

---

## The fleet — measured 2026-08-25, not assumed

Nine of the thirteen domains were fetched and fingerprinted. This is the compatibility target:

| | Finding | Consequence |
| --- | --- | --- |
| WordPress | 6.7.7 · 6.8.3 · 6.8.8 · 6.9.7 · 7.0.4 · 7.1 · 7.1 | Floor must be **≤ 6.7**. This **rules out WP 7.0's `supports.autoRegister`**, so the block needs ~25 lines of hand-written editor JS. |
| Page builders | Elementor Pro ×2, WPBakery ×1, Beaver Builder ×1 | Why the owned page exists. The shortcode is the universal in-content fallback. |
| SEO plugins | Yoast ×4 · AIOSEO ×2 · **none ×3** | A standalone metadata emitter is needed *anyway* for the three bare sites — which is most of the argument for override over feed. |
| Worst case | `sahajayogalondon.co.uk`: AIOSEO Pro **4.0.12** (years stale) + duplicate Jetpack `og:` tags, three `og:image`s | **Test here first.** If suppression is clean here it is clean everywhere. |
| Absent | No Rank Math anywhere | Build that adapter last, or not at all. |

---

## Step 0b — one upstream ticket, in SahajAtlasWeb — ✅ FILED as SahajAtlasWeb#169

**`SahajAtlasWeb`: map mode must be containable.** Verified in
`src/views/FullInterface.tsx:139` — the map renders `<div style={{ position: 'fixed', inset: 0 }}>`
with **no `z-index`**. So on the atlas page:

| Site header | What a visitor sees |
| --- | --- |
| Static | The map paints over it. It still occupies layout space, but is invisible. |
| Sticky with `z-index` (Elementor Pro's default, and most modern themes) | The header floats **above the map and above the widget's own drawers**, covering the widget's top-left settings control. |

Neither is "header, then atlas below". **Decision taken: raise a ticket rather than work around it** —
embeds that do not own the whole page are wanted generally, which means the widget needs a way to
determine its container other than assuming the viewport.

✅ **Filed 2026-08-25 as [SahajAtlasWeb#169](https://github.com/sydevs/SahajAtlasWeb/issues/169).**
⚠ **And the premise improved while writing it.** `FullInterface.tsx`'s comment claims containment is
intractable — vaul measures the window, `--sy-sheet-top` is viewport-relative. **Both were solved by
#161**, and the whole interface is *already* contained in one place: the compact card's expanded
dialog is `fixed inset-2 [contain:layout]`, and `FullInterface` renders inside it. So the ticket is
to **generalize an existing, tested mechanism** from "a dialog we own" to "an element on the host's
page" — not to build one. That is a much smaller job than this plan assumed.

**The plugin is not blocked by it.** Build the page template to include the header template part; the
widget change is what makes it render correctly. Until then ship the atlas page **without** the
header — a one-line change to the registered template content, not a rework — so volunteers never
see the broken intermediate.

This is the **only** change to SahajAtlasWeb in this whole plan.

---

## Phase 1 — install (the first release)

### Responsibilities, in full

1. Register a setting for the API key, and an admin screen.
2. Create and own the Atlas page; render it.
3. Register the rewrite rule that makes path routing work.
4. Enqueue `auto.js` with the right query string on the right pages.
5. Provide the in-content shortcode + block.
6. Show diagnostics.
7. Keep itself updated.

That is the whole plugin. If a seventh responsibility appears, question it.

### The widget contract this depends on

Verified in `SahajAtlasWeb/src/loader/index.ts` — **do not re-derive**:

- All configuration rides on the **script URL's query string**. The element observes no attributes.
- The loader resolves its own tag as
  `document.currentScript ?? document.querySelector('script[src*="auto.js"]')` — so `defer` is safe
  and the tag may live in `<head>`.
- `resolveElement()` **reuses an existing `<sahaj-atlas>`** if the page has one, and only inserts its
  own when none exists. So the plugin prints the element where it wants the widget, and enqueues the
  script separately, the WordPress-native way.
- **One widget per page.** A second is refused with a console warning.
- Parameters: `key` (required), `map`, `atlas`, plus `routing` and `locale` which the plugin **must
  not set** — locale comes from the page's `<html lang>`, and routing is moving to the client record
  (SahajCloud#644).

✅ **Resolved during planning: `auto.js` is genuinely an ES module.** `dist/auto.js` line 2 opens
`import{n as e,t}from"./assets/preload-helper-…"`. A top-level `import` in a classic script is a
`SyntaxError`, so **`wp_enqueue_script_module()` is the only option** — `wp_enqueue_script` with
`defer` is not a fallback, it is a hard break. Drop the "keep it switchable" idea from the brief.

⚠ **But core prints script modules somewhere that breaks this twice**, per
`WP_Script_Modules::add_hooks()` — `wp_is_block_theme() ? 'wp_head' : 'wp_footer'`:

- **Classic theme** → footer only. A template that skips the footer prints *no script at all*.
- **Block theme** → `<head>`, which the loader explicitly refuses (`resolveElement()` guards
  `parent.nodeName !== 'HEAD'` and logs "could not find a place to render").

**One move fixes both**: always print an explicit `<sahaj-atlas></sahaj-atlas>` on `wp_body_open` at
priority 1. The loader adopts an existing element wherever it is, so where the script tag lands stops
mattering. It also puts the element as a **direct child of `<body>`** — the only reliable defence
against an Elementor/WPBakery `transform` ancestor becoming the containing block and confining the
map.

⚠ `get_footer()` and `wp_footer()` are different. Skip the **former**; the latter must always fire or
the module never prints and the admin bar breaks.

⚠ **Do not give `<sahaj-atlas>` any CSS on the atlas page.** `lib/embed-slot.ts` degrades to the
compact card when the slot is under **0.8×** the viewport on a measured axis. An unstyled element
measures 0×0, which reads as "unmeasurable" and yields the full interface. A helpful
`height: calc(100vh - 80px)` would silently collapse the map into a card on any site with a tall
header. Worth a code comment.

### In-content embeds — the route shapes

The shortcode and block take an `atlas` route, which the widget's own `resolveStack`
(`SahajAtlasWeb/src/lib/shape/path.ts`) parses. `RESERVED_SLUGS` there is the authoritative list of
non-region words; the two that matter here:

```
[sahaj_atlas atlas="/in/pune/507"]            a single class
[sahaj_atlas atlas="/in/pune/507/register"]   its registration form
```

Both default to `map="false"` for in-content use. An event's own path comes from its `webPath` in the
CMS, so a volunteer copies it rather than composing it.

### Rendering the atlas page

Keep it a **real `page` post** — menus, admin visibility, trash/restore, permalinks, and phase 2's SEO
plugins all depend on it; a virtual route gets none. The plugin owns only its *template*, supplied
through whichever of core's two systems the theme uses. One decision point, `wp_is_block_theme()`:

- **Block themes → `register_block_template()`** (WP **6.7.0**, exactly our floor). Register a
  template whose content is the header template part plus our block, and **omit the footer part**.
  Core renders it via `template-canvas.php`, which already calls `wp_head()`, `wp_body_open()` and
  `wp_footer()`. Zero hand-written HTML — the cleanest thing in the design.
- **Classic themes → `theme_page_templates` + `template_include`** pointing at a four-line
  `templates/atlas-page.php`: `get_header()`, our element, `wp_footer()`, close the document.

⚠ **`get_header()` must never run on a block theme.** Verified in core source, not assumed:
`locate_template()` falls through to `wp-includes/theme-compat/header.php`, which still ships — it
raises `_deprecated_file()` and emits a **complete second `<!DOCTYPE html><html><head>`** with 2010-era
markup. It fails silently and malformed, not loudly.

Unifying both into one PHP template was considered and rejected: on a block theme that file must
hand-roll the doctype, `wp_head()`, `body_class()`, the `.wp-site-blocks` wrapper and `wp_footer()` —
a reimplementation of `template-canvas.php` we would then own. Two 30-line paths using core as
intended is less code than one path reimplementing core.

### Path routing — `parse_request`, no rewrite rules, no flushing ever

```php
add_action( 'parse_request', function ( $wp ) { /* match $wp->request against get_page_uri($id) */ } );
```

Chosen over `add_rewrite_rule` deliberately. Rewrite rules cache the slug, so they need flushing on
activation, slug change, re-parenting and permalink change — and go stale between times, 404-ing every
deep link. Reading `get_page_uri()` per request removes the problem instead of managing it. With
**non-technical volunteers who will rename a page and not know to re-save permalinks**, that is the
whole argument.

`$wp->request` is also shape-independent: with `/%postname%/` permalinks WordPress uses verbose page
rules and hands you `name`, not `pagename`, so a `request`-filter implementation keyed on `pagename`
would work on some of the 13 sites and 404 on others.

⚠ **`redirect_canonical()` will 301 every deep link back to the page root** — it sees `is_page()` with
a URL that doesn't match the permalink. Both approaches share this. Return `false` from the
`redirect_canonical` filter whenever our route var is set.

⚠ **Path routing is not fully in the plugin's gift.** It needs `routing=path` on the script URL *and*
`canonical.embed` on the client record naming this page. The plugin cannot set the second. Diagnostics
must make that visible, or the volunteer's experience is "I enabled it and nothing changed". With
plain `?p=123` permalinks it is impossible — detect and say so rather than emitting a config the
widget will refuse.

### The block — no build step

Confirmed: no npm, no webpack. The usual forcing function, `index.asset.php`, is optional since
core [#60460] — but its absence defaults dependencies to `array()`, so **register the script handle
in PHP** (`wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`, `wp-i18n`) and reference the
*handle* in `block.json`, not a `file:` path. Editor UI is ~40 lines of ES5 `wp.blocks.registerBlockType`
with `wp.element.createElement` — no JSX.

`"supports": { "multiple": false }` is core enforcing the widget's one-per-page rule for free.
`"render": "file:./render.php"` avoids wiring a PHP callback. `WP 7.0`'s `supports.autoRegister`
would remove the JS entirely but needs a 7.0 floor, which **excludes two live sites**.

### Configuration surface — complete

- **Site-wide:** the API key. Nothing else.
- **Per in-content embed:** `map` (default off for these) and `atlas` (a route). Nothing else.

Explicitly *not* settings: locale, routing mode, path prefix, brand name, colours, analytics,
widget height. Each is either derivable or owned by the client record. **Adding one needs a use case
somebody actually hit.**

### File layout

```
sahaj-atlas/
├── sahaj-atlas.php              # header, constants, requires, ONE add_action('init')
├── includes/
│   ├── settings.php             # the one option, the screen, the create-page button
│   ├── page.php                 # page ownership + both template paths
│   ├── routing.php              # parse_request + redirect_canonical suppression
│   ├── embed.php                # script-URL builder, the one enqueue, the one element
│   ├── shortcode.php            # [sahaj_atlas] — shares render.php's body
│   └── diagnostics.php          # the four checks
├── templates/atlas-page.php     # classic themes
├── blocks/embed/{block.json,editor.js,render.php}
├── vendor/plugin-update-checker/    # vendored, committed
├── readme.txt
└── .github/workflows/release.yml
```

**One widget per page is decided server-side**, on `template_redirect` — before `wp_head`, which also
sidesteps the block-theme placement problem. Resolve which embed wins (atlas page → first block →
first shortcode), enqueue once; later render callbacks print nothing.

⚠ **WP 6.7 translation gotcha**: nothing translated may run before `init` — not at file scope, not in
`register_activation_hook`, not on `plugins_loaded`. So the main file holds only the header,
`define()`s, `require`s and one `init` hook; and **activation cannot create the page** (it can't
produce a translated title), which is why creation is the settings button anyway.

### Verification for phase 1

- A non-Administrator author saves a page — **the case the plugin exists for.**
- The atlas page renders with header, no footer, on a classic theme *and* a block theme.
- `/find-a-class/gb/london` returns the atlas page on a fresh permalink flush, and after a slug change.
- The widget boots: one `<sahaj-atlas>`, one `auto.js` with the key, and the readiness marker
  `data-sahaj-atlas-ready` appears on `<html>`.
- The shortcode renders inside an Elementor text widget.
- Diagnostics correctly reports a missing key, a missing page, and a URL mismatch.

The four diagnostic checks:

| Check | How |
| --- | --- |
| Key accepted? | `GET /api/clients/me` with `Authorization: clients API-Key <key>`. Cache in a transient ~15 min. |
| Atlas page healthy? | Exists, published, still carries our template, and is the only one that does. |
| Path routing live? | Permalinks pretty **and** `canonical.embed` present **and** a loopback fetch of a deep path returns our body class. The loopback is what actually proves it works behind the site's caching. |
| URL matches the CMS? | Compare the page's host+path against `canonical.embed`, which is a scheme-less `host/path` mount key (`mountPrefix()`, `src/lib/shape/routing.ts`). |

Also surface `allowedDomains`: an **empty** list *refuses* the embed report rather than allowing
everything — a silent failure a volunteer would never otherwise see.

---

## Phase 2 — SEO (second release)

**Strategy: take over, do not feed.** On atlas routes only, suppress whichever SEO plugin is active
and emit our own metadata from one code path.

The reasoning is not preference. Feeding needs three adapters with three different shapes — Yoast's
dozen per-property filters, AIOSEO's single `aioseo_facebook_tags` array, Rank Math's dynamic
`{$network}/{$property}` names — **and none of the three emits hreflang at all**, so we print that
ourselves regardless. Three of nine sites run no SEO plugin, so the standalone emitter must exist
anyway. Override is one emitter plus three one-line suppressions.

Suppression, all first-party documented:

| | |
| --- | --- |
| Yoast | `remove_action( 'wpseo_head', [ $front_end, 'present_head' ], -9999 )` — the priority is required |
| AIOSEO | `add_filter( 'aioseo_disable', '__return_true' )` |
| Rank Math | `remove_all_actions( 'rank_math/head' )` |
| Core | `remove_action( 'wp_head', 'rel_canonical' )`, plus `pre_get_document_title` and `wp_robots` |

Detect on `plugins_loaded` (late) or `init`; suppress on `template_redirect`; **always suppress
before emitting.**

⚠ **Known cost, mitigate with an admin notice rather than architecture:** the SEO plugin's own
metabox and preview will show stale data for atlas pages, and a site owner will report it as a bug.

### The data source

`GET /api/atlas/seo?route=/gb/london&locale=fr` (SahajCloud#646), with
`Authorization: clients API-Key <key>` — the same key the widget uses. Server-to-server calls send no
`Origin`, which the endpoint explicitly allows.

Contract points that save a day each:

- `canonical` is the document's own `webUrl`, **never recomputed** — emit verbatim.
- `jsonLd` arrives **already serialized and escaped**. `echo` it raw; do **not** re-encode or
  `esc_html` it.
- **No HTML crosses the wire.** A description is `content.paragraphs`, plain text per block — which
  matters because our output never passes through `wp_kses`.
- A region has `description: null` by design; the host writes that sentence in its own language.
- An unresolvable route — the atlas root included — is a **404**.
- `breadcrumbs[].route` is nullable in the TypeScript type but declared non-nullable in their
  OpenAPI. **Trust the TypeScript.** Worth reporting upstream.
- Responses carry `Cache-Control: public, max-age=300`. Cache with a transient; do not fetch per
  request.

### Sitemaps — a core provider alone is dead code

Yoast and Rank Math **disable core sitemaps and 301 `wp-sitemap.xml`**, so
`wp_sitemaps_register_provider()` never runs on six of the nine surveyed sites. Needs one adapter per
system: core provider · Yoast `WPSEO_Sitemaps::register_sitemap` + `wpseo_sitemap_index` ·
AIOSEO `aioseo_sitemap_additional_pages`. Rank Math last, or never.

### The shared URL contract

`atlas-url-contract.json` — **21 cases, 13 emitting a URL and 8 asserting refusal.** Byte-identical in
SahajCloud and SahajAtlasWeb, each with a spec. **The plugin is the third consumer and must adopt it
the same way**: copy the file, assert against it in PHPUnit, add a sync check.
Source: `https://raw.githubusercontent.com/sydevs/SahajCloud/main/src/lib/atlas/atlas-url-contract.json`

It already caught a real defect — a canonical shipped percent-encoded as `?atlas=events%2F12345`,
which the widget refuses outright. Found by hand; the fixture exists so the next one is found by a gate.

---

## Distribution

**Public repo → GitHub Releases → Plugin Update Checker (YahnisElsts, v5.7, MIT, active).**

Manual zip-upload was ruled out on its own merits: it delivers **no update notifications at all**, so
every embed-contract change becomes 13 emails to 13 volunteers with no way to see who applied it.

Non-negotiables:

1. **Fix the slug once** — `sahaj-atlas` — and use it identically for the zip's top-level directory,
   the installed folder, and PUC's third argument. Check it does not collide on wordpress.org.
2. **Build the release asset in CI** (`10up/action-wordpress-plugin-build-zip`). GitHub's own
   "Source code (zip)" wraps everything in `repo-tag/`, which installs as a *new plugin folder per
   version* rather than an update. The README must say which file to download.
3. **Ship `readme.txt` from day one** — PUC surfaces its changelog in the update dialog.
4. Instantiate PUC on `plugins_loaded`, not inside an `admin_*` hook, or WP-CLI cannot see updates.

wordpress.org stays a later, optional step for discoverability. Points 1–3 are its prerequisites, so
nothing is wasted. ⚠ If it is ever attempted, expect **Guideline 8** (no third-party CDNs) to be the
crux, argued against **Guideline 6** (a plugin may be an interface to a service).

---

## Testing — proportionate, not a pyramid

`@wp-playground/cli` + Playwright. No Docker, no `wp-env`, clean WP per run.

| Worth it | Why |
| --- | --- |
| Rewrite matching, plain PHPUnit | The only real logic, and it 404s silently in production |
| Render output + escaping on every attribute reaching markup | Security-relevant and cheap |
| One Playwright smoke: page loads, one `<sahaj-atlas>`, `auto.js` present with key and query string intact | Verifies the architectural bet directly |
| `wp plugin check` in CI | Free, and the same gate wordpress.org applies |

**Over-engineering here:** the full WP PHPUnit suite with DB fixtures, a version matrix, visual
regression, E2E that drives the block editor UI (that is core's code), mocking frameworks.

---

## Risks — fleet-specific, not generic

- **Elementor Pro's Theme Builder can take over the page template entirely**, bypassing
  `theme_page_templates`. Expect at least one of the two Elementor sites to need the atlas page set
  to Elementor's own "Canvas" template with the widget supplied by shortcode. **Budget this as a
  documented per-site variant, not plugin code.**
- **`transform` ancestors** on Elementor/WPBakery animated sections confine `position: fixed`. Printing
  the element at `wp_body_open` steps outside them — but only on the atlas page. An in-content
  `map=true` embed inside an animated section will be confined, and the visible symptom is the
  **compact card**, not a broken map. Make the editor placeholder's map-mode warning loud.
- **A theme that never calls `wp_body_open()`** (pre-5.2 custom themes, some builder headers) falls back
  to printing the element inside the template — which reintroduces the transform hazard for exactly
  those sites. Diagnostics should report *where* the element rendered.
- **A volunteer renames, trashes or duplicates the atlas page.** The plugin must notice and say so.
- **A site already embeds the atlas** — `sahajayoga.at` serves an iframe to the legacy origin today.
  Two atlases on one site is the likeliest first-install failure, and by decision the plugin does not
  detect it. **The install instructions must lead with removing the old embed**, not bury it.
- **Multisite**, on at least one site. Store settings with `get_option` per site; never
  `get_site_option`. Do not assume a network admin screen exists, and do not share a key across
  sites. Note this is also the case where *every* Site Administrator has scripts stripped, so it is
  the audience the plugin most exists for.
- **`sahajayogalondon.co.uk`** already emits duplicate `og:` sets from two sources on a years-stale
  AIOSEO Pro. Test suppression there first.
- **Nothing this plugin ships reaches a real client until the origin cutover** (#148 Part 2), so it
  gets no production traffic to shake it out. Argues for a small surface and real-install testing.
