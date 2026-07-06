# Drive Core — Personal File Storage on the Platform

## Status: active — design

The file-storage leg of the self-hosted Google-suite replacement, alongside
mail (built), calendar (`specs/scheduling_system.md`), and the vault
(`specs/password_vault.md`). Folders, quotas, versioning, sharing, share
links, trash, a member Drive UI at `/drive`, a resumable upload API, and a
change feed.

**Depends on `specs/file_blob_layer.md`** (physical bytes as refcounted
`fbb_file_blobs`; ships first). Client-side encryption layers on top of this
spec separately (`specs/drive_encryption.md`). In-browser Office editing stays
in `specs/cloud_drive_office_suite.md`; this spec is the "Drive layer" that
spec sketches, and `FileVersion` below is the never-overwrite-the-only-copy
safety net it requires.

All table prefixes below (`fol`, `fvr`, `fga`, `fsl`, `fch`, `dru`, `fup`)
are verified collision-free. All new classes follow `docs/example_class.php`
conventions: 3-letter `$prefix`, `$field_specifications` drives schema via
`update_database` (directory-glob discovery, no registry), `$api_readable`/
`$api_writable` default false, `$permanent_delete_actions = array()` declared
even when empty, FK columns kept to the four-segment
`{prefix}_{src}_{entity}_id` shape wherever deletion-rule auto-detection
should apply.

## Data model

### Folder — `data/folders_class.php` (`fol`, `fol_folders`)

- `fol_folder_id` int8 serial PK
- `fol_usr_user_id` int4 not-null required, `'index'=>true` — owner
- `fol_parent_folder_id` int8 nullable, `'index'=>true` — null = drive root.
  Self-reference in the `cnv_previous_version_id` style: non-standard name,
  lifecycle code-managed (no auto-detected rule)
- `fol_name` varchar(255) not-null required
- `fol_create_time` timestamp(6) default now(), `fol_delete_time` timestamp(6)
  nullable

Sibling-name uniqueness among live rows via `$index_specifications` (partial
unique expression index — NULL parents must collide, so coalesce):

```php
public static $index_specifications = array(
    'fol_folders_sibling_name_unique' => array(
        'columns' => array('fol_usr_user_id', "COALESCE(fol_parent_folder_id, 0)", 'fol_name'),
        'unique'  => true,
        'where'   => 'fol_delete_time IS NULL',
    ),
);
```

