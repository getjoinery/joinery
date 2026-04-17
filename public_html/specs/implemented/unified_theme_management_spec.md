# Unified Theme Management Specification

**Version:** 2.2
**Date:** 2026-01-19
**Status:** Draft

**Summary:** Sparse-fetch, update-only model. Deployment fetches only installed themes via git sparse checkout. Users install new themes by uploading ZIP files. System themes (e.g., falcon) are protected from deletion.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Current State Analysis](#current-state-analysis)
3. [Identified Issues](#identified-issues)
4. [Proposed Solution](#proposed-solution)
5. [Implementation Plan](#implementation-plan)
6. [Migration Strategy](#migration-strategy)

---

## Executive Summary

This specification proposes a **sparse-fetch, update-only** approach to theme management that:
- Maintains all themes in source control as reference
- **Never auto-installs new themes** during deployment
- Only fetches and updates themes that are **already installed** on the site
- Uses sparse git checkout for efficient deployment (only downloads installed themes)
- Allows users to permanently remove themes without them returning
- Scales to hundreds of themes without user burden
- Users install new themes by uploading ZIP files (existing functionality)

### Core Principle

> **Deployment updates existing themes. It never adds new ones.**

This is the fundamental shift from the current behavior. The repository contains all available themes, but deployment only touches themes already installed on the site.

---

## Current State Analysis

### Theme Data Model

**File:** `data/themes_class.php`
**Table:** `thm_themes`

| Field | Type | Purpose |
|-------|------|---------|
| `thm_theme_id` | int8 | Primary key |
| `thm_name` | varchar(50) | Unique theme identifier |
| `thm_is_active` | bool | Currently active theme |
| `thm_is_stock` | bool | Stock vs custom classification |
| `thm_status` | varchar(20) | Status: 'installed', 'active' |
| `thm_metadata` | jsonb | Full manifest data |

### Current Theme Flow

#### During Upload (via Admin UI)
1. User uploads ZIP file
2. `ThemeManager::installTheme()` extracts and validates
3. Theme is registered in database
4. **Always marked as `is_stock = false`** (custom)

#### During Deployment (`deploy.sh`)

```
1. Clone repository (includes all themes)
2. For each theme in staging:
   a. Read manifest from BACKUP (existing deployment)
   b. If backup manifest says is_stock=false → PRESERVE from backup
   c. If backup manifest says is_stock=true → UPDATE with staged version
   d. If no backup exists → ADD new theme
3. Deploy to public_html
4. Run database migrations (includes theme_plugin_registry_sync)
```

**Critical:** Decision uses **backup's manifest**, not database `thm_is_stock` field.

#### During Upgrade (`upgrade.php`)

```
1. Download upgrade archive from upgrade server
2. Extract to staging area
3. Call DeploymentHelper::preserveCustomThemesPlugins()
   - Same logic as deploy.sh
4. Move staged files to public_html
5. Run update_database.php
```

#### During Deletion (via Admin UI)

```
1. Check if theme is active → Block if active
2. Check if files exist → Allow deletion
3. Call theme->permanent_delete()
4. **ONLY removes database record**
5. Files remain on disk
```

### Current Scripts Involved

| Script | Location | Theme Handling |
|--------|----------|----------------|
| `upgrade.php` | `utils/upgrade.php` | Calls `DeploymentHelper::preserveCustomThemesPlugins()` |
| `deploy.sh` | `maintenance_scripts/install_tools/deploy.sh` | Inline preservation logic + calls same helper |
| `DeploymentHelper.php` | `includes/DeploymentHelper.php` | `preserveCustomThemesPlugins()` method |
| `ThemeManager.php` | `includes/ThemeManager.php` | `sync()`, `installTheme()` methods |
| `admin_themes.php` | `adm/admin_themes.php` | Admin UI |
| `admin_themes_logic.php` | `adm/logic/admin_themes_logic.php` | Admin actions |

---

## Identified Issues

### Issue 1: Delete Removes Database Record Only

**Current Behavior:**
- `theme->permanent_delete()` only removes the database row
- Theme files remain at `/theme/{name}/`
- No mechanism to remove files

**Problem:**
- Users expect "delete" to remove the theme
- Theme directory persists invisibly
- Next sync will re-register the theme in database

**Code Location:** `admin_themes_logic.php:104`

### Issue 2: Deleted Themes Get Reinstalled

**Current Behavior:**
1. User deletes theme via admin UI
2. Database record removed
3. Files may or may not exist
4. During next deployment:
   - Repository contains theme
   - `deploy_themes_plugins_from_stage()` copies theme to public_html
   - `theme_plugin_registry_sync.php` migration re-registers theme

**Problem:**
- No persistent "do not install this theme" flag
- Deployment treats all repository themes as candidates

**Code Location:** `deploy.sh:239-291`, `migrations/theme_plugin_registry_sync.php`

### Issue 3: Stock/Custom Toggle Doesn't Affect Deployment

**Current Behavior:**
1. Admin marks theme as "custom" (sets `thm_is_stock = false`)
2. Database updated
3. During deployment:
   - `preserveCustomThemesPlugins()` reads **backup's manifest file**, not database
   - Manifest still has `"is_stock": true`
   - Theme gets **updated** despite database saying custom

**Problem:**
- UI action is misleading - it doesn't actually prevent updates
- Database and manifest are out of sync
- No write-back to manifest

**Code Location:** `DeploymentHelper.php:410-411`

### Issue 4: Asymmetric Upload Behavior

**Current Behavior:**
1. Uploaded themes are marked `is_stock = false`
2. "Custom" themes are **preserved** during deployment
3. This means uploaded themes won't receive updates

**Problem:**
- Confusing: "custom" = "will be preserved" (good)
- But what if user uploads a newer version of a stock theme?
- Need to understand: is the intent to preserve or to exclude?

### Issue 5: No Exclusion Mechanism

**Current Behavior:**
- No way to say "never install/reinstall this theme"
- Deleting removes it temporarily
- Next deployment/sync brings it back

**Problem:**
- Users cannot permanently remove unwanted stock themes
- Theme list cluttered with unused themes

### Issue 6: Multiple Stock Themes Possible

**Current Behavior:**
- Multiple themes can have `is_stock = true`
- All are updated during deployment
- No validation or warning

**Problem:**
- Unclear behavior if stock themes conflict
- No single "source" theme concept

### Issue 7: Manifest Auto-Generation Defaults to Stock

**Current Behavior:**
```bash
# deploy.sh:250-258
if [[ ! -f "$manifest_file" ]]; then
    echo "Auto-generating theme.json for $theme_name"
    cat > "$manifest_file" << EOF
{
  "is_stock": true
}
EOF
fi
```

**Problem:**
- Themes without manifests are treated as stock
- This may override user intent
- Custom themes should require explicit `is_stock: false`

---

## Proposed Solution

### Core Concept: Sparse Fetch, Update-Only Model

The repository contains all available themes, but deployment only fetches and updates themes that are already installed on the site. Users install new themes by uploading ZIP files.

```
┌─────────────────────────────────────────────────────────────────┐
│                    SPARSE FETCH MODEL                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  REPOSITORY (200 themes)          SITE (3 themes)               │
│  ─────────────────────            ───────────────               │
│  theme/                           public_html/theme/            │
│    falcon/        ─────────────►    falcon/    (UPDATED)        │
│    tailwind/      (not fetched)     custom1/   (PRESERVED)      │
│    canvas/        (not fetched)     bootstrap/ (UPDATED)        │
│    bootstrap/     ─────────────►                                │
│    material/      (not fetched)                                 │
│    ... 195 more   (not fetched)                                 │
│                                                                  │
│  Key: Deployment fetches ONLY installed themes from repo        │
│       New themes installed by user uploading ZIP files          │
│       Saves bandwidth and disk space during deployment          │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Key Behavior Changes

| Scenario | Current Behavior | New Behavior |
|----------|------------------|--------------|
| New theme added to repository | Auto-installed on all sites | **Not installed** - user uploads ZIP if wanted |
| User deletes a stock theme | Returns on next deployment | **Stays deleted** (files + DB removed) |
| User deletes a custom theme | May or may not return | **Stays deleted** (files + DB removed) |
| Deployment runs | Clones all 200 themes | **Sparse checkout of installed themes only** |
| User wants new theme | Already there (maybe unwanted) | **Uploads ZIP via Admin UI** (existing flow) |

### Deployment Efficiency

Instead of cloning all 200 themes during deployment, use git sparse checkout to fetch only the themes already installed on the site.

```
/var/www/html/{site}/
├── public_html/
│   └── theme/              ← Installed themes only (2-5 themes)
└── config/
```

**Benefits:**
- Deployment fetches ~15MB instead of ~1GB
- Faster deployments
- Less bandwidth usage
- Deleted themes stay deleted (not re-fetched)

**User theme installation:**
- Upload ZIP file via Admin UI (existing functionality)
- No browsing/installing from repository in UI

### Modified Database Schema

Minimal change - add one new field:

```php
// In themes_class.php - EXISTING fields (no changes needed):
'thm_is_stock' => array('type'=>'bool', 'default'=>true),
// TRUE  = Updates from repository during deployment
// FALSE = Preserved during deployment (user modifications)

'thm_is_active' => array('type'=>'bool', 'default'=>false),
// TRUE  = Currently active theme
// FALSE = Installed but not active

// NEW field to add:
'thm_is_system' => array('type'=>'bool', 'default'=>false),
// TRUE  = Cannot be deleted, always updated
// FALSE = Normal theme
```

**Key insights:**
- If theme isn't in `public_html/theme/`, it's not installed
- If theme isn't installed, deployment won't fetch it
- The filesystem IS the source of truth for "installed" state
- No need for manifest file or remote browsing - users upload ZIPs

### Modified Deployment Flow

```
NEW DEPLOYMENT FLOW:

1. Determine which themes are currently installed
   List: /var/www/html/{site}/public_html/theme/*/

2. Clone repository with SPARSE CHECKOUT (themes + core files only)
   - Fetch: public_html/* EXCEPT public_html/theme/*
   - Then fetch ONLY: public_html/theme/{installed_theme_1}/
                      public_html/theme/{installed_theme_2}/
                      ... (only themes already on site)

3. For each installed theme:
   ┌─────────────────────────────────────────────────────────────┐
   │ Is this theme in the repository?                            │
   ├─────────────────────────────────────────────────────────────┤
   │ YES → Check theme's manifest is_stock value:                │
   │       • is_stock = true  → UPDATE from staging              │
   │       • is_stock = false → PRESERVE existing (skip update)  │
   │                                                              │
   │ NO  → This is a custom-uploaded theme                       │
   │       → PRESERVE existing (it's not in repo)                │
   └─────────────────────────────────────────────────────────────┘

4. Deploy to public_html as normal

5. Run database migrations
   - theme_plugin_registry_sync only registers INSTALLED themes
```

**Sparse checkout benefit:** With 200 themes in repo but only 3 installed,
deployment fetches ~15MB instead of ~1GB.

### Comparison: Old vs New Flow

```
OLD FLOW (Current):
─────────────────────
Repository has: falcon, tailwind, canvas, bootstrap, material
Site has:       falcon, custom1

Deployment:     Clones entire repo (~1GB with 200 themes)
After deploy:   falcon, tailwind, canvas, bootstrap, material, custom1
                ↑ ALL repo themes installed! ↑

NEW FLOW (Proposed):
────────────────────
Repository has: falcon, tailwind, canvas, bootstrap, material
Site has:       falcon, custom1

Deployment:     Sparse checkout (only falcon from repo, ~15MB)
After deploy:   falcon (updated), custom1 (preserved)
                ↑ Only existing themes affected ↑

Want more?      User uploads ZIP file to install new themes
```

### Admin UI Changes

The admin UI remains simple - shows installed themes with upload capability.

#### Installed Themes (Enhanced)

Shows themes in `public_html/theme/`:

| Theme | Version | Status | Type | Actions |
|-------|---------|--------|------|---------|
| Falcon | 2.1.0 | Active | System | - |
| Custom1 | 1.0.0 | Inactive | Custom | Activate, Delete |
| Bootstrap | 1.5.0 | Inactive | Stock | Activate, Mark Custom, Delete |

**Actions:**
- **Activate** - Make this the active theme
- **Mark as Custom** - Prevent auto-updates (writes to manifest)
- **Mark as Stock** - Allow auto-updates (writes to manifest)
- **Delete** - Remove from site (files + database record) - **NOW ACTUALLY DELETES FILES**

*System themes show lock icon, no Delete/Mark Custom actions.*

#### Upload Theme (Existing, unchanged)

Users install new themes by uploading ZIP files:

1. User obtains theme ZIP (from releases page, third party, etc.)
2. Uploads via Admin UI
3. System extracts to `public_html/theme/{name}/`
4. Registers in database

**No "browse available themes" feature** - users get themes externally and upload them.

### Theme Lifecycle States

```
┌─────────────────────────────────────────────────────────────────┐
│                    THEME LIFECYCLE                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  [IN REPOSITORY ONLY]                                           │
│  Location: Remote repository only                               │
│  Database: Not registered                                       │
│  ─────────────────────────────────                              │
│  • Source of truth for stock themes                             │
│  • No effect on site until installed                            │
│  • Used by deployment for sparse checkout                       │
│       │                                                         │
│       │ Upload ZIP (admin), new_account.sh, or manually         │
│       ▼                                                         │
│  [INSTALLED - SYSTEM]                                           │
│  Location: /public_html/theme/{name}/                           │
│  Database: thm_is_system = true                                 │
│  ─────────────────────────────────                              │
│  • Auto-updates during deployment                               │
│  • CANNOT be deleted or marked custom                           │
│  • Required for platform to function                            │
│                                                                  │
│  [INSTALLED - STOCK]                                            │
│  Location: /public_html/theme/{name}/                           │
│  Database: thm_is_stock = true, thm_is_system = false           │
│  ─────────────────────────────────                              │
│  • Auto-updates during deployment                               │
│  • Can be activated, deleted, or marked custom                  │
│       │                                                         │
│       │ Mark as Custom                                          │
│       ▼                                                         │
│  [INSTALLED - CUSTOM]                                           │
│  Location: /public_html/theme/{name}/                           │
│  Database: thm_is_stock = false                                 │
│  ─────────────────────────────────                              │
│  • Preserved during deployment (no auto-updates)                │
│  • User modifications protected                                 │
│  • Can be marked back to stock                                  │
│       │                                                         │
│       │ Delete                                                  │
│       ▼                                                         │
│  [NOT INSTALLED]                                                │
│  Location: Not on site                                          │
│  Database: No record                                            │
│  ─────────────────────────────────                              │
│  • Re-install by uploading ZIP or using new_account.sh          │
│  • Deleted themes stay deleted (won't be fetched on deploy)     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Admin UI Badges

| State | Badge | Description |
|-------|-------|-------------|
| Active | `bg-success` | Currently active theme |
| System | `bg-primary` | Protected, cannot be deleted |
| Stock | `bg-info` | Auto-updates during deployment |
| Custom | `bg-warning` | Preserved during deployment |

### Handling Initial Installation / New Sites

For new sites, themes are installed via command-line arguments to `new_account.sh`:

```bash
# Default: install falcon (system theme) only
./new_account.sh mysite

# Install specific themes
./new_account.sh mysite --themes=falcon,bootstrap,material

# Specify which theme to activate
./new_account.sh mysite --themes=falcon,tailwind --activate=tailwind
```

**Default behavior:**
- If `--themes` not specified, only falcon (system theme) is installed
- If `--activate` not specified, falcon is activated

See Phase 8 for implementation details.

### Delete Behavior (Fixed)

Current problem: Delete only removes database record, files remain.

**New behavior:**
```php
case 'delete':
    $theme = Theme::get_by_theme_name($theme_name);
    if ($theme && !$theme->get('thm_is_active')) {
        // 1. Check if theme is protected (system theme)
        if ($theme->get('thm_is_system')) {
            $error = "Cannot delete system theme '$theme_name'. System themes are required for the platform to function.";
            break;
        }

        // 2. Delete theme directory
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        if (is_dir($theme_path)) {
            exec("rm -rf " . escapeshellarg($theme_path));
        }

        // 3. Delete database record
        $theme->permanent_delete();

        $message = "Theme '$theme_name' has been completely removed.";
    }
    break;
```

Since deployment no longer auto-installs themes, deleting files + database record means the theme is truly gone (until manually reinstalled from catalog).

---

### System (Protected) Themes

Some themes are essential for the platform to function and must be protected from deletion.

#### Manifest Flag

In `theme.json`:
```json
{
  "name": "Falcon",
  "version": "2.1.0",
  "is_stock": true,
  "system": true,
  "description": "Core Bootstrap 5 admin theme"
}
```

#### Database Field

Add to `themes_class.php`:
```php
'thm_is_system' => array('type'=>'bool', 'default'=>false),
```

#### Behavior of System Themes

| Action | Allowed? | Reason |
|--------|----------|--------|
| Delete | **NO** | Required for platform |
| Uninstall | **NO** | Same as delete |
| Mark as Custom | **NO** | Must always receive updates |
| Mark as Stock | N/A | Already stock |
| Activate | YES | Can be activated |
| Deactivate | YES | Can switch to another theme |

#### Protected Theme List

System themes are identified by `"system": true` in their manifest. Currently:

| Theme | Why System? |
|-------|-------------|
| `falcon` | Core admin interface theme |

Future system themes might include a minimal fallback theme.

#### Admin UI Display

```php
// In admin_themes.php - show lock icon for system themes
if ($theme->get('thm_is_system')) {
    $type_badge = '<span class="badge bg-primary">System</span>';
    // No Delete action available
}
```

---

### New Site Installation

When creating a new site, theme installation uses sparse checkout from the repository:

#### Default Behavior

```bash
./new_account.sh mysite
```

1. Sparse checkout of falcon (system theme) from repository
2. Activate falcon
3. User can install additional themes by uploading ZIP files

#### With Additional Themes

```bash
./new_account.sh mysite --themes=falcon,bootstrap,material --activate=bootstrap
```

1. Sparse checkout of specified themes from repository
2. Activate the specified theme (or falcon by default)

#### Command-Line Arguments

| Argument | Purpose | Example |
|----------|---------|---------|
| `--themes=list` | Themes to install (comma-separated) | `--themes=falcon,bootstrap` |
| `--activate=name` | Theme to activate (default: falcon) | `--activate=bootstrap` |

#### Why Sparse Checkout?

- **Efficient**: Downloads only requested themes (~5MB per theme vs ~1GB for all)
- **Simple**: No local catalog to maintain
- **Fast**: Git sparse checkout is quick and reliable
- **Standard**: Works with standard git installations

#### Why No Config File?

- **Simplicity**: Command-line arguments cover all use cases
- **Scripting**: Easy to automate: `./new_account.sh mysite --themes=falcon,bootstrap`
- **Defaults work**: Most sites just need falcon, add more later via ZIP upload
- **No file to maintain**: One less thing to document and support

---

## Implementation Plan

### Phase 0: Database Schema Update

**Files to modify:**
- `data/themes_class.php`

**Add system theme field:**
```php
public static $field_specifications = array(
    // ... existing fields ...
    'thm_is_system' => array('type'=>'bool', 'default'=>false),
);
```

This field is populated from the `"system": true` flag in theme.json during sync.

### Phase 1: Deployment Script Changes

**Files to modify:**
- `deploy.sh` - Use sparse checkout and only update existing themes

**Step 1: Sparse checkout to fetch only installed themes**
```bash
# Get list of currently installed themes
get_installed_themes() {
    local site="$1"
    local theme_dir="/var/www/html/$site/public_html/theme"
    local themes=""

    if [[ -d "$theme_dir" ]]; then
        for theme in "$theme_dir"/*; do
            if [[ -d "$theme" ]]; then
                local name=$(basename "$theme")
                themes="$themes public_html/theme/$name"
            fi
        done
    fi

    echo "$themes"
}

# Clone with sparse checkout - only fetch installed themes
sparse_clone_with_themes() {
    local target_site="$1"
    local staging_dir="/var/www/html/$target_site/public_html_stage"
    local installed_themes=$(get_installed_themes "$target_site")

    rm -rf "$staging_dir"
    mkdir -p "$staging_dir"

    cd "$staging_dir"
    git clone --no-checkout --filter=blob:none "$REPO_URL" .
    git sparse-checkout init --cone

    # Fetch everything EXCEPT theme directory, then add only installed themes
    git sparse-checkout set public_html
    git sparse-checkout add $installed_themes  # Only installed themes

    git checkout main
    rm -rf .git
}
```

**Step 2: Update only existing themes**
```bash
deploy_installed_themes_only() {
    local target_site="$1"
    local staging_dir="/var/www/html/$target_site/public_html_stage"
    local public_html_dir="/var/www/html/$target_site/public_html"

    echo "Updating installed themes only..."

    for installed_theme in "$public_html_dir/theme"/*; do
        if [[ -d "$installed_theme" ]]; then
            local theme_name=$(basename "$installed_theme")
            local staged_theme="$staging_dir/theme/$theme_name"

            if [[ -d "$staged_theme" ]]; then
                local manifest_file="$installed_theme/theme.json"
                local is_stock=$(get_json_value "$manifest_file" "is_stock" "true")

                if [[ "$is_stock" == "true" ]]; then
                    echo "Updating stock theme: $theme_name"
                    rm -rf "$installed_theme"
                    cp -r "$staged_theme" "$public_html_dir/theme/"
                else
                    echo "Preserving custom theme: $theme_name"
                fi
            else
                echo "Preserving uploaded theme: $theme_name (not in repo)"
            fi
        fi
    done
}
```

**Key changes:**
1. Sparse checkout fetches only themes already installed
2. No new themes are copied from staging
3. Custom themes (is_stock=false) are preserved

### Phase 2: Modify DeploymentHelper

**Files to modify:**
- `includes/DeploymentHelper.php`

**Changes:**
1. Rename/refactor `preserveCustomThemesPlugins()` to `updateInstalledThemesOnly()`
2. Change logic from "process all staged themes" to "process only installed themes"

```php
/**
 * Update only themes that are already installed (NEW LOGIC)
 */
public static function updateInstalledThemesOnly($stage_dir, $public_html_dir, $verbose = false) {
    $result = [
        'success' => true,
        'themes_updated' => 0,
        'themes_preserved' => 0,
        'errors' => []
    ];

    $staged_themes_dir = $stage_dir . '/theme';
    $installed_dir = $public_html_dir . '/theme';

    // Only process themes that are ALREADY INSTALLED
    if (!is_dir($installed_dir)) {
        return $result;
    }

    foreach (scandir($installed_dir) as $theme_name) {
        if ($theme_name == '.' || $theme_name == '..') continue;

        $installed_path = $installed_dir . '/' . $theme_name;
        $staged_path = $staged_themes_dir . '/' . $theme_name;

        if (!is_dir($installed_path)) continue;

        // Check if theme exists in staging (repository)
        if (is_dir($staged_path)) {
            // Read installed theme's manifest
            $manifest_path = $installed_path . '/theme.json';
            $is_stock = true;

            if (file_exists($manifest_path)) {
                $manifest = json_decode(file_get_contents($manifest_path), true);
                $is_stock = $manifest['is_stock'] ?? true;
            }

            if ($is_stock) {
                // Update from staging
                exec("rm -rf " . escapeshellarg($installed_path));
                exec("cp -r " . escapeshellarg($staged_path) . " " . escapeshellarg($installed_path));
                $result['themes_updated']++;

                if ($verbose) echo "Updated stock theme: $theme_name\n";
            } else {
                // Preserve custom theme
                $result['themes_preserved']++;
                if ($verbose) echo "Preserved custom theme: $theme_name\n";
            }
        } else {
            // Theme not in repo (custom upload) - preserve
            $result['themes_preserved']++;
            if ($verbose) echo "Preserved uploaded theme: $theme_name\n";
        }
    }

    return $result;
}
```

### Phase 3: Update Admin UI

**Files to modify:**
- `adm/admin_themes.php` - Add system theme protection UI
- `adm/logic/admin_themes_logic.php` - Fix delete handler, add system protection

**UI changes:**
```php
// Show system badge and hide dangerous actions for system themes
if ($theme->get('thm_is_system')) {
    $type_badge = '<span class="badge bg-primary"><i class="fas fa-lock"></i> System</span>';
    // No Delete or Mark Custom actions for system themes
} elseif ($is_stock) {
    $type_badge = '<span class="badge bg-info">Stock</span>';
} else {
    $type_badge = '<span class="badge bg-warning">Custom</span>';
}
```

**Logic file changes:**
```php
// In admin_themes_logic.php

case 'delete':
    $theme_name = $post['theme_name'];
    $theme = Theme::get_by_theme_name($theme_name);
    if ($theme) {
        if ($theme->get('thm_is_system')) {
            $error = "Cannot delete system theme '$theme_name'.";
        } elseif ($theme->get('thm_is_active')) {
            $error = "Cannot delete active theme. Switch to another theme first.";
        } else {
            // DELETE FILES (this is the fix!)
            $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
            if (is_dir($theme_path)) {
                exec("rm -rf " . escapeshellarg($theme_path));
            }
            // Delete database record
            $theme->permanent_delete();
            $message = "Theme '$theme_name' has been removed.";
        }
    }
    break;

case 'mark_custom':
    $theme_name = $post['theme_name'];
    $theme = Theme::get_by_theme_name($theme_name);
    if ($theme) {
        if ($theme->get('thm_is_system')) {
            $error = "Cannot mark system theme as custom.";
        } else {
            $theme->set('thm_is_stock', false);
            $theme->save();
            // Write back to manifest
            $theme_manager->writeManifestStockStatus($theme_name, false);
            $message = "Theme '$theme_name' marked as custom. It will not be updated during deployments.";
        }
    }
    break;
```

**No "Available Themes" section** - users upload ZIPs to install new themes (existing functionality).

### Phase 4: Add ThemeManager Helper Methods

**Files to modify:**
- `includes/ThemeManager.php`

**New methods (minimal - no remote browsing):**
```php
/**
 * Check if a theme is installed
 */
public function isInstalled($theme_name) {
    $install_path = PathHelper::getAbsolutePath('theme/' . $theme_name);
    return is_dir($install_path);
}

/**
 * Write is_stock value back to theme manifest
 * This ensures the manifest stays in sync with database changes
 */
public function writeManifestStockStatus($theme_name, $is_stock) {
    $manifest_path = $this->getExtensionPath($theme_name) . '/theme.json';

    if (!file_exists($manifest_path)) {
        $manifest = [
            'name' => $theme_name,
            'is_stock' => $is_stock
        ];
    } else {
        $manifest = json_decode(file_get_contents($manifest_path), true);
        $manifest['is_stock'] = $is_stock;
    }

    return file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Delete a theme completely (files and database record)
 * @param string $theme_name Theme to delete
 * @return bool Success
 */
public function deleteTheme($theme_name) {
    $theme = Theme::get_by_theme_name($theme_name);

    if (!$theme) {
        throw new Exception("Theme not found: $theme_name");
    }

    if ($theme->get('thm_is_system')) {
        throw new Exception("Cannot delete system theme: $theme_name");
    }

    if ($theme->get('thm_is_active')) {
        throw new Exception("Cannot delete active theme. Switch to another theme first.");
    }

    // Delete files
    $theme_path = PathHelper::getAbsolutePath('theme/' . $theme_name);
    if (is_dir($theme_path)) {
        exec("rm -rf " . escapeshellarg($theme_path));
    }

    // Delete database record
    $theme->permanent_delete();

    return true;
}
```

**Note:** No `fetchAvailableThemes()` or `installFromRepository()` methods needed.
Users install themes by uploading ZIP files (existing functionality).
The new_account.sh script handles initial theme installation during site creation.

### Phase 5: Update upgrade.php

**Files to modify:**
- `utils/upgrade.php`

**Changes:**
- Replace `preserveCustomThemesPlugins()` with `updateInstalledThemesOnly()`
- No catalog population needed (upgrade archive only contains installed theme updates)

```php
// After extracting upgrade archive...

echo '<br><h3>Updating Installed Themes</h3>';
$result = DeploymentHelper::updateInstalledThemesOnly($stage_directory, $public_html_dir, $verbose);
echo "✓ Themes: {$result['themes_updated']} updated, {$result['themes_preserved']} preserved<br>";
```

**Note:** The upgrade archive from the upgrade server should only contain:
1. Core platform files
2. The themes that are relevant for the upgrade (typically just stock themes)

For sites that need specific themes, the upgrade process only updates what's already installed.
Users obtain new themes by downloading ZIP files and uploading via Admin UI.

### Phase 6: Manifest Write-Back on UI Changes

**Files to modify:**
- `adm/logic/admin_themes_logic.php`

**When user marks theme as custom/stock, write to manifest:**
```php
case 'mark_stock':
    $theme_name = $post['theme_name'];
    $theme = Theme::get_by_theme_name($theme_name);
    if ($theme) {
        $theme->set('thm_is_stock', true);
        $theme->save();
        // NEW: Write back to manifest
        $theme_manager->writeManifestStockStatus($theme_name, true);
        $message = "Theme '$theme_name' marked as stock.";
    }
    break;

case 'mark_custom':
    $theme_name = $post['theme_name'];
    $theme = Theme::get_by_theme_name($theme_name);
    if ($theme) {
        // Block marking system themes as custom
        if ($theme->get('thm_is_system')) {
            $error = "Cannot mark system theme as custom. System themes must always receive updates.";
            break;
        }
        $theme->set('thm_is_stock', false);
        $theme->save();
        // NEW: Write back to manifest
        $theme_manager->writeManifestStockStatus($theme_name, false);
        $message = "Theme '$theme_name' marked as custom.";
    }
    break;
```

### Phase 7: System Theme Protection

**Files to modify:**
- `includes/ThemeManager.php`
- `adm/logic/admin_themes_logic.php`
- `adm/admin_themes.php`

**Modify loadMetadataIntoModel() in ThemeManager.php:**
```php
protected function loadMetadataIntoModel($model, $name) {
    $manifest_path = $this->getExtensionPath($name) . '/theme.json';
    if (!file_exists($manifest_path)) return;

    $metadata = json_decode(file_get_contents($manifest_path), true);
    if (json_last_error() === JSON_ERROR_NONE && $metadata) {
        $model->set('thm_metadata', json_encode($metadata));
        $model->set('thm_display_name', $metadata['name'] ?? $name);
        $model->set('thm_description', $metadata['description'] ?? '');
        $model->set('thm_version', $metadata['version'] ?? '1.0.0');
        $model->set('thm_author', $metadata['author'] ?? 'Unknown');
        $model->set('thm_is_stock', $metadata['is_stock'] ?? true);
        // NEW: Load system flag from manifest
        $model->set('thm_is_system', $metadata['system'] ?? false);
    }
}
```

**System theme identification:**
System themes are identified by `"system": true` in their installed theme.json manifest.
No separate lookup required - the flag is read during sync() and stored in the database.

**Admin UI protection in admin_themes.php:**
```php
// Build actions array - restrict actions for system themes
$actions = array();
$is_system = $theme->get('thm_is_system');

if (!$is_active && $files_exist) {
    $actions['Activate'] = "javascript:submitAction('activate', '$theme_name')";
}

// System themes cannot be marked as custom or deleted
if (!$is_system) {
    if ($is_stock) {
        $actions['Mark as Custom'] = "javascript:submitAction('mark_custom', '$theme_name')";
    } else {
        $actions['Mark as Stock'] = "javascript:submitAction('mark_stock', '$theme_name')";
    }

    if (!$files_exist || !$is_active) {
        $actions['Delete'] = "javascript:showDeleteModal('$theme_name', ...)";
    }
}

// Show system badge
if ($is_system) {
    $type_badge = '<span class="badge bg-primary"><i class="fas fa-lock"></i> System</span>';
}
```

### Phase 8: New Site Installation Support

**Files to modify:**
- `maintenance_scripts/install_tools/new_account.sh`

Theme installation for new sites is handled directly in new_account.sh using sparse checkout:

**Modify new_account.sh:**
```bash
# Add argument parsing for themes
INSTALL_THEMES=""
ACTIVATE_THEME=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --themes=*)
            INSTALL_THEMES="${1#*=}"
            shift
            ;;
        --activate=*)
            ACTIVATE_THEME="${1#*=}"
            shift
            ;;
        *)
            # Existing argument handling
            shift
            ;;
    esac
done

# ... existing site creation code ...

# Install themes using sparse checkout
install_themes_for_new_site() {
    local site="$1"
    local themes="$2"  # comma-separated list, or empty for default
    local activate="$3"

    local theme_dir="/var/www/html/$site/public_html/theme"
    local temp_dir=$(mktemp -d)
    local git_url="https://github.com/getjoinery/joinery.git"

    # Default: install falcon (system theme)
    local theme_list="${themes:-falcon}"

    echo "Installing themes: $theme_list"

    # Build sparse checkout paths
    local sparse_paths=""
    IFS=',' read -ra THEMES <<< "$theme_list"
    for theme in "${THEMES[@]}"; do
        theme=$(echo "$theme" | xargs)  # trim whitespace
        sparse_paths="$sparse_paths public_html/theme/$theme"
    done

    # Sparse checkout
    cd "$temp_dir"
    git clone --no-checkout --depth 1 --filter=blob:none "$git_url" . 2>/dev/null
    git sparse-checkout init --cone
    git sparse-checkout set $sparse_paths
    git checkout 2>/dev/null

    # Copy themes to site
    for theme in "${THEMES[@]}"; do
        theme=$(echo "$theme" | xargs)
        local source="$temp_dir/public_html/theme/$theme"
        if [[ -d "$source" ]]; then
            cp -r "$source" "$theme_dir/"
            echo "  Installed: $theme"
        else
            echo "  Warning: Theme not found in repository: $theme"
        fi
    done

    # Cleanup
    rm -rf "$temp_dir"

    # Determine which theme to activate
    local theme_to_activate="${activate:-falcon}"
    echo "Theme to activate: $theme_to_activate (will be set after database setup)"
}

# Call theme installation
mkdir -p "/var/www/html/$NEW_SITE/public_html/theme"
install_themes_for_new_site "$NEW_SITE" "$INSTALL_THEMES" "$ACTIVATE_THEME"

# Run database setup (creates theme records)
php /var/www/html/$NEW_SITE/public_html/utils/update_database.php

# Activate the theme (after database is ready)
if [[ -n "$ACTIVATE_THEME" ]]; then
    php -r "
        require_once '/var/www/html/$NEW_SITE/public_html/includes/PathHelper.php';
        require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
        ThemeManager::getInstance()->setActiveTheme('$ACTIVATE_THEME');
    "
else
    # Default: activate falcon
    php -r "
        require_once '/var/www/html/$NEW_SITE/public_html/includes/PathHelper.php';
        require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
        ThemeManager::getInstance()->setActiveTheme('falcon');
    "
fi
```

**Usage examples:**
```bash
# Default: install only falcon
./new_account.sh mysite

# Install falcon + bootstrap, activate bootstrap
./new_account.sh mysite --themes=falcon,bootstrap --activate=bootstrap

# Install multiple themes
./new_account.sh mysite --themes=falcon,tailwind,canvas
```

---

## Migration Strategy

### For Existing Sites

The migration is straightforward because we're simplifying, not adding complexity:

1. **First deployment with new scripts:**
   - Uses sparse checkout to fetch only installed themes
   - Themes already in `public_html/theme/` are updated (if stock) or preserved (if custom)
   - No new themes are added automatically

2. **Database cleanup:**
   - `theme_plugin_registry_sync` migration removes database records for themes not in `public_html/theme/`
   - This cleans up "ghost" records from previously deleted themes

3. **No user action required:**
   - Existing sites continue working
   - Installed themes update as before (if stock)
   - Custom themes preserved as before

### Backward Compatibility

| Scenario | Behavior |
|----------|----------|
| Old deploy.sh on updated site | Works, but downloads all themes (inefficient) |
| New deploy.sh on old site | Uses sparse checkout, updates installed themes only |
| Mixed upgrade/deploy | Safe - each operation is idempotent |

### New Site Setup

For `new_account.sh`:

```bash
# Default: install falcon only
./new_account.sh mysite

# With additional themes
./new_account.sh mysite --themes=falcon,bootstrap --activate=bootstrap
```

See Phase 8 for full implementation details.

---

## Summary of Changes by File

| File | Changes |
|------|---------|
| `data/themes_class.php` | Add `thm_is_system` field |
| `theme/falcon/theme.json` | Add `"system": true` flag |
| `maintenance_scripts/install_tools/deploy.sh` | Add sparse checkout, modify to only update existing themes |
| `maintenance_scripts/install_tools/new_account.sh` | Add `--themes` and `--activate` arguments, sparse checkout for theme installation |
| `includes/DeploymentHelper.php` | Refactor to `updateInstalledThemesOnly()` |
| `includes/ThemeManager.php` | Add `writeManifestStockStatus()`, `isInstalled()`, `deleteTheme()` |
| `utils/upgrade.php` | Modify to only update existing themes |
| `adm/admin_themes.php` | Add system theme badges/protection |
| `adm/logic/admin_themes_logic.php` | Fix `delete` to remove files, block system theme deletion/modification |
| `migrations/theme_plugin_registry_sync.php` | Cleanup orphaned database records, set `thm_is_system` from manifest |

### No New Repository Assets Needed

Since users install themes via ZIP upload, no `themes_manifest.json` is required. The repository structure stays the same - individual themes have their own `theme.json` files.

---

## Open Questions

1. **How to handle theme dependencies?**
   - If a component requires an uninstalled theme?
   - Recommendation: Show warning in component UI, don't prevent theme deletion

2. **Plugin handling?**
   - Should plugins follow the same sparse-fetch model?
   - Recommendation: Yes, apply same pattern to plugins for consistency

3. **Can there be multiple system themes?**
   - Currently assuming only one (falcon)
   - Recommendation: Allow multiple, all are protected from deletion

4. **What if user tries to activate a theme that requires an uninstalled dependency?**
   - e.g., child theme requires parent
   - Recommendation: Check dependencies on activate, show error message

5. **How to handle theme upgrades that change is_stock or system flags?**
   - Stock theme becomes system, or vice versa
   - Recommendation: Always trust manifest from repository, update database during sync

6. **Where do users get theme ZIP files?**
   - Recommendation: GitHub releases page, direct download links in documentation
   - Third-party themes: from theme author/marketplace

---

## Implementation Notes

### Delete Operation Must Be Defensive

The delete action should verify file deletion succeeded before removing the database record:

```php
case 'delete':
    $theme_name = $post['theme_name'];
    $theme = Theme::get_by_theme_name($theme_name);

    if (!$theme) {
        $error = "Theme not found.";
        break;
    }

    if ($theme->get('thm_is_system')) {
        $error = "Cannot delete system theme.";
        break;
    }

    if ($theme->get('thm_is_active')) {
        $error = "Cannot delete active theme. Switch to another theme first.";
        break;
    }

    // Delete files first
    $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
    if (is_dir($theme_path)) {
        exec("rm -rf " . escapeshellarg($theme_path), $output, $result);

        // Verify deletion succeeded
        if (is_dir($theme_path)) {
            $error = "Failed to delete theme files. Check file permissions.";
            break;
        }
    }

    // Only remove DB record after files are confirmed deleted
    $theme->permanent_delete();
    $message = "Theme '$theme_name' has been removed.";
    break;
```

### Manifest vs Database Conflict Resolution

When a theme's `is_stock` value differs between the database and manifest file, **the manifest wins** during sync. This ensures deployment behavior is predictable (manifest determines whether theme updates).

However, when a user changes the stock/custom status via Admin UI, the change is **written back to the manifest** immediately. This keeps them in sync and ensures the user's intent is preserved across deployments.

```php
// In admin_themes_logic.php - always write back to manifest
case 'mark_custom':
    $theme->set('thm_is_stock', false);
    $theme->save();
    $theme_manager->writeManifestStockStatus($theme_name, false);  // Keep in sync
    break;

case 'mark_stock':
    $theme->set('thm_is_stock', true);
    $theme->save();
    $theme_manager->writeManifestStockStatus($theme_name, true);   // Keep in sync
    break;
```

### Auto-Generated Manifests Include System Flag

When deploy.sh auto-generates a theme.json for themes that lack one, explicitly include the system flag:

```bash
if [[ ! -f "$manifest_file" ]]; then
    echo "Auto-generating theme.json for $theme_name"
    cat > "$manifest_file" << EOF
{
  "name": "$theme_name",
  "is_stock": true,
  "system": false
}
EOF
fi
```

This makes the theme's status explicit rather than relying on defaults.

---

## Appendix: Comparison Summary

### Before (Current)

```
Repository: 200 themes
     ↓
Deployment: Clone entire repo, install ALL 200 themes
     ↓
Site: 200 themes (whether wanted or not)
     ↓
User deletes 197 themes (but files stay!)
     ↓
Next deployment: 200 themes reinstalled
     ↓
User frustrated
```

### After (Proposed)

```
Repository: 200 themes
     ↓
Deployment: Sparse checkout ONLY installed themes (~3)
     ↓
Site: 3 themes (only what was installed)
     ↓
User wants new theme → Uploads ZIP via Admin UI
     ↓
User deletes theme → Files AND database removed, stays deleted
     ↓
Next deployment: Still 3 themes (or 2 if one deleted)
     ↓
User happy
```

### Key Differences

| Aspect | Before | After |
|--------|--------|-------|
| Disk usage per site | ~1GB (200 themes) | ~15MB (3 themes) |
| Deployment downloads | All 200 themes (~1GB) | Only installed themes (~15MB) |
| Delete behavior | DB only, files remain | Files + DB removed |
| New theme installation | Already there (unwanted) | User uploads ZIP |
| Theme persistence | Deleted themes return | Deleted themes stay deleted |

---

## Appendix: Code References

### Current Deployment Logic (deploy.sh:239-291)
```bash
deploy_themes_plugins_from_stage() {
    # Current: Processes ALL themes in staging
    for theme_dir in "$staging_dir/theme"/*; do
        # ...
        if [[ ! -d "$public_html_dir/theme/$theme_name" ]]; then
            echo "Adding new theme: $theme_name"  # ← PROBLEM: Auto-installs new themes
            cp -r "$theme_dir" "$public_html_dir/theme/"
        elif [[ "$is_stock" == "true" ]]; then
            echo "Updating stock theme: $theme_name"
            # ...
        fi
    done
}
```

### Proposed Deployment Logic
```bash
deploy_installed_themes_only() {
    # New: Only processes themes ALREADY in public_html
    for installed_theme in "$public_html_dir/theme"/*; do
        local theme_name=$(basename "$installed_theme")
        local staged_theme="$staging_dir/theme/$theme_name"

        if [[ -d "$staged_theme" ]]; then
            # Update from staging if stock
            if [[ "$is_stock" == "true" ]]; then
                rm -rf "$installed_theme"
                cp -r "$staged_theme" "$public_html_dir/theme/"
            fi
        fi
        # Themes NOT in public_html are ignored entirely
    done
}
```

### Current Delete Logic (admin_themes_logic.php:98-112)
```php
case 'delete':
    // Current: Only removes database record
    $theme->permanent_delete();
    $message = "Theme deleted from database.";
    // Files remain! Theme returns on next deployment!
```

### Proposed Delete Logic
```php
case 'delete':
    // Block system themes
    if ($theme->get('thm_is_system')) {
        $error = "Cannot delete system theme.";
        break;
    }

    // Remove files AND database record
    $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
    if (is_dir($theme_path)) {
        exec("rm -rf " . escapeshellarg($theme_path));
    }
    $theme->permanent_delete();
    $message = "Theme completely removed.";
    // Theme stays deleted because deployment won't reinstall it
```

### Sparse Checkout Logic (used by deploy.sh and new_account.sh)
```bash
# Fetch only specific themes from repository
sparse_checkout_themes() {
    local temp_dir="$1"
    local theme_list="$2"  # space-separated: "falcon bootstrap material"

    git clone --no-checkout --depth 1 --filter=blob:none "$GIT_URL" "$temp_dir"
    cd "$temp_dir"
    git sparse-checkout init --cone

    # Build paths for each theme
    local paths=""
    for theme in $theme_list; do
        paths="$paths public_html/theme/$theme"
    done

    git sparse-checkout set $paths
    git checkout
}
```

This approach:
- Downloads only the themes needed (~5MB per theme vs ~1GB for all)
- Works for both deployment (update existing) and new site setup (initial install)
- Requires git on the server (standard for most deployments)
