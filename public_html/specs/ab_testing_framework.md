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

**Cookie attributes.** Set with explicit flags — don't rely on PHP defaults:

```php
setcookie('ab_' . $test_id, $variant_id, [
    'expires'  => time() + 30 * 86400,
    'path'     => '/',
    'secure'   => true,    // HTTPS only — all Joinery sites are HTTPS
    'samesite' => 'Lax',   // sent on top-level nav (preserves assignment on inbound links)
    'httponly' => true,    // no JS needs to read it
]);
```

Domain is intentionally omitted (defaults to the current host — no cross-subdomain leakage).

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
    4. Records the `(test_id, variant_id)` pair in a request-scoped static array on `AbTest` (for the visitor-event hook to flush — see Trial + reward accounting below).
    5. Calls `$entity->set($field, $value)` for each field in `abv_overrides`.

`apply_variant()` does **not** write to the database itself. All DB accounting (trials and rewards) happens inside `SessionControl::save_visitor_event()` after that method's existing bot filter passes. See Trial + reward accounting.

Admin forms don't call `apply_variant()`, so they always edit the parent's stored values directly — variants are edited through the dedicated UI component below.

## Static cache interaction

`StaticPageCache` serves cached pages as raw HTML via `readfile()` — no PHP runs on a cache hit, making server-side variant selection impossible for cached pages.

**Solution: mark pages rendering a tested entity as nostatic on first render.**

`AbTest::apply_variant()` calls `StaticPageCache::markAsNostatic($_SERVER['REQUEST_URI'])` when the test is active. On all subsequent requests, `RouteHelper` sees the nostatic flag and skips the cache, so PHP runs normally and assignment is server-side.

On the very first request after a test is activated, there may be a stale cached page in place. The activation lifecycle event handles this.

## Test lifecycle and cache events

Cache invalidation is scoped per-entity where possible. `StaticPageCache` already exposes `invalidateUrl($url)` (deletes the single cached file AND removes its index entry, which also clears any nostatic flag at that hash) — so a Page-level test activation invalidates just that page's cache entry, not the whole site's.

Tested entities opt in to targeted invalidation by defining `get_tested_cache_urls(): array`. `AbTest::invalidate_cache_for_test($test)` is the single dispatcher all lifecycle transitions call:

```php
public static function invalidate_cache_for_test(AbTest $test) {
    $class = $test->get('abt_entity_type');
    $entity = new $class($test->get('abt_entity_id'), TRUE);
    if (method_exists($entity, 'get_tested_cache_urls')) {
        foreach ($entity->get_tested_cache_urls() as $url) {
            StaticPageCache::invalidateUrl($url);
        }
    } else {
        StaticPageCache::clearAll();
    }
}
```

`Page::get_tested_cache_urls()` returns `[$this->get_url()]`. `PageContent` does not declare it — a PageContent can appear on multiple pages (once `pac_pag_page_id` is dropped), and the targeted cross-page invalidation is deferred until someone actually needs it. For now component-level tests hit the `clearAll()` fallback, which is correct but coarse.

| Event | Cache action | Counter action | Why |
|-------|-------------|----------------|-----|
| **Created** (→ draft) | None | — | Draft tests don't affect rendering |
| **Activated** (→ active) | `invalidate_cache_for_test()` | Preserved | Busts any stale cached page(s); nostatic flag will be set on next render. Counters carry over from any prior active run |
| **Variant added/edited** (while active) | None | Preserved | Page is already nostatic; PHP runs on every request, picks up changes immediately |
| **Variant deleted** (while active) | None | — | Same as above |
| **Paused** (→ paused) | `invalidate_cache_for_test()` | Preserved | Clears nostatic flag so the page (now rendering parent-only) can be cached again. Counters remain so a pause-and-resume cycle doesn't throw away learning |
| **Crowned** (→ crowned) | `invalidate_cache_for_test()` | Preserved (historical) | Winner's `abv_overrides` are copied onto the parent entity; clears nostatic flag; next request renders the winner's content and caches it |
| **Deleted** (was active) | `invalidate_cache_for_test()` | — | Clears nostatic flag |
| **Deleted** (was draft/paused/crowned) | None | — | No nostatic flag was set |
| **Reset counters** (explicit admin action) | None | Zeroed (all variants) | On-demand only — zeroes `abv_trials` and `abv_rewards` across every variant of the test. Confirmation dialog required. Use case: the admin paused to fix a variant and wants a clean baseline for the next run |

## Trial + reward accounting (server-side, bot-filtered)

