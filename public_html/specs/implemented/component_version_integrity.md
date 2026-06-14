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
- **Hashing excludes what archives exclude.** git_hosting P1.8 adds `--exclude=.git --exclude=.gitignore` to the per-component `tar` commands in `publish_upgrade.php`. The content hash uses the same exclusion set, so a `.git/` appearing inside a component directory (Phase 1 test plugin onward) never reads as a content change. **Ordering note:** git_hosting is still active (unimplemented) as of this spec, and the per-component `tar` commands (theme loop and plugin loop) do **not** yet carry those excludes — they land with P1.8. This spec's hash exclusion can ship independently and ahead of P1.8; doing so is harmless because the hash stays internally consistent (it ignores `.git`/`.gitignore` on every run, so change detection is correct regardless). The only interim gap is cosmetic: until P1.8, the archives still *contain* `.git`/`.gitignore` while the hash ignores them, so "hashing excludes what archives exclude" is fully true only once P1.8 has landed. No code in this spec depends on the tar excludes existing first.
- **Auto-bump writes manifest files, which is compatible with per-component repos.** When publish bumps a `plugin.json` / `theme.json`, that is an ordinary working-copy edit — under git_hosting it shows up as "1 file changed" on the component's row, and the author commits it. This is the same "the script writes it, author commits" pattern `versioning_rationalization.md` already established for the core `VERSION` file.
- **The publish/upgrade last mile stays untouched.** git_hosting explicitly excludes changing `publish_upgrade.php` → archives → `upgrade.php` consumption. This spec only adds version bookkeeping inside the publish step; archive format, the published-archives manifest, and consumer behavior are unchanged in shape (filenames simply carry honest versions again).

## Touchpoint inventory (decided once)

Every place a component version is written or read, and what this spec does with it:

| Touchpoint | Role | Disposition |
|---|---|---|
| `theme.json` / `plugin.json` `version` | Source of truth | Authors bump minor/major for meaningful releases; publish auto-bumps patch when content changed and the author didn't bump (below) |
| `publish_upgrade.php` archive filenames `{name}-{version}.tar.gz` | Writer/reader | Unchanged mechanism; now always honest because of the publish-time check |
| `upg_upgrades.upg_component_state` (new column) | Per-release snapshot of each component's (version, tree hash) | Added by this spec; the latest release's snapshot is the baseline for change detection |
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

**Per-release component snapshot: `upg_upgrades.upg_component_state`** — a new `text` field on the existing `Upgrade` data class (`data/upgrades_class.php`), holding a JSON snapshot of every published component's `(version, tree_hash)` at that release, keyed by type and name:

```json
{
    "themes":  { "scrolldaddy": { "version": "1.0.1", "tree_hash": "ab12…" } },
    "plugins": { "inbound_email": { "version": "1.15.0", "tree_hash": "cd34…" } }
}
```

The state belongs to the release, so it lives on the release row: `upg_upgrades` already records one row per publish, created by the same script that needs the state. **Baseline rule:** the snapshot of the most recent release row that carries one (`MultiUpgrade` ordered by id desc, skipping rows with an empty or unparseable snapshot); if no row carries one — every pre-existing row when this ships — the run re-baselines: records everything, bumps nothing, same as a first publish. The new row's snapshot is written once the archive loops complete. No new table, no new class — the column arrives via `$field_specifications` and `update_database`.

This placement gives three properties for free:

- **History** — every release records exactly which component versions shipped with it.
- **Coherent deletion** — the publish page's existing delete action removes a release row, and the baseline naturally falls back to the previous release's snapshot: un-publishing a release un-publishes its component state.
- **Safe degradation** — corrupt or absent snapshots are skipped by the baseline rule, never fatal.

**Content hash.** A helper function `component_tree_hash($dir)` added to `publish_upgrade.php` alongside its existing helpers (`getDirContents()`, `create_zip()`): walk the component directory, take every regular file except the archive-exclusion set (`.git/`, `.gitignore` — same set as the P1.8 tar excludes), sort by relative path, and sha256 the concatenation of `relative_path . "\0" . sha256(file_contents)` entries. The manifest file (`theme.json` / `plugin.json`) is hashed with its `version` member excluded, so the hash measures *content minus version*: a patch auto-bump never changes the hash, and the decision rule below never has to reason about the version field's contribution. Deterministic across boxes; ignores permissions, mtimes, and ordering. It stays in the publish script — tree-hashing is publisher-only bookkeeping with exactly one caller, not a runtime helper concern.

**Decision rule, applied per component inside the existing theme and plugin archive loops in `publish_upgrade.php`, before each `tar`:**

Let `last` = this component's entry in the latest prior release's snapshot, `manifest_version` = the version currently in the manifest.

