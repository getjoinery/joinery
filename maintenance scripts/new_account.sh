#!/usr/bin/env bash
#VERSION 1.31
#Usage:  ./new_account.sh site_name domain_name server_ip [database_restore_file]

VIRTUALHOST_TEMPLATE=/home/user1/default_virtualhost.conf
GLOBALVARS_DEFAULT=/home/user1/Globalvars_site_default.php

if [ "$EUID" -ne 0 ]
then
	echo "ERROR: You must run as sudo or root."
	exit 1
fi

if [ "$1" == "" ]
then
	echo "Usage:  sudo ./new_account.sh site_name domain_name server_ip [database_restore_file]"
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

# Set database restore file (default or user-specified)
DATABASE_RESTORE_FILE="joinery-install-sql.sql"
if [ "$4" != "" ]; then
	DATABASE_RESTORE_FILE="$4"
fi

#CHECK NECESSARY FILES
if test -f "$GLOBALVARS_DEFAULT"; then
	echo "$GLOBALVARS_DEFAULT exists."
	
	# Check if database password has been configured
	echo "Checking if database password is configured..."
	DB_PASSWORD_LINE=$(grep "settings\['dbpassword'\]" "$GLOBALVARS_DEFAULT" | head -n 1)
	
	if [ -z "$DB_PASSWORD_LINE" ]; then
		echo "ERROR: Could not find dbpassword setting in $GLOBALVARS_DEFAULT"
		exit 1
	fi
	
	# Extract the password value using sed - handles both single and double quotes
	DB_PASSWORD=$(echo "$DB_PASSWORD_LINE" | sed -n "s/.*settings\['dbpassword'\]\s*=\s*['\"][^'\"]*['\"];.*/\1/p" | sed "s/.*['\"]//;s/['\"];.*//")
	
	# Alternative extraction method that's more reliable
	DB_PASSWORD=$(echo "$DB_PASSWORD_LINE" | sed -n "s/.*=\s*['\"]\\([^'\"]*\\)['\"].*/\\1/p")
	
	if [ -z "$DB_PASSWORD" ] || [ "$DB_PASSWORD" == "" ]; then
		echo "ERROR: Database password is empty in $GLOBALVARS_DEFAULT"
		echo "Please edit the file and set a password for:"
		echo "\$this->settings['dbpassword'] = 'your_password_here';"
		exit 1
	fi
	
	echo "Database password is configured."
	
	# Validate database restore file (use default if not overridden)
	if [ ! -f "$DATABASE_RESTORE_FILE" ]; then
		echo "ERROR: Database restore file '$DATABASE_RESTORE_FILE' does not exist."
		if [ "$DATABASE_RESTORE_FILE" == "joinery-install-sql.sql" ]; then
			echo "Make sure 'joinery-install-sql.sql' is in the current directory, or specify a different restore file."
		fi
		exit 1
	fi
	echo "Database restore file '$DATABASE_RESTORE_FILE' found."
else
	echo "ERROR: $GLOBALVARS_DEFAULT does not exist."
	exit 1
fi

#TEST FOR SITE EXISTENCE
NEW_SITE_ROOT=/var/www/html/$1
NEW_TEST_SITE_ROOT=/var/www/html/$1_test
if [ -d "$NEW_SITE_ROOT" ]; then
  echo "ERROR: $NEW_SITE_ROOT already exists."
  exit 1
fi

if [ -d "$NEW_TEST_SITE_ROOT" ]; then
  echo "ERROR: $NEW_TEST_SITE_ROOT already exists."
  exit 1
fi

if test -f "$VIRTUALHOST_TEMPLATE"; then
    echo "$VIRTUALHOST_TEMPLATE exists."
else
	echo "ERROR: $VIRTUALHOST_TEMPLATE does not exist."
	exit 1
fi

# Create main site directories
echo "Creating main site directory structure..."
mkdir -p /var/www/html/$1
mkdir -p /var/www/html/$1/public_html
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
chmod -R 777 /var/www/html/$1/uploads
chown -R www-data /var/www/html/$1/uploads

