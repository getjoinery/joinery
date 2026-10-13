# Folder trash that refuses what it never saw

**Status: built 2026-10-11 and approved by reviewer1 (NEEDED + VALID; design
read twice, R1-R16 folded in below). Verified: the server's functional tests
(trash_unchanged_since 16/16, placement_race 31/31, the other drive suites),
the client and simulator pins, the 2100-run sweep and the ring. A real release
running both halves and a live trash race are in the live verification queue.**
The remaining half of owner decision D1
(A, 2026-10-11: new content beats a folder delete). The client half of D1 is
built separately; this spec closes the window the client cannot see into.

## What the user gets

A folder deleted on one device never takes with it a file that changed on
another device after the deleting device last looked. Today the server trashes
a folder with everything in it, whatever arrived a moment before, so an edit or
a new file that reaches the server between the deleting device's last read of
the change feed and its trash request ends up only in the server's trash. The
user who made it sees it vanish from where they put it.

After this, the server refuses that trash. The deleting device reads what
changed, the folder comes back holding the newer files (an edit beats a delete,
and so does new content), and the rest goes to the trash as before.

## The window

The client already stands a folder trash down when it knows of a newer file
inside (`trash_remote`'s walk, B-O1 and D1). It cannot stand down for a change it
has not heard of yet: an edit or a new file that reaches the server after the
deleting device's last read of the change feed and before its trash request.
Only the server sees both at once. Witnessed by hand-built races (Tests); no
sweep seed has been traced to it yet. Clean3 74927, first read as this race,
is a different defect: the deleting device knew the edit and lost it to a
merged move it had not yet sent (B-E8, its own fix).

## The check

`drive_trash` takes an optional `unchanged_since`: a change id from the same
`file_changes` sequence the client reads in `drive_changes`. Same clock, said
plainly because a second clock here would be a silent miss.

- **Absent:** no check, today's behaviour (R5).
- **Older than the oldest change the server keeps:** refused with
  `changed_since` (R6), by the feed's own test (`drive_changes`: a cursor with
  `cursor + 1 < MIN(id)` resets) so the two agree on the boundary. An empty feed
  (`MIN` is NULL after a purge) refuses too, carrying the cursor itself as the
  id since there is no newest change (R14). A cursor from before the retained
  window proves nothing about the gap; a journaled op that outlived a feed reset
  carries one.
