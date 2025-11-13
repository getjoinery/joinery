# Deletion System Migration - Phase 2: Concrete Migration Plan

## Overview

This document provides **explicit, line-by-line migration instructions**. No discovery scripts needed - just follow the checklist.

**Key Principle**: The new system defaults to CASCADE. Only exceptions (prevent, null, set_value) need to be declared in child models.

## Migration Summary

- **Step 1**: Remove ALL `$permanent_delete_actions` from parent models (65 models)
- **Step 2**: Add `$foreign_key_actions` ONLY to child models with exceptions (15 child models, ~35-40 rules)
- **Step 3**: Run `update_database.php` to regenerate deletion rules
- **Step 4**: Test

---

## Step 1: Remove from Parent Models

**Remove the entire `$permanent_delete_actions` declaration from these files:**

### Core Models (28 models with rules to remove)

```bash
# Simple removal - entire array
/data/users_class.php:28-66
/data/products_class.php:592-597
/data/orders_class.php:21-26
/data/events_class.php:55-58
/data/event_sessions_class.php:53
/data/pages_class.php:20
/data/emails_class.php:19
/data/locations_class.php:18
/data/mailing_lists_class.php:31
/data/coupon_codes_class.php:19
/data/event_types_class.php:17
/data/product_groups_class.php:17
/data/groups_class.php:20
/data/event_registrants_class.php:41
/data/email_templates_class.php:17
/data/videos_class.php:17
/data/files_class.php:20
/data/order_items_class.php:24
/data/product_requirements_class.php:21
/data/posts_class.php:22
/data/questions_class.php:20
/data/surveys_class.php:19
/data/survey_questions_class.php:19
/data/comments_class.php:15
/data/admin_menus_class.php:18
/data/plugins_class.php:14
/data/plugin_versions_class.php:14
/data/upgrades_class.php:14
/data/phone_number_class.php:18
```

### Models with Empty Arrays (37 models - just remove the line)

```bash
/data/activation_codes_class.php:17
/data/address_class.php:19
/data/api_keys_class.php:17
/data/components_class.php:17
/data/contact_types_class.php:17
/data/coupon_code_uses_class.php:17
/data/coupon_code_products_class.php:20
/data/debug_email_logs_class.php:17
/data/email_recipient_groups_class.php:17
/data/email_recipients_class.php:17
/data/event_logs_class.php:17
/data/event_session_files_class.php:17
/data/event_waiting_lists_class.php:19
/data/general_errors_class.php:17
/data/group_members_class.php:19
/data/log_form_errors_class.php:17
/data/mailing_list_registrants_class.php:19
/data/messages_class.php:14
/data/migrations_class.php:17
/data/order_item_requirements_class.php:21
/data/page_contents_class.php:17
/data/product_details_class.php:14
/data/product_requirement_instances_class.php:21
/data/product_versions_class.php:18
/data/public_menus_class.php:17
/data/queued_email_class.php:19
/data/session_analytics_class.php:17
/data/settings_class.php:17
/data/stripe_invoices_class.php:17
/data/survey_answers_class.php:19
/data/themes_class.php:71
/data/urls_class.php:18
/data/visitor_events_class.php:17
/data/plugin_dependencies_class.php:14
/data/plugin_migrations_class.php:14
```

**Total removals: 65 files**

---

## Step 2: Add to Child Models

Only add `$foreign_key_actions` to child models that have **exception rules** (prevent, null, set_value).

### 2.1: Set_Value Rules (User::USER_DELETED)

**Parent: User** → Add to 21 child models

