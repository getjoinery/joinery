# Installation

> **New to Joinery?** The [Quick Start guide](quickstart.md) walks you through renting a server, configuring your domain, and installing Joinery step by step — no prior experience required.

Deploy Joinery on a fresh Ubuntu 24.04 server, either in a Docker container or directly on the host (bare-metal). The same `install.sh` script handles both — the deployment mode is auto-detected from whether a port is supplied.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Prerequisites](#prerequisites)
3. [Password Security](#password-security)
4. [Docker Deployment](#docker-deployment)
5. [Bare-Metal Deployment](#bare-metal-deployment)
6. [SSL Certificates](#ssl-certificates)
7. [Cloudflare Proxy Support](#cloudflare-proxy-support)
8. [Themes and Plugins](#themes-and-plugins)
9. [Site Cloning](#site-cloning)
10. [Domain Management](#domain-management)
11. [Site Management](#site-management)
12. [Maintenance Operations](#maintenance-operations)
13. [Troubleshooting](#troubleshooting)
14. [Script Reference](#script-reference)

## Quick Start

### One-liner install (latest version)

Docker:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh docker && \
  sudo ./install.sh site mysite example.com 8080
```

Bare-metal:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://joinerytest.site/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite example.com
```

### Manual transfer

```bash
# Copy the archive to the target server
scp joinery-X-Y.tar.gz root@YOUR_SERVER:~/
ssh root@YOUR_SERVER
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# One-time host setup
sudo ./install.sh docker         # OR: sudo ./install.sh server

# Create your first site (password auto-generated — save it!)
sudo ./install.sh site mysite mysite.com 8080   # Docker (with port)
sudo ./install.sh site mysite mysite.com        # Bare-metal (no port)
```

The presence of a port signals Docker mode; omitting it signals bare-metal. To force either explicitly, use `--docker` or `--bare-metal`. The resolved mode is recorded in the site's `Globalvars_site.php` as `deployment_environment` (`docker` or `baremetal`) — the single source of truth the platform reads instead of probing for a container at runtime.

## Prerequisites

### Server requirements

- Fresh Ubuntu 24.04 LTS
- Root access
- 1 GB RAM minimum
- 3 GB disk minimum
- Docker mode: port 8080+ available (or your chosen range)
- Bare-metal mode: ports 80 / 443 available

### Archive contents

The `joinery-X-Y.tar.gz` archive contains:

- `public_html/` — application code
- `config/` — configuration templates
- `maintenance_scripts/install_tools/` — installer, Dockerfile, defaults
- `maintenance_scripts/sysadmin_tools/` — backup, restore, maintenance utilities

## Password Security

**Never use weak or example passwords in production.** Auto-generation is the recommended path.

### Auto-generated (recommended)

Omit the password and the installer generates a 24-character secure password, then displays it once at the end of installation:

```bash
sudo ./install.sh site mysite mysite.com 8080
# Output: "Auto-generated secure password: xK9mN2pQ7rT4vW8yB3cF6hJ1"
```

Save the password immediately — it's also written to the site's `Globalvars_site.php`.

### Bring your own password

Use `--password-file` to avoid shell-escaping issues:

```bash
echo 'YourStr0ng&Secure#Pass@9' > /tmp/dbpass.txt
sudo ./install.sh site mysite --password-file=/tmp/dbpass.txt mysite.com 8080
rm /tmp/dbpass.txt
```

### Forbidden characters

Shell and sed escaping forbid these characters in the database password:

| Character | Reason                                  |
|-----------|------------------------------------------|
| `'`       | Breaks PHP string literals               |
| `"`       | Breaks shell double-quoted strings       |
| `\`       | Escape character in shell, sed, and PHP  |
| `$`       | Variable expansion in shell              |
| `` ` ``   | Command substitution in shell            |
| `!`       | History expansion in bash                |
| newlines  | Break sed replacement patterns           |

Safe symbols: `@ # % ^ * ( ) - _ + = { } [ ] | : ; < > , . ? ~ / &`

### Requirements

- Minimum 16 characters (24+ recommended)
- Mix of upper, lower, digits, symbols
- No dictionary words, personal info, sequential patterns, or example passwords

### Non-interactive deployment

```bash
sudo ./install.sh -y docker
sudo ./install.sh -y -q site mysite mysite.com 8080
```

`-y` accepts all prompts; `-q` suppresses progress output.

## Docker Deployment

### One-time setup

```bash
sudo ./install.sh docker
```

Checks for Docker, installs Docker CE if missing, starts the daemon, verifies it's operational.

### Create a site

```bash
sudo ./install.sh site SITENAME [DOMAIN_NAME] [PORT] [OPTIONS]
```

| Parameter         | Required | Default   | Notes                                          |
|-------------------|----------|-----------|------------------------------------------------|
| `SITENAME`        | Yes      | —         | Site & database name (e.g., `mysite`)          |
| `DOMAIN_NAME`     | No       | Server IP | Domain for VirtualHost                         |
| `PORT`            | No       | 8080      | Host port for web traffic                      |

The installer:

1. Validates parameters and archive structure.
2. Checks port availability and suggests alternatives on conflict.
3. Prepares an isolated build context.
4. Builds the Docker image.
5. Starts the container with persistent volumes.
6. Verifies the site responds.
7. Optionally downloads stock themes/plugins (with `--themes`).
8. Displays access info and the list of running containers.

### Multi-site support

Each site needs unique ports. The installer detects conflicts and suggests the next pair:

| Site   | Web port | DB port |
|--------|----------|---------|
| site1  | 8080     | 9080    |
| site2  | 8081     | 9081    |
| site3  | 8082     | 9082    |

### Volume mounts

| Volume                 | Container path                | Purpose                |
|------------------------|-------------------------------|------------------------|
| `{site}_postgres`      | `/var/lib/postgresql`         | Database files         |
| `{site}_uploads`       | `.../uploads`                 | User uploads           |
| `{site}_config`        | `.../config`                  | Site configuration     |
| `{site}_backups`       | `.../backups`                 | Database backups       |
| `{site}_static`        | `.../static_files`            | Generated files        |
| `{site}_logs`          | `.../logs`                    | Application logs       |
| `{site}_cache`         | `.../cache`                   | Runtime cache          |
| `{site}_sessions`      | `/var/lib/php/sessions`       | PHP sessions           |
| `{site}_apache_logs`   | `/var/log/apache2`            | Apache logs            |
| `{site}_pg_logs`       | `/var/log/postgresql`         | PostgreSQL logs        |

## Bare-Metal Deployment

### One-time setup

```bash
sudo ./install.sh server
```

Installs and configures PHP 8.3, Apache (with `mod_rewrite`), PostgreSQL, Composer, Certbot, UFW, fail2ban, SSH hardening, and unattended security updates.

### Create a site

```bash
sudo ./install.sh site SITENAME DOMAIN_NAME [OPTIONS]
```

Common options:

- `--activate THEME` — activate a specific theme after install
- `--with-test-site` — create a companion test site (bare-metal only)

The installer:

1. Verifies prerequisites (Apache, PHP, PostgreSQL).
2. Deploys code to `/var/www/html/{sitename}/`.
3. Runs `_site_init.sh` to create directories, configure `Globalvars_site.php`, create the database, load the schema, install Composer deps, and create the Apache VirtualHost.
4. Optionally creates a test site.
5. Verifies the site responds.

### Directory layout

```
/var/www/html/{sitename}/
├── public_html/      # Application code
├── config/           # Site configuration
├── uploads/          # User uploads
├── logs/             # Application logs
├── static_files/     # Generated files
└── backups/          # Database backups
```

## SSL Certificates

SSL is configured automatically when a domain (not localhost or an IP) is provided.

### How it works

1. After site creation, the installer verifies the domain's DNS points to this server.
2. If DNS is correct, Certbot runs to fetch a Let's Encrypt certificate.
3. If DNS isn't ready, SSL is skipped and the installer prints instructions for running Certbot manually later.

Requirements: domain DNS pointing here, port 80 reachable from the internet, Certbot installed (included in `install.sh server`).

### Bare-metal

Certbot configures Apache directly:

```bash
sudo ./install.sh site mysite mysite.example.com
```

### Docker

The installer adds Apache on the host (if not present), creates a reverse proxy `mysite.example.com → localhost:8080`, then runs Certbot against the proxy:

```bash
sudo ./install.sh site mysite mysite.example.com 8080
```

### Skip SSL

```bash
sudo ./install.sh site mysite mysite.example.com --no-ssl
```

### Manual SSL later

```bash
# Bare-metal
sudo certbot --apache -d mysite.example.com

# Docker (after the host proxy exists)
sudo certbot --apache -d mysite.example.com
```

## Cloudflare Proxy Support

The installer detects domains behind Cloudflare's proxy (orange cloud) by matching the resolved IP against Cloudflare's IP ranges, and adapts:

1. Skips Let's Encrypt — Cloudflare provides edge SSL.
2. Creates an HTTP proxy for Docker sites so Cloudflare can reach the origin.

Set the SSL mode in Cloudflare → SSL/TLS:

| Mode             | Browser ↔ Cloudflare | Cloudflare ↔ Origin     | Origin cert        |
|------------------|----------------------|--------------------------|--------------------|
| Flexible         | HTTPS                | HTTP                     | None required      |
| Full             | HTTPS                | HTTPS (any cert)         | Self-signed OK     |
| Full (Strict)    | HTTPS                | HTTPS (valid cert)       | Cloudflare Origin Certificate |

For Full (Strict), generate an Origin Certificate in Cloudflare → SSL/TLS → Origin Server and install it on Apache.

## Themes and Plugins

By default, fresh installs include only the core application. Use `--themes` to download stock themes and plugins from the upgrade server during site creation:

```bash
sudo ./install.sh site mysite mysite.com 8080 --themes
```

To download themes and plugins after the site exists, use `upgrade.php`:

```bash
# Docker
docker exec mysite php /var/www/html/mysite/public_html/utils/upgrade.php

# Bare-metal
php /var/www/html/mysite/public_html/utils/upgrade.php
```

The `--themes` flag uses the same distribution system as `upgrade.php`. See [Deploy and Upgrade](deploy_and_upgrade.md) for the upgrade pipeline.

## Site Cloning

Clone an existing site — database, uploads, settings — to a new server. The target machine pulls from the source.

### Enable export on the source

```sql
INSERT INTO stg_settings (stg_name, stg_value)
VALUES ('clone_export_key', 'YourSecureRandomKey123');

-- When done:
DELETE FROM stg_settings WHERE stg_name = 'clone_export_key';
```

Use a strong random key (32+ chars). HTTPS is required. Rotate or remove the key after cloning. Clone requests are logged on the source.

### Run the clone

```bash
# Docker
sudo ./install.sh site newsite newdomain.com 8080 \
    --clone-from=https://sourcesite.com \
    --clone-key=YourSecureRandomKey123

# Bare-metal
sudo ./install.sh site newsite newdomain.com \
    --clone-from=https://sourcesite.com \
    --clone-key=YourSecureRandomKey123
```

### What gets cloned

| Item                    | Behavior                                       |
|-------------------------|------------------------------------------------|
| Database (all tables)   | Exact copy from source                         |
| All settings            | Exact copy from source                         |
| `site_url` setting      | Updated to the target domain                   |
| Uploads directory       | Exact copy from source                         |
| User accounts           | Preserved from source                          |
| `clone_export_key`      | Removed on the new site                        |
| `Globalvars_site.php`   | Regenerated with new DB credentials            |
| Themes & plugins        | Downloaded from the source site                |

### Process

1. Pre-flight: source reachable, key valid.
2. Display manifest: DB size, uploads size, themes/plugins.
3. Confirmation prompt (skip with `-y`).
4. Deploy application code.
5. Stream encrypted, compressed database; restore.
6. Stream compressed uploads; extract.
7. Update site URL.
8. Standard setup: Composer, permissions, SSL.

## Domain Management

Use `manage_domain.sh` (in `maintenance_scripts/sysadmin_tools/`) to add, change, or remove domains on existing sites. Works for both Docker and bare-metal.

```bash
cd maintenance_scripts/sysadmin_tools

# Current state
sudo ./manage_domain.sh status mysite

# Assign a domain (with SSL via Let's Encrypt unless Cloudflare detected)
sudo ./manage_domain.sh set mysite example.com

# Without SSL (e.g. Cloudflare-proxied or testing)
sudo ./manage_domain.sh set mysite example.com --no-ssl

# Revert to IP-only access
sudo ./manage_domain.sh clear mysite

# Restore the previous configuration
sudo ./manage_domain.sh rollback mysite

# Remove SSL only, keep the domain
sudo ./manage_domain.sh remove-ssl mysite
```

For Docker sites, `set` creates an Apache reverse proxy on the host and disables `000-default.conf` so bare-IP requests don't fall through to Ubuntu's welcome page.

## Site Management

### Docker container lifecycle

```bash
docker stop mysite
docker start mysite
docker restart mysite
docker ps --filter "name=mysite"
```

### Logs

```bash
# Docker
docker logs mysite                                       # Startup
docker logs -f mysite                                    # Follow
docker logs --tail 100 mysite                            # Last 100
docker exec mysite tail -100 /var/www/html/mysite/logs/error.log

# Bare-metal
tail -f /var/www/html/mysite/logs/error.log
tail -f /var/log/apache2/access.log
```

### Shell access

```bash
# Docker
docker exec -it mysite bash

# Bare-metal — just use the host shell
cd /var/www/html/mysite/
```

### Apache management

In Docker, **never `service apache2 restart`** — it kills the container. Use `reload` or `graceful`:

```bash
docker exec mysite service apache2 reload
docker exec mysite apache2ctl graceful
docker exec mysite apache2ctl configtest
```

Bare-metal:

```bash
sudo systemctl reload apache2
sudo apache2ctl configtest
```

### PostgreSQL access

```bash
# Docker
docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" mysite \
    psql -h 127.0.0.1 -U postgres -d mysite

# Bare-metal
psql -U postgres -d mysite
```

## Maintenance Operations

### Database backup and restore

```bash
# Backup (Docker)
docker exec mysite pg_dump -U postgres mysite | gzip > backup.sql.gz

# Backup (bare-metal)
./maintenance_scripts/sysadmin_tools/backup_database.sh mysite

# Restore (Docker)
gunzip -c backup.sql.gz | docker exec -i mysite psql -U postgres -d mysite

# Restore (bare-metal)
./maintenance_scripts/sysadmin_tools/restore_database.sh mysite backup.sql
```

### Update application code

**Docker** — stop and re-create the container; volumes persist:

```bash
docker stop mysite && docker rm mysite
tar -xzf joinery-NEW-VERSION.tar.gz
cd maintenance_scripts/install_tools
sudo ./install.sh site mysite mysite.com 8080
```

The container detects this isn't a fresh install and skips initial setup.

**Bare-metal** — use `upgrade.php`:

```bash
php /var/www/html/mysite/public_html/utils/upgrade.php
```

For more detail on the upgrade pipeline, see [Deploy and Upgrade](deploy_and_upgrade.md).

### Run database migrations

```bash
# Docker
docker exec mysite php /var/www/html/mysite/public_html/utils/update_database.php

# Bare-metal
php /var/www/html/mysite/public_html/utils/update_database.php
```

### Remove a site

`remove_account.sh` detects whether the site is Docker or bare-metal and handles both:

```bash
sudo ./maintenance_scripts/sysadmin_tools/remove_account.sh mysite
sudo ./maintenance_scripts/sysadmin_tools/remove_account.sh mysite -y   # No prompt
```

| Docker sites                                  | Bare-metal sites           |
|-----------------------------------------------|----------------------------|
| Docker container                              | Website directories        |
| All Docker volumes (postgres, uploads, etc.)  | Test site directories      |
| Docker image                                  | Apache VirtualHost         |
| Build directory                               | PostgreSQL database        |

## Troubleshooting

### Container won't start

```bash
docker logs mysite
```

Common causes: port already in use (the installer normally detects this and offers alternatives), volume permission issues, or out of disk space.

### Services not running after a host restart

The container's CMD should bring services up automatically. If not:

```bash
docker exec mysite service postgresql start
docker exec mysite service apache2 start
```

### Permission errors (bare-metal)

```bash
sudo chown -R www-data:user1 /var/www/html/mysite
sudo chmod -R 775 /var/www/html/mysite

# Or:
./fix_permissions.sh mysite --production
```

### Database load failure during install

Almost always a syntax or escaping error.

1. Check the password against the [forbidden characters table](#forbidden-characters).
2. Verify any locally-modified `joinery-install.sql.gz` for SQL syntax.
3. Confirm UTF-8 encoding on the SQL file.

`pg_hba.conf` settings, authentication method, and database user permissions are not the cause — the installer handles all of those.

Debugging:

```bash
docker logs mysite 2>&1 | grep -i "error\|fail"

docker exec -it mysite bash
su postgres -c "psql -d mysite -c '\\dt'"
```

### Composer autoload errors after cloning

The `composerAutoLoad` setting was copied from the source and points to an invalid absolute path. Set it back to the portable relative path:

```bash
# Docker
docker exec -it mysite bash
PGPASSWORD='your_db_password' psql -U postgres -d mysite \
  -c "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';"

# Bare-metal
sudo -u postgres psql -d mysite \
  -c "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';"
```

### Port conflict handling

If the chosen port is in use, the installer shows existing Joinery containers and suggests the next available port pair, then prompts you to accept.

## Script Reference

### `install.sh`

| Subcommand                | Purpose                                       |
|---------------------------|-----------------------------------------------|
| `install.sh docker`       | Install Docker (one-time)                     |
| `install.sh server`       | Set up bare-metal host (one-time)             |
| `install.sh site …`       | Create a new Joinery site                     |
| `install.sh list`         | List existing sites                           |

Global flags:

| Flag         | Description                                                |
|--------------|------------------------------------------------------------|
| `-y`, `--yes`   | Auto-accept all prompts (non-interactive)               |
| `-q`, `--quiet` | Suppress progress output; show errors and final summary |

`install.sh site` options:

```
install.sh [-y] [-q] site [--docker|--bare-metal] SITENAME [DOMAIN] [PORT] [OPTIONS]

  --password-file=FILE   Read database password from file (recommended)
  --activate THEME       Activate this theme after install
  --with-test-site       Create a companion test site (bare-metal only)
  --themes               Download stock themes/plugins from upgrade server
  --no-ssl               Skip automatic SSL setup
  --clone-from=URL       Clone DB + uploads from an existing site
  --clone-key=KEY        Authentication key for clone source
```

If no password is given (and no `--password-file`), the installer auto-generates a 24-character password.

### Supporting scripts

| Script                          | Purpose                                                        | Called by                              |
|---------------------------------|----------------------------------------------------------------|----------------------------------------|
| `_site_init.sh`                 | Internal site initialization (DB, config, Composer)             | `install.sh site`, Dockerfile CMD      |
| `fix_permissions.sh`            | Sets ownership and permissions on site files                    | `_site_init.sh`, manual                |
| `Dockerfile.template`           | Template for building Docker images                             | `install.sh site` (Docker)             |
| `default_Globalvars_site.php`   | Template for site configuration                                 | `_site_init.sh`                        |
| `default_virtualhost.conf`      | Template for Apache VirtualHost                                 | `_site_init.sh`                        |

`_site_init.sh` is internal — don't invoke it directly. Use `install.sh site`.

### Sysadmin tools

Located in `maintenance_scripts/sysadmin_tools/`:

| Script                  | Purpose                                                  |
|-------------------------|----------------------------------------------------------|
| `manage_domain.sh`      | Domain management: `set`, `clear`, `status`, `rollback`, `remove-ssl` |
| `backup_database.sh`    | Backup PostgreSQL database                               |
| `restore_database.sh`   | Restore PostgreSQL database                              |
| `backup_project.sh`     | Full site backup (files + database)                      |
| `restore_project.sh`    | Full site restore                                        |
| `copy_database.sh`      | Copy database between sites                              |
| `remove_account.sh`     | Remove a site completely                                 |

### Reverse proxy for production (multiple Docker sites on 80/443)

For multiple Docker sites sharing standard ports, install Apache on the host:

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

## Related Documentation

- [Deploy and Upgrade](deploy_and_upgrade.md) — Upgrade pipeline and `upgrade.php`
- [Publish/Upgrade System Analysis](publish_upgrade_system_analysis.md) — How upgrade archives are built and distributed
- [Server Manager](/plugins/server_manager/docs/overview.md) — Remote node management and applying upgrades via the admin UI
- [Settings](settings.md) — Configuring a site after installation
