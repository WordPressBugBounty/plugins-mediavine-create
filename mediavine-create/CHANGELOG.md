# CHANGELOG

## 1.3.22
FIX: The previous version's contentUrl fix didn't fix videos that were attached several months ago, now it does :)

## 1.3.21
FIX: Fix an issue where video data for cards was missing contentUrl

## 1.3.20
ENHANCEMENT: Add JSON-LD schema toggle to How-To cards

## 1.3.19
ENHANCEMENT: Add stepped instructions to JSON-LD
FIX: Link Parsing in Ingredients
FIX: Some creations weren't mapping to canonical posts
FIX: Restore Ads on Print pages

## 1.3.10
FIX: Prevent list descriptions from being tiny
FIX: Amazon images will display in dropdown
FIX: Prevent misordered ingredients from detailed editor
FIX: Prevent multiple List JSON-LDs from outputting
FIX: Prevent a bug where typing in time inputs will insert "minutes" sporadically
FIX: Improve performance of image rendering in instructions preview
FIX: Buttons in lists will sync with theme
FIX: Add missing "Cost" field to front-end HowTo renders

## 1.3.5
FEATURE: Improves UX and speed of List search
FEATURE: Add a setting to disable JSON-LD output for individual posts
FEATURE: Add `mv_create_card_before_render` and `mv_create_card_after_render` hooks
FEATURE: Adds support for Amazon links as List iteems
FIX: Prevents output of JSON-LD markup in RSS feeds
FIX: Removes duplicate ad hints when rendering a list after a recipe card in a single post
FIX: Fixes error in List render
FIX: Prevents display of special characters as HTML entities in list search
ENHANCEMENT: Protect client-side resources from caching plugins
ENHANCEMENT: Refactor JavaScript

## 1.3.3
ENHANCEMENT: Adds ability to no-follow external List items
ENHANCEMENT: Optimize the ad hint used for Mediavine publishers
ENHANCEMENT: Adds a setting to override the author for all cards with default Copyright Attribution
FIX: Prevents Social Warfare and Pinterest browser extension from targeting List images for which we already include a Pinterest button
FIX: Prevents issue where List items would sometimes display in incorrect order
FIX: Fix an issue where adding previously-added products without thumbnails would result in the thumbnail not being re-scraped
FIX: Fixes issue where including Recipe and List in the same post would sometimes result in duplicate descriptions
FIX: Fixes an issue where Cards used as List items would link to incorrect page
FIX: Center ads used in lists
FIX: Prevents an issue where backspacing immediately after clicking a card would create an error when re-inserting the card
FIX: Prevents issues where editor would sometimes load with empty content
FIX: Improves size of images used by Grid layouts
FIX: Prevents an error when global affiliate notice has not been set
CHANGE: Changes the “Save” notice on the Settings page to be more visible
CHANGE: List JSON-LD will only display in the canonical post for that link

## 1.3.1
FIX: Change ad target for Mediavine publishers
FIX: Fix missing Pinterest buttons
FIX: CSS improvements for circle List layouts
FIX: Grid layouts will display ads for Mediavine publishers in a separate row
FIX: Regenerate images for List items if they don't exist
CHANGE: Change "Duplicate" button to "Clone" button

## 1.3.0
FEATURE: Add lists
FEATURE: Mobile improvements
FEATURE: Card duplication
FEATURE: Copy/paste-able shortcodes
FEATURE: Content type limiting
FEATURE: Admin i18n
FIX: Prevent scrolling bug with pagination links
FIX: Fixes an issue where the “Select Existing” UI in Gutenberg would display the wrong content type when multiple types of cards are added to a single post
CHANGE: Cards without an author will use the default copyright attribution setting as the author
CHANGE: Printed cards will include a URL back to the original post
CHANGE: Icons in the Gutenberg block selector are now under their own heading and have a new and lovely splash of teal

## 1.2.8
FIX: Fix issue with scrolling breaking upon modal close
FIX: Fix issues with editing images in posts

## 1.2.7
FIX: Fix issue with taxonomy dropdowns sometimes not updating on click
FIX: Fix issue where scheduled posts wouldn't create card associations necessary for canonical posts or review syncing

## 1.2.6
ENHANCEMENT: Print button compatibility with security plugins that strip <form> tags from post content
FIX: Prevent pinning of print pages
FIX: Yield displays on Centered card themes when times have not been provided
FIX: Fix issue where bulk ingredients would display as blank field during importing

