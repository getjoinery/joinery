# Theme and Plugin System Bug Fixes Specification

## Overview
This specification addresses remaining bugs in the theme and plugin system, implementing a simplified "plugin-provided theme" approach where plugins can serve as complete themes when the "plugin" theme is selected.

## Completed Fixes ✅

### Bug 2: Missing Globalvars Include in PathHelper ✅ FIXED
**Problem**: PathHelper used Globalvars but didn't include it, causing potential fatal errors.
**Solution**: Added `require_once(__DIR__ . '/Globalvars.php');` to PathHelper.php
**Result**: PathHelper now loads independently without dependency issues.

### Bug 1: Improved Error Messages ✅ FIXED  
**Problem**: `getThemeFilePath()` returned `false` causing cryptic "Failed opening required ''" errors.
**Solution**: Changed `getThemeFilePath()` to throw descriptive exceptions instead of returning false.
**Result**: Users now get clear, actionable error messages.

---

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

### Bug 3: Implement Plugin-Provided Theme System

**Problem**: Need to allow plugins to provide complete theme functionality.

**Solution**: Simple redirects and plugin directory as theme directory

**Implementation Steps**:

1. **Database Migration** (add to `/migrations/migrations.php`):
   ```php
   // Rename blank theme to plugin theme
   $migration = array();
   $migration['database_version'] = '0.XX';
   $migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template' AND stg_value = 'blank'";
   $migration['migration_sql'] = "UPDATE stg_settings SET stg_value = 'plugin' WHERE stg_name = 'theme_template' AND stg_value = 'blank';";
   $migration['migration_file'] = NULL;
   $migrations[] = $migration;
   
   // Add active_theme_plugin setting
   $migration = array();
   $migration['database_version'] = '0.XX';
   $migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'active_theme_plugin'";
   $migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('active_theme_plugin', '');";
   $migration['migration_file'] = NULL;
   $migrations[] = $migration;
   ```

2. **Rename theme directory** from `/theme/blank/` to `/theme/plugin/` and update `/theme/plugin/theme.json`:
   ```json
   {
     "name": "plugin",
     "display_name": "Plugin-Provided Theme",
     "description": "Delegates all theme functionality to a selected plugin",
     "author": "System",
     "version": "1.0.0",
     "framework": "none",
     "is_plugin_theme": true
   }
   ```

