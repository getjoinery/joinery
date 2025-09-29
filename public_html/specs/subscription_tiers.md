# Subscription Tier System Specification

## Overview
Add Patreon-style subscription tiers to the system that automatically integrate with the checkout process and use the existing grp_groups infrastructure for user associations.

## Goals
1. Enable tiered subscription offerings (Free, Basic, Premium, Pro, etc.)
2. Automatic tier assignment upon product purchase (no product scripts required)
3. Leverage existing grp_groups system for user-tier associations
4. Support tier-based feature access control
5. Allow easy tier upgrades/downgrades
6. Provide clear tier management interface for admins

## Current System Analysis

### Existing Infrastructure
- **Products**: `pro_products` table has `pro_grp_group_id` field for group associations
- **Groups**: `grp_groups` table with `grp_category` field for categorization
- **Group Members**: `grm_group_members` links users to groups
- **Checkout Flow**: `cart_charge_logic.php` already handles group associations for event bundles
- **Product Scripts**: Current controld implementation uses product scripts (we want to eliminate this requirement)

### Current Limitations
- No built-in tier hierarchy or progression
- Product scripts required for custom behavior
- No tier-specific feature/limit management
- No automatic tier switching on new purchases

## Proposed Solution

### 1. Database Structure

#### Core Tables (Phase 1)
- Use `grp_groups` with `grp_category = 'subscription_tier'` to identify tier groups
- `sbt_subscription_tiers` - Main tier metadata table
  - `sbt_subscription_tier_id` (primary key)
  - `sbt_grp_group_id` (references grp_groups)
  - `sbt_tier_level` (integer for hierarchy)
  - `sbt_name`, `sbt_display_name`, `sbt_description`
  - `sbt_badge_color`, `sbt_is_active`
  - Standard timestamp fields

- `cht_change_tracking` - General-purpose audit trail for any entity changes
  - `cht_change_tracking_id` (primary key)
  - `cht_entity_type` (varchar: 'subscription_tier', 'user_status', 'membership', etc.)
  - `cht_entity_id` (bigint: ID of the entity that changed)
  - `cht_usr_user_id` (user affected by the change, if applicable)
  - `cht_field_name` (varchar: what field/attribute changed, e.g., 'tier_level')
  - `cht_old_value` (text: previous value, can be NULL for new records)
  - `cht_new_value` (text: new value, can be NULL for deletions)
  - `cht_change_time` (timestamp)
  - `cht_change_reason` (varchar: 'purchase', 'admin', 'system', 'expired', etc.)
  - `cht_reference_type` (varchar: 'order', 'admin_action', etc.)
  - `cht_reference_id` (bigint: ID of related record, e.g., order_id)
  - `cht_changed_by_usr_user_id` (user who made the change, if applicable)
  - `cht_metadata` (text: optional JSON for additional context)

#### Future Tables (Phase 2 - Out of Scope)
- `sbt_tier_features` - Feature flags per tier
- `sbt_tier_limits` - Resource limits per tier

#### Product Integration
- Add new column to `pro_products`: `pro_sbt_subscription_tier_id` (references sbt_subscription_tiers)
- Keep `pro_grp_group_id` exclusively for event bundles
- Products (not product versions) determine tier assignment
- All versions of a product grant the same subscription tier

### 2. Tier Management

#### Tier Hierarchy
- Each tier has a numeric level (higher = more access)
- Example: Free=10, Basic=20, Premium=30, Pro=40, Enterprise=50
- Display properties (badge color, description) in main tier table

#### Phase 2 Features (Out of Scope)
- Feature flags and resource limits will be added in Phase 2

### 3. Automatic Checkout Integration

#### Purchase Flow
1. User purchases product with `pro_sbt_subscription_tier_id` set
2. Checkout process checks for subscription tier ID
3. If subscription tier is set:
   - Remove user from all other subscription tier groups
   - Add user to new tier's group
   - Create record in `cht_change_tracking`:
     - entity_type: 'subscription_tier'
     - entity_id: new tier ID
     - usr_user_id: user's ID
     - field_name: 'tier_level'
     - old_value: previous tier level (or NULL)
     - new_value: new tier level
     - change_reason: 'purchase'
     - reference_type: 'order'
     - reference_id: order ID
