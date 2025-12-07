# Subscription Tier System - Manual Testing Guide for ControlD Plugin

## Overview

This document provides step-by-step manual testing instructions for the subscription tier system, with a focus on the ControlD (ScrollDaddy) plugin functionality.

**Test Environment:** Use Stripe test mode with test credit cards

**Prerequisites:**
- Test user account(s) created
- Stripe test keys configured
- Three subscription tiers configured: Basic, Premium, Pro
- Three products configured with Stripe price IDs (monthly and yearly)

---

## Test Environment Setup

### Before You Begin

1. **Verify Stripe Test Mode**
   - Check `/adm/admin_settings.php` for Stripe settings
   - Ensure `stripe_test_mode` is enabled (`1`)
   - Verify `stripe_test_api_key` and `stripe_test_publishable_key` are set

2. **Verify Subscription Tiers Exist**
   - Navigate to `/adm/admin_subscription_tiers.php`
   - Verify three tiers exist:
     - **Basic** (Level 10): 1 device, no advanced filters, no custom rules
     - **Premium** (Level 20): 3 devices, advanced filters, no custom rules
     - **Pro** (Level 30): 10 devices, advanced filters, custom rules

3. **Verify Products Are Linked to Tiers**
   - Navigate to `/adm/admin_products.php`
   - Verify each product is linked to its corresponding tier via `pro_sbt_subscription_tier_id`
   - Products should have active product versions with Stripe test price IDs

4. **Configure Subscription Settings**
   - Navigate to `/adm/admin_settings.php`
   - Verify these settings:
     - `subscription_downgrades_enabled`: `1` (enabled)
     - `subscription_downgrade_timing`: `end_of_period` or `immediate`
     - `subscription_cancellation_enabled`: `1` (enabled)
     - `subscription_cancellation_timing`: `end_of_period` or `immediate`
     - `subscription_reactivation_enabled`: `1` (enabled)
     - `subscription_cancellation_prorate`: `0` or `1` (as desired)

---

## Test Stripe Credit Cards

Use these test card numbers (no actual charges will occur):

- **Success:** `4242 4242 4242 4242`
- **Decline:** `4000 0000 0000 0002`
- **Insufficient funds:** `4000 0000 0000 9995`
- **Requires authentication:** `4000 0025 0000 3155`

**Expiry:** Any future date (e.g., 12/30)
**CVC:** Any 3 digits (e.g., 123)
**ZIP:** Any 5 digits (e.g., 12345)

---

## Test Scenarios

### Scenario 1: Initial Purchase - Monthly Basic Plan

**Goal:** Test first-time subscription purchase through cart/checkout flow

**Steps:**

1. **Logout** (if logged in): Navigate to `/logout`

2. **Browse Pricing Page**
   - Navigate to `/pricing` or `/plugins/controld/pricing`
   - Verify monthly plans are displayed by default
   - Verify three tiers are shown: Basic, Premium, Pro
   - Verify prices display correctly for each tier

3. **Select Basic Monthly Plan**
   - Click "Get Started" or "Buy Now" on Basic plan
   - Should redirect to product page or add to cart

4. **Add to Cart**
   - Click "Add to Cart" button
   - Verify item appears in cart at `/cart`

5. **Proceed to Checkout**
   - Click "Checkout" or "Proceed to Checkout"
   - If not logged in, should prompt to login or create account

6. **Login or Register**
   - Login with test account OR create new test account
   - After login, should return to cart/checkout

7. **Enter Payment Information**
   - Enter test credit card: `4242 4242 4242 4242`
   - Expiry: `12/30`
   - CVC: `123`
   - ZIP: `12345`

8. **Complete Purchase**
   - Click "Complete Purchase" or "Pay Now"
   - Should process successfully

**Expected Results at Stripe:**
- ✅ New customer created in Stripe test dashboard
- ✅ Subscription created with status "active"
- ✅ First invoice paid successfully
- ✅ Subscription interval: monthly
- ✅ Amount matches Basic monthly price

