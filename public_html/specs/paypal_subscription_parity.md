# PayPal Subscription Parity Specification

**Status:** Active
**Created:** 2026-03-22
**Priority:** High

## Overview

Bring PayPal subscription management to parity with Stripe. Today, PayPal checkout works for one-time payments and subscription creation, but subscription lifecycle management (cancellation, status sync, billing management) is completely missing. After checkout, PayPal subscriptions are fire-and-forget — we never hear from PayPal again.

## Current State

### What works (PayPal)
- One-time payment checkout (capture flow)
- Subscription plan creation (`createPlan()`)
- Subscription checkout via PayPal smart buttons
- Product creation in PayPal catalog
- Payment validation (`validatePayment()`)
- Subscription details lookup (`subDetails()`)

### What's missing (PayPal has but we don't use)

| Capability | Stripe | PayPal | Gap |
|-----------|--------|--------|-----|
| **Store subscription ID after checkout** | `odi_stripe_subscription_id` | Not stored | Critical — blocks everything else |
| **Webhook for status changes** | `stripe_webhook.php` handles events | No webhook handler | Critical — we never learn about cancellations, failures, renewals |
| **Cancel subscription** | `cancel_subscription()` | API exists, not implemented | Users can't cancel from our site |
| **Reactivate subscription** | `reactivate_subscription()` | API exists, not implemented | Users can't reactivate from our site |
| **Suspend/pause subscription** | Not implemented | API exists, not implemented | Neither system has this |
| **Change billing cycle** | `change_subscription()` | Requires cancel + re-subscribe | PayPal doesn't support in-place plan changes |
| **View subscription status** | Read from `odi_subscription_status` | No data stored | Can't show status on profile/billing page |
| **Payment failure handling** | Webhook updates status | No webhook | Failed renewals go unnoticed |
| **Billing history / transactions** | `get_invoices()` | API exists (`/transactions`), not implemented | No invoice display for PayPal |
| **Update payment method** | Stripe Billing Portal | Managed at paypal.com only | Not possible via API — PayPal limitation |
| **Refunds** | `refund_charge()` | API exists, not implemented | Admin must refund through PayPal dashboard |

## Implementation Plan

### Phase 1: Store the Subscription ID (Critical Foundation)

Everything depends on this. Without a stored subscription ID, we can't do anything.

**Database:**
- Add `odi_paypal_subscription_id` field to `order_items` (`varchar(64)`)

**Checkout flow change (`cart_charge_logic.php`):**

The current PayPal subscription checkout redirects to `/cart_charge?subscription=1` — but doesn't pass the subscription ID back. The subscription ID is available in the `onApprove` callback as `data.subscriptionID`.

Fix `output_paypal_subscription_checkout_code()`:
```javascript
// Current (broken):
onApprove: function(data, actions) {
    window.location.href = "/cart_charge?subscription=1";
}

// Fixed:
onApprove: function(data, actions) {
    window.location.href = "/cart_charge?subscription=1&paypal_subscription_id="
        + encodeURIComponent(data.subscriptionID);
}
```

Fix `cart_charge_logic.php` to store it:
```php
if ($_GET['subscription'] && $_GET['paypal_subscription_id']) {
    // ... existing subscription handling ...
    $order_item->set('odi_paypal_subscription_id', $_GET['paypal_subscription_id']);
    $order_item->save();
}
```

### Phase 2: Webhook Handler

Create `/ajax/paypal_subscription_webhook.php` to receive PayPal subscription lifecycle events.

**PayPal webhook events to handle:**

| Event | Action |
|-------|--------|
| `BILLING.SUBSCRIPTION.ACTIVATED` | Set `odi_subscription_status = 'active'` |
| `BILLING.SUBSCRIPTION.CANCELLED` | Set `odi_subscription_cancelled_time`, update status |
| `BILLING.SUBSCRIPTION.SUSPENDED` | Set `odi_subscription_status = 'suspended'` |
| `BILLING.SUBSCRIPTION.EXPIRED` | Set `odi_subscription_status = 'expired'` |
| `BILLING.SUBSCRIPTION.PAYMENT.FAILED` | Set `odi_subscription_status = 'past_due'`, notify admin |
| `PAYMENT.SALE.COMPLETED` | Update `odi_subscription_period_end` for renewal tracking |

