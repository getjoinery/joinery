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
| Bucket credentials | stored on the machine | supplied per run, never stored; write-only via the target's node credential |
| Prunes the shelf | the site | the management node |
| Depends on | nothing | the management node being alive at the scheduled moment |

Both run the same engine, so chains, envelopes, deletion replay and history are
written once and behave identically for both.

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
{path_prefix}/{slug}/{profile}/{project}-{timestamp}.tar.gz.enc            the archive
{path_prefix}/{slug}/{profile}/{project}-{timestamp}.tar.gz.enc.keys.json  its envelope
```

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
  sealed to it has been retired.
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

### The upload ledger

Every artifact that reaches the bucket is also recorded on the machine that made
it, in `config/backup-ledger/{profile}.json`: the artifact's name relative to
its backup directory, its sha256, its size, and when it went up. `BackupLedger`
writes it, from `BackupRunner::upload()`, at the moment of upload.

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
  incrementals.
- **Local** — keep M days in `/backups` (default 7). The local copy is a
  convenience, not the archive: it lets a restore skip the download, and this
  window says how long that is worth the disk. Age is per file, so an old
  chain's early runs go while its recent runs stay, and the emptied chain
  directory is left for chain retention to retire. A chain's `manifest.json`
  and the snapshot beside it are never swept — they are what make the chain
  extendable, and without either the next run silently starts a fresh full. The
  sweep also removes the `auto_pre_*` snapshots a restore leaves behind, which
  are the size of a full backup. An archive and its envelope are always swept
  together. `0` means never. With **Delete the local copy once uploaded** on, a
  chain run removes its artifacts as soon as they are confirmed offsite instead
  of waiting out the window.

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
backups — it is pinned to the site profile, so a management node's copies can never
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
it is already archived to; coverage goes stale on its own if the management
node's runs stop.

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
