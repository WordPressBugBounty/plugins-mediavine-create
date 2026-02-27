# Pro Checklists Spec

> Generated: 2025-01-16
> Status: Draft

## Overview

Add interactive checkboxes to ingredients and instruction steps on recipe cards, allowing users to track their cooking progress. Feature includes collapsible ingredient groups, localStorage persistence with 24-hour expiry, and visual feedback (strikethrough + dimmed) for checked items. Pro tier only.

## Goals

- Allow users to check off ingredients as they gather them
- Allow users to check off instruction steps as they complete them
- Persist checked state across page reloads (24-hour localStorage)
- Provide visual feedback showing progress (strikethrough + dimmed opacity)
- Make ingredient groups collapsible for better organization

## Non-Goals (Out of Scope)

- Cross-device sync (account-based persistence)
- Reset/clear all button
- Print view of checked states
- Checkbox state in the admin editor
- User toggle to enable/disable checkboxes on the card (always active when enabled)

## Background

### WPRM Comparison
WPRM offers this as a Premium feature ($49/year). It's described as "simple but highly requested UX feature" with "low development effort, high perceived value."

### Existing Patterns
The Hands-Free Mode feature provides an excellent implementation pattern:
- Setting defined in `/lib/settings-group/class-advanced.php`
- Setting localized via `/lib/creations/class-creations-views.php`
- Preact component in `/client/src/HandsFree/`
- Mount point div in template
- Lazy-loaded via dynamic imports

### Data Structures

**Ingredients** (from `shortcode-mv-create-supplies-recipe.php`):
```php
$args['creation']['ingredients'] = [
    'group_name' => [
        ['original_text' => string, 'link' => string, 'nofollow' => bool],
        // ... more ingredients
    ],
    // ... more groups
];
```

**Instructions**: Already parsed with step IDs in PHP. Each instruction step has a unique identifier that can be used for localStorage keys.

## Technical Approach

### Feature Gating
- Add new GateKeeper constant: `FEATURE_CHECKLISTS`
- Gate behind Pro tier using existing `GateKeeper::can_access()` pattern

### Settings
- New setting: `mv_create_enable_checklists` (checkbox, default false)
- Add to Recipes settings group
- Localize to frontend via `MV_CREATE_SETTINGS.__OPTIONS__.checklistsEnabled`

### localStorage Schema
```javascript
// Key format: mv_create_checklist_{creation_id}
// Value: JSON object with 24-hour expiry
{
  ingredients: {
    "group_0_item_0": true,
    "group_0_item_1": false,
    // ...
  },
  instructions: {
    "step_0": true,
    "step_1": false,
    // ...
  },
  collapsedGroups: ["group_1", "group_2"], // collapsed group indices
  timestamp: 1705420800000 // for 24-hour expiry check
}
```

### Visual Feedback
```css
.mv-checklist-item.checked {
  text-decoration: line-through;
  opacity: 0.6;
}
```

## Task Groups

Tasks are organized into parallel execution groups. Each group can run concurrently unless marked as dependent.

### Group 1: Backend - Settings & Gating (Independent)

**Tasks:**

1. **Add GateKeeper constant for checklists feature**
   - Files: `lib/settings/class-gatekeeper.php`
   - Add `FEATURE_CHECKLISTS = 'checklists'`
   - Gate at Pro tier level

2. **Create checklists setting**
   - Files: `lib/settings-group/class-recipes.php`
   - Add `mv_create_enable_checklists` checkbox setting
   - Label: "Enable Checklists"
   - Instructions: "Add interactive checkboxes to ingredients and instructions for tracking cooking progress"
   - Default: false

3. **Localize setting to frontend**
   - Files: `lib/creations/class-creations-views.php`
   - Add `checklistsEnabled` to `MV_CREATE_SETTINGS.__OPTIONS__`
   - Only set true if setting enabled AND Pro tier

**Verification:**
- [ ] Setting appears in Create > Settings > Recipes
- [ ] Setting respects Pro tier gating
- [ ] `MV_CREATE_SETTINGS.__OPTIONS__.checklistsEnabled` is correct in page source

---

### Group 2: Backend - Template Modifications (Independent)

**Tasks:**

1. **Add checklist mount point to ingredients template**
   - Files: `lib/views/v1/shortcode-mv-create-supplies-recipe.php`
   - Add data attributes to ingredient items for identification
   - Add `data-ingredient-group="{index}"` to group containers
   - Add `data-ingredient-index="{index}"` to each `<li>` item
   - Add collapsible wrapper with toggle button for groups

