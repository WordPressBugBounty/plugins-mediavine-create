# Products Text List Display Mode Spec

> Generated: 2025-01-16
> Status: Draft
> Pro Feature: Yes

## Overview

Add the ability to render recommended products as a simple text/link list instead of the current image gallery format. This provides publishers with a lightweight "equipment" style display for products without requiring product images.

## Goals

- Allow products to display as a simple linked text list (like equipment/supplies sections)
- Provide a global default setting with per-card override capability
- Enable configurable section titles (e.g., "Recommended Products", "Equipment", "Tools")
- Maintain backward compatibility with existing image gallery display
- Pro-gate the text list display mode

## Non-Goals (Out of Scope)

- Changing the underlying product data model or API
- Modifying how products are added/managed in the editor
- Creating a hybrid display (some products as images, some as text)
- Changing JSON-LD schema output based on display mode
- Adding grouping capabilities (like supplies have)

## Background

### Current Product Display

Products are rendered as an image gallery in `lib/views/v1/shortcode-mv-create-products.php`:
- Container: `<div class="mv-create-products">`
- Each product shows: 240x240px thumbnail + title as clickable link
- Affiliate disclaimer displayed above the list (configurable)
- Links open in new tab with `rel="nofollow noopener"`

### Equipment/Supplies Display (Target Format)

Supplies are rendered as simple text lists in `shortcode-mv-create-supplies-*.php`:
- Container: `<div class="mv-create-ingredients">`
- Simple `<ul>/<li>` structure
- Items are either plain text or linked text
- No images

### Data Flow

```
ProductMap {
  title: string
  link: string (may be empty)
  thumbnail_id: string
  thumbnail_uri: string
}
```

The existing product data structure already has all fields needed—display mode just changes how it renders.

## Technical Approach

### Display Mode Options

```php
// Display modes
'gallery' => Image gallery with thumbnails (current behavior, default)
'list'    => Simple text/link list (new, Pro-only)
```

### Settings Structure

**Global Setting** (in Plugin Settings):
```php
'products_display_mode' => 'gallery' // default
'products_section_title' => 'Recommended Products' // default, customizable
'products_list_show_disclaimer' => true // show affiliate disclaimer in list mode
```

**Per-Card Setting** (in creations table or meta):
```php
'products_display_mode' => null | 'gallery' | 'list' // null = use global
'products_section_title' => null | string // null = use global
```

### Rendering Logic

```php
// In shortcode-mv-create-products.php
$display_mode = $args['creation']['products_display_mode']
    ?? Settings::get_setting('products_display_mode', 'gallery');

if ($display_mode === 'list') {
    // Render as simple linked list
    include 'shortcode-mv-create-products-list.php';
} else {
    // Render as image gallery (current code)
}
```

## Task Groups

Tasks are organized into parallel execution groups. Each group can run concurrently unless marked as dependent.

### Group 1: Database & Settings (Independent)

**Tasks:**

1. **Add global settings for products display**
   - Files: `lib/settings/class-settings.php`
   - Add settings: `products_display_mode`, `products_section_title`, `products_list_show_disclaimer`
   - Default values: `gallery`, `Recommended Products`, `true`
   - Register in settings schema

2. **Add per-card fields to creations table**
   - Files: `lib/creations/class-creations.php`
   - Add columns: `products_display_mode`, `products_section_title`
   - Nullable fields (null = inherit from global)
   - Add to fillable array and schema

3. **Update Creations API to include new fields**
   - Files: `lib/api/v1/class-creations-api.php`
   - Include new fields in creation response
   - Handle save/update for new fields

**Verification:**
- [ ] Settings appear in plugin settings API response
- [ ] New fields save/load correctly on creations
- [ ] Database migration runs without errors

---

### Group 2: Admin Settings UI (Depends on: Group 1)

**Tasks:**

1. **Add Products Display section to Settings page**
   - Files: `admin/ui/src/views/Settings/` (appropriate section)
   - Add display mode toggle (Gallery / Text List)
   - Add section title input field
   - Add "Show affiliate disclaimer in list mode" toggle
   - Pro-gate the Text List option
   - Skill: `/frontend-design`

