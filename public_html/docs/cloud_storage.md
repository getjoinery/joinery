# Cloud Storage

Customer-owned, S3-compatible object storage — **one private bucket** — with
offload organized into three layers: a **driver** (the byte backend), one
shared **engine + lifecycle** (the offload orchestration and admin flow), and a
per-consumer **profile** (which table, what an object-per-row looks like).
Every profile is private; the registry refuses any other.

## Overview

Cloud storage exists so a Joinery that is running out of disk can move files
to a bucket. **Only files people must be signed in to see move**: member
uploads, Drive files, sealed vault files (every blob with
`fbb_is_private = TRUE`) and inbound mail (every raw message). Public
uploads, photos, gallery and blog images and all their page-size copies,
theme and brand assets stay on this server. Nothing served on a page ever
lives in the bucket, so a page view is never a bucket transfer: a file in the
bucket is read only when a signed-in person opens it, and the bytes stream
through this server.

Each Joinery instance names one S3-compatible bucket (AWS S3, Backblaze B2,
Cloudflare R2, Wasabi, DigitalOcean Spaces, Linode, MinIO, etc.). **It must
be private.** Save proves it: a bucket that answers an anonymous read is
refused and nothing is stored. The customer carries the storage cost rather
than the platform.

Uploads themselves always land locally first. A scheduled task pushes
eligible files to the bucket on the next cron tick.

## Architecture

```
Upload arrives → File row + FileBlob created (fbb_storage_driver='local')
                                                     │
                                                     ▼
                                    Cron tick (every 15 min)
                                                     │
                                                     ▼
                            CloudOffloadRun (offload mode) iterates eligible blobs:
                              - private: fbb_is_private = TRUE
                              - fbb_storage_driver = 'local'
                              - fbb_sync_failed_count < 5
                                                     │
                            Push original + variants concurrently
                            Re-check eligibility
                              ├── still private → flip flag to 'cloud',
                              │                   delete local copies
                              └── went public → undo bucket pushes,
                                                leave blob at 'local'
```

Physical bytes live in a **`FileBlob`** (`fbb_file_blobs`), which a `File`
references; the storage driver, offload counters, and visibility class are blob
properties, shared by every file that references the blob. The per-blob
`fbb_storage_driver` flag is the source of truth. A misconfigured global setting
cannot strand existing bytes because each blob independently records where its
bytes live. A `cloud` blob is always a private blob.

## Unified offload architecture

The orchestration is table-agnostic. Three pieces in `includes/cloud_storage/`
do the work for every consumer:

- **`CloudOffloadEngine`** — `syncBatch(profile)` (local → cloud) and
  `reverseBatch(profile)` (cloud → local). It owns the bounded batch, the per-row
  advisory lock, the failure-count cap, and the ordering invariants: forward
  **pushes → reloads → flips the row to `cloud` → only then unlinks** local bytes;
  reverse **pulls to temp → commits the row to `local` → only then best-effort
  deletes** from the bucket. It resolves its driver from
  `CloudStorageDriverFactory::driver()`.
- **`CloudStorageLifecycle`** — the admin save/test/activate/health flow over
  the one store. It holds the binding-immutability guard, the Save check with
  the privacy gate, and the offload modes (below).
- **`StorageProfile`** — the per-consumer seam. It declares the table, the
  pkey/driver/failed-count/last-attempt columns, the visibility (always
  `private`), an `eligibilityWhere()` SQL gate, and the per-row object
  enumeration (`itemsForRow` for forward, `reverseItemsForRow` for pull-back —
  the latter computed from the row's key scheme **without** needing local
  bytes, since on pull-back none exist yet). `BlobStorageProfile` is the
  file-blob adapter over the `FileBlob` methods (keyed on `fbb_stored_name`),
  eligible only for `fbb_is_private = TRUE`.

A single scheduled task — **`CloudOffloadRun`** — drives offload for the whole
platform. Each tick it walks every declared `StorageProfile` (the registry) and
runs it in the store's current **mode**: `offload` pushes local → cloud,
`drain` pulls cloud → local, `idle` is skipped. A new offload consumer therefore
adds a `StorageProfile` and **zero** tasks. The task self-deactivates when the
store is neither offloading nor draining and no offloaded file remains; while
any does, it keeps running for the daily file-store check. (See *Offload modes*
below.)

### The rule: one private bucket, private things only

The bucket is private, and only private things move to it. Everything else
stays on this server. This is enforced, not advised:

- **At Save.** The check fetches a probe object anonymously; a bucket that
  answers is refused and nothing is stored (§ The Save check).
- **At the registry.** Every profile keeps `visibility()`, and
  `StorageProfileRegistry::all()` refuses any profile that does not answer
  `private` — logged and left out — so a future consumer cannot opt a public
  set into the bucket by declaring one.
