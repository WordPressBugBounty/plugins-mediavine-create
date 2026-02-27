# Playwright E2E Test Migration Spec

> **Status:** Draft
> **Author:** [TBD]
> **Created:** 2026-01-07
> **Last Updated:** 2026-01-07

## Summary

Migrate from Cypress to Playwright for all end-to-end testing, with initial focus on client-side (frontend Preact) functionality. This includes creating database fixtures via WP-CLI, establishing a Playwright test infrastructure, and ultimately removing the existing Cypress setup.

---

## Motivation

**Why are we doing this?**
- **Single framework** - Maintain one E2E testing framework instead of two
- **Modern tooling** - Playwright offers better reliability, parallelization, and developer experience
- **Client coverage gap** - Current Cypress tests focus on admin; client-side interactivity is undertested
- **Critical user touchpoints** - Rating submissions, print views, and hands-free mode directly impact publisher audiences

**What problems does this solve?**
- No automated testing for client-side JavaScript interactions (reviews, ratings, hands-free)
- Cypress maintenance overhead and test flakiness
- Missing mobile viewport coverage

**What are the expected benefits?**
- Unified testing framework with modern APIs
- Better mobile viewport testing
- Faster test execution through parallelization
- Improved developer experience with Playwright's tooling (UI mode, trace viewer)

---

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Test Framework | Playwright | Replaces Cypress entirely |
| Test Location | `/e2e/` | Replace existing Cypress folder |
| Test Environment | Docker WordPress (php74) | Single PHP version for speed; PHP compat tested elsewhere |
| Test Data | WP-CLI seeding | Programmatic content creation for reproducibility |
| Initial Focus | Recipe cards | Most common card type; expand to How-To/List after |
| Review Testing | Full submission flow | Test complete form submission including API calls |
| Mobile Testing | Yes | Mobile viewports are priority |
| Visual Regression | No | Not in initial scope |
| Accessibility (a11y) | No | Not in initial scope |
| CI Integration | No | Manual test runs initially |
| Cypress Fate | Delete after migration | Clean removal once Playwright is working |

---

## Scope

### Phase 1: Client Tests (Priority)
Testing the frontend Preact application that renders cards on publisher sites:
- Star ratings and review modal flow
- Review form submission (full API flow)
- Print button functionality
- Hands-free mode toggle
- Index search and pagination
- Recipe card rendering

### Phase 2: Admin Tests (Migration)
Migrate existing Cypress admin tests to Playwright:
- Recipe/How-To/List card creation (block editor and classic)
- Settings validation
- User reviews admin interface
- Products validation
- Schema validation

### Phase 3: Cleanup
- Remove `/e2e/cypress/` directory
- Remove Cypress dependencies from package.json
- Update documentation

---

## Components to Test

### Client Components

#### 1. Star Ratings & Reviews (Full Flow)
- Star hover states and click selection (1-5 stars)
- Modal opens on rating below auto-submit threshold
- Auto-submission for 5-star ratings
- Review form fields: name, email, review text
- Form validation (required fields, email format)
- Form submission to REST API
- Success state display
- Error state handling
- Modal close methods (X button, ESC key, click outside)
- Body scroll lock when modal open
- Previously submitted review restoration from localStorage

#### 2. Hands-Free Mode
- Toggle on/off functionality
- Wake lock API activation (where supported)
- Multiple cards on same page sync toggle states
- Correct state text display ("On"/"Off")
- Setting respects `wakeLockEnabled` configuration

#### 3. Print Button
- Click opens new window with print URL
- Correct URL constructed from `data-mv-print` attribute

#### 4. Index Search
- Search input accepts text
- Debounced API calls (350ms delay)
- Results update dynamically in page
- Reset button clears search and restores original content
- "Nothing found" state displays correctly
- Total items count updates

#### 5. Pagination
- Page number buttons navigate correctly
- Previous/Next buttons appear/hide conditionally
- Current page button is highlighted and non-clickable
- Ellipsis appears for large page counts (>5 pages)
- Scroll to top on page change

