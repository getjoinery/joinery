# Theme and Plugin Management System Upgrade
**Version:** 1.1 - Phase 1 Complete

## Directory Structure Changes

**Remove (will be deleted by deploy script if present):**
- `/var/www/html/[sitename]/theme/`
- `/var/www/html/[sitename]/plugins/`
- `/var/www/html/[sitename]/theme_stage/`
- `/var/www/html/[sitename]/plugins_stage/`

**Keep Only:**
- `/var/www/html/[sitename]/public_html/theme/`
- `/var/www/html/[sitename]/public_html/plugins/`

## Phase 1: Deploy Script & Manifests

**Deploy Script Location:** `/home/user1/joinery/joinery/maintenance_scripts/deploy.sh`

### 1.1 Manifest Files

**theme/[name]/theme.json:**
```json
{
  "name": "Theme Name",
  "version": "1.0.0",
  "description": "Theme description",
  "author": "Author Name",
  "is_stock": true
}
```

**plugins/[name]/plugin.json (add is_stock field):**
```json
{
  "name": "plugin_name",
  "version": "1.0.0",
  "description": "Plugin description",
  "author": "Author Name",
  "is_stock": true
}
```

### 1.2 Prerequisites

**JQ (JSON Query tool):**
The deployment script uses `jq` for parsing JSON manifest files. JQ is already installed on the server (version 1.7) at `/usr/bin/jq`. 

JQ is a lightweight command-line JSON processor that allows the script to:
- Parse theme.json and plugin.json files
- Extract the `is_stock` field value
- Handle missing fields with default values

No action needed - JQ is already available.

### 1.3 Deploy Script Changes

**✅ COMPLETED - Remove functions:**
- ✅ `deploy_theme_plugin()` - Removed lines 151-239
- ✅ `merge_themes_plugins_to_public_html()` - Removed lines 242-330

**✅ COMPLETED - Remove variables:**
- ✅ `IS_THEME_ONLY` - Removed variable declaration and all references
- ✅ All `--theme-only` flag handling - Removed from usage, argument parsing, and logic

**✅ COMPLETED - Add directory cleanup (added around line 330):**
```bash
# Remove old theme/plugin directories if they exist
if [[ -d "/var/www/html/$TARGET_SITE/theme" ]]; then
    echo "Removing old theme directory: /var/www/html/$TARGET_SITE/theme"
    rm -rf "/var/www/html/$TARGET_SITE/theme"
fi
if [[ -d "/var/www/html/$TARGET_SITE/plugins" ]]; then
    echo "Removing old plugins directory: /var/www/html/$TARGET_SITE/plugins"
    rm -rf "/var/www/html/$TARGET_SITE/plugins"
fi
if [[ -d "/var/www/html/$TARGET_SITE/theme_stage" ]]; then
    echo "Removing old theme_stage directory: /var/www/html/$TARGET_SITE/theme_stage"
    rm -rf "/var/www/html/$TARGET_SITE/theme_stage"
fi
if [[ -d "/var/www/html/$TARGET_SITE/plugins_stage" ]]; then
    echo "Removing old plugins_stage directory: /var/www/html/$TARGET_SITE/plugins_stage"
    rm -rf "/var/www/html/$TARGET_SITE/plugins_stage"
fi
```

