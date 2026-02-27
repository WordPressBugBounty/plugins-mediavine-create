# Beta Feedback - Bugs

**Source:** Nicole Johnson (beta)
**Date:** January 6, 2026
**Updated:** January 7, 2026 (added acceptance criteria, test plans, quick win tags)

---

## Summary

| Done | Bug | Component | Quick Win | Test Type |
|------|-----|-----------|-----------|-----------|
|  | #1 Serving Size Blacked Out | Client | No | PHPUnit + Playwright |
|  | #2 Character Encoding | Client | Yes | PHPUnit |
| ✅ | #3 Reply Button Visible | Reviews | Yes | PHPUnit |
| ✅ | #4 No Delete Review | Reviews | No | PHPUnit |
| ✅ | #5 Studio Password Loop | Auth | No | Manual (both sides) |
| ✅ | #6 X Button Too Large | Client | Yes | Playwright |
| ✅ | #7 Needs Response Not Linked | Reviews | Yes | PHPUnit |
| ✅ | #8 Rating-Only Needs Response | Reviews | Yes | PHPUnit |
| ✅ | #9 Star Ratings Not Editable | Reviews | No | PHPUnit |
| ✅ | #10 Unknown Error Settings | Admin | No | Manual |
|  | #11 New Design Whitespace | Client | No | Design Review |
|  | #12 Ingredient Pluralization | Client | - | **DEFERRED** |
| ✅ | #13 Password Reset Email | Studio | - | **OUT OF SCOPE** |
| ✅ | #14 Studio API Error | API | No | PHPUnit |

---

## P0 - Critical

### 1. Serving Size Selector Blacked Out
**Component:** Client Card Rendering

Serving size adjustment controls appear "blacked out" when certain color combinations are selected for primary/secondary theme colors.

**Root Cause (suspected):** CSS color variables for `.mv-create-yield-*` elements don't ensure sufficient contrast between text/controls and background.

**Repro:**
1. Set primary and secondary colors to similar/conflicting values
2. View recipe card with yield selector
3. Observe controls are unreadable

**URL:** https://blackstonerecipesdaily.com/2026/01/02/blackstone-smash-breakfast-tacos/

#### Acceptance Criteria

**Given** a recipe card with yield selector enabled
**When** the user sets any combination of primary/secondary theme colors
**Then** the yield controls (buttons, text, dropdown) must always be readable with sufficient contrast

**Given** the yield selector foreground color
**When** it would clash with the background color
**Then** CSS should automatically ensure contrast (e.g., use contrasting text color based on background luminance)

#### Test Plan

**PHPUnit:** Add tests to verify CSS variable output includes contrast-safe values
- File: `tests/Integration/test-card-rendering.php` or new `test-yield-selector.php`
- Test: Color combinations produce valid contrast ratios

**Playwright:**
- Create recipe card with various color combinations
- Assert yield selector text is visible (check computed styles or screenshot comparison)
- Test edge cases: white on white, black on black, similar hues

#### Fix Approach
Use CSS to ensure foreground/background never clash. Options:
1. Use `color-contrast()` CSS function (limited browser support)
2. Calculate luminance in PHP and set appropriate text color
3. Use fixed high-contrast colors for yield controls regardless of theme

---

### 2. Interactive Mode Character Encoding **[Quick Win]**
**Component:** Client Interactive Mode

Apostrophes display as HTML entities (`&#x27;`) instead of proper characters (`'`) in interactive mode.

**Root Cause:** HTML entities are being double-encoded or not decoded when rendering interactive mode content.

**Repro:**
1. Create recipe with apostrophes in text (e.g., "that's delicious")
2. View recipe card
3. Enter interactive mode
4. Observe `that&#x27;s` instead of `that's`

#### Acceptance Criteria

**Given** a recipe with text containing apostrophes
**When** the user views the recipe in interactive mode
**Then** apostrophes display as `'` not `&#x27;`

