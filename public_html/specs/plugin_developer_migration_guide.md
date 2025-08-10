# Plugin and Theme Developer Migration Guide

## Overview

This guide outlines the changes needed to make your existing plugins and themes compatible with the new unified component management system. The new system provides consistent manifest-based configuration, better validation, enhanced FormWriter selection, and improved developer experience for both plugins and themes.

## What's New: Unified Component Architecture

The system now treats themes and plugins as "components" with a shared base architecture:

### Key Features:
- **Mandatory Manifests**: All themes and plugins require JSON manifest files
- **Consistent Structure**: Both use similar manifest formats and patterns
- **Enhanced FormWriter Selection**: Themes can specify their FormWriter base class
- **Helper Classes**: New ThemeHelper and PluginHelper classes provide convenient methods
- **Backward Compatibility**: System falls back to legacy methods during transition

### Architecture Overview:
```
ComponentBase (abstract base class)
├── ThemeHelper (theme-specific implementation)
│   ├── Manifest loading (theme.json)
│   ├── Asset management
│   └── FormWriter selection
└── PluginHelper (plugin-specific implementation)
    ├── Manifest loading (plugin.json)
    ├── Activation/deactivation
    └── Migration management
```

### Benefits:
- **Consistent API** across themes and plugins
- **Better validation** with clear error messages
- **Improved performance** through caching
- **Enhanced developer experience** with helper methods
- **Future-ready** architecture for advanced features

## Part 1: Theme Migration

### New Theme Requirements

**CRITICAL:** Starting with Phase 1 of the component system, all themes MUST have a `theme.json` manifest file. Themes without manifests will not be discovered or usable.

#### **Required theme.json Structure:**
```json
{
  "name": "my-theme",
  "displayName": "My Theme Display Name",
  "version": "1.0.0",
  "description": "A brief description of the theme",
  "author": "Your Name or Company",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageFalcon"
}
```

#### **Required Fields:**
- `name` - Must match the theme directory name
- `displayName` - Human-readable name for admin interface
- `version` - Semantic versioning (e.g., 1.0.0)
- `description` - Brief description of the theme
- `author` - Theme author or organization

#### **Framework Configuration:**
- `cssFramework` - Options: "bootstrap", "tailwind", "uikit", "custom"
- `formWriterBase` - Base FormWriter class to use:
  - `FormWriterMasterBootstrap` for Bootstrap themes
  - `FormWriterMasterTailwind` for Tailwind themes
  - `FormWriterMasterUIkit` for UIKit themes
- `publicPageBase` - Base public page class (usually `PublicPageFalcon`)

### Theme Helper Functions

The new system provides helper functions for theme development:

```php
// Get theme asset URL
$cssUrl = ThemeHelper::asset('css/theme.css');

// Include theme file with fallback
ThemeHelper::includeThemeFile('includes/header.php');

// Get theme configuration value
$framework = ThemeHelper::config('cssFramework', 'bootstrap');
```

### Migration Steps for Existing Themes

1. **Create theme.json** in your theme's root directory
2. **Set appropriate FormWriter base class** based on your CSS framework
3. **Test theme functionality** after adding manifest
4. **Update any hardcoded paths** to use ThemeHelper methods (optional but recommended)

### Example Theme Manifests

#### Bootstrap-based Theme:
```json
{
  "name": "falcon",
  "displayName": "Falcon Theme",
  "version": "2.0.0",
  "description": "Bootstrap 5 based responsive theme",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageFalcon"
}
```

#### Tailwind-based Theme:
```json
{
  "name": "tailwind",
  "displayName": "Tailwind CSS Theme",
  "version": "1.0.0",
  "description": "Tailwind CSS based theme",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "tailwind",
  "formWriterBase": "FormWriterMasterTailwind",
  "publicPageBase": "PublicPageFalcon"
}
```

## Part 2: Plugin Migration

### Plugin Requirements

### 1. Add or Update plugin.json File

**CRITICAL:** Every plugin must have a `plugin.json` file in its root directory with proper version information.

#### **Minimum Required plugin.json:**
```json
{
    "name": "My Plugin Name",
    "version": "1.0.0"
}
```