All DB accounting for both trials and rewards is funneled through `SessionControl::save_visitor_event()`. That method already short-circuits for crawlers via `crawlerDetect()` (see *Prerequisite: fix `crawlerDetect()`* below), so every bandit UPDATE inherits the platform's canonical bot filter for free. Two consequences:

- **No duplicated bot regex** in `AbTest`.
- **Trials and rewards share the same eligibility criterion by construction** — if a request isn't a countable visitor event, it doesn't move either counter. The ratio `rewards / trials` is always computed over the same population.

Wiring:

1. During render, `AbTest::apply_variant()` records each `(test_id, variant_id)` it assigns into a static array on `AbTest` (request-scoped memory only, no DB write).
2. `SessionControl::save_visitor_event()` is modified to call `AbTest::flush_request_accounting($type)` after the existing bot/404/validity checks pass and the visitor event has been inserted.
3. `AbTest::flush_request_accounting($vse_type)`:
   1. For each `(test_id, variant_id)` stashed during this request, increments `abv_trials` (single UPDATE per variant).
   2. For each active test matching `abt_conversion_event_type = $vse_type`, reads `$_COOKIE['ab_' . $test_id]`; if the cookie points to a variant that belongs to that test, increments `abv_rewards` (single UPDATE per match).
   3. Clears the stash.

Trials are thus counted once per qualifying visitor event regardless of how many times `apply_variant()` was called during the request (deduped by `(test_id, variant_id)`), which matters if a page includes both a tested Page and a tested PageContent component that end up sharing a request.

