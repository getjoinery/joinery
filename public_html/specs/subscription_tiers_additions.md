# Subscription Tier System - Future Additions

This document contains additional features and enhancements for the subscription tier system that can be implemented after the core system and ControlD migration (Phase 3) are complete.

---

## 1. Email Notifications

**Goal:** Send automated emails for subscription events

### A. Email Templates

Create templates in `emt_email_templates`:
- `subscription_created` - Welcome email with subscription details
- `subscription_upgraded` - Confirmation of upgrade with new features
- `subscription_downgraded` - Confirmation of downgrade with date
- `subscription_cancelled` - Cancellation confirmation
- `subscription_reactivated` - Reactivation confirmation
- `subscription_expired` - Notification that subscription ended
- `subscription_payment_failed` - Payment failure notice

### B. Integration Points

Add email sending to:
- `/logic/change_subscription_logic.php` - After successful actions
- `/data/subscription_tiers_class.php` - After automatic expiration
- Stripe webhooks - On subscription events

### C. Email Content

Include in all subscription emails:
- Current tier and features
- Effective date of change
- Next billing date/amount
- Link to manage subscription
- Support contact information

---

## 2. Enhanced Webhook Processing

**Current State:** Basic webhook handling exists

**Enhancements:**

### A. Subscription Event Handlers

Improve `/ajax/stripe_webhook.php` to handle:
- `customer.subscription.updated` - Sync subscription changes
- `customer.subscription.deleted` - Handle cancellations
- `invoice.payment_succeeded` - Confirm renewals
- `invoice.payment_failed` - Handle failed payments

### B. Automatic Tier Validation

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

### C. Webhook Logging

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

## 3. Documentation

### A. End-User Documentation

**File:** `/docs/user_subscription_guide.md`

**Contents:**
- How to view current subscription
- How to upgrade/downgrade
- How to cancel subscription
- How to reactivate
- Understanding proration
- FAQ section

### B. Administrator Documentation

**File:** `/docs/admin_subscription_guide.md`

**Contents:**
- Creating and managing tiers
- Configuring subscription settings
- Adding features to tiers
- Manually assigning users to tiers
- Understanding change tracking
- Troubleshooting common issues

### C. Developer Documentation

**File:** `/docs/dev_subscription_integration.md`

**Contents:**
- Using `getUserFeature()` in plugins
- Creating `tier_features.json` files
- Checking tier requirements
- Testing with tiers
- API reference for SubscriptionTier class

### D. Migration Guide

**File:** `/docs/plugin_tier_migration.md`

**Contents:**
- How to migrate from hardcoded plans
- Creating tier features for plugins
- Refactoring permission checks
- User data migration strategies
- Testing migration

---

## 4. PayPal Integration (Optional)

**Note:** PayPal subscription support may be limited compared to Stripe

### A. Assessment

Evaluate PayPal capabilities:
- Can PayPal handle subscription changes mid-cycle?
- Does PayPal support proration?
- Can we reactivate cancelled subscriptions?
- What webhook events are available?

### B. Implementation (If Feasible)

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

### C. Limitations

Document PayPal limitations:
- May not support immediate tier changes
- Proration may be manual
- Webhook processing differences
- Testing in PayPal sandbox

---

## 5. Additional Admin Tools

### A. Bulk User Validation

**File:** `/adm/admin_subscription_validation.php`

**Purpose:** Validate all users' subscriptions at once

**Features:**
- List all users with subscriptions
- Show subscription status
- Highlight expired/invalid subscriptions
- Bulk validation button
- Report of changes made

### B. Subscription Analytics

**File:** `/adm/admin_subscription_analytics.php`

**Metrics:**
- Total subscriptions by tier
- Monthly recurring revenue by tier
- Churn rate
- Upgrade/downgrade trends
- Expiration predictions

### C. Grace Period Configuration

**Setting:** `subscription_grace_period_days`

**Implementation:**
- Instead of immediate tier removal on expiration
- Allow X days of access after expiration
- Send reminder emails during grace period
- Remove tier only after grace period ends

---

## 6. Testing Enhancements

### A. End-to-End UI Tests

Add Selenium/Playwright tests for:
- Complete upgrade flow through UI
- Complete downgrade flow through UI
- Cancellation and reactivation flows
- Error handling and messages

### B. Webhook Simulation Tests

Create test framework for:
- Simulating Stripe webhook events
- Verifying correct processing
- Testing error handling
- Testing idempotency

### C. Load Testing

Test performance under load:
- Concurrent subscription changes
- Many users checking features simultaneously
- Webhook processing under high volume

---

## Implementation Priority

### High Priority
1. Email notifications (user communication)
2. Enhanced webhook processing (reliability)
3. Administrator documentation

### Medium Priority
4. Developer documentation
5. Bulk validation tool
6. End-user documentation

### Low Priority
7. PayPal integration
8. Subscription analytics
9. Grace period feature
10. Advanced testing

---

## Notes

- These additions can be implemented incrementally
- Each feature can be deployed independently
- Email notifications and webhooks are most important for production
- Documentation should start with basics and expand iteratively
- PayPal integration may be deferred if not feasible
