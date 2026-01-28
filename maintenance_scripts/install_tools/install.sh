#!/usr/bin/env bash
#VERSION 2.8 - Skip theme download when cloning
#
# Usage:
#   ./install.sh docker                              # One-time: install Docker
#   ./install.sh server                              # One-time: set up bare-metal server
#   ./install.sh site SITENAME [DOMAIN] [PORT]      # Create a site (auto-generates password)
#   ./install.sh list                                # List existing sites
#
# Global Options:
#   -y, --yes     Auto-accept all prompts (non-interactive mode)
#   -q, --quiet   Suppress most output, show only errors and final status
#
# Site Options:
#   --password-file=FILE   Read database password from file (recommended for special chars)
#   --activate THEME       Set active theme after installation
#   --with-test-site       Create companion test site (bare-metal only)
#   --no-ssl               Skip automatic SSL certificate setup
#   --themes               Download themes/plugins from distribution server
#   --upgrade-server=URL   Override default distribution server
#   --clone-from=URL       Clone database and uploads from existing site
#   --clone-key=KEY        Authentication key for clone source
#
# Password Handling:
#   If no password is provided, a secure 24-character password is auto-generated.
#   For passwords with special characters (like !), use --password-file to avoid
#   shell escaping issues.
#
# SSL Behavior:
#   SSL is automatically configured when a domain name is provided (not localhost/IP).
#   DNS must point to this server for SSL setup to succeed.
#   Use --no-ssl to skip SSL setup.
#
# Examples:
#   # Auto-generate secure password (recommended)
#   sudo ./install.sh site mysite mysite.com 8080
#
#   # Use password from file (for special characters)
#   echo 'MyP@ss!word' > /tmp/dbpass.txt
#   sudo ./install.sh site mysite --password-file=/tmp/dbpass.txt mysite.com 8080
#   rm /tmp/dbpass.txt
#
#   # Skip SSL setup
#   sudo ./install.sh site mysite mysite.com --no-ssl
#
# See INSTALL_README.md for complete documentation.

set -e  # Exit on error
set +H  # Disable history expansion (prevents ! in passwords from being interpreted)

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

#==============================================================================
# GLOBAL FLAGS (parsed before command dispatch)
#==============================================================================

ASSUME_YES=0      # -y/--yes: Auto-accept all prompts
QUIET_MODE=0      # -q/--quiet: Suppress most output
CLOUDFLARE_PROXY=0  # Set to 1 if domain is behind Cloudflare proxy

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
# SSL SETUP FUNCTIONS
#==============================================================================

