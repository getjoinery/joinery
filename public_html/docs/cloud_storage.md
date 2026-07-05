# Cloud Storage

Customer-owned, S3-compatible object storage with offload organized into four
layers: a **driver** (the byte backend), one shared **engine + lifecycle** (the
offload orchestration and admin flow), **visibility** (public vs private, a
property of the *store*), and a per-consumer **profile** (which table, what an
object-per-row looks like, which visibility). The public-files consumer is the
first consumer; new consumers add only a profile.

## Overview

Each Joinery instance can be configured with one S3-compatible account
(AWS S3, Backblaze B2, Cloudflare R2, Wasabi, DigitalOcean Spaces, MinIO,
etc.). The platform offers two stores on that one account: a **public** store
(world-readable by key) and a **verified-private** store (a separate bucket,
proven non-public before any byte lands). Public uploads (photos, gallery
images, blog images) are asynchronously moved to the public store; the customer
carries the storage cost rather than the platform.

Uploads themselves are unchanged — they always land locally first. A scheduled
task pushes eligible files to the store on the next cron tick. The private store
ships inert until an admin configures and gates a private bucket; the public
files consumer never uses it (private files stay on local disk).

## Architecture

```
Upload arrives → UploadHandler → File row created (fil_storage_driver='local')
                                                     │
                                                     ▼
                                    Cron tick (every 15 min)
                                                     │
                                                     ▼
                            CloudOffloadRun (offload mode) iterates eligible rows:
                              - public per is_public()
                              - fil_storage_driver = 'local'
                              - fil_sync_failed_count < 5
                                                     │
                            Push original + variants concurrently
                            Re-check is_public()
                              ├── still public → flip flag to 'cloud',
                              │                  delete local copies
                              └── went private → undo bucket pushes,
                                                 leave row at 'local'
```

The per-row `fil_storage_driver` flag is the source of truth. A
misconfigured global setting cannot strand existing files because each
row independently records where its bytes live.

## Unified offload architecture

The orchestration is table-agnostic. Three pieces in `includes/cloud_storage/`
do the work for every consumer:

- **`CloudOffloadEngine`** — `syncBatch(profile)` (local → cloud) and
  `reverseBatch(profile)` (cloud → local). It owns the bounded batch, the per-row
  advisory lock, the failure-count cap, and the ordering invariants: forward
  **pushes → reloads → flips the row to `cloud` → only then unlinks** local bytes;
  reverse **pulls to temp → commits the row to `local` → only then best-effort
  deletes** from the bucket. It is visibility-blind — it resolves its driver from
  `forVisibility($profile->visibility())`.
- **`CloudStorageLifecycle`** — the admin save/test/activate/health flow,
  parameterized by store (visibility) and profile. It holds the per-visibility
  setting bindings, the binding-immutability guard, and the offload modes (below).
- **`StorageProfile`** — the per-consumer seam. It declares the table, the
  pkey/driver/failed-count/last-attempt columns, the visibility, an
  `eligibilityWhere()` SQL gate, and the per-row object enumeration
  (`itemsForRow` for forward, `reverseItemsForRow` for pull-back — the latter
  computed from the row's key scheme **without** needing local bytes, since on
  pull-back none exist yet). `FileStorageProfile` is the public-files adapter
  over the existing `File` methods.

A single scheduled task — **`CloudOffloadRun`** — drives offload for the whole
platform. Each tick it walks every declared `StorageProfile` (the registry) and
runs each store in its current **mode**: `offload` pushes local → cloud,
`drain` pulls cloud → local, `idle` is skipped. A new offload consumer therefore
adds a `StorageProfile` and **zero** tasks. The task self-deactivates when no
store is offloading or draining. (See *Offload modes* below.)

### Visibility — the two stores

Visibility is a property of the store, owned by the storage layer. A profile
names one; the layer maps it to a bucket binding, a read posture, and a guarantee.

| Visibility | Bucket | Read posture | Guarantee |
|------------|--------|--------------|-----------|
| `public` | `cloud_storage_bucket` | public URL (PHP-bypassed) | none — world-readable by key |
| `private` | `cloud_storage_private_bucket` (same endpoint/region/keys) | server-side gated stream; never `url()` | bucket **verified non-public** before any byte lands |

