# Plan: General-Purpose Ad Slots for List Cards

## Goal

Allow any publisher — not just Mediavine ad network users — to insert ads between list items. Mediavine publishers must continue working exactly as they do today with zero changes on their end.

## Current State

The ad system is tightly coupled to Mediavine's ad platform:

1. **`Plugin_Checker::has_mv_ads()`** gates everything — if the publisher doesn't run Mediavine Control Panel or Journey/Grow, they get no ad settings and no ad slots.
2. **`Control_Panel::settings()`** returns an empty array when `has_mv_ads()` is false, hiding the "List Items Between Ads" setting entirely.
3. **`mv_create_list_ads()` / `mv_create_list_ads_grid()`** both hard-check `has_mv_ads()` and hard-code the Mediavine slot markup (`<div class="mv_slot_target" data-slot="content">`).
4. There are **no filters** on the ad HTML output, the insertion decision, or the frequency setting availability.

### Key Files

| File | Role |
|------|------|
| `lib/helpers/class-plugin-checker.php` | `has_mv_ads()` detection |
| `lib/settings-group/class-control-panel.php` | Registers `list_items_between_ads` setting (MV-only) |
| `lib/creations/class-creations-views-hooks.php` | `mv_create_list_ads()`, `mv_create_list_ads_grid()`, `list_style_square_hooks()` |
| `lib/creations/class-creations-views.php` | Loads setting value into `$published_creation` at line 725 |
| `lib/views/v1/shortcode-mv-create-list-*.php` | Templates that fire `mv_create_list_after_single` / `mv_create_list_after_row` |

## Design

### Approach: Settings + Filters

We'll make the existing ad system configurable for all publishers while preserving Mediavine's exact behavior via backward-compatible defaults.

### Changes

#### 1. New Settings Group: `List_Ads` (new file)

**File:** `lib/settings-group/class-list-ads.php`

Create a new settings group that provides the "List Items Between Ads" setting for **all publishers**, not just Mediavine ones. This replaces the setting currently in `Control_Panel`.

Settings to register:
- **`mv_create_list_ads_enabled`** (toggle) — Master on/off for list ad slots. Default: `true` if `has_mv_ads()`, `false` otherwise.
- **`mv_create_list_items_between_ads`** — Move here from Control_Panel. Same options (0, 2, 3, 4, 5). Shown when ads are enabled.
- **`mv_create_list_ad_html`** (textarea/code) — Custom ad HTML for non-Mediavine publishers. Default: empty. When empty and Mediavine is detected, falls back to the existing `mv_slot_target` markup.

The setting group should be placed in a new "Ads" or "Monetization" settings tab/group so it's discoverable.

#### 2. Modify `Control_Panel::settings()`

Remove `list_items_between_ads` from here (it moves to `List_Ads`). If `Control_Panel` has no other settings, it can return `[]` unconditionally or be deprecated.

#### 3. Add Filters to Ad Insertion Methods

Modify `mv_create_list_ads()` and `mv_create_list_ads_grid()` in `class-creations-views-hooks.php`:

**a. Replace the `has_mv_ads()` gate with a filterable check:**

```php
// Before (current):
Plugin_Checker::has_mv_ads()

// After:
apply_filters( 'mv_create_list_ads_enabled', Plugin_Checker::has_mv_ads() )
```

This lets non-MV publishers return `true` to enable ad slots, while MV publishers see no change (filter defaults to their existing `true`).

**b. Add a filter on the ad HTML output:**

```php
$default_html = '<div class="mv-list-adwrap"><div class="mv_slot_target" data-slot="content"></div></div>';
$ad_html = apply_filters( 'mv_create_list_ad_html', $default_html, $args, $i, $count );
echo $ad_html;
```

Non-MV publishers can filter this to insert their own ad network code (AdSense, Raptive, Monumetric, etc.). MV publishers get the default markup unchanged.

**c. Add a filter on the insertion decision:**

