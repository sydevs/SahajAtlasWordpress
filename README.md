# Sahaj Atlas — WordPress plugin

Adds the [Sahaj Atlas](https://github.com/sydevs/SahajAtlasWeb) — a searchable map of free
meditation classes — to a WordPress site.

**Site owners:** see [Installation](#installation) below. You do not need to paste any code.
**Developers:** start with [`AGENTS.md`](AGENTS.md), then `docs/implementation-plan.md`.

## Why a plugin rather than a snippet

WordPress removes `<script>` tags from saved content, for every role below Administrator and
every Site Administrator on multisite. A plugin's own output skips this filter, so a plugin is
the only way most site editors can install the atlas.

## Installation

⚠ **If your site already shows the Sahaj Atlas** — an old embed, an iframe, or a pasted script —
**remove it first.** Two atlases on one page will not work.

1. Download the newest **`sahaj-atlas-<version>.zip`** from the [Releases](../../releases) page —
   take the highest version number. ⚠ Not "Source code (zip)": that one installs incorrectly.
2. **Plugins → Add New Plugin → Upload Plugin** → choose the file → **Install Now**.
3. **Activate** the plugin.
4. **Settings → Sahaj Atlas**: paste your API key, then press **Create the Atlas page**.
5. Add the new page to your site's menu.

Updates then arrive the normal way: WordPress shows an update notice, and you can turn on
automatic updates.

### Getting an API key

Ask the Sahaj Atlas maintainers for a key. They issue one per site by hand. The key is
**not a secret** — it ships in your page's HTML by design, with read-only access to atlas data.

## Something looks wrong

**Settings → Sahaj Atlas** has a status panel for the usual problems: your key, the Atlas page,
and clean URLs. Start there.

## Development

No Docker, no system PHP —
[`@wp-playground/cli`](https://www.npmjs.com/package/@wp-playground/cli) runs PHP in WebAssembly.

```
pnpm install
pnpm test:all        # syntax, behavior, render — what CI runs
pnpm start           # a real WordPress site with the plugin, for manual testing
```

Each lane also runs alone: `pnpm lint` (syntax), `pnpm test` (behavior suite, on WordPress 6.7
and PHP 7.4, the fleet's floor), `pnpm test:render` (real HTTP against a real server, once per
theme kind, since block and classic themes take different code paths).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
