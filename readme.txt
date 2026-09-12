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

= I use Elementor / WPBakery / Beaver Builder =

That is fine. The Atlas page does not use a page builder, and the shortcode works inside all
three. If your page builder takes over the Atlas page's layout, set that page to the builder's
blank or canvas template, and place the `[sahaj_atlas]` shortcode on it.

== Changelog ==

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
* Adds a status panel for the API key, the Atlas page, clean URLs, domain registration, and which
  side describes the Atlas page.
