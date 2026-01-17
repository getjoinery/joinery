# Joinery Installation Guide

This guide covers deploying Joinery on both Docker containers and bare-metal servers, from a blank server to a fully functional site.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Example Workflows](#example-workflows)
3. [Auto-Detection Behavior](#auto-detection-behavior)
4. [Prerequisites](#prerequisites)
5. [Docker Deployment](#docker-deployment-detailed)
6. [Bare-Metal Deployment](#bare-metal-deployment-detailed)
7. [Site Management](#site-management)
8. [Maintenance Operations](#maintenance-operations)
9. [Troubleshooting](#troubleshooting)
10. [Quick Reference](#quick-reference)
11. [Script Reference](#script-reference)

---

## Quick Start

### Docker Deployment

```bash
# 1. Transfer and extract the archive
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
ssh root@YOUR_SERVER
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# 2. Install Docker (one-time)
sudo ./install.sh docker

# 3. Create your site
sudo ./install.sh site mysite SecurePass123! mysite.com 8080
```

### Bare-Metal Deployment

```bash
# 1. Transfer and extract the archive
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
ssh root@YOUR_SERVER
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# 2. Set up server (one-time)
sudo ./install.sh server

# 3. Create your site
sudo ./install.sh site mysite SecurePass123! mysite.com
```

---

## Example Workflows

### Multi-Site Docker Deployment

```bash
# 1. One-time: Install Docker on fresh server
./install.sh docker

# 2. Create sites (each in its own container)
./install.sh site site1 SecurePass1! site1.com 8080
./install.sh site site2 SecurePass2! site2.com 8081
./install.sh site site3 SecurePass3! site3.com 8082

# 3. View all running sites
./install.sh list
```

### Multi-Site Bare-Metal Deployment

```bash
# 1. One-time: Set up server (Apache, PHP, PostgreSQL)
./install.sh server

# 2. Create sites (each in /var/www/html/{sitename}/)
./install.sh site site1 SecurePass1! site1.com
./install.sh site site2 SecurePass2! site2.com
./install.sh site site3 SecurePass3! site3.com

# 3. View all sites
./install.sh list
```

---

## Auto-Detection Behavior

`install.sh site` automatically detects the environment:

| Environment | Result |
|-------------|--------|
| Docker installed and running | Creates Docker container |
| Docker not present | Creates bare-metal site via `new_account.sh` |

**The PORT parameter signals intent:**
- With port → Docker mode (port required for container mapping)
- Without port → Bare-metal mode (Apache virtualhost handles routing)

**Force a specific mode:**
```bash
./install.sh site --docker mysite Pass123 mysite.com 8080
./install.sh site --bare-metal mysite Pass123 mysite.com
```

---

## Prerequisites

### Required Files
- `joinery-X-Y.tar.gz` - The Joinery archive containing:
  - `public_html/` - Application code
  - `config/` - Configuration templates
  - `maintenance_scripts/install_tools/` - Setup scripts, Dockerfile.template
  - `maintenance_scripts/sysadmin_tools/` - Backup, restore, and maintenance utilities

### Server Requirements
- Fresh Ubuntu 24.04 LTS installation
- Root access
- At least 4GB RAM
- At least 10GB disk space
- For Docker: Ports 8080+ available (or your chosen port range)
- For Bare-metal: Ports 80/443 available

---

## Docker Deployment (Detailed)

### One-Time Docker Setup

```bash
sudo ./install.sh docker
```

This command:
- Checks if Docker is already installed
- Installs Docker CE if missing
- Starts Docker daemon
- Verifies Docker is operational

### Creating Sites

```bash
sudo ./install.sh site SITENAME POSTGRES_PASSWORD [DOMAIN_NAME] [PORT]
```

**Parameters:**

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `SITENAME` | Yes | - | Site/database name (e.g., `mysite`) |
| `POSTGRES_PASSWORD` | Yes | - | Database password |
| `DOMAIN_NAME` | No | Server IP | Domain for VirtualHost |
| `PORT` | No | 8080 | Host port for web traffic |

**What the script does:**
1. Validates parameters and archive structure
2. Checks port availability (detects conflicts, suggests alternatives)
3. Prepares an isolated build context
4. Builds the Docker image
5. Starts the container with all persistent volumes
6. Verifies the site is responding
7. Cleans up build directory
8. Displays access information and all running containers

### Multi-Site Support

The script fully supports running multiple sites on the same server:

- **Port conflict detection**: Automatically checks if ports are in use
- **Port suggestions**: Offers next available port if conflict detected
- **Site isolation**: Each site uses completely isolated build context and volumes

```bash
# First site (uses port 8080)
./install.sh site site1 YOUR_PASSWORD_1 site1.com 8080

# Second site (uses port 8081)
./install.sh site site2 YOUR_PASSWORD_2 site2.com 8081

# Check what's running
./install.sh list
```

### Port Management

Each site needs unique ports. The script automatically checks and suggests available ports:

| Site | Web Port | Database Port |
|------|----------|---------------|
| site1 | 8080 | 9080 |
| site2 | 8081 | 9081 |
| site3 | 8082 | 9082 |

### Volume Mounts

| Volume | Container Path | Purpose |
|--------|----------------|---------|
| `{site}_postgres` | `/var/lib/postgresql` | Database files |
| `{site}_uploads` | `.../uploads` | User uploaded files |
| `{site}_config` | `.../config` | Site configuration |
| `{site}_backups` | `.../backups` | Database backups |
| `{site}_static` | `.../static_files` | Generated files |
| `{site}_logs` | `.../logs` | Application logs |
| `{site}_cache` | `.../cache` | Runtime cache |
| `{site}_sessions` | `/var/lib/php/sessions` | PHP sessions |
| `{site}_apache_logs` | `/var/log/apache2` | Apache logs |
| `{site}_pg_logs` | `/var/log/postgresql` | PostgreSQL logs |

---

## Bare-Metal Deployment (Detailed)

### One-Time Server Setup

```bash
sudo ./install.sh server
```

This command installs and configures:
- PHP 8.3 with all required extensions
- Apache web server with mod_rewrite
- PostgreSQL database server
- Composer
- Certbot for SSL certificates
- UFW firewall
- fail2ban
- SSH security hardening
- Automatic security updates

### Creating Sites

```bash
sudo ./install.sh site SITENAME POSTGRES_PASSWORD DOMAIN_NAME
```

**What happens:**
1. Verifies server prerequisites (Apache, PHP, PostgreSQL)
2. Calls `new_account.sh` to create:
   - Site directory structure at `/var/www/html/{sitename}/`
   - PostgreSQL database
   - Apache VirtualHost configuration
   - Test site at `/var/www/html/{sitename}_test/`
3. Installs Composer dependencies
4. Enables Apache VirtualHost
5. Verifies site is responding

### Directory Structure

After site creation:

```
/var/www/html/{sitename}/
├── public_html/      # Application code
├── config/           # Site configuration
├── uploads/          # User uploads
├── logs/             # Application logs
├── static_files/     # Generated files
└── backups/          # Database backups
```

---

## Site Management

### Docker Container Management

```bash
SITENAME="yoursite"

# Stop container
docker stop $SITENAME

# Start container
docker start $SITENAME

# Restart container
docker restart $SITENAME

# View container status
docker ps --filter "name=$SITENAME"
```

### Viewing Logs

**Docker:**
```bash
# Container startup logs
docker logs $SITENAME

# Follow logs in real-time
docker logs -f $SITENAME

# Last 100 lines
docker logs --tail 100 $SITENAME

# Apache error log
docker exec $SITENAME tail -100 /var/www/html/$SITENAME/logs/error.log

# PostgreSQL log
docker exec $SITENAME tail -100 /var/log/postgresql/postgresql-16-main.log
```

**Bare-metal:**
```bash
# Apache error log
tail -f /var/www/html/$SITENAME/logs/error.log

# Apache access log
tail -f /var/log/apache2/access.log
```

### Shell Access

**Docker:**
```bash
# Access bash shell inside container
docker exec -it $SITENAME bash

# Run single command
docker exec $SITENAME ls -la /var/www/html/$SITENAME/
```

**Bare-metal:**
```bash
# Just use normal shell
cd /var/www/html/$SITENAME/
```

### Apache Management

**Docker:**
```bash
# Reload Apache configuration (safe, no downtime)
docker exec $SITENAME service apache2 reload

# Check Apache status
docker exec $SITENAME service apache2 status

# Test Apache configuration
docker exec $SITENAME apache2ctl configtest

# WARNING: Never use 'service apache2 restart' in Docker - it will kill the container!
# Use 'reload' or 'graceful' instead
docker exec $SITENAME apache2ctl graceful
```

**Bare-metal:**
```bash
# Reload Apache configuration
sudo systemctl reload apache2

# Restart Apache
sudo systemctl restart apache2

# Test Apache configuration
sudo apache2ctl configtest
```

### PostgreSQL Access

**Docker:**
```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="your_password"

# Connect to database
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" $SITENAME psql -h 127.0.0.1 -U postgres -d $SITENAME
```

**Bare-metal:**
```bash
# Connect to database
psql -U postgres -d $SITENAME
```

---

## Maintenance Operations

### Database Backup

**Docker:**
```bash
SITENAME="yoursite"
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"

# Create backup
docker exec $SITENAME pg_dump -U postgres $SITENAME > $BACKUP_FILE

# Compressed backup
docker exec $SITENAME pg_dump -U postgres $SITENAME | gzip > ${BACKUP_FILE}.gz
```

**Bare-metal:**
```bash
# Use the sysadmin_tools backup script
./sysadmin_tools/backup_database.sh $SITENAME

# Or manually
pg_dump -U postgres $SITENAME > backup.sql
```

### Database Restore

**Docker:**
```bash
SITENAME="yoursite"
BACKUP_FILE="backup.sql"

# Restore from backup
docker exec -i $SITENAME psql -U postgres -d $SITENAME < $BACKUP_FILE

# From compressed backup
gunzip -c ${BACKUP_FILE}.gz | docker exec -i $SITENAME psql -U postgres -d $SITENAME
```

**Bare-metal:**
```bash
# Use the sysadmin_tools restore script
./sysadmin_tools/restore_database.sh $SITENAME backup.sql

# Or manually
psql -U postgres -d $SITENAME < backup.sql
```

### Update Application Code

**Docker:**
```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="yourpass"
DOMAIN_NAME="yourdomain"
PORT=8080

# 1. Stop and remove the container (volumes are preserved!)
docker stop $SITENAME
docker rm $SITENAME

# 2. Extract new archive and run install again
tar -xzf joinery-NEW-VERSION.tar.gz
cd maintenance_scripts/install_tools
./install.sh site $SITENAME $POSTGRES_PASSWORD $DOMAIN_NAME $PORT
```

The container detects this is not a first run and skips initial setup. Your data persists in the volumes.

**Bare-metal:**
```bash
# Use the deploy.sh script for git-based deployments
./deploy.sh $SITENAME --verbose

# Or use upgrade.php for archive-based upgrades
php /var/www/html/$SITENAME/public_html/utils/upgrade.php
```

### Run Database Migrations

**Docker:**
```bash
docker exec $SITENAME php /var/www/html/$SITENAME/public_html/utils/update_database.php
```

**Bare-metal:**
```bash
php /var/www/html/$SITENAME/public_html/utils/update_database.php
```

---

## Troubleshooting

### Container Won't Start

Check logs:
```bash
docker logs $SITENAME
```

Common causes:
- Port already in use: The install script detects this automatically and suggests available ports
- Volume permission issues: Check volume mounts
- Out of disk space: Clean up old images/containers

### Services Not Running After Restart

Services should start automatically via the CMD instruction. If not:

```bash
docker exec $SITENAME service postgresql start
docker exec $SITENAME service apache2 start
```

### Checking What's Running

```bash
# List all Joinery sites
./install.sh list

# Docker containers directly
docker ps -a --filter "name=joinery" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

### Permission Errors (Bare-metal)

```bash
# Fix web directory permissions
sudo chown -R www-data:user1 /var/www/html/$SITENAME
sudo chmod -R 775 /var/www/html/$SITENAME

# Use the fix_permissions script
./fix_permissions.sh $SITENAME --production
```

### Port Conflict Handling

If you try to use a port that's already in use:

1. The script detects the conflict
2. Shows all existing Joinery containers
3. Finds the next available port pair
4. Prompts you to accept the suggestion

```
[WARN] Port 8080 is already in use

Existing Joinery containers:
───────────────────────────────────────────────────────────────
SITE NAME            WEB PORT        DB PORT      STATUS
───────────────────────────────────────────────────────────────
site1                8080            9080         Up 2 hours
───────────────────────────────────────────────────────────────

Suggested available port: 8081 (database: 9081)

Would you like to use port 8081 instead? [Y/n]
```

---

## Quick Reference

### Essential Commands

```bash
# List all Joinery sites
./install.sh list

# Docker commands
docker start SITENAME           # Start container
docker stop SITENAME            # Stop container
docker logs SITENAME            # View logs
docker exec -it SITENAME bash   # Shell access
docker exec SITENAME service apache2 reload  # Apache reload

# Database backup (Docker)
docker exec SITENAME pg_dump -U postgres SITENAME > backup.sql

# Database backup (Bare-metal)
pg_dump -U postgres SITENAME > backup.sql

# List Docker volumes
docker volume ls | grep SITENAME

# Container status
docker ps --filter "name=SITENAME"
```

### Default Credentials

After fresh installation:
- **Admin Email:** admin@example.com
- **Database User:** postgres
- **Database Password:** (as set during installation)

---

## Script Reference

### install.sh

Universal installer with subcommands:

| Command | Purpose |
|---------|---------|
| `install.sh docker` | Install Docker (one-time) |
| `install.sh server` | Set up bare-metal server (one-time) |
| `install.sh site` | Create a new Joinery site |
| `install.sh list` | List existing sites |

**Site command options:**
```bash
./install.sh site [--docker|--bare-metal] SITENAME PASSWORD [DOMAIN] [PORT]
```

### Supporting Scripts

| Script | Purpose | Called By |
|--------|---------|-----------|
| `new_account.sh` | Creates site directory, database, virtualhost, user | `install.sh site` (bare-metal) |
| `fix_permissions.sh` | Sets correct ownership and permissions on site files | `install.sh site`, manual use |
| `Dockerfile.template` | Template for building Docker images | `install.sh site` (Docker) |
| `default_Globalvars_site.php` | Template for site configuration | `new_account.sh` |
| `default_serve.php` | Template for front controller | `new_account.sh` |
| `default_virtualhost.conf` | Template for Apache virtualhost | `new_account.sh` |

### Sysadmin Tools

Located in `maintenance_scripts/sysadmin_tools/`:

| Script | Purpose |
|--------|---------|
| `backup_database.sh` | Backup PostgreSQL database |
| `restore_database.sh` | Restore PostgreSQL database |
| `backup_project.sh` | Full site backup (files + database) |
| `restore_project.sh` | Full site restore |
| `copy_database.sh` | Copy database between sites |
| `remove_account.sh` | Remove a site completely |

### Reverse Proxy Setup (Multiple Sites on Port 80/443)

For production with multiple Docker sites on standard HTTP/HTTPS ports, install Apache on the host:

```bash
apt-get install -y apache2
a2enmod proxy proxy_http headers ssl rewrite
systemctl restart apache2
```

Create `/etc/apache2/sites-available/yoursite.conf`:

```apache
<VirtualHost *:80>
    ServerName yoursite.com
    ServerAlias www.yoursite.com

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
```

Enable and add SSL:
```bash
a2ensite yoursite
systemctl reload apache2
apt-get install -y certbot python3-certbot-apache
certbot --apache -d yoursite.com -d www.yoursite.com
```

---

## Version Information

- **Guide Version:** 2.0
- **install.sh Version:** 1.0
- **Tested With:** Ubuntu 24.04, Docker 29.1.3
- **Last Updated:** 2026-01-17