## 1.2.5
FIX: Fixes dropdowns like author and category in Gutenberg
FIX: Provides a button to register with a different email address
FIX: Prevents a nutrition notice from displaying when it shouldn't
FIX: Fixes a bug in Mediavine Control Panel preventing videos from being deleted from posts

## 1.2.4
Adds an upgrade notice for upcoming changes in the 1.3.0 release
FIX: Fixes error on single Product pages
FIX: Correctly disables card hero images from being targetted by Pinterest browser extension
FIX: Fixes issue that was causing infinite loops with themes or plugins using the 'save_post' hook
FIX: Provides backwards compatibility for custom card templates that used 'disable_nutrition' and 'disable_reviews' settings

## 1.2.3
FIX: Add support for block tags
FIX: Duplicate post association issues

## 1.2.2
ENHANCEMENT: Improve UX around calculate button
ENHANCEMENT: Add global nutrition disclaimer setting
FIX: Fix issue with PHP 5.4 conflict
COSMETIC: Better UI render on low-DPR screens

## 1.2.1
FIX: Fix local storage conflict with nutrition calculation auth

## 1.2.0
FEATURE: Free automatic nutrition calculation with simple plugin registration
FEATURE: Option to have traditional nutrition label display
FEATURE: Preview Google Rich Snippet with one click
FEATURE: Full Gutenberg support
FEATURE: Disable thumbnails in print cards
FEATURE: List view of Create Cards
FEATURE: Classic Editor button to access Create Card editor without scrolling

## 1.1.12
FIX: Fix a bug with Pinterest not picking up Pinterest image

## 1.1.11
FIX: Fix a bug with adding recommended products

## 1.1.10
FIX: Provide ability to translate plural time units (e.g. "minutes")
FIX: Fix issues with recommended product search
FIX: Fix front-end JavaScript errors in IE11
FIX: Fix "Add New" URL in sites running in subdirectories
FIX: Print card style will always be default style

## 1.1.9
FIX: Highest resolution image check only pulls from available sizes
FIX: ShortPixel will no longer re-optimize images after card publish

## 1.1.8
FEATURE: Track new ratings from Comment Rating Field pro
FIX: Prevent TinyMCE application from mounting on the wrong editor

## 1.1.7
FIX: Prevent app crash when adding multiple cards to a Gutenberg post
FIX: Prevent error when filtering JSON-LD by unique image
FIX: Prevent warning when rendering cards with no thumbnail images

## 1.1.6
FIX: Adhere to recommendations for JSON-LD image sizes

## 1.1.5
ENHANCEMENT: More reliable JSON-LD object for Google
FIX: Remove unnecessary Autoptimize clear on activate and deactivate
FIX: More reliable category and cuisine term names in JSON-LD
FIX: Fixes issue where MCP videos would use mv_create shortcode
FIX: Featured image displays properly in Gutenberg

## 1.1.4

FIX: Make Pinterest description available to browser extension
FIX: Fix display of thumbnail editor
FIX: Improve rendered card styles for small phones
FIX: Better display of images in card instructions
FIX: Fix issue with print view on some servers
FIX: Prevent Autoptimize from aggregating script localizations
FIX: Prevent mv_create post types from creating redirects

## 1.1.3

FIX: Improve responsive image size for recommended products
FIX: Allow for default localization of time labels
FIX: Improve Gutenberg integration

## 1.1.2

ENHANCEMENT: Add .pot with plugin so users can generate translations (note: at the moment, translations will only appear on the front-end of the site. Back-end support is coming in a future release!)
FIX: Prevent re-saving posts extraneously, fixing issues with <script> tags being stripped and pingbacks being triggered
FIX: Resolve issue where deleting content from Instructions or Notes fields would leave remaining content
FIX: Prevent admin CSS and JS from being cached
COSMETIC: Various improvements for admin CSS and copy

## 1.1.1

FEATURE: Remove tooltip from Instructions WYSIWYG
FEATURE: Adding async to front-end javascript tag
FEATURE: Track active filters when page is refreshed
FIX: Eliminate Duplicate DB Call
FIX: Prevent error with undefined properties field
COSMETIC: Adding namespaces to admin CSS
COSMETIC: Increase base font size to 16px on rendered cards

## 1.0.0

FIX: Repair Adele so units populate properly
FIX: Create plugin variable for MCP status

## 0.4.2

FEATURE: Pressing return creates a new ingredient in the advanced editor
FEATURE: Download link available in plugin settings
FEATURE: When adding link to instructions or notes, URL is the default field instead of Link Text
ENHANCEMENT: Improved Gutenberg support
FIX: Input text in draggable elements can be highlighted
FIX: Print template works with all card types
FIX: Avoid conflict with Sumo Me's JavaScript
FIX: Disallow GumGum ads on recipe imagesgit

