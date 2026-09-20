# Backups — Files That Live in the Cloud Store

**Status:** Unbuilt. Reviewed 2026-09-20; findings and decisions D1/D2 folded in.
**Date:** 2026-09-20

## For the executor — read this first

This section is the working brief. The design sections after it are the
reasons; the work packages at the end are the checklist. Do the packages in
order. Build `specs/backup_streaming_upload.md` **before** WP1 of this spec:
both edit `BackupRunner::execute_chain()`, the streaming one is smaller, and
the step ordering below is written against the streamed files engine.

**House rules that bind this work** (CLAUDE.md and project memory):

- **Never commit, never `git add`.** The index is shared with other sessions;
  the owner runs git.
- **No schema changes here.** This spec adds no column. The one settings
  change (WP0) is a declared default in `settings.json` plus a data migration
  in `migrations/`; the owner runs `php utils/update_database.php`. Ask before
  any database write on dev, naming the command.
- **Docs describe the current state only.** No "now", "previously", "replaces".
- **Bump the `@version` header** of every file you touch, one line saying what.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`
  — on class and function files only (`includes/`, `data/`, `logic/`, `tasks/`,
  plugin equivalents). The validator *includes* the file: never run it on
  `utils/*.php`, a view, or a script; those get `php -l` only.
- **Tests use the shared harness** (`tests/lib/harness.php`: `harness_boot()`,
  `section()`, `check()`, `harness_finish()`, `harness_defer()`,
  `harness_skip()`, `harness_scratch_dir()`, `harness_register_row()`,
  `harness_test_mode()`) and carry the `@joinery-test` header (`name`, `tier`,
  `env`, `needs`, optional `timeout`). Tiers: `safe` (no side effects), `db`
  (writes dev and self-cleans, registering every row), `test-db` (the copied
  test database, `harness_test_mode()` after boot). Working loop:
  `php tests/run.php --changed`; before handing back: `php tests/run.php db
  --changed`. Never run the runner as root. `harness_scratch_dir()` is shared
  across runs: unlink an output before a child writes it.
- **Vanilla HTML and JS** in the admin UI; forms through `$page->getFormWriter()`;
  an action is a POST button, never a link. Page JavaScript calls `/api/v1`
  with the session credential; nothing new under `/ajax/`.
- **Plain language on every page.** "Offloaded files on the shelf", "waiting
  for a backup", "still to copy from the file store". Never "epoch", "index",
  "held set" or "seq" where a person reads.
- **The Go agent is a separate checkout at `/home/user1/joinery-agent`**
  (`main.go` says `1.37.1` today). Edit it and run `go test ./...` there. Do
  NOT build, sign or publish an agent release, and do NOT run a platform
  publish: both are owner steps. A new primitive is a Go file in
  `primitives/` registered in `init()`, a row in `gate_test.go`'s
  `pinnedVocabulary`, and a row in `JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION`
  (a destructive primitive with no row fails closed — see
  `node_can_dispatch_destructive()`). Copy `operate_stage_chain.go` for the
  shape.
- **Never touch a live node**: no SSH, no settings writes on any node but dev.
  The live gate at the end is the owner's.
- **Secrets never appear in output.** The tick and the run print no key and no
  credential; the object store's index and result lines carry names, sizes and
  hashes only.
- **Identifiers**: report questions as Q1…, bugs found on the way as B1…,
  action items as A1….

**Decisions already made — do not reopen:**

- The manager profile never lists its shelf and never receives a read
  capability on any provider. It gets presigned links (§ Rollout). (D1)
- Restores fetch objects by presigned link, paged at ≤150 per job. No read
  key, on any provider. (D2)

**Brokered shelf — deltas from `services_phase2_platform.md` D3 (owner,
2026-09-20).** D1 and D2 get stronger, not weaker: the manager shelf is
becoming *brokered*, so a node holds **no** shelf credential of any kind —
not write-only either. Every write, including the object store's
`objects/{epoch}/…` puts, is a presigned PUT the plane signs inside the
node's own prefix; reads stay the presigned links this spec already uses.
That lands as phase 2 items 2a (the broker) and 2b (an object-store seam in
the engine) after or with `backup_streaming_upload.md`. What it means here:

- Where this spec says the manager run stores an object with
  `S3Signer::put_file()` under the credential it is handed, read: through
  the store the runner's destination gives it — the direct store today, the
  brokered store after 2b. Keep the object step's puts on that one path so
  the swap is a swap.
- The `manager` block's `credentials` field is the slot the per-run token
  takes over; the three fields this spec adds (`objects`,
  `objects_index_url`, `epoch_envelope_urls`) are unchanged and remain
  presigned links from the plane.
- "The node cannot list that shelf — the credential is write-only" becomes
  "the node does not list that shelf": the broker *could* answer a list, but
  the index-by-link design (D1) stands and is the cheaper, already-listed
  answer. Do not add a list call.
- A self-hosted site on the phase 2 `managed` target (its shelf is ours,
  brokered) runs the **site** profile, and its object store puts and lists
  go through the brokered store the same way; its own-bucket target is the
  direct store, untouched. The site profile's logic does not fork on that.
- Nothing in this spec's restore, verify, index or retention design changes.
- Objects are not ledgered; the index is. (§ The index)
- The plain index carries no plaintext hash, size or MIME type. (§ The index)
- One store budget covers local sources and catch-up alike. (§ The run)
- `backup_delete_local_after_upload` defaults on, with a migration. (§ Settings)
- The `restore_objects` agent primitive is **`ClassOperate`**. The agent's
  own definitions decide it (`primitives/registry.go`): destructive "destroys
  or replaces data"; operate "changes running state in recoverable ways". The
  object restore refuses to overwrite an existing local file and deletes
  nothing in any bucket, and the existing drain flow
  (`CloudOffloadEngine::reverseBatch()`) already pulls cloud bytes back to
  local and flips rows as an unattended scheduled task. No approval, so page
  jobs need none either. (§ Restore)

**Stop points** (hand back to the owner; do not work around):

1. Before `php utils/update_database.php` after WP0 (it runs the migration).
2. After WP2, for the agent version number that ships `restore_objects`; put
   it in `PRIMITIVE_MIN_AGENT_VERSION['restore_objects']`.
3. When everything is green, for the release and the live gate. Dev has cloud
   offload **off** and no joined management node (below), so the owner's live
   gate is the first end-to-end run of both profiles.

**Dev facts, verified 2026-09-20** (values read from the dev database; none
are credentials):

- Cloud offload is off (`cloud_storage_enabled` 0, private store 0). Blobs:
  1890 `local`, 1 `cloud` (a leftover). Every offload test runs against
  `tests/lib/cloud_fixtures.php` (`RecordingMockDriver`, `InMemoryBlobDriver`);
  neither has `head()` yet.
- `agent_join_state` is blank: dev is a management node, not a managed node.
  The manager-profile path is exercised unit-level (`plan_manager()` with a
  `manager` block) and through the plane-side builders; end to end it is the
  owner's live gate.
- The site backup target is id 3, Backblaze, enabled, minting off; mode
  `chain`, type `project`, full every 7 days; `backup_delete_local_after_upload`
  is 0 (WP0 flips it).
- `{site}/backups/` and every chain directory are `www-data:www-data` 2770,
  and the site-profile ledger is www-data-owned. A shell user cannot run a
  real site-profile run or verify on dev; the runner test drives
  `BackupRunner` against a scratch output directory and the local-provider
  fixture instead.

**Facts you will need, verified 2026-09-20** (line numbers are for
orientation; re-grep before editing):

- `CloudStorageLifecycle::runOffloadTick()` (372) walks
  `StorageProfileRegistry::all()` and calls `CloudOffloadEngine::syncBatch()`
  per profile in offload mode. `_sync_row()` (100–174) is push → reload →
  flip → unlink; the per-row advisory lock is `_lock()` /
  `ADVISORY_LOCK_NAMESPACE = -42` with the blob id as the second key.
  `BlobStorageProfile::itemsForRow()` enumerates original + variants;
  `reverseItemsForRow()` (92) is the placement a restore uses. The private
  profile is `BlobPrivateStorageProfile`, split by `fbb_is_private`.
- `FileBlob` (`data/file_blobs_class.php`): `fbb_stored_name` unique;
  `release()` (394) → `_reclaim()` (466) branches on driver; `resize()` (992)
  regenerates variants from a local original; `splitCopy()` (567) is the only
  path that gives bytes a new name (a new blob). `filesystem_path($size_key)`
  and `remote_key_for($size_key)` name local and bucket paths.
- `CloudStorageDriver` (interface, 71 lines): `put`, `get`, `get_range`,
  `delete`, `url`, `ping`; `CloudStorageS3Driver::putMany()` is a capability
  probed with `method_exists`. Add `head()` to the interface, the S3 driver
  (`headObject`) and both fixtures.
- `BackupRunner`: `plan_site()` 299, `plan_manager()` 384 (reads
  `$config['manager']` keys `bucket credentials delete_local_after_upload
  full_interval_days keep_local_days mode path_prefix provider slug
  target_name type`), `execute_chain()` 616, `run_files_engine()` 848 (builds
  the `backup_files.sh` command; `--exclude` per name today),
  `discard_failed_run()` 812 (the only user of `BackupChain::KINDS`),
  `execute_full()` 1091, `upload()` 1209 (records each artifact with
  `BackupLedger::record()`), `enforce_chain_retention()` 1029,
  `enforce_cloud_retention()` 1285 (history-driven), `sweep_local()` 1385.
  Two locks: the profile lock and the machine-wide mutex (`take_locks()`).
- `BackupChain`: `KINDS = ['files','db','meta']` (56); `add_run()` (105)
  stores any kind as `{name, bytes, sha256}`; `restore_plan()` (196) returns
  `files[]`, `db`, `meta` explicitly — add `objects`; `verify_artifact()`
  (236) is the size-then-hash check to reuse for objects;
  `should_start_new()` (144) holds the `recovery_rotated` test;
  `object_keys()` (325) lists a chain's keys for deletion — objects are
  deliberately not among them.
- `BackupEnvelope`: `mint($artifact_name, $recipients)` (61) → `{data_key,
  envelope}`; `build($data_key, $artifact_name, $recipients)` (95) re-seals an
  existing key; `open_as_site()` (279); `recipients()` (128); sidecar
  helpers. The epoch envelope is this structure with `artifact` = the epoch
  id.
- `BackupLedger`: `record($profile, $relname, $path, $object_key)` (149),
  `MAX_ENTRIES = 5000` (107), `verify()` (231). Index only.
- `BackupStaging`: `wanted()` (231) lists names from the plan;
  `fetch_artifacts()` (301) fetches each through
  `BackupFetch::fetch_artifact($profile, $work, $relname, $name, $url)`,
  ledger-checked, 0600, size-capped — the model for fetching objects by
  link, except that objects are checked against the index, not the ledger.
- `BackupVerifier`: `read_all()` (169) walks `files`/`db`/`meta`;
  `rehearse()` (254) has a throwaway database to read `fbb_sha256` from;
  `format_contract()` / `parse_contract()` (395/429) carry the `VERIFY_*`
  lines. `BackupVerifyLauncher::request()` (127) lists the chain prefix and
  presigns per artifact for the site profile.
- Plane (`plugins/server_manager/includes/`): `FleetBackupRun` task calls
  `FleetBackupRetention::prune()` (176) and gets the listing back in
  `objects`, then `check_shelf()` (203), then `JobCommandBuilder::build_backup_run()`
  (227) — the three new request fields are built between those two calls from
  that listing. `backup_run_config()` (JobCommandBuilder ~970) is the
  `manager` block. `FleetBackupRetention::group()` (161), `check_shelf()`
  (274, ignores anything not `chain-*/name`), `compare_manifest()` (331).
  `JobResultProcessor::process_backup_run()` (871) reads result lines by
  regex and stamps `mgn_` columns. `sign_chain_links()` (2219) and
  `build_download_backup_primitive()` (2069) are the presigned-link
  precedents; `signed_link_seconds()` (2356) sizes expiry to the claim
  budget. The plane has **no** follow-up-job loop today: a result is
  processed and stored, never used to issue the next job. The paged object
  restore adds one (WP4), driven from the index the plane reads.
- Agent primitives, model files: `operate_stage_chain.go` (links keyed by bare
  name, key recovered on the node), `operate_download_backup.go`,
  `destructive_restore_chain.go` (approval window, `ClassDestructive`).
  Scripts run from `public_html/utils/` and must be in the signed release
  manifest; `utils/stage_chain.php`, `utils/download_backup.php` and
  `utils/verify_backup.php` are the models for `utils/restore_objects.php`
  (JSON on stdin, `RESTORE_OBJECTS_*` result lines, exit codes 0/1/2).
- Scripts: `backup_files.sh` argument loop (84–100), `ARCHIVE` (118),
  tar (210–217), report lines (262–266). `restore_chain.sh`: database restore
  at 409–422, reconcile from 424; the objects step goes between them.
- Notices: `includes/AdminNotices.php` (`register(string $name, callable
  $renderer)`, `render()`, `resetForTests()`); `includes/SiteBackupNotice.php`
  (1.0) is the model — `render()` reads one stored fact and returns HTML or
  `''`, with its own small CSS. The file-store key is the
  `cloud_storage_access_key` setting; the target's is in
  `BackupTarget::get_credentials()['access_key']`.
- Admin: `adm/admin_backups.php` + `adm/logic/admin_backups_logic.php` (1.8,
  `$milestones`), `adm/admin_cloud_storage.php` (1.3) +
  `adm/logic/admin_cloud_storage_logic.php` (2.2),
  `plugins/server_manager/includes/node_detail_tabs/backups.php` (1.10),
  `includes/RecoveryReadiness.php` + `adm/admin_recovery_readiness.php`.
- Tests to copy from: `tests/backups/backup_runner_test.php` (pure rules),
  `tests/backups/backup_chain_gate.sh` (real tar/openssl),
  `tests/backups/s3signer_multipart_test.php` (`s3mp_start_fixture()`,
  `s3mp_stop_fixture()`, `s3mp_fixture_assembled()`; keep parts under 1 KB so
  curl sends no `Expect: 100-continue`), `tests/backups/backup_verify_test.php`,
  `tests/backups/backup_ledger_test.php`.

---

## What this is

A site can move its uploaded files (photos, gallery and blog images) off the
server into a bucket the customer owns — cloud offload. Once a file is offloaded
its bytes exist in exactly one place: that bucket. **The backup does not contain
it.** A restore of such a site works only for as long as the customer's bucket
still holds every object; a closed account, a deleted bucket, a revoked key or an
`aws s3 rm` loses every offloaded file with no recovery, and the backup that
reports itself complete restores a site of broken image links.

This spec makes every offloaded file part of the backup, **without ever
downloading it to do so**, and **without holding it on the node any longer than
today**. Each file is copied into the backup once, while it is still on the
server's disk, and never moves again.

## The gap, precisely

- Offload pushes a blob to the file store, flips the row to
  `fbb_storage_driver='cloud'`, then deletes the local bytes
  (`includes/cloud_storage/CloudOffloadEngine.php:158-171`).
- The backup archives the local tree with tar
  (`maintenance_scripts/sysadmin_tools/backup_files.sh:210`). Nothing in
  `BackupRunner`, the chain manifest, retention, verification or restore knows
  the cloud store exists. Neither `docs/backups.md` nor `docs/cloud_storage.md`
  mentions the other.
- The backup carries the blob rows and the bucket credentials
  (`cloud_storage_secret_key` is an ordinary `stg_settings` row; the `secret`
  flag only keeps it off the settings page), so a restored site points at the
  bucket and serves from it. Nothing else.
- Every verification level reports green: everything it knows to look for is
  present.

## How it works, by example

A member uploads `beach.jpg` (4 MB) on Tuesday at 2:07 pm. The site has its own
backup target and is also backed up by a management node.

1. **2:07 pm — upload.** Lands on disk as today, blob row `driver=local`.
2. **2:15 pm — offload tick.** The engine pushes the original and its variants
   to the customer's file bucket and flips the row to `driver=cloud` — as today.
   Then, in the same tick, it encrypts the original with the site profile's
   current epoch key and puts it on the site's backup shelf as
   `objects/{epoch}/beach.jpg.enc`. The site shelf now holds it. The manager
   shelf does not — the node holds no credential for that shelf between runs —
   so the local bytes stay. A site with no management node releases them here,
   and its disk is exactly as it is today.
3. **3:00 am — the nightly site run.** Lists its `objects/` prefix on the
   shelf, stores anything the listing lacks, writes the **object index** —
   every `cloud` blob, with whether it is stored — as an artifact of the run,
   and commits. The tar is built with every `cloud` blob's local paths
   **excluded**: the index accounts for them, so they never enter an archive.
   The run leaves its listing on disk as the site profile's held set.
4. **The manager run** (whenever the management node dispatches it) does the
   same against the manager shelf under the credential it is handed. It
   cannot list that shelf — the credential is write-only — so the request
   carries a link to the newest manager index instead, and that index plus the
   node's own held set is its picture of the shelf. It stores what is
   missing, indexes, commits, leaves its held set. Then it releases the local
   bytes of every `cloud` blob that **both** held sets name. `beach.jpg`
   leaves the disk here.
5. **Six months later — restore to a new server.** The chain replays, the
   database loads. `beach.jpg`'s row says `cloud`, the credentials came back
   with the settings, and the file bucket serves it. **Only if the file bucket
   cannot serve it** does the restore pull the object from the shelf, decrypt
   it, put it on local disk and flip the row to `local`. The site is whole
   either way.

`beach.jpg` left the server three times, once to each bucket, all from the one
local copy. It has never been downloaded to make a backup and never will be.

## Principles

- **Local bytes are released only once every enabled shelf holds the object.**
  A site with no backup configured releases at offload, as today. A site with
  only its own target releases at offload, as today — the tick stores to that
  shelf before it unlinks. A site a management node backs up holds bytes until
  that node's next run. A site whose backups have been failing for a week keeps
  the bytes on disk, and the cloud-storage page says so. Bytes are never in
  only one place because a backup was late.
- **Nothing waits on the node that a shelf already holds.** What the node
  holds at any moment is: uploads since the slowest enabled profile's last
  successful run, plus one object's ciphertext while it is being uploaded.
  Never a second copy of the store, never a tar containing what the shelf has.
- **What is stored is read from the shelf, never from a node-side record
  alone.** The site profile lists its `objects/` prefix with its own
  credential. The manager profile is handed the newest index on its shelf by
  the management node, which lists that shelf anyway before every dispatch.
  Either way a new server restored from this backup learns what its shelf
  holds and re-copies nothing; a lost local record costs at most one run's
  worth of re-stores, never the store.
- **No node reads a shelf with a credential.** The write-only manager
  credential stays write-only on every provider until the brokered shelf
  removes it altogether (the D3 block above); what a node needs to read
  arrives as a presigned link, as every restore does today. Nothing here adds
  a capability to a stored or minted key, so nothing here is provider-specific
  beyond the Backblaze minting that already exists.
- **One copy per object, ever.** Objects are immutable and named by
  `fbb_stored_name`, which is unique and never changes for the life of a blob
  (a visibility flip makes a **new** blob under a new name — `splitCopy()` —
  and releases the old). The store is outside any chain, so a new chain, a new
  full, a rotated key or a pruned chain never re-copies anything.
- **Originals only.** Variants are derived; `FileBlob::resize()` remakes them
  from the original on demand. Backing them up would double the storage for
  nothing.
- **A run's index says what was live.** Restore point N restores the tar chain
  to N plus the objects index N names. Retention keeps an object while any
  retained run names it.
- **Both profiles, both shapes.** The site profile and the manager profile each
  keep their own object store on their own shelf; chains and full-every-time
  runs both carry an index. A **database-only** backup type carries no files
  and its profile is not enabled for objects.
- **Bandwidth is the server's uplink, once per shelf.** No shelf is ever read
  to make a backup. The only download this design ever performs is the
  one-time catch-up for files offloaded before it shipped (§ Catch-up).

## Node disk

The nodes this matters most on have 25 GB. Every step is bounded:

| Step | Extra disk held | For how long |
|---|---|---|
| Offload tick, site shelf store | one object's ciphertext | the one upload |
| Waiting for the manager run | uploads since the last successful manager run | until that run |
| Run store step | one object's ciphertext | per object |
| Catch-up | one object's plaintext + ciphertext | per object |
| Tar | unchanged from today, **less** every `cloud` blob | as today |

The object step encrypts **one object at a time** to
`{profile}/objects/tmp/{name}.enc`, uploads it, unlinks it. Catch-up downloads
one object, encrypts, uploads, deletes both. The store budget (§ The run) is
bytes transferred, not bytes held. A budget or an interrupt leaves at most one
object's temporaries, which `sweep_local()` removes.

The tar excludes every `cloud` blob's local paths — original and variants,
`static_files/uploads/{name}` and `static_files/uploads/{size}/{name}` and the
restricted equivalents — through a new `--exclude-from FILE` on
`backup_files.sh`, written by the runner before the files engine from the same
query that builds the index. Bytes waiting for a shelf are therefore in no
archive, which is correct: the index names them and the store step is what
makes them safe. Without this, every incremental would carry a day of photos
that the object store already holds, and the chain would keep them for its
life.

**Exclusion and incremental replay.** A file an earlier archive held, then
offloaded and excluded, drops out of the next incremental's directory listing,
so a chain restore replays it as a deletion. That is correct and must not be
"fixed": the row says `cloud`, the index names the object, and the object
restore (§ Restore) runs **after** the replay and puts the file back only
where the file bucket cannot serve it.

Streaming the tar to the bucket without landing it on disk is
`backup_streaming_upload.md`; it changes the files engine and the ledger's
hash-from-disk, not anything here.

## Layout in the bucket

```
{prefix}/{slug}/{profile}/
    chain-{id}/
        manifest.json
        files-0003.tar.gz.enc
        db-0003.sql.gz.enc
        meta-0003.tar.gz.enc
        objects-0003.json.gz          the index — plain, like the manifest
    {slug}-{stamp}.tar.gz.enc         a standalone full …
    {slug}-{stamp}.tar.gz.enc.keys.json
    {slug}-{stamp}.objects.json.gz    … and its index, same stamp
    objects/
        {epoch}/
            envelope.json             the epoch's sealed data key
            {fbb_stored_name}.enc     one object per offloaded blob
