# Joinery Docker Installation Guide

This guide covers the complete process for deploying Joinery in Docker containers, from a blank server to a fully functional site.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Server Setup](#server-setup)
3. [Installation Process](#installation-process)
4. [Configuration Parameters](#configuration-parameters)
5. [Container Management](#container-management)
6. [Maintenance Operations](#maintenance-operations)
7. [Troubleshooting](#troubleshooting)
8. [Multiple Sites](#multiple-sites)

---

## Prerequisites

### Required Files
- `joinery-X-Y.tar.gz` - The Joinery archive containing:
  - `public_html/` - Application code
  - `config/` - Configuration templates
  - `maintenance_scripts/` - Setup and maintenance scripts (including `Dockerfile.template`)

### Server Requirements
- Fresh Ubuntu 24.04 LTS installation
- Root access
- At least 4GB RAM
- At least 10GB disk space
- Ports 8080+ available (or your chosen port range)

---

## Server Setup

### Step 1: Install Docker

SSH into your target server and run:

```bash
# Update packages
apt-get update

# Install prerequisites
apt-get install -y ca-certificates curl gnupg lsb-release

# Add Docker's GPG key
mkdir -m 0755 -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

# Add Docker repository
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io

# Verify installation
docker --version
docker run hello-world
```

---

## Installation Process

### Step 2: Transfer Files to Server

From your local machine or source server:

```bash
# Upload the archive
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
```

### Step 3: Prepare Build Context

On the target server:

```bash
# Set your site name (this determines directory names and database name)
SITENAME="yoursite"

# Create build directory
mkdir -p ~/joinery-docker-build
cd ~/joinery-docker-build

# Extract the archive
tar -xzf ~/joinery-X-Y.tar.gz

# Organize files under site name
mkdir -p $SITENAME
mv config $SITENAME/
mv public_html $SITENAME/

# Copy Dockerfile template from the archive
cp maintenance_scripts/Dockerfile.template ./Dockerfile

# Create .dockerignore
cat > .dockerignore << 'EOF'
.git
*.log
*/backups/*
EOF
```

### Step 4: Build the Docker Image

```bash
# Set your configuration values
SITENAME="yoursite"
POSTGRES_PASSWORD="your_secure_password_here"
DOMAIN_NAME="example.com"  # or server IP for testing

# Build the image
docker build \
  --build-arg SITENAME=$SITENAME \
  --build-arg POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
  --build-arg DOMAIN_NAME=$DOMAIN_NAME \
  -t joinery-$SITENAME .
```

**Build time:** Approximately 5-10 minutes depending on server speed.

### Step 5: Run the Container

```bash
SITENAME="yoursite"
PORT=8080

# Run with all recommended volumes
docker run -d \
  --name $SITENAME \
  -p $PORT:80 \
  -p $((PORT+1000)):5432 \
  -v ${SITENAME}_postgres:/var/lib/postgresql \
  -v ${SITENAME}_uploads:/var/www/html/${SITENAME}/uploads \
  -v ${SITENAME}_config:/var/www/html/${SITENAME}/config \
  -v ${SITENAME}_backups:/var/www/html/${SITENAME}/backups \
  -v ${SITENAME}_static:/var/www/html/${SITENAME}/static_files \
  -v ${SITENAME}_logs:/var/www/html/${SITENAME}/logs \
  -v ${SITENAME}_cache:/var/www/html/${SITENAME}/cache \
  -v ${SITENAME}_sessions:/var/lib/php/sessions \
  -v ${SITENAME}_apache_logs:/var/log/apache2 \
  -v ${SITENAME}_pg_logs:/var/log/postgresql \
  joinery-$SITENAME
```

### Step 6: Verify Installation

The container automatically handles:
- Composer dependency installation
- Apache site configuration (disables default, enables your site)
- PostgreSQL password setup

Wait about 30 seconds for initial setup, then verify:

```bash
# Check if site returns HTTP 200
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/

# Should return: 200
```

Access the site in a browser at `http://YOUR_SERVER_IP:8080/`

---

## Configuration Parameters

### Build Arguments

| Parameter | Description | Example |
|-----------|-------------|---------|
| `SITENAME` | Site directory name, database name | `mycompany` |
| `POSTGRES_PASSWORD` | PostgreSQL password | `SecurePass123!` |
| `DOMAIN_NAME` | Domain for VirtualHost | `mycompany.com` or server IP |

### Port Mapping

| Container Port | Host Port | Purpose |
|---------------|-----------|---------|
| 80 | 8080+ | Web traffic (HTTP) |
| 5432 | 9080+ | PostgreSQL (optional) |

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

## Container Management

### Starting and Stopping

```bash
SITENAME="yoursite"

# Stop container (gracefully)
docker stop $SITENAME

# Start container
docker start $SITENAME

# Restart container
docker restart $SITENAME

# View container status
docker ps --filter "name=$SITENAME"
```

### Viewing Logs

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

### Shell Access

```bash
# Access bash shell inside container
docker exec -it $SITENAME bash

# Run single command
docker exec $SITENAME ls -la /var/www/html/$SITENAME/
```

### Apache Management

```bash
# Reload Apache configuration (safe, no downtime)
docker exec $SITENAME service apache2 reload

# Check Apache status
docker exec $SITENAME service apache2 status

# Test Apache configuration
docker exec $SITENAME apache2ctl configtest

# WARNING: Never use 'service apache2 restart' - it will kill the container!
# Use 'reload' or 'graceful' instead
docker exec $SITENAME apache2ctl graceful
```

### PostgreSQL Access

```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="your_password"

# Connect to database
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" $SITENAME psql -h 127.0.0.1 -U postgres -d $SITENAME

# Run SQL command
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" $SITENAME psql -h 127.0.0.1 -U postgres -d $SITENAME -c "SELECT * FROM usr_users LIMIT 5;"
```

---

## Maintenance Operations

### Database Backup

```bash
SITENAME="yoursite"
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"

# Create backup
docker exec $SITENAME pg_dump -U postgres $SITENAME > $BACKUP_FILE

# Compressed backup
docker exec $SITENAME pg_dump -U postgres $SITENAME | gzip > ${BACKUP_FILE}.gz
```

### Database Restore

```bash
SITENAME="yoursite"
BACKUP_FILE="backup.sql"

# Restore from backup
docker exec -i $SITENAME psql -U postgres -d $SITENAME < $BACKUP_FILE

# From compressed backup
gunzip -c ${BACKUP_FILE}.gz | docker exec -i $SITENAME psql -U postgres -d $SITENAME
```

### Update Application Code

To update application code without losing data:

```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="yourpass"
DOMAIN_NAME="yourdomain"

# 1. Stop the container
docker stop $SITENAME

# 2. Remove the container (volumes are preserved!)
docker rm $SITENAME

# 3. Update source files and rebuild the image
cd ~/joinery-docker-build
# Extract new archive, reorganize files as in Step 3
docker build \
  --build-arg SITENAME=$SITENAME \
  --build-arg POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
  --build-arg DOMAIN_NAME=$DOMAIN_NAME \
  -t joinery-$SITENAME .

# 4. Run new container with same volumes
docker run -d \
  --name $SITENAME \
  -p 8080:80 \
  -p 9080:5432 \
  -v ${SITENAME}_postgres:/var/lib/postgresql \
  -v ${SITENAME}_uploads:/var/www/html/${SITENAME}/uploads \
  -v ${SITENAME}_config:/var/www/html/${SITENAME}/config \
  -v ${SITENAME}_backups:/var/www/html/${SITENAME}/backups \
  -v ${SITENAME}_static:/var/www/html/${SITENAME}/static_files \
  -v ${SITENAME}_logs:/var/www/html/${SITENAME}/logs \
  -v ${SITENAME}_cache:/var/www/html/${SITENAME}/cache \
  -v ${SITENAME}_sessions:/var/lib/php/sessions \
  -v ${SITENAME}_apache_logs:/var/log/apache2 \
  -v ${SITENAME}_pg_logs:/var/log/postgresql \
  joinery-$SITENAME
```

**Note:** The container automatically detects this is not a first run (config file exists) and skips initial setup. Your data and configuration persist in the volumes.

### Run Database Migrations

```bash
docker exec $SITENAME php /var/www/html/$SITENAME/public_html/utils/update_database.php
```

### Check Disk Usage

```bash
# Container disk usage
docker system df

# Volume sizes
docker system df -v | grep $SITENAME

# Specific volume inspection
docker volume inspect ${SITENAME}_postgres
```

### Clean Up Old Resources

```bash
# Remove unused images
docker image prune

# Remove all stopped containers
docker container prune

# Remove unused volumes (CAREFUL - this removes data!)
docker volume prune
```

---

## Troubleshooting

### Site Shows Apache Default Page

**Cause:** The 000-default site is enabled (should be disabled automatically).

```bash
docker exec $SITENAME a2dissite 000-default.conf
docker exec $SITENAME service apache2 reload
```

### "Vendor autoload not found" Error

**Cause:** Composer dependencies not installed (should install automatically).

```bash
docker exec $SITENAME bash -c "cd /var/www/html/$SITENAME/public_html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev"
docker exec $SITENAME chown -R www-data:www-data /var/www/html/$SITENAME/vendor
```

### Database Connection Failed

**Cause:** PostgreSQL password not set or authentication issue.

Check the error log:
```bash
docker exec $SITENAME tail -50 /var/www/html/$SITENAME/logs/error.log
```

If password needs to be reset manually:
```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="your_password_here"

# Temporarily allow trust authentication
docker exec $SITENAME bash -c "sed -i 's/local   all             postgres                                md5/local   all             postgres                                trust/' /etc/postgresql/16/main/pg_hba.conf"
docker exec $SITENAME service postgresql reload

# Set the password
docker exec $SITENAME bash -c "psql -U postgres -c \"ALTER USER postgres PASSWORD '$POSTGRES_PASSWORD';\""

# Restore md5 authentication
docker exec $SITENAME bash -c "sed -i 's/local   all             postgres                                trust/local   all             postgres                                md5/' /etc/postgresql/16/main/pg_hba.conf"
docker exec $SITENAME service postgresql reload
```

### Container Won't Start

Check logs:
```bash
docker logs $SITENAME
```

Common causes:
- Port already in use: Change the host port
- Volume permission issues: Check volume mounts
- Out of disk space: Clean up old images/containers

### Services Not Running After Restart

Services should start automatically via the CMD instruction. If not:

```bash
docker exec $SITENAME service postgresql start
docker exec $SITENAME service apache2 start
```

---

## Multiple Sites

### Port Management

Each site needs unique ports:

| Site | Web Port | Database Port |
|------|----------|---------------|
| site1 | 8080 | 9080 |
| site2 | 8081 | 9081 |
| site3 | 8082 | 9082 |

### Quick Reference Script

Create `~/manage-sites.sh`:

```bash
#!/bin/bash

case "$1" in
  list)
    echo "=== Running Joinery Containers ==="
    docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    ;;
  start)
    docker start $2
    ;;
  stop)
    docker stop $2
    ;;
  logs)
    docker logs --tail 100 $2
    ;;
  shell)
    docker exec -it $2 bash
    ;;
  *)
    echo "Usage: $0 {list|start|stop|logs|shell} [sitename]"
    ;;
esac
```

Usage:
```bash
chmod +x ~/manage-sites.sh
./manage-sites.sh list
./manage-sites.sh stop mysite
./manage-sites.sh start mysite
./manage-sites.sh logs mysite
./manage-sites.sh shell mysite
```

### Reverse Proxy Setup

For production with multiple sites on standard HTTP/HTTPS ports, install Apache on the host:

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

## Quick Reference

### Essential Commands

```bash
# Start container
docker start SITENAME

# Stop container
docker stop SITENAME

# View logs
docker logs SITENAME

# Shell access
docker exec -it SITENAME bash

# Apache reload
docker exec SITENAME service apache2 reload

# Database backup
docker exec SITENAME pg_dump -U postgres SITENAME > backup.sql

# List volumes
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

## Version Information

- **Guide Version:** 1.1
- **Tested With:** Ubuntu 24.04, Docker 29.1.3
- **Dockerfile Template:** v1.1
- **Last Updated:** 2026-01-04
