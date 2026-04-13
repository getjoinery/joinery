#!/usr/bin/env bash
# manage_domain.sh - Manage domain assignments for Joinery sites
# VERSION: 1.0
#
# Usage:
#   ./manage_domain.sh COMMAND SITENAME [OPTIONS]
#
# Commands:
#   set SITENAME DOMAIN [--no-ssl]  - Assign or change domain
#   clear SITENAME                   - Remove domain, revert to IP-only
#   status SITENAME                  - Show current configuration
#   rollback SITENAME                - Restore previous configuration
#   remove-ssl SITENAME              - Remove SSL, keep domain

set -e

#==============================================================================
# CONFIGURATION
#==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

#==============================================================================
# COLORS AND OUTPUT
#==============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[OK]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

#==============================================================================
# HELPER FUNCTIONS
#==============================================================================

# Get server's public IPv4 address
get_public_ip() {
    curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || \
    curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || \
    hostname -I | awk '{print $1}'
}

# Check if value is an IP address
is_ip_address() {
    local value="$1"
    [[ "$value" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

# Check if site is running in Docker
is_docker_site() {
    local sitename="$1"
    docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$sitename"
}

# Get Docker container port
get_container_port() {
    local sitename="$1"
    docker port "$sitename" 80 2>/dev/null | head -1 | sed 's/.*://'
}

# Check if an IP belongs to Cloudflare
is_cloudflare_ip() {
    local ip="$1"

    # Fetch current Cloudflare IP ranges (with fallback)
    local cf_ranges=$(curl -s --max-time 5 https://www.cloudflare.com/ips-v4 2>/dev/null)

    if [ -z "$cf_ranges" ]; then
        # Fallback to known ranges
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

# Check if DNS points to this server
check_dns_points_here() {
    local domain="$1"

    local server_ip=$(get_public_ip)
    if [ -z "$server_ip" ]; then
        print_warning "Could not determine server's public IP"
        return 1
    fi

    local dns_ip=$(dig +short "$domain" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
    if [ -z "$dns_ip" ]; then
        print_warning "DNS lookup failed for $domain"
        return 1
    fi

    if [ "$dns_ip" = "$server_ip" ]; then
        return 0
    fi

    if is_cloudflare_ip "$dns_ip"; then
        print_info "DNS for $domain points to Cloudflare proxy ($dns_ip)"
        return 2
    fi

    print_warning "DNS for $domain points to $dns_ip, but this server is $server_ip"
    return 1
}

# Create backup of current configuration
create_backup() {
    local sitename="$1"
    local backup_dir="/var/www/html/${sitename}/backups/domain"
    local timestamp=$(date +%Y%m%d_%H%M%S)

    mkdir -p "$backup_dir"

    # Backup Apache config
    if [ -f "/etc/apache2/sites-available/${sitename}.conf" ]; then
        cp "/etc/apache2/sites-available/${sitename}.conf" "$backup_dir/${sitename}.conf.${timestamp}"
        print_info "Backed up Apache config to ${backup_dir}/${sitename}.conf.${timestamp}"
    fi

    # For Docker sites, also backup proxy config if it exists
    if [ -f "/etc/apache2/sites-available/${sitename}-proxy.conf" ]; then
        cp "/etc/apache2/sites-available/${sitename}-proxy.conf" "$backup_dir/${sitename}-proxy.conf.${timestamp}"
    fi

    echo "$timestamp"
}

# Get latest backup timestamp
get_latest_backup() {
    local sitename="$1"
    local backup_dir="/var/www/html/${sitename}/backups/domain"

    if [ ! -d "$backup_dir" ]; then
        return 1
    fi

    ls -1t "$backup_dir"/*.conf.* 2>/dev/null | head -1 | sed 's/.*\.\([0-9_]*\)$/\1/'
}

# Ensure Apache with mod_proxy is available on host (for Docker sites)
ensure_apache_on_host() {
    if ! command -v apache2 &> /dev/null; then
        print_info "Installing Apache on host for reverse proxy..."
        apt-get update -qq
        apt-get install -y -qq apache2
    fi

    if ! apache2ctl -M 2>/dev/null | grep -q proxy_http; then
        print_info "Enabling Apache proxy modules..."
        a2enmod proxy proxy_http >/dev/null
        systemctl reload apache2
    fi
}

#==============================================================================
# COMMAND: STATUS
#==============================================================================

cmd_status() {
    local sitename="$1"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 status SITENAME"
        exit 3
    fi

    echo ""
    echo -e "${BLUE}Site: ${GREEN}${sitename}${NC}"
    echo "─────────────────────────────────────────"

    # Detect site type
    if is_docker_site "$sitename"; then
        local port=$(get_container_port "$sitename")
        echo -e "Type:   ${GREEN}Docker${NC} (port $port)"

        # Check for proxy config
        if [ -f "/etc/apache2/sites-available/${sitename}-proxy.conf" ]; then
            local domain=$(grep -m1 "ServerName" "/etc/apache2/sites-available/${sitename}-proxy.conf" 2>/dev/null | awk '{print $2}')
            if [ -n "$domain" ]; then
                echo -e "Domain: ${GREEN}${domain}${NC}"
            else
                echo -e "Domain: ${YELLOW}(IP-only)${NC}"
            fi

            # Check SSL
            if [ -f "/etc/apache2/sites-available/${sitename}-proxy-le-ssl.conf" ]; then
                echo -e "SSL:    ${GREEN}Enabled${NC}"
                local cert_file=$(grep -m1 "SSLCertificateFile" "/etc/apache2/sites-available/${sitename}-proxy-le-ssl.conf" 2>/dev/null | awk '{print $2}')
                if [ -n "$cert_file" ] && [ -f "$cert_file" ]; then
                    local expiry=$(openssl x509 -enddate -noout -in "$cert_file" 2>/dev/null | cut -d= -f2)
                    echo -e "Expiry: ${BLUE}${expiry}${NC}"
                fi
            else
                echo -e "SSL:    ${YELLOW}Disabled${NC}"
            fi
        else
            echo -e "Domain: ${YELLOW}(IP-only, no proxy)${NC}"
        fi
    elif [ -f "/etc/apache2/sites-available/${sitename}.conf" ]; then
        echo -e "Type:   ${GREEN}Bare-metal${NC}"

        local domain=$(grep -m1 "ServerName" "/etc/apache2/sites-available/${sitename}.conf" 2>/dev/null | awk '{print $2}')
        if [ -n "$domain" ] && ! is_ip_address "$domain"; then
            echo -e "Domain: ${GREEN}${domain}${NC}"
        else
            echo -e "Domain: ${YELLOW}(IP-only)${NC}"
        fi

        # Check SSL
        if [ -f "/etc/apache2/sites-available/${sitename}-le-ssl.conf" ]; then
            echo -e "SSL:    ${GREEN}Enabled${NC}"
            local cert_file=$(grep -m1 "SSLCertificateFile" "/etc/apache2/sites-available/${sitename}-le-ssl.conf" 2>/dev/null | awk '{print $2}')
            if [ -n "$cert_file" ] && [ -f "$cert_file" ]; then
                local expiry=$(openssl x509 -enddate -noout -in "$cert_file" 2>/dev/null | cut -d= -f2)
                echo -e "Expiry: ${BLUE}${expiry}${NC}"
            fi
        else
            echo -e "SSL:    ${YELLOW}Disabled${NC}"
        fi
    else
        print_error "Site not found: $sitename"
        exit 2
    fi

    echo ""
}

#==============================================================================
# COMMAND: SET
#==============================================================================

cmd_set() {
    local sitename="$1"
    local domain="$2"
    local no_ssl=false

    shift 2 || true
    while [[ $# -gt 0 ]]; do
        case $1 in
            --no-ssl) no_ssl=true ;;
            *) print_error "Unknown option: $1"; exit 3 ;;
        esac
        shift
    done

    if [ -z "$sitename" ] || [ -z "$domain" ]; then
        print_error "Usage: $0 set SITENAME DOMAIN [--no-ssl]"
        exit 3
    fi

    # Create backup before changes
    print_info "Creating backup..."
    create_backup "$sitename"

    if is_docker_site "$sitename"; then
        set_domain_docker "$sitename" "$domain" "$no_ssl"
    elif [ -f "/etc/apache2/sites-available/${sitename}.conf" ]; then
        set_domain_baremetal "$sitename" "$domain" "$no_ssl"
    else
        print_error "Site not found: $sitename"
        exit 2
    fi
}

set_domain_docker() {
    local sitename="$1"
    local domain="$2"
    local no_ssl="$3"

    print_info "Configuring Docker site: $sitename"

    # Ensure Apache is available on host
    ensure_apache_on_host

    local port=$(get_container_port "$sitename")
    if [ -z "$port" ]; then
        print_error "Could not determine container port for $sitename"
        exit 1
    fi

    print_info "Container port: $port"

    # Create reverse proxy config
    local proxy_conf="/etc/apache2/sites-available/${sitename}-proxy.conf"

    cat > "$proxy_conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/

    ErrorLog /var/www/html/${sitename}/logs/proxy_error.log
    CustomLog /var/www/html/${sitename}/logs/proxy_access.log combined
</VirtualHost>
EOF

    # Enable the proxy site
    a2ensite "${sitename}-proxy.conf" >/dev/null 2>&1 || true

    # Disable the Ubuntu default vhost so bare-IP hits (no Host header match) fall
    # through to our site's proxy instead of the Apache welcome page. Idempotent:
    # a2dissite silently succeeds if already disabled.
    a2dissite 000-default.conf >/dev/null 2>&1 || true

    # Test and reload Apache
    if ! apachectl configtest 2>/dev/null; then
        print_error "Apache configuration error"
        exit 4
    fi

    systemctl reload apache2
    print_success "Apache proxy configured for $domain -> localhost:$port"

    # Handle SSL
    if [ "$no_ssl" = false ]; then
        setup_ssl "$sitename" "$domain" "${sitename}-proxy"
    else
        print_info "Skipping SSL setup (--no-ssl)"
    fi

    print_success "Domain $domain configured for $sitename"
}

set_domain_baremetal() {
    local sitename="$1"
    local domain="$2"
    local no_ssl="$3"

    print_info "Configuring bare-metal site: $sitename"

    local conf="/etc/apache2/sites-available/${sitename}.conf"

    # Update ServerName in config
    if grep -q "ServerName" "$conf"; then
        sed -i "s/ServerName .*/ServerName ${domain}/" "$conf"
    else
        # Add ServerName after VirtualHost line
        sed -i "/<VirtualHost/a\\    ServerName ${domain}" "$conf"
    fi

    # Test and reload Apache
    if ! apachectl configtest 2>/dev/null; then
        print_error "Apache configuration error"
        exit 4
    fi

    systemctl reload apache2
    print_success "Apache configured for $domain"

    # Handle SSL
    if [ "$no_ssl" = false ]; then
        setup_ssl "$sitename" "$domain" "$sitename"
    else
        print_info "Skipping SSL setup (--no-ssl)"
    fi

    print_success "Domain $domain configured for $sitename"
}

setup_ssl() {
    local sitename="$1"
    local domain="$2"
    local conf_name="$3"

    # Skip for localhost
    if [ "$domain" = "localhost" ]; then
        print_info "Skipping SSL for localhost"
        return 0
    fi

    # Skip for IP addresses
    if is_ip_address "$domain"; then
        print_info "Skipping SSL for IP address"
        return 0
    fi

    # Check DNS
    print_info "Checking DNS for $domain..."
    local dns_result=0
    check_dns_points_here "$domain" || dns_result=$?

    if [ $dns_result -eq 1 ]; then
        print_warning "DNS does not point to this server. Skipping SSL."
        print_info "Configure DNS first, then run: certbot --apache -d $domain"
        return 0
    elif [ $dns_result -eq 2 ]; then
        print_info "Cloudflare detected - SSL handled by Cloudflare"
        return 0
    fi

    # Install certbot if not present
    if ! command -v certbot &> /dev/null; then
        print_info "Installing certbot..."
        apt-get update -qq
        apt-get install -y -qq certbot python3-certbot-apache
    fi

    # Run certbot
    print_info "Obtaining SSL certificate..."
    if certbot --apache -d "$domain" --non-interactive --agree-tos --register-unsafely-without-email 2>/dev/null; then
        print_success "SSL certificate installed for $domain"
    else
        print_warning "SSL certificate installation failed"
        print_info "You can try manually: certbot --apache -d $domain"
        return 5
    fi
}

#==============================================================================
# COMMAND: CLEAR
#==============================================================================

cmd_clear() {
    local sitename="$1"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 clear SITENAME"
        exit 3
    fi

    # Create backup before changes
    print_info "Creating backup..."
    create_backup "$sitename"

    local server_ip=$(get_public_ip)

    if is_docker_site "$sitename"; then
        clear_domain_docker "$sitename" "$server_ip"
    elif [ -f "/etc/apache2/sites-available/${sitename}.conf" ]; then
        clear_domain_baremetal "$sitename" "$server_ip"
    else
        print_error "Site not found: $sitename"
        exit 2
    fi
}

clear_domain_docker() {
    local sitename="$1"
    local server_ip="$2"

    print_info "Clearing domain for Docker site: $sitename"

    # Disable and remove proxy config
    a2dissite "${sitename}-proxy.conf" 2>/dev/null || true
    a2dissite "${sitename}-proxy-le-ssl.conf" 2>/dev/null || true
    rm -f "/etc/apache2/sites-available/${sitename}-proxy.conf"
    rm -f "/etc/apache2/sites-available/${sitename}-proxy-le-ssl.conf"

    systemctl reload apache2

    local port=$(get_container_port "$sitename")
    print_success "Domain cleared. Site accessible at http://${server_ip}:${port}/"
}

clear_domain_baremetal() {
    local sitename="$1"
    local server_ip="$2"

    print_info "Clearing domain for bare-metal site: $sitename"

    local conf="/etc/apache2/sites-available/${sitename}.conf"

    # Replace ServerName with IP
    sed -i "s/ServerName .*/ServerName ${server_ip}/" "$conf"

    # Disable SSL config if exists
    a2dissite "${sitename}-le-ssl.conf" 2>/dev/null || true

    # Test and reload Apache
    if ! apachectl configtest 2>/dev/null; then
        print_error "Apache configuration error"
        exit 4
    fi

    systemctl reload apache2
    print_success "Domain cleared. Site accessible at http://${server_ip}/"
}

#==============================================================================
# COMMAND: ROLLBACK
#==============================================================================

cmd_rollback() {
    local sitename="$1"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 rollback SITENAME"
        exit 3
    fi

    local backup_dir="/var/www/html/${sitename}/backups/domain"

    if [ ! -d "$backup_dir" ]; then
        print_error "No backups found for $sitename"
        exit 1
    fi

    # Find latest backup
    local latest_backup=$(ls -1t "$backup_dir"/${sitename}.conf.* 2>/dev/null | head -1)

    if [ -z "$latest_backup" ]; then
        print_error "No backup files found"
        exit 1
    fi

    local timestamp=$(echo "$latest_backup" | sed 's/.*\.\([0-9_]*\)$/\1/')
    print_info "Restoring from backup: $timestamp"

    # Restore main config
    if [ -f "$backup_dir/${sitename}.conf.${timestamp}" ]; then
        cp "$backup_dir/${sitename}.conf.${timestamp}" "/etc/apache2/sites-available/${sitename}.conf"
        print_info "Restored ${sitename}.conf"
    fi

    # Restore proxy config if exists
    if [ -f "$backup_dir/${sitename}-proxy.conf.${timestamp}" ]; then
        cp "$backup_dir/${sitename}-proxy.conf.${timestamp}" "/etc/apache2/sites-available/${sitename}-proxy.conf"
        a2ensite "${sitename}-proxy.conf" >/dev/null 2>&1 || true
        print_info "Restored ${sitename}-proxy.conf"
    fi

    # Test and reload Apache
    if ! apachectl configtest 2>/dev/null; then
        print_error "Apache configuration error after restore"
        exit 4
    fi

    systemctl reload apache2
    print_success "Configuration restored from $timestamp"
}

#==============================================================================
# COMMAND: REMOVE-SSL
#==============================================================================

cmd_remove_ssl() {
    local sitename="$1"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 remove-ssl SITENAME"
        exit 3
    fi

    # Create backup before changes
    print_info "Creating backup..."
    create_backup "$sitename"

    # Determine which SSL config to remove
    local ssl_conf=""
    if is_docker_site "$sitename"; then
        ssl_conf="${sitename}-proxy-le-ssl"
    else
        ssl_conf="${sitename}-le-ssl"
    fi

    if [ -f "/etc/apache2/sites-available/${ssl_conf}.conf" ]; then
        a2dissite "${ssl_conf}.conf" 2>/dev/null || true
        rm -f "/etc/apache2/sites-available/${ssl_conf}.conf"
        systemctl reload apache2
        print_success "SSL removed for $sitename"
    else
        print_info "No SSL configuration found for $sitename"
    fi
}

#==============================================================================
# MAIN
#==============================================================================

show_usage() {
    echo "Usage: $0 COMMAND SITENAME [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  set SITENAME DOMAIN [--no-ssl]  Assign or change domain"
    echo "  clear SITENAME                  Remove domain, revert to IP-only"
    echo "  status SITENAME                 Show current configuration"
    echo "  rollback SITENAME               Restore previous configuration"
    echo "  remove-ssl SITENAME             Remove SSL, keep domain"
    echo ""
    echo "Examples:"
    echo "  $0 set mysite example.com"
    echo "  $0 set mysite example.com --no-ssl"
    echo "  $0 status mysite"
    echo "  $0 clear mysite"
}

# Check for root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root"
    exit 1
fi

# Parse command
COMMAND="${1:-}"
shift || true

case "$COMMAND" in
    set)
        cmd_set "$@"
        ;;
    clear)
        cmd_clear "$@"
        ;;
    status)
        cmd_status "$@"
        ;;
    rollback)
        cmd_rollback "$@"
        ;;
    remove-ssl)
        cmd_remove_ssl "$@"
        ;;
    -h|--help|help|"")
        show_usage
        exit 0
        ;;
    *)
        print_error "Unknown command: $COMMAND"
        show_usage
        exit 3
        ;;
esac
