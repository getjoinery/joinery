# Backups

Every Joinery site can back itself up: on a schedule, encrypted, uploaded to an
S3-compatible bucket, with retention enforced. No agent, no SSH, no management
node. server_manager is a fleet layer on top of this, not a prerequisite for
it — a site running server_manager backs *itself* up through exactly the same
path as a standalone install, because no site's recovery may depend on another
machine being alive.

Configured at **Admin → System → Backups** (`/admin/admin_backups`).

## Two parties, two profiles

A site can be backed up by more than one party. It backs itself up, and a
management node managing it may take its own copies. Those are not two ways of
doing one thing — they are two parties' backups, on two schedules, onto two
shelves, answerable to two people. They open with the same key, because that key
belongs to the machine and its administrator, not to whoever asked for the run.

A **profile** (`includes/BackupProfile.php`) is the unit that keeps them apart:

| | `site` | `manager` |
|---|---|---|
| Configured by | the site's admin, on its Backups page | the management node |
| Triggered by | the `Backup` scheduled task | the management node's `FleetBackupRun` |
| Executed by | `BackupRunner` on the machine | `BackupRunner` on the machine |
| Recovery key | the site's own `backup_recovery_public_key` | the site's own `backup_recovery_public_key` |
| Bucket credentials | stored on the machine | supplied per run, never stored; write-only, and minted for that one run where the provider allows it |
| Prunes the shelf | the site | the management node |
| Depends on | nothing | the management node being alive at the scheduled moment |

Both run the same engine, so chains, envelopes, deletion replay and history are
written once and behave identically for both.

### The credential a run is handed

A manager run needs a credential to write with, and it is chosen at build time
from three, strongest first:

- **A key minted for that run.** Where the target's provider can pin a key to
  one bucket, one name prefix, one capability and a lifetime — Backblaze B2
  does, in one call — the node is handed a key created when the job is picked
  up, allowed only to add objects under **its own** prefix, expiring with the
  run. A key read off a machine somebody else administers then opens that
  machine's own directory for an hour, rather than the fleet's whole shelf
  indefinitely. **Off until an operator turns it on**, per target, on the Remote
  Backup page — minting needs a master key the provider will let create keys,
  and a target switched on without one fails every run rather than falling back.
- **The target's write-only node credential.** A second stored key allowed to
  add objects and not to delete any. Shared across the fleet, which is what
  minting improves on.
- **The target's main credential.** Where neither of the above is configured.

Minting happens at PICKUP rather than at build, because a key's lifetime starts
when it is created and the moment that matters is when the agent actually holds
it; a key minted an hour earlier would arrive expired and read at the node as a
bucket error. Its lifetime is derived from the job's own claim budget, so it
outlasts the upload it exists for. A target that declares it can mint and then
cannot fails the job with the provider's reason — it never falls back to the
shared key, because a silent downgrade would defeat the only thing minting is
for, on exactly the machines where it matters.

Restores need no minted key at all: the management node signs one object key
per artifact and the node receives the signature, so no bucket credential of
any kind travels for a read.

**Two things to settle before switching minting on for a real fleet**, neither
of which has been exercised yet:

- **Key lifetime and cleanup.** Nothing deletes a minted key early; each one is
  expected to expire on its own. Whether the provider removes expired keys, and
  what its per-account key ceiling is, decides whether a fleet of forty nodes
  backing up nightly accumulates keys faster than they lapse.
- **Cost per pickup.** Minting adds three provider round trips to the job
  hand-out request (authorize, look the bucket up, create the key), and the
  bucket is looked up afresh every run. Fine for a fleet of tens; worth caching
  the bucket id if job pickup ever starts timing out.

**The recovery key is the one thing a management node does not supply.** It says
where a backup goes and hands over a write-only credential to put it there; what
opens the archive is read on the machine, from that machine's own verified
setting. A manager run that arrives carrying key material is refused, not
ignored, and a machine with no verified key of its own refuses to back up at all
rather than sealing to a key it was handed.

The reason is that sealing cannot fail visibly. Encrypting to a public key
succeeds whether or not anybody holds the private half, so a substituted key
produces archives that report themselves encrypted, upload normally and show
green on every dashboard — while only whoever substituted the key can read them,
and only a restore attempt would ever reveal it. A key that arrives over a wire
is therefore a key nobody on the receiving machine can verify, whatever sent it.
The cost is accepted deliberately: opening a machine's backups needs that
machine's recovery key, and no single key opens a fleet.

**Neither profile owns the site's backups.** They are peers. A site admin who
wants copies of their own as well as the management node's just sets their profile
up; a management node keeps taking its own whatever the site does. Two backups a
night of one machine is a supported configuration, not a misconfiguration to be
detected.

The asymmetry in the last row is the safety argument: **the site profile depends
on nothing.** A management node that is down, retired or hostile costs a site
nothing it was relying on.

