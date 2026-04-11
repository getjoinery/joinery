# Server Manager

The Server Manager plugin provides a web UI for managing remote Joinery production servers. Operations include status checks, backups, database copies, and applying updates -- all from the admin interface at `/admin/server_manager`.

The system has two components:
- **PHP plugin** (`plugins/server_manager/`) -- admin UI, job creation, command generation
- **Go agent** (`/home/user1/joinery-agent/`) -- generic step executor that polls the job queue and runs commands via SSH

## Quick Start

### 1. Install the plugin

The plugin is already in the `plugins/` directory. From the admin panel:

1. Go to `/admin/admin_plugins`
2. Click **Actions** on "Server Manager" and choose **Install**
3. Click **Actions** again and choose **Activate**

The plugin creates three database tables automatically: `mgn_managed_nodes`, `mjb_management_jobs`, `ahb_agent_heartbeats`.

### 2. Install and start the Go agent

#### Build

```bash
cd /home/user1/joinery-agent
make release VERSION=1.0.0
```

This compiles the binary and packages it into `joinery-agent-installer.sh` — a self-extracting script that handles both fresh installs and upgrades.

#### Install

```bash
sudo bash joinery-agent-installer.sh --verbose
```

This creates:
- `/usr/local/bin/joinery-agent` — the binary
- `/etc/systemd/system/joinery-agent.service` — systemd unit
- `/etc/joinery-agent/joinery-agent.env` — configuration (from example, first install only)

#### Configure (usually not needed)

The agent reads database credentials directly from `Globalvars_site.php` — no manual configuration required on a standard Joinery install.

The default config path is `/var/www/html/joinerytest/config/Globalvars_site.php`. If your install is at a different path, set it in the env file:

```bash
sudo nano /etc/joinery-agent/joinery-agent.env
# Set: JOINERY_CONFIG=/var/www/html/mysite/config/Globalvars_site.php
```

Other optional settings in the env file:

| Setting | Default | Purpose |
|---------|---------|---------|
| `JOINERY_CONFIG` | `/var/www/html/joinerytest/config/Globalvars_site.php` | Path to Globalvars_site.php |
| `POLL_INTERVAL` | `5s` | How often to check for new jobs |
| `HEARTBEAT_INTERVAL` | `30s` | How often to update the dashboard status |
| `AGENT_NAME` | `joinery-agent` | Name shown in the admin dashboard |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | _(from Globalvars)_ | Override DB credentials if needed |

#### Start

```bash
sudo systemctl start joinery-agent
sudo systemctl status joinery-agent
```

The dashboard at `/admin/server_manager` should now show **Agent Status: Online**.

If anything is wrong, the agent logs to systemd journal:

```bash
journalctl -u joinery-agent -f
```

Common startup errors are self-explanatory — missing `DB_NAME`, wrong password, or plugin tables not installed. Each error message tells you exactly what to fix.

#### Upgrade

```bash
cd /home/user1/joinery-agent
make release VERSION=1.x.x
sudo bash joinery-agent-installer.sh --verbose
```

The installer auto-detects upgrades: stops the service, swaps the binary, restarts, and rolls back automatically if the new version fails to start.

### 3. Add managed nodes

Go to `/admin/server_manager/nodes_edit`. There are two ways to add nodes:

#### Auto-detect (recommended)

The **Auto-Detect Joinery Servers** panel at the top of the page scans a remote host for Joinery instances automatically. Enter:

1. **SSH Host** -- the server IP (e.g., `23.239.11.53`)
2. **SSH Key Path** -- path to the private key on the control plane (defaults to `/home/user1/.ssh/id_ed25519_claude`)
3. Click **Detect**

The plugin creates a `discover_nodes` job. The Go agent SSHes to the host, finds Docker containers (or bare-metal installs) running Joinery, and reports back with each instance's container name, web root, domain, database name, and version.

Detected instances appear as cards with **Add This Node** buttons. Clicking one auto-fills the entire form below -- just click **Add Node** to save.