**Expected Results in Application:**
- ✅ User redirected to success/confirmation page
- ✅ User assigned to Basic tier (check `/profile/change-tier`)
- ✅ User added to Basic tier's group (check `grp_group_members` in database)
- ✅ Order created in `ord_orders` table
- ✅ OrderItem created in `odi_order_items` table with:
   - `odi_stripe_subscription_id` populated
   - `odi_subscription_period_end` set to ~30 days from now
   - `odi_subscription_cancelled_time` = NULL
- ✅ Change tracking record created in `cht_change_tracking` with reason 'purchase'

**Verify ControlD Access:**
- Navigate to `/profile` or `/plugins/controld/profile`
- Verify "Basic" plan is displayed
- Navigate to `/profile/devices`
- Verify you can add UP TO 1 device only
- Navigate to `/profile/ctldfilters_edit`
- Verify advanced filters (ad/malware) are NOT shown
- Navigate to `/profile/rules`
- Verify custom rules page is NOT accessible or shows "Pro plan required"

---

### Scenario 2: Upgrade from Basic to Premium (Mid-Cycle)

**Goal:** Test upgrading to a higher tier with proration

**Prerequisites:** Active Basic monthly subscription from Scenario 1

**Steps:**

1. **Login** with test account from Scenario 1

2. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Verify current plan shows "Basic"
   - Verify current subscription status is "Active"

3. **Select Period Filter**
   - Ensure "Monthly" tab is selected
   - Verify Premium and Pro monthly plans are shown

4. **Initiate Upgrade to Premium**
   - Click "Upgrade" button on Premium plan
   - Should show confirmation dialog or form

5. **Confirm Upgrade**
   - Confirm the upgrade action
   - System processes the change

**Expected Results at Stripe:**
- ✅ Existing subscription updated (NOT new subscription)
- ✅ New subscription item added with Premium price
- ✅ Old subscription item removed (Basic price)
- ✅ Invoice created with:
   - **Credit** for unused time on Basic plan (prorated)
   - **Charge** for Premium plan from now until period end
   - **Net amount** charged immediately (difference)
- ✅ Next billing date remains the same (original monthly anniversary)
- ✅ Subscription status: "active"

