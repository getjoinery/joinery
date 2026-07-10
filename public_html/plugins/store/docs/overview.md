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

## Activation

`activate.php` is idempotent and self-guarded. It backfills `stc_stripe_customers` from the pre-extraction user columns and product fulfillment columns where those still exist, claims the plugin's scheduled-task rows, and drops the superseded columns. On upgrade, `update_database` runs a one-time auto-activation: the store activates when the install shows store evidence (product/order rows, or a Stripe/PayPal key configured); a store-less install stays inactive.

Settings keep their pre-extraction core names (`products_active`, `checkout_type`, `stripe_api_key`, ...) via the per-setting `legacy_core: true` flag in `plugin.json`.

## Related Docs

- [Product Requirements](product_requirements.md) — collecting data from buyers at checkout
- [Product Purchase Hooks](product_purchase_hooks.md) — post-purchase hook convention
- [Subscription Tiers](../../../docs/subscription_tiers.md) — the core/billing split
