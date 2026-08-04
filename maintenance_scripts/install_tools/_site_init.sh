#!/usr/bin/env bash
# _site_init.sh - Internal site initialization
# VERSION: 2.5 - create_config_file refuses to overwrite an existing
#                Globalvars_site.php. It generates a fresh secret_box_key, so a
#                re-run over a live site orphaned every secret encrypted at rest
#                while leaving the database intact.
# VERSION: 2.4 - Core half of spec linode_stackscript: honour JOINERY_ADMIN_EMAIL
#                so the admin account is recoverable by email from the start;
#                record upgrade_source as whatever endpoint this install came
#                from; install the default plugin bundle on fresh sites; point
#                the operator at email setup, which is the first thing a new
#                deployment needs and the thing that makes lockout recoverable.
# VERSION: 2.3 - Replace the seeded admin password with a per-site one on every
#                fresh install. Honours JOINERY_ADMIN_PASSWORD for unattended
#                installers; otherwise generates one and writes it to
#                config/admin_credentials.txt (mode 600).
# VERSION: 2.2 - Generate secret_box_key for SecretBox (secrets at rest) on install
#
# Called by install.sh and Dockerfile CMD
# Do not call directly - use install.sh site instead
#
# Usage (internal):
#   ./_site_init.sh SITENAME PASSWORD DOMAIN [OPTIONS]
#
# Options:
#   --activate THEME       Set active theme
#   --docker-mode          Running inside Docker container (skips virtualhost, serve.php)
#   --clone-from=URL       Clone database and uploads from URL
#   --clone-key=KEY        Authentication key for clone source
#   --skip-db-validation   Skip default admin/settings validation
#   -q, --quiet            Suppress most output

set -e
set +H  # Disable history expansion (prevents ! in passwords from being interpreted)

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
CLONE_FROM=""
CLONE_KEY=""
SKIP_DB_VALIDATION=false

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
        --clone-from=*)
            CLONE_FROM="${1#*=}"
            ;;
        --clone-key=*)
            CLONE_KEY="${1#*=}"
            ;;
        --skip-db-validation)
            SKIP_DB_VALIDATION=true
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
mkdir -p "$SITE_ROOT/uploads/small"
mkdir -p "$SITE_ROOT/uploads/medium"
mkdir -p "$SITE_ROOT/uploads/large"
mkdir -p "$SITE_ROOT/uploads/thumbnail"
mkdir -p "$SITE_ROOT/uploads/lthumbnail"
mkdir -p "$SITE_ROOT/public_html/cache"
mkdir -p "$SITE_ROOT/logs"
mkdir -p "$SITE_ROOT/static_files"
mkdir -p "$SITE_ROOT/backups"
# Durable runtime data (offloaded inbound-mail raw .eml lives here) — on par with
# uploads/ and backups/, NOT scratch like logs/. Must be backed by a persistent volume.
mkdir -p "$SITE_ROOT/storage"

# =============================================================================
# CONFIGURATION FILES
# =============================================================================

# Escape password for sed (handles special characters like /, &, \)
ESCAPED_PASSWORD=$(sed_escape "$PASSWORD")

