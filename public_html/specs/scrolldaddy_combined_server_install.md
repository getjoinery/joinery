# ScrollDaddy Combined Server Installation

**Status:** Kept on roadmap (owner, 2026-07-16); not scheduled. Updated
2026-07-16 for the plugin rename (`plugins/scrolldaddy` → `plugins/dns_filtering`,
settings `scrolldaddy_dns_*` → `dns_filtering_dns_*`). Note two later
developments to reconcile at build time:
- The plugin now supports a **secondary DNS server**
  (`dns_filtering_dns_secondary_*` settings). A combined install configures
  the primary only and leaves the secondary settings blank.
- `specs/edge_routing_tier.md` proposes a Caddy edge tier that also touches
  the "who owns 443/TLS" question for *managed* multi-node hosting. The two
  don't conflict — this spec is for a **self-hosted single box** where Apache
  owns 443 — but if the edge tier lands first, revisit whether the DoH proxy
  should ride the edge instead of local mod_proxy.

## Overview

Currently ScrollDaddy runs as a two-server architecture: a **web server** (Joinery PHP app in a Docker container) and a **DNS server** (Go binary). They share a PostgreSQL database via private network. (Current production IPs live in the Server Manager dashboard and Claude memory, not this spec.) This spec covers combining both onto a single server, analyzing what changes, and producing an installer that sets up the full stack.

See [scrolldaddy-deployment.md](implemented/scrolldaddy-deployment.md) for the existing two-server deployment spec.

## Why Single Server

The two-server design is ideal for production (independent failure domains, security isolation, independent scaling). A single-server option serves different use cases:

- **Self-hosted users** who want to run ScrollDaddy on their own VPS
- **Development/staging** environments
- **Cost-sensitive deployments** where a second VPS is not justified
- **Simpler operations** — one server to maintain, monitor, and back up

The trade-off is reduced isolation: if the web server goes down, DNS goes down too.

## Architecture Comparison

### Current: Two Servers

```
WEB SERVER (Docker)                    DNS SERVER
┌──────────────────────┐              ┌──────────────────────┐
│ Apache :443          │              │ Caddy :443           │
│ PHP 8.3              │              │   └→ :8053 (DoH)     │
│ PostgreSQL :5432 ────│─ private ──→ │ scrolldaddy-dns      │
│ Joinery + ScrollDaddy│   network    │   :8053 (localhost)  │
│ Blocklist downloader │              │   :853  (DoT)        │
└──────────────────────┘              └──────────────────────┘
```

### Proposed: Single Server

```
COMBINED SERVER
┌────────────────────────────────────────────────┐
│ Apache :443 (mod_rewrite, mod_php, mod_ssl)    │
│   scrolldaddy.app → Joinery PHP app            │
│   dns.scrolldaddy.app → mod_proxy → :8053      │
│                                                │
│ scrolldaddy-dns (Go binary)                    │
│   :8053 DoH (localhost, Apache proxies)         │
│   :853  DoT (public, Go TLS)                   │
│   DB: localhost:5432                            │
│                                                │
│ PostgreSQL :5432 (localhost only)               │
│                                                │
│ Blocklist downloader → http://127.0.0.1:8053   │
└────────────────────────────────────────────────┘
```

## DNS Records

All three records point to the same server IP:

```
scrolldaddy.app.           A     <SERVER_IP>    # Web app
dns.scrolldaddy.app.       A     <SERVER_IP>    # DoH endpoint
*.dns.scrolldaddy.app.     A     <SERVER_IP>    # DoT SNI routing
```

The wildcard record is required for DoT — each device connects to `{uid}.dns.scrolldaddy.app` and the Go binary extracts the device UID from the SNI hostname.

## Key Differences from Two-Server Setup

### 1. Port 443 Sharing (Biggest Change)

Both the web app and DNS endpoint need HTTPS on port 443. Currently Apache owns 443 on the web server and Caddy owns 443 on the DNS server.

