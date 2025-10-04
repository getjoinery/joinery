# Change Subscription Page Specification

## Overview
Create a generic subscription management page at `/profile/change-subscription` that handles billing and subscription administration **without** tier-based logic. This complements the tier-specific `/profile/change-tier` page.

## Purpose
Provide subscription management for:
- Single-product businesses (no tiers needed)
- Billing cycle changes (monthly ↔ yearly)
- Payment method updates
- Billing history and invoices
- Subscription pause/resume (if supported)
- Generic cancellation interface

## URL
`/profile/change-subscription`

## Files to Create

### 1. Logic File: `/logic/change_subscription_logic.php`
**Function:** `change_subscription_logic($get, $post)`

**Responsibilities:**
- Session validation (redirect to login if not authenticated)
- Load user's active subscription(s) from `order_items` table
- Handle POST actions for subscription management
- Integration with StripeHelper for billing operations
- Return subscription data via LogicResult::render()

**POST Actions:**
1. **`action=change_billing_cycle`**
   - Switch between monthly/yearly billing
   - Parameters: `new_price_id` (Stripe price ID)
   - Use `StripeHelper::change_subscription()` to update subscription
   - Pro-rate immediately or schedule for next period (configurable)

2. **`action=update_payment_method`**
   - Redirect to Stripe customer portal for payment method management
   - Use `StripeHelper::create_billing_portal_session()`
   - Return URL: `/profile/change-subscription?payment_updated=1`

3. **`action=cancel_subscription`**
   - Cancel subscription at period end or immediately (based on settings)
   - Use `StripeHelper::cancel_subscription()`
   - Mark `odi_subscription_cancelled_time` in order_items
   - Option to provide cancellation reason (save to change_tracking)

4. **`action=reactivate_subscription`**
   - Reactivate cancelled subscription before period end
   - Use `StripeHelper::reactivate_subscription()`
   - Clear `odi_subscription_cancel_at_period_end` flag

5. **`action=pause_subscription`** (future enhancement)
   - Pause subscription for 1-3 months
   - Use Stripe subscription schedules
   - Track pause duration in order_items

**Page Variables Returned:**
```php
$page_vars = array(
    'user' => User object,
    'subscriptions' => MultiOrderItem (active subscriptions),
    'has_active_subscription' => boolean,
    'current_subscription' => OrderItem or null,
    'subscription_status' => 'active'|'canceled'|'past_due'|'expired',
    'period_end' => timestamp,
    'cancel_at_period_end' => boolean,
    'current_product' => Product object,
    'current_version' => ProductVersion object,
    'billing_cycle' => 'month'|'year',
    'alternative_versions' => array of ProductVersion objects (for cycle switching),
    'invoices' => array of recent invoices from Stripe,
    'payment_method' => array (last4, brand, exp_month, exp_year),
    'next_billing_date' => timestamp,
    'next_billing_amount' => decimal,
    'settings' => array(
        'cancellation_enabled' => boolean,
        'cancellation_timing' => 'immediate'|'end_of_period',
        'billing_cycle_changes_enabled' => boolean,
        'pause_subscription_enabled' => boolean,
    ),
    'success_message' => string (if action succeeded),
    'error_message' => string (if action failed),
);
```

### 2. View File: `/views/profile/change-subscription.php`
**Template:** Clean, modern UI using PublicPage and FormWriter

**Sections:**

#### A. Subscription Overview Card
- Current plan name and description
- Billing cycle (monthly/yearly)
- Next billing date and amount
- Status badge (Active, Cancelled, Past Due)
- If cancelled: Show cancellation date and "Access until" message

#### B. Billing Cycle Switcher
- Show if `alternative_versions` exist
- Toggle or button interface to switch monthly ↔ yearly
- Display savings amount for yearly (e.g., "Save 17% with yearly billing")
- Form submits `action=change_billing_cycle` with `new_price_id`
- Disabled if subscription is cancelled

#### C. Payment Method Section
- Display current payment method (Visa •••• 4242, Expires 12/25)
- "Update Payment Method" button
- Links to Stripe billing portal via `action=update_payment_method`
- Show "Payment method updated" success message if `?payment_updated=1`

#### D. Billing History Table
- Last 10 invoices from Stripe
- Columns: Date, Description, Amount, Status, Download Link
- Pagination for >10 invoices (future enhancement)
- Use Stripe invoice PDF links for downloads

#### E. Subscription Actions
**Cancel Subscription Button:**
- Red/warning styled button
- Shows modal/confirmation dialog with:
  - "Are you sure you want to cancel?"
  - Impact statement ("Access until [date]" or "Immediate cancellation")
  - Optional: Cancellation reason dropdown/textarea
  - Confirm/Keep Subscription buttons
