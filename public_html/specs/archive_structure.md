# Specification: Archive Structure Changes - Phase 2

**Note:** Phase 1 (tar.gz format, maintenance scripts inclusion, install SQL) has been completed and moved to `/specs/implemented/archive_structure_phase1.md`.

## Overview

Standardize composer dependency management across all installation and upgrade scenarios by using a single, pre-configured `composer.json` with a relative vendor directory path. This eliminates conflicts and script complexity.

**What Changed Since Phase 1:**
- Phase 1 focused on archive format and structure (tar.gz, maintenance scripts, install SQL)
- Phase 2 focuses on standardizing composer dependency management
- Some sections from the original Phase 1 spec were adjusted during implementation (e.g., maintenance scripts location handling)

## Current Problems

1. **Conflicting composer.json files**:
   - server_setup.sh creates a generic composer.json in `/home/user1/` with common dependencies
   - Project has its own composer.json in `/var/www/html/{SITE}/public_html/`
   - These don't match, causing confusion and potential version conflicts

2. **Inconsistent composer handling**:
   - new_account.sh has NO composer handling at all
   - deploy.sh and upgrade.php use `composer_install_if_needed.php`
   - server_setup.sh pre-installs generic dependencies

3. **Script complexity**:
   - Multiple scripts duplicate vendor-dir update logic
   - File manipulation adds risk and complexity
   - No unified dependency installation strategy

## Design Decision: Per-Site Isolated Vendor Directory

Based on Docker compatibility requirements, vendor dependencies are stored **beside public_html**:
```
/var/www/html/{SITE}/
├── public_html/
├── config/
├── vendor/                 ← Isolated per site, easily mountable in Docker
├── uploads/
├── logs/
├── static_files/
├── cache/
├── backups/
└── maintenance_scripts/    ← Scripts deployed with site, version-matched
```

This approach provides:
- **Isolation**: Each site has independent dependencies (no version conflicts)
- **Docker compatible**: Clean volume mounts for containerized deployments
- **Single archive**: Works in both traditional and Docker environments
- **Simplified scripts**: No vendor-dir configuration needed during deployment

## Phase 2 Changes Required

### 0. Database Migration for composerAutoLoad Setting

**Add migration to update composerAutoLoad setting from old to new path:**

Add to `/public_html/migrations/migrations.php`:

```php
$migration = array(); // Clear previous migration data
$migration['database_version'] = '0.XX'; // Use next available version number
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'composerAutoLoad' AND stg_value LIKE '/home/user1/vendor%'";
$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

**What this does:**
- **Test:** Checks if composerAutoLoad is still pointing to old `/home/user1/vendor/` path
- **Migration:** Updates it to `../vendor/` (relative path that resolves to `/var/www/html/{SITE}/vendor/`)
- **When it runs:** Automatically during deploy.sh or upgrade.php when update_database.php is executed

**Note:** Sites that already have the correct path won't be affected (test will return 0).

### 1. Project composer.json Update

Update `/var/www/html/{SITE}/public_html/composer.json` to change vendor-dir from absolute to relative path:

**Current:**
```json
"vendor-dir": "/home/user1/vendor"
```

**Change to:**
```json
"vendor-dir": "../vendor"
```

**Complete composer.json after change:**
```json
{
    "name": "joinery/platform",
    "description": "Joinery membership and event management platform",
    "type": "project",
    "require": {
        "php": ">=7.4",
        "mailgun/mailgun-php": "^3.2",
        "kriswallsmith/buzz": "^1.2",
        "nyholm/psr7": "^1.3",
        "jhut89/mailchimp3php": "^3.2",
        "verot/class.upload.php": "^2.1",
        "stripe/stripe-php": "^10.16",
        "phpmailer/phpmailer": "^6.10.0"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true
        },
        "vendor-dir": "../vendor"
    }
}
```

The relative path `"../vendor"` places vendor at `/var/www/html/{SITE}/vendor/` (one level up from public_html).

### 2. ComposerValidator.php Enhancement

**Add two new capabilities:**
1. Vendor directory consistency check to detect mismatches
2. Automatic cleanup of old vendor directories when location changes

#### 2a. Add Vendor Directory Detection Method

Add this method to `ComposerValidator` class:

```php
/**
 * Detect if vendor directory location has changed between composer.json and database setting
 * @return array ['changed' => bool, 'old_path' => string|null, 'new_path' => string|null]
 */
