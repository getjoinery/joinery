#!/usr/bin/env bash
#version 2.3 - Fixed deployment order and theme/plugin deployment
# MODIFIED: Preserve staging directory on deployment failures for debugging
# MODIFIED: Added --norollback flag to disable rollback functionality

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

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Full deploy to live site"
    echo "  $0 [site_name] --test             # Full deploy to test site (site_name_test)"
    echo "  $0 [site_name] --theme-only       # Deploy only themes/plugins to live site"
    echo "  $0 [site_name] --theme-only --test # Deploy only themes/plugins to test site"
    echo "  $0 [site_name] --fix-permissions  # Fix permissions only (no deployment)"
    echo "  $0 [site_name] --norollback       # Disable rollback on deployment failure"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Full deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Full deploy to getjoinery_test"
    echo "  $0 getjoinery --theme-only        # Update only themes/plugins on getjoinery"
    echo "  $0 getjoinery --fix-permissions   # Fix permissions on getjoinery"
    echo "  $0 getjoinery --test --norollback # Full deploy to test site without rollback"
    echo ""
    echo "Note: Permission fixes work best when run with sudo"
    echo "Test site will be available at: https://test.[domain].com"
}

# Function to deploy themes and plugins directly to public_html
deploy_theme_plugin() {
    local target_site="$1"
    local site_root="/var/www/html/$target_site"
    local public_html_dir="$site_root/public_html"
    
    echo "Deploying themes and plugins for $target_site directly to public_html..."
    
    # Ensure public_html directory exists
    if [[ ! -d "$public_html_dir" ]]; then
        echo "Creating public_html directory..."
        mkdir -p "$public_html_dir"
    fi
    
    # DEPLOY THEMES directly to public_html/theme
    echo "Setting up theme deployment to public_html/theme..."
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

    # DEPLOY PLUGINS directly to public_html/plugins
    echo "Setting up plugin deployment to public_html/plugins..."
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

    # Deploy themes directly to public_html/theme
    if [[ -d "$theme_stage_dir/theme" ]]; then
        echo "Deploying themes to public_html/theme..."
        rm -rf "$public_html_dir/theme_old"
        if [[ -d "$public_html_dir/theme" ]]; then
            mv "$public_html_dir/theme" "$public_html_dir/theme_old"
        fi
        mv "$theme_stage_dir/theme" "$public_html_dir/theme"
        chown -R www-data:user1 "$public_html_dir/theme" 2>/dev/null || true
        chmod -R 775 "$public_html_dir/theme" 2>/dev/null || true
        echo "Themes deployed successfully to public_html/theme"
    else
        echo "WARNING: No theme directory found in repository"
    fi

    # Deploy plugins directly to public_html/plugins
    if [[ -d "$plugins_stage_dir/plugins" ]]; then
        echo "Deploying plugins to public_html/plugins..."
        rm -rf "$public_html_dir/plugins_old"
        if [[ -d "$public_html_dir/plugins" ]]; then
            mv "$public_html_dir/plugins" "$public_html_dir/plugins_old"
        fi
        mv "$plugins_stage_dir/plugins" "$public_html_dir/plugins"
        chown -R www-data:user1 "$public_html_dir/plugins" 2>/dev/null || true
        chmod -R 775 "$public_html_dir/plugins" 2>/dev/null || true
        echo "Plugins deployed successfully to public_html/plugins"
    else
        echo "WARNING: No plugins directory found in repository"
    fi

    # Cleanup staging directories
    echo "Cleaning up theme/plugin staging directories..."
    rm -rf "$theme_stage_dir"
    rm -rf "$plugins_stage_dir"
    
    # Also create backup theme/plugins directories outside public_html for compatibility
    echo "Creating backup theme/plugins directories outside public_html..."
    if [[ -d "$public_html_dir/theme" ]]; then
        rm -rf "$site_root/theme"
        cp -r "$public_html_dir/theme" "$site_root/theme"
    fi
    if [[ -d "$public_html_dir/plugins" ]]; then
        rm -rf "$site_root/plugins"
        cp -r "$public_html_dir/plugins" "$site_root/plugins"
    fi
    
    echo "Theme and plugin deployment complete."
}

# Parse arguments
if [ "$1" == "" ] || [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_usage
    exit 1
fi

LIVE_SITE="$1"
IS_TEST_DEPLOY=false
IS_THEME_ONLY=false
IS_FIX_PERMISSIONS_ONLY=false
DISABLE_ROLLBACK=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --test)
            IS_TEST_DEPLOY=true
            ;;
        --theme-only)
            IS_THEME_ONLY=true
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
    elif [ "$IS_THEME_ONLY" = true ]; then
        DEPLOY_TYPE="THEME-ONLY (TEST)"
    else
        DEPLOY_TYPE="FULL (TEST)"
    fi
else
    TARGET_SITE="$LIVE_SITE"
    if [ "$IS_FIX_PERMISSIONS_ONLY" = true ]; then
        DEPLOY_TYPE="PERMISSIONS-ONLY (LIVE)"
    elif [ "$IS_THEME_ONLY" = true ]; then
        DEPLOY_TYPE="THEME-ONLY (LIVE)"
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

