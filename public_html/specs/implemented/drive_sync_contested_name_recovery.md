# Drive sync: recovering from a contested name

**Status: IMPLEMENTED 2026-08-27.** Two defects in how the sync client answers a
server that refuses a name, both found by the soak rig and the jd-sim sweeps,
both built with tests that fail when their fix is reverted.

One of them was confirmed against the real platform: soak rig run 302 failed
convergence on it, and its signature is worth memorising — **convergence fails
naming `pending_upload` while `audited-green` and `no-loss` both pass in the
same cycle.** The tree is correct and the device is busy for ever.

The third defect in the original spec (two spellings on the server, one slot on
the disk) is NOT built and stays open; see
`specs/drive_sync_contested_name_recovery.md`.

---

## What is wrong, in plain terms

Two computers can reach for the same filename at the same moment. The server
lets only one of them have it, and the other has to be told to do something
else. The sync client knows several ways to do something else — put the file
beside the winner under a "conflicted copy" name, take the two steps of a move
in the other order, step aside into a temporary name and come back — and it
picks between them from what the server says when it refuses.

Two of those ways are not finished.

**A file that has to land beside a winner never lands at all.** If the server
already gave the name away and this computer never heard about the file that
took it, the upload is refused, the client quietly forgets the attempt, and the
next sweep of the disk decides to upload the very same file again. It does that
for as long as the computer is switched on. Nothing is lost — the bytes are
safe on the disk, and the other computer's copy is safe on the server — but the
tray never stops spinning, and no message ever appears explaining why.

**A file parked in a temporary name can be left wearing it.** When both ends of
a move are blocked the client steps the file aside into a scratch name of its
own invention, then moves it and renames it back. If the computer dies, or the
network drops, between the step aside and the rename back, the file is left on
the server under a name no person chose. From that moment every computer marks
it "cannot sync" — correctly, because a real file may not carry the engine's
reserved prefix — and skips it for ever. The file's real name is gone from the
record as well.

Neither is a data-loss bug. Both are silence bugs, which is the failure this
client's own comments say it is not allowed to have.

---

---

## Why this is worth doing now

The first one is not hypothetical. **Soak rig run 302 failed on it**, on
2026-08-27, against the real platform:

```
FAIL convergence: still working after 900s — device-a is attention
  (waiting on 7 pending_upload): 69 items need your attention
PASS audited-green: 2 devices agree with the server across 820 live entities
PASS no-loss: 470 live paths findable; all 561 contents ... still there
```

That combination is the signature and it is worth memorising: **convergence
fails naming `pending_upload`, while `audited-green` and `no-loss` both pass in
the same cycle.** The tree is correct and the device is busy for ever.

It went unnoticed for a long time because it is completely silent. The refusal
classifies as `Overtaken`, which by design raises no issue — the reasoning being
that a file deleted while its upload was in flight is nobody's problem. Here the
same silence hides a loop. The only visible symptom is a device that never
settles.

---
## Defect 1 — `upload_new` has no land-beside

### Evidence

Sweep seed 93128, arm `hunt-platform-longhostile` in `scratch_fresh_hunt_sweep`
(3 devices mac/Windows/HFS+, 80 steps, chaos):

