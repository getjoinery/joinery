# Versioning Rationalization

## Problem

Joinery has the appearance of a version system but, on close inspection, it's split across five disconnected stores with no agreement between them, and almost none of it is enforced. The cracks got exposed while bringing up the one-click node install: a fresh install ended up with `stg_settings.system_version='0.30'` on a site built from release tarball `joinery-core-0.8.13.tar.gz`, themes whose manifests claim `"requires": {"joinery": ">=1.0.0"}` despite never having been checked, and a `ManagedNode.mgn_joinery_version` column that nothing acts on.

## Prior work

Two previous specs in `specs/implemented/` laid the groundwork this one builds on:

- **`versioning_reset.md`** — established the `major.minor.patch` scheme starting at `0.8.1`, added `upg_patch_version`, and taught `publish_upgrade.php` to patch-bump. That is the versioning scheme we keep.
- **`version_number_consolidation.md`** — removed redundant `database_version` / `db_migration_version` settings and declared `stg_settings.system_version` the sole version setting. It explicitly documented that `system_version` is written by `upgrade.php` on client sites after upgrade, and the **publish/source server never sets it for itself**.

That last design decision is where things unraveled. On a publish server, `system_version` drifts forever (hence `0.30` on joinerytest.site). Anything on the publish server that reads `system_version` — including `ComponentBase::checkRequirements()` for its requirements check — gets the stale value. The prior spec knew this and shrugged; this spec fixes it. Also neither prior spec addressed theme/plugin `requires.joinery` enforcement at all.

This is a continuation, not a reset.

### Five version stores, one nominal concept

| Store | Current value on joinerytest.site | Who writes it | Who reads it | Enforced? |
|---|---|---|---|---|
| `stg_settings.system_version` | `0.30` | `upgrade.php:1188` after successful remote upgrade (writes whatever the server said it was) | `upgrade.php` version compare, node status check display, `publish_theme` serve | version_compare against remote only |
| `upg_upgrades.upg_major/minor/patch` | 0.8.13 | `publish_upgrade.php:352` on each publish | `upgrade.php:70` to report "what version am I serving" | No |
| Release tarball filename `joinery-core-X.Y.Z.tar.gz` | 0.8.13 | `publish_upgrade.php` | `/utils/latest_release` redirect target | No version tag inside tarball |
| `theme.json` / `plugin.json` `version` | mostly `1.0.0`, some `1.0.1`/`2.1.0` | hand-edited | `publish_upgrade.php` uses for archive filenames; nothing else | No |
| `theme.json` / `plugin.json` `requires.joinery` | all `">=1.0.0"` across ~25 themes | hand-edited | `ComponentBase::checkRequirements()` — but never called at runtime | No |
| `mgn_managed_nodes.mgn_joinery_version` | per-node | `JobResultProcessor::process_check_status:84` parses from remote's `system_version` | node detail display | No |

### Five specific bugs

1. **`system_version` is stale because it's never stamped by the canonical source.** `publish_upgrade.php` writes `upg_upgrades` but **does not update `stg_settings.system_version`** on the control plane. The only writer of `system_version` is `upgrade.php:1188` after a successful remote upgrade — and the value it writes is whatever came back in the server's JSON response. So the control plane's own `system_version` drifts: it last got updated when this site last self-upgraded (resulting in `0.30`), but releases have since marched on to `0.8.13`. Nothing ever fixes this.

2. **`ComponentBase::checkRequirements()` reads the wrong setting name.** At `includes/ComponentBase.php:90`:
   ```php
   $joineryVersion = $settings->get_setting('joinery_version', true, true) ?? '1.0.0';
   ```
   The setting in the DB is `system_version`, not `joinery_version`. So even if the caller were wired up, it'd compare `"1.0.0"` against the theme's `">=1.0.0"` — always passes.

3. **Requirements are never actually checked.** `checkRequirements()` is only called from `ThemeHelper::validate()` / `PluginHelper::validate()`, which are only called from `utils/test_components.php` — a manual test utility. No runtime path (theme activation, plugin activation, `upgrade.php`, `PluginManager::activate()`, sync jobs) calls them. The `"requires"` block in every `theme.json` and `plugin.json` is decorative.

