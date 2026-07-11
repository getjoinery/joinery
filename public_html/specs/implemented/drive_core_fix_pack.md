# Drive Core Fix Pack — post-review corrections

## Status: implemented

Fixes for the verified findings of the high-effort code review of the Drive
Core build (`specs/drive_core.md`, phases 2–6). Ten ranked findings plus the
confirmed cleanup items. Each fix is applied at the causing layer — no
defensive duplication.

## Decisions (root-cause policies, not per-site patches)

**D1 — Drive files are a distinct origin: `fil_source = 'drive'`.**
`File::SOURCE_DRIVE` tags every file created through Drive (upload complete,
dedup short-circuit). The entire Drive surface scopes to it: folder browse,
search, starred, trash listing, trash purge, quota accounting, and
`DriveHelper::load_file` (Drive verbs simply cannot see or touch non-Drive
files). This is what makes `DrivePurgeTrash` safe by construction — it
permanent-deletes only Drive trash, never a soft-deleted profile photo, store
image, or mail attachment. Pre-launch dev rows created before this tag are not
migrated (no production users).

**D2 — Single-owner trees.** Every child of a folder is owned by the folder's
owner:
- Uploading into a folder creates the file owned by (and billed to) the
  **folder owner**, whoever performs the upload. Tier gates
  (`drive_storage_bytes`, `drive_max_file_bytes`) evaluate against the owner.
  The actor is recorded as `fch_source_usr_user_id`.
- A new version of a file bills the **file owner** (same rule as the spec's
  "versions bill their owner").
- `drive_move` requires the destination folder to be owned by the moved
  entity's owner. Cross-owner nesting is impossible, so the folder trash /
  delete-forever cascades (which select by folder) can never touch another
  user's rows.

**D3 — Dedup requires proof of possession.** The `upload_init` short-circuit
only matches blobs already referenced by the **acting user's own files or file
versions**. Knowing another user's hash+size yields nothing (no disclosure, no
oracle). Cross-user dedup still happens safely at `upload_complete`, where the
server hashes the actual bytes.

**D4 — Quota is enforced at the boundary that stores bytes.**
`drive_upload_complete` re-checks the owner's quota with a fresh SUM before
ingesting, serialized per owner with a Postgres advisory lock
(`pg_advisory_lock(42002, owner_id)`), so N pre-opened uploads cannot
overshoot. The `upload_init` check remains as a fast-fail courtesy.

**D5 — An unverifiable cursor resets.** `drive_changes` returns
`{reset: true}` whenever a nonzero cursor cannot be proven contiguous with the
retained window — including the empty-table case (MIN is NULL after a purge).

**D6 — Chunk appends serialize per upload.** `DriveUploadTransport` takes
`pg_advisory_lock(42001, fup_id)` and re-reads `fup_received_bytes` under the
lock before the offset check and append, closing the concurrent-append race.

## Fixes by finding

1. **Dedup cross-user disclosure** — `FileBlob::find_dedup()` gains a
   `$possessed_by_user_id` parameter (EXISTS against `fil_files` and
   `fvr_file_versions`-joined files owned by that user);
   `drive_upload_init` passes the acting user (D3).
2. **Platform-wide trash purge** — `DrivePurgeTrash` file query adds
   `fil_source = 'drive'` (D1).
3. **Cross-owner move/destroy** — `drive_move` rejects a destination folder
   whose owner differs from the entity's owner (D2).
4. **Quota bypass via pre-opened uploads** — owner-locked quota re-check in
   `drive_upload_complete` (D4).
5. **Shared-folder browse empty** — `drive_list` lists a folder's children by
   the **folder owner's** id (correct for owner and grantee alike under D2);
   editor uploads are owned by the folder owner so the owner sees them (D2).
6. **Search misses shared files** — search returns the caller's own Drive
   files plus granted files and files inside granted folder subtrees
   (descendants via one recursive CTE), merged and deduped.
7. **Folder restore into trashed parent** — `drive_restore` re-roots a folder
   (parent → NULL) when its parent is still trashed, resolving root
   sibling-name collisions with a " (restored)"/counter suffix, then runs the
   cascade.
