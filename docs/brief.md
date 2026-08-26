# Sahaj Atlas — WordPress plugin: brief for a fresh session

**Read this whole file before writing code.** It is a handoff, not a ticket: it carries the facts a
new session cannot get from the plugin repo, because they live in SahajAtlasWeb and SahajCloud.

This is **stage C5** of the white-label & SEO programme
(`.claude/reports/whitelabel-seo-program.md` in SahajAtlasWeb). Every other stage in that programme
is merged. **C5 carries more of its value than any other remaining ticket**, and the widget side is
finished and waiting.

---

## 0. What you are building, and the one reason it exists

A WordPress plugin that installs the Sahaj Atlas widget on a page, and — later — makes that page
indexable.

**The reason it must exist, rather than a copy-pasted snippet:** WordPress strips `<script>` from
saved post content via `wp_kses` for every role below Administrator, **and for every Site
Administrator on multisite**. A plugin's own output never passes through `wp_kses`, so the plugin is
the only reliable install path. Thirteen of the twenty-nine known client domains are self-hosted
WordPress.

Two jobs, in order:

| Phase | Job                                                                                                       | Blocked?                                                                |
| ----- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| **1** | **Install** — a block + shortcode that put the widget on a page, and the rewrite rule path routing needs  | No. Build this now.                                                     |
| **2** | **SEO** — server-rendered content as children of the element, plus canonical / `og:` / hreflang / JSON-LD | **Yes** — needs SahajCloud's `/seo` endpoint (C3), which does not exist |

**Phase 1 is a complete, shippable product.** Do not let phase 2's absence shape phase 1's
architecture beyond leaving an obvious seam.

---

## 1. Principles — these are the acceptance criteria, not aspirations

The person commissioning this asked for **effective but robust and simple, with minimal maintenance
and minimum custom code.** Concretely:

1. **Prefer a WordPress core API over anything you would write.** Core has a sitemap provider
   interface, a rewrite API, a settings API, `block.json` registration, and a script-module loader.
   Use them. Every line you write is a line somebody maintains against a WP release you cannot see.
2. **No build step if you can avoid one.** A block can be registered from `block.json` with plain
   JS. If you find yourself adding webpack, `@wordpress/scripts`, npm and a `node_modules` to ship
   one block, stop and ask whether the block needs to be that.
3. **No framework, no service container, no abstraction layer.** This plugin has perhaps four
   responsibilities. A `src/` tree of interfaces for four responsibilities is the maintenance
   burden, not the protection from it.
4. **Configuration is a cost.** Every setting is a thing to document, migrate, support and get
   wrong. §4 lists the complete permitted surface and the argument for each. **Adding to it needs a
   use case somebody has actually hit**, not one you can imagine.
5. **Fail loudly to the admin, never to the visitor.** A misconfigured plugin should say so in
   `wp-admin` and render nothing broken on the front end.
6. **Do not reimplement anything the widget already does.** The widget handles routing, i18n,
   layout, errors and reporting. The plugin's job is to put it on the page and — later — to render
   the content a crawler needs.

---

## 2. The embed contract, in full

This is what the widget is. It is stable and documented at
`docs/embedding.md` in SahajAtlasWeb — **read that file; it is the host-facing source of truth** and
is more current than any summary.

### The snippet a human would paste

```html
<script type="module" src="https://sahajatlas.com/auto.js?key=YOUR_KEY"></script>
```

`auto.js` is a ~3 KiB loader. It reads its settings from **its own script URL's query string** —
there are no HTML attributes anywhere — then lazily fetches the widget (~302 KiB gz) when the embed
nears the viewport.

### The five parameters

| Parameter | Default                  | Notes for the plugin                                                                      |
| --------- | ------------------------ | ----------------------------------------------------------------------------------------- |
| `key`     | —                        | **Required.** A published, non-secret client key. Ships in page HTML by design.           |
| `map`     | `true`                   | `map=false` renders lists and event pages with no map canvas. Genuinely differs per page. |
| `locale`  | the page's `<html lang>` | **The plugin must NOT set this.** See §4.                                                 |
| `routing` | `query`                  | `query` or `path`. **Likely to be removed** — see §6.                                     |
| `atlas`   | —                        | A default route (`/gb/london`) for a page dedicated to one place.                         |