**✅ COMPLETED - Replace theme/plugin deployment (added around line 399, replacing merge_themes_plugins_to_public_html call):**
```bash
# Smart theme merge: update stock items, skip custom items
if [[ -d "public_html_stage/theme" ]]; then
    mkdir -p public_html/theme || {
        echo "ERROR: Failed to create public_html/theme directory"
        exit 1
    }
    
    for theme_dir in public_html_stage/theme/*/; do
        if [[ -d "$theme_dir" ]]; then
            theme_name=$(basename "$theme_dir")
            manifest_file="$theme_dir/theme.json"
            
            # Auto-generate manifest if missing
            if [[ ! -f "$manifest_file" ]]; then
                echo "Auto-generating theme.json for $theme_name"
                cat > "$manifest_file" << EOF
{
  "name": "$theme_name",
  "version": "1.0.0",
  "description": "Auto-generated manifest for $theme_name theme",
  "author": "Unknown",
  "is_stock": true
}
EOF
            fi
            
            # Check if theme is stock by reading manifest
            if [[ -f "$manifest_file" ]]; then
                is_stock=$(jq -r '.is_stock // true' "$manifest_file" 2>/dev/null || echo "true")
            else
                # Fallback if jq fails
                is_stock="true"
            fi
            
            if [[ ! -d "public_html/theme/$theme_name" ]]; then
                echo "Adding new theme: $theme_name (stock: $is_stock)"
                cp -r "$theme_dir" "public_html/theme/" || {
                    echo "ERROR: Failed to copy theme $theme_name"
                    exit 1
                }
            elif [[ "$is_stock" == "true" ]]; then
                echo "Updating stock theme: $theme_name"
                rm -rf "public_html/theme/$theme_name" || {
                    echo "ERROR: Failed to remove old theme $theme_name"
                    exit 1
                }
                cp -r "$theme_dir" "public_html/theme/" || {
                    echo "ERROR: Failed to copy theme $theme_name"
                    exit 1
                }
            else
                echo "Skipping custom theme: $theme_name (use admin interface to upgrade)"
            fi
        fi
    done
fi

# Smart plugin merge: update stock items, skip custom items
if [[ -d "public_html_stage/plugins" ]]; then
    mkdir -p public_html/plugins || {
        echo "ERROR: Failed to create public_html/plugins directory"
        exit 1
    }
    
    for plugin_dir in public_html_stage/plugins/*/; do
        if [[ -d "$plugin_dir" ]]; then
            plugin_name=$(basename "$plugin_dir")
            manifest_file="$plugin_dir/plugin.json"
            
            # Auto-generate manifest if missing
            if [[ ! -f "$manifest_file" ]]; then
                echo "Auto-generating plugin.json for $plugin_name"
                cat > "$manifest_file" << EOF
{
  "name": "$plugin_name",
  "version": "1.0.0",
  "description": "Auto-generated manifest for $plugin_name plugin",
  "author": "Unknown",
  "is_stock": true
}
EOF
            fi
            
            # Check if plugin is stock by reading manifest
            if [[ -f "$manifest_file" ]]; then
                is_stock=$(jq -r '.is_stock // true' "$manifest_file" 2>/dev/null || echo "true")
            else
                # Fallback if jq fails
                is_stock="true"
            fi
            
            if [[ ! -d "public_html/plugins/$plugin_name" ]]; then
                echo "Adding new plugin: $plugin_name (stock: $is_stock)"
                cp -r "$plugin_dir" "public_html/plugins/" || {
                    echo "ERROR: Failed to copy plugin $plugin_name"
                    exit 1
                }
            elif [[ "$is_stock" == "true" ]]; then
                echo "Updating stock plugin: $plugin_name"
                rm -rf "public_html/plugins/$plugin_name" || {
                    echo "ERROR: Failed to remove old plugin $plugin_name"
                    exit 1
                }
                cp -r "$plugin_dir" "public_html/plugins/" || {
                    echo "ERROR: Failed to copy plugin $plugin_name"
                    exit 1
                }
            else
                echo "Skipping custom plugin: $plugin_name (use admin interface to upgrade)"
            fi
        fi
    done
fi
```

### 1.4 First Deployment Transition Logic

**Expected behavior on first deploy:**
- Existing themes without theme.json → Auto-generates manifest with `is_stock: true`, will be updated
- Existing plugins without plugin.json → Auto-generates manifest with `is_stock: true`, will be updated  
- After first deploy, all items have proper manifests with `is_stock` field

**How public_html_stage is populated:**
The deploy script creates `public_html_stage` at line ~562-569:
1. Removes old `public_html_stage` if it exists
2. Creates fresh `public_html_stage` directory
3. Clones the git repository into it
4. Pulls specific folders from the repository

**Error handling:**
- All critical operations include error checking with `|| { echo "ERROR:..."; exit 1; }`
- Deploy script already has rollback functionality for failed deployments
- Failed deployments preserve staging directory for debugging

## ✅ PHASE 1 IMPLEMENTATION STATUS

**✅ COMPLETED on August 29, 2024:**

### Files Modified:
- **deploy.sh** - `/home/user1/joinery/joinery/maintenance_scripts/deploy.sh`
  - **Backup created:** `deploy.sh.bak` in same directory
  - **Lines reduced:** From 799 lines to 675 lines (removed 181 lines of old functions, added 57 lines of new logic)

### Changes Made:
1. ✅ **Removed deprecated functions** (lines 151-330 in original):
   - `deploy_theme_plugin()` function completely removed
   - `merge_themes_plugins_to_public_html()` function completely removed

2. ✅ **Removed IS_THEME_ONLY functionality**:
   - Removed `IS_THEME_ONLY` variable declaration
   - Removed `--theme-only` flag from argument parsing
   - Removed all `--theme-only` references from usage/help text
   - Removed all conditional logic based on `IS_THEME_ONLY`

3. ✅ **Added directory cleanup** (line 330):
   - Removes old `/theme/`, `/plugins/`, `/theme_stage/`, `/plugins_stage/` directories
   - Added before main staging directory creation

