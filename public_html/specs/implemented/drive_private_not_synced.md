# Drive sync: Private folders stay off computers, as promised

**Status: IMPLEMENTED (2026-10-15, drive-b3).** Client and mock built.
Verified: the pins (each red under its knockout), and the Rust suites, sweep,
journals and ring against the mock, unchanged from the baseline (the sweep has
no Private arm). Reviewed by reviewer1 (needed, valid). The runs against a real
server, with a folder made Private and back, are in the live verification
queue.

## The problem, plainly

`docs/drive_sync.md` says Private folders are not synced: the server opens a
Private file only inside its owner's unlock window, the window belongs to a
browser session, and a computer running the sync client never has one. The
server says so on every export (`syncable: false`, with `protection_level`).
The client never reads it. A Private folder therefore syncs like any other
folder, and fails in three ways:

- **Every Private file fails to download, every pass, for ever.** The server
  gives a sync client no download link for a sealed file (it could never open
  it), the client reads the missing link as a contract fault, withdraws the
  download, raises an issue, and plans the same download again on the next
  pass.
- **Every edit to a Private file on a computer is refused.** Replacing a
  sealed file's contents needs the window (`vault_needed`). Since
  `drive_refusal_reasons` the refusal is held for an hour or until the feed
  moves, so the whole file goes up again once an hour for ever, with the issue
  cleared and raised each time.
- **A new file saved into the folder on a computer goes up**, and the server
  seals it, so the folder fills with files no computer can then download.

The simulator has no Private level at all, which is why none of this ever
showed in a sweep.

## What changes

1. **The client reads `syncable`** on every folder and file export (feed,
   index, stat, listing) and keeps it on the record. An export without the
   field (an older server) reads as syncable, as today.
2. **A Private folder is out of scope on every computer**, the way a vault is
   on a FAT drive (`vault_on_a_weak_drive`): the top folder whose export says
   `syncable: false` is marked out of scope, and everything under it follows
   it. The engine then neither puts anything in its directory nor touches
   what is there: nothing is downloaded, nothing saved there is uploaded,
   nothing is moved or deleted.
3. **One issue per Private top folder says so**, in plain words: the folder
   is Private, Private folders open only in a browser, and (where the folder
   had synced before it was made Private) the copy already on this computer
   stays as it is and is no longer synced.
4. **It is undone when the folder stops being Private.** The status returns
   to synced (or a pending download where nothing was ever placed) and the
   issue is dismissed; the folder then syncs as any other.
5. **Only a top-level folder's own flag is read.** A level is a top-level
   tree's promise on the platform (`DriveFolderLevel`): the feed carries one
   change for it, on the top folder, and the files under it are converted
   afterwards with none, so a file's or a subfolder's own flag can be stale
   either way. Everything under the top folder follows it. The server's
   other `syncable: false`, a file another subsystem stored, never reaches a
   sync client's walk (`drive_index` reads `fil_source = 'drive'` only).
6. **Moves on the computer follow the park's rules for a vault on a FAT
   drive.** The parked folder renamed or moved here, or a file moved within
   it, follows on this disk only, and naming leaves its names alone. A file
   carried OUT of it is let through, known by the tree it leaves (a level
   change converts files with no change on the feed, so a file's own record
   need not know it is Private): a file the server holds is sent as a move,
   which the server refuses until the owner's window is open (`vault_needed`,
   held since `drive_refusal_reasons`), so nothing goes up in the clear; a
   file never sent goes up from where it now stands, as any new file.
7. **The mock server models Private**: a top-level folder can be made
   Private and back (one `level_changed` on the feed, files converted
   without one, as `DriveFolderLevel::flip` does), its files then export
   `syncable: false` with no download link for a sync client, a new version
   or a move out is refused `vault_needed`, a file moved or uploaded into it
   is sealed, and a folder does not cross a level by moving
   (`level_change_refused`, standing). Its standing refusals also name their
   reason (`forbidden`, `quota`), as the platform's do. Not modelled: the
   platform also refuses a file's move out of Private as
   `level_change_refused`, standing, when the file is too large to convert in
   one request or is shared; no pin needs it.

Local copies left from before a folder turned Private are kept, never
deleted: the engine does not remove a user's files for a change it did not
make, which is the rule a locked vault and a vault on a FAT drive already
follow.

Once a tree is Standard again, a file the server has not yet converted back
gives a sync client no download link, so its download is withdrawn and
planned again on the next pass until the conversion reaches it. That is
transient and heals itself; it looped for ever only while nothing lifted.

## Residual

A Private folder renamed on a computer follows there only, so the record
agrees with the local name. Made Standard again, the server's name then reads
as a rename made on the server, and the computer's folder is renamed back.
A vault parked on a FAT drive lifts the same way.

## How we know it is right

- Pins, each red before the change:
  - a Private folder on the server is never downloaded, raises one issue,
    and asks the server nothing more pass after pass;
  - a synced folder turned Private keeps its local copy untouched; an edit
    made there afterwards is not uploaded; a new file saved there is not
    uploaded;
  - the folder turned back to Standard syncs again, and the issue goes;
  - a file seen Private while its tree was Private comes down once the tree
    is Standard again;
  - an older server's exports (no `syncable`) sync as today.
- Sweep and ring against the mock, every journal difference traced.
