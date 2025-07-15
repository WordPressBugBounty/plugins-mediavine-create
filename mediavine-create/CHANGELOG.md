# CHANGELOG

## 1.9.14
FIX: Greek characters properly render in instructions

## 1.9.12
FIX: Recipe card ratings display in structured data
FIX: Mediavine video selection works with new Mediavine videos endpoint, and video thumbnails display correctly

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
