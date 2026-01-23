#!/usr/bin/env bash
# _site_init.sh - Internal site initialization
# VERSION: 1.0
#
# Called by install.sh and Dockerfile CMD
# Do not call directly - use install.sh site instead
#
# Usage (internal):
#   ./_site_init.sh SITENAME PASSWORD DOMAIN [OPTIONS]
#
# Options:
#   --activate THEME    Set active theme
#   --docker-mode       Running inside Docker container (skips virtualhost, serve.php)
#   -q, --quiet         Suppress most output

set -e

# Get script directory for finding template files
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

SITENAME="$1"
PASSWORD="$2"
DOMAIN="$3"
shift 3 || true

# Defaults
DOCKER_MODE=false
ACTIVATE_THEME=""
QUIET=false

# Parse options
while [[ $# -gt 0 ]]; do
    case $1 in
        --docker-mode)
            DOCKER_MODE=true
            ;;
        --activate)
            ACTIVATE_THEME="$2"
            shift
            ;;
        -q|--quiet)
            QUIET=true
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
    shift
done

# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

log() {
    if [ "$QUIET" = false ]; then
        echo "$1"
    fi
}

log_error() {
    echo "ERROR: $1" >&2
}

# Escape string for use in sed replacement (handles /, &, \, etc.)
sed_escape() {
    printf '%s\n' "$1" | sed -e 's/[\/&]/\\&/g'
}

# =============================================================================
# VALIDATION
# =============================================================================

if [ -z "$SITENAME" ] || [ -z "$PASSWORD" ] || [ -z "$DOMAIN" ]; then
    log_error "Usage: _site_init.sh SITENAME PASSWORD DOMAIN [OPTIONS]"
    log_error "This script is for internal use only. Use install.sh site instead."
    exit 1
fi

if [ "$EUID" -ne 0 ]; then
    log_error "This script must be run as root"
    exit 1
fi

# =============================================================================
# CONFIGURATION
# =============================================================================

SITE_ROOT="/var/www/html/$SITENAME"

# Template files location
GLOBALVARS_TEMPLATE="${SCRIPT_DIR}/default_Globalvars_site.php"
SERVE_TEMPLATE="${SCRIPT_DIR}/default_serve.php"
VIRTUALHOST_TEMPLATE="${SCRIPT_DIR}/default_virtualhost.conf"
SQL_RESTORE="${SCRIPT_DIR}/joinery-install.sql.gz"

# Verify required files exist
for file in "$GLOBALVARS_TEMPLATE" "$SQL_RESTORE"; do
    if [ ! -f "$file" ]; then
        log_error "Required file not found: $file"
        exit 1
    fi
done

# =============================================================================
# DIRECTORY CREATION
# =============================================================================

log "Creating directory structure..."

mkdir -p "$SITE_ROOT/config"
mkdir -p "$SITE_ROOT/public_html/uploads/small"
mkdir -p "$SITE_ROOT/public_html/uploads/medium"
mkdir -p "$SITE_ROOT/public_html/uploads/large"
mkdir -p "$SITE_ROOT/public_html/uploads/thumbnail"
mkdir -p "$SITE_ROOT/public_html/uploads/lthumbnail"
mkdir -p "$SITE_ROOT/public_html/cache"
mkdir -p "$SITE_ROOT/logs"
mkdir -p "$SITE_ROOT/static_files"
mkdir -p "$SITE_ROOT/backups"

# =============================================================================
# CONFIGURATION FILES
# =============================================================================

log "Configuring site..."

# Escape password for sed (handles special characters like /, &, \)
ESCAPED_PASSWORD=$(sed_escape "$PASSWORD")

# Copy and configure Globalvars_site.php
cp "$GLOBALVARS_TEMPLATE" "$SITE_ROOT/config/Globalvars_site.php"
sed -i "s/{{PASSWORD}}/${ESCAPED_PASSWORD}/g" "$SITE_ROOT/config/Globalvars_site.php"
sed -i "s/{{SITE_NAME}}/${SITENAME}/g" "$SITE_ROOT/config/Globalvars_site.php"
sed -i "s/{{DOMAIN_NAME}}/${DOMAIN}/g" "$SITE_ROOT/config/Globalvars_site.php"
# Also handle the legacy pattern with empty password
sed -i "s/\$this->settings\['dbpassword'\] = '';/\$this->settings['dbpassword'] = '${ESCAPED_PASSWORD}';/g" "$SITE_ROOT/config/Globalvars_site.php"

# Copy serve.php (skip in Docker - already copied during build)
if [ "$DOCKER_MODE" = false ]; then
    if [ -f "$SERVE_TEMPLATE" ]; then
        cp "$SERVE_TEMPLATE" "$SITE_ROOT/public_html/serve.php"
    fi
fi

# =============================================================================
# DATABASE SETUP
# =============================================================================

log "Setting up database..."

# Export password for PostgreSQL commands
export PGPASSWORD="$PASSWORD"

# Check if database already exists (handles container restarts with persistent volumes)
DB_EXISTS=false
if psql -U postgres -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw "$SITENAME"; then
    DB_EXISTS=true
    log "Database '$SITENAME' already exists. Skipping creation and restore."
fi

