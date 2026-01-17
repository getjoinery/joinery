#!/usr/bin/env bash
#version 3.11 - Centralized permissions to fix_permissions.sh
# MODIFIED v3.8: Renamed "maintenance scripts" to "maintenance_scripts" (underscore instead of space)
# MODIFIED v3.8: Updated git sparse-checkout to use "maintenance_scripts"
# MODIFIED v3.8: Updated all path references to use underscore notation
# MODIFIED v3.7: Theme and plugins now in public_html/ in repository (not root)
# MODIFIED v3.7: Removed separate theme/plugin sparse checkout (now part of public_html checkout)
# MODIFIED v3.7: Renamed deploy_theme_plugin() to deploy_maintenance_scripts()
# MODIFIED v3.7: Simplified deployment - repository structure matches deployment structure
# MODIFIED: Added comprehensive PHP syntax validation during deployment
# MODIFIED: Added plugin loading test with proper PathHelper context
# MODIFIED: Added basic runtime bootstrap test
# MODIFIED: Added manual rollback capability with optional directory selection
# MODIFIED: Preserve staging directory on deployment failures for debugging
# MODIFIED: Added --norollback flag to disable rollback functionality
# MODIFIED: Improved rollback functionality to handle directory conflicts
# MODIFIED: Added trap-based automatic rollback system
# MODIFIED v3.51: Fixed atomic backup using cp instead of mv with glob to preserve all files including hidden
# MODIFIED v3.51: Removed dangerous || true that masked backup failures
# MODIFIED v3.51: Replaced PHP/PathHelper-dependent rollback with pure bash implementation for reliability
# MODIFIED v3.51: Added backup verification step before proceeding with deployment
# MODIFIED v3.51: Automatic cache directory migration from public_html to site root with failure handling
# MODIFIED v3.51: Removed blocking .htaccess creation in backup/failed directories (caused rollback access issues)

# Deploy script version
DEPLOY_VERSION="3.11"

# Helper function for verbose output
verbose_echo() {
    if [ "$VERBOSE" = true ]; then
        echo "$@"
    fi
}

# Calculate deployment hash for comparing sites
calculate_deployment_hash() {
    local site_path="$1"
    find "$site_path" -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) -print0 2>/dev/null | sort -z | xargs -0 cat 2>/dev/null | md5sum | cut -d' ' -f1
}

# Simple JSON value extractor (no jq dependency)
get_json_value() {
    local json_file="$1"
    local key="$2"
    local default_value="$3"
    
    if [[ ! -f "$json_file" ]]; then
        echo "$default_value"
        return
    fi
    
    # Extract value using sed and grep (handles quoted strings)
    local value=$(grep -o "\"$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$json_file" 2>/dev/null | sed "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/")
    
    # Handle boolean values
    if [[ -z "$value" ]]; then
        value=$(grep -o "\"$key\"[[:space:]]*:[[:space:]]*[a-z]*" "$json_file" 2>/dev/null | sed "s/.*\"$key\"[[:space:]]*:[[:space:]]*\([a-z]*\).*/\1/")
    fi
    
    if [[ -n "$value" ]]; then
        echo "$value"
    else
        echo "$default_value"
    fi
}

# Global deployment state
DEPLOYMENT_SUCCESS=false
DEPLOYMENT_STARTED=false
DISABLE_ROLLBACK=false
TARGET_SITE=""

# Cleanup function that runs on script exit
cleanup_and_rollback() {
    local exit_code=$?
    
    # Don't rollback if deployment succeeded or if rollback is disabled
    if [ "$DEPLOYMENT_SUCCESS" = true ] || [ "$DISABLE_ROLLBACK" = true ]; then
        if [ "$DEPLOYMENT_SUCCESS" = true ]; then
            echo "========================================="
            echo "SUCCESS: Full deployment to live site '$TARGET_SITE' complete!"
            echo "Permissions have been fixed automatically."
            echo "========================================="
        fi
        exit $exit_code
    fi
    
    # Don't rollback if deployment never started (early validation failures)
    if [ "$DEPLOYMENT_STARTED" = false ]; then
        echo "Deployment failed before starting. No rollback needed."
        exit $exit_code
    fi
    
    # Only rollback if we have a target site and deployment started
    if [ -n "$TARGET_SITE" ] && [ "$DEPLOYMENT_STARTED" = true ]; then
        echo ""
        echo "========================================="
        echo "DEPLOYMENT FAILED - AUTOMATIC ROLLBACK"
        echo "========================================="
        echo "Attempting automatic rollback for site: $TARGET_SITE"

        # Pure bash rollback - no PHP dependencies
        local site_root="/var/www/html/$TARGET_SITE"
        local public_html_dir="$site_root/public_html"
        local backup_dir="$site_root/public_html_last"
        local failed_dir="$site_root/public_html_failed_$(date +%Y%m%d_%H%M%S)"

        # Check if backup exists
        if [[ ! -d "$backup_dir" ]] || [[ -z "$(ls -A "$backup_dir" 2>/dev/null)" ]]; then
            echo "✗ Automatic rollback failed: No backup found at $backup_dir"
            echo "  This may be an initial deployment with no previous version."
            echo "  Manual intervention required."
            echo "  Current deployment preserved at: $public_html_dir"
        else
            # Preserve failed deployment for debugging
            echo "Preserving failed deployment at: $failed_dir"
            if [[ -d "$public_html_dir" ]] && [[ -n "$(ls -A "$public_html_dir" 2>/dev/null)" ]]; then
                if mv "$public_html_dir" "$failed_dir" 2>/dev/null; then
                    verbose_echo "Failed deployment moved to $failed_dir"
                else
                    echo "Warning: Could not move failed deployment (will remove instead)"
                    rm -rf "$public_html_dir"
                fi
            fi

            # Recreate public_html directory
            mkdir -p "$public_html_dir"

            # Restore from backup
            echo "Restoring from backup: $backup_dir"
            if cp -a "$backup_dir/." "$public_html_dir/"; then
                # Fix permissions after rollback using centralized script
                fix_permissions "$TARGET_SITE" --production

                echo "✓ Automatic rollback completed successfully."
                if [[ -d "$failed_dir" ]]; then
                    echo "  Failed deployment preserved at: $failed_dir"
                fi
                echo "  Site restored to previous working version."
            else
                echo "✗ Automatic rollback failed: Could not restore from backup"
                echo "  Manual intervention required."
                if [[ -d "$failed_dir" ]]; then
                    echo "  Failed deployment at: $failed_dir"
                fi
            fi
        fi
    fi
    
    exit $exit_code
}

