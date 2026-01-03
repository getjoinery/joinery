# Docker Setup Testing Report

**Date:** January 3, 2026
**Tested By:** Claude Code
**Test Server:** 23.239.11.53 (Ubuntu 24.04)
**Spec Tested:** docker_minimal_setup.md

## Executive Summary

Testing of the Docker setup process revealed **9 significant issues** that prevent successful deployment. The primary causes are:

1. Scripts designed for traditional VMs don't auto-detect Docker environments
2. Existing VirtualHost/site creation scripts not being used by Docker workflow
3. Volume mounting strategy conflicts with build-time installations
4. Database initialization workflow not leveraging existing `new_account.sh`

**Key insight:** The existing scripts (`server_setup.sh`, `new_account.sh`) already handle all required functionality. They just need minor fixes for Docker compatibility - no new scripts required.

This report provides detailed findings and recommendations for fixes that work **both inside and outside Docker** without requiring special flags.

---

## Test Results Summary

| Test | Status | Notes |
|------|--------|-------|
| Archive Creation | PASS | joinery-2-17.tar.gz created correctly |
| Archive Transfer | PASS | SCP transfer successful |
| Docker Installation | PASS | Docker 29.1.3 installed |
| Docker Build | FAIL | Multiple script issues |
| Container Start | PARTIAL | Started but app not accessible |
| Application Load | FAIL | Apache default page, not Joinery |
| Database Init | PARTIAL | Required manual SQL import |
| Data Persistence | NOT TESTED | Blocked by earlier failures |

---

## Issue #1: POSTGRES_PASSWORD Environment Variable Override

**Severity:** CRITICAL
**File:** `/var/www/html/joinerytest/maintenance_scripts/server_setup.sh`
**Line:** 8

### Problem

```bash
# Current code
POSTGRES_PASSWORD=""  # This OVERWRITES any environment variable!
```

When Docker sets `ENV POSTGRES_PASSWORD=secret`, line 8 immediately overwrites it with an empty string, causing the interactive password prompt that fails in Docker builds.

### Solution

```bash
# Fixed code - preserves environment variable if set
POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-}"
```

This bash syntax means: "Use the value of POSTGRES_PASSWORD if it's set and non-empty, otherwise use empty string (which triggers the interactive prompt)."

**Works in both environments:**
- Docker: Uses the ENV value from Dockerfile
- Traditional: Prompts for password as before

---

## Issue #2: server_setup.sh Should Create user1

**Severity:** CRITICAL
**File:** `/var/www/html/joinerytest/maintenance_scripts/server_setup.sh`
**Lines:** 79-95

### Problem

The script currently assumes user1 already exists (comment on line 79: "assumes user1 already exists"):

```bash
# Configure user1 (assumes user1 already exists)  <-- This assumption is the problem
log "Configuring user1..."
if id "user1" &>/dev/null; then
    # configure user1...
else
    error "user1 does not exist. Please create user1 before running this script."
fi
```

This fails in Docker (no user1) and also fails on any fresh server where user1 wasn't pre-created.

### Analysis

For a self-contained setup script, it **should create user1** if it doesn't exist. This makes the script work:
- On Linode (where user1 may or may not be pre-created)
- In Docker containers (fresh environment)
- On any fresh Ubuntu server

### Solution

Change the logic from "assume exists, error if not" to "create if needed, then configure":

```bash
# Create and configure user1
log "Setting up user1..."

# Create user1 if it doesn't exist
if ! id "user1" &>/dev/null; then
    log "Creating user1..."
    useradd -m -s /bin/bash user1
    log "user1 created"
fi

# Configure user1's SSH directory
mkdir -p /home/user1/.ssh
chmod 700 /home/user1/.ssh
chown user1:user1 /home/user1/.ssh
touch /home/user1/.ssh/authorized_keys
chmod 600 /home/user1/.ssh/authorized_keys
chown user1:user1 /home/user1/.ssh/authorized_keys

log "user1 configured successfully"
```

**Works in both environments:**
- Docker: Creates user1 automatically
- Traditional Linode: Uses existing user1 if present, creates if not
- Any fresh server: Creates user1 automatically

**Note:** Line 441 (`usermod -aG www-data user1`) will also work correctly since user1 will always exist by that point.

---

## Issue #3: systemctl Commands Fail in Docker