`CloudStorageDriverFactory::forVisibility($v)` resolves the driver: `public` is
the retained `default()` path; `private` returns non-null only once the privacy
gate has latched `cloud_storage_private_enabled` true. Read mode is **derived**
from visibility — `private` ⇒ gated stream, always. Pull-back runs against a
*disabled* store, so the reverse path falls back to
`forVisibilityUnlatched($v)` (the raw binding, latch ignored) — a draining store
still has a driver.

### The privacy hard-gate

The private bucket is **not a separate account** — it is one more bucket on the
existing credentials, configured by a single field (`cloud_storage_private_bucket`)
on `/admin/admin_cloud_storage`. Before it is usable, a Save runs the gate:

1. PUT a scratch probe with platform credentials.
2. Fetch that key **anonymously** (no credentials) via the bucket's direct URL —
   the exact URL a misconfigured-public bucket would serve. This one-time probe
   is the sole sanctioned `url()` call on a private store.
3. **Anonymous 2xx ⇒ bucket is public ⇒ gate FAILS** (the latch is not set, and
   the admin is told to make the bucket private and re-test). Any denied/
   unreachable status (401/403/404/refused) ⇒ gate PASSES and the latch is set.
4. DELETE the probe.

Until the gate passes, `forVisibility('private')` returns null and no private
bytes can be written. Each store's Save is validated **independently** — a
private-bucket failure never blocks the public Save, and vice versa.

### Binding immutability (integrity guard)

The `(endpoint, bucket)` identity of a store is **immutable while that store
holds any `cloud` row** (summed across every profile of that visibility). A Save
that would change the bucket or endpoint of a store with offloaded objects is
rejected: pull them back to local first (Disable and Pull Files Back to Local).
Access-key rotation — same `(endpoint, bucket)` — stays allowed.

### Offload modes

A store's direction each tick is its **mode**, derived from store-level settings
(`CloudStorageLifecycle::modeForVisibility()`):

| Mode | When | Tick action |
|------|------|-------------|
| `offload` | store enabled latch on | push eligible local rows → bucket |
| `drain` | disabled + draining flag set (Disable-and-Pull) | pull cloud rows → local until none remain, then clear the flag |
| `idle` | disabled, not draining (paused / unconfigured) | nothing; existing cloud rows keep serving |

A store has exactly one mode per tick, so a row can never ping-pong between local
and cloud — the old forward/reverse mutual-exclusion is **structural** now, not an
enforced guard. Enabling a store sets it to offload mode and activates
`CloudOffloadRun`; pause sets idle; Disable-and-Pull sets the draining flag.

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
not), instantiates each class (no-arg constructor required), and groups by
visibility.

A deactivated plugin leaves its files — and so its declaration and class — on
disk, so the guard still sees its cloud rows. **Uninstall** is the one gap,
closed by policy: uninstalling a plugin that owns a `private` profile requires
the store **drained back to local first** (the same disable-and-pull flow the
guard points admins at).

### Add a new offload consumer

1. Implement `StorageProfile` (including `reverseItemsForRow` for pull-back).
2. Declare the class name in `storage_profiles.json` (core) or a plugin's
   `plugin.json` `storage_profiles` array.
3. Choose a `visibility()` — `'public'` or `'private'`.

The bucket, read posture, privacy guarantee, and reverse-driver fallback all
follow from the visibility. The consumer writes no offload, admin, or bucket
config: request-time byte I/O (upload/ingest/serve) stays the consumer's own
code — a private consumer's gated read calls
`CloudStorageDriverFactory::forVisibility('private')->get()` and streams behind
its own permission check.

**Sharing a table between two stores.** A profile may also implement an optional
`reverseEligibilityWhere(): string` — a SQL fragment naming which `cloud` rows of
its table physically live in **its** bucket. It is needed only when more than one
profile shares a table (the public and private `File` profiles both live on
`fil_files`); the engine and the binding-immutability count probe it via
`method_exists()`, so a profile that owns its table outright simply omits it. The
forward offload is already partitioned by `eligibilityWhere()`; this gate
partitions the reverse/drain and the cloud-row count.

### Private files

Any file that carries a restriction — `fil_min_permission`, `fil_grp_group_id`,
`fil_evt_event_id`, `fil_tier_min_level`, or `fil_private` — is private (the
inverse of `File::is_public()`). Two `File` profiles share the `fil_files`
table: `FileStorageProfile` (`visibility = public`) drains world-readable files
to the public bucket; `FilePrivateStorageProfile` (`visibility = private`)
drains restricted files to the verified-private bucket. So a group doc, event
handout, tier-gated download, or email attachment offloads to the bucket like
any public upload instead of pinning to local disk — draining a small VPS that
would otherwise fill with private uploads.

