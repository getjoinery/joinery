# Cloud Storage (S3 / Backblaze B2) for Public Uploads

## Goal

Move **public** uploaded files (photos, gallery images, blog images, etc.) to
a customer-owned S3-compatible object store, so customers with large file
footprints carry their own storage costs rather than driving up hosting
costs on the platform.

**Public files only.** Permissioned/private files stay on local disk and
keep their existing serving path. The cloud bucket is unambiguously
public-readable; private bytes never go in it. This eliminates the need
for presigned URLs, prefix-based bucket policies, and per-request PHP
redirects on public files.

**Per-site, customer-owned bucket.** Each Joinery instance is configured
with one bucket owned by that customer. Credentials live in `stg_settings`
like other per-site API integrations.

**Bucket is canonical for the files it holds.** Local disk is a working
scratch area for upload + resize only — local copies are deleted
immediately after a successful push to the bucket. There is no permanent
local copy of bucket-stored files.

## Current State

- `data/files_class.php` (`File` model) owns all file metadata and exposes
  `get_url()`, `get_filesystem_path()`, `resize()`, `permanent_delete()`, etc.
- `includes/UploadHandler.php` receives uploads and writes them to disk.
- Files live in one of two local directories based on permission state:
  - `static_files/uploads/` — public files (no permission restrictions),
    served by a fast pre-bootstrap path in `RouteHelper`.
  - `uploads/` — restricted files, served via the `/uploads/*` route in
    `serve.php` after `authenticate_read()` runs.
- `File::move_to_correct_directory()` shuffles files between those two
  local directories on save / soft-delete / undelete based on `is_public()`.
- `File::resize()` produces variants (`thumb/`, `avatar/`, …) defined by
  `ImageSizeRegistry`. Variants live alongside the original under per-size
  subdirectories.

The cloud feature replaces local storage **only for public files**. Local
behavior for restricted files is unchanged.

---

## Design

### 1. Storage driver

`includes/cloud_storage/CloudStorageDriver.php` — interface:

```php
interface CloudStorageDriver {
    /** Push a local file to the bucket at the given object key. */
    public function put(string $local_path, string $remote_key,
                        string $content_type): void;

    /** Pull an object from the bucket to a local path (re-resize, pull-back). */
    public function get(string $remote_key, string $local_path): void;

    /** Delete an object. No-op if it doesn't exist. */
    public function delete(string $remote_key): void;

    /** Return the public URL for an object (CDN domain or bucket URL). */
    public function url(string $remote_key): string;

    /** Quick credential probe — used by Test Connection. */
    public function ping(): array; // ['ok' => bool, 'message' => string]
}
```

One implementation: **`CloudStorageS3Driver`** (uses `aws/aws-sdk-php`). Endpoint is
configurable, so the same class serves AWS S3, Backblaze B2 (S3-compatible
API), Wasabi, DigitalOcean Spaces, MinIO, etc. No separate B2-native driver.

A `CloudStorageDriverFactory::default()` returns the configured driver
instance. Factory returns `null` if cloud storage isn't configured —
calling sites check that before assuming it's available.

Local file handling is **not** wrapped in a driver. Existing local code
paths in `File` and `RouteHelper` continue to handle local files
unchanged. The driver abstraction exists only for cloud-side operations.

### 2. Per-file driver flag

Add one column to `fil_files`:

```php
'fil_storage_driver' => array('type'=>'varchar(32)', 'default'=>'local',
                              'is_nullable'=>false),
```

- `'local'` — file's bytes live on disk under `upload_dir` /
  `static_files/uploads/`. Existing behavior.
- `'s3'` — file's bytes live in the bucket. Object keys are derived
  from `fil_name`: original at `<site_template>/<fil_name>`, variants
  at `<site_template>/<size>/<fil_name>`. The prefix is automatic
  (§9a).

The flag is the source of truth. Migration is row-by-row: each row
decides where its bytes are, so a misconfigured global setting cannot
strand existing files.

### 3. Bucket layout

```
<site_template>/<filename>            ← original
<site_template>/<size>/<filename>     ← variants (thumb, avatar, ...)
```

The prefix is the site's `site_template` (e.g. `joinerytest`),
applied automatically — see §9a. Multiple Joinery instances can
safely share one bucket because each gets its own `site_template`
prefix without any configuration.

The bucket is publicly readable (the customer applies that policy
at bucket-creation time, documented in `docs/cloud_storage.md`).

### 3a. Filename uniqueness

The current upload flow (`UploadHandler::get_unique_filename()`) prevents
collisions by checking the local filesystem and appending ` (1)`,
` (2)`, etc. to duplicates. That check is ineffective once bytes move
to the bucket and local copies are deleted: the next upload of
`photo.jpg` finds nothing on disk, names itself `photo.jpg`, and
overwrites the existing bucket object — two `File` rows pointing at
the same bytes.

**Fix:** extend `get_unique_filename()` to also consult `fil_files`.
The collision check becomes:

```
while (filesystem_has($name) || fil_files_has_active_row($name)) {
    $name = upcount_name($name);
}
```

`fil_files_has_active_row($name)` runs `SELECT 1 FROM fil_files WHERE
fil_name = ? AND fil_delete_time IS NULL LIMIT 1`. Order doesn't
matter — either condition triggers the suffix bump.

This works for both local and cloud-stored files and preserves the
existing URL-pattern semantics (no random characters injected into
filenames).

A small race remains (two simultaneous uploads of the same name pass
the check before either has saved its row). Acceptable for v1: the
window is narrow, both uploads still succeed, but both rows end up
with the same `fil_name` and whichever bytes win the bucket-PUT race
become the canonical content for both rows. If it becomes a real
problem, add a unique index on `fil_name` (over non-deleted rows) and
retry on conflict.

### 4. Upload flow (synchronous, local only)

The upload request never touches the bucket. It runs the existing flow
unchanged:

1. Receive upload → write to local (existing `UploadHandler`).
2. Run all resize variants locally.
3. Save the `File` row with `fil_storage_driver = 'local'`.
4. Respond.

Upload latency is identical to today. The file is publicly visible
immediately via the existing local-serve path.

A separate background task moves public files to the bucket
asynchronously (§4a).

### 4a. Async sync task

A scheduled task — `CloudStorageSync` — runs on every cron tick
(every 15 min via the existing `process_scheduled_tasks.php` cron entry)
when `cloud_storage_enabled = true`. It walks `fil_files` rows where:

- `fil_storage_driver = 'local'`, AND
- the file is public per `is_public()`, AND
- `fil_sync_failed_count < 5` (see "Failure handling" below).

For each row, in batches:

1. Acquire a row-level advisory lock (or `SELECT ... FOR UPDATE`) so
   concurrent task runs and permission-change paths don't race.
2. Push original + all variants to the bucket. **PUTs run concurrently**
   per row (Guzzle pool / `async-aws/s3`). For a typical 5-variant
   image this is ~1 RTT instead of 6.
