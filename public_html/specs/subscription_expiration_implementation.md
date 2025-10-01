# Subscription Expiration Handling - Implementation Summary

## Overview
Implemented automatic subscription expiration checking with tier removal using lazy evaluation (no cron jobs needed).

## Implementation Details

### What Was Changed

**File: `/data/subscription_tiers_class.php`**
- Modified `SubscriptionTier::GetUserTier()` method (lines 134-195)
- Added automatic subscription status validation
- Added automatic tier removal for expired subscriptions
- Added change tracking for removals

### How It Works

1. **Lazy Evaluation**: When `GetUserTier($user_id)` is called, it:
   - Queries for active subscriptions (`odi_is_subscription = TRUE AND odi_subscription_cancelled_time IS NULL`)
   - Calls `check_subscription_status()` on each subscription
   - Checks if subscription period has ended
   - Syncs with Stripe if period has passed
   - Finds user's current tier from groups
   - If user has tier but no active subscription → automatically removes tier

2. **Subscription Status Check** (already existed in OrderItem):
   - Fast path: If `odi_subscription_period_end > now()`, returns true immediately
   - Slow path: If expired, syncs with Stripe API to get current status
   - Fail-safe: If Stripe API fails, assumes subscription is still valid (prevents accidental removal)

3. **Automatic Cleanup**:
   - Removes user from all subscription tier groups
   - Logs change to `cht_change_tracking` table with reason `'subscription_expired'`
   - Clears tier cache for the user
   - Returns `null` (no tier)

### When Validation Occurs

Automatic validation happens whenever `GetUserTier()` is called:
- ✅ User views `/change-subscription` page
- ✅ Any feature check via `getUserFeature()`
- ✅ Admin views user profile at `/admin/admin_user`
- ✅ User profile pages that display tier
- ✅ Any tier-gated feature access

### Performance Impact

**For Active (Non-Expired) Subscriptions:**
- Added cost: 1 extra database query to `odi_order_items` table
- Query is simple: `WHERE odi_usr_user_id = ? AND odi_is_subscription = TRUE AND odi_subscription_cancelled_time IS NULL`
- Total cost: 3-4 queries per `GetUserTier()` call (already doing 2-3 queries)
- **Results are cached** via `$user_tier_cache` (line 32), so only happens once per user per request

**For Expired Subscriptions (one-time cost):**
- Stripe API call to sync subscription status
- Delete queries to remove from groups
- Insert into change tracking

### Fail-Safe Behavior

If Stripe API is unavailable:
- Subscription check returns `true` (assume valid)
- User keeps tier access
- Error logged to error.log
- Prevents accidental tier removal due to temporary API issues

### Change Tracking

All automatic removals are logged:
```php
ChangeTracking::logChange(
    'subscription_tier',      // entity_type
    null,                     // entity_id (null because tier was removed)
    $user_id,                 // user_id
    'tier_removed',           // field_name
    $tier_level,              // old_value
    null,                     // new_value (null = no tier)
    'subscription_expired'    // reason
);
```

Visible in admin user page under "Tier Change History"

## Testing

To test expiration handling:
1. Create a test subscription with Stripe
2. Use Stripe dashboard to set subscription to `past_due` or `canceled`
3. Update `odi_subscription_period_end` in database to past date
4. Visit user's profile or call `GetUserTier()` for that user
5. Verify tier is automatically removed and logged

## What This Covers

✅ Subscriptions that expire naturally (period ends)
✅ Subscriptions cancelled and period has ended
✅ Subscriptions with failed payments (Stripe sets to `past_due` or `canceled`)
✅ Subscriptions manually cancelled by admin in Stripe
✅ Multiple subscriptions per user (checks all active ones)

## What This Doesn't Cover

These remain manual processes:
- ❌ Proactive expiration notifications (need separate notification system)
- ❌ Webhook-based instant updates (webhooks could call `GetUserTier()` to trigger cleanup)
- ❌ Bulk cleanup of all expired users (could create admin tool that loops through users)

## Related Files

- `/data/subscription_tiers_class.php` - Main implementation
- `/data/order_items_class.php` - `check_subscription_status()` method (already existed)
- `/includes/StripeHelper.php` - `update_subscription_in_order_item()` method
- `/logic/change_subscription_logic.php` - Calls `GetUserTier()` and displays status
- `/adm/admin_user.php` - Displays tier and triggers validation
- `/data/change_tracking_class.php` - Logs tier removals

## Future Enhancements

Optional improvements for Phase 3+:
1. **Webhook integration**: Call `GetUserTier()` when Stripe sends subscription.updated webhook
2. **Admin bulk validation**: Admin page to validate all users at once
3. **Expiration notifications**: Email users before/after expiration
4. **Grace period**: Allow X days of access after expiration before removal