**Severity:** CRITICAL
**File:** `/var/www/html/joinerytest/maintenance_scripts/server_setup.sh`
**Lines:** 177-178, 224-225, 234, 243, 251-256, 297, 314-315, 342

### Problem

Docker containers don't run systemd (PID 1 is not systemd). Commands like `systemctl start postgresql` fail with:

```
System has not been booted with systemd as init system (PID 1). Can't operate.
Failed to connect to bus: Host is down
```

### Solution

Create a helper function that auto-detects the environment:

```bash
# Add near the top of the script, after color definitions

# Detect if running in Docker
is_docker() {
    [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null
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
```

Then replace all `systemctl` calls:

```bash
# Before
systemctl start postgresql
systemctl enable postgresql

# After
service_start postgresql
```

**Works in both environments:**
- Docker: Uses `service` command
- Traditional: Uses `systemctl` with enable

---

## Issue #4: Package Installation Fails in Docker (X11 Dependencies)

**Severity:** HIGH
**File:** `/var/www/html/joinerytest/maintenance_scripts/server_setup.sh`
**Lines:** 97-118

### Problem

The packages `php8.3-imagick` and `php8.3-fpm` have dependencies (ghostscript, X11 libraries) that fail to configure in headless Docker environments:

```
Errors were encountered while processing:
 x11-common
 xfonts-utils
 php8.3-fpm
 ghostscript
```

### Solution

Use `policy-rc.d` to prevent services from auto-starting during package installation. This is the standard Docker/Debian approach and allows all packages to install successfully:

```bash
# Prevent services from auto-starting during package installation
prevent_service_start() {
    printf '#!/bin/sh\nexit 101' > /usr/sbin/policy-rc.d
    chmod +x /usr/sbin/policy-rc.d
}

allow_service_start() {
    rm -f /usr/sbin/policy-rc.d
}

# Usage in server_setup.sh:
if is_docker; then
    prevent_service_start
fi

apt-get install -y php8.3-fpm php8.3-imagick  # Won't try to start services

allow_service_start  # Safe to call even if not in Docker
```

**Works in both environments:**
- Docker: Prevents service start failures during package install
- Traditional: `prevent_service_start` is skipped, packages install normally

---

## Issue #5: VirtualHost Scripts Not Used in Docker Workflow

**Severity:** CRITICAL
**Files:**
- `docker_minimal_setup.md` (spec) - doesn't reference existing scripts
- `new_account.sh` - handles VirtualHost but has Docker issues
- `virtualhost_update_script.sh` - not included in archive
- `default_virtualhost.conf` - IS in archive but not used

### Problem

**Existing infrastructure exists but isn't used:**

1. `default_virtualhost.conf` - Template with built-in URL routing (no .htaccess needed!):
   ```apache
   RewriteRule ^(.*)$ serve.php?__route=$1 [QSA,L]
   ```

2. `new_account.sh` - Creates sites including VirtualHost, but:
   - Line 5: `VIRTUALHOST_TEMPLATE=/home/user1/default_virtualhost.conf` (hardcoded path)
   - Line 6: `GLOBALVARS_DEFAULT=/home/user1/Globalvars_site_default.php` (hardcoded path)
   - Lines 163, 178, 187: Interactive password prompts for PostgreSQL
   - Line 234: `systemctl reload apache2` (Docker incompatible)

3. `virtualhost_update_script.sh` - Updates VirtualHosts but NOT included in archive

The spec's Dockerfile bypasses these scripts entirely, resulting in no VirtualHost configuration.

### Solution

Use the existing `new_account.sh` in the Docker workflow after fixing it for Docker compatibility. This leverages existing tested code rather than duplicating functionality.

**Required fixes to `new_account.sh`:**

```bash
# At top of script, make paths relative to script location
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VIRTUALHOST_TEMPLATE="${SCRIPT_DIR}/default_virtualhost.conf"
GLOBALVARS_DEFAULT="${SCRIPT_DIR}/Globalvars_site_default.php"

# Use environment variable for password, fall back to prompt
if [ -n "$PGPASSWORD" ]; then
    # Non-interactive mode (Docker)
    createdb -T template0 "$1" -U postgres
else
    # Interactive mode (traditional)
    echo "Enter PostgreSQL postgres user password:"
    createdb -T template0 "$1" -U postgres -W
fi

# Replace systemctl with service command
service apache2 reload
```

