# Storage targets — every stored object knows where it lives

**Status:** Building — 2026-10-09. No open owner decisions; build order in §10.
WP1–WP3 committed (7b6d7f0b). WP4 committed (3e90d348; dev migrated by `sm_015`, 19 node spaces), and the
reviewer1 review of WP1–WP4 committed (de6202d4). WP6 committed (c10c7f1b; dev migrated by migration 210
and `iem_019`). WP7 committed (fd391509; dev migrated by migration 211 and `sm_017`). **WP5 committed (025e70a7)
and released in 0.8.472 (10-09):** live on dev, a node backup through the broker (301 MB full, a hash for
every object, its ledger file in the bucket, one manifest per run), a level 2 verify of it, a re-upload
through the live endpoint, and the shell recovery by hand with the recorded manifest hash all passed. Still
owed: the first fleet night through the broker, the move test in §13 (B2 → Linode → B2, restored from
each), and the node Backups tab seen in a browser; they are in the live verification queue. S28 (a delete
on a versioned bucket only hid the object) is fixed ahead of WP8, and the passes cleared dev's 128 GB backlog.
WP8 and WP9 remain. The Linode half of F5 waits for a Linode backup target. A browser look at the target
forms, the node Move form, Who backs up here, Adopt, the Cloud Storage page's Switch to another bucket and
Move files, the Backups page's two safety warnings, and now the node Backups tab's manifest hashes is owed.

WP8 preparation (10-09, nothing built):
- **Found while preparing (B32): a delete on a versioned bucket only hides the object.** Backblaze keeps every
  version, and dev's buckets have no lifecycle rule, so every prune so far hid what it deleted: dev's backup
  bucket holds 4,641 current objects (220.9 GB) and 1,633 non-current versions (128.1 GB) under 1,270 delete
  markers. The file store bucket does the same, so a member's deleted file stays in the bucket as a hidden
  version. Every `S3Signer::delete` caller is affected: site retention (`BackupRunner` ×3), offloaded-file
  prune (`BackupObjects` ×2), node prune (`FleetBackupRetention`), customer prune (`ServiceTenantWatch`),
  Stored Backups delete (`TargetBackups`, `NodeBackupShelf`), the file store (`CloudStorageS3Driver`) and the
  connection-test probe (`TargetTester`). **Fixed 10-09, ahead of WP8:** `S3Signer::delete()` itself lists the
  key's versions and deletes each version and delete marker by id, so every caller above deletes for good on
  every target, with nothing for a caller to forget. What is already hidden is invisible to every listing
  and record the pruners read, so the passes that prune also sweep (`HiddenVersionSweep`, owner 10-09: a
  standing step, not a one-off script): a site's backup run its own folder per target, the fleet pass each
  backup target's whole folder, the offload tick each file store; daily per folder, within a budget, recorded
  on `bkt_hidden_sweep`. On dev the first passes remove 1,054 keys (128 GB) from the backup bucket and 2,449
  (42.8 MB) from the file store. WP8's lock work builds on this delete: a version still locked is refused by
  the provider, counted, and tried again the next day.
- Lock pieces: catalogue `object_lock` (b2, s3, linode); `bkt_lock_days` (part of the location, fixed once
  used); lock headers on every put and multipart create, signed into the broker's links as signed headers the
  node must send (the link answer carries them; `S3Signer` link mode sends them); a direct site target sends
  them itself; `GetObjectLockConfiguration` and a one-day locked probe in `TargetTester`; prune skips a version
  whose retain-until has not passed and leaves the point surplus for the next pass (F3 record kept).
- Needed from the owner before the live test (`object_lock_test`): a Backblaze bucket with object lock on and a
  key with readBucketRetentions, writeBucketRetentions, readFileRetentions and writeFileRetentions (dev's key
  has none, so `?object-lock` answers 403 "not entitled"); a Linode bucket with object lock, which must be
  enabled when the bucket is created.

WP5 notes, decided while building:
- Owner (10-09): a Managed node on an older core is **refused** a backup or a re-upload until it is
  upgraded (no key is handed out while it catches up). `JobCommandBuilder::BROKER_MIN_CORE_VERSION` is
  `0.8.472`, the release expected to carry WP5; if another release ships first, it moves to the one that
  does.
- Transport: the job's credential slot carries `__SM_BROKER_<space>_<kind>__`; at hand-out the agent channel
  opens a broker run (`svr_mgn_managed_node_id`, `svr_kind` backup|upload, token hash and expiry = claim
  budget + 1 h, `mjb_svr_shelf_run_id`) and puts `{broker, run_id, token}` in the slot, so the agent passes
  it through unchanged and **no agent release is needed**. The node calls `/api/v1/broker/{begin,sign,
  finish,abort}` (a pre-auth route like the relay channel). On the node, `BackupBroker` is an
  `S3LinkSource` that `S3Signer` takes in place of a credential, so streaming, multipart and retries are
  unchanged; it writes only.
- A manager chain follows the broker's space (`bkh_remote_space_id`), asked at begin, before the chain is
  chosen. A run is finished at the broker before it is committed on the node; a run the broker did not
  record is a failed run.
- Write-once: a key whose row a finished run completed with a sha256 is never signed again (answered
  `exists` with the recorded size and hash). A failed run's keys, and rows the customer reconcile adopted
  from a listing (no hash), are signed again for the run that retries them. `shelf_finish_run` requires each
  object's sha256. The offloaded-files step treats `exists` as stored.
- Re-sealing an epoch envelope after a recovery-key rotation was the one other rewrite: it now writes
  `envelope-{first 16 hex of the new fingerprint}.json` beside `envelope.json`.
- F9: chain version 3; the local working copy stays `manifest.json` and the node's upload ledger records
  each run's manifest under that name, so the agent's staged-chain check is unchanged. A v2 chain is not
  extended by a v3 runner (`layout_split`).