2. **Add TypeScript types for new settings**
   - Files: `admin/ui/src/types/responses.ts`, `admin/ui/src/types/settings.ts`
   - Add `products_display_mode`, `products_section_title`, `products_list_show_disclaimer`

**Verification:**
- [ ] Settings section renders in admin
- [ ] Toggle switches between Gallery and Text List
- [ ] Pro badge/gate appears on Text List option
- [ ] Settings save and persist correctly

---

### Group 3: Card Editor UI (Depends on: Group 1)

**Tasks:**

1. **Add display mode toggle in Products section**
   - Files: `admin/ui/src/views/Editor/components/Products.tsx` (or related)
   - Toggle inline with products list: "Display as: [Gallery] [List]"
   - Show "Using global default" indicator when not overridden
   - Clear override button to revert to global
   - Pro-gate the List option
   - Skill: `/frontend-design`

2. **Add section title override in Products section**
   - Files: `admin/ui/src/views/Editor/components/Products.tsx`
   - Text input for custom section title
   - Placeholder shows global default
   - Clear button to revert to global

3. **Update card save to include new fields**
   - Files: `admin/ui/src/helpers/network.ts` or creation save logic
   - Include `products_display_mode` and `products_section_title` in save payload

**Verification:**
- [ ] Toggle appears inline with products list
- [ ] Can switch between Gallery and List
- [ ] Title input accepts custom text
- [ ] Changes save with card
- [ ] Pro gate prevents non-Pro users from selecting List

---

### Group 4: Frontend Rendering - List Mode (Depends on: Group 1)

**Tasks:**

1. **Create text list template for products**
   - Files: `lib/views/v1/shortcode-mv-create-products-list.php` (new)
   - Simple `<ul>/<li>` structure matching supplies styling
   - Products with links: `<a>` wrapped title
   - Products without links: plain text title
   - Use class `mv-create-products-list` for styling
   - Include affiliate disclaimer if enabled

2. **Update main products shortcode to support display modes**
   - Files: `lib/views/v1/shortcode-mv-create-products.php`
   - Detect display mode from creation or global settings
   - Include appropriate template based on mode
   - Pass through section title to template

3. **Add CSS for text list mode**
   - Files: `client/src/styles/` or appropriate CSS location
   - Style `mv-create-products-list` to match supplies/equipment look
   - Ensure consistent spacing and typography

**Verification:**
- [ ] Products render as text list when mode is 'list'
- [ ] Products render as gallery when mode is 'gallery'
- [ ] Links work correctly on linked products
- [ ] Unlinked products show as plain text
- [ ] Custom section title displays correctly
- [ ] Affiliate disclaimer respects setting

---

### Group 5: Pro Gating (Depends on: Groups 2, 3, 4)

**Tasks:**

1. **Gate text list mode in frontend rendering**
   - Files: `lib/views/v1/shortcode-mv-create-products.php`
   - Check `Plugin::is_pro()` before allowing list mode
   - Fall back to gallery if not Pro

2. **Gate text list option in admin settings**
   - Files: Admin settings component
   - Disable/hide List option for non-Pro
   - Show upgrade prompt or Pro badge

3. **Gate text list option in card editor**
   - Files: Card editor Products component
   - Same treatment as settings page
   - Ensure saved 'list' mode falls back gracefully if Pro lapses

**Verification:**
- [ ] Non-Pro users see only Gallery option
- [ ] Non-Pro users cannot save List mode
- [ ] Cards with List mode fall back to Gallery if Pro lapses
- [ ] Pro users see and can use List option

---

### Group 6: Testing & Polish (Depends on: Groups 4, 5)

**Tasks:**

1. **Add PHPUnit tests for display mode rendering**
   - Files: `tests/Integration/` or appropriate test location
   - Test gallery mode rendering
   - Test list mode rendering
   - Test fallback when mode not set
   - Test Pro gating

