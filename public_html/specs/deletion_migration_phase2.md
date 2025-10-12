# Deletion System Migration - Phase 2 Specification

## Overview

Phase 2 migrates all existing models from the old `$permanent_delete_actions` system to the new auto-detected foreign key system implemented in Phase 1.

**KEY INSIGHT**: The new system defaults to **CASCADE** for all auto-detected foreign keys. You **ONLY declare exceptions** to this default behavior.

### What Requires Migration:

**✅ MUST MIGRATE (exceptions to cascade):**
- `'prevent'` → Blocks deletion if dependents exist
- `'null'` → Sets foreign key to NULL instead of deleting
- `set_value` (e.g., `User::USER_DELETED`) → Sets foreign key to specific value

**❌ NO MIGRATION NEEDED (use default):**
- `'delete'` → **Remove, don't migrate** (cascade is now the default!)
- `'skip'` → Remove, don't declare anything

### Migration Process:

1. **Scanning** all models for existing `$permanent_delete_actions` declarations
2. **Identifying** only non-default actions (prevent, null, set_value)
3. **Mapping** exceptions to appropriate child model's `$foreign_key_actions`
4. **Removing** all `$permanent_delete_actions` arrays (both empty and with 'delete' rules)
5. **Validating** that behavior is preserved

## Current State Analysis (Research Findings)

### Summary

Based on codebase analysis of all 65+ models with `$permanent_delete_actions`:

**Models Requiring Actual Migration (~15-20 models):**
- User - Multiple `User::USER_DELETED` set_value rules
- Product, Order, Location, AdminMenu - Mix of prevent/null rules
- ~10 models with 'prevent' rules (MailingList, CouponCode, EventType, ProductGroup, etc.)

**Models Requiring Only Cleanup (~45+ models):**
- Models with ONLY 'delete' rules → Just remove the array (cascade is default)
- Models with empty arrays → Just remove the empty declaration

**Models Requiring Manual Review (6 models):**
- User, File, Event, Order, Post, ContentVersions - Have custom `permanent_delete()` methods

### Detailed Model Analysis

The following models have `$permanent_delete_actions` declarations. **Note**: Models with ONLY 'delete' actions don't need migration to child models - just removal!

#### High-Impact Models (Many Rules)

**User (38 deletion rules)** - `/data/users_class.php:28-66`
- **Has custom permanent_delete()** - Removes from mailing lists, checks for system users
- ~18-20 rules need migration (User::USER_DELETED set_value)
- ~18-20 rules are 'delete' (skip - cascade is default)
- Examples:
  - ✅ `ord_usr_user_id => User::USER_DELETED` → **MIGRATE** (set_value)
  - ❌ `act_usr_user_id => 'delete'` → **SKIP** (cascade is default)
  - ✅ `msg_usr_user_id_sender => User::USER_DELETED` → **MIGRATE** (set_value)
  - ❌ `evr_usr_user_id => 'delete'` → **SKIP** (cascade is default)

#### Medium-Impact Models (3-4 Rules)

**Product** - `/data/products_class.php:592-597`
- 1 rule needs migration, 3 rules are 'delete'
- ❌ `prd_pro_product_id => 'delete'` → **SKIP** (cascade is default)
- ❌ `prv_pro_product_id => 'delete'` → **SKIP** (cascade is default)
- ❌ `ccp_pro_product_id => 'delete'` → **SKIP** (cascade is default)
- ✅ `odi_pro_product_id => 'prevent'` → **MIGRATE** (prevent deletion)

**Order** - `/data/orders_class.php:21-26`
- **Has custom permanent_delete()** method
- 2 rules need migration (null), 2 rules are 'delete'
- ❌ `odi_ord_order_id => 'delete'` → **SKIP** (cascade is default)
- ❌ `cls_ord_order_id => 'delete'` → **SKIP** (cascade is default)
- ✅ `evr_ord_order_id => 'null'` → **MIGRATE** (set to NULL)
- ✅ `ccu_ord_order_id => 'null'` → **MIGRATE** (set to NULL)

#### Low-Impact Models (1-2 Rules)

**Models with ONLY 'delete' (just remove, no migration):**
- **Event** - ❌ 1 'delete' rule → **SKIP**
- **EventSession** - ❌ 1 'delete' rule → **SKIP**
- **Page** - ❌ 1 'delete' rule → **SKIP**
- **Email** - ❌ 1 'delete' rule → **SKIP**
- **OrderItem** - ❌ 1 'delete' rule → **SKIP**
- **ProductRequirement** - ❌ 1 'delete' rule → **SKIP**
- **Post** - ❌ 1 'delete' rule → **SKIP**
- **Question** - ❌ 1 'delete' rule → **SKIP**
- **Survey** - ❌ 1 'delete' rule → **SKIP**
- **SurveyQuestion** - ❌ 1 'delete' rule → **SKIP**
- **Comment** - ❌ 1 'delete' rule (self-referencing) → **SKIP**

