# Theme and Plugin System Bug Fixes Specification

## Overview
This specification addresses remaining bugs in the theme and plugin system, implementing a simplified "plugin-provided theme" approach where plugins can serve as complete themes when the "plugin" theme is selected.


## Simplified Approach: Always Redirect

### Core Concept
When "plugin" theme is selected:
1. System routes (/, /login, etc.) always redirect to plugin routes
2. Plugin directory serves as theme directory for assets and includes
3. No complex route filtering or conditional loading needed

### How It Works
1. User selects "plugin" as the theme (renamed from "blank")
2. User selects which plugin provides the UI (stored in `active_theme_plugin` setting)
3. Homepage and system routes redirect to plugin routes (`/controld`, `/controld/login`)
4. Theme files (PublicPage, FormWriter, etc.) load from `/plugins/[plugin-name]/`

---

## Remaining Bug Fixes (In Implementation Order)

### Plugin-Provided Theme System Implementation

**Problem**: Need to allow plugins to provide complete theme functionality.

**Solution**: Simple redirects and plugin directory as theme directory

## Files to Modify

### PathHelper.php

Modify `getThemeFilePath()` to check plugin directory when plugin theme active:
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
       
       // NEW: Check if plugin theme with active plugin
       if($theme_template === 'plugin') {
           $active_theme_plugin = $settings->get_setting('active_theme_plugin');
           if($active_theme_plugin && is_dir($siteDir.'/plugins/'.$active_theme_plugin)) {
               // Use plugin as theme directory
               $theme_file = $siteDir.'/plugins/'.$active_theme_plugin.$subdirectory.'/'.$filename;
               if(file_exists($theme_file)) {
                   if($path_format == 'system'){
                       return $theme_file;
                   } else {
                       return '/plugins/'.$active_theme_plugin.$subdirectory.'/'.$filename;
                   }
               }
           }
           // If no plugin or file not found in plugin, continue to normal flow
       }
       
       // ... rest of existing logic (will throw exception if file not found) ...
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

Add new section describing the three types of plugins:

#### 1. Feature Plugins (Standard)
**Purpose**: Add specific functionality to the system
**Examples**: Bookings, Items, OAuth provider
**Requirements**:
- Must NOT define protected routes (/, /login, /logout, /register, /404, /500)
- Must use namespaced routes: `/[plugin-name]/*`
- Can provide admin pages at `/plugins/[name]/admin/*`
- Can provide API endpoints, webhooks, assets

**Restrictions**:
- Cannot override system routes
- Cannot act as theme provider
- Routes always active when plugin installed

#### 2. Theme Provider Plugins
**Purpose**: Provide complete UI/theme when plugin theme selected
**Examples**: ControlD, custom branded interfaces
**Requirements**:
- Must provide `/[plugin-name]` main route
- Must provide `/plugins/[name]/includes/PublicPage.php`
- Must provide `/plugins/[name]/includes/FormWriter*.php` (can wrap system classes)
- Should set `"provides_theme": true` in plugin.json for admin UI clarity

**Restrictions**:
- CANNOT define authentication routes (`/login`, `/logout`, `/register`)
- Must handle all theme responsibilities when active
- Must provide all required theme infrastructure files
- System login is always used for authentication
- Admin pages (`/adm/*`) remain separate and do not use the theme system

**How it works**:
1. User selects "plugin" theme in settings
2. User selects this plugin as UI provider
3. System routes (/, /login) redirect to plugin routes
4. Theme files load from plugin directory

#### 3. Hybrid Plugins
**Purpose**: Work as feature plugin normally, can also provide theme
**Examples**: Complex applications that can run standalone or integrated
**Requirements**:
- Must meet all Feature Plugin requirements for normal operation
- Must meet all Theme Provider requirements when acting as theme
- Must work correctly in both modes

**Behavior**:
- When NOT theme provider: Protected routes filtered out, only namespaced routes work
- When IS theme provider: All routes active, serves as complete theme

### Add Configuration Documentation

Document the new `active_theme_plugin` setting:

```sql
-- Setting: active_theme_plugin
-- Type: string (plugin name)
-- Default: empty string
-- Purpose: When theme_template='plugin', specifies which plugin provides the UI
-- Values: Must match installed plugin directory name
```

### Update Admin Interface Documentation

Document admin_settings.php changes:
- When `theme_template` = 'plugin', show plugin selector
- Dropdown shows all installed plugins
- Optionally filter by `provides_theme` flag
- Clear help text explaining the plugin theme concept

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

