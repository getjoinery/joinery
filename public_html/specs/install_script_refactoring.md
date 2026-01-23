# Install Script Refactoring Specification

## Overview

Consolidate installation scripts so that:
1. `install.sh` is the **only user-facing script** for all installation operations
2. `_site_init.sh` (renamed from `new_account.sh`) is an **internal script** containing shared setup logic
3. Users never need to know about or call the internal script directly

## Current State Analysis

### install.sh (v1.2) - Current Responsibilities

| Subcommand | Purpose | One-time? |
|------------|---------|-----------|
| `docker` | Install Docker on server | Yes |
| `server` | Set up bare-metal server (Apache, PHP, PostgreSQL, security) | Yes |
| `site` | Create a new Joinery site | No (per-site) |
| `list` | List existing sites | No (utility) |

**Site creation in Docker mode (`do_site_docker`):**
- Verifies archive structure (needs `public_html/`, `config/`)
- Checks port availability
- Copies `public_html/` and `config/` to build context
- Builds Docker image using `Dockerfile.template`
- Runs container with volume mounts
- Container's CMD calls `new_account.sh` on first run

**Site creation in bare-metal mode (`do_site_baremetal`):**
- Sets password in `default_Globalvars_site.php`
- Calls `new_account.sh`
- Does NOT copy application code (gap!)

### new_account.sh (v2.14) - Current Responsibilities

1. Creates directory structure (`/var/www/html/{site}/`)
2. Copies config templates only (`Globalvars_site.php`, `serve.php`)
3. Creates PostgreSQL database
4. Loads database restore file (`joinery-install.sql.gz`)
5. Runs Composer install
6. Optionally activates a theme
7. Creates Apache virtualhost
8. Reloads Apache

**Critical Gap:** Does NOT copy application code (`public_html/` contents)

### Current Multi-Site Workflow

**Docker:**
```bash
# First site
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh docker           # One-time
./install.sh site site1 Pass1! site1.com 8080

# Second site - REQUIRES keeping/re-extracting archive
./install.sh site site2 Pass2! site2.com 8081
```

**Bare-metal:**
```bash
# First site
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh server           # One-time
./install.sh site site1 Pass1! site1.com

# Second site - BROKEN (no code deployment)
# Would need manual: cp -r public_html /var/www/html/site2/
./install.sh site site2 Pass2! site2.com  # Fails - no code
```

## Proposed Architecture

### Script Responsibilities

| Script | Purpose | User-facing? |
|--------|---------|--------------|
| `install.sh` | All installation operations | **Yes** |
| `_site_init.sh` | Shared site initialization logic | No (internal) |

### install.sh - User-Facing Interface

```bash
./install.sh docker                                 # Install Docker (one-time)
./install.sh server                                 # Set up bare-metal server (one-time)
./install.sh site SITENAME PASSWORD DOMAIN [PORT]   # Create a site
./install.sh list                                   # List existing sites
```

**Environment auto-detection for `site` subcommand:**
- PORT provided → Docker mode
- No PORT → Bare-metal mode

### _site_init.sh - Internal Shared Logic

Called internally by:
- `install.sh` (bare-metal site creation)
- Dockerfile CMD (container first-run initialization)

**Contains the shared setup logic:**
1. Create directories (config/, logs/, uploads/)
2. Copy and configure `Globalvars_site.php`
3. Create PostgreSQL database
4. Load SQL restore file
5. Run Composer install
6. Activate theme (if specified)
7. Create Apache virtualhost (bare-metal only)

The underscore prefix signals this is an internal implementation detail.

## Detailed Design

### install.sh Parameters

