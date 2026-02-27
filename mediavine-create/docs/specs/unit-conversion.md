# Unit Conversion Spec

> Generated: 2026-01-27
> Status: Draft

## Overview

Add metric/imperial unit conversion as a Pro feature for recipe cards. Readers can toggle between US Customary and Metric measurement systems on ingredient lists. Conversions are precomputed lazily via the Create Studio API on first frontend request and cached in creation metadata. The feature supports volume and weight conversions, leaves non-convertible ingredients unchanged, and auto-detects the reader's locale for the default system.

## Goals

- Allow readers to toggle ingredient measurements between US Customary (Imperial) and Metric
- Provide polished, accessible toggle UI at the top of the ingredient list
- Precompute conversions via Create Studio API and cache in creation metadata for fast subsequent loads
- Auto-detect reader's preferred measurement system from browser locale, with global fallback
- Persist reader's preference in localStorage across visits
- Support volume conversions (cups/tbsp/tsp/fl oz ↔ mL/L) and weight conversions (oz/lb ↔ g/kg)
- Gate behind Pro tier with a separate admin enable/disable setting

## Non-Goals (Out of Scope)

- Temperature conversion in instructions (may add later)
- Length conversion (pan sizes)
- Per-recipe override of conversion (global setting only)
- Converting non-standard units (pinch, clove, dash, etc.)
- Cross-device sync of reader preference
- Real-time API calls on toggle (precomputed only)

## Background

### Existing Architecture

**Ingredient Data Model** (`wp_mv_supplies` table):
- `amount` (longtext): Quantity, e.g., "2"
- `unit` (longtext): Measurement unit, e.g., "cups"
- `max_amount` (longtext): For ranges, e.g., "3" in "2-3 cups"
- `item` (longtext): Ingredient name
- `group` (longtext): Group heading

**Existing Pro Feature Pattern** (Checklists reference):
- Setting defined in `/lib/settings-group/class-recipes.php`
- Setting localized to frontend via creations views
- Preact component in `/client/src/` with lazy loading
- GateKeeper constant in `/admin/ui/src/hooks/useGateKeeper.ts`
- PHP pro check via `Plugin::is_pro()`

**Create Studio API**:
- Base URL: `https://create.studio/api/v2`
- Used for nutrition calculation, link scraping
- Plugin authenticates via API token stored in settings

**Client Rendering**:
- Preact app in `/client/src/`
- SCSS styling with component-based structure
- Ingredients rendered as `<li>` elements inside `.mv-create-ingredient-group` containers
- Each ingredient has `data-ingredient-index` attribute

### Settings Pattern

Existing recipe settings in `/lib/settings-group/class-recipes.php`:
- `enable_servings_adjustment` (checkbox, default false, Pro-gated)
- `servings_adjustment_label` (text, dependent on enable setting)

## Technical Approach

### Conversion Flow

1. **Recipe Published/Saved** → No conversion action at save time
2. **First Frontend Request** → PHP checks creation metadata for cached conversions
3. **Cache Miss** → PHP calls Create Studio API with batch ingredient data, stores result in metadata
4. **Cache Hit** → PHP includes precomputed conversions in localized card data
5. **Client Toggle** → Preact swaps between original and converted values (no network request)
6. **Recipe Updated** → Cache invalidated (metadata key deleted) on save

### API Contract (Create Studio)

```
POST /api/v2/conversions/batch
Authorization: Bearer {api_token}

Request:
{
  "creation_id": 42,
  "source_system": "us_customary",  // detected from original units
  "ingredients": [
    { "id": 123, "amount": "2", "unit": "cups", "max_amount": null },
    { "id": 124, "amount": "1", "unit": "lb", "max_amount": "1.5" },
    { "id": 125, "amount": "1", "unit": "pinch", "max_amount": null }
  ]
}

Response:
{
  "conversions": [
    { "id": 123, "amount": "473", "unit": "mL", "max_amount": null, "convertible": true },
    { "id": 124, "amount": "454", "unit": "g", "max_amount": "680", "convertible": true },
    { "id": 125, "amount": "1", "unit": "pinch", "max_amount": null, "convertible": false }
  ],
  "target_system": "metric"
}
```

### Storage (Creation Metadata)

Cached conversions stored in `wp_mv_creations.metadata` JSON:

```json
{
  "unit_conversions": {
    "version": 1,
    "source_system": "us_customary",
    "generated_at": "2026-01-27T12:00:00Z",
    "ingredients": {
      "123": { "amount": "473", "unit": "mL", "max_amount": null },
      "124": { "amount": "454", "unit": "g", "max_amount": "680" }
    }
  }
}
```

Non-convertible ingredients are omitted from the cache (client falls back to original values).

### Cache Invalidation

