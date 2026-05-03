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

The plugin creates its database tables automatically: `mgn_managed_nodes`, `mjb_management_jobs`, `ahb_agent_heartbeats`, `bkt_backup_targets`.

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

Go to `/admin/server_manager/node_add` (or click **Add Node** on the dashboard). There are two ways to add nodes:

#### Auto-detect (recommended)

The **Auto-Detect Joinery Servers** panel scans a remote host for Joinery instances automatically. Enter:

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

Click **Add Node**, then use **Test Connection** from the node's Overview tab to verify SSH access.

## Admin Pages

All pages are at `/admin/server_manager/...` and require permission level 10 (superadmin).

The UI is organized around a **dashboard + node detail** pattern. The dashboard shows the fleet overview; clicking a node opens a tabbed detail page with all operations for that node.

| URL | Purpose |
|-----|---------|
| `/admin/server_manager` | **Dashboard** -- agent status, node cards with health dots, publish upgrade, recent jobs |
| `/admin/server_manager/node_detail?mgn_id=N` | **Node Detail** -- tabbed page for a single node (see tabs below) |
| `/admin/server_manager/node_add` | **Add Node** -- auto-detect panel + manual add form |
| `/admin/server_manager/targets` | **Backup Targets** -- CRUD for cloud storage targets (B2, S3, Linode) |
| `/admin/server_manager/jobs` | **Jobs** -- global job history with filters by node, status, and type |
| `/admin/server_manager/job_detail?job_id=N` | **Job Detail** -- single job output with live polling |

### Node Detail Tabs

The node detail page (`/admin/server_manager/node_detail?mgn_id=N&tab=...`) has five tabs:

| Tab | Purpose |
|-----|---------|
| **Overview** | Status summary (health dot, disk/memory/load/postgres/version), action buttons (Check Status, Test Connection), recent jobs for this node, connection settings (collapsed by default), delete node |
| **Backups** | Target indicator, run database/project backup, fetch backup file, backup file browser with scan and delete, restore full project from a `.tar.gz` archive |
| **Database** | Copy database from another node to this one, restore from backup file |
| **Updates** | Version comparison (node vs control plane), apply update |
| **Jobs** | Job history filtered to this node, with status and type filters |

### Dashboard Features

The dashboard shows:

- **Agent Status** -- online/offline indicator with version and last heartbeat time
- **Managed Nodes** -- cards with health-based status dots (green=healthy, yellow=warning, red=problem, gray=no data), key metrics, and action buttons
- **Publish Upgrade** -- build upgrade archives from control plane source code (node-independent)
- **Recent Jobs** -- latest 20 jobs across all nodes

Health dot colors reflect actual server health, not check recency:
- **Red**: Last check failed, disk > 90%, or PostgreSQL not accepting connections
- **Yellow**: Disk > 80% or load average > 5
- **Green**: All metrics healthy
- **Gray**: Never checked or no data

## Job Types

| Job Type | Description | Destructive |
|----------|-------------|-------------|
| `check_status` | SSH-probe disk, memory, uptime, PostgreSQL, version; subsumes the old `test_connection` since its first step is the SSH handshake | No |
| `backup_database` | Run `backup_database.sh`, optionally upload to cloud | No |
| `backup_project` | Run `backup_project.sh` (DB + files + Apache config), optionally upload | No |
| `fetch_backup` | SCP a backup file from remote to control plane | No |
| `list_backups` | List backup files on local server and cloud target | No |
| `delete_backup` | Delete backup files from local, cloud, or both | **Yes** |
| `copy_database` | Dump source DB, transfer, restore on target | **Yes** |
| `restore_database` | Restore a backup file on a node | **Yes** |
| `restore_project` | Restore a full project `.tar.gz` (files + DB + Apache config) in place on an existing node. Runs `restore_project.sh --force`, which cascades `--non-interactive` into `restore_database.sh`. Pre-restore snapshots of DB and files written to `/backups/auto_pre_project_restore_*` | **Yes** |
| `apply_update` | Run `upgrade.php` on target | **Yes** |
| `publish_upgrade` | Run `publish_upgrade.php` locally on control plane (in plugin) | No |
| `discover_nodes` | Scan a remote host for Joinery instances (Docker + bare metal) | No |
| `install_node` | Provision a fresh Joinery site on a remote host (fresh or from-backup) | No (target must be clean) |
| `provision_ssl` | Run certbot on the node's host to obtain a Let's Encrypt cert | No |

Destructive operations auto-backup the target database before proceeding. The UI requires explicit confirmation checkboxes.

