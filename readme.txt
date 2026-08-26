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

* A single Atlas page, created for you from the plugin's settings screen.
* A shortcode and a block for showing one class, or one class's registration form, inside your own
  pages.
* A status panel that tells you whether your key works and whether everything is set up correctly.

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

== Changelog ==

= 0.1.0 =
* Not yet released.