- **At eligibility.** `BlobStorageProfile::eligibilityWhere()` is
  `fbb_is_private = TRUE`; a public blob is never a candidate.
- **At a visibility flip.** A private cloud blob that is made public is pulled
  back to this server by `FileBlob::flipVisibility()` before its record flips
  (§ Permission changes). A local public blob made private moves on the next
  tick.

There is no setting that makes a public file move.

`CloudStorageDriverFactory::driver()` resolves the driver, non-null only while
`cloud_storage_enabled` is on — the single signal "there is a usable,
proven-private store". Pull-back runs against a *disabled* store, so the
reverse path uses `driverWithFallback()` (the raw binding when the latch is
off) — a draining or paused store still has a driver. `binding()` is the one
setting map: endpoint, region, bucket, access key, secret key.

### The Save check

Save runs the check, in this order, and stores nothing on a fail
(`CloudStorageLifecycle::testConnection()`):

0. **Its own bucket, and what the key may do.** Decided before the network
   is touched (`includes/BucketCheck.php`, shared with the backup target
   forms). The bucket must not be any backup target's bucket: files and
   backups in one bucket means one deletion takes both, so the Save is
   refused and says which target holds it. On Backblaze the key states what
   it may do (`b2_authorize_account`'s `allowed`): a key pinned to another
   bucket is refused, one that opens every bucket on the account passes with
   a warning naming the backup buckets it also opens, and a key missing
   `listFiles`, `readFiles`, `writeFiles` or `deleteFiles` is refused naming
   it.
1. **Reach.** A `HeadBucket` call: the key can list the bucket. Pass means
   DNS, TCP/TLS, region, and credentials all work.
2. **Write.** A probe object lands at `<prefix>/_joinery_probe-<rand>.txt`.
3. **Private.** The gate. The probe is fetched **anonymously** (no
   credentials) at the bucket's own address — the exact URL a public bucket
   would serve; this is the sole sanctioned `url()` call. **Anonymous 2xx ⇒
   bucket is public ⇒ fail**: "This bucket is publicly readable; it cannot
   hold private files. Make it private at the provider and save again." Any
   denied/unreachable status (401/403/404/refused) ⇒ pass
   (`privacyVerdict()`, the unit-testable decision).
4. **Delete.** The probe goes, so permanent delete and retention work. On a
   refusal a yellow note says the key cannot delete; the check still counts
   as passing.

### Binding immutability (integrity guard)

The `(endpoint, bucket)` identity of the store is **immutable while it holds
any `cloud` row** (summed across every profile). A Save that would change the
bucket or endpoint while offloaded objects exist is rejected: pull them back
to local first (Disable and Pull Files Back to Local). Access-key rotation —
same `(endpoint, bucket)` — stays allowed.

### Offload modes

The store's direction each tick is its **mode**, derived from its settings
(`CloudStorageLifecycle::mode()`):

| Mode | When | Tick action |
|------|------|-------------|
| `offload` | `cloud_storage_enabled` on | push eligible local rows → bucket |
| `drain` | disabled + `cloud_storage_draining` set (Disable-and-Pull) | pull cloud rows → local until none remain, then clear the flag |
| `idle` | disabled, not draining (paused / unconfigured) | nothing; existing cloud rows keep serving |

The store has exactly one mode per tick, so a row can never ping-pong between
local and cloud — forward/reverse mutual-exclusion is **structural**, not an
enforced guard. Enabling sets offload mode and activates `CloudOffloadRun`;
pause sets idle; Disable-and-Pull sets the draining flag.

### An offloaded file is in backup storage before its local copy goes

The archives a backup takes carry no `cloud` blob (the runner hands the files
engine an exclude list naming every one, original and variants). Each is
copied to the backup storage **once** instead, encrypted, under
`objects/{epoch}/{name}.enc` — see `docs/backups.md` § Offloaded files in backup
storage. The tick is where the two meet. `CloudOffloadEngine::_sync_row()`,
after the flip to `cloud`, calls `BackupObjects::after_offload()` in place of
an unconditional unlink:

1. **Store.** When the site profile is enabled, the original is encrypted with
   the site epoch key and uploaded to the site's own backup storage, one object's
   ciphertext on disk for the length of the upload. A failed store leaves the
   row `cloud` with its bytes; the next site run's backup storage listing shows the
   object missing and stores it. Nothing is retried in the tick.
2. **Release.** The local bytes — original and variants — are unlinked only
   when **every enabled profile holds the object**: the site profile by the
   store that just succeeded (or its `held.json`), the manager profile by its
   `held.json`, written by the management node's runs. With no profile
   enabled the release is unconditional, and the file is served from the
   bucket alone.

"Enabled" is one fact, `BackupProfile::enabled()`: the site profile when a
target is enabled, the recovery key is proven and the backup type includes
files; the manager profile when this machine has joined a management node
*and* a manager run carrying the object store has been here (the
`objects/enabled` marker). A node whose management node does not run the
object store holds nothing for it.

So a file can sit on this server, offloaded but not yet released, for as long
as a backup that stores offloaded files has not run. That figure is on the
cloud-storage page's Status block and the Backups page's **Offloaded files**
box — "M files (X GB) waiting for the management node's backup before their
local copy is released" (`BackupObjectsStatus`: every `cloud` row whose
original is still on disk, one `stat` per row on page load, no column and no
cache) — and, because a count on a page is read by nobody, on every admin
page as a notice when it matters: `BackupObjectsNotice` stands when the
waiting bytes exceed 2 GB, or when anything waits for a backup whose newest
success is older than 7 days (or that has never succeeded) — "N files (X GB)
are waiting for the management node's backup, which last succeeded D days
ago. They stay on this server until it does." — and clears itself when
nothing waits. The thresholds are constants on the class.

