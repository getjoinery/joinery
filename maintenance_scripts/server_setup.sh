#!/bin/bash

# Complete Linode Server Setup Script for Ubuntu 24.04
# This script sets up LAMP stack with Composer and your specified dependencies
# Version 1.02 - Added PHP dependencies setup

# CONFIGURATION - Edit these values before running (optional)
POSTGRES_PASSWORD=""  # Leave blank to be prompted, or set your desired PostgreSQL password here

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
   error "This script must be run with sudo privileges. Please run: sudo ./setup_server.sh"
fi

# Check if we're on Ubuntu 24.04
if ! grep -q "Ubuntu 24.04" /etc/os-release; then
    warning "This script is designed for Ubuntu 24.04. Continuing anyway..."
fi

log "Starting complete server setup..."

# Prompt for PostgreSQL password if not set
if [[ -z "$POSTGRES_PASSWORD" ]]; then
    log "PostgreSQL password not set in configuration."
    echo -n "Please enter a password for PostgreSQL postgres user: "
    read -s POSTGRES_PASSWORD
    echo ""
    
    if [[ -z "$POSTGRES_PASSWORD" ]]; then
        error "PostgreSQL password cannot be empty. Please edit the script or provide a password."
    fi
    
    echo -n "Confirm password: "
    read -s POSTGRES_PASSWORD_CONFIRM
    echo ""
    
    if [[ "$POSTGRES_PASSWORD" != "$POSTGRES_PASSWORD_CONFIRM" ]]; then
        error "Passwords do not match. Please run the script again."
    fi
    
    log "PostgreSQL password set successfully."
fi

# Update system packages
log "Updating system packages..."
apt update && apt upgrade -y

# Install essential packages
log "Installing essential packages..."
apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates gnupg lsb-release build-essential fail2ban

# Configure user1 (assumes user1 already exists)
log "Configuring user1..."
if id "user1" &>/dev/null; then
    # Set up SSH directory for user1 if it doesn't exist
    mkdir -p /home/user1/.ssh
    chmod 700 /home/user1/.ssh
    chown user1:user1 /home/user1/.ssh
    
    # Create authorized_keys file if it doesn't exist
    touch /home/user1/.ssh/authorized_keys
    chmod 600 /home/user1/.ssh/authorized_keys
    chown user1:user1 /home/user1/.ssh/authorized_keys
    
    log "user1 configured successfully"
else
    error "user1 does not exist. Please create user1 before running this script."
fi

# Install PHP 8.3 and extensions
log "Installing PHP 8.3 from Ubuntu repositories..."
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
    php8.3-readline \
    libapache2-mod-php8.3

log "PHP installation completed. Version: $(php -v | head -n1)"

# Install Composer
log "Installing Composer..."
cd /tmp
export COMPOSER_ALLOW_SUPERUSER=1
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

log "Composer installed. Version: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version)"

# Composer dependencies will be managed per-site during deployment
log "Composer installed and ready for per-site dependency management"

# Install Apache
log "Installing Apache web server..."
apt install -y apache2

# Enable Apache modules
log "Enabling Apache modules..."
a2enmod rewrite
a2enmod ssl
a2enmod headers
a2enmod php8.3

# Configure Apache settings
log "Configuring Apache..."
cp /etc/apache2/apache2.conf /etc/apache2/apache2.conf.backup

# Update Apache configuration for /var/www/ directory
sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ {
    s/Options Indexes FollowSymLinks/Options -Indexes +FollowSymLinks/
    s/AllowOverride None/AllowOverride All/
}' /etc/apache2/apache2.conf

# Set global ServerName to suppress warning messages
echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Ensure proper configuration for rewrite rules
cat >> /etc/apache2/apache2.conf << 'EOF'

# Global settings for membership applications
<Directory /var/www/html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF

log "Apache configured with AllowOverride All, disabled directory indexes, and enabled FollowSymLinks for rewrite rules"

# Install PostgreSQL Database
log "Installing PostgreSQL server..."
apt install -y postgresql postgresql-contrib

# Start and enable PostgreSQL
systemctl start postgresql
systemctl enable postgresql

# Configure PostgreSQL
log "Configuring PostgreSQL..."

# Get PostgreSQL version for config paths
PG_VERSION=$(psql --version | grep -oP '\d+\.\d+' | head -1 | cut -d. -f1)
PG_CONFIG_DIR="/etc/postgresql/${PG_VERSION}/main"

