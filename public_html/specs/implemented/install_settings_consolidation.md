# Install SQL Declarative Consolidation

## Problem

`utils/create_install_sql.php` maintains two separate hardcoded data sources that have drifted from their declarative counterparts.

### Settings

`utils/create_install_sql.php` maintains a hardcoded `$default_settings` PHP array (72 entries) that is the actual source of `stg_settings` rows in every fresh install. `settings.json` was introduced as the declarative source of truth for setting defaults (seeded on every `update_database` run via `ON CONFLICT DO NOTHING`), but the two sources have drifted:

- 14 settings in the PHP array are absent from `settings.json` — they would be missing from the seeder
- 11 settings in `settings.json` are absent from the PHP array — they are missing from fresh installs
- 9 values disagree between the sources
- `bookings_active` is hardcoded in the PHP array even though it is plugin-owned and belongs in bookings' `plugin.json`
- The PHP array uses hardcoded sequential IDs, which can cause sequence conflicts

The PHP array is not regenerated from any authoritative source. It requires manual maintenance and will continue to drift.

### Admin Menus

`amu_admin_menus` is in the `$essential_tables` list, so the generator exports every current row via `pg_dump --data-only`. The table holds two distinct populations that need different treatment:

**Core rows** — admin sidebar items for built-in features (Users, Events, Products, Blog, etc.) and the `core-*` user_dropdown items (Home, Sign in, My Profile, Sign out, etc.). These have no plugin.json declaration and should be in the install SQL.

**Plugin-owned rows** — items declared in a plugin's `plugin.json` under `adminMenu` or `profileMenu`, synced into the table by `PluginManager::sync()` when a plugin is activated. These should NOT be in the install SQL.

Currently, the generator exports both populations together. The 21 plugin-owned rows being exported:

| Plugin | Location | Slugs |
|--------|----------|-------|
| `server_manager` | admin_sidebar | `server-manager`, `server-manager-dashboard`, `server-manager-upgrades`, `server-manager-targets`, `server-manager-jobs`, `system-marketplace` |
| `bookings` | admin_sidebar | `bookings-parent`, `bookings`, `booking-types` |
| `joinery_ai` | admin_sidebar | `joinery-ai`, `joinery-ai-recipes`, `joinery-ai-runs`, `joinery-ai-notes` |
| `email_forwarding` | admin_sidebar | `incoming` |
| `dns_filtering` | user_dropdown | `dns-filtering` (declared via `profileMenu`) |

After filtering, 53 core admin_sidebar rows and 14 `core-*` user_dropdown rows remain — 67 total.

**Why this matters:**

1. **Stale data.** The install SQL captures menu state at publish time. If a plugin renames, reorders, or adds a menu item in `plugin.json` between publishes, fresh installs get the old data. The discrepancy is invisible until PluginManager overwrites it on next sync.

2. **Ghost menus.** On a fresh install, `plg_plugins` is empty — no plugins are formally active. But the exported menu rows make Server Manager, Bookings, and Joinery AI appear in the admin sidebar immediately, before the admin has activated or configured those plugins. An admin who never activates Server Manager still sees its menu entries.

3. **PluginManager conflict.** When a plugin IS activated, `PluginManager::sync()` upserts its menu rows. If those rows already exist from the install SQL (possibly with different `order`, `icon`, or `url` values than the current `plugin.json`), the upsert corrects them — but only at activate time. Between fresh install and first activation, the data may be inconsistent.

**Fresh install flow — current vs new:**

| Step | Current | After this change |
|------|---------|-------------------|
| Load install SQL | 80+ menu rows present, including all plugin menus | 67 core rows only |
| Admin sidebar | Shows Server Manager, Bookings, Joinery AI immediately | Shows only built-in content areas |
| Admin activates a plugin | PluginManager upserts rows (overwrites stale install data) | PluginManager inserts rows fresh from plugin.json |
| Source of truth | Install SQL snapshot → overwritten at activation | plugin.json, always current |

**Existing installs are unaffected.** The install SQL change only affects fresh installs. Existing sites already have their menu rows populated from prior syncs; `update_database` does not remove or reset menu rows.

