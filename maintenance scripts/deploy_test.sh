#!/usr/bin/env bash
#version 1.3

GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

if [ "$1" == "" ]
then
echo "You must pass the live site name as the first argument."
echo "Usage: ./deploy_test.sh [live_site_name]"
echo "Example: ./deploy_test.sh getjoinery"
echo "This will create/deploy to: getjoinery_test"
echo "Test site will be available at: https://test.[domain].com"
exit 1
fi

live_site="$1"
test_site="${live_site}_test"

# SAFETY CHECK: Prevent accidental overwrite of live site
if [ "$live_site" = "$test_site" ]; then
    echo "ERROR: Safety check failed. Live site and test site cannot be the same."
    echo "Live site: $live_site"
    echo "Test site: $test_site"
    exit 1
fi

# SAFETY CHECK: Ensure live site exists
if [[ ! -d "/var/www/html/$live_site" ]]; then
    echo "ERROR: Live site directory '/var/www/html/$live_site' does not exist."
    echo "Please verify the live site name is correct."
    exit 1
fi

# CONFIRMATION: Show what will happen
echo "==================================="
echo "DEPLOYMENT CONFIGURATION:"
echo "Live site: $live_site"
echo "Test site: $test_site"
echo "Test directory: /var/www/html/$test_site"
echo "==================================="
echo "This will:"
echo "1. Copy database: $live_site -> $test_site"
echo "2. Deploy code to: /var/www/html/$test_site"
echo "3. Copy theme/plugins from live site if needed"
echo "==================================="
read -p "Continue with deployment? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Deployment cancelled."
    exit 0
fi

deploy_directory="/var/www/html/$test_site"

# CREATE DEPLOY DIRECTORY AND STRUCTURE IF IT DOESN'T EXIST
if [[ ! -d $deploy_directory ]]
then
    echo "Deploy directory $deploy_directory does not exist. Creating it..."
    mkdir -p "$deploy_directory"
    mkdir -p "$deploy_directory/public_html"
    mkdir -p "$deploy_directory/logs"
    mkdir -p "$deploy_directory/theme"
    mkdir -p "$deploy_directory/plugins"
    mkdir -p "$deploy_directory/static_files"
    mkdir -p "$deploy_directory/uploads"
    echo "Created directory structure for $test_site"
fi

# CREATE SUBDIRECTORIES IF THEY DON'T EXIST
if [[ ! -d "/var/www/html/$test_site/theme" ]]
then
  echo "Creating theme directory..."
  mkdir -p "/var/www/html/$test_site/theme"
fi

if [[ ! -d "/var/www/html/$test_site/plugins" ]]
then
  echo "Creating plugins directory..."
  mkdir -p "/var/www/html/$test_site/plugins"
fi

if [[ ! -d "/var/www/html/$test_site/public_html" ]]
then
  echo "Creating public_html directory..."
  mkdir -p "/var/www/html/$test_site/public_html"
fi

if [[ ! -d "/var/www/html/$test_site/logs" ]]
then
  echo "Creating logs directory..."
  mkdir -p "/var/www/html/$test_site/logs"
fi

