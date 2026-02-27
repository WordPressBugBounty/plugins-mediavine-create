# Unified List Item Editor Spec

> Generated: 2025-01-16
> Status: Draft

## Overview

Redesign the list item editing experience to use a unified inline editor that eliminates the modal-based workflow. All item types (internal, external, custom text) share the same editor interface, with a smart "Source" section that determines how the item is linked to content.

## Goals

- Eliminate the modal-based add/search workflow in favor of inline editing
- Unify the editing experience across all item types (internal, external, text)
- Make it intuitive to link items to content OR create standalone custom text
- Reduce cognitive load by having one consistent editor interface
- Maintain full editing capability (title, description, image, link settings)

## Non-Goals (Out of Scope)

- Changing the underlying data model or API
- Modifying how list items render on the frontend
- Changing drag-and-drop reordering behavior
- Bulk editing capabilities

## Background

### Current System

The existing list item editor has three distinct paths:
1. **Modal workflow**: Click "Add Item" → Modal opens → Search internal content OR paste external URL → Select result → Item added with pre-populated data
2. **Custom text**: Click "Add Custom Text" → Empty text item created inline
3. **Editing**: Expand item → Different fields shown based on `content_type`

This creates UX friction:
- Users must know upfront if they want internal/external/text
- Modal interrupts the editing flow
- Different experiences for different item types

### New System

A unified inline editor where:
1. Click "Add Item" → New item appears inline, expanded, with title focused
2. "Source" section allows linking to content (URL paste or search)
3. All items have the same base fields (title, description, image)
4. Content type is determined by what's in the Source section:
   - URL → `content_type: 'external'`
   - Internal post/card → `content_type: 'card'|'post'|'page'`
   - Nothing → `content_type: 'text'`

## Technical Approach

### Component Architecture

```
ListItems.tsx (container)
├── ListItemDisplay.tsx (list + DnD)
│   └── UnifiedListItem.tsx (NEW - replaces DNDExpandable + ListItem)
│       ├── ItemHeader (title input, drag handle, expand toggle)
│       ├── SourceSection (collapsible, smart input)
│       │   ├── SourceInput (URL detection + search trigger)
│       │   ├── SourceBadge (shows linked source when collapsed)
│       │   └── SearchPanel (slide-out, split view)
│       ├── ItemFields (description, image, button text, etc.)
│       └── ItemFooter (type indicator, actions)
```

### State Flow

```typescript
interface ListItemState {
  // Source section
  sourceExpanded: boolean        // Controls Source section visibility
  sourceQuery: string            // Current input value
  searchPanelOpen: boolean       // Slide-out panel visibility
  isScrapingUrl: boolean         // Loading state for URL scrape

  // Derived from source:
  // - Empty sourceQuery + no linked content = text item
  // - URL in sourceQuery = external item (after scrape)
  // - Selected from search = internal item
}
```

### Smart Input Behavior

```
User types in Source input:
├── Detects URL pattern (https://, http://, www.)
│   └── Auto-trigger scrape → Populate fields → Set content_type: 'external'
├── Non-URL text
│   └── Open search panel → Show results → User selects → Populate fields
└── Empty/cleared
    └── content_type: 'text' (custom text item)
```

### Data Model (unchanged)

The existing `Relation` interface remains unchanged. The `content_type` field continues to drive field visibility and behavior.

## Task Groups

Tasks are organized into parallel execution groups. Each group can run concurrently unless marked as dependent.

### Group 1: Core Components (Independent)

**Tasks:**

1. **Create UnifiedListItem component shell**
   - Files: `admin/ui/src/views/Editor/components/UnifiedListItem.tsx`
   - New component with basic structure: header, source section, fields, footer
   - Accept same props as current ListItem + additional handlers
   - Skill: `/frontend-design`

2. **Create SourceSection component**
   - Files: `admin/ui/src/views/Editor/components/SourceSection.tsx`
   - Collapsible section with smart input
   - Placeholder: "Paste a URL or search for internal content..."
   - Expanded by default when empty, collapsed with badge when linked
   - Skill: `/frontend-design`

3. **Create SourceBadge component**
   - Files: `admin/ui/src/views/Editor/components/SourceBadge.tsx`
   - Compact pill showing linked source: "Recipe: Chocolate Cake" or "External: nytimes.com"
   - Clear button (X) with confirmation dialog for unlinking
   - Skill: `/frontend-design`