**Expected Results in Application:**
- ✅ Success message displayed
- ✅ User now shows "Premium" tier at `/profile/change-tier`
- ✅ User removed from Basic tier group
- ✅ User added to Premium tier group
- ✅ NEW Order created in `ord_orders` table
- ✅ NEW OrderItem created in `odi_order_items` table:
   - Same `odi_stripe_subscription_id` (subscription ID doesn't change)
   - `odi_subscription_period_end` updated to match Stripe
   - Old OrderItem marked as replaced or inactive
- ✅ Change tracking record created with reason 'upgrade'

**Verify New ControlD Access:**
- Navigate to `/profile/devices`
- Verify you can now add UP TO 3 devices
- Navigate to `/profile/ctldfilters_edit`
- Verify advanced filters (ad/malware blocking) ARE NOW shown
- Navigate to `/profile/rules`
- Verify custom rules page is still NOT accessible (Pro only)

**Verify in Stripe Test Dashboard:**
- Navigate to Stripe Dashboard > Customers
- Find your test customer
- Click on active subscription
- Verify subscription now has Premium price
- Check latest invoice:
   - Should show proration credit for Basic
   - Should show charge for Premium
   - Should show net charge amount

---

### Scenario 3: Downgrade from Premium to Basic (End of Period)

**Goal:** Test downgrade scheduling (not immediate)

**Prerequisites:** Active Premium monthly subscription from Scenario 2

**Configuration Required:**
- `subscription_downgrades_enabled` = `1`
- `subscription_downgrade_timing` = `end_of_period`

**Steps:**

1. **Login** with test account from Scenario 2

2. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Verify current plan shows "Premium"

3. **Initiate Downgrade to Basic**
   - Click "Downgrade" button on Basic plan
   - Should show warning about end-of-period downgrade

4. **Confirm Downgrade**
   - Confirm the downgrade action
   - System processes the change

**Expected Results at Stripe:**
- ✅ Subscription remains "active" (not cancelled)
- ✅ Subscription updated to switch to Basic at period end
- ✅ `schedule` object created showing upcoming tier change
- ✅ NO immediate invoice or charge
- ✅ NO credit issued yet
- ✅ Current period continues at Premium level

**Expected Results in Application:**
- ✅ Success message: "Downgrade scheduled for end of billing period"
- ✅ Current tier still shows "Premium" (until period ends)
- ✅ Subscription page shows pending downgrade message
- ✅ OrderItem updated:
   - `odi_pending_product_id` set to Basic product ID
   - `odi_subscription_period_end` remains unchanged
- ✅ Change tracking record created with reason 'downgrade_scheduled'

**Verify During Period:**
- User retains Premium access until period end
- Can add up to 3 devices
- Advanced filters still visible
- No change to subscription status

**Verify After Period Ends (Webhook or Manual Trigger):**
- ✅ User automatically moved to Basic tier
- ✅ User removed from Premium group
- ✅ User added to Basic group
- ✅ NEW OrderItem created for Basic subscription
- ✅ Device limit now enforced at 1 device
- ✅ If user has 2-3 devices, they can't add more but existing devices still work
- ✅ Change tracking record created with reason 'downgrade_executed'

**Note:** To test period end immediately, you can:
- Use Stripe CLI to forward webhook: `stripe listen --forward-to localhost/ajax/stripe_webhook.php`
- Use Stripe Dashboard to manually "end subscription period" (test mode only)
- Wait for actual billing date (not practical for testing)

---

### Scenario 4: Immediate Downgrade from Premium to Basic

**Goal:** Test immediate downgrade with proration

**Prerequisites:** Active Premium monthly subscription

**Configuration Required:**
- `subscription_downgrades_enabled` = `1`
- `subscription_downgrade_timing` = `immediate`
- `subscription_downgrade_prorate` = `1`

**Steps:**

1. **Update Settings** (if not already set)
   - Navigate to `/adm/admin_settings.php`
   - Set `subscription_downgrade_timing` = `immediate`
   - Save settings

2. **Login** with test account

3. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Verify current plan shows "Premium"

4. **Initiate Immediate Downgrade**
   - Click "Downgrade" button on Basic plan
   - Should show warning about immediate downgrade with credit

5. **Confirm Downgrade**
   - Confirm the downgrade action

**Expected Results at Stripe:**
- ✅ Subscription updated immediately
- ✅ New invoice created with:
   - **Credit** for unused Premium time (prorated)
   - **Charge** for Basic plan going forward
   - **Net credit** added to customer balance
- ✅ Next billing date: ~30 days from now (new cycle starts)
- ✅ Subscription now shows Basic price

**Expected Results in Application:**
- ✅ Success message displayed
- ✅ User immediately shows "Basic" tier
- ✅ User removed from Premium group immediately
- ✅ User added to Basic group immediately
- ✅ NEW Order and OrderItem created for Basic subscription
- ✅ Change tracking record created with reason 'downgrade'

**Verify New ControlD Access (Immediate):**
- Navigate to `/profile/devices`
- Verify device limit now 1
- Navigate to `/profile/ctldfilters_edit`
- Verify advanced filters NO LONGER shown

---

### Scenario 5: Upgrade from Basic to Pro (Yearly)

**Goal:** Test changing both tier AND billing interval

**Prerequisites:** Active Basic monthly subscription

**Steps:**

1. **Login** with test account

2. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Click "Yearly" tab to show yearly plans

3. **Select Pro Yearly Plan**
   - Click "Upgrade" on Pro yearly plan
   - Should show pricing and confirmation

4. **Confirm Upgrade**
   - Confirm the upgrade

**Expected Results at Stripe:**
- ✅ Subscription updated to Pro yearly
- ✅ Invoice created with:
   - Credit for unused Basic monthly time
   - Charge for full year of Pro
   - Net charge (Pro yearly - Basic monthly proration)
- ✅ Next billing date: ~365 days from now
- ✅ Subscription interval: yearly

**Expected Results in Application:**
- ✅ User shows "Pro" tier
- ✅ User added to Pro group
- ✅ OrderItem shows yearly subscription
- ✅ Change tracking record created

**Verify Pro Features:**
- Navigate to `/profile/devices`
- Verify device limit is 10
- Navigate to `/profile/ctldfilters_edit`
- Verify advanced filters ARE shown
- Navigate to `/profile/rules`
- Verify custom rules page IS NOW accessible

---

### Scenario 6: Cancel Subscription (End of Period)

**Goal:** Test cancellation without immediate access removal

**Prerequisites:** Active subscription (any tier)

**Configuration Required:**
- `subscription_cancellation_enabled` = `1`
- `subscription_cancellation_timing` = `end_of_period`
- `subscription_cancellation_prorate` = `0`

**Steps:**

1. **Login** with test account

2. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Locate "Cancel Subscription" button or section

3. **Initiate Cancellation**
   - Click "Cancel Subscription"
   - Should show warning about end-of-period cancellation

4. **Confirm Cancellation**
   - Confirm the cancellation

**Expected Results at Stripe:**
- ✅ Subscription status: "active" (until period end)
- ✅ `cancel_at_period_end` = true
- ✅ NO refund issued
- ✅ Subscription will auto-cancel at period end

**Expected Results in Application:**
- ✅ Success message: "Subscription cancelled. Access continues until [end date]"
- ✅ Subscription page shows "Cancellation scheduled" message
- ✅ Current tier access CONTINUES until period end
- ✅ OrderItem updated:
   - `odi_subscription_cancelled_time` set to current time
   - `odi_subscription_period_end` shows when access ends
- ✅ Change tracking record created with reason 'cancellation_scheduled'

**Verify During Period:**
- User retains full tier access until end date
- All features remain available

**Verify After Period Ends:**
- ✅ User removed from tier group
- ✅ User shows "No active subscription" at `/profile/change-tier`
- ✅ ControlD features become inaccessible
- ✅ Trying to access `/profile/devices` shows "upgrade required" or similar

---

### Scenario 7: Immediate Cancellation with Proration

**Goal:** Test immediate cancellation with refund

**Prerequisites:** Active subscription (any tier)

**Configuration Required:**
- `subscription_cancellation_enabled` = `1`
- `subscription_cancellation_timing` = `immediate`
- `subscription_cancellation_prorate` = `1`

**Steps:**

1. **Update Settings** (if not already set)
   - Navigate to `/adm/admin_settings.php`
   - Set `subscription_cancellation_timing` = `immediate`
   - Set `subscription_cancellation_prorate` = `1`
   - Save settings

2. **Login** with test account

3. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Click "Cancel Subscription"

4. **Confirm Immediate Cancellation**
   - Confirm the cancellation with proration

**Expected Results at Stripe:**
- ✅ Subscription status: "canceled"
- ✅ Invoice created with credit for unused time
- ✅ Credit amount added to customer balance (or refunded to card in live mode)
- ✅ Subscription ends immediately

**Expected Results in Application:**
- ✅ User IMMEDIATELY removed from tier group
- ✅ User shows "No active subscription"
- ✅ OrderItem updated:
   - `odi_subscription_cancelled_time` set
   - Marked as inactive
- ✅ Change tracking record created with reason 'cancellation_immediate'

**Verify Immediate Access Removal:**
- Navigate to `/profile/devices`
- Should show "No active subscription" or "Upgrade required"
- All ControlD features immediately inaccessible

---

### Scenario 8: Reactivate Cancelled Subscription

**Goal:** Test reactivation before period ends

**Prerequisites:** Subscription cancelled with end-of-period timing (from Scenario 6)

**Configuration Required:**
- `subscription_reactivation_enabled` = `1`

**Steps:**

1. **Login** with test account that has pending cancellation

2. **Navigate to Subscription Management**
   - Go to `/profile/change-tier`
   - Should show "Reactivate Subscription" button or message

3. **Initiate Reactivation**
   - Click "Reactivate Subscription"
   - Should confirm reactivation

4. **Confirm Reactivation**
   - Confirm the action

**Expected Results at Stripe:**
- ✅ Subscription `cancel_at_period_end` = false
- ✅ Subscription status: "active"
- ✅ Subscription will now continue and renew at period end

**Expected Results in Application:**
- ✅ Success message: "Subscription reactivated"
- ✅ Cancellation message removed from subscription page
- ✅ OrderItem updated:
   - `odi_subscription_cancelled_time` set to NULL
- ✅ Change tracking record created with reason 'reactivation'

**Verify Continued Access:**
- User retains tier access through and beyond period end
- Subscription will auto-renew at next billing date

---

### Scenario 9: Payment Failure Handling

**Goal:** Test handling of failed payment

**Prerequisites:** Active subscription

**Steps:**

1. **Update Payment Method to Failing Card**
   - Navigate to Stripe Dashboard > Customers > [Your Test Customer]
   - Update payment method to: `4000 0000 0000 0341` (card will decline next payment)
   - OR use Stripe CLI: `stripe customers update [customer_id] --invoice-settings.default_payment_method=[failing_payment_method]`

2. **Wait for Next Billing Attempt** OR **Manually Trigger Invoice**
   - Use Stripe CLI: `stripe invoices create --customer [customer_id] --subscription [subscription_id]`
   - Invoice will fail

3. **Check Stripe Dashboard**
   - Verify invoice status: "payment_failed"
   - Verify subscription status: "past_due"

4. **Check Application Response**
   - Navigate to `/profile/change-tier`
   - Should show warning about failed payment
   - Access should continue during grace period (if configured)

**Expected Results:**
- ✅ Stripe webhook `invoice.payment_failed` sent
- ✅ Application logs failed payment
- ✅ User notified (if email notifications implemented)
- ✅ Subscription enters grace period

**Recovery:**
- Update payment method to valid card: `4242 4242 4242 4242`
- Stripe will retry payment automatically
- Upon success, subscription returns to "active"

---

### Scenario 10: Expired Subscription Auto-Cleanup

**Goal:** Test automatic tier removal when subscription expires

**Prerequisites:** Subscription with `odi_subscription_period_end` in the past

**Setup:**

Option A - Manually expire in database:
```sql
-- Find your test user's order item
SELECT * FROM odi_order_items WHERE odi_usr_user_id = [user_id] AND odi_stripe_subscription_id IS NOT NULL;

-- Set period end to yesterday
UPDATE odi_order_items
SET odi_subscription_period_end = now() - interval '1 day'
WHERE odi_order_item_id = [order_item_id];
```

Option B - Cancel subscription and wait for period end (see Scenario 6)

**Steps:**

1. **Login** with test user that has expired subscription

2. **Navigate to Any Tier-Gated Feature**
   - Go to `/profile/change-tier`
   - OR go to `/profile/devices`
   - System calls `SubscriptionTier::GetUserTier()` which triggers validation

**Expected Results (Automatic):**
- ✅ `GetUserTier()` detects expired subscription
- ✅ User automatically removed from tier group
- ✅ `GetUserTier()` returns NULL (no tier)
- ✅ Change tracking record created with reason 'subscription_expired'
- ✅ User sees "No active subscription" message

**Verify Access Removed:**
- Navigate to `/profile/devices`
- Should show "Upgrade required" or similar
- All ControlD features inaccessible
- `/profile/change-tier` shows no current tier

---

## Edge Cases and Error Testing

### Edge Case 1: Multiple Concurrent Changes

**Goal:** Test race condition handling

**Steps:**
1. Open two browser windows with same logged-in user
2. In Window 1: Start upgrade to Premium
3. In Window 2: Simultaneously start upgrade to Pro
4. Complete both actions quickly

**Expected Results:**
- ✅ Only one change should succeed
- ✅ Second change should error: "Subscription already modified"
- ✅ OR second change processes but overrides first (acceptable)

### Edge Case 2: Upgrade to Same Tier

**Goal:** Test validation prevents upgrading to current tier

**Steps:**
1. Login with Basic tier user
2. Navigate to `/profile/change-tier`
3. Try to "upgrade" to Basic (current tier)

**Expected Results:**
- ✅ Upgrade button disabled or hidden for current tier
- ✅ OR error message: "You are already on this plan"

### Edge Case 3: Stripe API Failure

**Goal:** Test graceful error handling when Stripe is unreachable

**Steps:**
1. Temporarily invalidate Stripe API key in settings
2. Try to perform upgrade/downgrade/cancel
3. Restore valid API key

**Expected Results:**
- ✅ User-friendly error message displayed
- ✅ NO database changes made
- ✅ User remains on current tier
- ✅ Error logged for admin review

### Edge Case 4: No Active Subscription + Try to Cancel

**Goal:** Test error handling for invalid actions

**Steps:**
1. Login with user who has no subscription
2. Navigate to `/profile/change-tier`
3. Try to access "Cancel Subscription" action directly (via URL manipulation)

**Expected Results:**
- ✅ Error message: "No active subscription found"
- ✅ OR Cancel button not visible

### Edge Case 5: Product Not Linked to Tier

**Goal:** Test validation when product configuration is incorrect

**Steps:**
1. Create test product with NO `pro_sbt_subscription_tier_id`
2. Try to purchase or upgrade to this product

**Expected Results:**
- ✅ Error during checkout or upgrade: "Product not associated with tier"
- ✅ No tier assignment occurs
- ✅ Payment may still process (needs refund)

---

## Feature Access Testing

### ControlD Device Limits

**Test Basic Tier (1 device):**
1. Login with Basic tier user
2. Navigate to `/profile/devices`
3. Add 1 device successfully
4. Try to add 2nd device
   - ✅ Should show error: "Device limit reached (1/1)"
   - ✅ Add device button disabled or hidden

**Test Premium Tier (3 devices):**
1. Login with Premium tier user
2. Navigate to `/profile/devices`
3. Add 3 devices successfully
4. Try to add 4th device
   - ✅ Should show error: "Device limit reached (3/3)"

**Test Pro Tier (10 devices):**
1. Login with Pro tier user
2. Navigate to `/profile/devices`
3. Verify you can add up to 10 devices

### ControlD Advanced Filters

**Test Basic Tier:**
1. Login with Basic tier user
2. Navigate to `/profile/ctldfilters_edit`
3. ✅ Advanced filters (ad/malware blocking) should NOT be visible

**Test Premium/Pro Tiers:**
1. Login with Premium or Pro tier user
2. Navigate to `/profile/ctldfilters_edit`
3. ✅ Advanced filters SHOULD be visible and functional

### ControlD Custom Rules

**Test Basic/Premium Tiers:**
1. Login with Basic or Premium tier user
2. Try to access `/profile/rules`
3. ✅ Should redirect or show "Pro plan required"

**Test Pro Tier:**
1. Login with Pro tier user
2. Navigate to `/profile/rules`
3. ✅ Custom rules page should be accessible and functional

---

## Admin Testing

### Admin Tier Management

1. **Login as Admin** (permission level >= 5)

2. **View All Tiers**
   - Navigate to `/adm/admin_subscription_tiers.php`
   - ✅ All tiers displayed in table
   - ✅ Tier levels, names, and member counts shown

3. **Edit Tier Features**
   - Click "Edit" on a tier
   - Navigate to `/adm/admin_subscription_tier_edit.php?id=[tier_id]`
   - Modify feature values (e.g., increase max devices)
   - Save changes
   - ✅ Changes saved successfully
   - ✅ Users immediately get new limits (test by accessing feature)

4. **View Tier Members**
   - Click "Members" on a tier
   - Navigate to `/adm/admin_subscription_tier_members.php?id=[tier_id]`
   - ✅ All users in tier listed
   - ✅ Subscription status shown

5. **Manually Assign User to Tier**
   - Navigate to admin user edit page
   - Manually assign user to tier group
   - ✅ User gains tier access immediately

### Admin Settings Management

1. **Navigate to Settings**
   - Go to `/adm/admin_settings.php`

2. **Toggle Downgrades**
   - Set `subscription_downgrades_enabled` = `0`
   - Test as user: downgrade button should disappear
   - Set back to `1`
   - ✅ Setting takes effect immediately

3. **Change Timing Modes**
   - Change `subscription_cancellation_timing` between `immediate` and `end_of_period`
   - Test cancellation behavior changes accordingly

---

## Post-Testing Cleanup

### Clean Up Test Data

1. **In Stripe Dashboard:**
   - Navigate to Customers (Test Data)
   - Delete test customers created during testing
   - Or use Stripe CLI: `stripe customers delete [customer_id]`

2. **In Application Database:**
   - Remove test users if needed
   - Remove test orders
   - Clean up test subscriptions

3. **Reset Settings:**
   - Restore production-ready settings in `/adm/admin_settings.php`

---

## Success Criteria Summary

### Core Functionality
- ✅ Initial purchase creates subscription and assigns tier
- ✅ Upgrades process immediately with proration
- ✅ Downgrades work with configured timing
- ✅ Cancellations work with configured timing
- ✅ Reactivations restore cancelled subscriptions
- ✅ Expired subscriptions auto-remove tier access

### Stripe Integration
- ✅ Customers created correctly
- ✅ Subscriptions created and updated correctly
- ✅ Proration calculated accurately
- ✅ Invoices generated with correct line items
- ✅ Payments processed successfully

### Feature Access
- ✅ Device limits enforced per tier
- ✅ Advanced filters shown only for Premium/Pro
- ✅ Custom rules accessible only for Pro
- ✅ Access changes immediately upon tier change

### Data Integrity
- ✅ OrderItems track subscriptions correctly
- ✅ Group memberships updated accurately
- ✅ Change tracking logs all tier changes
- ✅ Subscription status reflects Stripe accurately

### Error Handling
- ✅ Payment failures handled gracefully
- ✅ Invalid actions prevented with clear errors
- ✅ Stripe API failures don't corrupt data
- ✅ Race conditions handled safely

---

## Troubleshooting Common Issues

### Issue: "No active subscription found" error

**Cause:** OrderItem not properly created or subscription ID missing

**Fix:** Check database for `odi_stripe_subscription_id` field

### Issue: User has subscription but no tier access

**Cause:** Tier assignment failed or expired

**Fix:**
- Check `grp_group_members` for tier group membership
- Check `odi_subscription_period_end` for expiration
- Call `SubscriptionTier::GetUserTier($user_id)` to trigger validation

### Issue: Proration amount incorrect

**Cause:** Stripe proration settings or period timing

**Fix:**
- Verify subscription `billing_cycle_anchor` in Stripe
- Check if upgrade happened very close to billing date

### Issue: Stripe webhook not processing

**Cause:** Webhook endpoint not configured or signature validation failing

**Fix:**
- Verify webhook URL in Stripe Dashboard: `/ajax/stripe_webhook.php`
- Check webhook signing secret matches application settings
- Use Stripe CLI for local testing: `stripe listen --forward-to localhost/ajax/stripe_webhook.php`

---

## Contact & Support

For issues or questions during testing:
- Check error logs: `/var/www/html/joinerytest/logs/error.log`
- Review Stripe Dashboard for payment details
- Check database tables: `odi_order_items`, `sbt_subscription_tiers`, `cht_change_tracking`
- Review relevant specs in `/specs/` directory

**End of Manual Testing Guide**
