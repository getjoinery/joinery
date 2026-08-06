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

## Protection levels

Every folder carries a protection level (`fol_protection_level`), and every file
records the level it was stored at (`fil_protection_level`). Drive shows three
rungs of the platform ladder (`includes/ProtectionLevel.php`):

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| Promise | The server manages these files for you | Encrypted at rest — opened only while you're present | Plaintext never exists on the server |
| Custody | none | server (sealed to your vault, opened in-window) | client (your browser holds the keys) |
| Bytes on disk | plaintext | `SealedFileContainer` | browser-made ciphertext |
| Previews, thumbnails, AI | always | while your unlock window is open | never, server-side |
| Names, sizes, types | plaintext | plaintext | encrypted (in `fil_encrypted_metadata`) |
| Public links | yes | no | files only, key in the URL fragment |
| Member grants | yes | no | yes (per-user wrapped keys) |
| Sync clients | yes | no — a daemon holds no unlock window | yes, under device custody |
| Survives a stolen database or backup | ✗ | ✓ | ✓ |

`File::is_encrypted()` means Fortress, exactly and only; `File::is_sealed()`
means Private. Both exports carry `protection_level` and a `syncable` flag, and
`encrypted` on an export means Fortress so a client that only ever knew two
modes keeps reading it.

A Private file's export also carries **`requires_window: true`** — its bytes and
its thumbnail open only inside the owner's unlock window, and that window is
keyed to the browser session (see [Sealed Vault](sealed_vault.md)). A caller
holding an API key has no session cookie to present when it follows a link, so
for that caller `download_url` and `thumb_url` are omitted rather than minted:
every one of them would answer `423`, and a listing of broken tiles is a worse
answer than a stated reason. A browser-session caller gets the URLs as normal.

**A protected tree is a top-level tree.** A folder's level is the floor for
everything inside it, and subtrees are uniform: a level is chosen when a
top-level folder is created, a subfolder inherits its parent's, and the
create/move/link triad refuses anything else. That uniformity is what lets a
public link on a Standard folder trust its whole subtree.

**A refusal reads a file's effective level** — the stronger of its own and its
folder's (`DriveHelper::effective_file_level()`). The two disagree on purpose
while a level change converges: the folder's column is the truth about what is
*promised*, the file's about what its *bytes* are. Public links and member grants
are refused on the promise, so nothing is minted in that gap only to be revoked
when the batch arrives. Byte work — sealing, unsealing, quota — reads the file's
own level, which is the one describing what is on disk. A move that would seal a
file carrying a live link or grant is refused outright, naming what is in the
way.

**Changing a level** (`drive_level_change`) is Standard ↔ Private only — the two
the server holds a key wrapping for. The folder changes at once, so everything
uploaded from that moment lands at the new level; the files already inside are
converted afterwards by repeated `drive_level_batch` calls, each bounded by a
byte budget rather than a row count. Raising needs only the owner's vault public
key and so runs locked; lowering decrypts and needs the window. Going Private
ends any public links and member grants in the subtree — the first call reports
them and does nothing until the caller confirms.

Private is described in full in [Drive Encryption](drive_encryption.md), which
covers both custody models.

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

> **This protocol is not Drive-only.** It is the platform's route for any file
> larger than a single request, and other subsystems use it by passing a `purpose`
> to `drive_upload_init` — see [API § Uploading something that is not a Drive
> file](api.md#uploading-something-that-is-not-a-drive-file). Everything described
> below is the `drive` purpose specifically: quota, folders, encryption and the
> dedup short-circuit belong to Drive and do not run for others.

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
files idle past `drive_stale_upload_retention_hours`, default 24) are swept by the
daily retention sweep.

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
`fil_fol_folder_id` → `null` rule). The daily retention sweep permanently deletes
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
The daily retention sweep trims rows past `drive_change_feed_retention_days`
(default 30).

## Sync clients

A desktop client keeps a folder on a computer matching the user's Drive. Four
server surfaces serve it, on top of the change feed above. The client itself —
its state model, conflict policy, per-filesystem naming rules, and health model
— is **[Drive Sync Client](drive_sync.md)**.

### Content identity on exports

`DriveHelper::file_export` carries three fields a syncing client needs and a
browser ignores:

| Field | What it is |
|---|---|
| `content_sha256` | The head blob's hash. Plaintext bytes for a plaintext file, ciphertext bytes for an encrypted one — either way it identifies the content a client holds. |
| `modified_time` | The mtime the uploading client declared (`fil_content_modified_time`), so a file copied back down keeps its original timestamp. Plaintext files only. |
| `head_change_id` | The feed position of the change that established the current content (`content`, or `created` for a file uploaded once). Lets a client say "what I have matches position N" without hashing. `0` means no feed row exists — compare hashes instead. |

An encrypted file never reports a plaintext `modified_time`: a timestamp on
ciphertext would leak when the file was last worked on, so the real mtime lives
inside the encrypted metadata blob. `drive_upload_init` refuses a
`modified_time` parameter for a vault destination for the same reason.

