# Theme/Plugin Marketplace MVP

**Status:** planned
**Priority:** normal
**Created:** 2026-04-04

## Goal

Add an admin page where users can browse themes and plugins available on the upgrade server and install them with one click. This builds on the existing distribution infrastructure (`publish_theme.php` catalog endpoints and tar.gz archives) without introducing a separate marketplace server.

## Non-Goals (Post-MVP)

- Ratings, reviews, download counts
- Search or category filtering
- Third-party / multi-source marketplace servers
- Paid themes/plugins or licensing
- Screenshot previews or live demos
- Automatic update checks from the marketplace page
- Per-item updates (updates are handled by the existing upgrade system)

## Architecture

```
Upgrade Server (publish_theme.php)           Client Site (admin_marketplace)
┌──────────────────────────────┐            ┌──────────────────────────────┐
│  ?list=themes → JSON catalog │◄───────────│  Fetch catalog via cURL      │
│  ?list=plugins → JSON catalog│            │  Compare with local installs │
│  ?download=name → tar.gz     │◄───────────│  One-click install           │
└──────────────────────────────┘            └──────────────────────────────┘
```

No new database tables. No new server-side infrastructure. The upgrade server already has catalog and download endpoints — this spec adds a client-side browsing UI and a tar.gz install method.

## 1. Server: Enrich Catalog Endpoints

Extend the existing `publish_theme.php` JSON responses to include additional fields needed by the marketplace UI.

### Current response (themes)

```json
{
  "success": true,
  "themes": [
    {
      "name": "Starter Theme",
      "display_name": "Starter Theme",
      "version": "1.0.0",
      "description": "",
      "is_system": false,
      "is_stock": true
    }
  ]
}
```

### Updated response (themes)

```json
{
  "success": true,
  "themes": [
    {
      "name": "Starter Theme",
      "directory_name": "starter-theme",
      "display_name": "Starter Theme",
      "version": "1.0.0",
      "description": "A clean starting point for custom themes",
      "author": "Joinery",
      "is_system": false,
      "is_stock": true
    }
  ]
}
```

Changes:
- **`directory_name`** — new field with the actual filesystem directory name. The client uses this to match against local installs and to construct download URLs. The existing `name` field is left unchanged for backward compatibility with the upgrade system.
- **`author`** — add from manifest `author` field (empty string if absent).

Same changes for `?list=plugins`.

### Implementation

In `publish_theme.php`, for the `?list=themes` block:

```php
$themes[] = [
    'name' => $theme_data['name'] ?? basename(dirname($json_file)),  // unchanged
    'directory_name' => basename(dirname($json_file)),                // new
    'display_name' => $theme_data['display_name'] ?? $theme_data['displayName'] ?? $theme_data['name'] ?? basename(dirname($json_file)),
    'version' => $theme_data['version'] ?? '1.0.0',
    'description' => $theme_data['description'] ?? '',
    'author' => $theme_data['author'] ?? '',
    'is_system' => $theme_data['is_system'] ?? $theme_data['system'] ?? false,
    'is_stock' => true,
];
```

Same pattern for `?list=plugins`, adding `directory_name` and `author`.

## 2. Client: `installFromTarGz()` in AbstractExtensionManager

The existing `installFromZip()` handles ZIP archives. The server distributes tar.gz. Add a parallel method.

### Method: `AbstractExtensionManager::installFromTarGz($tar_path)`