log "PostgreSQL version detected: ${PG_VERSION}"

# Backup original configuration files
cp ${PG_CONFIG_DIR}/pg_hba.conf ${PG_CONFIG_DIR}/pg_hba.conf.backup
cp ${PG_CONFIG_DIR}/postgresql.conf ${PG_CONFIG_DIR}/postgresql.conf.backup

# Configure authentication in pg_hba.conf
log "Configuring PostgreSQL authentication..."
tee ${PG_CONFIG_DIR}/pg_hba.conf > /dev/null << 'EOF'
# PostgreSQL Client Authentication Configuration File
# ===================================================

# TYPE  DATABASE        USER            ADDRESS                 METHOD

# "local" is for Unix domain socket connections only
local   all             postgres                                md5
local   all             all                                     md5

# IPv4 local connections:
host    all             all             127.0.0.1/32            md5
host    all             all             0.0.0.0/0               md5

# IPv6 local connections:
host    all             all             ::1/128                 md5

# Allow replication connections from localhost, by a user with the
# replication privilege.
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            md5
host    replication     all             ::1/128                 md5
EOF

# Configure PostgreSQL to listen on port 5432
log "Configuring PostgreSQL to listen on port 5432..."
sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/" ${PG_CONFIG_DIR}/postgresql.conf
sed -i "s/#port = 5432/port = 5432/" ${PG_CONFIG_DIR}/postgresql.conf

# Restart PostgreSQL to apply configuration
systemctl restart postgresql

# Set PostgreSQL postgres user password automatically
log "Setting PostgreSQL postgres user password..."

# Temporarily allow trust authentication for postgres user to set password
sed -i 's/local   all             postgres                                md5/local   all             postgres                                trust/' ${PG_CONFIG_DIR}/pg_hba.conf

# Reload PostgreSQL configuration
systemctl reload postgresql

# Set the postgres user password
su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres

# Restore secure md5 authentication
sed -i 's/local   all             postgres                                trust/local   all             postgres                                md5/' ${PG_CONFIG_DIR}/pg_hba.conf

# Reload PostgreSQL configuration again
systemctl reload postgresql

log "PostgreSQL postgres user password set successfully"

log "PostgreSQL configured and listening on port 5432"

# Start and enable services
log "Starting and enabling services..."
systemctl start apache2
systemctl enable apache2
systemctl start postgresql
systemctl enable postgresql
systemctl start php8.3-fpm
systemctl enable php8.3-fpm

# Install Certbot for SSL
log "Installing Certbot for SSL certificates..."
apt install -y certbot python3-certbot-apache

# Configure PHP for production
log "Configuring PHP settings..."
cp /etc/php/8.3/apache2/php.ini /etc/php/8.3/apache2/php.ini.backup

# Update PHP settings optimized for 1GB VPS
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' /etc/php/8.3/apache2/php.ini
sed -i 's/post_max_size = .*/post_max_size = 32M/' /etc/php/8.3/apache2/php.ini
sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/8.3/apache2/php.ini
sed -i 's/memory_limit = .*/memory_limit = 128M/' /etc/php/8.3/apache2/php.ini
sed -i 's/;date.timezone =/date.timezone = America\/New_York/' /etc/php/8.3/apache2/php.ini

# Enable PDO PostgreSQL extension
sed -i 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' /etc/php/8.3/apache2/php.ini
sed -i 's/^;extension=pgsql/extension=pgsql/' /etc/php/8.3/apache2/php.ini

log "PDO PostgreSQL extension enabled"

# Configure SSH security
log "Configuring SSH security..."
cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup

# Disable root login via SSH
sed -i 's/#PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config

# Additional SSH hardening
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/#PermitEmptyPasswords no/PermitEmptyPasswords no/' /etc/ssh/sshd_config
sed -i 's/PermitEmptyPasswords yes/PermitEmptyPasswords no/' /etc/ssh/sshd_config
sed -i 's/#MaxAuthTries 6/MaxAuthTries 3/' /etc/ssh/sshd_config
sed -i 's/#ClientAliveInterval 0/ClientAliveInterval 300/' /etc/ssh/sshd_config
sed -i 's/#ClientAliveCountMax 3/ClientAliveCountMax 2/' /etc/ssh/sshd_config

# Restart SSH service to apply changes
systemctl restart ssh

