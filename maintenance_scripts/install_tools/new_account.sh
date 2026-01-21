#!/usr/bin/env bash
#VERSION 2.13 - Fixed sed pattern to match any existing password, not just empty string
#Usage:  ./new_account.sh site_name domain_name server_ip [database_restore_file] [--themes theme1,theme2,...] [--activate theme_name]

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Template files - relative to script location
VIRTUALHOST_TEMPLATE="${SCRIPT_DIR}/default_virtualhost.conf"
GLOBALVARS_DEFAULT="${SCRIPT_DIR}/default_Globalvars_site.php"

# GitHub credentials for theme/plugin installation
GITHUB_USER="getjoinery"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/getjoinery/joinery.git"

# Function to install themes from repository
install_themes_from_repo() {
	local site_name="$1"
	local themes="$2"  # Comma-separated list
	local site_root="/var/www/html/$site_name"
	local public_html="$site_root/public_html"

	echo "Installing themes: $themes"

	# Create theme directory if it doesn't exist
	mkdir -p "$public_html/theme"

	# Create temporary staging directory
	local stage_dir="$site_root/theme_install_stage"
	rm -rf "$stage_dir"
	mkdir -p "$stage_dir"

	# Build sparse-checkout paths
	local sparse_paths=""
	IFS=',' read -ra THEME_ARRAY <<< "$themes"
	for theme in "${THEME_ARRAY[@]}"; do
		theme=$(echo "$theme" | xargs)  # Trim whitespace
		sparse_paths="$sparse_paths \"public_html/theme/$theme\""
	done

	# Clone with sparse checkout for only selected themes
	echo "Cloning repository with sparse checkout for themes..."
	git clone --no-checkout --quiet "$REPO_URL" "$stage_dir" 2>/dev/null || {
		echo "ERROR: Failed to clone repository for theme installation"
		rm -rf "$stage_dir"
		return 1
	}

	cd "$stage_dir" || return 1
	git config core.sparseCheckout true
	git sparse-checkout init --cone

	# Set sparse-checkout for themes
	for theme in "${THEME_ARRAY[@]}"; do
		theme=$(echo "$theme" | xargs)
		git sparse-checkout add "public_html/theme/$theme"
	done

	git checkout --quiet main 2>/dev/null || {
		echo "ERROR: Failed to checkout main branch"
		cd - > /dev/null
		rm -rf "$stage_dir"
		return 1
	}

	cd - > /dev/null

	# Copy themes to public_html
	local installed_count=0
	for theme in "${THEME_ARRAY[@]}"; do
		theme=$(echo "$theme" | xargs)
		if [[ -d "$stage_dir/public_html/theme/$theme" ]]; then
			echo "  Installing theme: $theme"
			cp -r "$stage_dir/public_html/theme/$theme" "$public_html/theme/"
			((installed_count++))
		else
			echo "  WARNING: Theme '$theme' not found in repository"
		fi
	done

	# Cleanup
	rm -rf "$stage_dir"

	echo "Installed $installed_count theme(s)."
	return 0
}

# Function to activate a theme via database update
activate_theme() {
	local site_name="$1"
	local theme_name="$2"

	echo "Activating theme: $theme_name"

	# Update the theme_template setting in database
	if [ -n "$PGPASSWORD" ]; then
		psql -U postgres -d "$site_name" -c "UPDATE stg_settings SET stg_value='$theme_name' WHERE stg_name='theme_template';" 2>/dev/null || {
			echo "WARNING: Failed to update theme_template setting"
			return 1
		}
	else
		psql -U postgres -W -d "$site_name" -c "UPDATE stg_settings SET stg_value='$theme_name' WHERE stg_name='theme_template';" 2>/dev/null || {
			echo "WARNING: Failed to update theme_template setting"
			return 1
		}
	fi

	echo "Theme '$theme_name' activated."
	return 0
}

if [ "$EUID" -ne 0 ]
then
	echo "ERROR: You must run as sudo or root."
	exit 1
fi

