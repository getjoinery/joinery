# Specification: A/B Testing Framework (Multi-Armed Bandit)

## Overview

A platform-level multi-armed bandit (epsilon-greedy) A/B testing framework. Tests attach to **entities** — any `SystemBase` data class that opts in. Admins turn testing on/off, add variants, and crown winners through a standard reusable component that drops into any testable entity's admin edit page. No per-test code changes.

The framework is **server-side assignment, server-side reward tracking**. Pages rendering an entity with an active test bypass the static page cache so PHP runs on every request, enabling clean per-visitor variant selection with no JavaScript complexity, no hidden content in the HTML, and no SEO concerns.

---

## Algorithm

Standard epsilon-greedy multi-armed bandit, per Steve Hanov's *"20 Lines of Code That Will Beat A/B Testing Every Time."*

For each test with variants V:
- Each variant tracks `trials` (times shown) and `rewards` (conversions attributed).
- On variant selection:
  - With probability **ε = 0.10**: pick a uniformly random variant (explore).
  - Otherwise: pick `argmax(rewards / trials)` (exploit).
- **Cold-start guard:** While any variant has fewer than a floor threshold (default: 100 trials), force ε = 1.0 (uniform random). Prevents one fluke early conversion from locking in a variant forever.

## Assignment persistence

When a visitor is assigned a variant, the assignment is stored in a cookie `ab_{test_id}` (value: variant_id) with a 30-day TTL. On subsequent visits that render the same tested entity, the cookie is read and the same variant is shown — so a returning visitor's conversion is attributable to the variant they originally saw.

Rationale: if a visitor sees variant B on Monday and signs up on Wednesday after seeing variant A (because assignment re-rolled), neither variant gets clean credit. Sticky assignment avoids this.

## Making an entity testable

Any `SystemBase` data class opts in with two static properties — **no new columns on the entity's table, no schema migration**:

```php
class Post extends SystemBase {
    public static $ab_testable = true;
    public static $ab_testable_fields = ['pst_title', 'pst_body', 'pst_cta_text'];
    // rest unchanged
}
```

Declaring these:
- Makes the entity eligible to have a test attached in admin
- Tells the admin UI which fields to show as variant-editable inputs
- Constrains valid keys in a variant's `abv_overrides` JSON

All bandit state (status, epsilon, conversion event type, trials, rewards, variants) lives in the two central tables below. Entities stay clean.

## Public rendering

One helper call in the public view, before the entity's fields are read:

```php
$post = new Post($id, TRUE);
AbTest::apply_variant($post);   // no-op if no active test; else overrides fields in memory
// rest of view renders normally — $post->get('pst_title') returns the variant's value
```

`AbTest::apply_variant()`:
1. Returns immediately if the entity's class doesn't declare `$ab_testable`.
2. Looks up `abt_tests` for `(entity_type, entity_id)`.
3. If no row, or status is `draft`/`paused`/`crowned`/deleted → returns (entity renders as stored — crowned tests have already had the winner copied onto the parent).
4. If status is `active`:
    1. Calls `StaticPageCache::markAsNostatic($_SERVER['REQUEST_URI'])`.
    2. Reads `$_COOKIE['ab_' . $test_id]`. If present and valid, uses that variant.
    3. Otherwise runs epsilon-greedy selection, sets the cookie (30-day TTL via `setcookie()`).
    4. Increments `abv_trials` for the selected variant.
    5. Calls `$entity->set($field, $value)` for each field in `abv_overrides`.

Admin forms don't call `apply_variant()`, so they always edit the parent's stored values directly — variants are edited through the dedicated UI component below.

## Static cache interaction

`StaticPageCache` serves cached pages as raw HTML via `readfile()` — no PHP runs on a cache hit, making server-side variant selection impossible for cached pages.

**Solution: mark pages rendering a tested entity as nostatic on first render.**

`AbTest::apply_variant()` calls `StaticPageCache::markAsNostatic($_SERVER['REQUEST_URI'])` when the test is active. On all subsequent requests, `RouteHelper` sees the nostatic flag and skips the cache, so PHP runs normally and assignment is server-side.

On the very first request after a test is activated, there may be a stale cached page in place. The activation lifecycle event handles this.

## Test lifecycle and cache events

