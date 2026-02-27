# Recipe Importers Integration Spec

> **Status:** Draft
> **Author:** [TBD]
> **Created:** 2026-01-06
> **Last Updated:** 2026-01-06

## Summary

Consolidate the standalone Recipe Importers plugin into the main Create plugin as an opt-in feature controlled by a settings toggle, simplifying maintenance and improving the user experience.

---

## Motivation

**Why are we doing this?**
- **Maintenance burden** - Two separate codebases is harder to maintain and keep in sync
- **Code sharing** - Want to share more code/components between importer and Create

**What problems does this solve?**
- Duplicate code and divergent patterns between plugins
- Having to coordinate releases between two plugins
- Importer not benefiting from Create UI improvements

**What are the expected benefits?**
- Simplified maintenance (single codebase)
- Easier access to Create's UI components
- Better onboarding experience for users migrating from other recipe plugins
- Opportunity to redesign importer UI with modern patterns

---

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Availability | Free (not Pro-only) | Importers help onboard new users from other plugins |
| Settings Location | Advanced tab toggle | Keep with other power-user features |
| Migration Strategy | Auto-disable + admin notice | Prevent conflicts while informing users |
| Editor Integration | Include single recipe importer | Maintain current UX for in-editor imports |
| API Backwards Compat | Not needed | Use new routes (`mv-create/v1/importers`), nothing external depends on old routes |
| Rollout Strategy | Direct release | No beta tester flag - importers are battle-tested |
| Standalone Deprecation | Immediate | Stop updating standalone once integrated version ships |
| UI Approach | Ship first, polish later | Get integration working, then redesign in follow-up |
| Auto-detect Plugins | Highlight detected | Show all importers, but highlight/sort detected plugins to top |

---

## User Stories

### Publisher Migrating from Another Plugin
> As a publisher currently using WP Recipe Maker, I want to easily import all my recipes into Create so that I can switch plugins without losing my content.

### Existing Create User
> As an existing Create user, I don't want importer functionality cluttering my admin unless I need it, so that my interface stays clean.

### User with Standalone Plugin Installed
> As a user who already has the standalone importer plugin, I want a smooth transition to the integrated version without losing any functionality.

---

## Feature Requirements

### Must Have (P0)
- [ ] Settings toggle to enable/disable importers
- [ ] All 13 existing importers functional
- [ ] Bulk import UI (find, import, replace workflow)
- [ ] Re-import functionality for previously imported recipes
- [ ] Single recipe importer in post editor
- [ ] Auto-migration from standalone plugin

### Should Have (P1)
- [ ] Persistent admin notice explaining migration
- [ ] Link from settings to importer page when enabled
- [ ] Progress indicators during bulk operations

### Nice to Have (P2)
- [ ] Import history/log
- [ ] Undo/rollback for imports
- [ ] Preview before import

### Out of Scope
- Adding new importers for additional plugins (can be added later)
- UI redesign (deferred to Phase 2 follow-up)
- REST API backwards compatibility with old routes

---

## Technical Design

### PHP Architecture

**New Directory Structure:**
```
lib/importers/
├── class-importers.php              # Main orchestrator
├── class-importers-api.php          # REST endpoints
├── class-mv-recipe-importer.php     # Core import engine
├── sources/                         # Individual importers
│   ├── class-import-cookbook.php
│   ├── class-import-tasty-recipes.php
│   ├── class-import-recipe-maker.php
│   └── ... (10 more)
└── helpers/
    ├── class-ingredient-parse.php
    └── easy-recipe/
```

**Namespace:**
- Change from `Mediavine\Create\Importer` → `Mediavine\Create\Importers`

**Conditional Loading:**
```php
if (\Mediavine\Settings::get_setting('mv_create_enable_importers')) {
    $Importers = new Importers();
    $Importers->init();
}
```

### REST API

**New Endpoints (under `mv-create/v1/importers`):**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/find` | Discover recipes from source plugins |
| POST | `/bulk` | Create Create cards from source recipes |
| POST | `/replace` | Replace old shortcodes in post content |
| POST | `/reimport` | Re-import previously imported recipes |
| POST | `/block` | Update Gutenberg blocks |

### Frontend Architecture

**Build Configuration:**
- Separate Vite entry point: `src/importers/main.tsx`
- Separate bundle: `importers.build.{VERSION}.js`
- Shared UI components via existing `@create/ui` imports

**Component Structure:**
```
admin/ui/src/importers/
├── main.tsx                    # Entry point
├── App.tsx                     # View router
├── components/
│   ├── BulkImporter/          # Multi-stage bulk import
│   ├── SingleImporter/        # Editor modal
│   └── Reimporter/            # Re-import existing
└── helpers/
    ├── constants.ts           # Importer definitions
    ├── network.ts             # API helpers
    └── recipes.ts             # Filtering/dedup
