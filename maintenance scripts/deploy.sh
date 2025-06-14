#!/usr/bin/env bash
#version 2.0 - Unified deploy script for live and test sites

GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

# Function to show usage
show_usage() {
    echo "Usage:"
    echo "  $0 [site_name]                    # Deploy to live site"
    echo "  $0 [site_name] --test             # Deploy to test site (site_name_test)"
    echo ""
    echo "Examples:"
    echo "  $0 getjoinery                     # Deploy to getjoinery (live)"
    echo "  $0 getjoinery --test              # Deploy to getjoinery_test"
    echo ""
    echo "Test site will be available at: https://test.[domain].com"
}

# Parse arguments
if [ "$1" == "" ] || [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_usage
    exit 1
fi

LIVE_SITE="$1"
IS_TEST_DEPLOY=false

# Check for test flag
if [ "$2" == "--test" ]; then
    IS_TEST_DEPLOY=true
    TARGET_SITE="${LIVE_SITE}_test"
    DEPLOY_TYPE="TEST"
else
    TARGET_SITE="$LIVE_SITE"
    DEPLOY_TYPE="LIVE"
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
    echo "This will:"
    echo "1. Copy database: $LIVE_SITE -> $TARGET_SITE"
    echo "2. Deploy code to: $deploy_directory"
    echo "3. Copy theme/plugins from live site if needed"
    echo "========================================="
    read -p "Continue with TEST deployment? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled."
        exit 0
    fi
else
    echo "This will deploy to the LIVE site: $TARGET_SITE"
    echo "========================================="
    read -p "Continue with LIVE deployment? (y/N): " -n 1 -r
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

# For live deploys, directory must exist
if [ "$IS_TEST_DEPLOY" = false ] && [[ ! -d $deploy_directory ]]; then
    echo "ERROR: Deploy directory $deploy_directory does not exist."
    exit 1
fi

# CHECK TO MAKE SURE EVERYTHING IS THERE
if [[ ! -d "/var/www/html/$TARGET_SITE/theme" ]]; then
    if [ "$IS_TEST_DEPLOY" = true ]; then
        echo "Creating theme directory..."
        mkdir -p "/var/www/html/$TARGET_SITE/theme"
    else
        echo "ERROR: Theme directory does not exist"
        exit 1
    fi
fi

if [[ ! "$(ls -A /var/www/html/$TARGET_SITE/theme)" ]]; then
    if [ "$IS_TEST_DEPLOY" = true ]; then
        echo "Theme directory is empty - will copy from live site"
    else
        echo "ERROR: Theme directory is empty"
        exit 1
    fi
fi

if [[ ! -d "/var/www/html/$TARGET_SITE/plugins" ]]; then
    if [ "$IS_TEST_DEPLOY" = true ]; then
        echo "Creating plugins directory..."
        mkdir -p "/var/www/html/$TARGET_SITE/plugins"
    else
        echo "ERROR: Plugin directory does not exist"
        exit 1
    fi
fi

# CREATE ADDITIONAL SUBDIRECTORIES IF NEEDED (test deploys only)
if [ "$IS_TEST_DEPLOY" = true ]; then
    for dir in public_html logs static_files uploads; do
        if [[ ! -d "/var/www/html/$TARGET_SITE/$dir" ]]; then
            echo "Creating $dir directory..."
            mkdir -p "/var/www/html/$TARGET_SITE/$dir"
        fi
    done
    
    # COPY THEME AND PLUGINS FROM LIVE SITE IF TEST DIRECTORIES ARE EMPTY
    if [[ ! "$(ls -A /var/www/html/$TARGET_SITE/theme)" ]] && [[ -d "/var/www/html/$LIVE_SITE/theme" ]]; then
        echo "Copying theme from live site $LIVE_SITE..."
        cp -r /var/www/html/$LIVE_SITE/theme/* /var/www/html/$TARGET_SITE/theme/
    fi

    if [[ ! "$(ls -A /var/www/html/$TARGET_SITE/plugins)" ]] && [[ -d "/var/www/html/$LIVE_SITE/plugins" ]]; then
        echo "Copying plugins from live site $LIVE_SITE..."
        cp -r /var/www/html/$LIVE_SITE/plugins/* /var/www/html/$TARGET_SITE/plugins/
    fi
    
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
    echo "SUCCESS: Deploy to test site '$TARGET_SITE' complete!"
    echo "Test site should be available at:"
    echo "  https://test.${LIVE_SITE}.com (if subdomain configured)"
else
    echo "SUCCESS: Deploy to live site '$TARGET_SITE' complete!"
fi
echo "========================================="