2. **Add checklist data attributes to instructions template**
   - Files: `lib/views/v1/shortcode-mv-create-instructions.php`
   - Ensure step IDs are output as data attributes
   - Add `data-step-id="{id}"` to instruction step elements

3. **Add checklist mount point div**
   - Files: `lib/views/v1/shortcode-mv-create.php` or appropriate template
   - Add `<div class="mv-create-checklists"></div>` mount point
   - Only render if setting enabled and Pro tier

**Verification:**
- [ ] Ingredient items have `data-ingredient-group` and `data-ingredient-index` attributes
- [ ] Instruction steps have `data-step-id` attributes
- [ ] Mount point div renders when feature enabled

---

### Group 3: Frontend - localStorage Utility (Independent)

**Tasks:**

1. **Create checklist storage utility**
   - Files: `client/src/Checklists/storage.js`
   - Functions:
     - `getChecklistState(creationId)` - returns state or null if expired
     - `setChecklistState(creationId, state)` - saves with timestamp
     - `isExpired(timestamp)` - checks 24-hour expiry
     - `clearExpiredChecklists()` - cleanup utility
   - Handle localStorage availability gracefully

2. **Create checklist state hook**
   - Files: `client/src/Checklists/useChecklistState.js`
   - Preact hook for managing checklist state
   - Initialize from localStorage on mount
   - Save to localStorage on state changes
   - Return: `{ state, toggleIngredient, toggleStep, toggleGroup, isLoaded }`

**Verification:**
- [ ] State persists across page reloads
- [ ] State clears after 24 hours
- [ ] Graceful fallback if localStorage unavailable

---

### Group 4: Frontend - Checklist Component (Depends on: Group 3)

**Tasks:**

1. **Create main Checklists component**
   - Files: `client/src/Checklists/index.js`
   - Entry point component, lazy-loaded
   - Find all checklist-enabled elements on mount
   - Apply checked state and event listeners
   - Handle multiple cards on same page independently

2. **Create ingredient checkbox handler**
   - Files: `client/src/Checklists/ingredients.js`
   - Add checkbox before each ingredient `<li>`
   - Apply strikethrough + dimmed style when checked
   - Update localStorage state on change

3. **Create instruction checkbox handler**
   - Files: `client/src/Checklists/instructions.js`
   - Add checkbox before each instruction step
   - Apply strikethrough + dimmed style when checked
   - Update localStorage state on change

4. **Create collapsible group handler**
   - Files: `client/src/Checklists/collapsibleGroups.js`
   - Add expand/collapse toggle to ingredient groups
   - Default: all expanded
   - Persist collapsed state in localStorage
   - Smooth CSS transition for expand/collapse

**Verification:**
- [ ] Checkboxes appear next to ingredients and instructions
- [ ] Clicking checkbox toggles checked state
- [ ] Visual feedback (strikethrough + dimmed) applies correctly
- [ ] Ingredient groups collapse/expand smoothly

---

### Group 5: Frontend - Styling (Depends on: Group 4)

**Tasks:**

1. **Create checklist styles**
   - Files: `client/src/style/components/_checklists.scss`
   - Checkbox styling (custom or native)
   - Checked state: `text-decoration: line-through; opacity: 0.6;`
   - Focus states for accessibility
   - Smooth transitions

2. **Create collapsible group styles**
   - Files: `client/src/style/components/_checklists.scss`
   - Toggle button styling
   - Expand/collapse animation (max-height transition)
   - Visual indicator for collapsed state (chevron icon)

3. **Add print media query**
   - Files: `client/src/style/components/_checklists.scss`
   - Hide checkboxes in print view
   - Remove strikethrough/dimmed styles in print

4. **Import styles in main stylesheet**
   - Files: `client/src/style/main.scss`
   - Add `@import 'components/checklists';`

**Verification:**
- [ ] Checkboxes styled appropriately
- [ ] Checked items have strikethrough + dimmed effect
- [ ] Groups expand/collapse with smooth animation
- [ ] Checkboxes hidden in print view

---

### Group 6: Frontend - Entry Point Integration (Depends on: Groups 4, 5)

**Tasks:**

1. **Add Checklists to client entry point**
   - Files: `client/src/index.js`
   - Add lazy import: `const Checklists = lazy(() => import('./Checklists'))`
   - Initialize when `checklistsEnabled` is true
   - Follow existing pattern from HandsFree component