if [ "$DB_EXISTS" = false ]; then
    # Create database (ignore error if already exists)
    log "Creating PostgreSQL database '$SITENAME'..."
    createdb -T template0 "$SITENAME" -U postgres 2>/dev/null || true

    # Load SQL restore
    log "Loading database schema..."
    if [ -f "$SQL_RESTORE" ]; then
        gunzip -c "$SQL_RESTORE" | psql -U postgres -d "$SITENAME" -q 2>/dev/null || {
            log_error "Failed to load database schema from $SQL_RESTORE"
            exit 1
        }
        log "Database '$SITENAME' loaded successfully."
    fi
fi

# =============================================================================
# COMPOSER INSTALL
# =============================================================================

log "Installing PHP dependencies..."

cd "$SITE_ROOT/public_html"

# Use the existing composer_install_if_needed.php script if available
if [ -f "$SITE_ROOT/public_html/utils/composer_install_if_needed.php" ]; then
    php "$SITE_ROOT/public_html/utils/composer_install_if_needed.php" || {
        log_error "Composer install failed"
        # Don't exit - continue with setup even if composer fails
    }
else
    # Find composer (check common locations)
    COMPOSER_CMD=""
    if command -v composer &> /dev/null; then
        COMPOSER_CMD="composer"
    elif [ -f "/usr/local/bin/composer" ]; then
        COMPOSER_CMD="/usr/local/bin/composer"
    elif [ -f "$HOME/composer.phar" ]; then
        COMPOSER_CMD="php $HOME/composer.phar"
    fi

    if [ -n "$COMPOSER_CMD" ] && [ -f "$SITE_ROOT/public_html/composer.json" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
        $COMPOSER_CMD install --no-dev --optimize-autoloader --quiet 2>/dev/null || {
            log_error "Composer not found or install failed - skipping dependency installation"
        }
    fi
fi

# =============================================================================
# THEME ACTIVATION
# =============================================================================

if [ -n "$ACTIVATE_THEME" ]; then
    log "Activating theme: $ACTIVATE_THEME"

    # Check if theme exists
    if [ -d "$SITE_ROOT/public_html/theme/$ACTIVATE_THEME" ]; then
        # Update database setting
        psql -U postgres -d "$SITENAME" -q -c \
            "UPDATE stg_settings SET stg_value = '$ACTIVATE_THEME' WHERE stg_name = 'theme_template';" 2>/dev/null || true

        # Insert if not exists
        psql -U postgres -d "$SITENAME" -q -c \
            "INSERT INTO stg_settings (stg_name, stg_value)
             SELECT 'theme_template', '$ACTIVATE_THEME'
             WHERE NOT EXISTS (SELECT 1 FROM stg_settings WHERE stg_name = 'theme_template');" 2>/dev/null || true
    else
        log_error "Theme not found: $ACTIVATE_THEME"
    fi
fi

# =============================================================================
# VIRTUALHOST SETUP (bare-metal only)
# =============================================================================

if [ "$DOCKER_MODE" = false ]; then
    log "Configuring Apache virtualhost..."

    if [ -f "$VIRTUALHOST_TEMPLATE" ]; then
        # Detect server IP
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -z "$SERVER_IP" ]; then
            SERVER_IP="*"
        fi

        cp "$VIRTUALHOST_TEMPLATE" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{DOMAIN_NAME}}/${DOMAIN}/g" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{SITE_NAME}}/${SITENAME}/g" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{SERVER_IP}}/${SERVER_IP}/g" "/etc/apache2/sites-available/${SITENAME}.conf"

        # Disable default site
        a2dissite 000-default.conf 2>/dev/null || true

        # Enable the new site
        a2ensite "${SITENAME}.conf" > /dev/null

        # Reload Apache
        if systemctl is-active --quiet apache2 2>/dev/null; then
            systemctl reload apache2
        elif service apache2 status >/dev/null 2>&1; then
            service apache2 reload
        fi
    else
        log_error "Virtualhost template not found: $VIRTUALHOST_TEMPLATE"
    fi

    # Create test site directories (bare-metal only)
    log "Creating test site directories..."
    mkdir -p "/var/www/html/${SITENAME}_test/public_html"
    mkdir -p "/var/www/html/${SITENAME}_test/logs"
fi

# =============================================================================
# PERMISSIONS
# =============================================================================

log "Setting permissions..."

# Use centralized fix_permissions script if available
if [ -f "${SCRIPT_DIR}/fix_permissions.sh" ]; then
    "${SCRIPT_DIR}/fix_permissions.sh" "$SITENAME" --production 2>/dev/null || true
    if [ "$DOCKER_MODE" = false ] && [ -d "/var/www/html/${SITENAME}_test" ]; then
        "${SCRIPT_DIR}/fix_permissions.sh" "${SITENAME}_test" --production 2>/dev/null || true
    fi
else
    # Fallback: set permissions manually
    chown -R www-data:www-data "$SITE_ROOT"
    chmod -R 755 "$SITE_ROOT/public_html"
    chmod -R 775 "$SITE_ROOT/public_html/uploads"
    chmod -R 775 "$SITE_ROOT/public_html/cache"
    chmod -R 775 "$SITE_ROOT/logs"
fi

# =============================================================================
# COMPLETE
# =============================================================================

log "Site initialization complete."
log "Site: $SITENAME"
log "Domain: $DOMAIN"
if [ "$DOCKER_MODE" = false ]; then
    log "Main site: http://${DOMAIN}"
    log "Test site: http://test.${DOMAIN}"
fi
