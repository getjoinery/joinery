# Specification: Multiple Domain Capability

## Overview
Joinery is currently a single-branded, single-domain platform: one deployment serves one brand on one domain, with one theme. This spec makes the platform natively multi-brand — one deployment can serve many domains, each with its own theme, copy, and pricing, while sharing a single database, single user pool, and single billing pipeline.

**Guiding principle:** this is platform infrastructure, not a scrolldaddy feature. The brand abstraction is generic. ScrollDaddy becomes the first brand (`brand_id=1`); any future product is another brand.

## Why now
Three separate pressures converge:
1. **Product:** ScrollDaddy's underlying service has multiple distinct markets (consumer digital-addiction, schools, law firms) that need different brands, pricing, and marketing angles.
2. **Architecture:** Doing it later means retrofitting brand-context plumbing into every cross-cutting system. Doing the groundwork now is much cheaper.
3. **Platform:** Joinery should support multi-site natively — other future clients on docker-prod may want the same capability.

## Core architectural decision
**Option B from the multi-brand analysis: domain-dispatching multi-tenant Joinery.** One deployment, one DB, many domains. Rejected alternatives:

- **Option A (thin marketing fronts + shared backend API):** faster to ship for one extra brand, but duplicates infrastructure for each additional brand and doesn't generalize into a platform capability.
- **Option C (separate deployments + Postgres replication):** pushes complexity into the infrastructure layer, fragments admin and analytics, and doesn't help other Joinery clients.

Option B is more work upfront but is the only option that produces a reusable platform capability.

---

## Product decisions

| # | Question | Answer |
|---|---|---|
| 1 | Users scoped per brand or unified? | **Unified.** Email uniqueness stays global; one user record represents one human regardless of how many brands they touch. `usr_brd_brand_id` records the user's signup source / primary brand affiliation — informational, not a partition key. Brand isolation lives in the brand-scoped data tables (devices, subscriptions, blocks, etc.), not in the user table. |
| 2 | Admin staff cross-brand? | Admin permission is global, exactly as today. The active brand context per request comes from `HTTP_HOST`; admins manage whichever brand they're looking at. Per-brand admin restrictions can layer on later if needed; not required for launch. |
| 3 | Billing | **One Stripe account** across all brands. Stripe customer objects carry `brand` metadata. Invoice branding (logo, from-address, customer portal URL) varies per brand. |
| 4 | Org-level / B2B accounts | **Out of scope for this spec.** A future B2B brand (schools, law firms) needing shared billing and an internal admin role will get its own org-layer spec when there's an actual B2B sales pipeline. Until then, every user is an individual account, even on "B2B-flavored" brand variants. |
| 5 | Feature divergence between brands | **Same features in v1.** Divergence mechanism: per-brand plugin activation + per-brand settings overrides. No feature-flag engine. |

---

## Data model

### New tables

**`brd_brands`** — the central brand/domain record
- `brd_brand_id` — int4 serial PK
- `brd_code` — varchar(32), unique (stable machine identifier, e.g., `scrolldaddy`, `schoolfilter`)
- `brd_display_name` — varchar(128) (e.g., "ScrollDaddy", "SchoolFilter")
- `brd_primary_domain` — varchar(255), unique (e.g., `scrolldaddy.app`)
- `brd_domain_aliases` — json (array of additional domains that resolve to this brand)
- `brd_theme` — varchar(64) (theme slug — overrides global `theme_template`)
- `brd_config` — json (per-brand settings overrides: email sender, logo URL, ToS URL, stripe price IDs, etc.)
- `brd_status` — varchar(16) — `active` | `inactive` | `archived`
- `brd_created_time`, `brd_modified_time`, `brd_delete_time`

### Modifications to existing tables

- `usr_users` → add `usr_brd_brand_id` (int4, **nullable** — records signup source / primary brand). Email uniqueness stays global; this column is informational, not part of any unique constraint.
- `stg_settings` → add `stg_brd_brand_id` (int4, nullable). Null = global default; non-null = brand override. Lookup resolves brand-first, global-fallback.
- `abe_experiments` (from marketing infra spec) → `abe_brd_brand_id` nullable. Already specified.
- `vse_visitor_events` → add `vse_brd_brand_id` (int4, nullable). Set from `BrandContext` at write time.
- `ccd_coupon_codes` → add `ccd_brd_brand_id` (int4, nullable). Null = global coupon; non-null = brand-scoped.
- `sdd_devices` → add `sdd_brd_brand_id` (int4, nullable). Stamped from `BrandContext` at create time. Determines which brand "owns" the device for admin and billing display.
- `plg_plugins` → per-brand activation deferred until divergence work begins. `plg_status` stays global.

