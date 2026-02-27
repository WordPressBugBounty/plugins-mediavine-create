# Featured Review Block

**Source:** Nicole Johnson (beta feedback)
**Date:** January 6, 2026
**Last Updated:** January 7, 2026

---

## Description
WordPress Block to display a "featured" review within post content. Publishers flag one review per card as featured via the admin UI.

> "A way to 'feature' a review from Create up inside the post somewhere. Maybe with a WP Block we could add, and then a way to flag the review as featured? I've been doing this manually, using typically Pinterest reviews, but would love a way to feature actual Create reviews too."

**Use Cases:**
- Highlight positive reviews as social proof
- Replace manual copy/paste of reviews
- Increase engagement by showcasing real user feedback

---

## Technical Specification

### Block Details
| Property | Value |
|----------|-------|
| Block Name | `mv/featured-review` |
| Display Name | "Featured Create Review" |
| Availability | Free version |
| WordPress Minimum | 6.5+ |
| Reusable Blocks | Not supported (disabled) |

### Data Model

**Reviews Table Addition:**
```sql
ALTER TABLE wp_mv_reviews ADD COLUMN is_featured TINYINT(1) DEFAULT 0;
```

- Add `is_featured` column following existing schema migration pattern in Reviews class
- Only one review per card can be featured at a time
- Marking a new review as featured auto-unfeatures the previous one

### Admin UI - "Mark as Featured"

**Locations (both required):**
1. **Review List Column** - Star/toggle icon for quick access
2. **Review Detail Modal** - Checkbox in review edit/view modal

**Behavior:**
- No approval requirement - can feature any review regardless of status
- Permissions: Use existing Create permission settings
- Auto-replace: Featuring a new review automatically clears the previous featured review

### Block Behavior

**Editor States:**
| State | Editor Behavior | Frontend Behavior |
|-------|-----------------|-------------------|
| Featured review exists | Shows preview of review | Renders featured review |
| No featured review | Warning: "No featured review selected" | Renders nothing (empty) |
| Card deleted | Warning: "Card no longer exists" | Renders nothing (empty) |

**Display Fields:**
- Star rating (visual)
- Review text (full, no truncation)
- Reviewer name (First Name + Last Initial format, e.g., "Sarah M.")

**Display Settings:**
- Located in **Create global settings** (not per-block)
- Settings apply to ALL featured review blocks site-wide
- Toggle which fields display (rating, text, name)

**Styling:**
- Single default style matching Create card theme
- Mobile-responsive design
- No theme variations in initial release

### Block Registration

```js
// Block attributes
{
  cardId: { type: 'number' },  // Required - the Create card to pull featured review from
}
```

**Restrictions:**
- Cannot be used in reusable blocks or patterns (disabled)
- Reviews only from the selected Create card

### REST API

**Endpoint:** `PUT /mv-create/v1/reviews/{id}/featured`

**Request:**
```json
{ "is_featured": true }
```

**Response:**
```json
{
  "success": true,
  "data": {
    "review_id": 123,
    "is_featured": true,
    "previous_featured_id": 456  // if auto-replaced
  }
}
```

**Permissions:** Uses existing Create permission settings

### Server-Side Rendering

- Block renders server-side for SEO
- No Schema.org ReviewSnippet markup (main card handles review schema)
- Orphaned blocks (deleted card) left in post content, show nothing on frontend

---

## Mockup

```
┌────────────────────────────────────────┐
│  ★★★★★                                 │
│  "This recipe was amazing! My family   │
│  loved it and I'll definitely make     │
│  it again."                            │
│                          - Sarah M.    │
└────────────────────────────────────────┘
```

---

## Acceptance Criteria

### Core Functionality
- [ ] Reviews can be marked as featured (one per card)
- [ ] Featuring a new review auto-unfeatures the previous one
- [ ] Featured toggle appears in review list column
- [ ] Featured toggle appears in review detail modal
- [ ] Block available in Gutenberg inserter as "Featured Create Review"
- [ ] Block pulls featured review from selected card
- [ ] Block cannot be added to reusable blocks/patterns

