# File identity

**Status: approach NEEDED and VALID (public-html-e9, 2026-09-25); O3-O6 are
build requirements checked in the commit-2 diff. Commit 1 (disk and record)
NEEDED and VALID 2026-09-25, its bar met: on 420 seeds (swaps on 160, off
160, held-out plain2 100), every trace byte-identical to `9b992a58`, and
every log identical once the random `enc-` content ids are masked (4 raw
differences, all of them `enc-` names, which differ between two runs of
`9b992a58` alone: R6's field whose meaning differs by branch, the reset's
B10). Commit 2 (the scan, and the upload's half of T1-D) VALID 2026-09-25
(freeze 609ad11f): against commit 1 on the same 420 seeds, swaps off
identical; swaps on, leaked files 158 to 40 and swap-oracle fires 74 to 5;
every G->R traced, to older gaps now filed as the reset's B2-B9. Commit 3
(the executor) is next.**

**Owner decision 2026-09-25: a file's own identity decides which record it belongs to, and its
path decides only when that identity is gone. This settles the reset's AH
decision, and it is the reset's cause 2 (`drive_sync_reset.md`, "Why a
reset"). T1-C, T1-D and R stop as separate fixes; they are routes into this
one cause.**

## What the user gets

Files keep their identity when they trade places. Two files that swap names
are two moves, not two edits that pour each file's contents into the other's
history, and a sealed file can never be sent up as some other file's
contents because it happens to stand at that file's name. A file renamed and
edited between two scans keeps its version history instead of starting over.

## The cause, in one paragraph

The scan decides which record a file on disk belongs to by its path
(`scan::pair` rule 1). The file's own identity, its inode, is consulted only
in exceptions: the held-file skip, the three-part trade test
(`arrived_by_a_trade`), the D1 return (`server_home`), the awaiting-bytes
slot. It had to be that way. The engine records only the inode number
(`jd_vfs::Fingerprint::file_id`), and disks hand a deleted file's number to
the next new file, so a bare inode once bound records to strangers. Hence
rule 4's doctrine: a bare inode may fund order, never identity. What is left
is a path rule, plus exceptions for the ways the path lies. Every open file
defect in the reset is one of those ways not yet covered:

- **AH:** a file standing at another record's path is read as that record's
  edit.
- **T1-C:** a record for a file never uploaded has no inode, so no exception
  can name it.
- **T1-D:** the executor acts on whatever stands at the path.
- **R:** a record without an inode reads a stranger.

The sweep's leaks under swaps (158 files, 78 seeds on `9b992a58`) are
nearly all these four.

## The mechanism

**Identity is the file id plus the birth time.** Every mainstream disk
records when a file came into existence: APFS and HFS+ (`st_birthtime`),
NTFS and ReFS (creation time), and ext4, btrfs and xfs (`statx` btime). Rust's
`Metadata::created()` reads all of them. A rename or a move within the volume
keeps both values. A safe-save, a copy, or a new file gets a new pair. A
recycled number arrives with a new birth, so the pair no longer matches and
recycling becomes visible. Rule 4's doctrine stays true of a bare inode. This
pair is not a bare inode.

- **Strong identity:** a nonzero file id and a birth time, on a volume whose
  ids are not positions.
- **Weak identity:** anything else. That covers a filesystem with no birth
  time, FAT and exFAT (their "file ids" are directory positions, which change
  on every move), a Windows handle that would not open (file id 0), and
  network volumes until proven. A weak identity reads by today's rules,
  exactly as they are.
- **The volume is asked, not named (O4).** `Personality::probe` asks today
  only about case and normalization. It gains one probe in the same style:
  write a probe file, read its id and birth, rename it in place, and read
  them again. The volume is weak if any of these holds:
  - the id changed (positional ids: FAT, exFAT, path-hashed network ids);
  - the birth is zero;
  - the birth is not within about a minute of now. A constant or epoch birth
    would make the pair a bare inode again, and rule 4's bug would return.

  That catches unknown FUSE and network volumes without naming them.
  Windows: the 64-bit index `GetFileInformationByHandle` gives
  (`real.rs` `file_index`) is documented as not unique on ReFS, which is
  what a Windows 11 Dev Drive is. The build reads the 128-bit `FILE_ID_INFO`
  through `GetFileInformationByHandleEx`, or marks ReFS weak.
- **NTFS tunnelling** copies an old file's creation time onto a new file
  saved under its name within 15 seconds. That is harmless: the file id
  differs, and NTFS file ids carry a sequence number that changes on reuse.

**Why the pair holds, platform by platform** (reviewer, read, not yet traced
on hardware). The pair is load-bearing exactly where file numbers recycle,
and there the birth cannot be forged:

| Volume | Numbers recycle? | Birth | Birth settable? |
|---|---|---|---|
| ext4, xfs v5 | yes, freely | statx btime, ns | no: no Linux API sets it (`utimensat` cannot) |
| xfs v4 | yes | none (zero) | -- weak |
| btrfs | no: 64-bit monotonic | statx btime | no |
| APFS | no: 64-bit monotonic | `st_birthtime` | yes (`setattrlist`; Finder copies keep it) |
| HFS+ | no: 32-bit CNIDs, until wrap | `st_birthtime` | yes |
| NTFS | reuse bumps a 16-bit sequence in the id | creation time | yes (`SetFileTime`; Explorer copies keep it) |
| ReFS | see O4 above | creation time | yes |

Where a birth can be set, the number does not recycle, so a copied birth
never meets its old number. What is left is a constant fake birth, which
O4's probe marks weak.

**Every record knows its own file from the moment it exists.** A new field,
`own_file` (file id and birth), is separate from `synced_fingerprint`. That
one stays what both sides agreed, for change detection. When each event
happens:

- **Set:** when a provisional is minted from a scanned file; when a download
  or restore places a file; and when the record takes a new file (a
  safe-save read as an edit). A placed file's identity is read from the
  committed target, which `OsSpoolFile::commit` already stats, not from the
  spool handle (O5). The spool lives under the state directory, so the
  handle's identity is the placed file's only when the commit is a
  same-volume rename.
- **Carried:** by every engine move of the file: follows, parks under a
  scratch name, `move_local`. A move does not change which file it is.
- **Kept through the agreement's resets.** The upload that records "nothing
  agreed about this file's fingerprint" (`execute.rs` `upload`, the arm that
  clears `synced_fingerprint`) leaves `own_file` alone: the file is still
  this record's.