## 0.4.1

FEATURE: Changes to products will sync across all associated Creations
FEATURE: Change default photo ratio setting to "no fixed ratio"
FEATURE: Add support for times without units
FIX: Fix issue where Global Product UI Images weren't saving
FIX: Remove unneeded public Creations URLs
FIX: Update certain SQL statements to be compliant with WordPress coding standards
FIX: Fix issue where searching while on the second page of card results would return nothing
FIX: Fix conflict with other plugins that would prevent card from being inserted into HTML editor
FIX: Fix issue where client side JavaScript might be cached by Cloudflare between updates
FIX: Prevent issue where updates might cause a card type to be duplicated
FIX: Improve scrolling behavior of editor when preview is taller than browser height
COSMETIC: Fix CSS conflict between card header/footers and certain themes
COSMETIC: Minor typographic changes for admin
COSMETIC: Improve mobile display of admin UI

## 0.4.0

FEATURE: Add How To card support
FEATURE: Ordered list default for instructions
FEATURE: Add helper functions to return lists of creations and reivews
FIX: Restores lost product images
FIX: Stops scraped images from duplicating
FIX: Video modal can now be closed on IE
FIX: Single quotes in titles no longer break card on editor
FIX: Fixes issues with TinyMCE not rendering [mv_recipe] cards
COSMETIC: Add Existing link more clear

## 0.3.8

FEATURE: Default recipe active time label to cook time
FIX: Print Recipe opens in new tab
FIX: Fix bug where ingredients and products wouldn't display in the correct order
FIX: Theme can be selected on settings page
FIX: Remove couple PHP notices
FIX: Exclamation points can be used in ingredient items now

## 0.3.7

FIX: Fix bug where posts were being duplicated when card rendered

## 0.3.6

FIX: Fix copy of autosave setting and issue with content not saving at all if autosave was disabled

## 0.3.5

FIX: Migrate missing active/cook times

## 0.3.4

FEATURE: Adds a "suitable for diet" field for JSON-LD schema
FEATURE: Adds ability to disable nutrition and review
FIX: Ratings UI will update global reviews values after submission
FIX: Fixes issue with Pinterest descriptions longer than 255 characters not updating
COSMETIC: Improves appearance of ingredients editor

## 0.3.3

FEATURE: We'll remember whether you were last using the bulk or advanced ingredient editor
FEATURE: Add back button support to editor
FEATURE: Javascript is no longer required for print button to work
FEATURE: Build unfound image sizes on publish
FIX: Templates will render higher-quality images, and fall back to lower-quality if not found
FIX: Fixes autosave actions not triggering for video, Pinterest, or instructions fields
FIX: Fix issue where clicking a card in TinyMCE would also open MCP video UI
FIX: Taxonomies in UI always display taxonomy name
FIX: Add create image sizes to WordPress
COSMETIC: Improves appearance of thumbnail editor
COSMETIC: Save buttons in editor stick to the top of the screen

## 0.3.2

FEATURE: Moved migration to a separate plugin
FIX: Fix issue where changes to a single product would affect every product, and where images wouldn't render
FIX: Prevent publish action not capturing unsaved changes
COSMETIC: Improves appearance of links when using dark cards
COSMETIC: Adjust placement of "yield" in front-end

## 0.3.1

FIX: Schema displays correctly in content
FIX: Fixes compatibility issue with new shortcode markup
FIX: Removes errant "Next" button on archive views
FIX: Auto-focus WYSIWYG link field
FIX: Adds helpful links to settings page
FIX: Reviews display in correct order in the admin
COSMETIC: Improves appearance of content while it loads
COSMETIC: Improves appearance of shortcode preview

## 0.3.0

- Major release! Overhaul UI

## 0.2.10

- FIX: Fix bug with newly-created recipes displaying validation warnings about nutrition
- FIX: Fix error where publishing a recipe without inputting times would cause a 500 error

## 0.2.9

- FIX: Remove bug where ingredients with partial link text would be converted from fractions to decimals
- FIX: Fix a bug where adding a recipe card would remove videos added with MCP
- FIX: Prevent ingredients from being ordered randomly in rendered card (note: content will need to be re-saved)
- FIX: Add a default of 0 for JSON-LD times to prevent warnings about missing fields
- FIX: Prevent empty <ul> tags in ingredients on the front end
- COSMETIC: Various improvements to rendered card
- COSMETIC: Remove unnecessary scrollbars on Windows
- COSMETIC: Improved instructions for video and recommended products

