# Subscription Tier System - Phase 3 Specification

## Overview
Phase 3 focuses on **plugin migrations**, **comprehensive documentation**, **email notifications**, and **expanded payment options**.

## Phase 1 & 2 Status
- ✅ **Phase 1 COMPLETED** - See `/specs/implemented/subscription_tiers_phase1.md`
- ✅ **Phase 2 COMPLETED** - See `/specs/implemented/subscription_tiers_phase2.md`

**What Phase 2 Delivered:**
- User-facing subscription management (upgrade/downgrade/cancel/reactivate)
- Feature/limit system with JSONB storage
- Admin configuration settings
- Automatic subscription expiration handling
- Dynamic feature editor for admin
- Comprehensive automated testing

---

## Phase 3 Goal

### ControlD Plugin Migration
Migrate ControlD plugin from hardcoded plan system to subscription tier features, eliminating all redundant code and tables.

**Primary Objectives:**
1. Eliminate CtldAccount table entirely
2. Remove pricing page display fields from ProductVersion model
3. Migrate all users to tier-based access
4. Update all logic/views to use core tier functions
5. Delete all obsolete files

**Note:** Additional features (email notifications, webhooks, documentation, etc.) are documented in [`/specs/subscription_tiers_additions.md`](/specs/subscription_tiers_additions.md)

---

## 1. ControlD Plugin Migration - Detailed Plan

### Current State Analysis

**Database Structure:**
- `cda_ctldaccounts` table stores ControlD account data
  - `cda_plan` (int4) - Hardcoded plan: 1=Basic, 2=Premium, 3=Pro
  - `cda_plan_max_devices` (int4) - Max devices allowed (1, 3, or 10)
  - `cda_is_active` (bool) - Account active status
  - `cda_period_end` (timestamp) - Subscription end date
  - `cda_usr_user_id` (int4) - Link to user

**Hardcoded Constants:**
```php
const BASIC_PLAN = 1;
const PREMIUM_PLAN = 2;
const PRO_PLAN = 3;

const BASIC_PLAN_MAX_DEVICES = 1;
const PREMIUM_PLAN_MAX_DEVICES = 3;
const PRO_PLAN_MAX_DEVICES = 10;
```

**Current Plan Checks Found:**
1. **Device limits:** `can_add_devices()` checks against `cda_plan_max_devices`
2. **Plan display:** `readable_plan_name()` converts plan ID to name
3. **Filter access:** Premium/Pro get advanced filters in `ctldfilters_edit.php`
4. **Custom rules:** Only Pro plan gets rules in `rules.php`
5. **Product purchase hook:** Sets plan and max_devices based on product ID

**Key Method:**
- `CtldAccount::GetPlanOrderItem()` - Gets active subscription OrderItem for user

---

### Migration Strategy: COMPLETE ELIMINATION of CtldAccount

**Goal:** Remove the ENTIRE CtldAccount table and class - it's completely redundant with the tier system!

### Phase 1: Setup Tier Features

**A. Update `/plugins/controld/tier_features.json` (if exists) or create:**
```json
{
  "controld_max_devices": {
    "label": "Max ControlD Devices",
    "type": "integer",
    "default": 1,
    "min": 1,
    "max": 100,
    "description": "Maximum number of ControlD devices allowed"
  },
  "controld_advanced_filters": {
    "label": "Advanced Filters (Ad/Malware)",
    "type": "boolean",
    "default": false,
    "description": "Enable advanced ad and malware blocking filters"
  },
  "controld_custom_rules": {
    "label": "Custom DNS Rules",
    "type": "boolean",
    "default": false,
    "description": "Enable custom DNS filtering rules"
  }
}
```

**B. Configure Tiers in Admin:**
- **Free/No Tier:** No access to ControlD (tier itself is access control!)
- **Basic (Level 1):** max_devices=1, filters=false, rules=false
- **Premium (Level 2):** max_devices=3, filters=true, rules=false
- **Pro (Level 3):** max_devices=10, filters=true, rules=true

**Key Insight:** Having a tier = having ControlD access. No tier = no access. Simple!

### Phase 2: ELIMINATE CtldAccount Completely & Simplify Product Display

