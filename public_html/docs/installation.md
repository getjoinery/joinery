# Installation

> **New to Joinery?** The [Quick Start guide](quickstart.md) walks you through renting a server, configuring your domain, and installing Joinery step by step — no prior experience required.

Deploy Joinery on a fresh Ubuntu 24.04 or 26.04 LTS server, either in a Docker container or directly on the host (bare-metal). The same `install.sh` script handles both — the deployment mode is auto-detected from whether a port is supplied.

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
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh docker && \
  sudo ./install.sh site mysite example.com 8080
```

Bare-metal:

```bash
mkdir -p /tmp/joinery && \
  curl -sL https://getjoinery.com/utils/latest_release | tar xz -C /tmp/joinery && \
  cd /tmp/joinery/maintenance_scripts/install_tools && \
  sudo ./install.sh server && \
  sudo ./install.sh site mysite example.com
```

### One-click deployment (Linode StackScript)

A StackScript installs Joinery while the instance first boots, so the deployer fills in a form and never opens a terminal. Select it when creating a Linode, answer the fields, and a few minutes later the site is running with SSL and a login.

The deploy form asks for as little as it can — every field is a chance for someone to abandon the form, and once this is a Marketplace listing each one is expensive to change:

| Field | Required | What it does |
|---|---|---|
| Admin email address | Yes | The admin account's address. Password reset needs a mailbox someone can receive at. |
| Admin password | Yes | The password for that account. Masked in the UI and kept out of the deployment log, which is what the `password` in its field name buys. A password change is still forced at first sign-in. |
| Site domain | No | Blank brings the site up on the instance's IP. |
| SSH public key | No | Placed in root's `authorized_keys` before server setup, which then mirrors it to `user1` with sudo and disables root login. Blank leaves root access as the provider configured it, so omitting it cannot lock anyone out. |
| Linode API token | No | Only useful when the domain's DNS is already at Linode. Creates the A record from the instance so the first certificate attempt succeeds rather than the retry timer's. Used once, never written to disk, never printed. |

Nothing is asked that can be worked out. The site name comes from the domain (or the instance ID); the install is always bare-metal, one site per instance.

There is no credentials file on this path — the owner already knows the password, because they chose it. Every other install writes one, since nobody chose that password.

An instance built this way is entirely the deployer's: no agent, no registration, no enrollment, no outbound call beyond fetching the release archive.

**How it is put together.** The script hosted at Linode is a wrapper of about twenty lines: it declares the fields, fetches the release archive, and hands off to `maintenance_scripts/install_tools/linode_stackscript.sh` inside it. All the real logic lives in the archive, so it ships with every release and an instance created today installs what was published this morning — with nothing to update on the Linode side. The pasted wrapper is kept in the repo at `maintenance_scripts/install_tools/linode_stackscript_wrapper.sh` so it stays reviewable.

If a step fails the script stops and says so in `/var/log/stackscript.log`, rather than continuing into a half-installed box that looks alive. The remedy is to destroy the instance and redeploy with the field corrected.

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

- Fresh Ubuntu 24.04 or 26.04 LTS — `install.sh server` refuses to run on anything else. It installs whichever PHP the release offers and derives every package, service, and config path from that, so no version is pinned; the gate is about which releases the package and service layout has been verified on. On an unverified release the setup can leave a server that does not work while looking like it installed. To proceed anyway and finish the setup by hand, pass `--allow-unsupported-os`; the check is not repeated by `install.sh site`, which presupposes `server` already ran.
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

#### How SSH hardening picks its account

Turning off root SSH login is the one hardening step that can lock an operator out, so the installer works out who will still be able to reach the box before it does that. Everything else — `MaxAuthTries 3`, empty passwords refused, idle-session timeouts, fail2ban, UFW — is applied unconditionally.

| What the installer finds | What it does |
|---|---|
| Running as root, and `/root/.ssh/authorized_keys` has keys | Copies those keys to `user1`, grants it passwordless sudo, then sets `PermitRootLogin no`. |
| Running under `sudo` from an ordinary account | That account already has its own key and sudo, so it sets `PermitRootLogin no` and does nothing else. |
| Neither — root reached by password, no key installed | Leaves `PermitRootLogin` alone and says so. Disabling it here would leave nothing able to log in. |

The third case is the only one that finishes with root password login still enabled. It is what you get on a provider that boots you a machine with a root password and no SSH key attached. To finish hardening, add your key and run the dedicated step:

```bash
ssh-copy-id root@your-server        # from your own machine
sudo ./install.sh host-harden       # on the server
```

`host-harden` refuses to run unless it can see a non-empty `authorized_keys`, then disables password authentication entirely and sets `PermitRootLogin prohibit-password`.

### Create a site

```bash
sudo ./install.sh site SITENAME DOMAIN_NAME [OPTIONS]
```

Common options:

- `--admin-email=ADDRESS` — the admin account's address. Set at the same moment as its password, so the only account on a new site is recoverable by email from the start. Omitted, the account is `admin@example.com`.
- `--activate THEME` — activate a specific theme after install
- `--with-test-site` — create a companion test site (bare-metal only)
- `--upgrade-server=URL` — fetch the code from somewhere other than the release site (see [Where a site gets its upgrades](#where-a-site-gets-its-upgrades))

The installer:

1. Verifies prerequisites (Apache, PHP, PostgreSQL).
2. Deploys code to `/var/www/html/{sitename}/`.
3. Runs `_site_init.sh` to create directories, configure `Globalvars_site.php`, create the database, load the schema, record where upgrades come from, install Composer deps, install the default plugin bundle, and create the Apache VirtualHost.
4. Optionally creates a test site.
5. Verifies the site responds.

### What a new site comes with

A fresh install is not the bare platform. Drive and the personal calendar are core and always present; on top of them the installer turns on a **bundle** — a named set of plugins declared in `install_bundles.json` at the `public_html/` root.

The default bundle is `personal`: mail and the AI assistant, which together with Drive and Calendar make the deployment a self-hosted replacement for the everyday Google tools. Everything else — events, commerce, bookings, the password vault, DNS filtering, server management — is installed from `/admin/admin_plugins` when it is wanted.

Both bundled plugins arrive installed and unconfigured, and each needs the owner to supply something before it does anything: mail needs MX and DKIM records and an outbound provider, the assistant needs a model provider.

```bash
# choose a different bundle at install time
JOINERY_INSTALL_BUNDLE=personal sudo ./install.sh site mysite mysite.com

