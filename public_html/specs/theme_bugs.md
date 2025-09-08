# Theme and Plugin System Bug Fixes Specification

## Overview
This specification addresses remaining bugs in the theme and plugin system, implementing a simplified "plugin-provided theme" approach where plugins can serve as complete themes when the "plugin" theme is selected.



## Remaining Bug Fixes (In Implementation Order)

### Plugin-Provided Theme System Implementation

**Problem**: Need to allow plugins to provide complete theme functionality.

**Solution**: Simple redirects and plugin directory as theme directory

## Files to Modify

### PathHelper.php

Modify `getThemeFilePath()` to check plugin directory when plugin theme active (for loading PHP classes like PublicPage and FormWriter):
   ```php
   public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $debug = false){
       $settings = Globalvars::get_instance();
       $siteDir = PathHelper::getBasePath();
       
       // ... existing subdirectory handling ...
       
       // Determine theme directory
       if($theme_name){
           $theme_template = $theme_name;
       } else {
           $theme_template = $settings->get_setting('theme_template');
       }
       
       /**
        * PLUGIN THEME SUPPORT
        * 
        * When the special 'plugin' theme is selected, a plugin can act as the complete theme provider.
        * This allows plugins like ControlD to provide a full UI replacement including:
        * - PublicPage.php (base page class)
        * - FormWriter.php (form generation class)
        * - Other theme infrastructure files
        * 
        * How it works:
        * 1. Admin selects 'plugin' as the theme
        * 2. Admin selects which plugin provides the UI (stored in 'active_theme_plugin' setting)
        * 3. This code redirects theme file lookups to the plugin directory
        * 
        * Example: If active_theme_plugin='controld' and looking for 'PublicPage.php' in 'includes':
        * - Normal theme would check: /theme/falcon/includes/PublicPage.php
        * - Plugin theme checks: /plugins/controld/includes/PublicPage.php
        * 
        * IMPORTANT: Skip plugin theme logic for core system files to prevent circular dependencies
        * Core files like Globalvars.php must always load from system directories
        */
       $core_system_files = array('Globalvars.php', 'Globalvars_site.php', 'DbConnector.php', 'PathHelper.php');
       if($theme_template === 'plugin' && !in_array($filename, $core_system_files)) {
           // Get which plugin is providing the theme functionality
           $active_theme_plugin = $settings->get_setting('active_theme_plugin');
           
           if($active_theme_plugin && is_dir($siteDir.'/plugins/'.$active_theme_plugin)) {
               // Build path to file in plugin directory instead of theme directory
               $theme_file = $siteDir.'/plugins/'.$active_theme_plugin.$subdirectory.'/'.$filename;
               
               if(file_exists($theme_file)) {
                   // Return the plugin file path in requested format
                   if($path_format == 'system'){
                       return $theme_file;  // Full system path
                   } else {
                       return '/plugins/'.$active_theme_plugin.$subdirectory.'/'.$filename;  // Web path
                   }
               }
           }
           // If plugin doesn't have this specific file, that's ok - fall through to normal logic
           // This allows plugins to provide only the files they want to override
       }
       
       // ... rest of existing logic (checks theme directory, then base directory) ...
   ```

### RouteHelper.php