### Brand-scoped data, generally
Tables that hold per-brand data (devices, subscriptions, blocks, settings overrides, etc.) gain their own `*_brd_brand_id` column, stamped from `BrandContext` at write time. Multi queries on those tables filter by the table's own brand column, not via user. The user table is global; brand isolation is enforced at the data tables.

### DNS resolver impact: **zero**
The resolver queries devices by `sdd_resolver_uid` (device-scoped, globally unique) and reads block rules tied to the device. Brand never enters the query. Brand is a presentation-layer concept, not a resolver concept.

---

## Request dispatch

### `BrandContext` singleton

New class `includes/BrandContext.php`, populated once per request from `HTTP_HOST`:

```php
class BrandContext {
    public static function get_instance(): self;
    public function get_brand_id(): ?int;       // null if no brand resolved (e.g., CLI, cron)
    public function get_brand(): ?Brand;        // lazy-loaded Brand object
    public function get_theme(): string;
    public function get_config(string $key, $default = null);  // brand_config, fallback to global
}
```

### Hook in `serve.php`

Early, before routing:

```php
BrandContext::get_instance();  // resolves HTTP_HOST, stashes brand
```

Zero overhead for requests (one table lookup, cached in session after first resolution).

### Theme resolution

`ThemeHelper::getInstance()` today reads `$settings->get_setting('theme_template')` globally. Changes to:

```php
$theme = BrandContext::get_instance()->get_theme()
    ?? $settings->get_setting('theme_template');  // backward compat fallback
```

### Settings resolution

`Globalvars::get_setting($name)` grows a brand-aware overload. Resolution order:

1. Brand-scoped setting (`stg_settings WHERE stg_name=? AND stg_brd_brand_id=?`)
2. Global setting (`stg_settings WHERE stg_name=? AND stg_brd_brand_id IS NULL`)
3. Provided default

An explicit `$settings->get_global_setting($name)` stays for code that genuinely wants the global (e.g., infrastructure settings like `composerAutoLoad`).

### Query scoping

Brand-scoped data tables (each has its own `*_brd_brand_id` column) filter by `BrandContext::get_brand_id()` automatically. The user table is *not* brand-scoped — it's global.

Approach: extend `SystemMultiBase::_get_resultsv2()` to apply an implicit brand filter when the class declares `$brand_scoped = true`. The filter targets the table's own brand column. Classes that hold cross-brand or system data (`plg_plugins`, `brd_brands`, `usr_users`, themes, etc.) leave the flag off. Individual call sites can pass `['is_cross_brand' => true]` to opt out for cross-brand admin tooling.

### The null-brand rule
`BrandContext::get_brand_id()` returns `null` for:
- CLI scripts (no `HTTP_HOST`)
- Cron / scheduled tasks
- Requests to unrecognized hosts

In those contexts, brand-scoped Multi queries skip the filter only when the caller declares cross-brand intent; otherwise they return no rows. This makes "I forgot to set brand context" a loud failure mode rather than silent cross-brand data exposure.

---

## Billing details

One Stripe account, one set of products. Per-brand:
- Different **price IDs** (brands can have different prices for the same underlying product; the price ID catalog lives in `brd_config`).
- Different **branding** on receipts: logo, from-address, customer portal URL configured on the Stripe customer or session object, sourced from `brd_config`.
- Metadata on every Stripe customer/subscription/payment: `brand=<code>` for downstream reporting.

