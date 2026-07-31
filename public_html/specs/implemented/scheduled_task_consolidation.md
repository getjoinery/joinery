# Scheduled Task Consolidation and Activate-on-Install

## Problem

The platform ships 35 scheduled task files across core and 7 plugins. That is more
than any operator can hold in their head, and the count grows by one every time a
table needs a retention window.

Two distinct problems produce it:

1. **One shape got a file per table.** Twelve of the 35 tasks are "delete or sweep
   things older than N" — the same six lines of SQL against a different table, each
   with its own JSON, its own `days_to_keep` config field, and its own row in the
   admin list.
2. **Nothing activates itself.** Every task starts life inactive and needs a manual
   Activate click on every site. Tasks a subsystem cannot function without — the
   provisioning pipeline, the relay poller — are indistinguishable in admin from
   tasks that are genuinely optional. Where this got painful, it was worked around
   with a hand-written `INSERT` in `migrations.php`.

This spec collapses 35 tasks to 18 and adds a declarative way for a task to be
activated when its plugin (or the platform) installs.

## Part 1 — Retention registry

### What it does

An operator today sees twelve separate "purge" tasks with twelve schedules and
twelve config forms. They will see one task, **Retention Sweep**, and set each
retention window where that window is already meaningful — as a normal setting on
the settings page, next to the feature it governs.

### Where a rule is declared

A retention rule is declared on the data class that owns the table. That class
already declares `$tablename`, every column in `$field_specifications`, and
`$foreign_key_actions` — its deletion strategy. Retention is deletion on a timer,
so it belongs in the same place:

```php
class GeneralError extends SystemBase {
    public static $tablename = 'err_general_errors';

    public static $retention_policy = [
        'label'          => 'Error log',
        'age_column'     => 'err_create_time',
        'age_unit'       => 'days',
        'window_setting' => 'error_log_retention_days',
    ];
}
```

It reads as a sentence: rows age by `err_create_time`, in days, against whatever
`error_log_retention_days` is set to. There is no `table` key — the class declares
that already — and no rule key; a rule is identified by its `$tablename`, which is
unique by construction. Because the column is named alongside
`$field_specifications`, a typo is a declaration-time failure rather than a sweep
that silently deletes nothing forever.

**Age form** (`age_column` + `age_unit`) covers eight of the eleven rules verbatim.
Optional `only_where` appends a qualifier for the two that do not purge everything:

```php
    public static $retention_policy = [
        'label'          => 'Notifications',
        'age_column'     => 'ntf_create_time',
        'age_unit'       => 'days',
        'window_setting' => 'notification_retention_days',
        'only_where'     => 'ntf_read_time IS NOT NULL',
    ];
```

`NotificationCleanup` only removes notifications that have been read;
`DrivePurgeStaleUploads` uses the same shape to restrict itself to pending uploads.

**Method form** (`purge_method`) covers the three that do more than one SQL
statement — `PurgeMailboxTrash` (reclaims attachments and the stored raw message),
`PurgeMailImportArchives` (releases Drive-sourced archives rather than deleting
them, and clears abandoned working directories), and `DrivePurgeTrash` (recursive
folder purge):

```php
    public static $retention_policy = [
        'label'          => 'Mailbox trash',
        'window_setting' => 'mailbox_trash_retention_days',
        'purge_method'   => 'purgeExpiredTrash',
    ];
```

`purge_method` names a **static method on the same class**, receiving the resolved
window and returning `['removed' => int, 'message' => string]`. Keeping the method
on the class means there is no `Class::method` string able to point at a class that
no longer exists, and the code sits beside the declaration that calls it — the same
arrangement as the `authenticate_read()` / `authenticate_write()` methods these
models already carry.

A `purge_method` rule that touches several tables is declared on the class that
anchors the operation, not split across them — `DrivePurgeTrash` sits on `File` and
handles folders from there. One rule, one owner, one entry in the run summary.

`PurgeMailImportArchives` anchors on `MailImportRun`: it ages import runs by when
they finished, and Drive is only where the reclaimed bytes live. Its orphaned
working-directory sweep stays inside that same purge method rather than becoming a
rule of its own — it parses a run id out of each directory name and checks
`mir_state` before removing anything, so it is the run's own litter.

The whole vocabulary is five keys, and a rule uses four: `label`, `window_setting`,
then either `age_column` + `age_unit` (plus optional `only_where`) or
`purge_method`. Every rule must name a `window_setting`.

