# Component Version Integrity

## Problem

An audit of the theme/plugin versioning system against `specs/implemented/versioning_rationalization.md` found the core release versioning (VERSION file, `system_version` self-heal, publish/upgrade flow, downgrade guard) working as designed, but the per-component layer broken in five ways:

1. **Component versions are not incremented when components change.** The prior spec made bumping `theme.json` / `plugin.json` versions "the author's responsibility" with no mechanism behind it. Git history shows the result: every component has unreleased commits since its last version bump — `server_manager` 50 commits since its version last moved, `joinery-system` 13, `inbound_email` 16, `dns_filtering` 10, `bookings` 10. Each publish wipes and regenerates archives, so `scrolldaddy-1.0.0.tar.gz` ships different contents on every release under the same name. The version number no longer identifies anything.

2. **`ThemeManager::updateExistingMetadata()` never refreshes manifest-derived columns.** It syncs only `thm_receives_upgrades` and `thm_is_system`, so `thm_version`, `thm_display_name`, `thm_description`, `thm_author`, and `thm_metadata` are frozen at first-install values. Current drift on this box: `default` 1.0.0 in DB vs 1.1.0 in manifest; `empoweredhealth-html5` 1.0.1 vs 1.0.2; `jeremytunnell-html5` 1.0.0 vs 1.0.1.

3. **`PluginManager::updateExistingMetadata()` never refreshes `plg_metadata`.** It is written once at install. Current drift: `inbound_email` 1.5.0 in DB vs 1.15.0 in manifest; `server_manager` 1.0.0 vs 1.2.1. This is load-bearing: the dependency version check in `PluginManager::validatePlugin()` (~line 454) reads the *dependency's* version from stale `plg_metadata`, so a plugin declaring `depends: {inbound_email: ">=1.10.0"}` is wrongly refused activation. The method also returns no boolean where `sync()` expects one (the "N plugin(s) updated" count is always zero) and saves the model unconditionally on every sync.

4. **Theme activation has no requirements gate.** `versioning_rationalization.md` step 7 required the admin theme-set flow to validate requirements before activation. `PluginManager::onActivate()` got this (via `validatePlugin()`, fail-closed); `ThemeManager::onActivate()` got nothing. `ThemeHelper::validate()` is still called only by `utils/test_components.php`.

5. **Dead "updates" UI on the plugins admin page.** "Check for Updates" is a stub that prints "will be implemented in a future update" (`adm/logic/admin_plugins_logic.php:62`). The "Updates Available" alert and the `available_version` badge in `adm/admin_plugins.php` reference a `$plugin_updates` variable that is never assigned anywhere — that UI can never fire.

## Alignment with the Git Hosting spec

`specs/git_hosting.md` reshapes where component source lives, and this spec's design is constrained by it:

- **Change detection must not depend on git.** After git_hosting Phase 2, `/plugins/*` and `/theme/*` leave the core monorepo and become independent repos; on a normal dev's box they may not be git repos at all. "Diff the core repo since the last publish" is therefore the wrong foundation. Detection happens at the publish layer using **content hashes of the working tree**, which works identically for monorepo, per-component-repo, and no-git layouts.
- **Hashing excludes what archives exclude.** git_hosting P1.8 adds `--exclude=.git --exclude=.gitignore` to the per-component `tar` commands in `publish_upgrade.php`. The content hash uses the same exclusion set, so a `.git/` appearing inside a component directory (Phase 1 test plugin onward) never reads as a content change.
- **Auto-bump writes manifest files, which is compatible with per-component repos.** When publish bumps a `plugin.json` / `theme.json`, that is an ordinary working-copy edit — under git_hosting it shows up as "1 file changed" on the component's row, and the author commits it. This is the same "the script writes it, author commits" pattern `versioning_rationalization.md` already established for the core `VERSION` file.
- **The publish/upgrade last mile stays untouched.** git_hosting explicitly excludes changing `publish_upgrade.php` → archives → `upgrade.php` consumption. This spec only adds version bookkeeping inside the publish step; archive format, the published-archives manifest, and consumer behavior are unchanged in shape (filenames simply carry honest versions again).