**Models with exception rules that NEED migration:**
- **Location** - ✅ `evt_loc_location_id => 'null'` → **MIGRATE**
- **AdminMenu** - ✅ `adm_adm_admin_menu_id_parent => 'null'` → **MIGRATE**
- **MailingList** - ✅ `mlr_mlt_mailing_list_id => 'prevent'` → **MIGRATE**
- **CouponCode** - ✅ `ccp_ccd_coupon_code_id => 'prevent'` → **MIGRATE**
- **EventType** - ✅ `evt_ety_event_type_id => 'prevent'` → **MIGRATE**
- **ProductGroup** - ✅ `pro_prg_product_group_id => 'prevent'` → **MIGRATE**
- **Group** - ✅ `evr_grp_group_id => 'prevent'` → **MIGRATE** (+ 1 'delete' to skip)
- **EventRegistrant** - ✅ `odi_evr_event_registrant_id => 'prevent'` → **MIGRATE**
- **EmailTemplate** - ✅ `mlt_emt_email_template_id => 'prevent'` → **MIGRATE**
- **Video** - ✅ `evs_vid_video_id => 'prevent'` → **MIGRATE**
- **File** - ✅ `esf_fil_file_id => 'prevent'` → **MIGRATE** (has custom permanent_delete)
- **Plugin** - ✅ `pdp_plg_plugin_id_dependee => 'prevent'` → **MIGRATE** (+ 2 'delete' to skip)
- **PluginVersion** - ✅ `pdp_plv_plugin_version_id => 'prevent'` → **MIGRATE**
- **Upgrade** - ✅ `upg_upg_upgrade_id_requires => 'prevent'` → **MIGRATE**
- **PhoneNumber** - ❌ `act_activation_codes => 'delete'` → **SKIP** (even if malformed, 'delete' = just remove)

### Models with Empty Arrays

The following **37 models** have empty `$permanent_delete_actions` arrays and can simply have the line removed:
- activation_codes, address, admin_menus, api_keys, components, contact_types
- coupon_code_uses, debug_email_logs, email_recipient_groups, email_recipients
- event_logs, event_session_files, event_waiting_lists, general_errors
- group_members, log_form_errors, locations, mailing_list_registrants
- messages, migrations, order_item_requirements, page_contents
- phone_number, product_details, product_requirement_instances, product_versions
- public_menus, queued_email, session_analytics, settings, stripe_invoices
- survey_answers, themes, urls, visitor_events

### Models with Custom permanent_delete() Methods

**6 models** have custom `permanent_delete()` implementations that need review:

1. **User** (`/data/users_class.php:754`)
   - Checks for USER_SYSTEM and USER_DELETED constants
   - Removes user from all mailing lists
   - Calls `parent::permanent_delete()`
   - **Action**: Preserve custom logic, update parent call

2. **File** (`/data/files_class.php:143`)
   - Deletes physical file from upload directory
   - Deletes resized image versions
   - Calls `parent::permanent_delete()`
   - **Action**: Preserve custom logic, update parent call

3. **Event** (`/data/events_class.php:516`)
   - Custom deletion logic for registrations
   - Calls `parent::permanent_delete()`
   - **Action**: Preserve custom logic, update parent call

4. **Order** (`/data/orders_class.php`)
   - Has custom permanent_delete() override
   - **Action**: Review and preserve

5. **Post** (`/data/posts_class.php`)
   - Has custom permanent_delete() override
   - **Action**: Review and preserve

6. **ContentVersions** (`/data/content_versions_class.php`)
   - Has custom permanent_delete() override
   - **Action**: Review and preserve

### Plugin Models

**10 plugin model classes found** in `/plugins/`:
- controld: ctlddevices, ctldprofiles, ctldrules, ctldservices, ctldfilters, ctlddevice_backups
- items: items, item_relations, item_relation_types
- bookings: bookings, booking_types

All plugin models currently have **empty** `$permanent_delete_actions` arrays.

### Migration Statistics

**IMPORTANT**: Most rules are `'delete'` which DON'T need migration (cascade is the default)!

- **Total data models with permanent_delete_actions**: ~65
- **Models with non-empty rules**: 28
- **Total rules that ACTUALLY need migration**: ~35-40 (only prevent, null, set_value)
  - `'prevent'` rules: ~12
  - `'null'` rules: ~5
  - `'set_value'` rules (User::USER_DELETED, etc.): ~18-20
- **Rules that DON'T need migration** (just remove): ~25-30 ('delete' rules)
- **Models with custom permanent_delete()**: 6
- **Plugin models**: 10 (all empty)
- **Models with empty arrays** (simple removal): 37

### Edge Cases Identified

1. **Constant Values**
   - User::USER_DELETED constant (value 3) used extensively
   - Need to preserve these as constant references, not literal values

2. **Nested Dependencies**
   - Comments can have parent comments (self-referencing)
   - AdminMenus can have parent menus (self-referencing)

## Migration Strategy

**Simplified Migration**: Since 'delete' rules don't need migration, the actual work is much smaller than initially estimated!

### Phase 2A: Automated Migration (Primary Focus)

Migrates **ONLY** the exception rules:
- Models with `'prevent'`, `'null'`, or `set_value` actions
- Auto-detects these and creates `$foreign_key_actions` in child models
- Removes all `$permanent_delete_actions` from parent models

**Estimated Coverage**: ~15-20 models with ~35-40 actual rules to migrate

### Phase 2B: Cleanup (Majority of Models)

For models that ONLY have `'delete'` or empty arrays:
- Simply remove the `$permanent_delete_actions` declaration
- No child model updates needed - cascade is the default!

**Estimated Coverage**: ~45+ models (just removal, no migration)

### Phase 2C: Manual Review (Secondary)

Requires human review for:
- Models with custom permanent_delete() methods (6 models)

**Estimated Coverage**: 6 models

## Implementation Details

### 1. Discovery Script

**File**: `/utils/migration_phase2_discover.php`