**Webhook verification:** PayPal webhooks include a `PAYPAL-TRANSMISSION-SIG` header. Verify using PayPal's `/v1/notifications/verify-webhook-signature` endpoint.

**Configuration:**
- Add `paypal_webhook_id` setting (obtained when registering webhook at PayPal developer dashboard)
- Register webhook URL: `https://yoursite.com/ajax/paypal_subscription_webhook`

### Phase 3: Subscription Management Methods

Add to `PaypalHelper`:

```php
/**
 * Cancel a PayPal subscription.
 * @param string $subscription_id PayPal subscription ID
 * @param string $reason Cancellation reason
 * @return bool
 */
public function cancel_subscription($subscription_id, $reason = 'Customer requested cancellation') {
    // POST /v1/billing/subscriptions/{id}/cancel
    // Body: {"reason": $reason}
}

/**
 * Suspend (pause) a PayPal subscription.
 * @param string $subscription_id
 * @param string $reason
 * @return bool
 */
public function suspend_subscription($subscription_id, $reason = 'Customer requested pause') {
    // POST /v1/billing/subscriptions/{id}/suspend
    // Body: {"reason": $reason}
}

/**
 * Reactivate a suspended PayPal subscription.
 * @param string $subscription_id
 * @param string $reason
 * @return bool
 */
public function activate_subscription($subscription_id, $reason = 'Customer requested reactivation') {
    // POST /v1/billing/subscriptions/{id}/activate
    // Body: {"reason": $reason}
}

/**
 * Get subscription transaction history.
 * @param string $subscription_id
 * @param string $start_time ISO 8601 date
 * @param string $end_time ISO 8601 date
 * @return array
 */
public function get_subscription_transactions($subscription_id, $start_time, $end_time) {
    // GET /v1/billing/subscriptions/{id}/transactions?start_time={}&end_time={}
}
```

### Phase 4: Integrate with Change-Tier and Billing Pages

**Change-tier page (`change_tier_logic.php`):**
- The `cancel` action currently only handles Stripe. Add PayPal path:
  ```php
  if ($order_item->get('odi_paypal_subscription_id')) {
      $paypal = new PaypalHelper();
      $paypal->cancel_subscription($order_item->get('odi_paypal_subscription_id'));
  } elseif ($order_item->get('odi_stripe_subscription_id')) {
      // existing Stripe cancellation
  }
  ```
- Same pattern for `reactivate` action.

**Billing page (`/profile/billing` — already implemented):**

The billing page (`logic/billing_logic.php` + `views/profile/billing.php`) already exists with full Stripe support and PayPal placeholders. The PayPal integration points are:

1. **Payment method section** — Already shows "Your subscription is managed through PayPal" with a link to paypal.com/myaccount/autopay/ when `$payment_system === 'paypal'`. No changes needed.

2. **Billing cycle section** — Already shows "To change your billing cycle, cancel and re-subscribe" message when `$payment_system === 'paypal'`. No changes needed.

3. **Billing history section** — Currently falls back to local order history for non-Stripe users. After Phase 3 is implemented, update `billing_logic.php` to call `PaypalHelper::get_subscription_transactions()` when a PayPal subscription ID is available:
   ```php
   // In billing_logic.php, after the Stripe invoice lookup:
   if (empty($page_vars['invoices']) && $current_subscription && $current_subscription->get('odi_paypal_subscription_id')) {
       $paypal = new PaypalHelper();
       $transactions = $paypal->get_subscription_transactions(
           $current_subscription->get('odi_paypal_subscription_id'),
           date('Y-m-d\TH:i:s\Z', strtotime('-1 year')),
           date('Y-m-d\TH:i:s\Z')
       );
       // Map PayPal transactions to the same invoice array format
   }
   ```