```bash
./install.sh SUBCOMMAND [ARGUMENTS]

Subcommands:
  docker
      Install Docker engine on the server (one-time setup)

  server
      Set up bare-metal server (Apache, PHP, PostgreSQL, security)

  site SITENAME PASSWORD DOMAIN [PORT]
      Create a new Joinery site
      - With PORT: Docker mode (creates container)
      - Without PORT: Bare-metal mode

  list
      List all existing Joinery sites

Options (for site subcommand):
  --activate THEME    Set active theme after installation
  --with-test-site    Create companion test site (bare-metal only)
  -y, --yes           Auto-accept prompts (non-interactive)
  -q, --quiet         Suppress most output
```

### _site_init.sh Parameters

```bash
# Internal use only - not documented for users
./_site_init.sh SITENAME PASSWORD DOMAIN [OPTIONS]

# Parameter order matches install.sh for consistency

Options:
  --activate THEME    Set active theme
  --docker-mode       Running inside Docker container (skips virtualhost, serve.php)
  -q, --quiet         Suppress most output
```

### Docker Mode Flow

**`install.sh site mysite Pass1! example.com 8080`:**
```
1. Verify Docker is available and running
2. Locate archive root (source files)
3. Check port availability:
   - If port in use, display error with suggested alternatives
   - Check both web port and DB port (web + 1000)
4. Prepare build context:
   - Copy public_html/ from archive
   - Copy maintenance_scripts/ from archive
   - Copy Dockerfile.template
5. Build Docker image (joinery-mysite)
6. Run container with volume mounts
7. Container initializes via Dockerfile CMD:
   - Starts PostgreSQL
   - Calls _site_init.sh for first-run setup
   - Starts Apache
8. Wait for site to respond (with timeout)
9. Clean up build context
10. Display summary with access URLs
```

**Dockerfile CMD calls `_site_init.sh` internally:**
```bash
# Inside container on first run
./_site_init.sh "${SITENAME}" "${POSTGRES_PASSWORD}" "${DOMAIN_NAME}" --docker-mode
```

### Bare-Metal Mode Flow

**`install.sh site mysite Pass1! example.com`:**
```
1. Verify prerequisites (Apache, PHP, PostgreSQL running)
2. Locate archive root (source files)
3. Check site doesn't already exist in /var/www/html/
4. Deploy application code:
   - rsync public_html/ to /var/www/html/{site}/public_html/
   - Copy maintenance_scripts/
5. Call _site_init.sh for shared setup:
   - Create directories
   - Configure Globalvars_site.php
   - Create database
   - Load SQL
   - Composer install
   - Create virtualhost
6. Enable site and reload Apache
7. (Optional) Create test site if --with-test-site
8. Verify site is responding
9. Display summary
```

### List Command Implementation

**`install.sh list`:**
```bash
do_list() {
    echo "=== Joinery Sites ==="
    echo ""

    # Docker sites (running containers)
    if command -v docker &> /dev/null; then
        echo "Docker containers:"
        docker ps --filter "name=joinery-" --format "  {{.Names}}\t{{.Status}}\tPort: {{.Ports}}" 2>/dev/null || echo "  (none)"

        # Also show stopped containers
        local stopped=$(docker ps -a --filter "name=joinery-" --filter "status=exited" --format "  {{.Names}}\t(stopped)" 2>/dev/null)
        if [ -n "$stopped" ]; then
            echo "$stopped"
        fi
        echo ""
    fi

    # Bare-metal sites (directories with config)
    echo "Bare-metal sites:"
    local found=false
    for dir in /var/www/html/*/; do
        if [ -f "${dir}config/Globalvars_site.php" ]; then
            local sitename=$(basename "$dir")
            # Skip test sites in listing (show as suffix)
            if [[ "$sitename" != *"_test" ]]; then
                local status="active"
                if [ -f "/etc/apache2/sites-enabled/${sitename}.conf" ]; then
                    status="enabled"
                fi
                # Check for companion test site
                local test_suffix=""
                if [ -d "/var/www/html/${sitename}_test" ]; then
                    test_suffix=" (+test site)"
                fi
                echo "  ${sitename}\t${status}${test_suffix}"
                found=true
            fi
        fi
    done
    if [ "$found" = false ]; then
        echo "  (none)"
    fi
}
```

