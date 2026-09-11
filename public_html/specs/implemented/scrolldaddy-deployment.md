# ScrollDaddy Production Deployment Specification

**Status:** Draft
**Related specs:**
- [ScrollDaddy DNS Service](scrolldaddy-dns-service.md) — Go application spec
- [ControlD to ScrollDaddy Migration](scrolldaddy-migration.md) — Plugin conversion spec

---

## 1. Architecture Overview

ScrollDaddy is a two-server system: a **web server** running the Joinery PHP application with the scrolldaddy plugin, and a **DNS server** running the Go DNS service. They share a single PostgreSQL database.

```
┌─────────────────────────────────────┐       ┌─────────────────────────────────────┐
│  WEB SERVER                         │       │  DNS SERVER                          │
│                                     │       │                                      │
│  ┌───────────────────────────────┐  │       │  ┌────────────────────────────────┐  │
│  │ Apache                        │  │       │  │ Caddy (reverse proxy)           │  │
│  │ :443 ── scrolldaddy.app ──────│──│──┐    │  │ :443 ── dns.scrolldaddy.app ───│──│── DoH
│  │ :443 ── admin.scrolldaddy.app │  │  │    │  │ Auto-TLS (Let's Encrypt)       │  │
│  └───────────────────────────────┘  │  │    │  └────────────────────────────────┘  │
│                                     │  │    │         │ ProxyPass                   │
│  ┌───────────────────────────────┐  │  │    │         ▼                             │
│  │ Joinery PHP App               │  │  │    │  ┌────────────────────────────────┐  │
│  │ + scrolldaddy plugin          │  │  │    │  │ scrolldaddy-dns (Go binary)    │  │
│  │ + admin UI                    │  │  │    │  │ :8053 DoH (localhost only)     │  │
│  │ + blocklist downloader task   │  │  │    │  │ :853  DoT (public, Go TLS)     │  │
│  └───────────────────────────────┘  │  │    │  └────────────────────────────────┘  │
│                                     │  │    │         │                             │
│  ┌───────────────────────────────┐  │  │    │         │ reads (every 60s/3600s)    │
│  │ PostgreSQL                    │──│──│────│─────────┘                             │
│  │ :5432 (private network only)  │  │  │    │                                      │
│  └───────────────────────────────┘  │  │    │                                      │
│                                     │  │    │                                      │
└─────────────────────────────────────┘  │    └──────────────────────────────────────┘
                                         │
                                    User browser
                                 (sign up, manage devices)
```

### Why Two Servers

- **Independent failure domains.** DNS going down = users' internet breaks. The web UI going down just means they can't manage settings. These must fail independently.
- **Different traffic profiles.** DNS gets constant, high-volume queries. The web UI gets occasional management traffic.
- **Security isolation.** The DNS server has minimal attack surface — just the Go binary. No PHP, no Apache, no admin interface.
- **Independent scaling.** Adding DNS capacity later means deploying the same binary on more servers. The web server stays unchanged.

### How They Communicate

Both connect to the same PostgreSQL database:

- **Web server writes** — Creating devices, toggling filters, managing rules, downloading blocklists
- **DNS server reads** — Loading device configs (every 60s) and blocklist domains (every 3600s) into in-memory cache

The web server also calls the DNS server's HTTP API (port 8053) for two purposes:
1. **Blocklist reload trigger** — after a blocklist download, POST `/reload` to force an immediate cache refresh (optional — DNS will pick up changes on its next scheduled reload regardless)
2. **Last-seen queries** — the devices page calls `GET /device/{uid}/seen` to show when each device last made a DNS query. Requires `SCD_API_KEY` authentication.

---

## 2. Domain Structure

| Domain | Points To | Purpose |
|--------|-----------|---------|
| `scrolldaddy.app` | Web server IP | Public site, user signup, device management |
| `dns.scrolldaddy.app` | DNS server IP | DoH endpoint (`/resolve/{uid}`) |
| `*.dns.scrolldaddy.app` | DNS server IP | DoT SNI routing (`{uid}.dns.scrolldaddy.app`) |

**DNS records needed:**

```
scrolldaddy.app.           A     <WEB_SERVER_IP>
dns.scrolldaddy.app.       A     <DNS_SERVER_IP>
*.dns.scrolldaddy.app.     A     <DNS_SERVER_IP>
```

> **Decision:** Domain is `scrolldaddy.app`.

