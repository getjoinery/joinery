# Declarative Plugin Admin Menus

## Problem

Every time a plugin needs an admin menu item -- adding one, renaming it, reordering it, moving it under a different parent -- it requires a new migration. These migrations are boilerplate-heavy (idempotency checks, parent ID lookups, raw SQL), fragile (hard-coded parent IDs that can drift across environments), and the source of truth for menu state ends up scattered across migration history rather than living in one readable place.

Plugins already declare an `adminMenu` key in `plugin.json`, and `PluginHelper` already parses and validates it. But nothing processes those declarations into the database. This spec bridges that gap.

## Goals

1. Plugins declare their admin menus entirely in `plugin.json` -- no migrations needed for menu items.
2. Menus can be placed anywhere in the menu tree, including as children of core menus or other plugin menus.
3. Activate syncs menus in; deactivate removes them. No manual uninstall cleanup.
4. Existing migration-based menus continue to work during transition.
5. The sync is idempotent and safe to run repeatedly (every activate, every `sync()`).

## Non-Goals

- Core (non-plugin) menus are not covered. They change infrequently and can remain seed data / migrations.
- This spec does not change the admin menu UI (`admin_admin_menu.php` / `admin_admin_menu_edit.php`). Manual edits to declarative menus via the UI will be overwritten on next sync -- this is intentional and documented.
- No new database tables or columns. The `plugin.json` file is the source of truth; the existing `amu_admin_menus` table is sufficient.

## Design

### plugin.json Schema

The `adminMenu` key becomes an array of menu group objects. Each group declares a placement anchor and a list of items.

```json
{
  "name": "server_manager",
  "version": "1.0.0",
  "adminMenu": [
    {
      "slug": "server-manager",
      "title": "Server Manager",
      "icon": "server",
      "permission": 10,
      "order": 14,
      "items": [
        {
          "slug": "server-manager-dashboard",
          "title": "Dashboard",
          "url": "/admin/server_manager",
          "order": 1
        },
        {
          "slug": "server-manager-destinations",
          "title": "Destinations",
          "url": "/admin/server_manager/destinations",
          "order": 3
        },
        {
          "slug": "server-manager-jobs",
          "title": "Jobs",
          "url": "/admin/server_manager/jobs",
          "order": 6
        }
      ]
    },
    {
      "slug": "system-marketplace",
      "title": "Marketplace",
      "url": "/admin/server_manager/marketplace",
      "permission": 8,
      "icon": "store",
      "parent": "server-manager",
      "order": 4
    }
  ]
}
```

#### Menu Item Fields

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `slug` | string | Yes | -- | Unique identifier. Used as the `amu_slug` value and as the sync key (how the system matches declared menus to database rows). Must be unique across the entire menu system. |
| `title` | string | Yes | -- | Display text (`amu_menudisplay`). Max 32 characters. |
| `url` | string | No | `""` | The target page (`amu_defaultpage`). Omit for parent-only items. If the URL starts with `/`, it is stored as-is. Otherwise `/admin/` is prepended automatically. |
| `icon` | string | No | `null` | Icon identifier (`amu_icon`). |
| `permission` | int | No | 10 | Minimum permission level (`amu_min_permission`). |
| `order` | int | Yes | -- | Sort position within its parent level (`amu_order`). |
| `settingActivate` | string | No | `null` | Setting name that must be truthy for the menu to display (`amu_setting_activate`). |
| `disabled` | bool | No | `false` | Whether the menu is disabled by default (`amu_disable`). |

#### Placement: Creating Top-Level Groups with Children

A menu item with an `items` array is a **parent group**. It creates a top-level menu, and each entry in `items` becomes a child. Children inherit the parent's `permission` unless they override it. Children do not need a `parent` field -- their placement is implicit.

```json
{
  "slug": "my-plugin",
  "title": "My Plugin",
  "icon": "plug",
  "permission": 8,
  "order": 15,
  "items": [
    { "slug": "my-plugin-dashboard", "title": "Dashboard", "url": "/admin/my_plugin", "order": 1 },
    { "slug": "my-plugin-settings", "title": "Settings", "url": "/admin/my_plugin/settings", "order": 2 }
  ]
}
```

#### Placement: Attaching to an Existing Parent

A menu item with a `parent` field (and no `items`) is a **child attachment**. The `parent` value is the `amu_slug` of the target parent menu -- which can be a core menu, another plugin's menu, or a group declared earlier in the same `adminMenu` array.

```json
{
  "slug": "incoming",
  "title": "Incoming",
  "url": "/plugins/email_forwarding/admin/admin_email_forwarding",
  "parent": "emails",
  "permission": 5,
  "order": 10,
  "settingActivate": "email_forwarding_enabled"
}
```

This attaches a menu item under the existing core "Emails" group (slug `emails`).

#### Placement: Top-Level Item (No Children, No Parent)

A menu item with neither `items` nor `parent` becomes a standalone top-level entry.