#### 6. Recipe Card Rendering
- Hero image displays
- Title and description render
- Ingredients section with proper grouping
- Instructions section with step numbers
- Nutrition facts display (when present)
- Notes section (when present)
- Print button visible
- Rating stars visible (when reviews enabled)

### Admin Components (Migration)

#### Existing Cypress Test Coverage to Migrate
| Test File | Functionality |
|-----------|--------------|
| `recipeBlock.spec.js` | Recipe creation in block editor |
| `recipeClassic.spec.js` | Recipe creation in classic editor |
| `recipeStandalone.spec.js` | Standalone recipe creation |
| `DIYblock.spec.js` | How-To in block editor |
| `DIYclassic.spec.js` | How-To in classic editor |
| `DIYstandalone.spec.js` | Standalone How-To creation |
| `listBlock.spec.js` | List in block editor |
| `listClassic.spec.js` | List in classic editor |
| `listStandalone.spec.js` | Standalone list creation |
| `userReviews.spec.js` | Admin reviews interface |
| `recProducts.spec.js` | Products management |
| `settings.spec.js` | Settings pages |
| `schema.spec.js` | JSON-LD schema validation |
| `frontend.spec.js` | Frontend UI validation |

---

## Technical Design

### Directory Structure

```
e2e/
├── playwright.config.ts
├── package.json
├── tsconfig.json
├── fixtures/
│   ├── seed-data.sh              # WP-CLI seeding script
│   ├── recipe-card.json          # Recipe card data
│   ├── howto-card.json           # How-To card data
│   └── list-card.json            # List card data
├── tests/
│   ├── client/
│   │   ├── reviews.spec.ts       # Star ratings & review modal
│   │   ├── hands-free.spec.ts    # Wake lock toggle
│   │   ├── print.spec.ts         # Print button
│   │   ├── index-search.spec.ts  # Search & pagination
│   │   └── recipe-card.spec.ts   # Recipe card rendering
│   └── admin/
│       ├── recipe/
│       │   ├── block.spec.ts
│       │   ├── classic.spec.ts
│       │   └── standalone.spec.ts
│       ├── howto/
│       │   ├── block.spec.ts
│       │   ├── classic.spec.ts
│       │   └── standalone.spec.ts
│       ├── list/
│       │   ├── block.spec.ts
│       │   ├── classic.spec.ts
│       │   └── standalone.spec.ts
│       ├── reviews.spec.ts
│       ├── products.spec.ts
│       ├── settings.spec.ts
│       └── schema.spec.ts
├── pages/
│   ├── card-page.ts              # Frontend card page object
│   ├── editor-page.ts            # Block editor page object
│   ├── classic-editor-page.ts    # Classic editor page object
│   └── admin-page.ts             # WP admin page object
├── helpers/
│   ├── auth.ts                   # WordPress login helpers
│   ├── api.ts                    # REST API helpers
│   └── selectors.ts              # Shared selectors
└── global-setup.ts               # Test environment setup
```

### Playwright Configuration

```typescript
// playwright.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30000,
  expect: {
    timeout: 5000,
  },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['html', { open: 'never' }], ['list']],

  use: {
    baseURL: 'http://localhost:8074',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
  },

  projects: [
    // Desktop
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    // Mobile
    {
      name: 'mobile-chrome',
      use: { ...devices['Pixel 5'] },
    },
    {
      name: 'mobile-safari',
      use: { ...devices['iPhone 12'] },
    },
  ],

  // Run seed script before all tests
  globalSetup: require.resolve('./global-setup'),
});
```

### WP-CLI Seed Data Script