#### **Recommended Complete plugin.json:**
```json
{
    "name": "My Advanced Plugin",
    "description": "A comprehensive plugin with proper metadata",
    "version": "2.1.0",
    "author": "Your Name or Company",
    "license": "MIT",
    "homepage": "https://yoursite.com/plugin-docs",
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json", "curl"]
    },
    "depends": {
        "core-plugin": ">=1.0",
        "helper-plugin": "*"
    },
    "provides": ["api-endpoint", "widget-support"],
    "conflicts": ["old-plugin-name"],
    "tags": ["utility", "api", "widget"]
}
```

#### **Version Requirements:**
- **MUST** use semantic versioning (major.minor.patch)
- **MUST** increment version for ANY code changes
- **MUST** increment major version for breaking changes
- Examples: `1.0.0`, `1.2.3`, `2.0.0-beta1`

### 2. Update Migration System

#### **Current Migration Format (DEPRECATED):**
```php
// OLD - plugins/my-plugin/migrations/migrations.php
$migration['database_version'] = '1.0';
$migration['test'] = "SELECT count(1) as count FROM my_table";
$migration['migration_sql'] = 'CREATE TABLE my_table (...)';
$migrations[] = $migration;
```

#### **New Migration Format (REQUIRED):**

**IMPORTANT:** Tables are automatically created from your data class `$field_specifications`. Migrations are only for:
- Adding settings/configuration data
- Creating indexes beyond what's in field specifications  
- Populating initial data
- Adding admin menu entries
- Database changes that can't be expressed in field specifications

```php
// NEW - plugins/my-plugin/migrations/migrations.php
return [
    [
        'id' => '001_initial_settings_and_data',
        'version' => '1.0.0',
        'description' => 'Add plugin settings and initial data',
        'up' => function($dbconnector) {
            // Add plugin settings (tables created automatically from data classes)
            $dbconnector->exec("INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                               VALUES ('my_plugin_enabled', '1', 1, NOW(), NOW(), 'general')");
            
            // Add custom indexes not defined in field specifications
            $dbconnector->exec("CREATE INDEX IF NOT EXISTS idx_my_plugin_custom ON mp_my_plugin_data(mpd_custom_field)");
            
            // Insert initial/default data
            $dbconnector->exec("INSERT INTO mp_my_plugin_data (mpd_key, mpd_value) VALUES ('version', '1.0.0')");
        },
        'down' => function($dbconnector) {
            // Remove settings
            $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'my_plugin_%'");
            
            // Note: Tables will be dropped automatically during uninstall via uninstall script
            // Only clean up data/settings that can't be handled by table drops
        }
    ],
    [
        'id' => '002_add_admin_menu',
        'version' => '1.1.0',
        'depends_on' => ['001_initial_settings_and_data'],
        'description' => 'Add admin menu entry',
        'up' => function($dbconnector) {
            $dbconnector->exec("INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_min_permission) 
                               VALUES ('My Plugin', '/plugins/my-plugin/admin/admin_my_plugin', 5)");
        },
        'down' => function($dbconnector) {
            $dbconnector->exec("DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/my-plugin/%'");
        }
    ]
];
```

#### **Important: Table Creation vs Migrations**

**Tables are created automatically** from your data class `$field_specifications` when the plugin is installed. The migration system calls `update_database.php` which reads all plugin data classes and creates/updates tables as needed.

**Migrations are ONLY for:**
- Adding settings to `stg_settings` table
- Inserting initial/default data into your tables
- Creating custom indexes not defined in field specifications
- Adding admin menu entries to `amu_admin_menus`
- Any data changes that can't be expressed as table structure

**Migrations are NOT for:**
- Creating tables (handled automatically)
- Adding columns (handled automatically via field specifications)
- Modifying table structure (handled automatically via field specifications)

#### **Migration Best Practices:**
1. **Always provide 'down' migrations** for rollback capability
2. **Use unique, descriptive IDs** within your plugin
3. **Include version numbers** to track which version introduced each migration
4. **Use functions for complex migrations** that need multiple SQL statements
5. **Declare dependencies** between migrations using 'depends_on'
6. **Test your rollbacks** - they will be used during uninstallation
7. **Only use migrations for data/settings** - not table structure

### 3. Create Uninstall Script (RECOMMENDED)

Create an `uninstall.php` file in your plugin root for clean removal:

