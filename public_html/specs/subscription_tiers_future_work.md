# Subscription Tier System - Future Work & Testing

This document consolidates testing guidance and future feature enhancements for the subscription tier system.

**Current Status:** Core system complete (Phases 1-3). All specs in `/specs/implemented/`.

---

## Part 1: Testing Status & Guidance

### Automated Test Coverage

**Current Coverage:** ~60-70% automated, ~30-40% requires manual testing

The automated test suite (`SubscriptionTierTester.php`) validates:

1. ✅ Model layer (SubscriptionTier, MultiSubscriptionTier)
2. ✅ Basic tier assignment and removal
3. ✅ Upgrade-only purchase logic (downgrades via purchase blocked)
4. ✅ Feature access (getUserFeature, getUserTierDisplay)
5. ✅ Minimum tier level checking
6. ✅ Change tracking integration
7. ✅ Stripe price ID auto-sync (with real Stripe API)
8. ✅ Upgrade via change_subscription_logic (with Stripe)
9. ✅ Downgrade immediate (with Stripe)
10. ✅ Cancellation immediate (with Stripe)
11. ✅ Stripe customer creation
12. ✅ Stripe subscription creation
13. ✅ Order and OrderItem creation through full flow
14. ✅ Cleanup of test data (Stripe and database)

### Manual Testing Required

#### **Priority 1 - Critical User Flows**

**1. UI/UX in `/change-subscription` view**
- [ ] Navigate to page as logged-in user
- [ ] Verify all tiers display correctly
- [ ] Test upgrade button functionality
- [ ] Test downgrade button (if enabled)
- [ ] Test cancel button (if enabled)
- [ ] Verify error messages display correctly
- [ ] Verify success messages display correctly

**2. End-of-Period Timing**
- [ ] Schedule a downgrade for end of period
- [ ] Wait for billing period to end (or mock date)
- [ ] Verify tier changes at correct time
- [ ] Verify user maintains access until period end

**3. Reactivation Flow**
- [ ] Cancel a subscription with end-of-period timing
- [ ] Before period ends, try to reactivate
- [ ] Verify reactivation works
- [ ] Verify user stays in tier

#### **Priority 2 - Error Handling**

**4. Stripe API Failures**
- [ ] Temporarily break Stripe connectivity
- [ ] Attempt upgrade/downgrade/cancel
- [ ] Verify graceful error handling
- [ ] Verify user is notified appropriately

**5. Authentication & Authorization**
- [ ] Access `/change-subscription` while logged out
- [ ] Verify redirect to login
- [ ] Verify return URL works after login

#### **Priority 3 - Integration Points**

**6. Webhook Processing**
- [ ] Use Stripe CLI to send test webhooks
- [ ] Verify subscription.updated processes correctly
- [ ] Verify payment failures are handled

**7. Initial Purchase via Cart**
- [ ] Add subscription product to cart
- [ ] Complete checkout
- [ ] Verify user is assigned to correct tier
- [ ] Verify Stripe subscription is created

### Not Tested - Requires Manual Testing or Future Test Development

#### **View Layer (UI)**
- [ ] `/views/change-subscription.php` rendering
- [ ] Tier display cards shown to users
- [ ] Button states based on settings (enabled/disabled/hidden)
- [ ] Form submission from UI
- [ ] Success/error messages displayed to users
- [ ] Redirect behavior after actions

#### **End-of-Period Timing**
- [ ] Downgrade scheduled for end of period (test shows it's scheduled, but doesn't verify it happens)
- [ ] Cancellation scheduled for end of period
- [ ] Tier actually changes at period end (would require waiting 30 days or mocking time)
- [ ] Pending product changes (`odi_pending_product_id`) processing

#### **Reactivation Flow**
- [ ] Reactivating a cancelled subscription
- [ ] Settings control for reactivation (`subscription_reactivation_enabled`)
- [ ] Reactivation only works before expiration
- [ ] Reactivation after expiration is blocked

#### **Proration Scenarios**
- [ ] Actual credit amounts on downgrades
- [ ] Actual charge amounts on upgrades
- [ ] Proration on immediate cancellation (`subscription_cancellation_prorate`)
- [ ] Verifying Stripe invoice line items

#### **Edge Cases & Error Handling**
- [ ] What happens if Stripe API fails mid-upgrade
- [ ] Invalid product IDs passed to logic file
- [ ] Missing Stripe price IDs on products
- [ ] Concurrent subscription changes by same user
- [ ] User not logged in (redirect to login)
- [ ] Expired subscriptions
- [ ] Failed payment handling
- [ ] Subscription already cancelled
- [ ] Trying to upgrade to same tier
- [ ] Trying to downgrade when downgrades disabled

#### **Session & Authentication**
- [ ] Permission checking in admin pages
- [ ] User must be logged in to access `/change-subscription`
- [ ] Return URL handling after login
- [ ] Session timeout during subscription change

