#!/bin/bash

# WSL PHP 8.3 Minimal Setup Script
# This script installs PHP 8.3 with the same extensions and dependencies as your server
# Version 1.0 - Minimal PHP Setup

set -e  # Exit on any error

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}"
    exit 1
}

# Check if running with sudo
if [[ $EUID -ne 0 ]]; then
   error "This script must be run with sudo privileges. Please run: sudo ./php_setup.sh"
fi

# Get the actual user (not root)
ACTUAL_USER=${SUDO_USER:-$(logname 2>/dev/null || echo $USER)}
USER_HOME=$(eval echo ~$ACTUAL_USER)

log "Starting PHP 8.3 setup for WSL user: $ACTUAL_USER"

# Update system packages
log "Updating system packages..."
apt update && apt upgrade -y

# Install essential packages
log "Installing essential packages..."
apt install -y curl wget git unzip software-properties-common

# Install PHP 8.3 and extensions
log "Installing PHP 8.3 with all extensions..."
apt install -y \
    php8.3 \
    php8.3-cli \
    php8.3-common \
    php8.3-pgsql \
    php8.3-xml \
    php8.3-curl \
    php8.3-gd \
    php8.3-imagick \
    php8.3-dev \
    php8.3-imap \
    php8.3-mbstring \
    php8.3-opcache \
    php8.3-soap \
    php8.3-zip \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-readline

log "PHP installation completed. Version: $(php -v | head -n1)"

# Install Composer
log "Installing Composer..."
cd /tmp
export COMPOSER_ALLOW_SUPERUSER=1
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

log "Composer installed. Version: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version)"

# Configure PHP for development
log "Configuring PHP settings..."
cp /etc/php/8.3/cli/php.ini /etc/php/8.3/cli/php.ini.backup

# Update PHP settings to match server but with development-friendly values
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' /etc/php/8.3/cli/php.ini
sed -i 's/post_max_size = .*/post_max_size = 32M/' /etc/php/8.3/cli/php.ini
sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/8.3/cli/php.ini
sed -i 's/memory_limit = .*/memory_limit = 256M/' /etc/php/8.3/cli/php.ini
sed -i 's/;date.timezone =/date.timezone = America\/New_York/' /etc/php/8.3/cli/php.ini
sed -i 's/display_errors = Off/display_errors = On/' /etc/php/8.3/cli/php.ini
sed -i 's/error_reporting = .*/error_reporting = E_ALL/' /etc/php/8.3/cli/php.ini

# Enable PDO PostgreSQL extension
sed -i 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' /etc/php/8.3/cli/php.ini
sed -i 's/^;extension=pgsql/extension=pgsql/' /etc/php/8.3/cli/php.ini

log "PHP configured with development settings and PostgreSQL extensions enabled"

# Set up development directory
log "Setting up development directory..."
mkdir -p $USER_HOME/dev
cd $USER_HOME/dev

# Create composer.json with the same dependencies as the server
tee composer.json > /dev/null << 'EOF'
{
    "require": {
        "mailgun/mailgun-php": "^3.2",
        "kriswallsmith/buzz": "^1.2",
        "nyholm/psr7": "^1.3",
        "jhut89/mailchimp3php": "^3.2",
        "zenapply/php-calendly": "^1.0",
        "verot/class.upload.php": "^2.1",
        "tm/error-log-parser": "^1.2",
        "stripe/stripe-php": "^10.16",
        "phpmailer/phpmailer": "^6.10.0"
    }
}
EOF

# Install dependencies
log "Installing PHP dependencies with Composer..."
COMPOSER_ALLOW_SUPERUSER=1 composer install

# Fix ownership for the actual user
chown -R $ACTUAL_USER:$ACTUAL_USER $USER_HOME/dev

log "PHP dependencies installed in $USER_HOME/dev/vendor/"



# Display completion message
log "PHP 8.3 setup completed successfully!"
echo ""
echo -e "${GREEN}=== SETUP SUMMARY ===${NC}"
echo "✓ PHP 8.3 with all required extensions installed"
echo "✓ Composer installed globally"
echo "✓ PHP dependencies installed in $USER_HOME/dev/vendor/"
echo "✓ PHP configured with development settings"
echo ""
echo -e "${GREEN}=== USAGE ===${NC}"
echo "• PHP command: php"
echo "• Composer command: composer"  
echo "• Dependencies location: $USER_HOME/dev/vendor/"
echo "• Use in projects: require_once '$USER_HOME/dev/vendor/autoload.php';"
echo ""
echo -e "${GREEN}PHP is ready to use!${NC}"