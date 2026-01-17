# Joinery Docker Installation Guide

This guide covers deploying Joinery in Docker containers, from a blank server to a fully functional site.

## Table of Contents

1. [Quick Install](#quick-install)
2. [Prerequisites](#prerequisites)
3. [Manual Installation](#manual-installation)
4. [Configuration Parameters](#configuration-parameters)
5. [Container Management](#container-management)
6. [Maintenance Operations](#maintenance-operations)
7. [Troubleshooting](#troubleshooting)
8. [Multiple Sites](#multiple-sites)

---

## Quick Install

The fastest way to deploy Joinery with Docker is using the master installation script.

### One-Command Installation

On your target server (Ubuntu 24.04):

```bash
# 1. Transfer and extract the archive
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
ssh root@YOUR_SERVER

# 2. Extract and run the installer
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts
./docker_install_master.sh SITENAME POSTGRES_PASSWORD [DOMAIN_NAME] [PORT]
```

### Example

```bash
tar -xzf joinery-2-21.tar.gz
cd maintenance_scripts
./docker_install_master.sh mysite YOUR_PASSWORD mysite.com 8080
```

### What the Script Does

The `docker_install_master.sh` script automates the entire process:

1. Validates parameters and archive structure
2. Checks port availability (detects conflicts, suggests alternatives)
3. Installs Docker if not present (with confirmation)
4. Prepares an isolated build context
5. Builds the Docker image
6. Starts the container with all persistent volumes
7. Verifies the site is responding
8. Cleans up build directory
9. Displays access information and all running containers

### Script Parameters

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `SITENAME` | Yes | - | Site/database name (e.g., `mysite`) |
| `POSTGRES_PASSWORD` | Yes | - | Database password |
| `DOMAIN_NAME` | No | Server IP | Domain for VirtualHost |
| `PORT` | No | 8080 | Host port for web traffic |

### Script Options

```bash
# List existing Joinery containers
./docker_install_master.sh --list
```

### Multi-Site Support

The script fully supports running multiple sites on the same server:

- **Port conflict detection**: Automatically checks if ports are in use
- **Port suggestions**: Offers next available port if conflict detected
- **Site isolation**: Each site uses completely isolated build context and volumes

```bash
# First site (uses port 8080)
./docker_install_master.sh site1 YOUR_PASSWORD_1 site1.com 8080

# Second site (uses port 8081)
./docker_install_master.sh site2 YOUR_PASSWORD_2 site2.com 8081

# Check what's running
./docker_install_master.sh --list
```

After installation, access your site at `http://YOUR_SERVER:PORT/`

---

## Prerequisites

### Required Files
- `joinery-X-Y.tar.gz` - The Joinery archive containing:
  - `public_html/` - Application code
  - `config/` - Configuration templates
  - `maintenance_scripts/install_tools/` - Setup scripts, Dockerfile.template, docker_install_master.sh
  - `maintenance_scripts/sysadmin_tools/` - Backup, restore, and maintenance utilities

### Server Requirements
- Fresh Ubuntu 24.04 LTS installation
- Root access
- At least 4GB RAM
- At least 10GB disk space
- Ports 8080+ available (or your chosen port range)

---

## Manual Installation

If you prefer to run each step manually, follow these instructions.

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
```

### Step 2: Prepare Build Context

```bash
# Set your site name
SITENAME="yoursite"

# Create build directory
mkdir -p ~/joinery-docker-build
cd ~/joinery-docker-build

# Extract the archive (assuming it's in home directory)
tar -xzf ~/joinery-X-Y.tar.gz

# Organize files under site name
mkdir -p $SITENAME
mv config $SITENAME/
mv public_html $SITENAME/

# Copy Dockerfile template
cp maintenance_scripts/install_tools/Dockerfile.template ./Dockerfile

# Create .dockerignore
cat > .dockerignore << 'EOF'
.git
*.log
*/backups/*
EOF
```

### Step 3: Build the Docker Image

```bash
SITENAME="yoursite"
POSTGRES_PASSWORD="your_secure_password_here"
DOMAIN_NAME="example.com"  # or server IP

docker build \
  --build-arg SITENAME=$SITENAME \
  --build-arg POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
  --build-arg DOMAIN_NAME=$DOMAIN_NAME \
  -t joinery-$SITENAME .
```

**Build time:** Approximately 5-10 minutes.

### Step 4: Run the Container

```bash
SITENAME="yoursite"
PORT=8080

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

### Step 5: Verify Installation

Wait about 30 seconds for initialization, then:

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/
# Should return: 200
```

Access the site at `http://YOUR_SERVER_IP:8080/`

---

## Configuration Parameters

### Build Arguments

| Parameter | Description | Example |
|-----------|-------------|---------|
| `SITENAME` | Site directory name, database name | `mycompany` |
| `POSTGRES_PASSWORD` | PostgreSQL password | (your password) |
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

# 1. Stop and remove the container (volumes are preserved!)
docker stop $SITENAME
docker rm $SITENAME

# 2. Update source files and rebuild
cd ~/joinery-docker-build
# Extract new archive and reorganize files...

docker build \
  --build-arg SITENAME=$SITENAME \
  --build-arg POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
  --build-arg DOMAIN_NAME=$DOMAIN_NAME \
  -t joinery-$SITENAME .

# 3. Run new container with same volumes
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

The container detects this is not a first run and skips initial setup. Your data persists in the volumes.

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

### Container Won't Start

Check logs:
```bash
docker logs $SITENAME
```

Common causes:
- Port already in use: The install script now detects this automatically and suggests available ports
- Volume permission issues: Check volume mounts
- Out of disk space: Clean up old images/containers

### Services Not Running After Restart

Services should start automatically via the CMD instruction. If not:

```bash
docker exec $SITENAME service postgresql start
docker exec $SITENAME service apache2 start
```

### Checking What's Running

Use the built-in list command to see all Joinery containers:

```bash
./docker_install_master.sh --list
```

Or use Docker directly:

```bash
docker ps -a --filter "name=joinery" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

---

## Multiple Sites

The installation script fully supports running multiple sites on the same server with automatic port management.

### Installing Multiple Sites

```bash
# First site - uses default port 8080
./docker_install_master.sh site1 YOUR_PASSWORD_1 site1.com

# Second site - specify port 8081
./docker_install_master.sh site2 YOUR_PASSWORD_2 site2.com 8081

# Third site - if you forget to specify a port, the script will:
#   1. Detect the conflict
#   2. Show existing containers
#   3. Suggest the next available port
./docker_install_master.sh site3 YOUR_PASSWORD_3 site3.com
```

### Listing Existing Sites

```bash
# Show all Joinery containers with their ports and status
./docker_install_master.sh --list
```

Example output:
```
Existing Joinery containers:
───────────────────────────────────────────────────────────────
SITE NAME            WEB PORT        DB PORT      STATUS
───────────────────────────────────────────────────────────────
site1                8080            9080         Up 2 hours
site2                8081            9081         Up 1 hour
───────────────────────────────────────────────────────────────
```

### Port Management

Each site needs unique ports. The script automatically checks and suggests available ports:

| Site | Web Port | Database Port |
|------|----------|---------------|
| site1 | 8080 | 9080 |
| site2 | 8081 | 9081 |
| site3 | 8082 | 9082 |

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
# List all Joinery containers
./docker_install_master.sh --list

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

- **Guide Version:** 1.4
- **Tested With:** Ubuntu 24.04, Docker 29.1.3
- **Dockerfile Template:** v1.1
- **Install Script:** v1.2
- **Last Updated:** 2026-01-04