- **Kept after a server delete**, until the record is forgotten. The reset's
  open item "a record the server trashes drops out of the scan's inode owners
  in the same pass" is that gap.
- **Handed over, never copied,** where the engine gives the file to another
  record:
  - the conflict rescue (`preserve_local_as`): the copy set aside takes it,
    and the original has none until its download places one. This is the
    state T1-C found the original in, taking whatever stood at its path;
  - the keyless crossing claimant: the claimant standing at the moved
    file's new path takes it from its source;
  - `merge_file`: the real entry takes the provisional's when it keeps an
    agreement here. Without one, the file is a spare copy `make_room` sets
    aside, and it is nobody's until it is found again as new.
- **Dropped where the engine gives the file up:**
  - a park or a disowning that leaves the record unsyncable, with its copy
    trashed or found to be another record's;
  - a placement dropped because its folder is gone from this disk;
  - the children of a folder taken off this disk.

  Every drop site also clears the agreed placement and fingerprint, and the
  scan offers only records with an agreed placement (`known_local`). So an
  own file kept on a record the scan cannot see would be read by nothing:
  the drop matches what the scan can see, and adoption at home cannot undo
  it.
- **Existing records** adopt `own_file` at the first scan that finds their
  file at home (the file at their path carries their recorded file id).
  Until then they are weak. A record made since has one from its mint, its
  download or its upload, so an adoption in the simulator names a site that
  should have set it; commit 1's build report counts them.
- **It is THE identity, not a second one (O3).** Every engine site that reads
  `synced_fingerprint.file_id` as "this record's own file" switches to
  `own_file` in commit 2, or the build report names why that site stays on
  the agreed fingerprint. At `9b992a58` those sites are `pass.rs` 2779,
  2797, 3074, 4040, 4275 (`held_and_away`) and 4430, and `execute.rs` 1198,
  3347 and 3675. Otherwise a conflict rescue that clears
  `synced_fingerprint` (`execute.rs` 2417, 2753, 4458, 4702) leaves the two
  disagreeing: `held_and_away` says away while the scan says at home.
- **Handed over, never copied.** Every site that hands one record's file to
  another moves `own_file` with it: adoption (`execute.rs` 2202) and
  `merge_file`. A copy would leave two live records owning one identity,
  and step 1's tie rule would then run on a state that is not a hard link.