---

## 3. Web Server Setup

The web server runs the Joinery PHP application. This follows the standard Joinery Docker deployment pattern (see `docs/deploy_and_upgrade.md`).

### 3.1 Server Requirements

- Docker host (existing Joinery deployment pattern)
- Joinery container with Apache + PHP + PostgreSQL
- Public ports: 80, 443
- The scrolldaddy plugin activated in Joinery admin

### 3.2 Plugin Configuration

After activating the scrolldaddy plugin, set these values in Joinery admin settings:

| Setting | Value | Purpose |
|---------|-------|---------|
| `scrolldaddy_dns_host` | `dns.scrolldaddy.app` | Used to build DoH URLs shown to users |
| `scrolldaddy_dns_internal_url` | `http://<DNS_SERVER_IP>:8053` | For DNS API calls (reload trigger, last-seen queries) |
| `scrolldaddy_dns_api_key` | `<random 64-char hex>` | Authenticates web server calls to DNS API |

### 3.3 PostgreSQL Access for DNS Server

The DNS server needs read access to PostgreSQL on the web server. The database must accept connections from the DNS server's IP.

**Inside the Joinery container's `pg_hba.conf`:**
```
# Allow DNS server to connect (read-only user)
host    joinerydb    scrolldaddy_reader    <DNS_SERVER_IP>/32    scram-sha-256
```

**Create a read-only database user:**
```sql
CREATE USER scrolldaddy_reader WITH PASSWORD '<strong_random_password>';
GRANT CONNECT ON DATABASE joinerydb TO scrolldaddy_reader;
GRANT USAGE ON SCHEMA public TO scrolldaddy_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO scrolldaddy_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO scrolldaddy_reader;
```

**Port exposure:** PostgreSQL port 5432 must be accessible from the DNS server. Both servers are on Linode, so we use **Linode VPC** (private networking). See Section 3.5 for details.

### 3.5 Private Networking (Linode VPC)

Both servers are on Linode, so we use **Linode VPC** for the database connection. This keeps PostgreSQL traffic off the public internet entirely.

**How Linode VPC works:**
- Create a VPC in the Linode Cloud Manager with a private subnet (e.g., `10.0.0.0/24`)
- Assign both Linodes to the VPC — each gets a private IP on the `10.0.0.x` subnet
- Traffic between the two private IPs stays on Linode's internal network (never hits the internet)
- No encryption needed — the traffic never leaves Linode's infrastructure
- No bandwidth charges for VPC traffic

**Setup:**
1. In Linode Cloud Manager: Networking → VPCs → Create VPC
2. Subnet: `10.0.0.0/24`, region: same region as both Linodes
3. Assign web server Linode → gets e.g., `10.0.0.2`
4. Assign DNS server Linode → gets e.g., `10.0.0.3`
5. Configure PostgreSQL to listen on the VPC interface (`10.0.0.2`)
6. `pg_hba.conf`: allow `scrolldaddy_reader` from `10.0.0.3/32`
7. DNS server's `SCD_DB_HOST=10.0.0.2`

**Important:** Both Linodes must be in the **same region** for VPC to work. If the DNS server needs to be in a different region later (Phase 2 growth), that server would use WireGuard instead.

**Reload trigger:** The web server can also reach the DNS server's `:8053` via `10.0.0.3:8053` for `/reload` POST calls, with no public firewall rules needed.

### 3.4 Blocklist Downloader

The scheduled task `DownloadBlocklists` runs on the web server (inside the Joinery container). After downloading and storing blocklist domains, it optionally POSTs to the DNS server's `/reload` endpoint.

The `/reload` endpoint on the DNS server is localhost-only by default. To allow the web server to trigger it:
- Configure the Go service with `SCD_RELOAD_ALLOWED_IPS=<WEB_SERVER_IP>` (requires adding this feature to the Go service)
- Or skip the trigger — the DNS server reloads blocklists on its own every 3600s

---

## 4. DNS Server Setup

The DNS server is a lightweight VPS running only the Go DNS binary and a reverse proxy for TLS.

### 4.1 Server Requirements

- **Linode VPS** (same provider as web server, enables VLAN private networking)
- Linux (Ubuntu 22.04+ or Debian 12+)
- Minimal resources: 1 CPU, 1 GB RAM (Go service uses ~50-200 MB depending on blocklist size)
- Public ports: 443 (DoH via Caddy), 853 (DoT via Go)
- Private port: 8053 (Go DoH listener, localhost only)

