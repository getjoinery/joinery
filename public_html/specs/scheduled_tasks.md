# Scheduled Tasks

## Overview

A general-purpose system for running tasks on a schedule. Each task is a PHP class paired with a JSON config file, stored in `/tasks/` or `/plugins/{plugin}/tasks/`. The admin page discovers available tasks by scanning these directories, and admins can activate, deactivate, and configure schedules. A single cron runner executes active tasks when due.

The first task is a **weekly events digest** that generates an email and queues it through the existing bulk email pipeline.

## Task File Structure

Each task consists of two files sharing the same base name:

```
tasks/
  WeeklyEventsDigest.php       ← PHP class implementing ScheduledTaskInterface
  WeeklyEventsDigest.json      ← Metadata and default configuration

plugins/bookings/tasks/
  BookingReminder.php
  BookingReminder.json
```

### JSON Config File

```json
{
    "name": "Weekly Events Digest",
    "description": "Emails upcoming events for the next 7 days to a mailing list",
    "default_day_of_week": 1,
    "default_time": "09:00:00",
    "config_fields": {
        "mailing_list_id": {"type": "mailing_list", "label": "Mailing List", "required": true}
    }
}
```

- `name` — Display name shown in admin
- `description` — Explains what the task does
- `default_day_of_week` — Default schedule when activated (0=Sun–6=Sat, omit for daily)
- `default_time` — Default time of day
- `config_fields` — Declares task-specific parameters. The admin page reads this to render the appropriate form fields. Values are saved to `sct_task_config` on the DB row. Supported field types:
  - `mailing_list` — Mailing list dropdown
  - `number` — Numeric input
  - `text` — Text input
  - `boolean` — Checkbox

## How It Works

```
Cron (every 15 min)
  → cron/process_scheduled_tasks.php
    → Load active ScheduledTask records from DB
    → For each task where is_due():
      1. Resolve and instantiate the task class
      2. Call run($config) with the task's sct_task_config
      3. Update last_run_time and last_run_status
```

## Data Model: ScheduledTask

**File:** `data/scheduled_tasks_class.php`

The DB table stores activated tasks and their runtime state. Rows are created when an admin activates a discovered task, not via migrations.

```php
public static $prefix = 'sct';
public static $tablename = 'sct_scheduled_tasks';
public static $pkey_column = 'sct_scheduled_task_id';

public static $field_specifications = array(
    'sct_scheduled_task_id'    => array('type'=>'bigserial', 'is_nullable'=>false, 'is_pkey'=>true),
    'sct_name'                 => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'sct_task_class'           => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'sct_is_active'            => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'true'),
    'sct_schedule_day_of_week' => array('type'=>'int4', 'is_nullable'=>true),
    'sct_schedule_time'        => array('type'=>'time', 'is_nullable'=>false, 'default'=>"'09:00:00'"),
    'sct_task_config'          => array('type'=>'jsonb', 'is_nullable'=>true),
    'sct_last_run_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    'sct_last_run_status'      => array('type'=>'varchar(50)', 'is_nullable'=>true),
    'sct_create_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true, 'default'=>'now()'),
    'sct_delete_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
);
```

**Field notes:**
- `sct_task_class` — Class name (e.g., `WeeklyEventsDigest`). Resolved by searching `/tasks/` then `/plugins/*/tasks/`
- `sct_task_config` — JSONB for task-specific configuration (e.g., `{"mailing_list_id": 3}`). Populated from admin form based on `config_fields` in the task's JSON file
- `sct_schedule_day_of_week` — 0=Sunday through 6=Saturday; NULL means daily
- `sct_schedule_time` — Time of day, in site timezone
- `sct_last_run_status` — `success`, `error`, `skipped`

**Key method: `is_due()`** — Returns true when:
1. Task is active
2. Current time is past `sct_schedule_time` today (site timezone)
3. If `sct_schedule_day_of_week` is set, today matches that day
4. `sct_last_run_time` is not already today

**MultiScheduledTask** — Filters: `active`, `deleted`.

## Task Interface

**File:** `includes/ScheduledTaskInterface.php`

```php
interface ScheduledTaskInterface {
    /**
     * @param array $config  Task-specific configuration from sct_task_config
     * @return string  'success', 'error', or 'skipped'
     */
    public function run(array $config): string;
}
```

## Task Discovery

The system discovers available tasks by scanning for `.json` files in:
1. `/tasks/*.json`
2. `/plugins/*/tasks/*.json`

For each JSON file found, it checks whether a matching `.php` class file exists. The admin page shows all discovered tasks, indicating which are activated (have a DB row) and which are available but not yet activated.

