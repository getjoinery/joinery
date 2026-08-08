# Restore Knows What Shape It Is Landing In

**Status:** Built and live-verified 2026-08-08 on upgtest2 (Ubuntu 26.04, PHP 8.5,
PostgreSQL 18). Every §6 gate passed: archive restore and chain restore both end
with the site serving **HTTPS 200** under a CA-issued certificate, identity
matching the machine, database opening on the machine's own credentials, and no
new errors in the site log. Live testing found five defects, all fixed — see
"What live testing found" at the end.

Not exercised on real hardware: the bare-metal → container direction (no
container host was rebuilt), and the Server Manager job builders, which were
verified by test only.

## Problem

A backup is supposed to be able to rebuild a site anywhere. Today it can only
rebuild the site on a machine set up exactly like the one it came from. Put a
backup taken inside a container onto a plain server and the site comes back
subtly wrong: it serves plain HTTP under an internal hostname, it thinks it is
still running in a container, and it believes it lives at the old address. None
of that is reported. The restore says it succeeded.

That was found for real. Rebuilding `joinerydemo` — a container site — onto a
fresh bare-metal Ubuntu 26.04 box, every step reported success, and the finished
site had:

- **No HTTPS.** The restore overwrote the virtualhost the installer had just
  written, replacing it with the container's internal one: `ServerName
  joinerydemo.site`, port 80 only, no certificate block. A Let's Encrypt
  certificate had been issued minutes earlier and was still on disk, unused.
  Confirmed by timestamp — the virtualhost was rewritten three seconds before
  the restore finished.
- **The wrong identity.** The restored `config/Globalvars_site.php` said
  `deployment_environment = 'docker'` and `webDir = 'demo.getjoinery.com'`, on a
  bare-metal box serving a different domain.
- **A database it could not open.** The restored config carried the source
  machine's database password, which no freshly installed PostgreSQL role has.
  Every page logged `SQLSTATE[08006]` until an operator changed the role
  password by hand.

Each of those needed a human with a shell. That is the bug. A restore has to
run mechanically — from the Server Manager dashboard, from a scheduled job, from
a script — with no step that only works because someone knew what to fix.

## Goals

1. A restore detects the shape it is landing in and reconciles the backup to it.
   Container → bare metal and bare metal → container both work with no operator
   decision and no post-restore repair.
2. Every restore path is reachable from Server Manager, including chains.
3. Nothing that must be true after a restore is left to a human to notice.

## What already exists

Most of the parts are already here, unused or used by only one path. This work
is mostly connecting them.

- **The shape is already recorded.** `_site_init.sh` writes
  `deployment_environment` into every site's `Globalvars_site.php`
  (`docker` or `bare-metal`), and `backup_project.sh` already reads it to decide
  whether to capture a virtualhost. See `specs/implemented/deployment_environment_flag.md`.
- **The clone path already gets most of this right.** `build_install_node`'s
  `from_backup` mode extracts only `project_files/`, explicitly excluding
  `config/Globalvars_site.php` (the target keeps its own) and
  `config/backup_site_key` (per-machine, must not be inherited), never touches
  `apache_config/`, then rewrites `webDir` in both the database and the config
  and fixes the Apache `ServerName`.
- **The backup-restore path does none of it.** `restore_project.sh` restores
  `config/Globalvars_site.php` and copies `apache_config/*.conf` straight into
  `/etc/apache2/sites-available/`, then enables and reloads.
- **The two disagree about the virtualhost.** `backup_project.sh` skips the
  virtualhost for container sites on purpose. `BackupRunner::build_meta()` — the
  chain path, which is what the fleet actually runs — copies
  `/etc/apache2/sites-available/{project}.conf` unconditionally, so a container's
  internal virtualhost travels in every chain backup.
- **The chain path records less than the archive path.** `backup_project.sh`
  writes `Environment: Docker container | Bare metal` into `backup_info.txt`;
  `BackupRunner::build_meta()` writes only project, slug, run and time.

## Design

### 1. The backup states its shape, in one machine-readable place

`BackupRunner::build_meta()` writes `shape.json` into the meta artifact
alongside `backup_info.txt` (which stays human-readable):

```json
{
  "version": 1,
  "deployment_environment": "docker",
  "project": "joinerydemo",
  "web_root": "/var/www/html/joinerydemo/public_html",
  "domain": "demo.getjoinery.com",
  "container_port": 8080,
  "php_version": "8.3",
  "postgres_version": 16,
  "vhost_captured": true,
  "vhost_role": "internal"
}
```

`deployment_environment` is read from the site's own config, not guessed at
runtime — same source `PluginProvisioning` and `backup_project.sh` already use.
`vhost_role` distinguishes a container's internal virtualhost from a bare-metal
site's public one, because only the second is ever safe to install as-is.

A backup with no `shape.json` (anything taken before this lands) is treated as
shape-unknown, and the restore falls back to the rules in §3 with the virtualhost
suppressed. Restoring an old backup must not become an error.

### 2. The restore classifies the target, then decides

The restore reads two things: the backup's `shape.json`, and the target it is
running on (its own `deployment_environment`, whether it is inside a container,
its web root, its domain). Those two produce one of four cases, and the case
drives everything else.

| | → bare metal | → container |
|---|---|---|
| **from bare metal** | same-shape | shape change |
| **from container** | shape change | same-shape |

**Same-shape** keeps today's behaviour, minus the defects in §3.

**Shape change** additionally: never installs the source virtualhost, regenerates
the target's own web serving config, and rewrites the identity settings.

### 3. Per-artifact rules

These apply to `restore_project.sh` and `restore_chain.sh` alike — both call one
shared reconciliation step so they cannot drift.

**Database** — always restored. Unchanged.

**Site files** — always restored, with two exclusions taken from the clone path,
which already has them right:

- `config/Globalvars_site.php` is never overwritten. The target's own config
  already holds credentials that match the target's PostgreSQL, its own web
  root and its own shape. Values that must carry over from the backup are
  copied field by field (§4), not by replacing the file. **This alone fixes the
  database-password failure**, which is not a shape problem at all — it bites a
  same-shape rebuild just as hard.
- `config/backup_site_key` is never restored. It identifies one machine as a
  recipient of its own backups; inheriting it makes two machines share one
  identity. `backup_envelope.php` mints a fresh one on first use.

**Virtualhost** — the captured one is **never installed**, in any case, same
shape or not. The restore always regenerates the target's serving config from
the platform's own templates:

- Landing on bare metal: `virtualhost_update_script.sh` with the target's site
  name and domain, then `setup_ssl.sh` for the domain. Both already exist and
  both already produce a virtualhost whose `:443` block is guarded by
  `<IfFile>`, so a site with no certificate yet still serves HTTP rather than
  refusing to start.
- Landing in a container: the container's internal virtualhost is written by
  `_site_init.sh` at install time and is correct already — leave it. The public
  face is the **host's** proxy virtualhost, which no backup contains because it
  lives outside the container. Restore calls `manage_domain.sh set {site}
  {domain}` on the host, which writes it.

The asymmetry is worth stating plainly: a container backup is missing the piece
that terminates TLS, and a bare-metal backup carries a piece a container must
never use. Neither direction can be handled by copying files.

Two reasons the captured virtualhost is not installed even when the shape
matches. It is the one file the installer has just written correctly for this
box, this domain and this shape. And the template keeps improving — the
`<IfFile>` guard on `:443`, the `static_files` alias, the `www` alias all
arrived after sites were already running — so installing an old capture quietly
reverts them, and the older the backup the worse the restore.

**Nothing is discarded, though.** When the captured virtualhost differs from the
regenerated one, the restore writes it beside the live file as
`{site}.conf.from-backup` and names it in the job output: *"the backup's
virtualhost differed from the generated one; kept at
/etc/apache2/sites-available/{site}.conf.from-backup for review."* A hand-added
redirect, alias or `ServerAlias` survives on disk and is pointed at, rather than
being applied unattended — applying an unknown config unattended is exactly how
the drill lost HTTPS. Today no site in the fleet has such a customisation (all
seven virtualhosts on the shared Docker host are the template verbatim), so this
is a guard for the future rather than a migration concern.

This makes `vhost_role` in `shape.json` informational only, and removes the
whole same-shape/shape-change branch from the virtualhost logic.

**Scheduled tasks** — `_site_init.sh` writes a cron file only on bare metal; in
a container the start command owns it. A restore that changes shape must apply
the target's convention, not the source's.

**Permissions** — `fix_permissions.sh {site} --production` already runs at the
end of a project restore and stays.

### 4. Identity reconciliation

After files land, the restore rewrites the settings that name *where the site
is*, in both places they live — the config file and `stg_settings` — because a
site that disagrees with itself fails in ways that look like anything but a bad
restore:

| Setting | Set to |
|---|---|
| `webDir` | the domain the job was given (§4a) |
| `deployment_environment` | the target's shape |
| `baseDir` / `siteDir` | the target's paths |
| database credentials | left as the target's — never taken from the backup |

`build_install_node` already updates `webDir` in both places for clones. That
code moves into the shared reconciliation step and gains the rest of the table,
so one implementation serves clone, restore-archive and restore-chain.

### 4a. The domain is given, never inferred

Every restore job takes a **required** `domain` parameter. The dashboard form
pre-fills it from the target node's `mgn_site_url`; the API requires it. The job
output states the domain it used.

Inference was considered and rejected. The correct domain depends on intent that
is not present in the data: a real rebuild keeps the site's own domain and cuts
DNS at the end, while a rehearsal must **not** claim it — the same backup and the
same target want opposite answers. The tempting rule, "the target node's recorded
URL wins when set," fails in exactly the case that matters most: a fresh node
provisioned during an incident carries whatever hostname the operator typed in a
hurry, so the restore would quietly adopt a throwaway name and the mistake would
surface only after DNS moved. Requiring the value costs one field and records the
decision at the moment somebody actually knows it.

### 4b. Certificates are never waited for

The restore does not block on a certificate and does not schedule a follow-up
job. It sets the node to `mgn_ssl_state = 'pending'` for the chosen domain and
arms the on-box retry timer for that domain, then finishes.

Both mechanisms already exist and both already gate on DNS:

- `joinery-ssl-retry@{domain}.timer`, installed by `install.sh`, runs every five
  minutes and does nothing until the domain resolves to that server, then issues
  once and disables itself. It treats a self-signed placeholder as *not* done.
- `ProvisionPendingSsl` walks nodes in `ssl_state = 'pending'` and checks the A
  record before spending an attempt, so its 16-hour give-up clock does not start
  while a cutover is still pending.

Together they cover the normal rebuild shape — restore now, cut DNS later,
certificate arrives on its own — with no operator step. The `<IfFile>` guard on
the `:443` block means the site serves HTTP until then rather than Apache
refusing to start. The gating also matters for a reason beyond convenience: an
ungated five-minute retry against a domain that does not resolve here would
exhaust Let's Encrypt's failed-validation rate limit long before the cutover.

The gap this work closes is narrow. `install.sh` arms the timer for the domain
it installed; a restore that sets a different domain (§4a) leaves the timer
watching the old name and nothing watching the new one. The reconciliation step
re-arms it for the domain it was given.

### 5. Server Manager

- **Add a chain restore job.** `restore_chain.sh` is currently reachable only
  from a shell on the box, which means the fleet's *actual* backups — the
  manager profile writes chains, not standalone archives — cannot be restored
  from the dashboard at all. The job downloads the chain from the target's
  shelf, recovers the data key, verifies every artifact against the manifest
  before writing anything, restores, and reconciles.
- **Remove `skip_apache` as an operator choice.** It is a parameter of
  `build_restore_project` today, which makes the correct behaviour something an
  operator has to know to ask for. The shape comparison decides it.
- **Report the reconciliation.** The job's output states the shape it detected,
  the shape it landed in, and every setting it rewrote. A silent fixup is as
  hard to trust as a silent breakage.
- **Verify like the clone path.** The file-level verification in
  `build_install_node` (every archived file must exist at the site root, no
  stray `project_files/`) applies to restores too.

### 6. Gates

A restore job is not complete until, on the restored node:

- `php tests/run.php deploy` passes.
- The site answers over **HTTPS** at the target's domain, with a certificate
  matching it — not merely over HTTP.
- `deployment_environment` and `webDir` match the target.
- The database opens with the target's credentials.
- No `SQLSTATE` or fatal entries in the site's error log after the restore's
  finish time.

The HTTPS check is called out because the drill's failure passed an HTTP-only
check comfortably.

## Out of scope

- Changing the backup format. `shape.json` is an addition inside an existing
  artifact; old chains stay restorable.
- PostgreSQL major-version migration. Dump-and-restore already crosses it — this
  work's own drill moved a PG 16 dump onto PG 18.4 with no special handling.
- The relay and the DNS resolver nodes. They host no Joinery site and have no
  shape to reconcile.

## Documentation (update at build time, current-state only)

- `docs/backups.md` — what a backup carries beyond files and database, and what a
  restore reconciles.
- `docs/deletion_system.md` is unaffected; `docs/deploy_and_upgrade.md` gains the
  rebuild-onto-new-hardware path.
- `plugins/server_manager/docs/overview.md` — the chain restore job and the
  removal of the Apache choice.

## Open decisions

1. ~~**Domain on restore.**~~ **Resolved:** required parameter, pre-filled from
   the target node's URL, never inferred. See §4a.
2. ~~**Certificate timing.**~~ **Resolved:** never blocked, never a follow-up
   job; the restore arms the two DNS-gated retry mechanisms that already exist.
   See §4b.
3. ~~**Same-shape virtualhost.**~~ **Resolved:** never installed, always
   regenerated; a differing capture is preserved as `{site}.conf.from-backup`
   and named in the job output. See §3.

All open decisions are resolved.

## What live testing found

Rebuilding `joinerydemo` on upgtest2 again, this time with the work in place.
Five defects surfaced that the test suite could not have found, because four of
them only exist on a real Ubuntu 26.04 box and the fifth only on a machine that
had already been damaged.

1. **`/tmp` is a RAM-sized tmpfs on 26.04.** `backup_project.sh` staged the whole
   site there and died with `No space left on device` on a 608 MB site with a
   479 MB `/tmp` — after the database dump had already succeeded.
   `restore_project.sh` had the same fault on the way back, where it surfaced as
   *"Failed to extract archive. File may be corrupted."* — a lie about a perfectly
   good archive. Both now stage beside their output, which is real disk by
   definition. This blocks backup and restore for **any** site larger than half
   the box's RAM on 26.04, so it is a fleet-wide 26.04 finding, not a test artifact.

2. **A config can lie about its own machine.** upgtest2's config still said
   `deployment_environment = docker` from the first drill, on a plain server.
   Reconciling to the config's claim would have preserved the bug forever. The
   machine now wins when the two disagree, and says so — a config can be wrong
   about its machine; a machine cannot be wrong about itself.

3. **`--disarm` was refused for the domains that most needed it.** The
   routable-name guard ran before the disarm branch, so a retry timer armed for
   an *IP address* by an earlier installer could never be cleaned up. upgtest2
   had exactly one: polling a rate-limited CA every five minutes, pointing at a
   site that had been deleted. Cleanup is now always permitted.

4. **The database also held the container's internal hostname.** `stg_settings.webDir`
   was `https://joinerydemo.site` — a third piece of drill damage nobody had
   spotted, invisible because the config file was checked and the settings table
   was not. Reconciling both places is what caught it.

5. **A cosmetic lie in the loudest place.** `archive_is_encrypted` read the magic
   bytes through `$( )`, so every plaintext restore printed a null-byte warning —
   noise in the one operation whose output has to be readable when something has
   gone wrong.

Proven on the box: shape recorded by both engines (PHP 8.5, PG 18, `vhost_role:
internal`); container → bare-metal reconciliation; a 454 MB full plus a **48 kB**
incremental restored in order with the deletion replayed; the machine's own
config and `backup_site_key` surviving incremental extraction byte-for-byte; the
container's internal virtualhost preserved as `.conf.from-backup` and **not**
installed; a wrong database password refused rather than reported as success; a
missing domain refused; the certificate retry arming, firing, finding a real
certificate and disabling itself.