private function detectVendorDirChange() {
    $basePath = PathHelper::getBasePath();
    $composerJsonPath = $basePath . '/composer.json';

    if (!file_exists($composerJsonPath)) {
        return ['changed' => false, 'old_path' => null, 'new_path' => null];
    }

    $composerJson = json_decode(file_get_contents($composerJsonPath), true);
    if (!$composerJson || !isset($composerJson['config']['vendor-dir'])) {
        return ['changed' => false, 'old_path' => null, 'new_path' => null];
    }

    // Get configured vendor dir from composer.json
    $configuredVendorDir = rtrim($composerJson['config']['vendor-dir'], '/');
    if (substr($configuredVendorDir, 0, 1) === '/') {
        // Absolute path - use as-is
        $expectedPath = $configuredVendorDir . '/';
    } else {
        // Relative path - resolve relative to base path
        $expectedPath = rtrim($basePath, '/') . '/' . $configuredVendorDir . '/';
    }

    // Get current setting path
    $settingPath = rtrim($this->composerPath, '/') . '/';

    // Detect change
    if ($expectedPath !== $settingPath) {
        return [
            'changed' => true,
            'old_path' => $settingPath,
            'new_path' => $expectedPath
        ];
    }

    return ['changed' => false, 'old_path' => null, 'new_path' => null];
}
```

#### 2b. Add Vendor Directory Consistency Validation

Add this method to validate and report mismatches:

```php
/**
 * Check if composerAutoLoad setting matches vendor-dir in composer.json
 * Uses string normalization for performance (avoids expensive realpath() calls)
 */
private function validateVendorDirConsistency() {
    $changeInfo = $this->detectVendorDirChange();

    if ($changeInfo['changed']) {
        $this->errors[] = "Vendor directory mismatch detected:";
        $this->errors[] = "  Database setting: " . $changeInfo['old_path'];
        $this->errors[] = "  composer.json config: " . $changeInfo['new_path'];
        $this->errors[] = "  Run 'composer install' to install to new location";
        return false;
    }

    return true;
}
```

Then add this call in the `validate()` method after the existing checks:

```php
// Check 5: Vendor directory consistency
if (!$this->validateVendorDirConsistency()) {
    return false;
}
```

#### 2c. Add Automatic Cleanup Logic

Modify the `installIfNeeded()` method to clean up old vendor directories:

```php
public function installIfNeeded() {
    // Detect vendor directory change BEFORE validation
    $changeInfo = $this->detectVendorDirChange();

    // Run validation first
    if ($this->validate()) {
        return true; // Already valid, no install needed
    }

    // Check if the errors are composer-install-fixable
    $installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch'];
    $canFix = false;

    foreach ($this->errors as $error) {
        foreach ($installFixableErrors as $fixableError) {
            if (strpos($error, $fixableError) !== false) {
                $canFix = true;
                break 2;
            }
        }
    }

    if (!$canFix) {
        return false; // Validation failed for reasons composer install won't fix
    }

    // Try to run composer install
    $basePath = PathHelper::getBasePath();
    $composerJsonPath = $basePath . '/composer.json';

    if (!file_exists($composerJsonPath)) {
        return false; // No composer.json to install from
    }

    // Change to project directory and run composer install
    $originalDir = getcwd();
    chdir($basePath);

    $output = [];
    $returnCode = 0;
    exec('composer install --no-dev --optimize-autoloader --no-interaction 2>&1', $output, $returnCode);

    chdir($originalDir);

    if ($returnCode !== 0) {
        $this->errors[] = "Composer install failed: " . implode("\n", $output);
        return false;
    }

    // If vendor directory changed and install succeeded, clean up old directory
    if ($changeInfo['changed'] && $changeInfo['old_path']) {
        $this->cleanupOldVendorDirectory($changeInfo['old_path']);
    }

    // Clear previous validation results and re-validate
    $this->errors = [];
    $this->warnings = [];

    return $this->validate();
}
```

#### 2d. Add Cleanup Method

Add this method to safely remove old vendor directories:

```php
/**
 * Clean up old vendor directory after successful migration to new location
 * @param string $oldPath Full path to old vendor directory
 */
