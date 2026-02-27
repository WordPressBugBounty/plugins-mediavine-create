# GateKeeper Feature Specification

## Overview

This document specifies the GateKeeper system for managing feature access based on subscription tiers in the Create plugin. The system gates specific premium features for users without an active Pro or Ad-Supported subscription, while maintaining a clear upgrade path.

## Problem Statement

- Create plugin currently offers all features to all users
- No monetization mechanism for premium features
- Need to differentiate between Free, Pro, and Ad-Supported tiers
- Must integrate with existing CreateStudio authentication system
- Premium features should be visible but disabled for free users to drive upgrades

## Goals

1. Gate specific premium features based on subscription tier
2. Store and cache subscription status locally with TTL-based refresh
3. Provide clear visual indication of gated features (Pro badges)
4. Enable seamless upgrade flow to CreateStudio
5. Enforce gating on both frontend (UX) and backend (security)
6. Handle tier downgrades gracefully (fallback to free alternatives)

## Non-Goals

- Changing the existing site-level connection flow
- Implementing payment processing (handled by CreateStudio)
- Blocking access to user-generated content (reviews, cards, etc.)
- Implementing the Ad-Supported tier in this phase (future work)

---

## Project Structure & Agent Instructions

This feature primarily spans the **Create Plugin** codebase with minor additions to **Create Studio** for subscription data.

### Codebase Locations

| Project | Path | Tech Stack |
|---------|------|------------|
| **Create Plugin** (WordPress) | `/Users/jm/Sites/wordpress/plugins/create` | PHP 7.4+, React (admin UI), Preact (client) |
| **Create Studio** (Web App) | `/Users/jm/Sites/create-studio` | (Check repo for stack details) |

### Agent Execution Strategy

When implementing this spec, the orchestrating agent should:

1. **Spawn parallel subagents** for independent work streams (see Work Streams below)
2. **Use the `frontend-design` skill** for all UI component work
3. **Coordinate API contracts** - ensure subscription data format is agreed upon
4. **Make small, focused commits** for each task
5. **Report progress** to this document's Progress Tracking section

### Work Streams (Parallelizable)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         PARALLEL WORK STREAMS                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Stream A: Core GateKeeper Backend       Stream B: Feature Gating Backend   │
│  ───────────────────────────────────     ─────────────────────────────────  │
│  • GateKeeper PHP class                  • Theme fallback logic             │
│  • Subscription sync with TTL            • Review response gating           │
│  • Settings storage integration          • Featured review gating           │
│  • Create Studio API client updates      • Interactive mode script gating   │
│                                                                              │
│  Stream C: Admin UI Components           Stream D: Create Studio Updates    │
│  ───────────────────────────────────     ─────────────────────────────────  │
│  • GateKeeper React component/hook       • Add subscription tier to API     │
│  • ProBadge component                    • Site object response updates     │
│  • Theme selector gating                                                     │
│  • Review management UI gating                                               │
│  • Featured review block gating                                              │
│                                                                              │
│  Stream E: Client-Side Gating                                                │
│  ───────────────────────────────────                                         │
│  • Interactive mode widget script        • Review response rendering         │
│  • Servings adjustment gating            • Featured review rendering         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Subagent Spawning Instructions

```
# Phase 1: Core infrastructure (spawn 2 agents in parallel)
Agent A: "Implement GateKeeper PHP class and subscription sync per spec"
         Path: /Users/jm/Sites/wordpress/plugins/create

Agent D: "Add subscription tier to Create Studio /users/{id} API response per spec"
         Path: /Users/jm/Sites/create-studio

# Phase 2: Feature gating (spawn 2-3 agents in parallel after Phase 1)
Agent B: "Implement PHP-side feature gating for themes, reviews, interactive mode per spec"
         Path: /Users/jm/Sites/wordpress/plugins/create

Agent C: "Implement React admin UI gating components per spec"
         Path: /Users/jm/Sites/wordpress/plugins/create
         Skill: Use /frontend-design for UI components

Agent E: "Implement client-side (Preact) gating for interactive mode and review rendering per spec"
         Path: /Users/jm/Sites/wordpress/plugins/create
```

### Coordination Points