**Given** a recipe with any special characters (quotes, em-dashes, fractions)
**When** viewed in interactive mode
**Then** characters render correctly, matching the non-interactive view

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-interactive-mode.php` (if exists) or `tests/Integration/test-card-rendering.php`
- Test cases:
  - Apostrophes in ingredient names
  - Apostrophes in instructions
  - Double quotes, single quotes
  - Em-dashes and en-dashes
  - Fraction characters (½, ¼, ¾)

```php
public function test_interactive_mode_decodes_apostrophes() {
    // Given a recipe with apostrophe
    $recipe = $this->create_recipe(['ingredients' => "1 cup that's great"]);

    // When rendered in interactive mode
    $output = $this->render_interactive_mode($recipe);

    // Then apostrophe is not entity-encoded
    $this->assertStringNotContainsString('&#x27;', $output);
    $this->assertStringContainsString("that's", $output);
}
```

#### Fix Approach
Check where interactive mode content is escaped/encoded. Likely need to use `html_entity_decode()` or avoid double-encoding.

---

## P1 - High

### 3. Reply Button Visible to Non-Admins **[Quick Win]**
**Component:** Reviews Frontend

Reply button shows for all users including non-logged-in visitors. Should be admin-only on all devices.

**Quote:** "I'm viewing the reviews on mobile where I'm not logged into the site, and it is giving me a reply option."

#### Acceptance Criteria

**Given** a logged-out user viewing reviews
**When** they view any review on any device
**Then** no reply button/option is visible

**Given** a logged-in non-admin user viewing reviews
**When** they view any review
**Then** no reply button/option is visible

**Given** a logged-in admin user viewing reviews
**When** they view any review
**Then** the reply button is visible and functional

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-reviews-retrieval.php` or `tests/Integration/test-reviews-api-filters.php`
- Test cases:

```php
public function test_reply_button_hidden_for_logged_out_users() {
    // Given no user is logged in
    wp_set_current_user(0);

    // When rendering review with reply option
    $output = $this->render_review_with_reply();

    // Then reply button is not present
    $this->assertStringNotContainsString('reply', strtolower($output));
}

public function test_reply_button_hidden_for_subscriber() {
    // Given a subscriber is logged in
    $user = $this->factory->user->create(['role' => 'subscriber']);
    wp_set_current_user($user);

    // When rendering review
    $output = $this->render_review_with_reply();

    // Then reply button is not present
    $this->assertStringNotContainsString('reply', strtolower($output));
}

public function test_reply_button_visible_for_admin() {
    // Given an admin is logged in
    $user = $this->factory->user->create(['role' => 'administrator']);
    wp_set_current_user($user);

    // When rendering review
    $output = $this->render_review_with_reply();

    // Then reply button is present
    $this->assertStringContainsString('reply', strtolower($output));
}
```

#### Fix Approach
Add `current_user_can('manage_options')` or similar capability check before rendering reply button.

---

### 4. No Delete Review Option
**Component:** Reviews Admin

Admins cannot delete reviews. Critical for spam management.

**Quote:** "Comment spammers are getting wise to recipe reviews and I've had to delete a ton of fraudulent spam reviews on my big site."

**Behavior:** Hard delete with confirmation dialog (no trash/restore).

#### Acceptance Criteria

**Given** an admin viewing a review in the admin interface
**When** they look at available actions
**Then** a "Delete" option is visible

**Given** an admin clicks "Delete" on a review
**When** the confirmation dialog appears
**Then** it clearly states this is permanent and cannot be undone

**Given** an admin confirms deletion
**When** the deletion completes
**Then** the review is permanently removed from the database
**And** success feedback is shown

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-reviews-api-filters.php`
- Test cases:

```php
public function test_admin_can_delete_review() {
    // Given an admin and a review
    $admin = $this->factory->user->create(['role' => 'administrator']);
    wp_set_current_user($admin);
    $review_id = $this->create_review();

    // When deleting the review via API
    $request = new WP_REST_Request('DELETE', '/mv-create/v1/reviews/' . $review_id);
    $response = rest_do_request($request);

    // Then deletion succeeds
    $this->assertEquals(200, $response->get_status());

    // And review no longer exists
    $this->assertNull($this->get_review($review_id));
}