When the file store and this site's backup target share an access key
(`cloud_storage_access_key` equals the target's), both pages also say so:
"Backup storage and the file store share one account; losing it loses both. A
management node's copy would survive it." The same-key test is the whole rule
— accounts are not detectable, keys are.

### The file store is checked daily

Once a file is offloaded, the bucket is the only place its bytes are served
from, and a file the bucket has lost is otherwise invisible until a visitor
gets a 404. So the tick asks: while any offloaded file exists — paused store
or not — `CloudStoreInventory::tick()` runs inside `runOffloadTick()` and, once a day,
HEADs every `cloud` blob in its bucket (`CloudStorageDriver::head()`), a
slice per tick (`TICK_BUDGET_SECONDS` of HEADs, then a cursor) so ten
thousand files never hold the scheduler. A file the bucket does not have, or
holds at a size other than its row records, goes on the missing list by name.

The check never calls a file missing on a bucket's silence: a tick pings the
store first and waits when it does not answer, and a HEAD that says absent is
asked once more before it counts. With no store bound nothing can be checked,
and the rows are counted as such.

The result is one settings row, `cloud_storage_inventory` (JSON: the pass in
progress, the last completed pass with its missing names, and what the last
Bring them back did). No schema. Both the cloud-storage page and the Backups
page read it (`CloudStoreInventoryPanel`) and say: "N offloaded files are
missing from the file store; the backup holds M of them" — M counted against
what the enabled backup profiles' shelves hold (`BackupObjects::held_sets()`),
or "known after its next run" when no profile has a run since the object store
shipped. The action beside it, **Bring them back**, is the object restore in
`missing` mode against the newest backup (see `docs/backups.md` § Restore):

- a site with a backup target of its own runs it here, in the background
  (`BackupObjectRestoreLauncher::start_newest()` → `utils/bring_back_objects.php`),
  with links it signs for itself and its own key;
- a site backed up only by its management node is told so, with the node's
  URL: the job is started from that node's Backups tab
  (`FleetObjectRestore::start()` through the `restore_objects` backup action);
- a site with neither is told nothing holds them.

A file brought home leaves the missing list at once; the daily pass corrects
the rest.

### Declarative profile registry

Profiles are **declared, not self-registered at runtime**, so the registry and
the immutability guard see them whether or not the owning plugin is active —
matching how the platform declares plugin settings and menus:

- **Core** profiles are listed in `storage_profiles.json` at the `public_html/`
  root; the class file lives at `includes/cloud_storage/<ClassName>.php`.
- **Plugin** profiles are listed under a `storage_profiles` key in the plugin's
  `plugin.json`; the class file lives at
  `plugins/<plugin>/includes/<ClassName>.php`.

Each manifest entry is just a class name — visibility comes from the
instantiated profile's `visibility()`, never the manifest. `StorageProfileRegistry`
reads the core manifest and scans every plugin's `plugin.json` on disk (active or
not), instantiates each class (no-arg constructor required), and refuses any
whose `visibility()` is not `private` (logged, left out).

A deactivated plugin leaves its files — and so its declaration and class — on
disk, so the guard still sees its cloud rows. **Uninstall** is the one gap,
closed by policy: uninstalling a plugin that owns a profile requires the store
**drained back to local first** (the same disable-and-pull flow the guard
points admins at).

### Add a new offload consumer

1. Implement `StorageProfile` (including `reverseItemsForRow` for pull-back),
   answering `'private'` from `visibility()` — the registry refuses anything
   else. Its rows must be things people must be signed in to see.
2. Declare the class name in `storage_profiles.json` (core) or a plugin's
   `plugin.json` `storage_profiles` array.

The consumer writes no offload, admin, or bucket config: request-time byte
I/O (upload/ingest/serve) stays the consumer's own code — its gated read
calls `CloudStorageDriverFactory::driverWithFallback()->get()` and streams
behind its own permission check.