```
SEED=93128 STEPS=80 DEVS=3 CHAOS=1 PLATFORMS=mac,windows,hfs NAMES=mac,pc,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

Device `pc` holds provisional file `-21`, `PendingUpload`, parent folder 504,
name `contested.txt`. The server holds a live `contested.txt` in that folder and
**pc's store has no entry for it** — its only children of 504 are its own
pending upload and two conflict copies from `mac`.

Run 302 is the same state on the rig: 7 provisional `pending_upload` entries, no
synced twin for any of them, the files present on both the disk and the server,
4 queued `upload_new` ops with `attempts=0` in the bundle while the last status
poll reported `pending_ops` 0 — ops are minted and dropped every pass, so the
count reads 0 or 4 depending on when you look.

Not a regression, and not the marker-less refusal dial: it fails with `NOPLAIN=1`
and against the pre-2026-08-26 engine.

### Why no local resolution fires first

The naming layer resolves clashes it can see. It cannot see this one: the rival
is a live file on the server that this device has never been told about. So the
clash is discovered only by the server refusing, and the refusal is the one
place a fix can go.

### The design

Give `upload_new` the recovery `create_remote_folder` already has, and keep the
two rules that path learned the hard way.

`create_remote_folder`, on `name_taken`:

1. **Wait** when the name is held by something *this device is itself renaming
   on the server* — a holder visible in our own store, under its server name,
   with a queued `move_remote` or `park_remote` (`held_by_a_rename_this_device_owes`).
   Renaming around a collision that clears itself in a moment hands the user a
   permanent conflict name for nothing.
2. **Land beside** in every other case, under `(env.conflict_name)(name, n)`,
   bounded at 1000 attempts.

The **wait-first rule and the 1000-attempt bound transfer unchanged.** The half
that does NOT transfer is where the conflict name lands.

### Where the conflict name goes — settled by experiment

Mirror the folder path exactly: **the server settles on the conflict name and
this disk keeps the original.** The wait-first rule and the 1000-attempt bound
transfer unchanged.

This was challenged in review on the grounds that files lack the two properties
that excuse the asymmetry for folders (same-placement merge, and the
impossibility of conflict-copying a folder locally), and that the end state would
leave the disk at a name its entry does not claim — a defect-3-shaped loop. The
proposed alternative was `PreserveLocalAs`: conflict-copy locally and upload
under the conflict name.

**Confidence: traced. The objection does not survive the experiment.** The end
state was hand-built directly in the store — record and server on the conflict
name, disk still wearing the original, occupant on the server and absent from
the store — and driven through passes. It settles in ONE round:

```
ours   -> report (conflicted copy 2026-07-31 from laptop).txt   preserved
theirs -> report.txt                                            materialised
issue  -> kept_aside: report.txt was moved aside to
          report (conflicted copy 2026-07-31 from laptop).txt