- **A folder:** refused if, anywhere in its subtree as it stands now,
  - a live **file** has a `created`, `content`, `moved` or `restored` row after
    the cursor, or
  - a live **subfolder** has a `moved` or `restored` row after the cursor (R3:
    a folder moved in or restored brings its files with rows older than the
    cursor, and only the folder's own row says it arrived).

  A rename inside the subtree, a grant or level change, and an empty subfolder
  created after the cursor do not count: none is content the deleting device
  failed to see (R10; a file inside such a subfolder counts by the file rule).
  `moved` here means "a row of that kind on something now in the subtree": rows
  carry no source or destination, so a move between two subfolders inside also
  refuses. That costs one overtaken trash; the next round decides at a newer
  cursor (R7).
- **A file:** refused on a `created`, `content` or `restored` row after the cursor,
  the same kinds as the folder rule on this one file (R11).
- **Zero** means "nothing agreed": any retained row refuses (R5).

The refusal carries the marker `changed_since` and the newest change id it
found, so the client can tell it from every other refusal. An accepted trash
answers `checked_since: <cursor>` when the check ran: proof for tests and traces
that the server honoured the field, not something the client decides on. An
older server passes the unknown field through untouched and answers without it
(R8).

## Atomicity (R1, R2, R13, R15, R16)

The check means nothing unless nothing can land in what it covers between the
check and the marking. Today it would not hold: only new placements take a
lock (`place_into`, per destination folder, and none at the drive root); every
verb records its change row after that lock is released (move, upload_init,
upload_complete, restore, folder_create); content replaces take no lock at all
(`drive_upload_complete_logic`'s replace paths, `drive_upload_init`'s,
`drive_version_restore_logic`); and the cascade releases a folder's lock before
recursing into its subfolders.

Locking every folder of the subtree cannot be the answer: advisory locks live
in Postgres's shared lock table (on dev 64 × 25 = 1600 slots for every session),
a synced project tree reaches thousands of folders, and a large trash would fail
part-way or starve every other session (R13). Nor would it reach a file at the
drive root, which has no folder lock (R15), or a file moved out of the folder
between the cascade's listing and its marking (R16).

**One lock per owner.** The trash takes `pg_advisory_lock` **exclusive** on
`(TRASH_LOCK_CLASS, owner user id)` around its check and every mark. Every write
the check reads -- a placement, a content replace, a move, a restore -- takes
`pg_advisory_lock_shared` on the same key around its write **and** its change
row. Keyed on the entity's owner, not the actor, so an editor's write into a
shared folder is held by the owner's lock. Order everywhere: the owner lock
first, then a folder's placement lock, so the two classes cannot deadlock. The
per-folder placement locks stay exactly as they are for write against write,
and the cascade keeps its top-folder lock, so `place_into`'s re-ask of a
trashed destination is unchanged. A file trash takes the same exclusive lock
around its own check and mark.

One lock per request because a move cannot cross owners
(`drive_move_logic`, the single-owner-tree rule): every write the check reads
has exactly one owner to lock. If that rule ever relaxes, a cross-owner move
takes both owners' shared locks in ascending owner-id order.

One lock per request, bounded, and it covers root files, file trashes and a
move out mid-cascade. Each write holds its side only from the moment it lands to
its change row, never across the byte work before it (an upload's seal and
copy), so a trash waits only for writes already landing. The cost: a trash holds
that owner's writes back for its whole cascade, which includes moving each
trashed file's blob to the trash's storage (`File::soft_delete`) as well as the
row updates -- for a large folder, seconds or more -- which nothing does today.
Other owners are unaffected.

## The client

- `trash_remote` sends `unchanged_since`: the change its round was decided at
  (`decided_at`), which every trash op carries, file or folder, fixed in the op
  so a retry sends the same request under the same key. It omits the field
  when it has none (an op journaled before trashes carried one) rather than
  sending zero (R5).
- `changed_since` is read as **overtaken**: the op leaves the journal and the
  next pass, which reads the feed first, decides again. The round then keeps
  the folder for the newer file, by the existing B-O1 and D1 rules. A
  re-planned trash carries a new idempotency key and body, so no stored
  refusal replays (R9).
- No retry count and no issue: nothing is wrong, somebody else's work arrived.
  A refusal in prose alone, with no marker, keeps its ordinary reading --
  withdrawn, and said -- since nothing says it was this one (a grantee's device
  deleting a folder it may not trash is refused that way, and must say so).

## Convergence

A busy shared folder can refuse the trash more than once. Each retry carries a
newer cursor, and the round at that cursor already keeps the folder for every
file changed before it, so each refusal ends in "kept", not in another trash of
the same folder. It converges to the folder kept with only the changed files,
or to the trash once nothing changes in between.

## Residual (R4)

The feed hands out `next_cursor` as the highest change id it saw. Change ids are
allocated at insert, and two concurrent writes can commit out of id order: a
lower id that commits after a client's read sits below that client's cursor for
good, in the feed and in this check alike. This is a property of the feed, not
of this spec, and the locks above do not close it (two writers in different
folders suffice). The fix is feed-side: `next_cursor` must not pass an id that is
still in flight. Its own change, named here so nobody reads this spec as closing
the window entirely.

## Known: one weak-volume seed moves the wrong way

Kill2 75102 passes before this and fails after it on FAT (linux and mac
personalities). The first difference is a trash this change correctly refuses;
the loss that follows is the weak-volume reading `drive_file_identity.md` lists
under "What it does not fix": a user's trade puts a sealed file at another
record's path, that record's local trash takes the file (on a volume without
identities the path decides the record), and the device then reads the file as
deleted. This change moves the interleaving, not the mechanism; it is filed
against the weak-volume work.

## The sim

`MockServer`'s `drive_trash` applies the same rule against its own change
sequence, including the subfolder rows (it already records one row for a folder
move, `server.rs` ~1518). It is one mutex and atomic by construction, so it
cannot witness R1 or R2 (R12).

## Tests

- **Server (PHP, `tests/functional/drive/`):**
  - a folder trash with `unchanged_since` older than a file's content change is
    refused with `changed_since`; one newer is accepted and answers
    `checked_since`;
  - a file created, moved in or restored after the cursor refuses; a subfolder
    moved in or restored after the cursor refuses; a rename inside does not;
    an empty subfolder created after the cursor does not;
  - absent field: today's behaviour; zero: refuses on any retained row; a cursor
    older than retention: refuses;
  - locking (R1, R2, R12): through a test hook, each write verb holds the
    owner's shared lock while it writes and records its row; a file trash and a
    root-level file are covered the same way. Proven here and by reading; the
    sim cannot witness it.
- **Client and sim pins:**
  - a hand-built race: b decides a folder trash; a's edit reaches the server
    before b's trash runs, without b reading the feed in between; the edit
    stays live in the folder on every device;
  - the same with a new file, and with a subfolder holding a file moved in;
  - convergence: two writers keep writing into a folder one deleter deletes;
    settle; every written body is live, and the folder holds only those files;
  - an op with no cursor sends no field;

- **Sweep:** the edit oracle's lost count stays at zero across the ring.
