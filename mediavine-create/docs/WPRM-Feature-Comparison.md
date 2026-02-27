# WP Recipe Maker vs Mediavine Create: Feature Gap Analysis

This document compares the feature sets of WP Recipe Maker (WPRM) and Mediavine Create, identifying feature gaps and prioritizing them by significance for competitive positioning.

---

## Executive Summary

| Metric | WPRM | Create |
|--------|------|--------|
| **Pricing Tiers** | Free, Premium ($49), Pro ($99), Elite ($149) | Free, Pro (subscription via Create Studio) |
| **Card Types** | Recipe, How-To | Recipe, How-To, List |
| **Gutenberg Blocks** | 9 blocks | 4 blocks |
| **Import Sources** | 20+ plugins | 13 plugins |
| **Unique Strength** | User engagement features | Ad network integration, List cards |

**Key Takeaway**: WPRM has significantly more user-facing interactive features (collections, shopping lists, timers), while Create has stronger publisher monetization features (affiliate products, Mediavine ad integration, List cards for roundups).

---

## Feature Gap Priority List

### 🔴 Critical Gaps (High User Impact, Competitive Disadvantage)

#### 1. Unit Conversion (Metric ↔ Imperial)
**WPRM**: Pro tier ($99/year) - Automatic conversion between metric and imperial units, temperature conversion
**Create**: ❌ Not available

**Impact**: International audiences expect unit conversion. Publishers with global readership lose engagement without this feature. This is a frequently requested feature across all recipe plugins.

**Recommendation**: High priority for Pro tier

---

#### 2. Kitchen Timer
**WPRM**: Premium tier ($49/year) - Mobile-friendly cooking timer integrated with instructions
**Create**: ❌ Not available

**Impact**: Keeps users on the page longer, improves engagement metrics, practical cooking utility. Timers attached to individual instruction steps provide better UX.

**Recommendation**: High priority for Pro tier

---

#### 3. Recipe Collections / Favorites (User Accounts)
**WPRM**: Elite tier ($149/year) - Visitors create personal recipe collections, save favorites, meal planning support
**Create**: ❌ Not available

**Impact**: Major engagement driver. Allows publishers to build community, increase return visits, and enable user accounts. Creates sticky user base.

**Recommendation**: High priority for future roadmap (significant development effort)

---

#### 4. Shopping List Generation
**WPRM**: Elite tier ($149/year) - Generate grocery lists from saved recipes
**Create**: ❌ Not available

**Impact**: Natural companion to collections. High practical value for users. Drives engagement and return visits.

**Recommendation**: Bundle with Collections feature

---

#### 5. Checkboxes for Ingredients & Instructions
**WPRM**: Premium tier ($49/year) - Interactive checkboxes for tracking cooking progress
**Create**: ❌ Not available

**Impact**: Simple but highly requested UX feature. Helps users track progress while cooking. Low development effort, high perceived value.

**Recommendation**: Medium-high priority, could be free tier

---

### 🟡 Moderate Gaps (Competitive Features)

#### 6. Shoppable Ingredients (Instacart, Walmart, Chicory)
**WPRM**: Available integrations with major grocery platforms
**Create**: ❌ Not available (has Amazon affiliate links only)

**Impact**: Direct revenue opportunity for publishers. Increasingly expected by users. Differentiator for large food publishers.

**Recommendation**: Medium priority - evaluate partnership opportunities with Instacart/Chicory

---

#### 7. Associate Ingredients with Specific Instructions
**WPRM**: Premium tier - Link specific ingredients to specific instruction steps
**Create**: ❌ Not available (ingredients and instructions are separate sections)

**Impact**: Better UX for complex recipes. Helps users understand which ingredients are needed at each step.

**Recommendation**: Medium priority - enhances recipe comprehension

---

#### 8. User Recipe Submission Form
**WPRM**: Elite tier ($149/year) - Frontend form for user-submitted recipes
**Create**: ❌ Not available

**Impact**: Community building feature. Enables user-generated content. Could be niche for most food bloggers.

**Recommendation**: Lower priority - niche use case

---

#### 9. Temperature Shortcode with Oven Symbols
**WPRM**: Available - Special formatting for temperatures with oven icons
**Create**: ❌ Not available

**Impact**: Nice polish feature. Low effort to implement. Improves visual recipe presentation.

**Recommendation**: Medium-low priority

---

#### 10. Cook Mode Popup (Keep Screen Awake)
**WPRM**: Premium tier - Popup to prevent screen timeout while cooking
**Create**: ✅ Partially - "Hands-free mode" with screen wake lock exists

**Gap Analysis**: Create has this feature but may need better discoverability/UX. Verify if hands-free mode matches WPRM's cook mode functionality.

---

#### 11. QR Code on Print Pages
**WPRM**: Available - QR code linking back to online recipe on printed pages
**Create**: ❌ Not available

**Impact**: Drives traffic back from printed recipes. Nice-to-have feature.

**Recommendation**: Low-medium priority

---

#### 12. Guided Recipes Metadata (Google-specific)
**WPRM**: Available - Optimized structured data format Google prefers for voice assistants
**Create**: ❓ Verify current JSON-LD output matches Google Guided Recipes spec

**Impact**: SEO benefit. May affect voice assistant recipe features.

**Recommendation**: Audit current implementation

---

### 🟢 Minor Gaps (Nice-to-Have)

#### 13. Additional Share Buttons
**WPRM**: WhatsApp, Bluesky, Messenger, Tumblr, Mastodon, Text Share
**Create**: Pinterest, Facebook, Instagram

**Gap**: Missing WhatsApp (huge for mobile), Bluesky (growing platform)