**The scan, with strong identities.** Precedence, each step over files no
earlier step claimed:

1. **At home.** A record whose own file stands at its path takes it:
   unchanged, or edited in place. If several records hold one path and one
   identity (case twins, hard links, a server-deleted record), one takes it:
   the one whose agreed bytes stand there, then a synced record before a
   provisional, then the lowest id. The rest are unchanged, for naming.
2. **Followed.** A record whose own file stands somewhere else follows it:
   moved, or moved and edited. Two exceptions:
   - *Hard links.* An identity that a record is at home with in step 1 is
     that record's. Other records sharing it read by path in step 3
     (reviewer probes p2, p8).
   - *A backup made by renaming.* The record's own file stands under a new
     name in the same folder, at a path no record holds, and a file no record
     owns stands at the record's path. That is a save: the file at the path
     is the edit, and the renamed one is new. This is what Emacs, vim and
     Word's mid-save moment look like (p1, p7). The same folder is the
     point. A backup is written beside its original, and a vault edge is
     never inside one folder.

     **Never for a held record (O1).** A held file is sealed but stands in a
     plain folder, so the vault edge argument does not cover it. Take a held
     file at `Plain/out.txt`: the user renames it in place to
     `Plain/out2.txt`, and something saves a new file at `Plain/out.txt`.
     The exception would make the held record the stranger's edit, and leave
     `out2.txt`, the vault file's plaintext, as a new plain file that goes up.
     A held record's own file is the file, and anything at its path is a
     stranger. That holds for both kinds: a D1 hold, and a source held by a
     claimant waiting for a vault key.

     **An awaiting slot is a path a record holds (O6).** A live record whose
     bytes have not landed yet (`awaiting_bytes`) is left out of `known`
     (`pass.rs` 3970). In this exception, in step 3 and in step 5, its path
     counts as held. The build report says whether step 5 can reach an
     awaiting slot at all. Otherwise a trade with an awaiting slot reads as a backup save,
     and T1 (plain2 75292) returns.

   With several unclaimed names for one identity, the record takes the name
   in its own folder first, then the first path in byte order.
3. **Replaced.** A record whose own file is gone from the disk takes a file
   at its path that no live record owns: the safe-save, an edit. A file
   another record owns, or one standing at an awaiting slot, is never taken
   here.
4. **Weak records** (no strong identity recorded) run today's rules 1 to 3,
   including the trade test, over files no strong record owns.
5. **Found by content.** A record still unsettled whose agreed bytes stand
   on an unclaimed file no record owns, and not at an awaiting slot, has
   moved. Examples: a move to
   another volume, or a restore from a backup. Its own folder comes first,
   then byte order.
6. **The rest are deleted.** One exception: a live record whose path holds
   another record's at-home file is left unchanged, for naming (case twins).

Files no step claimed are new.

**Every pairing binds the record's own file** (`ScanOutcome::bound`): the
file a record is paired with in steps 1 (the winner), 2, 3 and 5, and the
backup exception's file, is its own from then on -- including a record read
unchanged against a file that is not the one it knew (restored from a backup
with the same bytes). Step 1's losers and step 6's twins are not bound: the
file at their path is another record's.

**The invariant:** a file whose strong identity a live record owns is claimed
by that record or by nobody. The record releases it in two cases: to a hard
link at home, or as a backup (step 2, never for a held record). Steps 3, 4
and 5 never take it.
A held record reads only by its own identity: step 1, and step 2 without
the backup exception. It never reads by step 3 or step 5, as today's held
skip says for rule 1.

**Vault policy is unchanged and sits above.** A sealed file moved out of a
vault is held (D1), and a plain file moved into one converts. The difference
is what reaches the policy. A swap across a vault edge now arrives as two
moves (hold, convert) instead of two edits (a leak, and a sealed record
holding plain bytes).

**The executor acts only on the file it planned for (T1-D).** Every op that
reads or moves a local file carries the identity the plan saw:

- **Upload and version upload** open the file once and check the handle's
  identity. Then they hash and send from that same handle. Today `upload`
  fingerprints by path, hashes by path, then `open_read`s the path a third
  time. On a mismatch, nothing is sent (`Overtaken`), and the next scan
  re-reads.
