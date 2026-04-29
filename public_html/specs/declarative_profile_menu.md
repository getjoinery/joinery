# Declarative Profile Menu (Shared Menu Infrastructure)

## Problem

The user/profile menu (the items in `$menu_data['user_menu']['items']`, rendered by themes as the avatar dropdown / mobile nav) is a hardcoded PHP array inside `PublicPageBase::get_menu_data()` (`includes/PublicPageBase.php:181-309`). Every entry — Home, My Profile, Orders, Subscriptions, My Events, Event Sessions, Sign out, and the admin items spliced in by permission — is literal code. Plugins that add profile-area pages (e.g. `bookings`, `scrolldaddy`'s `/profile/scrolldaddy/*`) have no way to register a menu entry.

Joinery already has working declarative menu infrastructure for the admin sidebar: an `adminMenu` key in `plugin.json` synced into `amu_admin_menus` (see `specs/implemented/declarative_plugin_admin_menus.md`). Rather than build a parallel system for profile menus — separate table, separate model, separate admin pages, separate sync method — this spec **extends the existing one** with a `location` column so the same table, sync, validator, and admin UI handle both menu locations.

## Goals

1. Profile menu items live in the database, not in PHP.
2. Plugins declare profile menu items in `plugin.json` — no migrations, no `PublicPageBase` edits.
3. Activate syncs items in; deactivate removes them.
4. Logged-in / logged-out / permission-gated / setting-gated visibility is expressible declaratively.
5. **Reuse the existing menu table, sync, validator, and admin UI.** No parallel infrastructure.
6. Every column on the shared table means the same thing for every row — no row-type-conditional asymmetry.
7. `get_menu_data()` continues to return the same `$menu_data['user_menu']['items']` shape — themes do not change.

## Non-Goals

- The per-page in-profile tab navigation (`PublicPage::tab_menu()` calls inside `views/profile/*.php`) is **not** in scope. Page-local tabs, not the global user menu.
- The avatar / display name / login link / register link sub-keys of `user_menu` (`is_logged_in`, `display_name`, `avatar_url`, etc.) stay computed in PHP. Only the `items` array becomes declarative.
- Cart, notifications, and `mobile_menu` keys of `$menu_data` are unchanged.
- Public site nav (`pmu_public_menus`) is out of scope.
- Renaming `amu_admin_menus` to a more neutral name like `amu_menus` is out of scope. Worth doing eventually but unnecessary now and risks breaking external consumers.

## Design

### Schema Extension

Two new columns on `amu_admin_menus`:

| Column           | Type         | Default          | Meaning                                                |
|------------------|--------------|------------------|--------------------------------------------------------|
| `amu_location`   | varchar(32)  | `'admin_sidebar'`| Where the row renders. Discriminator. Required.        |
| `amu_visibility` | varchar(8)   | `'in'`           | When the row appears: `'in'` / `'out'` / `'both'`.     |

That is the entire schema delta. Every other column the profile menu needs (`amu_slug`, `amu_menudisplay`, `amu_defaultpage`, `amu_icon`, `amu_order`, `amu_min_permission`, `amu_setting_activate`, `amu_disable`) already exists on the table and means exactly what the profile menu needs it to mean.

#### Existing Row Backfill

A migration sets `amu_location='admin_sidebar'` and `amu_visibility='in'` on every existing row. The defaults handle this automatically for new inserts; the migration backfills the historical rows in place.

### Locations

Defined values, drawn from a `MultiAdminMenu::LOCATIONS` constant:

| Location         | Renderer                                              | Permission floor | Allowed visibility |
|------------------|-------------------------------------------------------|------------------|--------------------|
| `admin_sidebar`  | `MultiAdminMenu::getadminmenu()` — admin sidebar      | ≥ 5              | `'in'` only        |
| `user_dropdown`  | `MultiAdminMenu::get_user_dropdown_items()` — profile | ≥ 0              | `'in'` / `'out'` / `'both'` |

Adding a future menu location (e.g. `footer`, `main_nav`) is "new constant value + new renderer." No schema change.

### Why visibility is meaningful for both locations

Visibility describes when a row appears given the session login state. For `admin_sidebar` rows the value is *constrained* to `'in'` — not because the column is ignored, but because admin pages require permission ≥ 5 which requires login. The validator enforces that constraint (`if amu_location='admin_sidebar' then amu_visibility='in' and amu_min_permission >= 5`), making the column actively meaningful for admin rows rather than silently defaulted dead weight.

Visibility cannot be derived from `amu_min_permission` alone — there is no "max permission" concept, so "show only when logged out" (`'out'`) needs its own representation. The three-value enum is the minimal expression.

### Visibility Semantics

`amu_visibility` controls inclusion based on session login state:

| Value  | Show when logged in | Show when logged out |
|--------|---------------------|----------------------|
| `in`   | yes                 | no                   |
| `out`  | no                  | yes                  |
| `both` | yes                 | yes                  |

`amu_min_permission` is a further gate applied **only when logged in** — logged-out callers always have effective permission 0 and the visibility filter alone decides their menu.

`amu_setting_activate`, when present, must resolve to a truthy setting via `Globalvars::get_setting($name, false, true)` or the row is hidden.

### Seeding the Core Profile Rows

The current hardcoded list (in `PublicPageBase::get_menu_data()`) is migrated to seed rows with `amu_location='user_dropdown'`. This runs as a standard data-only migration entry in `migrations/migrations.php` (idempotent; tested via `SELECT count(1) WHERE amu_slug = 'core-...'` before insert). The table itself already exists; we are only adding rows.

| Slug                         | Display           | Default page                  | Visibility | Min Perm | Setting Activate     | Order |
|------------------------------|-------------------|-------------------------------|------------|----------|----------------------|-------|
| `core-home`                  | Home              | `/`                           | both       | 0        | —                    | 10    |
| `core-signin`                | Sign in           | `/login`                      | out        | 0        | —                    | 20    |
| `core-forgot-password`       | Forgot Password   | `/password-reset-1`           | out        | 0        | —                    | 30    |
| `core-signup`                | Sign up           | `/register`                   | out        | 0        | `register_active`    | 40    |
| `core-profile`               | My Profile        | `/profile`                    | in         | 1        | —                    | 50    |
| `core-orders`                | Orders            | `/profile#orders`             | in         | 1        | —                    | 60    |
| `core-subscriptions`         | Subscriptions     | `/profile/subscriptions`      | in         | 1        | —                    | 70    |
| `core-events`                | My Events         | `/profile#events`             | in         | 1        | —                    | 80    |
| `core-event-sessions`        | Event Sessions    | `/profile/event_sessions`     | in         | 1        | —                    | 90    |
| `core-admin-dashboard`       | Admin Dashboard   | `/admin/admin_users`          | in         | 5        | —                    | 100   |
| `core-admin-help`            | Admin Help        | `/admin/admin_help`           | in         | 5        | —                    | 110   |
| `core-admin-settings`        | Admin Settings    | `/admin/admin_settings`       | in         | 6        | —                    | 120   |
| `core-admin-utilities`       | Admin Utilities   | `/admin/admin_utilities`      | in         | 6        | —                    | 130   |
| `core-signout`               | Sign out          | `/logout`                     | in         | 1        | —                    | 200   |

Notes:
- The current code uses `permission >= 5` for Dashboard/Help and `permission > 5` for Settings/Utilities. With `>=` filtering on `amu_min_permission`, Settings/Utilities must be `6`.
- Order values are spaced (10, 20, …) so plugins can slot between them without renumbering.

### plugin.json Schema

Plugins declare profile menu contributions under a new `profileMenu` key, alongside the existing `adminMenu`:

```json
{
  "name": "scrolldaddy",
  "adminMenu": [ ... ],
  "profileMenu": [
    {
      "slug": "scrolldaddy-overview",
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

Sync reads both keys and tags rows with `amu_location='admin_sidebar'` or `amu_location='user_dropdown'` based on which key they came from. The plugin author never specifies location directly — it is implied by the manifest key.

#### Field Reference (profileMenu)

| Field             | Type   | Required | Default | DB Column              | Notes |
|-------------------|--------|----------|---------|------------------------|-------|
| `slug`            | string | yes      | —       | `amu_slug`             | Unique, `[a-z0-9-]`, max 32. Sync key. **Must start with `<plugin-name>-`.** |
| `title`           | string | yes      | —       | `amu_menudisplay`      | Max 32. |
| `url`             | string | yes      | —       | `amu_defaultpage`      | No `.php` extension. Stored as-is. |
| `icon`            | string | no       | null    | `amu_icon`             | |
| `visibility`      | enum   | no       | `in`    | `amu_visibility`       | `in` / `out` / `both`. |
| `permission`      | int    | no       | 0       | `amu_min_permission`   | 0–10. |
| `order`           | int    | yes      | —       | `amu_order`            | |
| `settingActivate` | string | no       | null    | `amu_setting_activate` | |
| `disabled`        | bool   | no       | false   | `amu_disable`          | |

The `parent` / `items` nesting fields supported by `adminMenu` are **not** offered for `profileMenu`. The user dropdown is rendered as a flat list and there is no current renderer for nested profile menus. If nesting is needed later, the column already exists (`amu_parent_menu_id`) and the field can be added without a schema change.

### URL Storage

Drop the URL auto-prefix from sync. Today `syncAdminMenus()` prepends `/admin/` to URLs that don't start with `/`. Looking at every plugin in tree, every declared URL already starts with `/`, so the auto-prefix is dead code in practice. Removing it makes URL handling consistent across locations: store what the plugin author wrote.

### Sync Algorithm

`syncAdminMenus()` is renamed `syncMenus()` (the old name remains as a thin wrapper for any external callers) and extended to read both `adminMenu` and `profileMenu` from the plugin manifest.

**Signature:**

```php
public function syncMenus(string $plugin_name, ?array $declared = null): void
```

`$declared` is an associative array `['admin' => [...], 'profile' => [...]]` containing the items for each location. When `null`, both keys are read from `plugin.json`. To deactivate or uninstall, pass `['admin' => [], 'profile' => []]` (or omit either key — missing keys are treated as empty arrays). The two existing keys (`admin`, `profile`) correspond directly to the two location values.

For each declared item:

1. **Read** both keys from `plugin.json` via `PluginHelper::getAdminMenuItems()` and `PluginHelper::getProfileMenuItems()` (new public getter), or use the explicit `$declared` shape.
2. **Tag with location**: items from `$declared['admin']` get `amu_location='admin_sidebar'`, items from `$declared['profile']` get `amu_location='user_dropdown'`. Plugin authors do not specify location.
3. **Flatten** the admin items' parent + child structure. Profile items are flat — no nesting.
4. **Upsert** by `amu_slug`. Resolve `parent` slug → id for admin rows; warn-and-skip on missing parent.
5. **Prune**: diff declared slugs against the previous slug set in `plg_metadata._menu_slugs`. The slug set covers both locations — no need to split, since slugs are globally unique by validator rule (every plugin slug starts with the plugin's name, and core slugs are reserved). A single prune pass cleans up both menu types.
6. **Cache** the new declared slug set.

The metadata key (`_menu_slugs`) is unchanged from the existing admin-menu sync. Existing entries continue to round-trip correctly.

### Admin Sidebar Renderer Update

`MultiAdminMenu::getadminmenu()` currently selects every row with `amu_min_permission <= :currpermission AND amu_disable = 0`. After core profile rows are seeded into the same table, this query would pull `user_dropdown` rows into the admin sidebar — e.g. "My Profile" (`amu_min_permission=1`) would appear in the sidebar for every logged-in user.

The signature gains an optional `$location` parameter (default `'admin_sidebar'` for backward compat) and the query gains a location filter:

```php
static function getadminmenu($user_permission, $current_menu_slug, $get_all = false, $location = 'admin_sidebar')
```

```sql
WHERE amu_min_permission <= :currpermission
  AND amu_disable = 0
  AND amu_location = :location
ORDER BY amu_order ASC
```

#### Caller audit

A grep turned up five callers, three of which want sidebar-only behavior and get it by default:

| Caller | Line | Wants | Action |
|--------|------|-------|--------|
| `includes/AdminPage.php` | 44 | admin sidebar | no change — default `'admin_sidebar'` is correct |
| `includes/AdminPage-uikit3.php` | 269 | admin sidebar | no change |
| `includes/AdminPage-uikit3.php` | 408 | admin sidebar | no change |
| `adm/admin_admin_menu.php` | 21 | every row, both locations | passes `$location` based on the active tab — admin sidebar tab calls with `'admin_sidebar'`, user dropdown tab calls with `'user_dropdown'` |

The admin admin-menu page (the only caller that needs to see profile rows) is being rebuilt for tabs anyway, so passing the new parameter is part of that rebuild rather than a separate concern.

### Lifecycle Integration

Unchanged from the existing admin-menu lifecycle — the same calls now handle both menu locations:

| Hook            | Call                                                          |
|-----------------|---------------------------------------------------------------|
| `onActivate`    | `syncMenus($name)` — reads both keys from `plugin.json`       |
| `onDeactivate`  | `syncMenus($name, ['admin' => [], 'profile' => []])`           |
| `sync()`        | per-active-plugin call in the existing loop, no second arg    |
| `uninstall()`   | `syncMenus($name, ['admin' => [], 'profile' => []])` before plugin hook |

### Validation

`PluginHelper::validate()` gains a `profileMenu` block alongside the existing `adminMenu` block. Per-location rules are applied based on which key the item came from:

**Common to both locations:**
1. `slug` non-empty, `[a-z0-9-]`, max 32, unique within the plugin's combined menu sets.
2. **Slug must start with `<plugin-name>-`** (mirrors the settings rule). Cross-plugin collisions impossible.
3. Slug must not start with `core-` (core-protection at validation time).
4. `title` non-empty, max 32.
5. `url` required, must be a string, no `.php`.
6. `order` required integer.

**`adminMenu`-specific (location='admin_sidebar'):**
7. `permission` if present, integer 1–10. Default 10.
8. Visibility is implicit `'in'`; the JSON does not accept a `visibility` field for admin items.

**`profileMenu`-specific (location='user_dropdown'):**
9. `permission` if present, integer 0–10. Default 0.
10. `visibility` if present, must be `'in'` / `'out'` / `'both'`. Default `'in'`.
11. No `parent` or `items` fields (flat only).

Invalid menus log errors but do not block activation (matches existing admin behavior).

### `get_menu_data()` Rewrite

`PublicPageBase::get_menu_data()` (`includes/PublicPageBase.php:122`) replaces the hardcoded blocks at lines 198-241 (logged-in items) and 284-308 (logged-out items) with a single query and filter pass:

```php
$is_logged_in = $session->is_logged_in();
$user_permission = $is_logged_in ? $session->get_permission() : 0;

try {
    $rows = MultiAdminMenu::get_user_dropdown_items($is_logged_in, $user_permission);
    // Already filtered by visibility, permission, setting_activate, disable, location.
    // Ordered by amu_order ASC, amu_slug ASC (stable tie-break).

    $menu_data['user_menu']['items'] = array_map(function ($row) {
        return [
            'label' => $row->get('amu_menudisplay'),
            'link'  => $row->get('amu_defaultpage'),
            'icon'  => $row->get('amu_icon'),
            'slug'  => $row->get('amu_slug'),
        ];
    }, iterator_to_array($rows));
} catch (PDOException $e) {
    // Columns missing during initial deploy / before update_database has run.
    $menu_data['user_menu']['items'] = [];
}
```

`MultiAdminMenu::get_user_dropdown_items($is_logged_in, $user_permission)` is a new static method — it filters by `amu_location='user_dropdown'`, applies the visibility / permission / setting / disable rules, and orders by `amu_order ASC, amu_slug ASC` (the secondary slug sort is a deterministic tie-break for duplicate order values).

The view shape adds one **additive** field: each item carries its `slug`. Themes that read only `label` / `link` / `icon` are unaffected.

### Theme Label-Filter Audit

A grep of `user_menu['items']` consumers turned up **five** sites that filter by literal label, not one. All five must convert to slug-based filtering or they break the moment any admin renames a labelled item.

No theme overrides `get_menu_data()` itself — every theme/page class calls the inherited base method, so the data-layer rewrite reaches them all without further work. The filter conversions are pure render-layer changes.

The five sites and their conversions:

| File | Line | Current filter | New filter |
|------|------|----------------|------------|
| `includes/PublicPage.php` (canvas base, user dropdown) | 66 | exclude `['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help']` | exclude slugs starting with `core-admin-` |
| `includes/PublicPageFalcon.php` (admin launcher equivalent) | 211 | include `['Home', 'My Profile', 'Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help']` | include `['core-home', 'core-profile']` + slugs starting with `core-admin-` |
| `includes/PublicPageFalcon.php` (user dropdown) | 255 | exclude `['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help']` | exclude slugs starting with `core-admin-` |
| `includes/PublicPageJoinerySystem.php` (9-dots launcher) | 471 | include `['Home', 'My Profile', 'Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help']` | include `['core-home', 'core-profile']` + slugs starting with `core-admin-` |
| `includes/PublicPageJoinerySystem.php` (user dropdown) | 510 | exclude `['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help']` | exclude slugs starting with `core-admin-` |

The "include admin items" filter (used by the launcher views in Falcon and JoinerySystem) collapses to a clear rule: `slug === 'core-home' || slug === 'core-profile' || str_starts_with($slug, 'core-admin-')`. The "exclude admin items" filter is the inverse: `!str_starts_with($slug, 'core-admin-')`.

A small helper avoids hand-rolling the same predicate four times — add to `PublicPageBase`:

```php
protected static function isAdminLauncherItem(array $item): bool {
    $slug = $item['slug'] ?? '';
    return $slug === 'core-home'
        || $slug === 'core-profile'
        || str_starts_with($slug, 'core-admin-');
}

protected static function isAdminMenuItem(array $item): bool {
    return str_starts_with($item['slug'] ?? '', 'core-admin-');
}
```

Each call site becomes one line: `if (!self::isAdminLauncherItem($item)) continue;` or `if (self::isAdminMenuItem($item)) continue;`.

### Admin UI

The existing `/admin/admin_admin_menu` page grows two tabs:

- **Admin Sidebar** (default, current view) — rows where `amu_location='admin_sidebar'`.
- **User Dropdown** — rows where `amu_location='user_dropdown'`.

Tabs rather than a filter because the field sets differ between locations (visibility shown only for `user_dropdown`, permission floor differs, ordering numbers don't conflict across locations) — a single mixed list would obscure those rules.

Edit form (`/admin/admin_admin_menu_edit`) treats location as a select on the **create** form (admin picks where the new row lives) and as **read-only** on subsequent edits — once a row exists, its location is fixed. Location migrations (moving a row between sidebar and dropdown) would be a separate operation and aren't supported in v1; the workaround is delete-and-recreate.

The `amu_visibility` field is shown only when location is `user_dropdown`; the field is hidden (and forced to `'in'`) for admin sidebar rows.

The list view distinguishes three ownership classes by slug pattern:

| Slug pattern         | Owner                              | Editable?                                          |
|----------------------|------------------------------------|----------------------------------------------------|
| `core-*`             | Core (seeded by migration)         | Reorder / rename / disable allowed. **Delete refused** by the UI — keeps the "admin removes Sign out" footgun shut. |
| `<plugin-name>-*`    | Plugin (synced from `plugin.json`) | All field values are overwritten on next sync. List view shows a "managed by *plugin-name*" badge and a warning tooltip on the edit form. |
| anything else        | Admin-created                      | Never touched by sync. |

No new admin page is created. The existing one grows tabs and a few field-conditional behaviors.

## Implementation Plan

Three phases, each independently shippable.

### Phase 1 — Schema + Core

After this phase, profile menu data lives in the DB but the user-visible behavior is identical.

1. Add `amu_location` and `amu_visibility` to `AdminMenu::$field_specifications` in `data/admin_menus_class.php`. `update_database` adds the columns.
2. Add a data migration to backfill existing rows (`amu_location='admin_sidebar'`, `amu_visibility='in'`) — required because `update_database` doesn't backfill default values into pre-existing rows.
3. **Add the location filter to `MultiAdminMenu::getadminmenu()`** so the admin sidebar query selects only `amu_location='admin_sidebar'`. Without this step, seeded `user_dropdown` rows would leak into the sidebar in step 5.
4. Add a data migration to seed the core `user_dropdown` rows from the table above. Idempotent (`SELECT count(1) WHERE amu_slug = 'core-...'` test).
5. Add `MultiAdminMenu::get_user_dropdown_items($is_logged_in, $user_permission)` — filters by location, visibility, permission, setting, disable. Orders by `amu_order, amu_slug`.
6. Update `PublicPageBase::get_menu_data()` to read from the DB inside a try/catch (degrade to empty items on missing columns); delete the hardcoded arrays.
7. Add `PublicPageBase::isAdminLauncherItem()` / `isAdminMenuItem()` helpers and convert all five label-filter sites to use them (see "Theme Label-Filter Audit"). Sites: `PublicPage.php:66`, `PublicPageFalcon.php:211` and `:255`, `PublicPageJoinerySystem.php:471` and `:510`.
8. Add tabs to `/admin/admin_admin_menu` (Admin Sidebar / User Dropdown), conditional `amu_visibility` field on the edit form, and the core-delete-refused rule from the "Core Protection" section. The page passes the active tab's location to `getadminmenu()` and to the location-aware list query.
9. **Verify permission gates match current behavior**: log in as permission 5 → see Dashboard + Help, no Settings/Utilities. Log in as permission 6 → see all four. The current code uses `>=5` and `>5`; the table uses `>=` so Settings/Utilities must be `amu_min_permission=6`.
10. **Verify admin sidebar isolation**: confirm that after the seed migration, `/admin/*` pages show only the existing admin sidebar items — no profile rows leaking into the sidebar.

### Phase 2 — Plugin extensibility

After this phase, plugins can declare `profileMenu` in `plugin.json`.

9. Add `PluginHelper::getProfileMenuItems()` (public).
10. Add the `profileMenu` validation block in `PluginHelper::validate()` — common rules + per-location rules, including the `<plugin-name>-` prefix check and `core-` rejection.
11. Extend `syncAdminMenus()` (rename to `syncMenus()` if desired; old name kept as alias) to read both manifest keys and tag rows with location. Drop the URL auto-prefix.
12. Verify deactivate / uninstall correctly prune profile rows the plugin owned.
13. Plugin "managed by" badge in admin UI list view.

### Phase 3 — Migrate in-tree plugins

14. `plugins/bookings/plugin.json` — add `profileMenu` for the bookings profile entry (`/profile/bookings`).
15. `plugins/scrolldaddy/plugin.json` — add `profileMenu` for the ScrollDaddy profile entry (`/profile/scrolldaddy`).
16. Verify on `https://joinerytest.site` that toggling each plugin active/inactive adds and removes its row, and that the user dropdown reflects the change without a deploy.

## Files to Add / Change

**Add:** none. The shared infrastructure removes the need for a separate model class and admin pages.

**Change:**
- `data/admin_menus_class.php` — add `amu_location` and `amu_visibility` to `$field_specifications`, add the location filter to `MultiAdminMenu::getadminmenu()`, add `MultiAdminMenu::get_user_dropdown_items()`.
- `includes/PublicPageBase.php` — `get_menu_data()` reads from DB, hardcoded lists deleted.
- `includes/PublicPage.php` — slug-based filter on user dropdown (line 66).
- `includes/PublicPageFalcon.php` — slug-based filters on admin launcher (line 211) and user dropdown (line 255).
- `includes/PublicPageJoinerySystem.php` — slug-based filters on 9-dots launcher (line 471) and user dropdown (line 510).
- `includes/PublicPageBase.php` — `isAdminLauncherItem()` and `isAdminMenuItem()` helpers (in addition to the `get_menu_data()` rewrite already listed).
- `includes/PluginManager.php` — extend `syncAdminMenus()` to read both manifest keys; drop URL auto-prefix.
- `includes/PluginHelper.php` — `getProfileMenuItems()` public getter, `profileMenu` validation block.
- `adm/admin_admin_menu.php` — location tabs / filter, ownership badging, refuse delete on `core-*`.
- `adm/admin_admin_menu_edit.php` — location selector, conditional visibility field.
- `migrations/migrations.php` — backfill existing rows, seed core `user_dropdown` rows.
- `plugins/bookings/plugin.json` — add `profileMenu`.
- `plugins/scrolldaddy/plugin.json` — add `profileMenu`.
- `docs/plugin_developer_guide.md` — extend the existing "Admin Menus (Declarative)" section to cover both locations and the `profileMenu` key.

## Core Protection

Plugins cannot remove, disable, or modify core rows. Specifically:

- Slugs beginning with `core-` are reserved. `syncMenus()` skips (with a logged warning) any declared item whose slug starts with `core-`.
- `PluginHelper::validate()` rejects `core-`-prefixed slugs at validation time so plugin authors get an error during development rather than a silent skip in production.
- The prune step only removes rows whose slugs are tracked in `plg_metadata._menu_slugs` for that plugin. Core rows are never in that set.
- The admin UI refuses to delete `core-*` rows. Admins can disable / rename / reorder them; deletion is explicitly blocked. This is the only protection needed — no `ensure_core_rows()` self-healing pass is required because the deletion path is already closed.

## Known Limitations

- **Sync overwrites all fields.** If an admin renames a plugin row's label via the admin UI, the next plugin sync overwrites the rename. Inherited from existing admin menu behavior. The "managed by *plugin*" badge warns admins.
- **`is_active` detection misses hash-anchored links.** `get_menu_data()` compares against `parse_url(... PHP_URL_PATH)` which strips the hash, so `/profile#orders` never matches as "active." Broken today, stays broken — moving to DB doesn't change it.
- **Translation surface narrows.** Hardcoded labels could later be wrapped in a translation function; DB-stored labels can't be wrapped after the fact without intercepting reads. Same constraint already applies to `amu_admin_menus` rows that have always been DB-stored.

## Open Questions

- **`target` field for `_blank` links?** Not needed today, easy to add later.
- **Rename `amu_admin_menus` to `amu_menus`?** The table name is now mildly misleading. Renaming is straightforward (rename the table, alias the old name in PostgreSQL, update the model's `$tablename`) but risks breaking external consumers (analytics dashboards, ad-hoc SQL scripts, external integrations) that hardcode the name. Defer until there's a concrete reason to do it.

## Documentation Updates

Update the existing "Admin Menus (Declarative)" section in `docs/plugin_developer_guide.md` (line ~349):

- Rename to "Plugin Menus (Declarative)" to reflect the broader scope.
- Add a "Locations" subsection introducing `admin_sidebar` and `user_dropdown` and the per-location rules (permission floor, allowed visibility values).
- Document the `profileMenu` key alongside `adminMenu` with a worked example.
- Field reference table grows columns for which fields apply to which locations.
- Note the URL auto-prefix removal for plugin authors who relied on it (none currently do).