**Migration record note.** Migration 96 inserted `system-marketplace` and is marked pre-applied in the install SQL. After this change, `system-marketplace` is no longer in the COPY data (it's plugin-owned by server_manager). The migration record stays pre-applied — that's correct, because the migration's job is now done by PluginManager rather than the migration itself. The pre-applied record just prevents the migration from running again, which is the right behavior.

## Goals

1. `create_install_sql.php` reads default settings directly from `settings.json` — no hardcoded array.
2. `settings.json` is the single source of truth for all core setting defaults on both fresh installs and `update_database` runs.
3. Plugin-owned settings are absent from the install SQL — they seed from `plugin.json` on plugin activate.
4. Plugin-owned menu rows are absent from the install SQL — they sync from `plugin.json` on plugin activate.
5. `_site_init.sh` validation continues to pass (`blog_active`, `theme_template`, `events_active` all present).

## Non-Goals / Out of Scope

- Settings UI metadata in `settings.json` (separate conversation)
- Bulk migration of plugin settings to `plugin.json` beyond `bookings_active` — the DB diff surfaced ~32 plugin-owned settings (`dns_filtering_*`, `email_forwarding_*`, `joinery_ai_*`, `acuity_*`, `urbit_*`) that should move to their respective plugin.json files, but doing so per-plugin is a follow-up
- Cleanup of accidentally-stored POST data in `stg_settings` — the diff surfaced `_csrf_token`, `g-recaptcha-response`, `h-captcha-response` rows. Real bug in some settings save flow that wasn't filtering form metadata. Fix and one-shot `DELETE` are a separate task
- Cleanup of runtime/calculated settings rows (`baseDir`, `database_version`, `mailgun_version`, etc.) — those are written by code at runtime, not declarative defaults; out of scope here
- Making core admin sidebar menus declarative (they change infrequently; migrations remain acceptable)

---

## Changes

### 1. Add missing settings to `settings.json`

The following settings are in the PHP array (with meaningful defaults) but absent from `settings.json`. Add them:

| Setting | Default | Notes |
|---------|---------|-------|
| `composerAutoLoad` | `../vendor/` | Same path on every site; set by install convention not environment |
| `theme_template` | `default` | Controls active theme; required for site to render |
| `upload_web_dir` | `uploads` | Web-relative path for uploaded files |
| `default_mailing_list` | `1` | Default mailing list ID for new signups |
| `bulk_footer` | `default_footer` | Email template references |
| `bulk_outer_template` | `default_outer_template` | |
| `event_email_inner_template` | `blank_template` | |
| `event_email_outer_template` | `default_outer_template` | |
| `event_email_footer_template` | `event_bulk_footer` | |
| `group_email_inner_template` | `blank_template` | |
| `group_email_outer_template` | `default_outer_template` | |
| `group_email_footer_template` | `event_bulk_footer` | |
| `individual_email_inner_template` | `blank_template` | |

Place these in the appropriate sections of `settings.json` (system defaults and email template refs).

### 2. Fix value disagreements in `settings.json`

Update `settings.json` as follows:

| Setting | Old default | Correct default | Reason |
|---------|-------------|-----------------|--------|
| `upgrade_source` | `""` | `https://getjoinery.com` | Fresh installs should point to the production upgrade server |
| `allowed_upload_extensions` | n/a (PHP had stale list) | `gif,jpeg,jpg,png,avif,webp,pdf,...` | JSON already correct; avif/webp added in migration 83 |
| `default_timezone` | n/a (PHP had `America/New_York`) | `UTC` | Neutral default; admins set per-site |
| `comments_unregistered_users` | `1` | `0` | Unregistered commenting off by default |
| `cookie_consent_mode` | `gdpr` | `auto` | Less restrictive default for new installs |
| `email_service` | `mailgun` | `smtp` | SMTP is the safe default before Mailgun is configured |
| `smtp_port` | n/a (PHP had `465`) | `""` | Leave blank; admin configures when setting up SMTP |
| `use_captcha` | `0` | `0` | Already correct; no change needed |

Remove from `settings.json` entirely:

| Setting | Reason |
|---------|--------|
| `upgrade_server_active` | Redundant — `upgrade.php` already ORs this with `PluginHelper::isPluginActive('server_manager')`, making the setting dead weight for any site running server_manager |

**Note on migration v37:** `migrations.php:516` inserts `upgrade_server_active='0'` if missing. After change 3b deletes the 95 legacy INSERT-settings migrations, this entry goes too. So the row genuinely will not appear on fresh installs. Existing sites already have the row (migration v37 ran long ago) — `update_database` does not delete it. Harmless: the only consumer ORs with `isPluginActive('server_manager')`.

### 3. Remove plugin-owned settings from install SQL

- `bookings_active` — drop from the PHP array; add `{ "name": "bookings_active", "default": "1" }` to `plugins/bookings/plugin.json` under the `settings` key. It will seed on plugin activate via `PluginManager::syncSettings()`.

### 3a. Add legit-missing settings discovered via DB diff

A diff between `SELECT stg_name FROM stg_settings` on the live test site and the names in `settings.json` surfaced 102 orphans. After excluding plugin-owned (~32, deferred to follow-up), runtime/calculated (~17, never seeded by design), and accidentally-stored POST data (~3, real bug — see "Out of Scope" below), 37 legit-missing settings remain that should be in `settings.json`:

- **Social link fields** (21): `social_discord_link`, `social_facebook_link`, `social_github_link`, `social_google_link`, `social_instagram_link`, `social_linkedin_link`, `social_messenger_link`, `social_mixcloud_link`, `social_pinterest_link`, `social_reddit_link`, `social_slack_link`, `social_snapchat_link`, `social_soundcloud_link`, `social_spotify_link`, `social_stack_link`, `social_telegram_link`, `social_tiktok_link`, `social_twitch_link`, `social_twitter_link`, `social_whatsapp_link`, `social_youtube_link` — all default `""`
- **Theme color overrides** (5): `jy_color_bg`, `jy_color_primary`, `jy_color_primary_hover`, `jy_color_primary_text`, `jy_color_surface` — all default `""`
- **Other** (11): `robots_text`, `preview_image`, `preview_image_increment`, `email_test_recipient`, `nickname_display_as`, `active_theme_plugin`, `site_template` — all default `""`. (Final count and exact names confirmed during implementation by re-running the diff.)

Use the live test DB as the canonical reference, not migrations.php — the DB reflects all paths through which settings have ever been added (migrations, admin saves, manual SQL).

### 3b. Delete legacy INSERT-into-`stg_settings` migrations

After 3a, the 95 INSERT-only migrations in `migrations/migrations.php` that exist solely to seed setting names are dead code:
- All existing sites have already run them (they're recorded in `dvr_database_versions`)
- Fresh installs mark them pre-applied via the install SQL, so they never execute
- `Setting::seed_declared($settings_json['settings'])` from change 4 covers fresh installs and any future name additions

Delete these 95 entries from `migrations.php`. Identify them with: `INSERT INTO ... stg_settings ... VALUES ('<name>', ...)` whose only effect is row creation (no transformation). The version records in `dvr_database_versions` on existing sites become orphans pointing at deleted migration entries — harmless; the migration runner skips unknown versions.

**Keep** the ~10 UPDATE/DELETE migrations (e.g. `UPDATE allowed_upload_extensions ... || ',avif,webp'`, `DELETE FROM stg_settings WHERE stg_name = 'database_version'`). Those captured intentional value changes or removals; they're committed history. Same "won't run again" logic applies, but they document why the data is what it is.

### 3c. Tighten `Setting::seed_declared()` validation

`data/settings_class.php` line 105 currently does `if (empty($d['name'])) continue;` — a silently-skipped malformed entry. Same pessimistic pattern as the menu code: a typo'd field (`"nme"` instead of `"name"`) drops the setting from fresh installs and from `update_database` runs, and nobody notices until something downstream breaks.

Replace the silent skip with a thrown `InvalidArgumentException` identifying the index of the bad entry. Apply to both `seed_declared()` and `unseed_declared()` for consistency. The existing call sites in `update_database.php` and `PluginManager::syncSettings()` are already wrapped in try/catch and will surface the error as a visible warning, so failure is loud but doesn't block the rest of the upgrade.

### 4. Update `create_install_sql.php`

Replace the hardcoded `$default_settings` array and its INSERT loop (lines ~555–663) with:

```php
// Load default settings from settings.json (single source of truth)
$settings_json_path = PathHelper::getIncludePath('settings.json');
$settings_json = json_decode(file_get_contents($settings_json_path), true);
if (!$settings_json || !isset($settings_json['settings'])) {
    die("ERROR: Could not read settings.json\n");
}

$setting_id = 1;
foreach ($settings_json['settings'] as $setting) {
    $name = $setting['name'];
    $value = $setting['default'] ?? '';
    $escaped_value = str_replace("'", "''", $value);
    fwrite($output_handle,
        "INSERT INTO public.stg_settings (stg_setting_id, stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_group_name) " .
        "VALUES ($setting_id, '$name', '$escaped_value', 1, CURRENT_TIMESTAMP, 'general');\n"
    );
    $setting_id++;
}
fwrite($output_handle, "\nSELECT pg_catalog.setval('public.stg_settings_stg_setting_id_seq', $setting_id, true);\n\n");
echo "   Generated " . count($settings_json['settings']) . " default settings from settings.json\n";
```

The sequential ID approach is retained for now (simpler than using DEFAULT + querying max). The sequence is reset correctly at the end.

Update the step label from `[8.7/10]` to reflect the new source:
```
echo "[8.7/10] Generating default settings from settings.json...\n";
```

### 5. Add unique constraint on `amu_slug`

`amu_admin_menus` currently has no uniqueness on `amu_slug`, but the existing code already treats slug as globally unique (`PluginManager::syncMenus()` resolves parents via `WHERE amu_slug = ?` with no location scoping; the `core-*` reserved-slug filter is global). The schema needs to match.

In `data/admin_menus_class.php`:

```php
'amu_slug' => array('type'=>'varchar(32)', 'unique'=>true),
```

`update_database` will create the unique index on next run. The live test DB has zero duplicates today; spot-check docker-prod containers before rolling out.

This is a prerequisite for `ON CONFLICT (amu_slug)` and for the parent-slug subquery used in the install SQL.

### 6. Create `admin_menus.json`

New file at `public_html/admin_menus.json` (same location as `settings.json`). Declares all 67 core menu rows — 53 `admin_sidebar` and 14 `user_dropdown`. **Schema matches `plugin.json` exactly** (`adminMenu` and `profileMenu` top-level keys), so the same JSON shape is used for both core and plugin menu sources:

```json
{
  "_comment": "Core admin and profile menu items. Seeded on every update_database run with overwrite=false, prune=false. Plugin menus are declared in plugin.json and synced by PluginManager with overwrite=true, prune=true. This file is a source of *initial values* for fresh installs — admin customizations to existing rows are preserved.",
  "adminMenu": [
    { "slug": "users", "title": "Users", "url": "", "order": 1, "permission": 5, "icon": "users" },
    { "slug": "users-list", "title": "Users list", "parent": "users", "url": "admin_users", "order": 1, "permission": 5 }
  ],
  "profileMenu": [
    { "slug": "core-home", "title": "Home", "url": "/", "order": 10, "permission": 0, "visibility": "both" },
    { "slug": "core-signin", "title": "Sign in", "url": "/login", "order": 20, "permission": 0, "visibility": "out" },
    { "slug": "core-signup", "title": "Sign up", "url": "/register", "order": 40, "permission": 0, "visibility": "out", "settingActivate": "register_active" },
    { "slug": "core-profile", "title": "My Profile", "url": "/profile", "order": 50, "permission": 1 },
    { "slug": "core-signout", "title": "Sign out", "url": "/logout", "order": 200, "permission": 1 }
  ]
}
```

**Fields** (per entry, both arrays): `slug` (required, unique), `title` (required), `url` (default `""`), `order` (required), `permission` (required), `icon` (optional), `settingActivate` (optional), `parent` (optional slug; admin only), `visibility` (optional, `profileMenu` only — `in` / `out` / `both`, defaults to `in`).

`location` and `disabled` are implicit: `adminMenu` items are `admin_sidebar`+`amu_disable=0`; `profileMenu` items are `user_dropdown`+`amu_disable=0`. No row in the live DB has `amu_disable=1`, so the field is omitted from the schema; if it's ever needed it can be added later.

### 7. Refactor `syncMenus()` to handle both core and plugin sources

The existing `PluginManager::syncMenus()` and the originally-proposed `seedCoreAdminMenus()` share ~90% of their logic — parent slug resolution, two-pass parent/child, reserved-slug filtering, INSERT/UPDATE construction. The only real differences are policy: whether to overwrite existing rows, whether to prune undeclared slugs, and how to load the declared items. Collapse them into one method.

**New signature:**

```php
public function syncMenus(
    string $source_name,                  // plugin name, or 'core'
    ?array $declared = null,              // ['admin' => [...], 'profile' => [...]]
    array $options = []                   // ['overwrite' => bool, 'prune' => bool]
): void
```

**Defaults preserve current plugin behavior** — `$options['overwrite']` defaults to `true`, `$options['prune']` defaults to `true`. Existing call sites (`syncMenus($plugin_name)`) continue to work unchanged.

**Core call site:**

```php
$plugin_manager->syncMenus(
    'core',
    [
        'admin'   => $core_data['adminMenu']   ?? [],
        'profile' => $core_data['profileMenu'] ?? [],
    ],
    ['overwrite' => false, 'prune' => false]
);
```

**Behavioral changes inside `syncMenus()`:**

- **Strict per-entry validation, up front.** Before any DB work, validate every declared entry. Required fields per entry: `slug`, `title`, `order`, `permission`. Throw `InvalidArgumentException` on the first violation, with a message identifying the source and the offending entry (e.g. `syncMenus('bookings'): adminMenu[2] missing required field 'slug'` or `syncMenus('core'): profileMenu entry 'core-signin' has non-integer 'order'`). Rationale: silently skipping malformed entries creates insidious bugs — a typo'd `"slugg"` produces a missing menu the admin won't notice for weeks. A thrown exception fails the activation (or `update_database` step) immediately and points at the line to fix. Existing per-entry error_log paths in syncMenus (e.g. the parent-not-found warning) are upgraded to throws as well, since the same reasoning applies.
- `core-*` reserved-slug filter is gated on `$source_name !== 'core'` — core owns the `core-*` namespace and is allowed to declare those slugs. Plugins attempting to declare `core-*` slugs throw (currently `error_log` + skip — upgrade to throw for consistency with the new validation policy).
- Conflict path branches on `$options['overwrite']`: `true` → existing UPDATE behavior; `false` → skip the UPDATE entirely (insert-only), preserving any admin customizations to existing rows.
- Pruning is gated on `$options['prune']`: `true` → existing prune-from-`plg_metadata` behavior; `false` → skip the prune step entirely. Core never prunes; if a slug is removed from `admin_menus.json`, the row stays in the DB (admin can delete it manually).
- The metadata-tracking call (`saveMenuSlugsToMetadata`) still runs for plugins; for `'core'` it's a no-op (no `plg_metadata` row to write — there's no plugin record).

**Caller behavior on validation failure:**

- `update_database.php` (core seed): the existing `try { ... } catch (Exception $e) { ... }` catches the exception, prints the message, and continues with the rest of `update_database`. Core menu seed fails loudly but doesn't block the rest of the upgrade.
- Plugin activation: the existing activation flow propagates the exception, so the activation fails. The admin sees the error and the plugin stays inactive until plugin.json is fixed. This is the right behavior — better than half-activating a plugin with broken menus.

**Net effect:** one code path, one parent-resolution implementation, one validation pass. Future menu-handling improvements only need to be made in one place. The original `seedCoreAdminMenus()` is not added.

### 8. Wire core menu sync into `update_database.php`

Add immediately after the existing core settings seed block (line ~617):

```php
// Step: Seed core menus from public_html/admin_menus.json
echo "<br>\n<strong>Core Menu Seed</strong><br>\n";
try {
    $core_menus_path = PathHelper::getIncludePath('admin_menus.json');
    if (file_exists($core_menus_path)) {
        $core_menus_raw = file_get_contents($core_menus_path);
        $core_menus_data = json_decode($core_menus_raw, true);
        if (!is_array($core_menus_data)) {
            echo "⚠️  admin_menus.json is not valid JSON.<br>\n";
        } else {
            $plugin_manager = new PluginManager();
            $plugin_manager->syncMenus(
                'core',
                [
                    'admin'   => $core_menus_data['adminMenu']   ?? [],
                    'profile' => $core_menus_data['profileMenu'] ?? [],
                ],
                ['overwrite' => false, 'prune' => false]
            );
            $admin_count   = count($core_menus_data['adminMenu']   ?? []);
            $profile_count = count($core_menus_data['profileMenu'] ?? []);
            echo "✓ Core menus seeded ({$admin_count} admin, {$profile_count} profile)<br>\n";
        }
    } else {
        echo "⚠️  admin_menus.json not found at public_html root; skipping core menu seed.<br>\n";
    }
} catch (Exception $e) {
    echo "⚠️  Core menu seed failed: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}
```

The "missing file is loud" pattern matches the core settings seed step above.

### 9. Update `create_install_sql.php` for menus

Remove `amu_admin_menus` from `$essential_tables` entirely. Instead, generate INSERT statements directly from `admin_menus.json`. Two passes per array (parents before children); parent IDs in child rows are resolved via subquery on `amu_slug`:

```php
$menus_data = json_decode(file_get_contents(PathHelper::getIncludePath('admin_menus.json')), true);

// adminMenu → admin_sidebar / visibility 'in'
foreach (split_parents_first($menus_data['adminMenu'] ?? []) as $item) {
    write_menu_insert($output_handle, $item, 'admin_sidebar', 'in');
}

// profileMenu → user_dropdown / per-row visibility (defaults to 'in')
foreach (split_parents_first($menus_data['profileMenu'] ?? []) as $item) {
    $visibility = $item['visibility'] ?? 'in';
    write_menu_insert($output_handle, $item, 'user_dropdown', $visibility);
}
```

`write_menu_insert` emits one row with `amu_admin_menu_id` left to the sequence default; for entries with `parent`, `amu_parent_menu_id` is `(SELECT amu_admin_menu_id FROM public.amu_admin_menus WHERE amu_slug = '<parent-slug>')`. With the unique constraint from change 5 in place, the subquery is guaranteed to return at most one row.

Result: install SQL contains **only core menu rows** (67 total) — no plugin menus. Plugins bring their own menus at activation time via `syncMenus($plugin_name)`.

Add a step label `echo "[8.8/10] Generating core menus from admin_menus.json...\n";` before the menu loop, mirroring the settings step label updated in change 4.

### 10. Validate `_site_init.sh` still passes

`_site_init.sh` validates:
- `stg_settings` has ≥ 10 rows — passes (settings.json has 135+ entries)
- `blog_active` present — passes (in settings.json)
- `theme_template` present — passes (added in change 1)
- `events_active` present — passes (in settings.json)
- Default admin user exists — unaffected

No changes to `_site_init.sh` required.

---

## Going Forward

After this spec lands, the convention for adding settings is:

- **New core setting** → declare in `settings.json` with a sensible default. Do *not* add a new INSERT-into-`stg_settings` migration. `Setting::seed_declared()` on every `update_database` run will pick it up on existing sites; `create_install_sql.php` will pick it up on fresh installs.
- **New plugin-owned setting** → declare in the plugin's `plugin.json` under `settings`. `PluginManager::syncSettings()` seeds it on activate. Never inline an INSERT in a migration.
- **Changing the default value of an existing setting** → update `settings.json` (or `plugin.json`). Existing sites keep whatever value they have (seed_declared uses ON CONFLICT DO NOTHING). If you also need to *correct* a wrong value on existing sites, add an UPDATE migration with a tight WHERE clause (e.g. `WHERE stg_value = '<old default>'` so admin overrides aren't trampled). UPDATE/DELETE migrations remain a valid tool — only INSERT migrations are deprecated.
- **New core admin or profile menu** → add to `admin_menus.json`. Do not add an INSERT migration for `amu_admin_menus`.
- **New plugin menu** → declare in `plugin.json` under `adminMenu` / `profileMenu`. Already the existing pattern, unchanged.

This guidance also belongs in `docs/deploy_and_upgrade.md` where migration syntax is documented — folding it in there is part of Phase 1.

## Phased Implementation

The work splits cleanly into three independent phases. Each phase ships on its own with its own verification gate. Settings work (Phase 1) and menu work (Phase 2) don't depend on each other and can be done in either order; install SQL regeneration (Phase 3) depends on both being complete.

---

### Phase 1 — Settings consolidation

**Scope:** Make `settings.json` the single source of truth for setting names and defaults at runtime. Existing install SQL keeps working unchanged. Covers spec changes 1, 2, 3, 3a, 3b, 3c.

**Steps:**

1. Re-run the DB-vs-`settings.json` diff against an up-to-date production site to confirm the 37-orphan list. Update change 3a if the list has shifted.
2. Update `settings.json`:
   a. Add the 13 entries from change 1 (PHP-array gap)
   b. Add the 37 entries from change 3a (DB diff, mostly empty defaults)
   c. Fix value disagreements from change 2
   d. Remove `upgrade_server_active`
3. Add `bookings_active` to `plugins/bookings/plugin.json`
4. Delete the 95 INSERT-into-`stg_settings` migrations from `migrations/migrations.php`. Keep the ~10 UPDATE/DELETE migrations.
5. Tighten `Setting::seed_declared()` and `unseed_declared()` to throw on malformed entries instead of silent-skipping.
6. Update `docs/deploy_and_upgrade.md` to document the going-forward rule: new core settings declare in `settings.json`; new plugin settings in `plugin.json`. INSERT-into-`stg_settings` migrations are deprecated; UPDATE/DELETE migrations remain valid.

**Verification gate (run on the live test site, then on a docker-prod container before proceeding to Phase 2):**

- Run `update_database` — no errors; ✓ settings seeded count matches added entries
- Plugin seed regression: `DELETE FROM stg_settings WHERE stg_name = 'bookings_active'`, then trigger plugin sync (re-run `update_database` or activate from admin UI). Confirm `bookings_active` row reappears with value `1` from `plugins/bookings/plugin.json`. (Note: deactivate alone does *not* unseed settings — that only happens on uninstall — so a manual delete is needed to exercise the reseed path.)
- DB-vs-`settings.json` diff returns only the expected categories (plugin-owned, runtime/calculated, the 3 leaked POST keys); zero unexplained orphans
- Existing-site sanity: a manual `update_database` run on a copy of a real production DB completes without errors. The deleted migrations don't cause the runner to choke on missing version entries.
- `_site_init.sh --skip-db-validation` still passes (uses existing install SQL — unchanged in this phase)

**Pause and verify before starting Phase 2.**

---

### Phase 2 — Menu source-of-truth migration

**Scope:** Make `admin_menus.json` the source of truth for core menus and unify the menu sync code path with plugins. Install SQL still exports menus via the old path; this phase changes the *runtime* seed only. Covers spec changes 5, 6, 7, 8.

**Steps:**

1. Add `'unique'=>true` to `amu_slug` in `data/admin_menus_class.php`. Spot-check every docker-prod container first with `SELECT amu_slug, COUNT(*) FROM amu_admin_menus GROUP BY amu_slug HAVING COUNT(*) > 1` — if any duplicates exist, manually resolve them (decide which row to keep, `DELETE` the rest) **before** deploying this phase, otherwise `update_database` will fail mid-upgrade when it tries to create the unique index. Then run `update_database` to create the index.
2. Create `public_html/admin_menus.json` with all 67 core rows (53 `adminMenu` + 14 `profileMenu`).
3. Refactor `PluginManager::syncMenus()` to accept `$source_name`, `$declared`, `$options` — defaults preserve existing plugin behavior. Add strict per-entry validation up front (throw on missing required fields, on parent-not-found, on plugin-declared `core-*`). Gate the `core-*` filter, overwrite-on-conflict, and prune steps on the new arguments.
4. Wire `syncMenus('core', ..., ['overwrite' => false, 'prune' => false])` into `utils/update_database.php` after the settings seed step.

**Verification gate:**

- Run `update_database` on the test site — ✓ core menu seed completes with the expected admin/profile counts; ✓ no admin customizations to existing rows are overwritten (rename a core menu's `amu_menudisplay` first, re-run, confirm rename survives)
- Plugin regression: deactivate and reactivate `bookings` (or any plugin with a menu). Confirm its menu rows are pruned on deactivate and re-inserted on activate, with the same `order` / `icon` / `url` as `plugin.json` declares. The `syncMenus()` refactor must not break the existing plugin code path.
- `pg_indexes` shows one unique index covering `amu_slug`
- `_site_init.sh --skip-db-validation` still passes (install SQL unchanged so far — this is just a sanity check)
- Strict-validation smoke test: temporarily introduce a malformed entry in `admin_menus.json` (drop `slug` from one row), re-run `update_database`. Expect a loud, descriptive error from the seed step. Revert.

**Pause and verify before starting Phase 3.**

---

### Phase 3 — Install SQL regeneration

**Scope:** Regenerate `joinery-install.sql.gz` from the now-canonical declarative sources. Phase 1 and Phase 2 are prerequisites. Covers spec changes 4 and 9.

**Steps:**

1. Update `utils/create_install_sql.php`:
   a. Replace hardcoded settings array with `settings.json` reader (use `PathHelper::getIncludePath('settings.json')`, no `../`)
   b. Remove `amu_admin_menus` from `$essential_tables`; generate INSERTs from `admin_menus.json` for both `adminMenu` and `profileMenu` arrays
   c. Add `[8.8/10]` step label for the menu generation step
2. Regenerate `joinery-install.sql.gz`.

**Verification gate (load regenerated install SQL into a fresh DB):**

- `SELECT COUNT(*) FROM stg_settings` returns ~185 (135 original + 13 + 37 added; exact number from Phase 1's confirmed diff)
- `SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_source'` returns `https://getjoinery.com`
- `SELECT stg_value FROM stg_settings WHERE stg_name = 'theme_template'` returns `default`
- `SELECT COUNT(*) FROM stg_settings WHERE stg_name = 'upgrade_server_active'` returns 0
- `SELECT COUNT(*) FROM amu_admin_menus` returns 67 (53 admin_sidebar + 14 user_dropdown)
- `SELECT COUNT(*) FROM amu_admin_menus WHERE amu_location='admin_sidebar'` returns 53
- `SELECT COUNT(*) FROM amu_admin_menus WHERE amu_location='user_dropdown'` returns 14
- `SELECT COUNT(*) FROM amu_admin_menus WHERE amu_slug IN ('server-manager','bookings-parent','joinery-ai','incoming','dns-filtering')` returns 0
- DB-vs-`settings.json` diff returns zero unexplained orphans (legit-missing all in JSON; plugin-owned, runtime, leaked-POST correctly absent on fresh install)
- Run `_site_init.sh --skip-db-validation` — passes
- Run `update_database` immediately after fresh install — no new settings inserted (all already present from install SQL); no new core menus inserted; no migration errors
- Activate the bookings plugin — its 3 menu rows appear; `bookings_active` setting seeds from plugin.json
- End-to-end deploy test: build a new release on the test server, apply it to a docker-prod scratch container via `upgrade.php`, confirm both first-time install and update paths complete cleanly