## 0.2.8

- ENHANCEMENT: Attachment_id retrieval now accepts thumbnail urls

## 0.2.7

- FEATURE: Add support for only parts of ingredient names to be designated as a link
- FEATURE: Overhaul print page to work with Thesis framework
- FEATURE: Add importer support for Yummly
- FIX: Site users will be available through Author dropdown again
- FIX: Prevent ingredients from reordering at random
- FIX: Videos should appear on card (requires MCP 1.9.0)
- FIX: Resolve a 404 for a script on the front-end of the site
- COSMETIC: Improvements to modal styles

## 0.2.6

- FEATURE: Add bug reporting to importer
- FEATURE: Add support for video integration with future releases of MCP
- FIX: Improve initial card render when other recipe plugins are installed
- FIX: Long product titles will not be truncated
- FIX: Carbohydrates will display in nutrition
- FIX: Prevent ingredients "jumping" around during import processing
- COSMETIC: Improve how card images render with long titles

## 0.2.5

- FIX: Activation bug when using certain caching plugins
- FEATURE: Add activation cache clearing for LightSpeed and WPRocket
- FEATURE: Add ability to start import over

## 0.2.4

- FIX: Enhancement search temporarily disabled

## 0.2.3

- FEATURE: If you're looking at an autosaved version of a recipe that's more recent than the published version, we'll let you know!
- FIX: Fixes a bug where video data was erroneously included in JSON+LD
- FIX: Improves Pinterest behavior, including defaulting to higher-res images, preventing product images from being pinned, and protecting Pinterest button from theme styles
- FIX: Fixes a bug where nutrition values of 0 wouldn't display on front-end

## 0.2.2

- FIX: Importer will alert recipes which couldn’t be replaced, and give instructions for replacing manually
- FIX: Various fixes for recommended product behavior
- FIX: Default
- COSMETIC: Nutrition typo fix, add various instructions

## 0.2.1

- FEATURE: Custom authors will be available for future posts
- FIX: Images uploaded to previous versions should be available
- FIX: Nutrition fields accept "0" as a value
- FIX: Recommended products can be added to recipes during import step

## 0.2.0

- Introduce ability to edit/moderate reviews
- When adding a new recipe, the post thumbnail will be used by default
- Inserting images into Instructions or Notes fields should behave better
- Prevent ads from running in Reviews UI
- Add a setting to enable button styles in themes that don't provide their own
- Time units will display as singular when the time value is 1
- Fix conflict with print button and Thesis Framework
- Improvements to importer instructions

## 0.1.11

- Adds several new features to the Reviews UI, including the ability to filter reviews by user and to jump to a recipe directly from reviews
- Change user review workflow so that only <4 star reviews require a message
- Adds support for adding recipes to pages
- Prevent page redirects during importing action
- Improvements to recipe editor tooltips
- Add small image size, 194x194

## 0.1.10

- Fixes a bug where ungrouped ingredients wouldn't render when combined with grouped ingredients

## 0.1.9

- Adds a setting for default copyright attribution
- Increases the number of categories initially displayed from 5 to 30
- Fixes a bug with updating a recipe's cook time labels

## 0.1.8

- Adds a new importer for older versions of Simple Recipe Pro
- Prepopulate categories when adding a new recipe to a post

## 0.1.7

- Adds a setting to disable enhanced search features that may conflict with other plugins
- Fixes a bug where saving recipe wasn't working on some website setups
- Fixes a bug where pressing the enter key would cause a page refresh
- Fixes rendering bugs for ampersands in categories
- Improvements to recommended products editor UI on slower network connections
- Cosmetic improvements: nutritional information is less condensed, ingredient printouts have bullet points

## 0.1.6

- Improves reliability of publish actions
- When adding a new recipe from a post, uses that post's title as a default
- UI improvements for recommended products
- Fixes a bug where newly-created terms display their ID rather than name in selection UI
- Fixes a bug where selecting a previously-scraped product link would not display thumbnail in editor
- Updates text displayed to users when leaving reviews for better GDPR compliance

## 0.1.5

- Introduces instructional tooltips to various recipe editor components
- Improvements to advanced mode of ingredients editor – actions won't be wiped out on slower network connections
- Improvements to WYSIWYG editor for instructions and notes – changes won't be wiped out on slower network connections
- Adds a fallback option for failed published requests – users will be prompted to try again
- Fixes intermittent bug where categories, cuisines, and author dropdowns were not functional during importer
- Adds error tracking
- Minor improvements to ad positioning within recipe cards
- Minor improvements to affiliate link parsing for recommended products
