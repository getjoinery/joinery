#!/bin/bash

# WSL Development Environment Setup Script for Ubuntu 24.04
# This script sets up LAMP stack with Composer for local development
# Version 1.0 - WSL Development Environment

# CONFIGURATION - Edit these values before running (optional)
POSTGRES_PASSWORD="devpassword123"  # Default dev password, change as needed

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}"
    exit 1
}

warning() {
    echo -e "${YELLOW}[WARNING] $1${NC}"
}

info() {
    echo -e "${GREEN}[INFO] $1${NC}"
}

# Check if running with sudo
if [[ $EUID -ne 0 ]]; then
   error "This script must be run with sudo privileges. Please run: sudo ./wsl_dev_setup.sh"
fi

# Check if we're on Ubuntu (WSL compatible)
if ! grep -q "Ubuntu" /etc/os-release; then
    warning "This script is designed for Ubuntu. Continuing anyway..."
fi

# Get the actual user (not root)
ACTUAL_USER=${SUDO_USER:-$(logname 2>/dev/null || echo $USER)}
USER_HOME=$(eval echo ~$ACTUAL_USER)

log "Starting WSL development environment setup for user: $ACTUAL_USER"

# Update system packages
log "Updating system packages..."
apt update && apt upgrade -y

# Install essential packages (removed production security tools)
log "Installing essential packages..."
apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates gnupg lsb-release build-essential

# Install PHP 8.3 and extensions (removed libapache2-mod-php8.3 since we'll use CLI primarily)
log "Installing PHP 8.3..."
apt install -y \
    php8.3 \
    php8.3-fpm \
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

# Set up development directory structure
log "Setting up development directories..."
mkdir -p $USER_HOME/dev/projects
mkdir -p $USER_HOME/dev/vendor
cd $USER_HOME/dev

# Create a composer.json with the same dependencies as the server
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
chmod -R 755 $USER_HOME/dev

log "PHP dependencies installed in $USER_HOME/dev/vendor/ with proper permissions"

# Install Apache (optional for local development)
log "Installing Apache web server..."
apt install -y apache2 libapache2-mod-php8.3

# Enable Apache modules
log "Enabling Apache modules..."
a2enmod rewrite
a2enmod php8.3

# Configure Apache for development (less restrictive)
log "Configuring Apache for development..."
cp /etc/apache2/apache2.conf /etc/apache2/apache2.conf.backup

# Set global ServerName to suppress warning messages
echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Create development virtual host pointing to user's dev directory
tee /etc/apache2/sites-available/dev.conf > /dev/null << EOF
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot $USER_HOME/dev/projects
    
    <Directory $USER_HOME/dev/projects>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/dev_error.log
    CustomLog \${APACHE_LOG_DIR}/dev_access.log combined
</VirtualHost>
EOF

# Enable the dev site and disable default
a2ensite dev
a2dissite 000-default

log "Apache configured for development with document root at $USER_HOME/dev/projects"

# Install PostgreSQL Database
log "Installing PostgreSQL server..."
apt install -y postgresql postgresql-contrib

# Start and enable PostgreSQL
systemctl start postgresql
systemctl enable postgresql

# Configure PostgreSQL for development (less restrictive)
log "Configuring PostgreSQL for development..."

# Get PostgreSQL version for config paths
PG_VERSION=$(psql --version | grep -oP '\d+\.\d+' | head -1 | cut -d. -f1)
PG_CONFIG_DIR="/etc/postgresql/${PG_VERSION}/main"

log "PostgreSQL version detected: ${PG_VERSION}"

# Backup original configuration files
cp ${PG_CONFIG_DIR}/pg_hba.conf ${PG_CONFIG_DIR}/pg_hba.conf.backup
cp ${PG_CONFIG_DIR}/postgresql.conf ${PG_CONFIG_DIR}/postgresql.conf.backup

# Configure authentication for development (more permissive)
tee ${PG_CONFIG_DIR}/pg_hba.conf > /dev/null << 'EOF'
# PostgreSQL Client Authentication Configuration File - Development
# ================================================================

# TYPE  DATABASE        USER            ADDRESS                 METHOD

# "local" is for Unix domain socket connections only
local   all             postgres                                trust
local   all             all                                     trust

# IPv4 local connections:
host    all             all             127.0.0.1/32            trust
host    all             all             ::1/128                 trust
EOF

# Restart PostgreSQL to apply configuration
systemctl restart postgresql

# Set PostgreSQL postgres user password
log "Setting PostgreSQL postgres user password..."
su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres

# Create database user for development
log "Creating database user for development..."
su -c "createuser -d -e -E -l -P -r -s $ACTUAL_USER" postgres || true

log "PostgreSQL configured for development"

# Configure PHP for development
log "Configuring PHP settings for development..."
cp /etc/php/8.3/apache2/php.ini /etc/php/8.3/apache2/php.ini.backup
cp /etc/php/8.3/cli/php.ini /etc/php/8.3/cli/php.ini.backup

