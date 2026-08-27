# Drive sync: finishing the recovery from a contested name

**Status: DRAFT, for review. Not built.**
Written 2026-08-27 from soak-rig and sweep evidence. Three defects; the first
two share a theme, the third was found while hunting for them.

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

The file version should mirror it exactly, including the wait-first ordering.

**The server settles on the conflict name; this disk keeps the original.** That
is what the folder path does and it is the half most likely to be got wrong.

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
  the fleet settles, the local name is unchanged on the disk, the server gains a
  conflict-named copy, and nothing is lost.
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

### Open design question for review

Whether to make the park durable at the point of creation — journalling the park
together with its intended destination, so a lost follow-up is replayable —
rather than recovering after the fact from the name. Durable-at-creation is the
tidier invariant; recovery-from-the-name needs no new state and works on stores
that are already stranded today. They are not exclusive.

### Interaction to weigh

The 2026-08-26 widening of `move_remote`'s three orders to
`may_be_about_the_name` makes `park()` reachable on refusals that carry no
marker, so it **adds instances of this class**. It does not create it — 96223
predates it and fails without it.

Measured: no new stranding appeared anywhere across the full sweep estate
(~50,000 seeds) with the widening in place. If that ever changes, the cheap
mitigation is to keep the first two orders widened — neither strands anything —
and re-gate only the `park()` last resort on a positive `name_taken`. That would
cost the scenario
`a_move_refused_at_both_ends_by_a_silent_server_still_finds_its_way`, which
needs all three orders.

### How to prove it

- Seed 96223 must pass, from a committed sweep arm covering its range.
- A deterministic scenario for the recovery: a store holding an entry whose
  remote name is a scratch name, with the parking op still journalled. Assert
  the fleet settles, the file ends under a real name, and its content survives.
- A second scenario for the op-is-gone fallback, asserting the file becomes
  visible under a rescue name and an issue names it — because a give-up nothing
  can lift is just a quieter way to lose a file.

---

## Defect 3 — a name that differs only in normalisation reads as a rename

Found 2026-08-27 by an ad-hoc hunt over 98000-101200. A committed arm now covers
it — `hunt-normalisation` (99600..100000) in `scratch_fresh_hunt_sweep`.

```
SEED=99674 STEPS=60 DEVS=2 CHAOS=1 PLATFORMS=mac,hfs NAMES=mac,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

Two devices, macOS and HFS+ — both filesystems that normalise. The macOS device
plans, every pass, for ever:

```
ApplyLocalMove file 935
  from  "cafe\u{301}-17.txt"   (decomposed, NFD)
  to    "café-17.txt"          (precomposed, NFC)
  same parent
```

It is asking the server to rename a file to the name the server already has,
spelled the other way. The other device is quiet. Pre-existing: it fails
identically against the engine before 7eec8b56.

**Why the 2026-08-26 no-op-move collapse does not catch it.** That guard drops a
`Delta::Moved` whose `to` equals `entry.synced_placement` — compared with `==`,
which is byte equality. `café-17.txt` and `cafe\u{301}-17.txt` are not equal
bytes. On a filesystem that normalises they are one name, and the personality
already knows that: `tree::key_for` and the naming layer compare through it
everywhere else.

**Likely fix, to be confirmed:** compare through the personality's comparison key
in the collapse rather than with `==`. That would be a small extension of code
already written and tested.

**Care needed before assuming it is that small.** On Linux the two spellings ARE
different files, and a rename between them is a real user action that must
propagate. The comparison has to be the personality's, not a global
normalisation — which is the same distinction the rest of the engine draws, so
the shape of the answer is known even if the placement is not.

**How to prove it:** seed 99674 from a committed arm, plus a deterministic
scenario with a decomposing device and a precomposing one holding one file,
asserting the fleet settles and neither device renames anything on the server.
And a Linux counter-scenario asserting that a genuine NFD-to-NFC rename still
propagates, because that is the half a careless fix breaks.

---

## A related shape, for context rather than action

The rig has failed `convergence` three times in 270 runs. Run 302 is defect 1.
The other two are a different mechanism and are recorded here so the family is
visible, not because they are in scope.

| run | waiting on | ops queued |
|---|---|---|
| 228 | 4 `pending_download` | **none** |
| 229 | 1 `pending_download` | **none** |
| 302 | 7 `pending_upload` | minted and dropped each pass |

Runs 228 and 229 held entries in `pending_download` with **no operation at all**
— nothing had planned them, and nothing was going to. That is the shape
`reference_drive_forever_loops` calls shape 3: an entry the scanner is never
offered, so no pass ever considers it. Run 302 is not that; its ops exist, they
are just futile.

**Probably already fixed, and deliberately not being chased.** Runs 228 and 229
are adjacent, predate several relevant changes including `Recover interrupted
work on every pass, not once at startup` (21bddbec), and have not recurred in the
73 runs since. Reopening them on that evidence would be chasing a ghost.

What is worth having is the discrimination, because the two look identical in a
ledger and want different questions asked:

- entries in a `pending_*` state with **no op** → nothing planned them; shape 3.
- entries in a `pending_*` state with ops that **come and go between polls** →
  something plans them and the server refuses; defect 1.

Read the violation bundle's `state.db` for this, never the run directory's
end-of-run copy — they can be many cycles apart.

---

## Deliberately not in scope

- **Converting a vault folder dragged out of a vault.** Shipped 2026-08-26 as a
  refusal that says so once and goes quiet. Converting would publish a vault's
  contents in the clear on the strength of a drag, and the platform's own answer
  is to change the folder's protection level first. This leaves the two sides
  disagreeing about where that folder lives until the user acts — deliberately,
  and visibly.
- **A general "do not repeat a refused placement until something changes" note.**
  Considered as a unified fix for the whole forever-loop family and set aside:
  its correctness argument leans on the change cursor being stable, and
  suppressing legitimate work is a worse failure than repeating it. Revisit only
  with a clear invalidation rule.

---

## Ordering

Defect 1 first. It is failing the soak rig today, it is confirmed on the real
platform, and its precedent (`create_remote_folder`) is already written and
tested. Defect 2 is more severe per occurrence but rarer, and its fix wants a
design decision that Defect 1's does not.
