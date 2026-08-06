# Fleet Scheduled Backups: Two Profiles, Two Keys

**Status:** All five phases BUILT 2026-08-06, uncommitted. safe + db tiers green
(235 tests, 7556 checks). Full code review 2026-08-06: five findings, all fixed
same day — a status check no longer stamps an in-flight run as failed, fleet
retention orders restore points by their timestamp rather than their name (a
name sort ordered mixed shelves by family after a mode switch), the per-node
policy is editable on the node Backups tab (fleet default / custom / off), a
manual run takes mode and full-interval from the node's policy, and the
scheduler pass reports error only when it could do nothing at all. Live gates
outstanding — see *As built* below.
**Date:** 2026-08-05

## As built

All six decisions implemented as written. New: `includes/BackupProfile.php`,
`plugins/server_manager/includes/FleetBackupPolicy.php`,
`FleetBackupRetention.php`, `tasks/FleetBackupRun.php`, the `backup_run` job
type, `bkh_profile` / `bkh_recovery_fpr`, and `mgn_backup_policy` /
`mgn_last_backup_time` / `mgn_last_backup_outcome`. `push_recovery_key` is gone
in every form — job type, builder, install step, automatic queueing and both
buttons — while `set_recovery_key.php --report` still feeds the fleet table.

**Deviations, deliberate.**

1. **Manager retention lists the bucket instead of replaying history.** The spec
   said control-plane retention would be "driven by recorded history", but that
   history lives on the node, and shipping it to the control plane to decide a
   delete adds a whole synchronisation path for no gain. Listing is safe here for
   the reason it is unsafe for a site: this control plane defined the entire
   `{slug}/manager/` path and is the only party that can delete from it. It is
   also stricter — it counts only objects that actually exist, so a run that
   failed part-way can never be mistaken for a restore point. Chains group by
   directory, so chain-atomicity falls out of the grouping rather than being a
   rule to remember.
2. **Retention runs immediately before each node's next run**, not after the
   previous one completes. Same cadence, one fewer moving part, and it never
   prunes on the strength of a run whose outcome it has not seen.
3. **`backup_project` / `backup_database` job types remain** for the ad-hoc
   single self-contained archive. The Backups tab dispatches `backup_run` as
   specified; the older builders are still exercised by tests and are the shape
   From-Backup installs need.

**Found while building, fixed:** the node Backups tab's field was named
`backup_type`, which collides with a declared core setting — FormWriter refuses
to let a page draw its own field for a declared setting, and the guard fired.
Renamed to `backup_scope`, which is what it is: a per-run job parameter, not a
setting anyone stores.

**Outstanding — all need a real node or a real bucket:**

- No manager-profile run has executed end to end. Everything up to job creation
  is asserted; the node-side execution has never happened.
- Write-only credential enforcement is unproven against a live provider. Nothing
  in the fleet's B2 keys has been narrowed yet — that is a bucket-side change,
  not a code change, and until it is made a node still holds a delete-capable
  credential during a run.
- The B2 lifecycle rule keeping only the current manifest version has not been
  created.
- `FleetBackupRun` is not activated on Scheduled Tasks.
- Both profiles have never run on one machine, so the machine-wide mutex and the
  chain isolation are asserted structurally but not observed.
- The dev box's own site-profile backups fail on `config/relay_pull_key`
  permissions, which predates this work and is unrelated to it.
- The phase-4 **bucket cross-check** is BUILT 2026-08-06, riding the retention
  pass's existing listing rather than `FleetBackups::list_grouped()`: the
  scheduler stamps when the shelf was listed and its newest object write onto
  the node (`mgn_backup_shelf_checked_time` / `mgn_backup_shelf_newest_time`),
  and the health check raises "Backups are not landing" for a claimed success
  with nothing written since. `update_database` run 2026-08-06 — both columns
  exist; its live observation rides the same gates as the scheduler itself.

## The problem in one paragraph

