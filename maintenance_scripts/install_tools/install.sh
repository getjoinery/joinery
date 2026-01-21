#!/usr/bin/env bash
#VERSION 1.2 - Added -y/--yes and -q/--quiet flags for non-interactive/scripted deployments
#
# Usage:
#   ./install.sh docker                              # One-time: install Docker
#   ./install.sh server                              # One-time: set up bare-metal server
#   ./install.sh site SITENAME PASS DOMAIN [PORT]   # Create a site
#   ./install.sh list                                # List existing sites
#
# Global Options:
#   -y, --yes     Auto-accept all prompts (non-interactive mode)
#   -q, --quiet   Suppress most output, show only errors and final status
#
# Examples:
#   # Docker deployment
#   sudo ./install.sh docker
#   sudo ./install.sh site mysite SecurePass123! mysite.com 8080
#
#   # Non-interactive deployment (for scripting/CI)
#   sudo ./install.sh -y docker
#   sudo ./install.sh -y -q site mysite SecurePass123! mysite.com 8080
#
#   # Bare-metal deployment
#   sudo ./install.sh server
#   sudo ./install.sh site mysite SecurePass123! mysite.com
#
# See INSTALL_README.md for complete documentation.

set -e  # Exit on error

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

#==============================================================================
# GLOBAL FLAGS (parsed before command dispatch)
#==============================================================================

ASSUME_YES=0      # -y/--yes: Auto-accept all prompts
QUIET_MODE=0      # -q/--quiet: Suppress most output

#==============================================================================
# HELPER FUNCTIONS (from docker_install_master.sh)
#==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_step() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${GREEN}[STEP]${NC} $1"
}

print_info() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_warning() {
    # Warnings always shown (even in quiet mode)
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    # Errors always shown (even in quiet mode)
    echo -e "${RED}[ERROR]${NC} $1"
}

print_success() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${GREEN}[OK]${NC} $1"
}

# Final summary output (always shown, even in quiet mode)
print_final() {
    echo -e "$1"
}

#==============================================================================
# PORT MANAGEMENT FUNCTIONS (from docker_install_master.sh)
#==============================================================================

# Check if a port is in use (by system or Docker)
is_port_in_use() {
    local port=$1

    # Check system ports using ss (preferred) or netstat
    if command -v ss &> /dev/null; then
        if ss -tuln | grep -q ":${port} "; then
            return 0
        fi
    elif command -v netstat &> /dev/null; then
        if netstat -tuln | grep -q ":${port} "; then
            return 0
        fi
    fi

    # Check Docker container port mappings
    if command -v docker &> /dev/null && docker info &> /dev/null 2>&1; then
        if docker ps --format '{{.Ports}}' 2>/dev/null | grep -q "0.0.0.0:${port}->"; then
            return 0
        fi
    fi

    return 1
}

# Find next available port starting from given port
find_available_port() {
    local start_port=$1
    local port=$start_port
    local max_port=$((start_port + 100))

    while [ $port -lt $max_port ]; do
        if ! is_port_in_use $port && ! is_port_in_use $((port + 1000)); then
            echo $port
            return 0
        fi
        port=$((port + 1))
    done

    echo ""
    return 1
}

# List existing Joinery Docker containers with their ports
list_docker_containers() {
    echo ""
    echo -e "${BLUE}Docker Containers:${NC}"
    echo "───────────────────────────────────────────────────────────────"
    printf "%-20s %-15s %-12s %s\n" "SITE NAME" "WEB PORT" "DB PORT" "STATUS"
    echo "───────────────────────────────────────────────────────────────"

    local found=0
    while IFS= read -r line; do
        if [ -n "$line" ]; then
            local name=$(echo "$line" | awk '{print $1}')
            local ports=$(echo "$line" | awk '{print $2}')
            local status=$(echo "$line" | awk '{$1=$2=""; print $0}' | xargs)

            # Extract web port (format: 0.0.0.0:8080->80/tcp)
            local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
            local db_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->5432)' | head -1)

            if [ -n "$web_port" ]; then
                printf "%-20s %-15s %-12s %s\n" "$name" "$web_port" "${db_port:-N/A}" "$status"
                found=1
            fi
        fi
    done < <(docker ps -a --filter "ancestor=joinery-*" --format "{{.Names}} {{.Ports}} {{.Status}}" 2>/dev/null)

    # Also check by naming convention if ancestor filter didn't work
    if [ $found -eq 0 ]; then
        while IFS= read -r line; do
            if [ -n "$line" ]; then
                local name=$(echo "$line" | awk '{print $1}')
                local image=$(echo "$line" | awk '{print $2}')
                local ports=$(echo "$line" | awk '{print $3}')
                local status=$(echo "$line" | awk '{$1=$2=$3=""; print $0}' | xargs)

                # Check if image starts with joinery-
                if [[ "$image" == joinery-* ]]; then
                    local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
                    local db_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->5432)' | head -1)

                    printf "%-20s %-15s %-12s %s\n" "$name" "${web_port:-N/A}" "${db_port:-N/A}" "$status"
                    found=1
                fi
            fi
        done < <(docker ps -a --format "{{.Names}} {{.Image}} {{.Ports}} {{.Status}}" 2>/dev/null)
    fi

    if [ $found -eq 0 ]; then
        echo "  (no existing Joinery containers found)"
    fi
    echo "───────────────────────────────────────────────────────────────"
    echo ""
}

