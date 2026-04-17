# Remove Plugin-as-Theme Functionality Specification

## Overview

This document outlines the remaining changes needed to completely separate themes and plugins in the Joinery system. The core RouteHelper changes have been completed in serve_refactor.md, which resolves the architectural issue of loading theme vs plugin serve.php files.

## Problem Statement

The current system allows plugins to act as themes, which creates several issues:

1. **Route Loading Conflict**: Themes have ONE serve.php, but we need ALL plugin serve.php files to load
2. **Architectural Ambiguity**: Is a plugin-theme THE theme or A plugin?
3. **Maintenance Complexity**: Code paths must handle both directory themes and plugin themes
4. **Testing Difficulty**: Multiple code paths for theme resolution

## Core Principle

**Themes are themes, plugins are plugins. No overlap.**

- Themes live in `/theme/` exclusively
- Plugins live in `/plugins/` exclusively
- A component can be EITHER a theme OR a plugin, never both

## Files Requiring Changes

### 1. Helper Classes

#### includes/PluginHelper.php
While PluginHelper doesn't currently have explicit theme-related code, the `providesFeature()` method could be used to check if a plugin provides theme functionality. To prevent future misuse:

**Remove or restrict the providesFeature method:**
```php
// CURRENT CODE (lines 353-356):
public function providesFeature($feature) {
    $provides = $this->manifestData['provides'] ?? [];
    return in_array($feature, $provides);
}

// CHANGE TO:
public function providesFeature($feature) {
    // Explicitly prevent plugins from providing theme functionality
    if ($feature === 'theme') {
        return false;
    }
    $provides = $this->manifestData['provides'] ?? [];
    return in_array($feature, $provides);
}
```

**Add validation to prevent theme in provides array:**
```php
// In validate() method, after line 183, ADD:
// Check that plugin doesn't claim to provide theme functionality
if (isset($this->manifestData['provides']) && in_array('theme', $this->manifestData['provides'])) {
    $errors[] = "Plugins cannot provide theme functionality. Use a separate theme in /theme/ directory.";
}
```

#### includes/PathHelper.php
The `getThemeFilePath()` method has multiple checks for plugin themes that need to be removed:

**Change 1 - Lines 53-62 (when theme_name is provided):**
```php
// CURRENT CODE:
if($theme_name){
    $theme_template = $theme_name;
    
    // Check if it's a directory theme first, then plugin
    if(is_dir($siteDir.'/theme/'.$theme_template)){
        // It's a directory theme - existing logic
        $is_plugin_theme = false;
    } elseif(PluginHelper::isPluginActive($theme_template)) {
        // It's a plugin theme
        $is_plugin_theme = true;
    } else {
        throw new SystemDisplayablePermanentError('Could not find the specified theme: '. $theme_name);
    }
}

// CHANGE TO:
if($theme_name){
    $theme_template = $theme_name;
    
    // Only check directory themes
    if(is_dir($siteDir.'/theme/'.$theme_template)){
        // It's a directory theme
    } else {
        throw new SystemDisplayablePermanentError('Could not find the specified theme: '. $theme_name);
    }
}
```

**Change 2 - Lines 70-82 (when using default theme):**
```php
// CURRENT CODE:
if($theme_template) {
    if(is_dir($siteDir.'/theme/'.$theme_template)){
        $is_plugin_theme = false;
    } elseif(PluginHelper::isPluginActive($theme_template)) {
        $is_plugin_theme = true;
    } else {
        // Invalid theme, set to null
        $theme_template = null;
        $is_plugin_theme = false;
    }
} else {
    $is_plugin_theme = false;
}

// CHANGE TO:
if($theme_template) {
    if(!is_dir($siteDir.'/theme/'.$theme_template)){
        // Invalid theme, set to null
        $theme_template = null;
    }
}
```

**Change 3 - Lines 91-97 & 113-118 (path building logic):**
```php
// CURRENT CODE:
if($theme_template) {
    if($is_plugin_theme) {
        $theme_file = $siteDir.'/plugins/'.$theme_template.$subdirectory.'/'.$filename;
    } else {
        $theme_file = $siteDir.'/theme/'.$theme_template.$subdirectory.'/'.$filename;
    }
}

// CHANGE TO:
if($theme_template) {
    $theme_file = $siteDir.'/theme/'.$theme_template.$subdirectory.'/'.$filename;
}

// SIMILARLY FOR URL PATH (lines 113-118):
// CURRENT:
if($is_plugin_theme) {
    return '/plugins/'.$theme_template.$subdirectory.'/'.$filename;
} else {
    return '/theme/'.$theme_template.$subdirectory.'/'.$filename;
}

// CHANGE TO:
return '/theme/'.$theme_template.$subdirectory.'/'.$filename;
```