### Code Deployment (install.sh)

**New function in install.sh for bare-metal:**

```bash
deploy_application_code() {
    local site_name="$1"
    local archive_root="$2"
    local site_root="/var/www/html/$site_name"

    log "Deploying application code..."

    # Create site directory
    mkdir -p "$site_root"

    # Copy public_html (excluding runtime directories)
    rsync -av --exclude='.git' \
              --exclude='uploads' \
              --exclude='cache' \
              --exclude='logs' \
              --exclude='.playwright-mcp' \
              "$archive_root/public_html/" \
              "$site_root/public_html/"

    # Copy maintenance_scripts
    rsync -av "$archive_root/maintenance_scripts/" \
              "$site_root/maintenance_scripts/"

    log "Application code deployed."
}
```

### Shared Setup Logic (_site_init.sh)

```bash
#!/bin/bash
# _site_init.sh - Internal site initialization
# Called by install.sh and Dockerfile CMD
# VERSION: 1.0
#
# Do not call directly - use install.sh site instead

set -e

# Get script directory for finding template files
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

SITENAME="$1"
PASSWORD="$2"
DOMAIN="$3"
shift 3

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
mkdir -p "$SITE_ROOT/public_html/uploads"
mkdir -p "$SITE_ROOT/public_html/cache"
mkdir -p "$SITE_ROOT/logs"

# =============================================================================
# CONFIGURATION FILES
# =============================================================================

log "Configuring site..."

# Escape password for sed (handles special characters like /, &, \)
ESCAPED_PASSWORD=$(sed_escape "$PASSWORD")

# Copy and configure Globalvars_site.php
cp "$GLOBALVARS_TEMPLATE" "$SITE_ROOT/config/Globalvars_site.php"
sed -i "s/{{PASSWORD}}/${ESCAPED_PASSWORD}/g" "$SITE_ROOT/config/Globalvars_site.php"
sed -i "s/{{SITENAME}}/${SITENAME}/g" "$SITE_ROOT/config/Globalvars_site.php"

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

# Create database (ignore error if already exists)
PGPASSWORD="$PASSWORD" createdb -U postgres "$SITENAME" 2>/dev/null || true

# Load SQL restore
log "Loading database schema..."
gunzip -c "$SQL_RESTORE" | PGPASSWORD="$PASSWORD" psql -U postgres "$SITENAME" -q

# =============================================================================
# COMPOSER INSTALL
# =============================================================================

log "Installing PHP dependencies..."

cd "$SITE_ROOT/public_html"

# Find composer (check common locations)
COMPOSER_CMD=""
if command -v composer &> /dev/null; then
    COMPOSER_CMD="composer"
elif [ -f "/usr/local/bin/composer" ]; then
    COMPOSER_CMD="/usr/local/bin/composer"
elif [ -f "$HOME/composer.phar" ]; then
    COMPOSER_CMD="php $HOME/composer.phar"
fi

if [ -n "$COMPOSER_CMD" ]; then
    $COMPOSER_CMD install --no-dev --optimize-autoloader --quiet
else
    log_error "Composer not found - skipping dependency installation"
    log_error "Run 'composer install' manually in $SITE_ROOT/public_html"
fi

# =============================================================================
# THEME ACTIVATION
# =============================================================================

if [ -n "$ACTIVATE_THEME" ]; then
    log "Activating theme: $ACTIVATE_THEME"

    # Check if theme exists
    if [ -d "$SITE_ROOT/public_html/theme/$ACTIVATE_THEME" ]; then
        # Update database setting
        PGPASSWORD="$PASSWORD" psql -U postgres "$SITENAME" -q -c \
            "UPDATE stg_settings SET stg_value = '$ACTIVATE_THEME' WHERE stg_name = 'active_theme';"

        # Insert if not exists
        PGPASSWORD="$PASSWORD" psql -U postgres "$SITENAME" -q -c \
            "INSERT INTO stg_settings (stg_name, stg_value)
             SELECT 'active_theme', '$ACTIVATE_THEME'
             WHERE NOT EXISTS (SELECT 1 FROM stg_settings WHERE stg_name = 'active_theme');"
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
        cp "$VIRTUALHOST_TEMPLATE" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{DOMAIN_NAME}}/${DOMAIN}/g" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{SITE_NAME}}/${SITENAME}/g" "/etc/apache2/sites-available/${SITENAME}.conf"
        sed -i "s/{{SERVER_IP}}/*/g" "/etc/apache2/sites-available/${SITENAME}.conf"

        a2ensite "${SITENAME}.conf" > /dev/null
        systemctl reload apache2
    else
        log_error "Virtualhost template not found: $VIRTUALHOST_TEMPLATE"
    fi
fi

# =============================================================================
# PERMISSIONS
# =============================================================================

log "Setting permissions..."

chown -R www-data:www-data "$SITE_ROOT"
chmod -R 755 "$SITE_ROOT/public_html"
chmod -R 775 "$SITE_ROOT/public_html/uploads"
chmod -R 775 "$SITE_ROOT/public_html/cache"
chmod -R 775 "$SITE_ROOT/logs"

# =============================================================================
# COMPLETE
# =============================================================================

log "Site initialization complete."
```

