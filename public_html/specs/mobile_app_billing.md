# Mobile App Billing — Apple IAP & Google Play Billing

**Purpose:** Sell subscriptions inside the ScrollDaddy mobile apps. Both
stores require their own billing system for digital subscriptions (Apple:
In-App Purchase; Google: Play Billing), each taking 15–30%. This spec covers
the shared server-side model, the two store integrations, and the in-app
purchase flows.

**Created:** 2026-06-11
**Updated:** 2026-07-16 — anchored names to the real code paths, decided
webhook placement, specified the client kit slots, added the member-app
source-routing consequence.

**Status:** Active — not yet implemented. Not a launch blocker for either
app: both launch login-only (accounts and subscriptions are created on the
website), and every app phase ships without this spec. Each store
integration is independently shippable, in either order, any time after the
respective app's first store release.

**Depends on (implemented):** the subscription tier system
(`docs/subscription_tiers.md`), and the store plugin's tier billing path.
Assignment is `TierBilling::handleProductPurchase()` →
`SubscriptionTier::addUser()`; revocation is
`TierBilling::handleSubscriptionExpired()` →
`SubscriptionTier::removeUserFromAllTiers()`. (`change_tier` is one
*caller* of these primitives, not the path itself — do not wire into
`change_tier_logic`.) The integration pattern to mirror is
`plugins/store/includes/StripeHelper.php` +
`plugins/store/ajax/stripe_webhook.php`: signature verification before any
state change, `WebhookLog::isDuplicate()` idempotency.

**Consumed by:** `specs/implemented/scrolldaddy_ios_app.md`,
`specs/implemented/scrolldaddy_android_app.md` (each references this spec
for its billing phase). The Joinery member apps
(`specs/ios_member_app_release.md`, `specs/android_member_app_release.md`)
still sell nothing in-app; they consume only the subscription-source
routing on their existing subscriptions screens (see "Member apps" below).

---

## Shared model (built once, used by both stores)

- **Subscription source exclusivity.** A user has exactly one subscription
  source: Stripe, App Store, or Play Store. The server records the source
  and routes manage/cancel/upgrade to it — Stripe-managed subscriptions
  manage on the website, store-managed ones deep-link to the store's
  subscription management. A user with an active subscription from one
  source cannot start one from another; the app shows the existing source
  instead of a purchase button. This is a new **stored** field: today
  source is derived at read time (`subscription_summary_logic.php` infers
  stripe/paypal/none from which provider-ID column on the order item is
  populated) and nothing enforces exclusivity — both the field and the
  enforcement are net-new.
- **Product-ID → tier mapping.** Admin-configured mapping from store product
  identifiers to subscription tiers, one admin surface covering both stores
  (a product row carries its store). This is a new table and a new admin
  page — the only mapping today is the tier dropdown on
  `admin_product_edit` (`pro_sbt_subscription_tier_id`), which stays as-is
  for web-sold products. Purchases and renewal events drive
  `TierBilling::handleProductPurchase()` — no second way to set a user's
  tier.
- **Pricing.** Store cut is 15–30%; IAP tier prices are set per product ID
  in the mapping (admin-priced), not derived from the Stripe prices.

## Webhook placement (decision)

New endpoints never go in `/ajax/` (CLAUDE.md, `docs/api.md` forward rule)
— but that rule exists because `/api/v1` actions authenticate with a
session or API-key credential, and provider webhooks can't: they are
machine-to-machine calls authenticated by payload signature, with no
session or CSRF. Provider webhooks are therefore the one standing
exception, and the new ones live where the existing ones do:
`plugins/store/ajax/`, alongside `stripe_webhook.php` and
`paypal_subscription_webhook.php`. Implementation records this carve-out
in `docs/api.md` so the forward rule and reality stop conflicting.

## Apple In-App Purchase

- **`AppStoreHelper`** (`plugins/store/includes/`, parallel to
  `StripeHelper`): validates StoreKit 2 transactions server-side via the
  App Store Server API.
- **Webhook** (`plugins/store/ajax/app_store_webhook.php`) for App Store
  Server Notifications V2 — renewals, cancellations, refunds, billing
  retries — the same role the Stripe webhook plays.
- **Client (`JoineryBillingKit`):** StoreKit 2 purchase, upgrade/downgrade,
  and restore-purchases flows; subscription status screen reflects the
  server's view, not StoreKit's.

## Google Play Billing

- **`GooglePlayHelper`** (`plugins/store/includes/`, parallel to the
  above): verifies purchases server-side via the Play Developer API.
