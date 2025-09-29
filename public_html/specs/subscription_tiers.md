# Subscription Tier System Specification - Phase 2

## Overview
Phase 2 extends the completed Phase 1 subscription tier system with user-facing subscription management, feature/limit controls, and advanced tier behaviors.

## Phase 1 Status
✅ **COMPLETED** - See `/specs/implemented/subscription_tiers_phase1.md`

Phase 1 delivered:
- Core database structure with groups integration
- Automatic tier assignment at checkout
- Admin interface for tier management
- Change tracking system
- Basic tier display in user profile

## Phase 2 Goals

1. **User-Facing Subscription Management** - Enable users to upgrade, downgrade, and cancel subscriptions
2. **Feature/Limit System** - Control access to features and resources based on tier
3. **Admin Configuration** - Settings to control subscription change behaviors

## Database Changes for Phase 2

### Additional Fields for subscription_tiers Table
Add to existing `sbt_subscription_tiers` table:
- `sbt_features` (JSONB) - Stores all feature flags and limits as key-value pairs

Example JSON structure:
```json
{
  "storage_gb": 10,
  "api_calls_per_month": 10000,
  "priority_support": true,
  "max_projects": 5
}
```


## Phase 2 Implementation Components

### 1. User-Facing Subscription Management

   All subscription business logic is centralized in `/logic/change_subscription_logic.php` with `/views/change-subscription.php` handling only display.

   **Architecture:**
   - **Logic file (`/logic/change_subscription_logic.php`)**: Handles ALL business logic, permission checks, data preparation, and action processing
   - **View file (`/views/change-subscription.php`)**: Pure presentation - receives prepared data and displays it

   **Logic File Responsibilities:**
   ```php
   // change_subscription_logic.php handles:
   function change_subscription_logic($get_vars, $post_vars) {
       // 1. Authentication check
       // 2. Load user's current subscription and tier
       // 3. Get admin settings
       // 4. Process POST actions (upgrade/downgrade/cancel/reactivate)
       // 5. Prepare view data:
       $page_vars = [
           'current_tier' => $tier,
           'current_subscription' => $subscription,
           'available_tiers' => $tiers_with_permissions,
           'can_downgrade' => $settings['subscription_downgrades_enabled'],
           'can_cancel' => $settings['subscription_cancellation_enabled'],
           'can_reactivate' => $can_reactivate,
           'downgrade_timing_text' => $downgrade_text,
           'cancel_timing_text' => $cancel_text,
           'tier_actions' => [ // Pre-computed for each tier
               'tier_1' => ['action' => 'upgrade', 'enabled' => true, 'button_text' => 'Upgrade Now'],
               'tier_2' => ['action' => 'current', 'enabled' => false, 'button_text' => 'Current Plan'],
               'tier_3' => ['action' => 'downgrade', 'enabled' => false, 'button_text' => 'Contact Support']
           ]
       ];

       return LogicResult::render($page_vars);
   }
   ```

   **View File Responsibilities:**
   ```php
   // change-subscription.php only displays:
   foreach ($page_vars['available_tiers'] as $tier) {
       $action = $page_vars['tier_actions'][$tier->key];

       // Just display based on pre-computed values
       if ($action['action'] == 'current') {
           show_current_tier($tier);
       } elseif ($action['enabled']) {
           show_action_button($tier, $action['button_text']);
       } else {
           show_disabled_tier($tier, $action['button_text']);
       }
   }

   // No business logic, no permission checks, no settings checks
   ```

   All subscription actions are controlled by 6 admin settings

   **a. UPGRADE TO HIGHER TIER**

   **Always Allowed** (upgrades generate revenue)

   **User Action:** Selects higher-priced tier and confirms purchase

   **System Behavior:**
   - Stripe: Use `change_subscription()` to update subscription to new price ID
   - Stripe automatically prorates and charges the difference immediately
   - Database:
     - Mark old OrderItem with `odi_subscription_cancelled_time = now()`
     - Create new Order with STATUS_PAID
     - Create new OrderItem with new subscription details
     - Update SubscriptionTier via `handleProductPurchase()`
     - Run any product scripts if configured
   - User Experience: Immediate access to higher tier features

   ---

   **b. DOWNGRADE TO LOWER TIER**

   **Controlled by Settings:**
   - `subscription_downgrades_enabled` (bool) - If false, hide downgrade options entirely
   - `subscription_downgrade_timing` (enum) - Controls when downgrade takes effect

   **Scenario 1: Downgrades Disabled**
   - Setting: `subscription_downgrades_enabled = false`
   - UI: Lower tier options are hidden or show "Contact support to downgrade"
   - User cannot self-service downgrade

   **Scenario 2: Immediate Downgrade**
   - Settings: `subscription_downgrades_enabled = true`, `subscription_downgrade_timing = 'immediate'`
   - User Action: Selects lower tier and confirms
   - Stripe: Use `change_subscription()` with immediate effect
   - Stripe credits unused time to customer balance (applied to next invoice)
   - Database: Same as upgrade process
   - User Experience: Immediately loses higher tier features

   **Scenario 3: End-of-Period Downgrade**
   - Settings: `subscription_downgrades_enabled = true`, `subscription_downgrade_timing = 'end_of_period'`
   - User Action: Selects lower tier and confirms
   - Stripe: Update subscription with new price, set to take effect at period end using `proration_behavior: 'none'`
   - Database: Update immediately with future effective date
   - User Experience: Keeps current tier until billing period ends, then automatically downgrades

   ---

   **c. CANCEL SUBSCRIPTION**

   **Controlled by Settings:**
   - `subscription_cancellation_enabled` (bool) - If false, hide cancel option
   - `subscription_cancellation_timing` (enum) - When cancellation takes effect
   - `subscription_cancellation_prorate` (bool) - Whether to refund unused time

   **Scenario 1: Cancellation Disabled**
   - Setting: `subscription_cancellation_enabled = false`
   - UI: No cancel button shown, display "Contact support to cancel"

   **Scenario 2: Immediate Cancellation Without Refund**
   - Settings: `subscription_cancellation_enabled = true`, `subscription_cancellation_timing = 'immediate'`, `subscription_cancellation_prorate = false`
   - User Action: Clicks cancel and confirms
   - Stripe: Cancel subscription immediately
   - No refund issued
   - Database: Set `odi_subscription_cancelled_time`, update status to 'canceled'
   - User Experience: Immediately loses access to all tier features

   **Scenario 3: Immediate Cancellation With Proration**
   - Settings: `subscription_cancellation_enabled = true`, `subscription_cancellation_timing = 'immediate'`, `subscription_cancellation_prorate = true`
   - User Action: Clicks cancel and confirms
   - Stripe: Cancel subscription immediately with proration
   - Refund issued for unused time
   - Database: Set `odi_subscription_cancelled_time`, update status to 'canceled', record refund
   - User Experience: Immediately loses access, receives refund

   **Scenario 4: End-of-Period Cancellation**
   - Settings: `subscription_cancellation_enabled = true`, `subscription_cancellation_timing = 'end_of_period'`
   - User Action: Clicks cancel and confirms
   - Stripe: Set `cancel_at_period_end = true`
   - Database: Set future cancellation date
   - User Experience: Keeps access until billing period ends
   - Note: `subscription_cancellation_prorate` setting ignored (no refund needed)

   ---

   **d. REACTIVATE CANCELLED SUBSCRIPTION**

   **Controlled by Setting:**
   - `subscription_reactivation_enabled` (bool) - If false, no reactivation allowed

   **Scenario 1: Reactivation Disabled**
   - Setting: `subscription_reactivation_enabled = false`
   - Cancelled users must purchase new subscription
   - No "reactivate" option shown

   **Scenario 2: Reactivation Enabled (Period-End Cancellation)**
   - Setting: `subscription_reactivation_enabled = true`
   - Subscription was cancelled with `end_of_period` timing
   - User Action: Clicks "Reactivate" before period ends
   - Stripe: Update subscription with `cancel_at_period_end = false`
   - Database: Clear cancellation date
   - User Experience: Subscription continues normally

   **Scenario 3: Reactivation Enabled (Already Expired)**
   - Setting: `subscription_reactivation_enabled = true`
   - Subscription already fully cancelled/expired
   - User Action: Must purchase new subscription
   - System: Direct to standard purchase flow (not true reactivation)

   ---

   **e. COMPLEX INTERACTION SCENARIOS**

   **Scenario: User Changes Mind About End-of-Period Change**
   - Since changes are atomic via Stripe, users can make a new change that overwrites the previous one
   - Example: User schedules end-of-period downgrade, then decides to cancel instead
   - Stripe handles this naturally - the new change replaces the scheduled one

   **Scenario: Immediate Downgrade + Refund Calculation**
   - User downgrades immediately mid-cycle
   - Stripe calculates credit for unused higher-tier time
   - Credit applied to new lower-tier subscription
   - If credit exceeds new price, balance carries forward

   ---

   **f. POST ACTION PROCESSING IN LOGIC FILE**

   **All subscription changes are processed in the logic file:**
   ```php
   // In change_subscription_logic.php
   if (isset($post_vars['action'])) {
       switch ($post_vars['action']) {
           case 'upgrade':
               // Validate tier is actually higher
               // Process with Stripe
               // Update database
               // Return redirect or success message
               break;

           case 'downgrade':
               // Check if downgrades enabled
               // Validate timing setting
               // Process based on immediate vs end-of-period
               // Update database
               break;

           case 'cancel':
               // Check if cancellation enabled
               // Process based on timing and proration settings
               // Update database
               break;

           case 'reactivate':
               // Check if reactivation enabled
               // Verify subscription can be reactivated
               // Process with Stripe
               // Update database
               break;
       }
   }
   ```

   **g. DATA PREPARATION FOR VIEW**

   **Logic file prepares ALL display decisions:**
   ```php
   // In change_subscription_logic.php

   // For each tier, determine what action is available
   foreach ($all_tiers as $tier) {
       $tier_data = [
           'tier' => $tier,
           'products' => $tier->getProducts(),
           'is_current' => ($tier->key == $current_tier->key),
           'action_type' => null,
           'button_text' => '',
           'button_enabled' => false,
           'message' => ''
       ];

       if ($tier->level > $current_tier->level) {
           // Upgrade - always allowed
           $tier_data['action_type'] = 'upgrade';
           $tier_data['button_text'] = 'Upgrade Now';
           $tier_data['button_enabled'] = true;
       }
       elseif ($tier->level < $current_tier->level) {
           // Downgrade - check settings
           if (!$settings['subscription_downgrades_enabled']) {
               $tier_data['action_type'] = 'downgrade_disabled';
               $tier_data['button_text'] = 'Contact Support';
               $tier_data['message'] = 'Downgrades require support assistance';
           } else {
               $tier_data['action_type'] = 'downgrade';
               $tier_data['button_text'] = ($settings['subscription_downgrade_timing'] == 'immediate')
                   ? 'Downgrade Now'
                   : 'Downgrade at Period End';
               $tier_data['button_enabled'] = true;
           }
       }
       else {
           $tier_data['action_type'] = 'current';
           $tier_data['button_text'] = 'Current Plan';
       }

       $page_vars['tier_display_data'][] = $tier_data;
   }

   // Prepare cancellation button data
   if ($has_active_subscription) {
       $page_vars['show_cancel_button'] = $settings['subscription_cancellation_enabled'];
       $page_vars['cancel_button_text'] = ($settings['subscription_cancellation_timing'] == 'immediate')
           ? 'Cancel Immediately'
           : 'Cancel at Period End';
   }

   // Prepare reactivation button data
   if ($has_cancelled_subscription && !$is_expired) {
       $page_vars['show_reactivate_button'] = $settings['subscription_reactivation_enabled'];
   }
   ```

   **The view file simply iterates through prepared data and displays it - no logic decisions!**

   **Key Implementation Considerations:**
   - **Proration Handling:** Upgrades charge immediately, downgrades credit at next billing
   - **Permission Checks:** Verify user owns subscription via `authenticate_write()`
   - **Tier Management Rules:** Purchases only allow upgrades (not downgrades)
   - **Product Script Execution:** Run appropriate scripts for new product
   - **Error Handling:** Stripe API failures, concurrent modifications, invalid transitions
   - **Notification Flow:** Customer confirmations, admin notifications, webhook handling

   **Implementation Clarifications:**
   1. **Stripe Subscription Updates:** Keep the same subscription ID when changing tiers - use Stripe's `update()` method rather than creating new subscriptions
   2. **Trial Period Handling:** Any tier change ends trial periods immediately - user starts paying for new tier
   3. **Failed Payment Handling:** If upgrade payment fails, user remains on current tier with error message
   4. **No Pending Changes:** All subscription changes happen immediately or at period end - no future-dated changes stored
   5. **Product Selection:** Tier changes are triggered by purchasing a specific product - the view shows available products for each tier
   6. **Free Tier:** Downgrading to free tier is treated as cancellation - no separate "free tier" concept
   7. **Subscription Expiration Handling:** Use lazy evaluation - when checking user's tier, if `odi_subscription_period_end` is in the past, sync with Stripe and update tier access accordingly (see detailed implementation below)

   **h. SUBSCRIPTION EXPIRATION HANDLING (LAZY EVALUATION)**

   **No cron jobs needed - subscription status is checked on-demand**

   **Subscription checking lives in OrderItem class, not tier code:**

   **Step 1: Add check_subscription_status() method to OrderItem class:**
   ```php
   // In /data/order_items_class.php
   class OrderItem extends SystemBase {

       /**
        * Check if subscription is still active, sync with Stripe if needed
        * @return bool True if subscription is active, false if expired/cancelled
        */
       public function check_subscription_status() {
           // Only check subscriptions
           if (!$this->get('odi_is_subscription')) {
               return true; // Non-subscription items are always "active"
           }

           // Check if period has ended
           $period_end = strtotime($this->get('odi_subscription_period_end'));

           if ($period_end < time()) {
               // Period has passed - sync with Stripe
               try {
                   $stripe_helper = new StripeHelper();

                   // This existing method updates all subscription fields
                   $stripe_helper->update_subscription_in_order_item($this);

                   // Check status after update
                   $status = $this->get('odi_subscription_status');
                   return in_array($status, ['active', 'trialing']);

               } catch (Exception $e) {
                   // If Stripe check fails, assume subscription is still valid
                   // to avoid removing access due to API issues
                   error_log('Failed to check subscription status: ' . $e->getMessage());
                   return true; // Fail open - assume valid
               }
           }

           // Period hasn't ended yet - check if cancelled
           if ($this->get('odi_subscription_cancelled_time')) {
               return false; // Cancelled subscription
           }

           return true; // Active subscription
       }
   }
   ```

   **Step 2: Enhanced GetPlanOrderItem() in CtldAccount:**
   ```php
   // In /plugins/controld/data/ctldaccounts_class.php
   static function GetPlanOrderItem($user_id) {
       // ... existing code to get subscription ...

       if ($order_item) {
           // Check if subscription is still valid
           if (!$order_item->check_subscription_status()) {
               return false; // Subscription expired
           }
       }

       return $order_item;
   }
   ```

   **Step 3: Tier code reacts to subscription changes:**
   ```php
   // In SubscriptionTier::GetUserTier()
   public static function GetUserTier($user_id) {
       // Check for active subscription (this now includes expiration check)
       $order_item = CtldAccount::GetPlanOrderItem($user_id);

       if (!$order_item) {
           // No active subscription - check if user had a tier
           $current_tier = parent::GetUserTier($user_id);
           if ($current_tier) {
               // User had tier but subscription ended - remove it
               self::removeUserFromAllTiers($user_id);

               // Log the change
               ChangeTracking::logChange(
                   'subscription_tier',
                   null,
                   $user_id,
                   'tier_removed',
                   $current_tier->get('sbt_tier_level'),
                   null,
                   'subscription_expired'
               );
           }
           return null;
       }

       // User has active subscription - return their tier
       return parent::GetUserTier($user_id);
   }
   ```

   **When This Check Occurs:**
   - User logs in
   - User accesses tier-gated features
   - User views their profile/subscription page
   - Any call to `SubscriptionTier::getUserFeature()`

   **Handles All End Scenarios:**
   - Subscription cancelled and period ended
   - Payment failed and subscription suspended
   - Card expired and subscription terminated
   - Manual cancellation by admin

   **Failsafe Behavior:**
   - If Stripe API is unavailable, assume subscription is valid
   - Prevents accidental tier removal due to temporary API issues
   - Logs errors for admin review

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

