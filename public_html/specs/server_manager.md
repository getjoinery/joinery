# Server Manager Spec

**Last Updated:** 2026-04-10

## Overview

A Joinery plugin + Go agent system for managing remote Joinery production servers from a single admin interface. The plugin provides the UI (node management, job triggering, status dashboards) and generates all commands. A Go agent daemon is a generic executor — it picks up jobs from a database queue, runs the commands via SSH, and writes output back. The agent has no knowledge of job types and never needs updating when new operations are added.

The management instance (joinerytest.site) acts as the control plane. Production servers are managed remotely — nothing needs to be installed on them beyond SSH access, which already exists.

## Current State

### Existing infrastructure that this system orchestrates

**Maintenance scripts** (in `/var/www/html/joinerytest/maintenance_scripts/`):
- `sysadmin_tools/backup_database.sh` (v3.0) — PostgreSQL dump with optional AES-256-CBC encryption
- `sysadmin_tools/backup_project.sh` (v2.1.0) — Full project backup (DB + files + Apache config)
- `sysadmin_tools/restore_database.sh` — Database restoration
- `sysadmin_tools/restore_project.sh` (v1.1.0) — Full project restore with dry-run support
- `sysadmin_tools/copy_database.sh` — Cross-server database copy
- `install_tools/deploy.sh` (v3.13) — Full deployment with syntax validation and rollback
- `install_tools/fix_permissions.sh` (v2.2) — Permission management

**Update system** (in `public_html/utils/`):
- `publish_upgrade.php` — Builds upgrade archives from source
- `upgrade.php` — Applies upgrades on target server (supports `--refresh-archives`, `--verbose`, `--dry-run`)

**Existing access:**
- SSH key at `~/.ssh/id_ed25519_claude` for docker-prod (23.239.11.53)
- Docker containers: `empoweredhealthtn`, `scrolldaddy`
- Each container runs a full Joinery instance with its own PostgreSQL

### No existing server management UI
No centralized management interface exists. All operations are performed manually via SSH.

## Architecture

```
joinerytest.site (control plane)
├── Plugin (server_manager)           ← Joinery plugin, admin UI
│   ├── Node registry                 ← Which servers/containers to manage
│   ├── Job queue                     ← Pending/running/completed operations
│   ├── Command generator             ← Builds step sequences for each job type
│   ├── Result processors             ← Parses completed job output into structured data
│   └── AJAX status endpoint          ← Live job output polling
│
├── Go Agent (joinery-agent)          ← systemd service, generic step executor
│   ├── DB poller                     ← Watches job queue for pending work
│   ├── Step runner                   ← Executes steps: ssh, scp, local
│   ├── Output streamer               ← Writes progressive output to DB
│   └── Heartbeat                     ← Agent liveness signal
│
└── Local PostgreSQL                  ← Communication channel (job queue tables)

Remote servers (managed)
├── docker-prod (23.239.11.53)
│   ├── empoweredhealthtn container
│   └── scrolldaddy container
└── (future servers)
```

### Smart Plugin, Dumb Agent

All job-type intelligence lives in the PHP plugin. The Go agent is a generic step executor that never changes when new operations are added.

**When an admin triggers an operation:**
1. Plugin looks up the target node's connection details (host, SSH key, container, etc.)
2. Plugin's command generator builds an ordered array of **steps** — each step is a primitive operation (run a command via SSH, copy a file via SCP, or run a command locally)
3. Plugin writes the job record with the steps embedded in `mjb_commands`
4. Agent picks up the job, executes each step in order, streams output
5. Agent marks job completed/failed
6. Plugin's result processor (optional, per job type) parses the raw output into structured data

**The agent understands exactly three primitives:**

| Primitive | What it does |
|-----------|-------------|
| `ssh` | Connect to a host via SSH, run a command (optionally wrapped in `docker exec` for containers) |
| `scp` | Copy a file between a remote host and the control plane |
| `local` | Run a command on the control plane itself (no SSH) |

