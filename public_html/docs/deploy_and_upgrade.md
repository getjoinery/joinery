# Deploy and Upgrade Systems

## Overview

Five complementary tools provide deployment and upgrade capabilities:

1. **upgrade.php** - Web-based upgrade system for client installations (recommended)
2. **publish_upgrade.php** - Package creation tool for distributing updates (core + themes + plugins) — lives in the Server Manager plugin
3. **publish_theme.php** - Individual theme/plugin publishing — lives in the Server Manager plugin
4. **install.sh** - Universal installer for Docker and bare-metal deployments
5. **build_dev_from_source.sh** - Git-based deployment for development environments (not recommended for production)

Tools 1, 4, and 5 use **DeploymentHelper** (`/includes/DeploymentHelper.php`) for shared validation, rollback, and theme/plugin preservation. Tools 2 and 3 require the **Server Manager** plugin to be active.

For Docker and bare-metal deployments, see **[Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md)**.

### Docker Shared Base Image

Docker site images build `FROM joinery-base:VERSION` rather than from a stock Ubuntu image. The base image contains Ubuntu + Apache + PHP + PostgreSQL + Composer + cron and is shared across all site containers on a host. Per-site images only layer the site code, config, and VirtualHost on top.

**Two-step build on a Docker host:**

```bash
# 1. One-time per host — build the shared base image (~5-10 minutes, ~2.3 GB).
./install.sh build-base

# 2. Create sites normally — each site image builds in seconds and is ~500 MB.
./install.sh site mysite mysite.com 8080
```

`install.sh site` refuses to run if `joinery-base:VERSION` is missing and tells you to run `build-base` first.

Site image builds also install every PHP extension the site's source declares (root `composer.json` `ext-*` plus each plugin's `requires.extensions`): a `Dockerfile.template` build step runs `utils/list_dependencies.php --apt` against the copied source and apt-installs the result. The base image carries the heavy shared stack; declared extensions ride the site layer, so they can never drift from the code.

**`BASE_IMAGE_VERSION`** is a constant at the top of `install.sh`. Bump it and run `build-base` again whenever the system stack changes:

- Ubuntu base version changes
- PHP major/minor version changes
- New apt packages or PHP extensions added to `do_server_setup`
- Any other change to `Dockerfile.base`

Existing containers keep running on their old base image until they are rebuilt — no disruption. Site rebuilds fire a **drift warning** if the current `install.sh do_server_setup` hash differs from the hash baked into the base image (stored as the `joinery.install_sh_hash` label). That's the signal to bump `BASE_IMAGE_VERSION` and rebuild the base.

#### Two-tier Apache: real client IP

A docker-prod request crosses two Apache instances: **host Apache** terminates TLS and reverse-proxies to `127.0.0.1:{container_port}`; **container Apache** runs PHP. Without help, `$_SERVER['REMOTE_ADDR']` inside the container is always `172.17.0.1` (the docker bridge gateway), which silently breaks IP-based features (rate limiting, API key IP restriction, analytics, audit logs).

The contract:

1. **Host proxy** (written by `install.sh` and `manage_domain.sh`) sets `RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s` — explicit `set` (not append) so the container receives a single trustworthy value.
2. **Container Apache** loads `mod_remoteip` with `RemoteIPInternalProxy 172.17.0.0/16`, rewriting `REMOTE_ADDR` from the `X-Forwarded-For` header before PHP runs. This is baked into `Dockerfile.template` (since v3.5).
3. **Access logs** use `%a` instead of `%h` so they show the rewritten address, not the bridge gateway.

Cloudflare-fronted sites are partial: the container sees Cloudflare's edge IP, not the original client. A future spec will trust Cloudflare's IP ranges at the host and read `CF-Connecting-IP`.

#### Upgrade-flow split (important)

This is the behavioural change most likely to trip up an operator who remembers the pre-shared-base model:

- **Code / theme / plugin changes** (PHP files under `public_html/`, migrations, settings) — deliver via the existing publish/upgrade pipeline (`publish_upgrade.php` + `upgrade.php`). **No base image work required.** Nothing changes here.
- **Declared PHP extensions** (root `composer.json` `ext-*`, plugin `requires.extensions`) — travel with the code: `upgrade.php` installs them post-swap and reloads web PHP. In a container they are apt packages in the writable layer, so `_install_declared_dependencies.sh` re-asserts the declared set at every container start, ahead of `update_database` — a rebuilt container gets them back before anything needs them. Nothing missing means apt is not called at all. No base image work required.
- **Other system stack changes** (new apt package outside the declared-extension mechanism, Ubuntu bump, PHP bump, anything in `do_server_setup`) — require **base rebuild + container rebuild**, not just `upgrade.php`. Operators must:
  1. Bump `BASE_IMAGE_VERSION` in `install.sh`
  2. Run `./install.sh build-base` on the host
  3. Rebuild each site container (see migration steps in `specs/implemented/docker_shared_base_image.md`)

##### Where a site's code lives, and what a rebuild does to it

Three named volumes hold everything `upgrade.php` writes: `{site}_code` at
`public_html`, `{site}_vendor` at `vendor`, and `{site}_scripts` at
`maintenance_scripts`. They sit alongside the data volumes (postgres, config,
uploads, storage, static_files, backups, cache, logs, sessions, apache_logs,
pg_logs), so no volume is mounted inside another.

The image also carries a copy of the release, and that copy is a **seed**:
Docker fills a named volume from the image the first time it is used and ignores
the image entirely once the volume has content. So the image builds a brand new
site and is inert for every rebuild after that — an in-place upgrade survives
`docker rm`, and an image older than the site cannot overwrite it.

A container with an empty code volume refuses to start and says so, rather than
serving an empty site.

The image carries no live configuration. Only `default_Globalvars_site.php`
travels into it from the archive's `config/`; the site's own
`Globalvars_site.php` — database password and `secret_box_key` — is generated at
first start, along with its admin password. Running `install.sh site` from a
directory that holds live configuration rather than an extracted release prints
what it declined to copy.

Rebuilding a site removes and recreates its container; every volume survives.
Deleting the volumes — the database, uploads, storage, backups, and the config
that holds `secret_box_key` — happens only under `--wipe-data`, on both the
`-y` path and the interactive prompt. The prompt states which of the two is
about to happen, and declining it leaves everything in place.