Serving stays gated: a private file's `get_url()` returns the local
`/uploads/...` path (never a bucket URL), and `serve.php` runs
`File::is_viewable()` before streaming the bytes from the private bucket through
PHP. No new column drives placement — the store a `cloud` row belongs to is
derived from `is_public()`.

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

Because both profiles live on `fil_files`, each declares a
`reverseEligibilityWhere()` ownership gate (`public` = `is_public()`; `private` =
its complement, including soft-deleted rows, which are kept in the private bucket
and never served) so the reverse/drain path and the binding-immutability count
touch only the cloud rows in their own bucket. Enabling or draining a store acts
on every profile of that visibility, so the private `File` store and the
inbound-mail raw store light up together. No per-store task is involved — the
single `CloudOffloadRun` tick drives the private `File` profile by mode like
every other store.

## Bucket Layout

```
<site_template>/<filename>            ← original
<site_template>/<size>/<filename>     ← variants (thumb, avatar, ...)
```

The `<site_template>` prefix is derived automatically from the
`site_template` setting (e.g. `joinerytest`). Multiple Joinery instances
can safely share one bucket — each gets its own prefix without any
configuration. The prefix is intentionally not configurable: changing it
would orphan every existing object in the bucket.

The bucket must be **publicly readable**. The customer applies that
policy at bucket creation; the platform never tries to set it.

## Settings

All configured via the admin page at `/admin/admin_cloud_storage`.
Stored in `stg_settings`:

| Setting | Required | Notes |
|---------|----------|-------|
| `cloud_storage_endpoint` | yes | Hostname or full URL, e.g. `s3.us-west-002.backblazeb2.com`. |
| `cloud_storage_region` | yes | `us-east-1`, `us-west-002`, etc. Auto-fills on endpoint blur if recognizable. |
| `cloud_storage_bucket` | yes | Bucket name. |
| `cloud_storage_access_key` | yes | API key / access key ID. |
| `cloud_storage_secret_key` | yes | API secret. |
| `cloud_storage_public_base_url` | no | Base URL for public reads. **Leave empty unless you have a CDN or custom domain.** Auto-derived from endpoint+bucket otherwise. |
| `cloud_storage_enabled` | internal | Flipped by the Save flow when Test Connection passes. |
| `cloud_storage_private_bucket` | no | A separate bucket on the **same** account (shares endpoint/region/keys) for the verified-private store. Empty = no private store. |
| `cloud_storage_private_enabled` | internal | Latched true only after a private-bucket Save whose anonymous-read-denied gate passed. Not edited directly. |

### Auto-Derivations

- **Path-style vs virtual-hosted addressing.** Derived from the endpoint
  hostname. `*.amazonaws.com` → virtual-hosted; everything else → path-style.
- **Public base URL** when empty:
  - AWS virtual-hosted: `https://{bucket}.s3.{region}.amazonaws.com`
  - Path-style: `https://{endpoint_host}/{bucket}`

## Admin UI

`/admin/admin_cloud_storage` has a single primary **Save** button that:

1. Runs a three-step Test Connection diagnostic against the pasted
   credentials before persisting anything.
2. On pass, saves settings, sets `cloud_storage_enabled = true` (offload
   mode), and activates the `CloudOffloadRun` task with `frequency = every_run`.
3. On fail, displays per-step diagnostic output with remediation; nothing
   is persisted.

When enabled, two additional buttons appear:

- **Pause Cloud Storage** — disables the feature (idle mode): stops offloading
  new files; existing cloud-stored files keep serving from the bucket. Click
  Save to re-enable.
- **Disable and Pull Files Back to Local** — disables the feature and sets the
  store's draining flag, so `CloudOffloadRun` pulls all bucket-stored files back
  to local (and clears the flag when done). Confirmation dialog shows the count
  of files and free local disk space.

The page also renders a live status block at the top: cron heartbeat,
driver ping, offload task status, file counts, and any "stuck" rows
(failed 5+ times). Stuck rows have a per-row "Retry" button.

### Test Connection Steps

1. **Reach + authenticate.** A `HeadBucket` call. Pass means DNS, TCP/TLS,
   region, and credentials all work.