```php
/**
 * Install extension from a tar.gz archive.
 * Mirrors installFromZip() but handles tar.gz format.
 *
 * @param string $tar_path Path to tar.gz file
 * @return string Extension name that was installed
 * @throws Exception on failure
 */
public function installFromTarGz($tar_path) {
    if (!file_exists($tar_path)) {
        throw new Exception("Archive not found: $tar_path");
    }

    $temp_dir = sys_get_temp_dir() . '/' . $this->extension_type . '_' . uniqid();
    mkdir($temp_dir, 0775, true);

    try {
        // Extract tar.gz
        $cmd = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($tar_path),
            escapeshellarg($temp_dir)
        );
        $output = [];
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        if ($exit_code !== 0) {
            throw new Exception("Failed to extract archive: " . implode("\n", $output));
        }

        // Find and validate manifest (same as installFromZip)
        $manifest_data = $this->findAndValidateManifest($temp_dir);
        $extension_root = $manifest_data['root'];
        $manifest = $manifest_data['manifest'];
        $extension_name = $manifest_data['name'];

        if (!$this->validateName($extension_name)) {
            throw new Exception("Invalid {$this->extension_type} name: $extension_name");
        }

        // If already exists, check if safe to replace
        $target_path = $this->getExtensionPath($extension_name);
        if (is_dir($target_path)) {
            // Refuse to overwrite custom (non-stock) extensions
            $manifest_path = $target_path . '/' . $this->manifest_filename;
            if (file_exists($manifest_path)) {
                $local_manifest = json_decode(file_get_contents($manifest_path), true);
                if (is_array($local_manifest) && isset($local_manifest['is_stock']) && !$local_manifest['is_stock']) {
                    throw new Exception("Cannot replace custom {$this->extension_type} '$extension_name'. It is marked is_stock: false.");
                }
            }
            // Stock or no manifest — safe to delete and replace
            $this->cleanup($target_path);
        }

        if (!rename($extension_root, $target_path)) {
            throw new Exception("Failed to install {$this->extension_type} files");
        }

        $this->setPermissions($target_path);
        $this->postInstall($extension_name, $manifest);
        $this->cleanup($temp_dir);

        return $extension_name;

    } catch (Exception $e) {
        $this->cleanup($temp_dir);
        throw $e;
    }
}
```

This is intentionally almost identical to `installFromZip()` — the only difference is the extraction step (tar vs ZipArchive). A shared `installFromArchive` refactor is a reasonable follow-up but not worth the complexity for MVP.

## 3. Client: Admin Marketplace Page

### Files

| File | Purpose |
|------|---------|
| `adm/admin_marketplace.php` | View — marketplace browse UI |
| `adm/logic/admin_marketplace_logic.php` | Logic — fetch catalog, handle install action |

### Route

No `serve.php` entry needed — the `/admin/*` wildcard auto-routes to `adm/admin_marketplace.php`.

### Permission

Permission level **8** (superadmin). Installing code from a remote server is a privileged operation.

### Logic: `admin_marketplace_logic.php`

**Actions:**

1. **Default (browse)** — Fetch catalog, compare with local installs, return view data.
2. **`action=install`** — Download and install a specific theme or plugin.

#### Browse flow

```php
function admin_marketplace_logic($get, $post) {
    $session = SessionControl::get_instance();
    $session->check_permission(8);

    $settings = Globalvars::get_instance();
    $upgrade_source = $settings->get_setting('upgrade_source');

    if (empty($upgrade_source)) {
        return LogicResult::page_data([
            'error' => 'No upgrade source configured. Set the upgrade_source setting to use the marketplace.',
            'themes' => [],
            'plugins' => [],
        ]);
    }

    // Handle install action (POST only)
    if (($post['action'] ?? '') === 'install') {
        return handle_install($post, $upgrade_source, $session);
    }

    // Fetch remote catalogs
    $remote_themes = fetch_catalog($upgrade_source, 'themes');
    $remote_plugins = fetch_catalog($upgrade_source, 'plugins');

    // Get local installs
    $local_themes = get_local_theme_names();
    $local_plugins = get_local_plugin_names();

    // Merge remote + local status
    $themes = enrich_with_local_status($remote_themes, $local_themes, 'theme');
    $plugins = enrich_with_local_status($remote_plugins, $local_plugins, 'plugin');

    return LogicResult::page_data([
        'themes' => $themes,
        'plugins' => $plugins,
        'upgrade_source' => $upgrade_source,
    ]);
}
```

#### `fetch_catalog($upgrade_source, $type)`

```php
function fetch_catalog($upgrade_source, $type) {
    $url = rtrim($upgrade_source, '/') . '/utils/publish_theme?list=' . $type;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        error_log("Marketplace: failed to fetch $type catalog from $url — HTTP $http_code, $curl_error");
        return [];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        return [];
    }

    return $data[$type] ?? [];
}
```

#### `enrich_with_local_status($remote_items, $local_names, $type)`

