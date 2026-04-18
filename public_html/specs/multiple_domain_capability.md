# Specification: Multiple Domain Capability

## Overview
Joinery is currently a single-branded, single-domain platform: one deployment serves one brand on one domain, with one theme and one user pool. This spec makes the platform natively multi-brand — one deployment can serve many domains, each with its own theme, copy, pricing, and user base, while sharing a single database, single billing pipeline, and single resolver data contract.

**Guiding principle:** this is platform infrastructure, not a scrolldaddy feature. The abstractions (brand, org) are generic. ScrollDaddy becomes the first brand (`brand_id=1`); any future product is another brand.

## Why now
Three separate pressures converge:
1. **Product:** ScrollDaddy's underlying service has multiple distinct markets (consumer digital-addiction, schools, law firms) that need different brands, pricing, and marketing angles.
2. **Architecture:** Doing it later means retrofitting brand-scoping into every table and query. Doing the groundwork now is much cheaper.
3. **Platform:** Joinery should support multi-site natively — other future clients on docker-prod may want the same capability.

## Core architectural decision
**Option B from the multi-brand analysis: domain-dispatching multi-tenant Joinery.** One deployment, one DB, many domains. Rejected alternatives:

- **Option A (thin marketing fronts + shared backend API):** faster to ship for one extra brand, but duplicates infrastructure for each additional brand and doesn't generalize into a platform capability.
- **Option C (separate deployments + Postgres replication):** pushes complexity into the infrastructure layer, fragments admin and analytics, and doesn't help other Joinery clients.

Option B is more work upfront but is the only option that produces a reusable platform capability.

---

## Product decisions (answers to the five open questions)

