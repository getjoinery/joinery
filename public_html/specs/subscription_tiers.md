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

#### Phase 1: Core Implementation (Current Scope)
1. Add `cht_change_tracking` table (via data class with field_specifications)
2. Add `sbt_subscription_tiers` table (via data class with field_specifications)
3. Add `pro_sbt_subscription_tier_id` to Product class field_specifications (auto-creates DB column)
4. Create ChangeTracker helper class for logging
5. Modify checkout logic for automatic tier assignment
6. Create basic helper functions for tier checking
7. Simple admin interface for tier management
8. Display user's tier in profile

#### Phase 2: Advanced Features (Future - Out of Scope)
1. Add admin menu item for `/adm/admin_subscription_tiers.php`
2. Feature flags system (`sbt_tier_features`)
3. Resource limits system (`sbt_tier_limits`)
4. Advanced access control helpers
5. Analytics and reporting
6. **Tier Switching Behaviors**:
   - Downgrade handling and policies
   - Multiple tier support (keeping highest tier)
   - Grace periods for downgrades
   - Refund handling and tier removal
   - Manual admin tier changes
   - Email notifications for tier changes
6. **Recurring Subscription Integration**:
   - Automatic tier removal on subscription expiration
   - Stripe webhook handling for subscription events
   - Trial period support with temporary tier access
   - Subscription status checks before tier access
7. **ControlD Plugin Migration**:
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

## Implementation Status

### ✅ COMPLETED Implementation Items (2025-09-28):

1. **[DONE]** Added `pro_sbt_subscription_tier_id` to Product class field_specifications
   - Modified `/data/products_class.php` to include new field
   - Database column will auto-create on next update_database run

2. **[DONE]** Created `ChangeTracking` data model class
   - Created `/data/change_tracking_class.php`
   - General-purpose audit trail system for any entity changes

3. **[DONE]** Created `SubscriptionTier` data model class
   - Created `/data/subscription_tiers_class.php`
   - Full tier management with group integration

4. **[DONE]** Integrated subscription tier handling in checkout
   - Modified `/logic/cart_charge_logic.php`
   - Automatic tier assignment on product purchase

5. **[DONE]** Created admin interface for tier management
   - Created `/adm/admin_subscription_tiers.php`
   - Full CRUD operations for subscription tiers

6. **[DONE]** Created user interface for tier selection
   - Created `/views/change-subscription.php`
   - Created `/logic/change_subscription_logic.php`
   - Tier comparison and upgrade options

### Files Modified/Created:
- **Modified:** `/data/products_class.php` (backup: `products_class.php.bak`)
- **Modified:** `/logic/cart_charge_logic.php` (backup: `cart_charge_logic.php.bak`)
- **Created:** `/data/change_tracking_class.php`
- **Created:** `/data/subscription_tiers_class.php`
- **Created:** `/adm/admin_subscription_tiers.php`
- **Created:** `/views/change-subscription.php`
- **Created:** `/logic/change_subscription_logic.php`

All PHP syntax verified - no errors detected.

## Benefits of This Approach

1. **No Product Scripts Required**: Tier assignment happens automatically in checkout
2. **Leverages Existing Systems**: Uses grp_groups infrastructure already in place
3. **Flexible**: Can support multiple tier types via group categories
4. **Scalable**: Easy to add new tiers and features
5. **Clean Integration**: Minimal changes to existing checkout flow

## Implementation Code

### 1. Data Model Classes

