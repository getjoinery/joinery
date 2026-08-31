# Server Manager

The Server Manager plugin provides a web UI for managing remote Joinery production servers. Operations include status checks, backups, database copies, and applying updates -- all from the admin interface at `/admin/server_manager`.

The system has two components:
- **PHP plugin** (`plugins/server_manager/`) -- admin UI, job creation, command generation
- **Go agent** (`/home/user1/joinery-agent/`) -- runs a management node's own job queue, and on a managed node takes work from its plane over the [agent channel](#the-agent-channel)

## Quick Start

### 1. Install the plugin

The plugin is already in the `plugins/` directory. From the admin panel:

1. Go to `/admin/admin_plugins`
2. Click **Actions** on "Server Manager" and choose **Install**
3. Click **Actions** again and choose **Activate**

The plugin creates its database tables automatically: `mgn_managed_nodes`, `mjb_management_jobs`, `ahb_agent_heartbeats`, `bkt_backup_targets`.

### 2. Install and start the Go agent

#### Release channel (how the agent normally arrives and stays current)

The agent ships inside the platform release. Publishing an upgrade bundles a signed agent artifact into `public_html/agent_dist/` — **core, not this plugin**, because every node must receive it and no plugin may arrive as a side effect of a core upgrade (this one is commercial and entitlement-gated). The plugin builds and signs the artifact; the core archive delivers it:

- `manifest.json` — agent version plus, per architecture, the artifact filename, its sha256, and an Ed25519 signature over the raw binary
- `joinery-agent-linux-amd64.gz` / `joinery-agent-linux-arm64.gz` — the binaries
- `joinery-agent.service` — the systemd unit

On the publishing management node, `publish_upgrade.php` cross-compiles both architectures from the checkout named by the `server_manager_agent_source_path` setting (default `/home/user1/joinery-agent`) whenever the source version differs from the bundled one, and signs them with the key at `{site root}/config/agent_signing_key` (generated on first publish; the `.pub` sibling holds the base64 public key that gets baked into the built agent).

Bundling is the first thing a publish does, before the VERSION file, the archives or the release row, because its outcome decides whether the release happens at all:

- **No agent source on this box** — the existing artifact carries forward unchanged and the publish proceeds. Publishing never depends on a Go toolchain being present.
- **Source version matches the bundle** — `agent_dist` is left byte-identical. It sits outside every plugin tree, so a rebuild changes no plugin's tree hash and bumps no plugin's version.
- **Source is newer and the rebuild succeeds** — the fresh artifact is bundled, and because this happens before plugin archives are built it is captured in the `server_manager` archive and its tree hash.
- **Source is newer and the rebuild fails** — the publish is refused. The build error is printed, and the VERSION file, archives and release row are all left untouched. Shipping here would mean releasing an agent the publisher already knows is out of date, and the resulting fleet has no way to tell.

The last line of a publish names the agent version the release carries. `plugins/server_manager/tests/agent_bundle_drift_test.php` asserts the same invariant on its own, so a bundle that falls behind its source is caught by the safe test tier rather than by the next release.

**First install** is handled by the core installer `maintenance_scripts/install_tools/install_agent.sh`, which runs at every root moment — site install, code upgrade, container start, and the node-detail **Run Plugin Installers** action. It installs the bundled binary, writes the env file with the right `JOINERY_CONFIG`, and sets up systemd or cron supervision automatically.

The installer is core rather than a plugin's, and runs on every Joinery instance: the agent does a machine's own backups, upgrades and health checks, and only a management node has `server_manager` turned on. The artifact stays in this plugin's tree because this plugin builds and signs it, and it reaches every node regardless — the plugin is `included_in_publish` and `receives_upgrades`, both independent of whether it is active there.

**The binary lands on every deployment.** Installing is not running: the artifact is converged at each root moment regardless of the switch, so a machine that is switched on later starts a service that is already there rather than fetching, decompressing and verifying one at that moment.

**Whether it runs is one setting, `agent_enabled`,** which ships off and is read fresh at each root moment: on starts the agent and sets up its supervision, off stops it and takes the supervision away — the cron keepalive included, or stopping would last a minute. The agent's identity survives an off, so turning it back on resumes the same pairing. A database the installer cannot reach leaves the machine untouched rather than being read as off.

Three ways to set it, all writing the same setting:

- the machine's own **Admin → System → Management Node** page, which also says what is still needed for it to take effect
- `php utils/agent_control.php --on` (also `--off`, `--join=URL`, `--leave`, `--status`) on the machine
- from a management node, the node detail **Agent Channel** panel's *Turn on the agent over SSH*, which switches it on, runs the installer, and has the node ask to join — the fleet path, available only while SSH is, and retired with it at the Phase 3 cutover
- `install.sh --enable-agent` at install time. A site this management node provisions passes it automatically, so a node it builds comes up running its agent — the one case where whether the machine should run one is already answered

None of those enroll anything. A join is a request; approving it here after comparing key fingerprints is what binds a node, unchanged.

A web request has no root, so flipping the setting does not itself start the agent — the next container start or upgrade does, or `sudo bash {site root}/maintenance_scripts/install_tools/install_agent.sh {sitename}` does it immediately. The Management Node page says so, and prints that command when the switch is on and no binary has reached the machine yet.

**Every later version change is handled by the agent itself.** Between jobs, the agent compares its own version with the bundled manifest. When they differ, it decompresses the artifact, checks the sha256, verifies the Ed25519 signature against the public key embedded in its binary, keeps the current binary as `.bak`, renames the new one into place, and exits cleanly for its supervisor to restart. The signature check is the security boundary: the site tree is writable by the web user while the agent runs as root, so the agent never installs anything the publisher did not sign. An artifact that fails verification is refused, logged under a `=== Self-update ===` header, and not retried until the manifest changes.

If the new binary fails to initialise (config, DB, or schema), it restores the `.bak` over itself and records the bad version in a `.rejected` marker — the supervisor restarts the previous working agent, and that version is never reinstalled; the next release supersedes the rejection. On the first fully healthy start after an update, the `.bak` and any stale marker are removed.

The dashboard's Agent Status bar surfaces all of this from the heartbeat row (`ahb_bundled_version`, `ahb_update_state`): a pending update, a refused (verification-failed) artifact, a rolled-back version, or an agent built without an update key.

#### Manual install (bootstrap fallback)

For a management node that has no bundled artifact yet, build and install by hand:

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

**Every management node needs a live agent.** All jobs (install_node, provision_ssl, backups, upgrades) sit `pending` until an agent polling that site's own database claims them. The **Server Manager → Provisioning** page shows an agent heartbeat badge as requirement #1.

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
2. **SSH Key Path** -- path to the private key on the management node (defaults to `/home/user1/.ssh/id_ed25519_claude`)
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
| `/admin/server_manager/domains` | **Domains** -- managed domain registrations: hand-overs waiting for a registrar push, failures, and the full ledger |

### Node Detail Tabs

The node detail page (`/admin/server_manager/node_detail?mgn_id=N&tab=...`) has six tabs:

| Tab | Purpose |
|-----|---------|
| **Overview** | Status summary (health dot, disk/memory/load/postgres/version), action buttons (Check Status, Test Connection), recent jobs for this node, connection settings (collapsed by default), delete node. The Actions dropdown also offers **Run Plugin Installers** — queues a `run_plugin_installers` job that executes every active plugin's declared `host_installer` on the node as root (idempotent); this is how a bare-metal node picks up system-service configuration (e.g. the mail stack) after a plugin is activated, since it has no container-start moment |
| **Backups** | Target indicator, run database/project backup, backup file browser with scan, per-file upload-to-cloud and delete, restore full project from a `.tar.gz` archive, restore from an incremental chain |
| **Database** | Restore from a backup file, and the record of database operations |
| **Updates** | Version comparison (node vs management node), apply update |
| **Jobs** | Job history filtered to this node, with status and type filters |

### Running a command on a node

There is no way to. A management node holds no mechanism for running an
instruction it composed at runtime on a managed node — no Console tab, no
`node_exec.php`, no `run_command` job (decision A1).

What a node accepts instead is a **primitive**: a name it looks up in a
vocabulary compiled into its own agent, with parameters validated against a
declared shape. A job names an operation; it never carries a command. A node
refuses anything outside that vocabulary whatever this plane asks, so what the
fleet can be made to do is bounded by what was built and signed, not by what
someone can compose.

Work with no primitive yet is done over the operator's **own SSH key**. That is
deliberate and it costs something real: those sessions are not recorded, where a
console run produced a job row with its command, operator, output and exit
status. The audit trail was given up knowingly, because a recorded path that can
run anything is still a path that can run anything, and a compromised plane
would have used it. `sshd` stays on across the current fleet (A11), so this is
the working route until the vocabulary covers the operation.

### Dashboard Features

The dashboard shows:

- **Agent Status** -- online/offline indicator with version and last heartbeat time
- **Managed Nodes** -- cards with health-based status dots (green=healthy, yellow=warning, red=problem, gray=no data), key metrics, and action buttons
- **Publish Upgrade** -- build upgrade archives from management node source code (node-independent)
- **Recent Jobs** -- latest 20 jobs across all nodes

Health dot colors reflect actual server health, not check recency:
- **Red**: Last check failed, disk > 90%, or PostgreSQL not accepting connections
- **Yellow**: Disk > 80% or load average > 5
- **Green**: All metrics healthy
- **Gray**: Never checked or no data

## The agent channel

A node's agent polls its management node over an outbound HTTPS connection, takes one job at a time, and posts the result back. Nothing has to reach in: a node behind NAT or Cloudflare works the same as one with a public address, and the poll itself is how the plane knows the node is alive.