### Discovery

`LibraryFunctions::discover_model_classes()` already walks core and plugin data
classes, and already accepts `'plugin_status' => 'active'` — an option that exists
so a deactivated plugin stops exposing its REST endpoints. The sweep reuses it as-is:

```php
$classes = LibraryFunctions::discover_model_classes([
    'include_plugins' => true,
    'plugin_status'   => 'active',
]);
```

A deactivated plugin therefore drops its retention rules with no additional check,
matching how plugin task suspension already works. There is no new manifest to
parse and no second plugin-active lookup to keep in step with the first.

**A deleted class takes its rule with it**, which is the reason the policy lives on
the model rather than in a manifest. A standalone rules file can outlive the table
it deletes from: drop the table, forget the entry, and the sweep errors against
something that is no longer there — the exact failure Part 4 exists to prevent,
reintroduced through a side door. With the policy on the class, absent code means
the rule is simply gone. No reconcile logic, no grace window.

### Windows are settings, not task config

Each of the eleven windows becomes a declared setting — core windows in
`settings.json`, plugin windows in that plugin's `plugin.json`. This is already the
established pattern: `mailbox_trash_retention_days` exists as a setting today
precisely because the mail reader shows each trashed message its purge date, and
docs already call it out as the reference case.

Generalizing it buys three things: the window is visible where the feature is
rather than buried in a task edit form, a member-facing surface can read it, and
the sweep task itself ends up with no `config_fields` at all.

**`0` in any window means "never purge"** — the rule is skipped, not run with a
default. This preserves the existing `PurgeMailboxTrash` semantics and applies it
everywhere.

It changes one task's behavior. `PurgeMailImportArchives` today treats a window of
`0` as "fall back to 7 days", so an operator cannot currently turn it off. Under
the unified rule `0` turns it off and 7 remains the factory default, which is the
answer an operator would expect from a field they can set to zero.

Settings to declare, with their current task-config defaults carried over as the
factory default:

| Setting | Default | Replaces |
|---|---|---|
| `error_log_retention_days` | 30 | `PurgeOldErrors.days_to_keep` |
| `request_log_retention_days` | 30 | `PurgeOldRequestLogs.days_to_keep` |
| `notification_retention_days` | 30 | `NotificationCleanup.retention_days` |
| `idempotency_key_retention_hours` | 24 | `PurgeIdempotencyKeys.hours_to_keep` |
| `drive_change_feed_retention_days` | 30 | `DrivePurgeChanges.days_to_keep` |
| `drive_trash_retention_days` | 30 | `DrivePurgeTrash.days_to_keep` |
| `drive_stale_upload_retention_hours` | 24 | `DrivePurgeStaleUploads.hours_to_keep` |
| `drive_device_link_grace_minutes` | 60 | `DrivePurgeDeviceLinks.grace_minutes` |
| `mailbox_inbound_log_retention_days` | 30 | `PurgeOldInboundEmailLogs.days_to_keep` |
| `mailbox_trash_retention_days` | *(exists)* | `PurgeMailboxTrash.days_to_keep` |
| `mailbox_import_archive_retention_days` | 7 | `PurgeMailImportArchives.retention_days` |

`SweepMailboxIndexTemp` is not a rule at all. It sweeps a filesystem directory, so
it has no table, no data class, and no window — nothing for a retention policy to
attach to. It becomes a plain step inside `RetentionSweep` rather than a registry
entry pretending to be a rule.

**Existing sites take the factory defaults.** No carry-over is read out of
`sct_task_config`. There are no production deployments whose tuned windows need
preserving, and reading them back would mean a one-shot migration that exists
forever to serve a case that never occurs.

### The task

`tasks/RetentionSweep.php`, `default_frequency: daily`, `default_time: 03:00:00`,
no `config_fields`, `activate_on_install: true`.

It collects every class carrying a `$retention_policy`, resolves each window from
its setting, skips rules whose window is `0`, and runs the rest — then the mailbox
index-temp directory sweep. **A rule that throws is caught, recorded, and does not
stop the remaining rules** — one bad table must not leave every other retention
window unswept. The run message summarizes per-rule counts; the overall status is
`error` if any rule failed, `success` otherwise.

`DrivePurgeDeviceLinks` runs hourly today. Folded into a daily sweep it becomes
daily, which is correct — the rows are already expired by then and the hourly
cadence was a guess, not a requirement.

## Part 2 — Subsystem merges