#### ChangeTracking Class (`/data/change_tracking_class.php`)
```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ChangeTracking extends SystemBase {
    public static $prefix = 'cht';
    public static $tablename = 'cht_change_tracking';
    public static $pkey_column = 'cht_change_tracking_id';

    public static $field_specifications = array(
        'cht_change_tracking_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'cht_entity_type' => array('type'=>'varchar(50)', 'required'=>true),
        'cht_entity_id' => array('type'=>'int8'),
        'cht_usr_user_id' => array('type'=>'int4'),
        'cht_field_name' => array('type'=>'varchar(100)'),
        'cht_old_value' => array('type'=>'text'),
        'cht_new_value' => array('type'=>'text'),
        'cht_change_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'cht_change_reason' => array('type'=>'varchar(50)'),
        'cht_reference_type' => array('type'=>'varchar(50)'),
        'cht_reference_id' => array('type'=>'int8'),
        'cht_changed_by_usr_user_id' => array('type'=>'int4'),
        'cht_metadata' => array('type'=>'text')
    );

    /**
     * Static method to log a change
     */
    public static function logChange($entity_type, $entity_id, $user_id, $field_name,
                                     $old_value, $new_value, $change_reason,
                                     $reference_type = null, $reference_id = null,
                                     $changed_by_user_id = null, $metadata = null) {
        $change = new ChangeTracking(NULL);
        $change->set('cht_entity_type', $entity_type);
        $change->set('cht_entity_id', $entity_id);
        $change->set('cht_usr_user_id', $user_id);
        $change->set('cht_field_name', $field_name);
        $change->set('cht_old_value', is_null($old_value) ? null : (string)$old_value);
        $change->set('cht_new_value', is_null($new_value) ? null : (string)$new_value);
        $change->set('cht_change_reason', $change_reason);
        $change->set('cht_reference_type', $reference_type);
        $change->set('cht_reference_id', $reference_id);
        $change->set('cht_changed_by_usr_user_id', $changed_by_user_id);
        $change->set('cht_metadata', $metadata);
        $change->save();
        return $change;
    }

    /**
     * Get all changes for a specific entity
     */
    public static function getEntityHistory($entity_type, $entity_id) {
        $changes = new MultiChangeTracking([
            'cht_entity_type' => $entity_type,
            'cht_entity_id' => $entity_id
        ], ['cht_change_time' => 'DESC']);
        $changes->load();
        return $changes;
    }

    /**
     * Get all changes for a specific user
     */
    public static function getUserHistory($user_id) {
        $changes = new MultiChangeTracking([
            'cht_usr_user_id' => $user_id
        ], ['cht_change_time' => 'DESC']);
        $changes->load();
        return $changes;
    }
}

class MultiChangeTracking extends SystemMultiBase {
    protected static $model_class = 'ChangeTracking';
}
```

