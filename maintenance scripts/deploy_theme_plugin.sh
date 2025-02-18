#!/usr/bin/env bash
#version 1.0

STAGING_DIR="/var/www/html/$1"
GITHUB_USER="getjoinery"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/getjoinery/joinery.git"


if [ "$1" == "" ]
then
echo "You must pass the active site as the first argument."
exit 1
fi

deploy_directory="/var/www/html/$1"

if [[ ! -d $deploy_directory ]]
then
    echo "Deploy directory $deploy_directory does not exist."
fi

#CHECK TO MAKE SURE EVERYTHING IS THERE
if [[ ! -d "/var/www/html/$1/theme" ]]
then
  echo "Theme directory does not exist"
  exit 1
fi


 if [[ ! -d "/var/www/html/$1/plugins" ]]
 then
   echo "Plugin directory does not exist"
   exit 1
 fi



# CLEAR THE STAGING FOLDER AND RECREATE
rm -rf "$STAGING_DIR/theme_stage"
mkdir -p "$STAGING_DIR/theme_stage"
#MAKE OWNED BY GROUP :www-data
chown -R :www-data "$STAGING_DIR/theme_stage"
chmod -R g+rw "$STAGING_DIR/theme_stage"

# CLONE THE REPO DIRECTLY INTO theme_stage
git clone --no-checkout "$REPO_URL" "$STAGING_DIR/theme_stage"

# MOVE INTO THE CLONED DIRECTORY
cd "$STAGING_DIR/theme_stage" || exit 1

# ENABLE SPARSE CHECKOUT
git config core.sparseCheckout true

# SPECIFY WHICH DIRECTORIES TO CHECKOUT
git sparse-checkout init --cone
git sparse-checkout set theme/

# PULL ONLY THE SPECIFIED FOLDERS
git pull origin main  # Change 'main' if your repo uses 'master' or another branch

rm -rf .git

ls -la "$STAGING_DIR/theme_stage"




rm -rf "$STAGING_DIR/plugins_stage"
mkdir -p "$STAGING_DIR/plugins_stage"
#MAKE OWNED BY GROUP :www-data
chown -R :www-data "$STAGING_DIR/plugins_stage"
chmod -R g+rw "$STAGING_DIR/plugins_stage"

# CLONE THE REPO DIRECTLY INTO theme_stage
git clone --no-checkout "$REPO_URL" "$STAGING_DIR/plugins_stage"

# MOVE INTO THE CLONED DIRECTORY
cd "$STAGING_DIR/plugins_stage" || exit 1

# ENABLE SPARSE CHECKOUT
git config core.sparseCheckout true

# SPECIFY WHICH DIRECTORIES TO CHECKOUT
git sparse-checkout init --cone
git sparse-checkout set plugins/

# PULL ONLY THE SPECIFIED FOLDERS
git pull origin main  # Change 'main' if your repo uses 'master' or another branch

rm -rf .git

ls -la "$STAGING_DIR/plugins_stage"

#Deploy
rm -rf "$STAGING_DIR/plugins_old"
mv "$STAGING_DIR/plugins" "$STAGING_DIR/plugins_old"
mv "$STAGING_DIR/plugins_stage/"* "$STAGING_DIR/plugins"
chown -R :www-data "$STAGING_DIR/plugins"
chmod -R g+rw "$STAGING_DIR/plugins"

rm -rf "$STAGING_DIR/theme_old"
mv "$STAGING_DIR/theme" "$STAGING_DIR/theme_old"
mv "$STAGING_DIR/theme_stage/"* "$STAGING_DIR/theme"
chown -R :www-data "$STAGING_DIR/theme"
chmod -R g+rw "$STAGING_DIR/theme"

exit 1


git remote add origin https://github.com/getjoinery/joinery.git
git config core.sparseCheckout true
echo "theme/" >> .git/info/sparse-checkout
echo "plugin/" >> .git/info/sparse-checkout
git pull origin main


echo 'Deploy to' $1 'complete'