2. **Write + read public.** PUT a scratch probe at
   `<prefix>/_joinery_probe-<rand>.txt`, then HEAD it via the configured
   public URL. Pass means the bucket accepts writes and the public URL
   works. The HEAD response is also inspected for CDN markers (see
   "Egress" below).
3. **Delete.** DELETE the scratch probe. On 403, a yellow note flags
   that DELETE is denied (`permanent_delete` and permission flips will
   fail) but the test still counts as passing for read/write.

## Egress

The feature exists to save customers storage cost, but raw-bucket egress
can dwarf storage savings (AWS S3 egress is ~4× the per-GB storage
cost). Use a CDN.

The recommended pattern: **B2 + Cloudflare via the Bandwidth Alliance**
— free egress between B2 and Cloudflare. Cheapest realistic option for
most customers.

Other good options:

- **Cloudflare R2** — free egress built in, S3-compatible API.
- **Bunny.net** in front of any bucket — cheap egress.

The admin page warns when a configured public URL looks like a raw
bucket hostname:

- **Inline yellow banner** as the admin types the public URL or endpoint
  field, when the hostname matches a known raw pattern (`*.amazonaws.com`,
  `*.backblazeb2.com`, `*.wasabisys.com`, `*.digitaloceanspaces.com`).
- **Pre-enable confirm dialog** if the admin clicks Save with a raw
  hostname.
- **Test Connection step 2** also inspects response headers and reports
  whether a CDN was detected. This catches the
  custom-domain-CNAMEd-to-raw-bucket case the hostname check can't see.

## Migration

### Forward (local → bucket)

The `CloudOffloadRun` tick in **offload mode is** the forward migration. When
cloud storage is first enabled, the batch query naturally selects every public
local file and the tick drains them across cron ticks until the queue is empty.
There is no separate migration task.

Migration starts on the next regular cron tick (within 15 minutes). To
start sooner, click "Run Now" on the Scheduled Tasks admin page.

The task is bounded per run (50 rows or 60 seconds, whichever first).
Failures increment `fil_sync_failed_count`; after 5 consecutive failures
a row is excluded from the batch query and surfaces in the admin UI as
"stuck." The "Retry" button resets the counter and re-queues the row.

### Reverse (bucket → local)

A store's **drain mode** is entered only by the "Disable and Pull Files Back to
Local" button (which sets the store's draining flag). `CloudOffloadRun` then pulls
that store's cloud rows back. Per-row, three phases:

1. Pull bytes to a temp dir.
2. Place files into the correct local dir (re-evaluated against
   `is_public()` per row), commit `fil_storage_driver = 'local'`.
3. Best-effort bucket delete. Failures here are logged with
   `CLOUD_STORAGE_ORPHAN: bucket=<name> keys=<...>`; the row is
   correctly served locally regardless. Manual cleanup with `aws s3 rm`
   or equivalent.

Self-deactivates when no more `'cloud'` rows remain.

## Permission Changes (cross-storage)

When a file's `is_public()` flips, its bytes must end up in the store that
matches the new visibility — public bytes in the public bucket, restricted bytes
in the verified-private bucket — so a restricted file is never reachable by a
world-readable URL.

### A cloud-stored file whose visibility flips

`File::save()` / `soft_delete()` / `undelete()` capture the row's persisted
(pre-change) visibility, then `move_to_correct_directory()` compares it to the
new one. On a flip the bytes are pulled back to local **from the store that still
holds them** (`_pull_back_from_cloud_to_local($source_visibility)`), three
explicit phases:

1. **Pull all bytes to a temp dir.** Failure: drop temps, leave bucket and DB
   unchanged, throw.
2. **Delete from bucket** with brief retries. Any failure: re-PUT
   successfully-deleted keys from temps (best-effort), drop temps, throw.
3. **Copy temps to restricted local dir, commit DB row to `'local'`.** Failure:
   re-PUT all temps to bucket so the row's `'cloud'` flag stays truthful, log
   `CLOUD_STORAGE_PARTIAL_FLIP`. If re-PUT also fails, the row is genuinely
   broken; the log marker is the breadcrumb.

The row is now `'local'`; the next offload tick re-evaluates eligibility and
pushes it to the **now-correct** store via the matching profile. Bytes touch
local disk briefly during the flip — simpler and safer than a bucket-to-bucket
transfer. Invariants: bucket is authoritative until DB commit; temps live until
DB commit so they remain rollback material. Peak local disk during a flip ≈ 2×
total file size.

