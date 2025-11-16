# Deploy and Upgrade Systems

## Overview

Three complementary tools provide deployment and upgrade capabilities:

1. **deploy.sh** - Git-based deployment for development/production environments
2. **upgrade.php** - Web-based upgrade system for client installations
3. **publish_upgrade.php** - Package creation tool for distributing updates

All three use **DeploymentHelper** (`/includes/DeploymentHelper.php`) for shared validation, rollback, and theme/plugin preservation.

---

## Quick Reference

### deploy.sh

**Location:** `/var/www/html/joinerytest/maintenance_scripts/deploy.sh`

```bash
# Basic deployment
./deploy.sh joinerytest

# Verbose mode (recommended)
./deploy.sh joinerytest --verbose

# Disable auto-rollback for debugging
./deploy.sh joinerytest --norollback

# Manual rollback
./deploy.sh joinerytest --rollback
```

**Features:**
- Git-based deployment from repository
- Pre-deployment validation (PHP syntax, plugin loading, bootstrap tests)
- Automatic rollback on failure (trap-based)
- Preserves custom themes/plugins (is_stock: false)
- Composer integration and database migrations

---

### upgrade.php

**Location:** `/utils/upgrade.php`

**Web Usage:**
```
# Check for upgrades
https://yoursite.com/utils/upgrade?serve-upgrade=1

# Perform upgrade (verbose)
https://yoursite.com/utils/upgrade?verbose=1

# Dry run (test without deploying)
https://yoursite.com/utils/upgrade?dry-run=1
```

**CLI Usage:**
```bash
# Basic upgrade
php /var/www/html/joinerytest/public_html/utils/upgrade.php

# Verbose mode
php /var/www/html/joinerytest/public_html/utils/upgrade.php --verbose

# Dry run
php /var/www/html/joinerytest/public_html/utils/upgrade.php --dry-run
```

**Features:**
- Downloads packages from upgrade server
- Pre-deployment validation via DeploymentHelper
- Preserves custom themes/plugins
- Enhanced rollback (preserves failed deployments with timestamps)
- Database migrations and composer integration

---

### publish_upgrade.php

**Location:** `/utils/publish_upgrade.php`
**Access:** Superadmin only (permission level 8)

```
# Create upgrade package
https://yoursite.com/utils/publish_upgrade?version_major=3&version_minor=26

# With verbose output
https://yoursite.com/utils/publish_upgrade?version_major=3&version_minor=26&verbose=1
```

**Features:**
- Creates ZIP packages from public_html directory
- Prevents overwriting existing versions
- Automatic cleanup on failure
- Registers upgrade in stg_upgrades table

---

## How It Works

### Deployment Flow

```
1. Download/extract to staging directory
2. DeploymentHelper validates:
   - PHP syntax on all files
   - Plugin class loading
   - Bootstrap/core components
3. DeploymentHelper preserves custom themes/plugins (is_stock: false)
4. Backup current installation to public_html_last/
5. Deploy staged files to public_html/
6. Run database migrations (update_database.php)
7. Run composer_install_if_needed.php
8. Fix permissions (www-data:user1, 775)

If ANY step fails → Automatic rollback
```

### Directory Structure

```
/var/www/html/{site}/
├── public_html/              # Current live installation
├── public_html_last/         # Backup (for rollback)
├── public_html_stage/        # Staging area for validation
├── public_html_failed_*/     # Preserved failed deployments (timestamped)
├── static_files/             # Upgrade packages (.upg.zip)
└── uploads/upgrades/         # Downloaded packages
```

---

## Theme/Plugin Preservation

Custom themes/plugins are automatically preserved during upgrades using manifest files:

**Example manifest (theme.json or plugin.json):**
```json
{
    "name": "controld",
    "version": "2.1.0",
    "description": "ControlD DNS management plugin",
    "is_stock": true
}
```

- **is_stock: true** - Updated during upgrades (stock code)
- **is_stock: false** - Preserved during upgrades (custom code)

If manifest is missing, it's auto-generated with `is_stock: true`.

---

## DeploymentHelper API

**Validation:**
```php
DeploymentHelper::validatePHPSyntax($directory, $verbose)
DeploymentHelper::testPluginLoading($stage_dir, $verbose)
DeploymentHelper::testBootstrap($stage_dir, $verbose)
```

**Theme/Plugin Preservation:**
```php
DeploymentHelper::preserveCustomThemesPlugins($stage_dir, $backup_dir, $verbose)
```

**Rollback:**
```php
DeploymentHelper::performRollback($target_site, $preserve_failed, $verbose)
```

All methods return structured arrays with success status, errors, and detailed results.

---

## Common Issues

**Permission Errors:**
```bash
sudo chown -R www-data:user1 /var/www/html/joinerytest/public_html
sudo chmod -R 775 /var/www/html/joinerytest/public_html
```

**Validation Failures:**
- Failed deployment preserved in `public_html_failed_*` directory
- Inspect for syntax errors or missing dependencies
- Fix and redeploy

**Custom Theme Overwritten:**
- Check manifest has `"is_stock": false`
- Restore from public_html_last/ if needed

**Rollback Failed:**
- Check public_html_last/ exists
- Manually restore from backup
- Fix permissions after restore

---

## Configuration

**Required settings** (in `/config/Globalvars_site.php` or stg_settings):

| Setting | Description |
|---------|-------------|
| `baseDir` | Base directory (e.g., `/var/www/html/`) |
| `site_template` | Site directory name (e.g., `joinerytest`) |
| `system_version` | Current version (e.g., `3.25`) |
| `upgrade_server_active` | Enable upgrade server mode |
| `upgrade_server_url` | Upgrade server URL |
| `composerAutoLoad` | Composer vendor path |

---

## Related Documentation

- **[CLAUDE.md](/CLAUDE.md)** - System architecture and development guidelines
- **[Plugin Developer Guide](/docs/plugin_developer_guide.md)** - Plugin development
- **Specifications:**
  - `/specs/implemented/upgrade_system.md` - Feature parity analysis
  - `/specs/implemented/fix_publish_upgrade_system.md` - Publish upgrade fixes

---

*Last Updated: 2025-10-18*