**A table with rows that are not the profile's.** A profile may also implement
an optional `reverseEligibilityWhere(): string` — a SQL fragment naming which
`cloud` rows of its table are its own. It is needed when the table also holds
rows that never move (`fbb_file_blobs` holds public blobs, which stay local);
the engine and the binding-immutability count probe it via `method_exists()`,
so a profile that owns its table outright simply omits it. The forward offload
is already partitioned by `eligibilityWhere()`; this gate partitions the
reverse/drain and the cloud-row count.

### Private files

Any file that carries a restriction — `fil_min_permission`, `fil_grp_group_id`,
`fil_access_provider`, `fil_tier_min_level`, or `fil_private` — is private (the
inverse of `File::is_public()`). A file's visibility is recorded on its blob as
`fbb_is_private` (kept in step with the referencing files by the dedup scoping
and copy-on-write split — see [File Signed URLs](file_signed_urls.md) and the
blob layer). `BlobStorageProfile` drains restricted blobs to the bucket. So a
group doc, event handout, tier-gated download, Drive file or email attachment
offloads instead of pinning to local disk — draining a small VPS that would
otherwise fill with private uploads. A public blob is never eligible.

Serving stays gated: a private file's `get_url()` returns the local
`/uploads/...` path (never a bucket URL), and `serve.php` runs
`File::is_viewable()` before streaming the bytes from the bucket through
PHP. No File column drives placement — whether a blob may be `cloud` at all is
`fbb_is_private`.

`fil_private` is a distinct restriction mode from the other four: it isn't a
threshold or membership check, it's an **owner-or-admin** rule — only the
file's owner (`fil_usr_user_id`) or an admin (permission ≥ 5) can view it.
`File::is_viewable()` and `SystemBase::authenticate_read()`/
`authenticate_write()` (the record-access gate) share one `is_owner_or_admin()`
helper, so the rule for opening a record and the rule for streaming its file
bytes can never drift apart. This is the only restriction mode that can express
"visible to a specific permission-0 user and nobody else" — a plain
`fil_min_permission` threshold can't, since any value that admits the owner
also admits every other user at that level. The trade-off: it's coarse for
admins (any admin can view any owner-or-admin private file) and it can't
express sharing among several non-admin users — a consumer needing that would
require a heavier per-set membership mechanism this platform doesn't build.

`BlobStorageProfile` declares a `reverseEligibilityWhere()` ownership gate
(`fbb_is_private = TRUE`) so the reverse/drain path and the binding-immutability
count touch only the cloud rows, never the public blobs that share the table.
Enabling or draining the store acts on every profile, so the blob profile and
the inbound-mail raw store light up together. No per-profile task is involved —
the single `CloudOffloadRun` tick drives every profile by the store's mode.

## Bucket Layout

```
<site_template>/<stored_name>            ← original
<site_template>/<size>/<stored_name>     ← variants (thumb, avatar, ...)
```

`<stored_name>` is the blob's `fbb_stored_name` — the physical identity shared
by every file that references the blob (files reference it by `fil_name`, which
is the URL identity and equals the stored name for a fresh, non-deduped upload).

The `<site_template>` prefix is derived automatically from the
`site_template` setting (e.g. `joinerytest`). Multiple Joinery instances
can safely share one bucket — each gets its own prefix without any
configuration. The prefix is intentionally not configurable: changing it
would orphan every existing object in the bucket.

The bucket must be **private**. The customer applies that policy at bucket
creation; the platform never tries to set it, and Save refuses a bucket that
is not.

## Settings

All configured via the admin page at `/admin/admin_cloud_storage`.
Stored in `stg_settings`:

| Setting | Required | Notes |
|---------|----------|-------|
| `cloud_storage_provider` | yes | Which provider the bucket is with: `generic` (the default; MinIO and any S3 compatible service), `b2`, `s3`, `r2`, `wasabi`, `digitalocean`, `linode`. Decides which of the next two the form asks for (§ Providers). |
| `cloud_storage_endpoint` | yes | Hostname or full URL, e.g. `s3.us-west-002.backblazeb2.com`. Asked for a generic bucket and Cloudflare R2; named by the provider otherwise. |
| `cloud_storage_region` | yes | `us-east-1`, `us-west-002`, etc. Asked for a generic bucket, Amazon, Wasabi, DigitalOcean and Linode; `auto` for R2; from the key for Backblaze. Auto-fills on endpoint blur for a generic endpoint that says. |
| `cloud_storage_bucket` | yes | Bucket name. A private bucket used for nothing else; not the backup bucket. Save refuses a bucket anyone can read. |
| `cloud_storage_access_key` | yes | API key / access key ID. |
| `cloud_storage_secret_key` | yes | API secret. |
| `cloud_storage_enabled` | internal | Latched by the Save flow when the check passes; cleared by Pause, Disable and Remove. |
| `cloud_storage_draining` | internal | Set by Disable and Pull Files Back to Local; cleared by the tick when no cloud row remains. |
| `cloud_storage_inventory` | internal | The daily file-store check's record (JSON): the pass in progress, the last completed pass and its missing names, the last Bring them back. Written by the tick and the launcher; read by the cloud-storage and Backups pages. |

