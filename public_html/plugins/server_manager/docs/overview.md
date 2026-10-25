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

- `manifest.json` — agent version, the agent commit the binaries were built from (`source_commit`), and per architecture the artifact filename, its sha256, and an Ed25519 signature over the raw binary
- `joinery-agent-linux-amd64.gz` / `joinery-agent-linux-arm64.gz` — the binaries
- `joinery-agent.service` — the systemd unit

On the publishing management node, `publish_upgrade.php` cross-compiles both architectures from the checkout named by the `server_manager_agent_source_path` setting (default `/home/user1/joinery-agent`) whenever the source version differs from the bundled one, and signs them with the key at `{site root}/config/agent_signing_key` (generated on first publish; the `.pub` sibling holds the base64 public key that gets baked into the built agent). The key is `600 root:root`, pinned by `fix_permissions.sh`: a publish is a job of the management node's own agent, which runs as root, so root is the key's only reader and an operator login on the box cannot copy it. A publish that runs as root gives every file it creates the owner and group of that file's parent directory before it exits, so nothing root-owned is left in the tree.

Bundling is the first thing a publish does, before the VERSION file, the archives or the release row, because its outcome decides whether the release happens at all:

- **No agent source on this box** — the existing artifact carries forward unchanged and the publish proceeds. Publishing never depends on a Go toolchain being present.
- **Source version matches the bundle, built from the source's HEAD** — `agent_dist` is left byte-identical. It sits outside every plugin tree, so a rebuild changes no plugin's tree hash and bumps no plugin's version.
- **Source version matches the bundle, built from an earlier commit** — the source is rebuilt with the bundle's recorded inputs (version, signing key, `baked_keys`) and compared. The same bytes record HEAD as `source_commit`; different bytes refuse the publish until the version in `main.go` is raised. The release statement names HEAD as the agent commit, and `utils/verify_release.php` rebuilds the binaries from it, so they must be HEAD's.
- **Source is newer and the rebuild succeeds** — the fresh artifact is bundled, replacing the agent's own files only (`AgentDistPublisher::ownFile()`: the binaries, the unit, `manifest.json`); the support bundle and the release statement beside them go on into the new `agent_dist`, and because this happens before plugin archives are built it is captured in the `server_manager` archive and its tree hash.
- **Source is newer and the rebuild fails** — the publish is refused. The build error is printed, and the VERSION file, archives and release row are all left untouched. Shipping here would mean releasing an agent the publisher already knows is out of date, and the resulting fleet has no way to tell.

The last line of a publish names the agent version the release carries. `plugins/server_manager/tests/agent_bundle_drift_test.php` asserts the same invariant on its own, so a bundle that falls behind its source is caught by the safe test tier rather than by the next release.

The converger has a second job: it is the **root actor** that carries out root
requests. The code tree belongs to root and the web server cannot write it, so
an upgrade, a plugin or theme install, a docs save — anything that writes code —
is queued by the page as a named request and run by the converger as root, with
its transcript polled back onto the page that asked. See
`docs/deploy_and_upgrade.md`. On a managed node the agent does the same work
through its own jobs; the converger is what a self-hosted box has instead.

**First install** is handled by the core installer `maintenance_scripts/install_tools/install_agent.sh`, which runs at every root moment — site install, code upgrade, container start, the node-detail **Run Plugin Installers** action, and the host converger's timer on a box whose upgrades run from the browser (`docs/deploy_and_upgrade.md`). It installs the bundled binary, writes the env file with the right `JOINERY_CONFIG`, and sets up systemd or cron supervision automatically.

The installer is core rather than a plugin's, and runs on every Joinery instance: the agent does a machine's own backups, upgrades and health checks, and only a management node has `server_manager` turned on. The artifact stays in this plugin's tree because this plugin builds and signs it, and it reaches every node regardless — the plugin is `included_in_publish` and `receives_upgrades`, both independent of whether it is active there.

**The binary lands on every deployment.** Installing is not running: the artifact is converged at each root moment regardless of the switch, so a machine that is switched on later starts a service that is already there rather than fetching, decompressing and verifying one at that moment.

**Whether it runs is one setting, `agent_enabled`,** which ships off and is read fresh at each root moment: on starts the agent and sets up its supervision, off stops it and takes the supervision away — the cron keepalive included, or stopping would last a minute. The agent's identity survives an off, so turning it back on resumes the same pairing. A database the installer cannot reach leaves the machine untouched rather than being read as off.

Three ways to set it, all writing the same setting:

- the machine's own **Admin → System → Management Node** page, which also says what is still needed for it to take effect
- `php utils/agent_control.php --on` (also `--off`, `--join=URL`, `--leave`, `--status`) on the machine
- from a management node, the node detail **Agent Channel** panel's *Turn on the agent over SSH*, which switches it on, runs the installer, and has the node ask to join — the fleet path, available only while SSH is, and retired with it at the Phase 3 cutover
- `install.sh --enable-agent` at install time, with `--management-node=URL` to have that agent ask to join the named plane in the same step. A site this management node provisions passes both automatically, so a node it builds comes up running its agent and asking to join — the one case where whether the machine should run one is already answered. The join is still a request an operator approves here

None of those enroll anything. A join is a request; approving it here after comparing key fingerprints is what binds a node, unchanged.

A web request has no root, so flipping the setting does not itself start the agent — the next container start or upgrade does, or `sudo bash {site root}/maintenance_scripts/install_tools/install_agent.sh {sitename}` does it immediately. The Management Node page says so, and prints that command when the switch is on and no binary has reached the machine yet.

**Every later version change is handled by the agent itself.** Between jobs, the agent compares its own version with the bundled manifest. When they differ, it decompresses the artifact, checks the sha256, verifies the Ed25519 signature against the public key embedded in its binary, keeps the current binary as `.bak`, renames the new one into place, and exits cleanly for its supervisor to restart. The signature check is the security boundary: the site tree is writable by the web user while the agent runs as root, so the agent never installs anything the publisher did not sign. An artifact that fails verification is refused, logged under a `=== Self-update ===` header, and not retried until the manifest changes. The update check and the poll loop share one job lock, taken before a job is claimed: a process holding the lock is either running a job or about to exit for a swap, and in both cases the poll skips its tick, so the plane never hands a job to a process that will not live to report it.

**The public log.** An agent that holds a release statement key and a log checkpoint key installs only a binary whose release is in Sigstore's public log. Before fetching the binary it reads the release's `RELEASE_STATEMENT` — `agent_dist/RELEASE_STATEMENT` on a machine with a site tree, the artifact kind `agent_statement` over the channel otherwise — and checks it offline: the statement's signature against a statement key it holds, the log entry bound to that statement, the checkpoint against the log's key, the inclusion proof, and that the statement records this binary's sha256 under `agent/<platform>`. The checks are `releaselog.go`, the same four `includes/TransparencyProof.php` makes. A binary that fails any of them is refused with the update state `unlogged` and not retried until the release changes. The keys are written to the key files before the swap, each file replaced whole by a rename; if they cannot be written, the binary is not installed and the next check tries again. The keys held are the ones compiled into the binary (`-X main.releaseStatementKeysB64`, `-X main.releaseLogKeysB64`, set by `AgentDistPublisher` from `release_keys/` and recorded in the manifest as `baked_keys`) plus `/etc/joinery-agent/release_statement_keys` and `/etc/joinery-agent/transparency_log_keys`, which are read only when root-owned and not writable by group or others. The statement's `key_chain` is walked in memory from those keys; before a swap the install step appends every key held and every key the chain proved to the two files, and nothing removes them — so once a machine has held the keys, every later binary must be logged, including one built without keys. An agent holding no keys updates on the signature alone. On a site node the agent's requirement and the site's own (`config/release_log_required`) are separate stores and start at different moments: the site's when its converger first writes keys from a bundle, the agent's when it first runs a binary built with keys. The agent never reads the site's `config/` key files, because the tree's owner can write there on some machines, and a key added is trust added. Every field of a statement is read by its exact name, as PHP reads it, so the agent and the PHP readers never see different values in one statement.

Before the swap, the agent runs the verified new binary with `--version` and refuses one that does not answer with the version the manifest names (a binary built for another machine, or one that dies before `main`), as it refuses a bad signature. After the swap the new version is **on trial**: `.pending` beside the binary names it with the count of its starts. It is trusted, and the `.bak` deleted, once a process running it has kept running for three minutes (`trialUptime`). A start that finds the previous start of the same version still on trial (it died first: a crash, an out-of-memory kill) puts the `.bak` back, records the version in `.rejected` and exits, the supervisor starts the previous agent, and that version is never reinstalled; the next release supersedes the rejection. A deliberate stop during the trial (the operator's switch, a signal, a restart a job asked for, a move, a leave) starts the trial afresh rather than counting against it. No newer version is installed over one still on trial, so the `.bak` is always a binary the machine has seen run. Before every start, the systemd unit's `ExecStartPre` and the cron keepalive run the same start guard: while a swap is on trial with a `.bak` kept, a binary that cannot answer `--version` is replaced by the `.bak` and marked rejected. That covers a binary that never reaches the agent's own watchdog in `main`. The unit itself is converged from the release's `agent_dist` on a site, and on a machine with no site from the copy built into the binary (the same `install/joinery-agent.service`), which the agent puts back within a minute of it going missing or being edited.

The dashboard's Agent Status bar surfaces all of this from the heartbeat row (`ahb_bundled_version`, `ahb_update_state`): a pending update, a refused (verification-failed) artifact, a binary refused because its release is not in the public log (`unlogged`), a rolled-back version, or an agent built without an update key.

A paired agent also reports where its self-update stands on every poll (1.65.0 and later): its verdict on the version on offer (`update_state`, a closed set: `none` when nothing is on offer, `current`, `update_pending`, `fetch_failed`, `verify_failed`, `unlogged`, `version_rejected`, `unsigned_build`, `no_binary`) and that version (`update_offered`), stored as `mgn_agent_update_state` and `mgn_agent_update_offered`. It sends them only once a check has concluded since it started, and an absent report leaves the last one standing. A refusal raises the `plane:agent_update_refused` incident. A siteless machine holds its support bundle to the same bar: with the log keys, it installs a bundle only when the release statement in the public log records its bytes (artifact `support_bundle`), keeps the keys the statement proves first, and reports its verdict on every poll as `bundle_state` (`current`, `unlogged`, `verify_failed`), stored as `mgn_agent_bundle_state`; a refusal raises `plane:agent_bundle_refused`. Publish writes the binaries and the bundle before the statement that records them, so an `unlogged` verdict, on either, is reported only once it has stood 15 minutes; an unlogged bundle is tried again whenever the statement beside it changes.

#### Manual install (bootstrap fallback)

For a management node that has no bundled artifact yet, build and install by hand:

```bash
cd /home/user1/joinery-agent
PUBKEY=$(cat /var/www/html/joinerytest/config/agent_signing_key.pub) make release
sudo bash joinery-agent-installer.sh --verbose [--config /path/to/Globalvars_site.php]
```

`make release` compiles the binary (passing `PUBKEY` bakes in the update-verification key so the manual build can still self-update later; `STATEMENT_KEYS` and `LOG_KEYS` bake in the release-log keys, in the forms the publisher passes) and packages it into `joinery-agent-installer.sh`, a self-extracting script that handles both fresh installs and upgrades with automatic rollback if the new version fails to start.

The installer detects the host's supervision capability:

- **systemd hosts**: installs `/etc/systemd/system/joinery-agent.service`; start with `systemctl start joinery-agent`.
- **No systemd** (Docker containers, minimal hosts): installs `/usr/local/bin/joinery-agent-supervise` plus `/etc/cron.d/joinery-agent` (`@reboot` + a once-a-minute keepalive) and starts the agent immediately. Logs go to `/var/log/joinery-agent.log`.

**A hung agent is restarted, not only a dead one** (agent 1.89.0, `liveness.go`). Every half minute, while it is alive, the agent sends systemd `WATCHDOG=1` and writes the time to `/run/joinery-agent/alive`. It states the watchdog's timeout itself (`WATCHDOG_USEC`, three minutes); the unit only allows the messages (`NotifyAccess=main`) and carries no `WatchdogSec`, so a unit converged ahead of its binary never kills an older agent that sends nothing. The agent stops saying it is alive when its poll has not reached the management node for 20 minutes, or its self-update check has not come round for 20 minutes, while no word is running within its deadline (a word inside its deadline holds the job lock legitimately, up to a restore's four hours); or when any word is still running ten minutes past its own deadline. It logs why, once, with every goroutine's stack. systemd then aborts it and `Restart=always` starts it again; in a container the keepalive aborts an agent that has run for more than three minutes with a file older than three minutes, and starts it. A missing file (an agent older than the watch) is never read as a hang. `agent_report` (1.90.0) gives the file's age as `alive_age_seconds`, and the `agent_supervision` recipe fails when the agent answering it has a heartbeat older than three minutes or none at all: the agent is running, so its heartbeat is not being written and a hang would go unnoticed.

Both modes create:
- `/usr/local/bin/joinery-agent` — the binary
- `/etc/joinery-agent/joinery-agent.env` — configuration (from example, first install only)

`--config PATH` stamps `JOINERY_CONFIG` into the env file, pointing the agent at the right site without editing anything.

**Every management node needs a live agent.** Jobs sit `pending` until an agent claims them — the node's own paired agent for everything but `install_node`, which is the one bootstrap the plane runs itself (see [Customer-Cloud Fulfillment](#customer-cloud-fulfillment)). The **Server Manager → Provisioning** page shows an agent heartbeat badge as requirement #1.

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

#### From the node

A site this management node did not install is enrolled from the node's own **Admin → System → Management Node** page: the admin there enters this management node's URL, the node's agent generates a keypair and asks to join, and the request appears on the node's API keys tab here for approval after a fingerprint comparison. Add the node's record below first so the join has something to be approved against. The plane never needs a shell on someone else's machine — there is no discovery scan (see `specs/ssh_single_bootstrap.md`).

#### A machine this management node provisioned

A cloud instance created from Remote Install runs two agents on a docker box: the site's, inside the container, and the host's own, beside it. Both ask to join on their own, named `<slug>` and `<slug>-host`. The instance is known by both the addresses the provider reported for it (IPv4 and IPv6), so a join from either family is recognised as that machine. The site's join is approved on the provision's node, from its API Keys tab, where the provider is asked first to confirm the instance is running at the join's address. The host's join is approved from the dashboard banner: the same provider check runs, then the host node is made at the instance's IPv4 and named on the placement record, which is what routes host-scope work (certificates, site removal) to it.

Rejecting a join is reversible for a day. The dashboard lists joins rejected in the last day with a **Reopen** button; the machine keeps its key and asks again every five minutes, so a reopened request is answered by the same fingerprint the operator already compared and can then be approved like any other. `joinery-agent leave` on the machine is what discards the key.

Retiring the install password is proven by the machine refusing it. A retirement job that finds the machine already refusing the password before its script runs completes as retired: the refusal is the proof, so a record that still says "held" catches up when the job is re-run.

#### Manual

Fill in the form fields directly:

| Field | Example (Empowered Health) | Example (ScrollDaddy) |
|-------|---------------------------|----------------------|
| Display Name | Empowered Health Production | ScrollDaddy Production |
| Slug | empoweredhealthtn | scrolldaddy |
| SSH Host | 23.239.11.53 | 23.239.11.53 |
| SSH User | root | root |
| SSH Key Path | /home/user1/.ssh/id_ed25519 | /home/user1/.ssh/id_ed25519 |
| SSH Port | 22 | 22 |
| Docker Container | empoweredhealthtn | scrolldaddy |
| Container User | _(blank)_ | _(blank)_ |
| Web Root | /var/www/html/empoweredhealthtn/public_html | /var/www/html/scrolldaddy/public_html |
| Site URL | https://empoweredhealthtn.com | https://scrolldaddy.app |

Click **Add Node**, then use **Test Connection** from the node's Overview tab to verify SSH access.

## Admin Pages

All pages are at `/admin/server_manager/...`. Each page's `@joinery-admin` header sets who may open it ([Permissions](../../../docs/permissions.md)): the dashboard, node detail and the job pages at 7, which run the look jobs (check status, logs, file head, page probe and the other reports) and read their redacted output; the other pages at 8; the backup target pages (keys), and the pages that add hosts, nodes or machines, provision a server, or manage domains, transfers or service tenants, at 10. What a POST on a page does needs its own level (`SmActionLevels`): 8 to change a node (restart, update, publish, run a backup, cancel a job, triage an incident), 10 for anything destructive or key-changing (restore, delete, purge, move or copy a site, reboot, root access, a join's or an escrow's trust, a credential, the data root's encryption, deleting a release). Below 8, node detail shows only its reading tabs. The API actions declare the same levels.

The UI is organized around a **dashboard + node detail** pattern. The dashboard shows the fleet overview; clicking a node opens a tabbed detail page with all operations for that node.

| URL | Purpose |
|-----|---------|
| `/admin/server_manager` | **Dashboard** -- agent status, node cards with health dots, publish upgrade, recent jobs |
| `/admin/server_manager/incidents` | **Incidents** -- every condition on every node that needs, or needed, a person; triage several at once (see *Incidents* below) |
| `/admin/server_manager/incident?id=N` | **Incident** -- one incident: its state, its timeline, its evidence, triage and notes |
| `/admin/server_manager/fleet_security` | **Fleet Security** -- every machine and site container and whether it is patched, red first; **Install security updates everywhere** (see *Fleet security* below) |
| `/admin/server_manager/machines` | **Machines** -- every machine we pay for: managed, servers in our cloud accounts no node runs on, and machines written down by hand (see *Machines* below) |
| `/admin/server_manager/node_detail?mgn_managed_node_id=N` | **Node Detail** -- tabbed page for a single node (see tabs below) |
| `/admin/server_manager/node_add` | **Add Node** -- the record a node's own join request is approved against |
| `/admin/server_manager/targets` | **Backup Targets** -- CRUD for cloud storage targets (any provider in `StorageProvider`), and Where new backups go |
| `/admin/server_manager/jobs` | **Jobs** -- global job history with filters by node, status, and type |
| `/admin/server_manager/job_detail?job_id=N` | **Job Detail** -- single job output with live polling; **Cancel** a pending job, **Mark as failed** a running agent job its node will not report, **Re-run** a finished one |
| `/admin/server_manager/domains` | **Domains** -- managed domain registrations: hand-overs waiting for a registrar push, failures, and the full ledger |
| `/admin/server_manager/transfers` | **Server Transfers** -- hosted sites moving to their customers' own Linode accounts: state, code expiry, what Linode last said, the last email and check, and any to-do (see [Hosted tier](hosted_tier.md#moving-a-site-to-its-customers-own-linode-account)) |
| `/admin/server_manager/rollouts` | **Staged Rollout** -- apply this management node's release across chosen nodes, one at a time in the order given (Deploy to all managed nodes: the test sites first, then the rest at once, one per machine), stopping at the first node whose apply does not prove good (see *Staged rollout* below) |

### Node Detail Tabs

The node detail page (`/admin/server_manager/node_detail?mgn_managed_node_id=N&tab=...`) has seven tabs:

| Tab | Purpose |
|-----|---------|
| **Overview** | Top to bottom, most wanted first. **The node at a glance:** the health dot, the name, the node number (**Node #N**) and any install state, the Actions menu, then the facts a person looks for: the address (with the instance's IPv6 when a provision recorded it), the site, the release (current, a newer one available, or ahead of this management node), the operating system with any release upgrade Ubuntu offers, and how long the machine has run with whether the site answers from outside. Under them the action buttons (Check Status, Install Report and Host Report on a node whose agent ships the primitive, and **Compare edge and direct** on a node with a site and an address of its own; see *Edge versus direct*) and two disclosures: **Logs** on a node whose agent ships `site_log` / `site_log_count` / `log_table_tail` (the last lines of a site log file, how many problem lines the error log holds since a time, or the newest rows of a log table, read on the node; when the node last reported its owner's log-access switch off, the disclosure shows that reason instead) and **Diagnose and repair**. The Actions menu also offers **Run Plugin Installers** (every active plugin's `host_installer`, as root, idempotent: how a bare-metal node picks up system-service configuration after a plugin is activated) and **Run Host Housekeeping** on a node whose agent ships `host_converge`. **Then what needs a person:** an install state's banner, a monitoring problem, and how many of this node's incidents need a person (red when a critical one is still happening). **Then Health, Agent and Security** (below), Incidents (this node's incidents, linked to their pages; see *Incidents*), SSL Setup when a certificate is still to be issued, the connection details, on a sold hosted site's node **Move to customer's Linode** (the transfer check, run as the tab opens, with Re-check and Start; see [Hosted tier](hosted_tier.md#moving-a-site-to-its-customers-own-linode-account)), DNS, reverse DNS (see *Reverse DNS*; for a site moved to its customer's own Linode account, a pointer to their own Cloud Manager; for a node with no record of its cloud server, **Adopt cloud server**, see *Adopting a cloud server*), recent jobs, and the connection settings form (collapsed by default) |
| **Health**, **Agent** and **Security** (on Overview) | **Health:** a bar each for disk, memory, swap and load, coloured by one rule (green under 75%, amber 75 to 90%, red over 90%, and a disk under 10% free is red too), with marks on the bar at 75% and 90%. Disk, memory and swap come from the `host_report` (`mgn_last_host_report`) where the node sent one, else from the status check; the disk's free space is the node's own `avail` figure, not total minus used, and its inode use and, on a node whose agent ships `disk_usage`, **What is using it?** sit under it. A data root stopped by hand reads **stopped by hand**, with when and what stopped with it and how to start it, where one down for any other reason reads not mounted. Under the data root's bar, the version of its script (`host_report`'s `data_root_script`), the copy a run starts where it differs from this release's, any service kept on the data root that is installed with no drop-in ordering it after the data root (it could start before the data root is mounted), and each such service in a list. Load is read against the machine's processor count (`host_report`'s `cpus`); from a node whose report has none it is a number without a bar. **PHP workers** (`host_report`'s `php_workers`): how many requests PHP works on at once, sized from what memory and how many CPUs (the plan's line in `zz-joinery-memory.conf`; see [Deploy and Upgrade](../../../docs/deploy_and_upgrade.md#postgresql-and-php-memory)), or in amber PHP's packaged figure, not sized to the machine; the host reserve beside what the rest of the machine actually holds (amber when it holds more); and **PostgreSQL restart pending** when its memory drop-in changed after PostgreSQL last started and is the value in force (read with `postgres -C` and `postmaster.pid`, no database login: host housekeeping writes the drop-in and never restarts PostgreSQL). **Services:** each expected service (web server, PHP, database, scheduled tasks, fail2ban) running or not running, as systemd says, or not on this machine. Whether a service also answers is the node's own check: its `service_health` recipe restarts one that does not and opens a case if that fails. A **Restart** button on a node whose agent ships `restart_unit` (the agent itself and sshd are never offered); where the mail apps gateway is installed, a **Mail apps gateway** row: off while no site has mail apps on, else running, not running or not answering, with its version, sites, connections, any site not answering and any certificate under 14 days, and **Restart** and **Why?** (its journal); on a Docker host each site container running, not running, or held stopped (with who held it, when and why, or a switch-over's old container, kept stopped until it is removed), with a Restart on a node whose agent ships `restart_container` for any container not held, and on a node whose agent ships `hold_container` **Hold stopped** for a running one (the system modal asks why; the reason is kept on the job, never sent to the host) and **Start** for a held one, and under its name the site's own figures: memory in use against its limit and its peak since it started, CPU (a share of one core) and bytes sent since the report before, the disk its volumes hold, its processes against their ceiling, an amber count of the times the kernel killed a process in it for memory, and an amber count of the outbound packets its limits dropped since the report before (or, for a running site its limits do not cover because it is still on Docker's default network, a line saying so). A container site's agent cannot see the host, so its Services tile names the host's node instead, and its Health box carries **On its server**: the same figures from the host's report, with a bar when the site has a memory limit. Inside a container the Memory bar is the site's own memory out of its limit (or out of the whole server where it has none), not the server's. **Certificates:** each name the site serves with its days left (green over 30, amber 14 to 30, red under 14, where the node renews), or **self-signed** in amber for a certificate that signs itself (the install placeholder: a visitor reaching the machine directly gets a browser warning, one through a proxy serving its own certificate does not). **Machine:** reboot pending, the last reboot this management node asked for (asked; waiting for the machine to come back; back, by when; not done, with the node's reason; or, after thirty minutes with no host report carrying a new boot id, not seen back), on a node whose agent ships them **Listening ports** (`listening_sockets`), **Network** (`net_health`, not on a container site's own node: its host is asked), **Web workers** (`web_workers`), **Site state records** (`site_state_report`), **All containers** on a Docker host (`container_inventory`) and **Reboot** behind the system modal (`reboot_machine`; in place of the button, the reason this management node will not ask: a container site, a data root mid-run, or an encrypted data root with no escrow holder), failed units (with **Why?** on a node whose agent ships `unit_journal` and **Clear** on one that ships `reset_failed_unit`, both offered only for a unit on the compiled list), the kernel's out-of-memory, disk-full and I/O-error counts and its connections dropped for a full connection table or a full SYN queue (`conntrack_full`, `syn_flood`) for the last 24 hours, the machine's outbound limits (on, off, not installed, or not in force with the reason, and on a bare-metal machine an amber count of the web server's user's drops since the report before; the figures each site gets (while off, the ones turning them on puts back) and whether they were set from this management node or on the machine, and each site whose own figures differ, marked where this management node set them; on a node whose agent ships `outbound_limits`, **Turn limits off** or **Turn limits on**, behind the system modal, and **Set the figures** for the machine or one container site), when security updates last ran (with **Install security updates now** behind the system modal on a node whose agent ships `install_security_updates`: the nightly run's updates, now), the security fixes waiting (`host_report`'s `security_pending`, from the machine's hourly check: how many, the critical ones by name with their new versions, when it checked, in red when any is critical; "not checked yet" where the check is not installed), anything still running code an update replaced (`services_needing_restart`), on a container host the site containers' OS (the nightly replacement's last check and run, the sites it replaced or passed over, any hold, a run under way, and in red a host running site containers that has never checked; with **Refresh now** behind the system modal on a node whose agent ships `refresh_site_containers`: the nightly run, now), and the release upgrade with the date of Ubuntu's own check (Ubuntu re-checks only when someone logs in). Plugin Checks, **Plugins** (each plugin switched on with its version, the count installed and off, from `check_status`'s `plugins`, with **Spam learning** where the Mailbox plugin is on and the agent ships `spam_learning_status`) and stored-secret health follow, and the box ends with when its figures were read. **Agent:** whether it checks in (connected, late, not checking in, or switched off by its owner) with its last check-in and key fingerprint; its version against the newest this management node ships; whether its scripts verify against their signed release; whether its owner lets this management node read its logs; **Release keys**: the keys the site and the agent trust, from the status check's `release_trust`, each marked as expected or differs against the keys this management node expects (on the box that publishes, the keys its repository lists and their authority, which the next release installs; elsewhere, the set this management node holds), and the dashboard marks a node whose set differs with **release keys differ**, and one whose agent reports its keys (1.81.0 or later) and whose status data holds none, or none for the site it hosts, with **release keys not reported** (`ReleaseTrustView::attention()`); and **What it watches**, one line per recipe in plain words with whether it repairs or only reports and what its check last said, from `mgn_agent_recipes` as the agent reported them at its last poll (see *Recipes* under the agent channel). Pairing and keys stay on the API Keys tab. **Security:** failed SSH sign-ins in the last 24 hours, whether passwords and root sign-in are accepted, sshd's other effective settings when the node reports them, **Turn off root login** behind the system modal on a node whose agent ships `disable_root_login` (or the reason this management node will not ask), and fail2ban's banned addresses per jail. Every value from the node is escaped, and an unreadable fact shows as unknown |
| **Backups** | Target indicator, run database/project backup, backup file browser with scan, per-file upload-to-cloud and delete, restore full project from a `.tar.gz` archive, restore from an incremental chain; the Backups box states the last backup, the last full backup, the oldest held and **Last verified restorable**, and each run has Prepare, **Verify** and Restore |
| **Database** | Restore from a backup file, and the record of database operations |
| **Updates** | Version comparison (node vs management node), apply update |
| **Copy** | Copy this site onto a new server and keep it dormant there; see [Site copy](#site-copy). On a dormant copy's own row, a pointer to its source's tab |
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
- **Managed Nodes** -- rows with health-based status dots (green=healthy, yellow=warning, red=problem, gray=no data), grouped by what a node is. A **host** group is a Docker box: its header shows the placement record (`mgh_managed_hosts` — address, container count against `mgh_max_sites`, whether automated provisioning may place customer containers on it), its first row is the box's own agent node (badged *host agent*, never counted as a site), and the rows under it are the containers placed there. Every other node — a bare-metal site, a relay, a DNS box, the management node itself — is a machine in its own right and is listed flat under **Machines**; a machine has no host record because its box identity is its node. A node placed on a host record that has since been deleted is listed as a machine, not dropped.
- **Publish Upgrade** -- build upgrade archives from management node source code, as a job of the management node's own agent
- **Recent Jobs** -- latest 20 jobs across all nodes

Health dot colors reflect actual server health, not check recency:
- **Red**: Last check failed, disk > 90%, or PostgreSQL not accepting connections
- **Yellow**: Disk > 80% or load average > 5
- **Green**: All metrics healthy
- **Gray**: Never checked or no data

### Edge versus direct

**Compare edge and direct** on a node's Overview answers one question: is the site slow, or is the path to it slow? This management node times the same requests two ways at the same moment: through the site's public name (its edge, such as Cloudflare or a relay) and straight at the machine's own addresses (`mgn_host`, and each public address in `mgn_addresses`, IPv4 and IPv6, which the agent reports at its join and in each escrow state post). No agent is involved; `EdgeOriginProbe` runs inside the request.

Each of up to 20 rounds sends, at once down every path, a GET of `/login` and a POST of `{}` to `/api/v1/action/vault_heartbeat`, which refuses it without a session; the POST is there because a request body that never arrives is a stall of its own. A request that takes 5 s or more, or never answers within 10 s, is a stall, and no round starts after 45 s, so a run finishes inside the 100 s the edge in front of this management node allows. The direct legs verify neither the certificate's name nor its chain (an origin behind an edge may present a Cloudflare Origin CA certificate or Apache's fallback one); the result names the certificate each origin presented. Requests identify themselves as `JoineryEdgeProbe/1`, which sites never count as visitors.

The completed job (`edge_vs_origin`) carries each path's requests, stalls, 50th and 95th percentile, statuses, errors and 429s, and one verdict line: *Slow through the edge, fast direct*, *Slow on the server*, or *No problem seen*, with a note when the site's public name resolves to the machine itself and both legs took one path. The POST legs carry no credential, so they count against the site's own per-address limit and land as `no_credential` rows in its request log; a node is compared at most 10 times an hour, and a 429 is reported as the site's limit, not as a stall. **Re-run** on the job page compares again.

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

Enrollment is node-initiated and **shares no secret**. On the node, Admin → System → **Management Node**: the site's admin enters the management node's URL — just an address. The node's root agent generates an Ed25519 keypair, keeps the private half at `/etc/joinery-agent/node_identity.json` (mode 0600, root-owned), and sends a join request carrying only the public half, a claimed name and, when the machine has a site, the site's `public_html` path. The node's page then shows the key's short fingerprint and waits.

On the management node, the request appears on the node's Detail → **API Keys** tab: claimed name, source address, and the same fingerprint. Approving is superadmin-only and should happen **only if the fingerprint matches the node's own page exactly** — the name and address are claims anyone could make; the fingerprint comparison is the entire security of the introduction. Approval binds the public key to the node record, and fills an empty web root from the one the join named; the agent picks it up on its next check and both panels flip to Connected. After that, each status check fills an empty web root and an empty site address the same way, from what the agent reads in the site's own config: the site address becomes `https://` plus the config's domain (`webDir`). A set value is never replaced, and an address another node already holds (other than a copy's source) or this management node's own is left empty and logged.

**The management node stores only the public half**, so there is no credential on it that could act as the node — and enrollment adds nothing to steal: no token, no code, nothing copied by a human. A wrong approval is visible (the tab stamps the connection time, agent version, and last poll) and severable with the Disconnect button, which forgets the key and drops the node back to API/SSH routing.

Requests are rate-limited, expire after an hour (the agent renews its own while it waits), and are capped in number. A rejected request retires its keypair — the agent introduces itself with a fresh key on the next ask.

The web tier's only involvement on the node is a handoff through three managed settings: `agent_join_request` (the URL the admin asked for), `agent_join_state` (the agent's progress, which the page renders), and `agent_leave_request` (the admin asked to disconnect). None ever holds a credential.

### Routing

A connected agent is routed to — approving the join is the routing decision, and there is no further switch. An operation with a primitive implementation runs on the node's own agent, and only there: a node without a paired agent is refused such a job at build time, with the fix (pair the node) in the message. SSH exists only for the bootstrap pair — `install_node` and `retire_install_password`, both run plane-side by `InstallJobExecutor` over a provision's sealed root password. Disconnecting the agent therefore stops a node's operations until it pairs again.

Either side can end the pairing, and neither needs the other's cooperation. This plane's Disconnect button forgets the node's key. The node's own Management Node page has a Disconnect too: its agent finishes any running job, sends one signed goodbye to `/api/v1/agent/leave` (so this plane forgets the key immediately), deletes its identity, and then takes no work at all until it pairs again — and it leaves even when the goodbye cannot be delivered, in which case this plane just sees the agent go silent until someone disconnects the node here as well. Both endings run through `AgentChannelEndpoint::forgetAgent()`, so they cannot drift apart.

The node's Management Node page shows the connection in use however it began (from that page, `joinery-agent join`, a site copy or `move_to_plane`): a connected agent writes it into `agent_join_state` and rewrites it whenever the page's view drifts, and clears a join request recorded while it is connected, which it would otherwise ignore.

**Moving a machine to another management node.** A site moves from its own Management Node page: Disconnect, then Connect to the other address. A machine with no site (a Docker host) has no page, so this plane asks for it: the API Keys tab's **Move to another management node** takes the other management node's address and sends `move_to_plane` (agent 1.67.0; `JobCommandBuilder::build_move_to_plane`). The agent stages a fresh keypair for that management node and files the join there under this node's slug, so an approval there gives it the same slug and its backups keep their folder name. That holds when the machine moves to its own management node too: the plane joining itself shows as its own site's host, but takes its slug from the claimed name (`AgentChannelEndpoint::adoptJoin`). The job's result names the fingerprint to compare before approving it there. The machine stays connected here until then, so an ask that is rejected or never answered loses nothing; once it is approved, the agent stores the new credential, sends this plane the same signed goodbye a Disconnect sends, and restarts onto the new management node. `joinery-agent leave` on the machine cancels a move in flight.

**After a goodbye.** The goodbye also records, on the node, when the agent left (`mgn_agent_left_time`) and, when it followed a `move_to_plane` this plane sent since the pairing began, the management node it went to (`mgn_agent_left_to`, from that job's `management_node`; `AgentChannelEndpoint::recordLeave`). From then on `ManagedNode::agent_moved_away()` answers for the row: the node page says 'Its agent moved to … on …' above every tab and that nothing here reaches the machine; Connect agent, the DNS box and Reverse DNS are not offered (Set Reverse DNS refuses); and fleet backups, patch runs and Deploy to all managed nodes leave it out without listing it. Its uptime check from outside keeps running when switched on, so a row kept only to watch a site that manages itself still does that job. A new pairing clears both fields. A plane-side Disconnect is not a goodbye and records neither.

Operations cross one at a time. An operation has crossed when `JobCommandBuilder` has a `build_<op>_primitive` method for it; `transports_for()` discovers that by reflection, and `ManagementJob::createFromBuild()` stores the right shape without any caller knowing which transport ran.

**A primitive is routed to a node only when that node says it has it.** Every claim carries the agent's own list of the primitives its binary compiled in, and the plane stores it (`mgn_agent_primitives`). Routing consults that list: an operation missing from it is not sent to the agent — the builder refuses at build time (or, for `check_status`, falls to the probe transport). The node's own account is the only one that is not a guess — a version number says which release a machine runs, and only the machine says what that release compiled into it.

**The version floor.** `AgentVocabulary::FLOOR` is the oldest agent this management node supports (1.43.0). A node whose agent is below it — or reports no version, or no vocabulary at all — is offered `apply_update` and nothing else, which is how it gets back above the floor; the node list flags it. The floor is a constant that rises in a release, never a setting: the code that spoke to older agents is deleted each time it rises, so there is nothing below it for the plane to say. At or above the floor, the node's reported words decide, and an empty report is no evidence of any word.

**Word contracts never change.** A word's name, parameters and meaning are fixed once it ships; its output may gain fields and never loses or redefines one, and the plane reads a missing field as *not reported* (null), never as zero or no. A change to what a word takes or does is a new word with a new name. That is what makes routing by name sufficient on a fleet running several agent versions.

**A feature declares the words it needs.** A feature built from several words asks `AgentVocabulary::missing_words($node, [...])` once, and on a node that lacks any of them shows the one standard state — *Needs a newer agent; update this node*, naming the missing words and, below the floor, the floor (`needs_newer_agent_text` / `needs_newer_agent_html`) — never an error, a blank, or a half-rendered card. Every builder refuses a missing word with the same sentence.

**Both directions of a version gap.** A claim field this plane does not know is set aside unread (`AgentChannelEndpoint::known_claim_fields`) and the rest of the claim is taken, so an agent newer than its management node keeps reporting its vocabulary; a word this plane has no builder for is stored in the vocabulary and never routed. Against a management node that still refuses an unknown claim field, the agent drops only the field the refusal names and claims again. `tests/agent_version_spread_test.php` holds a recorded older agent's poll and a newer agent's poll against this plane; the agent's `vocabulary_test.go` holds the other side.

**Version spread on the node list.** Each node row shows its agent version, how many releases it is behind the agent this management node ships (`agent_dist/manifest.json`), and a *below minimum* badge under the floor. A staged rollout (`/admin/server_manager/rollouts`) is how the spread is closed.

**The management node is a node like any other.** It pairs to its own site, and the work it does for itself — publishing a release — reaches it the same way every other operation reaches every other machine: as a primitive its own agent looks up in its own vocabulary. There is no second job source anywhere in the fleet. A row written into `mjb_management_jobs` by hand executes nothing, on any machine.

### Recipes: what the agent does on its own clock

Besides the words the plane may ask for, the agent carries **recipes**: fixed sentences compiled into its `recipes` package, each one *check, repair, verify, retry, hold, escalate* for one aspect of the host. A recipe takes no parameters and is registered like a primitive, with its own pinned registry test, so a recipe cannot arrive in a build without a human naming it. The loop runs on every agent from 1.27.0, paired or not — a self-hosted box with no management node is exactly the machine that has nobody else to repair it — and nothing on the wire names, starts, stops or arms one.

The loop (`recipes/loop.go`): every ten minutes each recipe's check runs. A check answers *pass*, *fail* or *unknown*; unknown (a container, a box without systemd, a script the node cannot verify) never repairs and never counts. A repair runs only after two consecutive failing ticks, under the same job lock a plane job holds (a tick that cannot take it writes "a job is running" and tries next tick) and the same job marker (`/etc/joinery-agent/job-running`, pid first, then `recipe <name>`). Three attempts per failing run (the attempts since the check last passed, a repair last verified or the escalation closed), the second ten minutes after the first and the third thirty after the second, each due on the first tick at or after its wait even when the timer lands that tick a little early; a fourth is an **escalation** — one open per recipe, appended to on every further failing tick, closed when the check passes — during which the recipe checks and never repairs. An operator working on that aspect sets a **hold marker**, `touch /etc/joinery-agent/hold/<recipe>`: the recipe records that it is held on every failing tick and repairs nothing until the marker goes. The hold directory must be root's and writable by root alone, or the marker is ignored and the ledger says so.

Three recipes are compiled in. **`fail2ban`**: the unit is active with at least one jail, or `host_converge` runs `host_housekeeping.sh`. **`agent_supervision`**: something would restart the agent if it stopped (`agent_report`: systemd supervising this process with a restarting unit, or the cron keepalive installed and switched on), and something starts it after a reboot (a unit that restarts the agent but is not enabled fails), or `agent_converge` runs `install_agent.sh`, which writes the supervision and, under the job marker an attempt holds, restarts nothing. The host timer's daily converge already ran that installer, so a lost unit file or keepalive was back within a day; the recipe makes it minutes. An agent that a person started by hand on a systemd box is genuinely unsupervised and the installer cannot fix that without restarting it, so three attempts open a case and a person restarts the agent. **`disk_headroom`** is **check-only** (`Recipe.NoRepair`): the site filesystem has at least 10% and at least 5 GiB available, read from `host_report`'s `disk.avail_bytes` and `disk.total_bytes`; unknown when either is unknown. It has no repair — there is no safe automatic answer to a full disk — so the loop opens a case on the first failing check, spends no attempts, and closes the case when the check passes. It runs on every node, paired or not, which is how a self-hosted box with no management node is warned about its own disk. Its rule has no slope and no history on purpose: the node holds no series. Register permits `NoRepair` only with no repair word and no repair function, and the registry test pins which recipes are check-only.

Four more are compiled in, and each **repairs with a value the node derived for itself** (`Recipe.NodeDerived`): a recipe may call a word that takes a parameter only when the value comes from the node's own answer — its own expected-units list, its own container list, its own vhost — never from the wire or a file, and the word validates the value against its own compiled set whatever the recipe passes. The registry pins which recipes are node-derived. **`service_health`**: PostgreSQL, PHP-FPM and Apache *answer*, not merely run — `host_report`'s `answers` (a request for the site's own name through Apache that must come back through PHP, and `pg_isready`), beside the unit states; a unit systemd has given up on, or one that runs and does not answer, is restarted with `restart_unit`, the first failing in dependency order (PostgreSQL, then PHP-FPM, then Apache), and an absent unit (a database on another host) is not this machine's to restart. **`container_health`**: on a Docker host, each site container runs and its site answers through PHP on its published port, or `restart_container` restarts the first that does not. A container held stopped (`held` in `host_report`'s `containers`: `hold_container`'s mark, `/etc/joinery/sites/<site>/held`) is left alone, and `restart_container` refuses it. Neither `service_health` nor `container_health` repairs while the data root is stopped by hand (`host_report`'s `data_root_stopped`, agent 1.81.1): what is on it is down on purpose, and restarting a service on it would start the data root again. **`certificate_expiry`** (hourly): every name the site vhost serves has a certificate with at least 14 days left, read over this machine's own connection with SNI (`served_certificates`); fewer means certbot's own timer failed, and `provision_certificate` re-issues the name with the fewest days, the domain taken from the node's own vhost. **`mail_gateway`** (host-scoped): while a site on the machine has mail apps on, the mail apps gateway (`joinery-mail-gateway`, plugins/mail_apps) runs and greets a mail program on 993 and 465 under each site's mail name (`host_report`'s `mail_gateway`); a stopped gateway, or one that runs and no longer greets, is restarted with `restart_unit`. systemd already restarts a gateway that exits. The unit is a constant in the recipe, never read from the report. The agent itself is never one of `service_health`'s units: its supervision is `agent_supervision`.

**`release_files`** (release-scoped: a site installed from a release, never a source checkout) is the deployment files' own recipe: the five files an upgrade starts from (`utils/upgrade.php` and its four companions) match the signed release, read from `upgrade_preflight`'s `manifest` and `files` facts alone (its disk, lock, `VERSION` and theme checks are a rollout's business and never fail this recipe), or `repair_release_files` puts each differing one back from the agent's kept copy or from this management node, asked by the hash the manifest lists. An unusable manifest is unknown here, never a failure: that is the manifest healer's case, and a repair would be refused for the same reason. See *Script trust and the manifest* for the state it clears.

A recipe has a **scope**. `release_files` is release-scoped: its subject is a signed release's own files, and a site that is a **source checkout** (a `.git` at the site root or the web root: the publishing site, a developer's box) has a tree that is the developer's, legitimately different from the last published manifest between publishes, so the recipe never ticks there, the claim reports it `not-applicable`, and both `restore_release_file` and `repair_release_files` refuse on such a site naming the checkout. `fail2ban` is host-scoped: its subject is the machine's units and jails, and an agent inside a container cannot see that machine. `agent_supervision` is site-scoped: its repair is an installer that lives in a site tree and is not in the support bundle a machine with no site runs from. Where a recipe does not apply the loop does not tick it: one journal line and one ledger line (`not_applicable`, with the reason) at start, and the claim reports the recipe as `name:not-applicable`, which the node page's Agent box shows as not here. The host's own agent watches the host.

Two ledgers, both JSON lines, one per recipe: `/etc/joinery-agent/ledger/<recipe>.jsonl` is root-only and is the one the loop reads back (the attempts of the current failing run, the open escalation); an attempt is written when it starts and its outcome when it ends, so a repair that kills the agent is still a counted, failed attempt on the next start. `{site}/cache/recipes/<recipe>.jsonl` is the same lines, readable by the site, written and never read. Events: `check`, `attempt`, `outcome` (`repaired`, `failed`, `report-only`, `interrupted`), `held`, `hold_ignored`, `busy`, `escalation`, `escalation_note`, `escalation_closed`. A `check` line is written on every failing tick and whenever the verdict changes; a pass after a pass, or an unknown after an unknown, writes nothing, so a healthy recipe's ledger is its restarts and a recipe that cannot answer does not fill its ledger with the same answer.

**Armed or report-only** is a compiled constant in the agent (`recipes.ReportOnly`, false: the loop is armed and repairs). While report-only is on, an attempt is ledgered with the outcome `report-only` and runs nothing; the two-tick rule, the backoff, the budget and the escalation count exactly as if it had run, so the ledger shows the path the armed loop would take. Arming or disarming is a release, never a setting. The fail2ban repair counts as run when the converge transcript carries the runner's ok line for `host_housekeeping.sh` and not its failure line; on a machine with no site the runner runs the whole host set and says more after that line, so the line is looked for, never required last.

The recipes an agent runs, each with its mode and, once its check has run, what the check last said, travel in every claim (`recipes`: `name:armed:fail`, `name:report-only:pass`, `name:not-applicable`, comma-separated; the verdict is `pass`, `fail` or `unknown`), are normalised on intake like the vocabulary (`AgentChannelEndpoint::normalised_recipes`, which drops an entry whose verdict is outside that set and drops the verdict from a not-applicable recipe, since one is never checked) and stored in `mgn_agent_recipes`. The Agent box on the node page lists them, one line each, so a person can see whether a node acts and whether its check passes. A failing check is the step before a case: an armed recipe is repairing it on its own clock, and if it cannot, the case it opens is the incident. A report-only escalation opens a case just the same.

#### The case

When a recipe gives up — three attempts in an hour and the check still fails — its escalation is a **case**: the same node-minted id, the same open/closed state, given a body and a delivery. The body (`recipes/case.go`) is composed once, when the escalation opens: the loop's mode, the attempts that spent the budget (time, word, outcome, bounded detail), a fresh `host_report`, the vocabulary and the recipe list, every field capped before it leaves. The node closes the case: the recipe's check passing again is the one close, and the plane never tells the node a fault is gone. A failing tick while a case is open is a note appended by id, never a second case; a new case cannot open until the old one closed.

On a paired node the case rides the claim in `cases`, a JSON object keyed by source (`recipe:fail2ban`; the unexplained-root classifier's own source lands in the same field once it is built): for each source the most recent case, open or closed, as a summary on every claim, plus the body until a claim carrying it succeeds. A closed summary keeps riding until the next case replaces it, so a close is never lost to a plane that was not listening. `cases` is one of the extras: a plane that predates it refuses the field and the agent drops every extra, as for `recipes`.

Intake (`AgentChannelEndpoint::intake_cases`) caps and re-validates every field — the field as JSON at `MAX_CASES_BYTES`, at most `MAX_CASES_PER_CLAIM` entries, free text at `MAX_CASE_TEXT`, attempts at `MAX_CASE_ATTEMPTS` with details at `MAX_CASE_ATTEMPT_DETAIL`, the host report through `sanitise_host_report`, the lists through the claim's own normalisers, times only as RFC 3339 UTC — and stores the result in `inc_incident_records` (`IncidentRecord`, node-scoped: the case store settled question Q3 names). A new id is stored; a known id is appended to (note count, newest note, a body when one rides); a close the node reports is recorded; a closed id never reopens; a source must be a recipe the node reports or a source the plane knows; at most one open case per source per node (a higher id arriving open closes the older one with a note that its close was not heard, a lower id is refused) and at most `MAX_OPEN_CASES_PER_NODE` open per node. A refusal is logged and costs the claim nothing else.

A stored case is an **incident** (see *Incidents*): intake gives it its plain title (`IncidentTitles`, the plane's own words for the recipe; the node's reason is shown under it as the node's account), its severity (warning), triage `new`, and its timeline: an `opened` event at the node's own opening time, and a `cleared` event when the node reports the close (or when a newer case shows the old close went unheard). A case first heard already closed still opened and still needs a look. Nothing on this side ever closes a case. A case rides every claim, and a claim that changes nothing writes nothing: an open case is re-stamped as still seen at most every ten minutes (`inc_last_seen_time`), and a closed summary, which rides until the next escalation replaces it, is never written again. A note is taken when the node's count is higher or its note time is newer, so a node whose ledger was trimmed and whose agent restarted (its count starts lower) still lands its newest note; the stored count never goes down.

On the node itself the agent also writes a rendered copy of the case outward, `{site}/cache/recipes/<recipe>.case.json` beside the outward ledger, written whole and never read back, carrying the case, its body and its delivery (`local`, or `management node` when a plane is polling). The site's admin header shows an open one as a core notice (`RecipeCaseNotice`) worded "as reported by the agent's ledger", on a paired node too (saying the management node has it). The record carries when the agent rendered it, and the agent renders an open case again on every failing tick, so the notice says when the agent last reported the case and, once that is more than a day old, that the record may be out of date and the agent should be checked. The hourly `RecipeCaseMail` task is the unpaired path only: while a case whose delivery is `local` is open and the agent has rendered it within the day, it sends the superadmins one plain-text mail per recipe per day — no markup, no link, the last send per recipe kept in the `recipe_case_mail_log` setting. A record the agent has not rendered for a day is not mailed until it renders it again, so a stopped agent's frozen record is not a daily mail forever; and a case a management node has is never mailed from the node, because the plane's Incidents page and header line already carry it. The web user can forge that file; what a forgery buys is a lie to the admin and one mail, and nothing root acts on.

| Recipe | Check | Repair | Reading |
|--------|-------|--------|---------|
| `fail2ban` | `host_report`: `expected_units.fail2ban` and `fail2ban_jails` | `host_converge`; verified by the transcript's last line `core installers: host_housekeeping.sh: ok` and the check again | *pass*: active with at least one jail. *fail*: inactive, failed, absent (the installer installs it; an operator who removed it on purpose sets the hold marker), or active with no jails. *unknown*: the unit state `unknown`, jails that could not be listed, or a report the node could not run |

### Log access: what the plane may read of a node's logs

The log words — and `file_head`, which reads host configuration — are gated on the node by its owner. `agent_log_access` is a managed setting on the node, on by default, switched from the node's own Management Node page (`/admin/admin_management_node`), where what it grants is explained in the owner's words. The agent reads it with the same reader it reads `agent_enabled` with, projects it into a root-owned marker so the file word still answers when the node's database is down, and refuses both words with `this site's owner has not allowed log access (agent_log_access is off)` while it is off — whatever the plane says. The node reports the switch on every poll as `log_access` (`on` or `off`), stored in `mgn_agent_log_access`, so the plane's Logs disclosure shows the reason before a job is queued. A node that was already connected when the switch arrived shows its administrators one notice (`AgentLogAccessNotice`) until they acknowledge it or use the switch.

The node also reports its owner's answer on emergency root access for the beta (the managed setting `beta_root_access`, agent 1.94.0+) on every poll as `root_access` (`on` or `off`), stored in `mgn_agent_root_access` when it is one of those and differs. A poll whose database does not answer sends nothing, and the last answer stands. Nothing acts on `off` by itself: the holder of the box's kept password sees it on the dashboard and retires it.

Everything the words return is redacted **on the node** by the agent's `redact` package before it leaves: credential values by the key names `SmSecretRedactor` masks (the two lists are pinned equal by `tests/unit/agent_redactor_parity_test.php`), in the quoted shapes and in the assignment shape in any case (`PGPASSWORD=...`, `password=...`, `api_key=...`; a lowercase name ending in `_key` is a flag or a column and stays readable), the local part of email addresses (the domain is kept, since a mail diagnosis needs it), IP addresses, and long opaque tokens. The plane's `SmSecretRedactor` is the second pass, at display time, as for every transcript. Member addresses, submitted forms and raw payloads are not returned at all: the table word reads a compiled column list that leaves them out.

What a log job returns is recorded as the job's result by `JobResultProcessor` (`process_site_log`, `process_site_log_count`, `process_log_table_tail`) from the node's envelope, reduced to the keys the job page renders and bounded on intake (text at `LOG_EXCERPT_MAX_BYTES`, rows at `JobCommandBuilder::LOG_MAX_COUNT`, columns at `LOG_TABLE_MAX_COLUMNS`, cells at `LOG_TABLE_MAX_CELL`); a completed job whose output was not a log envelope records `read: false` and the job page says so. The job page renders a site_log result as a log box and a log_table_tail result as a table, both through `SmSecretRedactor`. A `unit_journal` result is recorded the same way (`process_unit_journal`) and rendered as the unit's verdict — state, result, exit status, last run — above its journal lines; its lines age out on the same retention window as a site log's, since they are the same kind of thing (`ManagementJob::LOG_EXCERPT_TYPES`). A `reset_failed_unit` result (`process_reset_failed_unit`) is the unit's state, sub-state and result before and after, and whether systemd accepted the reset; the job page shows both states. A reset the node accepted queues one `host_report` (unless one is queued or ran in the last minute), so the Machine tile that offered **Clear** re-measures rather than naming the cleared unit until the next report. A `file_head` result (`process_file_head`) is kept like a site log's — name, path, line count, text at the same cap — and ages out on the same window; each line was redacted on the node by its configuration shape (`redact.Config`: a line whose key names a credential is the key alone, whatever the separator; a secret named later in the line loses its value; addresses in configuration are infrastructure and stay). A `disk_usage` result (`process_disk_usage`) is rebuilt as two size tables, every entry reduced to one path and one byte count, both capped at `DISK_USAGE_MAX_ENTRIES`; nothing in it ages out, because there is nothing in it to age out.

An excerpt does not live on the plane for good. `ManagementJob` declares a retention rule in the method form: after `server_manager_log_excerpt_retention_days` (30 by default; 0 keeps everything) the standard sweep blanks the result and transcript of a completed log job and writes `{"pruned": true}` in its place, so the job row stays as the record that the plane read that node's logs on that day.

### Reading refusals

A refused job is a terminal failure like any other, and reads as one everywhere a job status is shown — the message says `Refused by the node:` and then the node's own reason. The outcome the node actually reported is also recorded on its own (`mjb_agent_outcome`: `completed`, `failed` or `refused`), so a refusal can be **counted** rather than found by matching the text of an error message. `ManagementJob::refusalCountForNode()` is that count, windowed.

This matters more as the vocabulary grows. A node refusing work is a node whose plane is asking for something it should not be, or whose policy has been tightened without the plane noticing — either way it is the number, not the prose, that an alert reads.

A job this plane gives up on records the outcome `lost` (`ManagementJob::OUTCOME_LOST`), never one a node can send. The node never reported one, and inventing a verdict for it would make the refusal count untrustworthy in exactly the situation where it is being consulted.

### When a claim does not come back

An agent claims a job and then reports. If it never reports — it crashed, the box rebooted, the network went — the job would otherwise sit in `running` holding that node's concurrency lock. **A job is never handed out a second time**: whatever the node was doing may well have happened, and running a move, an upgrade or a restore again, unattended, is worse than not knowing. A lost job fails with the outcome `lost` and a message saying what it did on the node is unknown, and a person decides whether to run it again. It is found lost three ways:

- **The agent checks in idle** (agent 1.72.0+). An agent claims only while it runs nothing and owes no result, and says `idle` on that claim; whatever this plane still has as running for the node is failed as lost at once (`ManagementJob::markLost`). This is what catches an agent or a machine that restarted mid-job.
- **Its claim budget runs out** (`ManagementJob::failStaleClaims`, on every poll and every scheduled uptime pass): 15 minutes, or the primitive's own budget (`PRIMITIVE_CLAIM_BUDGETS`). This covers an agent that never comes back, or one too old to say it is idle.
- **An operator marks it failed**: **Mark as failed** on a running job's page, behind a confirm. It frees the node for its next job.

A result that arrives after any of these still lands: what the node did replaces `lost` (unless a newer job of the same type on the node has finished since, whose facts stand). The agent keeps every result on disk (`/etc/joinery-agent/outbox`) until this plane has taken it or refused it for good — a 4xx other than a rate limit or a refusal for the node's clock — and delivers what it kept before it claims anything, so a result posted to a management node that was down, or one cut off by a restart, arrives once it can. After an hour undelivered a result is sent as its outcome alone; after a day it is dropped and the agent's log says so, by which time this plane has failed the job as lost. A copy's `take_node_id` result is the exception: one whose post failed is dropped at once, because the copy gives up the identity it staged, and this plane never swaps node rows for a take that arrives after the job was given up on. While a unit a primitive started is still running on its own (the data root move), the agent's claim does not say idle, so the move's job keeps the node until it reports.

**Status facts are kept current on a cadence.** Version, certificate, disk and memory facts are measured only by a `check_status` job. Every scheduled uptime pass queues one for each enabled node whose agent offers the primitive and has no `check_status` completed inside the last hour (`RunNodeUptimeChecks::STATUS_REFRESH_SECONDS`, matching the hourly plugin health report), whatever the node's uptime setting — the up/down probe and the facts measure different things. The job table decides, not `mgn_last_status_check`, which the probe also stamps when it reads a site's health document. A queued or running `check_status`, or one completed inside the window, counts as cover, so a stale node yields one job per window. `host_report` rides the same cadence on its own stamp (`mgn_last_host_report_time`), except that a node whose last report says its data root is being encrypted, decrypted or re-keyed is asked every five minutes (`DATA_ROOT_RUN_REFRESH_SECONDS`); a machine this management node asked to reboot, until a report carries a new boot id, and an enrolled machine that told this escrow holder its data root opened after its last report (`DataRootEscrowSync::opened_since_report()`: a site copy's go-back, the provider's restart, a crash) are asked every two minutes (`REBOOT_REFRESH_SECONDS`), so the node page's 'opened by' is never the boot before; and a data root job that ends queues one at once (`JobResultProcessor::report_after_data_root_job()`).

### Endpoints

`POST /api/v1/agent/join`, `/join_status`, `/claim`, `/result`, `/leave`, `/quiet`, `/artifact`, `/job_status`. Join and join_status are unauthenticated by nature — until approval there is no identity to authenticate — and grant nothing; they are rate-limited, validated, and bounded in number. Claim and result are signed with the node's key; the plane verifies against the public half it holds, and selects jobs by the identity the **signature** proves, never by anything in the request body. Requests are schema-validated, size-capped, and refused for a clock more than five minutes from the plane's. `job_status` (signed, agent 1.52.0+) answers whether one of the node's own jobs was withdrawn here, cancelled or removed: a node waiting for its operator's approval asks every half minute, and a withdrawn job ends its wait as a refusal and takes the request off the node's page. Any other answer keeps it waiting; nothing on that route can approve a job. These are a separate route family from `/api/v1/management/*`, which runs the other way — the plane calling in to a node's web tier — and stays status-only.

`/artifact` is the one response that is bytes rather than a JSON envelope, and it is described under [Machines with no site](#machines-with-no-site). Its body fetches are metered on their own bucket (`api_agent_artifact_rate_limit_requests`, per node per window) and each is recorded, because they are the expensive ones; the small manifest fetches ride the general agent-channel limit.

The general limit (`api_agent_rate_limit_requests` per window) is counted in two buckets, one request-log row per request written at shutdown with the outcome. A request the node's **signature** proves (a result, a manifest fetch, a leave) counts toward that node's own bucket (`api_agent_node`, keyed `node:ID`); one that proves no node (a join, a missing or wrong signature, an unknown path) counts toward its address's (`api_agent`). A multi-tenant host's sites and a fleet behind one NAT share an address, so an address over its limit refuses only what proves no node: a neighbour whose signature verifies still gets in. A refused request is not recorded, so a bucket drains while it is over. The one request it does not count is a claim that succeeded: a fleet polls on a seconds cadence, which is tens of thousands of requests a day against a few hundred of everything else, and the rate-limit check counts rows with a query. A claim that fails does count, since an unsigned or mis-signed flood looks exactly like that. `AgentChannelEndpoint::meterOutcome()` is the rule.

## Cloud account tabs

The dashboard at `/admin/server_manager` has two tabs, **Main** (our main cloud accounts) and **Test** (the disposable test account). Hosts, machines, the find box, Agents asking to join, Cloud provisions and Recent Jobs show only the selected tab's boxes. `?account=main|test` selects a tab and a cookie remembers it. The other tab shows its node count and a dot when it holds an open incident that needs a person or a pending join.

An account is recorded on a host (`mgh_cloud_account`) or a node (`mgn_cloud_account`) as `main` or `test`. A node placed on a live host takes its host's account; any other node uses its own; an empty value reads as main, so an unmarked box is never hidden from the main tab. `CloudAccounts` holds the labels and the rules.

Main or test is the flag of the cloud account of ours the box is in (see *Cloud accounts*): a server created in, or adopted from, an account flagged test is `test`, any other account of ours `main` (`CloudAccounts::for_provision()`). A box made by hand is marked when it is first saved with the default account's flag (`CloudAccounts::plane_account()`), `main` when there is no default. Add Host and Connect Site ask for the account, with Main preselected. Migration `sm_016_cloud_account_backfill` marks the unmarked boxes a test-account plane created since 2026-10-07, the day its account became the test account; nothing is changed on any other plane.

A join belongs to the account of the box whose address it comes from, or of its provision. A join from an address no box or provision has appears on both tabs. A provision with no node yet belongs to the account it is in. The notices at the top of the page and the Incidents inbox are not filtered by tab.

## Join approval

An agent joins a plane by asking: it sends its name, public key and address, and a join request waits on the dashboard's **Agents asking to join** panel. A person approves it there after checking the key against what the machine printed, or a join from a machine this plane provisioned is approved automatically.

**Automatic approval** (`JoinAutoApproval`, run each minute by the *Auto-Approve Provisioned Joins* scheduled task) approves a pending request only when all of these hold:

1. its source address (IPv4 or IPv6) belongs to a provision that is installing or done;
2. its claimed name is exactly that provision's site name (the site agent) or `<site name>-host` (the host agent);
3. its key equals the one the provision's install showed for that agent — recorded when the install job finishes (`cvp_expected_site_key`, `cvp_expected_host_key`), read from the host agent's `Key fingerprint:` line and the site agent's `SITE_AGENT_KEY=` line in the install output — and not yet used;
4. the cloud provider reports the instance running at that address;
5. the site node has no agent yet, or no host agent is paired on the machine.

The first three and the fifth are database reads and run before the provider is asked, so a request from a stranger never causes a provider call. Any doubt leaves the request pending for a person; automatic approval never rejects anything. An approved key is consumed, and the request is marked `ajr_decided_by = auto`.

The panel shows, for a request that matches a provision, why it is still there ("no key was recorded from this provision's install", "the key does not match the one the install showed", and so on); a key that differs from the install's is flagged in red. Joins from machines this plane did not provision are always approved by a person. A person approving a join from a provisioned machine approves it as that machine's site node, its host's node, or a site in a container on it, and only once the provider reports the instance running at the join's address. Site copies approve their own agent. Setting `server_manager_auto_approve_joins` switches the automatic approval off.

## Machines with no site

Some machines a management node manages host no Joinery site at all — a mail relay, a Docker host. They run the same agent in a **machine posture**: no site root, no local database, no admin page, and no platform release ever delivered to them. Two things follow, and both are served by the same endpoint.

### Keeping the agent current

A machine with a site tree finds its next binary in `public_html/agent_dist`, put there by its own upgrade, and updates without asking anyone. A machine with no site has no such directory, so it fetches from its management node instead: a signed request to `/api/v1/agent/artifact` for the dist manifest, and — when the version differs from what it is running — for the binary for its architecture.

**Nothing about verification moves.** The agent decompresses, checks the sha256, and verifies an Ed25519 signature against the public key compiled into its own binary at build time, exactly as it does for a locally delivered artifact. The management node does not hold the release key and cannot sign an agent, so a plane serving a hostile binary produces a refusal and a recorded rejection. The endpoint is a delivery route for bytes that were always verified on arrival.

The request names a **kind** from a closed set and, for a binary, an **architecture** matched against a pattern. It never names a file: the plane resolves what to send out of its own manifest, so nothing a node sends is read as a path.

### The support bundle

A script-invoking primitive verifies its script against the signed release manifest before running it as root. On a site that manifest is `RELEASE_MANIFEST` and `RELEASE_MANIFEST.sig` at the site root, beside `public_html`; `install.sh` places it there when it lays down a fresh site (bare metal and the Docker image alike, from whichever archive it actually applied) and `upgrade.php` replaces it on every upgrade. A site tree without it refuses every script primitive, `apply_update` included. On a machine with no site there is no release manifest, so there is nothing to verify against and no script primitive can run at all — which would leave the machine's whole vocabulary in embedded Go.

**Only the site that built the shipped agent signs.** The agent verifies against the key compiled into its binary, so the key that built the bundle in `agent_dist/` is the only one whose signatures any node will accept; `agent_dist/manifest.json` records it as `signing_public_key`. `TreeManifestPublisher::authority()` compares that key with the site's own `config/agent_signing_key` and the publish signs only when they are the same (a bundle that predates the record counts as built here when the agent source is on the box). A site that received its agent from upstream — getjoinery, republishing for the beta testers — holds a different key, and a manifest it signed is one its own agent and every node it serves would refuse. Such a site carries forward the manifest it received: into the core archive from its own site root, and for each theme and plugin from the directory that ships, leaving its live tree exactly as upstream delivered it. A site that republishes (it may not mint a release number) also builds each archive from exactly the files that manifest lists, each checked against its hash (`TreeManifestPublisher::republish_artifact()`; see docs/deploy_and_upgrade.md), and rebuilds nothing — the agent, the support bundle and the other binaries ride along as received. A received manifest that turns out to be signed with the site's own key, or that does not verify against the bundle's key, aborts the publish and says so, rather than republishing a tree nothing can run.

### When a node cannot verify its own scripts

Verification fails in two ways, and they are opposite problems.

**A file does not match its signed hash**, or is not listed at all. The manifest is good and is doing its job: the file on disk is not the file that was published. That script refuses, and nothing repairs it automatically — find out why the file differs.

**The manifest itself cannot be used** — absent, unparseable, or signed by a key this agent does not carry. Nothing is known about any file, so every script primitive refuses at once, `apply_update` among them. A node in this state cannot be repaired by sending it work: the upgrade that would deliver a good manifest is refused by the check that is failing, and no non-script primitive writes a file.

The agent recovers from the second on its own. Every ten minutes it asks whether the core manifest is usable; when it is not, it reads the release its site runs from `public_html/VERSION`, asks the plane for that release's signed manifest over the artifact endpoint (`release_manifest`, naming an artifact and a version — never a path), verifies it against the key compiled into its own binary, and writes the pair to the site root. A manifest that does not verify is refused and not asked about again until what is on offer changes. The plane holds no release key and cannot sign one, so serving these bytes gives it no new authority — the same footing on which it already serves agent binaries. `ReleaseManifestSource` reads the pair straight out of the published archive, so any version still in `static_files` can be served; one pruned by retention cannot, and the agent says so rather than retrying silently.

Recovery is driven by `ArtifactManifests.Usable()`, which reports only on loading the manifest. A hash mismatch is raised by `Verify()` and never reaches it, so recovery is structurally incapable of firing on a modified file — the case where replacing the manifest would destroy the evidence.

The manifest is read back into the agent under an 8 MiB cap of its own; the 64 KiB cap that bounds every ordinary plane answer cannot carry one. The claim is the other answer with its own cap: the agent reports the most it reads (`claim_bytes`, 1 MiB from agent 1.45.0), because a long backup chain's jobs outgrow 64 KiB, and the plane holds each job it hands out to that (`AgentChannelEndpoint::claim_body_limit`, `dispatch_refusal`); a claim that reports none is held to 64 KiB. Each file is landed through a temporary file created with `O_EXCL` in the site root, with mode and ownership set on the descriptor rather than by path — the site tree is owned by the web user, so a root process writing there by name is a path a web-layer compromise can redirect.

A verifier is cached only while the manifest on disk is still the one it was parsed from, keyed on size, mtime, mode and inode. One agent build spans many core releases, so a parse held for the life of the process would have a routine upgrade reading as every file having been modified since release, and a manifest broken under a running agent staying invisible while the node reported itself healthy.

Scope is the core release. A plugin or theme carries its own manifest, and a node whose plugin manifest is bad still has a working `apply_update`, so an ordinary upgrade repairs it.

**The state is visible without opening a job.** A node reports `script_trust` on every poll, and a refusal that carries either wording is classified and stamped on the node as it arrives (`mgn_script_trust`, with when it was first seen, the reason verbatim, and the job type that refused). Such a node raises a critical incident (`plane:unmanageable`); its failing backups are a symptom, not a second problem. A node's own report wins over a stale refusal in both directions for the manifest. The report looks at the five deployment files and no other, so a refusal of any other script stays flagged until the job type that refused completes; an absent report — an older agent, a machine with no site whose bundle has not landed yet — is left alone and never reads as healthy. A machine with no site reports on the support bundle, the tree it actually runs scripts from, so it sets and clears the state the way a site node does.

**The deployment files an upgrade replaces first.** `utils/upgrade.php` copies five of its own files (`utils/upgrade.php`, `utils/update_database.php`, `includes/DatabaseUpdater.php`, `includes/DeploymentHelper.php`, `includes/PackageSignature.php`) over the live ones before anything else is checked, so a run that then stops short of a deploy leaves the old release with new deployment files in it. They no longer match the installed manifest, the agent refuses `upgrade.php` as modified, and every later `apply_update` is refused the same way. Three things keep a node out of that state. The upgrade itself puts the replaced files back, and empties staging, when it ends without the live `VERSION` having changed (`DeploymentHelper::restoreSelfUpdateFiles`). The agent, which lives outside the upgrade's process group, keeps a copy of each of the five that matches the installed manifest before every `apply_update` (`/etc/joinery-agent/release-files/`, tagged with the release) and settles afterwards, and on its manifest-check clock, whatever happened to the run: a file that no longer matches while the `VERSION` is unchanged is written back from the kept copy, never over a running upgrade (the upgrade lock is checked) and only with bytes whose sha256 is the one the key-verified manifest lists for that path. And the `restore_release_file` word does the same on demand for a node already in the state: its one parameter names one of the five files, the bytes come from the kept copy or, failing that, from this management node (artifact kind `release_file`, read out of the published core archive of the installed version by `ReleaseManifestSource::read_file`), they are checked against the manifest before a byte is written, every directory is opened relative to the one before it without following links, the file's mode and owner are kept, and the file it replaces is saved under `release-files/replaced/`. It refuses when the file already matches, when the manifest itself is unusable, and when no source has a verifying copy. The node asks this management node for the file **by the sha256 its manifest lists** (the `release_file` request's `sha256`; `ReleaseManifestSource::read_file_by_hash` searches every published core archive, the node's `VERSION` only a hint of where to look first), because the `VERSION` and the manifest can disagree: a deploy that fails its tier and rolls back puts the old code and `VERSION` back under the new release's manifest, and a file served for the `VERSION`'s release is then exactly the one the node refuses. `repair_release_files` is the same for every differing file in one parameterless word, and the node runs it itself: recipe **`release_files`** checks the five files every ten minutes (`upgrade_preflight`'s `manifest` and `files` facts; an unusable manifest is unknown, the healer's case, never repaired) and repairs after two failing checks, so a node in this state is upgradeable again within about twenty minutes with no one's hand on it.

**The poll names the files.** A node whose manifest is good but whose five deployment files differ reports `script_trust` as `untrusted_file`, and `script_trust_files` lists the ones that differ (`mgn_script_trust_files`). An empty list is the node saying it looked and every file matches, which is what clears the state once a file has been put back; an agent that does not report the list cannot clear it. While an upgrade holds the site's lock (`uploads/.upgrade.lock`) the poll gives neither, so the stored state stands: `upgrade.php` replaces itself minutes before the manifest changes, and a file differs on the way through. The agent asks the kernel's lock table (`/proc/locks`), never the lock itself, because `upgrade.php` refuses to start when it cannot take the lock at once; `upgrade_preflight`'s lock check and the start-up recovery of the deployment files ask the same way. A refusal and the poll's list are two findings held side by side (the refusal in `mgn_script_trust_reason` and `mgn_script_trust_job_type`, the list in `mgn_script_trust_files`), the worse one setting the state, and each clears only itself: a job completing releases its refusal and leaves the files the poll still names, and the poll's empty list releases the files and a refusal of one of them, never a refusal of another script. The node page and the `plane:unmanageable` incident list the files, with **Put them all back** (`repair_release_files`, one job for every differing file) on a node whose agent reports it and **Restore signed copy** beside each on a node whose agent reports `restore_release_file`.

**The publishing site's own changes are not tampering.** The site that signs releases runs from the tree it signs, and its live manifest is rewritten only when it publishes, so a script committed or edited in between is refused until the next publish. When a file refusal, or a poll naming differing deployment files, arrives for this management node's own record, this site is a git checkout that re-signs its own tree on publish (`TreeManifestPublisher::signsItsOwnTree()`), and the refused file is either identical to its last commit or one the web server cannot write, nor any folder from it up to the site root (`NodeMonitorHealth::beyond_this_process()`), the state is `unpublished_file` instead (`NodeMonitorHealth::changed_here_after_publish()`, and `file_changed_here_after_publish()` for each file the poll names, every one of which must pass). Agent results are handled by the web server, so the second test asks whether the web user could have made the change, which is the compromise the file check exists to catch; the agent still refuses to run the file. It shows on the node page as a warning and raises no incident, and clears the same way after the publish: the job type completing, the poll's empty list. Any other node, a tree the web user can write (every node a release installs), a process that can write the file, or a site that cannot sign its own tree keeps `untrusted_file`.

The support bundle closes that. A publish builds `public_html/agent_dist/support_bundle.tar.gz`: a small tree carrying the scripts those machines' primitives invoke, at site-root-relative paths, with its own `RELEASE_MANIFEST` and `.sig` signed by the release key. A siteless machine fetches it over the same artifact endpoint, verifies the signature against its baked-in key, checks every listed file's hash **and** that the tree holds nothing the manifest does not list, then unpacks it root-owned to `/opt/joinery-agent/tree`. `install.sh docker` also installs the release's own bundle from `agent_dist/` with `joinery-agent bundle install FILE --statement=RELEASE_STATEMENT` (agent 1.75.0), which holds the file to the same checks and the public log and then runs the converge a fetched bundle runs, so a Docker host that never joins a management node has its host timer too. On a joined machine the binary and the bundle are its management node's release: a bundle on offer replaces the one installed whenever their bytes differ, newer or older, as the self-update replaces the binary. Script primitives resolve against that tree when there is no site root; a machine with a site root uses the site root, and a machine with neither refuses as it always has.

**Live proofs of agent words.** A changed agent word runs once on a real machine before the release ships it (specs/fleet_security_patching.md WP7); the publish page warns about one that has not (`AgentLiveProofs`, `agent_live_proofs.json`). The live-tier test `agent_live_proofs_live` runs them (`AgentLiveProofRun`). It builds the agent from the source being prepared and the support bundle from this tree with the release's own publishers, in a scratch site whose only release key is one made for the run, so the binary differs from a release's only in the key baked into it and nothing the fleet trusts signs anything. It makes a throwaway server in the test cloud account (`test_server.php`), installs the agent (`install_agent.sh --siteless`) and the bundle over SSH, joins it to this management node, approves the join as the fingerprint the machine printed, only when it came from the server's own address (the address this plane saw it arrive from, never what the machine reports) under the run's label, and marks the node a test build (`mgn_agent_test_build`). This plane offers such a node none of its own agents or bundles (`AgentChannelEndpoint::OWN_BUILD_KINDS`, answered as a plane with none has), which it would refuse, each refusal an incident. Each word is queued as a job and judged (completed with an answer to read; for `install_security_updates`, an outcome that installed or found nothing to install), and a passing word is recorded with the build's agent version. The node, its join requests, the server, its DNS and the run's key are then removed, whatever happened; each run builds in a fresh scratch site, so a killed run's key is never reused. Only the words in `AgentLiveProofRun::RECIPES` can be proven on a bare machine. The words a site runs (`SITE_RECIPES`: `upgrade_preflight`, `apply_update`, `site_log`) are proven by the live test `agent_live_proofs_site_live`: the agent is built trusting the run's key and the real release keys; a candidate core archive is built from this tree as a publish stages it (`build_candidate`: publish's exclusions, which a safe test pins to publish_upgrade.php's own, the licenses, the config template, install_tools and sysadmin_tools, the run's agent in `agent_dist`, the next version, no release statement), signed with the run's key and checked against it; the newest published release is installed on a throwaway server as the provisioning flow installs a bare-metal site; the run's agent replaces the release's, its dist pinned to its own so it never updates back; and `upgrade_preflight`, `apply_update` onto the candidate (served from the server to itself, the run's key added to that site's trusted keys and the log requirement lifted there alone) and `upgrade_preflight` again run through it, judged by `StagedRolloutRunner`'s own `preflight_verdict` and `gate`, then `site_log` reads the upgraded site's access log. The installed `upgrade.php` self-updates to the candidate's and re-runs it, as every node's upgrade does. A changed word in neither list (`publish_upgrade`, which only the publishing box runs) is proven by hand with `record_live_proof.php`.

The bundle carries the host installers and nothing that reads a site, so a site question never goes to a machine in machine posture: the status processor asks a node for its recovery key only where `ManagedNode::hosts_site()` says there is a site to ask about, the same test the recovery-key page applies before calling a node not applicable. A site primitive that reaches such a machine anyway is refused as a posture, in words the trust classifier leaves alone ("this machine has no site, and its support bundle does not carry ..."), never as a file that fails its release.

The bundle's contents are a deliberate list in `SupportBundlePublisher`, not a directory sweep — every entry is a script some primitive names, and adding one is a visible decision. The Docker host is its consumer: `decommission_site` runs the bundled `remove_account.sh`, `host_report` reads the machine with `host_report.sh`, and `host_converge` runs the bundled host runner (`_plugin_installers_start.sh --machine`) over the two host installers, `host_housekeeping.sh` and `install_host_converger.sh`, with `_tree_trust.sh` beside them and the Cloudflare range list at its site-root-relative path. That is how a machine with no site converges its own fail2ban and installs its own host timer; the site installers (agent, parser jail, vhost, permissions, secrets) are deliberately not in the list, since a site installer on a machine with no site has nothing to install. A script that sources another needs that sibling in the list at the same relative path, and so does every file the functions it calls there use beside them: `setup_ssl.sh` sources `install.sh`, whose certificate retry is armed by `arm_ssl_retry.sh`, so both ship. The safe test `agent_machine_scripts_bundled` follows each bundled script's calls into `install.sh` and fails on a file the bundle leaves out. Binaries the scripts invoke ship under `bin/` with one file per architecture (`bin/<tool>-linux-amd64`, `bin/<tool>-linux-arm64`) and the script selecting on `uname -m`: one bundle carries both, so no machine can end up holding one built for the wrong architecture.

The list runs from the publishing box, so `SupportBundlePublisher.php` is a held path (`ReleaseCommit::heldPath()`, with `publish_upgrade.php`, `ReleaseCommit.php` and every other `*Publisher` class): while it has an uncommitted change, the publish refuses. The scripts themselves come from the commit. A bundle that cannot be rebuilt (a listed script missing from the commit) carries the previous one forward with a warning; a release that would ship no bundle at all is refused, a republish too.

Its version is the hash of its own manifest body, so a publish that changes no bundled script leaves the bundle byte-identical and no machine downloads anything. The plane also advertises the tarball's sha256, which an agent uses only to skip a transfer it already has — what makes the tree runnable is the signature inside it.

Each machine reports the bundle version it holds on every claim, stored as `mgn_agent_bundle_version`. On a machine nobody logs into, that column is the evidence the bundle arrived.

### Enrolling and switching one on

There is no admin page on these machines, so the ceremony is the same one reached from the command line:

```bash
joinery-agent join --management-node=https://plane.example.com [--name=NAME] [--no-wait]
joinery-agent status
joinery-agent enable | disable
joinery-agent leave
```

`join` generates the keypair, sends only the public half, and prints the fingerprint to compare against the pending request on the plane — the same comparison, and the same approval, as a node enrolling from its own admin page. `--name` is what the plane's pending list shows for this machine (default: the hostname, which on a fresh VPS is usually `localhost`). Without `--no-wait` the command waits up to five minutes for the approval; with it, the command returns once the ask is lodged. Either way the running agent finishes the join itself: it watches the staged keypair, and when the plane approves — minutes or hours later — it stores the credential and starts polling without a restart or anyone at the terminal. `install.sh docker --management-node=URL --node-name=NAME` is how a plane-built host asks. `leave` deletes the credential; the running agent sees it gone within seconds and restarts without it, so a `join` after a `leave` is finished the same way. The run switch is the marker file `/etc/joinery-agent/enabled`, which `enable` and `disable` write directly: on a machine with no settings table, the marker is the switch rather than a projection of one.

**A host agent is born at the Docker install and nowhere else.** `install.sh docker` places it (`install_agent.sh --siteless --dist-dir=<tree>/public_html/agent_dist --enable`, the one caller of `--siteless` in the tree, pinned by `installer_contract_test`) after the host's housekeeping, and with `--management-node=URL [--node-name=NAME]` asks to join in the same run; approval on the plane links the host record to the new node. Nothing later installs one: no upgrade, container start, plugin installer or plane job reaches a host that has no agent, and once installed the agent keeps itself current from the plane. A host built before the Docker install placed agents gets one the same way, by running `install.sh docker --management-node=URL --node-name=NAME` once from a current release tree copied onto it; that run's already-installed branch does the housekeeping and the agent install and nothing else. `--siteless` is explicit and never inferred — a missing site config keeps meaning "not my machine, exit 0" for everything else.

### The Docker host as a node

A shared Docker host is a plain ManagedNode in machine posture — paired, addressed and versioned like any node, with no web root and no container name. `install.sh docker` installs the host's own siteless agent as part of the install and, given `--management-node=URL` (and `--node-name=NAME` for the pending list), lodges its join; the machine that runs our Docker is managed by its own agent, which is the only path to certificate renewal or site removal once SSH is gone. The placement record (`mgh_managed_hosts`) stays what it is: which containers live where. One nullable link joins the two worlds: `mgh_mgn_managed_node_id` on the host record names the host's own paired node. Approving the host agent's join sets it, and so does each host report the machine sends (`ManagedHost::link_host_node`, from this plane's own records: the placement record the machine is itself placed in, when it has no host node; otherwise, for a machine that is no record's agent yet, the first record for its address, unless that one has a live host node already, which is never re-pointed), and the host's edit page sets it by hand. That is the routing chain for host-scope work — a container victim's `mgn_mgh_managed_host_id` finds the host record, the host record names the host's node, and the job is addressed there. Sibling containers on a host are found by `mgn_mgh_managed_host_id` and nothing else; `ManagedHost::ensure_for_node()` mints or links the placement record the moment a node needs a container port, so the FK is never absent where it matters. The writers that see every kind of node — the add/edit form and join approval — go through `ManagedHost::place_node()`, which mints a record only for a node that names a container and otherwise just links a record that already exists at the address (a host's own agent landing on the record its containers point at). A bare machine gets no placement record: it is only a node. A site agent running inside a container says so, on its join and on every claim (`container`, agent 1.70.0 and later); the plane then names the container for the site folder in the node's web root and places the node on its Docker host's record (`ManagedNode::adopt_reported_container()`), so a site that joins a management node is grouped under its host the same as one that node created. A node already named and placed is left alone; one named before placement existed and never placed is placed then, on the record for its own address. A machine with no site never says it.

A host record can be deleted (soft) from its edit page — last, deliberately: the delete refuses while any container site still names it as placement, and while its own agent node record is live.

### Removing a container site (decommission)

Removing one site from a shared Docker host is host-scope work — the container, its own `{site}_<name>` volumes (the fifteen install.sh creates, by exact name, so a sibling site named `{site}_<word>` keeps its own), the reverse-proxy vhost and the web root all live outside the container — so it runs as the **`decommission_site` destructive primitive on the host's own agent**. The wire carries exactly one parameter, the site's *name* (`^[a-z0-9_-]{1,50}$`); every path is composed on the host from compiled-in patterns, and the plane cannot express one.

**The site being destroyed approves its own removal.** The host agent locates the victim through two host-owned files — the vhost names the container's published web port, and the config volume's host-side path (`/var/lib/docker/volumes/{site}_config/_data/`) carries its database credential — then connects over the container's published loopback database port (web port + 1000) and stages an approval into the *victim's* settings table, sealed to the *victim's* proven backup recovery key. The victim's own Backups page renders it (`ApprovalChallengePanel`, decommission scope: this site will be destroyed, permanently), with the site's own record of its last completed offsite upload as the load-bearing fact. The ceremony has its own settings rows, HKDF context and plaintext tag (`decommission_approval_*`, `joinery-decommission-approval:`), so a decommission answer and a restore answer can never satisfy each other. The plane is not in the path: the primitive declares no parameter an answer could travel through. The victim's credential is held in memory for the life of the connection, never at rest; everything read from the victim is treated as untrusted display data.

Only after the answer verifies does the host run the bundled, self-verifying `remove_account.sh`, whose output carries its own verdict (`DECOMMISSION_VERIFIED` / `DECOMMISSION_FAILED_VERIFY`). Before it deletes the vhost, the script reads the certificates the vhost names (each `/etc/letsencrypt/live/<name>/` and `/etc/ssl/joinery/<domain>`); once the vhost, the site's certbot-made `<site>-le-ssl.conf` and the vhost's backups are gone, it removes each through its sibling `remove_site_certificate.sh`, which keeps any certificate anything else on the machine still uses or serves, so certbot stops renewing a domain that has left the host. A certificate it could not remove is a warning in the output, not a failed verdict. It removes only a container install.sh made: one running the site's own image (`joinery-<site>`, any tag) with every mount one of the site's own named volumes. Anything else (another image, a bind mount, another site's volume) is refused before anything is removed; a container made by hand is removed by hand. The script touches nothing of another site on the machine: it checks the name's shape, stops or removes a container only of exactly that name (Docker would otherwise fall back to a container ID starting with it), removes the site's volumes by exact name (plus `<site>_postgres_pg<major>`, the old database a rebase keeps, its `pre-rebase` images and `/root/rebase/<site>`), and treats `<site>_test` as a bare-metal site's test twin and `<site>-le-ssl.conf` as its certbot vhost only when no site of that name exists; a `<site>_test` with a vhost of its own is the twin only when that vhost serves `test.<the site's domain>`. On bare metal it drops the site's databases with the password from the site's own config (never a prompt), holds the host converger's lock for the whole removal, removes the host converger when it ran for the site, and checks the databases gone before it says `DECOMMISSION_VERIFIED`. Behind a container's proxy (its container found, or its vhost a `ProxyPass` to 127.0.0.1, as on a re-run after the container went) it drops no host database and takes no test twin. Docker installed but not answering, or not on the script's PATH while its socket or service is there, refuses before anything is removed, and stopping mid-run fails the verdict. Only plain files are cleared as marks, so a quiet bare-metal site's `held/` folder (`_site_state.sh`) is left alone. `build_decommission_node` refuses a container that another live site row on the same host, or on no recorded host, names (compared without case or spaces), since a row's container name is editable. Dispatch marks the victim quiet (`mgn_agent_quiet_time`) so its agent dying with the container reads as a deliberate silence.

**The old machine of a switch-over is removed on the host's proof instead.** After a switch-over the domain points at the new server, so the old container's Backups page cannot be reached to approve anything. For a row in state `retired`, `build_decommission_node` sends `decommission_moved_site` (agent 1.55.0) to the host instead, with the same one parameter and the same script. The host writes a one-time token into the container's `public_html/sm-ssl-probe.txt` with `docker cp`, which runs nothing inside the container. It checks that the container serves the token on its own port. Then it fetches each name its own vhost proxies to the container (the `ServerName` and any `ServerAlias`) over https, with the certificate checked, no redirect followed and a fresh query string. The token coming back refuses: the domain still reaches this site. No answer, a certificate error or a 5xx refuses too, because unreachable is not moved. So does an answer without Joinery's `X-Joinery-Version` header, which `serve.php` sets on every answer it routes (the probe view's 404 included): a CDN edge's challenge page or its cached copy of a live site, or a parking page, is not another Joinery server. An answer below 500 with the header means another Joinery server holds the name; an edge passes the header through on a real answer, so a site that moved behind a CDN still proves. An alias that does not resolve at all passes; the `ServerName` must answer. The token file is emptied either way. A switch-over leaves the old container frozen and then held stopped on its host (`hold_container`), so it can serve no token. When the hold is recorded on the host (`/etc/joinery/sites/{site}/held`) and the engine says the container is not running, the host instead asks each name the same way and needs an answer below 500 with Joinery's header, and none of the name's addresses may be one of the host's own (compared as addresses, IPv4 and IPv6): nothing on the host but its own proxy can answer for those names, and that proxy answers 503 for a stopped container or a suspended site. The hold is no evidence the site moved, since this management node can hold any site container; it only says the container cannot answer. The address rule reads the host's interfaces, so on a host whose public address its provider NATs to it, the header and the proxy's 503 carry the proof alone. A copy onto the same host never passes it (its names resolve to the host), and its old container is removed with the site's own approval. That takes a host agent from 1.80.0 (`JobCommandBuilder::MOVED_SITE_HELD_MIN_AGENT_VERSION`), which `build_decommission_node` requires for every retired row. The proof runs on the host, never here, so this management node alone still cannot destroy a site: it only chooses the word. Because the host's proof passes for any other server answering (a parking page included), `build_decommission_node` also requires this management node's own proof that the domain reaches the site's **new** server, from the last ten minutes (`MovedSiteCheck::new_server_refusal`). That proof can refuse a removal but never allow one the host's proof refuses. The victim needs the routing-probe route (`ProvisionPendingSsl::PROBE_MIN_CORE_VERSION`) rather than the approval panel.

**The page shows where the old machine's domain goes.** Beside the site on the overview of a `retired` container row, `MovedSiteCheck` shows two answers together. The host's: the domain left this container, still reaches it, could not be told, or the container is gone. It is the same proof, run as `moved_site_check` (agent 1.56.0), which reports its verdict instead of refusing and removes nothing; `process_moved_site_check` folds it onto the row's `mgn_moved_check_state`, `mgn_moved_check_detail` and `mgn_moved_check_time`. This management node's: whether the domain reaches the new server (the row named by `mgn_copy_of_node_id`). A `moved_site_reach` job places a one-time token on the new server through its agent (`ssl_probe_place`); `process_moved_site_reach` fetches `https://<domain>/sm-ssl-probe.txt` (certificate checked, no redirect, fresh query string), records `reached`, `elsewhere`, `unsure` or `failed` on `mgn_moved_reach_state`, `mgn_moved_reach_detail` and `mgn_moved_reach_time`, and files `moved_site_reach_clear` to empty the file. The site reads **Moved** only when the domain left the container and the token came back; left-but-not-reaching reads amber with what the domain answered. The page shows the stored answer at once. When it is older than ten minutes, the page asks again through the `moved_site_check` API action and polls until the host answers; **Check again** asks at once. A host check refuses while a removal is open on the host, because both write the same probe file. A verified removal records the container gone (`absent`), which is never asked again. A DNS lookup cannot answer this: a proxied domain resolves to the proxy for the old server and the new one alike.

`build_decommission_node` routes or refuses, each refusal naming the operator's next step: a relay goes through the relay flow; a **bare-metal node is a whole machine** — delete the instance at the provider, then delete the node record; an unpaired host says "pair the host's agent"; a victim below the core release that carries the approval panel (`DECOMMISSION_PANEL_MIN_CORE_VERSION`) says upgrade it first; a victim with pending or running jobs says finish or cancel them. There is no unattended path to destroying a site — a victim that cannot render its own approval does not take this path.

## Incidents

An **incident** is one condition on one node that needs a person, from the moment it starts until it is over. Every incident carries two answers, kept apart:

- **Is the condition still there?** Its source says: *happening now* or *cleared* (`inc_status`, `open` or `closed`). Nothing a person does changes it. A source is the node's agent (a recipe that gave up opens a case, see *The case* under the agent channel, and the node closes it when its check passes) or this management node's own detectors (below).
- **What is being done about it?** A person says: **new**, **snoozed** until a time, **resolved** or **ignored** (`inc_triage`). A new incident starts new, and **Reopen** sets it back to new. A snooze that has ended reads as new again (derived on read: a page view never writes). Ignore covers that incident only; the next time the condition happens is a new incident.

**A fix awaiting its proof.** Resolved while the condition is still happening is someone saying it is fixed before the source has seen it clear (`IncidentRecord::awaiting_proof()`). It reads as *Resolved, waiting for it to clear* and is off the needs-you list. When the condition clears it is simply resolved. If it is still happening a day after it was resolved (`IncidentReconciler::PROOF_WINDOW`), the reconciler sets it back to new, writes *Still happening 24 hours after it was resolved* on its timeline and tells the superadmins again. A fix whose proof takes longer than a day is a snooze instead. A fix to code that only a future event can prove (the incident already over) is not an incident's business: it is a live check on the release.

An incident **needs you** when it is new, or snoozed and its time has come (`IncidentRecord::NEEDS_YOU_SQL`, one definition). Needs you holds only what is still happening: an incident that clears while it is new or snoozed is resolved on the next pass, by no person, with *It cleared before anyone looked, so nothing needs doing* on its timeline (`IncidentReconciler::settle_cleared()`, which covers an agent case the node closed too). The open signal already told the superadmins; if the condition comes back within the hour the incident reopens as new. Severity is **critical** (visitors or data at risk now) or **warning**; an agent case is a warning.

**Where a person sees it.** One line at the top of every admin page (`IncidentNotice`, registered as `fleet_incidents`; it is the only fleet line in the header): how many incidents need you and how many are still happening, beside the number of new problem reports from sites (the bug_reports plugin's own notice yields to it for superadmins). Red when a critical one that needs you is still happening, amber when any incident needs you, blue when only problem reports wait, absent otherwise; it names no nodes, so it stays one line. The **Incidents** menu entry carries the same count (`AdminMenuCounts`), and the node page has an Incidents card and the same count for that node.

**The pages.** The Incidents page has a view per triage (Needs you, the default, then New, Snoozed, Waiting for proof, Resolved, Ignored, All, each with its count; every incident is in exactly one of New, Snoozed, Waiting for proof, Resolved and Ignored), filters by node, type and still happening, and a dropdown to set several selected incidents at once. An incident's page shows its state, its **timeline** oldest first, its **evidence** (an agent case's reason, the failing checks since, what the node tried and its host at the time), the triage buttons, a snooze (4 hours, 1 day, 3 days, 1 week) and a note box.

**The record.** Incidents live in `inc_incident_records` (`IncidentRecord`): the source's columns, plus `inc_title` (the plane's own words, `IncidentTitles`), `inc_severity`, `inc_triage`, `inc_triage_time`, `inc_triage_usr_user_id`, `inc_snooze_until` and `inc_detail` (what a source other than the agent saw). What happened is in `ine_incident_events` (`IncidentEvent`): `opened`, `cleared`, `reopened` (from the source), `triage` and `note` (from a person, with who; a `triage` with no person is the reconciler's: `unproven` in its data sends an unproven fix back to new, `settled` resolves an incident that cleared before anyone looked), and `analysis`. An event records a change, never a tick: an agent case's repeated failing checks stay folded on the incident.

**This management node's own sources.** Besides the agent's cases, conditions this management node detects itself become incidents. Each is an `IncidentSource` registered from the plugin bootstrap (`IncidentSources::register`), named `plane:<name>`, that answers from what is already stored on the node's row and never probes. The sources:

| Source | Holds when | Severity |
|---|---|---|
| `plane:site_down` | uptime monitoring stored `down` (two failing probes in a row); evidence: the site, the check, when it went down, what the check saw. Not while the data root the site lives on (its own machine's, or for a site container its host's) was stopped by hand: that is `plane:data_root_stopped` | critical |
| `plane:data_root_locked` | uptime monitoring stored `locked`: the machine answers 503 with `X-Joinery-Locked`, its encrypted data root waiting for its key; evidence: when, and where to unlock it (this node's page where this management node holds its key, else the machine's own page). Not while the data root was stopped by hand | critical |
| `plane:data_root_stopped` | the machine's latest host report, under six hours old, says its data root was stopped by hand (`data_root_stopped`, `joinery_data_root.sh stop`); evidence: when, the services stopped with it, and how to start it. On the machine only; its sites and site containers raise no `site_down` or `data_root_locked` meanwhile (`IncidentSourceDataRootStopped::stopped_for()`). A site that stops answering, or reads as locked, after its machine's last report has the machine asked for one every two minutes, for ten minutes at most (`report_wanted()`, `HOLD_SECONDS`, the uptime task's short window), and those two alarms wait for it as long, so a machine whose agent cannot answer still raises them | warning |
| `plane:two_layers` | a machine's data sits under two layers of encryption: its data root is encrypted by us and its provider's own disk encryption is on (`DataRootLayers`, from its host report and the provider's layer recorded on its provision). Not on a management node, which never copies itself and keeps both until it is replaced (`DataRootEncryptionOffer::may_keep_provider_layer()`), nor a container (its layers are its host's); its fix is a copy onto a new server born with one layer | warning |
| `plane:security_fix_pending` | a security fix has waited past its time on a machine, by its hourly check (`host_report`'s `security_pending`, each fix with when it was first seen): a critical one (PHP, Apache, OpenSSL, PostgreSQL; not the kernel, whose reboot is a person's choice) over an hour, which the check's at-once install should have taken (critical); any other over a day, which the nightly updates should have taken (warning); or no check passed in three hours while the hourly check is on (warning). Evidence: the fixes and since when, and the at-once install's last outcome | critical / warning |
| `plane:old_code_serving` | a node still serves code an update replaced: a php-fpm on a replaced binary (`check_status`'s `php`) ten minutes after its package was installed, or `needrestart` naming PHP-FPM, Apache or PostgreSQL ten minutes after the automatic updates last ran | critical |
| `plane:cloud_account_key` | the key of the cloud account an encrypted machine is in, which this management node reads the machine's activity log with before it opens it, does not work, has never been checked, or expires within a week; raised on each machine it covers | critical; warning when it only expires within a week |
| `plane:backup_failed` | the last backup this management node ran of the node failed | critical |
| `plane:backups_stopped` | backups from here are not happening (no verified recovery key after a node's first seven days, never, not landing, overdue) | critical |
| `plane:backup_unverified` | a backup was taken but is not proven whole (suspiciously small, incomplete in storage, verification failed, stale or never done) | warning |
| `plane:customer_backups` | this node only: a customer of backup storage has had no run finish here for two nights, or no run it reported verified for eight days (`IncidentSourceCustomerBackups`, the rule a site's Backups page applies to itself); one incident names every such customer | warning |
| `plane:failed_units` | the latest host report names failed systemd units | warning |
| `plane:mail_gateway` | a site on the node has mail apps on and the latest host report's `mail_gateway` shows the gateway not running, not greeting on 993 or 465 for that site, restarted three or more times and up under an hour, or a site's certificate under 14 days; a gateway unit systemd has given up on is `plane:failed_units`' alone | critical when mail programs cannot connect or a certificate is under seven days; warning otherwise |
| `plane:site_code_unverified` | the latest host report shows a site whose `code_check` failed: its code is not what was shipped (each package named in plain words: a file changed, added or missing, not built by Joinery, signed by a key the host does not trust, not in the public log, or a release manifest of another release than the code — `wrong_release`, which a container recreated from an image built on an older release shows, and which an upgrade realigns); or, on a host whose `host_restrictions` refuse unsigned code (or cannot be read), a running site whose check is missing, unreadable or more than two days old. One incident per node, naming every site. It reports only: nothing is turned off (specs/host_restrictions.md D8) | critical for a failed check; warning for a site whose only failure is `wrong_release`, and for a site not checked |
| `plane:certificate` | the served certificate has a problem (`mgn_cert_problem`, see *Certificate monitoring*) | warning; critical under seven days |
| `plane:agent_silent` | a paired agent has not checked in for two hours, and its owner did not switch it off | critical |
| `plane:unmanageable` | the agent cannot verify the scripts it would run as root (`mgn_script_trust`) | critical |
| `plane:agent_update_refused` | the agent refused the agent update on offer (`mgn_agent_update_state`): not in the public log (`unlogged`), failed its checksum or signature (`verify_failed`), or failed to start here and was rolled back (`version_rejected`); it clears when the agent accepts a later release | critical; warning for a rolled-back version |
| `plane:monitoring_broken` | uptime monitoring cannot conclude (misconfigured or stale; `NodeMonitorHealth::evaluate()`) | warning |
| `plane:machine_transfer` | the cloud machine the node speaks for has passed its month's transfer allowance, sent more than three times its daily share in a day, or is on pace past one and a half times its allowance (see *Each machine's transfer*) | critical once the allowance is passed; warning otherwise |
| `plane:release_log` | this node only, where it publishes releases: the next release cannot be logged on Sigstore's public log — a future log whose key nodes do not hold yet (not published by Sigstore, not pinned in `release_keys/log/`, or pinned and not yet shipped by a logged release), or any other reason a publish would be refused (from the **Watch Release Log** task; see *A release is a commit* in `docs/deploy_and_upgrade.md`) | warning; critical inside 30 days of the changeover, and for any other refusal |
| `plane:release_log_blind` | this node only, where it publishes releases: the release-log watch cannot conclude (Sigstore unreachable or unreadable, no conclusion for three days, task missing or off) | warning; critical after two weeks |
| `plane:agent_bundle_refused` | a siteless machine's agent refused the support bundle on offer (`mgn_agent_bundle_state`): its release statement in the public log does not record it (`unlogged`), or it failed its signature or file check (`verify_failed`); it clears when the agent installs a later bundle | critical |
| `plane:release_log_entry` | this node only, where it publishes releases: the public log holds an entry under one of our release statement keys that the release ledger does not account for, or a log key a logged release installed is not the one Sigstore's trusted root lists for that log (from the **Tail Release Log** task; see *Reading the log for entries under our key* in `docs/deploy_and_upgrade.md`) | critical |
| `plane:release_log_tail_blind` | this node only, where it publishes releases: a log nodes trust has not been read up to date for a day, installed log keys not checked against Sigstore's trusted root for three days, or the Tail Release Log task is missing or off | warning; critical after a week |

The three backup sources are three views of one backup judgement (`IncidentBackupVerdict` over `NodeMonitorHealth::fleet_backup_health()`), so the incidents and the node's Backups tab cannot disagree. A failing recipe check is not a source: it is the step before a case, and the node page's Agent box shows it. The **Reconcile Incidents** task (`IncidentReconciler`, every tick, one advisory lock) asks every source about every operational, enabled node: a condition that starts opens an incident, or reopens the newest of its source if that cleared within the hour (new again unless it was ignored); one still holding has its title, severity and evidence refreshed with no event; one that is gone clears with an event. An active incident on a node that is removed, disabled or in an install state (a site held stopped among them) is cleared, saying the node is no longer watched, and nothing opens on such a node. Disabling a node is a person saying this plane no longer looks after it; several sources read columns that froze when its agent went, so watching it would reopen the same incidents every tick. Deleting a node permanently deletes its incidents with their timelines, and waits for a running pass (it takes the same lock), so a pass never opens an incident on a node being deleted under it; each full pass also deletes any incident whose node row is gone, as a node deleted outside its model leaves behind.

**Telling people.** An incident that opens or reopens, or a fix sent back to new for want of proof, dispatches one signal, never one per tick: `incident.opened` for a warning, `incident.opened_critical` for a critical one, addressed to every superadmin. Notify puts it on each one's bell; email is on by default for critical and off for warning, and each superadmin can change either on the notification preferences page. An ignored incident that reopens tells nobody.

**A node's alerts.** Whether a node's incidents tell anyone is the node's **Alerts** setting in Connection Settings (`mgn_alerts`, `ManagedNode::sends_alerts()`): **Automatic** (empty) is off for a box hosted in our test cloud account and on for every other node; **On** and **Off** say so for that node alone. A node whose alerts are off still opens, refreshes and clears its incidents, which wait in the inbox and count in the header line as any other; it dispatches no signal, so nobody gets a bell, an email or a push. Test cloud boxes are made and removed by the dozen, and nearly every one starts with a condition nobody means to fix (no verified recovery key), so they are quiet unless someone turns a box's alerts on to watch it.

**Analysis.** Where the Joinery AI plugin is active, an incident's page has **Analyze**: a model reads the incident (title, timeline, the source's evidence) and the node's facts (release, agent version and recipes, uptime, host report, recent jobs) and says what it thinks is wrong. Pressing it records an `analysis` event marked running and starts a CLI worker (`cli/analyze_incident.php`, `IncidentAnalyst::run`); the page shows it running and refreshes until it is done. The model may ask the node for evidence with one tool, `observe`, from a fixed menu of the agent's read-only words (`host_report`, `disk_usage`, `unit_journal`, `site_log`, `check_status`), at most three requests within ten minutes. Each is an ordinary job built by `JobCommandBuilder` with its own checks (the owner's log-access switch, the compiled unit and log lists), redacted on the node. Anything else is refused and recorded. It answers with `conclude`: the likely cause in plain words, how sure it is (low, medium, high), the evidence it used, and one next step from a closed list (restart a service, run a backup, read a log, run a status check, a person needs to look), stored on the event and shown escaped. It never acts. The model comes from joinery_ai's resolver (purpose `incident_analysis`, the site's selection policy, local first, tools required, the person's private-content consent as the trust floor), under joinery_ai's monthly token ceiling, and its cost-bearing tokens count toward that ceiling (`IncidentAnalyst::usage_since`, registered with `CostGuard`). Node text reaches the model inside joinery_ai's untrusted envelope. An analysis whose worker died stops counting as running after fifteen minutes.

**Writes.** Every triage change and note goes through `IncidentTriage`: the pages post to themselves with the admin CSRF token, at 8, and the `incident_triage` API action (`/api/v1`, 8, not callable by the AI agent) takes the same `do` values (`resolved`, `ignored`, `new`, `snooze_4`, `snooze_24`, `snooze_72`, `snooze_168`) and notes. Setting the triage an incident already has records nothing. Everything a node or a note said is escaped where it is shown (`IncidentViews`), and nothing it said becomes a link.

### Each machine's transfer

A cloud provider adds every machine's outbound allowance to one account pool, so the pool alone hides one machine running far past its own share. **Watch Machine Transfer** (daily, `MachineTransferWatch`) reads every account this management node holds a credential for (`HeldCloudAccounts::drivers()`: each of ours, and each connected customer account through `ProvisionCustomerCloud::account_driver()`), lists its machines (`CloudMachineTransfer::listInstances()`), and matches them to nodes by address: the node's host (resolved when it is a name), its server's host where it is a site on a shared one, and the addresses its agent reported at its newest approved join (a host name behind a proxy resolves to the proxy). A node is on one machine: where its addresses match more than one (a site that moved keeps the old machine's address on an old join), a match by host beats a match by a join's report, and of equals the newest machine wins. A connected account whose token cannot be refreshed is reported in the run's message and never marked for re-granting by this read. For each machine a node runs on it reads the provider's figure (`getInstanceTransfer()`; on Linode `GET linode/instances/{id}/transfer`, which needs only `linodes:read_only`) into one `MachineTransfer` row (`mtr_machine_transfers`): this month's bytes, the allowance (prorated for a machine created during the month), the overage, and the read before, whose difference is a day's rate. Every node on the machine points at the row (`mgn_mtr_machine_transfer_id`); a failed listing unlinks nothing. A machine no node runs on is not read. On the first two days of a month, a figure no lower than last month's last reading is taken as the provider's month not yet started again and is not recorded.

The provider's figure counts about the payload, and Linode leaves out traffic to another machine in the same data center over IPv6, in both directions.

One node speaks for each machine (`mtr_mgn_managed_node_id`): the server's own node where it has one, else the lowest-numbered site. `plane:machine_transfer` opens on that node only, naming the other nodes on the machine, so a server of twelve sites is one incident. Its conditions (`MachineTransferWatch::conditions()`), worst first:

| Condition | Holds when | Severity |
|---|---|---|
| allowance passed | this month's figure is past the allowance | critical |
| a day far above its share | over a window of at least 20 hours since the read before, more than three times the machine's daily share (the allowance over the month's days, or the days since it was created) and at least 1 GB | warning |
| on pace | at least three days into the month, the month projected from the figure so far is past one and a half times the allowance | warning |

A reading more than three days old, or from another month, raises nothing. The incident clears when the next day's rate is ordinary or the month turns. The node's Overview shows the machine's month as a Health gauge against its allowance, with when it was read and any read error.


## Reverse DNS

A node whose server this management node created has a **Reverse DNS** box on its Overview tab: what each of its addresses answers now, and **Set Reverse DNS**, which names them through the cloud account the server is in (`NodeReverseDns::set()`, action `set_reverse_dns`). A provider takes only a name that points at the address, so the hostname's A record must give the IPv4 and its AAAA the IPv6; this is checked first, and the refusal names the record to create. A server with an IPv6 chooses what to name: both addresses (the IPv6 left as it is when the name has no AAAA giving it), the IPv4 alone, or the IPv6 alone (`ADDRESSES_BOTH`, `ADDRESSES_IPV4`, `ADDRESSES_IPV6`). The one address named alone must take. After an IP swap, the IPv6 alone is how the site's name goes on the copy's IPv6 while the moved IPv4 keeps the name it brought.

## Adopting a cloud server

Reverse DNS and an IP-swap switch-over reach a node's server only through its provision record (`cvp_customer_cloud_provisions`), and creating a server is what writes one. A node that joined from another management node runs on a server this one did not create, so it has none. **Adopt cloud server** on its Overview tab (`CloudServerAdoption`, action `adopt_cloud_server`) records that server.

The panel appears in place of Reverse DNS on a node with no provision holding an instance, unless it is a container site: a shared server belongs to the server's own node. It names the machine the daily transfer watch matched, when it has one. On confirm, adoption asks the providers again:

- It lists the servers on every account this management node holds (`HeldCloudAccounts::drivers()`: each of ours and each connected account).
- It matches the node by address, by the same rule as *Each machine's transfer*. A match by the node's own host beats one by its join's report.
- Exactly one server at the strongest match is adopted. None, or more than one (each is named), adopts nothing. So does a server different from the one the panel showed, or a server another node's provision already records.

Adoption writes an admin-origin provision in install mode `adopted`, status `done`:

- the instance id, its IPv4 and IPv6, and its region;
- hosting mode `operator` with the account of ours it was found in, or `customer` with the connected account;
- that account's provider (`cvp_provider`), whose driver the IP swap and reverse DNS then use. An account whose provider has no driver here adopts nothing;
- the link to the node.

Nothing is installed and nothing on the server changes. An adopted server is never one somebody bought, so it is never billed, trialled or handed to a customer. Removing the node removes its provision with it.

A later join from the adopted server's address is checked with the provider like any join from a server this management node created.

## Fleet security

`/admin/server_manager/fleet_security` shows every machine and site container and whether it is patched (`FleetSecurityView`), red rows first:

- A machine's row comes from its host report: its operating system, the security fixes its hourly check found waiting (`security_pending`), what still runs replaced code (`services_needing_restart`), when automatic updates last ran, and a reboot owed.
- A site container's row comes from its own status check (its PHP, and whether its php-fpm runs the binary installed) and from its host's report.
- Every node's own status check also names the packages a package run left half done (`dpkg_unfinished`), which nothing else reports, since a site container has no hourly check of its own. Its operating system is its host's base image, so the fixes it waits for are the ones the host's base check found (`site_refresh`).
- **Red:** old code is serving (a php-fpm on a replaced binary, or PHP-FPM, Apache or PostgreSQL among what needrestart names, as the `plane:old_code_serving` incident counts it), or a critical fix waits (PHP, Apache, OpenSSL, PostgreSQL), or one was left half done.
- **Amber:** other fixes wait for the next run, a kernel fix waits for the night and then a person's reboot, a reboot has been owed over a day, no check has passed in two hours, any other service runs replaced code until it restarts, or a package run was left half done (**Install security updates now** finishes it). Packages half done under a run that still holds its lock are its work in hand, for two hours; past that the run is shown as perhaps stuck.
- **Grey:** nothing has been reported to judge by; a node with no agent (the relay, the DNS servers) says it has none.

**Install security updates everywhere** (`FleetSecurityRunner`, a tracked `FleetSecurityRun` in `fsr_fleet_security_runs`, moved by the `AdvanceFleetSecurityRuns` task and by loading the page):

- Each machine runs its own `install_security_updates`, one at a time.
- Then each container host whose nightly refresh is on replaces its site containers once, as its **Refresh now** (`refresh_site_containers`): a fresh base when a fix waits, then each site due, down about 12 s each. The step names the containers it covers, and waits for a host report whose run began after the host was asked and has ended, asking for a report every five minutes. A run already under way when asked is waited out, and the host asked again. A run that replaced what was due, or found nothing due, passes, noting a site passed over or held; a failed check for fixes, a base that did not build, and a run that stopped on holds halt it, as does a host report ten minutes after the asking that shows none under way and none recorded since (the run ended without recording what it did, or never began), and one not seen to end within its own bounds.
- A site container whose host does not replace its containers runs its own `install_security_updates` after its host. One whose host is replacing its containers after all is left out, or skipped while its install is open: its new container carries the fix.
- A node whose updater failed stops the run, naming it. One with nothing to install, or with another package run in the way, is recorded and passed.
- A node switched off, or whose agent lacks the word, is left out by name.
- Stop takes effect between nodes.

## Machines

`/admin/server_manager/machines` lists every machine we pay for (`MachineInventory`), from three sources:

- **Managed:** each node that is not a site in a container, in whatever install state (a dormant copy and a retired source are machines we pay for, and the row names the state), with its operating system from its own host report. A node with no agent (the relay and the DNS servers, watched from here and never given one) reports nothing, so a note written on it (`fhm_instance_ref` `node:N`, from its row's **Write down**) says what it runs.
- **Not managed:** each server in our own cloud accounts this management node holds a key for that no node runs on (`MachineInventory::lists_account()`: not the test account, whose servers are thrown away nightly, and not a customer's Connect grant). A server is matched to a node by its addresses, as the transfer watch matches them. It shows its IPv4, or its IPv6 where it has none, and the release a note on it says it runs, or else the release of the image it was made from.
- **Written down:** each machine written down by hand (`FleetHandMachine`, `fhm_fleet_hand_machines`, at `/admin/server_manager/machine_edit`). That is a machine in an account no key here reads, or a note on a listed server that is not managed on purpose: what it is for, whose it is, what it serves, its release and a retire-by date. Saving it confirms it as true now, and **Confirm** does that alone.

The verdicts:

- **Red:** an Ubuntu release past standard support (`MachineInventory::UBUNTU_SUPPORT_ENDS`, which holds the first day of the earliest end Canonical's pages give), or a retire-by date passed.
- **Amber:** a server no node manages and nothing written down explains; a node with no agent and nothing written saying what it runs; a machine written down and not confirmed for 90 days; a written-down machine that serves nothing written and has no retire-by date; a retired source, kept for the way back; or a node whose install failed.

An account that cannot be read is named at the top, since its servers are missing until it can be.

## Staged rollout

`/admin/server_manager/rollouts` applies the release this management node serves across chosen nodes, **one node at a time, in the order given**, and moves on only when a node's structured apply result (the `apply` in its `apply_update` job's result) says: the job completed, the deploy tier passed, the release's version is reported, and nothing rolled back. The first node that misses any of the four **halts** the rollout, which names the node and the reason; the nodes after it are never started. Put the node you would least mind breaking first.

A rollout is a tracked record (`StagedRollout`, table `srl_staged_rollouts`): its steps, each with its node, its stage, its machine, its apply job and a verdict (`pending`, `running`, `passed`, `failed`, `skipped`), survive a reload. A rollout runs in **stages**: a stage starts only when every node of the one before has passed, and within a stage the nodes go at once, **one at a time on each machine** (`FleetBackupPolicy::machine_key`: container sites on one Docker host are one machine), because an upgrade's syntax sweep, unpacking and tests load the machine it runs on. A rollout started on this page gives every node a stage of its own, so it goes one node at a time; Deploy to all managed nodes uses two (below). `StagedRolloutRunner::advance()` moves the current stage on each tick: it judges the steps under way, starts a waiting step on each machine that has none running, and moves to the next stage when every step has passed. It is called by the **Advance Staged Rollouts** scheduled task every tick and by the page on load. The first miss stops anything further from starting; the applies already under way are still judged, the page says the rollout is halting meanwhile, and it ends halted, naming the first node that missed. **Stop** ends it at once: an apply already running finishes on its node, and nothing further is queued. One rollout runs at a time. A node whose site has a copy being made, switching over, or switched and not yet kept waits, its step saying why (`StagedRolloutRunner::copy_hold()`): the copy must run its site's release, and a way back still open returns the old server to its data. A dormant or stopped copy holds nothing. A node is refused up front when it hosts no site, has been removed, or does not report `apply_update` (the standard *needs a newer agent* state); a node below the version floor is offered, since `apply_update` is what raises it. A node whose `upgrade.php` sends no structured result halts the rollout: an apply that cannot be judged is not a pass.

**Readiness before each apply.** When a node's agent reports `upgrade_preflight`, the rollout asks it whether an upgrade would stop before it started, and queues the node's apply only after a good answer. The word checks the signed manifest, the five deployment files, the `VERSION`, the upgrade lock, room to stage a release, and that the active theme is on disk, and answers pass or fail with a short reason for each. A failing check halts the rollout at that node with the check's reason and nothing changed on it; a check that does not finish in fifteen minutes halts it too. A node whose agent does not report the word is applied directly. A node that refuses its apply because its upgrade script no longer matches the signed release halts the rollout with a reason naming the file and saying another release will not repair it.

**Pages to check.** A rollout may name up to five pages (`StagedRolloutRunner::MAX_PROBE_PAGES`), one a line on the form, each a path with its viewer before it (`admin /admin/admin_users`; anonymous otherwise), kept on the rollout (`srl_probe_pages`). A node that cannot render a page (`page_probe`) is refused up front. Each node renders them as `page_probe` jobs before its apply is queued (`probes_before`) and again once the apply passes its gate (`probes_after`); a round that does not finish in fifteen minutes halts the rollout. `StagedRolloutRunner::probe_verdict()` halts at the node when a page got worse with the apply: it answers an error status (400 or more, or none) it did not answer before, raises more PHP errors than it did, or fails to load an asset it loaded; and when a page's check after the apply did not run. A page with no check before is held to being clean after.

**Deploy to all managed nodes.** The publish form's *Deploy to all managed nodes* checkbox starts a rollout of the new release as soon as the publish completes, so publishing and upgrading the fleet is one click. The choice is recorded on the publish job (`deploy_all` in its parameters, never sent to the node); the **Advance Staged Rollouts** task, and loading the publish job's page, start the rollout (`StagedRolloutRunner::start_pending_deploy_all()` / `deploy_all_for_publish()`). The rollout covers every node that hosts a site, except this management node itself, which is the release's source, in two stages (`StagedRolloutRunner::deploy_all_plan()`). The first is the sites marked **Test releases here first** on their node's settings form (`mgn_rollout_first`); with none marked, the first site by name, so a release never goes everywhere untried. The second is the rest, plain sites then management nodes, so a management node on a shared machine takes its turn last: one that breaks takes its own fleet's dashboard with it. A test site that fails stops the rollout before the rest are touched. The checkbox, the node's Updates tab and the publish job's page say which nodes are in each stage (`deploy_all_description()`). A disabled node, or one the rollout would refuse, is left out and named, both under the checkbox and on the job page. What became of the deploy is written back onto the publish job (`deploy_all_outcome`: `started` with the rollout's id, or `refused` with the reason), and the job page shows it. A publish that fails deploys nothing. If another rollout is running, the deploy waits for it to finish. A completed publish whose rollout has not started within 24 hours (`DEPLOY_ALL_WINDOW_HOURS`) is never started. A publish whose version is not the newest published release is refused, because it is not what a node's apply would pull.

## Site copy

A node's **Copy** tab puts its whole site on another server and keeps it there, **dormant** and current (`specs/site_copy.md`): the database, the files, custom themes and plugins, the sealed secrets (which open there, because the copy takes the source's `secret_box_key`), the certificate and the DKIM keys. It is made from the backup chain this management node takes of the site, so every copy is also a real restore of those backups. The source does not change. The copy serves no visitors, sends nothing and runs no scheduled task (the quiet state, *The quiet state* in [Deploy and Upgrade](../../../docs/deploy_and_upgrade.md)); switching over to it is a separate action.

**What copies where.** Any site copies to any of four kinds of place, and its address follows it by whichever way works there:

| Source ↓ / Target → | Linode, made here | Hetzner, made here | Brought server | Container on an engine host |
|---|---|---|---|---|
| Own server, alive | proxy; IP swap (same account and region); DNS; by hand | proxy; Hetzner IP swap (same project and location); DNS; by hand | proxy; DNS; by hand | proxy; DNS; by hand |
| Container, alive | proxy; DNS; by hand | proxy; DNS; by hand | proxy; DNS; by hand | proxy; DNS; by hand |
| Another management node, alive | refused: copied from its backups only | same | same | same |
| Dead site (any kind), from backups | proxy; IP swap if both are at Linode here; DNS; by hand | proxy; Hetzner IP swap if both are at Hetzner here; DNS; by hand | proxy; DNS; by hand | proxy; DNS; by hand |

The management node that runs the copy never copies itself, the domain never changes, and a site on a server of its own is the only site there. `site_copy_matrix_test.php` pins this table to the code. `site_copy_live_test.php` (tier `live`, run by name after copy code changes) copies one site for real the way most copies go: a source at the oldest release this management node serves that a copy accepts, behind the Cloudflare test zone, onto a Linode made here in the test account, then the way back at ready, then the switch-over by the proxy, kept (`SiteCopyLiveRun`).

**Alive or dead is the source's** (`SiteCopy` `scp_from`, whatever the target). A **running site** (`source`) is frozen at the switch-over and copied one last time, so nothing written before the switch is lost. A site whose server is **dead**, or will not come back (`backups`, the tab's "From its backups"), is asked nothing and never frozen: what was written after its newest backup is lost, and the tab shows that backup's age.

**Preflight** (`SiteCopyRunner::source_refusals()`, read-only). A node can be copied when it is a site in the standard layout (`/var/www/html/<site>/public_html`) with an https site address, is a working site, runs release `JobCommandBuilder::COPY_INSTALL_MIN_VERSION` (0.8.497) or newer (the copy installs the source's own release, whose `install.sh` must take every option the copy's install passes: `--outbound-set-by` and an encrypted data root's options onto a new server, `--release-tree-only` onto a host; that release also carries the backup broker a copy run backs the source up through), is backed up by this management node (a site that backs up only to a bucket of its own cannot be copied), has a manager-profile chain of any age (its size sizes the new server), and has no copy already. A **running** one's agent must have been heard from within two hours (`ALIVE_WITHIN_SECONDS`), since it is frozen through it; it must hold a proven backup recovery key, and report `backup_run`, `copy_export` and `site_census` when its copy is made by export (anything but onto a host). Another management node is refused as a running site and copies from its backups. A **container** site's host must ship `site_quiet_host` and `hold_container`: it freezes the site from outside and holds the old container stopped once the copy has the site. A **dead** site's agent key must be on record, since the copy is installed as its copy. A site on a server of its own is refused while that machine serves anything else (`machine_wide_refusals()`): a site container its host report lists, a site its report names (`sites`), or another node on its address; its own test twin (`<site>_test`) does not count. A running one whose last host report does not list its sites (none yet, or older than 1.32) is asked for a status check first; a dead one's last report is all there is. The freeze, the power-off and an address swap act on the whole machine. The tab lists every reason that fails, under each of the two kinds.

**From its backups** (`source_refusals(…, SiteCopy::FROM_BACKUPS)`) asks nothing of the source's agent: not its words, not its reported recovery-key state, only its row, its layout, its release, its agent's key and this management node's backups of it, at any age (`newest_chain($source, null)`).

**Every way the address could move is shown before the copy is made** (`SiteCopyMethods::for_target()`, read-only): for each account a new server could be made in (at the region shown), for a brought server and for a host, the ways that can work and why the others cannot. The proxy where the domain's DNS is at a host that proxies (Cloudflare); an IP swap only between two servers made here at a provider that swaps addresses, in one account and one region, never a shared host's; the site's DNS records where the domain's host has a driver here (`DnsDriverRegistry`); and by hand always. An existing copy's ways are the same answer, with the swap judged from the two servers on record (`SiteCopyRunner::switch_methods()`).

**The new server**, one of two ways:

- **Create it for me.** The tab records an admin-origin `CustomerCloudProvision` in install mode `copy` (`cvp_source_node_id`, `cvp_release` = the source's release), in the cloud account chosen (any of ours, Linode or Hetzner, the default preselected, or a customer's connected one; `HeldCloudAccounts::targets()`), in the region and size chosen: each provider has its own pair of fields, shown for the account chosen, its sizes the plans its driver says the region shown sells (`CloudInstanceTypes::instanceTypes($region)`), the source's own plan chosen where it is at the same provider and the region still sells it, else the smallest with the source's memory. A plan the region chosen does not sell is refused before anything is made, naming the plans it does, as is a region that sells none. Its **encryption** is decided, never ticked (`SiteCopyRunner::copy_encryption()`), and said in one line on the form: in one of our accounts, encrypted by us, sealed to this management node's recovery key, with the provider's own disk encryption off; in its owner's account with the site's recovery key proven (its public half reported by the site and kept on its node, `mgn_backup_recovery_public_key`), encrypted by us, sealed to that key (`cvp_recovery_public_key`), the provider's off; in its owner's account without one, the provider's default, and the form says to prove a key first. One layer, never two: a region where the provider's own encryption cannot be turned off (a distributed region) is refused before anything is made. Nothing of ours while `data_root_encryption_offered` is off. The provisioning pipeline creates the instance and runs `install_node` in mode `copy`; the node row it makes is named `<source> (copy)`, records `mgn_copy_of_node_id`, and a finished install leaves it in state `copy` (`process_install_node`), never working. The copy's agent then asks to join; the tab approves it after the provider confirms the instance runs at the join's address.
- **I'll bring a server.** The tab shows the commands to run as root on any fresh Ubuntu server (`SiteCopyRunner::owner_command()`): fetch the source's exact release from the release stream (`utils/latest_release?version=`, below), install the prerequisites, then `install.sh site --bare-metal <site> … <domain> --enable-agent --management-node=… --dormant --copy-of=<id> --copy-of-key=<source agent key>`. The owner reads the new server's fingerprint with `joinery-agent status` and approves the matching join request on the tab (it lists pending requests from an agent named after the source's site); approving makes the copy's row in state `copy`.

**Onto a host, as a container** (`SiteCopyRunner::start_on_host()`, specs/site_container_engine.md D9). A site is copied into a container on a host whose sites run on the site container engine, from the running site or from its backups. Its runs always take the chain's key from the owner's recovery key (`SiteCopy::runs_from_backups()`); a running site is still frozen and copied once more at the switch-over. The tab's **Copy onto a host** box lists every host with why it cannot take the copy (`host_refusals()`), and names once, together, the hosts with no agent of their own: its agent must have been heard from within `SiteCopyRunner::ALIVE_WITHIN_SECONDS` (it installs the copy and holds it quiet), it must report `site_engine` `podman` (`host_report` 1.26), its agent must ship `site_install`, `site_quiet_host`, `provision_certificate` and `discard_copy`, it must not be the site's own host or hold a site or container of that name, the site's release must carry `maintenance_scripts/sysadmin_tools/site_quiet_host.sh` in a signed manifest this management node holds (`release_carries()`). This management node holds the archive of each release it publishes and of each it upgrades to (`upgrade.php` keeps the one it applied, signed as received, pruned by the same retention: `UpgradeRetention::keep_applied_archive()`, `prune_kept()`); with no archive of the site's release the box says so (`holds_release()`), and a running site must ship the words of a freeze and back up. The copy's row is made at once (`mgn_container_name`, the host link, a port of its own there from `next_container_port()`), and the host's agent installs it (`site_install`, the job in `scp_install_job_id`): it fetches the site's release from this management node (artifact kind `release_archive`), verifies it before anything in it runs (the signature, every file both ways with only the config template unlisted, the version, and the public log where the agent holds its keys), and runs `install.sh site --docker … --dormant --release-tree-only`, which builds the copy from that tree alone, with no core archive, theme, plugin or bundle fetched, and starts it quiet from its first process. The copy's restore brings the site's own code, themes and plugins. Until then the copy's pages, its key page among them, resolve through core's own views (`PathHelper::getActiveThemeDirectory()` answers no theme when the theme's folder is missing). The port the install took and the key it printed for the copy's agent (`SITE_AGENT_KEY`, `scp_expected_site_key`) are recorded, and the copy's join is approved when it comes from the host's address carrying that key (the owner can still approve one from the host by hand). Its run is a copy from backups; the look and key pages are reached at the site's name, pointed at the host by one hosts-file line, since a shared host answers by name only.

**Root SSH keys.** A copy is keyless with root login off unless the operator carries the source's keys. When the source's host report lists root SSH keys (`root_ssh`, from `host_report.sh`), the Create-it-for-me form shows each key's fingerprint and offers a tick to carry them. A key with options (`command=`, `from=`, `restrict`) is shown but never carried. The ticked keys are stored on the provision (`cvp_root_ssh_keys`) and the `retire_install_password` job installs them on the copy's root account; the start is refused if the source's keys changed since the form was drawn. The install form offers "Keep root SSH login" with a box for public keys on the same terms. With no keys, root login is off.

Either way the copy installs the source's own release, because `vendor/` is never in a backup: its dependencies must be the ones the source's code was built for. A copy that has a newer release on its site is stale (`SiteCopyRunner::stale_release()`): a dormant copy's tab says so as soon as the site's release on record moves, **Copy again** and **Switch over** ask the site its release and refuse before anything moves, and the tab says to discard it and copy again. While a copy is being made, is switching over, or has switched and is not yet kept, the site's update waits in a staged rollout (below), so a copy never goes stale under its own run.

**A copy run** starts by itself once the copy's agent has reported its words, and again at each **Copy again**. One agent job at a time, each judged before the next is queued:

1. `host_report` on the copy, then a fit check from both machines' host reports: memory at least the source's, and free disk for the chain staged, the files unpacked and the database loaded twice over, plus 2 GiB (`SiteCopyRunner::disk_needed()`, an estimate from the chain's sizes);
2. `backup_run` on the source, at its own chain policy, so the copy is of the site as it is now, at the release the copy installed; the run copies the chain this backup is in (an increment of the newest chain, or a new one where the policy began one);
3. `copy_export` on the source, which waits for its owner's approval on the source's own Backups page (the tab links there and shows the copy's key fingerprint to compare);
4. `copy_import`, 5. `copy_stage`, 6. `copy_restore` on the copy;
7. `site_census` on the source, 8. on the copy, and `SiteCensus::compare(…, false)`: informational, because the source is live and rows it wrote since its backup are expected to differ. A difference that cannot be explained that way (a sealed secret the copy cannot open, a canary that opens on one side only) stops the run.

**A copy run from backups** asks nothing of the source: `host_report`, `copy_look` (the copy's look path, so the owner can reach its page), `copy_take_key`, `copy_stage`, `copy_restore` and `site_census`, all on the copy. `copy_take_key` carries the chain, its manifest's hash and newest run, and the chain's data key as sealed to the owner's backup recovery key (the recovery recipient of the manifest's envelope, read from backup storage by `SiteCopyRunner::key_request()`; a chain sealed to another recovery key than the site last reported is named instead), and a link to the manifest signed for as long as the job may wait to be claimed. The copy reads the manifest at its provider itself and holds all of that to it, so none of it rests on this management node's word: the bytes must hash to the manifest named, the manifest must be that chain's and seal the very key named to the very recovery key, and its newest run may not be later than the moment the provider stored it. The provider's date (its `Last-Modified`, which no request can set or backdate) is believed only from a provider's own storage host (Backblaze B2, Amazon S3, Cloudflare R2, Wasabi, DigitalOcean Spaces, Linode, Hetzner); elsewhere the page says the date could not be checked. A chain made up by a management node after the site's server died shows a date after it died; the date does not show who made a chain stored while the site still ran, and the copy reads the chain it is sent rather than finding the newest itself. The copy shows that backup on its own **/copy-key** page, which the tab links at the copy's own address through its key look path (`<look path>/key`: the look cookie, then the key page, on a copy whose release is at least `JobCommandBuilder::COPY_KEY_LOOK_MIN_VERSION`; an older copy gets the look link and the key page as two links); the owner pastes the recovery key there, their browser works out the one value that opens that sealed key, and the copy opens it and writes the chain key and the vouch, as an import would. The recovery key is typed only on the copy's own page and never reaches this management node, which carries only the sealed key. The census is judged alone (`SiteCensus::check()`): the canary must open, no sealed secret may be dead, and the sampled offloaded files must answer.

The first step that does not do its part (a failed or refused job, an export declined or not approved in time, a step past its time budget) stops the run, names the step and why, and starts nothing after it. A run that passes leaves the copy **dormant, current as of** that chain's newest run, with the comparison and, from `copy_import`'s result, the **look link**: one hosts-file line and the path that sets the copy's look cookie, so the owner can open the copy privately before anything switches.

**Discard** removes the copy's row from the dashboard, ends the record, and cancels the step it was on: a source waiting for its owner to approve the export sees the job withdrawn within half a minute and takes the request off its Backups page (agent 1.52.0+). The server itself is not deleted (the platform never deletes a cloud instance); the confirmation names it for the owner to delete at the provider, and any escrow key this management node holds for its encrypted data root is retired (`DataRootEscrowSync::let_go()`). A server being created or installed at that moment is left to finish first. A copy onto a host is removed from it by its host's agent (`discard_copy`, the job in `scp_switch`'s `discard_job_id`), which refuses unless the host records the site as a dormant copy and the copy's own state file still says `quiet copy`; one whose install is still running is refused until it ends.

The copy is a tracked record, `SiteCopy` (table `scp_site_copies`), moved by `SiteCopyRunner::advance()` from the **Advance Site Copies** scheduled task every tick and when a node posts a copy step's result, under an advisory lock: a step that passes queues the next in the same call. The tab only reads, and reloads itself while a copy is moving.

**Switching over** makes the copy the site, from a dormant copy that is current, on the Copy tab (`SiteCopyRunner`, specs/site_copy.md steps 7–10). The tab offers each way the address can move (`SiteCopyRunner::switch_methods()`), with why one is not available:

- **Through the proxy** (`proxied_origin`): the proxied records in front of the site (Cloudflare's orange cloud) point at the copy, so visitors follow within seconds and no resolver cache is involved. Each press that touches DNS takes a DNS token for the site's zone in the form, builds the driver for that request (`ProxiedOriginMove::driver_class_for()`, from the domain's NS records) and keeps nothing.
- **By IP swap** (`ip_swap`, `IpSwapMove`): the two servers swap their public IPv4 addresses at their provider, so every record, allow-list and reverse DNS naming the site's address stays true. Both servers must have been created by this management node, at the same provider, one whose driver swaps addresses (`CloudAddressSwap`: Linode, and Hetzner's primary IPs), on one account and in one region; a provider that moves an address only between stopped servers (`CloudSwapWhileStopped`: Hetzner) has the copy powered off before the swap too, and on again after, each address move waited on until the provider says it succeeded; the provider credential is the one that created them (the account of ours they are in, or the connected account's grant, which needs `ips:read_write` and `firewall:read_only`). Two servers in different accounts of ours never swap. IPv6 addresses do not move.
- **By the site's DNS records** (`dns`, `DnsRecordMove`): every unproxied A and AAAA record in the zone naming one of the source's addresses (`ProxiedOriginMove::machine_addresses()`: for a site in a container, its Docker host's addresses too, which is where its IPv6 is known) changes to the copy's address of its family, with a DNS token for the zone typed at each press, at any DNS host this management node has a driver for.
- **By the owner's own DNS change** (`manual`), for every copy: the owner changes the records at their DNS host, and this management node watches them settle as it does its own change.

**What still names the old address.** A move by DNS, by hand or through the proxy gives the site the copy's address, and anything else naming the old server's address keeps naming it (`SiteCopyAddressFacts`). The tab lists, under each of those ways before anything is frozen, and in the switch-over as it started (`scp_switch`'s `address_facts`): each `ip4:` and `ip6:` entry of the site's SPF record that covers one of the old server's addresses, read from public DNS (`PublicResolvers`, TXT), for the owner to change at the DNS host; the copy's reverse DNS, which this management node sets on a server it made once the move settles, and which otherwise is the owner's at the copy's provider (a container's is its host's; through the proxy none can name the site); and a line for allow-lists and firewalls elsewhere, which this page cannot see. An IP swap moves the address itself and lists none of these.

A copy onto a host is never swapped: its host's address is every one of its sites'.

**A running site is frozen** for its final copy, whatever the target (`freeze_steps()`): `site_quiet on` (its maintenance page up, its scheduled tasks held), a container source then held quiet from outside by its own host (`site_quiet_host on`, switchover), a final `backup_run`, the site's vouch, the copy's stage and restore, and both censuses, which must match exactly. The hosts are recorded in `scp_switch`'s `hosts`, and a container source's old container in its `hold`, before the rows swap. The start of a copy onto a host is `take_node_id`, `site_quiet off` on the copy, `site_quiet_host off` on the copy's host, and `provision_certificate` filed on the host that issues for a container (`certificate_issuer_for()`). A copy whose certificate did not travel (a run from backups, or a container source, whose certificate is its host's) is issued one once its name points at it (`copy_carries_certificate()`). A container source's old container is held stopped last (`hold_container stop`), or, while a DNS move forwards visitors through it, once the move settles.

1. **Switch over** (`begin_switch()`). `switch_refusals()` first (read-only): the copy dormant and current, both agents reporting the switch-over's words (`SiteCopyRunner::SWITCH_SOURCE_WORDS`, `SWITCH_COPY_WORDS`; a copy from backups asks the source for none), the same release, the copy's look path known, and the source's newest backup still in the chain the copy holds the key for. Then the chosen way is checked, read-only, and anything that stops it is named with nothing frozen. **By IP swap**, `IpSwapMove::plan()` asks the provider: one public IPv4 on each server, Network Helper on for both (read from the configuration profiles, or the interface settings on the newer interface generation), every provider firewall of the source also on the copy, and no AAAA record of the site naming the source's IPv6; and, from this management node's own records (`IpSwapMove::record_refusals()`), a copy whose data root is encrypted must have an escrow holder, named in its last host report or this management node holding an enrolled key for the identity that report names (`IpSwapMove::opens_by_itself()`), since the swap restarts it. It records each machine's instance, address and reverse DNS, and the site's name. **Through the proxy**, with the token, `ProxiedOriginMove::plan()` reads the zone: every A and AAAA record naming one of the source's addresses (its host, its provision's addresses, and the addresses its agent reported at join, compared as addresses) must be proxied, the site's own name must point only at the source, and the copy must have an address of each record's family. Anything else stops here, naming the record, and nothing is frozen. The tab also says that a firewall letting only the proxy in is not visible from here.
2. **The final copy** (`freezing`). The source's row goes to `switching`, so no automation touches it, and one job at a time: `site_quiet on` on the source (the maintenance page, no scheduled tasks, no mail out), one more `backup_run` (a chain run on the longest interval, `FINAL_RUN_FULL_INTERVAL_DAYS`, so it extends the chain rather than starting one by age), `copy_vouch` on the source, `copy_take_vouch`, `copy_stage` and `copy_restore` on the copy, and both censuses, compared exactly: any difference stops the switch-over before anything moves. The final copy carries no secret and asks no approval: the copy already holds the chain key, certificate and DKIM keys from the last export its owner approved, and a frozen site's owner cannot reach its Backups page anyway. A final backup that started a new chain stops here: the copy holds no key for it, so the owner goes back and copies again.
   A dead source has nothing to freeze and no final copy: its server is powered off first when this management node created it, at any provider with a driver (a local `power_off` step), and the copy is `ready`. A container source shares its server, so the switch-over records its container and its host's node (`SiteCopyRunner::old_container_hold()`, the switch record's `hold`) and stops it later; `switch_refusals()` asks the host's agent for `hold_container`. Any other old server is the owner's to turn off, and the press says so. Every press that cannot be taken back asks in the system modal.

   **By the site's DNS records**, with the token, `DnsRecordMove::plan()` reads the zone; a record naming the site's server in a family the copy has no address of stops the switch, named. For a running site, the copy's address its proof asks at is recorded (`probe_at`), and the address it forwards to with the old machine's address of the same family (its host's, for a container; IPv4 where both have one, else IPv6) (`forward_to`, `forward_from`), and so is its server's power, when this management node created it; a machine whose agent is older than `JobCommandBuilder::FORWARD_MIN_AGENT_VERSION` cannot forward, nor can two machines with no address family in common, and the switch goes ahead without it, saying so (`no_forward`). Last, once nothing else can refuse and before anything is frozen, every planned record's TTL goes to 60 seconds (`lower_ttl()`), each record's old TTL kept in the plan (`ttl_lowered`) with the TTL the DNS host holds afterwards, read back (`ttl_held`): a host may keep a longer one (Linode keeps 120), and the tab says the one it holds.
3. **Move the address** (`ready`, then `move_address()`). **By IP swap**, as local steps this management node does itself: the source's server off, the swap (`POST networking/ips/assign`, both addresses at once), each address's reverse DNS checked and put back if the provider dropped it, this management node's records following the machines (each provision's address, each row's host), the copy restarted so Network Helper writes its new address, and the copy's look path asked at the site's name (`ProxiedOriginMove::prove()`) for up to five minutes. A step that fails before the copy takes the node undoes the swap (`undoing`: the addresses swapped back, the copy restarted on its own, the source powered on, still frozen), and the copy is `ready` again with the reason on the tab. IPv6 addresses never move. Once the copy has started as the site, its IPv6 answers the site's name in reverse DNS when the site's AAAA gives it (`IpSwapMove::name_copy_ipv6()`; the owner points the AAAA there before switching), and the tab shows what happened (`rdns_note`), or, when no AAAA gives it, says to point the AAAA there and keep the switch-over; the moved IPv4 keeps the reverse DNS it brought. When the addresses swap back (an undo, or the way back), an AAAA record of the site still naming the copy's IPv6 is named on the tab, with the source's IPv6 to point it at. **By the owner's own DNS change**, the owner presses once the records point at the copy, and the look path asked at the site's name is the proof; unproven, the copy is `ready` again. A copy from backups has no certificate (none could travel from a dead server), so its proof asks without checking one (the look secret is the proof), and its start ends with `provision_certificate` on the node once the name points at it, then, for a container source, `hold_container stop` on the host: the old container stopped and kept stopped until it is removed. It is last, so the site, which already runs on the copy, never waits on it. A host that answers and does not stop it halts the start, naming it, and **Try starting the copy again** repeats the start. A host that does not answer (its agent not heard from within `SiteCopyRunner::ALIVE_WITHIN_SECONDS`, or the job not taken up within the step's wait) is not waited for: the step is `owed`, the switch-over ends and can be kept, and the stop stays queued on that host, which runs it if it answers again. The switch record's `hold_owed` says why, and the Copy tab says so, after the switch-over is kept too, until the stop has run (`owed_hold_ran()`, from the job's result or the task tick, which finds such copies by `scp_switch`'s `hold_owed` key). Once the host has seen the old container gone (Permanently Delete Site, or its moved-site check reading `absent`), the task tick cancels a stop still queued, so a site installed there later under the same name is never the one stopped. Going back cancels it too, and starts the old container when its host had already taken the stop up (`old_container_restarts()`). **Through the proxy** (with the token again), the records are planned afresh and each is pointed at the copy (`ProxiedOriginMove::move()`, the address alone, so the proxy and the TTL stay; a failed write puts back the ones already written). Then `ProxiedOriginMove::prove()` asks `https://<domain><look path>` through the proxy for up to a minute, with a fresh query string each time: only the copy answers it, with a 303 that sets its look cookie. Unproven, the records move back and the site stays frozen, ready to try again or go back.
   **By the site's DNS records, or by hand**, the copy is first asked its look path at its own address under the site's name (`ProxiedOriginMove::prove()` with the name pinned to the copy's address), so nothing moves toward a copy that does not answer; then, with the token, the records change to the copy's addresses (`DnsRecordMove::move()`; a failed write puts back the ones already written), or, by hand, the owner has changed them. The start that follows (`dns_start_steps()`): the copy takes the node and starts; the machine answering the copy (its own, or its host for a container) believes the old server's forwarded addresses (`trust_forwarder trust`, for `FORWARDER_TRUST_HOURS`); the old server, now the retired row, forwards every visitor whose resolver still names it to the copy, over https, any other http request but the ACME challenge sent to https first (`site_quiet forward`, and for a container `site_quiet_host forward` on its host first); then a copy whose certificate did not travel is issued one, since the issuer's own resolver may be one that still names the old server.
4. **The copy starts** (`starting`). `take_node_id` on the copy, answered with the row swap; then `site_quiet off` on the node, which is now the copy's machine. The node is `switched`: it keeps its id, slug and history on the new machine, and the old machine's row is `retired`.

**The move's watch.** A switch by DNS or by hand that forwards, or that powers the old server off, is watched (`scp_switch`'s `watch`): every tick, `advance_watch()` asks three public resolvers over DNS-over-HTTPS (Cloudflare, Google, Quad9; `PublicResolvers`) for each moved name and type. The move has **settled** when every resolver names the copy's address and none the old, and one old TTL has passed since the change (two days at most, `DnsRecordMove::MAX_WAIT`); two days after the change it has settled whatever the resolvers say (`by_time`), so a resolver that cannot be asked never keeps the old server forwarding. The tab says what it waits on. On settling, a copy whose server this management node made answers the site's name in reverse DNS at its IPv4, and at its IPv6 when the site's AAAA moved to it (`NodeReverseDns`, each address checked against the record the resolvers gave for it; not before: a provider takes only a name that resolves to the address), and the tab shows what happened (`rdns_note`). Then the old server is let go (`settling`, `settle_steps()`): the copy's machine stops believing it (`trust_forwarder untrust`, since its address goes back to its provider's pool when the server is deleted), and the old server stops forwarding: powered off when this management node created it, a container source's old container held stopped, and any other old server quiet again (`site_quiet on`), its maintenance page in place of the forward. A settle that stops (an agent that does not answer, a provider that refuses) offers **Try letting go of the old server again** (`retry_settle()`, every settle step again) or Keep as it is. Every machine also drops any forwarder past its end on each housekeeping run (`trust_forwarder.sh expire`), so a trust a settle never took back ends after `FORWARDER_TRUST_HOURS` anyway. Keep is refused while the move is watched.

Local steps (`SiteCopyRunner::LOCAL_STEPS`: powering a server, the swap, the probe, the row swap back) take no agent job. A press that lays them out starts `utils/advance_site_copy.php`, a short worker that advances the copy every few seconds while its current step is a local one, so a machine's restart is followed in seconds rather than at the next task tick.

**The way back** (`go_back()`), offered from the freeze until the switch-over is kept, for every switch of a running site. When the address moved, it moves back first (through the proxy with the token, a record someone has since pointed elsewhere being left alone and named; by IP swap, the addresses swapped back, the copy restarted; by the site's DNS records with the token, the records back and their old TTLs with them, and the TTLs back even when the records never moved; by hand, the owner changes them back: the tab names each record with the copy's address and the site's own before the press (`manual_records_back()`), and afterwards, on the copy's ended banner, each one DNS still names the copy for). Before the swap, the site only starts again (`site_quiet off`, its host lifting a container's freeze first), and the copy stays a dormant copy, current if its final copy finished. After the swap, the copy's machine is quieted while it is still the node (and held off the network by its host, for a copy onto a host), the rows swap back, the copy's machine stops believing the old server where it did, the old server is powered on where a swap or a settled move powered it off (or asked to), an old container held stopped starts again, and the site starts again where it was. The copy is then discarded, with any escrow key held here for it retired: its agent took the site's node id, so it can no longer reach this management node. A copy onto a host is held stopped on its host instead (`hold_container stop`), with what was written on it. Its row stays on the dashboard, retired and named for it (`<site> (copy's container)`), and the tab says so: Permanently Delete Site on that row removes it from its host on the host's proof that the domain has left it, as it removes an old container. Anything written on the copy since it started does not come back, and the button says so in the system modal before it posts. A dead source's copy goes back only before it takes the node, and leaves the old server as it was: after the swap there is no running site to go back to.

**Keep the switch-over** (`finish()`) closes the way back, once a DNS move has settled (or its settle stopped): the retired row leaves the dashboard, its escrow key held here is retired, and the tab names the old server for its owner to delete at its provider. A switch by the site's DNS records asks the token once more, and puts each record's old TTL back (`DnsRecordMove::restore_ttl()`). A switch by IP swap whose copy's IPv6 is not yet named in reverse DNS asks again (`name_copy_ipv6()`), for an AAAA the owner pointed at the copy after the switch; still unnamed, the tab says to name that address alone with **Set Reverse DNS** (see [Reverse DNS](#reverse-dns)), so the moved IPv4 keeps its name. A container's old row is named instead, to be removed from its host with **Permanently Delete Site**, which proves the domain left rather than asking the unreachable site to approve (see [Removing a container site](#removing-a-container-site-decommission)). After a discard or a kept switch-over, the tab keeps naming the server to delete for a week, with what going back noted (an AAAA record still naming the copy's IPv6). A kept switch-over names the old server Keep named, which the copy records, so removing the old row afterwards changes nothing. A kept switch-over's old container is named on the site's Copy tab for as long as its cleanup is unfinished (`SiteCopyRunner::cleanup_left()`): until it is removed, and then while its host still reports a certificate for its domain, with **Remove it from the host** beside it.

The row swap (`SiteCopySwap`) moves only the machine columns (`SiteCopySwap::MACHINE_COLUMNS`: the agent key and what its agent reports, the host, the SSH fields) the provision links and the escrow rows (`dre_mgn_managed_node_id`: a machine's unlock identity is on its own disk), leaving the site's own columns on the node. The other row is named for what it holds: the site's name with (copy), then (old container) or (old server) once it is retired, and (copy) again on the way back. It is made only while answering the copy's `take_node_id` result (`JobResultProcessor::complete_take_node_id()`, from `AgentChannelEndpoint::handle_result`), which answers `node_id_taken`, and the copy changes its identity only on that answer, so a swap the copy does not hear about cannot happen. The copy's run moves on after the swap, in the same request. The old machine is not told: it goes on signing as the node, with the key that is now the retired row's (and after going back, the copy's machine signs as the node with the copy's row's key). While the switch-over is live, a request that fails against the node it names and verifies against the key of that node's copy's row is that row's (`AgentChannelEndpoint::moved_machine()`; the body's `node_id` is compared with the id signed as, and a job handed out is addressed to that id, the only one the machine accepts a job for), so the old machine still takes a DNS move's forward and, once it settles, its quiet, and the copy's machine its untrust on the way back. No other row or key is tried, and once the switch-over is kept or discarded nothing is.

## Job Types

| Job Type | Description | Destructive |
|----------|-------------|-------------|
| `install_report` | How the node's first-boot install went, as the `install_report` **observe primitive**: whether it finished, how its DNS and certificate steps ended, warnings and errors, and the tail of the install log (`/var/log/stackscript.log`, or cloud-init's), read off the marker lines the installer prints. Covers an install that reached the agent: one that died before the agent was installed, or a machine nobody has paired yet, has no agent to ask and its log is reachable only from the console | No |
| `host_report` | The machine, as the `host_report` **observe primitive**: failed systemd units, the expected units and their state, fail2ban jails with ban counts, SSH auth failures in the last 24 hours (a count only, never a username or an address), sshd posture as `sshd -T` prints it, disk, memory, swap, reboot-required, the kernel's boot id (`boot_id`, a new one each boot) and the unattended-upgrades last run. `security_pending` (host_report 1.37): from the machine's hourly check (`security_check.sh`, a timer host housekeeping installs on every machine), when it checked and whether it passed, when the package lists were last fetched, and each package a security run would install (from a `-security` pocket or a repository `51joinery-platform-repos` names) with its installed and candidate versions and whether it is critical (PHP, Apache, OpenSSL, PostgreSQL, the kernel), or `none` where the check has not run; `services_needing_restart`: what still runs code an update replaced, from `needrestart` in list-only mode where it is installed, else each of the machine's own binaries `/proc` names `(deleted)` (`JobResultProcessor::host_report_security_pending`, `host_report_services_needing_restart`). On a host that declares a data root, `data_root_script`: `installed_version` (the copy a run's unit starts, `/usr/local/sbin/joinery-data-root`, or `none`), `release_version` (this release's `joinery_data_root.sh`), and `consumers`, each service the script names as living on the data root (its `CONSUMERS`) with `drop_in` (its `joinery-data-root.conf` drop-in is written) and `installed` (the unit exists here); `none` where the host declares no data root. `data_root_stopped`: a data root stopped by hand (`joinery_data_root.sh stop`), `{at, services}` from `/run/joinery/data-root.stopped`, null while it is not stopped. Where the mail apps gateway is installed, `mail_gateway`: its version, its unit's state, systemd's automatic restarts and how long it has been up, its open connections on 993 and 465, and for each site turned on its mail name, whether a TLS connection under that name reaches an IMAP and a submission greeting (`ok` or `no_answer`), and the days left on its certificate; `none` where it is not installed. On a Docker host the report lists up to 100 site containers (`HOST_REPORT_MAX_SITES`; a multi-tenant host takes 50), read by one `docker inspect` for all of them, and asks every running site at once whether it answers, so a host's report costs its slowest site. Each site container (its name is its `SITENAME`) carries `started_at`, `memory` (`used_bytes` — everything its cgroup holds but the inactive file cache — `peak_bytes`, `limit_bytes`, `oom_kills`), `cpu` (`usage_usec`, `limit_millicores`), `pids` (`current`, `limit`), `net_tx_bytes`, `disk_bytes` and `code_check`, read from the container's cgroup, its network namespace, and one `du -s` over every site's named volumes under the command timeout; a ceiling is a number or `none`. The counters run from `started_at`, so on intake `JobResultProcessor::host_report_container_rates()` compares each container with the report it replaces and stores `since_last` (`seconds`, `cpu_millicores`, `net_tx_bytes`); a container that restarted in between is measured from its start, and the first report, a counter that went backwards or an older node's report has none. `outbound_limits` is the machine's outbound connection limits (`outbound_limits.sh`, the host's `joinery-limits` unit): `state` (`on`, `off`, `refused` with a `reason` code such as `resolver_not_loopback`, `absent` where the machine has none, `none` inside a container, `unknown`; `on` with `reason` `nft_refused` means the last change was refused and the table before it is in force, which the Machine tile says in amber), `since` (when their drop counters began, which is the last boot) and `web_user_dropped`; while `on`, the figures in force: `figures` (the machine's `ceiling_mbit`, a number or `none`, `conn_rate`, `conn_burst`, `open_conns`, and `set_by`, `plane` where this management node set them), `web_ceiling_mbit` (the web server's user's ceiling, lowered where a bare-metal site asked for less) and `sites` (each limited site's four figures in force, after its own run spec and its own lower setting); each site container carries `outbound_dropped`, the packets its limits dropped since then, or `none` where it is not limited. The counters belong to the machine, not the container, so `since_last.outbound_dropped` (and `outbound_limits.dropped_since_last`) is the difference while `since` holds, the count itself when the counters began between the two reports, and absent otherwise. `code_check` is the site's own `config/code_check`, read from its config volume, which each converge writes on a host that refuses unsigned code (`site_housekeeping.sh`): `state` (`ok` or `failed`), `at`, and `failures` (each `kind` — `core`, `plugin`, `theme` or `check` — `name`, `verdict` and `file`); `none` where the site keeps no such file, `unknown` where its config volume was not found or the file is not in the form the site writes. `host_restrictions` is what the machine refuses its sites, from `/etc/joinery/host_restrictions/restrictions` (`{"unsigned_packages":"refuse"}`): `none` where it has no file, `unknown` where the file is there and cannot be read as written, which the sites read as every restriction on. Inside a container, `memory` is the site's own cgroup out of its limit, or out of the whole server where it has none. The node runs `maintenance_scripts/sysadmin_tools/host_report.sh` from its own tree, verified against the release manifest, with no argv and no stdin; every list and string is capped in the script, a fact it cannot read is the string `unknown`, and the plane caps the object again on intake (`JobResultProcessor::sanitise_host_report`) before storing it in `mgn_last_host_report`. Its own word beside `check_status` (the site), stored in its own column; both are queued on the same cadence by the uptime pass | No |
| `site_log` | The last lines of one of the site's own log files, as the `site_log` **observe primitive**: `file` from a closed list compiled into the agent (`error`, `cron_scheduled_tasks`, `joinery_ai_worker`, `install_executor`, `host_converger`, `access`, resolving under the site's `logs/` directory, plus `postgresql`, which resolves to the newest `postgresql-*-main.log` in `/var/log/postgresql` — the one directory outside the site tree this word reaches, and the only place a node records a connection dying or a write being refused. An error line there can carry the statement that failed and the values bound to it, which is the same grade of exposure as the site's own error log: behind the same switch, redacted by the same redactor, capped the same way. ), `previous` to read the most recent rotation instead of the current file (never a compressed one), `lines` 1 to 200. The node tails the file byte-bounded, redacts it, and returns `{file, previous, present, size_bytes, modified_time, lines_returned, truncated, text}`; a file the site does not have is `present: false`, not an error. The access log (agent 1.93.0+; the plane offers it per value, `SITE_LOG_ACCESS_MIN_AGENT_VERSION`) is never returned as written: the node parses each line (Apache's `combined`, or the site vhost's `joinery_timed`, which adds the request's duration in microseconds after the byte count) and rebuilds it as `[time] METHOD PATH STATUS BYTES DURATION_US`, dropping the client address, Referer, user agent and query string, turning any path segment outside letters, digits, `-`, `_`, `.` and 64 characters, or one of 20 or more characters with letters and at least two digits (a token's look), into `<cut>`, and cutting everything after `/s/` (a share link's token is its capability); a line that does not parse is counted in `unparsed_lines`, never carried. Refused on the node while the owner's `agent_log_access` switch is off. Primitive only; the plane mirrors the list in `JobCommandBuilder::SITE_LOG_FILES` so the picker offers what the node accepts | No |
| `site_log_count` | How many times each of a compiled list of problem markers appears in the site's error log since a time, as the `site_log_count` **observe primitive**: `since` (unix seconds) is its only parameter. The markers are compiled into the agent and mirrored in `JobCommandBuilder::SITE_LOG_COUNT_MARKERS`: `serve.php` missing (`Failed opening required`), `Primary script unknown`, `Permission denied`, `PHP Fatal error`, a logged `CRITICAL` or `ERROR`, `Undefined column`, `TRANSACTION_LEFT_OPEN`, `[GET_WRITE]` and `[GET_MUTATION]`. The node reads `error.log`, then `error.log.1`, then `error.log.N.gz`, newest first, until it reaches a line at or before `since` (at most 15 files and 1 GiB), and returns per marker its count, first and last time and at most three redacted sample lines, with `covers_since`, `covered_from` (the oldest line read) and, when the read stopped short, `not_covered` saying why. This is how a check after an upgrade asks whether something appeared since then: `site_log`'s last 200 lines stop short of the upgrade on a busy site, and this word says so instead of answering from part of the window. The Logs box offers it as **Count problem lines**; the job page shows the counts under a line saying whether they cover the window. Refused on the node while the owner's `agent_log_access` switch is off; its samples age out with the other log excerpts. Primitive only | No |
| `log_table_tail` | The newest rows of one of the site's own log tables, as the `log_table_tail` **observe primitive**: `table` from a closed list (`logins`, `requests`, `events`, `form_errors`, `webhooks`), `rows` 1 to 200. The node runs a compiled query per table with a compiled column list — no SQL, table or column name crosses the wire, and the member's IP address, the submitted form and the webhook's raw payload are not in any list — orders by the table's time column, redacts every text value, and returns `{table, rows_returned, columns, rows, truncated}`, adding rows until 48 KiB. Refused on the node while `agent_log_access` is off. Primitive only; mirrored in `JobCommandBuilder::LOG_TABLES` | No |
| `unit_journal` | Why one unit is in the state it is in, as the `unit_journal` **observe primitive**: `unit` from a closed list compiled into the agent (the four expected units bar sshd, the agent itself, and the housekeeping units — `man-db`, `unattended-upgrades`, `logrotate`, `apt-daily`, `apt-daily-upgrade`, `fstrim`, `e2scrub_all` — and the mail apps gateway, `joinery-mail-gateway`), `lines` 1 to 200. The node runs `maintenance_scripts/sysadmin_tools/unit_journal.sh` from its own tree, verified against the release manifest, and returns `{unit, load_state, active_state, sub_state, result, exit_status, last_run_unix, lines_requested, lines_returned, journal}` — `result` and `exit_status` are usually the whole answer. The script re-validates the unit against its own copy of the list, and the agent redacts the journal before it leaves the machine (`ScriptSpec.Redact`). sshd is deliberately not on the list: that journal is who connected and from where, which `host_report` counts and refuses to quote. Refused on the node while the owner's `agent_log_access` switch is off. Primitive only; mirrored in `JobCommandBuilder::UNIT_JOURNAL_UNITS` | No |
| `reset_failed_unit` | Clear systemd's failed record for one unit, as the `reset_failed_unit` **operate primitive** (agent 1.41.0+): `unit` from the same closed list as `unit_journal`. The node runs `maintenance_scripts/sysadmin_tools/reset_failed_unit.sh`, verified against the release manifest, which re-validates the unit against its own copy of the list, runs `systemctl reset-failed <unit>.service` — never without a unit, which would clear every unit on the machine — and returns `{unit, before, reset, after}`, each state being `{active_state, sub_state, result}`. It clears a record and changes nothing running: a unit that is still broken fails again on its next start, and the next host report names it again. No log-access switch: it reads nothing. Deliberately not part of `host_converge`, because clearing a failure is a choice, not housekeeping. Primitive only | No |
| `install_security_updates` | Install now the updates the machine's own automatic-update policy allows, as the `install_security_updates` **operate primitive** (agent 1.87.0+): the packages its nightly unattended-upgrades run would take (Ubuntu's security pocket, and the outside PHP and PostgreSQL repositories `host_housekeeping.sh` names), never a general upgrade, never a package or option from the wire, and never a reboot. **No parameters**. The node runs `maintenance_scripts/sysadmin_tools/install_security_updates.sh`, verified against the release manifest, which waits up to ten minutes for another package run, refreshes the package lists, runs `unattended-upgrade`, and returns `{outcome, reason, apt_update, upgrade_exit, upgraded, upgraded_count, truncated, reboot_required, services_needing_restart, repaired, reloaded, still_on_old_code}`: `outcome` one of `installed`, `nothing_to_install`, `failed`, `busy`, `unavailable`; `upgraded` each package that changed as `{package, from, to}` (`from` empty for a new one), at most 200; `services_needing_restart` from `needrestart` in list-only mode, or `unknown` without it. A package's own scripts restart the service they replace where systemd runs it; afterwards every php-fpm master still running a binary an update replaced (`/proc/PID/exe` `(deleted)`, as in a site container, which has no systemd) is reloaded with USR2, which re-executes it without dropping a request: `reloaded` and `still_on_old_code` name the binaries. A package run left half done (`dpkg --audit`) is finished first with `apt-get -f install`, which names no package (`repaired`). A run that installed anything queues a `host_report`, so the Machine card shows a pending reboot. A machine word: the support bundle carries the script. In a site container, what it installs lasts until the container is recreated on a fresh image. Offered as **Install security updates now** beside when security updates last ran, and on a site container in its **PHP** card | No |
| `refresh_site_containers` | Start a container host's nightly replacement of its site containers now, as the `refresh_site_containers` **operate primitive** (agent 1.88.0+). **No parameters**: which sites are due is the host's own reading. The node runs `maintenance_scripts/install_tools/site_refresh.sh start`, verified against the release manifest, which starts `joinery-site-refresh.service` (the service the nightly timer starts, so the run keeps its own bounds, lock and log) and returns one line: started, already under way, or refused where the timer is not installed or is off. The run checks the base, builds it fresh when Ubuntu has a security fix for it, then replaces each due site one at a time (`installation.md`, *When Ubuntu ships a fix*). A run started queues a `host_report`, whose `site_refresh.running` shows it under way; what it replaced is in the next report. A machine word: the support bundle carries the script. Offered as **Refresh now** beside the site containers' OS line | No |
| `disk_usage` | Where the disk went, as the `disk_usage` **observe primitive**: the filesystem's used, total and available bytes, the site tree's biggest directories to depth two (twenty at most, biggest first, paths relative to the tree), and one total each for a compiled list of machine directories (`/var/log`, `/var/lib/postgresql`, `/var/cache`, `/var/backups`, `/var/lib/docker`). **No parameters** — the tree comes from the script's own location and the machine list is compiled in — and **sizes only**: a directory name and a byte count, never a file name, a count, a time or an owner. `du -x` so a walk never leaves the filesystem, under `nice` and `ionice` because walking a large tree on a small box costs the machine something; a walk that could not read everything reports `partial: true` rather than a total that is quietly too small. No log-access switch: it reads no log and no content | No |
| `listening_sockets` | What the machine listens on, as the `listening_sockets` **observe primitive** (agent 1.76.0+): each TCP socket that listens and UDP socket that is bound, with its protocol, family (`ipv4`, `ipv6`, or `any` for `*`), address, port, the program that holds it and the systemd unit its cgroup names. **No parameters**, at most 100 rows (`truncated` says when there were more), never a connection, a remote address, a pid or a command line. `maintenance_scripts/sysadmin_tools/listening_sockets.sh`, verified against the release manifest; inside a site container it answers for the container's own network. The job page lists them; `JobResultProcessor::sanitise_listening_sockets()` keeps only rows whose address is an address and whose port is a port | No |
| `net_health` | Whether the machine is dropping packets or connections, as the `net_health` **observe primitive** (agent 1.93.0+). **No parameters.** `maintenance_scripts/sysadmin_tools/net_health.sh` reads the kernel's counters twice, ten seconds apart, and reports each as its total since boot and its change over the interval: accept-queue overflows and drops, SYN-queue drops, backlog drops, retransmission timeouts and aborts, segments sent again, failed attempts and resets, packets the kernel dropped for want of CPU time (`softnet_stat`), and packets the default-route interfaces dropped or missed; a counter the kernel lacks is left out, never 0. Also the connection table (`nf_conntrack_count` of `_max`, or `absent`), the connections on ports 80 and 443 by TCP state, and each web listener's queue against its limit. Counts only: never a remote address, another port or a process. Host only: inside a site container it refuses (`refused: container`, exit 2), because a container's network is not where its edge connects, and the plane refuses a container site's node before asking. `JobResultProcessor::sanitise_net_health()` keeps the named counters and adds a verdict line (*No packets or connections dropped in the last 10 s*, or what is dropping); the job page shows it above the counters | No |
| `web_workers` | Whether the web tier is out of workers, as the `web_workers` **observe primitive** (agent 1.93.0+). **No parameters**: its one argument is the site's own domain, which the agent reads from the site's config (`ExecEnv.SiteDomain`), never from the wire. `maintenance_scripts/sysadmin_tools/web_workers.sh` asks two status pages the site vhost answers to local callers only (`default_virtualhost.conf` 2.10), over https and then http, with the site's name pinned to 127.0.0.1: Apache's `/server-status?auto` (busy, idle, slots, the scoreboard by state) and PHP-FPM's `/fpm-status?json` from its own status listener (`pm.status_listen = /run/php/joinery-fpm-status.sock`, written by host housekeeping): active, idle, the listen queue (requests waiting for a worker), the most waiting, how often the ceiling was reached. It adds the pool's configured ceiling (`pm.max_children` from the active PHP version's pool files) and how often PHP-FPM's log says the ceiling was hit in the last day. Never `?full`, which lists each worker's request; only named numbers pass. On a machine with no site, apache and fpm are `not_reported`; a status page that does not answer is `unknown`. Asked wherever the site's PHP runs: a container site's own node for its container | No |
| `site_state_report` | What every site state directory on the machine holds, as the `site_state_report` **observe primitive** (agent 1.86.0+), **no parameters**: each directory under `/etc/joinery/sites` (a bare-metal site's own, a container's own inside it, and on a host the host's side of each container site) with every record in it to three levels, by name, kind, size, mode, whether root owns it and when it last changed, at most 100 sites and 64 records each (`truncated` says when it cut the answer short). It reads the first line of the plain records alone (`state`, `copy_of`, `forward`, and a host's `quiet` and `idblock`), and prints it only when it is at most 64 bytes of letters, digits, spaces and `._:-`; the look secret, run spec, source key, vouched runs and history are named and sized, never read, and a link is reported as one, never followed. On a machine with a site it also gives the agent's own reading of it (`reading`: live, a dormant copy of which node, or frozen for a switch-over), the one that decides which words run. A record a clearing should have removed (a kept copy's `copy_of`) shows here. Offered under **Machine** on Overview as **Site state records**; the job page lists each site's records. `JobCommandBuilder::build_site_state_report()` | No |
| `container_inventory` | Every container on a machine, as the `container_inventory` **observe primitive** (agent 1.76.0+): its name, `install_sh` (install.sh made it: its name is a plain site name and its `SITENAME` is its name, the rule removing, holding and restarting a site keep), its image, state, mounts (each its type and, for a volume, the volume's name; a bind's source is never read) and `run_spec` (whether `/etc/joinery/sites/{name}/run_spec` exists). **No parameters**; one `ps` and one `inspect` on the engine this host's sites run on (`install_tools/_site_engine.sh`: the site container engine, Podman, where it is installed, else Docker; named in `engine`), whose template filters the environment to `SITENAME` inside the engine. Podman's `localhost/NAME` image is reported as `NAME`, as install.sh gave it. A name that is not a plain word is listed reduced and never inspected. `containers` is `none` without the engine and `unknown` when it does not answer; the list is held under its own byte budget (`truncated`). Its own word rather than keys in `host_report`, whose hundred sites already fill most of the agent's 64 KiB. The job page marks each container install.sh did not make: nothing on this management node touches it | No |
| `reboot_machine` | Reboot the machine, as the `reboot_machine` **operate primitive** (agent 1.76.0+): **no parameters**; `maintenance_scripts/sysadmin_tools/reboot_machine.sh` schedules `systemctl reboot` twenty seconds out on a transient systemd timer (`joinery-reboot-machine`), so the job reports first, and reports a reboot already scheduled rather than adding one. It refuses, with a code and a sentence and nothing scheduled, in a site container (`container`), with no systemd (`no_systemd`), while an encryption, its undo or a re-key of the data root runs (`data_root_run`), on an encrypted data root that names no escrow holder (`no_escrow`: the boot would wait for its recovery key), while dpkg or apt runs (`packages`), and while the host's installers or the data root script hold their lock past thirty seconds (`installers`). This management node refuses the first, third and fourth itself before queuing (`JobCommandBuilder::reboot_refusal()`). The result keeps `boot_id_before`; until a host report carries a different boot id the uptime pass asks for one every `RunNodeUptimeChecks::REBOOT_REFRESH_SECONDS` (two minutes), and that report records `came_back_at` on the job (`JobResultProcessor::reboot_seen()`). It loses no data, so it is operate, not destructive | No |
| `disable_root_login` | Turn root SSH login off on the machine for good, as the `disable_root_login` **operate primitive** (agent 1.95.0+): **no parameters**; `maintenance_scripts/sysadmin_tools/disable_root_login.sh` writes `PermitRootLogin no` in its own drop-in, `/etc/ssh/sshd_config.d/00-joinery-0-no-root-login.conf`, which sorts first so nothing after it reopens root login (where `sshd_config` includes no `sshd_config.d`, the line goes first in the main file), checks it with `sshd -t` (a refused configuration is put back as it was: `sshd_refused`), reloads sshd only when its unit (`ssh` or `sshd`) is active, and is done only when `sshd -T` reads `permitrootlogin no` (else `not_effective`, or `reload_failed`). It reports whether any `Match` block exists, which can let root in for some address or user whatever the global value. Nothing removes the file; other accounts' logins, root's password and keys are untouched, and open sessions stay up. It refuses in a site container (`container`). This management node refuses a container node (naming its host's node) and a machine whose install password is still `held` or `retiring` (`JobCommandBuilder::disable_root_login_refusal()`); the **Turn off root login** button is in the Security box, behind the system modal. A completed run on a machine whose provision is `kept` or `retire_failed` retires it (`JobResultProcessor::process_disable_root_login`: the sealed copies and the error cleared in one direct update), with a `break_glass_root_login_off` event naming the job's creator, shown on the node's Emergency root access box as **Root login turned off by the agent**. Operate, not destructive | No |
| `spam_learning_status` | Whether members' spam corrections reach the spam filter, as the `spam_learning_status` **observe primitive** (agent 1.76.0+): counts only, from the Mailbox plugin's `SpamLearning::status()`. Rows marked spam or not spam in the last seven days, split into taught, waiting for the keyless pass, waiting for their owner's window, and never taught because they were polled over IMAP; the corpus's token count, its newest token and the spam and good messages it has learned from; and whether filing and learning are on. `{"mailbox":"inactive"}` where the plugin is off. **No parameters**; `maintenance_scripts/sysadmin_tools/spam_learning_status.php`. Never a subject, a sender, an address or a row id | No |
| `host_converge` | Run `host_housekeeping.sh` — fail2ban's jails and drop-ins, the trusted-proxy list, the rest of the host's daily housekeeping — as the `host_converge` **operate primitive**: on a site, `_plugin_installers_start.sh --only=host_housekeeping.sh` under the runner lock, the same root runner the host timer uses, touching neither the converge stamp nor the timer's last-run record; on a machine with no site, the bundled runner in `--machine` mode, rooted at the support bundle, which runs housekeeping and the host timer installer together. The wire carries the name and nothing else; both arguments are constants compiled into the agent, picked by the machine's posture (an empty site root), never a parameter, and the runner refuses any name outside its `CORE_INSTALLERS`, a site name under `--machine`, and a site installer under `--machine --only=` anyway. On a siteless machine the agent also runs this word once, on its own, each time a new support bundle is installed — the machine's twin of a site's path trigger, and how the host timer first arrives on a host nobody pressed a button for. The runner exits 0 on every path, so `JobResultProcessor::process_host_converge` reads the transcript: `host_housekeeping.sh: ok` is green (on a container node the installer reports that fail2ban is the host's and does nothing, which is a complete answer); a WARNING, a missing or untrusted installer, a lock it never got, or silence is red with the reason. A completed run queues one `host_report` for the node. Primitive only; the repair step recipe `fail2ban` calls | No |
| `agent_report` | What would restart this agent if it stopped, as the `agent_report` **observe primitive**: whether systemd is supervising this very process with a unit that restarts it, and whether the cron keepalive is installed, executable and switched on; and (agent 1.87.0) whether anything starts it after a reboot (`starts_at_boot`: the keepalive's `@reboot` line, or the unit's `multi-user.target.wants` link); the same four files `restart_agent` reads before it will exit, so the two never disagree. No process runs, no parameters, no file content travels. The check word of recipe `agent_supervision` | No |
| `agent_converge` | Run `install_agent.sh` — this agent's own supervision: the unit file, the cron keepalive, the enabled switch — as the `agent_converge` **operate primitive**: `_plugin_installers_start.sh --only=install_agent.sh` under the runner lock, one constant compiled into the agent and never a parameter. Refused on a machine with no site, since the support bundle carries no `install_agent.sh`. Under a job marker (every plane job and every recipe attempt sets one) the installer writes supervision and defers any swap or restart of the running agent to its own signed self-update. The repair word of recipe `agent_supervision` | No |
| `check_status` | Disk, memory (inside a container, the site's own cgroup out of its limit, or out of the whole server where it has none), load, uptime, PostgreSQL, version and database list, the site's recorded plugin checks (`plugin_checks`, agent 1.42.0+), and its plugins (`plugins`, agent 1.76.0+): each not uninstalled, by name, with the version its recorded `plugin.json` gives, whether it is switched on, and `plg_status`; the management API's stats endpoint carries the same list. With agent 1.81.0+, `release_trust`: for the site and for the agent, the release and statement keys in the trusted set and the authority over them, each by the first 12 hex characters of its sha256, with `recorded` (a release recorded the set; false is the keys compiled into the agent or the site's key files, whose authority is the whole set) and `set_by` (the release that set it); the stats endpoint does not carry it, since only the agent reads the agent's set (`JobResultProcessor::status_release_trust`, `specs/release_key_rotation.md` R6). With agent 1.88.0+, `php`: each installed `phpX.Y-fpm`, `-cli` and `-common` package with its version, read from dpkg's status file, and each php-fpm master with its binary, `running_current` (false when an update replaced that binary on disk after the master started, so the old code is still serving; absent when `/proc` would not say), when it started and when its oldest worker started (`JobResultProcessor::status_php`); the node page shows it as the **PHP** card, with **Old code serving** in red. With agent 1.90.0+, `dpkg_unfinished`: the packages a package run left half done, as `dpkg --audit` lists them (a package flagged `reinstreq`, or `unpacked`, `half-configured`, `half-installed`, `triggers-awaiting` or `triggers-pending`), read from dpkg's status file, as `{count, packages, in_progress, since}`: the first 20 by `name` and `state`; `in_progress`, whether apt or dpkg holds its lock now (asked of the kernel with `F_GETLK`, which takes nothing; absent where neither lock file could be read); `since`, when the holder started. Absent where there is no status file (`JobResultProcessor::status_dpkg_unfinished`). On a node with an agent this is the `check_status` **observe primitive**, which collects all of it without running a command; on a Joinery site without one, the management API; on a machine with neither, a **probe** of what the machine publishes about itself | No |
| `backup_database` | Run `backup_database.sh`, optionally upload to cloud | No |
| `backup_project` | Run `backup_project.sh` (DB + files + Apache config), optionally upload | No |
| `list_backups` | List backup files on local server and cloud target | No |
| `upload_backup` | Push one existing backup file from the node to its cloud target; keeps the local copy | No |
| `delete_backup` | Delete backup files from local, cloud, or both | **Yes** |
| `restore_database` | Restore a backup file on a node | **Yes** |
| `restore_project` | Restore a full project `.tar.gz` (files + DB) in place on an existing node, then reconcile it to that machine. Runs `restore_project.sh --force --domain <domain>`, which cascades `--non-interactive` into `restore_database.sh`. Every file in the archive must exist under the project directory afterwards or the restore fails and names what is missing | **Yes** |
| `restore_chain` | Restore a node from an incremental backup chain — what the fleet's scheduled backups actually produce. Fetches the chain manifest, recovers the chain key on the node from the node's own `backup_site_key`, downloads every artifact the manifest names up to the chosen run, then runs `restore_chain.sh`, which verifies each artifact against its recorded size and hash **before writing anything** and applies them in order. A chain another server made (the site's server before a switch-over) is staged without a key, and the approval opens it with the recovery key the operator pastes (agent 1.85.0+; `docs/backups.md`, *Approve it on the node*) | **Yes** |
| `apply_update` | Run `upgrade.php` on the node. The node's `upgrade.php` ends every run with one `APPLY_RESULT:` line, which `JobResultProcessor::apply_result` reads into the job's result as `apply`: `version_before`, `version_after`, whether the pipeline updated itself and re-ran, each migration with its outcome and rows affected, each schema change, each plugin synced with its version before and after, the deploy tier's verdict with any failed test names, whether and at which step it rolled back and whether the schema was left ahead of the code, and the duration — counts and names, never row data. The job page renders it as a card above the transcript, which stays for forensics. A node whose `upgrade.php` predates the line records `apply: null` (not reported) | **Yes** |
| `restart_unit` | Restart one of the host's expected units (`fail2ban`, `apache2`, `php-fpm` — the versioned unit the host runs — `cron`, `postgresql`) or the mail apps gateway (`joinery-mail-gateway`, agent 1.74.0), as the `restart_unit` **operate primitive**: never the agent (which restarts only through `restart_agent` and its proof) and never sshd. The node runs `restart_unit.sh`, which re-validates the unit, restarts it (no stop, disable or mask), and returns `{unit, absent, before, restarted, after}`. A restart the node did not accept is a failed job; an accepted one queues a `host_report`. The repair of recipes `service_health` and `mail_gateway`. Mirrored in `JobCommandBuilder::RESTART_UNIT_UNITS` | No |
| `restart_container` | Restart one of a Docker host's site containers, as the `restart_container` **operate primitive** on the host's own agent: `name` is a site slug, and the node's `restart_container.sh` refuses any container whose name is not its own `SITENAME` (the shape install.sh creates), so an operator's other containers are out of reach. `docker restart` keeps the container, its layer and its volumes. The repair of recipe `container_health` | No |
| `hold_container` | Stop one of a host's site containers and keep it stopped, or start it again (a switch-over's old container, or a site an operator holds stopped from the host's Site containers), as the `hold_container` **operate primitive** on the host's own agent (agent 1.60.0): `action` is `stop` or `start`, `name` a site slug, and the host's `hold_container.sh` refuses any container whose name is not its own `SITENAME`. Stop writes the hold first (`/etc/joinery/sites/<site>/held`, holding the restart policy the container had), turns its restart off and stops it; while the hold exists `container_health` leaves it alone and `restart_container` refuses it, and with its restart off Docker does not start it at boot. Start puts the policy back, starts it, and lifts the hold once it runs. The container and its volumes stay; `remove_account.sh` removes the hold with the container. A switch-over whose source is a container holds its old container stopped this way, last in its start or once its DNS move settles, and starts it again on the way back. `process_hold_container` keeps the container, the action, whether it was done, whether it is held, and its state, writes the hold into the host's last report, and moves the site's row in or out of the **Held stopped** install state (`ManagedNode::fold_container_holds()`, which every host report also runs, so a hold placed on the host by hand is followed too; a row in another install state, such as a switch-over's retired old container, is left alone). A held site is not a working site: no backup, upgrade, uptime check or incident touches it, its page says who held it, when and why (`ManagementJob::hold_words()`, from the job that asked), and it is started from the host's page | No |
| `site_certificate_stage` | Hand a site in a container its own certificate for its copy's export, as the `site_certificate_stage` **operate primitive** on the host's own agent (agent 1.91.0+): `action` is `stage` or `clear`, `name` the site's container, `domain` (stage) the site's domain. The host ends the site's TLS, so the lineage is on the host, where `copy_export` (made inside the container) cannot see it. `stage` copies, into the container's `/etc/letsencrypt`, every lineage whose certificate names that domain or its `www.` and nothing else: its `live/` links, the archive files they point at and its renewal file without its `account` line. The host's ACME account, shared by every site there, never leaves it, nor does any DNS-01 credential. The host refuses a domain it does not serve that container at (its vhost for it, else the `DOMAIN_NAME` it gave the container), a lineage naming the site beside another name, and a container that is not one of its own sites. It records what it wrote (`/etc/joinery/sites/<site>/certificate-staged`), and `clear` removes exactly that. A copy's run puts `stage` just before `copy_export` and `clear` just after (`SiteCopyRunner::run_steps()`), and a discard clears what a declined export left. On the copy, `provision_origin_cert` adopts the carried lineage under the machine's own ACME account (`adopt_carried_lineage`, registering one when there is none), so it keeps serving and renews there. |
| `outbound_limits` | Turn the machine's outbound limits on or off, or set their figures, as the `outbound_limits` **operate primitive** (agent 1.62.0) on the agent of the machine that holds them: a Docker host's own agent, or a bare-metal site's, which is the machine's. `action` is `on`, `off` or `set`; `set` takes any of `ceiling` (Mbit/s, `off` or `default`), `conn_rate`, `conn_burst` and `open_conns` (a whole number or `default`), and `site` for one container site's own figures. The agent composes the argv of `maintenance_scripts/install_tools/outbound_limits.sh` (on a Docker host, from the support bundle): `on`, `off`, or `set` with each figure as the script's flag and `--by=plane`, so the host file records that this management node set them and each site's settings page says the figure is set by whoever hosts it. The script writes `/etc/joinery/outbound_limits.json` or the site's run spec, brings the installed `joinery-limits` up to its own copy, and runs the unit. The machine's own state is the switch: this management node records nothing, and the next host report shows what is in force. **Turn limits off**, **Turn limits on** and **Set the figures** on the Overview's Machine tile dispatch it (`JobCommandBuilder::build_outbound_limits()`) | No |
| `suspended_page` | Show the plain "This site is suspended" page for one of a host's sites in place of the site, or take it down again, as the `suspended_page` **operate primitive** on the host's own agent (agent 1.61.0): `action` is `show` or `clear`, `name` a site slug. The host's `suspended_page.sh` refuses a site with no enabled proxy vhost carrying the switch (`default_proxy_vhost.conf` 1.05). It places or removes the mark `/etc/joinery/sites/<site>/suspended`, tests Apache's configuration and reloads it, then reports whether the proxy now answers the site's name with the suspended page (`page`: `suspended`, `site` or `none`). The site and its container are untouched; a configuration Apache refuses puts the mark back. `JobCommandBuilder::build_suspended_page()` builds the job; nothing on the node page dispatches it | No |
| `data_root_migrate` | Move a host's data onto its data root, `/srv/joinery`, as the `data_root_migrate` **operate primitive** (agent 1.69.0+, `install_tools/joinery_data_root.sh migrate`; specs/one_data_root.md D5). No parameters: the host finds what moves (PostgreSQL, the Postfix queue, rspamd, each site's six folders, Docker's data-root) and sizes the data root from it, refusing with every reason before anything changes. Its sites, database and mail stop for as long as one copy of the data takes; a move that does not finish puts everything back. The move runs in a systemd unit of its own (`joinery-data-root-migrate.service`, log `/var/log/joinery-data-root-migrate.log`), so a job that times out ends only its transcript. The copies from before stay on the host until its data root passes check after a reboot. Offered on a host with no data root as **Move its data onto the data root** under Health; never on a site container, whose data is its volumes | No |
| `data_root_enrol` | Enrol the node's encrypted data root with this management node as its escrow holder, as the `data_root_enrol` **operate primitive** (`joinery-agent data-root enrol`): the node signs with its unlock identity and vouches with its node key, so the holder hands over the escrow secret at once; the secret goes to the node in the holder's answer, never into the job. Sent by `DataRootEscrowSync` when a host report shows an open encrypted data root not enrolled here, a dormant copy included once its agent is 1.75.5 or newer (it takes this word and `data_root_recipient` on a quiet site); a job not yet succeeded holds the next back an hour. Offered as **Enrol with this management node** on the node page's encryption card ([Encrypted data root](/docs/encrypted_data_root.md)) | No |
| `data_root_recipient` | Set the recovery public key the node's break-glass copy is sealed to, as the `data_root_recipient` **operate primitive** (`joinery_data_root.sh recipient --set-by=operator KEY`): this management node's own proven key, for a machine in one of our accounts. Sent before an encryption, and again by `DataRootEscrowSync` to every such machine whose host report names an older key, or names this key as set by anyone but the operator (`recipient_set_by`: a server made here is given it at its install, and its site would otherwise call it another key); the node seals its copy again on its next tick | No |
| `data_root_provider_layer` | Record on the machine the provider's own disk encryption under it, as the `data_root_provider_layer` **operate primitive** (agent 1.96.0+, `joinery_data_root.sh provider-layer enabled\|disabled`, into `/etc/joinery/data-root/provider_layer`). Sent by `DataRootLayers::push_provider_layer` when a host report's `data_root_provider_layer` is not what this management node knows: never unknown, to a machine with a data root (a container's is its host's) whose release's script is 1.32 or newer, the same value once an hour at most. The script then refuses `encrypt` while it says enabled, unless `--keep-provider-layer` | No |
| `data_root_encrypt`, `data_root_decrypt` | Encrypt the node's data root where it stands, or undo it, as **operate primitives** (`joinery_data_root.sh encrypt [--keep-provider-layer]` / `decrypt`): the start stops its services for seconds, then `joinery-data-encrypt.service` runs for hours at idle priority, reported in the host report (`reencrypt`, `reencrypt_percent`). Queued by **Encrypt the data root** (`DataRootEncryptionOffer`: a machine in one of our accounts, the recovery key proven, a verified backup under a day old, one layer unless the provider's is kept) and **Decrypt (undo)** | No |
| `data_root_rekey` | Re-encrypt the node's data root under a new volume key, as the `data_root_rekey` **operate primitive** (`joinery_data_root.sh rekey`), after a recovery key that may have been seen: online, for hours, and its escrow slot made again with a new secret through the holder's `rekey` action. **Re-encrypt under a new key…**; one machine at a time | No |
| `site_limits` | Change one of a host's sites' memory, CPU ceiling and disk allowance without rebuilding it, as the `site_limits` **operate primitive** on the host's own agent (agent 1.63.0+, `sysadmin_tools/site_limits.sh`): `name` is a site slug, and `memory`, `cpus` and `disk` are each a figure, `none`, or `keep` (an empty field on the node page). Memory and CPU change on the running container (`docker update`, swap held to the memory figure), the disk allowance on the host's disk pool (`docker_disk_pool.sh allow`, `release` for none), and the run spec records each, so a rebuild keeps them. A memory change restarts the site, so PostgreSQL and PHP-FPM size themselves to it. The host refuses what `install.sh site` would, a CPU ceiling above its own, an allowance on a host with no pool, and lifting a running container's memory limit or CPU ceiling (Docker keeps a CPU ceiling in the container's configuration and puts it back at the next start): a rebuild with `--memory=none` or `--cpus=none` does that. Offered on a Docker host's Site containers as **Change a site's limits**. `install.sh site-limits SITENAME [--memory=] [--cpus=] [--disk=]` runs the same script on the host | No |
| `remove_site_certificate` | Remove one HTTPS certificate a removed old container of a switch-over left on its host, as the `remove_site_certificate` **operate primitive** on the host's own agent (agent 1.59.0): `name` is the certificate's name (the domain, with certbot's optional `-NNNN`). The host's `remove_site_certificate.sh` refuses, touching nothing, while anything uses the certificate: a file Apache loads (`apache2ctl -t -D DUMP_INCLUDES`) or could enable (`sites-available/*.conf`) naming the lineage or its placeholder directory, any other file under `/etc` naming either, an Apache configuration that does not load, or the machine serving the certificate on 443 for its domain (asked over `127.0.0.1` and `::1`). Vhost backups beside a site (`<site>.conf.before-render.<time>`) do not count. Nothing is moved aside to test. Otherwise it runs `certbot delete` (so renewal stops) and removes `/etc/ssl/joinery/<domain>`. Offered on the old row's Overview, and on the site's Copy tab, once its container is gone and the host still reports a certificate for its domain (`MovedSiteCheck::leftover_certificates`), never for a domain a live site on the same host serves. A status check on the host follows | No |
| `site_quiet` | Freeze the site for a switch-over, or let it run again, as the `site_quiet` **operate primitive** (agent 1.46.0+): `action` is `on`, `off` or `forward` (agent 1.84.0+, with `to`, a public address). `forward`, on a site already frozen, has it forward every visitor to its copy at `to` while the site's DNS moves: the web drop-in proxies every request there over https with the copy's certificate checked against the site's own name, and the ACME challenge path over http, and the firewall rule lets the web user reach that address on 80 and 443; `on` stops forwarding. The node runs `maintenance_scripts/sysadmin_tools/site_quiet.sh`, verified against the release manifest, under the host runner lock. `on` sets `quiet switchover` (never `quiet copy`, which only the installer sets), asserts the quiet measures at once and waits up to five minutes until the web user runs no command-line PHP; a worker still running then is named and the job fails with the site still quiet. It then waits for mail already on its way in, since the final backup runs next: a Postfix reload changes no session already open, so each smtpd that was serving a client when the deferral was reloaded is given half a minute and then ended (its sender retries and meets the deferral), and every message Postfix already accepted must leave its incoming and active queues, which is the site's handler taking it; one still there after five minutes fails the job the same way. `off` clears the state and puts back what quiet took away; over `quiet copy` the agent refuses unless its own node id is the one the dormant install recorded, so a copy never goes live beside its source. While a site is quiet its agent runs every observe word and, of the rest, only a word that declares the reason (`site_quiet` under both, `backup_run`, `copy_export` and `copy_vouch` under `switchover`, `copy_import`, `copy_take_vouch`, `copy_take_key`, `copy_stage`, `copy_restore` and `take_node_id` under `copy`); everything else is refused before its parameters are read. See *The quiet state* in `docs/deploy_and_upgrade.md` | Primitive only | No |
| `trust_forwarder` | Believe X-Forwarded-For from one public address for a while, or stop, as the `trust_forwarder` **operate primitive** (agent 1.84.0+) on the machine whose Apache answers a copy (its own server, or the host fronting a copy in a container): `action` is `trust` (with `hours`, 1 to 168) or `untrust`, `address` a public unicast address (shared address space, where a tailnet lives, and the protocol, benchmarking and reserved IPv4 blocks refused too, on the agent and here: `JobCommandBuilder::NOT_PUBLIC_V4`). `maintenance_scripts/sysadmin_tools/trust_forwarder.sh` keeps `/etc/joinery/forwarders` (each address with its end) and renders `joinery-forwarders.conf`'s `RemoteIPTrustedProxy` lines from it, leaving out any address past its end; host housekeeping runs its `expire` on every converge, so an address past its end is dropped though nobody untrusted it. A switch by DNS trusts the old server while it forwards visitors and untrusts it once the move settles or goes back: its address returns to the provider's pool when the server is deleted | Primitive only | No |
| `copy_probes` | Prove one site's walls by acting as it, as the `copy_probes` **operate primitive** (agent 1.84.2+) on the machine that holds the site (its own server, or the host of its container): the rows of a copy's security check that a report cannot carry, because they are the attempts themselves (specs/site_copy_final_test.md, the rig). `site` names the site; optional `neighbour`, another site on the host whose config volume the site's root must not list; optional `old_address`, the site's old server, which no `RemoteIPTrustedProxy` may still name. `maintenance_scripts/sysadmin_tools/copy_probes.sh` tries, as the site's web user, a mount inside a user namespace, a mount, a packet socket (asked with Perl) and a write to `serve.php`, and reads the database's listener; it prints one `PASS`, `FAIL` or `SKIP` line per row and `RESULT:` last, and creates nothing on the way (a mount let through is taken down, the write appends nothing). It runs under any quiet reason, since the source and the dormant copy are both probed before the switch. No shipped script loosens a kernel setting: the check that one is put back is the test plane's own, over its login (`tests/lib/check_loosen_restored.sh`). `JobCommandBuilder::build_copy_probes()` | Primitive only | No |
| `copy_restore` | Apply a staged backup chain of the site this node is a dormant copy of, as the `copy_restore` **operate primitive** (agent 1.47.0+): `project` (this machine's own, cross-checked), `chain_id`, optional `seq`. The node runs `restore_chain.sh --adopt-secret-key --skip-ssl` on the chain workspace `restore_<chain_id>` under its backup base: the copy takes its source's `secret_box_key` into its own config and arms no certificate retry. It runs only under `quiet copy` (refused on a live site and on a frozen source), and only for a staged manifest whose hash is listed with its chain id in root's `/etc/joinery/sites/<site>/vouched` (`<sha256> <chain id>` per line, written by `copy_import`; refused when absent, writable by other accounts, or not root's); the node's own upload ledger does not count. No approval: a dormant copy holds nothing of its own. See `site_copy.md` | Primitive only | No |
| `copy_export` | Hand a dormant copy what its source holds and it cannot make, as the `copy_export` **destructive primitive** on the SOURCE (agent 1.49.0+): `profile`, `chain_id`, `target_public_key` (the copy row's recorded agent key; `build_copy_export($source, $copy, …)` refuses a row that is not a `copy` recorded as this node's). Destructive for what the class means on the node: never unattended. The node composes its own statement (the copy's key fingerprint and full SHA-256, the chain and its newest upload time, the certificate lineages and whether an ACME account goes with them, the DKIM key count), and its own operator approves on its own Backups page with its recovery key (the `export` scope of `ApprovalChallenge`), at every export. It then refuses unless the chain's local manifest is bytes its upload ledger recorded, opens the chain data key with its own `config/backup_site_key`, gathers the host bundle (each certbot lineage's current version with its `live/` links and renewal file, the ACME account, `/etc/letsencrypt/*.ini`, the DKIM signing keys under `/etc/rspamd/dkim/`; for a site in a container, the lineage its host staged inside it, `site_certificate_stage`), seals it all to the copy's agent key (Ed25519 converted to X25519; HKDF-SHA256, AES-256-GCM) and signs it with its own agent key under `joinery-copy-export-v1`. With `history` (agent 1.85.0+, sent by `build_copy_export` only when the source and the copy are both at `COPY_HISTORY_MIN_AGENT_VERSION`, since an older copy refuses a bundle carrying anything it does not know), the bundle also lists the site's backup history: the newest manifest of each chain the source's upload ledger holds, by hash, size and upload time, and the statement says so. Runs on a live site and under `quiet switchover`; refused on a dormant copy. `process_copy_export` keeps the bundle; `JobResultProcessor::bundle_of($job)` reads it back | Primitive only | No |
| `copy_import` | Take in a source's export on a dormant copy, as the `copy_import` **operate primitive** (agent 1.49.0+): `bundle`, as `bundle_of()` returned it. Only under `quiet copy`. The node checks the signature by the source key its dormant install recorded (`/etc/joinery/sites/<site>/copy_of_key`), that the bundle names the node in `copy_of`, that it is sealed to this node's own key, that it has not expired (six hours) and is newer than the last one taken (`copy_import_issued`), then opens it and writes each chain's `chain.key` into `restore_<chain_id>`, the host bundle into `/etc/letsencrypt` and `/etc/rspamd/dkim` (each entry a root by name and a path inside it; a `live/` link must point into its own lineage's archive), the `vouched` record whole, the site's backup history whole into `history` beside it (removed when the bundle carries none; agent 1.85.0+), and last the new high-water mark. Carries the chain ceiling (`ManagementJob::CHAIN_WORDS`) for its size. Its result also carries `look_path`, the path that sets the copy's look cookie (agent 1.50.0+), which `process_copy_import` keeps and `JobResultProcessor::look_path_of()` reads back | Primitive only | No |
| `copy_stage` | Download the chain a dormant copy will apply, as the `copy_stage` **operate primitive** (agent 1.49.0+): `chain_id`, `manifest_url`, `artifact_urls`, optional `seq`. The links are signed under the SOURCE's slug on the source's target (`sign_chain_links` asks `ManagedNode::backup_node_of()`, which answers with the node in `mgn_copy_of_node_id` for a row in state `copy`). Only under `quiet copy`, and only after `copy_import` wrote the chain's key. The node passes its own workspace, the vouched manifest hashes and the `history` record (`--profile`, `--history`) to `utils/copy_stage.php` (`CopyStaging`), which keeps the manifest only when its hash is vouched for and checks every artifact against the manifest's size and hash; it writes no key. It records in the copy's own upload ledger, marked handed over, the vouched manifest, every artifact it names and the history's manifests, so the site's backups from before a switch restore on the copy once it is the site, and refuses by name when the ledger cannot be written | Primitive only | No |
| `copy_vouch` | The switch-over's final copy on the frozen SOURCE, as the `copy_vouch` **operate primitive** (agent 1.53.0+): `profile`, `chain_id`, `target_public_key` (`build_copy_vouch($source, $copy, …)`, the same checks as an export's). Only under `quiet switchover`. The node refuses unless the chain's local manifest is bytes its upload ledger recorded, and signs its hash, the chain and the copy's key with its own agent key under `joinery-copy-vouch-v1`, valid for an hour. No secret is in it and no approval is asked. `process_copy_vouch` keeps it; `JobResultProcessor::vouch_of($job)` reads it back | Primitive only | No |
| `copy_take_vouch` | Take the source's vouch on a dormant copy, as the `copy_take_vouch` **operate primitive** (agent 1.53.0+): `vouch`, as `vouch_of()` returned it. Only under `quiet copy`. The node checks the signature against the source key its dormant install recorded, that the vouch names its source and its own key, that it has not expired and is newer than any export or vouch it took, and that it already holds the chain's key from an approved export; then it makes that manifest the one run it may apply (`vouched`) | Primitive only | No |
| `copy_look` | A dormant copy's look path, as the `copy_look` **observe primitive** (agent 1.54.0+), no parameters: the path that sets its look cookie, so its owner can reach its own page past the quiet state. Refused unless the site is `quiet copy`. `process_copy_look` keeps it | Primitive only | No |
| `copy_take_key` | A copy from backups takes its chain's key, as the `copy_take_key` **operate primitive** (agent 1.54.0+; `manifest_url` from 1.68.0, `JobCommandBuilder::COPY_TAKE_KEY_MIN_AGENT_VERSION`, and an older agent is refused): `chain_id`, `manifest_sha256`, `run_time`, `recovery_fingerprint`, `recovery_sealed` (the chain's data key as sealed to the recovery key, from the manifest's envelope), `site` and `manifest_url`, a signed link to the manifest (`build_copy_take_key($copy, SiteCopyRunner::key_request(…))`). Only under `quiet copy`. The node first reads the manifest through the link and refuses unless it hashes to `manifest_sha256`, is the chain's, seals `recovery_sealed` to `recovery_fingerprint`, and names no run after its provider stored it; it takes the run from the manifest, and the provider's date when the link is at a provider's storage host. It then stages the backup's statement on its own **/copy-key** page (`CopyKeyHandoff`, the `copy_key_request` and `copy_key_answer` settings rows) and waits up to an hour: the owner's browser imports the recovery key, checks its fingerprint, and posts the X25519 of it with the sealed box's ephemeral key, which opens that one box; the node finishes opening it, says a wrong key's reason on the page and keeps waiting, and on the right one writes the chain's `chain.key` and the stated manifest as its vouch. A withdrawn job or a decline ends the wait. `process_copy_take_key` keeps which chain it took, its newest run, and when and at which provider it was stored | Primitive only | No |
| `take_node_id` | Take over the node id of the site this node is a dormant copy of, keeping its own key, as the `take_node_id` **operate primitive** (agent 1.50.0+): `node_id`, `node_slug` (`build_take_node_id($copy, $source)` refuses any node but the copy's recorded source). Only under `quiet copy`, only the id the dormant install recorded in `copy_of`, and only when something is proven to restart the agent. The node stages its identity under the new id beside the live one (`node_identity.json.pending`) and posts its result; this management node swaps the two node rows while answering it and says `node_id_taken`, and only then does the node make the staged identity live and restart as the node. Without that answer the staged file is deleted. Processing the result anywhere else (a page view, the sweep) records what was staged and swaps nothing | Primitive only | No |
| `site_census` | Count what the node's site holds, as the `site_census` **observe primitive** (agent 1.48.0+), no parameters. The node runs `maintenance_scripts/sysadmin_tools/site_census.php`, verified against the release manifest, which prints one `CENSUS=` line: the rows in every table, the files, directories, bytes and a path-and-size digest per top-level directory, and the key canary's state with the stored and dead sealed secrets. It leaves out what each machine keeps as its own (see *Checking a restore: the census* in `docs/backups.md`). The result keeps the count (`JobResultProcessor::census_of()`), for `SiteCensus::compare()` against another node's; nothing is folded into the node's status. Read-only, so it runs on a dormant copy and a frozen source | Primitive only | No |
| `run_installer` | Run one installer by name through the host runner, as the `run_installer` **operate primitive**: a core installer (`JobCommandBuilder::RUN_INSTALLER_CORE`, the runner's `CORE_INSTALLERS`, `site_housekeeping.sh` among them — the site's logrotate file and cron entry, written when absent; on a machine with no site only its host installers, under `--machine`), or `plugin:NAME` for an active plugin's declared `host_installer` (`--only-plugin=NAME`). Every one is idempotent and is what the host timer already runs daily. `process_run_installer` reads the transcript as `host_converge`'s is read: green only on the runner's ok line for the installer asked for | No |
| `reclaim_managed_file` | Put one host file back to the platform's definition, as the `reclaim_managed_file` **operate primitive**: `file` from the resettable list (`JobCommandBuilder::RECLAIM_FILES`, each with the installer that owns it — fail2ban's jail files, the remote-IP conf, `mpm_event.conf`, the journal cap and the PHP-FPM `php.ini` (`host_housekeeping.sh`), the site vhost (`render_vhost.sh`), the agent's cron file (`install_agent.sh`), the site's logrotate and cron files (`site_housekeeping.sh`)). The node's `reclaim_managed_file.sh` moves the file to `FILE.reclaimed-YYYYmmddHHMMSS` beside it — nothing is deleted — and runs its owner through the runner's `--only`; when the owner does not write that file on this machine the copy is moved back and the transcript says so. `jail.local` is the one whose reset is its removal. `apache2.conf`, the certbot vhost, the mail files, the security settings, the apt files and docker's `daemon.json` are never on the list. The job is green on the owner's ok line, and the job page shows what moved where. Read the file first (`file_head`): a hand edit may be deliberate | No |
| `restore_release_file` | Put one deployment file an upgrade replaces first back to the bytes the installed release signed, as the `restore_release_file` **operate primitive**: `file` is one of the five (`ReleaseManifestSource::SELF_UPDATE_FILES`, mirrored from the agent's compiled list). The bytes come from the agent's kept copy or from this management node, asked for by the sha256 the node's key-verified manifest lists (artifact kind `release_file` with `sha256`; the node's `VERSION` is a hint of where to look first, never what decides, so a node rolled back under a newer release's manifest is still repaired, and one whose `VERSION` cannot be read too), are checked against that manifest before a byte is written, every directory is opened without following links, mode and owner are kept, and the file replaced is saved under `/etc/joinery-agent/release-files/replaced/`. Refused when the file already matches, when the manifest is unusable, and when no source has a verifying copy. **Restore signed copy** on the node page | Primitive only | No |
| `repair_release_files` | Put every one of the five deployment files that differs from the installed signed release back at once, as the `repair_release_files` **operate primitive** (agent 1.79.0): no parameters; the node decides which differ and restores each exactly as `restore_release_file` does, naming in `restored` each file put back with its source and in `failed` each that could not be with the node's reason; `ok` when nothing remains. Refused when the manifest is unusable and when every file already matches; fails outright only when nothing could be put back. The repair word of recipe `release_files`; **Put them all back** on the node page. `process_repair_release_files` keeps the two lists | Primitive only | No |
| `upgrade_preflight` | Whether an upgrade would stop before it started, as the `upgrade_preflight` **observe primitive**: no parameters; six checks (`manifest`, `files`, `version`, `lock`, `disk`, `theme`), each a pass or fail with one short reason, and an overall `ok`. It reads the active theme's name and file sizes and hashes, never file contents or rows. A staged rollout asks it before each node's apply | No |
| `file_head` | The first lines (1 to 400) of one host configuration file, as the `file_head` **observe primitive**: `file` is a name from the compiled readable list (`JobCommandBuilder::FILE_HEAD_FILES`, a mirror of the agent's — fail2ban's jail files, the site vhosts, `apache2.conf`, `mpm_event.conf`, the remote-IP conf, the PHP-FPM `php.ini` (settings only), journald's size limit, the site's logrotate and cron files, the agent's and certbot's cron files, the two apt files, docker's `daemon.json`, the sysctl hardening file, and the mail settings files), never a path; a per-site file takes the node's own site, or `site` on a Docker host. Files that are themselves secrets (the maps `main.cf` points at, OpenDKIM's keys and tables, `letsencrypt/live`, the agent's env file, `Globalvars_site.php`, sudoers, crypttab, passwd, shadow, `/etc/ssh`) are not on the list and cannot be named; a symlink in place of a listed file is refused. Redacted on the node by the configuration shape; refused while `agent_log_access` is off | No |
| `schema_probe` | One table of the site's own database, as the `schema_probe` **observe primitive**: `table` is an identifier the node must find in its own `information_schema` before anything else runs; the node returns whether it exists, its columns (type, nullability, length, default), its indexes and its row count (an aggregate; a table too large to count in 20 seconds reports the planner's estimate and says so). No SQL crosses the wire and no row is read | No |
| `page_probe` | Render one of the site's own pages as a throwaway `anonymous`, `member` or `admin` viewer, as the `page_probe` **observe primitive**. The node refuses a page that is not on its own list (its admin menu entries and public views, no query string) or that acts on arrival (`PageProbe::REFUSED_PAGES`). Inside the one run the node makes the viewer (a member, or an administrator at permission 10), mints a one-minute grant, sends one request over its own connection carrying the grant's token — the request runs as that viewer with no cookie and no PHP session, and cannot persist anything a user could ask for — then deletes the grant and **permanently** deletes the user. It returns HTTP status, bytes, render time, statements run, peak memory, PHP warnings and errors as type and `file:line`, theme and plugin assets that fail to load, a landmark check (header, main, footer, form count) and a structure hash; never page text, HTML, form values or a warning's message. A probe that could not render the page (it could not make its viewer, say) fails its job with the node's reason — a sentence and an exception's class name, never its message. A probe that cannot finish its cleanup fails its job and says so, and the next probe sweeps the leftover | No |
| `publish_upgrade` | Build and sign a release from a node's own tree, as a primitive of that node's **own** agent, dispatched by the management node that manages it (node detail, Updates tab, for a node that is a management node — its own report says the Server Manager plugin is active there: `server_manager` on every poll (`active` or `inactive`, stored in `mgn_agent_server_manager`), or `server_manager_active` in a check_status report — and its agent reports the primitive; a plain site never publishes and is not offered the action). A management node that manages itself is the same case pointed at its own record: it connects to itself from its Management Node page, approves the request on its own dashboard, and `ManagedNode::self_node()` is the row its Publish page dispatches to. A management node that is another plane's node is published from that plane, and its Publish page says so (`ManagedNode::managed_by()`). See `specs/publish_as_node_action.md` | No |
| `install_node` | The bootstrap SSH session: fetch the release, `install.sh docker` (host agent), `install.sh site … --enable-agent`. Run by `InstallJobExecutor` on the plane over the provision's sealed install password | No (target must be clean) |
| `retire_install_password` | The bootstrap's closing session, once every agent the install put on the machine is admitted: over the same password, put the provision's `cvp_root_ssh_keys` (if any) in root's `authorized_keys`, write `/etc/ssh/sshd_config.d/00-joinery-agent-managed.conf` (password and keyboard-interactive authentication off; root login off, or key-only when keys were given) and restart sshd, so the machine stops accepting it. `InstallJobExecutor` completes the job only after a fresh login with the password is refused; the provision pipeline then erases the sealed password | No |
| `provision_certificate` | Issue the node's certificate as a primitive on the **issuer**: the node itself on bare metal, its host's own agent for a container (`for_node_id` names the site). Driven by `ProvisionPendingSsl`, which observes a certificate the machine already reports before asking | No |
| `fleet_enroll` | Seed a new site's fleet-service URL and API key pair (three settings; the names are compiled into `utils/fleet_enroll.php`). The secret is blanked from the job row once the node answers | No |
| `backup_run` | This management node's own backup of a node. The node runs its backup engine — chain, envelope, upload, local sweep — writing through a broker run opened for that job ([The backup broker](#the-backup-broker)); no bucket key is sent. What opens the archive is not supplied: the node seals to the recovery key it holds and has verified | No |
| `stage_chain` | Put a whole backup chain back on the node, ready to restore: the plane signs a link to every object under the chain (`JobCommandBuilder::sign_chain_links`) and the node reads its own manifest, fetches what a restore of the run needs, checks each against its upload ledger (recording first the artifacts a matched manifest names that the ledger lacks) and recovers the chain key from its own `backup_site_key`. A chain that key does not open but the recovery key does is staged without a key (`STAGE_KEY=recovery`), for the restore's approval to open. The `stage_chain` **operate primitive** (script `utils/stage_chain.php`); no approval. The link set is every object in the chain, so the word takes up to 1024 links under a 1020 KiB params ceiling (agent 1.45.0). An agent before 1.45.0 takes 64 links under 60 KiB; a chain past that is refused when that node claims it, naming the update | No |
| `verify_backup` | Prove one of the node's backups restorable without restoring it, as the `verify_backup` **operate primitive** (script `utils/verify_backup.php`, agent 1.24.0+): the same links as `stage_chain`, under the same bounds, plus a level — 2 opens and reads every artifact to the end, 3 rehearses a restore into a scratch tree and a throwaway database on the node. Nothing on the site is touched, so no approval; the schedule dispatches level 2, a person chooses 3. The `VERIFY_*` result lines stamp `mgn_backup_verify_*` (`JobResultProcessor::process_verify_backup`). See [Verifying backups](../../../docs/backups.md#verifying-backups) | No |
| `restore_objects` | Bring a run's offloaded files home from the node's manager-profile backup storage, as the `restore_objects` **operate primitive** (script `utils/restore_objects.php`, agent 1.38.0+), paged and driven from the plane (`FleetObjectRestore`): a survey job carries the run's index link and the node answers with the names the file bucket cannot serve; each page job carries the signed links for a slice of them with the envelope of each epoch they are sealed under, filled to the job's byte ceiling, and its result issues the next page. Started by a completed `restore_chain` in `missing` mode (`JobResultProcessor::process_restore_chain`), and by hand from the node's Backups tab (**Bring them back**, the `restore_objects` backup action) when the node's own daily file-store check has found offloaded files its bucket can no longer serve. Overwrites nothing and deletes nothing in any bucket, so no approval. See [Restoring a managed node](../../../docs/backups.md#restoring-a-managed-node-from-its-management-node) | No |
| `decommission_node` | Permanently remove one container site from its shared host, as the `decommission_site` primitive on the **host's own paired agent** — the site itself approves its removal first. The old machine of a switch-over (state `retired`) takes `decommission_moved_site` instead, where the host's proof that the domain reaches another server stands in for the approval (see [Removing a container site](#removing-a-container-site-decommission)) | **Yes** (the old machine of a switch-over: no) |
| `moved_site_check` | Ask the Docker host whether the old machine of a switch-over (state `retired`) still holds its domain: `decommission_moved_site`'s proof, reported instead of enforced, removing nothing (agent 1.56.0). Filed against the **host's** node with `victim_node_id`; the answer lands on the victim's `mgn_moved_check_*` (see [Removing a container site](#removing-a-container-site-decommission)) | No |
| `moved_site_reach` | Place a one-time token on the **new** server of a switch-over (`ssl_probe_place`) and fetch it over the domain, to show the domain reaches the site's new home. Filed against the new server's node with `victim_node_id` (the old row) and `domain`; the answer lands on the old row's `mgn_moved_reach_*`, and `moved_site_reach_clear` (`ssl_probe_clear`) empties the file after the fetch | No |

Destructive operations take no safety copy of the state they replace: a restore happens because the current state is wrong, and the operator approves a statement that anything written since the archive was taken is gone. The UI requires explicit confirmation checkboxes.

**Note on bare-metal nodes:** `install.sh server` disables root SSH during the bootstrap; the session that ran it is the last one the plane opens. Everything after it — backups, restores, certificates, upgrades — runs on the node's own agent, which is root.

### One-Click Node Install

**Dashboard → Install New Node** opens a form that provisions a Joinery site in a single click. The **Target Host** dropdown offers three kinds of target:

- **A known host** (or *Other server* with manual SSH details): the form creates the ManagedNode and dispatches the `install_node` job immediately.
- **Create a new cloud instance**: no server exists yet. The form records an admin-origin `CustomerCloudProvision` (the cloud account — one of ours, the default preselected, or a customer's connected one — region, instance type, plus all install parameters) and the **Provision Customer Cloud** task births the instance, creates the node, and dispatches the install — see [Customer-Cloud Fulfillment](#customer-cloud-fulfillment). The instance is created in, and billed to, the selected account; a Linode sign-in expires after two hours, so connect (or re-connect) shortly before submitting. In-flight provisions appear in a banner at the top of the dashboard.

  The cloud target also offers **Bare instance** as the install type: the instance is born, the SSH key injected, and the managed node created with `mgn_skip_joinery_checks` set — but no site is installed (no web root, site URL, or SSL flow). Completion is a passing `check_status` job. This is how infrastructure nodes that host no Joinery site — a mail relay shard, for example — enter management; the role's own provisioning (e.g. the mailbox plugin's provision-relay job) builds on the bare node afterward. Bare is admin-origin only; orders always install a site.

Install types:

- **Copy** (`install_mode = copy`): a dormant copy of another node, made from that node's **Copy** tab rather than this form; see [Site copy](#site-copy).
- **Fresh**: empty Joinery site with default schema. Admin picks the domain. The admin login is `admin@example.com` with a password generated for that site alone — there is no shared default. Like the generated Postgres password, it stays on the node rather than in the management node: read it at `/var/www/html/{sitename}/config/admin_credentials.txt` (root only), or set a new one with `maintenance_scripts/sysadmin_tools/reset_admin_password.php`. `usr_force_password_change=true`, so the first sign-in forces a new password.

The job is one remote command, run as root over the provision's sealed root password: fetch the release from the release stream, `install.sh -y -q docker --management-node=URL --node-name=NAME-host` (Docker plus the siteless host agent, which asks to join), then `install.sh -y -q site --docker SITENAME - DOMAIN PORT --enable-agent --management-node=URL`. Without `--no-ssl`, install.sh writes the universal proxy vhost on the host (both ports forward `X-Forwarded-Proto https`; the :443 block is guarded on the certificate path), tries for a certificate, and when DNS is not pointed here yet arms the host's own retry timer, which issues once it is. The container name is the site name this plane chose and is recorded at dispatch; the published port is read back from the `CONTAINER_PORT=` line install.sh prints. Both `install.sh docker` and a bare-metal `install.sh server` carry the outbound limits' figures from the provisioning settings (`server_manager_outbound_ceiling_mbit`, `_conn_rate`, `_conn_burst`, `_open_conns`; 200, 20, 100 and 256 by default) as `--outbound-*` flags (`JobCommandBuilder::outbound_install_flags()`, which leaves out an empty value or one that is not a figure, and always adds `--outbound-set-by=plane`, so the machine records that this management node set them); a machine already running keeps its own, changed from its node page. A `bare` instance is the docker half alone. A `copy` is bare metal only: it fetches `latest_release?version=<the source's release>` instead of the newest, adds `--dormant --copy-of=<id> --copy-of-key=<source agent key>` to the site install, and creates no admin account. Two join requests arriving is the verification; nothing after the bootstrap opens SSH.

**The release stream** (`ReleaseStream::source_for()`). A server takes its release, and its upgrades, themes and plugins after (`--upgrade-server`), from whoever serves this management node's releases: this node itself when it has published one whose archive is on disk, otherwise this node's own `upgrade_source`. A management node built from a release and never published from serves none (its `?serve-upgrade` answers 404), so its servers go to the publisher it takes its own from, and run the same builds under the same key. A node that publishes and lacks a pinned version does not ask upstream for it: its numbers name its own builds. The join (`--management-node`) always names this node.

The `mgn_install_state` column tracks the lifecycle: `installing` → `NULL` (success) or `install_failed` (failure). A site copy (`specs/site_copy.md`) adds three more: `copy` (a dormant copy of another node's site, set when its join is approved onto a new row or, for a server this management node created, kept by a finished install of a row with `mgn_copy_of_node_id`), `switching` (the source of a switch-over in progress) and `retired` (the old machine after a switch-over, kept for the way back). `ManagedNode::INSTALL_STATES` lists all five with the words the fleet list badges them with, and `ManagedNode::is_operational()` is false for a node in any of them (or in any state it does not know): the fleet backups (`FleetBackupPolicy::eligible_nodes`, and with them the pruning that runs before each backup; `FleetBackupRetention::prune` also refuses one directly), staged rollouts (`StagedRolloutRunner::node_refusal`), the uptime task and `NodeMonitorHealth::evaluate`, the incident sources and the dashboard's status polling all skip it. The node stays in every list; its status dot is blue, a failed install red and a retired source grey. On failure, the node detail page surfaces a **Retry Install** button; the target must be cleaned manually (e.g. `rm -rf /var/www/html/SITENAME`) before retry because `install.sh` refuses to overwrite an existing site. Postgres passwords are auto-generated and stored in the target's `Globalvars_site.php` — Server Manager does not capture or display them.

**Docker notes:**
- The reverse proxy step is skipped when the domain is a bare IP address — a routable hostname is required for Apache `ServerName`-based virtual hosting. With an IP domain, the site is accessible directly on its mapped port, published on every interface. A site with a domain publishes its port on `127.0.0.1`, reachable only through the host proxy.
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

The **Overview** tab shows an **SSL Setup card** when `mgn_ssl_state` is not `active`, the node has a domain in its site URL, and `mgn_cert_expiry_ts` is empty. The last condition excludes nodes whose served certificate already covers their name (see [Certificate monitoring](#certificate-monitoring)) — their cert lifecycle is owned by an external renewer (e.g. Caddy), and the card's certbot-based provisioning does not apply to them. The card:

1. Resolves the domain via DNS and shows whether it points to the node's host IP
2. Enables the **Provision SSL** button when DNS is ready (or when the host IP is not configured)
3. On submit: starts the certificate chain (`ProvisionPendingSsl::begin_chain`), sets `mgn_ssl_state = 'pending'`, redirects to the jobs tab

The chain ends in a `provision_certificate` primitive on the **issuer** — the node's own agent on bare metal, its host's paired agent for a container (routed through the placement record, `mgn_mgh_managed_host_id` → `mgh_mgn_managed_node_id`; a container on a host with no paired host agent has no issuance path and the refusal says to pair the host). The job names the site in `for_node_id`, and `JobResultProcessor` reads what the script actually did (`SslProvisionOutcome`) before setting that site's `mgn_ssl_state` to `active`.

**Cloudflare-proxied domains** are gated on a routing probe first: the `ssl_probe_place` primitive writes a one-time token into the site node's webroot, the management node fetches `/sm-ssl-probe.txt` through the domain, and `ssl_probe_clear` removes it either way. The token is only fetchable because core serve.php routes that URL to `views/sm_ssl_probe.php` — a Joinery front controller never serves arbitrary webroot files, so a node whose code predates that route (`PROBE_MIN_CORE_VERSION`) is refused by name rather than blamed on Cloudflare. Only a match — proof that traffic for the domain actually lands on this node — dispatches the certificate; a miss leaves the domain pending until the customer's DNS actually routes here. The universal vhost already forwards `X-Forwarded-Proto https`, so nothing is patched.

### Automated Provisioning (installs only)

For nodes installed via **Install New Node**, `ProvisionPendingSsl` (scheduled hourly) watches for nodes with `mgn_ssl_state = 'pending'`. It **observes first**: a machine this plane installs issues its own certificate during the install or on its host's retry timer, and the node's (or its host's) `check_status` enumerates every lineage under `/etc/letsencrypt/live` — a CA-issued, unexpired lineage covering the domain flips the node `active` with no job at all. Only then does it **ask**: it checks DNS and drives the certificate chain above on the issuer. After ~16 hours of failed certificate attempts it flips state to `failed` — except a Cloudflare domain still waiting on its DNS cutover (`CF_ROUTING_UNVERIFIED`), which never flips: a cutover the customer has not made is not a fault, and can legitimately take days. Instead the routing wait is paced — hourly for the first `ROUTING_FAST_ATTEMPTS` tries, then one try every `ROUTING_SLOW_GAP` (six hours) — and entering the slow lane emails the operator once (recipient chain: `server_manager_provisioning_admin_alert_email` → `webmaster_email` → first superadmin; the sent marker is stamped into a job row's parameters as `routing_alert_sent`). The slow lane and the alert apply only while the domain still resolves to Cloudflare: once it repoints, the next attempt is due within the hour, and the 16-hour give-up window opens fresh at the first non-routing failure — time spent parked at Cloudflare never burns it. Manual provisioning via the Setup card is the fallback.

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
- **`customer_cloud`**: the buyer's site runs on its own cloud instance. A
  product opts in by picking **Customer cloud server** in the product-edit
  Purchase grants picker (`CustomerCloudFulfillment`, registered with the
  store's FulfillmentRegistry from serve.php); that stamps the provider value
  and contributes the one checkout requirement the line carries — the id of
  the site the buyer configured beforehand (see **The buyer configures first**
  below). The reference picked beside it decides **whose cloud account the
  server is born on**, and it is the product's decision rather than the buyer's:
  - *the buyer's own account* — they connect it, the provider bills them, and
    none of the operator's keys are involved. See below.
  - *the operator's account (hosted)* — the operator's own token creates it,
    there is no Connect page, and the mail, billing and banner legs apply. See
    **[Hosted tier](hosted_tier.md)**.

Every mode ends the same way: `install_node` completes, the welcome email goes
out, and `ProvisionPendingSsl` turns HTTPS on once DNS resolves.

A customer-cloud buyer can have their **domain name** registered for them in
the same payment — see **Managed domain registration** below. The choice is
made on the configure page, and when it is made the buyer never touches DNS.

### The buyer configures first, then pays; payment activates

Nothing about a customer-cloud site is asked in the cart. The buyer describes
it on one page, **`/profile/server_manager/configure`** (signed-in only; the
Managed product page sends a buyer with no configured site there). A visitor
with no session is sent first to **`/server_manager/start`**, step 1 of three:
the account, offered as sign-in and sign-up side by side with no preference.
Both forms post to the start page, which hands them to `login_logic` and
`register_logic` — so every gate those hold (throttles, activation, the
second-factor divert, the bot defences) holds there — shows a refusal in
place, and lets every success land on the configure page through the
session's return slot. Every page on the path carries the step strip
(`ManagedSiteDraft::steps_html()`: 1 Your account, 2 Your site, 3 Payment;
the summary, whose one button is Continue to payment, is step 3). The
configure page asks for: the domain
— their own, or one this plane registers for them, with the live availability
check and price and the registrant contact block — the site's internal name,
the region (`server_manager_hosted_regions`; one region in the list means the
field does not render), and the site admin email. **Save and continue**
validates everything (`ManagedSiteDraft::save_and_freeze`, with the domain
gates in `ManagedDomainIntake`) and writes a **draft provision**: a
`cvp_customer_cloud_provisions` row with `cvp_origin = buyer` at status
`pending_payment`, carrying the domain source (`cvp_domain_source`: `own` |
`register`), the frozen domain-year quote (`cvp_domain_quote`, `_time`) and
the sealed registrant block (`cvp_registrant_sealed`). **A draft holds
nothing** — no instance, no domain, no slug — and the pipeline cannot see it
(`draft` and `pending_payment` are outside every phase's query).

**Continue to payment** is a single-button form posting `product_id`,
`product_version` and `managed_site=<draft id>` at the product's own URL, so
the store's `product_logic` adds the line exactly as a product-page submit
would. `ManagedSiteRequirement` — contributed by `CustomerCloudFulfillment::
extraRequirements()`, never attached by hand — is the whole coupling: it
renders no fields, `validate()` refuses anything but the signed-in buyer's own
`pending_payment` draft, `process()` stores the draft id (`managed_site`) and
the freeze time (`managed_site_frozen`) in the line's form data plus, when
registering, the frozen quote under the keys the registration guard reads
(`managed_domain_price_line`, `managed_domain`), and `extra_cart_lines()` adds
the domain-year line from that frozen quote without ever re-quoting.

The draft's states:

| State | Meaning | Buyer actions on the sites page |
|---|---|---|
| `draft` | editable; expires after `server_manager_draft_days` (default 14) with no order item | Continue, Delete |
| `pending_payment` | frozen; the cart line was built from it | Finish payment (the cart, or a fresh add), Edit, Delete |

**Edit** on a frozen draft returns it to `draft` and drops its lines from the
buyer's cart (the hosting line and the domain-year line). A copy of the line
elsewhere is stale from then on: `CustomerCloudFulfillment::checkAvailability()`
— asked through `FulfillmentRegistry::cartRefusal()` with the line's form data,
on the checkout page before any payment session is created and by the charge
handler before it charges a card, never after money has moved — refuses a line
whose draft is not `pending_payment`, not the buyer's, or was frozen again since
the line was made, with *You changed your site setup after adding it to the
cart. Remove it from the cart and continue to payment again.* The checkout page
shows the sentence in place of the payment forms. A line that goes bad between
the payment session and the buyer's return is `fulfill()`'s to report (below).

**Payment activates.** `fulfill()` loads the draft the paid line names,
requires `pending_payment` and the buyer's ownership, and moves it to `ready`
with `cvp_external_order_item_id` stamped, `cvp_hosting_mode` from the
product's reference, and `cvp_mail_state = pending` on the operator's account
(or `pending_connect`/`ready` by the buyer's grant on their own). When the
draft registers a name, the `rdm_registered_domains` row is created here from
the sealed registrant and the frozen quote, and the draft's copy of the
registrant is erased. A slug or domain already held by a provision past
payment fails the activation closed: the row keeps the paid order item at
`failed`, so it is on the operator's board, and the alert names the clash.
Any refusal at `fulfill()` is logged and emailed to the alert recipient with
the order item id — the order is paid, so it is a person's task.

The **Drafts** phase of the provisioning umbrella (`SweepSiteDrafts`, first in
the sequence) soft-deletes a `draft` whose last change is older than
`server_manager_draft_days`, and returns a `pending_payment` row that old to
`draft` (its quote is stale). Both measure from `cvp_update_time`.

**Shared-host products** are unchanged by this: they attach the domain
Question, and the Orders poll reads its answer from the store.

### Activation — the Provisioning page

**Server Manager → Provisioning** (`/admin/server_manager/provisioning_setup`)
activates the pipeline. It is six tabs (`?tab=`), one subject each: **Setup**
(the job agent, the store API connection, scheduled tasks, emails), **Hosting**
(the cloud accounts, below; the Linode sign-in app; a new server's region,
type, image and the Linode referral URL), **Hosted tier** (see
[Hosted tier](hosted_tier.md)), **Shop** (the domain question, shared-host
fulfillment, products), **Domains** (registration) and **Cleanup** (the
test-account cleanup). Every requirement shows a live status badge, and each
automatable step is a one-click, idempotent action backed by
`includes/ProvisioningSetup.php` — mint the store API service user
(permission 8, password recovery disabled, a random `provisioning-<hex>@<host>`
address; found by the id in the managed setting
`server_manager_provisioning_service_user_id`, never by address — an account
at the old `provisioning@<host>` address is adopted once only when it owns the
configured key, otherwise a new account and key replace it) and machine
key and write the API settings (with a loopback probe badge, which asks for
one domain answer with `numperpage=1` and, when it fails, shows why: the HTTP
status and the API's own error, or the connection's; and key rotation), create the domain Question (shared-host products only), save the
email settings, activate the scheduled tasks (the provisioning umbrella, which
runs the draft sweep, order polling, customer cloud, SSL and the
managed-domain phases in one pass, plus the core Send Queued Emails task that
drains the welcome-email queue), the domain-registrar credentials and
promotion code, and the customer-cloud settings (referral URL, instance
defaults). The page also shows what stays manual: attaching the question to
shared-host products, opting a shared host in, and registering the Linode
OAuth app. When the store
is a remote site rather than the management node itself, the service key is
minted on the store site and its values entered in the API settings fields.

### Cloud accounts

The **Hosting** tab holds the cloud accounts of ours this management node
creates and manages servers in (`OperatorCloudAccount`,
`oca_operator_cloud_accounts`), at Linode or Hetzner (`oca_provider`). Each
account's driver is its provider's (`ComputeDriverRegistry`, in
`includes/cloud_compute/`): everything that acts on a server or an account asks
the registry for it, and asks the driver for a capability (an address swap, a
plan list, an activity log) before using one. Each is added one of two ways:

- **By API key**, pasted once, with its provider. The provider is asked first
  for the account's name, company and the token's scopes
  (`OperatorCloudAccount::add_key()`); a token it refuses saves nothing. A
  Hetzner Cloud token reaches one project with every right it was made with and
  never expires, and Hetzner's API does not name the project, so it is added
  under the name typed with it, and a check keeps that name. The key is sealed
  (`oca_operator_cloud_accounts.oca_credential`), never shown again, and never
  reaches a machine this node creates. **Replace key** takes a new token for the
  same account (`replacement_refusal()`: the provider's name for it must match,
  or, where the provider names none, the token must reach every server on
  record in it), since every server in the entry would follow it.
- **Connect with Linode**, a sign-in through the platform OAuth2 core (consumer
  purpose `operator_cloud`, `OperatorCloudConsumer`; superadmin only). Linode
  grants two hours and no refresh, so the entry shows the time it ends, then
  shows expired with **Reconnect**, and is offered nowhere: right for one server
  in somebody's own account, wrong for an account the node keeps managing.

Each entry shows the provider's name for the account, its company, who added it
(a record only: removing that login removes nothing), the scopes it lacks with
what each is for (`OperatorCloudAccount::SCOPES`), and how many servers are in
it. **Check** asks the provider again. The company `Joinery Test disposable`
flags the test account. A credential the provider answers 401 (at a check, or
in the daily transfer watch) marks the entry refused until it is replaced.
**Remove** erases the credential and keeps the row, so a server in it still
says which account it was in.

**The default** (`server_manager_default_cloud_account_id`) is where a server
goes when nobody chose one: a hosted order, and the account chosen first on the
forms that create a server. It cannot be a Linode sign-in, nor the test account
on a node that holds any other account (`OperatorCloudAccount::default_refusal()`),
and it cannot be removed while it is the default.

**Every server knows its account.** A provision in one of our accounts names it
(`cvp_oca_operator_cloud_account_id`; a customer's names
`cvp_cca_customer_cloud_account_id`, never both). A new server in our accounts
that names none is given the default when first saved. Everything done to a
server later uses its own account's credential
(`HeldCloudAccounts::provision_token()`): creating it, reverse DNS, a handover,
an address swap, shutting a hosted site down, removing its node. A server whose
account is no longer held (removed, refused, or a sign-in that expired) gets the
reason instead, and removing its node asks the person to confirm the machine is
gone. The hosted tier's transfer-pool alert reads the default account.

Customers' connected accounts are listed below ours, read-only: who connected
each and when. Only the customer reconnects one, from their own Connect page.

Migration `sm_019_operator_token_to_account_list` makes a single operator token
held in settings the first entry and the default, and points every server and
transfer record that named no account at it.

A server is created where the provision says, else at the Hosting tab's region,
plan and image when it is at Linode (those settings are Linode's), else at its
driver's own defaults (`ProvisionCustomerCloud::create_choices()`; Hetzner's:
`fsn1`, the cheapest x86 plan sold in the provision's location, `ubuntu-26.04`).
A plan is sold in a location when Hetzner's own entry for that location in the
plan's list says it is available now and not being withdrawn; its price list keeps
naming a location the plan is withdrawn from or sold out in. The provider's own disk
encryption on it is recorded when it is made (`cvp_provider_disk_encryption`),
and for an older server from the provider's listing by the daily transfer watch,
for the node page's count of the layers its data sits under
(`DataRootLayers`). Hetzner has no disk encryption of its own.

The management node holds no SSH key. A machine it creates gets a one-time
root password that the install job uses for its single bootstrap session and
that is retired once the machine's agents are admitted. At Hetzner, which
cannot be told one, the server's first-boot user data sets it from its SHA-512
hash with `runcmd` (`usermod -p`), after cloud-init's password module: Hetzner's
own vendor data sets root a random password of its own in that module, after
any the user data names there, and asks for it to be changed at first login,
which the user data turns off. Only the hash is in the user data, which
Hetzner's metadata service hands to any process on the server. Nothing of the
platform's is ever placed on the machine. The install runner never reads a
key file, and `config/` on the management node contains none.

### Test-account cleanup

A management node that holds a cloud account flagged test (see *Cloud accounts*) cleans each such account up on its own, one at a time. The **Delete Old Test Servers** task (`TestCloudCleanup`) runs hourly and is off until turned on under Scheduled Tasks. It deletes servers, and storage volumes attached to nothing, that are older than `server_manager_test_cloud_max_age_hours` (default 24).

**The safety catch is the account's own name.** Every run reads the account's company name from the provider (`CloudAccountCleanup::accountCompany()`; on Linode, Account Settings, which needs `account:read_only`). It deletes only when the name is exactly `Joinery Test disposable`. On any other account, or when the name cannot be read, it deletes nothing on that account. It then records the refusal, and `plane:test_cloud_cleanup` opens on this management node's own node, naming the account and the company it found. The incident clears when a run passes or the task is turned off. A live account flagged test by mistake, or the task turned on at a production management node, therefore deletes nothing. No site setting can make an account count as disposable.

**Never deleted:**
- anything tagged `keep` at the provider (any case);
- a server at the address of a live managed node here, IPv4 or IPv6, by the transfer watch's address rule. It is listed as held, so the operator tags it `keep` or removes the node first.

A live-proof test build's node (`mgn_agent_test_build`, see *Live proofs of agent words*) holds no server: its server goes at its age like any other, and the node is removed with it, so a run killed part way leaves nothing behind.

A server or volume whose creation time cannot be read is left alone.

A deleted server's address records go with it: every A and AAAA record in the test zones (`TestCloudCleanup::DNS_ZONES`, joinerytest.com) that names one of its addresses, under any name, whichever test account the server was in. The zone lives in a Linode test account, and that account's own token removes the records (`TestCloudCleanup::zone_holder()`, `LinodeDnsDriver`, which needs `domains:read_write`). A record left on a released address would name whoever the provider gives that address to next. Records that cannot be read, a record the provider refuses, or no held account holding the zone are each reported as a failure of that run; the servers still go.

The Cleanup tab of the Provisioning page shows the task's state, each test account with the scopes a run needs that it lacks (`linodes:read_write`, `volumes:read_write`, `account:read_only`, `domains:read_write`), and the last run per account: what it deleted, held and kept, or why it refused. **Preview the next run** asks the provider what a run would do now on each test account and deletes nothing. A failed delete, or volumes that cannot be listed, is reported in the run's message without stopping the rest.

### What stays manual

The Provisioning page reports these, and cannot do them:

0. **Job agent.** Every job the pipeline creates sits pending until a
   joinery-agent polling this site's queue claims it. Install the agent on
   the management node's host (`sudo bash joinery-agent-installer.sh --config
   <Globalvars_site.php path>`, built from the agent repo's
   `build_installer.sh`); hosts without systemd are detected and supervised
   via cron. The page's agent badge must show Online before anything below
   can execute.
1. **Per hosting product.** For customer-cloud fulfilment, pick **Customer
   cloud server** under Purchase grants; that stamps the provider, and the
   buyer configures the site on `/profile/server_manager/configure` before
   paying — nothing is attached to the product by hand. For a buyer-account
   product, put the Connect link
   (`https://<management-node-host>/profile/server_manager/connect_cloud`)
   in the after-purchase message; the Connect page is deliberately in no
   member menu. For shared-host products, attach the domain question as a
   requirement instead — the attachment is what makes an order a hosting
   order. To sell domains on the configure page, see **Managed domain
   registration** below.
2. **Shared-host fulfilment only.** Opt at least one managed host in from the
   dashboard (Edit → Max Sites + Provisioning Enabled). Its IP is sent to
   customers as the DNS A-record target, so it must be routable.
3. **Buyer-account fulfilment only.** Register the OAuth client in Linode
   Cloud Manager (Profile → OAuth Apps → Create OAuth App, **not** public,
   callback `https://<management-node-host>/oauth_callback`) and enter the
   client id and secret at **Admin → System → OAuth Providers**. The referral
   URL from Cloud Manager → Profile → Referrals goes in the page's field.

### Verifying end to end

1. Place a test order for a hosting product with a test domain (for a
   customer-cloud product: configure the site on
   `/profile/server_manager/configure`, Continue to payment, pay).
2. Wait up to 15 minutes for the next provisioning run.
3. A new node appears under **Admin → Server Manager** at
   `install_state = installing` (shared host), or the buyer's Connect page
   shows progress (buyer account), or the buyer's sites page does (hosted).
4. When the install job completes, the welcome email reaches the buyer.
5. Point the test domain's A record at the node IP.
6. The node's SSL badge flips from `pending` to `active` on the next
   Provision Pending SSL run once certbot succeeds.

### Failure modes

| Symptom | Likely cause |
|---|---|
| No node appears after 15 min | API credentials wrong (the page's probe badge), or (shared host) the question not attached to the product — check the provisioning task's last run in Scheduled Tasks |
| `[managed-hosting] Paid but not activated: order item N` | The paid line's draft was not `pending_payment` (edited after the line was made, deleted, or another buyer's), or its name is held by a live provision. The order is paid; look at the provision on the dashboard and resolve with the buyer |
| A site card says the name was taken before it could be registered | The registrar reported the name gone after payment (`rdm_taken_time`). The buyer chooses another name on the same card, at the paid price or less; the row returns to `pending` and registers on the next tick. If the box already exists, the operator is emailed to rename it by hand |
| Node stuck at `install_failed` | The install job failed — open the job, fix the host, Retry |
| SSL stuck at `pending` for hours | DNS not pointing at the node — `dig domain.com` |
| SSL badge `failed` | ~16 hours of certbot failures — job output names rate limits or DNS |
| Welcome email not received | The store's queued email queue; `welcome_from_email` must be SPF/DKIM-authorised |
| Domain field says registration is unavailable | No registrar credentials, or no domain-year product selected — the page's Sellable badge names which |
| Domain row `pending` with a transient error | The registrar is refusing or unreachable; the queue page shows its words. An IP-allowlist error names the address to add |
| Domain row `failed` | Terminal — name taken or refused. Resolve with the buyer, then Retry from the Domains page |
| `[managed-domain] Paid but never registered: order N` | The buyer kept the domain line and dropped the hosting line. Refund the domain line, or register and file the row by hand. Reported once per order |
| Domain row `failed` saying the order did not pay for it | The domain-year line was removed or repriced in the cart. Nothing was registered — settle the difference, then Retry |
| Domain registered but mail records never publish | The box's mailbox provisioning refused, or the mail leg stopped — the row's error says which. A DKIM key not yet generated keeps the step open on purpose |

### Customer-Cloud Fulfillment

The buyer connects their Linode account once at
`/profile/server_manager/connect_cloud` (the **Connect page** — also the
re-connect page if a grant is later revoked). The grant flows through the
[platform OAuth2 core](/docs/oauth2.md) (provider `linode`, consumer purpose
`customer_cloud`, scopes `linodes:read_write`, and `ips:read_write` with
`firewall:read_only` for a site copy's switch-over by IP swap — no account or
billing access). Tokens are SecretBox-encrypted on the buyer's
`CustomerCloudAccount` row.

Each provision is a `CustomerCloudProvision` row that the
**Provision Customer Cloud** scheduled task advances:

`pending_connect` → (grant arrives) → `ready` → instance created on the
connected account → `booting` → running + IP → ManagedNode + `install_node`
job → `installing` → `done` (or `failed`, which alerts the ops address).

Provisions have three origins (`cvp_origin`):

- **buyer** — created by the buyer on the configure page before paying, and
  activated by the paid order (see **The buyer configures first** above).
  Installs fresh + Docker, and sends the buyer welcome email on completion
  (the order-item linkage drives it). The welcome email carries the A-record
  instruction when the buyer brought their own domain (no registration row on
  the order), and says there is nothing to add when this plane registered it.
- **order** — created by the Orders poll from a shared-store order. Starts at
  `pending_connect`, installs fresh + Docker, and sends the buyer welcome
  email on completion.
- **admin** — created by the Install New Node form's cloud-instance target.
  Starts at `ready` (the admin picked an already-connected account), carries
  its install parameters on the row (`cvp_docker_mode`, `cvp_install_mode`,
  `cvp_source_node_id`, `cvp_port`, `cvp_sitename`),
  and sends no welcome email. The row belongs to the grant owner
  (`cvp_usr_user_id`), so a stale grant is re-connectable by the person who
  can actually re-consent.

If the buyer hasn't connected yet, they get an email pointing at the Connect
page; the page's create-account link uses `server_manager_linode_referral_url`
so new Linode signups carry the referral credit. A token-refresh failure or a
provider 401 parks the provision back at `pending_connect` and flags the
account link — a fresh grant resumes it automatically.

**Customer-owned node semantics:** the resulting node is a normal
`ManagedNode` (installs, upgrades, uptime checks, SSL all apply). Provisioning
is keyless — the instance is created with an install password sealed onto the
provision row (`cvp_root_pass_sealed`) and no SSH key of ours, so
`mgn_ssh_key_path` is empty; the machine is managed by its own agent, which
joins at install and whose join a human approves here.

**The install password is retired.** `cvp_install_password` tracks
it: `held` from the moment it is sealed; once the provision is `done` and
every agent the install put on the machine has been admitted (the site's
agent, and on a docker box the host's own agent too), the provisioning task
queues a `retire_install_password` job, which writes the sshd drop-in that
turns password login off, over the password (`retiring`). The executor completes that
job only after it tried the password again and the machine refused it; the
next pass erases the sealed password (`retired`). A job that could not prove
the refusal fails, the password is kept (`retire_failed`, reason on the row,
ops emailed), and re-running the job from its detail page tries again. A
provision that fails with a live instance keeps its password too: the
password goes only when the install is complete. The dashboard's provisions
table keeps a finished provision on the board until its password is retired,
and says where it stands.

**During the beta, a fresh install's password may be kept** as a way back in
if a machine breaks in a way its agent cannot fix
(`specs/implemented/beta_break_glass_root_access.md`). It is kept, in state `kept`, when
the agents are admitted and every one of these holds: the setting
`server_manager_beta_root_access` is on (default on); the install offered it
(`cvp_root_access_offered`, set only on a fresh install with no root keys
supplied, which passes `--beta-root-access` to `install.sh site` so the site's
setup wizard offers its owner the checkbox); no root keys were supplied; the
site's node has not reported its owner's `root_access` as `off`; and the holder
is fit. The holder, `server_manager_beta_root_access_holder` (chosen with a
user picker; no default), is fit with a vault and a second factor. Otherwise the password is
retired as above.

Keeping moves the password from SecretBox into the holder's vault, with nobody
present: `cvp_root_pass_vault_sealed` is a sealed field (the four convention
columns, sealed to `cvp_sealed_owner_user_id`, never the buyer), so a leak of
this management node's database and config does not reveal it. The row is
read back sealed to the holder before the SecretBox copy is cleared; any
failure clears the vault column and returns the row to `held`, which retires
it on the next pass. The sweep leaves a kept row alone, its node gone or not.

On the dashboard, a kept box's row shows its holder **Reveal root password**
(shown once, on a page of its own) and **Retire**; above the list, **Retire all
requested** (boxes whose owner turned it off) and **Retire all kept**; the
box's node page (its **Emergency root access** box, on the site's node and on
a Docker host's own node) offers its holder Reveal and Retire too, answering
there. Each needs the viewer to be the row's holder, their vault open and their
second factor used recently; a holder who has not confirmed it in the last five
minutes is offered **Confirm your passkey to reveal or retire** in their place,
and comes back to the buttons. Retire seals the password back under SecretBox and
returns the row to `held` with `cvp_root_access_offered` cleared, so the next
pass retires it and the box is never kept again. Every Reveal and Retire is a
row in the general event log (`break_glass_reveal`, `break_glass_retire`,
naming the provision and node), listed on the box's dashboard row and on its
node page's **Emergency root access** box. Nothing removes a kept password
unattended. `plane:break_glass_attention` (warning) lists kept boxes whose
owner asked, whose node is gone, or that are sealed to someone other than the
current holder; `plane:break_glass_no_holder` (critical) is raised while the
setting is on and the holder is unset or unfit. Both are raised on this
management node's own node.

The plugin declares `vaultConsumer.reseals`, and its bootstrap registers the
re-seal of `CustomerCloudProvision` rows, so a rotation of the holder's key
re-wraps kept passwords. A key rotation refuses on a management node where
that callback does not load.

**A join from a provisioned machine is checked with the provider.** The join
records the client address this plane can vouch for (behind Cloudflare, the
client header only from a verified edge). When that address is a provision's
instance, the join card names the provision, instance and age; approval asks
the provider for the instance and refuses unless it is `running` at that
address and the node being approved is the provision's site or a host record
at its address. A second join from that address after the node's agent is
admitted is refused and logged as an alarm. A docker provision's
container gets `mgn_mgh_managed_host_id` (its placement record, minted at booting), and
the host's own agent, once approved, is named in `mgh_mgn_managed_node_id`. The
server is the customer's property: cancelling their subscription stops
management, never touches the instance.

The install itself runs from this management node, not from a node agent: a
machine we just created has no agent yet. `ManagementJob::createJob` creates
every bootstrap job (`ManagementJob::BOOTSTRAP_JOB_TYPES`: `install_node` and
`retire_install_password`) in status `queued`, which the agent channel's claim
(`pending` only) never matches; the `Run Install Jobs` scheduled task spawns
`plugins/server_manager/utils/run_install_executor.php` (detached, single
instance, log at `logs/install_executor.log`), and `InstallJobExecutor` claims
each queued job, runs its `local` steps here and its `ssh` steps on the target
over the sealed root password (`sshpass`, password in the environment, never on
a command line), and writes the same output and status contract the agent
runner writes. Every shape travels it — fresh, copy (a dormant copy of another
node's site) and bare (a Docker host with no site) — as one session (`specs/ssh_single_bootstrap.md`). Fleet enrollment
seeding (the mailbox plugin's `FleetProvisionSeeding`) is the `fleet_enroll`
primitive on the new site's own agent, so it waits for that agent to pair:
`cvp_fleet_seed_state` goes `pending` at completion, `dispatched` once the node
reports the primitive, then `done` or `failed` by the job's answer.

**One layer of encryption, never two.** A server whose data root is encrypted
by us has the provider's own disk encryption off; every other server keeps the
provider's default. `cvp_data_root_encrypted` decides it, set when the
provision is created and only where the core setting
`data_root_encryption_offered` is on (off by default): a hosted order or an
activated Managed draft on our account, Remote Install into one of our
accounts, and a site copy onto a new server, whose layer the Copy tab decides
from where it is born (`SiteCopyRunner::copy_encryption()`): in one of our
accounts, ours; in its owner's account, ours sealed to the site's own proven
recovery key (`cvp_recovery_public_key`), or the provider's default where none
is proven. Any other server in a customer's own account is born unencrypted.
For such a provision the task refuses, before anything is created, when the
key it is sealed to is not proven (this management node's, unless the
provision names the site's own) or the region is a distributed one (where
Linode's layer cannot be turned off); creates the instance with `disk_encryption: disabled` (sent
only where the region lists Linode's "Disk Encryption" capability) and
`swap_size: 0`; saves `cvp_instance_id`, then records the provider's answer in
`cvp_provider_disk_encryption` (`enabled`, `disabled`, or `unknown` when it
gave none) at create and again at `booting`. A layer reported on fails the
provision before anything is installed, leaving the instance for its owner to
delete; unknown proceeds. The install then runs `install.sh docker` or
`install.sh server` with `--encrypted-data-root
--recovery-public-key=<that key>`.
An adopted server records what the provider's listing said and is never marked
encrypted by us; relays are born with the provider's defaults. The node page
says it in one line under the address: "Data root: encrypted by us · provider's
disk encryption: off" (or on, or unknown).

**Encrypting in place keeps to one layer too.** The node page's **Encrypt the
data root** refuses a machine whose provider's layer is on, except a management
node, which never copies itself and may keep both. What it says to do instead is
`DataRootEncryptionOffer::provider_layer_fix()`: copy the site onto a new
server, into its owner's account once the site's recovery key is proven where it
is not. A site's own Backups page asks root to encrypt in place and cannot read
the provider, so this management node records the layer on each machine (the
`data_root_provider_layer` word, sent by `DataRootLayers::push_provider_layer`
whenever a host report's `data_root_provider_layer` says otherwise than it
knows); `joinery_data_root.sh encrypt` then refuses there as well, unless
`--keep-provider-layer`, and the page says to copy the site instead.

**The escrow holder.** This management node holds the escrow secret of each
encrypted node enrolled with it (`dre_data_root_escrows`, `EscrowEndpoint` at
`/api/v1/agent/escrow/*`) and answers its boots; the node page's encryption
card shows what it holds, the last answer and refusal, the addresses a node may
ask from, and **Unlock it from here** with the recovery key in the browser. A
machine that is not one of its nodes asks to enrol on the dashboard's
**Machines asking to have their key held here**, where a person compares the
fingerprint. For a node in one of our accounts whose key holds
`events:read_only` the holder is Tier 2 (`EscrowTier2`): it reads the
provider's activity log before it answers, refuses a boot after a rescue boot,
a root-password reset and the rest, and offers **I did this myself** or a copy
from backups. The full picture: [Encrypted data root](/docs/encrypted_data_root.md).

Settings: `server_manager_customer_cloud_region` / `_type` / `_image` (instance
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

With both set, the configure page offers *Register one for me*; without them
it offers only *I own one already*. `server_manager_domain_tlds` (default
`com net org`) bounds what can be asked for. A registrar **promotion code**
(`server_manager_namecheap_promotion_code`, sealed, set on the same card)
rides every quote and every registration alike — `PromotionCode` on
`users.getPricing`, whose `CouponPrice` becomes the quote when the code
applies to the ending, and the same `PromotionCode` on `domains.create` — so
the buyer pays what the registrar charges. An ending the code does not cover
quotes and registers at `YourPrice`.

Both gates check the thing they name, not just the setting: a domain-year
product that was deleted, or whose version was deactivated, reads as unusable
and the checkout field refuses — because a silently skipped cart line would
mean registering a domain nobody was charged for.

One payment consequence worth knowing: a subscription hosting line plus a
one-time domain line is a mixed cart, and PayPal cannot process one
(`ShoppingCart::is_paypal_available()`). Deployments selling subscription
hosting with managed domains take card payment through Stripe.

### What the buyer's answer becomes

`ManagedDomainIntake` is the one gate for what a name and a registrant may
be, called by the configure page and by the taken-name alternate: the name has
to be registrable, in an offered ending, available, and not premium, and the
contact block has to be complete (including a phone number with an explicit
country code — a bare number is refused rather than guessed at, because
guessing puts a stranger's country code on a public WHOIS record). The quote
is the registrar's, live, at the moment the buyer presses Save and continue.

That quote is frozen on the draft and drives two things. It becomes a
**second cart line** against the domain-year product when the buyer continues
to payment, priced through the existing `prv_price_type = 'user'` path — a
line rather than a surcharge because a line carries its own recurrence, and a
one-time fee folded into a subscription line would bill every cycle. And at
activation, `CustomerCloudFulfillment` files the `rdm_registered_domains` row
for the pipeline to work from, from the draft's sealed registrant and frozen
quote. Nothing price-shaped is ever read from a browser.

**When the name is gone after payment**, the registration phase parks the row
`failed` with `rdm_taken_time` stamped, and the buyer's sites page offers an
alternate name on that card. The alternate is gated the same way (shape,
ending, availability) plus one rule: its quote must not exceed what was paid
(`rdm_price_paid`). Submitting it returns the row to `pending` under the same
paid-line guard; the provision's own domain and slug follow while no box
exists yet, and once one does the operator is emailed to rename it by hand.

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

## Joinery-run services for self-hosted sites

A self-hosted site can send its email through this plane's SMTP2GO account
and keep its offsite backups in this plane's backup storage, on credentials cut to its
own slice, for as long as a paid-through date on this plane says so. The
customer's own Managed site uses the same legs because that is what Managed
is; a self-hosted site *rents* them, and the difference is only how the
credential reaches the box. The master keys never leave this machine.

**The account link.** Nobody types a key. The site's setup wizard (Email or
Backups step) shows a **Connect your getjoinery account** button; it mints a
single-use state bound to the owner's browser session and sends them to
`/services/authorize?site=&return=&state=` here. Signed out, they go through
the platform's sign-in (sign-up beside it) and come back; signed in, the page
names the site and the account and takes one click. Approval is a POST that
mints an `ApiKey` for the site (`ServicesConnect::mintKey`: machine, read +
write, no delete, named *Joinery services* — `apk_name` is varchar(32), so the
host is recorded on the tenant rows, not the key) and redirects to the site's
own return address with the pair and the state, once. The return must be on
the site's host over https. One active key per site per account: a
re-connect mints a new key, moves the site's rows to it and deactivates the
earlier one. Connecting is the site's first contact: one `ServiceTenant` row
per service is created at `unpaid`.

**The tenant row** (`svt_service_tenants`, `ServiceTenant`) is one site's
standing with one service (`mail` | `shelf`), keyed by the connected key
(`svt_apk_api_key_id`); the host is a label refreshed on every status call.
What decides is `svt_paid_until`: null means never entitled. States: `unpaid`
(no date yet), `provisioning`, `active`, `suspended` (the date passed and the
grace ran out), `released` (the customer left, or the account holder
disconnected). The four shared states are the core `ServiceTenantLadder`'s
vocabulary. The figure (`svt_figure`) is sends this month for mail — the
provider's month-to-date count, read hourly by the reconcile, nudged between
reads by the SMTP2GO webhook — and the ledger's completed bytes for backup storage.

**The actions** the site calls over its key, all under
`/api/v1/action/server_manager/`: `services_enroll` (service, host),
`services_status` (host), `services_release` (service), and the five shelf
broker actions below. `JoineryServices` is the operator side of all of them.

- *Mail enrol* builds everything at once (`Smtp2GoLeg`, shared with
  `ProvisionHostedMail`): the subaccount capped at
  `server_manager_hosted_send_allowance`, the sender domain `mail.<host>`
  (zero DNS records from the provider fails loudly), and one SMTP user. The
  answer carries the send values (host, port, username, password, sender,
  HELO, hostname) and the DNS records; the site writes them and publishes
  the records. The password is never kept on this plane: a second enrol
  removes the tenant's SMTP user and mints a fresh one in the same
  subaccount, so the answer is always a working credential. A host change
  adds the new sender domain and releases the old. The status answer
  reports `domain_added` until the provider verifies the domain, probed once
  per status call.
- *Shelf enrol* mints nothing: the answer is the tenant's slug, backup storage
  path prefix, `{prefix}/{slug}/`, the 90-day retention promise and the
  bucket's coordinates — no credential anywhere in it.
- *Status* is umbrella contract C2 per service: `figure`, `allowance`,
  `paid_until`, `state`, `notice`, the first door (`action_label`,
  `action_url` — the referral settings `server_manager_smtp2go_referral_url`
  and `server_manager_storage_referral_url`, `''` when unset), `manage_url`
  (the account's Connected sites page), plus the ready-made banner row
  (`label`, `used_label`, `allowance_label`, `percent`).
- *Release* removes mail's SMTP user and sender domain from its subaccount and closes
  it now (a removal that fails keeps its name on the row, and `ServiceTenantWatch` reopens the
  subaccount, removes what is left and closes it again until both are gone), or marks backup storage tenant released so the broker
  refuses it; backup storage is kept 90 days from that day (`svt_prune_after_time`) and
  then pruned. Idempotent. A new paid-through date on released mail waits for the site's
  next enrol, which reopens the subaccount, adds the domain and mints a user.

**The backup storage broker** (`ShelfBroker`, signing with `S3Signer::presign()`): no box ever holds a
storage credential. For every object it writes or reads a site asks the plane
for a presigned URL — one request, one key, one operation, good for an hour,
SigV4-signed with the plane's own credential for the target of the storage
space it is signed in (see [Storage spaces](#storage-spaces)): a run is taken
in the tenant's active space and every link signed for it stays there; a read
names a run, or a space (`space_id`, the active one when absent). Presigned URLs are the S3 standard, so backup storage is any
S3-compatible store by construction. Five actions: `shelf_begin_run`
(profile, chain, artifacts with sizes → a run id, its space and base key
`{space}{profile}/`, refused with the sentence the site's run history
records when the ledger's bytes plus the declared sizes would cross the
allowance, when the tenant is not usable, or when the chain's objects are in
a space the tenant was moved away from); `shelf_sign` (run id, name,
operation — `put`, `multipart_create`, `multipart_parts` in batches of ten
and `multipart_complete` need an open run and a key inside its base key, and
a write for a key a finished run completed answers `exists` with the size and
sha256 recorded for it instead of a link — nothing is written twice;
`get` needs no run: with run id 0 the name is relative to one of the
tenant's spaces, as `shelf_list` answers it; nothing signs a delete);
`shelf_list` (a prefix inside every live space of the tenant; each object
names its `space_id`, its key relative to that space);
`shelf_finish_run` (the objects completed, each with its bytes and sha256;
the ledger marks them with the hash — one named without it is not completed —
and everything else the run signed is cancelled — an open multipart aborted
at the provider with the plane's credential, a row never completed marked
pruned — and the run's ledger file is written to `{space}ledger/{run id}.json`);
`shelf_verified_run` (a finished run whose chain the site opened and read and
found sound: `svr_verified_time`, which retention and the draining release
read);
`shelf_status`
(the C2 fields plus `writable` and `readable`). Writes need a usable tenant;
reads (`shelf_list`, a `get`) are allowed to a suspended or released tenant
until `svt_pruned_time` is set, so the retention promise is a readable one; a
tenant never entitled reads nothing.
The ledger (`svo_shelf_objects`, `ShelfObject`) holds one live row per space and
key — owner's space, run, key, bytes, sha256, chain, signed and completed
times. An object that goes keeps its row, with `svo_pruned_time` and the cause
(`retention`, `lapse`, `abort`, `reconcile`); every count reads live rows only. A
key a finished run completed with its hash is never signed again; one a run
signed and never completed (a failed run's) is taken by the run that signs it
next, so a retry is never refused for its own leftovers, and a row the
reconcile adopted from a listing carries no hash and is taken the same way. A
key signed again while its row holds a multipart upload id has that upload
aborted at the provider first, so a retried `multipart_create` orphans
nothing — so the figure is exact and immediate; runs are `svr_shelf_runs`
(`ShelfRun`), spent once finished or aborted. The reconcile neither counts nor
adopts the run ledger files in a space; they are the plane's own record.

**The reconcile** is the `Services` phase of `ServerManagerAdvanceProvisioning`
(`ServiceTenantWatch`), last, every tick. Per tenant row: the allowance is
re-read and a changed one re-sets the subaccount limit; the date is compared
every pass and the row walked down the ladder — `server_manager_services_grace_days`
(14) after the date passes the row is suspended (mail's subaccount closed;
the backup storage broker refusing) and the retention clock starts, and a new date at
any point before the prune reactivates in place; an act the provider refused
(unreachable) is retried next pass. Mail's figure is read from the provider
hourly. The backup storage ledger is reconciled daily, space by space, against
a listing of that space's own target (an object it lacks is marked pruned, a
multipart still open at the provider aborted first; one the ledger lacks is
adopted at its listed size; a space that cannot be listed is reported and left
alone); a run open longer than 36 hours is aborted on the plane's side,
its open multipart cancelled with the plane's credential; every active
tenant's backup storage is pruned to the newest `server_manager_services_shelf_keep_chains`
(4) chains per profile across its spaces, chains whole, each from its own
target, a chain with an open run never touched, and only as `BackupSafety`
allows (the newest verified chain and everything newer stay, the newest stays,
and a chain goes a day after a pass first found it surplus — see *What
retention will not delete* in `docs/backups.md`); a draining space is kept
whole until the active space holds a run the site reported verified, then
retired once empty;
a suspended or released tenant whose prune-after day has come loses
everything in its spaces once, and the row says so.

**Node-linked rows: a site moved off Managed.** A hosted site handed to its
customer's own Linode account ([Hosted tier § Moving a site](hosted_tier.md#moving-a-site-to-its-customers-own-linode-account))
keeps its mail and backups through two rows linked to its node
(`svt_mgn_managed_node_id`) instead of a key — it never ran the Connect flow, so
the site itself cannot call enrol, status or release. Only the reconcile and the
operator reach them. The mail row is the Managed leg's subaccount, SMTP user,
sender domain and records, carried over; from there the meter, suspend and close
work as for any tenant. Backup storage is the node's own fleet backups in its storage spaces, not the
broker: the figure is what they occupy
(`mgn_backup_shelf_bytes`), the allowance pauses them and lifts its own pause,
suspend and release switch them off with a marker and reactivate switches them
back on (`NodeBackupShelf`), and the prune empties what this management node
took in each of the node's spaces (`{space}manager/`), never the site's own
backups beside them; the
ledger, stale-run and chain-retention passes do not apply. With no status poll
on the box, the reconcile composes the site's `services` banner from the node's
rows and pushes it over the agent when it changes, and the customer gets one
email when grace starts and one at suspension (`services_moved_grace`,
`services_moved_suspended`). Connected sites shows such a site as *moved from
Managed* with no Disconnect — one click would close its mail; the customer
leaves our mail through their own wizard's Email step, then the operator
releases the row.

**Operator pages.** The **Service Tenants** page
(`/admin/server_manager/service_tenants`) lists every row with state, figure,
date and the ladder's timestamps, and carries the two acts: **Grant** (write
the paid-through date — what entitles a tenant in this phase; a stopped row
with a date ahead comes back in place now, an unpaid row waits for the site's
next enrol) and **Release**. Managed sites have no row and never appear; a
moved site's node-linked rows do, naming their node. The
account holder's **Connected sites** page (`/profile/server_manager/services`)
lists their linked sites with each service's state, date and figure, and
carries **Disconnect**, which deactivates the site's key and then releases
every service it holds; a provider refusal on one release is surfaced after
the rest have run, with the key already off.

**Removing an account waits for its services.** An account is removed for good
only once none of its tenant rows holds anything at a provider
(`ServiceTenant::provider_holding()`): a mail row whose subaccount is still open,
or a backup storage row whose copies are not yet pruned (copies in its storage
spaces, or a moved site's fleet backups while its node is still there),
refuses the removal whole, and the dry run names the site and what it holds.
Release the row first (the account's **Disconnect**, or **Release** on Service
Tenants); a backup storage row then waits out its 90 days, the prune empties its
spaces, and the removal goes through, taking the rows and their spaces with it.
The removal itself calls no provider.

**The webhook** (`ajax/smtp2go_webhook`) maps a delivery to a Managed site by
its SMTP username first; one it cannot map is tried against the tenant rows'
usernames and moves that tenant's figure.

## Backup Targets

Backup targets define where the backups this management node takes, and the backups it keeps for customers, are stored. Nothing is inferred from "the one enabled target".

### Storage spaces

Every stored backup on this management node is in a **storage space** (`sps_storage_spaces`, `StorageSpace`): one owner's folder on one target — a node's (`{target folder}/{node slug}/`) or a backup storage customer's (`{target folder}/t{id}/`). Every reader reaches a backup through its space, never through "the target in use now".

| State | Meaning |
|---|---|
| `active` | takes the owner's new backups; an owner has at most one |
| `draining` | the owner was moved elsewhere, removed from the dashboard (a node) or released the service (a customer): read, restored and pruned only, takes no write, kept whole until the owner's active space holds a chain a verify has passed (a node) or a run with something stored that the site reported verified (a customer) since it became active, then aged out by the owner's normal retention |
| `retired` | a draining space whose whole folder is empty |

On one target no live space's folder equals or contains another's, so a customer `t5` and a node slugged `t5` can never list, prune or adopt each other's objects. The target's folder is stored in one form (`BackupTarget::normalise_prefix()`), and no two targets share a name: a node's chain follows the name of the target it is sent to (below).

**Where new backups go** (`server_manager_backup_target_id`, chosen on the Targets page among the targets switched on) is where every new node (`ManagedNode::open_default_backup_space()`, called by each path that creates a node, once it is saved) and every new customer of backup storage (at enrol) opens its first space. A node with no space has no backups taken from here.

**Moving.** *Move* on a node's Backups tab, or on a customer's row of the Service Tenants page, sends the owner's new backups to another switched-on target: that target opens (or gives back) the owner's space there and the old one starts draining (`StorageSpace::move()`). *Move everyone* on a target's page does the same for every owner whose new backups go there (`move_everyone_off()`). Nothing is copied: the next backup starts a full one on the new target — the broker takes the run in the owner's new space, the node starts a new chain when the space changes, and the broker refuses to extend any chain whose objects are in a draining space — and the old backups stay readable and restorable where they are until they age out.

`JobCommandBuilder::node_space()` returns a node's space by id, or its active one; `get_target()` is the active space's target switched on or off; `write_space()` / `write_target()` return it only while its target is switched on, for new backups and uploads. Downloads, Prepare, Verify and Bring them back name the space a backup is in (`space_id`, from the listing), and a chain named without one is found in whichever space holds it.

### Supported Providers

Every provider in `StorageProvider` (Backblaze B2, Amazon S3, Linode, Cloudflare R2, Wasabi, DigitalOcean Spaces, Hetzner, generic S3-compatible). The form is `BackupTargetForm`, the same one the core Backups page draws; each provider shows the region and endpoint fields it asks for, and Backblaze's are read from the key at save time. A target's location, how it is switched off and when it may be deleted follow [Backups § Saving a target](../../../docs/backups.md#saving-a-target).

All providers authenticate against their S3-compatible endpoint via AWS SigV4 signing performed by `S3Signer.php`. There is **no per-provider CLI dependency** — uploads, downloads, deletes, and listings all run as direct HTTPS calls, from the management node (web tier) or from the node's own agent.

### Configuration

1. Go to `/admin/server_manager/targets` and click **Add Target**
2. Select a provider, enter bucket name, path prefix, and credentials
3. Choose it under **Where new backups go**, so new nodes are given it
4. For a node that already exists, choose the target under **Move new backups to** on its Backups tab

### Upload Path Structure

All providers use: `{space}{profile}/…` — `{target folder}/{node slug}/{profile}/…` — where the profile is `manager` for the backups this management node schedules and `site` for the site's own. Chains sit in `chain-*/` folders, offloaded files in `objects/`, and a standalone archive (a single run, or one re-uploaded from a node's Backups tab) directly in the profile folder beside its `.keys.json`.

Example: `joinery-backups/empoweredhealthtn/manager/empoweredhealthtn-04_11_2026.sql.gz.enc`

### Credential Storage

Credentials are stored on the `bkt_backup_targets` table using a unified shape for every provider:

```json
{"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
```

`bkt_credentials` holds a target's one key, SecretBox-sealed at rest, and it never leaves this management node. Nodes and customers reach backup storage through links it signs with that key (see [The backup broker](#the-backup-broker)).

A persisted job never contains a credential. A node's **backup run** and **re-upload** (`backup_run`, `upload_backup`) carry a placeholder, `__SM_BROKER_<space id>_<backup|upload>__`, that the agent channel turns into a broker run when the job is handed out; a job naming a key slot is refused at hand-out. A node-side **download**, **Prepare**, **Verify** or **Bring them back** carries presigned GETs for the objects it names, signed here; a **cloud delete** runs on this management node, in-process.

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
- **Backups** — one row per backup run in the node's backup storage, newest first (when, full or incremental, who took it, size), with the last backup, the last full backup and the oldest backup held stated above the list; read from each chain's `manifest.json` by `BackupChainListHelper`, which lists the node's own folder in every live storage space, every page of it (a run kept on a target the node was moved away from says so), so ten thousand offloaded-file objects under one node never push a manifest off the end of the list. Restoring a run replays the last full before it and every incremental up to it, in order. Chain artifacts are deliberately absent from the flat file table above — listed there, `files-0003.tar.gz.enc` invites a restore of one incremental with no full under it, which restores nothing at all. **Last verified restorable** is stated with the three facts (level name, date, the node's own counts, or the reason it failed), and each run's **Verify** button opens a dialog with two choices, *Open and read* and *Rehearse a restore (needs about N free on the node)*, the room worked out from the runs' recorded sizes the way the node works it out before downloading. Either creates a `verify_backup` job; the poller reports what the node said, and a reload shows it above and on the node's card. An **Offloaded files in backup storage** row totals the object store from the same listing — "N objects, X GB by this management node; last indexed at the run of …" — and, when a run in backup storage carries an offloaded-files index, offers **Bring them back**: the `restore_objects` survey job in `missing` mode, whose pages follow as their own jobs; the node's own Backups page says what its file store is missing, the plane only asks

### What a restore asks, and what it decides

Every restore form asks one thing and decides the rest.

**It asks for the domain**, pre-filled from the node's recorded URL. This is the one value a restore cannot work out for itself: a rebuild keeps the site's own domain and cuts DNS afterwards, while a rehearsal must not claim it, and the same backup on the same node wants opposite answers. A node provisioned during an incident carries whatever hostname somebody typed in a hurry, so adopting it silently is a mistake that surfaces only after DNS moves.

**It decides the serving config.** There is no Apache choice on the form. The restore regenerates the virtualhost for this machine from the platform's own templates and never installs the one the backup carries; a differing capture is preserved as `{site}.conf.from-backup` and named in the job output. On a container node the public name is the **host's** proxy virtualhost, which is outside the container and therefore in no backup; the restore keeps the node's own domain, so the host's proxy stays as it is. A different domain is set on the host with `manage_domain.sh`.

Every restore job ends with two gates: the site's identity must match the machine (domain, deployment shape, and a database that opens with this machine's credentials) and the site must actually be served — over **HTTPS** when the domain already resolves here, or reported as certificate-deferred when it does not. The HTTPS gate is explicit because an HTTP-only check passes comfortably while a site serves under a container's internal virtualhost with a valid certificate sitting unused on disk.

What gets reconciled, and why each item is on the list, is in [Backups](../../../docs/backups.md#what-a-restore-reconciles).

Cloud listings are fetched live via `S3Signer::list()` (capped at 500 objects per storage space, under the node's own folder in each) on every page render (one SigV4 HTTP GET per space, ~200–500ms each). The local listing comes from the most recent completed `list_backups` job; both the Backups and Database tabs auto-trigger a refresh on page load when that scan is more than 60 seconds stale, so the listing is effectively always current. Both the merge logic and the staleness window are owned by `BackupListHelper::get_for_node()`.

### Stored Backups (target-side)

The **Backup Targets** edit page shows **Who backs up here** — every live storage space on the target, its owner and folder, new backups or older backups aging out — with **Move everyone** to another switched-on target.

Below it, a **Stored Backups** panel lists the target's objects directly from the bucket and groups them by folder. It runs entirely on the management node via `TargetBackups` (which lists through `S3Signer::list`, a continuation-token-paged ListObjectsV2), so it needs no live node — the authoritative view of what is actually stored offsite. Each folder is tagged by the target's spaces (`FleetBackups::folder_map()`):

- **new backups go here** — an active space owns the folder; a node's links to its Backups tab
- **older backups, aging out** — a draining space owns it
- **unclaimed** — no space claims it (left by an earlier switch, or a deleted node, which is named). **Adopt as older backups of** gives it to a node or a customer as a draining space, so its backups are listable, restorable and pruned again

Delete acts through `S3Signer` from the management node: a single object (guarded so the key must sit under the target's own prefix), or a whole folder (type-to-confirm the slug; not offered, and refused, for the folder an owner's new backups go to — move the owner first). This is the deliberate path for erasing a retired site's offsite backups — deleting a node never touches them.

## Retiring a node

A node's Actions menu on the Overview tab has three retiring actions, all permission-10 and CSRF-guarded:

| Node state | Items |
|---|---|
| Live | Hide from Dashboard · Remove Permanently… · Permanently Delete Site… (container sites) |
| Hidden | Restore to Dashboard · Remove Permanently… · Permanently Delete Site… (when a site was ever confirmed) |

### Where a node is hosted

Every node records where its machine runs (`mgn_cloud_account`, shown and edited as **Hosted at** in Connection Settings and on Add Node; `CloudAccounts::HOSTED_AT`):

| Value | Meaning | Hosted by us |
|---|---|---|
| `main` | One of our cloud accounts (main) | yes |
| `test` | Our test cloud account | yes |
| `customer` | the customer's connected cloud account | no |
| `external` | anywhere else | no |

A site placed on a server is hosted where its server is (`CloudAccounts::hosted_at()`): the server's own node's value, else the host's account. A provisioned machine takes the flag of the account of ours it is in, and `customer` when bought on, or transferred to, the customer's account (`CloudAccounts::for_provision()`); an adopted server takes the account it was found on. A hand-added node defaults to the default account's flag. `main` and `test` are also the dashboard's tabs; `customer` and `external` nodes are listed on the Main tab. A node hosted in the test account sends no alerts unless its Alerts setting says On (see *Incidents*, A node's alerts).

### Hide from Dashboard

Soft-deletes the node record (`delete_node` → `ManagedNode::hide()`), all in one transaction: a step that fails leaves the node shown and every record as it was. The site keeps running on its host and every record is kept. Hiding ends the work on the site:

- Its unfinished jobs are cancelled: its agent is refused from then on, so nothing else would end them.
- Its active backup storage space drains: it takes no new backups and keeps what it holds.
- The hosting order's provisioning record and its hosted trial are removed, so nothing seeds, retires a password on, sends mail setup to, or powers off a site nobody tracks.
- The server stays recorded, since the site goes on running there: an adopted record of it (no order, never billed, no trial; owned by whoever hid the node) takes the order record's place, and a record that was adopted already is kept. The escrow holder's Tier 2 check, reverse DNS and an address swap find the server through it while the node is hidden and after Restore. A site in a container runs on its Docker host's server, so that record goes on the host's node (an adopted record is a whole machine), none goes on the site's, and a host already on record keeps its one record; a host with no node here keeps none, and the page says so. The other ways a node leaves the dashboard let its machine go (a discarded copy, the old server of a kept switch-over, a container its host removed) and keep no record of it.
- A domain bought for the site is kept: the buyer is its registrant, and its record carries the renewals and the hand-over. One still being bought or wired up is parked on the Domains page with the reason (Retry parks it again while its site is still hidden). An active domain needs no server and is left as it is; the domain watch sends a hidden node no notice.
- Billing is never touched. A subscription still charging for the hosting is named in the page's message, so whoever hid the site can cancel it from the buyer's user page (its Subscriptions panel).

Hidden sites, and their jobs, are left out of the dashboard (Hosts & Sites and Recent Jobs) and the Jobs page. **Show all sites (including hidden)** at the bottom of Hosts & Sites (`?show_all=1`), and **Include hidden sites** on the Jobs page, bring them back, each node with a **Hidden** badge and a link into its still-reachable detail page.

### Restore to Dashboard

`restore_node` → `ManagedNode::undelete()`: the node is listed again, its agent is accepted with no re-pairing, monitoring resumes, and the backup space its hiding drained takes its backups again when nothing else is active for it. Nothing else is reversed: cancelled jobs stay cancelled, a removed provisioning record stays removed (its server's adopted record is what the node has), a parked domain stays parked. Refused when another live site has taken the slug.

### Remove Permanently

`purge_node`, on a live or a hidden node: type the slug, then `NodeRemoval`'s guards, in order:

1. **A copy under way.** Refused while the site has a copy that is not discarded or kept (being made, dormant, or in its switch-over), and on the copy's own row while that copy is (`NodeRemoval::copy_refusal()`): removing either side would leave the other running alone, its server's provision still advancing. Discard the copy, or keep its switch-over, on the site's Copy tab first.
2. **Backups.** Refused while the node's storage spaces still hold backups, or while one cannot be listed to confirm; clear them from the target's Stored Backups panel first.
3. **A container site hosted by us.** Refused until its host verified the container gone: Permanently Delete Site stamps `mgn_site_removed_time` on the site when its teardown is verified. A node where no live site was ever seen (no status check, Joinery version or uptime result) has nothing to tear down and passes. When its host has no node on this management node (never paired, or removed for good), nothing here can remove the container, so the person types the host machine's IP address to say they deleted it (`NodeRemoval::host_attest_text()`: the host record's address, else the site's own); that is logged as `[NODE_REMOVE_ATTEST]` before the delete. While the host has a node, typing its address does not stand in: the host removes the container, or, if the host machine is gone, the host's own node is removed first.
4. **A machine hosted by us.** Refused until the provider says the instance is gone: by the instance ID on its provisioning record (removed ones included), else by the node's IPv4 and IPv6 addresses in the account's server listing. Only "not found" passes; a provider error refuses. The platform never deletes an instance itself. The provider is asked with the credential of the account the machine's provision record names (`NodeRemoval::account_token()`). Where no record names an account of ours, or that account is no longer held (removed, refused, a sign-in that expired), the person confirms instead by typing the instance ID, or the machine's IP address when no instance is recorded. The confirmation is written to the error log as `[NODE_REMOVE_ATTEST]` (user, node name and slug, account, what was typed) before the delete, since the node's own record goes with it.
5. **Not hosted by us.** No machine guard: the site keeps running where it is and is no longer managed from here.

What can be known without a provider is shown up front in the menu (a refusal opens instead of the confirmation); the provider is asked only on submit.

The removal itself is `ManagedNode::remove_permanently()`, one transaction: a live node's site records are released first, as hiding would; then `permanent_delete()` removes every record the node owns by the deletion rules: its jobs, its incidents and their events, its provisioning record with its trial and transfers, and its storage spaces with their runs and ledger rows. A copy of the node that has ended (discarded, or its switch-over kept), and its provisioning record, stay, and stop naming it. Join requests, relays, mail fleet shards, hosts, machine and instance transfers, site copies, service tenants and registered domains stop naming it too.

### Permanently Delete Site

Creates a `decommission_node` job addressed to the **host's own agent** as the `decommission_site` primitive (see [Removing a container site](#removing-a-container-site-decommission)). The site approves its own removal on its own Backups page with its own recovery key; the host then runs the bundled self-verifying `remove_account.sh`. Only on `DECOMMISSION_VERIFIED` in the result does the result processor stamp `mgn_site_removed_time` on the **victim's** node record and hide it (the job's subject is the host; the victim travels in the job params); the job result lists what hiding did. A failed, declined or unverified teardown leaves the node intact and enabled to retry. Type-to-confirm the site name; the name is derived from the node's own fields, never operator input. Relays and bare-metal machines are refused: a whole machine is deleted at its provider, then its record is removed with Remove Permanently.

On a hidden node it is offered only when this management node once saw a live site there. With no such evidence (for example an install that failed and never stood a site up) the action is replaced by a short note, since there is nothing on the host to tear down. The page never probes the host directly. A `decommission_site` that reaches a host with no vhost for the named site is refused by the host, naming the site and the path it looked for, and the job fails: a request naming a site the host does not know is never reported as a verified removal, so a stale entry or a mistyped name cannot be "verified" away. The consent rule holds here too: the site must still approve on its own admin, so a container too broken to render its own approval is not removable this way; it is recovered by rebuild-and-restore instead.

A hidden or decommissioned site's offsite backups stay readable regardless (each carries its own key sealed to the recovery key) and are never deleted by these actions; delete them deliberately from the Stored Backups panel above.

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
archive in somebody else's backup storage.

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
separate party's copies of each site, in this node's backup storage — the
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

#### The backup broker

A node holds no key to backup storage; it writes through this management
node's broker (`NodeBroker`, `BrokerEndpoint`, the shared signing and finishing
code in `ShelfBroker`). When a node's agent claims a `backup_run` or
`upload_backup` job, the agent channel opens a broker run (`svr_shelf_runs` with
`svr_mgn_managed_node_id`, `svr_kind` `backup` or `upload`) in the space the job
names, mints a token, keeps its hash and expiry on the run (the job's claim
budget plus an hour, `svr_token_hash`, `svr_token_expires_time`), records the
run on the job (`mjb_svr_shelf_run_id`) and puts the broker's address, the run
and the token in the job's credential slot. The job row at rest carries only
the placeholder.

The node's backup engine (`BackupBroker` in core) then calls
`/api/v1/broker/{begin,sign,finish,abort}` with the token in the
`X-Joinery-Broker-Token` header — a route dispatched before key
authentication, metered in its own bucket — and makes every upload request on
the link it is given:

- **begin**: the run's space, base key (`{space}manager/`), bucket and target name;
- **sign**: one write inside the base key, refused when the space is draining
  (the node was moved while the run was open), when the chain's objects are in
  another of the node's spaces, or for any read or delete; a key a finished run
  completed answers `exists` with its recorded size and sha256 — nothing is
  written twice, so a node can replace nothing it wrote. A chain manifest or an
  epoch envelope is never signed over one already in the bucket, even one
  written before runs went through the broker (it is looked for first). On a
  target that locks (`bkt_lock_days`), a put's or multipart create's link signs
  the COMPLIANCE lock headers (`ShelfBroker::lockHeaders()`) and the answer
  names them, so a node cannot write unlocked; a node older than
  `JobCommandBuilder::LOCK_MIN_CORE_VERSION` is refused such a job, naming the
  release (core `docs/backups.md` § Object lock);
- **finish**: every object written, with bytes and sha256; the ledger records
  them, cancels the rest, and writes the run's ledger file
  (`{space}ledger/{run id}.json`, outside every run's base key; locked with the
  run's objects on a target that locks). Asked again
  for a finished run — a lost reply — it answers what was recorded;
- **abort**: the run failed; what it signed is cancelled.

`FleetBackupRun` aborts every open node run whose token expired, and writes any
finished run's ledger file that did not reach backup storage
(`ShelfBroker::writeMissingLedgerFiles()`); one that cannot be written records
why (`svr_ledger_problem`), is tried again a day later, and is counted in the
pass's problems. A job handed out again replaces the broker run it opened.
Ledger files do not count when a draining space is checked for empty. Readers
of a chain take its newest manifest the broker recorded, before a newer one a
failed run left. A node whose core predates the
broker (`JobCommandBuilder::BROKER_MIN_CORE_VERSION`) is refused a backup or a
re-upload at build, naming the release it needs. The node's Backups tab shows
each run's manifest with the sha256 recorded when it was written — what a shell
restore checks the manifest against — and says when a set's newest manifest in
backup storage is not the one its run wrote; a site copy refuses such a
manifest outright.

`FleetBackupRetention` prunes from here, with the target's key, which never
leaves this machine; a deleted object's ledger row is kept, marked pruned by
retention. A credential that can delete is a
credential that can erase the fleet's backups, which is the first move of any
ransomware worth the name and the exact thing these copies exist to survive.

Pruning is driven by a bucket **listing**, which is the opposite of what a site
does for its own backups, and correct only here: this management node defined the
whole `{prefix}/{slug}/manager/` path, knows every slug under it, and is the only
party that can delete from it. It is also stricter — it keeps only sets of
objects that actually exist, so a run that failed part-way can never be counted
as a restore point. Chains are grouped by their directory, so they are kept or
deleted whole by construction. How long is the **site's** choice: each backup
run reports the site's `backup_retention_days` as `BACKUP_KEEP_DAYS`, the result
processor stores it in `mgn_backup_keep_days`, and the pass keeps that many days
— never fewer than the policy's `keep_days` (`server_manager_fleet_backup_keep_days`,
7 by default), the floor that stops an intruder on a site from shortening its
window to erase its history. A node that has not reported a window is read at the
site default of 28 days (`FleetBackupPolicy::retention_days()`). Within the window
the rule is the one a site's own retention uses (`BackupRunner::surplus()`): every
group started inside it, dated by the stamp in its name, and the newest one started
before it. What that finds surplus goes only as `BackupSafety` allows: the
newest chain a `verify_backup` job passed (`FleetBackupRetention::verified_chains()`)
and everything newer stay, the newest stays, and a point goes a day after a pass
first found it surplus, recorded on its storage space (`sps_surplus`; see *What
retention will not delete* in `docs/backups.md`). The site removes its own records
of manager runs outside its window by the window alone, so its Backups page lists
what is kept.

Every run of a chain writes its own manifest (`manifest-{seq}.json`), so no
key in backup storage is written twice and a versioned bucket keeps no
superseded versions of one.

#### Scheduling

The **Fleet Backups** task (`plugins/server_manager/tasks/FleetBackupRun.php`)
runs every cron tick, finds due nodes, prunes each one's backup storage across its
storage spaces (`FleetBackupRetention::prune()`, each point deleted from its own
space's target; a draining space kept whole until a verify of a chain in the
active space has passed), and dispatches one `backup_run` per node.

`FleetBackupPolicy` resolves each node's schedule: the declared fleet settings,
then that node's own `mgn_backup_policy` overrides. **The fleet default is
enabled.** That default is what stops a newly managed node being forgotten —
there is deliberately no detector for "nobody has decided about this node",
because a node nobody decided about is backed up anyway.

The node detail Backups tab edits the policy, as one of three positions:

- **Fleet default** stores nothing, so the node follows the fleet settings —
  including future changes to them.
- **A schedule of its own** stores the full field set (frequency, window, mode,
  retention, full interval, days between verifications), frozen against the
  fleet default: a value the operator saw and saved is a value they chose.
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
- one machine takes one backup at a time (`FleetBackupPolicy::machine_key`).
  A backup's cost lands on the machine that takes it — the database dump, the
  file read, the upload — so container sites on one host (siblings share
  `mgn_mgh_managed_host_id`) take turns, and a node on a machine of its own
  waits for nobody. Only claimed work occupies a machine: an agent claims
  within seconds of polling, so a job still pending is one no agent is
  running, and it holds nobody back. A lost claim is failed as lost when its
  agent checks in idle or its budget runs out (`ManagementJob::markLost`,
  `failStaleClaims`), which frees its machine.

A node whose agent has never checked in, or not within the two hours after
which **The agent stopped checking in** opens (`IncidentSourceAgentSilent`),
is skipped and named in the pass message (`FleetBackupPolicy::agent_unheard`):
a job sent to it would wait unclaimed and run whenever the agent came back,
not in its slot. Removing a node cancels its unfinished jobs
(`ManagedNode::withdraw_open_jobs`), since its agent is refused from then on
and nothing else would ever end them.

Due is keyed on when the last run was *started*, not on whether it succeeded.
Retrying a failing node every fifteen minutes until its next slot would hammer a
machine that is already unwell.

#### Verifying what was backed up

The same pass proves the backups it takes, at two of the three levels described
in [Verifying backups](../../../docs/backups.md#verifying-backups):

- **The backup storage check** runs on every retention listing, free: for each backup on
  the node's manager-profile backup storage the pass reads the manifest (one small GET) and
  checks every artifact it names is in the listing at the recorded size, and
  that the manifest carries its envelope (`FleetBackupRetention::check_shelf`;
  `compare_manifest` is the pure rule). What it finds is stamped on
  `mgn_backup_shelf_problem` — one line in the pass's words, empty when every
  backup is whole — and the health check turns anything else into **"A backup
  in backup storage is incomplete"** on the node's card. It catches a partial
  upload, an object deleted out from under retention, and a manifest rewritten
  after its artifacts were pruned. A manifest the pass could not *read* (a
  transport error, an HTTP status) is a fact about the pass, not backup storage: it
  is named in the pass's report and the stamp is left as it was, so a network
  blip is neither shown as an incomplete backup nor clears a real one found
  last time. The check runs on every retention listing, which is once per
  backup cycle for each node, not on every tick. Every offloaded file an index
  it read marks stored and the listing lacks goes to that node's run in the
  same pass as `objects_missing` (to an agent at 1.83.0 or newer, a page that
  fits in the job), and the run stores those files again from the
  site's copy ([Backups § Objects backup storage lost](../../../docs/backups.md)). The
  object half reads every restore point's index in each chain it holds, not
  only the newest, and names the restore points that cannot bring a file back
  by their times (`compare_chain_indexes`); earlier restore points that name a
  lost location stay reported until retention removes their chain.
- **Opened and read** is dispatched as a `verify_backup` job (level 2, of the
  newest backup in backup storage) when `FleetBackupPolicy::is_verify_due()` says
  so: the policy's `verify_every_days` is above zero (fleet default
  `server_manager_fleet_backup_verify_every_days`, 7; 0 means never, stored
  as a decision), the node has a successful backup from here, and either no
  verify has ever been attempted, or the last attempt is older than the
  interval and a newer backup exists. The last attempt is the later of the
  node's verify stamp and the creation of its newest `verify_backup` job,
  whatever became of that job (`FleetBackupPolicy::last_verify_attempt`): a
  verify that fails on the node comes back as a failed job, and a failed job
  is never folded into the node's columns, so the stamp alone would read
  "never verified" and re-dispatch the whole download every tick. A verify
  takes its machine's turn the way a backup does and is never
  dispatched beside a running backup, Prepare or verify of the same node; a
  backup due on the same tick waits for it. A failed verify is never retried
  automatically. Level 3 is never scheduled: it is a person's choice on the
  Backups tab.

The pass message names verifies dispatched and skipped the way it names backups.

#### What is reported, and what raises an alarm

`check_status` asks each node's management API for both profiles: whether each is
scheduled, when it last ran, how it went, whether it reached the bucket, and
which recovery key it sealed to. The manager profile's answer is denormalised
onto `mgn_last_backup_time` and `mgn_last_backup_outcome` so the dashboard reads
columns instead of visiting nodes.

**Only this management node's own runs raise an alarm** —
`NodeMonitorHealth::fleet_backup_health()` judges a node a problem when its last backup from
here failed, whose backups have stopped arriving within its schedule's window,
or whose last full backup was a tenth the size of the previous one or smaller
(outcome `warning`, from the node's own `BACKUP_WARNING` line: the run is kept,
the card says "Last backup is suspiciously small" with both sizes).
The alarm is "my backups of this node are broken", not "this node is
unprotected", which is not this management node's call to make.

A failed run is reported from the run history, not from the stamp alone. The
line says since when the runs have been failing and how many, when the last one
worked, and the reason the node gave — a refusal's reason is recorded on the
run's result by `process_backup_run` — and links the failed job. The summary is
`NodeMonitorHealth::backup_run_summary()` over the node's `backup_run` jobs
(`backup_runs_from_here()`), counting back to the last success and stepping over
skipped runs and jobs the sweep has not read. A run with no `BACKUP_TIME` line is
stamped at its job's completion time, so a refusal read by the sweep hours later
still says when it happened.

A successful run's `BACKUP_LEVEL` and `BACKUP_BYTES` lines are stored on its job
result as `level` and `bytes`. The Backups tab lists the recent runs from here
(**Recent runs from here**, the last seven `backup_run` jobs) with each one's
outcome, whether it was a full or an incremental, its size, and a link to its
job; a run that printed neither line shows neither.

**A backup problem is an incident** (see *Incidents*), judged exactly as the
node's Backups tab judges it (`NodeMonitorHealth::fleet_backup_health()`,
whose result carries a `kind`): the last run failed (`plane:backup_failed`,
critical, with the reason the node gave and its run); backups are not happening
(`plane:backups_stopped`, critical: no verified recovery key on the node, never
backed up, not landing in storage, or the last success older than the schedule
allows; a node in its first seven days with no recovery key yet opens none, since
only its own administrator can set one up, while its Backups tab says so from the
first pass); or a backup was taken but is not proven whole (`plane:backup_unverified`,
a warning: a suspiciously small run, an incomplete stored backup, or
verification failed, stale or never done). A node the scheduler would not back
up, or whose fleet backups somebody switched off, has none. Each clears when
the judgement does.

**The node's word is cross-checked against the bucket.** The scheduler lists each
node's backup storage with this management node's own credential on the first
pass after the node reports a successful run (`FleetBackupRun::witness_landing`,
due while the claimed run started after the last listing; a failed listing is
retried next pass), and the retention pass lists it again before every run. Each
listing stamps what it saw — when backup storage was listed and the newest object
write on it — onto `mgn_backup_shelf_checked_time` and
`mgn_backup_shelf_newest_time`. The health check compares that against the
node's claimed last run: backup storage listed after a claimed success that holds
nothing written since raises **"Backups are not landing"**. Backup storage is the
one witness a compromised or misconfigured node cannot talk into its story —
everything else in the health picture is the node reporting on itself.

A node with fleet backups switched off produces nothing either — that was
somebody's decision.

**Whether a backup is verified restorable is the next question the card
answers**, from `mgn_backup_verify_*` (stamped by `process_verify_backup` from
the job's `VERIFY_*` lines, and adopted from the node's own status report when
the node verified itself and that is newer — `adopt_reported_verify`).
`NodeMonitorHealth::verify_state()` gives four answers: a backup storage problem (above)
is a problem; a verify that failed is a problem, in the node's own words, with
the note that nothing is retried automatically; a pass older than a day past
the node's verify interval (`FleetBackupPolicy::verify_alarm_days()`, 8 days at
the weekly default) is **stale**, a problem; and never verified is information
on a healthy card ("Not yet verified restorable") for the same number of days
after the first backup from here, after which it is **"Backups never verified
restorable"**. A verify that
was skipped (not enough disk — "needs N free, has M" — or a busy machine)
records only its reason, beside the last real result.

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

A lost node is rebuilt on a new machine from its offsite backups as a **copy from
backups** on its Copy tab (*A copy run from backups*, above): the new machine reads
the newest chain at its storage provider itself, its owner opens the chain's key
with the recovery key on the new machine's own page, and the copy restores it,
takes the chain's `secret_box_key` so every sealed value opens, and switches over
under the node's own id.

By hand, with no management node:

1. Fetch the archive and its envelope (`{archive}.keys.json`) from the bucket.
2. Recover the archive key on a machine holding the recovery private key:
   `php backup_envelope.php open --sidecar {archive}.keys.json --private /path/to/recovery.key --key-out /tmp/k`
3. Install a fresh site on the new machine, then restore onto it with
   `restore_project.sh --key-file /tmp/k` (or `restore_chain.sh --key-file /tmp/k
   --adopt-secret-key` for a chain, which takes the chain's `secret_box_key` so
   sealed values open). Without that key the new machine keeps its own, so every
   sealed value from the old one is dead and is entered again
   ([Sealed Secrets](../../../docs/sealed_secrets.md#prevention--scrub-on-copy)).

On the node itself no key is needed: `restore_project.sh` finds the envelope beside
the archive and opens it with `config/backup_site_key`.

This works when the management node itself is the casualty — the envelopes sit in the
bucket alongside the archives, so bucket credentials plus the password-manager
private key are sufficient. No site's recovery depends on any other site being
alive.

The **agent signing key** (the fleet trust root) needs no separate recovery record:
it lives at `config/agent_signing_key`, inside the project tree that a root-run
whole-site backup carries (the key is root-only, so a backup taken as any other
user leaves it out and says so). The dashboard's trust-root check is satisfied by
any offsite whole-site backup of this machine that this site's proven recovery key
opens: one the site made itself, or one a management node made of it sealed to that
same key. A copy sealed to another party's key is that party's to recover from and
does not count.

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
their `/health` document, and so does the mail relay: it dials its own Postfix
over the loopback and answers 200 or 503, with its disk and memory. No machine
of ours dials port 25, so this plane never connects to a relay's port 25; a relay
too old to answer `/health` keeps a TCP check on 25 until its update.

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
| `node_id` | No | Override target node (defaults to job's node). |
| `on_host` | No | If `true`, run on the SSH host directly, not inside the Docker container. Used for `docker stats`, etc. |
| `direction` | scp | `upload` (local to remote) or `download` (remote to local) |
| `remote_path` | scp | File path on the remote host |
| `local_path` | scp/api | File path on the management node |
| `method` | api | HTTP method: `GET`, `POST`, `PUT`, `DELETE` (in practice always `GET` — the management API is read-only) |
| `endpoint` | api | Path relative to `/api/v1/management/` — e.g. `stats`, `backups/list` |
| `expect_status` | api | HTTP status code that counts as success (default 200) |
| `query` | api | Object of query-string params (e.g. `{"limit": 50}`) |
| `body` | api | Request body object (serialized as JSON; ignored for GET/DELETE) |
| `continue_on_error` | No | If `true`, don't abort the job when this step fails |
| `timeout` | No | Max seconds for this step (default: 1800 = 30 minutes; teardown steps carry 120) |
| `teardown` | No | If `true`, the step is teardown-phase: it runs on every exit path, its failure never affects the job outcome, and it must be an idempotent removal of a per-job scratch path. Always placed at the tail of the step array with `continue_on_error` set. |

## Management API (Read-Only)

Every Joinery instance exposes a namespaced read-only HTTP surface at `/api/v1/management/*`. The management node prefers this over SSH for observability operations (`check_status`, `list_backups`) because it's faster, parallelizable, and auditable.

**Endpoints** (all under `/api/v1/management/`, all `GET`, all JSON):

| Endpoint | Replaces SSH step(s) |
|----------|----------------------|
| `health` | (new — liveness probe) |
| `stats` | all steps of `check_status` |
| `version` | `Check Joinery version` |
| `databases` | `List databases` |
| `errors/recent` | `Recent errors` |
| `backups/list` | `list_backups` |

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
- `mgn_web_root` -- Path to `public_html` inside the server/container. Empty means the node hosts no site (no backup, no recovery-key report). The node's agent reports it at join and in every `check_status`; a report fills an empty one (an absolute path ending `/public_html`) and never replaces a set one — a difference is logged.
- `mgn_last_status_data` -- JSON from last status check (disk, memory, load, etc.)
- `mgn_joinery_version` -- Last known version string

### CustomerCloudAccount (`cca_customer_cloud_accounts`)

A user's OAuth-linked cloud provider account (one row per user + provider).
Holds the SecretBox-encrypted token set via `storeToken()`/`getToken()`;
`cca_status` is `active`, `refresh_failed`, or `revoked` (the latter two mean
the buyer must re-connect).

### CustomerCloudProvision (`cvp_customer_cloud_provisions`)

One cloud-instance provision, request to running site. `cvp_origin` is
`buyer` (the configure page's draft; `cvp_external_order_item_id` is stamped
at activation and required from `ready` on), `order` (keyed to the getjoinery
order item — `cvp_external_order_item_id`, unique, required for this origin)
or `admin` (no order item); `cvp_status` is the state machine documented under
[Customer-Cloud Fulfillment](#customer-cloud-fulfillment), preceded for a
buyer row by `draft` and `pending_payment`; a buyer row also carries
`cvp_domain_source` (`own` | `register`), the frozen `cvp_domain_quote` /
`cvp_domain_quote_time` and the sealed `cvp_registrant_sealed`, held only
until activation copies it to the registration row; install parameters
ride on the row (`cvp_docker_mode`, `cvp_install_mode`, `cvp_source_node_id`,
`cvp_port`, `cvp_sitename`, and for install mode `copy`
the release it installs at, `cvp_release`); links to the account
(`cvp_cca_customer_cloud_account_id`), instance (`cvp_instance_id`/`_ip`), and resulting
node (`cvp_mgn_managed_node_id`).

### SiteCopy (`scp_site_copies`)

One site copied onto a new server, from its start to its discard
([Site copy](#site-copy)): the source (`scp_source_node_id`), the copy's node
row once it exists (`scp_copy_node_id`), the provision when this management
node created the server (`scp_cvp_customer_cloud_provision_id`), the release
the copy was installed at (`scp_release`), `scp_status` (`waiting`, `copying`,
`dormant`, `halted`, `discarded`, and the switch-over's `freezing`, `ready`,
`starting`, `undoing`, `switched`, `returning`, `finished`), where the copy
comes from (`scp_from`: the running `source`, or its `backups` when its server
is dead), the current or last run's
steps (`scp_steps`: op, side, an argument such as on or off, job, verdict,
reason), the chain it applied and that chain's newest run time, the census
comparison (`scp_census`), the look path, why it stopped, when it was last
copied, and the switch-over's record (`scp_switch`: the phase, how the address
moves, the zone and each record moved with its old and new address or the
swap's plan (each machine's instance, address and reverse DNS), where the
address is now, and when the site froze, the address moved and was proven,
and moved back). A
source has at most one live copy (`SiteCopy::live_for_source()`).

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
- `rdm_mgn_managed_node_id` -- the box, resolved during fulfillment
- `rdm_registrant_sealed` -- the WHOIS contact block, SecretBox-sealed
- `rdm_dns_bootstrap_time` / `rdm_dns_mail_time` / `rdm_ptr_time` -- the
  idempotency ledger: null means outstanding, stamped means never redone
- `rdm_expiry_time`, `rdm_expiry_checked_time`, `rdm_prompt_pushed_time` --
  the countdown, its weekly refresh, and whether the buyer has been told
- `rdm_taken_time` -- stamped beside `failed` when the registrar found the
  name taken after payment; the one failure the buyer fixes themselves, by
  choosing an alternate on their sites page

### ManagementJob (`mjb_management_jobs`)

Represents a queued, running, or completed operation. Key fields:

- `mjb_mgn_managed_node_id` -- Target node (FK to mgn_managed_nodes, null for local-only jobs)
- `mjb_job_type` -- Label for display/filtering (e.g., "backup_run")
- `mjb_status` -- `pending`, `running`, `completed`, `failed`, or `cancelled`
- `mjb_commands` -- JSON with the step array the agent executes
- `mjb_output` -- Progressive text output (appended during execution)
- `mjb_result` -- Structured JSON populated by `JobResultProcessor` after completion
- `mjb_current_step` / `mjb_total_steps` -- Progress tracking
- `mjb_svr_shelf_run_id` -- The broker run a backup or re-upload job took, opened when the agent claimed it

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
- `bkt_delete_local` -- Whether to delete local backup after successful upload
- `bkt_enabled` -- Whether this target is active

### AgentHeartbeat (`ahb_agent_heartbeats`)

Single-row table tracking agent liveness. Updated every 30 seconds by the Go agent. The dashboard checks `ahb_last_heartbeat` to show online/offline status.

## Uptime Monitoring

A lightweight per-node uptime check runs on each scheduled-task tick (~15 min). It updates live state on `mgn_managed_nodes`. A site that goes down is an **incident** (`plane:site_down`, see *Incidents*): the reconciler opens it from these columns, tells the superadmins once, and clears it when the site answers again.

**Augmented `mgn_managed_nodes` fields:**

- `mgn_uptime_enabled` (bool, default true) — per-node on/off
- `mgn_uptime_check_type` (varchar, default `'http_status'`) — which check method to use (see below). `http_status` is the default because it concludes up/down for any node with a site URL and needs no setup; `api` is an opt-in that requires API keys provisioned on the node.
- `mgn_uptime_last_status` (varchar) — `'up'` / `'down'` / null (never checked)
- `mgn_uptime_consecutive_failures` (int) — streak counter for threshold logic
- `mgn_uptime_down_since` (timestamp) — when current outage started, null when up
- `mgn_uptime_down_reason` (varchar) — what the newest failing probe saw while down (the incident's evidence), null when up
- `mgn_cert_expiry_ts` (timestamp) — observed `notAfter` of the served TLS cert (see Certificate monitoring)
- `mgn_cert_problem` (jsonb) — the served certificate's current problem (`reason`, `title`, `not_after`, `detail`), or null; the certificate incident's evidence

"Last checked at" reuses the existing `mgn_last_status_check` — both check types update it.

**Per-node IP pinning.** `http_status` checks pin the request to the node's own `mgn_host` IP (`CURLOPT_RESOLVE`, SNI/Host preserved) when `mgn_host` is an IP literal **and** appears in the site hostname's public A records (`DnsResolver::getA()`) — the same directly-exposed guard `check_cert_expiry()` uses. A node behind a shared or round-robin hostname (e.g. two DNS servers sharing `dns.scrolldaddy.app` via dual A records) is therefore checked as *itself*, not whichever A record DNS happens to return — otherwise a single dead node hides behind its live partner. The guard matters: pinning a Cloudflare-fronted node to its origin IP would bypass the edge and hit the Apache default-vhost fallback cert, failing SNI/cert validation and reporting a false down — so nodes that aren't directly exposed are checked unpinned, through the public hostname, same as before pinning existed.

**Check types** (extensible via a single dispatch switch in `RunNodeUptimeChecks::run_check()`):

| Value | Behavior |
|-------|----------|
| `api` | Reuses `JobCommandBuilder::fetch_status_via_api($node)`. `reason='transport'` (DNS/connect/timeout) counts as down. **3xx responses also count as down** — the API endpoint should never redirect, so a 3xx means the request never reached the API handler (typical cause: infrastructure-level HTTP→HTTPS redirect, possibly looping if Cloudflare is in Flexible mode). Auth (401/403), body errors, and non-3xx non-200 statuses all mean the server responded → up. `reason='config'` (missing API keys) is a misconfiguration: logged to error log and skipped, never a false down. |
| `http_status` | Plain curl GET to `mgn_site_url`. Success = HTTP status in 2xx or 3xx. Forced when `mgn_skip_joinery_checks=true` regardless of stored check type. |

Add more types by adding a method to `RunNodeUptimeChecks` and a `case` to the dispatch — no schema change needed.

**Tick logic** (`plugins/server_manager/tasks/RunNodeUptimeChecks.php`):

1. Iterate non-deleted nodes where `mgn_enabled` and `mgn_uptime_enabled` are true and `mgn_site_url` is set.
2. Dispatch on `mgn_uptime_check_type` (with `mgn_skip_joinery_checks` overriding to `http_status`).
3. Apply state machine:
   - On success: clear the failure counter, `down_since` and `down_reason`, set status `'up'`.
   - On failure: increment `consecutive_failures`. Once it reaches `FAILURE_THRESHOLD` (default 2), keep the probe's message in `down_reason`; if prior status wasn't `'down'`, set status `'down'` and `down_since=now()`.
   - **Inconclusive**: the probe records why in `mgn_uptime_last_error` and returns without touching status, the failure counter or `down_since`, so it neither opens nor clears an incident. `mgn_uptime_last_conclusive` is deliberately left alone, so a node that can never conclude eventually surfaces as stale rather than as healthy.

Constants on the class: `TIMEOUT_SECONDS=10`, `FAILURE_THRESHOLD=2`. The cron tick interval (~15 min) is the natural rate limiter.

**A probe only concludes when it reached the node.** A failure inside the monitoring host's own name resolution is evidence about the monitoring host, not about the node, so it is inconclusive. Without this, one broken resolver on the management node fails every probe within a single tick, carries the whole fleet past the failure threshold together, and reports every site down while every site is serving traffic — an inverted signal, since the one machine actually at fault is the only one reporting nothing wrong.

`NodeMonitorHealth::is_name_resolution_failure($errno, $message)` makes the call, and all three check types route through it. It matches curl's `CURLE_COULDNT_RESOLVE_HOST`/`_PROXY` by number, and matches on message text for the two cases that carry no distinguishing number: a resolver that hangs rather than answering (curl reports the generic `CURLE_OPERATION_TIMEDOUT`, wording it "Resolving timed out after…"), and `fsockopen`, which reports getaddrinfo's text with errno 0. Everything else — refused connections, TLS failures, timeouts once dialling has begun — stays a genuine down result.

The recorded error is worded from the monitoring host's point of view (`monitoring host could not resolve <name> (…)`) so the dashboard points at the real fault, and the tick's summary line counts these as skipped with the reason attached.

**UI:**

- Node edit form (`node_detail` overview tab and `node_add`): a "Monitor uptime" checkbox and a "Check type" dropdown. When `mgn_skip_joinery_checks` is on, the runtime forces `http_status` regardless of the stored value (so picking `api` here is harmless for non-Joinery nodes).
- Node detail overview tab: a one-line uptime status under "Last checked" — `Up`, `Down since X`, `disabled`, or `not yet checked`.

### Certificate monitoring

Every enabled node also gets an **independent TLS certificate check** on each tick (`RunNodeUptimeChecks::check_cert_expiry()`), separate from the up/down probe. It finds when a certificate **the node renews itself** stops renewing — the failure mode where auto-renewal silently breaks and the certificate expires unnoticed.

It reads the **served certificate over the wire** (`stream_socket_client` on `ssl://mgn_host:443`, `capture_peer_cert`, SNI = the site hostname), so it sees whatever the cert manager actually serves — Caddy, certbot, anything. Validity is deliberately *not* verified, so the `notAfter` of an already-expired or near-expiry cert is still readable.

**Fronted nodes are watched the same as direct ones.** The probe is pinned to `mgn_host`, so a name proxied through Cloudflare is answered by the origin, not the edge. The edge renews its own certificate; the origin behind it holds another that expires on its own schedule, and on Cloudflare Full (not Strict) an expired origin certificate is accepted and invisible — right up until Strict is enabled and it is an outage. One test decides whose certificate it is: the served cert's CN/SANs must cover the hostname (`cert_covers_host()`). A certificate for some other name is not this node's to date, but it is reported (reason 4 below).

On a monitored node it stores `mgn_cert_expiry_ts` and, in `mgn_cert_problem`, the first of five reasons (`cert_alert_verdict()`, `www_gap()`, `uncovered_problem()`, `placeholder_problem()`), or null when there is none:

1. **Renewal overdue.** A standard ACME client replaces a certificate at two thirds of its life (`renewal_due_ts()`); when that date is more than a day past and the certificate on the wire is still the one that was due, renewal on the node is failing. No stored fingerprint is needed: the due date is computed from the served certificate's own dates, so a replacement carries a fresh `notBefore` and its own due date lies ahead. For a 90-day certificate this fires around day 61, nine days before the expiry threshold would.
2. **Expiry approaching.** Days remaining under `server_manager_cert_expiry_warn_days` (default **21**) — the safety net for a certificate whose issue date cannot be read.
3. **www uncovered.** When `www.<hostname>` resolves, the origin is probed again with `www.` as SNI; a certificate that does not cover it is its own reason, because a Strict flip would take the www address dark while the apex looks healthy. A www that does not resolve is not mentioned.
4. **Origin uncovered.** The origin answers TLS but with a certificate for other names — a shared fallback vhost on the host. Under Full the site serves; under Full (Strict) it goes dark. The problem names the presented names and the wanted one and the tool that fixes it (`issue_origin_cert.sh`). Nothing is stored for a foreign certificate: its expiry is not this node's.
5. **Origin on its placeholder.** The origin answers with a self-signed certificate (`is_self_signed()`: its issuer is its subject), the placeholder a site is given until its real certificate is issued. It names the site, so it covers the name, but nothing renews it and Full (Strict) or a visitor reaching the origin directly refuses it. The problem carries when it was first seen (`since`, kept across checks by `placeholder_since()`) and the fix: `setup_ssl.sh` on the site's own server, or the agent bundle's `setup_ssl.sh` on its host for a site in a container. Its ten-year expiry is not stored.

A stored problem is an incident, `plane:certificate` (`IncidentSourceCertificate`): a warning, critical once fewer than seven days remain. The placeholder is one only once it has been served for a day (`PLACEHOLDER_GRACE_SECONDS`), and never while `mgn_ssl_state` is `pending`: a new site answers with it until its name reaches it, and ProvisionPendingSsl says what a pending provision waits on. Its evidence is the diagnosis read off the served certificate (`expiry_problem()`): issue date, lifetime, renewal-due date and whether it is overdue, issuer, serial, and the www re-issue line when that is the gap. It clears when a later check finds nothing wrong. The node detail overview shows a "TLS cert: expires …" line (warning-styled under threshold) whenever `mgn_cert_expiry_ts` is set — which also surfaces certs the certbot-file SSL tile can't see (e.g. Caddy nodes).

This is distinct from `mgn_ssl_state` / the SSL tile, which track certbot **provisioning** status (does an LE cert exist on disk) — a different question from "is the served cert about to expire," and largely a different set of nodes. The two are orthogonal.

## Safety Constraints

1. **No safety copy before a restore** -- `restore_database` and `restore_project` replace the node's database (and, for `restore_project`, its project tree) with the archive, and nothing is saved of what they replace. The operator approves the destructive job on the node itself, and the approval states that anything written since the archive was taken is gone.

2. **Database restores replace** -- A database restore leaves the target equal to the snapshot. Every restore verifies the archive with `gunzip -t` before anything is destroyed, drops and recreates the `public` schema so target-only objects are removed too, and loads with `psql -v ON_ERROR_STOP=1` so the first load error fails the job instead of completing a partial restore. Dumps are plain `pg_dump` snapshots -- the restore step owns the replacement guarantee, so it holds for any file it is fed. Backup files restore onto the site that made them, where the database role matches.

3. **Per-node concurrency lock** -- The agent skips jobs if another job is already running on the same node, preventing conflicts.

4. **Stale job recovery** -- On agent startup, any orphaned `running` jobs are marked `failed` with a descriptive message.

5. **Step timeout** -- 30-minute default per step, overridable. On timeout, the SSH session is killed.

6. **Single-threaded agent** -- One job at a time. Queued jobs run sequentially.

7. **Remote credentials at runtime** -- Database credentials for backup/copy/restore are extracted from each node's `Globalvars_site.php` at execution time, never stored on the management node.

## API Actions

The dashboard's page JS calls these `POST /api/v1/action/server_manager/{name}`
actions with the browser-session credential (superadmin only, floor 10) and
reads the response envelope's `data`. The full set: `probe_api`, `job_status`,
`backup_actions`, `refresh_node_status`.

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
| `upload_file` | POST | `node_id`, `local_path` | `{success, job_id}` -- pushes a local-only backup to the node's target |
| `download_file` | POST | `node_id`, `cloud_path` | `{success, job_id}` -- brings a cloud-only backup back onto the node by signed link |
| `stage_chain` | POST | `node_id`, `chain_id`, `profile`, `seq` (optional) | `{success, job_id}` -- Prepare: downloads a run's set onto the node by signed links |
| `verify_backup` | POST | `node_id`, `chain_id`, `profile`, `level` (2 or 3), `seq` (optional) | `{success, job_id}` -- creates a `verify_backup` job |
| `restore_objects` | POST | `node_id`, `chain_id`, `profile`, `seq` (optional), `mode` (`missing` default, or `all`) | `{success, job_id}` -- **Bring them back**: starts the `restore_objects` survey job (`FleetObjectRestore::start`); the page jobs follow from its result |
| `list_status` | POST | `node_id`, `job_id` (optional) | `{success, status, job_status, backup_list, last_scan}` -- returns cached file listing; with a job id, that job's state |

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
| `data/managed_nodes_class.php` | ManagedNode + MultiManagedNode |
| `data/management_jobs_class.php` | ManagementJob + MultiManagementJob |
| `data/agent_heartbeats_class.php` | AgentHeartbeat + MultiAgentHeartbeat |
| `data/backup_targets_class.php` | BackupTarget + MultiBackupTarget |
| `data/registered_domains_class.php` | RegisteredDomain + MultiRegisteredDomain |
| `includes/domain_registrar/DomainRegistrarProvider.php` | The registrar seam + `DomainRegistrarException` (transient vs terminal) |
| `includes/domain_registrar/DomainRegistrarRegistry.php` | Interface-based registrar discovery, plus the shared domain-name and TLD gates |
| `includes/domain_registrar/NamecheapRegistrar.php` | Namecheap: availability, pricing, registration, WHOIS privacy, expiry, custody probe |
| `includes/requirements/ManagedSiteRequirement.php` | The one answer a customer-cloud line carries: the buyer's frozen draft id, plus the domain-year line from its frozen quote |
| `includes/ManagedSiteDraft.php` | The draft before payment: find, validate and freeze, unfreeze, delete, the Managed product and its price, the Continue-to-payment form |
| `includes/ManagedDomainIntake.php` | The one gate for what a domain and a registrant may be, and the live quote |
| `includes/provisioning/SweepSiteDrafts.php` | Expire old drafts, thaw old frozen ones |
| `includes/provisioning/ProvisionManagedDomains.php` | Register → web DNS → mail DNS → PTR → active |
| `includes/provisioning/ManagedDomainWatch.php` | Expiry refresh, the six-month prompt, custody detection, the node banner push |
| `logic/domain_check_logic.php` | `/api/v1/action/server_manager/domain_check` — live availability for the configure page and the taken-name alternate |
| `includes/JobCommandBuilder.php` | Command generation for all job types |
| `includes/JobResultProcessor.php` | Parses completed job output into structured data |
| `includes/S3Signer.php` | AWS SigV4 signer for S3-compatible storage: requests, the one presigner, and paginated listing |
| `includes/StorageProvider.php` | The one provider catalogue: labels, endpoint patterns, region rules, addressing, console links |
| `includes/BackupTargetForm.php` | The one backup target form and save path, the same one the core Backups page draws |
| `includes/services/NodeBroker.php` | A Managed node's broker runs: opened at job hand-out with a token, begin, sign, finish, abort, and the expiry pass |
| `includes/services/BrokerEndpoint.php` | `/api/v1/broker/*`: the token-authenticated HTTP shell a node's backup run calls |
| `includes/TargetTester.php` | Connection test on Save for Backup Targets |
| `includes/BackupListHelper.php` | Merges latest local list_backups job output with live cloud listing into a unified file table |
| `ajax/job_status.php` | Live job output polling |
| `ajax/backup_actions.php` | Backup browser actions (scan, delete) |
| `migrations/migrations.php` | Indexes, admin menu entries, menu consolidation |
| `views/admin/index.php` | Dashboard -- fleet overview, publish upgrade |
| `views/admin/node_detail.php` | Node detail -- tabbed page (overview/backups/database/updates/jobs) |
| `views/admin/node_add.php` | Add node -- the record a join request is approved against |
| `views/admin/targets.php` | Backup target CRUD |
| `views/admin/jobs.php` | Global job history |
| `views/admin/job_detail.php` | Single job output with live polling |
| `views/admin/domains.php` | Managed domain queue -- pending pushes, failures, the full ledger |
| `views/profile/domain.php` | The buyer's take-ownership flow (`/profile/server_manager/domain`) |
| `views/start.php` | Step 1 of a Managed site: sign in or create an account, side by side (`/server_manager/start`) |
| `views/profile/configure.php` | The buyer configures a Managed site and continues to payment (`/profile/server_manager/configure`) |
| `views/profile/index.php` | The buyer's sites: drafts, progress, the one-time password reveal, the taken-name alternate (`/profile/server_manager`) |
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
