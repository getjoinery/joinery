# Domain Rename: joinerydemo.site → demo.getjoinery.com

## Summary

Migrate the Joinery demo site from `joinerydemo.site` to `demo.getjoinery.com`. DNS is already set. This touches the host-level Apache proxy, the container-level Apache vhost, the site config file, and the Server Manager database record.

## Inventory of Changes

| Layer | File / Resource | Change |
|---|---|---|
| Host Apache proxy | `/etc/apache2/sites-available/joinerydemo-proxy.conf` on `23.239.11.53` | `ServerName` → `demo.getjoinery.com` |
| Host SSL cert | Let's Encrypt on `23.239.11.53` | Issue new cert for `demo.getjoinery.com` |
| Container Apache vhost | `/etc/apache2/sites-enabled/joinerydemo.conf` (inside `joinerydemo` container) | `ServerName joinerydemo.site` → `ServerName demo.getjoinery.com` |
| Site config | `/var/www/html/joinerydemo/config/Globalvars_site.php` (inside container) | `webDir` → `demo.getjoinery.com` |
| Server Manager DB | `mgn_managed_nodes.mgn_site_url` in `joinerytest` DB | `https://joinerydemo.site` → `https://demo.getjoinery.com` |
| joinerydemo DB | `stg_settings` table (in joinerydemo DB) | Verify no hardcoded domain references |

## Steps

### 1. Issue SSL Certificate (Host — requires SSH to 23.239.11.53)

```bash
ssh -i ~/.ssh/id_ed25519_claude root@23.239.11.53
certbot certonly --apache -d demo.getjoinery.com
```

Certbot will place the cert at `/etc/letsencrypt/live/demo.getjoinery.com/`.

### 2. Update Host-Level Apache Proxy Config (Host)

Read the current file first:
```bash
cat /etc/apache2/sites-available/joinerydemo-proxy.conf
```

The file will have a `:80` vhost and possibly a `:443` SSL vhost. Update:
- All `ServerName` and `ServerAlias` lines: replace `joinerydemo.site` with `demo.getjoinery.com`
- SSL cert paths: replace with `/etc/letsencrypt/live/demo.getjoinery.com/fullchain.pem` and `privkey.pem`

Add a redirect vhost so the old domain still works during any transition:
```apache
<VirtualHost *:80>
    ServerName joinerydemo.site
    Redirect permanent / https://demo.getjoinery.com/
</VirtualHost>
```

Then reload Apache on the host:
```bash
apache2ctl configtest && systemctl reload apache2
```

### 3. Update Container Apache Vhost (Container — via node_exec)

Edit `/etc/apache2/sites-enabled/joinerydemo.conf` inside the container. Change:
```apache
ServerName joinerydemo.site
```
to:
```apache
ServerName demo.getjoinery.com
```

Leave `ServerName test.joinerydemo.site` unchanged (or update if the test subdomain is being migrated too — out of scope for now).

Reload Apache inside the container:
```bash
php /var/www/html/joinerytest/public_html/plugins/server_manager/node_exec.php joinerydemo "apache2ctl graceful"
```

### 4. Update Globalvars_site.php (Container — via node_exec)

File: `/var/www/html/joinerydemo/config/Globalvars_site.php`

Change:
```php
$this->settings['webDir'] = 'joinerydemo.site';
```
to:
```php
$this->settings['webDir'] = 'demo.getjoinery.com';
```

No Apache reload needed for this change — the config is read on every request.

### 5. Check stg_settings for Domain References (Container — via node_exec)

```bash
# Run via node_exec using --stdin to avoid credential in args:
echo "SELECT stg_name, stg_value FROM stg_settings WHERE stg_value LIKE '%joinerydemo.site%';" \
  | php /var/www/html/joinerytest/public_html/plugins/server_manager/node_exec.php joinerydemo --stdin "PGPASSWORD=<pw> psql -U postgres joinerydemo -t"
```

Update any rows that contain the old domain.

### 6. Update Server Manager Database Record (joinerytest DB)

```sql
UPDATE mgn_managed_nodes
SET mgn_site_url = 'https://demo.getjoinery.com'
WHERE mgn_slug = 'joinerydemo';
```

### 7. Verify

1. `https://demo.getjoinery.com` loads the site with a valid SSL cert
2. `https://joinerydemo.site` redirects to `https://demo.getjoinery.com`
3. Server Manager node list shows the correct URL
4. Internal links and asset URLs use the new domain (check the webDir setting is applied)

## Out of Scope

- `test.joinerydemo.site` — the test vhost is unchanged
- Email domain — `joinerydemo.site` is not an email domain; no MX/SPF changes needed
- Implemented spec files — historical docs reference the old domain; they are frozen and should not be edited