| # | Question | Answer |
|---|---|---|
| 1 | Users scoped per brand or unified? | **Scoped by default.** Email uniqueness is `(brand_id, email)`, not global. Cross-brand account linking is a deliberate opt-in feature, architected but not built. |
| 2 | Admin staff cross-brand? | **Scoped by default**, with a distinct superadmin tier that sees all brands. Brand-scoped admins are the common case; cross-brand is a superuser capability. |
| 3 | Billing | **One Stripe account** across all brands. Stripe customer objects carry `brand` and `org_id` metadata. Invoice branding (logo, from-address, customer portal URL) varies per brand. |
| 4 | Org-level accounts | **Yes — architected now.** `org_organizations` table exists from day one. Consumer brands use nullable `usr_org_organization_id`; B2B brands (schools, law firms) use it as a required grouping for billing + device management. |
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
- `brd_org_required` — bool, default false (B2B brands require users to belong to an org; consumer brands don't)
- `brd_created_time`, `brd_modified_time`, `brd_delete_time`

**`org_organizations`** — groupings of users under a brand
- `org_organization_id` — int4 serial PK
- `org_brd_brand_id` — int4 (FK)
- `org_name` — varchar(255)
- `org_domain` — varchar(255) nullable (for email-domain auto-join, e.g., `westlawpartners.com`)
- `org_billing_usr_user_id` — int4 nullable (the user who owns billing for this org)
- `org_stripe_customer_id` — varchar(64) nullable (one Stripe customer per org)
- `org_config` — json (org-level settings: SSO provider, default blocklists, audit-log retention, etc.)
- `org_status` — varchar(16) — `active` | `suspended` | `archived`
- `org_created_time`, `org_modified_time`, `org_delete_time`

### Modifications to existing tables

- `usr_users` → add `usr_brd_brand_id` (int4, **nullable** — null means "cross-brand admin staff"; non-null users belong to exactly one brand). Add `usr_org_organization_id` (int4, nullable). Add `usr_role_in_org` (varchar(32), nullable — e.g., `member`, `org_admin`).
- `usr_users` → change email uniqueness constraint from `UNIQUE(usr_email)` to `UNIQUE(usr_brd_brand_id, usr_email)` to allow the same email in different brands. Cross-brand admins (null brand_id) are handled by a partial unique index on `usr_email WHERE usr_brd_brand_id IS NULL`.
- `stg_settings` → add `stg_brd_brand_id` (int4, nullable). Null = global default; non-null = brand override. Lookup resolves brand-first, global-fallback.
- `abe_experiments` (from marketing infra spec) → `abe_brd_brand_id` nullable. Already specified.
- `vse_visitor_events` → add `vse_brd_brand_id` (int4, nullable). Set from `BrandContext` at write time.
- `ccd_coupon_codes` → add `ccd_brd_brand_id` (int4, nullable). Null = global coupon; non-null = brand-scoped.
- `sdd_devices` → no change. Device identity is `sdd_resolver_uid` which is globally unique; devices inherit brand via their `sdd_usr_user_id → usr_brd_brand_id` chain.
- `plg_plugins` → add a `pla_plugin_activations` table for per-brand activation: `(pla_brd_brand_id, pla_plg_plugin_id, pla_is_active)`. Existing `plg_status` becomes the global default; per-brand rows override.

### DNS resolver impact: **zero**
The resolver queries devices by `sdd_resolver_uid` (device-scoped, globally unique) and reads block rules user-scoped. Brand never enters the query. This is the whole reason the resolver data contract satisfies the "shared DB" constraint cleanly — brand is a presentation-layer concept, not a resolver concept.

---

## Request dispatch

### `BrandContext` singleton

New class `includes/BrandContext.php`, populated once per request from `HTTP_HOST`:

```php
class BrandContext {
    public static function get_instance(): self;
    public function get_brand_id(): ?int;       // null if no brand resolved (e.g., CLI, cron, admin IP)
    public function get_brand(): ?Brand;        // lazy-loaded Brand object
    public function get_theme(): string;
    public function get_config(string $key, $default = null);  // brand_config, fallback to global
    public function is_cross_brand_context(): bool;            // superadmin / CLI
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

**This is the largest single change and the most error-prone.** Every Multi* query that returns user-scoped data must filter by `usr_brd_brand_id = BrandContext::get_brand_id()` unless explicitly opted out (for cross-brand admin tooling).

Approach: extend `SystemMultiBase::_get_resultsv2()` to accept an implicit brand filter, activated by a class-level `$brand_scoped = true` flag. Classes that hold cross-brand data (`plg_plugins`, `brd_brands` itself, etc.) opt out. Individual call sites can explicitly pass `['is_cross_brand' => true]` for admin tooling.

### The null-brand rule
`BrandContext::get_brand_id()` returns `null` for:
- CLI scripts (no `HTTP_HOST`)
- Cron / scheduled tasks
- Requests to admin subdomains configured as cross-brand (e.g., `admin.joinery.internal`)

In those contexts, queries bypass brand scoping. This is why admin users have a nullable `usr_brd_brand_id`.

---

## Orgs (B2B layer)

### Model
Orgs are a grouping abstraction between users and brands. A user belongs to zero-or-one org. An org belongs to exactly one brand.

```
brand ── 1:N ── org ── 1:N ── user
                         user ── 1:N ── device (sdd_devices)
```

### Billing
When `brd_org_required = true` (B2B brands), subscriptions attach to `org_stripe_customer_id` instead of an individual user. Invoices bill the org; members don't see billing. When `brd_org_required = false` (consumer brands), users can ignore orgs entirely — subscriptions attach to the user directly.

This is the cleanest shape because Stripe's model is flexible enough to do either — a customer is just an ID; whether it belongs to a user or an org is metadata.

### Org admin
A user with `usr_role_in_org = 'org_admin'` can manage the org: add/remove members, assign devices, view billing, configure org-level blocklists. Role enforcement lives in a new `OrgPermissions` helper.

### Non-goals for v1
- Nested orgs (org-of-orgs) — not architected.
- Cross-org users — a user is in zero or one org. Period.
- Org-level SSO — architect the `org_config` blob to hold SSO metadata; don't implement it.
- Org-scoped blocklists — `sdd_blocks` can gain `sbl_org_organization_id` when B2B brands actually ship; not part of this spec.

---

## Billing details

One Stripe account, one set of products. Per-brand:
- Different **price IDs** (brands can have different prices for the same underlying product; the price ID catalog lives in `brd_config`).
- Different **branding** on receipts: logo, from-address, customer portal URL configured on the Stripe customer or session object, sourced from `brd_config`.
- Metadata on every Stripe customer/subscription/payment: `brand=<code>`, `org_id=<id>` for downstream reporting.

Webhooks from Stripe route to existing handlers; the handlers look up the Stripe customer metadata to determine brand context (since the request doesn't have `HTTP_HOST` set to a brand domain).

---

## Admin UI

### Brand dimension everywhere
Every admin list page gains a brand filter (dropdown, default = current brand context). Superadmins see "All Brands" as an option; brand admins see only their brand.

### New admin pages
- `/adm/admin_brands` — CRUD for `brd_brands` rows (superadmin only).
- `/adm/admin_organizations` — CRUD for `org_organizations` (brand-scoped for brand admins; all-brands view for superadmins).
- Existing `/adm/admin_users` — gains brand + org filters.

### Permission tiers
Existing `permission` field on users:
- `10` = superadmin (cross-brand — no `usr_brd_brand_id` required; sees everything)
- `5` = brand admin (scoped by `usr_brd_brand_id`)
- Lower = regular user

No new permission levels needed. The scoping comes from the user's own brand affiliation.

---

## Feature divergence strategy

**v1 position: same features across all brands.** Copy, pricing, and emphasis vary via theme/settings, not via code.

**Divergence mechanism when it's eventually needed:**

1. **Plugin activation per brand.** A plugin becomes the unit of divergence. Schools-specific features (student reporting, classroom blocklists) live in a `schools` plugin, activated only for brands where `brd_code = 'schoolfilter'`. Law-firm features (audit logging, compliance reporting) live in a `compliance` plugin. Consumer brands activate neither.
2. **Settings-driven UI variants.** Within a shared plugin, per-brand settings toggle UI elements (e.g., `show_family_mode = true` on consumer; `show_device_groups = true` on B2B). Cheap, no new engine.
3. **NOT** building: a feature-flag framework, per-brand code forks, a brand-specific route dispatcher, a per-brand view-override layer beyond what themes already provide.

The firm line: **brand is configuration, not code.** If a feature can't be built by combining (theme × plugin activation × settings), that's a signal the platform needs a new extension point, not a per-brand fork.

---

## Migration / rollout

The existing scrolldaddy deployment becomes `brand_id=1` with `brd_code='scrolldaddy'`. Backfill strategy:

1. Create `brd_brands` table, insert one row for scrolldaddy with current settings.
2. Add `usr_brd_brand_id` column, backfill all existing users to `1`.
3. Add `stg_brd_brand_id` column (stays null — existing settings become global defaults).
4. Ship `BrandContext` + `HTTP_HOST` dispatch — for single-domain deployments, the resolved brand is just `brand_id=1` and nothing visible changes.
5. Roll out the second brand domain pointed at the same deployment.
6. Iterate.

**No data migration is destructive.** Every change is additive. A rollback is: revert the serve.php hook; everything keeps working against `brand_id=1`.

---

## Optional: cross-brand user unification (future, not v1)

Architected but not built. The path:
- Add a new `ide_identities` table keyed by email (global, not brand-scoped).
- `usr_users.usr_ide_identity_id` links a brand-scoped user row to a global identity.
- When a person signs up on a second brand with the same email, they can opt into "link these accounts" — merges identities at the `ide_identities` level; each brand's data stays separate.
- Login flow: email → identity → list of (brand, user) pairs → pick one.

The `ide_identities` table is additive and can be introduced any time without touching existing brand-scoped data. Not a v1 feature, but the email-uniqueness scoping chosen above (per-brand, not global) doesn't preclude it.

---

## Implementation phases

### Phase 0 — Data model groundwork
- [ ] Create `brd_brands`, `org_organizations`, `pla_plugin_activations` data classes
- [ ] Run `update_database` to materialize tables
- [ ] Seed `brd_brands` with a `scrolldaddy` row (the first brand)
- [ ] Add nullable `usr_brd_brand_id`, `usr_org_organization_id`, `usr_role_in_org` columns to `usr_users`; backfill existing users to scrolldaddy
- [ ] Add nullable `stg_brd_brand_id` to `stg_settings`
- [ ] Add nullable `vse_brd_brand_id` to `vse_visitor_events`
- [ ] Add nullable `ccd_brd_brand_id` to `ccd_coupon_codes`
- [ ] Update email uniqueness constraint on `usr_users` to `(usr_brd_brand_id, usr_email)` with partial null-brand unique for admin staff

### Phase 1 — BrandContext + domain dispatch
- [ ] Create `includes/BrandContext.php` singleton
- [ ] Wire `BrandContext::get_instance()` into `serve.php` after session init
- [ ] Implement `HTTP_HOST` → brand resolution (primary domain + aliases)
- [ ] Cache resolution on session to avoid per-request DB lookup
- [ ] Add null-brand fallback for CLI / cron / unrecognized hosts

### Phase 2 — Scope the platform
- [ ] Update `ThemeHelper::getInstance()` to prefer `BrandContext::get_theme()`
- [ ] Update `Globalvars::get_setting()` to do brand-scoped-first resolution; add `get_global_setting()` for global reads
- [ ] Add per-brand plugin activation: `PluginManager::is_active($plg_code, $brand_id = null)`
- [ ] Extend `SystemMultiBase::_get_resultsv2()` to implicitly brand-scope queries when the class declares `$brand_scoped = true`
- [ ] Audit existing data classes and flag which are brand-scoped (most user-facing ones) vs cross-brand (plugins, brands, themes)
- [ ] Stamp `vse_brd_brand_id` on new visitor events from BrandContext
- [ ] Stamp `ccd_brd_brand_id` on brand-scoped coupons created via admin UI

### Phase 3 — Orgs
- [ ] Implement `Organization` + `MultiOrganization` data classes
- [ ] Build `/adm/admin_organizations` CRUD page
- [ ] Add `OrgPermissions` helper for role checks (`is_org_admin()`, `can_manage_billing()`)
- [ ] Update user admin UI to show/edit `usr_org_organization_id` and `usr_role_in_org`
- [ ] Billing integration: route subscriptions to `org_stripe_customer_id` when `brd_org_required = true`

### Phase 4 — Admin brand-awareness
- [ ] Add brand selector to admin nav (superadmins only; brand admins are auto-scoped)
- [ ] Brand filter on every admin list page
- [ ] `/adm/admin_brands` CRUD (superadmin only)
- [ ] Per-brand settings override UI (extension of existing `/adm/admin_settings`)

### Phase 5 — First new brand
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
3. **No cross-brand user unification in v1.** Each brand has its own user pool. Architected as `ide_identities` future path, not built.
4. **No nested orgs.** An org is a flat grouping of users.
5. **No feature-flag engine.** If a feature needs to vary per brand, it goes in a plugin that's activated per brand, or it's a settings flag. Nothing more elaborate.
6. **No per-brand migrations.** Schema lives in one place; brand-aware data evolves via standard `update_database` + migrations.
7. **No brand-scoped admin codebase.** One `/adm/` directory serves all brands. Branding on admin pages is minimal — admin users know which brand they're managing because they picked it from the selector.

---

## Dependencies & sequencing

- **Independent of marketing infra spec.** The two specs touch different surfaces (marketing infra: SEO, coupons, bandit; multi-domain: brands, orgs, scoping). Either can ship first.
- **Recommended sequence:** Marketing infra ships first (smaller, proves Phase 1-2 mechanics in production). Multi-domain ships second (bigger, restructures the platform).
- **Marketing infra forward-compat:** the two hooks noted in that spec (settings-driven OG site name, nullable `abe_brd_brand_id` on experiments) mean the marketing work doesn't need to be revisited when multi-domain ships.

---

## Out of scope

- Cross-brand reporting dashboards (build when there's a second brand in production)
- Per-brand email template engine (current system works; add if needed)
- Multi-region / multi-DB deployments (a separate platform concern)
- Tenant-isolated file storage (shared filesystem with brand-prefixed paths is sufficient)
- White-label / reseller model where a third party creates brands (not a product need)

---

## Documentation Updates
- Add `docs/multi_brand_architecture.md` documenting: `BrandContext` usage, brand-scoped vs global settings resolution, how plugins opt into per-brand activation, org hierarchy, and the firm-line list above.
- Update `docs/plugin_developer_guide.md` with per-brand plugin activation and how plugins should detect brand context.
- Update `CLAUDE.md` with BrandContext as a pre-loaded core file.
