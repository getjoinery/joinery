# Plugin and Theme Developer Guide

## Licensing

The Joinery license includes a **plugin and theme exception**. Plugins and themes you create are yours — you may license them under any terms you choose, including commercial terms. The PolyForm Noncommercial license covers Joinery's core code, not your extensions. See the [Plugin and Theme Exception](../../LICENSE.md#plugin-and-theme-exception) in LICENSE.md for the full text.

## Overview

This guide outlines the current plugin and theme architecture after implementing the hybrid plugin/theme system. The system provides clear separation of concerns between plugins (backend-only) and themes (user-facing routing and presentation), while enabling themes to seamlessly integrate with plugin functionality through a sophisticated view resolution system.

## Current Architecture

### Plugin Architecture

**Plugins provide:**
- Data models and business logic
- Admin interfaces
- Database migrations
- API endpoints and webhooks
- Background processing
- User-facing views served under the plugin's URL namespace (see [Plugin URL Namespace](#plugin-url-namespace) below)

### Theme Architecture (Frontend + Routing)

**Themes handle all user-facing functionality:**
- URL routing and route definitions
- Public page templates and views
- Static assets (CSS, JS, images)
- User interface presentation
- Integration with plugin backend services
- Theme-specific class implementations (PublicPage, FormWriter extensions)
- CSS framework-specific customizations

#### Hybrid Plugin/Theme System

The system now supports a hybrid approach where:
- **Plugin views can be accessed by themes** through the view resolution fallback chain
- **Themes can override plugin views** by creating their own versions
- **Multiple fallback paths** ensure views are found even when themes don't provide them
- **Theme-specific includes** allow custom class implementations while maintaining compatibility

### Route Processing Order

> For complete routing documentation (adding pages, route options, common patterns), see **[Routing](routing.md)**.
> This section covers how routing interacts with plugins and themes.

Routes are processed in this order:
1. **Static routes** - Direct file serving with caching
2. **Theme routes** - Theme-specific routing (serve.php in theme directory)
3. **Plugin routes** - Merged from active plugin serve.php files (namespaced)
4. **Custom routes** - Complex logic routes (in main serve.php)
5. **Dynamic routes** - Standard view and model routes
6. **View fallback** - Auto-discovery: theme → plugin namespace → base → 404

#### View Resolution Chain

When a view is requested, the system searches in this order:
1. **Theme-specific view** - `/theme/{theme}/views/{view}.php`
2. **Plugin views** (if plugin specified) - `/plugins/{plugin}/views/{view}.php`
3. **Base system views** - `/views/{view}.php`
4. **404 error** if no view is found

This allows themes to override any view while providing automatic fallback to plugin or system defaults.

## Plugin Development

### Where does each piece go?

Before diving in, a quick reference for the four common things plugins need to register. Use this to jump to the right section — each row points to the one canonical path.