**A job names an operation; it never carries a command.** The payload is `{primitive, params}` — a name the agent looks up in a vocabulary compiled into its own binary, plus parameters validated against the bounds that primitive declares. A name the agent does not have, a parameter it did not declare, or an operation class the node does not accept is **refused on the node**, with the reason recorded and reported back, whatever the plane asked for.

Primitives are grouped into three classes, and a node's acceptance policy is set per class:

| Class | What it covers | Accepted unattended |
|---|---|---|
| `observe` | Collectors, status, listing | Yes |
| `operate` | Restarts, disk, certs, upgrades, backup runs | Yes |
| `destructive` | Restores, decommission | **No, anywhere** |

There is no class for running an arbitrary command. The agent's own test suite enforces that structurally: the vocabulary lives in its own package, exactly one file in it may start a process, and that file refuses to execute anything it cannot verify against the signed release manifest.

The policy lives at `/etc/joinery-agent/policy.json`, root-owned, outside the web tree. A missing file means the shipped fleet-wide policy above. A file that is not root-owned, or is writable by group or other, is refused outright and the node accepts nothing until a human fixes it — trusting a file the web user could have written would defeat the only thing the file is for.

### Connecting a node (the join)

Enrollment is node-initiated and **shares no secret**. On the node, Admin → System → **Management Node**: the site's admin enters the management node's URL — just an address. The node's root agent generates an Ed25519 keypair, keeps the private half at `/etc/joinery-agent/node_identity.json` (mode 0600, root-owned), and sends a join request carrying only the public half and a claimed name. The node's page then shows the key's short fingerprint and waits.

On the management node, the request appears on the node's Detail → **API Keys** tab: claimed name, source address, and the same fingerprint. Approving is superadmin-only and should happen **only if the fingerprint matches the node's own page exactly** — the name and address are claims anyone could make; the fingerprint comparison is the entire security of the introduction. Approval binds the public key to the node record; the agent picks it up on its next check and both panels flip to Connected.

**The management node stores only the public half**, so there is no credential on it that could act as the node — and enrollment adds nothing to steal: no token, no code, nothing copied by a human. A wrong approval is visible (the tab stamps the connection time, agent version, and last poll) and severable with the Disconnect button, which forgets the key and drops the node back to API/SSH routing.

Requests are rate-limited, expire after an hour (the agent renews its own while it waits), and are capped in number. A rejected request retires its keypair — the agent introduces itself with a fresh key on the next ask.

The web tier's only involvement on the node is a handoff through three managed settings: `agent_join_request` (the URL the admin asked for), `agent_join_state` (the agent's progress, which the page renders), and `agent_leave_request` (the admin asked to disconnect). None ever holds a credential.

### Routing

A connected agent is routed to — approving the join is the routing decision, and there is no further switch. An operation with a primitive implementation runs on the node's own agent, and only there: a node without a paired agent is refused such a job at build time, with the fix (pair the node) in the message. SSH-credential routing exists only for the operations that have no primitive — bootstrap (`install_node`, `enable_agent`, unpaired `provision_ssl`), `decommission_node`, and the relay lifecycle. Disconnecting the agent therefore stops a node's primitive operations until it pairs again.

Either side can end the pairing, and neither needs the other's cooperation. This plane's Disconnect button forgets the node's key. The node's own Management Node page has a Disconnect too: its agent finishes any running job, sends one signed goodbye to `/api/v1/agent/leave` (so this plane forgets the key immediately), deletes its identity, and returns to serving only local work — and it leaves even when the goodbye cannot be delivered, in which case this plane just sees the agent go silent until someone disconnects the node here as well. Both endings run through `AgentChannelEndpoint::forgetAgent()`, so they cannot drift apart.

Operations cross one at a time. An operation has crossed when `JobCommandBuilder` has a `build_<op>_primitive` method for it; `transports_for()` discovers that by reflection, and `ManagementJob::createFromBuild()` stores the right shape without any caller knowing which transport ran.

**A primitive is routed to a node only when that node says it has it.** Every claim carries the agent's own list of the primitives its binary compiled in, and the plane stores it (`mgn_agent_primitives`). Routing consults that list: an operation missing from it is not sent to the agent — the builder refuses at build time (or, for `list_backups` and `check_status`, falls to the API or probe transport). The node's own account is the only one that is not a guess — a version number says which release a machine runs, and only the machine says what that release compiled into it.

An agent old enough not to send a list leaves the column empty, and those nodes are answered by `JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION`: a per-operation floor naming the agent version that introduced the primitive. A node below the floor, or with no known version, is refused the primitive — dispatching to a vocabulary that cannot be confirmed buys a certain refusal on the node instead of a clear one at build time.

**Upgrade the management node's own agent before connecting any node.** An agent from before this channel existed does not know to leave primitive jobs alone, and would claim one out of its local queue. Such a job carries a step type no released executor recognises, so that agent fails it and says exactly why rather than marking it complete having done nothing — but the job still has to be re-run.

### Reading refusals

A refused job is a terminal failure like any other, and reads as one everywhere a job status is shown — the message says `Refused by the node:` and then the node's own reason. The outcome the node actually reported is also recorded on its own (`mjb_agent_outcome`: `completed`, `failed` or `refused`), so a refusal can be **counted** rather than found by matching the text of an error message. `ManagementJob::refusalCountForNode()` is that count, windowed.

This matters more as the vocabulary grows. A node refusing work is a node whose plane is asking for something it should not be, or whose policy has been tightened without the plane noticing — either way it is the number, not the prose, that an alert reads.

A job this plane gives up on after repeated lost claims records **no** node outcome. The node never reported one, and inventing a verdict for it would make the refusal count untrustworthy in exactly the situation where it is being consulted.

### When a claim does not come back

An agent claims a job and then reports. If it never reports — it crashed, the box rebooted, the network went — the job would otherwise sit in `running` holding that node's concurrency lock. A claim older than 15 minutes is returned to the queue with a note in the job output saying so, on every poll and on every scheduled uptime pass. After three such claims the job fails instead, naming the node: a job that kills three agents will not succeed on the fourth.

### Endpoints

`POST /api/v1/agent/join`, `/join_status`, `/claim`, `/result`, `/leave`, `/quiet`, `/artifact`. Join and join_status are unauthenticated by nature — until approval there is no identity to authenticate — and grant nothing; they are rate-limited, validated, and bounded in number. Claim and result are signed with the node's key; the plane verifies against the public half it holds, and selects jobs by the identity the **signature** proves, never by anything in the request body. Requests are schema-validated, size-capped, and refused for a clock more than five minutes from the plane's. These are a separate route family from `/api/v1/management/*`, which runs the other way — the plane calling in to a node's web tier — and stays status-only.

