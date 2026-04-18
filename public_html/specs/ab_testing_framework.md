# Specification: A/B Testing Framework (Multi-Armed Bandit)

## Overview

A platform-level multi-armed bandit (epsilon-greedy) A/B testing framework. Ships on the Joinery platform as a general capability; ScrollDaddy's marketing push is the first consumer, but nothing in this spec is ScrollDaddy-specific.

The framework is **client-side assignment, server-side reward tracking**, designed around the hard constraint that the static page cache (`StaticPageCache`, serves via `readfile()` bypassing PHP) makes server-side per-visitor variant selection impossible for exactly the pages most worth testing (marketing landing pages, pricing pages, product pages).

**Parent / sibling docs:**
- [`scrolldaddy_marketing_plan.md`](scrolldaddy_marketing_plan.md) — the marketing push that motivated building this
- [`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md) — Phase 1 prerequisites (Part D conversion events are this framework's reward signal; Part C UTM stickiness is a quality multiplier for attribution)

## Dependencies

- **Hard dependency: Part D conversion events** from the marketing infrastructure spec. No conversion events = no reward signal = no bandit.
- **Quality multiplier: Part C UTM stickiness** from the marketing infrastructure spec. Makes "which Reddit campaign drove variant B's conversions" a one-query answer. Without it, the bandit still works, just with weaker attribution.
- **Independent: Parts A, B** of the marketing infrastructure spec — nice to have shipped first, not required.

---

## Algorithm

Standard epsilon-greedy multi-armed bandit, per Steve Hanov's *"20 Lines of Code That Will Beat A/B Testing Every Time."*

For each experiment with variants V:
- Each variant tracks `trials` (times shown) and `rewards` (conversions attributed).
- On variant selection:
  - With probability **ε = 0.10**: pick a uniformly random variant (explore).
  - Otherwise: pick `argmax(rewards / trials)` (exploit).
- **Cold-start guard:** While any variant has fewer than a floor threshold (default: 100 trials), force ε = 1.0 (uniform random). Prevents one fluke early conversion from locking in a variant forever.

## Assignment persistence

When a visitor is assigned a variant, the assignment is stored in a cookie `ab_{experiment_id}` (value: variant_id) with a 30-day TTL. On subsequent visits to the same experiment, the cookie is read and the same variant is shown — so a returning visitor's conversion is attributable to the variant they originally saw.

Rationale: if a visitor sees variant B on Monday and signs up on Wednesday after seeing variant A (because assignment re-rolled), neither variant gets clean credit. Sticky assignment avoids this.

## Static cache compatibility (the key design decision)

**Problem:** `StaticPageCache` serves cached pages as raw HTML via `readfile()`. No PHP runs on a cache hit. Server-side variant selection is impossible for cached pages.

**Solution:** Render *all* variants into the cached HTML, hide them with CSS, and let inline JavaScript pick one on page load and reveal it.

Template author writes:

```php
<?php AbTest::render_variants('homepage_hero', function($variant) { ?>
    <h1><?= htmlspecialchars($variant['headline']) ?></h1>
    <p><?= htmlspecialchars($variant['subheadline']) ?></p>
    <a href="<?= htmlspecialchars($variant['cta_url']) ?>" class="btn"><?= htmlspecialchars($variant['cta_text']) ?></a>
<?php }); ?>
```

This emits (into the cached HTML):

```html
<div data-abtest="homepage_hero">
  <div data-variant="control" hidden>...</div>
  <div data-variant="short_copy" hidden>...</div>
  <div data-variant="urgency" hidden>...</div>
</div>
```

The inline `abtest.js` loader (bundled into every cached page's `<head>`) runs on page load:
1. Finds every `[data-abtest]` element.
2. For each, reads the `ab_{experiment_id}` cookie. If present, reveals that variant.
3. If no cookie, fetches the current stats for that experiment from `/ajax/ab_stats?exp={name}` (single request for all active experiments on the page, cached in memory), runs the epsilon-greedy pick, sets the cookie, reveals the variant.
4. Fires `/ajax/ab_impression` beacon (fire-and-forget) to bump `trials`.

**FOUC mitigation:** All variants start hidden via CSS (`[data-variant] { display: none; }` inline in `<head>`). The loader is inline and synchronous *before* the variant markup, so there's no unstyled flash. On first visit, the stats fetch adds ~one HTTP round-trip before reveal — acceptable for copy variants; not acceptable for above-the-fold images (out of scope for MVB).

## Reward tracking (server-side)

When a conversion event fires via the Part D hooks (see `scrolldaddy_marketing_infrastructure.md`), the server reads the `ab_*` cookies from the request and bumps `rewards` for each matching variant in each active experiment.

In `SessionControl::save_visitor_event()`, after the insert, call a new `AbTest::record_conversion_for_request($vse_type)`. The helper:
1. Enumerates active experiments where `abe_conversion_event_type = $vse_type`.
2. For each, reads `$_COOKIE['ab_' . $exp_id]`.
3. If present and the variant belongs to the experiment, increments `abv_rewards` (single UPDATE statement per match).

No client-side conversion beacon needed — the existing server-side conversion event is the source of truth. This matters for trust: client-side conversion tracking is easily gamed or lost (ad blockers, JS failures). Server-side reward matches what the admin funnel UI shows.

## Data model

**Table: `abe_experiments`**
- `abe_experiment_id` — int8 serial PK
- `abe_name` — varchar(128), unique (used as the hook key, e.g., `homepage_hero`)
- `abe_description` — text
- `abe_status` — varchar(16) — `draft` | `active` | `paused` | `crowned`
- `abe_conversion_event_type` — int2 (references `vse_type` constants)
- `abe_epsilon` — decimal(4,3), default 0.100
- `abe_cold_start_threshold` — int4, default 100
- `abe_winner_abv_variant_id` — int8 nullable (set when crowned)
- `abe_created_time`, `abe_modified_time`, `abe_delete_time`

**Table: `abv_variants`**
- `abv_variant_id` — int8 serial PK
- `abv_abe_experiment_id` — int4, indexed
- `abv_name` — varchar(64) — e.g., `control`, `short_copy`, `urgency`
- `abv_config` — json (arbitrary variant-specific data: headline, cta_text, image URL, etc.)
- `abv_trials` — int8, default 0
- `abv_rewards` — int8, default 0
- `abv_weight` — decimal(4,3), default 1.0 (optional manual weighting — ignored in MVB, reserved for later)
- `abv_created_time`, `abv_modified_time`, `abv_delete_time`

Both tables follow the standard `SystemBase` pattern (see CLAUDE.md) — `update_database` will materialize them from `$field_specifications`.

## Helper class: `includes/AbTest.php`

Static methods:

- `AbTest::render_variants(string $experiment_name, callable $render_fn): void`
  - Loads experiment + active variants.
  - If status is `crowned`, renders only the winner (strips experiment markup — no JS assignment needed).
  - Otherwise emits the `<div data-abtest>` wrapper and calls `$render_fn($variant_config_array)` once per variant, each wrapped in `<div data-variant hidden>`.
  - If experiment doesn't exist or is `draft`/`paused`, falls back to rendering only the first variant (or `control`) plain, no wrapper.

- `AbTest::record_conversion_for_request(int $vse_type): void`
  - Called from `SessionControl::save_visitor_event()` after insert.
  - Bumps rewards for matching variant(s) based on `ab_*` cookies.

- `AbTest::get_active_experiments(): array`
  - For the `/ajax/ab_stats` endpoint. Returns name + variants + trials for experiments with status `active`.

## AJAX endpoints

- **`/ajax/ab_stats.php`** — GET, returns JSON map of `{ experiment_name: { variant_id: { trials, rewards } } }` for all active experiments. Cached client-side in `sessionStorage` for the tab lifetime to avoid refetching on every page nav.
- **`/ajax/ab_impression.php`** — POST `{exp_id, variant_id}`, increments `abv_trials` by 1. Fire-and-forget from client. Rate-limited by IP + experiment + variant at ~1/sec to resist click-spamming.

Conversion endpoint deliberately **not included** — rewards are tracked server-side via the Part D conversion-event hooks.

## Client-side loader: `abtest.js`

~80-line vanilla JS file, inlined into `<head>` on pages that use `AbTest::render_variants()`. The template helper injects the `<script>` tag automatically on first `render_variants()` call per page.

Responsibilities:
1. Hide all `[data-variant]` elements via inline `<style>` (emitted before markup).
2. For each `[data-abtest]` container, read or assign the `ab_*` cookie.
3. Reveal the chosen variant (`removeAttribute('hidden')`).
4. Remove the siblings from DOM to keep the page clean.
5. Fire impression beacon.

No external framework dependency. Must be inline so there's no external-script-fetch latency before first paint.

## Admin UI: `/adm/admin_ab_experiments`

Pages:
- **List** — all experiments, with columns: name, status, total trials, leader variant, leader conversion rate, actions (view / pause / crown).
- **Edit** — experiment name, description, conversion event type dropdown (from the `vse_type` constants defined in Part D), epsilon slider, cold-start threshold.
- **Variants** — CRUD for variants within an experiment. Each variant has a name and a JSON config editor (validated as JSON, schema-free for MVB — the view template decides what fields it reads).
- **Crown action** — sets status to `crowned`, sets `abe_winner_abv_variant_id`, and from then on `render_variants()` serves only the winner. Irreversible from UI (edit in DB if you need to un-crown).

No reporting beyond the leaderboard view for MVB — the admin funnel UI handles deeper conversion analysis.

## Cache invalidation

When variants are added/edited/deleted or an experiment is crowned, the rendered HTML for pages using that experiment changes. The `StaticPageCache` must be invalidated. The simplest approach: on any variant/experiment write, call `StaticPageCache::flush_all()` (or the narrowest equivalent — investigate during implementation). Experiment edits are rare relative to page views, so flush-all is acceptable.

## First live experiment

Homepage hero headline for ScrollDaddy. Variants reflect the value-prop hypotheses from the marketing plan:
- `control` — current headline
- `short_copy` — tighter, benefit-first rewrite
- `urgency` — time/attention-loss framing

Reward event: `TYPE_SIGNUP` or `TYPE_PURCHASE` (pick during setup).

## Non-goals

- **Layout variants** — MVB supports text/CTA/image-URL variants only. Variants that shift page structure create FOUC and CLS problems; ship that later if needed.
- **Multivariate tests** — one experiment per page location, one dimension per experiment.
- **Auto-rollout** — crowning a winner is a manual admin action.
- **Segment experiments** — no "show variant A to new users, variant B to returning" logic. Possible later by hashing `vse_visitor_id`.
- **Bayesian bandits / Thompson sampling** — epsilon-greedy is enough and simpler to reason about.
- **Per-variant UTM attribution reports** — the raw data is there (conversion event rows carry UTM + cookie carries variant), but a dedicated report UI is a follow-on.
- **Dedicated client-side conversion beacon** — server-side reward tracking via conversion events is the single source of truth.

---

## Implementation Checklist

- [ ] Create data classes: `data/ab_experiments_class.php`, `data/ab_variants_class.php` (+ their Multi counterparts)
- [ ] Run `update_database` to materialize `abe_experiments`, `abv_variants` tables
- [ ] Create `includes/AbTest.php` with `render_variants()`, `record_conversion_for_request()`, `get_active_experiments()`
- [ ] Create `abtest.js` client-side loader (inline-ready, ~80 lines vanilla JS)
- [ ] Create AJAX endpoints: `/ajax/ab_stats.php`, `/ajax/ab_impression.php`
- [ ] Hook `AbTest::record_conversion_for_request()` into `SessionControl::save_visitor_event()` after insert
- [ ] Build admin UI: `/adm/admin_ab_experiments` (list, edit, variants CRUD, crown action)
- [ ] Wire cache invalidation: flush `StaticPageCache` on experiment/variant writes
- [ ] First live experiment: homepage hero headline variants (ScrollDaddy)
- [ ] Test: cold-start (< threshold trials forces random), warm bandit (argmax picks correctly), sticky assignment (cookie returns same variant), conversion attribution (purchase increments correct variant rewards), crown (serves winner only, no JS, no wrapper markup)

### Documentation

- [ ] Add a new section to `docs/` covering `AbTest::render_variants()` usage from view templates, the admin UI walkthrough, and how variants compose with the static cache. Since this is platform-level (not plugin-level), the right home is a new `docs/ab_testing.md` rather than folding into `docs/scrolldaddy_plugin.md`.