#### Order (`/data/orders_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### OrderItem (`/data/order_items_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'odi_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Email (`/data/emails_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'eml_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Event (`/data/events_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'evt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
    'evt_usr_user_id_leader' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Post (`/data/posts_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'pst_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Video (`/data/videos_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'vid_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### File (`/data/files_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'fil_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Message (`/data/messages_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'msg_usr_user_id_sender' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Group (`/data/groups_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'grp_usr_user_id_created' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Booking (`/plugins/bookings/data/bookings_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'bkn_usr_user_id_booked' => ['action' => 'set_value', 'value' => User::USER_DELETED],
    'bkn_usr_user_id_client' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### ClientSession (`/data/client_sessions_class.php`)
```php
// Add after $field_specifications (if file exists - check table prefix 'cls'):
protected static $foreign_key_actions = [
    'cls_usr_user_id_billing' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Conversation (`/data/conversations_class.php`)
```php
// Add after $field_specifications (if file exists - check table prefix 'cnv'):
protected static $foreign_key_actions = [
    'cnv_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### GeneralError (`/data/general_errors_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'err_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### PageContent (`/data/page_contents_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'pac_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### StripeInvoice (`/data/stripe_invoices_class.php`)
```php
// Add after $field_specifications (check column name - 'siv'):
protected static $foreign_key_actions = [
    'siv_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### Setting (`/data/settings_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'stg_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### SurveyAnswer (`/data/survey_answers_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'sva_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### VisitorEvent (`/data/visitor_events_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'vse_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

#### ProductDetail (`/data/product_details_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'prd_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];
```

**User set_value rules: 21 child models updated**

---

### 2.2: Prevent Rules

#### OrderItem (`/data/order_items_class.php`)
```php
// Add to existing $foreign_key_actions or create new:
protected static $foreign_key_actions = [
    'odi_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],  // From above
    'odi_pro_product_id' => ['action' => 'prevent', 'message' => 'Cannot delete product - order items exist'],
    'odi_evr_event_registrant_id' => ['action' => 'prevent', 'message' => 'Cannot delete event registration - order items exist']
];
```

#### MailingListRegistrant (`/data/mailing_list_registrants_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'mlr_mlt_mailing_list_id' => ['action' => 'prevent', 'message' => 'Cannot delete mailing list - registrants exist']
];
```

#### CouponCodeProduct (`/data/coupon_code_products_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'ccp_ccd_coupon_code_id' => ['action' => 'prevent', 'message' => 'Cannot delete coupon code - products exist']
];
```

#### Event (`/data/events_class.php`)
```php
// Add to existing $foreign_key_actions:
protected static $foreign_key_actions = [
    'evt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],  // From above
    'evt_usr_user_id_leader' => ['action' => 'set_value', 'value' => User::USER_DELETED],  // From above
    'evt_ety_event_type_id' => ['action' => 'prevent', 'message' => 'Cannot delete event type - events exist']
];
```

#### Product (`/data/products_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'pro_prg_product_group_id' => ['action' => 'prevent', 'message' => 'Cannot delete product group - products exist']
];
```

#### EventRegistrant (`/data/event_registrants_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'evr_grp_group_id' => ['action' => 'prevent', 'message' => 'Cannot delete group - event registrations exist']
];
```

#### MailingList (`/data/mailing_lists_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'mlt_emt_email_template_id' => ['action' => 'prevent', 'message' => 'Cannot delete email template - mailing lists exist']
];
```

#### EventSession (`/data/event_sessions_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'evs_vid_video_id' => ['action' => 'prevent', 'message' => 'Cannot delete video - event sessions exist']
];
```

#### EventSessionFile (`/data/event_session_files_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'esf_fil_file_id' => ['action' => 'prevent', 'message' => 'Cannot delete file - event sessions exist']
];
```

#### PluginDependency (`/data/plugin_dependencies_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'pdp_plg_plugin_id_dependee' => ['action' => 'prevent', 'message' => 'Cannot delete plugin - dependencies exist'],
    'pdp_plv_plugin_version_id' => ['action' => 'prevent', 'message' => 'Cannot delete plugin version - dependencies exist']
];
```

#### Upgrade (`/data/upgrades_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'upg_upg_upgrade_id_requires' => ['action' => 'prevent', 'message' => 'Cannot delete upgrade - required by other upgrades']
];
```

**Prevent rules: 12 rules across 11 child models**

---

### 2.3: Null Rules

#### EventRegistrant (`/data/event_registrants_class.php`)
```php
// Add to existing $foreign_key_actions:
protected static $foreign_key_actions = [
    'evr_grp_group_id' => ['action' => 'prevent', 'message' => 'Cannot delete group - event registrations exist'],  // From above
    'evr_ord_order_id' => ['action' => 'null']
];
```

#### CouponCodeUse (`/data/coupon_code_uses_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'ccu_ord_order_id' => ['action' => 'null']
];
```

#### Event (`/data/events_class.php`)
```php
// Add to existing $foreign_key_actions:
protected static $foreign_key_actions = [
    'evt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],  // From above
    'evt_usr_user_id_leader' => ['action' => 'set_value', 'value' => User::USER_DELETED],  // From above
    'evt_ety_event_type_id' => ['action' => 'prevent', 'message' => 'Cannot delete event type - events exist'],  // From above
    'evt_loc_location_id' => ['action' => 'null']
];
```

#### AdminMenu (`/data/admin_menus_class.php`)
```php
// Add after $field_specifications:
protected static $foreign_key_actions = [
    'adm_adm_admin_menu_id_parent' => ['action' => 'null']  // Self-referencing
];
```

**Null rules: 4 rules across 4 child models**

---

## Step 3: Verification Checklist

### Files to Modify

**Parent Models - Remove `$permanent_delete_actions` (65 files):**
- [ ] 28 core models with rules
- [ ] 37 models with empty arrays

**Child Models - Add `$foreign_key_actions` (15 unique files):**
- [ ] Order
- [ ] OrderItem (combine set_value + prevent rules)
- [ ] Email
- [ ] Event (combine set_value + prevent + null rules)
- [ ] Post
- [ ] Video
- [ ] File
- [ ] Message
- [ ] Group
- [ ] Booking (plugin)
- [ ] ClientSession (if exists)
- [ ] Conversation (if exists)
- [ ] GeneralError
- [ ] PageContent
- [ ] StripeInvoice
- [ ] Setting
- [ ] SurveyAnswer
- [ ] VisitorEvent
- [ ] ProductDetail
- [ ] MailingListRegistrant
- [ ] CouponCodeProduct
- [ ] Product
- [ ] EventRegistrant (combine prevent + null rules)
- [ ] MailingList
- [ ] EventSession
- [ ] EventSessionFile
- [ ] PluginDependency
- [ ] Upgrade
- [ ] CouponCodeUse
- [ ] AdminMenu

**Total: 15 child models, 65 parent models = 80 files**

---

## Step 4: Migration Process

### 4.1 Backup
```bash
cd /var/www/html/joinerytest
tar -czf backup_deletion_migration_$(date +%Y%m%d_%H%M%S).tar.gz public_html/
```

### 4.2 Execute Changes

**Option A: Manual** - Follow the code blocks above for each file

**Option B: Script** - Create a simple script that applies all changes:

```bash
#!/bin/bash
# Apply all removals and additions according to spec
# (Could create sed/awk commands for each change)
```

### 4.3 Run Update Database
```bash
cd /var/www/html/joinerytest/public_html/utils
php update_database.php
```

This will:
1. Create `del_deletion_rules` table if needed
2. Auto-detect ALL foreign keys (cascade by default)
3. Register exception rules from `$foreign_key_actions`

### 4.4 Validate
```bash
# Check deletion rules table
psql -U postgres -d joinerytest -c "SELECT del_source_table, del_target_table, del_action, COUNT(*)
FROM del_deletion_rules
GROUP BY del_source_table, del_target_table, del_action
ORDER BY del_source_table;"

