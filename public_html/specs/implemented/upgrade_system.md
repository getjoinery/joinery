# Upgrade System Comparison and Feature Parity Analysis

## Overview
This document analyzes the differences between the two upgrade mechanisms (`deploy.sh` and `upgrade.php`) and outlines the changes needed to bring `upgrade.php` to feature parity with `deploy.sh`.

## Current System Comparison

### deploy.sh (Full-Featured Deployment System)

**Location:** `/home/user1/joinery/joinery/maintenance_scripts/deploy.sh`

**Key Features:**
- **Pre-deployment Validation**
  - PHP syntax validation on all staged files
  - Plugin class loading tests with PathHelper context
  - Model tests execution
  - Application bootstrap tests
- **Automatic Rollback System**
  - Trap-based automatic rollback on failure
  - Preserves failed deployments with timestamps for debugging
  - Creates .htaccess to block web access to failed directories
  - Option to disable rollback with `--norollback` flag
- **Theme/Plugin Management**
  - Reads theme.json/plugin.json manifests
  - Auto-generates manifests if missing
  - Preserves custom themes/plugins (is_stock: false)
  - Only updates stock themes/plugins
- **Composer Integration**
  - Validates and installs composer dependencies
  - Runs composer_install_if_needed.php
- **Comprehensive Permission Management**
  - Sets ownership to www-data:user1
  - Sets permissions to 775 (777 for uploads)
  - Handles permission errors gracefully
- **Staging Environment**
  - Tests all code in staging before deployment
  - Preserves staging directory on failure for debugging
- **Additional Features**
  - Verbose mode for detailed output
  - Support for test deployments to separate instances
  - Git-based deployment from repository
  - Manual rollback capability
  - Database copying for test instances

### upgrade.php (Basic Upgrade System)

**Location:** `/var/www/html/joinerytest/public_html/utils/upgrade.php`

**Current Features:**
- Downloads zip file from configured upgrade server
- Basic permission checking (www-data ownership)
- Simple backup mechanism (moves to public_html_last)
- Theme-only deployment mode (`?theme-only=1`)
- Executes update_database.php for migrations
- Basic rollback on failure (restores from backup)
- Serves as upgrade server when configured

## Gap Analysis

### Critical Missing Features in upgrade.php

1. **No Pre-deployment Validation**
   - Missing PHP syntax checking
   - No plugin loading tests
   - No model or bootstrap tests
   - Could deploy broken code to production

2. **Limited Rollback Capabilities**
   - No preservation of failed deployments
   - No automatic rollback with trap handling
   - No option to disable rollback for debugging
   - Doesn't clean up old failed deployments

3. **No Theme/Plugin Manifest Support**
   - Doesn't check for is_stock flag
   - Overwrites all themes/plugins including custom ones
   - No manifest auto-generation

4. **Missing Composer Integration**
   - No composer dependency validation
   - Doesn't run composer_install_if_needed.php

5. **Limited Error Handling**
   - Basic error messages without debugging instructions
   - No structured error collection
   - No distinction between warnings and errors

6. **Obsolete Directory Structure (CRITICAL)**
   - **Theme-only mode (lines 42-133)** references separate `/theme/` and `/plugins/` directories outside `public_html` that NO LONGER EXIST
   - Lines 411-435 also incorrectly attempt to copy from these non-existent directories
   - **Current Architecture:**
     - **publish_upgrade.php** creates ZIPs containing the entire `/public_html/` directory including `/public_html/theme/` and `/public_html/plugins/`
     - **deploy.sh** downloads themes/plugins separately from joinery repo and merges them
     - **upgrade.php** receives a ZIP with themes/plugins already included
   - **Action Required:**
     - Remove theme-only mode entirely (lines 42-133)
     - Remove obsolete theme/plugin copying code (lines 411-435)
     - Implement theme/plugin preservation logic to protect custom themes/plugins during extraction

## Required Changes for Feature Parity

### Priority 1: Critical Safety Features

