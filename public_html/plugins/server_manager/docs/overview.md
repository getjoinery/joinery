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

#### Release channel (how the agent normally arrives and stays current)

The agent ships inside the platform release. Publishing an upgrade bundles a signed agent artifact into `plugins/server_manager/agent_dist/`:

- `manifest.json` — agent version plus, per architecture, the artifact filename, its sha256, and an Ed25519 signature over the raw binary
- `joinery-agent-linux-amd64.gz` / `joinery-agent-linux-arm64.gz` — the binaries
- `joinery-agent.service` — the systemd unit

On the publishing control plane, `publish_upgrade.php` cross-compiles both architectures from the checkout named by the `server_manager_agent_source_path` setting (default `/home/user1/joinery-agent`) whenever the source version differs from the bundled one, and signs them with the key at `{site root}/config/agent_signing_key` (generated on first publish; the `.pub` sibling holds the base64 public key that gets baked into the built agent).

Bundling is the first thing a publish does, before the VERSION file, the archives or the release row, because its outcome decides whether the release happens at all:

- **No agent source on this box** — the existing artifact carries forward unchanged and the publish proceeds. Publishing never depends on a Go toolchain being present.
- **Source version matches the bundle** — `agent_dist` is left byte-identical, which keeps the `server_manager` plugin tree hash stable between publishes.
- **Source is newer and the rebuild succeeds** — the fresh artifact is bundled, and because this happens before plugin archives are built it is captured in the `server_manager` archive and its tree hash.
- **Source is newer and the rebuild fails** — the publish is refused. The build error is printed, and the VERSION file, archives and release row are all left untouched. Shipping here would mean releasing an agent the publisher already knows is out of date, and the resulting fleet has no way to tell.

The last line of a publish names the agent version the release carries. `plugins/server_manager/tests/agent_bundle_drift_test.php` asserts the same invariant on its own, so a bundle that falls behind its source is caught by the safe test tier rather than by the next release.

**First install** is handled by the plugin's `host_installer` (`provisioning/install_agent.sh`), which runs at every root moment — site install, code upgrade, container start, and the node-detail **Run Plugin Installers** action. It installs the bundled binary, writes the env file with the right `JOINERY_CONFIG`, and sets up systemd or cron supervision automatically.

**Every later version change is handled by the agent itself.** Between jobs, the agent compares its own version with the bundled manifest. When they differ, it decompresses the artifact, checks the sha256, verifies the Ed25519 signature against the public key embedded in its binary, keeps the current binary as `.bak`, renames the new one into place, and exits cleanly for its supervisor to restart. The signature check is the security boundary: the site tree is writable by the web user while the agent runs as root, so the agent never installs anything the publisher did not sign. An artifact that fails verification is refused, logged under a `=== Self-update ===` header, and not retried until the manifest changes.

If the new binary fails to initialise (config, DB, or schema), it restores the `.bak` over itself and records the bad version in a `.rejected` marker — the supervisor restarts the previous working agent, and that version is never reinstalled; the next release supersedes the rejection. On the first fully healthy start after an update, the `.bak` and any stale marker are removed.

The dashboard's Agent Status bar surfaces all of this from the heartbeat row (`ahb_bundled_version`, `ahb_update_state`): a pending update, a refused (verification-failed) artifact, a rolled-back version, or an agent built without an update key.

#### Manual install (bootstrap fallback)

For a control plane that has no bundled artifact yet, build and install by hand:

```bash
cd /home/user1/joinery-agent
PUBKEY=$(cat /var/www/html/joinerytest/config/agent_signing_key.pub) make release
sudo bash joinery-agent-installer.sh --verbose [--config /path/to/Globalvars_site.php]
```

`make release` compiles the binary (passing `PUBKEY` bakes in the update-verification key so the manual build can still self-update later) and packages it into `joinery-agent-installer.sh`, a self-extracting script that handles both fresh installs and upgrades with automatic rollback if the new version fails to start.

The installer detects the host's supervision capability:

- **systemd hosts**: installs `/etc/systemd/system/joinery-agent.service`; start with `systemctl start joinery-agent`.
- **No systemd** (Docker containers, minimal hosts): installs `/usr/local/bin/joinery-agent-supervise` plus `/etc/cron.d/joinery-agent` (`@reboot` + a once-a-minute keepalive) and starts the agent immediately. Logs go to `/var/log/joinery-agent.log`.

Both modes create:
- `/usr/local/bin/joinery-agent` — the binary
- `/etc/joinery-agent/joinery-agent.env` — configuration (from example, first install only)

`--config PATH` stamps `JOINERY_CONFIG` into the env file, pointing the agent at the right site without editing anything.

**Every control plane needs a live agent.** All jobs (install_node, provision_ssl, backups, upgrades) sit `pending` until an agent polling that site's own database claims them. The **Server Manager → Provisioning** page shows an agent heartbeat badge as requirement #1.

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

If anything is wrong, the agent logs to the systemd journal (systemd hosts) or `/var/log/joinery-agent.log` (cron-supervised hosts):

```bash
journalctl -u joinery-agent -f      # systemd
tail -f /var/log/joinery-agent.log  # cron supervision
```

Common startup errors are self-explanatory — missing `DB_NAME`, wrong password, or plugin tables not installed. Each error message tells you exactly what to fix.

#### Upgrade