3. Re-load the row and re-check `is_public()`. The file may have been
   soft-deleted, undeleted to a different state, or had permission
   fields changed during the push.
   - **Still public:** flip `fil_storage_driver = 's3'`, delete all
     local copies. Done.
   - **No longer public:** delete the just-pushed bucket objects, leave
     the row at `'local'` and the local files in place. The normal
     permission-change path will place them correctly on its next call.
4. On any push failure: increment `fil_sync_failed_count`, set
   `fil_sync_last_attempt = now()`, log the error. Best-effort delete
   anything already pushed in this attempt. Move on to the next row.

Bounded per-run: process at most N rows or run for at most M seconds,
whichever comes first. A persistent-failure row never blocks the queue.

The same task, kicked manually from the admin UI with a progress
display, serves as the **one-time forward migration** of existing
public files. There is no separate migration task — bulk migration
and ongoing post-upload sync are the same operation.

#### Failure handling

Two new columns on `fil_files`:

```php
'fil_sync_failed_count' => array('type'=>'int4', 'default'=>0,
                                  'is_nullable'=>false),
'fil_sync_last_attempt' => array('type'=>'timestamp(6)',
                                  'is_nullable'=>true),
```

`fil_sync_failed_count` increments on each failure and resets to 0
on success. The batch query excludes rows with
`fil_sync_failed_count >= 5`; those rows surface on the admin
dashboard's stuck-files list (§10a) for manual retry.

`fil_sync_last_attempt` is set to `now()` on each attempt and
exists as a debugging breadcrumb (when did this last try?) — it
is not used in the batch filter. Retry pacing is the cron tick
itself (15 min), so no time-since-last-attempt math is needed: a
failing row retries on the next tick until it hits the cap.

After ~5 ticks of consistent failure (~75 min), a broken row stops
re-trying on its own. The admin clicks "Retry" on the stuck-files
list to reset the count and re-enter it in the queue.

#### Task locking

Only one instance of `CloudStorageSync` runs at a time.

**Confirmed prerequisite:** the existing `utils/process_scheduled_tasks.php`
runner has **no per-task locking** — it iterates active tasks and calls
`run()` directly. If a tick's run is still busy when the next tick
fires, both runs proceed and can race on the same `fil_files` rows.
We must add per-task locking to the task system before this feature
ships.

Recommended implementation: `pg_try_advisory_lock(hashtext(sct_name))`
in the runner around each task's `run()` call. If the lock can't be
acquired, skip the task (log "skipped: already running") and continue.
The lock auto-releases on connection close, so a crashed PHP process
self-recovers. This is a small, isolated change to
`utils/process_scheduled_tasks.php` that benefits every long-running
task, not just the cloud sync.

#### Lifecycle and activation

The task ships as two files in the core `/tasks/` directory:

- `tasks/CloudStorageSync.php` (implements
  `ScheduledTaskInterface`)
- `tasks/CloudStorageSync.json` (metadata; default
  `frequency = every_run`)

The task piggybacks on the existing cron infrastructure
(`utils/process_scheduled_tasks.php`, run every 15 min by the cron
entry that `_site_init.sh` installs for all sites). No new cron
entry is added.

**Activation is driven by the storage admin page**, not the
Scheduled Tasks page:

- When the admin flips `cloud_storage_enabled = true` after a passing
  Test Connection, the storage logic activates the task: creates
  or un-suspends the `sct_scheduled_tasks` row with
  `sct_is_active = true` and `frequency = 'every_run'`.
- When the admin disables cloud storage, the task is deactivated
  (`sct_is_active = false`). Already-cloud-stored files keep
  serving from the bucket via their existing `fil_storage_driver = 's3'`
  flag — disabling just stops *new* migrations.
- The standard Scheduled Tasks admin page still shows the task
  and can be used as a fallback to activate/deactivate manually.

**On a fresh install**, the task files are present (deployed with
the codebase), the cron entry is present (from `_site_init.sh`),
and the `sct_scheduled_tasks` row does not exist. The task is
discovered as "Available" on the Scheduled Tasks admin page but
makes no S3 calls until the storage admin page activates it. No
accidental activity, no errors.

**Migration starts on the next cron tick.** When cloud storage is
enabled via the Save flow (§10), the storage logic activates the
task row and stops. The next regular cron tick (within 15 min)
picks the task up and begins migration. The storage admin page's
status block (§10a) shows pending row counts and last-run
timestamp, so the admin can watch progress on subsequent reloads.

No subprocess spawning, no `disable_functions` detection, no
side-channel kick. Scheduled task work always flows through
`utils/process_scheduled_tasks.php` triggered by cron — there is
exactly one entry point.

If the admin wants migration to start sooner than the next tick,
the existing "Run Now" button on the Scheduled Tasks admin page
runs the task synchronously inside the admin's request. That path
is fine for moderate workloads; for very large initial migrations
the cron-driven path is preferred since each tick processes a
bounded batch and the run can be observed across multiple ticks.

#### Reverse migration as a sister task

A second task ships alongside:

- `tasks/CloudStorageReverseSync.php`
- `tasks/CloudStorageReverseSync.json` (default state: inactive)

Activation flow: admin clicks "Disable and Pull Files Back to
Local" on the storage admin page → the storage logic activates the
task with `frequency = 'every_run'`. The task processes a bounded
batch each cron tick. When `run()` finds zero rows remaining
(`fil_storage_driver = 's3'` count is 0), it deactivates itself
(`sct_is_active = false`). Admin can also manually deactivate from
either admin page to pause.

Per-row pull flow (re-evaluating `is_public()` per row, three-phase
ordering with bucket-deletes after DB commit) is detailed in §11
"Reverse (bucket → local)".

### 5. URL generation

`File::get_url($size_key, $format)` dispatches on the row's driver flag:

- **`fil_storage_driver = 'local'`**: existing `/uploads/...` URL,
  served by the fast path or the auth route as today. Unchanged.
- **`fil_storage_driver = 's3'`**: `driver->url(<remote_key>)` —
  the public CDN/bucket URL. Browser hits the bucket directly. PHP is
  not in the loop. URL is stable and cacheable.

No presigning. No expiring URLs for the bucket-direct path.

### 5a. Backwards-compatible `/uploads/*` redirect

Files migrated from local to cloud have URLs already in the wild —
sent emails, search engine indexes, RSS feeds, hot-linked from other
sites, embedded in cached HTML. Those old `/uploads/foo.jpg` URLs
must keep working.

`serve.php`'s `/uploads/*` route is extended. The handler resolves
the file row by basename and dispatches based on row state:

| Row state | Behavior |
|-----------|----------|
| No live row (deleted or missing) | Existing 404 / fall-through behavior. |
| Live row, `fil_storage_driver = 'local'` | Existing local-serve + `authenticate_read()` path. Unchanged. |
| Live row, `fil_storage_driver = 's3'` | 302 redirect to `driver->url(<remote_key>)` with `Cache-Control: public, max-age=86400`. Browser caches the redirect; subsequent hits skip PHP entirely. |

`get_url()` for cloud files returns the bucket URL directly (no
redirect hop) for *new* outbound URLs the platform generates. The
redirect path exists only to honor pre-migration URLs already
embedded elsewhere.

**Row-lookup correctness — `File::get_by_name()` audit.**