Compares remote catalog with locally installed items. Returns enriched array with `install_status`:

- **`not_installed`** — available on server, not present locally
- **`installed`** — present locally

Updates are handled by the existing upgrade system (`upgrade.php`), not the marketplace. No "update available" detection needed for MVP.

```php
function enrich_with_local_status($remote_items, $local_names, $type) {
    $result = [];
    foreach ($remote_items as $item) {
        $dir_name = $item['directory_name'];
        $item['type'] = $type;
        $item['install_status'] = in_array($dir_name, $local_names) ? 'installed' : 'not_installed';
        $result[] = $item;
    }
    return $result;
}
```

#### Getting local install lists

Use existing methods that already scan the filesystem:

- **Themes:** `Theme::get_all_themes_with_status()` returns an array keyed by directory name. Extract just the names with `array_keys()`.
- **Plugins:** `MultiPlugin::get_all_plugins_with_status()` returns a similar array. Extract just the names with `array_keys()`.

#### Install action: `handle_install($post, $upgrade_source, $session)`

```php
function handle_install($post, $upgrade_source, $session) {
    // CSRF check
    $token = $post['csrf_token'] ?? '';
    if (!$session->validate_csrf_token($token)) {
        return LogicResult::redirect('/admin/admin_marketplace', 'error', 'Invalid request token.');
    }

    $name = basename($post['name'] ?? '');
    $type = ($post['type'] ?? '') === 'plugin' ? 'plugin' : 'theme';

    if (empty($name)) {
        return LogicResult::redirect('/admin/admin_marketplace', 'error', 'No item specified.');
    }

    // Build download URL
    $download_url = rtrim($upgrade_source, '/') . '/utils/publish_theme?download=' . urlencode($name);
    if ($type === 'plugin') {
        $download_url .= '&type=plugin';
    }

    // Download to temp file
    $temp_file = tempnam(sys_get_temp_dir(), 'mkt_') . '.tar.gz';

    $ch = curl_init($download_url);
    $fp = fopen($temp_file, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($http_code !== 200) {
        @unlink($temp_file);
        return LogicResult::redirect('/admin/admin_marketplace', 'error',
            "Failed to download $type '$name': HTTP $http_code");
    }

    // Install via manager
    try {
        if ($type === 'plugin') {
            require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
            $manager = new PluginManager();
        } else {
            require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
            $manager = new ThemeManager();
        }

        $installed_name = $manager->installFromTarGz($temp_file);

        // Sync DB so the new item appears in admin pages
        $manager->sync();

        @unlink($temp_file);

        return LogicResult::redirect('/admin/admin_marketplace', 'message',
            ucfirst($type) . " '$installed_name' installed successfully.");

    } catch (Exception $e) {
        @unlink($temp_file);
        return LogicResult::redirect('/admin/admin_marketplace', 'error',
            "Install failed: " . $e->getMessage());
    }
}
```

### View: `admin_marketplace.php`

Standard admin page layout. Two sections: **Themes** and **Plugins**.

Each item is a card showing:
- Display name + version
- Author (if present)
- Description
- Status badge: **Installed** (green) or nothing
- Action: **Install** button (POST form with CSRF token) or **Installed** badge (no action)

Installing places the files on disk and registers in the database. It does **not** activate the theme/plugin. The success message should guide the user: *"Theme 'xyz' installed. Go to [Themes](/admin/admin_themes) to activate it."*

```
┌─────────────────────────────────────────────────────────┐
│  Marketplace                                [Refresh]   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  Themes                                                 │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ Starter      │ │ Falcon       │ │ Zouk Night   │    │
│  │ v1.2.0       │ │ v2.0.1       │ │ v1.0.0       │    │
│  │ by Joinery   │ │ by Joinery   │ │ by Joinery   │    │
│  │              │ │              │ │              │    │
│  │ A clean...   │ │ Modern...    │ │ Dance...     │    │
│  │              │ │              │ │              │    │
│  │ [Installed]  │ │ [Installed]  │ │ [Install]    │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
│                                                         │
│  Plugins                                                │
│  ┌──────────────┐ ┌──────────────┐                      │
│  │ Bookings     │ │ ControlD     │                      │
│  │ v3.1.0       │ │ v1.0.0       │                      │
│  │ by Joinery   │ │ by Joinery   │                      │
│  │              │ │              │                      │
│  │ Event book...│ │ DNS manag... │                      │
│  │              │ │              │                      │
│  │ [Installed]  │ │ [Install]    │                      │
│  └──────────────┘ └──────────────┘                      │
│                                                         │
│  Source: https://joinerytest.site                        │
└─────────────────────────────────────────────────────────┘
```