```

### Settings Schema

```php
[
    'slug'  => 'mv_create_enable_importers',
    'value' => false,
    'group' => 'mv_create_advanced',
    'order' => 200,
    'data'  => [
        'type'         => 'checkbox',
        'label'        => 'Enable Recipe Importers',
        'instructions' => 'Import recipes from other recipe plugins like WP Recipe Maker, Tasty Recipes, and more.',
    ],
]
```

### Migration Logic

**Detection:**
```php
is_plugin_active('create-recipe-importers/mediavine-recipe-importer.php')
```

**Auto-Disable:**
```php
deactivate_plugins('create-recipe-importers/mediavine-recipe-importer.php');
set_transient('mv_create_importer_migration_notice', true);
```

**Admin Notice:**
- Persistent until dismissed via AJAX
- Links to Settings → Advanced
- Explains new workflow

---

## UI/UX Design

### Current UI Issues (to address in redesign)
- **Outdated styling** - Visual design feels dated compared to current Create UI
- **Poor feedback** - Progress/status indicators aren't clear during imports
- **Too many steps** - Multi-stage flow is overly complex

### Design Direction
- **Phase 1 (This Release):** Ship existing UI with minimal changes to get integration working
- **Phase 2 (Follow-up):** New wizard-style pattern that could be reused elsewhere in Create

### Settings Toggle
- Location: Settings → Advanced tab
- Label: "Enable Recipe Importers"
- Description: "Import recipes from other recipe plugins like WP Recipe Maker, Tasty Recipes, and more."

### Admin Menu
- Menu item: "Import Recipes"
- Parent: Create (mv_create post type)
- Only visible when setting enabled

### Source Plugin Detection
- Show all 13 importers (keep all - all are still relevant)
- Highlight/sort detected plugins to the top of the list
- Visual indicator for "Detected" vs "Not found"

### Bulk Importer Flow (Current - Phase 1)
1. Select source plugin
2. Scan for recipes
3. Select recipes to import
4. Import progress
5. Replace shortcodes in posts
6. Success summary

### Bulk Importer Flow (Redesign - Phase 2)
- Simplified wizard with clearer progress
- Better visual feedback during operations
- Consider combining steps where possible
- Design mockups needed before implementation

### Editor Button
- Location: Media buttons row (next to Add Media)
- Opens modal for single recipe import
- Only shown when importers enabled

### Documentation
- **Help Center:** Detailed documentation in Mediavine/Create help center
- **In-app:** Contextual help/tooltips within the importer UI

---

## Testing Plan

### Test Data Requirements
- Need sample data from each source plugin to test importers
- Migrate existing tests from standalone plugin

### Unit Tests
- [ ] Individual importer serializers
- [ ] Settings registration
- [ ] REST endpoint validation

### Integration Tests
- [ ] Full import workflow for each of 13 importers
- [ ] Migration from standalone plugin
- [ ] Conditional loading (enabled vs disabled)

### Manual Testing
- [ ] Fresh install with setting disabled → no importer UI
- [ ] Enable setting → menu appears, UI works
- [ ] Standalone plugin active → auto-disabled, notice shown
- [ ] Each importer type with sample data
- [ ] Plugin detection highlighting works correctly

### Test Environments
- PHP 7.4, 8.1, 8.3
- WordPress 6.x
- With/without Gutenberg

---

## Rollout Plan

### Phase 1: Development
- [ ] PHP integration (copy files, update namespaces)
- [ ] Settings toggle implementation
- [ ] REST API endpoints under new routes
- [ ] Migration logic (auto-disable, admin notice)
- [ ] Admin menu (conditional)
- [ ] Frontend integration (Vite entry point)
- [ ] Editor button integration
- [ ] Testing with migrated tests + test data

### Phase 2: Release
- [ ] Documentation updates (help center + in-app)
- [ ] Release notes
- [ ] Standalone plugin: Final update with deprecation notice pointing to Create

### Phase 3: UI Redesign (Follow-up)
- [ ] Design mockups for new wizard pattern
- [ ] Implement simplified flow
- [ ] Better progress/feedback UI
- [ ] Reusable wizard component for Create

---

## Resolved Questions

| Question | Resolution |
|----------|------------|
| Deprecation Timeline | Immediate - stop updating standalone once this ships |
| Backwards Compatibility | Not needed - use new API routes |
| Feature Flag | Direct release, no beta tester |
| Documentation | Help center + in-app contextual help |

---

## Appendix

### Supported Importers

| Importer | Source Plugin | Notes |
|----------|---------------|-------|
| WP Recipe Maker | WPRM | Most popular |
| Tasty Recipes | WP Tasty | |
| Cookbook | Cookbook | |
| Meal Planner Pro | Meal Planner Pro | |
| WP Ultimate Recipe | WPUR | |
| Simple Recipes Pro | SRP | 3 versions |
| Zip Recipes | Zip Recipes | |
| ZipList | ZipList | Legacy |
| Purr | Purr Recipe Cards | |
| Yummly | Yummly | |
| EasyRecipe | EasyRecipe | |

### Related Documents
- [Create Hooks Documentation](../CreateHooks.md)
- [Settings Architecture](TBD)

### References
- Standalone plugin: `/plugins/create-recipe-importers/`
- Current UI components: `/admin/ui/src/components/`