**Note on bare-metal nodes with user1 SSH:** When a bare-metal install completes, `install.sh` disables root SSH and the node's `mgn_ssh_user` is automatically updated to `user1`. Subsequent jobs run as `user1` with `NOPASSWD sudo`. All backup/restore commands that need root-level paths (e.g. `/backups/`) use `sudo` automatically.

### One-Click Node Install

**Dashboard → Install New Node** opens a form that provisions a fresh Joinery site on an SSH-accessible server in a single click. Two modes:

- **Fresh**: empty Joinery site with default schema. Admin picks the domain. Default admin login is `admin@example.com` / `changeme123`, with `usr_force_password_change=true` so the first login forces a new password.
- **From Backup**: fresh install + restore of a source node's DB and project files. Target inherits the source's domain — admin cuts over DNS after install. Use source admin credentials to log in.

The job composes existing primitives: the installer artifacts from `maintenance_scripts/install_tools/` are packaged locally, SCP'd, extracted on the target, and `install.sh -y -q site SITENAME - DOMAIN` runs non-interactively. Docker installs add a follow-up step that invokes `manage_domain.sh set SITENAME DOMAIN --no-ssl` on the target to auto-install Apache + mod_proxy (if missing) and wire up an HTTP reverse proxy on port 80 — so the site is reachable at `http://DOMAIN/` as soon as DNS points here. SSL stays a separate admin step (`certbot --apache -d DOMAIN` on the target). For From-Backup, source backups are captured (or an existing cached backup is used), fetched to the control plane, and pushed to the target after install.

The `mgn_install_state` column tracks the lifecycle: `installing` → `NULL` (success) or `install_failed` (failure). On failure, the node detail page surfaces a **Retry Install** button; the target must be cleaned manually (e.g. `rm -rf /var/www/html/SITENAME`) before retry because `install.sh` refuses to overwrite an existing site. Postgres passwords are auto-generated and stored in the target's `Globalvars_site.php` — Server Manager does not capture or display them.

**Docker notes:**
- The reverse proxy step (`manage_domain.sh`) is skipped when the domain is a bare IP address — a routable hostname is required for Apache `ServerName`-based virtual hosting. With an IP domain, the site is accessible directly on its mapped port.
- `backup_project.sh` requires `rsync`. The bare-metal and Docker install scripts install rsync as part of the essential packages (`install.sh` line ~948). Sites installed before this was added can install it manually with `apt install rsync`.
- After a Docker install, `mgn_container_name` is automatically recorded in the control plane DB so future jobs correctly use `docker exec` to reach the site.

## SSL Management

### SSL State

Each node tracks its TLS certificate state in `mgn_ssl_state`:

| Value | Meaning |
|-------|---------|
| `null` | Unknown or not configured |
| `pending` | Waiting for DNS propagation; certbot has not run yet |
| `active` | A valid Let's Encrypt cert is installed |
| `failed` | Provisioning failed after repeated retries |

### Automatic Detection

`check_status` jobs include an SSH step that checks for a Let's Encrypt cert under `/etc/letsencrypt/live/{domain}/`. `JobResultProcessor` updates `mgn_ssl_state` and stores `ssl_domain`, `ssl_expiry_raw`, and `ssl_expiry_ts` in `mgn_last_status_data`. State transitions:

- `CERT_FOUND` → sets state to `active` (from any prior state)
- `CERT_MISSING` → clears state to `null` only if currently `null` or `active`; never overwrites `pending` or `failed`

### Manual Provisioning

The **Overview** tab shows an **SSL Setup card** when `mgn_ssl_state` is not `active` and the node has a domain in its site URL. The card:

1. Resolves the domain via DNS and shows whether it points to the node's host IP
2. Enables the **Provision SSL** button when DNS is ready (or when the host IP is not configured)
3. On submit: creates a `provision_ssl` job, sets `mgn_ssl_state = 'pending'`, redirects to job detail

The `provision_ssl` job runs `certbot --apache -d DOMAIN` on the node's host (for Docker nodes, certbot runs on the reverse-proxy host, not inside the container). On success, `mgn_ssl_state` is set to `active` by `JobResultProcessor`.

### Automated Provisioning (installs only)

For nodes installed via **Install New Node**, `ProvisionPendingSsl` (scheduled hourly) watches for nodes with `mgn_ssl_state = 'pending'`, checks DNS, and kicks off `provision_ssl` jobs automatically. After ~16 hours of DNS check failures it flips state to `failed`. Manual provisioning via the Setup card is the fallback.