Modify the template directory assignment around line 1010 to handle plugin themes (for loading view files):
   ```php
   // Around line 1007-1012, replace the template_directory assignment with:
   
   /**
    * PLUGIN THEME TEMPLATE DIRECTORY
    * 
    * This section determines which directory to use for template/view files.
    * Normally this points to /theme/{themename}/ but when 'plugin' theme is active,
    * it points to /plugins/{pluginname}/ instead.
    * 
    * This allows plugins to provide complete view overrides including:
    * - Homepage (/views/index.php)
    * - User profile pages
    * - Any other system views
    * 
    * The $template_directory is passed to all route handlers and used to check
    * for theme-specific view overrides before falling back to base views.
    */
   $template_directory = null;
   
   if ($theme_template === 'plugin') {
       /**
        * Plugin Theme Mode
        * A plugin is providing the complete theme/UI
        * Example: ControlD plugin acting as the entire user interface
        */
       
       // Get which plugin is providing the theme
       $active_theme_plugin = $settings->get_setting('active_theme_plugin');
       
       if ($active_theme_plugin && is_dir(PathHelper::getIncludePath('plugins/'.$active_theme_plugin))) {
           // Use the plugin directory as the template directory
           // This means views will be loaded from /plugins/{plugin}/views/ first
           $template_directory = PathHelper::getIncludePath('plugins/'.$active_theme_plugin);
       } else {
           // Plugin theme selected but plugin is missing or not configured
           // Throw exception to prevent system from running in broken state
           throw new Exception("Plugin theme is active but plugin '$active_theme_plugin' not found or not configured. Please contact administrator.");
       }
   } else if (ThemeHelper::themeExists($theme_template)) {
       /**
        * Normal Theme Mode
        * Standard theme like 'falcon', 'sassa', etc.
        */
       $template_directory = PathHelper::getIncludePath('theme/'.$theme_template);
   } else {
       // Theme doesn't exist - this shouldn't happen in production
       error_log("ERROR: Theme '$theme_template' does not exist or is invalid");
   }
   ```

### ThemeHelper.php

Modify the `asset()` method around line 148 to handle plugin theme assets:
   ```php
   public static function asset($path, $themeName = null) {
       if ($themeName === null) {
           $themeName = self::getActive();
       }
       
       /**
        * PLUGIN THEME ASSET SUPPORT
        * 
        * When 'plugin' theme is active, assets (CSS, JS, images) need to be loaded
        * from the plugin directory instead of the theme directory.
        * 
        * This modification allows plugins to provide complete asset sets including:
        * - Stylesheets (/assets/css/)
        * - JavaScript (/assets/js/)
        * - Images (/assets/img/)
        * - Fonts (/assets/fonts/)
        * 
        * Example: If active_theme_plugin='controld' and requesting 'css/style.css':
        * - Normal theme would serve: /theme/falcon/assets/css/style.css
        * - Plugin theme serves: /plugins/controld/assets/css/style.css
        * 
        * This completes the plugin theme system by ensuring all resources
        * (PHP classes, views, and assets) can be served from the plugin directory.
        */
       if ($themeName === 'plugin') {
           $settings = Globalvars::get_instance();
           $active_theme_plugin = $settings->get_setting('active_theme_plugin');
           
           if ($active_theme_plugin) {
               // Build path to asset in plugin directory
               $plugin_asset = "/plugins/{$active_theme_plugin}/assets/{$path}";
               
               // Check if asset exists in plugin using PathHelper
               $plugin_asset_path = PathHelper::getIncludePath("plugins/{$active_theme_plugin}/assets/{$path}");
               if (file_exists($plugin_asset_path)) {
                   // Return plugin asset path
                   return $plugin_asset;
               }
               // If asset not found in plugin, fall through to normal theme logic
               // This allows plugins to selectively override only some assets
           }
       }
       
       /**
        * Normal Theme Asset Loading
        * Check standard theme directory for assets
        */
       $theme_asset = "/theme/{$themeName}/assets/{$path}";
       $theme_asset_path = PathHelper::getIncludePath("theme/{$themeName}/assets/{$path}");
       if (file_exists($theme_asset_path)) {
           $version = self::getAssetVersion($themeName, $path);
           $versionString = $version ? "?v={$version}" : '';
           return "{$theme_asset}{$versionString}";
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
       // Filter for theme providers if desired
       // TODO: Check existing method signature and adapt as needed
       
       // Create FormWriter dropdown following existing admin_settings pattern
       $current_plugin = $settings->get_setting('active_theme_plugin');
       $formwriter = new FormWriter();
       
       // Build options array for FormWriter
       $plugin_options = array('' => '-- Select Plugin --');
       foreach ($available_plugins as $plugin_name => $display_name) {
           $plugin_options[$plugin_name] = $display_name;
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
$migration['database_version'] = '0.XX'; // Use next version number
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template' AND stg_value = 'blank'";
$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = 'plugin' WHERE stg_name = 'theme_template' AND stg_value = 'blank';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Migration 2: Add active_theme_plugin setting  
$migration = array();
$migration['database_version'] = '0.XX'; // Use next version number
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

