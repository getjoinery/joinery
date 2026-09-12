# Drive sync: the reset

**Status: in progress. WP1f, WP1a, WP1b DONE 2026-09-12 (harness only, engine
still `df2f5c88`); WP2 next. Every change reviewed by public-html-c6, approach
before patch.**

Testing is paused. No further guards land on the sync engine until the work
packages below are done, in order. This spec is the reason, the order, and the
rules.

## What the user gets

Their sealed files stop leaking because the engine stops getting confused, not
because six checks catch the confusion on its way out. Dragging a sealed file
into a folder they just made keeps working. And the test estate tells the
truth: a green run means no sealed file was published, not that one particular
file was not.

## Why a reset

The pattern of the last week is the pattern of the whole campaign: find a defect
with a sweep, build a guard, the guard breaks something or moves the failure,
narrow the guard, a new seed regresses, build another guard. Yesterday landed
four guards and a regression; today two more narrowings to undo it.

**Every guard in commit 87ca2389 stops a consequence.** "Never carry a file out
of a vault", "never mint a plain folder around a sealed file", "never forget a
sealed record while its file is here". Each says: *when the engine is confused,
do not let the confusion publish a secret.* None makes the engine less confused.
The plain-file version of every one still happens -- files re-minted,
re-uploaded, given second identities -- and the spec that landed them records
that as accepted debt. That is a belt described as a principle.

**One of those guards broke a designed behaviour, and nothing could see it.**
B1 (c6, traced against the named commit pair, reproduced by a pin): a user with
a vault makes a new folder and drags a sealed file into it -- the designed
drag-out, the thing `c1` pins. On 87ca2389^ the folder reaches the server and
the file goes up in the clear under it, in 4 rounds. On HEAD the mint guard
reads the new folder as a lost vault directory, holds it with an issue that
says *"it will sync once the folder is recognised"*, and it never is, because a
new folder is never recognised as anything. No ring arm crosses the vault edge
by design and no pin covered a NEW folder. **"201 pins, zero regressions" was
the landing bar, and it was the wrong question**: the right one was *which
designed behaviour does a refused mint block?* -- one scenario, never asked.

**The causes have been named for weeks; neither is built.** There are two, not
one, and the earlier draft of this spec got that wrong:

- **Cause 1 -- a folder record does not know which directory is its own.**
  `drive_directory_identity.md` (2026-09-10). It infers that from the files
  inside, and the inference fails exactly when files and folders move at once.
  Defects AC, AD, AE, AF (routes 1, 2, 4), AG and AJ (both shapes) are this
  gap. Both reviewers said so; 0e: *"everything above is a belt."*
- **Cause 2 -- the scan pairs a record with whatever stands at its path,
  inode not consulted.** `scan::pair` rule 1. Its verb is a FILE name swap
  (`NOSWAP=1` takes adoptions to zero); AH is that with a sealed file on one
  side and AI is it with no vault at all. Directory identity never enters
  rule 1. **AH owns 9 of the hostile arm's 22, its fix has an owner decision
  pending** (crossing + `mine4`, or nothing), **and this reset does not make
  that decision.** So when cause 1 is fixed the hostile arm stays roughly nine
  red BY DESIGN, and that number must be read as AH waiting, not as WP2
  failing.

**Today's regression is the pattern in one seed.** A guard concluded a folder
had moved inside itself because its FILES had; another guard caught the
consequence; both were narrowed. Three edits to repair one, none touching why
the engine cannot recognise its own folder.

**And the instrument undercounts.** The disclosure oracle watches ONE ring file
by hash. A different sealed file leaked on the three-device arm and only the
stranded-file check noticed, by accident. Months of "N of 40 seeds leak" were
taken against an oracle that sees a fraction of leaks.

## What happens first, before any work package

**The mint guard (fix 2) comes out of the tree**, with the B1 pin landing red
on HEAD and green after. Measured with fixes 1, 3 and 4 kept:

    arm (named pair: 87ca2389^ -> 87ca2389 -> fix 2 removed)
    clean, 2 devices          11  ->  1  ->  4
    clean, 3 devices          11  ->  4  ->  7
    hostile, 2 devices        21  -> 16  -> 17
    B1 pin                    green -> RED -> green
    scenario suite            201  -> 201 -> 202 (B1 added)

Fix 2 bought seven seeds across two synthetic arms at the price of a real user
action that silently never syncs. **The four that return on the two-device arm
-- 74023, 74031, 74033, 74035 -- are all the vault-missing shape from the AJ
trace: the file's own parent is not on disk and its path resolves to another
real folder.** That is the shape the subtree refusal does not reach and
directory identity does, so those four are a named statement of what WP2 owes. That is not a trade this project makes, and
the guard cannot tell the two cases apart without directory identity, which is
the whole thesis of this spec.

**With it goes "direct children only"** -- a narrowing of fix 2 fitted to seed
74826 with no mechanism written for the residual; R2 by this spec's own words.
**The subtree refusal stays**: a folder is never matched into its own
descendant is a tautology the proposer violated, not a belt.

## The order, and why it is this order

    WP1f  a seed is deterministic     -- or nothing below can be measured
    WP1a  the sealed oracle sees      -- before WP2 is JUDGED, not built
    WP1b  the swap chain oracle lands -- built; narrow; named
    WP2   directory identity          -- cause 1
    WP1d  the custody oracle          -- beside WP2, baseline on the named SHA
    WP3   remove the belts            -- each on its own bar, none on a count

WP1f first because WP3's method (remove one belt, re-run, a moved number was
not inert) and WP2's bar (no seed moves green to red) both assume a seed gives
one answer, and B10 said the sweep was not trace-reproducible with the cause
unestablished. "Stable in practice" is an observation, not a property. (It is
now a property: see WP1f.)

WP1a before WP2 is judged because it is the disclosure instrument and it is
cheap. WP1d is NOT in front of WP2: it sees custody, not leaks, and R4 makes
its order irrelevant -- its baseline is taken against the named pre-WP2 SHA
whenever it lands. An instrument unbuilt since 09-05 in front of the cause fix
would be "the cause fix is bigger" in reverse.

## WP1f -- a seed is deterministic -- DONE 2026-09-12