# Helper function to create config file
create_config_file() {
    # Never overwrite a config that is already there. It holds this deployment's
    # secret_box_key, and the block below mints a fresh one — so rewriting the
    # file leaves every secret encrypted at rest undecryptable: sealed vault
    # wrappings, stored credentials, DKIM keys. The database check further down
    # already skips a database that exists; this is the same guard for the file
    # that holds the keys to it. Silent, unrecoverable, and it looks like a clean
    # install right up until something tries to decrypt.
    if [ -f "$SITE_ROOT/config/Globalvars_site.php" ]; then
        log "Config already exists at $SITE_ROOT/config/Globalvars_site.php - leaving it."
        log "  (it carries this site's secret_box_key; regenerating it would orphan every encrypted secret)"
        return 0
    fi

    log "Configuring site..."
    cp "$GLOBALVARS_TEMPLATE" "$SITE_ROOT/config/Globalvars_site.php"
    sed -i "s/{{PASSWORD}}/${ESCAPED_PASSWORD}/g" "$SITE_ROOT/config/Globalvars_site.php"
    sed -i "s/{{SITE_NAME}}/${SITENAME}/g" "$SITE_ROOT/config/Globalvars_site.php"
    sed -i "s/{{DOMAIN_NAME}}/${DOMAIN}/g" "$SITE_ROOT/config/Globalvars_site.php"
    # Record the deployment environment — single source of truth (spec deployment_environment_flag)
    if [ "$DOCKER_MODE" = true ]; then DEPLOY_ENV=docker; else DEPLOY_ENV=baremetal; fi
    sed -i "s/{{DEPLOYMENT_ENVIRONMENT}}/${DEPLOY_ENV}/g" "$SITE_ROOT/config/Globalvars_site.php"
    # Generate a per-environment SecretBox key (32 random bytes, base64) for secrets at rest
    SECRET_BOX_KEY=$(openssl rand -base64 32)
    ESCAPED_SECRET_BOX_KEY=$(sed_escape "$SECRET_BOX_KEY")
    sed -i "s/{{SECRET_BOX_KEY}}/${ESCAPED_SECRET_BOX_KEY}/g" "$SITE_ROOT/config/Globalvars_site.php"
    # Also handle the legacy pattern with empty password
    sed -i "s/\$this->settings\['dbpassword'\] = '';/\$this->settings['dbpassword'] = '${ESCAPED_PASSWORD}';/g" "$SITE_ROOT/config/Globalvars_site.php"
    # Restrict config file — contains database credentials
    chmod 640 "$SITE_ROOT/config/Globalvars_site.php"
    chown root:www-data "$SITE_ROOT/config/Globalvars_site.php" 2>/dev/null || true
}

# In clone mode, delay config creation until clone completes successfully
# This prevents partial clone state where config exists but clone failed partway
if [ -z "$CLONE_FROM" ]; then
    create_config_file
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

