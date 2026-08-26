=== Sahaj Atlas ===
Contributors: sydevs
Tags: meditation, map, events, classes
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the Sahaj Atlas — a searchable map of free meditation classes — to your WordPress site.

== Description ==

This plugin embeds the Sahaj Atlas on your site: a searchable map and directory of free meditation
classes, with pages for each country, city, venue and class.

It creates one "Find a class" page for you and keeps it working. You do not need to paste any code.

**You will need an API key.** Ask the Sahaj Atlas maintainers for one — it is free, it is not a
secret, and one key covers your whole site.

= What it adds to your site =

* A single Atlas page, created for you from the plugin's settings screen — your site's own header
  at the top, the atlas filling the rest of the screen, and no footer.
* A shortcode and a block for showing one class, or one class's registration form, inside your own
  pages.
* A status panel that tells you whether your key works and whether everything is set up correctly.
* A sitemap of every atlas page, announced in your `robots.txt`, so search engines can find them.

= Services this plugin uses =

This plugin is an interface to the Sahaj Atlas service, which is operated by Sahaja Yoga
International. Using it means your visitors' browsers contact:

* `sahajatlas.com` — the atlas widget itself and its translations.
* `cloud.sydevelopers.com` — the class and location data.

Your site also contacts `cloud.sydevelopers.com` from the server to read page metadata.
Full details of every request, and what leaves a visitor's browser, are documented at
https://github.com/sydevs/SahajAtlasWeb/blob/main/docs/embedding.md

== Installation ==

⚠ **If your site already shows the Sahaj Atlas** — an older embed, an iframe, or a pasted script —
**remove it first.** Two atlases on one page will not work.

1. Download `sahaj-atlas.zip` from the Releases page. Do **not** use "Source code (zip)".
2. In WordPress, go to Plugins → Add New Plugin → Upload Plugin, choose the file, and install it.
3. Activate the plugin.
4. Go to Settings → Sahaj Atlas, paste your API key, and press "Create the Atlas page".
5. Add the new page to your site's menu.

== Frequently Asked Questions ==

= Where do I get an API key? =

Ask the Sahaj Atlas maintainers. Keys are issued by hand, one per site.

= The page is blank / the map does not appear =

Open Settings → Sahaj Atlas. The status panel names the problem.

= What are "clean URLs"? =

With them on, a link to a city looks like `yoursite.org/find-a-class/gb/london`. Search engines can
index those, and visitors can share them. With them off the same place is
`yoursite.org/find-a-class/?atlas=/gb/london`, which still works but is less shareable.

They need two things: your site must use any permalink setting other than "Plain", and the Sahaj
Atlas maintainers must have your page's address on file. The status panel tells you which is
missing and shows you the address to send them.

= Can the atlas be my site's front page? =

Not with clean URLs. The atlas would have to answer every address on your site, including the ones
that should show "not found". Give it a page of its own and link to it from your menu.

= I use Elementor / WPBakery / Beaver Builder =

That is fine — the Atlas page does not use them, and the shortcode works inside all of them. If
your page builder takes over the Atlas page's layout, set that page to the builder's blank or
canvas template and place the `[sahaj_atlas]` shortcode on it.

== Changelog ==

= 0.1.0 =
* First release.
* Creates and owns one Atlas page per site: your site's header, then the atlas, no footer.
* Serves deep atlas links as real URLs (`/find-a-class/gb/london`) when the site uses pretty
  permalinks and the maintainers have registered the page.
* Server-rendered page titles, descriptions, canonicals, hreflang, Open Graph and structured data
  for every atlas link, replacing whatever Yoast, All in One SEO or Rank Math would emit there.
* A `[sahaj_atlas]` shortcode and a block for showing one class, or its registration form, inside
  your own pages.
* A sitemap at `/sahaj-atlas-sitemap.xml`, announced in `robots.txt` and added to Yoast's and Rank
  Math's sitemap indexes. Nothing on your site links into the atlas pages, so this is how search
  engines find them at all.
* A status panel covering the four things that otherwise fail silently: the API key, the Atlas
  page, clean URLs, and whether this domain is registered.
