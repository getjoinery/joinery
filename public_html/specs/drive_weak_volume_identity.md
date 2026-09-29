# Drive sync: knowing which file is which on disks that cannot say

**Status: DESIGN SETTLED 2026-09-28, nothing built. Design reviewed by a5 on
2026-09-28: needed, and valid once its blocking points B1, B1a and B2 and the
layer 1 readers list are written in (they are, below). Owner accepted D1 to D3
the same day. Seeds traced 2026-09-28: rules X and U added, reviewed by a5 the same day
(valid with B3-B6, written in); Q4 decided (a). Facts F1, F2 and F4 traced on Linux, macOS and Windows (exFAT on Linux
untested). Next: the simulator's disk models.** Owner decision 2026-09-28: close this
limit rather than exempt it ("if we can diagnose the problem, explain it, and
there is a solution to it, we should do the work"). It blocks landing
`drive_file_ownership.md` under the reset's R10 bar (`drive_sync_reset.md`).

## The problem, plainly

Some disks cannot tell the sync engine which file is which. USB sticks and SD
cards (FAT32, exFAT) are the common ones. On them, when two files trade names
between two looks at the disk, the engine cannot tell that apart from an
editor saving a new version. It then records one file's contents as the next
version of the other.

Nothing is lost: both files reach the server with their latest contents. The
damage is to version history. One file's history holds the other file's past,
so restoring an old version can hand back the wrong file. In a vault the same
misreading can carry sealed bytes out, so it matters beyond history.

Disks that report a stable file id and a creation time (APFS, HFS+, NTFS, ext4,
btrfs, xfs v5) are not affected. `drive_file_identity.md` fixed them by reading
identity as the file id plus the birth time.

## Why it is hard

`drive_file_identity.md` calls a disk **weak** when the engine cannot trust
that pair, and reads weak disks by path. Its reasons:

- **Ids get reused.** A deleted file's number is handed to the next new file,
  so an id alone can point at a stranger (rule 4's doctrine: an id may fund
  order, never identity).
- **On FAT and exFAT the id is not an id.** The disk stores none, so each OS
  makes one up:
  - **Windows** (fastfat) and **macOS** (msdosfs) derive it from where the
    file's entry sits in its directory. A rename or move can change it, and a
    freed slot is reused first-fit within the directory. So the common editor
    save (rename the original away, write a new file at the old name) gives
    the new file the old file's id.
  - **Linux** (vfat, exfat) hands out a number when the file is loaded into
    memory, keeps it while cached, and hands out a fresh one after the file is
    evicted or the disk is remounted.
  (From kernel and driver sources as remembered, not traced; F1 checks.)
- **No birth, or an untrustworthy one**: xfs v4, some network and FUSE volumes.

It lists the cost under "What it does not fix": *"Weak volumes keep today's
reading, the scan's path-first rules, including its leaks under swaps."* This
spec is about removing that sentence.

## The engine sees two kinds of disk; there are three (B1, B1a)

`Personality.stable_file_identity` is one yes/no. Everything below "strong"
is one class, and the weak path still reads ids in many places. That is
tolerable where ids are stable and only reused after a delete. It is actively
wrong where ids move with renames and are reused first-fit.

**Three classes:**

| Class | Examples | What an id means |
|---|---|---|
| strong | APFS, HFS+, NTFS, ext4, btrfs, xfs v5 | identity (with birth) |
| stable-id weak | xfs v4, some network/FUSE | order only; reused after delete |
| positional | FAT, exFAT on every OS | nothing; do not read it |

**The probe cannot find Linux FAT (B1a).** The probe renames a file to a
longer name and checks that id and birth survive. On Linux the FAT id survives
a rename (it is only renumbered on eviction or remount). If Linux also reports
the FAT creation time as the birth (believed, kernel version not traced: F1),
a stick mounted on Linux passes the probe and is read **strong**. After a
remount every id is new, every record's file is "gone", each is rebound by
path, and a swap made while unmounted mixes histories on a disk the engine
trusts. `ids_not_unique_on_this_volume` checks only Windows ReFS; on Unix
nothing names the filesystem.

**Fix:** name the filesystem type as well as probing:
- Linux: `statfs` `f_type` = `MSDOS_SUPER_MAGIC` or `EXFAT_SUPER_MAGIC`;
- macOS: `f_fstypename` = `msdos` or `exfat`;
- Windows: `GetVolumeInformation` file system name = `FAT`, `FAT32` or
  `exFAT`.

A named FAT/exFAT volume is positional whatever the probe says. The probe
stays for the rest. The identity spec's O4 ("asked, not named") cannot answer
a question the probe cannot provoke, such as eviction.

**Every id reader on the weak path gets a stated fate on positional disks.**
Known so far (a5, from the committed code and the fo3g diff):
- `at_home` prefers the inode when it is nonzero (scan.rs, `pair_with`);
- `arrived_by_a_trade`: `standing` and `by_inode`;
- the held-record inode skip in `pair_with`;
- rule 2's same-inode-plus-content test;
- file ownership's 2b: `never_sent_own_id`, `bind_never_sent_own_ids`, and
  the upload's own-id check;
- the merge guard in `merge_duplicate_files` (fo3g);
- `trusted_own_file` in the executor.

**How positional is built:** the disk layer (`OsVfs`) reports every file and
folder id, and every birth, as 0 on a volume named FAT or exFAT. The engine
already reads 0 as "no identity" in most places, so ids are unread everywhere
at once, and 2b switches itself off (`never_sent_own_id` needs a nonzero id).
The type check goes in `real.rs`, called from the probe:
- Linux: `statfs` `f_type` 0x4d44 or 0x2011BAB0;
- macOS: `f_fstypename` `msdos` or `exfat`;
- Windows: extend the existing `GetVolumeInformationByHandleW` ReFS check to
  `FAT`, `FAT32` and `exFAT`.

The dependencies are already there. The simulator does the same zeroing when
its disk reports the positional class.

**Inventory with fates (2026-09-28, against the fo3g tree):** every id read in
jd-core and jd-vfs, checked for "every id is 0". 32 are safe as they stand
and the strong-only ones are unaffected. These need changing:

1. **The hash cache** (`store.rs` `cache_hash`/`cached_hash`; read by the
   scan and five executor sites). B7 above. On positional volumes, key it by
   path, size and mtime, and trust a row only while the watcher reports that
   path untouched since it was cached. An engine start or any watcher loss
   rehashes everything once. Never write a row with id 0 under the id key.
2. **`entity_for_file_id`** has no guard for 0, so it returns an arbitrary
   entity (read by the folder-trash rescue, `is_on_the_server`). Return None
   for 0, as `every_holder_of` and `owners_of_file` already do.
3. **`Fingerprint::unchanged_from`** reduces to size plus a 2-second mtime at
   id 0. Readers: fo3g's trash check, the folder-trash rescue (whose
   `agreed_here` table is keyed by id and collapses to one key), and park.
   Answer false when either id is 0, so callers compare bytes. `agreed_here`
   skips 0, and `is_on_the_server` compares the hash with `synced_content`.