A site backs itself up today, on its own schedule, with its own key, and needs
nothing else alive to do it. Server Manager can also back a node up, but only
when someone presses a button — and when it does, it writes into the same
working directory, uploads to the same bucket path, and seals to whichever
recovery key happens to be in the node's settings slot. So the two are not two
backup systems; they are one backup system with two front doors, and they can
step on each other. This spec makes them genuinely separate: **two profiles**,
isolated in space, in time, and in key custody, both executed by the node.

## The model

A **backup profile** is the unit of isolation. A node has two:

| | `site` | `manager` |
|---|---|---|
| Who configures it | The site's own admin, on `/admin/admin_backups` | The fleet owner, in Server Manager |
| Who triggers it | The node's `Backup` scheduled task | The control plane's `FleetBackupRun` task |
| Who executes it | `BackupRunner` on the node | `BackupRunner` on the node |
| Recovery key | The site's own `backup_recovery_public_key` | The control plane's key, supplied per run |
| Bucket credentials | Stored on the node, in its own `bkt_backup_targets` | Write-only, injected in memory at step time; never at rest on the node |
| Who prunes the shelf | The node | The control plane |
| Depends on | Nothing | The control plane being alive at the scheduled moment |

Both run the same engine. Everything that makes a backup good — chains,
envelopes, deletion replay, history — stays node-side and is written once. The
control plane contributes a schedule, a target, a key, a place to watch it from,
and (for its own profile only) the pruning.

The asymmetry in the last row is deliberate and is the whole safety argument:
**the site profile depends on nothing.** A control plane that is down, retired,
or hostile costs a site nothing it was relying on. The manager profile is the
fleet owner's own second copy, held under the fleet owner's own key.

**Neither profile owns the node's backups.** They are peers. Each party runs the
copies it initiated, under its own key, on its own schedule, and neither needs
the other's permission or absence to make sense. A site admin who wants their own
copies as well as the fleet owner's just sets their profile up; a fleet owner who
wants copies of a site that already backs itself up just leaves the manager
profile on. Two backups a night of the same box is a legitimate configuration,
not a misconfiguration to be detected — which is what the machine-wide mutex and
the spread window exist to absorb.

## Why two keys, concretely

A recovery key is a custody statement, not a config value. The private half of
the site key is in the site admin's password manager; the private half of the
manager key is in the fleet owner's. Sealing both profiles to one key would mean
one of those two people can open backups they are not the custodian of — in one
direction that is a hosting provider reading a customer's archives, in the other
it is a customer holding the key to the fleet owner's copy.

So:

- The site profile seals to `backup_recovery_public_key`, which **only the node's
  own operator ever sets**, through the existing possession ceremony. The control
  plane does not write it.
- The manager profile seals to the control plane's proven key, shipped in the
  step command as a **public** key (this is already how `step_mint_envelope`
  works — `--recovery-pub`). It is never written into the node's settings, so
  the node cannot accidentally start using it for its own runs.
- Both additionally seal to `config/backup_site_key`, unchanged, so the node can
  restore itself unattended. State this plainly in the docs: a manager-profile
  archive opens with the node's own site key. Manager key custody protects the
  archive against everyone except the machine it came from.

  This is not a cross-site exposure — the site key is per node, and a node holds
  a write-only bucket credential, so it can neither open another site's archives
  nor fetch them to try. Within one site it means whoever administers the node
  can read that node's manager backups. Mostly that grants nothing: they can read
  the live tree those archives were made from. The part that is genuinely extra
  is **history** — an archive from six weeks ago carries secrets since rotated
  and data since deleted. Accepted deliberately, because dropping the site
  recipient defends only against someone who already holds the node's disk, and
  costs unattended restore for every manager-profile backup.

Every history row records which recovery key its run sealed to
(`bkh_recovery_fpr`), so "which private key opens this backup" has a durable
answer per run rather than being inferred from whatever the settings say today.

### `push_recovery_key` is retired

The job type exists to answer one question: how do control-plane backups get
encrypted without setting a key on every node? The manager profile answers it
properly — the key travels in the step command, per run, as a public key. Zero
keys on the node, nothing to push, nothing to keep in sync, and no way for a
node to end up encrypting to a key nobody told it about.