```

The disk keeps the original name only until the occupant syncs down. At that
moment the ordinary conflict-copy machinery moves the local file aside — to
**exactly the conflict name the server already chose**, so the two coincide
rather than diverging. No loop, no loss, and the user is told by name.

Two things this settles beyond the immediate question:

- The alternative would have reached the same end state by a longer road, and
  cost a change to the upload path to get there.
- Decoupling the two questions is what made it cheap: "does this end state
  loop?" was answerable by construction, without first building the recovery
  that produces it. Do that again for the next design fork.

**The one-round settle depends on a coincidence, and reality will not always
supply it.** Traced. The local move-aside mints its own conflict name from the
same generator, date and device attribution; in the experiment above those
happened to match the name the server chose at land-beside, so the two coincided.
Land beside on day N and let the occupant arrive on day N+1 — or let the two
halves run on different devices — and they diverge. Re-run with divergent names:

```
report (conflicted copy 2026-07-30 from desktop).txt   ours     duplicate
report (conflicted copy 2026-07-31 from laptop).txt    ours     duplicate
report.txt                                             theirs
```

It still settles, in two rounds rather than one, and nothing is lost. But the
user is left holding **two identical copies of their own file** under two
conflict-copy names, and the second is uploaded to the server as a third entity.
The occupant's download moves the local file aside under the local name; the
entry still points at the server's name; both materialise; the moved-aside copy
is then unaccounted for and uploads as new.

Not a blocker for the design — no loop, no loss — but it should be fixed in the
same change rather than discovered later. Three things constrain the fix:

- **Key it on identity, not on content.** The displaced file is not merely bytes
  that happen to match something under a server conflict name: it is the
  materialization of a specific entry, ours, whose `remote` already names the
  server-side conflict copy and whose local slot is about to be empty. So at
  move-aside time, if the file being displaced is paired with an entry whose
  remote name is free on disk, move it TO that name — the entry adopts its own
  copy. Matching by content hash gets the same answer here but picks arbitrarily
  where content collides without identity: a deliberate duplicate elsewhere in
  the folder, or two conflict names left from separate incidents holding
  identical bytes. Identity-keyed has no arbitrary case.
- **It has to beat the download, not follow it.** *Read-verified.* `scan.rs`
  pairs in fixed precedence and **same path wins first**. Once our entry's copy
  has been fetched to the server conflict name, the entry pairs as `Unchanged`
  and the byte-identical file one name away is never considered for it — it
  falls through to "genuinely new" and uploads. That is why the existing
  content-pairing rule did not save us here, and why renaming after the fetch
  fixes the name but still pays the fetch, or races it and duplicates anyway on
  a slow link. The adoption must cancel or pre-empt the planned download.
- **Do not suppress the general arm.** A displaced file that nothing accounts
  for SHOULD survive as a new upload; that behaviour is correct and load-bearing.
  The defect is only that this particular file had an owner.

**What remains untested** is whether the recovery can *reach* that end state — a
build question, not a design one.

**Method note.** Both of the results above came from hand-building an end state
in the store and driving passes, without building the recovery that produces it.
That separates "does this end state behave?" from "can we get to it?", and it
answered a design fork in twenty minutes that argument had already reversed once.
Reach for it at the next fork. It also found the duplicate, which no amount of
reasoning about the design had surfaced — including two rounds of review.

### The trap

Read `reference_drive_standing_to_know` before touching this.

- `entry.remote` is where the **server** has it. Only an answer from the server
  may write it.
- `synced_placement` is **not** "where this disk has it". Reconcile treats it as
  the entry's *claim on a name*, so it has to track `entry.remote`. Leave it
  pointing at a name the entry has left and any real file wanting that name is
  parked `Unsyncable(DuplicateName)` against a rival that is not there —
  permanently, because nothing in a settled tree releases it.

Four defects and a full day were spent learning that the mirror of the first
rule is false. The land-beside must thread the chosen name through
`drive_upload_init`, the completion, and the final `entry.remote.name =
placement.name` without disturbing the agreement.

### Why it was not done on the spot

The upload path is roughly 150 lines of placement selection before it reaches
the wire, plus init, chunking and completion. It is the most data-critical path
in the engine and the one where a wrong conflict-copy decision duplicates or
loses a user's file. It deserves its own sitting, not the tail of another.

### Existing machinery to reuse

`free_conflict_path`, `names_the_server_has`, `(env.conflict_name)`,
`Action::PreserveLocalAs`.

### How to prove it

- Seed 93128 must pass, as part of `hunt-platform-longhostile`.
- A deterministic scenario as well, because a seed is regression cover and not a
  statement of intent: a device with a local file whose name a live server file
  already holds, where the server file is **not** in the device's store. Assert
  the fleet settles, our content survives under the conflict name, the occupant
  materialises under the plain name, and a `kept_aside` issue names the move.
  The experiment above is most of this test already.
- Both must fail with the fix reverted. A test that passes either way proves
  nothing here, because the tree is already correct in this defect.

---

## Defect 2 — a park can be left standing

### Evidence

Seed 96223, three devices mac/Windows/HFS+, 80 steps, chaos:

```
SEED=96223 STEPS=80 DEVS=3 CHAOS=1 PLATFORMS=mac,windows,hfs NAMES=mac,pc,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

The seed came from an ad-hoc hunt over 95000–97400. A committed arm now covers
it — `hunt-platform-longhostile-2` (96000..96500) in `scratch_fresh_hunt_sweep`,
added 2026-08-27 — because a reproducer that lives in no sweep is a reproducer
that rots. That range has been swept once already and holds exactly one failure,
this one.

Server file 901 is named `.jd-swap-disk-66ada91174633dfa`. All three devices
hold it `Unsyncable(ReservedPrefix)` with `synced_name` equal to the scratch
name, and their disk copies have **diverged** — two different hashes at the one
path. The fleet reports itself settled.

Not a regression, and not the marker dial: 96223 is not divisible by 4, so the
dial never arms, and it fails against the pre-2026-08-26 engine.

### Which park made it

Two places mint scratch names, and telling them apart matters or the
investigation starts in the wrong file:

| site | purpose | token |
|---|---|---|
| `order.rs:160` | breaking a cycle of renames (`broken_cycles`) | `{device}-{server_id}` in the sim |
| `execute.rs:2471` | the `park()` last resort inside `move_remote` | `op.idempotency_key` |

`.jd-swap-disk-66ada91174633dfa` is the second form. This is the **move dance's
park**, not cycle breaking.

### Why nothing rescues it