### A local file whose visibility flips

The directory move (`static_files/uploads/` ⇄ restricted `uploads/`) happens in
the save path; the row stays `'local'` and the next tick offloads it to the
store matching its new visibility. No request blocks on bucket I/O.

## URL Generation

`File::get_url($size_key, $format)` dispatches on the row's flag and visibility:

- `fil_storage_driver = 'local'` — the `/uploads/...` URL, served by the fast
  path (public) or the auth route (restricted).
- `fil_storage_driver = 'cloud'` **and public** — `driver->url(<remote_key>)`,
  the world-readable CDN/bucket URL. The browser hits the bucket directly; PHP is
  not in the loop.
- `fil_storage_driver = 'cloud'` **and restricted** — the local `/uploads/...`
  path, **never** a bucket URL. `serve.php` resolves the bytes from the private
  bucket behind the permission gate (below). The "never `url()`" rule, enforced
  at the model.

### /uploads/* serving

`serve.php`'s `/uploads/*` route resolves the `File` and dispatches:

- **Public cloud row** — 302-redirect to the bucket URL with
  `Cache-Control: public, max-age=86400`. After the first hit the browser caches
  the redirect; subsequent hits skip PHP. Pre-existing `/uploads/<filename>` URLs
  (in sent emails, search caches, RSS feeds, embedded HTML) keep working this way.