# Expected:
# - usr_users has ~21 set_value rules
# - Various tables have prevent rules (~12)
# - Various tables have null rules (~4)
# - MANY tables have cascade rules (auto-detected)
```

### 4.5 Test Key Deletions

Test these scenarios:

**1. User deletion (set_value):**
```php
$user = new User($test_user_id, true);
$dry_run = $user->permanent_delete_dry_run();
// Should show: many dependencies set to User::USER_DELETED
```

**2. Product deletion (prevent):**
```php
$product = new Product($product_with_orders_id, true);
try {
    $product->permanent_delete();
    // Should throw error: "Cannot delete - order items exist"
} catch (SystemDisplayableError $e) {
    echo "✓ Prevent working: " . $e->getMessage();
}
```

**3. Order deletion (null):**
```php
$order = new Order($order_id, true);
$dry_run = $order->permanent_delete_dry_run();
// Should show: event registrants and coupon uses set to NULL
```

**4. Event deletion (cascade - default):**
```php
$event = new Event($event_id, true);
$dry_run = $event->permanent_delete_dry_run();
// Should show: event sessions CASCADE deleted (auto-detected, not declared)
```

---

## Summary

**What We're Doing:**
1. Remove ALL `$permanent_delete_actions` from 65 parent models
2. Add `$foreign_key_actions` to 15 child models (~37 total rules)
3. Let auto-detection handle the remaining ~30+ relationships (cascade by default)

**Time Estimate:**
- Backup: 5 minutes
- Code changes: 60-90 minutes (manual) or 10 minutes (scripted)
- Database update: 2 minutes
- Testing: 20 minutes
- **Total: 1.5-2 hours**

**Why This Works:**
- No discovery scripts needed - explicit instructions
- No cross-model calculations at runtime - pre-analyzed
- Clear success criteria - count the files modified
- Reversible - we have explicit backup