```

A standalone index carries its archive's timestamp so `BackupNaming` and
`FleetBackupRetention::group()` file it with the archive and its sidecar, and
`enforce_cloud_retention()` deletes it with its run.

On the node, per profile, beside the chain directories:

```
{profile output dir}/objects/
    epoch.json          the current epoch id and its envelope (a copy of the shelf's)
    held.json           object names this profile's shelf held as of its last run
    enabled             manager profile only — written by the first run whose
                        request carried objects (§ Enabled profiles)
    tmp/                one object's ciphertext during an upload
```

`held.json` is a cache, rewritten whole by every run of its profile and
consulted only by the release rule. It is not the upload ledger and has no
entry cap. Losing it costs nothing on the site profile (the next run lists)
and at most one run's worth of re-stores on the manager profile (§ The run).

The objects directory is created `2775`, group `www-data`: the tick runs as
the web user, a scheduled run as the web user, a shell run as the deploy
account, and all three write here. The site key it reads is group-readable
already.

### The index

**Plain gzipped JSON**, not encrypted, for the same reason the manifest is:
the management node prunes the manager shelf and cannot open a chain key. It
holds **only what a shelf listing already shows**:

```json
{"version": 1, "profile": "site", "run": "chain-20260920_030000/3",
 "created": "2026-09-20T03:04:11Z",
 "epochs": ["epoch-20260901_000000"],
 "objects": [
   {"name": "beach.jpg", "epoch": "epoch-20260901_000000",
    "object_bytes": 4194352, "object_sha256": "…", "stored": true}
 ]}
```

`object_bytes` and `object_sha256` are the **encrypted** object's; `stored`
says whether it was on the shelf when the index was written. No plaintext
size, hash or MIME type: the private store offloads private blobs too
(`BlobPrivateStorageProfile`), and a plain file the management node reads must
not carry a fingerprint of a private file's content. Everything a restore needs
about the plaintext is in the blob row, which is restored first. Twenty
gigabytes of photos is ~10k entries, ~150 kB gzipped.

The index is a manifest artifact of kind `objects` — added to
`BackupChain::KINDS`, returned by `restore_plan()`, wanted by
`BackupStaging::wanted()`, read by `BackupVerifier::read_all()` — and is
recorded in the upload ledger like any other artifact. **Objects are not
ledgered.** The ledger is capped at 5000 entries and evicts the oldest
(`BackupLedger::MAX_ENTRIES`), lives on the machine rather than in the backup,
and is rewritten whole per record. An object's integrity chain is: the
manifest is ledgered → it names the index and its hash → the index names each
object and its hash. A fetched object is verified against the index before it
is decrypted, exactly as every artifact is verified against the manifest.

## Key model: epochs

Objects live for years; chains live for weeks. A chain's single data key sealed
at chain start does not fit, and one envelope per object is 10k envelopes. An
**epoch** is one data key with one envelope at `objects/{epoch}/envelope.json`
— the ordinary `BackupEnvelope` structure with `artifact` set to the epoch id,
`epoch-YYYYMMDD_HHMMSS` — sealed to the same two recipients as every chain
(recovery + site) by `BackupEnvelope::mint()`.

Objects are encrypted with the epoch key in the same stock format as archives
(`aes-256-cbc-pbkdf2`: the `Salted__` header, PBKDF2-SHA256 at openssl's
default iteration count), so the *Opening a backup with no Joinery anywhere*
procedure in `docs/backups.md` opens an object unchanged: unseal the epoch
envelope, decrypt the object. The format is produced **in PHP**, not by
shelling to `openssl` ten thousand times; a test proves the `openssl enc -d`
command line opens what PHP wrote, and PHP opens what `openssl enc` wrote.

The current epoch is decided by one function, `BackupObjects::epoch($profile)`,
called by the offload tick and by the run. A new epoch starts when: there is
no `epoch.json`; the recovery recipient changed (the same `recovery_rotated`
test `BackupChain::should_start_new()` applies); or the site key cannot open
the current envelope (site key lost or re-minted — degrade, never fail every
run). New objects go to the current epoch only. The tick opens the epoch
envelope with the site key on each use, the way a run opens a chain envelope;
no plaintext key is cached.

**On recovery-key rotation the old epochs are re-sealed**, not re-encrypted:
where the site key opens an old epoch's envelope, the run appends the new
recovery recipient to it (`BackupEnvelope::build()` re-seals an existing data
key to a recipient set) and uploads the envelope again under the same name. The
new key then opens everything; the old key still opens what it always did, as
with chains. The site profile reads old envelopes with its own credential; the
manager run reads them through the envelope links in its request (§ Rollout).
An old epoch the site key cannot open is reported on Recovery Readiness — "N
objects (X GB) open only with a retired recovery key" — with no automatic
re-copy.

## Enabled profiles

"Enabled" is the fact the release rule and the tick act on, so it is defined:

- **Site profile:** exactly the conditions `BackupRunner::plan_site()` refuses
  without — a `backup_target_id` naming an enabled, undeleted target, and a
  proven recovery key — **and** `backup_type` is not `database`.
- **Manager profile:** `agent_join_state` says this node has joined a
  management node, **and** `{manager output dir}/objects/enabled` exists. The
  first manager run whose request carries `objects` writes it; leaving the
  management node removes it. Until the marker exists the manager profile does
  not hold bytes, so a node whose management node has not yet been upgraded
  behaves as today instead of holding uploads for a run that will never store
  them.

`BackupProfile::enabled(): array` returns the enabled names and is the only
place either test lives.

## The offload tick

`CloudOffloadEngine::_sync_row()` after the flip to `cloud`, in place of the
unconditional unlink:

1. If the site profile is enabled: `BackupObjects::store($profile, $blob)` —
   encrypt the original with the site epoch key, `S3Signer::put_file()` it to
   `objects/{epoch}/{name}.enc` on the site target, unlink the ciphertext. A
   failure leaves the row `cloud` with its bytes; the next site run's listing
   will show the object missing and store it. Nothing is retried in the tick.
2. Release: unlink original and variants if every enabled profile holds the
   object — the site profile by the store that just succeeded (or by
   `held.json` if the store was skipped), the manager profile by its
   `held.json`. With no profile enabled this is the unconditional unlink of
   today.

The tick already holds the engine's per-row advisory lock for the row it is
pushing. **The run's store step takes the same lock per object**
(`CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE`, the blob id), so the tick and
a run never store one blob at the same time. Without that, two encryptions of
one file — salted differently — could both be uploaded, the later PUT winning
on the shelf while the index recorded the earlier hash, and every verify of
that object would fail.

The tick does not take the machine-wide backup lock: it archives nothing.

Uploads go through `S3Signer`, the backup upload stack (retry, multipart,
transfer budget), under the target's credentials. Not the file-store driver:
that is the AWS SDK under the file bucket's credentials, and the two must not
be mixed.

## The run

In `BackupRunner::execute_chain()` (and `execute_full()`):

1. **Epoch.** `BackupObjects::epoch()` per the rules above. Key file beside the
   run key, shredded with it.
2. **What the shelf holds.** Site profile: `S3Signer::list()` of its
   `objects/` prefix. Manager profile: the newest manager index, fetched
   through the presigned `objects_index_url` in the run request, union
   `held.json`; a request with no index link (an empty shelf, or none
   committed yet) means nothing is held. Entries the index marks `stored`
   count as held.
3. **Exclude list.** Query every blob with `fbb_storage_driver='cloud'` (the
   `BlobStorageProfile` is the enumerator; a second offload consumer supplies
   its own); write their local paths to the exclude file for the files engine.
4. **Store.** For every `cloud` blob not held: one at a time, under the
   per-row lock, encrypt the original with the epoch key, upload, unlink the
   ciphertext. Local originals first; then blobs with no local bytes, from the
   file bucket (§ Catch-up). **One budget covers the whole step: 2 GB or 20
   minutes** (constants on the runner, not settings). What the budget leaves
   is indexed `stored: false` and taken next run. Without a bound, a manager
   run after a week of failed runs, or a first run on a big site, has no
   ceiling inside a nightly task that has one.
5. **Files and database engines**, as today, with the exclude file.
6. **Index.** Write it from the query in 3, what was held in 2, and what 4
   stored.
7. **Commit.** Manifest gains the run with the `objects` artifact; upload as
   today. A failure anywhere in 1–6 fails the run under the existing
   discard-and-restart rule. Objects already uploaded stay: they are
   content-addressed, correct, and the next run finds them held (site) or,
   on the manager shelf, stores them again — bounded to one run's worth,
   and the second copy's hash is the one the next index records.
8. **Held set.** Write `held.json` from what was held plus what this run
   stored.
9. **Release.** For every `cloud` blob with local bytes: if every enabled
   profile's `held.json` names its object, unlink original and variants.

The store step runs **before** the tar so that at commit the shelf holds
everything the tar excludes.

**The manager profile** runs the same code under the write-only credential the
management node hands it per run; the object store is on the manager shelf.
The management node's listing is what the hosted tier's storage allowance
measures (`FleetBackupRetention`), so offloaded files count against the
allowance — as they did before offload, when the same bytes went into every
full tar. The shelf is the size it would be had the site never offloaded;
offload moves the serving copy, not the backup copy.

**Permanent delete of a waiting blob.** `FileBlob::_reclaim()` branches on the
driver and, for a `cloud` row, deletes only bucket bytes. A `cloud` row can now
hold local bytes, so the reclaim of a `cloud` row deletes bucket bytes **and**
unlinks the local paths. A blob permanently deleted while awaiting release
leaves nothing behind.

## Rollout, and what the manager run request carries

The management node must understand the `objects/` prefix before any node
writes to the manager shelf. Today `FleetBackupRetention::group()` files an
unknown top-level directory as a restore point with no timestamp, which sorts
oldest and is **deleted first** once the shelf exceeds the keep count: a node
upgraded ahead of its management node would lose its whole store at the next
prune. So:

- `group()` skips the `objects/` prefix; the object family is pruned by its
  own rule (§ Retention).
- The run request's `manager` block (built by `JobCommandBuilder`, read by
  `BackupRunner::plan_manager()`) gains three fields, present only from a
  management node running this code:
  - `objects: true` — the node stores to the manager shelf, and counts the
    manager profile as enabled, only when this is present.
  - `objects_index_url` — a presigned GET for the newest manager index on the
    shelf, or absent when there is none. The management node already lists
    the shelf immediately before every dispatch (`FleetBackupRetention::prune()`)
    and already signs per-object links for reads; this is one more.
  - `epoch_envelope_urls` — `{epoch id: presigned GET}` for every
    `objects/*/envelope.json` in that listing; what the re-seal on rotation
    reads. A handful, tiny.

  All three fit the 64 KiB plane-to-node job body with room to spare.

WP2 ships in the same release as WP1; the flag is what makes the order between
a node and its management node not matter.

## Catch-up: files offloaded before this ships

A `cloud` blob with no local bytes and not held is downloaded from the file
bucket (`CloudStorageDriver::get()`, the driver for the blob's visibility),
encrypted, uploaded, and both temporaries deleted — once, one object at a
time, inside the store budget above. The index still names every cloud blob,
so a restore point taken mid-catch-up is honest about what is on the shelf.
The Backups page shows "N files (X GB) still to copy from the file store"
until it reaches zero.

Cost for the user in the conversation that produced this spec: 20 GB, once.
Backblaze gives free egress to 3× stored; Linode includes 1 TB outbound a
month. Neither charges for it. A 1 TB catch-up on Linode spreads over two months
by the per-run budget alone.

## Retention

Objects are a third retention family beside chains and standalone fulls, pruned
by whoever prunes that shelf, each by that party's existing doctrine:

- **Manager shelf** — `FleetBackupRetention::prune()`, from the listing it
  already takes. An object is deleted when **no retained run's index names it**
  and its `LastModified` is older than the newest retained run's start — an
  object uploaded after the newest run has not had a run to be indexed by.
  Retained runs are every run of every retained chain plus every retained
  standalone full. The newest index of each retained chain is read first; only
  an object absent from all of those costs a read of the older indexes.
- **Site shelf** — `BackupRunner::enforce_object_retention()`, driven by this
  site's own records, as the site's other retention is (a listing could sweep
  another site's objects if two sites were ever pointed at one slug). When a
  chain or standalone full is pruned, every object its indexes name that no
  retained index names is deleted. An object uploaded by a run that then
  failed, whose blob was deleted before the next run, is never named by any
  index and is never deleted; the leak is one object per such coincidence and
  is accepted.

An empty epoch directory's envelope is deleted with its last object. Retention
runs last, after upload is confirmed, as today; a failed run prunes nothing.

## Verification

| Level | Addition | Where, for the manager profile |
|---|---|---|
| **Checked on the shelf** | The listing of `objects/` (one request per 1000 objects) shows every object the newest run's index marks `stored`, at its recorded encrypted size; the epoch envelopes the index names are present | Management node, in `check_shelf()`, from the listing it already has |
| **Opened and read** | The index is fetched and hashed like any artifact; every epoch envelope the index names opens with the site key. No per-object request: the listing already carries key, size and ETag, and a HEAD per object would be ten thousand requests to learn the same thing | Node; the launcher signs the index and envelope links as it signs every artifact today |
| **Rehearsed** | Plus a sample — the 5 largest and 15 random objects — downloaded, checked against the index's `object_sha256`, decrypted, and compared to the rehearsal database's row: `fbb_sha256` where the row records one, `fbb_size_bytes` otherwise | Node; the launcher picks the sample from the index it read and signs those twenty links |

The site profile does all three on the node with its own credential.
`VERIFY_OBJECTS=<n checked>` and `VERIFY_OBJECT_BYTES=<n>` join the result
lines. A missing object fails the verify with reason `gone`, naming it.

**The file store is checked too.** The offload tick runs a daily inventory:
every `cloud` blob is `HEAD`ed in the file bucket through a new
`CloudStorageDriver::head(string $remote_key): ?array` (size and ETag, or
null for absent) on the interface, the S3 driver and the test fixture. A miss
is surfaced on the cloud-storage page and the Backups page — "N offloaded
files are missing from the file store; the backup holds M of them" — with an
action **Bring them back** that runs the object restore in `missing` mode
against the newest run. On a site with its own target it runs on the node. On
a manager-only site it is a management-node Restore job (§ Restore), and the
button says so. Today a missing object is invisible until a visitor gets a
404.

## Restore

`utils/restore_objects.php` runs **after the database is loaded**, fed a run's
index, the epoch key files, and a source for objects — a local `objects/`
tree, or a map of name → presigned link:

- For each index entry: if the blob row is `cloud` and the file bucket `HEAD`s
  the object, do nothing. Otherwise fetch the object, verify it against the
  index's `object_sha256`, decrypt it into the placement
  `reverseItemsForRow()` already computes, set the row to `local`, and leave
  variants to on-demand `resize()`. MIME type and size come from the row.
- `--mode missing` (default) touches only what the file bucket cannot serve;
  `--mode all` brings every file home — a site leaving its bucket.
- `--dry-run` reports how many objects would be brought home and from which
  epochs.
- A restored site's first run finds every object held; nothing is re-copied.

**Site profile.** `restore_chain.sh --objects DIR` hands the downloaded tree
over; the node fetched it with its own credential.

**Manager profile — presigned links, paged.** A node never receives a bucket
credential for a read, on any provider; it receives signatures, one per object,
as it does for every artifact today. A presigned link is ~350 bytes and the
plane-to-node job body is 64 KiB, so the management node hands links over in
**pages of at most 150**, one job per page, driving the loop itself:

- `missing` mode: the first job carries the index link; the node HEADs the
  file bucket and reports the names it cannot serve (names only, ~40 bytes
  each, inside the 256 KiB node-to-plane cap, paged if ever not). The plane
  signs those and issues the page jobs.
- `all` mode: the plane walks the index it can read and issues one page job
  per 150 objects. A site with ten thousand objects leaving its bucket is
  around seventy small background jobs.

The node side of every page is a new agent primitive, `restore_objects`
(script `utils/restore_objects.php`, `ClassOperate` — it overwrites nothing
and deletes nothing, exactly as the drain flow it mirrors), taking the mode,
an index link on the first job, and a link map on every page job. The
dashboard's Restore job gains the paged loop as its final step; `BackupStaging`
learns the `objects/` prefix so a verify or a Prepare can fetch an object and
nothing else new.

## Admin surfaces

- **Backups page** (site) and **Backups tab** (node): "Offloaded files on the
  shelf: N objects, X GB; last indexed at run …"; catch-up remaining; "M files
  (X GB) waiting for the management node's backup before their local copy is
  released". The waiting figure is every `cloud` row whose original still
  exists on disk — one `stat` per row on page load, milliseconds at ten
  thousand rows; no column, no cache.
- **Cloud storage page:** the waiting-for-backup count and size, and the
  inventory result.
- **Recovery Readiness:** epochs openable only by a retired key.
- **Management dashboard node card:** shelf size already includes objects.
- **Same account, said plainly.** When the site's backup target and the file
  store share an access key (`cloud_storage_access_key` equals the target's),
  the Backups page and the cloud-storage page both say: "Your backup shelf and
  your file store are on the same account. Losing that account loses both. A
  copy taken by a management node is the one that survives it." The object
  store protects against a deleted bucket, a revoked key and an accidental
  `rm`; only a shelf on another account protects against the account itself.
  The same-key test is the whole rule — accounts are not detectable, keys
  are.
- **An admin notice, not only a page.** A count on a page is read by nobody.
  `BackupObjectsNotice` registers with `AdminNotices::register()` beside
  `SiteBackupNotice` and renders on every admin page when either holds:
  waiting bytes exceed **2 GB**, or an enabled profile's newest successful run
  is older than **7 days** while anything waits. Constants on the class, not
  settings. Text: "N files (X GB) are waiting for the management node's
  backup, which last succeeded D days ago. They stay on this server until it
  does." — or "…waiting for this site's backup…" for the site profile. It
  clears itself when nothing waits.

## Does this work with incremental backups?

Yes, and it is the reason the store sits outside the chains. An incremental
chain restores by replaying tar archives in order; objects are in no tar — the
exclude list keeps them out even while they wait on disk. Each run's index is a
complete statement of which objects were live at that run, so restoring seq 7
of a chain replays files 0–7 and consults index 7 — nothing about the
incremental mechanism changes. A new chain (age, length, rotation, lost
snapshot) starts a new full tar and a new index; the index names the same
objects, which are already on the shelf, so a new chain costs one index
upload, not a re-copy. Chain retention deletes chains; object retention
deletes an object only when no retained chain's run names it. The two families
never touch each other's objects.

## Tests

The fake shelf is the local-provider HTTP fixture `s3signer_multipart_test.php`
already starts; the fake file bucket is `tests/lib/cloud_fixtures.php`.

- `tests/backups/backup_objects_test.php` (safe): index build from a fixture
  set and a fixture listing; epoch decisions (none / rotated / site key lost);
  both retention rules over fixture indexes and listings including the
  newer-than-newest-run guard; release rule across zero, one and two enabled
  profiles and a missing `held.json`; `group()` skips `objects/`; the exclude
  list names original and every variant path; the PHP cipher and the
  `openssl` command line open each other's output; a standalone index groups
  with its archive.
- `tests/backups/backup_objects_run_test.php` (db): a runner run against the
  fake shelf — object stored once across two runs, a chain break and a wiped
  `held.json`; the store budget honoured with at most one object's
  temporaries on disk at any point; a manager run with an index link and no
  listing stores only what the index lacks; the tar excludes every `cloud`
  blob; index artifact in manifest and ledger; `restore_plan()` returns it; a
  manager request without `objects` stores nothing and holds nothing; a
  database-only site holds nothing.
- `tests/cloud_storage/offload_release_test.php` (db): `_sync_row()` stores to
  the site shelf and releases with only the site profile enabled; keeps local
  bytes while the manager profile is enabled and its held set lacks the
  object; releases immediately with none enabled; a failed tick store leaves
  the bytes and no ciphertext; a run's store step waits on the tick's row
  lock; permanent delete of a waiting `cloud` blob leaves no local file;
  `head()` on the fixture driver.
- Verify: `tests/backups/backup_verifier_test.php` gains an objects fixture at
  each level; `gone` on a deleted object; the read level makes no per-object
  request.
- Restore: `restore_objects.php` in `missing` and `all` modes against a fixture
  tree and against a link map, with a stubbed file-bucket `head()`; an object
  whose hash disagrees with the index is refused before decryption; the
  plane's paging yields ≤150 links per job and covers every index entry once.
- `tests/unit/core_api_mechanical_test.php`: the release step and the restore
  flip are server-initiated writes.
- `tests/backups/backup_objects_notice_test.php` (safe): the notice renders
  above the byte threshold, above the age threshold with anything waiting,
  and not otherwise; the same-account line appears exactly when the two
  access keys match.

## Docs

`docs/backups.md` — object store layout, epochs, the objects retention family,
verification rows, restore step, node disk table, the manager request fields,
the settings table's default, and one paragraph on what each shelf protects
against (the same-account point above). `docs/cloud_storage.md` — the tick's
store and release rule, enabled profiles, the inventory, the waiting count,
the notice, `head()` on the driver interface. Current state only.

## Out of scope

- Re-encrypting old epochs to the current key (a "re-seal everything" action).
- A second offload consumer; the enumerator seam is there for it.
- Bucket-to-bucket server-side copy; it needs same-provider buckets and cannot
  encrypt.
- Variants on the shelf.
- Streaming the tar to the bucket (`backup_streaming_upload.md`).
- Any read capability on a node's stored or minted credential, on any
  provider. Reads travel as presigned links; that doctrine is kept.

## Settings

No new settings. One default changes:

- **`backup_delete_local_after_upload` defaults to on.** A local copy of an
  archive is a convenience for a restore that would otherwise download it; on
  a 25 GB node it is the difference between a backup that runs and one that
  fills the disk. The declaration in `settings.json` changes to `"1"`, so a
  fresh install and a reseed both get it. Existing rows are never overwritten
  by a reseed (`Setting::seed_declared()` is insert-if-missing), so a migration
  flips the row on existing sites, in the shape of
  `migrations/spam_learning_on_by_default.php`: set `1` where the value is
  still `0`. An operator who wants the local copy back turns the setting off
  again after the upgrade; the Backups page says the local copy is removed once
  uploaded. The `docs/backups.md` settings table reads on.

  The setting keeps its meaning: it removes the run's archives once the upload
  is confirmed. The chain stays extendable from the manifest and the snapshot,
  which it never removes.

## Work packages

- **WP0** `backup_delete_local_after_upload` default and its migration. Ships
  first and alone: it is what keeps the nodes this spec is for from filling
  before the rest lands.
- **WP1** `BackupObjects` (epoch, PHP cipher, store under the row lock, index,
  the two "what is held" sources, held set, release rule, store budget), the
  tick's store-then-release, the run's ordering and exclude list,
  `backup_files.sh --exclude-from`, the `objects` kind through chain, staging
  and verifier, standalone index naming, `FileBlob::_reclaim()` for a waiting
  `cloud` row, site object retention — site profile.
- **WP2** Manager profile: the three request fields in `JobCommandBuilder` and
  `plan_manager()`, the enabled marker, the object step from the index link,
  envelope re-seal from links; `group()` skips `objects/`;
  `FleetBackupRetention` object family; shelf check. Same release as WP1.
- **WP3** Verification levels, by side, and result lines.
- **WP4** `restore_objects.php` (tree and link-map sources), `restore_chain.sh
  --objects`, the `restore_objects` agent primitive, the paged link jobs on
  the plane, `BackupStaging` prefix.
- **WP5** `CloudStorageDriver::head()`, the file-store inventory, **Bring them
  back** on both kinds of site.
- **WP6** Admin surfaces, the same-account line, `BackupObjectsNotice`,
  Recovery Readiness, docs, tests.