```php
<?php
/**
 * Deletion Migration Phase 2 - Discovery Script
 *
 * Scans all models and identifies migration requirements
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

class DeletionMigrationDiscovery {
    private $models_to_migrate = [];
    private $custom_permanent_delete_methods = [];
    private $empty_arrays = [];

    /**
     * Scan all models and build migration plan
     */
    public function discover() {
        echo "=== DELETION MIGRATION PHASE 2: DISCOVERY ===\n\n";

        // Get all model classes
        $classes = LibraryFunctions::discover_model_classes([
            'require_tablename' => true,
            'require_field_specifications' => true,
            'include_plugins' => true,
            'verbose' => false
        ]);

        echo "Found " . count($classes) . " model classes\n\n";

        foreach ($classes as $class) {
            $this->analyzeModel($class);
        }

        $this->generateReport();
    }

    /**
     * Analyze a single model for migration requirements
     */
    private function analyzeModel($class) {
        $reflection = new ReflectionClass($class);

        // Check for custom permanent_delete() override
        if ($reflection->hasMethod('permanent_delete')) {
            $method = $reflection->getMethod('permanent_delete');
            if ($method->getDeclaringClass()->getName() === $class) {
                $this->custom_permanent_delete_methods[] = [
                    'class' => $class,
                    'file' => $reflection->getFileName()
                ];
            }
        }

        // Check for $permanent_delete_actions
        try {
            $actions = $reflection->getStaticPropertyValue('permanent_delete_actions', []);

            if (empty($actions)) {
                // Track empty arrays for cleanup
                $this->empty_arrays[] = [
                    'class' => $class,
                    'file' => $reflection->getFileName()
                ];
            } else {
                $this->analyzeDeletionActions($class, $actions, $reflection);
            }
        } catch (ReflectionException $e) {
            // No permanent_delete_actions property
        }
    }

    /**
     * Analyze deletion actions and map to child models
     */
    private function analyzeDeletionActions($parent_class, $actions, $reflection) {
        $parent_table = $reflection->getStaticPropertyValue('tablename');

        $migration_plan = [
            'parent_class' => $parent_class,
            'parent_table' => $parent_table,
            'file' => $reflection->getFileName(),
            'rules' => []
        ];

        foreach ($actions as $column => $action) {
            $rule_info = $this->mapActionToChildModel($column, $action, $parent_table);

            if ($rule_info) {
                $migration_plan['rules'][] = $rule_info;
            }
            // Note: If rule_info is null, it means the action is 'delete' or 'skip'
            // which don't need migration - they're just removed from parent
        }

        if (!empty($migration_plan['rules'])) {
            $this->models_to_migrate[] = $migration_plan;
        }
    }

    /**
     * Map a deletion action to its child model
     */
    private function mapActionToChildModel($column, $action, $parent_table) {
        // Find the table that contains this column
        $tables_and_columns = LibraryFunctions::get_tables_and_columns();

        $target_table = null;
        foreach ($tables_and_columns as $table_name => $columns) {
            if (in_array($column, $columns)) {
                $target_table = $table_name;
                break;
            }
        }

        if (!$target_table) {
            return null;
        }

        // Find the model class for this table
        $child_class = $this->findModelForTable($target_table);

        if (!$child_class) {
            return null;
        }

        // Convert action format
        $converted_action = $this->convertActionFormat($action);

        return [
            'column' => $column,
            'target_table' => $target_table,
            'child_class' => $child_class,
            'old_action' => $action,
            'new_action' => $converted_action
        ];
    }

    /**
     * Find the model class for a given table name
     */
    private function findModelForTable($table_name) {
        $classes = LibraryFunctions::discover_model_classes([
            'require_tablename' => true,
            'include_plugins' => true,
            'verbose' => false
        ]);

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $tablename = $reflection->getStaticPropertyValue('tablename', null);

            if ($tablename === $table_name) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Convert old action format to new foreign_key_actions format
     *
     * IMPORTANT: 'delete' actions return NULL (don't migrate) because
     * CASCADE is now the DEFAULT behavior for auto-detected foreign keys.
     * We ONLY migrate exceptions to the default (prevent, null, set_value).
     */
    private function convertActionFormat($action) {
        if ($action === 'delete') {
            // Don't migrate - cascade is the default in the new system
            return null;
        } elseif ($action === 'null') {
            return ['action' => 'null'];
        } elseif ($action === 'prevent') {
            return ['action' => 'prevent', 'message' => 'Cannot delete - dependent records exist'];
        } elseif ($action === 'skip') {
            // Skip means don't process - in new system, just don't declare it
            return null;
        } else {
            // It's a value to set - check if it's a constant
            if (defined($action) || strpos($action, '::') !== false) {
                // It's a constant reference
                return ['action' => 'set_value', 'value' => $action];
            } else {
                // It's a literal value
                return ['action' => 'set_value', 'value' => $action];
            }
        }
    }

    /**
     * Generate migration report
     */
    private function generateReport() {
        echo "=== MIGRATION REPORT ===\n\n";

        echo "Models requiring migration: " . count($this->models_to_migrate) . "\n";
        echo "Models with empty arrays (cleanup): " . count($this->empty_arrays) . "\n";
        echo "Custom permanent_delete() methods: " . count($this->custom_permanent_delete_methods) . "\n\n";

        if (!empty($this->models_to_migrate)) {
            echo "--- MODELS TO MIGRATE ---\n\n";
            $total_rules = 0;
            $rules_to_migrate = 0;
            $rules_skipped = 0;

            foreach ($this->models_to_migrate as $model) {
                $rule_count = count($model['rules']);
                $total_rules += $rule_count;

                // Count actual migrations vs skipped
                $model_migrate = 0;
                $model_skip = 0;
                foreach ($model['rules'] as $rule) {
                    if ($rule['new_action'] !== null) {
                        $model_migrate++;
                        $rules_to_migrate++;
                    } else {
                        $model_skip++;
                        $rules_skipped++;
                    }
                }

                echo "Parent: {$model['parent_class']} ({$model['parent_table']})\n";
                echo "File: {$model['file']}\n";
                echo "Rules: $rule_count ($model_migrate to migrate, $model_skip to skip)\n";

                foreach ($model['rules'] as $rule) {
                    echo "  - Column: {$rule['column']}\n";
                    echo "    Target: {$rule['child_class']} ({$rule['target_table']})\n";
                    echo "    Old action: " . var_export($rule['old_action'], true) . "\n";
                    if ($rule['new_action']) {
                        echo "    New action: " . var_export($rule['new_action'], true) . " ✓ MIGRATE\n";
                    } else {
                        echo "    New action: (skip - cascade is default) ✗ SKIP\n";
                    }
                }
                echo "\n";
            }
            echo "Total rules analyzed: $total_rules\n";
            echo "Rules to migrate: $rules_to_migrate (prevent, null, set_value)\n";
            echo "Rules to skip: $rules_skipped (delete - cascade is default)\n\n";
        }

        if (!empty($this->empty_arrays)) {
            echo "--- EMPTY ARRAYS (CLEANUP ONLY) ---\n";
            echo "These models have empty arrays that can be removed:\n\n";
            foreach ($this->empty_arrays as $model) {
                echo "  - {$model['class']}\n";
            }
            echo "\n";
        }

        if (!empty($this->custom_permanent_delete_methods)) {
            echo "--- CUSTOM PERMANENT_DELETE() METHODS ---\n";
            echo "These models have custom permanent_delete() implementations that need manual review:\n\n";
            foreach ($this->custom_permanent_delete_methods as $method) {
                echo "  - {$method['class']}\n";
                echo "    File: {$method['file']}\n";
            }
            echo "\n";
        }

        // Save detailed report to JSON
        $report_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'models_to_migrate' => $this->models_to_migrate,
            'empty_arrays' => $this->empty_arrays,
            'custom_methods' => $this->custom_permanent_delete_methods,
            'statistics' => [
                'models_to_migrate' => count($this->models_to_migrate),
                'empty_arrays' => count($this->empty_arrays),
                'custom_methods' => count($this->custom_permanent_delete_methods)
            ]
        ];

        $report_file = PathHelper::getIncludePath('utils/deletion_migration_report.json');
        file_put_contents($report_file, json_encode($report_data, JSON_PRETTY_PRINT));

        echo "Detailed report saved to: $report_file\n";
    }
}

// Run discovery if not included from another script
if (!isset($no_autorun)) {
    $discovery = new DeletionMigrationDiscovery();
    $discovery->discover();
}
?>
```