private function cleanupOldVendorDirectory($oldPath) {
    // Safety checks
    $oldPath = rtrim($oldPath, '/');

    // Must contain 'vendor' in path for safety
    if (strpos($oldPath, 'vendor') === false) {
        $this->warnings[] = "Skipping cleanup: path doesn't contain 'vendor': $oldPath";
        return;
    }

    // Must not be root or system directory
    $dangerousPaths = ['/', '/usr', '/var', '/etc', '/home', '/root'];
    if (in_array($oldPath, $dangerousPaths)) {
        $this->warnings[] = "Skipping cleanup: refusing to remove protected directory: $oldPath";
        return;
    }

    // Directory must exist
    if (!is_dir($oldPath)) {
        return; // Nothing to clean up
    }

    // Try to remove the directory
    try {
        $this->recursiveRemoveDirectory($oldPath);
        $this->warnings[] = "✓ Cleaned up old vendor directory: $oldPath";
    } catch (Exception $e) {
        $this->warnings[] = "Failed to clean up old vendor directory: " . $e->getMessage();
    }
}

/**
 * Recursively remove a directory and all its contents
 * @param string $dir Directory path to remove
 */
private function recursiveRemoveDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $this->recursiveRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}
```

**Performance note:** This validation uses string normalization instead of `realpath()` to avoid filesystem calls, keeping it lightweight.

**Safety note:** The cleanup logic includes multiple safety checks to prevent accidental deletion of system directories.

### 3. server_setup.sh Modifications

**Remove the generic composer.json creation** (lines 137-155) and add simple composer install:

```bash
# Ensure Composer is installed globally
log "Composer installed at /usr/local/bin/composer"

# If project files already exist (from archive extraction), install dependencies
if [ -n "$SITENAME" ] && [ -f "/var/www/html/$SITENAME/public_html/composer.json" ]; then
    log "Project files detected - installing composer dependencies"
    cd "/var/www/html/$SITENAME/public_html"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
    chown -R www-data:www-data ../vendor
    log "Composer dependencies installed"
else
    log "No project files yet - composer dependencies will be installed after deployment"
fi
```

That's it! No vendor-dir manipulation, no environment variables, no complex logic.

### 4. new_account.sh Modifications

**Current Reality:** new_account.sh creates the site skeleton (directories, database, virtualhost) but does NOT deploy code. Code deployment always happens via deploy.sh afterwards.

**Typical workflow:**
```bash
sudo ./new_account.sh sitename domain.com IP
./deploy.sh sitename  # This installs composer dependencies
```

**No composer changes needed** - deploy.sh handles composer installation automatically via composer_install_if_needed.php.

**However, Phase 1 added joinery-install.sql.gz to archives.** Update new_account.sh to use it if available:

Change the database restore file default (around line 34):

```bash
# Set database restore file (default or user-specified)
# Check for joinery-install.sql.gz from archive first
if [ -f "joinery-install.sql.gz" ]; then
    DATABASE_RESTORE_FILE="joinery-install.sql.gz"
elif [ -f "joinery-install.sql" ]; then
    DATABASE_RESTORE_FILE="joinery-install.sql"
else
    DATABASE_RESTORE_FILE="joinery-install-sql.sql"  # Legacy fallback
fi

# Allow user override
if [ "$4" != "" ]; then
    DATABASE_RESTORE_FILE="$4"
fi
```

And update the restore logic (around line 165) to handle .gz files:

```bash
# Load database restore file
echo "Loading database from restore file '$DATABASE_RESTORE_FILE'..."
echo "Enter PostgreSQL postgres user password:"

# Check if file is compressed
if [[ "$DATABASE_RESTORE_FILE" == *.gz ]]; then
    if ! gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -W -d "$1"; then
        echo "ERROR: Failed to load database from compressed restore file"
        echo "Database '$1' was created but restore failed."
        echo "You may need to manually restore or recreate the database."
    else
        echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
    fi
else
    if ! psql -U postgres -W -d "$1" -f "$DATABASE_RESTORE_FILE"; then
        echo "ERROR: Failed to load database from restore file"
        echo "Database '$1' was created but restore failed."
        echo "You may need to manually restore or recreate the database."
    else
        echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
    fi
