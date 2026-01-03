# Specification: Minimal Docker Setup - Single Container Per Site

## Current Status (as of January 2025)

**Specification Status: 100% COMPLETE** ✅

- ✅ **Phase 1 (Archive Structure):** IMPLEMENTED - `publish_upgrade.php` creates tar.gz archives
- ✅ **Phase 2 (Composer Management):** IMPLEMENTED - All components updated
- ✅ **Phase 3 (Docker Specification):** COMPLETE - Ready to implement

**Testing Status: NOT YET VALIDATED** 🧪

See [Validation Checklist](#validation-checklist) for required testing steps.

Fresh Docker installations use `joinery-install.sql.gz` which already has the correct `../vendor/` path configured. No additional setup needed.

## Overview

Run each Joinery site in its own Docker container that includes everything - Apache, PHP, PostgreSQL, and the application. Just run your existing server_setup.sh script unchanged.

**Strategy: Your script does ALL the work, Docker just provides Ubuntu 24.04.**

### Persistent Storage Philosophy

**We err on the side of including MORE directories for persistent storage.** This ensures:
- No unexpected data loss across container restarts
- Complete operational state preserved
- Logs and audit trails maintained
- Fast restarts without cache rebuilding

See [Understanding the Volume Mounts](#understanding-the-volume-mounts-data-directories) for the complete list of 11 persistent volumes we recommend.

## Important: Site Name Variable

The **SITENAME** is critical - it determines:
- The installation directory: `/var/www/html/SITENAME/`
- Database name in PostgreSQL
- Configuration parameters passed to maintenance scripts
- Container and volume names

Examples: "joinerytest", "integralzen", "mycompany"

**This must match what your maintenance scripts expect!**

## Current Environment to Replicate

**What Exists Now:**
```
/var/www/html/SITENAME/
├── config/
│   └── Globalvars_site.php (database credentials, paths, etc.)
├── public_html/
│   ├── serve.php (entry point)
│   ├── includes/
│   ├── data/
│   ├── views/
│   ├── adm/
│   ├── ajax/
│   ├── api/
│   ├── logic/
│   ├── theme/ (symlink)
│   ├── plugins/ (symlink)
│   └── utils/
│       └── update_database.php (database migration tool)
├── uploads/
├── static_files/
├── logs/
├── cache/
└── backups/
```

**Database Setup:**
- PostgreSQL with `SITENAME` database
- Username: `postgres`
- Password: (from your Globalvars_site.php)
- Test database: `SITENAME_test`

**Entry Point:**
- Apache serves `/var/www/html/SITENAME/public_html/`
- All requests routed through `serve.php` via RouteHelper
- Database migrations run via `utils/update_database.php`

## Scope: What Must Be Replicated Exactly

✅ **Replicate Exactly:**
- Same directory structure (`/var/www/html/SITENAME/`)
- Same config file location and format (`config/Globalvars_site.php`)
- Same database credentials
- Same PHP version (8.3)
- Same Apache configuration
- Same PostgreSQL setup
- Same file permissions model
- Same symlinks for theme/plugins
- Same entry point (serve.php)
- Same database migration process

❌ **Don't Change:**
- Config file structure
- Database setup process
- Application code
- Routing system
- Permission model
- Symlink strategy

## Files to Create (Only 2!)

### 1. `Dockerfile`

This runs your existing setup script WITHOUT ANY CHANGES:

```dockerfile
FROM ubuntu:24.04

# Avoid interactive prompts
ENV DEBIAN_FRONTEND=noninteractive

# Set the site name (CHANGE THIS to match your site)
ENV SITENAME=CHANGE_ME_TO_YOUR_SITENAME

# Copy your application (must match the extracted directory name)
COPY ${SITENAME}/ /var/www/html/${SITENAME}/

# Copy your UNCHANGED setup script
COPY server_setup.sh /tmp/server_setup.sh

# Set PostgreSQL password (CHANGE THIS to match your Globalvars_site.php)
ENV POSTGRES_PASSWORD=CHANGE_ME_TO_YOUR_PASSWORD

# Run your setup script AS-IS (it will use SITENAME from environment)
RUN chmod +x /tmp/server_setup.sh && \
    /tmp/server_setup.sh && \
    rm /tmp/server_setup.sh

# Start both PostgreSQL and Apache
CMD service postgresql start && \
    sleep 5 && \
    su -c "psql -c \"ALTER USER postgres PASSWORD '$POSTGRES_PASSWORD';\"" postgres && \
    su -c "psql -c \"CREATE DATABASE ${SITENAME} OWNER postgres;\"" postgres || true && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php && \
    apache2ctl -D FOREGROUND
```

**IMPORTANT: You must change two things in this Dockerfile:**
1. Replace `CHANGE_ME_TO_YOUR_SITENAME` with your actual site name
2. Replace `CHANGE_ME_TO_YOUR_PASSWORD` with the password from your Globalvars_site.php

That's it! 20 lines total. No script modifications needed.

### 2. `.dockerignore`

```
.git
*.log
joinerytest/backups/*
```

## No Other Files Needed!

- ❌ No docker-compose.yml
- ❌ No script modifications
- ❌ No config changes
- ❌ No separate database container

---

## Phase 2 Composer Management Status Details

### What's Been Implemented ✅

All Phase 2 components have been implemented:

| Component | Status | Details |
|-----------|--------|---------|
| **composer.json** | ✅ Done | Updated to use relative vendor path: `"vendor-dir": "../vendor"` |
| **ComposerValidator.php** | ✅ Done | Enhanced with vendor directory detection and validation |
| **deploy.sh** | ✅ Done | v3.10 - Deploys maintenance_scripts directory properly |
| **new_account.sh** | ✅ Done | v1.31 - Handles gzip-compressed SQL files |
| **publish_upgrade.php** | ✅ Done | Creates tar.gz archives with all components |
| **upgrade.php** | ✅ Done | Properly extracts tar.gz format |
| **composerAutoLoad Migration** | ✅ Intentionally Disabled | Migration 0.68 - see [Appendix: Migrating Existing Sites](#appendix-migrating-existing-sites-to-docker) |
| **server_setup.sh** | ✅ Done | v1.02 - Generic composer.json creation removed |

### Archive Structure (Phase 1) - Complete ✅

Archives are created successfully at `/static_files/joinery-X-Y.tar.gz` containing:

```
joinery-X-Y.tar.gz
├── public_html/           (845 application files)
├── config/                (Globalvars_site_default.php template)
└── maintenance_scripts/   (12 scripts including joinery-install.sql.gz)
    ├── server_setup.sh
    ├── deploy.sh
    ├── new_account.sh
    ├── joinery-install.sql.gz
    └── ... (8 more scripts)
```

---

## Validation Checklist

This checklist validates that all components work together. The specification is complete; these steps confirm the implementation.

### Test 1: Archive Creation
- [ ] Run `php /var/www/html/{SITE}/public_html/utils/publish_upgrade.php`
- [ ] Verify archive created at `/var/www/html/{SITE}/static_files/joinery-X-Y.tar.gz`
- [ ] Extract archive and verify contents:
  - [ ] `public_html/` directory with application files
  - [ ] `config/Globalvars_site_default.php` template
  - [ ] `maintenance_scripts/server_setup.sh`
  - [ ] `maintenance_scripts/deploy.sh`
  - [ ] `maintenance_scripts/new_account.sh`
  - [ ] `maintenance_scripts/joinery-install.sql.gz`

### Test 2: Fresh Server Setup (Non-Docker)
- [ ] Copy archive to clean Ubuntu 24.04 system
- [ ] Extract: `tar -xzf joinery-X-Y.tar.gz`
- [ ] Run `bash maintenance_scripts/server_setup.sh`
- [ ] Verify directory structure created at `/var/www/html/{SITE}/`
- [ ] Verify `/var/www/html/{SITE}/vendor/` populated with composer dependencies
- [ ] Verify `/var/www/html/{SITE}/maintenance_scripts/` contains scripts
- [ ] Run `php /var/www/html/{SITE}/public_html/utils/update_database.php` to apply migrations
- [ ] Access site in browser, verify application loads

**Note:** `deploy.sh` is for git-based deployments and is not used in tar.gz-based installations.

### Test 3: Docker Container Build
- [ ] Create `Dockerfile` per specification (see [Files to Create](#files-to-create-only-2))
- [ ] Create `.dockerignore` per specification
- [ ] Run `docker build -t joinery-{SITENAME} .`
- [ ] Verify build completes without errors

### Test 4: Docker Container Runtime
- [ ] Run container with all persistent volumes (see [Step 5: Run the Container](#step-5-run-the-container)):
  ```bash
  docker run -d --name {SITENAME} -p 8080:80 \
    -v {SITENAME}_postgres:/var/lib/postgresql \
    -v {SITENAME}_uploads:/var/www/html/{SITENAME}/uploads \
    -v {SITENAME}_config:/var/www/html/{SITENAME}/config \
    [... all 11 volumes ...] \
    joinery-{SITENAME}
  ```
- [ ] Check container logs: `docker logs {SITENAME}`
- [ ] Verify PostgreSQL started: `docker exec {SITENAME} service postgresql status`
- [ ] Verify Apache running: `docker exec {SITENAME} apache2ctl -t`
- [ ] Access `http://localhost:8080` in browser
- [ ] Verify application loads and is functional

### Test 5: Data Persistence
- [ ] Create test data in application (e.g., create a user account)
- [ ] Stop container: `docker stop {SITENAME}`
- [ ] Start container: `docker start {SITENAME}`
- [ ] Verify test data persists after restart
- [ ] Remove and recreate container (keeping volumes):
  ```bash
  docker rm {SITENAME}
  docker run -d --name {SITENAME} [same volume mounts] joinery-{SITENAME}
  ```
- [ ] Verify data still persists

### Test 6: Reverse Proxy (Optional)
- [ ] Configure Apache reverse proxy per [Making Sites Accessible Without Port Numbers](#making-sites-accessible-without-port-numbers)
- [ ] Run `sudo a2ensite {SITENAME}` and `sudo systemctl reload apache2`
- [ ] Access site via domain name without port
- [ ] Run `sudo certbot --apache -d {DOMAIN}` for SSL
- [ ] Verify HTTPS access works

---

## Prerequisites - Starting from a Blank Server

If you're starting with a completely blank server, you need:
1. Docker installed (see "Installing Docker" section below)
2. One tar.gz file: `joinery-docker.tar.gz` containing:
   - Site files from the application
   - Maintenance scripts (server_setup.sh, deploy.sh, etc.)

**Note:** This requires the archive structure changes described in [Archive Structure Changes](/specs/archive_structure.md) to be implemented first.

## Getting Your Files

### Step 1: Upload the joinery-docker.tar.gz to Your Server

From your local machine, upload the file to the blank server:

```bash
# Upload the archive
scp joinery-docker.tar.gz user@YOUR_SERVER:~/
```

Or if downloading from a URL:
```bash
# Download to server
ssh user@YOUR_SERVER
wget https://example.com/joinery-docker.tar.gz
```

### Step 2: Extract the Archive

On your blank server:

```bash
# Extract the joinery-docker archive
cd ~/
tar -xzf joinery-docker.tar.gz

# This creates joinery/ directory with:
# - config/              (configuration files)
# - public_html/         (application code)
# - uploads/             (user uploads directory)
# - logs/                (log files)
# - maintenance_scripts/ (scripts from /home/user1)
# - etc.

# Check what was extracted
ls -la ~/joinery/
# Should show:
# config/
# public_html/
# uploads/
# logs/
# static_files/
# cache/
# backups/
# maintenance_scripts/

# Verify the config directory exists
ls -la ~/joinery/config/
# Should show Globalvars_site.php
```

### Step 3: Verify Critical Scripts

The maintenance_scripts directory should contain scripts from /home/user1:

```bash
# Check for required scripts
ls -la ~/joinery/maintenance_scripts/
# Must contain at minimum:
# - server_setup.sh
# - deploy.sh (if needed for updates)

# If server_setup.sh is missing, STOP HERE
if [ ! -f ~/joinery/maintenance_scripts/server_setup.sh ]; then
    echo "ERROR: server_setup.sh not found in maintenance_scripts/"
    echo "The archive was not created properly."
    exit 1
fi
```

## Implementation Steps

### Step 1: Set Site Name and Organize Files

The SITENAME will be used by server_setup.sh to configure the installation:

```bash
# IMPORTANT: Set your site name (this gets passed to maintenance scripts)
# This determines /var/www/html/SITENAME/ and database name
SITENAME="YOUR_SITE_NAME_HERE"  # <-- CHANGE THIS!
export SITENAME  # Make it available to scripts

# The extracted joinery/ contains raw files without site directory
# We need to organize them under the site name for Docker
cd ~/
mkdir -p joinery-docker-build/$SITENAME
cd joinery-docker-build

# Move all extracted content into the site-named directory
mv ~/joinery/* $SITENAME/

# Copy server_setup.sh to build directory root
# Handle potential space in "maintenance scripts"
cp $SITENAME/maintenance*/server_setup.sh .

# Clean up maintenance scripts from site directory
rm -rf $SITENAME/maintenance*

# Verify structure is correct
ls -la ~/joinery-docker-build/
# Should show:
# YOUR_SITE_NAME/     (directory with your chosen name)
# server_setup.sh     (at root level for Docker)

# Verify site directory structure
ls ~/joinery-docker-build/$SITENAME/
# Should show: config/, public_html/, uploads/, logs/, etc.
```

### Important: Understanding the Docker Build Context

After organizing, your ~/joinery-docker-build/ directory should contain:
```
~/joinery-docker-build/             # Docker build context
├── Dockerfile                      # You'll create this
├── .dockerignore                   # You'll create this
├── server_setup.sh                 # Copied from maintenance scripts
└── YOUR_SITE_NAME/                 # Named by you (e.g., integralzen)
    ├── config/
    │   └── Globalvars_site.php    # Has database credentials
    ├── public_html/
    │   ├── serve.php               # Application entry point
    │   └── utils/
    │       └── update_database.php # Database migration tool
    ├── uploads/
    ├── logs/
    └── static_files/
```

**Critical:** The SITENAME you choose gets passed to server_setup.sh which uses it to:
- Create `/var/www/html/SITENAME/` directory
- Name the PostgreSQL database
- Configure Apache VirtualHost
- Set up all paths in the application

### Step 2: Create the Two Files

1. Create `Dockerfile` with the content from section 1 above
2. Create `.dockerignore` with the content from section 2 above

That's all the files you need!

### Step 3: Build the Container

```bash
# Build with site-specific image name (replace SITENAME with your site)
docker build -t joinery-SITENAME .
```

### Step 4: Choose Your Port

Since you might run multiple Joinery sites on one server, each needs a different port:

```bash
# First site: Port 8080
# Second site: Port 8081
# Third site: Port 8082
# etc.

# Or use a pattern:
# Production sites: 8000-8099
# Test sites: 8100-8199
# Demo sites: 8200-8299
```

**Tip:** Keep a list of which port belongs to which site:
```
8080 = integralzen
8081 = testclient
8082 = democompany
```

### Step 5: Run the Container

```bash
# Simple run (WARNING: Data lost on restart!)
# Replace SITENAME with your site name, PORT with your chosen port
docker run -d --name SITENAME -p PORT:80 joinery-SITENAME

# RECOMMENDED: Run with persistent storage for all data directories
# Replace SITENAME and PORT with your actual values!
# We include ALL directories that might benefit from persistence
docker run -d \
  --name SITENAME \
  -p PORT:80 \
  -p PORT_PLUS_1000:5432 \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  -v SITENAME_config:/var/www/html/SITENAME/config \
  -v SITENAME_backups:/var/www/html/SITENAME/backups \
  -v SITENAME_static:/var/www/html/SITENAME/static_files \
  -v SITENAME_logs:/var/www/html/SITENAME/logs \
  -v SITENAME_cache:/var/www/html/SITENAME/cache \
  -v SITENAME_vendor:/var/www/html/SITENAME/vendor \
  -v SITENAME_sessions:/var/lib/php/sessions \
  -v SITENAME_apache_logs:/var/log/apache2 \
  -v SITENAME_pg_logs:/var/log/postgresql \
  joinery-SITENAME

# Real example for site "integralzen" on port 8080:
docker run -d \
  --name integralzen \
  -p 8080:80 \
  -p 9080:5432 \
  -v integralzen_postgres:/var/lib/postgresql \
  -v integralzen_uploads:/var/www/html/integralzen/uploads \
  -v integralzen_config:/var/www/html/integralzen/config \
  -v integralzen_backups:/var/www/html/integralzen/backups \
  -v integralzen_static:/var/www/html/integralzen/static_files \
  -v integralzen_logs:/var/www/html/integralzen/logs \
  -v integralzen_cache:/var/www/html/integralzen/cache \
  -v integralzen_vendor:/var/www/html/integralzen/vendor \
  -v integralzen_sessions:/var/lib/php/sessions \
  -v integralzen_apache_logs:/var/log/apache2 \
  -v integralzen_pg_logs:/var/log/postgresql \
  joinery-integralzen
```

### Understanding the Volume Mounts (Data Directories)

**IMPORTANT:** We err on the side of including more directories for persistent storage. This ensures no data loss and preserves operational state across container restarts.

Each `-v` line creates persistent storage that survives container restarts:

#### Critical Volumes (Data Loss Without These)

| Volume | Directory | What Gets Preserved | Why It Matters |
|--------|-----------|-------------------|----------------|
| `SITENAME_postgres` | `/var/lib/postgresql` | PostgreSQL database files | **Critical:** All user data, settings, content, accounts |
| `SITENAME_uploads` | `.../uploads` | User uploaded files, images | **Critical:** User content, profile pics, documents, videos |
| `SITENAME_config` | `.../config` | Globalvars_site.php | **Critical:** Database credentials, site settings |

#### Important Volumes (Operational State)

| Volume | Directory | What Gets Preserved | Why It Matters |
|--------|-----------|-------------------|----------------|
| `SITENAME_backups` | `.../backups` | Database backups, migration backups | **Important:** Recovery data, rollback capability |
| `SITENAME_static` | `.../static_files` | Generated upgrade packages, exports | **Important:** System exports, upgrade files |
| `SITENAME_logs` | `.../logs` | Application error logs, access logs | **Important:** Debugging, audit trails (2.5GB+) |
| `SITENAME_cache` | `.../cache` | Compiled templates, static page cache | **Important:** Performance, avoids slow rebuilds |

#### Recommended Volumes (Convenience & Performance)

| Volume | Directory | What Gets Preserved | Why It Matters |
|--------|-----------|-------------------|----------------|
| `SITENAME_vendor` | `.../vendor` | Composer dependencies | **Recommended:** Preserves exact package versions |
| `SITENAME_sessions` | `/var/lib/php/sessions` | PHP session files | **Recommended:** Users stay logged in after restart |
| `SITENAME_apache_logs` | `/var/log/apache2` | Apache access/error logs | **Recommended:** Web server audit trail |
| `SITENAME_pg_logs` | `/var/log/postgresql` | PostgreSQL server logs | **Recommended:** Database diagnostics |

**Without these volumes:** Every container restart loses all this data!

### Directory Structure Reference

For reference, here is the complete directory structure showing which directories benefit from persistent storage:

```
/var/www/html/SITENAME/
├── backups/                    # ✅ PERSIST - Database/migration backups
│   └── field_migration_*/      #    Migration backup subdirectories
├── cache/                      # ✅ PERSIST - Runtime cache
│   └── static_pages/           #    Cached static page content
├── config/                     # ✅ PERSIST - Site configuration
│   └── Globalvars_site.php     #    Database credentials, settings
├── logs/                       # ✅ PERSIST - Application logs
│   ├── access.log              #    Apache access log
│   └── error.log               #    Apache error log (can be large)
├── maintenance_scripts/        # ❌ NO PERSIST - Comes with image
├── public_html/                # ❌ NO PERSIST - Application code in image
│   ├── adm/                    #    Admin interface
│   ├── ajax/                   #    AJAX endpoints
│   ├── api/                    #    REST API
│   ├── assets/                 #    Static assets
│   ├── data/                   #    Model classes
│   ├── docs/                   #    Documentation
│   ├── includes/               #    Core classes
│   ├── logic/                  #    Business logic
│   ├── migrations/             #    Database migrations
│   ├── plugins/                #    Plugin modules
│   ├── specs/                  #    Specifications
│   ├── tests/                  #    Test suites
│   ├── theme/                  #    Theme files
│   ├── utils/                  #    Utility scripts
│   └── views/                  #    View templates
├── static_files/               # ✅ PERSIST - Generated files
│   └── *.tar.gz, *.upg.zip     #    Upgrade packages, exports
├── uploads/                    # ✅ PERSIST - User uploaded content
│   ├── large/                  #    Large image variants
│   ├── lthumbnail/             #    Large thumbnails
│   ├── medium/                 #    Medium image variants
│   ├── small/                  #    Small image variants
│   ├── thumbnail/              #    Thumbnail images
│   ├── upgrades/               #    Uploaded upgrade files
│   └── *.jpg, *.pdf, *.mp4     #    Direct uploads
└── vendor/                     # ✅ PERSIST (optional) - Composer deps

System directories:
├── /var/lib/postgresql/        # ✅ PERSIST - PostgreSQL data files
├── /var/lib/php/sessions/      # ✅ PERSIST - PHP session data
├── /var/log/apache2/           # ✅ PERSIST (optional) - Apache logs
└── /var/log/postgresql/        # ✅ PERSIST (optional) - PG logs
```

### Simplified Version (Absolute Minimum)

If the full list seems overwhelming, this is the **absolute minimum** to avoid critical data loss:

```bash
docker run -d \
  --name SITENAME \
  -p PORT:80 \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  -v SITENAME_config:/var/www/html/SITENAME/config \
  joinery-SITENAME
```

This preserves only the most critical data (database, uploads, config). However, **we strongly recommend using the full volume list** above to preserve all operational state.

**What you lose with minimum volumes:**
- `backups/` - No database backup history
- `static_files/` - No generated upgrade packages
- `logs/` - No audit trail or debugging history (logs can reach 2.5GB+)
- `cache/` - Slower startup as cache rebuilds
- `vendor/` - May get different package versions on rebuild
- `sessions/` - Users logged out on restart
- System logs - No Apache/PostgreSQL log history

### Step 6: Access Your Application

Open browser to: `http://localhost:PORT` (using your chosen port)

For example:
- `http://localhost:8080` for integralzen
- `http://localhost:8081` for testclient
- `http://localhost:8082` for democompany

## Common Docker Commands

```bash
# Replace SITENAME with your actual site name in all commands

# Start container
docker start SITENAME

# Stop container
docker stop SITENAME

# View logs
docker logs SITENAME

# Access shell inside container
docker exec -it SITENAME bash

# Access PostgreSQL
docker exec -it SITENAME psql -U postgres SITENAME

# Restart Apache (after code changes)
docker exec SITENAME service apache2 reload

# Backup database
docker exec SITENAME pg_dump -U postgres SITENAME > backup.sql

# Remove container (keeps volumes/data)
docker stop SITENAME && docker rm SITENAME

# List all volumes for this site
docker volume ls | grep SITENAME

# Remove everything including ALL data (DANGER!)
docker stop SITENAME && docker rm SITENAME
docker volume rm $(docker volume ls -q | grep SITENAME)

# List all Joinery containers across all sites
docker ps --filter "name=integralzen|testclient|democompany"

# See what ports are in use
docker ps --format "table {{.Names}}\t{{.Ports}}"
```

## Summary

**Total new code: 23 lines**
- Dockerfile: 20 lines
- .dockerignore: 3 lines
- **NO script modifications**
- **NO docker-compose needed**
- **NO config changes needed**

**Reused code: Your entire 500+ line server_setup.sh UNCHANGED**

## Why This is the Simplest Approach

1. **No modifications to your script** - Use exactly as-is
2. **No docker-compose** - Just docker build and run
3. **No container orchestration** - Single container with everything
4. **No networking between containers** - PostgreSQL and Apache together
5. **Exactly like your current server** - Everything in one place

This is literally your server in a box!

## Managing Multiple Joinery Sites

### Port Management Strategy

When running multiple sites, you need a port management strategy:

**Option 1: Sequential Ports**
```bash
integralzen:    8080 (web), 9080 (postgres)
testclient:     8081 (web), 9081 (postgres)
democompany:    8082 (web), 9082 (postgres)
```

**Option 2: Port Ranges by Environment**
```bash
Production:  8000-8099 (web), 9000-9099 (postgres)
Testing:     8100-8199 (web), 9100-9199 (postgres)
Demo:        8200-8299 (web), 9200-9299 (postgres)
```

**Option 3: Use a Reverse Proxy (Nginx)**
Run all containers on different ports, but access them via domains:
- integralzen.example.com → localhost:8080
- testclient.example.com → localhost:8081
- demo.example.com → localhost:8082

### Keeping Track of Sites

Create a management file `~/joinery-sites.txt`:
```
Site         Port  Status   Directory
=========================================
integralzen  8080  running  ~/joinery-docker-integralzen
testclient   8081  running  ~/joinery-docker-testclient
democompany  8082  stopped  ~/joinery-docker-democompany
```

### Quick Management Script

Create `~/manage-joinery.sh`:
```bash
#!/bin/bash
case "$1" in
  list)
    docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    ;;
  start-all)
    docker start integralzen testclient democompany
    ;;
  stop-all)
    docker stop integralzen testclient democompany
    ;;
  *)
    echo "Usage: $0 {list|start-all|stop-all}"
    ;;
esac
```

### Example: Setting Up Three Sites

```bash
# Site 1: integralzen (port 8080)
cd ~/joinery-docker-integralzen
docker build -t joinery-integralzen .
docker run -d --name integralzen -p 8080:80 [volumes] joinery-integralzen

# Site 2: testclient (port 8081)
cd ~/joinery-docker-testclient
docker build -t joinery-testclient .
docker run -d --name testclient -p 8081:80 [volumes] joinery-testclient

# Site 3: democompany (port 8082)
cd ~/joinery-docker-democompany
docker build -t joinery-democompany .
docker run -d --name democompany -p 8082:80 [volumes] joinery-democompany

# Check all are running
docker ps | grep joinery
```

## Troubleshooting tar.gz Extraction

### Problem: "tar: Cannot open: No such file or directory"
**Solution:** Make sure the tar.gz files were uploaded successfully:
```bash
ls -la ~/*.tar.gz
```

### Problem: Extracted files have wrong structure
Some tar files are created with different directory structures:
```bash
# Check what's inside before extracting
tar -tzf joinerytest.tar.gz | head -20

# If files are nested differently, extract and reorganize:
tar -xzf joinerytest.tar.gz
# Then move the files to the expected location
```

### Problem: "server_setup.sh not found"
The maintenance scripts might extract to unexpected locations:
```bash
# Find it
find ~/ -name "server_setup.sh" -type f

# Common patterns:
# - Might have spaces: "maintenance scripts/server_setup.sh"
# - Might be nested: "joinery/joinery/maintenance_scripts/server_setup.sh"
# - Might be flat: "server_setup.sh" directly in ~/
```

### Creating joinery-docker.tar.gz (For Reference)
The joinery-docker.tar.gz file is created by publish_upgrade.php. When extracted, it contains:
```
joinery/
├── config/               # Site configuration
├── public_html/          # Application code
├── uploads/              # User uploads
├── logs/                 # Log files
├── static_files/         # Generated files
├── cache/                # Cache directory
├── backups/              # Backup files
└── maintenance_scripts/  # Setup and maintenance scripts
    └── server_setup.sh

Note: The extracted directories do NOT include a site name directory.
The user chooses the site name when setting up Docker.
```

### Required Changes

See separate specification: **[Archive Structure Changes](/specs/archive_structure.md)**

This specification covers the required modifications to:
- publish_upgrade.php (create Docker-compatible archives)
- upgrade.php (handle new archive structure)
- server_setup.sh (remove generic composer.json, add conditional composer install)

These changes should be implemented first before proceeding with Docker deployment.

## Composer Configuration for Docker

### 1. Use Environment-Aware Vendor Directory

To ensure compatibility between traditional deployments and Docker containers, update the project's `composer.json` to use a relative vendor directory path:

```json
{
    "name": "joinery/platform",
    "description": "Joinery membership and event management platform",
    "type": "project",
    "require": {
        "php": ">=7.4",
        "mailgun/mailgun-php": "^3.2",
        "kriswallsmith/buzz": "^1.2",
        "nyholm/psr7": "^1.3",
        "jhut89/mailchimp3php": "^3.2",
        "verot/class.upload.php": "^2.1",
        "stripe/stripe-php": "^10.16",
        "phpmailer/phpmailer": "^6.10.0"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true
        },
        "vendor-dir": "../vendor"
    }
}
```

This places the vendor directory at `/var/www/html/SITENAME/vendor/`, which works in both:
- **Traditional deployments**: Vendor directory outside public_html
- **Docker containers**: Vendor directory at a predictable location
- **Multiple sites**: Each site has its own isolated vendor directory

### 2. Volume Mount for Vendor Directory (Included by Default)

The vendor directory is now included in our recommended persistent volume list. This ensures composer dependencies are preserved between container rebuilds:

```bash
# Vendor is already included in the recommended full volume list:
-v SITENAME_vendor:/var/www/html/SITENAME/vendor
```

**Benefits of vendor volume:**
- Faster container rebuilds (dependencies cached)
- Preserves exact package versions during updates
- Reduces bandwidth usage for package downloads
- Consistent behavior across container restarts

**Note:** The recommended docker run command in Step 5 already includes all volumes including vendor.

### 3. Database Setting for Composer Autoload

The `composerAutoLoad` database setting needs to point to the correct vendor directory. In your `joinery-install.sql.gz` or migration scripts, ensure this setting uses the relative path:

```sql
-- Update or insert the composerAutoLoad setting
INSERT INTO stg_settings (stg_name, stg_value, stg_create_time)
VALUES ('composerAutoLoad', '../vendor/', NOW())
ON CONFLICT (stg_name)
DO UPDATE SET stg_value = '../vendor/';
```

This relative path works correctly in both Docker and traditional deployments because:
- It's relative to the public_html directory
- PathHelper will resolve it correctly
- No hardcoded paths that break between environments

### Example: Real Installation
If your site is called "integralzen":
```bash
# You receive: joinery-docker.tar.gz
# Extract it:
tar -xzf joinery-docker.tar.gz
cd joinery-docker

# Contents:
# integralzen/         (your site)
# maintenance scripts/ (setup scripts)

# In Dockerfile, you'd set:
ENV SITENAME=integralzen

# Volume mounts would use:
-v integralzen_uploads:/var/www/html/integralzen/uploads
```

## Optional Improvement: Explicit Composer Installation in Dockerfile

While the basic Dockerfile runs server_setup.sh which handles composer installation, you may want more explicit control over the composer installation process, especially after Phase 2 changes are implemented.

### Enhanced Dockerfile with Explicit Composer Step

This optional enhancement adds a dedicated composer installation step after server_setup.sh:

```dockerfile
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV SITENAME=CHANGE_ME_TO_YOUR_SITENAME

# Copy application and scripts
COPY ${SITENAME}/ /var/www/html/${SITENAME}/
COPY server_setup.sh /tmp/server_setup.sh

ENV POSTGRES_PASSWORD=CHANGE_ME_TO_YOUR_PASSWORD

# Run setup script
RUN chmod +x /tmp/server_setup.sh && \
    /tmp/server_setup.sh && \
    rm /tmp/server_setup.sh

# OPTIONAL: Explicit composer dependency installation
# This ensures composer dependencies are installed even if
# server_setup.sh doesn't handle it (useful after Phase 2)
RUN if [ -f /var/www/html/${SITENAME}/public_html/composer.json ]; then \
        cd /var/www/html/${SITENAME}/public_html && \
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader && \
        chown -R www-data:www-data /var/www/html/${SITENAME}/vendor && \
        echo "Composer dependencies installed successfully"; \
    else \
        echo "No composer.json found, skipping dependency installation"; \
    fi

# Start services
CMD service postgresql start && \
    sleep 5 && \
    su -c "psql -c \"ALTER USER postgres PASSWORD '$POSTGRES_PASSWORD';\"" postgres && \
    su -c "psql -c \"CREATE DATABASE ${SITENAME} OWNER postgres;\"" postgres || true && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php && \
    apache2ctl -D FOREGROUND
```

**Benefits of this approach:**
- Guarantees composer dependencies are installed
- Works regardless of server_setup.sh modifications
- Clear visibility of composer installation in Docker build logs
- Proper ownership set for vendor directory

**When to use this:**
- After implementing Phase 2 composer changes
- When you want explicit control over composer installation
- For debugging composer-related issues
- When building production images

**Note:** This is completely optional. The basic approach of letting server_setup.sh handle everything works fine, especially if you've implemented the Phase 2 changes that improve composer handling.

## Making Sites Accessible Without Port Numbers

When running multiple containerized sites, users would normally need to type port numbers (e.g., `site.com:8080`). Use a reverse proxy on the host to route domains to container ports.

### Recommended: Apache Reverse Proxy

**Why Apache?** Since Joinery already uses Apache inside containers, you maintain a consistent technology stack. For typical Joinery deployments with a handful of sites, Apache performs excellently as a reverse proxy.

#### Step 1: Install Apache on the Host

```bash
# Install Apache on the host (not in Docker)
sudo apt-get update && sudo apt-get install -y apache2

# Enable required modules for reverse proxy
sudo a2enmod proxy proxy_http proxy_wstunnel headers ssl rewrite

# Restart Apache to load modules
sudo systemctl restart apache2
```

#### Step 2: Create VirtualHost Configuration

Create a configuration file for each site. Example for "integralzen":

**File:** `/etc/apache2/sites-available/integralzen.conf`

```apache
<VirtualHost *:80>
    ServerName integralzen.org
    ServerAlias www.integralzen.org

    # Redirect all HTTP to HTTPS (uncomment after SSL is configured)
    # RewriteEngine On
    # RewriteCond %{HTTPS} off
    # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Proxy settings
    ProxyPreserveHost On
    ProxyRequests Off

    # Pass requests to Docker container on port 8080
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/

    # Pass real client IP to the container
    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"

    # Timeout settings for long-running requests
    ProxyTimeout 300

    # Error and access logs (optional, container has its own logs)
    ErrorLog ${APACHE_LOG_DIR}/integralzen-error.log
    CustomLog ${APACHE_LOG_DIR}/integralzen-access.log combined
</VirtualHost>
```

#### Step 3: Enable the Site

```bash
# Enable the site configuration
sudo a2ensite integralzen

# Test configuration for syntax errors
sudo apache2ctl configtest

# Reload Apache to apply changes
sudo systemctl reload apache2
```

Now users can access `http://integralzen.org` and Apache routes to the container on port 8080.

#### Step 4: Add SSL with Let's Encrypt (Recommended for Production)

```bash
# Install Certbot for Apache
sudo apt-get install -y certbot python3-certbot-apache

# Obtain SSL certificate (automatically configures Apache)
sudo certbot --apache -d integralzen.org -d www.integralzen.org

# Certbot will:
# 1. Obtain the certificate
# 2. Create /etc/apache2/sites-available/integralzen-le-ssl.conf
# 3. Configure automatic HTTP to HTTPS redirect
# 4. Set up auto-renewal via systemd timer
```

After Certbot runs, your SSL configuration will look like:

**File:** `/etc/apache2/sites-available/integralzen-le-ssl.conf` (auto-generated)

```apache
<VirtualHost *:443>
    ServerName integralzen.org
    ServerAlias www.integralzen.org

    # SSL Configuration (managed by Certbot)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/integralzen.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/integralzen.org/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf

    # Proxy settings
    ProxyPreserveHost On
    ProxyRequests Off

    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/

    # Pass real client IP and protocol to container
    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "https"

    ProxyTimeout 300

    ErrorLog ${APACHE_LOG_DIR}/integralzen-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/integralzen-ssl-access.log combined
</VirtualHost>
```

#### Step 5: Verify SSL Auto-Renewal

```bash
# Test the renewal process (dry run)
sudo certbot renew --dry-run

# Check the systemd timer for auto-renewal
sudo systemctl status certbot.timer
```

Certificates auto-renew before expiry (typically every 60-90 days).

### Managing Multiple Sites

For each additional site, repeat Steps 2-4 with different:
- Configuration filename (e.g., `testclient.conf`)
- ServerName/ServerAlias values
- Container port number (e.g., 8081, 8082)

**Example port mapping:**
```
integralzen.org  → localhost:8080 → integralzen container
testclient.org   → localhost:8081 → testclient container
democompany.org  → localhost:8082 → democompany container
```

**Quick setup script for a new site:**
```bash
#!/bin/bash
# Usage: ./add-site.sh sitename domain port

SITENAME=$1
DOMAIN=$2
PORT=$3

cat > /etc/apache2/sites-available/${SITENAME}.conf << EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:${PORT}/
    ProxyPassReverse / http://127.0.0.1:${PORT}/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"

    ProxyTimeout 300

    ErrorLog \${APACHE_LOG_DIR}/${SITENAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITENAME}-access.log combined
</VirtualHost>
EOF

a2ensite ${SITENAME}
apache2ctl configtest && systemctl reload apache2
echo "Site ${SITENAME} configured. Run: sudo certbot --apache -d ${DOMAIN} -d www.${DOMAIN}"
```

### Troubleshooting Apache Reverse Proxy

**Issue: 503 Service Unavailable**
```bash
# Check if container is running
docker ps | grep SITENAME

# Check if container port is accessible
curl -I http://localhost:8080

# Check Apache error logs
tail -50 /var/log/apache2/SITENAME-error.log
```

**Issue: Mixed Content Warnings (HTTPS)**

If your site loads over HTTPS but has mixed content warnings, ensure `X-Forwarded-Proto` is set correctly. The application should detect this header and generate HTTPS URLs.

**Issue: WebSocket connections failing**

If your application uses WebSockets, ensure `proxy_wstunnel` is enabled and add:
```apache
RewriteEngine On
RewriteCond %{HTTP:Upgrade} websocket [NC]
RewriteCond %{HTTP:Connection} upgrade [NC]
RewriteRule ^/?(.*) ws://127.0.0.1:8080/$1 [P,L]
```

**Issue: Large file uploads timing out**

Increase proxy timeout and add request body limits:
```apache
ProxyTimeout 600
LimitRequestBody 104857600  # 100MB
```

### Alternative: Nginx Reverse Proxy

If you prefer Nginx (slightly better performance for high-traffic sites):

```bash
sudo apt-get update && sudo apt-get install -y nginx
```

**File:** `/etc/nginx/sites-available/integralzen`
```nginx
server {
    listen 80;
    server_name integralzen.org www.integralzen.org;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
        proxy_read_timeout 300;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/integralzen /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d integralzen.org -d www.integralzen.org
```

### Why We Recommend Apache Over Nginx

For Joinery deployments:
- **Consistency** - Same technology as your application containers
- **Familiarity** - One configuration syntax to learn
- **Adequate Performance** - For dozens of sites, Apache handles the load fine
- **Simpler Maintenance** - One technology stack to update and secure

Choose Nginx only if you're already familiar with it or expect very high traffic (1000+ concurrent users).

## Troubleshooting Docker Deployment

### Issue: "Composer autoload not found" or "Vendor directory missing"

**For fresh installs:** This shouldn't happen - the install SQL has the correct path. Check that composer dependencies were installed during the build.

**For migrated sites:** See [Appendix: Migrating Existing Sites to Docker](#appendix-migrating-existing-sites-to-docker).

### Issue: Docker build succeeds but container won't start

**Check logs:** `docker logs {SITENAME}`

**Common problems:**
1. PostgreSQL didn't start properly - wait 10+ seconds, increase sleep time in CMD
2. Database creation failed - check POSTGRES_PASSWORD matches Globalvars_site.php
3. update_database.php failed - check PHP syntax and database connectivity

**Solution:**
```bash
# Access container shell
docker exec -it {SITENAME} bash

# Check PostgreSQL status
service postgresql status

# Check Apache status
apache2ctl -t

# Check PHP syntax
php -l /var/www/html/{SITENAME}/public_html/utils/update_database.php

# View Apache error logs
tail -50 /var/log/apache2/error.log
```

### Issue: Application loads but shows "Vendor autoload not found"

**Cause:** Composer dependencies not installed in container

**Solution:**
1. Verify composer.json exists: `/var/www/html/{SITENAME}/public_html/composer.json`
2. Install manually: `docker exec {SITENAME} bash -c "cd /var/www/html/{SITENAME}/public_html && composer install"`
3. Or rebuild container to include dependencies in image

### Issue: Database migrations failing in Docker

**Common cause:** update_database.php can't find migrations

**Debug:**
```bash
# Inside container
php -d display_errors=1 /var/www/html/{SITENAME}/public_html/utils/update_database.php
```

**Check migration file exists:**
```bash
docker exec {SITENAME} ls -la /var/www/html/{SITENAME}/public_html/migrations/
```

### Issue: Persistent data lost after container restart

**Cause:** Volumes not properly mounted

**Solution:** Always use `-v` flags when running container:
```bash
docker run -d \
  --name {SITENAME} \
  -v {SITENAME}_postgres:/var/lib/postgresql \
  -v {SITENAME}_uploads:/var/www/html/{SITENAME}/uploads \
  {other-options}
```

**To verify volumes are mounted:**
```bash
docker inspect {SITENAME} | grep -A 20 Mounts
```

---

## Appendix: Installing Docker on a Blank Server

If Docker isn't installed on your blank server:

### Ubuntu/Debian:
```bash
# Update packages
sudo apt-get update

# Install prerequisites
sudo apt-get install -y ca-certificates curl gnupg lsb-release

# Add Docker's GPG key
sudo mkdir -m 0755 -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

# Add Docker repository
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io

# Add your user to docker group (to avoid using sudo)
sudo usermod -aG docker $USER

# Log out and back in for group change to take effect
echo "Log out and log back in, then Docker is ready!"
```

### Test Docker:
```bash
docker --version
# Should show: Docker version X.X.X

docker run hello-world
# Should show: Hello from Docker!
```

---

## Appendix: Migrating Existing Sites to Docker

This section applies only when migrating an **existing** Joinery site to Docker. Fresh installations do not need these steps.

### composerAutoLoad Setting Migration

Existing sites may have the `composerAutoLoad` database setting pointing to `/home/user1/vendor/` instead of the Docker-compatible `../vendor/` path.

**Why this matters:** The old path (`/home/user1/vendor/`) won't exist inside Docker containers, causing "vendor autoload not found" errors.

**Background:** Migration 0.68 in `/migrations/migrations.php` (lines 925-932) is intentionally disabled to prevent automatic changes to this critical path setting on production sites. The migration test always returns 1, skipping execution:

```php
'test' => "SELECT 1 as count"  // Intentionally disabled
```

### Manual Migration Steps

After deploying an existing site to Docker, run the following command to update the setting:

```bash
docker exec -it {SITENAME} psql -U postgres -d {SITENAME} -c "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';"
```

Verify the update:
```bash
docker exec -it {SITENAME} psql -U postgres -d {SITENAME} -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'composerAutoLoad';"
```

Expected output: `../vendor/`

### Other Migration Considerations

When migrating existing sites:

1. **Database backup:** Export your database before migration
2. **Uploads directory:** Copy all files from the existing uploads directory
3. **Config file:** Update `Globalvars_site.php` if paths need adjustment
4. **Test thoroughly:** Verify all functionality before switching production traffic
