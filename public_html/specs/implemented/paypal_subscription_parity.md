# PayPal Subscription Parity Specification

**Status:** Active
**Created:** 2026-03-22
**Priority:** High

## Overview

Bring PayPal subscription management to parity with Stripe. Today, PayPal checkout works for one-time payments and subscription creation, but subscription lifecycle management (cancellation, status sync, billing management) is completely missing. After checkout, PayPal subscriptions are fire-and-forget — we never hear from PayPal again.

## Current State

### What works
- One-time payment checkout (capture flow)
- Subscription plan creation (`createPlan()`)
- Subscription checkout via PayPal smart buttons
- Product creation in PayPal catalog
- Payment validation (`validatePayment()`)
- Subscription details lookup (`subDetails()`)

### What's missing

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
| **Refunds** | `refund_charge()` | API exists, not implemented | Deferred — admins refund through PayPal dashboard |

## What PayPal Cannot Do (True Platform Limitations)

These are not implementation gaps — PayPal's API genuinely doesn't support these:

- **Hosted payment method update portal** — No equivalent to Stripe Billing Portal. Users must update their payment method at paypal.com.
- **In-place billing cycle change** — Can't switch a subscription from monthly to yearly. Must cancel and create new subscription.
- **Proration** — PayPal doesn't prorate when changing plans. The old plan runs to period end, then the new plan starts.
- **Multiple payment methods** — PayPal subscription is tied to the user's PayPal account funding source, not a specific card.

## Implementation

### 1. Database: Store PayPal Subscription ID

Add `odi_paypal_subscription_id` field to `order_items` (`varchar(64)`).

Sandbox subscription IDs use the format `I-XXXXXXXXXX` (e.g., `I-BW452GLLEP1G`). The `varchar(64)` field is sufficient for both sandbox and production IDs.

### 2. Checkout: Capture and Verify Subscription ID

The current PayPal subscription checkout redirects to `/cart_charge?subscription=1` — but doesn't pass the subscription ID back. The subscription ID is available in the `onApprove` callback as `data.subscriptionID`.

**Fix `output_paypal_subscription_checkout_code()` in PaypalHelper.php:**
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

**Fix `cart_charge_logic.php` to verify and store:**
```php
if ($_GET['subscription'] && $_GET['paypal_subscription_id']) {
    // Verify the subscription exists and is active before trusting the client-provided ID
    $paypal = new PaypalHelper();
    $sub_details = $paypal->subDetails($_GET['paypal_subscription_id']);
    if (!$sub_details || !in_array($sub_details['status'], ['ACTIVE', 'APPROVED'])) {
        return _checkout_error('PayPal subscription could not be verified.');
    }

    // ... existing subscription handling ...
    $order_item->set('odi_paypal_subscription_id', $_GET['paypal_subscription_id']);
    $order_item->save();
}
```

### 3. PaypalHelper: Subscription Management Methods

Add to `PaypalHelper`:

```php
/**
 * Cancel a PayPal subscription.
 */
public function cancel_subscription($subscription_id, $reason = 'Customer requested cancellation') {
    // POST /v1/billing/subscriptions/{id}/cancel
    // Body: {"reason": $reason}
}

/**
 * Suspend (pause) a PayPal subscription.
 */
public function suspend_subscription($subscription_id, $reason = 'Customer requested pause') {
    // POST /v1/billing/subscriptions/{id}/suspend
    // Body: {"reason": $reason}
}

/**
 * Reactivate a suspended PayPal subscription.
 */
public function activate_subscription($subscription_id, $reason = 'Customer requested reactivation') {
    // POST /v1/billing/subscriptions/{id}/activate
    // Body: {"reason": $reason}
}

/**
 * Get subscription transaction history.
 */
public function get_subscription_transactions($subscription_id, $start_time, $end_time) {
    // GET /v1/billing/subscriptions/{id}/transactions?start_time={}&end_time={}
}

/**
 * Verify a webhook signature.
 */
public function verify_webhook_signature($headers, $body) {
    // POST /v1/notifications/verify-webhook-signature
}
```