### 2. Tier Features/Limits System

   **Implementation Approach:**
   - Single JSONB column `sbt_features` added to `sbt_subscription_tiers` table
   - Stores all features and limits as key-value pairs
   - No separate tables needed

   **Database Change:**
   ```php
   // Add to subscription_tiers_class.php field_specifications:
   'sbt_features' => array('type'=>'jsonb', 'default'=>'{}')
   ```

   **Example JSON Structure:**
   ```json
   {
     "controld_max_devices": 3,
     "controld_custom_rules": true,
     "controld_advanced_filters": true,
     "storage_gb": 10,
     "api_calls_per_month": 10000,
     "priority_support": true
   }
   ```

   **Helper Methods in SubscriptionTier Class:**
   ```php
   // Get a specific feature for a tier
   public function getFeature($key, $default = null) {
       $features = json_decode($this->get('sbt_features'), true) ?? [];
       return $features[$key] ?? $default;
   }

   // Set tier features (admin use)
   public function setFeatures($features_array) {
       $this->set('sbt_features', json_encode($features_array));
   }

   // Static method to get user's feature value
   public static function getUserFeature($user_id, $feature_key, $default = null) {
       // Check cache first
       if (!isset(self::$user_tier_cache[$user_id])) {
           self::$user_tier_cache[$user_id] = self::GetUserTier($user_id);
       }
       $tier = self::$user_tier_cache[$user_id];

       if (!$tier) return $default;

       return $tier->getFeature($feature_key, $default);
   }
   ```

   **Usage in Plugins:**
   ```php
   // Example: Check a numeric limit
   function can_add_item($user_id) {
       $current_items = // ... count current items

       // Get limit from tier features (with default for free users)
       $max_items = SubscriptionTier::getUserFeature($user_id, 'plugin_max_items', 1);

       return $current_items < $max_items;
   }

   // Example: Check feature access
   function has_premium_feature($user_id) {
       return SubscriptionTier::getUserFeature($user_id, 'plugin_premium_feature', false);
   }
   ```

   **Feature Registration System:**

   Core features are defined in a central file, while plugins define their own features:

   **Core Features Definition:**
   ```php
   // In /includes/core_tier_features.json
   {
       "storage_gb": {
           "label": "Storage (GB)",
           "type": "integer",
           "default": 5,
           "description": "Storage space in gigabytes"
       },
       "api_calls_per_month": {
           "label": "Monthly API Calls",
           "type": "integer",
           "default": 1000,
           "description": "Number of API calls allowed per month"
       },
       "priority_support": {
           "label": "Priority Support",
           "type": "boolean",
           "default": false,
           "description": "Access to priority support channels"
       }
   }
   ```

   **Plugin Features Definition:**
   ```json
   // In /plugins/{plugin_name}/tier_features.json
   {
       "plugin_feature_1": {
           "label": "Feature Name",
           "type": "integer",
           "default": 1,
           "min": 0,
           "max": 100,
           "description": "Description of what this feature controls"
       },
       "plugin_feature_2": {
           "label": "Another Feature",
           "type": "boolean",
           "default": false,
           "description": "Enable/disable this plugin feature"
       }
   }
   ```

   **Feature Discovery Function:**
   ```php
   // In SubscriptionTier class or helper
   public static function getAllAvailableFeatures() {
       $features = [];

       // Load core features
       $core_file = PathHelper::getIncludePath('includes/core_tier_features.json');
       if (file_exists($core_file)) {
           $features = json_decode(file_get_contents($core_file), true);
       }

       // Load plugin features
       $plugins = LibraryFunctions::list_plugins();
       foreach ($plugins as $plugin) {
           $feature_file = PathHelper::getRootDir() . "/plugins/$plugin/tier_features.json";
           if (file_exists($feature_file)) {
               $plugin_features = json_decode(file_get_contents($feature_file), true);
               $features = array_merge($features, $plugin_features);
           }
       }

       return $features;
   }
   ```

   **Admin UI Integration:**
   ```php
   // In admin tier edit page
   $available_features = SubscriptionTier::getAllAvailableFeatures();
   $current_values = $tier->getAllFeatures();

   foreach ($available_features as $key => $definition) {
       $current_value = $current_values[$key] ?? $definition['default'];

       if ($definition['type'] === 'boolean') {
           echo $formwriter->checkbox(
               $definition['label'],
               $key,
               $current_value,
               $definition['description']
           );
       } elseif ($definition['type'] === 'integer') {
           echo $formwriter->textinput(
               $definition['label'],
               $key,
               $current_value,
               10,
               '',
               $definition['description']
           );
       }
   }
   ```

   **Key Benefits:**
   - **Single database column** stores all feature values
   - **Plugins self-document** their tier features
   - **Admin UI automatically discovers** all available features
   - **No database changes** when plugins add new features
   - **Type safety** through feature definitions
   - **Default values** provided for new tiers

   This approach is:
   - **Simple**: One column, no extra tables
   - **Fast**: Tier is cached, JSON parsing is quick for small objects
   - **Flexible**: Easy to add new features without schema changes
   - **Maintainable**: Features defined in one place, used everywhere



