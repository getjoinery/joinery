# Specification: Remove ThemeHelper::includeThemeFile and Consolidate with Enhanced PathHelper::getThemeFilePath

## Overview

This specification documents the removal of the `ThemeHelper::includeThemeFile()` method and consolidation of all theme file resolution logic into an enhanced `PathHelper::getThemeFilePath()` method. This change eliminates code duplication, fixes variable scope issues, and simplifies the codebase's file inclusion model.

## Problem Statement

The current codebase has two methods for resolving and including theme files:

1. **`PathHelper::getThemeFilePath()`** - Returns file paths with 2-tier fallback (theme → base)
2. **`ThemeHelper::includeThemeFile()`** - Includes files with 3-tier fallback (theme → plugin → base)

### Issues with Current Implementation

1. **Code Duplication**: ~60-80 lines of duplicated file resolution logic between the two methods
2. **Variable Scope Bug**: `includeThemeFile()` isolates variables in method scope, breaking the codebase's assumption of shared scope between logic files and views
3. **Inconsistent Behavior**: Developers must remember which method to use for different scenarios
4. **Circular Dependency**: `ThemeHelper::includeThemeFile()` internally calls `PathHelper::getThemeFilePath()`
5. **Confusing Mental Model**: Two different APIs for essentially the same task

### Evidence of the Problem

The variable scope issue is explicitly documented as a known problem in CLAUDE.md:
> **CRITICAL:** `PathHelper::requireOnce()` includes files in method scope, isolating variables. When you need global scope access to variables defined in the included file (like `$migrations`), use `require_once(PathHelper::getIncludePath())`

The same issue affects `ThemeHelper::includeThemeFile()`, forcing developers to work around the scope isolation.

## Proposed Solution

Enhance `PathHelper::getThemeFilePath()` to handle all file resolution scenarios and remove `ThemeHelper::includeThemeFile()` entirely.

### Enhanced PathHelper::getThemeFilePath Implementation

```php
/**
 * Get the full system path to a theme file with complete override chain support
 * 
 * Resolution order:
 * 1. Theme override: /theme/{theme}/{path}
 * 2. Plugin context: /plugins/{plugin}/{path}  
 * 3. Base fallback: /{path}
 * 
 * @param string $filename Filename only with .php extension (e.g., 'profile.php', 'PublicPage.php')
 * @param string $subdirectory Subdirectory path without leading/trailing slashes (e.g., 'includes', 'assets/css')
 * @param string $path_format 'system' for absolute paths, 'web' for URL paths
 * @param string|null $theme_name Theme to use (null = current theme)
 * @param string|null $plugin_name Plugin name (null = auto-detect from RouteHelper)
 * @param bool $debug Enable debug output
 * @return string|false Path to file or false if not found
 * @throws Exception If file not found and required
 */
public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $plugin_name=NULL, $debug = false) {
    
    // STRICT INPUT VALIDATION - Enforce consistent format across codebase
    
    // 1. Filename validation
    if (empty($filename)) {
        throw new Exception("Filename cannot be empty");
    }
    
    // Filename must end with .php (all current usage does)
    if (substr($filename, -4) !== '.php') {
        throw new Exception("Filename must end with .php extension. Given: '$filename'");
    }
    
    // Filename cannot contain any directory separators
    if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        throw new Exception("Filename cannot contain slashes. Use subdirectory parameter for path. Given: '$filename'");
    }
    
    // 2. Subdirectory validation
    // Subdirectory cannot have leading or trailing slashes (none in current usage)
    if (!empty($subdirectory)) {
        if (substr($subdirectory, 0, 1) === '/' || substr($subdirectory, -1) === '/') {
            throw new Exception("Subdirectory cannot have leading or trailing slashes. Given: '$subdirectory'. Use format: 'includes' or 'assets/css'");
        }
        
        // No double slashes allowed
        if (strpos($subdirectory, '//') !== false) {
            throw new Exception("Subdirectory cannot contain double slashes. Given: '$subdirectory'");
        }
        
        // No backslashes allowed (use forward slashes for nested paths)
        if (strpos($subdirectory, '\\') !== false) {
            throw new Exception("Subdirectory must use forward slashes. Given: '$subdirectory'");
        }
    }
    
    // 3. Security validation - prevent path traversal
    if (strpos($filename, '..') !== false || strpos($subdirectory, '..') !== false) {
        throw new Exception("Path traversal attempt detected ('..') in filename or subdirectory");
    }
    
    // 4. Build clean path with exactly one slash between components
    if ($subdirectory !== '') {
        $relative_path = $subdirectory . '/' . $filename;
    } else {
        $relative_path = $filename;
    }
    
    // Get theme name if not specified
    if ($theme_name === NULL) {
        $theme_name = self::getActiveThemeDirectory();
    }
    
    // Auto-detect plugin name if not specified
    if ($plugin_name === null && class_exists('RouteHelper')) {
        $plugin_name = RouteHelper::getCurrentPlugin();
    }
    
    
    // Get the base directory based on path format
    $base_dir = ($path_format === 'system') ? self::getBasePath() : '';
    
    // 1. Try theme override first
    if ($theme_name) {
        $theme_path = $base_dir . 'theme/' . $theme_name . '/' . $relative_path;
        if ($debug) {
            error_log("getThemeFilePath: Checking theme path: $theme_path");
        }
        if (file_exists($theme_path)) {
            if ($debug) {
                error_log("getThemeFilePath: Found in theme: $theme_path");
            }
            return $theme_path;
        }
    }
    
    // 2. Try plugin path if plugin name exists
    if ($plugin_name) {
        $plugin_path = $base_dir . 'plugins/' . $plugin_name . '/' . $relative_path;
        if ($debug) {
            error_log("getThemeFilePath: Checking plugin path: $plugin_path");
        }
        if (file_exists($plugin_path)) {
            if ($debug) {
                error_log("getThemeFilePath: Found in plugin: $plugin_path");
            }
            return $plugin_path;
        }
    }
    
    // 3. Fall back to base directory
    $base_path = $base_dir . $relative_path;
    if ($debug) {
        error_log("getThemeFilePath: Checking base path: $base_path");
    }
    if (file_exists($base_path)) {
        if ($debug) {
            error_log("getThemeFilePath: Found in base: $base_path");
        }
        return $base_path;
    }
    
    // File not found
    if ($debug) {
        error_log("getThemeFilePath: File not found: $relative_path");
        error_log("getThemeFilePath: Searched locations:");
        error_log("  Theme: " . ($theme_name ? $theme_path : 'none'));
        error_log("  Plugin: " . ($plugin_name ? $plugin_path : 'none'));
        error_log("  Base: $base_path");
    }
    
    // Return false to indicate file not found
    // Caller can decide whether to throw exception
    return false;
}
```