Three groups of tasks are phases of one pipeline that were split into separate
files. Splitting them costs an implied ordering nobody enforces and makes a stalled
phase invisible from the others.

### `MailboxRelayReconcile` (4 → 1)

Absorbs `SyncRelayMap`, `PullRelaySpool`, `AdvanceRelayCloudProvisions`, and
`FleetReconcile`. All four are `every_run`, all four no-op on a colocated
deployment, and they have a real order: push the alias map before pulling spool, so
a newly created alias is valid on the relay before mail for it arrives.

Phases run in that order. A phase that throws is caught and recorded; later phases
still run. Config merges: `PullRelaySpool.max_per_run` and `SyncRelayMap.force`
become `spool_max_per_run` and `force_map_push`.

### `ServerManagerAdvanceProvisioning` (3 → 1)

Absorbs `PollHostingOrders`, `ProvisionCustomerCloud`, `ProvisionPendingSsl` —
poll for paid orders, provision the instance, then provision SSL, in that order.
One task means one place to look when a customer's site is stuck.

`RunNodeUptimeChecks` stays a separate task **deliberately**: it is monitoring, not
provisioning, and its up/down alerting must not be blocked behind a provisioning
call that hangs.

### `ReconcileSubscriptions` (2 → 1)

Absorbs `ReconcileStripeSubscriptions` and `SyncPaypalSubscriptions`. The task
loops the enabled payment providers and reconciles each, so a third provider costs
zero new tasks. Keeps the existing `ScheduledTaskDryRunnable` preview, which now
covers both providers in one view.

### Result

| Owner | Before | After |
|---|---|---|
| Core | 11 | 4 |
| mailbox | 13 | 6 |
| server_manager | 4 | 2 |
| store | 2 | 1 |
| event_manager | 2 | 2 |
| dns_filtering / joinery_ai / bookings | 3 | 3 |
| **Total** | **35** | **18** |

Not merged, and why: `SendQueuedEmails`, `CloudOffloadRun`, `PollImapAccounts`,
`RunMailImports`, `ApplyInboundEmailFilters`, `LearnSpamFeedback`,
`RecipeDispatcher`, `BookingEmailsTask` are queue drainers with independent failure
domains — an rspamd outage must not stop mail imports. `WeeklyEventsDigest`,
`SendPostEventSurveys`, `DownloadBlocklists`, `CheckDomainSetup`,
`DriveUsageReconcile`, `RunNodeUptimeChecks` have distinct schedules, audiences, or
dry-run previews.

## Part 3 — `activate_on_install`

### Declaration

One key in the task's JSON:

```json
{
  "name": "Retention Sweep",
  "description": "...",
  "activate_on_install": true,
  "default_frequency": "daily",
  "default_time": "03:00:00"
}
```

Absent or `false` keeps today's behavior: the task appears under Available Tasks
and waits for a click.

### The three call sites

- **`PluginManager::onActivate()`** — after the plugin's tables are created and its
  declared settings are seeded, create rows for that plugin's flagged tasks. Runs
  before the existing "resume suspended tasks" step.
- **`utils/update_database.php`** — after the core settings seed (the step at
  `utils/update_database.php:679`), do the same for `/tasks/`. A new flagged core
  task then lands activated on every site on the next upgrade, with no migration.
- **`PluginManager::sync()`** — same call as `onActivate`, so "Sync with Filesystem"
  picks up a task added to an already-active plugin.

The admin page is unchanged. The flag only governs auto-creation.

### The safety rule

**Create the row only if no row exists for that task class at all — including
soft-deleted ones.**

Deactivating a task in admin soft-deletes its row. If auto-activation ignored
soft-deleted rows, an operator who deliberately turned a task off would get it back
on the next upgrade or plugin toggle, with no way to make the removal stick. Since
uninstall permanently deletes a plugin's task rows, a genuine reinstall still
re-activates correctly.

### Which tasks get the flag

`activate_on_install: true` on: `RetentionSweep`, `SendQueuedEmails`,
`MailboxRelayReconcile`, `ServerManagerAdvanceProvisioning`, `RunNodeUptimeChecks`,
`PollImapAccounts`, `RunMailImports`, `ApplyInboundEmailFilters`,
`ReconcileSubscriptions`, `RecipeDispatcher`, `BookingEmailsTask`,
`DownloadBlocklists`, `CheckDomainSetup`, `SendPostEventSurveys`.