```bash
#!/bin/bash
# fixtures/seed-data.sh
# Creates test content via WP-CLI in Docker container

CONTAINER="wordpress-php74"
WP="docker-compose exec -T $CONTAINER wp"

echo "Seeding test data..."

# Create test user for reviews
$WP user create testreviewer test@example.com --role=subscriber --user_pass=testpass 2>/dev/null || true

# Create recipe card
RECIPE_ID=$($WP post create \
  --post_type=mv_create \
  --post_title="Test Recipe Card" \
  --post_status=publish \
  --porcelain)

$WP post meta update $RECIPE_ID mv_create_type recipe
$WP post meta update $RECIPE_ID mv_create_title "Chocolate Chip Cookies"
$WP post meta update $RECIPE_ID mv_create_description "Classic homemade chocolate chip cookies"
$WP post meta update $RECIPE_ID mv_create_ingredients '[{"content":"2 cups flour"},{"content":"1 cup sugar"},{"content":"1 cup chocolate chips"}]'
$WP post meta update $RECIPE_ID mv_create_instructions '[{"content":"Mix dry ingredients"},{"content":"Add wet ingredients"},{"content":"Bake at 350F for 12 minutes"}]'
$WP post meta update $RECIPE_ID mv_create_yield "24 cookies"
$WP post meta update $RECIPE_ID mv_create_prep_time "15 minutes"
$WP post meta update $RECIPE_ID mv_create_cook_time "12 minutes"

# Create post with embedded recipe
POST_ID=$($WP post create \
  --post_type=post \
  --post_title="Test Recipe Post" \
  --post_status=publish \
  --post_content="<!-- wp:mv/recipe {\"id\":$RECIPE_ID} --><div class=\"wp-block-mv-recipe\">[mv_create key=\"$RECIPE_ID\" type=\"recipe\"]</div><!-- /wp:mv/recipe -->" \
  --porcelain)

echo "Created recipe card $RECIPE_ID in post $POST_ID"

# Create How-To card
HOWTO_ID=$($WP post create \
  --post_type=mv_create \
  --post_title="Test How-To Card" \
  --post_status=publish \
  --porcelain)

$WP post meta update $HOWTO_ID mv_create_type diy
$WP post meta update $HOWTO_ID mv_create_title "How to Change a Tire"
$WP post meta update $HOWTO_ID mv_create_instructions '[{"content":"Loosen lug nuts"},{"content":"Jack up the car"},{"content":"Remove flat tire"},{"content":"Install spare"}]'

# Create post with embedded How-To
HOWTO_POST_ID=$($WP post create \
  --post_type=post \
  --post_title="Test How-To Post" \
  --post_status=publish \
  --post_content="<!-- wp:mv/diy {\"id\":$HOWTO_ID} --><div class=\"wp-block-mv-diy\">[mv_create key=\"$HOWTO_ID\" type=\"diy\"]</div><!-- /wp:mv/diy -->" \
  --porcelain)

echo "Created how-to card $HOWTO_ID in post $HOWTO_POST_ID"

# Create List card with many items (for pagination testing)
LIST_ID=$($WP post create \
  --post_type=mv_create \
  --post_title="Test List Card" \
  --post_status=publish \
  --porcelain)

$WP post meta update $LIST_ID mv_create_type list
$WP post meta update $LIST_ID mv_create_title "Top 20 Kitchen Tools"

# Create index post for search/pagination testing
INDEX_POST_ID=$($WP post create \
  --post_type=post \
  --post_title="Recipe Index" \
  --post_status=publish \
  --post_content="[mv_create_index]" \
  --porcelain)

echo "Created list card $LIST_ID and index post $INDEX_POST_ID"

# Enable relevant settings
$WP option update mv_create_settings '{"reviews_enabled":true,"wakeLockEnabled":true}' --format=json

echo "Test data seeding complete!"
```

### Global Setup

```typescript
// global-setup.ts
import { execSync } from 'child_process';
import { FullConfig } from '@playwright/test';

async function globalSetup(config: FullConfig) {
  console.log('Running WP-CLI seed script...');

  try {
    // Run the seed script
    execSync('bash fixtures/seed-data.sh', {
      cwd: __dirname,
      stdio: 'inherit',
    });
  } catch (error) {
    console.error('Failed to seed test data:', error);
    throw error;
  }
}

export default globalSetup;
```

### Page Object Models

