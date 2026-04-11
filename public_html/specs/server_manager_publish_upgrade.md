# Server Manager: Move Upgrade Server Features into Plugin

**Created:** 2026-04-11
**Status:** Active

## Problem

`utils/publish_upgrade.php` is a standalone script that builds upgrade archives from the control plane source code. It has its own web UI, can be run from the CLI by anyone with shell access, and is gated only by the `upgrade_server_active` database setting. There's no permission check, no audit trail, and no integration with the server manager's job system.

Publishing upgrades should be a deliberate, controlled action. The server manager plugin already wraps it via `JobCommandBuilder::build_publish_upgrade` and the dashboard "Publish Upgrade" form, but the actual logic lives outside the plugin in `utils/`.

Related settings in `admin_settings.php` that control this:
- `upgrade_server_active` — toggles whether this site acts as an upgrade server (shows/hides "Publish Upgrade" links in admin settings pages)
- `upgrade_source` — URL of the upgrade server that remote nodes pull from (stays — this is used by `upgrade.php` on remote nodes)
- `upgrade_location` — appears unused/legacy

## Solution

Move the publish upgrade functionality entirely into the server manager plugin:

1. The script moves from `utils/publish_upgrade.php` to `plugins/server_manager/includes/publish_upgrade.php`
2. The web UI for publishing moves into a plugin admin view (or the existing dashboard form replaces it)
3. The `upgrade_server_active` setting is **removed** — activating the server_manager plugin is what enables upgrade server functionality
4. "Publish Upgrade" links in admin settings pages are removed (publishing is now on the server manager dashboard)
5. The `--refresh-archives` callback URL in `upgrade.php` is updated to point to the new plugin location

## Backward Compatibility: Three-Phase Rollout

Remote nodes running `upgrade.php --refresh-archives` call back to `/utils/publish_upgrade?refresh-archives=1` on the control plane. If we delete that file before all nodes are upgraded, `--refresh-archives` breaks on nodes still running the old `upgrade.php`.

### Phase 1: Copy + update (this release)
- Copy `publish_upgrade.php` into the plugin (both locations exist simultaneously)
- Update `upgrade.php` with the new callback URL, settings checks, etc.
- Update all admin UI references
- Publish a new version and apply full upgrades to all remote nodes
- Remote nodes get the new `upgrade.php` (with updated URL) via self-update

### Phase 2: Delete original (subsequent release)
- Delete `utils/publish_upgrade.php` — now safe because all nodes point to the new URL
- Publish and upgrade again

The key insight: `upgrade.php` is in the self-update list, so remote nodes get the new callback URL during a full upgrade. But they need the old URL to still work *during* that upgrade if `--refresh-archives` is used. By keeping both locations alive in Phase 1, both old and new `upgrade.php` versions work.

## Current References to `upgrade_server_active`

The setting currently gates:
- `utils/publish_upgrade.php` line 24 — exits if setting is off
- `utils/upgrade.php` line 65 — serves upgrade archives to remote nodes if setting is on
- `utils/publish_theme.php` line 26 — serves theme archives if setting is on
- `adm/admin_settings.php` lines 39, 1242, 1254 — shows "Publish Upgrade" link and the setting toggle
- `adm/admin_settings_email.php` line 40 — shows "Publish Upgrade" link
- `adm/admin_settings_payments.php` line 62 — shows "Publish Upgrade" link

After the move:
- `publish_upgrade.php` — moved into plugin, gated by plugin activation + permission level 10
- `upgrade.php` serve mode (line 65) — should check if the server_manager plugin is active instead of the setting
- `publish_theme.php` — should also check plugin activation instead of the setting
- Admin settings links — removed (publishing is on the server manager dashboard)
- The `upgrade_server_active` setting toggle in admin_settings — removed

## What Changes

### `plugins/server_manager/includes/publish_upgrade.php`
- Moved from `utils/publish_upgrade.php`
- Remove the `upgrade_server_active` check (plugin activation replaces it)
- Update the CLI bootstrap path (`__DIR__` changes)
- The `?refresh-archives=1` API mode continues to work from its new location

### `plugins/server_manager/views/admin/publish.php` (optional)
- If the full web UI (version list, delete, form) is worth keeping as its own page, create a plugin view for it
- Otherwise, the dashboard "Publish Upgrade" form is sufficient and the old web UI is dropped

### `utils/publish_upgrade.php`
- Phase 1: Keep in place (both locations work)
- Phase 2: Delete after all nodes are upgraded

### `utils/upgrade.php`
- Line 65: Change `$settings->get_setting('upgrade_server_active')` to check if server_manager plugin is active
- Line 1765: Update `--refresh-archives` callback URL to new plugin path
- This file self-updates, so remote nodes get the fix on their next full upgrade

### `utils/publish_theme.php`
- Phase 1: Keep in place (both locations work)
- Phase 2: Delete after all nodes are upgraded

### `plugins/server_manager/includes/publish_theme.php`
- Copied from `utils/publish_theme.php`
- Remove `upgrade_server_active` check (plugin activation replaces it)
- Update bootstrap path