**Docker CMD uses it directly:**

```dockerfile
# In Dockerfile CMD:
PGPASSWORD=${POSTGRES_PASSWORD} ./new_account.sh ${SITENAME} localhost "*"
```

**Works in both environments:**
- Docker: Uses PGPASSWORD env var, no interactive prompts
- Traditional: Prompts for password as before

### Additional Fix: Include virtualhost_update_script.sh in Archive

Update `publish_upgrade.php` to include `virtualhost_update_script.sh` in the maintenance_scripts directory of the archive.

---

## Issue #6: Database Initialization - Use Existing new_account.sh (formerly #7)

**Severity:** CRITICAL
**Files:**
- `docker_minimal_setup.md` (spec) - doesn't use existing scripts
- `new_account.sh` - already handles database creation and import

### Problem

`new_account.sh` already handles database initialization:
- Creates database (line 163)
- Imports `joinery-install.sql.gz` (lines 176-194)
- Handles both compressed and uncompressed SQL files

But the Docker spec doesn't use it, instead trying to replicate this logic in the Dockerfile CMD.

**Docker compatibility issues in new_account.sh:**
- Lines 163, 178, 187: Interactive password prompts (`-W` flag)
- Uses `createdb` command directly instead of `psql -c "CREATE DATABASE..."`

### Solution

**Fix new_account.sh to support non-interactive mode:**

```bash
# Replace lines 161-194 with:

# Create PostgreSQL database
echo "Creating PostgreSQL database '$1'..."

# Check for PGPASSWORD environment variable for non-interactive mode
if [ -n "$PGPASSWORD" ]; then
    # Non-interactive (Docker/automated)
    if ! createdb -T template0 "$1" -U postgres; then
        echo "ERROR: Failed to create database '$1'"
        # ... rollback
        exit 1
    fi
else
    # Interactive (traditional)
    echo "Enter PostgreSQL postgres user password:"
    if ! createdb -T template0 "$1" -U postgres; then
        echo "ERROR: Failed to create database '$1'"
        # ... rollback
        exit 1
    fi
fi

# Load database restore file
echo "Loading database from restore file '$DATABASE_RESTORE_FILE'..."

if [[ "$DATABASE_RESTORE_FILE" == *.gz ]]; then
    if [ -n "$PGPASSWORD" ]; then
        # Non-interactive
        gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -d "$1"
    else
        # Interactive
        echo "Enter PostgreSQL postgres user password:"
        gunzip -c "$DATABASE_RESTORE_FILE" | psql -U postgres -W -d "$1"
    fi
else
    if [ -n "$PGPASSWORD" ]; then
        psql -U postgres -d "$1" -f "$DATABASE_RESTORE_FILE"
    else
        echo "Enter PostgreSQL postgres user password:"
        psql -U postgres -W -d "$1" -f "$DATABASE_RESTORE_FILE"
    fi
fi
```

**Then use new_account.sh in Docker startup:**

```bash
# In startup.sh:
export PGPASSWORD="${POSTGRES_PASSWORD}"
cd /var/www/html/${SITENAME}/maintenance_scripts
./new_account.sh ${SITENAME} localhost "*"
```

This leverages the existing tested script instead of duplicating logic.

---

## Issue #7: composerAutoLoad Database Setting Wrong Path (formerly #8)

**Severity:** HIGH
**File:** joinery-install.sql.gz

### Problem

The database dump contains:
```sql
INSERT INTO stg_settings VALUES ('composerAutoLoad', '/var/www/html/joinerytest/vendor/', ...);
```

This hardcoded path doesn't work in Docker where the site name is different.

### Solution

Update `create_install_sql.php` to use a relative path instead of hardcoded absolute path:

```php
// In create_install_sql.php, ensure composerAutoLoad uses relative path
// The SQL dump should contain:
// INSERT INTO stg_settings VALUES ('composerAutoLoad', '../vendor/', ...);
```

**Works in both environments:**
- Docker: Relative path works regardless of site name
- Traditional: Relative path works the same way

---

## Issue #8: Volume Mounts Overwrite Build-Time Files (formerly #9)

**Severity:** CRITICAL
**File:** docker_minimal_setup.md (spec)

### Problem

When running:
```bash
docker run -v dockertest_vendor:/var/www/html/dockertest/vendor ...
```