fi
```

**Why this approach:**
- new_account.sh stays focused on site skeleton creation
- deploy.sh handles all code deployment and composer installation
- Simpler, cleaner separation of concerns
- No duplicate composer install logic

### 5. upgrade.php Modifications

**Ensure maintenance_scripts are extracted during web-based upgrades:**

Since Phase 1 already handles tar.gz extraction, we just need to ensure it extracts ALL directories including maintenance_scripts:

```php
// In upgrade.php, the extraction already handles all directories
// Just verify maintenance_scripts are included:

// After extraction, verify critical directories exist
$required_dirs = [
    $stage_location . '/public_html',
    $stage_location . '/maintenance_scripts'  // Ensure scripts are extracted
];

foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        echo "ERROR: Required directory missing after extraction: $dir<br>";
        exit;
    }
}

// Copy maintenance_scripts to site directory
$source_scripts = $stage_location . '/maintenance_scripts';
$dest_scripts = '/var/www/html/' . $sitename . '/maintenance_scripts';

// Copy new scripts (overwriting old ones)
if (is_dir($source_scripts)) {
    recursiveCopy($source_scripts, $dest_scripts);
    echo "Updated maintenance scripts<br>";
}

// Continue with normal upgrade process...
```

### 6. deploy.sh Modifications

**Note:** deploy.sh pulls code directly from git, not from an archive.

**Two changes needed:**

**A. Add maintenance_scripts deployment:**

Currently, deploy.sh only pulls `theme` and `plugins` from the joinery repository via the `deploy_theme_plugin()` function. We need to add maintenance_scripts to the same function.

**Add this code at the end of the `deploy_theme_plugin()` function** (after the plugins deployment, before the cleanup section around line 490):

```bash
# DEPLOY MAINTENANCE_SCRIPTS to /var/www/html/sitename/maintenance_scripts (outside public_html)
verbose_echo "Setting up maintenance_scripts deployment to $site_root/maintenance_scripts..."
local maintenance_stage_dir="$site_root/maintenance_scripts_stage"
rm -rf "$maintenance_stage_dir"
mkdir -p "$maintenance_stage_dir"

# Clone repo for maintenance_scripts
verbose_echo "Cloning maintenance_scripts from: $THEME_PLUGIN_REPO_URL"
if [ "$VERBOSE" = true ]; then
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$maintenance_stage_dir"
else
    git clone --quiet --no-checkout "$THEME_PLUGIN_REPO_URL" "$maintenance_stage_dir" 2>/dev/null
fi
cd "$maintenance_stage_dir" || exit 1
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set "maintenance scripts"
if [ "$VERBOSE" = true ]; then
    git checkout main
else
    git checkout --quiet main 2>/dev/null
fi
rm -rf .git
cd - > /dev/null