Webhooks from Stripe route to existing handlers; the handlers look up the Stripe customer metadata to determine brand context (since the request doesn't have `HTTP_HOST` set to a brand domain).

---

## Admin UI

### Brand dimension on list pages
Every admin list page that lists brand-scoped data gains a brand filter (dropdown, default = current brand context, "All Brands" option for cross-brand views).

### New admin pages
- `/adm/admin_brands` — CRUD for `brd_brands` rows.
- Existing `/adm/admin_users` — gains a brand column (the user's signup source) and an optional brand filter.

### Permission
Admin permission stays global as today. The brand a request operates on comes from `HTTP_HOST`, not from the admin user's record. Per-brand admin restrictions can layer on top of the existing permission model later if a real need emerges.

---

## Feature divergence strategy

**v1 position: same features across all brands.** Copy, pricing, and emphasis vary via theme/settings, not via code.

**Divergence mechanism when it's eventually needed:**

1. **Plugin activation per brand.** A plugin becomes the unit of divergence. Schools-specific features live in a `schools` plugin, activated only for brands where `brd_code = 'schoolfilter'`. Law-firm features live in a `compliance` plugin. Consumer brands activate neither. (The `pla_plugin_activations` table is built when this is actually needed.)
2. **Settings-driven UI variants.** Within a shared plugin, per-brand settings toggle UI elements (e.g., `show_family_mode = true` on consumer; `show_device_groups = true` on B2B). Cheap, no new engine.
3. **NOT** building: a feature-flag framework, per-brand code forks, a brand-specific route dispatcher, a per-brand view-override layer beyond what themes already provide.

The firm line: **brand is configuration, not code.** If a feature can't be built by combining (theme × plugin activation × settings), that's a signal the platform needs a new extension point, not a per-brand fork.

---

## Migration / rollout

The existing scrolldaddy deployment becomes `brand_id=1` with `brd_code='scrolldaddy'`. Backfill strategy:

1. Create `brd_brands` table, insert one row for scrolldaddy with current settings.
2. Add `usr_brd_brand_id` column, backfill all existing users to `1`.
3. Add `stg_brd_brand_id` column (stays null — existing settings become global defaults).
4. Add `*_brd_brand_id` columns to brand-scoped data tables (`sdd_devices`, `vse_visitor_events`, `ccd_coupon_codes`); backfill existing rows to `1`.
5. Ship `BrandContext` + `HTTP_HOST` dispatch — for single-domain deployments, the resolved brand is just `brand_id=1` and nothing visible changes.
6. Roll out the second brand domain pointed at the same deployment.
7. Iterate.

**No data migration is destructive.** Every change is additive. A rollback is: revert the serve.php hook; everything keeps working against `brand_id=1`.

---

## Pre-launch priorities

Not all of this work has the same urgency relative to public launch. The list below distinguishes changes that produce breaking changes if deferred from changes that are genuinely additive and can ship after launch.

### Must land before public launch — breaking changes if retrofitted

1. **`BrandContext` singleton + `serve.php` HTTP_HOST dispatch** — the chokepoint that makes everything else clean. Without it, code accumulates implicit "the one brand" assumptions across hundreds of files. Even resolving to `brand_id=1` always, every future call site is already brand-aware.
2. **Brand-aware `SystemMultiBase` scoping mechanism** — the `$brand_scoped = true` class-level flag and the `_get_resultsv2()` filter hook. Not every Multi class needs to be flagged on day one, but the mechanism must exist so new code is born brand-scoped. Retrofitting after launch is the worst case: every existing query becomes a potential cross-brand data leak.
3. **Brand-aware `Globalvars::get_setting()`** — resolution order brand-scoped → global → default, plus explicit `get_global_setting()` for infrastructure reads. Settings reads are scattered everywhere; if the lookup isn't brand-aware from launch, every site becomes a future audit candidate.
4. **`usr_brd_brand_id` column on users (nullable)** — stamping every user with a primary brand from day one is trivial; backfilling later is a migration with assumptions baked in.

### Strongly recommended pre-launch — cheap now, painful to backfill

5. **Brand-stamping columns on event/log tables** — `vse_brd_brand_id` on visitor events, `ccd_brd_brand_id` on coupons, `sdd_brd_brand_id` on devices, `stg_brd_brand_id` on settings. All nullable. Past data without a brand stamp is forever "brand unknown" — a permanent data-quality cost, not a migration cost.
6. **Stripe customer metadata convention** — `brand=<code>` on every Stripe customer/subscription/payment from day one. Existing customers without it become a webhook special case otherwise.
7. **`brd_brands` table + seeded scrolldaddy row** — the anchor. Without it the rest has nowhere to point.

### Safely deferable — genuinely additive

- `pla_plugin_activations` table and per-brand plugin gating (defer the table; don't write code that assumes globally-active plugins when divergence work begins)
- Admin brand selector UI, `/adm/admin_brands` CRUD, per-brand settings override UI
- Theme override from `BrandContext` (wire the read path; the second theme itself can wait)
- Org / B2B layer (separate future spec when a real B2B brand needs it)

### Principle

The minimum viable "platform-shaped" launch is: **one deployment, one brand, but every code path already flows through `BrandContext` and brand-scoped lookups.** When `brand_id` resolves to 1 always, behavior is identical to today. When brand 2 is added later, no audit, no backfill, no broken queries. Items 1–4 buy that property; items 5–7 prevent secondary cleanup; everything else is genuinely additive.

This corresponds to Phases 0, 1, and 2 in the implementation plan below. Phases 3 and 4 are deferable past public launch.

---

## Implementation phases

### Phase 0 — Data model groundwork
- [ ] Create `brd_brands` data class
- [ ] Run `update_database` to materialize the table
- [ ] Seed `brd_brands` with a `scrolldaddy` row (the first brand)
- [ ] Add nullable `usr_brd_brand_id` column to `usr_users`; backfill existing users to scrolldaddy
- [ ] Add nullable `stg_brd_brand_id` to `stg_settings`
- [ ] Add nullable `vse_brd_brand_id` to `vse_visitor_events`
- [ ] Add nullable `ccd_brd_brand_id` to `ccd_coupon_codes`
- [ ] Add nullable `sdd_brd_brand_id` to `sdd_devices`; backfill existing rows to scrolldaddy

### Phase 1 — BrandContext + domain dispatch
- [ ] Create `includes/BrandContext.php` singleton
- [ ] Wire `BrandContext::get_instance()` into `serve.php` after session init
- [ ] Implement `HTTP_HOST` → brand resolution (primary domain + aliases)
- [ ] Cache resolution on session to avoid per-request DB lookup
- [ ] Add null-brand fallback for CLI / cron / unrecognized hosts

### Phase 2 — Scope the platform
- [ ] Update `ThemeHelper::getInstance()` to prefer `BrandContext::get_theme()`
- [ ] Update `Globalvars::get_setting()` to do brand-scoped-first resolution; add `get_global_setting()` for global reads
- [ ] Extend `SystemMultiBase::_get_resultsv2()` to implicitly brand-scope queries when the class declares `$brand_scoped = true`
- [ ] Audit existing data classes and flag which are brand-scoped (devices, subscriptions, etc.) vs cross-brand (users, plugins, brands, themes)
- [ ] Stamp `vse_brd_brand_id` on new visitor events from BrandContext
- [ ] Stamp `ccd_brd_brand_id` on brand-scoped coupons created via admin UI
- [ ] Stamp `sdd_brd_brand_id` on new devices from BrandContext

### Phase 3 — Admin brand-awareness
- [ ] Add brand selector to admin nav for cross-brand views
- [ ] Brand filter on admin list pages over brand-scoped data
- [ ] `/adm/admin_brands` CRUD
- [ ] Per-brand settings override UI (extension of existing `/adm/admin_settings`)

### Phase 4 — First new brand
- [ ] Stand up a second domain pointed at the existing deployment
- [ ] Create second `brd_brands` row
- [ ] Create a theme for the second brand
- [ ] Ship marketing pages
- [ ] Verify: signup, billing, DNS flow, admin isolation all work end-to-end

---

## Firm lines (what we are not building)

These are design boundaries, stated explicitly so future work doesn't drift past them.

1. **Brand is configuration, not code.** No per-brand forks of view files, no brand-specific route dispatchers, no `if ($brand === 'schoolfilter')` branches in business logic. Divergence lives in themes and plugin activation.
2. **One DB, one deployment.** No Postgres federation, no read replicas per brand, no data partitioning.
3. **Unified user pool.** Email uniqueness stays global. One human, one user record, regardless of brand affiliation. `usr_brd_brand_id` is informational (signup source), not a partition key.
4. **No feature-flag engine.** If a feature needs to vary per brand, it goes in a plugin that's activated per brand, or it's a settings flag. Nothing more elaborate.
5. **No per-brand migrations.** Schema lives in one place; brand-aware data evolves via standard `update_database` + migrations.
6. **No brand-scoped admin codebase.** One `/adm/` directory serves all brands. Branding on admin pages is minimal.
7. **No org / B2B layer in this spec.** Shared billing, internal admin roles, and team-account features are a separate future spec, justified by an actual B2B brand launch.

---

## Dependencies & sequencing

- **Independent of marketing infra spec.** The two specs touch different surfaces. Either can ship first.
- **Recommended sequence:** Marketing infra ships first (smaller, proves Phase 1-2 mechanics in production). Multi-domain ships second (bigger, restructures the platform).
- **Marketing infra forward-compat:** the two hooks noted in that spec (settings-driven OG site name, nullable `abe_brd_brand_id` on experiments) mean the marketing work doesn't need to be revisited when multi-domain ships.

---

## Out of scope

- Org / B2B layer (shared billing, internal admin role, team accounts) — separate future spec
- Cross-brand reporting dashboards (build when there's a second brand in production)
- Per-brand email template engine (current system works; add if needed)
- Multi-region / multi-DB deployments (a separate platform concern)
- Tenant-isolated file storage (shared filesystem with brand-prefixed paths is sufficient)
- White-label / reseller model where a third party creates brands (not a product need)

---

## Documentation Updates
- Add `docs/multi_brand_architecture.md` documenting: `BrandContext` usage, brand-scoped vs global settings resolution, how brand-scoped data tables stamp from `BrandContext`, the unified-user-pool model, and the firm-line list above.
- Update `docs/plugin_developer_guide.md` with how plugins should detect brand context.
- Update `CLAUDE.md` with `BrandContext` as a pre-loaded core file.
