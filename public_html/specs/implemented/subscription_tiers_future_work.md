# Subscription Tier System - Future Work

Future feature enhancements for the subscription tier system.

**Current Status:** Core system complete (Phases 1-3). All specs in `/specs/implemented/`.

**Related specs:**
- [Subscription Tiers Testing](subscription_tiers_testing.md) -- testing status, manual test checklists, future test improvements

---

## 1. Email Notifications

**Goal:** Send automated emails for subscription events

**Priority:** HIGH (critical for user communication)

### A. Email Templates

Templates are inserted via migration v92 (`migration_subscription_email_templates.php`). All 7 templates are type 2 (inner) and use the default outer template and footer. Site owners can customize the wording via the admin email template editor after migration runs.

| Template Name | Subject | Key Variables |
|--------------|---------|---------------|
| `subscription_created` | Welcome to your new subscription | `tier_name`, `billing_amount`, `next_billing_date` |
| `subscription_upgraded` | Your subscription has been upgraded | `tier_name`, `previous_tier_name`, `next_billing_date` |
| `subscription_downgraded` | Your subscription has been changed | `tier_name`, `previous_tier_name`, `effective_date` |
| `subscription_cancelled` | Your subscription has been cancelled | `tier_name`, `access_end_date` |
| `subscription_reactivated` | Your subscription has been reactivated | `tier_name`, `next_billing_date` |
| `subscription_expired` | Your subscription has expired | `tier_name` |
| `subscription_payment_failed` | Action required: subscription payment failed | `tier_name`, `billing_amount` |

All templates use `*web_dir*` and `*site_name*` (auto-injected by `EmailTemplate.php`). Links point to plain `/change-tier` URLs without `act_code` — users who aren't logged in will be redirected to login and back. No name salutation is used (names may not be accurate for all users).

### B. Integration Points

Add email sending via `EmailSender::sendTemplate()` to:
- `/logic/change_tier_logic.php` - After successful upgrade, downgrade, cancel, reactivate actions
- `/data/subscription_tiers_class.php` - After automatic expiration
- `/ajax/stripe_webhook.php` - On payment failure events (implemented alongside Section 2A Stripe webhook handlers)
- `/ajax/paypal_subscription_webhook.php` - On payment failure events

Note: Sections 1 and 2 should be implemented together since Stripe payment failure emails require the Stripe subscription webhook handlers from Section 2A.

**Payment failure email dedup:** Payment processors may retry failed payments multiple times, each generating a distinct webhook event. Before sending `subscription_payment_failed` emails, query `WebhookLog` for a matching event type processed in the last 24 hours. If one exists, log the webhook but skip the email. This applies to both Stripe `invoice.payment_failed` and PayPal `BILLING.SUBSCRIPTION.PAYMENT.FAILED`.

### C. Email Content

All templates include:
- Current/new tier name
- Effective date or next billing date (where applicable)
- Link to `/change-tier` for subscription management
- Generic sign-off with `*site_name*`

---

## 2. Enhanced Webhook Processing

**Goal:** Improve reliability and real-time subscription sync

**Priority:** HIGH (critical for reliability)

**Current State:**
- ✅ Stripe webhook (`/ajax/stripe_webhook.php`) handles `checkout.session.completed` only
- ✅ PayPal webhook (`/ajax/paypal_subscription_webhook.php`) handles 7 subscription events (activated, cancelled, suspended, expired, re-activated, renewed, payment failed) with signature verification and idempotency tracking
- ❌ Stripe does NOT handle subscription lifecycle events (updated, deleted, invoice events)
- ❌ No webhook logging data model (PayPal uses a raw inline `paypal_webhook_events` table for idempotency only -- no SystemBase model, created via `CREATE TABLE IF NOT EXISTS` on every request)
- ❌ Neither webhook handler calls `SubscriptionTier::GetUserTier()` for tier validation

### A. Stripe Subscription Event Handlers

Improve `/ajax/stripe_webhook.php` to handle:
- `customer.subscription.updated` - Sync subscription changes
- `customer.subscription.deleted` - Handle cancellations
- `invoice.payment_succeeded` - Confirm renewals
- `invoice.payment_failed` - Handle failed payments

### B. Automatic Tier Validation

In webhook handlers (both Stripe and PayPal), call `SubscriptionTier::GetUserTier()` to trigger validation:
```php
case 'customer.subscription.updated':
    $subscription_id = $event->data->object->id;

    // Find user by Stripe subscription ID
    $order_items = new MultiOrderItem(['odi_stripe_subscription_id' => $subscription_id]);
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

Create a proper data model for webhook logging that replaces the inline `paypal_webhook_events` table and provides unified logging for all webhook providers.

**Data model:** `WebhookLog` / `MultiWebhookLog` in `/data/webhook_logs_class.php`

```php
class WebhookLog extends SystemBase {
    public static $prefix = 'wbh';
    public static $tablename = 'wbh_webhook_logs';
    public static $pkey_column = 'wbh_webhook_log_id';