#### 1.1 Pre-deployment Validation
```php
// Add before moving staged files to production
function validateStagedDeployment($stage_directory, $verbose = false) {
    $errors = [];

    // PHP Syntax Validation
    $php_files = glob_recursive($stage_directory . '/*.php');
    foreach ($php_files as $file) {
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            $errors[] = "Syntax error in $file: " . implode("\n", $output);
        }
    }

    // Plugin Loading Tests
    $plugin_classes = glob($stage_directory . '/plugins/*/_class.php');
    foreach ($plugin_classes as $class_file) {
        if (!testPluginLoading($class_file)) {
            $errors[] = "Plugin loading failed: $class_file";
        }
    }

    // Bootstrap Test
    if (!testBootstrap($stage_directory)) {
        $errors[] = "Bootstrap test failed";
    }

    return $errors;
}
```

#### 1.2 Enhanced Rollback System
```php
function performRollback($target_site, $preserve_failed = true) {
    $public_html = "/var/www/html/$target_site/public_html";
    $backup_dir = "/var/www/html/$target_site/public_html_last";

    if ($preserve_failed) {
        // Preserve failed deployment for debugging
        $failed_dir = "/var/www/html/$target_site/public_html_failed_" . date('Ymd_His');
        rename($public_html, $failed_dir);

        // Block web access to failed deployment
        file_put_contents("$failed_dir/.htaccess",
            "Order Deny,Allow\nDeny from all\n<RequireAll>\nRequire all denied\n</RequireAll>");
    }

    // Restore from backup
    mkdir($public_html);
    exec("cp -r $backup_dir/* $public_html/");

    return true;
}
```

### Priority 2: Theme/Plugin Preservation

#### 2.1 Manifest Support (Based on deploy.sh implementation)
```php
// Deploy.sh approach: Preserve custom themes/plugins before main deployment
function preserve_custom_themes_plugins($stage_directory, $backup_directory) {
    $theme_preserved = 0;
    $theme_updated = 0;
    $plugin_preserved = 0;
    $plugin_updated = 0;

    // THEMES: Check if custom themes exist in backup and preserve them
    if (is_dir("$stage_directory/theme")) {
        foreach (scandir("$stage_directory/theme") as $theme_name) {
            if ($theme_name == '.' || $theme_name == '..') continue;

            $staging_manifest = "$stage_directory/theme/$theme_name/theme.json";
            $existing_theme = "$backup_directory/theme/$theme_name";

            // Auto-generate manifest if missing
            if (!file_exists($staging_manifest)) {
                $manifest = [
                    'name' => $theme_name,
                    'version' => '1.0.0',
                    'description' => "Auto-generated manifest for $theme_name theme",
                    'is_stock' => true
                ];
                file_put_contents($staging_manifest, json_encode($manifest, JSON_PRETTY_PRINT));
            }

            // Check if theme exists in previous deployment
            if (is_dir($existing_theme)) {
                $existing_manifest = "$existing_theme/theme.json";
                if (file_exists($existing_manifest)) {
                    $manifest_data = json_decode(file_get_contents($existing_manifest), true);
                    $is_stock = $manifest_data['is_stock'] ?? true;

                    if ($is_stock === false) {
                        // Preserve custom theme by copying over staged version
                        echo "Preserving custom theme: $theme_name\n";
                        exec("rm -rf $stage_directory/theme/$theme_name");
                        exec("cp -r $existing_theme $stage_directory/theme/");
                        $theme_preserved++;
                    } else {
                        echo "Updating stock theme: $theme_name\n";
                        $theme_updated++;
                    }
                } else {
                    $theme_updated++;
                }
            }
        }
    }

    // PLUGINS: Same logic for plugins
    if (is_dir("$stage_directory/plugins")) {
        foreach (scandir("$stage_directory/plugins") as $plugin_name) {
            if ($plugin_name == '.' || $plugin_name == '..') continue;

            $staging_manifest = "$stage_directory/plugins/$plugin_name/plugin.json";
            $existing_plugin = "$backup_directory/plugins/$plugin_name";

            // Auto-generate manifest if missing
            if (!file_exists($staging_manifest)) {
                $manifest = [
                    'name' => $plugin_name,
                    'version' => '1.0.0',
                    'description' => "Auto-generated manifest for $plugin_name plugin",
                    'is_stock' => true
                ];
                file_put_contents($staging_manifest, json_encode($manifest, JSON_PRETTY_PRINT));
            }

            // Check if plugin exists in previous deployment
            if (is_dir($existing_plugin)) {
                $existing_manifest = "$existing_plugin/plugin.json";
                if (file_exists($existing_manifest)) {
                    $manifest_data = json_decode(file_get_contents($existing_manifest), true);
                    $is_stock = $manifest_data['is_stock'] ?? true;

                    if ($is_stock === false) {
                        // Preserve custom plugin by copying over staged version
                        echo "Preserving custom plugin: $plugin_name\n";
                        exec("rm -rf $stage_directory/plugins/$plugin_name");
                        exec("cp -r $existing_plugin $stage_directory/plugins/");
                        $plugin_preserved++;
                    } else {
                        echo "Updating stock plugin: $plugin_name\n";
                        $plugin_updated++;
                    }
                } else {
                    $plugin_updated++;
                }
            }
        }
    }

    echo "Theme/Plugin Summary: $theme_preserved themes preserved, $theme_updated themes updated, ";
    echo "$plugin_preserved plugins preserved, $plugin_updated plugins updated\n";
}
```