**Why CtldAccount is redundant:**
- Devices already have `cdd_usr_user_id` - no need for intermediate table
- Profiles already have `cdp_usr_user_id` - direct user relationship
- Plan/limits/status all handled by subscription tier system
- No foreign keys reference `cda_ctldaccount_id` anywhere!

**Why Product Display Dropdowns should be removed from the model:**
- The fields `prv_plan_order_month` and `prv_plan_order_year` are used to control pricing page display
- These duplicate the tier system's functionality
- Subscription products should use `/change-subscription` page, not `/pricing`
- Tiers are automatically ordered by `sbt_tier_level`
- Removing from model ensures clean separation between regular products and subscription tiers

**A. Use Core Functions Directly - NO Helper Class Needed!**

```php
// Check if user has ControlD access (having a tier = having access)
$tier = SubscriptionTier::GetUserTier($user_id);
$has_access = ($tier !== null);

// Check if user can add more devices
$max_devices = SubscriptionTier::getUserFeature($user_id, 'controld_max_devices', 0);
$current_devices = new MultiCtldDevice([
    'cdd_usr_user_id' => $user_id,
    'cdd_delete_time' => 'IS NULL'
]);
$can_add = ($max_devices > 0) && ($current_devices->count_all() < $max_devices);

// Get user's plan display name
$tier = SubscriptionTier::GetUserTier($user_id);
$plan_name = $tier ? $tier->get('sbt_display_name') : 'Free';

// Note: GetUserTier() already validates subscription expiration!
```

### Phase 3: (REMOVED - Details moved to Phase 4 and 5)

### Phase 4: Update All Logic Files to Use Tier System

**Pattern for all logic files:** Replace CtldAccount with SubscriptionTier functions

**Files that just pass $account to view (SIMPLE - just remove):**
1. `/logic/devices_logic.php` - Line 36-38
2. `/logic/ctld_activation_logic.php` - Line 30
3. `/logic/ctlddevice_delete_logic.php` - Line 29
4. `/logic/ctlddevice_soft_delete_logic.php` - Line 29
5. `/logic/ctldprofile_delete_logic.php` - Line 29
6. `/logic/rules_logic.php` - Line 28
7. `/logic/profile_logic.php` - Line 40-41 (also line 87 GetPlanOrderItem)

**Common Changes (all files above):**
```php
// REMOVE this line:
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccounts_class.php'));

// REMOVE these lines:
$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);
$page_vars['account'] = $account;

// ADD this instead (if tier needed in view):
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
$tier = SubscriptionTier::GetUserTier($user->key);
$page_vars['tier'] = $tier;
```

**File with device limit check (NEEDS LOGIC CHANGE):**

`/logic/ctlddevice_edit_logic.php` - Lines 27-33, 89-92:
```php
// OLD:
$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);

if(!$account){
    throw new SystemDisplayablePermanentError("User ".$user->key." does not have an Account.");
    exit;
}
$page_vars['account'] = $account;

// Later in file (line 89):
if(!$account->can_add_device()){
    throw new SystemDisplayablePermanentError("You cannot add any devices at this time.");
    exit;
}

// NEW:
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Check if user has ControlD access
$tier = SubscriptionTier::GetUserTier($user->key);
if(!$tier){
    throw new SystemDisplayablePermanentError("You do not have an active subscription.");
    exit;
}
$page_vars['tier'] = $tier;

// Later in file - check device limit:
$max_devices = SubscriptionTier::getUserFeature($user->key, 'controld_max_devices', 0);
$current_devices = new MultiCtldDevice([
    'user_id' => $user->key,
    'deleted' => false
]);
if($current_devices->count_all() >= $max_devices){
    throw new SystemDisplayablePermanentError("You have reached your device limit of {$max_devices}.");
    exit;
}
```

`/logic/ctldfilters_edit_logic.php` - Line 27 (just passes to view, no logic):
```php
// REMOVE:
$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);
$page_vars['account'] = $account;

// REPLACE with (tier will be checked in view for feature access):
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
$tier = SubscriptionTier::GetUserTier($user->key);
$page_vars['tier'] = $tier;
```

### Phase 5: Update View Files to Use Tier Features