Left off — these are opt-in by nature: `CloudOffloadRun` (self-activating and
self-deactivating already), `DriveUsageReconcile` (a drift backstop, not required
for correctness), `WeeklyEventsDigest` (needs a mailing list chosen before it can
do anything), `LearnSpamFeedback` (needs an rspamd controller reachable).

## Prerequisite — extract the registry

`_discover_tasks()` currently lives inside
`adm/logic/admin_scheduled_tasks_logic.php`. PluginManager and update_database
cannot require an admin logic file to call it.

Move discovery to `includes/ScheduledTaskRegistry.php`:

- `ScheduledTaskRegistry::discover()` — the current `_discover_tasks()` body,
  unchanged in behavior.
- `ScheduledTaskRegistry::activateDeclared($scope)` — `$scope` is `'core'` or a
  plugin name; creates rows for flagged tasks under the safety rule above. Returns
  the list of task names it activated, for the caller to print.
- `ScheduledTaskRegistry::retentionRules()` — returns the `$retention_policy` of
  every class found by `discover_model_classes(include_plugins, plugin_status =>
  'active')`, keyed by `$tablename`. A thin collector; the declarations themselves
  live on the models.
- `ScheduledTaskRegistry::reconcileMissing($skip_grace = false)` — the retirement
  logic from Part 4, shared by the cron runner and `update_database`.

`admin_scheduled_tasks_logic.php` keeps `_discover_tasks()` as a thin wrapper
delegating to the registry, so the admin page is untouched.

## Defects fixed as part of this

**`tasks/DrivePurgeDeviceLinks.php` has no `.json`.** It is therefore invisible to
discovery — it cannot be edited in admin, its `grace_minutes` cannot be set, and it
was activated by a hand-written `INSERT` at `migrations/migrations.php:1127`. That
INSERT is exactly what `activate_on_install` exists to replace. The file and the
migration both go away when its rule folds into the retention registry; the
migration is left in place (migrations are append-only history) but stops mattering
once the class is gone.

**`docs/scheduled_tasks.md` points at moved files.** Its Related Files table lists
`tasks/SyncPaypalSubscriptions.php` and `tasks/ReconcileStripeSubscriptions.php`;
both live under `plugins/store/tasks/`.

## Part 4 — A task disappearing is a normal upgrade event

This consolidation removes 17 task files, but the rule below is not about this
rollout. **Every future upgrade may remove or rename a task**, and a production
site must absorb that without an error and without a crash. A site's cron output
after an upgrade should be quiet.

### What breaks today

Two distinct failures, both live right now:

**1. One task's fatal kills the whole run.** The runner catches `Exception`
(`utils/process_scheduled_tasks.php:157`), which does not cover `Error` — so a
`TypeError`, a call to an undefined method, or a parse error in the required task
file is an uncaught fatal. The cron process dies mid-loop. Every task ordered after
it never runs, the failed task's row keeps its previous status (so admin shows it
as last-run-success), and the run prints no summary line. Nothing self-corrects;
the same task fatals again on the next tick and the same tasks stay unrun. An
upgrade that changes a signature a task depends on takes out unrelated subsystems.

**2. A removed task errors forever.** A missing code file records `orphaned` and
counts toward the error tally deliberately, on every tick, until an operator visits
each site and clicks Deactivate. A consolidation of 17 tasks therefore hands every
production site a permanently red cron summary and manual per-site cleanup.

### The rule

**Absent code means the task retires. It never means an error, and it never stops
the run.**

### Runner: never let one task take down the pass

- Catch **`Throwable`**, not `Exception`, around both the file load and `run()`.
  `require_once` moves inside the guarded block so a parse error in a task file is
  contained to that task.
- Register a **shutdown handler** that fires if the process dies mid-task anyway —
  a fatal PHP cannot catch, an OOM, or a task calling `exit()`. It reads the
  in-progress task id from a variable set before the call and writes `error` with
  the fatal's message to that row, so the row never keeps a stale
  last-run-success. Without this, the one class of failure that can still end the
  run is also the one that leaves no trace.
- The advisory-lock `finally` already releases correctly and is unchanged.

A task can still fail. It can no longer take the other 17 with it.

### Runner: retire rather than error

When `resolve_task_file()` returns null:

- **First miss** — stamp `sct_missing_since` and skip the task. No error, no status
  change, nothing counted. A file can be absent mid-deploy, during a plugin sync,
  or on a partially-applied upgrade; a single miss proves nothing.
