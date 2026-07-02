# File Blob Layer — Physical Storage as a First-Class Object

## Status: active — design

Platform-level refactor, extracted from `specs/drive_core.md` (which depends on
it): physical bytes move out of `fil_files` into a new refcounted
**`fbb_file_blobs`** table. `fil_files` stays the logical file — identity,
ownership, visibility, everything users see. The blob is the physical unit —
stored name, size, hash, storage driver, offload state.

Why at this layer: today one `fil_files` row *is* its bytes, so two logical
files (or two versions of one file) can never share content — which makes
versioning, dedup, and byte-accounting impossible rather than merely unbuilt.
Drive is the first consumer; entity photos, inbound-email attachments, and any
future feature that stores bytes get the same capability for free. There are no
production users; the backfill is a one-time script, not a compatibility layer.

## Current physical-state inventory (verified)

Everything below touches `fil_name` / `fil_storage_driver` / bytes on disk and
is in scope for the refactor:

- `data/files_class.php` — `createFromBytes()` (:117), `read_bytes()` (:169),
  `get_fast_serve_dir()` (:223), `remote_key_for()` (:261),
  `get_filesystem_path()` (:281), `move_to_correct_directory()` (:320),
  `move_single_file()` (:407), overridden `save()`/`soft_delete()`/`undelete()`
  (:453-479), `get_url()` (:488), `permanent_delete()` (:670),
  `_permanent_delete_cloud()` (:725), `delete_resized()` (:765), `resize()`
  (:807), `_resize_cloud()` (:858), `generate_resized()` (:915),
  `_pull_back_from_cloud_to_local()` (:1017), `_cloud_visibility()` /
  `_cloud_driver()` / `_offloaded_visibility()`.
- Upload writers (three independent naming schemes today, collapsed by this
  spec): `adm/logic/admin_file_upload_process_logic.php` (UploadHandler +
  rename with random token, :169-210), `ajax/entity_photos_ajax.php`
  (`time()_` prefix + `move_uploaded_file`, :80-98),
  `File::createFromBytes()` (random 8-char token, :128-140).
- `includes/UploadHandler.php` — `get_unique_filename()` (:467) /
  `fil_files_has_active_row()` (:490).
- `serve.php` `/uploads/*` closure (:370-503) — signed-request check, cloud
  302 (public) / gated stream (private), local fast path.
- `includes/cloud_storage/` — `FileStorageProfile` + `FilePrivateStorageProfile`
  (two profiles sharing `fil_files`, split by public/restricted eligibility
  SQL), `CloudOffloadEngine` (candidate SELECT on
  driver/failed-count/eligibility columns, PUT→reload→flip→unlink invariant),
  `CloudStorageLifecycle::health()` (stuck-rows list special-cases `fil_files`
  columns, :535-544), `storage_profiles.json`.
- `utils/regenerate_image_sizes.php`, `adm/admin_cloud_storage.php` (+ logic),
  `tests/integration/cloud_storage_*`, `tests/integration/cloud_file_private_offload_test.php`,
  `tests/functional/files/signed_urls_test.php`.

## Data model

**`FileBlob` / `MultiFileBlob`** — new core class `data/file_blobs_class.php`,
`$prefix = 'fbb'` (verified free), `$tablename = 'fbb_file_blobs'`,
`$pkey_column = 'fbb_file_blob_id'`. Discovery is directory-glob; no registry
entry needed for the table itself.

```php
public static $field_specifications = array(
    'fbb_file_blob_id'      => array('type'=>'int8','serial'=>true,'is_primary_key'=>true),
    'fbb_stored_name'       => array('type'=>'varchar(255)','is_nullable'=>false,'required'=>true,'unique'=>true),
    'fbb_size_bytes'        => array('type'=>'int8','is_nullable'=>false,'required'=>true),
    'fbb_sha256'            => array('type'=>'character(64)','is_nullable'=>true,'index'=>true),
    'fbb_mime_type'         => array('type'=>'varchar(128)','is_nullable'=>true),
    'fbb_is_private'        => array('type'=>'bool','is_nullable'=>false,'default'=>'false'),
    'fbb_reference_count'   => array('type'=>'int4','is_nullable'=>false,'default'=>1),
    'fbb_storage_driver'    => array('type'=>'varchar(32)','is_nullable'=>false,'default'=>'local'),
    'fbb_sync_failed_count' => array('type'=>'int4','is_nullable'=>false,'default'=>0,'zero_on_create'=>true),
    'fbb_sync_last_attempt' => array('type'=>'timestamp(6)','is_nullable'=>true),
    'fbb_create_time'       => array('type'=>'timestamp(6)','is_nullable'=>false,'default'=>'now()'),
);
```