`$foreign_key_actions`: `fol_usr_user_id` → `set_value` `User::USER_DELETED`
(match File's owner rule). Behavior rules enforced in logic: move must reject
cycles (walk ancestors of the target; depth cap from setting
`drive_max_folder_depth`, default 32); rename/move re-validates sibling
uniqueness. Soft-delete cascades manually per `docs/deletion_system.md`
(:352-385): deleting a folder soft-deletes descendant folders/files in the
logic layer; restore captures the parent's `fol_delete_time` **before**
`undelete()` and restores only children with `delete_time >=` it.

`fil_files` gains `fil_fol_folder_id` (int8, `'index'=>true`, nullable =
root). Four-segment ✓ → File declares
`$foreign_key_actions['fil_fol_folder_id'] = ['action'=>'null']` (a
permanently deleted folder orphans its files to root rather than destroying
them; normal folder deletion goes through trash logic, not raw
permanent_delete).

The drive root is implicit (null parent) — no root row.

### FileVersion — `data/file_versions_class.php` (`fvr`, `fvr_file_versions`)

- `fvr_file_version_id` int8 serial PK
- `fvr_fil_file_id` int8 not-null required, `'index'=>true` — four-segment ✓,
  `$foreign_key_actions` → `cascade`
- `fvr_fbb_file_blob_id` int8 not-null — five-segment, refcount lifecycle
  code-managed (blob-layer rule)
- `fvr_version_number` int4 not-null, `'unique_with'=>array('fvr_fil_file_id')`
- `fvr_usr_user_id` int4 not-null — who saved this content
- `fvr_size_bytes` int8 not-null (denormalized for history display)
- `fvr_create_time` timestamp(6) default now()

Save-new-content flow (transactional, `DbConnector::BeginTransaction/Commit`
per the `ContentVersion::NewVersion` house pattern, content_versions_class:76):
create version row pointing at the current head blob with `version_number =
max+1`; ingest the new bytes (`FileBlob::createFromPath` — dedup applies);
point `fil_fbb_file_blob_id` at the new blob; recompute usage. Restore = same
flow with the roles swapped (the restored blob gets `retain()`; the head
becomes a version). Prune to `SubscriptionTier::getUserFeature($owner,
'drive_versioning_depth', 0)` — oldest rows released
(`FileBlob::release()`) and deleted, `MAX`-cap style
(`MAX_VERSIONS_PER_ITEM` precedent).

### FileAccessGrant — `data/file_access_grants_class.php` (`fga`, `fga_file_access_grants`)

Generalization of `plugins/mailbox/data/inbound_email_mailbox_grant_class.php`
(its method surface is the template), self-service instead of staff-gated:

- `fga_file_access_grant_id` int8 serial PK
- `fga_entity_type` varchar(16) not-null required — `'file'` | `'folder'`
- `fga_entity_id` int8 not-null required
- `fga_usr_user_id` int4 not-null required,
  `'unique_with'=>array('fga_entity_type','fga_entity_id')` — one grant per
  (entity, user); four-segment ✓ → `cascade` on user delete
- `fga_role` varchar(16) not-null — `'viewer'` | `'editor'`
- `fga_granted_by_user_id` int4 not-null — auditing; code-managed name
- `fga_create_time` timestamp(6) default now()

No `delete_time` column — revocation is `permanent_delete()`, deliberately,
per the `ieg_` precedent. Statics mirror the template:
`entity_ids_for_user($user_id, $entity_type): array`,
`user_ids_for_entity($entity_type, $entity_id): array`,
`sync_for_entity($entity_type, $entity_id, array $grants): void` where
`$grants` is `user_id => role` (reconcile: delete unwanted, insert new,
update changed roles, leave rest). Authorization for who may sync lives in
the logic layer (entity owner or permission ≥ 5) — the class stays CRUD.
`$api_readable`/`$api_writable` stay false; all mutation via the
`drive_share_sync` action.

**Access integration:** `File::is_viewable()` (files_class:1166) gains one
clause after the owner check: grant on the file, or on any ancestor folder
(walk `fil_fol_folder_id` parents, bounded by `drive_max_folder_depth`).
Folder listings for "Shared with me" come from
`entity_ids_for_user`. Editor role additionally satisfies the write gate in
drive mutation logic (rename, new version, upload-into-folder); it never
grants delete/share (owner-only).

### FileShareLink — `data/file_share_links_class.php` (`fsl`, `fsl_file_share_links`)

- `fsl_file_share_link_id` int8 serial PK
- `fsl_entity_type` varchar(16) not-null required, `fsl_entity_id` int8
  not-null required, indexed together (`'index_with'`)
- `fsl_token_sha256` character(64) not-null `'unique'=>true` — raw token is
  shown once at creation, never stored
- `fsl_usr_user_id` int4 not-null — creator; four-segment ✓ → `cascade`
- `fsl_expires_time` timestamp(6) nullable
- `fsl_password_hash` varchar(255) nullable — `password_hash()`; the
  `/_hash$/` credential floor auto-hides it from any future API exposure
- `fsl_revoked_time` timestamp(6) nullable
- `fsl_access_count` int4 not-null default 0
- `fsl_create_time` timestamp(6) default now()

A live link (not expired, not revoked, password satisfied) grants **view**
of the entity — folders render a read-only listing, files stream. Serving
mints internal signed URLs (`File::mintSignedUrl`, docs/file_signed_urls.md)
per download: the share link is the durable revocable grant, the signed URL
stays the short-lived transport. Creation gated by tier feature
`drive_share_links`.

**Route** — `serve.php` dynamic bucket, exact shape per the existing
`/post/{slug}` pattern (serve.php:103):

```php
'/s/{token}' => ['view' => 'views/share', 'check_setting' => 'drive_active'],
```

`views/share.php` reads `$params['token']` (merged into logic input per the
`views/notifications.php:10` pattern), hashes, loads by `fsl_token_sha256`,
checks expiry/revocation, prompts for password via FormWriter when set,
increments `fsl_access_count`. Anonymous-safe; no login required.

### FileChange — `data/file_changes_class.php` (`fch`, `fch_file_changes`)

- `fch_file_change_id` int8 serial PK — **this is the cursor**
- `fch_entity_type` varchar(16), `fch_entity_id` int8, indexed together
- `fch_usr_user_id` int4 not-null, `'index'=>true` — entity owner
  (four-segment ✓ → `cascade`)
- `fch_source_usr_user_id` int4 nullable — actor (the
  `ntf_source_usr_user_id` naming precedent)
- `fch_change_kind` varchar(24) not-null — `created` | `content` | `renamed`
  | `moved` | `trashed` | `restored` | `deleted` | `grant_changed`
- `fch_create_time` timestamp(6) default now(), `'index'=>true`

Append-only; written by every drive mutation in the logic layer (one helper,
`FileChange::record($kind, $entity_type, $entity_id, $owner_id, $actor_id)`).
No soft delete. Rows purged past `days_to_keep` (default 90) by task.

### DriveUsage — `data/drive_usage_class.php` (`dru`, `dru_drive_usage`)

- `dru_drive_usage_id` int8 serial PK
- `dru_usr_user_id` int4 not-null `'unique'=>true` — four-segment ✓ →
  `cascade`
- `dru_bytes_used` int8 not-null default 0
- `dru_update_time` timestamp(6) default now()

`DriveUsage::for_user($user_id)` — load-or-create, the
`NotificationPreference::get_for` idiom. **Recompute, don't increment** (the
house pattern; there is no incrementing-counter precedent in the codebase):
`DriveUsage::recompute($user_id)` runs one SUM inside the caller's
transaction —