**`/views/profile/ctldfilters_edit.php`:**
```php
// OLD (checking plan with constants):
if($account->get('cda_plan') == CtldAccount::PREMIUM_PLAN || $account->get('cda_plan') == CtldAccount::PRO_PLAN)

// NEW (using tier feature):
if(SubscriptionTier::getUserFeature($session->get_user_id(), 'controld_advanced_filters', false))
```

**`/views/profile/rules.php`:**
```php
// OLD (checking plan with constants):
if($account->get('cda_plan') == CtldAccount::PRO_PLAN)

// NEW (using tier feature):
if(SubscriptionTier::getUserFeature($session->get_user_id(), 'controld_custom_rules', false))
```

**Note:** Both view files will need to add the require statement at the top:
```php
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
```

### Phase 6: Update Service Layer

**`/services/ControlDService.php` - Remove getUserAccount() method:**
```php
// REMOVE lines 38-49 (getUserAccount method):
/**
 * Get user's ControlD account
 */
public function getUserAccount($user_id) {
    require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccount_class.php'));
    $accounts = new MultiCtldAccount(['cta_usr_user_id' => $user_id]);
    if ($accounts->count_all() > 0) {
        $accounts->load();
        return $accounts->get(0);
    }
    return null;
}

// If other code calls this method, replace with:
// SubscriptionTier::GetUserTier($user_id)
```

### Phase 7: Delete Product Purchase Hook

**DELETE the hook entirely!**
Since tier assignment happens automatically via `handleProductPurchase()`, the ControlD product purchase hook is no longer needed.

The subscription tier system handles everything:
- Tier assignment happens automatically based on `pro_sbt_subscription_tier_id`
- User gets added to tier group automatically
- Features are available immediately
- No custom code needed!

### Phase 8: Data Migration (From CtldAccount to Tiers)

**A. Manual Migration (2 users only):**
For the small number of users, manually assign tiers in admin:
1. Check each user's current plan in `cda_ctldaccounts`
2. Manually assign appropriate tier in admin interface
3. Verify access works correctly

**B. Update Product Links & Remove Pricing Page Display (MANUAL ACTION - SQL):**
```sql
-- Link ControlD products to tiers
UPDATE pro_products SET pro_sbt_subscription_tier_id = 10 WHERE pro_product_id = 19; -- Basic
UPDATE pro_products SET pro_sbt_subscription_tier_id = 20 WHERE pro_product_id = 20; -- Premium
UPDATE pro_products SET pro_sbt_subscription_tier_id = 30 WHERE pro_product_id = 21; -- Pro
```

**Note:** These SQL statements must be run manually by the user - no database access during migration.

**C. Remove Pricing Page Fields from Model:**
```php
// In /data/product_versions_class.php, remove these field specifications:
// 'prv_plan_order_month' => array('type'=>'int4'),
// 'prv_plan_order_year' => array('type'=>'int4'),

// The database will automatically drop the columns when update_database runs
```

**D. Update Admin UI:**
```php
// In /adm/admin_product_version_edit.php, remove lines 158-174:
// The pricing page dropdown section should be completely removed
```

**E. Redesign `/pricing` Page to Use Subscription Tiers:**

**1. `/logic/pricing_logic.php` - Complete Rewrite:**

Change from ProductVersion-based filtering to tier-based display:

```php
// OLD approach (remove):
// - Queries MultiProductVersion with 'is_monthly_plan' / 'is_yearly_plan'
// - Sorts by 'plan_order_month' / 'plan_order_year'
// - Gets products from versions

// NEW approach:
// 1. Get all active subscription tiers ordered by tier_level
$tiers = SubscriptionTier::GetAllActive(); // Already ordered by sbt_tier_level ASC

// 2. For each tier, get associated products
$tier_products = [];
foreach ($tiers as $tier) {
    $products = new MultiProduct([
        'pro_sbt_subscription_tier_id' => $tier->key,
        'is_active' => TRUE,
        'deleted' => FALSE
    ]);
    $products->load();

    if ($products->count() > 0) {
        $tier_products[] = [
            'tier' => $tier,
            'products' => $products
        ];
    }
}

// 3. Display tiers in order (automatically ordered by tier_level)
$page_vars['tier_products'] = $tier_products;
```