Auto-detect requires the Go agent to be running (it executes the SSH commands, not PHP).

#### Manual

Fill in the form fields directly:

| Field | Example (Empowered Health) | Example (ScrollDaddy) |
|-------|---------------------------|----------------------|
| Display Name | Empowered Health Production | ScrollDaddy Production |
| Slug | empoweredhealthtn | scrolldaddy |
| SSH Host | 23.239.11.53 | 23.239.11.53 |
| SSH User | root | root |
| SSH Key Path | /home/user1/.ssh/id_ed25519_claude | /home/user1/.ssh/id_ed25519_claude |
| SSH Port | 22 | 22 |
| Docker Container | empoweredhealthtn | scrolldaddy |
| Container User | _(blank)_ | _(blank)_ |
| Web Root | /var/www/html/empoweredhealthtn/public_html | /var/www/html/scrolldaddy/public_html |
| Site URL | https://empoweredhealthtn.com | https://scrolldaddy.app |

Click **Add Node**, then use **Test Connection** to verify SSH access.

## Admin Pages

All pages are at `/admin/server_manager/...` and require permission level 10 (superadmin).

| URL | Purpose |
|-----|---------|
| `/admin/server_manager` | Dashboard -- agent status, node cards with health data, recent jobs |
| `/admin/server_manager/nodes` | List all managed nodes with status indicators |
| `/admin/server_manager/nodes_edit` | Add or edit a node, test connection, trigger status check |
| `/admin/server_manager/backups` | Run database or project backups, fetch backup files |
| `/admin/server_manager/database` | Copy database between nodes, restore from backup |
| `/admin/server_manager/updates` | Version comparison across nodes, publish/apply updates |
| `/admin/server_manager/jobs` | Job history with filters by node, status, and type |
| `/admin/server_manager/job_detail?job_id=N` | Single job output with live polling |

## Job Types

| Job Type | Description | Destructive |
|----------|-------------|-------------|
| `test_connection` | Verify SSH connectivity to a node | No |
| `check_status` | Gather disk, memory, uptime, PostgreSQL, version info | No |
| `backup_database` | Run `backup_database.sh` on the remote server | No |
| `backup_project` | Run `backup_project.sh` (DB + files + Apache config) | No |
| `fetch_backup` | SCP a backup file from remote to control plane | No |
| `copy_database` | Dump source DB, transfer, restore on target | **Yes** |
| `restore_database` | Restore a backup file on a node | **Yes** |
| `apply_update` | Run `upgrade.php` on target (supports `--dry-run`) | **Yes** |
| `refresh_archives` | Run `upgrade.php --refresh-archives` on target | **Yes** |
| `publish_upgrade` | Run `publish_upgrade.php` locally on control plane | No |
| `discover_nodes` | Scan a remote host for Joinery instances (Docker + bare metal) | No |

Destructive operations auto-backup the target database before proceeding. The UI requires explicit confirmation checkboxes.

## How It Works: Smart Plugin, Dumb Agent

All job-type intelligence lives in `JobCommandBuilder.php`. The Go agent is a generic executor that understands only three primitives: `ssh`, `scp`, and `local`.

**When an admin triggers an operation:**

1. PHP looks up the node's connection details (host, SSH key, container, etc.)
2. `JobCommandBuilder::build_<type>()` generates an ordered array of steps
3. PHP writes a job row with the steps in `mjb_commands` (JSON)
4. Go agent picks up the job, executes each step in order, streams output
5. Agent marks job completed or failed
6. `JobResultProcessor` optionally parses the output into structured data

**Example: what a `check_status` job looks like in the database:**