Not API-exposed (`$api_readable`/`$api_writable` stay inherited-false), not
AI-readable. `$permanent_delete_actions = array()`. `fbb_sha256` is nullable
because cloud-resident rows created by the backfill may hash lazily (below);
dedup only ever matches non-null hashes. `'default'` values are applied by
SystemBase at INSERT (DatabaseUpdater does not emit them into CREATE TABLE), so
every NOT NULL column above carries an app-layer default or `required`.

**`fil_files` changes:** add `fil_fbb_file_blob_id` (`int8`, not-null after
backfill, `'index'=>true`); **remove** `fil_storage_driver`,
`fil_sync_failed_count`, `fil_sync_last_attempt` (they move to the blob).
`fil_name` remains the URL identity (unique among active rows — the
`get_by_name()` reverse map and signed URLs are unchanged); `fbb_stored_name`
is the physical identity. For a freshly uploaded file they are equal; they
diverge only under dedup and versioning.

**Deletion rules:** the blob↔file relationship is deliberately *not* expressed
in `$foreign_key_actions` — `fil_fbb_file_blob_id` is a five-segment column the
rule auto-detector cannot resolve (the `ieg_` grant class documents this
limitation), and refcounting isn't expressible declaratively anyway. Lifecycle
is code-owned: `File::permanent_delete()` calls
`FileBlob::release($blob_id)`, which decrements `fbb_reference_count` inside a
`DbConnector::BeginTransaction()/Commit()` guard and, at zero, deletes the
physical original + every `ImageSizeRegistry` variant (local unlink or cloud
driver delete with the existing `CLOUD_STORAGE_ORPHAN` retry/log behavior) and
the blob row. `FileBlob::retain($blob_id)` is the increment twin. Both use
`inTransaction()` guards per house convention.

## Visibility invariant

Public vs private is physical placement (fast-serve dir vs restricted dir; public
vs verified-private bucket), so it must be a **blob** property — but it is
*derived* from the referencing files. Invariant: **all files referencing a blob
are in the same visibility class**, maintained by:

1. **Dedup scoping** — a dedup match requires equal (`fbb_sha256`,
   `fbb_size_bytes`, `fbb_is_private`). Same bytes public and private = two
   blobs. Correct, and avoids cross-store aliasing.
2. **Visibility change** (the `move_to_correct_directory()` triggers: save /
   soft_delete / undelete with changed placement): refcount 1 → flip the blob
   (move bytes exactly as today, or `_pull_back_from_cloud_to_local` when
   cloud-resident, then re-place). Refcount > 1 → **copy-on-write split**: copy
   bytes to a new blob in the target class, repoint this file, decrement the
   old refcount.