**Remove all `$is_plugin_theme` variable references** - This variable is no longer needed

#### includes/LibraryFunctions.php
**Multiple Functions Need Updates:**

**1. `display_404_page()` - Lines 149-180:**
```php
// CURRENT CODE (lines 154-160):
// Try directory theme first, then plugin
$theme_file = null;
if (ThemeHelper::themeExists($theme_template)) {
    $theme_file = PathHelper::getBasePath() . '/theme/'.$theme_template.'/404.php';
} elseif (PluginHelper::isPluginActive($theme_template)) {
    $theme_file = PathHelper::getBasePath() . '/plugins/'.$theme_template.'/views/404.php';
}

// CHANGE TO:
// Only check directory themes
$theme_file = null;
if (ThemeHelper::themeExists($theme_template)) {
    $theme_file = PathHelper::getBasePath() . '/theme/'.$theme_template.'/404.php';
}
```

**2. `get_formwriter_object()` - Lines 300-324:**
```php
// CURRENT CODE (lines 311-318):
} elseif (PluginHelper::isPluginActive($theme_template)) {
    // Try plugin theme FormWriter
    $plugin_form = PathHelper::getBasePath() . '/plugins/' . $theme_template . '/includes/FormWriter.php';
    if (file_exists($plugin_form)) {
        require_once($plugin_form);
        return new FormWriter($form_id);
    }
}

// REMOVE ENTIRE elseif BLOCK - Lines 311-318
```

**3. `get_logic_file_path()` - Lines 400-453:**
```php
// CURRENT CODE (lines 405-414):
// Try directory theme first, then plugin
$theme_file = null;
$theme_url_path = null;
if (ThemeHelper::themeExists($theme_template)) {
    $theme_file = $siteDir.'/theme/'.$theme_template.'/logic/'.$filename;
    $theme_url_path = '/theme/'.$theme_template.'/logic/'.basename($filename, '.php');
} elseif (PluginHelper::isPluginActive($theme_template)) {
    $theme_file = $siteDir.'/plugins/'.$theme_template.'/logic/'.$filename;
    $theme_url_path = '/plugins/'.$theme_template.'/logic/'.basename($filename, '.php');
}

// CHANGE TO:
// Only check directory themes
$theme_file = null;
$theme_url_path = null;
if (ThemeHelper::themeExists($theme_template)) {
    $theme_file = $siteDir.'/theme/'.$theme_template.'/logic/'.$filename;
    $theme_url_path = '/theme/'.$theme_template.'/logic/'.basename($filename, '.php');
}
```

### 2. Admin Interface

#### ajax/theme_switch_ajax.php
**Theme Validation Logic - Lines 40-54:**

```php
// CURRENT CODE (lines 40-54):
// Validate theme exists - try directory theme first, then plugin
$valid_theme = false;

if (ThemeHelper::themeExists($theme)) {
    // It's a valid directory theme
    $valid_theme = true;
} elseif (PluginHelper::isPluginActive($theme)) {
    // It's an active plugin that can act as theme
    $valid_theme = true;
} 

if (!$valid_theme) {
    echo json_encode(array('success' => false, 'message' => 'Theme not found'));
    exit;
}

// CHANGE TO:
// Validate theme exists - directory themes only
$valid_theme = false;

if (ThemeHelper::themeExists($theme)) {
    // It's a valid directory theme
    $valid_theme = true;
}

if (!$valid_theme) {
    echo json_encode(array('success' => false, 'message' => 'Theme not found'));
    exit;
}
```

**Changes Required:**
1. Remove lines 46-48: The entire `elseif (PluginHelper::isPluginActive($theme))` block
2. Update comment on line 40 from "try directory theme first, then plugin" to "directory themes only"

#### adm/admin_settings.php
**Theme Dropdown Code - Lines 460-481:**

```php
// CURRENT CODE (lines 460-477):
// Get themes from both sources
$directory_themes = ThemeHelper::getAvailableThemes();
$plugins = PluginHelper::getActivePlugins();

// Build options array
$optionvals = array();

// Add directory themes
foreach($directory_themes as $theme_name => $theme_helper) {
    $display_name = $theme_helper->get('display_name', $theme_name);
    $optionvals[$theme_name] = $display_name;
}

// Add plugins as themes
foreach($plugins as $plugin_name => $plugin) {
    $display_name = $plugin->getPluginName() . ' (Plugin)';
    $optionvals[$plugin_name] = $display_name;
}

// CHANGE TO:
// Get themes from directory only
$directory_themes = ThemeHelper::getAvailableThemes();

// Build options array
$optionvals = array();

// Add directory themes only
foreach($directory_themes as $theme_name => $theme_helper) {
    $display_name = $theme_helper->get('display_name', $theme_name);
    $optionvals[$theme_name] = $display_name;
}
```