```sql
SELECT COALESCE(SUM(fbb_size_bytes),0) FROM fil_files
JOIN fbb_file_blobs ON fbb_file_blob_id = fil_fbb_file_blob_id
WHERE fil_usr_user_id = ?
```

plus the same over `fvr_file_versions` (versions bill their owner). Called
after upload_complete, new version, restore, permanent delete. Trash counts
until purged. Dedup does **not** reduce a user's number — each logical file
bills its full size (intuitive; dedup saves disk, not quota). Daily
`DriveUsageReconcile` task re-runs it for all users with drive files as a
drift backstop.

### FileUpload — `data/file_uploads_class.php` (`fup`, `fup_file_uploads`)

Pending-upload state for the resumable protocol:

- `fup_file_upload_id` int8 serial PK
- `fup_token_sha256` character(64) not-null `'unique'=>true`
- `fup_usr_user_id` int4 not-null → `cascade`
- `fup_fol_folder_id` int8 nullable — destination (four-segment ✓ → `null`)
- `fup_fil_file_id` int8 nullable — set when this is a new **version** of an
  existing file (four-segment ✓ → `cascade`)
- `fup_display_name` varchar(255) not-null
- `fup_mime_type` varchar(128) nullable
- `fup_expected_bytes` int8 not-null, `fup_expected_sha256` character(64)
  nullable
- `fup_received_bytes` int8 not-null default 0
- `fup_update_time` timestamp(6), `fup_create_time` timestamp(6)

Scratch bytes live at `{site_root}/storage/drive_uploads/<id>.part` — outside
the web root, the `RawMessageStore::localBase()` precedent. Stale rows +
part-files purged by task after 24 h without `fup_update_time` movement.

## API surface

Per the API rules: JSON logic goes through `_logic_api()` actions; the chunk
transport is the one deliberate exception (raw body), built into the API
front controller — **not** `/ajax/` (closed to new endpoints).

### Actions (core, flat names; `logic/{name}_logic.php` + `{name}_logic_api()`)