## Touchpoint inventory (decided once)

Every place a component version is written or read, and what this spec does with it:

| Touchpoint | Role | Disposition |
|---|---|---|
| `theme.json` / `plugin.json` `version` | Source of truth | Authors bump minor/major for meaningful releases; publish auto-bumps patch when content changed and the author didn't bump (below) |
| `publish_upgrade.php` archive filenames `{name}-{version}.tar.gz` | Writer/reader | Unchanged mechanism; now always honest because of the publish-time check |
| `cpr_component_releases` (new) | Publish-time ledger of what shipped | Added by this spec; the baseline for change detection |
| `upgrade.php` `get_published_archives()` (parses version from filename) | Reader | Unchanged |
| `upgrade.php` component status table (`Version` column) | Display | Unchanged |
| `adm/admin_plugins.php` via `Plugin::get_version()` | Display | Already reads the live manifest — correct, unchanged |
| `adm/admin_themes.php` (reads `theme.json` directly) | Display | Already live — correct, unchanged |
| `PluginManager::validatePlugin()` dependency version check | Enforcement | Fixed to read the dependency's live manifest via `Plugin::get_version()` instead of stale `plg_metadata` |
| `plg_plugins.plg_metadata` `version` key | Cache | Refreshed on every sync (merge-preserving, below) |
| `thm_themes.thm_version` (+ display name, description, author, metadata) | Cache | Refreshed on every sync. Alternative of dropping the column rejected: the row should carry a truthful manifest snapshot for orphan/"Missing" rows whose directory is gone, and the fix is the same few lines either way |
| `adm/admin_plugins.php` `available_version` badge + "Updates Available" block + "Check for Updates" stub | Dead code | Removed |
| Theme activation (`ThemeManager::onActivate()`) | Enforcement gap | Gated on requirements, mirroring plugins |

## Design

### 1. Publish-time version integrity (the headline fix)

The root cause of non-incrementing versions is that nothing connects "this component's content changed" to "this component's version must move." Relying on author discipline (human or agent) is the demonstrated failure. The right layer is the publish step — the moment a version becomes a public artifact name.

**New table: `cpr_component_releases`** — data class `plugins/server_manager/data/component_release_class.php` (`ComponentRelease` / `MultiComponentRelease`). This is publisher bookkeeping, so it belongs to the server_manager plugin (the publisher); `PluginManager::sync()` creates the table from `$field_specifications`.

| field | type | notes |
|---|---|---|
| `cpr_component_release_id` | int8 serial | pk |
| `cpr_component_type` | varchar(16) | `theme` \| `plugin` |
| `cpr_component_name` | varchar(128) | directory name |
| `cpr_version` | varchar(20) | version as published |
| `cpr_content_hash` | varchar(64) | sha256 of the component tree (below) |
| `cpr_upg_upgrade_id` | int8 | core release this shipped with |
| `cpr_create_time` | timestamp(6) | default now() |

**Content hash.** A static helper `ComponentContentHash::hashTree($dir)` in `plugins/server_manager/includes/ComponentContentHash.php`: walk the component directory, take every regular file except the archive-exclusion set (`.git/`, `.gitignore` — same set as the P1.8 tar excludes), sort by relative path, and sha256 the concatenation of `relative_path . "\0" . sha256(file_contents)` entries. Deterministic across boxes; ignores permissions, mtimes, and ordering.

**Decision rule, applied per component inside the existing theme and plugin archive loops in `publish_upgrade.php`, before each `tar`:**

Let `last` = the most recent `cpr_component_releases` row for this (type, name), `manifest_version` = the version currently in the manifest.

1. **No `last` row** (first publish under this system, or a new component): record `(manifest_version, current_hash)`, archive as-is. No bump.
2. **`manifest_version` > `last.version`**: the author bumped deliberately. Respect it; record; archive.
3. **`manifest_version` < `last.version`**: version went backward. Abort the publish with a clear per-component message (same posture as the core VERSION downgrade guard).
4. **`manifest_version` == `last.version`**: compute `current_hash`.
   - Hash equal → unchanged component. Archive as-is; no new ledger row.
   - Hash differs → **auto-bump the patch number** in the manifest file, then recompute the hash (the manifest is part of the tree), record the new `(version, hash)`, and archive. Output one line per auto-bump: `- scrolldaddy: content changed since 1.0.0, auto-bumped to 1.0.1`.