### Display
- [ ] Shows star rating, review text, reviewer name
- [ ] Reviewer name displays as "First L." format
- [ ] Full review text shown (no truncation)
- [ ] Mobile-responsive design
- [ ] Matches Create card default styling

### Edge Cases
- [ ] No featured review: Editor shows warning, frontend shows nothing
- [ ] Card deleted: Editor shows warning, frontend shows nothing
- [ ] Block settings controlled via Create global settings

---

## Testing Plan

### PHPUnit Integration Tests (PHP 7.4)

**Review Model Tests (`tests/Integration/reviews/`):**
- [ ] `test_is_featured_column_exists` - Schema migration adds column
- [ ] `test_mark_review_as_featured` - Can set is_featured = true
- [ ] `test_auto_unfeature_previous` - Only one featured per card
- [ ] `test_get_featured_review_for_card` - Retrieves correct featured review
- [ ] `test_featured_review_deleted` - Handle deletion gracefully

**REST API Tests (`tests/Integration/api/`):**
- [ ] `test_featured_endpoint_permissions` - Respects Create permission settings
- [ ] `test_featured_endpoint_success` - Returns correct response structure
- [ ] `test_featured_endpoint_auto_replace` - Returns previous_featured_id
- [ ] `test_featured_endpoint_invalid_review` - 404 for non-existent review

**Block Render Tests (`tests/Integration/blocks/`):**
- [ ] `test_featured_review_block_render` - Correct HTML output
- [ ] `test_featured_review_block_no_review` - Empty output when no featured
- [ ] `test_featured_review_block_deleted_card` - Empty output when card missing
- [ ] `test_reviewer_name_format` - "First L." format applied correctly
- [ ] `test_block_respects_display_settings` - Global settings honored

### Jest Unit Tests (Admin UI)

**Block Registration (`admin/ui/src/blocks/featured-review/`):**
- [ ] `featured-review.test.tsx` - Block registers correctly
- [ ] `edit.test.tsx` - Edit component renders inspector controls
- [ ] `edit.test.tsx` - Card selector shows available cards
- [ ] `edit.test.tsx` - Warning state displays when no featured review
- [ ] `attributes.test.ts` - Attribute serialization/deserialization

### Playwright E2E Tests (`e2e/`)

**Block Insert + Render (`e2e/featured-review.spec.ts`):**
- [ ] Can insert "Featured Create Review" block from inserter
- [ ] Block shows card selector in sidebar
- [ ] Selecting card with featured review shows preview
- [ ] Published post displays featured review correctly
- [ ] Frontend renders rating, text, and reviewer name

**Editor States (`e2e/featured-review-states.spec.ts`):**
- [ ] Block shows warning when card has no featured review
- [ ] Block shows warning when selected card is deleted
- [ ] Block preview updates when featured review changes
- [ ] Cannot add block to reusable block (verify disabled)

**Admin UI (`e2e/featured-review-admin.spec.ts`):**
- [ ] Can mark review as featured from list view
- [ ] Can mark review as featured from detail modal
- [ ] Previous featured review auto-unfeatures
- [ ] Featured indicator visible in review list

---

## Implementation Checklist

### Phase 1: Data Layer
- [ ] Add `is_featured` to reviews table schema
- [ ] Update Reviews model with featured methods
- [ ] Add REST endpoint for toggling featured status
- [ ] Write PHPUnit tests for data layer

### Phase 2: Admin UI
- [ ] Add featured toggle to review list column
- [ ] Add featured toggle to review detail modal
- [ ] Add display settings to Create global settings
- [ ] Write Jest tests for admin components

### Phase 3: Gutenberg Block
- [ ] Create block registration (`mv/featured-review`)
- [ ] Build edit component with card selector
- [ ] Implement server-side render callback
- [ ] Disable reusable block support
- [ ] Write Jest tests for block

### Phase 4: E2E Testing
- [ ] Write Playwright tests for block insertion
- [ ] Write Playwright tests for editor states
- [ ] Write Playwright tests for admin UI

### Phase 5: QA & Release
- [ ] Manual QA on staging
- [ ] Documentation update
- [ ] Release notes
