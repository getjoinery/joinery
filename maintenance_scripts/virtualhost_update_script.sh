#!/bin/bash

# Script to create Apache site configuration from template
# Version: 2.0.0
# Usage: ./virtualhost_update_script.sh <filename> [site_name] [domain_name] [server_ip]

# Version info
VERSION="2.2.0"
echo "Virtual Host Template Script v${VERSION}"
echo "=========================================="

# Check if filename argument is provided
if [ $# -eq 0 ]; then
    echo "Error: Please provide a filename as an argument"
    echo "Usage: $0 <filename> [site_name] [domain_name] [server_ip]"
    echo ""
    echo "Examples:"
    echo "  $0 scrolldaddy.conf"
    echo "  $0 mysite.conf mysite mysite.com 192.168.1.100"
    echo ""
    echo "If optional parameters are not provided, the script will attempt to extract them from existing config."
    exit 1
fi

FILENAME="$1"

# Validate filename has .conf extension
if [[ ! "$FILENAME" == *.conf ]]; then
    echo "Error: Filename must end with .conf extension"
    echo "You provided: $FILENAME"
    echo "Example: $0 galactictribune.conf"
    exit 1
fi

CONFIG_FILE="/etc/apache2/sites-available/${FILENAME}"
TEMPLATE_FILE="$(dirname "$0")/default_virtualhost.conf"

# Check if the template file exists
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "Error: Template file not found: $TEMPLATE_FILE"
    exit 1
fi

# Check if running with appropriate permissions
if [ "$EUID" -ne 0 ]; then 
    echo "This script needs to be run with sudo privileges"
    echo "Please run: sudo $0 $FILENAME"
    exit 1
fi

# Function to extract value from existing config or use parameter
extract_or_use_param() {
    local param_value="$1"
    local grep_pattern="$2"
    local sed_pattern="$3"
    local default_value="$4"
    
    if [ -n "$param_value" ]; then
        echo "$param_value"
    elif [ -f "$CONFIG_FILE" ]; then
        local extracted=$(grep "$grep_pattern" "$CONFIG_FILE" | sed "$sed_pattern" | head -1 | xargs)
        if [ -n "$extracted" ]; then
            echo "$extracted"
        else
            echo "$default_value"
        fi
    else
        echo "$default_value"
    fi
}

# Extract or use provided parameters
SITE_NAME=$(extract_or_use_param "$2" "DocumentRoot" "s/.*\/\([^/]*\)\/public_html.*/\1/" "mysite")
DOMAIN_NAME=$(extract_or_use_param "$3" "^[[:space:]]*ServerName" "s/^[[:space:]]*ServerName[[:space:]]\+\([^[:space:]]*\).*/\1/" "example.com")
SERVER_IP=$(extract_or_use_param "$4" "<VirtualHost" "s/.*<VirtualHost[[:space:]]*\([^:]*\):.*/\1/" "127.0.0.1")

# Validate extracted values - don't use defaults!
if [ "$SITE_NAME" = "mysite" ] || [ "$DOMAIN_NAME" = "example.com" ] || [ "$SERVER_IP" = "127.0.0.1" ]; then
    if [ -f "$CONFIG_FILE" ]; then
        echo "ERROR: Failed to extract configuration from existing file: $CONFIG_FILE"
        echo ""
        echo "Extracted values:"
        echo "  Site Name: $SITE_NAME"
        echo "  Domain Name: $DOMAIN_NAME"
        echo "  Server IP: $SERVER_IP"
        echo ""
        echo "These appear to be default values, not actual configuration."
        echo ""
        echo "Or check that the existing config file has the expected format."
    else
        echo "ERROR: No existing configuration file found, and no parameters provided."
        echo ""
        echo "When creating a new virtualhost, you must provide all parameters:"
    fi

    echo "Please provide the parameters explicitly:"
    echo "  $0 $FILENAME <site_name> <domain_name> <server_ip>"
    echo ""
    echo "Example:"
    echo "  $0 galactictribune.conf galactictribune galactictribune.net 69.164.209.253"
    echo ""
    exit 1
fi

# Additional validation for existing config files
if [ -f "$CONFIG_FILE" ]; then
    # Site name should match filename
    EXPECTED_SITE="${FILENAME%.conf}"
    if [ "$SITE_NAME" != "$EXPECTED_SITE" ]; then
        echo "WARNING: Site name '$SITE_NAME' doesn't match filename '$EXPECTED_SITE'"
        echo "This might indicate incorrect parameter extraction."
        echo ""
        read -p "Do you want to continue? (y/N): " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Aborting. Please run with explicit parameters:"
            echo "  $0 $FILENAME <site_name> <domain_name> <server_ip>"
            exit 1
        fi
    fi
fi

echo "Configuration:"
echo "  Site Name: $SITE_NAME"
echo "  Domain Name: $DOMAIN_NAME"
echo "  Server IP: $SERVER_IP"
echo "  Config File: $CONFIG_FILE"
echo "  Template: $TEMPLATE_FILE"
echo ""

# Confirm before proceeding if updating existing config
if [ -f "$CONFIG_FILE" ]; then
    echo "This will UPDATE the existing configuration file."
    read -p "Do you want to proceed? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborting."
        exit 1
    fi
fi

# Create a backup of the original file if it exists
if [ -f "$CONFIG_FILE" ]; then
    BACKUP_FILE="${CONFIG_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    echo "Creating backup: $BACKUP_FILE"
    cp "$CONFIG_FILE" "$BACKUP_FILE"
else
    echo "Creating new configuration file"
fi

# Create the new configuration by substituting template variables
echo "Generating configuration from template..."
sed -e "s/{{SITE_NAME}}/$SITE_NAME/g" \
    -e "s/{{DOMAIN_NAME}}/$DOMAIN_NAME/g" \
    -e "s/{{SERVER_IP}}/$SERVER_IP/g" \
    "$TEMPLATE_FILE" > "$CONFIG_FILE"

# Check if the file was created successfully
if [ ! -s "$CONFIG_FILE" ]; then
    echo "Error: Failed to create the configuration file"
    if [ -n "$BACKUP_FILE" ]; then
        echo "Restoring backup..."
        cp "$BACKUP_FILE" "$CONFIG_FILE"
    fi
    exit 1
fi

# Set proper permissions
chmod 644 "$CONFIG_FILE"
chown root:root "$CONFIG_FILE"

echo "Configuration file generated successfully"

# Create necessary directories if they don't exist
echo "Checking/creating required directories..."
MAIN_LOGS_DIR="/var/www/html/${SITE_NAME}/logs"
TEST_SITE_DIR="/var/www/html/${SITE_NAME}_test/public_html"
TEST_LOGS_DIR="/var/www/html/${SITE_NAME}_test/logs"

if [ ! -d "$MAIN_LOGS_DIR" ]; then
    echo "Creating main logs directory: $MAIN_LOGS_DIR"
    mkdir -p "$MAIN_LOGS_DIR"
    chown www-data:www-data "$MAIN_LOGS_DIR"
fi

if [ ! -d "$TEST_SITE_DIR" ]; then
    echo "Creating test site directory: $TEST_SITE_DIR"
    mkdir -p "$TEST_SITE_DIR"
    chown www-data:www-data "$TEST_SITE_DIR"
    # Copy main site files to test site if main site exists
    MAIN_SITE_DIR="/var/www/html/${SITE_NAME}/public_html"
    if [ -d "$MAIN_SITE_DIR" ]; then
        echo "Copying main site files to test site..."
        cp -r "$MAIN_SITE_DIR"/* "$TEST_SITE_DIR/" 2>/dev/null || true
    fi
fi

if [ ! -d "$TEST_LOGS_DIR" ]; then
    echo "Creating test logs directory: $TEST_LOGS_DIR"
    mkdir -p "$TEST_LOGS_DIR"
    chown www-data:www-data "$TEST_LOGS_DIR"
fi

# Show what was generated (first 30 lines)
echo ""
echo "Generated configuration (first 30 lines):"
echo "=========================================="
head -30 "$CONFIG_FILE"
echo "=========================================="
echo ""

# Test Apache configuration
echo "Testing Apache configuration..."
CONFIG_TEST_OUTPUT=$(apache2ctl configtest 2>&1)
echo "Apache config test output: $CONFIG_TEST_OUTPUT"
if echo "$CONFIG_TEST_OUTPUT" | grep -q "Syntax OK"; then
    echo "Configuration test passed"
    
    # Restart Apache (use service command for Docker compatibility)
    echo "Restarting Apache..."
    service apache2 restart

    if [ $? -eq 0 ]; then
        echo "Apache restarted successfully"
        echo "Update completed successfully!"
        echo ""
        echo "Your site should now be available at: http://$DOMAIN_NAME"
        echo "Test site should be available at: http://test.$DOMAIN_NAME"
        echo "Static files should be available at: http://$DOMAIN_NAME/static_files/"
    else
        echo "Error: Failed to restart Apache"
        if [ -n "$BACKUP_FILE" ]; then
            echo "Restoring backup..."
            cp "$BACKUP_FILE" "$CONFIG_FILE"
            service apache2 restart
        fi
        exit 1
    fi
else
    echo "Error: Apache configuration test failed"
    if [ -n "$BACKUP_FILE" ]; then
        echo "Restoring backup..."
        cp "$BACKUP_FILE" "$CONFIG_FILE"
    else
        rm -f "$CONFIG_FILE"
    fi
    echo "Configuration restored/removed due to errors"
    exit 1
fi