| What you're adding | Where it goes | Section |
|---|---|---|
| Tables and columns | `$field_specifications` in a data class under `data/` — applied automatically on install and sync | [Table Creation](#table-creation-automatic) |
| Admin menu entries | `adminMenu` key in `plugin.json` — created on activate, removed on deactivate/uninstall | [Admin Menus](#admin-menus-declarative) |
| Default plugin settings | `settings` array in `plugin.json` — seeded on activate and sync | [Plugin Settings](#plugin-settings-declarative) |
| Other initial data (seed rows, categories, etc.) | `.sql` file in `migrations/`, numbered for order, idempotent | [Migration System](#migration-system) |
| Activate/deactivate logic | `activate.php`, `deactivate.php` at the plugin root, each defining `{plugin}_activate()` / `_deactivate()` | [Plugin Lifecycle](#plugin-lifecycle) |
| Uninstall external cleanup *(optional)* | `uninstall.php` defining `{plugin}_uninstall()` — only for work the declarative systems can't do (external API calls, filesystem cleanup) | [Uninstall Script](#uninstall-script) |

If you find yourself writing SQL to INSERT menu rows, or CREATE TABLE statements in a migration, stop — you're on the wrong path. Those pieces come from the data class and `plugin.json` respectively.

### Core File Guarantees

When developing plugins, the following core files are guaranteed to be available without requiring them:

- **PathHelper** - Use for all file operations
- **Globalvars** - Access configuration and settings
- **SessionControl** - Handle session and authentication

#### Example Usage in Plugins

```php
// In any plugin file (admin, views, includes, etc.)

// ✅ CORRECT - Use directly without require
$settings = Globalvars::get_instance();
$theme = $settings->get_setting('theme_template');

$session = new Session($settings);
if (!$session->is_logged_in()) {
    // Handle not logged in
}

// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('data/users_class.php'));

// ❌ WRONG - Don't do this
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(__DIR__ . '/../../includes/Globalvars.php');
```

#### Why This Matters

1. **Cleaner Code** - No need for complex relative paths
2. **Consistency** - Same pattern everywhere
3. **Performance** - Files only loaded once
4. **Maintainability** - Easier to refactor

### Plugin Naming

Plugin directory names appear directly in user-facing URLs (`/{pluginname}/*`, `/profile/{pluginname}/*`, `/admin/{pluginname}/*`), so choose them carefully:

- **Must be distinctive** — avoid generic names like `events`, `billing`, `users`
- **Use the product or brand name** — e.g. `scrolldaddy`, `email_forwarding`
- **Short, lowercase, underscores for multi-word** — e.g. `email_forwarding` not `EmailForwarding`
- **Must not match a reserved system segment** — the following names are rejected at activation:
  `profile`, `admin`, `login`, `ajax`, `api`, `assets`, `theme`, `plugins`, `views`, `uploads`, `utils`, `tests`, `docs`, `specs`, `migrations`, `data`, `includes`, `logic`, `adm`
- **Must not clash with existing base view filenames** — if `views/profile/billing.php` exists, a plugin named `billing` is rejected

A plugin name that passes activation will own its URL namespace for the lifetime of the install. Choose something that won't conflict with other plugins or future system pages.

### Plugin URL Namespace

Every active plugin owns three URL prefixes automatically:

| URL pattern | View file | Example |
|-------------|-----------|---------|
| `/{plugin}` | `plugins/{plugin}/views/index.php` | `/scrolldaddy` |
| `/{plugin}/*` | `plugins/{plugin}/views/*.php` | `/scrolldaddy/pricing` |
| `/profile/{plugin}` | `plugins/{plugin}/views/profile/index.php` | `/profile/scrolldaddy` |
| `/profile/{plugin}/*` | `plugins/{plugin}/views/profile/*.php` | `/profile/scrolldaddy/devices` |
| `/admin/{plugin}` | `plugins/{plugin}/views/admin/index.php` | `/admin/scrolldaddy` |
| `/admin/{plugin}/*` | `plugins/{plugin}/views/admin/*.php` | `/admin/scrolldaddy/settings` |

**Auto-discovery:** Create a view file and the URL works immediately — no serve.php entry needed. The router searches: theme override → plugin directory → base directory → 404.

**Index convention:** When the URL has no trailing path (e.g. `/profile/scrolldaddy`), the router loads `index.php` from the corresponding views subdirectory.

**Internal links must always use namespaced URLs:**
```php
// ✅ CORRECT
<a href="/profile/scrolldaddy/devices">My Devices</a>

// ❌ WRONG — only works on sites where this plugin IS the theme
<a href="/profile/devices">My Devices</a>
```

**Plugin-as-theme shortcut:** When a plugin is set as the active theme (`theme_template = 'plugin'`), its views are found through theme resolution. Both `/profile/devices` (clean URL via theme) and `/profile/scrolldaddy/devices` (namespaced URL) resolve to the same file.

**Adding permissions or model binding:** Use serve.php for routes that need more than a view file — but the route pattern must be within the namespace:
```php
// plugins/myplugin/serve.php
$routes = [
    'dynamic' => [
        '/profile/myplugin/settings' => [
            'view'           => 'views/profile/settings',
            'min_permission' => 0,
        ],
    ],
];
```
Routes outside the namespace are dropped with a logged warning.

### Required Plugin Structure

```
/plugins/my-plugin/
├── plugin.json                  # Plugin metadata
├── serve.php                    # Only needed for routes requiring model/permission config
├── views/
│   ├── index.php                # /myplugin (landing page)
│   ├── pricing.php              # /myplugin/pricing
│   ├── profile/
│   │   ├── index.php            # /profile/myplugin
│   │   ├── dashboard.php        # /profile/myplugin/dashboard
│   │   └── settings.php        # /profile/myplugin/settings
│   └── admin/
│       ├── index.php            # /admin/myplugin
│       └── config.php           # /admin/myplugin/config
├── data/                        # Data model classes
├── logic/                       # Business logic (LogicResult pattern)
├── admin/                       # Admin interface files (/adm/admin_*)
├── ajax/                        # AJAX endpoints
├── includes/                    # Helper classes and libraries
├── migrations/                  # Database migrations
└── uninstall.php               # (optional) external-cleanup hook — most plugins don't need one
```

### Plugin.json Requirements

**Minimum required plugin.json:**
```json
{
    "name": "My Plugin Name",
    "version": "1.0.0",
    "description": "Plugin description"
}
```

**Complete plugin.json example:**
```json
{
    "name": "My Advanced Plugin",
    "description": "A comprehensive backend plugin",
    "version": "2.1.0",
    "author": "Your Name or Company",
    "license": "MIT",
    "homepage": "https://yoursite.com/plugin-docs",
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json", "curl"]
    },
    "depends": {
        "core-plugin": ">=1.0"
    },
    "provides": ["api-endpoint", "widget-support"],
    "tags": ["utility", "api", "backend"]
}
```

#### Deprecation Fields

Plugins (and themes) support two optional deprecation fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `deprecated` | bool | `false` | Marks the extension as deprecated |
| `superseded_by` | string | `null` | Directory name of the replacement extension |

```json
{
    "name": "Old Plugin",
    "version": "1.0.0",
    "is_stock": true,
    "deprecated": true,
    "superseded_by": "new-plugin"
}
```

**Effect of `deprecated: true`:**
- Admin list pages show a "Deprecated" badge and sort the extension to the bottom
- Activating a deprecated extension shows a warning message (activation is not blocked)
- Deprecated extensions are excluded from deployment archives for new installs
- Existing sites already running a deprecated extension continue to receive updates normally

### Data Models

Plugins provide data models using the SystemBase pattern:

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_id';

    public static $field_specifications = [
        'mdt_id' => ['required' => true, 'type' => 'int'],
        'mdt_name' => ['required' => true, 'type' => 'varchar', 'length' => 255],
        'mdt_description' => ['type' => 'text'],
        'mdt_created' => ['type' => 'timestamp', 'default' => 'now()']
    ];

    // Define foreign key behavior (optional - defaults to cascade)
    protected static $foreign_key_actions = [
        'mdt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
    ];
}
```

**Deletion Behavior**: For complete documentation on defining foreign key actions, cascading deletes, soft-delete cascading patterns, and undelete strategies, see the [Deletion System Documentation](deletion_system.md).

### Business Logic Files

Plugin logic files follow the same LogicResult pattern as core logic files. For comprehensive documentation on logic file architecture and best practices, see the [Logic Architecture Guide](CLAUDE_logic_architecture.md).

```php
// plugins/my-plugin/logic/my_feature_logic.php
<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function my_feature_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/my-plugin/data/my_data_class.php'));

    // Business logic processing
    $data = new MyData($get_vars['id'], TRUE);

    // Use LogicResult for consistent returns
    if ($post_vars['action'] === 'delete') {
        $data->soft_delete();
        return LogicResult::redirect('/plugins/my-plugin/admin/list');
    }

    return LogicResult::render(['data' => $data]);
}
?>
```

Key points for plugin logic files:
- Always use `LogicResult::render()`, `LogicResult::redirect()`, or `LogicResult::error()`
- Follow the naming convention: `[feature]_logic.php` with matching function name
- Include paths are relative to the plugin directory when using `__DIR__`
- Can be called from views, admin pages, or the router

### Admin Interface

Plugin admin pages are accessed via the plugin admin discovery route:
`/plugins/{plugin}/admin/{page}`

```php
// plugins/my-plugin/admin/admin_my_plugin.php
<?php
// Core files are already available - no need to require them
// PathHelper, Globalvars, and SessionControl are pre-loaded

// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$page->admin_header([
    'title' => 'My Plugin',
    'menu-id' => 'my-plugin',
    'readable_title' => 'My Plugin Management'
]);

// Admin interface content here

$page->admin_footer();
?>
```

### Plugin Menus (Declarative)

Plugins declare menu contributions in `plugin.json` under two keys:

- `adminMenu` — items in the admin sidebar (`/admin/*`).
- `profileMenu` — items in the user dropdown shown by themes (logged-in avatar menu, logged-out auth links, etc.).

Both keys are synced into the same `amu_admin_menus` table, distinguished by an `amu_location` column (`admin_sidebar` vs `user_dropdown`). The system automatically creates menu rows on activation, updates them on sync, and removes them on deactivation/uninstall. This is the only supported way to register plugin menus — do not INSERT into `amu_admin_menus` from migrations.

**Locations:**

| Location        | Source key    | Permission floor | Visibility |
|-----------------|---------------|------------------|------------|
| `admin_sidebar` | `adminMenu`   | ≥ 1              | always `in` (logged in) |
| `user_dropdown` | `profileMenu` | ≥ 0              | `in` / `out` / `both` |

**Slug rules (both locations):**

- Must match `[a-z0-9-]`, max 32 chars, unique within the plugin.
- Must start with `<plugin-name>-` (e.g. `mybooks-shelf`). For `adminMenu`, this is recommended; for `profileMenu`, it is required by validation.
- Must not start with `core-` — that prefix is reserved for core menu rows seeded by migrations.

#### `adminMenu`

**Three placement patterns:**

**1. Parent group with children** -- creates a top-level menu section:

```json
{
  "adminMenu": [
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
  ]
}
```

Children inherit the parent's `permission` unless they override it.

**2. Child attachment** -- attaches to any existing menu by slug:

```json
{
  "adminMenu": [
    {
      "slug": "incoming",
      "title": "Incoming",
      "url": "/plugins/email_forwarding/admin/admin_email_forwarding",
      "parent": "emails",
      "permission": 5,
      "order": 10,
      "settingActivate": "email_forwarding_enabled"
    }
  ]
}
```

The `parent` value is the `amu_slug` of any menu in the system -- core menus, other plugin menus, or groups from the same plugin.

**3. Standalone top-level** -- a single entry with no children or parent:

```json
{
  "adminMenu": [
    { "slug": "my-tool", "title": "My Tool", "url": "/admin/my_tool", "icon": "wrench", "permission": 10, "order": 16 }
  ]
}
```

**Available fields:**

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `slug` | Yes | -- | Unique identifier (`[a-z0-9-]`, max 32 chars) |
| `title` | Yes | -- | Display text (max 32 chars) |
| `order` | Yes | -- | Sort position within parent level |
| `url` | No | `""` | Target page. URLs starting with `/` are stored as-is |
| `icon` | No | null | Icon identifier |
| `permission` | No | 10 | Min permission level (1-10) |
| `settingActivate` | No | null | Setting that must be truthy for menu to display |
| `disabled` | No | false | Whether disabled by default |
| `parent` | No | null | Slug of parent menu to attach under |
| `items` | No | null | Array of child menu items |

**Important:** Menus declared in `plugin.json` are the source of truth. Manual edits via the admin menu UI will be overwritten on the next sync.

#### `profileMenu`

Profile menu items appear in the user dropdown. They are flat — no parent/items nesting — and support a per-row `visibility` value that selects between logged-in, logged-out, and both states.

```json
{
  "profileMenu": [
    {
      "slug": "scrolldaddy-filtering",
      "title": "Filtering",
      "url": "/profile/scrolldaddy",
      "icon": "shield",
      "visibility": "in",
      "permission": 1,
      "order": 75
    }
  ]
}
```

**Available fields:**

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `slug` | Yes | -- | Unique identifier (`[a-z0-9-]`, max 32). Must start with `<plugin-name>-`. |
| `title` | Yes | -- | Display text (max 32 chars). |
| `url` | Yes | -- | Target page (no `.php`). Stored as-is. |
| `order` | Yes | -- | Sort position in the dropdown. Core slots: home=10, profile=50, signout=200. |
| `icon` | No | null | Icon identifier passed through to theme renderers. |
| `visibility` | No | `"in"` | One of `"in"` (logged-in), `"out"` (logged-out), `"both"`. |
| `permission` | No | 0 | Min permission level (0-10). Only applies when logged in. |
| `settingActivate` | No | null | Setting that must be truthy for the row to display. |
| `disabled` | No | false | Whether disabled by default. |

`parent` and `items` are not supported on `profileMenu` — the user dropdown is rendered as a flat list. Themes that need additional grouping handle it at the render layer.

**Themes consuming the dropdown:** themes read `$menu_data['user_menu']['items']` returned by `PublicPageBase::get_menu_data()`. Each item carries `label`, `link`, `icon`, and `slug`. Filter by `slug` (e.g. `str_starts_with($item['slug'], 'core-admin-')`) — never by `label`, since admins can rename labels in the admin UI.

### Plugin Settings (Declarative)

> ⚠️ **Settings are a two-step setup.** Declaring in `plugin.json` only seeds the row in `stg_settings` — it does **not** make the setting appear in the admin UI. To expose a setting on `/admin/admin_settings`, you must also create a `settings_form.php` file in your plugin directory (see [Plugin Settings Form](#plugin-settings-form) below). Setting names in the two files must match exactly.

Plugin default settings are declared in `plugin.json` under an optional `settings` key. On activate and on every sync, PluginManager seeds any declared row that doesn't already exist in `stg_settings`. Existing values are never overwritten.

```json
{
  "name": "My Plugin",
  "version": "1.0.0",
  "settings": [
    { "name": "myplugin_enabled", "default": "1" },
    { "name": "myplugin_max_items", "default": "50" },
    { "name": "myplugin_api_key", "default": "" }
  ]
}
```

**Fields:**

| Field | Required | Default | Description |
|---|---|---|---|
| `name` | Yes | — | Setting key. Must start with the plugin's directory name. |
| `default` | No | `""` | String value stored in `stg_value`. Always a string — use `"0"`/`"1"` for booleans, `"42"` for numbers. JSON-native booleans/numbers are rejected at validation time. |

**Validation rules** (enforced on activate and sync):
1. Every declared `name` must start with the plugin's directory name (e.g., a plugin at `/plugins/bookings/` must declare settings named `bookings_*`).
2. No declared `name` may collide with a core setting in `settings.json` at the `public_html/` root.

Validation failures throw. On `activate()` the plugin does not activate; on `sync()` the offending plugin is skipped with a logged error and other plugins continue.

**Seed-only policy:** Existing setting values are never overwritten. If your plugin's v2 changes a declared default, existing sites keep their old value and only new installs get the new default. If you need existing sites to pick up a new default, write an SQL migration — silent default changes across upgrades have bitten production systems badly enough that the operator needs to opt in.

**Orphan rows:** Settings dropped from the manifest in a later version are **not** automatically deleted. Use an SQL migration if you need the row gone. Orphan setting rows are otherwise harmless — nothing reads them.

**Blank defaults:** `default: ""` creates a row with an empty value. Use this for things that have no meaningful factory default but should still be present (API keys, SMTP hosts, custom CSS) so the row exists for `settings_form.php` to render and for admins to fill in. Omitting the declaration entirely means no row in `stg_settings`, even if `settings_form.php` references the name — the form-page save logic auto-creates missing rows on first submit, but until then `get_setting()` returns `null` and the field renders empty.

**Uninstall:** On uninstall, PluginManager deletes rows matching the names in the current manifest. Settings declared in an earlier version but dropped from the current manifest are left in place.

### Plugin Lifecycle

**PluginManager is the single entry point for all lifecycle operations.** Plugin models (`Plugin`, `PluginHelper`) are pure CRUD — never call lifecycle methods directly on them.

Three states: `active`, `inactive`, and *uninstalled* (no row at all).

```
Discovery → Install → Activate ↔ Deactivate → Uninstall
              ↑                                    │
              └────────────── Install ─────────────┘
```

**Install** (`PluginManager::install($name)`)
1. Fetches a fresh archive from the upgrade endpoint and extracts over `plugins/{name}/`, so stock plugins get current code on every install. Custom plugins 404 silently and install proceeds with on-disk files.
2. Validates plugin structure and dependencies
3. Creates/updates database tables from data class `$field_specifications` (via `DatabaseUpdater::runPluginTablesOnly()`)
4. Runs pending `.sql` migration files in `plugins/{name}/migrations/`
5. Records the plugin in `plg_plugins` with status `inactive`

**Activate** (`PluginManager::activate($name)`)
1. Re-validates dependencies
2. Runs `DatabaseUpdater::runPluginTablesOnly()` — picks up any `$field_specifications` changes since install
3. Runs `activate.php` hook (calls `{plugin_name}_activate()` if defined)
4. Registers deletion rules via PluginHelper
5. Resumes any suspended scheduled tasks for this plugin
6. Sets `plg_active = 1`

**Developer workflow for schema changes** — If you add columns to `$field_specifications` on an already-installed plugin: modify the class, then run **Sync with Filesystem** from the admin Plugins page (`/admin/admin_plugins?action=sync_filesystem`). Sync calls `runPluginTablesOnly()` for all active plugins, which picks up new columns and creates new tables. Schema changes are also applied automatically during deploys (`upgrade.php`) and when running `update_database` from admin utilities.

**Schema changes on inactive plugins are deferred.** Sync and `update_database` only touch tables for active plugins. If you modify `$field_specifications` on a plugin that is installed but not active, the schema change will not be applied until the plugin is next activated (`PluginManager::activate()` calls `runPluginTablesOnly()` as its first step).

**Sync** (`PluginManager::sync()`)
1. Scans filesystem — discovers new plugins, updates metadata from manifests, detects missing directories
2. Updates database tables for **all active plugins** via `DatabaseUpdater::runPluginTablesOnly()`
3. Runs pending migrations for all active plugins
4. Re-registers deletion rules for all active plugins via `PluginHelper::registerAllActiveDeletionRules()`

Sync is the recommended way to apply schema changes after code deploys. It is also available as an admin UI action on the Plugins page and the Themes page.

**Deactivate** (`PluginManager::deactivate($name)`)
1. Runs `deactivate.php` hook
2. Removes deletion rules for this plugin
3. Suspends active scheduled tasks (`sct_is_active = false`) — tasks resume on reactivation
4. Sets `plg_active = 0`

**Uninstall** (`PluginManager::uninstall($name)`) — **destructive, cannot be undone.** Plugin files stay on disk; everything else is removed.

1. Deletes declared settings (from current `plugin.json`). Settings dropped from a later manifest version are left as orphans.
2. Deletes declared admin menus
3. Removes deletion rules
4. Deletes scheduled task records
5. Deletes version, dependency, and migration records
6. Runs `uninstall.php` hook if present. Tables are still available here for external teardown (e.g., revoking cached external state).
7. Drops plugin tables and orphan sequences
8. Deletes the `plg_plugins` row

**Hook failure is fatal.** If step 6 throws or returns false, steps 7 and 8 do NOT run — tables and the row remain intact. Steps 1–5 are idempotent, so the operator fixes the hook and re-runs uninstall. Use this to guard external work: if you can't revoke an API key, don't let the plugin's local state be destroyed.

**After uninstall,** the plugin appears in the admin UI as "Inactive" with an **Install** action (no DB row, files still on disk). Reinstall goes through the normal install path — on install the upgrade-endpoint refresh pulls fresh stock code, so stale on-disk files don't linger.

**Important:** The core `update_database.php` script excludes plugins from its main pipeline (`include_plugins => false`) because plugin tables have independent lifecycles. However, `update_database` runs a plugin/theme sync as its final step, so plugin schema changes are still applied when you run it.

### Table Creation (Automatic)

Plugin tables are created automatically from data class `$field_specifications` — you do NOT write CREATE TABLE statements. Simply define your data model classes in `plugins/{name}/data/` and tables will be created when the plugin is installed.

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_my_data_id';

    public static $field_specifications = array(
        'mdt_my_data_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'mdt_name' => array('type'=>'varchar(255)', 'required'=>true),
        'mdt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'mdt_delete_time' => array('type'=>'timestamp(6)'),
    );
}
```

**Choosing a prefix:** Your plugin's table prefix (e.g. `abc` in `abc_items`) must be unique across all plugins installed on a site. Use a short abbreviation of your plugin name — at least 3 characters. The system will block installation if your class names or table names collide with an installed plugin, and will warn if your prefix matches even when table names don't.

### Migration System

For default plugin settings, use the `settings` key in `plugin.json` (see [Plugin Settings](#plugin-settings-declarative) above). Migrations are for **initial data seeds only** — dropdown options, category rows, reference data — that doesn't fit the settings model. Schema is handled automatically from `$field_specifications` (see [Table Creation](#table-creation-automatic) above), and admin menus are declared in `plugin.json` (see [Admin Menus](#admin-menus-declarative) above) — none of those belong in a migration.

Migrations are `.sql` files placed in `plugins/{name}/migrations/`:

```sql
-- plugins/my-plugin/migrations/001_seed_categories.sql
INSERT INTO mpc_my_plugin_categories (mpc_name)
SELECT 'Default Category'
WHERE NOT EXISTS (SELECT 1 FROM mpc_my_plugin_categories WHERE mpc_name = 'Default Category');
```

Rules:
- Name files with a numeric prefix for ordering (e.g. `001_seed_categories.sql`, `002_seed_defaults.sql`).
- Files run in filename order during plugin installation.
- Execution is tracked in `plm_plugin_migrations`; each file runs exactly once per site.
- Write idempotent SQL (`WHERE NOT EXISTS`, `ON CONFLICT DO NOTHING`) so a file that partially applied can be safely re-run after the tracking row is cleared.

### Plugin Settings Form

Settings declared in `plugin.json`'s `settings` array (see [Plugin Settings](#plugin-settings-declarative) above) are seeded into the database on plugin activate. The `settings_form.php` file renders them in the admin settings page. The names used in both must match exactly — the manifest handles seeding, the form file handles UI.

If your plugin has configurable settings, create a `settings_form.php` file in your plugin directory. The admin settings page (`/adm/admin_settings`) **automatically discovers and includes** this file — no registration required.

```
plugins/my-plugin/settings_form.php
```

The file is included inside an already-open FormWriter form, so you output fields directly using `$formwriter`. The variables `$formwriter`, `$settings`, and `$session` are all available in scope.

```php
<?php
// plugins/my-plugin/settings_form.php
// $formwriter, $settings, and $session are already available

echo '<p>Configure My Plugin settings below.</p>';

$formwriter->textinput('my_plugin_api_url', 'API URL', [
    'value' => $settings->get_setting('my_plugin_api_url'),
    'placeholder' => 'e.g. https://api.example.com',
]);

$formwriter->passwordinput('my_plugin_api_key', 'API Key', [
    'value' => $settings->get_setting('my_plugin_api_key'),
    'placeholder' => 'Your API key',
]);

$formwriter->checkboxinput('my_plugin_enabled', 'Enable My Plugin', [
    'value' => $settings->get_setting('my_plugin_enabled'),
]);
```

**Rules:**
- All setting names **must be prefixed** with your plugin name (e.g. `my_plugin_`) to avoid collisions with core settings or other plugins.
- Use `$settings->get_setting('name')` to read current values — this handles missing rows gracefully.
- Use `passwordinput` for secrets (API keys, tokens) so the value is masked in the browser.
- The form submit is handled by the settings page — your fields are saved automatically alongside all other settings.
- Declare the setting in `plugin.json`'s `settings` array so it exists on fresh installs (see [Plugin Settings](#plugin-settings-declarative) above).

### Uninstall Script

`uninstall.php` is **optional**. Most plugins don't need one — the system automatically drops tables, deletes declared settings and menus, removes scheduled task / version / dependency / migration records, and deletes the `plg_plugins` row.

Create `uninstall.php` only when you need external cleanup the system can't do:
- Revoking an API key or token with a third-party service
- Removing uploaded files or cached assets outside the database
- Writing a final archival record to a log table before teardown
- Notifying a paired service (resolver, remote node) to drop cached state

**Contract:**
- Function name: `{plugin_name}_uninstall()` — must match the plugin directory name.
- Runs **after** settings/menus/scaffolding are deleted but **before** plugin tables are dropped, so you can still query your own tables.
- Return `true` on success. Return `false` or throw to signal failure.
- **Failure is fatal**: tables and the `plg_plugins` row are preserved, leaving the plugin in a recoverable state. Fix the hook and re-run uninstall — the scaffolding cleanup steps are idempotent.

```php
// plugins/my-plugin/uninstall.php
function my_plugin_uninstall() {
    try {
        // Example: revoke an API key with an external service.
        // Tables are still available here if you need to read credentials
        // or enumerate records that reference external resources.
        $api_key = Globalvars::get_instance()->get_setting('my_plugin_api_key');
        if ($api_key) {
            external_api_revoke_key($api_key);
        }
        return true;
    } catch (Exception $e) {
        error_log("My Plugin uninstall failed: " . $e->getMessage());
        return false; // preserves tables + row so operator can fix and retry
    }
}
```

**Do not** include `DROP TABLE`, `DELETE FROM stg_settings`, or `DELETE FROM amu_admin_menus` in the hook — those are the system's job now. A hook that duplicates them isn't harmful (drops are `IF EXISTS`, deletes match exact keys the system already cleared), but the extra code rots.

## Provider Abstractions

The system has two pluggable provider abstractions for external services. Each follows the same shape: an interface, a service manager that auto-discovers concrete classes, and one provider class per third-party service. Adding a new provider is a single-file change — drop a class into the providers directory and the rest of the system picks it up.

### Email providers (`EmailServiceProvider`)

- Interface: `includes/EmailServiceProvider.php`
- Manager: `EmailSender` (`includes/EmailSender.php`) — `EmailSender::getAvailableServices()`, `EmailSender::validateService()`
- Implementations: `includes/email_providers/*Provider.php` (Mailgun, SMTP, …)

### Mailing list providers (`MailingListProvider`)

- Interface: `includes/mailing_list_providers/MailingListProvider.php`
- Abstract base: `includes/mailing_list_providers/AbstractMailingListProvider.php` — concrete providers extend this rather than implementing the interface directly
- Typed exception: `includes/mailing_list_providers/MailingListProviderException.php` — `isRetryable()` distinguishes transient (rate limit, 5xx, network) from permanent (list missing, credentials revoked) failures
- Manager: `MailingListService` (`includes/MailingListService.php`) — `MailingListService::getProvider()`, `getAvailableServices()`, `getProviderSettings($key)`
- Implementations: `includes/mailing_list_providers/*Provider.php` (MailChimp, …)

**Required methods on the interface:**
| Method | Purpose |
|---|---|
| `getKey()` / `getLabel()` | Identity for the `mailing_list_provider` setting and admin dropdown |
| `getSettingsFields()` | Setting field definitions rendered dynamically by the admin UI |
| `validateConfiguration()` | Cheap, no-network check that required settings are non-empty |
| `validateApiConnection()` | Live API ping for the admin "Connection OK?" panel |
| `subscribe()` / `unsubscribe()` | Idempotent operations on a remote list. Email is normalized to lowercase; throw `MailingListProviderException` on provider-side failures, `\InvalidArgumentException` on bad input |
| `getSubscribers()` | Opaque-cursor pagination — caller passes `null` first, then echoes back `next_cursor` until it is `null`. Returns the canonical four-value `status` enum (`subscribed`, `unsubscribed`, `bounced`, `pending`) |

**Non-universal methods** (e.g. `getLists()`) live on `AbstractMailingListProvider` with a default body that throws `\BadMethodCallException`. Providers override them when their API supports the operation; consumers wrap calls in `try/catch \BadMethodCallException`. Future non-universal additions (sequences, broadcasts, list stats) follow the same pattern, keeping additions non-breaking for existing provider classes.

**Adding a new provider:**

1. Create `includes/mailing_list_providers/MyServiceProvider.php`:
   ```php
   require_once(PathHelper::getComposerAutoloadPath());
   require_once(PathHelper::getIncludePath('includes/mailing_list_providers/AbstractMailingListProvider.php'));

   class MyServiceProvider extends AbstractMailingListProvider {
       public static function getKey(): string { return 'myservice'; }
       public static function getLabel(): string { return 'My Service'; }
       // … implement the remaining required methods
   }
   ```
2. Add any provider-specific settings to `settings.json` (factory defaults seed automatically).
3. Pick the provider in admin settings (`/admin/admin_settings_email` → Mailing List Provider section) — the dropdown auto-populates from your new class.

No other files need to change. The model layer (`MailingList::sync_subscribe()` / `sync_unsubscribe()`) and the sync utility (`utils/mailing_list_synchronize.php`) call the configured provider through `MailingListService::getProvider()`.

**Canonical subscriber status enum.** `getSubscribers()` returns one of four `status` values regardless of provider. Each provider class maps its native vocabulary into this set:

| Canonical | Meaning | MailChimp | ConvertKit | Listmonk |
|---|---|---|---|---|
| `subscribed` | Actively receives mail | `subscribed` | `active` | `enabled` |
| `unsubscribed` | Opted out (incl. spam-complained) | `unsubscribed` | `cancelled`, `inactive`, `complained` | `disabled` |
| `bounced` | Email invalid; provider stopped sending | `cleaned` | `bounced` | `blocklisted` |
| `pending` | Double opt-in not yet confirmed | `pending` | (n/a) | (n/a) |

`complained` (spam-marked) collapses into `unsubscribed` — for the platform's purposes the action taken on the local row is the same. Mapping is typically ~5 lines of `switch` per provider.

**Out of scope (deliberate deferrals).** Three categories of methods are intentionally NOT on the interface today; they will be added when a concrete second provider needs them:

- **Webhooks.** Real-time event notifications (`registerWebhook`, `verifyWebhookSignature`) are not part of the contract. When added they go on the required interface — every modern provider supports them.
- **OAuth flows.** Some providers (HubSpot, Klaviyo) use OAuth2 instead of API keys. The current `getSettingsFields()` shape can't express an OAuth flow. When a provider needing OAuth is added, that provider class implements an additional method (e.g. `getOAuthAuthorizationUrl()`) outside the formal interface; the admin UI checks for its presence via `method_exists`.
- **Programmatic list creation.** `createList()` is not on the interface. Current workflow: admins create lists in the provider's UI and enter the ID locally. Add programmatically only when a concrete use case appears.

Non-universal future methods (sequences, broadcasts, list stats) get default throwing bodies on `AbstractMailingListProvider` so additions stay non-breaking for existing provider classes.

## Theme Development

### Theme Structure with Plugin Integration

Themes can range from simple presentation layers to complex integrations with multiple plugins:

**Basic Theme Structure:**
```
/theme/my-theme/
├── theme.json                  # Theme metadata and configuration
├── serve.php                   # Theme routing (optional)
├── views/                      # Theme templates and view overrides
│   ├── index.php
│   ├── page.php
│   └── plugin_overrides/       # Plugin view overrides
├── assets/                     # Theme assets
│   ├── css/
│   ├── js/
│   └── images/
└── includes/                   # Theme-specific classes
    ├── PublicPage.php          # Theme-specific PublicPage implementation
    └── FormWriter.php          # Theme-specific FormWriter (optional)
```

**Advanced Theme with Plugin Integration:**
```
/theme/advanced-theme/
├── theme.json
├── serve.php                   # Includes plugin routes
├── views/
│   ├── index.php
│   ├── items/                  # Plugin view overrides
│   │   ├── list.php
│   │   └── detail.php
│   └── profile/                # Plugin view overrides
│       └── dashboard.php
├── assets/
└── includes/
    ├── PublicPage.php          # Bootstrap/UIKit/WordPress-specific implementation
    └── ThemeHelper.php         # Theme-specific utilities
```

### Theme Routing (serve.php)

Themes can define their own routes in RouteHelper format, including integration with plugin functionality:

**Basic Theme Routing:**
```php
// theme/my-theme/serve.php
$routes = [
    'dynamic' => [
        // Simple view routes (uses view resolution chain)
        '/my-page' => ['view' => 'views/my_page'],
        '/about' => ['view' => 'views/about'],
        
        // Model-based routes using plugin data
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class'
        ],
    ],
    
    'custom' => [
        // Complex routing logic
        '/custom-handler' => function($params, $settings, $session, $template_directory) {
            // Custom logic here
            require_once(PathHelper::getThemeFilePath('custom.php', 'views'));
            return true;
        },
    ],
];
```

**Plugin serve.php (namespaced routes only):**
```php
// plugins/controld/serve.php
$routes = [
    'dynamic' => [
        // Routes must be within the plugin's namespace
        '/profile/controld/device_edit' => [
            'view'           => 'views/profile/device_edit',
            'min_permission' => 0,
        ],
        '/controld/create_account' => [
            'view' => 'views/create_account',
        ],
    ],
];
```

Note: The plugin name is extracted automatically from the URL pattern — no `plugin_specify` field is needed or supported.

### Plugin Integration in Themes

Themes integrate with plugin backend services through data models and the view resolution system:

**Using Plugin Data Models:**
```php
// theme/my-theme/views/items.php
<?php
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));

// Use plugin data models
$items = new MultiItem(['itm_active' => 1], ['itm_name' => 'ASC']);
$items->load();

foreach ($items as $item) {
    echo '<h3>' . $item->get('itm_name') . '</h3>';
    echo '<p>' . $item->get('itm_description') . '</p>';
}
?>
```

**View Override Pattern:**
```php
// theme/my-theme/views/items/list.php - Overrides plugin view
<?php
// This theme view will be used instead of plugins/items/views/items/list.php
// But can still access plugin data models and helpers
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));
require_once(PathHelper::getIncludePath('plugins/items/includes/ItemsHelper.php'));

$items = ItemsHelper::getActiveItems();
foreach ($items as $item) {
    // Theme-specific presentation
    include 'item_card_template.php';
}
?>
```

**Theme-Specific Class Integration:**
```php
// theme/bootstrap-theme/includes/PublicPage.php
class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped table-hover',
            'header' => 'thead-dark'
        ];
    }
    
    // Bootstrap-specific implementations
    public function renderAlert($message, $type = 'info') {
        return "<div class='alert alert-{$type}' role='alert'>{$message}</div>";
    }
}
```

**Profile/Member Area:**

Profile pages (`/profile/*`) and `/notifications` use the active theme's `PublicPage` directly — no separate `MemberPage` wrapper. Profile views call `$page->public_header()` / `$page->public_footer()` like any other public view and render their content inside a `.jy-ui` scope using the jy-ui kit components (`.jy-panel`, `.jy-page-header`, `.jy-breadcrumbs`, `.card`, etc.). In-page navigation between profile sub-pages is handled by the existing user dropdown in the theme header and, where relevant, a per-page `PublicPage::tab_menu()` tab bar.

### Asset Management

Theme assets are served through the theme asset route with automatic caching:
`/theme/{theme}/assets/*`

**Basic Asset Usage:**
```php
// In theme templates
<link rel="stylesheet" href="/theme/<?= $template_directory ?>/assets/css/style.css">
<script src="/theme/<?= $template_directory ?>/assets/js/app.js"></script>
<img src="/theme/<?= $template_directory ?>/assets/images/logo.png" alt="Logo">
```

**Base Assets:**

`PublicPageBase` loads fallback CSS/JS (`base.css`, `joinery-styles.css`, `base.js`) via the `render_base_assets()` method, called from `global_includes_top()`. Themes that provide their own complete CSS (like `PublicPageJoinerySystem`) override `render_base_assets()` with an empty body to prevent style conflicts. See [Theme Integration Instructions](theme_integration_instructions.md) for details.

**Using ThemeHelper for Assets:**
```php
// Enhanced asset management
<?php
$theme = ThemeHelper::getInstance();
?>
<link rel="stylesheet" href="<?= $theme->asset('css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= $theme->asset('css/theme.css') ?>">
<script src="<?= $theme->asset('js/theme.js') ?>"></script>
```

**Theme Configuration:**
```php
// Using theme.json configuration in templates
<?php
$theme_config = ThemeHelper::config('cssFramework', 'bootstrap');
if ($theme_config === 'bootstrap') {
    echo '<div class="container">';
} elseif ($theme_config === 'uikit') {
    echo '<div class="uk-container">';
}
?>
```

### Theme Metadata (theme.json)

All themes should include a `theme.json` file for proper system integration:

**Basic theme.json:**
```json
{
  "name": "my-theme",
  "displayName": "My Custom Theme",
  "version": "1.0.0",
  "description": "A custom theme for my site",
  "author": "Your Name",
  "is_stock": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterV2Bootstrap",
  "publicPageBase": "PublicPageBase"
}
```

**Tailwind theme.json:**
```json
{
  "name": "advanced-theme",
  "displayName": "Advanced Plugin-Integrated Theme",
  "version": "2.1.0",
  "description": "Theme with full plugin integration",
  "author": "Developer Team",
  "is_stock": false,
  "requires": {
    "php": ">=8.0",
    "joinery": ">=1.0.0"
  },
  "supports_plugins": ["controld", "items"],
  "cssFramework": "tailwind",
  "formWriterBase": "FormWriterV2Tailwind",
  "publicPageBase": "PublicPageBase",
  "features": {
    "responsive": true,
    "dark_mode": true,
    "plugin_integration": true
  }
}
```

**HTML5 framework-agnostic theme.json:**
```json
{
  "name": "custom-theme",
  "displayName": "Custom HTML5 Theme",
  "version": "1.0.0",
  "description": "Framework-agnostic theme with custom styling",
  "author": "Developer",
  "is_stock": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "html5",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

**Theme with plugin dependencies (requires_plugins):**
```json
{
  "name": "scrolldaddy-theme",
  "displayName": "ScrollDaddy Theme",
  "version": "1.0.0",
  "requires_plugins": ["scrolldaddy"],
  "cssFramework": "html5",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

The `requires_plugins` field declares plugins that must be active for the theme to work correctly. When present:
- **Theme activation is blocked** if any listed plugin is not active (with a clear error message directing the admin to activate the plugin first).
- **Plugin deactivation is blocked** if the active theme lists that plugin in `requires_plugins` (with an error directing the admin to switch themes first).

Use this when the theme directly uses plugin-provided classes, helpers, or pages — for example, a theme that renders a widget from a specific plugin's helper class, or whose navigation links to plugin-namespaced URLs.

Themes also support the `deprecated` and `superseded_by` fields described in the [plugin.json Deprecation Fields](#deprecation-fields) section above. The behavior is identical for themes and plugins.

## ThemeHelper Enhanced Capabilities

### Theme Management Methods

**Get Active Theme:**
```php
$current_theme = ThemeHelper::getActive();
```

**Get Theme Configuration:**
```php
$css_framework = ThemeHelper::config('cssFramework', 'bootstrap', 'theme-name');
$supports_plugins = ThemeHelper::config('supports_plugins', [], 'theme-name');
```

## Migration from Old Architecture

### For Existing Plugins

1. **Remove user-facing routes** from plugin serve.php files
2. **Keep admin interfaces** and backend functionality  
3. **Ensure plugin.json exists** with proper versioning
4. **Convert migrations** to new format if needed
5. **Add uninstall script** for clean removal
6. **Update view paths** to work with the new resolution system

### For Themes Using Plugin Features

1. **Move plugin routes to theme serve.php** using RouteHelper format
2. **Update view templates** to use plugin data models directly
3. **Ensure assets are in theme/assets/** not plugin directories  
4. **Test plugin admin access** via `/plugins/{plugin}/admin/*`
5. **Create theme.json** with proper metadata and plugin support
6. **Implement theme-specific classes** (PublicPage, FormWriter) if needed
7. **Test view resolution chain** to ensure fallbacks work correctly

### Working with Forms in Views

#### Getting FormWriter Instances

In views with PublicPage available (most frontend views):
```php
// Preferred method in views - uses PublicPage wrapper
$formwriter = $page->getFormWriter('form1');
```

In different contexts:
```php
// Admin pages - use the page object
$formwriter = $page->getFormWriter('form1'); // $page is AdminPage instance

// Utilities and logic files - direct instantiation
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
$formwriter = new FormWriter('form1');
```

The `$page->getFormWriter()` method automatically:
- Detects the correct FormWriter class for the theme's CSS framework
- Loads theme-specific FormWriter implementations if available
- Falls back to system defaults appropriately
- Handles all the complexity internally

#### FormWriter Framework Mapping
- **Bootstrap themes**: Uses `FormWriterV2Bootstrap`
- **Tailwind themes**: Uses `FormWriterV2Tailwind`
- **HTML5 themes**: Uses `FormWriterV2HTML5` (framework-agnostic)
- **Custom themes**: Can extend `FormWriterV2Base` for custom implementations

### Example: ControlD Plugin Migration

**Before (Plugin served routes):**
```php
// plugins/controld/serve.php (REMOVED)
$routes = [
    '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
    '/create_account' => ['view' => 'views/create_account'],
];
```

**After (Theme serves routes):**
```php
// theme/sassa/serve.php (CURRENT)
$routes = [
    'dynamic' => [
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/pricing' => ['view' => 'views/pricing'],
    ],
];
```

**Plugin now only provides:**
- Admin interface: `/plugins/controld/admin/*`
- Data models: `CtldAccount`, `CtldDevice`, etc.
- Business logic: `ControlDHelper` class and logic files

## Hybrid Architecture

### Separation of Concerns
- **Plugins**: Backend logic, data, admin interfaces
- **Themes**: User interface, routing, presentation
- **Hybrid Integration**: Themes can access plugin functionality without coupling

### View Resolution
- **View Resolution Chain**: Automatic fallback from theme → plugin → system views
- **Framework Support**: Multiple CSS frameworks with proper implementations
- **Plugin Integration**: Themes can include plugin routes without breaking separation
- **Override Capability**: Themes can override any plugin view while maintaining fallbacks

### Security Model
- Plugin code not directly accessible via web URLs
- Admin interfaces protected by plugin admin discovery route
- Clear separation between public and admin functionality
- Theme-specific includes isolated from system includes

### Performance
- Static asset caching through RouteHelper
- Reduced routing complexity with priority-based processing
- Plugin code only loaded when needed
- View resolution caching prevents repeated file system checks
- Framework-specific optimizations in theme implementations

## File Loading in Plugins and Themes

**Two methods for including files:**

1. **`PathHelper::getIncludePath()`** - Direct loading, no overrides
   ```php
   require_once(PathHelper::getIncludePath('data/user_class.php'));  // Data models
   require_once(PathHelper::getIncludePath('includes/MyHelper.php')); // System files
   ```

2. **`PathHelper::getThemeFilePath()`** - Theme-aware file resolution with override chain
   ```php
   // Files that can be overridden by themes
   require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile'));

   // With explicit plugin context (5th parameter)
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile', 'system', null, 'controld'));

   // Parameters: filename, subdirectory, path_format, theme_name, plugin_name
   ```
   **Override chain:** theme → plugin → base

**When to use:**
- `PathHelper::getIncludePath()`: Direct file access for system files, data models, plugin files
- `PathHelper::getIncludePath()`: Direct file access, no theme overrides needed (plugins, data files)
- `PathHelper::getThemeFilePath()`: Files that themes/plugins can override (views, logic, includes)

### File Override System

**Important:** The file override system uses `PathHelper::getThemeFilePath()` which checks:
1. Theme override: `/theme/{theme}/{subdirectory}/{filename}`
2. Plugin version: `/plugins/{plugin}/{subdirectory}/{filename}`
3. Base fallback: `/{subdirectory}/{filename}`

Always use the two-parameter format:
- First parameter: filename only (e.g., 'profile.php')
- Second parameter: subdirectory path (e.g., 'views', 'logic', 'views/profile')

## Development Workflow

### Creating a New Plugin

1. Create plugin directory under `/plugins/{name}/` with `plugin.json`
2. Create data model classes in `plugins/{name}/data/` with `$field_specifications` (tables created automatically on install)
3. Declare admin menus in `plugin.json` under the `adminMenu` key (see [Admin Menus](#admin-menus-declarative))
4. Declare default settings in `plugin.json` under the `settings` key (see [Plugin Settings](#plugin-settings-declarative))
5. Create `.sql` migration files in `plugins/{name}/migrations/` only if you have other initial data seeds (dropdowns, categories, reference rows)
6. Create admin interface in `plugins/{name}/admin/` if needed
7. *(Optional)* Create `uninstall.php` only if you have external cleanup to perform (API calls, filesystem, remote-service notifications) — the system handles tables, settings, menus, and scaffolding automatically. See [Uninstall Script](#uninstall-script).
8. **Install** the plugin via Admin > System > Plugins (creates tables, runs SQL migrations)
9. **Activate** the plugin to make it live (seeds declared settings)
10. Test admin functionality via `/plugins/{plugin}/admin/*`
11. No user-facing routes - these go in themes

### Creating a New Theme

1. **Create theme directory structure** with theme.json manifest
2. **Choose CSS framework** and implement corresponding PublicPage class
3. **Add serve.php** only if custom routing or plugin integration needed
4. **Create view templates** using plugin data models and ThemeHelper methods
5. **Add theme assets** (CSS, JS, images) in proper directory structure
6. **Test view resolution chain** to ensure plugin view fallbacks work
7. **Validate theme.json accuracy** against actual implementations
8. **Test integration** with existing plugins using the hybrid system

### Integrating Plugin and Theme

1. **Plugin provides backend services** and data models through SystemBase classes
2. **Theme creates user-facing routes** that use plugin models via serve.php
3. **Theme templates use plugin data** through proper model loading and ThemeHelper
4. **View resolution chain** allows themes to override plugin views while maintaining fallbacks  
5. **Plugin admin remains separate** from theme routing via `/plugins/{plugin}/admin/*`
6. **Theme.json documents integration** with supported plugins and framework choices
7. **CSS framework consistency** maintained between plugin data and theme presentation

## Debugging and Troubleshooting

### Route Debugging

Enable route debugging with URL parameter:
```
http://example.com/any-page?debug_routes=1
```

This shows detailed routing information in HTML comments.

### Common Issues

**404 on plugin admin pages:**
- Check plugin directory name matches URL
- Verify admin file exists in `plugins/{plugin}/admin/`
- Check file permissions

**Theme not finding plugin data:**
- Ensure plugin data class is properly included using PathHelper
- Verify plugin is installed and tables exist
- Check data model usage syntax and constructor parameters

**Views not resolving correctly:**
- Check view path format in routes (should not start with `/`)
- Test view resolution chain: theme → plugin namespace → base
- For auto-discovered views, confirm URL matches `/profile/{pluginname}/...` pattern and file exists at `plugins/{pluginname}/views/profile/....php`
- For explicit routes, confirm the route pattern is within the plugin namespace

**CSS framework conflicts:**
- Verify theme.json cssFramework matches actual implementation
- Check PublicPage class extends proper base and implements getTableClasses()
- Ensure FormWriter implementation (V2Bootstrap, V2Tailwind, or V2HTML5) matches CSS framework
- Validate CSS classes match framework documentation

**Assets not loading:**
- Verify asset paths use correct theme directory
- Check file exists in `theme/{theme}/assets/`
- Ensure web server can serve static files
- Test ThemeHelper::asset() method for enhanced asset management

**Class not found errors:**
- Distinguish between theme includes (direct) vs views (resolution chain)
- Use proper require_once(PathHelper::getIncludePath()) for includes
- Check abstract method implementation in theme-specific classes
- Verify class file naming conventions match theme requirements

## Cookie Consent Integration

If your plugin adds analytics or marketing scripts to public pages, you should wrap them for GDPR/CCPA consent compliance.

**Using ConsentHelper to wrap scripts:**
```php
require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
$consent = ConsentHelper::get_instance();
echo $consent->wrapTrackingCode('<script>...your tracking code...</script>', 'analytics');
```

**Or manually add the consent attribute to script tags:**
```html
<script type="text/plain" data-joinery-consent="analytics">
  // This script only runs after user consents to analytics
</script>
```

**Consent categories:**
- `analytics` - For analytics and tracking scripts (e.g., Google Analytics)
- `marketing` - For advertising and remarketing scripts (e.g., Facebook Pixel)

When cookie consent is enabled, scripts marked with `data-joinery-consent` remain inactive until the user grants consent for that category.

## CSS Framework Integration

### Supported CSS Frameworks

The system supports multiple CSS frameworks through theme-specific implementations:

**Bootstrap Themes:**
- CSS Framework: `bootstrap`
- FormWriter Base: `FormWriterV2Bootstrap`
- Table Classes: `table`, `table-striped`, `table-hover`
- Container Classes: `container`, `container-fluid`

**Tailwind CSS Themes:**
- CSS Framework: `tailwind`
- FormWriter Base: `FormWriterV2Tailwind`
- Utility-first approach with custom classes
- Table Classes: Custom Tailwind utility classes
- Container Classes: `container`, `mx-auto`

**HTML5 Themes (Framework-Agnostic):**
- CSS Framework: `html5` or `custom`
- FormWriter Base: `FormWriterV2HTML5`
- Pure semantic HTML5 markup
- No framework-specific classes
- Themes can apply any CSS styling

### Framework-Specific Implementations

**PublicPage Class Implementations:**

```php
// Bootstrap theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-responsive',
        'table' => 'table table-striped table-hover',
        'header' => 'thead-dark'
    ];
}

// UIKit theme  
protected function getTableClasses() {
    return [
        'wrapper' => 'uk-overflow-auto',
        'table' => 'uk-table uk-table-striped', 
        'header' => 'uk-table-header'
    ];
}

// WordPress theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-wrapper',
        'table' => 'wp-list-table widefat fixed striped',
        'header' => 'thead'
    ];
}
```

## Current Plugin Status

### Active Plugins

**ControlD (Backend-only)**
- Location: `/plugins/controld/`
- Admin: `/plugins/controld/admin/*`
- Data models: Account, Device, Filter, etc.
- User routes: Moved to sassa theme

**Items (Backend-only)**  
- Location: `/plugins/items/`
- Admin: `/plugins/items/admin/*`
- Data models: Item, ItemRelation, etc.
- User routes: Moved to sassa theme

### Theme Integration Examples

**Sassa Theme (Plugin-enabled, Bootstrap)**
- CSS Framework: `bootstrap`
- Includes ControlD routes: `/profile/*`, `/pricing`
- Includes Items routes: `/items`, `/item/{slug}`
- File: `/theme/sassa/serve.php`
- Custom PublicPage with Bootstrap table classes

**Jeremy Tunnell Theme (WordPress CSS)**
- CSS Framework: `wordpress`
- PublicPage with WordPress-specific table classes
- FormWriter using default base
- Theme.json accurately reflects implementation

**Zouk Room Theme (UIKit)**
- CSS Framework: `uikit` 
- PublicPage with UIKit table classes
- Theme.json specifies UIKit framework
- Custom styling for UIKit components

**Other Themes (Various Frameworks)**
- Falcon (Bootstrap), Tailwind (Tailwind CSS), Default (minimal)
- Each with framework-appropriate implementations
- Clean separation of concerns maintained

## Best Practices Summary

### For Plugin Developers
1. **Backend-only focus** - No user-facing routes or views
2. **Proper data models** using SystemBase patterns
3. **Admin interfaces** accessible via `/plugins/{name}/admin/*`
4. **Clean uninstall** scripts for data cleanup
5. **Version management** through plugin.json

### For Theme Developers
1. **Framework consistency** - Match CSS framework to implementations
2. **Accurate manifests** - theme.json should reflect actual code
3. **View resolution** - Leverage the fallback chain effectively
4. **Plugin integration** - Use data models, not direct plugin coupling
5. **Asset management** - Proper theme asset organization
6. **Abstract methods** - Implement required PublicPageBase methods
7. **Base class render methods** - Call `$this->render_notification_icon($menu_data)` in `top_right_menu()` for notifications; override only if theme needs different markup

### For System Integration
1. **Clear separation** - Plugins (backend) vs Themes (frontend)
2. **Flexible routing** - Theme serve.php can include plugin routes
3. **View fallbacks** - Automatic resolution chain prevents 404s
4. **Framework support** - Multiple CSS frameworks supported cleanly
5. **Maintainability** - Updates to plugins don't break theme functionality

This hybrid architecture provides maximum flexibility while maintaining clean separation of concerns and ensuring backward compatibility across all existing themes and plugins.

## Plugin Theme System

### Overview

The plugin theme system allows plugins to act as complete theme providers, replacing the entire user interface while maintaining all plugin functionality. This enables white-label solutions, complete UI replacements, and branded experiences.

### How the System Works

1. **PathHelper** intercepts theme file requests and redirects to plugin directory for PHP classes
2. **RouteHelper** sets template directory to plugin path for view loading
3. **ThemeHelper** serves assets from plugin directory instead of theme directory
4. **Admin Settings** provides UI for selecting which plugin provides the theme

### Three Types of Plugins

#### 1. Feature Plugins (Standard)
**Purpose**: Add specific functionality without affecting the UI
**Examples**: Bookings, Items, OAuth providers, Payment processors
**Characteristics**:
- Work within existing theme framework
- Add new routes under `/[plugin-name]/*`
- Can provide admin interfaces
- Cannot override system views or routes

**Directory Structure**:
```
/plugins/bookings/
├── plugin.json
├── serve.php
├── admin/
│   └── manage_bookings.php
├── views/
│   └── booking_list.php
└── assets/
    └── js/bookings.js
```

#### 2. Theme Provider Plugins
**Purpose**: Complete UI replacement when selected as active theme
**Examples**: ControlD, White-label solutions, Custom branded interfaces

**Required Files**:
```
/plugins/controld/
├── plugin.json (with "provides_theme": true)
├── serve.php
├── includes/
│   ├── PublicPage.php (required - base page class)
│   └── FormWriter.php (required - form generation)
├── views/
│   ├── index.php (homepage view)
│   ├── profile.php (user profile)
│   └── [other system view overrides]
└── assets/
    ├── css/style.css
    ├── js/main.js
    └── img/logo.png
```

**How Theme Provider Mode Works**:
1. Admin selects "plugin" as the theme
2. Admin selects specific plugin (e.g., "controld") as the theme provider
3. System modifications activate:
   - PathHelper loads PHP classes from `/plugins/controld/includes/`
   - RouteHelper loads views from `/plugins/controld/views/`
   - ThemeHelper loads assets from `/plugins/controld/assets/`
4. Plugin provides complete UI while system handles core functionality

#### 3. Hybrid Plugins
**Purpose**: Dual-mode plugins that can work as features OR complete themes
**Examples**: Complex applications with optional standalone mode

**Behavior Modes**:
- **Feature Mode**: When regular theme active, provides features within that theme
- **Theme Mode**: When selected as theme provider, replaces entire UI
- Same codebase, different activation modes

## System Configuration Documentation

### New Database Settings

**`active_theme_plugin`**
- **Type**: String (plugin directory name)
- **Default**: Empty string
- **Purpose**: Specifies which plugin provides the complete UI when plugin theme is active
- **Valid Values**: Must match an installed plugin directory name
- **Dependencies**: Only used when `theme_template = 'plugin'`
- **Example**: `'controld'` to use ControlD plugin as theme

### Modified Settings

**`theme_template`**
- **New Option**: `'plugin'` - Delegates all theme functionality to a plugin
- **Existing Options**: `'falcon'`, `'sassa'`, `'tailwind'`, etc.

## Admin Interface Documentation

### Settings Page Updates (`/adm/admin_settings.php`)

**Theme Selection Enhancement**:
When "Plugin-Provided Theme" is selected from the theme dropdown:
1. A new dropdown appears labeled "Active Theme Plugin"
2. Dropdown populates with all installed plugins
3. Plugins with `"provides_theme": true` are prioritized
4. Help text explains the plugin must provide theme infrastructure

**JavaScript Behavior**:
- Plugin selector is hidden when regular themes are selected
- Plugin selector shows immediately when "plugin" theme is selected
- Settings save normally through existing form processing

## Technical Implementation Notes

### File Resolution Order

When plugin theme is active, the system checks for files in this order:

**For PHP Classes** (via PathHelper):
1. `/plugins/{active_plugin}/includes/{file}`
2. `/theme/plugin/includes/{file}` (fallback)
3. `/includes/{file}` (system fallback)

**For Views** (via RouteHelper/ThemeHelper):
1. `/plugins/{active_plugin}/views/{file}`
2. `/views/{file}` (system fallback)

**For Assets** (via ThemeHelper):
1. `/plugins/{active_plugin}/assets/{file}`
2. `/theme/plugin/assets/{file}` (shouldn't exist)
3. Current route's plugin assets (existing behavior)

### Performance Considerations

- **Additional Database Queries**: One extra query to get `active_theme_plugin` setting
- **File Existence Checks**: Additional `is_dir()` and `file_exists()` checks
- **Caching Opportunity**: Could cache plugin theme selection in session
- **Impact**: Minimal - only adds conditional checks when plugin theme active

### Security Considerations

- **Plugin Validation**: System should verify plugin exists before activation
- **Fallback Strategy**: Falls back to safe defaults if plugin missing
- **No New Attack Vectors**: Uses existing file inclusion mechanisms
- **Admin Only**: Theme selection requires admin permissions