# Drive Sync — Folders Shared With Me on the Desktop (Phase 5)

**Status: draft, reviewed by reviewer2 2026-10-15 (sound, findings folded in),
owner decisions recorded, nothing built.** Phase 5 of `drive_sync_clients.md`
(§II.10, D12), on the owner's decision of 2026-10-15:
**each share the user syncs runs as its own sync engine** (own state store, pass
and folder on disk), not as synthetic folders inside the engine that syncs the
user's own Drive. §II.10's shared-with-me bullet and D12 point here.

## What the user gets

Someone shares a folder with you on the web. The desktop app offers it ("Alice
Smith shared Team with you, 1.2 GB. Sync it here?"). Say yes and it appears as
`Joinery Drive/Shared with me/Alice Smith — Team/` and stays in step both ways:
Alice's changes arrive; if she made you an editor, yours go up into her folder,
counted against her storage; if she made you a viewer, the files are read-only and
nothing you do here changes hers. If she stops sharing, the folder leaves your
computer through the Trash, and anything you changed that never reached her is
kept for you. One share's trouble never stops the rest.

## Why one engine per share

The engine (`pass.rs` alone is 8,100 lines) assumes one tree whose top is the sync
folder, all of it the user's, every delete theirs to make. Synthetic share folders
inside it would need a directory never uploaded, never a user folder and never
trashed, every "parent none" in `pass.rs` would become a hazard, and one share's
stall would stall the user's own Drive. Per share, the engine sees an ordinary tree
and a small adapter at the wire says what "the top" means. Accepted costs: a small
database and thread per share, a supervisor in the daemon, and **a move between two
trees is a copy plus a trash** (§1.3), since no engine sees both.

# Part 1 — What the user sees

## 1.1 Where shares appear, and their names

```
~/Joinery Drive/                    the user's own Drive ("My Drive" below)
~/Joinery Drive/Shared with me/     belongs to no engine (Q1)
    Alice Smith — Team/             one share = one engine; its top = Alice's folder
    Alice Smith — Team (2)/         a second folder of hers also called Team
    Bob Li — Budget 2026/
```

- **Name:** `<owner's display name> — <folder name>` (first + last name, as the
  share notification uses), fixed at acceptance and kept in the config. Collisions
  compare as the disk does (case-folded on macOS and Windows, NFC); the later share
  gets ` (2)`, ` (3)`. The usual naming rules apply (Windows reserved stems and
  characters, trailing dots), capped at 120 characters by shortening the folder part.
- **Renames.** The owner's rename is followed (Q5) by the supervisor between passes,
  or waits with one issue while the name is taken here or a file inside is held open
  (Windows). The user's rename here: the own root's watcher sees it under `Shared
  with me/` (same directory identity) and the supervisor re-points that engine's
  root and the config between passes; nothing is sent. A share folder that is gone
  is that share's "folder missing" pause (D11).
- **Files dropped directly in `Shared with me/`** belong to no engine: one issue says
  so and where to move them; nothing touches them.
- **Folder shares only** in v1 (Q4).
- **Nested shares:** if Alice shares `Team` and `Team/Plans`, only `Team` is offered;
  `Plans` arrives inside it under `Team`'s role. A narrower grant inside a wider one
  changes nothing on the server either (`DriveHelper::grant_reaches` takes any
  reaching grant). The one difference: an editor grant on `Plans` inside a view-only
  `Team` is read-only here, and the share's status says so. A share that later ends
  up inside another share of yours is retired (§1.4).

## 1.2 Viewer and editor

| On the desktop | Own Drive | Editor share | Viewer share |
|---|---|---|---|
| New file or folder | goes up | goes up into the owner's folder, billed to the owner | moved to My Drive (Q3) |
| Edit | new version | new version of the owner's file | server copy put back; the edit moved to My Drive (Q3) |
| Rename or move inside | goes up | goes up | put back where the server has it |
| Delete | to the server's trash | **put back**: trash is owner-only (`docs/drive.md`) | put back |
| Files on disk | normal | normal | read-only, best effort (Part 4) |

"Put back" is a download of the server's copy, never a hold: the standing-refusal
hold (B-SH3) would leave the file missing for good. Each kind is one counted issue
("3 files you deleted in Alice Smith — Team came back: only Alice can delete files
there."). A role change restarts the engine with the new rights and marks; an upload
queued under the old rights is refused by the server and meets the viewer rule.

## 1.3 Moving things across the line

The server moves an item only within one owner's Drive, and no engine sees two
trees, so a drag between trees is a delete in one and a new file in the other.

| Drag | What happens | What the user is told |
|---|---|---|
| My Drive → editor share | uploaded into the share; the original goes to My Drive's server trash | "Moved into Alice Smith — Team: it now belongs to Alice and counts against her storage. Your original is in your Drive's trash." |
| editor share → My Drive | a copy goes up to My Drive; the original is put back | "Copied out of Alice Smith — Team: only Alice can remove files from it, so the original is still there." |
| share → share | copy into the destination; the original put back | the same, naming both shares |
| anything → viewer share | moved to My Drive (Q3); the source follows its own tree's rule | the viewer-share issue |

The supervisor pairs the halves (same hash, a delete in one engine and a new file in
another within one wake-up) into one sentence. Nothing is lost in any order: the
bytes are in their new place before either engine acts. If the owner's Drive is
full, the quota issue (§1.6) adds that the file is **not on the server yet**.
Dragging many files from My Drive into a share trips the own engine's mass-delete
guard (D11): the uploads go ahead, the deletes wait for the prompt, so the result is
duplicates, never loss. Whether a paired delete may pass without the prompt is
settled in 8b, against the fingerprinted approval being built for that guard.

## 1.4 A share that ends

The same steps whatever ended it: the owner removed the user; trashed, deleted or
made Private the folder (going Private ends grants); the owner's account went; or
the user removed the share on this computer.

1. The engine stops. **Work that never reached the server is kept**: unsent edits
   and new files move to My Drive as `name (unsynced local edit).ext` (a folder's
   under `<share folder> (unsent work)/`) and go up as the user's own. A Fortress
   share's unsent work would go up in the clear from a plain folder, so it goes to
   `Shared with me/Kept from <share folder>/`, local-only, with an issue.
2. The folder goes to the **OS trash**, marks lifted first: D7's rule for every
   server-initiated delete and §II.10's for an unticked subtree. Then its state
   directory is removed.
3. One issue: "Alice Smith no longer shares Team with you. Its copy on this computer
   is in the Trash. 2 files you changed here are kept in your Drive: …" (when the
   user removed the share, it adds that those go up and count against their storage).

A share ends only on **positive evidence**: the scoped index answering
`share_ended`, or its `root` saying `deleted: true` (§2.2); never a failed request
or a list without it. A trashed folder keeps its acceptance: restored within the
trash window, the share comes back as a cold start (a new store, a full download;
the copy in the OS trash is not reused). A new grant after a removal is a new offer.

## 1.5 Private and Fortress shares

- **Private never syncs.** The server refuses to share a Private item, and making a
  shared folder Private ends its grants (§1.4). Entries marked `syncable: false` are
  parked as today.
- **Fortress syncs when this computer holds the account's vault key and the owner
  has granted the account each file's key** (her browser does so when sharing;
  later files are sealed to every reader, `drive_public_keys` reader-set mode, and
  an editor's upload is too). A file without its key waits as `pending_key` (green,
  counted: "4 files in Alice Smith — Team are waiting for Alice's browser to give
  you their keys") and opens when the key arrives with the new feed row (§2.4). A
  key withdrawn while access stays leaves the decrypted copy kept here (removing it
  would delete what the user holds), no longer updating, with one issue.

## 1.6 Storage, billing and status

An editor's uploads count against the **owner's** storage (single-owner trees,
`docs/drive.md`). Over the owner's quota the upload is held (B-SH3) and the issue
says whose storage it is: "Alice Smith's Drive is full. Files you add to Team count
against her storage, so report.pdf stays on this computer until she frees space."
The engine raises the `quota` kind (today a generic `withdrawn`), worded by the daemon.

**Status:** one tray indicator, the worst over all trees, except that a share that
cannot run shows **Attention**, not Stopped (kept for device-wide blockers). Every
issue names its tree ("In Alice Smith — Team: …"); `status` prints a line each.

# Part 2 — Server groundwork (WP7)

Today: `drive_index scope=shared` walks every grant and annotates `grant_root`;
`drive_changes` and `drive_stat` take `mine|all`, the latter with `not_yours`.

## 2.1 `drive_shared_roots` (new, read-only)

What the caller could sync: one row per access grant they hold.

| Field | Meaning |
|---|---|
| `entity_type`, `id`, `name` | the granted folder or file (folder names are plaintext, Fortress too) |
| `owner_id`, `owner_name` | whose it is, display name |
| `role` | `viewer` or `editor`: the caller's grant on this root |
| `protection_level`, `encrypted`, `syncable`, `deleted` | as `folder_export` |
| `within` | id of another granted folder containing it, or null (outermost) |
| `size_bytes`, `file_count` | live content under it, for the offer |
| `granted_time` | `fga_create_time` |

## 2.2 A share scope on the three sync reads

`scope: 'share'` with `root_id` (a folder the caller holds a grant on):

| Action | Today | Needed |
|---|---|---|
| `drive_index` | `shared` walks every grant, live subtree only (`descendant_folder_ids` skips the trash) | `root_id`'s subtree only, the root left out, **trashed descendants included** (`deleted: true`) as `mine` does; a `root` object (name, role, owner, deleted) on every page |
| `drive_changes` | `all`: own, grants, rows addressed to the caller | rows on entities now in the subtree, rows on the root, every row addressed to the caller |
| `drive_stat` | `all`: anything readable (an admin reads everything) | outside the subtree → `missing` + `not_yours`; trashed inside → an item with `deleted: true` |

The filter is the subtree, not readability (an admin reads every folder). With no
grant left reaching `root_id`, or the root deleted forever, all three refuse with a
new standing reason, **`share_ended`**. A trashed root is not ended (the index says
`root.deleted: true`). Share-scoped polls leave `sde_last_cursor` to the own feed.

## 2.3 One doorbell instead of a poll per share

`drive_changes scope: 'shared'`: rows seen through grants or addressed to the
caller, never their own, each annotated `grant_root` (the outermost granted folder
the entity sits in now, or null). The daemon polls it once per interval on its own
cursor and wakes only the engines a row names; a row addressed to the caller, or
with no `grant_root`, wakes them all (it may be about a root the item has left,
§2.4). A doorbell reset means: re-read the roots and wake every engine. A fresh
doorbell at cursor 0 needs no history and starts at the head that reset carries.

**The budget is per address.** Key traffic is metered by address before
authentication (`api/apiv1.php:484`, the `api` bucket, 1000/h): four computers on a
home network polling every 30 s spend 480 of it before any stat, so the shares get
one feed between them, never one each. Q6 picks between this doorbell and routing
the own feed: the own engine polls `scope: all`, its wrapper keeps rows on others'
entities out of the engine's view, and the daemon hands them to the share engines
(`pass.rs` stays single-root). A lost hand-off costs at most the 10-minute safety
poll (§3.4), since each share engine reads its own scoped feed.

## 2.4 Two missing feed rows

Both server rows below are **built** (WS-J, committed 2026-10-16).

- **A key grant (B-KG).** `drive_key_grants_sync` records a `grant_changed` row per
  file per newly keyed user, addressed to them (`FileKeyGrant::sync_for_file`
  returns them), and per user whose key is removed. Today it records none, and a
  sharee's files wait in `pending_key` until a full walk. No new kind: older clients
  already stat on `grant_changed`. These rows are per file, so the supervisor
  re-reads `drive_shared_roots` only for a `grant_changed` on a folder.
- **An item leaving a share (B-MV, found while designing).** The feed judges
  visibility by where an entity is *now*, so the engine holding an item that left
  its share is never told and keeps the copy for good. **Any action that changes an
  entity's parent addresses the users who lost reach**: `drive_move`, and
  `drive_restore` re-rooting a folder whose parent is still trashed (today a
  `restored` row with no audience). Reach counts **per root, not per user**: the row
  goes to every user who loses any outermost granted root reaching the item, or an
  owner moving a file between two folders she shares with one person leaves the old
  share a stale copy. Computed from the grants on the old and new parent chains (as
  `DriveHelper::reader_user_ids_for_folder` walks them, read per user and root).
  The old root's engine stats the item, hears `not_yours`, and descopes it (a folder
  with its contents) with the edit rescue; the new root's engine sees the usual row
  (a folder: E7).