### Test Site Implementation

```bash
# In install.sh, called after main site creation

create_test_site() {
    local main_site="$1"
    local password="$2"
    local domain="$3"

    local test_site="${main_site}_test"
    local test_domain="test.${domain}"

    log "Creating test site: $test_site"

    # Deploy code (copy from main site to save time)
    local site_root="/var/www/html/$test_site"
    mkdir -p "$site_root"

    rsync -av --exclude='uploads/*' \
              --exclude='cache/*' \
              --exclude='logs/*' \
              "/var/www/html/$main_site/public_html/" \
              "$site_root/public_html/"

    rsync -av "/var/www/html/$main_site/maintenance_scripts/" \
              "$site_root/maintenance_scripts/"

    # Run initialization (creates separate database)
    "$SCRIPT_DIR/_site_init.sh" "$test_site" "$password" "$test_domain"

    log "Test site created: $test_site"
}
```

### Quiet and Non-Interactive Mode Implementation

```bash
# At top of install.sh, after argument parsing

# Global flags
QUIET=false
AUTO_YES=false

# In argument parsing for 'site' subcommand
while [[ $# -gt 0 ]]; do
    case $1 in
        -q|--quiet)
            QUIET=true
            ;;
        -y|--yes)
            AUTO_YES=true
            ;;
        # ... other options
    esac
    shift
done

# Helper functions
log() {
    if [ "$QUIET" = false ]; then
        echo "$1"
    fi
}

confirm() {
    local prompt="$1"
    if [ "$AUTO_YES" = true ]; then
        return 0
    fi
    read -p "$prompt [y/N] " response
    [[ "$response" =~ ^[Yy]$ ]]
}

# Usage example
if [ -d "/var/www/html/$SITENAME" ]; then
    if ! confirm "Site $SITENAME already exists. Overwrite?"; then
        echo "Aborted."
        exit 1
    fi
fi
```

## Dockerfile.template Updates

