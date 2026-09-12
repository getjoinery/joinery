#!/usr/bin/env bash
# _site_init.sh - Internal site initialization
# VERSION: 3.3 - The sending provider is detected from the key when none is named.
# VERSION: 3.2 - Three optional services a fresh site can be handed at install,
#                honoured here so every path that reaches this script - bare
#                metal, Docker, the Linode StackScript - takes the same inputs:
#                a DNS credential kept for the mail records (JOINERY_DNS_CREDENTIAL),
#                a sending key that sets email up (JOINERY_MAIL_API_KEY), and a
#                bucket that becomes the backup target (JOINERY_BACKUP_*). The
#                outcomes are written to config/install_services.txt for the
#                closing summary, and the credentials file's first-task advice
#                says email is done when it is.
# VERSION: 3.1 - The secret_box_key generator comes from _config_secrets.sh,
#                shared with the root moments that mint it on a site older than
#                the key. The web side no longer writes config at all
#                (specs/read_only_tree.md).
# VERSION: 3.0 - A password the owner chose on the deploy form (JOINERY_ADMIN_PASSWORD)
#                is not marked for change at first login; only a generated one is.
# VERSION: 2.9 - Globalvars_site.php is filled by _write_site_config.php, so the
#                database password can be any string. sed needed its own escaping
#                and the result still had to parse as PHP; the union of the two
#                alphabets is where "never use ' \ $ ! in a password" came from.
# VERSION: 2.8 - a database that cannot be created, or a schema that cannot be
#                loaded, reports what PostgreSQL actually said. Both calls sent
#                stderr to /dev/null, so an authentication failure surfaced only
#                as "Failed to load database schema" with no cause anywhere.
# VERSION: 2.7 - creates the cache directory the code actually reads
#                ({site root}/cache/static_pages, not public_html/cache), and
#                writes the scheduled-task cron file only on bare metal — in a
#                container the start command owns it, since /etc/cron.d does
#                not survive a rebuild and this script runs once.
# VERSION: 2.6 - the database password may arrive in JOINERY_DB_PASSWORD instead
#                of argv, which is world-readable through ps for the life of
#                the process. The positional is still accepted.
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
#   JOINERY_DB_PASSWORD=... ./_site_init.sh SITENAME "" DOMAIN [OPTIONS]
#
# Prefer the second form. argv is readable by every account on the box through
# ps for as long as this runs; the environment is not. The positional stays
# supported for the Dockerfile CMD and any existing caller. Either way the third
# positional must be present, since three are consumed before the option loop.
#
# Options:
#   --activate THEME       Set active theme
#   --docker-mode          Running inside Docker container (skips virtualhost, serve.php)
#   --clone-from=URL       Clone database and uploads from URL
#   --clone-key=KEY        Authentication key for clone source
#   --skip-db-validation   Skip default admin/settings validation
#   -q, --quiet            Suppress most output
#
# Environment (all optional; secrets travel here, never on argv):
#   JOINERY_DB_PASSWORD      the site's database password (see above)
#   JOINERY_ADMIN_EMAIL      the admin account's address
#   JOINERY_ADMIN_PASSWORD   the admin password the owner chose (else generated)
#   JOINERY_INSTALL_BUNDLE   plugin bundle, default personal; none skips it
#   JOINERY_DNS_CREDENTIAL   a DNS credential kept for the wizard's one publish
#                            of the mail records, as the JSON object
#                            utils/install_dns_credential.php takes:
#                            {"driver":"linode","credential":{"access_token":"..."}}
#   JOINERY_MAIL_API_KEY     a sending provider's API key: email is set up now
#                            (utils/install_mail_provider.php). The provider is
#                            detected from the key unless JOINERY_MAIL_PROVIDER
#                            names it; JOINERY_MAIL_FROM (default derived)
#                            alongside
#   JOINERY_BACKUP_BUCKET    a bucket that becomes the scheduled backup target
#   JOINERY_BACKUP_KEY_ID    (utils/install_backup_target.php), with
#   JOINERY_BACKUP_KEY       JOINERY_BACKUP_PROVIDER (b2 default, s3, linode)
#                            and JOINERY_BACKUP_REGION alongside

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

# The password may arrive in the environment instead of argv, and that is the
# preferred way in: argv is world-readable through ps for the life of the
# process, and this script runs for minutes. The positional is still accepted so
# the Dockerfile CMD and any existing caller keep working — inside a container
# the only accounts that can read its ps are already root there.
#
# The slot still has to be occupied when the env var is used, because the three
# leading positionals are consumed by `shift 3`; callers pass an empty string.
if [ -z "$PASSWORD" ] && [ -n "${JOINERY_DB_PASSWORD:-}" ]; then
    PASSWORD="$JOINERY_DB_PASSWORD"