# Copy configuration files for main site
cp Globalvars_site_default.php /var/www/html/$1/config/Globalvars_site.php
cp serve.php /var/www/html/$1/public_html/serve.php
sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /var/www/html/$1/config/Globalvars_site.php
sed -i -e "s/{{SITE_NAME}}/${1}/g" /var/www/html/$1/config/Globalvars_site.php
echo "$NEW_SITE_ROOT created."

# Create test site directories
echo "Creating test site directory structure..."
mkdir -p /var/www/html/$1_test
mkdir -p /var/www/html/$1_test/public_html
mkdir -p /var/www/html/$1_test/static_files
mkdir -p /var/www/html/$1_test/config
mkdir -p /var/www/html/$1_test/logs
mkdir -p /var/www/html/$1_test/uploads
mkdir -p /var/www/html/$1_test/uploads/small
mkdir -p /var/www/html/$1_test/uploads/medium
mkdir -p /var/www/html/$1_test/uploads/large
mkdir -p /var/www/html/$1_test/uploads/thumbnail
mkdir -p /var/www/html/$1_test/uploads/lthumbnail
chown -R user1 /var/www/html/$1_test
chgrp -R user1 /var/www/html/$1_test
chmod -R 777 /var/www/html/$1_test/uploads
chown -R www-data /var/www/html/$1_test/uploads

# Copy configuration files for test site
cp Globalvars_site_default.php /var/www/html/$1_test/config/Globalvars_site.php
cp serve.php /var/www/html/$1_test/public_html/serve.php
sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /var/www/html/$1_test/config/Globalvars_site.php
sed -i -e "s/{{SITE_NAME}}/${1}_test/g" /var/www/html/$1_test/config/Globalvars_site.php
echo "$NEW_TEST_SITE_ROOT created."

# Create PostgreSQL database
echo "Creating PostgreSQL database '$1'..."
echo "Enter PostgreSQL postgres user password:"
if ! createdb -T template0 "$1" -U postgres; then
	echo "ERROR: Failed to create database '$1'"
	echo "Rolling back: removing created directories..."
	rm -rf "/var/www/html/$1"
	rm -rf "/var/www/html/$1_test"
	exit 1
fi

# Load database restore file
echo "Loading database from restore file '$DATABASE_RESTORE_FILE'..."
echo "Enter PostgreSQL postgres user password:"
if ! psql -U postgres -W -d "$1" -f "$DATABASE_RESTORE_FILE"; then
	echo "ERROR: Failed to load database from restore file"
	echo "Database '$1' was created but restore failed."
	echo "You may need to manually restore or recreate the database."
else
	echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
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

# Test Apache configuration before making any changes
echo "Testing Apache configuration..."
if ! apache2ctl configtest; then
	echo "ERROR: Apache configuration test failed. Please fix configuration errors before proceeding."
	exit 1
fi

# Enable the new virtualhost
echo "Enabling virtualhost $1..."
if ! a2ensite $1.conf; then
	echo "ERROR: Failed to enable virtualhost $1.conf"
	exit 1
fi

# Test configuration again after enabling the site
echo "Testing Apache configuration after enabling virtualhost..."
if ! apache2ctl configtest; then
	echo "ERROR: Apache configuration test failed after enabling virtualhost."
	echo "Disabling virtualhost $1.conf to restore previous state..."
	a2dissite $1.conf
	echo "Virtualhost disabled. Please check your virtualhost configuration."
	exit 1
fi

# Reload Apache gracefully
echo "Reloading Apache..."
if ! systemctl reload apache2; then
	echo "ERROR: Failed to reload Apache. Attempting graceful restart..."
	if ! apache2ctl graceful; then
		echo "ERROR: Failed to gracefully restart Apache."
		echo "WARNING: Apache may be in an inconsistent state. Please check manually."
		exit 1
	fi
fi

echo "Apache successfully reloaded."
echo "All done. Site $1 is now active and accessible."
echo "Main site: http://${2}"
echo "Test site: http://test.${2}"