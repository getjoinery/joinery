#!/usr/bin/env bash
#VERSION 1.04
#Usage:  ./fix_permissions.sh site_name 


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

PLUGINDIR="/var/www/html/$1/plugins"

# Check if the directory exists
if [ ! -d "$PLUGINDIR" ]; then
    echo "Directory $PLUGINDIR does not exist. Creating it now..."
    mkdir -p "$PLUGINDIR"
    echo "Directory $PLUGINDIR created successfully."
else
    echo "Directory $PLUGINDIR already exists."
fi


THEMEDIR="/var/www/html/$1/theme"

# Check if the directory exists
if [ ! -d "$THEMEDIR" ]; then
    echo "Directory $THEMEDIR does not exist. Creating it now..."
    mkdir -p "$THEMEDIR"
    echo "Directory $THEMEDIR created successfully."
else
    echo "Directory $THEMEDIR already exists."
fi

chown -R www-data /var/www/html/$1
chgrp -R user1 /var/www/html/$1
chmod -R 775 /var/www/html/$1
chmod -R 777 /var/www/html/$1/uploads

echo "All done."