fi

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

# The per-site secrets that live in config/, and the one definition of how each
# is minted - shared with _plugin_installers_start.sh so an install and a root
# moment produce identical keys.
# shellcheck source=_config_secrets.sh
. "${SCRIPT_DIR}/_config_secrets.sh"
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
# The page cache lives at {site root}/cache/static_pages — one level ABOVE
# public_html (StaticPageCache uses PathHelper::getSiteRoot()). There is no
# public_html/cache; creating one here would be a directory nothing reads.
mkdir -p "$SITE_ROOT/cache/static_pages"
mkdir -p "$SITE_ROOT/logs"
mkdir -p "$SITE_ROOT/static_files"
mkdir -p "$SITE_ROOT/backups"
# Durable runtime data (offloaded inbound-mail raw .eml lives here) — on par with
# uploads/ and backups/, NOT scratch like logs/. Must be backed by a persistent volume.
mkdir -p "$SITE_ROOT/storage"

# =============================================================================
# CONFIGURATION FILES
# =============================================================================

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
    # Record the deployment environment — single source of truth (spec deployment_environment_flag)
    if [ "$DOCKER_MODE" = true ]; then DEPLOY_ENV=docker; else DEPLOY_ENV=baremetal; fi
    # Generate a per-environment SecretBox key (32 random bytes, base64) for
    # secrets at rest. The generator is shared with the root moments, which mint
    # the same key on a site installed before it existed, so the two cannot
    # drift apart on format (specs/read_only_tree.md).
    SECRET_BOX_KEY=$(joinery_generate_secret_box_key)
    # PHP fills the template, not sed: var_export() writes a correct literal
    # for any password, and the values travel in the environment so neither
    # secret is in argv. Written under an umask so the file is never readable
    # by everyone, even for the instant before the chmod below.
    if ! (umask 027 && \
          JOINERY_CFG_SITENAME="$SITENAME" \
          JOINERY_CFG_DOMAIN="$DOMAIN" \
          JOINERY_CFG_DEPLOY_ENV="$DEPLOY_ENV" \
          JOINERY_CFG_PASSWORD="$PASSWORD" \
          JOINERY_CFG_SECRET_BOX_KEY="$SECRET_BOX_KEY" \
          php "${SCRIPT_DIR}/_write_site_config.php" "$GLOBALVARS_TEMPLATE" "$SITE_ROOT/config/Globalvars_site.php"); then
        log_error "Failed to write $SITE_ROOT/config/Globalvars_site.php"
        exit 1
    fi
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

    log "Updating site settings for new domain..."

    # The cloned site's host comes from webDir in config/Globalvars_site.php,
    # written with the new domain further up. Nothing in the database names the
    # host, so there is no settings row to repoint here.

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

    # Scrub sealed secrets. The clone carries ciphertext sealed to the SOURCE
    # site's secret_box_key, which this environment does not have, so every sealed
    # value is dead here. Null them at their declared locators (read from the
    # registry table that travelled inside the dump) so the copy lands clean —
    # "not configured" rather than a pile of broken features. Runs after the
    # config file exists (it needs DB credentials) and needs no plugin code.
    log "Scrubbing sealed secrets from the cloned database..."
    if [ -f "$SITE_ROOT/public_html/utils/scrub_sealed_secrets.php" ]; then
        if php "$SITE_ROOT/public_html/utils/scrub_sealed_secrets.php" 2>/dev/null; then
            log "Sealed secrets scrubbed"
        else
            log_error "Warning: Sealed-secret scrub failed (non-fatal); a later update_database reconcile will flag any dead secret"
        fi
    fi