- **Private cloud row** — `is_viewable($session)` first (fail ⇒ 404, never 403,
  so existence isn't confirmed); then the bytes are pulled from the
  verified-private bucket to a temp file and streamed via
  `File::serve_from_path()`. Never a 302: the bucket URL is never exposed and
  the gate runs on every request.
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

### Backblaze B2

1. Create a bucket; set its type to **Public**.
2. Create an application key scoped to that bucket with read+write+delete.
3. The endpoint is `s3.<region>.backblazeb2.com` (region matches the
   bucket's region: `us-west-002`, `us-west-004`, etc.).
4. Front the bucket with **Cloudflare** for free egress via the
   Bandwidth Alliance:
   - Add the bucket's domain to a Cloudflare zone.
   - CNAME a custom domain (e.g. `images.example.com`) to the bucket
     hostname.
   - In the Joinery admin, set `cloud_storage_public_base_url` to
     `https://images.example.com`.

### AWS S3

1. Create a bucket; disable "Block all public access" for the
   `s3:GetObject` policy you'll add.
2. Apply a bucket policy granting public read on the prefix:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [{
       "Sid": "PublicReadGetObject",
       "Effect": "Allow",
       "Principal": "*",
       "Action": "s3:GetObject",
       "Resource": "arn:aws:s3:::YOUR-BUCKET/*"
     }]
   }
   ```
3. Create an IAM user with a policy granting read/write/delete on the
   bucket; use its access key and secret in the admin form.
4. Endpoint: `s3.<region>.amazonaws.com`. Region matches the bucket
   region.
5. **Strongly recommended:** front the bucket with CloudFront or another
   CDN to keep egress costs reasonable.

### Cloudflare R2

1. Create an R2 bucket.
2. In the bucket's settings, attach a public custom domain or enable
   the public bucket URL. R2 is free for egress.
3. Endpoint: `<account-id>.r2.cloudflarestorage.com`.
4. Region: `auto`.
5. `cloud_storage_public_base_url` should be the public custom domain
   you attached.

## Provider Compatibility

| Provider | Status | Notes |
|----------|--------|-------|
| Backblaze B2 (S3 API) | Verified | Path-style endpoint. Cloudflare Bandwidth Alliance is the cheapest realistic option. |
| AWS S3 | Verified | Virtual-hosted-style preferred. Reference implementation. |
| Cloudflare R2 | Should work, unverified | Free egress. |
| Wasabi | Should work, unverified | Free egress up to monthly storage allowance. |
| DigitalOcean Spaces | Should work, unverified | Includes a CDN option. |
| MinIO (self-hosted) | Should work, unverified | Path-style. Useful for development. |

## Failure Modes

| Mode | Behavior | Recovery |
|------|----------|----------|
| Sync push fails | `fil_sync_failed_count` increments; next cron tick retries. After 5 failures the row is excluded and surfaces as "stuck". | Click Retry on the stuck-files list. |
| Credentials become invalid | Driver health-check goes red; the offload tick fails every row. New uploads keep landing locally. | Save again with fixed creds. |
| Bucket runs out of quota / billing failure | Sync task fails; uploads continue locally. | Resolve at the provider; sync resumes. |
| `permanent_delete` bucket-delete fails | Logged as `CLOUD_STORAGE_ORPHAN`; row is still deleted. | Manual cleanup via `aws s3 rm` or equivalent. |
| Public→private flip phase 3 fails | Logged as `CLOUD_STORAGE_PARTIAL_FLIP`. | Manual recovery: flip the row to `'local'` and re-upload. |
| File becomes private during async push | Detected by re-check after PUTs; just-pushed objects deleted; row stays local. | Automatic. |

## File-by-File Architecture

| File | Role |
|------|------|
| `includes/cloud_storage/CloudStorageDriver.php` | Interface (put/get/delete/url/ping). |
| `includes/cloud_storage/CloudStorageS3Driver.php` | Sole implementation. Handles AWS, B2, R2, Wasabi, etc. |
| `includes/cloud_storage/CloudStorageDriverFactory.php` | `default()`/`forVisibility()` return a configured driver or `null`; `forVisibilityUnlatched()` builds from the raw binding for pull-back; `bindingFor()` is the per-visibility setting map; `fromOptions()` builds from explicit settings. |
| `includes/cloud_storage/StorageProfile.php` | The per-consumer seam interface. |
| `includes/cloud_storage/StorageProfileRegistry.php` | Reads `storage_profiles.json` + every plugin's `plugin.json` `storage_profiles` (on disk, active or not); instantiates and groups by visibility. |
| `storage_profiles.json` | Core profile manifest (declares `FileStorageProfile` + `FilePrivateStorageProfile`). |
| `includes/cloud_storage/CloudOffloadEngine.php` | Table-agnostic forward/reverse batch + per-row logic; reverse/count honour the optional `reverseEligibilityWhere()` ownership gate for shared tables. |
| `includes/cloud_storage/CloudStorageLifecycle.php` | Shared admin save/test/health + the binding-immutability guard + per-visibility bindings + the privacy gate verdict; owns the offload modes (`modeForVisibility`, `startDrain`/`stopDrain`, `ensureTickActive`) and the `runOffloadTick()` orchestration; cloud-row counts scoped per store via the ownership gate. |
| `includes/cloud_storage/FileStorageProfile.php` | Public-files adapter over the existing `File` methods (`visibility=public`). |
| `includes/cloud_storage/FilePrivateStorageProfile.php` | Restricted-files adapter (`visibility=private`); extends `FileStorageProfile`, overriding visibility, eligibility, and the ownership gate. |
| `data/files_class.php` | Visibility-aware cloud methods: `get_url()` (private → local gated path, never a bucket URL), `permanent_delete()`, `delete_resized()`, `resize()`, `move_to_correct_directory()` (visibility-flip pull-back via `_pull_back_from_cloud_to_local()`). |
| `tasks/CloudOffloadRun.php` | The one offload task for the whole platform. Calls `CloudStorageLifecycle::runOffloadTick()`, which drives every store by mode (offload/drain/idle); self-deactivates when nothing is offloading or draining. |
| `adm/admin_cloud_storage.php` | Admin UI. Save = test + persist + activate; carries the private-bucket field + privacy-gate results. |
| `adm/logic/admin_cloud_storage_logic.php` | Thin caller over `CloudStorageLifecycle`, per store; Save/Pause/Disable-and-Pull/Retry handlers. |
| `serve.php` | `/uploads/*` route: 302-redirects public cloud rows; gate-streams private cloud rows through PHP after `is_viewable()`. |
| `includes/UploadHandler.php` | `get_unique_filename()` consults `fil_files` so dedup works after locals are deleted. |
| `utils/process_scheduled_tasks.php` | Per-task advisory locking (prereq for the offload tick — prevents tick-overlap races). |

## Out of Scope (v1)

- Per-tenant / per-user buckets within a single Joinery instance.
- Bucket-level encryption configured via the admin UI (customer
  responsibility at bucket creation).
- Resize-on-demand (variants still generated upfront).
- Automatic CDN setup (customer responsibility, documented above).
- Egress monitoring or alerts based on actual bytes served.
- Signed-URL serving for private files — server-side streaming only; presigned
  URLs are a later egress optimization, additive to the driver.