- On recipe save: delete `unit_conversions` key from metadata
- On supplies update (PUT `/creations/{id}/supplies`): delete `unit_conversions` key
- Manual: admin can re-trigger via a "Refresh Conversions" action (stretch goal)

### Locale Detection

Client-side detection order:
1. localStorage preference (if reader previously toggled)
2. `navigator.language` / `navigator.languages` → map to measurement system
3. Global setting fallback (`mv_create_unit_conversion_default_system`)

Locale mapping: US, Liberia, Myanmar → US Customary; all others → Metric.

### Client Data Flow

PHP localizes conversion data alongside existing card data:

```javascript
window.defined_creation_42 = {
  // ... existing fields
  unit_conversions: {
    enabled: true,
    default_system: "auto",  // "us_customary" | "metric" | "auto"
    source_system: "us_customary",
    label: "Unit Conversion",
    conversions: {
      "123": { amount: "473", unit: "mL", max_amount: null },
      "124": { amount: "454", unit: "g", max_amount: "680" }
    }
  }
};
```

## Task Groups

### Group 1: Backend Settings & Pro Gating (Independent)

**Tasks:**
1. Add unit conversion settings to recipe settings group
   - Files: `lib/settings-group/class-recipes.php`
   - Add `enable_unit_conversion` (checkbox, default false, order ~85)
   - Add `unit_conversion_default_system` (select: auto/us_customary/metric, dependent, order ~86)
   - Add `unit_conversion_label` (text, default "Unit Conversion", dependent, order ~87)

2. Add GateKeeper constant for unit conversion
   - Files: `admin/ui/src/hooks/useGateKeeper.ts`
   - Add `UNIT_CONVERSION: 'unit_conversion'` to `GATED_FEATURES`
   - Add gating description in `GatedFeature.tsx`

3. Add Pro feature summary entry
   - Files: `admin/ui/src/components/ProFeaturesSummary.tsx`
   - Add unit conversion to the pro features list

**Verification:**
- [ ] Settings appear in admin Recipe settings panel (Pro only)
- [ ] Default system dropdown only shows when enable_unit_conversion is checked
- [ ] Feature is gated behind Pro tier

---

### Group 2: Create Studio API Integration (Independent)

**Tasks:**
1. Create PHP conversion service class
   - Files: `lib/unit-conversion/class-unit-conversion.php` (new)
   - Handles calling Create Studio API batch endpoint
   - Parses response and formats for metadata storage
   - Includes unit recognition logic (which units are convertible)

2. Add metadata cache management
   - Files: `lib/unit-conversion/class-unit-conversion.php`
   - Read/write `unit_conversions` key in creation metadata
   - Cache invalidation on recipe save (hook into `save_post` or supplies update)

3. Register conversion service in plugin bootstrap
   - Files: `lib/autoloader-pro.php` or appropriate init file
   - Instantiate and hook the conversion service

4. Add REST endpoint for manual conversion trigger (admin)
   - Files: `lib/unit-conversion/class-unit-conversion.php`
   - `POST /mv-create/v1/creations/{id}/conversions` — force-refresh conversions
   - Permissions: `is_user_authorized()`

**Verification:**
- [ ] Conversion service calls Create Studio API correctly
- [ ] Metadata is populated on first request and persists
- [ ] Cache clears when recipe supplies are updated
- [ ] Manual refresh endpoint works from admin

---

### Group 3: Frontend Data Localization (Depends on: Group 1, Group 2)

**Tasks:**
1. Localize conversion data to frontend
   - Files: `lib/creations/class-creations-views.php` (or equivalent view layer)
   - When rendering a recipe card, check if conversions are cached
   - If not cached and feature enabled, trigger lazy computation
   - Include `unit_conversions` object in localized card data

2. Add conversion data to REST API response
   - Files: `lib/creations/class-creations-api.php` (or equivalent)
   - Include conversion data in the creation GET response for admin preview

**Verification:**
- [ ] `window.defined_creation_{id}` includes `unit_conversions` when feature enabled
- [ ] Conversion data absent when feature disabled or free tier
- [ ] Lazy computation triggers correctly on first view

---

### Group 4: Client Preact Component (Depends on: Group 3)

**Tasks:**
1. Create UnitConversion Preact component
   - Files: `client/src/UnitConversion/` (new directory)
   - `index.js` — entry point, lazy-loaded
   - `UnitConversionToggle.js` — the toggle UI component
   - `useUnitConversion.js` — hook for state management (localStorage + locale detection)
   - `utils.js` — locale detection, unit formatting helpers

2. Implement toggle UI with polished design
   - Skill: `/frontend-design`
   - Metric/Imperial toggle at top of ingredients list
   - Follows existing Create card design language (SCSS)
   - Accessible: keyboard navigable, aria labels, focus states
   - Animated transition when switching systems
   - Non-convertible ingredients remain unchanged (no visual disruption)

