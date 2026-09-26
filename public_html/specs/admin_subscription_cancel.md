# Admin Subscription Cancel

**Status:** Draft (2026-09-26). The owner asked for a spec to consider later. WP1 and WP2 (B1, B2)
were built the same day at the owner's word, ahead of the rest; WP3–WP5 are not started.
**Related:** `plugins/server_manager/data/managed_nodes_class.php` 1.27 (removing a hosted site names
a subscription still billing, and points at the buyer's user page to cancel it).

## What this does for the owner

Lets an admin end a customer's subscription from the order page, the same place they refund one,
and end it now rather than only at the end of the paid period. Today the order page only links to
Stripe's dashboard; the one admin cancel is on the user's page (Subscriptions panel), and it always
cancels at the period's end. The case that found this: retiring the test380s hosted site left its
$12.99/month hosting subscription active, due to renew in three weeks for a site already gone.

Writing this found two problems in the buyer's own Cancel, which the admin panel shares; both are
fixed (WP1, WP2):
- it only worked for Stripe subscriptions (B1);
- it was a link, so any page a signed-in buyer — or an admin — visited could cancel a subscription (B2).

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **Money moves.** Every provider call in a test is doubled; nothing reaches Stripe or PayPal from
  a test, test mode included.
- **API first.** The action is a logic function with a `_logic_descriptor()`, and the page calls
  `/api/v1` with the session credential (`docs/api.md` § Authentication). No `/ajax/` endpoint.
- **Actions are buttons.** A cancel is a POST (`AdminPage::action_button()` on the admin side).
- **Docs describe the current state only. Bump the version header of every file touched.**
- `php -l` every PHP file; `validate_php_file.php` on class and function files.
- Tests use the shared harness with the `@joinery-test` header. Run `php tests/run.php --changed`,
  then `db --changed`.

## Current state (checked 2026-09-26)

- **Admin order page** (`plugins/store/admin/admin_order.php` ~:155–190): per subscription line it
  shows status, period end, the cancelled time, `[refund]` (permission 8 and a Stripe charge),
  `[edit]`, and "View sub_… on Stripe". No cancel.
- **Admin user page** (`SubscriptionsPanel`, on `/admin/admin_user`): a cancel button per active
  subscription, posting to the buyer's route, so always at the period's end.
- **Admin refund** (`plugins/store/admin/admin_order_refund.php`): permission 8, a POST confirm, then
  `StripeHelper::refund_charge()`. The one admin money action today; it asks for no step-up.
- **Buyer cancel:** `/profile/subscriptions` shows a Cancel button (a POST with a CSRF token) to
  `/profile/orders_recurring_action`. The logic (`orders_recurring_action_logic.php`) calls
  `OrderItem::cancel_subscription_order_item(true, 'period_end')`, which cancels with the provider
  that bills it, and emails `subscription_notification_emails`. The iOS app reaches the same logic
  through the API.
- **Providers:** `OrderItem::get_payment_source()` knows `stripe`, `paypal`, `app_store` and
  `play_store`. `PaypalHelper::cancel_subscription()` exists (`change_tier_logic.php:461` uses it).
  App Store subscriptions are cancelled by the buyer at Apple; the merchant cannot. `GooglePlayHelper`
  has no cancel.
- **What a cancel sets off:** the Stripe webhook's `customer.subscription.deleted`
  (`plugins/store/ajax/stripe_webhook.php:143`) marks the order item canceled, dispatches
  `subscription.cancelled` on the SignalBus (a hosted site's trial starts its grace period and is shut
  down at the deadline, `HostedTrialSignals`), and calls `TierBilling::handleSubscriptionExpired()`.
  A site whose webhook is not set up gets none of that; dev's webhook secret is empty.
- **Step-up:** `SessionControl::require_recent_second_factor()` (page) and `step_up_outstanding()`
  (API) are the shared gates for sensitive administration (`docs/account_security.md` § Step-up).

## Bugs found

**B1 — the buyer's Cancel only worked for Stripe (fixed, WP1).** `cancel_subscription_order_item()`
(`order_items_class.php:243`) calls `StripeHelper::cancel_subscription()` with
`odi_stripe_subscription_id` whatever the payment source. The buyer's list shows every active
subscription with a Cancel button, so a PayPal, App Store or Google Play subscription fails with
"We were unable to cancel that subscription () Please contact the webmaster."

**B2 — a GET cancelled a subscription (fixed, WP2).** Cancel is a link, and the logic acts on `$_GET`. The session
cookie is `SameSite=Lax`, which goes with a top-level GET, so a link or redirect from another site
cancels a signed-in buyer's subscription. The GET-mutation guard (`SystemBase::assert_not_get_mutation`)
is log-only during its rollout, so the local save goes through too and only logs `[GET_MUTATION]`.

## Design

**WP1 — One cancel routine, by provider (fixes B1). Built 2026-09-26.** `cancel_subscription_order_item()` dispatches
on `get_payment_source()`:
- `stripe`: `StripeHelper::cancel_subscription()`, `period_end` or `immediate`.
- `paypal`: `PaypalHelper::cancel_subscription()`, which takes no timing: PayPal stops billing at
  once, so the row records it cancelled now, as the plan-change page already does
  (`change_tier_logic.php:461`). The buyer's confirm says so ("PayPal ends it now").
- `app_store`, `play_store`: refused with where the buyer cancels it (Apple's or Google's
  subscription settings). The buyer's list shows that text instead of a Cancel button.

**WP2 — The buyer's Cancel is a POST (fixes B2). Built 2026-09-26.** A button with the browser's
confirm, posting the `subscription_cancel` form's token; the view checks the token and the logic
refuses anything that is not a POST. The admin user panel's cancel is the same POST
(`action_button`). The API path is unchanged: it already required POST.
- **Built:** `OrderItem` 1.1 (`subscription_cancel_blocker()`, provider seams),
  `orders_recurring_action_logic` 1.1, the two views, `SubscriptionsPanel` 1.1,
  `subscription_summary_logic` 1.2.0 (`can_cancel` reads the blocker), store 1.5.8, and
  `docs/subscription_tiers.md`. Test `subscription_cancel` (16/16). Live on dev: a GET and a forged
  POST to the route are both refused; both pages render the POST button with the right confirm.

**WP3 — Cancel on the admin order page.** Per active subscription line, a "Cancel subscription…"
action beside `[refund]`:
- A confirm dialog offers **At the end of the paid period** (preselected; the buyer keeps what they
  paid for) or **Now** (for a service already ended, like a retired hosted site).
- Logic `admin_order_cancel_subscription_logic` with a descriptor: `order_item_id` and `when`
  (`period_end` | `now`). Permission per D1, step-up per D2.
- It runs WP1's routine and records who cancelled and how (an event log row naming the admin).
- It notifies `subscription_notification_emails` as the buyer path does, and the buyer per D3.
- The line then shows "Cancels on <date>" or "Canceled on <date>, by <admin>".

**WP4 — A cancel "now" does not wait for the webhook.** After the provider confirms an immediate
cancel, the action applies what `customer.subscription.deleted` applies: the order item's state, the
`subscription.cancelled` signal and the tier expiry. That code moves out of `stripe_webhook.php` into
one function the webhook and the action both call. A webhook arriving later finds the work done and
changes nothing. A period-end cancel changes nothing locally beyond the cancel date; the webhook ends
it at the period's end, as today.

**WP5 — Server Manager points at it.** The removal message in `ManagedNode::release_site_records()`
says to cancel the named subscription on its order page, and links there.

**Tests**
- WP1: each source routes to its provider double, a PayPal cancel is recorded as ended now, and the
  store sources refuse with their text.
- WP2: a GET does nothing and a POST cancels.
- WP3: permission below the line is refused, a missing step-up answers `requires_stepup`, both
  timings reach the provider double, and the event row names the admin.
- WP4: an immediate cancel dispatches `subscription.cancelled` once, and a webhook replayed after it
  dispatches nothing more.

## Open decisions

**D1 — Who may cancel?**
- **Permission 8, as refunds:** the same people who can already give the money back. Catch: none
  beyond refunds'.
- **Permission 10 only:** narrower. Catch: an admin who can refund a charge cannot stop the next one.
- **Recommendation:** 8, matching refunds.

**D2 — Step-up before an admin cancel?**
- **Yes:** a cancel is a money action, and a stolen admin session should not end customers'
  billing. Catch: refunds do not ask today, so the two differ until refunds adopt it too.
- **No:** matches refunds. Catch: the stolen-session case stays open for both.
- **Recommendation:** yes, and bring refunds under the same gate in the same change.

**D3 — Tell the buyer when an admin cancels?**
- **Yes, with the existing `subscription_cancelled` template:** the buyer learns their billing
  stopped, and when. Catch: an operator ending a test purchase sends a real email to the test address.
- **No:** Catch: a buyer finds out from a missing renewal, or a shutdown.
- **Recommendation:** yes.

## Not in this spec

- **A refund at cancel time.** Cancelling never refunds; the refund page stays the way to give money
  back.
- **Cancelling App Store and Google Play subscriptions for the buyer.** Apple does not allow it;
  Google Play's revoke API is a separate feature if it is ever wanted.
- **Turning the GET-mutation guard to throwing.** B2 is fixed at its source here; the guard's own
  rollout finishes that for every other page.