All sessioned (`requires_session` true), all returning `LogicResult`, all
receiving merged JSON-body input per `ApiLogicEndpoint::executeAction`
(:131-159), acting user via `SessionControl::get_instance()->get_user_id()`
(session simulation). Browser JS calls the same actions with the
session-cookie + `X-Joinery-Csrf` credential (meta tag emitted by
`PublicPageBase:573` for logged-in users).

- `drive_upload_init` — `{name, folder_id?, file_id?, size_bytes, sha256?,
  mime_type?}`. Gates: quota (`bytes_used + size_bytes <=
  getUserFeature('drive_storage_bytes', 0)`), `drive_max_file_bytes`,
  folder/file write access (owner or editor grant). Dedup short-circuit: if
  `sha256` matches an existing same-visibility blob, complete immediately
  (retain blob, create file/version, `FileChange::record`) and return the
  file. Otherwise create `FileUpload`, return `{upload_token, chunk_bytes}`
  (raw token; only its hash stored).
- `drive_upload_complete` — `{upload_token}`. Verifies `fup_received_bytes ==
  fup_expected_bytes` and, when given, sha256 of the part-file matches;
  `FileBlob::createFromPath` (dedup applies here too), creates the File (or
  version via the FileVersion flow), recomputes usage, records the change,
  deletes the `FileUpload` row. Safe to retry; also covered by the existing
  `Idempotency-Key` machinery (actions only — which is exactly this).
- `drive_changes` — `{cursor}`. Returns `{changes: [...], next_cursor,
  reset?}`. Visibility: rows where `fch_usr_user_id = me` OR entity in my
  grant set (`entity_ids_for_user`). Cursor older than retention → `{reset:
  true}` and the client re-lists. This plus the upload actions is the
  complete server contract for future sync clients.
- `drive_share_sync` — `{entity_type, entity_id, grants: {user_id: role}}`.
  Owner-only; wraps `FileAccessGrant::sync_for_entity`, records
  `grant_changed`, and for each newly granted user calls
  `Notification::create_notification($grantee, 'drive_share', $title, $body,
  '/drive?shared=1', $actor)` gated by
  `NotificationPreference::get_for($grantee, 'drive_share')` (absent row =
  on).
- `drive_link_create` / `drive_link_revoke` — share-link mint (returns the
  raw token/URL once) and revoke (`fsl_revoked_time = now()`); tier-gated by
  `drive_share_links`.
- `drive_folder_create`, `drive_rename`, `drive_move`, `drive_trash`,
  `drive_restore`, `drive_delete_forever`, `drive_list`,
  `drive_version_restore` — the browser/UI verbs; each validates access,
  mutates through the models, records its `FileChange`. `drive_list` returns
  a folder's children (files + folders) with the fields the UI needs
  (id, name, size, mime, times, starred flag via
  `Reaction::has_reacted($me, 'file', $id)`).

Discovery via `GET /api/v1/actions` is automatic.

### Chunk transport — `PUT /api/v1/drive_upload/{token}`

New pre-CRUD dispatch branch in `api/apiv1.php` (the management
`backups/fetch` handler establishes the stream-your-own-response pattern;
this is its inbound twin):

- Segment match `drive_upload/<token>` + method `PUT` (plus `GET` for
  status). Authenticated via the normal `ApiAuth::authenticate()` (session
  key or browser session); the token must belong to the acting user.
- Body read from `php://input` and appended to the part-file. **Sequential
  chunks only**: the request carries `Content-Range: bytes
  <start>-<end>/<total>`; `<start>` must equal `fup_received_bytes`, else
  HTTP 409 with `{received_bytes}` so the client resumes from the right
  offset (this removes sparse-range bookkeeping entirely — resume = ask
  status, continue). Each successful append updates
  `fup_received_bytes`/`fup_update_time`.
- `GET` returns `{received_bytes, expected_bytes}`.
- **Rate limiting**: chunk requests get their own `RequestLogger` feature
  bucket `'api_upload'` (settings `api_upload_rate_limit_requests` default
  `'10000'`, window `'3600'`) instead of the general `'api'` bucket — a
  multi-GB upload must not exhaust the 1000/hr general limit.
