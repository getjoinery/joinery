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

### 3. Documentation

**Priority:** HIGH for admin docs, MEDIUM for others

#### A. End-User Documentation

**File:** `/docs/user_subscription_guide.md`

**Contents:**
- How to view current subscription
- How to upgrade/downgrade
- How to cancel subscription
- How to reactivate
- Understanding proration
- FAQ section

#### B. Administrator Documentation

**File:** `/docs/admin_subscription_guide.md`

**Contents:**
- Creating and managing tiers
- Configuring subscription settings
- Adding features to tiers
- Manually assigning users to tiers
- Understanding change tracking
- Troubleshooting common issues

#### C. Developer Documentation

**File:** `/docs/dev_subscription_integration.md`

**Contents:**
- Using `getUserFeature()` in plugins
- Creating `tier_features.json` files
- Checking tier requirements
- Testing with tiers
- API reference for SubscriptionTier class

#### D. Migration Guide

**File:** `/docs/plugin_tier_migration.md`

**Contents:**
- How to migrate from hardcoded plans
- Creating tier features for plugins
- Refactoring permission checks
- User data migration strategies
- Testing migration

---

### 4. PayPal Integration (Optional)

**Priority:** LOW (optional alternative payment method)

**Note:** PayPal subscription support may be limited compared to Stripe

#### A. Assessment

Evaluate PayPal capabilities:
- Can PayPal handle subscription changes mid-cycle?
- Does PayPal support proration?
- Can we reactivate cancelled subscriptions?
- What webhook events are available?

#### B. Implementation (If Feasible)

**Database Changes:**
Add PayPal fields to OrderItem:
- `odi_paypal_subscription_id`
- `odi_payment_provider` (enum: 'stripe', 'paypal')

**Logic Changes:**
Update `/logic/change_subscription_logic.php` to:
- Detect payment provider
- Route to StripeHelper or PayPalHelper
- Handle provider-specific limitations

**Admin Settings:**
Add settings for provider preference:
- `subscription_payment_provider` - Default provider
- `subscription_allow_provider_choice` - Let users choose

#### C. Limitations

Document PayPal limitations:
- May not support immediate tier changes
- Proration may be manual
- Webhook processing differences
- Testing in PayPal sandbox

---

### 5. Additional Admin Tools

**Priority:** MEDIUM

#### A. Bulk User Validation

**File:** `/adm/admin_subscription_validation.php`

**Purpose:** Validate all users' subscriptions at once

**Features:**
- List all users with subscriptions
- Show subscription status
- Highlight expired/invalid subscriptions
- Bulk validation button
- Report of changes made

#### B. Subscription Analytics

**File:** `/adm/admin_subscription_analytics.php`

**Metrics:**
- Total subscriptions by tier
- Monthly recurring revenue by tier
- Churn rate
- Upgrade/downgrade trends
- Expiration predictions

#### C. Grace Period Configuration

**Setting:** `subscription_grace_period_days`

**Implementation:**
- Instead of immediate tier removal on expiration
- Allow X days of access after expiration
- Send reminder emails during grace period
- Remove tier only after grace period ends

---

### 6. Testing Enhancements

**Priority:** LOW (nice to have)

#### A. End-to-End UI Tests

Add Selenium/Playwright tests for:
- Complete upgrade flow through UI
- Complete downgrade flow through UI
- Cancellation and reactivation flows
- Error handling and messages

#### B. Webhook Simulation Tests

Create test framework for:
- Simulating Stripe webhook events
- Verifying correct processing
- Testing error handling
- Testing idempotency

#### C. Load Testing

Test performance under load:
- Concurrent subscription changes
- Many users checking features simultaneously
- Webhook processing under high volume

---

## Implementation Priority Summary

### High Priority
1. **Email notifications** - Critical for user communication
2. **Enhanced webhook processing** - Critical for reliability
3. **Administrator documentation** - Needed for operations

### Medium Priority
4. **Developer documentation** - Helpful for future development
5. **Bulk validation tool** - Useful admin utility
6. **End-user documentation** - Helpful for support

### Low Priority
7. **PayPal integration** - Optional payment method
8. **Subscription analytics** - Nice-to-have insights
9. **Grace period feature** - Optional enhancement
10. **Advanced testing** - Quality improvements

---

## Notes

- All enhancements can be implemented incrementally
- Each feature can be deployed independently
- Email notifications and webhooks are most important for production readiness
- Documentation should start with basics and expand iteratively
- PayPal integration should only proceed if technically feasible
- Testing enhancements provide long-term quality benefits but aren't critical

---

## Related Specifications

**Implemented (see `/specs/implemented/`):**
- `subscription_tiers_phase1.md` - Core tier system
- `subscription_tiers_phase2.md` - User-facing management
- `subscription_tiers_controld_migration.md` - Plugin migration
- `subscription_expiration_implementation.md` - Automatic expiration