B10 said two runs of one seed do not give one op journal, cause unestablished.
The candidate was per-process HashMap iteration order at decision sites, the
fix sorted iteration there, and the fallback -- if one round did not close it
-- every seed in a measurement run N times with any red counting as red.

**Closed as a misread of the probe, no engine change.** The 09-10 evidence was
two `enc=true` UPLOAD probe lines whose `content` field differed. For an
encrypted upload that field is the content id, sixteen bytes from `OsRng`
(`jd_crypto::drive::new_content_id`): a random label for the ciphertext, never
a hash of the bytes. Four seeds across the arm shapes, three processes each:
the per-pass journal and every plaintext tree byte-identical; only the
sealed entries' random names and ciphertext hashes differed. Full record in
`drive_sync_estate_fidelity.md` under B10.

**What landed:** `World::journal()` (one line per pass: device, plan, exec
report), `JD_JOURNAL=<file>` on `scratch_arm_one` to dump journal plus trees,
and `one_seed_leaves_one_trace` in `zz_sweep.rs`: one seed per arm shape run
on two threads, the threads first shown to iterate a `HashMap` in two orders
(so the test measures what it claims), then the trace compared line for line
through the key holder's view of the server -- sealed entries decrypted, not
excluded -- with the first differing line named. Proved red by comparing the
raw server tree instead (a sealed entry's random name, as predicted), then
put back.

**Consequences:** the N-times fallback is not in force. R6 gains a fifth trap:
a probe field whose meaning differs by branch. The seam for reproducible
ciphertext exists (`new_content_id_with_rng`, `FileKey` over any `RngCore`);
seeding the sim's crypto RNG is a separate small step, not needed for any bar
here.

## WP1a -- the sealed oracle watches every sealed file -- DONE 2026-09-12

`assert_sealed_content_never_reached_the_clear` looked for one hash. It now
looks for every body the user wrote into a folder they sealed, every version,
cumulatively, MINUS every body the user later wrote into a plain folder by
their own hand -- action 16 duplicates a file into any directory with no
vault-edge gate, so without the subtraction the oracle false-reds on the
user's own copies.

**What "sealed" means, and why it is not a path prefix.** The disk itself
records every user write and rename (`MemFs::user_writes`, `user_renames`),
each with whether it stood under a directory the harness had marked sealed
(`mark_sealed_dir`, placed on `Private` and the encrypted ring on every disk
after the folders arrive and before the first file is written; the mark
travels with the directory through every rename, the user's and the engine's,
and dies when the directory is removed). A body is sealed if it was written
under a marked directory OR under `Private/` by name. Two rules because the
two folders are trusted differently: the user never renames `Private`
(`is_movable` refuses it), so a plain directory standing at that name is the
engine's doing -- the AG shape -- and a user write into it going up in the
clear is a TRUE positive, not a subtraction. The rings the user trades by hand
all run long, so a ring name says nothing: the first cut of this oracle used
`ring-1/` as a prefix and read a landing-time chaos save into the PLAIN ring
wearing that name as a leak (hostile2 74400). A write under a ring name into
an unmarked directory is the plain ring by identity, or an engine mint whose
provenance is gone; both are treated as the user's own plain publication and
counted beside the verdict as `unattributed_ring_writes`.

**Three checks, each named in its failure**: (1) no sealed body's plaintext
hash is in the server's blob store (content-addressed, never forgets, so old
versions are found the same as current ones), less the exempted; (2) no
version of a file the server FLAGS encrypted carries the hash of any user
body, exempt or not -- ciphertext never hashes like plaintext, so a match is
plaintext under the encrypted flag, the inside half the subtraction cannot
see, and the shape `assert_nothing_in_the_vault_is_readable` (flag only)
misses; (3) no leaf name the user only ever wrote or renamed onto under a
sealed prefix stands on the server as a stored title (renames count -- a
plain file renamed onto a name that also exists sealed was a false red on
clean2 74000 without them; NFC on both sides for the decomposing volume).
The residual, named: the sealed original of a body the user also copied plain,
published OUTSIDE the vault (the AJ shape), is invisible to check 1; the mock
cannot tell which upload carried it. Not chased with move tracking.

**Coverage beside every verdict** (R3): `SEALED-ORACLE seed sealed_bodies
exempted_as_copied_plain sealed_names unattributed_ring_writes`.

**Pin:** `the_sealed_oracle_sees_a_file_the_workload_sealed` -- a user writes
`Private/budget.xlsx`, the world settles, the oracle is green; the harness
seeds that plaintext at `quarterly.dat` and the oracle must fire naming both
paths; the user copies the body plain and the oracle is green again; the
sealed file's ciphertext is rotted to the plaintext and check 2 must fire
naming the file. RED on the one-file oracle (run in the `df2f5c88` scratch
tree: "the oracle passed a sealed body standing plain on the server").

**Readings** are in the table under WP1b; the first every-file reading is
labelled "bodies pre-WP1b" and paired with the second under R4.

## WP1b -- the swap chain oracle lands -- DONE 2026-09-12

`assert_no_entity_holds_both_sides_of_a_swap`: for every pair of bodies a
swap separated, no single server entity's version history holds both. It
reports *"N swap-separated pair(s) held by one entity"* and never "N poisoned
chains"; it is blind to poisoning that arrives any other way and its comment
says so. Coverage beside the verdict: pairs recorded by source (`chaos` --
the mid-upload name-swapper; `slots` and `rotation` -- action 13's file
slots; `folders` -- the ring trade, recorded per leaf present under both
folders with differing bodies, which is the AD shape and lets this oracle see
part of cause 1), entities with versions, versions, and the count.

**Unique bodies at the source, no dial.** The af2 form was `UNIQ=1`, a
process-global stamp on every write: non-deterministic across threads (it
would have failed WP1f) and it stamped the two writes that reuse bytes on
purpose -- action 16's copy and action 17's stale write -- killing the dedup
coverage that exercises the engine's hash-match paths and making WP1a's
exemption count a permanent zero: R6's fourth trap, a control that changes
more than the thing under test. So every FRESH body now carries what makes it
unique in its own format (`saved {step} {device}`, `slot {n} from {device} at
{step}`, the landing-time save names its device -- each disk counted its own
rounds, so two disks wrote one body), the deliberate reuses are untouched,
and there is nothing to switch on.