public function test_non_admin_cannot_delete_review() {
    // Given a subscriber and a review
    $user = $this->factory->user->create(['role' => 'subscriber']);
    wp_set_current_user($user);
    $review_id = $this->create_review();

    // When attempting to delete
    $request = new WP_REST_Request('DELETE', '/mv-create/v1/reviews/' . $review_id);
    $response = rest_do_request($request);

    // Then deletion is forbidden
    $this->assertEquals(403, $response->get_status());
}
```

**Playwright (for confirmation dialog):**
- Click delete button
- Assert confirmation dialog appears with warning text
- Click cancel, verify review still exists
- Click delete, confirm, verify review removed

---

### 5. Create Studio Password Loop
**Component:** Create Studio Auth

Recommended Products section asks for password despite already being authenticated.

**Quote:** "The Recommended Products section is asking me to set a password for studio, even though I already did."

**Scope:** Requires fixes on both plugin side and Studio backend.

#### Acceptance Criteria

**Given** a user who has already set their Studio password
**When** they navigate to the Recommended Products section
**Then** they are not prompted to set a password again

**Given** a user is authenticated with Studio
**When** they access any Studio feature (Products, etc.)
**Then** their authentication persists across all features

#### Test Plan

**Manual Testing Required** (spans plugin and Studio):
1. Set up Studio password
2. Navigate away from Studio settings
3. Return to Recommended Products
4. Verify no password prompt appears
5. Test across different Studio features

**Plugin-side investigation:**
- Check token storage in WordPress options
- Verify token is sent with all Studio API requests
- Check if different features use different auth endpoints

---

## P2 - Medium

### 6. Interactive Mode X Button Too Large **[Quick Win]**
**Component:** Client Interactive Mode

Close button spans entire screen width on mobile, blocking recipe photo. Caused by WordPress theme button styles being inherited.

**Quote:** "The 'x' across the top like that seems like a lot. It blocks a big portion of the photo."

**Root Cause:** Close button CSS selectors are not specific enough, allowing theme `button` styles to override and make it full-width.

#### Acceptance Criteria

**Given** a user in interactive mode on mobile
**When** they view the close button
**Then** it is a reasonably-sized icon (44px minimum for touch) in the corner
**And** it does not span the full screen width
**And** it does not significantly block the recipe photo

**Given** any WordPress theme with aggressive button styling
**When** interactive mode is displayed
**Then** the close button maintains its intended size and position

#### Test Plan

**Playwright:**
- Open recipe in interactive mode on mobile viewport
- Assert close button width < 100px (or similar reasonable max)
- Assert close button is positioned in corner
- Test with multiple theme stylesheets (Twenty Twenty-Four, Flavor, etc.)

```javascript
test('interactive mode close button is reasonably sized on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/recipe-with-interactive-mode/');
  await page.click('[data-interactive-mode-trigger]');

  const closeButton = page.locator('.mv-create-interactive-close');
  const box = await closeButton.boundingBox();

  expect(box.width).toBeLessThan(100);
  expect(box.width).toBeGreaterThanOrEqual(44); // touch target
});
```

#### Fix Approach
Add more specific CSS selectors for the close button to prevent theme override:
```css
.mv-create-interactive-mode .mv-create-close-button,
.mv-create-interactive-mode button.mv-create-close-button {
  width: 44px !important;
  /* other reset styles */
}
```

---

### 7. "Needs Response" Not Linked **[Quick Win]**
**Component:** Reviews Admin

"Needs response" indicator doesn't link to respond action.

#### Acceptance Criteria

**Given** a review showing "Needs response" indicator
**When** the admin clicks on it
**Then** they are navigated to the respond interface for that review

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-reviews-retrieval.php`
- Test that needs_response output includes clickable link/action