1. **No `last` entry** (first publish under this system, or a new component): record `(manifest_version, current_hash)`, archive as-is. No bump.
2. **`manifest_version` > `last.version`**: the author bumped deliberately. Respect it; record; archive.
3. **`manifest_version` < `last.version`**: version went backward. Record and archive as-is, but emit a warning line in the publish summary naming the component and both versions. This is not aborted: a backward component version has no destructive consequence at publish time (unlike the core VERSION guard, which prevents running schema/data backward against a live database), and any resulting `depends` constraint violation is already caught fail-closed at activation by `validatePlugin()` (§3). A hard stop here would only let one mistaken manifest block releasing every other component.
4. **`manifest_version` == `last.version`**: compute `current_hash`.
   - Hash equal → unchanged component. Archive as-is; entry carries forward.
   - Hash differs → **auto-bump the patch number** in the manifest file, record the new `(version, current_hash)`, and archive. Because the hash excludes the manifest `version` member (above), the bump does not change the hash and no recompute is needed. Output one line per auto-bump: `- scrolldaddy: content changed since 1.0.0, auto-bumped to 1.0.1`.

**Manifest write.** The auto-bump edits only the `version` value via targeted string replacement of the existing `"version" : "..."` member (tolerant of whitespace), never a full `json_decode`/`json_encode` round-trip — re-serializing would churn the whole file's formatting in the component's own repo. If the pattern can't be found (e.g. version key absent), abort with a clear message rather than guessing.

**Ordering inside the loop:** read manifest → compute hash → apply decision rule → (maybe) write bump → `tar` → stage the component's new state entry. The hash is computed once; the bump leaves it unchanged. The publish script saves the `Upgrade` row before the archive loops run today; the accumulated snapshot is set on that row in one update after both loops complete (skipped components carry their prior entries forward unchanged). An aborted publish therefore leaves its row snapshot-less, and the baseline rule skips it — a later publish still compares against the last completed snapshot, never a half-written one. The archive always contains the manifest it was named after.

**Publish summary output** gains a section listing auto-bumped components, so the maintainer knows which manifest edits to commit (identical workflow to committing the `VERSION` file bump).

After this lands, the CLAUDE.md guidance ("increment version numbers when making changes") remains the preferred authoring behavior — auto-bump is the floor that keeps the system honest when authors forget, not a replacement for meaningful minor/major bumps.

### 2. Sync refreshes manifest-derived DB state

**`ThemeManager::updateExistingMetadata($model, $name)`** — rewritten to read the manifest once and diff-and-set all manifest-derived columns: `thm_display_name`, `thm_description`, `thm_version`, `thm_author`, `thm_metadata`, `thm_receives_upgrades`, `thm_is_system`. Save only when something changed; return that boolean (the existing contract `sync()` expects).

**`PluginManager::updateExistingMetadata($model, $name)`** — refresh `plg_metadata` from the live manifest with a **merge that preserves runtime keys**: keys prefixed with `_` (currently `_menu_slugs`, written by `saveMenuSlugsToMetadata()`) are carried over from the existing stored metadata; everything else is replaced by the manifest contents. Also refresh `plg_is_system` and `plg_receives_upgrades` as today. Save only on change; return the boolean (fixes the always-empty "updated" list and the unconditional save).

### 3. Dependency version check reads live state