**Recommendation**: Add WhatsApp as priority, others optional

---

#### 14. Elementor / Divi Native Blocks
**WPRM**: Elementor blocks, Divi 5 module
**Create**: Gutenberg + shortcodes only

**Impact**: Page builder users may prefer native blocks. Shortcodes work but less polished.

**Recommendation**: Low priority - shortcodes provide compatibility

---

#### 15. More Import Sources
**WPRM**: 20+ import sources including Paprika app, more legacy plugins
**Create**: 13 import sources

**Gap**: Paprika app import (popular recipe management app), some legacy plugins

**Recommendation**: Add Paprika import if requested

---

#### 16. Custom Recipe Taxonomies (Difficulty, Price Level)
**WPRM**: Pro tier - Custom taxonomies beyond course/cuisine
**Create**: Course, cuisine, category taxonomies

**Gap**: Difficulty levels, price levels as taxonomies

**Recommendation**: Low priority - can be added to existing taxonomy system

---

#### 17. Template Editor in Free Tier
**WPRM**: Full visual template editor in free version
**Create**: Theme selection only in free, limited customization

**Impact**: WPRM's free tier feels more generous for customization.

**Recommendation**: Evaluate if more free customization options would reduce friction

---

#### 18. Pills Layout Style for Meta
**WPRM**: Visual "pills" style for displaying recipe metadata
**Create**: ❌ Not available

**Impact**: Visual polish only

**Recommendation**: Very low priority

---

## Features Where Create Has Advantage

Create isn't just playing catch-up. These are areas where Create leads:

### ✅ List Cards (Roundup Posts)
Create has dedicated List card type with multiple layouts (Hero, Grid, Circles, Numbered). WPRM uses Roundup Item blocks but no dedicated card type.

### ✅ Product/Affiliate Management
Create has robust product system with Amazon PA API integration, product collections, per-item affiliate links. More comprehensive than WPRM's equipment links.

### ✅ Review Management System
Create (Pro) has advanced review moderation, featured reviews, review responses, expandable review management. More robust than WPRM's comment ratings.

### ✅ Featured Review Gutenberg Block
Unique Create Pro feature to display single featured review prominently.

### ✅ Mediavine Ad Integration
Deep integration with Mediavine ad network for publishers.

### ✅ Create Studio Ecosystem
Connected platform for license management, interactive features, future expansion.

### ✅ How-To Card Materials & Tools
More granular editing for DIY/How-To content with grouping, quantities, and notes.

---

## Recommended Roadmap

### Phase 1: Quick Wins (Low Effort, High Impact)
1. **Checkboxes for ingredients/instructions** - Simple toggle feature
2. **WhatsApp share button** - Popular mobile sharing
3. **Temperature shortcode** - Visual enhancement

### Phase 2: Competitive Parity (Medium Effort)
4. **Unit Conversion** - Pro tier feature
5. **Kitchen Timer** - Pro tier feature
6. **QR Code on print** - Nice enhancement

### Phase 3: Differentiation (High Effort)
7. **Recipe Collections/Favorites** - Major feature requiring user accounts
8. **Shopping List** - Companion to collections
9. **Shoppable Ingredients** (partnerships required)

---

## Appendix: Full Feature Comparison Matrix

| Feature | WPRM Free | WPRM Paid | Create Free | Create Pro |
|---------|-----------|-----------|-------------|------------|
| Recipe Cards | ✅ | ✅ | ✅ | ✅ |
| How-To Cards | ✅ | ✅ | ✅ | ✅ |
| List Cards | ❌ | ❌ | ✅ | ✅ |
| JSON-LD Schema | ✅ | ✅ | ✅ | ✅ |
| Gutenberg Blocks | 9 | 9 | 4 | 4+ |
| Adjustable Servings | ❌ | ✅ Premium | ❌ | ✅ |
| Unit Conversion | ❌ | ✅ Pro | ❌ | ❌ |
| Kitchen Timer | ❌ | ✅ Premium | ❌ | ❌ |
| Ingredient Checkboxes | ❌ | ✅ Premium | ❌ | ❌ |
| Nutrition Facts | Manual | API (Pro) | API (Free) | API |
| User Ratings | ❌ | ✅ Premium | ✅ | ✅ |
| Review Management | Basic | Basic | Basic | ✅ Advanced |
| Featured Reviews | ❌ | ❌ | ❌ | ✅ |
| Review Responses | ❌ | ❌ | ❌ | ✅ |
| Print Functionality | ✅ | ✅ | ✅ | ✅ |
| QR Code Print | ✅ | ✅ | ❌ | ❌ |
| Recipe Collections | ❌ | ✅ Elite | ❌ | ❌ |
| Shopping List | ❌ | ✅ Elite | ❌ | ❌ |
| User Submissions | ❌ | ✅ Elite | ❌ | ❌ |
| Product Links | ❌ | ✅ Premium | ✅ | ✅ |
| Amazon API | ❌ | ✅ | ❌ | ✅ |
| Instacart/Walmart | ❌ | ✅ | ❌ | ❌ |
| Template Editor | ✅ | ✅ | ❌ | ❌ |
| Theme Selection | ✅ | ✅ | ✅ | ✅ Premium Themes |
| Import Sources | 20+ | 20+ | 13 | 13 |
| Jump to Recipe | ✅ | ✅ | ❌ | ✅ |
| Cook Mode | ❌ | ✅ Premium | ✅ Hands-free | ✅ |
| Share Buttons | 10+ | 10+ | 3-4 | 3-4 |

---

*Last Updated: January 2026*
*Comparison based on WPRM v10.x and Create v2.0.x*
