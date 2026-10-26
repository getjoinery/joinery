# Store

## Overview

The Store plugin (`/plugins/store/`) is the platform's commerce subsystem: products, the shopping cart, checkout, orders, coupons, subscriptions, and payment-provider integration (Stripe and PayPal). It owns every "money" surface — with the plugin inactive, the platform runs as a pure membership/content site: no cart in the header, store URLs hard-404, and tier gating falls back to its contact-us prompt.

The plugin is a **commercial extension** (`license: Joinery-Commercial`, `requires_entitlement` in its manifest; terms in its `LICENSE.md`). Buying it issues a per-buyer license key by email, also listed with the buyer's order history on their profile. The license grants one production instance per purchase, with staging and dev copies included.

**Owns:** `pro_products` (+ versions, groups, details), `ord_orders` / `odi_order_items`, coupon codes, product requirements, `stc_stripe_customers`, Stripe invoices.

**Core URLs (plugin-delegated in serve.php):** `/products`, `/product/{slug}`, `/pricing`, `/cart`, `/checkout`, `/cart_charge`, `/cart_confirm`, `/cart_clear`, and the `/profile/orders`, `/profile/billing`, `/profile/subscriptions` pages. Admin pages live at `/plugins/store/admin/*`.

**Webhooks:** `/ajax/stripe_webhook` and `/ajax/paypal_subscription_webhook` (URLs registered with the payment providers; files live in `plugins/store/ajax/`). The Stripe endpoint handles `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_succeeded`, `invoice.payment_failed` and `charge.refunded`; subscribe it to those seven in Stripe. `invoice.paid` covers an invoice marked paid by hand in Stripe's dashboard, which sends no `invoice.payment_succeeded`. `charge.refunded` records a refund of an order's own charge made anywhere, Stripe's dashboard included, on its order; without it, the order page folds the refund in when an admin opens it. `customer.subscription.updated` to `incomplete_expired` ends the line as `.deleted` does, and only one of the two sends `subscription.cancelled`.

**Refunds:** the order page's [refund] on a line opens `admin_order_refund`, which refunds through `OrderItem::refund_payment()` from the payment that paid that line: a subscription line from its subscription's latest invoice, any other line from the order's charge (`StripeHelper::refundable_charge_for_item()`). A line refunds at most its own price and what is left on that charge (`OrderItem::refundable_amount()`). A subscription line's refund can cancel the subscription at once, through the same cancel as everywhere else; Stripe's `customer.subscription.deleted` then sends `subscription.cancelled`, as for any cancel. A refused cancel is reported beside the refund, which stands. `ord_refund_amount` is the order's total refunded: its lines' recorded refunds, with the order charge's part never below what Stripe reports refunded on it (`Order::refresh_refund_amount()`). A PayPal order is refunded in PayPal.

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

A guest's checkout makes a new account from the billing email. Paying as an
account that already exists needs that account's sign-in: the checkout page
asks for it before it offers payment, and `cart_charge` refuses a charge to an
existing account other than the one signed in. A free order completes when the
billing form posts to `/cart_charge`; a GET there persists something only when
it is a payment gateway's verified return.

### The card form (`stripe_regular`)

The card is entered on the checkout page itself. Stripe.js turns it into a
PaymentMethod in the browser and the form posts only its id to `/cart_charge`
(`StripeHelper::output_stripe_regular_form()`); a posted token is made into a
PaymentMethod (`StripeHelper::payment_method_from_token()`). The charge then
runs one sequence in `cart_charge_logic`:

1. the card is saved on the buyer's Stripe customer as the card their
   invoices are charged to (`attach_card()`);
2. the one-time lines are **authorized**, not charged
   (`authorize_card_payment()`, a manually captured PaymentIntent); with a
   subscription in the cart, the authorization also covers charging the card
   again without the buyer, so the subscription is not confirmed a second time;
3. the subscription is made (off-session after step 2) and kept only when
   live: Stripe answers a declined first invoice with an `incomplete`
   subscription, not an error;
4. the authorization is **captured**, last (`capture_card_payment()`), so the
   one-time lines are paid only when the subscription went through.

Where the buyer's bank asks them to confirm a payment (3-D Secure), what was
made is kept in the session (`StripeHelper::CARD_PENDING_KEY`, for an hour)
and the buyer is sent back to `/checkout`, where the card form runs the bank's
confirmation as the page loads and posts `card_payment_confirm` back to
`/cart_charge`. The checkout carries on with the same order, reading the
outcome from Stripe, provided the cart is the one it paid for. A buyer who
never comes back is charged nothing: an authorization never captured lapses,
and an incomplete subscription expires.

