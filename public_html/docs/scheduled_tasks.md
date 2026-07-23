# Scheduled Tasks

Developer documentation for the scheduled task system.

## Overview

The scheduled tasks system provides a general-purpose framework for running tasks on a schedule. Tasks are PHP classes paired with JSON config files. A single cron entry runs every 15 minutes and executes any tasks that are due.

## Architecture

```
Cron (every 15 min)
  → utils/process_scheduled_tasks.php
    → Load active ScheduledTask records from DB
    → For each task where is_due():
      1. Resolve and instantiate the task class
      2. Call run($config) with the task's sct_task_config
      3. Update last_run_time and last_run_status
```

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

**Config field types:**
- `text` — Text input
- `number` — Numeric input
- `boolean` — Checkbox
- `mailing_list` — Mailing list dropdown (populated from database)

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
| `sct_last_run_status` | varchar(50) | success/error/skipped/orphaned |
| `sct_last_run_message` | varchar(500) | Human-readable result detail |
| `sct_create_time` | timestamp | Row creation time |
| `sct_delete_time` | timestamp | Soft delete time |

**Classes:** `ScheduledTask` (single), `MultiScheduledTask` (collection)

**MultiScheduledTask filter options:** `active` (bool), `deleted` (bool), `task_class` (string)

## Key Methods

### `ScheduledTask::is_due()`

Behavior depends on `sct_frequency`:

- **`every_run`** — Always due (runs every cron invocation, ~15 min)
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

### Orphaned tasks (code file removed)

Deploys ship and remove code; they never touch the `sct_scheduled_tasks`
table. So when a task is **removed or consolidated** in a release, its
activation row is intentionally left behind (orphan-by-design) — a deploy
will not silently delete an operator's configured schedule and config
based on a file no longer being present (which could also be a partial
deploy, a rename, or an unsynced plugin).

When the runner hits such a row, `resolve_task_file()` returns null and it
records a distinct **`orphaned`** status with a "remove this task in admin,
or restore the file" message. An orphan **still counts toward the run's
error tally**, so the cron-health summary stays honest — it is a real
"this needs attention" condition, just a remove-or-restore cleanup item
rather than a task that ran and failed.

The admin Active Tasks list flags orphans with an **Orphaned** badge,
detected live (by resolving the file on page load) so it reflects the
current deploy rather than the last stored status. Cleanup is a manual,
per-site operator action: **Deactivate** the task (soft-deletes the row).
Consolidation therefore has two operator steps on each site — activate the
new task, and remove the obsolete ones.

### Per-task advisory locking

Each task's `run()` is wrapped in `pg_try_advisory_lock(hashtext(sct_name))`,
so a long-running task cannot be re-entered by the next cron tick. If the
lock cannot be acquired the task is skipped with `skipped: already running`
and the runner moves on to the next task. The lock auto-releases when the
PHP connection closes, so a crashed process self-recovers on the next tick.

This is transparent to task implementations — no `run()` code needs to
know about the lock — but it means tasks that legitimately want to run
in parallel across ticks would be serialized. In practice the cron tick
interval (15 min) is long compared to almost every task's runtime, so
the serialization is rarely visible.

### Setup

**Standard server** — Add to the `www-data` user's crontab (`sudo crontab -e -u www-data`):
```
*/15 * * * * php /var/www/html/{sitename}/public_html/utils/process_scheduled_tasks.php >> /var/www/html/{sitename}/logs/cron_scheduled_tasks.log 2>&1
```

**Docker container** — Ensure `cron` is installed and running, then create `/etc/cron.d/scheduled-tasks`:
```
*/15 * * * * www-data php /var/www/html/{sitename}/public_html/utils/process_scheduled_tasks.php >> /var/www/html/{sitename}/logs/cron_scheduled_tasks.log 2>&1
```

Note: Docker containers may not have `cron` installed by default. Install with `apt-get install -y cron` and start the daemon with `cron`. The cron daemon must also be started after container restart (add to entrypoint if needed).

**New installs** get the crontab entry automatically via `_site_init.sh`. **Existing sites** see a warning on the admin page with setup instructions if cron hasn't run in 30+ minutes.

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

Tasks in `/plugins/{plugin}/tasks/` are discovered automatically alongside core tasks. Each needs both a `.json` and `.php` file.

### Plugin Ownership

Each plugin task record stores the owning plugin name in `sct_plugin_name`. This field is populated automatically when a task is activated via the admin UI for a task discovered in a plugin's `/tasks/` directory.

### Plugin Lifecycle Behavior

Plugin-owned tasks follow the plugin lifecycle:

- **Plugin deactivated** — All tasks with matching `sct_plugin_name` are suspended (`sct_is_active = false`). They will not run until the plugin is reactivated.
- **Plugin reactivated** — Suspended tasks are resumed (`sct_is_active = true`).
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

## Related Files

| File | Purpose |
|------|---------|
| `data/scheduled_tasks_class.php` | Data model classes |
| `includes/ScheduledTaskInterface.php` | Task interface |
| `utils/process_scheduled_tasks.php` | Cron runner |
| `adm/admin_scheduled_tasks.php` | Admin page view |
| `adm/logic/admin_scheduled_tasks_logic.php` | Admin page logic |
| `tasks/WeeklyEventsDigest.php` | Example email digest task |
| `tasks/WeeklyEventsDigest.json` | Example email digest config |
| `tasks/PurgeOldErrors.php` | Example cleanup task |
| `tasks/PurgeOldErrors.json` | Example cleanup config |
| `tasks/SyncPaypalSubscriptions.php` | PayPal subscription backstop |
| `tasks/ReconcileStripeSubscriptions.php` | Stripe subscription backstop (bulk-list) |
| `migrations/migration_scheduled_tasks_init.php` | Setup migration |

### Subscription reconciliation tasks

`SyncPaypalSubscriptions` and `ReconcileStripeSubscriptions` are sibling backstops
to their respective webhooks: webhooks are the authoritative real-time path for
subscription state, and these daily tasks catch anything a webhook missed
(cancellations, period rollovers, status changes).

`ReconcileStripeSubscriptions` loads the global working set
(`MultiOrderItem(['is_active_subscription' => true])`), then **pages Stripe's
subscription list endpoint** (`get_subscriptions(['status' => 'all', 'limit' =>
100])`, up to 100 per call, stopping once every wanted id is found) and applies
each via `StripeHelper::apply_subscription_to_order_item()` — one bulk fetch
rather than a per-item round-trip. It writes a single `EventLog` summary row per
run and implements `ScheduledTaskDryRunnable` for a no-write preview of pending
changes.
