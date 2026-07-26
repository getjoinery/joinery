# Store

## Overview

The Store plugin (`/plugins/store/`) is the platform's commerce subsystem: products, the shopping cart, checkout, orders, coupons, subscriptions, and payment-provider integration (Stripe and PayPal). It owns every "money" surface — with the plugin inactive, the platform runs as a pure membership/content site: no cart in the header, store URLs hard-404, and tier gating falls back to its contact-us prompt.

**Owns:** `pro_products` (+ versions, groups, details), `ord_orders` / `odi_order_items`, coupon codes, product requirements, `stc_stripe_customers`, Stripe invoices.

**Core URLs (plugin-delegated in serve.php):** `/products`, `/product/{slug}`, `/pricing`, `/cart`, `/checkout`, `/cart_charge`, `/cart_confirm`, `/cart_clear`, and the `/profile/orders`, `/profile/billing`, `/profile/subscriptions` pages. Admin pages live at `/plugins/store/admin/*`.

**Webhooks:** `/ajax/stripe_webhook` and `/ajax/paypal_subscription_webhook` (URLs registered with the payment providers; files live in `plugins/store/ajax/`).

## Key Pieces

| Piece | Role |
|-------|------|
| `includes/ShoppingCart.php` | Cart state. Session storage is plain array data only (`ShoppingCart::current()` rehydrates; mutators persist) — never a serialized object |
| `includes/TierBilling.php` | The billing half of subscription tiers: purchase-driven tier grants, upgrade options, renewal/expiry. Tiers themselves are core — see [Subscription Tiers](../../../docs/subscription_tiers.md) |
| `includes/FulfillmentRegistry.php` | Post-purchase fulfillment providers. A product carries `pro_fulfillment_provider` + `pro_fulfillment_ref`; checkout resolves the provider at charge time. event_manager registers `event_registration` here |
| `includes/StripeHelper.php` / `PaypalHelper.php` | Payment provider clients |
| `includes/requirements/` | Product requirements (data collected from buyers at checkout) — see [Product Requirements](product_requirements.md) |
| `data/stripe_customers_class.php` | User ↔ Stripe customer mapping (`StripeCustomer::GetForUser()`) |
| `tasks/` | `ReconcileStripeSubscriptions`, `SyncPaypalSubscriptions` scheduled tasks |

## Extension-Point Registrations (serve.php)

The plugin registers its providers with core registries at load time: SEO metadata (`product`), tier-gated content summary (`Products`), entity photos (`product`), the header cart menu provider, profile-dashboard sections (recent orders, subscriptions), and admin-user panels (Orders, Subscriptions).

## Checkout callers and guest checkout

Checkout page JS drives the cart and charge through `/api/v1` actions using the
browser-session credential (session cookie + the `X-Joinery-Csrf` header), the
same as the rest of the platform — see [API § Authentication](../../../docs/api.md).

Checkout does not require an account. The charge-side actions declare
`allow_guest`, so an **anonymous browser session** — a visitor with a session
cookie but no login — can complete a purchase. Guest-reachable page JS reads its
CSRF token from the `joinery_api_csrf` cookie (falling back to the
`joinery-api-csrf` meta tag) rather than the meta tag alone, because product and
checkout pages may be cached and the meta tag cannot be baked into a cached page.
Everything else stays locked down: an anonymous caller is denied by default and
reaches only the actions that opt in with `allow_guest`. See
[API § Authentication](../../../docs/api.md) for the anonymous-principal contract.

## Activation

`activate.php` is idempotent and self-guarded. It backfills `stc_stripe_customers` from the pre-extraction user columns and product fulfillment columns where those still exist, claims the plugin's scheduled-task rows, and drops the superseded columns. On upgrade, `update_database` runs a one-time auto-activation: the store activates when the install shows store evidence (product/order rows, or a Stripe/PayPal key configured); a store-less install stays inactive.

Settings keep their pre-extraction core names (`products_active`, `checkout_type`, `stripe_api_key`, ...) via the per-setting `legacy_core: true` flag in `plugin.json`. Declaring them there is also what puts them on **Admin → Settings → Plugin Settings**; the payment credentials additionally appear on the store's own Payment Settings tab, which wraps them with live connection tests.

### Optional piggyback donation

A buyer entering an amount in a `UserPriceRequirement` field ("User Chooses Price" on the product edit page) gets a second cart item: the site's donation product, identified by the `store_optional_donation_product_id` setting (the "Optional donation product" dropdown on the settings page). The donation product prices itself from the buyer's entered amount (`Product::is_optional_donation()`); it shows no fixed price in listings. Blank setting = feature off — entered amounts are logged and skipped, never charged. The donation product is never identified by a hardcoded product ID.

## Related Docs

- [Product Requirements](product_requirements.md) — collecting data from buyers at checkout
- [Product Purchase Hooks](product_purchase_hooks.md) — post-purchase hook convention
- [Subscription Tiers](../../../docs/subscription_tiers.md) — the core/billing split