# or apply one to an existing site
sudo php /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/install_bundle.php --list
sudo php /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/install_bundle.php --bundle=personal
```

`JOINERY_INSTALL_BUNDLE=none` installs no plugins. Bundles are flat lists and never extend one another — they are alternative products rather than layers, so each names everything it wants.

### Where a site gets its upgrades

Two separate things, which the installer keeps in agreement:

- `--upgrade-server=URL` tells *this run* where to fetch the archive from. It defaults to `https://getjoinery.com`, the release site.
- `upgrade_source`, a setting on the finished site, tells `upgrade.php` where to fetch from every time after.

`_site_init.sh` writes the second from the first, so whatever a site was installed from is what it upgrades from. Nothing to configure and nothing to keep in sync: pass `--upgrade-server` and both follow, leave it off and the site tracks stable releases.

Cloned sites are the exception — they carry the source site's `upgrade_source`, which is the right answer for a copy of that site.

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

1. The installer checks whether the domain's DNS points to this server.
2. If it does, Certbot runs to fetch a Let's Encrypt certificate.
3. If it doesn't, the install goes ahead anyway and no certificate is issued. The vhost guards its `:443` block with `<IfFile>`, so a missing certificate means the site serves HTTP rather than Apache refusing to start.

DNS not being ready never stops an install, and it does not leave you anything to remember either.

#### The retry timer

An install that could not issue a certificate leaves behind a systemd timer, `joinery-ssl-retry@{domain}`, that finishes the job whenever DNS lands — minutes later or a week later. Nothing needs to be run by hand.

Each run resolves the domain first and only invokes Certbot when the A record actually points at this server. That is what makes an open-ended retry safe: Let's Encrypt allows five *failed validations* per hostname per hour, and a DNS lookup that comes back empty costs nothing against that budget. On a CA-issued certificate the timer disables itself and removes its config.

```bash
sudo systemctl list-timers 'joinery-ssl-retry@*'      # is one pending
sudo journalctl -fu joinery-ssl-retry@mysite.example.com   # what it is seeing
```

Its state is a single file per domain at `/etc/joinery/ssl-retry/{domain}.conf`. Delete it to stop the retries.

To issue immediately rather than wait for the next check:

```bash
sudo /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh mysite.example.com
```

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
sudo /var/www/html/{sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh mysite.example.com
```

Works for both modes — Docker sites terminate TLS at the host's reverse proxy, which is the same Apache the script reloads. It tries an HTTP-01 challenge, falls back to DNS-01 when a provider credential file is present at `/etc/letsencrypt/<provider>.ini`, and leaves the site on HTTP if neither succeeds.

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