# Set up trap to run cleanup on script exit
trap cleanup_and_rollback EXIT

GITHUB_USER="getjoinery"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/getjoinery/joinery.git"

# Theme/Plugin repository is same as main repo now (single repository)
THEME_PLUGIN_REPO_URL="$REPO_URL"

# Function to fix permissions - calls centralized fix_permissions.sh script
fix_permissions() {
    local target_site="$1"
    local mode="${2:---production}"  # Default to production mode

    # Get the directory where this script is located
    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local fix_script="$script_dir/fix_permissions.sh"

    if [ "$VERBOSE" = true ]; then
        echo "Fixing permissions for $target_site (mode: $mode)..."
    fi

    # Check if fix_permissions.sh exists
    if [[ ! -f "$fix_script" ]]; then
        echo "ERROR: fix_permissions.sh not found at $fix_script"
        return 1
    fi

    # Call the centralized script
    if [ "$EUID" -eq 0 ]; then
        # Running as root, call directly
        "$fix_script" "$target_site" "$mode"
    else
        # Not root, warn and attempt anyway
        if [ "$VERBOSE" = true ]; then
            echo "WARNING: Not running as root/sudo. Permission changes may fail."
            echo "For best results, run this script with: sudo $0 $*"
        fi
        "$fix_script" "$target_site" "$mode" 2>/dev/null || {
            echo "  Permissions update failed (try running with sudo)"
            return 1
        }
    fi
}

# Function to handle test failures with --norollback support
handle_test_failure() {
    local error_message="$1"
    local staging_dir="$2"

    echo "ERROR: $error_message"
    echo "DEBUGGING: Staging directory preserved at: $staging_dir"

    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo ""
        echo "--norollback flag detected: Proceeding with deployment despite test failures."
        echo "WARNING: Tests failed but deployment will continue. Manual intervention may be required."
        echo ""
        return 0  # Continue with deployment
    else
        exit 1    # Exit normally if rollback is enabled
    fi
}