4. **The upload's settle check** (`fp == fingerprint` after sending) is size
   plus mtime at id 0. Rule U: settle only when the sent hash equals the file.
5. **Owner lookups by identity** (`owners_of_file`, `Entry::owns`,
   `the_owner_follows_its_file`) find nobody at id 0. Readers:
   - make_room: a synced record's own file read as disposable;
   - the owner following its file aside: it doesn't, so the file is minted
     new;
   - a download taking over a never-sent record at its slot;
   - kill recovery finding the copy's record;
   - fo3g's `record_a_file_found_landing`, which returns at id 0.

   Fix: one positional lookup, "the live file records placed at this path,
   confirmed by bytes (agreed or last-seen)". This is the executor's half of
   layer 1. The landing record is minted with `last_seen_sha` instead of an
   id.
6. **The scan's `standing`** finds nothing at id 0, so every swap reads as an
   edit. Layer 1 and rule X.
7. **The merge guard** is skipped at id 0 (class M). Layer 1.
8. **Upload, local move and park** have no identity check on weak volumes.
   Rule U, whose stranger check is extended to local renames (`move_local`,
   park), since these can carry a swapped-in file.
9. **The held-record skip** (`scan.rs`) is `0 != 0`. Not reachable under D3,
   but guarded anyway.

Folder following by ids is safe but blind at 0, so folder renames rest on
contents and paths, as the existing "vanished id" scenario already covers.

**The hash cache is keyed by the id (B7, found 2026-09-28).** `cached_hash`
looks a file's hash up by (file id, size, mtime). On a positional disk the
id moves between files: a swap through a temporary name traded ids on Windows
exFAT. FAT's mtime is 2-second, so two files of one size saved in the same two
seconds can then be handed each other's hash. That is a wrong content reading
with no swap-reading rule involved, and it can happen on Windows sticks today.
Reading ids as 0 does not fix it; it widens it. The cache on positional disks
needs its own key (in the inventory).

**2b is off on positional disks.** File ownership's 2b follows a never-sent
record by its file id. On a positional disk the editor-save shape gives the
new file the old id. The never-sent record then reads the stranger as its own
file at home (rule 1), and its real file is minted as new. If the record was
held (a sealed file dragged out), the hold sits on the stranger and the sealed
file is minted plain: a leak, not only history. On positional disks 2b is
replaced by layer 1: a never-sent record's minted bytes are its last-seen
bytes, so it follows by content. 2b stays on stable-id weak disks, where it
was measured.

## Evidence (sweep, 2026-09-27, 420 seeds, births hidden)

| Build | Seeds where one file's history holds both sides of a swap | Sealed-leak seeds |
|---|---|---|
| committed (79b1addb + c2fb5432) | 74 | 73 |
| file-ownership candidate (scratch fo3g) | 13 | 13 |

The 13 that remain, traced 2026-09-28 (all 13 also fail on the committed
code except where noted). **None is a residual shape (i) or (ii).** Three
classes are not in the design above, so the design grew (the rules marked NEW
below):

