# www Redirect Fix for Host Apache Proxy Configs

## Problem

All production sites share a single host Apache server (`23.239.11.53`). Each site has a `{sitename}-proxy.conf` with only a bare-domain `ServerName` (e.g. `ServerName jeremytunnell.com`). None include a `www.` handler.

Every production domain has a live `www.` DNS record (Cloudflare-proxied). When a browser requests `www.jeremytunnell.com`, Apache finds no matching vhost and falls back to the default — which is whichever site's config sorts first alphabetically (currently `empoweredhealthtn-proxy.conf`). The browser stays at `www.jeremytunnell.com` in the address bar but receives the wrong site's content.

**Why mobile/Safari and not desktop:** Safari autocompletes to `www.` from history/suggestions. Browsers like Brave default to the bare domain.

## Fix

### 1. `install.sh` — all three proxy config generation blocks

Each of the three `cat > ... << EOF` blocks that generates `{sitename}-proxy.conf` must include a second `<VirtualHost>` block that redirects `www.{domain}` → `https://{domain}`, preserving path and query string. Always `https://` — the bare `http://domain.com` vhost remains untouched and stays accessible for troubleshooting, but www should always land on the canonical HTTPS URL.

In the HTTP-only/temp case (DNS not yet configured) the site isn't reachable from the internet yet anyway, so using `https://` is harmless and avoids a protocol branch.

The redirect must preserve the full path and query string. Use `RewriteEngine` rather than bare `Redirect` so `%{REQUEST_URI}` is available:

```apache
<VirtualHost *:80>
    ServerName www.${domain}
    RewriteEngine On
    RewriteRule ^(.*)$ https://${domain}$1 [R=301,L]
</VirtualHost>
```

**Code duplication note:** This same block appears in three separate heredocs in `install.sh`. Extract the proxy config generation into a helper function so the www block is written once. This is a prerequisite for the install.sh change, not optional cleanup.

### 2. Existing production configs — retrofit

After updating `install.sh`, apply the same www redirect block to each of the 8 existing `{sitename}-proxy.conf` files on `23.239.11.53`. All 8 are Cloudflare-proxied, so all redirect to `https://`.

Sites and their domains:
- `empoweredhealthtn-proxy.conf` → `www.empoweredhealthtn.com` → `https://empoweredhealthtn.com`
- `galactictribune-proxy.conf` → `www.galactictribune.net` → `https://galactictribune.net`
- `getjoinery-proxy.conf` → `www.getjoinery.com` → `https://getjoinery.com`
- `jeremytunnell-proxy.conf` → `www.jeremytunnell.com` → `https://jeremytunnell.com`
- `joinerydemo-proxy.conf` → `www.joinerydemo.site` → `https://joinerydemo.site`
- `mapsofwisdom-proxy.conf` → `www.mapsofwisdom.org` → `https://mapsofwisdom.org`
- `phillyzouk-proxy.conf` → `www.phillyzouk.org` → `https://phillyzouk.org`
- `scrolldaddy-proxy.conf` → `www.scrolldaddy.app` → `https://scrolldaddy.app`

After editing all 8 files, run `systemctl reload apache2` on the host once.

### 3. `default_virtualhost.conf` — add www redirect vhost

In bare-metal installs, this template IS the internet-facing Apache config (no host proxy in front). The same www problem applies. Add a third `<VirtualHost>` block redirecting `www.{{DOMAIN_NAME}}` → `https://{{DOMAIN_NAME}}`, preserving path:

```apache
<VirtualHost {{SERVER_IP}}:80>
    ServerName www.{{DOMAIN_NAME}}
    RewriteEngine On
    RewriteRule ^(.*)$ https://{{DOMAIN_NAME}}$1 [R=301,L]
</VirtualHost>
```

## Verification

After applying, confirm each site redirects correctly:

```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://www.jeremytunnell.com/some/path
# Expected: 301 https://jeremytunnell.com/some/path
```

Repeat for each domain.