- **Webhook** (`plugins/store/ajax/play_rtdn_webhook.php`) for Real-Time
  Developer Notifications (Pub/Sub) — renewals, cancellations, refunds.
- **Client (`joinery-android-billing`):** Play Billing purchase and change
  flows; same server-authoritative status rule.
- **Escape hatch (noted, not planned):** direct APK / F-Droid distribution
  is legal on Android and skips Play Billing entirely.

## Client entry points (both platforms)

Billing is a feature kit, not core — the core libraries (`JoineryKit`,
`joinery-android`) stay brand- and feature-free. Each platform gets a new
kit following the established `registerScreens()` pattern:

- **iOS:** `JoineryBillingKit` library product in the `joinery-kit`
  package (parallel to `JoineryDNSFilterKit`), exposing
  `JoineryBilling.registerScreens()`.
- **Android:** `joinery-android-billing` feature module (parallel to
  `joinery-android-dnsfilter`), exposing `JoineryBilling.registerScreens()`.

Each registers a `billing` native screen in `NativeScreenRegistry` —
purchase, plan change, restore, subscription status, and manage-routing by
source — with the web pricing page as the fallback URL. The ScrollDaddy
app shells add the kit as a dependency; the server flips the nav entry to
native once the installed app version registers the screen. Purchase
validation calls ride the existing `POST /api/v1/action/{action}` device
session-key convention (same shape as `store/orders_recurring_action`).
The member kits' existing read-only `subscriptions` screen is a different
surface and keeps its name — a selling app ships the billing kit in
addition to whatever else it uses.

## Member apps: source routing (in scope)

The member apps' native subscriptions screens
(`JoineryMemberKit/SubscriptionsScreen.swift`,
`memberkit/SubscriptionsScreen.kt`) show the manage/billing rows only when
`payment_source == "stripe"`. Once store sources exist, a member who
subscribed inside a store app would otherwise see no management surface at
all. Both screens gain the store branch: `app_store` / `play_store`
sources deep-link to the store's subscription management and hide the
Stripe web rows. This is the only member-app work in this spec — the
member apps still sell nothing in-app.

## Store-policy consequences inside the apps

Selling in-app changes what the apps must show: a purchase surface, price
display in store currency, and store-mandated subscription disclosures.
Until this spec ships, the apps offer no purchase and make no reference to
where to pay (the safe login-only pattern); the optional US external-link
entitlement on iOS is a decision to make at implementation time, not before.

---

## Concerns & Edge Cases

- **Webhook trust:** both store webhooks must verify signatures (App Store:
  signed JWS payloads; Play: Pub/Sub push authentication) before touching
  tier state, mirroring the Stripe webhook's verification discipline.
- **Source transitions:** a user who cancels their store subscription and
  later subscribes via Stripe (or vice versa) is a normal flow — exclusivity
  applies to *active* subscriptions only. Lapsed source records are kept for
  history, not enforcement.
- **Refunds revoke tier benefits** via
  `TierBilling::handleSubscriptionExpired()` — the same full-removal path
  expiration uses (there is no graded "downgrade on refund"). The trigger
  is net-new: no refund handler exists today for any provider, so the
  store webhooks' refund events are the first refund-driven revocation in
  the system. The revoke function already exists; only the trigger is new.
- **Sandbox/test purchases** (App Store sandbox, Play license testers) must
  map to the dev deployment's tiers without polluting production billing
  records.

## Scope

- Stored subscription source field + exclusivity enforcement in the tier
  system.
- Product-ID → tier mapping table and admin surface (both stores).
- `AppStoreHelper` + App Store Server Notifications V2 webhook
  (`plugins/store/`).
- `GooglePlayHelper` + RTDN webhook (`plugins/store/`).
- `JoineryBillingKit` (iOS) and `joinery-android-billing` (Android)
  feature kits registering the `billing` native screen (purchase, change,
  restore, manage-routing).
- Store-source routing on the member kits' existing subscriptions screens.
- Tests under `/tests/` for webhook signature verification, tier
  assignment/revocation on purchase/renewal/refund events, and source
  exclusivity.

## Documentation

- New `docs/mobile_billing.md` — the subscription-source model, product
  mapping admin, both helpers and webhooks, written as current state.
- `docs/subscription_tiers.md` — subscription source field and how store
  events drive tier assignment.
- `docs/api.md` — any billing-related action endpoints added for the apps,
  and the provider-webhook placement carve-out.
- `docs/mobile_apps.md` — the billing kit entry points and the `billing`
  native screen.