```php
<?php
// File: /plugins/my-plugin/uninstall.php

/**
 * Uninstall function for my-plugin
 * This function will be called when the plugin is uninstalled
 * @return bool True on success, false on failure
 */
function my_plugin_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        
        // Drop plugin tables (use your actual table names)
        $dbconnector->exec("DROP TABLE IF EXISTS mp_my_plugin_data CASCADE");
        $dbconnector->exec("DROP TABLE IF EXISTS mp_user_preferences CASCADE");
        
        // Remove plugin-specific settings
        $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'my_plugin_%'");
        
        // Clean up uploaded files (if your plugin creates any)
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/my-plugin/';
        if (is_dir($upload_dir)) {
            // Remove files recursively (implement your own logic)
            // Example: exec("rm -rf " . escapeshellarg($upload_dir));
        }
        
        // Clean up any scheduled tasks, cache files, etc.
        
        return true;
        
    } catch (Exception $e) {
        error_log("My Plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
?>
```

#### **Uninstall Function Rules:**
- **Function name MUST match** your plugin directory name + `_uninstall`
- **MUST return boolean** - true for success, false for failure
- **Should clean up ALL** data created by your plugin
- **Should be safe to run multiple times** (idempotent)

### 4. Follow New Installation Lifecycle

#### **Old Lifecycle (DEPRECATED):**
```
Upload Plugin → System Detects → Activate
```

#### **New Lifecycle (REQUIRED UNDERSTANDING):**
```
Upload Plugin → Install (create tables + run migrations) → Activate (enable routing)
                    ↓
               Deactivate (disable routing) → Uninstall (drop tables + rollback migrations)
```

#### **What This Means for Your Plugin:**
- **Installation** creates tables from data classes AND runs your migrations for settings/data
- **Activation** just enables routing - your tables and data should already exist
- **Deactivation** disables routing but keeps your tables and data intact
- **Uninstallation** runs your uninstall script (drops tables) and rollbacks migrations (removes settings)

### 5. Update Table Naming Conventions

#### **Recommended Table Prefixes:**
```sql
-- Use your plugin name as prefix to avoid conflicts
CREATE TABLE mp_my_plugin_data (...)      -- Good
CREATE TABLE my_plugin_settings (...)     -- Good  
CREATE TABLE user_data (...)              -- Bad - too generic
CREATE TABLE data (...)                   -- Bad - conflicts likely
```

### 6. Handle External Updates Properly

#### **CRITICAL RULE: Always Update Version**
When you update your plugin code outside the system (FTP, git, etc.):

1. **MUST increment version** in plugin.json
2. **MUST add new migrations** for any database changes  
3. **MUST NOT modify existing migrations** - always create new ones

#### **Example Update Process:**
```bash
# Your workflow when updating a plugin:
1. Edit your plugin code
2. Update version in plugin.json (1.0.0 → 1.0.1)
3. Add new migration if database changes needed
4. Upload/sync to server
5. System will auto-detect version change
6. Admin can then run upgrade process
```

## Migration Checklist

### For Existing Plugins:

- [ ] **Create plugin.json** with proper name and version
- [ ] **Convert migration format** from old array style to new format
- [ ] **Add rollback migrations** ('down' methods) for all existing migrations
- [ ] **Create uninstall script** for clean removal
- [ ] **Test migration rollbacks** to ensure they work
- [ ] **Use proper table naming** with plugin-specific prefixes
- [ ] **Document dependencies** if your plugin requires others

### For New Plugins:

- [ ] **Start with plugin.json** as your first file
- [ ] **Use new migration format** from the beginning
- [ ] **Include uninstall script** in initial development
- [ ] **Follow semantic versioning** from version 1.0.0
- [ ] **Test full lifecycle** (install → activate → deactivate → uninstall)

## Breaking Changes to Avoid

### **Things That Will Break Your Plugin:**

1. **Missing plugin.json** - System won't detect version changes
2. **Invalid version format** - Use semantic versioning only
3. **Missing rollback migrations** - Uninstall will fail
4. **Modifying existing migrations** - Creates hash mismatches
5. **Generic table names** - May conflict with other plugins

### **Safe Practices:**

1. **Always increment version** for any change
2. **Never modify existing migrations** - always add new ones
3. **Test rollbacks thoroughly** before releasing
4. **Use descriptive migration IDs** that won't conflict
5. **Include proper dependencies** in plugin.json

## Testing Your Updated Plugin

### **Development Testing:**
```bash
1. Install your plugin through admin interface
2. Verify all tables are created correctly
3. Activate plugin and test functionality  
4. Deactivate plugin (should still work when reactivated)
5. Uninstall plugin completely
6. Verify all tables and data are removed
7. Reinstall and verify everything works again
```