The version-equality check comes first deliberately: it means the hash never needs the manifest's version field normalized out (when versions are equal, the field contributes identically to both hashes; when they differ, the hash isn't consulted).

**Manifest write.** The auto-bump edits only the `version` value via targeted string replacement of the existing `"version" : "..."` member (tolerant of whitespace), never a full `json_decode`/`json_encode` round-trip — re-serializing would churn the whole file's formatting in the component's own repo. If the pattern can't be found (e.g. version key absent), abort with a clear message rather than guessing.

**Ordering inside the loop:** read manifest → apply decision rule → (maybe) write bump → compute final hash → `tar` → insert ledger row. The archive always contains the manifest it was named after.

**Publish summary output** gains a section listing auto-bumped components, so the maintainer knows which manifest edits to commit (identical workflow to committing the `VERSION` file bump).

After this lands, the CLAUDE.md guidance ("increment version numbers when making changes") remains the preferred authoring behavior — auto-bump is the floor that keeps the system honest when authors forget, not a replacement for meaningful minor/major bumps.

### 2. Sync refreshes manifest-derived DB state

**`ThemeManager::updateExistingMetadata($model, $name)`** — rewritten to read the manifest once and diff-and-set all manifest-derived columns: `thm_display_name`, `thm_description`, `thm_version`, `thm_author`, `thm_metadata`, `thm_receives_upgrades`, `thm_is_system`. Save only when something changed; return that boolean (the existing contract `sync()` expects).

**`PluginManager::updateExistingMetadata($model, $name)`** — refresh `plg_metadata` from the live manifest with a **merge that preserves runtime keys**: keys prefixed with `_` (currently `_menu_slugs`, written by `saveMenuSlugsToMetadata()`) are carried over from the existing stored metadata; everything else is replaced by the manifest contents. Also refresh `plg_is_system` and `plg_receives_upgrades` as today. Save only on change; return the boolean (fixes the always-empty "updated" list and the unconditional save).

### 3. Dependency version check reads live state

