# File Blob Layer — Physical Storage as a First-Class Object

Every uploaded file on the platform splits into two objects:

- **`File`** (`fil_files`) — the *logical* file. Identity, ownership, visibility,
  title, the URL a user links to. Everything a user sees.
- **`FileBlob`** (`fbb_file_blobs`) — the *physical* bytes underneath it. The
  stored name on disk / in the bucket, size, content hash, storage driver, and
  offload state. A `File` references one blob via `fil_fbb_file_blob_id`.

Many files can point at one blob. That is what makes content **dedup**, byte
accounting, and (once Drive lands) versioning possible: two logical files can
share one physical copy. The blob carries a **reference count** and is reclaimed
only when the last file lets go.

Any feature that stores bytes — entity photos, inbound-email attachments, AI
chat uploads, profile pictures, Drive — gets this for free by going through the
one ingestion path below. No feature touches `fbb_file_blobs` directly.

## Storing bytes

There is exactly one byte-ingestion path. Use it; never write a `File` row and a
file on disk by hand.

```php
// From an uploaded temp file (a $_FILES entry, or any staged path):
$file = File::createFromUpload($tmp_path, $display_name, $mime, $owner_id, $restrictions);

// From bytes you already hold in memory (generated / received / fetched):
$file = File::createFromBytes($bytes, $display_name, $mime, $owner_id, $restrictions);
```

`$restrictions` is an array of visibility columns set at creation, e.g.
`['fil_private' => true]` or `['fil_min_permission' => 5, 'fil_source' => File::SOURCE_ENTITY_PHOTO]`.

Both route through `FileBlob::createFromPath()`, which:

1. Hashes the bytes (sha256) and measures the size.
2. **Dedup lookup** — a live blob with the same `(sha256, size, is_private)`?
   Hit → retains that blob (refcount +1) and discards the new bytes. Miss →
   moves the bytes into the correct visibility directory under a collision-free
   `fbb_stored_name` and inserts a new blob.
3. Detects the honest MIME type from the bytes (the caller's `$mime` is only a
   fallback) and stores it — so `fil_type` is trustworthy by construction, never
   by caller discipline.

For a fresh, non-deduped upload the file's `fil_name` (URL identity) equals the
blob's `fbb_stored_name` (physical identity). They diverge only under dedup and
versioning: a deduped file gets its own unique `fil_name` but points at the
shared blob, and the serving path resolves the physical bytes through the blob.

Image variants are **not** generated automatically — call `$file->resize()` when
you want them (it delegates to the blob; variants live at `<size>/<stored_name>`
and are shared by every referencing file).

## Reference counting and deletion

Lifecycle is code-owned (refcounting isn't expressible in declarative deletion
rules):

- `File::permanent_delete()` calls `FileBlob::release($blob_id)`. Release
  decrements the count inside a transaction guard and, **at zero**, deletes the
  physical original + every image variant (local unlink or cloud driver delete)
  and the blob row. A blob still referenced by another file is left untouched.
- `FileBlob::retain($blob_id)` is the increment twin (a new file pointing at an
  existing blob — the dedup path calls it).

The at-zero delete takes the offload engine's per-row advisory lock before
removing bytes, so it never unlinks bytes the `CloudOffloadEngine` is mid-push.

A practical consequence for tests and callers: deleting one of several files
that share a blob (identical bytes) does **not** remove the bytes — they survive
for the remaining references. That is correct dedup behavior, not a leak.

## Visibility

Public vs private is a *physical placement* — public bytes go to the fast-serve
directory / public bucket, private bytes to the restricted directory /
verified-private bucket — so it is a **blob** property, `fbb_is_private`. But it
is *derived* from the referencing files. The invariant: **every file referencing
a blob is in the same visibility class.** It is maintained by:

- **Dedup scoping.** A dedup match requires equal `(sha256, size, is_private)`.
  The same bytes uploaded once public and once private become two blobs — never
  a shared blob straddling both stores.
- **Visibility change** (`File::move_to_correct_directory()`, called after
  `save` / `soft_delete` / `undelete`). When a file's desired class no longer
  matches its blob's:
  - refcount 1 → **flip** the blob (move its bytes between dirs, or pull home
    from the wrong bucket).
  - refcount > 1 → **copy-on-write split**: copy the bytes to a new blob in the
    target class, repoint this file, decrement the old blob. The siblings keep
    the shared blob.
- **Soft-delete exception.** Soft-deleting one of several references leaves a
  shared blob where it is — the deleted file's own URLs are already gated by
  `File::is_viewable()`, and the siblings keep serving. A refcount-1 soft delete
  flips the blob private (as before); undelete flips it back.

