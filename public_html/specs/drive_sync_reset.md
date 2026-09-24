# Drive sync: the reset

**Status: in progress. WP1f, WP1a, WP1b DONE 2026-09-12; WP2 (directory
identity) landed in two units -- `4466080e` and `5a347458` (reading 7, the
post-commit re-run: identical to reading 6 per oracle and byte-identical
traces); WP1d (the custody oracle) DONE 2026-09-13, harness only, findings
C1-C3 recorded below (WP1d committed as `1581721c`); WP3 in progress: change
1 (`aba4bd60`, naming waits on a chain that runs into an open op) closes C3,
change 2 (`20c431b5`, the round brings nothing in under a folder the user has
just deleted) closes C2; the belts' instrument (`d5f939ab`, reading 11)
finds fix 4's bar met and finding C4; change 3 (`a5638dc9`, a recycled
directory id under a folder's own path is void) lands C5; change 4 (a folder
record knows its directory from the mint) closes C4, next in the chain C4,
C6, fix 4's removal, C7, each measured and graded. C10 + C11 (a device puts back only its own
park; a scratch name is not a placement) NEEDED and VALID 2026-09-22. Every
change reviewed approach-before-patch: public-html-25 from 2026-09-22
(public-html-67 from 2026-09-14, public-html-c6 before that).**

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

## WP1d -- the custody oracle, per FOLDER -- DONE 2026-09-13

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
**Landed 2026-09-13 (harness only; c6 graded traced).** The oracle is
`assert_every_file_is_in_a_folder_the_user_put_it_in` in `zz_sweep.rs`, fed by
a `Custody` ledger the workload keeps as it runs:

- A folder is held by `(device, directory birth)`. `MemFs` mints a birth per
  directory (once, in creation order, never reused; carried by rename, dropped
  by remove: `birth_of`, `path_of_birth`). A birth and not a directory id
  because the chaos arms recycle ids (`reuse_file_ids`), and a handle keyed by
  id would expect the folder that inherited an id to hold the dead folder's
  files.
- A handle is LEARNED once, at the first pass point on that device after the
  directory was written into, from `scenario::folder_record_at`: the
  non-provisional, undeleted folder record whose local placement resolves to
  the directory's current path (comparison keys; `Root` for the sync root).
  Where two records resolve to the path and the directory's identity picks one
  (`synced_fingerprint` = `file_id_of`), that one; where it picks none,
  `Several` and the handle is not learned: `deferred` at a pass point,
  `undecided` at settle, and every body under it goes unjudged. Never
  relearned, so a later mis-pairing shows as files under the wrong id instead
  of being absorbed. A store read only: no dice, no engine call. Every
  directory standing at the start (root, `Private`, the rings) is a handle
  learned before the first step, and every file standing then is an intent.
- Intent is per FILE (the path at creation, followed through the workload's
  renames, moves, slot swaps and rotations, aliases kept for the other device's
  spelling): a file's candidates are every folder the workload ever placed it
  in, on any device; the bodies are `(sha256, file)`. Per-file union rather
  than per-body latest because one legitimate race would fire otherwise: E
  edits `p` in F1 while D moves `p` to F2, and the edit rightly follows the
  entity to F2. Bodies the chaos hooks wrote are attributed after settle to the
  file at their path (adds candidates only); the chaos name-swapper's recorded
  pairs union the two files' candidates and carry an unknown across.
- The check, after settle, over every LIVE server file (plain: the stored
  hash; sealed: decrypted as the owner, `open_as_the_owner`): the folder it
  stands in is in the union of candidates of every intent carrying its body.
  A fire is sorted before it is called misplaced: `rescued` (a candidate is
  trashed on the server and the file stands under an ancestor of it -- the
  rescue net's designed move), `reminted` (on some device the folder it stands
  in resolves to the directory a candidate was learned from: one birth, two
  ids, the row-6 residual measured), else `misplaced`.
- Blind, and the line says so: `bodies_unknown` (none after attribution),
  `unresolved` (a body with a never-learned candidate), `multi_candidate`
  (moved between folders by the user: cannot discriminate), `late` (learned
  after settle: the engine's final belief), `deferred`/`undecided`,
  `sealed_unopened`. A directory learned after an engine mis-pairing inherits
  that belief. The workload never crosses a vault edge with a folder
  (`same_side_of_the_vault` refuses arms 7 and 14), so no folder-conversion
  custody fact is reachable; the chaos file swapper can cross, files only, and
  those bodies carry both sides' candidates. The ring rotation counterfeit
  (folder id stays at the name, files cross) reads as misplaced here, which is
  the finding it would be.
- The workload makes no server-side placement (every arm is a disk action; the
  one server-side hook renames a folder during creation), so the ledger takes
  no server id directly; an arm that ever does `drive_move` by hand adds that.

**Pin:** `a_file_that_keeps_its_bytes_and_loses_its_folder` -- `keep.txt` put
in `Docs`, the server moves it into `Other` by the harness's hand, every device
takes the move; converged, no stranded entry, no live orphan all green (blind
is the point), the custody oracle fires once naming the file and both folders.

**Finding C1 (frozen seed 3072116, `Vault::Shared`, mac + pc, no faults, no
chaos):** `slot-3 (conflicted copy from mac) 2.dat` stands in `Private`; the
user kept it in `Private/Shared`. Site: `scan::pair` rule 1 (same path,
whatever the inode). mac's record for 902 still had local path
`Private/Shared/slot-3.dat` (the server-side move of 902 to `Private` not yet
applied on mac); the user's slot swap put another inode there (the former
`slot-2.dat`, body 7714) and rule 1 read it as an EDIT of 902. Then
`ApplyRemoteMove` carried that inode to `Private/slot-3.dat` and
`preserve_local_as` (parent = `entry.remote.parent`) made the conflict copy
beside the entity, one folder up from where the user had it. The move and the
copy are the engine acting consistently on a wrong pairing; there is no
independent cause at the conflict-copy site. Rule 1 paired inode 1016 to a
record whose `synced_fingerprint` said 1015: the AH shape with the identity
already in the record, which is what makes it WP3's and not a new letter.
The seed is wrapped `red_only_on([every_file_in_a_folder_the_user_put_it_in])`
naming C1; when C1 is fixed the wrapper comes off. Not fixed in this unit: the
oracle is an instrument.

**Finding C2 (kill2 75112 and 75115, read from the journal, not probed):**
`contested.txt` stands in `Contested Folder` 510; the user put it in
`Contested Folder` 506, which pc deleted at step 27. pc's pass then plans
`TrashRemote 506` AND `CreateRemoteFolder -6 "Contested Folder"` with
`contested.txt` under it: between the user's delete and pc's next scan a
download of 506's file landed on pc and rebuilt the directory on the way, so
the scan met a new directory holding a file and made a new folder of it. mac
then trashed its own 506 (rescuing four never-uploaded files to the root) and
downloaded 510. The user's delete is undone for one file, in a folder nobody
made. 75115 is the same shape (`in-9-pc.txt`, 511 for 507). kill2 only
(chaos + kills); not seen on hostile2 or plat3 in reading 8; whether the kill
is needed is untraced. Goes to WP3's list beside the kill-arm resurrection
shapes.

**Finding C3 (kill2 75112 with swaps off, `r8swapoff`):** `ring-3.txt`
stands at the root; the user put it in `ring-2` (504), which the server never
trashed. The device's `rescued_from_trash` issue names folder 504 and the
file: the rescue net fired on the engine's own local trash of a plain ring
directory, not on a user deleting a vault subfolder. That is the shape WP3's
bar for the rescue net (fires only on its legitimate shape) exists to catch,
seen by an oracle for the first time: the custody oracle reads it as
misplaced because the candidate folder is not trashed on the server. Sharper
(c6, from the journal): no plan on either device ever carries a `TrashLocal`
for 504 -- its ops are `CreateLocalFolder`, `ApplyLocalMove` on pc,
`ApplyRemoteMove` on mac, then `CreateLocalFolder` AGAIN on mac at its next
pass. The rescue net fired from inside another operation, not from a planned
trash, and mac re-made the directory afterwards. That is WP3's lead.

**Readings (2026-09-13).** R4 pair on ONE engine, `5a347458`: reading 7 =
`zz_sweep.5a347458` (no custody oracle; also the WP1f post-commit re-run of
reading 6: identical per oracle, traces identical to `tr_follow` 160 of 160)
against reading 8 = `zz_sweep.custody` (7c5ec633cef8). Traces byte-identical
160 of 160 (`tracediff` on `tr_5a347458` vs `tr_custody`): the ledger draws
nothing. `delta.sh` 7 vs 8: sealed, chain and converged unmoved on every seed;
only the custody column moves. Reading 8-base = the same harness on the
`df2f5c88` engine (worktree, HEAD `jd-sim` + `jd-vfs`, `zz_sweep` only;
`zz_sweep.custody_base` afd3453e9769), the baseline the section above named,
quoted as custody fires INCLUDING learn-time mis-pairings (that engine pairs a
local folder made under a name the server was bringing as the server's folder
by path until naming parks it, and the learn reads that).

    reading 8 (5a347458)     seeds fired checked multi unresolved late rescued reminted misplaced
        clean2                40     0     530    48      21     120     0        2         0
        hostile2              30     1     731   328      13     108     0        3         7
        clean3                30     0     411    43       8     119     0        0         0
        kill2                 30     6     756   293      16      68     7       16         7
        plat3                 30     5    1303   422      34     171     0       21         7
    reading 8-base (df2f5c88)
        clean2                40     0     530    48      21     120     2       21         0
        hostile2              30     0     756   336      14     110     1       55         0
        clean3                30     0     409    43       8     119     1       17         0
        kill2                 30     4     756   297      18      69     7       39         7
        plat3                 30     2    1359   450      30     173     1       68         2

deferred and undecided are 0 on every seed of both readings; bodies_unknown
and sealed_unopened 0. Per-seed rows: `custody_seeds.sh` on
`scratchpad/wp2/reading8` and `reading8base`.