- F4: ledger files are `{space base}ledger/{run id}.json`, written by the broker at finish (and retried by
  the fleet pass), and by a site for its own target at `{prefix}/{slug}/ledger/{history id}.json`. They
  are kept after their runs are pruned (a few kB each).
- Shell disaster recovery: the required hash is on `backup_envelope.php open` (`--manifest-sha256`, or
  `--trust-bucket-manifest`), which only an operator runs. `restore_chain.sh` takes `--manifest-sha256` as
  an optional check and prints the hash it used: the agent runs it after its own ledger or vouch check,
  and making it required there would need an agent release. The node Backups tab shows each run's
  manifest and its recorded sha256, and says when a set's newest manifest differs from the recorded one.
- The site-copy check compares the manifest with the recorded hash when there is one; a chain written
  before the broker has none and is read as before.
- reviewer1's review (10-09: 1 high, 5 medium, 5 low, 4 notes) is fixed, and its re-review found WP5 VALID
  (10-09). Of its four observations, three are fixed after it: the broker header says a landed manifest or
  envelope is followed by a new chain, not a retry; one finish runs at a time per run (an advisory lock);
  a failed ledger file waits on its own `svr_ledger_tried_time`. The fourth (a taken-over adopted row names
  the aborted run) is cosmetic and left. The fixes: a finish or
  abort asked again for a closed run is answered (a lost reply no longer fails a recorded run); a name
  already taken with other bytes closes the chain and the next run starts one (`name_taken`), the same bytes
  count as stored; a manifest or envelope is never signed over one already in the bucket, recorded,
  adopted or written before the broker (looked for first); a taken-over adopted row is left as it was when
  the run aborts; ledger files do not make a draining space non-empty; a failed ledger file records why and
  waits a day, counted by the pass; `restore_chain.sh` no longer exits silently with no manifest, and it and
  the hand verify say when nothing vouches; readers prefer the newest recorded manifest; a site's pruned
  chain takes every manifest in its folder; a re-claimed job aborts the run it opened; the reconcile says a
  size that differs under a hashed row instead of adopting it.
- The node prune marks a deleted object's ledger row pruned (`retention`). Retired: per-run key minting,
  `bkt_node_credentials`, `bkt_mint_run_keys`, the three credential placeholders, `B2Client`'s key calls.

WP7 notes, decided while building:
- Owner Q1 (10-08): verification is weekly by default, for sites (`backup_verify_every_days`) and nodes
  (`server_manager_fleet_backup_verify_every_days`), and "not verified" is a problem a day past the
  interval (8 days). Migration 211 and `sm_017` move settings and node policies still at the old 30.
- One rule, `BackupSafety::confirm()`, decides for every pruner: site chains and standalone runs,
  `FleetBackupRetention::prune()` and the customer `retain_chains()`.
- A site chain is verified when any of its runs carries `bkh_verify_outcome` pass; no new column.
- "Found surplus" is `bkh_surplus_time` on a site and `sps_surplus` on the storage space on the
  management node (not `svr_surplus_time`): a node's chains have no run rows until WP5, and a chain the
  reconcile adopted has no run.
- F2 goes by time: a failed run records no artifacts (a chain run already discards its files), so the
  sweep keeps every backup file written after the newest run of its own kind (chain, or standalone of
  its type) that finished uploading.
- reviewer1's review (10-09: 1 medium, 1 low-medium, 1 low, 2 notes) is fixed: F2 judges a file by runs of
  its own kind; the verified floor is read from the verify jobs and verified runs, never from a listing, so
  a space that cannot be listed cannot let what is newer than its verified chain go; migration 211 has no
  test, so an operator's later 30 stays; the unreachable local-plan branch is gone. Its note N2 stands as
  written below (customers alarm and hold draining spaces until the site half reports verifies). reviewer1
  verified the fixes (10-09).
- Customers: `svr_verified_time` is stamped by the new `shelf_verified_run` action
  (`ServicesClient::shelfVerifiedRun()`); the site half calls it when the services site side is built.
  Until then no customer chain is verified: customers alarm as never verified, and a customer's draining
  space (released now by a verified run, not a finished one) is held. Owner (10-09): kept as built, so the
  gap cannot go unnoticed when customers arrive.
- Incidents: a Managed node's two-night gap and verify gap are the existing `plane:backups_stopped` and
  `plane:backup_unverified` (now 8 days); customers get `plane:customer_backups` on this node.
- §13's `backup_safety_test` covers F1–F3; F4 and F9 are checked by `broker_managed_run_test` (ledger file,
  write-once), `backup_chain_manifest` and the `backup_chain` gate (one manifest per run, version 2 still restores).

WP6 notes, decided while building:
- A row records its store and the full key of its **primary** object (`fbb_remote_key`,
  `iem_raw_remote_key`); a blob's variants sit beside it (`FileBlob::remote_key_for()`).
- The file store's own on/off is still `cloud_storage_enabled` (Pause); `bkt_enabled` stays on for a file
  store. Disable-and-pull stays, beside Move files.
- Offloaded mail's backup object is `mailbox/{id}.eml`: one namespace segment, which no stored file name
  can carry (`BackupObjects::location_of()`). Restore finds a row through its profile (`backupRow()`).
- A move checks each copy by size and the ETag when it is the bytes' MD5, otherwise by reading it back.
- reviewer2's review (10-08, 1 high, 3 medium, 4 low) is fixed: two file stores in one place (bucket and
  same or nested folder) are refused at save, at Move files and in the move batch; an MD5-shaped ETag that
  is not the MD5 falls back to reading back; the per-store file count no longer drops a table on an error;
  an older store is pinged and has its own Replace key; each table's row locks are their own space; a
  whole-object answer to a ranged read is cut to the span; the migration and docs say the rest.

WP4 notes, decided while building:
- A node's chain follows the **name** of the target the management node sends: a run to a target of
  another name starts a new chain (`BackupRunner` 1.28), so target names are unique. Until WP5 gives
  runs a broker id, that is how a moved node starts fresh. A node must run this core before a Move
  restarts its chain; an older one extends its chain into the new bucket.
