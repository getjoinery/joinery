# Theme and Plugin System Bug Fixes Specification

## Overview
This specification addresses remaining bugs in the theme and plugin system, implementing a simplified "plugin-provided theme" approach where plugins can serve as complete themes when the "plugin" theme is selected.



## Remaining Bug Fixes (In Implementation Order)

### Plugin-Provided Theme System Implementation

**Problem**: Need to allow plugins to provide complete theme functionality.

**Solution**: Simple redirects and plugin directory as theme directory

## Files to Modify

### PathHelper.php

Add new centralized theme helper methods and modify `getThemeFilePath()` to use them:

**Add these new public static methods:**
   ```php
   /**
    * Get the active theme directory path (handles both regular and plugin themes)
    * @return string Theme directory relative path (e.g., 'theme/falcon' or 'plugins/controld')
    * @throws Exception if plugin theme is active but plugin not found
    */
   public static function getActiveThemeDirectory() {
       $settings = Globalvars::get_instance();
       $theme_template = $settings->get_setting('theme_template');
       
       if ($theme_template === 'plugin') {
           $active_plugin = $settings->get_setting('active_theme_plugin');
           
           if (!$active_plugin) {
               throw new Exception("Plugin theme is active but no plugin selected. Please contact administrator.");
           }
           
           $plugin_dir = self::getIncludePath("plugins/$active_plugin");
           if (!is_dir($plugin_dir)) {
               throw new Exception("Plugin theme is active but plugin '$active_plugin' not found. Please contact administrator.");
           }
           
           return "plugins/$active_plugin";
       }
       
       // Validate regular theme exists
       $theme_dir = self::getIncludePath("theme/$theme_template");
       if (!is_dir($theme_dir)) {
           throw new Exception("Theme '$theme_template' directory not found. Please contact administrator.");
       }
       
       return "theme/$theme_template";
   }
   
   /**
    * Check if the current theme is a plugin-provided theme
    * @return bool True if plugin theme is active
    */
   public static function isPluginTheme() {
       $settings = Globalvars::get_instance();
       return $settings->get_setting('theme_template') === 'plugin';
   }
   
   /**
    * Get the active theme plugin name (if plugin theme is active)
    * @return string|null Plugin name or null if not using plugin theme
    */
   public static function getActiveThemePlugin() {
       if (!self::isPluginTheme()) {
           return null;
       }
       $settings = Globalvars::get_instance();
       return $settings->get_setting('active_theme_plugin');
   }
   ```

**Modify `getThemeFilePath()` to use the new centralized methods:**
   ```php
   public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $debug = false){
       $siteDir = PathHelper::getBasePath();
       
       // SUBDIRECTORY WORKS WITH OR WITHOUT SLASH
       if (substr($subdirectory, 0, 1) !== '/') {
           $subdirectory = '/' . $subdirectory; // Add a forward slash if it doesn't exist
       }
       
       // IMPORTANT: Core system files must always load from system directories
       // to prevent circular dependencies during bootstrap
       $core_system_files = array('Globalvars.php', 'Globalvars_site.php', 'DbConnector.php', 'PathHelper.php');
       $is_core_file = in_array($filename, $core_system_files);
       
       // Handle when specific theme is requested
       if ($theme_name) {
           $theme_dir = "theme/$theme_name";
           $theme_file = $siteDir . '/' . $theme_dir . $subdirectory . '/' . $filename;
           
           if (file_exists($theme_file)) {
               if ($path_format == 'system') {
                   return $theme_file;  // Full system path
               } else {
                   return '/' . $theme_dir . $subdirectory . '/' . $filename;  // Web path
               }
           }
           // Fall through to check base directory
       }
       // Don't use plugin theme for core files
       else if (!$is_core_file) {
           try {
               // Use centralized method to get active theme directory
               $theme_dir = self::getActiveThemeDirectory();
               $theme_file = $siteDir . '/' . $theme_dir . $subdirectory . '/' . $filename;
               
               if (file_exists($theme_file)) {
                   if ($path_format == 'system') {
                       return $theme_file;  // Full system path
                   } else {
                       return '/' . $theme_dir . $subdirectory . '/' . $filename;  // Web path
                   }
               }
           } catch (Exception $e) {
               // Log error and re-throw - don't silently fall back
               error_log("Theme error: " . $e->getMessage());
               throw $e;
           }
       }
       
       // ... rest of existing logic (checks base directory) ...
   ```