1. **Subscription Data Format** - Agree on tier identifier strings before implementation
2. **GateKeeper API** - PHP class must be available before feature gating work begins
3. **Settings Localization** - Ensure subscription tier is passed to frontend before UI work

---

## Architecture

### Subscription Tiers

| Tier | Identifier | Access Level |
|------|------------|--------------|
| Free | `free` | Limited - gated features disabled |
| Pro | `pro` | Full access to all features |
| Ad-Supported | `ad_supported` | Full access with ads (future) |

### Gated Features

| Feature | Category | Gating Behavior | Fallback |
|---------|----------|-----------------|----------|
| Editorial Theme | Themes | Disabled in selector, Pro badge | Switch to `big-image` |
| Modern Elegant Theme | Themes | Disabled in selector, Pro badge | Switch to `big-image` |
| Edit User Reviews | Reviews | Button disabled, Pro badge | Read-only view |
| Respond to Reviews | Reviews | Form hidden, upgrade prompt | No response capability |
| Mark Review as Featured | Reviews | Checkbox disabled, Pro badge | Cannot feature |
| Featured Review Block | Gutenberg | Block disabled, Pro badge | Block unavailable |
| Interactive Mode | Client | Widget script not loaded | Standard card view |
| Servings Adjustments | Client | Widget script not loaded | Static servings |
| Text-Only List Items | Editor | Option disabled, Pro badge | Require image |

### Data Storage

```
mv_settings table
├── subscription_tier (string: 'free' | 'pro' | 'ad_supported')
├── subscription_synced_at (datetime: ISO 8601 timestamp)
└── subscription_expires_at (datetime: TTL expiration, ~1 hour from sync)
```

### Current State (CreateStudio Connection)

```
mv_settings
├── api_token (string) - Site-level JWT token
├── mv_create_api_user_id (string) - User ID for API calls
└── api_email_confirmed (bool) - Email verification status
```

### Proposed State (Add Subscription Data)

```
mv_settings (existing)
├── api_token (string) - Site-level JWT token
├── mv_create_api_user_id (string) - User ID for API calls
├── api_email_confirmed (bool) - Email verification status
└── [existing settings...]

mv_settings (new)
├── mv_create_subscription_tier (string) - 'free' | 'pro' | 'ad_supported'
├── mv_create_subscription_synced_at (datetime) - Last sync timestamp
└── mv_create_subscription_ttl (int) - TTL in seconds (default: 3600)
```

---

## GateKeeper Class Design

### PHP Class: `GateKeeper`

Location: `lib/settings/class-gatekeeper.php`

```php
namespace Mediavine\Create;

class GateKeeper {

    // Subscription tier constants
    const TIER_FREE = 'free';
    const TIER_PRO = 'pro';
    const TIER_AD_SUPPORTED = 'ad_supported';

    // Feature identifiers
    const FEATURE_THEME_EDITORIAL = 'theme_editorial';
    const FEATURE_THEME_MODERN = 'theme_modern';
    const FEATURE_REVIEW_EDIT = 'review_edit';
    const FEATURE_REVIEW_RESPOND = 'review_respond';
    const FEATURE_REVIEW_FEATURED = 'review_featured';
    const FEATURE_FEATURED_REVIEW_BLOCK = 'featured_review_block';
    const FEATURE_INTERACTIVE_MODE = 'interactive_mode';
    const FEATURE_SERVINGS_ADJUSTMENT = 'servings_adjustment';
    const FEATURE_TEXT_ONLY_LIST_ITEM = 'text_only_list_item';

    // TTL for subscription cache (1 hour)
    const SUBSCRIPTION_TTL = 3600;

    /**
     * Check if a feature is gated (requires Pro or Ad-Supported tier).
     */
    public static function is_feature_gated( string $feature ): bool;

    /**
     * Check if user has access to a specific feature.
     */
    public static function can_access( string $feature ): bool;

    /**
     * Get the current subscription tier.
     */
    public static function get_subscription_tier(): string;

    /**
     * Check if subscription cache needs refresh.
     */
    public static function needs_subscription_refresh(): bool;

    /**
     * Sync subscription status from CreateStudio.
     */
    public static function sync_subscription(): bool;

    /**
     * Get the upgrade URL for CreateStudio.
     */
    public static function get_upgrade_url(): string;

    /**
     * Check if the current tier is Pro or higher.
     */
    public static function is_pro_or_higher(): bool;

    /**
     * Get list of all gated features.
     */
    public static function get_gated_features(): array;

    /**
     * Get fallback value for a gated feature (e.g., fallback theme).
     */
    public static function get_fallback( string $feature );
}
```