Any refusal takes back what the sequence made (an authorization released, a
subscription cancelled and its first payment refunded), records the reason on
the order, and tells the buyer what happened to their money. A take-back that
fails is emailed to the operator. The card form takes one subscription per
checkout (`ShoppingCart::card_checkout_refusal()`).

### Gateway returns

A hosted payment (a Stripe Checkout session, a PayPal order or subscription)
is paid on the gateway's page and completed later. What it was for is a
`GatewayPaymentRecord` (`gpr_gateway_payment_records`): the cart's items,
prices, coupons and billing details and who was signed in, keyed by the
gateway's reference (the Stripe session id, or the `custom_id` the PayPal order
or subscription carries), written by `ShoppingCart::record_payment()`. A
checkout page view writes none and makes nothing at a gateway; a record is
written by the buyer's own POST:

- **Stripe Checkout:** the Pay button posts `start_hosted_checkout` to
  `/cart_charge`, which checks the cart as the page did
  (`ShoppingCart::payment_start_refusal()`; a cart changed since the page was
  shown is shown again), makes the session, records it, and sends the buyer to
  Stripe's page.
- **PayPal:** the button's `createOrder` / `createSubscription` first calls
  `store/checkout_record_paypal`, which makes the same checks, records the cart
  and answers the `custom_id` (and, for a subscription, the plan).

