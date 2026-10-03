=== Sahaj Atlas ===
Contributors: sydevs
Tags: meditation, map, events, classes
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the Sahaj Atlas — a searchable map of free meditation classes — to your WordPress site.

== Description ==

This plugin embeds the Sahaj Atlas: a searchable map and directory of free meditation classes,
with pages for each country, city, venue, and class.

It creates and maintains one "Find a class" page for you. You do not need to paste any code.

**You need an API key.** Ask the Sahaj Atlas maintainers for one. The key is free, not secret,
and covers your whole site.

= What it adds to your site =

* One Atlas page, created from the plugin's settings screen. It shows your site's header at the
  top and the atlas below it, with no footer.
* A shortcode and a block. Use them to show one class, or its registration form, inside your own
  pages.
* A title and description for your Atlas page, written by Sahaj Atlas in each visitor's own
  language. One checkbox on the settings screen hands that back to your own SEO plugin.
* A status panel. It shows whether your key works and whether setup is correct.
* A sitemap of every atlas page, listed in your `robots.txt` file, so search engines can find
  them.

= Services this plugin uses =

This plugin connects to the Sahaj Atlas service, operated by Sahaja Yoga International. When
visitors use the atlas, their browsers contact:

* `sahajatlas.com` — the atlas widget and its translations.
* `cloud.sydevelopers.com` — class and location data.

Your server also contacts `cloud.sydevelopers.com` to read page metadata. Full details of every
request are documented at
https://github.com/sydevs/SahajAtlasWeb/blob/main/docs/embedding.md

== Installation ==

⚠ **If your site already shows the Sahaj Atlas** — an old embed, an iframe, or a pasted script —
**remove it first.** Two atlases on one page will not work.

1. On the Releases page, download the newest `sahaj-atlas-<version>.zip` file. The version number
   changes with every release. Do **not** use "Source code (zip)".
2. In WordPress, go to Plugins → Add New Plugin → Upload Plugin. Choose the file, and install it.
3. Activate the plugin.
4. Go to Settings → Sahaj Atlas. Paste your API key, and press "Create the Atlas page".
5. Add the new page to your site's menu.

== Frequently Asked Questions ==

= Where do I get an API key? =

Ask the Sahaj Atlas maintainers. They issue one key per site by hand.

= The page is blank / the map does not appear =

Open Settings → Sahaj Atlas. The status panel names the problem.

= What are "clean URLs"? =

With clean URLs on, a city link looks like `yoursite.org/find-a-class/gb/london` — easy for
search engines to index and visitors to share. Off, the same page is
`yoursite.org/find-a-class/?atlas=/gb/london`, which still works but is harder to share.

Clean URLs need two things: a permalink setting other than "Plain", and your page's address on
file with the Sahaj Atlas maintainers. The status panel names what is missing and shows the
address to send.

= Can the atlas be my site's front page? =

Not with clean URLs on. The atlas would then have to answer every address on your site, including
addresses that should show "not found". Give the atlas its own page, and link to it from your
menu.

= My SEO plugin's description for the Atlas page stopped showing =

That is Sahaj Atlas describing the page instead, in each visitor's own language. It does this for
your Atlas page and for every country, city and class page under it.

To keep your own description for the Atlas page, tick "Let my SEO plugin describe the Atlas page"
under Settings → Sahaj Atlas. Country, city and class pages stay with Sahaj Atlas either way —
your SEO plugin has never seen those addresses, and would describe every one of them as your
Atlas page.

= Search engines are not finding my atlas pages =

Nothing on your site links into the atlas, so a sitemap is the only way a search engine learns
those pages exist. The plugin publishes one and adds a `Sitemap:` line to your `robots.txt`.

WordPress can only write that line when your permalinks are not set to "Plain" and WordPress runs
at the top level of your domain. If it runs in a subfolder, or your site has a real `robots.txt`
file, WordPress never writes the line and nothing says so.

The status panel's "Sitemap" row names which of those applies, and shows the exact address or line
you need. Where there is a line to paste, paste it at the end of the `robots.txt` file at the top
of your domain — the one at `yoursite.org/robots.txt`, not one inside the subfolder. Search
engines read no other. If your site is one of a network, there is no such file to edit: submit the
address the row shows to Google Search Console and Bing Webmaster Tools instead.