# Function to deploy themes and plugins from staging to public_html
deploy_themes_plugins_from_stage() {
    local target_site="$1"
    local staging_dir="/var/www/html/$target_site/public_html_stage"
    local public_html_dir="/var/www/html/$target_site/public_html"
    
    echo "Deploying themes and plugins from staging to public_html..."
    
    # Validate that staging directory exists
    if [[ ! -d "$staging_dir" ]]; then
        echo "ERROR: Staging directory not found: $staging_dir"
        return 1
    fi
    
    # Ensure theme directory exists
    mkdir -p "$public_html_dir/theme" || {
        echo "ERROR: Failed to create public_html/theme directory"
        return 1
    }
    
    # Check for theme directory and deploy if present
    if [[ -d "$staging_dir/theme" ]]; then
        echo "Found themes in staging, processing..."
        
        for theme_dir in "$staging_dir/theme"/*; do
            if [[ -d "$theme_dir" ]]; then
                local theme_name=$(basename "$theme_dir")
                local manifest_file="$theme_dir/theme.json"
                
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
                local is_stock
                if [[ -f "$manifest_file" ]]; then
                    is_stock=$(get_json_value "$manifest_file" "is_stock" "true")
                else
                    # Fallback if jq fails
                    is_stock="true"
                fi
                
                if [[ ! -d "$public_html_dir/theme/$theme_name" ]]; then
                    echo "Adding new theme: $theme_name (stock: $is_stock)"
                    cp -r "$theme_dir" "$public_html_dir/theme/" || {
                        echo "ERROR: Failed to copy theme $theme_name"
                        return 1
                    }
                elif [[ "$is_stock" == "true" ]]; then
                    echo "Updating stock theme: $theme_name"
                    rm -rf "$public_html_dir/theme/$theme_name" || {
                        echo "ERROR: Failed to remove old theme $theme_name"
                        return 1
                    }
                    cp -r "$theme_dir" "$public_html_dir/theme/" || {
                        echo "ERROR: Failed to copy theme $theme_name"
                        return 1
                    }
                else
                    echo "Skipping custom theme: $theme_name (use admin interface to upgrade)"
                fi
            fi
        done
        echo "Theme deployment completed."
    else
        echo "No themes found in staging directory."
    fi
    
    # Ensure plugins directory exists
    mkdir -p "$public_html_dir/plugins" || {
        echo "ERROR: Failed to create public_html/plugins directory"
        return 1
    }
    
    # Check for plugin directory and deploy if present  
    if [[ -d "$staging_dir/plugins" ]]; then
        echo "Found plugins in staging, processing..."
        
        for plugin_dir in "$staging_dir/plugins"/*; do
            if [[ -d "$plugin_dir" ]]; then
                local plugin_name=$(basename "$plugin_dir")
                local manifest_file="$plugin_dir/plugin.json"
                
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
                local is_stock
                if [[ -f "$manifest_file" ]]; then
                    is_stock=$(get_json_value "$manifest_file" "is_stock" "true")
                else
                    # Fallback if jq fails
                    is_stock="true"
                fi
                
                if [[ ! -d "$public_html_dir/plugins/$plugin_name" ]]; then
                    echo "Adding new plugin: $plugin_name (stock: $is_stock)"
                    cp -r "$plugin_dir" "$public_html_dir/plugins/" || {
                        echo "ERROR: Failed to copy plugin $plugin_name"
                        return 1
                    }
                elif [[ "$is_stock" == "true" ]]; then
                    echo "Updating stock plugin: $plugin_name"
                    rm -rf "$public_html_dir/plugins/$plugin_name" || {
                        echo "ERROR: Failed to remove old plugin $plugin_name"
                        return 1
                    }
                    cp -r "$plugin_dir" "$public_html_dir/plugins/" || {
                        echo "ERROR: Failed to copy plugin $plugin_name"
                        return 1
                    }
                else
                    echo "Skipping custom plugin: $plugin_name (use admin interface to upgrade)"
                fi
            fi
        done
        echo "Plugin deployment completed."
    else
        echo "No plugins found in staging directory."
    fi
    
    echo "Theme and plugin deployment from staging completed successfully."
    return 0
}

# Function to deploy maintenance scripts from repository
# NOTE: Themes and plugins are now in public_html/ in repository and come automatically with main checkout
deploy_maintenance_scripts() {
    local target_site="$1"
    local site_root="/var/www/html/$target_site"

    echo "Downloading maintenance scripts from joinery repository to $target_site..."

    # DEPLOY MAINTENANCE_SCRIPTS to /var/www/html/sitename/maintenance_scripts
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
    git sparse-checkout set "maintenance_scripts"
    if [ "$VERBOSE" = true ]; then
        git checkout main
    else
        git checkout --quiet main 2>/dev/null
    fi
    rm -rf .git
    cd - > /dev/null

    # Deploy maintenance_scripts directly to site root
    if [[ -d "$maintenance_stage_dir/maintenance_scripts" ]]; then
        verbose_echo "Deploying maintenance scripts..."

        # Remove old maintenance_scripts if exists
        rm -rf "$site_root/maintenance_scripts"

        # Move to site root
        mv "$maintenance_stage_dir/maintenance_scripts" "$site_root/maintenance_scripts" || {
            echo "ERROR: Failed to deploy maintenance scripts"
            return 1
        }

        # Make scripts executable
        chmod +x "$site_root/maintenance_scripts"/*.sh 2>/dev/null || true

        verbose_echo "Maintenance scripts deployed successfully"
    else
        echo "WARNING: No maintenance scripts directory found in joinery repository"
        # Not a fatal error - deployment can continue without maintenance scripts
    fi

    # Cleanup staging directory
    verbose_echo "Cleaning up maintenance staging directory..."
    rm -rf "$maintenance_stage_dir"

    echo "Maintenance scripts download from joinery repository complete."
    return 0
}

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Full deploy to live site"
    echo "  $0 [site_name] --test             # Full deploy to test site (site_name_test)"
    echo "  $0 [site_name] --fast             # Fast deploy (skips plugin/bootstrap/model tests)"
    echo "  $0 [site_name] --fix-permissions  # Fix permissions only (no deployment)"
    echo "  $0 [site_name] --rollback [dir]   # Manual rollback to last backup or specified directory"
    echo "  $0 [site_name] --norollback       # Disable rollback on deployment failure"
    echo "  $0 [site_name] --verbose          # Show detailed output (git clones, individual files, etc.)"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Full deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Full deploy to getjoinery_test"
    echo "  $0 getjoinery --fast              # Fast deploy (PHP syntax check only)"
    echo "  $0 getjoinery --fix-permissions   # Fix permissions on getjoinery"
    echo "  $0 getjoinery --rollback          # Rollback to public_html_last"
    echo "  $0 getjoinery --rollback public_html_failed_20240907_143000  # Rollback to specific failed deployment"
    echo "  $0 getjoinery --test --norollback # Full deploy to test site without rollback"
    echo ""
    echo "Note: Permission fixes work best when run with sudo"
    echo "      --fast skips plugin loading, bootstrap, and model tests (keeps PHP syntax validation)"
    echo "Test site will be available at: https://test.[domain].com"
}


# Parse arguments
if [ "$1" == "" ] || [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_usage
    exit 1
fi

LIVE_SITE="$1"
IS_TEST_DEPLOY=false
IS_FIX_PERMISSIONS_ONLY=false
IS_MANUAL_ROLLBACK=false
DISABLE_ROLLBACK=false
ROLLBACK_SOURCE_DIR=""
VERBOSE=false
FAST_MODE=false

# Parse arguments with better handling for rollback directory parameter
i=1
while [ $i -le $# ]; do
    arg="${!i}"
    case $arg in
        --test)
            IS_TEST_DEPLOY=true
            ;;
        --fix-permissions)
            IS_FIX_PERMISSIONS_ONLY=true
            ;;
        --rollback)
            IS_MANUAL_ROLLBACK=true
            # Check if next argument is a directory name (not another flag)
            next_i=$((i + 1))
            if [ $next_i -le $# ]; then
                next_arg="${!next_i}"
                if [[ ! "$next_arg" =~ ^-- ]]; then
                    ROLLBACK_SOURCE_DIR="$next_arg"
                    i=$next_i  # Skip the next argument since we consumed it
                fi
            fi
            ;;
        --norollback)
            DISABLE_ROLLBACK=true
            ;;
        --verbose|-v)
            VERBOSE=true
            ;;
        --fast)
            FAST_MODE=true
            ;;
    esac
    i=$((i + 1))
done

# Set target site and deploy type
if [ "$IS_TEST_DEPLOY" = true ]; then
    TARGET_SITE="${LIVE_SITE}_test"
    if [ "$IS_FIX_PERMISSIONS_ONLY" = true ]; then
        DEPLOY_TYPE="PERMISSIONS-ONLY (TEST)"
    else
        DEPLOY_TYPE="FULL (TEST)"
    fi
else
    TARGET_SITE="$LIVE_SITE"
    if [ "$IS_FIX_PERMISSIONS_ONLY" = true ]; then
        DEPLOY_TYPE="PERMISSIONS-ONLY (LIVE)"
    else
        DEPLOY_TYPE="FULL (LIVE)"
    fi
fi

# Add rollback status to deploy type if disabled
if [ "$DISABLE_ROLLBACK" = true ]; then
    DEPLOY_TYPE="$DEPLOY_TYPE - ROLLBACK DISABLED"
fi

# Add fast mode status to deploy type
if [ "$FAST_MODE" = true ]; then
    DEPLOY_TYPE="$DEPLOY_TYPE - FAST MODE"
fi

# SAFETY CHECK: Ensure live site exists (needed for both live and test deploys)
if [[ ! -d "/var/www/html/$LIVE_SITE" ]]; then
    echo "ERROR: Live site directory '/var/www/html/$LIVE_SITE' does not exist."
    echo "Please verify the site name is correct."
    exit 1
fi

# SAFETY CHECK: Prevent accidental test deploy to live site name
if [ "$IS_TEST_DEPLOY" = true ] && [ "$LIVE_SITE" = "$TARGET_SITE" ]; then
    echo "ERROR: Safety check failed. Cannot deploy test to same name as live site."
    exit 1
fi

# SAFETY CHECK: Ensure target site exists for permissions-only operations  
if [ "$IS_FIX_PERMISSIONS_ONLY" = true ] && [[ ! -d "/var/www/html/$TARGET_SITE" ]]; then
    echo "ERROR: Target site directory '/var/www/html/$TARGET_SITE' does not exist."
    echo "Please verify the site name is correct."
    exit 1
fi

# IF PERMISSIONS-ONLY, HANDLE AND EXIT
if [ "$IS_FIX_PERMISSIONS_ONLY" = true ]; then
    echo "========================================="
    echo "PERMISSIONS FIX:"
    echo "Target site: $TARGET_SITE"
    echo "========================================="
    echo "This will fix permissions for: /var/www/html/$TARGET_SITE"
    echo "Owner: www-data, Group: user1, Permissions: 770 (777 for uploads)"
    echo "========================================="
    read -p "Continue with permission fix? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Permission fix cancelled."
        exit 0
    fi
    
    fix_permissions "$TARGET_SITE"
    
    echo "========================================="
    echo "SUCCESS: Permissions fixed for '$TARGET_SITE'!"
    echo "========================================="
    exit 0
fi

# IF MANUAL ROLLBACK, HANDLE AND EXIT
if [ "$IS_MANUAL_ROLLBACK" = true ]; then
    echo "========================================="
    echo "MANUAL ROLLBACK:"
    echo "Target site: $TARGET_SITE"
    echo "========================================="
    
    # Determine source directory for rollback
    site_root="/var/www/html/$TARGET_SITE"
    public_html_dir="$site_root/public_html"
    source_dir=""
    
    if [ -n "$ROLLBACK_SOURCE_DIR" ]; then
        # User specified a directory
        if [[ "$ROLLBACK_SOURCE_DIR" =~ ^/ ]]; then
            # Absolute path provided
            source_dir="$ROLLBACK_SOURCE_DIR"
        else
            # Relative path - assume it's under the site root
            source_dir="$site_root/$ROLLBACK_SOURCE_DIR"
        fi
        
        if [[ ! -d "$source_dir" ]]; then
            echo "ERROR: Specified rollback directory does not exist: $source_dir"
            exit 1
        fi
        
        if [[ -z "$(ls -A "$source_dir" 2>/dev/null)" ]]; then
            echo "ERROR: Specified rollback directory is empty: $source_dir"
            exit 1
        fi
        
        echo "Rollback source: $source_dir"
    else
        # Default to public_html_last
        source_dir="$site_root/public_html_last"
        
        if [[ ! -d "$source_dir" ]] || [[ -z "$(ls -A "$source_dir" 2>/dev/null)" ]]; then
            echo "ERROR: Default backup directory not found or empty: $source_dir"
            echo ""
            echo "Available rollback options:"
            for dir in "$site_root"/public_html_failed_* "$site_root/public_html_last"; do
                if [[ -d "$dir" ]] && [[ -n "$(ls -A "$dir" 2>/dev/null)" ]]; then
                    echo "  $(basename "$dir")"
                fi
            done
            echo ""
            echo "Usage: $0 $LIVE_SITE --rollback [directory_name]"
            exit 1
        fi
        
        echo "Rollback source: $source_dir (default backup)"
    fi
    
    echo "Current deployment: $public_html_dir"
    echo "Failed deployment will be preserved for debugging"
    echo "========================================="
    read -p "Continue with rollback? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Rollback cancelled."
        exit 0
    fi
    
    # Perform the rollback using modified logic
    echo "Starting rollback process..."
    
    # Create a temporary directory for the current deployment
    failed_dir="$site_root/public_html_failed_$(date +%Y%m%d_%H%M%S)"
    echo "Moving current deployment to: $failed_dir"
    
    # Move the current public_html to preserve it for debugging
    if [[ -d "$public_html_dir" ]]; then
        mv "$public_html_dir" "$failed_dir" || {
            echo "ERROR: Could not move current deployment"
            exit 1
        }
    fi
    
    # Recreate public_html directory
    mkdir -p "$public_html_dir"
    
    # Restore from source directory
    echo "Restoring from: $source_dir"
    cp -r "$source_dir"/* "$public_html_dir/" || {
        echo "ERROR: Failed to restore from source directory"
        exit 1
    }
    
    # Fix permissions using centralized script
    fix_permissions "$TARGET_SITE" --production

    echo "========================================="
    echo "SUCCESS: Manual rollback completed for '$TARGET_SITE'!"
    echo "Restored from: $source_dir"
    echo "Previous deployment preserved at: $failed_dir"
    echo "========================================="
    exit 0
fi

deploy_directory="/var/www/html/$TARGET_SITE"

# Show deployment configuration
echo "========================================="
echo "DEPLOY.SH VERSION: $DEPLOY_VERSION"
echo "DEPLOYMENT CONFIGURATION:"
echo "Deploy type: $DEPLOY_TYPE"
echo "Live site: $LIVE_SITE"
echo "Target site: $TARGET_SITE"
echo "Target directory: $deploy_directory"
if [ "$DISABLE_ROLLBACK" = true ]; then
    echo "Rollback: DISABLED"
else
    echo "Rollback: ENABLED"
fi
echo "========================================="

if [ "$IS_TEST_DEPLOY" = true ]; then
    echo "This will:"
    echo "1. Copy database: $LIVE_SITE -> $TARGET_SITE"
    echo "2. Deploy code to: $deploy_directory"
    echo "3. Deploy fresh theme/plugins from repository"
    echo "========================================="
    read -p "Continue with FULL deployment to TEST site? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled."
        exit 0
    fi
else
    echo "This will deploy to the LIVE site: $TARGET_SITE"
    echo "This includes fresh theme/plugins from repository"
    echo "========================================="
    read -p "Continue with FULL LIVE deployment? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled."
        exit 0
    fi
fi

# CREATE DEPLOY DIRECTORY AND STRUCTURE IF IT DOESN'T EXIST (for test deploys)
if [ "$IS_TEST_DEPLOY" = true ] && [[ ! -d $deploy_directory ]]; then
    echo "Deploy directory $deploy_directory does not exist. Creating it..."
    mkdir -p "$deploy_directory"
    mkdir -p "$deploy_directory/public_html"
    mkdir -p "$deploy_directory/logs"
    mkdir -p "$deploy_directory/theme"
    mkdir -p "$deploy_directory/plugins"
    mkdir -p "$deploy_directory/static_files"
    mkdir -p "$deploy_directory/uploads"
    echo "Created directory structure for $TARGET_SITE"
fi

# For live deploys or theme-only deploys, directory must exist
if [ "$IS_TEST_DEPLOY" = false ] && [[ ! -d $deploy_directory ]]; then
    echo "ERROR: Deploy directory $deploy_directory does not exist."
    exit 1
fi

# Ensure public_html directory exists
if [[ ! -d "/var/www/html/$TARGET_SITE/public_html" ]]; then
    echo "Creating public_html directory..."
    mkdir -p "/var/www/html/$TARGET_SITE/public_html"
fi

# CREATE ADDITIONAL SUBDIRECTORIES IF NEEDED (test deploys only)
if [ "$IS_TEST_DEPLOY" = true ]; then
    for dir in logs static_files uploads config; do
        if [[ ! -d "/var/www/html/$TARGET_SITE/$dir" ]]; then
            echo "Creating $dir directory..."
            mkdir -p "/var/www/html/$TARGET_SITE/$dir"
        fi
    done
    
    # COPY DATABASE FROM LIVE SITE TO TEST SITE
    echo "Copying database from $LIVE_SITE to $TARGET_SITE..."
    if [[ -f "./copy_database.sh" ]]; then
        ./copy_database.sh "$LIVE_SITE" "$TARGET_SITE"
        if [ $? -ne 0 ]; then
            echo "ERROR: Database copy failed. Aborting deploy."
            exit 1
        fi
        echo "Database copy successful."
    else
        echo "ERROR: copy_database.sh not found in current directory. Aborting deploy."
        exit 1
    fi
fi

# DEPLOYMENT OPERATIONS START HERE - Set flag for rollback system
DEPLOYMENT_STARTED=true

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

# CLEAR THE STAGING FOLDER AND RECREATE
rm -rf /var/www/html/$TARGET_SITE/public_html_stage
mkdir /var/www/html/$TARGET_SITE/public_html_stage

# CLONE THE REPO DIRECTLY INTO staging directory
verbose_echo "Cloning repository: $REPO_URL"
if [ "$VERBOSE" = true ]; then
    git clone --no-checkout "$REPO_URL" /var/www/html/$TARGET_SITE/public_html_stage
else
    git clone --quiet --no-checkout "$REPO_URL" /var/www/html/$TARGET_SITE/public_html_stage
fi

# MOVE INTO THE CLONED DIRECTORY
cd /var/www/html/$TARGET_SITE/public_html_stage || exit 1

# SPARSE CHECKOUT: Only extract public_html/ directory
verbose_echo "Configuring sparse checkout for public_html directory"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html

# CHECKOUT THE MAIN BRANCH
verbose_echo "Checking out main branch"
if [ "$VERBOSE" = true ]; then
    git checkout main
else
    git checkout --quiet main
fi

# MOVE public_html CONTENTS TO ROOT OF STAGING DIRECTORY
verbose_echo "Moving public_html contents to staging root"
shopt -s dotglob
mv public_html/* . 2>/dev/null || true
rmdir public_html
shopt -u dotglob

rm -rf .git
echo "✓ Repository cloned and updated to latest version"

# MIGRATE CACHE DIRECTORY OUT OF PUBLIC_HTML (if it exists)
if [[ -d "/var/www/html/$TARGET_SITE/public_html/cache" ]]; then
    verbose_echo "Migrating cache directory out of public_html..."

    # Check if cache already exists at site root level
    if [[ -d "/var/www/html/$TARGET_SITE/cache" ]]; then
        verbose_echo "Cache directory already exists at site root - merging with public_html cache..."
        # Merge the two cache directories (public_html cache takes precedence)
        if ! cp -a /var/www/html/$TARGET_SITE/public_html/cache/. /var/www/html/$TARGET_SITE/cache/; then
            echo "ERROR: Failed to merge cache directories. This is likely a permissions issue."
            echo "Try running: sudo chown -R www-data:user1 /var/www/html/$TARGET_SITE/public_html/cache"
            echo "Then run deployment again."
            exit 1
        fi
        # Remove the old cache from public_html
        if ! rm -rf /var/www/html/$TARGET_SITE/public_html/cache; then
            echo "ERROR: Failed to remove cache directory from public_html after merge."
            echo "Permissions issue - deployment aborted."
            exit 1
        fi
        verbose_echo "✓ Cache merged and public_html cache removed"
    else
        # Move cache directory to site root
        verbose_echo "Moving cache from public_html to site root..."
        if ! mv /var/www/html/$TARGET_SITE/public_html/cache /var/www/html/$TARGET_SITE/cache; then
            echo "ERROR: Failed to move cache directory from public_html to site root."
            echo "This is likely a permissions issue."
            echo "Try running: sudo chown -R www-data:user1 /var/www/html/$TARGET_SITE/public_html/cache"
            echo "Then run deployment again."
            exit 1
        fi
        verbose_echo "✓ Cache directory migrated successfully"
    fi
else
    verbose_echo "No cache directory in public_html to migrate"
fi

# BACKUP CURRENT DEPLOYMENT TO LAST (ATOMIC OPERATION)
verbose_echo "Creating atomic backup of current deployment..."
rm -rf /var/www/html/$TARGET_SITE/public_html_last
mkdir -p /var/www/html/$TARGET_SITE/public_html_last

if [[ -d /var/www/html/$TARGET_SITE/public_html ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html 2>/dev/null)" ]]; then
    # Use cp -a to preserve all attributes and copy hidden files
    verbose_echo "Backing up existing public_html to public_html_last..."
    if ! cp -a /var/www/html/$TARGET_SITE/public_html/. /var/www/html/$TARGET_SITE/public_html_last/; then
        echo "ERROR: Failed to create backup of current deployment. Aborting."
        echo "Cannot proceed with deployment if backup fails."
        exit 1
    fi

    # Verify backup was created successfully
    if [[ ! -d /var/www/html/$TARGET_SITE/public_html_last ]] || [[ -z "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        echo "ERROR: Backup verification failed - backup directory is empty. Aborting."
        exit 1
    fi

    verbose_echo "✓ Backup created successfully"

    # Now safe to clear public_html for new deployment
    verbose_echo "Clearing public_html for new deployment..."
    if ! rm -rf /var/www/html/$TARGET_SITE/public_html/*; then
        echo "ERROR: Failed to clear public_html. Aborting."
        echo "Backup preserved at public_html_last"
        exit 1
    fi

    # Also remove hidden files
    if ! rm -rf /var/www/html/$TARGET_SITE/public_html/.[!.]* 2>/dev/null; then
        # This may fail if no hidden files exist - that's ok
        verbose_echo "No hidden files to remove (or permission denied)"
    fi

    verbose_echo "✓ public_html cleared for new deployment"
else
    verbose_echo "No existing deployment to backup (fresh install)"
fi

# DOWNLOAD MAINTENANCE SCRIPTS FROM JOINERY REPOSITORY
# Note: Themes and plugins are now included in public_html/ sparse checkout above
if ! deploy_maintenance_scripts "$TARGET_SITE"; then
    echo "ERROR: Maintenance scripts download failed. Aborting deployment."
    exit 1
fi

# PRESERVE CUSTOM THEMES AND PLUGINS FROM EXISTING PUBLIC_HTML (using DeploymentHelper)
verbose_echo "Preserving custom themes and plugins from existing deployment..."
preservation_output=$(php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

    \$verbose = $VERBOSE ? true : false;
    \$result = DeploymentHelper::preserveCustomThemesPlugins('/var/www/html/$TARGET_SITE', \$verbose);

    if (!\$result['success']) {
        echo 'ERROR:' . \$result['message'] . PHP_EOL;
        exit(1);
    } else {
        echo 'SUCCESS:' . \$result['summary'];
        exit(0);
    }
" 2>&1)
preservation_exit=$?

if [ $preservation_exit -ne 0 ]; then
    echo "$preservation_output" | grep "^ERROR:" | while IFS=: read -r prefix message; do
        echo "ERROR: $message"
    done
    exit 1
else
    summary=$(echo "$preservation_output" | grep "^SUCCESS:" | cut -d: -f2-)
    echo "$summary"
fi

# RUN PRE-DEPLOYMENT TESTS ON STAGING
echo "Running pre-deployment tests on staging environment..."

# PHP SYNTAX VALIDATION ON STAGING (using DeploymentHelper)
verbose_echo "Validating PHP syntax on staging files..."
php_validation_output=$(php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

    \$verbose = $VERBOSE ? true : false;
    \$result = DeploymentHelper::validatePHPSyntax('/var/www/html/$TARGET_SITE/public_html_stage', \$verbose);

    if (!\$result['success']) {
        echo 'ERRORS_FOUND:' . count(\$result['errors']) . ':' . \$result['files_checked'] . PHP_EOL;
        foreach (\$result['errors'] as \$error) {
            echo 'ERROR:' . \$error['file'] . ':' . \$error['line'] . ':' . \$error['message'] . PHP_EOL;
        }
        exit(1);
    } else {
        echo 'SUCCESS:' . \$result['files_checked'];
        exit(0);
    }
" 2>&1)
php_validation_exit=$?

if [ $php_validation_exit -ne 0 ]; then
    # Parse error output
    error_count=$(echo "$php_validation_output" | grep "^ERRORS_FOUND:" | cut -d: -f2)
    files_checked=$(echo "$php_validation_output" | grep "^ERRORS_FOUND:" | cut -d: -f3)

    echo "$php_validation_output" | grep "^ERROR:" | while IFS=: read -r prefix file line message; do
        echo "SYNTAX ERROR in: $file (line $line)"
        echo "  $message"
    done

    handle_test_failure "$error_count PHP syntax errors found in staging." "/var/www/html/$TARGET_SITE/public_html_stage"
    if [ $? -eq 1 ]; then
        exit 1
    fi
else
    files_checked=$(echo "$php_validation_output" | grep "^SUCCESS:" | cut -d: -f2)
    verbose_echo "✓ PHP syntax validation passed ($files_checked files checked)"
fi

# PLUGIN LOADING TEST ON STAGING (using DeploymentHelper)
if [ "$FAST_MODE" = true ]; then
    echo "⚡ Fast mode: Skipping plugin loading test"
else
verbose_echo "Testing plugin class file loading on staging..."
plugin_test_output=$(php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

    \$verbose = $VERBOSE ? true : false;
    \$result = DeploymentHelper::testPluginLoading('/var/www/html/$TARGET_SITE/public_html_stage', \$verbose);

    if (!\$result['success']) {
        echo 'ERRORS_FOUND:' . count(\$result['errors']) . ':' . \$result['files_checked'] . PHP_EOL;
        foreach (\$result['errors'] as \$error) {
            echo 'ERROR:' . \$error['file'] . ':' . \$error['type'] . ':' . \$error['message'] . PHP_EOL;
        }
        exit(1);
    } else {
        echo 'SUCCESS:' . \$result['files_checked'];
        exit(0);
    }
" 2>&1)
plugin_test_exit=$?

if [ $plugin_test_exit -ne 0 ]; then
    # Parse error output
    error_count=$(echo "$plugin_test_output" | grep "^ERRORS_FOUND:" | cut -d: -f2)
    files_checked=$(echo "$plugin_test_output" | grep "^ERRORS_FOUND:" | cut -d: -f3)

    echo ""
    echo "========================================="
    echo "PLUGIN LOADING FAILURES DETECTED"
    echo "========================================="
    echo "ERROR: $error_count plugin loading errors found in staging."
    echo ""

    echo "$plugin_test_output" | grep "^ERROR:" | while IFS=: read -r prefix file type message; do
        echo "  [$type] $file"
        echo "    $message"
    done

    echo ""
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "You can manually test plugin files with:"
    echo "  cd /var/www/html/$TARGET_SITE/public_html_stage"
    echo "  php -l plugins/path/to/problematic_file.php"
    echo "========================================="

    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo ""
        echo "--norollback flag detected: Proceeding with deployment despite plugin loading failures."
        echo "WARNING: Plugin tests failed but deployment will continue. Manual intervention may be required."
        echo ""
    else
        exit 1
    fi
else
    files_checked=$(echo "$plugin_test_output" | grep "^SUCCESS:" | cut -d: -f2)
    verbose_echo "✓ Plugin loading test passed ($files_checked files checked)"
fi
fi  # End of FAST_MODE check for plugin loading test

# APPLICATION BOOTSTRAP TEST ON STAGING (using DeploymentHelper)
if [ "$FAST_MODE" = true ]; then
    echo "⚡ Fast mode: Skipping bootstrap test"
else
verbose_echo "Testing application bootstrap on staging..."
bootstrap_output=$(php -r "
    require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

    \$verbose = $VERBOSE ? true : false;
    \$result = DeploymentHelper::testBootstrap('/var/www/html/$TARGET_SITE/public_html_stage', \$verbose);

    if (!\$result['success']) {
        echo 'ERRORS_FOUND:' . count(\$result['errors']) . PHP_EOL;
        foreach (\$result['errors'] as \$error) {
            echo 'ERROR:' . \$error['type'] . ':' . \$error['message'] . PHP_EOL;
        }
        exit(1);
    } else {
        echo 'SUCCESS';
        exit(0);
    }
" 2>&1)
bootstrap_exit_code=$?

if [ $bootstrap_exit_code -ne 0 ]; then
    # Parse error output
    error_count=$(echo "$bootstrap_output" | grep "^ERRORS_FOUND:" | cut -d: -f2)

    echo ""
    echo "========================================="
    echo "BOOTSTRAP TEST FAILED"
    echo "========================================="
    echo "ERROR: Application bootstrap test failed in staging."
    echo ""

    echo "$bootstrap_output" | grep "^ERROR:" | while IFS=: read -r prefix type message; do
        echo "  [$type] $message"
    done

    echo ""
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "========================================="

    handle_test_failure "Application bootstrap test failed in staging." "/var/www/html/$TARGET_SITE/public_html_stage"
    if [ $? -eq 1 ]; then
        exit 1
    fi
else
    verbose_echo "✓ Application bootstrap test passed on staging"
fi
fi  # End of FAST_MODE check for bootstrap test

echo "✓ All pre-deployment tests passed on staging"

# DO THE MAIN CODE DEPLOY (with themes and plugins already in staging)
echo "✓ Deploying application code..."
cd /var/www/html/$TARGET_SITE/public_html_stage || {
    echo "ERROR: Could not change to staging directory"
    exit 1
}

file_count=0
for item in *; do
    verbose_echo "  Copying $item..."
    cp -r "$item" "/var/www/html/$TARGET_SITE/public_html/" || {
        echo "ERROR: Failed to copy $item"
        exit 1
    }
    ((file_count++))
done
cd - > /dev/null
echo "✓ Deployed $file_count components to public_html"

# Directory validation already performed during staging

# FIX PERMISSIONS AFTER DEPLOYMENT
verbose_echo "✓ Fixing permissions after deployment..."
fix_permissions "$TARGET_SITE"

# CHECK AND INSTALL COMPOSER DEPENDENCIES IF NEEDED
/usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/composer_install_if_needed.php
returnvalue=$?

if [[ "$returnvalue" != 0 ]]; then
    echo "ERROR: Composer dependency setup failed."

    # Check if rollback is disabled
    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo "ROLLBACK DISABLED: Keeping current deployment in place for debugging."
        echo "Manual intervention required to fix composer dependencies."
        exit 1
    fi

    # Attempt rollback using DeploymentHelper
    if [[ -d /var/www/html/$TARGET_SITE/public_html_last ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        rollback_result=$(php -r "
            require_once('/var/www/html/$TARGET_SITE/public_html/includes/PathHelper.php');
            require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

            \$result = DeploymentHelper::performRollback('/var/www/html/$TARGET_SITE');

            if (!\$result['success']) {
                echo 'ERROR:' . \$result['message'];
                exit(1);
            } else {
                exit(0);
            }
        " 2>&1)

        if [ $? -ne 0 ]; then
            echo "ERROR: Composer setup failed and rollback failed. Manual intervention required."
            echo "$rollback_result" | grep "^ERROR:" | while IFS=: read -r prefix message; do
                echo "  $message"
            done
            exit 1
        fi
    else
        echo "This appears to be an initial deployment - no previous version to rollback to."
        echo "Keeping current deployment in place for debugging."
    fi
    exit 1
else
    echo "✓ Composer dependencies validated/installed successfully"
fi

# Check if update_database.php exists
if [[ ! -f /var/www/html/$TARGET_SITE/public_html/utils/update_database.php ]]; then
    echo "ERROR: /var/www/html/$TARGET_SITE/public_html/utils/update_database.php does not exist."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: You can examine the staged files to understand what was deployed."
    
    # Check if rollback is disabled
    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo "ROLLBACK DISABLED: Keeping current deployment in place for debugging."
        echo "Manual intervention required to fix the missing update_database.php file."
        exit 1
    fi
    
    # Check if this is an initial deployment (no backup to restore)
    if [[ -d /var/www/html/$TARGET_SITE/public_html_last ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        rollback_result=$(php -r "
            require_once('/var/www/html/$TARGET_SITE/public_html/includes/PathHelper.php');
            require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

            \$result = DeploymentHelper::performRollback('/var/www/html/$TARGET_SITE');

            if (!\$result['success']) {
                echo 'ERROR:' . \$result['message'];
                exit(1);
            } else {
                exit(0);
            }
        " 2>&1)

        if [ $? -ne 0 ]; then
            echo "ERROR: Rollback failed. Manual intervention required."
            echo "$rollback_result" | grep "^ERROR:" | while IFS=: read -r prefix message; do
                echo "  $message"
            done
            exit 1
        fi
    else
        echo "This appears to be an initial deployment - no previous version to rollback to."
        echo "Keeping current deployment in place for debugging."
        # MODIFIED: Don't clean up staging directory on initial deployment failure
        # rm -rf /var/www/html/$TARGET_SITE/public_html_stage
    fi
    
    exit 1
fi

if [ "$VERBOSE" = true ]; then
    /usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php --upgrade --verbose
else
    /usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php --upgrade
fi
returnvalue=$?

if [[ "$returnvalue" != 0 ]]; then
    echo "ERROR: Database update failed."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: You can examine the staged files to understand what was deployed."
    exit 1
fi

# MODEL TESTS ON DEPLOYED CODE (after migrations and composer install)
if [ "$FAST_MODE" = true ]; then
    echo "⚡ Fast mode: Skipping model tests"
else
verbose_echo "Running model tests on deployed code..."
model_test_output=$(php -r "
    \$_SERVER['DOCUMENT_ROOT'] = '/var/www/html/$TARGET_SITE/public_html';
    chdir('/var/www/html/$TARGET_SITE/public_html');

    // Set output buffering to capture any output
    ob_start();

    // Include the model test runner
    try {
        include 'tests/models/run_all.php';
        \$output = ob_get_contents();
        ob_end_clean();

        // Check for test failures in output
        if (strpos(\$output, 'FAIL') !== false || strpos(\$output, 'ERROR') !== false) {
            echo \"Model test output:\n\";
            echo \$output;
            echo \"\nModel tests failed - check output for details\";
            exit(1);
        }

        echo 'Model tests completed successfully';
    } catch (Exception \$e) {
        ob_end_clean();
        echo 'Model test error: ' . \$e->getMessage();
        exit(1);
    } catch (Error \$e) {
        ob_end_clean();
        echo 'Model test fatal error: ' . \$e->getMessage();
        exit(1);
    }
" 2>&1)
model_test_exit_code=$?

if [ $model_test_exit_code -ne 0 ]; then
    echo ""
    echo "========================================="
    echo "MODEL TESTS FAILED"
    echo "========================================="
    echo "ERROR: Model tests failed on deployed code."
    echo ""
    echo "$model_test_output"
    echo ""

    # Check if rollback is disabled
    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo "ROLLBACK DISABLED: Keeping current deployment in place for debugging."
        echo "Manual intervention required to fix model test failures."
        exit 1
    fi

    # Attempt rollback using DeploymentHelper
    if [[ -d /var/www/html/$TARGET_SITE/public_html_last ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        echo "Attempting to rollback to previous version..."
        rollback_result=$(php -r "
            require_once('/var/www/html/$TARGET_SITE/public_html/includes/PathHelper.php');
            require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

            \$result = DeploymentHelper::performRollback('/var/www/html/$TARGET_SITE');

            if (!\$result['success']) {
                echo 'ERROR:' . \$result['message'];
                exit(1);
            } else {
                echo 'Rollback completed successfully';
                exit(0);
            }
        " 2>&1)

        if [ $? -ne 0 ]; then
            echo "ERROR: Rollback failed. Manual intervention required."
            echo "$rollback_result"
            exit 1
        else
            echo "$rollback_result"
            echo "Previous version has been restored."
            exit 1
        fi
    else
        echo "ERROR: No previous version available to rollback to."
        echo "Keeping failed deployment in place for debugging."
        exit 1
    fi
else
    verbose_echo "✓ Model tests passed on deployed code"
fi
fi  # End of FAST_MODE check for model tests

# PHP syntax validation and plugin loading tests now run on staging before deployment

# Bootstrap test now runs on staging before deployment

# CLEANUP: Remove staging directory after successful deployment ONLY
verbose_echo "Cleaning up staging directory..."
rm -rf /var/www/html/$TARGET_SITE/public_html_stage

# CLEANUP: Remove backup directory after successful deployment
verbose_echo "Cleaning up backup directory..."
if [[ -d "/var/www/html/$TARGET_SITE/public_html_last" ]]; then
    rm -rf /var/www/html/$TARGET_SITE/public_html_last
    verbose_echo "Removed backup directory: public_html_last"
else
    verbose_echo "No backup directory to clean up"
fi

# CLEANUP: Remove old failed deployment directories after successful deployment
verbose_echo "Cleaning up old failed deployment directories..."
failed_dirs_count=0
for failed_dir in /var/www/html/$TARGET_SITE/public_html_failed_*; do
    if [[ -d "$failed_dir" ]]; then
        verbose_echo "Removing old failed deployment: $(basename "$failed_dir")"
        rm -rf "$failed_dir"
        ((failed_dirs_count++))
    fi
done

if [[ $failed_dirs_count -eq 0 ]]; then
    verbose_echo "No failed deployment directories to clean up"
else
    verbose_echo "Removed $failed_dirs_count failed deployment directories"
fi

# Calculate and display deployment hash
DEPLOY_HASH=$(calculate_deployment_hash "/var/www/html/$TARGET_SITE/public_html")
echo ""
echo "========================================="
echo "DEPLOYMENT HASH: $DEPLOY_HASH"
echo "========================================="
echo "Use this hash to verify sites are in sync."
echo "Compare with other sites using:"
echo "  find /var/www/html/$TARGET_SITE/public_html -type f \\( -name '*.php' -o -name '*.js' -o -name '*.css' \\) -print0 | sort -z | xargs -0 cat | md5sum"

# DEPLOYMENT COMPLETED SUCCESSFULLY
DEPLOYMENT_SUCCESS=true