## 2.5 Smaller additions, docs, tests

- `quota` and `file_too_large` refusals carry `owner_id`; `drive_stat` already
  hands a sharee their own `wrapped_file_key` (by reading), now pinned.
- `drive_changes` answers cursor 0 with a reset carrying the feed's head (E1):
  `changes_test.php`'s cold-start sections become "reset at 0, then walk", and the
  mock drops its `cursor > 0 &&` guard (`jd-sim/src/server.rs`, `drive_changes`).
  Built (B-CS, committed 2026-10-16).
- Docs in `docs/drive.md`; tests in `changes_test.php` and `sync_contract_test.php`
  (`tests/functional/drive/`), each failing without its change.

# Part 3 — Client architecture (WP8)

## 3.1 A share engine

Today's engine, given different things:

| | Own Drive | A share |
|---|---|---|
| Store | `<state>/state.db` | `<state>/shares/<root_id>/state.db` |
| Spool | `<state>/spool` | `<state>/shares/<root_id>/spool` (each engine sweeps its own at start; a shared spool would delete another's downloads in flight) |
| Disk (`OsVfs`) | the sync root, `Shared with me` hidden (Q1 a) | the share's folder |
| Server | the client | the client inside `ScopedApi` (§3.2) |
| Vault | the account's key | the same key: it opens file keys granted to the account |
| Rights (new, in `Context`) | write + delete | editor: write; viewer: none |
| Thread | its own | its own |

"Only the sync thread opens the store" becomes "only a store's engine thread opens
it". Every store records `instance_url`, `account_id` and (shares) `root_id` in
meta, and refuses to run bound to anything else.

## 3.2 `ScopedApi`: the top, said at the wire

A `DriveApi` wrapper in `jd-proto`, shared by the daemon and `jd-sim`:

- **Requests:** `scope: "mine"` on `drive_changes`, `drive_index` and `drive_stat`
  becomes `scope: "share", root_id: R`; a parent of none (`folder_id` in
  `drive_upload_init` and `drive_public_keys`, `parent_id` in `drive_folder_create`
  and `drive_move`) becomes `R`.
- **Answers:** a `parent_id` or `folder_id` of `R` becomes null. Rows and items
  about `R` itself leave the engine's view for the supervisor's (renamed: rename the
  folder; trashed, restored, `grant_changed`: re-read the roots). A `parent_trashed`
  naming `R` goes to the supervisor too; the engine sees a retryable error.
- **Viewer guard:** a write action from a viewer engine is refused locally as a
  standing `forbidden` and counted. The engine never plans one (Part 4); this is the
  second wall, and the simulator asserts the count stays zero.

## 3.3 Engine changes (`jd-core`)

- **E1. A cold start walks (B-CS, found while designing).** An empty store at
  cursor 0 replays the feed from 0 and never walks (`poll_remote`, `pass.rs:2290`),
  but the server trims rows after 30 days and never resets cursor 0: a new share or
  computer never sees a file untouched for 30 days (dev: 6 live files of user 4497,
  6 live folders). Fix: the server resets cursor 0 (§2.5), and the engine walks an
  empty store anyway against an older server. Both halves are built (B-CS,
  committed 2026-10-16).
- **E2. Rights.** Without delete, a local delete plans a download; without write,
  local edits, creations, renames and moves plan §1.2's viewer rule.
- **E3. `ExecEnv::keep_dir`**: where the refused-rescue path puts `name (unsynced
  local edit).ext`: the own top (today), or for a share My Drive's root or the
  Fortress keep folder.
- **E4. Descope a whole tree with rescue**: `out_of_scope` first, unsent work to
  `keep_dir`, then the OS trash. Share end and §II.10's selective sync share it.
- **E5.** `quota` / `file_too_large` refusals raise the `quota` kind.
- **E6.** The own engine's `OsVfs` hides `Shared with me` at its top (as it hides
  spool files); an own top-level folder of that name gets a reserved-name verdict
  (unsyncable here, one issue asking for a rename). Store schema 10 makes an older
  client refuse the store rather than upload the shares as a new folder.