What the push actually did was write into the *site's* key slot, which is not
the same problem and is now the custody confusion described above. So it goes:

- The `push_recovery_key` job type is removed, along with the automatic queueing
  in `JobResultProcessor` and the manual buttons on the node Backups tab and the
  Backup Targets fleet table.
- `set_recovery_key.php --report` **stays** — reporting which key a node holds is
  still how the fleet knows whether a site profile can run at all.
- The rare case of "this node should use the fleet key as its own" needs no
  machinery: the key is public, and the node's Backups page already accepts a
  pasted public key through the normal setup panel.

**A managed node does not need a site profile at all.** Manager profile only is a
first-class configuration and the expected one for a node the fleet owner runs:
no recovery key on the box, no bucket credentials on the box, nothing to set up
per node. The site profile is for sites somebody else administers, and for
standalone installs that have no control plane.

Existing fleet nodes are holding the control plane's key in the site slot. There
are no production users, so this is a one-time reconciliation, not a migration:
clear it on nodes that will run manager-profile-only (the common case), leave it
on any node whose own scheduled backups you want to keep. Archives already sealed
to it remain openable either way; the key does not move.

## The node may write to the shelf, but never erase it

One fleet target serves every node. What keeps that safe is not the number of
credentials but what a credential can do: the one a node is handed is
**write-only** — `writeFiles` on B2, `s3:PutObject` without `s3:DeleteObject` on
S3. A node can add its own archives and nothing else.

This matters because retention needs delete permission, and a credential that
can delete is a credential that can erase the fleet's backups. That is the first
move of any ransomware worth the name, and it would defeat the entire reason the
manager copy exists: it is supposed to be the copy that survives whatever happens
to the node.

So **manager-profile retention runs on the control plane**, with a delete-capable
credential that never leaves it, driven by the same recorded run history the node
reports in phase 4. This is the one place the manager profile deliberately does
*not* keep work on the node — pruning a bucket is not node work, and the shelf
being pruned is the fleet owner's.

Site-profile retention is unchanged and stays node-side. A site's own shelf is
its own business, and its credential is its own to scope.

Three consequences to carry into the build:

- **A chain's manifest is rewritten every run**, which is a PUT over an existing
  key — allowed under write-only, since `PutObject` overwrites on S3 and B2
  writes a new version. On B2 those superseded manifest versions accumulate,
  because the node cannot delete them. The fleet bucket wants a lifecycle rule
  keeping only the current version.

- **Provider caveat.** Linode Object Storage keys are read-only or read-write per
  bucket with no separate delete capability, so write-without-delete is not
  expressible there. B2 and S3 both express it cleanly. A Linode manager target
  is therefore a weaker configuration and should say so where it is chosen.
- **Per-node scoping is the next rung, not this one.** Limiting each node's
  credential to its own `{slug}/manager/` prefix (B2 `namePrefix`, an S3 IAM
  policy) narrows reads as well as writes, at the cost of provider-specific key
  minting, key ids stored per node, and revocation on decommission. Not required
  while every node in the fleet is administered by the fleet owner. Required
  before one isn't. Object lock or bucket versioning is the belt to this brace
  and is worth its own spec.

## Isolation in space

**In the bucket.** The profile becomes a path segment for both:

```
{path_prefix}/{slug}/site/chain-20260805_020000/...
{path_prefix}/{slug}/manager/chain-20260805_031500/...
```

Retention drives off recorded history rather than a bucket listing, so objects
written under the old flat layout keep being aged out correctly by the same
history rows that created them. Nothing needs moving.

**On disk.** Each profile gets its own working directory — `{backup_output_dir}`
for `site`, `{backup_output_dir}/manager` for `manager` — and therefore its own:

- `.jy_backup.lock` (correctness lock: snapshot + manifest)
- `.{slug}.snar` tar snapshot — **sharing one would corrupt both chains**, since
  each run advances it and each would then see the other's work as already
  archived
