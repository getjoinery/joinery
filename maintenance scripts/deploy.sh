#!/usr/bin/env bash
#version 1.18

GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

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

if [[ ! "$(ls -A /var/www/html/$1/theme)" ]]
then
  echo "Theme directory is empty"
  exit 1
fi

 if [[ ! -d "/var/www/html/$1/plugins" ]]
 then
   echo "Plugin directory does not exist"
   exit 1
 fi

 #if [[ ! "$(ls -A /var/www/html/$1/plugins)" ]]
 #then
   #echo "Plugin directory is empty"
   #exit 1
 #fi


#CLEAR THE STAGING FOLDER AND RECREATE
rm -rf /var/www/html/$1/public_html_stage
mkdir /var/www/html/$1/public_html_stage


# CLONE THE REPO DIRECTLY INTO theme_stage
git clone --no-checkout "$REPO_URL" /var/www/html/$1/public_html_stage

# MOVE INTO THE CLONED DIRECTORY
cd /var/www/html/$1/public_html_stage || exit 1

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
#git init /var/www/html/$1/public_html_stage

#echo "Enter git credentials."
#if ! git remote add origin https://github.com/Tunnell-Software/membership.git
#then
#git remote set-url origin https://github.com/Tunnell-Software/membership.git
#fi
#git pull origin main

#CLEAR THE LAST FOLDER AND SAVE CURRENT TO LAST
rm -rf /var/www/html/$1/public_html_last
mkdir /var/www/html/$1/public_html_last
mv /var/www/html/$1/public_html/* /var/www/html/$1/public_html_last

#MAKE THE ARCHIVES FOR UPGRADES...THIS REQUIRES SUDO
#rm /var/www/html/$1/static_files/*.upg.zip
#: > /var/www/html/$1/public_html/version_hash.md5
#find /var/www/html/$1/public_html -type f -exec md5sum {} \; > /var/www/html/$1/public_html/version_hash.md5
#tar --exclude-vcs -cf /var/www/html/$1/static_files/current_upgrade.upg.tar -C /var/www/html/$1 public_html_stage
#zip -qr /var/www/html/$1/static_files/current_upgrade.upg.zip /var/www/html/$1/public_html_stage -x '*.git*'

#COPY THE THEME FILES
if [[ -d /var/www/html/$1/theme ]]
then
	#REMOVE DEFAULT THEME AND COPY ALL THEMES
	#rm -rf /var/www/html/$1/public_html_stage/theme
	cp -r /var/www/html/$1/theme /var/www/html/$1/public_html_stage
fi

#COPY THE PLUGIN FILES
if [[ -d /var/www/html/$1/plugins ]]
then
	#REMOVE DEFAULT THEME AND COPY ALL THEMES
	#rm -rf /var/www/html/$1/public_html_stage/plugins
	cp -r /var/www/html/$1/plugins /var/www/html/$1/public_html_stage
fi

#DO THE DEPLOY
cp -r /var/www/html/$1/public_html_stage/* /var/www/html/$1/public_html
rm -rf /var/www/html/$1/public_html_stage

#Must run as sudo
#chown -R user1 /var/www/html/$1
#chgrp -R www-data /var/www/html/$1
#chmod -R 775 /var/www/html/$1/theme
#chmod -R 777 /var/www/html/$1/uploads
#chown -R www-data /var/www/html/$1/uploads

# Check if update_database.php exists
if [[ ! -f /var/www/html/$1/public_html/utils/update_database.php ]]; then
    echo "ERROR: /var/www/html/$1/public_html/utils/update_database.php does not exist. Aborting deploy."
	mv /var/www/html/$1/public_html_last/* /var/www/html/$1/public_html
    exit 1
fi

/usr/bin/php /var/www/html/$1/public_html/utils/update_database.php
returnvalue=$?
#echo "Database update script returns: $returnvalue"

if [[ "$returnvalue" != 1 ]];
then
	echo "ERROR: Database update failed.  Reverting deploy"
	mv /var/www/html/$1/public_html_last/* /var/www/html/$1/public_html
	exit 1
else
	echo "Database update successful."
fi


echo 'Deploy to' $1 'complete'