- **`move_local`, `trash_local` and the conflict and park renames of a file**
  check the identity at `from` immediately before the rename, as the folder
  arm already asks the directory. POSIX has no conditional rename, so a
  window of microseconds remains. What a move there gets wrong, the next
  scan reads by identity. No bytes are sent from it.
- **With a weak identity** the file id alone is checked: a mismatch costs a
  pass. That is order, not identity, which is the doctrine.

**A file moved over another's name (O2).** The strong scan reads it as the
mover moved and the file it replaced deleted, in the same pass. Today's plan
cannot run that:

- The stages run CreateFolders, Move, Transfer, Delete (`order.rs`
  `Stage`), so the trash runs after the move.
- `order::dependency_graph` counts only movers (items with `move_from`) as
  slot occupants. A move onto a slot whose holder is being trashed has no
  blocker, and it runs first.
- The server refuses it: `DriveHelper::file_name_taken` counts only live
  rows, so the not-yet-trashed holder has the name.
- `held_by_a_rename_this_device_owes` waits only for `move_remote` and
  `park_remote`. So the first attempt takes a conflict name and brings the
  disk along: the user's `Report.txt` is renamed on their own disk to
  `Report (conflicted copy).txt`, and the trash lands afterwards. The end
  state is converged and wrong.

Both of these are needed, in both directions. The peer's replay
(`ApplyRemoteMove` with `TrashLocal`) has the same stage order as the
device that made the move (`move_remote` with `trash_remote`).

1. **Planner.** A move whose destination slot is held by an entity planned
   for deletion this round, or one with a queued trash, is impossible this
   round. It is left out and derived again next round, as the ancestry rule
   in `dependency_graph` already does. The trash lands this round and the
   move next round.
2. **Executor.** `held_by_a_rename_this_device_owes` also waits on a queued
   `trash_remote` holder, answering Retry and never a conflict name. This is
   for when the trash is queued but has not landed, for example backing off
   after a fault. Such a holder can be missing from the planner's items
   (`entities_with_open_ops`, `pass.rs` 586), so the planner alone would let
   the move through.

## What it replaces

In the scan's strong path, rule 1's exceptions go: `arrived_by_a_trade`, the
held skip, `server_home` and the `awaiting_bytes` slot test. The strong path
does not reach them; the weak path keeps them. Rule 4's chosen price (moved
and edited is a delete plus a creation) ends for strong identities. The
enum variant `MovedAndEdited` is still wired through `remote.rs` and
`reconcile.rs`.

T1-C's scratch prototype (the provisional's minted identity as a hash-cache
link, `linked_identity`) is dropped, since `own_file` is that link on every
record.

D1's waits W1 and W2 exist because a held file moved and edited reads as a
delete plus a creation. With strong identities it reads as the held record
moved and edited. W1 and W2 stay in the tree, and come out one at a time on
WP3's bar, each with its shape report (R8), never in the same change.

## What it does not fix

- **A file whose identity is gone and whose bytes changed** still reads as a
  delete plus a creation. Examples: a copy to another volume and then an
  edit, or a safe-save and then a rename. The version chain is lost; no bytes
  are.
- **A backup-by-rename in which the user really renamed a file and made a new
  one** at the old name in the same folder reads as an edit, as today. From
  one scan they are the same disk.
- **Backups kept in a subfolder of the sync root** (a central `backups/`
  folder) read as a move of the original into it, and the new file at the
  old name is new. macOS atomic saves are not this: Cocoa parks the old file
  outside the sync root (`NSItemReplacementDirectory`, on the boot volume or
  in the volume's `.TemporaryItems`), so the record's own file reads as gone
  and step 3 reads an edit.
- **Weak volumes keep today's reading,** the scan's path-first rules,
  including its leaks under swaps. Rules outside the scan reach them as they
  reach every volume: O2's ordering, the own-file reads (by file id alone
  where the identity is weak), and the held-name set built from the scan's
  moves. On the 420-seed sweep with births hidden that moves 14 verdicts, 7
  each way, every one attributed by knockout to one of those three.
- **Folders.** WP2 gave folders directory identity. The same birth pair would
  retire C5's recycled-directory rule; that is a follow-up. C9 part 2, the
  folder make_room rule, folder T1, the D1 park gap and C13 are separate.

## Owner questions