- A customer's draining space is released when the active space holds a **finished** run; the
  "verified" half joins with WP7's `svr_verified_time` (F1). A node's needs a passed verify of a
  chain in the active space (the verify job names `space_id`).
- `svr_mgn_managed_node_id` (runs with a node owner) is left to WP5, which is its first user.
WP8 waits for WP5; WP5 waits for `backup_database_incrementals.md`'s `BackupRunner` work.
**Takes over:** item 2b (the object-store seam) and the shelf-target parts of item 2a in
`services_phase2_platform.md`, which stays the owner of the services themselves
(enrolment, metering, the lapse ladder, the site side).

## 1. The problem

We store bytes in other people's buckets in three separate arrangements, each with
its own rules:

| Arrangement | Where its settings live | Who holds the key |
|---|---|---|
| **Backup targets** (`bkt_backup_targets`, on every site and on the management node) | a sealed row per destination | the site; for Managed nodes, the node is handed one of three keys per job |
| **The file store** (private uploads and offloaded mail) | six `cloud_storage_*` settings, secret in plain text | the site |
| **Backup storage for customers** (the services shelf) | one plane-wide setting naming a target row | the management node only; sites get presigned links |

None of them records **which destination holds a given object**. Every reader,
whether restore, verify, list, prune or the byte count, asks "what is the
destination right now?" and looks there. That holds only while nothing ever
changes. Change a destination, which is exactly what adding Linode means, and:

- old backups become unlistable, unrestorable and unprunable, and stay in the
  old bucket forever at our cost;
- retention deletes from the new bucket, gets "not found", counts that as
  success, and marks the old copies pruned;
- the next incremental extends a chain whose full backup is in the other bucket;
- a Managed node moved to Linode is quietly handed the delete-capable main key,
  because Linode cannot mint a write-only one;
- enabling a second target anywhere breaks every node and customer that relied
  on "the only enabled target".

The fix is not a Linode feature. It is three rules every storage path obeys.

## 2. The rules

**R1. A target's location never changes once something is stored there.**
The location is the provider, endpoint, bucket and path prefix. Keys can be
rotated and the name can be edited. To move to another provider or bucket, add a
target. This is the rule the file store already has (`assertBindingMutable`),
applied everywhere.

**R2. Every run and every stored object records the target that holds it.**
Readers use the recorded target, never "the current one".

**R3. Switching means new writes go somewhere new. Nothing is copied.**
Point an owner (a node, a customer or the site) at a new target and its next
backup starts a fresh full chain there. Old copies stay readable and restorable
on the old target, and normal retention prunes them there. Once the old target
holds nothing, it can be removed. Backups move by ageing out; no migration step
exists. The file store is the one exception, because files never age out (§7).

Three supporting rules make the first three hold:

**R4. Only the machine that owns a target holds its key.** A site uses its own
targets directly. Every target the management node owns is used through the
broker's presigned links, by customers *and* Managed nodes (owner decision D3 in
`services_phase2_platform.md`, which is not yet built for Managed nodes). This
removes per-run key minting, the shared node key and the "Linode gets the main
key" gap together. No node holds a storage key at all.

**R5. One provider catalogue, and providers differ only in data.** A provider is
a label, an endpoint pattern, a region rule, an addressing style and a console
link. Backblaze also has one native-API helper, used for endpoint discovery and
to read a key's capabilities. Adding Hetzner or anything else S3-compatible is
one catalogue entry.

**R6. Every choice of target is explicit.** No path falls back to "the one
enabled target". There are three such fallbacks today
(`JobCommandBuilder::get_target`, `JoineryServices::shelfTarget`, the core
setting with no default), and all three break when a second target is added.

## 3. What "the target that holds it" is

Two databases are involved, so the answer has two forms.

**On the management node: a storage space.** A space is one owner's folder on one
target, for example "node `acme`'s folder on the B2 target" or "customer
`t12`'s folder on the Linode target". It is the unit the broker signs inside,
the ledger counts, retention prunes and a move replaces.

```
sps_storage_spaces
  sps_storage_space_id       serial
  sps_bkt_backup_target_id   int8 FK, not null        -- the target that holds it
  sps_base_key               text, not null           -- '{prefix}/{slug}/', normalised once, here
  sps_mgn_managed_node_id    int8 FK, null            -- exactly one owner column is set
  sps_svt_service_tenant_id  int8 FK, null
  sps_state                  'active' | 'draining' | 'retired'
  sps_opened_time, sps_draining_time, sps_retired_time
```

- An owner has exactly one **active** space, which takes new writes, and any
  number of **draining** spaces, which are read and pruned only. A draining
  space with no objects left is marked **retired**.
- Base keys are unique per target, and no base key may be a prefix of another
  on the same target. This rules out the collision where customer `t5` and a
  node slugged `t5` share a folder, prune each other's objects and adopt each
  other's objects.
- A space holds three kinds of thing: runs under `{base}/{profile}/`, ledger
  files under `{base}/ledger/` (§9, F4), and the customer's wrapped recovery
  key at `{base}/recovery/wrapped_key.json` (`managed_backup_recovery.md`).
  Runs reach only their own folder. The wrapped key is written through a broker
  action of its own, not a run, and its writes are recorded like any object's.
- Prefix trimming happens once, when a space is created. Today it is done four
  different ways.
- `mgn_bkt_backup_target_id` is replaced by the node's active space.
- `server_manager_services_shelf_target_id` is replaced by §5's "where new
  backups go".

`svr_shelf_runs` and `svo_shelf_objects` gain `svr_sps_storage_space_id` and
`svo_sps_storage_space_id`. Runs also gain an optional node owner, because
Managed nodes use the same broker (R4). Every broker action resolves its target
through the run's space, or through the object's space for reads. It never uses
a setting.

**On a site: the history row says where the run went.** `bkh_backup_history`
gains `bkh_destination`, which is never null:

| `bkh_destination` | Meaning | Where it points |
|---|---|---|
| `local` | kept on this machine only | nothing to point at, and saying so is the answer |
| `target` | the site's own target, used directly | `bkh_bkt_backup_target_id`, a real FK in the same database |
| `service` | the management node's broker (Managed run, or a customer of backup storage) | `bkh_remote_run_id`, the management node's `svr` id, plus `bkh_target_name` for display |

A node cannot hold a foreign key to the management node's target ids; its own
`bkt` ids mean something else. So a node records the broker's run id, and the
management node holds the run with a real foreign key to the space and the
target. "Every event for target X" is then one query on the management node,
plus one on each site for its own targets. A run that never reached the broker,
for example one refused at `shelf_begin_run`, stored nothing anywhere, and its
`service` row records the refusal as the cause.

## 4. Events: which record answers "what happened on target X"

These are the B1–B7 gaps from the 10-08 inventory, each with the record that
closes it.

| Event | Record after this spec |
|---|---|
| A site's local-only run | `bkh` row, `bkh_destination = 'local'` |
| A site's run to its own target | `bkh` row with the target id. Every reader now uses it (§6) |
| A Managed run, or a customer's run | `svr` row → space → target on the management node; the node's `bkh` row carries `bkh_remote_run_id` |
| A re-upload or copy of an archive | a broker run of kind `upload`, so it gets an `svr` row. Today the upload primitive writes nothing |
| An object written | `svo` row with space, key, bytes, sha256 and completed time |
| An object pruned | the same `svo` row gains `svo_pruned_time` and `svo_pruned_cause` (retention, lapse, abort, reconcile) and is kept, not deleted |
| Offloaded files in backup storage | `svo` rows under the owner's space |
| Management jobs and node stamps | summaries, not events. `mjb` rows gain `mjb_svr_shelf_run_id` where a job ran a broker run, and stamps describe the active space |

## 5. Choosing and changing targets

**On the management node** there is one typed setting, **Where new backups go**
(a select of enabled targets, required once a target exists). Every new node and
every new customer opens its first space there.