`StaticPageCache::clearAll()` wipes both cached HTML files and the entire cache index (including all nostatic flags) in one call. It is the correct tool for both "bust stale cache on activate" and "restore cacheability on deactivate."

| Event | Cache action | Why |
|-------|-------------|-----|
| **Created** (→ draft) | None | Draft tests don't affect rendering |
| **Activated** (→ active) | `clearAll()` | Busts any stale cached pages; nostatic flag will be set on next render |
| **Variant added/edited** (while active) | None | Page is already nostatic; PHP runs on every request, picks up changes immediately |
| **Variant deleted** (while active) | None | Same as above |
| **Paused** (→ paused) | `clearAll()` | Clears nostatic flag so the page (now rendering parent-only) can be cached again |
| **Crowned** (→ crowned) | `clearAll()` | Winner's `abv_overrides` are copied onto the parent entity; clears nostatic flag; next request renders the winner's content and caches it |
| **Deleted** (was active) | `clearAll()` | Clears nostatic flag |
| **Deleted** (was draft/paused/crowned) | None | No nostatic flag was set |

## Reward tracking (server-side)

When a conversion event fires, the server reads the `ab_*` cookies from the request and bumps `rewards` for each matching variant in each active test.

In `SessionControl::save_visitor_event()`, after the insert, call `AbTest::record_conversion_for_request($vse_type)`. The helper:
1. Enumerates active tests where `abt_conversion_event_type = $vse_type`.
2. For each, reads `$_COOKIE['ab_' . $test_id]`.
3. If present and the variant belongs to the test, increments `abv_rewards` (single UPDATE statement per match).

No client-side conversion beacon needed — the existing server-side conversion event is the source of truth.

## Data model

**Table: `abt_tests`**
- `abt_test_id` — int8 serial PK
- `abt_entity_type` — varchar(64), class name (e.g. `Post`, `Product`)
- `abt_entity_id` — int8, the entity's primary key
- `abt_status` — varchar(16) — `draft` | `active` | `paused` | `crowned`
- `abt_conversion_event_type` — int2 (references `VisitorEvent::TYPE_*` constants)
- `abt_epsilon` — decimal(4,3), default 0.100
- `abt_cold_start_threshold` — int4, default 100
- `abt_winner_abv_variant_id` — int8 nullable (set when crowned)
- `abt_created_time`, `abt_modified_time`, `abt_delete_time`