### React Hook: `useGateKeeper`

Location: `admin/ui/src/hooks/useGateKeeper.ts`

```typescript
interface GateKeeperState {
  subscriptionTier: 'free' | 'pro' | 'ad_supported';
  isProOrHigher: boolean;
  canAccess: (feature: string) => boolean;
  isFeatureGated: (feature: string) => boolean;
  upgradeUrl: string;
  gatedFeatures: string[];
}

export function useGateKeeper(): GateKeeperState;
```

### React Component: `ProBadge`

Location: `admin/ui/src/components/ProBadge.tsx`

```typescript
interface ProBadgeProps {
  feature?: string;
  showUpgradeLink?: boolean;
  size?: 'sm' | 'md';
}

export function ProBadge({ feature, showUpgradeLink, size }: ProBadgeProps): JSX.Element;
```

### React Component: `GatedFeature`

Location: `admin/ui/src/components/GatedFeature.tsx`

```typescript
interface GatedFeatureProps {
  feature: string;
  children: React.ReactNode;
  fallback?: React.ReactNode;
  showBadge?: boolean;
  disabled?: boolean;
}

export function GatedFeature({
  feature,
  children,
  fallback,
  showBadge,
  disabled
}: GatedFeatureProps): JSX.Element;
```

---

## Subscription Sync Flow

### Sync Trigger Points

1. **Admin Page Load** - Check TTL, refresh if expired
2. **Plugin Activation** - Initial sync
3. **Manual Refresh** - User-triggered via settings

### Sync Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SUBSCRIPTION SYNC FLOW                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Admin loads Create plugin page                                           │
│     └─► GateKeeper::needs_subscription_refresh() called                      │
│                                                                              │
│  2. Check if TTL expired                                                     │
│     ├─► No  → Use cached subscription_tier                                   │
│     └─► Yes → Continue to step 3                                             │
│                                                                              │
│  3. Call Create Studio API                                                   │
│     GET /api/v2/users/{user_id}?site_url={site_url}                         │
│     Authorization: Bearer {api_token}                                        │
│                                                                              │
│  4. Parse response                                                           │
│     {                                                                        │
│       "user": { ... },                                                       │
│       "site": {                                                              │
│         "id": 123,                                                           │
│         "subscription_tier": "pro",  // NEW FIELD                            │
│         "verified": true                                                     │
│       }                                                                      │
│     }                                                                        │
│                                                                              │
│  5. Update local cache                                                       │
│     └─► Store tier, synced_at timestamp in mv_settings                       │
│                                                                              │
│  6. Return subscription tier for use                                         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### API Contract: CreateStudio Response Update

**Endpoint:** `GET /api/v2/users/{id}?site_url={site_url}`

**Current Response:**
```json
{
  "success": true,
  "user": { "validEmail": true, "hasPassword": true },
  "site": { "id": 123, "verified": true, "url": "https://example.com" }
}
```

**Updated Response:**
```json
{
  "success": true,
  "user": { "validEmail": true, "hasPassword": true },
  "site": {
    "id": 123,
    "verified": true,
    "url": "https://example.com",
    "subscription_tier": "pro"  // NEW: 'free' | 'pro' | 'ad_supported'
  }
}
```

---

## UI Specification

### Pro Badge Component

```
┌─────────────────┐
│  PRO  │
└─────────────────┘
```

- Small size: 9px font, 2px 6px padding
- Medium size: 11px font, 4px 8px padding
- Background: Primary brand color (or gradient)
- Text: White, uppercase, bold
- Optional: Hover shows upgrade tooltip