```dockerfile
# Joinery Docker Template
# VERSION 2.0 - Updated to use _site_init.sh
#
# Usage:
#   docker build \
#     --build-arg SITENAME=clientsite \
#     --build-arg POSTGRES_PASSWORD=secure_password \
#     --build-arg DOMAIN_NAME=client.example.com \
#     -t joinery-clientsite .

FROM ubuntu:24.04

# Build arguments
ARG SITENAME=dockertest
ARG POSTGRES_PASSWORD
ARG DOMAIN_NAME=localhost

# Environment variables for runtime
ENV DEBIAN_FRONTEND=noninteractive
ENV SITENAME=${SITENAME}
ENV POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
ENV DOMAIN_NAME=${DOMAIN_NAME}

# Copy source files
COPY ${SITENAME}/ /var/www/html/${SITENAME}/
COPY maintenance_scripts/ /var/www/html/${SITENAME}/maintenance_scripts/

# Run server setup
RUN chmod +x /var/www/html/${SITENAME}/maintenance_scripts/install_tools/*.sh && \
    cd /var/www/html/${SITENAME}/maintenance_scripts/install_tools && \
    ./install.sh server

# Create VirtualHost configuration during build
RUN cp /var/www/html/${SITENAME}/maintenance_scripts/install_tools/default_virtualhost.conf \
       /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SERVER_IP}}/*/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SITE_NAME}}/${SITENAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    mkdir -p /var/www/html/${SITENAME}_test/public_html && \
    mkdir -p /var/www/html/${SITENAME}_test/logs && \
    a2dissite 000-default.conf 2>/dev/null || true && \
    a2ensite ${SITENAME}.conf

EXPOSE 80 5432

# Start services
# First run: _site_init.sh creates database and configures site
CMD echo "Container starting: SITENAME=${SITENAME}, DOMAIN=${DOMAIN_NAME}" && \
    if [ -z "${SITENAME}" ]; then echo "FATAL: SITENAME is empty"; exit 1; fi && \
    if [ -z "${POSTGRES_PASSWORD}" ]; then echo "FATAL: POSTGRES_PASSWORD is empty"; exit 1; fi && \
    service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    PG_CONF="/etc/postgresql/16/main/pg_hba.conf" && \
    if [ ! -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]; then \
        sed -i 's/local   all             postgres                                md5/local   all             postgres                                trust/' "$PG_CONF" && \
        service postgresql reload && \
        su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres && \
        sed -i 's/local   all             postgres                                trust/local   all             postgres                                md5/' "$PG_CONF" && \
        service postgresql reload; \
    fi && \
    ([ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ] || \
        (cd "/var/www/html/${SITENAME}/maintenance_scripts/install_tools" && \
        ./_site_init.sh "${SITENAME}" "${POSTGRES_PASSWORD}" "${DOMAIN_NAME}" --docker-mode)) && \
    php "/var/www/html/${SITENAME}/public_html/utils/update_database.php" 2>/dev/null || true && \
    apache2ctl -D FOREGROUND
```

## Required Template Files

The following files must exist in `maintenance_scripts/install_tools/`:

| File | Purpose | Status |
|------|---------|--------|
| `default_Globalvars_site.php` | Config template with `{{PASSWORD}}`, `{{SITENAME}}` placeholders | Exists |
| `default_serve.php` | Front controller template | Exists |
| `default_virtualhost.conf` | Apache vhost with `{{DOMAIN_NAME}}`, `{{SITE_NAME}}`, `{{SERVER_IP}}` | Exists |
| `joinery-install.sql.gz` | Database schema and initial data | Exists |
| `Dockerfile.template` | Docker build template | Exists |

## Migration Path

### Phase 1: Create _site_init.sh
1. Create `_site_init.sh` with shared logic from `new_account.sh`
2. Include SCRIPT_DIR detection
3. Add `--docker-mode` flag
4. Add password escaping for special characters
5. Add `-q/--quiet` support
6. Test in isolation on bare-metal

### Phase 2: Update install.sh
1. Add `deploy_application_code()` function
2. Add `create_test_site()` function
3. Update `do_site_baremetal()` to:
   - Deploy code first
   - Call `_site_init.sh` instead of `new_account.sh`
4. Update `do_site_docker()` to prepare for new Dockerfile
5. Update `do_list()` with Docker and bare-metal detection
6. Add `-y/--yes` and `-q/--quiet` flag handling
7. Update help text to version 2.0

### Phase 3: Update Dockerfile.template
1. Change CMD to call `_site_init.sh` instead of `new_account.sh`
2. Pass `--docker-mode` flag
3. Update version comment
4. Test container builds

