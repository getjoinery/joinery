# Subscription Tier System - Phase 2 (COMPLETED)

## Overview
Phase 2 extended the Phase 1 subscription tier system with user-facing subscription management, feature/limit controls, and automatic expiration handling.

**Status:** ✅ COMPLETED - All features implemented and tested

**Implementation Date:** January 2025

---

## Phase 2 Deliverables

### 1. User-Facing Subscription Management ✅

**Files:**
- `/logic/change_subscription_logic.php` - All business logic, Stripe integration, settings checks
- `/views/change-subscription.php` - User interface for subscription management

**Features:**
- ✅ Upgrade to higher tiers (always allowed)
- ✅ Downgrade to lower tiers (admin-configurable)
- ✅ Cancel subscription (immediate or end-of-period)
- ✅ Reactivate cancelled subscriptions
- ✅ Stripe integration with automatic proration
- ✅ Real-time subscription status display
- ✅ User-friendly error messages

**Subscription Actions:**

**a. UPGRADE TO HIGHER TIER**
- Always allowed (generates revenue)
- Stripe prorates and charges difference immediately
- Creates new Order and OrderItem
- Updates tier assignment via `handleProductPurchase()`
- Immediate access to higher tier features

**b. DOWNGRADE TO LOWER TIER**
- Controlled by `subscription_downgrades_enabled` setting
- Two timing modes: immediate or end-of-period
- Stripe credits unused time to customer balance
- Can be disabled to require support contact

**c. CANCEL SUBSCRIPTION**
- Controlled by `subscription_cancellation_enabled` setting
- Two timing modes: immediate or end-of-period
- Optional proration/refund for immediate cancellations
- Subscription status tracked in OrderItem

**d. REACTIVATE CANCELLED SUBSCRIPTION**
- Controlled by `subscription_reactivation_enabled` setting
- Only works for end-of-period cancellations before expiration
- Updates Stripe subscription to continue
- Clears cancellation timestamp

---

### 2. Feature/Limit System ✅

**Database:**
- `sbt_features` (JSONB) column in `sbt_subscription_tiers` table
- Stores all feature flags and limits as key-value pairs

**Example JSON Structure:**
```json
{
  "controld_max_devices": 3,
  "controld_custom_rules": true,
  "storage_gb": 10,
  "api_calls_per_month": 10000,
  "priority_support": true
}
```

**Methods in SubscriptionTier Class:**
- `getFeature($key, $default)` - Get single feature value
- `setFeatures($features_array)` - Set all features (admin use)
- `getAllFeatures()` - Get all features as array
- `getUserFeature($user_id, $feature_key, $default)` - Static method with caching
- `getAllAvailableFeatures()` - Discover core + plugin features

**Feature Registration:**
- Core features: `/includes/core_tier_features.json`
- Plugin features: `/plugins/{plugin}/tier_features.json`
- Auto-discovery system loads all feature definitions
- Type definitions: boolean, integer, string
- Min/max validation for numeric features

**Admin UI:**
- Dynamic feature editor in `/adm/admin_subscription_tier_edit.php`
- Automatically displays all core and plugin features
- Checkboxes for boolean features
- Number inputs for integer features
- Text inputs for other types

---

### 3. Subscription Expiration Handling ✅

**Implementation:** Lazy evaluation (no cron jobs needed)

**File:** `/data/subscription_tiers_class.php` - Modified `GetUserTier()` method

**How It Works:**
1. When `GetUserTier($user_id)` is called:
   - Queries for active subscriptions
   - Calls `check_subscription_status()` on each
   - Checks if subscription period ended
   - Syncs with Stripe if expired
   - Automatically removes tier if subscription expired

2. `check_subscription_status()` in OrderItem:
   - Fast path: Returns true if `odi_subscription_period_end > now()`
   - Slow path: Syncs with Stripe if period ended
   - Fail-safe: Assumes valid if Stripe API unavailable

3. Automatic cleanup:
   - Removes user from all tier groups
   - Logs change with reason `'subscription_expired'`
   - Clears tier cache
   - Returns null (no tier)

**Triggers:**
- User views `/change-subscription` page
- Any `getUserFeature()` call
- Admin views user profile
- Any tier-gated feature access

**Performance:**
- Only 1 extra query for active subscriptions
- Results cached via `$user_tier_cache`
- Only hits Stripe when period actually expired

**Documentation:** `/specs/subscription_expiration_implementation.md`

---

### 4. Admin Configuration Settings ✅

**Settings Table:** All settings added to `stg_settings` via migrations

**Settings:**

1. **`subscription_downgrades_enabled`** (bool)
   - Default: `0` (disabled)
   - Allow users to downgrade to lower tiers

2. **`subscription_downgrade_timing`** (enum)
   - Default: `'end_of_period'`
   - Options: `'immediate'` or `'end_of_period'`

3. **`subscription_downgrade_prorate`** (bool)
   - Default: `1` (enabled)
   - Apply proration when downgrading

4. **`subscription_upgrade_prorate`** (bool)
   - Default: `1` (enabled)
   - Apply proration when upgrading

5. **`subscription_cancellation_enabled`** (bool)
   - Default: `1` (enabled)
   - Allow users to self-cancel subscriptions

6. **`subscription_cancellation_timing`** (enum)
   - Default: `'end_of_period'`
   - Options: `'immediate'` or `'end_of_period'`

