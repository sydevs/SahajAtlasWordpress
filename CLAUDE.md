# Sahaj Atlas — WordPress plugin (developer guide)

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
- ⚠ **Print `<sahaj-atlas></sahaj-atlas>` on `wp_body_open` priority 1.** Core prints script modules
  in the footer on classic themes and in `<head>` on block themes, and the loader *refuses* `<head>`.
  An explicit element makes placement irrelevant, and puts it outside page-builder `transform`
  wrappers that would otherwise confine the map.
- ⚠ **Never style `<sahaj-atlas>` on the atlas page.** The widget degrades to a compact card when its
  slot is under 0.8× the viewport. Unstyled measures 0×0, which reads as "unmeasurable" and gives the
  full interface. A helpful `height: calc(100vh - 80px)` silently collapses the map.
- ⚠ **`get_header()` must never run on a block theme.** It falls through to
  `wp-includes/theme-compat/header.php` and emits a second, 2010-era `<!DOCTYPE html>`.
- ⚠ **`get_footer()` ≠ `wp_footer()`.** Skip the former; the latter must always fire.
- ⚠ **`redirect_canonical()` 301s every deep link back to the page root.** Suppress it when our route
  var is set.
- ⚠ **Nothing translated may run before `init`** (WP 6.7). Not at file scope, not in
  `register_activation_hook`, not on `plugins_loaded`.

## Where the truth lives

| | |
| --- | --- |
| Host-facing widget contract | `docs/embedding.md` in **sydevs/SahajAtlasWeb** — the source of truth |
| What changes under hosts | `CHANGELOG.md` in SahajAtlasWeb |
| Loader behaviour | `src/loader/index.ts` in SahajAtlasWeb |
| Slot / compact-card rules | `src/lib/embed-slot.ts` in SahajAtlasWeb |
| SEO endpoint | `GET /api/atlas/seo` — SahajCloud PR #646 |
| Shared URL contract | `src/lib/atlas/atlas-url-contract.json` in **sydevs/SahajCloud** |

**Ask rather than infer** where these docs and the code disagree. The code is what ships.

## Conventions

- **Plugin slug is `sahaj-atlas`** and must be byte-identical in three places: the release zip's
  top-level directory, the installed folder, and Plugin Update Checker's third argument. Verified
  free on wordpress.org 2026-08-25.
- Text domain `sahaj-atlas`, matching the slug.
- PHP follows WordPress coding standards (real tabs). `.editorconfig` covers it.
- Every attribute reaching markup goes through `esc_attr()` / `esc_url()`. The one exception is the
  SEO endpoint's `jsonLd`, which arrives **already escaped** — echo it raw, never re-encode it.