**Verification:**
- [ ] Components render without errors
- [ ] Source section expands/collapses correctly
- [ ] Badge displays appropriate content for each type

---

### Group 2: Search Panel (Independent)

**Tasks:**

1. **Create SearchPanel component**
   - Files: `admin/ui/src/views/Editor/components/SearchPanel.tsx`
   - Slide-out panel that appears alongside editor (split view)
   - Contains search results from internal content search
   - Close button and click-outside-to-close behavior
   - Skill: `/frontend-design`

2. **Extract search logic from ListItemModal**
   - Files: `admin/ui/src/views/Editor/components/useListItemSearch.ts`
   - Custom hook for internal content search
   - Reuse existing API calls and filtering logic
   - Return: results, loading state, search function

3. **Create SearchResultCard component**
   - Files: `admin/ui/src/views/Editor/components/SearchResultCard.tsx`
   - Individual search result with thumbnail, title, type badge
   - Click to select and populate item fields
   - Skill: `/frontend-design`

**Verification:**
- [ ] Search panel slides in/out smoothly
- [ ] Search results display correctly
- [ ] Clicking result triggers selection callback

---

### Group 3: URL Detection & Scraping (Independent)

**Tasks:**

1. **Create useUrlScrape hook**
   - Files: `admin/ui/src/views/Editor/components/useUrlScrape.ts`
   - Detect URL patterns in input
   - Auto-trigger scrape when URL detected
   - Return: scrapedData, isLoading, error, scrape function
   - Reuse existing scrape API endpoints

2. **Create SourceInput component**
   - Files: `admin/ui/src/views/Editor/components/SourceInput.tsx`
   - Input field with URL detection
   - Shows loading spinner during scrape
   - Triggers search panel for non-URL input
   - Debounced input handling

