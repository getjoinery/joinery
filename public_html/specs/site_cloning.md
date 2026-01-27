# Site Cloning Specification

## Overview

Enable cloning an existing Joinery site to a new server with a single command run on the target machine. The target pulls the database, uploads, and themes/plugins directly from the source site.

**Design Principles:**
- All commands run on target machine only
- Source site serves data via secure authenticated endpoint
- Works with both Docker and bare-metal targets
- Leverages existing install.sh infrastructure

## Usage

```bash
# Clone to Docker container
./install.sh site newsite SecurePass123 newdomain.com 8080 \
    --clone-from=https://sourcesite.com \
    --clone-key=SecretCloneKey

# Clone to bare-metal
./install.sh site newsite SecurePass123 newdomain.com \
    --clone-from=https://sourcesite.com \
    --clone-key=SecretCloneKey
```

## Source Site: Clone Export Endpoint

### Location

`/var/www/html/{site}/public_html/utils/clone_export.php`

### Security

- **HTTPS required** - rejects non-HTTPS requests
- **Disabled by default** - requires explicit opt-in
- **Authentication** via Authorization header (Bearer token) matched against `clone_export_key` in `stg_settings`
- **Rate limiting** - one request per minute per IP
- **Logging** - all clone requests logged

### Security Checks (at top of endpoint)

```php
// Require HTTPS
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'HTTPS required']));
}

// Check if clone export is enabled
$clone_key_setting = $settings->get_setting('clone_export_key');
if (empty($clone_key_setting)) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Clone export not enabled on this site']));
}

// Validate key from Authorization header (timing-safe comparison)
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$provided_key = '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
    $provided_key = $matches[1];
}
if (!hash_equals($clone_key_setting, $provided_key)) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid or missing clone key']));
}
```

### Enabling Clone Export on Source

```sql
-- Enable clone export with a secure key
INSERT INTO stg_settings (stg_name, stg_value)
VALUES ('clone_export_key', 'YourSecureRandomKey123');

-- Or update existing
UPDATE stg_settings SET stg_value = 'YourSecureRandomKey123'
WHERE stg_name = 'clone_export_key';

-- Disable clone export
DELETE FROM stg_settings WHERE stg_name = 'clone_export_key';
```

### Endpoint Actions

#### 1. Manifest
Returns metadata about what will be cloned.

**Request:**
```
GET /utils/clone_export.php?action=manifest
Authorization: Bearer <clone_key>
```

**Response:**
```json
{
    "status": "ok",
    "site_name": "sourcesite",
    "database_size_mb": 45,
    "uploads_size_mb": 128,
    "uploads_count": 1523,
    "themes": ["falcon", "canvas", "empoweredhealth"],
    "plugins": ["bookings", "controld"],
    "joinery_version": "2.1.0",
    "php_version": "8.3",
    "created_at": "2026-01-27T15:30:00Z"
}
```

#### 2. Database Export
Streams an encrypted, gzipped PostgreSQL dump. Uses same encryption format as `backup_database.sh` (AES-256-CBC with PBKDF2).

**Request:**
```
GET /utils/clone_export.php?action=database
Authorization: Bearer <clone_key>
```

**Response:**
- Content-Type: `application/octet-stream`
- Content-Disposition: `attachment; filename="database.sql.gz.enc"`
- Body: encrypted gzipped SQL dump stream

**Implementation:**
```php
// Stream encrypted pg_dump output directly (same format as backup_database.sh)
// Clone key serves as both authentication and encryption key
$cmd = sprintf(
    "PGPASSWORD=%s pg_dump -U postgres %s | gzip | openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:%s",
    escapeshellarg($db_password),
    escapeshellarg($dbname),
    escapeshellarg($clone_key)
);
passthru($cmd);
```

**Decryption (on target):**
```bash
openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:$CLONE_KEY | gunzip | psql
```

#### 3. Uploads Export
Streams a tar.gz archive of the uploads directory. Transport security provided by HTTPS.

**Request:**
```
GET /utils/clone_export.php?action=uploads
Authorization: Bearer <clone_key>
```

