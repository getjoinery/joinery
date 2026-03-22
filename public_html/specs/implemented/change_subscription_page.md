# Billing Page Specification

**Status:** Active
**URL:** `/profile/billing`
**Updated:** 2026-03-22

## Overview

A billing management page on the user profile for viewing and managing payment details, billing history, and billing cycle preferences. This page is separate from `/profile/change-tier` (which handles plan selection) — this page handles the money side.

## Context

The subscription plan management page (`/profile/change-tier`) already handles upgrade, downgrade, cancel, and reactivate. This page complements it with three billing-specific features that don't belong on the plan selection page.

---

## Feature 1: Payment Method Management

**What:** Display the card on file and let the user update it via Stripe's billing portal.

**Payment system compatibility:**

| Payment System | Supported | Notes |
|---------------|-----------|-------|
| Stripe | Yes | Full support via Billing Portal API |
| PayPal | No | PayPal manages payment methods within PayPal — users update their card/bank at paypal.com, not on our site |
| Free orders | N/A | No payment method to manage |

**Show this section when:** User has an active Stripe subscription (`odi_stripe_subscription_id` is set on any active order item) AND order was paid via Stripe (`ord_payment_method` is `stripe` or `stripe_checkout`).

**Hide this section when:** User's subscription was purchased via PayPal, or user has no active subscription.

**Implementation:**
- Add `create_billing_portal_session($customer_id, $return_url)` to StripeHelper (~10 lines, wraps `\Stripe\BillingPortal\Session::create`)
- Display: card brand icon, last 4 digits, expiry (from existing `get_payment_methods()`)
- "Update Payment Method" button POSTs `action=update_payment_method`
- Logic creates billing portal session, redirects user to Stripe
- Stripe redirects back to `/profile/billing?payment_updated=1`
- Show success message on return

**For PayPal subscribers:** Show a note: "Your subscription is managed through PayPal. To update your payment method, visit [paypal.com](https://paypal.com)."

---

## Feature 2: Billing Cycle Switcher

**What:** Switch between monthly and yearly billing for the current subscription without changing tiers.

**Payment system compatibility:**

| Payment System | Supported | Notes |
|---------------|-----------|-------|
| Stripe | Yes | Uses existing `StripeHelper::change_subscription()` |
| PayPal | No | PayPal subscription plan changes require cancelling and re-subscribing. No in-place cycle change API. |
| Free orders | N/A | No billing cycle |

**Show this section when:** User has an active Stripe subscription AND the current product has multiple ProductVersions with different `prv_price_type` values (e.g., both `month` and `year` versions exist).

**Hide this section when:** Only one billing cycle option exists for the product, OR subscription is via PayPal, OR subscription is cancelled.

**Implementation:**
- Load ProductVersions for the user's current product where `prv_price_type` differs from current
- Calculate and display savings: "Save X% with yearly billing" (compare annual cost of monthly vs yearly price)
- Toggle or button submits `action=change_billing_cycle` with `new_price_id`
- Calls existing `StripeHelper::change_subscription()` — prorate based on `subscription_downgrade_timing` setting
- Show confirmation before switching: "Your billing will change from $X/month to $Y/year. You'll be charged the prorated difference today."

**For PayPal subscribers:** Show a note: "To change your billing cycle, please cancel your current subscription and re-subscribe with the new billing option."

---

## Feature 3: Invoice / Billing History

**What:** Table of past invoices with dates, amounts, and PDF download links.

**Payment system compatibility:**

| Payment System | Supported | Notes |
|---------------|-----------|-------|
| Stripe | Yes | Full invoice API with hosted PDF links |
| PayPal | Partial | Can show order history from local database, but no PayPal-hosted invoice PDFs |
| Free orders | Yes | Show from local order history (amount = $0) |

**Show this section when:** User has any past orders (not just subscriptions — one-time purchases too).

**Implementation:**
- For Stripe customers: Add `get_customer_invoices($customer_id, $limit)` to StripeHelper. Returns invoice date, amount, status, and `invoice_pdf` URL.
- For all users: Fall back to local order history from `ord_orders` table if no Stripe customer ID or for non-Stripe orders.
- Display: Date, Description (product name), Amount, Status (Paid/Failed/Refunded), Download link
- Limit to 10 most recent, with "View all" link if more exist
- Stripe invoices include PDF download; local-only orders show "Receipt emailed" instead