2. **Add Jest tests for admin components**
   - Files: `admin/ui/src/**/__tests__/`
   - Test settings toggle behavior
   - Test card editor toggle behavior
   - Test Pro gating in UI

3. **Manual testing checklist**
   - Test on recipe card type
   - Test on how-to card type
   - Test with products that have links
   - Test with products that have no links
   - Test with products that have no images (gallery mode)
   - Test affiliate disclaimer in both modes
   - Test custom titles in both modes

**Verification:**
- [ ] All PHPUnit tests pass
- [ ] All Jest tests pass
- [ ] Manual testing confirms expected behavior
- [ ] No regressions in existing gallery mode

---

## Execution Instructions

To execute this spec, use the `/parallel` skill:

```
/parallel
```

**Agent Guidelines:**
- Each task group can be assigned to a separate agent
- Group 1 (Database & Settings) should complete first
- Groups 2, 3, 4 can run in parallel after Group 1
- Group 5 (Pro Gating) requires Groups 2, 3, 4
- Group 6 (Testing) runs last
- Use `/frontend-design` skill for UI components (marked above)
- Commit after completing each task as a checkpoint

**Commit Strategy:**
```bash
# Example commit messages
git commit -m "feat(products): add display mode settings to database schema"
git commit -m "feat(products): add global products display settings"
git commit -m "feat(products): add display mode toggle to card editor"
git commit -m "feat(products): create text list rendering template"
git commit -m "feat(products): add Pro gating for text list mode"
git commit -m "test(products): add tests for display mode rendering"
```

**Testing Notes:**
- Run `run-wordpress-tests -e php74` after database changes
- Test both Pro and non-Pro scenarios
- Verify existing gallery mode is unaffected
- Check responsive behavior for list mode

## Open Questions

1. **Disclaimer position**: In list mode, should the affiliate disclaimer appear above or below the list?
2. **Empty state**: If a card has products but all lack links, should list mode show anything? (Current answer: yes, as plain text)
3. **Print styles**: Should list mode have specific print CSS?

## Visual Reference

### Gallery Mode (Current)

```
┌─────────────────────────────────────────────────────┐
│  Recommended Products                               │
│  [affiliate disclaimer if enabled]                  │
│                                                     │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐            │
│  │  IMG    │  │  IMG    │  │  IMG    │            │
│  │ 240x240 │  │ 240x240 │  │ 240x240 │            │
│  └─────────┘  └─────────┘  └─────────┘            │
│  Product 1     Product 2     Product 3             │
│  (linked)      (linked)      (linked)              │
└─────────────────────────────────────────────────────┘
```

### List Mode (New)

```
┌─────────────────────────────────────────────────────┐
│  Equipment  (or custom title)                       │
│  [affiliate disclaimer if enabled]                  │
│                                                     │
│  • Product Name 1 (linked)                         │
│  • Product Name 2 (linked)                         │
│  • Product Name 3 (plain text, no link)            │
│  • Product Name 4 (linked)                         │
└─────────────────────────────────────────────────────┘
```

### Editor Toggle (Inline with Products)

```
┌─────────────────────────────────────────────────────┐
│  Recommended Products                    [+ Add]    │
│  ─────────────────────────────────────────────────  │
│  Display as: [Gallery] [List PRO]                   │
│  Section title: [Equipment____________] [× clear]   │
│  ─────────────────────────────────────────────────  │
│  [Product 1 with image and details...]             │
│  [Product 2 with image and details...]             │
└─────────────────────────────────────────────────────┘
```

## References

- Product rendering: `lib/views/v1/shortcode-mv-create-products.php`
- Supplies rendering (reference): `lib/views/v1/shortcode-mv-create-supplies-recipe.php`
- Products model: `lib/products/class-products.php`
- Product map: `lib/product-maps/class-products-map.php`
- Settings: `lib/settings/class-settings.php`
- Admin UI types: `admin/ui/src/types/responses.ts`
- Pro check: `Plugin::is_pro()` or `mv_create_is_pro` filter