```json
{
  "slug": "my-tool",
  "title": "My Tool",
  "url": "/admin/my_tool",
  "icon": "wrench",
  "permission": 10,
  "order": 16
}
```

#### Referencing Other Plugin Menus

A plugin can attach items to another plugin's menu group using its slug. The system resolves parent slugs at sync time from whatever currently exists in the database. If the target parent does not exist, the sync logs a warning and skips that item (it does not fail the entire activation).

### Sync Algorithm

A single method `syncAdminMenus(string $plugin_name, array|null $declared_menus)` in `PluginManager` handles both creation and removal. The plugin's `plugin.json` is the source of truth -- the declared slugs are what the plugin owns. Previously synced slugs are cached in the `_menu_slugs` key of the plugin's `plg_metadata` JSON column for diffing on prune.

It runs:

1. **Read** the `adminMenu` array from the plugin's `plugin.json` via `PluginHelper`. If the plugin has no `adminMenu` key, this is an empty array. On deactivate/uninstall, an empty array is passed directly.
2. **Flatten** the tree inline into an ordered list (parents first, then children), resolving `parent_slug` from `items` nesting or explicit `parent` field. Collect all declared slugs.
3. **Upsert** each entry by looking up existing rows by `amu_slug`:
   - If the row exists, update all fields.
   - If the row does not exist, insert it.
   - Resolve `parent_slug` to `amu_admin_menu_id` via database lookup. If the target parent does not exist, log a warning and skip the item.
4. **Prune**: Diff declared slugs against the previous slug set (from `plg_metadata`). Delete rows whose slugs are in the previous set but not the current one. Children are deleted before parents. On deactivate (empty declared list), this removes all the plugin's menu rows.
5. **Cache** the current declared slugs in `plg_metadata._menu_slugs` for the next sync.

### Lifecycle Integration

#### On Activate (`onActivate`)

After the existing activation steps (table updates, activate.php hook, deletion rules), call `syncAdminMenus($name)`.

#### On Deactivate (`onDeactivate`)

Before the existing deactivation steps, call `syncAdminMenus($name, [])`. The prune step removes all the plugin's previously synced slugs.

#### On Sync (`sync()`)

After the existing sync steps (table updates, migrations, deletion rules), iterate active plugins and call `syncAdminMenus($name)` for each.

#### On Uninstall

Call `syncAdminMenus($name, [])` in the `uninstall()` method *before* running the plugin's uninstall hook. This removes all the plugin's menu rows. Legacy uninstall scripts that still have menu cleanup SQL are harmless (they delete rows that are already gone).

### Validation

`PluginHelper::validate()` enforces the schema:

1. Each item must have `slug` (non-empty string, max 32 chars, `[a-z0-9-]` only) and `title` (non-empty, max 32 chars).
2. Each item must have `order` (integer).
3. `url`, if present, must be a string. No `.php` extension in URLs.
4. `permission`, if present, must be an integer 1-10.
5. `parent`, if present, must be a non-empty string (`[a-z0-9-]`).
6. An item cannot have both `items` and `parent` (it is either a parent group or a child, not both).
7. No duplicate slugs within a single plugin's `adminMenu`.
8. Nested `items` children are validated with the same rules.

Validation runs during `sync()` and `activate()`. Invalid menus log errors but do not block plugin activation -- the plugin works, it just does not get its menus.

### `PluginHelper` Changes

- `getAdminMenuItems()` visibility changed from `protected` to `public` (PluginManager needs to call it).

## What Was Migrated

### server_manager
- `plugin.json` -- added `adminMenu` with 5 items (parent group + marketplace child attachment)
- `migrations.php` -- removed sm_002 through sm_005 (menu migrations, already applied)
- `uninstall.php` -- removed menu cleanup SQL

### email_forwarding
- `plugin.json` -- added `adminMenu` with "Incoming" item attached to core "emails" parent
- `migrations.php` -- removed menu INSERT/DELETE from migration and down function
- `uninstall.php` -- removed menu cleanup SQL

## Files Changed

- `includes/PluginManager.php` -- `syncAdminMenus()`, `getMenuSlugsFromMetadata()`, `saveMenuSlugsToMetadata()`, lifecycle wiring
- `includes/PluginHelper.php` -- `getAdminMenuItems()` made public, validation updated
- `plugins/server_manager/plugin.json` -- added `adminMenu`
- `plugins/server_manager/migrations/migrations.php` -- removed menu migrations
- `plugins/server_manager/uninstall.php` -- removed menu cleanup
- `plugins/email_forwarding/plugin.json` -- added `adminMenu`
- `plugins/email_forwarding/migrations/migrations.php` -- removed menu code
- `plugins/email_forwarding/uninstall.php` -- removed menu cleanup
- `docs/plugin_developer_guide.md` -- added "Admin Menus (Declarative)" section

## Documentation Updates

Added "Admin Menus (Declarative)" section to `/docs/plugin_developer_guide.md` with:
- Schema reference and all three placement patterns
- Field reference table
- Deprecation note for migration-based menu items