`park()` succeeds; the step after it, or the whole operation, is lost — a kill, a
transport death, a withdrawal. The next pass asks the server where the entity
actually is (`server_view_after_retry`, and the standing-to-know rule says only
the server may answer), gets the scratch name, and correctly records it as
`entry.remote.name`.

From there the naming pass sees a reserved prefix. That verdict **is** derivable
from the name, so unlike a non-name give-up it is not recomputed away next pass,
and the pass loop skips every `Unsyncable` entry that is not remote-deleted.
Self-inflicted, and self-locking.

**The observation worth keeping:** `ReservedPrefix` is the one `UnsyncableReason`
the engine can inflict on *itself*. Every other reason describes a name a user or
another client chose, meeting this filesystem. `.jd-swap-…` is a name this
engine wrote. Treating it as a naming verdict turns an unfinished operation into
a permanent resting place. **An unfinished park needs recovery, not a verdict.**

### What was tried and rejected

An in-process undo — `if let Err(e) = reparent().and_then(rename) { unpark();
return Err(e) }` after `park()` — was built and measured on 2026-08-27. **It did
not move seed 96223 at all**, because the park there outlives the operation, so
no in-process handler ever runs. It was reverted rather than shipped, because
nothing could make a test bite it.

It may still be worth having as a second line of defence for the plain
call-fails case, but only alongside the real fix and only with a server dial
that can fail a specific call, so that a test can prove it.

### The recovery thread

The scratch name is `.jd-swap-{op.idempotency_key}`. **The name on the server
names the operation that made it**, and that operation's params carry the
destination it was going to. A repair can read the key out of the name and
finish the journey.

That only works while the op is still in the journal. If it has been dropped,
the destination is genuinely gone, and the honest fallback is a rescue name plus
a surfaced issue — a file under an odd name that a person can see beats a file
under an internal name that nobody can. Un-parking alone is **not** sufficient:
the disk copies also carry the scratch name, so local and remote agree and the
ordinary reconcile plans nothing.

### The fork was false, and the guard that decides it

Durable-at-creation versus recover-from-the-name is not a choice: **the journal
row already is durable-at-creation.** The op's params carry the destination
(`place(to)`, `execute.rs:218`) and the scratch name carries the idempotency key.
Journalling the park with its destination would record nothing the store does not
already hold.

What actually kills recovery is a guard in `move_remote` (`execute.rs:2343`):

```rust
if from.is_some_and(|f| entry.remote != f) {
    return Ok(OpOutcome::Overtaken("the server has moved it since this was planned"));
}
```

After a kill mid-dance, the next pass's index walk updates `entry.remote` to the
scratch name. When the recovered op re-runs, **the guard reads the op's own park
as somebody else's move** and drops the journal entry on the spot. So "recovery
works while the op is still journalled" has a window of about one attempt, on
precisely the kill path that produces the defect.

**Confidence: traced.** Constructed rather than staged — the aftermath of a kill
mid-dance is built directly: the park has landed on the server, the index walk
has recorded the scratch name as `entry.remote` (which is the truth), and the
recovered op re-runs. One pass:

```
after one pass: overtaken=1, ops left: 0
server:  Source/.jd-swap-key-parked-then-killed
entry:   (".jd-swap-key-parked-then-killed", Unsyncable(ReservedPrefix))
```

The guard drops the recovered op, the park stands, and the entry parks
`Unsyncable(ReservedPrefix)` — self-locked from that moment, skipped by every
later pass. **The whole defect materialises in a single pass.**

**The rescue arm is the worse half, and it is silent.** Same construction with
the op dropped from the journal first — which is the withdrawal path, and the
kill path once recovery has given up:

```
after five passes: done=0 withdrawn=0 overtaken=0 retry=0 quiet=true
server:  Source/.jd-swap-key-parked-then-killed
entry:   Unsyncable(ReservedPrefix)
```

The device goes **quiet**. It reports itself settled, raises nothing, and leaves
the user's file on the server under an engine-internal name for good. The resume
arm at least churns where a convergence check can see it; this one stops. So the
store-level rescue is not merely a fallback for stranded stores — it is the only
thing that ever addresses this arm, and it should be built alongside the resume,
not after it.

