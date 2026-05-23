# Domain Rename: joinerytest.site → dev.getjoinery.com

## Summary

Migrate the local dev/control-plane site from `joinerytest.site` to `dev.getjoinery.com`. DNS is already set. This is a bare-metal server (no Docker proxy layer), so SSL is issued directly via certbot. There are several additional touchpoints vs. the joinerydemo rename: stg_settings email rows, upgrade-server URLs in scripts, PHP fallback strings, docs, active specs, and the agent files (CLAUDE.md/GEMINI.md).

**Email domain is out of scope.** The `mg.joinerytest.site` Mailgun subdomain and `inbox.joinerytest.site` inbound email subdomain require separate DNS/Mailgun work and are not changed here.

## Inventory of Changes

| # | Layer | File / Resource | Line(s) | Change |
|---|---|---|---|---|
| 1 | Local SSL cert | Let's Encrypt (certbot on this server) | — | Issue cert for `dev.getjoinery.com` |
| 2 | Local Apache vhost | `/etc/apache2/sites-available/joinerytest.conf` | `ServerName` lines | `joinerytest.site` → `dev.getjoinery.com` (certbot adds HTTPS vhost automatically) |
| 3 | Site config | `/var/www/html/joinerytest/config/Globalvars_site.php` | `webDir` | `joinerytest.site` → `dev.getjoinery.com` |
| 4 | Database settings | `stg_settings` in joinerytest DB | — | `webDir`, `webDir_readonly`, `smtp_helo` → `dev.getjoinery.com` |
| 5 | Upgrade script | `maintenance_scripts/install_tools/install.sh` | 574 | `UPGRADE_SERVER` default → `https://dev.getjoinery.com` |
| 6 | Server Manager | `plugins/server_manager/includes/JobCommandBuilder.php` | 1285 | Fallback domain → `dev.getjoinery.com` |
| 7 | Upgrade endpoint | `utils/latest_release.php` | 9–10 | Docblock comment URLs → `dev.getjoinery.com` |
| 7a | Test suite | `tests/integration/routing_test.php` | 107 | Fallback domain → `dev.getjoinery.com` |
| 8 | Docs | `docs/deploy_and_upgrade.md` | 431 | Example URL → `https://dev.getjoinery.com` |
| 9 | Docs | `docs/quickstart.md` | 88 | Install command URL → `https://dev.getjoinery.com` |
| 10 | Docs | `docs/installation.md` | 32, 42 | Install command URLs → `https://dev.getjoinery.com` |
| 11 | Docs | `maintenance_scripts/install_tools/INSTALL_README.md` | multiple | Install command URLs → `https://dev.getjoinery.com` |
| 12 | Active spec | `specs/geolocation_postgis_spec.md` | 328 | User-Agent URL → `https://dev.getjoinery.com` |
| 13 | Active spec | `specs/system_features.md` | 435 | Inbound email example subdomain (note only) |
| 14 | Agent files | `agf_agent_files` table via `/admin/admin_agent_files` | — | Update `joinerytest.site` URLs to `dev.getjoinery.com` in CLAUDE.md + GEMINI.md records |

## Steps

### 1. Issue SSL Certificate

```bash
certbot --apache -d dev.getjoinery.com --non-interactive --agree-tos --email jeremy.tunnell@gmail.com
```

Certbot will issue the cert, create a new `joinerytest-le-ssl.conf` (or similar) in `sites-available`, and add a redirect from HTTP to HTTPS in the existing HTTP vhost. Verify:

```bash
certbot certificates | grep dev.getjoinery.com
```

### 2. Update Local Apache Vhost

Certbot will have modified `/etc/apache2/sites-available/joinerytest.conf`. Verify the `ServerName` line reads `dev.getjoinery.com`. If certbot did not change it, update manually:

```bash
sed -i 's/ServerName joinerytest.site/ServerName dev.getjoinery.com/' /etc/apache2/sites-available/joinerytest.conf
apache2ctl configtest && systemctl reload apache2
```

Also add a redirect for the old domain (add a new vhost block to `joinerytest.conf`):

```apache
<VirtualHost 69.164.209.253:80>
    ServerName joinerytest.site
    ServerAlias www.joinerytest.site
    RewriteEngine On
    RewriteRule ^(.*)$ https://dev.getjoinery.com$1 [R=301,L]
</VirtualHost>
```

### 3. Update Globalvars_site.php

File: `/var/www/html/joinerytest/config/Globalvars_site.php`