## What lives where

| Concern | Owner |
|---------|-------|
| Identity, ownership, title, `fil_name` (URL), visibility gates, signed URLs | `File` (`data/files_class.php`) |
| Stored name, size, sha256, MIME, driver, offload counters, refcount | `FileBlob` (`data/file_blobs_class.php`) |
| Paths, reads, resize, variant layout, cloud put/get/delete, pull-back, flip / split | `FileBlob` — `File` delegates (`get_filesystem_path`, `read_bytes`, `remote_key_for`, `resize`, `delete_resized`, `storage_driver`) |
| Offload eligibility + per-row enumeration for the shared engine | `BlobStorageProfile` / `BlobPrivateStorageProfile` |

Cloud offload of blob bytes runs through the platform's unified offload engine —
see [Cloud Storage](cloud_storage.md). Signed short-lived links to private files
are unchanged — see [File Signed URLs](file_signed_urls.md).

---

# Drive — folders, quotas, sharing, versioning

The member Drive at `/drive` is personal file storage built on the blob layer
above. It adds folders, a per-member quota, file versioning, sharing (member
grants and public links), trash, a resumable upload protocol, and a change feed
for sync clients. It is off by default: the `drive_active` setting gates the page,
the menu entry, and every `drive_*` action.

## Data model

Drive files are ordinary `File` rows (private, `fil_source = 'drive'`) placed
in the tree by `fil_fol_folder_id` (NULL = the drive root, which is implicit —
there is no root row). The `drive` source tag is the boundary of the whole
feature: listings, search, trash, the trash purge, and quota accounting all
scope to it, and `DriveHelper::load_file` resolves only Drive files — so a
Drive verb can never list, trash, share, or destroy a file another subsystem
owns (an avatar, a mail attachment, a store image). Everything else is a small
model of its own:

| Model | Table | Role |
|-------|-------|------|
| `Folder` | `fol_folders` | A node in a user's tree. Sibling-name uniqueness among live rows is a partial unique index on `(owner, COALESCE(parent,0), name) WHERE delete_time IS NULL`. |
| `DriveUsage` | `dru_drive_usage` | One recomputed byte total per user (the quota gate + storage meter). |
| `FileVersion` | `fvr_file_versions` | Prior content of a file — one row pins one historical blob. |
| `FileAccessGrant` | `fga_file_access_grants` | A share of a file/folder to another member (`viewer` / `editor`). |
| `FileShareLink` | `fsl_file_share_links` | A durable, revocable public link (`/s/{token}`). |
| `FileChange` | `fch_file_changes` | Append-only change feed; the primary key IS the sync cursor. |
| `FileUpload` | `fup_file_uploads` | Pending state for a resumable upload. |

Access and tree logic lives in `includes/DriveHelper.php`; the verbs are `drive_*`
API actions (`logic/drive_*_logic.php`, each with a `_logic_descriptor()`), which
page JavaScript (`assets/js/drive.js`) calls with the browser-session credential.

## Access and grant semantics

A Drive file is private, so `File::is_viewable()` returns true for the owner or an
admin. Sharing adds one clause: a `viewer` or `editor` grant **on the file, or on
any ancestor folder** also grants view. The one grant-reach implementation is
`DriveHelper::grant_reaches()` (a recursive-CTE ancestor walk bounded by
`drive_max_folder_depth`); `is_viewable` and every `drive_*` action resolve
sharing through it. `DriveHelper::can_write()` adds the same reach for `editor`
— an editor may rename, save a new version, and upload into a shared folder.
**Delete, trash, and share stay owner-only**; an editor grant never confers
them. "Shared with me" is `FileAccessGrant::entity_ids_for_user`; filename
search spans the caller's own files plus granted files and granted folder
subtrees.