**A site whose code volume is not populated keeps its code in the container's
writable layer instead**, where `docker rm` discards it. For those sites the
installer compares the VERSION inside the container against
`$ARCHIVE_ROOT/public_html/VERSION` before it stops anything, and refuses unless
the archive is the same version or newer — an older archive, or either version
unreadable, is a hard refusal that leaves the container running untouched.
`--wipe-data` skips the check, since a wipe deletes the database that made the
running code load-bearing; `--allow-downgrade` is the only override, and it
prints what it is about to overwrite.

**Bare-metal installs get the same check**, against
`/var/www/html/SITENAME/public_html/VERSION`, and it runs before the overwrite
prompt so a refusal has changed nothing. `--wipe-data` is not a bypass there: it
deletes volumes, and bare metal has none. To move a bare-metal site forward in
place, run its own `utils/upgrade.php` rather than reinstalling over it —
`install.sh site` copies the archive on without deleting anything, so an older
one leaves shared files rolled back, newer-release files behind, and VERSION
naming the older release.

To see where a site stands:

```bash
# is this site's code on a volume?
docker volume inspect SITENAME_code
# what the site is running now
docker exec SITENAME cat /var/www/html/SITENAME/public_html/VERSION
# what a first-time seed would install
cat ARCHIVE_ROOT/public_html/VERSION
```

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

Universal installer for Docker and bare-metal deployments. Supports `--themes` flag to download published themes/plugins from the upgrade server after site creation (extensions whose manifests have `included_in_publish: true`).

**Full documentation:** [Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md)

---

### build_dev_from_source.sh

**Location:** `/var/www/html/joinerytest/maintenance_scripts/install_tools/build_dev_from_source.sh`

> **Note:** This script is functional but not recommended for production. Use `upgrade.php` for production deployments. `build_dev_from_source.sh` is suitable for development environments where git-based deployment is convenient.

```bash
# Basic deployment
./build_dev_from_source.sh joinerytest

# Verbose mode (recommended)
./build_dev_from_source.sh joinerytest --verbose

# Disable auto-rollback for debugging
./build_dev_from_source.sh joinerytest --norollback

# Manual rollback
./build_dev_from_source.sh joinerytest --rollback
```

**Features:**
- Git-based deployment from repository
- Pre-deployment validation (PHP syntax, plugin loading, bootstrap tests)
- Automatic rollback on failure (trap-based)
- Preserves extensions marked `receives_upgrades: false`
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
```

**CLI Usage:**
```bash
# Basic upgrade
php /var/www/html/joinerytest/public_html/utils/upgrade.php