### RouteHelper.php

Modify the template directory assignment around line 1010 to use PathHelper's centralized theme methods:
   ```php
   // Around line 1007-1012, replace the template_directory assignment with:
   
   /**
    * Template Directory Resolution
    * Uses PathHelper's centralized theme methods to determine the correct
    * template directory for loading view files.
    */
   
   try {
       // Use PathHelper's centralized method to get the active theme directory
       // This handles both regular themes and plugin themes automatically
       // PathHelper::getActiveThemeDirectory() already validates directory exists
       $theme_dir = PathHelper::getActiveThemeDirectory();
       $template_directory = PathHelper::getIncludePath($theme_dir);
       
   } catch (Exception $e) {
       // Plugin theme configuration error - log and throw
       error_log("Template directory error: " . $e->getMessage());
       throw $e; // Re-throw to prevent system from running in broken state
   }
   ```

### ThemeHelper.php

Modify the `asset()` method around line 148 to use PathHelper's centralized theme methods:
   ```php
   public static function asset($path, $themeName = null) {
       // If specific theme requested, load from that theme directory
       if ($themeName !== null) {
           $theme_asset = "/theme/{$themeName}/assets/{$path}";
           $theme_asset_path = PathHelper::getIncludePath("theme/{$themeName}/assets/{$path}");
           if (file_exists($theme_asset_path)) {
               return $theme_asset;
           }
       } else {
           // No specific theme - use the currently active theme (regular or plugin)
           try {
               // Get the active theme directory using centralized method
               $theme_dir = PathHelper::getActiveThemeDirectory();
               $asset_path = "/{$theme_dir}/assets/{$path}";
               $asset_full_path = PathHelper::getIncludePath("{$theme_dir}/assets/{$path}");
               
               if (file_exists($asset_full_path)) {
                   return $asset_path;
               }
           } catch (Exception $e) {
               // Log error but don't throw - fall through to existing fallback logic
               error_log("Asset loading error: " . $e->getMessage());
           }
       }
       
       // ... rest of existing fallback logic (checking current plugin, etc.) ...
   }
   ```

### admin_settings.php

Add plugin selector dropdown:
   
   Add after theme_template dropdown (around line where theme selection is):
   ```php
   // Show plugin selector when plugin theme is active
   $current_theme = $settings->get_setting('theme_template');
   if ($current_theme === 'plugin') {
       // Use existing method to get available plugins
       $available_plugins = PluginHelper::getAvailablePlugins();
       // Could filter for theme providers using $plugin_helper->providesTheme() if needed
       
       // Create FormWriter dropdown following existing admin_settings pattern
       $current_plugin = $settings->get_setting('active_theme_plugin');
       $formwriter = new FormWriter();
       
       // Build options array for FormWriter
       $plugin_options = array('' => '-- Select Plugin --');
       foreach ($available_plugins as $plugin_name => $plugin_helper) {
           // PluginHelper::getAvailablePlugins returns array of PluginHelper instances
           $plugin_options[$plugin_name] = $plugin_helper->getDisplayName();
       }
       
       echo $formwriter->dropinput('Active Theme Plugin', 'active_theme_plugin', '', $plugin_options, $current_plugin, 'Select which plugin provides the user interface', FALSE);
   }
   ```
   
   Add JavaScript function following the existing admin_settings pattern:
   ```javascript
   function set_plugin_theme_choices(){
       var value = $("#theme_template").val();
       if(value === 'plugin'){  
           $("#active_theme_plugin_container").show();
       } else { 
           $("#active_theme_plugin_container").hide();
       }		
   }
   ```
   
   Add to existing JavaScript calls:
   ```javascript
   $("#theme_template").change(function(){
       set_plugin_theme_choices();
   });
   
   $(document).ready(function(){
       // ... existing ready functions ...
       set_plugin_theme_choices();
   });
   ```
   
   The `active_theme_plugin` setting will save automatically with the existing form processing, just like all other settings.