# Check if domain should have SSL configured
should_setup_ssl() {
    local domain="$1"
    local no_ssl="$2"

    # Skip if --no-ssl flag was passed
    if [ "$no_ssl" = true ]; then
        return 1
    fi

    # Skip for localhost
    if [ "$domain" = "localhost" ]; then
        return 1
    fi

    # Skip for IP addresses
    if [[ "$domain" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        return 1
    fi

    return 0
}

# Check if an IP address belongs to Cloudflare
# Returns 0 if IP is Cloudflare, 1 otherwise
is_cloudflare_ip() {
    local ip="$1"

    # Try to fetch current Cloudflare IP ranges
    local cf_ranges=$(curl -s --max-time 5 https://www.cloudflare.com/ips-v4 2>/dev/null)

    # Fallback to known ranges if fetch fails (updated Jan 2025)
    if [ -z "$cf_ranges" ]; then
        cf_ranges="173.245.48.0/20
103.21.244.0/22
103.22.200.0/22
103.31.4.0/22
141.101.64.0/18
108.162.192.0/18
190.93.240.0/20
188.114.96.0/20
197.234.240.0/22
198.41.128.0/17
162.158.0.0/15
104.16.0.0/13
104.24.0.0/14
172.64.0.0/13
131.0.72.0/22"
    fi

    # Use Python to check CIDR membership (Python3 is standard on Ubuntu)
    python3 -c "
import ipaddress
import sys

ip = ipaddress.ip_address('$ip')
ranges = '''$cf_ranges'''.strip().split('\n')

for cidr in ranges:
    cidr = cidr.strip()
    if cidr and ip in ipaddress.ip_network(cidr):
        sys.exit(0)
sys.exit(1)
" 2>/dev/null
}

# Check if DNS for domain points to this server
# Returns: 0 = points here, 1 = doesn't point here, 2 = Cloudflare proxy detected
check_dns_points_here() {
    local domain="$1"

    # Get this server's public IP
    local server_ip=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
    if [ -z "$server_ip" ]; then
        print_warning "Could not determine server's public IP"
        return 1
    fi

    # Get DNS resolution for domain
    local dns_ip=$(dig +short "$domain" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
    if [ -z "$dns_ip" ]; then
        print_warning "DNS lookup failed for $domain"
        return 1
    fi

    # Compare - direct match
    if [ "$dns_ip" = "$server_ip" ]; then
        return 0
    fi

    # Check if it's Cloudflare proxy
    if is_cloudflare_ip "$dns_ip"; then
        print_info "DNS for $domain points to Cloudflare proxy ($dns_ip)"
        CLOUDFLARE_PROXY=1
        return 2  # Special return code for Cloudflare
    fi

    # Neither direct nor Cloudflare
    print_warning "DNS for $domain points to $dns_ip, but this server is $server_ip"
    return 1
}

# Set up SSL for bare-metal site
setup_ssl_baremetal() {
    local domain="$1"

    # Skip certbot if behind Cloudflare proxy (Cloudflare handles SSL)
    if [ "$CLOUDFLARE_PROXY" -eq 1 ]; then
        print_info "Skipping Let's Encrypt - Cloudflare provides edge SSL"
        print_info "Configure Cloudflare SSL/TLS settings for origin encryption if needed"
        return 0
    fi

    print_step "Setting up SSL certificate for $domain..."

    # Check DNS first
    if ! check_dns_points_here "$domain"; then
        print_warning "Skipping SSL - DNS not configured"
        print_info "Run 'sudo certbot --apache -d $domain' once DNS is pointing to this server"
        return 1
    fi

    # Run certbot
    if certbot --apache -d "$domain" --non-interactive --agree-tos --register-unsafely-without-email; then
        print_success "SSL certificate installed for $domain"
        return 0
    else
        print_warning "SSL setup failed - site will work over HTTP"
        print_info "Run 'sudo certbot --apache -d $domain' to retry"
        return 1
    fi
}

# Set up reverse proxy with SSL for Docker site
setup_ssl_docker_proxy() {
    local sitename="$1"
    local domain="$2"
    local port="$3"

    print_step "Setting up reverse proxy for $domain..."

    # Check if Apache is installed on host
    if ! command -v apache2 &> /dev/null; then
        print_info "Installing Apache for reverse proxy..."
        apt-get update -qq
        apt-get install -y -qq apache2
    fi

    # Enable required modules
    a2enmod proxy proxy_http ssl headers rewrite > /dev/null 2>&1 || true

    # Handle Cloudflare proxy - create HTTP proxy only (Cloudflare handles SSL)
    if [ "$CLOUDFLARE_PROXY" -eq 1 ]; then
        print_info "Creating HTTP proxy for Cloudflare origin connection..."

        # Create HTTP proxy config for Cloudflare
        cat > "/etc/apache2/sites-available/${sitename}-proxy.conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
EOF

        a2ensite "${sitename}-proxy.conf" > /dev/null
        systemctl reload apache2

        print_success "HTTP proxy configured for Cloudflare origin"
        print_info "Cloudflare handles SSL at the edge"
        return 0
    fi

    # Check if certbot is installed on host (only needed for non-Cloudflare)
    if ! command -v certbot &> /dev/null; then
        print_info "Installing Certbot for SSL certificates..."
        apt-get install -y -qq certbot python3-certbot-apache
    fi

    # Check DNS first
    if ! check_dns_points_here "$domain"; then
        print_warning "Skipping SSL - DNS not configured"
        print_info "Creating HTTP-only proxy for now"

        # Create HTTP-only proxy config
        cat > "/etc/apache2/sites-available/${sitename}-proxy.conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
EOF

        a2ensite "${sitename}-proxy.conf" > /dev/null
        systemctl reload apache2

        print_info "Run 'sudo certbot --apache -d $domain' once DNS is pointing to this server"
        return 1
    fi

    # Create proxy config (certbot will add SSL)
    cat > "/etc/apache2/sites-available/${sitename}-proxy.conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
EOF

    a2ensite "${sitename}-proxy.conf" > /dev/null
    systemctl reload apache2

    # Run certbot to add SSL
    if certbot --apache -d "$domain" --non-interactive --agree-tos --register-unsafely-without-email; then
        print_success "Reverse proxy with SSL configured for $domain"
        return 0
    else
        print_warning "SSL setup failed - proxy will work over HTTP only"
        print_info "Run 'sudo certbot --apache -d $domain' to retry"
        return 1
    fi
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
# CODE DEPLOYMENT (for bare-metal installs)
#==============================================================================

# Deploy application code from archive to site directory
deploy_application_code() {
    local site_name="$1"
    local archive_root="$2"
    local site_root="/var/www/html/$site_name"

    print_step "Deploying application code..."

    # Create site directory
    mkdir -p "$site_root"

    # Copy public_html (excluding runtime directories)
    if [ -d "$archive_root/public_html" ]; then
        print_info "Copying public_html..."
        rsync -av --exclude='.git' \
                  --exclude='uploads' \
                  --exclude='cache' \
                  --exclude='logs' \
                  --exclude='.playwright-mcp' \
                  "$archive_root/public_html/" \
                  "$site_root/public_html/" > /dev/null
    else
        print_error "public_html directory not found in archive"
        return 1
    fi

    # Copy maintenance_scripts
    if [ -d "$archive_root/maintenance_scripts" ]; then
        print_info "Copying maintenance_scripts..."
        rsync -av "$archive_root/maintenance_scripts/" \
                  "$site_root/maintenance_scripts/" > /dev/null
    fi

    # Copy config templates if they exist
    if [ -d "$archive_root/config" ]; then
        print_info "Copying config templates..."
        mkdir -p "$site_root/config"
    fi

    print_success "Application code deployed to $site_root"
}

#==============================================================================
# THEME/PLUGIN DOWNLOAD FUNCTIONS
#==============================================================================

# Default upgrade server (can be overridden via --upgrade-server)
UPGRADE_SERVER="${UPGRADE_SERVER:-https://joinerytest.site}"

# Download themes and plugins from distribution server
# Usage: download_themes_and_plugins TARGET_DIR [THEMES_LIST]
#   If THEMES_LIST is empty, downloads all system themes/plugins
download_themes_and_plugins() {
    local target_dir="$1"
    local themes_list="$2"

    print_step "Downloading themes and plugins from $UPGRADE_SERVER..."

    # Ensure target directories exist
    mkdir -p "$target_dir/theme"
    mkdir -p "$target_dir/plugins"

    if [[ -n "$themes_list" ]]; then
        # Download specific themes (comma-separated)
        IFS=',' read -ra THEME_ARRAY <<< "$themes_list"
        for theme in "${THEME_ARRAY[@]}"; do
            theme=$(echo "$theme" | xargs)  # Trim whitespace
            download_single_item "$theme" "$target_dir"
        done
    else
        # No themes specified - download all system themes and plugins
        print_info "No --themes specified, downloading system themes and plugins..."

        # Get system themes from server
        local themes_json=$(curl -sf "${UPGRADE_SERVER}/utils/publish_theme?list=themes" 2>/dev/null)
        if [[ -n "$themes_json" ]]; then
            # Parse JSON and download each system theme
            local system_themes=$(echo "$themes_json" | grep -oP '"name"\s*:\s*"\K[^"]+' | while read theme_name; do
                # Check if this theme is a system theme
                if echo "$themes_json" | grep -A5 "\"name\".*\"$theme_name\"" | grep -q '"is_system"\s*:\s*true'; then
                    echo "$theme_name"
                fi
            done)

            for theme in $system_themes; do
                download_single_item "$theme" "$target_dir" "theme"
            done
        else
            print_warning "Could not fetch theme list from server"
        fi

        # Get system plugins from server
        local plugins_json=$(curl -sf "${UPGRADE_SERVER}/utils/publish_theme?list=plugins" 2>/dev/null)
        if [[ -n "$plugins_json" ]]; then
            # Parse JSON and download each system plugin
            local system_plugins=$(echo "$plugins_json" | grep -oP '"name"\s*:\s*"\K[^"]+' | while read plugin_name; do
                # Check if this plugin is a system plugin
                if echo "$plugins_json" | grep -A5 "\"name\".*\"$plugin_name\"" | grep -q '"is_system"\s*:\s*true'; then
                    echo "$plugin_name"
                fi
            done)

            for plugin in $system_plugins; do
                download_single_item "$plugin" "$target_dir" "plugin"
            done
        else
            print_warning "Could not fetch plugin list from server"
        fi
    fi

    print_success "Theme and plugin download complete"
}

# Download a single theme or plugin
# Usage: download_single_item NAME TARGET_DIR [TYPE]
#   TYPE is "theme" or "plugin" (defaults to trying both)
download_single_item() {
    local item_name="$1"
    local target_dir="$2"
    local item_type="${3:-auto}"

    if [[ "$item_type" == "theme" ]]; then
        print_info "Downloading theme: $item_name"
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}" | tar xz -C "$target_dir/theme" 2>/dev/null; then
            if [[ -d "$target_dir/theme/$item_name" ]]; then
                print_success "Downloaded theme: $item_name"
                return 0
            fi
        fi
        print_error "Failed to download theme: $item_name"
        return 1
    elif [[ "$item_type" == "plugin" ]]; then
        print_info "Downloading plugin: $item_name"
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}&type=plugin" | tar xz -C "$target_dir/plugins" 2>/dev/null; then
            if [[ -d "$target_dir/plugins/$item_name" ]]; then
                print_success "Downloaded plugin: $item_name"
                return 0
            fi
        fi
        print_error "Failed to download plugin: $item_name"
        return 1
    else
        # Auto-detect: try theme first, then plugin
        print_info "Downloading: $item_name"

        # Try as theme first
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}" | tar xz -C "$target_dir/theme" 2>/dev/null; then
            if [[ -d "$target_dir/theme/$item_name" ]]; then
                print_success "Downloaded theme: $item_name"
                return 0
            fi
        fi

        # Try as plugin
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}&type=plugin" | tar xz -C "$target_dir/plugins" 2>/dev/null; then
            if [[ -d "$target_dir/plugins/$item_name" ]]; then
                print_success "Downloaded plugin: $item_name"
                return 0
            fi
        fi

        print_error "Failed to download: $item_name (not found as theme or plugin)"
        return 1
    fi
}

# Create a test site (copy from main site)
create_test_site() {
    local main_site="$1"
    local password="$2"
    local domain="$3"

    local test_site="${main_site}_test"
    local test_domain="test.${domain}"

    print_step "Creating test site: $test_site"

    # Deploy code (copy from main site to save time)
    local site_root="/var/www/html/$test_site"
    mkdir -p "$site_root"

    rsync -av --exclude='uploads/*' \
              --exclude='cache/*' \
              --exclude='logs/*' \
              "/var/www/html/$main_site/public_html/" \
              "$site_root/public_html/" > /dev/null

    if [ -d "/var/www/html/$main_site/maintenance_scripts" ]; then
        rsync -av "/var/www/html/$main_site/maintenance_scripts/" \
                  "$site_root/maintenance_scripts/" > /dev/null
    fi

    # Run initialization (creates separate database)
    "$SCRIPT_DIR/_site_init.sh" "$test_site" "$password" "$test_domain"

    print_success "Test site created: $test_site"
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
    local PASSWORD_FILE=""
    local DOMAIN_NAME=""
    local PORT=""
    local ACTIVATE_THEME=""
    local WITH_TEST_SITE=false
    local NO_SSL=false
    local THEMES=""
    local CLONE_FROM=""
    local CLONE_KEY=""

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
            -y|--yes)
                ASSUME_YES=1
                shift
                ;;
            --activate)
                ACTIVATE_THEME="$2"
                shift 2
                ;;
            --with-test-site)
                WITH_TEST_SITE=true
                shift
                ;;
            --no-ssl)
                NO_SSL=true
                shift
                ;;
            --password-file=*)
                PASSWORD_FILE="${1#*=}"
                shift
                ;;
            --password-file)
                PASSWORD_FILE="$2"
                shift 2
                ;;
            --themes=*)
                THEMES="${1#*=}"
                shift
                ;;
            --themes)
                # --themes alone means "download all" (default), --themes VALUE means specific list
                if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                    THEMES="$2"
                    shift 2
                else
                    # No value provided - keep THEMES empty to trigger "download all" behavior
                    THEMES=""
                    shift
                fi
                ;;
            --upgrade-server=*)
                UPGRADE_SERVER="${1#*=}"
                shift
                ;;
            --upgrade-server)
                UPGRADE_SERVER="$2"
                shift 2
                ;;
            --clone-from=*)
                CLONE_FROM="${1#*=}"
                shift
                ;;
            --clone-from)
                CLONE_FROM="$2"
                shift 2
                ;;
            --clone-key=*)
                CLONE_KEY="${1#*=}"
                shift
                ;;
            --clone-key)
                CLONE_KEY="$2"
                shift 2
                ;;
            -h|--help)
                echo "Usage: $0 site [OPTIONS] SITENAME PASSWORD [DOMAIN] [PORT]"
                echo ""
                echo "Mode Options:"
                echo "  --docker      Force Docker mode (requires Docker installed)"
                echo "  --bare-metal  Force bare-metal mode (requires Apache/PHP/PostgreSQL)"
                echo ""
                echo "Site Options:"
                echo "  --activate THEME       Set active theme after installation"
                echo "  --with-test-site       Create companion test site (bare-metal only)"
                echo "  --no-ssl               Skip automatic SSL certificate setup"
                echo ""
                echo "Clone Options:"
                echo "  --clone-from=URL       Clone database and uploads from existing site"
                echo "  --clone-key=KEY        Authentication key for clone source"
                echo ""
                echo "Parameters:"
                echo "  SITENAME      Site/database name (required)"
                echo "  PASSWORD      PostgreSQL password (required, use '-' to auto-generate)"
                echo "  DOMAIN        Domain name (optional, defaults to server IP)"
                echo "  PORT          Web port (Docker only, default: 8080)"
                echo ""
                echo "SSL:"
                echo "  SSL is automatically configured when a domain is provided."
                echo "  DNS must point to this server. Use --no-ssl to skip."
                echo ""
                echo "Cloning:"
                echo "  Clone an existing site's database and uploads to create a new site."
                echo "  The source site must have clone_export_key configured in stg_settings."
                echo ""
                echo "Auto-detection:"
                echo "  - With PORT specified: Docker mode"
                echo "  - Without PORT: Bare-metal mode"
                exit 0
                ;;
            *)
                if [ -z "$SITENAME" ]; then
                    SITENAME="$1"
                elif [ -z "$POSTGRES_PASSWORD" ] && [ -z "$PASSWORD_FILE" ]; then
                    # Check if this looks like a domain or port (skip password)
                    # Domain: contains a dot (example.com, localhost.local)
                    # Port: all digits
                    if [[ "$1" =~ \. ]] || [[ "$1" =~ ^[0-9]+$ ]]; then
                        # Looks like domain or port - skip password, go to domain
                        DOMAIN_NAME="$1"
                    else
                        POSTGRES_PASSWORD="$1"
                    fi
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

    # Handle password: --password-file takes priority, then command line, then auto-generate
    PASSWORD_WAS_GENERATED=0

    if [ -n "$PASSWORD_FILE" ]; then
        # Read password from file
        if [ ! -f "$PASSWORD_FILE" ]; then
            print_error "Password file not found: $PASSWORD_FILE"
            exit 1
        fi
        POSTGRES_PASSWORD=$(cat "$PASSWORD_FILE" | tr -d '\n')
        if [ -z "$POSTGRES_PASSWORD" ]; then
            print_error "Password file is empty: $PASSWORD_FILE"
            exit 1
        fi
        print_info "Password read from file: $PASSWORD_FILE"
    elif [ -z "$POSTGRES_PASSWORD" ] || [ "$POSTGRES_PASSWORD" = "-" ]; then
        # Auto-generate secure password if none provided or "-" specified
        POSTGRES_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)
        print_info "Auto-generated secure password: $POSTGRES_PASSWORD"
        print_warning "Save this password - you will need it for database access!"
        PASSWORD_WAS_GENERATED=1
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
    if [ -n "$ACTIVATE_THEME" ]; then
        print_info "Theme: $ACTIVATE_THEME"
    fi
    if [ "$WITH_TEST_SITE" = true ]; then
        print_info "Test site: enabled"
    fi
    if [ "$NO_SSL" = true ]; then
        print_info "SSL: disabled"
    fi

    # Early DNS validation - fail before doing any work if SSL is expected but DNS isn't ready
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        print_step "Validating DNS configuration for SSL..."
        # Capture return code without set -e killing the script
        local dns_result=0
        check_dns_points_here "$DOMAIN_NAME" || dns_result=$?

        if [ $dns_result -eq 0 ]; then
            # Direct DNS match - proceed with Let's Encrypt
            print_success "DNS validated - $DOMAIN_NAME points to this server"
        elif [ $dns_result -eq 2 ]; then
            # Cloudflare proxy detected - proceed without Let's Encrypt
            print_success "Cloudflare proxy detected - SSL handled by Cloudflare"
            echo ""
            print_info "Cloudflare provides SSL at the edge. For origin encryption, configure:"
            echo "  - Cloudflare SSL/TLS → Full (Strict) with Origin Certificate, or"
            echo "  - Cloudflare SSL/TLS → Full (works with self-signed or no origin cert)"
            echo ""
        else
            # DNS doesn't point here and it's not Cloudflare
            echo ""
            print_error "DNS for $DOMAIN_NAME does not point to this server"
            echo ""
            echo "SSL requires DNS to be configured correctly before installation."
            echo ""
            echo "Options:"
            echo "  1. Update DNS to point $DOMAIN_NAME to this server's IP, then retry"
            echo "  2. Use --no-ssl flag to skip SSL setup and configure it later:"
            echo "     ./install.sh site $SITENAME PASSWORD $DOMAIN_NAME${PORT:+ $PORT} --no-ssl"
            echo ""
            exit 1
        fi
    fi

    # Clone source verification
    if [ -n "$CLONE_FROM" ]; then
        print_step "Verifying clone source..."

        if [ -z "$CLONE_KEY" ]; then
            print_error "--clone-key is required when using --clone-from"
            exit 1
        fi

        MANIFEST=$(curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_FROM}/utils/clone_export?action=manifest" 2>/dev/null)

        if [ $? -ne 0 ] || [ -z "$MANIFEST" ]; then
            print_error "Cannot connect to clone source or invalid key"
            print_info "Verify the URL and clone key are correct"
            exit 1
        fi

        # Check for error response
        if echo "$MANIFEST" | grep -q '"status".*"error"'; then
            ERROR_MSG=$(echo "$MANIFEST" | grep -oP '"message"\s*:\s*"\K[^"]+')
            print_error "Clone source error: $ERROR_MSG"
            exit 1
        fi

        # Display clone info (using grep to avoid jq dependency)
        print_info "Clone source: $CLONE_FROM"
        print_info "Database size: $(echo "$MANIFEST" | grep -oP '"database_size_mb"\s*:\s*\K[0-9]+') MB"
        print_info "Uploads size: $(echo "$MANIFEST" | grep -oP '"uploads_size_mb"\s*:\s*\K[0-9]+') MB"
        print_info "Themes: $(echo "$MANIFEST" | grep -oP '"themes"\s*:\s*\[\K[^\]]+' | tr -d '"')"

        if [ "$ASSUME_YES" -eq 0 ]; then
            echo ""
            read -p "Proceed with clone? [y/N] " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                print_info "Clone cancelled"
                exit 0
            fi
        fi

        # Use clone source as upgrade server for themes/plugins
        UPGRADE_SERVER="$CLONE_FROM"
    fi

    if [ "$MODE" = "docker" ]; then
        do_site_docker "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME" "$PORT" "$ACTIVATE_THEME" "$NO_SSL" "$CLONE_FROM" "$CLONE_KEY"
    else
        do_site_baremetal "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME" "$ACTIVATE_THEME" "$WITH_TEST_SITE" "$NO_SSL" "$CLONE_FROM" "$CLONE_KEY"
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
    local ACTIVATE_THEME="${5:-}"
    local NO_SSL="${6:-false}"
    local CLONE_FROM="${7:-}"
    local CLONE_KEY="${8:-}"
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

    # Download themes and plugins to archive before copying (skip when cloning)
    if [ -z "$CLONE_FROM" ]; then
        if [ -n "$THEMES" ] || [ -n "$UPGRADE_SERVER" ]; then
            download_themes_and_plugins "$ARCHIVE_ROOT/public_html" "$THEMES"
        fi
    else
        print_info "Skipping theme download (will be cloned from source)"
    fi

    print_info "Copying public_html..."
    cp -r "$ARCHIVE_ROOT/public_html" "$BUILD_DIR/$SITENAME/"

    print_info "Setting up config directory..."
    mkdir -p "$BUILD_DIR/$SITENAME/config"
    if [ -n "$CLONE_FROM" ]; then
        # When cloning, don't copy Globalvars_site.php - _site_init.sh will create it
        # This ensures first-run initialization happens with the clone
        print_info "Skipping config copy (will be configured during clone)"
    else
        # Normal install: copy the archive config
        cp -r "$ARCHIVE_ROOT/config"/* "$BUILD_DIR/$SITENAME/config/"
    fi

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
    # Note: Clone options are passed at runtime, not build time (security)
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

    # Build clone environment options (passed at runtime, not baked into image)
    CLONE_ENV_OPTS=""
    if [ -n "$CLONE_FROM" ] && [ -n "$CLONE_KEY" ]; then
        CLONE_ENV_OPTS="-e CLONE_FROM=${CLONE_FROM} -e CLONE_KEY=${CLONE_KEY}"
    fi

    if [ "$QUIET_MODE" -eq 1 ]; then
        docker run -d \
            --name "$SITENAME" \
            -p "$PORT":80 \
            -p "$DB_PORT":5432 \
            $CLONE_ENV_OPTS \
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
            $CLONE_ENV_OPTS \
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

    # Create host-side logs directory for reverse proxy (used by manage_domain.sh)
    # Container has its own /var/www/html/{site}/ but host needs logs dir for proxy
    mkdir -p "/var/www/html/${SITENAME}/logs"

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

    # Set up SSL with reverse proxy if domain provided
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        setup_ssl_docker_proxy "$SITENAME" "$DOMAIN_NAME" "$PORT"
    fi
}

#------------------------------------------------------------------------------
# Site creation: Bare-metal mode
#------------------------------------------------------------------------------

do_site_baremetal() {
    local SITENAME="$1"
    local POSTGRES_PASSWORD="$2"
    local DOMAIN_NAME="${3:-localhost}"
    local ACTIVATE_THEME="${4:-}"
    local WITH_TEST_SITE="${5:-false}"
    local NO_SSL="${6:-false}"
    local CLONE_FROM="${7:-}"
    local CLONE_KEY="${8:-}"

    # Auto-detect server IP if domain is localhost
    if [ "$DOMAIN_NAME" = "localhost" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -n "$SERVER_IP" ]; then
            DOMAIN_NAME="$SERVER_IP"
            print_info "Auto-detected server IP: $DOMAIN_NAME"
        fi
    fi

    # Check if site already exists
    if [ -d "/var/www/html/$SITENAME" ] && [ -f "/var/www/html/$SITENAME/config/Globalvars_site.php" ]; then
        if [ "$ASSUME_YES" -eq 1 ]; then
            print_warning "Site $SITENAME already exists. Overwriting..."
        else
            echo ""
            read -p "Site $SITENAME already exists. Overwrite? [y/N] " -n 1 -r
            echo ""
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                print_error "Aborted."
                exit 1
            fi
        fi
    fi

    # Verify archive structure and locate source files
    print_step "Locating source files..."

    # Determine archive root (parent of maintenance_scripts which is parent of install_tools)
    ARCHIVE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

    if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
        print_error "Cannot find public_html directory in $ARCHIVE_ROOT"
        print_error "Make sure you've extracted the joinery archive correctly"
        exit 1
    fi

    print_success "Source files located at $ARCHIVE_ROOT"

    # Download themes and plugins to archive before deployment
    download_themes_and_plugins "$ARCHIVE_ROOT/public_html" "$THEMES"

    # Deploy application code
    deploy_application_code "$SITENAME" "$ARCHIVE_ROOT"

    # Verify _site_init.sh exists
    if [ ! -f "${SCRIPT_DIR}/_site_init.sh" ]; then
        print_error "Cannot find _site_init.sh in $SCRIPT_DIR"
        exit 1
    fi

    # Build _site_init.sh arguments
    local INIT_ARGS="$SITENAME $POSTGRES_PASSWORD $DOMAIN_NAME"
    if [ -n "$ACTIVATE_THEME" ]; then
        INIT_ARGS="$INIT_ARGS --activate $ACTIVATE_THEME"
    fi
    if [ "$QUIET_MODE" -eq 1 ]; then
        INIT_ARGS="$INIT_ARGS -q"
    fi
    if [ -n "$CLONE_FROM" ] && [ -n "$CLONE_KEY" ]; then
        INIT_ARGS="$INIT_ARGS --clone-from=${CLONE_FROM} --clone-key=${CLONE_KEY}"
    fi

    # Call _site_init.sh for shared setup
    print_step "Initializing site via _site_init.sh..."

    # Export PGPASSWORD for non-interactive database operations
    export PGPASSWORD="$POSTGRES_PASSWORD"

    # Run the initialization script
    if ! "$SCRIPT_DIR/_site_init.sh" $INIT_ARGS; then
        print_error "_site_init.sh failed"
        exit 1
    fi

    # Create test site if requested
    if [ "$WITH_TEST_SITE" = true ]; then
        create_test_site "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME"
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
        if [ -n "$ACTIVATE_THEME" ]; then
            echo -e "Theme:            ${GREEN}$ACTIVATE_THEME${NC}"
        fi
        if [ "$WITH_TEST_SITE" = true ]; then
            echo -e "Test Site:        ${GREEN}/var/www/html/${SITENAME}_test/${NC}"
        fi
        echo ""
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo -e "${YELLOW}  IMPORTANT: Save this auto-generated password!${NC}"
            echo -e "${YELLOW}  Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo ""
        fi
        echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME/${NC}"
        if [ "$WITH_TEST_SITE" = true ]; then
            echo -e "Test site:        ${GREEN}http://test.$DOMAIN_NAME/${NC}"
        fi
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

    # Set up SSL if domain provided
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        setup_ssl_baremetal "$DOMAIN_NAME"
    fi
}

#==============================================================================
# SUBCOMMAND: list - List existing Joinery sites
#==============================================================================

do_list() {
    print_header "Existing Joinery Sites"

    # Check for Docker sites
    if command -v docker &> /dev/null; then
        echo -e "${BLUE}Docker containers:${NC}"

        local found=false
        while IFS= read -r line; do
            if [ -n "$line" ]; then
                local name=$(echo "$line" | awk '{print $1}')
                local status=$(echo "$line" | awk '{$1=""; print $0}' | xargs)
                local ports=$(docker port "$name" 2>/dev/null | head -1 | sed 's/.*://')

                # Only show joinery containers
                if [[ "$name" == joinery-* ]] || docker inspect "$name" --format '{{.Config.Image}}' 2>/dev/null | grep -q "^joinery-"; then
                    echo "  $name	$status	Port: ${ports:-N/A}"
                    found=true
                fi
            fi
        done < <(docker ps -a --format "{{.Names}} {{.Status}}" 2>/dev/null)

        # Also show stopped containers
        local stopped=$(docker ps -a --filter "status=exited" --format "{{.Names}}" 2>/dev/null | grep "^joinery-")
        if [ -n "$stopped" ]; then
            echo "$stopped" | while read name; do
                if ! docker ps --format "{{.Names}}" 2>/dev/null | grep -q "^${name}$"; then
                    echo "  ${name}	(stopped)"
                    found=true
                fi
            done
        fi

        if [ "$found" = false ]; then
            echo "  (none)"
        fi
        echo ""
    else
        print_info "Docker not installed"
        echo ""
    fi

    # Check for bare-metal sites
    echo -e "${BLUE}Bare-metal sites:${NC}"
    local found=false
    for dir in /var/www/html/*/; do
        if [ -f "${dir}config/Globalvars_site.php" ]; then
            local sitename=$(basename "$dir")
            # Skip test sites in listing (show as suffix)
            if [[ "$sitename" != *"_test" ]]; then
                local status="configured"
                if [ -f "/etc/apache2/sites-enabled/${sitename}.conf" ]; then
                    status="enabled"
                fi
                # Check for companion test site
                local test_suffix=""
                if [ -d "/var/www/html/${sitename}_test" ]; then
                    test_suffix=" (+test site)"
                fi
                echo "  ${sitename}	${status}${test_suffix}"
                found=true
            fi
        fi
    done
    if [ "$found" = false ]; then
        echo "  (none)"
    fi
}