### Priority 3: Composer Integration

#### 3.1 Add Composer Validation
```php
// After successful deployment
$composer_result = shell_exec("php $live_directory/utils/composer_install_if_needed.php");
if ($return_value != 0) {
    echo "ERROR: Composer dependency setup failed.\n";
    performRollback($target_site);
    exit(1);
}
```

### Priority 4: Update Database Integration

#### 4.1 Fix update_database.php Integration

**CRITICAL BUG TO FIX:** The current `update_database.php` uses **backwards exit codes** that violate Unix conventions!

**Current (WRONG):**
- `exit(1)` = Success ❌
- `exit(0)` = Failure ❌

**Standard Unix (CORRECT):**
- `exit(0)` = Success ✅
- `exit(1)` = Failure ✅

**Impact:**
- Breaks shell conditionals (`&&`, `||`, `if`)
- Breaks CI/CD integrations
- Confuses developers
- Incompatible with standard tooling

**Fix Required for update_database.php Integration:**

The actual function signature is:
```php
function update_database($verbose=false, $upgrade=false, $cleanup=false)
```

**Current Bug in update_database.php (lines 511-514):**
```php
// WRONG - Backwards exit codes!
if(update_database($verbose, $upgrade, $cleanup)){
    echo 'Database update script successful'. "<br>\n";
    exit(1);  // RETURN 1 FOR THE DEPLOY SCRIPT - THIS IS BACKWARDS!
} else {
    echo 'Database update script failed'. "<br>\n";
    exit(0);  // RETURN 0 FOR FAILURE - THIS IS BACKWARDS!
}
```

**CORRECT implementation for upgrade.php:**
```php
// Set noautorun to prevent automatic execution
$noautorun = 1;
require_once('update_database.php');

// Call with correct parameters
$migration_result = update_database(
    $verbose,    // bool: show detailed output
    true,        // bool: upgrade mode (allow column modifications)
    false        // bool: cleanup mode (don't drop columns)
);

// Use STANDARD Unix exit codes
if (!$migration_result) {
    echo 'Migration failed...reverting upgrade.<br>';
    DeploymentHelper::performRollback($target_site);
    exit(1); // Failure - STANDARD UNIX
}
echo 'Database migration completed successfully.<br>';
exit(0); // Success - STANDARD UNIX
```

**Note:** The update_database() function returns a boolean (true = success, false = failure), but the script's exit codes are backwards and need to be fixed!

**Also Update deploy.sh:**

Current deploy.sh line ~1411:
```bash
# WRONG:
if [[ "$returnvalue" != 1 ]]; then
    echo "ERROR: Database update failed."
```

Should be:
```bash
# CORRECT:
if [[ "$returnvalue" != 0 ]]; then
    echo "ERROR: Database update failed."
```

### Priority 5: Enhanced Features