The current `get_by_name()` (`data/files_class.php:54`) has three
problems that affect this redirect:

1. No `fil_delete_time` filter — soft-deleted rows match.
2. No `LIMIT 1` — relies on `rowCount()`.
3. No `ORDER BY` — for duplicate `fil_name` rows the returned row
   is whichever PostgreSQL chooses.

The fix: extend the signature to `get_by_name($name, $search_deleted = false)`
matching the `get_by_link($link, $search_deleted = false)` convention
already in use, and add `LIMIT 1` + `ORDER BY fil_file_id DESC` (most
recent wins on ties). Audit shows only two callers in the codebase
— both want live rows only:

- `serve.php:389` — `/uploads/*` route. Default behavior (filter
  deleted) is correct.
- `adm/logic/admin_file_upload_process_logic.php:180` — duplicate
  detection during upload. Default behavior is correct (we don't
  want to "re-use" a soft-deleted file's row for a fresh upload).

### 6. Permission changes (the cross-storage cases)

A file's `is_public()` state can change after upload (soft-delete,
undelete, edits to `fil_min_permission` / `fil_grp_group_id` /
`fil_evt_event_id` / `fil_tier_min_level`). When the public/private
state flips, the file moves between local and bucket.

**Public → private** (file currently in bucket, now must be private):

The operation is split into three explicit phases with strict
ordering. The two non-negotiable invariants are:

- **Bucket-authoritative until DB commit.** The DB row says
  `fil_storage_driver = 's3'` until the file is correctly placed
  locally. If anything fails before the commit, the bucket must
  still hold the bytes (so the existing public URL keeps serving),
  even if that means re-PUTting deleted objects.
- **Temps live until DB commit.** All pulled-from-bucket temp files
  are retained until the DB row is committed. They are the only
  rollback material for Phase 2 failures and the source bytes for
  Phase 3 placement.

**Phase 1 — Pull all bytes to a temp directory.**

For original + every variant, `driver->get(<remote_key>, <temp_path>)`.

On any failure (network error, missing variant, disk full):
drop all temps created so far, leave bucket and DB unchanged,
surface error to caller. The bucket's public copies remain
intact; the file keeps serving publicly via its existing URL
until the admin retries.

**Phase 2 — Delete from bucket.**

With all temps still on disk, `driver->delete(<remote_key>)` for
original + every variant, with brief retries (~3 attempts over
~5 seconds per key).

On any delete failure after retries:

- Roll back: re-PUT every key that *was* successfully deleted in
  this phase, from the corresponding temp file. Best-effort —
  ignore re-PUT failures here, since the original failure already
  indicates broader storage trouble and the surviving bucket
  objects continue serving most of the file.
- Drop temps.
- Leave DB unchanged.
- Surface error to caller.

**Phase 3 — Place locally and commit DB row.**

1. Copy (do not move) temps into the restricted directory
   structure — keeping temps in place leaves a rollback path if
   the DB commit fails.
2. `BEGIN; UPDATE fil_files SET fil_storage_driver='local',
   <permission fields>, ...; COMMIT;`
3. After successful commit: drop temps and the now-redundant copies'
   temp paths.

If Phase 3 step 1 (local copy) or step 2 (DB commit) fails:

- This is the worst case: bucket is empty, temps still exist, DB
  still says `'s3'`.
- Best-effort: clean up any partially-written restricted-dir
  files, then re-PUT all temps to bucket so the row's `'s3'` flag
  remains truthful. Drop temps.
- Surface error to caller with a `CLOUD_STORAGE_PARTIAL_FLIP`
  marker in `error_log`. If the re-PUT *also* fails, the row is
  genuinely broken (DB says `'s3'`, bucket is empty) — a
  double-failure case that's not automatically detected. The
  marker in the log is the breadcrumb; manual recovery is
  flipping the row to `'local'` and re-uploading. If this turns
  out to fire in practice, an integrity-check sweep (`driver->exists()`
  over `'s3'` rows) is straightforward to add later, since the
  log marker is already unique and greppable.

**Peak local disk during a flip ≈ 2× total file size** (temp +
restricted-dir copy, briefly, during Phase 3 step 1). For multi-MB
images with several variants this is small in absolute terms but
worth noting for sites near disk capacity.

**Realistic failure rates.** Phase 1 failures are network blips;
retry on the next admin click works. Phase 2 failures imply broader
storage trouble (creds revoked, S3 down) and are also surfaced by
the dashboard's driver-ping health check. Phase 3 failures (after a
successful Phase 2) are very rare — local disk full or DB outage —
and when they occur the file is genuinely broken and admin
intervention is required. The detailed rollback paths above are
belt-and-suspenders for failure modes that should almost never fire
in a healthy environment.

**Private → public** (file currently local, now allowed to be public):

The synchronous path doesn't push to the bucket. The row stays at
`fil_storage_driver = 'local'` after the permission update, and the
async sync task (§4a) picks it up on its next run. This avoids
blocking the user's request on bucket I/O.

Both flows hook into `File::move_to_correct_directory()` (or the
slightly renamed equivalent — name no longer fits) so existing callers
(`save()`, `soft_delete()`, `undelete()`) get the cross-storage
behavior for free.

If cloud storage becomes unconfigured between the upload of a public
file and a later permission flip back to public, the file just stays
local. No-op.

### 7. Re-resize

When `ImageSizeRegistry` gains a new size, existing files need a new
variant generated:

- Local file: existing `File::resize()` behavior unchanged.
- Bucket file: `driver->get(<original_key>, <temp>)` → resize against
  the temp file → `driver->put()` the new variant → drop the temp.

### 8. `permanent_delete()`

- Local file: existing behavior.
- Bucket file: attempt `driver->delete()` for original + every variant
  with brief retry. On success, delete the row.
- On failure: write a clearly-marked entry to `error_log` with all
  undeleted keys (`CLOUD_STORAGE_ORPHAN: bucket=<name>
  keys=<comma-separated list>`) so the admin can clean up manually
  if they care, then delete the row. The orphan exists in the
  customer's bucket consuming a few KB to MB; manual cleanup via
  `aws s3 rm` or equivalent is the recovery path. This is rare
  (effectively only fires when the broader S3 health check is also
  red).

### 9. Settings

Added to `stg_settings` via declarations in `settings.json`:

```
cloud_storage_endpoint         = ''   required; e.g. 's3.us-west-002.backblazeb2.com'
cloud_storage_region           = ''   required; e.g. 'us-east-1' or 'us-west-002'
cloud_storage_bucket           = ''   required
cloud_storage_access_key       = ''   required
cloud_storage_secret_key       = ''   required
cloud_storage_public_base_url  = ''   optional; auto-derived if empty (see below)
cloud_storage_enabled          = false  internal; flipped by the Save flow (§10)
```

#### Auto-derivations (no setting needed)

- **Path-style vs virtual-hosted addressing.** Derived from the
  endpoint hostname: AWS (`*.amazonaws.com`) → virtual-hosted;
  everything else → path-style. The driver constructor sets the
  SDK flag accordingly:
  ```php
  $path_style = !preg_match('/\.amazonaws\.com$/i',
                            parse_url($endpoint, PHP_URL_HOST) ?? '');
  ```

