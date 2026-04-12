# Deploy and Upgrade Systems

## Overview

Five complementary tools provide deployment and upgrade capabilities:

1. **upgrade.php** - Web-based upgrade system for client installations (recommended)
2. **publish_upgrade.php** - Package creation tool for distributing updates (core + themes + plugins) — lives in the Server Manager plugin
3. **publish_theme.php** - Individual theme/plugin publishing — lives in the Server Manager plugin
4. **install.sh** - Universal installer for Docker and bare-metal deployments
5. **deploy.sh** - Git-based deployment for development environments (not recommended for production)

Tools 1, 4, and 5 use **DeploymentHelper** (`/includes/DeploymentHelper.php`) for shared validation, rollback, and theme/plugin preservation. Tools 2 and 3 require the **Server Manager** plugin to be active.

For Docker and bare-metal deployments, see **[Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md)**.

### Distribution Architecture

Updates are distributed as separate archives:
- **Core archive** (`joinery-core-X.XX.upg.zip`) - Main application without themes/plugins
- **Theme archives** (`theme-THEMENAME-X.XX.upg.zip`) - Individual themes
- **Plugin archives** (`plugin-PLUGINNAME-X.XX.upg.zip`) - Individual plugins

This allows:
- Independent versioning of themes and plugins
- Selective updates (update core without touching themes)
- Smaller download sizes for incremental updates
- Third-party theme/plugin distribution

---

## Quick Reference

### install.sh

**Location:** `/var/www/html/joinerytest/maintenance_scripts/install_tools/install.sh`

Universal installer for Docker and bare-metal deployments. Supports `--themes` flag to download stock themes/plugins from the upgrade server after site creation.

**Full documentation:** [Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md)

---

### deploy.sh

**Location:** `/var/www/html/joinerytest/maintenance_scripts/install_tools/deploy.sh`

> **Note:** This script is functional but not recommended for production. Use `upgrade.php` for production deployments. `deploy.sh` is suitable for development environments where git-based deployment is convenient.

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

# Refresh archives from source, then apply (no version bump needed)
php /var/www/html/joinerytest/public_html/utils/upgrade.php --refresh-archives --verbose
```

**Features:**
- Downloads packages from upgrade server (configured via `upgrade_source` setting)
- Downloads core, themes, and plugins as separate archives
- Pre-deployment validation via DeploymentHelper
- Preserves custom themes/plugins (is_stock: false)
- Enhanced rollback (preserves failed deployments with timestamps)
- Database migrations and composer integration
- **Graceful handling of missing archives** — if a theme or plugin archive returns 404, the upgrade warns and skips it instead of aborting. The core upgrade and all other themes/plugins proceed normally. A summary of skipped items is shown at the end.

**`--refresh-archives` flag:**

For small fixes that don't warrant a version bump, `--refresh-archives` tells `upgrade.php` to first call back to the upgrade server and ask it to rebuild its archives from the current source files, then download and apply them. This avoids the need to run `publish_upgrade` manually.

**Download Flow:**
1. Fetches available upgrade info from upgrade server
2. Downloads core archive (`joinery-core-X.XX.upg.zip`)
3. Downloads each stock theme archive (`theme-THEMENAME-X.XX.upg.zip`)
4. Downloads each stock plugin archive (`plugin-PLUGINNAME-X.XX.upg.zip`)
5. If any theme/plugin archive is unavailable (404), logs a warning and continues
6. Extracts and validates all archives
7. Performs deployment with rollback protection

---

### publish_upgrade.php

**Location:** `plugins/server_manager/includes/publish_upgrade.php`
**Access:** Requires the Server Manager plugin to be active. Superadmin only (permission level 10).

**Preferred usage:** Use the **Publish Upgrade** form on the Server Manager dashboard (`/admin/server_manager`). Enter release notes and submit — the plugin creates a job that builds all archives.

**CLI usage:**
```bash
php plugins/server_manager/includes/publish_upgrade.php "release notes here"
# Auto-detects the next version number
```

> **Note:** The legacy location `utils/publish_upgrade.php` still exists for backward compatibility during the Phase 1 transition. It will be removed in a future release once all remote nodes have been upgraded.

**Features:**
- Creates separate archives for core, themes, and plugins
- Core archive excludes theme/ and plugins/ directories
- Each stock theme/plugin gets its own versioned archive
- Prevents overwriting existing versions
- Automatic cleanup on failure
- Registers upgrade in stg_upgrades table

**Output Archives:**
```
static_files/
├── joinery-core-3.26.upg.zip        # Core application
├── theme-falcon-3.26.upg.zip        # Falcon theme
├── theme-default-3.26.upg.zip       # Default theme
├── plugin-bookings-3.26.upg.zip     # Bookings plugin
├── plugin-controld-3.26.upg.zip     # ControlD plugin
└── ...
```

---

### publish_theme.php

**Location:** `plugins/server_manager/includes/publish_theme.php`
**Access:** Requires the Server Manager plugin to be active. Superadmin only (permission level 10).

```
# Publish a single theme
https://yoursite.com/admin/server_manager/publish_theme?type=theme&name=falcon&version=1.0.0