3. Add SCSS styles
   - Files: `client/src/style/components/_unit-conversion.scss`
   - Import in main stylesheet
   - Responsive design (mobile-first)
   - Respect card theme colors/variables

4. Integrate with ingredient rendering
   - Mount toggle component at top of `.mv-create-ingredient-group` section
   - When toggled, swap `amount`, `unit`, and `max_amount` values in ingredient DOM
   - Preserve checklist state (if both features enabled simultaneously)

5. Implement localStorage persistence
   - Key: `mv_create_unit_preference`
   - Value: `"us_customary"` or `"metric"`
   - No expiry (persist indefinitely)

**Verification:**
- [ ] Toggle renders at top of ingredients list
- [ ] Clicking toggle swaps all convertible ingredient amounts/units
- [ ] Non-convertible ingredients remain unchanged
- [ ] Preference persists across page reloads
- [ ] Auto-detection works for non-US locales
- [ ] Works alongside servings adjustment and checklists
- [ ] Accessible via keyboard
- [ ] Looks polished on mobile and desktop

---

### Group 5: Cache Invalidation & Edge Cases (Depends on: Group 2)

**Tasks:**
1. Hook cache invalidation into supplies update flow
   - Files: `lib/supplies/class-supplies-api.php`
   - Clear `unit_conversions` metadata when ingredients are saved/updated
   - Also clear on bulk import operations

2. Handle edge cases
   - Files: `lib/unit-conversion/class-unit-conversion.php`
   - Fractional amounts ("1/2", "1 1/2")
   - Range amounts (amount + max_amount)
   - Unicode fractions ("½", "¼")
   - Empty/null amounts or units
   - API failure graceful degradation (feature hidden if no conversions available)

**Verification:**
- [ ] Updating ingredients clears cached conversions
- [ ] Fractional amounts convert correctly
- [ ] Range amounts show both converted values
- [ ] API failure doesn't break recipe card rendering

---

### Group 6: Tests (Depends on: Groups 1-5)

**Tasks:**
1. PHP integration tests for conversion service
   - Files: `tests/Integration/unit-conversion/` (new)
   - Test cache read/write in metadata
   - Test cache invalidation on supplies update
   - Test settings registration
   - Mock Create Studio API responses

2. Client unit tests for Preact component
   - Files: `client/src/UnitConversion/__tests__/` (new)
   - Test toggle state management
   - Test locale detection logic
   - Test localStorage persistence
   - Test ingredient value swapping

3. Admin UI tests for settings
   - Files: `admin/ui/src/__tests__/`
   - Test GateKeeper integration
   - Test settings visibility

**Verification:**
- [ ] All PHP tests pass on php74
- [ ] Client tests pass
- [ ] Admin UI tests pass

---

## Execution Instructions

To execute this spec, use the `/parallel` skill:

```
/parallel
```

**Agent Guidelines:**
- Each task group can be assigned to a separate agent
- Groups marked "Independent" can run in parallel (Groups 1 and 2)
- Groups with dependencies wait for their prerequisites
- Use `/frontend-design` skill for Group 4 Task 2 (toggle UI design)
- Commit after completing each task group as a checkpoint
- Use descriptive commit messages referencing the spec

**Commit Strategy:**
- Small, atomic commits after each task group
- Format: `feat(unit-conversion): [group description]`
- Examples:
  - `feat(unit-conversion): add settings and pro gating`
  - `feat(unit-conversion): add Create Studio API integration`
  - `feat(unit-conversion): add client toggle component`

## Open Questions

1. **Create Studio API**: Does the `/api/v2/conversions/batch` endpoint already exist, or does it need to be built on the Studio side? If it needs to be built, this spec assumes it will be available before Group 2 implementation begins.
2. **Rounding rules**: How should converted values be rounded? (e.g., 2 cups = 473.176 mL — show as "475 mL"? "470 mL"? "473 mL"?)
3. **Friendly units**: Should conversions use "friendly" amounts where possible? (e.g., 1 cup → "250 mL" instead of "236.6 mL", or 1 lb → "450 g" instead of "453.6 g")
4. **Servings adjustment interaction**: When both servings adjustment and unit conversion are active, should scaled amounts also be converted? (e.g., 2x servings + metric mode)

## References

- Existing Pro feature pattern: `docs/specs/pro-checklists.md`
- Settings group: `lib/settings-group/class-recipes.php`
- Supplies schema: `lib/supplies/class-supplies.php`
- GateKeeper: `admin/ui/src/hooks/useGateKeeper.ts`
- Client checklists: `client/src/Checklists/`
- Create Studio base URL: `class-plugin.php` (`$services_api_url`)
