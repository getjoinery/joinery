# Scheduled Tasks

Developer documentation for the scheduled task system.

## Overview

The scheduled tasks system provides a general-purpose framework for running tasks on a schedule. Tasks are PHP classes paired with JSON config files. A single cron entry runs every minute and executes any tasks that are due.

**A scheduled task can never read sealed content.** The Sealed Vault keeps a user's secret key in APCu keyed to their browser session, and the cron runner is a separate CLI process with its own APCu segment, so `VaultUnlock::secretKey()` returns null there by design. Work that needs a user's vault open belongs to `VaultDeferredWork` instead, which runs it in slices inside that user's own request — see [Sealed Vault § Deferred work in the window](sealed_vault.md#deferred-work-in-the-window). A task that queues or selects such work is fine; a task that tries to decrypt it will find nothing.

## Architecture

```
Cron (every minute)
  → utils/process_scheduled_tasks.php
    → Reconcile rows against the filesystem (retire what has no code)
    → Load active ScheduledTask records from DB
    → For each task where is_due():
      1. Resolve and instantiate the task class
      2. Call run($config) with the task's sct_task_config
      3. Update last_run_time and last_run_status
```

Each task runs inside a `Throwable` guard with a shutdown handler behind it, so
one task's failure cannot end the pass — see [Failure containment](#failure-containment).

## File Structure

Each task consists of two files sharing the same base name:

```
tasks/
  WeeklyEventsDigest.php       ← PHP class implementing ScheduledTaskInterface
  WeeklyEventsDigest.json      ← Metadata and default configuration

plugins/bookings/tasks/
  BookingEmailsTask.php
  BookingEmailsTask.json
```

## Creating a New Task

### 1. Create the JSON Config File

Place in `/tasks/` (core) or `/plugins/{plugin}/tasks/` (plugin).

```json
{
    "name": "My Task Name",
    "description": "What this task does",
    "default_frequency": "daily",
    "default_day_of_week": 1,
    "default_time": "09:00:00",
    "config_fields": {
        "some_setting": {"type": "text", "label": "Some Setting", "required": true}
    }
}
```