```json
{
    "steps": [
        {"type": "ssh", "label": "Check disk usage", "cmd": "df -h /"},
        {"type": "ssh", "label": "Check memory", "cmd": "free -m"},
        {"type": "ssh", "label": "Check uptime", "cmd": "uptime"},
        {"type": "ssh", "label": "Check PostgreSQL", "cmd": "pg_isready"},
        {"type": "ssh", "label": "Check Joinery version",
         "cmd": "grep VERSION /var/www/html/site/public_html/includes/version.php"},
        {"type": "ssh", "label": "Container stats",
         "cmd": "docker stats --no-stream empoweredhealthtn", "on_host": true}
    ]
}
```

The agent doesn't know this is a "status check." It just runs each step's command via SSH, captures output, and moves on.

## Adding a New Job Type

Adding a new operation requires PHP changes only -- no Go rebuild needed.

1. Add a static method to `JobCommandBuilder`:

```php
// plugins/server_manager/includes/JobCommandBuilder.php
public static function build_restart_apache($node) {
    return [
        ['type' => 'ssh', 'label' => 'Restart Apache',
         'cmd' => 'systemctl restart apache2'],
        ['type' => 'ssh', 'label' => 'Verify Apache status',
         'cmd' => 'systemctl is-active apache2'],
    ];
}
```

2. Add a UI trigger (button/form) in the appropriate admin view that calls:

```php
$steps = JobCommandBuilder::build_restart_apache($node);
$job = ManagementJob::createJob($node->key, 'restart_apache', $steps, null, $session->get_user_id());
header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
```

3. Optionally add a result processor method in `JobResultProcessor` if you want to parse the output into structured data.

## Step Fields Reference