# Verbose mode
php /var/www/html/joinerytest/public_html/utils/upgrade.php --verbose
```

**Features:**
- Downloads packages from upgrade server (configured via `upgrade_source` setting)
- Downloads core, themes, and plugins as separate archives
- Pre-deployment validation via DeploymentHelper
- Preserves extensions marked `receives_upgrades: false`
- Enhanced rollback (preserves failed deployments with timestamps)
- Database migrations and composer integration
- **Declared-dependency install** — after the file swap, installs any PHP extension the new code declares (root `composer.json` `ext-*` + plugin `requires.extensions`, resolved by `utils/list_dependencies.php --apt`) and reloads web PHP. Needs root (Docker `docker exec` has it; a non-root run degrades to a warning naming the manual `apt-get install`). Then runs every active plugin's declared `host_installer` via `_plugin_installers_start.sh` so new host requirements land with the deploy.
- **Graceful handling of missing archives** — if a theme or plugin archive returns 404, the upgrade warns and skips it instead of aborting. The core upgrade and all other themes/plugins proceed normally. A summary of skipped items is shown at the end.
- **Post-deploy smoke test** — after the new code is in place and migrations have run, the **`deploy` test tier** runs against it. A failure restores `public_html_last` and preserves the broken tree for diagnosis. This is the first thing in the pipeline that reads a line of the code being installed: `publish_upgrade.php` builds its archive from whatever is on the publisher's disk at that moment, half-finished edits included. The tier takes a couple of seconds, is entirely reads, and asks only whether the code runs on this machine — every deployable PHP file compiles, the core classes load, the database answers, the declarative manifests parse, and the site returns a page over HTTP. See [The deploy tier](#the-deploy-tier) below for why it is not `safe`. The rollback returns the code but **not** the schema, because migrations ran first; schema changes are additive so the previous code normally runs against them, but the output says plainly that this is a recovery rather than a clean undo, and the node should be upgraded forward rather than left there.
- **Site-root `maintenance_scripts/`** — the core archive carries `install_tools/` and `sysadmin_tools/` alongside `public_html/`, and the upgrade syncs them into the site root after the deploy swap and before `fix_permissions.sh` runs, so a release applies as one piece rather than with its own tooling a version behind. The sync compares by content (`rsync --checksum`), because the staged files carry the publishing box's timestamps while the node keeps its own and a same-size edit can otherwise look unchanged. It does not delete: a node can legitimately hold scripts the archive does not ship, so files absent from a release are left in place. `*.sh` are made executable afterwards. A failed sync is a warning, not an abort — `public_html` is already live — and it says explicitly that the node's backup, restore and permission scripts are still the previous version.

**Plugin refresh scope:** the upgrade download loop iterates **plugins that are installed** (rows in `plg_plugins`) and attempts an archive fetch for each. Plugins published by the source succeed; plugins not in the source's catalog 404 at the upgrade endpoint (they were never packaged because they have `included_in_publish: false` — see [Extension Distribution Flags](#extension-distribution-flags) below) and are skipped via the warning path above. Uninstalling a plugin removes its row, so an uninstalled plugin is not re-downloaded on subsequent upgrades — the operator's removal sticks. Conversely, a new upstream plugin won't auto-appear on existing sites; the operator gets it via the admin Plugins page (install a plugin already on disk) or a plugin upload.

The two distribution flags on the plugin's manifest govern the distribution pipeline: `included_in_publish` controls what `publish_upgrade.php` packages (publisher-side), while `receives_upgrades` controls what `DeploymentHelper` preserves across a deploy swap and what `_reconcile_upgradable_assets.sh` re-downloads on container boot (customer-side). The upgrade-time refresh loop itself no longer *filters* by either flag — it just tries everything installed and lets the endpoint's response be the source of truth for whether a given plugin is in the publisher's catalog.

**Download Flow:**
1. Fetches available upgrade info from upgrade server
2. Downloads core archive (`joinery-core-X.XX.upg.zip`)
3. Downloads each published theme archive (`theme-THEMENAME-X.XX.upg.zip`) — themes the source published with `included_in_publish: true`
4. Downloads an archive (`plugin-PLUGINNAME-X.XX.upg.zip`) for each plugin with a row in `plg_plugins`
5. If any theme/plugin archive is unavailable (404), logs a warning and continues
6. Extracts and validates all archives
7. Performs deployment with rollback protection

#### Deployment self-update (read before editing `upgrade.php`)

A release can change the deployment tooling itself, so before deploying anything the upgrade compares four files between the staged archive and the live site:

```
utils/upgrade.php
utils/update_database.php
includes/DatabaseUpdater.php
includes/DeploymentHelper.php
```

Any that differ are copied to live immediately and the pipeline re-executes from the start, so a release is applied by its own tooling rather than by the previous version's.

The comparison happens twice, and both passes take all four together:

1. **Early** — straight out of the downloaded tarball, before staging is cleared or anything is extracted. A bug anywhere in the pipeline is then one upgrade attempt away from fixing itself.
2. **Post-extract** — again from the fully staged tree, so a file the tarball listing missed is still caught.

**All four move as one set, never individually.** They call each other, so refreshing a subset produces a new file running against an old API — which is the same breakage the self-update exists to prevent, arrived at from the other direction.

**The rule this imposes:** during that window the new `upgrade.php` is running against the **old** core — every file outside the set of four is still the previous release. Anything those four call must therefore already exist on the oldest site expected to upgrade, or travel inside that same set.

Calling a newly added core method from `upgrade.php` is the specific way this breaks, and it breaks hard: the re-run hits an undefined method and aborts *before* it can deliver the file that defines it. Every retry aborts identically, and the node needs files copied in by hand.

Two things guard against it. `isUpgradeServer()` lives on `DeploymentHelper` rather than a general core helper, so `upgrade.php`'s dependency travels with it. And `upgrade.php` calls it behind `method_exists()`, so a site that somehow reaches the re-run with an older helper degrades to "not an upgrade server" for one pass instead of fataling. Write new call sites the same way: **guard any deployment-set call whose target was added in the same release.**

**Dashboard surfaces (Server Manager):**

On any node detail page (`/admin/server_manager/node_detail?mgn_id=N`), the **Updates** tab exposes:

- **Apply Update** — single-site action, queues one `apply_update` job for that node.
- **Upgrade All Sites on This Host** — fans out to every enabled, non-deleted node sharing the same `mgn_host`. Queues one independent `apply_update` job per sibling (so a per-site failure doesn't affect the others), then redirects to the Jobs page. To skip a specific site in the bulk run, disable it (`mgn_enabled = false`) via its node detail page first.

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
- Each theme/plugin with `included_in_publish: true` gets its own versioned archive
- Prevents overwriting existing versions
- Refuses a release whose tree fails its tests (see Publish refusals below)
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

**Component version integrity:**

Each publish records a per-release snapshot of every published component's `(version, tree_hash)` in `upg_upgrades.upg_component_state` (JSON, keyed by `themes`/`plugins`). The snapshot of the most recent release row that carries one is the **baseline** for change detection; rows without a parseable snapshot are skipped, so an aborted publish never poisons the baseline. When no prior snapshot exists, the run re-baselines — records everything, bumps nothing.

The `tree_hash` is a deterministic, git-independent SHA-256 of the component's working tree (`.git/` and `.gitignore` excluded). The manifest (`theme.json` / `plugin.json`) is hashed with its `version` member removed, so the hash measures content-minus-version.

Per component, before archiving, the publisher applies a four-way decision against the baseline entry:

1. **No baseline entry** — first publish of this component: record as-is, no bump.
2. **Manifest version higher** — author bumped deliberately: respect and record.
3. **Manifest version lower** — record and archive as-is, with a warning line in the publish summary naming the component and both versions. Not aborted: a backward component version has no destructive effect at publish time, and any resulting `depends` violation is caught fail-closed at activation.
4. **Manifest version equal** — compare hashes. Equal → unchanged, carry the entry forward. Differ → **auto patch-bump** the manifest's `version` (targeted string edit, no reformatting) and archive under the new version.

Auto-bumped manifests are ordinary working-copy edits — the publish summary lists them so the maintainer can commit the change, the same workflow as the core `VERSION` file. Authors still bump minor/major for meaningful releases; auto patch-bump is the floor that keeps archive filenames honest when a content change ships without one.

**Publish log:**

Every publish writes the full text of its run to `{site root}/logs/publish/publish-{version}-{YmdHis}.log`, outside `public_html` so it is not web-served. The log is written by a shutdown handler rather than at the end of a successful run, so a publish that exits early — a refusal, a `die` on a missing prerequisite, a fatal error — is captured too. Those are the runs worth reading: a stage that reports a problem through the publish summary and continues leaves no other trace once the page or terminal is gone. The header records the version, whether the run was CLI or web, and which account it ran as; a fatal error is appended to the end. The newest 20 logs are kept and older ones removed on each publish. A successful run names its log path as the last line of the summary.

**Publish refusals:**

These conditions stop a publish before anything is written — no VERSION change, no archives, no release row:

1. The requested version is lower than the one already in the `VERSION` file.
2. `LICENSE.md` is missing or empty (every archive carries the license text).
3. A default install bundle names a plugin this release cannot carry.
4. **The tree fails its own tests.** The publisher runs the `deploy` tier (every deployable file compiles, the core classes load, the database answers, the manifests parse, the site answers over HTTP — seconds, all reads) and, where this site authored the code (`DeploymentHelper::mayMintReleaseVersion()`), requires the `safe` tier to have passed on this exact tree. The publisher is root on the local job queue and never runs a development tier itself; it checks the runner's PASS stamp (`cache/test_tier_stamp.json`, written by a full `php tests/run.php safe` as the site's user) against the tree on disk, and a refusal names the paths that differ — see [The PASS stamp](testing.md#the-pass-stamp-how-a-publish-knows-the-tree-was-tested). A relay republishing what it received runs only `deploy`, because the development tiers assert things about a checkout it is not — see [The deploy tier](#the-deploy-tier). Per-suite PASS lines are left out of the publish output; failures, skips and the summary are kept. There is no flag to skip the gate: the fix is the failing test or the code it caught.
5. This box holds agent source newer than the bundled agent artifact and the rebuild fails (see [Server Manager](../plugins/server_manager/docs/overview.md)).
6. The mailbox plugin's relay sealer binaries cannot be built.

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
3. DeploymentHelper preserves extensions marked `receives_upgrades: false`
4. Backup current installation to public_html_last/
5. Deploy staged files to public_html/
6. Sync staged maintenance_scripts/ into the site root
7. Run database migrations (update_database.php)
8. Run composer_install_if_needed.php
9. Fix permissions (www-data:user1, 775)

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

## Extension Distribution Flags

Themes and plugins carry two independent boolean flags in their manifests
(`theme.json` / `plugin.json`) that control distribution. Both default to `true`
when missing, and they govern different sides of the pipeline:

**Example manifest (theme.json or plugin.json):**
```json
{
    "name": "controld",
    "version": "2.1.0",
    "description": "ControlD DNS management plugin",
    "receives_upgrades": true,
    "included_in_publish": true
}
```

- **`receives_upgrades`** — *customer-side, deploy preservation.* If `true`, the
  on-disk copy is replaced from the upgrade payload during a deploy swap. If
  `false`, the live copy is preserved across the swap and `_reconcile_upgradable_assets.sh`
  will not re-download it. Mirrored to the database column
  `thm_receives_upgrades` / `plg_receives_upgrades` so the admin UI can
  toggle it; uploaded extensions are auto-set to `false` so a deploy doesn't
  wipe them.
- **`included_in_publish`** — *publisher-side, packaging filter.* If `true`,
  `publish_upgrade.php` packages this extension into the upgrade archive and
  `publish_theme.php`'s catalog endpoint advertises it. If `false`, it is
  skipped. Manifest-only — there is no DB column and no admin UI for this
  flag, since it has no meaning on a customer site.

If a manifest is missing, it's auto-generated with both flags `true`.

---

## update_database Behavior

### Advisory Lock

`update_database.php` uses a PostgreSQL advisory lock (`pg_try_advisory_lock(99999)`) to prevent concurrent runs. If a second process tries to run while one is already in progress, it exits immediately with "already running." The lock is released automatically when the database connection closes.

### Halt on Migration Failure

Migrations stop on the **first failure** — subsequent migrations are skipped. Fix the failing migration and re-run `update_database.php` to continue.

### Migration `test` Semantics

Each migration has an optional `test` SQL query that returns a row with a `count` column. The runner interprets it as:

- **`count > 0` → migration is skipped** (already applied)
- **`count = 0` → migration runs**

This works naturally for INSERT-style migrations — test whether the row already exists:

```php
// Insert a row — skip if it already exists
$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_name = 'my_template'";
$migration['migration_sql'] = "INSERT INTO emt_email_templates (emt_name, emt_body) VALUES ('my_template', '...')";
```

> **Note: do not use migrations to seed `stg_settings` rows.** Setting names and defaults are declarative — see "Declarative Settings (no migration)" below.

**Drop-table migrations require inverted logic.** If you test for the table's presence the same way, the migration is skipped *while the table still exists* — the opposite of what you want. Use a `CASE` expression to flip the sense:

```php
// Drop a table — run while table is present, skip once it's gone
$migration['test'] = "SELECT CASE WHEN EXISTS(
    SELECT 1 FROM pg_tables WHERE tablename = 'old_table' AND schemaname = 'public'
) THEN 0 ELSE 1 END as count";
$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.old_table CASCADE;';
```

The `CASE` returns 0 while the table exists (→ run) and 1 once it has been dropped (→ skip). The `DROP TABLE IF EXISTS` makes the migration idempotent — safe to run even if the table is already gone.

### Declarative Settings (no migration)

Setting names and defaults are declared, not migrated. Every `update_database` run reseeds them via `Setting::seed_declared()`, which uses `INSERT ... ON CONFLICT (stg_name) DO NOTHING` — existing rows are never overwritten, only missing ones are filled in.

- **New core setting** → add an entry to `public_html/settings.json` with a sensible default. Reseeded automatically on existing sites; included in `joinery-install.sql` for fresh installs.
- **New plugin-owned setting** → add an entry to the plugin's `plugin.json` under `settings`. Seeded by `PluginManager::syncSettings()` when the plugin is activated.
- **Changing the default value of an existing setting** → edit `settings.json` (or `plugin.json`). Existing sites keep whatever value they have (ON CONFLICT DO NOTHING). If you also need to *correct* a wrong value on existing sites, add an UPDATE migration with a tight WHERE clause (e.g. `WHERE stg_value = '<old default>'` so admin overrides aren't trampled).

**INSERT-into-`stg_settings` migrations are deprecated.** They duplicate what `seed_declared` already does, drift from the declarative source, and clutter migration history. UPDATE/DELETE migrations against `stg_settings` remain a valid tool — only INSERT-only seed migrations are off-limits.

The same principle applies to core admin/profile menu rows (declared in `public_html/admin_menus.json`) and plugin menu rows (declared in `plugin.json` under `adminMenu` / `profileMenu`).

### Column Defaults

A `default` in `$field_specifications` is enforced in both layers. `SystemBase::save()` applies it to unset fields on a new model row, and the updater declares it on the column itself — at table creation, on `ADD COLUMN` (Postgres backfills existing rows), and reconciled under `--upgrade` when a declared default is absent from the live column — so raw-SQL inserts get the same value the model path gets. The reconcile pass only *adds* missing defaults: a live default that differs from the declaration is reported as a drift warning and left unchanged, and a live default with no declared counterpart is left alone.

### Index Management

`update_database` reconciles ordinary (non-unique) indexes from the data class the same way it reconciles unique constraints. Declare an index and the run creates it; remove the declaration and a cleanup run drops it.

**Two declaration surfaces.** Plain btree indexes — the common FK / filter-column case — are declared inline in `$field_specifications`:

```php
'ord_usr_user_id' => array('type' => 'int8', 'index' => true),                 // single column
'cal_subject_type' => array('type' => 'varchar(32)', 'index_with' => array('cal_subject_id')),  // composite, in order
```

Anything beyond a plain btree goes in a table-level `$index_specifications` array:

```php
public static $index_specifications = array(
    array('columns' => array('cal_subject_id'), 'where' => 'cal_delete_time IS NULL'),   // partial
    array('columns' => array('prd_attributes'), 'method' => 'gin'),                       // method override
    array('columns' => array('LOWER(usr_email)')),                                        // expression
    array('columns' => array('usr_email'), 'unique' => true, 'where' => 'usr_delete_time IS NULL'), // partial-unique
);
```

Each entry: `columns` (required, array of bare names or SQL expressions), optional `method` (default `btree`), optional `where` (partial predicate, stored verbatim), optional `unique`.

**Division of labour.** Whole-table uniqueness stays with `unique` / `unique_with` (real `UNIQUE` constraints — FK-referenceable, read as constraints). Uniqueness scoped by a predicate, or over an expression, uses an `$index_specifications` entry with `unique => true` — a partial unique index expresses "unique among active rows," which a plain constraint cannot. The two never describe the same index.

**Naming.** Managed indexes get a deterministic, 63-char-safe name that is a complete fingerprint of the definition (columns and order, method, predicate, uniqueness), ending in the reserved suffix `_idx` (plain) or `_uidx` (unique). **Those suffixes are reserved for system-managed indexes — never hand-create an index ending in `_idx` / `_uidx`.** Because the name is a fingerprint, changing a definition produces a different name: the run creates the new index and a cleanup run drops the old one. There is no in-place recreate.

**Flag gating.** Create-missing runs when `cleanup || upgrade`; drop-obsolete runs only when `cleanup` — identical to unique constraints. So in plain `upgrade` mode a changed definition creates the new index immediately but leaves the stale one until the next `cleanup` run; the stale index is redundant, never wrong. A unique index whose data contains duplicates (scoped by its partial predicate when present) is skipped with a warning, never failing the run.

**Drop safety.** The obsolete-drop pass only touches an index when all three hold: its name carries the reserved suffix, it does not back a constraint and is not a primary key, and it is not in the class's declared set. Primary keys, constraint-backing indexes, and hand-made indexes are never dropped.

### Plugin Tables

The core table pass (`runCoreTablesOnly`) runs with `include_plugins => false`; plugin schema is managed by `PluginManager` (`DatabaseUpdater::runPluginTablesOnly()`), which core cannot enumerate on its own. `update_database` touches plugin schema at two points:

1. **Before migrations** — `PluginManager::syncTables()` creates missing tables and adds missing columns for every active plugin's data classes, and does nothing else. It runs after the core table pass (a plugin table may reference a core one) and before the migrations step, so a migration that reads or writes a new plugin column finds it on the first run.
2. **After migrations** — the Plugin & Theme Sync step runs `PluginManager::sync()`: the same additive pass again (a no-op by then), then column modifications, unique constraints, indexes, foreign keys, plugin migrations, deletion rules, menus and settings.

Plugin tables are also synced on plugin activation and from the admin Plugins page ("Sync with Filesystem").

### Agent File Regeneration

After migrations and plugin sync, `update_database` regenerates DB-managed agent files (`CLAUDE.md`, `GEMINI.md`, etc.) from the `agf_agent_files` table — the table is the source of truth, the on-disk files are generated output. Only rows previously written to disk (`agf_last_written_time IS NOT NULL`) are regenerated, so a never-written customer baseline row stays dormant until the customer opts in.

A drift guard protects out-of-band edits: if a target file on disk was changed since it was last written (its sha256 no longer matches `agf_last_written_hash`), the row is **skipped with a warning** rather than overwritten. Resolve a skipped row from `/admin/admin_agent_files` — writing from there prompts for confirmation and backs the on-disk copy up as `<filename>.old` before overwriting. See `specs/implemented/agent_files_management.md` for the full design.

### Agent File Upgrades (Customer Baseline)

The `default_agents_template.md` file ships inside the upgrade tarball. When `update_database` runs it compares the template's normalized SHA-256 against each Customer baseline row's `agf_template_baseline_hash`:

| Row state | Result |
|---|---|
| Baseline hash is null (pre-feature install) | Skipped — no surprise updates |
| Hash matches template | Already up to date, no action |
| Row content matches new template | Admin hand-applied the update; baseline hash bumped silently |
| Row content unchanged from its baseline | **Auto-upgraded** in place; new content and hash written; regeneration step picks it up |
| Row edited *and* template changed | A **candidate row** is created (or rolled forward if one already exists) |

The candidate appears in `/admin/admin_agent_files` with a **"Candidate for #N"** badge and an inline panel:

> **An updated agent template is available.** [Compare] [Switch to new version]

- **Compare** opens a read-only side-by-side view of the current content vs the candidate.
- **Switch to new version** moves target filenames from the active row to the candidate, archives the previously-active row (name prefixed `Archived — `, target filenames cleared), and writes the new content to disk.

There is at most one candidate per active row. Subsequent template versions roll the same candidate forward — the admin always sees "the latest upgrade is available," never a backlog.

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
DeploymentHelper::preserveExtensionsAcrossDeploy($stage_dir, $backup_dir, $verbose)
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

**Preserved Theme Overwritten:**
- Check manifest has `"receives_upgrades": false`
- Restore from public_html_last/ if needed

**Rollback Failed:**
- Check public_html_last/ exists
- Manually restore from backup
- Fix permissions after restore

**Infinite Redirect Loop (site behind Cloudflare):**
If a site sits behind Cloudflare and the CF SSL/TLS mode is set to **Flexible**, Cloudflare proxies to origin over plain HTTP. A naive certbot HTTP→HTTPS redirect would bounce forever between CF and origin, taking the site down.

The Joinery vhost bakes a `RewriteCond %{HTTP:CF-Visitor} !"scheme":"https"` guard into the redirect rule, so it cannot loop in any CF SSL mode (or with no CF at all). The admin Settings page also surfaces a yellow warning banner whenever the platform detects it's being served from Flexible-mode CF — so the misconfig is visible without needing to read logs.

---

## Apache Vhost

Every site installed by `install.sh` has the same Apache vhost shape, regardless of whether it sits behind Cloudflare, behind another CDN, or is exposed directly to the public internet, and regardless of whether the origin has its own TLS certificate.

**Shape.** Defined by template files in `maintenance_scripts/install_tools/` (single source of truth per deployment mode) — `default_proxy_vhost.conf` for Docker reverse-proxy sites, `default_virtualhost.conf` for bare-metal sites. `install.sh write_universal_vhost` substitutes the placeholders (`{{DOMAIN_NAME}}`, `{{SITE_NAME}}`, `{{PORT}}`, `{{SERVER_IP}}`) and writes the result to `/etc/apache2/sites-available/${sitename}.conf`.

- **Port 80** — proxies/serves traffic, plus a `RewriteRule` that redirects to HTTPS. The redirect carries a `CF-Visitor` guard so it cannot loop under CF Flexible mode and is a no-op when no CF is in front.
- **Port 443** — wrapped in `<IfFile /etc/letsencrypt/live/${domain}/fullchain.pem>`. Apache evaluates `<IfFile>` at config-parse time: if the cert exists, the `:443` vhost activates; if not, Apache silently skips the block. Sites with no origin cert just serve port 80, and whatever's in front (Cloudflare etc.) handles TLS at the edge.

**Origin SSL is opt-in.** `install.sh` runs `provision_origin_cert` once during install:

1. **Domain resolves to this server** → Let's Encrypt HTTP-01 challenge via `certbot --apache --no-redirect`.
2. **Domain resolves elsewhere** (Cloudflare, other CDN) and a matching DNS-API credentials file exists at `/etc/letsencrypt/<provider>.ini` → LE DNS-01 with the matching certbot plugin. Plugin map:

   | NS pattern                | certbot plugin              | credentials file                          |
   |---------------------------|-----------------------------|-------------------------------------------|
   | `*.ns.cloudflare.com`     | `certbot-dns-cloudflare`    | `/etc/letsencrypt/cloudflare.ini`         |
   | `awsdns-*`                | `certbot-dns-route53`       | `/etc/letsencrypt/route53.ini`            |
   | `ns[1-5].linode.com`      | `certbot-dns-linode`        | `/etc/letsencrypt/linode.ini`             |
   | `ns[1-3].digitalocean.com`| `certbot-dns-digitalocean`  | `/etc/letsencrypt/digitalocean.ini`       |

   Each plugin reads its credential file in its own standard format; certbot's docs cover the schemas.
3. **Neither path produces a cert** → install proceeds without origin SSL. The `:443` vhost stays dormant via the `<IfFile>` guard; CF or another front-end handles TLS.

**Adding a new DNS provider.** Edit `detect_dns_provider` in `install.sh` — add one `case` clause mapping the provider's NS-record signature to its tag, and document the plugin package + credential file format in the table above.

**Enabling origin SSL later** (e.g. after dropping a CF API token in place to switch a CF zone to Full strict): `sudo /var/www/html/<site>/maintenance_scripts/sysadmin_tools/setup_ssl.sh <domain>`. The script re-enters the decision tree; the `:443` vhost begins serving on the next Apache reload because the `<IfFile>` guard sees the new cert.

**A certificate deferred at install time arrives on its own.** When DNS is not pointing at the box yet, `arm_ssl_retry.sh` installs `joinery-ssl-retry@<domain>.timer`: every five minutes it checks whether the domain resolves to this server, does nothing until it does, then issues once and disables itself. A self-signed placeholder does not count as done. The DNS gate is what makes an indefinite retry safe — Let's Encrypt counts failed validations, not failed lookups. A restore that lands the site on a different domain re-arms the timer for the new name and disarms the old one.

---

## Rebuilding a site on new hardware

A backup does not carry `config/Globalvars_site.php` — that file holds the machine's database password and its `secret_box_key`, and both belong to the machine. So a rebuild is **install, then restore onto the installed site**:

```bash
# 1. On the new box: server prerequisites, then the site
sudo ./install.sh -y server
sudo ./install.sh -y site --bare-metal <site> --password-file=/root/.joinery_postgres_password <domain>