8. **Change-feed reset skipped on empty log** — reset fires when
   `MIN(fch_file_change_id)` is NULL and the cursor is nonzero (D5).
9. **Version bytes billed to saver** — `DriveUsage::recompute()` joins
   versions to their file and bills `fil_usr_user_id`; both SUMs scope to
   `fil_source = 'drive'` (D1, D2). The dedup new-version branch recomputes
   the file owner, not the actor.
10. **Breadcrumb leaks private ancestors** — for a non-owner viewer,
    `drive_list` cuts the breadcrumb at the topmost folder in the chain that
    carries a direct grant for the viewer (the `share_logic` `$in_scope`
    rule).

## Cleanup items (confirmed below the report cap)

- `drive_share_sync` reports `skipped` (unresolved emails) and an accurate
  `granted_count`; `drive.js` surfaces skipped entries in a toast.
- The chunk endpoint logs its **outcome**: `RequestLogger::log` moves from the
  pre-dispatch success stamp in `apiv1.php` into `DriveUploadTransport`'s
  exit helpers (success on 2xx, failure otherwise; the rate-limit count is
  unchanged — one row per request).
- One grant-reach implementation: `DriveHelper::grant_reaches()` becomes the
  public single source; `File::_has_drive_grant()` delegates to it.
- Dead `is_file`/`class_exists` guards for same-release classes removed
  (`DriveHelper::_grant_reaches`, `_drive_list_shared`, `File`).
- `DriveHelper::ancestors()` and `subtree_height()` become single recursive
  CTE queries (depth-capped); new `descendant_folder_ids()` shares the shape.
- `DriveHelper::starred_file_ids()` memoizes per request.
- `api/apiv1.php` bumped to 2.13 with a changelog line; touched class headers
  bumped.

## Out of scope

- The PLAUSIBLE-only concurrent-`upload_complete` double-ingest beyond the
  owner quota lock (D4 already serializes the quota gate, which was the harm).
- Any UI redesign; `drive.js` changes are limited to the skipped-emails toast.

## Tests (regressions; house harness, db tier)

All fix-pack regressions live in one cross-cutting suite,
`tests/functional/drive/fix_pack_test.php` (33 checks): possession-scoped
dedup (owner hits, foreign hash opens a normal upload), quota enforcement at
`upload_complete` with two pre-opened uploads, editor upload owned by + billed
to the folder owner, grantee browse of a shared folder, cross-owner move
rejection, breadcrumb cut at the granted root, shared search, trashed-parent
restore re-root, empty-log change-feed reset, version billing to the file
owner, Drive-scoped trash purge (non-Drive file survives), and `share_sync`
skipped-email reporting. It creates its own small-quota tier so quota
boundaries are exercisable. The five existing drive suites run unchanged
(fixtures updated to `fil_source='drive'`).

## Found along the way: harness teardown never ran on success

`harness_finish()` did not call `harness_teardown_data()` — deferred fixture
cleanup (`harness_register_row/user/key`, `harness_defer`) only ran on the
crash path, so every *passing* db-tier test leaked its fixtures into the dev
DB (docs/testing.md always described teardown as running at finish; the code
didn't). Fixed in `tests/lib/harness.php`; the full db tier (81 tests) is
green with teardown active, and the accumulated litter (345 leaked
`harnesstest_%` users, their 79 files / 168 folders / 53 API keys) was swept
from dev through the model layer.

## Dev environment state (at implementation)

- `drive_active` is `'1'` on dev; Test tier 1a carries nonzero Drive features;
  users drivedemo / drivedemo2 exist for live verification.
- `storage/drive_uploads/` exists as the chunk scratch dir.
- Demo drive files created before the `fil_source='drive'` tag carry
  `user_upload` source and are invisible to the Drive UI (pre-launch, not
  migrated — delete or re-upload them). Stored usage meters read stale until
  the next mutation or `DriveUsageReconcile` run.

## Docs

`docs/drive.md`: ownership rule (D2), `fil_source='drive'` scoping (D1),
dedup possession rule (D3), quota boundary (D4), change-feed reset contract
(D5). `docs/api.md`: upload-transport outcome logging note if it documents the
bucket.