**Every oracle fires, and every one that fired is named.** `workload_core`
runs the oracles as caught closures and the seed's verdict is the list --
`N oracle(s) fired [sealed_never_in_the_clear, no_entity_holds_both_sides_of_a_swap]`
-- so a seed WP2 turns green on the sealed oracle cannot read as "still red"
with the chain oracle unmentioned.

**Pin:** `the_chain_oracle_sees_two_files_trading_names` -- the AI repro (two
files, one device, no faults, names traded through a scratch name) with the
expectation inverted: it asserts the oracle FIRES naming both entities and
both bodies. On the engine as it stands it does (`scan::pair` rule 1). The
day AH's fix lands this test goes red with "the chain oracle did not fire",
which is the signal to flip it into AI's regression pin; it cannot pass
silently in either world. The frozen chaos seeds that pin other invariants
(4123847, 111201, 111120) are wrapped `red_only_on_the_chain_oracle`: they
must fire exactly that oracle and no other, asserted both ways, so AH's fix
surfaces there too.

**Readings, engine `df2f5c88`, harness uncommitted, all five ring arms.**
Reading 1 is the every-file sealed oracle (marks) with the bodies as they
were before WP1b; reading 2 is the same oracle plus the chain oracle with
WP1b's unique bodies. Paired under R4: same engine, harness-only change
between them, and any seed that changes verdict between them is a UNIQ
effect to read, not a number to quote. Every row sums to the arm.

    reading 1 (every-file sealed oracle; chain oracle not yet built)
    arm       seeds green sealed-only chain-only both
    clean2       40    36           4          -    -
    hostile2     30     2          28          -    -
    clean3       30    23           7          -    -
    kill2        30     1          29          -    -
    plat3        30     0          30          -    -

    reading 2 (every-file sealed oracle + chain oracle, unique bodies)
    arm       seeds green sealed-only chain-only both
    clean2       40    36           4          0    0
    hostile2     30     0           0          1   29
    clean3       30    23           7          0    0
    kill2        30     0           1          2   27
    plat3        30     0           0          1   29

For scale: the one-file oracle on the same engine read clean2 4, hostile2
17, clean3 7 (kill2 and plat3 were never in the named-pair table). The clean
arms are unchanged by the every-file oracle -- the same four and seven seeds,
every one the ring-file shape -- so the one-file numbers on the clean arms
were not wrong, only unproven. The hostile arms were undercounted: of
reading 1's reds, eleven hostile2 seeds, eight kill2 and three plat3 name
only bodies the workload sealed and never the ring file -- exactly the
seeds the one-file oracle could not see.

Verdict changes reading 1 -> 2, per ORACLE (a per-seed list missed two of
them, because their overall verdict stayed red; the per-oracle table is what
caught them). Chain oracle new: hostile2 74419 and kill2 75129 green -> red
on it alone, sealed unchanged. Three UNIQ effects on the sealed oracle, in
both directions, all on the same engine: hostile2 74428 sealed green -> red
(the ring body now stands at `Sub 28 renamed/Sub 14 (24) (24b)/contested
(conflicted copy … from desktop) 2.txt`); kill2 75122 and plat3 75406 sealed
RED -> green (reading 1 named the ring file -- a seed-specific body no other
write can produce, so not a two-origin false red -- standing at
`ring-2/ring-2.txt` and `Contested Folder/in-26-pc.txt`; real leaks in
reading 1's world that reading 2's world does not produce, the unique chaos
bodies having changed what the engine did with the same dice). As WP1a
deltas all three are UNIQ effects and not quoted; as rows of reading 2 they
are what that world is, and none may later be struck as an artefact.

Exemptions after unique bodies: 20 of 160 seeds exempt exactly one body,
none more; the one read by name (hostile2 74426: `Private/DOC-19.TXT ->
Sub 0 (8) (8b)/Copy of DOC-19.TXT`) is the copy arm doing its job. The
coverage line now names every exemption, so the post-commit re-run reads
all twenty by name. Chain coverage on the hostile arms: 209 / 210 / 297
pairs recorded (hostile2 / kill2 / plat3), all from the chaos name-swapper,
`folders=0` on every seed -- the proof by count that the ring trade never
separates a shared leaf today; `pairs_sealed` (pairs the oracle cannot
judge because the server holds ciphertext for them) lands with the
post-commit re-run.

One seed of reading 2 (plat3 75418) left an empty log on its first run --
no verdict line, and the process took no measurable time (the next seed's
log follows one seed-length later) -- and was re-run by hand on the same
binary (both oracles, 5 pairs held); the manifest says so. Open item below:
the runner then recorded no exit status, and a missing verdict must count as
a row, never as absent.

**Reading 2 is the WP2 baseline, provisionally.** It names engine `df2f5c88`
with the harness uncommitted; once the owner commits, the arms run once more
on the named SHA and that run is quoted as the baseline. Per WP1f the two
must match, which is the determinism check on real arms.

## WP1d -- the custody oracle, per FOLDER

`reference_estate_oracle_blind_to_custody` records that runtime state cannot
answer custody and that the only surviving handle is a server id learned by the
workload as it runs. That decision has sat as "needs a decision" since
2026-09-05. **Decided: build it, scoped to folders.** Custody is a folder
question. The workload learns each FOLDER's server id lazily at its existing
pass points (a store read draws no RNG; the draw-sequence fear is unmeasured),
and the oracle checks per folder id: the multiset of bodies the workload
intends under that folder is contained in the bodies the server holds under
that id. Bodies are unique per write except the copy arm, which is count 2;
conflict copies stay in their folder and do not matter. This sees AA, AB and
AC -- right bytes, wrong folder -- without finding any file by name or path. A
folder renamed before its creator's next pass has no id yet: skipped, and the
oracle says how many it skipped.

Lands beside WP2, baseline against the named pre-WP2 SHA. If observing ids
cannot preserve the draw sequence, the arms are re-baselined once and the spec
says so; the oracle is not skipped.

## WP2 -- directory identity -- IN PROGRESS from 2026-09-12