## Backup Targets

Backup targets define where backup files are uploaded after creation. Each node can optionally have a backup target assigned. If no target is set, backups remain local only on the remote server.

### Supported Providers

| Provider | Credentials (UI fields) |
|----------|-------------------------|
| **Backblaze B2** | Application Key ID + Application Key (region/endpoint auto-detected via `b2_authorize_account` at save time) |
| **Amazon S3** | Access Key + Secret Key + Region |
| **Linode Object Storage** | Access Key + Secret Key + Region + Endpoint URL |

All providers authenticate against their S3-compatible endpoint via AWS SigV4 signing performed by `S3Signer.php`. There is **no per-provider CLI dependency** — uploads, downloads, deletes, and listings all run as direct HTTPS calls from either the control plane (web tier) or the node (via a heredoc'd `node_uploader.php` script). New S3-compatible providers can be added by configuration alone, no script changes.

Nodes with no backup target leave backups local-only on the remote server.

### Configuration

1. Go to `/admin/server_manager/targets` and click **Add Target**
2. Select a provider, enter bucket name, path prefix, and credentials
3. Go to a node's Overview tab, expand **Edit Connection Settings**, and select the target from the **Backup Target** dropdown
4. Save — backups for this node will now auto-upload after creation

### Upload Path Structure

All providers use: `{prefix}/{node_slug}/{filename}`

Example: `joinery-backups/empoweredhealthtn/empoweredhealthtn-04_11_2026.sql.gz.enc`

### Credential Storage

Credentials are stored in the `bkt_credentials` JSON column on the `bkt_backup_targets` table using a unified shape for every provider:

```json
{"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
```

For node-side operations (upload, delete, download), the credentials are embedded into a self-contained PHP script that is piped to the node via a heredoc'd `php --` invocation — never written to a file on the node and never visible in process listings as positional arguments. The `S3Signer.php` and `node_uploader.php` source is composed at job-build time by `JobCommandBuilder::build_node_uploader_script()`.

### Backup Browser

The **Backups** tab on each node includes a file browser that lists backup files from both local storage and the cloud target. Features:

- **Scan for Backups** — creates a `list_backups` job to scan local `/backups/` on the node
- **Unified file table** — shows filename, size, date, and location (Local / Cloud / Both)
- **Delete** — single Delete button per row that removes the file from every location it exists in (local, cloud, or both); the confirmation dialog names the file and locations explicitly
- **Restore Full Project** — for `.tar.gz` archives, see the `restore_project` row in the Job Types table

Cloud listings are fetched live via `TargetLister` on every page render (one SigV4 HTTP GET, ~200–500ms). The local listing comes from the most recent completed `list_backups` job; both the Backups and Database tabs auto-trigger a refresh on page load when that scan is more than 60 seconds stale, so the listing is effectively always current. Both the merge logic and the staleness window are owned by `BackupListHelper::get_for_node()`.

## Backup Encryption

### Default Behavior

Encryption is **enabled by default** on both Database Backup and Full Project Backup forms. The existing `backup_database.sh` script handles encryption using AES-256-CBC with a key from `~/.joinery_backup_key` on the remote server.

### B2 Enforcement

When a node's backup target is Backblaze B2, encryption is **mandatory**. The UI replaces the checkbox with a message, and the server-side enforces it regardless of form input.

### Auto-Generated Keys

If an encryption key doesn't exist on the remote server, the backup job auto-generates one:

1. The first step checks for `~/.joinery_backup_key`
2. If missing, generates a random 32-byte base64 key with `openssl rand -base64 32`
3. Saves it with 600 permissions
4. Logs `ENCRYPTION_KEY_GENERATED` in the job output (the key value itself is never in the output)

### Key Security

The encryption key **never touches the control plane**. It exists only on the remote server. This ensures that compromising the B2 bucket or the control plane database does not expose the decryption key.

To retrieve the key (for decrypting backups on another machine), SSH to the remote server:

```bash
cat ~/.joinery_backup_key
```

## How It Works: Smart Plugin, Dumb Agent

All job-type intelligence lives in `JobCommandBuilder.php`. The Go agent is a generic executor that understands four primitives: `ssh`, `scp`, `local`, and `api`.

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
| `type` | Yes | `ssh`, `scp`, `local`, or `api` |
| `label` | Yes | Human-readable description (shown in UI and output) |
| `cmd` | ssh/local | Shell command to execute |
| `node_id` | No | Override target node (defaults to job's node). Used for multi-node operations like `copy_database` |
| `on_host` | No | If `true`, run on the SSH host directly, not inside the Docker container. Used for `docker stats`, etc. |
| `direction` | scp | `upload` (local to remote) or `download` (remote to local) |
| `remote_path` | scp | File path on the remote host |
| `local_path` | scp/api | File path on the control plane (for `api`, set to stream the response body to a file instead of appending to job output — used by `backups/fetch`) |
| `method` | api | HTTP method: `GET`, `POST`, `PUT`, `DELETE` (in practice always `GET` — the management API is read-only) |
| `endpoint` | api | Path relative to `/api/v1/management/` — e.g. `stats`, `backups/list`, `backups/fetch` |
| `expect_status` | api | HTTP status code that counts as success (default 200) |
| `query` | api | Object of query-string params (e.g. `{"path": "/backups/foo.sql.gz"}`) |
| `body` | api | Request body object (serialized as JSON; ignored for GET/DELETE) |
| `continue_on_error` | No | If `true`, don't abort the job when this step fails. Used for cleanup steps. |
| `timeout` | No | Max seconds for this step (default: 1800 = 30 minutes) |

## Management API (Read-Only)

Every Joinery instance exposes a namespaced read-only HTTP surface at `/api/v1/management/*`. The control plane prefers this over SSH for observability operations (`check_status`, `list_backups`, `fetch_backup`) because it's faster, parallelizable, and auditable.

**Endpoints** (all under `/api/v1/management/`, all `GET`, all JSON except `backups/fetch` which streams binary):

| Endpoint | Replaces SSH step(s) |
|----------|----------------------|
| `health` | (new — liveness probe) |
| `stats` | all steps of `check_status` |
| `version` | `Check Joinery version` |
| `databases` | `List databases` |
| `errors/recent` | `Recent errors` |
| `backups/list` | `list_backups` |
| `backups/fetch?path=...` | `fetch_backup` (SCP) |

Discovery: `GET /api/v1/management` returns every endpoint with its description.

**Authentication** uses the existing API key system (`stg_api_keys` — same bcrypt flow as public CRUD). The gate is **user-level**: the key's owning user must have `usr_permission >= 10` (superadmin). `apk_permission` is NOT the gate here — it's the CRUD-axis permission and is orthogonal. A superadmin's key with `apk_permission=1` can call management endpoints (read-only across both axes); a permission-5 admin's key cannot, regardless of `apk_permission`.

**Adding a management key for a node:** on the target node, Admin → API Keys → New Key, owner = a superadmin user, `apk_permission = 1`, IP-restrict to the control plane's egress IP. Paste the public/secret pair into the node's Overview tab on the control plane's Server Manager ("API Credential" panel).

> **IP restriction on docker-prod nodes:** for sites fronted directly by host Apache (no Cloudflare), the container now reads the real client IP via `mod_remoteip` + the host's `X-Forwarded-For: %{REMOTE_ADDR}s` header, so IP restriction works end-to-end. For Cloudflare-fronted sites, the container sees Cloudflare's edge IP — IP restriction is not yet meaningful in that case (a future spec will trust Cloudflare's ranges and read `CF-Connecting-IP`).

**Build-time routing:** `JobCommandBuilder::build_<op>()` dispatches to `build_<op>_api()` or `build_<op>_ssh()` based on `has_api($node, $op)`, which checks: (1) credentials stored on the node row, (2) a matching `build_<op>_api` exists, (3) a fresh `/health` probe succeeds. No runtime fallback — a job is decided at build-time and runs that path or fails. The existing SSH implementation stays in place; clearing the stored credentials or breaking `/health` routes the next job back to SSH automatically.

**Adding a new management endpoint:** drop a file under `includes/management_api/<name>_handler.php` with `<name>_handler($request)` + `<name>_handler_api()` meta function. Nested paths mirror directories (`backups/list_handler.php` → `GET /api/v1/management/backups/list`). Parallels the action-endpoint convention in `logic/*_logic.php`.

**TLS verification** is strict by default. The `mgn_tls_insecure` boolean on `mgn_managed_nodes` opts a single node out for dev/local instances without a cert from a trusted CA. Audit: `SELECT mgn_slug FROM mgn_managed_nodes WHERE mgn_tls_insecure = true`.

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
- `mgn_bkt_backup_target_id` -- FK to backup target (null = local only)

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

### BackupTarget (`bkt_backup_targets`)

Configured storage target for backups. Key fields:

- `bkt_name` -- Display name (e.g., "Production B2")
- `bkt_provider` -- `b2`, `s3`, or `linode`
- `bkt_bucket` -- Bucket name (required)
- `bkt_path_prefix` -- Path prefix within the bucket (default: `joinery-backups`)
- `bkt_credentials` -- JSON with the unified shape `{access_key, secret_key, region, endpoint}` for every provider; B2's region/endpoint are auto-detected at save time
- `bkt_delete_local` -- Whether to delete local backup after successful upload
- `bkt_enabled` -- Whether this target is active

### AgentHeartbeat (`ahb_agent_heartbeats`)

Single-row table tracking agent liveness. Updated every 30 seconds by the Go agent. The dashboard checks `ahb_last_heartbeat` to show online/offline status.

## Safety Constraints

1. **Auto-backup before destructive operations** -- `copy_database`, `restore_database`, and `restore_project` automatically prepend backup steps. `restore_project` snapshots both the current database (`auto_pre_project_restore_*.sql.gz`) and the current project tree (`auto_pre_project_restore_*.tar.gz`) to `/backups/` before overwriting; either can be skipped if the corresponding component is unchecked in the form. If any pre-backup step fails, the destructive steps never run.

2. **Per-node concurrency lock** -- The agent skips jobs if another job is already running on the same node, preventing conflicts.

3. **Stale job recovery** -- On agent startup, any orphaned `running` jobs are marked `failed` with a descriptive message.

4. **Step timeout** -- 30-minute default per step, overridable. On timeout, the SSH session is killed.

5. **Single-threaded agent** -- One job at a time. Queued jobs run sequentially.

6. **Remote credentials at runtime** -- Database credentials for backup/copy/restore are extracted from each node's `Globalvars_site.php` at execution time, never stored on the control plane.

## AJAX Endpoints

### `/ajax/job_status`

Polled by the job detail page for live output.

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

### `/ajax/backup_actions`

Used by the backup browser on the Backups tab.

| Action | Method | Parameters | Returns |
|--------|--------|------------|---------|
| `refresh_list` | GET | `node_id` | `{success, job_id}` -- creates a `list_backups` job |
| `delete_file` | GET | `node_id`, `target` (local/cloud/both), `local_path`, `cloud_path` | `{success, job_id}` -- creates a `delete_backup` job |
| `list_status` | GET | `node_id`, `job_id` (optional) | `{success, status, backup_list, last_scan}` -- returns cached file listing |

### `/ajax/discover_nodes`

Used by the auto-detect panel on the Add Node page. Creates and polls `discover_nodes` jobs.

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
| `data/backup_target_class.php` | BackupTarget + MultiBackupTarget |
| `includes/JobCommandBuilder.php` | Command generation for all job types |
| `includes/JobResultProcessor.php` | Parses completed job output into structured data |
| `includes/S3Signer.php` | AWS SigV4 signer for S3-compatible storage (get/put/delete) |
| `includes/TargetUploader.php` | Web-tier upload + delete helpers using S3Signer |
| `includes/TargetLister.php` | Web-tier paginated bucket listing using S3Signer |
| `includes/TargetTester.php` | Connection test on Save for Backup Targets |
| `includes/node_uploader.php` | Self-contained upload/delete/download dispatcher run on the node via heredoc; composed at job-build time with S3Signer + injected credentials |
| `includes/BackupListHelper.php` | Merges latest local list_backups job output with live cloud listing into a unified file table |
| `ajax/job_status.php` | Live job output polling |
| `ajax/discover_nodes.php` | Creates and polls node discovery jobs |
| `ajax/backup_actions.php` | Backup browser actions (scan, delete) |
| `migrations/migrations.php` | Indexes, admin menu entries, menu consolidation |
| `views/admin/index.php` | Dashboard -- fleet overview, publish upgrade |
| `views/admin/node_detail.php` | Node detail -- tabbed page (overview/backups/database/updates/jobs) |
| `views/admin/node_add.php` | Add node -- auto-detect + manual form |
| `views/admin/targets.php` | Backup target CRUD |
| `views/admin/jobs.php` | Global job history |
| `views/admin/job_detail.php` | Single job output with live polling |
| `views/admin/nodes_edit.php` | Redirect stub (-> node_detail or node_add) |
| `views/admin/nodes.php` | Redirect stub (-> dashboard) |
| `views/admin/backups.php` | Redirect stub (-> dashboard or node_detail) |
| `views/admin/database.php` | Redirect stub (-> dashboard or node_detail) |
| `views/admin/updates.php` | Redirect stub (-> dashboard or node_detail) |

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