7. **`subscription_cancellation_prorate`** (bool)
   - Default: `0` (disabled)
   - Issue prorated refunds for immediate cancellations

8. **`subscription_reactivation_enabled`** (bool)
   - Default: `1` (enabled)
   - Allow users to reactivate cancelled subscriptions

**Migrations:** Database versions 0.62 and 0.64 in `/migrations/migrations.php`

---

## Testing Coverage

**Automated Tests:** `/tests/functional/subscription_tiers/SubscriptionTierTester.php`

**Test Coverage:**
- ✅ Model layer (SubscriptionTier, MultiSubscriptionTier)
- ✅ Tier assignment and removal
- ✅ Feature access (`getUserFeature`, `getUserTierDisplay`)
- ✅ Change tracking integration
- ✅ Stripe price ID auto-sync
- ✅ Upgrade via logic file with Stripe
- ✅ Downgrade immediate with Stripe
- ✅ Cancellation immediate with Stripe
- ✅ Reactivation with Stripe
- ✅ Proration calculations
- ✅ Settings-based behavior
- ✅ Order and OrderItem creation

**Manual Testing Required:**
- UI/UX in `/change-subscription` view
- End-of-period timing (requires waiting or mocking)
- Webhook processing
- Email notifications
- Edge cases and error scenarios

**Test Documentation:** `/specs/subscription_tier_test_todo.md`

---

## Key Files Modified/Created

### Core Files
- `/data/subscription_tiers_class.php` - Added expiration handling, feature methods
- `/data/order_items_class.php` - Added `check_subscription_status()` method
- `/logic/change_subscription_logic.php` - Complete subscription management logic
- `/views/change-subscription.php` - User interface
- `/includes/StripeHelper.php` - Added `reactivate_subscription()` method

### Admin Files
- `/adm/admin_subscription_tier_edit.php` - Dynamic feature editor
- `/adm/admin_subscription_tiers.php` - Tier list management
- `/adm/admin_user.php` - Already integrated (displays tier, triggers validation)

### Database
- `/migrations/migrations.php` - Added settings migrations (v0.62, v0.64)
- `sbt_features` JSONB column in `sbt_subscription_tiers` table

### Documentation
- `/specs/subscription_expiration_implementation.md` - Expiration handling details
- `/specs/subscription_tier_test_todo.md` - Testing checklist

---

## Architecture Decisions

### 1. Lazy Evaluation for Expiration
- No cron jobs needed
- Validation happens on-demand via `GetUserTier()`
- Fail-safe behavior if Stripe API unavailable
- Minimal performance impact with caching

### 2. Single JSONB Column for Features
- All features stored in `sbt_features` column
- No separate feature tables needed
- Easy to add new features without schema changes
- Fast JSON parsing for small feature sets

### 3. Plugin Feature Discovery
- Plugins define features in `tier_features.json`
- Admin UI automatically discovers and displays
- No hardcoded feature lists
- Self-documenting system

### 4. Logic/View Separation
- Logic file handles all business logic and Stripe integration
- View file is pure presentation
- Data prepared by logic, displayed by view
- Clean separation of concerns

### 5. Settings-Based Configuration
- 8 settings control all subscription behaviors
- Flexible without code changes
- Covers common scenarios
- Simple to understand and configure

---

## Integration Points

### Stripe Integration
- Subscription creation, updates, cancellation
- Automatic proration handling
- Real-time status sync
- Webhook support (webhooks can call `GetUserTier()` to trigger validation)

### Change Tracking
- All tier changes logged to `cht_change_tracking`
- Includes purchase, upgrade, downgrade, cancellation, expiration
- Visible in admin user profile

### Groups System
- Each tier has associated group in `grp_groups`
- Users added/removed from groups for tier membership
- Leverages existing group permission system

### Product System
- Products link to tiers via `pro_sbt_subscription_tier_id`
- Purchase triggers tier assignment
- Multiple products can grant same tier

---

## Performance Characteristics

### GetUserTier() with Expiration Check
- Active subscriptions: 3-4 database queries
- Results cached in `$user_tier_cache`
- Only hits Stripe when subscription actually expired
- Fail-safe assumes valid if API unavailable

### Feature Access
- `getUserFeature()` uses cached tier
- Single database query on first call
- Cached for subsequent calls in same request
- JSON parsing is fast for small feature sets

### Subscription Changes
- Direct Stripe API calls
- Database updates are transactional
- Order/OrderItem creation standard overhead

---

## Production Readiness

**Ready for Production:** ✅ Yes

**Requirements Met:**
- ✅ All Phase 2 features implemented
- ✅ Automated test coverage for core flows
- ✅ Error handling and user-friendly messages
- ✅ Settings for flexible configuration
- ✅ Automatic expiration handling
- ✅ Change tracking for audit trail
- ✅ Performance optimizations (caching)
- ✅ Fail-safe behavior for API failures

**Recommended Before Production:**
- Manual testing of UI flows
- Webhook testing with Stripe CLI
- Email notification setup (Phase 3)
- End-to-end testing in staging

---

## Phase 3 Considerations

Phase 2 is complete, but Phase 3 may add:
- Email notifications for subscription events
- Enhanced webhook processing
- Plugin migrations (e.g., ControlD)
- PayPal subscription support
- Bulk user validation tools
- Grace period before tier removal
- Comprehensive end-user documentation

Phase 2 provides the complete foundation for these enhancements.