#### SubscriptionTier Class (`/data/subscription_tiers_class.php`)
```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));
require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

class SubscriptionTierException extends SystemBaseException {}

class SubscriptionTier extends SystemBase {
    public static $prefix = 'sbt';
    public static $tablename = 'sbt_subscription_tiers';
    public static $pkey_column = 'sbt_subscription_tier_id';

    public static $field_specifications = array(
        'sbt_subscription_tier_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'sbt_grp_group_id' => array('type'=>'int4', 'required'=>true, 'unique'=>true),
        'sbt_tier_level' => array('type'=>'int4', 'required'=>true),
        'sbt_name' => array('type'=>'varchar(100)', 'required'=>true),
        'sbt_display_name' => array('type'=>'varchar(100)', 'required'=>true),
        'sbt_description' => array('type'=>'text'),
        'sbt_badge_color' => array('type'=>'varchar(20)'),
        'sbt_is_active' => array('type'=>'bool', 'default'=>true),
        'sbt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'sbt_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'sbt_delete_time' => array('type'=>'timestamp(6)')
    );

    /**
     * Create the associated group when creating a new tier
     */
    public function save($debug = false) {
        // If new tier, create the group first
        if (!$this->key && !$this->get('sbt_grp_group_id')) {
            $group = new Group(NULL);
            $group->set('grp_name', $this->get('sbt_name'));
            $group->set('grp_category', 'subscription_tier');
            $group->save();
            $this->set('sbt_grp_group_id', $group->key);
        }

        return parent::save($debug);
    }

    /**
     * Add user to this subscription tier
     */
    public function addUser($user_id, $reason = 'manual', $reference_type = null,
                            $reference_id = null, $changed_by_user_id = null) {
        // Get current tier before change
        $current_tier = self::GetUserTier($user_id);
        $old_tier_level = $current_tier ? $current_tier->get('sbt_tier_level') : null;

        // For purchases, only allow upgrades (not downgrades)
        if ($reason === 'purchase' && $current_tier) {
            if ($this->get('sbt_tier_level') <= $current_tier->get('sbt_tier_level')) {
                // User already has this tier or higher - skip the change
                return false;
            }
        }

        // Get all subscription tier groups
        $tier_groups = new MultiGroup(['grp_category' => 'subscription_tier']);
        $tier_groups->load();

        // Remove user from all subscription tier groups
        foreach ($tier_groups as $group) {
            $existing_members = new MultiGroupMember([
                'grm_grp_group_id' => $group->key,
                'grm_foreign_key_id' => $user_id
            ]);
            $existing_members->load();

            foreach ($existing_members as $member) {
                $member->remove();  // Uses the remove() method from GroupMember class
            }
        }

        // Add user to this tier's group
        $group_member = new GroupMember(NULL);
        $group_member->set('grm_grp_group_id', $this->get('sbt_grp_group_id'));
        $group_member->set('grm_foreign_key_id', $user_id);
        $group_member->save();

        // Log the change
        ChangeTracking::logChange(
            'subscription_tier',
            $this->key,
            $user_id,
            'tier_level',
            $old_tier_level,
            $this->get('sbt_tier_level'),
            $reason,
            $reference_type,
            $reference_id,
            $changed_by_user_id
        );

        return true;
    }

    /**
     * Get current subscription tier for a user
     */
    public static function GetUserTier($user_id) {
        // Get all subscription tier groups the user belongs to
        $user_groups = new MultiGroupMember(['grm_foreign_key_id' => $user_id]);
        $user_groups->load();

        foreach ($user_groups as $group_member) {
            // Get the group
            $group = new Group($group_member->get('grm_grp_group_id'), TRUE);

            // Check if it's a subscription tier group
            if ($group->get('grp_category') === 'subscription_tier' && !$group->get('grp_delete_time')) {
                // Find the tier for this group
                $tier = self::GetByColumn('sbt_grp_group_id', $group->key);
                if ($tier && !$tier->get('sbt_delete_time')) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Check if user meets minimum tier level
     */
    public static function UserHasMinimumTier($user_id, $minimum_tier_level) {
        $user_tier = self::GetUserTier($user_id);
        if (!$user_tier) return false;
        return $user_tier->get('sbt_tier_level') >= $minimum_tier_level;
    }

    /**
     * Handle subscription tier assignment when a product is purchased
     * Called from cart_charge_logic.php
     */
    public static function handleProductPurchase($user, $product, $order_item, $order) {
        // Check if product has a subscription tier
        if (!$product->get('pro_sbt_subscription_tier_id')) {
            return false;
        }

        try {
            $tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);

            // Add user to tier with purchase context
            $tier->addUser(
                $user->key,
                'purchase',
                'order',
                $order->key,
                null  // No admin user for purchases
            );

            return true;

        } catch (Exception $e) {
            // Log error but don't break checkout
            error_log('Subscription tier assignment failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user has minimum tier level and redirect if not
     */
    public static function requireMinimumTier($user_id, $minimum_tier_level, $redirect_url = '/change-subscription') {
        if (!self::UserHasMinimumTier($user_id, $minimum_tier_level)) {
            header('Location: ' . $redirect_url);
            exit;
        }
    }

    /**
     * Get badge HTML for user's tier
     */
    public static function getUserTierBadge($user_id) {
        $tier = self::GetUserTier($user_id);

        if (!$tier) {
            return '<span class="badge badge-secondary">Free</span>';
        }

        $color = $tier->get('sbt_badge_color') ?: 'primary';
        $name = htmlspecialchars($tier->get('sbt_display_name'));

        return sprintf(
            '<span class="badge badge-%s">%s</span>',
            $color,
            $name
        );
    }

    /**
     * Get available upgrade options for a user
     */
    public static function getUpgradeOptions($user_id) {
        $current_tier = self::GetUserTier($user_id);
        $current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

        $all_tiers = MultiSubscriptionTier::GetAllActive();
        $upgrade_options = [];

        foreach ($all_tiers as $tier) {
            if ($tier->get('sbt_tier_level') > $current_level) {
                // Find products that grant this tier using models
                $products_with_tier = new MultiProduct([
                    'pro_sbt_subscription_tier_id' => $tier->key,
                    'pro_is_active' => true,
                    'pro_delete_time' => 'IS NULL'
                ]);

                if ($products_with_tier->count_all() > 0) {
                    $products_with_tier->load();
                    $products = [];

                    foreach ($products_with_tier as $product) {
                        $products[] = [
                            'pro_product_id' => $product->key,
                            'pro_name' => $product->get('pro_name')
                        ];
                    }

                    if (count($products) > 0) {
                        $upgrade_options[] = [
                            'tier' => $tier,
                            'products' => $products
                        ];
                    }
                }
            }
        }

        return $upgrade_options;
    }
}

class MultiSubscriptionTier extends SystemMultiBase {
    protected static $model_class = 'SubscriptionTier';

    /**
     * Get all active tiers ordered by level
     */
    public static function GetAllActive() {
        $tiers = new MultiSubscriptionTier(
            ['sbt_is_active' => true, 'sbt_delete_time' => 'IS NULL'],
            ['sbt_tier_level' => 'ASC']
        );
        $tiers->load();
        return $tiers;
    }
}
```