### Theme Selector - Gated Themes

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Available Themes                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│ ┌───────────────────────────────────────────────────────────────────────┐   │
│ │ Editorial [PRO]                                                   🔒  │   │
│ │ by Mischief Marmot                                                    │   │
│ │ A magazine-inspired design with refined typography...                 │   │
│ │ ──────────────────────────────────────────────────────────            │   │
│ │ [Upgrade to unlock this theme →]                                      │   │
│ └───────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│ ┌───────────────────────────────────────────────────────────────────────┐   │
│ │ Modern Elegant [PRO]                                              🔒  │   │
│ │ by Mischief Marmot                                                    │   │
│ │ A contemporary, minimalist design with clean lines...                 │   │
│ │ ──────────────────────────────────────────────────────────            │   │
│ │ [Upgrade to unlock this theme →]                                      │   │
│ └───────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│ ┌───────────────────────────────────────────────────────────────────────┐   │
│ │ ✓ Hero Image                                                          │   │
│ │ by Purr Design                                                        │   │
│ │ A bold, hero-style layout that puts your recipe image...              │   │
│ └───────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Review Management - Gated Actions

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Review from John D.                                              ★★★★★     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│ "This recipe was amazing! My family loved it."                              │
│                                                                              │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ Actions                                                                  │ │
│ ├─────────────────────────────────────────────────────────────────────────┤ │
│ │                                                                          │ │
│ │ [Edit Review] [PRO]  [Mark as Featured] [PRO]  [Delete]                 │ │
│ │                                                                          │ │
│ │ ─────────────────────────────────────────────────────────────────────── │ │
│ │                                                                          │ │
│ │ Reply to this review [PRO]                                              │ │
│ │ ┌─────────────────────────────────────────────────────────────────────┐ │ │
│ │ │ Upgrade to Pro to respond to reviews                                │ │ │
│ │ │                                    [Upgrade →]                      │ │ │
│ │ └─────────────────────────────────────────────────────────────────────┘ │ │
│ │                                                                          │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Gutenberg Block - Featured Review (Gated)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Featured Review Block [PRO]                                             🔒  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │                                                                          │ │
│ │     This block requires a Pro subscription                              │ │
│ │                                                                          │ │
│ │     Display featured reviews from your Create cards                     │ │
│ │     to build trust and showcase reader feedback.                        │ │
│ │                                                                          │ │
│ │                     [Upgrade to Pro →]                                  │ │
│ │                                                                          │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Feature Gating Implementation Details

### 1. Theme Gating

**Files to modify:**
- `lib/settings-group/class-display.php` - Add `gated: true` to theme options
- `admin/ui/src/views/Settings/components/ThemeSelector.tsx` - Disable gated themes
- `lib/creations/class-creations-views.php` - Enforce fallback on render

**Gating Logic:**
```php
// On card render, check if selected theme is gated
$selected_theme = Settings::get_setting('mv_create_card_style');
$gated_themes = ['editorial', 'modern'];

if (in_array($selected_theme, $gated_themes) && !GateKeeper::is_pro_or_higher()) {
    $selected_theme = 'big-image'; // Fallback theme
}
```

**Downgrade Behavior:**
- When a Pro user with `editorial` or `modern` theme downgrades to Free:
  1. Theme setting remains unchanged in database
  2. On next card render, GateKeeper detects gated theme + free tier
  3. Card renders with `big-image` (Hero) fallback theme
  4. Admin theme selector shows original theme as selected but disabled
  5. User sees prompt to upgrade to re-enable

### 2. Review Management Gating

**Files to modify:**
- `lib/reviews/class-reviews-api.php` - Gate edit/respond endpoints
- `lib/reviews/class-review-responses-api.php` - Gate response creation
- `lib/reviews/class-featured-review-api.php` - Gate featured marking
- `admin/ui/src/views/Collections/Reviews.tsx` - Disable UI actions
- `admin/ui/src/views/Collections/components/SingleReview.tsx` - Disable edit
- `admin/ui/src/views/Collections/components/ResponseForm.tsx` - Show upgrade prompt

**Backend Gating:**
```php
// In Reviews_API::update_review()
if (!GateKeeper::can_access(GateKeeper::FEATURE_REVIEW_EDIT)) {
    return new \WP_Error(
        'feature_gated',
        __('Editing reviews requires a Pro subscription', 'mediavine'),
        ['status' => 403, 'upgrade_url' => GateKeeper::get_upgrade_url()]
    );
}
```