#### **Webhook Processing**
- [ ] Stripe webhooks for subscription events
- [ ] `subscription.updated` webhook
- [ ] `subscription.deleted` webhook
- [ ] `invoice.payment_succeeded` webhook
- [ ] `invoice.payment_failed` webhook
- [ ] Processing tier changes from webhooks

#### **Integration with Cart/Checkout**
- [ ] Initial subscription purchase through cart
- [ ] Product scripts execution after tier assignment
- [ ] Multiple products in same tier scenario
- [ ] Free trial period handling
- [ ] Coupon codes on subscription purchases

#### **Email Notifications**
- [ ] Subscription confirmation emails
- [ ] Upgrade confirmation emails
- [ ] Downgrade notification emails
- [ ] Cancellation confirmation emails
- [ ] Reactivation confirmation emails

#### **Admin Features**
- [ ] Viewing tier members in admin (`admin_subscription_tier_members.php`)
- [ ] Manually assigning users to tiers (UI)
- [ ] Manually removing users from tiers (UI)
- [ ] Admin subscription tier CRUD operations (create/edit/delete via UI)
- [ ] Admin settings page for subscription management

#### **Multiple Billing Intervals**
- [ ] Monthly vs yearly product versions
- [ ] User choosing billing interval during upgrade/downgrade
- [ ] Changing billing interval without changing tier

#### **Group Integration**
- [ ] Group permissions based on tier membership
- [ ] Multiple groups per tier (if supported)
- [ ] Group member listing filtered by tier

#### **Feature System Edge Cases**
- [ ] Features with no default value
- [ ] Plugin features when plugin is disabled
- [ ] Core tier features from `/includes/core_tier_features.json`
- [ ] Feature value validation

#### **Data Consistency**
- [ ] What happens if Stripe subscription exists but no OrderItem
- [ ] What happens if OrderItem exists but Stripe subscription deleted
- [ ] Orphaned tier assignments
- [ ] Multiple active subscriptions per user (should be prevented)

#### **Settings Combinations Not Fully Tested**
- [ ] All disabled (upgrades still work via purchase)
- [ ] Downgrades end-of-period (scheduled but not executed)
- [ ] Cancellation end-of-period (scheduled but not executed)
- [ ] Proration enabled on cancellation with actual refund verification

### Testing Notes

**Test Database:** All automated tests use test database and Stripe test mode

**Stripe Cleanup:** Automated tests clean up all Stripe test data (customers, subscriptions)

**Manual Testing Environment:** Use staging environment with Stripe test keys

**Date/Time Testing:** End-of-period features require waiting for billing cycles or date mocking

### Future Test Improvements

Potential enhancements to the automated test suite:

- [ ] Add date/time mocking to test end-of-period scenarios
- [ ] Add webhook simulation tests
- [ ] Add UI testing with Selenium/Playwright
- [ ] Add performance testing (concurrent subscription changes)
- [ ] Add load testing (many users changing subscriptions)
- [ ] Mock Stripe API for faster tests (separate from integration tests)
- [ ] Add tests for all error scenarios
- [ ] Add reactivation flow tests
- [ ] Add proration verification tests

---

## Part 2: Future Feature Enhancements

The following features can be implemented incrementally after the core system is complete.

### 1. Email Notifications

**Goal:** Send automated emails for subscription events

**Priority:** HIGH (critical for user communication)

#### A. Email Templates

Create templates in `emt_email_templates`:
- `subscription_created` - Welcome email with subscription details
- `subscription_upgraded` - Confirmation of upgrade with new features
- `subscription_downgraded` - Confirmation of downgrade with date
- `subscription_cancelled` - Cancellation confirmation
- `subscription_reactivated` - Reactivation confirmation
- `subscription_expired` - Notification that subscription ended
- `subscription_payment_failed` - Payment failure notice

#### B. Integration Points

Add email sending to:
- `/logic/change_subscription_logic.php` - After successful actions
- `/data/subscription_tiers_class.php` - After automatic expiration
- Stripe webhooks - On subscription events

#### C. Email Content

Include in all subscription emails:
- Current tier and features
- Effective date of change
- Next billing date/amount
- Link to manage subscription
- Support contact information

---

### 2. Enhanced Webhook Processing

**Goal:** Improve reliability and real-time subscription sync

**Priority:** HIGH (critical for reliability)

**Current State:** Basic webhook handling exists

#### A. Subscription Event Handlers

Improve `/ajax/stripe_webhook.php` to handle:
- `customer.subscription.updated` - Sync subscription changes
- `customer.subscription.deleted` - Handle cancellations
- `invoice.payment_succeeded` - Confirm renewals
- `invoice.payment_failed` - Handle failed payments

#### B. Automatic Tier Validation

In webhook handlers, call `SubscriptionTier::GetUserTier()` to trigger validation:
```php
case 'customer.subscription.updated':
    $subscription_id = $event->data->object->id;

    // Find user by subscription ID
    $order_items = new MultiOrderItem(['subscription_id' => $subscription_id]);
    $order_items->load();

    if ($order_items->count() > 0) {
        $order_item = $order_items->get(0);
        $user_id = $order_item->get('odi_usr_user_id');

        // Trigger validation which auto-removes expired tiers
        SubscriptionTier::GetUserTier($user_id);

        // Send notification if needed
    }
    break;
```

