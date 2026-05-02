# Specification: Multiple Domain Capability — Sister Brand Deployment

## Overview

Joinery is currently a single-branded, single-domain platform: one deployment serves one brand on one domain. To launch a second brand (NetworkSentry: a B2B-flavored DNS-filtering product, working name; domain TBD) under a different visual identity, copy, email voice, and pricing — without forking the codebase or running a copy-paste plugin — this spec defines the deployment pattern and the supporting refactor work.

**The pattern:** stand up the second brand as a **parallel Joinery deployment**, sharing only the DNS resolver. Each brand is a complete, independent Joinery instance — its own DB, sessions, users, devices, admin, email — running the same codebase.

**Guiding principle:** the shared concern is the DNS resolver (the actual filtering service that answers queries from devices). Everything else is independent. Optional sharing (Stripe account with brand metadata) is opt-in convenience, not architectural coupling.

## Why this approach

A "domain-dispatching multi-tenant Joinery" — one deployment, one DB, many domains — was designed in detail and rejected late in the scoping pass. The honest reason: the unification benefits (single user pool, cross-brand admin, one Stripe customer per human) are theoretical for two brands with disjoint audiences. ScrollDaddy users and NetworkSentry users overlap by approximately zero. Paying the cost of multi-brand abstractions (BrandContext propagation, brand-scoped queries on every table, brand-aware settings, brand-aware email lookup, scheduled-task brand modes, admin brand-awareness) for benefits nobody uses is a bad trade. The "How we got here" note at the bottom captures the rejected design and why it was rejected; it's there in case the trade-off ever inverts (a real B2B brand with shared customers, or 5+ brands sharing real data).

Separate deployments are also the path your operations already use — `empoweredhealthtn` is deployed exactly this way. Pattern is established. NetworkSentry is the second instance of an existing pattern, not a new architecture.

## What's shared vs. separate

| Concern | Shared | Separate per brand |
|---|---|---|
| DNS resolver service (the actual filtering daemons) | ✓ | |
| Block-list source / category data the resolver uses | ✓ | |
| Codebase (same git repo, same tag) | ✓ | |
| Stripe account (with brand metadata on customers/subs) | ✓ (optional, recommended) | |
| Database (resolver reads from each, see "Resolver coordination") | | ✓ |
| `usr_users` table | | ✓ |
| Sessions / cookies / login | | ✓ |
| Admin (`/adm/`) | | ✓ |
| Devices, blocks, scheduled blocks, query logs | | ✓ |
| Subscriptions, orders, coupons | | ✓ |
| Mailgun sender domain (DKIM/SPF) | | ✓ |
| Email templates | | ✓ |
| Marketing pages, theme, copy | | ✓ |
| Apache vhost, SSL cert | | ✓ |
| Container | | ✓ |

The only platform-level shared thing is the DNS resolver, which is already a separate service called via API.

---

## Prerequisite refactor: plugin / theme decoupling

The current `plugins/scrolldaddy/` plugin bundles two unrelated concerns into one directory: the DNS-filtering product (functional code) and the ScrollDaddy brand presentation (theme files). This entanglement is the source of the "deploy a second brand looks like copy the entire plugin" problem. It must be unwound before a sister brand can stand up cleanly.

This refactor stands on its own merit even single-brand — it produces correctly-shaped plugin and theme directories — and is a **prerequisite** for sister-brand deployment.

### What stays in the plugin (functional, brand-neutral, shared)