- **File returns** — clear `sct_missing_since` and carry on. Transients
  self-heal with no operator action and no trace.
- **Missing continuously past the grace window** (default 1 hour, ~4 ticks) —
  **retire** it: set `sct_is_active = false`, `sct_last_run_status = 'retired'`,
  and a message naming why. Counted as neither run nor error.

Retirement sets `sct_is_active = false` and **does not soft-delete the row**. The
schedule and `sct_task_config` survive, so restoring the file and reactivating
restores the operator's configuration exactly. This is what makes silent
retirement safe: nothing is destroyed, so being wrong about a rename or a botched
deploy costs a click, not a reconstruction.

The `orphaned` status is retired along with it — `retired` replaces it entirely.

### Deploy: retire promptly, not an hour later

The grace window makes the runner safe standing alone, but a deploy knows exactly
when the filesystem has settled. `utils/update_database.php` calls the same
reconcile after the plugin sync step, with the grace window skipped — the
filesystem is authoritative at that moment. An upgrade therefore retires removed
tasks in the same run that installs their replacements, and the first post-upgrade
cron tick is already clean.

Both call sites share one implementation:
`ScheduledTaskRegistry::reconcileMissing($skip_grace = false)`.

### Supersession — say what replaced it

A task's JSON may declare what it absorbs:

```json
{
  "name": "Retention Sweep",
  "activate_on_install": true,
  "replaces": ["PurgeOldErrors", "PurgeOldRequestLogs", "NotificationCleanup"]
}
```

Reconcile checks `replaces` before falling back to "code file is missing", so the
retired row reads **"Superseded by Retention Sweep"** rather than a message
implying something broke. `replaces` is retirement metadata only — it never moves
config between tasks and never activates anything.

Every task removed by this spec is named in its successor's `replaces`, so no site
sees a bare missing-file message from this rollout. The same key is how all future
consolidations and renames announce themselves.

### Schema and admin

`ScheduledTask::$field_specifications` gains `sct_missing_since`
(`timestamp`, nullable), added automatically by `update_database`.

The admin list's **Orphaned** badge becomes **Retired**, with the retirement reason
shown and two actions: **Restore** (reactivate — for a task retired by a botched
deploy) and **Remove** (soft-delete the row for good). Neither is required for the
site to run correctly; a retired row is inert.

### What an operator does after this rollout

Nothing. New tasks self-activate via `activate_on_install`, superseded rows retire
themselves during `update_database` with a message naming their successor, and the
first cron tick after the upgrade reports zero errors. Clearing the retired rows
out of the admin list is optional housekeeping.

## Documentation

`docs/scheduled_tasks.md` is rewritten to describe the end state:

- **Retention** section replacing the per-task purge examples: declaring
  `$retention_policy` on a data class, both rule forms, the `window_setting`
  requirement, and `0` meaning never. `docs/example_class.php` gains a
  `$retention_policy` block alongside its deletion-strategy section, since that
  template is where a developer looks when writing a new model.
- **Activate on install** section: the JSON key, the three call sites, and the
  create-only-if-no-row-exists rule with its reasoning.
- **Task discovery** section repointed at `includes/ScheduledTaskRegistry.php`.
- The **Orphaned tasks** section is replaced by **Retired tasks**: absent code
  retires a task rather than erroring, the grace window and why it exists, the
  `replaces` key, and the guarantee that retirement preserves schedule and config.
- A **Failure containment** note on the runner: `Throwable` plus the shutdown
  handler mean one task's fatal cannot end the pass or leave a stale status.
- The `PurgeMailboxTrash` "task config over a setting" section is replaced — the
  window is unconditionally a setting, and the section becomes the worked example
  of a `purge_method` rule.
- Related Files table corrected and updated.

`docs/settings.md` gains the retention window settings in whatever inventory it
carries. Plugin-owned windows are documented in each plugin's own overview.

## Tests

New, under `tests/integration/`:

- `retention_registry_test.php` — every declared `$retention_policy` resolves
  (`age_column` is a real column in that class's `$field_specifications`, or
  `purge_method` is a callable static method on that class); every rule names a
  `window_setting` that is itself declared; a window of `0` skips its rule; a
  throwing rule does not prevent later rules from running; a class belonging to an
  inactive plugin contributes no rule.
- `scheduled_task_activation_test.php` — a flagged task with no row is created; a
  flagged task with an active row is not duplicated; a flagged task with a
  soft-deleted row is **not** recreated; an unflagged task is never auto-created.