3. **Modify PathHelper::getThemeFilePath()** to check plugin directory when plugin theme active:
   ```php
   public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $debug = false){
       $settings = Globalvars::get_instance();
       $siteDir = PathHelper::getBasePath();
       
       // ... existing subdirectory handling ...
       
       // Determine theme directory
       if($theme_name){
           $theme_template = $theme_name;
       } else {
           $theme_template = $settings->get_setting('theme_template', true, true);
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

4. **Modify system routes in serve.php to redirect when plugin theme active**:
   
   **Homepage (around line 167):**
   ```php
   '/' => function($params, $settings, $session, $template_directory) {
       $theme = $settings->get_setting('theme_template', true, true);
       
       if ($theme === 'plugin') {
           $active_theme_plugin = $settings->get_setting('active_theme_plugin');
           if ($active_theme_plugin && is_dir(PathHelper::getIncludePath('plugins/'.$active_theme_plugin))) {
               // Redirect to plugin's main route using RouteHelper for proper URL generation
               PathHelper::requireOnce('includes/LibraryFunctions.php');
               $redirect_url = RouteHelper::url("/$active_theme_plugin");
               LibraryFunctions::Redirect($redirect_url);
               exit;
           } else {
               // Show helpful message
               echo '<!DOCTYPE html><html><head><title>Select UI Plugin</title></head><body>';
               echo '<h1>Plugin Theme Active</h1>';
               if (!$active_theme_plugin) {
                   echo '<p>Please go to admin settings and select a plugin to provide the user interface.</p>';
               } else {
                   echo '<p>Error: Selected plugin "'.$active_theme_plugin.'" not found.</p>';
               }
               $admin_url = RouteHelper::url('/adm/admin_settings');
               echo '<p><a href="'.$admin_url.'">Go to Settings</a></p>';
               echo '</body></html>';
               return true;
           }
       }
       
       // Original homepage logic for non-blank themes continues...
       $alternate_page = $settings->get_setting('alternate_loggedin_homepage');
       // ... rest unchanged ...
   ```
   
   **Note**: Login route (`/login`) remains unchanged - system login is always used regardless of theme. Plugins cannot override the login route.

5. **Plugin requirements for theme providers**:
   - **Must provide route**: `/[plugin-name]` (main dashboard/homepage)
   - **Must provide files**:
     - `/plugins/[name]/includes/PublicPage.php` (or variant)
     - `/plugins/[name]/includes/FormWriter*.php` (can be wrapper of system FormWriter)
   - **Cannot define**: `/login`, `/logout`, `/register` routes (system handles authentication)
   - **Optional**: Plugin manifest can include `"provides_theme": true` for admin UI clarity
   - **Note**: Admin pages (`/adm/*`) remain separate and do not use the theme system

6. **Admin interface implementation** (admin_settings.php):
   
   Add after theme_template dropdown (around line where theme selection is):
   ```php
   // Show plugin selector when plugin theme is active
   $current_theme = $settings->get_setting('theme_template');
   if ($current_theme === 'plugin') {
       // Get list of available plugins
       $plugins_dir = PathHelper::getIncludePath('plugins');
       $available_plugins = array();
       
       if (is_dir($plugins_dir)) {
           $plugins = array_diff(scandir($plugins_dir), array('.', '..'));
           foreach ($plugins as $plugin_name) {
               if (is_dir($plugins_dir . '/' . $plugin_name)) {
                   // Check for plugin.json to get display name
                   $plugin_json = $plugins_dir . '/' . $plugin_name . '/plugin.json';
                   if (file_exists($plugin_json)) {
                       $plugin_data = json_decode(file_get_contents($plugin_json), true);
                       $display_name = $plugin_data['display_name'] ?? $plugin_name;
                       // Optionally check for provides_theme flag
                       if (isset($plugin_data['provides_theme']) && !$plugin_data['provides_theme']) {
                           continue; // Skip plugins that explicitly don't provide themes
                       }
                   } else {
                       $display_name = ucfirst($plugin_name);
                   }
                   $available_plugins[$plugin_name] = $display_name;
               }
           }
       }
       
       // Create dropdown
       $current_plugin = $settings->get_setting('active_theme_plugin');
       ?>
       <tr>
           <td class="admin">Active Theme Plugin:</td>
           <td>
               <select name="active_theme_plugin" class="form-control">
                   <option value="">-- Select Plugin --</option>
                   <?php foreach ($available_plugins as $plugin_name => $display_name): ?>
                       <option value="<?php echo htmlspecialchars($plugin_name); ?>" 
                               <?php echo ($current_plugin === $plugin_name) ? 'selected' : ''; ?>>
                           <?php echo htmlspecialchars($display_name); ?>
                       </option>
                   <?php endforeach; ?>
               </select>
               <small class="form-text text-muted">
                   Select which plugin provides the user interface when using the plugin theme.
                   The plugin must provide theme infrastructure files (PublicPage.php, FormWriter, etc.)
               </small>
           </td>
       </tr>
       <?php
   }
   ```
   
   Add JavaScript to show/hide plugin selector dynamically:
   ```javascript
   <script>
   $(document).ready(function() {
       // Function to toggle plugin selector visibility
       function togglePluginSelector() {
           var selectedTheme = $('select[name="theme_template"]').val();
           if (selectedTheme === 'plugin') {
               $('#plugin-selector-row').show();
           } else {
               $('#plugin-selector-row').hide();
           }
       }
       
       // Initial check
       togglePluginSelector();
       
       // Watch for theme changes
       $('select[name="theme_template"]').on('change', togglePluginSelector);
   });
   </script>
   ```
   
   Modify the plugin selector row to include an ID:
   ```php
   <tr id="plugin-selector-row" style="display: <?php echo ($current_theme === 'plugin') ? 'table-row' : 'none'; ?>;">
   ```
   
   Add to save handler (where other settings are saved):
   ```php
   // Save active_theme_plugin if plugin theme is selected
   if ($_POST['theme_template'] === 'plugin' && isset($_POST['active_theme_plugin'])) {
       $active_plugin = trim($_POST['active_theme_plugin']);
       // Validate plugin exists
       if (empty($active_plugin) || is_dir(PathHelper::getIncludePath('plugins/' . $active_plugin))) {
           $settings->set_setting('active_theme_plugin', $active_plugin);
       } else {
           $error_message = "Selected plugin '$active_plugin' not found";
       }
   } elseif ($_POST['theme_template'] !== 'plugin') {
       // Clear active_theme_plugin if not using plugin theme
       $settings->set_setting('active_theme_plugin', '');
   }
   ```

**Testing**:
- Test that plugin theme + controld redirects homepage to /controld
- Verify PublicPage.php loads from /plugins/controld/includes/
- Test missing plugin shows helpful error
- Test that regular themes still work normally
- Verify login redirects to plugin login route

---

### Bug 4: Add Debug Comments

**Problem**: Hard to tell which theme/plugin is active during development

**Solution**: Add HTML comments with debug information

**Implementation** in RouteHelper or when serving views:
```php
// Always include helpful debug comments in HTML output
echo "<!-- System Info\n";
echo "Theme: " . $theme . "\n";
if ($theme === 'plugin') {
    echo "Active Theme Plugin: " . $active_theme_plugin . "\n";
}
echo "File: " . $current_file . "\n";
echo "Route: " . $_SERVER['REQUEST_URI'] . "\n";
echo "Session: " . ($session->is_logged_in() ? 'logged_in' : 'guest') . "\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "-->\n";
```

---

## What This Approach Eliminates

### No Longer Needed:
- ❌ Complex route filtering in RouteHelper
- ❌ Conditional route loading based on theme provider status
- ❌ Protected routes that plugins can sometimes override
- ❌ Complex merging logic
- ❌ Installation validation for routes

### Plugins Simply:
- Define their own routes under their namespace: `/controld`, `/controld/login`, etc.
- Provide theme files in their plugin directory
- Work exactly the same whether they're a theme provider or not

---

## Potential Edge Cases and Solutions

### Edge Case 1: Plugin Uninstalled but Still Selected
**Problem**: `active_theme_plugin` points to non-existent plugin
**Solution**: Check `is_dir()` before redirect, show error if missing
**Status**: ✅ Addressed in code above

### Edge Case 2: Plugin Has No Login Route
**Problem**: System redirects to `/controld/login` but it doesn't exist
**Solution**: Plugin must provide login route or handle 404 appropriately
**Status**: ✅ Plugin requirement

### Edge Case 3: Logout Flow
**Problem**: After logout, where does user go?
**Solution**: System handles normally - redirects to `/` which then redirects to `/controld`
**Status**: ✅ Works automatically

### Edge Case 4: Subdirectory Installations
**Problem**: Site installed at `/myapp/` needs proper URL handling
**Solution**: Use RouteHelper::url() for all redirects
**Status**: ✅ Addressed using RouteHelper

### Edge Case 5: Admin Pages
**Problem**: Admin pages should not use plugin theme
**Solution**: Admin system remains separate, doesn't use theme system
**Status**: ✅ By design

### Edge Case 6: FormWriter Classes
**Problem**: System expects FormWriter classes that plugin may not provide
**Solution**: Plugins must provide FormWriter (can wrap system FormWriter)
**Status**: ✅ Plugin requirement

### Edge Case 7: Circular Redirects
**Problem**: Plugin's main route redirects back to `/`
**Solution**: This would be a plugin bug, not system's problem
**Status**: ✅ Plugin responsibility

### Edge Case 8: Performance Impact
**Problem**: Two database queries on every request (theme + plugin setting)
**Solution**: Accept for now, could cache in session later
**Status**: ✅ Acceptable tradeoff for simplicity

---

## Success Criteria

1. **✅ Clear Error Messages**: Already achieved
2. **Simple Mental Model**: Plugin theme = redirect to plugin
3. **No Complex Logic**: Just redirects and directory substitution
4. **Backward Compatible**: Existing themes unchanged
5. **Plugin Simplicity**: Plugins work the same way regardless

---

## Implementation Priority

1. **First**: Update PathHelper::getThemeFilePath() - enables file loading
2. **Second**: Update homepage route - enables basic functionality  
3. **Third**: Update login route - enables full auth flow
4. **Fourth**: Add admin UI - enables user configuration
5. **Fifth**: Update controld plugin - provide theme files

---

## Documentation Updates Required

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
- Should set `"provides_theme": true` in plugin.json for clarity

**Restrictions**:
- CANNOT define authentication routes (`/login`, `/logout`, `/register`)
- Must handle all theme responsibilities when active
- Must provide all required theme infrastructure files
- System login is always used for authentication

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

## Required Migrations and Updates

### Database Migrations (Part of Bug 3 Implementation)

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
     "framework": "none",
     "is_plugin_theme": true
   }
   ```

### ControlD Plugin Updates

For ControlD to work as a theme provider, these files MUST be added:

1. **Create** `/plugins/controld/includes/PublicPage.php`:
   ```php
   <?php
   // Minimal PublicPage implementation for ControlD
   class PublicPage {
       public function public_header($options = []) {
           // ControlD specific header
           echo '<!DOCTYPE html><html><head>';
           echo '<title>' . ($options['title'] ?? 'ControlD') . '</title>';
           echo '</head><body>';
       }
       
       public function public_footer($options = []) {
           echo '</body></html>';
       }
       
       public static function BeginPage($class = '') {
           return '<div class="controld-page ' . $class . '">';
       }
       
       public static function EndPage() {
           return '</div>';
       }
   }
   ```

2. **Create** `/plugins/controld/includes/FormWriter.php`:
   ```php
   <?php
   // Wrapper for system FormWriter
   PathHelper::requireOnce('includes/FormWriterMaster.php');
   
   class FormWriter extends FormWriterMaster {
       // Use system FormWriter with any ControlD-specific overrides
   }
   ```

3. **Update** `/plugins/controld/plugin.json`:
   ```json
   {
       "name": "controld",
       "display_name": "ControlD DNS Filtering",
       "version": "2.0.0",
       "description": "Complete ControlD DNS filtering service with integrated UI",
       "author": "System Developer",
       "type": "application",
       "routes_prefix": "/controld",
       "provides": ["dns_filtering", "device_management", "content_filtering"],
       "provides_theme": true,
       "is_stock": true,
       "requires": {
           "php": ">=8.0",
           "extensions": ["curl"]
       }
   }
   ```

4. **Verify** `/plugins/controld/serve.php` has main route:
   ```php
   $routes = [
       'dynamic' => [
           '/controld' => [
               'view' => 'views/index',
               'plugin_specify' => 'controld'
           ],
           // ... other routes
       ]
   ];
   ```

### Testing Migration

After implementing migrations:

1. Run database migrations: `php /utils/update_database.php`
2. Verify settings in database:
   ```sql
   SELECT * FROM stg_settings WHERE stg_name IN ('theme_template', 'active_theme_plugin');
   ```
3. Check theme directory renamed properly
4. Test homepage redirect to `/controld` when plugin theme active
5. Verify PublicPage loads from plugin directory
6. Confirm FormWriter works correctly