# SAFETY CHECK: Ensure target site exists for permissions-only or theme-only operations
if ([ "$IS_FIX_PERMISSIONS_ONLY" = true ] || [ "$IS_THEME_ONLY" = true ]) && [[ ! -d "/var/www/html/$TARGET_SITE" ]]; then
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
    if [ "$IS_THEME_ONLY" = true ]; then
        echo "This will:"
        echo "1. Deploy fresh theme/plugins to TEST site: $TARGET_SITE"
        echo "2. NO database copy or code deployment"
        echo "========================================="
        read -p "Continue with THEME-ONLY deployment to TEST site? (y/N): " -n 1 -r
    else
        echo "This will:"
        echo "1. Copy database: $LIVE_SITE -> $TARGET_SITE"
        echo "2. Deploy code to: $deploy_directory"
        echo "3. Deploy fresh theme/plugins from repository"
        echo "========================================="
        read -p "Continue with FULL deployment to TEST site? (y/N): " -n 1 -r
    fi
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled."
        exit 0
    fi
else
    if [ "$IS_THEME_ONLY" = true ]; then
        echo "This will deploy fresh theme/plugins to LIVE site: $TARGET_SITE"
        echo "NO code deployment or database changes will be made"
        echo "========================================="
        read -p "Continue with THEME-ONLY deployment to LIVE site? (y/N): " -n 1 -r
    else
        echo "This will deploy to the LIVE site: $TARGET_SITE"
        echo "This includes fresh theme/plugins from repository"
        echo "========================================="
        read -p "Continue with FULL LIVE deployment? (y/N): " -n 1 -r
    fi
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

# CREATE ADDITIONAL SUBDIRECTORIES IF NEEDED (test deploys only, not theme-only)
if [ "$IS_TEST_DEPLOY" = true ] && [ "$IS_THEME_ONLY" = false ]; then
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

# DEPLOY THEMES AND PLUGINS FROM REPOSITORY FIRST
deploy_theme_plugin "$TARGET_SITE"

# IF THEME-ONLY DEPLOYMENT, SKIP THE REST
if [ "$IS_THEME_ONLY" = true ]; then
    # Fix permissions after theme deployment
    fix_permissions "$TARGET_SITE"
    
    echo "========================================="
    if [ "$IS_TEST_DEPLOY" = true ]; then
        echo "SUCCESS: Theme-only deployment to test site '$TARGET_SITE' complete!"
        echo "Test site should be available at:"
        echo "  https://test.${LIVE_SITE}.com (if subdomain configured)"
    else
        echo "SUCCESS: Theme-only deployment to live site '$TARGET_SITE' complete!"
    fi
    echo "Permissions have been fixed automatically."
    echo "========================================="
    exit 0
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

# DO THE MAIN CODE DEPLOY (with smart theme/plugin merging)
echo "Deploying main application code..."
cd /var/www/html/$TARGET_SITE/public_html_stage || {
    echo "ERROR: Could not change to staging directory"
    exit 1
}

for item in *; do
    if [ "$item" = "theme" ]; then
        echo "Smart merging themes (preserving ALL existing themes, adding only new ones)..."
        
        # Ensure theme directory exists in target
        mkdir -p "/var/www/html/$TARGET_SITE/public_html/theme"
        
        # FIRST: Restore all themes from backup (these include theme repository themes + any custom themes)
        if [[ -d "/var/www/html/$TARGET_SITE/public_html_last/theme" ]]; then
            echo "  Restoring all existing themes from backup..."
            cp -r /var/www/html/$TARGET_SITE/public_html_last/theme/* "/var/www/html/$TARGET_SITE/public_html/theme/" 2>/dev/null || true
        fi
        
        # SECOND: Add any new themes from main code repo (if they don't already exist)
        if [ -d "theme" ]; then
            for theme_path in theme/*/; do
                if [ -d "$theme_path" ]; then
                    theme_name=$(basename "$theme_path")
                    target_theme_path="/var/www/html/$TARGET_SITE/public_html/theme/$theme_name"
                    
                    if [ -d "$target_theme_path" ]; then
                        echo "  PRESERVING existing theme: $theme_name (not overwriting)"
                    else
                        echo "  Adding new theme from main repo: $theme_name"
                        cp -r "$theme_path" "/var/www/html/$TARGET_SITE/public_html/theme/" || {
                            echo "ERROR: Failed to copy theme $theme_name"
                            exit 1
                        }
                        # Fix permissions for newly added theme
                        chown -R www-data:user1 "$target_theme_path" 2>/dev/null || true
                        chmod -R 775 "$target_theme_path" 2>/dev/null || true
                    fi
                fi
            done
        fi
    elif [ "$item" = "plugins" ]; then
        echo "Smart merging plugins (preserving ALL existing plugins, adding only new ones)..."
        
        # Ensure plugins directory exists in target
        mkdir -p "/var/www/html/$TARGET_SITE/public_html/plugins"
        
        # FIRST: Restore all plugins from backup (these include theme repository plugins + any custom plugins)
        if [[ -d "/var/www/html/$TARGET_SITE/public_html_last/plugins" ]]; then
            echo "  Restoring all existing plugins from backup..."
            cp -r /var/www/html/$TARGET_SITE/public_html_last/plugins/* "/var/www/html/$TARGET_SITE/public_html/plugins/" 2>/dev/null || true
        fi
        
        # SECOND: Add any new plugins from main code repo (if they don't already exist)
        if [ -d "plugins" ]; then
            for plugin_path in plugins/*/; do
                if [ -d "$plugin_path" ]; then
                    plugin_name=$(basename "$plugin_path")
                    target_plugin_path="/var/www/html/$TARGET_SITE/public_html/plugins/$plugin_name"
                    
                    if [ -d "$target_plugin_path" ]; then
                        echo "  PRESERVING existing plugin: $plugin_name (not overwriting)"
                    else
                        echo "  Adding new plugin from main repo: $plugin_name"
                        cp -r "$plugin_path" "/var/www/html/$TARGET_SITE/public_html/plugins/" || {
                            echo "ERROR: Failed to copy plugin $plugin_name"
                            exit 1
                        }
                        # Fix permissions for newly added plugin
                        chown -R www-data:user1 "$target_plugin_path" 2>/dev/null || true
                        chmod -R 775 "$target_plugin_path" 2>/dev/null || true
                    fi
                fi
            done
        fi
    else
        echo "Copying $item..."
        cp -r "$item" "/var/www/html/$TARGET_SITE/public_html/" || {
            echo "ERROR: Failed to copy $item"
            exit 1
        }
    fi