### 4.2 Software Stack

| Component | Purpose |
|-----------|---------|
| `scrolldaddy-dns` | Go DNS binary (handles all DNS logic) |
| Caddy | Reverse proxy for DoH, auto-TLS for `dns.scrolldaddy.app` |
| systemd | Process management for both Caddy and scrolldaddy-dns |

**Why Caddy over nginx/Apache:**
- Automatic Let's Encrypt TLS with zero configuration (including wildcard certs via DNS-01 challenge)
- Simple Caddyfile syntax for reverse proxy
- No need for certbot, cron renewal, etc.
- Perfect for a single-purpose proxy server

### 4.3 Directory Layout

```
/usr/local/bin/scrolldaddy-dns          # Go binary
/etc/scrolldaddy/
├── scrolldaddy.env                     # Environment variables (DB creds, config)
├── dot-cert.pem                        # Wildcard TLS cert for DoT
└── dot-key.pem                         # Wildcard TLS key for DoT
/etc/caddy/Caddyfile                    # Caddy reverse proxy config
/var/log/scrolldaddy/
└── dns.log                             # Go service log file
```

### 4.4 Environment Configuration

`/etc/scrolldaddy/scrolldaddy.env`:
```bash
# Database (read-only connection to web server's PostgreSQL via Linode VPC)
SCD_DB_HOST=10.0.0.2
SCD_DB_PORT=5432
SCD_DB_NAME=joinerydb
SCD_DB_USER=scrolldaddy_reader
SCD_DB_PASSWORD=<strong_random_password>

# DoH (Caddy proxies HTTPS → this port)
SCD_DOH_PORT=8053

# DoT (Go handles TLS directly)
SCD_DOT_PORT=853
SCD_DOT_CERT_FILE=/etc/scrolldaddy/dot-cert.pem
SCD_DOT_KEY_FILE=/etc/scrolldaddy/dot-key.pem
SCD_DOT_BASE_DOMAIN=dns.scrolldaddy.app

# Upstream DNS
SCD_UPSTREAM_PRIMARY=1.1.1.1:53
SCD_UPSTREAM_SECONDARY=8.8.8.8:53

# Cache reload intervals
SCD_RELOAD_INTERVAL=60
SCD_BLOCKLIST_RELOAD_INTERVAL=3600

# Logging
SCD_LOG_LEVEL=info
SCD_LOG_FILE=/var/log/scrolldaddy/dns.log

# API authentication (required for /device/{uid}/seen endpoint)
# Must match scrolldaddy_dns_api_key setting in Joinery admin
SCD_API_KEY=<random_64_char_hex>
```

### 4.5 systemd Service Unit

`/etc/systemd/system/scrolldaddy-dns.service`:
```ini
[Unit]
Description=ScrollDaddy DNS Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=scrolldaddy
Group=scrolldaddy
EnvironmentFile=/etc/scrolldaddy/scrolldaddy.env
ExecStart=/usr/local/bin/scrolldaddy-dns
Restart=always
RestartSec=5
StandardOutput=append:/var/log/scrolldaddy/dns.log
StandardError=append:/var/log/scrolldaddy/dns.log

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadOnlyPaths=/etc/scrolldaddy
ReadWritePaths=/var/log/scrolldaddy

# Allow binding to privileged port 853
AmbientCapabilities=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
```

### 4.6 Caddy Configuration

`/etc/caddy/Caddyfile`:
```
dns.scrolldaddy.app {
    reverse_proxy localhost:8053

    # Health check passthrough
    handle /health {
        reverse_proxy localhost:8053
    }
}
```

Caddy automatically obtains and renews the TLS certificate for `dns.scrolldaddy.app` via Let's Encrypt.

### 4.7 DoT Wildcard Certificate

DoT requires a wildcard cert for `*.dns.scrolldaddy.app` because each device connects to `{uid}.dns.scrolldaddy.app`. The Go service loads this cert directly.

**Option A: Caddy with DNS-01 challenge (recommended)**

Caddy can obtain wildcard certs if configured with a DNS provider plugin. Add to Caddyfile:
```
*.dns.scrolldaddy.app {
    tls {
        dns cloudflare {env.CLOUDFLARE_API_TOKEN}
    }
    # This block exists only to obtain the wildcard cert.
    # DoT traffic goes directly to Go on :853, not through Caddy.
    respond "Not Found" 404
}
```

