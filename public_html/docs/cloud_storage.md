# Cloud Storage

Customer-owned, S3-compatible object storage for **public** uploaded files.
Permissioned/private files always stay on local disk.

## Overview

Each Joinery instance can be configured with one S3-compatible bucket
(AWS S3, Backblaze B2, Cloudflare R2, Wasabi, DigitalOcean Spaces, MinIO,
etc.). Public uploads (photos, gallery images, blog images) are
asynchronously moved to that bucket; the customer carries the storage
cost rather than the platform.

Uploads themselves are unchanged — they always land locally first. A
scheduled task pushes public files to the bucket on the next cron tick.
Private files are never put in the bucket.

## Architecture

```
Upload arrives → UploadHandler → File row created (fil_storage_driver='local')
                                                     │
                                                     ▼
                                    Cron tick (every 15 min)
                                                     │
                                                     ▼
                            CloudStorageSync iterates eligible rows:
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
2. On pass, saves settings, sets `cloud_storage_enabled = true`,
   activates the `CloudStorageSync` task with `frequency = every_run`.
3. On fail, displays per-step diagnostic output with remediation; nothing
   is persisted.

When enabled, two additional buttons appear:

- **Pause Cloud Storage** — disables the feature and deactivates the
  sync task. Existing cloud-stored files keep serving from the bucket.
  Click Save to re-enable.
- **Disable and Pull Files Back to Local** — same as Pause, *plus*
  activates `CloudStorageReverseSync` to pull all bucket-stored files
  back. Confirmation dialog shows the count of files and free local
  disk space.

The page also renders a live status block at the top: cron heartbeat,
driver ping, sync task status, file counts, and any "stuck" rows
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

The `CloudStorageSync` task **is** the forward migration. When cloud
storage is first enabled, the batch query naturally selects every public
local file and the task drains them across cron ticks until the queue is
empty. There is no separate migration task.

Migration starts on the next regular cron tick (within 15 minutes). To
start sooner, click "Run Now" on the Scheduled Tasks admin page.

The task is bounded per run (50 rows or 60 seconds, whichever first).
Failures increment `fil_sync_failed_count`; after 5 consecutive failures
a row is excluded from the batch query and surfaces in the admin UI as
"stuck." The "Retry" button resets the counter and re-queues the row.

### Reverse (bucket → local)

`CloudStorageReverseSync` is activated only by the "Disable and Pull
Files Back to Local" button. Per-row, three phases:

1. Pull bytes to a temp dir.
2. Place files into the correct local dir (re-evaluated against
   `is_public()` per row), commit `fil_storage_driver = 'local'`.
3. Best-effort bucket delete. Failures here are logged with
   `CLOUD_STORAGE_ORPHAN: bucket=<name> keys=<...>`; the row is
   correctly served locally regardless. Manual cleanup with `aws s3 rm`
   or equivalent.

Self-deactivates when no more `'cloud'` rows remain.

## Permission Changes (cross-storage)

When a file's `is_public()` flips, the file moves between local and bucket.

### Public → private (cloud → local)

Synchronous from the admin's request, three explicit phases:

1. **Pull all bytes to a temp dir.** Failure: drop temps, leave bucket
   and DB unchanged, throw.
2. **Delete from bucket** with brief retries. Any failure: re-PUT
   successfully-deleted keys from temps (best-effort), drop temps, throw.
3. **Copy temps to restricted local dir, commit DB row.** Failure:
   re-PUT all temps to bucket so the row's `'cloud'` flag stays
   truthful, log `CLOUD_STORAGE_PARTIAL_FLIP`. If re-PUT also fails,
   the row is genuinely broken; log marker is the breadcrumb.

Invariants: bucket is authoritative until DB commit; temps live until
DB commit so they remain rollback material.

Peak local disk during a flip ≈ 2× total file size (temp + restricted-dir
copy, briefly during phase 3).

### Private → public (local → bucket)

The synchronous path doesn't push to the bucket. The row stays at
`'local'`; the next sync task tick picks it up. Avoids blocking the
user's request on bucket I/O.

## URL Generation

`File::get_url($size_key, $format)` dispatches on the row's flag:

- `fil_storage_driver = 'local'` — existing `/uploads/...` URL,
  served by the fast path or auth route.
- `fil_storage_driver = 'cloud'` — `driver->url(<remote_key>)`, the
  public CDN/bucket URL. Browser hits the bucket directly; PHP is not
  in the loop.

### Backwards-compatible /uploads/* redirect

Pre-migration `/uploads/<filename>` URLs (in sent emails, search index
caches, RSS feeds, embedded HTML) keep working: `serve.php`'s `/uploads/*`
route checks `fil_storage_driver` and 302-redirects cloud rows to the
bucket URL with `Cache-Control: public, max-age=86400`. After the first
hit the browser caches the redirect; subsequent hits skip PHP entirely.

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
| Credentials become invalid | Driver health-check goes red; sync task fails every row. New uploads keep landing locally. | Save again with fixed creds. |
| Bucket runs out of quota / billing failure | Sync task fails; uploads continue locally. | Resolve at the provider; sync resumes. |
| `permanent_delete` bucket-delete fails | Logged as `CLOUD_STORAGE_ORPHAN`; row is still deleted. | Manual cleanup via `aws s3 rm` or equivalent. |
| Public→private flip phase 3 fails | Logged as `CLOUD_STORAGE_PARTIAL_FLIP`. | Manual recovery: flip the row to `'local'` and re-upload. |
| File becomes private during async push | Detected by re-check after PUTs; just-pushed objects deleted; row stays local. | Automatic. |

## File-by-File Architecture

| File | Role |
|------|------|
| `includes/cloud_storage/CloudStorageDriver.php` | Interface (put/get/delete/url/ping). |
| `includes/cloud_storage/CloudStorageS3Driver.php` | Sole implementation. Handles AWS, B2, R2, Wasabi, etc. |
| `includes/cloud_storage/CloudStorageDriverFactory.php` | `default()` returns configured driver or `null`. `fromOptions()` builds from explicit settings (used by Test Connection before persisting). |
| `data/files_class.php` | Cloud-aware methods: `get_url()`, `permanent_delete()`, `delete_resized()`, `resize()`, `move_to_correct_directory()` (incl. three-phase pull-back). |
| `tasks/CloudStorageSync.php` | Forward sync task (also serves as one-time forward migration). |
| `tasks/CloudStorageReverseSync.php` | Pull-back task; self-deactivates when no `'cloud'` rows remain. |
| `adm/admin_cloud_storage.php` | Admin UI. Save = test + persist + activate. |
| `adm/logic/admin_cloud_storage_logic.php` | Save/Pause/Disable-and-Pull/Retry handlers; Test Connection diagnostic; health-status query. |
| `serve.php` | `/uploads/*` route extended to 302-redirect cloud rows. |
| `includes/UploadHandler.php` | `get_unique_filename()` consults `fil_files` so dedup works after locals are deleted. |
| `utils/process_scheduled_tasks.php` | Per-task advisory locking (prereq for the sync task — prevents tick-overlap races). |

## Out of Scope (v1)

- Per-tenant / per-user buckets within a single Joinery instance.
- Bucket-level encryption configured via the admin UI (customer
  responsibility at bucket creation).
- Resize-on-demand (variants still generated upfront).
- Automatic CDN setup (customer responsibility, documented above).
- Egress monitoring or alerts based on actual bytes served.
- Storing private/permissioned files in the bucket. Private files stay
  local. Period.