In `PluginManager::validatePlugin()`, the `depends` version constraint check switches from `json_decode($dep_plugin->get('plg_metadata'))['version']` to `$dep_plugin->get_version()` (which already reads the dependency's live `plugin.json`). The cache refresh in §2 keeps `plg_metadata` honest for its other consumers, but enforcement should never read a cache when the source file is on the same disk.

### 4. Theme activation requirements gate

`ThemeManager::onActivate($name, $model, $dblink)` gains a requirements check before any state mutation, mirroring the plugin path: instantiate `ThemeHelper` for the theme and call `checkRequirements()` (the `requires.php` / `requires.joinery` gate, fail-closed against the VERSION file — the same surface the plugin path enforces via `validatePlugin()`). Note this is `checkRequirements()`, **not** the broader `ThemeHelper::validate()`: `validate()` also asserts the declared `formWriterBase` class file exists, which most current theme manifests fail (they declare `FormWriterHTML5` while the file is `FormWriterV2HTML5.php`), so gating activation on full `validate()` would wrongly block switching to nearly every theme. That FormWriter-base mismatch is a separate pre-existing issue, out of scope here. On errors, throw with the specific failures joined into the message; the existing transaction wrapper in `AbstractExtensionManager::activate()` rolls back and `admin_themes_logic.php` already surfaces the exception message. Grandfathering needs no code: the gate runs only on activation, so an already-active theme that newly fails requirements keeps running, exactly as the rationalization spec prescribed. The unmet-requirements warning badge on `/admin/admin_themes` already exists.

### 5. Remove dead updates UI

In `adm/admin_plugins.php`: remove the `available_version` badge branch and the `$plugin_updates` "Updates Available" block (the variable is never set). In `adm/logic/admin_plugins_logic.php` remove the `check_updates` action stub, and remove the "Check for Updates" entry from `$altlinks`. Update availability for managed nodes is the Server Manager node Updates tab's job; a consumer-site-facing "update available" surface, if ever wanted, would be driven by the source's published-archives manifest and is out of scope here.

## Edge cases

- **First publish after this ships** (no prior row carries a snapshot): every component hits rule 1 (no baseline) — versions recorded as-is, no bumps. Enforcement begins with the next publish.
- **Component skipped from publish** (`included_in_publish: false` or `deprecated`): skipped entirely — no hash, no decision; its prior snapshot entry carries forward, exactly as it is skipped from archiving today.
- **Author bumps version without changing anything else:** rule 2 — respected and recorded (the bump itself is a content change in any case).
- **Manifest-only change** (e.g. description edit, same version): hash differs → auto patch-bump. Correct: the archive contents changed.
- **Renamed component:** appears as a new name (rule 1). The old name's snapshot entry goes stale harmlessly; the existing archive-wipe on publish already handles the stale archive file.
- **All release rows deleted via the publish page:** no snapshot remains → next publish re-baselines. Consistent with what deletion means.
- **CLI and web publish paths:** the loops are shared, so both paths get the behavior; abort messages go through the existing `publish_output()` helper.

## Files to modify

| File | Change |
|---|---|
| `data/upgrades_class.php` | Add `upg_component_state` (`text`) to `$field_specifications` |
| `plugins/server_manager/includes/publish_upgrade.php` | `component_tree_hash()` helper; baseline load + snapshot save; decision rule + manifest bump in both archive loops; summary output |
| `includes/ThemeManager.php` | `updateExistingMetadata()` full refresh; `onActivate()` requirements gate |
| `includes/PluginManager.php` | `updateExistingMetadata()` merge-refresh + boolean; `validatePlugin()` dependency check via `get_version()` |
| `adm/admin_plugins.php` | Remove dead updates UI |
| `adm/logic/admin_plugins_logic.php` | Remove `check_updates` stub |

No new files. Bump the `@version` header on each modified file. No migrations — the new column arrives automatically via `update_database` from the field specification.

## Testing

1. **Baseline publish:** run a publish; the new `upg_upgrades` row's `upg_component_state` holds an entry per published component matching its manifest version; no manifests modified.
2. **No-change publish:** publish again with nothing touched; no bumps, snapshot entries unchanged, archives regenerate under the same names.
3. **Content-change publish:** edit one file in a theme and one in a plugin; publish; both manifests show a patch bump, output names them, the new snapshot carries the new versions and hashes, archives carry the new filenames.
4. **Manual bump respected:** set a plugin's version to the next minor, change a file, publish; the manual version is kept (no double-bump) and recorded.
5. **Regression warned:** set a version below its baseline entry; publish completes, the summary carries a warning line naming the component and both versions, and the component is recorded and archived as-is.
6. **Release deletion:** delete the latest release from the publish page; the next publish baselines against the prior release's snapshot.
7. **`.git` insensitivity:** create a `.git/` directory inside a component (git_hosting Phase 1 scenario), publish twice with no other change; second publish reports no change.
8. **Sync refresh:** with the known-stale rows on this box (`default` theme, `inbound_email` plugin), run "Sync with Filesystem" / `update_database`; `thm_version` and `plg_metadata` versions match manifests; `_menu_slugs` in `plg_metadata` survives.
9. **Dependency check:** with `plg_metadata` artificially stale, a plugin declaring `depends: {inbound_email: ">=1.10.0"}` activates successfully (live manifest 1.15.0 satisfies it).
10. **Theme activation gate:** set a test theme's `requires.joinery` to `>=99.0.0`; activation is refused with the specific failure in the admin message; removing the floor allows activation; the currently-active theme is unaffected throughout.
11. **Dead UI gone:** plugins admin page renders with no "Check for Updates" option and no updates alert; no PHP notices.

## Documentation updates

- `docs/deploy_and_upgrade.md` — publish behavior: the per-release `upg_component_state` snapshot and baseline rule, the four-way decision rule, auto patch-bump and the warn-on-regression behavior, and that auto-bumped manifests are working-copy edits the maintainer commits.
- `docs/plugin_developer_guide.md` — component versioning: authors bump minor/major for meaningful releases; publish auto-bumps patch when content changed without a bump; activation (plugins *and* themes) is gated on `requires`; dependency version constraints are evaluated against the dependency's live manifest.

Per documentation rules, both docs describe the end state only — no references to the prior behavior.

## Out of scope

- A dedicated component-release table with its own data class. Considered and dropped as unjustified weight: the snapshot column on `upg_upgrades` provides the baseline *and* per-release history with no new schema machinery. (A standalone `static_files` JSON state file was also considered and rejected — an orphan artifact with no owning model, weaker deletion semantics, and no history.)
- Any UI over the release history snapshots ("when did scrolldaddy 1.0.3 ship?" stays a SQL query for now).
- Consumer-site "update available" surfaces (the removed dead UI is not replaced).
- Version-gating downloads in `upgrade.php` — consumers continue to refresh all published extensions by name; honest versions are bookkeeping and identity, not a download filter.
- Signing or integrity-verifying archives.
- Any change to git_hosting's own scope (the P1.8 tar excludes land with that spec; this spec only mirrors the exclusion set in the hash).
