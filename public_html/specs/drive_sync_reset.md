# Drive sync: the reset

**Status: specified, reviewed by public-html-c6 (2026-09-12), not started.**

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
one answer, and B10 says the sweep is not trace-reproducible with the cause
unestablished. "Stable in practice" is an observation, not a property.

WP1a before WP2 is judged because it is the disclosure instrument and it is
cheap. WP1d is NOT in front of WP2: it sees custody, not leaks, and R4 makes
its order irrelevant -- its baseline is taken against the named pre-WP2 SHA
whenever it lands. An instrument unbuilt since 09-05 in front of the cause fix
would be "the cause fix is bigger" in reverse.

## WP1f -- a seed is deterministic

B10: two runs of one seed do not give one op journal, cause unestablished. The
candidate is per-process HashMap iteration order at decision sites; the fix is
sorted iteration there, never a fixed hasher. Lands with a test that two runs
of one seed produce one journal.

**Bounded, because a determinism hunt can run for days and the cause is
unestablished.** Step one is a journal diff of two runs naming the first
divergent op. If sorted iteration at that site does not close it in one round,
the fallback is in force: every seed in a measurement runs N times and any red
counts as red, stated per R3 beside the number. WP1a and WP2 then proceed under
an honest instrument rather than wait behind a hunt.

## WP1a -- the sealed oracle watches every sealed file

`assert_sealed_content_never_reached_the_clear` looks for one hash. It must
look for the plaintext hash and the real name of every body the workload wrote
into an encrypted folder -- **every version, cumulatively, because an old
version's plaintext is still a leak** -- MINUS every body the workload later
wrote into a plain folder by its own hand. That subtraction is not optional:
action 16 duplicates a file into any directory with no vault-edge gate, so the
workload itself copies sealed bodies plain and the oracle would false-red
without it. The workload records both sets as it goes.

First run is expected RED on seeds the estate calls green today. That red is
the baseline WP2 is measured against, and every existing per-seed number in
`drive_sync_estate_fidelity.md` is struck with a line saying which oracle it
was taken on.

## WP1b -- the swap chain oracle lands

Built and measured this week; lands NARROW, named for what it checks,
reporting *"N swap-separated pairs"* and never "N poisoned chains". `UNIQ` is
the default world for the arms it runs in: one re-baseline, then no dial. It
is blind to poisoning that arrives any other way and its report cannot say
otherwise. The general per-body provenance form is a follow-on spec whose
whole work is the rule for legitimate cross-lineage bodies (conflict copies,
restores); not a gate here.

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

## WP2 -- directory identity

`drive_directory_identity.md` is the design and stands: directories get an id
the VFS reports, folder records store it, four readers ask it. Its rule -- *the
id funds order, never identity; it corroborates, never claims* -- keeps a
recycled or foreign id survivable.

**Two decisions the design leaves open and must make before it is built:**

1. **The empty-vault mirror.** Rename `Private` to `Plain`, then make a new
   empty `Private`. No contents propose 501 -> Plain, the id may not claim, so
   the fresh empty directory keeps the vault's protection and the real vault
   mints plain. Either an ENCRYPTED folder's id may claim (a wrong claim on a
   recycled id over-seals, and over-sealing never publishes), or that shape is
   a hold. Undecided, it is the next AG. Decide, and pin it RED first.
2. **The no-id fallback.** A restore, a re-created sync root, a volume swap,
   `file_id` 0 on Windows: the folder has no id and falls back to something.
   Either the fallback is today's rules -- in which case fix 3's refusals stay
   for that world and WP3 does not remove them -- or the fallback is a hold.
   Decide here, not in WP3's counters.

**Also in WP2, because it is cause-level and cheap:** the upload-link gap. A
file this device WROTE never gets its inode-to-entity link; only a download
writes one, because the scan caches the hash first with no entity and the
upload takes the cached branch. Six lines, measured harmless, and it is what
the rescue's blindness rests on.

**Done means:** the pins in the identity spec are green, each proved RED without
the fix first; the empty-vault mirror and the no-id fallback are pinned as
decided; every arm's red count on the WP1a oracle is at or below its WP1a
baseline with no seed moving green to red; and the belts' fire reports read
as WP3 requires below. **A seed still red after WP2 is a finding about WP2,
written up as such, never a reason for a seventh belt.**

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

**R6. The four measurement traps are checked before a number is quoted:** a
dial whose values add instead of choose; an exclusion list with the same
defect; a flag read through an Option that reports false for absent; a control
that changes more than the thing under test.

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
- WP1f: two runs of one seed, one journal.
- WP1a: a workload-written sealed file (not the ring file) published; RED
  before 1a, with the reason it was green before in the pin's comment.
- WP1b: the swap chain oracle's own pin, RED on the pristine engine.
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