Only the exact strings `false` and `0` switch a boolean off.

### ⚠ The architectural fact that shapes the whole plugin

**You do not need to inject an inline `<script>` into post content.** I verified this in
`SahajAtlasWeb/src/loader/index.ts`:

- **Position** — `resolveElement()` first does `document.querySelector('sahaj-atlas')`. If an element
  already exists it claims that one and renders there. Only when none exists does it insert one
  before its own script tag.
- **Config** — the loader captures
  `document.currentScript ?? document.querySelector('script[src*="auto.js"]')`. So even when
  `currentScript` is null — which `async` and `defer` both cause — it still finds its own URL and
  reads its parameters.

**Therefore the WordPress-native shape works and is the one to build:**

```
block/shortcode output:   <sahaj-atlas></sahaj-atlas>
enqueued separately:      auto.js?key=…&map=…   (type="module")
```

Verify this yourself before committing to it — it is the single most consequential fact here, and
`docs/embedding.md`'s troubleshooting table still tells humans to avoid `defer`, which is correct
advice for a hand-pasted snippet and misleading for this case.

### One widget per page

The loader refuses a second copy and says so in the console. Two widgets would both write the same
`?atlas=` parameter and fight. **The block must prevent a second instance on the same page**, or at
minimum warn in the editor — a silently non-rendering second block is a support ticket.

### Sizing

- `map=true` **takes over the viewport** (`position: fixed; inset: 0`). It wants a dedicated page.
  Dropped into an article column it renders a compact card instead, whose button opens the map
  full-screen.
- `map=false` fills its container, and **an unsized custom element is an inline box of zero
  height**. The block must give it a height, or it appears not to render. This is the single most
  common "nothing happened" report.

---

## 3. Path routing — what it asks of WordPress

Path routing turns `?atlas=/gb/london` into `/classes/gb/london`, which is the shape a search engine
and a reader both prefer. **It is the main reason a plugin can do something a snippet cannot.**

It needs two things:

1. **The server must serve the same page for everything under the prefix.** In-widget clicks are
   `pushState` and never hit the server — but a reload, bookmark or shared link does, and
   `/classes/gb/london` must return the page the embed is on. **This is a WordPress rewrite rule,
   and shipping it is the plugin's job.**
2. **A canonical embed on the client record**, in SahajCloud. The prefix comes from there, not from
   the plugin — deliberately, because it is the same value canonical URLs are composed from and two
   copies could disagree. The plugin does not configure it and should not try.

If either is missing the widget falls back to query routing and logs which one. That degradation is
already built and tested; the plugin does not need to reimplement the check.

**Design question to answer with research, not assumption:** can the plugin derive which page needs
the rewrite from `has_block()` at save time, rather than asking the admin to pick a page? That would
remove a setting. Consider multiple atlas pages, drafts, trashing, and permalink changes.

⚠ **Rewrite rules must be flushed on activation/deactivation and when the atlas page changes**, and
flushing on every request is a well-known performance mistake. Get this right; it is the classic
WordPress plugin bug.

---

## 4. Configuration — the complete permitted surface

**Site-wide (one setting):**

| Setting     | Why it earns its place                                                                     |
| ----------- | ------------------------------------------------------------------------------------------ |
| **API key** | Required, non-secret, identical for every embed on the site. There is no way to derive it. |

**Per block / shortcode instance (two, both optional):**

| Attribute   | Why it earns its place                                                                      |
| ----------- | ------------------------------------------------------------------------------------------- |
| **`map`**   | A site can legitimately have a full-page map _and_ a map-less sidebar embed. Not derivable. |
| **`atlas`** | A page dedicated to one city or one event's registration form. Not derivable.               |

**That is the whole surface.** Everything else is either derivable or belongs elsewhere:

- **Locale** — the widget reads the page's `<html lang>`, which WordPress already sets from the site
  language (and per-post language on multilingual setups). Offering a locale setting would create a
  second source of truth that can disagree with the page it is on. **Do not add it.**
- **Routing mode** — moving to the client record (SahajCloud#644). Do not surface it.
- **Path prefix** — comes from the client record. Do not surface it.
- **Brand name, colours** — client record.
- **Analytics / privacy toggles** — deliberately do not exist in the widget.
- **Widget height** — CSS. Give the block a sensible default and let the theme win.

If you conclude a further setting is genuinely required, **write down the use case somebody hit**
before adding it.

---

## 5. Phase 2 — SEO (blocked on SahajCloud C3)

Do not start this until `GET /api/{events,regions}/{id}/seo?locale=xx` exists. When it does:

- **Read `?atlas=` server-side**, resolve it against that endpoint, and **render the result as
  children of `<sahaj-atlas>`**. The widget replaces them when it upgrades. Crawlers, scrapers and
  no-JS visitors get real HTML in the real document.
- **Emit `<link rel="canonical">`, `og:*`, hreflang over the widget's ten locales, and JSON-LD** into
  the head. The canonical must be byte-identical to what SahajCloud composes.
- **Suppress the theme's own canonical** for atlas routes.
- **Proxy or extend the sitemap.**

### ⚠ The biggest real-world risk in phase 2

**Most of these thirteen sites almost certainly run Yoast, Rank Math or AIOSEO.** Two plugins
emitting a canonical and `og:` tags for the same URL is worse than neither. Before writing anything:

- Find the documented integration point for each (they all have filters — `wpseo_canonical`,
  `rank_math/frontend/canonical`, `aioseo_canonical_url`, and equivalents for `og:`).
- Decide whether the atlas plugin **overrides** those or **feeds** them. Feeding is usually right and
  is far less code.
- Establish what happens when none is installed and WordPress core's own `rel_canonical` applies.
- **This is a research task with a written answer**, not something to discover while debugging a
  client's site.

### The shared URL contract

`atlas-url-contract.json` defines the exact shape of a canonical atlas URL. It is byte-identical in
SahajCloud and SahajAtlasWeb, each with a spec asserting against it.

- Source: `https://raw.githubusercontent.com/sydevs/SahajCloud/main/src/lib/atlas/atlas-url-contract.json`
- **The plugin is the third consumer and must adopt it the same way** — copy the file, assert against
  it in PHPUnit, and add a sync/drift check. Do not re-derive the rules from prose.
- It already caught a real defect: SahajCloud once emitted `?atlas=events%2F12345`, which the widget
  refuses outright. That was found by hand. The fixture exists so the next one is found by a gate.

---

## 6. Known upstream movement — do not design around today's shape

- **SahajCloud#644** proposes an operator-settable routing mode, after which the `routing` script
  parameter disappears. **Do not build UI for it.**
- **SahajAtlasWeb#166** asks whether `auto.js` and `embed.js` should merge. The plugin should
  reference `auto.js` and nothing else, so the outcome is invisible to it.
- **The origin cutover (#148 Part 2) has not happened.** Live clients are still served a legacy build
  from `atlas.sydevelopers.com`. New embeds should point at `https://sahajatlas.com/auto.js`.
  ⚠ **Nothing this plugin ships reaches a real client until that cutover**, so it will get no
  production traffic to shake it out — which is an argument for a small surface, and for testing
  against a real WordPress install rather than assuming.

---

## 7. Research tasks — do these first, and write down the answers

The plugin ecosystem moves, and this brief was written by someone whose knowledge has a cutoff.
**Verify rather than trust the following**, and record what you find in the repo:

1. **Current WordPress and PHP floors.** What versions are worth supporting in 2026, and what does
   that unlock? A good answer names the WP version, the PHP version, and one thing each buys you.
2. **Block registration, current best practice.** Is `block.json` + `register_block_type` still the
   idiomatic path? Is a build step avoidable for a block this simple? Does the Interactivity API
   apply here, or is it irrelevant because the widget owns its own interactivity? (I believe the
   latter — confirm.)
3. **Script modules.** `wp_enqueue_script_module` exists in recent WP for `type="module"` scripts.
   Confirm the version, and confirm it produces a tag the loader's
   `querySelector('script[src*="auto.js"]')` fallback can still find. **Test this in a browser, not
   on paper** — it decides §2's architecture.
4. **The block editor renders in an iframe.** `customElements` registries are per-document, so the
   widget can never upgrade inside the editor. Confirm which WP versions iframe the editor, and
   decide the editor preview: a static placeholder, or an iframe preview of the front end. **A blank
   block in the editor is a support burden**, so this needs a deliberate answer.
5. **Rewrite rules**: correct registration, correct flush timing, and how to avoid flushing per
   request. Also what happens on permalink-structure changes.
6. **SEO plugin coexistence** — see §5. The most important research item in the whole brief.
7. **Core sitemaps.** WordPress has shipped `wp-sitemap.xml` since 5.5 with a provider interface.
   Can the atlas's URLs be added as a provider rather than proxying a separate sitemap? Also: how do
   Yoast/Rank Math replace core sitemaps, and what does that mean for a provider you register?
8. **Distribution.** This is undecided and is a real blocker for adoption:
   - The wordpress.org plugin directory (free updates, review queue, GPL, guideline constraints), or
   - a self-hosted updater against GitHub releases, or
   - a zip somebody uploads.
     A good answer weighs the review overhead against thirteen sites that need updating when the
     embed contract changes. **Recommend one, with reasons, before building the update path.**
9. **Testing.** What does a modern WordPress plugin's test setup look like — PHPUnit with
   `wp-env`/`wp-browser`? What is worth testing here given how thin the plugin should be? Do not
   build a test pyramid for four responsibilities, but the URL contract and the rewrite rules
   deserve assertions.

---

## 8. Traps already paid for — do not rediscover these

- **A `<sahaj-atlas>` with no height renders nothing** in `map=false`, and looks like a broken
  install.
- **`map=true` covers the page.** In a narrow slot the widget now shows a compact card instead. That
  is deliberate; do not try to "fix" it from the plugin.
- **The widget never writes to the host `<head>`.** The server owns the head, the element's children
  own the body. Phase 2 must respect that split — it is why the SEO half is the plugin's job at all.
- **`?p=123`** is WordPress's default permalink and the widget preserves it. Do not assume pretty
  permalinks.
- **`atlas` is not a WordPress reserved query var.** `embed` **is** — do not use it as a name.
- **CSP and Permissions-Policy**: `docs/embedding.md` carries the full table. If the plugin documents
  anything, link that rather than copying it — it has been wrong-by-copy before.

---

## 9. Definition of done, phase 1

- A block and a shortcode that render `<sahaj-atlas>` and enqueue `auto.js` with the site's key.
- One site setting (the key), two optional per-instance attributes (`map`, `atlas`), nothing else.
- The rewrite rule for path routing, flushed correctly, on the right page(s).
- A non-blank block editor experience.
- A second instance on a page is prevented or clearly warned about.
- An admin notice when the key is missing.
- Written answers to §7, in the repo.
- Tested against a real WordPress install, including a non-Administrator author saving a page —
  **the case the plugin exists for.**

## 10. Where the truth lives

|                            |                                                               |
| -------------------------- | ------------------------------------------------------------- |
| Host-facing embed contract | `docs/embedding.md` (SahajAtlasWeb) — **the source of truth** |
| What changes under hosts   | `CHANGELOG.md` (SahajAtlasWeb)                                |
| Programme context          | `.claude/reports/whitelabel-seo-program.md` (SahajAtlasWeb)   |
| URL contract fixture       | `src/lib/atlas/atlas-url-contract.json` (SahajCloud)          |
| Loader behaviour           | `src/loader/index.ts` (SahajAtlasWeb)                         |

**Ask rather than infer** where this brief and the code disagree. The brief was written on
2026-08-25 against `main` at `aad76b6`; the code is what ships.