# 2. Bring the backup down and restore onto it
bash maintenance_scripts/sysadmin_tools/restore_project.sh <site> <archive> --domain <domain> --force
#    or, for a chain:
bash maintenance_scripts/sysadmin_tools/restore_chain.sh <site> \
     --artifacts <chain dir> --key-file /tmp/k --domain <domain> --force
```

The restore reconciles the result to the new box: the domain, the deployment shape (`docker` vs `baremetal`), the paths, a regenerated virtualhost, and an armed certificate retry. It **refuses** if the restored database will not open with the new machine's credentials, rather than leaving that to show up as `SQLSTATE[08006]` on every page. What it reconciles and why is in [Backups](backups.md#what-a-restore-reconciles).

Cross-shape rebuilds work in both directions with no extra step: a container backup landing on a plain server, or the reverse. Neither installs the virtualhost the backup carries — see the same section.

**From the dashboard**, the equivalent is a Server Manager **install_node** job in From-Backup mode (a fresh install plus the source's data, cloned in one job), or a **restore_project** / **restore_chain** job against a node that already has a site. Both reconcile identically.

**A PostgreSQL major-version jump needs nothing special, upwards.** The dump-and-restore path crosses it: a PG 16 dump restores onto PG 18 as an ordinary restore.

**Downwards it is refused.** A dump carries the syntax of the version that wrote it, so a newer dump cannot load into an older server. The restore reads the version out of the dump header and stops before touching the target database, reporting `RESTORE_SERVER_TOO_OLD`. The target must run a PostgreSQL at least as new as the source — which for a container target means a base image carrying it.

---

## Configuration

**Required settings** (in `/config/Globalvars_site.php` or stg_settings):

| Setting | Description |
|---------|-------------|
| `baseDir` | Base directory (e.g., `/var/www/html/`) |
| `site_template` | Site directory name (e.g., `joinerytest`) |
| `system_version` | Current version (e.g., `3.25`) |
| `upgrade_source` | URL of upgrade server to download from (e.g., `https://getjoinery.com`) |
| `root_node` | Domain of the deployment this code is written and published from. Empty everywhere else. Machine-written, not shown on the settings page. |
| `composerAutoLoad` | Composer vendor path |