```typescript
// pages/card-page.ts
import { Page, Locator, expect } from '@playwright/test';

export class CardPage {
  readonly page: Page;
  readonly cardWrapper: Locator;
  readonly starRatings: Locator;
  readonly reviewModal: Locator;
  readonly printButton: Locator;
  readonly handsFreeToggle: Locator;

  constructor(page: Page) {
    this.page = page;
    this.cardWrapper = page.locator('.mv-create-wrapper');
    this.starRatings = page.locator('.mv-reviews-stars');
    this.reviewModal = page.locator('.mv-modal');
    this.printButton = page.locator('[data-mv-print]');
    this.handsFreeToggle = page.locator('.handsfree-toggle-button');
  }

  async goto(slug: string) {
    await this.page.goto(`/${slug}/`);
    await expect(this.cardWrapper).toBeVisible();
  }

  async clickStar(rating: 1 | 2 | 3 | 4 | 5) {
    await this.page.click(`[data-name="Clickable ${rating} Star${rating > 1 ? 's' : ''}"]`);
  }

  async expectModalOpen() {
    await expect(this.reviewModal).toBeVisible();
  }

  async expectModalClosed() {
    await expect(this.reviewModal).not.toBeVisible();
  }

  async closeModalWithEsc() {
    await this.page.keyboard.press('Escape');
  }

  async closeModalWithX() {
    await this.page.click('.mv-modal-close');
  }

  async closeModalWithOutsideClick() {
    await this.page.click('.mv-modal', { position: { x: 10, y: 10 } });
  }

  async submitReview(data: { rating?: number; name?: string; email?: string; review?: string }) {
    if (data.rating) {
      await this.clickStar(data.rating as 1 | 2 | 3 | 4 | 5);
    }
    if (data.name) {
      await this.reviewModal.locator('input[name="name"]').fill(data.name);
    }
    if (data.email) {
      await this.reviewModal.locator('input[name="email"]').fill(data.email);
    }
    if (data.review) {
      await this.reviewModal.locator('textarea[name="review"]').fill(data.review);
    }
    await this.reviewModal.locator('button[type="submit"]').click();
  }

  async toggleHandsFree() {
    await this.handsFreeToggle.click();
  }

  async getHandsFreeState(): Promise<boolean> {
    const checkbox = this.page.locator('.hands-free-checkbox');
    return await checkbox.isChecked();
  }
}
```

```typescript
// pages/admin-page.ts
import { Page, expect } from '@playwright/test';

export class AdminPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async login(username = 'admin', password = 'password') {
    await this.page.goto('/wp-login.php');
    await this.page.fill('#user_login', username);
    await this.page.fill('#user_pass', password);
    await this.page.click('#wp-submit');
    await expect(this.page).toHaveURL(/wp-admin/);
  }

  async navigateToCreate() {
    await this.page.click('#menu-posts-mv_create');
  }

  async navigateToCreateSettings() {
    await this.navigateToCreate();
    await this.page.click('text=Settings');
  }

  async navigateToUserReviews() {
    await this.navigateToCreate();
    await this.page.click('text=User Reviews');
  }
}
```

### Example Test Files