### Providers

The form is headed by a provider picker (`includes/StorageProvider.php`, the
one catalogue of what each provider asks for and names). Only the fields the
provider needs are shown; the rest are settled at Save, before the
diagnostic runs, and stored like any other binding:

| Provider | Asks for | Endpoint | Region |
|----------|----------|----------|--------|
| Generic S3 compatible bucket (default) | endpoint, region | as typed | as typed, may be empty |
| Backblaze B2 | nothing beyond the bucket and key | `s3.<region>.backblazeb2.com`, named by `b2_authorize_account` for the key | from that endpoint |
| Amazon S3 | region | `s3.<region>.amazonaws.com` | as typed |
| Cloudflare R2 | endpoint (`<account-id>.r2.cloudflarestorage.com`) | as typed | `auto` |
| Wasabi | region | `s3.<region>.wasabisys.com` | as typed |
| DigitalOcean Spaces | region (the datacenter) | `<region>.digitaloceanspaces.com` | as typed |
| Linode Object Storage | region (the cluster) | `<region>.linodeobjects.com` | as typed |

A missing region or endpoint is refused naming the provider; a Backblaze
key the service refuses is refused with its reason. The show/hide is
`show_when` on the two declarations. A store saved with `generic` whose
endpoint belongs to a recognised provider shows as that provider.

This page is the only place the cloud storage settings are drawn. The core
Settings page links here and to Backups instead of drawing either group,
because a plain settings save would store a bucket and key nobody proved.

### Auto-Derivations

- **Path-style vs virtual-hosted addressing.** Derived from the endpoint
  hostname. `*.amazonaws.com` → virtual-hosted; everything else → path-style.
- **The bucket's own address**, which `url()` builds on and only the privacy
  gate's anonymous probe fetches:
  - AWS virtual-hosted: `https://{bucket}.s3.{region}.amazonaws.com`
  - Path-style: `https://{endpoint_host}[:port]/{bucket}`

## Admin UI

`/admin/admin_cloud_storage` (System → Cloud Storage) opens with a note on
what moves — only files people must be signed in to see: private uploads,
Drive files and inbound mail; public images and downloads stay on this
server, so nothing on a page is served from the bucket — then shows the
store in one of three shapes, after a status block:

- **Nothing configured** — the setup form: provider, then endpoint and
  region as the provider asks (§ Providers), bucket, access key, secret
  key. **Save** runs the check against what was typed before persisting
  anything; on pass it saves, sets `cloud_storage_enabled = true` (offload
  mode) and activates the `CloudOffloadRun` task; on fail it shows the
  per-step result and persists nothing.
- **Configured** — what is stored, read-only (provider, endpoint, region,
  bucket, access key, whether a secret is stored, files in the bucket),
  with a state line (active, off, pulling files back) and the actions that
  fit it:
  - **Pause** (when active): stops offloading new files; files already in
    the bucket keep being served from it.
  - **Enable** (when off): re-proves the stored settings with the same
    check and switches offloading back on.
  - **Disable and Pull Files Back to Local** (when active, or off with
    files in the bucket): sets the draining flag, so `CloudOffloadRun`
    pulls every bucket-stored file back to local and clears the flag when
    done. The confirmation shows the count and free disk.
  - **Remove** (off, nothing in the bucket, nothing draining): forgets
    the bucket and key. Refused while any file is in the bucket or on its
    way back.
- **What may change.** While any file is in the bucket, or a pull-back is
  running, the provider, endpoint, region and bucket are locked — the file
  records point at objects there — and the form below the summary carries
  only the access key and secret key. A field the form does not post keeps
  its stored value. With nothing in the bucket the whole configuration is
  under a **Change settings** fold, open after a failed save. Every Save
  runs the check first.

The status block is one sentence for the state — not set up, off, active
or pulling files back — with the file counts and sizes and the last run
folded into it, then a line each only for what needs attention or is worth
knowing: cron not running, the bucket not answering, a failed last run,
files stuck after 5+ failed moves (with a per-row **Retry**), the file-store
check when one has run (what it found, **Bring them back**, and what the
last Bring them back did — § The file store is checked daily), files
waiting for a backup before their local copy is released, and the
same-account line when the file store and backup storage share an access
key (§ An offloaded file is in backup storage before its local copy goes).
A healthy driver and a task whose last run succeeded say nothing.