### **Version Update Testing:**
```bash
1. Install plugin version 1.0.0
2. Update plugin files to version 1.0.1 (via FTP/git)
3. Access plugin functionality to trigger detection
4. Verify admin shows "upgrade available"
5. Run upgrade process
6. Verify new features work correctly
```

## Support and Resources

### **Documentation References:**
- Plugin Manager Phase 3 specification
- Migration system documentation
- Database schema guidelines

### **Common Issues:**
- **"Migration failed"** - Check your SQL syntax and table names
- **"Version not detected"** - Ensure plugin.json format is correct
- **"Uninstall incomplete"** - Review your uninstall script logic
- **"Dependency errors"** - Check plugin names in 'depends' section

### **Getting Help:**
1. Check error logs for specific failure messages
2. Test migrations manually if needed
3. Review existing core plugins for examples
4. Validate JSON format using online tools

## Example: Complete Plugin Structure

```
/plugins/my-awesome-plugin/
├── plugin.json                 # REQUIRED - Plugin metadata
├── serve.php                   # Optional - Custom routing
├── uninstall.php              # RECOMMENDED - Clean removal
├── migrations/
│   └── migrations.php         # REQUIRED - Database changes
├── data/
│   └── my_data_class.php      # Plugin data models
├── logic/
│   └── my_logic.php           # Business logic
├── views/
│   └── my_view.php            # Templates
└── adm/
    └── admin_my_plugin.php    # Admin interface
```

## Current Plugin Audit and Action Items

Based on analysis of existing plugins in the system, here are the specific actions required for each plugin:

### **Plugin: bookings**

**Current Status:** ❌ Not compliant
**Priority:** High (has database tables but no migrations)

**Required Actions:**
- [ ] **Create plugin.json** in `/plugins/bookings/`
  ```json
  {
      "name": "Bookings Management",
      "version": "1.0.0",
      "description": "Booking and booking type management system",
      "author": "System Developer"
  }
  ```

- [ ] **Create migrations file** at `/plugins/bookings/migrations/migrations.php`
  ```php
  return [
      [
          'id' => '001_booking_initial_setup',
          'version' => '1.0.0', 
          'description' => 'Initial booking system setup and default data',
          'up' => function($dbconnector) {
              // Tables are created automatically from BookingType and Booking data classes
              // This migration only handles settings, initial data, etc.
              
              // Add plugin settings
              $dbconnector->exec("INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                                 VALUES ('bookings_enabled', '1', 1, NOW(), NOW(), 'general')");
              
              // Add default booking types (if needed)
              // $dbconnector->exec("INSERT INTO bkt_booking_types (bkt_name, bkt_description) VALUES ('Standard', 'Standard booking type')");
          },
          'down' => function($dbconnector) {
              // Remove settings
              $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'bookings_%'");
              
              // Tables will be dropped by uninstall script, not here
          }
      ]
  ];
  ```

- [ ] **Create uninstall script** at `/plugins/bookings/uninstall.php`
  ```php
  function bookings_uninstall() {
      try {
          $dbconnector = DbConnector::get_instance();
          $dbconnector->exec("DROP TABLE IF EXISTS bkn_bookings CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS bkt_booking_types CASCADE");
          return true;
      } catch (Exception $e) {
          error_log("Bookings uninstall failed: " . $e->getMessage());
          return false;
      }
  }
  ```

**Tables to migrate:** `bkt_booking_types`, `bkn_bookings`

---

### **Plugin: controld**

**Current Status:** ⚠️ Partially compliant
**Priority:** Medium (has old-format migrations, needs conversion)

**Required Actions:**
- [ ] **Create plugin.json** in `/plugins/controld/`
  ```json
  {
      "name": "ControlD Integration",
      "version": "1.0.0", 
      "description": "ControlD DNS filtering service integration",
      "author": "System Developer",
      "requires": {
          "php": ">=8.0",
          "extensions": ["curl"]
      }
  }
  ```

