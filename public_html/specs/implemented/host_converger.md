# Host converger: a self-hosted box takes its root-level changes without a shell

**Status:** IMPLEMENTED 2026-09-11 with `read_only_tree.md`; the timer runs on all nine nodes (0.8.386). The StackScript closing proof is deferred to the live verification queue. Built 2026-09-10: `install_host_converger.sh`
(core installer 1.0), `_plugin_installers_start.sh` 1.5 (`--when-changed`,
`--site-root=`, the stamp and `cache/host_converger.last`), `install.sh`
2.69 (changelog only; the runner it already runs installs the converger),
`utils/upgrade.php` (the non-root note), `HostConvergerNotice` + `AdminNotices`
1.3 + `VaultHealth` 1.3, `tests/integration/host_converger_gate.sh`,
`installer_contract_test` and `vault_health_test` extended, docs. Verified:
the stamp logic in the gate against a temp tree; the timer itself has not
yet run on a real box — that happens on dev when the next publish queues the
installers on its own agent, and on a StackScript install. Moves to
`implemented/` after a browser upgrade on a bare-metal box is seen to
converge without a shell. Written after the parser
jail rollout (`implemented/parser_jail.md`) showed the gap: managed nodes
took the launcher from their upgrade job, dev took it from a `sudo` command,
and a self-hosted bare-metal box would have taken it from nobody.

## What this closes