**Two assertions the tests must carry when the fix lands:**

- The resume test must assert the file ends at **the op's destination** (the `to`
  carried in `op.params`), not merely that the scratch name cleared. Resume means
  finishing the dance. A fix that unparked to the ORIGINAL name would pass a
  weaker assertion while silently discarding the user's move.
- The rescue arm needs **its own construction with the journal empty**, because
  the resume path can never reach it and a passing resume test says nothing about
  it. The variant above is that construction, already written.

Also settled by the trace: `overtaken=1` means the recovered op DID reach
`move_remote` with the entry already parked `ReservedPrefix`, so a resume check
placed before the from-guard genuinely gets its one shot per restart. The
construction proves the shot happens.

**Use this as the reproducer, not seed 96223.** The seed needs 80 steps, chaos
and a three-platform fleet to arrive somewhere this construction reaches
directly; keep the seed as regression cover, but debug against the construction.
It is `scratch_park_then_recover` in `jd-sim/tests/scenarios.rs`, and it should
become a real test — failing now, passing when the resume-before-guard lands.

### The design, therefore

Two parts, and the first is small:

1. **In `move_remote`, before the staleness guard:** if
   `entry.remote.name == swap_name(&op.idempotency_key)`, this is our own
   half-finished dance — resume it (reparent, rename) rather than call it
   overtaken. That realises the durable-at-creation invariant with no new state.
2. **A store-level repair, still needed regardless:** an entry whose remote name
   carries the engine's reserved prefix with no journalled op behind it gets a
   rescue name and a surfaced issue. That covers stores stranded today and the
   withdrawal path, where there is no op left to resume.

### Interaction to weigh

The 2026-08-26 widening of `move_remote`'s three orders to
`may_be_about_the_name` makes `park()` reachable on refusals that carry no
marker, so it **adds instances of this class**. It does not create it — 96223
predates it and fails without it.

Measured, with a correction. The original sentence here read "no new stranding
appeared anywhere across the full sweep estate (~50,000 seeds)". That measurement
ran through the sweeps, which rest on `assert_converged` — and `assert_converged`
at the time EXCUSED exactly this stranding, because `held_back` waived the
content of every `Unsyncable` entry including a `ReservedPrefix` park. So what it
actually measured was "no new stranding the oracle can see", which is a weaker
claim than the one it was making.

Re-measured under the stricter oracle described above, on the park-heavy arms —
the ones where a stranding would live:

```
kill            1800 seeds   7034 kills   clean
kill_platform   1400 seeds   5742 kills   clean
rich            2870 seeds                clean
platform        3600 seeds                clean
                9670 seeds  12776 kills   0 failures
```

That is a real measurement rather than an oracle-limited one, and the widening
call stands on it. It is 9,670 seeds and not 50,000; the remaining arms have not
been re-run under the stricter oracle and their earlier green is still
oracle-limited.

**Keep it; do not pre-emptively re-gate.** The risk it amplifies is exactly this
defect, and the fix above removes the sting — a park that resumes, or is
repaired, is an extra hop rather than a stranding. Re-gating now would cost a
live scenario
(`a_move_refused_at_both_ends_by_a_silent_server_still_finds_its_way`, which
needs all three orders, and which exists because the rig's own core answers
without markers) to buy insurance against a defect being fixed in the same
change. The mitigation stays named as a contingency: keep the first two orders
widened — neither strands anything — and re-gate only the `park()` last resort.

### How to prove it

- Seed 96223 must pass, from a committed sweep arm covering its range.
- A deterministic scenario for the recovery: a store holding an entry whose
  remote name is a scratch name, with the parking op still journalled. Assert
  the fleet settles, the file ends under a real name, and its content survives.
- A second scenario for the op-is-gone fallback, asserting the file becomes
  visible under a rescue name and an issue names it — because a give-up nothing
  can lift is just a quieter way to lose a file.

---
## What the implementation review changed

Reviewed 2026-08-27 against the full diff, plus the real PHP where the mock's
fidelity was the question. Five findings; three were defects in the change and
are fixed, two are documentation.

