# Own-Once Products (Store Ownership Model)

## Purpose

Some products can only sensibly be owned once: a course, a lifetime unlock, a
software license, an all-access bundle. The store currently has no memory of
this — a buyer can be charged twice for the same thing, and a buyer of a bundle
can be charged for the individual products it covers.

This spec gives the store an **ownership model**. An operator puts an
**ownership tag** on a product; the store then guarantees *one purchase per
buyer per tag*: the product page shows "you already own this" instead of a buy
button, and checkout refuses to charge for a tagged product the buyer already
owns.

This is a general store-plugin feature, not a getjoinery feature. A course
seller tags a product `portfolio-course`; getjoinery tags the Store plugin
product `store` and the Founder license `*`. The store answers the ownership
question; what ownership *unlocks* (course pages, plugin downloads, a license
key email) is the operator's fulfillment logic.

**Mental model.** What buyers experience is the App Store / Steam pattern: the
buy button says "Owned." Ownership is neither a receipt (order items are and
remain the receipts) nor a coupon (no discount appears, no code is entered, no
price changes). The concept the store gains is one sentence: *some products can
only be owned once, and the store will not sell you what you already own.* An
operator who never tags a product never encounters the feature.

**The license-key split.** getjoinery's plugin licensing is a thin fulfillment
layer on top of ownership, not the concept itself. The ownership row is the
fact; the JNRY-XXXX key string is one operator's artifact for proving that fact
to a remote machine. Core store code speaks only "ownership"; the word
"license" appears only in the optional key-minting purchase script and the
(future, separate) download gate in server_manager.

## Boundaries (load-bearing — enforce, don't just document)

Two rules keep ownership from bleeding into the store's other money concepts:

1. **Ownership never touches pricing.** The store *refuses* a sale it would
   double-charge; it never zero-prices, discounts, or interacts with coupons.
   Coupons change what you pay; ownership changes what you may buy. No code
   path may use an ownership check to alter a price.
2. **Tags go on one-time products only — never subscriptions.** Ownership stays
   completely out of tiers, billing, renewals, and proration. Enforced at the
   source: the admin product edit refuses to save an ownership tag on a product
   whose current version is a subscription (validation error, not silent drop).
   The payment-time recorder additionally logs and skips if it ever meets a
   subscription order item, as a backstop.

The feature therefore touches exactly three moments — the buy button, the
pre-charge check, and the post-payment record — and has no interaction with
coupons, product requirements, fulfillment providers, or tier billing.

## Explicit non-goals

- **No remote verification.** Nothing on a customer's own server checks a key;
  no redemption endpoint; no download or update gating. (Those are the future
  licensing system — `specs/plugin_entitlement_gate.md`; this spec's `*`
  convention and the preserved key-string column are forward-compatible with
  it.)
- **No instance binding.** Ownership is per buyer account, not per site a
  purchase is deployed to.
- **No reissue.** A key string is written once at fulfillment time; there is no
  regenerate action. Revoke-and-regrant covers support cases.

## Terminology

**Ownership tag** — a free-form string on a product (`pro_ownership_tag`)
naming what a purchase of it confers. On getjoinery the tags are plugin
directory names; any operator may use any string. Products sharing a tag count
as the same thing.

**`*` (all-access)** — a reserved tag value meaning "every ownership tag in
this store". A buyer holding a non-revoked `*` ownership owns every tagged
product.

**Owns** — user U owns tag T iff a row exists in `own_ownerships` with
`own_usr_user_id = U`, `own_tag IN (T, '*')`, `own_revoked_time IS NULL`,
`own_delete_time IS NULL`.

## Design

### 1. The Ownership model (renames `lck_license_keys`)

`plugins/store/data/license_keys_class.php` becomes
`plugins/store/data/ownerships_class.php`: class `Ownership` /
`MultiOwnership`, table `own_ownerships`, prefix `own`. The old model's name
described one operator's fulfillment artifact, not the fact the row records.

Columns (carried over, renamed):

- `own_ownership_id` (serial pkey)
- `own_usr_user_id` (required — the owner; on user deletion, reassigned to the
  deleted-user sentinel as today)
- `own_tag` varchar(64) (required)
- `own_ord_order_id`, `own_odi_order_item_id` (nullable; null on manual grants;
  FK action null on deletion)
- `own_license_key` varchar(64) (nullable — written only by fulfillment such as
  the key-minting script; **core never reads it**)
- `own_create_time`, `own_revoked_time`, `own_delete_time`

API surface:

- `Ownership::user_owns($user_id, $tag)` → bool, implementing the **Owns**
  definition above. The single authority; every guard and any future consumer
  (e.g. a download gate) calls it.