A self-hosted deployment upgrades from the admin's Upgrade link, which runs
`utils/upgrade.php` in the browser as the web user. The code lands. The two
steps that need root are skipped with a warning: installing the PHP
extensions the new code declares, and running the host installers
(`_plugin_installers_start.sh`: the agent artifact, the parser jail launcher,
every active plugin's `host_installer`, such as the mail stack). So an owner
who never opens a shell — the person the self-hosted tier is for — keeps
receiving code that assumes host state they never get. The parser jail is the
first core feature whose absence is visible (VaultHealth, the admin notice);
the mail stack has had the same shape all along.

After this spec, every box has exactly one root process that converges the
host to the tree that is deployed on it, installed at the one root moment
every box has (first install), and nothing after that day needs a shell.

## The shape today, verified 2026-09-10

| Deployment | Root moments after install | Host installers after a browser upgrade |
|---|---|---|
| Managed node (paired agent) | every `apply_update` job: the agent runs `upgrade.php` as root | run inside the job — converged |
| Docker self-hosted | every container start (`Dockerfile` CMD runs `_plugin_installers_start.sh`) | not run by the upgrade; run at the next restart |
| Bare-metal self-hosted (StackScript → `install.sh`) | none | never |
| The management node itself | the publish primitive (root, via its own agent) | queued by the publish (`publish_upgrade.php`, 2026-09-10) |

Facts the design leans on:

- `install.sh` runs `_plugin_installers_start.sh` once at the end of a site
  install (line ~4366), as root. That is the root moment.
- `_plugin_installers_start.sh` (1.4) derives its own site root, reads its
  own database credentials, is idempotent, and exits 0 when not applicable.
  Every installer it runs is idempotent by contract.
- `utils/upgrade.php` gates its root steps on `posix_geteuid() === 0` (line
  ~1201) and, when not root, prints the `apt-get` line and skips the runner
  (line ~1736). It never elevates.
- The agent binary is on every deployment and runs as root when
  `agent_enabled` is on, but an unpaired agent does nothing locally: the
  local queue was retired (`agent_local_queue_retirement.md`), by design.
- `install_agent.sh` already chooses systemd where PID 1 is systemd and a
  `/etc/cron.d` keepalive otherwise; the converger follows the same rule.
- There are no self-hosted production deployments yet
  (`project_no_production_users`), so nothing needs a one-time migration.

## The design

### One root timer, installed once

`install.sh`, in the site-install path right after the first
`_plugin_installers_start.sh` run, installs **the host converger**:

- **systemd** (PID 1 is systemd): `joinery-host-converger.service` (oneshot,
  `ExecStart=/bin/bash <site root>/maintenance_scripts/install_tools/_plugin_installers_start.sh <SITENAME> <site root>`)
  and `joinery-host-converger.timer` (`OnBootSec=2min`,
  `OnUnitActiveSec=5min`, `Persistent=true`).
- **no systemd** (a container image built by `install.sh`, a box without it):
  `/etc/cron.d/joinery-host-converger`, `*/5 * * * * root …`, the same command.

The unit and the cron entry are written by a new core host installer,
`install_tools/install_host_converger.sh`, so the converger installs and
converges itself like the agent and the jail do, and `install.sh` merely runs
the runner it already runs. One file owns the unit text.

### Run only when something changed

`_plugin_installers_start.sh` gains a `--when-changed` mode the timer uses:
it reads `public_html/VERSION` and the set of active plugins with a
`host_installer`, hashes them with the runner's own version, and compares
against `<site root>/cache/host_converger.stamp` (root-owned, 0644). Same
stamp: exit 0 having run nothing, in well under a second. Different stamp, or
no stamp, or the stamp older than 24 hours: run every installer as today, then
write the stamp. The daily floor is the backstop for an installer whose
inputs are not in the hash (a plugin's installer that reads a setting).

So after a browser upgrade the code lands at once and root converges the host
within five minutes. A plugin activated from the admin (a new
`host_installer`) converges the same way — closing the note in
`docs/plugin_developer_guide.md` that says activation cannot run one.

### What the operator sees

- The admin notice and VaultHealth already say what is missing; with the
  converger they clear themselves within minutes instead of naming a command.
- `docs/deploy_and_upgrade.md` § upgrade.php: the warning the non-root run
  prints changes from "run this apt-get line" to "the host converger applies
  this within five minutes" on a box that has one, and keeps the command on
  a box that does not (a box installed before this spec, or by hand).
- The converger writes to `<site root>/logs/host_converger.log`, rotated by
  the logrotate config `install.sh` already installs.

### When the converger itself fails

Three layers, and the last one is a person:

1. **It repairs itself while it runs.** `install_host_converger.sh` is one of
   the installers the runner runs, so a bad unit file or cron entry is
   rewritten on the next tick. Under systemd the timer is `Persistent=true`,
   so a window missed while the box was off runs at the next boot.
2. **When it stops running, that is visible, never silent.** Every run
   writes `<site root>/cache/host_converger.last` (root-owned, the time and
   the outcome). Core `AdminNotices` gains `HostConvergerNotice`: for
   superadmins, a stamp older than 24 hours, or absent on a box that has a
   timer, shows "the host converger has not run since …" with the one
   command that reinstalls it; `VaultHealth` gains the same as a check. The
   parser jail notice already reports one effect of a dead converger; this
   reports the mechanism, so every future installer is covered.
3. **On a managed node the plane is the backup, with no new primitive.**
   `install_host_converger.sh` is a core host installer, so the existing
   `run_plugin_installers` primitive reinstalls a dead timer, and so does
   any `apply_update` job; a dead converger on a managed node is one click on
   its node page. An unmanaged box has no plane and no paired agent, so no
   primitive can reach it — that is what unmanaged means, and the only remote
   backup for such a box is to pair it, which makes it a managed node (the
   paid tier, settled 2026-09-06).
4. **A box whose root scheduling is dead needs a person once.** cron dying
   also stops the platform's own scheduled tasks (`/etc/cron.d/joinery-*`),
   so that box is already visibly broken. The agent's keepalive has the same
   residual. No design lets a root process repair a machine that can no
   longer run root processes.

## Alternatives considered

Four ways exist to do root-level work on a box after install day:

| Way | Hands-off | Root acts on the web user's request | Verdict |
|---|---|---|---|
| A root schedule converging from a stamp root owns (this spec) | yes | no | chosen |
| The agent doing it locally | yes | no, but root takes instructions from state the web user writes: the local queue, retired by decision (`agent_local_queue_retirement.md`) | not reopened |
| A narrow root door the browser upgrade knocks on: a sudoers line for one fixed command, or a socket-activated root service | yes | yes | refused; it is the shape every inventory item removes |
| Keep the notice and the one command | no | no | where we are today; the owner ruled it out 2026-09-10 |

Until S10, the timer and the local agent read a tree the web user can write,
exactly as container start and the upgrade job do; the difference between the
first two is not safety but whether a mechanism closed by decision is reopened.

## What stays out, and why

- **Elevating the browser upgrade itself.** A sudoers rule for the web user,
  or a setuid runner, would make the web user root on demand: the exact
  thing every other item in the security inventory removes. The converger
  runs on root's schedule, reading a stamp, never on the web user's request.
- **The agent as the converger.** It is root and it is on every box, but an
  unpaired agent taking local work is the local queue, retired on purpose
  (`agent_local_queue_retirement.md`). A managed node already converges
  through its upgrade job; the timer on a managed node is harmless and
  redundant, and is installed there too so one shape covers every box.
- **Docker.** Container start already converges; the image has no systemd
  and the cron form would run inside the container as root. Installed for
  uniformity; it changes nothing on a container that restarts, and covers one
  that runs for months without restarting.

## Interfaces to the other items

- **S10, the read-only tree.** The converger runs a script out of a tree the
  web user can write, exactly as container start and the upgrade job do
  today; it adds no new door and closes when S10 closes. Until then the
  entry-point gate is the same one every root moment has
  (`reference_manifest_gate_is_entry_point_only`).
- **The parser jail.** Its installer runs on the timer like any other; a
  launcher fix reaches a self-hosted box five minutes after the browser
  upgrade that carried it.
- **Declared PHP extensions.** `_plugin_installers_start.sh` already
  installs them when root; the timer makes that true for self-hosted too.
- **The management node.** The publish queues the runner on its own agent;
  the timer would also catch it. Both are fine; the queued job answers
  sooner and shows in the job log.

## Decisions this spec takes

- One root process per box, installed at first install, no shell after.
- Converge on change plus a daily floor; never on the web user's request.
- systemd where present, cron otherwise, chosen by the same rule the agent
  installer uses.

## Decisions settled with the owner, 2026-09-10

- **The interval is five minutes.** Not a real decision: a one-minute tick
  buys nothing a five-minute one does not, and five keeps the timer clear
  of an upgrade still swapping directories.
- **A box installed before this spec** (today: none) gets the converger from
  the one `sudo` command the notice names, its last shell.
- **The last resort for an unmanaged box is to be managed for the fix.** A
  self-hosted owner whose converger is dead and who will not open a shell
  pairs the box to a management node, the plane runs
  `run_plugin_installers` (which reinstalls the converger), and the box may
  unpair afterwards. That is a support arrangement using flows that exist
  (join request, approval on the plane's dashboard); nothing new is built
  for it here.

## Open questions

None.

## Work packages

1. **WP1 — the installer.** `install_tools/install_host_converger.sh` (core
   host installer, idempotent, writes the unit + timer or the cron entry,
   enables it, exit 0 when not applicable); `_plugin_installers_start.sh` 1.5
   lists it after the jail's and gains `--when-changed` and the stamp.
2. **WP2 — install.sh.** Nothing beyond what runs already: the runner at the
   end of a site install now installs the converger. The StackScript path
   is covered by that. Version bump and changelog line.
3. **WP3 — upgrade.php.** The non-root warning names the converger where
   `/etc/systemd/system/joinery-host-converger.timer` or
   `/etc/cron.d/joinery-host-converger` exists.
4. **WP4 — the failure is visible.** `HostConvergerNotice` (core
   `AdminNotices`) and a `VaultHealth` check read `cache/host_converger.last`;
   both name the one reinstall command when the stamp is stale or absent on
   a box with a timer.
5. **WP5 — tests.** `installer_contract_test` covers the new installer's
   contract; a shell gate renders the unit into a temp root and checks the
   stamp logic (`--when-changed` runs nothing on a matching stamp, runs on a
   VERSION change, runs on a stale stamp); the notice's wording from a fixed
   stamp; the deploy tier after the `install.sh` edit.
6. **WP6 — the record.** `docs/deploy_and_upgrade.md` (upgrade.php section,
   distribution table), `docs/plugin_developer_guide.md` (activation and
   host installers), `plugins/server_manager/docs/overview.md` (root
   moments). This spec moves to `implemented/` when a StackScript install
   proves a browser upgrade converges without a shell.
