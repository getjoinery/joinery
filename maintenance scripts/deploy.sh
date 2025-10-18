#!/usr/bin/env bash
#version 3.0 - SKIPPING migration messages now hidden by default, only shown with --verbose flag
# MODIFIED: Added comprehensive PHP syntax validation during deployment
# MODIFIED: Added plugin loading test with proper PathHelper context
# MODIFIED: Added basic runtime bootstrap test
# MODIFIED: Added manual rollback capability with optional directory selection
# MODIFIED: Preserve staging directory on deployment failures for debugging
# MODIFIED: Added --norollback flag to disable rollback functionality
# MODIFIED: Improved rollback functionality to handle directory conflicts
# MODIFIED: Added trap-based automatic rollback system

# Deploy script version
DEPLOY_VERSION="3.09"

# Helper function for verbose output
verbose_echo() {
    if [ "$VERBOSE" = true ]; then
        echo "$@"
    fi
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

        # Call DeploymentHelper::performRollback()
        rollback_result=$(php -r "
            // DeploymentHelper may not be available if deployment failed early
            // So we need to check for PathHelper first
            if (file_exists('/var/www/html/$TARGET_SITE/public_html/includes/PathHelper.php')) {
                require_once('/var/www/html/$TARGET_SITE/public_html/includes/PathHelper.php');
            } else if (file_exists('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php')) {
                require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
            } else {
                echo 'ERROR:PathHelper not found';
                exit(1);
            }

            require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

            \$result = DeploymentHelper::performRollback('/var/www/html/$TARGET_SITE');

            if (!\$result['success']) {
                echo 'ERROR:' . \$result['message'];
                exit(1);
            } else {
                echo 'SUCCESS:' . \$result['message'];
                if (isset(\$result['failed_dir'])) {
                    echo ':' . \$result['failed_dir'];
                }
                exit(0);
            }
        " 2>&1)
        rollback_exit=$?

        if [ $rollback_exit -eq 0 ]; then
            echo "✓ Automatic rollback completed successfully."
            failed_location=$(echo "$rollback_result" | grep "^SUCCESS:" | cut -d: -f3)
            if [ -n "$failed_location" ]; then
                echo "Failed deployment preserved at: $failed_location"
            else
                echo "Failed deployment preserved for debugging."
            fi
        else
            echo "✗ Automatic rollback failed. Manual intervention required."
            echo "$rollback_result" | grep "^ERROR:" | while IFS=: read -r prefix message; do
                echo "  $message"
            done
        fi
    fi
    
    exit $exit_code
}

# Set up trap to run cleanup on script exit
trap cleanup_and_rollback EXIT

GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

# Theme/Plugin repository settings
THEME_PLUGIN_USER="getjoinery"
THEME_PLUGIN_TOKEN="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"
THEME_PLUGIN_REPO_URL="https://${THEME_PLUGIN_USER}:${THEME_PLUGIN_TOKEN}@github.com/getjoinery/joinery.git"

# Function to fix permissions
fix_permissions() {
    local target_site="$1"
    local has_warnings=false
    
    if [ "$VERBOSE" = true ]; then
        echo "Fixing permissions for $target_site..."
    fi
    
    # Check if running with sufficient privileges
    if [ "$EUID" -ne 0 ]; then
        has_warnings=true
        if [ "$VERBOSE" = true ]; then
            echo "WARNING: Not running as root/sudo. Permission changes may fail."
            echo "For best results, run this script with: sudo $0 $*"
            echo "Attempting permission changes anyway..."
        fi
    fi
    
    # Test for site existence
    local site_root="/var/www/html/$target_site"
    if [[ ! -d "$site_root" ]]; then
        echo "ERROR: Site directory $site_root does not exist."
        return 1
    fi
    
    # Set the correct ownership and permissions (suppress errors for non-root execution)
    if [ "$VERBOSE" = true ]; then
        echo "Setting ownership to www-data..."
    fi
    if ! chown -R www-data "/var/www/html/$target_site" 2>/dev/null; then
        has_warnings=true
        if [ "$VERBOSE" = true ]; then
            echo "  Warning: Could not change ownership (may need sudo)"
        fi
    fi
    
    if [ "$VERBOSE" = true ]; then
        echo "Setting group to user1..."
    fi
    if ! chgrp -R user1 "/var/www/html/$target_site" 2>/dev/null; then
        has_warnings=true
        if [ "$VERBOSE" = true ]; then
            echo "  Warning: Could not change group (may need sudo)"
        fi
    fi
    
    if [ "$VERBOSE" = true ]; then
        echo "Setting permissions to 775..."
    fi
    if ! chmod -R 775 "/var/www/html/$target_site" 2>/dev/null; then
        has_warnings=true
        if [ "$VERBOSE" = true ]; then
            echo "  Warning: Could not change permissions (may need sudo)"
        fi
    fi
    
    # Special permissions for uploads directory if it exists
    if [[ -d "/var/www/html/$target_site/uploads" ]]; then
        if [ "$VERBOSE" = true ]; then
            echo "Setting uploads directory permissions to 777..."
        fi
        if ! chmod -R 777 "/var/www/html/$target_site/uploads" 2>/dev/null; then
            has_warnings=true
            if [ "$VERBOSE" = true ]; then
                echo "  Warning: Could not change uploads permissions (may need sudo)"
            fi
        fi
    fi
    
    if [ "$VERBOSE" = true ]; then
        echo "Permissions update complete for $target_site."
    elif [ "$has_warnings" = true ]; then
        echo "  Permissions updated (warnings occurred - use --verbose for details)"
    else
        echo "  Permissions updated successfully"
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

# Function to deploy themes and plugins from external repository to directories outside public_html
deploy_theme_plugin() {
    local target_site="$1"
    local site_root="/var/www/html/$target_site"
    local staging_dir="$site_root/public_html_stage"
    
    echo "Downloading themes and plugins from joinery repository to $target_site..."
    
    # DEPLOY THEMES to /var/www/html/sitename/theme (outside public_html)
    verbose_echo "Setting up theme deployment to $site_root/theme..."
    local theme_stage_dir="$site_root/theme_stage"
    rm -rf "$theme_stage_dir"
    mkdir -p "$theme_stage_dir"
    
    # Clone repo for themes
    verbose_echo "Cloning themes from: $THEME_PLUGIN_REPO_URL"
    if [ "$VERBOSE" = true ]; then
        git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$theme_stage_dir"
    else
        git clone --quiet --no-checkout "$THEME_PLUGIN_REPO_URL" "$theme_stage_dir" 2>/dev/null
    fi
    cd "$theme_stage_dir" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set theme
    if [ "$VERBOSE" = true ]; then
        git checkout main
    else
        git checkout --quiet main 2>/dev/null
    fi
    rm -rf .git
    cd - > /dev/null

    # DEPLOY PLUGINS to /var/www/html/sitename/plugins (outside public_html)
    verbose_echo "Setting up plugin deployment to $site_root/plugins..."
    local plugins_stage_dir="$site_root/plugins_stage"
    rm -rf "$plugins_stage_dir"
    mkdir -p "$plugins_stage_dir"
    
    # Clone repo for plugins
    verbose_echo "Cloning plugins from: $THEME_PLUGIN_REPO_URL"
    if [ "$VERBOSE" = true ]; then
        git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$plugins_stage_dir"
    else
        git clone --quiet --no-checkout "$THEME_PLUGIN_REPO_URL" "$plugins_stage_dir" 2>/dev/null
    fi
    cd "$plugins_stage_dir" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set plugins
    if [ "$VERBOSE" = true ]; then
        git checkout main
    else
        git checkout --quiet main 2>/dev/null
    fi
    rm -rf .git
    cd - > /dev/null

    # Deploy themes directly to public_html_stage
    if [[ -d "$theme_stage_dir/theme" ]]; then
        verbose_echo "Deploying stock themes to staging directory..."
        mkdir -p "$staging_dir"
        
        # Deploy stock themes (status will be shown during merge operation)
        
        mv "$theme_stage_dir/theme" "$staging_dir/theme" || {
            echo "ERROR: Failed to move themes to staging directory"
            return 1
        }
        verbose_echo "Stock themes deployed successfully to staging directory"
    else
        echo "ERROR: No theme directory found in joinery repository - deployment cannot continue"
        return 1
    fi

    # Deploy plugins directly to public_html_stage
    if [[ -d "$plugins_stage_dir/plugins" ]]; then
        verbose_echo "Deploying stock plugins to staging directory..."
        mkdir -p "$staging_dir"
        
        # Deploy stock plugins (status will be shown during merge operation)
        
        mv "$plugins_stage_dir/plugins" "$staging_dir/plugins" || {
            echo "ERROR: Failed to move plugins to staging directory"
            return 1
        }
        verbose_echo "Stock plugins deployed successfully to staging directory"
    else
        echo "ERROR: No plugins directory found in joinery repository - deployment cannot continue"
        return 1
    fi

    # Validate that directories were created successfully in staging
    if [[ ! -d "$staging_dir/theme" ]]; then
        echo "ERROR: Theme directory was not created successfully in staging directory"
        return 1
    fi
    
    if [[ ! -d "$staging_dir/plugins" ]]; then
        echo "ERROR: Plugins directory was not created successfully in staging directory"
        return 1
    fi

    # Cleanup staging directories
    verbose_echo "Cleaning up theme/plugin staging directories..."
    rm -rf "$theme_stage_dir"
    rm -rf "$plugins_stage_dir"
    
    echo "Theme and plugin download from joinery repository complete."
    return 0
}

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Full deploy to live site"
    echo "  $0 [site_name] --test             # Full deploy to test site (site_name_test)"
    echo "  $0 [site_name] --fix-permissions  # Fix permissions only (no deployment)"
    echo "  $0 [site_name] --rollback [dir]   # Manual rollback to last backup or specified directory"
    echo "  $0 [site_name] --norollback       # Disable rollback on deployment failure"
    echo "  $0 [site_name] --verbose          # Show detailed output (git clones, individual files, etc.)"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Full deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Full deploy to getjoinery_test"
    echo "  $0 getjoinery --fix-permissions   # Fix permissions on getjoinery"
    echo "  $0 getjoinery --rollback          # Rollback to public_html_last"
    echo "  $0 getjoinery --rollback public_html_failed_20240907_143000  # Rollback to specific failed deployment"
    echo "  $0 getjoinery --test --norollback # Full deploy to test site without rollback"
    echo ""
    echo "Note: Permission fixes work best when run with sudo"
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
    echo "Owner: www-data, Group: user1, Permissions: 775 (777 for uploads)"
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
        
        # Create .htaccess to block web access to failed deployment directory
        echo "Creating .htaccess to block web access to failed deployment directory..."
        cat > "$failed_dir/.htaccess" << 'EOF'
# Block all web access to failed deployment directory
Order Deny,Allow
Deny from all

# Alternative syntax for Apache 2.4+
<RequireAll>
    Require all denied
</RequireAll>
EOF
    fi
    
    # Recreate public_html directory
    mkdir -p "$public_html_dir"
    
    # Restore from source directory
    echo "Restoring from: $source_dir"
    cp -r "$source_dir"/* "$public_html_dir/" || {
        echo "ERROR: Failed to restore from source directory"
        exit 1
    }
    
    # Fix permissions
    echo "Fixing permissions after rollback..."
    chown -R www-data:user1 "$public_html_dir" 2>/dev/null || echo "Warning: Could not change ownership (try with sudo)"
    chmod -R 775 "$public_html_dir" 2>/dev/null || echo "Warning: Could not change permissions (try with sudo)"
    
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

# PULL ONLY THE SPECIFIED FOLDERS
verbose_echo "Pulling latest changes from main branch"
if [ "$VERBOSE" = true ]; then
    git pull origin main
    git checkout main
else
    git pull --quiet origin main
    git checkout --quiet main
fi
rm -rf .git
echo "✓ Repository cloned and updated to latest version"

# CLEAR THE LAST FOLDER AND SAVE CURRENT TO LAST
rm -rf /var/www/html/$TARGET_SITE/public_html_last
mkdir /var/www/html/$TARGET_SITE/public_html_last
if [[ -d /var/www/html/$TARGET_SITE/public_html ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html)" ]]; then
    mv /var/www/html/$TARGET_SITE/public_html/* /var/www/html/$TARGET_SITE/public_html_last/ 2>/dev/null || true
fi

# Create .htaccess to block web access to backup directory
verbose_echo "Creating .htaccess to block web access to backup directory..."
cat > /var/www/html/$TARGET_SITE/public_html_last/.htaccess << 'EOF'
# Block all web access to backup directory
Order Deny,Allow
Deny from all

# Alternative syntax for Apache 2.4+
<RequireAll>
    Require all denied
</RequireAll>
EOF

# DOWNLOAD THEMES AND PLUGINS FROM JOINERY REPOSITORY
if ! deploy_theme_plugin "$TARGET_SITE"; then
    echo "ERROR: Theme/plugin download failed. Aborting deployment."
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

# MODEL TESTS ON STAGING
verbose_echo "Running model tests on staging..."
model_test_output=$(php -r "
    \$_SERVER['DOCUMENT_ROOT'] = '/var/www/html/$TARGET_SITE/public_html_stage';
    chdir('/var/www/html/$TARGET_SITE/public_html_stage');

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
    echo "Model test output:"
    echo "$model_test_output"
    handle_test_failure "Model tests failed in staging." "/var/www/html/$TARGET_SITE/public_html_stage"
    if [ $? -eq 1 ]; then
        exit 1
    fi
fi
verbose_echo "✓ Model tests passed on staging"

# APPLICATION BOOTSTRAP TEST ON STAGING (using DeploymentHelper)
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

# DEPLOYMENT COMPLETED SUCCESSFULLY
DEPLOYMENT_SUCCESS=true