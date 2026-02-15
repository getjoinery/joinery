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
  BookingReminder.php
  BookingReminder.json
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
- `default_time` — Default time of day (HH:MM:SS, only used for `daily` and `weekly`)
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

### 3. Activate via Admin

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
| `sct_last_run_status` | varchar(50) | success/error/skipped |
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
- **Active Tasks** — Table with schedule, status, edit/run now/deactivate controls
- **Edit Form** — Schedule day/time and task-specific config fields
- **Available Tasks** — Discovered but not yet activated tasks with activate button

## Plugin Integration

### Task Discovery

Tasks in `/plugins/{plugin}/tasks/` are discovered automatically alongside core tasks. Each needs both a `.json` and `.php` file.

### Uninstall Cleanup

When a plugin is uninstalled, the system scans the plugin's `/tasks/` directory and permanently deletes any matching `sct_scheduled_tasks` rows. This happens in `Plugin::uninstall()`.

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
| `migrations/migration_scheduled_tasks_init.php` | Setup migration |