**Response:**
- Content-Type: `application/octet-stream`
- Content-Disposition: `attachment; filename="uploads.tar.gz"`
- Body: gzipped tar stream of uploads/

**Implementation:**
```php
// Stream tar output directly
$site_root = PathHelper::get_site_root();
$cmd = sprintf(
    "tar -czf - -C %s uploads",
    escapeshellarg($site_root)
);
passthru($cmd);
```

### Error Responses

```json
{"status": "error", "message": "Clone export not enabled on this site"}
{"status": "error", "message": "Invalid or missing clone key"}
{"status": "error", "message": "Rate limit exceeded. Try again in 60 seconds."}
{"status": "error", "message": "Invalid action"}
```

## Target Site: Install Process

### New install.sh Options

```bash
--clone-from=URL     Source site URL to clone from
--clone-key=KEY      Authentication key for clone export
```

### Modified Flow

When `--clone-from` is specified:

| Step | Normal Install | Clone Install |
|------|----------------|---------------|
| 1. Validate args | Check sitename, password, domain | + Validate clone URL is reachable |
| 2. Fetch manifest | - | **Fetch and display clone source info** |
| 3. Deploy code | From archive | **Use clone source as upgrade server** |
| 4. Download themes | From upgrade server | **From clone source** |
| 5. Create directories | Standard | Standard |
| 6. Create config | New Globalvars_site.php | New Globalvars_site.php |
| 7. Create database | createdb | createdb |
| 8. Load database | joinery-install.sql.gz | **Stream from clone source** |
| 9. Load uploads | - | **Stream from clone source** |
| 10. Update settings | - | **Update domain in stg_settings** |
| 11. Validate | Check default admin | **Skip (cloned data differs)** |
| 12. Composer install | Standard | Standard |
| 13. Permissions | Standard | Standard |

### Changes to _site_init.sh

Add new options:

```bash
# New options
--clone-from=URL      Clone database and uploads from URL
--clone-key=KEY       Authentication key for clone source
--skip-db-validation  Skip default admin/settings validation
```

**Argument parsing (add to existing while loop):**

```bash
CLONE_FROM=""
CLONE_KEY=""
SKIP_DB_VALIDATION=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --clone-from=*)
            CLONE_FROM="${1#*=}"
            ;;
        --clone-key=*)
            CLONE_KEY="${1#*=}"
            ;;
        --skip-db-validation)
            SKIP_DB_VALIDATION=true
            ;;
        # ... existing options
    esac
    shift
done
```

**Database loading section (replace existing):**

```bash
if [ -n "$CLONE_FROM" ]; then
    # Clone mode: stream database from source
    set -o pipefail  # Catch failures anywhere in pipeline

    log "Streaming database from clone source..."

    CLONE_URL="${CLONE_FROM}/utils/clone_export.php"

    curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=database" | \
        openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:${CLONE_KEY} | \
        gunzip | \
        psql -U postgres -d "$SITENAME" -q 2>/dev/null || {
            log_error "Failed to load database from clone source"
            exit 1
        }

    log "Database cloned successfully"

    # Stream uploads
    log "Streaming uploads from clone source..."

    curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_URL}?action=uploads" | \
        tar -xzf - -C "$SITE_ROOT/" || {
            log_error "Failed to load uploads from clone source"
            exit 1
        }

    log "Uploads cloned successfully"

    # Update site URL in settings
    log "Updating site settings for new domain..."

    psql -U postgres -d "$SITENAME" -q -c \
        "UPDATE stg_settings SET stg_value = 'https://${DOMAIN}' WHERE stg_name = 'site_url';" \
        2>/dev/null || true

    SKIP_DB_VALIDATION=true

elif [ "$DB_EXISTS" = false ]; then
    # Normal mode: load from SQL file
    # ... existing code ...
fi
```

**Validation section (wrap existing):**

```bash
if [ "$SKIP_DB_VALIDATION" = false ]; then
    log "Validating database initialization..."
    # ... existing validation code ...
fi
```

### Changes to install.sh

**Pass clone options to _site_init.sh:**