### 2. Migration Script

**File**: `/utils/migration_phase2_execute.php`

```php
<?php
/**
 * Deletion Migration Phase 2 - Execution Script
 *
 * Performs the actual code transformation
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

class DeletionMigrationExecutor {
    private $report_data;
    private $dry_run;
    private $modifications = [];

    public function __construct($dry_run = true) {
        $this->dry_run = $dry_run;

        // Load the discovery report
        $report_file = PathHelper::getIncludePath('utils/deletion_migration_report.json');
        if (!file_exists($report_file)) {
            throw new Exception("Migration report not found. Run migration_phase2_discover.php first.");
        }

        $this->report_data = json_decode(file_get_contents($report_file), true);
    }

    /**
     * Execute the migration
     */
    public function execute() {
        echo "=== DELETION MIGRATION PHASE 2: EXECUTION ===\n";
        echo "Mode: " . ($this->dry_run ? "DRY RUN" : "LIVE") . "\n\n";

        if (!$this->dry_run) {
            echo "WARNING: This will modify your model files!\n";
            echo "Type 'yes' to continue: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            if (trim($line) !== 'yes') {
                echo "Migration cancelled.\n";
                return;
            }
            fclose($handle);
        }

        // Process each model
        foreach ($this->report_data['models_to_migrate'] as $model) {
            $this->migrateModel($model);
        }

        // Process cleanup (empty arrays)
        foreach ($this->report_data['empty_arrays'] as $model) {
            $this->cleanupEmptyArray($model);
        }

        echo "\n=== MIGRATION COMPLETE ===\n";
        echo "Models migrated: " . count($this->report_data['models_to_migrate']) . "\n";
        echo "Models cleaned up: " . count($this->report_data['empty_arrays']) . "\n";
        echo "Files modified: " . count($this->modifications) . "\n";

        if (!empty($this->modifications)) {
            echo "\nModified files:\n";
            foreach ($this->modifications as $file => $changes) {
                echo "  - $file\n";
                echo "    Changes: " . count($changes) . "\n";
            }
        }

        if (!empty($this->report_data['custom_methods'])) {
            echo "\n⚠️  MANUAL REVIEW REQUIRED:\n";
            echo count($this->report_data['custom_methods']) . " model(s) with custom permanent_delete() methods\n";
            echo "These need manual review to ensure custom logic is preserved.\n";
        }
    }

    /**
     * Migrate a single model
     */
    private function migrateModel($model) {
        echo "Migrating {$model['parent_class']}...\n";

        // Step 1: Add foreign_key_actions to child models
        foreach ($model['rules'] as $rule) {
            if ($rule['new_action'] !== null) {
                $this->addForeignKeyActionToChild($rule);
            }
        }

        // Step 2: Remove permanent_delete_actions from parent
        $this->removePermanentDeleteActions($model);

        echo "  ✓ Migrated\n";
    }

    /**
     * Add foreign_key_actions to a child model
     */
    private function addForeignKeyActionToChild($rule) {
        echo "  Adding rule to {$rule['child_class']}...\n";

        // Load the child model file
        $reflection = new ReflectionClass($rule['child_class']);
        $file_path = $reflection->getFileName();
        $content = file_get_contents($file_path);

        // Check if foreign_key_actions already exists
        if (strpos($content, 'protected static $foreign_key_actions') !== false ||
            strpos($content, 'public static $foreign_key_actions') !== false) {
            // Need to add to existing array
            $this->addToExistingArray($file_path, $content, $rule);
        } else {
            // Need to add new property
            $this->addNewForeignKeyActions($file_path, $content, $rule);
        }
    }

    /**
     * Add to existing foreign_key_actions array
     */
    private function addToExistingArray($file_path, $content, $rule) {
        // Find the foreign_key_actions array
        $pattern = '/(protected|public)\s+static\s+\$foreign_key_actions\s*=\s*(\[|array\()(.*?)(\]|\));/s';

        if (preg_match($pattern, $content, $matches)) {
            $array_content = $matches[3];

            // Build new entry
            $new_entry = $this->buildArrayEntry($rule);

            // Add to array (handle trailing comma)
            $array_content = rtrim($array_content);
            if (!empty($array_content) && substr($array_content, -1) !== ',') {
                $array_content .= ',';
            }
            $array_content .= "\n\t\t" . $new_entry;

            // Replace in content
            $new_array = $matches[1] . ' static $foreign_key_actions = ' . $matches[2] . $array_content . "\n\t" . $matches[4];
            $new_content = str_replace($matches[0], $new_array, $content);

            if (!$this->dry_run) {
                // Backup original
                copy($file_path, $file_path . '.pre_migration');
                file_put_contents($file_path, $new_content);
            }

            $this->modifications[$file_path][] = "Added rule for {$rule['column']}";
        }
    }

    /**
     * Add new foreign_key_actions property
     */
    private function addNewForeignKeyActions($file_path, $content, $rule) {
        // Find where to insert (after $field_specifications or $tablename)
        $insert_after = null;

        // Try after field_specifications (using non-greedy matching)
        if (preg_match('/public\s+static\s+\$field_specifications\s*=\s*(\[|array\()[^\]]*?(\]|\));/sU', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insert_after = $matches[0][1] + strlen($matches[0][0]);
        } elseif (preg_match('/public\s+static\s+\$tablename\s*=\s*[^;]+;/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insert_after = $matches[0][1] + strlen($matches[0][0]);
        }

        if ($insert_after !== null) {
            $new_entry = $this->buildArrayEntry($rule);
            $new_property = "\n\n\tprotected static \$foreign_key_actions = [\n\t\t" . $new_entry . "\n\t];";

            $new_content = substr($content, 0, $insert_after) . $new_property . substr($content, $insert_after);

            if (!$this->dry_run) {
                // Backup original
                copy($file_path, $file_path . '.pre_migration');
                file_put_contents($file_path, $new_content);
            }

            $this->modifications[$file_path][] = "Added foreign_key_actions with rule for {$rule['column']}";
        }
    }

    /**
     * Build array entry for foreign_key_actions
     */
    private function buildArrayEntry($rule) {
        $action = $rule['new_action'];
        $parts = [];

        $parts[] = "'action' => '" . $action['action'] . "'";

        if (isset($action['value'])) {
            // Preserve constant references (like User::USER_DELETED)
            $value = $rule['old_action']; // Use original to preserve constants
            if (is_numeric($value)) {
                $parts[] = "'value' => " . $value;
            } else {
                $parts[] = "'value' => " . $value; // Assume it's a constant
            }
        }

        if (isset($action['message'])) {
            $parts[] = "'message' => '" . addslashes($action['message']) . "'";
        }

        return "'{$rule['column']}' => [" . implode(', ', $parts) . "]";
    }

    /**
     * Remove permanent_delete_actions from parent model
     */
    private function removePermanentDeleteActions($model) {
        echo "  Removing permanent_delete_actions from {$model['parent_class']}...\n";

        $file_path = $model['file'];
        $content = file_get_contents($file_path);

        // Find and remove the entire permanent_delete_actions declaration
        $pattern = '/public\s+static\s+\$permanent_delete_actions\s*=\s*(\[|array\().*?(\]|\));/s';

        if (preg_match($pattern, $content, $matches)) {
            // Remove the declaration
            $new_content = str_replace($matches[0], '// permanent_delete_actions removed - migrated to child models via foreign_key_actions', $content);

            if (!$this->dry_run) {
                // Backup if not already done
                if (!file_exists($file_path . '.pre_migration')) {
                    copy($file_path, $file_path . '.pre_migration');
                }
                file_put_contents($file_path, $new_content);
            }

            $this->modifications[$file_path][] = "Removed permanent_delete_actions";
        }
    }

    /**
     * Cleanup empty permanent_delete_actions arrays
     */
    private function cleanupEmptyArray($model) {
        echo "Cleaning up {$model['class']}...\n";

        $file_path = $model['file'];
        $content = file_get_contents($file_path);

        // Find and remove empty array declarations
        $pattern = '/public\s+static\s+\$permanent_delete_actions\s*=\s*array\(\s*\);[^\n]*\n?/';

        if (preg_match($pattern, $content, $matches)) {
            $new_content = preg_replace($pattern, '', $content);

            if (!$this->dry_run) {
                // Backup if not already done
                if (!file_exists($file_path . '.pre_migration')) {
                    copy($file_path, $file_path . '.pre_migration');
                }
                file_put_contents($file_path, $new_content);
            }

            $this->modifications[$file_path][] = "Removed empty permanent_delete_actions";
        }
    }
}

// Run migration if not included from another script
if (!isset($no_autorun)) {
    $dry_run = !isset($argv) || !in_array('--execute', $argv);

    $executor = new DeletionMigrationExecutor($dry_run);
    $executor->execute();

    if ($dry_run) {
        echo "\nThis was a DRY RUN. No files were modified.\n";
        echo "To execute the migration, run: php migration_phase2_execute.php --execute\n";
    }
}
?>
```