**Solution: Apache handles everything.** Joinery has deep Apache dependencies (mod_rewrite routing via `.htaccess`, mod_php SAPI, dynamic `.htaccess` generation, `getallheaders()` in PHP, `Alias` directives for static files). Replacing Apache with Caddy or putting Caddy in front would break these features or add unnecessary complexity.

Instead, Apache serves both the web app and proxies the DNS DoH endpoint using mod_proxy, routing by hostname:

- `scrolldaddy.app` → standard Joinery PHP handling (mod_rewrite + mod_php)
- `dns.scrolldaddy.app` → `ProxyPass` to scrolldaddy-dns on `localhost:8053`
- `*.dns.scrolldaddy.app` → DoT on port 853 (bypasses Apache entirely, Go handles TLS)

**No Caddy needed.** Apache handles TLS for both domains via certbot (already part of the Joinery install process). This eliminates the port conflict without introducing a second web server.

**Apache VirtualHost for web app** (unchanged from standard Joinery):
```apache
<VirtualHost *:443>
    ServerName scrolldaddy.app
    DocumentRoot /var/www/html/scrolldaddy/public_html

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/scrolldaddy.app/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/scrolldaddy.app/privkey.pem

    RewriteEngine On
    RewriteBase /
    RewriteCond %{REQUEST_URI} !^/serve\.php$
    RewriteRule ^(.*)$ serve.php?__route=$1 [QSA,L]

    # ... standard Joinery directives ...
</VirtualHost>
```

**Apache VirtualHost for DoH proxy** (new):
```apache
<VirtualHost *:443>
    ServerName dns.scrolldaddy.app

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/dns.scrolldaddy.app/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/dns.scrolldaddy.app/privkey.pem

    # Only proxy DoH resolve and health endpoints
    ProxyPreserveHost On
    ProxyPass /resolve/ http://127.0.0.1:8053/resolve/
    ProxyPassReverse /resolve/ http://127.0.0.1:8053/resolve/
    ProxyPass /health http://127.0.0.1:8053/health
    ProxyPassReverse /health http://127.0.0.1:8053/health

    # Block everything else
    <Location />
        Require all denied
    </Location>
    <Location /resolve>
        Require all granted
    </Location>
    <Location /health>
        Require all granted
    </Location>
</VirtualHost>
```

**TLS certificates:** certbot obtains separate certs for `scrolldaddy.app` and `dns.scrolldaddy.app`. The DoT wildcard cert (`*.dns.scrolldaddy.app`) still needs DNS-01 challenge via certbot's DNS plugin (same as the two-server setup).

**Required Apache modules** (in addition to standard Joinery modules):
```bash
a2enmod proxy proxy_http
```

### 2. Database Connection (Simpler)

No private network, no remote DB user, no `pg_hba.conf` edits for remote access.

| Two-server | Single-server |
|-----------|---------------|
| DNS connects to `10.0.0.2:5432` via VPC | DNS connects to `localhost:5432` |
| Needs `scrolldaddy_reader` user with remote access | Can use same user or `scrolldaddy_reader` via localhost |
| `pg_hba.conf`: allow from DNS server IP | `pg_hba.conf`: localhost only (default) |
| PostgreSQL listens on VPC interface | PostgreSQL listens on localhost only (more secure) |

