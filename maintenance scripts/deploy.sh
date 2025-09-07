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
DEPLOY_VERSION="3.0"

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

# Function to merge themes and plugins into public_html after main code deployment
preserve_custom_themes_plugins() {
    local target_site="$1"
    local site_root="/var/www/html/$target_site"
    local public_html_dir="$site_root/public_html"
    local staging_dir="$site_root/public_html_stage"
    
    local theme_count=0
    local theme_added=0
    local theme_updated=0
    local theme_preserved=0
    
    # SHOW STATUS FOR ALL THEMES (STOCK AND CUSTOM)
    if [[ -d "$staging_dir/theme" ]]; then
        for staging_theme_path in "$staging_dir/theme"/*/; do
            if [[ -d "$staging_theme_path" ]]; then
                theme_name=$(basename "$staging_theme_path")
                staging_manifest="$staging_theme_path/theme.json"
                theme_version=$(get_json_value "$staging_manifest" "version" "unknown")
                theme_description=$(get_json_value "$staging_manifest" "description" "")
                ((theme_count++))
                
                # Check if theme exists in previous deployment
                existing_theme_path="$site_root/public_html_last/theme/$theme_name"
                if [[ -d "$existing_theme_path" ]]; then
                    existing_manifest="$existing_theme_path/theme.json"
                    if [[ -f "$existing_manifest" ]]; then
                        is_stock=$(get_json_value "$existing_manifest" "is_stock" "true")
                        if [[ "$is_stock" == "false" ]]; then
                            verbose_echo "  🔒 PRESERVING custom theme: $theme_name v$theme_version - $theme_description"
                            ((theme_preserved++))
                            # Copy custom theme over stock version in staging
                            cp -r "$existing_theme_path" "$staging_dir/theme/" || {
                                echo "ERROR: Failed to preserve custom theme $theme_name"
                                return 1
                            }
                        else
                            verbose_echo "  📦 UPDATING stock theme: $theme_name v$theme_version - $theme_description"
                            ((theme_updated++))
                        fi
                    else
                        verbose_echo "  📦 UPDATING theme: $theme_name v$theme_version - $theme_description"
                        ((theme_updated++))
                    fi
                else
                    verbose_echo "  ✨ ADDING new theme: $theme_name v$theme_version - $theme_description"
                    ((theme_added++))
                fi
            fi
        done
    fi
    
    local plugin_count=0
    local plugin_added=0
    local plugin_updated=0
    local plugin_preserved=0
    
    # SHOW STATUS FOR ALL PLUGINS (STOCK AND CUSTOM)
    if [[ -d "$staging_dir/plugins" ]]; then
        for staging_plugin_path in "$staging_dir/plugins"/*/; do
            if [[ -d "$staging_plugin_path" ]]; then
                plugin_name=$(basename "$staging_plugin_path")
                staging_manifest="$staging_plugin_path/plugin.json"
                plugin_version=$(get_json_value "$staging_manifest" "version" "unknown")
                plugin_description=$(get_json_value "$staging_manifest" "description" "")
                ((plugin_count++))
                
                # Check if plugin exists in previous deployment
                existing_plugin_path="$site_root/public_html_last/plugins/$plugin_name"
                if [[ -d "$existing_plugin_path" ]]; then
                    existing_manifest="$existing_plugin_path/plugin.json"
                    if [[ -f "$existing_manifest" ]]; then
                        is_stock=$(get_json_value "$existing_manifest" "is_stock" "true")
                        if [[ "$is_stock" == "false" ]]; then
                            verbose_echo "  🔒 PRESERVING custom plugin: $plugin_name v$plugin_version - $plugin_description"
                            ((plugin_preserved++))
                            # Copy custom plugin over stock version in staging  
                            cp -r "$existing_plugin_path" "$staging_dir/plugins/" || {
                                echo "ERROR: Failed to preserve custom plugin $plugin_name"
                                return 1
                            }
                        else
                            verbose_echo "  🔌 UPDATING stock plugin: $plugin_name v$plugin_version - $plugin_description"
                            ((plugin_updated++))
                        fi
                    else
                        verbose_echo "  🔌 UPDATING plugin: $plugin_name v$plugin_version - $plugin_description"
                        ((plugin_updated++))
                    fi
                else
                    verbose_echo "  ⚡ ADDING new plugin: $plugin_name v$plugin_version - $plugin_description"
                    ((plugin_added++))
                fi
            fi
        done
    fi
    
    # Show summary
    local theme_msg=""
    local plugin_msg=""
    
    if [ $theme_count -gt 0 ]; then
        if [ $theme_updated -gt 0 ] && [ $theme_added -eq 0 ] && [ $theme_preserved -eq 0 ]; then
            theme_msg="Updated $theme_updated themes"
        elif [ $theme_added -gt 0 ] && [ $theme_updated -eq 0 ] && [ $theme_preserved -eq 0 ]; then
            theme_msg="Added $theme_added themes"
        else
            local parts=()
            [ $theme_added -gt 0 ] && parts+=("$theme_added new")
            [ $theme_updated -gt 0 ] && parts+=("$theme_updated updated")  
            [ $theme_preserved -gt 0 ] && parts+=("$theme_preserved preserved")
            theme_msg="Themes: $(IFS=', '; echo "${parts[*]}")"
        fi
    fi
    
    if [ $plugin_count -gt 0 ]; then
        if [ $plugin_updated -gt 0 ] && [ $plugin_added -eq 0 ] && [ $plugin_preserved -eq 0 ]; then
            plugin_msg="Updated $plugin_updated plugins"
        elif [ $plugin_added -gt 0 ] && [ $plugin_updated -eq 0 ] && [ $plugin_preserved -eq 0 ]; then
            plugin_msg="Added $plugin_added plugins"
        else
            local parts=()
            [ $plugin_added -gt 0 ] && parts+=("$plugin_added new")
            [ $plugin_updated -gt 0 ] && parts+=("$plugin_updated updated")
            [ $plugin_preserved -gt 0 ] && parts+=("$plugin_preserved preserved")
            plugin_msg="Plugins: $(IFS=', '; echo "${parts[*]}")"
        fi
    fi
    
    if [ -n "$theme_msg" ] && [ -n "$plugin_msg" ]; then
        echo "✓ $theme_msg, $plugin_msg"
    elif [ -n "$theme_msg" ]; then
        echo "✓ $theme_msg"
    elif [ -n "$plugin_msg" ]; then
        echo "✓ $plugin_msg" 
    fi
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

# PRESERVE CUSTOM THEMES AND PLUGINS FROM EXISTING PUBLIC_HTML
verbose_echo "Preserving custom themes and plugins from existing deployment..."
if ! preserve_custom_themes_plugins "$TARGET_SITE"; then
    echo "ERROR: Custom theme/plugin preservation failed."
    exit 1
fi

# RUN PRE-DEPLOYMENT TESTS ON STAGING
echo "Running pre-deployment tests on staging environment..."

# PHP SYNTAX VALIDATION ON STAGING
verbose_echo "Validating PHP syntax on staging files..."
php_error_count=0
php_file_count=0
while IFS= read -r -d '' file; do
    ((php_file_count++))
    if ! php -l "$file" >/dev/null 2>&1; then
        echo "SYNTAX ERROR in: $file"
        php -l "$file"
        ((php_error_count++))
    else
        verbose_echo "  ✓ $file"
    fi
done < <(find "/var/www/html/$TARGET_SITE/public_html_stage" -name "*.php" -print0)

if [[ $php_error_count -gt 0 ]]; then
    echo "ERROR: $php_error_count PHP syntax errors found in staging."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    exit 1
fi
verbose_echo "✓ PHP syntax validation passed ($php_file_count files checked)"

# PLUGIN LOADING TEST ON STAGING
verbose_echo "Testing plugin class file loading on staging..."
plugin_error_count=0
plugin_file_count=0
while IFS= read -r -d '' file; do
    ((plugin_file_count++))
    # Test if the file can be included without errors
    # CRITICAL: Set working directory and document root so PathHelper works correctly
    if ! php -r "
        \$_SERVER['DOCUMENT_ROOT'] = '/var/www/html/$TARGET_SITE/public_html_stage';
        chdir('/var/www/html/$TARGET_SITE/public_html_stage');
        
        error_reporting(E_ALL);
        set_error_handler(function(\$errno, \$errstr) {
            // Only treat actual errors and warnings as failures, ignore deprecation notices
            if (\$errno & (E_ERROR | E_WARNING | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
                echo \"INCLUDE ERROR: \$errstr\n\";
                exit(1);
            }
            // Ignore E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_STRICT
            return true;
        });
        
        try {
            // Bootstrap PathHelper first so plugin files can use it
            require_once('/var/www/html/$TARGET_SITE/public_html_stage/includes/PathHelper.php');
            
            // Now test the plugin file
            include_once '$file';
        } catch (Exception \$e) {
            echo 'EXCEPTION in $file: ' . \$e->getMessage() . \"\n\";
            exit(1);
        }
    " >/dev/null 2>&1; then
        echo "PLUGIN LOADING ERROR in: $file"
        ((plugin_error_count++))
    else
        verbose_echo "  ✓ $(basename $file)"
    fi
done < <(find "/var/www/html/$TARGET_SITE/public_html_stage/plugins" -name "*_class.php" -print0 2>/dev/null)

if [[ $plugin_error_count -gt 0 ]]; then
    echo "ERROR: $plugin_error_count plugin loading errors found in staging."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    exit 1
fi
verbose_echo "✓ Plugin loading test passed ($plugin_file_count files checked)"

# MODEL TESTS ON STAGING
verbose_echo "Running model tests on staging..."
if ! php -r "
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
            echo 'Model tests failed - check output for details';
            exit(1);
        }
        
        echo 'Model tests completed successfully';
    } catch (Exception \$e) {
        ob_end_clean();
        echo 'Model test error: ' . \$e->getMessage();
        exit(1);
    }
