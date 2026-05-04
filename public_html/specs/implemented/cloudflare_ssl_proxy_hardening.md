# Cloudflare SSL Proxy Hardening

## Background

When `orgs.getjoinery.com` was provisioned by copying `getjoinery.com`, it ended up with
a "too many redirects" error. Root cause investigation identified four bugs, one of which is
systemic (affects any future site provisioned via Server Manager's SSL button).

---

## What Went Wrong (orgs.getjoinery.com)

### Bug 1 — HTTP→HTTPS redirect loop (root cause of the error)

The outer Apache HTTP VHost for `orgs.getjoinery.com` contains:

```apache
RewriteEngine on
RewriteCond %{SERVER_NAME} =orgs.getjoinery.com
RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
```

This is certbot's standard `--redirect` behavior, correct for direct-SSL sites. But
`orgs.getjoinery.com` is behind Cloudflare in **Flexible SSL** mode, which means Cloudflare
always connects to the origin over HTTP — even when the visitor used HTTPS. The sequence:

1. Visitor → Cloudflare (HTTPS) → Origin port 80 (HTTP)
2. Apache redirects to `https://orgs.getjoinery.com/`
3. Cloudflare follows the redirect but reconnects to origin over HTTP (Flexible mode)
4. Apache redirects again → infinite loop → browser: "Too many redirects"

The working `getjoinery.com` proxy was set up without this redirect because install.sh
already detects Cloudflare and skips certbot for proxied domains. The orgs site was
provisioned differently (copied, then SSL added via Server Manager), bypassing that check.

### Bug 2 — SSL VHost has `X-Forwarded-Proto "http"` (wrong)

`getjoinery_orgs-proxy-le-ssl.conf` (the port-443 VHost) contains:

```apache
RequestHeader set X-Forwarded-Proto "http"
```

This causes `LibraryFunctions::isSecure()` to return false for HTTPS requests, meaning the
app thinks every connection is plain HTTP. This is always wrong in the SSL VHost.

**Why this happens systematically:** certbot's Apache plugin copies the HTTP VHost config
(which legitimately has `X-Forwarded-Proto "http"`) into the new SSL VHost it generates.
The value is never corrected post-generation.

### Bug 3 — `webDir` copied from source site

The `stg_settings` record `webDir` still contained `https://getjoinery.com` (the source
site's value). The copy process does not update it.

Additional problem: the value includes the `https://` protocol prefix. `PublicPageBase`
prepends `https://` when building canonical URLs, producing `href="https://https://..."`.
The `webDir` setting is documented as domain-only (e.g. `orgs.getjoinery.com`), but no
validation or migration enforces this.

`webDir` has two sources of truth: `Globalvars_site.php` (file, takes precedence) and
`stg_settings` (database, used when the file value is absent or empty). The copy process
left the wrong value in `Globalvars_site.php` with a `https://` prefix. The DB also had
the wrong domain from the copy. Both needed fixing.

### Bug 4 — Inner container Apache `ServerName` not updated

Inside the `getjoinery_orgs` container, `getjoinery_orgs.conf` still has:

```apache
<VirtualHost *:80>
    ServerName getjoinery.com
```

Requests with `Host: orgs.getjoinery.com` don't match, so Apache falls through to the
default VirtualHost. Content is served correctly only because it happens to be the only
VirtualHost, but `$_SERVER['SERVER_NAME']` is wrong and any ServerName-conditional logic
would misbehave.

---

## Immediate Fix (orgs.getjoinery.com)

### 1. Outer proxy — HTTP VHost (`getjoinery_orgs-proxy.conf`)

Remove the 3-line RewriteRule block and change `X-Forwarded-Proto` to `"https"`:

```apache
<VirtualHost *:80>
    ServerName orgs.getjoinery.com

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8088/
    ProxyPassReverse / http://127.0.0.1:8088/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "https"

    ErrorLog /var/www/html/getjoinery_orgs/logs/proxy_error.log
    CustomLog /var/www/html/getjoinery_orgs/logs/proxy_access.log combined
</VirtualHost>
```

### 2. Outer proxy — SSL VHost (`getjoinery_orgs-proxy-le-ssl.conf`)

Change `X-Forwarded-Proto "http"` to `"https"`:

```apache
RequestHeader set X-Forwarded-Proto "https"
```

### 3. Database — fix `webDir`

```sql
UPDATE stg_settings SET stg_value = 'orgs.getjoinery.com'
WHERE stg_name = 'webDir';
```

### 4. Inner container Apache — update `ServerName`

In `/var/www/html/getjoinery_orgs/public_html` (served from inside the container),
`getjoinery_orgs.conf` `ServerName` should be updated to `orgs.getjoinery.com`.
Reload Apache inside the container after the change.

---

## Systemic Fixes (prevent recurrence)

### Fix A — Server Manager `provision_ssl`: Cloudflare awareness

**File:** `plugins/server_manager/includes/JobCommandBuilder.php`  
**Method:** `build_provision_ssl()`

Currently runs certbot unconditionally. Add Cloudflare detection before certbot:

```
1. Resolve the domain's A/AAAA records
2. Check if any resolve to a known Cloudflare IP range
   (fetch https://www.cloudflare.com/ips-v4 + ips-v6, same logic as install.sh)
3. If Cloudflare proxied:
   - Skip certbot entirely
   - Update the existing HTTP VHost to set X-Forwarded-Proto "https" (if not already set)
   - Reload Apache
   - Return success with a note that Cloudflare handles SSL at the edge
4. If NOT Cloudflare:
   - Run certbot as today
   - After certbot succeeds, patch the generated SSL VHost:
     sed -i 's/X-Forwarded-Proto "http"/X-Forwarded-Proto "https"/' on the *-le-ssl.conf
   - Reload Apache
```

The Cloudflare IP range fetch should be cached for the duration of the job (not re-fetched
per step).

### Fix B — After certbot: always patch `X-Forwarded-Proto` in SSL VHost

Even for non-Cloudflare direct-SSL sites, the SSL VHost should have `X-Forwarded-Proto
"https"`. Certbot copies the HTTP VHost config verbatim, so this patch is always needed.

Add a step to `build_provision_ssl()` after the certbot step:

```bash
# Fix X-Forwarded-Proto in the certbot-generated SSL vhost
SSL_CONF="/etc/apache2/sites-enabled/${sitename}-proxy-le-ssl.conf"
if [ -f "$SSL_CONF" ]; then
  sed -i 's/X-Forwarded-Proto "http"/X-Forwarded-Proto "https"/' "$SSL_CONF"
  systemctl reload apache2
fi
```

### Fix C — Copy-site process: post-copy database and config corrections

When a site is provisioned from a backup/copy of another site, the following must be
updated as a post-copy step (either in the install job or as a documented checklist item
in the Server Manager UI):

| What | Where | Update to |
|------|-------|-----------|
| `webDir` | `stg_settings` in the new site's DB | New domain (no protocol prefix) |
| Inner container `ServerName` | Apache VHost inside the container | New domain |

These should be automated in `build_install_node()` when `mode = 'from_backup'`:
- After DB restore: `UPDATE stg_settings SET stg_value = '{new_domain}' WHERE stg_name = 'webDir'`
- After deploy: sed `webDir` in `config/Globalvars_site.php` inside the container to the new domain (stripping any protocol prefix), sed `ServerName` in the container's Apache conf, and reload Apache

### Fix D — Validate `webDir` format

The `webDir` setting should be stored as a bare domain (`example.com`), not as a URL
(`https://example.com`). Two layers of protection:

**1. Error log detection in `Globalvars::__construct()`**

Add after the `require_once` that loads `Globalvars_site.php`:

```php
if (!empty($this->settings['webDir']) &&
    (str_starts_with($this->settings['webDir'], 'http://') || str_starts_with($this->settings['webDir'], 'https://'))) {
    error_log("CONFIG: webDir contains protocol prefix ('" . $this->settings['webDir'] . "') — should be domain only, e.g. 'example.com'");
}
```

Runs once per request at singleton construction time. No DB hit, no regex, negligible cost.

**2. Strip protocol on save in admin settings**

In the admin settings save path, strip any leading `http://` or `https://` from `webDir`
before writing to `stg_settings` (and if the value is also in `Globalvars_site.php`, note
that the file takes precedence and must be corrected manually or via deploy).

---

## Files to Change

| File | Change |
|------|--------|
| `/etc/apache2/sites-enabled/getjoinery_orgs-proxy.conf` (on docker-prod) | Remove RewriteRule; fix X-Forwarded-Proto |
| `/etc/apache2/sites-enabled/getjoinery_orgs-proxy-le-ssl.conf` (on docker-prod) | Fix X-Forwarded-Proto |
| `stg_settings` in `getjoinery_orgs` DB | Update `webDir` |
| Inner container Apache conf | Update `ServerName` |
| `plugins/server_manager/includes/JobCommandBuilder.php` | Cloudflare detection + post-certbot patch in `build_provision_ssl()` |
| `includes/Globalvars.php` | Add `webDir` protocol-prefix error log in `__construct()` |
| `adm/admin_settings.php` (or settings save logic) | Strip protocol from `webDir` on save |