#### UI details

- Cards use Bootstrap grid: `col-lg-4 col-md-6` for responsive layout
- Install buttons are `<form method="POST">` with hidden CSRF token, name, and type fields, plus `onclick="return confirm('Install...')"` confirmation
- **Installed** badge is non-clickable — updates are handled by the upgrade system
- If `upgrade_source` is not configured, show an info message with a link to settings
- If catalog fetch fails, show a warning but don't break the page
- Show spinner or "Loading..." text while fetch is in progress? No — this is a server-rendered page, the fetch happens in PHP before render. If slow, the page load is slow. Acceptable for MVP.

#### Admin menu placement

Add to the System menu alongside existing theme/plugin admin pages:

```
System
  ├── Themes
  ├── Plugins
  ├── Marketplace   ← new
  └── ...
```

Menu entry in the admin menu config (same pattern as other System pages):

```php
'system-marketplace' => [
    'label' => 'Marketplace',
    'url' => '/admin/admin_marketplace',
    'permission' => 8,
    'parent' => 'system',
]
```

## 4. Security

| Concern | Mitigation |
|---------|-----------|
| Permission | Level 8 required (superadmin only) |
| Download source | Only downloads from configured `upgrade_source` — no arbitrary URLs |
| Archive extraction | Uses `tar -xzf` to temp dir, then validates manifest before moving to final location |
| Path traversal | `basename()` on all user-supplied names; `validateName()` rejects `..`, `/`, `\` |
| CSRF on install | Install action requires POST with session CSRF token |
| HTTPS | cURL uses `CURLOPT_SSL_VERIFYPEER => true` |
| Overwrite protection | Refuses to replace local extensions marked `is_stock: false`; stock or unmanifested directories are deleted and replaced |

## 5. Files to Create/Modify

| File | Action | Description |
|------|--------|-------------|
| `utils/publish_theme.php` | Modify | Add `directory_name` and `author` fields to catalog responses |
| `includes/AbstractExtensionManager.php` | Modify | Add `installFromTarGz()` method |
| `adm/admin_marketplace.php` | Create | Marketplace browse UI |
| `adm/logic/admin_marketplace_logic.php` | Create | Fetch catalog, handle install |
| `includes/AdminPage.php` (or menu config) | Modify | Add Marketplace to System menu |
| `docs/deploy_and_upgrade.md` | Modify | Add Marketplace section to documentation |

## 6. Settings

No new settings. Uses existing:
- **`upgrade_source`** — URL of the server to fetch catalog/downloads from
- **`upgrade_server_active`** — must be enabled on the server side (already required for catalog endpoints)

## 7. Testing Plan

1. **Catalog fetch** — Verify `?list=themes` and `?list=plugins` return `directory_name` and `author` fields alongside existing fields
2. **Backward compatibility** — Verify existing `name` field is unchanged (upgrade.php still works)
3. **Browse page** — Load marketplace page, verify themes and plugins display with correct installed/not-installed status
4. **Install theme** — Click Install on a theme not present locally, verify it downloads, extracts, and appears in theme admin
5. **Install plugin** — Same for a plugin
6. **Post-install guidance** — Verify success message links to the appropriate admin page for activation
7. **Already installed** — Verify Install button does not appear for items already on disk
7b. **Reinstall over existing stock** — Manually place a stock theme directory, verify marketplace install replaces it cleanly
7c. **Custom extension protected** — Place a directory with `is_stock: false` in its manifest, verify marketplace install refuses with clear error
8. **No upgrade_source** — Clear the setting, verify friendly error message
9. **Server unreachable** — Point upgrade_source at a bad URL, verify graceful degradation
10. **Permission gate** — Verify non-superadmin cannot access the page
11. **CSRF** — Verify install rejects requests without valid CSRF token