# Development-friendly PHP settings
for php_ini in "/etc/php/8.3/apache2/php.ini" "/etc/php/8.3/cli/php.ini"; do
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' $php_ini
    sed -i 's/post_max_size = .*/post_max_size = 64M/' $php_ini
    sed -i 's/max_execution_time = .*/max_execution_time = 0/' $php_ini
    sed -i 's/memory_limit = .*/memory_limit = 512M/' $php_ini
    sed -i 's/;date.timezone =/date.timezone = America\/New_York/' $php_ini
    sed -i 's/display_errors = Off/display_errors = On/' $php_ini
    sed -i 's/display_startup_errors = Off/display_startup_errors = On/' $php_ini
    sed -i 's/error_reporting = .*/error_reporting = E_ALL/' $php_ini
    sed -i 's/;extension=pdo_pgsql/extension=pdo_pgsql/' $php_ini
    sed -i 's/;extension=pgsql/extension=pgsql/' $php_ini
done

log "PHP configured for development with error reporting enabled"

# Start services
log "Starting services..."
systemctl start apache2
systemctl enable apache2
systemctl start postgresql
systemctl enable postgresql
systemctl start php8.3-fpm
systemctl enable php8.3-fpm

# Create a sample index.php for testing
log "Creating sample development files..."
tee $USER_HOME/dev/projects/index.php > /dev/null << 'EOF'
<?php
echo "<h1>WSL Development Environment</h1>";
echo "<h2>PHP Information</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server Time:</strong> " . date('Y-m-d H:i:s') . "</p>";

echo "<h2>Extensions Status</h2>";
$extensions = ['pgsql', 'pdo_pgsql', 'curl', 'gd', 'imagick', 'mbstring', 'xml', 'zip', 'bcmath', 'intl'];
echo "<ul>";
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? "✓" : "✗";
    $color = extension_loaded($ext) ? "green" : "red";
    echo "<li style='color: $color;'>$ext: $status</li>";
}
echo "</ul>";

echo "<h2>Composer Packages</h2>";
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    echo "<p style='color: green;'>Composer autoloader: ✓</p>";
    
    $packages = ['Mailgun\\Mailgun', 'Stripe\\Stripe', 'PHPMailer\\PHPMailer\\PHPMailer'];
    echo "<ul>";
    foreach ($packages as $class) {
        $status = class_exists($class) ? "✓" : "✗";
        $color = class_exists($class) ? "green" : "red";
        echo "<li style='color: $color;'>$class: $status</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>Composer autoloader: ✗</p>";
}

echo "<h2>Database Connection Test</h2>";
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=postgres', 'postgres', 'devpassword123');
    echo "<p style='color: green;'>PostgreSQL connection: ✓</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>PostgreSQL connection: ✗ (" . $e->getMessage() . ")</p>";
}

phpinfo();
?>
EOF

# Fix ownership
chown -R $ACTUAL_USER:$ACTUAL_USER $USER_HOME/dev

# Create helpful development scripts
tee $USER_HOME/dev/start-dev.sh > /dev/null << 'EOF'
#!/bin/bash
echo "Starting development services..."
sudo systemctl start apache2
sudo systemctl start postgresql
sudo systemctl start php8.3-fpm
echo "Development environment started!"
echo "Visit: http://localhost"
EOF

tee $USER_HOME/dev/stop-dev.sh > /dev/null << 'EOF'
#!/bin/bash
echo "Stopping development services..."
sudo systemctl stop apache2
sudo systemctl stop postgresql
sudo systemctl stop php8.3-fpm
echo "Development environment stopped!"
EOF

chmod +x $USER_HOME/dev/start-dev.sh
chmod +x $USER_HOME/dev/stop-dev.sh
chown $ACTUAL_USER:$ACTUAL_USER $USER_HOME/dev/*.sh

# Display completion message
log "WSL Development environment setup completed successfully!"
echo ""
info "=== DEVELOPMENT SETUP SUMMARY ==="
info "✓ PHP 8.3 with all extensions installed"
info "✓ Composer installed globally"
info "✓ Development dependencies installed in $USER_HOME/dev/vendor/"
info "✓ Apache web server configured for development"
info "✓ PostgreSQL database configured (trust authentication for localhost)"
info "✓ PHP configured for development (errors enabled, higher limits)"
info "✓ Development directory structure created in $USER_HOME/dev/"
info "✓ Sample files created for testing"
echo ""
info "=== DEVELOPMENT DETAILS ==="
info "• Web root: $USER_HOME/dev/projects/"
info "• Dependencies: $USER_HOME/dev/vendor/"
info "• PostgreSQL postgres password: $POSTGRES_PASSWORD"
info "• Local URL: http://localhost"
info "• Start services: $USER_HOME/dev/start-dev.sh"
info "• Stop services: $USER_HOME/dev/stop-dev.sh"
echo ""
warning "=== NEXT STEPS ==="
warning "1. Test your setup by visiting: http://localhost"
warning "2. Place your PHP projects in: $USER_HOME/dev/projects/"
warning "3. Use the vendor directory in your projects: require_once '../vendor/autoload.php';"
warning "4. Create databases as needed: createdb myproject"
echo ""
info "Development environment is ready!"
echo ""
log "Setup script completed!"