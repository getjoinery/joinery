# Subscription Tier System - Testing Status & Guidance

**Current Status:** Core system complete (Phases 1-3). All specs in `/specs/implemented/`.

**Related spec:** [Subscription Tiers Future Work](subscription_tiers_future_work.md) -- remaining feature enhancements

---

## Automated Test Coverage

**Current Coverage:** ~60-70% automated, ~30-40% requires manual testing

The automated test suite (`SubscriptionTierTester.php`) validates:

1. ✅ Model layer (SubscriptionTier, MultiSubscriptionTier)
2. ✅ Basic tier assignment and removal
3. ✅ Upgrade-only purchase logic (downgrades via purchase blocked)
4. ✅ Feature access (getUserFeature, getUserTierDisplay)
5. ✅ Minimum tier level checking
6. ✅ Change tracking integration
7. ✅ Stripe price ID auto-sync (with real Stripe API)
8. ✅ Upgrade via change_tier_logic (with Stripe)
9. ✅ Downgrade immediate (with Stripe)
10. ✅ Cancellation immediate (with Stripe)
11. ✅ Stripe customer creation
12. ✅ Stripe subscription creation
13. ✅ Order and OrderItem creation through full flow
14. ✅ Cleanup of test data (Stripe and database)
15. ✅ Reactivation flow (testLogicFileReactivation)
16. ✅ Proration on mid-cycle upgrades (testProration)

---

## Manual Testing Required

### **Priority 1 - Critical User Flows**

**1. UI/UX in `/change-tier` view**
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

### **Priority 2 - Error Handling**

**4. Stripe API Failures**
- [ ] Temporarily break Stripe connectivity
- [ ] Attempt upgrade/downgrade/cancel
- [ ] Verify graceful error handling
- [ ] Verify user is notified appropriately

**5. Authentication & Authorization**
- [ ] Access `/change-tier` while logged out
- [ ] Verify redirect to login
- [ ] Verify return URL works after login

### **Priority 3 - Integration Points**

**6. Webhook Processing**
- [ ] Use Stripe CLI to send test webhooks
- [ ] Verify subscription.updated processes correctly
- [ ] Verify payment failures are handled

**7. Initial Purchase via Cart**
- [ ] Add subscription product to cart
- [ ] Complete checkout
- [ ] Verify user is assigned to correct tier
- [ ] Verify Stripe subscription is created

---

## Not Tested - Requires Manual Testing or Future Test Development

### **View Layer (UI)**
- [ ] `/views/profile/change-tier.php` rendering
- [ ] Tier display cards shown to users
- [ ] Button states based on settings (enabled/disabled/hidden)
- [ ] Form submission from UI
- [ ] Success/error messages displayed to users
- [ ] Redirect behavior after actions

### **End-of-Period Timing**
- [ ] Downgrade scheduled for end of period (test shows it's scheduled, but doesn't verify it happens)
- [ ] Cancellation scheduled for end of period
- [ ] Tier actually changes at period end (would require waiting 30 days or mocking time)
- [ ] Pending product changes (`odi_pending_product_id`) processing

### **Reactivation Flow**
- [ ] Reactivating a cancelled subscription
- [ ] Settings control for reactivation (`subscription_reactivation_enabled`)
- [ ] Reactivation only works before expiration
- [ ] Reactivation after expiration is blocked

### **Proration Scenarios**
- [ ] Actual credit amounts on downgrades
- [ ] Actual charge amounts on upgrades
- [ ] Proration on immediate cancellation (`subscription_cancellation_prorate`)
- [ ] Verifying Stripe invoice line items

### **Edge Cases & Error Handling**
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

### **Session & Authentication**
- [ ] Permission checking in admin pages
- [ ] User must be logged in to access `/change-tier`
- [ ] Return URL handling after login
- [ ] Session timeout during subscription change

### **Stripe Webhook Processing**
- [ ] Stripe `customer.subscription.updated` webhook
- [ ] Stripe `customer.subscription.deleted` webhook
- [ ] Stripe `invoice.payment_succeeded` webhook
- [ ] Stripe `invoice.payment_failed` webhook
- [ ] Processing tier changes from Stripe webhooks

Note: PayPal webhook processing is implemented (`/ajax/paypal_subscription_webhook.php`) and handles 7 event types including activated, cancelled, suspended, expired, re-activated, renewed, and payment failed.

### **Integration with Cart/Checkout**
- [ ] Initial subscription purchase through cart
- [ ] Product scripts execution after tier assignment
- [ ] Multiple products in same tier scenario
- [ ] Free trial period handling
- [ ] Coupon codes on subscription purchases

### **Email Notifications**
- [ ] Subscription confirmation emails
- [ ] Upgrade confirmation emails
- [ ] Downgrade notification emails
- [ ] Cancellation confirmation emails
- [ ] Reactivation confirmation emails

### **Admin Features**
- [ ] Viewing tier members in admin (`admin_subscription_tier_members.php` -- referenced by links in admin but page does not exist)
- [ ] Manually assigning users to tiers (UI -- currently only possible via Groups admin)
- [ ] Manually removing users from tiers (UI -- currently only possible via Groups admin)
- [ ] Admin subscription tier CRUD operations (create/edit/delete via UI -- `admin_subscription_tiers.php` and `admin_subscription_tier_edit.php` exist)
- [ ] Admin settings page for subscription management

### **Multiple Billing Intervals**
- [ ] Monthly vs yearly product versions
- [ ] User choosing billing interval during upgrade/downgrade
- [ ] Changing billing interval without changing tier

### **Group Integration**
- [ ] Group permissions based on tier membership
- [ ] Multiple groups per tier (if supported)
- [ ] Group member listing filtered by tier

### **Feature System Edge Cases**
- [ ] Features with no default value
- [ ] Plugin features when plugin is disabled
- [ ] Core tier features from `/includes/core_tier_features.json`
- [ ] Feature value validation

### **Data Consistency**
- [ ] What happens if Stripe subscription exists but no OrderItem
- [ ] What happens if OrderItem exists but Stripe subscription deleted
- [ ] Orphaned tier assignments
- [ ] Multiple active subscriptions per user (should be prevented)

### **Settings Combinations Not Fully Tested**
- [ ] All disabled (upgrades still work via purchase)
- [ ] Downgrades end-of-period (scheduled but not executed)
- [ ] Cancellation end-of-period (scheduled but not executed)
- [ ] Proration enabled on cancellation with actual refund verification

---

## Testing Notes

**Test Database:** All automated tests use test database and Stripe test mode

**Stripe Cleanup:** Automated tests clean up all Stripe test data (customers, subscriptions)

**Manual Testing Environment:** Use staging environment with Stripe test keys

**Date/Time Testing:** End-of-period features require waiting for billing cycles or date mocking

---

## Future Test Improvements

Potential enhancements to the automated test suite:

- [ ] Add date/time mocking to test end-of-period scenarios
- [ ] Add webhook simulation tests
- [ ] Add UI testing with Selenium/Playwright
- [ ] Add performance testing (concurrent subscription changes)
- [ ] Add load testing (many users changing subscriptions)
- [ ] Mock Stripe API for faster tests (separate from integration tests)
- [ ] Add tests for all error scenarios
- [x] Add reactivation flow tests (testLogicFileReactivation exists)
- [x] Add proration verification tests (testProration exists)

**Last reviewed:** 2026-03-26