| Field | Required | Description |
|-------|----------|-------------|
| `type` | Yes | `ssh`, `scp`, or `local` |
| `label` | Yes | Human-readable description (shown in UI and output) |
| `cmd` | ssh/local | Shell command to execute |
| `node_id` | No | Override target node (defaults to job's node). Used for multi-node operations like `copy_database` |
| `on_host` | No | If `true`, run on the SSH host directly, not inside the Docker container. Used for `docker stats`, etc. |
| `direction` | scp | `upload` (local to remote) or `download` (remote to local) |
| `remote_path` | scp | File path on the remote host |
| `local_path` | scp | File path on the control plane |
| `continue_on_error` | No | If `true`, don't abort the job when this step fails. Used for cleanup steps. |
| `timeout` | No | Max seconds for this step (default: 1800 = 30 minutes) |

## Data Models

### ManagedNode (`mgn_managed_nodes`)

Represents a remote Joinery instance. Key fields:

- `mgn_name` -- Display name (e.g., "Empowered Health Production")
- `mgn_slug` -- Short identifier, unique (e.g., "empoweredhealthtn")
- `mgn_host` -- SSH host (IP or hostname)
- `mgn_ssh_user`, `mgn_ssh_key_path`, `mgn_ssh_port` -- SSH connection details
- `mgn_container_name` -- Docker container name (null for bare metal)
- `mgn_web_root` -- Path to `public_html` inside the server/container
- `mgn_last_status_data` -- JSON from last status check (disk, memory, load, etc.)
- `mgn_joinery_version` -- Last known version string

### ManagementJob (`mjb_management_jobs`)

Represents a queued, running, or completed operation. Key fields:

- `mjb_mgn_node_id` -- Target node (FK to mgn_managed_nodes, null for local-only jobs)
- `mjb_job_type` -- Label for display/filtering (e.g., "backup_database")
- `mjb_status` -- `pending`, `running`, `completed`, `failed`, or `cancelled`
- `mjb_commands` -- JSON with the step array the agent executes
- `mjb_output` -- Progressive text output (appended during execution)
- `mjb_result` -- Structured JSON populated by `JobResultProcessor` after completion
- `mjb_current_step` / `mjb_total_steps` -- Progress tracking

Create jobs with the static helper:

```php
$job = ManagementJob::createJob(
    $node_id,               // target node ID (or null for local)
    'backup_database',      // job type label
    $steps,                 // array of step dicts from JobCommandBuilder
    ['encryption' => true], // parameters (stored for reference/re-run)
    $session->get_user_id() // who triggered it
);
```

### AgentHeartbeat (`ahb_agent_heartbeats`)

Single-row table tracking agent liveness. Updated every 30 seconds by the Go agent. The dashboard checks `ahb_last_heartbeat` to show online/offline status.

## Safety Constraints

1. **Auto-backup before destructive operations** -- `copy_database` and `restore_database` automatically prepend a backup step. If the backup fails, the destructive steps never run.

2. **Per-node concurrency lock** -- The agent skips jobs if another job is already running on the same node, preventing conflicts.

3. **Stale job recovery** -- On agent startup, any orphaned `running` jobs are marked `failed` with a descriptive message.

4. **Step timeout** -- 30-minute default per step, overridable. On timeout, the SSH session is killed.

5. **Single-threaded agent** -- One job at a time. Queued jobs run sequentially.

6. **Remote credentials at runtime** -- Database credentials for backup/copy/restore are extracted from each node's `Globalvars_site.php` at execution time, never stored on the control plane.

## AJAX Endpoint

`/plugins/server_manager/ajax/job_status` -- polled by the job detail page for live output.

Parameters:
- `job_id` (int) -- job to query
- `output_offset` (int) -- character position; only new output since this offset is returned

Response:
```json
{
    "success": true,
    "status": "running",
    "new_output": "=== [Step 2/5] Check memory ===\n...",
    "output_offset": 1234,
    "current_step": 2,
    "total_steps": 5,
    "error_message": null
}
```

The UI polls every 2 seconds while a job is running and stops when status is `completed` or `failed`.

## Troubleshooting

**Agent shows Offline on dashboard**
- Check the agent is running: `sudo systemctl status joinery-agent`
- Check logs: `journalctl -u joinery-agent -f`
- Verify DB credentials in `/etc/joinery-agent/joinery-agent.env` match those in `Globalvars_site.php`

**Jobs stay in `pending` forever**
- Agent is not running or can't connect to the database
- Another job is running on the same node (per-node lock)

**SSH step fails with "connection refused"**
- Verify SSH key path on the node record matches an actual key file
- Test manually: `ssh -i /path/to/key root@host "echo ok"`
- For container nodes, verify the container name is correct

**Job fails with "Agent restarted while job was running"**
- The agent crashed or was restarted mid-job. Check `journalctl` for the crash cause.
- The partially-completed job should be inspected manually. Use **Re-run** to retry.

## File Reference

### Plugin (`plugins/server_manager/`)

| File | Purpose |
|------|---------|
| `plugin.json` | Plugin metadata |
| `uninstall.php` | Removes settings and menu entries on uninstall |
| `data/managed_node_class.php` | ManagedNode + MultiManagedNode |
| `data/management_job_class.php` | ManagementJob + MultiManagementJob |
| `data/agent_heartbeat_class.php` | AgentHeartbeat + MultiAgentHeartbeat |
| `includes/JobCommandBuilder.php` | Command generation for all job types |
| `includes/JobResultProcessor.php` | Parses completed job output into structured data |
| `ajax/job_status.php` | Live job output polling |
| `ajax/discover_nodes.php` | Creates and polls node discovery jobs |
| `migrations/migrations.php` | Unique indexes and admin menu entries |
| `views/admin/*.php` | 8 admin view pages |

### Go Agent (`/home/user1/joinery-agent/`)

| File | Purpose |
|------|---------|
| `main.go` | Entry point, signal handling, poll loop |
| `config.go` | Environment-based configuration |
| `db.go` | PostgreSQL: job claiming, output writing, heartbeat |
| `runner.go` | Step executor dispatching to ssh/scp/local |
| `ssh.go` | SSH connection pooling and command execution |
| `scp.go` | SCP file transfer |
| `server.go` | Node connection info struct |
| `Makefile` | build, test, release targets |
| `build_installer.sh` | Generates self-extracting installer |
| `install/joinery-agent.service` | systemd unit file |
| `config/joinery-agent.env.example` | Example configuration |