If the volume is empty (first run), Docker creates an empty directory that **overwrites** the composer packages installed during the build.

This affects:
- `/var/www/html/${SITENAME}/vendor/` - Composer packages
- `/var/www/html/${SITENAME}/config/` - Globalvars_site.php

### Solution

Don't mount `vendor/` as a volume. Vendor packages are part of the application and should be baked into the image.

**Volumes to mount (user data only):**
- `postgres` - Database files
- `uploads` - User-uploaded files
- `config` - Site configuration
- `logs` - Log files

**Do NOT mount as volumes:**
- `vendor/` - Composer packages (part of the app)
- `public_html/` - Application code (part of the app)

```bash
# Correct docker run command
docker run -d \
  --name mysitename \
  -p 8080:80 \
  -v mysitename_postgres:/var/lib/postgresql \
  -v mysitename_uploads:/var/www/html/mysitename/uploads \
  -v mysitename_config:/var/www/html/mysitename/config \
  -v mysitename_logs:/var/www/html/mysitename/logs \
  joinery-mysitename
```

---

## Issue #9: Container Stops on Apache Restart (formerly #10)

**Severity:** MEDIUM
**File:** Runtime behavior

### Problem

When running `apache2ctl restart` inside a container where Apache is the foreground process (PID 1 or the CMD), it kills the process and the container stops.

### Solution

Use `apache2ctl graceful` or `service apache2 reload` instead of restart:

```bash
# Inside running container, use:
apache2ctl graceful
# or
service apache2 reload

# NOT:
apache2ctl restart  # This kills the container!
```

**Document in spec:** Add a warning about this in the "Common Docker Commands" section.

---

## Recommended Changes Summary

### Key Insight: Use Existing Infrastructure

The Joinery codebase already has scripts that handle most Docker requirements:
- `new_account.sh` - Creates sites, databases, VirtualHosts
- `default_virtualhost.conf` - Template with URL routing built-in
- `virtualhost_update_script.sh` - Updates VirtualHost configurations

**The Docker spec should leverage these scripts, not duplicate their functionality.**

### Files to Modify

| File | Changes Required |
|------|-----------------|
| `server_setup.sh` | Issues #1, #2, #3, #4 (environment detection, service management) |
| `new_account.sh` | Issues #5, #6 (relative paths, PGPASSWORD support, service functions) |
| `virtualhost_update_script.sh` | Issue #3 (use service functions instead of systemctl) |
| `docker_minimal_setup.md` | Rewrite to use existing scripts properly |
| `publish_upgrade.php` | Include `virtualhost_update_script.sh` in archive |
| `create_install_sql.php` | Issue #7 (use relative composerAutoLoad path) |

### No New Scripts Needed

The existing scripts (`server_setup.sh` and `new_account.sh`) already handle all required functionality.
Docker just needs a simple CMD to orchestrate them - no separate startup script required.

### Files NOT Needing Changes

| File | Reason |
|------|--------|
| `.htaccess` | NOT NEEDED - VirtualHost template has routing built-in |
| `default_virtualhost.conf` | Already correct |

### Priority Order

1. **CRITICAL (Blocks deployment):**
   - Issue #1: POSTGRES_PASSWORD override in server_setup.sh
   - Issue #2: user1 creation in server_setup.sh
   - Issue #3: systemctl → service functions (all scripts)
   - Issue #5: Use VirtualHost scripts properly (via new_account.sh)
   - Issue #6: Fix new_account.sh for non-interactive mode

2. **HIGH (Causes failures):**
   - Issue #4: Package installation (policy-rc.d)
   - Issue #7: composerAutoLoad path
   - Issue #8: Volume mount strategy

3. **MEDIUM (Operational issues):**
   - Issue #9: Document apache2ctl graceful usage

---

## Proposed Updated server_setup.sh Structure

