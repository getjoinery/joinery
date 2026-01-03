# Docker Setup Attempt 3 - Complete Issue Report

**Date:** 2026-01-03
**Server:** 23.239.11.53 (fresh Ubuntu 24.04.3 LTS)
**Final Status:** SUCCESS (with manual interventions)

## Summary

Docker image build and container deployment succeeded after resolving 12 distinct issues. The Joinery application is now running at `http://23.239.11.53:8080/`. This report documents all issues encountered and the fixes required for a smooth automated build.

---

## Issues Encountered (In Order)

### Issue 1: SSH Configuration Not Available in Docker

**Error:**
```
[2026-01-03 19:41:28] Configuring SSH security...
cp: cannot stat '/etc/ssh/sshd_config': No such file or directory
```

**Location:** `server_setup.sh` lines 356-376

**Root Cause:** The script attempts to configure SSH security, but Docker containers don't have SSH installed (nor do they need it).

**Fix Applied (local, in server_setup.sh v2.2):** Wrapped SSH/firewall/fail2ban section in `if is_docker` check

```bash
if is_docker; then
    log "Docker detected - skipping SSH, firewall, and security hardening (not needed in containers)"
else
    # ... existing SSH/firewall/fail2ban code ...
fi
```

---

### Issue 2: Kernel Hardening Not Available in Docker

**Error:**
```
tee: /etc/modprobe.d/blacklist-rare-network.conf: No such file or directory
```

**Location:** `server_setup.sh` lines 456-514

**Root Cause:** Docker containers share the host kernel and don't have `/etc/modprobe.d/` or sysctl configuration capabilities.

**Fix Applied (local, in server_setup.sh v2.2):** Wrapped kernel hardening section in `if is_docker` check

```bash
if is_docker; then
    log "Docker detected - skipping kernel security hardening (managed by host)"
else
    # ... existing kernel hardening code ...
fi
```

---

### Issue 3: Database Password Check Fails with PGPASSWORD

**Error:**
```
ERROR: Database password is empty in Globalvars_site_default.php
```

**Location:** `new_account.sh` lines 56-84

**Root Cause:** Script required password in config file even when `PGPASSWORD` environment variable was set for non-interactive Docker use.

**Fix Applied (local, in new_account.sh v2.5):** Modified password extraction to fall back to `PGPASSWORD`

```bash
if [ -z "$DB_PASSWORD" ] || [ "$DB_PASSWORD" == "" ]; then
    if [ -n "$PGPASSWORD" ]; then
        echo "Using PGPASSWORD environment variable for database password."
        DB_PASSWORD="$PGPASSWORD"
    else
        echo "ERROR: Database password is empty..."
        exit 1
    fi
fi
```

---

### Issue 4: Directory Already Exists Check

**Error:**
```
ERROR: /var/www/html/dockertest already exists.
```

**Location:** `new_account.sh` lines 100-120

**Root Cause:** In Docker, the site directory is created during image build, but config file isn't created until container runs. Script incorrectly detected this as "site already configured."

**Fix Applied (local, in new_account.sh v2.5):** Added Docker first-run detection

```bash
DOCKER_FIRST_RUN=false
if [ -d "$NEW_SITE_ROOT" ]; then
  if [ ! -f "$NEW_CONFIG_FILE" ]; then
    echo "Docker first-run detected: $NEW_SITE_ROOT exists but config doesn't. Continuing setup..."
    DOCKER_FIRST_RUN=true
  else
    echo "ERROR: $NEW_SITE_ROOT already exists and is fully configured."
    exit 1
  fi
fi
```

---

### Issue 5: Hardcoded VirtualHost Template Path

**Error:**
```
cp: cannot stat '/home/user1/default_virtualhost.conf': No such file or directory
```

**Location:** `new_account.sh` line 282 (original)

**Root Cause:** Script used hardcoded path `/home/user1/default_virtualhost.conf` instead of `$VIRTUALHOST_TEMPLATE` variable.

**Fix Applied (local, in new_account.sh v2.5):** Changed to use the variable

```bash
# Before:
cp /home/user1/default_virtualhost.conf /etc/apache2/sites-available/$1.conf

# After:
cp "$VIRTUALHOST_TEMPLATE" /etc/apache2/sites-available/$1.conf
```

