# Sister Brand Deployment — Remaining Work

## Context

The platform-level architecture work enabling sister-brand deployments is complete. See [`specs/implemented/multiple_domain_capability.md`](implemented/multiple_domain_capability.md) for the full design rationale, the rejected multi-tenant alternative, and what was shipped.

**What's done:**
- Plugin renamed `scrolldaddy` → `dns_filtering`; theme split into `theme/scrolldaddy/`
- All settings renamed `scrolldaddy_*` → `dns_filtering_*`
- DNS resolver supports multiple Joinery DBs via `SCD_JOINERY_DB_URLS` (v1.8.0, deployed to both servers)
- Resolver blocklist version key bug fixed (`dns_filtering_blocklist_version`)

**What remains:** platform code items that must be completed before a second deployment goes live, followed by the operational runbook for standing up the second site.

---

## Platform Code (complete before launch)

### 1. Stripe brand metadata + webhook filtering

Each deployment must tag every Stripe customer and subscription it creates with `brand=<code>` metadata, and each webhook handler must ignore events for brands it doesn't own.

**`stg_settings` declaration** — add to `settings.json` (core settings):
```json
{ "name": "stripe_brand_code", "default": "" }
```
ScrollDaddy's deployment gets `stripe_brand_code=scrolldaddy`; NetworkSentry gets `stripe_brand_code=networksentry`. Empty string means tagging is disabled (safe default for existing deployment during rollout).

**`includes/StripeHelper.php`** — three places to add `'metadata' => ['brand' => $brand_code]`:

1. `create_customer_at_stripe()` (~line 665): add `'metadata'` to the `customers->create()` call.
2. Subscription creation (~line 1270): add `'metadata'` key to `$subscription_params`.
3. Checkout session builder (~line 566): add `'metadata'` to `$create_list['subscription_data']` when `$contains_subscription` is true (create the array if it doesn't exist yet).

In all three places: read `$brand_code = Globalvars::get_instance()->get_setting('stripe_brand_code')` and only add the metadata key when `$brand_code` is non-empty.

**`ajax/stripe_webhook.php`** — add a brand filter after the idempotency check. For events that carry a `customer` ID (`customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`): retrieve the Stripe customer, check `$customer->metadata->brand`, and if it doesn't match this deployment's `stripe_brand_code`, log and return 200 without acting. Skip the check when `stripe_brand_code` is empty (single-deployment compat).

`checkout.session.completed` is naturally scoped because it uses `client_reference_id` to find the local user — a foreign-brand session will have a `client_reference_id` that doesn't match any local user, which is already handled gracefully.

### 2. Device UID prefix

`plugins/dns_filtering/data/devices_class.php:67` — the UID generator currently calls `bin2hex(random_bytes(16))` directly. Add a `device_uid_prefix` setting (declare in `plugins/dns_filtering/plugin.json` under `settings`, default `""`). On device creation, prepend the prefix and shorten the random part so the total stays within the `varchar(32)` column:

- No prefix: `bin2hex(random_bytes(16))` — 32 hex chars (current behavior, unchanged)
- With prefix (e.g. `s-`): prefix (2 chars) + `bin2hex(random_bytes(15))` — 32 chars total

Set `device_uid_prefix=s-` on ScrollDaddy and `device_uid_prefix=n-` on NetworkSentry before either deployment has production users, or backfill existing UIDs with a one-time UPDATE (safe pre-launch since no production users yet).

### 3. Brand-neutralize default profile views

The 14 views in `plugins/dns_filtering/views/profile/` carry ScrollDaddy-flavored copy. A new deployment would show this copy verbatim unless its theme overrides every view. Remove brand-specific strings and replace with generic functional language. The ScrollDaddy theme's view overrides in `theme/scrolldaddy/views/profile/` then provide the brand voice on scrolldaddy.app.

Views to audit: `devices.php`, `device_edit.php`, `querylog.php`, `scheduled_block_edit.php`, `activation.php`, `mobileconfig.php`, and any others under that directory. Grep for `"ScrollDaddy"`, `"scrolldaddy"`, `"scrolldaddy.app"`.

### 4. Settings form labels (admin-only)

`plugins/dns_filtering/settings_form.php` still uses labels like "ScrollDaddy DNS Host", "ScrollDaddy Internal URL", "ScrollDaddy DNS API Key". These are admin-facing only and have zero user impact, but a second deployment's admin would see "ScrollDaddy" everywhere. Update labels to be generic: "DNS Host", "Internal URL", "DNS API Key", etc.

---

## Deployment Runbook (when ready to launch)

The second deployment is currently named "NetworkSentry" in this spec. Substitute the actual chosen domain/brand name throughout.

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
- [ ] Register NetworkSentry as a node in Server Manager so the publish/upgrade pipeline includes it
- [ ] Configure backups (`backup_database.sh` / `backup_project.sh`) for the new container and DB
- [ ] Add log rotation for the new deployment's log files
- [ ] Add NetworkSentry to uptime / error-log monitoring

### Phase D — Mailgun
- [ ] Add `mail.networksentry.com` to Mailgun account
- [ ] Verify DNS records (DKIM, SPF, return-path) — wait for propagation
- [ ] Mailgun warmup (low send volume for first 7 days; ramp gradually)
- [ ] Configure inbound route if needed — `match_recipient .*@inbox.networksentry.com` → webhook URL on NetworkSentry deployment
- [ ] Set NetworkSentry deployment's `stg_settings`: `mailgun_domain`, `mailgun_api_key`, `from_address`, `reply_to_address`, `support_address`

### Phase E — Stripe
- [ ] Add NetworkSentry products and prices to the existing Stripe account
- [ ] Set NetworkSentry deployment's tier-to-price-ID mapping in `stg_settings`
- [ ] Set `stripe_brand_code=networksentry` in NetworkSentry deployment's `stg_settings`; set `stripe_brand_code=scrolldaddy` on ScrollDaddy's deployment
- [ ] Confirm shared `STRIPE_SECRET_KEY` is set in NetworkSentry deployment's `Globalvars_site.php`
- [ ] Register webhook endpoint `https://networksentry.com/ajax/stripe_webhook` in the Stripe dashboard
- [ ] Smoke-test brand filtering: trigger a ScrollDaddy subscription event, confirm NetworkSentry webhook ignores it

### Phase F — Theme & content
- [ ] Build `theme/networksentry/` — marketing pages (`index.php`, `pricing.php`, etc.), CSS, brand assets, logo, favicon
- [ ] Profile-view overrides only where the neutral defaults need NetworkSentry brand voice
- [ ] Set NetworkSentry deployment's `active_theme` setting to `networksentry`
- [ ] SEO/OG defaults: `og:site_name`, `og:image`, `twitter:site`, title template — in NetworkSentry deployment's `stg_settings`
- [ ] Per-brand keys: Google Analytics ID, captcha keys, CSP fragments — `stg_settings`
- [ ] Email templates: clone ScrollDaddy's template directory, rewrite for B2B voice

### Phase G — Plugin activation
- [ ] Activate `dns_filtering` plugin on NetworkSentry deployment
- [ ] Configure plugin settings: `dns_filtering_dns_host`, `dns_filtering_dns_internal_url`, `dns_filtering_dns_api_key`, etc.
- [ ] Set `device_uid_prefix=n-` on NetworkSentry; set `device_uid_prefix=s-` on ScrollDaddy (and backfill existing ScrollDaddy UIDs if not already done)
- [ ] Set tier-to-category mapping for NetworkSentry's subscription tiers

### Phase H — Resolver
- [ ] Open firewall / pg_hba on NetworkSentry's PostgreSQL to accept connections from resolver IPs (`45.56.103.84`, `97.107.131.227`)
- [ ] Add NetworkSentry's DB DSN to `SCD_JOINERY_DB_URLS` in `/etc/scrolldaddy/scrolldaddy.env` on each DNS server; `systemctl restart scrolldaddy-dns` on each
- [ ] Mint NetworkSentry API key on the resolver; store in NetworkSentry deployment's plugin settings
- [ ] Smoke-test: create a NetworkSentry test device; verify DNS resolution applies expected blocks within the resolver's poll interval
- [ ] Smoke-test: verify ScrollDaddy device behavior is unaffected

### Phase I — End-to-end verification
- [ ] Sign up a test user on `networksentry.com`
- [ ] Receive welcome email from `noreply@mail.networksentry.com` with NetworkSentry URLs
- [ ] Purchase a subscription; confirm Stripe customer/subscription carries `brand=networksentry` metadata
- [ ] Activate a device; verify it appears on the resolver and filters traffic
- [ ] Confirm ScrollDaddy is unaffected throughout
- [ ] Confirm admin on each deployment shows only that deployment's data
- [ ] Update `CLAUDE.md` with NetworkSentry container/DB info

---

## Drift prevention

Two deployments running the same codebase will drift if not actively prevented.

- **Same git tag.** Both deployments deploy from the same Joinery release. Don't cherry-pick fixes onto one without the other.
- **Same `update_database` invocation.** Schema changes from migrations apply to both (run sequentially). Use the existing publish-upgrade flow with both nodes registered in Server Manager.
- **Per-deployment settings only.** All divergence is in `stg_settings` and theme files — never in code. If you find yourself wanting an `if ($deployment === 'networksentry')` branch, stop; it's a settings or plugin-activation question.
- **Smoke-test on both** before declaring a release complete.