- [ ] **Convert existing migrations** from old format to new format
  - **Current file:** Has old-style migrations that add settings and menu entries
  - **Action:** Convert to new return array format with proper rollback migrations
  - **Example conversion:**
  ```php
  // OLD FORMAT (what's currently there):
  // $migration['database_version'] = '20250104';
  // $migration['migration_sql'] = 'INSERT INTO stg_settings...';
  
  // NEW FORMAT (convert to this):
  return [
      [
          'id' => '001_controld_initial_setup',
          'version' => '1.0.0',
          'description' => 'Add ControlD settings and admin menu',
          'up' => function($dbconnector) {
              // Add ControlD API key setting
              $dbconnector->exec("INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                                 VALUES ('controld_key', '', 1, NOW(), NOW(), 'general')");
              
              // Add admin menu entry
              $dbconnector->exec("INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) 
                                 VALUES ('Accounts', NULL, '/plugins/controld/admin/admin_ctld_accounts', 5, 8, 0, '', 'accounts', 'controld_key')");
          },
          'down' => function($dbconnector) {
              // Remove settings
              $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name = 'controld_key'");
              
              // Remove menu entries
              $dbconnector->exec("DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/controld/%'");
          }
      ]
  ];
  ```

- [ ] **Create uninstall script** at `/plugins/controld/uninstall.php`
  ```php
  function controld_uninstall() {
      try {
          $dbconnector = DbConnector::get_instance();
          
          // Drop all ControlD tables
          $dbconnector->exec("DROP TABLE IF EXISTS cds_ctldservices CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cdb_ctlddevice_backups CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cdr_ctldrules CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cdf_ctldfilters CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cda_ctldaccounts CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cdp_ctldprofiles CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS cdd_ctlddevices CASCADE");
          
          // Remove settings
          $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name = 'controld_key'");
          
          // Remove menu entries
          $dbconnector->exec("DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/controld/%'");
          
          return true;
      } catch (Exception $e) {
          error_log("ControlD uninstall failed: " . $e->getMessage());
          return false;
      }
  }
  ```

**Tables to migrate:** `cds_ctldservices`, `cdb_ctlddevice_backups`, `cdr_ctldrules`, `cdf_ctldfilters`, `cda_ctldaccounts`, `cdp_ctldprofiles`, `cdd_ctlddevices`

---

### **Plugin: items**

**Current Status:** ❌ Not compliant  
**Priority:** High (has database tables but no migrations)

**Required Actions:**
- [ ] **Create plugin.json** in `/plugins/items/`
  ```json
  {
      "name": "Items Management",
      "version": "1.0.0",
      "description": "Item tracking and relationship management system", 
      "author": "System Developer"
  }
  ```

- [ ] **Create migrations file** at `/plugins/items/migrations/migrations.php`
  ```php
  return [
      [
          'id' => '001_items_initial_setup',
          'version' => '1.0.0',
          'description' => 'Initial items system setup and default data',
          'up' => function($dbconnector) {
              // Tables are created automatically from Item, ItemRelationType, and ItemRelation data classes
              // This migration only handles settings, initial data, indexes, etc.
              
              // Add plugin settings
              $dbconnector->exec("INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                                 VALUES ('items_enabled', '1', 1, NOW(), NOW(), 'general')");
              
              // Add default item relation types (if needed)
              // $dbconnector->exec("INSERT INTO itt_item_relation_types (itt_name, itt_description) VALUES ('Contains', 'Item contains another item')");
              
              // Add any custom indexes not defined in field specifications
              // $dbconnector->exec("CREATE INDEX IF NOT EXISTS idx_items_custom ON itm_items(itm_custom_field)");
          },
          'down' => function($dbconnector) {
              // Remove settings
              $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'items_%'");
              
              // Tables will be dropped by uninstall script, not here
          }
      ]
  ];
  ```

- [ ] **Create uninstall script** at `/plugins/items/uninstall.php`
  ```php
  function items_uninstall() {
      try {
          $dbconnector = DbConnector::get_instance();
          
          // Drop in reverse dependency order
          $dbconnector->exec("DROP TABLE IF EXISTS itr_item_relations CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS itm_items CASCADE");
          $dbconnector->exec("DROP TABLE IF EXISTS itt_item_relation_types CASCADE");
          
          return true;
      } catch (Exception $e) {
          error_log("Items uninstall failed: " . $e->getMessage());
          return false;
      }
  }
  ```

**Tables to migrate:** `itt_item_relation_types`, `itm_items`, `itr_item_relations`

---

## Migration Priority Matrix