---

## Page Layout

```
/profile/billing

+------------------------------------------+
| Billing & Payment                        |
+------------------------------------------+
|                                          |
|  Payment Method                          |
|  +------------------------------------+  |
|  | Visa •••• 4242    Expires 12/25    |  |
|  |            [Update Payment Method]  |  |
|  +------------------------------------+  |
|  (or PayPal note, or hidden entirely)    |
|                                          |
|  Billing Cycle                           |
|  +------------------------------------+  |
|  | Currently: Monthly ($7.99/mo)      |  |
|  | Switch to: Yearly ($79.99/yr)      |  |
|  |            Save 17%! [Switch]      |  |
|  +------------------------------------+  |
|  (or hidden if only one cycle option)    |
|                                          |
|  Billing History                         |
|  +------------------------------------+  |
|  | Date    | Description | Amt | PDF  |  |
|  | Mar 1   | Premium     | $7.99| ⬇  |  |
|  | Feb 1   | Premium     | $7.99| ⬇  |  |
|  | Jan 1   | Premium     | $7.99| ⬇  |  |
|  +------------------------------------+  |
|                                          |
+------------------------------------------+
```

## Files to Create/Modify

**New files:**
- `/views/profile/billing.php` — View
- `/logic/billing_logic.php` — Logic

**Modified files:**
- `/includes/StripeHelper.php` — Add `create_billing_portal_session()` and `get_customer_invoices()`

**No new routes needed** — `/profile/billing` resolves automatically via the view fallback system.

## StripeHelper Methods to Add

```php
/**
 * Create a Stripe Billing Portal session for payment method management.
 * @param string $stripe_customer_id
 * @param string $return_url URL to redirect back to after portal
 * @return \Stripe\BillingPortal\Session
 */
public function create_billing_portal_session($stripe_customer_id, $return_url) {
    return $this->stripe->billingPortal->sessions->create([
        'customer' => $stripe_customer_id,
        'return_url' => $return_url,
    ]);
}

/**
 * Get recent invoices for a Stripe customer.
 * @param string $stripe_customer_id
 * @param int $limit
 * @return array of invoice objects
 */
public function get_customer_invoices($stripe_customer_id, $limit = 10) {
    $invoices = $this->stripe->invoices->all([
        'customer' => $stripe_customer_id,
        'limit' => $limit,
    ]);
    return $invoices->data;
}
```

## Visibility Logic Summary

The page itself is always accessible at `/profile/billing` for any logged-in user. Individual sections show/hide based on what's applicable:

```
Payment Method section:
  SHOW IF:  has Stripe subscription (odi_stripe_subscription_id set)
  SHOW AS:  "Update via PayPal" note IF ord_payment_method is paypal/venmo
  HIDE IF:  no active subscription and no Stripe customer ID

Billing Cycle section:
  SHOW IF:  has active Stripe subscription
            AND product has multiple price_type versions
            AND subscription is not cancelled
  HIDE IF:  PayPal subscription, or single cycle option, or cancelled

Billing History section:
  ALWAYS SHOW (for any user with past orders)
  SOURCE:   Stripe invoices if Stripe customer, else local ord_orders
```

## Settings

No new settings needed — reuses existing subscription settings from change-tier:
- `subscription_cancellation_enabled`
- `subscription_cancellation_timing`
- `subscription_downgrades_enabled`

## Testing Checklist

- [ ] Stripe subscriber sees payment method with last 4 and expiry
- [ ] "Update Payment Method" redirects to Stripe portal and back
- [ ] PayPal subscriber sees "managed through PayPal" note instead of update button
- [ ] User with no subscription sees billing history only (no payment method or cycle sections)
- [ ] Billing cycle switcher shows correct savings percentage
- [ ] Cycle switch calls Stripe and updates subscription
- [ ] Cycle switcher hidden for PayPal subscriptions
- [ ] Cycle switcher hidden when only one cycle option exists
- [ ] Billing history shows Stripe invoices with PDF download links
- [ ] Billing history falls back to local orders for non-Stripe purchases
- [ ] Page accessible but sections appropriately empty for users with no purchase history
- [ ] Mobile responsive
