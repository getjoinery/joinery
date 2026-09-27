# Every file has an owner

**Status: DRAFT (public-html-b3, 2026-09-26). Design proposal, not built.
Measured with a census in scratch on the 420-seed sweep at `d9ad1769`.
Approach NEEDED and VALID (public-html-a5, 2026-09-26), after its
objections O1-O8 were written in (design 1a-1d, 2, 3, 4). Build started.**

This is the reset's cause 3 (`drive_sync_reset.md`, "Why a reset"). It takes
B6 and the reserved-name detour below as routes into one cause, the same way
`drive_file_identity.md` took T1-C, T1-D and R.

## What the user gets

Whatever the engine can see in the sync folder is always somebody's: every
file on the disk belongs to exactly one record from the moment the engine
first sees it until it is gone. The rules a record carries (this file is
sealed, this file is held outside its vault, this file is waiting for a key)
then reach every file, with no window in which a file belongs to nobody and
no rule applies to it. In particular, no route exists by which a sealed file
comes out of a vault as a brand-new plain file.

## The flaw, in one paragraph

Every rule the engine has is a property of a record. A file with no record is
outside all of them: it has no identity to be followed by, no hold, no
encryption decision. The engine lets files be without a record in three
places. The scan skips a new file standing where a pending download will
land (the reserved slot). The disk walk skips every name that begins `.jd-`,
the engine's own prefix, including a file the user named that way. And the
executor moves such unowned files aside as conflict copies, which stay
unowned until the next pass. In each window a move can carry the file
somewhere its old rules would have stopped it, and the next pass meets it as
a stranger: minted as a new file, decided afresh by where it now stands.

## What it causes

- **B6 (traced in kill2 75129).** A file saved at a vault slot reserved for
  a download gets no record; the download moves it aside; a swap carries it
  into a plain folder before the next pass; it is minted there as a new plain
  file and its sealed bytes go up in the clear. The hold for a file carried
  out of a vault (owner decision D1) cannot reach a file with no record.
- **The reserved-name detour (new, 2026-09-26, pinned in scratch, one device,
  no faults).** A sealed file renamed in its vault to `.jd-out.txt` vanishes
  from the walk, so its record reads it as deleted and the sealed copy is
  deleted on the server: `out.txt` (901) is trashed there, and the one sealed
  file the vault still holds is the other one, `stays.txt` (902). Renamed out
  to `Plain/out.txt`, it is a new file and goes up in the clear, no hold
  raised. The same drag without the detour is held, as designed.
- **Unowned files, routinely.** After a pass that completed, 382 of the 420
  seeds have a file on the disk that no record owns (census below). The
  shape in almost all of them is B6's first half: a file saved at a pending
  download's slot is skipped by the scan, and the download moves it aside as
  a conflict copy with no owner. B6 is the rare case where a move then
  carries it out of a vault before the next pass.

## Census (scratch, 2026-09-26)

After every pass on every device, every regular file on the disk is classed:
owned by one record's identity; TWICE (two live records own it); PATHONLY (a
settled record's path, no record's identity); SLOT (only a pending download's
reserved path); ORPHAN (nothing). The scan's skips and the executor's
move-aside sites are tagged so each unowned file is traced to where it came
from.

Births shown, counting only passes that completed (a pass that failed
before its scan has seen nothing new, which the rule allows):

| Unowned file after a pass | Seen | In a vault | Made by |
|---|---|---|---|
| Conflict copy with no owner | 5,253 | 894 | `make_room` moving a file the scan skipped at a reserved slot (5,426 move-asides) |
| At a reserved download slot | 6,077 (+147 on the decomposing volume, first misfiled by the census) | 916 | the scan's reserved-slot skip (6,798 skips) |
| A download placed and not yet recorded | 76 | | a kill between the placement and the record write; retried and settled (kill2 75100, traced) |
| A file saved during a pass, after its walk | about 210 | | the rig's saves while the engine lands a file; the next scan owns it (kill2 75102, traced; the rest read by shape) |
| A user file named `.jd-...` | 1 | | the walk's prefix skip; the workload almost never makes one, so the detour is pinned by hand |

Births hidden gives the same picture (5,405 conflict copies, 16,123 at a
slot). It also shows 14,942 files owned twice: without births ownership
falls back to the file id alone, and the rig reuses ids, so stale records
match strangers. The rule on those volumes is by path (below), so these are
not the same measurement.

Executor move-asides over the same run: `make_room` 5,426, the held-name
clear 108, rescue 50, `preserve_local_as` 42, the blocker at a move's
destination 6. Only `make_room` moves the owner record-first.

## The rule

At the end of every scan, every regular file the walk can see is the own
file of exactly one live record. The executor keeps it true: no step makes,
moves or leaves a file without its record in the same step, record first.

On a volume without trusted identity (no birth, FAT, exFAT, ReFS, a probe
that could not write) records keep owning by path, as `drive_file_identity.md`
says; there the rule reads "every visible file's path is held by at least one
live record". At least one, not exactly one: a file waiting for the download
due at its path shares that path with the download's record (1b).