```typescript
// tests/client/reviews.spec.ts
import { test, expect } from '@playwright/test';
import { CardPage } from '../../pages/card-page';

test.describe('Star Ratings & Reviews', () => {
  let cardPage: CardPage;

  test.beforeEach(async ({ page }) => {
    cardPage = new CardPage(page);
    await cardPage.goto('test-recipe-post');
  });

  test('star ratings are visible on recipe card', async () => {
    await expect(cardPage.starRatings).toBeVisible();
  });

  test('clicking a star opens review modal', async ({ page }) => {
    await cardPage.clickStar(4);
    await cardPage.expectModalOpen();
  });

  test('5-star rating auto-submits without modal', async ({ page }) => {
    // Intercept the API call
    const apiPromise = page.waitForResponse(
      (resp) => resp.url().includes('/mv-create/v1/reviews') && resp.status() === 200
    );

    await cardPage.clickStar(5);

    // Should submit directly
    await apiPromise;

    // Modal should show success/thank you state, not full form
    await expect(page.locator('text=Thank you')).toBeVisible();
  });

  test('modal closes on ESC key', async () => {
    await cardPage.clickStar(3);
    await cardPage.expectModalOpen();
    await cardPage.closeModalWithEsc();
    await cardPage.expectModalClosed();
  });

  test('modal closes on X button click', async () => {
    await cardPage.clickStar(3);
    await cardPage.expectModalOpen();
    await cardPage.closeModalWithX();
    await cardPage.expectModalClosed();
  });

  test('modal closes on outside click', async () => {
    await cardPage.clickStar(3);
    await cardPage.expectModalOpen();
    await cardPage.closeModalWithOutsideClick();
    await cardPage.expectModalClosed();
  });

  test('full review submission flow', async ({ page }) => {
    // Intercept API
    const apiPromise = page.waitForResponse(
      (resp) => resp.url().includes('/mv-create/v1/reviews') && resp.status() === 200
    );

    await cardPage.clickStar(4);
    await cardPage.expectModalOpen();

    await cardPage.submitReview({
      name: 'Test User',
      email: 'test@example.com',
      review: 'This recipe was great!',
    });

    await apiPromise;

    // Verify success state
    await expect(page.locator('text=Thank you')).toBeVisible();
  });

  test('form validation shows errors for invalid email', async ({ page }) => {
    await cardPage.clickStar(3);
    await cardPage.expectModalOpen();

    await cardPage.submitReview({
      name: 'Test User',
      email: 'invalid-email',
      review: 'Test review',
    });

    // Should show validation error
    await expect(page.locator('text=valid email')).toBeVisible();
  });
});
```

```typescript
// tests/client/hands-free.spec.ts
import { test, expect } from '@playwright/test';
import { CardPage } from '../../pages/card-page';

test.describe('Hands-Free Mode', () => {
  test.beforeEach(async ({ page }) => {
    const cardPage = new CardPage(page);
    await cardPage.goto('test-recipe-post');
  });

  test('hands-free toggle is visible when enabled', async ({ page }) => {
    await expect(page.locator('.handsfree-wrap')).toBeVisible();
  });

  test('toggle changes state correctly', async ({ page }) => {
    const cardPage = new CardPage(page);

    const initialState = await cardPage.getHandsFreeState();
    await cardPage.toggleHandsFree();
    const newState = await cardPage.getHandsFreeState();

    expect(newState).not.toBe(initialState);
  });

  test('toggle shows correct text for state', async ({ page }) => {
    const onText = page.locator('.handsfree-enabled');
    const offText = page.locator('.handsfree-disabled');

    // Check initial state visibility
    const checkbox = page.locator('.hands-free-checkbox');
    if (await checkbox.isChecked()) {
      await expect(onText).toHaveClass(/true/);
    } else {
      await expect(offText).toHaveClass(/true/);
    }
  });
});
```

```typescript
// tests/client/print.spec.ts
import { test, expect } from '@playwright/test';
import { CardPage } from '../../pages/card-page';

test.describe('Print Button', () => {
  test('print button opens new window with correct URL', async ({ page, context }) => {
    const cardPage = new CardPage(page);
    await cardPage.goto('test-recipe-post');

    // Listen for new page (popup)
    const pagePromise = context.waitForEvent('page');

    await cardPage.printButton.click();

    const newPage = await pagePromise;
    await newPage.waitForLoadState();

    // Verify print URL format
    expect(newPage.url()).toContain('/mv_create_print/');
  });
});
```

```typescript
// tests/client/recipe-card.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Recipe Card Rendering', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/test-recipe-post/');
  });

  test('card wrapper is visible', async ({ page }) => {
    await expect(page.locator('.mv-create-wrapper')).toBeVisible();
  });

  test('title displays correctly', async ({ page }) => {
    await expect(page.locator('.mv-create-title')).toContainText('Chocolate Chip Cookies');
  });

  test('ingredients section is visible', async ({ page }) => {
    await expect(page.locator('.mv-create-ingredients')).toBeVisible();
  });

  test('instructions section is visible', async ({ page }) => {
    await expect(page.locator('.mv-create-instructions')).toBeVisible();
  });

  test('print button is visible', async ({ page }) => {
    await expect(page.locator('[data-mv-print]')).toBeVisible();
  });
});
```

