# Sahaj Atlas — WordPress plugin

Adds the [Sahaj Atlas](https://github.com/sydevs/SahajAtlasWeb) — a searchable map of free
meditation classes — to a WordPress site.

**Site owners:** see [Installation](#installation) below. You do not need to paste any code.
**Developers:** start with [`CLAUDE.md`](CLAUDE.md), then
[`docs/implementation-plan.md`](docs/implementation-plan.md).

> **Status: complete, not yet tagged.** Both phases are implemented and tested; there is no release
> to download until `v0.1.0` is tagged, at which point CI builds the zip the instructions below
> refer to.

## Why a plugin rather than a snippet

WordPress strips `<script>` from saved content for every role below Administrator — and for *every*
Site Administrator on multisite. A plugin's own output does not pass through that filter, so for most
of the people who actually edit these sites, a plugin is the only way the atlas can be installed at
all.

## Installation

⚠ **If your site already shows the Sahaj Atlas** — an older embed, an iframe, or a pasted script —
**remove it first.** Two atlases on one page will not work.

1. Download **`sahaj-atlas.zip`** from the [Releases](../../releases) page.
   ⚠ Not "Source code (zip)" — that one installs incorrectly.
2. WordPress → **Plugins → Add New Plugin → Upload Plugin** → choose the file → **Install Now**.
3. **Activate** it.
4. **Settings → Sahaj Atlas**: paste your API key, then press **Create the Atlas page**.
5. Add the new page to your site's menu.

Updates arrive the normal way once installed — WordPress will show an update notice, and you can
switch on automatic updates for it.

### Getting an API key

Ask the Sahaj Atlas maintainers. Keys are issued by hand, one per site. The key is **not a secret** —
it ships in your page's HTML by design and is scoped to read-only atlas data.

## Something looks wrong

**Settings → Sahaj Atlas** has a status panel that checks the things that usually go wrong: whether
your key is accepted, whether the Atlas page is healthy, and whether clean URLs are working. Start
there.

## Development

No Docker, no system PHP — [`@wp-playground/cli`](https://www.npmjs.com/package/@wp-playground/cli)
runs PHP in WebAssembly.

```
npm install
npm run test:all     # syntax + behaviour + render, which is what CI runs
npm start            # a real WordPress with the plugin mounted, for poking at by hand
```

The three lanes separately: `npm run lint` (syntax), `npm test` (69 assertions in a booted
WordPress 6.7 on PHP 7.4 — the fleet's floor), `npm run test:render` (real HTTP against a real
server, once per theme kind, because block and classic themes take different code paths).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