Conversion rewards do **not** require the visitor to have a stashed assignment on the conversion request — they come from the sticky `ab_*` cookie, so a visitor who saw variant B on page X (trial counted then) and later converts on a checkout event on page Y (reward counted on the checkout event's `save_visitor_event`) is properly attributed.

No client-side beacon; the existing server-side visitor-event pipeline is the single source of truth.

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
- `abv_overrides` — json — keys restricted to the parent entity's `$ab_testable_fields`; values match the field's storage type (strings for text fields, arrays for JSON fields, etc.). **Absence = inherit**: a field *not present* as a key means the variant inherits the parent entity's current value for that field. A key present with an empty string means "override to empty" (an explicit choice). Never conflate the two.
- `abv_trials` — int8, default 0
- `abv_rewards` — int8, default 0
- `abv_created_time`, `abv_modified_time`, `abv_delete_time`

Both tables follow the standard `SystemBase` pattern — `update_database` will materialize them from `$field_specifications`.

**JSON-field handling for testable fields.** `SystemBase::load()` does not auto-decode JSON columns — `get()` returns the raw JSON string straight off the DB. `save()` does auto-encode arrays/objects on the way back. This asymmetry (string post-load vs. array post-`set()`) matters for any JSON-typed field in `$ab_testable_fields` (most notably `pag_component_layout`).

**Base-class precursor — add `SystemBase::get_json_decoded($key)`:** a small, opt-in helper that returns decoded PHP for JSON strings, returns the value as-is if already decoded (post-`set()`), returns `null` for null/empty, and falls back to the raw string if decode fails. Nothing changes unless a caller opts in — zero blast radius against the 14+ existing `json_decode($obj->get(...), true)` sites. Added once in `includes/SystemBase.php` and then used by this spec's code plus any future JSON-field work that wants to simplify.

```php
function get_json_decoded($key) {
    $value = $this->data->$key ?? null;
    if (!is_string($value) || $value === '') return $value;
    $decoded = json_decode($value, true);
    return $decoded !== null ? $decoded : $value;
}
```

With that in place, the A/B wiring for JSON-typed fields becomes:

- `AbTest::apply_variant()` calls `$variant->get_json_decoded('abv_overrides')` once before iterating override pairs. Inner values are correctly-typed PHP after the single decode and can be passed straight to `$entity->set()`.
- Rendering code for a JSON-typed testable field must tolerate both "string" (fresh load, no variant applied) and "array" (variant applied via `set()`). Add a typed getter like `Page::get_component_layout()` that delegates to `$this->get_json_decoded('pag_component_layout') ?: []`, and have renderers read through it rather than through raw `get()`.
- `AbTestVersionsPanel`'s variant edit form must JSON-parse structured field values on submit before writing them into `abv_overrides`, so the stashed value is a real PHP array, not a double-encoded string.

**Class naming.**
- `AbTest` / `MultiAbTest` — data class for `abt_tests` (prefix `abt`). Also hosts the static runtime methods (`apply_variant()`, `flush_request_accounting()`, etc.), following the standard Joinery pattern where a data class carries its own static helpers alongside instance behavior (see `Page::get_by_link()`, `User::*`, etc.).
- `AbTestVariant` / `MultiAbTestVariant` — data class for `abv_variants` (prefix `abv`).
- `AbTestVersionsPanel` — admin UI component, lives in the same file as `AbTest` since it is the admin surface of the runtime and trivially small.

## Runtime API and admin panel

Defined in `data/abt_tests_class.php` alongside the `AbTest` data class itself — the runtime is static methods on the same class that models a test row, following standard Joinery pattern. `AbTestVersionsPanel` lives in the same file since it is the admin surface of the runtime and trivially small; splitting later is a one-line move if it grows.

**`AbTest` — static runtime methods:**

- `AbTest::apply_variant(SystemBase $entity): void` — public-render hook; selects variant, overrides fields in memory, stashes assignment for the visitor-event flush. Does not touch the DB.
- `AbTest::flush_request_accounting(int $vse_type): void` — invoked by `SessionControl::save_visitor_event()` after its bot filter passes; commits trials for stashed assignments and rewards for matching conversion events.
- `AbTest::get_active_test_for_entity(string $entity_class, int $entity_id): ?AbTest` — helper for the admin UI to determine current test state.
- `AbTest::copy_winner_onto_parent(AbTest $test): void` — invoked by the crown action; reads winner's `abv_overrides`, sets each field on the parent entity, saves it.
- `AbTest::invalidate_cache_for_test(AbTest $test): void` — dispatcher called from every lifecycle transition; uses the entity's `get_tested_cache_urls()` if defined (targeted per-URL invalidation), else falls back to `StaticPageCache::clearAll()`.

**`AbTestVersionsPanel` — admin UI component, one static method:**

- `AbTestVersionsPanel::render(string $entity_class, int $entity_id): void` — outputs the admin panel HTML for a testable entity. See the Admin UI section below for what it renders.

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
- **Variants section** — CRUD for variants. Form auto-generates inputs from the entity's `$ab_testable_fields`: one input per field (labels title-cased: `pst_cta_text` → "Cta Text"), per variant. Each field has an "Override for this variant?" checkbox; when checked, the input is revealed and the key is written to `abv_overrides` on save. When unchecked, the key is omitted entirely — that's how "inherit from parent" is encoded. Admins who genuinely want to test an empty value check the box and leave the field blank; the distinction between "not overridden" and "overridden to empty" is preserved.
- **Leaderboard** — per-variant: trials, conversions, conversion rate. Leader highlighted. Includes a secondary **Reset counters** button (confirmation dialog required) that zeroes `abv_trials` and `abv_rewards` across every variant of this test. Counters are preserved by default through pause/activate cycles — reset is explicit and on-demand only.
- **Test settings** (collapsible) — conversion event type (dropdown from `VisitorEvent::TYPE_*` constants), epsilon, cold-start threshold.
- **Shared-entity disclosure** — for entity types that can appear in multiple contexts (e.g., `PageContent`, which is referenced by `pag_component_layout` arrays across pages once `pac_pag_page_id` is dropped), the panel renders a pre-test disclosure listing every context the entity appears in:

    > **This component appears on:** *(list of pages with deep-links)*. Launching a test will affect all of them.

    When the list has more than one entry and the admin clicks "Start test" or "Activate", an extra confirmation step surfaces the same list and requires an explicit acknowledgement. Single-context entities render a collapsed/muted version of the list — informative but not interruptive. Testable entities declare their contexts via an optional instance method `get_test_contexts(): array` returning `[['label' => 'Page title', 'url' => '/page/slug'], ...]`; `PageContent::get_test_contexts()` does the `pag_component_layout::jsonb @> to_jsonb($this->key)` query. Entities that don't declare the method render no disclosure.

### Cross-entity list: `/adm/admin_ab_tests`

Global view of all tests across all entity types. Sortable columns: entity type, entity link (deep-links to the entity's edit page where the panel lives), status, trials, leader, conversion rate, started date. No reporting beyond the leaderboard — the admin funnel UI handles deeper conversion analysis.

**Duplicate-active-test sanity check.** The list page runs one `GROUP BY abt_entity_type, abt_entity_id HAVING COUNT(*) > 1` query across rows where `abt_status = 'active' AND abt_delete_time IS NULL`. If any group comes back, the page renders a red warning banner at the top:

> **Warning:** N entities have more than one active test. This should never happen; it usually indicates a race during test activation. Affected entities: {list with deep-links}. Review each and soft-delete the duplicate(s).

Rows in affected groups are also badged inline in the list. The warning is cheap insurance for a state the DB isn't preventing — a safety net for the rare race the UI's SELECT-then-INSERT check can't close on its own. If this warning ever fires repeatedly in practice, that's the signal to revisit adding a partial unique index to `update_database`.

### Crown action

Sets status to `crowned`, sets `abt_winner_abv_variant_id`, **copies the winner's `abv_overrides` onto the parent entity's fields and saves the parent**, calls `invalidate_cache_for_test()`. After crowning, the test is historical and the parent renders the winner's content as its own stored state. Irreversible from UI (edit in DB if you need to un-crown).

**Atomicity.** The status update, winner assignment, and parent save are wrapped in a single DB transaction so the system cannot end up in a half-crowned state if any step fails. Cache invalidation runs only after commit — cache is a filesystem side effect and doesn't belong inside the transaction. Sketch:

```php
$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();
try {
    $test->set('abt_status', 'crowned');
    $test->set('abt_winner_abv_variant_id', $winner_id);
    $test->save();
    AbTest::copy_winner_onto_parent($test);   // sets+saves parent
    $dblink->commit();
} catch (\Throwable $e) {
    $dblink->rollBack();
    throw $e;
}
AbTest::invalidate_cache_for_test($test);
```

**Side-effects caveat.** Any entity opted into `$ab_testable` must not perform external side effects (email, webhook, outbound API) inside `save()` — those commit regardless of transaction rollback. Verify per entity at opt-in time. For the current opt-ins (`Page`, `PageContent`), save() only writes to the DB (including `ContentVersion::NewVersion`, which joins the transaction correctly).

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

## Prerequisite: Fix `SessionControl::crawlerDetect()`

The bandit's trial/reward accounting piggybacks on `save_visitor_event()`'s existing bot filter. That filter is currently broken — `crawlerDetect()` at `includes/SessionControl.php:292-325` does:

```php
$crawlers_agents = implode('|', $crawlers);   // "Google|msnbot|Rambler|Yahoo|..."
if (strpos($crawlers_agents, $USER_AGENT) === false)
    return false;
```

The arguments to `strpos` are reversed: it's searching the short bot-name haystack for the full UA string as a needle. A realistic Googlebot UA like `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)` cannot possibly appear as a substring of the pipe-joined bot list, so `strpos` returns `false` and the method returns `false` — i.e., reports *not a crawler* — for every real bot on the planet. Nothing has been filtered.

**Fix:** invert the search — iterate the bot-name list and check whether any of them appears in the UA:

```php
public function crawlerDetect($USER_AGENT) {
    if (empty($USER_AGENT)) return true;   // no UA at all → treat as bot
    $crawlers = [
        'Googlebot', 'bingbot', 'Baiduspider', 'YandexBot', 'DuckDuckBot',
        'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Slackbot',
        'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot',
        'crawler', 'spider', 'bot/', 'bot ', 'Bot/', 'Bot ',
        // ...expand as needed
    ];
    foreach ($crawlers as $pattern) {
        if (stripos($USER_AGENT, $pattern) !== false) return true;
    }
    return false;
}
```

Notes on the fix:
- Replace the legacy keyed-array (`'Google' => 'Google'`) with a flat list of substring patterns; the keys were unused.
- Use `stripos` (case-insensitive) — bot UA casing varies.
- Expand the pattern list — the existing list misses nearly every common modern bot (Googlebot, Bingbot, Ahrefs, Semrush, Slack, Twitter, Facebook, LinkedIn, etc.).
- Treat empty UA as a bot (common for some scrapers and default curl calls).

This fix benefits everything that currently calls `save_visitor_event()` — analytics pageview counts, conversion funnels, attribution reports — not just the bandit. It's a live bug that has been silently letting bot traffic into visitor events for as long as this code has existed; the bandit work is simply what surfaced it.

**Verification:** after the fix, spot-check `vse_visitor_events` to confirm bot UAs are no longer appearing, and sanity-check that analytics totals drop by a plausible amount (bots are typically 20-40% of raw pageviews on a small site).

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

The work sequences in four phases: the `crawlerDetect()` fix and legacy content cleanup are both prerequisites, then the A/B framework proper, then opt-ins. Each phase's work ships as a coherent unit. The `crawlerDetect()` fix is independent of the legacy-content work and can land first (it's also useful on its own).

### Phase 0 — Fix `crawlerDetect()` bot filter

- [ ] Rewrite `SessionControl::crawlerDetect()` per the Prerequisite section: flat substring list, `stripos` loop, expanded bot patterns, empty-UA treated as bot
- [ ] Spot-check `vse_visitor_events` after deploy to confirm common bot UAs are no longer appearing
- [ ] Sanity-check analytics totals drop by a plausible amount relative to pre-fix baseline

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

- [ ] Add `SystemBase::get_json_decoded($key)` helper (one method, no existing callers affected — opt-in from here forward)
- [ ] Create data classes: `data/abt_tests_class.php` (`AbTest` / `MultiAbTest` + static runtime methods + `AbTestVersionsPanel`), `data/abv_variants_class.php` (`AbTestVariant` / `MultiAbTestVariant`)
- [ ] Run `update_database` to materialize `abt_tests`, `abv_variants` tables
- [ ] Implement on `AbTest`: `apply_variant()` (memory-only, stashes assignment; no DB write), `flush_request_accounting()` (commits trials + rewards from stash), `get_active_test_for_entity()`, `copy_winner_onto_parent()`, `invalidate_cache_for_test()` (dispatcher — targeted `invalidateUrl()` via `get_tested_cache_urls()` if defined, else `clearAll()` fallback), and `AbTestVersionsPanel::render()` (status header, variants CRUD, leaderboard, settings) — wires `invalidate_cache_for_test()` to all status transition actions per the lifecycle table
- [ ] Hook `AbTest::flush_request_accounting($type)` into `SessionControl::save_visitor_event()` after the existing bot/404/validity checks pass and the visitor event has been inserted
- [ ] Build `/adm/admin_ab_tests` cross-entity list, including the duplicate-active-test sanity-check banner (`GROUP BY entity_type, entity_id HAVING COUNT(*) > 1` over active/non-deleted rows)

### Phase 3 — Opt-ins

- [ ] Opt `Page` into testing: add `$ab_testable` + `$ab_testable_fields = ['pag_title', 'pag_body', 'pag_component_layout']` to `data/pages_class.php`, define `Page::get_tested_cache_urls()` returning `[$this->get_url()]`, call `AbTest::apply_variant($page)` in `views/page.php` (+ themed overrides), mount `AbTestVersionsPanel` on the Page admin edit page
- [ ] Create Page record for homepage content; set `alternate_homepage = /page/home`
- [ ] Opt `PageContent` into testing: `$ab_testable = true`, `$ab_testable_fields = ['pac_body']`; define `PageContent::get_test_contexts()` returning every page whose `pag_component_layout` references this PageContent (JSON containment query); call `AbTest::apply_variant()` inside `ComponentRenderer::render_component()`; mount `AbTestVersionsPanel` on the component admin edit page

### Phase 4 — Verification

- [ ] Test: cold-start (< threshold forces random), warm bandit (argmax picks correctly), sticky assignment (cookie returns same variant), conversion attribution (event increments correct variant rewards), crown (copies winner fields onto parent, clears nostatic, page re-caches), activate/pause cycle (nostatic set on activate, cleared on pause, page re-caches)
- [ ] Test bot filtering end-to-end: with the fixed `crawlerDetect()`, hit a tested page with a real bot UA (Googlebot, Bingbot) and confirm `abv_trials` does NOT increment; hit with a real browser UA and confirm it does. Confirm the same for rewards via a conversion event.
- [ ] Test layout testing end-to-end: create a Page with multiple components, create a test with reorder + show-hide + swap variants, confirm cookie-sticky assignment and reward attribution work across all three shapes

### Documentation

- [ ] **New — `docs/ab_testing.md`:** opt-in pattern (static properties on entity), `AbTest::apply_variant()` usage, admin UI walkthrough, cache lifecycle, cookie semantics, reward attribution semantics (leaderboard counts conversions by visitors whose cookie points to the variant, regardless of which page they converted on — exposure-based attribution, standard to all A/B frameworks, not causal measurement).
- [ ] **Update `docs/component_system.md`:** reflect removal of `pac_pag_page_id`, `pac_order`, and the legacy placeholder system; document `pag_component_layout` as the canonical ordering mechanism.
- [ ] **Update `docs/routing.md`:** add `pag_template` as the mechanism for dynamic-content pages (replaces `pag_script_filename`) — views/page.php delegates to `theme/*/views/{pag_template}.php` when set.
- [ ] **Update `docs/analytics.md`:** note the `crawlerDetect()` fix and its effect on visitor-event counts (bot traffic was silently being counted before; totals will drop by a plausible fraction post-fix). Relevant to anyone comparing pre- and post-fix analytics numbers.
- [ ] **Update `CLAUDE.md`'s SystemBase reference section:** add `get_json_decoded($key)` alongside the existing `get()` / `set()` listings — short description + one-line usage example. Wider codebase benefits beyond A/B testing.