done
cd - > /dev/null

# Note: Themes and plugins use SAFE smart merging - existing themes/plugins are NEVER overwritten, only new ones are added

# FIX PERMISSIONS AFTER DEPLOYMENT
echo "Fixing permissions after deployment..."
fix_permissions "$TARGET_SITE"

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
        echo "Rolling back to previous deployment..."
        # MODIFIED: Don't clean up staging directory on rollback
        # rm -rf /var/www/html/$TARGET_SITE/public_html_stage
        
        # Remove current broken deployment
        rm -rf /var/www/html/$TARGET_SITE/public_html/*
        
        # Restore from backup
        mv /var/www/html/$TARGET_SITE/public_html_last/* /var/www/html/$TARGET_SITE/public_html/ 2>/dev/null || true
        echo "Rollback completed."
    else
        echo "This appears to be an initial deployment - no previous version to rollback to."
        echo "Keeping current deployment in place for debugging."
        # MODIFIED: Don't clean up staging directory on initial deployment failure
        # rm -rf /var/www/html/$TARGET_SITE/public_html_stage
    fi
    
    exit 1
fi

/usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php
returnvalue=$?

if [[ "$returnvalue" != 1 ]]; then
    echo "ERROR: Database update failed."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    echo "DEBUGGING: You can examine the staged files to understand what was deployed."
    
    # Check if rollback is disabled
    if [ "$DISABLE_ROLLBACK" = true ]; then
        echo "ROLLBACK DISABLED: Keeping current deployment in place for debugging."
        echo "Manual intervention required to fix the database update issue."
        exit 1
    fi
    
    # Check if this is an initial deployment (no backup to restore)
    if [[ -d /var/www/html/$TARGET_SITE/public_html_last ]] && [[ "$(ls -A /var/www/html/$TARGET_SITE/public_html_last 2>/dev/null)" ]]; then
        echo "Rolling back to previous deployment..."
        # MODIFIED: Don't clean up staging directory on rollback
        # rm -rf /var/www/html/$TARGET_SITE/public_html_stage
        
        # Remove current broken deployment
        rm -rf /var/www/html/$TARGET_SITE/public_html/*
        
        # Restore from backup
        mv /var/www/html/$TARGET_SITE/public_html_last/* /var/www/html/$TARGET_SITE/public_html/ 2>/dev/null || true
        echo "Rollback completed."
    else
        echo "This appears to be an initial deployment - no previous version to rollback to."
        echo "Keeping current deployment in place for debugging."
        echo "You may need to manually fix the database update issue."
        # MODIFIED: Don't clean up staging directory on initial deployment failure
        # rm -rf /var/www/html/$TARGET_SITE/public_html_stage
    fi
    
    exit 1
else
    echo "Database update successful."
fi

# CLEANUP: Remove staging directory after successful deployment ONLY
echo "Cleaning up staging directory..."
rm -rf /var/www/html/$TARGET_SITE/public_html_stage

echo "========================================="
if [ "$IS_TEST_DEPLOY" = true ]; then
    echo "SUCCESS: Full deployment to test site '$TARGET_SITE' complete!"
    echo "Test site should be available at:"
    echo "  https://test.${LIVE_SITE}.com (if subdomain configured)"
else
    echo "SUCCESS: Full deployment to live site '$TARGET_SITE' complete!"
fi
echo "Permissions have been fixed automatically."
echo "========================================"