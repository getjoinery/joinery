# Own-Once Products (Store Entitlement Purchase Guard)

## Purpose

The store already remembers what each buyer owns — every purchase of a license-tagged
product mints a row in `lck_license_keys`. Nothing consults that memory: a buyer who
holds a license can be charged for the same thing again, and a buyer of an all-access
bundle can be charged for the individual products it covers.

This spec teaches the store to consult its own memory before selling. An operator tags
a product with an **entitlement tag**; the store then guarantees *one purchase per
buyer per tag*: the product page shows "you already own this" instead of a buy button,
and checkout refuses to charge for a tagged product the buyer already owns.

This is a general store-plugin feature, not a getjoinery feature. A course seller tags
a product `portfolio-course`; getjoinery tags the Store plugin product `store` and the
Founder license `*`. The store answers the ownership question; what ownership *unlocks*
(course pages, plugin downloads) remains the operator's fulfillment logic.

**Mental model.** What buyers experience is the App Store / Steam pattern: the buy
button says "Owned." Ownership is neither a receipt (order items are and remain the
receipts) nor a coupon (no discount appears, no code is entered, no price changes).
The concept the store gains is one sentence: *some products can only be owned once,
and the store will not sell you what you already own.* An operator who never tags a
product never encounters the feature.

## Boundaries (load-bearing — enforce, don't just document)

Two rules keep ownership from bleeding into the store's other money concepts:

1. **Ownership never touches pricing.** The store *refuses* a sale it would
   double-charge; it never zero-prices, discounts, or interacts with coupons. Coupons
   change what you pay; ownership changes what you may buy. No code path may use an
   ownership check to alter a price.
2. **Tags go on one-time products only — never subscriptions.** Ownership stays
   completely out of tiers, billing, renewals, and proration. Enforced at the source:
   the admin product edit refuses to save an entitlement tag on a product whose
   current version is a subscription (validation error, not silent drop). The mint
   hook additionally logs and skips if it ever meets a subscription order item, as a
   backstop.

The feature therefore touches exactly two moments — the buy button and the pre-charge
check — and has no interaction with coupons, product requirements, fulfillment
providers, or tier billing.

## Explicit non-goals