#### 5.1 Verbose Mode Support
```php
$verbose = isset($_GET['verbose']) || isset($_POST['verbose']);

function verbose_echo($message) {
    global $verbose;
    if ($verbose) {
        echo $message . "\n";
    }
}
```

#### 5.2 CLI Support
```php
// Add at the beginning of upgrade.php
$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    // Parse command line arguments
    $options = getopt("", ["verbose", "force-upgrade", "norollback", "theme-only"]);
    $verbose = isset($options['verbose']);
    $force_upgrade = isset($options['force-upgrade']);
    $disable_rollback = isset($options['norollback']);
    $theme_only = isset($options['theme-only']);
} else {
    // Use existing $_GET/$_POST parsing
    $verbose = isset($_REQUEST['verbose']);
    // ... etc
}
```

## Implementation Roadmap

### Phase 0: Remove Obsolete Code (CRITICAL - Must Complete First)
1. **Remove theme-only mode** (lines 42-133) - references non-existent `/theme/` and `/plugins/` directories
2. **Remove obsolete theme/plugin copying code** (lines 411-435) - these directories no longer exist
3. **Verify directory structure** - themes and plugins are now part of the main codebase in staging

**Staging Architecture Clarification:**
- **ZIP Contents:** The upgrade ZIP created by `publish_upgrade.php` contains the entire `/public_html/` directory including `/public_html/theme/` and `/public_html/plugins/`
- **No External Directories:** There are NO separate theme/plugin directories outside of public_html anymore
- **Preservation Strategy:** When extracting the ZIP to staging, we must:
  1. Extract everything to `public_html_stage/`
  2. Check existing `public_html_last/theme/` and `public_html_last/plugins/` for custom themes/plugins
  3. Use `DeploymentHelper::preserveCustomThemesPlugins()` to copy custom ones over the staged versions
  4. Then deploy the complete staging directory to public_html

### Phase 1: Create Shared Deployment Library & Update upgrade.php (Immediate)

#### 1.1 Create DeploymentHelper Class
**File:** `/includes/DeploymentHelper.php`

Create a static utility class containing all shared deployment logic:
- Pre-deployment validation (PHP syntax, plugin loading, bootstrap tests)
- Theme/plugin preservation with manifest support
- Enhanced rollback with failure preservation
- Helper utilities (directory checks, permission fixes)

**Class Structure:**
```php
class DeploymentHelper {
    // ============================================
    // VALIDATION METHODS
    // ============================================

    /**
     * Validate PHP syntax on all files in directory
     * @param string $directory Directory to validate
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'errors' => array, 'files_checked' => int]
     *               Each error: ['file' => string, 'line' => int, 'message' => string, 'type' => 'syntax']
     */
    public static function validatePHPSyntax($directory, $verbose = false);

    /**
     * Test that plugin class files can be loaded
     * @param string $stage_dir Staging directory
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'errors' => array, 'files_checked' => int]
     *               Each error: ['file' => string, 'message' => string, 'type' => string]
     *               Types: 'syntax', 'missing_dependency', 'fatal_error', 'include_error'
     */
    public static function testPluginLoading($stage_dir, $verbose = false);

    /**
     * Test application bootstrap (PathHelper, Globalvars, DbConnector)
     * @param string $stage_dir Staging directory
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'error' => string|null, 'components_loaded' => array]
     */
    public static function testBootstrap($stage_dir, $verbose = false);

    // ============================================
    // THEME/PLUGIN PRESERVATION
    // ============================================

    /**
     * Preserve custom themes/plugins based on is_stock flag
     * @param string $stage_dir Staging directory with new themes/plugins
     * @param string $backup_dir Backup directory with existing themes/plugins
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'themes_preserved' => int, 'themes_updated' => int, 'themes_added' => int,
     *                'plugins_preserved' => int, 'plugins_updated' => int, 'plugins_added' => int,
     *                'errors' => array]
     */
    public static function preserveCustomThemesPlugins($stage_dir, $backup_dir, $verbose = false);

    /**
     * Process individual theme or plugin (private helper)
     * @return string 'preserved'|'updated'|'added'|'error'
     */
    private static function processThemeOrPlugin($type, $name, $stage_dir, $backup_dir, $verbose);

    /**
     * Auto-generate manifest file if missing (private helper)
     */
    private static function generateManifest($path, $type, $name);

    // ============================================
    // ROLLBACK/BACKUP
    // ============================================

    /**
     * Rollback to previous deployment
     * @param string $target_site Site name (e.g., 'joinerytest')
     * @param bool $preserve_failed Save failed deployment for debugging
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'failed_dir' => string|null,
     *                'backup_restored' => bool,
     *                'permissions_fixed' => bool,
     *                'error' => string|null]
     */
    public static function performRollback($target_site, $preserve_failed = true, $verbose = false);

    /**
     * Create backup of current deployment
     * @param string $source_dir Directory to backup
     * @param string $backup_dir Destination for backup
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'files_backed_up' => int,
     *                'size_bytes' => int,
     *                'error' => string|null]
     */
    public static function createBackup($source_dir, $backup_dir, $verbose = false);

    // ============================================
    // UTILITY HELPERS
    // ============================================

    /**
     * Check if directory is empty (private helper)
     * @return bool True if empty or only contains . and ..
     */
    private static function isDirEmpty($dir);

    /**
     * Fix permissions after deployment (private helper)
     * @param string $path Directory or file path
     * @param string $owner Owner username
     * @param string $group Group name
     * @param string $mode Octal permissions as string
     * @return array ['success' => bool, 'warnings' => array]
     */
    private static function fixPermissions($path, $owner = 'www-data', $group = 'user1', $mode = '775');
}
```

