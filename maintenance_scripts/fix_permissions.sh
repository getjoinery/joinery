#!/usr/bin/env bash
#VERSION 1.05
#Usage:  ./fix_permissions.sh site_name
# MODIFIED: Removed theme and plugin directory creation - now using symlinks 


if [ "$EUID" -ne 0 ]
then
	echo "You must run as sudo or root."
	exit 1
fi

if [ "$1" == "" ]
then
	echo "Usage:  sudo ./fix_permissions.sh site_name"
	echo "You must pass the new site name as the first argument."
	exit 1
fi


#TEST FOR SITE EXISTENCE
NEW_SITE_ROOT=/var/www/html/$1
if [ -d "$NEW_SITE_ROOT" ]; then
	echo "Site exists, updating permissions."
else
  echo "This site does not exist."
  exit 1
fi

# Theme and plugin directories removed - now using symlinks to /home/user1/joinery/joinery/

chown -R www-data /var/www/html/$1
chgrp -R user1 /var/www/html/$1
chmod -R 775 /var/www/html/$1
chmod -R 777 /var/www/html/$1/uploads

echo "All done."