**1. The adopt arm skipped the disk-follow, and its own crash window walked into
it.** Adoption returned before the local rename. Trace the land-beside crash
window: the upload lands under conflict name *n1*, the process dies before the
rename, recovery re-runs, the original name is still refused, the SAME *n1* is
minted again (deterministic generator, counter restarts) — and *n1* is now held
by our own crashed upload, so the hashes match and adoption fires. The record
moves to the conflict name and the disk keeps the original: the exact gap this
change exists to close, reproduced by its own recovery. Both arms now share
`follow_the_server_name`. Test: `a_land_beside_that_died_before_its_rename_heals_on_the_next_run`.

**2. The rescue livelocked when the agreed name had been retaken.** Asking for a
name somebody else now holds is refused, the rescue op is overtaken, the rescue
is planned again next pass — noisy rather than silent, but never settling. It
now lands beside, which is doctrine: park is a naming verdict, and a give-up that
is not about the name goes BESIDE the agreement rather than into it. Test:
`a_rescue_whose_name_was_retaken_lands_beside_it`.

**3. Two silent skips.** The land-beside rename was skipped when the destination
was occupied or unplaceable, and the rescue did nothing when there was no agreed
name to return to — in both cases leaving exactly the posture this change
prosecutes, and saying nothing. Both now raise an issue even when they cannot
act.

**4. The kind-mismatch case is unconstructable and needs no test.** Verified on
both sides: the real server's `file_name_taken` checks `fil_files` only and
`folder_name_taken` checks `fol_folders` only, both byte-exact in SQL, and the
mock's branches are per-kind to match. A folder cannot refuse a file upload —
the namespaces are disjoint. The `holder_type == "file"` guard stays as contract
safety against a future unified namespace.

**5. Contract coverage is narrower than the helper suggests.** Only the upload
file-collision site emits the enriched refusal. Folder-create, rename and
reparent still send the bare marker. That is fine for this change and NOT fine
for defect 3, whose work will want rename enriched.

**Verified on the real server, all favourable:** the refusal fires at
`upload_init`, before any byte crosses, so the retry loop and the enrichment cost
nothing on the wire. The client already sends the plaintext hash at init and the
`fil` row holds names and hashes, so the PHP enrichment is a small change at two
refusal sites. Byte-exact comparison means `holder_name` cannot differ from the
requested name on today's platform — that plumbing is future-proofing and is
currently unreachable. And `upload_init`'s existing dedup short-circuit fires
only when the name is FREE, so adopt complements it with no overlap.

---

## Still open about this work

Neither blocks what was built; the client ships and degrades correctly without
them.

- **The PHP enrichment**, which activates adoption fleet-wide. Today only the
  mock sends the enriched `name_taken`; against a real core the client sees the
  bare marker and lands beside, which is the conservative direction and exactly
  the behaviour this spec originally described. The server already has the
  colliding row in hand when it refuses, and `parent_trashed` shows the shape.
- **Contract coverage.** Only the upload file-collision site emits the enriched
  refusal. Folder-create, rename and reparent still send the bare marker. Fine
  here; the normalisation work will want rename enriched.


---

## A gap found after shipping, 2026-08-27

Fresh-range hunting (110000-114400, 4,400 seeds) turned up three never-settles
that are NOT regressions — all three fail identically against the engine before
this change — but two of them are upload loops this change was meant to end, and
understanding why is worth recording.

**Seed 110980 — an unmarked refusal reaches no recovery at all.** `110980 % 4
== 0`, so the sweep's marker-less refusal dial is armed: the refusal carries no
`reason`, `name_taken()` is false, and the upload loop's arms are exhausted —
wait-for-rename, adopt, `name_taken` land-beside, then `Err(e) => return
Err(e.into())`. **There is no unmarked arm in the upload path.** Zero name
attempts; straight to withdrawn; entry stays `pending_upload`; next pass plans
it again. For ever.

A hypothesis raised and dropped in review, recorded so nobody re-chases it: that
the loop was really the parent FOLDER create (which does have a cap) with the
file waiting on it. The journal settles it against that — the file's own
`upload_new` is withdrawn carrying name PROSE in its message, which is precisely
the no-unmarked-arm story: the dial strips the marker, not the words, so the
message still reads like a name clash while `name_taken()` is false. The file's
own upload is the loop.