- **Q2: a file saved in a vault and moved out before it was ever uploaded**
  (found building commit 2; the build holds it pending the answer).
  - *Hold it* (built). Nothing is sent; it stays on this device, and the user
    is told: "{name} was saved in a vault and moved out before it was
    uploaded, so it is kept only on this device and not uploaded. Move it
    back into the vault to sync it encrypted, or delete it." It is the same
    file, not a copy -- the same reason D1 holds a sealed file dragged out --
    and the sealed oracle counts it sent plain as a leak. A copy of it made
    outside the vault still goes up plain (the copy-out decision).
  - *Upload it plain.* What happens today, by accident: the path rule reads
    the file as deleted where it was and new where it is. The user who meant
    to move it out gets it synced; the user who did not has published it.

  Recommendation: hold. The wording is the owner's to approve.

  The hold's real scope is a file SEEN in a vault by a pass while unsent: a
  file saved outside, dragged into a vault, seen there, and dragged out again
  is held too (it may have been edited there). An over-hold, never a leak.
  If the owner picks hold, "stood in a vault" says that more truly than "was
  saved in a vault" (reviewer, 2026-09-25).
- **Q1: a vault folder on a weak volume** (a USB stick, a network share).
  Two options:
  - *Sync it by today's rules.* It works anywhere, and carries today's swap
    risk.
  - *Refuse and say why.* The vault's promise holds everywhere it syncs, and
    a vault cannot sync to a stick.

  Recommendation: refuse. This does not block the build; it is its own
  change after this one.

## Behaviour changes, pinned

**As built, all 25 of today's scan unit tests keep their result.** Their
fixtures carry no birth, so every identity in them is weak and they pin the
path-first rules as they are. The strong readings are new tests beside them
(`by_identity_*` in `scan.rs`), one per row below:

| Test | Today (weak, kept) | Strong identity (new test) |
|---|---|---|
| `a_recycled_inode_does_not_swap_two_files_identities` | deleted + created | the same, only once the fixture gives the recycled file a new birth. With equal births the strong path reads moved and edited. A fixture change, not a free pass. |
| `a_move_the_scan_cannot_confirm_reads_as_a_delete_plus_a_create` | deleted + created | moved and edited (weak: unchanged) |
| `a_file_at_its_own_path_is_claimed_before_anybody_goes_looking` (file 1 moved over file 2's name) | file 2 edited with file 1's bytes, file 1 deleted | file 1 moved, file 2 deleted |
| `a_held_files_own_file_back_where_the_server_keeps_it_is_the_file_come_home`, second half (no `server_home`) | edit | moved: its file is in another folder, so it is not a backup |

**The move-over row is AH in miniature.** When file 1 is sealed and file 2
is plain, today's reading sends the sealed bytes as file 2's version. That
test's comment records why the path rule was chosen: the move onto a name a
live record holds was refused by the server on every pass. The strong
reading plans file 2's delete in the same pass, which O2's planner and
executor rules sequence.

**New unit tests:**

- moved and edited, strong: moved and edited;
- a recycled id with a new birth: not the record's;
- a three-way rotation caught mid-way in one folder (a to tmp, b to a):
  two moves;
- a backup-by-rename across two folders: a move, not an edit;
- a provisional traded with a synced file: two moves;
- a server-deleted record's standing file: still its own;
- a hard-linked identity with no record at home: the tie rule;
- the weak path: every current pin, run with births hidden, unchanged.

**New scenario pins:**

- T1-C: a rescued provisional swapped with a synced file (held-out plain2
  75212 shape). Neither history takes the other's bytes.
- T1-D: a stranger swapped onto a record's path between plan and upload.
  Nothing is sent, and the next pass sends the right file.
- AH: a sealed file moved over a plain file's name. It is held, and nothing
  goes up plain.
- O2: a plain file moved over another's name, on the device that did it and
  on a peer.
  - At the end, `Report.txt` is the final name on both devices and on the
    server.
  - The replaced file is in the server's trash and the peer's local trash.
  - There are zero conflict names. The pin asserts the name, not only that
    the state settles.
  - A second pin queues the trash behind a fault, so the executor rule is
    what holds the move.
  - `MemFs::rename` refuses to rename over a file (`AlreadyExists`, jd-sim
    `vfs.rs` 1017), so these pins build the state as a remove plus a rename
    inside one scan window. A real disk replaces the file; the snapshot the
    scan reads is the same.
- O1: a held file renamed in place, with a stranger saved at its old name.
  The held record moves, the stranger is new, and nothing goes up plain.
- O6: a trade with a slot awaiting a download reads as two moves, not a
  backup save (plain2 75292's shape).
- A sealed file moved and edited inside its vault. Sealed, with one history.
- A sealed file moved and edited out of its vault. Held, with the edit
  waiting. This supersedes
  `a_sealed_file_moved_and_edited_in_one_pass_is_a_delete_and_a_creation`
  for strong identities; that pin stays with births hidden.

Every scenario pin that changes result is listed in the build report with
its old and new end state. Each one is a behaviour change, not a fixture
update.

## Found while building commit 2 (reviewed VALID with the freeze)

Each of these was found by a pin or a frozen seed, traced, and fixed at its
cause. They go beyond the approved text, so they are here before the patch
is frozen (R5).

- **A claimant is bound to its vault path.** A claimant (a record waiting for
  a vault key to send the file its source had) took its source's own file at
  the crossing, as the hand-over says. Carried back OUT of the vault, the
  claimant then followed the file out, and the hold never lapsed
  (`a_file_brought_back_out_of_a_vault_under_a_new_name_is_not_held_hostage`,
  `a_released_file_dragged_back_into_the_vault_is_held_again`). Rule: a
  claimant's own file standing anywhere but its path is its source's again.
  The source follows it (step 2), which is what ends the hold today, and the
  claimant reads its path like a record whose own file is gone.
  `KnownLocal::claimant_for` carries the source.
- **A file never uploaded follows its file** (T1-C). The pass's provisional
  branch planned each upload at the record's recorded path, so a provisional
  whose own file moved was planned and refused on every pass (frozen 111740
  never settled). It now takes its file's new place as its placement, and
  whether it goes up sealed is decided again there:
  - into a vault: it goes up sealed (or waits for a key), as a file saved
    there does today;
  - out of a vault the server has deleted: it goes up as the ordinary file it
    now is -- the complaint about that vault asks the user to move files out
    (`a_vault_trashed_while_the_guest_was_down_still_parks_and_the_complaint_clears`);
  - **out of a live vault, before it was ever sent: held.** It is the same
    file, by its identity, not a copy, so D1 holds it rather than the copy-out
    decision uploading it. Nothing is sent; the user is told (Q2 below).

  And an upload of a provisional that knows its own file, finding that file
  at none of its paths, stands down instead of forgetting the record: a
  provisional busy with a queued upload is not followed that pass, and
  forgotten there, its file was minted again as a new plain one and sent in
  the clear (`a_file_saved_in_a_vault_and_moved_out_before_it_was_sent_is_held`).
  The next scan follows it, or reads it gone and forgets it then. The harness
  declares such a held file to the convergence check
  (`scenario::held_never_sent`, counted `held_never_sent=` on the custody
  line): on the disk only, on purpose, and still required to stand at its
  path.
- **The merge hand-over is decided by its callers** (the reviewer's note 1,
  and one step further). A provisional merged by NAME into a real record
  (`merge_duplicate_files`) handed it a stranger saved at that name; the
  record was held (its own file waiting with a claimant in a vault), so the
  scan followed it to the stranger and the stranger went up as the held
  file's next version (`a_held_file_does_not_take_over_a_stranger_at_the_servers_new_path`).
  Rule: a held record never takes a merged file. The name-only merge is
  SKIPPED for a held record: the provisional stays a record of its own, and
  its upload lands beside the held name under a conflict name (performed
  without the hand-over, the file would be minted again by the next scan and
  folded again by the next pass). For any other record the name-only merge
  hands over only when the record owns no file; the two upload merges (the
  server says the bytes are that record's) hand over unless its own file
  still stands where it lives. `merge_file` takes `hand_over`. The pin asserts
  settlement with the stranger under a conflict name beside the held one.
- **The upload sends the record's own file** (T1-D's upload half, moved from
  commit 3). Among its candidate paths it takes the one holding the record's
  own file and skips one holding another; with its own file at none of them
  it stands down and the next scan decides. Without it, frozen 111740 on the
  new trajectory: a record's version upload sent another record's file that
  stood at its path, and took that file as its own (two owners, then a
  stranger read by path). Commit 3 keeps the rest: `move_local`'s check at
  `from`, and hashing and sending from one handle.
- **Every pairing binds the own file** (`ScanOutcome::bound`). Binding only
  on an edit or a move left a file restored from a backup with the same bytes
  unbound (read unchanged), and the folder scan then credited a folder with
  its one moved file's new id and none of its staying files
  (`a_restored_folder_with_a_vanished_id_is_read_by_its_contents`).
- **A held file moved and edited outside any vault follows, edit waiting;
  moved and edited back to exactly the server's slot is released there.** The
  held branch took only `Moved`, so `MovedAndEdited` was planned as a server
  move across the edge and refused on every pass. The third arm, moved and
  edited into a vault folder other than the server's slot, goes through D1's
  existing path: planned from the server's placement, a move into that
  folder, and the edit its next version, sealed
  (`a_held_file_moved_and_edited_into_another_vault_folder_is_released_with_the_edit`).
- **A sealed file leaving its vault holds its name from the start of the
  pass** (found by the commit 1 to commit 2 sweep: hostile2 74403, 74406,
  74414, the server holding one real name twice in a vault). The hold is
  written when the round reaches the record. A file arriving at its slot
  earlier in the same pass -- a new file saved there, or another record moved
  there -- found the name free (`clear_of_a_held_name` read only holds already
  written) and went up under it. Rule: the pass first collects the sealed
  records its scan finds outside their vault, and their names count as held.
  Commit 1 has the same gap; in these seeds its path rule read the drag-out
  and the arrival in different passes. Pins, both RED on commit 1 (two sealed
  files with the one name):
  `a_file_saved_where_a_sealed_file_left_in_the_same_pass_is_set_aside`,
  `a_held_file_moved_home_onto_a_name_leaving_in_the_same_pass_is_set_aside`.
- **A file never sent holds no name and is never merged** (hostile2 74403: a
  sealed file never sent, carried out of its vault, then another device sent
  a plain file under the name it stood at). The name merge folded it into
  that file; its own file was owned by nobody, minted again in the plain
  folder, and sent in the clear. Rule: a provisional held outside its vault
  is never merged by name (no server file is its upload), and naming does not
  judge it for a name (it is never uploaded under one; judged, it outranked
  the other file, which was parked as a duplicate for good). The other file
  lands, the held one is moved aside, and the next scan finds it by its own
  identity. On a weak volume the drag-out itself still sends it plain: the
  path rule forgets the provisional and mints the file where it lands (weak
  volumes keep today's reading; Q1). Pin, RED on commit 1 (which sends it
  plain at the drag-out) and RED on commit 2 with either rule out:
  `a_never_sent_file_moved_aside_by_a_download_stays_held`.
- **A record's server name is a name it holds, and the name merge never folds
  two files** (plain2 75237, 75292: green on commit 1, a swap-oracle fire on
  the draft). In both seeds a record's own file stood at the record's server
  name -- the server had moved it, or a move this device owed had landed --
  while this device's agreement still named the old path, and a file no
  record owned stood there. Step 2's backup exception (own file moved in its
  folder to a path no record holds, an unowned file at its path) read the
  unowned file as the record's edit: two files' bytes in one history. The
  unowned file got there because the name merge had folded its provisional
  into a real record that owned another file, leaving it nobody's. Two rules:
  - a record's server placement, where it differs from this disk's, is a
    path it holds (`KnownLocal::server_path`), so its own file standing there
    is a move, never a backup;
  - the name merge skips a provisional and a real record that each own a
    different strong file: two files, and the provisional's upload lands
    beside under a conflict name.

  Commit 1 has the same backup reading. Pins, each RED on commit 1 and RED
  on commit 2 with its rule out:
  `a_file_found_at_its_server_name_is_moved_and_the_file_at_its_old_name_is_new`,
  `a_new_file_at_a_name_a_record_reaches_later_is_not_folded_into_it`.
- **Two harness fixes** (hostile2 74424, whose engine behaviour was right).
  The convergence check dropped every disk path whose bytes match a parked
  server file before looking for held files, so a never-sent held file with
  the same bytes as a parked file read as missing; declared held paths are
  now exempt from that drop. And a harness write whose folder was already
  gone when it was attributed was recorded as belonging to no folder, which
  reads as known and lent nothing to a swap partner; it is now unknown.
- **Births hidden marks the simulated volume weak** (`MemFs::personality`),
  as a real volume's probe does, so a pin can run the path-first rules.
- **Pins:** the three held-file and rule-4 pins now run on disks without
  births (`a_vault_of_two_without_births`), with strong twins; the frozen
  seeds 111201, 111120 and `frozen_park_onto_a_strangers_name_seed` are green
  outright (AH fixed there) and lose their chain-oracle wrappers. Fifteen new
  scenario pins RED on commit 1 (two of them on the sealed oracle: the
  sealed file moved and edited out of its vault, and the sealed file moved
  over a plain file's name), among them T1-D's
  (`an_upload_sends_the_records_own_file_never_a_stranger_at_its_path`) and
  the held file moved and edited into another vault folder. O6's pin
  (`a_rename_onto_a_name_awaiting_its_download_is_a_move_not_a_backup`) is
  GREEN on commit 1, whose path rule has T1's own fix for that slot; it is
  RED on commit 2 with O6's clause removed, which is the neighbour it pins.
  The sweep reports `CONFLICT-NAMES` per seed (O2).

## The build, in three commits

1. **Disk and record, read by nothing.**
   - `jd_vfs` reports the birth time: a new field beside `file_id`, zero where
     the volume has none. On Windows it reads `FILE_ID_INFO`, or ReFS is
     weak (O4).
   - The personality's probe marks a volume weak by the rename-and-reread
     test (O4).
   - `MemFs` gives files a birth serial, as it already does directories:
     carried by a rename, new on create and copy, never recycled under
     `reuse_file_ids`. A switch hides births, for the weak control.
   - The store gains `own_file`, set and carried as above.

   Bar: traces byte-identical to `9b992a58` on every arm (R4), because
   nothing reads the field.
2. **The scan reads it.** The strong path as above, with the weak path
   left as today's code, untouched. It carries:
   - O2's planner and executor rules, since the move-over reading arrives
     with it;
   - O3's switch of every own-id reader to `own_file`, and the hand-over at
     adoption and merge;
   - `merge_file`'s hand-over decided at its callers (`execute.rs` 2188 and
     2338, `pass.rs` 4630), which can see the disk: only when the real
     entry owns no file, or its own file is not the one at its path.
     Commit 1 hands over whenever the real entry keeps an agreement, which
     would overwrite a real entry's own file with a case or normalization
     twin's. The build report says what state the real entry is in at each
     caller.

   Measured alone against commit 1.
3. **The executor checks it.** Measured alone against commit 1, then with
   commit 2 (R7: each with its neighbour removed). What is left for it once
   the upload's half moved into commit 2:
   - `move_local` checks the file at `from` is the record's own;
   - `trash_local` checks the same before it trashes: on a strong volume it
     trashes only the record's own file, and otherwise answers overtaken for
     the next scan to decide (kill2 75129: a swap in the pass put another
     record's file at the path, the trash took it, and that record read the
     unowned file left at its own path as its replacement);
   - hashing and sending read one handle.

**Simulator fidelity, known.** The simulated spool commit keeps the id
of a file it lands on ("same inode, new content", by design), where a real
rename from the spool gives the placed file the spool's inode; births follow
the simulated id, so a download over an existing file keeps its identity in
the simulator and gets a new one on disk. `renumber_every_id` gives every
file a new birth with its new id: a renumbered volume is a new identity,
which is right. The own file is read from the committed target either way.

The small items waiting on the reset's list (C9 part 2, the park gap, C13)
come after commit 3. Their measurements would otherwise need re-taking on a
new trajectory. The reviewer may reorder.

## The instrument

The four suites: jd-core, executor, scenarios, and zz_sweep's frozen seeds and
oracle pins, plus jd-vfs for the birth read.
Then the sweep on the named pair `9b992a58` → each commit:

- 160 seeds with swaps on;
- 160 with swaps off;
- held-out plain2, 100 seeds;
- and the same arms with births hidden.

Every oracle is named on the ARM line (R3): sealed (every file), chain,
custody, and converged or never-settled. The conflict-name count is reported
beside `held=` (O2). What they cannot see is stated in
the reset: a copy-out and an AH pairing both read `as_an_edit` to the sealed
oracle, and `pairs_sealed` is the chain oracle's blind share.

The bars:

- **Swaps off:** no seed goes green to red on any oracle.
- **Swaps on and held-out:** every green-to-red seed is traced to a named
  cause before the commit is proposed. Sealed and chain fires are expected
  to fall. Holds and converts are expected to rise, since swaps across a
  vault edge now reach the policy as moves; they are counted on the custody
  line, as `held=` is today.
- **Births hidden:** the scan is today's, so any change from `9b992a58` comes
  from the executor check alone (commit 3), and each one is traced.