```bash
#!/bin/bash
# Version 2.0 - Docker and Traditional Environment Compatible

# ============================================================
# CONFIGURATION
# ============================================================
POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-}"  # Use env var if set

# ============================================================
# ENVIRONMENT DETECTION
# ============================================================
is_docker() {
    [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null
}

# ============================================================
# SERVICE MANAGEMENT (auto-detects environment)
# ============================================================
service_start() { ... }
service_stop() { ... }
service_restart() { ... }
service_reload() { ... }

# ============================================================
# MAIN SETUP
# ============================================================

# 1. Prevent service auto-start during package install (Docker safety)
if is_docker; then
    printf '#!/bin/sh\nexit 101' > /usr/sbin/policy-rc.d
    chmod +x /usr/sbin/policy-rc.d
fi

# 2. System updates
# 3. Create user1 if needed
# 4. Install PHP (core packages, then optional)
# 5. Install Composer
# 6. Install Apache + configure VirtualHost + .htaccess
# 7. Install PostgreSQL + configure
# 8. Create directories + set permissions
# 9. Install Composer dependencies
# 10. Cleanup

# Remove policy-rc.d if we created it
rm -f /usr/sbin/policy-rc.d
```

---

## Next Steps

1. **Review this report** - Confirm the analysis and proposed fixes
2. **Update server_setup.sh** - Implement all compatible fixes (Issues #1, #2, #3, #4)
3. **Update new_account.sh** - Implement Docker compatibility fixes (Issues #5, #7)
4. **Update docker_minimal_setup.md** - Document corrected workflow using existing scripts
5. **Test full workflow** - Fresh server, archive to running container
6. **Document volume strategy** - Clarify which directories need persistence

---

## Proposed Corrected Docker Workflow

### Phase 1: Archive Contents (publish_upgrade.php changes)

The archive should include:
```
joinery-X-Y.tar.gz
├── public_html/                    # Application code
├── config/
│   └── Globalvars_site_default.php # Config template
└── maintenance_scripts/
    ├── server_setup.sh             # Base system setup (fixed for Docker)
    ├── new_account.sh              # Site creation (fixed for Docker)
    ├── default_virtualhost.conf    # VirtualHost template
    ├── virtualhost_update_script.sh # VirtualHost updates (ADD THIS)
    ├── Globalvars_site_default.php # Config template
    └── joinery-install.sql.gz      # Database schema
```

### Phase 2: Docker Build (Dockerfile)

```dockerfile
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV SITENAME=mysitename
ENV POSTGRES_PASSWORD=mypassword

# Copy everything
COPY ${SITENAME}/ /var/www/html/${SITENAME}/

# Copy maintenance scripts to accessible location
COPY maintenance_scripts/ /var/www/html/${SITENAME}/maintenance_scripts/

# Run server_setup.sh (installs PHP, Apache, PostgreSQL, Composer)
# The fixed script auto-detects Docker and uses appropriate commands
RUN chmod +x /var/www/html/${SITENAME}/maintenance_scripts/*.sh && \
    cd /var/www/html/${SITENAME}/maintenance_scripts && \
    ./server_setup.sh

# Container startup: start services, create site if needed, run Apache foreground
# No separate startup script needed - just orchestrate existing scripts
CMD service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    ([ -f /var/www/html/${SITENAME}/config/Globalvars_site.php ] || \
        cd /var/www/html/${SITENAME}/maintenance_scripts && \
        ./new_account.sh ${SITENAME} localhost "*") && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php 2>/dev/null || true && \
    apache2ctl -D FOREGROUND
```

**CMD breakdown:**
1. Start PostgreSQL service
2. Wait for PostgreSQL to be ready
3. Export password for non-interactive database operations
4. If config doesn't exist (first run), run `new_account.sh` to create site
5. Run database migrations
6. Start Apache in foreground (keeps container alive)

### Phase 3: Run Container

```bash
docker run -d \
  --name mysitename \
  -p 8080:80 \
  -v mysitename_postgres:/var/lib/postgresql \
  -v mysitename_uploads:/var/www/html/mysitename/uploads \
  -v mysitename_config:/var/www/html/mysitename/config \
  -v mysitename_logs:/var/www/html/mysitename/logs \
  joinery-mysitename
```

**Note:** Don't mount `vendor/` as a volume - it should come from the image.

---

## Appendix: Test Commands Used

```bash
# SSH with password
sshpass -p 'PASSWORD' ssh -o StrictHostKeyChecking=no root@SERVER 'command'

# Docker build
docker build -t joinery-SITENAME .

# Docker run with volumes
docker run -d --name SITENAME -p 8080:80 \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  ...

# Debug container
docker logs SITENAME
docker exec -it SITENAME bash

# Manual SQL import
docker exec SITENAME bash -c "gunzip -c /tmp/joinery-install.sql.gz | su -c 'psql -d SITENAME' postgres"
```