Everything a run touches that could collide with another run is derived from the
profile: the working directory (`backups/` for the site, `backups/manager/` for a
management node's), and therefore the lock, the tar snapshot, the chain manifest,
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
{path_prefix}/{slug}/{profile}/{project}-{YYYYMMDD_HHMMSS}.tar.gz.enc            the archive
{path_prefix}/{slug}/{profile}/{project}-{YYYYMMDD_HHMMSS}.tar.gz.enc.keys.json  its envelope
```

A database-only backup is the same shape with `{database}-{YYYYMMDD_HHMMSS}.sql.gz.enc`
as the archive. All three families carry the same stamp, which is what a
management node's retention sorts a shelf by.

**Nothing an engine produces lands on this disk.** The files archive, the
standalone archive and the database dump are each one pipeline —
`tar | openssl` or `pg_dump | gzip | openssl` — whose stdout the runner hands
to `S3Signer::put_stream()`, which uploads it as it flows. What a run holds on
disk is the metadata artifact (kilobytes), the chain manifest, the snapshot,
and, for a standalone archive, the compressed database dump for the length of
the tar (it is a member of that archive, staged beside the output and removed
with the staging directory). A standalone archive is built from the **live**
tree — a second `-C` into the site root with the members renamed under
`project_files/` — so no copy of the site is ever made. On the smallest nodes
this is the difference between a site that can be backed up and one that
cannot: peak disk during a run is the site itself.

The engine's verdict arrives after its bytes. tar reports a file that changed
while it was being read as exit 1 (accepted — the normal case on a live tree)
and a real failure as 2 or more; `pg_dump` reports its own. So each engine in
stream mode (`--archive -`) writes its statuses to a report file
(`--report FILE`) once the stream has been fully produced, and the runner
completes the upload only when the process exited 0, the report says tar 0
or 1 (or `pg_dump` 0), openssl 0, and at least 64 bytes went up — an openssl
envelope around an empty stream is 32 bytes, and a backup of nothing is never
recorded as a backup. Anything else aborts the upload: nothing partial or
empty is ever on the shelf, and a chain run that fails this way is discarded
under the ordinary rule (snapshot cleared, manifest restored, the run's other
objects deleted where the credential can delete).

Everything is AES-256-CBC (PBKDF2, random salt). `slug` defaults to the project
directory name — the same value a management node would use for this site — so a
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

## Offloaded files on the shelf

A site that offloads its uploaded files to a cloud file store
(`docs/cloud_storage.md`) serves them from that bucket, and its archives carry
none of them: the runner hands the files engine an exclude list naming every
`cloud` blob's local paths, original and variants. Each offloaded file is on
the backup shelf **once** instead — encrypted, content-addressed, kept for as
long as any retained run's index names it — so a restore point stays whole
whatever the chain does, and a chain's incrementals never carry a day of
photos the shelf already holds.

```
{prefix}/{slug}/{profile}/
    chain-{id}/
        objects-0003.json.gz          the run's index — plain gzipped JSON, like the manifest
    {slug}-{stamp}.objects.json.gz    a standalone full's index, same stamp as its archive
    objects/
        {epoch}/
            envelope.json             the epoch's sealed data key
            {fbb_stored_name}.enc     one object per offloaded blob
```

**The index** is a manifest artifact of kind `objects` and names every
offloaded blob the run knew: `name`, `epoch`, the **encrypted** object's
`object_bytes` and `object_sha256`, and `stored` — whether it was on the shelf
when the index was written. No plaintext size, hash or MIME type: the private
store offloads private blobs too, and a plain file the management node reads
must not carry a fingerprint of a private file's content. Everything a restore
needs about the plaintext is in the blob row, which is restored first. Objects
are not ledgered; their integrity chain is manifest → index → object, each
verified against the one above before it is opened.

**Epochs** are the key model. One data key with one envelope at
`objects/{epoch}/envelope.json` — the ordinary envelope, sealed to the same two
recipients as every chain — encrypts every object stored while the epoch is
current, in the same `aes-256-cbc-pbkdf2` form as an archive, so the
*Opening a backup with no Joinery anywhere* procedure opens an object
unchanged. A new epoch starts when there is none, when the recovery recipient
changed, or when the site key cannot open the current envelope (degrade, never
fail every run). On recovery-key rotation the older epochs are **re-sealed**,
not re-encrypted: each envelope the site key opens is rebuilt with the new
recovery recipient added and uploaded again under its name, so the new key
opens everything and the old key still opens what it always did. An epoch the
site key cannot open stays sealed to the retired key alone; the run writes
those down (`objects/retired-epochs.json`), names them in its message (so a
management node's job result and this site's history say so), and **Recovery
Readiness** says so on the recovery-key card — "N objects (X GB) open only
with a retired recovery key" — with no automatic re-copy. Keep that key.

**Enabled profiles, and the hold.** A profile stores offloaded files when it
is *enabled* (`BackupProfile::enabled()`): the site profile when a target is
enabled, the recovery key proven and the backup type includes files; the
manager profile when this machine has joined a management node and a manager
run carrying the object store has been here (the `objects/enabled` marker).
An offloaded file's local bytes stay on this server until **every enabled
profile holds it** — the site profile by the store the offload tick makes on
the way out (`docs/cloud_storage.md` § An offloaded file is on the backup
shelf before its local copy goes), the manager profile by its `held.json`,
which the management node's runs write. Only then are the original and its
variants released. With no profile enabled nothing is held, and the file
store alone serves the file.

**The run.** Before the archive: the epoch; what the shelf holds (the site
profile lists its `objects/` prefix; the manager profile reads the newest
manager index through the presigned `objects_index_url` in its request, union
`held.json`); the exclude list; then the store — every `cloud` blob not held,
one at a time under the offload engine's per-row lock, local originals first,
then **catch-up**: a blob with no local bytes is fetched from the file bucket,
encrypted and uploaded, both temporaries deleted. One budget covers the whole
step, **2 GB or 20 minutes** (`BackupRunner::OBJECT_STORE_BUDGET_*`, constants);
what it leaves is indexed `stored: false` and taken next run, and the Backups
page says "N files (X GB) still to copy from the file store" until it reaches
zero. Then the engines with the exclude file, the index, the commit, and —
after the run is offsite — `held.json` and the release. A run whose request
did not ask for objects (a management node not running the object store, a
database-only backup) stores nothing and holds nothing.

**The manager run request** carries three fields from a management node
running the object store: `objects: true`, `objects_index_url` (a presigned
GET for the newest manager index, or absent when there is none) and
`epoch_envelope_urls` (`{epoch id: presigned GET}` for every envelope in the
listing, what the re-seal reads). A node whose management node sends none of
them behaves as if the object store did not exist.

**Node disk.** Every step is bounded:

| Step | Extra disk held | For how long |
|---|---|---|
| Offload tick, site shelf store | one object's ciphertext | the one upload |
| Waiting for the manager run | uploads since the last successful manager run | until that run |
| Run store step | one object's ciphertext | per object |
| Catch-up | one object's plaintext + ciphertext | per object |
| Tar | as before, **less** every `cloud` blob | as before |

On the node, per profile, beside the chain directories: `objects/epoch.json`
(the current epoch and its envelope), `objects/held.json` (what this
profile's shelf held as of its last run — a cache, rewritten whole by every
run, consulted only by the release rule), `objects/retired-epochs.json`,
`objects/enabled` (manager profile) and `objects/tmp/`.

**Retention.** Objects are a third family beside chains and standalone
fulls, pruned by whoever prunes that shelf: the management node's
`FleetBackupRetention::prune()` deletes an object when no retained run's index
names it and it is older than the newest retained run's start, and stops
without deleting when any index it needs cannot be read; the site's own
`enforce_object_retention()` deletes, when a chain or standalone full is
pruned, every object its indexes name that no retained index names. An empty
epoch's envelope goes with its last object.

**What each shelf protects against.** The object store protects against a
deleted bucket, a revoked key and an accidental `rm` in the file store. It
does not protect against losing the *account* the shelf is on: when the site's
backup target and the file store share an access key, the Backups page and
the cloud-storage page both say "Your backup shelf and your file store are on
the same account. Losing that account loses both. A copy taken by a management
node is the one that survives it." The same-key test is the whole rule.

**On the pages.** The Backups page's **Offloaded files** box: how many files
live in the file store; per enabled backup, "Offloaded files on the shelf
(this site's backup): N objects, X GB; last indexed at the run of …"; what is
still to copy from the file store; "M files (X GB) waiting for the management
node's backup before their local copy is released" (every `cloud` row whose
original is still on disk — one `stat` per row on page load, no column, no
cache; `BackupObjectsStatus`); the same-account line; and the daily file-store
check with **Bring them back** (§ The file store is checked too). The
cloud-storage page's Status block has the waiting count and size. The
node's Backups tab on the management node reads the shelf listing:
"Offloaded files on the shelf: N objects, X GB by this management node; last
indexed at the run of …", with **Bring them back**. And because a count on a
page is read by nobody, `BackupObjectsNotice` stands on every admin page when
the waiting bytes pass 2 GB or anything waits for a backup that has not
succeeded in 7 days: "N files (X GB) are waiting for the management node's
backup, which last succeeded D days ago. They stay on this server until it
does." It clears itself when nothing waits.

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
removes what it made and puts the manifest back to its pre-run state: the
metadata artifact on disk, and any object it had already streamed to the shelf
where the credential can delete (the site profile). Under the manager profile's
write-only credential an already-streamed files object stays until its chain is
pruned whole — a bounded orphan the manifest never names. A local manifest
describing a run the bucket never received must not survive to be uploaded by
anything later.

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

**Offloaded files.** The archives carry no file the site offloaded to its
file bucket; those are on the shelf under `objects/{epoch}/`, named by the
run's index (`objects-NNNN.json.gz` beside the run's archives). Download that
tree with the site's own credential and hand it over:

```
bash maintenance_scripts/sysadmin_tools/restore_chain.sh {project} \
    --artifacts {downloaded chain dir} --key-file /tmp/k --objects {downloaded objects dir} \
    [--objects-mode missing|all] [--epoch-key epoch-20260901_000000=/tmp/e]
```

The step runs after the database is loaded, because the blob row is what says
where each file belongs and how big it is. It runs the restored tree's own
`utils/restore_objects.php`: each object is checked against the index's size
and hash before it is decrypted, decrypted into its placement, checked against
its row (size, and `fbb_sha256` where the row records one), put in place, and
only then is the row set to `local`. `missing` (the default) brings home only
what the file bucket cannot serve — the bucket is `HEAD`ed per file — and `all`
brings every offloaded file home, for a site leaving its bucket. Nothing on
disk is overwritten (a file already at the placement is adopted when it matches
its row and refused by name when it does not) and nothing in any bucket is
deleted; running it again finishes what an interrupted run left. Variants are
regenerated on demand. Each epoch's envelope opens with the machine's own
`backup_site_key`; for an epoch it does not open, recover the key with
`backup_envelope.php open --sidecar {epoch}/envelope.json --private …` and pass
it as `--epoch-key`. The script can be run on its own, and `--dry-run` says
how many files would come home and from which epochs:

```
php public_html/utils/restore_objects.php --index {chain dir}/objects-0003.json.gz \
    --objects {objects dir} [--mode missing|all] [--dry-run]
```

From the dashboard: the node's **Backups** tab lists every run on the node's
shelf, newest first, with the last backup, the last full backup and the oldest
backup held stated above the list — and, when a run on the shelf carries an
offloaded-files index, an **Offloaded files** row with **Bring them back**,
which runs the `restore_objects` loop below in `missing` mode against the
newest such run without restoring anything else; each row's Restore button
runs the `restore_chain` job for that run. That job recovers the chain key on the node
from the node's own `backup_site_key`, so no recovery private key travels in a
job record. Once it completes, the run's offloaded files follow in `missing`
mode as the paged `restore_objects` jobs described under *Restoring a managed
node* below. A chain taken by a machine that no longer exists is
restored from a shell with the recovery key, as above.

## Key model: one envelope per backup

Every run mints its own random data key, encrypts the archive with it, and seals
that key to two recipients:

- **recovery** — the site's own `backup_recovery_public_key`, read from this
  site's settings. Both profiles seal to it: a management node's copies of this
  site open with the same key the site's own copies do, held by the same
  custodian. The private half lives in a password manager and never touches a
  server. A site holds only the public half, so the same key can be configured on
  any number of sites and one private key opens every backup from all of them —
  which is a choice each operator makes for their own sites, not something a
  management node can arrange from outside.
- **site** — a keypair the site itself holds at `config/backup_site_key`. This is
  what lets a site restore itself unattended: routine restores need no operator. It is disposable — lose it and the recovery
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

### Opening a backup with no Joinery anywhere

The format is deliberately stock crypto, so a backup opens on any machine with
`openssl` and PHP's sodium extension (or any libsodium binding) — no Joinery
code, no server, no network. You need three things: the encrypted archive, its
envelope (the `{archive}.keys.json` sidecar beside a standalone archive; for a
chain, the `envelope` object inside the chain's manifest JSON), and the
recovery private key from the password manager (base64, 32 bytes decoded).

Step 1 — unseal the data key from the envelope (each `recipients[].sealed`
entry is a libsodium sealed box over the data key; try each until one opens):

```bash
php -r '
$env = json_decode(file_get_contents($argv[1]), true);
$sk  = base64_decode(trim(file_get_contents($argv[2])));
$kp  = sodium_crypto_box_keypair_from_secretkey_and_publickey(
           $sk, sodium_crypto_box_publickey_from_secretkey($sk));
foreach ($env["recipients"] as $r) {
    $key = sodium_crypto_box_seal_open(base64_decode($r["sealed"]), $kp);
    if ($key !== false) { file_put_contents("data_key.txt", $key); exit; }
}
fwrite(STDERR, "no recipient in this envelope opens with that key\n"); exit(1);
' {archive}.keys.json recovery_private.b64
```

Step 2 — decrypt the archive (the data key is the passphrase; the cipher is
recorded in the envelope's `cipher` field, `aes-256-cbc-pbkdf2`):

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:data_key.txt \
    -in {archive}.tar.gz.enc -out {archive}.tar.gz
```

Then shred `data_key.txt`. The same two steps work with the site key —
`config/backup_site_key` is the base64 of the raw sodium keypair, so in step 1
replace the two keypair lines with
`$kp = base64_decode(trim(file_get_contents($argv[2])));` — and that is all the
platform's own restore path does. A chain restores by decrypting the full plus
each incremental with the one data key from the chain manifest's envelope and
applying them oldest-first.

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

### Rotating the key

Rotation is offered where the key lives: the Backups page's Recovery key
section, **Actions → Rotate key**. It walks the same generate-and-verify
ceremony as setup — new keypair made in the browser, private half into the
password manager, pasted back to prove the stored copy — with the save marked
as a deliberate rotation. Three properties make it safe:

- **Old backups keep opening.** Each chain's data key was sealed at chain
  start; rotation never touches it. Keep the old private key until every chain
  sealed to it has been retired. Offloaded files' epoch envelopes are
  re-sealed to the new key by the next run where the site key opens them; one
  it cannot open is named on Recovery Readiness as opening only with the
  retired key.
- **Nothing seals to the new key until it is proven.** An interrupted rotation
  leaves the key unproven, and backups refuse to run — loudly — until the
  ceremony is finished (or run again with a fresh key).
- **The next run starts a fresh chain.** A chain cannot change recipients
  mid-life, so the runner ends the current chain when the recovery recipient no
  longer matches (`recovery_rotated`) and the new chain seals to the new key.

Standing re-verification lives on **Recovery Readiness**, so "did I really save
it?" has an answer on demand rather than only at setup time.

### Only this site ever sets this site's key

`backup_recovery_public_key` is the key every backup of this site seals to,
whoever took it, and its custodian is whoever administers this site. Nothing
writes it from outside. Possession is proven here, against a challenge this site
issued: `maintenance_scripts/sysadmin_tools/set_recovery_key.php` reports what
this site holds and refuses to write it, and a management job that passes key
material is refused by the tool it passes it to.

**An empty slot means this site takes no backups, for anybody.** Not its own, and
not a management node's copies of it — there is no key those could be sealed to,
and an unencrypted whole-site archive on somebody else's shelf is not a fallback.
Set the key up at Admin → System → Backups: the page generates a keypair in the
browser and runs the possession challenge in one pass, needing no management node
and no shell. A management node managing this site can see that the slot is empty
and say so on its dashboard — see
[Server Manager](../plugins/server_manager/docs/overview.md#backups-across-the-fleet)
— and that is the whole of what it can do about it.

## Uploads

Artifacts reach the bucket through `S3Signer` — hand-rolled SigV4 against any
S3-compatible endpoint, so the backup path carries no SDK dependency. A
Backblaze credential needs the account's region and S3 endpoint to sign, and
the forms hide both: `BackupTarget::complete_credentials()` fills them from
Backblaze's own authorize answer at save time, and `get_credentials()` fills
them on read for a row that still lacks either, writing the completed
credential back once (a server-initiated reconciliation) so the signer never
sees an incomplete B2 credential.

**Streamed artifacts** — the files archive, the standalone archive, the
database dump — go through `S3Signer::put_stream()`: an engine's stdout,
unknown length, never re-readable. The stream is read one part at a time,
hashed and counted as it goes. A stream that ends inside the first part is
sent as one signed PUT of the buffered bytes; anything longer opens a
multipart upload once the first full part is in hand, and each part is a
string signed with its real payload hash, so a retry re-sends the bytes it
holds. The runner asks for completion to be **deferred**: the parts go up (or
the small buffer is held) while the engine runs, and `CompleteMultipartUpload`
— or the single PUT — is issued only after the engine's report has been read.
A refused archive is aborted, and under the small-stream shape nothing was
ever sent, which is what lets a write-only credential refuse an archive with
no object to delete.

**File artifacts** — the metadata artifact, the chain manifest, an envelope
sidecar — go through `put_file()`. At 1 GiB or less that is one signed
streamed PUT; above it `put_file()` switches to the **multipart API** on its
own, with the source re-read from disk on a retry.

Parts are 100 MiB in both paths: each is held in memory, hashed, and signed
with its real payload hash, so the provider verifies every part's bytes
against the signature, and a failed part is retried on the same budget as any
other request. One part is also the peak memory cost, sized for the smallest
node; 100 MiB × the API's 10 000-part cap is about 1 TB per object.
`CompleteMultipartUpload` responses are checked by **body**, not just status —
a provider can answer HTTP 200 with an `<Error>` document, and that response
is retried and then surfaced as a failure, never recorded as a backup. Any
failure aborts the multipart upload so no partial object is left claimable;
because an abort can itself be lost (the process can die), the bucket should
carry a cancel-unfinished-multipart lifecycle rule (B2: cancel unfinished
large files after 7 days) as the backstop.

`sha256` and `bytes` are the hash and count of the bytes that went up — taken
from the stream by the process that pushed them, or from the local file for a
file artifact. A restore verifies against them and cannot tell how the object
was uploaded.

### The upload ledger

Every artifact that reaches the bucket is also recorded on the machine that made
it, in `config/backup-ledger/{profile}.json`: the artifact's name relative to
its backup directory, its sha256, its size, and when it went up. `BackupLedger`
writes it at the moment of upload: `record()` hashes a file artifact from disk,
and `record_hash()` records a streamed artifact from the hash and count the
upload itself took — the same claim, this machine made these bytes, taken by
the process that pushed them from the same bytes.

It exists for one adversary: the party that chooses where a restore's bytes come
from. When a management node runs this machine's backups it also owns the bucket
and signs the download, and the person approving a restore approves a *name* and
a date — they never see the bytes. The ledger is the only thing on the machine
able to say whether those bytes are the ones it uploaded under that name. Two
attacks fail against it, and the second is the one that carries it:

- **Forgery** — an artifact whose content is simply made up. The hash does not
  match.
- **Replay** — this machine's own genuine month-old archive, served under a
  fresh-looking name. Every signature verifies and every envelope opens, because
  it really is this machine's backup; sealing does not touch this at all. The
  name it is offered under has no record, so it is refused.

A name that is legitimately rewritten keeps its earlier versions. Only one is:
a chain's `manifest.json`, which every run of that chain rewrites. The ledger
answers "did this machine make these bytes", not "are these the newest bytes it
made" — so a chain staged for restore is not refused because a scheduled backup
happened to land while somebody was reading the approval screen. What is
reported back is the version that matched, so the age shown on that screen is
the age of the bytes being restored.

The address is chosen for two properties that are invisible from the code that
reads it. `config/` is a named volume on a container node, so the ledger survives
a container rebuild — a ledger under `/var/lib` would not, and since a ledger
only records what has been uploaded *since*, restore would stay broken for as
long as the current chain is old. And `restore_project.sh` drops
`config/backup-ledger` from a staged archive the same way it drops
`Globalvars_site.php` and `backup_site_key`: they are the machine's, not the
backup's, so the first restore cannot overwrite the record that vouches for the
second.

A backup taken by an unprivileged process cannot write the ledger; the run
reports that rather than hiding it, because an unledgered artifact is one the
machine will refuse to restore from over the agent channel. On a managed node
the backups that matter are taken by the root agent, so they are ledgered as a
matter of course.

The ledger is `0700`/`0600`, and both the platform and the agent **refuse** one
that group or other can write rather than reading it. Anything that can write
the ledger can vouch for any bytes it likes, so a loose ledger does not weaken
the check — it makes the check report success. The test is on the mode, not on
the owner: backups legitimately run as root on a managed node and as the site
user elsewhere. `fix_permissions.sh` pins the directory out of its sweep to
match.

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
  incrementals. Offloaded files under `objects/` are a third family, deleted
  only when no retained run's index names them ([Offloaded files on the
  shelf](#offloaded-files-on-the-shelf)).
- **Local** — keep M days in `/backups` (default 7). What a run leaves on this
  disk is small: the chain's metadata artifact, and a standalone run's envelope
  sidecar. The archives and the dumps stream to the bucket and are never here.
  This window says how long those leftovers are kept. Age is per file, so an
  old chain's early runs go while its recent runs stay, and the emptied chain
  directory is left for chain retention to retire. A chain's `manifest.json`
  and the snapshot beside it are never swept — they are what make the chain
  extendable, and without either the next run silently starts a fresh full. The
  sweep also removes the `auto_pre_*` snapshots a restore leaves behind, which
  are the size of a full backup. An archive and its envelope are always swept
  together. `0` means never. **Delete the local copy once uploaded** is on by
  default: a run removes its metadata artifact (and a standalone run its
  sidecar) as soon as they are confirmed offsite instead of waiting out the
  window; a restore or a verify fetches from the bucket either way. Turned off,
  the leftovers stay until the window ages them out.

  On a machine a management node backs up, this window is the *only* thing
  bounding local disk. Chain retention deletes a chain's local directory as
  part of pruning the bucket, and a managed node does not prune the bucket —
  the shelf belongs to the management node, and the credential the node is
  handed cannot delete.

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

**Nothing is saved before a restore.** The schema is dropped and replaced, and
the state that was there is not kept anywhere. A restore happens because the
current state is wrong, so preserving it preserves what the operator has decided
to discard — and it kept a full copy of the database, per restore, indefinitely,
on a disk sized for backups rather than for regret. The approval an operator
answers already says in words that anything written since the archive was taken
is gone.

What that gives up, stated plainly: a load that fails part way leaves the schema
already replaced and nothing on the machine to put back
(`RESTORE_LOAD_FAILED`). The answer is the archive itself, which is still on the
shelf and can be retried, or an earlier one.

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

### Restoring a managed node from its management node

A managed node is restored over the agent channel, and it takes three steps
because they are three genuinely different decisions.

**Bring the backup back.** Every node deletes its local archive once it is
safely uploaded, so the normal state of a machine is that its backups are all
offsite — and a restore takes the name of a file it expects to find in its own
backup directory. *Bring back to node* on the node's Backups tab (or *Prepare*,
for a chain) fetches it. No bucket credential is sent: the management node signs
one object key, for no longer than the job's own claim budget, and the node
receives the signature. A node's stored credential is write-only by design,
because a node that could read the shelf is a node whose compromise reaches
every other node's backups. Everything fetched is checked against the node's own
upload ledger before it lands, and lands `0600` — created that way, not chmod'd
afterwards, because on a container node the backup directory is inside the site
tree and a descriptor opened during a multi-gigabyte transfer stays open. The
transfer is capped at the size the ledger recorded, so a response that declines
to say how big it is cannot run the node's disk to zero; and a failed download
reports its HTTP status without its body, because the plane picks the URL and
reads the transcript.

**Ask for the restore.** The management node dispatches it as a primitive. It
sends a *name* and a profile — no path, no key, no bucket, no domain. A chain
restore also carries a project, and the node treats it as a claim to check
rather than an instruction: `restore_chain.sh` spends that value twice, on the
tree it replaces and on the database name it loads over, so the node uses its
own project and refuses a job naming any other.

**Approve it on the node.** The node's agent claims the job and runs nothing. It
composes its own statement of what it would do — which project, which database,
which archive, the archive's real age, size and fingerprint — from its own
records, seals a
one-time challenge to the backup recovery public key it already holds, binds it
to that job and that statement, and stages it for the node's own site. The
node's Backups page shows the pending approval; the operator opens the challenge
there with their recovery key, in their browser, and answers. The agent verifies
the answer against what it sealed and only then restores.

**What a database restore leaves behind, and what it does not roll back.** A
database restore replaces the database and nothing else, so a dump taken before
an upgrade lands under files that are still on the newer release: the site's
recorded `system_version` and its settings go back to the older value while the
code on disk stays put. This is self-correcting — the next upgrade re-runs the
migrations, which are idempotent — but it means the version a freshly restored
node reports from its database can trail its files until then. A restore also leaves the
archive it was given in the backup directory; the local sweep expires it on the
ordinary `keep_local_days` schedule.

**Bring the offloaded files home.** The archives carry no file the site
offloaded to its file bucket, so once the chain restore completes the plane
brings those home too, in `missing` mode — only what the file bucket cannot
serve. The node never lists its shelf and holds no read credential, so every
object arrives by a link the plane signs, and a job is bounded, so the work is
paged and the plane drives the loop (`FleetObjectRestore`): a **survey** job
(the `restore_objects` primitive with the run's index link and no object
links) has the node read the index — ledger-checked like every artifact —
`HEAD` its file bucket per offloaded file, and answer with the names it would
bring home (`RESTORE_OBJECTS_WANT`, at most a thousand, `RESTORE_OBJECTS_MORE`
when there are more). The plane signs a **page** of links from that answer —
object links plus the envelope of each epoch those objects are sealed under,
filled to the job's byte ceiling and never more than 150 — and each page's
result issues the next, so no link waits in a queue past its expiry. When the
pages are done and the survey said there was more, a fresh survey names the
next thousand; a survey that names nothing ends the loop. The node opens each
envelope with its own key, fetches objects one at a time, checks each against
the index before decrypting it and against its row after, and sets the row to
`local` only once the file is in place. `restore_objects` is an operate
primitive: it overwrites nothing and deletes nothing in any bucket, so no page
needs an approval. A failed page ends the loop with its reason on its own job;
a restore whose result is read long after it finished starts none. The job's
record names the survey it pages and its slice, and the survey's result holds
the names, so a store of ten thousand files is stored once, not once per page.

**The management node is not in that path at all** — not as a gate, and not as a
relay. The challenge and the answer live entirely between the node's own site and
its own agent, and the restore vocabulary declares no parameter through which an
approval answer could travel, so relaying one is impossible by wire format rather
than by care. A management node can dispatch a restore and can do nothing
whatsoever to get it approved.

The costs are deliberate and worth stating. Restoring in place requires the
node's site to be up; a node whose site will not boot is rebuilt and restored
(`install_mode = from_backup`), which needs no approval because there is no node
yet to ask. An unanswered challenge expires and the job is refused, so a restore
nobody is watching fails rather than pinning the node. And a support-driven
restore requires the customer to be reachable: there is no unattended
destructive path, including for us.

The archive's age is on the approval screen as an age, above the key box, because
it is the one fact no automatic check can substitute for — a replayed archive is
genuine, signed and openable, and only its date is wrong.

## Verifying backups

A backup that has never been opened is a hope. The shelf listing proves a backup
is *present*; `restore_chain.sh --dry-run` proves it is *intact* against its
manifest; Prepare proves the key this machine holds *opens* its envelope.
Verification is the step that proves a backup is *recoverable*, without
restoring it, and records the proof where people look: the site's own Backups
page, the node's Backups tab on its management node, and the node's card on the
management dashboard. The word every page uses is **verified restorable**, with
a date, and the level it was proven at:

| Level | Where it runs | What it does | What it proves | Cost |
|---|---|---|---|---|
| **Checked on the shelf** | The management node, on every retention listing | Every artifact each backup's manifest names is in the bucket listing at the recorded size, and the manifest carries its envelope. Every offloaded file the newest run's index (and each standalone full's index) marks stored is in the listing under `objects/` at its recorded encrypted size, and its epoch's envelope is there | Present and complete | The listing the pass already takes, plus one small read per backup and one per index. Seconds, no disk |
| **Opened and read** | The node (or the site itself), on a schedule | The set a restore of the run needs — the full, every incremental up to it, that run's database dump, metadata and offloaded-files index — is downloaded, checked against the manifest's sizes and hashes, decrypted with the machine's own key and read to the end: `tar -tz` for the archives, a full decompression and a header check for the dump. Every epoch envelope the index names is fetched and opened with the machine's own key; no request is made per offloaded file, because the shelf listing already proved each one present at its size | Decryptable and structurally sound with the key this machine holds, offloaded files included | Downloads the whole set once; disk equal to the set, freed at the end; a minute or three |
| **Rehearsed** | The node (or the site itself), only when a person asks | Opened and read, then the files are replayed into a scratch directory under the backup working area and the dump is loaded into a throwaway database on the machine's own PostgreSQL; what came back is counted (files, bytes, tables, rows in `usr_users` and the three largest tables) and both are deleted. A sample of the offloaded files — the 5 largest and 15 drawn at random — is downloaded, checked against the index's hash, decrypted with its epoch key and compared to the rehearsed database's row for it: `fbb_sha256` where the row records one, `fbb_size_bytes` otherwise | Recoverable | The set plus the sample plus roughly twice the site plus the database, freed at the end; a few minutes |

Nothing on the live site is touched at any level. The run verified is always the
newest: it is the one a restore would start from, and it exercises everything
beneath it.

**What no level proves, and how the ceremony closes it.** No level accepts a
private key — for the same reason `stage_chain.php` refuses one, a key on the
wire is a key in every job record — so none of them exercises the recovery
private key an operator holds. That path is proven by the recovery-key ceremony
on the Recovery Readiness page, which shows the private key opens a challenge
sealed the way every envelope is. The two together are the proof: the ceremony
shows the key opens an envelope, verification shows the envelope's contents are
sound. The Backups page shows the two dates side by side for that reason.

**The node's history row is the authority.** A verify stamps the run it opened
(`bkh_verify_time`, `bkh_verify_level` 2 or 3, `bkh_verify_outcome` pass or
fail, `bkh_verify_message` in plain words) whichever profile took the run. A
verify that was *skipped* — not enough disk, a throwaway database that could not
be created, a machine busy with a backup for too long — stamps the message
only, because nothing was proven either way: the last real outcome stands and
the skip's reason rides beside it. A request refused before anything was read
is noted the same way. The management node's copy
(`mgn_backup_verify_*`) is refreshed from the job's result lines and from the
node's status report, so a verify the site ran itself is seen from the
management node too.

**A failed verify is surfaced exactly like a failed backup**, in the node's own
words, and never triggers anything automatic — not a re-run, not a deletion,
not a fresh full. A failing verify is a person's problem to look at.

### The engine

`utils/verify_backup.php` is the script a node runs (as the `verify_backup`
agent primitive, or from the site's own scheduled task and Backups page). It
takes JSON on stdin and nothing on argv: the chain, the profile, the level, a
signed link to the manifest and a signed link to every object under the chain,
keyed by bare name, optionally the run, and — when the run carries offloaded
files — a signed link per epoch envelope the run's index names
(`epoch_envelope_urls`, keyed by epoch id) and, for a rehearsal, the sample
(`object_urls`, keyed by the object's name in the index, at most 20). Whoever
signs the links reads the run's index off the shelf to pick them: the site's
own launcher (`BackupVerifyLauncher::object_links()`) and the management
node's builder alike. Everything about *what may be fetched* is
`BackupStaging`'s, shared with Prepare, so a verify can never fetch
something a Prepare would refuse: the manifest is read on the machine, the
artifact list comes from `BackupChain::restore_plan()`, every fetch is checked
against the upload ledger, and the chain key is recovered from the machine's
own `config/backup_site_key`. Offloaded files are not ledgered — their index
is — so `BackupStaging::fetch_objects()` checks each sampled object against the
index's recorded size and hash instead, and lands it under
`objects/{epoch}/` in the working directory. `BackupVerifier` is the level 2
and 3 engine over an already-staged directory (`read_all`, `rehearse`,
`disk_needed`, `sample_objects`). A management node sends the two link maps
only to an agent at or past `JobCommandBuilder::VERIFY_BACKUP_OBJECTS_MIN_AGENT_VERSION`;
a run that carries offloaded files but whose request links no envelope for an
epoch they need fails by name, because the objects under it cannot be opened
here.

The script works in `{site}/backups/{profile}/verify-{pid}/`, under the same
two locks as a backup run (so it never reads a chain a run is writing), checks
free disk before downloading anything (the set for level 2; the set plus twice
the full's files archive plus three times the dump for level 3), and removes
its working directory, scratch tree and throwaway database on every exit path,
including a fatal. The local sweep removes any `verify-*` directory older than
a day that a killed process left behind.

It answers with one key per line:

```
VERIFY_RESULT=pass|fail|skipped
VERIFY_LEVEL=2|3
VERIFY_RUN=<chain_id>/<seq>
VERIFY_RUN_TIME=<manifest run time, UTC>
VERIFY_ARTIFACTS=<n read>
VERIFY_BYTES=<bytes read>
VERIFY_FILES=<entries listed (2) or files restored (3)>
VERIFY_OBJECTS=<offloaded files proven recoverable: stored, epoch envelope opened>
VERIFY_OBJECT_BYTES=<their bytes on the shelf>
VERIFY_OBJECTS_SAMPLED=<n opened and compared>     (level 3 only)
VERIFY_TABLES=<n>                            (level 3 only)
VERIFY_ROWS=usr_users:<n>,<table>:<n>,…      (level 3 only)
VERIFY_DURATION=<seconds>
VERIFY_REASON=<one line>                     (fail or skipped only)
VERIFY_NEEDS_BYTES=<n>                       (skipped for disk only)
VERIFY_FREE_BYTES=<n>                        (skipped for disk only)
```

Exit 0 on pass or skipped, 1 on fail, 2 on a request it could not understand. A
skip's reason is `disk` (with both numbers, so the card can say "needs N free,
has M"), `createdb`, or `busy`. An object retention deleted from under a verify
in flight fails with reason `gone`, naming the object — an archive, an epoch
envelope or a sampled offloaded file alike; the next pass verifies the newer
backup, and the retention pass never holds a deletion for a verify — a verify
must not be able to keep a backup alive.

### The site's own backups

A site verifies its own backups the same way: the **Backup verification**
scheduled task (`tasks/BackupVerify.php`, switched on together with **Backup**
by `BackupNightly`) opens and reads the site's newest own backup every
`backup_verify_every_days` (30 by default; 0 never), signing the links from
the site's own target through `S3Signer::presign_get` — no credential reaches
the script. It is due when the interval has passed since the last verify (pass
or fail) and a newer backup exists, or when nothing has ever been verified. A
site that takes no backups of its own has nothing to verify and the task says
so, once, as a skip. The Backups page's **Verify the newest backup** button
runs the same thing on demand, and **Rehearse a restore** runs level 3; both
run in the background like *Run a backup now* (the request crosses on the
detached process's stdin, handed over on an explicit descriptor —
`BackupVerifyLauncher::detach`), and the result lands on the run's row under
Recent backups and in the Status box's **Last verified restorable** line. A
verify that proves nothing either way — skipped for disk or a busy machine,
or refused before it read anything — leaves its reason as the run's message
with no outcome (`BackupVerifier::stamp_history` / `note_history`); the row
says **not verified** with the reason, or **since then:** beside a proof that
still stands, and the Status box says **Last attempt:** when it is about a
backup no older than the last proof.

`BackupVerifyLauncher` is the site side (`newest_run`, `due`, `request`, `run`,
`start`).

### The file store is checked too

Verification proves the shelf holds what the index says. The other side —
does the *file bucket* still hold every offloaded file it is serving? — is
asked daily by the offload tick (`CloudStoreInventory`, `docs/cloud_storage.md`
§ The file store is checked daily): every `cloud` blob is `HEAD`ed in its
bucket, a slice per tick, and a file the bucket lacks or holds at the wrong
size is written down by name. The Backups page's **Offloaded files** box and
the cloud-storage page's Status block both say when it last looked and, when
anything is missing, "N offloaded files are missing from the file store; the
backup holds M of them" — M counted against the enabled profiles' held sets —
with **Bring them back** beside it:

- **A site with a backup target of its own** runs it here, in the background
  (`BackupObjectRestoreLauncher::start_newest()` → `utils/bring_back_objects.php`,
  the request on stdin as a verify's is). The launcher reads the newest run's
  offloaded-files index off the site's own shelf, surveys it in `missing` mode
  (the bucket is `HEAD`ed per file, so a file the bucket has recovered by then
  is left alone), and brings the rest home a page at a time — each page's
  object and envelope links signed here with the site's own credential
  (`S3Signer::presign_get`) and opened with its own key; the engine is the
  same `BackupObjectRestore` the node path runs. One at a time, by a lock in
  the profile's `objects/` directory. What it did lands on the inventory
  record, both pages read it, and the names it brought home leave the missing
  list at once.
- **A site backed up only by its management node** holds no shelf credential,
  so the box names the management node and its URL: from that node's Backups
  tab, **Bring them back** (the `restore_objects` backup action →
  `FleetObjectRestore::start()`) runs the paged survey-and-page loop described
  under *Restoring a managed node*, in `missing` mode, against the newest run
  on the shelf that carries an offloaded-files index. The management node does
  not see the node's missing list; the survey job asks the node.
- **A site with neither** is told nothing holds them.

From a shell, the same run as the pages start:

```
php public_html/utils/bring_back_objects.php --chain chain-20260912_044520 --seq 3 [--mode missing|all]
```

### Verifying by hand, with the recovery key

`maintenance_scripts/sysadmin_tools/verify_backup.sh` drives the same engine
over a chain directory an operator downloaded by hand, with a key the operator
recovered. This is the **one verification path that exercises the recovery
private key**, and the runbook for proving that the key in a password manager
still opens a real backup:

```bash
# 1. Download the chain's whole directory from the bucket to DIR
#    (manifest.json and every artifact it names).
# 2. Recover the chain key with the recovery private key.
php backup_envelope.php open --sidecar DIR/manifest.json \
    --private /path/to/recovery.key --key-out /tmp/chain.key
# 3. Open and read (level 2), or rehearse a restore (level 3).
./verify_backup.sh --artifacts DIR --key-file /tmp/chain.key --level 2
./verify_backup.sh --artifacts DIR --key-file /tmp/chain.key --level 3 [--seq N] [--project NAME]
# 4. Shred the key file.
shred -u /tmp/chain.key
```

It runs from a site's `maintenance_scripts/sysadmin_tools` (the engine is PHP
in the `public_html` beside it, and a rehearsal uses `restore_chain.sh` and
`restore_database.sh` from the same directory), prints the same `VERIFY_*`
report, leaves the chain directory as it was (a rehearsal's `scratch/` is
removed), and exits 0 on pass or skipped, 1 on fail, 2 on a request it could
not understand. `--project` names the directory the archive carries, for a
rehearsal; left out it is read from the archive, and a wrong name is refused.
This path holds a chain key, not the site key, so it proves the archives and
reports the run's offloaded files unproven (`VERIFY_OBJECTS=0`); those are
proven by the fetching path, which opens their epoch envelopes with the site
key.

### Disk and egress

Opened-and-read downloads the whole set each time; on Backblaze that is egress.
At the monthly default on a 1 GB set it is inside the free allowance for a
fleet of nodes; weekly across nine nodes would still be under 40 GB a month.
A rehearsal needs scratch disk of roughly twice the site plus the database, and
a PostgreSQL role that can create a database. Both are checked before anything
is downloaded, and the machine says the numbers when it declines.

A **backup** needs no scratch disk beyond the site itself. Every archive and
every dump streams from its engine into the bucket; a chain run's peak disk is
the live tree plus the metadata artifact, a database-only run's is nothing,
and a standalone whole-site run's is the live tree plus its compressed dump
for the length of the tar. Memory is one upload part, 100 MiB. The
double-space need is a restore's and a verify's, not a backup's.

## The node tool

`maintenance_scripts/sysadmin_tools/backup_envelope.php` reads a backup's key
back. `open`, `relabel` and `site-key` are deliberately standalone — no platform
bootstrap — so they work during disaster recovery when the site will not boot,
which is the moment that matters. `mint` runs only on a live site and does read
its settings, because it must know which recovery key that site holds: nobody
hands it one, and `--recovery-pub` is refused.

| Command | What it does |
|---|---|
| `mint` | Mints a data key, seals it to this site's own verified recovery key, writes the key file and the envelope |
| `open` | Recovers the data key from an envelope or manifest, given a recovery or site key |
| `relabel` | Points an envelope at the archive it belongs to |
| `site-key` | Prints this site's public key, minting the keypair if absent |

`backup_files.sh` archives the file tree, incrementally when given a snapshot
path. `restore_chain.sh` applies a chain in order. Both take an explicit
directory override so the incremental and deletion-replay behaviour is tested
against a throwaway tree rather than a live site.

Three guards keep a files archive honest:

- **Elevation is a rule, not a credential.** An unprivileged run uses `sudo`
  only when `sudo -n -l` shows a `NOPASSWD: ALL` rule. An account holding one
  narrow rule can validate and still be refused the real command, and a refused
  `sudo tar` exits 1 with no output — the same status tar gives for a file that
  changed while being read. Listing the rules sends none of the mail an
  attempt does. `tests/backups/backup_files_sudo_gate.sh` holds every shape.
- **An archive of nothing is never a backup.** Fewer than 64 bytes is refused
  and deleted whatever tar's exit status said: an envelope around an empty
  stream is 32 bytes, and a gzipped tar holding even one entry is longer.
- **A full a tenth the size of the previous full is said out loud.** The run
  is kept — the archive is real — but its history row, the task message and
  the `BACKUP_WARNING` line a management node reads all carry the two sizes.
  The comparison is with the previous full only, so a site that really did
  shrink is flagged once and then measured against its new size
  (`BackupRunner::full_size_warning()`).

One file is left out of an unprivileged run on purpose, and announced: the
release signing key on a publishing box, `config/agent_signing_key`, is
readable by root only. The root-run manager backup of that box carries it. Any
other unreadable file fails the run rather than being skipped.

| Script | What it does |
|---|---|
| `reconcile_site.sh` | A site's shape, read both ways: `--print-shape` records it for a backup, the default mode makes a restored site agree with the machine it landed on |
| `verify_backup.sh` | Proves a downloaded chain restorable with a key the operator recovered — the one path that exercises the recovery private key; see [Verifying by hand](#verifying-by-hand-with-the-recovery-key) |
| `arm_ssl_retry.sh` | Arms (or disarms) the DNS-gated certificate retry for a domain |

`includes/BackupEnvelope.php` reads and writes the same format;
`tests/backups/backup_envelope_cli_test.php` holds both to that contract in
both directions, because drift there is silent and only surfaces at disaster
time.

## Scheduling

The **Backup** scheduled task (`tasks/BackupRun.php`) runs this site's own
backups — it is pinned to the site profile, so a management node's copies can never
be started by editing a row in this site's task table. It is not active
on install: a site with no target configured runs nothing and warns about
nothing. Activate it on **Scheduled Tasks**, where its frequency and time are
also set. It supports a dry run, which reports exactly what a real run would do
without producing or deleting anything. The **Backup verification** task
(`tasks/BackupVerify.php`) is switched on with it and proves the newest backup
restorable on its own interval — see [Verifying backups](#verifying-backups).

A run is recorded in `bkh_backup_history` before it starts and updated when it
finishes — including when it fails. Every row carries `bkh_profile` (whose backup
it was) and `bkh_recovery_fpr` (which private key opens it), so a restore never
has to infer from today's settings what was true when the archive was made. A site whose backups have been failing for a
month looks identical to a healthy one if only successes are written down.

**A failed site run is an admin notice.** From the first failure, every admin
page (superadmin) carries the notice (`includes/SiteBackupNotice.php`, a core
`AdminNotices` renderer): when the run failed, which target, and the engine's
last line (`bkh_message`, colour codes stripped, the last `|`-joined segment).
It reads the newest site-profile row; a run still recorded as running is not a
failure, and a manager-profile row never speaks for the site's own backup. The
next site-profile success clears it. A site that has never configured a target
has no row and hears nothing.

Because manager-profile rows land in the site's own database, the site can
answer "does someone back me up?" locally: `BackupHistory::manager_coverage()`
returns the newest manager-profile success that reached its bucket within
`MANAGER_COVERAGE_DAYS` (7), or null. The setup wizard's Backups step reads it
as a green condition, so a fleet-backed node is not asked to configure a bucket
it is already archived to; coverage goes stale on its own if the management
node's runs stop.

### A target an installer creates

A first-boot installer that was handed a bucket and its key pair (the Linode
deploy form's optional backup fields) creates the first target itself with
`utils/install_backup_target.php`: the target is saved, a Backblaze
credential's region and endpoint are filled from Backblaze's authorize answer
(`BackupTarget::complete_credentials`, the same call the Backups page makes),
the connection is tested, and a first target becomes the scheduled one. A
target whose test fails is removed again so the setup wizard asks for one. The
recovery key is never created here — it is shown once to a human — so nightly
runs still wait on the wizard's key ceremony, exactly as they do for a target
saved on the Backups page. Inputs are environment variables
(`JOINERY_BACKUP_BUCKET`, `JOINERY_BACKUP_KEY_ID`, `JOINERY_BACKUP_KEY`,
optional `JOINERY_BACKUP_PROVIDER` b2/s3/linode and `JOINERY_BACKUP_REGION`);
the first output line is `INSTALL_BACKUP_TARGET=ok` or `=error`.

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
| `backup_verify_every_days` | `30` | Days between verifications of the newest backup by opening and reading it; 0 never |
| `backup_retention_count` | `4` | Restore points (or chains) kept offsite |
| `backup_output_dir` | `/backups` | Working directory backups are built in |
| `backup_exclude` | — | Extra directory names to skip (build output, caches). A name matches a directory of that name at **any depth** — this is tar's exclude semantics, and it applies to the built-in skips (`vendor`, `cache`, `tmp`, `logs`, …) too |
| `backup_local_retention_days` | `7` | Days kept locally; 0 never sweeps |
| `backup_delete_local_after_upload` | on | Remove what a run left on disk — the metadata artifact, a standalone run's envelope sidecar — once uploaded; archives and dumps stream and are never on disk |
| `backup_path_slug` | project dir | Folder in the bucket this site files under |

## The account backups run as

The scheduled task runs as the web user, which is what `fix_permissions.sh`
makes the owner of the site tree. A backup that cannot read part of the tree
**fails** rather than shipping a partial archive, and says which file it could
not read — so a permissions problem surfaces as a failed run with a specific
cause, not as a backup that turns out to be incomplete on the day it is needed.