**Changes Required:**
1. Remove line 462: `$plugins = PluginHelper::getActivePlugins();`
2. Remove lines 473-477: The entire "Add plugins as themes" foreach loop
3. Update comment on line 460 from "Get themes from both sources" to "Get themes from directory only"

### 3. Public Page Classes

#### includes/PublicPageBase.php
**Admin Bar Theme Switcher - Lines 565-615:**

```php
// CURRENT CODE (lines 565-567):
// Get themes from both sources
$directory_themes = ThemeHelper::getAvailableThemes();
$plugins = PluginHelper::getActivePlugins();

// CHANGE TO:
// Get themes from directory only
$directory_themes = ThemeHelper::getAvailableThemes();
```

**Remove Plugin Themes from Admin Bar Dropdown - Lines 604-615:**
```php
// REMOVE ENTIRE BLOCK (lines 604-615):
<?php 
// Display plugins as themes
foreach ($plugins as $plugin_name => $plugin): 
    $display_name = $plugin->getPluginName();
    ?>
    <a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($plugin_name); ?>'); return false;" 
       <?php echo ($plugin_name == $theme_template) ? 'style="font-weight: bold !important;"' : ''; ?>>
        <?php echo htmlspecialchars($display_name); ?>
        <span style="font-size: 0.8em; opacity: 0.7;">(Plugin)</span>
        <?php echo ($plugin_name == $theme_template) ? ' ✓' : ''; ?>
    </a>
<?php endforeach; ?>
```

**Changes Required:**
1. Remove line 567: `$plugins = PluginHelper::getActivePlugins();`
2. Update comment on line 565 from "Get themes from both sources" to "Get themes from directory only"
3. Remove entire plugin theme loop (lines 604-615)
4. **Optional:** Remove line 7: `require_once('PluginHelper.php');` if no longer needed elsewhere in the file

### 4. ControlD/Sassa Split

The ControlD plugin currently contains both theme and plugin functionality (the sassa theme was previously merged into it per sassa_migration.md). 

**Step 1: Restore the sassa theme from git history**
```bash
# Find the commit before sassa was deleted/merged
git log --all --diff-filter=D --summary | grep "sassa"

# Restore the entire sassa theme directory from that commit
git checkout <commit-hash> -- theme/sassa/

# Or if sassa exists in another repository, copy it from there
```

**Step 2: Remove duplicated files from controld plugin**

After restoring sassa theme, remove all files from `/plugins/controld/` that now exist in `/theme/sassa/`:

**Files to remove from controld plugin (if they exist in sassa):**
- `assets/` directory (entire directory if identical to sassa)
- `includes/FormWriter.php` (if sassa has it)
- `includes/PublicPage.php` (if sassa has it)
- Views that are in sassa: cart.php, cart_confirm.php, index.php, login.php, logout.php, pricing.php, product.php
- Profile views that are in sassa
- Logic files that are presentation-focused and exist in sassa

**Files to keep in controld plugin (plugin-specific):**
- Data models in `data/` (these are plugin-specific)
- API endpoints and webhooks
- Admin interface files in `adm/`
- Database migrations in `migrations/`
- Plugin-specific business logic not in sassa
- `plugin.json` manifest

**Step 3: Update references**
- Change all references from `/plugins/controld/assets/` to `/theme/sassa/assets/`
- Update any includes that now point to sassa theme files
- Ensure controld plugin no longer tries to act as a theme

## Implementation Changes

All changes will be implemented at once:

1. Update PluginHelper to block 'theme' feature and add validation
2. Update PathHelper to remove all `PluginHelper::isPluginActive($theme_template)` checks
3. Update LibraryFunctions:
   - `display_404_page()` - Remove plugin theme 404 handling
   - `get_formwriter_object()` - Remove plugin FormWriter checks
   - `get_logic_file_path()` - Remove plugin theme logic paths
4. Update ajax/theme_switch_ajax.php - Remove plugin validation
5. Update admin_settings.php - Theme selector shows only directory themes
6. Update PublicPageBase.php - Remove plugin theme handling
7. Split ControlD into `/theme/sassa/` and `/plugins/controld/`
8. Remove all plugin-as-theme code paths

## Conclusion

This is a breaking change with no backward compatibility. Sites using plugins as themes will need to be manually updated. The ControlD split will create a cleaner architecture with proper separation between theme presentation and plugin functionality.