- **Public base URL when empty.** Computed from `endpoint` + `bucket`:
  - AWS virtual-hosted: `https://{bucket}.s3.{region}.amazonaws.com`
  - Path-style: `https://{endpoint_host}/{bucket}`
  
  The auto-derived URL points at the bucket root, not the
  `<site_template>/` subtree. URL generation appends the prefix
  per-key. Customers using a CDN typically point the CDN at the
  bucket root and let the prefix come through the path naturally.
  
  The customer only fills `cloud_storage_public_base_url` if they
  have a CDN or custom domain. An auto-derived URL still trips the
  egress-cost warning (§10c) — correctly, since it's a raw bucket
  hostname.

The driver decision itself is automatic (public + cloud-enabled →
bucket; otherwise → local). `cloud_storage_enabled` is the on/off
switch for the whole feature, set by the Save flow.

Secrets stored plain in `stg_settings`, matching the existing pattern
for Stripe/Mailgun keys.

### 9a. Path prefix — automatic, not configurable

Bucket object keys are namespaced by the site's `site_template`
(the install-directory name from `Globalvars_site.php`, e.g.
`joinerytest`). This is computed at every key-construction call —
no setting, no form field, no admin choice.

Implemented as a private static method on `CloudStorageS3Driver`
itself — prefix construction is a driver implementation detail,
not a free-floating utility:

```php
private static function pathPrefix(): string {
    $template = strtolower(Globalvars::get_instance()->get_setting('site_template') ?? '');
    if ($template === '' || strpos($template, '/') !== false) {
        throw new RuntimeException(
            'site_template is empty or contains a slash; '
            . 'refusing to derive cloud storage path prefix.');
    }
    $sanitized = preg_replace('/[^a-z0-9-]/', '-', $template);
    return trim(preg_replace('/-+/', '-', $sanitized), '-');
}
```

The empty/slash guard is a hard fail rather than a silent fallback:
silently re-deriving to a different prefix would orphan every
existing object in the bucket. The defensive sanitizer below the
guard is belt-and-suspenders — `site_template` should already be a
clean directory name, but if it ever isn't, we don't want surprises.

Why no setting:

- The only purpose of a path prefix is per-site isolation. Using
  `site_template` (the existing system identifier for "which
  install is this") accomplishes that automatically and matches
  how the rest of the codebase already namespaces things (DB
  names, filesystem paths, container names).
- Configurability adds permanent UI complexity (form rendering,
  immutability lock-out, "are you sure" warnings) for a setting
  almost no one will change. The intentionally-share-a-flat-bucket
  case is rare and exotic.
- A power user who genuinely needs an override can edit the
  driver code directly. A future admin setting can be added if
  real customers ask for it; we're not painting ourselves into
  a corner.

`site_template` is itself effectively immutable (changing it would
break filesystem paths, DB connections, and many other things long
before bucket keys), so the prefix is stable in practice without
needing explicit lock-out logic.

### 10. Admin UI

New admin page: `/adm/admin_cloud_storage` (or a tab on `admin_settings`).

The principle: **everything works automatically on Save, or the page
tells you exactly why it isn't working.** Manual clicks are reserved
for high-impact decisions (disabling, pulling files back).

#### Save flow (one button does everything)

The form has a single primary **Save** button. Submitting:

1. Runs the full Test Connection diagnostic (see §10b) against the
   pasted credentials *before* persisting anything.
2. If Test Connection fails: settings are not saved. The form
   displays per-step diagnostic output (§10b) with a remediation
   for the failed step.
3. If Test Connection passes:
   - Saves the settings to `stg_settings`.
   - Sets `cloud_storage_enabled = true`.
   - Activates `CloudStorageSync` (`sct_is_active = true`,
     `sct_frequency = 'every_run'`).
   - Renders the success state with live status (§10a).

Migration of pre-existing public files begins on the next regular
cron tick (within 15 min). The status block on the admin page
shows pending row counts and last-run timestamp so the admin can
watch it tick down on reload. If they want to start sooner, the
"Run Now" button on the Scheduled Tasks admin page is one click
away (see §4a).

There is no separate "Test Connection" button or "Enable" toggle.
Save does both. The admin types credentials, clicks Save, and either
sees success or sees a focused error with a fix.

#### Disable flow

The **Save** button is always present and always does the same
thing: run Test Connection, persist settings, set
`cloud_storage_enabled = true`, activate the sync task. Clicking
Save while already enabled is harmless — it just re-runs the test
and re-asserts state. This collapses "first-time enable" and
"resume after pause" into one code path.

When `cloud_storage_enabled = true`, two additional buttons
appear alongside Save:

- **Pause Cloud Storage** — sets `cloud_storage_enabled = false`.
  Deactivates the sync task. New uploads stay local. Existing
  cloud-stored files (`fil_storage_driver = 's3'`) continue serving
  from the bucket via their existing URLs. Click Save to re-enable.

- **Disable and Pull Files Back to Local** — same as Pause, *plus*
  activates `CloudStorageReverseSync` to pull all bucket-stored
  files back. The destructive/expensive option is named for what
  it actually does. Confirmation dialog before activating
  (potentially large bandwidth + disk spike).

When paused, the form shows a single-line hint above the fields:
"Cloud storage is paused — click Save to re-enable." Settings
remain visible/editable while disabled so the admin can adjust
before re-enabling.

#### Provider-specific setup helper

As the admin types the **endpoint** field, the page recognizes the
provider (`*.backblazeb2.com`, `*.amazonaws.com`, `*.wasabisys.com`,
etc.) and renders a collapsible "Setup steps for `<provider>`" panel
beside the form. Contents:

- B2: link to the B2 console, one-paragraph "create a private app
  key scoped to this bucket," reminder to set bucket type to
  "Public," and the recommended Cloudflare-fronting steps.
- AWS S3: link to the S3 console, IAM-policy snippet (copy button)
  scoped to the configured bucket name, and bucket-policy JSON
  (copy button) for `s3:GetObject` public read.
- Generic / unknown endpoint: a generic guide with the AWS-style
  bucket policy.

The setup panel is informational, never blocking. Empty endpoint
field → no panel.

#### Small UX touches

- **Region auto-fill on endpoint blur.** When the admin tabs out of
  the endpoint field, parse a region segment if recognizable
  (e.g. `s3.us-west-002.backblazeb2.com` → `us-west-002`,
  `s3.us-east-1.amazonaws.com` → `us-east-1`) and pre-fill the region
  field if it's empty. The admin can override; this is a hint, not a
  binding decision.
- **Public base URL placeholder.** When `cloud_storage_public_base_url`
  is empty, the form's placeholder text shows the URL that will be
  auto-derived (per §9), so the admin sees what they'll get without
  saving first.

### 10a. Persistent health status

Whenever the storage admin page loads (not only after a Test
Connection click), it renders a live status block at the top showing
the current state of every dependency. Anything not green is
explained in place with a fix.

**Cron heartbeat.**
Reads `scheduled_tasks_last_cron_run` (the existing heartbeat).
- Green: cron ran within the last 30 min.
- Red banner — heartbeat is missing or older than 30 min: "Cron
  isn't running. Last tick: `<timestamp or 'never'>`. New uploads
  aren't migrating to cloud. Verify the crontab is installed
  (`*/15 * * * * www-data php /var/www/.../utils/process_scheduled_tasks.php`)
  and the cron daemon is active (`systemctl status cron`)." The
  literal `never` makes the fresh-install case visually distinct
  without a second banner.

**Driver health.**
On page load, runs `driver->ping()` — a single S3 `HeadBucket` call
against the configured bucket. No object dependency, no state left
behind. Round-trip is single-digit ms from the platform's region;
the dashboard isn't a high-traffic surface (loaded by an admin a
handful of times a day at most), so no caching layer.
- Green: ping succeeded.
- Yellow: ping took >2s.
- Red: ping failed. Show the underlying error and link to the
  diagnostic flow (§10b) — Save again to re-run the full test.