**Benefits:**
- Single source of truth for validation logic
- Used by both upgrade.php and deploy.sh
- Prevents code duplication
- Easier to test and maintain
- Private methods hide implementation details
- Consistent error reporting format

**Verbose Output Example Implementation:**
```php
public static function validatePHPSyntax($directory, $verbose = false) {
    $result = [
        'success' => true,
        'errors' => [],
        'files_checked' => 0
    ];

    if ($verbose) {
        echo "Validating PHP syntax in: $directory\n";
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') continue;

        $result['files_checked']++;
        $filepath = $file->getPathname();

        if ($verbose) {
            echo "  Checking: " . str_replace($directory, '', $filepath) . "...";
        }

        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($filepath) . " 2>&1", $output, $return_var);

        if ($return_var !== 0) {
            // Parse error message for line number
            $line = 0;
            $message = implode(' ', $output);
            if (preg_match('/on line (\d+)/', $message, $matches)) {
                $line = (int)$matches[1];
            }

            $result['errors'][] = [
                'file' => $filepath,
                'line' => $line,
                'message' => $message,
                'type' => 'syntax'
            ];
            $result['success'] = false;

            if ($verbose) {
                echo " FAILED\n";
                echo "    Error: $message\n";
            }
        } else {
            if ($verbose) {
                echo " OK\n";
            }
        }
    }

    if ($verbose) {
        echo "Syntax validation complete: {$result['files_checked']} files checked, " .
             count($result['errors']) . " errors found\n";
    }

    return $result;
}
```

#### 1.2 Integrate DeploymentHelper into upgrade.php
1. Remove obsolete code (Phase 0 items)
2. Replace inline validation with `DeploymentHelper::validatePHPSyntax()`
3. Add `DeploymentHelper::testPluginLoading()` before deployment
4. Add `DeploymentHelper::testBootstrap()` before deployment
5. Replace basic rollback with `DeploymentHelper::performRollback()`
6. Add `DeploymentHelper::preserveCustomThemesPlugins()` to preserve custom themes/plugins
7. **FIX CRITICAL BUG:** Fix update_database.php to use standard Unix exit codes (exit(0) = success, exit(1) = failure)
8. Add composer dependency validation via `composer_install_if_needed.php`

#### 1.3 Enhanced Features for upgrade.php
1. Add verbose mode support throughout
2. Implement CLI argument parsing (for command-line usage)
3. Add comprehensive error reporting

### Phase 2: Migrate deploy.sh to Use DeploymentHelper (High Priority)

**Goal:** Replace inline bash/PHP code in deploy.sh with calls to DeploymentHelper AND fix backwards exit codes