log "SSH security configured: root login disabled, connection limits set"

# Configure UFW firewall
log "Configuring firewall..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow http
ufw allow https
ufw allow 5432
ufw --force enable

# Configure fail2ban
log "Configuring fail2ban..."
systemctl start fail2ban
systemctl enable fail2ban

# Create basic fail2ban jail configuration using defaults
cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

# Enable SSH protection (most important)
tee -a /etc/fail2ban/jail.local > /dev/null << 'EOF'

# Enable SSH protection
[sshd]
enabled = true

# Enable basic Apache protection
[apache-auth]
enabled = true

[apache-badbots]
enabled = true

[apache-noscript]
enabled = true

[apache-overflows]
enabled = true
EOF

# Restart fail2ban to apply configuration
systemctl restart fail2ban

log "fail2ban configured with default settings and basic protections enabled"

# Install automatic security updates
log "Configuring automatic security updates..."
apt install -y unattended-upgrades apt-listchanges

# Configure automatic security updates
tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
EOF

# Configure unattended upgrades for security updates only
tee /etc/apt/apt.conf.d/50unattended-upgrades > /dev/null << 'EOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Package-Blacklist {
};
Unattended-Upgrade::DevRelease "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Automatic-Reboot-Time "02:00";
EOF

log "Automatic security updates configured"

# Security hardening
log "Applying additional security hardening..."

# Disable unused network protocols
echo "install dccp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
echo "install sctp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
echo "install rds /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
echo "install tipc /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf

# Set kernel parameters for security
tee /etc/sysctl.d/99-security.conf > /dev/null << 'EOF'
# IP Spoofing protection
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.rp_filter = 1

# Ignore ICMP redirects
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0

# Ignore send redirects
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0

# Disable source packet routing
net.ipv4.conf.all.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.default.accept_source_route = 0

# Log Martians
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# Ignore ICMP ping requests
net.ipv4.icmp_echo_ignore_all = 0

# Ignore Directed pings
net.ipv4.icmp_echo_ignore_broadcasts = 1

# Disable IPv6 if not needed
net.ipv6.conf.all.disable_ipv6 = 0
net.ipv6.conf.default.disable_ipv6 = 0

# TCP SYN flood protection
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2
net.ipv4.tcp_syn_retries = 5

# Control Buffer Overflow attacks (Ubuntu uses different mechanisms)
kernel.randomize_va_space = 2
EOF

# Apply sysctl settings (ignore errors for non-existent parameters)
sysctl -p /etc/sysctl.d/99-security.conf || true

log "Security hardening applied"

# Set proper permissions for web directory
log "Setting up web directory permissions..."
chown -R www-data:www-data /var/www/
chmod -R 755 /var/www/

# Add user1 to www-data group for web development
usermod -aG www-data user1

# Restart services
log "Restarting services..."
systemctl restart apache2
systemctl restart php8.3-fpm

# Display completion message
log "Server setup completed successfully!"
echo ""
info "=== SETUP SUMMARY ==="
info "✓ Ubuntu 24.04 system updated"
info "✓ user1 configured with SSH setup"
info "✓ PHP 8.3 with required extensions installed"
info "✓ Composer installed globally"
info "✓ Composer installed globally (dependencies managed per-site during deployment)"
info "✓ Apache web server configured"
info "✓ PostgreSQL 16.x database server installed and configured"
info "✓ PostgreSQL postgres user password set (see POSTGRES_PASSWORD variable)"
info "✓ Certbot for SSL certificates installed"
info "✓ UFW firewall configured"
info "✓ fail2ban installed and configured with SSH and Apache protection"
info "✓ SSH security configured (root login disabled, connection limits set)"
info "✓ Automatic security updates enabled"
info "✓ System security hardening applied"
info "✓ Web directory permissions set up"
echo ""
warning "=== NEXT STEPS ==="
warning "1. Add your SSH public key to /home/user1/.ssh/authorized_keys"
warning "2. Configure PostgreSQL database users:"
warning "   - PostgreSQL postgres password is: ${POSTGRES_PASSWORD}"
warning "   - Create user1 database user: sudo createuser -U postgres -d -e -E -l -P -r -s user1"
warning "   - Create application databases as needed"
warning "3. Set up SSL certificates as needed"
echo ""
info "Server is ready for web application deployment!"
echo ""
log "Setup script completed!"