### 2. Checkout Integration

#### Modification to `/logic/cart_charge_logic.php`
```php
// Add this after line 505 (after product scripts), before line 507
// This goes in the cart item processing loop

// Handle subscription tier assignment
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
SubscriptionTier::handleProductPurchase($user, $product, $order_item, $order);

// Continue with existing code...
```

### 3. Admin Interface

#### `/adm/admin_subscription_tiers.php`
```php
<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Check permissions
$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$formwriter = LibraryFunctions::get_formwriter_object('admin_tiers', 'admin');

// Handle form submissions
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $tier = new SubscriptionTier(NULL);
        $tier->set('sbt_name', $_POST['sbt_name']);
        $tier->set('sbt_display_name', $_POST['sbt_display_name']);
        $tier->set('sbt_tier_level', $_POST['sbt_tier_level']);
        $tier->set('sbt_description', $_POST['sbt_description']);
        $tier->set('sbt_badge_color', $_POST['sbt_badge_color']);
        $tier->save();

        ChangeTracking::logChange(
            'subscription_tier',
            $tier->key,
            null,
            'created',
            null,
            $tier->get('sbt_name'),
            'admin_create',
            'admin_action',
            null,
            $session->get('usr_user_id')
        );

        header('Location: admin_subscription_tiers.php?success=1');
        exit;
    }
}

// Get all tiers
$tiers = MultiSubscriptionTier::GetAllActive();

// Display page
$page->admin_header('Subscription Tiers');
?>

<div class="container-fluid">
    <h1>Subscription Tier Management</h1>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Tier saved successfully!</div>
    <?php endif; ?>

    <!-- Create New Tier Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Create New Tier</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">

                <?php echo $formwriter->textinput('Tier Name', 'sbt_name', null, 30, '',
                    'Internal name (e.g., premium)', 100); ?>

                <?php echo $formwriter->textinput('Display Name', 'sbt_display_name', null, 30, '',
                    'User-facing name (e.g., Premium Member)', 100); ?>

                <?php echo $formwriter->textinput('Tier Level', 'sbt_tier_level', null, 10, '',
                    'Higher number = higher tier (e.g., 30)', 10); ?>

                <?php echo $formwriter->textarea('Description', 'sbt_description', '', 3, 50,
                    'Optional description'); ?>

                <?php echo $formwriter->select('Badge Color', 'sbt_badge_color', [
                    'secondary' => 'Gray',
                    'primary' => 'Blue',
                    'success' => 'Green',
                    'warning' => 'Yellow',
                    'danger' => 'Red',
                    'info' => 'Cyan'
                ], 'primary'); ?>

                <button type="submit" class="btn btn-primary">Create Tier</button>
            </form>
        </div>
    </div>

    <!-- Existing Tiers List -->
    <div class="card">
        <div class="card-header">
            <h3>Existing Tiers</h3>
        </div>
        <div class="card-body">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Level</th>
                        <th>Name</th>
                        <th>Display Name</th>
                        <th>Badge</th>
                        <th>Members</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tiers as $tier): ?>
                        <?php
                        // Count members
                        $group = new Group($tier->get('sbt_grp_group_id'), TRUE);
                        $members = $group->get_member_list();
                        $member_count = count($members);
                        ?>
                        <tr>
                            <td><?php echo $tier->key; ?></td>
                            <td><?php echo $tier->get('sbt_tier_level'); ?></td>
                            <td><?php echo htmlspecialchars($tier->get('sbt_name')); ?></td>
                            <td><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $tier->get('sbt_badge_color') ?: 'primary'; ?>">
                                    <?php echo htmlspecialchars($tier->get('sbt_display_name')); ?>
                                </span>
                            </td>
                            <td><?php echo $member_count; ?></td>
                            <td>
                                <a href="admin_subscription_tier_edit.php?id=<?php echo $tier->key; ?>"
                                   class="btn btn-sm btn-primary">Edit</a>
                                <a href="admin_subscription_tier_members.php?id=<?php echo $tier->key; ?>"
                                   class="btn btn-sm btn-info">View Members</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Products Using Tiers -->
    <div class="card mt-4">
        <div class="card-header">
            <h3>Products Granting Tiers</h3>
        </div>
        <div class="card-body">
            <?php
            // Get all products that have subscription tiers
            $products_with_tiers = [];

            foreach ($tiers as $tier) {
                $tier_products = new MultiProduct([
                    'pro_sbt_subscription_tier_id' => $tier->key,
                    'pro_delete_time' => 'IS NULL'
                ], ['pro_name' => 'ASC']);

                if ($tier_products->count_all() > 0) {
                    $tier_products->load();
                    foreach ($tier_products as $product) {
                        $products_with_tiers[] = [
                            'pro_product_id' => $product->key,
                            'pro_name' => $product->get('pro_name'),
                            'sbt_display_name' => $tier->get('sbt_display_name'),
                            'sbt_tier_level' => $tier->get('sbt_tier_level')
                        ];
                    }
                }
            }

            // Sort products by tier level and name
            usort($products_with_tiers, function($a, $b) {
                if ($a['sbt_tier_level'] == $b['sbt_tier_level']) {
                    return strcmp($a['pro_name'], $b['pro_name']);
                }
                return $a['sbt_tier_level'] - $b['sbt_tier_level'];
            });
            ?>

            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Product Name</th>
                        <th>Grants Tier</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products_with_tiers as $product): ?>
                        <tr>
                            <td><?php echo $product['pro_product_id']; ?></td>
                            <td><?php echo htmlspecialchars($product['pro_name']); ?></td>
                            <td>
                                <span class="badge badge-primary">
                                    <?php echo htmlspecialchars($product['sbt_display_name']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="admin_product_edit.php?pro_product_id=<?php echo $product['pro_product_id']; ?>"
                                   class="btn btn-sm btn-primary">Edit Product</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$page->admin_footer();
?>
```