---

### Issue 6: Missing Test Site Directories

**Error:**
```
AH00112: Warning: DocumentRoot [/var/www/html/dockertest_test/public_html] does not exist
```

**Location:** `new_account.sh` around line 193

**Root Cause:** In Docker first-run mode, test site directories weren't being created, but Apache VirtualHost referenced them.

**Fix Applied (local, in new_account.sh v2.5):** Added minimal test site creation for Docker

```bash
if [ "$DOCKER_FIRST_RUN" = true ]; then
    echo "Docker first-run: Creating minimal test site directories for Apache..."
    mkdir -p /var/www/html/$1_test/public_html
    mkdir -p /var/www/html/$1_test/logs
    chown -R www-data:www-data /var/www/html/$1_test
    echo "Minimal test site structure created."
fi
```

---

### Issue 7: Apache Already Running in Container

**Error:**
```
httpd (pid 136) already running
```
Container would exit immediately.

**Location:** `new_account.sh` Apache reload section

**Root Cause:** `new_account.sh` reloads Apache after enabling the site. On regular servers this is necessary (Apache is already running and needs to pick up the new VirtualHost). In Docker, Apache isn't running yet - it starts fresh via `apache2ctl -D FOREGROUND` after the script completes, so the reload is unnecessary and causes Apache to start prematurely.

**Fix Required (in new_account.sh):** Test config always, but only reload if not Docker:

```bash
# Always test config (catches errors early in both environments)
apache2ctl configtest

# Only reload if not Docker (Docker starts Apache fresh after this script)
# Check both /.dockerenv file and cgroup for robust Docker detection
if [ ! -f /.dockerenv ] && ! grep -q docker /proc/1/cgroup 2>/dev/null; then
    service apache2 reload
fi
```

This preserves necessary behavior on regular servers while avoiding the start-then-stop issue in Docker.

---

### Issue 8: VirtualHost Not Persisted on Container Restart

**Symptom:** Apache showed default page instead of Joinery app after container restart.

**Root Cause:** VirtualHost was created at runtime by `new_account.sh`, but when container restarted, the VirtualHost symlink in sites-enabled wasn't persisted (ephemeral container filesystem).

**Fix:** Create VirtualHost during Docker **build phase**. This bakes the VirtualHost configuration into the image, so it survives container restarts without needing a volume.

```dockerfile
RUN cp /var/www/html/${SITENAME}/maintenance_scripts/default_virtualhost.conf /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SERVER_IP}}/*/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SITE_NAME}}/${SITENAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    mkdir -p /var/www/html/${SITENAME}_test/public_html && \
    mkdir -p /var/www/html/${SITENAME}_test/logs && \
    a2dissite 000-default.conf && \
    a2ensite ${SITENAME}.conf
```

This approach works because the VirtualHost is part of the image itself, not created at runtime. See **Dockerfile Template** section below for the complete solution.

---

### Issue 9: Default Apache Site Taking Precedence

**Symptom:** Apache showed Ubuntu default page instead of Joinery app.

**Root Cause:** `000-default.conf` was enabled and matched before `dockertest.conf` for requests without a specific hostname.

**Fix Required (in new_account.sh):** Disable default site before enabling the new site:

```bash
# Disable default site (safe on all servers - we're enabling our own site)
a2dissite 000-default.conf 2>/dev/null || true

# Enable our site
a2ensite $1.conf
```

This is safe on regular servers too since we're enabling our own VirtualHost.

---

### Issue 10: Database Already Exists on Container Restart

**Error:**
```
createdb: error: database creation failed: ERROR:  database "dockertest" already exists
ERROR: Failed to create database 'dockertest'
Rolling back: removing created directories...
```

**Location:** `new_account.sh` lines 203-226