elif [ "$DB_EXISTS" = false ]; then
    # ==========================================================================
    # NORMAL MODE: Load from SQL file
    # ==========================================================================

    # Create database (ignore error if already exists)
    log "Creating PostgreSQL database '$SITENAME'..."
    # An existing database is the ordinary idempotent case. Anything else is the
    # reason the schema load below is about to fail, so it is reported here
    # rather than discarded — a create that failed on authentication used to
    # surface two lines later as "Failed to load database schema", which names
    # the symptom and hides the cause.
    CREATEDB_ERR=$(createdb -T template0 "$SITENAME" -U postgres 2>&1) || {
        if printf '%s' "$CREATEDB_ERR" | grep -qi "already exists"; then
            log "Database '$SITENAME' already exists - reusing it."
        else
            log_error "Could not create database '$SITENAME': $CREATEDB_ERR"
            exit 1
        fi
    }

    # Load SQL restore
    log "Loading database schema..."
    if [ -f "$SQL_RESTORE" ]; then
        SCHEMA_ERR=$(gunzip -c "$SQL_RESTORE" | psql -U postgres -d "$SITENAME" -q 2>&1 >/dev/null) || {
            log_error "Failed to load database schema from $SQL_RESTORE"
            if [ -n "$SCHEMA_ERR" ]; then
                log_error "psql said: $(printf '%s' "$SCHEMA_ERR" | head -3 | tr '\n' ' ')"
            fi
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
        if [ "$ADMIN_PASSWORD_SUPPLIED" = true ]; then
            # The owner chose it on the deploy form and nothing wrote it down,
            # so there is nothing to make them replace at first login. A
            # generated one is printed to a file and IS replaced.
            RESET_ARGS="$RESET_ARGS --chosen"
        fi
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
                } > "$CRED_FILE"
                # The first-task paragraph is appended by the OPTIONAL SERVICES
                # section below, which knows whether email was set up here.
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
# OPTIONAL SERVICES
# =============================================================================
#
# What the deployer already had in hand when they started the install, done
# now so the setup wizard finds it done: a DNS credential kept for the one
# publish of the mail records, a sending key that configures email (the
# wizard's own ceremony, run by utils/install_mail_provider.php), and a bucket
# that becomes the backup target (utils/install_backup_target.php). Each is
# independent, none is a condition of the install, and a failure is recorded
# and left for the wizard to ask about. Fresh installs only, after the bundle,
# because the mail half needs the mailbox plugin the bundle installs.
#
# The outcomes land in config/install_services.txt, one key=value line each
# (mail=, backup=, dns_credential=; values start done:, failed: or skipped:),
# which install.sh and the StackScript read for their closing summaries. The
# tools print their secrets nowhere, so their output is safe in the log.
SERVICES_FILE="${SITE_ROOT}/config/install_services.txt"
MAIL_OUTCOME=""
BACKUP_OUTCOME=""
DNS_CRED_OUTCOME=""

# The first line of a tool's output is its verdict; reason= says why on a failure.
tool_reason() {
    printf '%s\n' "$1" | sed -n 's/^reason=//p' | head -1
}