`drive_directory_identity.md` is the design and stands: directories get an id
the VFS reports, folder records store it, the readers ask it. Its rule -- *the
id funds order, never identity; it corroborates, never claims* -- keeps a
recycled or foreign id survivable, with the one exception decision 1 makes.

**The two decisions, made (c6 agreed 2026-09-12; the owner ratifies):**

1. **The empty-vault mirror: an ENCRYPTED folder's recorded id may CLAIM.**
   The directory carrying the vault's id is the vault wherever it stands and
   whatever it holds. Cost: a directory id recycled onto a plain folder after
   the vault was deleted turns that folder sealed -- visibly, with nothing
   published, protection raised. The alternative, a hold, stalls every rename
   of an empty vault, an ordinary action. Plain folders keep
   corroborate-never-claim. Pinned RED first:
   `a_renamed_empty_vault_stays_the_vault_when_its_old_name_is_reused`
   (today: 501 stays `Private` by path, `Plain` is minted plain, what the
   user saves into their renamed vault is published), AG's two pins, and
   the recycled-id pins (row 7 below).
2. **The no-id fallback: today's rules.** A restore, a re-created sync root,
   a volume swap, `file_id` 0 on Windows: the id is a cache of evidence and
   its absence is ordinary. A hold would stall every restore on its first
   pass. The residual, named: the no-id world keeps AG for one pass -- until
   the id is re-recorded on the next corroborated pairing -- and fix 3's
   contested-pool refusals stay for that world; WP3's table says so. Pin:
   ids wiped, one pass, AG's pin green from the second pass.

**Precedence: contents evidence by id evidence, one outcome per cell.** The
design's two sentences -- "a directory whose id belongs to a live folder
record is that folder, whatever its name or contents" and "corroborates,
never claims" -- are in tension, and this table is the resolution: an id
match is the strongest corroboration of a pairing; it claims on its own only
for an encrypted folder with no contents to speak (row 6). A row not in this
table goes in the table, not in the code.

    row  id evidence                          contents evidence            outcome                                        pin
    1    matches the directory standing at    propose another directory    paired at its path; the files elsewhere        AG (both provenances)
         the record's path                    (or nothing)                 moved OUT; at a vault edge that is a drag
                                                                           and converts
    2    none recorded                        any                          today's rules (decision 2); id recorded on     ids wiped, one pass,
                                                                           the first corroborated pairing                 then AG green
    3    recorded, no directory on the disk   any                          as row 2; id re-recorded. For the FILE rule    P1: ids wiped / disk
         carries it -- or 0 on either side    (or any file's parent)       too: an unknown or vanished id on either side  swapped, one pass,
         (Windows, handle would not open)                                  is NO evidence, never "changed" -- a naive     nothing converts;
                                                                           "parent id differs" reads a restore as every   P2: both ids 0, a
                                                                           sealed file dragged out and converts the       real drag converts by
                                                                           whole vault in the clear                       today's rules
    4    stands elsewhere                     propose that same directory  paired there: a rename                         with-file mirror
                                                                                                                          (green today, stays)
    5    stands elsewhere                     propose a THIRD directory    stand down: folder and files Unsyncable, one    row-5 pin (new)
                                                                           issue naming both readings; adopt neither
    6    stands elsewhere                     none anywhere (empty)        plain: today's standing-alone rule;            empty mirror (RED
                                                                           encrypted: claims (decision 1)                 today) + plain half
    7    recycled: the record's directory     none (the stranger holds     plain: no proposal, nothing happens; the       row-7 pins, both
         is gone and a stranger carries its   nothing of the record's)     record goes with its directory, the stranger   halves, on MemFs's
         id                                                                is minted new. encrypted: the claim lands on   reuse_file_ids
                                                                           the stranger, over-seals, publishes nothing

**Layer, three parts, each landed and gated on its own:**

