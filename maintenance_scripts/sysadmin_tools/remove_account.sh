#!/usr/bin/env bash
#VERSION 1.0
#Usage:  ./remove_site.sh site_name

if [ "$EUID" -ne 0 ]
then
	echo "ERROR: You must run as sudo or root."
	exit 1
fi

if [ "$1" == "" ]
then
	echo "Usage:  sudo ./remove_site.sh site_name"
	echo "ERROR: You must pass the site name to remove as the first argument."
	exit 1
fi

SITE_NAME=$1
SITE_ROOT="/var/www/html/$SITE_NAME"
TEST_SITE_ROOT="/var/www/html/${SITE_NAME}_test"
VIRTUALHOST_FILE="/etc/apache2/sites-available/$SITE_NAME.conf"

echo "WARNING: This will completely remove site '$SITE_NAME' and all its data!"
echo "This includes:"
echo "  - Website files: $SITE_ROOT"
echo "  - Test site files: $TEST_SITE_ROOT"
echo "  - Apache virtual host: $VIRTUALHOST_FILE"
echo "  - PostgreSQL database: $SITE_NAME"
echo ""
echo "This action CANNOT be undone!"
echo ""
read -p "Are you sure you want to continue? (type 'yes' to confirm): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Operation cancelled."
    exit 0
fi

# Disable Apache site if enabled
echo "Disabling Apache site..."
if [ -f "$VIRTUALHOST_FILE" ]; then
    a2dissite "$SITE_NAME.conf" 2>/dev/null || echo "Site was not enabled or already disabled"
else
    echo "Virtual host file does not exist, skipping Apache disable"
fi

# Test Apache configuration
echo "Testing Apache configuration..."
if ! apache2ctl configtest; then
    echo "WARNING: Apache configuration test failed, but continuing with cleanup"
fi

# Reload Apache
echo "Reloading Apache..."
if ! systemctl reload apache2; then
    echo "WARNING: Failed to reload Apache, but continuing with cleanup"
fi

# Remove website directories
echo "Removing website directories..."
if [ -d "$SITE_ROOT" ]; then
    rm -rf "$SITE_ROOT"
    echo "Removed: $SITE_ROOT"
else
    echo "Directory $SITE_ROOT does not exist"
fi

if [ -d "$TEST_SITE_ROOT" ]; then
    rm -rf "$TEST_SITE_ROOT"
    echo "Removed: $TEST_SITE_ROOT"
else
    echo "Directory $TEST_SITE_ROOT does not exist"
fi

# Remove Apache virtual host file
echo "Removing Apache virtual host..."
if [ -f "$VIRTUALHOST_FILE" ]; then
    rm -f "$VIRTUALHOST_FILE"
    echo "Removed: $VIRTUALHOST_FILE"
else
    echo "Virtual host file $VIRTUALHOST_FILE does not exist"
fi

# Remove PostgreSQL database
echo "Removing PostgreSQL database..."
echo "Enter PostgreSQL postgres user password:"
if dropdb -U postgres "$SITE_NAME" 2>/dev/null; then
    echo "Removed database: $SITE_NAME"
else
    echo "WARNING: Failed to remove database '$SITE_NAME' or database does not exist"
    echo "You may need to remove it manually: dropdb -U postgres $SITE_NAME"
fi

echo ""
echo "Cleanup completed for site: $SITE_NAME"
echo "You can now run 'install.sh site' again to recreate the site."