if [ "$1" == "" ]
then
	echo "Usage:  sudo ./new_account.sh site_name domain_name server_ip [database_restore_file] [--themes theme1,theme2,...] [--activate theme_name]"
	echo ""
	echo "Arguments:"
	echo "  site_name           Name for the new site (e.g., mysite)"
	echo "  domain_name         Domain for the site (e.g., example.com)"
	echo "  server_ip           Server IP address"
	echo "  database_restore_file  (Optional) SQL file to restore"
	echo ""
	echo "Options:"
	echo "  --themes theme1,theme2   Install specific themes from repository (default: falcon)"
	echo "  --activate theme_name    Set the active theme after installation (default: first theme)"
	echo ""
	echo "Examples:"
	echo "  sudo ./new_account.sh mysite example.com 1.2.3.4"
	echo "  sudo ./new_account.sh mysite example.com 1.2.3.4 --themes falcon"
	echo "  sudo ./new_account.sh mysite example.com 1.2.3.4 --themes falcon,custom --activate falcon"
	echo ""
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

# Store required positional arguments in named variables
SITE_NAME="$1"
DOMAIN_NAME="$2"
SERVER_IP="$3"

# Defaults for optional arguments
THEMES_TO_INSTALL="falcon"
ACTIVATE_THEME=""
DATABASE_RESTORE_FILE=""

# Parse optional arguments ($4 and beyond) without using shift
# This is simpler and less error-prone than shift-based parsing
for ((i=4; i<=$#; i++)); do
	arg="${!i}"
	case "$arg" in
		--themes)
			((i++))
			THEMES_TO_INSTALL="${!i}"
			;;
		--activate)
			((i++))
			ACTIVATE_THEME="${!i}"
			;;
		*)
			# First non-flag argument is the database restore file
			if [[ -z "$DATABASE_RESTORE_FILE" && "$arg" != --* ]]; then
				DATABASE_RESTORE_FILE="$arg"
			fi
			;;
	esac
done

# Set database restore file (default if not specified)
# Check for joinery-install.sql.gz from archive first, then fall back to legacy names
# Check both current directory and parent directory (for Docker builds)
if [[ -z "$DATABASE_RESTORE_FILE" ]]; then
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
NEW_SITE_ROOT=/var/www/html/$SITE_NAME
NEW_TEST_SITE_ROOT=/var/www/html/${SITE_NAME}_test
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
    mkdir -p /var/www/html/$SITE_NAME/uploads/small
    mkdir -p /var/www/html/$SITE_NAME/uploads/medium
    mkdir -p /var/www/html/$SITE_NAME/uploads/large
    mkdir -p /var/www/html/$SITE_NAME/uploads/thumbnail
    mkdir -p /var/www/html/$SITE_NAME/uploads/lthumbnail
    mkdir -p /var/www/html/$SITE_NAME/config
else
    echo "Creating main site directory structure..."
    mkdir -p /var/www/html/$SITE_NAME
    mkdir -p /var/www/html/$SITE_NAME/public_html
    mkdir -p /var/www/html/$SITE_NAME/static_files
    mkdir -p /var/www/html/$SITE_NAME/config
    mkdir -p /var/www/html/$SITE_NAME/logs
    mkdir -p /var/www/html/$SITE_NAME/uploads
    mkdir -p /var/www/html/$SITE_NAME/uploads/small
    mkdir -p /var/www/html/$SITE_NAME/uploads/medium
    mkdir -p /var/www/html/$SITE_NAME/uploads/large
    mkdir -p /var/www/html/$SITE_NAME/uploads/thumbnail
    mkdir -p /var/www/html/$SITE_NAME/uploads/lthumbnail
fi

# Fix permissions for main site using centralized script (staging mode for new sites)
"$SCRIPT_DIR/fix_permissions.sh" "$SITE_NAME" --production

# Copy configuration files for main site
cp default_Globalvars_site.php /var/www/html/$SITE_NAME/config/Globalvars_site.php
if [ "$DOCKER_FIRST_RUN" = false ]; then
    cp default_serve.php /var/www/html/$SITE_NAME/public_html/serve.php
fi
sed -i -e "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /var/www/html/$SITE_NAME/config/Globalvars_site.php
sed -i -e "s/{{SITE_NAME}}/${SITE_NAME}/g" /var/www/html/$SITE_NAME/config/Globalvars_site.php
# Update password in config file using DB_PASSWORD
# Use a pattern that matches any existing password value (empty or not)
sed -i -e "s/\$this->settings\['dbpassword'\] = '[^']*';/\$this->settings['dbpassword'] = '${DB_PASSWORD}';/g" /var/www/html/$SITE_NAME/config/Globalvars_site.php