- `MultiOwnership` options: `user_id`, `order_item_id`, `tag`, plus
  - `'revoked' => FALSE` → `own_revoked_time IS NULL` (TRUE selects revoked)
  - `'covers_tag' => $tag` → `own_tag IN (?, '*')` (split-parenthesis OR)
- Not REST-exposed, not `$ai_readable` — ownership is shown to a buyer only on
  their own profile.
- `generate_key_string()` (the JNRY-XXXX generator) moves out of the model into
  `plugins/store/hooks/product_purchase.php` as a plain function — it is
  fulfillment vocabulary, not ownership.

**Product column rename:** `pro_licensed_plugin` → `pro_ownership_tag`.

**Data carry-over (pre-launch, one-off):** a maintenance script in
`maintenance_scripts/dev_tools/` copies every `lck_license_keys` row into
`own_ownerships` verbatim (plugin name → tag, key string preserved so
already-emailed keys stay true, revoked/create times kept), and copies
`pro_licensed_plugin` values into `pro_ownership_tag`. The old table and column
are then dropped manually. Code touch points to rename are exactly: the data
class, `hooks/product_purchase.php`, `views/profile/orders.php`,
`logic/orders_profile_logic.php`, and `tests/license_key_minting_test.php`.
Where `specs/plugin_entitlement_gate.md` names the old table, it reads as
`own_ownerships` after this lands.

### 2. Core records ownership at payment

When an order item for a tagged product is paid, the store itself creates the
ownership row — no purchase script required; tagging a product is the entire
setup. This runs in `cart_charge_logic.php`'s post-payment region **before**
product purchase scripts, so fulfillment scripts can find the row. Rules:

- The recorded owner is the user fulfillment is for: the recipient on a
  buy-for-someone-else item, the buyer otherwise.
- Idempotent per order item: an existing row for `own_odi_order_item_id` means
  skip, so replays and re-runs are safe.
- A subscription order item is logged and skipped (boundary backstop).
- `*` is stored literally; a bundle is pure product config (tag `*`).

### 3. Enforcement — three layers

**Product page (UX):** `product_logic.php` passes an `already_owned` flag when
the product has a tag and the logged-in viewer owns it. The view replaces the
buy/cart controls with a "You already own this" notice (link to
`/profile#orders`). Anonymous viewers see the normal buy button — identity is
unknown.

**Add to cart (early feedback):** `ShoppingCart` refuses to add a tagged
product the logged-in user already owns, throwing `ShoppingCartException` with
a friendly message, in the same validation region as the existing
subscription-cap and `pro_max_cart_count` checks. Anonymous carts pass — the
charge-time guard is the authority. A tagged product is also capped at
quantity 1 per cart (own-once implies there is nothing a quantity 2 could
mean).