Then symlink or copy the cert files for the Go service:
```bash
# Caddy stores certs at:
# /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/
# Wildcard cert: *.dns.scrolldaddy.app/
ln -s /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/wildcard_.dns.scrolldaddy.app/wildcard_.dns.scrolldaddy.app.crt /etc/scrolldaddy/dot-cert.pem
ln -s /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/wildcard_.dns.scrolldaddy.app/wildcard_.dns.scrolldaddy.app.key /etc/scrolldaddy/dot-key.pem
```

**Option B: certbot with DNS plugin**

If Caddy's DNS plugin approach is too complex:
```bash
certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/cloudflare.ini \
  -d "*.dns.scrolldaddy.app" \
  --preferred-challenges dns-01
```

Then point the Go service at certbot's cert files and set up a renewal hook to restart the service.

### 4.8 Firewall Rules

```bash
# UFW example
ufw default deny incoming
ufw allow 22/tcp          # SSH
ufw allow 443/tcp         # DoH (Caddy)
ufw allow 853/tcp         # DoT (Go)
ufw allow from <WEB_SERVER_IP> to any port 8053  # Web server API access (reload + last-seen)
ufw enable
```

Port 8053 is only exposed to the web server. The Go service exposes these endpoints on that port:
- `GET /device/{uid}/seen` — requires `X-API-Key` header matching `SCD_API_KEY`; returns `{"seen": true/false, "last_seen": "<RFC3339 timestamp or null>"}`
- `POST /reload` — localhost only
- `GET /stats` — localhost only
- `GET /test` — localhost only

---

## 5. Build and Deploy Procedure

### 5.1 Initial DNS Server Setup

Run these steps on the DNS server:

```bash
# 1. Create service user
sudo useradd --system --no-create-home --shell /usr/sbin/nologin scrolldaddy

# 2. Create directories
sudo mkdir -p /etc/scrolldaddy /var/log/scrolldaddy
sudo chown scrolldaddy:scrolldaddy /var/log/scrolldaddy

# 3. Install Go (if building on server)
sudo apt install golang-go

# 4. Build the binary
cd /tmp
git clone <repo_url> joinery
cd joinery/public_html/scrolldaddy-dns
go build -o scrolldaddy-dns ./cmd/dns/
sudo cp scrolldaddy-dns /usr/local/bin/
sudo chmod 755 /usr/local/bin/scrolldaddy-dns

# 5. Install Caddy
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install caddy

# 6. Write config files (scrolldaddy.env, Caddyfile)
# See sections 4.4 and 4.6 above

# 7. Set permissions
sudo chown root:scrolldaddy /etc/scrolldaddy/scrolldaddy.env
sudo chmod 640 /etc/scrolldaddy/scrolldaddy.env

# 8. Install and start systemd service
sudo cp scrolldaddy-dns.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable scrolldaddy-dns
sudo systemctl start scrolldaddy-dns

# 9. Start Caddy
sudo systemctl enable caddy
sudo systemctl start caddy
```

### 5.2 Updating the DNS Binary

When the Go code changes:

```bash
# On build machine (or the DNS server itself)
cd scrolldaddy-dns/
go build -o scrolldaddy-dns ./cmd/dns/

# Deploy
scp scrolldaddy-dns dns-server:/tmp/
ssh dns-server 'sudo systemctl stop scrolldaddy-dns && sudo cp /tmp/scrolldaddy-dns /usr/local/bin/ && sudo systemctl start scrolldaddy-dns'
```

Or send `SIGHUP` for a config-only reload (no binary update needed):
```bash
sudo systemctl reload scrolldaddy-dns
```

### 5.3 Verifying the Deployment

```bash
# On the DNS server — check service is running
sudo systemctl status scrolldaddy-dns

# Check health endpoint (via Caddy)
curl https://dns.scrolldaddy.app/health

# Check health endpoint (direct)
curl http://localhost:8053/health

# Test DNS resolution with a known device UID
curl "https://dns.scrolldaddy.app/resolve/<test_uid>?name=example.com&type=A"

# Check DoT (requires a test device UID)
# kdig is from the knot-dnsutils package
kdig @dns.scrolldaddy.app -p 853 +tls-sni=<test_uid>.dns.scrolldaddy.app example.com A

# Check logs
sudo tail -f /var/log/scrolldaddy/dns.log

# Check stats (from DNS server only)
curl http://localhost:8053/stats
```