2. **Handle Intersection Observer initialization**
   - Files: `client/src/index.js`
   - Ensure checklists initialize when card enters viewport
   - Follow existing lazy-loading pattern

**Verification:**
- [ ] Checklists component loads when setting enabled
- [ ] Component lazy-loads (check network tab for chunk)
- [ ] Works with multiple cards on same page

---

### Group 7: Testing & Edge Cases (Depends on: Group 6)

**Tasks:**

1. **Add PHPUnit tests for settings**
   - Files: `tests/integration/test-checklists-settings.php`
   - Test setting registration
   - Test Pro tier gating
   - Test setting localization

2. **Test multiple cards on same page**
   - Manual testing or Cypress
   - Each card maintains independent state
   - Checking item on card A doesn't affect card B

3. **Test localStorage edge cases**
   - localStorage disabled
   - localStorage full
   - Corrupted data recovery
   - Expiry cleanup

4. **Accessibility testing**
   - Keyboard navigation (Tab, Space/Enter to toggle)
   - Screen reader announcements
   - Focus visible states
   - ARIA labels for checkboxes and collapse buttons

**Verification:**
- [ ] PHPUnit tests pass
- [ ] Multiple cards work independently
- [ ] localStorage edge cases handled gracefully
- [ ] Accessibility requirements met

---

## Execution Instructions

To execute this spec, use the `/parallel` skill:

```
/parallel
```

**Agent Guidelines:**
- Groups 1, 2, 3 can run in parallel (Independent)
- Group 4 depends on Group 3 (needs storage utilities)
- Groups 5, 6 depend on Group 4
- Group 7 depends on Group 6
- Commit after completing each task as a checkpoint
- Use descriptive commit messages referencing the spec

**Commit Strategy:**
```bash
# Example commit messages
git commit -m "feat(checklists): add GateKeeper constant and Pro tier gating"
git commit -m "feat(checklists): add settings for enabling checklists"
git commit -m "feat(checklists): add data attributes to ingredient template"
git commit -m "feat(checklists): create localStorage utility with 24h expiry"
git commit -m "feat(checklists): implement checkbox component for ingredients"
git commit -m "feat(checklists): add collapsible ingredient groups"
git commit -m "feat(checklists): add styling with strikethrough and dimmed effect"
```

**Testing Notes:**
- Test with Pro tier active AND inactive
- Test setting enabled AND disabled
- Test multiple recipes on same page
- Test page reload for persistence
- Test after 24 hours for expiry (or mock time)
- Test print preview

## Open Questions

1. **Checkbox styling**: Native browser checkbox or custom styled checkbox?
2. **Collapse icon**: Chevron, plus/minus, or caret?
3. **Group header styling**: Should collapsed groups show count of items? ("Ingredients (8)")

## Visual Reference

```
┌─────────────────────────────────────────────────────────────┐
│  INGREDIENTS                                                │
├─────────────────────────────────────────────────────────────┤
│  ▼ For the Sauce (3)                              [−]      │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ [✓] 1 cup tomato sauce                              │   │
│  │ [ ] 2 tbsp olive oil                                │   │
│  │ [ ] 3 cloves garlic, minced                         │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ▶ For the Pasta (4)                              [+]      │
│    (collapsed)                                              │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  INSTRUCTIONS                                               │
├─────────────────────────────────────────────────────────────┤
│  [✓] 1. Heat olive oil in a large pan over medium heat.    │
│  [ ] 2. Add garlic and sauté until fragrant.               │
│  [ ] 3. Pour in tomato sauce and simmer for 10 minutes.    │
└─────────────────────────────────────────────────────────────┘

Checked item styling:
┌─────────────────────────────────────────────────────────────┐
│  [✓] 1 cup tomato sauce     (strikethrough, opacity: 0.6) │
└─────────────────────────────────────────────────────────────┘
```

## References

- Hands-Free Mode pattern: `client/src/HandsFree/index.js`
- Settings localization: `lib/creations/class-creations-views.php` (line 375)
- GateKeeper: `lib/settings/class-gatekeeper.php`
- Ingredients template: `lib/views/v1/shortcode-mv-create-supplies-recipe.php`
- Instructions template: `lib/views/v1/shortcode-mv-create-instructions.php`
- Client entry point: `client/src/index.js`
- WPRM comparison: `docs/WPRM-Feature-Comparison.md` (Section 5)