4. **Manifest version floors don't match reality.** Every manifest says `"requires": {"joinery": ">=1.0.0"}`. Current Joinery is `0.8.13`. If checks were wired up today, nothing would activate — because `0.8.13 < 1.0.0`.

5. **The tarball has no version tag inside it.** Filename says `joinery-core-0.8.13.tar.gz`, but extracting it produces files with no marker of which version they came from. Remote nodes trust the version string from the server's JSON response rather than verifying it from the extracted code. If `upg_upgrades` gets out of sync with `/static_files/*.tar.gz`, the mismatch is silent.

Additionally, two dead/misleading fields:
- `plg_metadata['version']` checked by `PluginManager::validatePlugin():437` — but `plg_metadata` is never populated, so the check is effectively disabled.
- `migrations/migrations.php` `database_version` numbers (1..101+) are a fourth, independent sequence that has nothing to do with the release version. This one probably shouldn't change.

## What "version" should actually mean

There are two distinct concepts that have been collapsed into one:

- **Release version** — the version of the Joinery codebase installed on a site. Identifies which set of core files, themes, and plugins shipped together. Monotonically increasing. This is what `upg_upgrades.upg_major/minor/patch` wants to be.
- **Database schema version** — which migrations have been applied. This is `migrations/migrations.php` + `mig_migrations` table. It's already working and should not change.

Themes and plugins have their **own** release versions (`theme.json` `version`, `plugin.json` `version`) that should be monotonically increasing per-component.

The "`requires.joinery`" field on a theme/plugin says "this component requires at least Joinery release version X." It's compared against the core release version of the site the component is about to run on.

## Design

### Single source of truth: `VERSION` file in the repo

Add a file `public_html/VERSION` at the repo root containing a single line: the current Joinery release version in `major.minor.patch` semver form. This file:

- Lives in git — every branch/commit has a definitive version.
- Is bumped by `publish_upgrade.php` as part of publish (writes `VERSION` before packaging, commits it as part of the release).
- Is baked into the release tarball (because `public_html/` is what gets packaged).
- Is read at runtime by a single helper: `LibraryFunctions::get_joinery_version()` that `file_get_contents()`s it (cached in a static variable per-request).

**Transition fallback**: during rollout, a site may be running code from before this spec shipped and won't have a `VERSION` file. The helper falls back to `stg_settings.system_version` in that case. Once every site we own has been upgraded to a VERSION-aware release, the fallback becomes dead code and gets removed in a follow-up cleanup.

All the other "version" places consume this helper instead of duplicating the value. Specifically:
- `ComponentBase::checkRequirements()` reads from the helper, not from settings.
- `stg_settings.system_version` is no longer authoritative — becomes a derived/cached value that always mirrors the `VERSION` file. Updated by `update_database.php` so any run of the migration pipeline self-heals drift.
- `upg_upgrades` still tracks what was published (history), but `publish_upgrade.php` gets its "what version am I publishing" from the `VERSION` file, not from the last row's patch+1.

### Publish flow

`publish_upgrade.php` takes a version argument (explicit bump, e.g. `0.9.0` for a minor or `0.8.14` for a patch). Defaults to patch-bump if omitted. It:

1. Refuses to proceed if the argument is less than the version currently in `VERSION` (same rule as `upgrade.php`'s downgrade block, applied at the publish layer — cheap guard against accidental regression when someone bumped the file manually out-of-band).
2. Writes the new version to `public_html/VERSION`.
3. (Optional, future) git commits `VERSION` as part of the publish.
4. Packages the tarball (which now includes the updated `VERSION` file).
5. Writes the `upg_upgrades` row with the same version.
6. Writes the new version to `stg_settings.system_version` on the publish server.

Step 5 supersedes the prior spec's "publish server never sets system_version for itself" rule. That rule existed because at the time the only writer was `upgrade.php` on a client site, and publish servers don't self-upgrade — so there was no clean moment to update it. With `VERSION` as the source of truth, publishing is a clean moment: the publish server really is now running what it just published (it has the code + migrations), so `system_version` on the publish server is accurate data, not a lie. `update_database.php` also self-heals `system_version` from `VERSION` on every run, as a backstop — both writers always set the same value (`get_joinery_version()`), so they can't disagree.

### Upgrade flow (remote node applies a new release)

`upgrade.php` on a remote node:

1. Fetches `/utils/latest_release`, extracts the tarball.
2. Reads the `VERSION` file from the extracted code — that's the authoritative target version.
3. Compares with local `VERSION` (or `system_version` if `VERSION` is missing, for one-shot backfill).
4. Refuses to proceed if target < local (existing downgrade block, now comparing from file).
5. After successful migration, writes the target version to local `stg_settings.system_version` as a cache. `VERSION` file on disk is already up-to-date (it came from the tarball).

### Activation-time enforcement

Theme activation and plugin activation gate on `checkRequirements()`. Concretely:

- **New activations** (`PluginManager::activate()` / admin theme-set): call `$component->validate()` before the state mutation. If `checkRequirements()` returns errors, refuse and surface the error in the admin UI. Currently-inactive components that fail checks cannot be activated.
- **Already-active components are never auto-deactivated.** When this enforcement lands on a site that has a plugin or theme that was activated under the old no-check regime but now fails its requirements, the component keeps running. Ripping something out from under a running site is worse than the drift.
- **Warn loudly where admins can see it.** On the admin plugins page, the admin themes page, and the `admin/server_manager/marketplace` if relevant, components that currently fail requirements show an error-styled badge with the specific failure ("Requires Joinery >=0.9.0, this site is 0.8.13"). Gives admins a clear remediation path without forcing it. Deactivating → fixing the manifest → reactivating follows the new rules.
- **Clear error at blocked-reactivation.** If an admin deactivates and then tries to reactivate a component that fails requirements, the refusal needs to state the specific failure in the same format as the badge, so it's obvious that this is the new rule kicking in, not a bug. Prevents the "the new system broke my working site" confusion.
- **Sync-time detection**: `PluginSync` / `ThemeSync` during `update_database` flag incompatible components in output but do *not* refuse to sync (syncing a manifest record is fine; activation is the gate). Keeps installs from breaking mid-upgrade.

Fix `ComponentBase::checkRequirements()` to read from the new helper. Keep the fallback but make it fail-closed (empty version → check fails, rather than `1.0.0` → check passes).

### Manifest cleanup

Every shipped `theme.json` and `plugin.json` gets its `requires.joinery` **removed** entirely. None of the shipped components actually depend on a specific Joinery release feature — the `>=1.0.0` floor was symbolic, not load-bearing. The field stays supported in the schema, reserved for:

- Third-party components that genuinely need a floor.
- Shipped components that, at some future point, start depending on a feature that wasn't in earlier releases. In that case the component author adds the field with a real version.

When the field is absent, `checkRequirements()` treats that as "no Joinery-version constraint" and passes the check on that dimension.

Component `version` fields in `theme.json` / `plugin.json` stay as-is (they describe the component's own version, not Joinery's). Bumping them on real changes is the author's responsibility.

### Cleanup of dead fields

- `plg_metadata['version']` check in `PluginManager::validatePlugin():437` either gets wired to `plg_version` (the actual column) or removed. Currently it's checking a null. Pick one.
- `mgn_joinery_version` stays for display, but gains a "compare against control plane version" on the node dashboard. The control plane reads its own version via `get_joinery_version()`; each managed node reports its `system_version` via the existing `check_status` flow. The dashboard shows a warning badge whenever the two diverge in either direction:
  - Node behind control plane → "Upgrade available: node at 0.8.10, control plane at 0.8.13."
  - Node ahead of control plane → "Node ahead of control plane: node at 0.9.0, control plane at 0.8.13. Investigate — node may have been upgraded out-of-band."

  Either way, divergence is surfaced so admins can act. The ability to rebuild archives on a node without a version bump is being removed separately (tracked outside this spec), which closes the main legitimate cause of drift.

## Migration plan

Changes are ordered so each step is safely deployable on its own, and the system self-heals as new versions roll out.

1. **Add `public_html/VERSION` file** with current value (`0.8.13` or whatever is current when this ships). Ship `LibraryFunctions::get_joinery_version()` helper.

2. **Fix `update_database.php` to self-heal `system_version`** — write `get_joinery_version()` into `stg_settings.system_version` at end of every run. From this point forward, any site that runs `update_database` (which happens on every deploy) gets correct `system_version` regardless of how stale it was. This fixes the current `0.30` drift on joinerytest.site.

3. **Update `publish_upgrade.php`** to require an explicit version (with patch-bump default), write `VERSION` before packaging, and write `stg_settings.system_version` on the publish server so its version is current immediately (rather than waiting for the next `update_database` self-heal).

4. **Update `upgrade.php`** to read the target version from the extracted `VERSION` file rather than from the server's JSON response. Keep the JSON response field for back-compat during the transition.

5. **Fix `ComponentBase::checkRequirements()`** to use the helper and fail-closed. Still safe because nothing calls it yet.

6. **Clean up all `theme.json` / `plugin.json`** `requires.joinery` floors per the policy above.

7. **Wire up activation-time enforcement** in `PluginManager::activate()` and admin theme-set flow. This is the behavior change that could actually break existing installs — needs testing, rollout, and a clear error surface. Do last, once floors are cleaned and everything reliably reads correct versions.

8. **Publish a new core release** after each of the above lands, so nodes pick up the fixes incrementally.

## Testing

1. **`VERSION` file present and read correctly.** `get_joinery_version()` returns the file contents. Fresh install has the file at expected path. `update_database` writes `system_version` to match.
2. **Publish flow.** `publish_upgrade.php 0.9.0 "notes"` writes `0.9.0` to `VERSION`, packages it into the tarball, creates `upg_upgrades` row, and writes `system_version=0.9.0` on the publish server. Immediately after publish, the admin UI shows `0.9.0` without needing a separate `update_database` run.
3. **Upgrade flow.** Remote node with old `VERSION=0.8.13` upgrades to `0.9.0`; tarball extraction overwrites the file; `system_version` cache updates.
4. **Drift self-heal.** A site with `system_version=0.30` (like joinerytest.site today) runs `update_database` → `system_version` becomes the `VERSION` file contents.
5. **New-activation enforcement.** Try to activate a currently-inactive plugin with `requires.joinery=">=99.0.0"` — refused with a clear error. Deactivate-then-fix-manifest-then-reactivate works.
6. **Already-active grandfathering.** A plugin already activated under the old rules that now fails `checkRequirements()` keeps running after the code ships. Admin plugin page shows an error badge with the specific failure. Toggling it off then on again enforces the new rules.
7. **Deprecated theme skip.** Falcon stays uninstalled and its `requires` are never evaluated.
8. **Node dashboard version compare.** Node behind control plane shows "upgrade available" badge; node ahead shows "ahead of control plane — investigate" badge; versions equal shows nothing.

## Out of scope

- Committing `VERSION` to git automatically from `publish_upgrade.php`. Authors still do that manually; the file just needs to be correct before publish (the script writes it, author commits).
- Signing tarballs or verifying integrity of release archives. Separate concern.
- Rolling back to a prior version. `upgrade.php` still hard-blocks downgrade; a dedicated "rollback" flow is future work.
- Changing the migration version sequence (`mig_migrations`). That pipeline works and is independent.
- Version-gating individual features inside core (e.g. "if version < 0.9 then use old path"). Not yet needed; add if and when a breaking change requires it.

## Estimated scope

Small-to-medium. The code changes are concentrated:

- `LibraryFunctions::get_joinery_version()` + `VERSION` file + `update_database` self-heal: ~20 lines.
- `publish_upgrade.php` wiring: ~30 lines.
- `upgrade.php` read-from-VERSION: ~20 lines, back-compat path.
- `ComponentBase::checkRequirements()` setting-name + fail-closed fix: ~5 lines.
- Manifest cleanup: mechanical, ~30 files touched.
- Activation-time enforcement wiring: ~40 lines across PluginManager and theme admin.
- Node-detail version compare display: ~15 lines.

The testing and deployment is the bigger cost — especially step 7, where enforcing requirements on real installs needs careful rollout.