# Deploy maintenance_scripts directly to site root
if [[ -d "$maintenance_stage_dir/maintenance scripts" ]]; then
    verbose_echo "Deploying maintenance_scripts to $site_root/maintenance_scripts..."

    # Remove old maintenance_scripts if exists
    if [[ -d "$site_root/maintenance_scripts" ]]; then
        rm -rf "$site_root/maintenance_scripts"
    fi

    # Move to site root (note: git has "maintenance scripts" with space, we deploy as maintenance_scripts with underscore)
    mv "$maintenance_stage_dir/maintenance scripts" "$site_root/maintenance_scripts" || {
        echo "ERROR: Failed to move maintenance_scripts to site root"
        return 1
    }

    # Make scripts executable
    chmod +x "$site_root/maintenance_scripts"/*.sh 2>/dev/null || true

    verbose_echo "Maintenance scripts deployed successfully"
else
    echo "WARNING: No maintenance scripts directory found in joinery repository"
fi

# Cleanup staging directory
rm -rf "$maintenance_stage_dir"

echo "Maintenance scripts deployment complete."
```

**B. Keep using composer_install_if_needed.php** (no change needed at line 1225):

The existing composer validation and installation via `composer_install_if_needed.php` should remain unchanged. This script:
- Validates composer setup (checks autoload.php, composer.lock exist)
- Only runs `composer install` when actually needed
- Verifies required packages are installed
- Provides detailed error messages

With Phase 2's standardized `composer.json` (with `vendor-dir: ../vendor`), the script will automatically work correctly without modification.

**Why this approach?**
- Maintenance scripts need to be deployed with each deployment to stay version-matched with the code
- The joinery repository already contains maintenance_scripts - we just need to pull them
- `composer_install_if_needed.php` provides validation that simple bash commands can't match
- No need to replace working, validated code

## Phase 2 Installation Flow

### Day 0 - Fresh Server Installation
**Starting point:** You have the archive file (joinery-X-Y.tar.gz) from publish_upgrade.php

1. Upload and extract archive to `/home/user1/joinery/joinery/`:
   ```bash
   cd /home/user1
   mkdir -p joinery/joinery
   cd joinery/joinery
   tar -xzf joinery-X-Y.tar.gz
   # Creates: maintenance scripts/ directory with tools
   ```

2. Run server_setup.sh for system configuration:
   ```bash
   cd "maintenance scripts"
   sudo ./server_setup.sh
   # This will:
   # - Install Apache, PostgreSQL, PHP, Composer
   # - Set up system dependencies
   ```

3. Create first site:
   ```bash
   # Still in maintenance scripts directory
   sudo ./new_account.sh SITENAME domain.com SERVER_IP
   # This will:
   # - Create site directories at /var/www/html/SITENAME/
   # - Create database and load joinery-install.sql.gz
   # - Create Apache virtualhost
   ```

4. Deploy code to the site:
   ```bash
   ./deploy.sh SITENAME
   # This will:
   # - Pull code from git to /var/www/html/SITENAME/public_html/
   # - Pull themes/plugins from joinery repo
   # - Pull maintenance_scripts from joinery repo to /var/www/html/SITENAME/maintenance_scripts/
   # - Run composer install (creates /var/www/html/SITENAME/vendor/ automatically)
   # - Update database schema
   ```

5. For additional sites, repeat steps 3-4 with different sitename

### Standard Installation Flows

1. **Fresh Server Setup (via server_setup.sh)**:
   - One-time system configuration
   - Installs Apache, PostgreSQL, PHP, Composer globally
   - Sets up system-level dependencies
   - Does NOT deploy any sites

2. **New Site Creation (via new_account.sh + deploy.sh)**:
   - **new_account.sh:** Creates site skeleton (directories, database, virtualhost)
   - **deploy.sh:** Deploys code from git
   - Composer dependencies installed automatically during deploy
   - Vendor directory created at `/var/www/html/{SITE}/vendor/` automatically
   - maintenance_scripts deployed to `/var/www/html/{SITE}/maintenance_scripts/`

3. **Code Updates (via deploy.sh)**:
   - Pull latest code from git to `/var/www/html/{SITE}/public_html/`
   - Pull latest themes/plugins from joinery repo
   - Pull latest maintenance_scripts from joinery repo
   - Run composer_install_if_needed.php (updates deps if needed)
   - Run database migrations
   - Vendor directory maintained at `/var/www/html/{SITE}/vendor/`

## Benefits of Simplified Phase 2

1. **Single Source of Truth**: `composer.json` in archive is pre-configured, never modified
2. **No Script Complexity**: Each script just runs `composer install` - no file manipulation
3. **Per-Site Isolation**: Each site has independent vendor directory
4. **Docker Compatible**: Vendor directory easily mounted as a Docker volume
5. **Reproducible Builds**: `composer.lock` ensures exact version matching
6. **Maintainable**: Identical 3-line composer install logic in all scripts
7. **Safe**: No file modifications during deployment
8. **Works Everywhere**: Same approach for traditional servers and Docker containers

## Phase 2 Testing Checklist

1. **ComposerValidator enhancements:**
   - Verify `validateVendorDirConsistency()` method added to ComposerValidator class
   - Test validator detects mismatch when composerAutoLoad setting doesn't match composer.json vendor-dir
   - Test validator passes when setting and composer.json are consistent
   - Verify error messages are clear and actionable

2. **composer.json configuration:**
   - Verify `composer.json` has `"vendor-dir": "../vendor"`
   - Verify `composer.lock` is included in archive

3. **Script installation flow:**
   - Test server_setup.sh installs dependencies correctly and copies maintenance_scripts
   - Test new_account.sh extracts archive and installs dependencies
   - Confirm deploy.sh updates maintenance_scripts and installs/updates dependencies
   - Confirm upgrade.php updates maintenance_scripts during web upgrades
   - Verify vendor directory created at `/var/www/html/{SITE}/vendor/`
   - Verify maintenance_scripts at `/var/www/html/{SITE}/maintenance_scripts/`

4. **Site functionality:**
   - Test fresh install creates working site with all dependencies
   - Test upgrade maintains correct dependencies
   - Test all critical packages (PHPMailer, Stripe, Mailgun) are accessible

5. **Docker compatibility:**
   - Verify Docker volume mount works correctly for `../vendor`
   - Test container can access vendor dependencies

6. **Configuration validation:**
   - After fresh install, verify composerAutoLoad setting is correct
   - Test ComposerValidator catches any misconfigurations

## Phase 2 Notes

- **Key difference from complex proposal**: No file manipulation, no duplicate logic, single pre-configured composer.json
- Each site has isolated vendor directory, preventing version conflicts between sites
- The relative path `"../vendor"` works identically in Docker and traditional deployments
- Remove the old generic `/home/user1/vendor/` and `/home/user1/composer.json` - they are no longer needed
- Always include `composer.lock` in the archive to ensure reproducible installations
- Three scripts, same simple pattern: `cd public_html && composer install --no-dev --optimize-autoloader`

## Note on Maintenance Scripts Location

**Current Phase 1 implementation:** Pulls maintenance scripts from `/home/user1/joinery/joinery/maintenance_scripts/` when creating archives.

**Phase 2 approach:** Scripts end up at `/var/www/html/{SITE}/maintenance_scripts/` after installation. This is fine - the archive structure remains the same, just the final deployed location is clarified in Phase 2.

**No changes needed to Phase 1 implementation** - it already creates the correct archive structure with maintenance_scripts/ directory.

## Important Database Setting Note

**The `composerAutoLoad` database setting:** This setting is stored in the database and tells the application where to find the vendor autoload.php file.

- **How it's updated:** Migration 0.XX automatically updates it from `/home/user1/vendor/` to `../vendor/`
- **Expected value after Phase 2:** `../vendor/` (relative path that resolves to `/var/www/html/{SITE}/vendor/`)
- **Validation:** ComposerValidator will detect if this setting doesn't match the composer.json vendor-dir configuration
- **Fresh installs:** The migration ensures new sites get the correct value automatically

## Summary of Phase 2 Changes

**Code Changes (7 items):**
1. ✏️ **Migration** - Add composerAutoLoad update migration to migrations.php
2. ✏️ **composer.json** - Change `vendor-dir` from `/home/user1/vendor` to `../vendor`
3. ✏️ **ComposerValidator.php** - Add vendor directory detection, validation, and automatic cleanup methods:
   - `detectVendorDirChange()` - Detects vendor directory location changes
   - `validateVendorDirConsistency()` - Validates setting matches composer.json
   - `cleanupOldVendorDirectory()` - Automatically removes old vendor dirs after successful migration
   - `recursiveRemoveDirectory()` - Safe recursive directory removal
   - Modified `installIfNeeded()` - Triggers cleanup after successful install
4. ✏️ **server_setup.sh** - Remove generic composer.json creation in /home/user1
5. ✏️ **new_account.sh** - Add support for .gz compressed SQL files
6. ✏️ **deploy.sh** - Add maintenance_scripts deployment to deploy_theme_plugin() function

**No Changes Needed:**
- ✅ **upgrade.php** - Already extracts maintenance_scripts from archive (Phase 1)
- ✅ **composer_install_if_needed.php** - Works with new paths automatically
- ✅ **publish_upgrade.php** - Already includes maintenance_scripts (Phase 1)

**Result:**
- Each site gets isolated vendor directory at `/var/www/html/{SITE}/vendor/`
- Old vendor directories automatically cleaned up during migration
- Maintenance scripts stay version-matched with code
- Database setting automatically updated via migration
- Simple, consistent composer workflow across all scripts
