#!/usr/bin/env bash
#VERSION 2.9 - Fixed SQL file path and sed regex
#Usage:  ./new_account.sh site_name domain_name server_ip [database_restore_file]

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Template files - relative to script location
VIRTUALHOST_TEMPLATE="${SCRIPT_DIR}/default_virtualhost.conf"
GLOBALVARS_DEFAULT="${SCRIPT_DIR}/default_Globalvars_site.php"

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
# Check for joinery-install.sql.gz from archive first, then fall back to legacy names
# Check both current directory and parent directory (for Docker builds)
if [ -f "joinery-install.sql.gz" ]; then
	DATABASE_RESTORE_FILE="joinery-install.sql.gz"
elif [ -f "${SCRIPT_DIR}/../joinery-install.sql.gz" ]; then
	DATABASE_RESTORE_FILE="${SCRIPT_DIR}/../joinery-install.sql.gz"
elif [ -f "joinery-install.sql" ]; then
	DATABASE_RESTORE_FILE="joinery-install.sql"
elif [ -f "${SCRIPT_DIR}/../joinery-install.sql" ]; then
	DATABASE_RESTORE_FILE="${SCRIPT_DIR}/../joinery-install.sql"
else
	DATABASE_RESTORE_FILE="joinery-install-sql.sql"
fi

# Allow user override
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
	DB_PASSWORD=$(echo "$DB_PASSWORD_LINE" | sed -n "s/.*=\s*['\"]\\([^'\"]*\\)['\"].*/\\1/p")
	
	if [ -z "$DB_PASSWORD" ] || [ "$DB_PASSWORD" == "" ]; then
		# If PGPASSWORD is set, use it instead
		if [ -n "$PGPASSWORD" ]; then
			echo "Using PGPASSWORD environment variable for database password."
			DB_PASSWORD="$PGPASSWORD"
		else
			echo "ERROR: Database password is empty in $GLOBALVARS_DEFAULT"
			echo "Please edit the file and set a password for:"
			echo "\$this->settings['dbpassword'] = 'your_password_here';"
			exit 1
		fi
	else
		echo "Database password is configured."
	fi
	
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
NEW_CONFIG_FILE=$NEW_SITE_ROOT/config/Globalvars_site.php

# Check if this is a Docker first-run (directory exists but config doesn't)
DOCKER_FIRST_RUN=false
if [ -d "$NEW_SITE_ROOT" ]; then
  if [ ! -f "$NEW_CONFIG_FILE" ]; then
    echo "Docker first-run detected: $NEW_SITE_ROOT exists but config doesn't. Continuing setup..."
    DOCKER_FIRST_RUN=true
  else
    echo "ERROR: $NEW_SITE_ROOT already exists and is fully configured."
    exit 1
  fi
fi

if [ -d "$NEW_TEST_SITE_ROOT" ] && [ "$DOCKER_FIRST_RUN" = false ]; then
  echo "ERROR: $NEW_TEST_SITE_ROOT already exists."
  exit 1
fi

if test -f "$VIRTUALHOST_TEMPLATE"; then
    echo "$VIRTUALHOST_TEMPLATE exists."
else
	echo "ERROR: $VIRTUALHOST_TEMPLATE does not exist."
	exit 1
fi

# Create main site directories (skip if Docker first-run)
if [ "$DOCKER_FIRST_RUN" = true ]; then
    echo "Docker first-run: Skipping directory creation (already exists from image)..."
    # Ensure upload directories exist and have correct permissions
    mkdir -p /var/www/html/$1/uploads/small
    mkdir -p /var/www/html/$1/uploads/medium
    mkdir -p /var/www/html/$1/uploads/large
    mkdir -p /var/www/html/$1/uploads/thumbnail
    mkdir -p /var/www/html/$1/uploads/lthumbnail
    mkdir -p /var/www/html/$1/config
else
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
fi

# Fix permissions for main site using centralized script (staging mode for new sites)
"$SCRIPT_DIR/fix_permissions.sh" "$1" --production

# Copy configuration files for main site
cp default_Globalvars_site.php /var/www/html/$1/config/Globalvars_site.php
if [ "$DOCKER_FIRST_RUN" = false ]; then
    cp default_serve.php /var/www/html/$1/public_html/serve.php
fi
sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /var/www/html/$1/config/Globalvars_site.php
sed -i -e "s/{{SITE_NAME}}/${1}/g" /var/www/html/$1/config/Globalvars_site.php
# Update password in config file using DB_PASSWORD
sed -i -e "s/\$this->settings\['dbpassword'\] = '';/\$this->settings['dbpassword'] = '${DB_PASSWORD}';/g" /var/www/html/$1/config/Globalvars_site.php

# Fix permissions again after config files are created (ensures correct ownership)
"$SCRIPT_DIR/fix_permissions.sh" "$1" --production

echo "$NEW_SITE_ROOT created/configured."

# Create test site directories
if [ "$DOCKER_FIRST_RUN" = false ]; then
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

    # Fix permissions for test site using centralized script (staging mode)
    "$SCRIPT_DIR/fix_permissions.sh" "${1}_test" --production

    # Copy configuration files for test site
    cp default_Globalvars_site.php /var/www/html/$1_test/config/Globalvars_site.php
    cp default_serve.php /var/www/html/$1_test/public_html/serve.php
    sed -i -e "s/{{DOMAIN_NAME}}/${2}/g" /var/www/html/$1_test/config/Globalvars_site.php
    sed -i -e "s/{{SITE_NAME}}/${1}_test/g" /var/www/html/$1_test/config/Globalvars_site.php

    # Fix permissions again after config files are created (ensures correct ownership)
    "$SCRIPT_DIR/fix_permissions.sh" "${1}_test" --production

    echo "$NEW_TEST_SITE_ROOT created."