```php
public function test_needs_response_indicator_is_clickable() {
    // Given a review needing response
    $review = $this->create_review(['needs_response' => true]);

    // When rendering the review admin row
    $output = $this->render_review_admin_row($review);

    // Then needs response indicator contains a link
    $this->assertStringContainsString('<a', $output);
    $this->assertStringContainsString('needs response', strtolower($output));
}
```

---

### 8. Rating-Only Reviews Show "Needs Response" **[Quick Win]**
**Component:** Reviews Admin

Reviews with only star rating (no text) show "needs response" prompt unnecessarily.

**Quote:** "Maybe the rating only reviews don't need a 'needs response' prompt?"

#### Acceptance Criteria

**Given** a review with only a star rating and no text content
**When** displayed in the admin interface
**Then** no "needs response" indicator is shown

**Given** a review with star rating AND text content
**When** displayed in the admin interface
**Then** "needs response" indicator shows if not yet responded to

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-reviews-retrieval.php`

```php
public function test_rating_only_review_no_needs_response() {
    // Given a review with rating but no text
    $review = $this->create_review([
        'rating' => 5,
        'content' => '',
    ]);

    // When checking needs_response status
    $needs_response = $this->get_review_needs_response($review);

    // Then it should not need response
    $this->assertFalse($needs_response);
}

public function test_review_with_text_needs_response() {
    // Given a review with rating and text
    $review = $this->create_review([
        'rating' => 5,
        'content' => 'This recipe was great!',
    ]);

    // When checking needs_response status
    $needs_response = $this->get_review_needs_response($review);

    // Then it should need response
    $this->assertTrue($needs_response);
}
```

---

### 9. Star Ratings Not Editable
**Component:** Reviews Admin

Admins cannot edit star rating value. WPRM has this feature.

**Quote:** "I've had people email me and ask them to edit something because they clicked the wrong thing and then got confused."

#### Acceptance Criteria

**Given** an admin editing a review
**When** they view the edit interface
**Then** the star rating is displayed as an editable field

**Given** an admin changes the star rating
**When** they save the review
**Then** the new rating is persisted

**Given** an admin changes a rating from 1 to 5
**When** the change is saved
**Then** the recipe's average rating is recalculated

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-reviews-api-filters.php`

```php
public function test_admin_can_edit_star_rating() {
    // Given an admin and a review with 3 stars
    $admin = $this->factory->user->create(['role' => 'administrator']);
    wp_set_current_user($admin);
    $review_id = $this->create_review(['rating' => 3]);

    // When updating the rating to 5
    $request = new WP_REST_Request('PATCH', '/mv-create/v1/reviews/' . $review_id);
    $request->set_param('rating', 5);
    $response = rest_do_request($request);

    // Then update succeeds
    $this->assertEquals(200, $response->get_status());

    // And rating is updated
    $review = $this->get_review($review_id);
    $this->assertEquals(5, $review->rating);
}

public function test_rating_change_updates_recipe_average() {
    // Given a recipe with one 5-star review
    $recipe_id = $this->create_recipe();
    $review_id = $this->create_review(['recipe_id' => $recipe_id, 'rating' => 5]);

    // When adding another review and changing its rating
    $review2_id = $this->create_review(['recipe_id' => $recipe_id, 'rating' => 1]);

    // Then average should be 3
    $recipe = $this->get_recipe($recipe_id);
    $this->assertEquals(3, $recipe->average_rating);
}
```

---

### 10. Unknown Error on Create Settings
**Component:** Admin Settings

Plugin conflict with Ultimate Social Media Share plugin causing JavaScript to output as text in Create footer/editor areas.

