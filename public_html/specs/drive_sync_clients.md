# Drive Sync Clients — Full Specification

**Status:** Specced — ready for build planning. All decisions are resolved
(walked through with the owner 2026-07-16); deliberately-deferred items are
collected in [§ Deferred items](#deferred-items). Supersedes the earlier skeleton.

## Why this exists

A self-hosted file suite lives or dies on its **sync client**, not its feature
list. The loudest, most repeated complaint about Nextcloud — across HN, Reddit,
and review sites — is not missing features; it is that **sync silently stalls,
large uploads fail, and files occasionally get lost or conflicted**. Users
forgive a plain UI; they do not forgive a sync engine they cannot trust with the
one copy of a file.

The strategic opening: Joinery Drive competes not by matching Nextcloud's app
breadth but by making **"a file put here is never lost, corrupted, or silently
un-synced"** the acceptance bar. And Joinery can do it while syncing
**client-custody end-to-end-encrypted** files, which Nextcloud's E2EE cannot
(its encrypted folders are not even browser-accessible). The desktop client is
also the **shared hardening deliverable** the Sealed Vault family has been
waiting for: `sealed_vault_core.md`, `password_vault.md`, and
`drive_encryption.md` all defer the served-JS residual risk to "a future
native/extension client" — this is that client, for the `drive` scope.

## Goal

Native desktop sync clients (macOS / Windows / Linux) with **one shared core**,
keeping a local folder tree bidirectionally in step with a member's Drive,
including encrypted vault folders, with correctness ahead of speed: never lose
a local edit, never corrupt on conflict, never silently stop.

## Non-goals (this spec)

- Mobile photo/file backup — a separate future spec (one-way, its own thin
  engine; it will reuse Phase 0's server surface and the `jd-proto` crate).
- Real-time collaborative editing (Office-suite concern, not sync).
- Files-on-demand placeholders (macOS File Provider / Windows Cloud Files API)
  — a future major phase; v1 syncs real files.
- Block-level delta sync — deferred (see D14).
- LAN peer-to-peer sync. The server is the hub.
- Syncing anything outside the Drive `fil_source = 'drive'` boundary.

---

# Architecture overview

```
{repo root}/sync/                     ← new top-level dir, like ios/, android/
  Cargo.toml                          ← Rust workspace
  jd-proto/      server API client: auth, actions, chunk transport, download
  jd-crypto/     VaultCrypto + DriveCrypto reimplementation (must-match list §III.1)
  jd-vfs/        filesystem abstraction: real OS backend + simulated backend
  jd-core/       the sync engine: state store, watcher intake, reconciler, executor
  jd-sim/        deterministic simulation harness: virtual FS/network/clock, mock server
  jd-daemon/     headless daemon + CLI (`joinery-drive`): login, status, sync
  jd-shell/      tray UI + settings window (thin; one codebase, per-OS packaging)
```

The core (`jd-core` + everything it uses) is identical on all three OSes; the
only per-OS code is the watcher backend inside `jd-vfs`, keychain access, and
packaging. The daemon is fully functional without the shell (headless Linux
servers are a real use case).

Server-side work is small and additive (Phase 0): sync metadata in exports, a
batch stat action, an index walk, ranged download, and a device-link ceremony
that issues the per-device credential and (optionally) hands the Drive vault
key to the device.

**Language: Rust** (see D1). **Local state: SQLite** in WAL mode (see D2).

---

# Part I — Server work (Phase 0)

All additive; nothing existing changes shape. House rules apply throughout:
schema via `$field_specifications` (no schema migrations), actions as
`logic/{name}_logic.php` with `_logic_descriptor()`, settings with factory
defaults in `settings.json`, docs in current-state voice.

## I.1 Sync metadata in `file_export`

`DriveHelper::file_export` gains:

- `content_sha256` — the head blob's `fbb_sha256`. For plaintext files this is
  the plaintext hash (and matches what the client committed at
  `drive_upload_init`); for encrypted files it is the **ciphertext** hash. In
  both cases it is a reliable content-identity token for change detection.
- `modified_time` — new nullable column `fil_content_modified_time
  timestamp(6)`, set from an optional `modified_time` (ISO-8601 UTC) parameter
  on `drive_upload_init`, carried onto the file at complete. Plaintext files
  only: for an encrypted file the client omits the parameter and stores the
  true mtime inside the encrypted metadata blob instead (a plaintext mtime for
  an encrypted file would leak file age; stated honestly in docs).
- `head_change_id` — the `fch_file_change_id` of the file's most recent
  `content` change (0 if pre-feed). Lets a client prove "the content I have
  corresponds to feed position N" without hashing.

Web UI is unaffected (extra fields are ignored by `drive.js`).

## I.2 `drive_stat` — batch entity fetch

`logic/drive_stat_logic.php`, `capability: read`. The change feed deliberately
stays lean (id-only rows); this is the batch re-fetch that pairs with it.

- Input: `entities` — array of `{entity_type, entity_id}`, max 500.
- Response: `{ok, items: [...], missing: [{entity_type, entity_id}, ...]}`.
  `items` are `folder_export` / `file_export` for every entity the caller can
  read; entities that are gone or not visible land in `missing` (a `deleted`
  or `grant_changed`-away entity must be distinguishable from an error).
- File exports here do **not** mint `download_url` / `thumb_url` (minting a
  signed URL per file is wasted work at sync scale) — a `urls: true` input
  flag opts in for small batches.

## I.3 `drive_index` — full-tree walk for cold start / reset

`logic/drive_index_logic.php`, `capability: read`. A `{reset: true}` from
`drive_changes` (or a cold start) currently forces a folder-by-folder
`drive_list` walk with per-file signed-URL minting — wrong tool at 100k files.

- Input: `after_id` (int, default 0), `scope` (`mine` | `shared`, default
  `mine`), `limit` (max 2000, default 2000).
- Response: `{ok, items, next_after_id, done}` — lean exports (no signed URLs,
  no breadcrumbs), ordered by `(entity_type, id)` ascending so the walk is a
  stable keyset pagination that tolerates concurrent mutations (anything that
  changes mid-walk also lands in the change feed the client replays after).
  `mine` = every live folder + file the caller owns; `shared` = every entity
  reachable through the caller's grants (each item annotated with the granting
  root so the client can build the "Shared with me" mapping).
- Trashed items are included with `deleted: true` (a sync client must know the
  trash to classify restores).

## I.4 Ranged download

`File::serve_from_path()` learns single-range requests on the signed-URL path:
`Accept-Ranges: bytes` on every response; a valid `Range: bytes=start-[end]`
gets 206 + `Content-Range`, an unsatisfiable one gets 416. Local storage via
`fseek`/bounded reads; cloud-offloaded blobs pass the range to the storage
driver (S3 `GetObject` supports `Range`) instead of buffering the object.
Files served through a registered decrypt hook (server-custody sources — not
Drive) are excluded: they keep streaming whole (no `Accept-Ranges`). Signed
URLs are unchanged — the signature already covers `{file_id}:{size_key}:{expires}`
and a range does not alter identity. `docs/file_signed_urls.md` gains the
range contract.

## I.5 Device registration + device-link ceremony

Today the per-device credential is a session `ApiKey` minted by
`POST /api/v1/auth/login` with a `device_label`. That path requires a password
(and fails for passkey-first accounts, and can't satisfy step-up), and there is
no device identity beyond the key's label. The device-link ceremony fixes both
and doubles as the **vault-key handoff** for encrypted sync — the same pattern
as Tailscale-style device auth: the browser, where the user is already signed
in and where WebAuthn works, approves the device.

### Data model

`data/sync_devices_class.php` — `SyncDevice`, prefix `sde`, table
`sde_sync_devices`:

| Column | Type | Notes |
|---|---|---|
| `sde_sync_device_id` | int8 serial | pkey |
| `sde_usr_user_id` | int4 not null, indexed | owner; FK `permanent_delete` |
| `sde_apk_api_key_id` | int8 not null | the session key this device authenticates with; FK `permanent_delete` |
| `sde_device_name` | varchar(64) not null | user-visible; used in conflict-copy names |
| `sde_platform` | varchar(16) not null | `macos` / `windows` / `linux` |
| `sde_client_version` | varchar(32) null | last reported |
| `sde_device_pubkey` | text null | device X25519 public key (std base64, raw 32 bytes) — the vault-handoff target |
| `sde_last_seen_time` | timestamp(6) null | opportunistically updated (see below) |
| `sde_last_cursor` | int8 null | last `drive_changes` cursor the device acknowledged |
| `sde_create_time` | timestamp(6) not null default now() | |
| `sde_delete_time` | timestamp(6) null | soft delete = unlinked |

`data/device_links_class.php` — `DeviceLink`, prefix `dlk`, table
`dlk_device_links` (pending ceremonies): `dlk_code_hash` char(64) unique (SHA-256
of the 8-char Crockford link code), `dlk_poll_token_hash` char(64) unique,
`dlk_device_pubkey` text, `dlk_device_name` varchar(64), `dlk_platform`
varchar(16), `dlk_status` varchar(12) (`pending`/`approved`/`denied`),
`dlk_usr_user_id` int4 null (set at approval), `dlk_apk_api_key_id` int8 null,
`dlk_sde_sync_device_id` int8 null, `dlk_sealed_vault_key` text null,
`dlk_secret_once` text null (the minted session secret, SecretBox-encrypted at
rest, deliverable exactly once), `dlk_attempts` int4 default 0,
`dlk_expires_time` timestamp(6) not null (10 min), `dlk_create_time`.
Swept by a new `DrivePurgeDeviceLinks` scheduled task (hourly; expired or
claimed rows).

### Flow

1. **Begin** — `POST /api/v1/auth/device_link` (pre-auth, same family as
   `auth/login`): `{device_name, platform, device_pubkey}` →
   `{link_code, poll_token, verify_url, expires_time}`. `verify_url` is
   `/profile/devices/link?code=XXXX-XXXX` on the instance. Rate-limited by its
   own IP bucket (`api_device_link_*`, defaults 600/3600s — sized for 3-second
   polling for 10 minutes plus headroom).
2. **User approves in the browser** — the client opens `verify_url` (and shows
   the code for manual entry). The page (`views/profile/devices_link.php` +
   FormWriter) requires a signed-in browser session **and recent step-up**
   (300 s, same bar as `vault_client_add_wrapping`), shows device name /
   platform / requesting IP, and offers, when the user has a `drive`-scope
   client-custody vault, an **"Enable encrypted folders on this device"**
   checkbox: checking it runs the vault unlock in the browser (any unlocker,
   passkey PRF included — full WebAuthn is available here) and seals the vault
   secret key (the PKCS8 X25519 bytes) to `device_pubkey` with the standard
   sealed-box primitive (`VaultCrypto.sealToPublicKey`). Approval calls
   `drive_device_link_approve` `{code, enable_vault, sealed_vault_key?}`
   (browser-session-only action) which mints the session `ApiKey`
   (`CreateSessionKey`, label = device name), inserts the `SyncDevice`, stores
   the sealed key + encrypted secret on the link row, and flips status.
3. **Poll** — `GET /api/v1/auth/device_link/{poll_token}`: `{status}` while
   pending; on `approved` (first successful poll only) additionally
   `{public_key, secret_key, device_id, sealed_vault_key?}`, then the row is
   scrubbed of secrets. `denied` / expiry ends the ceremony.

The device stores the API secret and its own X25519 secret key in the OS
keychain (macOS Keychain / Windows DPAPI+Credential Manager / libsecret), opens
`sealed_vault_key` with the device secret key, and re-stores the vault secret
key in the keychain too (see D6 for the custody rationale). Nothing lands on
disk in plaintext.

### Management surface

- `drive_devices` (read): the caller's devices —
  `{id, device_name, platform, client_version, last_seen_time, last_cursor,
  linked_time, has_vault_key}`.
- `drive_device_rename` `{device_id, name}`; `drive_device_revoke`
  `{device_id}` — soft-deletes the device **and revokes its session key** (the
  lost-laptop kill switch; the existing password-change sweep
  `RevokeSessionKeysForUser` already kills all of them at once). Both
  browser-session actions surfaced on `/profile/security` alongside App
  Sessions.
- **Liveness for free:** the `drive_changes` handler, when the calling
  credential maps to a `SyncDevice`, opportunistically stamps
  `sde_last_seen_time` (throttled to once/hour like `apk_last_used_time`) and
  `sde_last_cursor` from the request cursor. The security page can then show
  "MacBook — synced 4 minutes ago", which is the visible trust surface
  Nextcloud lacks: a stalled device is *seen* to be stalled.

## I.6 `drive_vault_status` — lean vault probe for native clients

Every `vault_client_*` action is browser-session-only (correct — wrappings and
enrollment stay in the browser). A native client still needs three facts:
whether a `drive` vault exists, its public key, and its `key_generation` (to
detect rotation and to seal file keys). New `logic/drive_vault_status_logic.php`
(read, session-key allowed): `{scope: 'drive'}` →
`{set_up, public_key, key_generation}`. No wrappings, no salts, no KDF params —
those never leave the browser path.

## I.7 Settings

`settings.json` additions (factory defaults; zero-config rule — nothing
required at install): `api_device_link_rate_limit_requests` (`'600'`),
`api_device_link_rate_limit_window` (`'3600'`). Scheduled task registration for
`DrivePurgeDeviceLinks`. No new tier features: sync is Drive, gated by the
existing `drive_active` + quota/tier features (D18 — no sync-specific gate).

## I.8 Server tests (Phase 0)

- `tests/functional/drive/sync_contract_test.php` — tier `db`, env `dev-only`:
  `drive_stat` (batch, missing-marking, no-URL default), `drive_index` (keyset
  walk, `mine`/`shared` scopes, trashed inclusion), `file_export` new fields,
  `modified_time` round trip (and its refusal on encrypted uploads),
  `drive_vault_status`, the full device-link state machine (begin → approve →
  single-use poll → secrets scrubbed; deny; expiry; attempts cap; step-up
  requirement), `drive_devices`/`rename`/`revoke` (revoke kills the key),
  last-seen stamping via `drive_changes`.
- `tests/functional/drive/ranged_download_gate.sh` — tier `live`, env
  `dev-only`: curl against dev — 206 semantics, `Content-Range`, 416, full-GET
  unchanged, ranged GET on a cloud-offloaded blob.

---

# Part II — The sync engine (`jd-core`)

## II.1 First principles

Sync is a **three-way merge with no merge tool**: at every moment there are
three states — local now, remote now, and the last state both sides agreed on.
Correctness means inferring what changed on each side from those three. The
engine therefore persists the **last-agreed state per entry** perfectly, in a
transactional store, and every rule below derives from it. Two footguns are
already dead on arrival, courtesy of the server design: ordering (the change
feed's primary key is a server-assigned monotonic cursor — wall clocks are
never consulted) and catastrophic deletion (server-side trash + version
history are a backstop the client cannot bypass).

Identity is **server-id-keyed, not path-keyed**: an entry is `(entity_type,
server_id)`; paths are labels. This is what makes a 10k-file folder rename one
operation and lets moves preserve shares and version history.

## II.2 State store (SQLite, WAL)

One database per sync root, in the state dir (never inside the synced tree):

- `meta` — schema version, instance URL, device id, account id, **cursor**
  (single row; the `drive_changes` position), sync-root path, scope config.
- `entries` — the heart. One row per known remote entity:
  `(entity_type, server_id)` pkey; `parent_folder_id`; `remote_name` (exact
  server name, or decrypted name for encrypted files); `local_name` (the
  materialized name if mangling applied, else null); `is_encrypted`;
  `remote_content_sha256`; `remote_size`; `remote_modified_time`;
  `head_change_id`; **last-agreed section:** `synced_content_sha256`
  (plaintext-domain hash for encrypted files, see §III.4), `synced_size`,
  `synced_fingerprint` (size, mtime_ns, inode/file-id — the cheap local
  change filter); `local_status` (`synced` / `pending_download` /
  `pending_upload` / `conflict` / `unsyncable` / `pending_key` / `out_of_scope`);
  `unsyncable_reason`; `wrapped_file_key` (encrypted entries).
- `ops` — the write-ahead intent journal (§II.7): `op_id`, `kind`, `entity`,
  `params`, `state` (`queued`/`in_flight`/`done`), `idempotency_key`,
  `attempts`, `next_retry_time`, `last_error`.
- `local_index` — inode/file-id → entry mapping for move re-pairing, plus a
  hash cache keyed by `(file_id/inode, size, mtime_ns)` so rescans do not
  re-hash unchanged files.
- `issues` — the surfaced-problems table backing the UI (§II.9).

Every reconciliation step that changes both the FS and the DB writes the DB
intent first, acts, then marks done — the crash-recovery contract (§II.7).

## II.3 The four loops

1. **Watcher intake** (`jd-vfs`): FSEvents (macOS) / `ReadDirectoryChangesW`
   (Windows) / inotify (Linux), normalized into `{path, hint}` dirty records,
   debounced per path (quiet period, default 2 s since last event). Overflow
   (inotify queue overflow, FSEvents `mustScanSubDirs`) sets a *rescan-needed*
   flag instead of trusting the stream — dirty marking, never truth.
2. **Rescanner**: walks dirty subtrees (or everything on start / overflow /
   schedule, default every 24 h; network-FS roots run in rescan-only mode) and
   compares each file's fingerprint to `synced_fingerprint`; fingerprint drift
   → hash to confirm (mtime lies: FAT 2 s precision, backwards clock — the
   hash is the truth, the fingerprint only a filter). Produces **local deltas**.
3. **Remote poller**: `drive_changes` every `poll_seconds` (default 30 s;
   immediately after any own mutation completes), dedups entity ids in the
   batch, `drive_stat`s them, updates the remote side of `entries`, produces
   **remote deltas**. `{reset: true}` → full `drive_index` walk diffed against
   `entries` (an index walk is a *statement of remote truth*, so entries absent
   from it become remote-deletes — but only after the walk completes
   successfully; a partial walk mutates nothing). Cursor advances only after
   the batch's deltas are durably queued.
4. **Reconciler + executor**: merges local and remote deltas per entry through
   the decision matrix (§II.4), orders the resulting ops (§II.6), and executes
   them with bounded concurrency (default 3 transfers; ops for one entry are
   serialized).

## II.4 The reconciliation matrix

Per entry, each side's delta is one of: **none / edited / created / deleted /
moved-renamed**. Content and location are independent axes (id-keyed), so a
remote move + local edit compose without conflict. The matrix:

| Local \ Remote | none | edited | deleted | moved/renamed |
|---|---|---|---|---|
| **none** | — | download | local-trash the file | apply move locally |
| **edited** | upload version | **hash-equal? adopt; else conflict** (§II.5) | **edit wins**: re-create on server (new file, old id is gone) | apply move, then upload version |
| **deleted** | trash on server | **edit wins**: re-download (delete loses to edit) | agree: forget entry | re-download at new location (delete loses to move? No — see below) |
| **moved/renamed** | apply move on server | apply remote edit + local move (compose) | re-create at local position | same target: adopt; different: **server placement wins**, log |

Refinements:

- **Delete vs move (remote moved, locally deleted):** the local delete was of
  the entry at its old path; the move proves remote activity. Resolution:
  delete proceeds (trash on server) — but only if `synced_content_sha256`
  equals the remote head (no content change hiding behind the move); otherwise
  edit-wins re-download applies. Deletes only ever win against *unchanged*
  content.
- **Edit wins over delete, always, in both directions.** A delete can be
  recovered from trash; a destroyed edit cannot. This includes the folder
  case: a remotely-deleted folder containing files with unsynced local edits
  is not blindly removed — the edited files are **rescued**: their paths are
  re-created server-side (fresh ids), everything else in the folder goes to
  the local trash.
- **`restored` / `trashed` kinds** map to remote-create / remote-delete of the
  entry (with the same edit-wins guard). **`grant_changed`** re-evaluates
  scope: an entry leaving the caller's visibility is *not a delete* — it
  transitions to `out_of_scope`, and its local file is removed to the local
  trash only when the scope rules (§II.10) say the subtree was shared-in;
  never for the user's own files.
- **Creations meeting at the same path** (both sides made `Reports/Q3.pdf`):
  hash-equal → adopt (pair them, one entry); different → the remote file takes
  the canonical path, the local one becomes a conflict copy (§II.5).
- **Every "adopt" is hash-verified**, never fingerprint-trusted.

## II.5 Conflicts — non-destructive, deterministic

Policy (D5): **the remote head keeps the canonical path; the losing local
content is preserved as a conflict copy and uploaded as a new file**, so both
versions exist on both sides within one sync round. Naming:

```
Report (conflicted copy 2026-07-16 from MacBook).xlsx
```

— date + `sde_device_name`, suffix ` 2`, ` 3` on collision. For encrypted
files the naming happens in the plaintext domain (the decrypted metadata
name), the conflict copy is a **new encrypted file**: fresh FK + content id,
sealed to the destination folder's full reader set exactly like any encrypted
upload. Conflict events always land in `issues` (visible, dismissible), never
silently.

## II.6 Operation ordering and cycles

Each reconcile round emits a batch ordered as a dependency graph, not a list:

1. folder creates, top-down; 2. moves/renames (see below); 3. file
uploads/downloads (parallelizable); 4. deletes, bottom-up, folders last.

Moves can genuinely cycle (A→B while B→A, name swaps): break with a temp
rename (`.jd-swap-<rand>`, an always-ignored name) locally, and `drive_rename`
to a temp name then back server-side. A move whose destination folder does not
exist yet orders after that folder's create; the executor re-validates each
op's preconditions at execution time (the world may have moved) and re-queues
through the reconciler on precondition failure rather than improvising.

## II.7 Crash consistency

The engine may die at any instruction; invariants:

- **Downloads:** stream to `.jd-tmp-<rand>` in the state dir's spool (same
  volume as the root when possible), verify (sha256 for plaintext; full AEAD
  chunk verification for encrypted — §III.4) **before** fsync + atomic rename
  onto the target. A partial or tampered download never becomes a visible
  file. If the target changed while downloading (fingerprint check under the
  rename), abort and re-reconcile.
- **Uploads:** `drive_upload_init` commits the sha256; a file that changes
  mid-upload fails verification at `drive_upload_complete` and re-queues
  cleanly. Upload state (token, offset) is journaled in `ops`, so a restart
  GETs `{received_bytes}` and resumes; a 404 (24 h sweep) restarts from init.
  Every mutating action carries an `Idempotency-Key` from the journal row, so
  a crash between "server applied it" and "journal marked done" cannot
  double-apply.
- **DB-vs-FS divergence:** recovery on start replays `in_flight` ops by
  re-checking both sides (the op is re-derived, not blindly re-run).
- **State-store loss/corruption** is survivable by design: rebuild = fresh
  `drive_index` walk + full local scan, pairing by path + hash (equal → adopt
  silently, differ → conflict copies). Identical bytes are never re-transferred
  (hash pairing; and even a mis-pair only costs a dedup-short-circuited upload
  — `drive_upload_init` with a possessed sha256 moves no bytes).

## II.8 The filesystem is an adversary

The `jd-vfs` adaptation layer owns every OS quirk, so `jd-core` sees a clean
case-sensitive, NFC-normalized, legal-names-only tree:

- **Unicode normalization:** all name comparison in NFC (macOS reports NFD).
  `remote_name` keeps the server's exact bytes; the NFC form is only the
  comparison key. Two remote siblings that NFC-collide: first materializes,
  second is `unsyncable` (surfaced).
- **Case-insensitivity (macOS/Windows default):** two remote siblings
  differing only by case — first materializes, second `unsyncable` with reason
  `case-clash` (materializing a mangled sibling would leak the mangling back
  on rename; not materializing is Nextcloud's behavior too, but *surfaced*
  here, never silent). On Linux both materialize.
- **Illegal names (Windows):** reserved characters `< > : " / \ | ? *`,
  reserved stems (`CON`, `NUL`, `AUX`, `COM1`…), trailing dots/spaces —
  materialized with percent-escaped `local_name` (`Report: final.docx` →
  `Report%3A final.docx`), the mapping held in `entries` (the DB mapping is
  authoritative; reversibility of the escape is not relied on — a user's real
  `%3A` is not a trap). A local rename of such a file uploads the name as
  typed. Names whose UTF-8 exceeds the local filesystem's byte limit:
  `unsyncable`.
- **Long paths (Windows):** all FS access through `\\?\` extended-length
  paths; the 260-char cap does not apply.
- **Symlinks / junctions / mount points inside the root:** never followed,
  never synced, surfaced once as `unsyncable` (loop + escape risk). Hardlinks
  sync as independent files (server dedup makes the bytes free). Sparse files
  upload dense. xattrs / resource forks / ACLs / permissions: not synced in
  v1, including the executable bit — documented as unsupported (revisit if
  synced code folders prove a real workload).
- **Safe-save dances** (Office/Photoshop/SQLite write-temp-rename storms): the
  quiet period coalesces the storm; pairing precedence — (1) same path (a new
  inode at the same path is a *content edit*, not delete+create), (2) same
  inode + same hash (a move), (3) same hash elsewhere in the tree (a move,
  re-paired; inode reuse is guarded by the hash requirement) — otherwise
  delete + create. A moved 4 GB file thus stays one entry (share + history
  preserved); even a missed pairing costs no bytes (dedup-by-possession).
- **Never upload a half-written file:** quiet period + fingerprint stability
  check + the init-time sha256 commitment (a mid-upload change fails complete
  and re-queues).

## II.9 "Never silently stop" — the health model

Every entry is always in exactly one visible state (`synced`, `pending_*`,
`conflict`, `unsyncable`, `pending_key`, `out_of_scope`), and the tray reduces
them to one honest indicator: **green** (converged: all entries `synced` /
`out_of_scope`), **spinning** (work in flight), **amber badge** (issues n > 0:
conflicts, unsyncables, quota-blocked, key-pending), **red** (cannot reach
server / auth dead / root missing). Failures retry with exponential backoff
(cap 15 min) but *always* count and surface in the issues panel with reasons
("Owner's Drive is full", "Name clashes with 'report.txt'", "Waiting for a
key grant from the owner"). `sde_last_seen_time` gives the server-side twin of
this promise: the web security page shows each device's last sync. A device
that stalls is visible from every other device.

Structured local logs (JSONL, rotated) with an in-UI "recent activity" view;
no telemetry leaves the machine.

## II.10 Scope: what syncs

- v1 default: the member's own Drive root, entire (`scope: mine`).
- **Selective sync:** subtree opt-out (checkbox tree in the shell, stored in
  `meta`). Descoping a subtree is recorded transactionally *first*, then local
  files are removed (to the OS trash) — the classic "unchecked ≠ delete"
  misclassification is structurally impossible because `out_of_scope` is a
  distinct entry state, not an absence. Files inside a descoped subtree are
  tracked (id + remote hash) via the change feed but hold no local presence.
- **Shared-with-me** (Phase 5): grant roots mount under `~/Joinery
  Drive/Shared/<owner> — <root name>/`, opt-in per root. Viewer-role subtrees
  materialize read-only (best-effort chmod); a local edit to a viewer file is
  restored from the server and the edit preserved as
  `name (unsynced local edit).ext` + an issue (never uploadable — no editor
  grant, surfaced instead of lost). Editor-role uploads bill the owner
  (single-owner trees): an over-quota owner surfaces as a `quota-blocked`
  issue and retries on feed activity.
- **Mass-delete guard** (D11): if one reconcile round would delete more than
  `max(50, 25%)` of synced entries — in either direction — the engine pauses
  that class of ops and raises a blocking prompt ("Keep everything / proceed")
  in the shell (headless: refuses and logs; CLI flag to proceed). Ransomware
  or an unmounted-disk illusion propagates nowhere. An unavailable root
  (unmounted volume: state dir present, root gone) hard-pauses sync rather
  than reading as a mass local delete.

## II.11 Transfers

- Uploads: init (with sha256 + size + `modified_time`) → sequential 8 MiB
  chunk PUTs (`chunk_bytes` from init; 409 resync to `received_bytes`) →
  complete (idempotent). Possessed-hash dedup means move-detection misses,
  state rebuilds, and copy-paste duplicates cost zero bytes.
- Downloads: `drive_stat urls:true` (or `file_export.download_url`) → GET,
  with `Range` resume (Phase 0) on retry; re-mint on 403/expiry (TTL 3600 s
  outlives any single request it starts).
- Bounded parallelism (default 3), per-entry serialization, user-configurable
  up/down rate caps (token bucket) in daemon config.
- Rate-limit hygiene: batch-first design (`drive_stat` ≤ 500, `drive_index`
  2000/page, changes 500/batch) keeps a large account well inside the 1000/hr
  general bucket; chunk PUTs ride the separate 10000/hr `api_upload` bucket.
  429 → back off per `Retry-After` when present.

---

# Part III — Encrypted vault sync

## III.1 `jd-crypto` — the must-match list

A native reimplementation of `VaultCrypto` + `DriveCrypto` (verified against
`assets/js/vault-crypto.js`, `assets/js/drive-crypto.js`,
`tests/functional/drive/drive_crypto_roundtrip.mjs`). Exact contract:

- **Encodings:** standard base64 (padded) everywhere on the wire, **except**
  WebAuthn credential ids and PRF outputs (base64url). Vault public key =
  raw 32-byte X25519, std base64. The vault secret key travels as **PKCS8
  DER**, not a raw scalar.
- **Sealed box (file-key wrap, vault handoff):** custom HKDF ECIES — **not**
  libsodium `crypto_box_seal`. `blob = b64(ephPub[32] || IV[12] ||
  AES-256-GCM ct+tag)`; key = HKDF-SHA256(salt = empty, ikm = X25519(eph,
  recipient), info = `"sealed-vault:dek"` || ephPub || recipientPub); no AAD.
  Opening requires the recipient public key (it is bound into the KDF).
- **Content container:** per 4 MiB plaintext chunk:
  `uint32be(blockLen) || IV[12] || AES-256-GCM(ct+tag[16])`, AAD =
  `utf8(contentId + ":" + chunkIndex)` (0-based decimal); contentId = 32-char
  lowercase hex (16 random bytes); empty file = exactly one zero-length chunk;
  32 bytes overhead per chunk (matches `DriveHelper::encrypted_size_ceiling`).
- **Metadata blob:** `b64(IV[12] || AES-GCM ct)` of the JSON
  `{v:1, name, mime, size, cid, chunk:4194304, thumb}` under the FK, no AAD —
  **plus a new optional `mtime` field** (ISO UTC) this client writes and the
  web client learns to display (additive, version-tolerant readers).
- **Thumbnail:** `IV[12] || ct` raw bytes, AAD = `contentId + ":thumb"`,
  JPEG ≤ 256 px. Desktop v1 does not generate thumbnails for encrypted
  uploads (`thumb: false`; web-parity thumbs can ride a later phase).
- **Versions:** re-encrypt under the **same FK + contentId**
  (`encryptFileWith` semantics); the server refuses a wrapped-key payload on
  the version path and requires the uploader to hold a `FileKeyGrant`.
- **Unlockers** (used only in the browser ceremony, but `jd-crypto` implements
  passphrase + recovery for CLI-recovery scenarios): Argon2id (read
  `kdf_params`; defaults 64 MiB / t=3 / p=4 / 32 B), recovery KEK =
  SHA-256(salt || normalized code) with Crockford normalization (`O→0`,
  `I/L→1`, strip non-alnum), wrapping AD = `vault:<scope>:<type>[:credId]`.

**Cross-implementation parity is a gate, not a hope:**
`tests/functional/drive/sync_crypto_parity_gate.sh` (tier `safe`, env `any`,
skip-if-no-toolchain like `drive_crypto_gate.sh`) round-trips vectors both
directions — Rust encrypts / Node (`drive_crypto_roundtrip.mjs` helpers)
decrypts, and vice versa: content (multi-chunk, empty, exact-boundary),
metadata (incl. `mtime`), sealed boxes against fixed keypairs, AAD transplant
and reorder refusals, container tamper refusals.

## III.2 Key custody on the device

The Drive vault secret key reaches the device once, sealed to the device
keypair during the link ceremony (§I.5), and lives **only in the OS keychain**.
Consequences, stated honestly (they go in the docs):

- Routine sync never prompts: FK unwrap is local. This is what makes encrypted
  folders *syncable* rather than *openable-with-ceremony*.
- The custody class equals the platform password managers': an attacker with
  the user's unlocked OS account can use the key. Snapshots (stolen disk
  image without the OS credential, stolen server backup, dumped DB) remain
  worthless — which is the threat model E2EE defends.
- Revoking the device (or the vault's key rotation bumping `key_generation`,
  detected via `drive_vault_status`) ends future access; like every E2EE
  system, it cannot un-know what a device already saw. Rotation flips
  encrypted subtrees to a `re-link needed` issue; plaintext sync continues.
- A user may decline vault handoff at link time: encrypted folders then show
  as `locked` scope (visible, not synced), everything else syncs.

## III.3 Encrypted operations in the engine

- **Download:** fetch ciphertext → AEAD-verify **every chunk** against
  `contentId:index` while streaming to spool → only then materialize.
  Tampered/corrupt ciphertext is an `issues` row + retry, and is never written
  where it could read as a local edit.
- **Upload (new file):** encrypt to a **ciphertext spool file** (resume
  requires byte-identical streams; GCM re-encryption differs, so spool —
  transient disk = file size), compute ciphertext sha256, resolve the
  destination's reader set (`drive_public_keys folder_id` mode), seal the FK
  to every reader (owner entry mandatory), init → chunks → complete with
  `encrypted_metadata` + `wrapped_file_keys`.
- **Edit:** new version under the same FK/contentId (no key payload).
- **Rename/move:** rename = metadata re-encrypt (`drive_rename
  encrypted_metadata`), never a plaintext name; move within the vault =
  `drive_move` (ids, nothing re-encrypts).
- **Crossing the boundary** (local move plaintext↔vault): the server refuses
  in-place crossing, so the client converts exactly as the browser does —
  upload as new file on the destination side, trash the source (one issue-log
  note: version history does not carry across).
- **Key-arrival race:** visible file, no `FileKeyGrant` yet → `pending_key`
  state (not an error, not a delete), retried on `grant_changed` events.
- **Name intelligence in the encrypted domain:** conflict naming, case-clash
  detection, illegal-name mangling all run post-decrypt on metadata names.

## III.4 Two-domain change detection

For an encrypted entry the two sides speak different hash languages, so
`entries` tracks both: local edits are detected against
`synced_content_sha256` (plaintext domain, computed at sync time); remote
edits are detected by `remote_content_sha256` drift (ciphertext domain, from
`drive_stat`) against the ciphertext hash recorded at last sync. The client
**never re-encrypts to compare** (random IVs make ciphertext non-deterministic
— equal plaintexts do not imply equal ciphertexts). Plaintext hashes of
encrypted files never leave the device.

---

# Part IV — Shells, packaging, distribution

- **Onboarding:** instance URL first (this is a platform, not one service) →
  device-link ceremony in the default browser → choose sync-root location →
  (optional) selective-sync tree. `client_app` identifiers
  `joinery-sync-macos|windows|linux` + `client_version` headers wire the
  existing 426 upgrade gate.
- **Shell (v1 scope, deliberately thin):** a cross-platform Rust tray /
  menu-bar icon with the four health states, issues panel, pause/resume, and
  recent activity; Settings is a local page served by the daemon and opened
  in the default browser (folders, limits, selective sync, unlink) — see D16.
  No file-manager overlay icons in v1 (deferred).
- **Daemon:** autostart per OS (LaunchAgent / Run key or Task Scheduler /
  systemd user unit). CLI: `joinery-drive login|status|issues|pause|resume|
  sync-now|unlink|recover-vault`.
- **Packaging:** macOS notarized `.dmg` (universal2), Windows signed installer
  (NSIS or MSIX), Linux `.deb` + `.rpm` + static-musl tarball. Auto-update:
  the client checks a channel manifest and applies signed updates (macOS/Win);
  Linux defers to the package manager. The update feed is central
  (getjoinery.com) so instances stay zero-config (D19); signing identities
  are a Phase-6 procurement prerequisite (see § Deferred items).
- **Builds:** Linux + Windows cross-builds from the dev box (cargo,
  `cargo-xwin` for MSVC targets); macOS builds/notarization on the Mac mini
  over SSH (established iOS-gate pattern). Never run builds concurrently with
  Ollama loads on the mini (memory-budget house rule).

---

# Verification — the harness is the product claim

The edge-case space is combinatorial; hand-written cases cannot cover it. The
engine is built **around** a deterministic simulation harness (`jd-sim`), the
way FoundationDB and Dropbox's Nucleus were. Shipping bar for every phase: a
green simulation run across the full fault matrix, plus the live gates.

- **Simulate the world, not the code paths:** `jd-core` takes its filesystem
  (`jd-vfs` trait), network (`jd-proto` trait), clock, and RNG by injection.
  `jd-sim` provides a virtual FS (with per-OS personality: case-insensitive,
  NFD, illegal names, mtime precision), a **mock server implementing the exact
  Part-I contract** (cursor feed with resets, 409 chunk resync, dedup, quota,
  reader-set validation, key grants), and a controlled clock — every run
  reproducible from a seed.
- **Contract parity keeps the mock honest** (the simulator-boundary decision,
  D13): `sync_live_gate.sh` runs the same scenario script against the mock
  and against dev.getjoinery.com and diffs observable outcomes; divergence
  fails the gate and the mock (or the spec) gets fixed.
- **Fault matrix, injected every run:** process kill at any await point,
  network drop/delay/reorder/duplicate, chunk corruption, watcher overflow
  and dropped events, mtime lies, inode reuse, illegal/colliding names, two
  devices racing, grant revocation and key-arrival races mid-sync, quota
  rejection at complete, 24 h upload-token sweep, feed reset, server 5xx.
- **Two invariants asserted after every scenario, always:** (1) both replicas
  **converge** to a consistent joint state, and (2) **no committed content is
  ever lost** — every version either survives locally, on the server (head,
  version row, or trash), or as a materialized conflict copy. The mock
  server's version history is the oracle.
- **Property-based generation + shrinking** (`proptest`): random op sequences,
  minimal reproducers frozen as regression seeds in-repo.

Test estate (headers per `docs/testing.md`; shell gates are exit-code
contracts, skip-if-toolchain-missing like `drive_crypto_gate.sh`):

| File | Tier / env | Covers |
|---|---|---|
| `tests/functional/drive/sync_contract_test.php` | db / dev-only | Phase 0 server surface (§I.8) |
| `tests/functional/drive/ranged_download_gate.sh` | live / dev-only | Range semantics over HTTP |
| `tests/functional/sync/sync_sim_gate.sh` | safe / any, needs `[rust]` | `cargo test -p jd-sim` — full matrix + regression seeds |
| `tests/functional/drive/sync_crypto_parity_gate.sh` | safe / any, needs `[rust, node]` | Rust↔Node crypto vectors (§III.1) |
| `tests/functional/sync/sync_live_gate.sh` | live / dev-only, needs `[rust]` | CLI two-device E2E vs dev + mock-parity diff |
| `tests/functional/sync/sync_macos_gate.sh` | live / dev-only, needs `[macmini, rust]` | build + FSEvents/NFD/case-fold suite on the mini |

---

# Phases (each independently useful)

- **Phase 0 — server additions.** §I.1–I.8. Useful alone: richer exports and
  batch stat/index serve any future client; device management improves the
  security page today.
- **Phase 1 — contract crates.** `jd-proto` + `jd-crypto` + parity gate +
  `joinery-drive login/list/get/put` plumbing CLI. Proves both contracts
  end-to-end against dev before any engine code exists.
- **Phase 2 — the engine, plaintext, Linux.** `jd-core` + `jd-vfs` (inotify) +
  `jd-sim` with the fault matrix; two-way sync of the own-drive scope with
  selective subtree opt-out; daemon + CLI. Simulation green = the reliability
  claim exists.
- **Phase 3 — macOS and Windows.** Watcher backends, FS personalities
  (case-fold, NFD, illegal names, `\\?\`), keychain backends, macOS gate on
  the mini. Tray shell (all three OSes) with the health model. **BUILT
  2026-07-31.** Naming wired into the pass (`jd-core::naming`), volume-probed
  personalities, NFC at the `Vfs` boundary, Windows file identity and verbatim
  paths, watcher root resolution; new `jd-platform` (secrets, directories,
  autostart, browser, control channel), `jd-daemon` (link ceremony, the loop,
  health model, CLI), `jd-shell` (tray on all three). Verified by per-platform
  simulator scenarios, a cross-build gate, and `sync_macos_gate.sh` on the mini.
  Two pre-existing engine defects surfaced and fixed on the way: a folder
  renamed locally was rebuilt server-side rather than renamed, and a folder
  deleted locally was never propagated at all.
- **Phase 4 — encrypted vault sync.** Vault handoff in the link ceremony,
  keychain custody, encrypted engine ops (§III.3), parity gate extended.
- **Phase 5 — shared-with-me + multi-device hardening.** Grant-root mounts,
  read-only viewer trees, editor billing surfacing, two-device race scenarios
  promoted into the default fault matrix.
- **Phase 6 — packaging, signing, auto-update.** Installers, notarization,
  update channel, docs + download page.

(Mobile backup: separate spec, after Phase 4, reusing Phase 0 + `jd-proto`.)

---

# Decisions (resolved by this spec)

- **D1 — Rust core.** One audited core for a correctness-critical concurrent
  engine: memory safety, best-in-class property-testing (`proptest`), mature
  crypto (RustCrypto/`ring`), `notify` for watchers, single static binaries,
  first-class cross-compilation. Precedent for this exact problem: Dropbox
  rewrote its engine in Rust. (In-house Go precedent — `joinery-agent` —
  considered; the deciding factors are the simulation-harness ergonomics and
  crypto maturity. Owner-confirmed.)
- **D2 — SQLite (WAL) state store**, id-keyed entries, last-agreed state as
  first-class persisted data.
- **D3 — Session `ApiKey` is the device credential**, minted via the
  device-link browser ceremony (works for passkey-first accounts, satisfies
  step-up, hands off the vault key). Password `auth/login` remains a CLI
  fallback for headless boxes.
- **D4 — Device identity is a new `SyncDevice` row** bound to the session key;
  liveness piggybacks on `drive_changes` (no extra endpoint).
- **D5 — Conflict policy:** remote-head-keeps-path + conflict copy uploaded as
  a new file (Dropbox model); naming carries date + device name; always
  surfaced.
- **D6 — Vault key custody = OS keychain after a one-time browser handoff.**
  Routine unlock without prompts is what makes encrypted sync usable;
  custody class matches platform password managers; snapshots stay worthless.
  Per-sync passphrase entry rejected as an option users would turn off the
  feature over.
- **D7 — Edit wins over delete, both directions; deletes only win against
  unchanged content; remote-initiated deletes land in the local OS trash.**
- **D8 — Case/Unicode sibling clashes do not materialize a second file** —
  surfaced as unsyncable; illegal-char names materialize via DB-backed
  reversible mangling.
- **D9 — Poll-only change detection in v1** (30 s + post-mutation immediate
  poll). The feed is cheap; push is a later additive endpoint (deferred).
- **D10 — Whole-file transfer in v1**; dedup-by-possession already removes
  the worst re-transfer cases (moves, rebuilds, copies). Delta sync deferred
  — it demands server-side CDC and an encryption-envelope redesign.
- **D11 — Mass-delete guard** at `max(50, 25%)` per round, blocking prompt,
  both directions; unavailable sync root hard-pauses instead of reading as
  deletion.
- **D12 — Sync scope v1 = own drive; shared-with-me is Phase 5 opt-in per
  grant root.**
- **D13 — Simulator boundary:** in-process Rust mock server implementing the
  documented contract, kept honest by a live parity gate against dev — not an
  embedded PHP server.
- **D14 — `drive_index` uses keyset pagination (`after_id`)**, tolerating
  concurrent mutation, replay-safe with the change feed.
- **D15 — `modified_time` is plaintext-only server-side; encrypted files
  carry mtime inside the encrypted metadata blob** (additive `mtime` field).

- **D16 — Shell = cross-platform Rust tray + daemon-served local settings
  UI** opened in the default browser. Smallest maintained surface, zero UI
  framework risk, and the daemon stays fully headless-capable. Tauri and
  per-OS native shells rejected for v1 (framework weight / three UI codebases
  for a deliberately thin surface).
- **D17 — Naming:** "Joinery Drive" app name, `joinery-drive` binary,
  `~/Joinery Drive` default sync root. Owner-confirmed.
- **D18 — No sync-specific tier gate.** Desktop sync is included wherever
  `drive_active` is on; storage quota — already a tier feature — is the
  commercial meter. Gating the flagship trust feature would undercut the
  reliability wedge; a gate stays additive to introduce later if ever wanted.
- **D19 — Central update feed** at getjoinery.com: one signed artifact
  stream, instances stay zero-config. Per-instance feeds rejected (every
  instance would need its own signing infrastructure).

# Deferred items

Deliberately not in v1; none block any phase.

- Native passkey-PRF vault unlock via CTAP2 `hmac-secret`
  (salt = SHA-256(`joinery-passkey-prf:vault-drive-kek`)) — keychain custody
  (D6) is the answer until revisited.
- Push / long-poll change notification — 30 s polling stands (D9).
- An instance-wide admin page of sync devices (per-user visibility on
  `/profile/security` ships in Phase 0).
- Executable-bit / xattr / ACL preservation.
- Client-generated encrypted thumbnails from desktop uploads.
- Finder/Explorer overlay badges and context menus.
- Block-level delta sync (D10) — revisit when telemetry shows large-file
  edit-in-place is a real workload.
- A UI for user-editable ignore patterns; the built-in junk list ships
  (`.DS_Store`, `Thumbs.db`, `desktop.ini`, `~$*`, `*.tmp`, `.~lock*`,
  `.jd-tmp-*`, `.jd-swap-*`), and a config-file escape hatch may ride along
  at no design cost.

**Phase-6 prerequisite (procurement, not design):** an Apple Developer ID
(paid program — shared blocker with the iOS release spec) and a Windows
code-signing certificate must exist before signed installers can ship.

# Docs to update when phases land

- `docs/drive.md` — "Sync clients" section: device linking, the sync API
  additions (`drive_stat`, `drive_index`, exports), device management.
- `docs/drive_encryption.md` — device vault-key custody, the metadata `mtime`
  field, the desktop client as the served-JS hardening consumer.
- `docs/file_signed_urls.md` — ranged download contract.
- `docs/api.md` — `auth/device_link` family, new rate bucket, new actions.
- `docs/account_security.md` — sync devices on the security page, revocation.
- New `docs/drive_sync.md` — the client architecture, state model, conflict
  policy, health model, and the simulation-harness contract (current-state
  voice).

# Out of scope (deliberate)

Mobile backup (separate spec); files-on-demand placeholders; LAN sync;
server-side content search inside encrypted files (impossible by design);
delta sync (O13); preserving xattrs/ACLs/forks; syncing non-`drive` sources.