**Benefits:**
- Uses tier IDs (10, 20, 30) for natural ordering
- No need for separate monthly/yearly pages
- Single source of truth (tier system)
- Easy to add new tiers - just set tier_level appropriately

**2. `/data/product_versions_class.php` - Remove pricing page filters:**
```php
// In MultiProductVersion::getMultiResults(), remove lines 77-83:
// if (isset($this->options['is_monthly_plan'])) {
//     $filters['prv_plan_order_month'] = "> 0";
// }
// if (isset($this->options['is_yearly_plan'])) {
//     $filters['prv_plan_order_year'] = "> 0";
// }
```

**3. `/views/pricing.php` - Update display logic:**
```php
// Update to display tier-based products:
foreach ($page_vars['tier_products'] as $tier_data) {
    $tier = $tier_data['tier'];
    $products = $tier_data['products'];

    // Display tier name/description
    echo '<h3>' . $tier->get('sbt_display_name') . '</h3>';

    // Display products for this tier
    foreach ($products as $product) {
        // ... existing product display code
    }
}
```

**4. `/plugins/controld/views/index.php` - Update plugin homepage:**

This file also uses the old pricing logic for displaying plans. Update it to use tier-based logic:

```php
// OLD (line 8):
require_once(PathHelper::getThemeFilePath('pricing_logic.php', 'logic', 'system', null, 'controld'));

// NEW - Create controld-specific logic or reuse core tier-based pricing_logic.php
// The loop starting at line 322 should iterate over tier-based products:
<?php foreach ($page_vars['tier_products'] as $tier_data) {
    $tier = $tier_data['tier'];
    $products = $tier_data['products'];

    foreach ($products as $product) {
        // Get first product version
        $versions = $product->get_product_versions();
        $product_version = $versions->count() > 0 ? $versions->get(0) : null;

        // Display product card (existing code from lines 325-358)
        ?>
        <div class="col-xl-4 col-md-6">
            <div class="price-box th-ani">
                <div class="price-title-wrap">
                    <h3 class="box-title"><?php echo $product->get('pro_name'); ?></h3>
                </div>
                <?php echo $product->get('pro_short_description'); ?>
                <h4 class="box-price">
                    <?php echo $product->get_readable_price($product_version->key); ?>
                    <span class="duration">/month</span>
                </h4>
                <div class="box-content">
                    <div class="available-list">
                        <?php echo $product->get('pro_description'); ?>
                    </div>
                    <a href="<?php echo $product->get_url(). '?product_version_id='.$product_version->key; ?>" class="th-btn btn-fw style-radius">Get Started</a>
                </div>
            </div>
        </div>
        <?php
    }
}?>
```

**Note:** The plugin index page will automatically display plans in tier_level order (10, 20, 30) since the tier-based logic orders by `sbt_tier_level ASC`.

**F. Custom View Override (Optional):**
- Copy existing `/plugins/controld/views/subscription_edit.php` if needed
- Move to `/theme/falcon/views/change-subscription.php`
- This overrides the core view with ControlD-specific styling
- Remove all custom logic - just styling changes
- Core logic in `/logic/change_subscription_logic.php` handles everything

### Phase 9: Complete Cleanup

**After successful migration:**

1. **DROP THE ENTIRE CtldAccount TABLE (MANUAL ACTION - SQL):**
```sql
-- After verification, completely remove the table
DROP TABLE cda_ctldaccounts;
```

**Note:** This SQL statement must be run manually by the user - no database access during migration.

2. **Delete CtldAccount-related files:**
- `/plugins/controld/data/ctldaccounts_class.php` - DELETE entirely (includes removing hardcoded constants)
- `/plugins/controld/logic/subscription_edit_logic.php` - DELETE (use core logic)
- `/plugins/controld/logic/subscription_cancel_logic.php` - DELETE
- `/plugins/controld/views/profile/subscription_cancel.php` - DELETE
- `/plugins/controld/hooks/product_purchase.php` - DELETE (no longer needed!)

3. **Remove hardcoded plan constants from ctldaccounts_class.php:**
```php
// DELETE these constants:
const BASIC_PLAN = 1;
const PREMIUM_PLAN = 2;
const PRO_PLAN = 3;

const BASIC_PLAN_MAX_DEVICES = 1;
const PREMIUM_PLAN_MAX_DEVICES = 3;
const PRO_PLAN_MAX_DEVICES = 10;

// These are replaced by tier features:
// - Tier level determines plan (sbt_tier_level: 1, 2, 3)
// - Max devices from: SubscriptionTier::getUserFeature($user_id, 'controld_max_devices', 0)
```