Public-URL reachability (does the bucket policy actually allow
public reads?) is verified at Test Connection time, not on every
dashboard load. It's a setup-time concern that fails when policy
changes, and policy doesn't churn. If a customer breaks their
bucket policy post-setup, the next file view 403s and the
platform's existing error log surfaces it; the admin re-runs Test
Connection to diagnose.

**Sync task.**
Reads from `sct_scheduled_tasks` for `CloudStorageSync`:
- Last run time, last run status, last message
- Files pending migration (count of public + `driver = 'local'`)
- Files migrated this week
- Recent errors (counts of `fil_sync_failed_count > 0`)

**Stuck files.**
Lists `fil_files` rows where `fil_sync_failed_count >= 5` — the sync
task has given up. Shows file name and last error, with a per-row
"Retry" action that resets `fil_sync_failed_count` to 0. Empty /
hidden when none.

The intent: the admin should be able to look at this page and see
"everything's green" or "here's exactly what's broken and how to
fix it" — without having to read logs, run CLI commands, or guess.

### 10b. Test Connection diagnostic detail

When the Save flow runs Test Connection (or when the admin re-runs
it manually from §10a), the result panel breaks the operation into
discrete steps with per-step status. Failed steps show the
underlying error *plus* a remediation; successful steps still
display so the admin can see how far the test got.

```
Test Connection results:
  ✓  Reached and authenticated (s3.us-west-002.backblazeb2.com)
  ✗  Public read failed (HTTP 403)
       The bucket isn't allowing public reads. See the
       "Bucket policy" section of docs/cloud_storage.md for
       the per-provider fix.
       Raw error: [SDK error text, "Copy for support" button]
  —  Delete: skipped (prior step failed)
```

**Three steps, each independently reportable:**

1. **Reach + authenticate.** A single `HeadBucket` call. Pass means
   DNS, TCP/TLS, region, and credentials all work. Failure surfaces
   the raw SDK error verbatim with a "Copy for support" button.
2. **Write + read public.** PUT a scratch probe at
   `<prefix>/_joinery_probe-<rand>.txt`, then HEAD it via the
   configured public URL. Pass means the bucket accepts writes
   and the public URL works. The same HEAD response is inspected
   for CDN markers (§10c check 2), and the result is included in
   this step's status line ("Public read OK — Cloudflare detected"
   or "Public read OK — raw B2 (egress warning applies)"). 403
   on the public HEAD shows a one-line hint pointing at the
   provider-specific bucket-policy fix in `docs/cloud_storage.md`.
   Other failures show the raw error.
3. **Delete.** DELETE the scratch probe. On 403, yellow note:
   "Credentials lack delete permission; `permanent_delete` and
   permission flips will fail until this is fixed." Test still
   counts as successful for read/write — the scratch object
   lingers in the bucket (a few bytes) until manually cleaned
   up. Other failures show the raw error.

The provider-specific bucket-policy JSON snippets and B2 "Public"
toggle instructions live in `docs/cloud_storage.md`, not in the
form. One source of truth, no per-provider branching in PHP.

The "Copy for support" button on each raw-error display copies the
full SDK error text plus the step name, so an admin filing a
support ticket can paste a self-contained snippet.

### 10c. Egress-cost warning

The feature exists to save customers storage cost, but raw-bucket
egress can dwarf storage savings (AWS S3 egress is ~4× the per-GB
storage cost). The realistic cost wins are:

- **B2 + Cloudflare** via the Bandwidth Alliance — free egress
- **Cloudflare R2** — free egress built in
- **Bunny.net** or another CDN in front of S3 — cheap egress

A customer who configures S3 with a raw bucket URL and walks away
may see *higher* total cost than they had before. We can't enforce
a CDN, but we can warn loudly enough that they make an informed
choice.

#### Detection — two complementary checks

**1. Hostname check (instant, runs as the admin types).**

A static helper checks whether the configured public base URL's
hostname matches a known raw-bucket pattern:

```php
// Returns provider label if URL looks raw, null otherwise.
public static function looksLikeRawBucketHost(string $public_base_url): ?string {
    $host = strtolower(parse_url($public_base_url, PHP_URL_HOST) ?? '');
    if (!$host) return null;

    static $raw_patterns = [
        '/\.amazonaws\.com$/'          => 'AWS S3',
        '/\.backblazeb2\.com$/'        => 'Backblaze B2',
        '/\.wasabisys\.com$/'          => 'Wasabi',
        '/\.digitaloceanspaces\.com$/' => 'DigitalOcean Spaces',
    ];
    foreach ($raw_patterns as $pattern => $label) {
        if (preg_match($pattern, $host)) return $label;
    }
    return null;
}
```

Used to surface a yellow inline banner on the settings form. Catches
the common case (admin pastes the bucket URL directly) without any
network call.

Limitation: doesn't catch custom domains (e.g. `images.example.com`
CNAMEd to a raw S3 bucket with no CDN). The header check covers that.

**2. Response-header check (definitive, runs during Test Connection).**

After Test Connection puts its scratch probe object, do an HTTP
HEAD against the probe's *public* URL
(`<cloud_storage_public_base_url>/<site_template>/_joinery_probe-<rand>.txt`)
and inspect the response headers, then DELETE the scratch object
as part of cleanup.

Detection priority: positive CDN markers first, then raw-bucket
markers. CDNs in front of buckets often rewrite or supplement the
bucket's headers, so a positive CDN marker wins.