**Root Cause:** Database volume persists across container restarts. Script tried to create database that already existed, then rolled back by deleting directories (which are volume-mounted and can't be deleted).

**Fix Applied (local, in new_account.sh v2.5):** Check if database exists before creating

```bash
echo "Checking if PostgreSQL database '$1' already exists..."
DB_EXISTS=false
if [ -n "$PGPASSWORD" ]; then
    if psql -U postgres -lqt | cut -d \| -f 1 | grep -qw "$1"; then
        DB_EXISTS=true
        echo "Database '$1' already exists. Skipping creation and restore."
    fi
fi

if [ "$DB_EXISTS" = false ]; then
    # Create database and load restore file...
fi
```

---

### Issue 11: Missing Composer Dependencies

**Error:**
```
Composer autoload.php not found at: ../vendor/autoload.php
```

**Location:** Runtime error from `includes/SmtpMailer.php`

**Root Cause:** Composer dependencies not installed. `new_account.sh` doesn't run composer install, but `deploy.sh` does (via `composer_install_if_needed.php`).

**Fix Required:** Add composer install to `new_account.sh` after database restore, before virtualhost setup:

```bash
# Install composer dependencies
# Must be after database restore (Globalvars needs stg_settings table)
# Before virtualhost setup (fail early if composer fails)
echo "Installing composer dependencies..."
if ! php /var/www/html/$1/public_html/utils/composer_install_if_needed.php; then
    echo "ERROR: Failed to install composer dependencies"
    exit 1
fi
echo "Composer dependencies installed."
```

**Placement in new_account.sh flow:**
```
9.  Load database restore file
10. Install composer dependencies  <-- NEW
11. Create virtualhost file
12. Test Apache config
13. Enable virtualhost
14. Reload Apache (skip in Docker)
```

---

### Issue 12: Missing composerAutoLoad Setting

**Error:**
```
Composer autoload.php not found at: /var/www/html/dockertest/public_html/vendor/autoload.php
```

**Root Cause:** Composer dependencies weren't installed, so vendor directory didn't exist.

**Resolution:** This is handled automatically by database migrations which set `composerAutoLoad` to a relative path in `stg_settings`. The only fix needed is ensuring `composer install` runs during Docker build (see Issue 11).

---

## Files Modified

### Local Scripts (fixes applied and committed)

#### 1. server_setup.sh (Version 2.2)
- ✅ Added Docker skip for SSH configuration (lines 356-376)
- ✅ Added Docker skip for firewall/fail2ban (lines 378-420)
- ✅ Added Docker skip for unattended upgrades (lines 422-449)
- ✅ Added Docker skip for kernel hardening (lines 456-514)

#### 2. new_account.sh (Version 2.5)
- ✅ Added PGPASSWORD fallback for database password
- ✅ Added Docker first-run detection
- ✅ Fixed hardcoded virtualhost template path
- ✅ Added minimal test site directory creation for Docker
- ✅ Added database existence check before creation
- ❌ **NEEDS:** Skip Apache reload in Docker (inline Docker detection check)
- ❌ **NEEDS:** Disable 000-default.conf before enabling new site
- ❌ **NEEDS:** Add composer install step (call `composer_install_if_needed.php`)

#### 3. Dockerfile.template (NEW - to be created)
- ❌ **NEEDS:** Create `maintenance_scripts/Dockerfile.template` with build arguments

### Dockerfile-Only Fixes (on remote server, NOT in local files)

These fixes were applied directly to the Dockerfile on the remote server:

#### Issue 7: Apache already running
- **Current Dockerfile workaround:** `service apache2 stop` before `apache2ctl -D FOREGROUND`
- **Proper fix:** Update `new_account.sh` to skip Apache reload when `/.dockerenv` exists (see Issue 7 above)
- Once `new_account.sh` is fixed, Dockerfile workaround can be removed

#### Issue 8: VirtualHost not persisted
- **Fix:** Create VirtualHost during Docker build phase (Dockerfile RUN)
- **Resolution:** Dockerfile template will be added to `maintenance_scripts/` (see Dockerfile Template section)

#### Issue 9: Default site taking precedence
- **Current Dockerfile workaround:** `a2dissite 000-default.conf` in Dockerfile RUN
- **Proper fix:** Update `new_account.sh` to run `a2dissite 000-default.conf` (see Issue 9 above)
- Once `new_account.sh` is fixed, Dockerfile workaround can be removed

---

## Dockerfile Template

A reusable Dockerfile template will be added to `maintenance_scripts/Dockerfile.template` for version control and reproducibility.

### Why a Template?

- **Version controlled**: Travels with the codebase
- **Reproducible**: Same Dockerfile for all deployments
- **Parameterized**: Build arguments allow customization per deployment

### Build Arguments

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `SITENAME` | No | dockertest | Site directory name |
| `POSTGRES_PASSWORD` | Yes | - | Database password |
| `DOMAIN_NAME` | No | localhost | Domain for VirtualHost |

### Usage

```bash
docker build \
  --build-arg SITENAME=clientsite \
  --build-arg POSTGRES_PASSWORD=secure_password \
  --build-arg DOMAIN_NAME=client.example.com \
  -t joinery-clientsite .
```

### Template Location

`maintenance_scripts/Dockerfile.template`

---

## Recommended Final Dockerfile

This will be saved as `maintenance_scripts/Dockerfile.template`:

```dockerfile
FROM ubuntu:24.04

# Build arguments (customizable per deployment)
ARG SITENAME=dockertest
ARG POSTGRES_PASSWORD
ARG DOMAIN_NAME=localhost

# Set as environment variables for runtime
ENV DEBIAN_FRONTEND=noninteractive
ENV SITENAME=${SITENAME}
ENV POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
ENV DOMAIN_NAME=${DOMAIN_NAME}

# Copy source files
COPY ${SITENAME}/ /var/www/html/${SITENAME}/
COPY maintenance_scripts/ /var/www/html/${SITENAME}/maintenance_scripts/

# Run server setup (installs all dependencies)
RUN chmod +x /var/www/html/${SITENAME}/maintenance_scripts/*.sh && \
    cd /var/www/html/${SITENAME}/maintenance_scripts && \
    ./server_setup.sh

# Create VirtualHost configuration during build (persists across restarts)
# Note: Composer install and 000-default disable are handled by new_account.sh
RUN cp /var/www/html/${SITENAME}/maintenance_scripts/default_virtualhost.conf /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SERVER_IP}}/*/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{DOMAIN_NAME}}/${DOMAIN_NAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    sed -i "s/{{SITE_NAME}}/${SITENAME}/g" /etc/apache2/sites-available/${SITENAME}.conf && \
    mkdir -p /var/www/html/${SITENAME}_test/public_html && \
    mkdir -p /var/www/html/${SITENAME}_test/logs && \
    a2ensite ${SITENAME}.conf

# Expose ports
EXPOSE 80 5432

# Start services
CMD service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    ([ -f /var/www/html/${SITENAME}/config/Globalvars_site.php ] || \
        cd /var/www/html/${SITENAME}/maintenance_scripts && \
        ./new_account.sh ${SITENAME} ${DOMAIN_NAME} "*") && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php 2>/dev/null || true && \
    apache2ctl -D FOREGROUND
```

**Note:** Once `new_account.sh` is updated (v2.6), the `service apache2 stop` workaround and `a2dissite 000-default.conf` in the Dockerfile RUN can be removed, as the script will handle these.

---

## Current Deployment Status

- **Container:** Running successfully at `http://23.239.11.53:8080/`
- **Database:** PostgreSQL 16 with dockertest database
- **Volumes:** 10 persistent volumes configured
- **Manual fixes applied in container:**
  - Ran `composer install` manually (needs to be added to `new_account.sh`)

---

## Next Steps for Automated Build

### Confirmed TODOs

1. Update `new_account.sh` to skip Apache reload in Docker (use inline Docker detection: `/.dockerenv` + cgroup check)
2. Update `new_account.sh` to disable 000-default.conf before enabling new site
3. Update `new_account.sh` to run `composer_install_if_needed.php` after database restore, before virtualhost
4. Add `Dockerfile.template` to `maintenance_scripts/`
5. Regenerate joinery archive with updated files
6. Test fresh build from scratch

---

## Script Version Summary

| Script | Version | Key Changes |
|--------|---------|-------------|
| server_setup.sh | 2.2 | Docker-aware SSH/firewall/kernel skipping |
| new_account.sh | 2.5 | Docker first-run, PGPASSWORD, DB exists check |
| new_account.sh | 2.6 (TODO) | Docker-aware Apache reload, disable 000-default, add composer install |
| Dockerfile.template | 1.0 (TODO) | Reusable template with build arguments |