### 3. Validation Script

**File**: `/utils/migration_phase2_validate.php`

```php
<?php
/**
 * Deletion Migration Phase 2 - Validation Script
 *
 * Validates that the migration preserved all deletion behavior
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

class DeletionMigrationValidator {
    private $errors = [];
    private $warnings = [];

    /**
     * Run validation checks
     */
    public function validate() {
        echo "=== DELETION MIGRATION PHASE 2: VALIDATION ===\n\n";

        // Check 1: Verify del_deletion_rules table is populated
        $this->checkRulesTablePopulated();

        // Check 2: Compare old vs new behavior for each model
        $this->compareOldVsNewBehavior();

        // Check 3: Verify no orphaned permanent_delete_actions
        $this->checkForOrphanedRules();

        // Check 4: Test dry-run on sample data
        $this->testDryRunFunctionality();

        $this->generateReport();
    }

    /**
     * Check that del_deletion_rules table has entries
     */
    private function checkRulesTablePopulated() {
        echo "Checking del_deletion_rules table...\n";

        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT COUNT(*) FROM del_deletion_rules";
        $count = $db->query($sql)->fetchColumn();

        if ($count === 0) {
            $this->errors[] = "del_deletion_rules table is empty - migration may not have run";
        } else {
            echo "  ✓ Found $count deletion rules\n";
        }
    }

    /**
     * Compare old vs new deletion behavior
     */
    private function compareOldVsNewBehavior() {
        echo "\nComparing old vs new behavior...\n";

        // Load the migration report
        $report_file = PathHelper::getIncludePath('utils/deletion_migration_report.json');
        if (!file_exists($report_file)) {
            $this->errors[] = "Migration report not found - cannot validate";
            return;
        }

        $report_data = json_decode(file_get_contents($report_file), true);

        foreach ($report_data['models_to_migrate'] as $model) {
            $this->validateModelRules($model);
        }
    }

    /**
     * Validate that a model's rules were correctly migrated
     */
    private function validateModelRules($model) {
        $parent_table = $model['parent_table'];

        echo "  Validating {$model['parent_class']} ({$parent_table})...\n";

        foreach ($model['rules'] as $rule) {
            if ($rule['new_action'] === null) {
                // Skip action - should not be in del_deletion_rules
                continue;
            }

            // Check that rule exists in del_deletion_rules
            $db = DbConnector::get_instance()->get_db_link();
            $sql = "SELECT * FROM del_deletion_rules
                    WHERE del_source_table = ?
                    AND del_target_table = ?
                    AND del_target_column = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$parent_table, $rule['target_table'], $rule['column']]);
            $db_rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$db_rule) {
                $this->errors[] = "Missing rule: {$parent_table} -> {$rule['target_table']}.{$rule['column']}";
            } else {
                // Verify action matches
                $expected_action = $rule['new_action']['action'];
                if ($db_rule['del_action'] !== $expected_action) {
                    $this->errors[] = "Action mismatch for {$rule['column']}: expected {$expected_action}, got {$db_rule['del_action']}";
                }
            }
        }
    }

    /**
     * Check for models that still have permanent_delete_actions
     */
    private function checkForOrphanedRules() {
        echo "\nChecking for orphaned permanent_delete_actions...\n";

        $classes = LibraryFunctions::discover_model_classes([
            'require_tablename' => true,
            'include_plugins' => true,
            'verbose' => false
        ]);

        $orphaned_count = 0;
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            try {
                $actions = $reflection->getStaticPropertyValue('permanent_delete_actions', []);
                if (!empty($actions)) {
                    $orphaned_count++;
                    $this->warnings[] = "$class still has permanent_delete_actions defined";
                }
            } catch (ReflectionException $e) {
                // No property - good
            }
        }

        if ($orphaned_count === 0) {
            echo "  ✓ No orphaned permanent_delete_actions found\n";
        } else {
            echo "  ⚠ Found $orphaned_count models with permanent_delete_actions\n";
        }
    }

    /**
     * Test dry-run functionality on sample data
     */
    private function testDryRunFunctionality() {
        echo "\nTesting dry-run functionality...\n";

        // Find a model with deletion rules
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT DISTINCT del_source_table FROM del_deletion_rules LIMIT 1";
        $table = $db->query($sql)->fetchColumn();

        if (!$table) {
            $this->warnings[] = "No deletion rules to test";
            return;
        }

        // Find the model for this table
        $classes = LibraryFunctions::discover_model_classes([
            'require_tablename' => true,
            'include_plugins' => false,
            'verbose' => false
        ]);

        $test_class = null;
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->getStaticPropertyValue('tablename') === $table) {
                $test_class = $class;
                break;
            }
        }

        if (!$test_class) {
            $this->warnings[] = "Could not find model class for table $table";
            return;
        }

        // Find a record to test
        $reflection = new ReflectionClass($test_class);
        $pkey = $reflection->getStaticPropertyValue('pkey_column');
        $sql = "SELECT $pkey FROM $table LIMIT 1";
        $id = $db->query($sql)->fetchColumn();

        if (!$id) {
            $this->warnings[] = "No test data available in $table";
            return;
        }

        // Test dry-run
        try {
            $obj = new $test_class($id, true);
            $dry_run = $obj->permanent_delete_dry_run();

            if (!isset($dry_run['primary']) || !isset($dry_run['dependencies'])) {
                $this->errors[] = "Dry-run returned invalid structure";
            } else {
                echo "  ✓ Dry-run works on $test_class\n";
            }
        } catch (Exception $e) {
            $this->errors[] = "Dry-run failed on $test_class: " . $e->getMessage();
        }
    }

    /**
     * Generate validation report
     */
    private function generateReport() {
        echo "\n=== VALIDATION REPORT ===\n\n";

        echo "Errors: " . count($this->errors) . "\n";
        echo "Warnings: " . count($this->warnings) . "\n\n";

        if (!empty($this->errors)) {
            echo "--- ERRORS ---\n";
            foreach ($this->errors as $error) {
                echo "  ✗ $error\n";
            }
            echo "\n";
        }

        if (!empty($this->warnings)) {
            echo "--- WARNINGS ---\n";
            foreach ($this->warnings as $warning) {
                echo "  ⚠ $warning\n";
            }
            echo "\n";
        }

        if (empty($this->errors) && empty($this->warnings)) {
            echo "✅ VALIDATION PASSED\n";
            echo "All deletion rules have been successfully migrated!\n";
        } elseif (empty($this->errors)) {
            echo "⚠️  VALIDATION PASSED WITH WARNINGS\n";
            echo "Migration successful but there are items to review.\n";
        } else {
            echo "❌ VALIDATION FAILED\n";
            echo "Please address the errors above before using the new deletion system.\n";
        }
    }
}

// Run validation if not included from another script
if (!isset($no_autorun)) {
    $validator = new DeletionMigrationValidator();
    $validator->validate();
}
?>
```