#### C. Webhook Logging

Log all webhook events to dedicated table for debugging:
```sql
CREATE TABLE wbh_webhook_logs (
    wbh_webhook_log_id BIGSERIAL PRIMARY KEY,
    wbh_provider VARCHAR(50) NOT NULL,
    wbh_event_type VARCHAR(100) NOT NULL,
    wbh_event_id VARCHAR(255),
    wbh_payload JSONB,
    wbh_processed BOOLEAN DEFAULT false,
    wbh_error_message TEXT,
    wbh_create_time TIMESTAMP(6) DEFAULT now()
);
```

---

### 3. PayPal Subscription Lifecycle Completion

**Goal:** Complete PayPal subscription management to match Stripe capabilities

**Priority:** HIGH (PayPal checkout and plan creation already work, but subscription lifecycle is incomplete)

**Current State:**
- ✅ `PaypalHelper` class exists with `createPlan()`, `subDetails()`, subscription button rendering
- ✅ Cart detects subscription items and creates PayPal plans before checkout
- ✅ `ord_payment_method` field tracks provider ('paypal', 'venmo', 'card', 'stripe')
- ✅ `odi_is_subscription`, `odi_subscription_status`, `odi_subscription_period_end` fields exist (generic)
- ❌ No `odi_paypal_subscription_id` field — PayPal subscription IDs are not persisted after checkout
- ❌ No PayPal webhook handler — subscription status changes from PayPal are never synced
- ❌ No PayPal subscription cancellation or management from the app
- ❌ `change_subscription_logic.php` only routes through Stripe

#### A. Store PayPal Subscription IDs

Add field to OrderItem:
- `odi_paypal_subscription_id` — Store the PayPal subscription ID returned at checkout approval

Update `cart_charge_logic.php` to capture and persist the PayPal subscription ID when processing subscription payments.

#### B. PayPal Webhook Handler

Create `/ajax/paypal_webhook.php` to handle:
- `BILLING.SUBSCRIPTION.ACTIVATED` — Confirm subscription active
- `BILLING.SUBSCRIPTION.CANCELLED` — Handle cancellations
- `BILLING.SUBSCRIPTION.SUSPENDED` — Handle payment failures
- `BILLING.SUBSCRIPTION.EXPIRED` — Handle expiration
- `PAYMENT.SALE.COMPLETED` — Confirm renewal payments

Use the same webhook logging table as Stripe (see Section 2C).

#### C. Provider-Aware Subscription Management

Update `/logic/change_subscription_logic.php` to:
- Detect payment provider from `ord_payment_method` on the user's active order
- Route cancel/downgrade/upgrade actions to the correct helper (StripeHelper or PaypalHelper)
- Add PayPal subscription cancellation via `PaypalHelper`

#### D. Provider-Aware Feature Gating in UI and Logic

Disable incompatible subscription features when the user's active subscription is through PayPal.

**Detection (in `change_tier_logic.php`):**
- Load the Order associated with the current subscription's OrderItem
- Check `ord_payment_method` — values `'paypal'` or `'venmo'` indicate PayPal provider
- Pass `$is_paypal` flag into `$page_vars` for the view

**Logic gates to add (alongside existing setting checks at lines ~210, 323, 400):**
- **Upgrade** — block for PayPal (no mid-cycle plan changes); show "cancel and re-subscribe" guidance
- **Downgrade** — block for PayPal (same reason)
- **Proration** — disable for PayPal (not natively supported)
- **Reactivation** — block or limit for PayPal depending on cancellation state

**View changes (`change-tier.php`):**
- For PayPal subscribers, replace upgrade/downgrade buttons with messaging: "To change your plan, cancel your current subscription and subscribe to a new tier"
- Show "Contact Support" as fallback where appropriate
- Cancel button remains available (routed to PayPal cancellation via Section 3C)

#### E. PayPal Limitations Reference

- PayPal does not support mid-cycle plan changes (upgrade/downgrade) — user must cancel and re-subscribe
- Proration is not natively supported — handle manually if needed
- Reactivation may be limited depending on cancellation state

---

## Implementation Priority Summary

### High Priority
1. **Email notifications** - Critical for user communication
2. **Enhanced webhook processing** - Critical for reliability (Stripe)
3. **PayPal subscription lifecycle** - PayPal checkout works but subscriptions are unmanaged after purchase

---

## Notes

- All enhancements can be implemented incrementally
- Each feature can be deployed independently
- Email notifications and webhooks are most important for production readiness
- PayPal subscription lifecycle should be completed alongside Stripe webhook work since they share infrastructure (webhook logging, provider-aware logic)

---

## Related Specifications

**Implemented (see `/specs/implemented/`):**
- `subscription_tiers_phase1.md` - Core tier system
- `subscription_tiers_phase2.md` - User-facing management
- `subscription_tiers_controld_migration.md` - Plugin migration
- `subscription_expiration_implementation.md` - Automatic expiration