# Publish a single plugin
https://yoursite.com/admin/server_manager/publish_theme?type=plugin&name=bookings&version=2.1.0

# List available themes (used by marketplace and upgrade.php)
https://yoursite.com/admin/server_manager/publish_theme?list=themes
```

> **Note:** The legacy location `utils/publish_theme.php` still exists for backward compatibility during the Phase 1 transition.

**Features:**
- Publishes individual themes or plugins independently of core
- Allows different versioning for themes/plugins vs core
- Useful for third-party theme/plugin distribution
- Validates theme.json/plugin.json exists before packaging
- Serves catalog listings for the marketplace and `upgrade.php`

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
├── static_files/             # Published upgrade packages
│   ├── joinery-core-X.XX.upg.zip
│   ├── theme-THEMENAME-X.XX.upg.zip
│   └── plugin-PLUGINNAME-X.XX.upg.zip
└── uploads/upgrades/         # Downloaded packages (client sites)
```

**Archive Naming Convention:**
- `joinery-core-X.XX.upg.zip` - Core application (no themes/plugins)
- `theme-{name}-X.XX.upg.zip` - Individual theme archive
- `plugin-{name}-X.XX.upg.zip` - Individual plugin archive

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

## update_database Behavior

### Advisory Lock

`update_database.php` uses a PostgreSQL advisory lock (`pg_try_advisory_lock(99999)`) to prevent concurrent runs. If a second process tries to run while one is already in progress, it exits immediately with "already running." The lock is released automatically when the database connection closes.

### Halt on Migration Failure

Migrations stop on the **first failure** — subsequent migrations are skipped. Fix the failing migration and re-run `update_database.php` to continue.

### Plugin Tables Excluded

`update_database.php` always runs with `include_plugins => false`. Plugin tables are managed through the plugin activation workflow (`PluginManager::activate()` calls `DatabaseUpdater::runPluginTablesOnly()`), not through the core updater. This is intentional — core can't know about plugins at compile time.

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
| `upgrade_source` | URL of upgrade server to download from (e.g., `https://joinerytest.site`) |
| `composerAutoLoad` | Composer vendor path |

**Note:** A site acts as an upgrade server when the **Server Manager** plugin is active. The `upgrade_source` setting specifies where a site *downloads* upgrades from.

---

## Marketplace

The marketplace admin page lets superadmins browse themes and plugins available on the upgrade server and install them with one click.

**Admin Page:** Server Manager > Marketplace (permission level 8)
**Files:** `plugins/server_manager/views/admin/marketplace.php`, `plugins/server_manager/logic/admin_marketplace_logic.php`

> **Note:** The old URL `/admin/admin_marketplace` redirects to `/admin/server_manager/marketplace`.

### How It Works

1. Fetches catalog from the upgrade server (`publish_theme.php?list=themes` and `?list=plugins`)
2. Compares with locally installed themes/plugins
3. Shows a card grid with install buttons for items not yet installed
4. Install downloads the tar.gz archive and extracts it via `AbstractExtensionManager::installFromTarGz()`
5. After install, files are on disk and synced to the database — user must activate separately via Themes or Plugins admin page

### Prerequisites

- `upgrade_source` setting must be configured (URL of the upgrade server)
- The upgrade server must have the **Server Manager** plugin active

### Overwrite Protection

- **Stock extensions** (or those without a manifest) can be reinstalled/replaced from the marketplace
- **Custom extensions** (`is_stock: false` in manifest) are protected — the marketplace refuses to overwrite them

### Catalog Endpoint Fields

The `publish_theme.php` catalog endpoints (`?list=themes`, `?list=plugins`) include:
- `name` — display name (unchanged for backward compatibility)
- `directory_name` — filesystem directory name (used for matching and downloads)
- `display_name`, `version`, `description`, `author`, `is_system`, `is_stock`

---

## Related Documentation

- **[CLAUDE.md](/CLAUDE.md)** - System architecture and development guidelines
- **[Plugin Developer Guide](/docs/plugin_developer_guide.md)** - Plugin development
- **[Server Manager](/docs/server_manager.md)** - Server management, publishing, and backup destinations
- **Specifications:**
  - `/specs/implemented/upgrade_system.md` - Feature parity analysis
  - `/specs/implemented/fix_publish_upgrade_system.md` - Publish upgrade fixes
  - `/specs/implemented/theme_plugin_distribution_refactor.md` - Separate archive distribution
  - `/specs/implemented/server_manager_publish_upgrade.md` - Moving publish/upgrade into server_manager plugin
  - `/specs/implemented/upgrade_graceful_theme_download.md` - Graceful handling of missing archives

---

*Last Updated: 2026-04-12*