(An earlier draft of this section blamed a two-attempt cap. That cap is in
`create_remote_folder`, not here, and the correction matters: the fix is not
"raise a cap" but "there is nothing to raise". Any real fix has three sites to
consider — upload, create, and move, which gates on `may_be_about_the_name` —
which argues for one shared shape rather than three arms.)

**The design, reviewed and not yet built.** Raising or adding a cap is the wrong
instinct, because repetition carries no information: a quota, validation or size
refusal answers every candidate name identically, so N unmarked refusals across
N names is exactly what a NON-name refusal produces. Escalating on repetition
fires precisely on the case a cap exists to protect. And asking the server what
holds the name is not available — there is no folder-scoped read, only a
whole-tree cursor walk.

So spend one call turning unknown into known. After the deterministic
candidates, ask for a name that CANNOT already be taken — derived from the op's
idempotency key, so it is unique by construction and DETERMINISTIC, and a
crash-retry replays the same request and dedups.

- **Probe accepted** — it was the name all along. The file is up, converged,
  wearing an ugly but true name, and `follow_the_server_name` already moves the
  disk to match.
- **Probe refused** — nothing could have been holding that name, so the refusal
  is not about the name. Now the give-up is justified by evidence rather than by
  exhaustion.

Encrypted uploads confirm the shape: their names are already unique by
construction (`enc-{content id}`), so an unmarked refusal there is not-the-name
by definition and should skip the probe entirely. The probe gives plaintext
uploads the same epistemic footing encrypted ones get for free.

**The give-up must be a note BESIDE the agreement, never a status.** Park is a
naming verdict, recomputed each pass from names and keys, so a non-name reason
stored there is erased next pass and the work re-planned — a lesson already paid
for once. The template is the `unreadable` table: keyed on the request's inputs
— (parent, name, content hash), because an unmarked refusal does not say which
one the server objected to — self-clearing the moment any of them changes, with
a raised issue whose dismissal is the manual retry.

One further self-clear, because "old cores only" cuts both ways: that population
upgrades, and a terminal note would outlive the defect, leaving files stuck
until touched. Record per-server that it has never sent a reason marker; the
first 422 that arrives WITH one is proof the core now speaks, and clears every
such note for that server. Evidence-keyed, no expiry clock.

Reproduce:Reproduce:

```
SEED=110980 STEPS=90 DEVS=3 CHAOS=1 PLATFORMS=mac,windows,hfs NAMES=mac,pc,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

**Seed 111201 — a different shape, not this defect.** `done: 1` every pass: the
upload SUCCEEDS and something re-mints it. The seed is not divisible by four, so
the marker is present and both arms were available. The name is `DOC-24.TXT`,
the case twin of `doc-24.txt`, on a fleet including two case-insensitive
volumes — so this looks like the case-clash sibling of the unbuilt normalisation
defect rather than anything about refusals. Same reproduce line with SEED=111201.


---

## A failure this change made QUIETER, not smaller — seed 121109

Found 2026-08-27 by a case-clash-targeted hunt (Windows + macOS + Linux, 70
steps, chaos).

```
SEED=121109 STEPS=70 DEVS=3 CHAOS=1 PLATFORMS=windows,mac,linux NAMES=pc,mac,lin \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

**Before this change:** `lin` never settled.
**After it:** all three devices settle, and `lin` is missing a file the server
holds — `Sub 4 (39) (39b)/Sub 25/Sub 31/slot-1.dat` — while reporting itself
healthy.

No data is lost: the server has the file and the other devices materialize it.
But the failure has changed character in the wrong direction. A device that
loops is loud and a user reports it; a device that says it is finished while a
file is missing is the silence this engine's own comments say it is not allowed
to have.

**This is not a regression in the usual sense** — the seed failed before and
fails now — which is exactly why it could be missed: a sweep that only asks
pass/fail sees no change. It took comparing the failure MODE across the commit
boundary to see it.

**Worth checking before the next change lands:** whether any other seed on the
"was already failing" list has quietly changed shape the same way. A blanket
before/after over known-failing seeds, comparing the panic TEXT and not just the
verdict, would answer it cheaply.

Not diagnosed further. Whether the missing file is a consequence of adoption,
the disk-follow, or something the change merely unmasked is unknown.