## Migration Requirements

### Files to Update

1. **PathHelper.php** - Enhance `getThemeFilePath()` method with plugin support
2. **49 PHP files using `ThemeHelper::includeThemeFile()`** - Update to use `require_once(PathHelper::getThemeFilePath())`
3. **ThemeHelper.php** - Remove `includeThemeFile()` method and related helper methods
4. **CLAUDE.md** - Update documentation to reflect single method approach

### Migration Count Breakdown

**Total `ThemeHelper::includeThemeFile()` calls:** 49 across PHP files

#### By Usage Pattern:
- **Basic inclusion (1 parameter):** 40 calls
  - Example: `ThemeHelper::includeThemeFile('logic/profile_logic.php');`
  - Files: Most view files in `/views/` directory
  
- **With plugin name (2 parameters):** 0 calls
  - No instances found in current codebase
  
- **With variables (3 parameters):** 4 calls
  - `serve.php`: 1 call passing blog data
  - `RouteHelper.php`: 3 calls passing view variables
  
- **Dynamic path construction:** 5 calls
  - `data/pages_class.php`: 1 call
  - `data/page_contents_class.php`: 1 call
  - `includes/ThemeHelper.php`: 4 validation error messages (not actual calls)
  - `includes/LibraryFunctions.php`: 1 comment (not actual call)

#### By File Location:
- **View files (`/views/`):** 37 files
- **Profile views (`/views/profile/`):** 11 files  
- **System files (`/includes/`):** 1 file (RouteHelper.php with 3 calls)
- **Data classes (`/data/`):** 2 files (dynamic logic file inclusion)
- **Root files:** 1 file (serve.php)
- **Utility files (`/utils/`):** 1 file

### Migration Patterns

#### Basic File Inclusion (Most Common - 40 instances)
```php
// OLD: Single parameter format
ThemeHelper::includeThemeFile('logic/profile_logic.php');

// NEW: Two parameter format
require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
```

#### Includes Directory Files
```php
// OLD: Single parameter format
ThemeHelper::includeThemeFile('includes/PublicPage.php');

// NEW: Two parameter format  
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
```