else
    # Docker first-run: Create minimal test site structure for Apache VirtualHost
    echo "Docker first-run: Creating minimal test site directories for Apache..."
    mkdir -p /var/www/html/$1_test/public_html
    mkdir -p /var/www/html/$1_test/logs
    # Fix permissions for test site using centralized script (staging mode)
    "$SCRIPT_DIR/fix_permissions.sh" "${1}_test" --production
    echo "Minimal test site structure created."
fi

# Check if database already exists (handles container restarts with persistent volumes)
echo "Checking if PostgreSQL database '$1' already exists..."
DB_EXISTS=false
if [ -n "$PGPASSWORD" ]; then
	if psql -U postgres -lqt | cut -d \| -f 1 | grep -qw "$1"; then
		DB_EXISTS=true
		echo "Database '$1' already exists. Skipping creation and restore."
	fi
else
	if psql -U postgres -W -lqt | cut -d \| -f 1 | grep -qw "$1"; then
		DB_EXISTS=true
		echo "Database '$1' already exists. Skipping creation and restore."
	fi
fi

if [ "$DB_EXISTS" = false ]; then
	# Create PostgreSQL database
	echo "Creating PostgreSQL database '$1'..."

	# Check for PGPASSWORD environment variable for non-interactive mode
	if [ -n "$PGPASSWORD" ]; then
		# Non-interactive mode (Docker/automated)
		if ! createdb -T template0 "$1" -U postgres; then
			echo "ERROR: Failed to create database '$1'"
			echo "Rolling back: removing created directories..."
			rm -rf "/var/www/html/$1"
			rm -rf "/var/www/html/$1_test"
			exit 1
		fi
	else
		# Interactive mode (traditional)
		echo "Enter PostgreSQL postgres user password:"
		if ! createdb -T template0 "$1" -U postgres -W; then
			echo "ERROR: Failed to create database '$1'"
			echo "Rolling back: removing created directories..."
			rm -rf "/var/www/html/$1"
			rm -rf "/var/www/html/$1_test"
			exit 1
		fi
	fi

	# Load database restore file
	echo "Loading database from restore file '$DATABASE_RESTORE_FILE'..."

	# Check if file is compressed
	if [[ "$DATABASE_RESTORE_FILE" == *.gz ]]; then
		# Decompress and pipe to psql
		if [ -n "$PGPASSWORD" ]; then
			# Non-interactive mode
			if ! gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -d "$1"; then
				echo "ERROR: Failed to load database from compressed restore file"
				echo "Database '$1' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		else
			# Interactive mode
			echo "Enter PostgreSQL postgres user password:"
			if ! gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -W -d "$1"; then
				echo "ERROR: Failed to load database from compressed restore file"
				echo "Database '$1' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		fi
	else
		# Load uncompressed SQL file
		if [ -n "$PGPASSWORD" ]; then
			# Non-interactive mode
			if ! psql -U postgres -d "$1" -f "$DATABASE_RESTORE_FILE"; then
				echo "ERROR: Failed to load database from restore file"
				echo "Database '$1' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		else
			# Interactive mode
			echo "Enter PostgreSQL postgres user password:"
			if ! psql -U postgres -W -d "$1" -f "$DATABASE_RESTORE_FILE"; then
				echo "ERROR: Failed to load database from restore file"
				echo "Database '$1' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$1' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		fi
	fi
fi

# Install composer dependencies
# Must be after database restore (Globalvars needs stg_settings table)
# Before virtualhost setup (fail early if composer fails)
echo "Installing composer dependencies..."
if ! php /var/www/html/$1/public_html/utils/composer_install_if_needed.php; then
	echo "ERROR: Failed to install composer dependencies"
	exit 1
fi
echo "Composer dependencies installed."

VIRTUALHOST_FILE=/etc/apache2/sites-available/$1.conf
if [ -f "$VIRTUALHOST_FILE" ]; then
    echo "$VIRTUALHOST_FILE exists."
else
	cp "$VIRTUALHOST_TEMPLATE" /etc/apache2/sites-available/$1.conf
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

# Disable default site (safe on all servers - we're enabling our own site)
a2dissite 000-default.conf 2>/dev/null || true

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

# Only reload if not Docker (Docker starts Apache fresh after this script)
# Check both /.dockerenv file and cgroup for robust Docker detection
if [ ! -f /.dockerenv ] && ! grep -q docker /proc/1/cgroup 2>/dev/null; then
	echo "Reloading Apache..."
	if ! service apache2 reload; then
		echo "ERROR: Failed to reload Apache. Attempting graceful restart..."
		if ! apache2ctl graceful; then
			echo "ERROR: Failed to gracefully restart Apache."
			echo "WARNING: Apache may be in an inconsistent state. Please check manually."
			exit 1
		fi
	fi
	echo "Apache successfully reloaded."
else
	echo "Docker detected - skipping Apache reload (will start fresh via apache2ctl -D FOREGROUND)"
fi
echo "All done. Site $1 is now active and accessible."
echo "Main site: http://${2}"
echo "Test site: http://test.${2}"