# A/B Testing

Platform-level multi-armed bandit (epsilon-greedy) A/B testing. Tests attach to **entities** — any `SystemBase` data class that opts in. No per-test code: admins configure variants and crown winners from a reusable admin panel.

- Server-side assignment, server-side reward tracking.
- Sticky cookie persists a visitor's variant for 30 days.
- Pages rendering a tested entity automatically bypass the static cache.
- Bot traffic is filtered by `SessionControl::crawlerDetect()` — both trials and rewards inherit this filter by construction.

Spec: [`/specs/ab_testing_framework.md`](../specs/ab_testing_framework.md).

## Opting an entity in

Add two static properties to the data class. No schema change on the entity's own table — all bandit state lives in `abt_tests` and `abv_variants`.

```php
class Post extends SystemBase {
    public static $ab_testable = true;
    public static $ab_testable_fields = ['pst_title', 'pst_body', 'pst_cta_text'];
    // rest unchanged
}
```

## Wiring the render hook

One call in the public view, **before** any testable field is read:

```php
$post = new Post($id, TRUE);
AbTest::apply_variant($post);   // no-op if no active test
echo $post->get('pst_title');   // returns the variant's override if one is assigned
```

`apply_variant()` is a no-op if:
- the entity's class has no `$ab_testable` property,
- no test row is attached to `(entity_type, entity_id)`,
- or the test status is not `active`.

When active, it marks the URL `nostatic` via `StaticPageCache`, reads / writes the `ab_{test_id}` cookie, stashes the assignment, and overrides the entity's fields in memory.

## Mounting the admin panel

On the entity's existing admin edit page:

```php
if (!empty(Post::$ab_testable)) {
    require_once(PathHelper::getIncludePath('data/abt_tests_class.php'));
    AbTestVersionsPanel::render('Post', $post_id);
}
```

The panel renders:
- Status header (draft / active / paused / crowned).
- Activate / Pause / Crown buttons.
- Leaderboard: trials, rewards, rate per variant; leader highlighted.
- Variants CRUD — inputs auto-generated from `$ab_testable_fields`.
- Test settings (conversion event type, epsilon, cold-start threshold).
- Reset-counters button (explicit on-demand zeroing — otherwise counters persist across pause/activate cycles).

The global cross-entity list is at `/admin/admin_ab_tests`.

## Cache lifecycle

Entities may declare `get_tested_cache_urls(): array` to opt into targeted cache invalidation. Without it, lifecycle transitions fall back to `StaticPageCache::clearAll()` (correct but coarse).

| Event | Cache action |
|-------|--------------|
| Created (draft) | — |
| Activated | invalidate per-URL (or clearAll) |
| Paused | invalidate per-URL |
| Crowned | invalidate per-URL |
| Deleted (was active) | invalidate per-URL |
| Variant add/edit (while active) | — (page is already nostatic) |

The nostatic flag is set on every render through `apply_variant()`; invalidating the cached file by deleting it also removes the nostatic entry (see `StaticPageCache::invalidateUrl()`).

## Cookie semantics

- Name: `ab_{test_id}`
- Value: `{variant_id}`
- TTL: 30 days, rolling per-visitor
- Flags: `Secure; HttpOnly; SameSite=Lax; Path=/`

SameSite=Lax is deliberate — it lets the assignment persist on top-level navigation from email, search, and social, which is what attribution needs.

## Reward attribution

Rewards are attributed to the variant the visitor's cookie points to on the conversion event, regardless of which page they converted on. This is **exposure-based attribution** — the standard for A/B tests — not causal measurement. A visitor who saw variant B on page X and converts later on page Y attributes that conversion to B.

Trials and rewards both go through `SessionControl::save_visitor_event()`, so they share a single bot filter and a single eligibility criterion. The ratio `rewards / trials` is always computed over the same population.

## Shared-entity disclosure

Entities that can appear in multiple contexts (`PageContent`, referenced by multiple pages via `pag_component_layout`) should declare `get_test_contexts(): array` returning `[ ['label' => '...', 'url' => '/admin/...'], ... ]`. The admin panel surfaces this list so admins know a test will affect every page the component lives on.

## Layout tests

Pages are testable on `pag_title`, `pag_body`, and `pag_component_layout`. Reorder / show-hide / swap / replace all map to different values of the same JSON array — no special framework support needed.

## Side-effects caveat

Entity `save()` implementations used by `$ab_testable` classes must not perform external side effects (email, webhook, outbound API) — the crown action wraps the parent-save in a DB transaction, and side effects fire regardless of rollback. Today's opt-ins (`Page`, `PageContent`) only write to the DB.