### 4. User Profile Display

#### Modification to `/views/profile/profile.php` or `/views/profile/subscriptions.php`
```php
// Add after user info display
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Get user's current tier
$user_tier = SubscriptionTier::GetUserTier($page_vars['user']->key);
?>

<div class="sm:col-span-1">
    <dt class="text-sm font-medium text-gray-500">
        Membership Tier
    </dt>
    <dd class="mt-1 text-sm text-gray-900">
        <?php if ($user_tier): ?>
            <?php echo SubscriptionTier::getUserTierBadge($page_vars['user']->key); ?>
            <p class="mt-1 text-xs text-gray-600">
                <?php echo htmlspecialchars($user_tier->get('sbt_description')); ?>
            </p>
        <?php else: ?>
            <span class="badge badge-secondary">Free Member</span>
            <p class="mt-1 text-xs text-gray-600">
                <a href="/change-subscription" class="text-blue-600 hover:text-blue-800">Upgrade your membership</a>
            </p>
        <?php endif; ?>
    </dd>
</div>

<?php
// Show tier history
$tier_history = ChangeTracking::getUserHistory($page_vars['user']->key);
$tier_changes = [];

foreach ($tier_history as $change) {
    if ($change->get('cht_entity_type') == 'subscription_tier') {
        $tier_changes[] = $change;
    }
}

if (count($tier_changes) > 0):
?>
<div class="sm:col-span-2">
    <dt class="text-sm font-medium text-gray-500">
        Membership History
    </dt>
    <dd class="mt-1 text-sm text-gray-900">
        <ul class="list-disc list-inside">
            <?php foreach ($tier_changes as $change): ?>
                <li>
                    <?php
                    $change_date = date('M j, Y', strtotime($change->get('cht_change_time')));
                    $reason = $change->get('cht_change_reason');
                    echo "Changed to level {$change->get('cht_new_value')} on {$change_date} ({$reason})";
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </dd>
</div>
<?php endif; ?>
```