**Charge time (authoritative):** in `cart_charge_logic.php`, beside the
existing pre-charge fulfillment-availability pass ("refuse while the purchase
can still be declined for free", ~line 240) — i.e. after the buying user is
resolved (guest account creation happens earlier in the flow) and strictly
before any payment call. For each cart item with a tag, check ownership
**against the user the payment-time recorder would credit for that item** (the
recipient, for buy-for-someone-else items; the buyer otherwise). On a hit,
return `_checkout_error()` naming the product and saying it is already owned by
that account — no charge occurs.

This layering means the URL/replay paths cannot bypass the guard, and a guest
who logs in (or whose email resolves) mid-checkout is still caught before money
moves.

### 4. Admin product edit (`plugins/store/admin/admin_product_edit.php`)

The operator never faces a blank string field — the control is a dropdown of
the three meanings own-once can have, and the tag string is derived plumbing
except in the shared case:

- The "Licenses plugin" dropdown becomes an **"Ownership"** dropdown:
  1. **No limit** — can be purchased repeatedly (default; tag stored empty)
  2. **Own once** — a buyer can purchase this product only once (tag derived
     as `product-{id}`; nothing to type)
  3. **Own once, shared with other products…** — counts as the same thing as
     other products (reveals a tag text input via `visibility_rules`, with a
     suggestion list of the tags already in use on this store's products —
     sharing means picking from what exists; a string is only invented for the
     first product in a group)
  4. **Bundle** — owning this grants every own-once product (stores `*`)
- One column underneath (`pro_ownership_tag`); on load the stored value maps
  back: empty → 1, `product-{this product's id}` → 2, `*` → 4, anything else
  → 3 with the field showing the tag.
- Save handling: option 3 with an empty tag field fails validation. For a
  brand-new product saved with option 2, the tag is derived after the insert
  (the id does not exist until the row does).
- Helptext on the dropdown: an owner sees Owned instead of a buy button and
  checkout will not charge them again; products sharing a tag count as the
  same purchase.
- On a saved product with a tag, a line under the field — "N buyers own this
  tag" — linking to the Ownership admin page filtered to that tag.
- Saving a tag on a product whose current version is a subscription fails
  validation with a message saying ownership applies to one-time purchases
  only.
- The purchase-scripts checkbox list is unchanged; `mint_license_key` is simply
  one optional script among them (see §6).
- Bump version numbers of touched files per project convention.

### 5. Admin Ownership page (new)

`plugins/store/admin/admin_ownerships.php` (list) +
`admin_ownership_edit.php` (detail), menu entry **Ownership** in the store's
`plugin.json`, following the coupon-codes page pattern.

- **List:** owner (linked to the user admin), tag, originating order (linked;
  blank for manual grants), created date, status active/revoked. Filters: tag,
  user, status. The license-key string is a detail-view field, not a list
  column — most operators' rows have none.
- **Revoke / un-revoke:** POST action buttons (never links). Revoke confirm
  states the consequence: the buyer becomes able to purchase this tag again.
  Revocation is the refund/chargeback companion action; the guards exclude
  revoked rows, so it re-opens purchase automatically.
- **Manual grant:** a small form (user + tag) minting a row with no order
  attached — comps and support cases. No key string is written; if the
  operator's fulfillment needs one, that remains their script's business.
- **No reissue.** No action regenerates a key string.

### 6. License-key fulfillment script (getjoinery's layer)

`mint_license_key_product_script` (`plugins/store/hooks/product_purchase.php`)
stops creating rows. It now:

1. loads the ownership row core recorded for its order item (logs and bails if
   none — a tagless product with the script attached mints nothing),
2. if `own_license_key` is empty, stamps a generated JNRY-XXXX key string,
3. emails the key to the owner.

Email copy fix: the current email states "A second production site needs a
second purchase." Under own-once, a same-account second purchase is impossible,
so the sentence instructs the impossible. Change to: "This license covers one
production instance; staging and development copies are included. Need an
additional instance? Contact us." Revisit when instance binding exists.

The script stays in the store plugin as an optional generic "sell keys"
fulfillment — but nothing in core depends on it, and an operator who never
attaches it never sees a key string anywhere.

## getjoinery store configuration (deploy checklist, not code)

1. Run the carry-over script (§1), then drop `lck_license_keys` and
   `pro_licensed_plugin`.
2. Business License product: set ownership tag `*`, keep
   `mint_license_key_product_script` attached.
3. Future per-plugin products (Store $99, Server Manager $149): tag with the
   plugin directory name, attach the script.
4. **Backfill:** for paid order items of tagged products with no ownership row,
   run the payment-time recorder logic once (idempotent per order item) so
   existing buyers gain the exemption; the script then keys+emails any row
   still missing a key string.

## Tests

`plugins/store/tests/` (db tier, harness + `@joinery-test` headers):

- `user_owns()`: direct tag, `*` row, revoked row (false), deleted row (false),
  other user's row (false).
- `MultiOwnership` `revoked` and `covers_tag` options.
- Core payment-time recorder: row created on paid tagged item; recipient
  credited on buy-for-someone-else; idempotent per order item; subscription
  item skipped; untagged product records nothing.
- Cart add of an owned tagged product throws; unowned adds pass; untagged
  products unaffected; quantity capped at 1 for tagged products.
- Charge-time guard refuses an owned item (including the `*`-covers-specific
  case) and charges nothing.
- Boundaries: admin product edit refuses saving a tag on a subscription
  product; an ownership refusal leaves cart pricing untouched (no zero-pricing
  path exists).
- Admin page: revoke re-opens purchase; manual grant rows guard like purchased
  ones.
- `license_key_minting_test.php` reworked: the script stamps a key on the
  core-recorded row, is idempotent, and mints nothing without a row.

## Documentation

Update `plugins/store/docs/overview.md` with an "Own-once products (ownership)"
section — the tag field, `*`, the ownership rule, the three guard points, the
`user_owns()` helper, the Ownership admin page, and the optional key-minting
script — written as current state. Update
`plugins/store/docs/product_purchase_hooks.md` where it references the
"Licenses plugin" label. No CLAUDE.md index change (overview.md is already
indexed).

## Out-of-band items recorded here for visibility (not part of this build)

- Site/store contradictions on getjoinery: install service advertised at $39.99
  vs $299 product; ELv2 vs PolyForm license text. Business decisions pending.
- `plugins/server_manager/tests/plugin_distribution_anonymous_test.php` still
  asserts the ungated paid-plugin download on purpose; it flips only when the
  future download gate lands, not with this spec.
