# Drive: every refusal a sync client can meet says why

**Status: IMPLEMENTED (2026-10-15, drive-b3).** Server, mock and client
built. Verified: the mechanical test (25/25, red on the tree before), the PHP
drive suites (20/20), the pins (red without the client change, green with
it), and the Rust suites (1494/0), sweep, journals and ring against both mock
modes, unchanged from the baseline. Reviewed by reviewer1 (NEEDED + VALID).
The rig and soak runs after release are in the live verification queue.

## The problem, plainly

When the server refuses something the sync client asked for, the client has to
decide what to do next: try again, plan again from the disk, hold the request,
or rename around a clash. It can only decide well if the refusal says why.
About a hundred of the refusals in the eleven Drive actions the client calls
say why only in English prose, which the client must not parse.

Today a refusal with no machine-readable reason is treated as possibly a name
clash, in six places (uploads, folder creates, moves and renames). That guess
costs real things:

- a corrupted upload went up again as a "conflicted copy" of itself (B-IR);
- B-IR's fix had to add an extra listing call to check the guess, and that
  check can still be misled by a folder of the same name;
- every reason-less refusal that is not about a name walks through the
  rename recoveries before it is dropped.

## What changes

1. **Every refusal from the eleven actions carries `reason`** in its error
   data, beside `standing` where the same request is refused again (see
   `docs/drive.md`, Standing refusals). The eleven: `drive_changes`,
   `drive_index`, `drive_stat`, `drive_list`, `drive_upload_init`,
   `drive_upload_complete`, `drive_folder_create`, `drive_rename`,
   `drive_move`, `drive_trash`, `drive_public_keys`.
2. **The server says so.** `drive_changes` and `drive_index` answer with
   `refusals: 'reasoned'`. A client that has seen it from a server stops
   guessing for that server: only `reason: name_taken` starts a name
   recovery, and a refusal with no reason is a server fault, withdrawn with an
   issue rather than read as a clash.
3. **An older server keeps working.** A client that has not seen the marker
   guesses exactly as it does today, so a site that has not upgraded loses
   nothing.
4. **A test keeps it true.** A mechanical test reads the eleven logic files
   and fails on any `LogicResult::error(` without a `reason`.

## The reasons

Existing reasons keep their names and meaning: `name_taken`, `parent_trashed`,
`file_trashed`, `quota`, `file_too_large`, `upload_incomplete`,
`invalid_parent`, `protection_boundary`, `changed_since`,
`idempotency_key_reused`.

New ones, each with what the client does with it:

| Reason | Where | Client |
|---|---|---|
| `drive_disabled` | Drive not enabled on the site | retry later |
| `signed_out` | no session | retry (the credential, not the op) |
| `invalid_request` | the client's own malformed request: a bad entity type, a missing or malformed field, an unknown scope or cursor, too many entities, missing encrypted metadata or keys | withdrawn, standing; a client bug. One is not standing: an encrypted upload with a key for a member who has since lost access, which a plan made again from the member list as it is cures |
| `name_too_long` | a name over the server's limit | withdrawn, standing; naming parks it |
| `not_found` | the entity, the file to update, or the upload token is gone | overtaken: plan again |
| `parent_not_found` | the parent or destination folder is gone | overtaken: plan again |
| `forbidden` | no permission, no access, no longer allowed | withdrawn, standing |
| `vault_needed` | a Private change needs the vault set up or unlocked | withdrawn and held like a standing refusal: only an unlock window lifts it, a window belongs to a browser session, and a sync client never holds one. Retried, every attempt at an upload would be a fresh one, the whole file sent every quarter of an hour for ever |
| `key_unavailable` | this device does not hold the file's key | withdrawn, standing |
| `level_change_refused` | a move would convert protection beyond what a move may | withdrawn, standing |
| `move_into_self` | a folder moved into its own subtree | overtaken: plan again |
| `too_deep` | the folder depth limit | withdrawn, standing |
| `no_room` | the server's disk is short of space | retry later |
| `storage_failed` | the bytes could not be stored or sealed | retry, on the backoff (doubling to a 15-minute cap). The one failure that would repeat for ever, sealing to an owner with no vault, cannot meet a Private folder, which needs the vault to exist |
| `action_failed` | the action threw (any action: the API's generic refusal) | retry |

A request the API's own input schema refuses, before the logic runs, comes
back as a `ValidationError` and is read as `invalid_request`.

Refusals outside the eleven actions (sharing, devices, versions, links) are
not part of this; the sync client never calls them.

## Build order

1. Server: the reasons, `refusals: 'reasoned'`, the mechanical test, and
   `docs/drive.md` (a Refusal reasons section).
2. Mock server (`jd-sim`): answer every refusal as the platform does -- 422
   with its reason, never the 404 or 400 the mock used for a missing entity
   or a missing field, which the platform never sends for these actions (the
   client met prose 422s in the field where the sweep met kinder 404s) --
   and carry the marker. Its silent mode (`refuses_without_saying_why`)
   drops the marker as well as the reasons, so the older-server path stays
   tested.
3. Client: remember the marker per server; map the new reasons; the six
   guessing sites guess only without the marker. `docs/drive_sync.md`.

## How we know it is right

- Pins: a reasoned server's reason-less refusal is withdrawn, never a conflict
  copy, and its issue says it came without a reason; an unmarked server's
  still steps aside as today; each new reason maps to its outcome; a
  `vault_needed` upload is held, not sent again; a trash answered `not_found`
  is done. The last is a defect the platform's answers showed: only a 404 read
  as already gone, the platform never sends one for these actions, and a trash
  of something already gone was withdrawn and the user told.
- The mechanical test is red on any refusal without a reason.
- Sweep and ring against both mock modes, every journal difference traced.