- Chunk size returned by `upload_init` (setting `drive_upload_chunk_bytes`,
  default `'8388608'` — 8 MiB; PHP `post_max_size`/`upload_max_filesize` do
  not apply to raw `php://input` reads, but keep chunks comfortably under
  proxy limits).

**Phase 2 (later, contract-preserving):** S3 presigned multipart behind the
same `upload_init`/`upload_complete` actions — init returns presigned part
URLs instead of an upload_token when the instance opts in; complete verifies
via the bucket. The AWS SDK is already present; no client-visible change.

## Member UI — `/drive`

No serve.php entry needed for the bare page (view-directory fallback);
`views/drive.php` follows the `views/notifications.php` wiring exactly:
`require getThemeFilePath('drive_logic.php', 'logic')` →
`process_logic(drive_logic(array_merge($_GET, $_POST, $params ?? [])))` →
`new PublicPage()` → markup. `drive_logic()` does the initial folder listing
server-side; everything after first paint is JS against the actions above.
Vanilla JS (no frameworks, per theme rules), single asset
`assets/js/drive.js` emitted with the `?v=<filemtime>` cache-bust pattern
(`PublicPageBase::asset_mtime`). All dialogs (rename, move, share, link)
are FormWriter forms; the share dialog drives `drive_share_sync` /
`drive_link_create`.

Surface: breadcrumb folder browser, list/grid toggle, thumbnails from
existing size variants, drag-drop + picker upload with per-file progress
(chunk protocol), context menu (download via `mintSignedUrl`-backed
`/uploads/` links, rename, move, star, share, version history, trash),
left rail — My Drive / Shared with me / Starred / Trash / storage meter
(`bytes_used / drive_storage_bytes`, upgrade prompt via
`render_tier_gate_prompt()` when full).

- **Starred** = `Reaction::toggle($me, 'file', $id)` — `entity_type` is a
  free string, zero registration; the existing `ajax/reaction_ajax.php`
  contract (`action=toggle|status|count`) is reused as-is (existing
  endpoint, not a new one).
- **Trash** = listings with `deleted => true`; restore honors the
  parent-capture recipe; "Delete forever" runs `permanent_delete_dry_run()`
  first and shows the impact summary.
- **Search** (v1): filename/title `ILIKE` within own + shared files.
  Content-text search arrives with the Office-suite extraction pipeline,
  not here.

**Menu**: `admin_menus.json` `profileMenu` entry —

```json
{ "slug": "core-drive", "title": "Drive", "url": "/drive",
  "order": 55, "permission": 0, "icon": "folder",
  "settingActivate": "drive_active", "visibility": "in" }
```

seeded by `update_database` (`overwrite:false, prune:false`), which also
feeds the native-app navigation endpoint automatically.

## Settings and tier features

`settings.json` (string values, seeded every `update_database`):
`drive_active` `'0'` (explicit opt-in per instance), `drive_max_folder_depth`
`'32'`, `drive_upload_chunk_bytes` `'8388608'`,
`api_upload_rate_limit_requests` `'10000'`, `api_upload_rate_limit_window`
`'3600'`.

`includes/core_tier_features.json` (currently `{}`; format per
`theme/scrolldaddy/tier_features.json`):

```json
{
  "drive_storage_bytes":    { "label": "Drive Storage (bytes)", "type": "integer", "default": 0,
                              "description": "Total Drive storage per member. 0 disables uploads." },
  "drive_max_file_bytes":   { "label": "Drive Max File Size (bytes)", "type": "integer", "default": 0 },
  "drive_share_links":      { "label": "Drive Share Links", "type": "boolean", "default": false },
  "drive_versioning_depth": { "label": "Drive Versions Kept", "type": "integer", "default": 0 }
}
```

Read via `SubscriptionTier::getUserFeature($uid, $key, $default)` — returns
the default for tierless users, so a no-tier instance configures a tier or
gets 0-byte quota (correct fail-closed default).

## Scheduled tasks

