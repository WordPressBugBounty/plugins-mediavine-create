=== Create ===
Contributors: mischiefmarmot
Donate link: https://create.studio
Tags: recipe, recipe card, how to, schema, seo
Requires at least: 6.5
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Complete tool for creating and publishing recipes and other schema types on your site.

== Description ==

= A Plugin for Bakers. Makers. Adventure-takers. =
Top in tech, speed, and SEO so you can focus on what you do best and CREATE.

Now you can craft multiple Google Schema.org types using just one plugin.

* Recipes
* How-to guides and craft instructions
* Lists and round-ups
* More to come!

Now: Automatically calculate nutritional data for your recipes for free.

= Create is for... =

**Recipes** — Easily import content from other plugins. Includes free nutrition calculator and video embeds.
**Lists and round-ups** — Showcase images, links and more in a user-friendly manner.
**How-to guides** — Display beautiful printable materials lists, instructions and videos for DIYs, crafts and more.

= Create was built with the following in mind: =
**1. Speed**
Lightweight, with our strong focus on site speed

**2. Optimized for SEO**
Full Google Rich Snippet support and one-button schema validation so content is marked up for mobile search carousels

**3. Easy to Use**
Built for optimal user experience, for you and your readers

**4. Top-notch Importers**
Easily transfer your content from other recipe plugins

**5. Multiple Themes**
Five gorgeous themes by Purr Design with more on the way

**6. Ad-Ready**
Fully monetize your content using the most-ad-optimized themes

**7. Matches your site**
All themes mimic your site's unique design so no two look the same

**8. Live Preview**
See your content how it will appear on your site, in real time, with full Gutenberg support

**9. Mobile First**
Responsively designed to engage the majority of your audience

== Installation ==

= Minimum Requirements =

* PHP version 7.4 or greater
* MySQL version 6.5 or greater

= Automatic Installation =

1. Go to Plugins > Add New
1. Type "Create" in the search field and click "Search Plugins"
1. Click "Install Now" to install and then click "Activate"
1. Go to Settings > Create and choose your card style
1. [Register your Create plugin](https://help.create.studio/en/articles/8916417)
1. If using another recipe card plugin and you'd like to import your recipes from that plugin, [download and install the Recipe Importers utility](https://create.studio/downloads/create-recipe-importers.zip)

= Manual Installation =

1. [Download a copy of the "Create" plugin](https://downloads.wordpress.org/plugin/mediavine-create.latest-stable.zip)
1. Upload `mediavine-create` to the `/wp-content/plugins/` directory
1. Activate the plugin through the "Plugins" menu in WordPress
1. Go to Settings > Create and choose your card style
1. [Register your Create plugin](https://help.create.studio/en/articles/8916417)
1. If using another recipe card plugin and you'd like to import your recipes from that plugin, [download and install the Recipe Importers utility](https://create.studio/downloads/create-recipe-importers.zip)

For more, please see our [help center](https://help.create.studio).

== Frequently Asked Questions ==

= How do I import my existing recipes? =

[Download and install the Recipe Importers utility](https://create.studio/downloads/create-recipe-importers.zip)

= Which recipe card plugins does the importer support?

* Cookbook
* EasyRecipe
* Meal Planner Pro Recipes
* Purr Recipe Cards
* Simple Recipe Pro
* WP Recipe Maker
* WP Tasty
* WP Ultimate Recipe
* Yummly
* Zip Recipes
* ZipList Recipe Plugin

= How will the cards display? =
Our cards are displayed using a WordPress shortcode.

This means that if the plugin is disabled, the recipes themselves will not display on the front end of a blog post. This is typical behavior for most WordPress plugins.

If the plugin is deactivated, no data will be deleted and reactivating the plugin will restore the original card display.

= Will I be able to add nutritional data? =

Yes! Nutritional data is an important part of Schema, which search engines love to have for optimal results.

Nutrition facts can be manually entered for a recipe. They will also transfer over if the recipe already contains it.

We also provide automatic nutrition calculation with [API Ninjas Nutrition](https://api-ninjas.com/api/nutrition). [Learn more about this feature](https://help.create.studio/en/articles/8914561).

= How much does it cost? =

Create is free to the blogging community at large. You do not need to be a Mediavine publisher to use it. All core functions of the plugin will always remain free.

There may be features in the future that would need a license for a fee, but the core functionalities will always remain free and supported for everyone — including plugin updates to keep Create in compliance with WordPress releases.

= Where do I report security bugs found in this plugin? =
Please report security bugs found in the source code of the Create plugin through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/mediavine-create). The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Screenshots ==

1. Choose between Recipe, How-To and List cards. (More types coming soon.)
2. Refreshed interface design provides a better user experience.
3. View all of your cards at a glance in the Create card gallery.
4. Search and sort all of your cards for easy editing.
5. Create SEO-ready Recipe cards in minutes.
6. A published Recipe card using the Hero Image card style.
7. A published Recipe card using the Simple Square card style.
8. Our automatic nutrition calculator saves you time and headaches.
9. Publish beautiful lists and round-ups with the List card type.
10. A published List card using the Big Image layout.
11. A published List card using the Circles layout.
12. How-to cards can be used for any kind of instructional guide.
13. A published How-To card on the Dark Classy Circle card style.
14. A published How-To card on the Hero Image card style.
15. Add recommended products to your Recipe and How-To cards.
16. All card styles adapt to your site's existing design.

== Changelog ==

= 1.10.5 =
* FIX: Resolve PHP 8.x fatal error when saving list relations with empty or array-type meta fields

= 1.10.4 =
* FIX: Restore soft returns (Shift+Enter line breaks) in WYSIWYG instructions editor

= 1.10.3 =
* FIX: Restore Slate editor CSS fix for Chrome 105+ to prevent cursor jumping in WYSIWYG editors

= 1.10.2 =
* FEATURE: Add "Rating" sort option to card collections that uses weighted rating (Bayesian average)
* ENHANCEMENT: Display star ratings and review counts in card grid and list views
* FIX: Restrict admin script enqueuing to Create-specific pages to prevent variable conflicts with other plugins and resolve Gutenberg block registration issues
* FIX: Classic editor toolbar buttons display correctly in Code tab editor
* FIX: Build output wrapped with IIFE wrapper to prevent strict mode variable leakage

= 1.10.1 =
* FEATURE: Add "Posts" dropdown navigation to card editor to easily navigate to a card's parent posts
* ENHANCEMENT: Include descriptions from external links and posts when building Lists
* FIX: Automatically republish cards with missing `<ol>` and `<ul>` tags in instructions
* FIX: Retain `href`/link in Instructions when editing a card
* FIX: Improve WordPress 6.5+ compatibility by using traditional script enqueuing
* FIX: Skip synchronous image processing during REST API requests to prevent timeouts on list card saves
* FIX: Adds checks in color mixing functions to prevent PHP 8+ fatal errors (thanks Peter/Deep Roots Hosting!)
* FIX: Resolve "spastic" editing and unusability in detail ingredient editor


= 1.9.16 =
* FEATURE: Change to new ownership!
* ENHANCEMENT: Upgrade to PHP 7.4, Node 18 & 22 for modern features
* ENHANCEMENT: Upgrade to WordPress 6.5 for modern features

= 1.9.15 =
* FIX: Improved instructions processing with robust DOM handling
* FIX: Fixed Mediavine video aspect ratio

= 1.9.14 =
* FIX: Greek characters properly render in instructions

= 1.9.12 =
* FIX: Recipe card ratings display in structured data
* FIX: Mediavine video selection works with new Mediavine videos endpoint, and video thumbnails display correctly