`/artifact` is the one response that is bytes rather than a JSON envelope, and it is described under [Machines with no site](#machines-with-no-site). Its body fetches are metered on their own bucket (`api_agent_artifact_rate_limit_requests`, per address per window) and each is recorded, because they are the expensive ones; the small manifest fetches ride the general agent-channel limit.

## Machines with no site

Some machines a management node manages host no Joinery site at all — a mail relay, a Docker host. They run the same agent in a **machine posture**: no site root, no local database, no admin page, and no platform release ever delivered to them. Two things follow, and both are served by the same endpoint.

### Keeping the agent current

A machine with a site tree finds its next binary in `public_html/agent_dist`, put there by its own upgrade, and updates without asking anyone. A machine with no site has no such directory, so it fetches from its management node instead: a signed request to `/api/v1/agent/artifact` for the dist manifest, and — when the version differs from what it is running — for the binary for its architecture.

**Nothing about verification moves.** The agent decompresses, checks the sha256, and verifies an Ed25519 signature against the public key compiled into its own binary at build time, exactly as it does for a locally delivered artifact. The management node does not hold the release key and cannot sign an agent, so a plane serving a hostile binary produces a refusal and a recorded rejection. The endpoint is a delivery route for bytes that were always verified on arrival.

The request names a **kind** from a closed set and, for a binary, an **architecture** matched against a pattern. It never names a file: the plane resolves what to send out of its own manifest, so nothing a node sends is read as a path.

### The support bundle

A script-invoking primitive verifies its script against the signed release manifest before running it as root. On a machine with no site there is no release manifest, so there is nothing to verify against and no script primitive can run at all — which would leave the machine's whole vocabulary in embedded Go.

The support bundle closes that. A publish builds `public_html/agent_dist/support_bundle.tar.gz`: a small tree carrying the scripts those machines' primitives invoke, at site-root-relative paths, with its own `RELEASE_MANIFEST` and `.sig` signed by the release key. A siteless machine fetches it over the same artifact endpoint, verifies the signature against its baked-in key, checks every listed file's hash **and** that the tree holds nothing the manifest does not list, then unpacks it root-owned to `/opt/joinery-agent/tree`. Script primitives resolve against that tree when there is no site root; a machine with a site root uses the site root, and a machine with neither refuses as it always has.

The bundle's contents are a deliberate list in `SupportBundlePublisher`, not a directory sweep — every entry is a script some primitive names, and adding one is a visible decision. A script that sources another needs that sibling in the list at the same relative path. Binaries the scripts invoke ship under `bin/` with one file per architecture (`bin/<tool>-linux-amd64`, `bin/<tool>-linux-arm64`) and the script selecting on `uname -m`: one bundle carries both, so no machine can end up holding one built for the wrong architecture.

Its version is the hash of its own manifest body, so a publish that changes no bundled script leaves the bundle byte-identical and no machine downloads anything. The plane also advertises the tarball's sha256, which an agent uses only to skip a transfer it already has — what makes the tree runnable is the signature inside it.

Each machine reports the bundle version it holds on every claim, stored as `mgn_agent_bundle_version`. On a machine nobody logs into, that column is the evidence the bundle arrived.

### Enrolling and switching one on

There is no admin page on these machines, so the ceremony is the same one reached from the command line:

```bash
joinery-agent join --management-node=https://plane.example.com
joinery-agent status
joinery-agent enable | disable
joinery-agent leave
```

`join` generates the keypair, sends only the public half, and prints the fingerprint to compare against the pending request on the plane — the same comparison, and the same approval, as a node enrolling from its own admin page. The run switch is the marker file `/etc/joinery-agent/enabled`, which `enable` and `disable` write directly: on a machine with no settings table, the marker is the switch rather than a projection of one.

Install with `install_agent.sh --siteless`, which is explicit and never inferred — a missing site config keeps meaning "not my machine, exit 0" for everything else. `--dist-dir=DIR` names where the first artifact is, since no release delivered one.

## Job Types

| Job Type | Description | Destructive |
|----------|-------------|-------------|
| `check_status` | Disk, memory, load, uptime, PostgreSQL, version and database list. On a node with an agent this is the `check_status` **observe primitive**, which collects all of it without running a command; on a Joinery site without one, the management API; on a machine with neither, a **probe** of what the machine publishes about itself | No |
| `backup_database` | Run `backup_database.sh`, optionally upload to cloud | No |
| `backup_project` | Run `backup_project.sh` (DB + files + Apache config), optionally upload | No |
| `list_backups` | List backup files on local server and cloud target | No |
| `upload_backup` | Push one existing backup file from the node to its cloud target; keeps the local copy | No |
| `delete_backup` | Delete backup files from local, cloud, or both | **Yes** |
| `copy_database` | Dump source DB, transfer, restore on target | **Yes** |
| `restore_database` | Restore a backup file on a node | **Yes** |
| `restore_project` | Restore a full project `.tar.gz` (files + DB) in place on an existing node, then reconcile it to that machine. Runs `restore_project.sh --force --domain <domain>`, which cascades `--non-interactive` into `restore_database.sh`. Pre-restore snapshots of DB and files written to `/backups/auto_pre_project_restore_*`. Every file in the archive must exist under the project directory afterwards or the restore fails and names what is missing | **Yes** |
| `restore_chain` | Restore a node from an incremental backup chain — what the fleet's scheduled backups actually produce. Fetches the chain manifest, recovers the chain key on the node from the node's own `backup_site_key`, downloads every artifact the manifest names up to the chosen run, then runs `restore_chain.sh`, which verifies each artifact against its recorded size and hash **before writing anything** and applies them in order | **Yes** |
| `apply_update` | Run `upgrade.php` on target | **Yes** |
| `publish_upgrade` | Run `publish_upgrade.php` locally on management node (in plugin) | No |
| `discover_nodes` | Scan a remote host for Joinery instances (Docker + bare metal) | No |
| `install_node` | Provision a fresh Joinery site on a remote host (fresh or from-backup) | No (target must be clean) |
| `provision_ssl` | Run certbot on the node's host to obtain a Let's Encrypt cert | No |
| `backup_run` | This management node's own backup of a node. The node runs its backup engine — chain, envelope, upload, local sweep — with the bucket and a write-only credential supplied for that run and never stored there. What opens the archive is not supplied: the node seals to the recovery key it holds and has verified | No |
| `decommission_node` | Ship and run `remove_account.sh` on the host to permanently delete the site, verify it is gone, then soft-delete the node record | **Yes** |

Destructive operations auto-backup the target database before proceeding. The UI requires explicit confirmation checkboxes.

**Note on bare-metal nodes with user1 SSH:** When a bare-metal install completes, `install.sh` disables root SSH and the node's `mgn_ssh_user` is automatically updated to `user1`. SSH-only jobs (bootstrap, decommission) then run as `user1` with `NOPASSWD sudo`; steps that need root-level paths carry the `sudo` prefix automatically. Backup and restore work runs on the node's own agent, which is already root.

### One-Click Node Install

**Dashboard → Install New Node** opens a form that provisions a Joinery site in a single click. The **Target Host** dropdown offers three kinds of target:

- **A known host** (or *Other server* with manual SSH details): the form creates the ManagedNode and dispatches the `install_node` job immediately.
- **Create a new cloud instance**: no server exists yet. The form records an admin-origin `CustomerCloudProvision` (connected cloud account, region, instance type, plus all install parameters) and the **Provision Customer Cloud** task births the instance, creates the node, and dispatches the install — see [Customer-Cloud Fulfillment](#customer-cloud-fulfillment). The instance is created in, and billed to, the selected connected account; Linode grants expire after two hours, so connect (or re-connect) shortly before submitting. Cloud targets always take a fresh source backup in From-Backup mode. In-flight provisions appear in a banner at the top of the dashboard.

  The cloud target also offers **Bare instance** as the install type: the instance is born, the SSH key injected, and the managed node created with `mgn_skip_joinery_checks` set — but no site is installed (no web root, site URL, or SSL flow). Completion is a passing `check_status` job. This is how infrastructure nodes that host no Joinery site — a mail relay shard, for example — enter management; the role's own provisioning (e.g. the mailbox plugin's provision-relay job) builds on the bare node afterward. Bare is admin-origin only; orders always install a site.

Two install types:

- **Fresh**: empty Joinery site with default schema. Admin picks the domain. The admin login is `admin@example.com` with a password generated for that site alone — there is no shared default. Like the generated Postgres password, it stays on the node rather than in the management node: read it at `/var/www/html/{sitename}/config/admin_credentials.txt` (root only), or set a new one with `maintenance_scripts/sysadmin_tools/reset_admin_password.php`. `usr_force_password_change=true`, so the first sign-in forces a new password.
- **From Backup**: fresh install + restore of a source node's DB and project files, then reconciliation to the new node — its own domain (the node's recorded URL), its own deployment shape, its own paths. Use source admin credentials to log in; cut DNS over when ready and the certificate is issued on its own.

The job composes existing primitives: the installer artifacts from `maintenance_scripts/install_tools/` are packaged locally, SCP'd, extracted on the target, and `install.sh -y -q site SITENAME - DOMAIN` runs non-interactively. Docker installs add a follow-up step that invokes `manage_domain.sh set SITENAME DOMAIN --no-ssl` on the target to auto-install Apache + mod_proxy (if missing) and wire up an HTTP reverse proxy on port 80 — so the site is reachable at `http://DOMAIN/` as soon as DNS points here. SSL stays a separate admin step (`certbot --apache -d DOMAIN` on the target). For From-Backup, source backups are captured (or an existing cached backup is used), fetched to the management node, and pushed to the target after install.

From-Backup restores files by extracting the source archive with **both** of its
leading path components stripped, taking only the `project_files/` subtree —
`backup_project.sh` writes archives as `{backup_name}/project_files/{public_html,
uploads,config,...}` with the archive's own metadata (`apache_config/`,
`backup_info.txt`, the `.sql` dump) as siblings. The target keeps its own
`Globalvars_site.php` (it holds this machine's database password and
`secret_box_key`) and mints its own `backup_site_key` rather than inheriting the
source's identity as a backup recipient. A verification step then requires every regular file the
archive carries to exist at the site root and fails the job otherwise, because a
clone whose files did not land still serves pages: the fresh install ran first
and the database restore succeeded, so the only symptom is uploaded files
missing from where the restored database says they are.

The `mgn_install_state` column tracks the lifecycle: `installing` → `NULL` (success) or `install_failed` (failure). On failure, the node detail page surfaces a **Retry Install** button; the target must be cleaned manually (e.g. `rm -rf /var/www/html/SITENAME`) before retry because `install.sh` refuses to overwrite an existing site. Postgres passwords are auto-generated and stored in the target's `Globalvars_site.php` — Server Manager does not capture or display them.

**Docker notes:**
- The reverse proxy step (`manage_domain.sh`) is skipped when the domain is a bare IP address — a routable hostname is required for Apache `ServerName`-based virtual hosting. With an IP domain, the site is accessible directly on its mapped port.
- `backup_project.sh` requires `rsync`. The bare-metal and Docker install scripts install rsync as part of the essential packages (`install.sh` line ~948). Sites installed before this was added can install it manually with `apt install rsync`.
- After a Docker install, `mgn_container_name` is automatically recorded in the management node DB so future jobs correctly use `docker exec` to reach the site.

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

A `check_status` on a node with an agent enumerates the certificate lineages the node holds, and `JobResultProcessor` matches them against the host this plane expects. Where no certificate is reported, an HTTPS probe from here catches a site whose TLS terminates at an edge. `JobResultProcessor` updates `mgn_ssl_state` and stores `ssl_domain`, `ssl_expiry_raw`, and `ssl_expiry_ts` in `mgn_last_status_data`. State transitions:

- `CERT_FOUND` → sets state to `active` (from any prior state)
- `CERT_MISSING` → clears state to `null` only if currently `null` or `active`; never overwrites `pending` or `failed`

### Manual Provisioning