```php
public static function inspectPublicUrl(string $probe_url): array {
    $context = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 5,
                   'ignore_errors' => true],
    ]);
    $raw = @get_headers($probe_url, true, $context);
    if ($raw === false) {
        return ['reachable' => false, 'cdn' => null, 'raw_provider' => null];
    }
    $h = [];
    foreach ($raw as $k => $v) {
        if (is_string($k)) $h[strtolower($k)] = is_array($v) ? end($v) : $v;
    }

    // Positive CDN markers.
    if (isset($h['cf-ray'])
        || (isset($h['server']) && stripos($h['server'], 'cloudflare') !== false)) {
        return ['reachable'=>true, 'cdn'=>'Cloudflare', 'raw_provider'=>null];
    }
    if (isset($h['x-amz-cf-id']) || isset($h['x-amz-cf-pop'])) {
        return ['reachable'=>true, 'cdn'=>'CloudFront', 'raw_provider'=>null];
    }
    if (isset($h['x-bunnycdn-pop']) || isset($h['cdn-cachekey'])) {
        return ['reachable'=>true, 'cdn'=>'Bunny', 'raw_provider'=>null];
    }
    if (isset($h['x-served-by']) && stripos($h['x-served-by'], 'cache-') !== false) {
        return ['reachable'=>true, 'cdn'=>'Fastly', 'raw_provider'=>null];
    }
    if (isset($h['x-vercel-cache'])) {
        return ['reachable'=>true, 'cdn'=>'Vercel', 'raw_provider'=>null];
    }

    // Raw-bucket markers (no CDN detected above).
    if (isset($h['x-bz-file-id']) || isset($h['x-bz-content-sha1'])) {
        return ['reachable'=>true, 'cdn'=>null, 'raw_provider'=>'Backblaze B2'];
    }
    if (isset($h['x-amz-id-2']) || isset($h['x-amz-request-id'])) {
        return ['reachable'=>true, 'cdn'=>null, 'raw_provider'=>'AWS S3 / S3-compatible'];
    }

    return ['reachable'=>true, 'cdn'=>null, 'raw_provider'=>null];
}
```

#### Where the warning appears

1. **Inline on the storage settings form**, beside the
   `cloud_storage_public_base_url` field, whenever the hostname check
   matches a raw pattern. Updates live as the admin edits the field.
   Yellow banner with provider name and a one-line steer:
   > "This looks like a raw `<provider>` bucket URL. Without a CDN,
   > you'll pay egress on every file view. Common cheaper patterns:
   > B2 + Cloudflare (free egress via Bandwidth Alliance),
   > Cloudflare R2, or Bunny.net in front of S3. See the storage
   > docs."

2. **Test Connection step 2 status line** (§10b). The same HEAD
   that verifies public read also runs the response-header check
   and folds the result into the step's pass message — "Public
   read OK — Cloudflare detected" or "Public read OK — appears
   to be raw B2." No separate badge, no supersede-the-inline-banner
   logic. The admin sees CDN status in the diagnostic flow without
   a parallel UI surface.

3. **Pre-enable confirmation**: when the admin clicks Save and the
   hostname check matches a raw pattern, the submit handler
   interrupts with a confirm/cancel dialog:
   > "Your public URL appears to be a raw `<provider>` bucket. Without
   > a CDN you'll pay egress on every file view, which can exceed
   > storage savings. Continue anyway?"
   
   Trigger is the hostname check only. The custom-domain-CNAMEd-to-raw-S3
   case (where hostname looks fine but headers reveal raw markers)
   is caught earlier by Test Connection's step 2 status line — the
   admin sees it during diagnostics and can decide to back out
   before clicking Save.

4. **Documentation** — `docs/cloud_storage.md` includes a clear
   recipe for the B2 + Cloudflare setup (the cheapest realistic
   option for most customers) and brief notes on R2 and Bunny.

No dashboard banner. The settings-page checks plus the pre-enable
confirm cover the failure mode without adding noise.

### 11. Migration

#### Forward (local → bucket, public files only)

The forward migration is **the same scheduled task as the async sync**
(§4a) — `CloudStorageSync`.

There is no manual "Migrate Now" button on the storage admin page.
The Save flow (§10) activates the task; the next cron tick (within
15 min) starts moving files. The storage admin page renders
progress (rows pending, rows migrated, recent errors) on reload —
not live, but updated each tick.

If an admin wants to start sooner or re-trigger a run after
clearing stuck-row errors, the standard "Run Now" button on the
Scheduled Tasks page runs the task synchronously inside that
request.

Idempotent and resumable. An interrupted run picks up where it left
off because the per-row driver flag is the source of truth.

#### Reverse (bucket → local)

The reverse migration is `CloudStorageReverseSync` (§4a). It is
**not** kicked automatically — the only way to start it is the
"Disable and Pull Files Back to Local" button on the storage admin
page (§10), which surfaces a confirmation dialog before activating.
The dialog shows the count of files to pull back
(`SELECT COUNT(*) FROM fil_files WHERE fil_storage_driver = 's3'`)
and the current free local disk space (`disk_free_space()`), with
a recommendation: "Ensure several GB of free space before
continuing." Both queries are O(1); no bucket traversal, no
size-tracking column.

The task does **not** pre-flight a definitive size estimate. If
local disk fills mid-migration, per-row Phase 2 placement fails
gracefully — the row stays at `'s3'`, the bucket bytes are
untouched, and `fil_sync_failed_count` increments. The admin sees
stuck rows accumulate, frees space, clicks Retry, and the run
continues. Once running, it processes a bounded batch each cron
tick; it self-deactivates when no more rows remain.

**Per-row flow.** For each row with `fil_storage_driver = 's3'`:

1. **Phase 1 — Pull bytes to temp.** `driver->get(<remote_key>, <temp>)`
   for original + every variant. On any failure: drop temps, leave
   bucket and DB unchanged, increment `fil_sync_failed_count`,
   continue to next row. Same `>= 5` cap as the forward sync task.
2. **Phase 2 — Place locally + commit DB row.** Re-evaluate
   `is_public()` on the freshly-loaded row (do not trust the
   was-public-when-pushed assumption — see below). Place files
   into the directory that `is_public()` selects:
   - `is_public() === true` → `static_files/uploads/`
   - `is_public() === false` → `uploads/`
   
   Then `BEGIN; UPDATE fil_files SET fil_storage_driver = 'local';
   COMMIT;`. On placement or commit failure, drop temps, leave
   bucket and DB unchanged, retry next tick.
3. **Phase 3 — Delete from bucket (best-effort).** After DB commit,
   `driver->delete()` for original + every variant with brief
   retry. On any delete failure: log
   `CLOUD_STORAGE_ORPHAN: bucket=<name> keys=<...>` to
   `error_log` and proceed. The row is now `'local'` and serves
   correctly; the bucket has stranded objects the customer can
   clean up manually (`aws s3 rm` etc.). The dashboard's
   stuck-files list (§10a) is **not** extended for these — they
   aren't broken, just leftover.
4. Drop temps.

**Why re-evaluate `is_public()` on every row.** In the steady state,
every `'s3'` row should also be `is_public() === true` (the §6
public→private flow pulls back to local synchronously when
permissions flip). But two real cases break that assumption:

- A previous `CLOUD_STORAGE_PARTIAL_FLIP` event left a row at
  `'s3'` with `is_public() === false`. The reverse migration is
  the natural recovery for these — pulling the bytes back to the
  *restricted* directory makes `authenticate_read()` gating
  effective again.
- Direct DB manipulation, bulk SQL updates, or imports altered
  permission fields without going through `move_to_correct_directory()`.

Defensive routing closes both holes for the cost of one extra
method call per row.

**Phase ordering differs from §6** (public→private flip). §6 must
delete from bucket *before* DB commit to avoid serving private
bytes publicly during the window. Reverse migration has the
opposite property: the row's `'s3'` flag means "bucket is
authoritative" until DB commit flips it to `'local'`. So we
commit first, then clean up the bucket. If bucket delete fails,
the file is already correctly served locally with proper auth
gating; the orphan in the bucket is a cost issue, not a
correctness issue.

