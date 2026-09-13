> **Status (2026-08-25): the plugin is built and shipped.**
>
> This file is the plan written before the code. It stays for its reasoning: why an owned page
> beat a block and shortcode, why the plugin overrides SEO plugins instead of feeding them, and
> what the fleet survey found. It is not a status document. Where this plan disagrees with the
> code, the code is right. `AGENTS.md` records what was actually built, the traps found while
> building it, and how to run the tests.
>
> One planned approach did not survive: a front-page atlas refuses path routing. See AGENTS.md's
> Traps section for why.

# SahajAtlasWordpress — implementation plan

## Context

Thirteen of the twenty-nine known client domains run self-hosted WordPress. See AGENTS.md's "Why
this plugin exists" section for why only a plugin can install the widget there.

This plan covers stage C5 of a larger white-label and SEO programme. Every earlier stage,
including the SEO endpoint this plan depends on, had already merged when this plan was written.

The host-facing widget contract lives in `docs/embedding.md` in SahajAtlasWeb. That file is the
source of truth. Read it instead of any summary here.

## Principles

See AGENTS.md's Principles section for the full list. It has not changed since this plan was
written.

## Decisions taken in planning

AGENTS.md's Decisions section lists the load-bearing choices. This table adds the rest.

| Question | Decision |
| --- | --- |
| Audience | One non-technical local volunteer per site. This drives every decision below. |
| Atlas pages per site | One. The plugin owns it. |
| Page chrome | The site's header, with no footer. `<sahaj-atlas>` needs a definite height to opt into a contained map. See `assets/atlas-page.css` and `includes/page.php`. |
| In-content embeds | A shortcode and a block, without a map, for one event or its registration form. |
| Editor preview | A static placeholder. The widget never boots inside the editor. |
| Setup | A button in settings creates the atlas page. Never automatic on activation. |
| API key | Issued by hand. The setup screen names who to ask. |
| Diagnostics | A status panel. For this audience, it is the difference between self-service and 13 support emails. |
| Distribution | A public repo, GitHub Releases, and Plugin Update Checker. |
| URL and CMS match | The plugin reports the page's URL. A human sets the matching value in SahajCloud. The panel warns on a mismatch. |
| SEO conflicts | The plugin takes over SEO on atlas pages. It does not feed the site's own SEO plugin. |
| Existing embeds | Documented only. Installation instructions say to remove an old embed first. The plugin does not detect or remove it. |
| Multisite | At least one site runs multisite. Settings stay per-site, never network-wide. |
| Release scope | Phase 1, install, ships first. SEO follows in phase 2. |

## Responsibilities, in full

1. Register the site's settings — the API key, and the Atlas page's description opt-out — and an
   admin screen.
2. Create and own the Atlas page, and render it.
3. Register the rule that makes path routing work.
4. Enqueue `auto.js` with the right query string on the right pages.
5. Provide the in-content shortcode and block.
6. Show diagnostics.
7. Keep itself updated.

That is the whole plugin. If an eighth responsibility appears, question it.

## The block — no build step

The block needs no npm, no webpack, and no build tool. `index.asset.php` is optional since core
change #60460, but its absence defaults the block's dependencies to none. So the plugin registers
the script handle in PHP — `wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`,
`wp-i18n` — and `block.json` references that handle, not a file path.

The editor UI is about 40 lines of plain JavaScript. It uses `wp.blocks.registerBlockType` and
`wp.element.createElement`, and needs no JSX.

`"supports": {"multiple": false}` makes core enforce the one-widget-per-page rule for free.
`"render": "file:./render.php"` avoids a separate PHP callback. WordPress 7.0's
`supports.autoRegister` could remove this JavaScript entirely, but it needs a 7.0 floor. Two live
sites do not meet that floor.

## Why the plugin owns a page

An earlier design proposed only a block and a shortcode, placed by a volunteer. Planning rejected
this design. The atlas needs the full viewport, and four of the nine surveyed sites build pages
with Elementor, WPBakery, or Beaver Builder, where a Gutenberg block never appears.

The plugin instead owns one Atlas page per site, created by a settings-screen button. This design
needs no manual block placement, works inside every page builder, and guarantees one widget per
page by construction.

In-content embeds stay a separate, secondary feature: a shortcode and a block for one event or
its registration form, without a map.

## Why the plugin overrides SEO plugins instead of feeding them

Feeding an SEO plugin needs one adapter per plugin: Yoast's dozen filters, AIOSEO's single array,
and Rank Math's own dynamic property names. None of the three emits hreflang tags, so the plugin
must print those itself either way. Three of the nine surveyed sites run no SEO plugin at all, so
a standalone metadata emitter is required regardless of this choice.

Override needs one emitter plus three short suppression calls:

| SEO plugin | Suppression |
| --- | --- |
| Yoast | `remove_action('wpseo_head', [$front_end, 'present_head'], -9999)` |
| AIOSEO | `add_filter('aioseo_disable', '__return_true')` |
| Rank Math | `remove_all_actions('rank_math/head')` |
| WordPress core | `remove_action('wp_head', 'rel_canonical')`, plus `pre_get_document_title` and `wp_robots` |