**Example: what a `check_status` job looks like in the database:**
```json
{
    "steps": [
        {"type": "ssh", "label": "Check disk usage", "cmd": "df -h /"},
        {"type": "ssh", "label": "Check memory", "cmd": "free -m"},
        {"type": "ssh", "label": "Check uptime", "cmd": "uptime"},
        {"type": "ssh", "label": "Check PostgreSQL", "cmd": "pg_isready"},
        {"type": "ssh", "label": "Check Joinery version",
         "cmd": "grep VERSION /var/www/html/site/public_html/includes/version.php"}
    ]
}
```

The agent doesn't know this is a "status check." It just runs each step's command via SSH to the job's target node, captures output, and moves on.

**Example: what a `copy_database` job looks like (multi-node):**
```json
{
    "steps": [
        {"type": "ssh", "label": "Dump source database",
         "cmd": "pg_dump -U postgres mydb | gzip > /tmp/copy_abc123.sql.gz"},
        {"type": "scp", "label": "Download dump from source",
         "direction": "download", "remote_path": "/tmp/copy_abc123.sql.gz",
         "local_path": "/tmp/copy_abc123.sql.gz"},
        {"type": "scp", "label": "Upload dump to target",
         "direction": "upload", "local_path": "/tmp/copy_abc123.sql.gz",
         "remote_path": "/tmp/copy_abc123.sql.gz", "node_id": 2},
        {"type": "ssh", "label": "Restore on target",
         "cmd": "gunzip -c /tmp/copy_abc123.sql.gz | psql -U postgres mydb",
         "node_id": 2},
        {"type": "ssh", "label": "Clean up source",
         "cmd": "rm /tmp/copy_abc123.sql.gz"},
        {"type": "ssh", "label": "Clean up target",
         "cmd": "rm /tmp/copy_abc123.sql.gz", "node_id": 2},
        {"type": "local", "label": "Clean up control plane",
         "cmd": "rm /tmp/copy_abc123.sql.gz"}
    ]
}
```