---

## 6. Monitoring

### 6.1 Health Checks

Set up external uptime monitoring (e.g., UptimeRobot, Hetrixtools, or similar) for:

| Check | URL/Target | Frequency | Alert |
|-------|-----------|-----------|-------|
| DoH health | `https://dns.scrolldaddy.app/health` | 60s | If non-200 for 2+ checks |
| DoT port | `dns.scrolldaddy.app:853` TCP | 60s | If connection refused |
| Web UI | `https://scrolldaddy.app/` | 300s | If non-200 for 2+ checks |

### 6.2 Log Monitoring

The Go service logs to `/var/log/scrolldaddy/dns.log`. Key things to watch:

- `ERROR` lines — database connection failures, cert loading errors
- `WARN` lines — reload failures, schema validation issues
- Reload timing — ensure light reloads complete in < 1s

Set up log rotation:

`/etc/logrotate.d/scrolldaddy`:
```
/var/log/scrolldaddy/dns.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    postrotate
        systemctl kill --signal=SIGHUP scrolldaddy-dns
    endscript
}
```

### 6.3 Resource Monitoring

The Go service is lightweight, but monitor:
- **Memory** — Grows with blocklist size. ~50 MB base + ~1 MB per 100K blocked domains.
- **CPU** — Should be near zero. Spikes only during cache reloads.
- **Open file descriptors** — Each active DoT connection holds one. Relevant at scale.
- **PostgreSQL connections** — The Go service uses a connection pool (default max 10).

---

## 7. Security Considerations

### 7.1 DNS Server Hardening

- Run the Go binary as a dedicated non-root user (`scrolldaddy`)
- systemd security directives: `NoNewPrivileges`, `ProtectSystem=strict`, `ProtectHome=true`
- Only `CAP_NET_BIND_SERVICE` granted (for port 853)
- No shell access for the service user
- Firewall: only 443, 853, and SSH open
- Management endpoints (`/stats`, `/reload`, `/test`) are localhost-only
- `/device/{uid}/seen` is accessible from the web server IP only (firewall) and additionally requires `SCD_API_KEY` authentication

### 7.2 Database Security

- Read-only database user — the DNS service cannot modify any data
- Connection over private network or encrypted tunnel (never plain internet)
- Strong random password, rotated periodically
- `pg_hba.conf` restricts access to only the DNS server's IP

### 7.3 TLS

- DoH: Caddy handles TLS with automatic cert renewal
- DoT: Go loads wildcard cert, requires cert renewal automation (see 4.7)
- All DNS traffic is encrypted end-to-end (that's the entire point of DoH/DoT)

---

## 8. Growth Path

### Phase 1: Launch (Current Plan)

- 1 web server (existing Joinery/Linode deployment)
- 1 DNS server (new Linode VPS, same region)
- PostgreSQL connection over Linode VPC (`10.0.0.0/24`)

### Phase 2: Redundancy

- Add a second DNS server in a different region
- Both read from the same PostgreSQL (acceptable latency for 60s cache refresh)
- Use geographic DNS (e.g., Cloudflare load balancing) to route users to nearest server
- Web server unchanged

### Phase 3: Scale

- PostgreSQL read replica(s) co-located with DNS servers (eliminates cross-region DB latency)
- Anycast IP for DNS servers (single IP, routed to nearest)
- Multiple web servers behind a load balancer (if needed — unlikely at DNS-service scale)

### Adding a New DNS Node

The Go binary is fully stateless. Adding a DNS node is:

1. Provision a VPS
2. Copy the binary and env file (update DB host if using a local replica)
3. Set up Caddy + wildcard cert
4. Start the service
5. Add the server's IP to DNS records

The new node self-populates its cache from the database on startup. No data migration, no coordination with other nodes.

---

## 9. Decisions

1. **Domain name** — `scrolldaddy.app`
2. **VPS provider for DNS server** — Linode (same provider as web server)
3. **Private networking method** — Linode VPC (both servers same region, private `10.0.0.0/24` subnet)
4. **DNS provider for domain** — Cloudflare (free tier, DNS-01 challenge support for wildcard certs via Caddy)
5. **Monitoring service** — UptimeRobot (existing subscription)