4. Product's `pro_grp_group_id` remains separate for event bundles
5. Both can coexist - a product could theoretically grant a tier AND be an event bundle

#### Subscription Types Supported
This system works with ALL product types:
- **One-time/Lifetime purchases** - User buys "Lifetime Premium" for $500, gets Premium tier forever
- **Recurring subscriptions** - User pays $20/month for Premium tier (handled by Stripe)
- **Limited-time access** - User buys "1 Year Premium" for $200 (would need expiration handling in Phase 2)

The tier assignment happens at purchase regardless of payment type. The product's payment structure (one-time vs recurring) is handled by existing product_versions settings.

#### Key Integration Point
Modify `cart_charge_logic.php` to add tier handling:
1. Check for `pro_sbt_subscription_tier_id` before the existing event bundle logic
2. Handle tier switching automatically if set
3. Continue with existing `pro_grp_group_id` logic for event bundles
4. Both features remain independent and can coexist

### 4. Change Tracking System

#### General Purpose Design
The `cht_change_tracking` table can track any entity changes across the system:

**Example Uses:**
```php
// Track subscription tier change
ChangeTracker::logChange(
    'subscription_tier',     // entity_type
    $new_tier_id,           // entity_id
    $user_id,               // usr_user_id
    'tier_level',           // field_name
    $old_tier_level,        // old_value
    $new_tier_level,        // new_value
    'purchase',             // change_reason
    'order',                // reference_type
    $order_id               // reference_id
);

// Track user status change
ChangeTracker::logChange(
    'user',
    $user_id,
    $user_id,
    'status',
    'active',
    'suspended',
    'admin',
    'admin_action',
    $admin_user_id
);

// Track any future entity changes
ChangeTracker::logChange(
    'product',
    $product_id,
    null,  // not user-specific
    'price',
    '99.99',
    '149.99',
    'admin',
    'admin_action',
    $admin_user_id
);
```

**Benefits:**
- One table for all audit needs
- Consistent tracking across all entities
- Can query all changes for a user across all entity types
- Can track who made changes and why
- Flexible enough for future use cases

### 5. Access Control (Phase 1 - Basic)

#### Tier Level Checking
```php
// Check minimum tier level
if (UserTierHelper::hasMinimumTier($user_id, 30)) { // Premium or higher
    // Allow premium features
}

// Get user's current tier
$tier = UserTierHelper::getUserTier($user_id);
if ($tier && $tier->get('sbt_tier_level') >= 40) { // Pro or higher
    // Show pro features
}
```

#### Phase 2 Access Control (Out of Scope)
- Feature-specific checking
- Resource limits and quotas

### 6. User Interface

#### Admin Interface
- `/adm/admin_subscription_tiers.php` - Manage tiers
- List all subscription tiers with level, features, limits
- Create/edit tier groups with subscription_tier category
- Assign products to tiers

#### User Interface
- Display current tier in profile (`/profile`)
- Show available upgrade options
- Tier comparison page
- Visual tier badges throughout the system

### 7. Implementation Phases

#### Phase 1: Core Implementation (✅ COMPLETED - September 28, 2025)
**See `/specs/implemented/subscription_tiers_phase1.md` for full Phase 1 implementation details.**

Phase 1 successfully implemented:
- Core database structure and models
- Automatic tier assignment at checkout
- Admin interface for tier management
- User tier display and upgrade options
- Change tracking system