4. **RENAME to override core view:**
- `/plugins/controld/views/profile/subscription_edit.php` → `/plugins/controld/views/change-subscription.php`
- This will override the core `/views/change-subscription.php` with ControlD styling
- Uses core `/logic/change_subscription_logic.php` - no custom logic needed!

5. **Update admin pages:**
- `/plugins/controld/admin/admin_ctld_accounts.php` - DELETE
- `/plugins/controld/admin/admin_ctld_account.php` - DELETE
- Update admin menus to remove CtldAccount references

6. **Update profile page** to link to subscription management:
```php
// In /plugins/controld/views/profile/profile.php
// Replace subscription management section with:
echo '<a href="/change-subscription">Manage Subscription</a>';
// This will use the ControlD-styled override view with core logic!
```

### How Theme Override Works

When user visits `/change-subscription`:
1. Core system loads `/logic/change_subscription_logic.php` (handles all business logic)
2. Theme system checks for override: `/plugins/controld/views/change-subscription.php`
3. If found, uses ControlD's styled view instead of core `/views/change-subscription.php`
4. ControlD gets custom styling while using 100% core logic and tier data

**Result:** Perfect separation - core handles logic, ControlD provides branded UI!

### Benefits After Eliminating CtldAccount

1. **MASSIVE simplification:** ~85% less ControlD subscription code
2. **No redundant table:** Eliminated entire `cda_ctldaccounts` table
3. **Direct relationships:** Devices/profiles linked directly to users
4. **Zero duplication:** All subscription data in core system only
5. **Unified management:** Everything through `/change-subscription`
6. **Automatic expiration:** Core system handles all validation
7. **Dynamic features:** Admin controls without code changes
8. **Better performance:** One less table to query
9. **Cleaner architecture:** No intermediate account layer

### Testing Checklist

- [ ] Migration script runs without errors
- [ ] Existing users maintain correct access levels
- [ ] Device limits enforced correctly (via tier feature)
- [ ] Advanced filters show for Premium/Pro only
- [ ] Custom rules show for Pro only
- [ ] New purchases assign correct tier automatically
- [ ] Subscription changes work through `/change-subscription`
- [ ] Expiration removes ControlD access automatically
- [ ] Admin can modify features dynamically
- [ ] CtldAccount table successfully dropped
- [ ] All CtldAccount references removed from code
- [ ] No broken pages after elimination

### Migration Summary

**Before:**
- Separate CtldAccount table with plan/limits/status
- Duplicate subscription tracking
- Hardcoded plan constants (BASIC_PLAN = 1, etc.)
- Custom subscription management pages
- Complex product purchase hook
- ~15 files managing subscriptions
- Redundant `cda_is_active` field
- Custom pricing page display dropdowns

**After:**
- NO CtldAccount table needed
- Tier presence = ControlD access (no tier = no access)
- Just 3 tier features (max_devices, advanced_filters, custom_rules)
- Zero hardcoded plans
- Core `/change-subscription` for all management
- No product purchase hook needed
- No pricing page display fields needed (`prv_plan_order_month`/`prv_plan_order_year` removed)
- Just 1 file (tier_features.json) + minor updates to existing views/logic
- Direct use of core functions - no wrapper methods needed

---

## Phase 3 Success Criteria

Phase 3 (ControlD Migration) is complete when:
- ✅ ControlD plugin fully migrated to tier features
- ✅ CtldAccount table eliminated
- ✅ All redundant files removed
- ✅ Product display fields removed from model
- ✅ All subscription management flows through `/change-subscription`
- ✅ All tests passing
- ✅ No broken functionality

---

## Future Additions

Additional features and enhancements are documented in:
**[`/specs/subscription_tiers_additions.md`](/specs/subscription_tiers_additions.md)**

Including:
- Email notifications
- Enhanced webhook processing
- Documentation (admin, developer, end-user)
- PayPal integration
- Additional admin tools
- Testing enhancements