- chain manifest
- envelope scratch (`.jy_envelope.key`, `.jy_envelope.keys.json`)
- local retention sweep, which only ever sweeps its own directory

The manager directory sits inside the site's backup working directory, which is
already excluded from archives, so the profiles do not archive each other.

### Defects in the current tree this fixes

These are live today, not hypothetical, and are the reason the isolation is
phase 1 rather than a nicety:

1. `JobCommandBuilder::ENVELOPE_KEY_PATH` / `ENVELOPE_SIDECAR_PATH` are the fixed
   paths `/backups/.jy_envelope.*`. Two backup jobs on one node — or one job
   alongside a scheduled run — mint over each other's envelope, and the loser
   ends up with an archive whose envelope names a different archive.
2. The upload step resolves its file with `ls -t /backups/... | head -1`. If the
   node's own scheduled run writes an archive in the window between the job's
   backup step and its upload step, the job uploads the wrong file — under the
   manager's key expectations, sealed to the site's envelope.
3. The site profile's local retention sweeps `{output_dir}` by age, so it deletes
   control-plane-initiated archives sitting there, and vice versa.
4. Both write to `{prefix}/{slug}/` in the bucket, so a listing cannot tell whose
   backup is whose, and neither profile's retention can reason about the shelf it
   is responsible for.
5. **A From-Backup install clones the source's site key.** The extract step
   (`JobCommandBuilder.php:2239`) excludes exactly one file,
   `config/Globalvars_site.php`, so `config/backup_site_key` comes across with
   the rest of `config/`. Two sites then share what the whole key model calls a
   per-site disposable identity, and the envelope's site recipient stops
   identifying which machine made a backup. Nothing cross-site follows today —
   the clone has no read credential to fetch the source's objects with — but it
   is the one path by which a site key could ever become cross-site. Fix: exclude
   it on extract and let `backup_envelope.php site-key` mint a fresh one on first
   use, which it already does for an absent key.

## Isolation in time

Two tars of the same tree at once is not a correctness problem after the locks
above — it is an I/O problem, and on a shared host it is somebody else's I/O
problem too.

- **One backup at a time per machine.** A machine-wide advisory lock above the
  per-profile locks. A run that finds the other profile running returns `skipped`
  with a plain message and waits for its next tick. `skipped` is already a
  first-class scheduled-task status; nothing new is needed to report it.
- **Spread across the fleet.** The manager schedule does not give every node the
  same minute. Each node's slot is derived from its slug
  (`crc32(slug) % window_minutes`) inside a configured window, so forty nodes
  land across the window instead of forty simultaneous multi-hundred-megabyte
  uploads to one bucket.
- **Separated from the site's own default.** The site profile's shipped default
  is 02:00; the manager window defaults to 03:00–05:00. A collision then requires
  someone to deliberately configure one, and the lock handles it when they do.
- **Never two jobs deep.** `FleetBackupRun` skips any node whose previous manager
  backup job is still `pending` or `running` — a node that is slow gets fewer
  backups, not a queue.

## What gets built

### Phase 1 — profiles in the engine (node-side, no control plane involved)

`BackupRunner::run(['profile' => 'site'|'manager'])`. `plan()` resolves every
path, lock, key and retention setting **through the profile** rather than reading
bare settings. The `site` profile's behaviour is unchanged in every observable
way; the `manager` profile is not reachable yet.

- `bkh_profile` (`site|manager`) and `bkh_recovery_fpr` on `bkh_backup_history`.
- Per-profile working dir, lock, snapshot, manifest, envelope scratch.
- Machine-wide backup mutex.
- From-Backup installs exclude `config/backup_site_key` on extract, so a clone
  mints its own rather than inheriting its source's identity (defect 5).
- The defects above cease to be reachable.

### Phase 2 — the manager profile executes