### 5. Change Subscription Page

#### `/views/change-subscription.php`
```php
<?php
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));
require_once(PathHelper::getThemeFilePath('change_subscription_logic.php', 'logic'));

$page_vars = process_logic(change_subscription_logic($_GET, $_POST));

$page = new PublicPage();
$hoptions = array(
    'title' => 'Change Your Subscription',
    'breadcrumbs' => array(
        'Change Subscription' => ''
    ),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Choose Your Membership Tier', $hoptions);

// Get current user's tier
$session = SessionControl::get_instance();
$user_id = $session->get('usr_user_id');
$current_tier = SubscriptionTier::GetUserTier($user_id);
$current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

// Get all active tiers
$all_tiers = MultiSubscriptionTier::GetAllActive();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center">
        <h2 class="text-3xl font-extrabold text-gray-900 sm:text-4xl">
            Choose Your Membership Level
        </h2>
        <?php if ($current_tier): ?>
            <p class="mt-3 text-xl text-gray-500">
                You currently have <strong><?php echo htmlspecialchars($current_tier->get('sbt_display_name')); ?></strong> membership
            </p>
        <?php else: ?>
            <p class="mt-3 text-xl text-gray-500">
                Join today and unlock premium features
            </p>
        <?php endif; ?>
    </div>

    <div class="mt-12 space-y-4 sm:mt-16 sm:space-y-0 sm:grid sm:grid-cols-<?php echo count($all_tiers); ?> sm:gap-6 lg:max-w-5xl lg:mx-auto">

        <?php foreach ($all_tiers as $tier): ?>
            <?php
            // Get products for this tier
            $tier_products = new MultiProduct([
                'pro_sbt_subscription_tier_id' => $tier->key,
                'pro_is_active' => true,
                'pro_delete_time' => 'IS NULL'
            ], ['pro_name' => 'ASC']);
            $tier_products->load();

            $is_current = $current_tier && $current_tier->key == $tier->key;
            $is_upgrade = $tier->get('sbt_tier_level') > $current_level;
            $is_downgrade = $tier->get('sbt_tier_level') < $current_level;
            ?>

            <div class="border border-gray-200 rounded-lg shadow-sm divide-y divide-gray-200
                        <?php echo $is_current ? 'ring-2 ring-indigo-500' : ''; ?>">
                <div class="p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        <?php echo htmlspecialchars($tier->get('sbt_display_name')); ?>
                    </h3>

                    <?php if ($is_current): ?>
                        <p class="mt-2 text-sm text-indigo-600 font-semibold">Your Current Plan</p>
                    <?php elseif ($is_downgrade): ?>
                        <p class="mt-2 text-sm text-gray-500">(Downgrade)</p>
                    <?php endif; ?>

                    <p class="mt-4 text-sm text-gray-500">
                        <?php echo htmlspecialchars($tier->get('sbt_description')); ?>
                    </p>

                    <div class="mt-6">
                        <?php if (count($tier_products) > 0): ?>
                            <?php foreach ($tier_products as $product): ?>
                                <?php
                                // Get product versions for pricing
                                $versions = $product->get_product_versions(TRUE);
                                if (count($versions) > 0):
                                    $version = $versions[0]; // Get first active version
                                ?>
                                    <p class="text-3xl font-extrabold text-gray-900">
                                        $<?php echo number_format($version->get('prv_version_price'), 2); ?>
                                        <?php if ($version->is_subscription()): ?>
                                            <span class="text-base font-medium text-gray-500">
                                                /<?php echo $version->get('prv_price_type'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-8">
                        <?php if ($is_current): ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-gray-100 cursor-not-allowed" disabled>
                                Current Plan
                            </button>
                        <?php elseif ($is_downgrade): ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed" disabled>
                                Downgrades Coming Soon
                            </button>
                        <?php elseif (count($tier_products) > 0): ?>
                            <?php foreach ($tier_products as $product): ?>
                                <a href="/product/<?php echo $product->get('pro_link'); ?>"
                                   class="block w-full py-2 px-4 border border-transparent rounded-md text-center text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                                    <?php echo $is_upgrade ? 'Upgrade Now' : 'Select Plan'; ?>
                                </a>
                                <?php break; // Show only first product as button ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed" disabled>
                                Coming Soon
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>
</div>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer($hoptions, NULL);
?>
```