## Admin Page

**File:** `adm/admin_scheduled_tasks.php`

Shows two sections:

**Active Tasks** — Tasks with a DB row:
- Name, Description, Schedule, Last Run (time + status), Active toggle
- Edit schedule (day of week, time)
- Edit task-specific config (form fields rendered from `config_fields` in JSON)
- "Run Now" button
- Deactivate

**Available Tasks** — Discovered on disk but not yet activated:
- Name and description (from JSON)
- "Activate" button (creates DB row with defaults from JSON, prompts for required config fields)

## Cron Runner

**File:** `cron/process_scheduled_tasks.php`

A single cron entry hits this one file. It is the sole timing source for all scheduled tasks — no complex cron configs. The file itself decides what's due and runs it.

- Bootstraps the application via `PathHelper`
- Rejects non-CLI access
- Updates `scheduled_tasks_last_cron_run` setting to current timestamp
- Loads all active, non-deleted `ScheduledTask` records
- For each where `is_due()` is true:
  - Resolves the task class from `/tasks/` or `/plugins/*/tasks/`
  - Calls `run($config)` with the row's `sct_task_config`
  - Updates `sct_last_run_time` and `sct_last_run_status`
- Outputs timestamped results to stdout

**Crontab (one line per site):**
```
*/15 * * * * php /var/www/html/{sitename}/public_html/cron/process_scheduled_tasks.php >> /var/www/html/{sitename}/logs/cron_scheduled_tasks.log 2>&1
```

### Cron Installation

**New installs:** `_site_init.sh` adds the crontab entry automatically during site setup.

**Existing sites:** The Scheduled Tasks admin page checks the `scheduled_tasks_last_cron_run` setting. If missing or older than 30 minutes, it displays a warning with the crontab line to copy/paste. Admins install it manually.

## First Task: WeeklyEventsDigest

**Files:** `tasks/WeeklyEventsDigest.php` + `tasks/WeeklyEventsDigest.json`

Implements `ScheduledTaskInterface`. Queries upcoming events, builds an email, and queues it through the existing email pipeline.

**Behavior:**
1. Read `mailing_list_id` from `$config` — if not configured, return `skipped`
2. Query `MultiEvent` with `upcoming`, `deleted => false`, `status_not_cancelled`, `exclude_recurring_parents`, sorted by `evt_start_time ASC`, limit 20
3. Filter to events starting within the next 7 days
4. If none, return `skipped`
5. For each event, build an HTML block with:
   - Event name (linked to event page)
   - Date and time (converted to site timezone)
   - Location (if set)
   - Short description (if set)
6. Wrap in heading, intro text, and "View All Events" button
7. Create an `Email` record with the generated HTML, linked to the mailing list, status `EMAIL_QUEUED`
8. Populate `EmailRecipient` records from mailing list subscribers
9. Return `success`

The existing send pipeline (`admin_emails_send.php`) handles delivery.

## Migration

The migration inserts:
1. An **admin menu item**: "Scheduled Tasks" under the System parent menu, pointing to `admin_scheduled_tasks`, permission level 10
2. A **setting**: `scheduled_tasks_last_cron_run` (initially empty) — updated by the cron runner on each execution, checked by the admin page to detect whether cron is active

## Files to Create

| File | Purpose |
|------|---------|
| `data/scheduled_tasks_class.php` | ScheduledTask + Multi class |
| `includes/ScheduledTaskInterface.php` | Task interface |
| `tasks/WeeklyEventsDigest.php` | Events digest task class |
| `tasks/WeeklyEventsDigest.json` | Events digest task config |
| `cron/process_scheduled_tasks.php` | Cron runner |
| `migrations/migration_scheduled_tasks_init.php` | Admin menu item |
| `adm/admin_scheduled_tasks.php` | Admin page |

## Files to Modify

| File | Change |
|------|--------|
| `migrations/migrations.php` | Add migration entry |
| `data/plugins_class.php` | Add scheduled task cleanup to `uninstall()` |
| `maintenance_scripts/install_tools/_site_init.sh` | Add crontab entry for new site installs |

## Plugin Uninstall Cleanup

When a plugin is uninstalled, scan the plugin's `/tasks/` directory for task class names and delete any matching `sct_scheduled_tasks` rows. Add this to the existing plugin uninstall logic.

## Adding New Tasks

1. Create `TaskName.php` and `TaskName.json` in `/tasks/` (or `/plugins/{plugin}/tasks/`)
2. The task appears automatically in the admin page under "Available Tasks"
3. Admin clicks "Activate" to enable it

## Testing Checklist