`utils/run_backup.php --profile=manager --config=<fd>`, taking an **ephemeral
profile config** (target endpoint/bucket/prefix, recovery public key, mode,
retention, output dir) on a file descriptor rather than argv, with bucket
credentials injected by the agent at step time exactly as
`build_node_uploader_script()` does now. Nothing manager-owned persists on the
node except non-secret chain state (snapshot, manifest) and history rows.

- New job type `backup_run`, profile-aware and chain-capable.
- The node detail Backups tab's **Run Backup** buttons dispatch `backup_run`
  (manager profile) instead of the one-off shell-script path, so an on-demand
  backup extends the manager chain and is covered by manager retention.
- `backup_project` / `backup_database` stay exactly as they are for From-Backup
  installs, which need one self-contained archive rather than a chain link.

### Phase 3 — the control plane schedules

- `plugins/server_manager/tasks/FleetBackupRun.php` — every 15 minutes, find
  nodes whose manager profile is due, respect the concurrency cap, dispatch one
  `backup_run` per node.
- Per-node policy on `mgn_managed_nodes`: `mgn_backup_policy` (jsonb — enabled,
  frequency, window, mode, retention, target override) plus two queryable
  scalars, `mgn_last_backup_time` and `mgn_last_backup_outcome`, for sorting and
  alerting.
- Fleet defaults as declared plugin settings: enabled, target, window start and
  length, max concurrent jobs, mode, retention.
- **Manager retention runs here**, not on the node: after a run is confirmed, the
  control plane prunes that node's manager shelf with its own delete-capable
  credential, chain-atomically and driven by recorded history, exactly as
  `BackupRunner` does for the site profile. A run that failed never prunes.
- A node with no policy inherits the fleet default, which is **enabled**: a newly
  managed node gets manager backups without anyone remembering to ask. Bare nodes
  (`mgn_skip_joinery_checks`) are out of scope entirely.

### Phase 4 — see it and be told about it

- `includes/management_api/stats_handler.php` reports both profiles: schedule
  active, frequency/time, last run time and outcome, whether it was confirmed
  offsite, and the recovery fingerprint each used. This is where the site
  profile's state becomes visible to the fleet at all — it is reported, never
  written.
- `check_status` records it; the fleet table and the node Backups tab read
  columns rather than polling nodes on page load.
- `NodeMonitorHealth` gains **manager-profile** problems: last run failed,
  nothing successful within N intervals, no offsite copy confirmed. These join
  the existing dashboard problem list and notification path. The alarm is "my
  backups of this node are broken" — not "this node is unprotected", which is
  not the control plane's call to make.
- **Site-profile state is reported, never alarmed.** It appears on the node row
  as information — schedule, last run, which key opens it — because knowing a box
  runs two backups a night explains a lot about its 3am I/O. A site that runs no
  backups of its own is a site exercising a choice, and it alarms on the node's
  own Backups page, to the person whose choice it is.
- A node whose manager backups are switched off produces no fleet alarm either;
  that is also somebody exercising a choice. What prevents a node falling through
  unnoticed is the **default**, not a detector: manager backups are on for new
  nodes unless someone turns them off.
- **Cross-check against the bucket.** `FleetBackups::list_grouped()` already
  knows what is actually in the target, per slug. Comparing "the node says it
  succeeded" with "the object is there" is nearly free and is the only signal
  that catches a node lying by omission. A node whose backups have been failing
  for a month must not look identical to a healthy one.

### Phase 5 — the node's own page tells the truth

`/admin/admin_backups` grows a second, read-only panel: *Backups taken by
{control plane}* — schedule, last run, where they go, and which key opens them. A
site admin should never have to discover from a directory listing that someone
else is also backing their site up.

The site's own panel is unchanged, and in particular is not reframed as optional
or redundant: a site admin who wants their own copies, under their own key, on
their own schedule, is doing a supported thing and the page should read that way.

## Non-goals

- The control plane executing archives itself. The node does the work; the
  control plane triggers, credentials, and watches.
- Persistent manager bucket credentials on nodes. If that is ever wanted it is a
  separate decision with a separate blast-radius argument (per-node scoped keys),
  not something this spec smuggles in.