Detect the active plugin on `plugins_loaded` or `init`. Suppress it on `template_redirect`, and
only after a successful fetch from the SEO data endpoint. Suppressing first risks a page with no
metadata at all if the fetch then fails.

Sitemaps follow the same logic. A core sitemap provider alone would miss most sites: Yoast and
Rank Math disable core sitemaps and redirect `wp-sitemap.xml`. The plugin instead serves its own
sitemap file, so every SEO plugin needs only to point at it.

## The fleet survey (2026-08-25)

Nine of the thirteen known sites were fetched and fingerprinted before building anything.

| | Finding | Consequence |
| --- | --- | --- |
| WordPress version | 6.7 through 7.1, across seven sites | The support floor is 6.7. This rules out WP 7.0's `supports.autoRegister`, so the block needs its own editor script. |
| Page builders | Elementor Pro (2 sites), WPBakery (1), Beaver Builder (1) | Confirms the owned-page design. The shortcode is the fallback for in-content use. |
| SEO plugins | Yoast (4 sites), AIOSEO (2), none (3) | The three bare sites need a standalone emitter regardless of the override-versus-feed choice. |
| Worst case | `sahajayogalondon.co.uk` runs a years-stale AIOSEO build and duplicate Jetpack `og:` tags | Test suppression here first. If it is clean here, it is clean everywhere. |
| Absent | No surveyed site runs Rank Math | Build that adapter last, or skip it. |

## The embed contract's five parameters

The widget reads every setting from its own script URL. It has no HTML attributes.

| Parameter | Default | Plugin behavior |
| --- | --- | --- |
| `key` | none | Required. A published, non-secret client key. |
| `map` | `true` | `map=false` renders a list with no map canvas. |
| `locale` | the page's `<html lang>` | The plugin must never set this. |
| `routing` | `query` | `query` or `path`. May be removed in a future widget version. |
| `atlas` | none | A default route, for example `/gb/london`. |

Only the exact strings `false` and `0` turn off a boolean parameter.

## Path routing

Path routing turns `?atlas=/gb/london` into `/classes/gb/london`. It needs two things: a
WordPress rule that serves the same page for every URL under the prefix, and a canonical embed
path set on the client record in SahajCloud. The plugin supplies the first and cannot set the
second.

The plugin matches routes on the `parse_request` hook rather than a registered rewrite rule. A
rewrite rule caches the page's slug and needs reflushing after every rename or permalink change.
Reading the page's current URL on every request removes that failure mode instead of managing it.
This matters because the audience renames pages without knowing to flush permalinks.

See AGENTS.md's Traps section for the `redirect_canonical()` trap and the front-page routing
refusal this approach still needs.

## Diagnostics checks

| Check | Method |
| --- | --- |
| Key accepted | `GET /api/clients/me` with the site's key. Cached in a transient for about 15 minutes. |
| Atlas page healthy | The page exists, is published, and is the only page using the Atlas template. |
| Path routing live | Permalinks are not "Plain", the client record has a canonical embed path, and a request to a deep path returns the Atlas page's body class. |
| URL matches SahajCloud | The page's host and path match the client record's canonical embed path. |

An empty `allowedDomains` list on the client record blocks the embed report entirely, rather than
allowing every origin. See AGENTS.md's Traps section for why.

## File layout notes

`vendor/plugin-update-checker/` is vendored and committed, not installed at build time. The
Gutenberg block lives in `blocks/embed/`, registered from `block.json` with no build step. The
classic-theme page template lives in `templates/atlas-page.php`. The release workflow lives in
`.github/workflows/release.yml`.

## Distribution

A manual zip upload was ruled out: it gives no update notifications, so every embed-contract
change becomes one email per site with no way to confirm anyone applied it. The release build
runs in CI, because GitHub's own "Source code (zip)" wraps the plugin in an extra folder that
installs as a second copy instead of an update.

## Testing scope

Worth testing: rewrite-rule matching in plain PHPUnit, since a routing bug fails silently in
production; escaping on every attribute that reaches markup; one browser smoke test confirming
the widget boots with the right script and query string; and `wp plugin check` in CI, the same
gate wordpress.org applies.

Not worth building here: a full WordPress PHPUnit suite with database fixtures, a version matrix,
visual regression tests, or end-to-end tests that drive the block editor's own UI.

## Known risks specific to this fleet

- Elementor Pro's Theme Builder can replace the page template entirely. At least one Elementor
  site may need the Atlas page set to Elementor's own blank template, with the widget placed by
  shortcode instead.
- A volunteer may rename, trash, or duplicate the Atlas page. Diagnostics must detect this and
  say so.
- `sahajayoga.at` already serves an iframe to a legacy atlas build. Two atlases on one site is the
  most likely first-install failure. The plugin does not detect it, so installation instructions
  must say to remove the old embed first.
- At least one site runs WordPress multisite. Store settings per site with `get_option`, never
  `get_site_option`.
- No client site had received production traffic from this plugin when this plan was written,
  pending an unrelated origin cutover. This argued for a small surface and testing against a real
  install rather than assumptions.