#### Phase 2: Advanced Features (Current Specification)
1. Add admin menu item for `/adm/admin_subscription_tiers.php`
2. Feature flags system (`sbt_tier_features`)
3. Resource limits system (`sbt_tier_limits`)
4. Advanced access control helpers
5. Analytics and reporting
6. **User-Facing Subscription Management**:
   - Complete implementation of subscription change logic for user-facing pages
   - All subscription actions handled through `/logic/change_subscription_logic.php`

   **User Subscription Actions & Handling:**

   a. **UPGRADE TO HIGHER TIER**
      - User with active subscription switches to more expensive plan
      - Stripe: Use `change_subscription()` to update subscription item to new price ID
      - Stripe prorates charges automatically (charges difference immediately)
      - Database: Mark old OrderItem with `odi_subscription_cancelled_time = now()`
      - Create new Order with STATUS_PAID
      - Create new OrderItem with new subscription details
      - Run product scripts (e.g., `controld_subscription_product_script`)
      - Update SubscriptionTier via `handleProductPurchase()`
      - Update CtldAccount plan levels if applicable

   b. **DOWNGRADE TO LOWER TIER**
      - User switches to less expensive plan
      - Stripe: Use `change_subscription()` to update subscription item
      - Stripe credits the difference at next billing cycle
      - Consider option: immediate vs. end-of-period downgrade
      - Database: Same as upgrade process
      - Note: SubscriptionTier::addUser() prevents downgrades via 'purchase' reason
      - Need to handle tier downgrade separately (manual reassignment)

   c. **CANCEL SUBSCRIPTION**
      - User cancels recurring billing
      - Cancel Types: Immediate (lose access now) or Period End (retain until billing cycle end)
      - Stripe: `cancel_subscription($subscription_id, 'immediate')` or `'period_end'`
      - Updates subscription status to 'canceled'
      - Database: Set `odi_subscription_cancelled_time`
      - Update `odi_subscription_status` to 'canceled'
      - Send notification emails if configured
      - CtldAccount deactivation (set `cda_is_active = false` for immediate)

   d. **REACTIVATE CANCELLED SUBSCRIPTION**
      - User with cancelled (but not expired) subscription wants to reactivate
      - Stripe: If cancelled but not expired: `update()` with `cancel_at_period_end = false`
      - If fully cancelled: Create new subscription
      - Database: Clear `odi_subscription_cancelled_time`
      - Update `odi_subscription_status` to 'active'
      - Reactivate CtldAccount if needed

   e. **SWITCH BILLING CYCLE**
      - Change from monthly to annual or vice versa
      - Stripe: Use `change_subscription()` with new price ID for different interval
      - Prorating applies
      - Database: Same as tier change, update ProductVersion reference if different

   f. **PAUSE SUBSCRIPTION**
      - Temporarily suspend billing (if supported)
      - Stripe: Use subscription schedules or pause collection feature
      - Set `pause_collection` behavior
      - Database: Track pause state in OrderItem
      - Update CtldAccount access accordingly

   g. **APPLY COUPON/DISCOUNT**
      - Add promotional code to existing subscription
      - Stripe: Apply coupon to subscription via `update()`
      - Discount applies to future invoices
      - Database: Track coupon application in OrderItem, store discount details

   h. **UPDATE PAYMENT METHOD**
      - Change credit card on file
      - Stripe: Update customer's default payment method
      - No subscription modification needed
      - Database: No database changes required (handled by Stripe)

   **Key Implementation Considerations:**
   - **Proration Handling:** Upgrades charge immediately, downgrades credit at next billing
   - **Permission Checks:** Verify user owns subscription via `authenticate_write()`
   - **Tier Management Rules:** Purchases only allow upgrades (not downgrades)
   - **Product Script Execution:** Run appropriate scripts for new product
   - **Error Handling:** Stripe API failures, concurrent modifications, invalid transitions
   - **Notification Flow:** Customer confirmations, admin notifications, webhook handling

   **Admin-Configurable Settings for Subscription Management:**

   The following 5 essential settings control subscription change behaviors:

   1. **`subscription_downgrades_enabled`** (bool): Allow users to downgrade to lower tiers
      - Default: `false` (only upgrades allowed)

   2. **`subscription_downgrade_timing`** (enum): When downgrades take effect
      - Options: `'immediate'` or `'end_of_period'`
      - Default: `'end_of_period'`

   3. **`subscription_cancellation_enabled`** (bool): Allow users to self-cancel subscriptions
      - Default: `true`

   4. **`subscription_cancellation_timing`** (enum): When cancellations take effect
      - Options: `'immediate'` or `'end_of_period'`
      - Default: `'end_of_period'`

   5. **`subscription_reactivation_enabled`** (bool): Allow users to reactivate cancelled subscriptions
      - Default: `true`

   6. **`subscription_cancellation_prorate`** (bool): Issue prorated refunds for immediate cancellations
      - Default: `false` (no refunds on cancellation)
      - Only applies when `subscription_cancellation_timing` is `'immediate'`

   These core settings cover the most common subscription management scenarios while keeping configuration simple.