if [ -n "$CLONE_FROM" ]; then
    # ==========================================================================
    # CLONE MODE: Stream database and uploads from source
    # ==========================================================================

    # Create database (ignore error if already exists)
    log "Creating PostgreSQL database '$SITENAME'..."
    createdb -T template0 "$SITENAME" -U postgres 2>/dev/null || true

    set -o pipefail  # Catch failures anywhere in pipeline

    log "Streaming database from clone source..."

    CLONE_URL="${CLONE_FROM}/utils/clone_export"

    curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=database" | \
        openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:${CLONE_KEY} | \
        gunzip | \
        psql -U postgres -d "$SITENAME" -q 2>/dev/null || {
            log_error "Failed to load database from clone source"
            exit 1
        }

    log "Database cloned successfully"

    # Download and extract uploads (skip if source has no uploads)
    log "Downloading uploads from clone source..."

    # Check Content-Type to determine if there are uploads to transfer
    CONTENT_TYPE=$(curl -sI -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=uploads" 2>/dev/null | grep -i "^content-type:" | head -1)

    if echo "$CONTENT_TYPE" | grep -qi "application/json"; then
        # JSON response - no uploads to transfer
        log "Source has no uploads to transfer"
    else
        # Binary response - download to temp file then extract (avoids pipe truncation issues)
        TEMP_UPLOADS=$(mktemp)
        if curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=uploads" -o "$TEMP_UPLOADS"; then
            tar -xzf "$TEMP_UPLOADS" -C "$SITE_ROOT/" || {
                rm -f "$TEMP_UPLOADS"
                log_error "Failed to extract uploads from clone source"
                exit 1
            }
            rm -f "$TEMP_UPLOADS"
            log "Uploads cloned successfully"
        else
            rm -f "$TEMP_UPLOADS"
            log_error "Failed to download uploads from clone source"
            exit 1
        fi
    fi

    # Download and extract static_files (skip if source has no static_files)
    log "Downloading static_files from clone source..."

    # Check Content-Type to determine if there are static_files to transfer
    CONTENT_TYPE=$(curl -sI -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=static_files" 2>/dev/null | grep -i "^content-type:" | head -1)

    if echo "$CONTENT_TYPE" | grep -qi "application/json"; then
        # JSON response - no static_files to transfer
        log "Source has no static_files to transfer"
    else
        # Binary response - download to temp file then extract (avoids pipe truncation issues)
        TEMP_STATIC=$(mktemp)
        if curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=static_files" -o "$TEMP_STATIC"; then
            tar -xzf "$TEMP_STATIC" -C "$SITE_ROOT/" || {
                rm -f "$TEMP_STATIC"
                log_error "Failed to extract static_files from clone source"
                exit 1
            }
            rm -f "$TEMP_STATIC"
            log "Static files cloned successfully"
        else
            rm -f "$TEMP_STATIC"
            log_error "Failed to download static_files from clone source"
            exit 1
        fi
    fi

    # Update site URL in settings
    log "Updating site settings for new domain..."

    psql -U postgres -d "$SITENAME" -q -c \
        "UPDATE stg_settings SET stg_value = 'https://${DOMAIN}' WHERE stg_name = 'site_url';" \
        2>/dev/null || true

    # Disable clone export key on the new site (security)
    psql -U postgres -d "$SITENAME" -q -c \
        "DELETE FROM stg_settings WHERE stg_name = 'clone_export_key';" \
        2>/dev/null || true

    # Reset protocol_mode to 'auto' (cloned site may have different SSL config)
    psql -U postgres -d "$SITENAME" -q -c \
        "UPDATE stg_settings SET stg_value = 'auto' WHERE stg_name = 'protocol_mode';" \
        2>/dev/null || true

    SKIP_DB_VALIDATION=true

    # Clone completed successfully - NOW create the config file
    # This ensures that if clone fails partway, config won't exist and next attempt will retry
    create_config_file

    # Fix PostgreSQL sequences after clone
    # Cloned databases often have sequences out of sync with their data
    log "Synchronizing database sequences..."
    if [ -f "$SITE_ROOT/public_html/utils/fix_sequences.php" ]; then
        php "$SITE_ROOT/public_html/utils/fix_sequences.php" 2>/dev/null || {
            log_error "Warning: Sequence synchronization failed (non-fatal)"
        }
        log "Sequences synchronized"
    fi

elif [ "$DB_EXISTS" = false ]; then
    # ==========================================================================
    # NORMAL MODE: Load from SQL file
    # ==========================================================================

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
# DATABASE VALIDATION (skip for cloned sites)
# =============================================================================

if [ "$SKIP_DB_VALIDATION" = false ] && [ "$DB_EXISTS" = false ]; then
    log "Validating database initialization..."

    VALIDATION_FAILED=false

    # Check that key tables exist
    REQUIRED_TABLES="usr_users stg_settings"
    for table in $REQUIRED_TABLES; do
        TABLE_EXISTS=$(psql -U postgres -d "$SITENAME" -tAc \
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = '$table');" 2>/dev/null)
        if [ "$TABLE_EXISTS" != "t" ]; then
            log_error "Required table '$table' does not exist!"
            VALIDATION_FAILED=true
        fi
    done

    # Check that settings table has data
    SETTINGS_COUNT=$(psql -U postgres -d "$SITENAME" -tAc \
        "SELECT COUNT(*) FROM stg_settings;" 2>/dev/null)
    if [ -z "$SETTINGS_COUNT" ] || [ "$SETTINGS_COUNT" -lt 10 ]; then
        log_error "Settings table is empty or has insufficient data (found: ${SETTINGS_COUNT:-0} rows, expected: 10+)"
        VALIDATION_FAILED=true
    else
        log "Settings table populated: $SETTINGS_COUNT rows"
    fi

    # Check for critical settings (these are always present in fresh installs)
    CRITICAL_SETTINGS="blog_active theme_template"
    for setting in $CRITICAL_SETTINGS; do
        SETTING_EXISTS=$(psql -U postgres -d "$SITENAME" -tAc \
            "SELECT COUNT(*) FROM stg_settings WHERE stg_name = '$setting';" 2>/dev/null)
        if [ "$SETTING_EXISTS" != "1" ]; then
            log_error "Critical setting '$setting' not found in stg_settings!"
            VALIDATION_FAILED=true
        fi
    done
    log "Critical settings verified"

    # Check that users table has the admin user
    ADMIN_EXISTS=$(psql -U postgres -d "$SITENAME" -tAc \
        "SELECT COUNT(*) FROM usr_users WHERE usr_email = 'admin@example.com';" 2>/dev/null)
    if [ "$ADMIN_EXISTS" != "1" ]; then
        log_error "Default admin user (admin@example.com) not found!"
        VALIDATION_FAILED=true
    else
        log "Default admin user exists"
    fi

    # Check migrations table has entries (indicates SQL loaded properly)
    MIGRATIONS_COUNT=$(psql -U postgres -d "$SITENAME" -tAc \
        "SELECT COUNT(*) FROM mig_migrations;" 2>/dev/null)
    if [ -z "$MIGRATIONS_COUNT" ] || [ "$MIGRATIONS_COUNT" -lt 1 ]; then
        log_error "Migrations table is empty - database may not have loaded correctly"
        VALIDATION_FAILED=true
    else
        log "Migrations table populated: $MIGRATIONS_COUNT entries"
    fi

    if [ "$VALIDATION_FAILED" = true ]; then
        log_error "DATABASE VALIDATION FAILED - The database was not initialized correctly."
        log_error "This usually indicates a problem with the SQL restore file or PostgreSQL."
        log_error "Check the logs above for specific errors."
        exit 1
    fi

    log "Database validation passed"
fi

# =============================================================================
# ADMIN CREDENTIAL
# =============================================================================
#
# The shipped database seeds a well-known admin login. Give every fresh site its
# own password before it is reachable, so there is no window in which the login
# is guessable and nothing for the default homepage to publish.
#
# JOINERY_ADMIN_PASSWORD lets an unattended installer hand in a password the
# owner chose on a deploy form. When it is set, nothing is generated and nothing
# is written to disk — there is no file to go and read. Cloned sites are skipped:
# they carry the source site's real accounts, not the seeded default.
#
# JOINERY_ADMIN_EMAIL moves the account to the owner's real address in the same
# call. That ordering matters: a password reset needs a mailbox someone can
# actually receive at, so setting the address afterwards would leave a window
# where the only account on the site is unrecoverable.
ADMIN_EMAIL="${JOINERY_ADMIN_EMAIL:-admin@example.com}"

if [ -z "$CLONE_FROM" ] && [ "$DB_EXISTS" = false ]; then
    RESET_TOOL="${SITE_ROOT}/maintenance_scripts/sysadmin_tools/reset_admin_password.php"

    if [ ! -f "$RESET_TOOL" ]; then
        log_error "Warning: $RESET_TOOL not found — the seeded admin password is still in place."
        log_error "Set one before exposing this site."
    else
        if [ -n "${JOINERY_ADMIN_PASSWORD:-}" ]; then
            ADMIN_PASSWORD="$JOINERY_ADMIN_PASSWORD"
            ADMIN_PASSWORD_SUPPLIED=true
        else
            ADMIN_PASSWORD=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-24)
            ADMIN_PASSWORD_SUPPLIED=false
        fi

        # Handed over in a file, never as an argument — arguments show up in `ps`.
        ADMIN_PW_FILE=$(mktemp)
        chmod 600 "$ADMIN_PW_FILE"
        printf '%s\n' "$ADMIN_PASSWORD" > "$ADMIN_PW_FILE"

        RESET_ARGS="--email=admin@example.com --password-file=$ADMIN_PW_FILE --yes"
        if [ "$ADMIN_EMAIL" != "admin@example.com" ]; then
            RESET_ARGS="$RESET_ARGS --set-email=$ADMIN_EMAIL"
        fi

        if php "$RESET_TOOL" $RESET_ARGS >/dev/null 2>&1; then
            log "Per-site admin password applied"
            if [ "$ADMIN_EMAIL" != "admin@example.com" ]; then
                log "Admin account address set to $ADMIN_EMAIL"
            fi

            if [ "$ADMIN_PASSWORD_SUPPLIED" = false ]; then
                # Nobody chose this password, so it has to be legible somewhere.
                CRED_FILE="${SITE_ROOT}/config/admin_credentials.txt"
                : > "$CRED_FILE"
                chmod 600 "$CRED_FILE"
                chown root:root "$CRED_FILE" 2>/dev/null || true
                {
                    printf 'Joinery admin login for %s\n\n' "$SITENAME"
                    printf 'URL:      http://%s/login\n' "$DOMAIN"
                    printf 'Email:    %s\n' "$ADMIN_EMAIL"
                    printf 'Password: %s\n\n' "$ADMIN_PASSWORD"
                    printf 'You are asked to choose a new password at first sign-in.\n'
                    printf 'Delete this file once you have signed in.\n\n'
                    printf 'FIRST TASK: set up email at\n'
                    printf '  http://%s/admin/admin_settings_email\n\n' "$DOMAIN"
                    printf 'A new site cannot send mail until you name a provider, and\n'
                    printf 'password reset is the only way back into this account once the\n'
                    printf 'password above stops working. Most hosts block outbound port 25,\n'
                    printf 'so a mail server on this machine is generally not an option.\n'
                } > "$CRED_FILE"
                log "Admin credentials written to $CRED_FILE (mode 600)"
            fi
        else
            log_error "Warning: could not apply a per-site admin password."
            log_error "The seeded default is still in place — run"
            log_error "  $RESET_TOOL"
            log_error "before exposing this site."
        fi

        rm -f "$ADMIN_PW_FILE"
        unset ADMIN_PASSWORD
    fi
fi

# =============================================================================
# UPGRADE SOURCE
# =============================================================================
#
# Whatever endpoint this install fetched its code from is the endpoint it will
# fetch every future upgrade from. One rule covers both audiences: nobody
# overrides UPGRADE_SERVER, so a public install upgrades from the release site;
# we pass --upgrade-server, so ours upgrade from wherever we said. Without this
# a fresh site could come up believing it upgrades from somewhere it is already
# ahead of.
#
# Clones are skipped. UPGRADE_SERVER is pointed at the clone source for the
# duration of a clone, and that is a peer site rather than a release endpoint —
# the cloned database already carries the source's own upgrade_source, which is
# the right answer.
if [ -z "$CLONE_FROM" ] && [ -n "${UPGRADE_SERVER:-}" ]; then
    UPGRADE_SOURCE_VALUE="${UPGRADE_SERVER%/}"

    psql -U postgres -d "$SITENAME" -q -c \
        "UPDATE stg_settings SET stg_value = '${UPGRADE_SOURCE_VALUE}' WHERE stg_name = 'upgrade_source';" \
        2>/dev/null || true

    psql -U postgres -d "$SITENAME" -q -c \
        "INSERT INTO stg_settings (stg_name, stg_value)
         SELECT 'upgrade_source', '${UPGRADE_SOURCE_VALUE}'
         WHERE NOT EXISTS (SELECT 1 FROM stg_settings WHERE stg_name = 'upgrade_source');" \
        2>/dev/null || true

    log "Upgrades will come from $UPGRADE_SOURCE_VALUE"
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
# DEFAULT PLUGIN BUNDLE
# =============================================================================
#
# A new site otherwise arrives as the bare platform: every plugin's files are on
# disk and none of them are installed. The bundle is what makes the deployment
# the product someone thought they were installing.
#
# Fresh installs only. A clone carries the source site's own plugin set, and a
# site coming back up on an existing database has already been through this.
# Set JOINERY_INSTALL_BUNDLE=none to skip it.
BUNDLE_NAME="${JOINERY_INSTALL_BUNDLE:-personal}"

if [ -z "$CLONE_FROM" ] && [ "$DB_EXISTS" = false ] && [ "$BUNDLE_NAME" != "none" ]; then
    BUNDLE_TOOL="${SITE_ROOT}/maintenance_scripts/sysadmin_tools/install_bundle.php"

    if [ ! -f "$BUNDLE_TOOL" ]; then
        log_error "Warning: $BUNDLE_TOOL not found — no plugins were installed."
    else
        log "Installing the '$BUNDLE_NAME' plugin bundle..."
        # Non-fatal. A site with no plugins is a working site; the operator can
        # install them from /admin/admin_plugins. Losing the whole install over
        # it would be the wrong trade.
        if php "$BUNDLE_TOOL" --bundle="$BUNDLE_NAME"; then
            log "Plugin bundle installed"
        else
            log_error "Warning: the '$BUNDLE_NAME' bundle did not install cleanly."
            log_error "Install what you need from /admin/admin_plugins."
        fi
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
    # Create test site directories FIRST — the virtualhost template references them, so
    # Apache reload will fail if they don't exist yet when the vhost is enabled.
    log "Creating test site directories..."
    mkdir -p "/var/www/html/${SITENAME}_test/public_html"
    mkdir -p "/var/www/html/${SITENAME}_test/logs"

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
    chmod -R 775 "$SITE_ROOT/uploads"
    chmod -R 775 "$SITE_ROOT/storage"
    chmod -R 775 "$SITE_ROOT/public_html/cache"
    chmod -R 775 "$SITE_ROOT/logs"
fi

# =============================================================================
# LOG ROTATION SETUP
# =============================================================================

log "Setting up log rotation..."

LOGROTATE_TEMPLATE="${SCRIPT_DIR}/logrotate_joinery.conf"
LOGROTATE_DEST="/etc/logrotate.d/joinery-${SITENAME}"

if [ -f "$LOGROTATE_TEMPLATE" ]; then
    cp "$LOGROTATE_TEMPLATE" "$LOGROTATE_DEST"
    sed -i "s|{{SITE_ROOT}}|${SITE_ROOT}|g" "$LOGROTATE_DEST"
    chmod 644 "$LOGROTATE_DEST"
    log "Log rotation configured: $LOGROTATE_DEST"
else
    log_error "Warning: logrotate template not found at $LOGROTATE_TEMPLATE (non-fatal)"
fi

# =============================================================================
# CRON SETUP
# =============================================================================

log "Setting up cron jobs..."

# Write to /etc/cron.d/ — more durable than user crontab (survives script re-runs,
# works identically on bare metal and Docker as long as the cron service is running).
# /etc/cron.d/ format requires the username in the line; file must not be world-writable.
#
# Every minute, not every five: the tick interval is the floor on latency for
# every every_run task, and inbound mail is the one users feel — a relay-fronted
# deployment cannot see a message until the next PullRelaySpool. A full pass
# costs about a second, and the runner holds a per-task advisory lock, so a
# slow task is skipped rather than run concurrently.
CRON_FILE="/etc/cron.d/joinery-${SITENAME}"
CRON_LINE="* * * * * www-data php ${SITE_ROOT}/public_html/utils/process_scheduled_tasks.php >> ${SITE_ROOT}/logs/cron_scheduled_tasks.log 2>&1"
printf '%s\n' "$CRON_LINE" > "$CRON_FILE" && chmod 644 "$CRON_FILE" && {
    log "Scheduled tasks cron entry installed: $CRON_FILE"
} || {
    log_error "Warning: Could not write $CRON_FILE (non-fatal)"
}

# In Docker, cron isn't started automatically — ensure it's running.
if [ "$DOCKER_MODE" = true ]; then
    service cron start 2>/dev/null || true
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