Read: the clean arms are custody-green on both engines (the oracle sees no
right-bytes-wrong-folder in a calm world). `reminted` -- one directory, two
server ids -- falls from 200 files on `df2f5c88` to 42 on `5a347458`: WP2
stopped the engine minting a new folder for a directory it already had a
record for, which is what the twelve converged reds and the leak signature
were. Fired seeds, reading 8: hostile2 74424; kill2 75101 75111 75112 75115
75121 75124; plat3 75401 75409 75412 75415 75426. Swap-off (`NOSWAP=1`, same
binary, `scratchpad/wp2/r8swapoff`): TEN of the twelve are custody-green with
the chaos name-swapper off -- AH residue, the same shape as C1 below (a ring
file's conflict copy standing in the neighbouring ring: rule 1 pairs the
swapped inode to the entity, the copy is made beside the entity). The two
that stay red with swaps off are 75112 and 75115: finding C2. Reading 8-base
fires on 75111 75112 75123 75124 75415 75421; the pair 8-base -> 8 is not a
G->R claim about WP2 (the baseline's fires include learn-time beliefs) and is
quoted only as the instrument's first two readings.

**Blind, by count:** `unresolved` 92 files on reading 8 (a directory the user
deleted before a pass on that device could read its record, or whose create
never completed under faults: judged nowhere); `multi_candidate` 1134 of 3731
files checked (moved between folders by the user or exchanged by the chaos
swapper: the folder is one of several, so a wrong one among them is not
seen); `late` 586 handles (learned after settle, from the engine's final
belief). And `rescued` trusts the server's trashed flag whichever hand set it:
a folder the engine trashes locally by itself and then `TrashRemote`s reads as
rescued, not misplaced, so the rescue net's WP3 bar (fires only on its
legitimate shape) needs its own instrument -- the `rescued_from_trash` issue
naming a folder the user never deleted. C3 was seen only because 504 stayed
live on the server. The oracle asserts on `misplaced` only; `rescued` and
`reminted` are sorted counts that never fire (a reminted file is in the
user's directory under a second id, which is converged's business; a rescue
above a trashed candidate is the net's designed move).


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
                                                                           the stranger, over-seals, publishes nothing.
                                                                           Also through the tracker skip (2026-09-13):
                                                                           a never-materialized tracker whose name the
                                                                           stranger wears stays unmaterialized while
                                                                           the stale owner lives -- the one-pass window
                                                                           (a folder deleted here, inode reused before
                                                                           its delete is confirmed) clears when the
                                                                           owner is remote_deleted; the residual is
                                                                           row 7's, named here for the next reader

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
those are pinned. The same no-evidence residual holds for the rename-race
policy's disk follow (2026-09-13): it moves the entity's own directory or
file by identity, and where the id is unknown on either side (a Windows
id of 0 under P2, or a record that never learned its directory) it moves
what stands at the planned path -- so the 74023 leak is closed by identity
and open exactly where identity is absent, like row 6's and P2's.

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

**A second recording site, the move landing (2026-09-13, with E1-2):**
`move_local` records the id it read at the SOURCE before the rename (the
directory it verified as this folder's and then moved itself) as the
folder's identity when it lands, and asks the destination only where it did
not do the rename. Consistent with the rule above -- the record's agreed
placement WAS the source, the engine checked the directory there and moved
it -- and required by it: `fingerprint(dest)` is `None` for a directory, so
the landing was throwing a known identity away on every folder move, and
the scan re-records only at the agreed path on the NEXT pass. Two folder
ops in one pass (a move, then room-making at the same name) met the moved
directory in between with no id on its record, the follow found no owner,
two records named one path, and the one read as gone was trashed with the
other's file inside (`a_second_folder_conflict_at_one_name_gets_its_own_name`,
which is now that landing's pin and the follow's).

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

**Committed as `4466080e` (2026-09-12).** The post-commit re-run on that SHA
matches reading 4 per seed and per oracle on all 160 seeds of the five arms
-- WP1f on real arms, and the named-SHA baseline reading 5 is paired with.

**Readings 5 and 6 (2026-09-13), the R4 chain from `4466080e`.** Frozen
binaries, one change per pair: `zz_sweep.e12policy` (the ring by identity,
the path map's root form, the follow at the aside sites, E1-2 and its two
findings, the rename-race policy), `zz_sweep.uplink` (+ the upload link),
`zz_sweep.tracker` (+ the tracker rule, the create-site refusal removed),
`zz_sweep.follow` (+ the policy's disk follow by identity). Trace pairs:
e12policy -> uplink 159/160 identical (plat3 75412, the rescue's absence);
uplink -> tracker 158/160 (kill2 75121: the server's move applied to 503's
own directory instead of a fresh re-creation; plat3 75415: no mint, the
file into 502 -- both the tracker rule's designed effect); tracker ->
follow 148/160 (the twelve differing seeds all carry the leak's signature
on the before side -- a conflict-named folder or file minted new and the
misrenamed entity trashed: clean2 74023/74031/74035, clean3 74804/74818/
74820, hostile2 74402/74410, kill2 75109/75129, plat3 75409/75426).

Reading 5 on `zz_sweep.tracker`: clean2 39/40, clean3 29/30, hostile2 /
kill2 / plat3 0/30; against reading 4, sealed G->R clean2-74023
clean3-74820 hostile2-74424 kill2-75123 plat3-75402 plat3-75409, chain G->R
kill2-75123 plat3-75409; swap-off on the same binary: 74424/75123/75402/
75409 green (AH), **74023 and 74820 RED -- the finding recorded under the
policy entry (the disk follow), fixed and pinned.**

**Reading 6 on `zz_sweep.follow` (supersedes reading 5):**

    arm       seeds green sealed-only chain-only both other no-verdict
    clean2       40    40           0          0    0     0          0
    hostile2     30     0           0          1   28     1          0
    clean3       30    30           0          0    0     0          0
    kill2        30     0           1          1   28     0          0
    plat3        30     0           0          1   28     1          0

Against reading 4 (`4466080e`): sealed G->R hostile2-74424 kill2-75123
plat3-75402 plat3-75409, every one swap-off GREEN on `zz_sweep.follow`
(AH by the discriminator); sealed R->G clean3-74800 hostile2-74412
plat3-75406; chain G->R kill2-75123 plat3-75409 (the same two, AH), R->G
hostile2-74412; **converged R->G: clean3-74800, hostile2 74414 74424 74426,
kill2 75100 75101 75105 75110 75124, plat3 75412 75415 75428 -- the twelve
converged-reds of reading 3 all settle now; converged G->R: none.** Against
reading 5: only clean2-74023 and clean3-74820 sealed R->G, nothing else
moved. clean2 and clean3 are 70 of 70 green for the first time; the
hostile arms are AH waiting (every red there swap-off green where checked),
not WP2's.

**The three per-arm traces (2026-09-13), and what they taught:**

- hostile2 74424 (withdrawn 14): every pass, every file in the three rings
  was NotADrag -- the three ring directories stood ROTATED (each record's
  own id at another ring's path, all three contested) and the ring walk
  could not close the ring by contents, because the two plain rings' files
  had been re-parented across passes by cause 2 and only the vault's were
  whole: one arrival, no ring, nothing moved, every file inside withdrawn
  for ever. **Landed: the ring walk reads arrivals by identity first** --
  the directory standing at a contested path that carries another tracked
  folder's own id is that folder arrived, whatever its files say; contents
  decide where either side does not know. Within a ring every member is
  live, tracked and standing, so this is not a plain folder claiming a
  stranger. With it 74424 converges; what it then shows is four sealed
  bodies standing under ordinary names -- the hold had been masking cause
  2. Swap-off run: green. **Classified: AH residue** (and the same for
  kill2 75100, swap-off green).
- plat3 75412 (withdrawn 18): with the ring by identity it converges and
  leaks the AH way with the swap on (AH residue for that half). With the
  swap OFF it still does not converge: on the `disk` device the plain ring
  503 says `ring-1`, nothing stands there, and its directory stands at
  `ring-1 (conflicted copy … from disk)` -- a directory that acquired a
  SECOND record by the mint while 503's record lagged (the no-mint hold is
  encrypted-only), so nothing follows it and nothing lifts it. **Landed on
  the way: `the_owner_follows_its_directory`** at both directory-aside
  sites (make_room and the rename-into-place aside): the record that knows
  the directory just moved aside as its own takes the aside name in its
  agreed placement, so the next scan corroborates it there and reconcile
  pushes the honest conflict rename; the design's make_room rule, landed
  directly, for plain and encrypted alike (not a claim: the engine moved
  the directory itself). It does not reach 75412's shape, whose aside was
  not the engine's room-making but the mint's second record.
  Traced to the pass of the mint (2026-09-13): 503 present at `ring-1`,
  its directory 1003 standing there, corroborated by identity, no issue --
  and the scan MINTED a new record on 1003 anyway. Not the no-mint rule at
  all: the PATH MAP (`folder_ids`, keyed by path like `tracked` was) held
  only one of two records that resolved to `ring-1` -- 502's stale record,
  which said `ring-1` while its directory stood at `ring-2` -- and when
  502 was matched away to its directory the map dropped the key so the
  directory could be "adopted for what it is", which was 503's own. The
  same two-records-one-path bug in the second map. **Landed: a present
  record standing at its path stays in the path map** (not one matched
  elsewhere this pass, whose placement can read unchanged when only its
  parent was renamed -- the lost-answer rename pin caught that first cut).
  With it 75412 with the swap off is GREEN; with the swap on it leaks the
  AH way. **Classified: reader gap, fixed; AH residue for the swap-on
  half.** Built in the root form c6 set: the path map takes `tracked`'s
  holder for every tracked path after the eviction, and every removal is
  own-key-only, so the map is right by construction with no repair loop.
  Pin owed: the two-records-one-path shape in the path map has not yet been
  built minimally (75412 is a sweep seed); evidence today is the seed, red
  without the fix and green with it. c6's refusal half of the plain rule --
  no mint of a directory whose id a LIVE record knows as its own, plain or
  encrypted -- is not yet built either; on this seed it was not the
  mechanism.

- **plat3 75415 with the swap off, traced (2026-09-13):** on the mac the
  vault's own directory, standing at `ring-3` after the user's rotation
  while its record still said `ring-1`, had been ADOPTED by
  `create_local_folder` as a plain folder another device created under
  that name on the server -- adoption by path, invisible to the create
  site's record-based check because the vault's record resolved elsewhere.
  The design's adoption rule, landed: the create site refuses (Overtaken,
  re-decided) to adopt a directory whose id another live folder record
  knows as its own; refusal only, never an aside. With it the claim had to
  yield to a tracker that has no agreement and no stand-in (a server folder
  nothing of which stands here: its create is refused at the directory by
  identity, and the vault takes what is its own), and the `remote_wants`
  deferral likewise. Then the seed LIVELOCKS at a shape with no rule: the
  claim places the vault at its directory's name every pass, the server
  refuses the rename (a plain sibling holds that name there and is not
  moving), and the next scan derives the same claim. `MoveRaceServerWon`
  covers a local move against a remote move of the same entity; nothing
  covers a local folder move that loses to a server-held sibling name that
  is not moving. Before the refusal this was a converged-red hold on a lie
  (the vault's directory wearing a plain record); now it is a livelock that
  says what it needs. **Classified: reader gap, half fixed; the rename-race
  policy one step wider is the open line** (does the server win the name
  and the local directory take a conflict name by naming's convention,
  with the record following it?).

- **E1's second shape (naming's destination judgement), built and
  REVERTED 2026-09-13.** Cut as c6 conditioned it -- a holder may be
  stepped aside for an arrival when it is leaving by the server's word AND
  stands on its own directory by identity, at the create site and in
  `judge_destinations` -- it broke the two pins named to stay green: the
  unrelated case twin got past the clash (the holder was leaving in a trade
  and on its own directory, so the exemption applied, but its name was
  retaken by the trade and the twin could never hold it) and a finisher ran
  ahead of its park. The two facts are not sufficient: the exemption is
  sound only when the vacated slot is not retaken by the same round, which
  is the closed-trade condition `trading_names` already encodes and
  refuses to widen. The two pins are ignored with that reason; the follow at
  the aside sites stays landed and still has no pin of its own (every
  construction reaches naming first, or the holder's retried move lands
  before the arrival meets it). Open with c6: `trading_names` generalized
  from a closed ring to a chain that ends at a free name.

- **E1-2 landed 2026-09-13 (uncommitted): the open ending of
  `trading_names`.** A chain is walked from each arrival through the
  holder of the name it wants; it ends CLOSED (back at the start), OPEN (at
  a name no settled entry holds -- vacated by the round, by the holders'
  own moves and nothing else) or STOPPED (at a holder not moving, or in a
  cycle the arrival is not part of). Closed and open pair the arrival with
  the ONE holder it displaces; stopped is judged as before. One arrival per
  vacated slot, the first by lowest entity id; the rest judged as before.
  Built on `leaving_this_pass` and nothing wider; identity plays no part;
  no create-site aside (a create waits behind the holder's move). Pins:
  `a_folder_arriving_at_a_name_its_holder_is_leaving_for_a_free_one_is_not_parked`
  (RED without: R parked as a duplicate of P and re-created from the
  server) and `a_folder_created_at_a_leaving_holders_name_waits_behind_the_holder`
  (a regression pin for the no-aside decision: green both ways, the
  create's record check already declines). Two findings on the way, both
  fixed and accepted by c6: (1) `leaving_this_pass` admitted a record whose
  SERVER name is a `.jd-swap-` park -- under the closed rule harmless, under
  the open ending a chain that ended at the park read as ending free and an
  arrival took the parker's slot while the parker was coming back for it
  (the peer-put-back kill sweep, die_after=3: the swap's second half lost to
  a conflict copy); a record wearing a server-side park is not leaving for
  that name -- the pass's put-back owns it -- so it is out of the set. (2)
  the move landing threw the folder's identity away (the second recording
  site above).

- **The rename-race policy at the move site, landed 2026-09-13
  (uncommitted).** `move_remote` takes the answer the create and the upload
  already give: on `name_taken`, a name held by something THIS device is
  renaming away is waited for (Retry); otherwise the next conflict name by
  naming's convention is asked for under a key of its own, and when the
  server takes it the disk follows (the move was derived from this disk, so
  it wears the planned name; server first, then disk, then the record) and
  the user is told (`kept_aside`: "X was already taken on the server, so
  this is now Y"), never a hold. Encrypted folders included; an encrypted
  FILE's name is sealed into the request and takes the ordinary orders with
  no conflict name. Only in answer to a refusal, never ahead of one (a
  retry whose answer was lost must ask for the same name). Pin:
  `a_vault_renamed_onto_a_name_a_peer_took_on_the_server_lands_beside_it`
  (75415's shape, one device: RED without = never settles; with = the vault
  under the conflict name with its id, directory and sealed file, the plain
  folder materialized, nothing trashed, nothing held). The executor test
  `a_name_held_by_something_we_already_know_about_is_not_waited_on` now
  asserts the land-beside instead of the Overtaken. **plat3 75415 with the
  swap off CONVERGES green on both oracles** (issues: kept_aside 27,
  reconcile 7, withdrawn 15, and one stale `parked` on the disk device for a
  folder that came back after its clash cleared -- an issue left open after
  it resolved, the known cost noted on the peer-put-back pin, not a hold).
  The three other swap-off seeds (75412, 74424, 75100) stay green.

  **Reading 5 found the policy's disk follow leaking (2026-09-13):** clean2
  74023 and clean3 74820 went sealed G->R with the swap OFF (not AH), and
  bisected to the policy (green with its loop disabled on the same tree).
  Probe on 74023: folder 504 (plain, own directory 1004), planned
  `/sync/ring-2`, the directory standing there id 1002 -- the VAULT's,
  carried there by the server's move of the vault earlier in the same pass,
  with the room-making stepping 504's directory aside. The follow renamed
  what stood at the planned path to 504's conflict name, the plain record
  sat on the vault's directory, and the sealed file inside went up in the
  clear under it. Cause: the follow asked the path, not the directory.
  Layer: the follow itself -- it moves this entity's OWN directory or file
  by identity (`directory_id`/`fingerprint` at the planned path against
  the record's own id); a different id is left where it stands and the
  record takes the server's name (the next scan finds the entity's own
  directory wherever it is); unknown on either side = today's rule, by
  path. Pin: `a_refused_plain_move_never_carries_the_vaults_directory_to_
  its_conflict_name` (74023's shape, the mirror of the 74033 pin: the
  laptop swaps vault and ring-2, the desktop rotates 1 -> 3 -> 2 -> 1; RED
  without the identity check: the vault trashed / the sealed body under
  the plain folder's conflict name; green with, converged, kept_aside +
  MoveRaceServerWon only). 74023 and 74820 green again on the follow
  binary; reading 6 on it supersedes reading 5.

- **The create site's identity refusal has no instrument (2026-09-13):**
  with the policy in, 75415 swap-off converges identically with the refusal
  disabled, and no pin goes RED without it (the create's record-based check
  declines the same directory in every construction reached). It is the
  identity spec's adoption rule stated in the layer that owns the question,
  and it fired on 75415 before the policy existed. c6 asked for the PLAIN
  shape before any decision (a plain folder has no claim, so it is the
  shape that could reach the refusal on its own): one device, plain `P`
  with a file, the user renames `P`'s directory to a name a peer's folder
  holds on the server. Result, c6's third reading: **the scan resolved it
  first, and wrongly** -- the peer's never-materialized tracker (no
  agreement, no stand-in) was read PRESENT at `Q` because a directory stood
  there; `Q` was then accounted for, `P` (whose own directory it was, by
  id) could not be found at it (a plain folder corroborates and never
  claims, and a tracked path is no candidate), `P` was read as deleted and
  trashed on the server, its file moved into the peer's folder, and the
  create adopted `P`'s directory. Identity loss, both ways round the
  refusal. **Landed, the reader rule:** a folder record with no agreement
  and no stand-in has never stood anywhere, so a directory at its name is
  no evidence it is present; where that directory is KNOWN to be another
  live record's own it is not in `tracked`, not present, and out of the
  path map (own key only) -- its create meets the directory and waits
  behind the owner's move. Where nobody knows the directory, today's
  reading stands (the user's own folder of that name IS the server's
  folder arriving). Pin: `a_plain_folder_renamed_onto_a_name_a_peer_took_
  on_the_server_lands_beside_it` (RED without: `P` trashed, its file in
  `Q`; with: `P` under `Q (conflicted copy ...)` with its id, directory
  and file, `Q` materialized, nothing trashed, nothing held). The refusal
  is STILL green-both-ways on this pin: by the time the create runs, the
  scan has recorded `P`'s move to `Q` and the create's record check
  declines the directory by path. **Removed 2026-09-13 (c6's call, (a)):**
  the executor trusts records; the scan is what makes records
  identity-correct (eviction by identity, the ring by identity, the claim,
  the tracker skip, the path map from `tracked`). A second identity check in
  the executor was a belt over the scan, and it fired only in a world where
  the scan was still wrong. The by-path loop is not the fallback of
  anything; it is the executor's own question, asked of records the scan
  has already put where their directories stand. The plain shape was
  tried before the removal (above). Folded into the tracker binary for
  the R4 pair (a removal with no reachable shape cannot move a seed).
  NOT landed, so nobody re-derives it: an except-self clause on
  `held_by_a_rename_this_device_owes` (the asking entity as the holder of
  its own name, a case-only rename against a server that folds case). The
  server compares names byte-for-byte (`DriveHelper::folder_name_taken`
  and `file_name_taken`, plain SQL equality) and excludes the entity
  itself (`<> :exclude`), verified 2026-09-13: the shape does not exist on
  the server the engine talks to, and a change that can move nothing and
  protects against nothing is a guard for a hypothetical. If a server ever
  folds case, that is the day it gets a shape and a pin.

- **The follow's pin (2026-09-13):** `a_second_folder_conflict_at_one_name_
  gets_its_own_name` now asserts the server and the disk end at the
  server's own names. Without the follow the server LEARNED the
  room-making's aside name (`Docs (conflicted copy ...)`) as the folder's
  own -- the record kept naming the path its directory had left, the scan
  met the directory at the aside as the user moving it, and pushed the
  conflict name. RED without the follow, green with. The reachability
  question is closed: it fires, and what it prevents is a rename nobody
  made published to every device.

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
  without a classified hold.** **CLOSED by reading 6 (2026-09-13):** all
  twelve are converged R->G on `zz_sweep.follow` -- the rename-race policy
  ended the refused-rename livelocks and holds the twelve shared (75412
  and 75415 traced to it directly; the others follow with it) -- and no
  seed is converged G->R against reading 4. The item leaves the WP2
  not-done list.
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

**Landed 2026-09-13 (uncommitted):** the upload's settled arm and `adopt`
both write the link beside their `agree` (`cache_hash(fp, sha, Some(id))`,
the plaintext hash for a sealed upload as for a plain one: the link is
about the file on this disk). The moved-on arm writes nothing, as it
records no fingerprint. `plaintext_source_of` does NOT read the link (it
reads the entry's own fingerprint), so the keyless-vault family
(`a_file_dragged_into_a_vault_with_no_key_here_waits_instead_of_trashing_it`,
`a_file_brought_back_out_of_a_vault_under_a_new_name_is_not_held_hostage`,
both on locally WRITTEN files) was never blind and stays green. Pin:
`a_file_this_device_wrote_is_linked_to_its_entity_once_it_is_up` -- RED
on `entity_for_file_id(inode) == id` before the link; then a folder trash
with an unuploaded sibling: the uploaded file goes with the folder, the
unuploaded one is rescued. Trace gate (c6's form): every ring-arm seed on
`zz_sweep.e12policy` (before) and `zz_sweep.uplink` (after, the link the
only change); the first differing line on every differing seed must be a
rescue that no longer fires, and the count of such seeds is the rescue
net's spurious-fire count on the pre-link engine. **Result (2026-09-13):
159 of 160 byte-identical; the one differing seed is plat3 75412, and its
first divergent line (mac, line 29) is the `UploadAsNew` of `ring-2.txt`
at the sync root that the rescue had left there -- the pass before, the
mac parked folder 503 (`ring-2`, a case clash with `ring-1` on its folding
disk) and `unmaterialize_and_park` rescued `ring-2.txt` out to `/sync`
because the records did not vouch for it and the link answered "never
seen"; with the link the file goes to the trash with its folder (the
server has it) and no `rescued_from_trash` issue is raised. Nothing else
diverges first. Spurious fires on the ring arms, pre-link engine: 1 seed
(plat3 75412), 0 on clean2/hostile2/clean3/kill2. The link's instrument is
the pin; the rescue net's earlier number (the clean ring arm, AJ work:
disclosures by entity 13 -> 11 under the net) has this beside it. 75412's
verdict: sealed before, sealed + chain after (a per-oracle G->R on the
chain oracle, so it carries its swap-off verdict on the SAME binary):
`NOSWAP=1` on zz_sweep.uplink = green, both oracles silent -- AH on both,
by the discriminator. Its history after line 29 differs, as any plan
change makes it.**

**B12 (2026-09-13): a park's event complaint outlived the park.** The
`parked` issue ("moved to the trash ... comes back here if the clash is
resolved") was raised as an event that stands until the user waves it away,
and stayed open after the clash cleared and the file came back (plat3 75415
swap-off, folder 504 on the disk device). On a sweep trace a stall beside
an open issue reads as a hold, so a stale issue makes a livelock look like
a hold. Fixed in the issue lifecycle: when naming releases the entry
(`recovered`), its `parked` issues are dismissed. Pin:
`a_parks_complaint_ends_when_the_file_comes_back` (RED without).

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
                           Bar: fires only on that shape. Instrument: the
                           custody line's net_fires_outside_a_user_delete
                           (reading 11: 3, two of them C4, one AH).
    mint guard (fix 2)     already out; bar is the B1 pin.
    keep-record (fix 4)    bar: zero fires across every arm after WP2.
                           MET, reading 11 (sealed_record_kept 0 of 160);
                           removal after C4, with its own pair.
    fix 3's refusals       bar decided by WP2's no-id fallback, above.
    subtree refusal        not a belt; stays.

Each removal is its own commit with the arm re-run after it. A belt whose
removal moves a number goes back in with the seed named.

**WP3 change 1 (2026-09-13): naming waits on a chain that runs into an open
op -- C3's root, in naming, not in the rescue net.** Traced on kill2 75112
with swaps off (transient probes, removed). mac plans a local swap of two
rings; the planner breaks the cycle by parking one ring on the server under a
scratch name (`park_remote`) and the kill lands the moment that park is
answered, leaving the op queued at attempts=1. pc rotates all three rings and
wins. On mac's next pass naming runs before the queue: `busy` = {the parked
ring}; a busy entry is not in `leaving_this_pass`, so every chain in the
rotation STOPPED at it, and `judge_destinations` -- which, unlike the main
loop's mid-operation arm, had no busy test -- parked all three rings as
`DuplicateName` of each other, the busy one included. Three
`unmaterialize_and_park` ops gave three directories to the OS trash; the
never-uploaded `ring-3.txt` was rescued to the root (C3's issue naming a
folder the server never trashed); the next pass re-created the rings from
the server (reminted); their files landed in the wrong rings (misplaced).
`trading_names`' own comment names this failure in prose ("the machinery
that was planned and then thrown away when naming parked the entities
first"); the busy case is the same throw-away one kill later.

Change, `naming.rs` and one skip in `pass.rs`: (1) `judge_destinations`
skips a busy entry, as the main loop does; (2) `trading_names` has a fourth
ending, PENDING -- a walk that reaches a busy holder pairs nothing and the
arrival is not judged this pass; (3) the pending arrivals come out of naming
in `NamingOutcome::pending` and the round skips them exactly as it skips
busy entries (c6's condition: judged-but-planned, the arrival's move landed
on the busy holder's directory the same pass and the room-making stepped it
aside -- the planner's occupant map is built from movers only, and a busy
holder is not a mover). No status written, no op dropped, no belief added: a
decision is deferred to the pass that can make it. The interrupted op runs
in `run_queued` that same pass -- completes or is overtaken -- and the chain
is CLOSED, OPEN or STOPPED for real on the next.

Rejected: counting a busy holder as leaving (its op may be stuck or a peer's
put-back; the peer-put-back kill sweep is why busy is excluded); dropping the
interrupted park when a chain forms (throws the cycle-breaker's work away
again and strands the scratch name); running the queue before naming
(reorders the pass for one case; the freeing batch's own reason argues
against it); anything at the rescue net or `unmaterialize_and_park` -- they
acted correctly on a wrong decision.

Pin: `a_swap_interrupted_after_its_park_is_not_given_up_when_a_peer_rotates`
-- the death is FOUND, not known (the first arming after which the server
shows a ring under a scratch name and mac still queues the park); asserts the
invariant at settle: three live rings, each the directory it was before
mac's swap on BOTH disks (`synced_fingerprint`), each holding its own file
by id, `late.txt` in its ring; parks, rescues and kept-asides printed, not
asserted. RED on 1581721c (a fourth ring minted: the pending arrival's move
landed on the busy holder's directory); RED with the naming half alone
(same shape, c6's C1); GREEN with the round's skip.

Reading 9 (`zz_sweep.pending` d5321fd31f37, R4 pair with reading 8 on the
custody harness): sealed, chain, converged -- no seed moves either way.
Custody column: kill2 fired 6 -> 5 (75121 green), plat3 5 -> 5 (75415 green,
75421 red -- swap-off green, AH); kill2 rescued 7 -> 5, plat3 reminted
21 -> 15. 75112 with swaps off: the `rescued_from_trash` naming 504 is gone;
its remaining misplaced file is C2. Trace pair `tr_custody` vs `tr_pending`:
149 identical, 11 differ, every first divergence one of the change's two
signatures -- a ring's `ApplyRemoteMove`/`AdoptPlacement` absent from a plan
(pending: 75107 75112 75113 75412 75421) or an exec count shifted by one
where the freeing batch's park no longer ran and the interrupted op was
overtaken instead (75104 75105 75121 75123 75400 75415). **C3 CLOSED.** The
rescue net's own bar stands unchanged: the fire on 504 was a legitimate
rescue from a park that should never have been ordered. Committed as
`aba4bd60`.

**WP3 change 2 (2026-09-13): the round brings nothing in under a folder the
user has just deleted -- C2's root.** Traced on kill2 75112 with swaps off
(journal + net log, no engine probe). pc's user removes `Contested Folder`
(506) at step 27; pc's next pass reads the feed (mac's `contested.txt`, 906:
new, never on pc) and the disk (the folder: gone) and plans both in one
round: `TrashRemote 506` and `Download 906`. Transfers run before deletes, and
a landing creates its parent directories -- on the real disk too (`OsVfs`
`try_commit`: `fs::create_dir_all(parent)`; `MemFs` `ensure_parents`) -- so
the directory the user deleted stood again, holding the download, before
the trash ran; the next scan met a directory nobody knew and minted a new
folder for it (510). The harness's landing-save hook wrote the body that
ended in 510, but the shape needs no hook: the pin below reproduces it in a
clean world, as an empty-folder resurrection. 75115 is the same (507 -> 511).

Change, `round.rs` (`run_round`) with one read in `pass.rs`: `going` = the
folders this round's resolution trashes on the server (local delta Deleted)
PLUS the folders whose `trash_remote` is already open in the journal from an
earlier pass (c6's condition C2-1: refused once by the network or killed
mid-call, such a folder is busy and out of the round, and its trash is just
as decided; `pass.rs` reads `queued_ops` for it). For every other input,
`Download`, `CreateLocalFolder`, and `ApplyRemoteMove` whose target parent
is under a going folder (walked up `parents.remote`) are not planned this
round. Withheld, not dropped: no status, no op, no belief. The trash runs,
the server trashes the subtree, the feed marks the children deleted and they
are forgotten as today. Read before the mass-delete withholding on purpose:
while that pause holds a folder's trash for a person to answer, the arrivals
under it wait with it. The local-delete twin of the server's
`parent_trashed` refusal, decided where cross-entry knowledge already lives
(the mass-delete count). Q2 (c6, `scan.rs`): every known child of a missing
directory is reported Deleted on its own and resolves to `TrashRemote`, so
only a never-materialized child produces an arrival, and an upload creates
no directories -- no further coverage is needed.

Rejected: ordering Delete before Transfer (the stages are global and deletes
run last for the mass-delete reason); a VFS that does not create parents (the
real disk does, and a download whose parent was renamed mid-pass relies on
it); anything at the scan (the directory really is new by the time the scan
sees it); a rule keyed on the harness's landing save (the shape stands
without it).

Pin: `a_download_never_rebuilds_a_folder_the_user_has_just_deleted`, two
armings -- plain, and with pc's `drive_trash` refused once by the network so
the folder is busy on the next pass while the download is planned again.
Invariant: no live folder of that name on the server (one trashed), none on
either disk, converged; issues printed. RED on `aba4bd60` (502 minted, both
armings); GREEN with `going` from the round alone for the first arming and
RED for the second (C2-1 load-bearing); GREEN with both.

Reading 10 (`zz_sweep.going` f041a2be620e, R4 pair with reading 9): sealed,
chain, converged -- no seed moves either way. Custody: kill2 fired 5 -> 3
(75112 and 75115 green), misplaced 6 -> 4, rescued 5 -> 6; no custody G->R.
75112 and 75115 with swaps off: every oracle green. Trace pair `tr_pending`
vs `tr_going`: 150 identical, 10 differ; the first divergence on nine is a
`Download`/`CreateLocalFolder` absent from a plan that carries its ancestor's
`TrashRemote` (clean2 74008 74010 74039, clean3 74827, hostile2 74414, kill2
75112 75115 75118 75122), and on kill2 75110 a `Download` absent two passes
after a pass that failed mid-way with the parent's trash already journalled
(C2-1's case). Frozen 111740 (chaos, no kills) moved from green-on-everything
to red on the chain oracle: its first divergence is two downloads withheld
under two trashed folders on disk's pass 7; the chaos name-swapper then fires
at other moments and one swap meets scan rule 1; swap-off green on the
engine before AND after the change -- AH residue, so the seed joins the
`poisoned_by_ah` list with the reason in the comment (c6's F1, answered by
the trace, not by widening the pin). **C2 CLOSED.** Committed as `20c431b5`.

**WP3, the belts' instrument (2026-09-13): two belts report before any
removal (R8).** Reports only; nothing decides on them.

- Fix 4, keep-record (`forget_folder_the_server_confirms`, the `keep`
  closure): a sealed FILE record whose inode still stands on this disk is
  not forgotten when the server confirms its folder gone, and the folder's
  record is kept under it. It fired silently; now it raises
  `sealed_record_kept` on the folder, once, naming the kept files ("N sealed
  file(s) here are still on this computer although the server has deleted
  their folder; their records were kept so nothing is sent again in the
  clear, and the files go to this computer's trash with the folder").
  Its one reachable shape, found by the pin: the file's record still under
  the folder while its inode stands elsewhere in the tree when the forget is
  confirmed -- moves run before deletes, so an APPLIED move takes the record
  out of the folder first and there is nothing to keep; the move has to be
  in flight. Pin `a_sealed_file_still_here_says_so_when_the_server_forgets_
  its_folder`: the user drags the sealed file to the vault root, the
  device's pass sends the move and the network refuses it once, another hand
  trashes the subfolder on the server, the device passes: the file is busy
  and out of the round, the folder's local trash finds an empty directory,
  the forget stats the file as gone with the cascade, its inode is at the
  root -> keep -> the issue stands once, on the folder, naming the file. RED
  without the report, GREEN with. R9 gap, stated: no pin exists for fix 4's
  own invariant (nothing plain reaches the server; the file goes to the OS
  trash with the folder) -- 87ca2389 landed it with `scratch_clean_one` and
  the estate spec's F5/F6 measurements only.
- Fix 1, the rescue net: reports already (`rescued_from_trash`,
  `sealed_not_rescued`); what could not be read was whether the USER deleted
  the folder the issue names. The harness now knows: `Custody::removing`
  runs before every workload removal (arms 15 and 18) and resolves each
  directory of the subtree to its server folder while it stands -- the
  learned handle, else the record the store places at the path, else the
  folder the store merely NAMES there (`scenario::folder_named_at`: a
  directory the user made under a name the server was bringing is adopted
  as that folder by the next scan, so removed first it is that folder's
  directory the user deleted); unresolved counted `removal_unattributed`.
  The set is world-wide: the cross-device shape (deleted on pc, a
  never-uploaded file rescued on mac on the feed's word) is the net's
  legitimate shape too. `rescues_from_folders_the_user_never_deleted` = every
  rescue issue on every device whose folder is not in the set; on the custody
  line as `net_fires_outside_a_user_delete=` with the lines, plus
  `user_removed_folders=`. Unit test `the_rescue_nets_bar_knows_whose_delete_
  it_was`: pc deletes, mac rescues -> 0; the server trashes by hand, mac
  rescues -> 1 naming the folder.

Reading 11 (`zz_sweep.reports` a84efdf82f83 = `20c431b5` + the report; vs
reading 10): traces identical 160 of 160 (a report draws nothing), every
oracle and every custody cell unmoved. `sealed_record_kept`: 0 on every seed
of every arm -- fix 4's bar is met. `net_fires_outside_a_user_delete`:
clean2 0, hostile2 1 (74414), clean3 1 (74821), kill2 1 (75101), plat3 0.
Traced: 75101 swap-off 0 (AH residue); 74821 (clean, no faults) and 74414
(swap-off still 1) are one shape, **finding C4**: b makes `Private/Sub 4`,
writes into it, its pass creates 506 from that directory; b's user renames
the directory (`Sub 28 renamed`); a's stale write mints a namesake on the
server (507); b's next pass plans `TrashRemote 506`, `CreateLocalFolder 507`
onto b's directory, and `ApplyLocalMove 906` (506's file) into 507; a and c
trash their copies and a's never-uploaded sealed file goes to the trash with
a `sealed_not_rescued`. The user renamed a folder and lost it everywhere
except as a merge into a stranger's namesake. Site: the provisional folder
record the scan mints for a new directory carries no directory identity
(`blank`), the create's landing records none (`agree` passes none), and
`record_directory_identities` reads agreed paths only on a LATER scan --
renamed before that scan, the agreed path holds nothing and the record never
learns its directory; the tracked loop's `owned` map then cannot protect the
directory from the namesake's arrival, the record reads as missing, and its
own files corroborate "moved into 507". Custody counted it `reminted` (one
birth, two ids): the residual line hid it; the belt report shows the human
cost. C4's fix is the unit after C5.

**WP3 change 3 (2026-09-14): an id standing under the record's own path is a
recycled id, and the record knows no directory by it -- C5's root.** Found
when C4's fix (below) turned frozen 1073449 custody-red; traced on that seed
in a scratch worktree with probes at the four move sites: disk's 502
(`Contested Folder`, encrypted under the shared vault) was created from its
own directory 1002; the user removed the directory (arm 18) and, on a disk
that hands ids straight back (`reuse_file_ids`), a later `mkdir` of
`Contested Folder/Sub 6/Sub 9` got 1002; a new `Contested Folder` (1003)
stood at 502's agreed path. The vault-claim site ("an ENCRYPTED folder's
identity may claim") found 1002 under 502's own path and planned
`ApplyLocalMove 502 -> Contested Folder/Sub 6/Sub 9`: a folder into its own
subtree, which the server refuses, and the local reading then had 502 owning
`Sub 9`'s directory with the misplacement behind it. The contents site
refuses exactly that shape (the 74826 comment) and the claim site did not;
and refusing it at the claim alone is not enough: the no-mint hold ("a
directory whose identity belongs to a folder still alive here is never
minted") then kept `Sub 9` from ever syncing at all, silently.

Change, `pass.rs`, at the source rather than at either site: one closure,
`recycled_under_own_path`, read by BOTH readers of a record's identity in
`detect_folder_moves` -- the `owned` map built before the walk (the named-only
tracker rule reads it: c6's C6-1, else a server folder arriving at the
recycled directory's path would decline it by identity and wait for an
owner's move never planned) and `own_id` in the tracked loop (which feeds
`record_identity`, the vault claim, the no-mint hold and corroboration). A
record whose recorded id stands under its own believed path knows no
directory this pass; and `record_directory_identities` counts such an id
stale and re-records the directory standing at the agreed path -- the
namesake, which the standing-directory rule already makes the folder.
Nothing can stand inside itself; an id found there was the disk's to give
away.

The cost, stated: the user renames `Sub` to `Other`, makes a new `Sub`, and
moves `Other` inside it. The directory's id now stands under the record's
believed path and is void; the contents proposal is refused at the same
subtree test (74826); the record becomes the namesake by the standing-
directory rule and the moved folder is re-minted new, losing its server
identity. That is today's outcome for a plain folder already; C5 makes it
the vault folder's outcome too, instead of a claim into itself refused for
ever. And the cost falls on every disk, recycling or not: a folder
genuinely moved into its own namesake is re-minted on APFS and NTFS too,
because the rule reads the shape, not the disk. No pin covers that shape; it
is named here as the cost.

Pin `a_vault_folders_identity_never_claims_a_directory_inside_its_own_path`:
one keyed device on a recycling disk; a vault subfolder whose record knows
its directory; the user removes it, makes a namesake at its path and `Inner`
inside, and `Inner` wears the removed directory's id. Invariant: one live
folder under the vault; `Inner` a folder of its own under it, sealed, holding
its file; the removed folder's file trashed; the live folder's record knows
the directory standing at its path, not the recycled one; converged. RED on
HEAD without C4 (the claim renamed the folder onto `Inner`), GREEN with the
change alone. Reading 12 (`zz_sweep.c5` e1ad191e1279 vs reading 11): traces
identical 160 of 160, every cell unmoved -- the shape is not reachable on the
ring arms until C4 makes records know their directories at the mint, which
is why C5 lands first and C4 second with 1073449 green outright rather than
wrapped.

**WP3 change 4 (2026-09-14): a folder record knows its directory from the
moment it is minted from one -- C4's root.** One line at the provisional mint
in the scan (`pass.rs`, the new-directory loop): `entry.synced_fingerprint =
dir_identity[dir]` as `Fingerprint::of_directory`. The create's landing keeps
it (`agree` passes no fingerprint) and `record_directory_identities` finds it
not stale. Rejected: recording at the create landing (reads the record's path
at landing time; renamed in between, that path holds nothing or a rebuilt
stranger); widening the tracker rule or the contents match (they read a
record that does not know its directory, which is the cause). Two readers a
provisional folder with a fingerprint reaches that it did not before, traced:
Q4 `unmaterialize_and_park`'s child loop -- unreachable, `inside` skips
provisionals at the top of its loop before the reset; Q5 `child_folders`
(corroboration by a known child folder's id): a provisional child's directory
now counts beside its parent's own id -- reading 13 shows it once, kill2
75129, where pc's own directory, holding a provisional with its id, is
created as its own folder (`CreateRemoteFolder`) instead of adopted by name
for the server's namesake arriving in the same pass; two folders of one name
land beside each other under the rename-race policy; custody unchanged.

Pin `a_folder_renamed_before_its_first_scan_after_creation_keeps_its_
identity`: b makes `Sub`, writes into it, syncs (the folder is created from
b's directory); b renames `Sub` to `Other`; a's own `Other` reaches the server
first; b passes; settle. Invariant: both folders live, each holding its own
file by id, b's record for its folder carries the directory b made; converged
(names race-dependent: one lands beside the other). Issue kinds printed. RED
on HEAD (b's folder trashed, its file moved into a's), GREEN with the change.

Reading 13 (`zz_sweep.c4c5` c43148503b7d = the instrument + C5 + C4; vs
reading 11): sealed, chain, converged -- no seed moves either way. Traces
144 identical, 16 differ; first divergences, every form: fourteen are C4's
signature -- a folder's `TrashRemote`, or the re-creation of its renamed
directory as a new folder, replaced by `ApplyLocalMove` of the known record
to where its directory stands (clean2 74000 74008 74013 74030, clean3 74800
74821 74827, kill2 75102 75107 75116 75125, plat3 75400 75415 75422); plat3
75429 is identity correcting a contents guess (507 matched to `Sub 37` by
contents before, to its own directory `Sub 23` after); kill2 75129 is Q5's
form above. Custody: clean3's net fire (74821, C4's seed) gone; clean2
reminted 2 -> 1, kill2 reminted 18 -> 12; kill2 75125 custody G->R (its three
ring conflict copies counted `reminted` before and `misplaced` after -- the
same files, the ring family; with C6 landed the swap-off discriminator says
AH). **OPEN, finding C7 (67, from C4's reading):** on the same seed two
`sealed_not_rescued` fires outside a user delete appear with C4 (0 on reading
11, 2 on reading 13, and with swaps off on the fix-4-removed binary still 1:
mac, folder 505 `Sub 5 (19) (19b)`, beside a `parked` issue on that folder --
"cannot hold the name it now has on the server (DuplicateName with Sub 5)").
A naming park gave a vault subfolder holding two never-uploaded sealed files
to this computer's trash, on a sequence C4 reaches; not AH, and not a user
delete: the rescue net's bar instrument doing what it was built for (the C3
family). Untraced -- C4's cost or a pre-existing naming shape the changed
sequence reaches; the trace is the next WP3 unit, before fix 1's bar is read
again (67's artifacts: journal and full log under its scratchpad r75125).
hostile2 74414's net fire
stays: traced on C4's binary, desktop's `Contested Folder` (506, created from
its own directory, which C4 now records) is moved by the user into a rebuilt
`Contested Folder (10)`; its only files never reached the server, so no
contents proposal exists and a plain folder's id may not claim on its own --
506 reads as deleted, the directory is minted new and the two files are
rescued to the root. That is the empty-plain residual as stated (owner item
A2), not C4. Frozen 1073449 green outright (C5 landed first; no wrapper).
**C4 CLOSED.**

**Finding C6 (kill2 75125 with swaps off, every binary since the instrument):
never settles.** mac holds two `move_remote` ops, 504 -> ring-2 and 503 ->
ring-3, each refused 1700+ times with "the name is spoken for by something
this device is renaming": a two-folder trade whose cycle-breaker never parked
one side (a kill in the middle of the trade), and the rename-race policy's
wait-for-a-name-this-device-is-renaming holds each behind the other for
ever. Not C4's (the instrument binary shows it); untraced; WP3's next.

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
- WP1d: `a_file_that_keeps_its_bytes_and_loses_its_folder` -- landed; GREEN on
  converged, stranded and orphan (blind is the point), RED on the custody
  oracle naming the file and both folders. Frozen 3072116 wrapped
  `red_only_on([every_file_in_a_folder_the_user_put_it_in])` for C1.
- WP2: the identity spec's pins plus the two decisions above, each RED without
  the fix before it is kept.
- WP3: each belt's shape report, in the commit that removes it.

## Open

- **D1 (2026-09-24), a sealed file dragged out of a vault is held, not
  converted. Built; awaiting NEEDED/VALID on the tree copy (public-html-25).**
  Owner decision 2026-09-22 (held, not converted; the sealed oracle stays
  strict), wording 2026-09-24 (honest text, no unencrypt feature, no revert).
  Nothing on the platform takes a file out of a Fortress vault:
  `drive_level_change` refuses Fortress both ways and the browser converts
  nothing, so the words name the two real exits (move it back, or download
  and upload it).
  - **Rule.** `crossing_a_vault_edge` answers Convert only into a vault. A
    sealed FILE leaving one is held: its agreed placement becomes where the
    user put it, `local_name` clears, and nothing is asked of the server,
    which keeps the sealed copy where it was. `held_outside_its_vault`
    derives the hold from the record, never remembered beside it: a sealed
    file whose agreed parent is the root or a KNOWN plain folder (live or in
    the server's trash) while the server's parent is a vault. A folder never
    reads it (a vault root stands in a plain parent); an unknown agreed parent
    is no evidence; a peer moving the sealed copy between vault folders leaves
    it standing.
  - **While held.** Moved again outside any vault -- renamed where it
    stands, or into another plain folder -- is this disk's side alone: the
    record follows, the issue takes the new name, the server is asked nothing
    (review B1: a rename in place was planned as a move across the edge and
    refused on every pass). The remote delta is content and deletion only. Edits wait
    on both sides, and the agreed contents are untouched, so both are still
    seen when the file goes back. A move back into a vault is planned from the
    server's placement (from the agreed one it was overtaken for ever: hostile2
    74401, kill2 75101 never settled); exactly at it, agreed with no op. A
    server trash of an unedited copy is an ordinary delete; of an edited one,
    the copy is kept here only and never sent.
  - **Where the rest of the engine meets a hold.** `rekey_entry` and
    `merge_folder` re-point agreed parents: a hold into a brand-new folder
    names a provisional parent, and without it the file re-minted plain.
    Naming neither counts a held file as leaving nor judges it at its server
    destination (judged there, a case clash on a folding disk gave up its only
    copy here). A file saved or moved into a vault slot whose real name a held
    file still holds on the server takes a conflict name (else two sealed files
    with one real name in one vault folder, kill2 75101).
  - **Moved and edited in one pass (review B2, B3, Ca, Cb).** The scan reads
    a file both moved and edited between two scans as a delete plus a creation
    (scan.rs rule 4: a bare inode may fund ORDER, never IDENTITY). A held
    file so treated shows up as a new file carrying the held record's disk
    identity, and that identity buys a wait and nothing else. W1: that new
    file is not sent while the hold stands -- in a plain folder it waits with
    the held record; in a vault it waits until the held record's delete has
    landed, then goes up sealed under its own name, and no held-name aside is
    decided for it. W2: a held record reading deleted while such a file stands
    outside any vault is not trashed -- both wait, and the issue says "{new}
    may be the vault file {old} under a new name, so it is kept only on this
    device and not uploaded. Move it into the vault to sync it encrypted, or
    delete it." (true whichever file it is; review-approved, with the owner).
    The held identity is read including records the server has deleted, so a
    server trash in that state keeps the wait and says H3's text naming the
    file (Ca: the wait ended and the file went up plain). A hard link -- the
    held file still standing where it agrees -- in a vault goes up sealed at
    once; in a plain folder it is a copy out of the vault and waits (Cb; the
    simulator has no hard links, so this has no pin). A recycled identity
    costs a wait until the user acts, never a name or bytes: in a plain folder
    an unrelated new file that got a deleted held file's inode is not
    uploaded, and the held record's delete does not reach the server, until
    one of them is moved or deleted. End states: renamed and edited in a
    plain folder, one file here, nothing sent, the sealed copy intact, one
    issue; moved home and edited, the held record trashed and the file back
    as a NEW sealed record (rule 4's price), no aside, nothing in the clear.
    A held record whose own file does not stand at its agreed path holds
    nothing there -- no name for naming, no claim for an upload -- so a new
    file saved at that path goes up as the user's new file. Without it, on
    the first W build the stranger's upload was refused for the held record's
    claim on every pass (hostile2 74400, kill2 75112, 75118, plat3 75410,
    75413 never settled), and naming parked the held record against the
    stranger's name, dropping the hold and with it the wait, so the waiting
    file went up plain.
  - **A held file gone from this disk while the server's copy changed** is
    restored where the server keeps it: the hold is released toward the
    server before the restore, so the download lands in the vault (it landed
    in the plain folder and was minted plain -- the both-sides pin, RED on the
    first W build). `make_room`'s owner
    follow that lands a sealed file outside its vault makes a hold and owes no
    `move_remote` (kill2 75127), and a held owner follows even at its own
    agreed path, since the case-twin guard's reason (a conflict name pushed to
    the server) cannot hold for it (kill2 75100: the aside was minted plain and
    the sealed copy trashed). A folder trash does not wait on a held child,
    `is_on_the_server` is false for its inode, and the rescue carries its
    record with it (C1(a): a peer trashing the plain folder re-dragged the file
    into the vault on disk, then, with that fixed, uploaded it plain).
  - **Words (owner-approved).** Held: "{name} is encrypted and stays in its
    vault on the server. The copy here is kept only on this device. Move it
    back into the vault to sync it again, or download it in the browser and
    upload it where you want it." (one open issue per file, re-raised on
    rename). A server file refused a name by a held file: its own state kind
    `waits_for_a_held_file`, re-derived each pass, in place of the generic
    `unsyncable` (whose detail must stay `{reason:?}` for its reconciler),
    naming both files; it lifts when the held file goes back or goes. Rescue
    out of a trashed folder: "The folder {name} was in was deleted on the
    server, so it was moved to {where}. It is still encrypted on the server
    and kept only on this device until you move it back into the vault." H3:
    "{name} was deleted on the server while it was kept outside its vault on
    this device. The edited copy here is kept only on this device and is not
    uploaded." Server: `drive_move_logic.php`'s Fortress refusal split by
    direction -- out: "A Fortress file cannot be moved out of its vault.
    Download it and upload it where you want it."; in: "A file cannot be moved
    into a Fortress vault. Download it and upload it into the vault." A vault
    folder dragged out, inside a vault: "{folder} is encrypted and stays in its
    vault on the server. It is kept only on this device. Move it back into the
    vault to sync it again, or download its files in the browser and upload
    them where you want them." (review E2: it offered a protection-level
    change, which does not exist for Fortress; the vault-root sentence was
    true and stays).
  - **Harness.** `assert_converged` declares held entities by entity
    (`scenario::held_outside_the_vault`: open issue, sealed server parent,
    plain agreed parent) in the records-agree check and the tree comparison,
    and the file must stand at its agreed local path. Custody sorts a `held`
    class beside rescued and reminted, on an open hold's (id, server parent).
    Both counted on the custody line (`held=`, `held_records_converged_skips=`).
    The sealed line splits leaks by road from server facts alone:
    `never_sealed`, `as_an_edit`, `beside_a_live_sealed_copy`,
    `sealed_copy_gone`.
  - **Pins.** New: `a_sealed_file_dragged_out_of_a_vault_is_held_not_published`,
    `a_held_file_moved_back_into_its_vault_is_released`,
    `a_held_file_deleted_here_is_deleted_on_the_server`,
    `a_file_saved_where_a_held_file_was_is_a_new_sealed_file`,
    `a_file_moved_to_where_a_held_file_was_is_set_aside`,
    `a_held_file_edited_on_both_sides_keeps_both_edits_when_it_returns`,
    `a_peer_file_arriving_at_a_held_files_path_waits_for_the_name`,
    `a_hold_survives_a_restart`,
    `the_server_trashing_an_unedited_held_file_deletes_it_here`,
    `the_server_trashing_an_edited_held_file_keeps_the_edit_here_unsent`,
    `a_held_file_moved_between_vault_folders_by_a_peer_stays_where_the_user_put_it`,
    `a_held_file_whose_plain_folder_is_trashed_stays_held_where_it_is_carried`,
    `a_held_files_plain_folder_dragged_into_a_vault_duplicates_it_sealed`,
    `a_held_file_renamed_where_it_stands_stays_held`,
    `a_held_file_moved_to_another_plain_folder_stays_held`,
    `a_held_file_moved_back_into_a_vault_subfolder_is_released_there`,
    `a_held_file_moved_back_and_edited_in_one_pass_comes_home`,
    `a_held_file_renamed_and_edited_in_one_pass_is_not_published`,
    `a_held_file_renamed_and_edited_then_trashed_on_the_server_is_not_published`,
    `a_file_saved_where_a_waiting_held_file_was_goes_up_as_a_new_file`,
    `a_sealed_file_moved_and_edited_in_one_pass_is_a_delete_and_a_creation`
    (rule 4's price, pinned as it is),
    `a_sealed_file_moved_from_one_vault_to_another_is_an_ordinary_move`
    (keeper), executor `a_held_file_set_aside_by_a_download_on_its_own_path_keeps_its_record`.
    Rewritten from "converts" to "is held": the brand-new-folder pin, the
    unreadable-ids pin, P3 (both provenances), AG (both provenances), and the
    vault-edge swap (`a_swap_across_a_vault_edge_converts_in_and_holds_out`).
    Every pin asserting the hold is RED on 181843af; the stranger pin is RED on
    a hold that leaves the record's local side in the vault; the move-aside and
    follow pins are RED with their own part off.
  - **Measured vs 181843af** (final build, W1/W2 included). 160 swaps on:
    sealed seeds 84 -> 77, leaked files 197 -> 157, chain 34 -> 33, custody
    8 -> 8, converged 0 -> 0, never_settled 1 -> 1, R->G 6 (hostile2 74403,
    74418, kill2 75102, 75118, 75129, plat3 75429), G->R 0. Held-out plain2:
    identical. Swaps off: custody 1 -> 0, otherwise identical. Custody line:
    held=2, held_records_converged_skips=55, held_waiting=3. Every new
    per-oracle fire, traced: sealed kill2 75116, plat3 75406: T1-C; chain 6
    in, 7 out, none a held entity -- T1-D x5 (hostile2 74409, kill2 75122,
    plat3 75403, 75419, 75425), T1-C family x1 (plat3 75406: a record whose
    inode the conflict rescue cleared); custody kill2 75101, plat3 75426,
    75410: folder T1 / C9 (a ring directory minted under a plain folder on
    one device and the vault on another); kill2 75124 sorts `held`. Against
    the build before W1/W2: hostile2 74400 fires sealed again (red on
    181843af too) -- four bodies, all T1-C (sealed provisionals with no inode,
    carried into a plain folder and minted there).
  - **Leaks left (157), by road.** never_sealed 116 (T1-C). sealed_copy_gone
    25: no crossing op or Convert on any; on the build before W1/W2 its 28
    split by the route that published each body into T1-C 19 (an inode-less
    provisional took a swapped-in body, or lost its file) and T1-D 9 (a
    sealed record's version upload read a stranger's inode at its agreed
    path, and its own inode was minted plain elsewhere). beside_a_live_sealed_copy
    13, as_an_edit 3 (AH / rule 1 pairing, or a copy-out; the harness cannot
    tell those apart). not_carried_out 1: kill2 75112, folder T1 / C9
    (written into a ring directory while it was the vault, set aside there
    after the path map gave it to a plain folder).
  - **Held-path edit readings join the copy-out line.** On the build before
    the follow fix, kill2 75102 and 75118 lost a held record's file to the
    scan's edit reading. Both strangers at the held path were files with no
    record (75102: never minted on that device; 75118: the leftover bytes of a
    plain record the trade had converted, swapped back), so mine4 reads them
    as edits by design: the backup-save shape. Inode-first pairing for held
    records was rejected (it overrides mine4 for one class and only changes
    which copy goes up plain on a real editor save), and so was a content-hash
    do-not-mint rule (a guard, and the copy-out line in disguise).
- **Owner decision D2 (2026-09-22): scan rule 1 takes the careful form
  (`mine4`).** A record refuses bytes at its path as an edit only when its own
  file still stands elsewhere and the thing at its path is one the store
  knows, or its file stands on another record's path whose owner is not at
  home. Backup-by-rename saves, hardlinked twins and a zero Windows file id
  read as edits, as today. Settles the AH decision (A1); with D1 the crossing
  half is moot. Re-measured on today's engine before it lands; not built.
- **Owner decision D3 (2026-09-22): the empty-plain residual stands**, as
  stated under WP2 (owner item A2, closed): a plain folder never claims by
  its own directory id, so an empty or never-uploaded plain folder renamed
  while its old name is rebuilt keeps today's reading, including hostile2
  74414's files rescued to the root.
- **C10 + C11 (2026-09-22), NEEDED and VALID (public-html-25), awaiting the
  owner's commit.** C10: a device puts back only a park it made. Scratch
  names carry a device tag (`.jd-swap-{tag}-{token}`, tag hashed from the
  device's first park key and kept in the store's meta); the stranded-park
  rescue fires only on this device's tag, and untagged parks keep the old
  rule until every device runs a tagging build. The real client's keys are
  random, so nothing may read a device from a key (the simulator's
  `{device}-{token}` keys are a standing trap; filed: mint them in the real
  shape). C11: a scratch name is not a placement -- `observed_remote` reads
  the agreement in its place, and an entry with a scratch name and no
  agreement is `waiting_on_a_park` and is skipped by naming and the pass.
  Before C11, C10 alone lost data (a peer followed the park onto its disk,
  the walk hid it, the round trashed it). On HEAD the peer's put-back fired
  on ten of the 160 seeds and minted a conflict name on a ring folder on
  six of them; C10 removes that. Pins: `a_peers_park_is_neither_put_back_
  nor_followed`, `a_peers_park_on_files_this_device_never_had_is_waited_for`,
  and the residual pin below.
- **Residual of C10, for the owner:** a device that parks and does not come
  back with the SAME store -- a reinstall, a wiped or re-created store, a
  new enrollment on the machine, a lost disk -- leaves that entity under the
  scratch name on the server, and no peer puts it back. No bytes are lost.
  Closing it needs the server to say a device is gone (a state, never a
  timer). Pinned red-to-be:
  `a_park_left_by_a_reinstalled_device_stays_until_the_server_can_say_it_is_gone`.
- **D2 (2026-09-23), NEEDED and VALID with its blockers, awaiting the owner.**
  Scan rule 1 reads a name trade as a trade: a record refuses the bytes at its
  path as an edit when its own inode stands elsewhere and the file there is
  another record's that is not at home (by inode, or by non-empty bytes held by
  exactly one live record), or its own file stands at another record's path
  whose record is not at home -- unless a hardlinked twin at home holds its
  inode (p8). Zero file ids never count. Measured on C10+C11: swap-separated
  pairs 295 -> 69 (160 seeds), 305 -> 101 on the held-out 75200-75299. Its
  residue is mostly T1 (1116 of 1279 declines are the twin shape).
  Two blockers found by the always-on zz_sweep tests landed with it:
  - **C12**: a FILE move the server refuses in prose alone lands beside under
    a conflict name (the wait for a name this device is vacating asked first),
    where it was withdrawn and the next scan read a stranger's bytes as the
    file's edit (frozen 111120, now green). FOLDERS keep the withdrawal: the
    disk still wears the new name and the next pass re-derives the rename by
    directory identity, completing it once the name frees up.
  - **Blocker 2**: a move whose answer was lost after the server completed it
    is finished as this op's own when the file's own inode stands at the
    destination (placement only; its bytes are the scan's to judge), where it
    was stood down and the file was re-uploaded beside itself.
  **Residual, not measurable in the simulator:** where the file id is unknown
  (zero, or a filesystem without stable ids) a rename chain interrupted after
  a completed-but-unheard first rename still ends in a duplicate -- the
  completed move cannot be recognised without an identity. Sealed files stay
  stood down (their server name is a placeholder) and the chain in a vault
  ends clean at every kill point.
- plain2 75228 and 75292 (pairs gained under the D2 package): T1's shape;
  both green under T1.
- **C6, latent, no reproduction.** The rename-race wait
  (`held_by_a_rename_this_device_owes`) has no cycle test: two queued server
  renames that want each other's names wait for ever if the planner's park
  is absent. With C10 in, the only reproductions (plain2 75298, kill2 75125
  swap-off) settle -- the lost park was the peer's put-back -- and recovery
  re-plans a fresh park after a kill. A cycle break was built and measured:
  no seed needed it, and it added a never-settle (kill2 75110) and a G->R
  (kill2 75123, swap-off), so it did not land. The unlanded patch and its
  three pins (which never armed it) are kept in
  `specs/drive_sync_reset_c6_unlanded.diff`.
- **C8, C8b, C8b-4 (2026-09-23), NEEDED and VALID (public-html-25).**
  - **C8:** when two folder records resolve to one path and identity cannot
    pick an owner, the record that does not take the path goes to the
    contested pool; when both are known to stand elsewhere, both go. Before,
    the earlier record fell out of the scan, read as deleted, and a vault so
    dropped was re-created while its real directory was minted plain with its
    sealed file in the clear (kill2 75110). Pin:
    `frozen_vault_dropped_from_a_shared_path_seed` (75110, swaps off).
  - **C8b:** a vault's claim on its own directory is not blocked by a plain
    record the path map gives that path to when that record's own directory
    is known to stand elsewhere; the displaced record goes to the contested
    pool. Closes plat3 75400 and hostile2 74412 with swaps off.
  - **C8b-4:** that displaced record leaves the scan's present set at the
    path it lost, so it never stands beside the vault on one path. Pin:
    `frozen_holder_gives_up_the_path_it_lost_seed` (75400, swaps on).
  - Placing such a record where the server has it (when its own directory
    stands there) was built and did not land: no seed needed it beyond the
    presence fix. The same placement for any contested folder (C9(a)) settled
    plat3 75415 but added name-trade pairs on hostile2 74401, 74404, 74409,
    plat3 75407 and 75427, and a custody fire on 75407; not landed, traced
    inside T1. Both are in `specs/drive_sync_reset_c9a_unlanded.diff`.
- **Residual of C8b-4 (plat3 75412, rooted in T1):** a record that lost its
  path to a claim and that nothing places takes today's reading -- it can be
  re-created at its server name while a directory it names still stands, a
  named breach of the rule that a record with a known directory is never
  re-created while that directory stands. T1's trace ends with 75412 obeying
  the rule or the rule amended with the owner's say.
- **Open finding R5-HOLD:** a row-5 hold does not suspend the held record's
  server move, so a hold on a record the server is moving livelocks. The only
  construction (a row-5 stand-down for C8b-4's displaced record, 75412) has no
  clearing event: deferring the move leaves a hold nothing but the user can
  lift. No pin yet; the design waits for T1, whose twin makes "its own
  directory" ambiguous there.
- **plat3 75415 never settles** (swaps off; swaps on it now reports), in the
  C9/T1 queue.
- **Sealed-leak queue** (engine-made, not carried out by the chaos user):
  kill2 75112; hostile2 74412's b5ed7579 and fb003484, seen only once C8b let
  that world settle (they were uploaded on the earlier engine too, hidden by
  its never-settle); kill2 75101, plat3 75401, 75412 from reading 18.
- The C8b-4 constructed pin (a displaced plain folder that loses a path to a
  vault's claim, searched by contents, no two records on one path) is owed:
  a hand-built rotation is resolved by the ring walk before the claim runs.
- **T1 (2026-09-23), files, NEEDED and VALID (public-html-25), landed
  181843af: one file, two records.** Traced with a probe on every
  record written with a disk identity another live record holds. A file's
  identity was given to a second record in three ways, each landed:
  - **B, scan rule 1:** a path held by a live file record with nothing of
    its own on this disk yet (a download still to come, or one the user
    saved over as it landed) is a record's path that is not at home. Left
    out of `known_local`, a trade with such a slot read as an edit and this
    record's own file was minted again (plain2 75292). Sealed records count
    on the same terms: a keyless device never counts one (parked), a keyed
    one reads the real name (measured exclude vs include: a wash, 2 seeds
    one each way). Pin: `a_trade_with_a_download_the_user_saved_over_is_read_as_a_trade`.
  - **A, make_room's file owner follows the aside:** a file moved onto a
    download's name mid-pass (no scan between) was set aside with no owner
    and minted again. Exactly one live file record holding its identity
    (not held, with an agreed placement, and not standing at its own agreed
    path -- a case twin on a folding disk has not moved) follows it by
    parent and name, and owes the server the same move: agreement and a
    `move_remote` op are written in one transaction, BEFORE the rename, so
    a kill between leaves the op queued and the file found by inode. The
    user's move stands (the reviewer's ruling (ii)): undone, a move that
    crossed folders put the file back in a folder the user moved it out of
    (plat3 75427, custody). The owed move's key is derived from the op that
    made room plus the owner and the placement, so one op making room twice
    sends two requests under two keys (server key-reuse count 0 over 420
    runs). Pins: `a_file_moved_onto_a_landing_download_keeps_the_users_move`,
    `a_file_moved_across_folders_onto_a_landing_download_stays_in_the_users_folder`,
    `a_file_moved_onto_a_landing_download_survives_a_failed_set_aside`,
    `a_sealed_file_moved_onto_a_landing_download_keeps_the_users_move`,
    `one_download_setting_a_file_aside_twice_owes_each_move_under_its_own_key`.
  - **A', an upload keeps an owed move:** a version read from the agreed
    placement while the server names another keeps that placement after
    agreeing; agreeing on the server's said the file already stood there,
    and the next scan pushed the move back (a peer's rename undone; kill2
    75124, held-out 75239). Pre-existing without A. Pin:
    `an_edit_uploaded_before_an_owed_move_does_not_erase_the_move`.
  Every pin is red with its own part switched off. Frozen 111740 green
  outright (B alone closes it; A only with A'), wrapper off.
  Measured vs 16c5a38c: 160 swaps on, swap-separated pairs 69 -> 47, seeds
  48 -> 34, custody 10 -> 8, sealed 82 -> 84, R->G 1 (hostile2 74419), G->R 0;
  held-out plain2 green 37 -> 59, pairs 99 -> 59, G->R 2; swaps off 160/160
  identical. Every new per-oracle fire, traced:
  - sealed hostile2 74405, 74418: chaos-carried cross-edge drags, D1.
  - chain plain2 75212 (held-out): T1-C.
  - chain hostile2 74401, 74413, plain2 75236, plat3 75418: T1-D.
  - custody kill2 75109: folder T1 / C9 -- the mac mints `ring-1/ring-3.txt`
    under folder 503 because the path map gives `ring-1` to 503, while the
    directory there carries identity 1004, held by 504 alone, after a
    two-device folder-ring rename.
- **T1-C, open: a record for a file never uploaded carries no inode.** An
  engine-rescued provisional (rescue_unsynced out of a trashed folder) is
  swapped with a synced file; no clause can name it, and the trade reads as
  an edit (held-out plain2 75212). Folders learn their directory at mint;
  files do not. The largest leak road left after D1: never_sealed 116 and
  most of sealed_copy_gone. The fix (an inode on every record) has to cover a
  second path too: the conflict rescue clears the ORIGINAL record's
  `synced_fingerprint` when it renames a file aside, and that record then
  takes whatever stands at its path (chain plat3 75406, 75429; kill2 75114's
  sealed provisionals). Four lines after D1's.
- **T1-D, open: executor ops act on whatever file stands at the path.**
  `move_local` checks a folder's directory identity at `from` but not a
  file's inode, and a queued version upload sends the bytes at the agreed
  path, while the record's own inode is known and stands elsewhere (hostile2
  74401: a stranger the swapper put at 907's from path moved as 907;
  74413, plat3 75418, plain2 75236: a set-aside stranger swapped onto a
  record's path mid-pass, sent as its version). Under D1: 9 of the 28
  sealed_copy_gone leaks are this inside a vault (a sealed record's version
  reads a stranger at its agreed path; its own inode is minted plain
  elsewhere), and 5 of the chain fires. After T1-C.
- **Open: a record the server trashes drops out of the scan's inode owners
  in the same pass.** Its still-standing file then reads as nobody's: the
  record is Forgotten and the file minted new, plain when its folder is plain
  (plat3 75409, the held form), or a trade partner reads the sealed bytes at
  its path as its own edit (no hold involved). Not D1's, and not generic AH.
  Pin, ignored with that reason:
  `a_file_traded_across_the_vault_edge_as_the_server_trashes_it_is_not_published`.
  D1's own H3 pins do not share the path: with no second record on the slot,
  a trashed held record still pairs with its file (local None or Edited).
- **The chosen price, not a defect: a file moved and edited in one pass
  reads as a delete and a creation** (scan.rs, rule 4). Sealed and plain
  alike: the record is trashed on the server and the edited bytes go up as a
  new file; the version chain is lost and no bytes are. A bare inode may fund
  order, never identity: a recycled inode once bound entries to strangers.
  Pinned as it is: `a_sealed_file_moved_and_edited_in_one_pass_is_a_delete_and_a_creation`.
  D1 builds its waits on this (above), never a name.
- **Open, pre-existing, not D1: a sealed move between vaults never re-grants
  the file key.** The client wraps a file key to the destination's readers
  only at a new upload; a move sends `drive_move` alone and nothing calls
  `drive_key_grants_sync`, so a sealed file moved from vault A to vault B
  stays readable by A's readers and not by B's (the owner apart).
- **Owner line owed: copying a file out of a vault uploads it plain.** Not
  covered by D1. The same line now carries the backup-save shape on a held
  file (kill2 75102, 75118 above): an editor that saves by rename on a held
  file leaves one of its two files minted plain in the plain folder, whichever
  pairing the scan uses. And a hard link of a held file made in a plain folder
  is a copy out of the vault: under D1 it waits, unsent, until the user acts.
- **Finding, the folder make_room rule:** `the_owner_follows_its_directory`
  sets the agreed NAME only, so a directory the user moved across folders
  onto a destination gets a wrong agreed parent, and it undoes the user's
  move, so the custody argument that decided T1's (ii) applies to folders
  too. Four lines of its own after T1-D.
- **Folder T1, open:** a folder mint for a directory a live plain folder
  names. Mostly inside D3's empty-plain residual (hostile2 74412, 74424's
  second mint, plat3 75409). Not inside it: 74424's first (a path vacated
  by a claim mid-scan is never a candidate), plat3 75412 (its own directory
  with its own file, refused as not moved wholesale because the user moved
  one of its files out), plat3 75415 (the directory holds another folder's
  file), and kill2 75109's custody above. After the folder-rule finding.
- **C9, open: records that do not follow their directories.** A folder
  move is planned from a stale placement and refused by identity every
  pass: (a) an all-contested ring whose disk already matches the server
  (hostile2 74424 on D2's world); (b) a twin record holds the directory
  (T1). Before its approach: why the contested-ring claim does not fire on
  74424 (Q5), and plat3 75424's class.
- The AH owner decision (crossing + `mine4`, or nothing) is outside this
  reset and blocks nothing in it; it is named so the hostile arm's residue
  after WP2 is read correctly.
- WP1d's draw-sequence cost: measured zero (traces byte-identical 160 of
  160); no re-baseline was needed.
- C2 CLOSED by WP3 change 2 (the round brings nothing in under a folder the
  user has just deleted). C3 CLOSED by WP3 change 1 (its root was a naming
  park, not the rescue net). C1 is AH (owner decision A1). C4 (a folder
  renamed before the first scan after its creation loses its identity and is
  trashed for a namesake) found by the belt instrument, reading 11; fix next.
- R9 gap: fix 4's own invariant has no pin (see the belts' instrument).
- plat3 75418, reading 2, first run: no verdict line, near-zero runtime,
  cause unknown (`scratchpad/wp1a/reading2/manifest.txt`; the host's kernel
  log shows no OOM or kill at 16:15). The runner now keeps the whole output
  and the exit status of any run with no verdict and the table counts it as
  `no-verdict`, a red-unknown row. CLOSED: readings 3 through 8 (six runs of
  the arm on four binaries) show no no-verdict row; 75418 has a verdict on
  every one.
- The chain oracle's blind share: `pairs_sealed` on the coverage line lands
  with the post-commit re-run and goes into the WP1b section as the oracle's
  stated coverage (4 of 7 on hostile2 74423 at first sight). It is the
  argument for a decrypting reader of sealed versions later, not now.