Agents upgrade themselves from the bundled artifact after each platform release lands (see **Release channel** above). The manual-install path also accepts upgrades: re-running the generated installer stops the service, swaps the binary, restarts, and rolls back automatically if the new version fails to start.

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
| **Overview** | Status summary (health dot, disk/memory/load/postgres/version), action buttons (Check Status, Test Connection), recent jobs for this node, connection settings (collapsed by default), delete node. The Actions dropdown also offers **Run Plugin Installers** — queues a `run_plugin_installers` job that executes every active plugin's declared `host_installer` on the node as root (idempotent); this is how a bare-metal node picks up system-service configuration (e.g. the mail stack) after a plugin is activated, since it has no container-start moment |
| **Backups** | Target indicator, run database/project backup, backup file browser with scan and delete, restore full project from a `.tar.gz` archive |
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
| `list_backups` | List backup files on local server and cloud target | No |
| `delete_backup` | Delete backup files from local, cloud, or both | **Yes** |
| `copy_database` | Dump source DB, transfer, restore on target | **Yes** |
| `restore_database` | Restore a backup file on a node | **Yes** |
| `restore_project` | Restore a full project `.tar.gz` (files + DB + Apache config) in place on an existing node. Runs `restore_project.sh --force`, which cascades `--non-interactive` into `restore_database.sh`. Pre-restore snapshots of DB and files written to `/backups/auto_pre_project_restore_*`. Every file in the archive must exist under the project directory afterwards or the restore fails and names what is missing | **Yes** |
| `apply_update` | Run `upgrade.php` on target | **Yes** |
| `publish_upgrade` | Run `publish_upgrade.php` locally on control plane (in plugin) | No |
| `discover_nodes` | Scan a remote host for Joinery instances (Docker + bare metal) | No |
| `install_node` | Provision a fresh Joinery site on a remote host (fresh or from-backup) | No (target must be clean) |
| `provision_ssl` | Run certbot on the node's host to obtain a Let's Encrypt cert | No |
| `decommission_node` | Ship and run `remove_account.sh` on the host to permanently delete the site, verify it is gone, then soft-delete the node record | **Yes** |

Destructive operations auto-backup the target database before proceeding. The UI requires explicit confirmation checkboxes.

**Note on bare-metal nodes with user1 SSH:** When a bare-metal install completes, `install.sh` disables root SSH and the node's `mgn_ssh_user` is automatically updated to `user1`. Subsequent jobs run as `user1` with `NOPASSWD sudo`. All backup/restore commands that need root-level paths (e.g. `/backups/`) use `sudo` automatically.

### One-Click Node Install

**Dashboard → Install New Node** opens a form that provisions a Joinery site in a single click. The **Target Host** dropdown offers three kinds of target:

- **A known host** (or *Other server* with manual SSH details): the form creates the ManagedNode and dispatches the `install_node` job immediately.
- **Create a new cloud instance**: no server exists yet. The form records an admin-origin `CustomerCloudProvision` (connected cloud account, region, instance type, plus all install parameters) and the **Provision Customer Cloud** task births the instance, creates the node, and dispatches the install — see [Customer-Cloud Fulfillment](#customer-cloud-fulfillment). The instance is created in, and billed to, the selected connected account; Linode grants expire after two hours, so connect (or re-connect) shortly before submitting. Cloud targets always take a fresh source backup in From-Backup mode. In-flight provisions appear in a banner at the top of the dashboard.

  The cloud target also offers **Bare instance** as the install type: the instance is born, the SSH key injected, and the managed node created with `mgn_skip_joinery_checks` set — but no site is installed (no web root, site URL, or SSL flow). Completion is a passing `check_status` job. This is how infrastructure nodes that host no Joinery site — a mail relay shard, for example — enter management; the role's own provisioning (e.g. the mailbox plugin's provision-relay job) builds on the bare node afterward. Bare is admin-origin only; orders always install a site.

Two install types:

- **Fresh**: empty Joinery site with default schema. Admin picks the domain. Default admin login is `admin@example.com` / `changeme123`, with `usr_force_password_change=true` so the first login forces a new password.
- **From Backup**: fresh install + restore of a source node's DB and project files. Target inherits the source's domain — admin cuts over DNS after install. Use source admin credentials to log in.

The job composes existing primitives: the installer artifacts from `maintenance_scripts/install_tools/` are packaged locally, SCP'd, extracted on the target, and `install.sh -y -q site SITENAME - DOMAIN` runs non-interactively. Docker installs add a follow-up step that invokes `manage_domain.sh set SITENAME DOMAIN --no-ssl` on the target to auto-install Apache + mod_proxy (if missing) and wire up an HTTP reverse proxy on port 80 — so the site is reachable at `http://DOMAIN/` as soon as DNS points here. SSL stays a separate admin step (`certbot --apache -d DOMAIN` on the target). For From-Backup, source backups are captured (or an existing cached backup is used), fetched to the control plane, and pushed to the target after install.

From-Backup restores files by extracting the source archive with **both** of its
leading path components stripped, taking only the `project_files/` subtree —
`backup_project.sh` writes archives as `{backup_name}/project_files/{public_html,
uploads,config,...}` with the archive's own metadata (`apache_config/`,
`backup_info.txt`, the `.sql` dump) as siblings. The target keeps its own
`Globalvars_site.php`. A verification step then requires every regular file the
archive carries to exist at the site root and fails the job otherwise, because a
clone whose files did not land still serves pages: the fresh install ran first
and the database restore succeeded, so the only symptom is uploaded files
missing from where the restored database says they are.

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