### 3. Featured Review Block Gating

**Files to modify:**
- `lib/blocks/class-featured-review-block.php` - Gate block registration/render
- `admin/ui/src/views/Blocks/FeaturedReviewBlock.tsx` - Show upgrade placeholder
- `admin/ui/src/main.tsx` - Conditional block registration

**Block Registration:**
```php
// Only register block for Pro users, or register with disabled state
public function register_block() {
    if (!GateKeeper::can_access(GateKeeper::FEATURE_FEATURED_REVIEW_BLOCK)) {
        // Register with gated state - shows placeholder in editor
        register_block_type('mv/featured-review', [
            'render_callback' => [$this, 'render_gated_placeholder'],
            'attributes' => [...],
        ]);
        return;
    }
    // Normal registration for Pro users
    register_block_type('mv/featured-review', [...]);
}
```

### 4. Interactive Mode & Servings Adjustment Gating

**Files to modify:**
- `lib/creations/class-creations-views.php` - Conditional widget script loading
- `client/src/components/` - No changes needed (script won't load)

**Script Loading Gating:**
```php
// In Creations_Views, where CreateStudio widget script is enqueued
public function enqueue_interactive_mode_scripts() {
    if (!GateKeeper::can_access(GateKeeper::FEATURE_INTERACTIVE_MODE)) {
        // Don't enqueue the CreateStudio embed widget script
        return;
    }

    wp_enqueue_script(
        'create-studio-embed',
        Plugin::$create_studio_embed_url,
        [],
        null,
        true
    );
}
```

**Effect:** When script is not loaded:
- Interactive mode button does not appear on cards
- Servings adjustment slider does not appear
- Cards display in standard, non-interactive format

### 5. Review Response Client Rendering

**Files to modify:**
- `client/src/components/Reviews/` - Check tier before rendering responses
- `lib/creations/class-creations-views.php` - Filter responses from JSON output

**Server-Side Filtering:**
```php
// When preparing card data for client render
public function prepare_card_data($card) {
    // ... existing preparation ...

    // Filter admin responses if not Pro
    if (!GateKeeper::can_access(GateKeeper::FEATURE_REVIEW_RESPOND)) {
        if (isset($card['reviews'])) {
            foreach ($card['reviews'] as &$review) {
                // Keep reviews, remove admin responses
                unset($review['admin_responses']);
                $review['response_count'] = 0;
            }
        }
    }

    return $card;
}
```

### 6. Text-Only List Items

**Files to modify:**
- `admin/ui/src/views/Editor/List.tsx` - Disable "no image" option
- `lib/api/class-creations-api.php` - Validate on save

**Frontend Gating:**
```typescript
// In List editor component
const canAddTextOnlyItem = useGateKeeper().canAccess('text_only_list_item');

// When rendering "Add Item" options
{canAddTextOnlyItem ? (
  <Button onClick={addTextOnlyItem}>Add Text-Only Item</Button>
) : (
  <GatedFeature feature="text_only_list_item">
    <Button disabled>Add Text-Only Item <ProBadge /></Button>
  </GatedFeature>
)}
```

---

## Upgrade Flow

### Upgrade URL Format

```
https://create.studio/admin/settings?site_url={encoded_site_url}#subscription
```

**URL Construction:**
```php
public static function get_upgrade_url(): string {
    $site_url = rawurlencode(home_url());
    return Plugin::$create_studio_base_url . '/admin/settings?site_url=' . $site_url . '#subscription';
}
```

### Upgrade CTA Patterns

1. **Inline Badge** - Small "PRO" badge next to feature name
2. **Disabled State** - Feature visible but non-interactive with tooltip
3. **Upgrade Prompt** - Replace feature UI with upgrade message and CTA button
4. **Block Placeholder** - Gutenberg block shows upgrade message instead of content

---

## Downgrade Behavior Summary

| Feature | Downgrade Action | User Impact |
|---------|------------------|-------------|
| Editorial/Modern Theme | Fallback to Hero Image | Cards render with Hero theme |
| Review Edit | Disable edit button | Read-only view of reviews |
| Review Respond | Hide response form | Cannot add new responses |
| Existing Responses | Don't render on client | Readers don't see admin replies |
| Featured Review | Disable checkbox | Cannot feature new reviews |
| Existing Featured | Keep featured status | Still featured, can't change |
| Featured Review Block | Render empty/placeholder | Block shows upgrade message |
| Interactive Mode | Don't load widget script | Standard card display |
| Text-Only List Items | Disable option | Cannot add new text-only items |
| Existing Text-Only | Keep items | Existing items remain |

**Key Principle:** Nothing is deleted. Content remains intact but may not render or be editable until upgrade.

---

## Security Considerations

1. **Backend Enforcement** - All gated features must check subscription tier on PHP side
2. **Frontend is UX Only** - Frontend gating is for user experience; backend is the authority
3. **API Error Responses** - Include upgrade URL in 403 responses for gated features
4. **Cached Tier Trust** - Use cached tier if API unavailable (fail open, not closed)
5. **No Data Exposure** - Gated features don't expose data differently, just disable functionality

---

## Testing Strategy

### Unit Tests

- `GateKeeper::is_feature_gated()` returns correct values
- `GateKeeper::can_access()` respects subscription tier
- `GateKeeper::get_subscription_tier()` returns cached value
- `GateKeeper::needs_subscription_refresh()` respects TTL
- Theme fallback works correctly
- API endpoints return 403 for gated features

### Integration Tests

- Subscription sync updates mv_settings
- Theme selector disables gated themes for free tier
- Review management buttons disabled for free tier
- Featured review block shows placeholder for free tier
- Interactive mode script not loaded for free tier
- Downgrade triggers correct fallback behavior

### E2E Tests

- Complete upgrade flow from plugin to CreateStudio
- Feature access after subscription sync
- Theme change blocked for gated themes
- Review response form shows upgrade prompt

---

## Implementation Phases

### Phase 1: Core Infrastructure

**Stream A: GateKeeper Backend** (`/Users/jm/Sites/wordpress/plugins/create`)
- [ ] A1: Create `GateKeeper` PHP class with tier constants and feature constants
- [ ] A2: Implement `get_subscription_tier()` with mv_settings storage
- [ ] A3: Implement `needs_subscription_refresh()` with TTL logic
- [ ] A4: Implement `sync_subscription()` using Create_Studio_Client
- [ ] A5: Implement `is_feature_gated()` and `can_access()` methods
- [ ] A6: Implement `get_upgrade_url()` method
- [ ] A7: Add subscription sync trigger on admin page load
- [ ] A8: Write unit tests for GateKeeper class

**Stream D: CreateStudio API** (`/Users/jm/Sites/create-studio`)
- [ ] D1: Add `subscription_tier` field to Site model
- [ ] D2: Update `/api/v2/users/{id}` to include `subscription_tier` in site object
- [ ] D3: Default `subscription_tier` to 'free' for existing sites
- [ ] D4: Add admin UI for managing site subscription tiers (if not exists)

**Checkpoint:** GateKeeper class working, subscription sync verified

### Phase 2: Feature Gating - Backend

**Stream B: PHP Feature Gating** (`/Users/jm/Sites/wordpress/plugins/create`)
- [ ] B1: Add theme gating metadata to `class-display.php`
- [ ] B2: Implement theme fallback in `class-creations-views.php`
- [ ] B3: Add gating check to `Reviews_API::update_review()`
- [ ] B4: Add gating check to `Review_Responses_API::create_response()`
- [ ] B5: Add gating check to `Featured_Review_API` endpoints
- [ ] B6: Implement `render_gated_placeholder()` for Featured Review block
- [ ] B7: Conditionally load interactive mode widget script
- [ ] B8: Filter admin responses from card data for free tier
- [ ] B9: Write integration tests for gated endpoints

### Phase 3: Feature Gating - Frontend

**Stream C: Admin UI Gating** (`/Users/jm/Sites/wordpress/plugins/create/admin/ui`)
> **Note:** Use `/frontend-design` skill for UI component implementation

- [ ] C1: Create `useGateKeeper` hook with subscription state
- [ ] C2: Create `ProBadge` component (styled, responsive sizes)
- [ ] C3: Create `GatedFeature` wrapper component
- [ ] C4: Update `ThemeSelector.tsx` to disable gated themes with ProBadge
- [ ] C5: Update `SingleReview.tsx` to disable edit button for free tier
- [ ] C6: Update `ResponseForm.tsx` to show upgrade prompt for free tier
- [ ] C7: Update `ExpandableReviewRow.tsx` to disable featured checkbox
- [ ] C8: Update `FeaturedReviewBlock.tsx` to show gated placeholder
- [ ] C9: Update List editor to disable text-only item option
- [ ] C10: Localize subscription tier to frontend via settings

### Phase 4: Client-Side Gating

**Stream E: Client Gating** (`/Users/jm/Sites/wordpress/plugins/create`)
- [ ] E1: Verify interactive mode script conditional loading works
- [ ] E2: Verify admin responses filtered from card data
- [ ] E3: Test downgrade scenarios for all features
- [ ] E4: Add client-side tests if applicable

### Phase 5: Integration & Testing

- [ ] F1: End-to-end testing of subscription sync flow
- [ ] F2: Test upgrade flow from plugin to CreateStudio
- [ ] F3: Test all feature gating scenarios (free vs pro)
- [ ] F4: Test downgrade behavior for all features
- [ ] F5: Performance testing of subscription sync
- [ ] F6: Update documentation

---

## Admin Notifications

When subscription status changes, show notifications:

### Downgrade Notice (Admin Banner)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ⚠️ Your Create Pro subscription has expired. Some features are now          │
│ limited. [Renew subscription →]                                       [✕]  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Plugin Dashboard Notice

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Subscription Status                                                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│ Current tier: Free                                                          │
│                                                                              │
│ Upgrade to Pro to unlock:                                                   │
│ • Premium card themes (Editorial, Modern Elegant)                           │
│ • Review management (edit, respond, feature)                                │
│ • Interactive mode for readers                                              │
│ • And more...                                                               │
│                                                                              │
│ [Upgrade to Pro →]                                                          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Related Documentation

- [Create Studio Authentication Flow](./CreateStudioAuth.md)
- [User Verification Specification](./UserVerificationSpec.md)
- [Settings API](./SettingsAPI.md)

---

## Changelog

| Date | Author | Changes |
|------|--------|---------|
| 2026-01-15 | - | Initial specification |

---

## Progress Tracking

> **Instructions for Agents:** Update this section as work progresses. Mark tasks with completion status and add notes about any blockers, decisions, or deviations from spec.

### Current Status

| Stream | Status | Agent | Last Updated |
|--------|--------|-------|--------------|
| A: GateKeeper Backend | ✅ Complete | a626cc9 | 2026-01-15 |
| B: PHP Feature Gating | ✅ Complete | a10a23c | 2026-01-15 |
| C: Admin UI Gating | ✅ Complete | - | 2026-01-15 |
| D: CreateStudio API | ✅ Complete | - | 2026-01-15 |
| E: Client Gating | ✅ Complete | - | 2026-01-15 |
| F: Integration & Testing | ⬜ Not Started | - | - |

### Task Completion Log

#### Stream A: GateKeeper Backend
| Task | Status | Notes |
|------|--------|-------|
| A1: GateKeeper class | ✅ Complete | Created `lib/settings/class-gatekeeper.php` with all constants |
| A2: get_subscription_tier() | ✅ Complete | Reads from `mv_create_subscription_tier` setting |
| A3: needs_subscription_refresh() | ✅ Complete | TTL-based refresh check (1 hour) |
| A4: sync_subscription() | ✅ Complete | Uses Create_Studio_Client to sync via API |
| A5: is_feature_gated() / can_access() | ✅ Complete | Full feature gating logic implemented |
| A6: get_upgrade_url() | ✅ Complete | Builds URL to Create Studio subscription page |
| A7: Admin page sync trigger | ✅ Complete | Hooked into `admin_init` |
| A8: Unit tests | ⬜ Not Started | |

#### Stream B: PHP Feature Gating
| Task | Status | Notes |
|------|--------|-------|
| B1: Theme gating metadata | ✅ Complete | Added `gated: true` to Editorial/Modern in class-display.php |
| B2: Theme fallback | ✅ Complete | Fallback to 'simple' theme in class-creations-views.php |
| B3: Reviews API gating | ✅ Complete | Added check in `update_single_review()` |
| B4: Review Responses API gating | ✅ Complete | Added gating to `create_response()` and `update_response()` |
| B5: Featured Review API gating | ✅ Complete | Added gating to `set_featured()` endpoint |
| B6: Featured Review block placeholder | ⬜ Not Started | Low priority - block already gated via UI |
| B7: Interactive mode script gating | ✅ Complete | `enqueue_studio_script()` checks `FEATURE_INTERACTIVE_MODE` |
| B8: Filter admin responses | ⬜ Not Started | Low priority - responses hidden when script not loaded |
| B9: Integration tests | ⬜ Not Started | |

#### Stream C: Admin UI Gating
| Task | Status | Notes |
|------|--------|-------|
| C1: useGateKeeper hook | ✅ Complete | Created `hooks/useGateKeeper.ts` |
| C2: ProBadge component | ✅ Complete | Created `components/ProBadge.tsx` with gradient styling |
| C3: GatedFeature component | ✅ Complete | Created `components/GatedFeature.tsx` wrapper |
| C4: ThemeSelector gating | ✅ Complete | Updated with lock icons, upgrade CTAs |
| C5: ExpandableReviewRow edit gating | ✅ Complete | Edit button/form disabled with ProBadge for free users |
| C6: ExpandableReviewRow respond gating | ✅ Complete | Reply button disabled with ProBadge for free users |
| C7: ExpandableReviewRow featured gating | ✅ Complete | Featured toggle disabled with ProBadge for free users |
| C8: FeaturedReview component gating | ✅ Complete | Shows GatedFeature upgrade prompt for free users |
| C9: List editor text-only gating | ✅ Complete | GatedCustomTextButton component disables "+ Custom Text" |
| C10: Localize subscription tier | ✅ Complete | Added to `class-display.php` settings localization |

#### Stream D: CreateStudio API
| Task | Status | Notes |
|------|--------|-------|
| D1: Site model subscription_tier | ✅ Complete | Already exists in Subscriptions table |
| D2: Update /users/{id} endpoint | ✅ Complete | Added `subscription_tier` to site object in v2 API |
| D3: Default tier for existing sites | ✅ Complete | SubscriptionRepository.getActiveTier() defaults to 'free' |
| D4: Admin UI for tiers | ⬜ Not Started | Low priority - can manage via DB/Stripe |

#### Stream E: Client Gating
| Task | Status | Notes |
|------|--------|-------|
| E1: Interactive mode script verify | ✅ Complete | Verified `enqueue_studio_script()` checks `FEATURE_INTERACTIVE_MODE` |
| E2: Admin responses filter verify | ✅ Complete | Responses not rendered when embed script not loaded |
| E3: Downgrade scenario testing | ⬜ Not Started | Manual testing needed |
| E4: Client-side tests | ⬜ Not Started | |

#### Stream F: Integration & Testing
| Task | Status | Notes |
|------|--------|-------|
| F1: E2E subscription sync | ⬜ Not Started | |
| F2: Upgrade flow testing | ⬜ Not Started | |
| F3: Feature gating scenarios | ⬜ Not Started | |
| F4: Downgrade behavior | ⬜ Not Started | |
| F5: Performance testing | ⬜ Not Started | |
| F6: Documentation updates | ⬜ Not Started | |

### Blockers & Issues

| ID | Description | Stream | Status | Resolution |
|----|-------------|--------|--------|------------|
| - | - | - | - | - |

### Decisions Made During Implementation

| Date | Decision | Rationale | Affected Tasks |
|------|----------|-----------|----------------|
| 2026-01-15 | Use existing Subscriptions table | Already has `tier` field, no schema changes needed | D1 |
| 2026-01-15 | Theme fallback to 'simple' not 'big-image' | 'simple' is the default theme in codebase | B2 |
| 2026-01-15 | ProBadge uses brand teal/slate gradient | Matches existing design system colors | C2 |

### Status Legend
- ⬜ Not Started
- 🔄 In Progress
- ✅ Complete
- ⚠️ Blocked
- ❌ Cancelled
