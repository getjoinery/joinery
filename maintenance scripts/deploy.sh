#!/usr/bin/env bash
#version 2.5 - Added automatic rollback via bash traps
# MODIFIED: Preserve staging directory on deployment failures for debugging
# MODIFIED: Added --norollback flag to disable rollback functionality
# MODIFIED: Improved rollback functionality to handle directory conflicts
# MODIFIED: Added trap-based automatic rollback system

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
        
        if perform_rollback "$TARGET_SITE"; then
            echo "✓ Automatic rollback completed successfully."
        else
            echo "✗ Automatic rollback failed. Manual intervention required."
        fi
        
        echo "Failed deployment preserved for debugging."
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

# Improved rollback function
perform_rollback() {
    local target_site="$1"
    local public_html_dir="/var/www/html/$target_site/public_html"
    local backup_dir="/var/www/html/$target_site/public_html_last"
    
    echo "Starting rollback process..."
    
    # Check if backup exists
    if [[ ! -d "$backup_dir" ]] || [[ -z "$(ls -A "$backup_dir" 2>/dev/null)" ]]; then
        echo "ERROR: No backup available to rollback to."
        return 1
    fi
    
    # Create a temporary directory for the failed deployment
    local failed_dir="/var/www/html/$target_site/public_html_failed_$(date +%Y%m%d_%H%M%S)"
    echo "Moving failed deployment to: $failed_dir"
    
    # Move the entire failed public_html to preserve it for debugging
    if [[ -d "$public_html_dir" ]]; then
        mv "$public_html_dir" "$failed_dir" && {
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
        } || {
            echo "ERROR: Could not move failed deployment. Attempting alternative cleanup..."
            
            # Alternative: Thorough directory-by-directory cleanup
            cd "$public_html_dir" || return 1
            
            # Remove each subdirectory individually
            for item in */; do
                if [[ -d "$item" ]]; then
                    echo "  Removing: $item"
                    rm -rf "$item" || echo "    Warning: Could not remove $item"
                fi
            done
            
            # Remove files
            find . -maxdepth 1 -type f -delete 2>/dev/null || true
            
            # Remove any remaining hidden files/directories
            find . -maxdepth 1 -name ".*" -not -name "." -not -name ".." -exec rm -rf {} + 2>/dev/null || true
        }
    fi
    
    # Recreate public_html directory if it doesn't exist
    mkdir -p "$public_html_dir"
    
    # Restore from backup using copy (safer than move)
    echo "Restoring from backup..."
    cp -r "$backup_dir"/* "$public_html_dir/" || {
        echo "ERROR: Failed to restore from backup"
        return 1
    }
    
    # Fix permissions
    echo "Fixing permissions after rollback..."
    chown -R www-data:user1 "$public_html_dir" 2>/dev/null || echo "Warning: Could not change ownership"
    chmod -R 775 "$public_html_dir" 2>/dev/null || echo "Warning: Could not change permissions"
    
    echo "Rollback completed successfully."
    echo "Failed deployment preserved at: $failed_dir"
    return 0
}

# Function to fix permissions
fix_permissions() {
    local target_site="$1"
    echo "Fixing permissions for $target_site..."
    
    # Check if running with sufficient privileges
    if [ "$EUID" -ne 0 ]; then
        echo "WARNING: Not running as root/sudo. Permission changes may fail."
        echo "For best results, run this script with: sudo $0 $*"
        echo "Attempting permission changes anyway..."
    fi
    
    # Test for site existence
    local site_root="/var/www/html/$target_site"
    if [[ ! -d "$site_root" ]]; then
        echo "ERROR: Site directory $site_root does not exist."
        return 1
    fi
    
    # Set the correct ownership and permissions (suppress errors for non-root execution)
    echo "Setting ownership to www-data..."
    chown -R www-data "/var/www/html/$target_site" 2>/dev/null || echo "  Warning: Could not change ownership (may need sudo)"
    
    echo "Setting group to user1..."
    chgrp -R user1 "/var/www/html/$target_site" 2>/dev/null || echo "  Warning: Could not change group (may need sudo)"
    
    echo "Setting permissions to 775..."
    chmod -R 775 "/var/www/html/$target_site" 2>/dev/null || echo "  Warning: Could not change permissions (may need sudo)"
    
    # Special permissions for uploads directory if it exists
    if [[ -d "/var/www/html/$target_site/uploads" ]]; then
        echo "Setting uploads directory permissions to 777..."
        chmod -R 777 "/var/www/html/$target_site/uploads" 2>/dev/null || echo "  Warning: Could not change uploads permissions (may need sudo)"
    fi
    
    echo "Permissions update complete for $target_site."
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
                    is_stock=$(jq -r '.is_stock // true' "$manifest_file" 2>/dev/null)
                    # Validate jq output
                    if [[ "$is_stock" != "true" && "$is_stock" != "false" ]]; then
                        is_stock="true"
                    fi
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
                    is_stock=$(jq -r '.is_stock // true' "$manifest_file" 2>/dev/null)
                    # Validate jq output
                    if [[ "$is_stock" != "true" && "$is_stock" != "false" ]]; then
                        is_stock="true"
                    fi
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
    
    echo "Downloading themes and plugins from joinery repository to $target_site..."
    
    # DEPLOY THEMES to /var/www/html/sitename/theme (outside public_html)
    echo "Setting up theme deployment to $site_root/theme..."
    local theme_stage_dir="$site_root/theme_stage"
    rm -rf "$theme_stage_dir"
    mkdir -p "$theme_stage_dir"
    
    # Clone repo for themes
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$theme_stage_dir"
    cd "$theme_stage_dir" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set theme
    git checkout main
    rm -rf .git
    cd - > /dev/null

    # DEPLOY PLUGINS to /var/www/html/sitename/plugins (outside public_html)
    echo "Setting up plugin deployment to $site_root/plugins..."
    local plugins_stage_dir="$site_root/plugins_stage"
    rm -rf "$plugins_stage_dir"
    mkdir -p "$plugins_stage_dir"
    
    # Clone repo for plugins
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$plugins_stage_dir"
    cd "$plugins_stage_dir" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set plugins
    git checkout main
    rm -rf .git
    cd - > /dev/null

    # Deploy themes to /var/www/html/sitename/theme (outside public_html)
    if [[ -d "$theme_stage_dir/theme" ]]; then
        echo "Deploying themes to $site_root/theme..."
        rm -rf "$site_root/theme_old"
        if [[ -d "$site_root/theme" ]]; then
            mv "$site_root/theme" "$site_root/theme_old"
        fi
        mv "$theme_stage_dir/theme" "$site_root/theme"
        chown -R www-data:user1 "$site_root/theme" 2>/dev/null || true
        chmod -R 775 "$site_root/theme" 2>/dev/null || true
        echo "Themes deployed successfully to $site_root/theme"
    else
        echo "ERROR: No theme directory found in joinery repository - deployment cannot continue"
        return 1
    fi

    # Deploy plugins to /var/www/html/sitename/plugins (outside public_html)  
    if [[ -d "$plugins_stage_dir/plugins" ]]; then
        echo "Deploying plugins to $site_root/plugins..."
        rm -rf "$site_root/plugins_old"
        if [[ -d "$site_root/plugins" ]]; then
            mv "$site_root/plugins" "$site_root/plugins_old"
        fi
        mv "$plugins_stage_dir/plugins" "$site_root/plugins"
        chown -R www-data:user1 "$site_root/plugins" 2>/dev/null || true
        chmod -R 775 "$site_root/plugins" 2>/dev/null || true
        echo "Plugins deployed successfully to $site_root/plugins"
    else
        echo "ERROR: No plugins directory found in joinery repository - deployment cannot continue"
        return 1
    fi

    # Validate that directories were created successfully
    if [[ ! -d "$site_root/theme" ]]; then
        echo "ERROR: Theme directory was not created successfully at $site_root/theme"
        return 1
    fi
    
    if [[ ! -d "$site_root/plugins" ]]; then
        echo "ERROR: Plugins directory was not created successfully at $site_root/plugins"
        return 1
    fi

    # Cleanup staging directories
    echo "Cleaning up theme/plugin staging directories..."
    rm -rf "$theme_stage_dir"
    rm -rf "$plugins_stage_dir"
    
    echo "Theme and plugin download from joinery repository complete."
    return 0
}

# Function to merge themes and plugins into public_html after main code deployment
merge_themes_plugins_to_public_html() {
    local target_site="$1"
    local site_root="/var/www/html/$target_site"
    local public_html_dir="$site_root/public_html"
    
    echo "Merging themes and plugins into public_html..."
    
    # Ensure public_html theme and plugins directories exist
    mkdir -p "$public_html_dir/theme"
    mkdir -p "$public_html_dir/plugins"
    
    # MERGE THEMES: overwrite same names, preserve different names
    echo "Merging themes from $site_root/theme to $public_html_dir/theme..."
    if [[ -d "$site_root/theme" ]]; then
        # Copy all themes from joinery repo, overwriting any with same names
        for theme_path in "$site_root/theme"/*/; do
            if [[ -d "$theme_path" ]]; then
                theme_name=$(basename "$theme_path")
                target_theme_path="$public_html_dir/theme/$theme_name"
                
                if [[ -d "$target_theme_path" ]]; then
                    echo "  OVERWRITING existing theme: $theme_name (from joinery repo)"
                    rm -rf "$target_theme_path"
                else
                    echo "  Adding theme: $theme_name (from joinery repo)"
                fi
                
                cp -r "$theme_path" "$public_html_dir/theme/" || {
                    echo "ERROR: Failed to copy theme $theme_name"
                    return 1
                }
                
                # Fix permissions for theme
                chown -R www-data:user1 "$target_theme_path" 2>/dev/null || true
                chmod -R 775 "$target_theme_path" 2>/dev/null || true
            fi
        done
        echo "Theme merge completed."
    else
        echo "ERROR: No themes directory found at $site_root/theme"
        return 1
    fi
    
    # MERGE PLUGINS: overwrite same names, preserve different names  
    echo "Merging plugins from $site_root/plugins to $public_html_dir/plugins..."
    if [[ -d "$site_root/plugins" ]]; then
        # Copy all plugins from joinery repo, overwriting any with same names
        for plugin_path in "$site_root/plugins"/*/; do
            if [[ -d "$plugin_path" ]]; then
                plugin_name=$(basename "$plugin_path")
                target_plugin_path="$public_html_dir/plugins/$plugin_name"
                
                if [[ -d "$target_plugin_path" ]]; then
                    echo "  OVERWRITING existing plugin: $plugin_name (from joinery repo)"
                    rm -rf "$target_plugin_path"
                else
                    echo "  Adding plugin: $plugin_name (from joinery repo)"
                fi
                
                cp -r "$plugin_path" "$public_html_dir/plugins/" || {
                    echo "ERROR: Failed to copy plugin $plugin_name"
                    return 1
                }
                
                # Fix permissions for plugin
                chown -R www-data:user1 "$target_plugin_path" 2>/dev/null || true
                chmod -R 775 "$target_plugin_path" 2>/dev/null || true
            fi
        done
        echo "Plugin merge completed."
    else
        echo "ERROR: No plugins directory found at $site_root/plugins"
        return 1
    fi
    
    # Final validation
    if [[ ! -d "$public_html_dir/theme" ]]; then
        echo "ERROR: Theme directory missing after merge: $public_html_dir/theme"
        return 1
    fi
    
    if [[ ! -d "$public_html_dir/plugins" ]]; then
        echo "ERROR: Plugins directory missing after merge: $public_html_dir/plugins"
        return 1
    fi
    
    echo "Theme and plugin merge to public_html complete."
    return 0
}

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Full deploy to live site"
    echo "  $0 [site_name] --test             # Full deploy to test site (site_name_test)"
    echo "  $0 [site_name] --fix-permissions  # Fix permissions only (no deployment)"
    echo "  $0 [site_name] --norollback       # Disable rollback on deployment failure"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Full deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Full deploy to getjoinery_test"
    echo "  $0 getjoinery --fix-permissions   # Fix permissions on getjoinery"
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
DISABLE_ROLLBACK=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --test)
            IS_TEST_DEPLOY=true
            ;;
        --fix-permissions)
            IS_FIX_PERMISSIONS_ONLY=true
            ;;
        --norollback)
            DISABLE_ROLLBACK=true
            ;;
    esac
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

deploy_directory="/var/www/html/$TARGET_SITE"

# Show deployment configuration
echo "========================================="
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
echo "========================================="
echo "STARTING DEPLOYMENT OPERATIONS"
echo "Target: $TARGET_SITE ($DEPLOY_TYPE)"
echo "Automatic rollback: $([ "$DISABLE_ROLLBACK" = true ] && echo "DISABLED" || echo "ENABLED")"
echo "========================================="

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
git clone --no-checkout "$REPO_URL" /var/www/html/$TARGET_SITE/public_html_stage

# MOVE INTO THE CLONED DIRECTORY
cd /var/www/html/$TARGET_SITE/public_html_stage || exit 1

# PULL ONLY THE SPECIFIED FOLDERS
git pull origin main
git checkout main
rm -rf .git

# CLEAR THE LAST FOLDER AND SAVE CURRENT TO LAST
rm -rf /var/www/html/$TARGET_SITE/public_html_last
mkdir /var/www/html/$TARGET_SITE/public_html_last
if [[ -d /var/www/html/$TARGET_SITE/public_html ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html)" ]]; then
    mv /var/www/html/$TARGET_SITE/public_html/* /var/www/html/$TARGET_SITE/public_html_last/ 2>/dev/null || true
fi

# Create .htaccess to block web access to backup directory
echo "Creating .htaccess to block web access to backup directory..."
cat > /var/www/html/$TARGET_SITE/public_html_last/.htaccess << 'EOF'
# Block all web access to backup directory
Order Deny,Allow
Deny from all

# Alternative syntax for Apache 2.4+
<RequireAll>
    Require all denied
</RequireAll>
EOF

# DO THE MAIN CODE DEPLOY (with smart theme/plugin merging)
echo "Deploying main application code..."
cd /var/www/html/$TARGET_SITE/public_html_stage || {
    echo "ERROR: Could not change to staging directory"
    exit 1
}

for item in *; do
    echo "Copying $item..."
    cp -r "$item" "/var/www/html/$TARGET_SITE/public_html/" || {
        echo "ERROR: Failed to copy $item"
        exit 1
    }
done
cd - > /dev/null

# DOWNLOAD THEMES AND PLUGINS FROM JOINERY REPOSITORY
echo "Downloading themes and plugins from joinery repository..."
if ! deploy_theme_plugin "$TARGET_SITE"; then
    echo "ERROR: Theme/plugin download failed. Aborting deployment."
    exit 1
fi

# MERGE THEMES AND PLUGINS INTO PUBLIC_HTML
echo "Merging themes and plugins into public_html..."
if ! merge_themes_plugins_to_public_html "$TARGET_SITE"; then
    echo "ERROR: Theme/plugin merge failed."
    exit 1
fi

# VALIDATE CRITICAL DIRECTORIES EXIST AFTER DEPLOYMENT
echo "Validating critical directories after deployment and merge..."
if [[ ! -d "/var/www/html/$TARGET_SITE/public_html/theme" ]]; then
    echo "ERROR: Theme directory missing after merge: /var/www/html/$TARGET_SITE/public_html/theme"
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: Theme source directory: /var/www/html/$TARGET_SITE/theme"
    exit 1
fi

if [[ ! -d "/var/www/html/$TARGET_SITE/public_html/plugins" ]]; then
    echo "ERROR: Plugins directory missing after merge: /var/www/html/$TARGET_SITE/public_html/plugins"
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: Plugins source directory: /var/www/html/$TARGET_SITE/plugins"
    exit 1
fi

echo "Critical directories validation passed."

# FIX PERMISSIONS AFTER DEPLOYMENT
echo "Fixing permissions after deployment..."
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
    
    # Attempt rollback
    if [[ -d /var/www/html/$TARGET_SITE/public_html_last ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        if ! perform_rollback "$TARGET_SITE"; then
            echo "ERROR: Composer setup failed and rollback failed. Manual intervention required."
            exit 1
        fi
    else
        echo "This appears to be an initial deployment - no previous version to rollback to."
        echo "Keeping current deployment in place for debugging."
    fi
    exit 1
else
    echo "Composer dependencies validated/installed successfully."
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
        if ! perform_rollback "$TARGET_SITE"; then
            echo "ERROR: Rollback failed. Manual intervention required."
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

/usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php --upgrade
returnvalue=$?

if [[ "$returnvalue" != 1 ]]; then
    echo "ERROR: Database update failed."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: You can examine the staged files to understand what was deployed."
    exit 1
else
    echo "Database update successful."
fi

# CLEANUP: Remove staging directory after successful deployment ONLY
echo "Cleaning up staging directory..."
rm -rf /var/www/html/$TARGET_SITE/public_html_stage

# CLEANUP: Remove backup directory after successful deployment
echo "Cleaning up backup directory..."
if [[ -d "/var/www/html/$TARGET_SITE/public_html_last" ]]; then
    rm -rf /var/www/html/$TARGET_SITE/public_html_last
    echo "Removed backup directory: public_html_last"
else
    echo "No backup directory to clean up"
fi

# CLEANUP: Remove old failed deployment directories after successful deployment
echo "Cleaning up old failed deployment directories..."
failed_dirs_count=0
for failed_dir in /var/www/html/$TARGET_SITE/public_html_failed_*; do
    if [[ -d "$failed_dir" ]]; then
        echo "Removing old failed deployment: $(basename "$failed_dir")"
        rm -rf "$failed_dir"
        ((failed_dirs_count++))
    fi
done

if [[ $failed_dirs_count -eq 0 ]]; then
    echo "No failed deployment directories to clean up"
else
    echo "Removed $failed_dirs_count failed deployment directories"
fi

# DEPLOYMENT COMPLETED SUCCESSFULLY
DEPLOYMENT_SUCCESS=true