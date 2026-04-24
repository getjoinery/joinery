# Declarative Plugin Settings Spec

## Overview

Plugin settings currently live in SQL migration files — a developer writes `INSERT INTO stg_settings ...` in `plugins/{name}/migrations/001_settings.sql` to seed defaults on install, then maintains parallel `DELETE` logic in `uninstall.php`. This mirrors the pattern `adminMenu` previously had and which was already cleaned up: declared once in `plugin.json`, managed by `PluginManager`.

This spec brings settings to the same model. A new `settings` key in `plugin.json` (and a new `settings.json` file at the `public_html/` root for core settings) declares the names and defaults. `PluginManager` seeds missing rows on activate/sync and removes them on uninstall. Migrations are no longer the place to put default settings.

**File location note:** `settings.json` lives at `public_html/settings.json`, alongside `composer.json`. The front controller (`serve.php`) doesn't route unknown top-level files, so the file is not web-accessible — no `.htaccess` rule is needed. This is the same treatment `composer.json` already gets.

**Explicit non-goal:** settings UI metadata (labels, types, help text, form widgets). Settings forms today use `settings_form.php`, and that mechanism continues to work. Adding UI schema to the JSON is tempting and will be a separate conversation if and when it earns its place.

## Current State

### Settings table (`stg_settings`)
Defined in `data/settings_class.php:38-46`. Columns:
- `stg_setting_id` (serial PK)
- `stg_name` (varchar(100), unique)
- `stg_value` (text, nullable)
- `stg_group_name` (varchar(255), nullable) — dead metadata. The admin settings page at `/adm/admin_settings.php` renders sections from hardcoded `<h3>` headings and does not read this column. In the DB it's almost always `'general'`; a handful of other values (`email`, `api`, `subscriptions`) exist from old migrations but have no observable effect.
- `stg_usr_user_id` (int4) — in practice always `1` (system)
- `stg_create_time`, `stg_update_time`

For seeding purposes, only `name` and `value` matter. The seeder writes `'general'` for `stg_group_name` unconditionally; plugins cannot override it.

### How defaults get into the DB today
1. **Core settings:** Large historical `INSERT INTO stg_settings` block in `migrations/migrations.php` (~4500 lines of migrations, many of which insert settings).
2. **Plugin settings:** Each plugin's `migrations/migrations.php` or `.sql` files contain INSERTs, e.g. `plugins/bookings/migrations/migrations.php` inserts `bookings_enabled`.
3. **Reads:** `Globalvars::get_setting()` at `includes/Globalvars.php:23-96` — in-memory cache per request, returns `''` on miss (or `NULL` for blank-valued rows).

No JSON, schema, or settings manifest exists today.

### How `admin_settings.php` discovers settings
`adm/logic/admin_settings_logic.php:80-98` does `new MultiSetting(array())` and loads every row. The admin page reflects whatever is in the DB. No schema is consulted. This continues to work unchanged — settings will still be in the DB after seeding; only the seeding source changes.

### Pattern being mirrored
`syncAdminMenus()` at `includes/PluginManager.php:1068-1220` (~153 lines). Reads `adminMenu` from the manifest, upserts rows into `amu_admin_menus`, prunes undeclared slugs, caches the declared slug list in `plg_metadata`. Settings sync will be simpler because settings are a flat list rather than a parent/child tree.

## Design

### Plugin JSON shape

New optional top-level `settings` key in `plugin.json`:

```json
{
  "name": "Email Forwarding",
  "version": "1.2.0",
  "adminMenu": [ ... ],
  "settings": [
    { "name": "email_forwarding_enabled", "default": "1" },
    { "name": "email_forwarding_max_aliases", "default": "50" },
    { "name": "email_forwarding_smtp_host", "default": "" }
  ]
}
```

**Fields:**

| Field | Required | Default | Description |
|---|---|---|---|
| `name` | Yes | — | Setting key. Must start with the plugin's directory name. Validated on install. |
| `default` | No | `""` | String value stored in `stg_value`. Always a string — booleans are `"0"` / `"1"`, numbers are `"42"`. JSON-native `true`/`false`/numbers are rejected at validation time. |

**Naming validation, applied on install and sync — two rules:**
1. Each declared `name` must start with the plugin's directory name (e.g. a plugin at `/plugins/email_forwarding/` must declare names like `email_forwarding_enabled`). Catches typos and cross-plugin mistakes.
2. A plugin may not declare a `name` that is already present in `settings.json` (core owns its namespace).

