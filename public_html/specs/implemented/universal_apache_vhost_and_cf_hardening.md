# Universal Apache Vhost and Cloudflare Flexible-Mode Hardening

## Background

`install.sh` previously produced two structurally different Apache vhosts depending on whether `CLOUDFLARE_PROXY=1` was set: bare-metal sites and Docker-proxy sites diverged in shape, and certbot's default behaviour inserted an HTTP→HTTPS redirect that loops infinitely when Cloudflare's SSL mode is set to **Flexible** (CF talks HTTPS to the browser, HTTP to origin; the redirect bounces forever between the two layers).

The Flexible-mode loop was a total-site-loss class of failure with no clear root cause in logs — just `AH00124: Request exceeded the limit of 10 internal redirects`. It hit `dev.getjoinery.com` after a domain migration, and the per-host drift made every site's posture an "it depends" diagnostic.

## Goal

One universal Apache vhost shape, identical for every site, that works in every front-end posture (Cloudflare in any SSL mode, other CDN, or direct-to-origin) and works regardless of whether the origin has its own TLS certificate. Origin SSL is opt-in.

## The vhost shape

Three `<VirtualHost>` blocks per site, with optional cert-gated activation of the HTTPS block:

```apache
# HTTP — proxies/serves directly. The redirect rule fires when no proxy is
# in front; the CF-Visitor guard prevents an infinite loop when CF is in
# front in Flexible mode (CF talks HTTPS to browser, HTTP to origin).
<VirtualHost *:80>
    ServerName {{DOMAIN_NAME}}

    # ... site-specific block (DocumentRoot or ProxyPass) ...

    RewriteEngine On
    RewriteCond %{HTTP:CF-Visitor} !"scheme":"https"
    RewriteCond %{SERVER_NAME} ={{DOMAIN_NAME}}
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

# HTTPS — only activates when a cert exists at the standard path.
# Apache evaluates <IfFile> at config-parse time; missing cert = block
# silently skipped, no parse error. Any TLS-terminating proxy in front
# still handles HTTPS at the edge for sites with no origin cert.
<IfFile /etc/letsencrypt/live/{{DOMAIN_NAME}}/fullchain.pem>
<VirtualHost *:443>
    ServerName {{DOMAIN_NAME}}

    # ... same site-specific block as :80 ...

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/{{DOMAIN_NAME}}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{{DOMAIN_NAME}}/privkey.pem
</VirtualHost>
</IfFile>

# Canonical-host redirect: www.{{DOMAIN_NAME}} -> {{DOMAIN_NAME}}.
<VirtualHost *:80>
    ServerName www.{{DOMAIN_NAME}}
    RewriteEngine On
    RewriteRule ^(.*)$ https://{{DOMAIN_NAME}}$1 [R=301,L]
</VirtualHost>
```

Behaviour matrix (intentionally identical config file across all rows):

| Front-end posture | Origin cert? | Behaviour |
|---|---|---|
| No proxy in front, browser direct | Yes | `:80` redirect fires (guard is no-op, header absent). Browser lands on `:443`. |
| No proxy in front, browser direct | No | `:80` redirect fires. Browser follows to `https://` but origin has no `:443` listener — operator either issues a cert (see below) or accepts that direct HTTP-only access is the deployment posture. |
| Cloudflare, Full (strict) mode | Yes | CF talks HTTPS to origin → request lands on `:443`. Redirect never runs. |
| Cloudflare, Flexible mode | Either | CF talks HTTP to origin → guard skips the redirect → `:80` serves the page → CF wraps HTTPS to browser. No loop. `:443` vhost dormant under `<IfFile>` if no cert. |

The `<IfFile>` guard means origin SSL is **opt-in**, not required by the template. Sites relying on CF for all TLS termination work with no cert provisioning step. Sites with an LE cert at the standard path automatically get the `:443` vhost on next Apache reload.

## Template files (one source of truth per deployment mode)

Two `.conf` files under `maintenance_scripts/install_tools/`, both implementing the shape above, differing only in the site-specific block:

- **`default_virtualhost.conf`** — bare-metal sites: `DocumentRoot /var/www/html/{{SITE_NAME}}/public_html` plus a `<Directory>` block. Also includes a per-site test-vhost. Used by `_site_init.sh` for bare-metal installs.
- **`default_proxy_vhost.conf`** — Docker reverse-proxy sites: `ProxyPass / http://127.0.0.1:{{PORT}}/`. Used by `install.sh`'s `write_universal_vhost` for Docker-proxy installs.

Placeholders: `{{DOMAIN_NAME}}`, `{{SITE_NAME}}`, `{{SERVER_IP}}` (bare-metal only), `{{PORT}}` (proxy only). Substituted via `sed`.

`install.sh write_universal_vhost` is a thin substituter: reads the appropriate template, sed-substitutes, writes to `/etc/apache2/sites-available/${sitename}.conf`, enables required mods, `a2ensite`. No heredoc, no buried vhost shape in shell code.

## Cert provisioning (opt-in, vendor-agnostic)

`install.sh provision_origin_cert` runs at install time. Two-step decision tree; **no self-signed fallback**; never fails the install:

1. **Domain resolves to this server** → `certbot --apache -d <domain> --no-redirect` (HTTP-01 challenge). The `--no-redirect` flag prevents certbot from inserting its own HTTP→HTTPS redirect — the template owns redirect placement.
2. **Domain resolves elsewhere** (CDN, proxy) **and** a credentials file exists at `/etc/letsencrypt/<provider>.ini` → DNS-01 challenge via the matching certbot plugin:

   | NS pattern                | certbot plugin              | credentials file                          |
   |---------------------------|-----------------------------|-------------------------------------------|
   | `*.ns.cloudflare.com`     | `certbot-dns-cloudflare`    | `/etc/letsencrypt/cloudflare.ini`         |
   | `awsdns-*`                | `certbot-dns-route53`       | `/etc/letsencrypt/route53.ini`            |
   | `ns[1-5].linode.com`      | `certbot-dns-linode`        | `/etc/letsencrypt/linode.ini`             |
   | `ns[1-3].digitalocean.com`| `certbot-dns-digitalocean`  | `/etc/letsencrypt/digitalocean.ini`       |

   Add new providers by extending `detect_dns_provider` (one `case` clause) plus the table.

If neither path produces a cert, the function exits silently with a one-line pointer to `sysadmin_tools/setup_ssl.sh`. The `<IfFile>` guard means the `:443` vhost stays dormant; the site still serves on `:80` and CF (or another front-end) handles edge TLS.

`sysadmin_tools/setup_ssl.sh <domain>` is a small standalone helper that sources `install.sh` and re-runs `provision_origin_cert` for one domain. Use it to upgrade no-cert → LE later (e.g. after dropping a CF API token at `/etc/letsencrypt/cloudflare.ini`) without re-running the full installer. The `:443` block begins serving on the next Apache reload once a cert appears at the standard path.

## Uptime monitor changes

Two small adjustments to `RunNodeUptimeChecks` so a Flexible-mode loop on any managed node fires the existing "down" alert email instead of being misread as "up":

1. **`api` check** — `fetch_status_via_api` returns `reason='status'` for non-200 responses. Previously treated as "up" (server responded). Now: when `reason='status'` and the status code is 300–399, return `['ok' => false, 'message' => 'unexpected redirect (HTTP N) — possible infrastructure misconfiguration']`. A 3xx on `/api/v1/management/stats` means the request never reached the API handler.
2. **`http_status` check** — bumped `CURLOPT_MAXREDIRS` from 3 to 5 to tolerate legitimate redirect chains while still catching real loops (curl returns an errno when the cap is exhausted).

## Settings-page warning banner

`/admin/admin_settings.php` renders a yellow banner at the top of the page when it detects CF Flexible mode is active (`CF-Visitor: {"scheme":"https"}` header present and `$_SERVER['HTTPS']` unset). The banner explains the misconfig and recommends switching the CF zone SSL mode to Full (strict). No email, no scheduled task, no DB state — the banner appears only for the admin who happens to load the page, and disappears as soon as the misconfig is fixed.

This intentionally trades "proactive alert" for "near-zero implementation cost." The CF-Visitor guard keeps the site running in the meantime, so timing of the operator's notice isn't critical.

## File reference

| Path | Purpose |
|---|---|
| `maintenance_scripts/install_tools/default_virtualhost.conf` | Bare-metal vhost template |
| `maintenance_scripts/install_tools/default_proxy_vhost.conf` | Docker-proxy vhost template |
| `maintenance_scripts/install_tools/install.sh` | Contains `write_universal_vhost`, `provision_origin_cert`, `detect_dns_provider` |
| `maintenance_scripts/sysadmin_tools/setup_ssl.sh` | Standalone re-run of `provision_origin_cert` for a single domain |
| `plugins/server_manager/tasks/RunNodeUptimeChecks.php` | Uptime monitor with 3xx-as-down logic |
| `adm/admin_settings.php` | CF Flexible warning banner |
| `docs/deploy_and_upgrade.md` | "Apache Vhost" section: shape, cert decision tree, DNS provider extensibility |

## Out of scope (deliberately)

- **Self-signed cert fallback.** Earlier drafts proposed a 10-year self-signed cert when LE couldn't issue. Replaced by the `<IfFile>` guard: missing cert just means no `:443` vhost. Cleaner; no false sense of "origin SSL" when the cert isn't browser-trusted.
- **Non-CF reverse proxies that proxy HTTPS-to-HTTP without a CF-Visitor-equivalent header.** No standard equivalent exists across CDNs; supporting each one is a separate piece of work. The vhost handles CF and direct-to-origin cleanly; sites behind other CDNs would still need that CDN's redirect rule disabled at the edge.
- **DNS provider plugins beyond the four listed.** The plugin map is extensible.
- **Automatic CF API token bootstrapping.** Operator drops the token into `/etc/letsencrypt/cloudflare.ini` once per host.
- **Automated migration script for legacy vhosts.** Deliberately not shipped in core; the conversion is per-site manual work via `write_universal_vhost`. Lets pre-existing per-site quirks (ServerAlias chains, log path overrides, ServerName bugs) be reviewed and corrected during the conversion rather than blindly preserved.