| Plugin | Priority | Complexity | Risk | Timeline |
|--------|----------|------------|------|----------|
| **bookings** | 🔴 High | Medium | Low | 1-2 days |
| **controld** | 🟡 Medium | High | Medium | 2-3 days |  
| **items** | 🔴 High | Medium | Low | 1-2 days |

### **Complexity Explanations:**

**bookings (Medium Complexity):**
- No existing migrations to convert
- Straightforward table structure
- Clear relationships

**controld (High Complexity):**
- Has existing old-format migrations that need conversion
- Multiple interdependent tables
- Settings and menu entries to handle
- External API integration considerations

**items (Medium Complexity):**  
- No existing migrations to convert
- Three related tables with dependencies
- Clear parent-child relationships

### **Implementation Order Recommendation:**

1. **Start with items** - Clean slate, good learning experience
2. **Then bookings** - Similar to items but simpler
3. **Finish with controld** - Most complex, learn from previous two

### **Testing Checklist for Each Plugin:**

For each plugin, after implementing the changes:

- [ ] **Fresh install test**
  1. Remove plugin from database if exists
  2. Install through admin interface
  3. Verify all tables created correctly
  4. Test plugin functionality

- [ ] **Upgrade test**
  1. Install version 1.0.0
  2. Update plugin.json to 1.0.1
  3. Access plugin to trigger detection
  4. Verify upgrade process works

- [ ] **Uninstall test**
  1. Uninstall through admin interface
  2. Verify all tables removed
  3. Verify no orphaned data
  4. Verify clean reinstall works

### **Common Issues to Watch For:**

1. **Foreign key constraints** - Make sure rollback order is correct
2. **Data dependencies** - Consider existing data when creating migrations
3. **Settings cleanup** - Don't forget to remove plugin settings on uninstall
4. **Menu entries** - Clean up admin menu entries added by plugins
5. **File uploads** - Remove any uploaded files during uninstall

## Testing the Unified Component System

### Component Discovery Test

Use the provided test utility to verify your components are properly configured:

```bash
php utils/test_components.php
```

Expected output:
```
Testing Component System
========================

1. Discovering all themes...
   Found X themes
   - theme-name: Display Name

2. Discovering all plugins...
   Found Y plugins
   - plugin-name: Display Name

3. Validating all components...
   Themes:
   ✓ theme-name: Valid
   Plugins:
   ✓ plugin-name: Valid
```

### Common Issues and Solutions

#### Theme Not Discovered
**Problem**: Theme doesn't appear in component discovery
**Solution**: Ensure theme.json exists and is valid JSON

#### FormWriter Base Class Not Found
**Problem**: Error about missing FormWriter class
**Solution**: Use one of the existing base classes:
- FormWriterMasterBootstrap
- FormWriterMasterTailwind
- FormWriterMasterUIkit

#### Plugin Manifest Invalid
**Problem**: Plugin discovered but validation fails
**Solution**: Check plugin.json has all required fields

#### Legacy Code Still Running
**Problem**: System using old FormWriter selection
**Solution**: This is normal during transition - the system falls back to legacy methods if new components fail

### Component Helper Methods

Both themes and plugins can use their respective helper classes:

#### ThemeHelper Methods:
```php
// Get current theme instance
$theme = ThemeHelper::getInstance();

// Get theme configuration
$framework = $theme->getCssFramework();
$formWriter = $theme->getFormWriterBase();

// Static helper methods
$assetUrl = ThemeHelper::asset('css/style.css');
ThemeHelper::includeThemeFile('includes/header.php');
$config = ThemeHelper::config('cssFramework', 'bootstrap');
```

#### PluginHelper Methods:
```php
// Get plugin instance
$plugin = PluginHelper::getInstance('my-plugin');

// Check plugin status
if ($plugin->isActive()) {
    // Plugin is active
}

// Get plugin configuration
$version = $plugin->getVersion();
$author = $plugin->getAuthor();

// Activation/deactivation
$plugin->activate();
$plugin->deactivate();
```

## Conclusion

By following this guide, your plugins and themes will be fully compatible with the unified component management system. This provides:

1. **Consistent experience** for developers working with both themes and plugins
2. **Better validation** and error handling throughout the system
3. **Enhanced features** like automatic FormWriter selection for themes
4. **Future compatibility** with planned enhancements like dependency resolution
5. **Improved performance** through component caching and optimized loading

The migration path is designed to be gradual - your existing code continues to work while you add the new manifest files and optionally adopt the new helper methods.