**Verification:**
- [ ] URLs are detected correctly (https://, http://, www.)
- [ ] Scraping triggers automatically on URL paste
- [ ] Non-URL text opens search panel
- [ ] Loading states display correctly

---

### Group 4: Integration (Depends on: Groups 1, 2, 3)

**Tasks:**

1. **Wire up UnifiedListItem with all sub-components**
   - Files: `admin/ui/src/views/Editor/components/UnifiedListItem.tsx`
   - Integrate SourceSection, SearchPanel, SourceInput
   - Handle source selection → field population
   - Handle source clearing → convert to text type

2. **Add unlink confirmation dialog**
   - Files: `admin/ui/src/views/Editor/components/UnifiedListItem.tsx`
   - Modal confirming "This will convert to custom text. Continue?"
   - On confirm: clear source, set content_type to 'text'

3. **Update field visibility logic**
   - Files: `admin/ui/src/views/Editor/components/UnifiedListItem.tsx`
   - Migrate TYPES_WITH_* constants from ListItem.tsx
   - Show/hide fields based on content_type
   - Ensure all existing field editing works

**Verification:**
- [ ] Selecting internal content populates fields
- [ ] Pasting URL scrapes and populates fields
- [ ] Clearing source converts to text type
- [ ] All field types edit correctly

---

### Group 5: List Integration (Depends on: Group 4)

**Tasks:**

1. **Replace ListItem usage with UnifiedListItem**
   - Files: `admin/ui/src/views/Editor/components/ListItemDisplay.tsx`
   - Swap ListItem for UnifiedListItem in render
   - Remove GatedCustomTextButton (no longer needed)
   - Update props passing

2. **Update ListItems container**
   - Files: `admin/ui/src/views/Editor/components/ListItems.tsx`
   - Remove modal-related state and handlers
   - Simplify to just manage list of items
   - Single "Add Item" button (no separate custom text button)

3. **Update NoItems empty state**
   - Files: `admin/ui/src/components/NoItems.tsx`
   - Single "Add Item" button instead of two options
   - Simpler messaging since all items start the same way

4. **Remove or deprecate ListItemModal**
   - Files: `admin/ui/src/views/Editor/components/ListItemModal.tsx`
   - Mark as deprecated or remove entirely
   - Ensure no other code depends on it

**Verification:**
- [ ] Adding item creates inline UnifiedListItem
- [ ] No modal appears during add flow
- [ ] Drag-and-drop still works
- [ ] All item types can be created and edited

---

### Group 6: Polish & Edge Cases (Depends on: Group 5)

**Tasks:**

1. **Handle scrape failures gracefully**
   - Files: `admin/ui/src/views/Editor/components/SourceInput.tsx`
   - Show error message if scrape fails
   - Allow manual entry (keep URL, edit fields manually)
   - "Retry" option

2. **Keyboard navigation**
   - Files: Multiple components
   - Tab through fields naturally
   - Escape closes search panel
   - Enter in source input triggers search/scrape

3. **Animation and transitions**
   - Files: Multiple components
   - Smooth expand/collapse for source section
   - Slide animation for search panel
   - Fade transitions for badge appearance

4. **Accessibility audit**
   - Files: Multiple components
   - Proper ARIA labels
   - Focus management
   - Screen reader announcements for async operations

**Verification:**
- [ ] Scrape failures show clear error state
- [ ] Keyboard-only navigation works
- [ ] Animations are smooth and not jarring
- [ ] No accessibility violations

---

## Execution Instructions

To execute this spec, use the `/parallel` skill:

```
/parallel
```

**Agent Guidelines:**
- Each task group can be assigned to a separate agent
- Groups marked "Independent" can run in parallel (Groups 1, 2, 3)
- Groups with dependencies wait for prerequisites
- Use `/frontend-design` skill for UI components (marked above)
- Commit after completing each task as a checkpoint
- Use descriptive commit messages referencing the spec

**Commit Strategy:**
```bash
# Example commit messages
git commit -m "feat(list-editor): create UnifiedListItem component shell"
git commit -m "feat(list-editor): add SourceSection with smart input"
git commit -m "feat(list-editor): implement URL detection and auto-scrape"
git commit -m "refactor(list-editor): replace ListItem with UnifiedListItem"
```

**Testing Notes:**
- Test all three item creation paths: URL paste, internal search, empty (text)
- Test unlinking flow with confirmation
- Test editing existing items of each type
- Verify drag-and-drop still functions
- Check responsive behavior

## Open Questions

1. **Search panel width**: How wide should the slide-out panel be? 300px? 400px? Percentage-based?
2. **Search filters**: Should the type filters (Recipe, DIY, Post, etc.) be in the panel or simplified?
3. **Auto-close behavior**: Should search panel auto-close when item is selected, or stay open for adding more?

## Visual Reference

```
┌─────────────────────────────────────────────────────────────────────┐
│ [≡] Item Title Here                                    [−] [🗑] [▾] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ ▼ Source                                    ┌──────────────────────┐│
│ ┌─────────────────────────────────────────┐ │  SEARCH PANEL        ││
│ │ Paste a URL or search for content...    │ │                      ││
│ └─────────────────────────────────────────┘ │  [Recipe] Chocolate  ││
│                                             │   Cake               ││
│ ─ OR when linked: ─────────────────────    │  [Recipe] Vanilla    ││
│                                             │   Cupcakes           ││
│ ▶ Source  [Recipe: Chocolate Cake] [×]     │  [Post] Baking Tips  ││
│                                             │                      ││
│ ───────────────────────────────────────────│──────────────────────┘│
│                                                                     │
│ Description                                              [Image]    │
│ ┌─────────────────────────────────────────┐             ┌───────┐  │
│ │ Rich text editor...                     │             │       │  │
│ │                                         │             │  📷   │  │
│ └─────────────────────────────────────────┘             └───────┘  │
│                                                                     │
│ Button Text: [Get the Recipe ▾]                                    │
│                                                                     │
│ ─────────────────────────────────────────── [Recipe] ──────────────│
└─────────────────────────────────────────────────────────────────────┘
```

## References

- Current implementation: `admin/ui/src/views/Editor/components/ListItem.tsx`
- Modal implementation: `admin/ui/src/views/Editor/components/ListItemModal.tsx`
- List container: `admin/ui/src/views/Editor/components/ListItems.tsx`
- Data types: `admin/ui/src/types/responses.ts` (Relation interface)
- Existing search/scrape APIs: `mv-create/v1/list/search`, `mv-create/v1/products/scrape`
