#!/usr/bin/env bash
#VERSION 1.25
#Usage:  ./new_account.sh site_name domain_name server_ip --virtualhostonly

VIRTUALHOST_TEMPLATE=/home/user1/default_virtualhost.conf
GLOBALVARS_DEFAULT=/home/user1/Globalvars_site_default.php

if [ "$EUID" -ne 0 ]
then
	echo "ERROR: You must run as sudo or root."
	exit 1
fi

if [ "$1" == "" ]
then
	echo "Usage:  sudo ./new_account.sh site_name domain_name server_ip --virtualhostonly (optional)"
	echo "ERROR: You must pass the new site name as the first argument."
	exit 1
fi

if [ "$2" == "" ]
then
	echo "ERROR: You must pass the active domain (sample.com) as the second argument."
	exit 1
fi

if [ "$3" == "" ]
then
	echo "ERROR: You must pass the server ip as the third argument."
	exit 1
fi

VHONLY=0
if [ "$4" == "--virtualhostonly" ]
then
	VHONLY=1
fi

#CHECK NECESSARY FILES
if [ "$VHONLY" == 0 ]; then
	if test -f "$GLOBALVARS_DEFAULT"; then
		echo "$GLOBALVARS_DEFAULT exists."
	else
		echo "ERROR: $GLOBALVARS_DEFAULT does not exist."
		exit 1
	fi

	#TEST FOR SITE EXISTENCE
	NEW_SITE_ROOT=/var/www/html/$1
	if [ -d "$NEW_SITE_ROOT" ]; then
	  echo "ERROR: $NEW_SITE_ROOT already exists."
	  exit 1
	fi
fi


if test -f "$VIRTUALHOST_TEMPLATE"; then
    echo "$VIRTUALHOST_TEMPLATE exists."
else
	echo "ERROR: $VIRTUALHOST_TEMPLATE does not exist."
	exit 1
fi


if [ "$VHONLY" == 0 ]; then
	mkdir -p /var/www/html/$1
	mkdir -p /var/www/html/$1/public_html
	mkdir -p /var/www/html/$1/theme
	mkdir -p /var/www/html/$1/static_files
	mkdir -p /var/www/html/$1/config
	mkdir -p /var/www/html/$1/logs
	mkdir -p /var/www/html/$1/uploads
	mkdir -p /var/www/html/$1/uploads/small
	mkdir -p /var/www/html/$1/uploads/medium
	mkdir -p /var/www/html/$1/uploads/large
	mkdir -p /var/www/html/$1/uploads/thumbnail
	mkdir -p /var/www/html/$1/uploads/lthumbnail
	chown -R user1 /var/www/html/$1
	chgrp -R user1 /var/www/html/$1
	chmod -R 775 /var/www/html/$1/theme
	chmod -R 777 /var/www/html/$1/uploads
	chown -R www-data /var/www/html/$1/uploads
	cp Globalvars_site_default.php /var/www/html/$1/config/Globalvars_site.php
	cp serve.php /var/www/html/$1/public_html/serve.php
	# Updated to use new {{}} placeholder format for Globalvars_site.php
	sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /var/www/html/$1/config/Globalvars_site.php
	sed -i -e "s/{{SITE_NAME}}/${1}/g" /var/www/html/$1/config/Globalvars_site.php
	echo "$NEW_SITE_ROOT created."
fi

VIRTUALHOST_FILE=/etc/apache2/sites-available/$1.conf
if [ -f "$VIRTUALHOST_FILE" ]; then
    echo "$VIRTUALHOST_FILE exists."
else
	cp /home/user1/default_virtualhost.conf /etc/apache2/sites-available/$1.conf
	# Updated to use new {{}} placeholder format
	sed -i -e "s/{{SERVER_IP}}/${3}/g" /etc/apache2/sites-available/$1.conf
	sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /etc/apache2/sites-available/$1.conf
	sed -i -e "s/{{SITE_NAME}}/${1}/g" /etc/apache2/sites-available/$1.conf
	echo "$VIRTUALHOST_FILE created."
fi


echo "All done."
#removed
#echo 'Enter git credentials:'
#/home/user1/deploy.sh $1