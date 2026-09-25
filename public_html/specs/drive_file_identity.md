# File identity

**Status: drafted 2026-09-25 for approach review (R5). Owner decision
2026-09-25: a file's own identity decides which record it belongs to, and its
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
- **NTFS tunnelling** copies an old file's creation time onto a new file
  saved under its name within 15 seconds. That is harmless: the file id
  differs, and NTFS file ids carry a sequence number that changes on reuse.

**Every record knows its own file from the moment it exists.** A new field,
`own_file` (file id and birth), is separate from `synced_fingerprint`. That
one stays what both sides agreed, for change detection. When each event
happens:

- **Set:** when a provisional is minted from a scanned file; when a download
  or restore places a file (read from the spool handle before the rename,
  which keeps it); and when the record takes a new file (a safe-save read as
  an edit).
- **Carried:** by every engine move of the file: rescues, parks, conflict
  renames and follows.
- **Never cleared while the record lives.** Today the conflict rescue clears
  `synced_fingerprint` when it renames a file aside, and the record then
  takes whatever stands at its path. `own_file` is not touched by that.
- **Kept after a server delete**, until the record is forgotten. The reset's
  open item "a record the server trashes drops out of the scan's inode owners
  in the same pass" is that gap.
- **Existing records** adopt `own_file` at the first scan that finds their
  file at home (the file at their path carries their recorded file id).
  Until then they are weak.

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

   With several unclaimed names for one identity, the record takes the name
   in its own folder first, then the first path in byte order.
3. **Replaced.** A record whose own file is gone from the disk takes a file
   at its path that no live record owns: the safe-save, an edit. A file
   another record owns is never taken here.
4. **Weak records** (no strong identity recorded) run today's rules 1 to 3,
   including the trade test, over files no strong record owns.
5. **Found by content.** A record still unsettled whose agreed bytes stand
   on an unclaimed file no record owns has moved. Examples: a move to
   another volume, or a restore from a backup. Its own folder comes first,
   then byte order.
6. **The rest are deleted.** One exception: a live record whose path holds
   another record's at-home file is left unchanged, for naming (case twins).

Files no step claimed are new.

**The invariant:** a file whose strong identity a live record owns is claimed
by that record or by nobody. The record releases it in two cases: to a hard
link at home, or as a backup (step 2). Steps 3, 4 and 5 never take it.
A held record (its bytes known to stand elsewhere) reads only by its own
identity: steps 1 and 2, never 3 or 5, as today's held skip says for rule 1.

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
- **Weak volumes keep today's behaviour,** including its leaks under swaps.
- **Folders.** WP2 gave folders directory identity. The same birth pair would
  retire C5's recycled-directory rule; that is a follow-up. C9 part 2, the
  folder make_room rule, folder T1, the D1 park gap and C13 are separate.

## Owner question

- **Q1: a vault folder on a weak volume** (a USB stick, a network share).
  Two options:
  - *Sync it by today's rules.* It works anywhere, and carries today's swap
    risk.
  - *Refuse and say why.* The vault's promise holds everywhere it syncs, and
    a vault cannot sync to a stick.

  Recommendation: refuse. This does not block the build; it is its own
  change after this one.

## Behaviour changes, pinned

The scan's unit tests: 22 keep their result. Three change, each on purpose:

| Test | Today | Strong identity |
|---|---|---|
| `a_move_the_scan_cannot_confirm_reads_as_a_delete_plus_a_create` | deleted + created | moved and edited (weak: unchanged) |
| `a_file_at_its_own_path_is_claimed_before_anybody_goes_looking` (file 1 moved over file 2's name) | file 2 edited with file 1's bytes, file 1 deleted | file 1 moved, file 2 deleted |
| `a_held_files_own_file_back_where_the_server_keeps_it_is_the_file_come_home`, second half (no `server_home`) | edit | moved: its file is in another folder, so it is not a backup |

**The second row is AH in miniature.** When file 1 is sealed and file 2 is
plain, today's reading sends the sealed bytes as file 2's version. That
test's comment records why the path rule was chosen: the move onto a name a
live record holds was refused by the server on every pass. The strong
reading plans file 2's delete in the same pass, so the build must order the
delete before the move. A pin asserts that it settles, with no request
repeated.

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
- A plain file moved over another's name. One move and one trash, settled.
- A sealed file moved and edited inside its vault. Sealed, with one history.
- A sealed file moved and edited out of its vault. Held, with the edit
  waiting. This supersedes
  `a_sealed_file_moved_and_edited_in_one_pass_is_a_delete_and_a_creation`
  for strong identities; that pin stays with births hidden.

Every scenario pin that changes result is listed in the build report with
its old and new end state. Each one is a behaviour change, not a fixture
update.

## The build, in three commits

1. **Disk and record, read by nothing.**
   - `jd_vfs` reports the birth time: a new field beside `file_id`, zero where
     the volume has none.
   - The personality marks positional-id volumes weak.
   - `MemFs` gives files a birth serial, as it already does directories:
     carried by a rename, new on create and copy, never recycled under
     `reuse_file_ids`. A switch hides births, for the weak control.
   - The store gains `own_file`, set and carried as above.

   Bar: traces byte-identical to `9b992a58` on every arm (R4), because
   nothing reads the field.
2. **The scan reads it.** The strong path as above; the weak path is today's
   code, untouched. Measured alone against commit 1.
3. **The executor checks it.** Measured alone against commit 1, then with
   commit 2 (R7: each with its neighbour removed).

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
custody, and converged or never-settled. What they cannot see is stated in
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