In `PluginManager::validatePlugin()`, the `depends` version constraint check switches from `json_decode($dep_plugin->get('plg_metadata'))['version']` to `$dep_plugin->get_version()` (which already reads the dependency's live `plugin.json`). The cache refresh in §2 keeps `plg_metadata` honest for its other consumers, but enforcement should never read a cache when the source file is on the same disk.

### 4. Theme activation requirements gate

`ThemeManager::onActivate($name, $model, $dblink)` gains a requirements check before any state mutation, mirroring the plugin path: instantiate `ThemeHelper` for the theme and call `validate()` (which runs `ComponentBase::checkRequirements()` — already fail-closed against the VERSION file). On errors, throw with the specific failures joined into the message; the existing transaction wrapper in `AbstractExtensionManager::activate()` rolls back and `admin_themes_logic.php` already surfaces the exception message. Grandfathering needs no code: the gate runs only on activation, so an already-active theme that newly fails requirements keeps running, exactly as the rationalization spec prescribed. The unmet-requirements warning badge on `/admin/admin_themes` already exists.

### 5. Remove dead updates UI

In `adm/admin_plugins.php`: remove the `available_version` badge branch and the `$plugin_updates` "Updates Available" block (the variable is never set). In `adm/logic/admin_plugins_logic.php` remove the `check_updates` action stub, and remove the "Check for Updates" entry from `$altlinks`. Update availability for managed nodes is the Server Manager node Updates tab's job; a consumer-site-facing "update available" surface, if ever wanted, would be driven by the source's published-archives manifest and is out of scope here.

## Edge cases

- **First publish after this ships:** every component hits rule 1 (no baseline) — versions recorded as-is, no bumps. Enforcement begins with the second publish.
- **Component skipped from publish** (`included_in_publish: false` or `deprecated`): skipped entirely — no hash, no ledger row, exactly as it is skipped from archiving today.
- **Author bumps version without changing anything else:** rule 2 — respected and recorded (the bump itself is a content change in any case).
- **Manifest-only change** (e.g. description edit, same version): hash differs → auto patch-bump. Correct: the archive contents changed.
- **Renamed component:** appears as a new name (rule 1). Old name's ledger rows remain as history; the existing archive-wipe on publish already handles the stale archive file.
- **CLI and web publish paths:** the loops are shared, so both paths get the behavior; abort messages go through the existing `publish_output()` helper.

## Files to modify

| File | Change |
|---|---|
| `plugins/server_manager/data/component_release_class.php` | New — `ComponentRelease` / `MultiComponentRelease` data class |
| `plugins/server_manager/includes/ComponentContentHash.php` | New — deterministic tree hash helper |
| `plugins/server_manager/includes/publish_upgrade.php` | Decision rule + manifest bump + ledger insert in both archive loops; summary output |
| `includes/ThemeManager.php` | `updateExistingMetadata()` full refresh; `onActivate()` requirements gate |
| `includes/PluginManager.php` | `updateExistingMetadata()` merge-refresh + boolean; `validatePlugin()` dependency check via `get_version()` |
| `adm/admin_plugins.php` | Remove dead updates UI |
| `adm/logic/admin_plugins_logic.php` | Remove `check_updates` stub |

Bump the `@version` header on each modified file. No migrations: the new table comes from `$field_specifications` via plugin sync. Verify the `cpr_` prefix is unused before building.

## Testing

1. **Baseline publish:** run a publish; every published component gets a `cpr_component_releases` row matching its manifest version; no manifests modified.
2. **No-change publish:** publish again with nothing touched; no bumps, no new ledger rows, archives regenerate under the same names.
3. **Content-change publish:** edit one file in a theme and one in a plugin; publish; both manifests show a patch bump, output names them, ledger gains two rows, archives carry the new filenames.
4. **Manual bump respected:** set a plugin's version to the next minor, change a file, publish; the manual version is kept (no double-bump) and recorded.
5. **Regression refused:** set a version below its last ledger entry; publish aborts naming the component and both versions.
6. **`.git` insensitivity:** create a `.git/` directory inside a component (git_hosting Phase 1 scenario), publish twice with no other change; second publish reports no change.
7. **Sync refresh:** with the known-stale rows on this box (`default` theme, `inbound_email` plugin), run "Sync with Filesystem" / `update_database`; `thm_version` and `plg_metadata` versions match manifests; `_menu_slugs` in `plg_metadata` survives.
8. **Dependency check:** with `plg_metadata` artificially stale, a plugin declaring `depends: {inbound_email: ">=1.10.0"}` activates successfully (live manifest 1.15.0 satisfies it).
9. **Theme activation gate:** set a test theme's `requires.joinery` to `>=99.0.0`; activation is refused with the specific failure in the admin message; removing the floor allows activation; the currently-active theme is unaffected throughout.
10. **Dead UI gone:** plugins admin page renders with no "Check for Updates" option and no updates alert; no PHP notices.

## Documentation updates

- `docs/deploy_and_upgrade.md` — publish behavior: per-component release ledger, the four-way decision rule, auto patch-bump and the abort-on-regression guard, and that auto-bumped manifests are working-copy edits the maintainer commits.
- `docs/plugin_developer_guide.md` — component versioning: authors bump minor/major for meaningful releases; publish auto-bumps patch when content changed without a bump; activation (plugins *and* themes) is gated on `requires`; dependency version constraints are evaluated against the dependency's live manifest.

Per documentation rules, both docs describe the end state only — no references to the prior behavior.

## Out of scope

- Consumer-site "update available" surfaces (the removed dead UI is not replaced).
- Version-gating downloads in `upgrade.php` — consumers continue to refresh all published extensions by name; honest versions are bookkeeping and identity, not a download filter.
- Signing or integrity-verifying archives.
- Any change to git_hosting's own scope (the P1.8 tar excludes land with that spec; this spec only mirrors the exclusion set in the hash).