**Trees have a single owner.** Everything under a folder belongs to the
folder's owner: an upload into a shared folder creates a file **owned by (and
billed to) the folder owner**, whoever performs it (the actor is recorded in
the change feed), and `drive_move` only accepts a destination folder owned by
the moved item's owner. That invariant is what makes the folder trash and
delete-forever cascades — which select by folder — safe: they can never touch
another user's rows. It is also why a grantee browsing a shared folder sees
the full listing (children are listed by the folder owner's id). A grantee's
breadcrumb is cut at the topmost folder that carries a direct grant for them,
so the owner's private ancestor names are never exposed.

Grant management is a full-set reconcile: `drive_share_sync` takes `grants` (a
JSON object mapping each grantee — a user id **or** an email, resolved
server-side — to a role) and `FileAccessGrant::sync_for_entity` inserts new
grants, updates changed roles, and hard-deletes ones no longer in the set
(revocation is a row deletion; there is no `delete_time`). Newly-granted users get
a `drive_share` notification, gated by their `NotificationPreference`.

Public links (`drive_link_create`, owner-only, gated by the `drive_share_links`
tier feature) mint a `FileShareLink`: the raw token is returned once, only its
SHA-256 is stored. A live link (not revoked, not past `fsl_expires_time`, password
satisfied) grants **view** at `/s/{token}` — anonymous, no login. The page streams
files through a short-lived signed URL and renders folders as a read-only listing
scoped to the shared subtree; the link is the durable revocable grant, the signed
URL is the transport. `drive_link_revoke` stamps `fsl_revoked_time`.

**Encrypted files** (files inside an encrypted vault folder) add a second layer to
the share dialog: a `FileAccessGrant` grants access, and a `FileKeyGrant` grants
readability — the owner's browser wraps the file key to each recipient's Drive
vault public key, and an upload seals the new file's key to the destination's
full reader set. An encrypted vault folder exists only at the Drive root or
inside another vault, so a plaintext subtree never hides encrypted content.
Encrypted files rename via their re-encrypted metadata (`drive_rename` with
`encrypted_metadata`), never a plaintext `name`. Public links carry the file key
in the URL fragment and are single-file only (encrypted folders can't use them).
See [Drive Encryption](drive_encryption.md).

## Upload protocol (resumable, sequential chunks)

Uploads never overwrite bytes directly — they flow through the one blob-ingestion
path, so dedup and accounting are automatic. The protocol is the complete server
contract for sync clients:

1. **`drive_upload_init`** — `{name, folder_id?, file_id?, size_bytes, sha256?,
   mime_type?}`. Gates folder/file write access, then the per-file size
   (`drive_max_file_bytes`; for an encrypted destination the gate is
   `DriveHelper::encrypted_size_ceiling()` — the cap plus the client
   container's fixed per-chunk overhead, since the cap means plaintext bytes
   and the upload arrives as ciphertext) and quota (`bytes_used + size_bytes <=
   drive_storage_bytes`) **of the owner who will be billed** — the target
   file's owner, else the destination folder's owner, else the actor. If
   `sha256` matches a private blob **the actor already possesses** it
   **short-circuits**: retain the blob, create the `File` (or a new
   `FileVersion` when `file_id` is set), and return the file — no bytes
   transferred. Possession means the blob is already referenced by one of the
   actor's own files or file versions (`FileBlob::find_dedup`'s
   `$possessed_by_user_id`): a client-claimed hash is not proof of content, so
   a foreign hash+size never matches — no cross-user disclosure and no
   existence oracle. Otherwise it creates a `FileUpload` and returns
   `{upload_token, chunk_bytes}` (the raw token; only its hash is stored).
2. **`PUT /api/v1/drive_upload/{token}`** — the raw-body chunk transport (a
   pre-CRUD branch in `api/apiv1.php`, the inbound twin of `management/backups/fetch`).
   Chunks are **sequential only**: the request carries `Content-Range: bytes
   <start>-<end>/<total>`, and `<start>` must equal the server's `received_bytes`
   or the response is **409** with `{received_bytes}` so the client resumes from
   the right offset. Appends are serialized per upload with a Postgres advisory
   lock, and the offset is re-read under the lock, so concurrent PUTs for one
   token cannot interleave writes. `GET` on the same path returns
   `{received_bytes, expected_bytes}`. Scratch bytes accumulate in a part-file
   under `{site_root}/storage/drive_uploads/`, outside the web root. Chunk
   requests use their own `api_upload` rate-limit bucket so a large upload never
   drains the general API budget; the transport's response helpers write the
   request-log row with the actual outcome (success on 2xx, failure with the
   status code otherwise).
3. **`drive_upload_complete`** — `{upload_token}`. Verifies the byte count and
   (when given) the sha256, re-validates write access, and **enforces the quota
   here, where bytes are admitted to storage**: under a per-owner advisory lock
   it recomputes usage fresh and rejects the complete if the upload would land
   past `drive_storage_bytes` (the init check is only a fast-fail — N uploads
   opened while under quota cannot all complete past it; a rejected upload
   keeps its pending row so the user can free space and retry). Then it ingests
   through `FileBlob::createFromPath` (server-side dedup applies — the server
   hashed the actual bytes), creates the `File` owned by the folder owner — or
   a new `FileVersion` when the upload targeted an existing file — recomputes
   the owner's usage, records the change, and clears the pending row. Safe to
   retry, and covered by the standard `Idempotency-Key` machinery. For an
   encrypted destination the complete also carries the opaque key/metadata
   payloads, and an encrypted **version** upload must reuse the file's key —
   see [Drive Encryption](drive_encryption.md) for that contract.

Chunk size is `drive_upload_chunk_bytes` (8 MiB). Abandoned uploads (rows + part
files idle > 24h) are swept by the `DrivePurgeStaleUploads` task.

## Versioning

Saving new content to a file **demotes the current head blob to a `FileVersion`
and repoints the file at the freshly-ingested blob** — the head blob's reference
is transferred to the version row, so refcounts stay correct without a
retain/release. Restoring swaps the roles back. Both prune to the owner's
`drive_versioning_depth` (oldest versions released and deleted). Because a version
IS a reference to its blob, `FileVersion::permanent_delete` releases it, and the
file's `fvr_fil_file_id` rule is `permanent_delete` so deleting a file releases
every version's bytes. `drive_versions` lists a file's history; `drive_version_restore`
promotes one back to head. The web UI uploads a new version by sending `file_id`
to `drive_upload_init`.

## Quota accounting

`DriveUsage` is **recomputed, never incremented** — `DriveUsage::recompute($user)`
sums the blob sizes of the user's **Drive** files (`fil_source = 'drive'`) plus
the versions of those files, inside the caller's transaction, after every
upload / new version / restore / permanent delete. Version bytes bill the
**file's owner** (a version row's `fvr_usr_user_id` records who saved it —
audit only, since an editor may save a version of someone else's file), and an
upload into a shared folder bills the folder owner, per the single-owner-tree
rule above. Each logical file bills its full size even when its bytes are
deduped onto a shared blob (dedup saves disk, not quota); trashed files count
until purged. `DriveUsage::current_bytes($user)` is a row-free read for the
storage meter (the `/drive` page render is a GET and must not create a row). The
daily `DriveUsageReconcile` task re-runs the sum for every file-owning user as a
drift backstop.

## Trash

Trashing soft-deletes; `DriveHelper::soft_delete_folder_cascade` deletes the
folder **first** (earliest timestamp in its cascade) then every descendant, so
`restore_folder_cascade` — which restores only descendants with
`delete_time >= the folder's own` — leaves a child trashed independently earlier
in the trash. "Delete forever" runs an impact preview
(`DriveHelper::delete_impact`) before `permanent_delete_tree` destroys the subtree
(a raw folder permanent-delete instead orphans its files to root via the
`fil_fol_folder_id` → `null` rule). The `DrivePurgeTrash` task permanently deletes
**Drive** items (`fil_source = 'drive'`, plus folders) trashed longer than its
window (default 30 days) — a soft-deleted file belonging to another subsystem
is that subsystem's to reclaim and is never touched. Restoring a folder whose
parent is still in the trash re-roots it (with a name-collision suffix when
needed) so it never reappears inside an unreachable parent.

## Change feed

Every mutation records one `FileChange` via `FileChange::record($kind,
$entity_type, $entity_id, $owner_id, $actor_id)` — kinds `created`, `content`,
`renamed`, `moved`, `trashed`, `restored`, `deleted`, `grant_changed`. The feed is
append-only and the primary key is the cursor. `drive_changes` takes a `{cursor}`
and returns the changes after it that the caller may see — their own entities plus
entities shared to them — with `next_cursor`. A nonzero cursor that cannot be
proven contiguous with the retained window — it points before the earliest
retained row, **or the log is empty after a purge** — returns `{reset: true}`
so the client re-lists from scratch rather than silently missing changes.
`DrivePurgeChanges` trims rows past its window (default 90 days).

## Settings and tier features

Settings (`settings.json`): `drive_active` (`'0'`), `drive_max_folder_depth`
(`'32'`), `drive_upload_chunk_bytes` (`'8388608'`), `api_upload_rate_limit_requests`
(`'10000'`), `api_upload_rate_limit_window` (`'3600'`).

Tier features (`includes/core_tier_features.json`), read via
`SubscriptionTier::getUserFeature($uid, $key, $default)`: `drive_storage_bytes`
(total quota; 0 disables uploads), `drive_max_file_bytes` (per-file cap; 0
disables), `drive_share_links` (boolean), `drive_versioning_depth` (versions
kept). All default to the fail-closed value for a tierless member.