3. **Soft delete exception**: soft-deleting one of several references leaves
   the blob public (identical bytes remain publicly served via the sibling
   file; the deleted file's own URLs are already gated by `is_viewable()`).
   Refcount-1 soft delete flips to private as today; undelete flips back.

## File class after the refactor

Physical methods delegate through `$this->_blob()` (memoized `new
FileBlob($this->get('fil_fbb_file_blob_id'), true)`):

- `remote_key_for($size)` → `original ? stored_name : "{size}/{stored_name}"`.
- `get_filesystem_path()`, `read_bytes()`, `get_url()`, `resize()`,
  `delete_resized()` — same logic, keyed on `fbb_stored_name` and branching on
  `fbb_storage_driver`. Variants live at `<size>/<stored_name>` locally and in
  the bucket, shared by all referencing files.
- `mintSignedUrl()` / `verify_signed_request()` — unchanged (HMAC over file id
  + size key + expiry; resolution goes through the blob at serve time).
- `is_public()` / `is_viewable()` / all visibility gates — unchanged (logical).
- `move_to_correct_directory()` — becomes the invariant-maintenance entry
  point (§ above).

**One byte-ingestion path.** New statics replace the three naming schemes:

- `FileBlob::createFromPath($path, $mime, $is_private)` — sha256 + filesize,
  dedup lookup (`sha256 + size + is_private`, non-null hash only); hit →
  `retain()` existing blob and discard the new bytes; miss → move bytes to a
  collision-free `fbb_stored_name` (random-token scheme from
  `createFromBytes`) in the correct dir and insert.
- `File::createFromBytes()` (existing signature kept — inbound email is a
  caller) and a new `File::createFromUpload($tmp_or_path, $display_name,
  $mime, $owner_id, $restrictions)` both route through it.
- `adm/logic/admin_file_upload_process_logic.php` and
  `ajax/entity_photos_ajax.php` are rewritten onto `File::createFromUpload()`;
  `UploadHandler::get_unique_filename()`'s DB probe moves to
  `fbb_stored_name`; the `fil_name` active-row uniqueness check stays (URL
  identity).

## Storage profiles

`FileStorageProfile` / `FilePrivateStorageProfile` are **replaced** by
`BlobStorageProfile` / `BlobPrivateStorageProfile` over `fbb_file_blobs`
(pattern proven by inbound_email's `RawMessageStore`: profile owns
offload-eligibility; consumers own request-time I/O):

- Columns: `fbb_storage_driver` / `fbb_sync_failed_count` /
  `fbb_sync_last_attempt`; `visibility()` `'public'` / `'private'`;
  `eligibilityWhere()` = `fbb_is_private = FALSE` (public profile) /
  `= TRUE` (private); `reverseEligibilityWhere()` mirrors it (the two profiles
  share the table — same split mechanism the File pair uses today).
- `itemsForRow()` / `reverseItemsForRow()` enumerate original + ImageSizeRegistry
  variants when `fbb_mime_type` is an image; local paths derive from
  `fbb_is_private` (fast dir vs upload dir).
- `storage_profiles.json` → `["BlobStorageProfile","BlobPrivateStorageProfile"]`.
- `CloudOffloadEngine` needs **no changes** (it is already profile-generic).
  `CloudStorageLifecycle::health()`'s stuck-rows special case (:535) switches to
  `fbb_stored_name`/`fbb_sync_*`; `adm/admin_cloud_storage.php` retry UI
  follows.

## serve.php `/uploads/*` closure

Same flow, one added hop: `File::get_by_name(basename)` → `_blob()` →
branch on `fbb_storage_driver` + `fbb_is_private`. Public cloud → 302 to
`driver->url(remote_key)`; private cloud → gate then stream; local → fast path
via `RouteHelper::serveStaticFile` when the file exists at
`fast_dir/<subpath>`. Dedup consequence: a secondary reference's `fil_name`
has no physical file under its own name, so the fast-dir `.htaccess` 302
fallback (written by `move_to_correct_directory()`, files_class:369-377)
lands it in this closure, which resolves the blob and serves — existing
mechanism, no new code path.

## Backfill

One-time script `maintenance_scripts/dev_tools/backfill_file_blobs.php` (CLI):
iterate `fil_files` in batches; per row create a blob with
`fbb_stored_name = fil_name`, `fbb_is_private = !is_public()`,
`fbb_storage_driver` copied from the old column, refcount 1; local rows get
`filesize()` + `hash_file('sha256')`; cloud rows get size via S3 `headObject`
(AWS SDK already present) and `fbb_sha256 = NULL` (hashed opportunistically on
any future drain/read — dedup simply doesn't match them until then). Then set
`fil_fbb_file_blob_id`. The old `fil_*` storage columns are dropped from
`$field_specifications` in the same change; run order: add class + column →
backfill → remove old columns (single deploy, no production users).

## Tests

House style (CLI, `check()` assertions, fixtures torn down in `finally`,
non-zero exit on failure — per `tests/functional/files/signed_urls_test.php`):

- `tests/functional/files/blob_layer_test.php` — createFromUpload → blob row,
  dedup hit retains (+refcount, no second physical file), visibility flip at
  refcount 1 moves bytes, COW split at refcount > 1, release-at-zero deletes
  bytes + variants + row.
- Update `tests/integration/cloud_storage_*` and
  `cloud_file_private_offload_test.php` to the blob columns/profiles.
- `tests/models/` ModelTester covers `FileBlob` automatically once field specs
  are valid.
- `signed_urls_test.php` must pass unmodified (serving contract unchanged).

Run `php -l` + `validate_php_file.php` on every touched file.

## Docs

On ship: update `docs/cloud_storage.md` (profiles now blob-based; bucket layout
keyed by stored name — layout itself is unchanged) and the file-storage
portions of `docs/photo_system.md` if method surfaces shift. New
`docs/drive.md` (from drive_core) documents the blob model for feature
developers. Current-state voice only.

## Out of scope

Folders, quotas, versioning, sharing, upload API, change feed — all in
`specs/drive_core.md`, which consumes this layer. Encryption:
`specs/drive_encryption.md` (ciphertext blobs flow through this layer
untouched).

## Open decisions (resolve at implementation)

- Whether `FileBlob::release()` deletes bytes inline or marks the blob
  (`fbb_reference_count = 0`) for a sweep task (inline proposed — matches
  today's `permanent_delete()` behavior; the engine's advisory locks prevent
  offload races on the row).
- Whether cloud-resident backfilled rows get a lazy-hash task or hash only on
  drain (lazy task proposed, piggybacking `CloudOffloadRun`'s tick budget).