All methods follow the existing curl pattern in PaypalHelper (see `subDetails()`, `createPlan()`, etc.)

### 4. Webhook Handler

Create `/ajax/paypal_subscription_webhook.php` to receive PayPal subscription lifecycle events.

**Events to handle:**

| Event | Action |
|-------|--------|
| `BILLING.SUBSCRIPTION.ACTIVATED` | Set `odi_subscription_status = 'active'` |
| `BILLING.SUBSCRIPTION.CANCELLED` | Set `odi_subscription_cancelled_time`, update status |
| `BILLING.SUBSCRIPTION.SUSPENDED` | Set `odi_subscription_status = 'suspended'` |
| `BILLING.SUBSCRIPTION.EXPIRED` | Set `odi_subscription_status = 'expired'` |
| `BILLING.SUBSCRIPTION.PAYMENT.FAILED` | Set `odi_subscription_status = 'past_due'`, notify admin |
| `BILLING.SUBSCRIPTION.RENEWED` | Update `odi_subscription_period_end` for renewal tracking |

**Implementation details:**

- **Respond HTTP 200 immediately.** PayPal has a 30-second timeout. Read the payload, respond 200, then process. If we don't respond fast enough, PayPal retries and causes duplicate processing.
- **Idempotency:** PayPal can resend the same event. Use the `event_id` field from the webhook payload to deduplicate — check if we've already processed this event before acting. Store processed event IDs in a simple table or use a cache with TTL.
- **Verification:** PayPal webhooks include `PAYPAL-TRANSMISSION-SIG`, `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-TIME`, and `PAYPAL-CERT-URL` headers. Verify by POSTing these to PayPal's `/v1/notifications/verify-webhook-signature` endpoint with the webhook ID and event body. Reject and log if verification fails.
- **Event-to-subscription mapping:** All `BILLING.SUBSCRIPTION.*` events include `resource.id` as the PayPal subscription ID (format `I-XXXXXXXXXX`). The subscription state is in `resource.state`. Verified via sandbox API simulation — the `PAYMENT.SALE.COMPLETED` event is for one-time payments only and has no subscription link, so we use `BILLING.SUBSCRIPTION.RENEWED` for renewal tracking instead.

**The primary reason for the webhook:** Users commonly cancel subscriptions directly through paypal.com/myaccount/autopay — not through our site. Without the webhook, these cancellations go undetected and the user retains access indefinitely.

**Configuration:**
- Add `paypal_webhook_id` setting (obtained when registering webhook at PayPal developer dashboard)
- Add `paypal_webhook_id_test` setting (separate webhook for sandbox)
- Register webhook URL: `https://yoursite.com/ajax/paypal_subscription_webhook`
- Subscribe to events: `BILLING.SUBSCRIPTION.*` (not `PAYMENT.SALE.*` — those are one-time payment events)

### 5. Change-Tier Page Integration

The `cancel` and `reactivate` actions in `change_tier_logic.php` currently only handle Stripe. Add PayPal paths:

```php
if ($order_item->get('odi_paypal_subscription_id')) {
    $paypal = new PaypalHelper();
    $paypal->cancel_subscription($order_item->get('odi_paypal_subscription_id'));
} elseif ($order_item->get('odi_stripe_subscription_id')) {
    // existing Stripe cancellation
}
```

Same pattern for `reactivate` action.

Always check the specific order item's subscription ID fields to determine payment system — don't assume all subscriptions are Stripe. A user could theoretically have historical orders from both systems.

### 6. Billing Page Integration

The billing page (`/profile/billing`) already exists with PayPal placeholders. The remaining integration point:

**Billing history:** Update `billing_logic.php` to call `PaypalHelper::get_subscription_transactions()` when a PayPal subscription ID is available:
```php
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

**Already implemented (no changes needed):**
- Payment method section shows "Managed through PayPal" with link to paypal.com
- Billing cycle section shows "cancel and re-subscribe" message
- Payment system detection checks `odi_paypal_subscription_id` — will work automatically once subscription IDs are stored

### 7. Sync Scheduled Task

Create `SyncPaypalSubscriptions` scheduled task that periodically checks PayPal subscription status for all active PayPal subscriptions. Safety net for missed webhooks.

```php
class SyncPaypalSubscriptions implements ScheduledTaskInterface {
    public function run(array $config) {
        // Find all order items with odi_paypal_subscription_id where status is active
        // For each, call subDetails()
        // Update odi_subscription_status, odi_subscription_period_end
        // If status changed (cancelled externally, expired, etc.), handle accordingly
    }
}
```

Run daily.

## Cart Restriction Note

The existing cart restriction (can't mix subscriptions with non-subscription items when PayPal is enabled) should remain. This is a PayPal checkout limitation — PayPal subscription buttons use a different SDK flow than one-time payment buttons, and they can't be combined in a single transaction.

## Refunds

PayPal's refund API (`POST /v2/payments/captures/{capture_id}/refund`) exists but is **deferred from this spec**. Currently admins refund PayPal transactions through the PayPal dashboard. Adding programmatic refunds is a separate, lower-priority enhancement that can be added to the admin order management page later.

## Files to Create/Modify

**New files:**
- `/ajax/paypal_subscription_webhook.php` — Webhook handler
- `/tasks/SyncPaypalSubscriptions.php` + `.json` — Status sync scheduled task

**Modified files:**
- `/data/order_items_class.php` — Add `odi_paypal_subscription_id` field
- `/includes/PaypalHelper.php` — Add `cancel_subscription()`, `suspend_subscription()`, `activate_subscription()`, `get_subscription_transactions()`, `verify_webhook_signature()`. Fix `output_paypal_subscription_checkout_code()` to pass subscription ID back.
- `/logic/cart_charge_logic.php` — Verify and store PayPal subscription ID on checkout
- `/logic/change_tier_logic.php` — Add PayPal paths for cancel/reactivate actions
- `/logic/billing_logic.php` — Add PayPal transaction history lookup

**Already done (no changes needed):**
- `/views/profile/billing.php` — PayPal payment method and billing cycle messages
- `/logic/billing_logic.php` — PayPal detection via `odi_paypal_subscription_id`

## Testing Requirements

**PayPal sandbox accounts are required.** Real PayPal accounts cannot be used in sandbox mode. Create sandbox buyer and seller accounts at https://developer.paypal.com/dashboard/accounts. The sandbox buyer email/password are needed for browser-based checkout testing.

**Webhook testing:** Use PayPal's webhook simulator at https://developer.paypal.com/dashboard/webhooksSimulator to send test events during development. For end-to-end testing, the webhook URL must be publicly accessible (not localhost).

## Testing Plan

- [ ] PayPal subscription checkout stores `odi_paypal_subscription_id`
- [ ] Subscription ID verified via `subDetails()` before storing
- [ ] Webhook receives and processes `BILLING.SUBSCRIPTION.CANCELLED`
- [ ] Webhook receives and processes `BILLING.SUBSCRIPTION.RENEWED`
- [ ] Webhook signature verification rejects invalid requests
- [ ] Webhook handles duplicate events without double-processing
- [ ] Cancel subscription from change-tier page works for PayPal subscriptions
- [ ] Reactivate suspended subscription works
- [ ] Subscription status displays correctly on profile for PayPal subscribers
- [ ] Billing page shows "Managed through PayPal" for payment method (already implemented)
- [ ] Billing page shows "cancel and re-subscribe" for billing cycle (already implemented)
- [ ] Billing page shows transaction history from PayPal API
- [ ] SyncPaypalSubscriptions scheduled task updates stale statuses
- [ ] User who cancels via paypal.com is detected by webhook and/or sync task
- [ ] Cart restriction still prevents mixing subscription + non-subscription items