```bash
# In do_site_docker() and do_site_baremetal()

CLONE_OPTS=""
if [ -n "$CLONE_FROM" ]; then
    CLONE_OPTS="--clone-from=${CLONE_FROM} --clone-key=${CLONE_KEY}"

    # Use clone source as upgrade server for themes/plugins
    UPGRADE_SERVER="$CLONE_FROM"
fi

# Call _site_init.sh with clone options
./_site_init.sh "${SITENAME}" "${POSTGRES_PASSWORD}" "${DOMAIN_NAME}" \
    $DOCKER_MODE_FLAG $CLONE_OPTS $OTHER_OPTS
```

**Pre-flight check for clone mode:**

```bash
if [ -n "$CLONE_FROM" ]; then
    print_step "Verifying clone source..."

    MANIFEST=$(curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_FROM}/utils/clone_export.php?action=manifest")

    if [ $? -ne 0 ]; then
        print_error "Cannot connect to clone source or invalid key"
        exit 1
    fi

    # Display clone info (using grep to avoid jq dependency)
    print_info "Clone source: $CLONE_FROM"
    print_info "Database size: $(echo "$MANIFEST" | grep -oP '"database_size_mb"\s*:\s*\K[0-9]+') MB"
    print_info "Uploads size: $(echo "$MANIFEST" | grep -oP '"uploads_size_mb"\s*:\s*\K[0-9]+') MB"
    print_info "Themes: $(echo "$MANIFEST" | grep -oP '"themes"\s*:\s*\[\K[^\]]+' | tr -d '"')"

    if [ "$YES_MODE" = false ]; then
        read -p "Proceed with clone? [y/N] " confirm
        if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
            print_info "Clone cancelled"
            exit 0
        fi
    fi
fi
```

## What Gets Cloned vs Generated

| Item | Behavior |
|------|----------|
| Database (all tables) | **Cloned** - exact copy from source |
| `stg_settings` (all settings) | **Cloned** - exact copy from source |
| `stg_settings.site_url` | **Updated** - set to target domain from install command |
| Uploads directory | **Cloned** - exact copy from source |
| User accounts | **Cloned** - source users/admins preserved |
| API keys (Stripe, etc.) | **Cloned** - same as source |
| `Globalvars_site.php` | **Generated fresh** - target's DB credentials |
| Themes/plugins | **Downloaded** - from source (acting as upgrade server) |

This is a true clone - all application settings and data are identical except for the database connection and site URL which must differ for the target to function.

## Files Changed Summary

**New files (1):**
- `public_html/utils/clone_export.php` - Secure endpoint for exporting site data

**Modified files (3):**
- `maintenance_scripts/install_tools/_site_init.sh` - Add clone options and streaming logic
- `maintenance_scripts/install_tools/install.sh` - Add --clone-from and --clone-key options
- `maintenance_scripts/install_tools/INSTALL_README.md` - Document clone feature

## Examples

### Basic Clone

```bash
# On target server
./install.sh site clientsite SecurePass newclient.com 8080 \
    --clone-from=https://template.joinerysite.com \
    --clone-key=abc123
```

### Clone with Theme Override

```bash
# Clone but activate different theme
./install.sh site clientsite SecurePass newclient.com 8080 \
    --clone-from=https://template.joinerysite.com \
    --clone-key=abc123 \
    --activate customtheme
```

### Non-Interactive Clone (for scripts)

```bash
./install.sh -y site clientsite SecurePass newclient.com 8080 \
    --clone-from=https://template.joinerysite.com \
    --clone-key=abc123
```

## Security Notes

1. **Clone key should be strong** - at least 32 random characters
2. **HTTPS required** - clone_export.php should reject non-HTTPS requests in production
3. **Rotate keys** - change clone_export_key after each use if desired
4. **Monitor logs** - clone requests are logged for audit
5. **Firewall** - optionally restrict clone endpoint to specific IPs

## Future Enhancements

- **Selective clone** - clone only database or only uploads
- **Incremental sync** - update existing clone with changes
- **Scheduled backups** - automatic clone to standby server
- **Clone profiles** - pre-configured settings for different clone scenarios