### Phase 4: Remove new_account.sh
1. Delete `new_account.sh`
2. Verify no references remain in codebase
3. Search for any documentation references

### Phase 5: Update Documentation
1. Update `INSTALL_README.md`
2. Update `docker_install.md`
3. Update any other docs referencing `new_account.sh`

## Files Affected

| File | Changes |
|------|---------|
| `install.sh` | Add code deployment, list implementation, call _site_init.sh, add flags, update help |
| `_site_init.sh` | **NEW** - extracted shared logic with proper escaping and flags |
| `new_account.sh` | **DELETE** - replaced by _site_init.sh |
| `Dockerfile.template` | Update CMD to call _site_init.sh with --docker-mode |
| `utils/latest_release.php` | **NEW** - redirect endpoint to latest release archive |
| `INSTALL_README.md` | Update workflows, remove new_account.sh references |
| `docker_install.md` | Update to reflect single-script interface |

## Distribution Server

### Archive Location

Archives are published to the distribution server via `publish_upgrade.php` and stored at:
```
https://{DISTRIBUTION_SERVER}/static_files/joinery-{MAJOR}-{MINOR}.tar.gz
```

**Default distribution server:** `joinerytest.site`

**Example URLs:**
- `https://joinerytest.site/static_files/joinery-2-31.tar.gz`
- `https://joinerytest.site/static_files/joinery-2-32.tar.gz`

### Latest Version Endpoint

To support "install latest" workflows, create a redirect endpoint at `/utils/latest_release.php`:

```php
<?php
// /utils/latest_release.php
// Redirects to the latest Joinery release archive
//
// Usage: curl -sL https://joinerytest.site/utils/latest_release.php | tar xz

// PathHelper, Globalvars, SessionControl are pre-loaded via serve.php
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

// Get the most recent release
$latest = new MultiUpgrade([], ['upgrade_id' => 'DESC'], 1);
$latest->load();

if ($latest->count() > 0) {
    $upgrade = $latest->get(0);
    $filename = $upgrade->get('upg_name');

    // Redirect to the actual file
    header('Location: /static_files/' . $filename);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'No releases found';
```

This endpoint:
- Queries the database for the most recent published version
- Redirects (302) to the actual archive file in `/static_files/`
- Returns 404 if no releases exist
- No symlinks required - always serves the newest version automatically

### One-Liner Install Commands

These commands download, extract, and run the installation in one step. Add to documentation.

**Docker - Latest Version:**
```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  ./install.sh docker && \
  ./install.sh site mysite 'MySecurePass123!' example.com 8080
```

**Docker - Specific Version:**
```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/static_files/joinery-2-31.tar.gz | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  ./install.sh docker && \
  ./install.sh site mysite 'MySecurePass123!' example.com 8080
```

**Bare-Metal - Latest Version:**
```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  ./install.sh server && \
  ./install.sh site mysite 'MySecurePass123!' example.com
```

**Bare-Metal - Specific Version:**
```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/static_files/joinery-2-31.tar.gz | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  ./install.sh server && \
  ./install.sh site mysite 'MySecurePass123!' example.com
```

### Step-by-Step Alternative

For users who prefer explicit steps:

```bash
# 1. Create directory and download (latest version)
mkdir -p /tmp/joinery && cd /tmp/joinery
curl -LO https://joinerytest.site/utils/latest_release
mv latest_release joinery-latest.tar.gz

# 2. Extract
tar xzf joinery-latest.tar.gz

# 3. Run installation
cd maintenance_scripts/install_tools
./install.sh server   # or: ./install.sh docker
./install.sh site mysite 'MySecurePass123!' example.com
```

Or with a specific version:

```bash
# 1. Create directory and download (specific version)
mkdir -p /tmp/joinery && cd /tmp/joinery
curl -LO https://joinerytest.site/static_files/joinery-2-31.tar.gz

# 2. Extract
tar xzf joinery-2-31.tar.gz

# 3. Run installation
cd maintenance_scripts/install_tools
./install.sh server   # or: ./install.sh docker
./install.sh site mysite 'MySecurePass123!' example.com
```