4. ✅ **Added smart theme/plugin deployment** (lines 399-511):
   - Auto-generates `theme.json`/`plugin.json` manifests if missing
   - Uses JQ to parse `is_stock` field (defaults to true)
   - Updates stock themes/plugins, skips custom ones
   - Includes comprehensive error handling with exit codes
   - Uses full absolute paths throughout

### Verification:
- ✅ **Syntax check passed:** `bash -n deploy.sh` returns clean
- ✅ **No deprecated references remain:** Grep confirms no IS_THEME_ONLY, --theme-only, or function calls
- ✅ **New functionality present:** Smart merge logic and directory cleanup confirmed

### Post-Implementation Improvements Made:
**✅ ADDITIONAL IMPROVEMENTS - August 29, 2024:**

1. **✅ Refactored to self-contained function:**
   - Created `deploy_themes_plugins_from_stage()` function (lines 129-277)
   - Eliminated path context dependency - fully self-contained with absolute paths
   - Improved JQ output validation (ensures true/false values)

2. **✅ Integrated with rollback system:**
   - Theme/plugin deployment failures now trigger rollback (lines 551-572)
   - Respects `DISABLE_ROLLBACK` flag like other deployment operations
   - Handles initial deployment case (no backup available)

3. **✅ Added directory validation:**
   - Validates staging directory exists before processing
   - Clear messaging when theme/plugin directories not found
   - Handles missing theme or plugin directories gracefully

## Phase 1 Status: ✅ COMPLETED

**Implementation Completed:** Phase 1 theme/plugin deployment system has been successfully implemented and deployed.

### ✅ Completed Features:

1. **Enhanced Deploy Script (v2.5):**
   - ✅ Automatic rollback system using bash traps
   - ✅ Two-repository deployment (main code + themes/plugins)
   - ✅ Auto-manifest generation for missing theme.json/plugin.json files
   - ✅ Stock/custom theme logic with jq validation
   - ✅ Database schema updates with --upgrade flag
   - ✅ Comprehensive error handling with debugging preservation

2. **Deploy Script Architecture:**
   - ✅ `deploy_theme_plugin()` - Downloads from `getjoinery/joinery` repository
   - ✅ `merge_themes_plugins_to_public_html()` - Merges into public_html directories
   - ✅ Trap-based automatic rollback on any failure
   - ✅ `DEPLOYMENT_SUCCESS/DEPLOYMENT_STARTED` state tracking
   - ✅ Removed 200+ lines of duplicate rollback logic

3. **Database Integration:**
   - ✅ Fixed migration system type conversion (`mig_version` int4 → numeric(6,2))
   - ✅ Migrations run after database schema updates
   - ✅ Proper error handling for database operations

### 🔧 Key Improvements Made:

**Automatic Rollback System:**
```bash
# OLD: 20+ manual rollback calls that could be bypassed
if ! some_operation; then
    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo "ROLLBACK DISABLED..."
        exit 1  # NO ROLLBACK!
    fi
    perform_rollback "$TARGET_SITE"
    exit 1
fi

# NEW: Trap-based automatic rollback (can't be bypassed)
trap cleanup_and_rollback EXIT
if ! some_operation; then
    exit 1  # Trap handles rollback automatically
fi
```

**Theme Repository Integration:**
- Themes are now pulled from `getjoinery/joinery` repository (not main code repo)
- Two-step deployment: external download → merge into public_html
- Maintains backward compatibility with existing sites

### 🚀 Deployment Results:

**Before Phase 1:**
- Manual rollback logic in 20+ locations
- Themes missing from deployments (empty directories)
- Database migration failures due to schema mismatch
- Rollback frequently bypassed on failures

**After Phase 1:**
- ✅ **Reliable automatic rollback** on ALL failure modes
- ✅ **Working theme deployment** from correct repository
- ✅ **Fixed database migrations** with proper schema updates
- ✅ **Simplified maintenance** with 200+ fewer lines of rollback code

## Phase 2: Admin Interface (Not Yet Started)

Phase 2 implementation (Admin Interface for theme/plugin management) has been extracted to a separate specification document: `theme_plugins_upgrade_phase2.md`

## Notes

**Stock/Custom Logic:**
- Items with `"is_stock": true` in manifests are overwritten on deploy
- Items with `"is_stock": false` or missing manifests are preserved
- Admin interface allows toggling stock/custom status

**Breaking Changes:**
- `--theme-only` flag removed
- External directories removed (`/var/www/html/[site]/theme/`, `/var/www/html/[site]/plugins/`)