No cross-plugin name-collision check. Rule 1 makes cross-plugin collisions impossible in practice (every plugin's names start with its own unique directory name), and under seed-only + `ON CONFLICT DO NOTHING` semantics, even a pathological collision would just silently no-op rather than corrupt data. Not worth the N×M manifest walk on every sync.

Validation failures throw — on `install()` the plugin does not install; on `activate()` the plugin does not activate; on `sync()` the offending plugin is skipped with a logged error and other plugins continue.

**Blank-default semantics — important:** `default: ""` creates a row with an empty value. `Globalvars::get_setting()` returns `''` for both "no row" and "row with empty value," so callers can't distinguish — but the admin settings UI *does* see the row and renders an editable field for it. Declaring `default: ""` is the right move for anything you want to expose to admins even without a meaningful factory default (API keys, SMTP hosts, custom CSS). Omitting the declaration entirely means no row, no UI, and `get_setting()` still returns `''`. Choose deliberately.

### Core settings file

New file `settings.json` at the `public_html/` root:

```json
{
  "settings": [
    { "name": "blog_active", "default": "1" },
    { "name": "site_name", "default": "" },
    { "name": "totp_require_admins", "default": "0" }
  ]
}
```

Same shape as the plugin `settings` array. No prefix validation for core (core owns the namespace).

**Scope principle — what belongs in this file:**

A grep of `get_setting('literal')` in the codebase returns ~223 distinct literal names. We are **not** enumerating all of them. Use this rule for what to include:

- **Include** settings that control feature behavior and where a working default exists (`blog_active=1`, `activation_required_login=0`, rate limits, cache TTLs).
- **Include** settings the admin UI expects to render with a blank initial value (`site_name=""`, `contact_email=""`, `captcha_private=""`). These benefit from having a row so admins see them in the settings page.
- **Omit** settings whose values are environment-derived and set by deployment tooling rather than factory defaults (`composerAutoLoad`, `baseDir`, `siteDir`).
- **Omit** settings that are strictly per-site state (`database_version`, sequence counters).

A fresh install with an empty DB plus `settings.json` should result in a site that boots, renders, and lets an admin finish configuration through the UI. That's the bar, not "every setting anyone ever reads."

This file is additive. Settings already in existing databases from historical migrations keep working untouched. Adding a setting here causes it to be seeded on next `update_database` for sites that don't yet have the row.

### Seed-only policy

Confirmed: **A — seed-only, never overwrite.**

On every sync/activate pass:
- If `stg_name` does not exist: INSERT with the declared default.
- If `stg_name` exists: leave it alone. No UPDATE.

Rationale: settings hold user configuration. A plugin v2 that wants to change an existing site's setting value should do it explicitly through a migration (which is an intentional, versioned operation). The declarative path is only for "ensure this row exists with *some* value."

This differs from `syncAdminMenus()`, which **does** overwrite — because menu structure is developer-owned. Settings values are user-owned once configured.

**What happens when v2 drops a setting from the manifest:**
Sync does not delete settings that are no longer declared, and uninstall only deletes what the *current* manifest declares. So a setting declared in v1 and dropped in v2 stays in `stg_settings` as an orphan row after uninstall. This is accepted: orphan setting rows have no runtime cost (nothing reads them), and the admin can delete them manually if they care. We explicitly chose not to track a historical cache of "every setting ever declared," because the complexity wasn't worth it for the orphan-row outcome.

If a developer genuinely needs the row gone, they write a migration (`DELETE FROM stg_settings WHERE stg_name = ...`).

**What happens to a plugin v2 that changes a default value:**
Existing sites keep the old value (seed-only — we don't touch existing rows). New installs get the new default. If the developer wants existing sites to get the new default, they write a one-time migration. This is intentional; silent default changes across upgrades have bitten production systems badly enough that we'd rather make the operator opt in.

## Implementation Plan

### Phase 1 — Setting class bulk helpers + PluginManager integration

Add two static methods to `Setting` in `data/settings_class.php` so both PluginManager and the core `update_database` path share them. Putting this on `Setting` instead of a new helper class colocates the bulk stg_settings write logic with the model that already owns that table:

```php
class Setting extends SystemBase {
    // ... existing fields ...

    /**
     * Bulk-insert declared default settings, skipping any stg_name that
     * already exists. Seed-only — never overwrites.
     * $declarations: [['name' => ..., 'default' => ...], ...]
     */
    public static function seed_declared(array $declarations): void {
        if (empty($declarations)) return;
        $dblink = DbConnector::get_instance()->get_db_link();
        $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
                VALUES (?, ?, 1, NOW(), NOW(), 'general')
                ON CONFLICT (stg_name) DO NOTHING";
        $stmt = $dblink->prepare($sql);
        foreach ($declarations as $d) {
            if (empty($d['name'])) continue;
            $stmt->execute([$d['name'], $d['default'] ?? '']);
        }
    }

    /**
     * Delete settings rows whose names appear in $declarations. Used during
     * plugin uninstall. Only currently-declared names are removed; orphans
     * from previously-declared-but-now-dropped settings are left in place.
     */
    public static function unseed_declared(array $declarations): void {
        if (empty($declarations)) return;
        $names = array_values(array_filter(array_column($declarations, 'name')));
        if (empty($names)) return;
        $dblink = DbConnector::get_instance()->get_db_link();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $dblink->prepare("DELETE FROM stg_settings WHERE stg_name IN ({$placeholders})")->execute($names);
    }
}
```

Uses `ON CONFLICT (stg_name) DO NOTHING` — `stg_name` already has a unique constraint (`data/settings_class.php:40`), so this is the race-safe form. `WHERE NOT EXISTS` in a prior draft had a TOCTOU window.

New method `syncSettings($name)` in `includes/PluginManager.php` wraps the seeder plus validation:

```php
private function syncSettings($pluginName) {
    $helper = PluginHelper::getInstance($pluginName);
    $declared = $helper->getDeclaredSettings();
    if (empty($declared)) return;

    $this->validateDeclaredSettings($pluginName, $declared);   // prefix + core collision
    Setting::seed_declared($declared);
}
```

No `plg_metadata` cache. Uninstall reads the manifest directly via `PluginHelper::getDeclaredSettings()` at uninstall time, and deletes only the names currently declared. Rows from settings that were declared in earlier versions but dropped from the current manifest are left in place (see "What happens when v2 drops a setting" above).

Validation method enforces the two rules from the design section: every declared `name` must start with the plugin's directory name, and no declared `name` may collide with a core setting in `settings.json`.

Add `PluginHelper::getDeclaredSettings()` at `includes/PluginHelper.php`:

```php
public function getDeclaredSettings() {
    return $this->manifestData['settings'] ?? [];
}
```

### Phase 2 — Wire into lifecycle

| Lifecycle hook | Action |
|---|---|
| `install()` | Do nothing. Settings are not needed while the plugin is inactive; seeding happens at first activate. |
| `activate()` | Call `syncSettings($name)` after dependency check, before `onActivate()` hook fires. |
| `sync()` | Call `syncSettings($name)` for every active plugin — catches settings added in new versions. |
| `deactivate()` | Do nothing. Settings persist. |
| `uninstall()` | Read the current manifest, DELETE rows for every declared name. Settings dropped from the manifest in a prior version remain as orphans. (Covered more fully in the auto-uninstall spec.) |

For uninstall specifically, the deletion reads `plugin.json` directly — no metadata cache is maintained. This keeps the mechanism simple and makes the trade-off explicit: v2 of a plugin dropping a setting from its manifest leaves the corresponding row behind on uninstall. Orphan rows are cheap; the cache-plus-union machinery that would avoid them is not.

### Phase 3 — Core settings seeding

1. Create `settings.json` at the `public_html/` root with declarations following the scope principle above. Initial pass: start from the settings the admin settings page currently expects (by auditing `admin_settings_logic.php` and the `settings_form.php` files), plus the ~20 boolean feature-gate settings (`*_active`, `*_enabled`). Aim for a file under ~100 entries in the first pass; it can grow over time.
2. In `utils/update_database.php`: after core migrations run but before the plugin sync step, read `settings.json` (via `PathHelper::getIncludePath('settings.json')`) and call `Setting::seed_declared()` directly (no validation wrapper needed — core is trusted).
3. The core seeder runs on every `update_database` invocation (deploy, admin utility run). Safe because it's seed-only.

Core does **not** use the `plg_metadata` caching mechanism — core settings are never "uninstalled," so there's no need to track what was seeded.

### Phase 4 — Convert existing plugins

For each plugin, add `settings` key to its `plugin.json`, then delete the INSERT statements from its migrations. If a migration ends up empty, delete it.

| Plugin | Settings declared in plugin.json | Migration file fate |
|---|---|---|
| `bookings` | `bookings_active` (moved from core `settings.json` — starts with plugin prefix, so it's plugin-owned) | Settings INSERT removed; migration retained for default booking-type seed |
| `items` | (none — the historical `items_enabled` migration INSERT was a zombie; no code reads it. Left undeclared; existing rows, if any, become orphans) | Settings INSERT removed; migration retained for default relation-type seed |
| `email_forwarding` | `email_forwarding_enabled`, `email_forwarding_log_retention_days`, `email_forwarding_max_destinations`, `email_forwarding_rate_limit_per_alias`, `email_forwarding_rate_limit_per_domain`, `email_forwarding_rate_limit_window`, `email_forwarding_srs_enabled`, `email_forwarding_srs_secret`, `email_forwarding_smtp_host`, `email_forwarding_smtp_port`, `email_forwarding_smtp_username`, `email_forwarding_smtp_password` (12 total) | `migrations.php` reduced to `return [];` (only held settings) |
| `scrolldaddy` | `scrolldaddy_dns_host`, `scrolldaddy_dns_internal_url`, `scrolldaddy_dns_api_key`, `scrolldaddy_dns_server_ip`, `scrolldaddy_dns_secondary_internal_url`, `scrolldaddy_dns_secondary_api_key`, `scrolldaddy_dns_secondary_server_ip` (7 total) | `migrations.php` reduced to `return [];` (three former migrations all held settings only; `scrolldaddy_blocklist_version` is per-site state, not declared) |
| `server_manager` | (none — its settings (`upgrade_server_active`, `upgrade_source`, `upgrade_location`, `archive_refresh_allowed_ips`, `allow_remote_archive_refresh`) don't start with the `server_manager_` prefix, so they cannot be plugin-owned under the prefix rule — left in core `settings.json`) | Unchanged |

Each plugin conversion is an independent small change. Verify by:
1. Fresh install in a throwaway site — settings appear in DB with correct defaults.
2. Upgrade of an existing install — declared settings that already exist are untouched; any newly-added settings in the JSON appear with the default.

**Migration records:** Plugins that have already-run migrations recorded in `plm_plugin_migrations` are unaffected. The migration files can be emptied or deleted after conversion; the tracking row is harmless and prevents re-runs of anything that was in the file before.

### Phase 5 — Docs

Three docs files need updates, in order of impact.

#### 5.1 — `docs/plugin_developer_guide.md`

This is the primary reference for plugin authors. Five changes:

1. **"Where does each piece go?" table** (around line 67). Change the existing row:
   > *Default settings rows, seed data → `.sql` file in `migrations/`, numbered for order, idempotent → [Migration System](#migration-system)*

   to:
   > *Default plugin settings → `settings` array in `plugin.json` → [Plugin Settings](#plugin-settings)*
   > *Other initial data (seed rows, categories, etc.) → `.sql` file in `migrations/`, numbered for order, idempotent → [Migration System](#migration-system)*

2. **New "Plugin Settings" section** after "Admin Menus (Declarative)" (around line 345). Parallel structure to the admin menus section. Contents:
   - The `settings` JSON shape with a full example (three settings with varied defaults).
   - The two validation rules (prefix must match plugin directory name; no collision with `settings.json`).
   - Seed-only policy stated explicitly: *"Existing setting values are never overwritten. If a plugin v2 changes a declared default, existing sites keep their old value; new installs get the new default. If you need existing sites to pick up a new default, write an SQL migration."*
   - Orphan-row behavior: *"Settings dropped from the manifest in a later version are not automatically deleted. Use an SQL migration if you need the row gone."*
   - Uninstall behavior: *"On uninstall, the rows named in the current manifest are deleted. Nothing else is touched."*

3. **"Migration System" section** (line 492). Current text says migrations are for "settings rows and initial data only." Tighten to **"initial data seeds only"** and add a cross-reference at the top pointing to the new Plugin Settings section:
   > *"For default plugin settings, use the `settings` key in `plugin.json` (see [Plugin Settings](#plugin-settings)). Migrations are for other seed data — dropdown options, category rows, reference data — that doesn't fit the settings model."*

4. **"Creating a New Plugin" checklist** (around line 1040). Current step 4 says *"Create `.sql` migration files in `plugins/{name}/migrations/` for settings rows and initial data seeds"*. Split into:
   - "Declare default settings in `plugin.json` under `settings` (see Plugin Settings)."
   - "Create `.sql` migration files in `plugins/{name}/migrations/` only if you have other initial data seeds (dropdowns, categories, reference rows)."

5. **"Plugin Settings Form" section** (line 511). Unchanged in mechanism — `settings_form.php` is still how plugins expose their settings in the admin UI. Add one new paragraph at the top:
   > *"Settings declared in `plugin.json`'s `settings` array are seeded into the database on plugin activate. `settings_form.php` renders them in the admin settings page. The names used in both must match exactly. The manifest handles seeding; the form file handles UI."*

   Also remove the bullet at line 548 (*"Use a migration to INSERT the setting row(s) into `stg_settings` so they exist on fresh installs"*) and replace with *"Declare the setting in `plugin.json`'s `settings` array so it exists on fresh installs."*

#### 5.2 — `docs/settings.md`

This is the dedicated settings reference. Current framing centers on "auto-creation when an admin saves the form" — that mechanism still works, but the manifest path is now the preferred one for plugin-owned settings. Three changes:

1. **Overview** (line 5). Current text: *"eliminates the need for migrations when adding new settings."* Keep that framing, but add a sentence about the manifest path:
   > *"For plugin-owned settings that need to exist on fresh install without admin intervention, declare them in `plugin.json` — see the Plugin Developer Guide. For settings that only need to exist once an admin fills them in (the historical pattern), the auto-create-on-save mechanism described below still applies."*

2. **"Adding New Settings → For Core Settings"** (line 50). Current text says add a form field to `/adm/admin_settings.php`. Add an adjacent path:
   > *"For core settings that should exist on every fresh install with a factory default, add an entry to `settings.json` at the `public_html/` root. This file is read by `update_database` on every run and seeds missing rows. Use this when the setting needs a sensible value from day one (feature gates, rate limits). Use the form-only path when the setting has no meaningful default and only exists once an admin configures it."*

3. **"Adding New Settings → For Plugin Settings"** (line 68). Currently three steps: create settings_form.php, use prefix convention, "that's it." Add a new "Step 0" before the existing Step 1:
   > *"**Step 0: Declare defaults in `plugin.json`.** For any setting that should exist on fresh install with a default value, add it to the `settings` array in your plugin manifest. See the Plugin Developer Guide for the full shape. This replaces writing `INSERT INTO stg_settings` statements in migrations."*

4. **"Plugin Uninstall"** (line 180). Current content likely describes the LIKE-based deletion pattern. Update to describe the automated path (PluginManager reads the current manifest and deletes declared names) and note the orphan-row caveat. This section will need to be rewritten fully against the auto-uninstall spec when that lands — for this spec, just update it to say settings declared in `plugin.json` are automatically cleaned up on uninstall.

5. **"Migration Guide for Existing Plugins"** (line 301). Repurpose as a conversion guide from the SQL-migration path to the manifest path. Walk through one example (e.g., bookings): before/after of `migrations.php` and `plugin.json`. Point out that removing the `INSERT INTO stg_settings` from the migration doesn't affect existing sites (the migration tracking row prevents re-run anyway) but new installs now use the manifest.

#### 5.3 — `CLAUDE.md`

Minimal. The "Configuration" section at line 184 currently describes `Globalvars::get_setting()` and notes "no `set_setting()` method." Both stay true. Add one line after the existing bullets:

> *"- Plugin-owned settings with factory defaults are declared in the plugin's `plugin.json` under `settings`. Core settings with factory defaults are declared in `settings.json` at the `public_html/` root. Both are seeded into `stg_settings` automatically; no migrations needed."*

No other changes needed — CLAUDE.md intentionally indexes into the detailed docs, and the detailed changes above carry the weight.

#### Not affected

- `docs/deploy_and_upgrade.md` — mentions migrations and the `upgrade_source` setting but never describes where settings originate. No changes.
- `docs/publish_upgrade_system_analysis.md`, `docs/theme_integration_instructions.md` — grep hits are incidental (they reference `stg_settings` in passing). No changes.

## Testing

1. **Fresh install of a plugin with a `settings` block** — rows appear in `stg_settings` with correct names and values.
2. **Sync with pre-existing settings** — user-modified values are not overwritten. Set a row to a non-default value, run sync, verify value is unchanged.
3. **Plugin adds a setting in v2** — sync picks it up and seeds it. Existing settings remain untouched.
4. **Plugin drops a setting in v2** — v1 declared `foo_a` and `foo_b`; v2 declares only `foo_a`. Sync is a no-op for `foo_b` (the row stays). Uninstall removes `foo_a` but leaves `foo_b` as an orphan row. This is the intended behavior.
5. **Plugin changes a default in v2** — existing sites keep the old value, new installs get the new default.
6. **Prefix validation** — plugin at `/plugins/bookings/` declaring a setting named `other_plugin_something` fails install with an error naming the expected prefix (`bookings`).
7. **Core collision** — plugin declaring a name present in `settings.json` fails install.
8. **Race-safe seeding** — run two syncs in parallel (test fixture). No duplicate key errors; `ON CONFLICT DO NOTHING` handles the overlap.
9. **Blank default** — `default: ""` creates a row with empty `stg_value`. Admin settings page renders an editable field for it.
10. **JSON-native non-string default rejected** — a plugin declaring `default: true` (JSON boolean) fails validation with a message pointing to the offending setting.
11. **Core seeding, fresh DB** — empty DB, run `update_database`, core settings from `settings.json` are all present with correct defaults.
12. **Core seeding, existing DB** — DB with all core settings pre-existing, `update_database` is a no-op for settings.
13. **Plugin uninstall** — rows whose names appear in the current manifest are deleted. Rows whose names are *not* in the current manifest (orphans from dropped settings, or unrelated settings sharing the prefix) are untouched.

## Out of Scope

- Settings UI metadata in JSON (labels, types, form widgets) — future consideration.
- Auto-generating `settings_form.php` from the JSON declarations — requires UI metadata first.
- Migrating the ~4500 lines of historical core migrations into `settings.json`. The file is seed-for-new-installs, not a rewrite of history.
- Per-user settings, per-environment overrides. Settings today are single-tenant global; this spec does not change that.
- Changing the `stg_settings` table schema.

## Dependencies

None — this change is self-contained. The auto-uninstall spec depends on this one (it reads the plugin's manifest at uninstall time to know which settings to delete), so land this first.

## Implementation notes (what actually shipped)

Deviations from the spec as originally drafted:

- **No new `SettingsSeeder` class.** The two bulk helpers live as static methods on `Setting` (`data/settings_class.php`): `Setting::seed_declared()` and `Setting::unseed_declared()`. Colocates the stg_settings write logic with the model that owns the table, one fewer class in `includes/`.
- **Core-name lookup is inlined** in `PluginManager::validateDeclaredSettings()` — read `settings.json` at the top of the method and build the lookup map there. No separate `getCoreSettingNames()` method and no per-request cache; the callers (activate, sync) are rare enough that re-parsing a 7KB JSON on each call is immeasurably cheap.
- **Core `settings.json` location:** `public_html/settings.json` (not `/config/settings.json`). The front controller doesn't route unknown top-level files, so the file isn't web-accessible — same treatment `composer.json` gets, no `.htaccess` rule needed.
- **Validated end-to-end** via admin "Sync with Filesystem" on joinerytest.site after implementation: sync completed cleanly for all 3 active plugins (email_forwarding with 12 declared settings, scrolldaddy with 7, server_manager with 0), no errors in the log, all declared rows already existed in DB so `ON CONFLICT DO NOTHING` was a full no-op as expected.

Files changed:
- Added: `public_html/settings.json` (122 core declarations)
- Added: `Setting::seed_declared()` and `Setting::unseed_declared()` in `data/settings_class.php`
- Added: `PluginHelper::getDeclaredSettings()` in `includes/PluginHelper.php`
- Added: `PluginManager::syncSettings()` + `validateDeclaredSettings()` in `includes/PluginManager.php`
- Modified: `PluginManager::onActivate()` seeds settings before activate.php hook
- Modified: `PluginManager::sync()` calls `syncSettings()` per active plugin
- Modified: `PluginManager::uninstall()` calls `Setting::unseed_declared()` with current manifest
- Modified: `utils/update_database.php` seeds core `settings.json` before plugin sync step
- Converted: `plugins/bookings/plugin.json`, `plugins/email_forwarding/plugin.json`, `plugins/scrolldaddy/plugin.json`, and their migrations
- Docs: `docs/plugin_developer_guide.md`, `docs/settings.md`, `CLAUDE.md`