## Documentation Updates

### INSTALL_README.md Changes

**Remove all references to new_account.sh.** Update to show install.sh as the only interface:

```markdown
# Joinery Installation Guide

## Quick Start

### Docker Installation
\`\`\`bash
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh docker
./install.sh site mysite MySecurePass123! example.com 8080
\`\`\`

### Bare-Metal Installation
\`\`\`bash
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh server
./install.sh site mysite MySecurePass123! example.com
\`\`\`

## Commands

### install.sh docker
Install Docker engine (one-time setup).
\`\`\`bash
./install.sh docker
\`\`\`

### install.sh server
One-time server setup for bare-metal installations.
\`\`\`bash
./install.sh server
\`\`\`

### install.sh site
Create a new Joinery site.
\`\`\`bash
# Bare-metal (no port)
./install.sh site mysite MyPass123! example.com

# Docker (with port)
./install.sh site mysite MyPass123! example.com 8080

# With options
./install.sh site mysite MyPass123! example.com --activate falcon
./install.sh site mysite MyPass123! example.com --with-test-site
./install.sh site mysite MyPass123! example.com -y -q  # Non-interactive, quiet
\`\`\`

### install.sh list
List all existing Joinery sites.
\`\`\`bash
./install.sh list
\`\`\`

## Adding Additional Sites

### Docker
\`\`\`bash
./install.sh site site2 Pass2! site2.example.com 8081
./install.sh site site3 Pass3! site3.example.com 8082
\`\`\`

### Bare-Metal
\`\`\`bash
./install.sh site site2 Pass2! site2.example.com
./install.sh site site3 Pass3! site3.example.com
\`\`\`

## Options

| Option | Description |
|--------|-------------|
| `--activate THEME` | Activate specified theme after installation |
| `--with-test-site` | Create companion test site (bare-metal only) |
| `-y, --yes` | Non-interactive mode, accept all prompts |
| `-q, --quiet` | Suppress most output |
```

### docker_install.md Changes

```markdown
# Docker Installation Guide

## Overview
All Docker operations are handled through `install.sh`. You never need to interact with containers directly for site setup.

## First-Time Setup
\`\`\`bash
# Extract archive
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Install Docker (one-time)
./install.sh docker

# Create first site
./install.sh site mysite MySecurePass123! example.com 8080
\`\`\`

## Adding More Sites
\`\`\`bash
./install.sh site site2 Pass2! site2.example.com 8081
\`\`\`

## Port Mapping
- Web port: specified port (e.g., 8080)
- Database port: web port + 1000 (e.g., 9080)

## Listing Sites
\`\`\`bash
./install.sh list
\`\`\`

Output shows both Docker containers and their status:
\`\`\`
=== Joinery Sites ===

Docker containers:
  joinery-mysite    Up 2 hours    Port: 0.0.0.0:8080->80/tcp
  joinery-site2     Up 1 hour     Port: 0.0.0.0:8081->80/tcp

Bare-metal sites:
  (none)
\`\`\`

## Container Management
\`\`\`bash
# Stop a container
docker stop joinery-mysite

# Start a stopped container
docker start joinery-mysite

# View logs
docker logs joinery-mysite

# Remove a container (data in volumes preserved)
docker rm joinery-mysite
\`\`\`
```

### Help Text (install.sh)