The `scrolldaddy_reader` user is still recommended (principle of least privilege — DNS server should not be able to write), but the `pg_hba.conf` only needs the default `local all all peer` or `host all all 127.0.0.1/32 scram-sha-256` entry. (The resolver's multi-database support, `SCD_JOINERY_DB_URLS`, is irrelevant here — a combined install serves one database.)

### 3. DNS Server Env Config

```bash
# Database — localhost, no remote connection
SCD_DB_HOST=localhost
SCD_DB_PORT=5432
SCD_DB_NAME=scrolldaddy
SCD_DB_USER=scrolldaddy_reader
SCD_DB_PASSWORD=<password>

# DoH — Apache proxies 443 → 8053
SCD_DOH_PORT=8053

# DoT — Go handles TLS directly on public port 853
SCD_DOT_PORT=853
SCD_DOT_CERT_FILE=/etc/scrolldaddy/dot-cert.pem
SCD_DOT_KEY_FILE=/etc/scrolldaddy/dot-key.pem
SCD_DOT_BASE_DOMAIN=dns.example.com

# Everything else same as two-server
SCD_UPSTREAM_PRIMARY=1.1.1.1:53
SCD_UPSTREAM_SECONDARY=8.8.8.8:53
SCD_RELOAD_INTERVAL=60
SCD_BLOCKLIST_RELOAD_INTERVAL=3600
SCD_LOG_LEVEL=info
SCD_LOG_FILE=/var/log/scrolldaddy/dns.log
SCD_API_KEY=<random_64_char_hex>
```

### 4. Firewall (Simpler)

```bash
ufw default deny incoming
ufw allow 22/tcp     # SSH
ufw allow 443/tcp    # HTTPS (Apache: both web and DoH)
ufw allow 853/tcp    # DoT
ufw enable
```

No need for cross-server rules on port 8053. The Go API is truly localhost-only.

### 5. DNS Server Internal URL Setting

The Joinery `dns_filtering_dns_internal_url` setting stays `http://127.0.0.1:8053` — same as it would be in a two-server setup where the web server calls the DNS API. The difference is this is now genuinely localhost, not crossing a network. The `dns_filtering_dns_secondary_*` settings stay blank (no secondary server in a combined install).

### 6. TLS Certificates

| Component | Two-server | Single-server |
|-----------|-----------|---------------|
| Web HTTPS | Apache + certbot (or Docker proxy) | Apache + certbot (same) |
| DoH HTTPS | Caddy auto-TLS | Apache mod_proxy + certbot |
| DoT TLS | Separate wildcard cert | certbot DNS-01 challenge |

certbot obtains standard certs for `scrolldaddy.app` and `dns.scrolldaddy.app` via HTTP-01 challenge. The DoT wildcard cert (`*.dns.scrolldaddy.app`) requires DNS-01 challenge via certbot's DNS plugin (e.g., `certbot-dns-cloudflare`). The Go binary loads the wildcard cert files directly for DoT.

### 7. Blocklist Downloader

No change needed. The `DownloadBlocklists` task (`plugins/dns_filtering/tasks/`) already triggers the resolver reload via the `dns_filtering_dns_internal_url` setting. Works identically on a single server (and skips the secondary-server reload when the secondary settings are blank).

### 8. Cron / Scheduled Tasks

Same as current: cron runs `process_scheduled_tasks.php` every 15 minutes as `www-data`. The blocklist downloader runs daily at 04:00, downloads lists, inserts into PostgreSQL, and triggers DNS reload.

## Installer Design

### What the Installer Does

A single script that sets up the complete ScrollDaddy stack on a fresh Ubuntu 24.04 server. It combines the existing Joinery `install.sh` workflow with the DNS server `scrolldaddy-dns-installer.sh` workflow, plus the Apache DoH proxy VirtualHost.

### Installation Phases

**Phase 1: System Dependencies**
- Update apt, install base packages
- Install Apache, PHP 8.3 + required extensions (including mod_proxy, mod_proxy_http)
- Install PostgreSQL
- Install certbot + DNS plugin (for wildcard DoT cert if needed)
- Create system users (`scrolldaddy` for DNS service, `www-data` for web)

**Phase 2: Joinery Web Application**
- Create directory structure (`/var/www/html/{sitename}/`)
- Create PostgreSQL database
- Load base schema from `joinery-install.sql.gz`
- Generate `Globalvars_site.php` from template
- Configure Apache virtualhost on port 443
- Install Composer dependencies
- Set file permissions
- Set up log rotation
- Install crontab for scheduled tasks

**Phase 3: ScrollDaddy DNS Server**
- Install pre-built `scrolldaddy-dns` binary to `/usr/local/bin/`
- Create `/etc/scrolldaddy/` directory with env config
- Create `scrolldaddy_reader` database user with SELECT-only grants
- Write systemd service unit
- Create log directories (`/var/log/scrolldaddy/`, `/var/log/scrolldaddy/queries/`)

**Phase 4: Apache DoH Proxy Configuration**
- Enable mod_proxy and mod_proxy_http
- Write Apache VirtualHost for `dns.{domain}` with ProxyPass to localhost:8053
- Run certbot for both web domain and DNS domain
- Optionally configure wildcard cert for DoT (requires DNS provider API token via certbot DNS plugin)

**Phase 5: Firewall**
- Configure UFW (ports 22, 80, 443, 853)
- Enable UFW

**Phase 6: Start Services**
- Start PostgreSQL (if not already running)
- Start Apache (serves web + proxies DoH)
- Start scrolldaddy-dns
- Verify health endpoints

### Interactive vs Non-Interactive

**Interactive mode** (default):
- Prompts for: domain name, DNS subdomain, database password, admin email
- Optionally prompts for: DoT wildcard cert setup (DNS provider API token)
- Tests database connectivity and DNS resolution
- Shows summary before proceeding

**Non-interactive mode** (`--non-interactive`):
- Requires config file or environment variables for all settings
- Writes example configs
- Does not start services
- User must review and edit configs before starting

### Upgrade Path

The combined installer should also support upgrading individual components:

```bash
# Upgrade Joinery web app only
./scrolldaddy-install.sh upgrade-web

# Upgrade DNS server only
./scrolldaddy-install.sh upgrade-dns VERSION=1.4.0

# Upgrade everything
./scrolldaddy-install.sh upgrade
```

### Required Inputs

| Input | Required | Default | Purpose |
|-------|----------|---------|---------|
| Site name | Yes | — | Database name, directory name |
| Domain | Yes | — | Primary web domain (e.g., `scrolldaddy.app`) |
| DNS subdomain | No | `dns.{domain}` | DoH endpoint domain |
| DB password | Auto-generated | Random 24-char | PostgreSQL password |
| Admin email | Yes | — | Initial admin account + Let's Encrypt contact |
| API key | Auto-generated | Random 64-char hex | DNS server API authentication |
| DoT setup | No | Disabled | Requires DNS provider API token for wildcard cert |

### Output Structure

After installation:

```
/var/www/html/{sitename}/
├── config/Globalvars_site.php
├── public_html/                     # Joinery web root
│   └── plugins/dns_filtering/      # DNS filtering plugin (ScrollDaddy brand)
├── uploads/
├── logs/
└── backups/

/usr/local/bin/scrolldaddy-dns       # DNS binary

/etc/scrolldaddy/
├── scrolldaddy.env                  # DNS server config
├── scrolldaddy.env.example
└── dns.json                         # Feature config (cache, query log)

/etc/apache2/sites-available/dns-{sitename}.conf  # DoH reverse proxy

/etc/systemd/system/scrolldaddy-dns.service

/var/log/scrolldaddy/
├── dns.log
└── queries/                         # Per-device query logs

/etc/apache2/sites-available/{sitename}.conf  # Joinery web app

/etc/cron.d/scrolldaddy-{sitename}   # Scheduled tasks cron
/etc/logrotate.d/scrolldaddy         # Log rotation
```

## Docker Variant

For users who prefer Docker, the combined setup can work with Docker Compose. The web container runs Apache + PHP + PostgreSQL (standard Joinery container pattern), and the DNS server runs as a sidecar container on the host network:

```yaml
services:
  web:
    image: joinery-scrolldaddy
    ports:
      - "443:443"
      - "80:80"
    volumes:
      - ./config:/var/www/html/scrolldaddy/config
      - ./uploads:/var/www/html/scrolldaddy/uploads
      - db_data:/var/lib/postgresql/data

  dns:
    image: scrolldaddy-dns
    network_mode: host   # needs localhost access to DB port exposed by web container
    ports:
      - "853:853"
    env_file: ./scrolldaddy.env
    depends_on:
      - web

volumes:
  db_data:
```

In this model, Apache inside the web container handles both the web app VirtualHost and the DoH proxy VirtualHost (same as bare-metal). The DNS binary connects to PostgreSQL via the container's exposed port. This is a future consideration — the initial combined installer targets bare-metal Ubuntu.

## Implementation Plan

### Approach: Extend `install.sh` with a `--with-dns` Flag

The existing Joinery `install.sh` already handles ~80% of the work (Apache, PHP, PostgreSQL, site setup, cron). Rather than creating a separate installer, add DNS server setup as an optional phase triggered by `--with-dns`. This keeps the base install untouched and makes DNS opt-in.

**Usage:**
```bash
# Standard Joinery install (no DNS)
./install.sh site scrolldaddy scrolldaddy.app

# Joinery + ScrollDaddy DNS on same server
./install.sh site scrolldaddy scrolldaddy.app --with-dns dns.scrolldaddy.app

# Add DNS to an existing Joinery site
./install.sh dns scrolldaddy dns.scrolldaddy.app
```

### Changes to Existing Scripts

**1. `install.sh` — add `--with-dns` flag and `dns` subcommand (~70 lines)**

The `dns` phase runs after the standard `site` setup (or standalone via `install.sh dns`). It:

- Validates the DNS domain argument
- Calls `_dns_init.sh` with the site name, DNS domain, and database credentials

Can also be invoked standalone: `./install.sh dns SITENAME DNS_DOMAIN` adds DNS to an existing Joinery site without re-running the full site setup.

**2. Create `_dns_init.sh` — DNS server setup (~120 lines)**

New script alongside `_site_init.sh`. Handles all DNS-specific setup:

```bash
# 1. System user
useradd --system --no-create-home --shell /usr/sbin/nologin scrolldaddy

# 2. Install binary (bundled in install_tools/ or downloaded)
cp scrolldaddy-dns /usr/local/bin/
chmod 755 /usr/local/bin/scrolldaddy-dns

# 3. Create directories
mkdir -p /etc/scrolldaddy /var/log/scrolldaddy/queries
chown scrolldaddy:scrolldaddy /var/log/scrolldaddy /var/log/scrolldaddy/queries

# 4. Create read-only DB user
psql -U postgres -d $DBNAME <<SQL
  CREATE USER scrolldaddy_reader WITH PASSWORD '$READER_PASSWORD';
  GRANT CONNECT ON DATABASE $DBNAME TO scrolldaddy_reader;
  GRANT USAGE ON SCHEMA public TO scrolldaddy_reader;
  GRANT SELECT ON ALL TABLES IN SCHEMA public TO scrolldaddy_reader;
  ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO scrolldaddy_reader;
SQL

# 5. Write scrolldaddy.env (templated)
cat > /etc/scrolldaddy/scrolldaddy.env <<EOF
SCD_DB_HOST=localhost
SCD_DB_PORT=5432
SCD_DB_NAME=$DBNAME
SCD_DB_USER=scrolldaddy_reader
SCD_DB_PASSWORD=$READER_PASSWORD
SCD_DOH_PORT=8053
SCD_API_KEY=$API_KEY
...
EOF
chown root:scrolldaddy /etc/scrolldaddy/scrolldaddy.env
chmod 640 /etc/scrolldaddy/scrolldaddy.env

# 6. Write systemd service unit
cp scrolldaddy-dns.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable scrolldaddy-dns

# 7. Apache: enable proxy modules, write DoH VirtualHost
a2enmod proxy proxy_http
cp default_dns_virtualhost.conf /etc/apache2/sites-available/dns-$SITENAME.conf
sed -i "s/{{DNS_DOMAIN}}/$DNS_DOMAIN/g" /etc/apache2/sites-available/dns-$SITENAME.conf
a2ensite dns-$SITENAME.conf

# 8. Certbot for DNS domain
certbot --apache -d $DNS_DOMAIN --non-interactive --agree-tos

# 9. Update Joinery settings
psql -U postgres -d $DBNAME <<SQL
  UPDATE stg_settings SET stg_value = '$DNS_DOMAIN' WHERE stg_name = 'dns_filtering_dns_host';
  UPDATE stg_settings SET stg_value = 'http://127.0.0.1:8053' WHERE stg_name = 'dns_filtering_dns_internal_url';
  UPDATE stg_settings SET stg_value = '$API_KEY' WHERE stg_name = 'dns_filtering_dns_api_key';
SQL

# 10. Start
systemctl start scrolldaddy-dns
systemctl reload apache2
```

**3. Create `default_dns_virtualhost.conf` — Apache DoH proxy template**

New file in `install_tools/`:

```apache
<VirtualHost *:80>
    ServerName {{DNS_DOMAIN}}
    # certbot will add redirect to 443
</VirtualHost>

<VirtualHost *:443>
    ServerName {{DNS_DOMAIN}}

    # TLS (certbot will populate these)
    # SSLEngine on
    # SSLCertificateFile ...
    # SSLCertificateKeyFile ...

    ProxyPreserveHost On
    ProxyPass /resolve/ http://127.0.0.1:8053/resolve/
    ProxyPassReverse /resolve/ http://127.0.0.1:8053/resolve/
    ProxyPass /health http://127.0.0.1:8053/health
    ProxyPassReverse /health http://127.0.0.1:8053/health

    <Location />
        Require all denied
    </Location>
    <Location /resolve>
        Require all granted
    </Location>
    <Location /health>
        Require all granted
    </Location>
</VirtualHost>
```

**4. Bundle DNS binary in `install_tools/`**

The `scrolldaddy-dns` binary (linux/amd64, ~10MB) is included in `install_tools/` alongside the existing `joinery-install.sql.gz`. The `make release` target in the scrolldaddy-dns repo copies the built binary into the Joinery install_tools directory.

Alternatively, `_dns_init.sh` downloads the latest release from a URL if the binary isn't bundled locally.

**5. Bundle systemd service unit template in `install_tools/`**

`default_scrolldaddy-dns.service` — same as the existing DNS installer's embedded service unit.

### Files Added/Modified

| File | Action | Purpose |
|------|--------|---------|
| `install_tools/install.sh` | Modified | Add `--with-dns` flag and `dns` subcommand |
| `install_tools/_dns_init.sh` | New | DNS server setup (binary, DB user, env, systemd, Apache proxy) |
| `install_tools/default_dns_virtualhost.conf` | New | Apache DoH proxy VirtualHost template |
| `install_tools/default_scrolldaddy-dns.service` | New | systemd service unit template |
| `install_tools/scrolldaddy-dns` | New | Pre-built DNS binary (linux/amd64) |

### Test Matrix

- Ubuntu 24.04, fresh install with `--with-dns`
- Ubuntu 24.04, add DNS to existing Joinery site via `install.sh dns`
- Upgrade DNS binary on combined server (via existing `scrolldaddy-dns-installer.sh --verbose`)
- Upgrade Joinery web app without affecting DNS service

## Security Notes

- PostgreSQL only listens on localhost (no network exposure)
- Apache handles public TLS for both web and DoH domains via certbot
- DNS API (:8053) is localhost-only (Apache proxies only `/resolve/*` and `/health`)
- DNS binary runs as unprivileged `scrolldaddy` user with systemd hardening
- `scrolldaddy_reader` DB user has SELECT-only access
- UFW blocks everything except SSH (22), HTTP (80, for certbot), HTTPS (443), and DoT (853)

## Limitations vs Two-Server

| Concern | Two-server | Single-server |
|---------|-----------|---------------|
| Failure isolation | DNS survives web outage | Both fail together |
| Security isolation | DNS has minimal attack surface | Web app compromise could affect DNS |
| Scaling DNS | Add more DNS servers independently | Must scale entire stack |
| Resource contention | Independent resources | Shared CPU/memory |
| Blocklist loading | ~333MB peak on DNS server alone | Shares memory with web app + DB |
| Maintenance windows | Can update web without DNS downtime | Any restart affects both |

For most self-hosted users with fewer than ~1000 devices, a single server with 2GB+ RAM handles the full stack comfortably.