1. **Fix Backwards Exit Codes (CRITICAL):**
   - **update_database.php (lines 511-514):** Change `exit(1)` for success to `exit(0)`, and `exit(0)` for failure to `exit(1)`
   - **deploy.sh (line ~1411):** Change `if [[ "$returnvalue" != 1 ]]` to `if [[ "$returnvalue" != 0 ]]`
   - **upgrade.php:** Update to use correct exit codes (currently uses backwards codes from update_database.php)

   **Before (WRONG):**
   ```php
   // update_database.php lines 511-514
   if(update_database($verbose, $upgrade, $cleanup)){
       echo 'Database update script successful'. "<br>\n";
       exit(1);  // BACKWARDS!
   } else {
       echo 'Database update script failed'. "<br>\n";
       exit(0);  // BACKWARDS!
   }
   ```

   **After (CORRECT):**
   ```php
   // update_database.php lines 511-514
   if(update_database($verbose, $upgrade, $cleanup)){
       echo 'Database update script successful'. "<br>\n";
       exit(0);  // SUCCESS - Standard Unix
   } else {
       echo 'Database update script failed'. "<br>\n";
       exit(1);  // FAILURE - Standard Unix
   }
   ```

2. **Replace validation code:**
   - Lines 1102-1123: PHP syntax validation → `DeploymentHelper::validatePHPSyntax()`
   - Lines 1125-1239: Plugin loading tests → `DeploymentHelper::testPluginLoading()`
   - Lines 1287-1317: Bootstrap test → `DeploymentHelper::testBootstrap()`

3. **Replace theme/plugin preservation:**
   - Lines 539-675: Custom theme/plugin preservation → `DeploymentHelper::preserveCustomThemesPlugins()`

4. **Replace rollback code:**
   - Lines 107-180: Rollback function → `DeploymentHelper::performRollback()`

**Example Usage in deploy.sh:**
```bash
# Replace lines 1102-1123 with:
echo "Running pre-deployment tests on staging environment..."
php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html/includes/DeploymentHelper.php');
    \$errors = DeploymentHelper::validatePHPSyntax('$staging_dir', $VERBOSE);
    if (!empty(\$errors)) {
        echo 'PHP syntax errors found:\n';
        foreach (\$errors as \$error) {
            echo '  ' . \$error['file'] . ': ' . \$error['error'] . '\n';
        }
        exit(1);
    }
    echo 'PHP syntax validation passed\n';
"
[ $? -ne 0 ] && exit 1

# Replace lines 1125-1239 with:
php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html/includes/DeploymentHelper.php');
    \$errors = DeploymentHelper::testPluginLoading('$staging_dir', $VERBOSE);
    if (!empty(\$errors)) {
        echo 'Plugin loading errors found:\n';
        foreach (\$errors as \$error) {
            echo '  ' . \$error['file'] . ': ' . \$error['error'] . '\n';
        }
        exit(1);
    }
    echo 'Plugin loading test passed\n';
"
[ $? -ne 0 ] && exit 1

# Replace lines 539-675 with:
php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html/includes/DeploymentHelper.php');
    \$stats = DeploymentHelper::preserveCustomThemesPlugins(
        '$staging_dir',
        '/var/www/html/$TARGET_SITE/public_html_last',
        $VERBOSE
    );
    echo 'Themes: ' . \$stats['themes_preserved'] . ' preserved, ' . \$stats['themes_updated'] . ' updated\n';
    echo 'Plugins: ' . \$stats['plugins_preserved'] . ' preserved, ' . \$stats['plugins_updated'] . ' updated\n';
"
```

**Benefits of migration:**
- Reduces deploy.sh code complexity
- Ensures identical behavior between deploy.sh and upgrade.php
- Single place to fix bugs or add features
- Easier to test validation logic