### 12. Failure modes

- **Sync task push fails:** the row's `fil_sync_failed_count`
  increments; the next cron tick (15 min) retries. After 5
  consecutive failures, the row is excluded from the batch query
  and surfaces in admin as "stuck." The upload itself already
  succeeded — the user is unaffected.
- **Credentials become invalid mid-life:** the sync task starts
  failing every row; the admin dashboard health-check (driver ping)
  shows red; new uploads continue to succeed locally and queue for
  sync once creds are fixed.
- **Bucket runs out of quota / billing failure:** sync task fails;
  uploads continue locally; admin sees alerts.
- **Migration partial failure:** per-row flag means partial state is
  safe and resumable. Same code path as the sync task, same recovery.
- **Permission-change pull-back fails:** behavior depends on which
  phase failed (§6):
  - *Phase 1 (pull from bucket)* — temps dropped, bucket and DB
    untouched. Retry works.
  - *Phase 2 (bucket delete)* — successfully-deleted keys are
    re-PUT from temps to restore bucket-authoritative state, temps
    dropped, DB untouched. Retry works.
  - *Phase 3 (local placement / DB commit)* — temps re-PUT to
    bucket on best-effort basis to restore the `'s3'` flag's
    truth. If the re-PUT also fails, the row is genuinely broken
    (DB says `'s3'`, bucket empty); logged with
    `CLOUD_STORAGE_PARTIAL_FLIP` for manual recovery. Not
    automatically surfaced — the log marker is the breadcrumb.
- **`permanent_delete()` bucket-delete fails:** logged to `error_log`
  with a clear marker; the row is still deleted; the orphan object
  consumes a small amount of bucket storage until manually cleaned
  up. Effectively only happens when broader storage health is
  already failing.
- **File becomes private during async push:** detected by the
  re-check after PUTs complete (§4a step 3). Bucket objects that were
  just pushed get deleted; the row stays local; permission-change
  flow places it correctly.

### 13. Out of scope for v1

- Per-tenant / per-user buckets within a single Joinery instance.
- Bucket-level encryption configured via the admin UI (customer
  responsibility at bucket creation).
- Resize-on-demand (variants still generated upfront).
- Automatic CDN setup (documented as customer responsibility).
- Egress monitoring or alerts based on actual bytes served.
- Periodically re-running the public-URL header check to catch
  drift (CDN got disabled after initial setup). One-shot at Test
  Connection time is the v1 surface; add a daily task later if
  drift becomes a real concern.
- Storing private/permissioned files in the bucket. Private files stay
  local. Period. Adding cloud-backed private files later would require
  a presigning + redirect serving path and is an explicit future-work
  item, not v1.

---

## Files involved

### New files

- `includes/cloud_storage/CloudStorageDriver.php` — interface
- `includes/cloud_storage/CloudStorageS3Driver.php` — S3-compatible implementation
- `includes/cloud_storage/CloudStorageDriverFactory.php`
- `adm/admin_cloud_storage.php` + `adm/logic/admin_cloud_storage_logic.php`
- `tasks/CloudStorageSync.php` — implements
  `ScheduledTaskInterface`; runs every cron tick when activated
- `tasks/CloudStorageSync.json` — metadata
  (`default_frequency = "every_run"`)
- `tasks/CloudStorageReverseSync.php` — implements
  `ScheduledTaskInterface`; activated only when admin clicks
  "Reverse Migrate"; self-deactivates when no work remains
- `tasks/CloudStorageReverseSync.json` — metadata

### Modified files

- `data/files_class.php`
  - Add `fil_storage_driver`, `fil_sync_failed_count`,
    `fil_sync_last_attempt` to `$field_specifications`.
  - Extend `get_by_name($name, $search_deleted = false)` to filter
    `fil_delete_time IS NULL` by default, add `ORDER BY fil_file_id
    DESC LIMIT 1`. Matches the existing `get_by_link()` convention.
    See §5a for the audit.
  - **Fix `is_public()` to also check `fil_tier_min_level`.** The
    current implementation only checks `fil_delete_time`,
    `fil_min_permission`, `fil_grp_group_id`, `fil_evt_event_id` —
    a file gated only by `fil_tier_min_level` is wrongly classified
    as public. This is a pre-existing bug in the local
    fast-serve gating, but the cloud feature would amplify it by
    pushing tier-gated files into a world-readable bucket. Fix
    `is_public()` first; cloud eligibility then derives correctly.
  - `get_url()`: dispatch to driver for `'s3'` rows, existing path otherwise.
  - `get_filesystem_path()`: keeps its existing contract — returns
    the would-be-local path. Does **not** throw on `'s3'` rows;
    instead, when called on an `'s3'` row, emits a single
    `error_log('CLOUD_STORAGE_UNEXPECTED_LOCAL_PATH_QUERY: fil=' . $this->key)`
    warning so we can surface any caller we missed without breaking
    them. The four in-tree callers (`permanent_delete`,
    `delete_resized`, `resize`, `regenerate_image_sizes.php`) all
    dispatch on the driver flag *before* calling
    `get_filesystem_path()`, so the warning should never fire in
    practice. See the caller audit below.
  - `delete_resized()`: dispatch on `fil_storage_driver` at the top.
    For `'s3'` rows, iterate sizes and call `driver->delete()` per
    variant key. For `'local'` rows, existing behavior (current
    `get_filesystem_path()` → `unlink` loop).
  - `move_to_correct_directory()`: extend to handle cross-storage
    transitions (public→private pulls back from bucket; private→public
    pushes to bucket if cloud is enabled).
  - `permanent_delete()`: dispatch on `fil_storage_driver` at the
    **top of the method**. Cloud branch: `driver->delete()` for
    original + variants (best-effort with `CLOUD_STORAGE_ORPHAN`
    log on failure, per §8). Local branch: existing
    `get_filesystem_path()` → `unlink` flow.
  - `resize()`: dispatch on `fil_storage_driver` at the top. For
    `'s3'` rows: `driver->get(<original_key>, <temp_path>)` →
    derive a temp upload_dir → run the existing variant generation
    against temp → `driver->put()` each variant back to
    `<prefix>/<size>/<filename>` → drop all temp files. For
    `'local'` rows, existing behavior.

  **`get_filesystem_path()` caller audit (all four sites in tree):**

  | Site | Treatment |
  |------|-----------|
  | `files_class.php:319` (`permanent_delete`) | Dispatch on driver before calling. |
  | `files_class.php:359` (`delete_resized`) | Dispatch on driver before calling. |
  | `files_class.php:376` (`resize`) | Dispatch on driver before calling. |
  | `utils/regenerate_image_sizes.php:93` | Drop the explicit `file_exists($source_path)` probe and let the now-driver-aware `resize()` handle bytes acquisition internally. The script's loop becomes: `try { $file->resize('all'); } catch (...) { errors++ }` — no path probing. |