# COPY THEME AND PLUGINS FROM LIVE SITE IF TEST DIRECTORIES ARE EMPTY
if [[ ! "$(ls -A /var/www/html/$test_site/theme)" ]] && [[ -d "/var/www/html/$live_site/theme" ]]
then
  echo "Copying theme from live site $live_site..."
  cp -r /var/www/html/$live_site/theme/* /var/www/html/$test_site/theme/
fi

if [[ ! "$(ls -A /var/www/html/$test_site/plugins)" ]] && [[ -d "/var/www/html/$live_site/plugins" ]]
then
  echo "Copying plugins from live site $live_site..."
  cp -r /var/www/html/$live_site/plugins/* /var/www/html/$test_site/plugins/
fi

# COPY DATABASE FROM LIVE SITE TO TEST SITE
echo "Copying database from $live_site to $test_site..."
if [[ -f "./copy_database.sh" ]]; then
    ./copy_database.sh "$live_site" "$test_site"
    if [ $? -ne 0 ]; then
        echo "ERROR: Database copy failed. Aborting deploy."
        exit 1
    fi
    echo "Database copy successful."
else
    echo "ERROR: copy_database.sh not found in current directory. Aborting deploy."
    exit 1
fi

#CLEAR THE STAGING FOLDER AND RECREATE
rm -rf /var/www/html/$test_site/public_html_stage
mkdir /var/www/html/$test_site/public_html_stage

# CLONE THE REPO DIRECTLY INTO theme_stage
git clone --no-checkout "$REPO_URL" /var/www/html/$test_site/public_html_stage

# MOVE INTO THE CLONED DIRECTORY
cd /var/www/html/$test_site/public_html_stage || exit 1

# ENABLE SPARSE CHECKOUT
#git config core.sparseCheckout true

# SPECIFY WHICH DIRECTORIES TO CHECKOUT
#git sparse-checkout init --cone
#git sparse-checkout set theme/

# PULL ONLY THE SPECIFIED FOLDERS
git pull origin main  # Change 'main' if your repo uses 'master' or another branch
git checkout main
rm -rf .git

#PULL THE FILES
#git init /var/www/html/$test_site/public_html_stage

#echo "Enter git credentials."
#if ! git remote add origin https://github.com/Tunnell-Software/membership.git
#then
#git remote set-url origin https://github.com/Tunnell-Software/membership.git
#fi
#git pull origin main

#CLEAR THE LAST FOLDER AND SAVE CURRENT TO LAST
rm -rf /var/www/html/$test_site/public_html_last
mkdir /var/www/html/$test_site/public_html_last
mv /var/www/html/$test_site/public_html/* /var/www/html/$test_site/public_html_last

#MAKE THE ARCHIVES FOR UPGRADES...THIS REQUIRES SUDO
#rm /var/www/html/$test_site/static_files/*.upg.zip
#: > /var/www/html/$test_site/public_html/version_hash.md5
#find /var/www/html/$test_site/public_html -type f -exec md5sum {} \; > /var/www/html/$test_site/public_html/version_hash.md5
#tar --exclude-vcs -cf /var/www/html/$test_site/static_files/current_upgrade.upg.tar -C /var/www/html/$test_site public_html_stage
#zip -qr /var/www/html/$test_site/static_files/current_upgrade.upg.zip /var/www/html/$test_site/public_html_stage -x '*.git*'

#COPY THE THEME FILES
if [[ -d /var/www/html/$test_site/theme ]]
then
	#REMOVE DEFAULT THEME AND COPY ALL THEMES
	#rm -rf /var/www/html/$test_site/public_html_stage/theme
	cp -r /var/www/html/$test_site/theme /var/www/html/$test_site/public_html_stage
fi

#COPY THE PLUGIN FILES
if [[ -d /var/www/html/$test_site/plugins ]]
then
	#REMOVE DEFAULT THEME AND COPY ALL THEMES
	#rm -rf /var/www/html/$test_site/public_html_stage/plugins
	cp -r /var/www/html/$test_site/plugins /var/www/html/$test_site/public_html_stage
fi

#DO THE DEPLOY
cp -r /var/www/html/$test_site/public_html_stage/* /var/www/html/$test_site/public_html
rm -rf /var/www/html/$test_site/public_html_stage

#Must run as sudo
#chown -R user1 /var/www/html/$test_site
#chgrp -R www-data /var/www/html/$test_site
#chmod -R 775 /var/www/html/$test_site/theme
#chmod -R 777 /var/www/html/$test_site/uploads
#chown -R www-data /var/www/html/$test_site/uploads

# Check if update_database.php exists
if [[ ! -f /var/www/html/$test_site/public_html/utils/update_database.php ]]; then
    echo "ERROR: /var/www/html/$test_site/public_html/utils/update_database.php does not exist. Aborting deploy."
	mv /var/www/html/$test_site/public_html_last/* /var/www/html/$test_site/public_html
    exit 1
fi

/usr/bin/php /var/www/html/$test_site/public_html/utils/update_database.php
returnvalue=$?
#echo "Database update script returns: $returnvalue"

if [[ "$returnvalue" != 1 ]];
then
	echo "ERROR: Database update failed.  Reverting deploy"
	mv /var/www/html/$test_site/public_html_last/* /var/www/html/$test_site/public_html
	exit 1
else
	echo "Database update successful."
fi

echo "========================================="
echo "SUCCESS: Deploy to test site '$test_site' complete!"
echo "Test site should be available at:"
echo "  https://test.${live_site}.com (if subdomain configured)"
echo "========================================="