The **Overview** tab shows an **SSL Setup card** when `mgn_ssl_state` is not `active`, the node has a domain in its site URL, and `mgn_cert_expiry_ts` is empty. The last condition excludes directly-exposed, self-renewed nodes (see [Certificate Expiry Monitoring](#certificate-expiry-monitoring)) — their cert lifecycle is owned by an external renewer (e.g. Caddy), and the card's certbot-based provisioning does not apply to them. The card:

1. Resolves the domain via DNS and shows whether it points to the node's host IP
2. Enables the **Provision SSL** button when DNS is ready (or when the host IP is not configured)
3. On submit: creates a `provision_ssl` job, sets `mgn_ssl_state = 'pending'`, redirects to job detail

The `provision_ssl` job runs `certbot --apache -d DOMAIN` on the node's host (for Docker nodes, certbot runs on the reverse-proxy host, not inside the container). On success, `mgn_ssl_state` is set to `active` by `JobResultProcessor`.

**Cloudflare-proxied domains** skip certbot (Cloudflare terminates TLS at its edge) but are gated on a routing probe: the job writes a one-time token into the site's webroot, and the control plane fetches it through the domain. Only a match — proof that traffic for the domain actually lands on this node — patches the proxy's `X-Forwarded-Proto` and marks SSL `active` (`JobResultProcessor` additionally requires the `CF_ROUTING_VERIFIED` marker). A miss fails the job and the domain stays pending until the customer's DNS actually routes here.

### Automated Provisioning (installs only)

For nodes installed via **Install New Node**, `ProvisionPendingSsl` (scheduled hourly) watches for nodes with `mgn_ssl_state = 'pending'`, checks DNS, and kicks off `provision_ssl` jobs automatically. After ~16 hours of failed attempts it flips state to `failed` — except a Cloudflare domain still waiting on its DNS cutover (`CF_ROUTING_UNVERIFIED`), which keeps quietly retrying. Manual provisioning via the Setup card is the fallback.

## Hosting Provisioning

Paid hosting orders on getjoinery become installed, SSL'd Joinery sites with
no human touch. The **Poll Hosting Orders** scheduled task polls the
getjoinery API each cron tick for paid orders carrying an answer to the
configured domain Question, and fulfills each one in the mode the product
declares in `pro_fulfillment_provider`:

- **Shared host** (the default, any other value): the pipeline picks the
  least-loaded provisioning-enabled `ManagedHost`, assigns the next Docker
  port, and dispatches an `install_node` job. The buyer's site is a container
  on infrastructure the operator owns.
- **`customer_cloud`**: the buyer's site runs on a server in **their own
  cloud account**, billed to them by the provider — see below. A product opts
  in by picking **Customer cloud server** in the product-edit Purchase grants
  picker (`CustomerCloudFulfillment`, registered with the store's
  FulfillmentRegistry from serve.php); that stamps the provider value and
  contributes the domain question as a checkout requirement automatically.

Both modes end the same way: `install_node` completes, the welcome email goes
out with DNS instructions, and `ProvisionPendingSsl` turns HTTPS on once DNS
resolves.

### Activation — the Provisioning page

**Server Manager → Provisioning** (`/admin/server_manager/provisioning_setup`)
activates the pipeline: every requirement shows a live status badge, and each
automatable step is a one-click, idempotent action backed by
`includes/ProvisioningSetup.php` — mint the store API service user
(`provisioning@<host>`, permission 5, password recovery disabled) and machine
key and write the API settings (with a loopback probe badge and key
rotation), create the domain Question, save the email settings, activate the
three scheduled tasks, and save the customer-cloud settings (SSH key path
with key/.pub existence badges, referral URL, instance defaults). The page
also shows what stays manual: attaching the question to hosting products,
opting a shared host in, and registering the Linode OAuth app. When the store
is a remote site rather than the control plane itself, the service key is
minted on the store site and its values entered in the API settings fields.

The customer-cloud provisioning keypair (the public half is installed on
created instances; the private half is the control plane's only access to
them) is generated automatically at plugin activation
(`activate.php` → `ProvisioningSetup::ensureSshKey()`), defaulting to
`{site root}/config/provisioning_key`. The page's **Generate provisioning
key** button runs the same idempotent action for control planes activated
before the key existed; an existing key or custom path is never overwritten.

### Customer-Cloud Fulfillment

The buyer connects their Linode account once at
`/profile/server_manager/connect_cloud` (the **Connect page** — also the
re-connect page if a grant is later revoked). The grant flows through the
[platform OAuth2 core](/docs/oauth2.md) (provider `linode`, consumer purpose
`customer_cloud`, scope `linodes:read_write` only — no account or billing
access). Tokens are SecretBox-encrypted on the buyer's
`CustomerCloudAccount` row.

Each provision is a `CustomerCloudProvision` row that the
**Provision Customer Cloud** scheduled task advances:

`pending_connect` → (grant arrives) → `ready` → instance created on the
connected account → `booting` → running + IP → ManagedNode + `install_node`
job → `installing` → `done` (or `failed`, which alerts the ops address).

Provisions have two origins (`cvp_origin`):

- **order** — created by a customer-cloud purchase. Starts at
  `pending_connect`, installs fresh + Docker, and sends the buyer welcome
  email on completion (the order-item linkage drives it).
- **admin** — created by the Install New Node form's cloud-instance target.
  Starts at `ready` (the admin picked an already-connected account), carries
  its install parameters on the row (`cvp_docker_mode`, `cvp_install_mode`,
  `cvp_source_node_id`, `cvp_backup_source`, `cvp_port`, `cvp_sitename`),
  and sends no welcome email. The row belongs to the grant owner
  (`cvp_usr_user_id`), so a stale grant is re-connectable by the person who
  can actually re-consent.

If the buyer hasn't connected yet, they get an email pointing at the Connect
page; the page's create-account link uses `server_manager_linode_referral_url`
so new Linode signups carry the referral credit. A token-refresh failure or a
provider 401 parks the provision back at `pending_connect` and flags the
account link — a fresh grant resumes it automatically.

**Customer-owned node semantics:** the resulting node is a normal
`ManagedNode` (installs, upgrades, uptime checks, SSL all apply), with
`mgn_ssh_key_path` set from `server_manager_customer_cloud_ssh_key_path`
(whose `.pub` sibling is installed on the instance at create time) and no
`mgn_mgh_host_id` — it belongs to no managed host. The server is the
customer's property: cancelling their subscription stops management, never
touches the instance.

Settings: `server_manager_customer_cloud_ssh_key_path` (required),
`server_manager_customer_cloud_region` / `_type` / `_image` (instance
defaults), `server_manager_linode_referral_url`. Provider credentials are the
core `oauth_linode_*` settings (Admin → System → OAuth Providers).

The compute API surface is `CloudComputeProvider`
(`includes/cloud_compute/`) with `LinodeComputeDriver` implementing it; a new
provider is a new driver plus its OAuth provider class.

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

### Stored Backups (target-side)

The **Backup Targets** edit page has a **Stored Backups** panel that lists the target's objects directly from the bucket and groups them by site. It runs entirely on the control plane via `TargetBackups` (which lists through `S3Signer::list`, a continuation-token-paged ListObjectsV2), so it needs no live node — the authoritative view of what is actually stored offsite. Each group is tagged against the node table:

- **live** — a current node owns the slug; a link jumps to that node's Backups tab for granular local+cloud management
- **decommissioned** — a soft-deleted node owned the slug; the site is gone but its offsite backups remain here, reachable and deletable
- **orphaned** — no node, present or deleted, matches the slug

Delete acts through `S3Signer` from the control plane: a single object (guarded so the key must sit under the target's own prefix), or a whole site's prefix (type-to-confirm the slug). This is the deliberate path for erasing a retired site's offsite backups — deleting a node never touches them.

## Retiring a node

Two distinct actions on the node detail Overview tab, both permission-10 and CSRF-guarded:

- **Remove from Dashboard** — soft-deletes the node record only. The site keeps running on its host; Server Manager simply stops tracking it. For a box handed back to its owner or managed elsewhere.
- **Permanently Delete Site** — creates a `decommission_node` job that ships `remove_account.sh` to the host, runs it (`-y`), and re-probes to confirm the container, its `{site}_*` volumes, and the reverse-proxy vhost are all gone (`DECOMMISSION_VERIFIED`). Only on that verification does the result processor soft-delete the node record; a failed or unverified teardown leaves the node intact and enabled to retry. Type-to-confirm the site name; the name is derived from the node's own fields, never operator input. Relays are refused (they tear down through the relay flow).

The record is soft-deleted, not hard-deleted, on purpose: the container port stays reserved on shared hosts, the job history stays joinable, and the backup-key **escrow rows are retained** — so a decommissioned site's offsite backups stay recoverable. Those backups are not purged by decommission; delete them deliberately from the Stored Backups panel above.

Removed sites are hidden from the dashboard by default. The **Show all sites (including removed)** link at the bottom of the Hosts & Sites panel re-renders with them included, each carrying a **Removed** badge and linking into its still-reachable node detail page (`?show_all=1`).

Opening a removed node's detail page offers two follow-up actions in its Danger Zone:

- **Permanently Delete Site** — the same `decommission_node` host teardown, for a node that was only removed from the dashboard while its site kept running (e.g. an orphaned container). For a removed node it is offered only when this control plane once saw a live site there — a recorded status check, Joinery version, or uptime result. With no such evidence (for example an install that failed and never stood a site up) the action is hidden behind a short note and only **Permanently Delete Entry** is offered, since there is nothing on the host to tear down. (The page cannot probe the host directly — the web user holds no host SSH key — so this uses evidence already on the record; the `decommission_node` job itself is idempotent and reports `REMOVE_ACCOUNT_NOTHING` if it reaches a host with nothing to remove.)
- **Permanently Delete Entry** — hard-deletes the Server Manager record itself (`purge_node`). Offered only for an already-removed node — purging a still-tracked node is refused, since that is how a live site becomes an untracked orphan. It is also refused while the node's slug still has offsite backups on any enabled target (or while a target cannot be listed to confirm): deleting the record would orphan those backups from the node they belong to, so they must be cleared from the target's Stored Backups panel first. Once allowed, the host is not touched; the escrow rows and job history survive the purge (cascade rules null the references).

## Backup Encryption and Key Custody

### Default Behavior

Encryption is **enabled by default** on both Database Backup and Full Project Backup forms. `backup_database.sh` / `backup_project.sh` encrypt with AES-256-CBC (PBKDF2, random salt) using the key at `~/.joinery_backup_key` on the node. When a node's backup target is Backblaze B2 encryption is **mandatory**: the UI replaces the checkbox with a message and the server enforces it regardless of form input.

### Custody model: sealed-box escrow

The encryption key lives on the node, and a copy of it is **sealed to a recovery public key** the operator holds. The control plane can seal, never open — so a stolen bucket or a dumped control-plane database yields only unopenable blobs, while a node that burns down is still recoverable.

- The recovery keypair is generated with `maintenance_scripts/sysadmin_tools/escrow_keypair.php` (standalone PHP + sodium; no platform bootstrap, so it runs during disaster recovery when the platform is gone). The **private** key belongs in the operator's password manager and never touches a server.
- The **public** key is stored in the `server_manager_escrow_public_key` setting.
- Each sealed copy is a row in `bke_backup_key_escrow` (`bke_kind` = `backup` | `agent_signing`, `bke_source` = `generated` | `migrated` | `rotated`) and is replicated to every enabled cloud target as `escrow/{node_slug}/{fingerprint}.sealed`.
- Rows are append-only: rotating a node's key adds a row, so archives written under an older key stay recoverable.
- Key material never enters a `ManagementJob` — job `cmd` and output rows persist forever. `BackupKeyCustody` reads and writes node keys over a direct SSH channel, passing the key on stdin only.
- Escrow happens **before** the key reaches the node: a generated key is sealed and its row saved first, so a key that exists on a node with no escrow row is impossible on that path.

Escrow runs on the **control plane, as a job step** (`JobCommandBuilder::step_escrow_backup_key()` → `plugins/server_manager/includes/escrow_node_key.php --node=<id>`), not inside the web request that asks for it. Reading a node's key means SSHing to it with that node's admin key, and those keys are owned by the operator account at mode 600 — readable by the agent, which runs every other node step, and not by the web-server user. The script seals, saves the row, stamps `mgn_backup_key_fingerprint`, and prints only `BACKUP_KEY_FPR` / `BACKUP_KEY_ESCROWED`, so the key-out-of-jobs rule holds. It is idempotent: an already-escrowed key produces no new row.

### Possession check

Sealing to a public key always appears to succeed, including when the pasted key is wrong — every blob would then be permanently unopenable, discovered only during a real recovery. So the key is honored only after the operator unseals a challenge with the private key. Until that proof is recorded (`server_manager_escrow_public_key_proven_fpr`), `escrow_public_key()` throws and encrypted backups refuse to run.

The check runs against the copy of the key the operator is actually keeping, which is the copy that has to work in a disaster. Two ways to do it, both proving possession of the same X25519 secret:

- **In the page** — paste the key (from a password manager, typically) into the setup panel. `BackupKeyCustody::browser_challenge()` packages the same proof string as `ephemeralPub[32] || iv[12] || ciphertext || tag`, sealed with X25519 → HKDF-SHA256 (info `sm-escrow-possession:` + ephemeral public + recipient public) → AES-256-GCM, so `assets/js/backup_key_verify.js` opens it with WebCrypto alone. The key is read from an input outside the form, used in memory, and cleared; it is never submitted, stored, or sent anywhere. Only the recovered proof string is posted, and the server re-checks it.
- **At the command line** — `escrow_keypair.php unseal` opens the libsodium sealed-box form of the same challenge with a key file.

What the challenge contains is a plain sentence ending in the key's full sha256 fingerprint — readable, so recovering it is self-evidently a success, and bound, so a proof earned for one key can never satisfy another. It is ASCII with no timestamp or randomness, because it is compared byte for byte after a copy-paste through a terminal.

### Guided setup

The **Backup key recovery** panel on the Backup Targets page detects how far setup has got and walks the outstanding step. It covers the recovery key alone — create the keypair, then prove possession — after which it collapses to a one-line confirmation. `BackupKeyCustody::setup_state()` is the single source of truth for that state.

Sealing an individual node's key belongs to that node, not to fleet setup: its Backups tab shows whether its key is sealed and offers **Seal backup key now**, which runs an `escrow_backup_key` job. Any encrypting backup seals the node's current key as its first step, before the step that verifies the key is present. The dashboard lists nodes still unsealed, from `BackupKeyCustody::survey_nodes()`.

Until setup is finished the platform does not offer backups it would refuse: a node with a cloud target shows the explanation in place of the Run Backup forms, and a local-only node has encryption switched off with a link to the panel. Creating an encrypting backup checks the recovery settings up front and refuses on the page; on the node, `JobCommandBuilder` verifies the key file exists (`BACKUP_KEY_MISSING`) rather than generating one there.

### Disaster recovery

To rebuild a lost node from its offsite backups:

1. Fetch the sealed key from the bucket: `escrow/{node_slug}/{fingerprint}.sealed` (the fingerprint is the sha256 of the raw key, shown on the node's Backups tab and stored in `bke_key_fingerprint`).
2. Open it on a machine holding the private key:
   `php escrow_keypair.php unseal --private /path/to/recovery.key --in blob.sealed`
3. Provision the replacement node, write the recovered key to `~/.joinery_backup_key` (mode 600).
4. Restore the archive through the dashboard, or with `restore_database.sh` / `restore_project.sh`, which decrypt with that key.

This works when the control plane itself is the casualty: the sealed blobs are in the bucket alongside the archives, so bucket credentials plus the password-manager private key are sufficient.

The **agent signing key** (the fleet trust root) is escrowed by the same mechanism, as an `agent_signing` row, when a release is published.

## How It Works: Smart Plugin, Dumb Agent

All job-type intelligence lives in `JobCommandBuilder.php`. The Go agent is a generic executor that understands four primitives: `ssh`, `scp`, `local`, and `api`.

**When an admin triggers an operation:**

1. PHP looks up the node's connection details (host, SSH key, container, etc.)
2. `JobCommandBuilder::build_<type>()` generates an ordered array of steps
3. PHP writes a job row with the steps in `mjb_commands` (JSON)
4. Go agent picks up the job, executes each main step in order, streams output
5. Agent runs the job's teardown steps (if any), then marks the job completed or failed
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

### Execution phases: main and teardown

A job's steps form two phases. Steps without the `teardown` flag are the **main
phase**: they run in order, and a hard failure (no `continue_on_error`) stops
the phase and determines the job's outcome. Steps flagged `"teardown": true`
are the **teardown phase**: they run on *every* exit path — success, mid-job
failure, or none-of-the-main-steps-ran — so the scratch files a job creates
(dumps, staged archives, unpacked installers) are removed even when the job
aborts on a shared production host.

Teardown semantics:

- **Teardown never changes the outcome.** A failed job stays failed with the
  original failing step in `mjb_error_message`; a teardown step erroring is
  logged under the `=== Teardown ===` output header and ignored.
- **Teardown runs before the terminal status is written.** The job stays
  `running` while teardown executes, so the per-node concurrency lock holds
  (no re-run can race the deletions) and the job detail view keeps streaming.
- **Progress counts main steps only.** `mjb_total_steps` excludes teardown
  steps and teardown output never advances `mjb_current_step`.
- **Stale-job replay.** Jobs force-failed at agent startup (left `running` by
  a crash or restart) get their teardown steps replayed from `mjb_commands` —
  safe because every teardown command is an idempotent `rm` on a per-job path.
- **Placement.** Builders put teardown steps at the tail of the array, after
  every main step, and keep `continue_on_error` on them. An agent that ignores
  the flag runs the array sequentially, so tail placement makes the steps
  plain trailing cleanup there — correct, just not failure-proof.

**Which cleanup belongs in which phase:** only **scratch** may be teardown —
an intermediate the job created purely to move data, where the original still
exists, at a per-job unique path. Two things look like cleanup and must stay
main steps: a *policy deletion* of real data (the offsite backup job's "Clean
up local backup" removes a node's actual backup after upload, and must stay
behind its upload-succeeded guard), and the *job's deliverable* (the
publish-upgrade job's release archives are its product; their lifecycle
belongs to the upgrade repository, never to teardown). The test: if this step
ran the moment the job ended — including right after a mid-job failure —
could it destroy data that exists nowhere else, or the thing the job was run
to produce? If either, it is not teardown.

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
| `continue_on_error` | No | If `true`, don't abort the job when this step fails |
| `timeout` | No | Max seconds for this step (default: 1800 = 30 minutes; teardown steps carry 120) |
| `teardown` | No | If `true`, the step is teardown-phase: it runs on every exit path, its failure never affects the job outcome, and it must be an idempotent removal of a per-job scratch path. Always placed at the tail of the step array with `continue_on_error` set. |

## Management API (Read-Only)

Every Joinery instance exposes a namespaced read-only HTTP surface at `/api/v1/management/*`. The control plane prefers this over SSH for observability operations (`check_status`, `list_backups`) because it's faster, parallelizable, and auditable.

**Endpoints** (all under `/api/v1/management/`, all `GET`, all JSON except `backups/fetch` which streams binary):

| Endpoint | Replaces SSH step(s) |
|----------|----------------------|
| `health` | (new — liveness probe) |
| `stats` | all steps of `check_status` |
| `version` | `Check Joinery version` |
| `databases` | `List databases` |
| `errors/recent` | `Recent errors` |
| `backups/list` | `list_backups` |
| `backups/fetch?path=...` | (no control-plane consumer — streams a backup file as binary) |

Discovery: `GET /api/v1/management` returns every endpoint with its description.

**Authentication** uses the existing API key system (`apk_api_keys` — same key headers and hashing as public CRUD; resolved by `ApiAuth::authenticate()`). The gate (`ApiAuth::authorize()`, with `requires_machine_key + min_user_permission: 10`) has **two requirements**: the key must be a **machine key** (`apk_type = machine`) — user session keys minted via `/api/v1/auth/login` get 403 here, so a superadmin logging into a phone app can't reach the control plane — **and** its owning user must be a superadmin (`usr_permission >= 10`). `apk_permission` is NOT a gate here — it's the CRUD-axis capability and is orthogonal. A superadmin's machine key with `apk_permission=1` can call management endpoints; a permission-5 admin's key cannot, regardless of `apk_permission`.

**Adding a management key for a node:** on the target node, Admin → API Keys → New Key (admin-created keys are machine keys, which is what the control plane requires), owner = a superadmin user, `apk_permission = 1`, IP-restrict to the control plane's egress IP. Paste the public/secret pair into the node's Overview tab on the control plane's Server Manager ("API Credential" panel).

> **IP restriction on docker-prod nodes:** for sites fronted directly by host Apache (no Cloudflare), the container now reads the real client IP via `mod_remoteip` + the host's `X-Forwarded-For: %{REMOTE_ADDR}s` header, so IP restriction works end-to-end. For Cloudflare-fronted sites, the container sees Cloudflare's edge IP — IP restriction is not yet meaningful in that case (a future spec will trust Cloudflare's ranges and read `CF-Connecting-IP`).

**Build-time routing:** `JobCommandBuilder::build_<op>()` dispatches to `build_<op>_api()` or `build_<op>_ssh()` based on `has_api($node, $op)`, which checks: (1) credentials stored on the node row, (2) a matching `build_<op>_api` exists, (3) a fresh `/health` probe succeeds. No runtime fallback — a job is decided at build-time and runs that path or fails. The existing SSH implementation stays in place; clearing the stored credentials or breaking `/health` routes the next job back to SSH automatically.

**Adding a new management endpoint:** drop a file under `includes/management_api/<name>_handler.php` with `<name>_handler($request)` + `<name>_handler_api()` meta function. Nested paths mirror directories (`backups/list_handler.php` → `GET /api/v1/management/backups/list`). Parallels the action-endpoint convention in `logic/*_logic.php`. The machine-key + superadmin default applies automatically; a handler can **tighten** it (never loosen) by returning an `'auth'` block from `<name>_handler_api()` — e.g. `'auth' => ['capability' => 'delete']` for a destructive endpoint. See [docs/api.md](../../../docs/api.md#declaring-endpoint-authorization).

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

### CustomerCloudAccount (`cca_customer_cloud_accounts`)

A user's OAuth-linked cloud provider account (one row per user + provider).
Holds the SecretBox-encrypted token set via `storeToken()`/`getToken()`;
`cca_status` is `active`, `refresh_failed`, or `revoked` (the latter two mean
the buyer must re-connect).

### CustomerCloudProvision (`cvp_customer_cloud_provisions`)

One cloud-instance provision, request to running site. `cvp_origin` is
`order` (keyed to the getjoinery order item — `cvp_external_order_item_id`,
unique, required for this origin) or `admin` (no order item); `cvp_status` is
the state machine documented under
[Customer-Cloud Fulfillment](#customer-cloud-fulfillment); install parameters
ride on the row (`cvp_docker_mode`, `cvp_install_mode`, `cvp_source_node_id`,
`cvp_backup_source`, `cvp_port`, `cvp_sitename`); links to the account
(`cvp_cca_account_id`), instance (`cvp_instance_id`/`_ip`), and resulting
node (`cvp_mgn_node_id`).

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

## Uptime Monitoring

A lightweight per-node uptime check runs on each scheduled-task tick (~15 min). It updates live state on `mgn_managed_nodes` and emails an admin on up→down and down→up transitions. One alert per transition; no re-alerting while still down.

**Augmented `mgn_managed_nodes` fields:**

- `mgn_uptime_enabled` (bool, default true) — per-node on/off
- `mgn_uptime_check_type` (varchar, default `'http_status'`) — which check method to use (see below). `http_status` is the default because it concludes up/down for any node with a site URL and needs no setup; `api` is an opt-in that requires API keys provisioned on the node.
- `mgn_uptime_last_status` (varchar) — `'up'` / `'down'` / null (never checked)
- `mgn_uptime_consecutive_failures` (int) — streak counter for threshold logic
- `mgn_uptime_down_since` (timestamp) — when current outage started, null when up
- `mgn_cert_expiry_ts` (timestamp) — observed `notAfter` of the served TLS cert (see Certificate-expiry monitoring)
- `mgn_cert_alerted_ts` (timestamp) — last cert-expiry warning send, for re-alert cadence; null when the cert is comfortably valid

"Last checked at" reuses the existing `mgn_last_status_check` — both check types update it.

**Per-node IP pinning.** `http_status` checks pin the request to the node's own `mgn_host` IP (`CURLOPT_RESOLVE`, SNI/Host preserved) when `mgn_host` is an IP literal **and** appears in the site hostname's public A records (`DnsResolver::getA()`) — the same directly-exposed guard `check_cert_expiry()` uses. A node behind a shared or round-robin hostname (e.g. two DNS servers sharing `dns.scrolldaddy.app` via dual A records) is therefore checked as *itself*, not whichever A record DNS happens to return — otherwise a single dead node hides behind its live partner. The guard matters: pinning a Cloudflare-fronted node to its origin IP would bypass the edge and hit the Apache default-vhost fallback cert, failing SNI/cert validation and reporting a false down — so nodes that aren't directly exposed are checked unpinned, through the public hostname, same as before pinning existed.

**Check types** (extensible via a single dispatch switch in `RunNodeUptimeChecks::run_check()`):

| Value | Behavior |
|-------|----------|
| `api` | Reuses `JobCommandBuilder::fetch_status_via_api($node)`. `reason='transport'` (DNS/connect/timeout) counts as down. **3xx responses also count as down** — the API endpoint should never redirect, so a 3xx means the request never reached the API handler (typical cause: infrastructure-level HTTP→HTTPS redirect, possibly looping if Cloudflare is in Flexible mode). Auth (401/403), body errors, and non-3xx non-200 statuses all mean the server responded → up. `reason='config'` (missing API keys) is a misconfiguration: logged to error log and skipped, no false down alert. |
| `http_status` | Plain curl GET to `mgn_site_url`. Success = HTTP status in 2xx or 3xx. Forced when `mgn_skip_joinery_checks=true` regardless of stored check type. |

Add more types by adding a method to `RunNodeUptimeChecks` and a `case` to the dispatch — no schema change needed.

**Tick logic** (`plugins/server_manager/tasks/RunNodeUptimeChecks.php`):

1. Iterate non-deleted nodes where `mgn_enabled` and `mgn_uptime_enabled` are true and `mgn_site_url` is set.
2. Dispatch on `mgn_uptime_check_type` (with `mgn_skip_joinery_checks` overriding to `http_status`).
3. Apply state machine:
   - On success: clear failure counter and `down_since`, set status `'up'`. Fire **recovered** alert on down→up transition.
   - On failure: increment `consecutive_failures`. Once it reaches `FAILURE_THRESHOLD` (default 2) and prior status wasn't `'down'`, set status `'down'`, set `down_since=now()`, fire **down** alert.

Constants on the class: `TIMEOUT_SECONDS=10`, `FAILURE_THRESHOLD=2`. The cron tick interval (~15 min) is the natural rate limiter.

**Alert email recipient** is resolved per tick via a fallback chain — no new setting:

1. `server_manager_provisioning_admin_alert_email` (existing plugin setting)
2. `webmaster_email` (existing core setting)
3. The first permission-10 user's email

If none resolve, the send is logged and skipped; the state machine still advances so the same transition isn't re-attempted on the next tick. Emails are sent via `EmailSender::quickSend()` with hard-coded plain-text bodies — no template editor in v1.

**UI:**

- Node edit form (`node_detail` overview tab and `node_add`): a "Monitor uptime" checkbox and a "Check type" dropdown. When `mgn_skip_joinery_checks` is on, the runtime forces `http_status` regardless of the stored value (so picking `api` here is harmless for non-Joinery nodes).
- Node detail overview tab: a one-line uptime status under "Last checked" — `Up`, `Down since X`, `disabled`, or `not yet checked`.

### Certificate-expiry monitoring

Every enabled node also gets an **independent TLS certificate-expiry check** on each tick (`RunNodeUptimeChecks::check_cert_expiry()`), separate from the up/down probe. It warns before a certificate **we renew** lapses — the failure mode where auto-renewal silently breaks for weeks and the cert expires unnoticed.

It reads the **served certificate over the wire** (`stream_socket_client` on `ssl://mgn_host:443`, `capture_peer_cert`, SNI = the site hostname), so it sees whatever the cert manager actually serves — Caddy, certbot, anything. Validity is deliberately *not* verified, so the `notAfter` of an already-expired or near-expiry cert is still readable.

**It self-limits to self-renewed, directly-exposed nodes** via two guards, because probing an origin behind a CDN returns a misleading cert:

1. **Directly-exposed:** `mgn_host` must appear in the public A records for the site hostname (`DnsResolver::getA()`). A Cloudflare-fronted hostname resolves to Cloudflare, not the origin — those are skipped, because Cloudflare renews that edge cert (not our failure surface).
2. **SAN match:** the served cert's CN/SANs must actually cover the hostname. A shared default-vhost fallback cert (SAN mismatch) is ignored — there is nothing dedicated to monitor.

On a monitored node it stores `mgn_cert_expiry_ts`. When days-remaining drops below `server_manager_cert_expiry_warn_days` (default **21**), it emails a warning through the same recipient fallback chain as the up/down alerts — once on crossing the threshold, then re-alerting every `CERT_RECHECK_ALERT_DAYS` (default 3) while still under it, and clearing `mgn_cert_alerted_ts` when a fresh cert pushes the date back out. The node detail overview shows a "TLS cert: expires …" line (warning-styled under threshold) whenever `mgn_cert_expiry_ts` is set — which also surfaces certs the certbot-file SSL tile can't see (e.g. Caddy nodes).

This is distinct from `mgn_ssl_state` / the SSL tile, which track certbot **provisioning** status (does an LE cert exist on disk) — a different question from "is the served cert about to expire," and largely a different set of nodes. The two are orthogonal.

## Safety Constraints

1. **Auto-backup before destructive operations** -- `copy_database`, `restore_database`, and `restore_project` automatically prepend backup steps. `restore_project` snapshots both the current database (`auto_pre_project_restore_*.sql.gz`) and the current project tree (`auto_pre_project_restore_*.tar.gz`) to `/backups/` before overwriting; either can be skipped if the corresponding component is unchecked in the form. If any pre-backup step fails, the destructive steps never run.

2. **Database restores replace** -- A database restore leaves the target equal to the snapshot. Every restore site (`restore_database`, both copy jobs, the from-backup install) verifies the archive with `gunzip -t` before anything is destroyed, drops and recreates the `public` schema so target-only objects are removed too, and loads with `psql -v ON_ERROR_STOP=1` so the first load error fails the job instead of completing a partial restore. Dumps are plain `pg_dump` snapshots -- the restore step owns the replacement guarantee, so it holds for any file it is fed. Job-internal dumps (copy jobs, install clone) add `--no-owner --no-acl` because they are restored as the *target* site's DB user; backup files restore onto the site that made them, where the role matches.

3. **Per-node concurrency lock** -- The agent skips jobs if another job is already running on the same node, preventing conflicts.

4. **Stale job recovery** -- On agent startup, any orphaned `running` jobs are marked `failed` with a descriptive message.

5. **Step timeout** -- 30-minute default per step, overridable. On timeout, the SSH session is killed.

6. **Single-threaded agent** -- One job at a time. Queued jobs run sequentially.

7. **Remote credentials at runtime** -- Database credentials for backup/copy/restore are extracted from each node's `Globalvars_site.php` at execution time, never stored on the control plane.

## API Actions

The dashboard's page JS calls these `POST /api/v1/action/server_manager/{name}`
actions with the browser-session credential (superadmin only, floor 10) and
reads the response envelope's `data`. The full set: `probe_api`, `job_status`,
`discover_nodes`, `backup_actions`, `refresh_node_status`, `add_discovered_nodes`.

### `server_manager/job_status`

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

### `server_manager/backup_actions`

Used by the backup browser on the Backups tab.

| Action | Method | Parameters | Returns |
|--------|--------|------------|---------|
| `refresh_list` | POST | `node_id` | `{success, job_id}` -- creates a `list_backups` job |
| `delete_file` | POST | `node_id`, `target` (local/cloud/both), `local_path`, `cloud_path` | `{success, job_id}` -- creates a `delete_backup` job |
| `list_status` | POST | `node_id`, `job_id` (optional) | `{success, status, backup_list, last_scan}` -- returns cached file listing |

### `server_manager/discover_nodes`

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
| `includes/JobCommandBuilder.php` | Command generation for all job types; `ssh_prefix()` is public for use by other tools |
| `node_exec.php` | **Dev/AI diagnostic CLI** — run any command on a managed node in one call; handles SSH + Docker transparently. Usage: `php node_exec.php` (list nodes), `php node_exec.php <slug> "<cmd>"` (run), or pipe stdin with `--stdin` for SQL queries. |
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