# List existing Joinery bare-metal sites
list_baremetal_sites() {
    echo ""
    echo -e "${BLUE}Bare-Metal Sites:${NC}"
    echo "───────────────────────────────────────────────────────────────"
    printf "%-30s %-20s %s\n" "SITE NAME" "DOMAIN" "STATUS"
    echo "───────────────────────────────────────────────────────────────"

    local found=0
    for site_dir in /var/www/html/*/; do
        # Skip if no directories found
        [ -d "$site_dir" ] || continue

        local site_name=$(basename "$site_dir")

        # Skip test sites and common non-site directories
        [[ "$site_name" == *_test ]] && continue
        [[ "$site_name" == "html" ]] && continue

        # Check if it looks like a Joinery site (has public_html and config)
        if [ -d "${site_dir}public_html" ] && [ -d "${site_dir}config" ]; then
            # Try to extract domain from config
            local domain="N/A"
            local config_file="${site_dir}config/Globalvars_site.php"
            if [ -f "$config_file" ]; then
                domain=$(grep -oP "site_url.*?'https?://\K[^'/]+" "$config_file" 2>/dev/null | head -1 || echo "N/A")
            fi

            # Check if Apache virtualhost is enabled
            local status="configured"
            if [ -f "/etc/apache2/sites-enabled/${site_name}.conf" ]; then
                status="active"
            elif [ -f "/etc/apache2/sites-available/${site_name}.conf" ]; then
                status="disabled"
            fi

            printf "%-30s %-20s %s\n" "$site_name" "$domain" "$status"
            found=1
        fi
    done

    if [ $found -eq 0 ]; then
        echo "  (no existing Joinery sites found)"
    fi
    echo "───────────────────────────────────────────────────────────────"
    echo ""
}

#==============================================================================
# ENVIRONMENT DETECTION (from server_setup.sh)
#==============================================================================

# Detect if running in Docker (including during docker build)
is_docker() {
    # Check for running container
    [ -f /.dockerenv ] && return 0

    # Check cgroup for running container
    grep -q docker /proc/1/cgroup 2>/dev/null && return 0

    # Check for Docker build environment (no systemd running)
    [ ! -d /run/systemd/system ] && return 0

    return 1
}

# Check if Docker is installed and running
is_docker_available() {
    command -v docker &> /dev/null && docker info &> /dev/null 2>&1
}

# Check if bare-metal prerequisites are met
check_bare_metal_ready() {
    local missing=()

    command -v apache2 &> /dev/null || missing+=("Apache")
    command -v psql &> /dev/null || missing+=("PostgreSQL")
    command -v php &> /dev/null || missing+=("PHP")

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing prerequisites: ${missing[*]}"
        print_info "Run './install.sh server' first to set up the base server"
        return 1
    fi
    return 0
}

#==============================================================================
# SERVICE MANAGEMENT (from server_setup.sh)
#==============================================================================

# Prevent services from auto-starting during package installation (Docker)
prevent_service_start() {
    printf '#!/bin/sh\nexit 101' > /usr/sbin/policy-rc.d
    chmod +x /usr/sbin/policy-rc.d
}

# Allow services to auto-start again
allow_service_start() {
    rm -f /usr/sbin/policy-rc.d
}

# Service management that works in both Docker and traditional environments
service_start() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" start || true
    else
        systemctl start "$service_name"
        systemctl enable "$service_name"
    fi
}

service_stop() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" stop || true
    else
        systemctl stop "$service_name"
    fi
}

service_restart() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" restart || true
    else
        systemctl restart "$service_name"
    fi
}

service_reload() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" reload || true
    else
        systemctl reload "$service_name"
    fi
}

#==============================================================================
# SUBCOMMAND: docker - Install Docker on the server
#==============================================================================

do_docker_install() {
    print_header "Docker Installation"

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    print_step "Checking Docker installation..."

    if command -v docker &> /dev/null; then
        DOCKER_VERSION=$(docker --version)
        print_success "Docker is already installed: $DOCKER_VERSION"

        # Verify Docker is running
        if ! docker info &> /dev/null; then
            print_warning "Docker daemon is not running. Starting Docker..."
            systemctl start docker
            sleep 2
            if ! docker info &> /dev/null; then
                print_error "Failed to start Docker daemon"
                exit 1
            fi
            print_success "Docker daemon started"
        else
            print_success "Docker daemon is running"
        fi
        exit 0
    fi

    print_info "Docker is not installed"

    if [ "$ASSUME_YES" -eq 1 ]; then
        print_info "Auto-accepting Docker installation (-y flag)"
    else
        echo ""
        read -p "Would you like to install Docker now? [Y/n] " -n 1 -r
        echo ""

        if [[ $REPLY =~ ^[Nn]$ ]]; then
            print_info "Docker installation cancelled"
            exit 0
        fi
    fi

    print_step "Installing Docker..."

    # Update packages
    apt-get update

    # Install prerequisites
    apt-get install -y ca-certificates curl gnupg lsb-release

    # Add Docker's GPG key
    mkdir -m 0755 -p /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

    # Add Docker repository
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

    # Install Docker
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io

    # Verify installation
    if command -v docker &> /dev/null; then
        print_success "Docker installed successfully"
        DOCKER_VERSION=$(docker --version)
        print_info "$DOCKER_VERSION"

        # Start Docker
        systemctl start docker
        systemctl enable docker

        if docker info &> /dev/null; then
            print_success "Docker daemon is running"
        else
            print_warning "Docker installed but daemon is not running"
        fi
    else
        print_error "Docker installation failed"
        exit 1
    fi

    if [ "$QUIET_MODE" -eq 1 ]; then
        echo -e "${GREEN}Docker installation complete!${NC}"
    else
        print_success "Docker installation complete!"
    fi
}

#==============================================================================
# SUBCOMMAND: server - Set up bare-metal server (integrated from server_setup.sh)
#==============================================================================

do_server_setup() {
    print_header "Bare-Metal Server Setup"

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    # Check if we're on Ubuntu 24.04
    if ! grep -q "Ubuntu 24.04" /etc/os-release; then
        print_warning "This script is designed for Ubuntu 24.04. Continuing anyway..."
    fi

    # Get PostgreSQL password
    POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-}"

    if [[ -z "$POSTGRES_PASSWORD" ]]; then
        print_info "PostgreSQL password not set."
        echo -n "Please enter a password for PostgreSQL postgres user: "
        read -s POSTGRES_PASSWORD
        echo ""

        if [[ -z "$POSTGRES_PASSWORD" ]]; then
            print_error "PostgreSQL password cannot be empty."
            exit 1
        fi

        echo -n "Confirm password: "
        read -s POSTGRES_PASSWORD_CONFIRM
        echo ""

        if [[ "$POSTGRES_PASSWORD" != "$POSTGRES_PASSWORD_CONFIRM" ]]; then
            print_error "Passwords do not match. Please run the script again."
            exit 1
        fi

        print_success "PostgreSQL password set successfully."
    fi

    # Update system packages
    print_step "Updating system packages..."
    apt update && apt upgrade -y

    # Install essential packages
    print_step "Installing essential packages..."
    apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates gnupg lsb-release build-essential fail2ban

    # Create and configure user1
    print_step "Setting up user1..."

    if ! id "user1" &>/dev/null; then
        print_info "Creating user1..."
        useradd -m -s /bin/bash user1
        print_success "user1 created"
    fi

    # Configure user1's SSH directory
    mkdir -p /home/user1/.ssh
    chmod 700 /home/user1/.ssh
    chown user1:user1 /home/user1/.ssh
    touch /home/user1/.ssh/authorized_keys
    chmod 600 /home/user1/.ssh/authorized_keys
    chown user1:user1 /home/user1/.ssh/authorized_keys

    print_success "user1 configured successfully"

    # Prevent service auto-start during package installation (Docker safety)
    if is_docker; then
        print_info "Docker detected - preventing service auto-start during package installation..."
        prevent_service_start
    fi

    # Install PHP 8.3 and extensions
    print_step "Installing PHP 8.3..."
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

    print_success "PHP installation completed. Version: $(php -v | head -n1)"

    # Install Composer
    print_step "Installing Composer..."
    cd /tmp
    export COMPOSER_ALLOW_SUPERUSER=1
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer

    print_success "Composer installed. Version: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version)"

    # Install Apache
    print_step "Installing Apache web server..."
    apt install -y apache2

    # Enable Apache modules
    print_step "Enabling Apache modules..."
    a2enmod rewrite
    a2enmod ssl
    a2enmod headers
    a2enmod php8.3

    # Configure Apache settings
    print_step "Configuring Apache..."
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

    print_success "Apache configured"

    # Install PostgreSQL Database
    print_step "Installing PostgreSQL server..."
    apt install -y postgresql postgresql-contrib

    # Start and enable PostgreSQL
    service_start postgresql

    # Configure PostgreSQL
    print_step "Configuring PostgreSQL..."

    # Get PostgreSQL version for config paths
    PG_VERSION=$(psql --version | grep -oP '\d+\.\d+' | head -1 | cut -d. -f1)
    PG_CONFIG_DIR="/etc/postgresql/${PG_VERSION}/main"

    print_info "PostgreSQL version detected: ${PG_VERSION}"

    # Backup original configuration files
    cp ${PG_CONFIG_DIR}/pg_hba.conf ${PG_CONFIG_DIR}/pg_hba.conf.backup
    cp ${PG_CONFIG_DIR}/postgresql.conf ${PG_CONFIG_DIR}/postgresql.conf.backup

    # Configure authentication in pg_hba.conf
    print_info "Configuring PostgreSQL authentication..."
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
    print_info "Configuring PostgreSQL to listen on port 5432..."
    sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/" ${PG_CONFIG_DIR}/postgresql.conf
    sed -i "s/#port = 5432/port = 5432/" ${PG_CONFIG_DIR}/postgresql.conf

    # Restart PostgreSQL to apply configuration
    service_restart postgresql

    # Set PostgreSQL postgres user password automatically
    print_info "Setting PostgreSQL postgres user password..."

    # Temporarily allow trust authentication for postgres user to set password
    sed -i 's/local   all             postgres                                md5/local   all             postgres                                trust/' ${PG_CONFIG_DIR}/pg_hba.conf

    # Reload PostgreSQL configuration
    service_reload postgresql

    # Set the postgres user password
    su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres

    # Restore secure md5 authentication
    sed -i 's/local   all             postgres                                trust/local   all             postgres                                md5/' ${PG_CONFIG_DIR}/pg_hba.conf

    # Reload PostgreSQL configuration again
    service_reload postgresql

    print_success "PostgreSQL postgres user password set successfully"

    # Start and enable services
    print_step "Starting services..."
    service_start apache2
    service_start postgresql
    service_start php8.3-fpm

    # Install Certbot for SSL
    print_step "Installing Certbot for SSL certificates..."
    apt install -y certbot python3-certbot-apache

    # Configure PHP for production
    print_step "Configuring PHP settings..."
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

    print_success "PHP configured"

    # Skip SSH, firewall, and security hardening in Docker
    if is_docker; then
        print_info "Docker detected - skipping SSH, firewall, and security hardening"
    else
        # Configure SSH security
        print_step "Configuring SSH security..."
        cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup

        sed -i 's/#PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
        sed -i 's/PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
        sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config
        sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config
        sed -i 's/#PermitEmptyPasswords no/PermitEmptyPasswords no/' /etc/ssh/sshd_config
        sed -i 's/PermitEmptyPasswords yes/PermitEmptyPasswords no/' /etc/ssh/sshd_config
        sed -i 's/#MaxAuthTries 6/MaxAuthTries 3/' /etc/ssh/sshd_config
        sed -i 's/#ClientAliveInterval 0/ClientAliveInterval 300/' /etc/ssh/sshd_config
        sed -i 's/#ClientAliveCountMax 3/ClientAliveCountMax 2/' /etc/ssh/sshd_config

        service_restart ssh

        print_success "SSH security configured"

        # Configure UFW firewall
        print_step "Configuring firewall..."
        ufw --force reset
        ufw default deny incoming
        ufw default allow outgoing
        ufw allow ssh
        ufw allow http
        ufw allow https
        ufw allow 5432
        ufw --force enable

        # Configure fail2ban
        print_step "Configuring fail2ban..."
        service_start fail2ban

        cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

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

        service_restart fail2ban

        print_success "fail2ban configured"

        # Install automatic security updates
        print_step "Configuring automatic security updates..."
        apt install -y unattended-upgrades apt-listchanges

        tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
EOF

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

        print_success "Automatic security updates configured"

        # Security hardening
        print_step "Applying security hardening..."

        echo "install dccp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install sctp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install rds /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install tipc /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf

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

# Control Buffer Overflow attacks
kernel.randomize_va_space = 2
EOF

        sysctl -p /etc/sysctl.d/99-security.conf || true

        print_success "Security hardening applied"
    fi

    # Set proper permissions for web directory
    print_step "Setting up web directory permissions..."
    chown -R www-data:www-data /var/www/
    chmod -R 755 /var/www/

    # Add user1 to www-data group for web development
    usermod -aG www-data user1

    # Restart services
    print_step "Restarting services..."
    service_restart apache2
    service_restart php8.3-fpm

    # Remove policy-rc.d if we created it (Docker cleanup)
    allow_service_start

    # Display completion message
    print_header "Server Setup Complete!"

    echo -e "${GREEN}✓${NC} Ubuntu 24.04 system updated"
    echo -e "${GREEN}✓${NC} user1 configured"
    echo -e "${GREEN}✓${NC} PHP 8.3 with required extensions installed"
    echo -e "${GREEN}✓${NC} Composer installed globally"
    echo -e "${GREEN}✓${NC} Apache web server configured"
    echo -e "${GREEN}✓${NC} PostgreSQL database server configured"
    echo -e "${GREEN}✓${NC} Certbot for SSL certificates installed"
    if ! is_docker; then
        echo -e "${GREEN}✓${NC} UFW firewall configured"
        echo -e "${GREEN}✓${NC} fail2ban installed and configured"
        echo -e "${GREEN}✓${NC} SSH security configured"
        echo -e "${GREEN}✓${NC} Automatic security updates enabled"
        echo -e "${GREEN}✓${NC} System security hardening applied"
    fi
    echo ""
    print_warning "=== NEXT STEPS ==="
    print_info "1. Add your SSH public key to /home/user1/.ssh/authorized_keys"
    print_info "2. Create sites using: ./install.sh site SITENAME PASSWORD DOMAIN"
    print_info "3. PostgreSQL postgres password is: ${POSTGRES_PASSWORD}"
    echo ""
    print_success "Server is ready for site deployment!"
}

#==============================================================================
# SUBCOMMAND: site - Create a new Joinery site
#==============================================================================

do_site_create() {
    local FORCE_MODE=""
    local SITENAME=""
    local POSTGRES_PASSWORD=""
    local DOMAIN_NAME=""
    local PORT=""

    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --docker)
                FORCE_MODE="docker"
                shift
                ;;
            --bare-metal)
                FORCE_MODE="bare-metal"
                shift
                ;;
            -h|--help)
                echo "Usage: $0 site [--docker|--bare-metal] SITENAME PASSWORD [DOMAIN] [PORT]"
                echo ""
                echo "Options:"
                echo "  --docker      Force Docker mode (requires Docker installed)"
                echo "  --bare-metal  Force bare-metal mode (requires Apache/PHP/PostgreSQL)"
                echo ""
                echo "Parameters:"
                echo "  SITENAME      Site/database name (required)"
                echo "  PASSWORD      PostgreSQL password (required)"
                echo "  DOMAIN        Domain name (optional, defaults to server IP)"
                echo "  PORT          Web port (Docker only, default: 8080)"
                echo ""
                echo "Auto-detection:"
                echo "  - With PORT specified: Docker mode"
                echo "  - Without PORT: Bare-metal mode (if no Docker) or Docker mode (if Docker running)"
                exit 0
                ;;
            *)
                if [ -z "$SITENAME" ]; then
                    SITENAME="$1"
                elif [ -z "$POSTGRES_PASSWORD" ]; then
                    POSTGRES_PASSWORD="$1"
                elif [ -z "$DOMAIN_NAME" ]; then
                    DOMAIN_NAME="$1"
                elif [ -z "$PORT" ]; then
                    PORT="$1"
                fi
                shift
                ;;
        esac
    done

    # Validate required parameters
    if [ -z "$SITENAME" ]; then
        print_error "SITENAME is required"
        echo "Usage: $0 site [--docker|--bare-metal] SITENAME PASSWORD [DOMAIN] [PORT]"
        exit 1
    fi

    if [ -z "$POSTGRES_PASSWORD" ]; then
        print_error "PASSWORD is required"
        echo "Usage: $0 site [--docker|--bare-metal] SITENAME PASSWORD [DOMAIN] [PORT]"
        exit 1
    fi

    # Auto-generate password if "-" is provided
    if [ "$POSTGRES_PASSWORD" = "-" ]; then
        POSTGRES_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)
        print_info "Auto-generated secure password: $POSTGRES_PASSWORD"
        PASSWORD_WAS_GENERATED=1
    else
        PASSWORD_WAS_GENERATED=0
    fi

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    # Determine mode
    local MODE=""

    if [ "$FORCE_MODE" = "docker" ]; then
        if ! is_docker_available; then
            print_error "Docker mode requested but Docker is not installed or running"
            print_info "Run './install.sh docker' first to install Docker"
            exit 1
        fi
        MODE="docker"
    elif [ "$FORCE_MODE" = "bare-metal" ]; then
        if ! check_bare_metal_ready; then
            exit 1
        fi
        MODE="bare-metal"
    elif [ -n "$PORT" ]; then
        # PORT specified implies Docker mode
        if ! is_docker_available; then
            print_error "PORT specified but Docker is not available"
            print_info "Either remove PORT parameter for bare-metal mode, or install Docker first"
            exit 1
        fi
        MODE="docker"
    elif is_docker_available; then
        # Docker is available, use it
        MODE="docker"
        PORT="${PORT:-8080}"
    else
        # Fall back to bare-metal
        if ! check_bare_metal_ready; then
            exit 1
        fi
        MODE="bare-metal"
    fi

    print_header "Creating Joinery Site: $SITENAME"
    print_info "Mode: $MODE"

    if [ "$MODE" = "docker" ]; then
        do_site_docker "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME" "$PORT"
    else
        do_site_baremetal "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME"
    fi
}

#------------------------------------------------------------------------------
# Site creation: Docker mode
#------------------------------------------------------------------------------

do_site_docker() {
    local SITENAME="$1"
    local POSTGRES_PASSWORD="$2"
    local DOMAIN_NAME="${3:-localhost}"
    local PORT="${4:-8080}"
    local DB_PORT=$((PORT + 1000))

    # Auto-detect server IP if domain is localhost
    if [ "$DOMAIN_NAME" = "localhost" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -n "$SERVER_IP" ]; then
            DOMAIN_NAME="$SERVER_IP"
            print_info "Auto-detected server IP: $DOMAIN_NAME"
        fi
    fi

    # Port conflict detection
    print_step "Checking port availability..."

    PORT_CONFLICT=0
    SUGGESTED_PORT=""

    if is_port_in_use $PORT; then
        print_warning "Port $PORT is already in use"
        PORT_CONFLICT=1
    fi

    if is_port_in_use $DB_PORT; then
        print_warning "Database port $DB_PORT is already in use"
        PORT_CONFLICT=1
    fi

    if [ $PORT_CONFLICT -eq 1 ]; then
        list_docker_containers

        SUGGESTED_PORT=$(find_available_port 8080)

        if [ -n "$SUGGESTED_PORT" ]; then
            if [ "$ASSUME_YES" -eq 1 ]; then
                print_info "Auto-accepting suggested port $SUGGESTED_PORT (-y flag)"
                PORT=$SUGGESTED_PORT
                DB_PORT=$((PORT + 1000))
                print_success "Using port $PORT (database: $DB_PORT)"
            else
                echo ""
                echo -e "Suggested available port: ${GREEN}$SUGGESTED_PORT${NC} (database: $((SUGGESTED_PORT + 1000)))"
                echo ""
                read -p "Would you like to use port $SUGGESTED_PORT instead? [Y/n] " -n 1 -r
                echo ""

                if [[ ! $REPLY =~ ^[Nn]$ ]]; then
                    PORT=$SUGGESTED_PORT
                    DB_PORT=$((PORT + 1000))
                    print_success "Using port $PORT (database: $DB_PORT)"
                else
                    print_error "Cannot continue with port conflict. Please specify a different port."
                    exit 1
                fi
            fi
        else
            print_error "Could not find an available port in range 8080-8180"
            exit 1
        fi
    else
        print_success "Ports $PORT and $DB_PORT are available"
    fi

    # Verify archive structure
    print_step "Verifying archive structure..."

    # Determine archive root (parent of maintenance_scripts which is parent of install_tools)
    ARCHIVE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

    if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
        print_error "Cannot find public_html directory in $ARCHIVE_ROOT"
        print_error "Make sure you've extracted the joinery archive correctly"
        exit 1
    fi

    if [ ! -d "$ARCHIVE_ROOT/config" ]; then
        print_error "Cannot find config directory in $ARCHIVE_ROOT"
        exit 1
    fi

    if [ ! -f "$SCRIPT_DIR/Dockerfile.template" ]; then
        print_error "Cannot find Dockerfile.template in $SCRIPT_DIR"
        exit 1
    fi

    print_success "Archive structure verified"

    # Check for existing container
    print_step "Checking for existing container named '$SITENAME'..."

    if docker ps -a --format '{{.Names}}' | grep -q "^${SITENAME}$"; then
        print_warning "A container named '$SITENAME' already exists"

        if [ "$ASSUME_YES" -eq 1 ]; then
            print_info "Auto-removing existing container (-y flag)"
            docker stop "$SITENAME" 2>/dev/null || true
            docker rm "$SITENAME" 2>/dev/null || true
            print_success "Existing container removed"
        else
            echo ""
            read -p "Would you like to remove it and continue? [y/N] " -n 1 -r
            echo ""

            if [[ $REPLY =~ ^[Yy]$ ]]; then
                print_info "Stopping and removing existing container..."
                docker stop "$SITENAME" 2>/dev/null || true
                docker rm "$SITENAME" 2>/dev/null || true
                print_success "Existing container removed"
            else
                print_error "Cannot continue with existing container."
                exit 1
            fi
        fi
    else
        print_success "No existing container found"
    fi

    # Prepare build context
    print_step "Preparing build context..."

    BUILD_DIR=~/joinery-docker-build-${SITENAME}

    if [ -d "$BUILD_DIR" ]; then
        print_info "Cleaning up existing build directory..."
        rm -rf "$BUILD_DIR"
    fi

    mkdir -p "$BUILD_DIR/$SITENAME"

    print_info "Copying public_html..."
    cp -r "$ARCHIVE_ROOT/public_html" "$BUILD_DIR/$SITENAME/"

    print_info "Copying config..."
    cp -r "$ARCHIVE_ROOT/config" "$BUILD_DIR/$SITENAME/"

    print_info "Copying maintenance_scripts..."
    mkdir -p "$BUILD_DIR/maintenance_scripts"
    cp -r "$(dirname "$SCRIPT_DIR")"/* "$BUILD_DIR/maintenance_scripts/"

    print_info "Setting up Dockerfile..."
    cp "$SCRIPT_DIR/Dockerfile.template" "$BUILD_DIR/Dockerfile"

    cat > "$BUILD_DIR/.dockerignore" << 'EOF'
.git
*.log
*/backups/*
EOF

    print_success "Build context prepared at $BUILD_DIR"

    # Build Docker image
    print_step "Building Docker image (this may take 5-10 minutes)..."

    cd "$BUILD_DIR"

    # Build with -q flag in quiet mode to suppress build output
    if [ "$QUIET_MODE" -eq 1 ]; then
        docker build -q \
            --build-arg SITENAME="$SITENAME" \
            --build-arg POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
            --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
            -t "joinery-$SITENAME" . > /dev/null
    else
        docker build \
            --build-arg SITENAME="$SITENAME" \
            --build-arg POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
            --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
            -t "joinery-$SITENAME" .
    fi

    if [ $? -eq 0 ]; then
        print_success "Docker image built successfully"
    else
        print_error "Docker image build failed"
        exit 1
    fi

    # Run container
    print_step "Starting container..."

    if [ "$QUIET_MODE" -eq 1 ]; then
        docker run -d \
            --name "$SITENAME" \
            -p "$PORT":80 \
            -p "$DB_PORT":5432 \
            -v "${SITENAME}_postgres":/var/lib/postgresql \
            -v "${SITENAME}_uploads":/var/www/html/"${SITENAME}"/uploads \
            -v "${SITENAME}_config":/var/www/html/"${SITENAME}"/config \
            -v "${SITENAME}_backups":/var/www/html/"${SITENAME}"/backups \
            -v "${SITENAME}_static":/var/www/html/"${SITENAME}"/static_files \
            -v "${SITENAME}_logs":/var/www/html/"${SITENAME}"/logs \
            -v "${SITENAME}_cache":/var/www/html/"${SITENAME}"/cache \
            -v "${SITENAME}_sessions":/var/lib/php/sessions \
            -v "${SITENAME}_apache_logs":/var/log/apache2 \
            -v "${SITENAME}_pg_logs":/var/log/postgresql \
            "joinery-$SITENAME" > /dev/null
    else
        docker run -d \
            --name "$SITENAME" \
            -p "$PORT":80 \
            -p "$DB_PORT":5432 \
            -v "${SITENAME}_postgres":/var/lib/postgresql \
            -v "${SITENAME}_uploads":/var/www/html/"${SITENAME}"/uploads \
            -v "${SITENAME}_config":/var/www/html/"${SITENAME}"/config \
            -v "${SITENAME}_backups":/var/www/html/"${SITENAME}"/backups \
            -v "${SITENAME}_static":/var/www/html/"${SITENAME}"/static_files \
            -v "${SITENAME}_logs":/var/www/html/"${SITENAME}"/logs \
            -v "${SITENAME}_cache":/var/www/html/"${SITENAME}"/cache \
            -v "${SITENAME}_sessions":/var/lib/php/sessions \
            -v "${SITENAME}_apache_logs":/var/log/apache2 \
            -v "${SITENAME}_pg_logs":/var/log/postgresql \
            "joinery-$SITENAME"
    fi

    if [ $? -eq 0 ]; then
        print_success "Container started"
    else
        print_error "Failed to start container"
        exit 1
    fi

    # Verify installation
    print_step "Waiting for services to initialize..."

    MAX_ATTEMPTS=12
    ATTEMPT=1

    while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
        print_info "Checking site availability (attempt $ATTEMPT/$MAX_ATTEMPTS)..."

        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT/" 2>/dev/null || echo "000")

        if [ "$HTTP_CODE" = "200" ]; then
            print_success "Site is responding with HTTP 200"
            break
        elif [ "$HTTP_CODE" = "500" ]; then
            print_warning "Site returned HTTP 500 - may still be initializing..."
        else
            print_info "HTTP response: $HTTP_CODE"
        fi

        if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
            print_warning "Site not responding after $MAX_ATTEMPTS attempts"
            print_info "This may be normal - check logs with: docker logs $SITENAME"
        fi

        ATTEMPT=$((ATTEMPT + 1))
        sleep 5
    done

    # Cleanup build directory
    print_step "Cleaning up build directory..."
    if [ -d "$BUILD_DIR" ]; then
        rm -rf "$BUILD_DIR"
        print_success "Build directory removed"
    fi

    # Summary (always shown, even in quiet mode)
    if [ "$QUIET_MODE" -eq 1 ]; then
        # Minimal summary for quiet mode
        echo ""
        echo -e "${GREEN}Installation Complete!${NC}"
        echo -e "Site: ${GREEN}$SITENAME${NC} | URL: ${GREEN}http://$DOMAIN_NAME:$PORT/${NC}"
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
        fi
    else
        print_header "Installation Complete!"

        echo -e "Site Name:        ${GREEN}$SITENAME${NC}"
        echo -e "Domain:           ${GREEN}$DOMAIN_NAME${NC}"
        echo -e "Web Port:         ${GREEN}$PORT${NC}"
        echo -e "Database Port:    ${GREEN}$DB_PORT${NC}"
        echo ""
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo -e "${YELLOW}  IMPORTANT: Save this auto-generated password!${NC}"
            echo -e "${YELLOW}  Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo ""
        fi
        echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME:$PORT/${NC}"
        echo ""
        echo "Default admin login:"
        echo -e "  Email:    ${YELLOW}admin@example.com${NC}"
        echo ""
        echo "Useful commands:"
        echo -e "  View logs:      ${BLUE}docker logs $SITENAME${NC}"
        echo -e "  Shell access:   ${BLUE}docker exec -it $SITENAME bash${NC}"
        echo -e "  Stop container: ${BLUE}docker stop $SITENAME${NC}"
        echo -e "  Start container:${BLUE}docker start $SITENAME${NC}"
        echo ""

        CONTAINER_STATUS=$(docker ps --filter "name=$SITENAME" --format "{{.Status}}" 2>/dev/null)
        if [ -n "$CONTAINER_STATUS" ]; then
            echo -e "Container status: ${GREEN}$CONTAINER_STATUS${NC}"
        else
            print_warning "Container may not be running. Check logs with: docker logs $SITENAME"
        fi

        list_docker_containers

        print_success "Docker site installation complete!"
    fi
}

#------------------------------------------------------------------------------
# Site creation: Bare-metal mode
#------------------------------------------------------------------------------

do_site_baremetal() {
    local SITENAME="$1"
    local POSTGRES_PASSWORD="$2"
    local DOMAIN_NAME="${3:-localhost}"

    # Auto-detect server IP if domain is localhost
    if [ "$DOMAIN_NAME" = "localhost" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -n "$SERVER_IP" ]; then
            DOMAIN_NAME="$SERVER_IP"
            print_info "Auto-detected server IP: $DOMAIN_NAME"
        fi
    fi

    # Update default_Globalvars_site.php with the password
    print_step "Configuring database password..."

    local GLOBALVARS_DEFAULT="${SCRIPT_DIR}/default_Globalvars_site.php"
    if [ ! -f "$GLOBALVARS_DEFAULT" ]; then
        print_error "Cannot find $GLOBALVARS_DEFAULT"
        exit 1
    fi

    # Create temporary copy with password set
    cp "$GLOBALVARS_DEFAULT" "${GLOBALVARS_DEFAULT}.tmp"
    sed -i "s/\$this->settings\['dbpassword'\] = '';/\$this->settings['dbpassword'] = '${POSTGRES_PASSWORD}';/g" "${GLOBALVARS_DEFAULT}.tmp"
    mv "${GLOBALVARS_DEFAULT}.tmp" "$GLOBALVARS_DEFAULT"

    print_success "Database password configured"

    # Call new_account.sh
    print_step "Creating site via new_account.sh..."

    if [ ! -f "${SCRIPT_DIR}/new_account.sh" ]; then
        print_error "Cannot find new_account.sh in $SCRIPT_DIR"
        exit 1
    fi

    # Export PGPASSWORD for non-interactive database operations
    export PGPASSWORD="$POSTGRES_PASSWORD"

    # Change to script directory and run new_account.sh
    cd "$SCRIPT_DIR"

    if ! ./new_account.sh "$SITENAME" "$DOMAIN_NAME" "$SERVER_IP"; then
        print_error "new_account.sh failed"
        exit 1
    fi

    # Verify site is responding
    print_step "Verifying site installation..."

    sleep 2

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/" 2>/dev/null || echo "000")

    if [ "$HTTP_CODE" = "200" ]; then
        print_success "Site is responding with HTTP 200"
    else
        print_warning "Site returned HTTP $HTTP_CODE - may need manual verification"
    fi

    # Summary (always shown, even in quiet mode)
    if [ "$QUIET_MODE" -eq 1 ]; then
        # Minimal summary for quiet mode
        echo ""
        echo -e "${GREEN}Installation Complete!${NC}"
        echo -e "Site: ${GREEN}$SITENAME${NC} | URL: ${GREEN}http://$DOMAIN_NAME/${NC}"
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
        fi
    else
        print_header "Installation Complete!"

        echo -e "Site Name:        ${GREEN}$SITENAME${NC}"
        echo -e "Domain:           ${GREEN}$DOMAIN_NAME${NC}"
        echo -e "Location:         ${GREEN}/var/www/html/$SITENAME/${NC}"
        echo ""
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo -e "${YELLOW}  IMPORTANT: Save this auto-generated password!${NC}"
            echo -e "${YELLOW}  Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo ""
        fi
        echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME/${NC}"
        echo ""
        echo "Default admin login:"
        echo -e "  Email:    ${YELLOW}admin@example.com${NC}"
        echo ""
        echo "Useful commands:"
        echo -e "  View logs:      ${BLUE}tail -f /var/www/html/$SITENAME/logs/error.log${NC}"
        echo -e "  Restart Apache: ${BLUE}sudo systemctl restart apache2${NC}"
        echo ""

        list_baremetal_sites

        print_success "Bare-metal site installation complete!"
    fi
}

#==============================================================================
# SUBCOMMAND: list - List existing Joinery sites
#==============================================================================

do_list() {
    print_header "Existing Joinery Sites"

    # Check for Docker sites
    if is_docker_available; then
        list_docker_containers
    else
        print_info "Docker not available - skipping Docker container list"
        echo ""
    fi

    # Check for bare-metal sites
    list_baremetal_sites
}

#==============================================================================
# HELP OUTPUT
#==============================================================================

show_help() {
    echo ""
    echo "Joinery Installation Script"
    echo ""
    echo "Usage:"
    echo "  ./install.sh [global-options] <command> [options]"
    echo ""
    echo "Global Options:"
    echo "  -y, --yes     Auto-accept all prompts (non-interactive mode)"
    echo "  -q, --quiet   Suppress most output, show only errors and final status"
    echo ""
    echo "Commands:"
    echo "  docker    Install Docker (one-time, for Docker deployments)"
    echo "  server    Set up base server (one-time, for bare-metal deployments)"
    echo "  site      Create a new Joinery site"
    echo "  list      List existing Joinery sites"
    echo ""
    echo "Examples:"
    echo "  # Docker deployment"
    echo "  sudo ./install.sh docker"
    echo "  sudo ./install.sh site mysite SecurePass123! mysite.com 8080"
    echo ""
    echo "  # Bare-metal deployment"
    echo "  sudo ./install.sh server"
    echo "  sudo ./install.sh site mysite SecurePass123! mysite.com"
    echo ""
    echo "  # Multi-site Docker deployment"
    echo "  sudo ./install.sh docker"
    echo "  sudo ./install.sh site site1 Pass1! site1.com 8080"
    echo "  sudo ./install.sh site site2 Pass2! site2.com 8081"
    echo "  sudo ./install.sh list"
    echo ""
    echo "  # Multi-site bare-metal deployment"
    echo "  sudo ./install.sh server"
    echo "  sudo ./install.sh site site1 Pass1! site1.com"
    echo "  sudo ./install.sh site site2 Pass2! site2.com"
    echo "  sudo ./install.sh list"
    echo ""
    echo "  # Non-interactive deployment (for scripting/CI)"
    echo "  sudo ./install.sh -y docker"
    echo "  sudo ./install.sh -y -q site mysite SecurePass123! mysite.com 8080"
    echo ""
    echo "Auto-Detection:"
    echo "  'install.sh site' automatically detects the environment:"
    echo "  - Docker installed and running → creates Docker container"
    echo "  - No Docker → creates bare-metal site via new_account.sh"
    echo "  - PORT parameter specified → forces Docker mode"
    echo "  - Use --docker or --bare-metal flags to override"
    echo ""
    echo "Run './install.sh <command> --help' for command-specific help."
    echo ""
}

#==============================================================================
# MAIN DISPATCHER
#==============================================================================

# Parse global flags first (before command)
while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes)
            ASSUME_YES=1
            shift
            ;;
        -q|--quiet)
            QUIET_MODE=1
            shift
            ;;
        *)
            break
            ;;
    esac
done

case "${1:-}" in
    docker)
        shift
        do_docker_install "$@"
        ;;
    server)
        shift
        do_server_setup "$@"
        ;;
    site)
        shift
        do_site_create "$@"
        ;;
    list)
        shift
        do_list "$@"
        ;;
    --help|-h|"")
        show_help
        ;;
    *)
        print_error "Unknown command: $1"
        show_help
        exit 1
        ;;
esac
