=== Create by Mediavine ===
Contributors: mediavine
Donate link: https://www.mediavine.com
Tags: recipe, recipe card, how to, schema, seo
Requires at least: 6.3
Tested up to: 6.8.2
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

[youtube https://www.youtube.com/watch?v=OmtqDGi3Nc4]

= Create is for... =

**Recipes** — Easily import content from other plugins. Includes free nutrition calculator and video embeds.
**Lists and round-ups** — Showcase images, links and more in a user-friendly manner.
**How-to guides** — Display beautiful printable materials lists, instructions and videos for DIYs, crafts and more.

= Create by Mediavine was built with the following in mind: =
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

* PHP version 5.4.45 or greater (PHP 7.2 or greater is recommended)
* MySQL version 5.5 or greater (MySQL 5.6 or greater is recommended)

= Automatic Installation =

1. Go to Plugins > Add New
1. Type "Create by Mediavine" in the search field and click "Search Plugins"
1. Click "Install Now" to install and then click "Activate"
1. Go to Settings > Create by Mediavine and choose your card style
1. [Register your Create plugin](https://help.mediavine.com/create-by-mediavine/how-to-register-your-create-plugin)
1. If using another recipe card plugin and you'd like to import your recipes from that plugin, [download and install the Mediavine Recipe Importers utility](https://www.mediavine.com/mediavine-recipe-importers-download)

= Manual Installation =

1. [Download a copy of the "Create by Mediavine" plugin](https://downloads.wordpress.org/plugin/mediavine-create.latest-stable.zip)
1. Upload `mediavine-create` to the `/wp-content/plugins/` directory
1. Activate the plugin through the "Plugins" menu in WordPress
1. Go to Settings > Create by Mediavine and choose your card style
1. [Register your Create plugin](https://help.mediavine.com/create-by-mediavine/how-to-register-your-create-plugin)
1. If using another recipe card plugin and you'd like to import your recipes from that plugin, [download and install the Mediavine Recipe Importers utility](https://www.mediavine.com/mediavine-recipe-importers-download)

For more, please see our [help center](https://help.mediavine.com/create-by-mediavine).

== Frequently Asked Questions ==

= How do I import my existing recipes? =

[Download and install the Mediavine Recipe Importers utility](https://www.mediavine.com/mediavine-recipe-importers-download)

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

We also provide automatic nutrition calculation through our partnership with [Nutritionix](http://nutritionix.com/). [Learn more about this feature](http://help.mediavine.com/create-by-mediavine/auto-calculate-nutrition-with-create-by-mediavine).

= How much does it cost? =

Create is free to the blogging community at large. You do not need to be a Mediavine publisher to use it. All core functions of the plugin will always remain free.

There may be features in the future that would need a license for a fee, but the core functionalities will always remain free and supported for everyone — including plugin updates to keep Create in compliance with WordPress releases.

= Where do I report security bugs found in this plugin? =
Please report security bugs found in the source code of the Create by Mediavine plugin through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/mediavine-create). The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

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

= 1.9.15 =
* FIX: Improved instructions processing with robust DOM handling
* FIX: Fixed Mediavine video aspect ratio

= 1.9.14 =
* FIX: Greek characters properly render in instructions

= 1.9.12 =
* FIX: Recipe card ratings display in structured data
* FIX: Mediavine video selection works with new Mediavine videos endpoint, and video thumbnails display correctly

= 1.9.11 =
* FIX: Fixes author dropdown to include WP author names
* REMOVE: Removes unused code

= 1.9.10 =
* FIX: Re-adds edit review functionality after patching Reviews API endpoints

= 1.9.9 =
* FIX: Patches potential sensitive data exposure vulnerability through Reviews API

= 1.9.8 =
* FIX: Patches potential XSS security vulnerability