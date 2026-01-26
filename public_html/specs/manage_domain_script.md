# manage_domain.sh Specification

## Overview

A standalone script to manage domain assignments for Joinery sites. The script handles adding, changing, or removing domain names from existing sites, with support for both Docker and bare-metal deployments.

**Design Principles:**
- Self-contained script with no external dependencies on shared libraries
- No modifications to existing scripts (install.sh, _site_init.sh, etc.)
- Simple, focused functionality

## Location

`/var/www/html/{repo}/maintenance_scripts/sysadmin_tools/manage_domain.sh`

## Commands

### set
Assign or change domain for an existing site.

```bash
./manage_domain.sh set SITENAME DOMAIN [OPTIONS]
```

**Options:**
- `--no-ssl` - Skip SSL certificate setup (useful for testing or Cloudflare-proxied sites)

**Actions:**
1. Detect if site is Docker or bare-metal
2. Update Apache VirtualHost configuration with new domain
3. For Docker sites: Update host Apache reverse proxy
4. Attempt SSL certificate via certbot (unless `--no-ssl`)
5. Reload Apache
6. Save backup of previous configuration for rollback

### clear
Remove domain and revert to IP-only access.

```bash
./manage_domain.sh clear SITENAME
```

**Actions:**
1. Remove domain from VirtualHost, replace with server IP
2. Remove SSL configuration if present
3. Reload Apache
4. Save backup of previous configuration

### status
Show current domain configuration for a site.

```bash
./manage_domain.sh status SITENAME
```

**Output:**
- Current domain (or IP if no domain)
- SSL status (enabled/disabled, expiry date if applicable)
- Site type (Docker/bare-metal)
- Container port (for Docker sites)

### rollback
Restore previous domain configuration from backup.

```bash
./manage_domain.sh rollback SITENAME
```

### remove-ssl
Remove SSL certificates and configuration, keeping the domain.

```bash
./manage_domain.sh remove-ssl SITENAME
```

## Implementation Details

### Site Detection

```bash
# Check if site runs in Docker
if docker ps --format '{{.Names}}' | grep -qx "$SITENAME"; then
    SITE_TYPE="docker"
    CONTAINER_PORT=$(docker port "$SITENAME" 80 2>/dev/null | head -1 | sed 's/.*://')
else
    SITE_TYPE="baremetal"
fi
```

### Configuration Backup

Before any changes, save current state:
```bash
BACKUP_DIR="/var/www/html/${SITENAME}/backups/domain"
mkdir -p "$BACKUP_DIR"
cp /etc/apache2/sites-available/${SITENAME}.conf "$BACKUP_DIR/${SITENAME}.conf.$(date +%Y%m%d_%H%M%S)"
```

### Docker Sites - Host Apache Proxy

For Docker sites, the script manages an Apache reverse proxy on the host:

```apache
<VirtualHost *:80>
    ServerName example.com
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
</VirtualHost>
```

The container's internal Apache configuration is NOT modified - only the host proxy.

### SSL Handling

**Certificate Installation:**
```bash
certbot --apache -d "$DOMAIN" --non-interactive --agree-tos --email admin@"$DOMAIN"
```

**Skip Conditions:**
- `--no-ssl` flag provided
- Domain is `localhost`
- Domain is an IP address
- DNS doesn't point to this server (with Cloudflare detection)

**Cloudflare Detection:**
When DNS points to Cloudflare IPs, warn user and skip certbot (Cloudflare provides SSL).

### IP Address Detection

```bash
# Get server's public IPv4 address
get_public_ip() {
    curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || \
    curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || \
    hostname -I | awk '{print $1}'
}
```

## Error Handling

- Verify site exists before any operation
- Verify Apache configuration syntax before reload (`apachectl configtest`)
- Provide clear error messages with suggested fixes
- Create backup before any destructive operation
- Support rollback on failure

## Exit Codes

- 0: Success
- 1: General error
- 2: Site not found
- 3: Invalid arguments
- 4: Apache configuration error
- 5: SSL certificate error

## Examples

```bash
# Add domain to Docker site
./manage_domain.sh set mysite example.com

# Add domain without SSL (for Cloudflare-proxied sites)
./manage_domain.sh set mysite example.com --no-ssl

# Check current configuration
./manage_domain.sh status mysite

# Revert to IP-only access
./manage_domain.sh clear mysite

# Restore previous configuration
./manage_domain.sh rollback mysite
```

## Dependencies

- Apache2 with mod_proxy (for Docker sites)
- certbot with apache plugin (for SSL)
- Docker CLI (for Docker site detection)
- curl (for IP detection)
- Standard bash utilities

## Documentation Updates

### INSTALL_README.md

Add a new section "Domain Management" after "Site Management":

```markdown
## Domain Management

Use `manage_domain.sh` to add, change, or remove domains from existing sites.

### Check Current Configuration

```bash
cd maintenance_scripts/sysadmin_tools
sudo ./manage_domain.sh status mysite
```

### Assign a Domain

```bash
# With automatic SSL certificate
sudo ./manage_domain.sh set mysite example.com

# Without SSL (for Cloudflare-proxied sites or testing)
sudo ./manage_domain.sh set mysite example.com --no-ssl
```

### Remove Domain (Revert to IP-Only)

```bash
sudo ./manage_domain.sh clear mysite
```

### Rollback to Previous Configuration

```bash
sudo ./manage_domain.sh rollback mysite
```

### Remove SSL Only (Keep Domain)

```bash
sudo ./manage_domain.sh remove-ssl mysite
```
```

Also add to the "Script Reference" section:

```markdown
### manage_domain.sh

Manages domain assignments for existing sites.

| Command | Description |
|---------|-------------|
| `set SITENAME DOMAIN [--no-ssl]` | Assign or change domain |
| `clear SITENAME` | Remove domain, revert to IP-only |
| `status SITENAME` | Show current configuration |
| `rollback SITENAME` | Restore previous configuration |
| `remove-ssl SITENAME` | Remove SSL, keep domain |
```

## Files Changed Summary

**New files (1):**
- `maintenance_scripts/sysadmin_tools/manage_domain.sh`

**Modified files (1):**
- `maintenance_scripts/install_tools/INSTALL_README.md` - Add domain management documentation

**Existing scripts modified (0):**
- None (install.sh, _site_init.sh, Dockerfile.template remain untouched)

## Notes

- This script does NOT modify install.sh or any other existing scripts
- All helper functions are defined within the script itself
- Backups are stored per-site for easy management