- Only shows if `cancellation_enabled` setting is true
- Disabled if already cancelled

**Reactivate Subscription Button:**
- Green/success styled button
- Only shows if subscription is cancelled but not yet expired
- Simple confirmation: "Resume your subscription?"
- Submits `action=reactivate_subscription`

**Pause Subscription Button:** (future enhancement)
- Only shows if `pause_subscription_enabled` setting is true
- Modal to select pause duration (1-3 months)
- Explains billing will resume after pause period

#### F. Additional Features
- Link to contact support for downgrades/special requests
- Link to `/profile/change-tier` if tiers are enabled (feature flag)
- Subscription FAQ/Help section (collapsible)

### 3. Settings to Add (via admin or migration)
Add to `stg_settings` table:
```
subscription_billing_cycle_changes_enabled = 1
subscription_pause_enabled = 0 (future)
subscription_cancellation_requires_reason = 0
subscription_invoice_history_limit = 10
```

## UI/UX Guidelines

### Design Principles:
1. **Clarity** - User always knows their current status and next billing date
2. **Safety** - Destructive actions (cancel) require confirmation
3. **Transparency** - Show exact charges, dates, and impact of changes
4. **Helpfulness** - Provide alternative actions (e.g., pause instead of cancel)

### Visual Hierarchy:
1. Current subscription status (most prominent)
2. Quick actions (change cycle, update payment)
3. Billing history (reference information)
4. Cancellation (least prominent, requires scrolling)

### Mobile Responsive:
- Stack cards vertically on mobile
- Ensure buttons are touch-friendly (min 44px height)
- Collapse billing history table to card layout on small screens

## Integration Points

### StripeHelper Methods Required:
- `change_subscription($subscription_id, $item_id, $new_price_id)` ✅ (already exists)
- `cancel_subscription($subscription_id, $timing)` ✅ (already exists)
- `reactivate_subscription($subscription_id)` ✅ (already exists)
- `create_billing_portal_session($customer_id, $return_url)` - **Need to add**
- `get_customer_invoices($customer_id, $limit)` - **Need to add**
- `get_payment_method($payment_method_id)` - **Need to add**

### Database Schema:
No schema changes required - uses existing tables:
- `order_items` - Subscription data
- `orders` - Order history
- `products` - Product information
- `product_versions` - Pricing/billing cycle options
- `stg_settings` - Feature flags

### Error Handling:
- Stripe API errors → Display friendly message, log to error_log
- Invalid subscription state → Redirect to `/pricing`
- Missing payment method → Show "Payment required" message
- Network issues → "Please try again" with retry button

## Success Criteria
1. ✅ User can view current subscription status and billing details
2. ✅ User can switch billing cycles (monthly ↔ yearly) in one click
3. ✅ User can update payment method via Stripe portal
4. ✅ User can view/download invoices
5. ✅ User can cancel subscription with proper confirmation
6. ✅ User can reactivate cancelled subscription before expiry
7. ✅ All actions show clear success/error messages
8. ✅ Page works for single-product subscriptions (no tier logic)
9. ✅ Mobile responsive and accessible

## Testing Checklist
- [ ] Active subscription displays correctly
- [ ] Cancelled subscription shows expiry date
- [ ] Billing cycle switcher calculates savings correctly
- [ ] Payment method update redirects to Stripe portal and back
- [ ] Invoices load from Stripe API
- [ ] Cancel subscription updates database and Stripe
- [ ] Reactivate subscription clears cancellation
- [ ] Settings flags properly enable/disable features
- [ ] Error messages display for Stripe API failures
- [ ] Works on mobile/tablet/desktop viewports

## Future Enhancements
1. **Subscription Pause** - Temporary hold for 1-3 months
2. **Upgrade Prompts** - If tiers exist, show "Upgrade to unlock..." messages
3. **Usage Metering** - Display usage stats for usage-based billing
4. **Multiple Subscriptions** - Handle users with >1 active subscription
5. **Proration Preview** - Show exact charges before billing cycle change
6. **Cancellation Surveys** - Collect feedback on why users cancel
7. **Win-back Offers** - Show discount when user attempts to cancel
8. **Referral Credits** - Display account credits/referral bonuses

## Notes
- This page is **tier-agnostic** - it manages subscription billing only
- For tier changes (upgrade/downgrade), users should use `/profile/change-tier`
- Works with or without the subscription_tiers system
- Can be used standalone for simple subscription businesses
- ControlD plugin should NOT override this page (use change-tier for tier management)

## Timeline Estimate
- Logic file: 4-6 hours
- View file: 4-6 hours
- StripeHelper additions: 2-3 hours
- Testing: 2-3 hours
- **Total: ~12-18 hours**