```php
$should_insert = ( 0 === ( $i + 1 ) % $args['creation']['list_items_between_ads'] )
    && ( $i + 1 ) !== $count;

$should_insert = apply_filters( 'mv_create_should_insert_list_ad', $should_insert, $args, $i, $count );
```

This allows fine-grained control over where ads appear (e.g., skip certain positions, custom frequency logic).

#### 4. Wire Up the Custom HTML Setting

When `mv_create_list_ad_html` setting has a value and `has_mv_ads()` is false, use the stored custom HTML as the default instead of the Mediavine slot markup. This can be done with a simple internal hook:

```php
// In the List_Ads settings group or a dedicated class
add_filter( 'mv_create_list_ad_html', function( $html ) {
    $custom = \Mediavine\Settings::get_setting( 'mv_create_list_ad_html', '' );
    if ( ! empty( $custom ) && ! Plugin_Checker::has_mv_ads() ) {
        return '<div class="mv-list-adwrap">' . $custom . '</div>';
    }
    return $html;
});
```

#### 5. Update `list_items_between_ads` Loading

In `class-creations-views.php` line 725, the setting load stays the same — it already reads from the settings table by slug, so moving the registration to a new group doesn't affect retrieval.

#### 6. Register Settings Group

In `class-plugin.php`, add `List_Ads::settings()` to the `get_settings()` merge array.

### Backward Compatibility

| Scenario | Before | After |
|----------|--------|-------|
| MV publisher, default settings | Ads every 3 items with MV slot markup | Identical — `mv_create_list_ads_enabled` defaults true, HTML defaults to MV markup |
| MV publisher, custom frequency | Works via setting | Same setting, same behavior |
| Non-MV publisher | No ads, setting hidden | Setting visible, ads disabled by default. Publisher enables + provides custom HTML |
| Third-party dev using hooks | Had to know about `mv_create_list_after_single` | Can now use `mv_create_list_ad_html` filter or the admin setting |

### Admin UI Considerations

The "List Items Between Ads" setting needs to be visible to all publishers when ads are enabled. The settings UI already renders based on the settings array, so moving the setting to a new group and removing the `has_mv_ads()` gate on registration is sufficient.

For the custom ad HTML field:
- Show only when `has_mv_ads()` is false (MV publishers don't need it)
- Include helper text: "Paste your ad network code here. This will be inserted between list items."
- Consider a `code` field type if available, otherwise `textarea`

## Task Breakdown

### Task 1: Create `List_Ads` settings group
- New file `lib/settings-group/class-list-ads.php`
- Register `mv_create_list_ads_enabled`, move `mv_create_list_items_between_ads`, add `mv_create_list_ad_html`
- Register in `class-plugin.php` get_settings()
- Remove from `Control_Panel::settings()`

### Task 2: Add filters to ad insertion methods
- Modify `mv_create_list_ads()` — replace `has_mv_ads()` with filterable check, add HTML filter, add insertion decision filter
- Modify `mv_create_list_ads_grid()` — same changes
- Wire up custom HTML setting via filter

### Task 3: Update setting loading
- Ensure `list_items_between_ads` is loaded regardless of `has_mv_ads()` state
- Verify the `mv_create_list_ads_enabled` toggle controls visibility of frequency setting

### Task 4: Test
- Verify MV publishers see no behavioral change
- Verify non-MV publishers can enable ads, set frequency, provide custom HTML
- Verify filters work for programmatic customization
- Run existing PHPUnit tests to ensure no regressions

## Filters Reference (New)

| Filter | Parameters | Default | Purpose |
|--------|-----------|---------|---------|
| `mv_create_list_ads_enabled` | `(bool $enabled)` | `has_mv_ads()` | Master toggle for ad slot rendering |
| `mv_create_list_ad_html` | `(string $html, array $args, int $index, int $count)` | MV slot markup | Customize the ad slot HTML |
| `mv_create_should_insert_list_ad` | `(bool $should_insert, array $args, int $index, int $count)` | frequency math result | Override individual insertion decisions |