Change:
```php
$this->settings['webDir'] = 'joinerytest.site';
```
to:
```php
$this->settings['webDir'] = 'dev.getjoinery.com';
```

### 4. Update stg_settings (joinerytest DB)

Three rows reference the web domain directly. The email rows (`mailgun_domain`, `smtp_hostname`, `smtp_username`, `webmaster_email`, `defaultemail`) are out of scope — leave them pointing at `joinerytest.site` / `mg.joinerytest.site` until email is migrated separately.

```sql
UPDATE stg_settings SET stg_value = 'dev.getjoinery.com' WHERE stg_name IN ('webDir', 'webDir_readonly', 'smtp_helo');
```

Verify:
```sql
SELECT stg_name, stg_value FROM stg_settings WHERE stg_value LIKE '%joinerytest.site%';
```
Expected remaining rows: the email-related ones (`webmaster_email`, `defaultemail`, `mailgun_domain`, `smtp_hostname`, `smtp_username`).

### 5. Update install.sh

File: `maintenance_scripts/install_tools/install.sh`, line 574

Change:
```bash
UPGRADE_SERVER="${UPGRADE_SERVER:-https://joinerytest.site}"
```
to:
```bash
UPGRADE_SERVER="${UPGRADE_SERVER:-https://dev.getjoinery.com}"
```

### 6. Update JobCommandBuilder.php

File: `plugins/server_manager/includes/JobCommandBuilder.php`, line 1285

Change fallback:
```php
$webdir = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'] ?? 'joinerytest.site';
```
to:
```php
$webdir = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'] ?? 'dev.getjoinery.com';
```

### 7. Update latest_release.php

File: `utils/latest_release.php`, line 107

Change:
```php
$host = $settings->get_setting('site_domain') ?? 'joinerytest.site';
```
to:
```php
$host = $settings->get_setting('site_domain') ?? 'dev.getjoinery.com';
```

### 8. Update Docs

**`docs/deploy_and_upgrade.md`** line 431 — update the `upgrade_source` example URL.

**`docs/quickstart.md`** line 88 — update the `curl` install command URL.

**`docs/installation.md`** lines 32 and 42 — update both `curl` install command URLs.

**`maintenance_scripts/install_tools/INSTALL_README.md`** — update all `curl` install command URLs and the `upgrade_source` description.

### 9. Update Active Specs

**`specs/geolocation_postgis_spec.md`** line 328 — update the User-Agent URL example.

**`specs/system_features.md`** line 435 — update the `inbox.joinerytest.site` reference to `inbox.dev.getjoinery.com` (note: this is a doc reference only; the actual inbound email routing is a separate DNS/infra change).

### 10. Update Agent Files (CLAUDE.md / GEMINI.md)

**Do not edit these files on disk.** Edit the "Internal CLAUDE.md" and "Internal GEMINI.md" records at `/admin/admin_agent_files`. Search for `joinerytest.site` and update:

- Test site URL: `https://joinerytest.site` → `https://dev.getjoinery.com`
- Browser navigation examples: `joinerytest.site/path` → `dev.getjoinery.com/path`
- Inbound email example: `*@inbox.joinerytest.site` → `*@inbox.dev.getjoinery.com` (note: actual routing unchanged until email migration)

After saving, run "Regenerate" to write the updated CLAUDE.md and GEMINI.md to disk.

### 11. Verify

1. `https://dev.getjoinery.com` loads with a valid SSL cert
2. `https://joinerytest.site` redirects to `https://dev.getjoinery.com`
3. Admin panel loads correctly (session cookies are domain-scoped — may need to log in again)
4. Server Manager node list shows updated URL for joinerytest node (if it has a self-referential record)
5. Check upgrade workflow: `curl -sL https://dev.getjoinery.com/utils/latest_release` returns a tarball

## Out of Scope

- **Email domain** — `mg.joinerytest.site` Mailgun domain, `inbox.joinerytest.site` inbound routing, and all email addresses require separate Mailgun + DNS work
- **`test.joinerytest.site`** — the test vhost is unchanged
- **Implemented spec files** — historical docs reference the old domain; they are frozen

## Notes

- The local server VirtualHost is bound to `69.164.209.253` (not `*`). Certbot may add the SSL vhost bound to `*:443` — that's fine; both patterns work.
- The `webDir` appears in both `Globalvars_site.php` (authoritative) and `stg_settings` (may override or shadow). Both must be updated.
- After changing the `webDir` setting, any cached session data or absolute URLs stored in the database (e.g. in page content) will still reference the old domain — search for those if broken links appear post-migration.