- [ ] Task discovery finds tasks in `/tasks/` and `/plugins/*/tasks/`
- [ ] Task discovery ignores `.json` files without matching `.php` files
- [ ] Admin page shows discovered tasks and active tasks
- [ ] Activating a task creates a DB row with defaults from JSON
- [ ] Deactivating a task sets `is_active` to false
- [ ] `is_due()` evaluates schedule correctly and prevents duplicate runs
- [ ] Cron runner loads and executes due tasks
- [ ] Cron runner rejects web access
- [ ] Activating a task with required config_fields prompts for values
- [ ] Task config saved to sct_task_config and passed to run()
- [ ] WeeklyEventsDigest returns `skipped` when no events or no mailing list configured
- [ ] WeeklyEventsDigest creates Email record with correct content
- [ ] WeeklyEventsDigest populates recipients from mailing list
- [ ] Existing send pipeline delivers the queued email
- [ ] Admin page supports "Run Now"
- [ ] Plugin uninstall removes that plugin's scheduled task rows

---

# Phase 2: Configurable Task Types (UI-Created Tasks)

## Overview

Phase 1 requires dropping PHP + JSON files on disk to add tasks. Phase 2 adds **built-in task types** that admins can configure entirely through the UI — no files, no code. An admin picks a task type, fills in parameters (data source, recipients, schedule), and saves.

Most useful scheduled tasks fall into a few repeating patterns. Rather than writing a custom class for each, Phase 2 provides configurable engines that cover the common cases. Custom file-based tasks (Phase 1) remain available for anything the built-in types can't handle.

## Task Types

### 1. Email Digest

Queries a data source, formats the results as HTML, and emails them to a recipient list.

**Configuration:**
- Data source: Events / Members / (extensible)
- Filter: Upcoming / New since last run / Expiring soon
- Time window: next N days / last N days
- Recipients: Mailing list (dropdown)
- Limit: max items to include

**Built-in data sources:**

| Source | Filter Options | Output per item |
|--------|---------------|-----------------|
| Events | Upcoming next N days | Name, date/time, location, short description, link |
| Events | New since last run | Name, date/time, location, short description, link |
| Members | New since last run | Name, join date |
| Members | Inactive for N days | Name, last login |
| Members | Membership expiring in N days | Name, expiration date |

**How it works:** A single `EmailDigestTask` class handles all email digest instances. It reads its configuration from the DB row, queries the appropriate data source, formats results using a standard HTML layout, creates an `Email` record, and queues it through the existing pipeline.

### 2. Data Cleanup

Finds old records and removes them.

**Configuration:**
- Target: Debug email logs / Soft-deleted records / Expired sessions
- Age threshold: older than N days
- Action: Permanent delete / Archive

**Built-in targets:**

| Target | What it deletes |
|--------|----------------|
| Debug email logs | `del_debug_email_logs` older than N days |
| Soft-deleted records | Any table with `delete_time` older than N days |
| Expired sessions | Old session data |

### 3. Admin Report

Aggregates stats and emails a summary to admins.

**Configuration:**
- Report type: Site activity / Email delivery stats
- Recipients: Admin email or mailing list

**Built-in reports:**

| Report | Content |
|--------|---------|
| Site activity | New members, events held, registrations — for the period since last run |
| Email delivery | Emails sent, failures, queue status — for the period since last run |

## Data Model

No schema changes needed — `sct_task_config` (JSONB) already exists from Phase 1. UI-created tasks use the same column, just with richer configuration:

```json
{
    "data_source": "events",
    "filter": "upcoming",
    "days": 7,
    "mailing_list_id": 3,
    "max_items": 20
}
```

The `sct_task_class` for UI-created tasks references the built-in engine (e.g., `EmailDigestTask`, `DataCleanupTask`, `AdminReportTask`).

## Admin UI Changes

Add a "Create Task" button to the admin page. The creation flow:

1. Pick a task type (Email Digest / Data Cleanup / Admin Report)
2. Fill in the type-specific configuration form
3. Set schedule (day of week, time)
4. Save — creates a DB row with the config

The admin page would show these alongside file-based tasks. UI-created tasks are fully editable; file-based tasks only allow schedule and active/inactive changes.

## Extensibility

Plugins could register additional data sources for the Email Digest type (e.g., a bookings plugin could add "Upcoming Bookings" as a source). This would use a hook or registry pattern — details TBD.

## Open Questions

- Should email digest formatting be customizable per task (template selection), or use a standard layout?
- Should data cleanup tasks require confirmation before first run, or trust the configuration?
- How granular should the member filters be (e.g., filter by group, subscription tier)?
- Should admin reports be a single flexible type, or one class per report?