## The design

1. **The scan mints a record for every file nothing owns. No path skips.**
   - **(a) A name the user chose that begins `.jd-`** (other than `.jd-swap-`,
     below) is seen by the walk and gets a record of its own reading, not
     the server-side `Unsyncable(ReservedPrefix)` park, which means the
     server name has the prefix and makes the entry invisible to later passes
     and subject to the put-back rule. The record keeps its `remote`; it plans
     no move to that name (the server refuses the prefix; the planner reads
     this from the record's status, named at the freeze); it raises the
     `reserved_prefix` issue for itself; it stays in `known_local`, so its
     identity pairs and its holds stand; `holds_a_local_file` is true, since
     the file is on the disk; the put-back rule does not fire on it. A sealed
     file detoured through such a name is then a move of a sealed record, and
     stays sealed and held.
   - **(b) A file at a pending download's slot** gets a provisional record
     in a state of its own: *waiting for the download due here*. It is not
     sent while the download is pending, so the two can never race in the
     Transfer stage. It holds the slot's path with the downloading record.
     This state is what the reserved-slot set protected, now reachable by
     identity and by holds: a download placed at an ESCAPED local name
     (`memo-47%20` for the server's `memo-47 `, `names.rs`; the escape has no
     inverse) and killed before it was recorded must never be minted and
     uploaded under the escape. The download settles it when it lands, in
     the executor, keyed by identity:
     - same bytes (its own earlier placement): the download takes the file
       from its owner in the same step (`owners_of_file`, then `merge_file`
       with hand-over), and the waiting record is forgotten;
     - different bytes: `make_room` moves the file aside WITH its waiting
       record (2), which becomes an ordinary new file under the conflict name
       and is sent from there.
     If the download is withdrawn instead (the server deleted the file), the
     waiting record becomes an ordinary new file where it stands.
     The wait is worked out fresh each pass, not stored: a never-sent record
     whose path a record with no agreement here holds. Nothing is left to go
     stale when the download is withdrawn or the file moves.
     A waiting record holds no name in naming's contest either. Judged
     there, a never-sent record outranks a real one and the real one is
     parked as a duplicate; parked, its download never comes, and the file
     waiting for it, refused its name, waits for ever (found building step
     3: frozen seed 111740 and kill2 75400 never settled with the reserved
     set gone and naming unchanged).
   - **(c) No record whose bytes have not arrived takes a merged file.** The
     name merge (`merge_duplicate_files`) hands a provisional's file to any
     real record at the same name that owns none, by name alone, before any
     bytes are read. A pending download owns none, so a stranger minted at
     its slot would be folded into it, moved aside by the download, and
     orphaned again. The name merge refuses a record with no agreement
     here; the same-bytes hand-over is the executor's, in (b). This covers
     the merge's other job too, an upload whose answer was lost: the server
     file it made arrives as a record with no agreement, the never-sent
     record waits for it, and its download finds its own bytes and takes
     them. One download of its own bytes, only after a lost answer.
   - **(d) The engine's scratch names.** A `.jd-swap-` name is the engine's:
     owned with an operation open, it is a live park; owned by nothing, it is
     litter from a finished or abandoned dance and is sent to the OS trash as
     today (recoverable). That rule stays. A user file literally named
     `.jd-swap-...` is trashed with it, as today: accepted. Any other `.jd-`
     name is the user's (a). The spool (`.jd-tmp-`) lives in the state
     directory, outside the walk.
   - **(e) A file whose folder has no record yet:** the folder is minted
     earlier in the same pass, so this only waits when the folder itself
     could not be. The census saw no file waiting on this across a pass.
2. **One way to move a file aside: with its owner, record first.** Every
   executor site that renames a file aside does it through one step that
   writes the owner's new placement before the rename.
   - An established owner (it has an agreement) follows as `make_room` does
     today: agreement moved, server move owed (`agree_and_owe_move`).
   - A never-sent owner follows by rewriting its `remote` placement, with no
     server move owed: there is no server file to move. Today
     `the_owner_follows_its_file` returns without doing anything for a record
     with no agreement, so under 1b alone `make_room` would still orphan the
     aside. A never-sent owner held outside its vault stays held where it
     lands (Q2 in `drive_file_identity.md`).
   - `preserve_local_as` mints its copy's record after the rename; it moves
     to record first.
   - The blocker at a local move's destination (`execute.rs`) follows only a
     directory's owner; it follows a file's owner too. Its identical-bytes
     arm trashes the blocker without asking whose it is: with every file
     owned, trashing an established record's file there reads as a local
     delete at the next scan and trashes the server copy. It trashes only
     when the blocker's owner is nobody, or a never-sent record forgotten in
     the same step; otherwise the blocker goes aside with its owner.
   - The rescue out of a trashed folder follows a never-sent owner as well as
     a held one, record first. An established live record there is one the
     server spared: no server move is owed for it, and the next scan finds its
     file by identity.
3. **A download's record takes its file's identity before the file is
   placed.** The spool keeps its identity through the rename into place, so
   the record writes `own_file` first and then the file lands. It writes
   `own_file` ONLY, never the agreement: a commit that fails removes the
   spool, and the record is left with an own file that stands nowhere,
   which reads as "not yet here", not as a record whose file was deleted.
   This holds only while placing is a rename on one volume. Today it is a
   bare rename from the state directory, and the reset's B1 says a sync
   folder on another volume therefore cannot download at all; the fix for B1
   must keep placing a rename (a copy keeps no identity), for example by
   keeping the spool on the sync folder's own volume. Coupled: B1 is fixed
   with or before this point.
4. **The harness checks the rule after every pass, not only at the end.**
   The census becomes an oracle, run after every pass on every device:
   - strong volume: no file unowned, none owned only by a slot, none owned
     twice;
   - weak volume: every visible file's path is held by at least one live
     record;
   - excused, by name: a file the rig itself saved after the pass's walk
     began (the harness makes those saves and records them per pass, so it
     can name them), and every file after a pass that failed before its
     scan.
   `assert_no_disk_file_is_unclaimed` asks the question once, at the end;
   a file unowned between passes and owned again by the end is exactly the
   window B6 leaked through, and an end-state check blesses it.

## What it retires

B6 as a separate fix, and the reserved-slot SET in `pass.rs` (a path skip).
The reservation itself stays, as a record's state (1b), where identity and
holds can reach it. Not retired: the `.jd-swap-` litter rule (1d).

## How we know it is right

- The oracle (4) reads zero on the 420 seeds, births shown and hidden, or
  every remaining case is traced and named.
- Pins, each red on `d9ad1769`:
  - the reserved-name detour;
  - B6's shape: a file saved at a pending download's vault slot, set aside
    by the download and carried out of the vault before the next pass, stays
    sealed; and its twin where the download is withdrawn instead: the waiting
    record becomes a new file decided by the folder it stands in (sealed in
    the vault), and dragged out before it was sent it is held (Q2);
  - a stranger at a pending download's slot is not folded into the download
    (1c), and keeps its own record under the conflict name;
  - a download placed at an escaped name and killed before it was recorded
    keeps one record and never uploads the escape (1b);
  - a sealed file set aside by `make_room` while never sent keeps its record
    and its encryption (2).
- Sweep against `d9ad1769`, births hidden as the control: every G->R traced.

## Build order

Each its own commit, measured alone against the one before it, births
hidden as the control:

1. The oracle (design 4), counting, not failing: the census as a harness
   check with its excuses, so every later commit shows the count it moves.
2. The executor (design 2): every move-aside takes its owner, record first,
   never-sent owners included; the blocker's trash asks whose it is.
3. The scan and the download (1b, 1c): the waiting state, no merge into a
   pending download, the download settling its slot by identity. The
   reserved-slot set goes.
4. User `.jd-` names (1a), files and folders: a folder the user names
   `.jd-...` hides everything inside it from the walk, the same detour one
   level up. A synced file renamed to such a name must keep its server name
   while its file stands under the reserved one, which touches the
   placement agreement the way a held file does; designed on its own after
   1-3 are reviewed.
5. Record-first downloads (3), with or after B1.

The oracle turns from counting to failing once the count reads zero.

## Found by the sweep, 2026-09-27

- **Step 2, a never-sent record followed across folders mid-pass** (hostile2
  74427, a sealed leak; eleven seeds red, all back with the follow off).
  Fixed in step 2: a make-room follows a never-sent record only within its
  own folder (a rescue, whose folder the engine chose, may cross), and an
  upload planned before its file was set aside stands down. Both needed;
  each pinned. Re-swept: births shown, no seed green to red; hidden, one
  (plain2 75208), not yet traced.
- **Step 3 exposes a gap on volumes without births: a never-sent record
  cannot follow its own file.** It has no agreed content to be found by, and
  its file id alone is not trusted there. So a file carried by a swap reads
  as a new file (hostile2 74415, births hidden: a vault file minted again in
  a plain folder and sent in the clear) or as another record's edit at the
  path it landed on (plain2 75204, 75217, 75278, 75279: one record holds
  both bodies of a swap). The reserved slot hid this by never minting such
  files at all: in the base run of 74415 the vault file had no record for
  the whole run and was never synced. Proposed close, not built: on such a
  volume a never-sent record follows its own file by file id, and scan rule
  1 counts a never-sent record's own file id when it asks whether a file at
  a path arrived by a trade. An id there is evidence enough to keep a file
  under its record, never to send it: a wrong match (a reused id) holds a
  stranger or mints it where it stands, and loses and leaks nothing.
- **A harness false alarm** (plat3 75429, births shown): the sealed-name
  check counts the name of a vault file the user only edited. Here the
  engine had named it (a conflict copy), and the same generated name came
  up independently for a plain file at the top. The check should count only
  names the user chose, by creating or renaming. The reset's flaw 3 family
  (the harness judges by name, not by where the name came from).

## Open

- B1's fix and where the spool lives (3). The coupling is stated; the
  placement is B1's to choose, under the rule that placing stays a rename.