7. **Tier Switching Behaviors**:
   - Downgrade handling and policies
   - Multiple tier support (keeping highest tier)
   - Grace periods for downgrades
   - Refund handling and tier removal
   - Manual admin tier changes
   - Email notifications for tier changes
8. **Recurring Subscription Integration**:
   - Automatic tier removal on subscription expiration
   - Stripe webhook handling for subscription events
   - Trial period support with temporary tier access
   - Subscription status checks before tier access
9. **ControlD Plugin Migration**:
   - **Current Implementation Analysis**:
     - ControlD uses hardcoded product IDs (19=Basic, 20=Premium, 21=Pro)
     - Stores plan level in `cda_ctldaccounts` table (cda_plan: 1=Basic, 2=Premium, 3=Pro)
     - Uses `controld_subscription_product_script` hook for product purchases
     - Access control checks `cda_plan` directly (e.g., Premium/Pro for advanced filters, Pro for custom rules)
     - Device limits enforced via `cda_plan_max_devices` (Basic=1, Premium=3, Pro=10)

   - **Migration Tasks**:
     a. Create subscription tiers matching ControlD plans:
        - Basic ControlD (Level 10): 1 device limit
        - Premium ControlD (Level 20): 3 device limit, advanced filters
        - Pro ControlD (Level 30): 10 devices, custom rules, all features

     b. Update products to use tier system:
        - Modify products 19, 20, 21 to set `pro_sbt_subscription_tier_id`
        - Remove `controld_subscription_product_script` from product scripts

     c. Migrate existing ControlD accounts:
        - Query all `cda_ctldaccounts` records
        - Assign users to appropriate tiers based on `cda_plan`
        - Maintain `cda_ctldaccounts` for device tracking but deprecate plan fields

     d. Refactor access control in ControlD plugin:
        - Replace `$account->get('cda_plan') == CtldAccount::PREMIUM_PLAN` checks
        - Use `SubscriptionTier::UserHasMinimumTier($user_id, 20)` instead
        - Update device limit checks to use tier metadata

     e. Update ControlD views and logic:
        - `/views/profile/ctldfilters_edit.php`: Check tier level >= 20 for premium features
        - `/views/profile/rules.php`: Check tier level >= 30 for pro features
        - `/views/profile/devices.php`: Get device limits from tier metadata
        - `/logic/devices_logic.php`: Use tier system for plan checks

     f. Add tier metadata for feature flags:
        - Store device limits in tier metadata
        - Store feature flags (advanced_filters, custom_rules) in tier metadata
        - Create helper methods in ControlDHelper for tier-based checks


## Benefits of This Approach

1. **No Product Scripts Required**: Tier assignment happens automatically in checkout
2. **Leverages Existing Systems**: Uses grp_groups infrastructure already in place
3. **Flexible**: Can support multiple tier types via group categories
4. **Scalable**: Easy to add new tiers and features
5. **Clean Integration**: Minimal changes to existing checkout flow


## Next Steps

1. Review and approve this specification with code
2. Answer implementation decision questions
3. Implement Phase 1 (Core Implementation)
4. Test with sample tiers and products
5. Deploy to production
6. Plan Phase 2 features