The **Overview** tab shows an **SSL Setup card** when `mgn_ssl_state` is not `active`, the node has a domain in its site URL, and `mgn_cert_expiry_ts` is empty. The last condition excludes directly-exposed, self-renewed nodes (see [Certificate Expiry Monitoring](#certificate-expiry-monitoring)) — their cert lifecycle is owned by an external renewer (e.g. Caddy), and the card's certbot-based provisioning does not apply to them. The card:

1. Resolves the domain via DNS and shows whether it points to the node's host IP
2. Enables the **Provision SSL** button when DNS is ready (or when the host IP is not configured)
3. On submit: creates a `provision_ssl` job, sets `mgn_ssl_state = 'pending'`, redirects to job detail

The `provision_ssl` job runs `certbot --apache -d DOMAIN` on the node's host (for Docker nodes, certbot runs on the reverse-proxy host, not inside the container). On success, `mgn_ssl_state` is set to `active` by `JobResultProcessor`.

**Cloudflare-proxied domains** skip certbot (Cloudflare terminates TLS at its edge) but are gated on a routing probe: the job writes a one-time token to `{webroot}/sm-ssl-probe.txt` on the node, and the management node fetches `/sm-ssl-probe.txt` through the domain. The token is only fetchable because core serve.php routes that URL to `views/sm_ssl_probe.php`, which serves the file — a Joinery front controller never serves arbitrary webroot files, so a node whose code predates that route cannot pass the probe and needs an upgrade first. Only a match — proof that traffic for the domain actually lands on this node — patches the proxy's `X-Forwarded-Proto` and marks SSL `active` (`JobResultProcessor` additionally requires the `CF_ROUTING_VERIFIED` marker). A miss fails the job and the domain stays pending until the customer's DNS actually routes here.

### Automated Provisioning (installs only)

For nodes installed via **Install New Node**, `ProvisionPendingSsl` (scheduled hourly) watches for nodes with `mgn_ssl_state = 'pending'`, checks DNS, and kicks off `provision_ssl` jobs automatically. After ~16 hours of failed attempts it flips state to `failed` — except a Cloudflare domain still waiting on its DNS cutover (`CF_ROUTING_UNVERIFIED`), which never flips: a cutover the customer has not made is not a fault, and can legitimately take days. Instead the routing wait is paced — hourly for the first `ROUTING_FAST_ATTEMPTS` tries, then one try every `ROUTING_SLOW_GAP` (six hours) — and entering the slow lane emails the operator once (recipient chain: `server_manager_provisioning_admin_alert_email` → `webmaster_email` → first superadmin; the sent marker is stamped into a job row's parameters as `routing_alert_sent`). The slow lane and the alert apply only while the domain still resolves to Cloudflare: once it repoints, the next attempt is due within the hour, and the 16-hour give-up window opens fresh at the first non-routing failure — time spent parked at Cloudflare never burns it. Manual provisioning via the Setup card is the fallback.

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

A hosting product can also sell the buyer their **domain name** in the same
click — see **Managed domain registration** below. That leg is orthogonal to
compute mode: it attaches to shared-host and customer-cloud products alike,
and when it is present the buyer never touches DNS at all.

### Activation — the Provisioning page

**Server Manager → Provisioning** (`/admin/server_manager/provisioning_setup`)
activates the pipeline: every requirement shows a live status badge, and each
automatable step is a one-click, idempotent action backed by
`includes/ProvisioningSetup.php` — mint the store API service user
(`provisioning@<host>`, permission 5, password recovery disabled) and machine
key and write the API settings (with a loopback probe badge and key
rotation), create the domain Question, save the email settings, activate the
scheduled tasks (the provisioning umbrella, which runs order polling,
customer cloud, SSL and the managed-domain phases in one pass, plus the
core Send Queued Emails task that drains the welcome-email queue), the
domain-registrar credentials, and the customer-cloud settings (SSH key path
with key/.pub existence badges, referral URL, instance defaults). The page
also shows what stays manual: attaching the question or the Managed domain
requirement to hosting products, opting a shared host in, and registering the
Linode OAuth app. When the store
is a remote site rather than the management node itself, the service key is
minted on the store site and its values entered in the API settings fields.

The customer-cloud provisioning keypair (the public half is installed on
created instances; the private half is the management node's only access to
them) is generated automatically at plugin activation
(`activate.php` → `ProvisioningSetup::ensureSshKey()`), defaulting to
`{site root}/config/provisioning_key`. The page's **Generate provisioning
key** button runs the same idempotent action for management nodes activated
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

## Managed Domain Registration

A hosting buyer who does not already own a domain can buy one in the same
click as the server. At checkout they type the name they want, see live
availability and the one-year price, and fill a contact block prefilled from
their account. One payment covers both. Behind it the pipeline registers the
name, points it at their box, publishes their mail records and sets reverse
DNS — so their website answers and `jane@theirdomain.com` works by the time
the welcome email lands. No registrar dashboard, no DNS panel, no waiting on
the buyer to paste a record somewhere.

**The buyer legally owns the domain from the moment it is registered.** They
are the WHOIS registrant on day one; the operator holds only management and
billing, so that buying it could be one click. Those move to the buyer later
(see *Graduation*). Ownership is never in question and never waits on a step.

### Selling it

Two things have to be set before a domain can be sold, and until both are the
checkout field refuses the order rather than taking money for a name it cannot
register:

1. **Registrar credentials** — the *Domain registration* card on
   **Server Manager → Provisioning**. Namecheap needs an API username, an API
   key (sealed at rest), and the management node's public IPv4 address
   allowlisted in its API panel. Namecheap grants API access only to accounts
   with 20+ domains, $50 in the balance, or $50 spent in the last two years.
   A sandbox switch points every registrar call at Namecheap's sandbox for a
   full rehearsal.
2. **A domain-year product** — an ordinary store product, not publicly
   listed, with one version whose price type is `user`. Select it in the
   store's `store_domain_registration_product_id` setting. Its price comes
   from the live registrar quote at checkout, so the buyer pays one year at
   cost.

Then attach **Managed domain** to the hosting product from *Info to collect
before purchase* on the product edit page. `server_manager_domain_tlds`
(default `com net org`) bounds what can be asked for.

Both gates check the thing they name, not just the setting: a domain-year
product that was deleted, or whose version was deactivated, reads as unusable
and the checkout field refuses — because a silently skipped cart line would
mean registering a domain nobody was charged for.

One payment consequence worth knowing: a subscription hosting line plus a
one-time domain line is a mixed cart, and PayPal cannot process one
(`ShoppingCart::is_paypal_available()`). Deployments selling subscription
hosting with managed domains take card payment through Stripe.

### What the buyer's answer becomes

`ManagedDomainRequirement` validates the submission against the registrar,
live: the name has to be registrable, in an offered ending, available, and not
premium, and the contact block has to be complete (including a phone number
with an explicit country code — a bare number is refused rather than guessed
at, because guessing puts a stranger's country code on a public WHOIS record).

The quote it gets back drives two things. It becomes a **second cart line**
against the domain-year product, priced through the existing
`prv_price_type = 'user'` path — a line rather than a surcharge because a line
carries its own recurrence, and a one-time fee folded into a subscription line
would bill every cycle. And after payment, `post_purchase()` files an
`rdm_registered_domains` row for the pipeline to work from. Nothing
price-shaped is ever read from the POST.

### Fulfillment

`ProvisionManagedDomains` runs as a phase of the provisioning umbrella task
and takes at most one step per row per tick:

| Step | Guard |
|---|---|
| register the name with the buyer as registrant, WHOIS privacy on | `rdm_status` is `pending` |
| publish apex + `www` A records at the box | `rdm_dns_bootstrap_time` |
| ask the box for its mail records and publish them | `rdm_dns_mail_time` |
| set the PTR to `mail.<domain>` | `rdm_ptr_time` |

Each null timestamp is an outstanding step retried next tick; a stamped one is
never redone. Registration is guarded by status rather than a timestamp
because a stamp written after a charge is one crash away from a second charge
— and when the registrar reports the name unavailable, the phase asks whether
*we already hold it* before concluding someone else took it.

The web records unblock certificate issuance, so `ProvisionPendingSsl`
succeeds without the buyer doing anything. The mail records are **not**
computed on the management node. The box is asked, as a
`managed_domain_prepare` job on the agent channel whose whole vocabulary is the
domain: the node runs
`plugins/mailbox/utils/managed_domain_prepare.php` against its own site, makes
the domain mail-ready, and prints the record set
`InboundEmailSetupCheck::dnsPlan()` prescribes — the box is what knows its own
topology, SPF shape, DKIM key and Joinery Direct state, and a management node
that guessed would publish a plausible set the box does not match.

The answer therefore lands on a later tick than the question, and the mail step
is a four-state check rather than a call: a completed and unread job is read,
published and marked consumed; a job in flight is waited for; a finished one is
re-asked after `PREPARE_RETRY_GAP_MINUTES`. **Reading it exactly once is what
makes the incomplete answers work.** A record set returned without DKIM is
published anyway (MX and SPF are what make mail arrive) but the step stays open
until the signing key is included — and without the consumed mark, that same
answer would be re-read and re-published every tick and the signing key never
asked for again. The lookup is scoped to the domain as well as the node,
because a shared host carries many managed domains. A node whose agent does not
offer the primitive writes the reason onto `rdm_error`, where the Domains page
shows it, and is retried rather than failed.

**Before anything is bought, the order is checked for the money.** The
checkout answers and the payment are two separate objects, and the cart lets a
buyer separate them: every cart line carries its own Edit and Remove, so the
domain-year line can be deleted — or repriced through its own product page —
while the hosting line carrying the answers is submitted unchanged. The intake
reads the hosting line, so without a check the domain would be registered on
the operator's card for free. The rule: the order must hold a paid
domain-registration line worth at least the quote, and each such line backs at
most one registration. Anything else parks the row with an alert. That turns
every one of those doors into something an operator sees rather than a silent
loss.

Publishing always goes through `DnsReconciler` in additive mode, never a
driver's raw call: Namecheap's `setHosts` replaces a zone's entire host list,
and additive means the pipeline can create records a zone lacks but never
overwrites something a person put there. A shared-host row stamps the PTR step
immediately — one address serving many domains has no per-domain PTR to set.

The mirror case has its own sweep, because there is nothing to run it from. A
buyer who removes the *hosting* line from the cart and keeps the domain line
pays for a domain year whose intake never fires — no row is written, so no
queue could ever show it. `ManagedDomainWatch` therefore looks for the
arithmetic signature directly: an order with more paid domain-year lines than
registration rows. It reports each such order once (a high-water mark over
order-item ids), and gives a fresh charge fifteen minutes to file its row
before judging it.

A terminal failure parks the row at `failed` and emails the provisioning alert
address. It is never auto-retried: a name someone else took needs a
conversation with the buyer, not another attempt.

### Ownership and graduation

Legal ownership is immediate and never moves. What moves is custody —
`rdm_graduation_state`, running `operator_managed` → `push_requested` →
`push_sent` → `self_custody`.

While the domain sits in the operator's registrar account, its renewal bills
the **operator**, and the platform never renews a buyer's domain and never
fronts the cost. So the domain has to reach the buyer's own account before its
first expiry. `ManagedDomainWatch` is what makes sure it does:

- It sweeps for domain years that were paid for but never registered (above).
- It refreshes the expiry from the registrar at most weekly.
- At **expiry minus six months** it pushes a custody state to the buyer's box,
  and `ManagedDomainNotice` starts rendering a take-ownership notice there —
  calm at first, sharper at 30, 14, 7 and 1 days. That notice is the buyer's
  first mention of graduation anywhere; nothing in the setup wizard or the
  welcome email raises it. It is shown to permission-5+ users only, never to
  the site's visitors.
- Once a push is in flight it asks `inAccount()`. **False is the success
  signal** — the domain has left the operator's account — and flips the row to
  `self_custody`, updates the box, and emails the buyer a confirmation with
  the auto-renew reminder.

The buyer's side of it is `/profile/server_manager/domain`: create a free
registrar account, tell us its name, then finish in their own dashboard. That
middle step is the only part that happens here, and submitting it queues an
operator task — Namecheap's Change Ownership push has no API. The push itself
is free and immediate, and DNS records, WHOIS privacy and auto-renew settings
all survive it.

### The operator queue

**Server Manager → Domains** (`/admin/server_manager/domains`) is ordered by
what needs a person: hand-overs waiting for a dashboard push first, then
terminal failures with a Retry button, then every domain as a ledger with its
status, custody, expiry and per-step progress.

### Node settings

Four core settings, declared `managed` so the node's own settings page does not
offer them and the management node is their only author:
`managed_domain_name`, `managed_domain_expiry_time`, `managed_domain_state`,
`managed_domain_manage_url`. Empty `managed_domain_state` renders no notice,
which is what every deployment that did not buy a domain this way has.

They are written by a `managed_domain_notice` job, and **the setting names are
not on the wire**. The job carries four VALUES; which settings they land in is
decided by `utils/managed_domain_notice.php` on the node, which writes them
through `Setting::put` — so an undeclared name is refused by the declared-
settings gate, and a generic write-a-setting job that could reach the rest of
`stg_settings` does not exist.

`ManagedDomainWatch` **converges** on that state rather than pushing at it: each
tick computes what the box should be holding, compares it against the last
notice job that completed for that node and domain, and files one only when they
differ. A push that failed therefore self-heals on the next tick, and
`rdm_prompt_pushed_time` — the record that the buyer has seen the take-ownership
notice at all — is stamped from a job that COMPLETED carrying a state that
renders one, never from a dispatch.

### Adding a registrar

`DomainRegistrarProvider` (`includes/domain_registrar/`) is the seam;
`DomainRegistrarRegistry` discovers implementations by interface, so a second
registrar is a class in that directory and nothing else. It covers
availability and price, registration with a registrant contact, WHOIS privacy,
expiry, a custody probe, and which DNS driver serves its zones. It has no
renewal call and no DNS methods — the platform never renews, and records are
published through the shared DNS stack by driver key.

## Backup Targets

Backup targets define where backup files are uploaded after creation. Each node can optionally have a backup target assigned. If no target is set, backups remain local only on the remote server.

### Supported Providers

| Provider | Credentials (UI fields) |
|----------|-------------------------|
| **Backblaze B2** | Application Key ID + Application Key (region/endpoint auto-detected via `b2_authorize_account` at save time) |
| **Amazon S3** | Access Key + Secret Key + Region |
| **Linode Object Storage** | Access Key + Secret Key + Region + Endpoint URL |

All providers authenticate against their S3-compatible endpoint via AWS SigV4 signing performed by `S3Signer.php`. There is **no per-provider CLI dependency** — uploads, downloads, deletes, and listings all run as direct HTTPS calls, from the management node (web tier) or from the node's own agent. New S3-compatible providers can be added by configuration alone, no script changes.

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

Credentials are stored on the `bkt_backup_targets` table using a unified shape for every provider:

```json
{"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
```

Two columns hold two keys: `bkt_credentials` is the main (delete-capable) credential the management node itself uses, and `bkt_node_credentials` optionally holds a write-only key handed to nodes instead (see *The node may write to the shelf but never erase it*). Both are SecretBox-sealed at rest.

A persisted job never contains a credential — a node-bound **upload** (`backup_run`, `upload_backup`) carries a placeholder token that the agent channel resolves in memory when the job is handed out: `__SM_NODE_CREDS_<target_id>__` for the write-only node slot whenever it is filled, `__SM_CREDS_<target_id>__` otherwise. The channel resolves exactly the slot the token names and never falls back to the other, so a job built against a since-emptied slot fails visibly rather than running with a more powerful key than intended.

The operations that need more capability than a write-only key never send one at all: a node-side **download** receives a presigned URL for the one object it names, signed on the management node with the main credential; a **cloud delete** runs on the management node itself, in-process. So the main (delete-capable) credential never travels to a node in any form.

### Transient Failures

A storage provider that answers a request with a 5xx does not fail the job. `S3Signer::request()` retries — `MAX_ATTEMPTS` tries, exponential backoff with jitter — for the failures that are worth another go: 5xx, 429, 408, and the transport errors that mean the connection died rather than the request being wrong. A deterministic error (403 signature, 404, 400) is returned immediately; retrying it would only burn the budget and bury the message. `S3Signer::is_retryable()` is the whole policy and is pure, so the classification is testable without a network.

Retrying is safe because every request the class makes is idempotent: a PUT overwrites its key (a single PUT, never multipart, so no orphaned parts survive a failure), and GET/DELETE/list have no cumulative effect.

Two bounds keep a retry from doing harm:

- **Wall clock.** Total time is capped at one attempt's timeout plus `RETRY_WINDOW_SECONDS`. An attempt that burns the entire transfer timeout leaves no room for another — the right answer, since a transfer that cannot finish in an hour will not finish on the second try. Job steps that shell out to the uploader take their own `timeout` from `S3Signer::transfer_budget_seconds()`, so the agent can never kill a transfer part-way through a retry.
- **Replay.** A retried upload rewinds the body stream before resending. curl consumes the stream on the first attempt while `CURLOPT_INFILESIZE` still claims the full length, so a retry without the rewind sends nothing and then blocks until the timeout — a hang rather than an error. A stream that cannot seek is not retried at all, rather than being sent truncated.

Each retry is named in the job output (`RETRY: attempt 1 failed (HTTP 500 internal incident); retrying in 2s`), and a transfer that only succeeded on a later attempt says so. A provider that is degrading looks exactly like a healthy one unless the attempts are visible.

### Backup Browser

The **Backups** tab on each node includes a file browser that lists backup files from both local storage and the cloud target. Features:

- **Scan for Backups** — creates a `list_backups` job to scan local `/backups/` on the node
- **Unified file table** — shows filename, size, date, and location (Local / Cloud / Both)
- **Upload to cloud** — offered on rows that exist only on the node, when the node has an enabled cloud target. Creates an `upload_backup` job that pushes that one file from the node to the target. The transfer runs on the node, where the file already is; routing it through the management node would drag the archive down and push it straight back up. The local copy is kept regardless of the node's delete-after-upload setting — an operator asking for an offsite copy of a file they are looking at did not ask for that file to disappear, and deleting stays an explicit action. The button waits for the job's real verdict, so a failed transfer reports as failed with a link to the job output rather than reading as done
- **Delete** — single Delete button per row that removes the file from every location it exists in (local, cloud, or both); the confirmation dialog names the file and locations explicitly
- **Restore Full Project** — for `.tar.gz` archives, see the `restore_project` row in the Job Types table
- **Restore points (incremental chains)** — a second table listing each chain on the node's shelf with its runs, size and newest restore point, read from the chain's own `manifest.json` by `BackupChainListHelper`. Restoring picks a run: the full, then every incremental up to it, in order. Chain artifacts are deliberately absent from the flat file table above — listed there, `files-0003.tar.gz.enc` invites a restore of one incremental with no full under it, which restores nothing at all

### What a restore asks, and what it decides

Every restore form asks one thing and decides the rest.

**It asks for the domain**, pre-filled from the node's recorded URL. This is the one value a restore cannot work out for itself: a rebuild keeps the site's own domain and cuts DNS afterwards, while a rehearsal must not claim it, and the same backup on the same node wants opposite answers. A node provisioned during an incident carries whatever hostname somebody typed in a hurry, so adopting it silently is a mistake that surfaces only after DNS moves.

**It decides the serving config.** There is no Apache choice on the form. The restore regenerates the virtualhost for this machine from the platform's own templates and never installs the one the backup carries; a differing capture is preserved as `{site}.conf.from-backup` and named in the job output. On a container node a further step publishes the domain on the **host** with `manage_domain.sh`, because the host's proxy virtualhost is outside the container and therefore in no backup.

Every restore job ends with two gates: the site's identity must match the machine (domain, deployment shape, and a database that opens with this machine's credentials) and the site must actually be served — over **HTTPS** when the domain already resolves here, or reported as certificate-deferred when it does not. The HTTPS gate is explicit because an HTTP-only check passes comfortably while a site serves under a container's internal virtualhost with a valid certificate sitting unused on disk.

What gets reconciled, and why each item is on the list, is in [Backups](../../../docs/backups.md#what-a-restore-reconciles).

Cloud listings are fetched live via `TargetLister` on every page render (one SigV4 HTTP GET, ~200–500ms). The local listing comes from the most recent completed `list_backups` job; both the Backups and Database tabs auto-trigger a refresh on page load when that scan is more than 60 seconds stale, so the listing is effectively always current. Both the merge logic and the staleness window are owned by `BackupListHelper::get_for_node()`.

### Stored Backups (target-side)

The **Backup Targets** edit page has a **Stored Backups** panel that lists the target's objects directly from the bucket and groups them by site. It runs entirely on the management node via `TargetBackups` (which lists through `S3Signer::list`, a continuation-token-paged ListObjectsV2), so it needs no live node — the authoritative view of what is actually stored offsite. Each group is tagged against the node table:

- **live** — a current node owns the slug; a link jumps to that node's Backups tab for granular local+cloud management
- **decommissioned** — a soft-deleted node owned the slug; the site is gone but its offsite backups remain here, reachable and deletable
- **orphaned** — no node, present or deleted, matches the slug

Delete acts through `S3Signer` from the management node: a single object (guarded so the key must sit under the target's own prefix), or a whole site's prefix (type-to-confirm the slug). This is the deliberate path for erasing a retired site's offsite backups — deleting a node never touches them.

## Retiring a node

Two distinct actions on the node detail Overview tab, both permission-10 and CSRF-guarded:

- **Remove from Dashboard** — soft-deletes the node record only. The site keeps running on its host; Server Manager simply stops tracking it. For a box handed back to its owner or managed elsewhere.
- **Permanently Delete Site** — creates a `decommission_node` job that ships `remove_account.sh` to the host, runs it (`-y`), and re-probes to confirm the container, its `{site}_*` volumes, and the reverse-proxy vhost are all gone (`DECOMMISSION_VERIFIED`). Only on that verification does the result processor soft-delete the node record; a failed or unverified teardown leaves the node intact and enabled to retry. Type-to-confirm the site name; the name is derived from the node's own fields, never operator input. Relays are refused (they tear down through the relay flow).

The record is soft-deleted, not hard-deleted, on purpose: the container port stays reserved on shared hosts, and the job history stays joinable. A decommissioned site's offsite backups stay readable regardless — each carries its own key sealed to the recovery key — and are not purged by decommission; delete them deliberately from the Stored Backups panel above.

Removed sites are hidden from the dashboard by default. The **Show all sites (including removed)** link at the bottom of the Hosts & Sites panel re-renders with them included, each carrying a **Removed** badge and linking into its still-reachable node detail page (`?show_all=1`).

Opening a removed node's detail page offers two follow-up actions in its Danger Zone:

- **Permanently Delete Site** — the same `decommission_node` host teardown, for a node that was only removed from the dashboard while its site kept running (e.g. an orphaned container). For a removed node it is offered only when this management node once saw a live site there — a recorded status check, Joinery version, or uptime result. With no such evidence (for example an install that failed and never stood a site up) the action is hidden behind a short note and only **Permanently Delete Entry** is offered, since there is nothing on the host to tear down. (The page cannot probe the host directly — the web user holds no host SSH key — so this uses evidence already on the record; the `decommission_node` job itself is idempotent and reports `REMOVE_ACCOUNT_NOTHING` if it reaches a host with nothing to remove.)
- **Permanently Delete Entry** — hard-deletes the Server Manager record itself (`purge_node`). Offered only for an already-removed node — purging a still-tracked node is refused, since that is how a live site becomes an untracked orphan. It is also refused while the node's slug still has offsite backups on any enabled target (or while a target cannot be listed to confirm): deleting the record would orphan those backups from the node they belong to, so they must be cleared from the target's Stored Backups panel first. Once allowed, the host is not touched and the job history survives the purge (cascade rules null the references).

## Backup Encryption and Key Custody

### Default Behavior

Encryption is **enabled by default** on both Database Backup and Full Project Backup forms. `backup_database.sh` / `backup_project.sh` encrypt with AES-256-CBC (PBKDF2, random salt) using the key minted for that run and passed as `--key-file`. The project archive is encrypted as tar streams into openssl, so the plaintext archive never lands on disk; the artifact is `.tar.gz.enc`. When a node's backup target is Backblaze B2 encryption is **mandatory**: the UI replaces the checkbox with a message and the server enforces it regardless of form input.

### Key model: one envelope per backup

Every backup run mints its own random encryption key. The archive is encrypted
with it, and the key itself is sealed to two recipients and written beside the
archive as a JSON envelope (`{archive}.keys.json`), which is uploaded with it:

- **recovery** — the recovery public key **the node itself holds and has
  verified**, read on the node. The private half lives in a password manager,
  held by whoever administers that node, and never touches a server. An operator
  who administers several sites may configure the same public key on all of them,
  and then one private key opens all of theirs; that is their arrangement to
  make, on each site, and not something this management node can impose from here.
- **site** — a keypair the node itself holds at `config/backup_site_key`. This is
  what lets a site restore itself with nobody present: pre-restore rollback
  snapshots and routine restores need no operator. It is disposable — lose it and
  the recovery key still opens everything, and the next run mints a new one.

Nothing on a node is precious as a result. Losing a node, or its whole disk, costs
no ability to read any backup it ever made, so there is no per-node key to track,
seal, or reconcile.

**No key is ever sent to a node.** Sealing to a public key always appears to
succeed, so a key supplied over the wire would let whoever supplied it decide who
can open a node's database and mail, with nothing on any machine looking wrong
until a restore was attempted. Every backup job therefore carries no key
material, and `backup_envelope.php mint` refuses one if a job passes it anyway.
A node with no verified recovery key of its own is refused a backup, loudly, at
build time and again on the node — never quietly downgraded to an unencrypted
archive on somebody else's shelf.

- The recovery keypair is generated with
  `maintenance_scripts/sysadmin_tools/escrow_keypair.php` (standalone PHP + sodium,
  no platform bootstrap, so it runs during disaster recovery when the platform is
  gone), or in the browser from the setup panel.
- The **public** key is stored in the core `backup_recovery_public_key` setting.
- Minting and sealing happen **on the node**, in
  `maintenance_scripts/sysadmin_tools/backup_envelope.php`. Only the recovery
  *public* key travels in the job step, so a `ManagementJob` row — which persists
  forever — carries nothing that can open anything.
- The plaintext key exists only as a 0600 file for the length of the run and is
  shredded by the step that seals the envelope to the finished archive.

`config/backup_site_key` is pinned to `600 www-data:www-data` by
`fix_permissions.sh`. A key that exists but cannot be read is an error, never
treated as absent — minting over a live key would orphan the site recipient for
every backup already sealed to the first one.

### Possession check

Sealing to a public key always appears to succeed, including when the pasted key
is wrong — every backup would then be permanently unopenable, discovered only
during a real recovery. So the key is honored only after the operator unseals a
challenge with the private key. Until that proof is recorded
(`backup_recovery_public_key_proven_fpr`), `BackupRecoveryKey::public_key()`
throws and encrypted backups refuse to run.

The check runs against the copy of the key the operator is actually keeping,
which is the copy that has to work in a disaster. Two ways to do it, both proving
possession of the same X25519 secret:

- **In the page** — paste the key (from a password manager, typically) into the
  setup panel. `BackupRecoveryKey::browser_challenge()` packages the proof string
  as `ephemeralPub[32] || iv[12] || ciphertext || tag`, sealed with X25519 →
  HKDF-SHA256 (info `BackupRecoveryKey::BROWSER_INFO` + ephemeral public +
  recipient public) → AES-256-GCM, so `backup_key_verify.js` and
  `assets/js/recovery-readiness.js` open it with WebCrypto alone. The HKDF context
  is sent to the browser with the challenge rather than hardcoded at both ends.
  The key is read from an input outside the form, used in memory, and cleared; it
  is never submitted, stored, or sent anywhere. Only the recovered proof string is
  posted, and the server re-checks it.
- **At the command line** — `escrow_keypair.php unseal` opens the libsodium
  sealed-box form of the same challenge with a key file.

What the challenge contains is a plain sentence ending in the key's full sha256
fingerprint — readable, so recovering it is self-evidently a success, and bound,
so a proof earned for one key can never satisfy another. It is ASCII with no
timestamp or randomness, because it is compared byte for byte after a copy-paste
through a terminal.

Replacing a proven key is a rotation, not an edit: backups already made carry
keys sealed to the old public key. Pasting over a proven value is refused.

### Guided setup

Recovery key setup is core, not fleet — a standalone site needs it just as much —
so it lives on the Backups page and is rendered by
`includes/RecoveryKeySetupPanel.php` (see
[Backups](../../../docs/backups.md#recovery-key-setup)). The Backup Targets page
shows the current state and links there rather than carrying a second copy of
the panel. `BackupRecoveryKey::setup_state()` is the single source of truth for
that state, so the panel, the node Backups tab, and the dashboard cannot
disagree.

That panel covers this management node's own site. Whether a **node** can be backed
up is a question about the node's key, answered by `RecoveryKeyFleet::node_state()`
from the last status check: a node whose key is missing, unverified or not yet
checked shows the explanation in place of the Run Backup forms, and the job
builder refuses to build a run for it, so an operator is told while looking at
the button rather than part-way through a backup. `NodeMonitorHealth::fleet_backup_health()`
leads with the same state, without a grace period — a node that cannot encrypt is
not a node whose backups are late.

### Backups across the fleet

This management node takes its own backups of the nodes it manages. They are a
separate party's copies of each site, on this management node's shelf — the
`manager` profile described in
[Backups](../../../docs/backups.md#two-parties-two-profiles). A site's own
backups are the `site` profile: its own schedule, its own business.

Neither owns the other. A site that takes no copies of its own is still backed up
from here; a site that takes plenty is still backed up from here. Nothing on
either side needs the other to be absent.

**Both open with the node's key.** The two profiles differ in who schedules them,
where the archive lands and who prunes it — not in who can read it. That belongs
to the node's administrator in both cases, which is what makes a compromise of
this management node a metadata problem rather than a fleet-wide disclosure.

**The node does the work.** `backup_run` hands it the bucket and a credential on
stdin, and its own `BackupRunner` builds the archive, extends the chain, seals the
envelope to the node's own verified recovery key, uploads and sweeps its local
copies. Routing archives through the management node would drag every byte down and
push it back up, and would put this machine in the path of every restore.

**Nothing is left on the node, and nothing is given to it.** The credential is
substituted into the step by the agent at run time and never written to a job row
or a node's database, and it leaves with the run. No encryption key goes the other
way: a run that arrives carrying key material is refused rather than obeyed, so a
management node that had been tampered with cannot re-seal the fleet's next backups
to a key of its choosing. A node holds no key to anyone's backups but its own, and
a node that leaves this fleet takes nothing with it.

#### The node may write to the shelf but never erase it

A backup target holds two credential slots. The main credential
(`bkt_credentials`) is the management node's own — it lists, prunes and downloads.
The **node credential** (`bkt_node_credentials`, on the target edit form) is an
optional second key created **write-only** — `writeFiles` without `deleteFiles`
on B2, `s3:PutObject` without `s3:DeleteObject` on S3. When it is set, that is
the key nodes are handed during a run: a node can add its archives and remove
nothing. When no node credential is configured, nodes receive the main key —
functional, but a compromised node then briefly holds a key that could erase
the shelf, so a fleet target wants the node slot filled.

`FleetBackupRetention` prunes from here, with the delete-capable main
credential that never leaves this machine. A credential that can delete is a
credential that can erase the fleet's backups, which is the first move of any
ransomware worth the name and the exact thing these copies exist to survive.

Pruning is driven by a bucket **listing**, which is the opposite of what a site
does for its own backups, and correct only here: this management node defined the
whole `{prefix}/{slug}/manager/` path, knows every slug under it, and is the only
party that can delete from it. It is also stricter — it keeps the newest N sets
of objects that actually exist, so a run that failed part-way can never be
counted as a restore point. Chains are grouped by their directory, so they are
kept or deleted whole by construction.

Two provider notes:

- Linode Object Storage keys are read-only or read-write per bucket with no
  separate delete capability, so write-without-delete cannot be expressed there.
  B2 and S3 both express it cleanly.
- A chain rewrites `manifest.json` every run. That is a PUT over an existing key,
  which write-only permits, but on B2 it leaves superseded versions the node
  cannot remove. Give the fleet bucket a lifecycle rule keeping only the current
  version.

#### Scheduling

The **Fleet Backups** task (`plugins/server_manager/tasks/FleetBackupRun.php`)
runs every cron tick, finds due nodes, prunes each one's shelf, and dispatches
one `backup_run` per node.

`FleetBackupPolicy` resolves each node's schedule: the declared fleet settings,
then that node's own `mgn_backup_policy` overrides. **The fleet default is
enabled.** That default is what stops a newly managed node being forgotten —
there is deliberately no detector for "nobody has decided about this node",
because a node nobody decided about is backed up anyway.

The node detail Backups tab edits the policy, as one of three positions:

- **Fleet default** stores nothing, so the node follows the fleet settings —
  including future changes to them.
- **A schedule of its own** stores the full field set (frequency, window, mode,
  retention, full interval), frozen against the fleet default: a value the
  operator saw and saved is a value they chose.
- **Off** stores exactly that decision, which is what lets the dashboard treat
  a node without fleet backups as somebody's choice rather than a gap.

The tab's **Run backup now** dispatches the same `backup_run` the schedule
dispatches, with mode and full-interval taken from the node's policy, so a
manual run extends the same family of restore points the schedule builds.

Three rules keep a fleet from behaving like a thundering herd:

- each node's minute is derived from its slug and spread across a window
  (default 03:00 UTC, 120 minutes wide), so forty nodes do not all begin a
  multi-hundred-megabyte upload at once;
- a node whose previous run is still pending or running is skipped, so a slow
  node gets fewer backups rather than a queue;
- no more than `server_manager_fleet_backup_max_concurrent` run at once.

Due is keyed on when the last run was *started*, not on whether it succeeded.
Retrying a failing node every fifteen minutes until its next slot would hammer a
machine that is already unwell.

#### What is reported, and what raises an alarm

`check_status` asks each node's management API for both profiles: whether each is
scheduled, when it last ran, how it went, whether it reached the bucket, and
which recovery key it sealed to. The manager profile's answer is denormalised
onto `mgn_last_backup_time` and `mgn_last_backup_outcome` so the dashboard reads
columns instead of visiting nodes.

**The dashboard alarms only on this management node's own runs** —
`NodeMonitorHealth::fleet_backup_problems()` raises a node whose last backup from
here failed, or whose backups have stopped arriving within its schedule's window.
The alarm is "my backups of this node are broken", not "this node is
unprotected", which is not this management node's call to make.

**The node's word is cross-checked against the bucket.** The retention pass
lists each node's shelf with this management node's own credential before every
run, and the scheduler stamps what it saw — when the shelf was listed and the
newest object write on it — onto `mgn_backup_shelf_checked_time` and
`mgn_backup_shelf_newest_time`. The health check compares that against the
node's claimed last run: a shelf listed after a claimed success that holds
nothing written since raises **"Backups are not landing"**. The shelf is the
one witness a compromised or misconfigured node cannot talk into its story —
everything else in the health picture is the node reporting on itself.

A node with fleet backups switched off produces nothing either — that was
somebody's decision.

#### Which key each node holds, and whether it can be backed up

`set_recovery_key.php --report` is asked during `check_status`, and the answer
lands on `mgn_backup_recovery_fpr` and `backup_recovery_state`. It prints one
machine-readable line, `RECOVERY_KEY=already|none|invalid`, and the fleet table
on the Targets page reads the columns rather than reaching out to every node on
page load.

That state decides whether the node can be backed up at all, by anyone — the
Targets page lists it as fleet coverage, and `RecoveryKeyFleet::has_own_key()` is
the one predicate every surface asks. Whose key it is is not compared against
this management node's: a node holding a key this machine has never seen is a node
whose operator holds their own recovery key, which is the intended arrangement.

It is **reported and never written**. There is no job type that can write it, and
`set_recovery_key.php` refuses `--public` outright so a stale management node finds
out rather than succeeding. A node's key is set up on that node's own Backups
page, with the possession ceremony that makes it trustworthy — the page generates
a keypair in the browser and runs the challenge in one pass.

### Disaster recovery

To rebuild a lost node from its offsite backups:

1. Fetch the archive and its envelope (`{archive}.keys.json`) from the bucket.
2. Recover the archive key on a machine holding the recovery private key:
   `php backup_envelope.php open --sidecar {archive}.keys.json --private /path/to/recovery.key --key-out /tmp/k`
3. Restore through the dashboard, or with `restore_database.sh --key-file /tmp/k`
   / `restore_project.sh --key-file /tmp/k`.

On the node itself no key is needed: `restore_project.sh` finds the envelope beside
the archive and opens it with `config/backup_site_key`.

This works when the management node itself is the casualty — the envelopes sit in the
bucket alongside the archives, so bucket credentials plus the password-manager
private key are sufficient. No site's recovery depends on any other site being
alive.

The **agent signing key** (the fleet trust root) needs no separate recovery record:
it lives at `config/agent_signing_key`, inside the project tree that the site's own
encrypted project backup carries.

## How It Works: Smart Plugin, Dumb Agent

All job-type intelligence lives in `JobCommandBuilder.php`. The Go agent is a generic executor that understands four primitives: `ssh`, `scp`, `local`, and `api`.

**When an admin triggers an operation:**

1. PHP looks up the node's connection details (host, SSH key, container, etc.)
2. `JobCommandBuilder::build_<type>()` generates an ordered array of steps
3. PHP writes a job row with the steps in `mjb_commands` (JSON)
4. Go agent picks up the job, executes each main step in order, streams output
5. Agent runs the job's teardown steps (if any), then marks the job completed or failed
6. `JobResultProcessor` optionally parses the output into structured data

**Example: what a step-list job looks like in the database:**

```json
{
    "steps": [
        {"type": "ssh", "label": "Prepare the workspace", "cmd": "mkdir -p ..."},
        {"type": "ssh", "label": "Run the operation", "cmd": "...", "continue_on_error": true}
    ]
}
```

The agent doesn't know this is a "backup." It just runs each step's command, captures output, and moves on.

### Not every job is a step list

Two job shapes carry no commands for anything to execute.

A **primitive** job names an operation the node's own agent compiled in
(`{"primitive": "check_status", "params": {}}`). The plane composes nothing; the
node looks the name up in its own vocabulary and refuses anything it does not
recognise.

A **probe** job (`{"probe": "check_status"}`) is work this plane does itself, and
it is already finished by the time the row exists. `NodeHealthProbe` reads what
the machine publishes about itself over HTTP, or establishes that it answers on
its port, then folds the figures onto the node and writes the row in a terminal
state. This is how a machine that carries no agent and hosts no site is asked
about itself: the ScrollDaddy DNS servers report their own disk and memory in
their `/health` document, and the mail relay proves it is alive by accepting
connections on port 25, which is what it exists to do.

`ManagementJob::createFromBuild()` reads which shape a builder returned and
stores it correctly, so a caller dispatches an operation and never chooses a
transport.

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
| `local_path` | scp/api | File path on the management node (for `api`, set to stream the response body to a file instead of appending to job output — used by `backups/fetch`) |
| `method` | api | HTTP method: `GET`, `POST`, `PUT`, `DELETE` (in practice always `GET` — the management API is read-only) |
| `endpoint` | api | Path relative to `/api/v1/management/` — e.g. `stats`, `backups/list`, `backups/fetch` |
| `expect_status` | api | HTTP status code that counts as success (default 200) |
| `query` | api | Object of query-string params (e.g. `{"path": "/backups/foo.sql.gz"}`) |
| `body` | api | Request body object (serialized as JSON; ignored for GET/DELETE) |
| `continue_on_error` | No | If `true`, don't abort the job when this step fails |
| `timeout` | No | Max seconds for this step (default: 1800 = 30 minutes; teardown steps carry 120) |
| `teardown` | No | If `true`, the step is teardown-phase: it runs on every exit path, its failure never affects the job outcome, and it must be an idempotent removal of a per-job scratch path. Always placed at the tail of the step array with `continue_on_error` set. |

## Management API (Read-Only)

Every Joinery instance exposes a namespaced read-only HTTP surface at `/api/v1/management/*`. The management node prefers this over SSH for observability operations (`check_status`, `list_backups`) because it's faster, parallelizable, and auditable.

**Endpoints** (all under `/api/v1/management/`, all `GET`, all JSON except `backups/fetch` which streams binary):

| Endpoint | Replaces SSH step(s) |
|----------|----------------------|
| `health` | (new — liveness probe) |
| `stats` | all steps of `check_status` |
| `version` | `Check Joinery version` |
| `databases` | `List databases` |
| `errors/recent` | `Recent errors` |
| `backups/list` | `list_backups` |
| `backups/fetch?path=...` | (no management-node consumer — streams a backup file as binary) |

Discovery: `GET /api/v1/management` returns every endpoint with its description.

**Authentication** uses the existing API key system (`apk_api_keys` — same key headers and hashing as public CRUD; resolved by `ApiAuth::authenticate()`). The gate (`ApiAuth::authorize()`, with `requires_machine_key + min_user_permission: 10`) has **two requirements**: the key must be a **machine key** (`apk_type = machine`) — user session keys minted via `/api/v1/auth/login` get 403 here, so a superadmin logging into a phone app can't reach the management node — **and** its owning user must be a superadmin (`usr_permission >= 10`). `apk_permission` is NOT a gate here — it's the CRUD-axis capability and is orthogonal. A superadmin's machine key with `apk_permission=1` can call management endpoints; a permission-5 admin's key cannot, regardless of `apk_permission`.

**Adding a management key for a node:** on the target node, Admin → API Keys → New Key (admin-created keys are machine keys, which is what the management node requires), owner = a superadmin user, `apk_permission = 1`, IP-restrict to the management node's egress IP. Paste the public/secret pair into the node's Overview tab on the management node's Server Manager ("API Credential" panel).

> **IP restriction on docker-prod nodes:** for sites fronted directly by host Apache (no Cloudflare), the container now reads the real client IP via `mod_remoteip` + the host's `X-Forwarded-For: %{REMOTE_ADDR}s` header, so IP restriction works end-to-end. For Cloudflare-fronted sites, the container sees Cloudflare's edge IP — IP restriction is not yet meaningful in that case (a future spec will trust Cloudflare's ranges and read `CF-Connecting-IP`).

**Build-time routing:** `JobCommandBuilder::build_<op>()` picks one implementation in preference order — `build_<op>_primitive()`, then `build_<op>_api()`, then `build_<op>_probe()`, then `build_<op>_ssh()` — and an operation with none it can reach throws rather than emitting an empty job. The API arm is gated on `has_api($node, $op)`: credentials stored on the node row, a matching `build_<op>_api`, and a fresh `/health` probe. There is no runtime fallback; a job is decided at build time and runs that path or fails. `check_status` has no `_ssh` implementation at all.

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

### RegisteredDomain (`rdm_registered_domains`)

One domain bought on a buyer's behalf. Two independent axes run along the row
and must not be conflated: `rdm_status` is fulfillment (`pending` →
`registered` → `active`, or `failed`), and `rdm_graduation_state` is custody
(`operator_managed` → `push_requested` → `push_sent` → `self_custody`). Legal
ownership belongs to neither — the buyer is the registrant from registration.

- `rdm_domain` -- the name, lowercase and unique
- `rdm_usr_user_id` -- the buyer; deletion is refused while a domain is theirs
- `rdm_external_order_item_id` -- the order item both this and the compute leg
  hang off, and the intake's idempotency key
- `rdm_mgn_node_id` -- the box, resolved during fulfillment
- `rdm_registrant_sealed` -- the WHOIS contact block, SecretBox-sealed
- `rdm_dns_bootstrap_time` / `rdm_dns_mail_time` / `rdm_ptr_time` -- the
  idempotency ledger: null means outstanding, stamped means never redone
- `rdm_expiry_time`, `rdm_expiry_checked_time`, `rdm_prompt_pushed_time` --
  the countdown, its weekly refresh, and whether the buyer has been told

### ManagementJob (`mjb_management_jobs`)

Represents a queued, running, or completed operation. Key fields:

- `mjb_mgn_node_id` -- Target node (FK to mgn_managed_nodes, null for local-only jobs)
- `mjb_job_type` -- Label for display/filtering (e.g., "backup_run")
- `mjb_status` -- `pending`, `running`, `completed`, `failed`, or `cancelled`
- `mjb_commands` -- JSON with the step array the agent executes
- `mjb_output` -- Progressive text output (appended during execution)
- `mjb_result` -- Structured JSON populated by `JobResultProcessor` after completion
- `mjb_current_step` / `mjb_total_steps` -- Progress tracking

Create jobs with the static helper:

```php
$job = ManagementJob::createJob(
    $node_id,               // target node ID (or null for local)
    'backup_run',           // job type label
    $steps,                 // array of step dicts from JobCommandBuilder
    ['profile' => 'manager'], // parameters (stored for reference/re-run)
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
- `bkt_node_credentials` -- optional write-only key handed to nodes during a backup run in place of the main one; same shape, same sealing; B2/S3 only
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
   - **Inconclusive**: the probe records why in `mgn_uptime_last_error` and returns without touching status, the failure counter or `down_since`, and without alerting. `mgn_uptime_last_conclusive` is deliberately left alone, so a node that can never conclude eventually surfaces as stale rather than as healthy.

Constants on the class: `TIMEOUT_SECONDS=10`, `FAILURE_THRESHOLD=2`. The cron tick interval (~15 min) is the natural rate limiter.

**A probe only concludes when it reached the node.** A failure inside the monitoring host's own name resolution is evidence about the monitoring host, not about the node, so it is inconclusive. Without this, one broken resolver on the management node fails every probe within a single tick, carries the whole fleet past the failure threshold together, and mails the operator that every site is down while every site is serving traffic — an inverted signal, since the one machine actually at fault is the only one reporting nothing wrong.

`NodeMonitorHealth::is_name_resolution_failure($errno, $message)` makes the call, and all three check types route through it. It matches curl's `CURLE_COULDNT_RESOLVE_HOST`/`_PROXY` by number, and matches on message text for the two cases that carry no distinguishing number: a resolver that hangs rather than answering (curl reports the generic `CURLE_OPERATION_TIMEDOUT`, wording it "Resolving timed out after…"), and `fsockopen`, which reports getaddrinfo's text with errno 0. Everything else — refused connections, TLS failures, timeouts once dialling has begun — stays a genuine down result.

The recorded error is worded from the monitoring host's point of view (`monitoring host could not resolve <name> (…)`) so the dashboard points at the real fault, and the tick's summary line counts these as skipped with the reason attached.

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

7. **Remote credentials at runtime** -- Database credentials for backup/copy/restore are extracted from each node's `Globalvars_site.php` at execution time, never stored on the management node.

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
| `data/registered_domains_class.php` | RegisteredDomain + MultiRegisteredDomain |
| `includes/domain_registrar/DomainRegistrarProvider.php` | The registrar seam + `DomainRegistrarException` (transient vs terminal) |
| `includes/domain_registrar/DomainRegistrarRegistry.php` | Interface-based registrar discovery, plus the shared domain-name and TLD gates |
| `includes/domain_registrar/NamecheapRegistrar.php` | Namecheap: availability, pricing, registration, WHOIS privacy, expiry, custody probe |
| `includes/requirements/ManagedDomainRequirement.php` | The checkout field, its live quote, the companion cart line, and the intake |
| `includes/provisioning/ProvisionManagedDomains.php` | Register → web DNS → mail DNS → PTR → active |
| `includes/provisioning/ManagedDomainWatch.php` | Expiry refresh, the six-month prompt, custody detection, the node banner push |
| `logic/domain_check_logic.php` | `/api/v1/action/server_manager/domain_check` — live availability for the checkout field |
| `includes/JobCommandBuilder.php` | Command generation for all job types; `ssh_prefix()` is public for use by other tools |
| `includes/JobResultProcessor.php` | Parses completed job output into structured data |
| `includes/S3Signer.php` | AWS SigV4 signer for S3-compatible storage (get/put/delete) |
| `includes/TargetLister.php` | Web-tier paginated bucket listing using S3Signer |
| `includes/TargetTester.php` | Connection test on Save for Backup Targets |
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
| `views/admin/domains.php` | Managed domain queue -- pending pushes, failures, the full ledger |
| `views/profile/domain.php` | The buyer's take-ownership flow (`/profile/server_manager/domain`) |
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