Listings prime these in two queries for the whole page
(`DriveHelper::prime_sync_meta()`); a mutation that changes head content within
a request calls `DriveHelper::forget_sync_meta()` so the export it returns
reflects what it just wrote.

### `drive_stat` — batch fetch

The change feed carries id-only rows so it stays cheap however far behind a
client is; this turns a list of ids back into entities in one round trip.
`{entities: [{entity_type, entity_id}, …]}`, up to 500, deduped. Entities that
are gone or no longer visible come back under `missing` rather than as an
error — a client must be able to tell "delete the local copy" from "retry
later". Signed URLs are withheld unless `urls: true`.

### `drive_index` — full walk

Cold start, and whatever `drive_changes` returns `{reset: true}`. Keyset
paginated: pass the previous page's `next_after_id` back as `after_id` and stop
when `done` is true. The cursor is an opaque token (`folder:123` / `file:456`)
because folders and files have separate id spaces and a bare integer cannot say
which it points into. Folders come before files, so a client materializing as it
reads always has somewhere to put the next file.

`scope: 'mine'` walks everything the caller owns; `scope: 'shared'` walks
everything they reach through grants, each item annotated with `grant_root` —
the granted entity it hangs off — so the client can mount it under the right
"Shared with me" root. Trashed items are included with `deleted: true`: a client
that could not see the trash would read a trashed file as vanished and delete
the local copy, and would never recognize a restore.

### `drive_vault_status` — lean vault probe

`{scope: 'drive'}` → `{set_up, public_key, key_generation}`, reachable with a
session key. Enough to seal file keys for uploads and to notice a key rotation.
Wrappings, salts, and KDF parameters are unlock material and stay on the
browser-only `vault_client_status`.

## Device linking

A sync client cannot sign a user in well: it has no trustworthy password field,
WebAuthn does not work outside a browser, and a passkey-first account has no
password at all. So it does not try. It opens a ceremony, shows a code, and the
user approves in the browser they are already signed into.

1. The client calls `POST /api/v1/auth/device_link` with its name, platform, and
   (optionally) an X25519 public key, and gets a code plus a poll token.
2. The user opens `/profile/devices/link?code=…`, which requires a signed-in
   session and a recent step-up, and shows what is asking — name, platform, and
   the address the request came from. If they have encrypted folders they may
   tick a box to give this device access: the browser unlocks the vault and
   seals the vault secret key to the device's public key
   (`VaultKeyring` session `sealSecretKeyTo()`). Approval calls
   `drive_device_link_approve`, which mints the session `ApiKey`, creates the
   `SyncDevice`, and parks the sealed key and the encrypted one-time secret on
   the ceremony row.
3. The client polls `GET /api/v1/auth/device_link/{poll_token}` and collects the
   credential exactly once; the row is scrubbed immediately after.

**Data model.** `SyncDevice` (`sde_sync_devices`) is the device identity, paired
1:1 with the session key it authenticates with, holding the device public key,
last check-in, and last acknowledged cursor. `DeviceLink` (`dlk_device_links`)
is the ten-minute ceremony state: code and poll token stored only as hashes, the
minted secret SecretBox-encrypted at rest and deliverable once.
The daily retention sweep removes finished rows past
`drive_device_link_grace_minutes` (default 60).

Codes are 8 characters of a Crockford-style alphabet, and lookalike characters
fold on entry (`O`→`0`, `I`/`L`→`1`) so a font cannot defeat someone reading a
code off another screen. Wrong codes are counted per IP address rather than per
ceremony — a guesser by definition matches no row — and 20 misses in 15 minutes
shuts that address out.

**Management.** `drive_devices`, `drive_device_rename`, `drive_device_revoke`,
surfaced on `/profile/security` alongside App Sessions. Revoking unlinks the
device *and* revokes its session key; a device row without its credential
revoked would be a list that lies. Bytes already downloaded stay on that
computer — what stops is future access.

**Liveness comes for free.** When the caller of `drive_changes` maps to a
`SyncDevice`, the handler stamps `sde_last_seen_time` (throttled to hourly, like
`apk_last_used_time`) and `sde_last_cursor` from the request. There is no
separate heartbeat call and therefore no client that can forget to send one, and
the security page can say "last synced 4 minutes ago" — which is what makes a
stalled device visible instead of silent.

## Settings and tier features

Settings (`settings.json`): `drive_active` (`'0'`), `drive_max_folder_depth`
(`'32'`), `drive_upload_chunk_bytes` (`'8388608'`), `api_upload_rate_limit_requests`
(`'10000'`), `api_upload_rate_limit_window` (`'3600'`).

Tier features (`includes/core_tier_features.json`), read via
`SubscriptionTier::getUserFeature($uid, $key, $default)`: `drive_storage_bytes`
(total quota; 0 disables uploads), `drive_max_file_bytes` (per-file cap; 0
disables), `drive_share_links` (boolean), `drive_versioning_depth` (versions
kept). All default to the fail-closed value for a tierless member.
