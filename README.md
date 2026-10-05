# Sahaj Atlas for WordPress

This plugin adds the **Sahaj Atlas** — a searchable map of free meditation classes — to your
WordPress site. You do not need to write or paste any code.

It gives you two things:

- **An Atlas page.** A full page with the map, made for you with one click. This is what most
  sites need.
- **A shortcode and a block.** Use these to show the atlas, one country, or one class inside your
  other pages.

---

## Before you start

You need two things:

1. **Administrator access** to your WordPress site.
2. **An API key.** Ask the Sahaj Atlas maintainers for one. It is free, and one key covers your
   whole site. The key is not a password — it is safe to paste and share.

⚠ **If your site already shows the atlas** — an old embed, an iframe, or a pasted script —
**remove it first.** Two atlases on one page will not work.

---

## 1. Install the plugin

1. Go to the [Releases page](../../releases) and download the newest file named
   **`sahaj-atlas-<version>.zip`** (the one with the highest number).
   ⚠ Do **not** download "Source code (zip)". That one will not install correctly.
2. In WordPress, go to **Plugins → Add New Plugin**, and press **Upload Plugin** at the top.
3. Choose the file you downloaded, and press **Install Now**.
4. Press **Activate**.

The plugin updates itself from then on. WordPress shows an update notice like any other plugin,
and you can turn on automatic updates on the **Plugins** screen.

## 2. Set it up

1. Go to **Settings → Sahaj Atlas**.
2. Paste your API key into **API key**, and press **Save Changes**.
3. Press **Create the Atlas page**.
4. Add the new page to your site's menu (**Appearance → Menus**, or **Appearance → Editor →
   Navigation** on newer themes).

That is all. Visit the page to see the map. You can rename the page freely — the atlas follows.

## 3. Choose where the atlas opens (optional)

By default the Atlas page opens on the world list. To open it at **your country or city**:

1. Go to [sahajatlas.com](https://sahajatlas.com), and click through to your country (or city).
2. Copy the address from your browser's address bar. For example:
   `https://sahajatlas.com/gb` (the United Kingdom) or `https://sahajatlas.com/gb/london`.
3. In WordPress, go to **Settings → Sahaj Atlas**, paste it into **Atlas page opens at**, and
   press **Save Changes**.

The plugin checks the address when you save. If it is not a place in the atlas, it tells you, and
nothing changes. To go back to the world list, empty the field and save.

Visitors can still move anywhere in the atlas, and links to other places keep working. Only the
starting point changes.

---

## Show the atlas inside your other pages

The Atlas page is the main way to show the map. To show the atlas **inside another page** — a
"Classes" section on your home page, or one class on an event page — use the shortcode or the
block.

### The shortcode

Type the shortcode into any page or post, or into a "Shortcode" widget in a page builder
(Elementor, WPBakery, Beaver Builder and others).

| What you want to show | Type this |
| --- | --- |
| The list of classes | `[sahaj_atlas]` |
| The map | `[sahaj_atlas map="true"]` |
| The map, opened at your country | `[sahaj_atlas map="true" atlas="https://sahajatlas.com/gb"]` |
| The classes in your city, as a list | `[sahaj_atlas atlas="https://sahajatlas.com/gb/london"]` |
| One class | `[sahaj_atlas atlas="https://sahajatlas.com/gb/london/1234"]` |
| One class's sign-up form | `[sahaj_atlas atlas="https://sahajatlas.com/gb/london/1234/register"]` |

**`atlas`** is where it opens. Find the place or class on [sahajatlas.com](https://sahajatlas.com),
copy the address from your browser, and paste it between the quotes. The number at the end of a
class address (`1234` above) is only an example — use your own class's address, and add
`/register` to the end for its sign-up form. The short form works too: `atlas="/gb"` is the same
as `atlas="https://sahajatlas.com/gb"`.

**`map="true"`** shows the map. Leave it out to show a list without a map.

Good to know:

- **One atlas per page.** A second shortcode on the same page shows nothing.
- **Not on the Atlas page.** That page already has the atlas.
- **Give the map room.** Put it in a full-width section. In a narrow column (under 360 pixels
  wide) the map shows a "Find a class near you" button instead, which opens the map full-screen.

### The block

In the block editor, add the **Sahaj Atlas** block. In the block's settings panel on the right:

- **Open at** is the same as `atlas` above. Paste an address from sahajatlas.com.
- **Show the map** is the same as `map="true"`.

---

## If something looks wrong

Go to **Settings → Sahaj Atlas** and look at the **Status** panel. Each row is green when that
part works, and a red row says what to do. It checks your key, your Atlas page, clean URLs, your
domain, the sitemap, and whether the map loads on your page.

If a row stays red and you are not sure why, send a screenshot of the panel to the Sahaj Atlas
maintainers.

**Common questions**

- **The Atlas page is blank, or the map does not appear.** Check the Status panel. The usual
  causes are a missing key, or a "JavaScript optimisation" setting in a caching plugin — turn that
  off for the Atlas page.
- **My theme's large header image is gone on the Atlas page.** That is on purpose, on that page
  only: the map needs the height. Your logo and menu stay.
- **I use a page builder.** That is fine. If the builder takes over the Atlas page, the map still
  appears, inside the page's content with your footer below it.
- **My SEO plugin's description for the Atlas page stopped showing.** Sahaj Atlas describes the
  page in each visitor's language. To keep your own, tick **Let my SEO plugin describe the Atlas
  page** under Settings → Sahaj Atlas.

---

## For developers

Start with [`AGENTS.md`](AGENTS.md), then `docs/implementation-plan.md`. The widget's own contract
is [`docs/embedding.md`](https://github.com/sydevs/SahajAtlasWeb/blob/main/docs/embedding.md) in
SahajAtlasWeb.

No Docker and no system PHP are needed —
[`@wp-playground/cli`](https://www.npmjs.com/package/@wp-playground/cli) runs PHP in WebAssembly.

```
pnpm install
pnpm test:all        # syntax, measure, behaviour, render — what CI runs
pnpm test:browser    # the real widget in real themes, in Chromium (local only; see AGENTS.md)
pnpm start           # a real WordPress site with the plugin, for manual testing
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