- Restore orchestration changes. Restore is per-artifact and already works from
  either profile: on the node via the site key, off the node via whichever
  recovery key that run recorded.
- Cross-profile deduplication. Two profiles means two copies; that is the point.

## Testing

- **Profile isolation gate** (`tests/backups/`): run both profiles against a
  throwaway tree, assert two independent chains, two snapshots, two manifests,
  no shared scratch, and that each profile's local retention leaves the other's
  files alone. Regression-pins defects 1–4.
- **Concurrency:** a second profile launched mid-run reports `skipped` and
  neither chain is damaged.
- **Key separation:** a manager-profile archive opens with the manager private
  key and **not** with the site private key; a site-profile archive the reverse;
  both open with the node's site key. Asserted against real envelopes.
- **Bucket layout:** objects land under `{slug}/{profile}/`, and each profile's
  retention only ever deletes its own.
- **Write-only enforcement:** a node handed the manager credential can upload and
  cannot delete — asserted against the real provider, not just against our own
  code declining to try. Control-plane retention deletes the same objects
  successfully with its own credential.
- `FleetBackupRun` scheduling: due-calculation, slug spread, concurrency cap,
  and never queueing behind a running job — unit-testable with no nodes.
- `job_command_builder_test.php` extended for `backup_run`.
- Live gate on dev against the real bucket: one manager chain and one site chain
  on the same node, both extended twice, both restored.

## Documentation (current-state only, at build time)

- `docs/backups.md` — profiles as the organising idea; the two-key custody model;
  per-profile paths and locks; what the site profile depends on (nothing).
- `plugins/server_manager/docs/overview.md` — `backup_run` job type,
  `FleetBackupRun`, fleet policy and defaults, the reporting columns, and the
  rewritten recovery-key section (push is explicit, not automatic).
- `docs/scheduled_tasks.md` — `FleetBackupRun` entry.
- `docs/api.md` — the management `stats` backup fields.

## Open Decisions

1. ~~**Bucket layout symmetry.**~~ **RESOLVED 2026-08-05 — symmetric.**
   `{slug}/{profile}/` for both profiles. Retention is history-driven, so objects
   already written flat age out correctly where they sit; nothing moves.
2. ~~**What happens to `push_recovery_key`.**~~ **RESOLVED 2026-08-05 —
   retired.** The manager profile carries its key per run, which is what the push
   was reaching for; writing into the site's slot was the wrong mechanism for it.
   `--report` stays. Managed nodes run manager-profile-only by default, with no
   key of their own.
3. ~~**Manager target: shared or per-node.**~~ **RESOLVED 2026-08-05 — one
   shared target, write-only on nodes.** The credential's *capability* is what
   protects the shelf, not its multiplicity: nodes can append, only the control
   plane can delete, and manager retention moves there with it. Per-node prefix
   scoping stays available as the next rung and is required before any node the
   fleet owner does not administer joins.
4. ~~**Does a manager run seal to the node's site key?**~~ **RESOLVED
   2026-08-05 — yes.** Unattended self-restore is what the site recipient exists
   for. Not a cross-site exposure (per-node key, write-only credential); within a
   site it grants read access to backup *history*, which is accepted. Turned up
   defect 5, fixed in phase 1.
5. ~~**Manager profile default mode.**~~ **RESOLVED 2026-08-05 — chains,**
   matching the site default. 193 MB → 37 kB measured; across a fleet that is
   most of the backup bandwidth. The cost is one extra full whenever a chain
   breaks, and control-plane retention must therefore be chain-atomic. Restores
   replay in order and are hash-checked before anything is written.
6. ~~**What the fleet does when a site profile is unconfigured.**~~ **RESOLVED
   2026-08-05 — nothing; the profiles are peers.** Nobody owns a node's backups;
   each party owns the copies it initiated. The fleet alarms on its own runs
   failing, the node alarms on its own, and neither reads the other's absence as
   a fault. A node cannot fall through unnoticed because manager backups default
   to on for new nodes — a default, not a detector.