(a) **The VFS answers for directories -- through a narrow surface.**
`DirEntry.fingerprint` answers for a directory in `read_dir` (Unix inode;
Windows file index through a handle opened with `FILE_FLAG_BACKUP_SEMANTICS`,
0 when it cannot be opened, as files do), and a new `Vfs::directory_id(path)`
answers for a path. `fingerprint(path)` KEEPS returning `None` for a
directory: twelve executor sites read `fingerprint(path).is_some()` as
"something stands here" and read `None` for a directory today, and
`make_room`'s own comment records the bug that once caused; changing that
contract would change every one of those branches at once. Size and mtime
are 0 for a directory and never read. `MemFs` gives directories ids in the
same map as files (allocated when a directory is made, carried by
`move_subtree`, released on removal, under the same reuse knob) so the
recycled-id world is reachable from a scenario. The volume is recorded the
way files record it -- personality-level, not per entry -- and is owed to
files and folders alike; the collision case is a junction inside the sync
root crossing volumes on Windows, bounded by corroborate-only for plain
folders and by over-seal for encrypted ones. Windows facts proved on the
test VM before release, not before the sim work: stable across rename,
distinct between two directories, 0 when the handle cannot be opened.
**Gate:** every seed of every ring arm leaves a byte-identical trace
(journal + plaintext trees, WP1f's instrument) before and after, plus the
scenario suite. A recording change that moves one plan line is a reader in
disguise.

(b) **Folder records store it.** `synced_fingerprint` on a folder entry
(the column exists; folders write `None` today) is set at the three places a
folder becomes bound to a directory: `create_local_folder` after its
read_dir check, the folder mint in the scan, and every match
`detect_folder_moves` makes. Same gate as (a).

(c) **The readers, each on its own bar, in landing order:**

    reader                       what changes                                        judged by
    corroborated(path)           true when the standing directory's id is the        AG's pins (both provenances),
                                 record's; a record whose id stands elsewhere is      the two mirrors, row 5, row 7
                                 not present at its path however full that path is
    crossing_a_vault_edge        converts only when the FILE's directory changed,    AG's pins (the drag converts;
                                 read as: the directory the file stands in now       the vault does not); P1, P2
                                 against the id of the folder record it is NOW       (no evidence is not change);
                                 under -- never a snapshot from before the           P3 (two devices: B's executor
                                 executor moved it; unknown or 0 on either side is   moved the copy for the server,
                                 no evidence; the second link of a hardlink is       B must not report a second
                                 ignored, the path the record holds is read          crossing)
    the candidate loop / mint    an id match is the strongest corroboration of a     assert_the_ring_ids_are_intact
                                 proposal the contents made; a directory whose id    on the ring arms; AF's
                                 belongs to a live record is never minted new        identity-survives assertion
    move_local's source          refuses a source directory whose id is not the      seed 74019 (AF route 2's
                                 record's                                            candidate)
    make_room                    asks whose directory it is clearing; the owner's    AF route 1's pin (the aside
                                 record follows it or the move stands down          carries the owner)

**The map against the tree (2026-09-12, end of day):**

    reader / rule                          state
    corroborated(path)                     LANDED, plus C1 (a vanished id is no
                                           evidence) and child-folder identity
                                           beside the parent's own id
    crossing_a_vault_edge                  LANDED as NotADrag (P1-P3 honoured)
    the encrypted claim (decision 1)       LANDED over missing, displaced and
                                           contested, with the remote_wants
                                           deferral (sequencing, not a guard)
    row 5 stand-down                       LANDED as the directory_disagrees
                                           hold, persisted through its issue,
                                           processed before any early return
    the candidate loop, id as strongest    LANDED IN ANOTHER FORM: corroborated
    corroboration for PLAIN proposals      already answers by id at the path;
                                           for a candidate elsewhere the
                                           child-folder rule is the only id
                                           evidence a plain folder may add,
                                           never its own id alone (the rm -rf
                                           reason); nothing else to land
    move_local's source                    LANDED: asks the directory at from
    make_room's owner check                LANDED IN ANOTHER FORM: the aside
                                           happens, the owner's record follows
                                           by the encrypted claim on the next
                                           pass (74033's pin); for a PLAIN owner
                                           the server's rename is applied from
                                           the aside (E1's two pins) -- a
                                           stand-down inside make_room itself
                                           was not needed and is withdrawn
    unmaterialize_and_park's folder arm    LANDED (not in the original map):
                                           asks the directory before disowning
    two records on one path                LANDED (not in the original map):
                                           identity decides, the other is
                                           evicted into the contested pool
    the no-mint hold                       LANDED for a live ENCRYPTED record's
                                           directory; plain: withdrawn, hold
                                           nothing lifts
    evicted-and-unmatched plain folder     WITHDRAWN: a fallback hold was
                                           built and livelocked plat3 75415
                                           (a held record blocking the
                                           create the server wanted at its
                                           stale path); today's reading, in
                                           the empty-plain residual

**The crossing rule's residual, stated:** a hardlink puts one inode under two
directory ids at once and "changed" has no single answer; the rule reads the
path the record holds and ignores the second link. The engine re-parenting a
directory around a file does not change the file's parent id -- a directory
move keeps its id and everything inside keeps its parent -- so the design's
sentence is satisfied by the rule as written; the hazards are the cases with
NO evidence (P1, P2) and the move the engine makes for the server (P3), and
those are pinned.

**Part (a) landed 2026-09-12 (uncommitted):** `Vfs::directory_id`,
`DirEntry.fingerprint` for directories, `Fingerprint::of_directory`, MemFs
directory ids in the file-id pool (root included, reuse knob included), unit
pins on both VFS implementations (stable across rename, distinct, `None` for
a file, `fingerprint` still `None` for a directory). Workspace suite green.
**C2 gate: 160 of 160 ring-arm seeds byte-identical** (journal + plaintext
trees) before and after, every run exit 0.

**Part (b) landed 2026-09-12 (uncommitted), one site:** `observed_dirs`
returns each directory's id (from the FULL listing, parks included, so a
parked folder's id counts as standing); `record_directory_identities` runs
after the folder pairing and writes `Fingerprint::of_directory(id)` on a
folder entry that has a settled placement and either no id or one that
stands nowhere on this disk -- and ONLY from the directory at the record's
own agreed path as it stood before the scan moved anything, never from a
pairing the contents rule made (else AG on an install with no ids yet would
cache the wrong directory for the life of the record). Every lookup by file
id says which kind it wants (`entity_for_file_id` = files; the plaintext
source, `agreed_here` and the keep closure filter to files). Pins: the
record knows its directory on every device, keeps it across a rename, the
folder that stood still keeps its own, a restore (`renumber_every_id`)
re-records with no move read into it; the namesake park leaves each record
on its own directory; ids wiped then the AG drag leaves the vault NOT
carrying the new folder's id. C2 gate: recorded below when it lands.

**Part (c), first two readers landed 2026-09-12 (uncommitted):**
`corroborated(path)` answers by identity when both sides know it; an
ENCRYPTED missing or displaced folder whose id stands at an untracked
directory claims it (decision 1); a folder whose id stands at a third
directory while its files propose another stands down -- present, unmoved,
the id's directory held from adoption, issue `directory_disagrees` naming
both readings, the hold persisting through that issue until the id stands at
its own path again or nowhere (without the persistence it lasted one pass:
the files, once placed elsewhere, proposed nothing and the folder read as
deleted). `crossing_a_vault_edge` answers `NotADrag` -- not converted, not
moved, one issue -- when a FILE stands in the very directory its agreed
parent folder owns and the scan put that folder on some OTHER directory this
pass; a parent with no directory this pass (it crossed the edge itself, a
claimant stands in) is not evidence, nor is an unknown or 0 id on either
side. Designed behaviour that changed, and its pins split in two: the
ambiguous empty-vault stand-downs (`two_empty_vaults_leaving_at_once…`,
`an_empty_vault_leaving_beside_two_new_folders…`) now resolve by identity
where the ids are known (B is told apart by its directory, no issue) and
stand down as before where they are not (ids wiped first). AG both
provenances, the empty mirror, row 5, row 7 both halves, P1-P3 and the
ids-wiped pin are all green; the three that were RED are un-ignored in the
same change.

**Part (c), the next two readers, landed 2026-09-12 (uncommitted):**
`move_local`'s source asks the directory at `from` its id: this folder's own
id means the move runs whatever a lagging record says; a different id means
not this folder's; either side unknown falls to the record-based guard as
before. The old guard alone refused the server's move of a plain ring its
own directory every pass on clean2 74033 (its record lagged the user's
trade), and the world never settled. And the mint: a directory whose id
belongs to a LIVE ENCRYPTED folder record is never minted as a new folder --
it is held (`FolderScan::held`) and the claim takes it from wherever the
room-making leaves it. The claim also takes the CONTESTED pool (a stranger
full of somebody else's files at the vault's old path is evidence about the
stranger) and defers to `remote_wants` -- when the server is already moving
another folder onto the candidate's name, the remote move lands first and
the vault is claimed on a later pass. That deferral is a sequencing rule, not
a guard: two moves cannot both take one slot in one pass, refusing the claim
for a pass decides which lands first and never decides identity, and the
claim lands afterwards from wherever the directory ends up (the same
standing fix 3's refusals have under decision 2). Unrefused it produced a
2000-pass livelock on 74033, and with the mint unheld the vault's directory
was minted plain in the same pass and the claim never had a directory left
to take. **Result: the four clean2 seeds the reset named as what WP2 owes
(74023 74031 74033 74035) are GREEN.** Pin
`a_vault_whose_directory_stands_where_the_server_wants_a_plain_folder_keeps_its_identity`
(the laptop rotates the rings so the vault wears `ring-2`, the desktop swaps
the two plain rings, the desktop's trade lands first): RED on `df2f5c88` with
74033's exact leak ("ring-2 (conflicted copy … from laptop)/sealed.txt" in
the clear), green now -- the vault keeps its id and protection under a
conflict name, the plain folder wears `ring-2`, the world settles with only
`kept_aside` and the server-won race reported.

**Plain folders: corroborate-never-claim stays (c6, 2026-09-12), and what
corroborates widens.** The no-mint hold is scoped to ENCRYPTED records'
directories, because only they claim; held for plain records it was a hold
nothing ever lifted. Instead a known child FOLDER counts as contents, by
identity, beside the parent's own id: a folder whose own directory stands at
a candidate AND whose child folder's directory stands inside it is that
folder renamed, whatever its subfolder is now called and wherever its files
sit inside that child (`A -> X`, `X/B -> X/C`: Defect Q's shape one level
deeper). The child alone never counts -- the user moving `B` into a
brand-new `X` and deleting `A` puts `B` under `X` just the same, and pairing
on that carries `A`'s grants onto a folder the user made fresh; the
parent's id at `X` is what tells the two apart. Why not a displaced-plain
claim, for the owner: its failure is `rm -rf A; mkdir B; mkdir A`, which on
ext4 hands `B` `A`'s inode as a matter of course; the record would claim
`B`, `A`'s sharing would land on a folder made for something else, and the
deleted files would read as `A` having moved -- a sharing leak onto an
unrelated folder, reachable by an ordinary shell command, worse than
history left on a shell. **The plain residual, stated for the owner to
ratify:** a PLAIN folder with nothing inside it, renamed while its old name
is rebuilt, keeps today's reading -- the rebuilt shell keeps the record, the
renamed empty directory is minted new -- and its sharing and history stay on
the empty shell. It needs an empty folder, a rename and a rebuild of the old
name together.

Designed behaviour that changed with it, its pins split in two (ids known /
directories unknown, files known): `renaming_a_folder_and_its_subfolder_
together…` and `…with_the_old_name_rebuilt…` -- `A` follows its directory to
`X` keeping its identity, the rebuilt `A` is a new folder; with the
directories unknown the older decision holds (`A` trashed, `X` minted).
`a_folded_provisional_parent_still_receives_the_move_into_it` now finds
nothing to fold (`A` is not re-minted) and stays as the lost-answer costs
nothing.

**Also landed with the readers:** `unmaterialize_and_park`'s folder arm asks
the directory before disowning a record (a lagging record said another
folder stood at the vault's path, the arm cleared the vault's placement and
id, and the next scan minted its directory plain -- clean2 74037); and two
records resolving to ONE path (a record that lags a name trade beside the
one the server has since put there) no longer lose one of them from the
scan: the record whose id the standing directory carries keeps the path,
the other -- or the one whose id is known to stand elsewhere -- is evicted
into the contested pool and found from there (74037 again: the vault
vanished from the scan, neither present nor in any pool, and was
re-materialized as a fresh directory while its own stood orphaned). Where
identity cannot say, today's reading (the later record takes the path).

**Reading 3 -- engine df2f5c88 + the readers through the eviction (child-
folder corroboration not yet in), every-file sealed oracle + chain oracle,
harness uncommitted, all five ring arms.** The first numbers on this estate
produced by removing a cause rather than adding a guard:

    arm       seeds green sealed-only chain-only both other(+converged)
    clean2       40    40           0          0    0     0
    hostile2     30     0           0          1   26     3
    clean3       30    29           0          0    0     1
    kill2        30     0           1          1   22     6
    plat3        30     0           0          1   24     5

    against reading 2:  clean2 4 -> 0 red, clean3 7 -> 1, hostile2 30 -> 30,
                        kill2 30 -> 30, plat3 30 -> 30

Per oracle, reading 2 -> 3. Sealed red -> green: clean2 74023 74031 74033
74035 (the four the reset named), clean3 74804 74813 74818 74820 74822
74827, hostile2 74424, kill2 75123, plat3 75402 75409 75424. Sealed green ->
red, two, both the AH shape (a sealed body adopted into an ordinary
workload file's identity -- cause 2, scan rule 1, the owner decision this
reset does not make): kill2 75129 (`Private/doc-25.txt` now at
`Sub 5/stale-9.txt`), plat3 75406 (the ring body at `Contested
Folder/in-34-disk.txt`); the folder readers changed what the engine did with
the same dice and AH found a new victim on each -- SHOWN by the swap-off run
(c6's discriminator: AH's verb is the name swap; with `NOSWAP=1` scan rule 1
has nothing to adopt): both seeds GREEN on the readers binary with the swap
verb off, red with it on. **The WP2 bar is amended: no seed moving green to
red except by cause 2, shown by the swap-off run**, with these two under it.
Chain: no green -> red;
red -> green kill2 75123, plat3 75409, 75424 -- the chain-only column did
move, downward, on seeds whose folder handling changed; recorded, not
claimed as WP2's doing. Converged green -> red, twelve: clean3 74800,
hostile2 74414 74424 74426, kill2 75100 75101 75105 75110 75124, plat3
75412 75415 75428 -- leaks turned into holds where the crossing reader and
the row-5 hold refuse to act on a misreading; each carries its open issue
kinds (E2) in the table below when the re-run with issue kinds lands, and a
non-converged seed with NO open issue is a livelock and a finding. Issue
kinds at the end (re-run on the same readers plus the ISSUES line): every
one of the twelve carries open issues -- `withdrawn` 4 to 18 per seed
(NotADrag and OutOfReach), `kept_aside` 5 to 31, `reconcile`,
`directory_disagrees` on 74800 74414 75110, `rescued_from_trash` on three,
`parked` on 75110. No livelock among the twelve.

**Reading 4 -- the readers-4 binary: readers-3 plus one change, child-folder
corroboration (the plain-folder rule); same oracles, same engine SHA; paired
with reading 3 under R4:** per seed and per oracle IDENTICAL to reading 3 on
all 160 seeds -- which also stands as WP1f's check on that change (same dice,
same trace on every seed the rule does not touch) -- -- no verdict changed in either direction on any
oracle -- and plat3 75415 sits in the converged column (settles on this
binary; the E1 hold that livelocked it was never in it). The child-folder
rule moves no ring-arm verdict, as expected of a rule about plain folders
paired by their subfolders: the ring trade is a rename the ring walk
already reads.

    reading 4: clean2 40/40/0/0/0/0; hostile2 30/0/0/1/26/3;
               clean3 30/29/0/0/0/1; kill2 30/0/1/1/22/6; plat3 30/0/0/1/24/5

**A rule the row-5 check taught, general:** a record with a known directory
is never re-created at its remote name while that directory stands,
whatever verdict naming reaches about the name.

**Open after reading 3 (c6, 2026-09-12), before WP2 is called done:**

- The twelve converged reds are WP2's open finding. `withdrawn` dominates,
  and NotADrag's comment says "left for the folder scan to put right"; on
  these seeds it never does -- a hold nothing lifts is the campaign's cost
  in a new coat. Trace one seed per arm (the highest withdrawn count:
  hostile2 74424 at 14, kill2 75100 at 9, plat3 75412 at 18) to the folder
  the crossing reader says was misread and say why the scan does not put it
  right. Two classes: a reader gap (the folder's id stands somewhere and no
  reader follows it) -- fixed before the next reading with a pin; or an AH
  victim (the folder's directory itself adopted by path pairing) -- named as
  AH residue with the seed. Each of the twelve gets a one-line
  classification, and **the WP2 bar gains: no seed converged green to red
  without a classified hold.**
- plat3 75415: converged-red with issues on the readers-3 binary; on the
  readers-5 binary it NEVER SETTLED. Bisected in two runs: readers-4
  (child-folder corroboration, no E1 branch) settles; readers-5 (the E1
  fallback hold) does not -- the held record kept a placement at a path it
  did not own, `move_local 503: /sync/ring-2 is not this folder's directory`
  and `create_local_folder 504: /sync/ring-2 belongs to another folder`
  overtaken every pass, and a plain folder may not claim so nothing could
  lift it. THE E1 FALLBACK HOLD IS REMOVED; with it removed 75415 settles
  again (R7: the fix shown to be one thing). The evicted-and-unmatched
  plain empty folder is therefore today's reading -- read as deleted, its
  directory minted new -- and that shape is inside the empty-plain residual
  stated above. The reader map's last row reads "withdrawn: livelocked
  75415" accordingly. **The rule it teaches: a hold may hold the record's
  OWN directory, never a path; a record kept at a path it does not own
  blocks whoever the server sends there, and nothing lifts it.** Checked
  against the one hold still standing, row 5: the pin is extended with a
  server-side folder sent to the held record's old path before the user
  resolves, and on the first run it did not land -- NAMING judged the held
  record as holding that name and parked the peer's folder as a duplicate,
  the fourth site of the lagging-record family. Naming now leaves a HELD
  record (open `directory_disagrees`) out of the competition for the name
  its record still says; asked of the issue, not of the disk, because a
  record whose directory stands elsewhere for one pass during the user's
  own trade is mid-cycle and naming's park carries that trade (skipping by
  disk broke the case-twin swap pin). The peer's folder lands, the hold
  keeps its issue and its directory, the user deletes the empty held
  directory and the hold lifts. The pin builds the server state the way the
  real server can produce it -- another device renames the old folder away,
  then creates the peer's folder at the name through the real endpoint --
  because `seed_folder` bypasses the mock's `name_taken` refusal and two
  live siblings with one name is a state the real server refuses. With the
  rename in the world, the user's delete of the held directory meets the
  server-side rename and the engine's standing rule applies (the change
  wins, `DeleteLostToEdit` said): the folder comes back empty under its
  new name; the pin asserts that. With the naming skip reverted the pin is
  RED -- not on the peer's folder landing (it lands either way in the
  reachable world) but on the hold: the duplicate verdict made the held
  record give up its stale placement and re-created it as a fresh directory
  under its new server name while its own directory stood orphaned, the AJ
  family. The skip's pin is therefore "a held record is not re-created
  elsewhere over its own standing directory". A held plain directory renamed by the user
  to a FRESH name stays held until deleted or put back -- a plain folder is
  not followed by its id alone -- and that is stated as the plain
  residual's cost.
- E1's second shape is a finding, not an acceptable outcome: `R`'s local
  directory trashed to the OS trash because `P`'s record lagged by one pass
  -- the lagging-record family a third time, now in NAMING: two records
  wanted the name `P` and naming called them duplicates without asking
  whether `P`'s record still holds a directory at that path (it does not;
  its id stands elsewhere). Naming's duplicate check asks identity the way
  `move_local` and `unmaterialize_and_park` now do; then `R` lands at `P`,
  nothing parked, nothing trashed, and the pin asserts that ONE outcome.