= My theme's header image or page title is missing on the Atlas page =

On that page only, and on purpose: the map needs the height, and without it visitors get a single
"Find a class near you" button instead of the map. Your logo and menu stay, and every other page on
your site is untouched.

The plugin asks your theme to leave the image out, using the theme's own switch for it. A theme with
no such switch keeps its header image.

= I use Elementor / WPBakery / Beaver Builder =

That is fine, and there is nothing to set up. The Atlas page does not use a page builder.

If your builder renders the Atlas page with its own template, the map appears in the page's content
area instead, with your site's footer below it. Nothing to change. The `[sahaj_atlas]` shortcode is
for your other pages — a single class, or its registration form — and is not needed on the Atlas
page.

Settings → Sahaj Atlas names where the map rendered. If that row is red, send it to the Sahaj Atlas
maintainers.

== Changelog ==

= 0.3.0 =
* The Atlas page now renders the map even when a page builder or another template takes the page
  over. The map then sits in the page's content area, with your site's footer below it.
* The status panel gained three rows. One shows which part of your page printed the map. One
  checks that the map's script tag reached the page unchanged. One shows how search engines find
  the atlas sitemap, and gives the line to add to your `robots.txt` file when the plugin cannot.
* The plugin can now load translations of its settings screen. Translators can start from the
  template file shipped in `languages/`. No translation ships yet.
* Fixed: on the Esotera, Fluida, OceanWP and Seva Lite themes, the Atlas page leaves out the
  theme's header image or page-title band, using the theme's own switch for it. The full map now
  fits instead of a single "Find a class near you" button. Your logo and menu stay.
* Fixed: when a theme's header is fixed to the top of the screen, it no longer covers the map,
  the search box or the top of the side panel.
* Fixed: when a cookie banner, a late-loading logo or a wrapping menu makes the header taller, the
  map now shrinks to fit. Before, its bottom edge slid off the screen.
* Fixed: on Twenty Twenty-Four, Twenty Twenty-Five, PopularFX and Mesmerize, the map now reaches
  the bottom of the screen. Before, it stopped a few pixels short.
* Fixed: on sites running Yoast, country, city and class pages now carry the title Sahaj Atlas
  writes for them. On some themes they had no title at all.
* Fixed: on sites with plain or `index.php` permalinks, the plugin no longer gives search engines a
  sitemap address that leads to a "not found" page.
* Fixed: a theme without its own header file no longer shows a 2010-era default header on the
  Atlas page.
* Changed: the readme's advice for page-builder sites now describes what actually happens. The
  `[sahaj_atlas]` shortcode is for your other pages, not the Atlas page.

= 0.2.1 =
* Fixed: on the Mesmerize and Mesmerize Pro themes, the Atlas page no longer shows the theme's
  large header image above the map. The page keeps your site's menu, and the full map now fits
  on laptops and phones instead of a single "Find a class near you" button.

= 0.2.0 =
* Your Atlas page now carries a title and description written by Sahaj Atlas, in each visitor's
  own language. To keep your own, tick "Let my SEO plugin describe the Atlas page" under
  Settings → Sahaj Atlas.
* Sites without clean URLs now get a title, description and search-engine tags on every country,
  city and class page. Before this, those pages named a different address to search engines than
  the one in the sitemap.
* The status panel gained a fifth row. It names which side describes the Atlas page.
* Fixed: a title containing a `<` character no longer breaks the page's HTML.
* Fixed: if the Sahaj Atlas service answers with another site's address, your Atlas page keeps the
  title and description it already had.

= 0.1.0 =
* First release.
* Creates one Atlas page per site, with your site's header and no footer.
* Serves deep atlas links as real URLs, for example `/find-a-class/gb/london`, given pretty
  permalinks and a page registered with the maintainers.
* Renders page titles, descriptions, canonicals, hreflang, Open Graph tags, and structured data
  for every atlas link, replacing any output from Yoast, All in One SEO, or Rank Math.
* Adds a `[sahaj_atlas]` shortcode and a block, for one class or its registration form.
* Adds a sitemap at `/sahaj-atlas-sitemap.xml`, listed in `robots.txt` and in the sitemap indexes
  of Yoast and Rank Math.
* Adds a status panel for the API key, the Atlas page, clean URLs, and domain registration.