- **No remote verification.** Nothing on a customer's own server checks a key; no
  redemption endpoint; no download or update gating. (Those are the future licensing
  system; this spec's `*` convention is forward-compatible with it.)
- **No license admin page.** Listing, revoking, and reissuing keys stays manual
  (direct row edit) until that page is specced.
- **No instance binding.** A key does not know which site it is used on.

## Terminology

**Entitlement tag** — a free-form string on a product (`pro_licensed_plugin`, existing
column, no rename) naming what a purchase of it confers. On getjoinery the tags are
plugin directory names; any operator may use any string.

**`*` (all-access)** — a reserved tag value meaning "every entitlement tag in this
store". A buyer holding a non-revoked `*` key owns every tagged product.

**Owns** — user U owns tag T iff a row exists in `lck_license_keys` with
`lck_usr_user_id = U`, `lck_plugin_name IN (T, '*')`, `lck_revoked_time IS NULL`,
`lck_delete_time IS NULL`.

## Design

### 1. Ownership helper (`plugins/store/data/license_keys_class.php`)

- `LicenseKey::user_owns($user_id, $tag)` → bool, implementing the **Owns** definition
  above. This is the single authority; every guard and any future consumer (e.g. a
  download gate) calls it.
- `MultiLicenseKey` gains two options:
  - `'revoked' => FALSE` → `lck_revoked_time IS NULL` (TRUE selects revoked rows)
  - `'covers_tag' => $tag` → `lck_plugin_name IN (?, '*')` (split-parenthesis OR)
- No schema change. No new tables.

### 2. Tag semantics (`*`)

`pro_licensed_plugin` may now hold `*`. The mint hook
(`mint_license_key_product_script`) is unchanged in logic — it mints a key whose
`lck_plugin_name` is the literal product tag, including `*`. A Founder-style bundle is
therefore pure product config: tag `*`, mint script attached.

The JNRY-XXXX key *string* is dormant in this scope: nothing here reads it. It exists
for the future remote-redemption system; within this feature the row itself is the
ownership fact. It continues to be minted and emailed so keys exist when that system
lands.

### 3. Enforcement — three layers

**Product page (UX):** `product_logic.php` passes an `already_owned` flag when the
product has a tag and the logged-in viewer owns it. The view replaces the buy/cart
controls with an "You already own this" notice (link to `/profile#orders`). Anonymous
viewers see the normal buy button — identity is unknown.

**Add to cart (early feedback):** `ShoppingCart` refuses to add a tagged product the
logged-in user already owns, throwing `ShoppingCartException` with a friendly message,
in the same validation region as the existing subscription-cap and
`pro_max_cart_count` checks. Anonymous carts pass — the charge-time guard is the
authority. A tagged product is also capped at quantity 1 per cart (own-once implies
there is nothing a quantity 2 could mean).

**Charge time (authoritative):** in `cart_charge_logic.php`, beside the existing
pre-charge fulfillment-availability pass ("refuse while the purchase can still be
declined for free", ~line 240) — i.e. after the buying user is resolved (guest
account creation happens earlier in the flow) and strictly before any payment call.
For each cart item with a tag, check ownership **against the user the mint hook would
record for that item** (the recipient, for buy-for-someone-else items; the buyer
otherwise). On a hit, return `_checkout_error()` naming the product and saying it is
already owned by that account — no charge occurs.

This layering means the URL/replay paths cannot bypass the guard, and a guest who
logs in (or whose email resolves) mid-checkout is still caught before money moves.

### 4. Revocation honored, not managed

The guards exclude revoked keys, so revoking a key (refund, chargeback) automatically
re-opens purchase for that buyer. No UI for revocation in this spec.

### 5. Admin product edit (`plugins/store/admin/admin_product_edit.php`)

- Relabel the field **"Entitlement tag"** (same column). Helptext: purchasing this
  product records that the buyer owns this tag; the store will not sell them a product
  carrying a tag they own; pair with the mint script.
- Replace the plugin-directory dropdown with a text input carrying a `datalist` of
  suggestions: installed plugin directory names plus `*` ("all tags — bundle
  product"). If FormWriter lacks datalist support, add it to FormWriter (it is ours)
  rather than shipping a bare text input. An existing saved value always survives the
  edit form regardless of the suggestion list.
- Bump version numbers of touched files per project convention.

### 6. Key email copy (`mint_license_key_product_script`)

The current email states "A second production site needs a second purchase." Under
own-once, a same-account second purchase is impossible, so the sentence instructs the
impossible. Change to: "This license covers one production instance; staging and
development copies are included. Need an additional instance? Contact us." Revisit
when instance binding exists.

## getjoinery store configuration (deploy checklist, not code)

1. Business License product: set entitlement tag `*`, attach
   `mint_license_key_product_script`.
2. Future per-plugin products (Store $99, Server Manager $149): tag with the plugin
   directory name, attach the script.
3. **Backfill:** one-off maintenance script (in `maintenance_scripts/dev_tools/`)
   iterating paid order items of tagged products and invoking the mint hook — the hook
   is idempotent per order item, so re-runs are safe. Existing buyers receive their key
   email and gain the exemption. Run once after (1).

## Tests

`plugins/store/tests/` (db tier, harness + `@joinery-test` headers):

- `user_owns()`: direct tag, `*` key, revoked key (false), deleted key (false),
  other user's key (false).
- `MultiLicenseKey` `revoked` and `covers_tag` options.
- Cart add of an owned tagged product throws; unowned adds pass; untagged products
  unaffected; quantity capped at 1 for tagged products.
- Charge-time guard refuses an owned item (including the `*`-covers-specific case)
  and charges nothing.
- Boundaries: admin product edit refuses saving a tag on a subscription product; an
  ownership refusal leaves cart pricing untouched (no zero-pricing path exists).
- Existing `license_key_minting_test.php` extended: minting with tag `*`.

## Documentation

Update `plugins/store/docs/overview.md` with an "Own-once products (entitlement
tags)" section — the tag field, `*`, the ownership rule, the three guard points, and
the `user_owns()` helper — written as current state. Update
`plugins/store/docs/product_purchase_hooks.md` where it references the "Licenses
plugin" label. No CLAUDE.md index change (overview.md is already indexed).

## Out-of-band items recorded here for visibility (not part of this build)

- Site/store contradictions on getjoinery: install service advertised at $39.99 vs
  $299 product; ELv2 vs PolyForm license text. Business decisions pending.
- `plugins/server_manager/tests/plugin_distribution_anonymous_test.php` still asserts
  the ungated paid-plugin download on purpose; it flips only when the future download
  gate lands, not with this spec.