**Also in WP2, its own commit and its own four lines:** the upload-link gap.
A file this device WROTE never gets its inode-to-entity link; only a download
writes one, because the scan caches the hash first with no entity and the
upload takes the cached branch. Six lines, measured harmless, and it is what
the rescue's blindness rests on.

**The identity spec's "What lands before it"** (the hold for an encrypted
folder paired by contents alone) is SUPERSEDED by decisions 1 and 2 -- the
id world decides by identity, the no-id world runs today's rules -- and the
identity spec says so as of today. `empty_and_encrypted` in `pass.rs` is a
different arm (a missing vault beside a new empty directory) and stays.

**Done means:** the pins above green, each proved RED without the fix first
(AG both provenances and the empty mirror are RED on `df2f5c88` as of
2026-09-12: 501 renamed onto `Plain` with a plain 502 wearing `Private`; 501
kept at `Private` by path with `Plain` minted plain); every arm's red count
on the every-file oracle at or below the post-commit reading-2 baseline with
no seed moving green to red; the chain-only column not moving (WP2 does not
touch scan rule 1, so a chain change is a finding about WP2); and the belts'
fire reports read as WP3 requires below. **A seed still red after WP2 is a
finding about WP2, written up as such, never a reason for a seventh belt.**

## WP3 -- the belts, each on its own bar