" > /dev/null 2>&1; then
    echo "ERROR: Model tests failed in staging."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    exit 1
fi
verbose_echo "✓ Model tests passed on staging"

# APPLICATION BOOTSTRAP TEST ON STAGING
verbose_echo "Testing application bootstrap on staging..."
if ! php -r "
    \$_SERVER['DOCUMENT_ROOT'] = '/var/www/html/$TARGET_SITE/public_html_stage';
    chdir('/var/www/html/$TARGET_SITE/public_html_stage');
    
    // Test core includes
    try {
        require_once('includes/PathHelper.php');
        PathHelper::requireOnce('includes/Globalvars.php');
        PathHelper::requireOnce('includes/DbConnector.php');
        echo 'Bootstrap test completed successfully';
    } catch (Exception \$e) {
        echo 'Bootstrap error: ' . \$e->getMessage();
        exit(1);
    }
" >/dev/null 2>&1; then
    echo "ERROR: Application bootstrap test failed in staging."
    echo "DEBUGGING: Staging directory preserved at: /var/www/html/$TARGET_SITE/public_html_stage"
    exit 1
fi
verbose_echo "✓ Application bootstrap test passed on staging"

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

if [ "$VERBOSE" = true ]; then
    /usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php --upgrade --verbose
else
    /usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php --upgrade
fi
returnvalue=$?

if [[ "$returnvalue" != 1 ]]; then
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