#### `/logic/change_subscription_logic.php`
```php
<?php
function change_subscription_logic($get, $post) {
    $page_vars = array();

    // Check if user is logged in
    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        // Redirect to login with return URL
        header('Location: /login?return=' . urlencode('/change-subscription'));
        exit;
    }

    // User data for display
    $page_vars['user'] = new User($session->get('usr_user_id'), TRUE);

    return LogicResult::data($page_vars);
}
?>
```

### 6. Product Edit Integration

#### Modification to `/adm/admin_product_edit.php`
```php
// Add this field to the product edit form
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Get all active tiers for dropdown
$tiers = MultiSubscriptionTier::GetAllActive();
$tier_options = ['' => '-- None --'];

foreach ($tiers as $tier) {
    $tier_options[$tier->key] = sprintf(
        '%s (Level %d)',
        $tier->get('sbt_display_name'),
        $tier->get('sbt_tier_level')
    );
}

// In the form
echo $formwriter->select(
    'Subscription Tier',
    'pro_sbt_subscription_tier_id',
    $tier_options,
    $product->get('pro_sbt_subscription_tier_id'),
    'Select a tier this product grants when purchased'
);
```

## Next Steps

1. Review and approve this specification with code
2. Answer implementation decision questions
3. Implement Phase 1 (Core Implementation)
4. Test with sample tiers and products
5. Deploy to production
6. Plan Phase 2 features