if [ -z "$CLONE_FROM" ] && [ "$DB_EXISTS" = false ]; then
    SITE_UTILS="${SITE_ROOT}/public_html/utils"

    if [ -n "${JOINERY_DNS_CREDENTIAL:-}" ]; then
        DNS_CRED_TOOL="$SITE_UTILS/install_dns_credential.php"
        if [ ! -f "$DNS_CRED_TOOL" ]; then
            DNS_CRED_OUTCOME="failed: install_dns_credential.php is not in this release"
        elif printf '%s' "$JOINERY_DNS_CREDENTIAL" | php "$DNS_CRED_TOOL" >/dev/null 2>&1; then
            DNS_CRED_OUTCOME="done: kept for the one publish of the mail records"
            log "DNS credential kept for the mail records (used once, then deleted)"
        else
            DNS_CRED_OUTCOME="failed: the credential could not be kept"
            log_error "Warning: the DNS credential could not be kept - the wizard will ask for it when it adds the mail records."
        fi
    fi
    unset JOINERY_DNS_CREDENTIAL

    if [ -n "${JOINERY_MAIL_API_KEY:-}" ]; then
        MAIL_TOOL="$SITE_UTILS/install_mail_provider.php"
        log "Setting up email with the supplied sending key${JOINERY_MAIL_PROVIDER:+ ($JOINERY_MAIL_PROVIDER)}..."
        if [ ! -f "$MAIL_TOOL" ]; then
            MAIL_OUTCOME="failed: install_mail_provider.php is not in this release"
        else
            # The key is already in this environment; the tool reads it there.
            MAIL_OUT=$(php "$MAIL_TOOL" 2>&1) && MAIL_RC=0 || MAIL_RC=$?
            printf '%s\n' "$MAIL_OUT"
            if [ "$MAIL_RC" -eq 0 ]; then
                MAIL_FROM=$(printf '%s\n' "$MAIL_OUT" | sed -n 's/^from=//p' | head -1)
                MAIL_DNS=$(printf '%s\n' "$MAIL_OUT" | sed -n 's/^dns=//p' | head -1)
                MAIL_STATE=$(printf '%s\n' "$MAIL_OUT" | sed -n 's/^state=//p' | head -1)
                MAIL_OUTCOME="done: sending as ${MAIL_FROM}; DNS ${MAIL_DNS}; provider reports the domain ${MAIL_STATE}"
                log "Email set up: sending as $MAIL_FROM"
            else
                MAIL_OUTCOME="failed: $(tool_reason "$MAIL_OUT")"
                log_error "Warning: email was not set up - ${MAIL_OUTCOME#failed: }"
            fi
        fi
    fi
    unset JOINERY_MAIL_API_KEY

    if [ -n "${JOINERY_BACKUP_BUCKET:-}" ]; then
        BACKUP_TOOL="$SITE_UTILS/install_backup_target.php"
        log "Pointing backups at the supplied bucket..."
        if [ ! -f "$BACKUP_TOOL" ]; then
            BACKUP_OUTCOME="failed: install_backup_target.php is not in this release"
        else
            BACKUP_OUT=$(php "$BACKUP_TOOL" 2>&1) && BACKUP_RC=0 || BACKUP_RC=$?
            printf '%s\n' "$BACKUP_OUT"
            if [ "$BACKUP_RC" -eq 0 ]; then
                BACKUP_OUTCOME="done: ${JOINERY_BACKUP_PROVIDER:-b2} bucket ${JOINERY_BACKUP_BUCKET} is the backup target"
                log "Backups point at $JOINERY_BACKUP_BUCKET"
            else
                BACKUP_OUTCOME="failed: $(tool_reason "$BACKUP_OUT")"
                log_error "Warning: the backup bucket was not set - ${BACKUP_OUTCOME#failed: }"
            fi
        fi
    fi
    unset JOINERY_BACKUP_KEY

    if [ -n "$MAIL_OUTCOME$BACKUP_OUTCOME$DNS_CRED_OUTCOME" ]; then
        : > "$SERVICES_FILE"
        chmod 600 "$SERVICES_FILE"
        chown root:root "$SERVICES_FILE" 2>/dev/null || true
        {
            [ -n "$DNS_CRED_OUTCOME" ] && printf 'dns_credential=%s\n' "$DNS_CRED_OUTCOME"
            [ -n "$MAIL_OUTCOME" ]     && printf 'mail=%s\n' "$MAIL_OUTCOME"
            [ -n "$BACKUP_OUTCOME" ]   && printf 'backup=%s\n' "$BACKUP_OUTCOME"
            true
        } >> "$SERVICES_FILE"
    fi

    # The credentials file's first task, written now that it is known whether
    # email is already set up. Only a generated password has a file.
    if [ -n "${CRED_FILE:-}" ] && [ -f "$CRED_FILE" ]; then
        case "$MAIL_OUTCOME" in
            done:*)
                {
                    printf 'Email is set up: %s\n' "${MAIL_OUTCOME#done: }"
                    printf 'The setup wizard will ask you to confirm a test message arrived.\n'
                } >> "$CRED_FILE"
                ;;
            *)
                {
                    printf 'FIRST TASK: set up email at\n'
                    printf '  http://%s/admin/admin_settings_email\n\n' "$DOMAIN"
                    printf 'A new site cannot send mail until you name a provider, and\n'
                    printf 'password reset is the only way back into this account once the\n'
                    printf 'password above stops working. Most hosts block outbound port 25,\n'
                    printf 'so a mail server on this machine is generally not an option.\n'
                } >> "$CRED_FILE"
                ;;
        esac
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
    chmod -R 775 "$SITE_ROOT/cache"
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

# One writer per artifact: on bare metal this script owns the cron file; in a
# container the start command in Dockerfile.template owns it, because
# /etc/cron.d does not survive a container rebuild and this script only runs
# on first boot. Writing it here as well meant two files running the same
# task runner, colliding on every shared tick.
if [ "$DOCKER_MODE" = false ]; then
    # Write to /etc/cron.d/ — more durable than user crontab (survives script
    # re-runs). /etc/cron.d/ format requires the username in the line; file
    # must not be world-writable.
    #
    # Every minute, not every five: the tick interval is the floor on latency
    # for every every_run task, and inbound mail is the one users feel — a
    # relay-fronted deployment cannot see a message until the next
    # PullRelaySpool. A full pass costs about a second, and the runner holds a
    # per-task advisory lock, so a slow task is skipped rather than run
    # concurrently.
    CRON_FILE="/etc/cron.d/joinery-${SITENAME}"
    CRON_LINE="* * * * * www-data php ${SITE_ROOT}/public_html/utils/process_scheduled_tasks.php >> ${SITE_ROOT}/logs/cron_scheduled_tasks.log 2>&1"
    printf '%s\n' "$CRON_LINE" > "$CRON_FILE" && chmod 644 "$CRON_FILE" && {
        log "Scheduled tasks cron entry installed: $CRON_FILE"
    } || {
        log_error "Warning: Could not write $CRON_FILE (non-fatal)"
    }
else
    log "Docker mode: cron entry is written by the container start command"
    # In Docker, cron isn't started automatically — ensure it's running.
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