- **Move one owner** (node detail, or the customer's row) to target X: X opens a
  new active space and the old space starts draining. The next run starts a full
  chain on X, because the broker refuses to extend a chain whose space is
  draining (§6). This works in either direction, between any two targets.
- **Move everyone off target A** (on A's page) does the same for every active
  space on A. Changing providers is this one button, with no further steps.
- **A draining space is kept whole until its owner's new space holds a complete
  verified chain**, so there is never a night with no restorable copy anywhere.
  After that, normal retention prunes it, chains whole.
- **Unclaimed folders.** Stored Backups already shows folders on a target that no
  node claims. Each one gets an *Adopt as draining space for…* action, so copies
  orphaned by past switches become listable, restorable and prunable again.

**Target rows**, the same class in both databases:

- Location fields (provider, endpoint, bucket, prefix) are read-only once the
  target holds anything. On the management node, "holds anything" means any
  non-retired space. On a site, it means any `bkh` row or file-store row that
  points at the target. Keys and name stay editable.
- **Disable** means "take no new spaces or writes". Reads, restores and pruning
  continue. Disabling is refused while the target is the default or holds an
  active space. **Delete** is refused while it holds any non-retired space or
  row. Both refusals give a sentence naming what still uses the target.
- Readers that list "every target" include disabled targets: Stored Backups,
  the recovery-readiness console links, and the node hard-delete guard.
- On a site, `backup_target_id` remains the one choice. The wizard and the
  schedule show that target only, and offer enabled targets only.

## 6. What changes in the engine

**The object-store seam** (formerly item 2b): one interface (`put_file`,
`put_stream`, `get`, `get_to_file`, `list`) with two implementations:

- **direct**: today's `S3Signer` calls with the site's credential;
- **brokered**: asks the broker for each link, then makes the HTTP request
  itself.

`BackupRunner`, `BackupVerifyLauncher`, `BackupObjectRestoreLauncher`,
`TargetTester` and `utils/upload_backup.php` all call the interface. The node's
job carries a per-run broker token in the slot where the credential goes today.
`mint_run_credentials`, `bkt_node_credentials`, `bkt_mint_run_keys`, the three
`__SM_*CREDS_*__` placeholders and the `B2Client` key minting all retire.

**Chains belong to one destination.** `current_chain()` filters on the
destination: target id, or broker space as answered by `shelf_begin_run`.
`BackupChain::should_start_new()` gains the reason *the destination changed*.

**Readers use the run's recorded destination.** Retention, verify, *Bring files
back*, prepare and restore each take the target from the history row or the
space. "Not found" on a prune of a row's own target still counts as already
gone. It can no longer come from the wrong bucket.

**Offloaded-file epochs follow the destination.** When a run's destination holds
no `envelope.json` for the current epoch, the run uploads it. The listing parse
already detects the missing envelope.

**Write-once names, and a recorded hash for every object.** This closes the
chain-forgery gap from the 10-08 review.

- The broker signs a `put` only for a key the ledger does not already hold as
  completed. There is no exception: each run writes its own manifest (§9, F9).
- At `shelf_finish_run` the node reports each object's sha256. The ledger
  records it, and the management node now holds an authoritative hash of every
  manifest version.
- A site copy from a dead source (`copy_take_key`) checks the bucket's manifest
  against that recorded hash, not against whatever the bucket holds. The
  management node's listings and the backup-storage check do the same.
- **Shell disaster recovery** (`backup_envelope.php open`, then
  `restore_chain.sh`) takes the manifest's expected sha256 as a required
  argument and refuses a manifest that does not match. The run's page on the
  management node shows the hash beside its download links, and the
  disaster-recovery card in `key_management_simplification.md` says to copy it
  from there. If the management node is lost as well, the hash comes from the
  run's ledger file in the bucket (§9, F4). Only for a bucket with neither does
  the operator pass `--trust-bucket-manifest`, which says in the output that
  nothing vouches for the bytes (§12).
- A node can then replace nothing it already wrote, and cannot slip a forged
  manifest past a copy or a restore. What a compromised node can still do is
  write bad *new* runs; §9's F1 stops those from pushing out the good ones.
- No manifest signing key is added: `key_management_simplification.md` is
  reducing the number of keys.

## 7. The file store joins the target rows

The file store becomes a target row with `bkt_purpose = 'files'`, chosen by the
site setting `file_store_target_id`. The six `cloud_storage_*` settings retire.
Its credentials are sealed like every other target's.

- `fbb_file_blobs` and the raw mail store (`iem_raw_storage_*`) record the target
  id and the **full** object key when a file is offloaded. Changing
  `site_template` then no longer re-points every existing key.
- **Switching the file store:** new offloads go to the new target, and every
  existing file reads from its recorded one. Files never age out, so a **Move
  files** pass on the old target's page carries them across in small batches:
  download, upload, verify the hash, update the row, then delete the old copy.
  It runs online, can resume, and shows progress. Once the old target holds no
  rows it can be removed. This replaces "disable, pull everything to local disk,
  rebind", which needs the whole store to fit on the server.
- The file store's driver moves from the AWS SDK onto `S3Signer`, so one client
  remains. The build checks every operation the driver uses (put, get, head,
  delete, presigned get) against what `S3Signer` already has.
- **Offloaded mail is included in backups.** `RawMessageStore` declares its
  backup objects the way `BlobStorageProfile` does. Today the local copy is
  deleted after offload and the bucket holds the only copy (bug S17).

## 8. One provider catalogue, one client

- `StorageProvider` is the only provider list. It covers b2, s3, linode, r2,
  wasabi, digitalocean, hetzner and generic, with a label, endpoint pattern,
  region rule, addressing style, console URL and object-lock support (§9, F8)
  for each. `bkt_provider`'s
  allowed values, both target forms, the wizard dropdown,
  `install_backup_target.php`, and the four label maps under `server_manager`
  all read it. That replaces six lists and four maps.
- **One endpoint form.** Endpoints are read by `S3Signer::endpoint()` (built
  2026-10-08: a bare host is https) and stored as `https://host`. One host
  parser replaces the two identical ones in `StorageProvider` and `BucketCheck`.
  One B2 region rule comes from the catalogue, replacing three different
  regexes.
- **Addressing is a catalogue property.** `S3Signer` adds virtual-host
  addressing for `amazonaws.com`, which the SDK driver already uses. Path-style
  stays for the rest.
- `ShelfPresigner` and `S3Signer::presign_get` become one presigner, and
  `TargetLister` folds into `S3Signer::list`.
- **One target form.** `plugins/server_manager/views/admin/targets.php` and the
  core Backups page's `save_target` both write `bkt_backup_targets`, with
  different field names, different B2 handling and different defaults. Once
  node credentials are gone (§6), the plugin form has nothing the core form
  lacks. The plugin page renders the core form, and one save path remains.

## 9. Backup safety

§1–§8 make storage movable. This section closes the weaknesses that remain once
they are built:

- one prune bug, or one break-in on the machine holding the delete key, can
  erase everything;
- bad backups can age out the good ones;
- the management node's records are the only thing vouching for a bucket;
- losing a whole machine leaves only the manual shell restore;
- local copies can be swept before they ever went offsite;
- retention deletion has never been tested against a real bucket.

### F1. Never prune the last good backup

Retention is by age alone today (`BackupRunner::surplus`), with no knowledge of
verification. Picture a compromised node, or a bug that produces backups which
upload fine but do not open. After the retention window (`keep_days`), the last
good one is gone.

- A run's verify result is recorded on the run: `bkh_verified_time` on a site,
  `svr_verified_time` on the management node. It is written when the scheduled
  verify (`BackupVerify`, or the management node's verify dispatch) passes for
  that run's chain.
- Every retention pass protects the owner's **newest verified chain and
  everything newer than it**. Site retention (`enforce_chain_retention`,
  `enforce_cloud_retention`) and the management node's per-space prune both do
  this. It holds whatever the age: a chain outside the window stays until a
  newer chain verifies.
- No verified chain for 8 days opens an incident on the management node
  ("*acme*'s backups have not passed verification since …"). On a self-hosted
  site with its own target, the Backups page says it.

### F2. Never sweep a local copy that did not go offsite

`sweep_local` removes local artifacts by age alone. While off-site uploads are
failing, for example because the management node is unreachable (after WP5 a
node cannot go offsite without it), the sweep removes the only copy.

- `sweep_local` keeps every artifact whose run has no completed off-site upload:
  a `bkh` row with `bkh_destination` other than `local` and no finished upload.
  Local-only runs sweep by age as before.
- Two nights with no completed off-site run opens an incident on the management
  node for Managed nodes and customers. A self-hosted site with its own target
  shows it on its Backups page.

### F3. A prune confirms before it deletes

A bug in the prune can delete everything in one pass, as the wrong-bucket prune
found on 10-08 shows. One rule caps the damage:

- A pass deletes a chain only if the previous pass, at least 20 hours earlier,
  also listed it as surplus. The site records this as `bkh_surplus_time` on the
  chain's rows; the management node as `svr_surplus_time`.
- A pass never deletes an owner's newest chain, whatever F1 says.
- Every deletion is written to the ledger with its cause (`svo_pruned_cause`,
  §4).

A wrong rule shows up as a day of "would prune" entries before anything goes,
and the newest chain survives even a rule that is wrong about everything.

### F4. Every run's hashes are also in the bucket

The management node's database is the one record of every object's hash (§6).
Lose it together with a source machine, and nothing vouches for a bucket.

- When a run finishes, the owner of the target writes
  `{space base}/ledger/{run id}.json`: every object of the run with its key,
  bytes and sha256, plus the run's time, profile and chain. The management node
  does this for brokered runs; a site does it with its own key for direct
  targets.
- The broker never signs a key under `ledger/`. Run base keys are
  `{space base}/{profile}/…`, so no run can reach it.
- Shell disaster recovery and the site-copy check read the hash from there when
  the management node is gone (§6).
- On a target without object lock, a ledger file is only as trustworthy as the
  target's key. With F8 it cannot be replaced.

### F5. Retention deletion, proven against real buckets

`tests/backups/retention_live_test.php` (live tier) runs against a real B2 test
bucket and a real Linode test bucket. It follows the credential pattern of
`tests/integration/cloud_storage_live_b2_test.php`. For each bucket it:

- writes four small chains with backdated history;
- runs retention twice, 20 hours apart on the test clock;
- asserts that exactly the surplus chains are gone from the bucket, that the
  newest verified chain and the newest chain survive (F1, F3), and that the
  first pass deleted nothing (F3).

It is part of WP7's acceptance and of the live gate in §13.

### F6. A second recovery key — DEFERRED (owner, 2026-10-08)

Kept for reference; not built by this spec. Every backup is sealed to one
operator recovery key, kept in a password manager. If that key is lost, every
backup is unreadable once the machine that made it is gone (each machine's own
site key dies with it). The same question is open decision 4 in
`key_management_simplification.md`.

- **(a) A second copy of the same key, kept offline** (printed, in a safe, or
  with a trusted person). No code. It covers losing the password manager. One
  leaked copy cannot be cut off alone: the cure is a new key from then on.
- **(b) A second, different key added as one more envelope recipient**
  (`BackupEnvelope` already seals to two: recovery and site). Small code
  change, plus its own possession ceremony. It covers losing either key, and
  either can be dropped without the other. It also lets a second person hold
  independent access. The cost is two keys that each open everything.

The leaning when deferred was (a), with (b) only if a second person needs
independent access. Customers' keys are covered separately by
`managed_backup_recovery.md`.

### F7. Restore onto a new machine — only from a locked target

When a machine dies, the one-click restore from the management node refuses
on the replacement. The new machine has no upload record (ledger) for those
backups, and no site key to open them. The refusal is deliberate: it stops a
compromised management node from choosing a restore's bytes
(`restore_dispatch_approval_mechanism.md`). Today the way out is the manual
shell procedure.

F7 is a guided **Restore onto a new machine** flow on the node's page:

1. The replacement machine builds its upload record from the space's ledger
   files in the bucket (F4), written at upload time.
2. The operator unlocks the chain in the browser with the recovery private key,
   so the key never reaches a server. The browser reseals the chain's data key
   to the replacement's site key and hands it over.
3. The normal approved restore runs.

**Only on a target with object lock (F8).** The trust the existing refusal
protects then holds: a locked ledger file was written when the bytes were
uploaded, and nobody can have changed it since, the management node included.
On an unlocked target, the ledger file is only as good as the management
node's word. There the flow is not offered, and the page points at the shell
procedure.

- The browser unlock is the same step `managed_backup_recovery.md` designs for
  customers. Build it once, for both.
- This closes gap 2 of `backups_remaining_gaps.md` for locked targets: the
  ledger handover and the data-key reseal it names, done by the same party at
  the same moment.

### F8. Object lock where the provider has it

F1 and F3 stop bugs. Only the provider can stop a break-in on the machine
holding the delete key: under **object lock in compliance mode**, nobody can
delete or overwrite an object before its retain-until date, the key's owner
included. B2, AWS S3 and Linode all offer it. Linode's is documented by Akamai
at <https://techdocs.akamai.com/cloud-computing/docs/protect-data-with-object-lock>.

- The provider catalogue (§8) gains `object_lock: true` for b2, s3 and linode.
  It stays false for the others until each is checked.
- A backup target gains `bkt_lock_days`, where 0 means off. It is part of the
  location, so it is fixed once the target holds anything (R1).
- With lock on:
  - every put carries `x-amz-object-lock-mode: COMPLIANCE` and
    `x-amz-object-lock-retain-until-date` set to now plus `bkt_lock_days`;
  - the broker signs both headers into the presigned URL, so a node cannot leave
    them out;
  - the default for `bkt_lock_days` is `keep_days + full_interval_days`, which
    covers every object for as long as its chain can be in use.
- **Retention becomes "delete once unlocked".** A prune skips objects whose
  retain-until date has not passed and deletes them by version once it has.
  Locking needs versioning, so a version-aware delete is required. `S3Signer`
  gains list-versions, delete-by-version, the lock headers, and
  `GetObjectLockConfiguration`.
- **The connection test** reads the bucket's lock configuration and writes one
  locked probe. It refuses a target set to lock whose bucket has no lock
  enabled. The locked probe stays until its date, so the test locks it for one
  day only.
- **Backup targets only.** The file store keeps ordinary deletes, because
  deleting a member's file has to delete it.
- **Cost:** storage for an object is held until its lock date even if it is
  pruned earlier, and nothing can shorten that. A mistaken large upload costs
  its lock period. The target form states this beside the field.

### F9. One manifest per run

Every run of a chain rewrites `manifest.json`, which is why §6's write-once rule
needed an exception and why an open chain could be spoiled.

- A run writes `manifest-{seq}.json`, where `seq` is the run's position in the
  chain, zero-padded. This is chain format version 3.
- Readers take the highest `seq`, and a prune deletes every manifest of a chain
  with the chain.
- Version-1 and version-2 chains keep `manifest.json` and stay readable.
- Staging writes the chosen manifest locally as `manifest.json`, so the agent's
  staged-chain check (`restore_paths.go`) is unchanged. The build confirms that
  no agent release is needed.
- On a locked target this also stops every chain run adding a locked version of
  the same name.

## 10. Build order

Each work package is self-contained and leaves the system working.

| WP | What | Depends on |
|---|---|---|
| **WP1** | Provider catalogue, one endpoint form, one presigner and lister, addressing rule, endpoint normalised on save; one target form. No behaviour change otherwise | — |
| **WP2** | Target rules: location read-only once used, disable/delete guards with sentences, disabled targets counted by every reader, no "sole enabled target" fallback anywhere, the management node's *Where new backups go* setting | WP1 |
| **WP3** | Site history destinations: `bkh_destination`, every reader uses the recorded target, chains restart when the destination changes, epoch envelope on a new destination. Fixes direct-target switching on self-hosted sites | WP2 |
| **WP4** | Spaces on the management node: table, row migration (below), broker resolves by space, ledger keeps pruned rows, Move one owner and Move everyone off, adopt unclaimed folders. Fixes every backup-storage bug in §11 | WP2 |
| **WP5** | Managed nodes on the broker: the object-store seam, per-run token, upload, download, verify and restore through broker links, write-once names, recorded hashes and the copy and shell-restore checks against them, ledger files in the bucket (F4), one manifest per run (F9), retire minting and node keys, `bkh_destination = 'service'` with `bkh_remote_run_id` | WP3, WP4, and **after** `backup_database_incrementals.md`'s `BackupRunner` work is committed (one session in that file at a time) |
| **WP6** | File store on target rows, blob and mail rows record target and key, Move files, the driver onto `S3Signer`, mail in backups | WP1, WP2 |
| **WP7** | Backup safety: keep the last good backup (F1), keep local copies that never went offsite (F2), prune confirms before deleting (F3), the live retention test (F5) | WP3, WP4 |
| **WP8** | Object lock (F8): catalogue flag, `bkt_lock_days`, lock headers signed by the broker, version-aware prune, connection-test check | WP5 |
| **WP9** | Restore onto a new machine (F7): upload record rebuilt from locked ledger files, the browser unlock and reseal (shared with `managed_backup_recovery.md`), offered only on locked targets | WP8 |

F6 (a second recovery key) is deferred and in no work package.

**Row migration (WP4), not data movement.**

- Every Managed node with a resolvable target gets an active space for
  `{prefix}/{slug}/` on that target.
- Every customer row on the shelf target gets one for `{prefix}/t{id}/`.
- Existing `svo` rows join their customer's space.
- WP3's node migration sets `bkh_destination` from what each row already holds.
  A target id becomes `target`. A manager-profile row with a target name becomes
  `service` with no run id. Everything else becomes `local`.
- WP6's migration writes the current file-store binding as a `files` target row
  and stamps it and the computed key onto every `cloud` blob and mail row.
- No bytes move in any of these.

**Live exposure today.** The site side of backup storage for customers is not
built (`ServicesClient`'s shelf calls have no callers), so the backup-storage
bugs in §11 have no customers behind them yet. Managed nodes do: one target is
enabled on each management node, and the switch bugs fire on the first change.

## 11. Bugs found in the 2026-10-08 survey

These are the bugs in the 2026-10-08 survey, with file references from that
date. "Now" means fixed in this session, outside this spec's work packages.

| ID | Bug | Fixed by |
|---|---|---|
| S1 | A Linode or S3 endpoint entered as a bare host (what the form and the endpoint check ask for) failed at upload with "Invalid endpoint" (`S3Signer.php` request/presign, `ShelfPresigner`) | **Now**: `S3Signer::endpoint()` |
| S2 | `JobCommandBuilder::get_target` hands out a soft-deleted target, and credential slots resolve without checking enabled or deleted | **Now** |
| S3 | Cloud-file delete on a node page is scoped to the whole target, not the node's own folder, and ignores the fallback target the listing uses (`backup_actions_logic.php`) | **Now** |
| S4 | An encrypted archive can be uploaded without its key file when the node has no explicit target (`backup_actions_logic.php`) | **Now** |
| S5 | The bucket-privacy probe counts "no answer" as private, and with a bare-host endpoint it built a URL with no scheme, so it never got an answer: the check passed without checking (`BucketCheck.php`, `CloudStorageLifecycle::privacyVerdict`) | **Now**: no answer is a warning; the probe URL goes through `S3Signer::endpoint()` |
| S6 | The setup wizard shows and tests the first target by name, not the chosen one (`setup_steps/backups.php`) | **Now** |
| S7 | A run whose chosen target is disabled or deleted says "No backup target is configured" (`BackupRunner::plan_site`). Listing disabled targets in the schedule, marked, is deliberate and stays | **Now**: the refusal names the target and its state |
| S8 | Manager-profile re-uploads land outside the profile folder, so retention and the byte count miss them (`utils/upload_backup.php`) | **Now** |
| S9 | Changing an existing target's provider keeps the old node key, so the save fails its test and Linode has no field to clear it (`views/admin/targets.php`) | **Now** |
| S21 | The node hard-delete guard and the recovery-readiness list ignore disabled targets that still hold backups | **Now** |
| S23 | `referential_integrity` counts another concurrent test run's in-flight users as leaks | **Now**: liveness lock per run |
| S10 | The node target dropdown's blank option says "Local only" but means "the one enabled target", and re-saving while the target is disabled clears it (`overview.php`) | WP2 (built) |
| S11 | Path prefix trimmed four different ways, so a leading `/` breaks minted-key scoping | **WP4** (built): one normalisation, stored on the target and composed into the space |
| S12 | Three different B2 region regexes; a rejected cluster leaves the endpoint empty with no note | WP1 (built) |
| S13 | `CloudStorageLifecycle::_write_settings` silently skips a missing setting row | **WP6** (built): the settings are gone; the page writes through `Setting::put()` |
| S14 | File-store binding guard compares endpoints as raw strings in one place and as hosts in another | **WP6** (built): the store is a target row; R1 compares locations in one form |
| S15 | Changing `site_template` re-points every offloaded file's key with no warning | **WP6** (built): every row records its full key |
| S16 | The file store's secret key is stored in plain text | **WP6** (built): sealed in the target row |
| S17 | Offloaded mail is not in backups; the bucket holds the only copy | **WP6** (built): `RawMessageStore` declares its backup objects |
| S18 | Dead code and stale text: `B2Client::deleteKey`/`countKeys`, the "bkt claimed by BookingType" comment, misplaced docblocks in `backup_targets_class.php`, `creds.go` and "target override" comments, `__SM_RUN_CREDS_` missing from the overview doc | WP1 part built (the first three); **WP5** (built): the credential slots are gone, the overview describes the broker, and the agent holds no slot code of its own |
| S19 | Backup storage: no target on customer, run or object rows; `shelf_of()` skips silently; reconcile against the wrong bucket wipes the ledger and zeroes the figure; a read with a run id signs the old key against the current bucket; abort uses the current target and drops the row on failure; `t{id}` can collide with a node slug; the target setting is free text; completeness checks differ; unpaid customers can list | **WP4** (built; the setting was WP2) |
| S20 | Site targets: retention after a switch deletes from the wrong bucket and counts 404 as success; chains continue across a switch; verify and *Bring files back* use the current target; no epoch envelope on a new target; `bkh_bkt_backup_target_id` is never read; location editable in place | WP2 and WP3 (built) |
| S22 | `NodeBackupShelf::prune` deletes every profile under the node's folder, including a site-profile target sharing that bucket and prefix | **WP4** (built): it empties `{space}manager/` only |
| S24 | A write-only key can overwrite a manifest; a copy from a dead source, shell disaster recovery and the management node's listings trust the bucket's manifest | **WP5** (built): write-once names, recorded hashes, one manifest per run |
| S25 | Linode Managed nodes are handed the delete-capable main key | **WP5** (built): no node is handed any key |
| S26 | The setup wizard's target form posted no Enabled box, so the target it saved was disabled, never tested and never scheduled | **WP1**: the wizard draws the shared form, which saves it enabled |
| S27 | The node Backups tab listed the whole target capped at 500 objects and then filtered to the node, so a node past the first 500 showed no cloud backups | **WP1**: listed under the node's own folder |
| S28 (B32) | A delete on a versioned bucket (every Backblaze bucket) only hides the object: pruned backups (128 GB on dev) and deleted member files stay in the bucket | **Now** (10-09, ahead of WP8): `S3Signer::delete()` removes every version, so every caller deletes for good; the passes that prune also sweep what is already hidden (`HiddenVersionSweep`) |

## 12. Out of scope, deliberately

- **Copying backups between targets.** They age out (R3). An owner who must
  leave a provider *today* moves, waits one night for a full chain on the new
  target, and deletes the old target's draining space by hand from its page.
- **A bucket per customer** (`sentinel_managed_recovery.md` O5). Spaces are
  compatible with it: a bucket per customer is a target per customer holding
  one space, and §9's object lock applies to it unchanged.
- **A second simultaneous copy** (mirroring one owner to two targets). The
  model allows it later as two active spaces with different roles. This spec
  keeps one.
- **Signing manifests with a new key** (§6 explains why). The accepted cost: on
  a target without object lock, the ledger file in the bucket (F4) is only as
  trustworthy as the key that wrote it; with lock (F8) nobody can replace it.

## 13. Tests

- `tests/backups/storage_provider_catalogue_test.php` (safe): every list reads
  the catalogue; endpoint forms; addressing; region rules.
- `tests/backups/target_location_rules_test.php` (db): location read-only once
  used; disable/delete refusals with their sentences; no fallback.
- `tests/backups/destination_switch_test.php` (db, loopback S3 fixture with two
  buckets): site target switch. The old run stays listable, verifiable and
  prunable on its own target. The next run starts a full chain on the new
  target. The epoch envelope is written. A prune never touches the other bucket.
- `plugins/server_manager/tests/storage_spaces_test.php` (db, two fixture
  buckets): Move one owner, Move everyone off, draining kept until the new chain
  verifies, the ledger keeps pruned rows, adopting an unclaimed folder, the
  base-key overlap refusal, and reconcile reading the space's own target.
- `plugins/server_manager/tests/broker_managed_run_test.php` (db): a Managed run
  end to end through broker links. No credential appears in the job. Put to a
  completed key is refused. Hashes are recorded. The copy check refuses a
  manifest whose hash differs.
- `tests/integration/file_store_move_test.php` (db): Move files resumes, verifies
  hashes and flips rows; reads follow the row.
- Extend `shelf_broker_test.php`, `service_tenant_watch_test.php` and
  `services_tenants_test.php`, which all pin one target today, with a second
  target.
- `tests/backups/backup_safety_test.php` (db, loopback fixture): an old
  verified chain survives while newer unverified ones age (F1); a run that never
  went offsite is not swept (F2); a first prune pass deletes nothing and the
  second deletes only what both listed (F3); the ledger file is written and the
  broker refuses a key under `ledger/` (F4); a version-3 chain writes one
  manifest per run and a version-2 chain still restores (F9).
- `tests/backups/retention_live_test.php` (live, F5).
- `tests/backups/object_lock_test.php` (live, real B2 and Linode buckets with
  lock on): a locked object refuses delete and overwrite with the target's own
  key; the prune skips it before its date; the connection test refuses lock on
  an unlocked bucket (F8).
- `plugins/server_manager/tests/restore_new_machine_test.php` (db, loopback
  fixture standing in for a locked target): the upload record is rebuilt from
  the ledger files; a ledger file that differs from its recorded hash is
  refused; the flow is not offered on an unlocked target; the resealed data key
  opens the chain on the replacement (F7). Its live counterpart runs in the
  object-lock live test against a real locked bucket.
- A live gate before WP5 ships: one Managed node moved B2 → Linode → B2 on dev,
  with each step restored from.

## 14. Docs

- `docs/backups.md`: targets, destinations, spaces and moving; the safety rules
  (last good backup kept, prune confirmation, ledger files, one manifest per
  run, object lock); restore onto a new machine and when it is offered. Present
  tense.
- `docs/cloud_storage.md`: the file store as a target and Move files.
- `plugins/server_manager/docs/overview.md`: Where new backups go, the move
  actions, Managed runs through the broker.
- `services_phase2_platform.md` §3 and §10: a pointer to this spec for 2b and
  the shelf target.