# Fix permissions again after config files are created (ensures correct ownership)
"$SCRIPT_DIR/fix_permissions.sh" "$SITE_NAME" --production

echo "$NEW_SITE_ROOT created/configured."

# Create test site directories
if [ "$DOCKER_FIRST_RUN" = false ]; then
    echo "Creating test site directory structure..."
    mkdir -p /var/www/html/${SITE_NAME}_test
    mkdir -p /var/www/html/${SITE_NAME}_test/public_html
    mkdir -p /var/www/html/${SITE_NAME}_test/static_files
    mkdir -p /var/www/html/${SITE_NAME}_test/config
    mkdir -p /var/www/html/${SITE_NAME}_test/logs
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads/small
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads/medium
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads/large
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads/thumbnail
    mkdir -p /var/www/html/${SITE_NAME}_test/uploads/lthumbnail

    # Fix permissions for test site using centralized script (staging mode)
    "$SCRIPT_DIR/fix_permissions.sh" "${SITE_NAME}_test" --production

    # Copy configuration files for test site
    cp default_Globalvars_site.php /var/www/html/${SITE_NAME}_test/config/Globalvars_site.php
    cp default_serve.php /var/www/html/${SITE_NAME}_test/public_html/serve.php
    sed -i -e "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /var/www/html/${SITE_NAME}_test/config/Globalvars_site.php
    sed -i -e "s/{{SITE_NAME}}/${SITE_NAME}_test/g" /var/www/html/${SITE_NAME}_test/config/Globalvars_site.php

    # Fix permissions again after config files are created (ensures correct ownership)
    "$SCRIPT_DIR/fix_permissions.sh" "${SITE_NAME}_test" --production

    echo "$NEW_TEST_SITE_ROOT created."
else
    # Docker first-run: Create minimal test site structure for Apache VirtualHost
    echo "Docker first-run: Creating minimal test site directories for Apache..."
    mkdir -p /var/www/html/${SITE_NAME}_test/public_html
    mkdir -p /var/www/html/${SITE_NAME}_test/logs
    # Fix permissions for test site using centralized script (staging mode)
    "$SCRIPT_DIR/fix_permissions.sh" "${SITE_NAME}_test" --production
    echo "Minimal test site structure created."
fi

# Check if database already exists (handles container restarts with persistent volumes)
echo "Checking if PostgreSQL database '$SITE_NAME' already exists..."
DB_EXISTS=false
if [ -n "$PGPASSWORD" ]; then
	if psql -U postgres -lqt | cut -d \| -f 1 | grep -qw "$SITE_NAME"; then
		DB_EXISTS=true
		echo "Database '$SITE_NAME' already exists. Skipping creation and restore."
	fi
else
	if psql -U postgres -W -lqt | cut -d \| -f 1 | grep -qw "$SITE_NAME"; then
		DB_EXISTS=true
		echo "Database '$SITE_NAME' already exists. Skipping creation and restore."
	fi
fi