Zero fires is necessary and not sufficient, and wrong for two of them. Every
belt reports the SHAPE it fired on (R8); a bare count cannot tell a legitimate
fire from a confused one.

    rescue net (fix 1)     KEEPS its legitimate shape: action 15 deletes any
                           folder but the root, vault subfolders included, so
                           a user deleting a vault subfolder holding a
                           not-yet-uploaded sealed file fires it CORRECTLY.
                           Bar: fires only on that shape.
    mint guard (fix 2)     already out; bar is the B1 pin.
    keep-record (fix 4)    bar: zero fires across every arm after WP2.
    fix 3's refusals       bar decided by WP2's no-id fallback, above.
    subtree refusal        not a belt; stays.

Each removal is its own commit with the arm re-run after it. A belt whose
removal moves a number goes back in with the seed named.

## Process rules, effective now

The measurement discipline stays -- measure before build, bisect on the same
seeds, one hunk per checkpoint; it caught today's regression before it
shipped. These are the changes.

**R1. The first review question on any sync-engine change is: does this fix a
cause or guard a consequence?** If "guard", the second question is why the
cause is not being fixed, and "the cause fix is bigger" is not an answer. A
guard lands only with the cause named and its ticket open. Nobody here blocks,
so R1's answer goes to the owner as one line: *guard; cause is X; landing
anyway because Y.*