One live test per entity — enforced at the admin UI / save layer (the component won't let an admin create a second active test on the same entity while one exists).

**Table: `abv_variants`**
- `abv_variant_id` — int8 serial PK
- `abv_abt_test_id` — int8, indexed, references `abt_tests`
- `abv_name` — varchar(64) — e.g., `control`, `short_copy`, `urgency`
- `abv_overrides` — json — keys restricted to the parent entity's `$ab_testable_fields`; values match the field's storage type (strings for text fields, etc.)
- `abv_trials` — int8, default 0
- `abv_rewards` — int8, default 0
- `abv_created_time`, `abv_modified_time`, `abv_delete_time`

Both tables follow the standard `SystemBase` pattern — `update_database` will materialize them from `$field_specifications`.

## Helper class: `includes/AbTest.php`

Static methods:

- `AbTest::apply_variant(SystemBase $entity): void` — public-render hook described above.
- `AbTest::record_conversion_for_request(int $vse_type): void` — conversion attribution described above.
- `AbTest::get_active_test_for_entity(string $entity_class, int $entity_id): ?AbtTest` — helper for the admin UI to determine current test state.
- `AbTest::copy_winner_onto_parent(AbtTest $test): void` — invoked by the crown action; reads winner's `abv_overrides`, sets each field on the parent entity, saves it.

## Admin UI

### Reusable component: `AbTestVersionsPanel`

Drops into any testable entity's existing admin edit page:

```php
// In /adm/admin_post_edit.php (or equivalent)
if (Post::$ab_testable ?? false) {
    AbTestVersionsPanel::render('Post', $post_id);
}
```

The component renders:
- **Status header + primary action** — Activate / Pause / Crown button, plus a "Start test" affordance if no test exists yet for this entity.
- **Variants section** — CRUD for variants. Form auto-generates inputs from the entity's `$ab_testable_fields`: one input per field (labels title-cased: `pst_cta_text` → "Cta Text"), per variant. Values stored in `abv_overrides`. Empty override → variant inherits the parent's value for that field.
- **Leaderboard** — per-variant: trials, conversions, conversion rate. Leader highlighted.
- **Test settings** (collapsible) — conversion event type (dropdown from `VisitorEvent::TYPE_*` constants), epsilon, cold-start threshold.

### Cross-entity list: `/adm/admin_ab_tests`

Global view of all tests across all entity types. Sortable columns: entity type, entity link (deep-links to the entity's edit page where the panel lives), status, trials, leader, conversion rate, started date. No reporting beyond the leaderboard — the admin funnel UI handles deeper conversion analysis.

### Crown action

Sets status to `crowned`, sets `abt_winner_abv_variant_id`, **copies the winner's `abv_overrides` onto the parent entity's fields and saves the parent**, calls `clearAll()`. After crowning, the test is historical and the parent renders the winner's content as its own stored state. Irreversible from UI (edit in DB if you need to un-crown).

## Non-goals

- **Layout / structural variants** — MVB tests field overrides on an entity. No "render a different view" logic.
- **Multivariate tests** — one test per entity, one dimension.
- **Auto-rollout** — crowning is a manual admin action.
- **Segment tests** — no "show variant A to new users, B to returning" logic. Possible later by hashing `vse_visitor_id`.
- **Bayesian bandits / Thompson sampling** — epsilon-greedy is enough.
- **Per-variant UTM attribution reports** — raw data is in conversion event rows + the `ab_*` cookie. Dedicated report UI is a follow-on.
- **Multiple concurrent tests per entity** — one live test per entity at a time.

## Homepage (and arbitrary content pages)

The existing `Page` entity (`data/pages_class.php`) and the existing `alternate_homepage` / `alternate_loggedin_homepage` settings (see serve.php's `/` route) already provide the homepage-swap mechanism: set `alternate_homepage = /page/{slug}` and the root URL renders that Page through `views/page.php`. No new entity type or template-view special-casing is needed.

To enable homepage (and any Page) A/B testing:

1. Move the current homepage content into a Page record (e.g., `pag_link = 'home'`).
2. Point `alternate_homepage` at `/page/home`.
3. Opt `Page` into testing in `data/pages_class.php`:
   ```php
   public static $ab_testable = true;
   public static $ab_testable_fields = ['pag_title', 'pag_body'];
   ```
4. Add `AbTest::apply_variant($page)` near the top of `views/page.php` (and the themed `theme/*/views/page.php` overrides) before the entity's fields are read.
5. Mount `AbTestVersionsPanel::render('Page', $page_id)` on the Page admin edit page.

Result: every Page is testable, the homepage included. Admins write full `pag_body` HTML variants per test — maximally flexible for hero-style pages. Title variants cover head-tag and share-surface tests.

**Composable extension — component-level tests:** pages that use the `PageContent`/`ComponentRenderer` system render through components rather than raw `pag_body`. Component-body variants and layout variants both opt into the same framework with no special cases — see below.

## Component-level tests

Two flavors, both using the same opt-in pattern as Page.

### Component-body tests (per-component copy variants)

`PageContent` is already an entity (`pac_page_contents`) with `pac_body` and `pac_config` fields. Opt it in:

```php
// data/page_contents_class.php
public static $ab_testable = true;
public static $ab_testable_fields = ['pac_body'];
```

Then call `AbTest::apply_variant($pageContent)` inside `ComponentRenderer::render_component()` before the component's body/config is read. Mount `AbTestVersionsPanel::render('PageContent', $pac_id)` on whatever admin surface edits a PageContent row.

Result: each component instance is independently testable — hero copy, CTA copy, testimonial text, etc.

**`pac_config` is deferred** — it's a JSON blob whose keys are component-type-specific, and testing it cleanly requires component types to declare their config schemas so the variant form can render a structured editor. For MVB, body-only is enough.

**Interaction-effects caveat:** multiple active component tests on the same page are implicitly multivariate — each variant's rewards are attributed independently even though a visitor sees a specific combination. Document the guidance: "run at most one or two concurrent tests per page." Consistent with the framework-level "no multivariate tests" non-goal.

### Page-layout tests (reorder / show-hide / swap / replace)

A new JSON column on Page — `pag_component_layout` — holds an ordered array of `pac_page_content_id` values. This becomes the **sole** source of truth for which components render on a page and in what order. The legacy `pac_order` column and `pac_pag_page_id` FK are dropped; the legacy placeholder system is removed entirely (see *Prerequisite: Legacy content system cleanup* below for the full scope and migration).

```
pag_component_layout   -- json: e.g. [17, 42, 9, 23]
```

**Rendering.** `Page::get_filled_content()` reads `pag_component_layout`, loads the listed PageContents in a single query, and renders in array order. If the array is empty, renders `pag_body` directly.

**Admin UI.** Page's admin edit page gains a drag-reorder component picker that writes back to `pag_component_layout`. Same UI used for editing the base page's layout and for editing a variant's layout override. Admins can reorder, hide (omit from the list), or swap (add an alternate PageContent created for this purpose).

**A/B testing.** Add the field to the list:

```php
// data/pages_class.php
public static $ab_testable_fields = ['pag_title', 'pag_body', 'pag_component_layout'];
```

A variant's `abv_overrides` stores its own JSON array for `pag_component_layout`. When a variant is applied, the page renders that component arrangement. One mechanism covers all four layout test shapes:

- **Reorder** — variant's list is the same IDs in a different order
- **Show/hide** — variant's list omits specific IDs
- **Swap/replace** — admin creates alternate PageContent rows and references their IDs in the variant's list
- **Entirely different page** — variant's list is a completely different ID set

No framework extensions, no coordinated multi-test orchestration, no new override concept — layout is just another testable field.

**Side benefit from dropping `pac_pag_page_id`:** PageContents become cross-page reusable — a single "free trial banner" row can appear in multiple pages' layout arrays, edited once and reflected everywhere.

---

## Prerequisite: Legacy content system cleanup

The A/B layout-testing work depends on a broader cleanup of deprecated Page/PageContent systems that predate the component model. Carrying these forward would mean two sources of truth for component ordering, three substitution mechanisms, and a twisted rendering path in `Page::get_filled_content()`. Since there are no production users yet, a coordinated breaking cutover is both feasible and preferable to a drawn-out dual-source-of-truth period.

This section specifies the cleanup as a single coherent change with automated migration support designed to run across multiple deployed sites (ScrollDaddy, empoweredhealthtn, etc.) with minimal manual intervention per site.

### Scope of removal

**Schema — columns dropped:**
- `PageContent.pac_order` — replaced by `Page.pag_component_layout`
- `PageContent.pac_pag_page_id` — replaced by layout array membership (side effect: components become cross-page reusable)
- `PageContent.pac_link` — legacy `*!**slug**!*` placeholder system
- `PageContent.pac_script_filename` — legacy logic-file inclusion on components
- `Page.pag_script_filename` — legacy logic-file inclusion on pages

**Code removed:**
- `Page::get_body_content()` entirely (data/pages_class.php:95-126) — handled both legacy placeholder substitution paths
- `PageContent::save()` — drop the `pac_link` duplicate-check branch; method reverts to `parent::save()`
- `MultiPageContent::getMultiResults()` — drop the `page_id`, `link`, and `has_link` filter options

**Admin UI removed:**
- `adm/admin_page_content.php` (entire file) — legacy content viewer
- `adm/admin_page_content_edit.php` (entire file) — legacy content editor
- `adm/admin_page.php:190-230` — conditional "Page Content (Legacy)" table
- `adm/admin_component_edit.php` — remove the `pac_pag_page_id` "Assign to Page" dropdown and its save/redirect logic; pages pick components via the drag-reorder picker on Page edit

### Replacement for `pag_script_filename`

A few Pages today are dynamic — `pag_script_filename` names a logic file that populates `$replace_values`, which substitutes `{{var}}` placeholders in `pag_body`. Examples from `migrations/migrations.php`: `register-thanks`, `verify-email-confirm`. These can't be auto-flattened; the content depends on runtime state.

Replace with a cleaner view-override mechanism. Add one new column:

```
pag_template   -- varchar(128), nullable
```

When set, `views/page.php` defers to `theme/*/views/{pag_template}.php` — a normal view that receives `$page` and does whatever rendering it needs inline with standard PHP. No separate logic file, no `{{var}}` convention. Example: `register-thanks` becomes `theme/*/views/page_register_thanks.php`, and `pag_template = 'page_register_thanks'` routes the page there.

This is a better-factored mechanism independent of the cleanup — worth the small lift.

### Migration strategy

All transformation, auditing, and schema coordination lives in a single idempotent CLI script: `utils/legacy_content_cleanup.php`. It's designed to be safely re-run and to halt cleanly on any ambiguous state rather than guess.

**Invocation:**

```
php utils/legacy_content_cleanup.php --audit
php utils/legacy_content_cleanup.php --migrate
php utils/legacy_content_cleanup.php --migrate --confirm-dynamic=/page/register-thanks
php utils/legacy_content_cleanup.php --drop-columns
```

**What it does, in order:**

**1. Audit (`--audit`).** Produces a report to stdout:

```
AUDIT: Legacy content survey for {site_hostname}
================================================

Pages requiring manual conversion (pag_script_filename set):
  - /page/register-thanks   script=register_thanks.php   placeholders: 3
  - /page/verify-email-confirm   script=verify_email.php   placeholders: 1

Pages with *!**slug**!* placeholders (AUTO-FLATTENABLE on --migrate):
  - /page/about   3 placeholders → will substitute and soft-delete absorbed PageContents

Pages using pag_component_layout already (NO ACTION):
  - /page/home, /page/services, ...

Pages with pag_body only, no placeholders (NO ACTION):
  - /page/privacy, /page/terms, ...

Total: 27 pages, 2 require manual conversion, 4 auto-flattenable, 21 no-op.
```

Read-only — touches nothing.

**2. Stub generation for dynamic pages.** For each page with `pag_script_filename` set, `--migrate` writes a stub view file at `theme/default/views/page_{slug_underscored}.php` (skipping if one already exists). The stub includes:
- A header comment identifying the source page and its current script
- A `require_once(PathHelper::getThemeFilePath(...))` pulling in the current logic file (so existing `$replace_values` logic runs)
- The current `pag_body` with `{{var}}` placeholders rewritten as PHP echoes of the matching `$replace_values` keys
- A TODO banner listing anything the script couldn't fully mechanize (unusual placeholder syntax, conditional blocks, etc.)

The admin verifies each stub, edits as needed, confirms the page renders. Then re-runs `--migrate --confirm-dynamic=/page/{slug}` to signal the page is ready — the script then sets `pag_template`, clears `pag_script_filename`, and proceeds with that page's layout migration.

**3. Data migration.** For every Page not blocked on dynamic conversion:
- Populate `pag_component_layout` from current `pac_order`-sorted component IDs (where `pac_pag_page_id = page->key` and `pac_delete_time IS NULL`). Skip if already populated.
- If `pag_body` contains `*!**slug**!*` placeholders AND `pag_script_filename` is empty: perform substitution inline using matching `pac_link` PageContent bodies, write the flattened HTML back to `pag_body`, soft-delete the absorbed legacy PageContents.
- Every step is guarded by state checks — re-running is a no-op on completed pages.

**4. Schema cleanup (`--drop-columns`).** Separate phase, explicit flag required. Runs only after `--audit` confirms no pages still reference any of the legacy mechanisms:
- Verifies no PHP file in the repo references any of the dropped columns (grep-based scan; aborts with diagnostic if any reference remains — catches reverted code, stale plugins, forgotten admin pages)
- Drops the columns via raw SQL on the live DB
- Logs every change

Wrapped in a transaction per site; failure rolls back cleanly.

### Per-site rollout flow

For each deployed site:

1. **Deploy** the code (new columns auto-added via `update_database`; legacy columns still present)
2. **SSH in, run audit**: `php utils/legacy_content_cleanup.php --audit`
3. **Run migrate**: `php utils/legacy_content_cleanup.php --migrate`
   - For any dynamic pages flagged: review the generated stub views, edit as needed, verify the page renders, then `--migrate --confirm-dynamic=/page/{slug}` for each
4. **Verify**: spot-check every page type renders correctly
5. **Drop columns**: `php utils/legacy_content_cleanup.php --drop-columns`

Step 3 is the only per-site manual work, and it's bounded (~2-3 dynamic pages per site based on current `migrations.php` content). The rest is hands-off.

**Backups:** take a DB dump before `--drop-columns` on each site. The column drops are irreversible.

---

## Implementation Checklist

The work sequences in three phases: legacy content cleanup first (without it, layout testing can't cleanly land), then the A/B framework proper, then opt-ins. Each phase's work ships as coherent unit.

### Phase 1 — Legacy content cleanup

- [ ] Add `pag_component_layout` (json) and `pag_template` (varchar(128), nullable) to `Page` `$field_specifications`; add-only — legacy columns stay in place for the moment
- [ ] Update `Page::get_filled_content()` to render from `pag_component_layout` when populated; fall back to direct `pag_body` when array is empty; remove the existing `get_body_content()` fallback path
- [ ] Delete `Page::get_body_content()`
- [ ] Remove `PageContent::save()` override (reverts to `parent::save()`)
- [ ] Remove `page_id`, `link`, `has_link` options from `MultiPageContent::getMultiResults()`
- [ ] Add `pag_template` routing in `views/page.php` (+ themed overrides): when set, defer to `theme/*/views/{pag_template}.php`
- [ ] Replace the component-ordering UI on `adm/admin_page.php` with a drag-reorder picker writing `pag_component_layout`
- [ ] Remove the "Page Content (Legacy)" conditional table from `adm/admin_page.php:190-230`
- [ ] Delete `adm/admin_page_content.php` and `adm/admin_page_content_edit.php`
- [ ] Remove `pac_pag_page_id` dropdown + save/redirect logic from `adm/admin_component_edit.php`; use referrer / `?pag_page_id` param for post-save redirect only
- [ ] Build `utils/legacy_content_cleanup.php` with `--audit`, `--migrate`, `--confirm-dynamic`, and `--drop-columns` flags; idempotent; per-phase state checks
- [ ] Run per-site rollout: deploy → audit → migrate (handle dynamic pages per stub-generation flow) → drop-columns → verify
- [ ] After per-site drop-columns completes, remove the legacy fields from `$field_specifications` (`pac_order`, `pac_pag_page_id`, `pac_link`, `pac_script_filename`, `pag_script_filename`)

### Phase 2 — A/B framework core

- [ ] Create data classes: `data/abt_tests_class.php`, `data/abv_variants_class.php` (+ Multi counterparts)
- [ ] Run `update_database` to materialize `abt_tests`, `abv_variants` tables
- [ ] Create `includes/AbTest.php` with `apply_variant()`, `record_conversion_for_request()`, `get_active_test_for_entity()`, `copy_winner_onto_parent()`
- [ ] Hook `AbTest::record_conversion_for_request()` into `SessionControl::save_visitor_event()` after insert
- [ ] Build `AbTestVersionsPanel` reusable admin component (status header, variants CRUD, leaderboard, settings) — wires `clearAll()` to all status transition actions per the lifecycle table
- [ ] Build `/adm/admin_ab_tests` cross-entity list

### Phase 3 — Opt-ins

- [ ] Opt `Page` into testing: add `$ab_testable` + `$ab_testable_fields = ['pag_title', 'pag_body', 'pag_component_layout']` to `data/pages_class.php`, call `AbTest::apply_variant($page)` in `views/page.php` (+ themed overrides), mount `AbTestVersionsPanel` on the Page admin edit page
- [ ] Create Page record for homepage content; set `alternate_homepage = /page/home`
- [ ] Opt `PageContent` into testing: `$ab_testable = true`, `$ab_testable_fields = ['pac_body']`; call `AbTest::apply_variant()` inside `ComponentRenderer::render_component()`; mount `AbTestVersionsPanel` on the component admin edit page

### Phase 4 — Verification

- [ ] Test: cold-start (< threshold forces random), warm bandit (argmax picks correctly), sticky assignment (cookie returns same variant), conversion attribution (event increments correct variant rewards), crown (copies winner fields onto parent, clears nostatic, page re-caches), activate/pause cycle (nostatic set on activate, cleared on pause, page re-caches)
- [ ] Test layout testing end-to-end: create a Page with multiple components, create a test with reorder + show-hide + swap variants, confirm cookie-sticky assignment and reward attribution work across all three shapes

### Documentation

- [ ] Add `docs/ab_testing.md` covering the opt-in (static properties on entity), `AbTest::apply_variant()` usage, admin UI walkthrough, and cache lifecycle
- [ ] Update `docs/component_system.md` to reflect the removal of `pac_pag_page_id`, `pac_order`, and the legacy placeholder system; document `pag_component_layout` as the canonical ordering mechanism
- [ ] Note `pag_template` as the mechanism for dynamic-content pages (replaces `pag_script_filename`)