```
Joinery Installation Script v2.0

Usage: ./install.sh COMMAND [OPTIONS]

Commands:
  docker
      Install Docker engine on the server
      Run once before creating Docker-based sites

  server
      Set up bare-metal server with Apache, PHP, and PostgreSQL
      Run once before creating bare-metal sites

  site SITENAME PASSWORD DOMAIN [PORT]
      Create a new Joinery site
      - Without PORT: bare-metal installation
      - With PORT: Docker container

  list
      List all existing Joinery sites (Docker and bare-metal)

Options for 'site' command:
  --activate THEME    Activate specified theme after installation
  --with-test-site    Also create a test site (bare-metal only)
  -y, --yes           Non-interactive mode, accept all prompts
  -q, --quiet         Suppress most output

Examples:
  # Install Docker (once)
  ./install.sh docker

  # Create Docker site
  ./install.sh site production SecurePass! prod.example.com 8080

  # Create another Docker site
  ./install.sh site staging StagePass! stage.example.com 8081

  # Set up bare-metal server (once)
  ./install.sh server

  # Create bare-metal site
  ./install.sh site client1 Pass1! client1.example.com

  # Create site with test site
  ./install.sh site client2 Pass2! client2.example.com --with-test-site

  # List all sites
  ./install.sh list
```

## Example Workflows After Refactoring

### Docker Multi-Site

```bash
# Extract archive
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Install Docker (one time)
./install.sh docker

# Create sites
./install.sh site site1 Pass1! site1.com 8080
./install.sh site site2 Pass2! site2.com 8081
./install.sh site site3 Pass3! site3.com 8082

# List all sites
./install.sh list
```

### Bare-Metal Multi-Site

```bash
# Extract archive
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Set up server (one time)
./install.sh server

# Create sites
./install.sh site site1 Pass1! site1.com
./install.sh site site2 Pass2! site2.com --with-test-site
./install.sh site site3 Pass3! site3.com --activate falcon

# List all sites
./install.sh list
```

## Design Decisions

### 1. Single User-Facing Script

**Decision:** `install.sh` is the only script users interact with.

**Rationale:**
- Simpler user experience - one script to learn
- Clear documentation - no confusion about which script to call
- Internal implementation details are hidden

### 2. Internal Script Naming

**Decision:** Use underscore prefix (`_site_init.sh`) for internal scripts.

**Rationale:**
- Common convention signaling "private/internal"
- Sorts to top/bottom in directory listings (visually distinct)
- Clear that it shouldn't be called directly

### 3. Shared Logic Separation

**Decision:** Keep shared setup logic in a separate internal script rather than duplicating.

**Rationale:**
- DRY principle - database setup, config copying, composer install are identical for Docker and bare-metal
- Single place to fix bugs or add features
- Dockerfile CMD and install.sh both call the same code

### 4. Environment Detection

**Decision:** Port presence determines Docker vs bare-metal mode.

**Rationale:**
- Simple and unambiguous
- No need for complex Docker detection
- Matches user mental model: "Docker needs a port"

### 5. Parameter Order Consistency

**Decision:** Both scripts use SITENAME PASSWORD DOMAIN order.

**Rationale:**
- Reduces errors when passing parameters between scripts
- Easier to remember one order
- PASSWORD before DOMAIN because password is more "secret" (less likely to be visible in process list when domain is last)

### 6. Password Special Character Handling

**Decision:** Use sed escape function for password substitution.

**Rationale:**
- Passwords commonly contain `/`, `&`, `\` which break naive sed
- Escape function handles all problematic characters
- Alternative (heredoc/envsubst) requires additional dependencies

### 7. Docker vs Bare-Metal Flag

**Decision:** Use `--docker-mode` flag instead of `--skip-virtualhost`.

**Rationale:**
- More semantic - describes the context, not just what to skip
- Easier to extend if Docker needs other behavioral differences
- Self-documenting in logs and process lists

### 8. Archive Persistence

**Decision:** No automatic persistence. The extracted archive is the source.

**Rationale:**
- Simple and predictable
- No hidden file copying
- Re-extract for upgrades (rare operation)

### 9. Test Sites

**Decision:** Optional via `--with-test-site`, disabled by default.

**Rationale:**
- Many deployments don't need test sites
- Reduces default complexity
- Docker users create separate containers for testing

---

*Version: 2.3*
*Date: 2026-01-23*