**R2. A guard scoped to avoid breaking a designed behaviour is a guard.**
"Scoped to sealed files" was presented as a principle. It was the belt
shrinking to fit around the tests it broke. Those tests were the design
speaking.

**R3. No number without the oracle that produced it and what it cannot see.**
"The sealed oracle (one file by hash)" beside every figure would have kept
months of them honest.

**R4. Before and after on the same seeds against a named commit pair.** Today's
baseline silently contained the fix for an hour because the owner committed
between two runs. Name the SHA.

**R5. The reviewer sees the approach before the patch**, and the request
arrives as four lines: the cause in one sentence, the layer proposed, the
alternative rejected, the instrument that will judge it. Not the diff. A
patch reviewed sound on top of an approach nobody was asked about is how this
week happened.

**R6. The five measurement traps are checked before a number is quoted:** a
dial whose values add instead of choose; an exclusion list with the same
defect; a flag read through an Option that reports false for absent; a control
that changes more than the thing under test; a probe field whose meaning
differs by branch (B10: `content` is a hash when `enc=false` and a random id
when `enc=true`).

**R7. A fix is re-run with its neighbour's bug REMOVED.** Today's regression
was fix 2 catching fix 3's state. The memory for that existed; the rule did
not.

**R8. Every belt reports the SHAPE it fired on**, never a bare count.

**R9. Every designed behaviour a belt touches gets a pin, run RED with the
belt in, before the belt lands.** B1 is what happens without this.

## Tests

- B1: `a_sealed_file_dragged_into_a_brand_new_folder_still_converts` -- RED on
  87ca2389, GREEN on 87ca2389^ and after fix 2 is removed. Written; lands with
  the removal. Asserts the invariant only: the new folder reaches the server
  and the body is under it in the clear. (Its first draft also asserted the
  absence of an issue kind that no longer exists -- mechanism, could never
  fire -- and that assertion is gone.) Covers the downloaded provenance; c6's
  probe covered the locally written one; both were red on 87ca2389.
- WP1f: two runs of one seed, one journal -- `one_seed_leaves_one_trace`, landed.
- WP1a: a workload-written sealed file (not the ring file) published; RED
  before 1a, with the reason it was green before in the pin's comment --
  `the_sealed_oracle_sees_a_file_the_workload_sealed`, landed.
- WP1b: the swap chain oracle's own pin, RED on the pristine engine --
  `the_chain_oracle_sees_two_files_trading_names`, landed (asserts the fire).
- WP1d: a file keeps its bytes and loses its folder; GREEN on today's oracles
  (blind is the point), RED on the custody oracle for the right reason.
- WP2: the identity spec's pins plus the two decisions above, each RED without
  the fix before it is kept.
- WP3: each belt's shape report, in the commit that removes it.

## Open

- The AH owner decision (crossing + `mine4`, or nothing) is outside this
  reset and blocks nothing in it; it is named so the hostile arm's residue
  after WP2 is read correctly.
- WP1d's draw-sequence cost is unmeasured; the fallback is one re-baseline.
- plat3 75418, reading 2, first run: no verdict line, near-zero runtime,
  cause unknown (`scratchpad/wp1a/reading2/manifest.txt`; the host's kernel
  log shows no OOM or kill at 16:15). The runner now keeps the whole output
  and the exit status of any run with no verdict and the table counts it as
  `no-verdict`, a red-unknown row. The post-commit re-run reproduces it or
  closes it.
- The chain oracle's blind share: `pairs_sealed` on the coverage line lands
  with the post-commit re-run and goes into the WP1b section as the oracle's
  stated coverage (4 of 7 on hostile2 74423 at first sight). It is the
  argument for a decrypting reader of sealed versions later, not now.