| Class | Seeds | What happens | Where it is fixed |
|---|---|---|---|
| M: the name merge folds a never-sent record into a synced one whose file is elsewhere | plain2 75298, 75237, 75201 | two records claim one name; the merge guard sees the never-sent record's own id at the name and folds it in; the survivor reads the stranger at its path as its edit | layer 1 in the merge guard: the survivor's last-seen bytes must stand at the shared name |
| X: a synced record's unedited file stands in another folder, and a file no record holds stands at its path | plat3 75408, 75416, 75423; the second step of every E below | the synced branch of `arrived_by_a_trade` counts a trade only when the file at the path is some record's; an unrecorded stranger (never scanned, or left unrecorded by E1) reads as an edit | NEW rule X (below) |
| U: an upload sends a file other than the one the scan read | kill2 75100 (red on committed with a sealed leak; fo3g adds the history mixing), 75122 | a swap between the scan and the upload; a synced record on a weak disk has no identity check in `upload` (`trusted_own_file` is None; fo3g's own-id check covers never-sent records only), so the version or the upload-as-new fallback takes another file's bytes | NEW rule U (below) |
| E1: a record is dropped while its file still stands | kill2 75112, 75116, 75119 (Convert arm); hostile2 74414 (folder forget); plat3 75425 (held-name rename) | a file with no record, which rule X then meets | `drive_file_ownership.md` (every file has one record), not this spec |

Also found: **a file saved in a vault and swapped out before any scan**
(plat3 75423 leak 22a1667c, 75425 leak 552fd16c) is minted plain on every disk
kind, births shown too. No scan can know it was ever in the vault. Only layer 2
could. Owner chose to accept it (Q4 option a).

**The sweep does not model FAT.** Its births-hidden mode keeps file ids across
renames and reuses them after deletes: the stable-id weak class. Nothing in the
sweep produces positional ids or Linux's renumber-on-remount. The numbers above
understate the problem on the most common weak disk.

## What each kind of weak disk can still tell us

| Evidence | Stable-id weak | Positional (FAT / exFAT) | Survives a swap? |
|---|---|---|---|
| File id | yes, reused after delete | no | stable-id: yes |
| Birth / creation time | no | FAT has one, but see tunnelling | no on Windows |
| Content hash (bytes) | yes | yes | yes, if the file was not edited |
| Size, mtime | yes | yes | yes (travel with the file) |
| Watcher rename events | inotify, Windows yes; macOS no (see layer 2) | same | while the engine runs and no events drop |

**Tunnelling (Windows).** When a name is vacated and another file takes it
within 15 seconds, Windows copies the old file's creation time onto the
newcomer, on FAT as on NTFS, so that safe-saves keep their creation time. A
swap through a temporary name therefore also swaps creation times. On Windows a
FAT birth cannot detect a swap.

## Design

Build layers 1 and 4 and the three-class split first, measure, and build
layer 2 only if fires remain that are not the residual (a5: pare down; layer 2
is the most code, on the least-tested surface, absent on macOS, and buys only
edit-then-swap while running).

### 1. Every record remembers the bytes it last saw

Today only agreed bytes are remembered (`synced_content`, which becomes
`KnownLocal.sha256` in pass.rs's `known_local` build). A record never sent has
none, and a synced record edited since the last agreement has stale ones. So
the scan's content test for a trade misses any swap involving a new or edited
file.

Proposal: each file record keeps **the hash of its own file as the last
completed scan saw it** (`last_seen_sha`). The scan writes it for every record
it pairs. It is used as another content owner: "is the file at my path another
record's, by its last-seen bytes?" and "do my last-seen bytes stand elsewhere?"

**Cost.** The scan already hashes every observed file (pass.rs fills
`ObservedFile.sha256` for each, from the hash cache or the disk). But the hash
cache is keyed by file id, size and mtime (store.rs `cached_hash`), so on a
positional disk every rename is a cache miss and a full re-hash of that file.
Bounded, not free. Also on positional volumes: the round's gone check asks
`owners_here` at each provisional's path, which hashes that file once per
pass. That is bounded by the number of provisionals.

**What the path-keyed cache rests on (B6, a5 2026-09-29).** On a positional
disk the scan's hash cache is kept by path, and a row is only as good as the
watcher that forgets it. A real watcher that drops one event without
reporting overflow leaves a row standing until that file's size or mtime
changes. A swap of two same-sized files within one two-second tick, whose
event was dropped, is read as nothing changed until either file is touched.
An upload planned in between is refused by rule U, because it hashes what it
opens. The daemon forgets everything only on overflow, on start, or with no
watcher. The simulator's watcher is perfect, so the sweep cannot show this.

**Readers** (each gets a pin):
- `arrived_by_a_trade`: `by_content` and the trade test;
- `at_home` on positional disks, in place of the inode;
- rule 3 (pairing by content);
- the name merge's guard: on positional disks, "the provisional's own id
  stands at the shared name" becomes "its last-seen bytes stand there". This is
  where 75298 fails;
- 2b's replacement for never-sent records on positional disks (above);
- the executor: rule U (below). The trash check (`its_agreed_file_is_here`)
  already compares bytes.

**Edges:**
- Two records with the same last-seen bytes: `by_content` requires a single
  owner, so it is blind there. A copy plus a swap with a third file falls to
  the residual.
- An empty file hashes the same as every empty file; `EMPTY_SHA256` stays
  excluded.
- A synced record edited but not yet uploaded has agreed bytes A and last-seen
  A'. Both go in the content-owner table, both pointing at it.

It catches any swap where neither file was edited between the two looks. It
cannot catch a file edited and then swapped between two looks.

### 2. Rename events from the watcher (built last, only if needed)

`jd-vfs/src/watch.rs` subscribes to the OS through `notify` 6.1.1. Its
callback marks each path dirty and drops which name went to which. What
notify 6.1.1 gives (traced in the crate source by a5):
- **inotify:** pairs `MOVED_FROM`/`MOVED_TO` by cookie into one event with
  both paths, when the two halves arrive together. A move out of the root stays
  a lone From.
- **Windows:** From and To as two separate events. We would pair them by
  adjacency, which ReadDirectoryChangesW promises, in our own code.
- **macOS (FSEvents):** one path per event and no pairing ("FSEvents provides
  no mechanism to associate the old and new sides"). Pairing on macOS needs our
  own FSEvents binding with extended data (it gives inodes, which are useless on
  FAT) or a heuristic. As pinned, layer 2 does not exist on macOS.

Proposal: a **rename journal** of paired `from -> to` events since the last
scan, in order. The scan reads it only if all of these hold:
- the watcher reported no loss for the window;
- **replay-consistency:** replaying the journal over the last scan's paths
  predicts exactly the path set the walk found. Any mismatch voids it. This
  catches Linux `RENAME_EXCHANGE` (two cookie pairs, a->b and b->a, which a
  naive chain reads as nothing moved) and rename-over (a->b, with the replaced
  file vanishing without a delete event);
- no event is timestamped inside the walk's own window (the walk saw a mix);
- folder renames are applied to every path under the folder.

### 3. On stable-id weak disks, ids help only as a tie-break

On a stable-id weak disk, an id is reused only after its file is deleted.
File ownership's 2b uses this for never-sent records. Extending it to synced
records is what `drive_file_identity.md` rejected, because a wrong match puts a
stranger's bytes in a history. Proposal: do not extend it. On these disks,
ids are only a tie-break between candidates that layer 1 or layer 2 already
names. On positional disks ids are not read at all (B1).

### NEW rule X: an unedited file elsewhere is followed, unless it is the backup shape

When a synced record's own file stands unedited at another path, and the file
at its path is held by no record, the record follows its file: a move, and the
file at its path is new. "Its own file" is found by id on a stable-id disk,
and by last-seen or agreed bytes on any weak disk.

This is not new behaviour. `pair_files` step 2 already does it on strong
disks by identity (scan.rs, step 2). X makes weak disks read the same way, so
the cost below is already paid on every strong disk today.

**X carries all of step 2's clauses (B3, a5 2026-09-28).** The strong step 2
and fo3g's never-sent `a_backup` test have already drifted apart, so X takes
their union. It is the backup shape, and stays an edit with D2's mark, only
when all of these hold:
- the record is not held;
- the own file stands in the same folder;
- the backup's path is held by no record, is not in the held paths, and has
  nothing awaiting there;
- nothing is awaiting at the record's own path (a file where a download is
  due is a landing save, not a backup's save);
- the file at the record's path is unclaimed and unowned.

Otherwise X follows the file. The pin is one fixture run on both disk kinds,
asserting that the strong and weak readings agree, so they cannot drift again.
The never-sent `a_backup` test is replaced by X's clauses.

**X keeps the existing guards (B4):**
- **Single owner:** found by bytes, X follows only when those bytes belong to
  exactly one record. Two records sharing bytes (a copy) leave it blind:
  otherwise X would claim the copy's file.
- **Twin at home:** if the file elsewhere stands at a path whose record is at
  home with it, X does not follow.
- **Held records** reach X only through `home_again` (`server_home`). X never
  turns a held file back at its server home into a plain move.
- **Awaiting bytes** at the elsewhere path is already a trade
  (`mine_on_anothers_path`); X adds nothing there.

Safe by D1: the followed file is unedited, so following it adds nothing to its
history (for an edited, not-yet-uploaded record, A then A' is the right
chain), and the file at the path goes up as new. The cost: an editor that
keeps backups in a different folder inside the sync root (Emacs
`backup-directory-alist`, vim `backupdir` pointed there) saves as "move plus
new file" while the engine is not watching. Its history follows the backup,
not the saved file. No bytes are lost. This is rare: both editors keep
backups beside the file by default.

### NEW rule U: the executor acts only on the file the scan read

**Upload: what is sent must be what was read.**
- On a weak disk, `upload` (a version, or upload-as-new and every fallback
  candidate) compares the hash of the handle it opened (it already hashes it
  before sending) with the hash the plan was made from. If they differ, the op
  is overtaken and the next scan reads the file as it stands. This covers
  75100's version and 75122's fallback candidate.
- **No op carries the planned hash today (B5).** `UploadVersion` serialises
  `{}` and `UploadAsNew` only the placement. The planned sha256 goes into the
  op's params at plan time, so the op says what it was made from across
  retries.
- Overtaken does not loop. Its cost: a file edited between every scan and its
  upload (autosave while the user types) is not uploaded until a pass finds it
  quiet. That is bounded by typing pauses and loses nothing.

**Move: refuse only a stranger, never an edit (B5).**
- A move changes only placement, and a user edit between the scan and the op
  must not overtake it. The executor's `completed_here` arm already says so
  ("this file's own inode, never its bytes"). Bytes-equal would turn an
  ordinary move-then-edit on a positional disk into delete plus create, and
  lose the history that today is kept.
- So on weak disks `move_remote` refuses only when the file at the
  destination is identifiably another record's: its bytes are another
  record's last-seen or agreed bytes, with a single owner (the table X
  reads). On a stable-id disk it also refuses when its id is another record's
  own id. This covers 75201's step: the swapped-back file at the destination
  was another record's known body.

A strong disk keeps its identity check (`trusted_own_file`). **Rule U is a
prerequisite for landing file ownership (fo3g), not a later step:** kill2
75100 is red on the committed code (a sealed leak) but fo3g adds history
mixing to it, through exactly this upload.

### 4. What is left, and the rules for it (B2)

After layers 1 to 3 the residual is a file **edited and then swapped with no
watcher evidence**. It has two shapes.

**(i) The backup shape.** The record's previous file still stands beside it in
the same folder at a name nobody holds, and an unowned file stands at its path.
This is how Emacs, vim, and Word's temporary names save. It cannot cross a
vault edge (the rule needs the same folder), so the harm is to history only.

**(ii) The vanished shape.** The record's file is found nowhere (it was edited,
so its bytes match nothing), and a stranger stands at its path.
`arrived_by_a_trade` returns false because nothing of the record's stands
elsewhere, and rule 1 pairs by path as an edit. This is the write-a-temp-file
then rename-over save used by Cocoa, LibreOffice and most editors, which leave
nothing behind. It **must** keep reading as an edit, or every save on a stick
loses its history. Its harm: in a swap where the carried file was edited, a
sealed record takes the plain stranger (harmless, it gets sealed), and the
sealed file's edited bytes are minted plain where they landed. **That is a
leak, and no rule can fix it without identity.** D2's "move plus new file"
does not exist for this shape: nobody knows where the file went.

## The simulator has to model FAT first

The sweep needs disks that behave like the real ones:
- **Positional (Windows/macOS):** the id changes on every rename and move;
  freed slots are reused **first-fit within the directory**, so a new file at
  an old name gets the old id. A global stack of freed ids does not produce
  that, and it is the common case.
- **Linux FAT:** ids stable within a mount, all new after a remount or
  eviction (`renumber_every_id`), births kept. This is a fourth behaviour.
- **Optionally Windows tunnelling:** creation times carried onto a name taken
  again within 15 s.
- A sweep mode for each beside `HIDEBIRTHS` (for example `FATDISK=win` and
  `FATDISK=linux`). Each build is measured on strong, stable-id weak, and both
  positional behaviours.
- A positional-id variant of the scan unit fixtures.

The sim's disk kinds must map to engine classes. Today they cannot, because
the engine has one bool.

## Facts to establish

- **F1:** Per OS, what does a FAT/exFAT volume report?
  - Linux: is the id stable across a rename; is it renumbered after a
    remount; does `statx` report a btime, and from which kernel?
  - Windows and macOS: does the longer-name rename move the id?
  - Does the filesystem-type check name the volume on each?

  Settle this before the FAT sim, because the sim must model what the engine
  sees.

  **Linux FAT32, traced 2026-09-28** (kernel 6.8, loop image, `f1_linux.sh`
  in the scratchpad):
  - `statfs` names it: type `msdos`, magic `0x4d44` (`MSDOS_SUPER_MAGIC`).
  - `statx` reports a birth (the FAT creation time).
  - The id survives a rename, the probe's longer-name rename, and a swap
    through a temporary name (each file keeps its id). **So the probe reads
    Linux FAT as strong. B1a is confirmed.**
  - A backup-by-rename save gives the new file a fresh id (no reuse while
    mounted).
  - Dropping the inode cache did not renumber here (it may not have evicted).
  - A remount renumbered every file (3, 4, 5 became 9, 10, 11); the births
    were unchanged.

  Linux FAT is therefore "stable within a mount, all new after a remount",
  as a5 said. The filesystem-type check names it. exFAT on Linux is not
  tested yet: no `mkfs.exfat` on the dev box.

  **macOS 15.5, traced 2026-09-28** (hdiutil images; macOS 15 mounts both
  through FSKit, not the old kernel msdosfs):
  - `mount` names them `msdos` and `exfat`. The birth is reported on both.
  - **FAT32:** the id is the file's first data cluster. It survives a rename,
    a move to another folder, a swap and a remount. It **changes on an
    ordinary in-place save** (truncate and write reallocates), and when
    emptied. An empty file has a temporary id (counting down from `u64::MAX`)
    that changes on remount. A write-temp-rename save gives a new id.
  - **exFAT:** ids are temporary (counting down from `u64::MAX`), stable while
    mounted through rename, move and swap, and all new after a remount (like
    Linux FAT).
  - **The probe reads both as strong** (the id survives the rename and a
    birth is there). Naming the filesystem type is needed on macOS as well,
    and the "positional" class must mean "ids are not read", whatever the
    reason they move: a directory slot (Windows, per the docs), a data
    cluster (macOS FAT32), or a mount session (Linux FAT, macOS exFAT).
  - The earlier belief that macOS derives FAT ids from the directory-entry
    position was wrong for macOS 15. The sim's positional disk must model
    the harmful behaviours seen: ids moving on rewrite (macOS FAT32), ids
    renumbered on remount (Linux FAT, macOS exFAT), and ids moving with the
    directory entry (Windows).

  **Windows 11 (ARM64 VM), traced 2026-09-28** (diskpart VHDs, `fsutil file
  queryfileid`):
  - `Get-Volume` names them `FAT32` and `exFAT`.
  - **The id is the directory entry's position.** It survives a rename to a
    name of similar length and a remount. It changes when the entry has to
    move: the probe's longer-name rename, a move into another folder, and a
    swap through a temporary name (on exFAT the two files traded ids with the
    names). A new file took a fresh slot in this short test; first-fit reuse
    of a freed slot was not provoked.
  - **The probe catches it** (the longer-name rename moves the id), so Windows
    reads FAT and exFAT as weak today.
  - **F4, tunnelling: confirmed on both.** After a swap through a temporary
    name, each name kept its old creation time, so the creation times did not
    travel with the files. A backup-by-rename save's new file inherited the
    old file's creation time, and so did a file renamed onto a name vacated a
    moment before. On Windows a FAT birth tells nothing about which file is
    which.
- **F2:** Does macOS `msdosfs` report `st_birthtime`? **Yes, on macOS 15 for FAT32 and exFAT (traced 2026-09-28).**
- **F3:** How do we pair renames on macOS, if layer 2 is built (own FSEvents
  binding, or a heuristic)? Only if layer 2 is built.
- **F4:** On Windows FAT, does tunnelling fire for a swap through a temporary
  name, as documented? **Yes, on FAT32 and exFAT (traced 2026-09-28).**

Disk images can answer F1, F2 and F4 without a physical stick: a loop-mounted
vfat/exfat image on Linux (needs root to mount), `hdiutil` on the Mac mini, a
FAT-formatted VHD on the Windows VM.

## Decisions (owner, 2026-09-28: D1, D2 and D3 accepted as recommended)

- **D1: what may say "this is the same file".**
  - Last-seen bytes may, because an unedited file standing elsewhere adds a
    version equal to what the history already holds, so nothing can mix.
    Agreed bytes already do this (rule 3). The single-owner and non-empty rules
    stay.
  - Rename events may only when the journal replays consistently with the walk.
    Otherwise they can only stop an edit reading.

  Accepted.
- **D2: the residual rule.**
  - Shape (i): read it as a save, and mark the version as made while the engine
    could not tell a save from a swap, so a restore can warn. The mark is
    defined narrowly: paired by path while the record's previous file still
    stands beside it at an unheld name.
  - Shape (ii): stays an unmarked edit. It is every safe-save.

  Pro: editor histories keep working on sticks, and the doubtful case is
  visible. Con: a real swap of shape (i) still mixes a history, marked. The
  alternative ("move plus new file") never mixes, but it moves a real editor's
  history onto its backup file, and it cannot apply to shape (ii) at all.
  Accepted: save with the mark.
- **D3: vaults on weak disks.** Refuse to sync a vault folder on a positional
  or stable-id weak disk, whether or not this spec lands. Shape (ii) leaks
  across the vault edge whatever D2 says. With the filesystem-type check the
  refusal is predictable and explainable ("this disk cannot keep a file's
  identity"). This is the identity spec's Q1 recommendation, now with a
  reason it cannot be avoided.
  Accepted: refuse.

- **Q4 (owner, 2026-09-28: option a): a file saved in a vault and moved out
  before the engine looked.** No scan can know it was in the vault. Only a rename journal (layer
  2) running at the time could. It leaks on every disk kind, strong included.
  Options: (a) accept it: the user's last act put the file in a plain folder,
  so the engine uploads what it sees; the sweep oracle is taught that a file
  never observed in a vault is not "sealed"; (b) build layer 2 for it, which
  covers it only while the engine runs and not at all on macOS as pinned.
  Chosen: (a). The sweep's sealed oracle counts a body as sealed on a device
  once either of these holds (B6, a5 2026-09-28):
  - a completed scan on that device saw it in a vault folder; or
  - the engine on that device held a record placing it in a vault, whether
    minted by any scan (complete or killed mid-pass, since records persist
    per entry) or placed there by a download.

  The union matters in both directions. A record alone misses a file a scan
  saw in a vault folder that was not yet tracked. A completed scan alone
  excuses a kill after the record was written, and a download swapped out
  before the next scan. The harness reads each device's store as the
  ownership oracle does, or the engine journals a sealed-observed line at
  `put_entry` and at download placement. Pins, births shown and hidden: a
  file written in a vault and swapped out before any scan or record is not a
  leak; the same file swapped out after a record or a completed scan still
  is.

## D3 as built behaviour (proposed 2026-09-28; revised with a5's B5, 2026-09-29; built in scratch wf5 2026-09-29)

The owner chose to refuse vaults on weak disks. What "refuse" does, case by
case. The aim: no sealed bytes are ever in a folder the engine syncs on a
weak disk, so no misreading can carry them out.

- **A vault on the server** is not created on this disk, and nothing in it is
  downloaded. Its top folder is parked `OutOfScope`, with one issue per vault
  (`vault_on_a_weak_drive`). As raised: "{name} is a vault. This drive
  cannot keep track of which file is which (FAT, exFAT, or another kind that
  does not keep file identities), so it cannot keep a vault's files safe, and
  vaults are not synced on it. They stay on the server and on your other
  devices. Files you save into it here are not uploaded; files you move out
  of it are synced as ordinary files, except sealed files, which stay held."
- **Records already under a vault when the volume is first read weak** (d):
  parked with it. They are never read as deleted or moved, and never
  uploaded.
- **A local folder already at a vault's path** (the sync root copied onto a
  stick, or an upgrade reclassified the volume) is left as it is: nothing in
  it is uploaded, deleted, trashed or moved. The walk still observes under
  it, as it does under a parked stand-in today, so its files are known (c).
  A file found there with no record is minted as an `OutOfScope`
  provisional that never uploads, so a later swap out of it is caught by its
  bytes. The issue adds: "The copy of {name} on this drive is no longer
  synced; files you move out of it are synced as ordinary files, except
  sealed files, which stay held."
- **Files dragged out of the parked vault folder into the synced tree** (b):
  - A sealed record's file (its plaintext, known by its bytes) reads as
    moved out of its vault. The round holds it (D1) and it is never sent.
    The hold stands if it is later edited, because a held record's path
    stays its own on a positional disk (see the next bullet).
  - A file with no record is a new plain file (Q4).
- **A sealed file held outside its vault (D1)** keeps its hold on a weak
  disk (a). With no ids the path is all the held record has, so a file at
  its held path is its edit, never a stranger. A source a claimant holds
  is the other way round: its path proves nothing while its file is held,
  so a file at its old name is new, never its edit. Read as its edit, the
  hold lapsed and the stranger went up as the source's version. Both are
  pinned. The cost is
  an over-hold: after the user deletes the held plaintext and saves an
  unrelated file under the same name, that file is held too, and never sent
  until moved. That is the safe direction.
- **The user makes a folder with a vault's name:** it is the vault's stand-in,
  as on a device with no key. What is saved into it waits and is never sent,
  and goes up sealed when the park lifts
  (`a_vault_is_not_synced_on_a_usb_stick_until_the_disk_is_strong_again`).
  It is never read as a plain folder, which would publish what the user
  meant to be private.
- **A plain file dragged into a parked vault folder** (c): layer 1 reads it
  as moved into the parked folder by its bytes. A new round arm sets it
  `OutOfScope` and keeps its `synced_placement`: no trash on the server, no
  name held. `OutOfScope` already means "holds no local presence, competes
  for no name" (naming, and the executor's park); the arm is new code.
- **The disk turning strong again** (the sync root moved back): the park
  lifts. The vault syncs as on any strong disk. `OutOfScope` records under
  it go through the Convert arm: a claimant, a sealed upload, then the
  source's trash.

The sweep runs its vault seeds on every weak disk kind, and their G->R
count (a5, 2026-09-29). The convergence oracle knows the park: a parked
vault at its path is dropped from both sides, earned by the weak volume and
the park's own issue. Everything that leaves the park is judged as ever.

**The user moving a parked vault (a5's ruling, 2026-09-29).** The park
follows its directory; it never becomes a plain folder. Local placement
only: nothing is asked of the server, and it stays parked.
- Where directories keep identity (stable-id weak), by that identity: the
  folder scan finds the vault's directory as for any vault, and the round
  writes the move locally instead of skipping it.
- On a positional disk, by contents, before the folders are read: a
  directory holding the vault's sealed files at their own relative paths, by
  the bytes each record last saw, is the vault. Exactly one such directory,
  none at the vault's own path, and no other live record's files standing in
  it by their bytes. A followed vault keeps a contested path over a record
  that only names it.
- Edge cases: (i) the sealed files pair nowhere (the vault emptied, or its
  only sealed file carried out in the same breath): nothing to follow, and
  the directory at the new name reads as plain. (ii) Two directories carry
  the vault's bytes (a copy): no follow; the copy's sealed files pair as
  moved out and are held. (iii) The vault's old path now holds something
  else: that is not the vault; a folder of the vault's name there is the
  stand-in rule.
- The park is decided before the folders are read, so the first pass that
  finds the volume weak already follows by contents. Parked after the
  folder scan, that pass read a same-breath ring rotation by name (FAT
  hostile2 74400).
- A file moved from one place under a park to another follows the same way.

**As built (scratch wf5, 2026-09-29).**
- `park_vaults_on_a_weak_volume` runs each pass beside the existing
  stand-in park. On a weak volume, each vault's top folder is parked
  `OutOfScope`, with one issue per vault (`vault_on_a_weak_drive`, whose
  wording is above). The issue also marks the park as D3's.
- Everything under it waits through the existing shadow: nothing is
  downloaded or created, nothing under it is read as deleted or moved, and a
  file saved into it gets a record that is never sent.
- Held files (D1) are not under the vault, so they keep their holds.
- A sealed record under the park is still paired by the scan: its
  `last_seen_sha` is written, and layer 1 finds its bytes. Only the round
  skips it. That is what the exception below reads.
- One exception to the shadow: a sealed file the user carries out of the
  parked vault is let through the round, to be held (case (b)). Skipped with
  its vault, its move was never read, and the file was minted plain.
- On a volume read strong again, the park lifts: the status goes back to
  Synced or PendingDownload, and the issue is dismissed.
- Pins:
  - `a_vault_is_not_synced_on_a_usb_stick_until_the_disk_is_strong_again`
    (not brought down, told once, a save waits, the lift brings it down and
    sends the save sealed);
  - `a_sealed_file_moved_and_edited_in_one_pass_on_a_weak_disk_stays_parked`
    (case (d));
  - `a_held_file_edited_on_a_usb_stick_stays_held` (the drag-out exception).

  Each is red under its own knockout. By case:
  - (a), holds kept, and (b), a sealed file dragged out: the stick pin
    `a_held_file_edited_on_a_usb_stick_stays_held`;
  - (c), a plain file dragged in: the existing crossing-arm claimant pins
    (the claimant waits in the shadow);
  - (d), records under the vault on a disk turned weak: `..._stays_parked`;
  - the server side and the lift: `a_vault_is_not_synced_on_a_usb_stick_...`.
- The scenario helper `a_vault_of_two_on` now brings the vault down while the
  disk is strong and then hides births, because a weak disk only ever holds
  sealed files from before it was read weak.

Different from the text above: a plain file dragged into a parked vault goes
through the existing crossing arm (a claimant that never sends while the
vault is parked, with the plain source held on the server), not through a
new `OutOfScope` arm. The outcome is the same: no trash, no upload, and the
Convert flow on the lift.

**Found by the post-D3 sweep (2026-09-29), fixed and pinned.**
- Folder moves, strong disks included (the path map re-pointed by directory
  identity exposed them: files read correctly as in a folder the folder scan
  then read as deleted):
  - an open chain of folder moves is read by identity, its end on an
    untracked directory corroborated by its own files, wholesale (kill2
    75123; it also resolves a swap whose members both named one path and
    were both evicted from it, kill2 75110);
  - the ring and chain walks include displaced paths, a shell carrying
    another tracked folder's own id (kill2 75100);
  - a folder parked under a scratch local name because its directory stood
    elsewhere gets its name back when the directory stands at its agreed
    placement again (hostile2 74414, never settled).
- Held files (D1):
  - a held file set aside for a download follows to its aside on a disk
    with no directory ids, by the records' paths (FAT hostile2 74403);
  - a created file carrying a held record's bytes (last seen or agreed)
    waits unsent on a positional disk, as the id case does: a held file
    renamed while a new file took its name went up plain;
  - a held file placed in a plain folder the server deleted follows its file
    if it stands outside the folder, and otherwise keeps the folder's record,
    as a kept sealed child does; the folder's subtree, read by the server's
    parents, never listed it (hidden kill2 75129).
- The harness: the sealed oracle credits what the walk itself listed in a
  vault, not a snapshot taken before the pass (a trade landing mid-pass was
  counted as seen); a record's path counts only where that path is in a
  vault (Q4's wording; a held file's path is not); a trade whose second side
  did not exist records the name it gave.

**A shortcut that does not work (tried 2026-09-29).** Running every pass on a
weak disk as a device with no vault key covers the server side: nothing in
a vault is downloaded or created, and saves into a vault wait. But a device
that had the key and then turns weak already holds sealed plaintext on the
disk. In no-key mode those records are parked and their files are no longer
read as sealed. A sealed file held outside its vault, edited or moved, then
went up plain: three scenarios reproduced it. D3 is therefore built as
written above: the vault's records are parked but stay sealed, so held files
keep their holds. It is not a no-key mode.

## Build order

1. **Facts** F1, F2, F4 on disk images.
2. **Seeds traced** (done 2026-09-28, table above). E1 goes to file ownership;
   Q4 decided (a): the oracle change goes with the simulator work.
3. **Simulator:** the positional and Linux-FAT disks, sweep modes, fixture
   variants. *Built in scratch 2026-09-28, not landed:*
   - `MemFs` numbers files by one of four models (`FileIds`: `Stable`,
     `DirectorySlot` for Windows with first-fit slot reuse and tunnelling,
     `DataCluster` for macOS FAT32, `MountSession` for Linux FAT and macOS
     exFAT), plus `remount()`. Each is pinned by a unit test against the
     traced facts.
   - The sweep reads `FATDISK=win|mac|linux` and remounts every 12th step on
     a fixed beat, so seeds keep their runs.
   - The Q4 oracle is built (B6's union). Its two pins go red without it. Baseline numbers on every disk kind, including file ownership's
   2b on positional disks.
4. **Three volume classes:** the filesystem-type check, the `Personality`
   state, and the id-reader inventory with each reader's fate. 2b off on
   positional disks. *Built in scratch (wf4), 2026-09-28:*
   - The type check and `positional_file_ids`, with `OsVfs` reporting ids and
     births as 0.
   - `unchanged_from` answers no at id 0; `entity_for_file_id(0)` is None;
     the id-keyed cache skips 0.
   - The path-keyed cache, which the daemon keeps honest from the watcher.
   - The folder-trash rescue by agreed bytes (both call sites).
   - The spool commit guard as size and time, behind the executor's hash
     gate.
   - The simulator shows the engine no ids on its FAT models, keeps 2-second
     mtimes, and models the watcher (touched paths, lost on remount or
     restart).
5. **Layer 1, rule X, rule U** (rule U also lands with file ownership, see
   above). *Built in scratch (wf5), 2026-09-28:*
   - `Entry::last_seen_sha` (a store column), written for every record the
     scan reads and for every record minted.
   - On a weak volume `pair_files` runs its "at home" and "followed" steps by
     bytes: a record's last-seen bytes, counted only when one record claims
     them and they stand on the disk. Everything else goes to `pair_with`,
     which still sees every record and file as evidence (`pair_with_given`).
     Rule X is therefore the strong step 2 itself, backup clauses included
     (B3 by construction), with a fixture that holds the two readings
     together.
   - Rule U in `upload` (compared with `last_seen_sha`, and the settle check
     by bytes) and in `move_remote` (refuses another record's bytes at the
     destination).
   - The merge guard by bytes; the executor's owner lookups by bytes on
     positional volumes (`owners_here`); the landing record minted with
     `last_seen_sha`; the held-skip guard.

   The rest of the step: `last_seen_sha`, written by the scan, read by
   the readers listed above; rule X in `arrived_by_a_trade`; rule U in
   `upload` and `move_remote`. Pins: 75298's merge shape, 75408's unrecorded
   stranger, 75122's upload fallback, and an unedited swap on each positional
   behaviour.
6. **Layer 4:** the shape (i) mark per D2; D3's vault refusal.
7. **Measure.** Trace every remaining fire on every disk kind. Build layer 2
   only if fires remain that are neither residual shape.
8. Under R10: zero seeds that pass on the committed code may fail, on any disk
   kind.

## How we know it is right

- The history-mixing oracle (`no_entity_holds_both_sides_of_a_swap`) and the
  sealed oracle are both at zero on the 420-seed sweep on every weak disk
  kind. The only exceptions are seeds traced to residual shape (i), which
  carry the mark. Vault seeds run on every weak disk kind and count; the
  oracle knows the D3 park.
- No seed that passes on the committed code fails, on any disk kind (R10).
- Every rule has a pin that fails with that rule taken out.
- A second agent reviews the build's diff (the design was reviewed by a5 on
  2026-09-28).

## Related

- `drive_file_identity.md`: strong identity; the weak limit this spec
  removes; Q1 (vaults on weak disks); O4 (asked, not named), which B1a
  qualifies for FAT.
- `drive_file_ownership.md`: every file has one record. Its 2b rules are the
  stable-id weak case of layer 3, and are off on positional disks. Its landing
  waits on this spec.
- `drive_sync_reset.md`: R10 (the landing bar), R11 (design first).