**Note:** A site acts as an upgrade server when the **Server Manager** plugin is active or the `upgrade_server_active` setting is on. `DeploymentHelper::isUpgradeServer()` answers that question; use it rather than re-deriving the pair, so publishing and consuming behaviour cannot drift apart. The `upgrade_source` setting specifies where a site *downloads* upgrades from.

The distinction matters beyond the upgrade endpoint: any control that edits a file the upgrade replaces wholesale belongs only on a publishing instance, because on a consuming site the edit is discarded at the next upgrade. Version-controlled manifests like Joinery AI's [recipes.json](../plugins/joinery_ai/docs/overview.md#shipped-recipes) are for the same reason edited only on the publishing checkout.

### PostgreSQL memory

`maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh` sizes PostgreSQL from the RAM
the machine actually owns and writes the result as `conf.d/20-joinery-memory.conf`:
`shared_buffers` at 20% of RAM (floor 64MB, cap 2GB) and `effective_cache_size` at 50%. It
is idempotent, writes nothing when the drop-in already matches, and restarts the cluster
unit (`postgresql@{version}-main`) only when it wrote. `--dry-run` prints what it would
write; `--no-restart` writes without restarting.

"RAM the machine owns" is the whole question, and on a shared host only the container knows
the answer. The budget is resolved in one order, and the script skips rather than guesses:

| Source | When it applies |
|---|---|
| `--ram-mb=N` | Always wins when given |
| The cgroup limit | The container has a memory limit |
| `MemTotal` | This is not a container |
| *skip, exit 3* | A container with no memory limit |

The last row is the case with no honest answer: `/proc/meminfo` reports the host's memory,
which is not the container's to size from, and every container on that host reads the same
figure — eight containers each taking 20% of one host claim 160% of it. PostgreSQL keeps its
packaged settings until the container is given a budget.

**Where it runs.** On bare metal, `install.sh server` runs it during the install: that host is
the machine. On Docker it runs from the container start command on every start, before
PostgreSQL starts, so the value applies to the first postmaster. It is deliberately not part
of the base image — `install.sh server` is what `docker build` runs to bake that image, so a
figure computed there would be the build host's RAM, frozen into the image and shipped to
every container on every host — and deliberately not in `_site_init.sh`, which runs only on
first boot while `/etc/postgresql` is reset by a container rebuild.

**Giving a container a budget.** `install.sh site --memory=SIZE` (Docker's syntax: `512m`,
`2g`) sets the limit at creation, and `--memory-swap` is pinned to the same figure so the cap
is not doubled by swap. An already running container takes one with
`docker update --memory=512m --memory-swap=512m NAME`, and picks up the sizing at its next
start. Containers are unlimited by default: a limit that arrives uninvited OOM-kills a site
that was fitting fine, so it is a decision for whoever knows how many sites share the host.
On any host running more than one site it is worth setting — it is the only thing that tells
each container what share of the host is its own.

