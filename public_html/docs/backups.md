# Backups

Every Joinery site can back itself up: on a schedule, encrypted, uploaded to an
S3-compatible bucket, with retention enforced. No agent, no SSH, no control
plane. server_manager is a fleet layer on top of this, not a prerequisite for
it — a site running server_manager backs *itself* up through exactly the same
path as a standalone install, because no site's recovery may depend on another
machine being alive.

Configured at **Admin → System → Backups** (`/admin/admin_backups`).

## Two parties, two profiles

A site can be backed up by more than one party. It backs itself up, and a
control plane managing it may take its own copies. Those are not two ways of
doing one thing — they are two parties' backups, under two recovery keys, on two
schedules, answerable to two people.

A **profile** (`includes/BackupProfile.php`) is the unit that keeps them apart:

| | `site` | `manager` |
|---|---|---|
| Configured by | the site's admin, on its Backups page | the control plane |
| Triggered by | the `Backup` scheduled task | the control plane's `FleetBackupRun` |
| Executed by | `BackupRunner` on the machine | `BackupRunner` on the machine |
| Recovery key | the site's own `backup_recovery_public_key` | the control plane's, supplied per run |
| Bucket credentials | stored on the machine | supplied per run, never stored; write-only via the target's node credential |
| Prunes the shelf | the site | the control plane |
| Depends on | nothing | the control plane being alive at the scheduled moment |

Both run the same engine, so chains, envelopes, deletion replay and history are
written once and behave identically for both.

**Neither profile owns the site's backups.** They are peers. A site admin who
wants copies of their own as well as the control plane's just sets their profile
up; a control plane keeps taking its own whatever the site does. Two backups a
night of one machine is a supported configuration, not a misconfiguration to be
detected.

The asymmetry in the last row is the safety argument: **the site profile depends
on nothing.** A control plane that is down, retired or hostile costs a site
nothing it was relying on.