- `data/` — `devices_class.php`, `scheduled_blocks_class.php`, etc.
- `logic/` — `devices_logic.php`, `scheduled_block_edit_logic.php`, etc.
- `ajax/` — `block_rule_add.php`, `scan_url.php`, `purge_querylog.php`, etc.
- `tasks/`, `migrations/`, `includes/`
- `settings_form.php`
- `views/profile/*.php` — the **default** versions of the dashboard views (devices, querylog, device_edit, scheduled_block_edit, activation, mobileconfig, etc.). These resolve through the theme chain, so any active theme can override them.
- Plugin-functional JS (any JS that talks to the plugin's AJAX endpoints).

### What moves out to a theme (presentation, brand-specific)

- `theme.json` (the existing one) → moves to `theme/scrolldaddy/theme.json`. Drop `provides_theme: true` from `plugin.json`.
- `assets/css/scrolldaddy-plugin.css`, `assets/css/style.css`, `assets/js/scrolldaddy-plugin.js`, all branded `assets/img/*` (hero, brand, testimonial, project, blog, portfolio, etc.) → `theme/scrolldaddy/assets/`.
- Marketing & site-chrome views: `views/index.php`, `views/pricing.php`, `views/login.php`, `views/cart.php`, `views/cart_confirm.php`, `views/page.php`, `views/items.php`, `views/product.php`, `views/logout.php`, `views/forms_example.php` → `theme/scrolldaddy/views/`.
- `tier_features.json` (brand copy for tier descriptions) → moves to the theme, or to per-deployment `stg_settings`. Final location TBD during refactor; key point is it stops living in the plugin.

### Brand-neutralizing the default profile views

The 14 profile views currently in `plugins/scrolldaddy/views/profile/` render with ScrollDaddy-flavored copy. They become **brand-neutral defaults**: generic functional language ("Your devices", "Filtering schedule"), no "ScrollDaddy" strings. Each brand's theme then overrides any view it wants to stylize for brand voice.

### Hard-coded brand strings audit

Part of the decoupling work is a sweep for hard-coded brand strings:
- Literal `"scrolldaddy"`, `"ScrollDaddy"`, `"scrolldaddy.app"` in views, CSS, JS, email templates, copy
- Hard-coded asset paths starting with `/plugins/scrolldaddy/assets/`
- Site-name / company-name strings in HTML titles, meta tags, footer
- Email signatures, support-address strings

Each must either move to `theme/scrolldaddy/` (if it's brand-specific copy) or come from a per-deployment setting (if the same string would differ per brand). A grep audit on the plugin and any cross-cutting code is part of this phase.

### Plugin rename (just do it now)

`plugins/scrolldaddy/` → `plugins/dns_filtering/`. Matches the platform-generality principle and stops new contributors from assuming the plugin is brand-coupled. With no production users yet, there are no bookmarks to redirect from, so this is a flat rename:
- Directory rename
- `plugin.json` `name` field
- Profile menu URL slugs: `/profile/scrolldaddy` → `/profile/dns_filtering`
- All `require_once(PathHelper::getIncludePath('plugins/scrolldaddy/...'))` references
- Plugin activation rows on the existing deployment (one UPDATE)
- Update any settings in `stg_settings` that reference the plugin path

No redirects required. The pre-launch window is the right time to do this.

### Verification gate

After the refactor, ScrollDaddy must render byte-identically to its pre-refactor state. The refactor is purely a relocation of files; it should not be a UI change. Visual regression on the marketing pages and the profile dashboard is the gate.

---

## User and device collision handling

**Pre-launch context:** there are no production users yet. ScrollDaddy is pre-launch, and NetworkSentry will stand up before either brand has signed-up customers. This relaxes constraints throughout the runbook — the spec does not need to preserve any existing UID, user, or session state.

Because each brand is a separate deployment with a separate database, **user-table collisions don't exist** at the platform level. The same human signing up on both brands (post-launch, once they have customers) creates two `usr_users` rows in two different databases. They have separate passwords, separate profiles, separate session cookies. This is acceptable; user overlap is expected to be approximately zero.

### Device UID collisions

Device resolver UIDs are generated as `bin2hex(random_bytes(16))` (`plugins/scrolldaddy/data/devices_class.php:67`) — 32 hex chars, 128 bits of entropy. The column is `varchar(32)`. Collision probability across two deployments is effectively zero for any realistic device count.

**Pre-launch convention** (taking advantage of no production devices yet): namespace UIDs from day one on **both** deployments. Format: `<prefix>-<30 hex chars>` = 32 chars total. Fits the existing column width with no schema change.
- ScrollDaddy: `s-` + 30 hex chars
- NetworkSentry: `n-` + 30 hex chars
- Future brand 3: its own letter prefix

The resolver doesn't care about the format — UIDs are looked up by exact match — but the prefix gives operational visibility ("which deployment owns this UID?") and prevents accidental DB cross-imports from silently mixing devices. The plugin's UID generator reads its prefix from a per-deployment setting (`device_uid_prefix`) and falls back to no-prefix only as a safety default.

Any existing test-data UIDs in the current ScrollDaddy DB can be backfilled in place (one update query) since no production users exist yet. After NetworkSentry stand-up, all new UIDs on both deployments carry their respective prefix.

### Other ID spaces

Most other IDs (user, order, device, subscription) are auto-incrementing integers per-database. There's no cross-deployment lookup that would require global uniqueness. The only IDs the resolver sees are `sdd_resolver_uid` (just covered) and Stripe customer IDs (unique by definition).

---

## Resolver coordination (the one shared thing)

The DNS resolver is the only piece both deployments share. Understanding the **current** integration model is essential to designing the multi-deployment one.

### Current model: resolver pulls via direct PostgreSQL

Today the Go DNS resolver (`/home/user1/scrolldaddy-dns/`) connects directly to Joinery's PostgreSQL database (`internal/db/db.go`) and reads `sdd_devices`, `sdd_scheduled_blocks`, the filter tables, etc. via SQL. It is the active reader; Joinery is passive on the data side.

The only direction Joinery → resolver communication runs is two thin RPCs (`plugins/scrolldaddy/includes/ScrollDaddyApiClient.php`):
- `GET /device/{uid}/seen` — Joinery asks the resolver if a device has been seen (UI display only, not config sync).
- `POST /reload` — Joinery nudges the resolver to reload caches after blocklist downloads.

Neither RPC carries device or block configuration. **All device/block state moves resolver-ward via the resolver's SQL reads.**

### Multi-deployment design: resolver reads multiple Joinery DBs

A second Joinery deployment writes to its **own** PostgreSQL database. The resolver — currently configured for a single DB — must learn to read both.

Extend the resolver's config to accept a list of Joinery database URLs. Resolver opens one connection pool per DB and queries each on its existing schedule, unioning the results in memory.

```
SCD_JOINERY_DB_URLS=host=scrolldaddy-db dbname=joinery user=scd password=xxx sslmode=disable,host=networksentry-db dbname=joinery user=scd password=xxx sslmode=disable
```

Per-DB schema validation runs at startup (the existing `ValidateSchema` pattern). UIDs are globally unique (128-bit entropy + optional `s-`/`n-` namespacing), so the union is collision-free. Tier-to-category mappings are read out of each DB's settings; the resolver applies whichever tier/category set the device's owning DB declared.

**Cost:** a small Go change in `internal/db/db.go` and `internal/config/config.go` — a loop where there's currently a single DB connection. The resolver's hot path doesn't change. Each Joinery exposes its DB to the resolver IPs (already done today for ScrollDaddy).

Two alternatives were considered and rejected:
- **Sync layer** materializing both DBs into a shared resolver-read DB. Avoids resolver code change but introduces a new component, sync latency, and a class of "fell behind" failure modes. Not worth it.
- **Flip resolver to push-based** with Joinery deployments POSTing device CRUD. Cleanest separation in theory, but a significant rewrite of both sides — resolver gains write APIs, persistence, reconciliation semantics, missed-delete handling. Not justified for two deployments.

### What this means for the runbook

Phase H (resolver setup) becomes:
- Add NetworkSentry's database URL to the resolver's config (`scrolldaddy.env` on each DNS server)
- Restart resolver; it picks up the second DB
- Smoke-test: NetworkSentry device appears in DNS resolution within the resolver's poll interval

No new API key on the resolver is needed for the data path (the resolver is still reading SQL, not receiving pushes). The existing `/device/{uid}/seen` and `/reload` RPCs that Joinery uses still need an API key per deployment, but that's a separate, smaller concern (each deployment carries its own key in plugin settings).

### Network access

Each Joinery's PostgreSQL must be reachable from the resolver IPs (`45.56.103.84`, `97.107.131.227`). ScrollDaddy already exposes its DB this way; NetworkSentry's DB needs the same firewall/auth setup.

### Block-list source

Block-list categories (porn, gambling, malware, ads, etc.) are resolver-owned configuration data, not Joinery-owned. Both Joinery DBs reference the same category keys; the resolver enforces. No coordination needed beyond keeping the category catalog stable.

### Tier-to-categories mapping

Each Joinery deployment has its own subscription tiers and its own tier-to-categories mapping in its DB. NetworkSentry's mapping (B2B emphasis on malware/ads) differs from ScrollDaddy's (consumer emphasis on addictive-content categories). Resolver applies whichever mapping the device's owning DB declares. Per-deployment configuration; not a resolver concern.

---

## Stripe (shared account, brand metadata)

One Stripe account serves both deployments. Each deployment uses the same `STRIPE_SECRET_KEY` but creates its own products and prices.

### Customer/subscription tagging

Every Stripe customer, subscription, payment, and invoice carries metadata `brand=<code>` (e.g., `brand=scrolldaddy`, `brand=networksentry`) at creation time. Helpers around `customers->create()`, `subscriptions->create()`, etc. set this metadata from a per-deployment setting (`stripe_brand_metadata` or similar — value `scrolldaddy` on ScrollDaddy's deployment, `networksentry` on NetworkSentry's).

### Webhook handling

Both deployments register their own webhook endpoints with Stripe (`scrolldaddy.app/ajax/stripe_webhook`, `networksentry.com/ajax/stripe_webhook`). Stripe delivers all events to both endpoints.

Each deployment's webhook handler ignores events for customers it doesn't recognize: if `customers.retrieve(event.customer).metadata.brand` doesn't match this deployment's brand setting, the handler returns 200 OK without acting. Slightly chatty (each event hits both endpoints) but bulletproof and stateless.

### Customer portal

Stripe's customer-portal session URL can be configured per session. Each deployment passes a brand-appropriate `return_url` and (where Stripe supports) per-brand portal branding configuration. Existing helper `create_billing_portal_session()` accepts `$return_url`; the deployment's brand affects which URL is sent.

### Different prices per brand

Each deployment's tier-to-Stripe-price-ID mapping lives in that deployment's `stg_settings`. Two deployments can sell the same tier at different prices by referencing different price IDs.

---

## Email (separate Mailgun domains)

Each brand has its own Mailgun sending domain — `mail.scrolldaddy.app`, `mail.networksentry.com`. Each domain has its own DKIM, SPF, return-path, and Mailgun API credentials.

### Per-deployment email config

Email config (`mailgun_domain`, `mailgun_api_key`, `from_address`, `reply_to_address`, `support_address`) lives in each deployment's `stg_settings`. No abstraction needed — each Joinery is single-brand.

### Email templates

Each deployment carries the templates appropriate to its voice. NetworkSentry's templates are written for B2B language; ScrollDaddy's for recovery-coded language. Templates are deployment-local; no cross-brand fallback machinery is needed.

If a template change needs to land on both deployments (rare), it's two PRs or one PR that touches both deployments' template directories. Same as any other deployment-specific config.

### URLs in emails

Each deployment composes URLs from its own `$_SERVER['HTTP_HOST']` (or its configured site URL setting). NetworkSentry emails have NetworkSentry URLs; ScrollDaddy emails have ScrollDaddy URLs. No special logic required.

### Inbound email

Each deployment's inbound flow is configured for its own domain (`*@inbox.networksentry.com` → NetworkSentry deployment's webhook). Independent.

---

## Deployment runbook (NetworkSentry, concrete)

This is the actionable checklist for standing up the second brand.

### Phase A — Refactor (prerequisite, on the existing ScrollDaddy deployment)

**Status (2026-05-01):** Core refactor shipped to scrolldaddy.app prod via v0.8.30. Plugin renamed, theme split, settings renamed for compliance with plugin-naming rules. Brand-neutralization of display copy / class names / tier-feature keys, plus the device UID prefix work, are intentionally deferred — none are blockers for sister-brand deployment, and the user opted to skip them until clearly needed.

**Done**
- [x] Plugin renamed `scrolldaddy` → `dns_filtering` (flat rename — directory, plugin.json, profile menu URL slugs, all `require_once(PathHelper::getIncludePath('plugins/scrolldaddy/...'))` references, `getThemeFilePath(... 'scrolldaddy')` plugin-name args, `/profile/scrolldaddy/...` URL refs)
- [x] Plugin / theme decoupling complete: `theme.json`, `tier_features.json`, `includes/PublicPage.php`, `includes/FormWriter.php`, `assets/`, and the marketing views (`index`, `pricing`, `login`, `cart`, `cart_confirm`, `page`, `items`, `product`, `logout`, `forms_example`) moved from `plugins/scrolldaddy/` to a new `theme/scrolldaddy/`. `provides_theme: true` dropped from `plugin.json`. Theme registered in `thm_themes`.
- [x] **Settings renamed `scrolldaddy_*` → `dns_filtering_*`** (added to original Phase A scope mid-flight). Required for plugin-rule compliance: PluginManager rejects declared settings whose names don't start with the plugin directory name. 8 settings, ~30 code references, plus a SQL migration. Without this, every future `PluginManager::sync()` would skip re-seeding defaults and log a warning.
- [x] DB migrations v125 + v126 carry the rename atomically across `plg_plugins.plg_name`, `sct_scheduled_tasks.sct_plugin_name`, `active_theme_plugin` setting, and the 8 `stg_settings` row names. Idempotent.
- [x] ScrollDaddy.app post-deploy verification: HTTP smoke (`/`, `/pricing`, `/login`, theme assets) all 200; `/profile/dns_filtering/devices` redirects correctly; `/profile/scrolldaddy/devices` returns 404 as expected. Formal byte-identical visual regression not done — informal smoke tests pass.

**Deferred (not blocking sister-brand deployment)**
- [ ] Hard-coded brand strings audit (literal `"ScrollDaddy"` / `scrolldaddy.app` in display copy, email templates, settings_form labels). The setting **names** are renamed; the **labels** still say "ScrollDaddy DNS Host" etc. — admin-only impact.
- [ ] Brand-neutralize default profile views (the 14 views in `plugins/dns_filtering/views/profile/` still carry ScrollDaddy-flavored copy; they currently look correct because scrolldaddy.app uses the matching theme's overrides).
- [ ] Class names: `ScrollDaddyApiClient`, `ScrollDaddyHelper`, `SdDevice` / `MultiSdDevice` / `Sd*` model classes, `sdd_*` / `sbf_*` / etc. table prefixes — these aren't covered by any plugin rule and stay as brand-residue. Visible only to developers reading the codebase.
- [ ] Tier feature keys: `scrolldaddy_max_devices`, `scrolldaddy_advanced_filters`, `scrolldaddy_custom_rules`, `scrolldaddy_query_logging`, `scrolldaddy_max_scheduled_blocks` in `sbt_subscription_tiers.sbt_features` JSON — code reads them by exact key, no plugin rule binding. Brand-residue.
- [ ] `device_uid_prefix` setting and `s-`/`n-` prefix backfill — only matters once both deployments are live; currently a single deployment so no collision risk.

**Pipeline gap surfaced and fixed mid-flight (not part of original Phase A scope)**

The first attempt to deploy this refactor exposed a pre-existing bug in `utils/upgrade.php`: the pipeline asks the source for plugins/themes by *prod-side state*, so a renamed plugin's old name returned 404 from source and the new name was never asked for. Recovered via manual tar-pipe of the new directories to scrolldaddy.app, then fixed the pipeline. See [`specs/implemented/upgrade_pipeline_rename_gap.md`](implemented/upgrade_pipeline_rename_gap.md). Deployed in v0.8.30. Future renames will flow through cleanly without manual intervention. As a bonus, the deploy correctly flagged 1 stale plugin (`controld`) and 11 stale themes (legacy tailwind variants superseded by their `-html5` counterparts) on scrolldaddy.app — these can be reviewed and uninstalled at the operator's convenience.

### Phase B — Domain & DNS
- [ ] Register `networksentry.com` (or chosen domain)
- [ ] Point apex + `www` at the docker-prod IP (`23.239.11.53`) via A records
- [ ] Configure DNS for Mailgun sender domain (`mail.networksentry.com`) — MX, TXT (SPF), TXT (DKIM), CNAME (return-path)
- [ ] Configure DNS for inbound (if scoped) — `inbox.networksentry.com` → Mailgun

### Phase C — Container & infrastructure
- [ ] Provision new Docker container on docker-prod following the established pattern (model on `empoweredhealthtn` setup)
- [ ] Provision new PostgreSQL database for NetworkSentry
- [ ] Apache vhost for `networksentry.com` (and `www.networksentry.com` redirect)
- [ ] Let's Encrypt SSL cert
- [ ] Deploy current Joinery release tag into the container
- [ ] Run `update_database` on first boot to materialize schema
- [ ] Seed admin user (claude superuser pattern)
- [ ] Register NetworkSentry as a node in Server Manager (existing `mgn_managed_nodes` infrastructure) so the publish/upgrade pipeline includes it
- [ ] Configure backups (`backup_database.sh` / `backup_project.sh` patterns from `maintenance_scripts/sysadmin_tools/`) for the new container and DB
- [ ] Add log rotation (`logrotate_joinerytest.conf` template) for the new deployment's log files
- [ ] Add NetworkSentry to whatever uptime / error-log monitoring covers ScrollDaddy

### Phase D — Mailgun
- [ ] Add `mail.networksentry.com` to Mailgun account
- [ ] Verify DNS records (DKIM, SPF, return-path) — wait for propagation
- [ ] Mailgun warmup (low send volume for first 7 days; ramp gradually)
- [ ] Configure inbound route (if needed) — `match_recipient .*@inbox.networksentry.com` → webhook URL on NetworkSentry deployment
- [ ] Set NetworkSentry deployment's `stg_settings`: `mailgun_domain`, `mailgun_api_key`, `from_address`, `reply_to_address`, `support_address`

### Phase E — Stripe
- [ ] Add NetworkSentry products and prices to the existing Stripe account
- [ ] Set NetworkSentry deployment's tier-to-price-ID mapping in `stg_settings`
- [ ] Set `stripe_brand_metadata=networksentry` (or equivalent setting) so customer/subscription/payment helpers tag every Stripe object
- [ ] Confirm shared `STRIPE_SECRET_KEY` is set in NetworkSentry deployment's `Globalvars_site.php`
- [ ] Register webhook endpoint `https://networksentry.com/ajax/stripe_webhook` in the Stripe dashboard, listening for the same events ScrollDaddy listens for
- [ ] Verify webhook handler ignores events for customers it doesn't recognize (smoke-test with a ScrollDaddy event)

### Phase F — Theme & content
- [ ] Build `theme/networksentry/` — its own marketing pages (`index.php`, `pricing.php`, etc.), CSS, brand assets, logo, favicon
- [ ] Profile-view overrides only where ScrollDaddy's neutral defaults need brand voice
- [ ] Set NetworkSentry deployment's `theme_template` setting to `networksentry`
- [ ] SEO/OG defaults: `og:site_name`, `og:image`, `twitter:site`, title template — all in NetworkSentry deployment's `stg_settings`
- [ ] `robots.txt`, `sitemap.xml` — render under NetworkSentry's brand context (no special mechanism — each deployment serves its own)
- [ ] Per-brand keys: Google Analytics ID, captcha keys, CSP fragments — `stg_settings`
- [ ] Email templates: clone ScrollDaddy's template directory and rewrite for B2B voice. Operational task; not a platform feature.

### Phase G — Plugin activation
- [ ] Activate `dns_filtering` plugin (formerly `scrolldaddy`) on NetworkSentry deployment
- [ ] Configure plugin settings: resolver host, resolver API key (separate from ScrollDaddy's), tier-to-category mapping
- [ ] If using UID namespacing, set deployment's UID prefix setting (`s-` for ScrollDaddy, `n-` for NetworkSentry) — plugin's UID generator reads this

### Phase H — Resolver
- [ ] Open firewall / pg_hba on NetworkSentry's PostgreSQL to accept connections from the resolver IPs (`45.56.103.84`, `97.107.131.227`)
- [ ] Add NetworkSentry's DB URL to `SCD_JOINERY_DB_URLS` in `/etc/scrolldaddy/scrolldaddy.env` on each DNS server (comma-separated list; existing `SCD_DB_*` vars still work for single-deployment compat)
- [x] **DONE** Roll out the resolver build that supports multiple Joinery DB sources: `internal/config/config.go` (`SCD_JOINERY_DB_URLS`), `internal/db/db.go` (`ConnectURL`, fixed `dns_filtering_blocklist_version` setting key), `internal/cache/cache.go` (per-DB device load + union, multi-DB blocklist merge), `cmd/dns/main.go` (connect loop, reload loops). Build clean, tests pass.
- [ ] Deploy the updated resolver binary: `make release VERSION=…` locally, run installer on each DNS host
- [ ] Mint NetworkSentry API key on the resolver for the thin Joinery → resolver RPCs (`/seen`, `/reload`); store on the NetworkSentry deployment
- [ ] Smoke-test: create a NetworkSentry test device; verify DNS resolution applies expected blocks within the resolver's poll interval
- [ ] Smoke-test: verify ScrollDaddy device behavior is unaffected

### Phase I — End-to-end verification
- [ ] Sign up a test user on `networksentry.com`
- [ ] Receive welcome email from `noreply@mail.networksentry.com` with NetworkSentry URLs
- [ ] Purchase a subscription; receive Stripe receipt with `brand=networksentry` metadata
- [ ] Activate a device; verify it appears on the resolver and filters traffic
- [ ] Confirm ScrollDaddy is unaffected throughout
- [ ] Confirm admin on each deployment shows only that deployment's data

---

## Drift prevention

Two deployments running the same codebase will drift if not actively prevented.

- **Same git tag.** Both deployments deploy from the same Joinery release. Don't cherry-pick fixes onto one without the other.
- **Same `update_database` invocation.** Schema changes from migrations apply to both (run sequentially, not simultaneously). Use the existing publish-upgrade flow with both nodes registered in Server Manager.
- **Per-deployment settings only.** All divergence is in `stg_settings` and theme files — never in code. If you find yourself wanting an `if ($deployment === 'networksentry')` branch, stop; it's a settings or plugin-activation question.
- **Smoke-test on both** before declaring a release complete.

---

## Out of scope (this spec)

- **Cross-brand user identity** — separate user pools by design; no SSO, no shared passwords across brands. If a future brand has meaningful audience overlap and unification becomes valuable, see "How we got here" below for the rejected alternative.
- **Cross-brand admin reporting** — querying across deployments requires either ad-hoc dual-DB queries or a future reporting layer. Not built; build when there's a real need.
- **Org / B2B layer** — see [`FUTURE_organizations.md`](FUTURE_organizations.md). Org accounts are a per-deployment feature; the brand abstraction (or lack thereof) doesn't affect that spec.
- **Per-deployment automated provisioning** — standing up a new sister brand is a manual runbook (above), not a self-service feature. If we ever sell Joinery-as-SaaS to clients who want to spin up brands, that's a separate platform-tooling spec.
- **Replication or shared state between deployments** — explicitly rejected. The only shared state is the resolver, which is already a separate service.

---

## How we got here

This section preserves the design path so future work has the context for *why* we chose separate deployments — and the breadcrumbs to revisit the decision if the trade-off ever inverts.

### The original framing

The first version of this spec proposed **domain-dispatching multi-tenant Joinery** — one deployment, one DB, many domains. The plan was a `brd_brands` table mapping `HTTP_HOST` to a brand record (theme, settings overrides, sender domain, Stripe price IDs); a `BrandContext` singleton populated per request; brand-stamping (`*_brd_brand_id`) on every brand-relevant table; brand-aware settings resolution (`stg_settings` with brand-first → global fallback); brand-aware `SystemMultiBase` query scoping; brand-aware email template lookup with fallback; brand-aware admin filters; and a push/pop `BrandContext` mechanism for cron, CLI, and background email generation (because `HTTP_HOST` doesn't exist there).

That design was internally consistent and would have worked. It was rejected because:

1. **The benefits are theoretical for two disjoint-audience brands.** ScrollDaddy (consumer recovery) and NetworkSentry (B2B network filtering) have approximately zero user overlap. The "unified user pool" gives nothing real; the "cross-brand admin" is a manual annoyance for an operator running two brands, but it's the operator's annoyance, not the user's. Single Stripe customer per human across brands sounded clean, but the same human almost never has both accounts.

2. **The cost compounds forever.** Brand-aware settings, brand-scoped queries, brand-context propagation in background jobs, plugin/brand activation matrices — every cross-cutting feature picks up a brand dimension forever, including features added years from now by future contributors. The cost isn't a one-time refactor; it's an ongoing tax on the platform.

3. **Standing up a separate Joinery deployment is an established pattern.** `empoweredhealthtn` is already deployed this way. NetworkSentry is the second instance, not a new architecture.

4. **Future Joinery clients shouldn't pay for it.** If someone deploys Joinery for their own purposes (the marketing pitch on `getjoinery.com`), they shouldn't inherit complexity that exists only because the original ScrollDaddy operator wanted two brands.

### What was kept from the unified design

- **Plugin / theme decoupling refactor.** Stands on its own merit even single-brand. Required for sister-brand deployment regardless of approach.
- **Hard-coded brand strings audit.** Same.
- **Stripe metadata convention** (`brand=<code>` on customers/subs). Cheap, useful for cross-deployment reporting if you ever consolidate views.

### When to revisit

The unified design is the correct answer if any of the following becomes true:
- A real B2B brand with meaningful user overlap with ScrollDaddy launches (e.g., a "ScrollDaddy for Schools" where parent users naturally bridge)
- The operator stands up 5+ brands and the per-deployment overhead (5 DBs, 5 Mailgun domains, 5 deploy steps per release) becomes painful
- A cross-brand product feature emerges that requires shared identity or shared data (cross-brand referrals with shared rewards, cross-brand single-checkout, etc.)

If the trade-off inverts, the unified design (recoverable from this file's git history) is a sound starting point. None of the work done under this spec precludes the unified path: plugin/theme decoupling, brand-neutral defaults, Stripe metadata, and `dns_filtering` plugin renaming all carry forward unchanged.

### Org/B2B layer interaction

The org spec ([`FUTURE_organizations.md`](FUTURE_organizations.md)) was written assuming a single Joinery deployment. With separate-deployment branding, that spec describes a per-deployment feature: each Joinery instance can independently grow an org layer. The architecture audit there (no current blockers to adding orgs) is unchanged.

---

## Documentation updates

When this spec is implemented, update:
- `docs/deploy_and_upgrade.md` — add a section on running multiple sister deployments off the same release tag, and the "register every deployment in Server Manager" pattern for the upgrade pipeline.
- `docs/scrolldaddy_plugin.md` (rename to `dns_filtering_plugin.md` if the plugin is renamed) — note that the plugin is brand-neutral and the resolver pulls device state from each Joinery DB.
- `docs/server_manager.md` — note that one Server Manager instance can manage multiple sister-brand deployments.
- `/home/user1/scrolldaddy-dns/README.md` and the resolver's ops guide — document the multi-DB configuration (`JOINERY_DB_URLS`) and the operational expectation that each Joinery exposes its DB to the resolver IPs.
- `CLAUDE.md` — add NetworkSentry's container/DB info to the "Docker Production Server" section once it's stood up.
- New `docs/sister_brand_runbook.md` — extract the deployment runbook from this spec into a living doc that future brand stand-ups can follow without re-reading the design rationale.