if [ "$DB_EXISTS" = false ]; then
	# Create PostgreSQL database
	echo "Creating PostgreSQL database '$SITE_NAME'..."

	# Check for PGPASSWORD environment variable for non-interactive mode
	if [ -n "$PGPASSWORD" ]; then
		# Non-interactive mode (Docker/automated)
		if ! createdb -T template0 "$SITE_NAME" -U postgres; then
			echo "ERROR: Failed to create database '$SITE_NAME'"
			echo "Rolling back: removing created directories..."
			rm -rf "/var/www/html/$SITE_NAME"
			rm -rf "/var/www/html/${SITE_NAME}_test"
			exit 1
		fi
	else
		# Interactive mode (traditional)
		echo "Enter PostgreSQL postgres user password:"
		if ! createdb -T template0 "$SITE_NAME" -U postgres -W; then
			echo "ERROR: Failed to create database '$SITE_NAME'"
			echo "Rolling back: removing created directories..."
			rm -rf "/var/www/html/$SITE_NAME"
			rm -rf "/var/www/html/${SITE_NAME}_test"
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
			if ! gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -d "$SITE_NAME"; then
				echo "ERROR: Failed to load database from compressed restore file"
				echo "Database '$SITE_NAME' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$SITE_NAME' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		else
			# Interactive mode
			echo "Enter PostgreSQL postgres user password:"
			if ! gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -W -d "$SITE_NAME"; then
				echo "ERROR: Failed to load database from compressed restore file"
				echo "Database '$SITE_NAME' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$SITE_NAME' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		fi
	else
		# Load uncompressed SQL file
		if [ -n "$PGPASSWORD" ]; then
			# Non-interactive mode
			if ! psql -U postgres -d "$SITE_NAME" -f "$DATABASE_RESTORE_FILE"; then
				echo "ERROR: Failed to load database from restore file"
				echo "Database '$SITE_NAME' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$SITE_NAME' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		else
			# Interactive mode
			echo "Enter PostgreSQL postgres user password:"
			if ! psql -U postgres -W -d "$SITE_NAME" -f "$DATABASE_RESTORE_FILE"; then
				echo "ERROR: Failed to load database from restore file"
				echo "Database '$SITE_NAME' was created but restore failed."
				echo "You may need to manually restore or recreate the database."
			else
				echo "Database '$SITE_NAME' loaded successfully from '$DATABASE_RESTORE_FILE'."
			fi
		fi
	fi
fi

# Install composer dependencies
# Must be after database restore (Globalvars needs stg_settings table)
# Before virtualhost setup (fail early if composer fails)
echo "Installing composer dependencies..."
if ! php /var/www/html/$SITE_NAME/public_html/utils/composer_install_if_needed.php; then
	echo "ERROR: Failed to install composer dependencies"
	exit 1
fi
echo "Composer dependencies installed."

# Install themes from repository
if [[ -n "$THEMES_TO_INSTALL" ]]; then
	if ! install_themes_from_repo "$SITE_NAME" "$THEMES_TO_INSTALL"; then
		echo "WARNING: Theme installation failed, continuing with setup..."
	fi

	# Activate specified theme or default to first installed theme
	if [[ -n "$ACTIVATE_THEME" ]]; then
		activate_theme "$SITE_NAME" "$ACTIVATE_THEME"
	else
		# Activate first theme in the list
		IFS=',' read -ra THEME_ARRAY <<< "$THEMES_TO_INSTALL"
		first_theme=$(echo "${THEME_ARRAY[0]}" | xargs)
		activate_theme "$SITE_NAME" "$first_theme"
	fi

	# Fix permissions after theme installation
	"$SCRIPT_DIR/fix_permissions.sh" "$SITE_NAME" --production
fi

VIRTUALHOST_FILE=/etc/apache2/sites-available/$SITE_NAME.conf
if [ -f "$VIRTUALHOST_FILE" ]; then
    echo "$VIRTUALHOST_FILE exists."
else
	cp "$VIRTUALHOST_TEMPLATE" /etc/apache2/sites-available/$SITE_NAME.conf
	# Updated to use new {{}} placeholder format
	sed -i -e "s/{{SERVER_IP}}/${SERVER_IP}/g" /etc/apache2/sites-available/$SITE_NAME.conf
	sed -i -e "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /etc/apache2/sites-available/$SITE_NAME.conf
	sed -i -e "s/{{SITE_NAME}}/${SITE_NAME}/g" /etc/apache2/sites-available/$SITE_NAME.conf
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
echo "Enabling virtualhost $SITE_NAME..."
if ! a2ensite $SITE_NAME.conf; then
	echo "ERROR: Failed to enable virtualhost $SITE_NAME.conf"
	exit 1
fi

# Test configuration again after enabling the site
echo "Testing Apache configuration after enabling virtualhost..."
if ! apache2ctl configtest; then
	echo "ERROR: Apache configuration test failed after enabling virtualhost."
	echo "Disabling virtualhost $SITE_NAME.conf to restore previous state..."
	a2dissite $SITE_NAME.conf
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
echo "All done. Site $SITE_NAME is now active and accessible."
echo "Main site: http://${DOMAIN_NAME}"
echo "Test site: http://test.${DOMAIN_NAME}"