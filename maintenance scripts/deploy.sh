#!/usr/bin/env bash
#version 2.1 - Unified deploy script with theme/plugin deployment

GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

# Theme/Plugin repository settings
THEME_PLUGIN_USER="getjoinery"
THEME_PLUGIN_TOKEN="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"
THEME_PLUGIN_REPO_URL="https://${THEME_PLUGIN_USER}:${THEME_PLUGIN_TOKEN}@github.com/getjoinery/joinery.git"

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Full deploy to live site"
    echo "  $0 [site_name] --test             # Full deploy to test site (site_name_test)"
    echo "  $0 [site_name] --theme-only       # Deploy only themes/plugins to live site"
    echo "  $0 [site_name] --theme-only --test # Deploy only themes/plugins to test site"
    echo "  $0 [site_name] --fix-permissions  # Fix permissions only (no deployment)"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Full deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Full deploy to getjoinery_test"
    echo "  $0 getjoinery --theme-only        # Update only themes/plugins on getjoinery"
    echo "  $0 getjoinery --fix-permissions   # Fix permissions on getjoinery"
    echo ""
    echo "Note: Permission fixes work best when run with sudo"
    echo "Test site will be available at: https://test.[domain].com"
}

# Function to deploy themes and plugins
deploy_theme_plugin() {
    local target_site="$1"
    local staging_dir="/var/www/html/$target_site"
    
    echo "Deploying themes and plugins for $target_site..."
    
    # DEPLOY THEMES
    echo "Setting up theme deployment..."
    rm -rf "$staging_dir/theme_stage"
    mkdir -p "$staging_dir/theme_stage"
    chown -R :www-data "$staging_dir/theme_stage" 2>/dev/null || true
    chmod -R g+rw "$staging_dir/theme_stage" 2>/dev/null || true

    # Clone repo for themes
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$staging_dir/theme_stage"
    cd "$staging_dir/theme_stage" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set theme/
    git pull origin main
    rm -rf .git
    cd - > /dev/null

    # DEPLOY PLUGINS
    echo "Setting up plugin deployment..."
    rm -rf "$staging_dir/plugins_stage"
    mkdir -p "$staging_dir/plugins_stage"
    chown -R :www-data "$staging_dir/plugins_stage" 2>/dev/null || true
    chmod -R g+rw "$staging_dir/plugins_stage" 2>/dev/null || true

    # Clone repo for plugins
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$staging_dir/plugins_stage"
    cd "$staging_dir/plugins_stage" || exit 1
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set plugins/
    git pull origin main
    rm -rf .git
    cd - > /dev/null

    # Deploy themes
    if [[ -d "$staging_dir/theme_stage/theme" ]]; then
        echo "Deploying themes..."
        rm -rf "$staging_dir/theme_old"
        if [[ -d "$staging_dir/theme" ]]; then
            mv "$staging_dir/theme" "$staging_dir/theme_old"
        fi
        mv "$staging_dir/theme_stage/theme" "$staging_dir/theme"
        chown -R :www-data "$staging_dir/theme" 2>/dev/null || true
        chmod -R g+rw "$staging_dir/theme" 2>/dev/null || true
    fi

    # Deploy plugins
    if [[ -d "$staging_dir/plugins_stage/plugins" ]]; then
        echo "Deploying plugins..."
        rm -rf "$staging_dir/plugins_old"
        if [[ -d "$staging_dir/plugins" ]]; then
            mv "$staging_dir/plugins" "$staging_dir/plugins_old"
        fi
        mv "$staging_dir/plugins_stage/plugins" "$staging_dir/plugins"
        chown -R :www-data "$staging_dir/plugins" 2>/dev/null || true
        chmod -R g+rw "$staging_dir/plugins" 2>/dev/null || true
    fi

    # Cleanup staging directories
    echo "Cleaning up theme/plugin staging directories..."
    rm -rf "$staging_dir/theme_stage"
    rm -rf "$staging_dir/plugins_stage"
    
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

# CREATE THEME AND PLUGIN DIRECTORIES IF THEY DON'T EXIST
if [[ ! -d "/var/www/html/$TARGET_SITE/theme" ]]; then
    echo "Creating theme directory..."
    mkdir -p "/var/www/html/$TARGET_SITE/theme"
fi

if [[ ! -d "/var/www/html/$TARGET_SITE/plugins" ]]; then
    echo "Creating plugins directory..."
    mkdir -p "/var/www/html/$TARGET_SITE/plugins"
fi

# CREATE ADDITIONAL SUBDIRECTORIES IF NEEDED (test deploys only, not theme-only)
if [ "$IS_TEST_DEPLOY" = true ] && [ "$IS_THEME_ONLY" = false ]; then
    for dir in public_html logs static_files uploads; do
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

# DEPLOY THEMES AND PLUGINS FROM REPOSITORY
deploy_theme_plugin "$TARGET_SITE"

# IF THEME-ONLY DEPLOYMENT, SKIP THE REST
if [ "$IS_THEME_ONLY" = true ]; then
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
mv /var/www/html/$TARGET_SITE/public_html/* /var/www/html/$TARGET_SITE/public_html_last

# COPY THE THEME FILES
if [[ -d /var/www/html/$TARGET_SITE/theme ]]; then
    cp -r /var/www/html/$TARGET_SITE/theme /var/www/html/$TARGET_SITE/public_html_stage
fi

# COPY THE PLUGIN FILES
if [[ -d /var/www/html/$TARGET_SITE/plugins ]]; then
    cp -r /var/www/html/$TARGET_SITE/plugins /var/www/html/$TARGET_SITE/public_html_stage
fi

# DO THE DEPLOY
cp -r /var/www/html/$TARGET_SITE/public_html_stage/* /var/www/html/$TARGET_SITE/public_html

# FIX PERMISSIONS AFTER DEPLOYMENT
echo "Fixing permissions after deployment..."
fix_permissions "$TARGET_SITE"

# Check if update_database.php exists
if [[ ! -f /var/www/html/$TARGET_SITE/public_html/utils/update_database.php ]]; then
    echo "ERROR: /var/www/html/$TARGET_SITE/public_html/utils/update_database.php does not exist. Aborting deploy."
    # Clean up staging directory before reverting
    rm -rf /var/www/html/$TARGET_SITE/public_html_stage
    mv /var/www/html/$TARGET_SITE/public_html_last/* /var/www/html/$TARGET_SITE/public_html
    exit 1
fi

/usr/bin/php /var/www/html/$TARGET_SITE/public_html/utils/update_database.php
returnvalue=$?

if [[ "$returnvalue" != 1 ]]; then
    echo "ERROR: Database update failed. Reverting deploy"
    # Clean up staging directory before reverting
    rm -rf /var/www/html/$TARGET_SITE/public_html_stage
    mv /var/www/html/$TARGET_SITE/public_html_last/* /var/www/html/$TARGET_SITE/public_html
    exit 1
else
    echo "Database update successful."
fi

# CLEANUP: Remove staging directory after successful deployment
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
echo "========================================="