- **E7.** A share engine treats a `moved` row on a folder its store does not hold
  as it treats `restored`: it walks. A folder moved into the share brings children
  whose rows all lie behind the cursor, and statting the folder alone finds none.

## 3.4 The supervisor (`jd-daemon`)

- **Lifecycle.** At start, on a `grant_changed` row on a folder, on any addressed
  `moved` row, and every 10 min: read `drive_shared_roots`, diff with the config,
  run an engine per accepted, outermost, syncable folder share; offer new ones (Q2);
  end those answered `share_ended` (§1.4); retire those now nested. The `moved` rows
  count because a move can make a new outermost root: with grants on `P` and on `S`
  inside `P/F`, `F` moving out of `P` leaves `S` a share of its own, offered at once.
- **Wake-ups.** A share engine passes when its folder's watcher settles, when a
  feed row names it (§2.3), every 10 min as a safety poll, or on "sync now"; the own
  engine keeps its 30 s cycle. Per computer: own 120/h, the doorbell's 120/h under
  Q6 (a), and 6/h per idle share, all against the address.
- **One watcher**, the own root's: events reach an engine's dirty set by path prefix.
- **Isolation.** A share engine's error or panic stops that share only; it restarts
  after 1, 5, then 15 min with one issue. Transfers share one device-wide limit
  (§II.11, default 3), so a large share cannot starve the own Drive. Pause is global.