#### View Files
```php
// OLD: Single parameter format
ThemeHelper::includeThemeFile('views/profile.php');

// NEW: Two parameter format
require_once(PathHelper::getThemeFilePath('profile.php', 'views'));
```

#### With Plugin Name (Currently 0 instances, but supported)
```php
// OLD: Plugin as second parameter
ThemeHelper::includeThemeFile('views/dashboard.php', 'myplugin');

// NEW: Plugin as fifth parameter
require_once(PathHelper::getThemeFilePath('dashboard.php', 'views', 'system', null, 'myplugin'));
```

#### With Variables (4 instances - variables already in scope)
```php
// OLD: Variables passed as parameter
ThemeHelper::includeThemeFile('views/user_card.php', null, ['user' => $user]);

// NEW: Variables already available in calling scope
require_once(PathHelper::getThemeFilePath('user_card.php', 'views'));
```

### Methods to Remove from ThemeHelper

The following methods in ThemeHelper.php should be removed as they are only used to support `includeThemeFile()`:

1. **`includeThemeFile()` (lines 189-371)** - Main method to remove
   - 182 lines of code including validation, file resolution, and inclusion logic

2. **`outputDebugComments()` (lines 560-591)** - Private helper method
   - Only called by `includeThemeFile()` for debug output
   - 31 lines of code that outputs debug information when debug mode is enabled

3. **`getViewResolutionOrder()` (lines 528-546)** - Public helper method  
   - Only used by `requireThemeFile()` which depends on `includeThemeFile()`
   - 18 lines of code that returns the order of paths checked for view files

4. **`requireThemeFile()` (lines 492-515)** - Public wrapper method
   - Wrapper around `includeThemeFile()` that exits with 404 if file not found
   - 23 lines of code that would need to be rewritten to use `getThemeFilePath()`

**Total lines to remove:** ~254 lines of code

**Note:** The `getAssetVersion()` method (lines 551-555) is not related to `includeThemeFile()` and should be kept as it's referenced by the `asset()` method.

## Benefits

1. **Eliminates Code Duplication**: Removes ~100 lines of redundant file resolution logic
2. **Fixes Variable Scope Issues**: Uses natural PHP inclusion scope, eliminating the isolation bug
3. **Simplifies Mental Model**: One method for file path resolution instead of two
4. **Improves Flexibility**: Developers can choose `require`, `require_once`, `include`, or `include_once`
5. **Reduces Complexity**: Removes circular dependency between ThemeHelper and PathHelper
6. **Better Performance**: Single resolution path instead of multiple method calls

## Testing Requirements

1. **Unit Tests**: Update existing tests for PathHelper to verify plugin name resolution
2. **Integration Tests**: Verify theme override chain works correctly:
   - Theme overrides load from `/theme/{theme}/`
   - Plugin files load from `/plugins/{plugin}/`
   - Base files load as fallback
3. **Regression Tests**: Ensure all existing file inclusions continue to work
4. **Plugin Name Tests**: Verify RouteHelper::getCurrentPlugin() integration works

## Risks and Mitigation

### Path Format Standardization

**Current Situation**:
**PathHelper::getThemeFilePath()** uses two-parameter format:
- Format: `('filename.php', 'subdirectory')` 
- Example: `('PublicPage.php', 'includes')`
- Used in 44 files

**ThemeHelper::includeThemeFile()** uses single-parameter format:
- Format: `('subdirectory/filename.php')`
- Example: `('includes/PublicPage.php')`
- Used in 49 files

**Solution**:
All calls will be converted to the standard two-parameter format used by `PathHelper::getThemeFilePath()`. This provides a cleaner, more consistent API without the complexity of supporting multiple formats.

The migration will convert all single-parameter calls to two-parameter format:
```php
// OLD: Single parameter
ThemeHelper::includeThemeFile('logic/profile_logic.php');

// NEW: Two parameters
require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
```

## Implementation Plan

### Phase 1: Manual Complex Cases & Infrastructure

#### 1.1 Enhance PathHelper::getThemeFilePath()
- [ ] Add plugin name support (5th parameter)
- [ ] Add strict validation rules (no slashes in filename, etc.)
- [ ] Add comprehensive error messages
- [ ] Test enhanced method thoroughly

#### 1.2 Handle Complex Cases Manually