### 4. Cleanup Script

**File**: `/utils/migration_phase2_cleanup.php`

```php
<?php
/**
 * Deletion Migration Phase 2 - Cleanup Script
 *
 * Final cleanup after successful migration
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

class DeletionMigrationCleanup {
    /**
     * Perform cleanup tasks
     */
    public function cleanup() {
        echo "=== DELETION MIGRATION PHASE 2: CLEANUP ===\n\n";

        // Remove backup files
        $this->removeBackupFiles();

        // Remove migration report
        $this->removeMigrationReport();

        echo "\n✅ CLEANUP COMPLETE\n";
    }

    /**
     * Remove .pre_migration backup files
     */
    private function removeBackupFiles() {
        echo "Removing .pre_migration backup files...\n";

        $backup_files = glob(PathHelper::getIncludePath('data/*.pre_migration'));

        foreach ($backup_files as $file) {
            if (unlink($file)) {
                echo "  ✓ Removed " . basename($file) . "\n";
            }
        }

        if (empty($backup_files)) {
            echo "  No backup files found\n";
        }
    }

    /**
     * Remove migration report
     */
    private function removeMigrationReport() {
        echo "\nRemoving migration report...\n";

        $report_file = PathHelper::getIncludePath('utils/deletion_migration_report.json');
        if (file_exists($report_file)) {
            if (unlink($report_file)) {
                echo "  ✓ Removed deletion_migration_report.json\n";
            }
        } else {
            echo "  No report file found\n";
        }
    }
}

// Run cleanup if not included from another script
if (!isset($no_autorun)) {
    echo "This will remove all .pre_migration backup files and the migration report.\n";
    echo "Type 'yes' to continue: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) === 'yes') {
        $cleanup = new DeletionMigrationCleanup();
        $cleanup->cleanup();
    } else {
        echo "Cleanup cancelled.\n";
    }
    fclose($handle);
}
?>
```