Everything a run touches that could collide with another run is derived from the
profile: the working directory (`backups/` for the site, `backups/manager/` for a
control plane's), and therefore the lock, the tar snapshot, the chain manifest,
the envelope scratch and the local sweep. Sharing a snapshot alone would corrupt
both chains — each run advances it, so each profile would treat the other's work
as already archived.

Two locks are held. The per-profile lock is correctness: two runs of one profile
share a snapshot and a manifest. The machine-wide lock is courtesy and I/O: a run
that finds the other profile working reports itself `skipped` and waits for its
next tick.

## What a backup is

Two shapes, chosen by **How backups are taken**.

**Incremental (default).** A chain: one full, then runs that carry only what
changed. Measured on a real site, the first run's file archive was 193 MB and
the next was 37 kB.

```
{path_prefix}/{slug}/{profile}/chain-{YYYYMMDD_HHMMSS}/
    manifest.json           the restore contract — order, hashes, sealed keys
    files-0000.tar.gz.enc   the full
    db-0000.sql.gz.enc
    meta-0000.tar.gz.enc    shape.json + virtualhost + a note of the run
    files-0001.tar.gz.enc   an incremental
    db-0001.sql.gz.enc
    ...
```

**Full every time.** One self-contained archive per run:

```
{path_prefix}/{slug}/{profile}/{project}-{timestamp}.tar.gz.enc            the archive
{path_prefix}/{slug}/{profile}/{project}-{timestamp}.tar.gz.enc.keys.json  its envelope
```

Everything is AES-256-CBC (PBKDF2, random salt). `slug` defaults to the project
directory name — the same value a control plane would use for this site — so a
standalone site that later joins a fleet keeps one location instead of starting
a second pile beside the first. `profile` separates the parties, so a listing can
always say whose backup an object is and each party's retention addresses only
its own shelf.

## What a backup carries besides files and database

A backup has to be able to rebuild a site on hardware that is not the hardware it
came from, so it records the facts about that hardware which a restore has to
settle. `shape.json`, written by `reconcile_site.sh --print-shape` into the
archive (full mode) or the meta artifact (chain mode):

| Field | What it is for |
|---|---|
| `deployment_environment` | `docker` or `baremetal`, read from the site's own config — never probed for at runtime |
| `domain`, `web_root`, `base_dir`, `site_template` | where the site thought it was |
| `php_version`, `postgres_version` | what it was running under, so an incompatibility is legible later |
| `vhost_captured`, `vhost_role` | whether a virtualhost travelled, and whether it was a container's internal one or a bare-metal site's public one |

The virtualhost travels for **reference**, not for reinstallation — see
[what a restore reconciles](#what-a-restore-reconciles).

An archive with no `shape.json` restores normally: the source shape reads as
unknown and the restore reconciles against the target regardless.

## What a restore reconciles

Every restore path — the archive, the chain, and a From-Backup clone — ends in
`reconcile_site.sh`, which makes the restored site agree with the machine it
landed on. It reports each value it changed and refuses rather than papering
over a mismatch it cannot fix.

**The identity**, in both places it lives:

| Setting | Set to |
|---|---|
| `webDir` (config and `stg_settings`) | the domain the restore was given |
| `deployment_environment` | the target's shape |
| `baseDir`, `site_template` | the target's paths |
| database credentials | left as the target's — never taken from the backup |

**The domain is a required parameter.** It is not inferred, because the correct
answer depends on intent that is not in the data: a rebuild keeps the site's own
domain and cuts DNS afterwards, while a rehearsal must not claim it, and the same
backup on the same box wants opposite answers. The dashboard pre-fills the field
from the node's recorded URL.

**Two files in a backup are the machine's, not the backup's**, and the target's
own copies survive every restore:

- `config/Globalvars_site.php` holds this machine's database password and its
  `secret_box_key`. Restoring the source's copy is what turns a clean-looking
  rebuild into `SQLSTATE[08006]` on every page — and it bites a same-shape
  rebuild exactly as hard as a cross-shape one.
- `config/backup_site_key` identifies one machine as a recipient of its own
  backups. Two machines sharing it means one machine's key opens the other's
  archives. `backup_envelope.php` mints a fresh one on first use, so absent is
  the correct state.

**The serving config is always regenerated** from the platform's own templates,
in every case. On bare metal that is `virtualhost_update_script.sh` for the site
and domain; in a container the internal virtualhost written at install time is
already correct and the public name is served by the **host's** proxy, which
`manage_domain.sh` writes. A container backup is missing the piece that
terminates TLS, and a bare-metal backup carries a piece a container must never
use, so neither direction can be handled by copying files.

When the captured virtualhost differs from the generated one it is kept beside
the live file as `{site}.conf.from-backup` and named in the output, so a
hand-added redirect or alias survives on disk without being applied unattended.

**The certificate is never waited for.** The reconcile arms
`joinery-ssl-retry@{domain}.timer` (`arm_ssl_retry.sh`) and disarms the old
domain's. That timer checks DNS every five minutes, does nothing until the
domain resolves to this server, then issues once and disables itself. The
`<IfFile>` guard on the `:443` block means the site serves HTTP until then
rather than Apache refusing to start. Restore now, cut DNS later, certificate
arrives on its own.

## How chains work

The files archive uses GNU tar's `--listed-incremental` against a snapshot file
at `{working dir}/.{slug}.snar`, where the working directory is the profile's
own. tar records each directory's full contents, so
restoring replays **deletions** as well as additions — a file removed last
Tuesday is absent when you restore to Wednesday, rather than rising from the
dead.

The archive is taken from the **live tree**, not from a staging copy. This is
not a preference: an rsync copy gives every file a new ctime, so tar sees the
whole site as changed and every "incremental" silently becomes a full. Measured,
and pinned by a test.

Archiving a live tree means a file can change while tar is reading it. That is
tolerated — GNU tar reports it with exit status 1, the run notes it and carries
on, and the file's settled version ships with the next run. It also means the
file set has no single point-in-time: the database dump is taken minutes after
the file archive, so a deploy landing mid-backup can leave the two slightly
skewed. For a web tree this is the normal trade; restore the newest run if it
matters.

**The database is dumped in full on every run.** A dump is the small part, and a
half-applied database is not something anyone wants to restore.

A run starts a **new chain** when there is nothing to extend, when the snapshot
file is missing or empty, when the chain is older than the configured interval,
when one full is carrying more than 30 incrementals, or when the chain's
envelope no longer opens with the site key (the site key is disposable; a chain
sealed to a lost one cannot be extended, only restored). Losing the snapshot —
or the local manifest — is therefore safe: the next run costs one extra full,
and never produces a broken backup.

A run that **fails** partway clears the snapshot for the same reason: the
snapshot advances while tar runs, before the run is committed to the manifest
and confirmed in the bucket, so carrying it past a failure would quietly leave
the failed run's changes out of the chain. The next run starts a fresh chain
instead — one extra full, never a silently broken backup. The failed run also
deletes its own artifacts and puts the manifest back to its pre-run state: the
abandoned run's archives can be gigabytes a small disk does not have to spare
until chain retention removes the chain, and a local manifest describing a run
the bucket never received must not survive to be uploaded by anything later.

Runs are serialized with a lock in the working directory; a run that finds
another in progress reports itself skipped rather than racing it for the
snapshot and the manifest.

Retention over chains is **atomic**: a chain is kept or deleted whole. Deleting
the oldest runs of a chain would leave incrementals whose full is gone, which is
not a smaller backup — it is no backup, and it would look like a restore point
right up until someone needed it.

### Restoring a chain

```
php maintenance_scripts/sysadmin_tools/backup_envelope.php open \
    --sidecar manifest.json --private ~/recovery.key --key-out /tmp/k
bash maintenance_scripts/sysadmin_tools/restore_chain.sh {project} \
    --artifacts {downloaded chain dir} --key-file /tmp/k [--seq N] [--domain d]
```

Every artifact is checked against its recorded size and hash **before anything
is written**, so a truncated download fails while the live site is still intact.
`--seq N` restores as at run N; the default is the newest. `--dry-run` reports
the plan and needs no key. `--domain` names the domain the restored site is to
answer to; without it the site keeps the domain this machine's config already
names.

From the dashboard: the node's **Backups** tab lists chains as restore points
and its Restore button runs the `restore_chain` job. That job recovers the chain
key on the node from the node's own `backup_site_key`, so no recovery private key
travels in a job record. A chain taken by a machine that no longer exists is
restored from a shell with the recovery key, as above.

## Key model: one envelope per backup

Every run mints its own random data key, encrypts the archive with it, and seals
that key to two recipients:

- **recovery** — the recovery public key of whoever's backup this is. For the
  site profile that is the site's own setting; for the manager profile it is the
  control plane's key, which travels with the run and is never stored on the
  machine. The private half lives in a password manager and never touches a
  server. A site holds only the public half, so the same key can be configured on
  any number of sites and one private key opens every backup from all of them.
- **site** — a keypair the site itself holds at `config/backup_site_key`. This is
  what lets a site restore itself unattended: pre-restore rollback snapshots and
  routine restores need no operator. It is disposable — lose it and the recovery
  key still opens everything, and the next run mints a new one.

Nothing on the machine is precious as a result. Losing a site, or its whole
disk, costs no ability to read any backup it ever made.

The plaintext data key exists only as a `0600` file for the length of the run
and is destroyed before the run ends. It is never passed in argv, and on the
fleet path it never enters a management job row.

`config/backup_site_key` is pinned to `640 www-data:www-data` by
`fix_permissions.sh`, and minted at the same mode. Backups run under more than
one account — the web user takes the scheduled run, the deploy account runs one
from a shell — and both are in the `www-data` group, so the key is group-readable
rather than owner-only. A key that exists but cannot be read is an error, never
treated as absent — minting over a live key would orphan the site recipient for
every backup already sealed to the first one.

## Recovery key setup

Setup happens on the Backups page and needs no shell. The panel is rendered by
`includes/RecoveryKeySetupPanel.php` — one class for all four states
(`unconfigured` / `invalid` / `unproven` / `ready`), so every surface that
offers the setup offers the same one.

The default path is one screen:

1. **Generate.** The page mints an X25519 keypair with WebCrypto
   (`recoveryReadiness.generateKeypair()`). The private half is shown once, with
   copy and download, and is never sent anywhere. The public half never appears
   on screen at all — it rides the API call in step 2.
2. **Paste it back, one button.** The operator pastes the private key back from
   wherever they saved it; the page refuses a paste that does not match the
   generated key, then one button drives two API actions: `backup_recovery_save`
   stores the public half (unproven) and returns a challenge sealed to what was
   actually **stored** — re-read from the settings table, not echoed from the
   input — and the browser opens it with the pasted key (X25519 → HKDF-SHA256 →
   AES-256-GCM) and posts the recovered sentence to `backup_recovery_prove`.

The paste-back is the save confirmation, and the proof **must** come from the
pasted copy, never the in-memory one. The ceremony's job is proving that the
copy the operator *saved* works: auto-proving would pass just as happily for
someone who closed the tab without saving, and every backup afterwards would be
sealed to a key that exists nowhere. That is also why the proof is load-bearing
in general — sealing to a public key always appears to succeed, so a mistyped
key produces backups that all report themselves encrypted and recoverable while
every one is permanently unopenable. Until the proof is recorded, encrypted
backups refuse to run.

Generate-in-browser is the one setup path (a browser that cannot do X25519 is
told so plainly). A session that dies between save and proof lands on the
`unproven` state, which runs the same challenge ceremony with the saved private
key — in the browser, or via `escrow_keypair.php unseal`. A key generated at
the shell can still be installed by POSTing `save_recovery_key` (the
`admin_backups` handler), which stores it unproven into the same state.

Once a proven key and a scheduled target both exist, the nightly `BackupRun`
task switches itself on (`BackupNightly::maybe_activate`, called from the
setup-completing requests) — nightly backups are not a decision of their own.

`escrow_keypair.php` is the disaster-recovery tool — it runs on any machine
with PHP and libsodium, with no platform around it:

```
php maintenance_scripts/sysadmin_tools/escrow_keypair.php generate --private-out ~/recovery.key
php maintenance_scripts/sysadmin_tools/escrow_keypair.php unseal   --private ~/recovery.key
```

The encoding is one contract across all of them: both halves are the raw 32
bytes, base64, one line. `tests/backups/recovery_key_encoding_test.php` holds it
by running the shipped generator and checking libsodium agrees;
`tests/backups/recovery_one_screen_flow_test.php` holds the save → challenge →
prove sequence, and `tests/backups/backup_nightly_test.php` the activation
rules.

Replacing a proven key is a rotation, not an edit: backups already made carry
keys sealed to the old public key. Pasting over a proven value is refused.

Standing re-verification lives on **Recovery Readiness**, so "did I really save
it?" has an answer on demand rather than only at setup time.

### Only this site ever sets this site's key

`backup_recovery_public_key` is the key for the backups this site takes, and its
custodian is whoever administers this site. Nothing writes it from outside — a
control plane that wrote into it would hold the private half of a key the site
believes is its own.

An empty slot means this site takes no backups of its own. It does not mean the
site is unprotected: a control plane managing it takes its own copies, sealed to
its own key, which it carries with each run. Those are separate backups under
separate custody, and either party can have them without the other — see
[Server Manager](../plugins/server_manager/docs/overview.md#backups-across-the-fleet).

## Uploads

Artifacts reach the bucket through `S3Signer` — hand-rolled SigV4 against any
S3-compatible endpoint, so the backup path carries no SDK dependency. An
artifact of 1 GiB or less goes up as one signed streamed PUT. Above that,
`put_file()` switches to the **multipart API** on its own: no setting, no
caller involvement. The threshold sits deliberately far below the 5 GB
single-PUT cap every provider enforces, so the multipart path is exercised by
routine artifacts (a nightly database dump crosses it) rather than only by the
oversized archive it exists for.

Parts are 100 MiB: each is read into memory, hashed, and signed with its real
payload hash, so the provider verifies every part's bytes against the
signature, and a failed part is re-read from disk and retried on the same
budget as any other request. One part is also the peak memory cost, sized for
the smallest node. `CompleteMultipartUpload` responses are checked by **body**,
not just status — a provider can answer HTTP 200 with an `<Error>` document,
and that response is retried and then surfaced as a failure, never recorded as
a backup. Any failure aborts the multipart upload so no partial object is left
claimable; because an abort can itself be lost (the process can die), the
bucket should carry a cancel-unfinished-multipart lifecycle rule (B2: cancel
unfinished large files after 7 days) as the backstop.

`sha256` and `bytes` are computed from the local file either way; a restore
verifies against them and cannot tell how the object was uploaded.

## Retention

- **Cloud** — keep the newest N restore points (default 4). Older ones are
  deleted oldest-first, driven by this site's own run history rather than by a
  bucket listing, so it can only ever delete objects this site recorded writing.
  Retention runs last in a backup, and only after an upload is confirmed: a run
  that failed must never be the run that decides an older backup is surplus.

  Chains and standalone full backups are retained as **separate families**, and
  every run prunes both: standalone archives are counted and deleted per restore
  point, chains only ever whole. A site switched between modes keeps aging its
  old backups out, and no pass can delete a chain's full out from under its
  incrementals.
- **Local** — keep M days in `/backups` (default 7). This also sweeps the
  `auto_pre_*` snapshots a restore leaves behind, which are the size of a full
  backup. An archive and its envelope are always swept together. `0` means never.
  With **Delete the local copy once uploaded** on, a chain run removes its
  artifacts as soon as they are confirmed offsite — the chain stays extendable
  from just the manifest and the snapshot.

## Restoring

On the machine itself, nothing extra is needed — the envelope sits beside the
archive and opens with the site's own key:

```
bash maintenance_scripts/sysadmin_tools/restore_project.sh {project} /backups/{archive}
```

From the bucket, with only the recovery key:

```
php maintenance_scripts/sysadmin_tools/backup_envelope.php open \
    --sidecar {archive}.keys.json --private ~/recovery.key --key-out /tmp/k
bash maintenance_scripts/sysadmin_tools/restore_project.sh {project} {archive} --key-file /tmp/k
```

`restore_project.sh` decides whether an archive is encrypted by reading the
openssl magic bytes, not the filename, so a renamed archive still restores. It
takes `--domain` to name the domain the restored site is to answer to; without
it the site keeps the domain this machine's config already names.

The target's PostgreSQL must be at least as new as the source's. A dump carries
the syntax of the version that wrote it, so the restore reads that version from
the dump header and refuses before replacing the schema when the target is
older, reporting `RESTORE_SERVER_TOO_OLD` with the database untouched. Restoring
onto a newer PostgreSQL is ordinary and needs nothing.

A restore lands on an **installed** site. The config that carries this machine's
database password and `secret_box_key` is never in a backup, so the sequence for
new hardware is: install the site, then restore onto it. See
[Deploy and Upgrade](deploy_and_upgrade.md#rebuilding-a-site-on-new-hardware).

Bucket credentials plus the password-manager private key are sufficient to
recover from total loss of the machine.

## The node tool

`maintenance_scripts/sysadmin_tools/backup_envelope.php` is deliberately
standalone — no platform bootstrap — so it works during disaster recovery when
the site will not boot.

| Command | What it does |
|---|---|
| `mint` | Mints a data key, seals it, writes the key file and the envelope |
| `open` | Recovers the data key from an envelope or manifest, given a recovery or site key |
| `relabel` | Points an envelope at the archive it belongs to |
| `site-key` | Prints this site's public key, minting the keypair if absent |

`backup_files.sh` archives the file tree, incrementally when given a snapshot
path. `restore_chain.sh` applies a chain in order. Both take an explicit
directory override so the incremental and deletion-replay behaviour is tested
against a throwaway tree rather than a live site.

| Script | What it does |
|---|---|
| `reconcile_site.sh` | A site's shape, read both ways: `--print-shape` records it for a backup, the default mode makes a restored site agree with the machine it landed on |
| `arm_ssl_retry.sh` | Arms (or disarms) the DNS-gated certificate retry for a domain |

`includes/BackupEnvelope.php` reads and writes the same format;
`tests/backups/backup_envelope_cli_test.php` holds both to that contract in
both directions, because drift there is silent and only surfaces at disaster
time.

## Scheduling

The **Backup** scheduled task (`tasks/BackupRun.php`) runs this site's own
backups — it is pinned to the site profile, so a control plane's copies can never
be started by editing a row in this site's task table. It is not active
on install: a site with no target configured runs nothing and warns about
nothing. Activate it on **Scheduled Tasks**, where its frequency and time are
also set. It supports a dry run, which reports exactly what a real run would do
without producing or deleting anything.

A run is recorded in `bkh_backup_history` before it starts and updated when it
finishes — including when it fails. Every row carries `bkh_profile` (whose backup
it was) and `bkh_recovery_fpr` (which private key opens it), so a restore never
has to infer from today's settings what was true when the archive was made. A site whose backups have been failing for a
month looks identical to a healthy one if only successes are written down.

Because manager-profile rows land in the site's own database, the site can
answer "does someone back me up?" locally: `BackupHistory::manager_coverage()`
returns the newest manager-profile success that reached its bucket within
`MANAGER_COVERAGE_DAYS` (7), or null. The setup wizard's Backups step reads it
as a green condition, so a fleet-backed node is not asked to configure a bucket
it is already archived to; coverage goes stale on its own if the control
plane's runs stop.

## Artifact naming

`includes/BackupNaming.php` owns which files are backups, what each one is, and
what restoring it would do. Every surface consults it — the management API's
local listing, the node Backups tab, the job builder's globs.

Recognized suffixes are matched longest-first: `.sql.gz` is a suffix of
`.sql.gz.enc`, so a shortest-first match would classify every encrypted dump as
plaintext and hand the restore engine a file it will not decrypt.

## Settings

| Setting | Default | What it controls |
|---|---|---|
| `backup_recovery_public_key` | — | The key every backup seals to |
| `backup_target_id` | none | Which target scheduled backups upload to |
| `backup_type` | `project` | Whole site, or database only |
| `backup_mode` | `chain` | Incremental chains, or a full every time |
| `backup_full_interval_days` | `7` | Days before a chain rolls to a fresh full |
| `backup_retention_count` | `4` | Restore points (or chains) kept offsite |
| `backup_output_dir` | `/backups` | Working directory backups are built in |
| `backup_exclude` | — | Extra directory names to skip (build output, caches). A name matches a directory of that name at **any depth** — this is tar's exclude semantics, and it applies to the built-in skips (`vendor`, `cache`, `tmp`, `logs`, …) too |
| `backup_local_retention_days` | `7` | Days kept locally; 0 never sweeps |
| `backup_delete_local_after_upload` | off | Remove the local copy once uploaded |
| `backup_path_slug` | project dir | Folder in the bucket this site files under |

## The account backups run as

The scheduled task runs as the web user, which is what `fix_permissions.sh`
makes the owner of the site tree. A backup that cannot read part of the tree
**fails** rather than shipping a partial archive, and says which file it could
not read — so a permissions problem surfaces as a failed run with a specific
cause, not as a backup that turns out to be incomplete on the day it is needed.