The return (`/cart_charge?session_id=`, or PayPal's `?id=` / `?subscription=`):

1. verifies the payment with the gateway before writing anything (paid, and
   its Stripe `client_reference_id` the recorded signed-in user);
2. finds the open record by that reference, from whatever browser session it
   lands in, and checks the payment against it: a PayPal order's amount is the
   recorded cart's, and a PayPal subscription's plan is the recorded one, for a
   recorded cart of exactly one subscription line of quantity one;
3. completes the order from the record, never from the live cart, under a lock
   on the payment reference (`Order::with_payment_lock()`), and marks the
   record completed with its order and receipt.

A record can be completed for two days (`GatewayPaymentRecord::MAX_AGE`) and
is kept a week. Only the browser session that started the payment
(`gpr_session_hash`) is acted on: a new account is signed in there and its cart
emptied of what was paid for; a return that lands in another browser session
completes the order and shows its receipt, signing nothing in and leaving that
session's cart as it is.

Stripe's `checkout.session.completed` webhook completes the order itself when
the buyer has not come back (`cart_charge_complete_from_webhook()`), with its
receipts, fulfilment and emails; the buyer's later return finds the order
complete, shows its receipt, empties the cart, and signs in the account the
completion made for a guest (the record's `made_user_id`). A paid session it
cannot complete (no open record, made for someone else, a line that no longer
loads) is kept as a paid order with no lines and emailed to the operator with
the reason.

A payment that was taken but cannot be completed as recorded (billing as an
account the buyer was not signed in to when they paid, a coupon gone invalid,
an ownership conflict) keeps its order in error with the reason and the cart,
marks its record refused, and emails the operator; the refund is theirs to
decide; a PayPal payment whose amount or plan is not the record's is kept the
same way. A payment that matches no record is emailed to the operator. The
buyer lands on `/cart_confirm`, which shows that the payment was received and
offers no second payment; a reload shows the same and emails nobody again.

The PayPal buttons send the cart hash the page was shown with, and the record
action refuses a cart changed since, before PayPal is asked for anything. A
PayPal payment is captured in the buyer's browser, and only the return
completes it: no PayPal webhook completes a one-time order.

## Activation

`activate.php` is idempotent and self-guarded. It backfills `stc_stripe_customers` from the pre-extraction user columns and product fulfillment columns where those still exist, claims the plugin's scheduled-task rows, and drops the superseded columns. On upgrade, `update_database` runs a one-time auto-activation: the store activates when the install shows store evidence (product/order rows, or a Stripe/PayPal key configured); a store-less install stays inactive.

Settings keep their pre-extraction core names (`products_active`, `checkout_type`, `stripe_api_key`, ...) via the per-setting `legacy_core: true` flag in `plugin.json`. Declaring them there is also what puts them on **Admin → Settings → Plugin Settings**; the payment credentials additionally appear on the store's own Payment Settings tab, which wraps them with live connection tests.

## How a cart line gets its price

A cart line is a **product version, a quantity, and the form data the buyer's
submission produced**. Every price on the receipt comes out of that tuple, and
this is the whole of it.

### Price resolution

`Product::get_price($version, $form_data)` answers in one of three ways:

- **The version's own price** (`prv_version_price`) — the ordinary case. The
  version also carries the recurrence: a `prv_price_type` of `day`, `week`,
  `month` or `year` makes the line a subscription.
- **A price carried in the line's form data** — a version with
  `prv_price_type = 'user'` reads `user_price_override` from the form data
  rather than from the version row. That is how a line can cost something the
  product row could not know in advance.
- **An amount the buyer typed** — the optional-donation product prices itself
  from `user_price` (`Product::is_optional_donation()`) and shows no fixed
  price in listings.

Because the price lives in the form data, **coupon repricing is free of side
effects**: `ShoppingCart::update_items_for_coupon()` re-calls `get_price()`
with the persisted form data and derives the same number, with no lookup and
no second call to anything external.

### The version's Stripe price

A version's Stripe price id (`prv_stripe_price_id`, or `prv_stripe_price_id_test`
in test mode) is the plan's price: a tier change, the billing page and the
Stripe webhook read it back to know what a subscription bills at.
`StripeHelper::get_or_create_price($version)` finds or creates it at the
version's own amount (`prv_version_price`), interval and trial, and stores it.
Called with any other amount (a coupon-discounted or buyer-priced line), it
finds or creates a Stripe price at that amount for that one charge and never
writes the version.

### Requirement-contributed companion lines

A product requirement may contribute **additional cart lines whose price it
derives server-side, from the buyer's validated answers, at the moment the
item is added to the cart**. `AbstractProductRequirement::extra_cart_lines(
$form_data, $product)` returns `['product_id' => int, 'form_data' => array]`
entries, and `product_logic` adds each one beside the parent line (resolving
the companion product's active version when the requirement did not name one).

Two examples of the same shape: a buyer who enters a donation amount gets the
site's donation product as a second line; a buyer who asks for a managed
domain gets a "Domain registration (1 year)" line priced at the live registrar
quote their name came back with (see
[Server Manager](../../server_manager/docs/overview.md)).

The scope is deliberately narrow:

- **Not a parent-price modifier.** A requirement cannot discount or surcharge
  the product it is attached to.
- **Not a fees engine.** Every contributed line is a real product with a real
  version — visible in orders, reports and refunds like any other. The product
  stays the platform's unit of sale.
- **Not buyer-priceable.** The contributed line's form data is constructed by
  the requirement, never taken from the POST.

Editing a cart item replaces its companion lines rather than leaving them:
`product_logic` recomputes them from the answers being replaced, removes those
exact lines, and adds the new ones — otherwise the cart would go on charging
for the buyer's previous answer. That makes determinism part of the contract:
the same form data must always produce the same lines.

The cart's own Edit and Remove still reach a companion line directly, so a
buyer can delete or reprice one after it is added. Requirements whose line
authorizes something expensive must therefore verify the ORDER, not the
answers — the managed-domain pipeline refuses to register a name unless the
order holds a paid domain-year line worth the quote.

Why a line rather than a fold-in: a line carries its own product version and
therefore its own recurrence. A one-time fee folded into a parent line that
happens to be a subscription would be billed every cycle; as a separate
non-recurring line, "one charge, once" is structurally true and the receipt
shows exactly what each part cost.

The optional-donation piggyback is wired directly in `product_logic` rather
than through the hook: a buyer entering an amount in a `UserPriceRequirement`
field ("User Chooses Price" on the product edit page) gets the site's donation
product as a second line. The product is named by
`store_optional_donation_product_id` (the "Optional donation product" dropdown
on the settings page), never by a hardcoded ID; blank turns the feature off,
and an entered amount is then logged and skipped rather than charged.

### What pricing never does

Ownership refuses a sale; it does not zero-price or discount (see below).
Nothing derives a price from a value the buyer posted directly — a
`user_price` a buyer types is the price *by design*, and every other number
is derived server-side.

## Own-once products (ownership)

Some products can only sensibly be owned once: a course, a lifetime unlock, a
software license, an all-access bundle. Give such a product an **ownership
tag** and the store guarantees one purchase per buyer per tag — the buy button
reads "You already own this", and checkout refuses to charge for it again.

An operator who never tags a product never meets the feature.

### Tagging a product

The **Ownership** dropdown on the admin product edit page offers the four
meanings own-once can have, and writes the single `pro_ownership_tag` column:

| Choice | Stored tag |
| --- | --- |
| No limit — can be purchased repeatedly | empty |
| Own once | `product-{id}`, derived |
| Own once, shared with other products… | the tag the operator names |
| Bundle — owning this grants every own-once product | `*` |

Products sharing a tag count as the same thing, which is how a second product
joins an existing group: pick its tag from the list of tags already in use.
`*` is the all-access tag — a buyer holding it owns every tag in the store.

Ownership applies to **one-time purchases only**, enforced from both
directions: saving a tag on a product with a subscription version fails
validation, and adding a subscription version to a tagged product fails the
same way (on the product edit page and the version edit page alike). The
payment-time recorder additionally skips a subscription order item and logs
it, as a backstop.

### The ownership rule

User U owns tag T when a row exists in `own_ownerships` with
`own_usr_user_id = U`, `own_tag` in (T, `*`), no revoke time and no delete
time. `Ownership::user_owns($user_id, $tag)` is the single authority; every
guard calls it.

The store writes the row itself when a tagged order item is paid, in
`cart_charge_logic`'s post-payment work and before any purchase script, so
fulfillment finds it already there. The owner recorded is the user fulfillment
is for — the recipient on a buy-for-someone-else line, the buyer otherwise.
Recording is idempotent per order item, so a webhook replay is safe.

### The three guard points

- **Product page** — an owner sees a "You already own this" notice with a link
  to their purchases, in place of the buy controls. Anonymous viewers see the
  normal buy button; identity is unknown.
- **Add to cart** — `ShoppingCart::add_item()` checks the account the line is
  *for* (the recipient on a line carrying an email, the signed-in buyer
  otherwise — the same account the recorder credits, so an owner can still buy
  a gift). It refuses a line whose recipient already owns the tag, a second
  copy for the same recipient (own-once caps a line at quantity 1; two
  recipients make two lines), and a bundle sharing a cart with a product it
  covers for the same recipient. Unknown identity passes.
- **Charge time** — the authoritative guard, in the pre-charge region of
  `cart_charge_logic`, beside the fulfillment-availability pass. It checks the
  user each line would be credited to and returns a checkout error naming the
  product. No payment call happens, so a replayed URL or a guest resolving to an
  existing account mid-checkout is still caught before money moves.

Ownership never touches pricing. It refuses a sale; it does not zero-price,
discount, or interact with coupons.

The buyer's profile lists what they own under "What you own", labeled with the
product name(s) carrying each tag — the raw tag shows only when no live
product carries it, and `*` reads "All products".

### The Ownership admin page

**Products → Ownership** lists who owns what: owner, tag, originating order
(blank for hand-granted rows), created date, and active/revoked status,
filterable by tag, owner, and status. A row's detail view adds the license key
if one was issued and lists the products the tag covers.

**Revoking** an ownership re-opens purchase for that buyer — it is the
companion action to a refund or chargeback, since the guards exclude revoked
rows. **Granting** by hand mints a row with no order attached, for comps and
support; a grant to someone who already owns the tag is refused, so revocation
always means what it says — one live row per person per tag. There is no
reissue: a key string is written once.

### Optional: selling license keys

`mint_license_key_product_script` (in `plugins/store/hooks/product_purchase.php`)
is one optional fulfillment script among the purchase scripts. It stamps a
`JNRY-XXXX-XXXX-XXXX-XXXX` string onto the ownership the store recorded and
emails it to the owner; a re-run keeps the key already issued. Core never reads
`own_license_key` — it is the operator's artifact for proving an ownership to a
machine elsewhere. A product with no ownership tag has no ownership row, so the
script mints nothing.

Keys identify a purchase and nothing more: there is no activation step, no
install registry, and no runtime check. The one-production-instance scope lives
in the commercial license terms stated at checkout and in the key email.

## Related Docs

- [Product Requirements](product_requirements.md) — collecting data from buyers at checkout
- [Product Purchase Hooks](product_purchase_hooks.md) — post-purchase hook convention
- [Subscription Tiers](../../../docs/subscription_tiers.md) — the core/billing split