```typescript
// tests/client/recipe-card.spec.ts (mobile viewport tests)
import { test, expect } from '@playwright/test';

test.describe('Recipe Card - Mobile', () => {
  test.use({ viewport: { width: 375, height: 667 } });

  test('card renders correctly on mobile', async ({ page }) => {
    await page.goto('/test-recipe-post/');
    await expect(page.locator('.mv-create-wrapper')).toBeVisible();
  });

  test('ingredients are readable on small screen', async ({ page }) => {
    await page.goto('/test-recipe-post/');
    const ingredients = page.locator('.mv-create-ingredients');
    await expect(ingredients).toBeVisible();

    // Check that text isn't clipped
    const box = await ingredients.boundingBox();
    expect(box?.width).toBeGreaterThan(300);
  });
});
```

### Package.json

```json
{
  "name": "create-e2e-tests",
  "version": "1.0.0",
  "scripts": {
    "test": "playwright test",
    "test:ui": "playwright test --ui",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug",
    "test:client": "playwright test tests/client/",
    "test:admin": "playwright test tests/admin/",
    "test:mobile": "playwright test --project=mobile-chrome --project=mobile-safari",
    "seed": "bash fixtures/seed-data.sh",
    "report": "playwright show-report"
  },
  "devDependencies": {
    "@playwright/test": "^1.40.0",
    "@types/node": "^20.0.0",
    "typescript": "^5.0.0"
  }
}
```

---

## Feature Requirements

### Must Have (P0)
- [ ] Playwright configuration and setup
- [ ] WP-CLI seed data script with recipe card
- [ ] Star rating interaction tests
- [ ] Review modal open/close tests
- [ ] Full review form submission tests (with API)
- [ ] Print button functionality tests
- [ ] Recipe card rendering verification
- [ ] Mobile viewport tests

### Should Have (P1)
- [ ] Hands-free mode toggle tests
- [ ] Index search functionality tests
- [ ] Pagination tests
- [ ] Page object models for reusability
- [ ] Admin login helper
- [ ] How-To and List card test data

### Nice to Have (P2)
- [ ] Migrate all existing Cypress admin tests
- [ ] Multiple cards on same page tests
- [ ] Error state handling tests
- [ ] localStorage persistence tests

### Cleanup Phase
- [ ] Remove `/e2e/cypress/` directory
- [ ] Remove Cypress from root package.json
- [ ] Update CLAUDE.md documentation
- [ ] Update README.md

---

## Testing Plan

### Test Data Requirements

| Content | Purpose | Created By |
|---------|---------|------------|
| Recipe card | Primary testing target | WP-CLI seed script |
| Recipe post | Frontend recipe display | WP-CLI seed script |
| How-To card | Secondary card type | WP-CLI seed script |
| How-To post | Frontend how-to display | WP-CLI seed script |
| List card | Tertiary card type | WP-CLI seed script |
| Index post | Search/pagination tests | WP-CLI seed script |
| Test user | Review submission | WP-CLI seed script |

### Test Execution

```bash
# Start Docker environment
docker-compose up -d

# Wait for WordPress to be ready
./wait-for-wordpress.sh

# Run seed script (automatically via global setup, or manually)
cd e2e && yarn seed

# Run all tests
yarn test

# Run only client tests
yarn test:client

# Run with mobile viewports
yarn test:mobile

# Debug mode with UI
yarn test:ui
```

### Browser Coverage

| Feature | Chrome | Mobile Chrome | Mobile Safari |
|---------|--------|---------------|---------------|
| Star Ratings | Yes | Yes | Yes |
| Review Modal | Yes | Yes | Yes |
| Hands-Free | Yes | Yes | Limited* |
| Print Button | Yes | Yes | Yes |
| Card Rendering | Yes | Yes | Yes |

*Wake Lock API has limited mobile browser support

---

## Rollout Plan