    public static $field_specifications = array(
        'wbh_provider'      => array('type' => 'varchar(50)', 'is_nullable' => false),  // 'stripe', 'paypal'
        'wbh_event_type'    => array('type' => 'varchar(100)', 'is_nullable' => false), // e.g. 'checkout.session.completed', 'BILLING.SUBSCRIPTION.CANCELLED'
        'wbh_event_id'      => array('type' => 'varchar(255)', 'is_nullable' => true),  // provider's event ID, used for idempotency
        'wbh_payload'       => array('type' => 'jsonb', 'is_nullable' => true),         // full webhook payload
        'wbh_processed'     => array('type' => 'bool', 'is_nullable' => false),
        'wbh_error_message' => array('type' => 'text', 'is_nullable' => true),
    );
}
```

**Idempotency:** The model replaces the current inline `paypal_webhook_events` table. Both Stripe and PayPal handlers should check for duplicate `wbh_event_id` before processing using `WebhookLog::GetByColumn('wbh_event_id', $event_id)`.

**Migration steps:**
1. Create `WebhookLog` / `MultiWebhookLog` data model class
2. Update `/ajax/paypal_subscription_webhook.php` to use `WebhookLog` for both logging and idempotency (remove inline `CREATE TABLE IF NOT EXISTS` and all direct queries to `paypal_webhook_events`)
3. Update `/ajax/stripe_webhook.php` to log all incoming events via `WebhookLog`
4. Drop the `paypal_webhook_events` table (add a data migration in `/migrations/migrations.php`)

---

## 3. PayPal Subscription Lifecycle Completion

**Goal:** Complete PayPal subscription management to match Stripe capabilities

**Priority:** MEDIUM (most PayPal lifecycle items are now implemented; remaining work is UI gating)

**Current State:**
- ✅ `PaypalHelper` class exists with `createPlan()`, `subDetails()`, `cancel_subscription()`, `activate_subscription()`, `suspend_subscription()`, subscription button rendering
- ✅ Cart detects subscription items and creates PayPal plans before checkout
- ✅ `ord_payment_method` field tracks provider ('paypal', 'venmo', 'card', 'stripe')
- ✅ `odi_is_subscription`, `odi_subscription_status`, `odi_subscription_period_end` fields exist (generic)
- ✅ `odi_paypal_subscription_id` field exists (varchar(64) in OrderItem)
- ✅ `cart_charge_logic.php` captures and persists PayPal subscription IDs at checkout
- ✅ PayPal webhook handler exists (`/ajax/paypal_subscription_webhook.php`) with 7 event types, signature verification, and idempotency tracking
- ✅ `change_tier_logic.php` routes cancel and reactivate actions to PayPal when detected
- ❌ Upgrade/downgrade in `change_tier_logic.php` only checks for `odi_stripe_subscription_id` -- PayPal subscribers get a generic error
- ❌ No provider-aware UI gating -- PayPal subscribers see upgrade/downgrade buttons that will fail

### ~~A. Store PayPal Subscription IDs~~ DONE

~~Add field to OrderItem: `odi_paypal_subscription_id`~~
Field exists. `cart_charge_logic.php` captures it at checkout.

### ~~B. PayPal Webhook Handler~~ DONE

~~Create `/ajax/paypal_webhook.php`~~
Implemented at `/ajax/paypal_subscription_webhook.php`. Handles: ACTIVATED, CANCELLED, SUSPENDED, EXPIRED, RE-ACTIVATED, RENEWED, PAYMENT.FAILED. Includes signature verification and idempotency tracking.

### C. Provider-Aware Upgrade/Downgrade Gating

**NOT IMPLEMENTED.** This is the main remaining PayPal work. Cancel and reactivate already route to PayPal correctly.

PayPal does not support mid-cycle plan changes. The UI should hide upgrade/downgrade for PayPal subscribers, and the logic should return a clean error as a server-side safety net.

**Detection (in `change_tier_logic.php`):**
- Load the Order associated with the current subscription's OrderItem
- Check `ord_payment_method` -- values `'paypal'` or `'venmo'` indicate PayPal provider
- Pass `$is_paypal` flag into `$page_vars` for the view

**View changes (`change-tier.php`):**
- When `$is_paypal` is true, hide upgrade/downgrade buttons and show messaging: "To change your plan, cancel your current subscription and subscribe to a new tier"
- Cancel button remains available (already routed to PayPal cancellation)

**Logic fallback (`change_tier_logic.php`):**
- If upgrade or downgrade action is submitted for a PayPal subscriber, return a user-friendly error instead of the current generic "not linked to payment processor" error

### E. PayPal Limitations Reference

- PayPal does not support mid-cycle plan changes (upgrade/downgrade) -- user must cancel and re-subscribe
- Proration is not natively supported -- handle manually if needed
- Reactivation is implemented and working via `PaypalHelper::activate_subscription()`

---

## Implementation Priority Summary

### High Priority
1. **Email notifications** - Critical for user communication
2. **Enhanced Stripe webhook processing** - Stripe only handles checkout.session.completed; needs subscription lifecycle events (PayPal webhooks are complete)

### Medium Priority
3. **PayPal UI gating** - PayPal lifecycle is mostly complete; remaining work is provider-aware UI to prevent PayPal users from hitting upgrade/downgrade errors
4. **Webhook logging model** - Unified WebhookLog data model for both Stripe and PayPal webhook events, replacing inline `paypal_webhook_events` table
5. **admin_subscription_tier_members.php** - Referenced by links in admin UI but page does not exist

---

## Notes

- All enhancements can be implemented incrementally
- Each feature can be deployed independently
- Email notifications and Stripe webhook events are most important for production readiness
- PayPal subscription lifecycle is largely complete -- remaining work is UI gating to prevent errors when PayPal users try upgrade/downgrade

**Last reviewed:** 2026-03-26

---

## Related Specifications

**Implemented (see `/specs/implemented/`):**
- `subscription_tiers_phase1.md` - Core tier system
- `subscription_tiers_phase2.md` - User-facing management
- `subscription_tiers_controld_migration.md` - Plugin migration
- `subscription_expiration_implementation.md` - Automatic expiration