**RouteHelper.php (3 instances with variables):**
```php
// Line 570 - Dynamic route with model variables
if (ThemeHelper::includeThemeFile($view_path, null, $viewVariables)) {
// BECOMES:
if (file_exists(PathHelper::getThemeFilePath(basename($view_path), dirname($view_path)))) {
    require_once(PathHelper::getThemeFilePath(basename($view_path), dirname($view_path)));
    return true;
}

// Line 581 - Default view fallback with variables  
if (ThemeHelper::includeThemeFile($default_view, null, $viewVariables)) {
// BECOMES:
if (file_exists(PathHelper::getThemeFilePath(basename($default_view), dirname($default_view)))) {
    require_once(PathHelper::getThemeFilePath(basename($default_view), dirname($default_view)));
    return true;
}

// Line 1235 - Page fallback with is_valid_page
if (ThemeHelper::includeThemeFile($view_file, null, ['is_valid_page' => true])) {
// BECOMES:
$is_valid_page = true; // Set before include
if (file_exists(PathHelper::getThemeFilePath(basename($view_file), dirname($view_file)))) {
    require_once(PathHelper::getThemeFilePath(basename($view_file), dirname($view_file)));
    return true;
}
```

**serve.php (1 instance with blog parameters):**
```php
// Line 245
return ThemeHelper::includeThemeFile('views/blog.php', null, [
    'params' => $params,
    'is_valid_page' => true
]);
// BECOMES:
$is_valid_page = true; // Already in scope from earlier
// $params already in scope
require_once(PathHelper::getThemeFilePath('blog.php', 'views'));
return true;
```

**Dynamic path construction (2 files):**
```php
// data/pages_class.php (line 58)
ThemeHelper::includeThemeFile('logic/' . $this->get('pag_script_filename'));
// BECOMES:
require_once(PathHelper::getThemeFilePath($this->get('pag_script_filename'), 'logic'));

// data/page_contents_class.php (line 63)  
ThemeHelper::includeThemeFile('logic/' . $this->get('pac_script_filename'));
// BECOMES:
require_once(PathHelper::getThemeFilePath($this->get('pac_script_filename'), 'logic'));
```

#### 1.3 Remove ThemeHelper methods
- [ ] Remove `includeThemeFile()` method (lines 189-371)
- [ ] Remove `outputDebugComments()` method (lines 560-591)
- [ ] Remove `getViewResolutionOrder()` method (lines 528-546)
- [ ] Remove `requireThemeFile()` method (lines 492-515)

#### 1.4 Update Documentation
- [ ] Update CLAUDE.md as specified
- [ ] Update plugin_developer_guide.md
- [ ] Update other documentation files

### Phase 2: Automated Bulk Replacement Script

Create `/utils/migrate_includethemefile.php`:

```php
#!/usr/bin/env php
<?php
/**
 * Migration script to replace ThemeHelper::includeThemeFile() with PathHelper::getThemeFilePath()
 * Phase 2 of the includeThemeFile removal
 * 
 * This handles the simple, repetitive cases that follow a standard pattern.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv) || $dryRun;

echo "Starting ThemeHelper::includeThemeFile migration...\n";
if ($dryRun) {
    echo "DRY RUN MODE - No files will be modified\n\n";
}

// Define the files to process (simple cases only)
$filesToProcess = [
    // View files with standard logic includes
    'views/blog.php',
    'views/event_waiting_list.php', 
    'views/booking.php',
    'views/list.php',
    'views/pricing.php',
    'views/event.php',
    'views/location.php',
    'views/cart_clear.php',
    'views/events.php',
    'views/rss20_feed.php',
    'views/products.php',
    'views/cart.php',
    'views/cart_charge.php',
    'views/product.php',
    'views/lists.php',
    'views/post.php',
    'views/password-reset-1.php',
    'views/login.php',
    'views/password-set.php',
    'views/video.php',
    'views/register.php',
    'views/password-reset-2.php',
    'views/page.php',
    'views/survey.php',
    // Profile views
    'views/profile/account_edit.php',
    'views/profile/profile.php',
    'views/profile/event_sessions.php',
    'views/profile/password_edit.php',
    'views/profile/event_withdraw.php',
    'views/profile/contact_preferences.php',
    'views/profile/address_edit.php',
    'views/profile/event_register_finish.php',
    'views/profile/event_sessions_course.php',
    'views/profile/orders_recurring_action.php',
    'views/profile/phone_numbers_edit.php',
    'views/profile/subscriptions.php',
    // Utility files
    'utils/products_list.php'
];

$totalFiles = count($filesToProcess);
$modifiedFiles = 0;
$errors = [];

foreach ($filesToProcess as $file) {
    $filePath = PathHelper::getIncludePath($file);
    
    if (!file_exists($filePath)) {
        $errors[] = "File not found: $file";
        continue;
    }
    
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Pattern 1: Simple logic file includes
    // ThemeHelper::includeThemeFile('logic/filename_logic.php');
    $content = preg_replace_callback(
        "/ThemeHelper::includeThemeFile\('logic\/([^']+)'\);/",
        function($matches) use ($verbose) {
            $filename = $matches[1];
            $replacement = "require_once(PathHelper::getThemeFilePath('$filename', 'logic'));";
            if ($verbose) {
                echo "  Replace: ThemeHelper::includeThemeFile('logic/$filename');\n";
                echo "     With: $replacement\n";
            }
            return $replacement;
        },
        $content
    );
    
    // Pattern 2: Includes directory files
    // ThemeHelper::includeThemeFile('includes/PublicPage.php');
    $content = preg_replace_callback(
        "/ThemeHelper::includeThemeFile\('includes\/([^']+)'\);/",
        function($matches) use ($verbose) {
            $filename = $matches[1];
            $replacement = "require_once(PathHelper::getThemeFilePath('$filename', 'includes'));";
            if ($verbose) {
                echo "  Replace: ThemeHelper::includeThemeFile('includes/$filename');\n";
                echo "     With: $replacement\n";
            }
            return $replacement;
        },
        $content
    );
    
    // Pattern 3: Views directory files
    // ThemeHelper::includeThemeFile('views/filename.php');
    $content = preg_replace_callback(
        "/ThemeHelper::includeThemeFile\('views\/([^']+)'\);/",
        function($matches) use ($verbose) {
            $filename = $matches[1];
            $replacement = "require_once(PathHelper::getThemeFilePath('$filename', 'views'));";
            if ($verbose) {
                echo "  Replace: ThemeHelper::includeThemeFile('views/$filename');\n";
                echo "     With: $replacement\n";
            }
            return $replacement;
        },
        $content
    );
    
    if ($content !== $originalContent) {
        if ($verbose) {
            echo "Processing: $file\n";
        }
        
        if (!$dryRun) {
            file_put_contents($filePath, $content);
            
            // Verify PHP syntax
            $output = [];
            $returnCode = 0;
            exec("php -l $filePath 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Restore original content on syntax error
                file_put_contents($filePath, $originalContent);
                $errors[] = "Syntax error after modifying $file: " . implode("\n", $output);
            } else {
                $modifiedFiles++;
            }
        } else {
            $modifiedFiles++;
        }
    }
}

echo "\n=== Migration Summary ===\n";
echo "Total files processed: $totalFiles\n";
echo "Files modified: $modifiedFiles\n";

if (count($errors) > 0) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

if ($dryRun) {
    echo "\nThis was a DRY RUN. To apply changes, run without --dry-run flag.\n";
} else {
    echo "\nMigration complete! Remember to:\n";
    echo "1. Test all modified files\n";
    echo "2. Run your test suite\n";
    echo "3. Commit the changes\n";
}
```

### Phase 2 Execution Instructions

1. First run in dry-run mode to see what will change:
   ```bash
   php /utils/migrate_includethemefile.php --dry-run
   ```

2. Review the output carefully

3. Run the actual migration:
   ```bash
   php /utils/migrate_includethemefile.php --verbose
   ```

4. Verify all files have correct PHP syntax:
   ```bash
   find views/ -name "*.php" -exec php -l {} \;
   ```

## Implementation Checklist

### Phase 1 (Manual):
- [ ] Enhance `PathHelper::getThemeFilePath()` with new features
- [ ] Manually update RouteHelper.php (3 complex instances)
- [ ] Manually update serve.php (1 complex instance)
- [ ] Manually update data/pages_class.php (dynamic path)
- [ ] Manually update data/page_contents_class.php (dynamic path)
- [ ] Remove 4 methods from ThemeHelper.php
- [ ] Update CLAUDE.md documentation
- [ ] Test Phase 1 changes thoroughly

### Phase 2 (Automated):
- [ ] Create migration script `/utils/migrate_includethemefile.php`
- [ ] Run script in dry-run mode
- [ ] Review proposed changes
- [ ] Run script in actual mode
- [ ] Verify PHP syntax on all modified files
- [ ] Run comprehensive test suite
- [ ] Commit all changes

## Success Criteria

1. All file inclusions work with the single enhanced `getThemeFilePath()` method
2. No variable scope isolation issues
3. Plugin name resolution works correctly
4. Theme override chain functions properly
5. No regression in existing functionality
6. Cleaner, more maintainable codebase with less duplication

## Notes

