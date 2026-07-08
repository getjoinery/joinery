# Mobile App Billing — Apple IAP & Google Play Billing

**Purpose:** Sell subscriptions inside the ScrollDaddy mobile apps. Both
stores require their own billing system for digital subscriptions (Apple:
In-App Purchase; Google: Play Billing), each taking 15–30%. This spec covers
the shared server-side model, the two store integrations, and the in-app
purchase flows.

**Created:** 2026-06-11

**Status:** Active — not yet implemented. Not a launch blocker for either
app: both launch login-only (accounts and subscriptions are created on the
website), and every app phase ships without this spec. Each store
integration is independently shippable, in either order, any time after the
respective app's first store release.

**Depends on (implemented):** the subscription tier system
(`docs/subscription_tiers.md`), `StripeHelper` as the integration pattern,
and the `change_tier` tier-assignment path.

**Consumed by:** `specs/implemented/scrolldaddy_ios_app.md`,
`specs/implemented/scrolldaddy_android_app.md` (each references this spec for its
billing phase).

---

## Shared model (built once, used by both stores)

- **Subscription source exclusivity.** A user has exactly one subscription
  source: Stripe, App Store, or Play Store. The server records the source
  and routes manage/cancel/upgrade to it — Stripe-managed subscriptions
  manage on the website, store-managed ones deep-link to the store's
  subscription management. A user with an active subscription from one
  source cannot start one from another; the app shows the existing source
  instead of a purchase button.
- **Product-ID → tier mapping.** Admin-configured mapping from store product
  identifiers to subscription tiers, one admin surface covering both stores
  (a product row carries its store). Purchases and renewal events drive the
  same tier-assignment path `change_tier` uses — no second way to set a
  user's tier.
- **Pricing.** Store cut is 15–30%; IAP tier prices are set per product ID
  in the mapping (admin-priced), not derived from the Stripe prices.

## Apple In-App Purchase

- **`AppStoreHelper`** (core, parallel to `StripeHelper`): validates
  StoreKit 2 transactions server-side via the App Store Server API.
- **Webhook** in `/ajax/` for App Store Server Notifications V2 — renewals,
  cancellations, refunds, billing retries — the same role Stripe webhooks
  play.
- **Client (JoineryKit billing entry point):** StoreKit 2 purchase,
  upgrade/downgrade, and restore-purchases flows; subscription status screen
  reflects the server's view, not StoreKit's.

## Google Play Billing

- **`GooglePlayHelper`** (core, parallel to the above): verifies purchases
  server-side via the Play Developer API.
- **Webhook** for Real-Time Developer Notifications (Pub/Sub) — renewals,
  cancellations, refunds.
- **Client (`joinery-android` billing entry point):** Play Billing purchase
  and change flows; same server-authoritative status rule.
- **Escape hatch (noted, not planned):** direct APK / F-Droid distribution
  is legal on Android and skips Play Billing entirely.

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
- **Refunds revoke tier benefits** through the same downgrade path
  expiration uses — no special-case removal logic.
- **Sandbox/test purchases** (App Store sandbox, Play license testers) must
  map to the dev deployment's tiers without polluting production billing
  records.

## Scope

- Subscription source field + exclusivity enforcement in the tier system.
- Admin product-ID → tier mapping surface (both stores).
- `AppStoreHelper` + App Store Server Notifications V2 webhook.
- `GooglePlayHelper` + RTDN webhook.
- JoineryKit and `joinery-android` billing entry points (purchase, change,
  restore, manage-routing).
- Tests under `/tests/` for webhook signature verification, tier
  assignment/revocation on purchase/renewal/refund events, and source
  exclusivity.

## Documentation

- New `docs/mobile_billing.md` — the subscription-source model, product
  mapping admin, both helpers and webhooks, written as current state.
- `docs/subscription_tiers.md` — subscription source field and how store
  events drive tier assignment.
- `docs/api.md` — any billing-related action endpoints added for the apps.