**Conflicting Plugin:** [Ultimate Social Media Icons](https://wordpress.org/plugins/ultimate-social-media-icons/)

#### Acceptance Criteria

**Given** Ultimate Social Media Share plugin is active alongside Create
**When** viewing Create settings or editor
**Then** no JavaScript code appears as visible text

#### Test Plan

**Manual Testing:**
1. Install and activate Ultimate Social Media Share plugin
2. Navigate to Create settings page
3. Open Create editor
4. Check for visible JavaScript code in footer areas

**Investigation:**
- Check where Create renders footer content
- Identify how USM plugin hooks are interfering
- Add output buffering or hook priority adjustments

---

## P3 - Low

### 11. New Design Whitespace
**Component:** Client Card Themes

New design has significant whitespace requiring excessive scrolling.

**Quote:** "It takes a LOT of scrolling to get through the card with that new design enabled."

**Note:** May be intentional design choice. Requires design team review.

#### Acceptance Criteria

**To be determined after design review.** Options:
1. Accept as intentional design
2. Reduce padding/margins in new theme
3. Add "compact mode" setting

#### Test Plan

**Design Review Required** - not a bug fix, but a design decision.

---

### ~~12. Ingredient Pluralization~~ **[DEFERRED]**

~~Ingredient names stay singular when yield increases (1 egg → 2 egg instead of 2 eggs).~~

**Status:** Deferred to future release. Complex i18n problem with no simple solution.

---

### ~~13. Password Reset Email Not Responsive~~ **[OUT OF SCOPE]**

~~Email template not mobile-friendly.~~

**Status:** Studio backend issue only. Not addressed in this plugin spec.

---

### 14. Internal Server Error Adding Site
**Component:** Create Studio API

Error shown when adding site, but connection succeeds anyway.

**Quote:** "I got an internal server error... However, in the Create settings in my WP Admin, it says the site is connected, so IDK?"

#### Acceptance Criteria

**Given** a user adds their site to Studio
**When** the operation succeeds but an error was thrown
**Then** the success state should be shown, not the error

**Given** an API error occurs during site connection
**When** the error is transient but operation succeeded
**Then** plugin should verify actual state before showing error

#### Test Plan

**PHPUnit:**
- File: `tests/Integration/test-studio-api.php` (if exists) or new file

```php
public function test_site_connection_shows_success_when_connected() {
    // Given a site that successfully connected despite API error
    $this->mock_studio_api_error_but_success();

    // When checking connection status
    $status = $this->get_studio_connection_status();

    // Then status should show connected
    $this->assertTrue($status['connected']);
}
```

**Fix Approach:**
After catching an error, verify the actual connection state before displaying error to user. If connected, show success.

---

## Test File Summary

| Existing File | Bugs to Add |
|---------------|-------------|
| `tests/Integration/test-reviews-retrieval.php` | #3, #7, #8 |
| `tests/Integration/test-reviews-api-filters.php` | #4, #9 |
| `tests/Integration/test-card-rendering.php` | #1, #2 |

| New File (if needed) | Bugs |
|---------------------|------|
| `tests/Integration/test-interactive-mode.php` | #2 (if not in card-rendering) |
| `tests/Integration/test-studio-api.php` | #14 |

| Playwright Tests | Bugs |
|-----------------|------|
| `e2e/tests/yield-selector.spec.ts` | #1 |
| `e2e/tests/interactive-mode.spec.ts` | #6 |
| `e2e/tests/reviews-admin.spec.ts` | #4 (confirmation dialog) |

---

## Recommended Fix Order (Quick Wins First)

1. **#3 Reply Button** - Simple capability check
2. **#8 Rating-Only Needs Response** - Conditional logic
3. **#7 Needs Response Link** - Add href/action
4. **#2 Character Encoding** - Fix double-encoding
5. **#6 X Button CSS** - More specific selectors
6. **#1 Serving Size Colors** - CSS contrast fix
7. **#4 Delete Review** - Add endpoint + UI
8. **#9 Editable Stars** - Add edit field
9. **#14 API Error Handling** - Verify state before error
10. **#5 Password Loop** - Both sides (defer if blocking)
11. **#10 Plugin Conflict** - Investigate hook priorities
12. **#11 Whitespace** - Design team decision
