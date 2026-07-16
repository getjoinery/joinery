# Mobile App Billing (Apple IAP & Google Play)

Subscriptions sold inside the mobile apps bill through the platform stores —
Apple In-App Purchase on iOS, Play Billing on Android. The server is the
authority: every purchase is verified server-side, becomes a normal Order +
OrderItem, and grants its tier through the same `TierBilling` path web
checkout uses. The apps never grant anything locally.

## Subscription source

Every subscription order item carries a payment source in
`odi_payment_source`: `stripe`, `paypal`, `app_store`, or `play_store`.
`OrderItem::get_payment_source()` reads the stored value and falls back to
deriving from the provider-ID columns for rows that predate the field.

**Source exclusivity:** a user has at most one active subscription source. A
user with an active subscription from one source cannot start one from
another — `TierBilling::sourceConflict($user_id, $new_source)` is the gate,
enforced in the mobile claim actions, `change_tier` (web plan changes are
refused for store-billed subscriptions), and pre-charge in the cart for
subscription products. Lapsed subscriptions don't count: cancel in one
source, subscribe in another is a normal flow.

Store-billed subscriptions are managed in their store. The web change-tier
page and the native member subscriptions screens hide their money actions for
`app_store`/`play_store` sources and deep-link to the store's subscription
management instead; `subscription_summary` reports `can_cancel: false` for
them.

## Product mapping

**Admin: Products → Mobile Store Products**
(`/plugins/store/admin/admin_store_product_mappings`). Each row maps a store
product identifier (configured in App Store Connect / the Play Console) to
the Joinery product it sells, optionally pinned to a product version for the
billing period. The product's own tier dropdown
(`pro_sbt_subscription_tier_id`) determines the granted tier — one grant
path for web and store sales alike. Model:
`plugins/store/data/mobile_store_products_class.php` (`MobileStoreProduct`,
table `msp_mobile_store_products`). One active mapping per (store, store
product ID).

Prices are set in the store consoles; the apps display store-localized
prices from StoreKit / Play Billing. The mapping carries no price.

## Purchase flow (both stores)

1. The app loads `store/billing_catalog` (`{store: app_store|play_store}`):
   the mapped plans, the caller's `active_source`, `can_purchase`, and an
   `app_account_token`.
2. The app runs the store's purchase sheet, attaching the account token
   (`appAccountToken` on iOS, `obfuscatedAccountId` on Android).
3. The app posts the purchase for validation — `store/app_store_claim`
   (`{jws}`: the StoreKit 2 signed transaction) or `store/play_claim`
   (`{purchase_token, package_name}`). The server verifies it (see below),
   enforces exclusivity, creates the Order/OrderItem, grants the tier via
   `TierBilling::handleProductPurchase()`, and (Play) acknowledges the
   purchase. Claims are idempotent — restore purchases re-claims safely.
4. Renewals, cancellations, refunds, and billing failures arrive via the
   store webhooks and update the same order item.

The account token (`MobileBilling::appAccountTokenForUser()`) is a
deterministic, reversible UUID, so a webhook event that arrives with no prior
claim can still be linked to its user and create the subscription.

## Verification

**Apple** (`plugins/store/includes/AppStoreHelper.php`): StoreKit 2
transactions and App Store Server Notifications V2 are JWS payloads carrying
their x5c certificate chain. `verifySignedPayload()` validates every chain
link, certificate validity windows, and that the chain anchors at the pinned
Apple Root CA - G3 (`plugins/store/includes/certs/AppleRootCA-G3.pem`), then
verifies the ES256 signature — all offline. The App Store Server API client
(ES256 JWT auth) backs `update_subscription_in_order_item()` for lazy status
refresh.

**Google** (`plugins/store/includes/GooglePlayHelper.php`): purchase tokens
are never trusted from the client — the server fetches the authoritative
purchase from the Play Developer API (`subscriptionsv2`, service-account
OAuth2 from `store_play_service_account_json`). RTDN push requests authenticate
with a Google OIDC bearer token verified against Google's signing keys and
the `store_play_rtdn_audience` setting.

## Webhooks

Provider webhooks are the documented `/ajax/` exception (see
`docs/api.md` § Authentication): machine-to-machine calls authenticated by
payload signature, not session credentials.

- **`/ajax/app_store_webhook`** — App Store Server Notifications V2.
  Idempotent on `notificationUUID` via `WebhookLog`.
- **`/ajax/play_rtdn_webhook`** — Real-Time Developer Notifications
  (Pub/Sub push). The RTDN is only a poke; the handler fetches current state
  from the Play Developer API. Idempotent on the Pub/Sub message ID.

Event handling lives in `plugins/store/includes/MobileBilling.php`
(`applyAppStoreEvent` / `applyPlayEvent`), shared with the claim actions:

| Store event | Effect |
|---|---|
| Renewal (DID_RENEW / RTDN 2) | status active, period end extended |
| Auto-renew off (DID_CHANGE_RENEWAL_STATUS / state CANCELED) | `odi_subscription_cancel_at_period_end`, entitled until expiry |
| Billing failure (DID_FAIL_TO_RENEW / ON_HOLD) | `grace_period`/`past_due` + `subscription.payment_failed` signal; grace period stays entitled |
| Expiry (EXPIRED / RTDN 13) | order item cancelled + `TierBilling::handleSubscriptionExpired()` revokes the tier |
| Refund (REFUND, REVOKE / RTDN 12) | refund fields recorded + same revocation path |
| Plan change | order item remapped to the new store product's plan, tier re-granted |

## Sandbox and test purchases

Sandbox App Store payloads (`environment: Sandbox`) and Play license-tester
purchases (`testPurchase` present) are accepted only when the deployment's
`debug` setting is on — dev deployments process them (`ord_test_mode`,
`odi_store_environment` mark them), production acknowledges and ignores
them.

## Settings (store plugin)

| Setting | Meaning |
|---|---|
| `store_app_store_issuer_id`, `store_app_store_key_id`, `store_app_store_private_key` | App Store Server API credentials (the .p8 key contents) |
| `store_app_store_bundle_ids` | Comma-separated bundle IDs allowed to bill here |
| `store_play_package_names` | Comma-separated Android package names allowed to bill here |
| `store_play_service_account_json` | Play Developer API service-account key (JSON) |
| `store_play_rtdn_audience` | Expected `aud` on RTDN push OIDC tokens; RTDN is refused while empty |

All default empty — mobile billing is inert until configured.

## Client kits

- **iOS:** `JoineryBillingKit` (library in `ios/joinery-kit`), registered via
  `JoineryBilling.registerScreens()` — StoreKit 2 purchase, restore,
  server-authoritative status.
- **Android:** `joinery-android-billing` Gradle module, registered via
  `JoineryBilling.registerScreens()` — Play Billing purchase sheet, restore,
  same status rule.

Both register the `billing` native screen; a menu entry with
`nativeScreen: "billing"` lights it up, with the web pricing page as the
version-skew fallback. See `docs/mobile_apps.md`.

## Tests

`plugins/store/tests/mobile_billing_test.php` (tier `db`) — JWS chain and
OIDC bearer verification against locally-generated keys (the helpers expose
test-seam overrides for the pinned root / JWKS / API responses), claim flow,
tier grant/revoke across renewal/expiry/refund events, and source
exclusivity.