## Migration Workflow

### Step-by-Step Process

1. **Discovery Phase**
   ```bash
   cd /var/www/html/joinerytest/public_html/utils
   php migration_phase2_discover.php
   ```
   - Reviews output for any issues
   - Checks `deletion_migration_report.json` for details
   - **Expected output**: ~28 models to migrate, ~65 rules, 6 custom methods, 37 empty arrays

2. **Dry Run Phase**
   ```bash
   php migration_phase2_execute.php
   ```
   - Reviews what would be changed (no files modified)
   - Verifies mappings are correct

3. **Execution Phase**
   ```bash
   # Create full backup first
   cd /var/www/html/joinerytest
   tar -czf backup_pre_migration_$(date +%Y%m%d).tar.gz public_html/

   # Execute migration
   cd public_html/utils
   php migration_phase2_execute.php --execute
   ```
   - Modifies model files
   - Creates .pre_migration backups for each file

4. **Manual Review Phase** (CRITICAL)
   - Review the 6 models with custom permanent_delete() methods:
     - User, File, Event, Order, Post, ContentVersions
   - Ensure custom logic is still called correctly
   - Verify they still call `parent::permanent_delete()`

5. **Re-register Rules**
   ```bash
   php update_database.php
   ```
   - Regenerates del_deletion_rules table with new configuration

6. **Validation Phase**
   ```bash
   php migration_phase2_validate.php
   ```
   - Verifies all rules migrated correctly
   - Tests dry-run functionality
   - Identifies any issues

7. **Testing Phase**
   - Test deletion in admin interface
   - Verify dry-run previews work correctly
   - Test both preventable and allowed deletions
   - Focus on User, Product, Order, Event deletions

8. **Cleanup Phase** (after verification)
   ```bash
   php migration_phase2_cleanup.php
   ```
   - Removes backup files
   - Removes migration report

## Special Cases

### Custom permanent_delete() Methods

Models with custom implementations need manual review:

1. **User** - Checks system users, removes from mailing lists
2. **File** - Deletes physical files and resizes
3. **Event** - Custom registration deletion
4. **Order** - Custom order cleanup
5. **Post** - Custom post cleanup
6. **ContentVersions** - Custom version cleanup

**Handling**: These should continue to work as-is since they call `parent::permanent_delete()`. Verify after migration.

### Constant Values

**User::USER_DELETED** constant appears extensively:
- Must be preserved as constant reference, not converted to literal `3`
- Migration script specifically handles this