4. **Payment system detection** — Already checks `odi_paypal_subscription_id` in billing_logic.php (line ~48). Once Phase 1 stores the PayPal subscription ID, this detection will work automatically.

### Phase 5: Sync Subscription Status

Create a scheduled task `SyncPaypalSubscriptions` that periodically checks PayPal subscription status for all active PayPal subscriptions. This is a safety net in case webhooks are missed.

```php
class SyncPaypalSubscriptions implements ScheduledTaskInterface {
    public function run(array $config) {
        // Find all order items with odi_paypal_subscription_id
        // For each, call subDetails()
        // Update odi_subscription_status, odi_subscription_period_end
        // If status changed, handle accordingly (cancelled, expired, etc.)
    }
}
```

Run daily. Catches any webhook failures.

## What PayPal Cannot Do (True Platform Limitations)

These are not implementation gaps — PayPal's API genuinely doesn't support these:

- **Hosted payment method update portal** — No equivalent to Stripe Billing Portal. Users must update their payment method at paypal.com.
- **In-place billing cycle change** — Can't switch a subscription from monthly to yearly. Must cancel and create new subscription.
- **Proration** — PayPal doesn't prorate when changing plans. The old plan runs to period end, then the new plan starts.
- **Multiple payment methods** — PayPal subscription is tied to the user's PayPal account funding source, not a specific card.

## Cart Restriction Note

The existing cart restriction (can't mix subscriptions with non-subscription items when PayPal is enabled) should remain. This is a PayPal checkout limitation — PayPal subscription buttons use a different SDK flow than one-time payment buttons, and they can't be combined in a single transaction.

## Files to Create/Modify

**New files:**
- `/ajax/paypal_subscription_webhook.php` — Webhook handler
- `/tasks/SyncPaypalSubscriptions.php` + `.json` — Status sync scheduled task

**Modified files:**
- `/data/order_items_class.php` — Add `odi_paypal_subscription_id` field
- `/includes/PaypalHelper.php` — Add `cancel_subscription()`, `suspend_subscription()`, `activate_subscription()`, `get_subscription_transactions()`, webhook signature verification
- `/includes/PaypalHelper.php` — Fix `output_paypal_subscription_checkout_code()` to pass subscription ID back
- `/logic/cart_charge_logic.php` — Store PayPal subscription ID on checkout
- `/logic/change_tier_logic.php` — Add PayPal paths for cancel/reactivate actions
- `/logic/billing_logic.php` — Add PayPal transaction history lookup (minor — the page already handles PayPal display, just needs the API call once `get_subscription_transactions()` exists)

**Already done (no changes needed):**
- `/views/profile/billing.php` — PayPal payment method message and billing cycle message already implemented
- `/logic/billing_logic.php` — PayPal detection via `odi_paypal_subscription_id` already in place

## Testing Plan

- [ ] PayPal subscription checkout stores `odi_paypal_subscription_id`
- [ ] Webhook receives and processes `BILLING.SUBSCRIPTION.CANCELLED`
- [ ] Webhook receives and processes `PAYMENT.SALE.COMPLETED`
- [ ] Webhook signature verification rejects invalid requests
- [ ] Cancel subscription from change-tier page works for PayPal subscriptions
- [ ] Reactivate suspended subscription works
- [ ] Subscription status displays correctly on profile for PayPal subscribers
- [ ] Billing page shows "Managed through PayPal" for payment method (already implemented)
- [ ] Billing page shows "cancel and re-subscribe" for billing cycle (already implemented)
- [ ] Billing page shows transaction history from PayPal API (after Phase 3+4)
- [ ] SyncPaypalSubscriptions scheduled task updates stale statuses
- [ ] Cart restriction still prevents mixing subscription + non-subscription items

## Priority Order

1. **Phase 1** (Store subscription ID) — 1-2 hours, unblocks everything
2. **Phase 2** (Webhook handler) — 4-6 hours, critical for status sync
3. **Phase 3** (Management methods) — 2-3 hours, enables cancel/reactivate
4. **Phase 4** (Page integration) — 2-3 hours, user-facing features
5. **Phase 5** (Sync task) — 1-2 hours, safety net