**Testing**:
- Test that plugin theme + controld redirects homepage to /controld
- Verify PublicPage.php loads from /plugins/controld/includes/
- Test missing plugin shows helpful error
- Test that regular themes still work normally
- Verify login redirects to plugin login route


---

## Required Migrations and Updates

These migrations MUST be added to `/migrations/migrations.php`:

```php
// Migration 1: Rename blank theme to plugin theme
$migration = array();
$migration['database_version'] = '0.XX'; // TODO: Replace XX with actual next version number
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template' AND stg_value = 'blank'";
$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = 'plugin' WHERE stg_name = 'theme_template' AND stg_value = 'blank';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Migration 2: Add active_theme_plugin setting  
$migration = array();
$migration['database_version'] = '0.XX'; // TODO: Replace XX with actual next version number
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'active_theme_plugin'";
$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('active_theme_plugin', '');";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

### File System Changes

1. **Rename theme directory**: 
   - FROM: `/theme/blank/`
   - TO: `/theme/plugin/`
   
2. **Update theme.json** in `/theme/plugin/`:
   ```json
   {
     "name": "plugin",
     "display_name": "Plugin-Provided Theme",
     "description": "Delegates all theme functionality to a selected plugin",
     "author": "System",
     "version": "1.0.0",
     "framework": "none"
   }
   ```

### Flexible File Loading System

**How Plugin Theme File Loading Works:**

When a plugin is set as the active theme provider, the system will check for ANY file in the plugin directory first before falling back to the theme or system directories. This provides maximum flexibility:

1. **Required files** (must exist for basic functionality):
   - `/plugins/{name}/includes/PublicPage.php` - Base page class
   - `/plugins/{name}/includes/FormWriter.php` - Form generation class

2. **Optional override files** (plugin can provide any of these):
   - `/plugins/{name}/includes/AdminPage.php` - Admin page base class
   - `/plugins/{name}/includes/EmailTemplate.php` - Email template class
   - `/plugins/{name}/views/error/404.php` - Custom 404 page
   - `/plugins/{name}/views/error/500.php` - Custom error page
   - `/plugins/{name}/templates/pdf/*.php` - PDF generation templates
   - `/plugins/{name}/views/*.php` - Any view override
   - Any other file the plugin wants to override

3. **File Resolution Order**:
   - First: Check `/plugins/{active_plugin}/{subdirectory}/{file}`
   - Second: Check `/theme/{current_theme}/{subdirectory}/{file}` 
   - Third: Check base system directory for the file
   - If not found anywhere: System throws appropriate exception

This approach means plugin themes can:
- Override only what they need (minimal approach)
- Override everything (complete replacement)
- Add new files not in the base system

### ControlD Plugin Updates

For ControlD to work as a theme provider, these files MUST be added:

1. **Create** `/plugins/controld/includes/PublicPage.php`:
   Copy the complete structure from Sassa theme's PublicPage:
   ```php
   <?php
   $settings = Globalvars::get_instance();
   $siteDir = $settings->get_setting('siteDir');
   require_once($siteDir . '/includes/PublicPageBase.php');

   class PublicPage extends PublicPageBase {

       // Implement abstract method from PublicPageBase
       protected function getTableClasses() {
           return [
               'wrapper' => 'table-responsive scrollbar',
               'table' => 'table',
               'header' => 'thead-light'
           ];
       }

       public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
           $page = new PublicPage();
           $page->public_header(
               array_merge(
                   array(
                       'title' => $title,
                       'showheader' => TRUE
                   ),
                   $options));
           echo PublicPage::BeginPage($title);
           echo PublicPage::BeginPanel();
           echo '<div class="text-lg max-w-prose mx-auto">';
           echo '<div>'.$body.'</div>';
           echo '</div>';
           
           echo PublicPage::EndPanel();
           echo PublicPage::EndPage();
           $page->public_footer();
           exit;
       }
       
       // Copy all other methods from Sassa PublicPage:
       // - Change asset paths from /theme/sassa/ to /plugins/controld/
       // - Keep same method signatures and structure
       // - No branding or styling changes needed for this refactor
   }
   ```

2. **Create** `/plugins/controld/includes/FormWriter.php`:
   Copy the complete structure from Sassa theme's FormWriter:
   ```php
   <?php
   require_once(__DIR__ . '/../../../includes/PathHelper.php');

   PathHelper::requireOnce('includes/Globalvars.php');
   PathHelper::requireOnce('includes/DbConnector.php');
   PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');

   class FormWriter extends FormWriterMasterBootstrap { 

       // Copy all validation styling and CSS classes from Sassa FormWriter
       // Keep exactly the same for this refactor
       public $validate_style_info = '...'; // Same validation config as Sassa
       
       // Copy all protected class properties for form styling
       protected $button_primary_class = 'th-btn'; // Or ControlD equivalent
       protected $button_secondary_class = 'th-btn style2'; // Or ControlD equivalent
       // ... all other styling properties from Sassa
       
       // Copy all methods: begin_form(), end_form(), toggleinput(), 
       // new_button(), new_form_button(), etc.
       // Keep same method signatures and functionality
   }
   ```

3. **Update** `/plugins/controld/plugin.json`:
   Add the `provides_theme` flag to indicate this plugin can act as a theme provider:
   ```json
   {
       ... existing fields unchanged ...
       "provides_theme": true,
       ... rest of existing fields ...
   }
   ```

---

## Phase 2: Documentation Updates

### Update Plugin Developer Guide

**File**: `/docs/claude/plugin_developer_guide.md`

Add comprehensive documentation about the Plugin Theme System:

#### Plugin Theme System Overview

The plugin theme system allows plugins to act as complete theme providers, replacing the entire user interface while maintaining all plugin functionality. This enables white-label solutions, complete UI replacements, and branded experiences.

**How the System Works:**

1. **PathHelper** intercepts theme file requests and redirects to plugin directory for PHP classes
2. **RouteHelper** sets template directory to plugin path for view loading
3. **ThemeHelper** serves assets from plugin directory instead of theme directory
4. **Admin Settings** provides UI for selecting which plugin provides the theme

#### Three Types of Plugins

##### 1. Feature Plugins (Standard)
**Purpose**: Add specific functionality without affecting the UI
**Examples**: Bookings, Items, OAuth providers, Payment processors
**Characteristics**:
- Work within existing theme framework
- Add new routes under `/[plugin-name]/*`
- Can provide admin interfaces
- Cannot override system views or routes

**Directory Structure**:
```
/plugins/bookings/
├── plugin.json
├── serve.php
├── admin/
│   └── manage_bookings.php
├── views/
│   └── booking_list.php
└── assets/
    └── js/bookings.js
```

##### 2. Theme Provider Plugins
**Purpose**: Complete UI replacement when selected as active theme
**Examples**: ControlD, White-label solutions, Custom branded interfaces

**Required Files**:
```
/plugins/controld/
├── plugin.json (with "provides_theme": true)
├── serve.php
├── includes/
│   ├── PublicPage.php (required - base page class)
│   └── FormWriter.php (required - form generation)
├── views/
│   ├── index.php (homepage view)
│   ├── profile.php (user profile)
│   └── [other system view overrides]
└── assets/
    ├── css/style.css
    ├── js/main.js
    └── img/logo.png
```

**How Theme Provider Mode Works**:
1. Admin selects "plugin" as the theme
2. Admin selects specific plugin (e.g., "controld") as the theme provider
3. System modifications activate:
   - PathHelper loads PHP classes from `/plugins/controld/includes/`
   - RouteHelper loads views from `/plugins/controld/views/`
   - ThemeHelper loads assets from `/plugins/controld/assets/`
4. Plugin provides complete UI while system handles core functionality

##### 3. Hybrid Plugins
**Purpose**: Dual-mode plugins that can work as features OR complete themes
**Examples**: Complex applications with optional standalone mode

**Behavior Modes**:
- **Feature Mode**: When regular theme active, provides features within that theme
- **Theme Mode**: When selected as theme provider, replaces entire UI
- Same codebase, different activation modes

### System Configuration Documentation

#### New Database Settings

**`active_theme_plugin`**
- **Type**: String (plugin directory name)
- **Default**: Empty string
- **Purpose**: Specifies which plugin provides the complete UI when plugin theme is active
- **Valid Values**: Must match an installed plugin directory name
- **Dependencies**: Only used when `theme_template = 'plugin'`
- **Example**: `'controld'` to use ControlD plugin as theme

#### Modified Settings

**`theme_template`**
- **New Option**: `'plugin'` - Delegates all theme functionality to a plugin
- **Existing Options**: `'falcon'`, `'sassa'`, `'tailwind'`, etc.

### Admin Interface Documentation

#### Settings Page Updates (`/adm/admin_settings.php`)

**Theme Selection Enhancement**:
When "Plugin-Provided Theme" is selected from the theme dropdown:
1. A new dropdown appears labeled "Active Theme Plugin"
2. Dropdown populates with all installed plugins
3. Plugins with `"provides_theme": true` are prioritized
4. Help text explains the plugin must provide theme infrastructure

**JavaScript Behavior**:
- Plugin selector is hidden when regular themes are selected
- Plugin selector shows immediately when "plugin" theme is selected
- Settings save normally through existing form processing

### Technical Implementation Notes

#### File Resolution Order

When plugin theme is active, the system checks for files in this order:

**For PHP Classes** (via PathHelper):
1. `/plugins/{active_plugin}/includes/{file}`
2. `/theme/plugin/includes/{file}` (fallback)
3. `/includes/{file}` (system fallback)

**For Views** (via RouteHelper/ThemeHelper):
1. `/plugins/{active_plugin}/views/{file}`
2. `/views/{file}` (system fallback)

**For Assets** (via ThemeHelper):
1. `/plugins/{active_plugin}/assets/{file}`
2. `/theme/plugin/assets/{file}` (shouldn't exist)
3. Current route's plugin assets (existing behavior)

#### Performance Considerations

- **Additional Database Queries**: One extra query to get `active_theme_plugin` setting
- **File Existence Checks**: Additional `is_dir()` and `file_exists()` checks
- **Caching Opportunity**: Could cache plugin theme selection in session
- **Impact**: Minimal - only adds conditional checks when plugin theme active

#### Security Considerations

- **Plugin Validation**: System should verify plugin exists before activation
- **Fallback Strategy**: Falls back to safe defaults if plugin missing
- **No New Attack Vectors**: Uses existing file inclusion mechanisms
- **Admin Only**: Theme selection requires admin permissions

---

## Phase 3: Plugin Validation

### Theme Provider Plugin Requirements

For plugins to work as theme providers, they must meet these validation requirements:

**Required Route**:
- Must provide route: `/[plugin-name]` (main dashboard/homepage)

**Required Files**:
- `/plugins/[name]/includes/PublicPage.php` (or variant)
- `/plugins/[name]/includes/FormWriter*.php` (can be wrapper of system FormWriter)

**Route Restrictions**:
- Cannot define: `/login`, `/logout`, `/register` routes (system handles authentication)

### Validation Implementation

These requirements should be validated when:
- Plugin is selected as active theme plugin
- During plugin installation/activation
- In admin interface (show warnings for non-compliant plugins)

### Plugin Removal Protection

**Problem**: Prevent uninstalling a plugin that's currently the active theme provider.

**Implementation**: 
- Before plugin uninstall, check if plugin is the current `active_theme_plugin`
- If yes, prevent uninstall with error message: "Cannot uninstall plugin while it is the active theme provider. Please select a different theme first."
- Add this check to the plugin uninstall validation in `admin_plugins.php` or relevant uninstall handler

