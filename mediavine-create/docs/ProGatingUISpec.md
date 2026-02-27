# Pro Gating UI Redesign Specification

## Overview

This specification outlines the redesign of the Pro feature gating UI system across the Mediavine Create plugin admin interface. The goal is to transform cluttered multi-badge chaos into elegant single-indicator zones with clear communication of locked features.

## Problem Statement

### Current Issues

1. **Too Many Pro Indicators** - Theme selector displays 4 separate indicators (badge, lock icon, upgrade button, preview overlay) which is overwhelming
2. **Unclear Gating Communication** - User reviews show 3 separate Pro badges on individual action buttons (edit, reply, featured) without clearly communicating what's locked as a group
3. **Fading Pro Badges** - When parent elements are disabled/faded (opacity: 0.5-0.6), Pro badges inherit that opacity and become hard to see
4. **No Unified Feature List** - Users don't see a consolidated view of all Pro features they could unlock

### Current Implementation Locations

| Location | Current Indicators | Issue |
|----------|-------------------|-------|
| ThemeSelector | ProBadge + LockIcon + UpgradeCTA + LockedOverlay | 4 indicators for same feature |
| ExpandableReviewRow | ProBadge on Edit + Reply + Featured | 3 badges per review card |
| ListItemDisplay | ProBadge on Custom Text button | Badge fades with disabled button |
| FeaturedReview | GatedFeature wrapper | Inconsistent with other patterns |

## Design Goals