**Example Usage in upgrade.php:**
```php
require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

// Validate syntax
$result = DeploymentHelper::validatePHPSyntax($stage_directory, $verbose);
if (!$result['success']) {
    echo "PHP syntax validation FAILED - {$result['files_checked']} files checked<br>";
    echo count($result['errors']) . " errors found:<br>";
    foreach ($result['errors'] as $error) {
        echo "  • " . htmlspecialchars($error['file']) .
             " (line {$error['line']}): " . htmlspecialchars($error['message']) . "<br>";
    }
    $rollback = DeploymentHelper::performRollback($target_site, true, $verbose);
    exit(1);
} else {
    echo "✓ PHP syntax validation passed ({$result['files_checked']} files)<br>";
}

// Test plugin loading
$result = DeploymentHelper::testPluginLoading($stage_directory, $verbose);
if (!$result['success']) {
    echo "Plugin loading tests FAILED<br>";
    foreach ($result['errors'] as $error) {
        $type_label = ($error['type'] === 'syntax') ? 'SYNTAX' : strtoupper($error['type']);
        echo "  • [$type_label] " . htmlspecialchars($error['file']) . ": " .
             htmlspecialchars($error['message']) . "<br>";
    }
    $rollback = DeploymentHelper::performRollback($target_site, true, $verbose);
    exit(1);
} else {
    echo "✓ Plugin loading tests passed ({$result['files_checked']} plugins)<br>";
}

// Test bootstrap
$result = DeploymentHelper::testBootstrap($stage_directory, $verbose);
if (!$result['success']) {
    echo "Bootstrap test FAILED: " . htmlspecialchars($result['error']) . "<br>";
    $rollback = DeploymentHelper::performRollback($target_site, true, $verbose);
    exit(1);
} else {
    echo "✓ Bootstrap test passed (loaded: " . implode(', ', $result['components_loaded']) . ")<br>";
}

// Preserve custom themes/plugins
$result = DeploymentHelper::preserveCustomThemesPlugins($stage_directory, $backup_directory, $verbose);
if ($result['success']) {
    echo "✓ Themes: {$result['themes_preserved']} preserved, {$result['themes_updated']} updated, {$result['themes_added']} added<br>";
    echo "✓ Plugins: {$result['plugins_preserved']} preserved, {$result['plugins_updated']} updated, {$result['plugins_added']} added<br>";
} else {
    echo "Theme/Plugin preservation had errors:<br>";
    foreach ($result['errors'] as $error) {
        echo "  • " . htmlspecialchars($error) . "<br>";
    }
}

// ... deploy code ...

// Rollback if needed
if ($deployment_failed) {
    $result = DeploymentHelper::performRollback($target_site, true, $verbose);
    if ($result['success']) {
        echo "✓ Rollback successful<br>";
        if ($result['failed_dir']) {
            echo "  • Failed deployment preserved at: " . $result['failed_dir'] . "<br>";
        }
        echo "  • Backup restored: " . ($result['backup_restored'] ? 'Yes' : 'No') . "<br>";
        echo "  • Permissions fixed: " . ($result['permissions_fixed'] ? 'Yes' : 'No') . "<br>";
    } else {
        echo "✗ Rollback FAILED: " . htmlspecialchars($result['error']) . "<br>";
        exit(1);
    }
}
```

### Phase 3: Enhanced Features (Medium Priority)
1. Add deployment status reporting
2. Add test deployment capability to upgrade.php
3. Add manual rollback option to upgrade.php web UI

### Phase 4: Additional Features (Nice to Have)
1. Add deployment metrics/logging
2. Add pre-flight checks UI in upgrade.php
3. Add rollback history tracking

## Testing Requirements

Before deploying these changes:

1. **Test in Development Environment**
   - Create test scenarios with broken PHP syntax
   - Test with custom themes/plugins
   - Verify rollback functionality

2. **Validate Backwards Compatibility**
   - Ensure existing upgrade server functionality still works
   - Test theme-only mode
   - Verify permission checks

3. **Performance Testing**
   - Measure deployment time with validation
   - Test with large codebases
   - Verify staging cleanup

## Conclusion

The upgrade.php system currently lacks critical safety features that deploy.sh provides. The highest priority is adding pre-deployment validation to prevent broken code from reaching production. The second priority is preserving custom themes/plugins during upgrades. With these changes, upgrade.php can provide a safe, reliable upgrade path for production systems while maintaining the ability to customize themes and plugins.