## Implementation Summary

Phase 2 builds on the completed Phase 1 foundation to add:

1. **Subscription Management** - Full user control over upgrades, downgrades, and cancellations with Stripe integration
2. **Feature System** - JSON-based feature flags and limits with plugin self-registration via `tier_features.json` files
3. **Admin Settings** - 6 essential settings to control subscription change behaviors
4. **ControlD Migration** - Clear path to move from hardcoded plans to tier-based features

Key technical decisions:
- Single JSONB column for all tier features
- Plugin feature discovery via JSON definition files
- Cached tier data for performance
- Minimal configuration complexity

## Phase 3 (Future)

Phase 3 will focus on **plugin migrations**, **documentation**, and **expanded payment options**:

**ControlD Plugin Migration:**
- Migrate from hardcoded plans (Basic=1, Premium=2, Pro=3) to tier-based features
- Create tier features: `controld_max_devices`, `controld_custom_rules`, `controld_advanced_filters`
- Update products 19, 20, 21 to use subscription tiers
- Refactor all plan checks to use `getUserFeature()` calls
- Migrate existing users from `cda_plan` to subscription tiers
- Create `/plugins/controld/tier_features.json` for feature registration

**Documentation:**
- End-user documentation for subscription management
- Administrator guide for tier configuration
- Developer documentation for plugin integration
- API reference for tier features system
- Migration guides and best practices

**Payment Integration:**
- PayPal subscription support (if feasible)
- Integration with PayPalHelper for recurring payments
- Parallel support for both Stripe and PayPal subscriptions