### `plugins/server_manager/views/admin/marketplace.php`
- Moved from `adm/admin_marketplace.php`
- Update breadcrumbs to Server Manager context
- Update menu-id to `server-manager`

### `plugins/server_manager/logic/admin_marketplace_logic.php`
- Moved from `adm/logic/admin_marketplace_logic.php`
- Update `/utils/publish_theme` URLs to `/admin/server_manager/publish_theme`
- Update redirect URLs from `/admin/admin_marketplace` to `/admin/server_manager/marketplace`

### `adm/admin_marketplace.php`
- Becomes redirect stub to `/admin/server_manager/marketplace`

### `utils/upgrade.php`
- Line 65: Already updated to check plugin activation
- Line 83: Update `theme_endpoint` from `/utils/publish_theme` to `/admin/server_manager/publish_theme`
- Line 1765: Already updated refresh-archives callback URL

### `plugins/server_manager/serve.php`
- Add route for `/admin/server_manager/publish_theme`

### `plugins/server_manager/migrations/migrations.php`
- Add migration to move marketplace menu entry from System to Server Manager parent, update URL

### `plugins/server_manager/includes/JobCommandBuilder.php`
- Update `build_publish_upgrade` path:
  ```php
  'cmd' => "cd {web_root} && php plugins/server_manager/includes/publish_upgrade.php {$notes}"
  ```

### `adm/admin_settings.php`
- Remove `upgrade_server_active` dropdown (lines 1242-1245)
- Remove "Publish Upgrade" altlink (line 39-40)
- Keep `upgrade_source` setting (remote nodes still need it)

### `adm/admin_settings_email.php`
- Remove "Publish Upgrade" altlink (line 40-41)

### `adm/admin_settings_payments.php`
- Remove "Publish Upgrade" altlink (line 62-63)

### `serve.php`
- Add a route for the plugin's publish_upgrade endpoint (for `--refresh-archives` callbacks):
  ```php
  '/plugins/server_manager/publish_upgrade' => ['view' => 'plugins/server_manager/includes/publish_upgrade']
  ```
  Or handle it through the plugin's existing AJAX/view routing.

### `CLAUDE.md`
- Update deploy instructions to reference new location
- Remove mention of `upgrade_server_active` setting

## What Does NOT Change

- `utils/upgrade.php` — stays in `utils/`, runs on remote servers
- `utils/update_database.php` — stays in `utils/`
- `upgrade_source` setting — stays, used by remote nodes to find the control plane
- `static_files/` archive location — unchanged
- Self-update mechanism — still self-updates from staged archives

## Settings Cleanup

| Setting | Action |
|---------|--------|
| `upgrade_server_active` | DELETE — plugin activation replaces it |
| `upgrade_source` | KEEP — remote nodes need this to find the control plane |
| `upgrade_location` | DELETE if unused — appears to be legacy |

## Files

| File | Action |
|------|--------|
| `utils/publish_upgrade.php` | KEEP (Phase 1), DELETE (Phase 2) |
| `utils/publish_theme.php` | KEEP (Phase 1), DELETE (Phase 2) |
| `utils/upgrade.php` | MODIFY — update theme_endpoint URL |
| `plugins/server_manager/includes/publish_upgrade.php` | CREATE — copied from utils/ |
| `plugins/server_manager/includes/publish_theme.php` | CREATE — copied from utils/ |
| `plugins/server_manager/views/admin/marketplace.php` | CREATE — moved from adm/ |
| `plugins/server_manager/logic/admin_marketplace_logic.php` | CREATE — moved from adm/logic/ |
| `plugins/server_manager/serve.php` | MODIFY — add publish_theme route |
| `plugins/server_manager/includes/JobCommandBuilder.php` | MODIFY — update path in build_publish_upgrade |
| `plugins/server_manager/migrations/migrations.php` | MODIFY — move marketplace menu entry |
| `adm/admin_settings.php` | MODIFY — remove upgrade_server_active toggle and Publish Upgrade link |
| `adm/admin_settings_email.php` | MODIFY — remove Publish Upgrade link |
| `adm/admin_settings_payments.php` | MODIFY — remove Publish Upgrade link |
| `adm/admin_marketplace.php` | MODIFY — redirect stub |
| `CLAUDE.md` | MODIFY — update deploy instructions |

## Verification

### Phase 1
1. Publish an upgrade from the server manager dashboard — verify archives are created
2. Verify `/utils/publish_upgrade` still works (both locations active)
3. Verify `/utils/publish_theme?list=themes` still works
4. Verify `/admin/server_manager/publish_theme?list=themes` works
5. Verify `/admin/server_manager/marketplace` loads and shows themes/plugins
6. Verify `/admin/admin_marketplace` redirects to the plugin version
7. Apply a full upgrade on a remote node — verify it completes and self-updates upgrade.php
8. After the full upgrade, verify `--refresh-archives` works with the NEW callback URL
9. Verify `upgrade_server_active` setting no longer appears in admin settings

### Phase 2 (after all nodes upgraded)
1. Delete `utils/publish_upgrade.php` and `utils/publish_theme.php`
2. Verify old URLs return 404
3. Verify `--refresh-archives` and marketplace still work via new URLs