#==============================================================================
# HELP OUTPUT
#==============================================================================

show_help() {
    echo ""
    echo "Joinery Installation Script v2.7"
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
    echo "  list      List existing Joinery sites (Docker and bare-metal)"
    echo ""
    echo "Site Command Options:"
    echo "  --activate THEME       Activate specified theme after installation"
    echo "  --with-test-site       Also create a test site (bare-metal only)"
    echo "  --no-ssl               Skip automatic SSL certificate setup"
    echo "  --docker               Force Docker mode"
    echo "  --bare-metal           Force bare-metal mode"
    echo "  --clone-from=URL       Clone database and uploads from existing site"
    echo "  --clone-key=KEY        Authentication key for clone source"
    echo ""
    echo "SSL (Automatic):"
    echo "  When a domain name is provided (not localhost/IP), SSL is automatically"
    echo "  configured using Let's Encrypt. DNS must point to this server."
    echo "  Use --no-ssl to skip SSL setup."
    echo ""
    echo "Examples:"
    echo "  # Install Docker (once)"
    echo "  sudo ./install.sh docker"
    echo ""
    echo "  # Create Docker site (with automatic SSL)"
    echo "  sudo ./install.sh site production SecurePass! prod.example.com 8080"
    echo ""
    echo "  # Create site without SSL"
    echo "  sudo ./install.sh site staging StagePass! stage.example.com 8081 --no-ssl"
    echo ""
    echo "  # Clone an existing site"
    echo "  sudo ./install.sh site newsite example.com 8080 \\"
    echo "      --clone-from=https://source.example.com --clone-key=SecretKey123"
    echo ""
    echo "  # Set up bare-metal server (once)"
    echo "  sudo ./install.sh server"
    echo ""
    echo "  # Create bare-metal site (with automatic SSL)"
    echo "  sudo ./install.sh site client1 Pass1! client1.example.com"
    echo ""
    echo "  # Create site with test site"
    echo "  sudo ./install.sh site client2 Pass2! client2.example.com --with-test-site"
    echo ""
    echo "  # Non-interactive deployment (for scripting/CI)"
    echo "  sudo ./install.sh -y -q site mysite SecurePass! mysite.com 8080"
    echo ""
    echo "  # List all sites"
    echo "  sudo ./install.sh list"
    echo ""
    echo "Auto-Detection:"
    echo "  'install.sh site' automatically detects the environment:"
    echo "  - With PORT specified: Docker mode"
    echo "  - Without PORT: Bare-metal mode"
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