## 3.5 Config, state, CLI

`config.json` gains `shares: [{root_id, owner_id, folder, role_seen, accepted_at,
state: offered | synced | declined}]`, per computer like selective sync;
`<state>/shares/doorbell` keeps the doorbell cursor. CLI: `joinery-drive shares`
(offered and synced, role, size), `shares add <id>`, `shares remove <id>`; control
endpoints for the settings page: `GET /shares`, `POST /shares/add`, `/shares/remove`.

## 3.6 Health and issues

`Snapshot` gains `shares: [{root_id, folder, owner, role, indicator, blocker,
counts}]`. Issue ids are per store, so the aggregated list keys them `own:<id>` and
`share:<root_id>:<id>`, and `DismissIssue` takes the key. The daemon words quota,
key-pending and cross-tree issues with the share's owner and folder.

## 3.7 Paths on each OS

| | macOS | Windows | Linux |
|---|---|---|---|
| Container | `~/Joinery Drive/Shared with me` | `~\Joinery Drive\Shared with me` | `~/Joinery Drive/Shared with me` |
| Name rules | NFC, case-folded | reserved stems (`CON`…), `<>:"/\|?*`, no trailing dot or space, case-folded, `\\?\` as today | bytes, case-sensitive |

All mounts sit on the own root's volume, so moving kept bytes out is a rename. The
container name is not localized: a language change would read as a mass move.

# Part 4 — Read-only trees (WP9)

**The guarantee is the engine's; the marks are a courtesy.** A viewer engine never
plans a remote write (E2), `ScopedApi` refuses one if it does (§3.2), and a local
change is undone by §1.2. Marks are set when an entry lands or changes, re-checked
on a full rescan, and lifted before a share is trashed or becomes editable:

- **macOS, Linux:** files `0444`, folders `0555`: an app that saves by renaming a
  temporary file over the original needs write on the folder, not the file. The
  engine lifts a folder's write bit around its own writes (a download commits by a
  rename) and the next pass repairs a crash in between. Not `chflags uchg`: it
  would stop the engine's trash and the user's.
- **Windows:** `FILE_ATTRIBUTE_READONLY` on files (Explorer, Office and the
  replace-file call honour it). Windows ignores it on folders, so new files there
  meet the engine rule instead; ACLs are not touched.

# Part 5 — Verification

## 5.1 Simulator (`jd-sim`)

The mock is one account with "shared in" marks (`seed_shared_in_folder`,
`hand_to_another_member`, `shared_in_read_only`). It becomes multi-account:

- accounts with names and trees; `MockServer::as_account(id)` returns a `DriveApi`
  for that caller over one state (the old helpers wrap a second account + a grant);
- grant, revoke and role change writing the platform's rows; `drive_shared_roots`,
  the `share` and `shared` scopes, `share_ended`, the B-KG and B-MV rows (moves and
  re-rooting restores), the cursor-0 reset;
- the platform's rules: owner-only trash, editor writes billed to the owner, viewer
  writes refused (standing `forbidden`), per-owner quota, per-user file keys;
- counters per caller and root (viewer writes, request totals).

New invariants beside converge and lose-nothing (the oracle spans every account and
kept copy): no viewer write reaches the mock; a grantee's own Drive gains share
content only as a kept or scripted copy; each synced share converges to its subtree.

Pins, each failing without its change: not synced until accepted; editor edit billed
to the owner; editor delete put back; viewer edit put back and kept in My Drive;
owner rename; owner moves a file out (B-MV); owner moves a file between two shares
of one grantee (the old copy leaves, the new arrives, no duplicate); a re-rooting
restore takes a folder out of the share; owner moves a populated folder into the
share (its files arrive, E7); revoke with an unsent edit; folder trashed and
restored (a cold start); role change with an upload in flight; drags both ways; owner
over quota; Fortress key after the file (B-KG); a share older than the feed window
(E1); one share's engine panicking while the own Drive syncs; a share becoming
nested; `Shared with me` invisible to the own engine (E6).

## 5.2 Race sweeps (the coordinator's decision)

A **shared arm**: an owner's, an editor's and a viewer's device; random ops plus
grant, revoke, role change, root rename, cross-tree drags, quota exhaustion. As for
every race sweep: a **fixed seed range in the default gate** (about 40 per arm; a
canary, green by construction) plus **nightly fresh seeds**, failures frozen.

## 5.3 Live, on drivetest (0.8.498 or later)

The 10-14 live share test's accounts (`soak-mac-share-1`, user 2532, owner;
`soak-mac-share-2`, 2533, grantee), new device homes in fresh `~/drive-work/sr-…`
slots on the mini (never `~/share-test`):

| | Check |
|---|---|
| L1 | Share Team as editor: offered, accepted, downloaded under `Shared with me/`; the grantee's own Drive unchanged |
| L2 | Grantee edits and adds: the owner's device and browser see them; the owner's usage rises, the grantee's does not |
| L3 | Owner over a low test quota: the grantee's upload held, the issue names the owner; freeing room lifts it |
| L4 | Role to viewer: read-only files; a forced edit comes back and lands in the grantee's My Drive; no write rows by 2533 |
| L5 | Owner renames Team, then moves a file out (B-MV): the folder follows; the copy goes to the Trash |
| L6 | Revoke with an unsent offline edit: folder in the Trash, the edit in My Drive, one issue |
| L7 | Drags both ways across the line: §1.3's outcomes and sentences |
| L8 | Fortress share, once the Phase 4 live gate exists: files open on the grantee; a later owner file opens; a grantee upload opens in the owner's browser |

# Work packages, in order

| WP | What | Size | Risk | Needs |
|---|---|---|---|---|
| 7 | Server: §2.1-2.5, docs, tests | M | low: additive; old clients send `mine` and see nothing change except the cursor-0 reset, which they already handle | — |
| 8a | `ScopedApi`, multi-account mock, E1 (fixes the own engine too), E3, E7 | M | medium: the mock change touches every scenario's setup | 7 for live only |
| 8b | Supervisor, config and state, watcher routing, naming, offers, CLI and control endpoints, health, folder rename, share end (E4), cross-tree pairing, E5, E6 | L | **high**: a new process shape around a proven engine | 8a |
| 9 | Rights (E2) and read-only marks | M | medium: marks against the engine's own trash and renames | 8a; ships with 8b, or a viewer's edits are held for ever |
| 10 | Shared sweep arm, gate range + nightly | S | low | 8b, 9 |
| 11 | Live L1-L7 on drivetest; L8 after the vault live gate | M | medium: writes on the test server only | 7 deployed, 8b, 9 |
| 12 | Docs: a "Folders shared with you" section in `docs/drive_sync.md`; `docs/drive.md`; §II.10 and D12 point here | S | low | as each lands |

# Owner decisions

Decided 2026-10-15: **Q1 (a)** shares live inside the sync root under `Shared with
me/`; **Q2 (a)** a new share is offered on each computer and synced on yes; **Q3
(a)** a view-only edit is put back and the edit moves to My Drive (Fortress shares
keep it in the local-only folder, §1.4); **Q4 (a)** files shared on their own are
not synced in v1; **Q5 (a)** the local folder follows the owner's rename. The
other options, and the catch accepted with each choice:

- **Q1** (b) beside the root: two places to find and pin; (c) chosen at link time:
  two layouts to test for ever. (a)'s catch: an own top-level `Shared with me`
  folder does not sync here until renamed (E6).
- **Q2** (b) every share: someone's 200 GB folder fills every disk unasked; (c) by
  size: shares grow afterwards. (a)'s catch: an offer can go unnoticed.
- **Q3** (b) kept beside the original, never sent: a new "never send" engine state;
  (c) a local-only keep folder for all: lost with the computer. (a)'s catch: the
  edit leaves the folder the user was in (the issue says where).
- **Q4** (b) a local folder per owner: a top with no server folder, so nothing can
  be added. (a)'s catch: such files are never on the desktop.
- **Q5** (b) keep the accepted name: names drift from the web. (a)'s catch: a file
  open in an app loses its path.

Open, from review:

- **Q6. How shares hear about changes (§2.3).** (a) A doorbell feed, one more poll
  per computer; catch: four computers behind one address spend 960 of its 1000/h on
  polls alone. (b) The own engine polls `scope: all` and the daemon routes rows by
  owner; catch: share wake-ups ride on the own engine's cycle (the supervisor polls
  `shared` itself while it is paused or its folder is missing), and a busy share's
  rows fill the own feed's pages. **Recommend (b)**: polling stays at today's level.

Open item, not a decision: **the feed cursor can skip an id** when two writers
commit out of id order (a lower id lands after the cursor passed it), every feed
reader's exposure today. A feed returning only rows from transactions older than
the oldest one in flight (`pg_current_snapshot()`'s xmin) would close it with no
cursor change; the doorbell and every share engine inherit the caveat until then.

Found while designing, both blocking this phase and both wider than it: **B-CS**
(E1: a newly linked computer never gets files untouched for longer than the feed's
retention) and **B-MV** (§2.4: an item leaving a share stays on the grantees'
computers).