Three new task pairs in `tasks/` (`ScheduledTaskInterface::run(array $config)`,
self-deactivation only via the `'deactivate'` result key; discovered from the
directory, activated by an admin — not migration-seeded):

- `DrivePurgeTrash` (+ `.json`, `default_frequency: daily`, `config_fields:
  {days_to_keep: 30}`) — `permanent_delete()` on files/folders trashed
  longer than the window (blob refcounts handle shared bytes).
- `DrivePurgeStaleUploads` (daily) — delete `fup_` rows + `.part` files idle
  > 24 h.
- `DriveUsageReconcile` (daily) — recompute all users' usage.
- `DrivePurgeChanges` (daily, `days_to_keep: 90`) — trim `fch_` rows.

(CloudOffloadRun already covers blob offload via the blob-layer profiles.)

## Tests

House style (CLI, `check()`, fixtures inline, `finally` teardown, exit code):

- `tests/functional/drive/folders_test.php` — create/rename/move/cycle-reject,
  sibling-uniqueness, trash cascade + selective restore.
- `tests/functional/drive/upload_api_test.php` — init → sequential chunks
  (including a wrong-offset 409 + resume) → complete; dedup short-circuit;
  quota rejection at the boundary; idempotent complete.
- `tests/functional/drive/sharing_test.php` — grant sync add/remove/role
  change, viewer vs editor enforcement, ancestor-folder grant reaches nested
  file, share link lifecycle (mint → anonymous fetch → password → expiry →
  revoke), `is_viewable()` matrix.
- `tests/functional/drive/changes_test.php` — every mutation kind appears
  once with correct cursor ordering; grant-visibility; reset on expired
  cursor.
- `tests/functional/drive/versions_test.php` — version create/restore/prune,
  usage recompute after each.
- ModelTester auto-covers all seven classes.

`php -l` + `validate_php_file.php` on every file, per house rules.

## Phases

1. **Blob layer** — `specs/file_blob_layer.md`, ships and stabilizes first.
2. **Folders + quotas + trash** — Folder, DriveUsage, tier features,
   settings, menu entry, `drive_active`, purge/reconcile tasks.
3. **Drive UI** — `/drive` browser + starred + trash views on the existing
   web upload path.
4. **Upload API + versioning** — FileUpload, chunk transport, the three
   upload actions, FileVersion, dedup short-circuit, stale-upload task.
5. **Sharing** — FileAccessGrant, FileShareLink, `/s/{token}`, share dialog,
   Shared-with-me, notifications.
6. **Change feed** — FileChange writes across all mutations, `drive_changes`,
   purge task.
7. **Presigned multipart** — the contract-preserving large-file path.

Each phase ships independently useful.

## Docs

On ship (current-state voice only): new `docs/drive.md` (data model,
access/grant semantics, upload protocol with the sequential-chunk rule,
change feed contract, quota accounting); update `docs/api.md` (upload
actions + binary endpoint + the `api_upload` rate bucket),
`docs/subscription_tiers.md` (core drive feature keys — first non-empty
`core_tier_features.json` entries), `docs/file_signed_urls.md` (share-link
composition note), `docs/deletion_system.md` (trash retention example),
`docs/routing.md` only if the `/s/{token}` pattern warrants an example.

## Out of scope (deliberate)

- Client-side encryption — `specs/drive_encryption.md`.
- Office editing — `specs/cloud_drive_office_suite.md`.
- Desktop/mobile sync clients — future consumers; `drive_changes` + the
  upload protocol are deliberately their complete server contract.
- Real-time push (websockets/APNS/FCM) — clients poll the change feed.
- Content-text search, comment/activity UI, team spaces, per-user buckets.

## Open decisions (resolve at implementation)

- `drive_upload_chunk_bytes` default (8 MiB proposed) vs. typical
  proxy/Cloudflare body limits on target deployments.
- Whether `drive_list` pagination is needed at v1 for pathological folders
  (proposed: cap listing at 2,000 children, no pagination).
- Whether editor-role grantees may create share links (proposed: no —
  owner-only, matches the delete/share restriction).
- Trash/changes retention defaults (30/90 proposed).