1. **Single Indicator Per Feature Area** - One clear Pro indicator per feature group, not per action
2. **Clear Communication** - Obvious what's locked and why, with grouped features
3. **Prominent Badges** - Pro indicators remain visible even when parent content is dimmed
4. **Unified Feature List** - Consolidated view of all Pro features in settings
5. **Premium Aesthetic** - Maintain dark/gold luxury feel (#1a1a1a background, #d4af37 gold)

## Solution Architecture

### New Component: ProZone

A container component that wraps related gated features with a single indicator.

**Key Characteristics:**
- Single floating label positioned outside dimmed content
- Subtle gold "breathing" glow animation on container border
- Content inside is dimmed (50% opacity, grayscale filter, pointer-events disabled)
- Label uses `isolation: isolate` to prevent opacity inheritance
- Clicking label navigates to upgrade URL

**Props:**
```typescript
interface ProZoneProps {
  feature: GatedFeature | GatedFeature[]  // Feature(s) being gated
  featureLabel?: string                    // Display label (default: "Pro")
  children: React.ReactNode                // Gated content
  className?: string
}
```

**Visual Design:**
- Container: 12px border-radius, subtle gold glow animation (4s breathing cycle)
- Label: Positioned top-right, -10px offset, 20px border-radius pill shape
- Label styling: Dark gradient background, gold border, crown icon + text + arrow
- Content: 50% opacity, 30% grayscale, pointer-events: none when locked

### New Component: ProFeaturesSummary

A comprehensive panel showing all Pro features in one elegant display.

**Use Cases:**
- Settings page "Your Plan" section
- Dismissible banner in admin notices
- Modal triggered from any Pro indicator

**Content Structure:**
```typescript
const PRO_FEATURES = [
  {
    id: 'premium_themes',
    icon: 'palette',
    name: 'Premium Themes',
    description: 'Editorial & Modern themes for distinctive card styling',
    features: [GATED_FEATURES.THEME_EDITORIAL, GATED_FEATURES.THEME_MODERN]
  },
  {
    id: 'review_management',
    icon: 'star',
    name: 'Review Management',
    description: 'Edit, reply to, and feature user reviews',
    features: [GATED_FEATURES.REVIEW_EDIT, GATED_FEATURES.REVIEW_RESPOND, GATED_FEATURES.REVIEW_FEATURED]
  },
  {
    id: 'servings',
    icon: 'utensils',
    name: 'Servings Adjustment',
    description: 'Let readers scale ingredient quantities',
    features: [GATED_FEATURES.SERVINGS_ADJUSTMENT]
  },
  {
    id: 'custom_items',
    icon: 'text',
    name: 'Custom List Items',
    description: 'Add text-only items to any list',
    features: [GATED_FEATURES.TEXT_ONLY_LIST_ITEM]
  }
]
```

**Visual Design:**
- Dark gradient container with gold top border accent
- 2-column grid of feature cards
- Each card: icon (gold tint) + name + description
- Single prominent CTA button at bottom with shimmer animation on hover

### Updated Component: ProBadge

Simplified for rare inline use cases where ProZone isn't appropriate.

**Changes:**
- Add `isolation: isolate` to prevent opacity inheritance
- Add `opacity: 1 !important` as fallback
- Remove tooltip/hover upgrade prompt (ProZone handles this now)
- Simplify to just crown icon + "Pro" text
- Keep only 'xs' and 'sm' sizes

## Implementation Plan

### Phase 1: Core Components

1. **Create ProZone component** (`admin/ui/src/components/ProZone.tsx`)
   - Container with breathing gold glow animation
   - Floating label with crown icon
   - Content wrapper with dimming styles
   - Click handler for upgrade navigation

2. **Create ProFeaturesSummary component** (`admin/ui/src/components/ProFeaturesSummary.tsx`)
   - Feature grid with icons and descriptions
   - Upgrade CTA button
   - Responsive layout (2 columns → 1 on mobile)

3. **Update ProBadge component** (`admin/ui/src/components/ProBadge.tsx`)
   - Add isolation and opacity fixes
   - Remove tooltip functionality
   - Simplify props and styling

### Phase 2: ThemeSelector Refactor

**File:** `admin/ui/src/views/Settings/components/ThemeSelector.tsx`

**Changes:**
1. Remove: ProBadge from theme name
2. Remove: LockIcon in theme card header
3. Remove: UpgradeCTA button below description
4. Remove: LockedOverlay on preview image
5. Add: Wrap gated themes in single ProZone

**Before:**
```tsx
<ThemeCard>
  <ThemeName>
    Editorial <ProBadge />  {/* Remove */}
  </ThemeName>
  <LockIcon />              {/* Remove */}
  <ThemeDescription />
  <UpgradeCTA />            {/* Remove */}
</ThemeCard>
<PreviewPanel>
  <LockedOverlay />         {/* Remove */}
  <ThemePreview />
</PreviewPanel>
```

**After:**
```tsx
<ProZone feature={[GATED_FEATURES.THEME_EDITORIAL, GATED_FEATURES.THEME_MODERN]} featureLabel="Pro Themes">
  <ThemeCard theme="editorial">
    <ThemeName>Editorial</ThemeName>
    <ThemeDescription />
  </ThemeCard>
  <ThemeCard theme="modern">
    <ThemeName>Modern</ThemeName>
    <ThemeDescription />
  </ThemeCard>
</ProZone>
```

### Phase 3: User Reviews Refactor

**File:** `admin/ui/src/views/Collections/components/ExpandableReviewRow.tsx`

**Changes:**
1. Remove: ProBadge from Featured toggle label
2. Remove: ProBadge from Reply button
3. Remove: ProBadge from Edit button
4. Remove: Individual disabled states with inline opacity
5. Add: Wrap all review actions in single ProZone

**Before:**
```tsx
<ReviewActions>
  <FeaturedToggle disabled={!canFeature}>
    Featured <ProBadge />   {/* Remove */}
  </FeaturedToggle>
  <ReplyButton disabled={!canReply}>
    Reply <ProBadge />      {/* Remove */}
  </ReplyButton>
  <EditButton disabled={!canEdit}>
    Edit <ProBadge />       {/* Remove */}
  </EditButton>
  <DeleteButton />          {/* Always enabled - not gated */}
</ReviewActions>
```

**After:**
```tsx
<ReviewActions>
  <ProZone feature="review_management" featureLabel="Pro">
    <FeaturedToggle>Featured</FeaturedToggle>
    <ReplyButton>Reply</ReplyButton>
    <EditButton>Edit</EditButton>
  </ProZone>
  <DeleteButton />  {/* Outside ProZone - always available */}
</ReviewActions>
```

**Note:** Delete functionality should NOT be gated - it remains outside the ProZone.

### Phase 4: Settings Integration

**File:** `admin/ui/src/views/Settings/Settings.tsx` (or appropriate settings view)

**Changes:**
1. Add new "Your Plan" section to Display tab
2. Show ProFeaturesSummary for free users
3. Show current plan status for Pro users

```tsx
<SettingsSection title="Your Plan">
  {isProOrHigher ? (
    <CurrentPlanDisplay>
      <ProBadge size="sm" />
      <span>You have access to all Pro features</span>
    </CurrentPlanDisplay>
  ) : (
    <ProFeaturesSummary upgradeUrl={upgradeUrl} />
  )}
</SettingsSection>
```

### Phase 5: Other Gated Features

**ListItemDisplay** (`admin/ui/src/views/Editor/components/ListItemDisplay.tsx`):
- Wrap "+ Custom Text" button in ProZone

**FeaturedReview** (`admin/ui/src/views/Editor/components/FeaturedReview.tsx`):
- Replace GatedFeature wrapper with ProZone for consistency

## Visual Specifications

### Color Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--pro-gold` | `#d4af37` | Primary accent, text, icons |
| `--pro-gold-light` | `#f0d060` | Hover states |
| `--pro-gold-dim` | `rgba(212, 175, 55, 0.3)` | Borders, glows |
| `--pro-dark` | `#1a1a1a` | Background start |
| `--pro-dark-light` | `#2d2d2d` | Background end |

### ProZone Styling

```css
/* Container */
.pro-zone {
  position: relative;
  border-radius: 12px;
  animation: pro-breathe 4s ease-in-out infinite;
}

@keyframes pro-breathe {
  0%, 100% {
    box-shadow: inset 0 0 0 1px rgba(212, 175, 55, 0.3),
                0 0 20px rgba(212, 175, 55, 0.05);
  }
  50% {
    box-shadow: inset 0 0 0 1px rgba(212, 175, 55, 0.5),
                0 0 30px rgba(212, 175, 55, 0.12);
  }
}

/* Label */
.pro-zone-label {
  position: absolute;
  top: -10px;
  right: 16px;
  z-index: 10;
  isolation: isolate;

  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;

  background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
  border: 1px solid rgba(212, 175, 55, 0.4);
  border-radius: 20px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);

  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  color: #d4af37;
  cursor: pointer;
}

/* Dimmed Content */
.pro-zone-content--locked {
  opacity: 0.5;
  pointer-events: none;
  filter: grayscale(30%);
}
```

### ProFeaturesSummary Styling

```css
.pro-features-summary {
  background: linear-gradient(145deg, #0d0d0d 0%, #1a1a1a 50%, #0d0d0d 100%);
  border: 1px solid rgba(212, 175, 55, 0.2);
  border-radius: 16px;
  padding: 32px;
}

.pro-features-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.pro-upgrade-button {
  background: linear-gradient(135deg, #d4af37 0%, #c9a432 50%, #d4af37 100%);
  background-size: 200% 100%;
  color: #1a1a1a;
  font-weight: 600;
}

.pro-upgrade-button:hover {
  animation: shimmer 1.5s ease infinite;
}

@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
```

## Migration Checklist

- [ ] Create `ProZone` component
- [ ] Create `ProFeaturesSummary` component
- [ ] Update `ProBadge` with isolation fix
- [ ] Refactor `ThemeSelector` (remove 4 indicators, add 1 ProZone)
- [ ] Refactor `ExpandableReviewRow` (remove 3 badges, add 1 ProZone)
- [ ] Update `ListItemDisplay` (wrap in ProZone)
- [ ] Update `FeaturedReview` (replace GatedFeature with ProZone)
- [ ] Add "Your Plan" section to Settings
- [ ] Remove unused `GatedFeature` modes if no longer needed
- [ ] Update `useGateKeeper` hook if needed for grouped feature checks
- [ ] Test all gated features in free tier
- [ ] Test all features work correctly in Pro tier
- [ ] Visual QA for consistent styling

## Success Metrics

1. **Reduced Visual Clutter** - Max 1 Pro indicator per feature area
2. **Clear Communication** - Users understand what's locked at a glance
3. **Prominent Badges** - Pro indicators visible at 100% opacity regardless of parent
4. **Unified Experience** - Consistent pattern across all gated features
5. **Conversion Path** - Single click from any indicator to upgrade page

## Files to Modify

| File | Action |
|------|--------|
| `admin/ui/src/components/ProZone.tsx` | Create new |
| `admin/ui/src/components/ProFeaturesSummary.tsx` | Create new |
| `admin/ui/src/components/ProBadge.tsx` | Modify |
| `admin/ui/src/components/GatedFeature.tsx` | Possibly deprecate |
| `admin/ui/src/views/Settings/components/ThemeSelector.tsx` | Major refactor |
| `admin/ui/src/views/Collections/components/ExpandableReviewRow.tsx` | Major refactor |
| `admin/ui/src/views/Editor/components/ListItemDisplay.tsx` | Minor refactor |
| `admin/ui/src/views/Editor/components/FeaturedReview.tsx` | Minor refactor |
| `admin/ui/src/views/Settings/Settings.tsx` | Add plan section |