- `includes/UploadHandler.php`
  - `get_unique_filename()` extended to also consult `fil_files` (see
    §3a) so collision detection works regardless of where bytes live.
  - No bucket I/O in the upload path; the sync task handles that
    asynchronously (§4a).
- `serve.php`
  - `/uploads/*` route extended to 302-redirect cloud-stored files to
    their bucket URL (see §5a), preserving pre-migration URLs in
    emails, search indexes, and embedded HTML.
- `settings.json` — declare the new `storage_*` settings.
- `composer.json` — add `aws/aws-sdk-php` if not already present.
  The existing upgrade pipeline (`utils/upgrade.php` →
  `utils/composer_install_if_needed.php`) and Docker build flow
  install dependencies automatically after every file swap, with
  rollback-on-failure. No manual composer step is required during
  deployment.

---

## Provider compatibility

The driver speaks the S3 API. In principle it works with any
S3-compatible service, but real-world differences (addressing style,
region strings, public-bucket semantics, error responses) mean each
provider needs hands-on verification before being recommended.

| Provider | v1 status | Notes |
|----------|-----------|-------|
| Backblaze B2 (S3 API) | Verified before ship | Path-style endpoint. Bucket-level public. The Cloudflare Bandwidth Alliance pairing is the cheapest realistic option for most customers. |
| AWS S3 | Verified before ship | Virtual-hosted-style preferred. Reference implementation. |
| Cloudflare R2 | Should work, unverified | Free egress. S3-compatible API. |
| Wasabi | Should work, unverified | Free egress up to monthly storage allowance. |
| DigitalOcean Spaces | Should work, unverified | Includes a CDN option (`*.cdn.digitaloceanspaces.com`). |
| MinIO (self-hosted) | Should work, unverified | Path-style. Useful for development and on-prem deployments. |

Customers using unverified providers self-test via Test Connection.
We don't actively support them but won't refuse to talk to them.

## Pre-implementation verification checklist

Before this feature ships, the implementer should run through this
list against a live B2 + Cloudflare setup (the recommended pattern)
and a live AWS S3 setup (the reference). Each item should pass
visibly.

**Driver basics:**
1. `Test Connection` writes a scratch probe at
   `<prefix>/_joinery_probe-<rand>.txt`, reaches it via the
   public URL, then deletes it. If DELETE is denied, the test
   surfaces a yellow note and continues — DELETE failures are
   also caught at first real use.
2. With B2 path-style addressing, the probe round-trip works.
3. With AWS virtual-hosted-style addressing, the probe round-trip
   works.
4. Public-URL header check correctly identifies: raw B2, raw S3,
   Cloudflare-fronted B2, CloudFront-fronted S3.

**Upload + serve:**
5. Upload a public image with multiple variants. All variants land
   in the bucket under correct keys (`<site_template>/<size>/<filename>`).
6. `get_url()` returns a working public URL for each variant. Browser
   loads the image without auth.
7. `static_files/uploads/` no longer contains the file after the
   sync task runs.
8. The pre-migration `/uploads/<filename>` URL still works
   (302-redirects to the bucket URL).

**Permission flips:**
9. Soft-delete the file. Pull-back succeeds. Bucket no longer has
   the object. Local restricted directory has it. Public URL 404s.
10. Undelete. File returns to public. After the next sync task tick,
    bytes are back in the bucket.
11. Add a group restriction to a public file. Pull-back works the
    same way.
12. Permanent-delete a bucket-stored file. Bucket and DB row both
    clean up.

**Migration:**
13. Run forward migration on 5–10 pre-existing local public files.
    All migrate successfully. Flag flips. Local copies removed.
14. Run reverse migration on the same files. All return to local.
    Bucket cleans up. URLs still work.

**Concurrency:**
15. Trigger the sync task while uploads are in progress. No corruption.
16. Soft-delete a file while the sync task is mid-push. Re-check
    after PUTs (§4a step 3) catches it; bucket cleans up; row stays
    local.

**Failure modes:**
17. Configure with bad credentials. Test Connection fails clearly.
    Sync task logs failures and backs off. Admin dashboard surfaces
    the failure count.
18. Pull-back with simulated bucket failure (revoke delete permission
    temporarily). Operation aborts cleanly; admin sees error;
    retry after permissions restored succeeds.

**Cost-warning UX:**
19. Configure with a raw B2 hostname. Inline yellow banner appears.
    Test Connection's header check confirms "raw B2." Pre-enable
    confirmation interrupts.
20. Reconfigure with a Cloudflare-fronted custom domain pointing
    at the same bucket. Inline banner clears. Test Connection's
    header check reports "Cloudflare ✓." No interruption on enable.

**Pre-existing-state verification (run against production data
before shipping):**

21. Before enabling cloud storage on each site, run
    `SELECT fil_name, COUNT(*) FROM fil_files WHERE
    fil_delete_time IS NULL GROUP BY fil_name HAVING COUNT(*) > 1`
    by hand and resolve any duplicates manually. The platform has a
    small enough number of deployed sites that this is cheaper than
    building detect-and-skip logic into the migration. The §3a
    `get_unique_filename()` extension still ships, to prevent
    *new* collisions once local-disk is no longer the source of
    truth for whether a name is taken.
22. `grep -rn "move_uploaded_file" plugins/` to verify no plugin
    uploads files outside the central `UploadHandler` flow. Any
    plugin that does needs its own follow-up to participate in
    cloud storage.
23. `grep -rn "move_uploaded_file\|file_put_contents.*upload" api/`
    to verify any API-level upload endpoints flow through
    `UploadHandler`.
24. Verify the `get_filesystem_path()` audit hasn't grown new
    callers since the spec was written: `grep -rn
    "get_filesystem_path" public_html/ --include="*.php"` should
    return the four sites already enumerated in the §10 caller
    audit (`permanent_delete`, `delete_resized`, `resize`,
    `regenerate_image_sizes.php`), the method definition itself,
    and nothing else. Any new caller needs the same dispatch
    treatment.
25. Verify the existing resize-on-demand vs eager-resize behavior
    when a theme adds a new image size. If lazy, document or fix
    the cloud-storage gap (missing variants 404 from the bucket).
26. Test the `/uploads/*` redirect chain end-to-end with a
    cloud-migrated file: confirm the existing
    `static_files/uploads/.htaccess` fallback doesn't create a
    redirect loop in combination with the new bucket-redirect path.

**Implementation tradeoffs to acknowledge explicitly:**

27. Permission flips on a cloud-stored file (soft-delete, etc.)
    run a synchronous pull-from-bucket inside the admin's request.
    For multi-MB images with several variants this is several
    seconds of latency on the click. Acceptable for v1 (soft-delete
    is a low-volume action) but the UI should surface a "Working…"
    state.

## Documentation

At implementation time:

- New `/docs/cloud_storage.md` — driver architecture, settings, bucket
  layout, admin UI, migration, and CDN setup recommendations
  (specifically B2 + Cloudflare Bandwidth Alliance for free egress).
- Extend `/docs/photo_system.md`'s "File Storage Directories" section
  with a paragraph noting that public files may also live in a cloud
  bucket, with cross-reference to `cloud_storage.md`.