### Phase 1: Infrastructure Setup
- [ ] Create `/e2e/` directory structure
- [ ] Add Playwright dependencies
- [ ] Create `playwright.config.ts`
- [ ] Create WP-CLI seed data script
- [ ] Create global setup for seeding
- [ ] Test basic configuration works

### Phase 2: Client Tests (Priority)
- [ ] Create page object models
- [ ] Implement star rating tests
- [ ] Implement review modal tests
- [ ] Implement print button tests
- [ ] Implement hands-free tests
- [ ] Implement card rendering tests
- [ ] Add mobile viewport tests

### Phase 3: Admin Test Migration
- [ ] Create admin page objects
- [ ] Migrate recipe validation tests
- [ ] Migrate how-to validation tests
- [ ] Migrate list validation tests
- [ ] Migrate settings tests
- [ ] Migrate user reviews admin tests
- [ ] Migrate products tests
- [ ] Migrate schema tests

### Phase 4: Cleanup
- [ ] Verify all Cypress tests have Playwright equivalents
- [ ] Remove `/e2e/cypress/` directory
- [ ] Remove Cypress from dependencies
- [ ] Update documentation

---

## Migration Mapping

### Cypress to Playwright Equivalents

| Cypress | Playwright |
|---------|------------|
| `cy.visit()` | `page.goto()` |
| `cy.get()` | `page.locator()` |
| `cy.contains()` | `page.locator('text=...')` |
| `cy.click()` | `locator.click()` |
| `cy.type()` | `locator.fill()` |
| `cy.should('exist')` | `expect(locator).toBeVisible()` |
| `cy.should('have.value')` | `expect(locator).toHaveValue()` |
| `cy.intercept()` | `page.route()` |
| `cy.wait()` | `page.waitForResponse()` |
| Custom `cy.login()` | `AdminPage.login()` |

### Existing Cypress Tests Reference

```
e2e/cypress/integration/
├── wp54/
├── wp57/
└── wpLatest/
    ├── diyValidation/
    │   ├── DIYblock.spec.js
    │   ├── DIYclassic.spec.js
    │   └── DIYstandalone.spec.js
    ├── frontendValidation/
    │   └── frontend.spec.js
    ├── listValidation/
    │   ├── listBlock.spec.js
    │   ├── listClassic.spec.js
    │   └── listStandalone.spec.js
    ├── productsValidation/
    │   └── recProducts.spec.js
    ├── recipeValidation/
    │   ├── recipeBlock.spec.js
    │   ├── recipeClassic.spec.js
    │   └── recipeStandalone.spec.js
    ├── reviewValidation/
    │   └── userReviews.spec.js
    ├── schemaValidation/
    │   └── schema.spec.js
    └── settingsValidation/
        └── settings.spec.js
```

Note: Only `wpLatest` tests will be migrated. The `wp54` and `wp57` duplicates exist for multi-version testing which is no longer needed (single php74 environment).

---

## Appendix

### Client Components Reference

| Component | File | Purpose |
|-----------|------|---------|
| HandsFree | `client/src/HandsFree/index.js` | Wake lock toggle |
| IndexSearch | `client/src/IndexSearch/index.js` | Card search with API |
| Pagination | `client/src/Pagination/index.js` | Page navigation |
| PrintButton | `client/src/PrintButton/index.js` | Opens print window |
| Reviews/Ratings | `client/src/Reviews/Ratings/Base.js` | Star rating UI & form |
| Reviews/Modal | `client/src/Reviews/Modal/index.js` | Review form modal |
| Reviews/Stars | `client/src/Reviews/Stars/index.js` | Star SVG component |

### Useful Playwright Commands

```bash
# Install browsers
npx playwright install

# Run specific test file
yarn test tests/client/reviews.spec.ts

# Run with specific browser
yarn test --project=chromium

# Generate test code (record interactions)
npx playwright codegen http://localhost:8074

# View test report
yarn report

# Update snapshots (if using visual testing later)
yarn test --update-snapshots
```

### Related Documents
- [Admin UI CLAUDE.md](../../admin/ui/CLAUDE.md)
- [Client README](../../client/README.md)
- [Docker Environment](../../Sites/wordpress/CLAUDE.md)