### The deploy tier

`php tests/run.php deploy` is the set `upgrade.php` runs after a swap. It pulls in no other tier and no other tier pulls it in.

It exists because `safe`, `db` and `test-db` are **development** gates. They run in a checkout and are entitled to assert things about one: that the full first-party plugin set is present, that the components manifest lists what the repository holds, that `maintenance_scripts` has the layout the installer expects. A deployed site has none of that and never should — it carries the plugins it uses and no repository around it. Eleven of those suites fail on a production node, for reasons that say nothing whatever about the release.

So `deploy` asks the only question that means anything on a node: **does the code that just landed run on this machine.**

| Check | What it catches |
|---|---|
| `deploy_syntax_sweep` | Every deployable PHP file compiles. The failure in scope: an edit left half-finished on the publishing box, in a file loaded on one page, waiting for someone to visit it. |
| `deploy_bootstrap` | Core classes load, the database is reachable, `settings.json` / `admin_menus.json` / `install_bundles.json` parse, the licence shipped. Catches a class that moved between core and a plugin, or a require pointing at a path the release removed. |
| `deploy_site_responds` | The homepage and sign-in page come back without a 5xx, through Apache and the theme. The only check that exercises the web SAPI. |

Three rules for anything added to it, all of them learned the hard way:

- **No assumption of a repository.** Not the plugin set, not the theme set, not a git checkout, not a sibling directory.
- **Reads only.** It runs on production, after a swap, with a rollback hanging on the result.
- **An unreachable dependency is a SKIP, not a failure.** Reverting a working release because a socket would not open is worse than the thing being guarded against.

The sweep compiles rather than lints: `opcache_compile_file()` parses without executing, doing the whole tree in about a second where `php -l` per file takes over a minute. One process shares one symbol table, so two files that legitimately declare the same function name collide — anything that fails the fast pass is re-checked with an isolated `php -l` before it counts.

### Where a site's `upgrade_source` comes from

It is not a decision anyone makes twice. `_site_init.sh` writes it at install time from the endpoint the install actually fetched its code from — `install.sh`'s `UPGRADE_SERVER`, which defaults to `https://getjoinery.com` and is overridden with `--upgrade-server=URL`. One rule covers both audiences: leave the flag off and the site tracks stable releases; pass it and the site follows wherever it was installed from.

Clones are the exception: `UPGRADE_SERVER` points at the clone source for the duration of a clone, and that is a peer site rather than a release endpoint, so the cloned database keeps the source's own `upgrade_source`.

### The root node

One deployment is the origin of the estate: the code is written there, and the
themes and plugins are published from there. The `root_node` setting names it
by domain — `dev.getjoinery.com` — and a site is the origin when its own
`webDir` matches that name. It is machine-written and never rendered on the
settings page, because it is a fact about the estate's shape rather than a
preference anyone tunes.

Naming it by domain rather than raising a flag is what makes a clone or a
restored backup safe. The copy carries the same value, which still names the
origin, and the copy correctly concludes it is not the origin itself.

Five behaviours follow from it, each fixing a state that has no sensible
reading otherwise:

- **It serves its own catalog.** `MarketplaceClient::source()` answers with
  the site itself rather than `upgrade_source`, so the Marketplace lists what
  is actually on this disk. `upgrade_source` records where a site was
  installed from, which on the origin is a site running an older copy of this
  very tree — reading a catalog from it offers to install yesterday's theme
  over today's.
- **It sees every extension.** An `audience` naming a customer's sites does
  not have to also name the origin; see
  [Plugin Developer Guide](/docs/plugin_developer_guide.md).
- **It refuses to upgrade itself.** `upgrade.php` stops before its first fetch,
  because applying the last release published from here would replace new work
  with an older copy of itself. Serving upgrades to other sites is a different
  branch and is unaffected — the origin still publishes normally.
