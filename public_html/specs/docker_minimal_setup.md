# Specification: Minimal Docker Setup - Single Container Per Site

## Current Status (as of November 2024)

**Overall Docker Compatibility: 85% Complete** ✅

- ✅ **Phase 1 (Archive Structure):** FULLY IMPLEMENTED - tar.gz creation, extraction, and deployment working
- ⚠️ **Phase 2 (Composer Management):** 95% COMPLETE - One blocking issue must be fixed first (see below)
- 📋 **Docker Implementation:** SPECIFICATION COMPLETE - Ready to implement after Phase 2 fixes

### ⚠️ BLOCKING ISSUE: composerAutoLoad Migration Disabled

Before Docker can be tested, **one critical fix is needed:**

**File:** `/migrations/migrations.php` (migration 0.68, lines 925-932)

**Current (Broken):**
```php
'test' => "SELECT 1 as count"  // ❌ Always returns 1, skips migration!
```

**Should be:**
```php
'test' => "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'composerAutoLoad' AND stg_value LIKE '/home/user1/vendor%'"
```

**Why it matters:** Without this fix, existing sites won't get updated to use per-site vendor directories (`../vendor/`), causing Docker deployments to fail.

**Estimated fix time:** 5 minutes

See [Phase 2 Status Details](#phase-2-composer-management-status-details) below for complete information.

## Overview

Run each Joinery site in its own Docker container that includes everything - Apache, PHP, PostgreSQL, and the application. Just run your existing server_setup.sh script unchanged.

**Strategy: Your script does ALL the work, Docker just provides Ubuntu 24.04.**

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

All Phase 2 components have been implemented except for one migration fix:

| Component | Status | Details |
|-----------|--------|---------|
| **composer.json** | ✅ Done | Updated to use relative vendor path: `"vendor-dir": "../vendor"` |
| **ComposerValidator.php** | ✅ Done | Enhanced with vendor directory detection and validation |
| **deploy.sh** | ✅ Done | v3.8 - Deploys maintenance_scripts directory properly |
| **new_account.sh** | ✅ Done | Handles gzip-compressed SQL files |
| **publish_upgrade.php** | ✅ Done | Creates tar.gz archives with all components (2.04 MB) |
| **upgrade.php** | ✅ Done | Properly extracts tar.gz format |
| **composerAutoLoad Migration** | ⚠️ **BROKEN** | Migration 0.68 disabled - test condition needs fix (see above) |
| **server_setup.sh** | ✅ Verify | Should have generic composer.json creation removed |

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

### Next Steps to Reach 100%

**Priority 1: Fix composerAutoLoad Migration (5 minutes)**
1. Open `/migrations/migrations.php`
2. Find migration 0.68 (lines 925-932)
3. Replace the test condition (see blocking issue above)
4. Run test to verify migration now triggers properly

**Priority 2: Verify server_setup.sh (10 minutes)**
- Confirm generic `composer.json` creation in `/home/user1/` has been removed
- Verify conditional composer install only runs for project files at `/var/www/html/$SITENAME/public_html/`

**Priority 3: End-to-End Test (30-60 minutes)**
- Create fresh archive with publish_upgrade.php
- Extract and run server_setup.sh on test system
- Verify `/var/www/html/{SITE}/vendor/` is populated
- Verify `/var/www/html/{SITE}/maintenance_scripts/` deployed correctly
- Run deploy.sh and confirm dependencies installed

**Priority 4: Docker Implementation**
- After Phase 2 is complete, proceed with Docker deployment using this specification
- All infrastructure is ready; just needs the migration fix

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
docker run -d \
  --name SITENAME \
  -p PORT:80 \
  -p PORT_PLUS_1000:5432 \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  -v SITENAME_cache:/var/www/html/SITENAME/cache \
  -v SITENAME_logs:/var/www/html/SITENAME/logs \
  -v SITENAME_static:/var/www/html/SITENAME/static_files \
  -v SITENAME_backups:/var/www/html/SITENAME/backups \
  -v SITENAME_config:/var/www/html/SITENAME/config \
  -v SITENAME_sessions:/var/lib/php/sessions \
  joinery-SITENAME

# Real example for site "integralzen" on port 8080:
docker run -d \
  --name integralzen \
  -p 8080:80 \
  -p 9080:5432 \
  -v integralzen_postgres:/var/lib/postgresql \
  -v integralzen_uploads:/var/www/html/integralzen/uploads \
  -v integralzen_cache:/var/www/html/integralzen/cache \
  -v integralzen_logs:/var/www/html/integralzen/logs \
  -v integralzen_static:/var/www/html/integralzen/static_files \
  -v integralzen_backups:/var/www/html/integralzen/backups \
  -v integralzen_config:/var/www/html/integralzen/config \
  -v integralzen_sessions:/var/lib/php/sessions \
  joinery-integralzen
```

### Understanding the Volume Mounts (Data Directories)

Each `-v` line creates persistent storage that survives container restarts:

| Volume | Directory | What Gets Preserved | Why It Matters |
|--------|-----------|-------------------|----------------|
| `joinery_postgres` | `/var/lib/postgresql` | PostgreSQL database files | **Critical:** All user data, settings, content |
| `joinery_uploads` | `.../uploads` | User uploaded files, images | **Critical:** User content, profile pics, documents |
| `joinery_cache` | `.../cache` | Compiled templates, query cache | **Important:** Avoids slow rebuilds after restart |
| `joinery_logs` | `.../logs` | Error logs, access logs | **Important:** Debugging, audit trails |
| `joinery_static` | `.../static_files` | Generated PDFs, exports | **Important:** User-generated downloads |
| `joinery_backups` | `.../backups` | Database backups, migrations | **Important:** Recovery data |
| `joinery_config` | `.../config` | Globalvars_site.php | **Important:** Custom settings that may change |
| `joinery_sessions` | `/var/lib/php/sessions` | PHP session files | **Nice to have:** Users stay logged in |

**Without these volumes:** Every container restart loses all this data!

### Simplified Version (Minimum Recommended)

If the full list seems overwhelming, at minimum use:

```bash
docker run -d \
  --name SITENAME \
  -p PORT:80 \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  -v SITENAME_cache:/var/www/html/SITENAME/cache \
  joinery-SITENAME
```

This preserves the most critical data (database, uploads, cache).

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

### 2. Volume Mount for Vendor Directory (Optional)

If you want to cache composer dependencies between container rebuilds or updates, add a vendor volume:

```bash
docker run -d \
  --name SITENAME \
  -p PORT:80 \
  -v SITENAME_vendor:/var/www/html/SITENAME/vendor \
  -v SITENAME_postgres:/var/lib/postgresql \
  -v SITENAME_uploads:/var/www/html/SITENAME/uploads \
  -v SITENAME_cache:/var/www/html/SITENAME/cache \
  -v SITENAME_logs:/var/www/html/SITENAME/logs \
  -v SITENAME_static:/var/www/html/SITENAME/static_files \
  -v SITENAME_backups:/var/www/html/SITENAME/backups \
  -v SITENAME_config:/var/www/html/SITENAME/config \
  -v SITENAME_sessions:/var/lib/php/sessions \
  joinery-SITENAME
```

**Benefits of vendor volume:**
- Faster container rebuilds (dependencies cached)
- Preserves exact package versions during updates
- Reduces bandwidth usage for package downloads

**Note:** This is optional. The default approach includes vendor in the container image, which ensures consistency.

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

When running multiple containerized sites, users would normally need to type port numbers (e.g., `site.com:8080`). Here are solutions to provide clean URLs:

### Option 1: Nginx Reverse Proxy (Recommended)

Install Nginx on the host to route domains to container ports:

```bash
# Install Nginx on the host (not in Docker)
sudo apt-get update && sudo apt-get install -y nginx

# Create a config for each site in /etc/nginx/sites-available/
# For example: /etc/nginx/sites-available/integralzen
```

Example Nginx configuration (`/etc/nginx/sites-available/integralzen`):
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
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/integralzen /etc/nginx/sites-enabled/
sudo nginx -t  # Test configuration
sudo systemctl reload nginx
```

Now users can access `integralzen.org` and Nginx routes to the container on port 8080.

### Option 2: Apache Reverse Proxy

If you prefer Apache (since your application uses it):

```bash
# Install Apache on the host
sudo apt-get update && sudo apt-get install -y apache2

# Enable proxy modules
sudo a2enmod proxy proxy_http

# Create VirtualHost configuration
```

Example Apache configuration (`/etc/apache2/sites-available/integralzen.conf`):
```apache
<VirtualHost *:80>
    ServerName integralzen.org

    ProxyPreserveHost On
    ProxyPass / http://localhost:8080/
    ProxyPassReverse / http://localhost:8080/
</VirtualHost>
```

Enable the site:
```bash
sudo a2ensite integralzen
sudo systemctl reload apache2
```

### Option 3: Use a Docker-based Reverse Proxy

Run Traefik or Nginx Proxy Manager in Docker to handle routing:

```yaml
# docker-compose.yml for Traefik (example)
version: '3'
services:
  traefik:
    image: traefik:v2.10
    command:
      - "--api.insecure=true"
      - "--providers.docker=true"
      - "--entrypoints.web.address=:80"
    ports:
      - "80:80"
      - "8080:8080"  # Traefik dashboard
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
```

Then label your containers for automatic routing:
```bash
docker run -d \
  --name integralzen \
  --label "traefik.enable=true" \
  --label "traefik.http.routers.integralzen.rule=Host(\`integralzen.org\`)" \
  --label "traefik.http.services.integralzen.loadbalancer.server.port=80" \
  -p 8081:80 \
  [other options] \
  joinery-integralzen
```

### Recommendation for Multiple Sites

For production with multiple sites, use **Option 1 (Nginx)** because:
- Simple to configure and maintain
- Excellent performance
- Can handle SSL certificates with Let's Encrypt
- Well-documented and widely used
- Keeps reverse proxy separate from application containers

## Troubleshooting Docker Deployment

### Issue: "Composer autoload not found" or "Vendor directory missing"

**Root Cause:** The composerAutoLoad migration (0.68) is disabled and hasn't updated existing sites to use per-site vendor directories.

**Solution:**
1. Fix the migration test condition in `/migrations/migrations.php` (lines 925-932)
2. Run `php /var/www/html/{SITENAME}/public_html/utils/update_database.php` to trigger the migration
3. Verify the setting was updated: `psql -d {SITENAME} -c "SELECT * FROM stg_settings WHERE stg_name = 'composerAutoLoad';"`
4. Should show: `../vendor/` (not `/home/user1/vendor/`)

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