This consolidation aligns with the codebase's actual architecture where logic files prepare variables that views consume. The removal of artificial scope isolation will make the system more predictable and easier to work with.

The enhanced `getThemeFilePath()` provides all the functionality of both previous methods while maintaining the natural PHP inclusion model that the codebase was designed around.

## Documentation Updates Required

### CLAUDE.md Updates

#### Section: "File Loading Methods" (around line 305)
**Remove:**
```markdown
2. **`ThemeHelper::includeThemeFile()`** - File loading with override chain
   ```php
   // Views, logic, and other overridable files
   ThemeHelper::includeThemeFile('views/profile.php');           // View files
   ThemeHelper::includeThemeFile('logic/pricing_logic.php');     // Logic files
   ThemeHelper::includeThemeFile('includes/PublicPage.php');     // Theme includes
   
   // Plugin context (2nd parameter)
   ThemeHelper::includeThemeFile('logic/devices_logic.php', 'controld');
   
   // With variables (3rd parameter)
   ThemeHelper::includeThemeFile('views/profile.php', null, ['user' => $user]);
   ```
   
   **Override chain:** theme/{theme}/path → plugins/{plugin}/path → /path
```

**Replace with:**
```markdown
2. **`PathHelper::getThemeFilePath()`** - Theme-aware file resolution with override chain
   ```php
   // Files that can be overridden by themes
   require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
   require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
   require_once(PathHelper::getThemeFilePath('profile.php', 'views'));
   
   // With explicit plugin context (5th parameter)
   require_once(PathHelper::getThemeFilePath('devices_logic.php', 'logic', 'system', null, 'controld'));
   
   // Parameters: filename, subdirectory, path_format, theme_name, plugin_name
   ```
   
   **Override chain:** theme/{theme}/path → plugins/{plugin}/path → /path
   **Format:** Always use two parameters - filename and subdirectory separately
```

#### Section: "When to use each" (around line 331)
**Remove:**
```markdown
**When to use each:**
- `PathHelper::requireOnce()`: System files, data models, non-overridable code
- `ThemeHelper::includeThemeFile()`: Views, logic, any file that themes/plugins should override
```

**Replace with:**
```markdown
**When to use each:**
- `PathHelper::requireOnce()`: System files, data models (wrapper around require_once)
- `PathHelper::getIncludePath()`: Direct file access, no theme overrides needed (plugins, data files)
- `PathHelper::getThemeFilePath()`: Files that themes/plugins can override (views, logic, includes)

**Examples:**
```php
// System file - never overridden
PathHelper::requireOnce('includes/LibraryFunctions.php');

// Plugin file - direct access
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

// Theme-overridable file
require_once(PathHelper::getThemeFilePath('profile.php', 'views'));
```
```

#### Section: "Common Tasks & Quick Reference" (around line 340)
**Update entire "Getting FormWriter Instances" example to reflect removal of includeThemeFile**

### /docs/claude/plugin_developer_guide.md Updates

#### Find and replace all instances:
```php
// OLD
ThemeHelper::includeThemeFile('views/profile/devices.php', 'controld', ['user' => $user]);

// NEW  
require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile', 'system', null, 'controld'));
```

#### Update "File Override System" section:
**Add note:**
```markdown
**Important:** The file override system uses `PathHelper::getThemeFilePath()` which checks:
1. Theme override: `/theme/{theme}/{subdirectory}/{filename}`
2. Plugin version: `/plugins/{plugin}/{subdirectory}/{filename}` 
3. Base fallback: `/{subdirectory}/{filename}`

Always use the two-parameter format:
- First parameter: filename only (e.g., 'profile.php')
- Second parameter: subdirectory path (e.g., 'views', 'logic', 'views/profile')
```

### /docs/claude/CLAUDE_admin_pages.md Updates

#### If this file contains any references to ThemeHelper::includeThemeFile:
Replace with appropriate `PathHelper::getThemeFilePath()` examples using the two-parameter format.

### Key Documentation Points to Emphasize

1. **Two-parameter format is mandatory**:
   - `('filename.php', 'subdirectory')` - NO other format accepted
   - Subdirectory cannot have leading/trailing slashes

2. **Variable scope is natural PHP scope**:
   - No more `$variables` parameter
   - Variables in calling scope are naturally available

3. **Plugin name is explicit**:
   - 5th parameter when needed
   - Auto-detected from route context when null

4. **Strict validation ensures consistency**:
   - Filename must end with .php
   - No slashes in filename
   - Clear error messages for format violations