- `scheduled_task_retirement_test.php` — a first miss stamps `sct_missing_since`
  and changes no status; a file returning clears the stamp; a miss past the grace
  window retires with `sct_is_active = false`, the row intact, and **zero** added
  to the error tally; `update_database` retires immediately without waiting out
  the grace; a `replaces` entry produces the supersession message.
- `scheduled_task_crash_containment_test.php` — a task throwing an `Error` (not an
  `Exception`) is recorded as `error` and **the following task still runs**; a task
  file with a parse error is contained the same way; a task calling `exit()` leaves
  its row marked `error` via the shutdown handler rather than a stale success.

The crash-containment test is the one that must not be skipped. Both failures it
covers are silent by nature — the evidence of them is tasks that quietly did not
run.

Existing `tests/integration/cloud_storage_guards_test.php` and
`cloud_storage_characterization_test.php` reference scheduled tasks and must stay
green.

The `db` tier is the gate for this work, since every part of it touches schema seed
or plugin lifecycle.

## Decisions resolved

- **Existing sites take factory defaults for retention windows.** No carry-over
  from `sct_task_config`. Owner decision, 2026-07-31 — pre-launch, no tuned
  production windows exist to preserve.
- **Retention policy is declared on the data class, not in a manifest file.** Owner
  decision, 2026-07-31. The class already owns the table name, the columns, and its
  deletion strategy, and `discover_model_classes()` already provides active-plugin
  discovery — so a manifest would restate facts the class holds and add a second
  source of truth that can outlive the table it points at.
- **`RunNodeUptimeChecks` stays out of the provisioning merge** — monitoring must
  not be blocked behind provisioning.
- **A task whose code disappears retires silently; it never errors and never
  crashes the run.** Owner decision, 2026-07-31, and it governs all future
  upgrades, not just this rollout. Retirement deactivates but preserves the row, so
  a wrong retirement costs a click rather than a lost configuration. No migration
  deletes rows: a deploy must never destroy operator config based on a file's
  absence, which could equally be a partial deploy or an unsynced plugin.
- **The retirement grace window is one hour (~4 cron ticks).** Owner confirmed
  2026-07-31 that no upgrade path leaves task files absent that long, so the window
  cannot be tripped by a slow deploy.
- **The runner catches `Throwable`, not `Exception`.** Pre-existing defect, not
  introduced here — a task fatal currently ends the entire cron pass and leaves the
  culprit showing its previous successful run.

## As built

Implemented and verified 2026-07-31 (`db` tier 203/203, 6220 checks; a live cron
pass reporting 9 run, 0 errors). Seven things landed differently from the plan
above, each because the existing code said otherwise:

- **The rule split is 6 age-form / 6 method-form, not 8/3.**
  `DrivePurgeStaleUploads` discards each row through `discard()` because every row
  owns a scratch `.part` file, and `DrivePurgeDeviceLinks` ages on two different
  columns in an `OR`. Neither fits a single `DELETE`.
- **`request_log_retention_days` already existed**, declared with default 90 and
  read by nothing. Reused rather than adding a second 30-day setting, so it has a
  reader for the first time.
- **`mailbox_inbound_log_retention_days` was not created.**
  `mailbox_log_retention_days` already meant exactly that; the rule points at it.
- **`SweepMailboxIndexTemp` became a windowless rule, not a hardcoded step.** A
  step inside `RetentionSweep` would make core `require` mailbox code. It is
  `InboundMailboxSearchIndex::sweepWorkingCopies()` with `window_setting` null,
  which is the shape this spec had removed and then needed.
- **`PurgeMailboxTrash` lost `report_only` and a configurable `max_per_run`.** The
  sweep has no `config_fields`, so both went; the per-run cap is a constant 500.
  `report_only` was a small dry-run capability and has no replacement.
- **Migration 159's `INSERT` is neutralized rather than left live.** As written it
  would create a `DrivePurgeDeviceLinks` row on every *fresh* install, which would
  then immediately retire — a new site showing a retired task it never had. The
  entry stays so version numbering is unbroken.
- **The provisioning and subscription phase bodies moved rather than being
  rewritten**, to `plugins/server_manager/includes/provisioning/` and
  `plugins/store/includes/subscriptions/`, as plain classes.

`docs/settings.md` carries no per-setting inventory, so the documentation item
naming it had nothing to add.