**Step fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `type` | Yes | `ssh`, `scp`, or `local` |
| `label` | Yes | Human-readable step description (shown in UI output) |
| `cmd` | For ssh/local | The shell command to execute |
| `node_id` | No | Override target node (defaults to job's `mjb_mgn_node_id`). Agent looks up connection details from the node record |
| `on_host` | No | If `true`, run directly on SSH host even if node has a container configured (for commands like `docker stats`) |
| `direction` | For scp | `upload` or `download` (relative to control plane) |
| `remote_path` | For scp | File path on the remote host |
| `local_path` | For scp | File path on the control plane |
| `continue_on_error` | No | If `true`, don't abort the job if this step fails (useful for cleanup steps) |
| `timeout` | No | Max execution time in seconds for this step (default: 1800 = 30 min) |

**Adding a new job type = PHP only:**

To add a new operation (say, "restart Apache"), you:
1. Add a command generator method in the plugin's PHP helper class
2. Add an admin UI button that calls it
3. Done — no Go changes, no agent redeployment

```php
// In the plugin's command generator class
public static function build_restart_apache($node) {
    return [
        ['type' => 'ssh', 'label' => 'Restart Apache', 'cmd' => 'systemctl restart apache2'],
        ['type' => 'ssh', 'label' => 'Verify Apache status', 'cmd' => 'systemctl is-active apache2']
    ];
}
```

## Implementation Plan

**Plugin scaffold:**

```
plugins/server_manager/
├── plugin.json
├── data/
│   ├── managed_node_class.php       # ManagedNode + MultiManagedNode
│   ├── management_job_class.php     # ManagementJob + MultiManagementJob
│   └── agent_heartbeat_class.php    # AgentHeartbeat
├── includes/
│   ├── JobCommandBuilder.php        # Command generation (all job-type logic)
│   └── JobResultProcessor.php       # Output parsing and structured results
├── views/
│   └── admin/
│       ├── index.php                # /admin/server_manager → Dashboard
│       ├── nodes.php                # /admin/server_manager/nodes
│       ├── nodes_edit.php           # /admin/server_manager/nodes_edit
│       ├── backups.php              # /admin/server_manager/backups
│       ├── database.php             # /admin/server_manager/database
│       ├── updates.php              # /admin/server_manager/updates
│       ├── jobs.php                 # /admin/server_manager/jobs
│       └── job_detail.php           # /admin/server_manager/job_detail
├── ajax/
│   └── job_status.php               # Live job output polling
├── migrations/                      # SQL migrations for settings
└── uninstall.php                    # Clean removal of settings and non-table data
```

Admin pages use the auto-discovery pattern (`views/admin/`) so URLs are clean:
`/admin/server_manager/nodes` instead of `/plugins/server_manager/admin/admin_server_nodes`.

Each admin view starts with the standard permission check:
```php
$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only
```

`plugins/server_manager/plugin.json`:
```json
{
    "name": "Server Manager",
    "version": "1.0.0",
    "description": "Remote server management for Joinery instances",
    "author": "Joinery",
    "is_stock": true
}
```

**Data class: ManagedNode** (`plugins/server_manager/data/managed_node_class.php`)

```php
class ManagedNode extends SystemBase {
    public static $prefix = 'mgn';
    public static $tablename = 'mgn_managed_nodes';
    public static $pkey_column = 'mgn_id';
    public static $field_specifications = array(/* see fields below */);
}

class MultiManagedNode extends SystemMultiBase {
    public static $table_name = 'mgn_managed_nodes';
    public static $table_primary_key = 'mgn_id';
}
```

Represents a managed Joinery instance (a container on a host, or a bare-metal server).

| Field | Type | Purpose |
|-------|------|---------|
| `mgn_id` | serial PK | Primary key |
| `mgn_name` | varchar(100), NOT NULL | Display name (e.g., "Empowered Health Production") |
| `mgn_slug` | varchar(50), NOT NULL, UNIQUE | Short identifier (e.g., "empoweredhealthtn") |
| `mgn_host` | varchar(255), NOT NULL | SSH host (IP or hostname) |
| `mgn_ssh_user` | varchar(50), NOT NULL | SSH username (default: "root") |
| `mgn_ssh_key_path` | varchar(500) | Path to SSH private key on control plane |
| `mgn_ssh_port` | integer, default 22 | SSH port |
| `mgn_container_name` | varchar(100) | Docker container name (NULL for bare metal) |
| `mgn_container_user` | varchar(50) | User to run commands as inside container |
| `mgn_web_root` | varchar(500) | Web root path inside the server/container |
| `mgn_site_url` | varchar(500) | Public URL of the managed site |
| `mgn_joinery_version` | varchar(20) | Last known Joinery version |
| `mgn_last_status_check` | timestamp(6) | Last successful status check time |
| `mgn_last_status_data` | jsonb | Last status check results (disk, memory, etc.) |
| `mgn_enabled` | bool, default true | Whether this node is active |
| `mgn_notes` | text | Admin notes |
| `mgn_create_time` | timestamp(6) | Record creation |
| `mgn_update_time` | timestamp(6) | Last update |
| `mgn_delete_time` | timestamp(6) | Soft delete |

**Data class: ManagementJob** (`plugins/server_manager/data/management_job_class.php`)

```php
class ManagementJob extends SystemBase {
    public static $prefix = 'mjb';
    public static $tablename = 'mjb_management_jobs';
    public static $pkey_column = 'mjb_id';
    public static $json_vars = array('mjb_commands', 'mjb_parameters', 'mjb_result');
    public static $field_specifications = array(/* see fields below */);
}

class MultiManagementJob extends SystemMultiBase {
    public static $table_name = 'mjb_management_jobs';
    public static $table_primary_key = 'mjb_id';
}
```

Represents a queued, running, or completed operation.

| Field | Type | Purpose |
|-------|------|---------|
| `mjb_id` | serial PK | Primary key |
| `mjb_mgn_node_id` | integer, FK → mgn_id | Target node |
| `mjb_job_type` | varchar(50), NOT NULL | Operation label for display/filtering (e.g., "backup_database") — not used by agent |
| `mjb_status` | varchar(20), NOT NULL, default 'pending' | pending / running / completed / failed / cancelled |
| `mjb_commands` | jsonb, NOT NULL | Ordered array of steps for the agent to execute (see Step fields above) |
| `mjb_parameters` | jsonb | Original input parameters for reference and re-run capability |
| `mjb_output` | text | Progressive output log (appended during execution) |
| `mjb_result` | jsonb | Structured result data — populated by plugin's result processor after completion |
| `mjb_current_step` | integer, default 0 | Index of the step currently executing (for progress display) |
| `mjb_total_steps` | integer | Total number of steps in the job |
| `mjb_error_message` | text | Error details on failure |
| `mjb_created_by` | integer, FK → usr_user_id | User who triggered the job |
| `mjb_started_time` | timestamp(6) | When agent began execution |
| `mjb_completed_time` | timestamp(6) | When job finished |
| `mjb_create_time` | timestamp(6) | Record creation |
| `mjb_update_time` | timestamp(6) | Last update |
| `mjb_delete_time` | timestamp(6) | Soft delete |

**Data class: AgentHeartbeat** (`plugins/server_manager/data/agent_heartbeat_class.php`)

Single-row table tracking agent liveness.

| Field | Type | Purpose |
|-------|------|---------|
| `ahb_id` | serial PK | Primary key |
| `ahb_agent_name` | varchar(100), NOT NULL, UNIQUE | Agent identifier |
| `ahb_last_heartbeat` | timestamp(6) | Last heartbeat time (UTC) |
| `ahb_agent_version` | varchar(20) | Agent software version |
| `ahb_status` | varchar(20) | Agent self-reported status |
| `ahb_create_time` | timestamp(6) | Record creation |
| `ahb_update_time` | timestamp(6) | Last update |

**Go agent** (source: `/home/user1/joinery-agent/`):

The agent is small and generic. It has no knowledge of job types — it just executes steps.

Core structure:
```
joinery-agent/
├── main.go              # Entry point, config loading, main poll loop
├── config.go            # Configuration (DB connection, poll interval, agent name)
├── db.go                # PostgreSQL connection, job claiming, output writing, heartbeat
├── runner.go            # Step executor: reads step type, dispatches to ssh/scp/local
├── ssh.go               # SSH connection pooling and command execution
├── scp.go               # SCP file transfer (upload/download)
├── server.go            # Node lookup (reads mgn_managed_nodes for connection details)
├── Makefile             # Build, test, release (same pattern as scrolldaddy-dns)
├── go.mod
├── go.sum
├── install/
│   └── joinery-agent-installer.sh  # systemd service installer
└── config/
    └── joinery-agent.env.example    # Example config
```

Agent config (`/etc/joinery-agent/joinery-agent.env`):
```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=joinerytest
DB_USER=postgres
DB_PASSWORD=...
POLL_INTERVAL=5s
AGENT_NAME=joinerytest-agent
```

Agent behavior:
- **Startup**: Connects to local PostgreSQL, recovers any stale `running` jobs (marks them `failed` — see Safety Constraint #3)
- **Poll loop** (single-threaded — see Safety Constraint #5):
  1. Query for oldest `pending` job
  2. Check per-node concurrency lock — skip if another job is `running` on the same node (see Safety Constraint #2)
  3. Claim job: `UPDATE ... SET status = 'running', started_time = now() WHERE status = 'pending' RETURNING *`
  4. Read `mjb_commands` JSON — an ordered array of steps
  5. Execute each step sequentially:
     - `ssh` step: look up node (from step's `node_id` or job's `mjb_mgn_node_id`), SSH to host, wrap in `docker exec` if node has a container (unless `on_host: true`), run command, capture output
     - `scp` step: look up node, SCP file in the specified direction
     - `local` step: run command on control plane via `exec.Command`
  6. Each step enforced with a timeout (default 30 min — see Safety Constraint #4)
  7. After each step: append `[step label]` header + output to `mjb_output`, update `mjb_current_step`
  8. If a step fails (non-zero exit or timeout): mark job `failed` with error message, skip remaining steps (unless `continue_on_error: true`)
  9. On completion: mark job `completed`, set `mjb_completed_time`
- SSH connection pooling: reuse connections to the same host within a job (avoids reconnecting per step)
- Updates heartbeat row every 30 seconds
- Logs to stdout (systemd captures to journal)

**This is the entire agent.** It never needs updating for new job types. All intelligence about what commands to run lives in the plugin's PHP command generators.

**Deployment** (same pattern as ScrollDaddy DNS):
```bash
cd /home/user1/joinery-agent
make release VERSION=1.0.0
# Installer is self-contained, installs binary + systemd unit
# First run: edit /etc/joinery-agent/joinery-agent.env, then start
sudo systemctl start joinery-agent
```

**Plugin command generator class:**

`plugins/server_manager/includes/JobCommandBuilder.php` — Static methods that build step arrays for each job type. This is where all job-type knowledge lives.

```php
class JobCommandBuilder {
    // Each method takes a ManagedNode (and job-specific params) and returns a steps array
    public static function build_check_status($node) { ... }
    public static function build_backup_database($node, $params) { ... }
    public static function build_copy_database($source_node, $target_node, $params) { ... }
    public static function build_apply_update($node, $params) { ... }
    // Adding a new job type = adding a new method here. No Go changes needed.
}
```

**Plugin result processor class:**

`plugins/server_manager/includes/JobResultProcessor.php` — Optional post-processing that runs when the plugin detects a completed job. Parses raw output into structured data.

```php
class JobResultProcessor {
    // Called by the AJAX endpoint or dashboard when a job is newly completed
    public static function process($job) {
        $type = $job->get('mjb_job_type');
        $method = 'process_' . $type;
        if (method_exists(self::class, $method)) {
            self::$method($job);
        }
    }
    
    // Parses df/free/uptime output into JSON, updates node's mgn_last_status_data
    private static function process_check_status($job) { ... }
    
    // Extracts backup file path and size from output
    private static function process_backup_database($job) { ... }
}
```

### Admin Pages

All admin pages use AdminPage, require permission 10, and share a navigation partial.

`views/admin/nodes.php` — List all managed nodes with status indicators (`/admin/server_manager/nodes`)
- Table columns: Name, Host, Container, Site URL, Last Status, Version, Actions
- Status indicators: green (checked in < 5 min), yellow (< 30 min), red (> 30 min or never), gray (disabled)
- Actions: Edit, Check Status Now, View Jobs

`views/admin/nodes_edit.php` — Add/edit a managed node (`/admin/server_manager/nodes_edit`)
- Form fields for all mgn_ columns
- "Test Connection" button (creates a `test_connection` job, shows result inline)
- SSH key path uses a dropdown/text field (keys in `~/.ssh/`)

`views/admin/index.php` — Overview dashboard (`/admin/server_manager`)
- Agent status (online/offline based on heartbeat, with version)
- Node cards showing: name, URL, last check time, disk/memory summary, version
- Recent jobs list (last 20)
- Quick action buttons per node

**Status check command generator** (`JobCommandBuilder::build_check_status`):

Generates steps from the node's configuration:
```php
public static function build_check_status($node) {
    $web_root = $node->get('mgn_web_root');
    $steps = [
        ['type' => 'ssh', 'label' => 'Check disk usage', 'cmd' => 'df -h /'],
        ['type' => 'ssh', 'label' => 'Check memory', 'cmd' => 'free -m'],
        ['type' => 'ssh', 'label' => 'Check uptime', 'cmd' => 'uptime'],
        ['type' => 'ssh', 'label' => 'Check PostgreSQL', 'cmd' => 'pg_isready'],
        ['type' => 'ssh', 'label' => 'Check Joinery version',
         'cmd' => "grep VERSION {$web_root}/public_html/includes/version.php"],
        ['type' => 'ssh', 'label' => 'Recent errors',
         'cmd' => "grep -i 'fatal\\|error\\|exception' {$web_root}/logs/error.log | tail -20",
         'continue_on_error' => true],
    ];
    // Add container-level checks if this is a Docker node
    if ($node->get('mgn_container_name')) {
        $container = $node->get('mgn_container_name');
        $steps[] = ['type' => 'ssh', 'label' => 'Container stats',
                    'cmd' => "docker stats --no-stream {$container}", 'on_host' => true];
    }
    return $steps;
}
```

**Result processor** (`JobResultProcessor::process_check_status`):

After job completes, parses the raw output from each step into structured JSON and updates the node record:
```php
// Parses df/free/uptime output → updates mgn_last_status_data and mgn_last_status_check
// Extracts version string → updates mgn_joinery_version
```

**AJAX endpoint for live job output:**

`plugins/server_manager/ajax/job_status.php`
- Input: `job_id`, `output_offset` (character position to read from)
- Output: JSON with `status`, `new_output` (text since offset), `result` (if completed)
- Permission: level 10 required
- The UI polls this every 2-3 seconds while a job is running

### Command Generators

All command generators are static methods on `JobCommandBuilder`. Each returns a steps array.

**Backup:**

`JobCommandBuilder::build_backup_database($node, $params)` — Generates steps to run the existing `backup_database.sh`:
```php
// Params: encryption (bool), compression (bool), backup_label (string)
// Steps: run backup_database.sh with appropriate flags, then ls -la the output file
```

`JobCommandBuilder::build_backup_project($node, $params)` — Generates steps to run `backup_project.sh`:
```php
// Params: encryption (bool), include_uploads (bool)
// Steps: run backup_project.sh with flags
```

`JobCommandBuilder::build_fetch_backup($node, $params)` — SCPs a backup file to the control plane:
```php
// Params: remote_path (string)
// Steps: single SCP download step
```

**Database:**

`JobCommandBuilder::build_copy_database($source_node, $target_node, $params)` — The most complex command sequence, using all three primitives:
```php
// Generates the multi-node step sequence shown in the Architecture section above:
// 1. SSH to source → pg_dump
// 2. SCP download dump from source to control plane
// 3. SCP upload dump to target
// 4. SSH to target → pg_restore
// 5. Cleanup steps (continue_on_error: true)
```

Safety: The `confirm_overwrite` parameter must be true. The plugin UI shows a confirmation dialog with the target database name before creating the job.

**Database restore command generator:**

`JobCommandBuilder::build_restore_database($node, $params)` — Restores a backup file that already exists on the target server:
```php
// Params: backup_path (string), confirm_overwrite (bool)
// Steps: gunzip + psql restore, then verify
```

**Updates:**

`JobCommandBuilder::build_apply_update($node, $params)` — Runs `upgrade.php` on target:
```php
// Params: dry_run (bool)
// Steps: cd to web root, run php utils/upgrade.php --verbose [--dry-run]
```

`JobCommandBuilder::build_refresh_archives($node, $params)` — Runs upgrade with `--refresh-archives`:
```php
// Steps: cd to web root, run php utils/upgrade.php --refresh-archives --verbose
```

`JobCommandBuilder::build_publish_upgrade($params)` — Runs locally (no node needed):
```php
// Params: release_notes (string)
// Steps: single 'local' type step running publish_upgrade.php
public static function build_publish_upgrade($params) {
    $notes = escapeshellarg($params['release_notes']);
    return [
        ['type' => 'local', 'label' => 'Publish upgrade',
         'cmd' => "cd /var/www/html/joinerytest/public_html && php utils/publish_upgrade.php {$notes}"]
    ];
}
```

### All Files

**Plugin (`plugins/server_manager/`):**

| File | Purpose |
|------|---------|
| `plugin.json` | Plugin metadata |
| `uninstall.php` | Clean removal of settings |
| `data/managed_node_class.php` | ManagedNode + MultiManagedNode models |
| `data/management_job_class.php` | ManagementJob + MultiManagementJob models |
| `data/agent_heartbeat_class.php` | AgentHeartbeat model |
| `includes/JobCommandBuilder.php` | Command generation — all job-type logic lives here |
| `includes/JobResultProcessor.php` | Output parsing and structured results |
| `ajax/job_status.php` | Live job output polling (AJAX endpoint) |
| `views/admin/index.php` | Dashboard — agent status, node cards, recent jobs (`/admin/server_manager`) |
| `views/admin/nodes.php` | Node list with status indicators (`/admin/server_manager/nodes`) |
| `views/admin/nodes_edit.php` | Node add/edit form with test connection (`/admin/server_manager/nodes_edit`) |
| `views/admin/backups.php` | Backup history, filter by node, download links (`/admin/server_manager/backups`) |
| `views/admin/database.php` | Database copy/restore with confirmation dialogs (`/admin/server_manager/database`) |
| `views/admin/updates.php` | Version comparison, publish, apply, bulk update (`/admin/server_manager/updates`) |
| `views/admin/jobs.php` | Job history with filters (`/admin/server_manager/jobs`) |
| `views/admin/job_detail.php` | Single job output, re-run, cancel (`/admin/server_manager/job_detail`) |

**Go Agent (`/home/user1/joinery-agent/`):**

| File | Purpose |
|------|---------|
| `main.go` | Entry point, config loading, poll loop |
| `config.go` | Configuration struct and env loading |
| `db.go` | PostgreSQL connection, job claiming, output writing, heartbeat |
| `runner.go` | Step executor — dispatches to ssh/scp/local |
| `ssh.go` | SSH connection pooling and command execution |
| `scp.go` | SCP file transfer (upload/download) |
| `server.go` | Node lookup (reads mgn_managed_nodes for connection details) |
| `Makefile` | Build, test, release |
| `go.mod` | Go module definition |
| `install/joinery-agent-installer.sh` | systemd service installer |
| `config/joinery-agent.env.example` | Example config |

## Job Types Summary

| Job Type | Description | Destructive | Requires Confirmation |
|----------|-------------|-------------|----------------------|
| `test_connection` | Verify SSH connectivity to node | No | No |
| `check_status` | Gather system health metrics | No | No |
| `backup_database` | PostgreSQL dump | No | No |
| `backup_project` | Full project backup (DB + files) | No | No |
| `fetch_backup` | SCP backup file to control plane | No | No |
| `copy_database` | Copy DB from one node to another | **Yes** | **Yes** |
| `restore_database` | Restore DB from backup file | **Yes** | **Yes** |
| `apply_update` | Run upgrade.php on target | **Yes** | **Yes** |
| `refresh_archives` | Rebuild + apply upgrade archives | **Yes** | **Yes** |
| `publish_upgrade` | Build upgrade archives from source | No | No |

## Safety Constraints

### 1. Auto-backup before destructive operations

The `JobCommandBuilder` automatically prepends a database backup step before any destructive database operation. This is not optional — it's built into the command generators.

Affected job types: `copy_database`, `restore_database`

```php
// Inside JobCommandBuilder::build_copy_database()
// Step 0 is ALWAYS a backup of the target database
$config_path = self::get_config_path($target_node);
$steps = [
    ['type' => 'ssh', 'label' => 'Auto-backup target database before overwrite',
     'cmd' => "DB_NAME=\$(grep -oP \"dbname\\s*=\\s*'\\K[^']+\" {$config_path}) && "
            . "DB_USER=\$(grep -oP \"dbuser\\s*=\\s*'\\K[^']+\" {$config_path}) && "
            . "pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_overwrite_\$(date +%Y%m%d_%H%M%S).sql.gz",
     'node_id' => $target_node->key],
    // ... then the actual destructive steps follow
];
```

If the auto-backup step fails, the entire job aborts. The destructive steps never run.

### 2. Per-node concurrency lock

The agent refuses to start a job on a node that already has a `running` job. This prevents conflicting operations (e.g., backup + update running simultaneously on the same server).

Implementation: When claiming a job, the agent checks:
```sql
SELECT COUNT(*) FROM mjb_management_jobs 
WHERE mjb_mgn_node_id = $node_id 
AND mjb_status = 'running'
AND mjb_id != $this_job_id
```

If another job is running on the same node, the agent skips this job and moves on — it stays `pending` and will be picked up once the other job completes. The dashboard shows a "queued behind job #X" indicator.

Exception: Jobs with `mjb_mgn_node_id = NULL` (like `publish_upgrade` which runs locally) are exempt from node locking.

### 3. Stale job recovery

If the agent crashes mid-job, that job stays `running` forever. On startup, the agent checks for orphaned jobs:

```sql
SELECT * FROM mjb_management_jobs 
WHERE mjb_status = 'running'
```

Any job found in `running` state when the agent starts is marked `failed` with:
- `mjb_error_message`: "Agent restarted while job was running. Job may have partially completed."
- `mjb_completed_time`: now

The dashboard flags these with a warning icon so the admin can inspect what happened.

### 4. Step timeout

Each step has a maximum execution time. If a command (SSH, SCP, or local) exceeds the timeout, the agent:
1. Kills the SSH session / process
2. Appends "[TIMEOUT after Xs]" to `mjb_output`
3. Marks the job `failed`

Default: 30 minutes per step. Overridable per step with a `timeout` field (in seconds):
```json
{"type": "ssh", "label": "Long backup", "cmd": "pg_dump ...", "timeout": 3600}
```

### 5. Single-threaded agent (v1)

The agent processes one job at a time. While a job is running, no other jobs are claimed. This simplifies the per-node lock (only one thing ever runs), avoids SSH connection contention, and makes output/logging straightforward.

The poll loop:
1. Check for pending jobs
2. If found, claim one and execute it to completion
3. Go back to step 1

This means if 5 jobs are queued, they run sequentially. For v1 this is fine — the operations themselves are the bottleneck, not the queue throughput.

### 6. Remote database credentials

Commands that need database credentials (backup, copy, restore) extract them at runtime from the remote server's `Globalvars_site.php`. No credentials are stored on the control plane.

The config file path is derived from the node's `mgn_web_root`:
```
{mgn_web_root}/../config/Globalvars_site.php
```

For example, if `mgn_web_root` is `/var/www/html/site/public_html`, the config is at `/var/www/html/site/config/Globalvars_site.php`.

Helper method in `JobCommandBuilder`:
```php
private static function get_config_path($node) {
    $web_root = rtrim($node->get('mgn_web_root'), '/');
    // web_root is the public_html directory; config is one level up
    return dirname($web_root) . '/config/Globalvars_site.php';
}

private static function get_db_credentials_script($node) {
    $config = self::get_config_path($node);
    return "DB_NAME=\$(grep -oP \"dbname\\s*=\\s*'\\K[^']+\" {$config}) && "
         . "DB_USER=\$(grep -oP \"dbuser\\s*=\\s*'\\K[^']+\" {$config})";
}
```

Usage in command generators:
```php
$creds = self::get_db_credentials_script($node);
$steps[] = ['type' => 'ssh', 'label' => 'Backup database',
            'cmd' => "{$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/backup.sql.gz"];
```

This ensures credentials are always current (read at execution time, not stored) and never leave the remote server.

## Security Considerations

- **Plugin access**: Permission level 10 (superadmin) only — no lower-level access
- **Agent permissions**: Runs as `user1` (has SSH keys), not as root. Sudo only if explicitly needed for specific operations
- **SSH keys**: Stored as file paths in the database, not key contents. Keys remain on disk with proper permissions
- **Destructive operations**: Require explicit `confirm_overwrite`/confirmation flag in job parameters. Plugin UI shows confirmation dialogs before creating these jobs
- **Command generation is server-side only**: The agent executes commands from `mjb_commands`, but those commands are generated entirely by the plugin's PHP code on the server. Users never supply raw commands — they click buttons in the admin UI, and the plugin's `JobCommandBuilder` translates that into safe, parameterized step sequences
- **Job authentication**: All jobs record `mjb_created_by` (the user who triggered them). Agent verifies the job was created by a permission-10 user before executing
- **Database communication**: Agent connects to local PostgreSQL only — no network-exposed API on the agent
- **No external attack surface on the agent**: The agent has no HTTP listener. The only way to make it do anything is to insert a row into the job queue table, which requires direct database access

## Go Agent Dependencies

```
github.com/jackc/pgx/v5    — PostgreSQL driver
golang.org/x/crypto/ssh    — SSH client and SCP
github.com/joho/godotenv   — Environment file loading
```

Minimal, stable dependency set. The agent is generic enough that these three dependencies cover everything it will ever need to do. No web framework, no job-type-specific libraries — all domain logic lives in the PHP plugin.

## Not In Scope (for initial implementation)

- **Scheduled/recurring jobs** — Can be added later using Joinery's scheduled task system or cron on the agent side
- **Multi-agent support** — Single agent is sufficient. The architecture supports multiple agents (via the claim pattern) but no UI for managing them
- **Backup rotation/retention policies** — Manage manually for now
- **Alerting/notifications** — No email/Slack alerts on job failure (can be added later)
- **File management** — No browsing/editing files on remote servers
- **Log viewer** — No real-time log streaming from remote servers (status check captures recent errors, but no live tail)
- **SSL certificate management** — Out of scope
- **Container lifecycle** — No starting/stopping/creating containers (use Docker CLI directly)

## Documentation

During implementation, add developer documentation to `/docs/server_manager.md` covering:
- Plugin architecture and data models
- Go agent setup and configuration
- Adding new job types
- Deployment and upgrade procedures