- **It always may mint a release number.** Everywhere else that is decided locally, by whether the tree has moved past what upstream delivered; on the origin it must not depend on anything — see [The release channel](#the-release-channel).
- **It refuses to install over itself.** Everything in its catalog is already
  on its disk, so a marketplace install, or the upstream refresh a plugin
  install performs, could only overwrite the working copy with an archive of
  itself. The publisher caches archives per version, so an edit made without a
  version bump would be replaced by the code it replaced.

Every other site leaves `root_node` empty, which is the ordinary case and
changes nothing: empty answers every question it is asked with "not the
origin", so there is nothing to configure on a node and nothing that can be
forgotten there.

### The release channel

`getjoinery.com` serves stable releases. It is a site rather than a flag on a row: no schema, no promotion state to forget, and it reuses the chaining `utils/latest_release` already implements.

A release reaches it by being published *there*, and `publish_upgrade.php` builds its archive from the tree of the site it runs on. So promotion is three steps, done by hand:

1. Publish on dev.
2. `upgrade.php` brings getjoinery to that build.
3. Publish on getjoinery.

**Only the site that authored the code may mint a release number.** `DeploymentHelper::mayMintReleaseVersion()` answers that from a local fact — whether the running `VERSION` is still exactly what `upgrade_received_version` says upstream delivered — and the publisher asks before anything else: a site running what it was handed republishes that number, and refuses a version that differs from it. So step 3 takes release notes and nothing more; the number is already decided by what step 2 delivered.

The question is deliberately local. Asking which site is the origin would mean every deployment had to be told, and a deployment that was never told reads as free to mint — so the rule would fail exactly where nobody had thought to apply it. The one exception is the origin itself, which always may mint: its `root_node` is already set, and its authorship should not hang on never having received an upgrade — a restore or one accidental `upgrade.php` run would otherwise strand it. A site that has received something but cannot read its running version at all is refused; minting is the unrecoverable direction.

```bash
# on getjoinery, after upgrade.php brings it to 0.8.199
php plugins/server_manager/includes/publish_upgrade.php "release notes"
# -> Republishing 0.8.199 -- this deployment is running exactly what it received, not new work.
```

The rule guards a failure with no local symptom. Auto-detect reads the next patch from `VERSION`, so upgrade-to-0.8.199-then-publish would emit 0.8.200 carrying 0.8.199's code, and dev's next publish would mint 0.8.200 from a different tree. Two archives, one number, and nothing notices: the downgrade guard rejects only *lower*, and the duplicate check reads the local `upg_upgrades` table, which does not hold the other site's rows. The check sits ahead of the auto-detect block precisely because an explicitly supplied version skips that block — a guard inside it would protect only the case nobody gets wrong. The dashboard's publish form is covered too: on a site that may not mint, it offers the running version read-only rather than the next patch.

A site that has never received an upgrade mints freely, as does one whose tree has moved past what it received. That is every independent deployment: it authors its own code, and it is configured with nothing.

The ordering earns something beyond housekeeping: getjoinery is running the code it serves, so a build reaching strangers has at least come up on a real site first.

getjoinery keeps `upgrade_source = https://dev.getjoinery.com` permanently, which is not circular — upgrades flow *into* getjoinery from dev, releases flow *out of* getjoinery to the world.

`latest_release` also accepts `?version=X.Y.Z` to serve one specific published release, for reproducing a build. Installers use plain `latest_release`: a pinned installer needs a bump on every publish, and a stale pin hands out old code to people who asked for current code.

---

## Marketplace

The marketplace admin page lets superadmins browse themes and plugins available on the upgrade server and install them with one click. It is the primary way a site adds plugins and themes — the ZIP upload on the Plugins/Themes admin pages is the secondary path, for custom extensions that are in no catalog. Because node upgrades only refresh extensions already on disk, the marketplace is how a site *acquires* one it doesn't have.

**Admin Page:** System > Marketplace, `/admin/admin_marketplace` (permission level 10)
**Files:** `adm/admin_marketplace.php`, `adm/logic/admin_marketplace_logic.php`, `includes/MarketplaceClient.php`

The client is core and ships to every site; only the *source* site needs the server_manager plugin (it serves the catalog). The same operations are exposed as API actions: `marketplace_catalog` (read) and `marketplace_install` (write), both superadmin-floored — `logic/marketplace_catalog_logic.php`, `logic/marketplace_install_logic.php`.

### How It Works

1. `MarketplaceClient` fetches the catalog from the upgrade server (`publish_theme?list=themes` and `?list=plugins`)
2. Compares with locally installed themes/plugins
3. Shows a card grid with install buttons for items not yet installed
4. Install downloads the tar.gz archive and extracts it via `AbstractExtensionManager::installFromTarGz()`
5. After install, files are on disk and synced to the database — user must activate separately via Themes or Plugins admin page

### Prerequisites

- `upgrade_source` setting must be configured (URL of the upgrade server), or
  the site is the origin named by `root_node` and serves its own catalog
- The upgrade server must have the **Server Manager** plugin active

### Overwrite Protection

- **Extensions with `receives_upgrades: true`** (or those without a manifest) can be reinstalled/replaced from the marketplace
- **Extensions with `receives_upgrades: false`** are protected — the marketplace refuses to overwrite them

### Catalog Endpoint Fields

The `publish_theme.php` catalog endpoints (`?list=themes`, `?list=plugins`) include:
- `name` — display name (unchanged for backward compatibility)
- `directory_name` — filesystem directory name (used for matching and downloads)
- `display_name`, `version`, `description`, `author`, `is_system`, `included_in_publish`

---

## Related Documentation

- **[CLAUDE.md](/CLAUDE.md)** - System architecture and development guidelines
- **[Plugin Developer Guide](/docs/plugin_developer_guide.md)** - Plugin development
- **[Server Manager](/plugins/server_manager/docs/overview.md)** - Server management, publishing, and backup targets
- **Specifications:**
  - `/specs/implemented/upgrade_system.md` - Feature parity analysis
  - `/specs/implemented/fix_publish_upgrade_system.md` - Publish upgrade fixes
  - `/specs/implemented/theme_plugin_distribution_refactor.md` - Separate archive distribution
  - `/specs/implemented/server_manager_publish_upgrade.md` - Moving publish/upgrade into server_manager plugin
  - `/specs/implemented/upgrade_graceful_theme_download.md` - Graceful handling of missing archives

---

*Last Updated: 2026-08-22*
