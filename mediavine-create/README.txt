=== Create ===
Contributors: mischiefmarmot
Donate link: https://create.studio
Tags: recipe, recipe card, how to, schema, nutrition
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Complete tool for creating and publishing recipes and other schema types on your site.

== Description ==

= Recipes, guides, and lists — for creators who care about their craft. =

Create gives you everything you need to publish recipes, how-to guides, and lists — with correct Schema.org markup, fast page loads, and an editor that stays out of your way. Whether you're sharing recipes, DIY tutorials, travel guides, crochet patterns, game walkthroughs, or curated round-ups, Create handles the structured content so you can focus on creating.

**One plugin. Three card types. Full schema support.**

* **Recipes** — Granular ingredient editing, free nutrition calculator, video embeds, and built-in importers for 10+ recipe plugins
* **How-to guides** — Step-by-step instructions with materials lists, photos, and video for any kind of tutorial or guide
* **Lists and round-ups** — Showcase links, images, and products with bulk import and drag-and-drop ordering

= New Independent Ownership =
In mid-2025, Create was [purchased](https://create.studio/hello) from Mediavine by John-Michael, one of the plugin's founding developers. Create is now fully independent and continues to be actively developed at [Create Studio](https://create.studio).

= Why Create? =

**Fast by default** — Lightweight JavaScript and optimized bundling so your cards don't slow down your pages

**SEO built in** — Recipe, HowTo, and ItemList JSON-LD generated automatically with one-click schema validation

**Looks like your site** — Seven card themes that inherit your fonts and colors, including Editorial and Modern Elegant

**Ad-ready themes** — Optimized card layouts with configurable ad slot placements

**Gutenberg and Classic Editor** — Full block editor support with live preview, plus shortcode fallback

**Built-in importers** — Switch from WP Recipe Maker, Tasty, EasyRecipe, and 8 other plugins without a separate download

**Free nutrition calculator** — Automatic nutrition data powered by [API Ninjas](https://api-ninjas.com/api/nutrition)

**Modern editor experience** — Keyboard shortcuts throughout the app, simple workflows, and unobtrusive customization options designed for how *you* actually work

= Premium Features =
Upgrade through [Create Studio](https://create.studio) to unlock:

* **Interactive Mode** — Turns your cards into a hands-free cooking companion with interactive checklists so readers can check off ingredients and steps as they go
* **Adjustable Servings and Unit Conversion** — Readers scale ingredient quantities and convert between metric and imperial
* **Premium themes** — Editorial and Modern Elegant card designs
* **Review management** — Reader reviews with featured review blocks and response tools
* **Bulk list tools** — Paste URLs to bulk-import list items, plus inline bulk editing
* **Products in Lists** — Add product items for affiliate placements

== Installation ==

= Minimum Requirements =

* PHP version 7.4 or greater
* MySQL version 8.0 or greater
* WordPress version 6.5 or greater

= Automatic Installation =

1. Go to Plugins > Add New
1. Type "Create" in the search field and click "Search Plugins"
1. Click "Install Now" to install and then click "Activate"

= Manual Installation =

1. [Download a copy of the "Create" plugin](https://create.studio/plugin)
1. Upload `mediavine-create.zip` to the `/wp-content/plugins/` directory (using the "Upload Plugin" button or server filesystem access)
1. Activate the plugin through the "Plugins" menu in WordPress

= After Installation =
1. Explore the plugin settings and choose your card style
1. [Connect your site to Create Studio](https://create.studio/admin) to unlock features like nutrition calculation and link scraping – [Learn more](https://help.create.studio/en/articles/8916417)
1. If you previously used another recipe card plugin, enable the built-in Recipe Importers under Create > Settings > Advanced to begin importing

For more information, please see our [help center](https://help.create.studio).

== Frequently Asked Questions ==

= How much does it cost? =

Create is free for everyone. All core features of the plugin will remain free and supported — including plugin updates, automatic nutriton calculation, user reviews, and more.

Premium features like premium themes, Interactive Mode, and review management are available through a [Create Studio](https://create.studio/#interactive-mode) subscription.

= How do I import my existing recipes? =

Recipe Importers are built into Create. Enable them under Create > Settings > Advanced, then go to Create > Import Recipes to start importing.

= Which recipe card plugins does the importer support? =

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
Our cards are displayed using WordPress blocks or shortcodes.

This means that if the plugin is disabled, the cards will not display on the front end of your posts. This is typical behavior for most WordPress plugins.

If the plugin is deactivated, no data will be deleted and reactivating the plugin will restore the original card display.

= Will I be able to add nutritional data? =

Yes! Nutritional data is an important part of Schema, which search engines love to have for optimal results.

Nutrition facts can be manually entered for a recipe. They will also transfer over through the importer if the recipe already contains it.

Create provides **free** automatic nutrition calculation with [API Ninjas Nutrition](https://api-ninjas.com/api/nutrition). [Learn more about this feature](https://help.create.studio/en/articles/8914561).

= Where do I report security bugs found in this plugin? =
Please report security bugs found in the source code of the Create plugin through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/mediavine-create). The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Screenshots ==

1. Create beautiful recipe cards, how-to guides, and lists — all with full schema support.
2. New! Filters and sorting options to find your cards quickly.
3. New! redesigned recipe editor with granular ingredient editing.
4. New! Drag-and-drop all over the place to rearrange list items, products, and ingredients.
5. Bring your recipes home with (newly) built-in importers for 10+ plugins.
6. New! Searchable settings with intuitive previews for layout and theming options.
7. New! Position and layout options for Recommended Products.
8. New! Bulk import links into list items in seconds.
9. Redesigned reviews section with bulk actions makes spam management a beeze.
10. New! Dashboard, Achievements, and app-wide Keyboard Shortcuts (try confetti!).
11. A published Recipe card in the New Modern Elegant style.
12. A published Recipe card in the New Editorial style.
13. A published List card in the Hero Image style.

== Changelog ==

= 2.4.2 =

* FIX: The Bulk Link Import Wizard and list Bulk Edit modal now appear above the editor overlay when opened from the Gutenberg block editor, instead of being hidden behind it (Premium)


= 2.4.1 =

* ENHANCEMENT: Added a "Sync subscription" button to the Create Studio settings for free-tier users, giving you a self-serve way to refresh your subscription state if it becomes stale
* FIX: Resolved an error when saving Amazon product relations on sites configured with the new Amazon Creators API
* FIX: The Button Text dropdown inside list items is no longer clipped by the editor pane and now display the full list of options


= 2.4.0 =

* FEATURE: Amazon Creators API integration — replaces the legacy PA-API ahead of Amazon's April 30, 2026 deprecation, with clearer, actionable error messages when credentials or product requests fail
* FIX: Recipe and how-to editors now also have fixed-height, independently-scrolling panes, matching the list editor
* FIX: Sticky section headers in the recipe, how-to, and list editors no longer hide behind the control bar

= 2.3.0 =

* FEATURE: Ad Provider setting — choose whether Mediavine ad slot markup appears in your cards, or disable it for non-Mediavine ad setups
* FEATURE: Redesigned list editor with permanent search panel, inline/top/bottom placement controls, and direct URL scraping
* FEATURE: Sticky formatting toolbar in recipe and how-to editors — no more scrolling up to access bold, links, and headings
* ENHANCEMENT: Copy-to-clipboard button on error details so you can easily share issues with support
* FIX: Resolve editor crashes related to Slate selection sync, importer page rendering, and card preview errors
* FIX: Eliminate layout reflow triggered by card size detection, improving PageSpeed scores


= 2.2.0 =

* FEATURE: Try Pro free for 14 days — unlock premium features like servings adjustment, unit conversion, checklists, and interactive mode
* FEATURE: Earn up to 7 bonus trial days by completing onboarding steps
* FIX: Prevent caching plugins from serving stale REST API responses when editing Create cards
* FIX: Default list button text now uses first option from Button Action Defaults setting instead of hardcoded value
* FIX: Category and Cuisine fields in the editor now display names instead of IDs
* FIX: Amazon link scraping now shows specific error messages with actionable links instead of generic errors

= 2.1.2 =

* FIX: Photo credits are now correctly attributed with bulk list item imports (Premium)

= 2.1.1 =

* ENHANCEMENT: Add aggressive CSS settings for widget and nutrition styles with theme override protection
* FIX: Fix reviews filtering error when relationships are undefined

= 2.1.0 =

* FEATURE: Add video shortcode support and per-card video position controls
* FIX: Fix Create editor crashing in TinyMCE/Classic Editor
* FIX: Duplicating a card from a collection view now succeeds

= 2.0.14 =

* FIX: Card editor keyboard shortcut (Cmd+S) now properly publishes changes
* FIX: Multiple theme display fixes for Centered Dark, Square, and Modern layouts
* ENHANCEMENT: Now choose sections for the Checklists feature (ingredients, instructions) (Premium)

= 2.0.13 =

* FIX: Images no longer appear extended vertically in some situations

= 2.0.0 =

* FEATURE: New Dashboard page with stats, tips & announcements, setup checklist, and achievements
* FEATURE: Granular Ingredient Editing (quantity, unit, item, note, and links)
* FEATURE: Redesigned Settings with search, logical grouping, collapsible sections, and new theme selector
* FEATURE: Welcome page showcasing all new 2.0 features with interactive demos
* FEATURE: Ad slot settings for list card ad placements
* FEATURE: Create Studio connection for site registration, Premium features, and multi-user verification
* FEATURE: [Interactive Mode](https://create.studio/#interactive-mode) (Premium)
* FEATURE: Review Responses (Premium)
* FEATURE: Adjustable Servings (Premium – Enable in settings)
* FEATURE: Unit Conversion (Premium – Enable in settings)
* FEATURE: New Featured Review block (Premium)
* FEATURE: New **Editorial** card theme option (Premium)
* FEATURE: New **Modern Elegant** card theme option (Premium)
* FEATURE: Interactive Checklists — readers can check off ingredients and steps as they cook (Premium)
* FEATURE: Products in Lists — add products as list items for affiliate placements (Premium)
* FEATURE: Bulk List Item Import — paste URLs to import them all as list items (Premium)
* SETTING: Custom ad slots for list cards
* SETTING: Preview themes live before choosing one using the new Theme Selector
* SETTING: Preview Interactive Mode in the settings using one of your own cards
* SETTING: Recommended Products title, format, and position (Premium)
* SETTING: Custom CSS for Create cards (Premium)
* ENHANCEMENT: Improve text contrast in all card themes to meet WCAG standards
* ENHANCEMENT: Rebrand Pro features to Premium with three-tier subscription model
* ENHANCEMENT: Bring the Importers into Create — no need to download a separate plugin; completely redesigned and integrated; enable in Advanced Settings
* ENHANCEMENT: Major rewrite of the editor to improve performance, usability, and stability
* ENHANCEMENT: Rename top-level admin menu from "Create Cards" to "Create"
* ENHANCEMENT: Add sticky editor toolbar with scroll-based compaction for always-visible save button
* ENHANCEMENT: Add collapsible sections with split-pane layout and auto-collapse on narrow screens
* ENHANCEMENT: Improve drag-and-drop with better drop indicator and arrow button fallback for touch devices
* ENHANCEMENT: Add mobile-responsive layouts for editor toolbar, Products collection, and form sections
* ENHANCEMENT: Revamp card creation flow with smart type detection and allowed type validation
* ENHANCEMENT: Enhance card collection views with advanced filtering options including missing fields filter
* ENHANCEMENT: Improve Recommended Products management with bulk selection, shift+click range select, keyboard shortcuts, and Amazon/Other filtering
* ENHANCEMENT: Redesign Reviews moderation UI with expandable rows, inline editing, advanced filters (rating, content type, sort order), and URL-persisted filter state
* ENHANCEMENT: Revamp In-Editor Post Links for quick navigation from card editor to embedded posts
* ENHANCEMENT: Improve List editor with unified item types, better search, and smoother drag-and-drop
* ENHANCEMENT: Add keyboard shortcuts throughout the app (Shift+? to view all)
* ENHANCEMENT: Optimize JavaScript bundling for faster card and page loading
* ENHANCEMENT: Redesign reader-facing review modal for a smoother experience
* ENHANCEMENT: Robust error reporting system with Create Studio integration
* ENHANCEMENT: Add bulk editing for list items (Premium)
* FIX: Apply Photo Ratio setting to list layouts
* FIX: Resolve WP 6.7 _load_textdomain_just_in_time notice

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