### Self-Referencing Tables

- **Comment** (`cmt_cmt_comment_id_parent`) - nested comments
- **AdminMenu** (`adm_adm_admin_menu_id_parent`) - hierarchical menus

**Handling**: Auto-detection will pick these up correctly

### Edge Case: PhoneNumber

The rule `'act_activation_codes' => 'delete'` appears malformed (table name not column).
- **Action**: Flag for manual review and correction

## Rollback Plan

If migration needs to be rolled back:

1. **Restore from .pre_migration backups**
   ```bash
   cd /var/www/html/joinerytest/public_html/data
   for file in *.pre_migration; do
       mv "$file" "${file%.pre_migration}"
   done
   ```

2. **Or restore from tar backup**
   ```bash
   cd /var/www/html/joinerytest
   tar -xzf backup_pre_migration_YYYYMMDD.tar.gz
   ```

3. **Re-run update_database**
   ```bash
   cd public_html/utils
   php update_database.php
   ```

## Success Criteria

Migration is considered successful when:

✅ Discovery script completes without errors
✅ All ~15-20 models with exception rules are mapped correctly
✅ All ~35-40 exception rules (prevent, null, set_value) are migrated
✅ All ~25-30 'delete' rules are identified as not needing migration
✅ Execution completes without errors
✅ Validation reports no errors
✅ del_deletion_rules table contains expected rules (from auto-detection + migrations)
✅ Dry-run functionality works on sample data
✅ Test deletions work correctly in admin interface
✅ All 6 custom permanent_delete() methods still function
✅ No permanent_delete_actions remain in any models

## Post-Migration

After successful migration:

1. **Update documentation** - Note that models should use `$foreign_key_actions` not `$permanent_delete_actions`
2. **Update templates** - Create model template with `$foreign_key_actions` example
3. **Train team** - Ensure developers understand the new system
4. **Monitor logs** - Watch for any deletion-related errors
5. **Archive scripts** - Move migration scripts to `/utils/archive/`

## Timeline Estimate

**Significantly reduced due to simplified scope!**

- **Discovery**: 5 minutes
- **Review & Planning**: 30 minutes (review ~15 models with actual migrations)
- **Dry Run & Testing**: 15 minutes
- **Backup**: 10 minutes
- **Execution**: 3 minutes (only ~35-40 rules to migrate, not 65)
- **Manual Review**: 30-60 minutes (verify 6 custom permanent_delete methods)
- **Validation**: 10 minutes
- **Testing**: 30 minutes (test key models)
- **Cleanup**: 5 minutes

**Total**: 2-3 hours (reduced from 3-5 hours)

## Checklist

- [ ] Run discovery script
- [ ] Review migration report
  - [ ] Verify ~15-20 models have exception rules (prevent, null, set_value)
  - [ ] Verify ~25-30 'delete' rules marked as not needing migration
  - [ ] Verify ~45+ models marked for simple removal
- [ ] Review 6 custom permanent_delete() methods manually
- [ ] Create full backup
- [ ] Run dry-run migration
- [ ] Review proposed changes (should be ~35-40 actual migrations)
- [ ] Execute migration
- [ ] Manually verify custom permanent_delete() methods still work
- [ ] Re-run update_database (regenerates deletion rules)
- [ ] Run validation script
- [ ] Test deletions with exception rules:
  - [ ] Test User deletion (set_value rules)
  - [ ] Test Product deletion (prevent rule)
  - [ ] Test Order deletion (null rules)
  - [ ] Test MailingList deletion (prevent rule)
- [ ] Test dry-run previews
- [ ] Run cleanup script
- [ ] Update documentation
- [ ] Archive migration scripts

## Notes

### Key Simplifications

**The migration is MUCH simpler than originally estimated!**

- **Most rules don't need migration**: ~25-30 'delete' rules are simply removed
- **Only exceptions migrate**: ~35-40 rules (prevent, null, set_value) actually move to child models
- **Cascade is the default**: The new system automatically handles 'delete' behavior

### Discovery Script Output

When you run the discovery script, you should see output like:

```
Models requiring migration: 15-20
Models with empty arrays (cleanup): 37
Custom permanent_delete() methods: 6

--- MODELS TO MIGRATE ---
Total rules to migrate: 35-40 (only prevent, null, set_value)
Total rules skipped (delete): 25-30 (default behavior, no migration needed)
```

### Technical Details

- All migration scripts include `$no_autorun` check for testing
- Scripts can be safely re-run (discovery and validation are non-destructive)
- Execution script only modifies when `--execute` flag is provided
- All file modifications create .pre_migration backups
- JSON report allows for manual review and custom processing if needed
- Focus testing on models with **exception rules**: User (set_value), Product (prevent), Order (null), MailingList (prevent)

---

## Quick Reference: What Gets Migrated?

**✅ MIGRATE TO CHILD MODELS (~35-40 rules):**
- 'prevent' actions → `['action' => 'prevent']`
- 'null' actions → `['action' => 'null']`
- set_value actions (e.g., User::USER_DELETED) → `['action' => 'set_value', 'value' => User::USER_DELETED]`

**❌ JUST REMOVE FROM PARENT (~25-30 rules):**
- 'delete' actions → Remove (cascade is the default!)
- 'skip' actions → Remove (don't declare)
- Empty arrays → Remove

**Key Models with Exception Rules:**
- **User** (~18 set_value rules)
- **Product, MailingList, CouponCode, EventType, ProductGroup, Group, EventRegistrant, EmailTemplate, Video, File, Plugin, PluginVersion, Upgrade** (prevent rules)
- **Order, Location, AdminMenu** (null rules)