The steps a failed Save shows are those of § The Save check.

## Migration

### Forward (local → bucket)

The `CloudOffloadRun` tick in **offload mode is** the forward migration. When
cloud storage is first enabled, the batch query naturally selects every private
local file and the tick drains them across cron ticks until the queue is empty.
There is no separate migration task.

Migration starts on the next regular cron tick (within 15 minutes). To
start sooner, click "Run Now" on the Scheduled Tasks admin page.

The task is bounded per run (50 rows or 60 seconds, whichever first).
Failures increment `fbb_sync_failed_count`; after 5 consecutive failures
a blob is excluded from the batch query and surfaces in the admin UI as
"stuck." The "Retry" button resets the counter and re-queues the blob.

### Reverse (bucket → local)

**Drain mode** is entered only by the "Disable and Pull Files Back to Local"
button (which sets the draining flag). `CloudOffloadRun` then pulls every cloud
row back. Per-row, three phases:

1. Pull bytes to a temp dir.
2. Place files into the correct local dir (per the blob's `fbb_is_private`),
   commit `fbb_storage_driver = 'local'`.
3. Best-effort bucket delete. Failures here are logged with
   `CLOUD_STORAGE_ORPHAN: bucket=<name> keys=<...>`; the row is
   correctly served locally regardless. Manual cleanup with `aws s3 rm`
   or equivalent.

Self-deactivates when no more `'cloud'` rows remain.

## Permission Changes

When a file's `is_public()` flips, its bytes must end up where the new
visibility allows — public bytes on this server, in the fast-serve directory;
private bytes in the restricted directory, and from there to the bucket on the
next tick — so nothing public is ever served from the bucket, and a restricted
file is never reachable by a world-readable URL.

### A cloud-stored file made public

`File::save()` / `soft_delete()` / `undelete()` call `move_to_correct_directory()`,
which compares the file's desired visibility class to its blob's current one
(`fbb_is_private`). When the blob is referenced by exactly one file (refcount 1)
and cloud-resident, its bytes are pulled back to local **before its record
flips** (`FileBlob::flipVisibility()` → the cloud pull-back), three explicit
phases (when the blob is shared, a copy-on-write split gives the changed file
its own blob instead):

1. **Pull all bytes to a temp dir.** Failure: drop temps, leave bucket and DB
   unchanged, throw.
2. **Delete from bucket** with brief retries. Any failure: re-PUT
   successfully-deleted keys from temps (best-effort), drop temps, throw.
3. **Copy temps to the fast-serve dir, commit the row to `'local'` and
   `fbb_is_private = false` in one transaction.** Failure: re-PUT all temps to
   the bucket so the row's `'cloud'` flag stays truthful, log
   `CLOUD_STORAGE_PARTIAL_FLIP`. If re-PUT also fails, the row is genuinely
   broken; the log marker is the breadcrumb.

The blob is local and public by the time its record says public, and it is
never eligible again while public. Invariants: bucket is authoritative until DB
commit; temps live until DB commit so they remain rollback material. Peak
local disk during a flip ≈ 2× total file size.

### A local file whose visibility flips

The directory move (`static_files/uploads/` ⇄ restricted `uploads/`) happens in
the save path; the row stays `'local'`. A file made private is eligible, and
the next tick offloads it; a file made public stays where it is. No request
blocks on bucket I/O.

## URL Generation

`File::get_url($size_key, $format)` always mints a local `/uploads/...` URL,
whatever the blob's driver. A public file is a local file, served by the fast
path; a private file's URL routes through `serve.php`'s gate, which streams the
bytes — from the bucket when the blob is `cloud`. A bucket URL is **never**
emitted: the "never `url()`" rule, enforced at the model.

### /uploads/* serving

`serve.php`'s `/uploads/*` route resolves the `File` and dispatches:

- **Cloud row** (always a private file) — `is_viewable($session)` first (fail
  ⇒ 404, never 403, so existence isn't confirmed); then the bytes are pulled
  from the bucket to a temp file and streamed via `File::serve_from_path()`.
  A Range request for an unsealed original is answered by a ranged bucket
  read. Never a 302: the bucket URL is never exposed and the gate runs on
  every request.
- **Local row** — `is_viewable($session)`, then `File::serve_from_path()` with
  a cacheable posture (`public` for unrestricted rows, `private` for gated
  ones). Public rows are normally served earlier by the pre-boot fast path
  (`RouteHelper`), which cannot load the `File` model and applies its own
  conservative header backstop.

Every gated or signed stream goes through `File::serve_from_path()`, which owns
the serve-back header set: the stored magic-byte-detected `Content-Type`,
`X-Content-Type-Options: nosniff`, the caller's `Cache-Control` posture, and
`Content-Disposition: attachment` for everything except the inline-safe raster
allowlist (`File::is_inline_safe_type()`: png/jpeg/gif/webp/avif). Any other
type — including `image/svg+xml` — is served as a download, so a script-bearing
SVG can never render inline from our origin.

## Bucket Policy / Setup

Every bucket is **private**. Save refuses one that is not.

### Backblaze B2

1. Create a bucket; set its type to **Private**.
2. Create an application key scoped to that bucket with `listFiles`,
   `readFiles`, `writeFiles` and `deleteFiles`.
3. Pick **Backblaze B2** on the form and enter the bucket and the key. The
   endpoint (`s3.<region>.backblazeb2.com`) and region come from the key.

### AWS S3

1. Create a bucket; keep **Block all public access** on.
2. Create an IAM user with a policy granting `s3:ListBucket`, `s3:GetObject`,
   `s3:PutObject` and `s3:DeleteObject` on the bucket; use its access key and
   secret in the admin form.
3. Pick **Amazon S3** on the form and enter the bucket's region; the
   endpoint `s3.<region>.amazonaws.com` follows from it.

### Cloudflare R2

1. Create an R2 bucket. Attach no public domain and leave the public bucket
   URL off.
2. Create an API token with object read and write on the bucket.
3. Pick **Cloudflare R2** on the form and enter the account's endpoint,
   `<account-id>.r2.cloudflarestorage.com`. The region is `auto`.

### Wasabi, DigitalOcean Spaces, Linode Object Storage, MinIO

1. Create a private bucket (no public listing or file-level public read).
2. Create a key with list, read, write and delete on the bucket.
3. Pick the provider and enter the region (the datacenter or cluster), or
   for MinIO and any other S3-compatible service pick **Generic** and enter
   the endpoint.

## Provider Compatibility

| Provider | Status | Notes |
|----------|--------|-------|
| Backblaze B2 (S3 API) | Verified | Path-style endpoint. |
| AWS S3 | Verified | Virtual-hosted-style preferred. Reference implementation. |
| Cloudflare R2 | Should work, unverified | |
| Wasabi | Should work, unverified | Pick it and enter the region. |
| DigitalOcean Spaces | Should work, unverified | Pick it and enter the datacenter. |
| Linode Object Storage | Should work, unverified | Pick it and enter the cluster. |
| MinIO (self-hosted) | Should work, unverified | Path-style. Useful for development. Generic: enter the endpoint. |

## Failure Modes

| Mode | Behavior | Recovery |
|------|----------|----------|
| Sync push fails | `fbb_sync_failed_count` increments and `fbb_sync_last_error` says why; next cron tick retries. After 5 failures the blob is excluded and surfaces as "stuck", with its last error. | Click Retry on the stuck-files list. |
| A record has no bytes on this server | Nothing to move: the record is parked at once (count at the cap, reason "no bytes on this server") and the run is not an error. The page lists it apart from stuck files, without Retry. Such a record dates from before the bucket was set up: a file deleted or lost before its blob record was made. | Permanently delete the file; that releases the record. |
| Credentials become invalid | Driver health-check goes red; the offload tick fails every row. New uploads keep landing locally. | Save again with fixed creds. |
| Bucket runs out of quota / billing failure | Sync task fails; uploads continue locally. | Resolve at the provider; sync resumes. |
| `permanent_delete` bucket-delete fails | Logged as `CLOUD_STORAGE_ORPHAN`; row is still deleted. | Manual cleanup via `aws s3 rm` or equivalent. |
| Flip-to-public phase 3 fails | Logged as `CLOUD_STORAGE_PARTIAL_FLIP`. | Manual recovery: flip the row to `'local'` and re-upload. |
| File becomes public during async push | Detected by re-check after PUTs; just-pushed objects deleted; row stays local. | Automatic. |
| Backup stops running while files offload | Local copies of offloaded files stay on this server (the release rule above); the Status block and the Backups page count them, and the admin notice stands past 2 GB or a week without a successful backup. | Fix the backup; the next successful run releases them. |
| Bucket loses an offloaded file | The daily file-store check names it on the cloud-storage and Backups pages: "N offloaded files are missing from the file store; the backup holds M of them". | **Bring them back** restores it from backup storage (this site's own, or the management node's job). |

## File-by-File Architecture

| File | Role |
|------|------|
| `includes/cloud_storage/CloudStorageDriver.php` | Interface (put/get/get_range/head/delete/url/ping). `head()` is the presence check — size and ETag, or `null` — the backup's object restore asks before bringing a file home. `url()` is the bucket's own address for an object, fetched only by the privacy gate. |
| `includes/cloud_storage/CloudStorageS3Driver.php` | Sole implementation. Handles AWS, B2, R2, Wasabi, etc. |
| `includes/cloud_storage/CloudStorageDriverFactory.php` | `driver()` returns the configured driver or `null` (latch honoured); `driverUnlatched()` builds from the raw binding for pull-back; `driverWithFallback()` is the resolver for request-time byte I/O; `binding()` is the setting map; `fromOptions()` builds from explicit settings. |
| `includes/cloud_storage/StorageProfile.php` | The per-consumer seam interface. |
| `includes/cloud_storage/StorageProfileRegistry.php` | Reads `storage_profiles.json` + every plugin's `plugin.json` `storage_profiles` (on disk, active or not); instantiates each, refusing any that is not private. |
| `storage_profiles.json` | Core profile manifest (declares `BlobStorageProfile`). |
| `includes/cloud_storage/CloudOffloadEngine.php` | Table-agnostic forward/reverse batch + per-row logic; reverse/count honour the optional `reverseEligibilityWhere()` ownership gate. |
| `includes/cloud_storage/CloudStorageLifecycle.php` | Shared admin save/test/health + the binding-immutability guard + the Save check with the privacy gate verdict; owns the offload modes (`mode`, `startDrain`/`stopDrain`, `ensureTickActive`) and the `runOffloadTick()` orchestration; cloud-row counts scoped via the ownership gate. |
| `includes/cloud_storage/CloudStoreInventory.php` | The daily file-store check: `tick()` HEADs every cloud blob a slice per tick and writes the missing names to the `cloud_storage_inventory` setting; `summary()`/`sentence()` are what the pages say; `note_bring_back()`/`forget_missing()` are the launcher's marks. |
| `includes/cloud_storage/CloudStoreInventoryPanel.php` | The block both pages render from that record: when it last looked, the sentence, **Bring them back** (or who runs it), the last Bring them back. `source()` says who brings a file back on this site. |
| `includes/BackupObjectsStatus.php` | The figures about offloaded files and backup storage: what each enabled backup holds, what waits here (one `stat` per cloud row), what is still to copy from the file store, the same-account test; the sentences both pages show. Reads this machine only. |
| `includes/BackupObjectsNotice.php` | The admin-header notice when waiting bytes pass 2 GB or anything waits on a backup a week without a success; a core `AdminNotices` renderer beside `SiteBackupNotice`. |
| `includes/BackupObjectRestoreLauncher.php` | This site's own Bring them back: reads the newest run's index off its backup storage, surveys, signs its own links a page at a time and runs `BackupObjectRestore`; `start_newest()` detaches `utils/bring_back_objects.php`. |
| `includes/cloud_storage/BlobStorageProfile.php` | The blob adapter over the `FileBlob` methods: private blobs only (`fbb_is_private = TRUE`), original plus variants. |
| `data/file_blobs_class.php` | The physical `FileBlob` (`fbb_file_blobs`): stored bytes, refcount, offload state, visibility flip / copy-on-write split, and all cloud methods (`resize()`, `delete_resized()`, pull-back, cloud delete). |
| `data/files_class.php` | The logical `File`: identity, ownership, visibility gates, signed URLs. Physical operations delegate to its `FileBlob` (`get_url()`, `permanent_delete()` → `FileBlob::release()`, `move_to_correct_directory()` → flip / copy-on-write split). |
| `tasks/CloudOffloadRun.php` | The one offload task for the whole platform. Calls `CloudStorageLifecycle::runOffloadTick()`, which drives every store by mode (offload/drain/idle) and gives the daily file-store check its slice; self-deactivates when nothing is offloading or draining and no offloaded file remains. |
| `adm/admin_cloud_storage.php` | Admin UI. Save = check + persist + activate; the check's steps shown inline on a fail. |
| `adm/logic/admin_cloud_storage_logic.php` | Thin caller over `CloudStorageLifecycle`; Save/Pause/Disable-and-Pull/Remove/Retry handlers; `bring_back_objects` starts this site's own Bring them back. |
| `serve.php` | `/uploads/*` route: resolves the file's blob, gate-streams cloud bytes through PHP after `is_viewable()` (never a redirect to the bucket); resolves the local path through the blob (so a dedup secondary finds the shared bytes). |
| `includes/UploadHandler.php` | `get_unique_filename()` consults active `fil_name` rows and `fbb_stored_name` so a landing name never collides with a live file or an offloaded blob object. |
| `utils/process_scheduled_tasks.php` | Per-task advisory locking (prereq for the offload tick — prevents tick-overlap races). |

## Out of Scope (v1)

- Per-tenant / per-user buckets within a single Joinery instance.
- Bucket-level encryption configured via the admin UI (customer
  responsibility at bucket creation).
- Resize-on-demand (variants still generated upfront).
- Signed-URL serving for private files — server-side streaming only; a
  short-lived signed link straight from the bucket is a later, separate piece
  of work, additive to the driver (the file signed URL machinery exists for
  it).