**Fields:**
- `name` — Display name in admin
- `description` — Explains what the task does
- `default_frequency` — Default frequency: `every_run`, `hourly`, `daily`, `weekly` (defaults to `daily`)
- `default_day_of_week` — Default schedule day (0=Sunday–6=Saturday, only used for `weekly`)
- `default_time` — Default time of day (HH:MM:SS, only used for `daily` and `weekly`). Omit the key entirely for other frequencies — the value is written verbatim to a `time` column, so an empty string fails activation
- `config_fields` — Task-specific parameters rendered in admin form
- `activate_on_install` — Create the task's row automatically (see [Activate on install](#activate-on-install))
- `replaces` — Class names this task absorbed, for retirement messages (see [Supersession](#supersession--say-what-replaced-it))

**Config field types:**
- `text` — Text input
- `number` — Numeric input
- `boolean` — Checkbox
- `mailing_list` — Mailing list dropdown (populated from database)

### Running an AI recipe when a task succeeds (`run_on_success`)

A task can hand its output straight to an AI recipe the moment it finishes, so work the task produces is judged in seconds rather than waiting for the recipe's own next scheduled tick. Declare it in the task JSON:

```json
{
    "name": "My Task",
    "default_frequency": "hourly",
    "run_on_success": {
        "recipes": ["some_recipe_declared_key"]
    }
}
```

Each entry is a recipe's `rcp_declared_key` (the key it was seeded under in `recipes.json`). After the task returns `success`, the runner queues a run of each named recipe. It is deliberately conservative — it fires only when joinery_ai is present, only for recipes that exist **and are enabled** (never resurrecting one an operator turned off or one still unconfigured), and skips a recipe that already has a run in flight. Queuing is the guarantee: the immediate spawn is best-effort, and the per-minute dispatcher drains any pending run.

**Two ways to wire it — they stack.** The JSON above is the *plugin author's* zero-config default, for when the same plugin ships the task and the recipe and wants them chained out of the box (portable — it uses the recipe's declared key). Separately, an **operator** can wire any task to any recipe on the **Edit Task** page (Admin › System › Scheduled Tasks › Edit): a "Run recipes when this task succeeds" checkbox list, autodetected from the recipes on this deployment, saved into `sct_task_config.run_on_success_recipes` as recipe **ids** (so it works for operator-created recipes that carry no declared key). At run time the two sources are unioned and deduped, so a recipe named by both fires once. Disabled recipes are listed but never fire.

**Firing only when there was work.** By default a successful run fires the chain. A task that often succeeds with nothing to hand on can opt out per run by returning an array result with `run_chain => false`:

```php
return ['status' => 'success', 'message' => '0 new items', 'run_chain' => false];
```

Omit the key (or return a plain `'success'` string) to fire the chain as normal.

### 2. Create the PHP Task Class

```php
<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class MyTaskName implements ScheduledTaskInterface {
    public function run(array $config) {
        // $config contains values from sct_task_config (set via admin form)

        // Do work here...

        // Return an array with status and human-readable message
        // Status meanings:
        //   'success'  — Ran and completed (with or without work to do)
        //   'skipped'  — Could not run (misconfigured, missing prerequisite)
        //   'error'    — Attempted to run but failed
        return array('status' => 'success', 'message' => 'Processed 5 items');
    }
}
```

**Sending email from a task.** For digest/notification tasks, send through `EmailSender` — the
one-call helper is `EmailSender::sendTemplate($templateName, $to, $values)`, or build an
`EmailMessage::fromTemplate(...)` and call `$sender->send($message)` for more control. See the
[Email System → Development Patterns](email_system.md#development-patterns) for the full API; do
not hand-roll `mail()` or PHPMailer calls. `tasks/WeeklyEventsDigest.php` is the worked example.

#### Self-deactivating tasks

A task can ask the runner to flip its `sct_is_active` to `false` after the
current run by adding `'deactivate' => true` to the result array:

```php
return array(
    'status'     => 'success',
    'message'    => 'No more work to do.',
    'deactivate' => true,
);
```

This is the right pattern for self-limiting tasks (e.g. `CloudOffloadRun`
deactivates itself once no store is offloading or draining). The runner reads the flag, sets
`sct_is_active = false` on the task row, and saves — so the row is not
re-evaluated on subsequent ticks until something explicitly reactivates
it.

Setting `sct_is_active = false` from inside the task with a separate
`save()` does *not* work: the runner holds an in-memory snapshot of
the row from before the call to `run()`, and its post-run save would
overwrite the deactivation. Use the `deactivate` flag.

### 3. (Optional) Add Dry Run Support

Tasks can implement the `ScheduledTaskDryRunnable` interface to support preview/dry run from the admin UI. This is especially useful for email tasks where you want to see what would be sent without actually sending.

```php
class MyTaskName implements ScheduledTaskInterface, ScheduledTaskDryRunnable {
    public function run(array $config) {
        // ... normal execution with side effects
    }

    public function dryRun(array $config) {
        // Perform all read/computation logic but skip side effects
        // (no sending emails, no deleting records, no API calls)

        return array(
            'status' => 'success',
            'message' => 'Would process 5 items',
            'html' => $preview_html,  // Optional: rendered in admin UI
        );
    }
}
```

**Return keys:**
- `status` (string, required) — Same as `run()`: `success`, `skipped`, `error`
- `message` (string, required) — Summary of what *would* happen (e.g., "Would send 5 events to 42 recipients")
- `html` (string, optional) — HTML preview displayed inline on the admin page (e.g., the email body)

When a task implements this interface, a **Dry Run** button appears alongside **Run Now** in the admin UI. Tasks that don't implement it simply won't show the button.

### 4. Activate via Admin

Navigate to **Admin > System > Scheduled Tasks**. The task appears under "Available Tasks". Click **Activate** to create the database row and enable scheduling.

A task its subsystem cannot function without should skip this step entirely —
see [Activate on install](#activate-on-install).

## Activate on install

A task that a subsystem **cannot function without** should not wait for a click
on every site. Declaring one key in its JSON creates its row automatically:

```json
{
    "name": "Retention Sweep",
    "description": "...",
    "activate_on_install": true,
    "default_frequency": "daily",
    "default_time": "03:00:00"
}
```

Absent or `false` keeps the manual behaviour: the task appears under Available
Tasks and waits to be activated.

**Where it fires:**

- `PluginManager::onActivate()` — after the plugin's tables are created and its
  declared settings are seeded, before suspended tasks are resumed.
- `PluginManager::sync()` — so "Sync with Filesystem" picks up a task added to an
  already-active plugin, which no activate cycle would.
- `utils/update_database.php` — after the core settings seed, for `/tasks/`. A
  new flagged core task then lands activated on every site on the next upgrade,
  with no migration.

### The safety rule

> **A row is created only when no row exists for that class at all — including
> soft-deleted ones.**

Deactivating a task in admin soft-deletes its row. If auto-activation ignored
soft-deleted rows, an operator who deliberately turned a task off would get it
back on the next upgrade or plugin toggle, with no way to make the removal
stick. Since uninstall permanently deletes a plugin's task rows, a genuine
reinstall still activates correctly.

Reserve the flag for tasks whose subsystem is broken without them. A task that
needs a mailing list chosen, an external service reachable, or a decision the
operator has not made yet stays opt-in.

## Retention windows

Deleting rows older than N is one shape, and it used to mean one task per table.
It is now **declared, not coded**: the rule lives as `$retention_policy` on the
data class that owns the table, next to the `$foreign_key_actions` that declare
the rest of that table's deletion behaviour. Retention is deletion on a timer.

One task, `RetentionSweep` (daily, 03:00), runs every declared rule.

### Age form

```php
class GeneralError extends SystemBase {
    public static $tablename = 'err_general_errors';

    public static $retention_policy = array(
        'label'          => 'Error log',
        'age_column'     => 'err_create_time',
        'age_unit'       => 'days',
        'window_setting' => 'error_log_retention_days',
    );
}
```

There is no `table` key — the class declares that already — and no rule key; a
rule is identified by its `$tablename`. `age_unit` is `days`, `hours` or
`minutes`. Optional `only_where` appends a qualifier for a rule that does not
purge everything:

```php
    public static $retention_policy = array(
        'label'          => 'Read notifications',
        'age_column'     => 'ntf_create_time',
        'age_unit'       => 'days',
        'only_where'     => 'ntf_is_read = true',
        'window_setting' => 'notification_retention_days',
    );
```

### Method form

For a rule that reclaims attachments, recurses, or touches the filesystem,
`purge_method` names a **static method on the same class**:

```php
    public static $retention_policy = array(
        'label'          => 'Mailbox trash',
        'purge_method'   => 'purgeExpiredTrash',
        'window_setting' => 'mailbox_trash_retention_days',
    );
```

It receives the resolved window and returns `['removed' => int, 'message' =>
string]`. `InboundEmailMessage::purgeExpiredTrash()` is the worked example: it
goes row by row through `permanent_delete()`, which reclaims the attachment
Files and the stored raw object — a bulk `DELETE` would drop the rows and leak
both.

A rule touching several tables is declared on the class that anchors the
operation, not split across them: `File::purgeExpiredTrash()` handles Drive
folders from there. One rule, one owner, one entry in the run summary.

### Windows are settings

Every rule names a `window_setting` — a declared setting, core in
`settings.json` or plugin-owned in `plugin.json`. That puts the window on the
settings page beside the feature it governs rather than inside a task edit form,
and lets a member-facing surface read it (the mail reader shows each trashed
message its purge date from `mailbox_trash_retention_days`).

**`0` in any window means never purge** — the rule is skipped entirely, not run
with some default the operator never chose.

The one exception is an unconditional rule with no age to choose, which declares
`'window_setting' => null`. `InboundMailboxSearchIndex::sweepWorkingCopies()` is
the only one: a sealed-index working copy whose vault window has closed is
plaintext nobody asked for, so there is no window to wait out.

### What the sweep guarantees

- Rules come from `LibraryFunctions::discover_model_classes()` with
  `plugin_status => 'active'`, so a deactivated plugin contributes no rules.
- A deleted class takes its rule with it. There is no manifest that can outlive
  the table it deletes from.
- **A rule that throws is caught, recorded, and does not stop the remaining
  rules.** One bad table must never leave every other window unswept.

Adding a retention window means adding a declaration and a setting. It never
means another task, another schedule, or another row in the admin list.

## Data Model

**Table:** `sct_scheduled_tasks`

| Column | Type | Description |
|--------|------|-------------|
| `sct_scheduled_task_id` | int8 (serial) | Primary key |
| `sct_name` | varchar(255) | Display name |
| `sct_task_class` | varchar(255) | PHP class name |
| `sct_is_active` | bool | Whether task runs on schedule |
| `sct_frequency` | varchar(20) | `every_run`, `hourly`, `daily`, `weekly` |
| `sct_schedule_day_of_week` | int4 | 0=Sun–6=Sat (weekly only) |
| `sct_schedule_time` | time | Time of day in site timezone (daily/weekly only) |
| `sct_task_config` | jsonb | Task-specific configuration |
| `sct_last_run_time` | timestamp | When task last ran |
| `sct_last_run_status` | varchar(50) | `success` / `error` / `skipped` / `retired` |
| `sct_last_run_message` | varchar(500) | Human-readable result detail |
| `sct_create_time` | timestamp | Row creation time |
| `sct_delete_time` | timestamp | Soft delete time |
| `sct_plugin_name` | varchar(100) | Owning plugin, NULL for core tasks |
| `sct_missing_since` | timestamp | First time the code file was found absent; cleared if it returns. See [Retired tasks](#retired-tasks-code-file-removed) |

**Classes:** `ScheduledTask` (single), `MultiScheduledTask` (collection)

**MultiScheduledTask filter options:** `active` (bool), `deleted` (bool), `task_class` (string), `plugin_name` (string)

## Key Methods

### `ScheduledTask::is_due()`

Behavior depends on `sct_frequency`:

- **`every_run`** — Always due (runs every cron invocation, ~1 min)
- **`hourly`** — Due if not already run in the current clock hour
- **`daily`** — Due if past `sct_schedule_time` today (site timezone) and not already run today
- **`weekly`** — Due if correct `sct_schedule_day_of_week`, past `sct_schedule_time`, and not already run today

All checks use the site's configured timezone (`default_timezone` setting).

### `ScheduledTask::resolve_task_file()`

Searches for the PHP class file:
1. `/tasks/{class_name}.php`
2. `/plugins/*/tasks/{class_name}.php`

Returns the full file path or null.

### `ScheduledTask::get_task_config()`

Returns `sct_task_config` as an associative array.

## Cron Runner

**File:** `utils/process_scheduled_tasks.php`

- Rejects non-CLI access
- Updates `scheduled_tasks_last_cron_run` setting (heartbeat)
- Loads active, non-deleted tasks
- Runs due tasks and updates their status
- Outputs timestamped results to stdout (logged by cron)

### Failure containment

One task must never take down the pass. Two mechanisms guarantee it:

- **`Throwable`, not `Exception`.** A `TypeError`, a call to a method an
  upgrade removed, or a `ParseError` in a task file is an `Error`, which is
  not an `Exception`. The guard catches `Throwable`, and the `require_once`
  sits inside it, so an unparseable task file is contained to that task.
- **A shutdown handler.** For the failures PHP cannot catch — an OOM kill, a
  fatal in a task's file scope, a task calling `exit()` — a handler writes
  `error` and the fatal's message to the in-progress row. Without it, the one
  class of failure that can still end the run is also the one that leaves no
  trace: the row would keep its previous status and admin would show the task
  as healthy.

A task can still fail. It cannot stop the tasks ordered after it, and it
cannot leave a stale last-run-success behind.

### Retired tasks (code file removed)

A task disappearing is a normal upgrade event. Every release may remove or
rename one, and a production site must absorb that without an error and
without a crash:

> **Absent code means the task retires. It never means an error, and it never
> stops the run.**

`ScheduledTaskRegistry::reconcileMissing()` runs at the top of every cron pass
and applies a three-step ladder:

| State | What happens |
|---|---|
| First miss | Stamp `sct_missing_since`. No status change, nothing counted. A file can be absent mid-deploy or during a plugin sync — a single miss proves nothing. |
| File returns | Clear the stamp. The transient self-heals with no operator action and no trace. |
| Missing past the grace window (1 hour, ~4 ticks) | Retire: `sct_is_active = false`, status `retired`, message naming why. Counted as neither run nor error. |

**Retirement never soft-deletes the row.** The schedule and `sct_task_config`
survive, so restoring the file and reactivating restores the operator's
configuration exactly. That is what makes silent retirement safe: nothing is
destroyed, so being wrong about a rename or a botched deploy costs a click
rather than a reconstruction.

`utils/update_database.php` calls the same reconcile with the grace window
skipped, because the filesystem is authoritative the moment a deploy finishes.
An upgrade therefore retires the tasks it removed in the same run that installs
their replacements, and the first cron tick afterwards is already clean.

The admin Active Tasks list shows a **Retired** badge with the retirement
reason, detected live by resolving the file on page load, and offers two
actions: **Restore** (reactivate, for a task retired by a botched deploy) and
**Remove** (soft-delete the row for good). Neither is required — a retired row
is inert.

#### Supersession — say what replaced it

A task's JSON may declare what it absorbs:

```json
{
    "name": "Retention Sweep",
    "activate_on_install": true,
    "replaces": ["PurgeOldErrors", "PurgeOldRequestLogs", "NotificationCleanup"]
}
```

Reconcile checks `replaces` before falling back to a missing-file message, so
the retired row reads **"Superseded by Retention Sweep"** rather than something
implying a fault. `replaces` is retirement metadata only — it never moves config
between tasks and never activates anything.

### Per-task advisory locking

Each task's `run()` is wrapped in `pg_try_advisory_lock(hashtext(sct_name))`,
so a long-running task cannot be re-entered by the next cron tick. If the
lock cannot be acquired the task is skipped with `skipped: already running`
and the runner moves on to the next task. The lock auto-releases when the
PHP connection closes, so a crashed process self-recovers on the next tick.

This is transparent to task implementations — no `run()` code needs to
know about the lock — but it means tasks that legitimately want to run
in parallel across ticks would be serialized. The cron tick is every
minute, so the lock is what keeps a task slower than the tick from
piling up: later ticks skip it rather than stack behind it.

### Setup

The runner is driven by exactly one cron entry per site: `/etc/cron.d/joinery-{sitename}`, firing every minute.

- **Bare metal** — `_site_init.sh` writes the file at install time.
- **Docker** — the container start command (in `Dockerfile.template`) writes the file and starts the cron daemon on every container start, so it survives container rebuilds.

The entry:
```
* * * * * www-data php /var/www/html/{sitename}/public_html/utils/process_scheduled_tasks.php >> /var/www/html/{sitename}/logs/cron_scheduled_tasks.log 2>&1
```

Every minute, not a longer interval: the tick is the floor on latency for `every_run` tasks, and inbound mail delivery is the one users feel. A full pass costs about a second, and the per-task advisory lock means a slow task is skipped rather than run concurrently.

**Existing sites** see a warning on the admin page with setup instructions if cron hasn't run in 30+ minutes.

## Admin Page

**File:** `adm/admin_scheduled_tasks.php`
**Logic:** `adm/logic/admin_scheduled_tasks_logic.php`
**Menu:** System > Scheduled Tasks (permission level 10)

**Sections:**
- **Cron Status Warning** — Shown when cron hasn't run in 30+ minutes
- **Active Tasks** — Table with schedule, status, edit/run now/dry run/deactivate controls
- **Edit Form** — Schedule day/time and task-specific config fields
- **Dry Run Preview** — Shown after a dry run; displays the task's HTML preview with a "no email was sent" banner
- **Available Tasks** — Discovered but not yet activated tasks with activate button

## Plugin Integration

### Task Discovery

Tasks in `/plugins/{plugin}/tasks/` are discovered automatically alongside core
tasks. Each needs both a `.json` and `.php` file.

Discovery lives in `includes/ScheduledTaskRegistry.php`, so the cron runner,
`PluginManager` and `update_database` can all reach it without requiring an
admin logic file. The registry has four entry points:

| Method | What it does |
|---|---|
| `discover()` | Every task class on disk, with its JSON metadata and source |
| `activateDeclared($scope)` | Create rows for tasks flagged `activate_on_install` in `'core'` or a named plugin |
| `retentionRules()` | Every `$retention_policy` declared on an active data class |
| `reconcileMissing($skip_grace)` | Retire rows whose code file is gone |

### Plugin Ownership

Each plugin task record stores the owning plugin name in `sct_plugin_name`. This field is populated automatically when a task is activated via the admin UI for a task discovered in a plugin's `/tasks/` directory.

### Plugin Lifecycle Behavior

Plugin-owned tasks follow the plugin lifecycle:

- **Plugin activated** — Tasks declaring `activate_on_install` get rows created (see [Activate on install](#activate-on-install)), then suspended tasks are resumed (`sct_is_active = true`). A task retired because its code file is gone is left alone: resuming it would put back a row that cannot run.
- **Plugin deactivated** — All tasks with matching `sct_plugin_name` are suspended (`sct_is_active = false`). They will not run until the plugin is reactivated.
- **Plugin uninstalled** — Task records with matching `sct_plugin_name` are permanently deleted (not just suspended).

### PollImapAccounts — task-floor vs. per-account cadence

The Mailbox plugin's **PollImapAccounts** task (`every_run`) illustrates a
two-level cadence. The task frequency is a **floor**: it fires every cron pass but
does no per-mailbox work unless an account is *due*. Each IMAP account carries its
own `iia_poll_interval_seconds` (default 300), and the task only polls accounts
whose interval has elapsed — so the **per-account interval is the real cadence**,
and the task can run frequently without hammering every mailbox. Each account is
claimed with an atomic conditional `UPDATE` (stamping `iia_last_poll_time` on
pickup) so two overlapping runs can't race the same account's UID cursor. Failures
are per-account and non-fatal — one unreachable mailbox is recorded in that
account's status and never fails the run. See
[Receiving by IMAP poll](/plugins/mailbox/docs/overview.md#receiving-by-imap-poll).

### RunNodeUptimeChecks — task-floor vs. per-node cadence

The Server Manager's **RunNodeUptimeChecks** task (`every_run`) uses the same
two-level cadence. Each managed node carries `mgn_uptime_interval_seconds`
(default 300) and `mgn_uptime_last_check`; the task probes only nodes whose
interval has elapsed. Probe volume is therefore a function of the node's
interval, not of how often cron ticks — the cron interval can be tightened to
reduce inbound mail latency without multiplying outbound monitoring traffic.

The attempt stamp is written even when a check cannot conclude up/down (for
example an `api` check on a node without API credentials), so an inconclusive
node still honours its interval instead of being retried every pass. A node
that has never been checked is always due, and a negative elapsed time — clock
skew, a bad stored value — is treated as due rather than allowed to wedge the
node permanently.

Set a node's interval to `0` to probe it on every pass.

A check that cannot conclude — an `api` check on a node without API
credentials, a `tcp_port` check with no port set — records the reason in
`mgn_uptime_last_error` and leaves `mgn_uptime_last_conclusive` untouched.
`NodeMonitorHealth` reads those two fields to classify a node as `ok`,
`misconfigured`, `stale`, `pending` or `disabled`, and both the Server Manager
dashboard and the node detail page render its verdict. A node that cannot
report up or down is therefore visible as a problem rather than presenting as
a node that simply has not alerted.

Three check types are available: `api` (authenticated management endpoint),
`http_status` (plain GET), and `tcp_port` (connection accepted on
`mgn_uptime_tcp_port` at the node's host). `tcp_port` covers services with no
web endpoint — an inbound mail relay proves it is alive by accepting
connections on port 25. `mgn_skip_joinery_checks` redirects `api` to
`http_status`, and leaves an explicitly chosen `http_status` or `tcp_port`
alone.

### FleetBackupRun — the control plane's backups of its nodes

The Server Manager's **FleetBackupRun** task (`every_run`) schedules the
manager-profile backups — this control plane's own copies of the nodes it
manages, under its own recovery key. The node does the backup; the task decides
when, prunes the node's manager shelf first with the control plane's
delete-capable credential, and dispatches one `backup_run` job per due node.

Three rules keep a fleet of these from behaving like a thundering herd: each
node's slot is derived from its slug and spread across the configured window,
so a fleet does not start every upload at the same minute; a node whose
previous run is still `pending` or `running` is skipped, so a slow node gets
fewer backups rather than a queue; and a fleet-wide cap bounds how many run at
once. A pass that dispatched nothing because nothing was due is a success —
`error` is reserved for a pass where every node it tried failed. The task
supports dry run. See the
[Server Manager overview](/plugins/server_manager/docs/overview.md) for the
policy model and the `backup_run` job type.

## Related Files

| File | Purpose |
|------|---------|
| `data/scheduled_tasks_class.php` | Data model classes |
| `includes/ScheduledTaskInterface.php` | Task interface |
| `includes/ScheduledTaskRegistry.php` | Discovery, auto-activation, retention rules, retirement |
| `utils/process_scheduled_tasks.php` | Cron runner |
| `adm/admin_scheduled_tasks.php` | Admin page view |
| `adm/logic/admin_scheduled_tasks_logic.php` | Admin page logic |
| `tasks/RetentionSweep.php` | Runs every declared `$retention_policy` |
| `tasks/WeeklyEventsDigest.php` | Example email digest task |
| `plugins/store/tasks/ReconcileSubscriptions.php` | Subscription backstop across all providers |
| `plugins/mailbox/tasks/MailboxRelayReconcile.php` | Example ordered-phase task |
| `plugins/server_manager/tasks/FleetBackupRun.php` | Fleet backup dispatch (manager profile) |
| `migrations/migration_scheduled_tasks_init.php` | Setup migration |

### Ordered-phase tasks

Some tasks are several stages of one pipeline. Splitting them across files costs
an implied ordering nobody enforces and makes a stalled stage invisible from the
others, so they run as ordered phases inside one task instead:

| Task | Phases |
|---|---|
| `MailboxRelayReconcile` | alias map → spool pull → cloud provision → fleet |
| `ServerManagerAdvanceProvisioning` | poll orders → customer cloud → SSL |
| `ReconcileSubscriptions` | one reconciler per enabled payment provider |

The phase bodies live outside `tasks/` (`plugins/server_manager/includes/provisioning/`,
`plugins/store/includes/subscriptions/`) so they are plain classes rather than
discoverable tasks. In each case a phase that throws is caught and recorded, and
the later phases still run — pushing the alias map failing must not strand mail
already sitting on the relay's spool, and a getjoinery API outage must not stop
SSL being issued for a site provisioned an hour ago.

`RunNodeUptimeChecks` stays a separate task deliberately: it is monitoring, not
provisioning, and its up/down alerting must not sit behind a provisioning call
that hangs.

### Subscription reconciliation

Webhooks are the authoritative real-time path for subscription state;
`ReconcileSubscriptions` is the daily backstop that catches anything a webhook
missed (cancellations, period rollovers, status changes). It loops the enabled
providers, so a third provider costs a reconciler class rather than another task,
and one provider's API outage cannot leave another's subscriptions unchecked.

`StripeSubscriptionReconciler` loads the global working set
(`MultiOrderItem(['is_active_subscription' => true])`), then **pages Stripe's
subscription list endpoint** (`get_subscriptions(['status' => 'all', 'limit' =>
100])`, up to 100 per call, stopping once every wanted id is found) and applies
each via `StripeHelper::apply_subscription_to_order_item()` — one bulk fetch
rather than a per-item round-trip. It writes a single `EventLog` summary row per
run and supplies the dry-run preview, which `ReconcileSubscriptions` shows for
every provider in one view.
