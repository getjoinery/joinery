# Drive sync: two spellings on the server, one slot on the disk

**Status: DRAFT, not built. WARNING — the original reproducer (seed 99674) now
PASSES; the defect does not.** `f81388e6` incidentally fixed that one instance
while leaving the class intact. Anyone who runs the documented seed, sees green
and concludes this is done will be wrong.

Use a THREE-DEVICE reproducer instead:

```
SEED=111740 STEPS=70 DEVS=3 CHAOS=1 PLATFORMS=mac,windows,hfs NAMES=mac,pc,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

Seed 111120 (STEPS=90, same platforms) is a second. Both still loop, with the
same composed/decomposed `ApplyLocalMove` every pass.

This lines up with a negative result from the same batch: 800 seeds of the
two-device mac+HFS+ shape came back clean, while the three-device
mac/Windows/HFS+ arm produced both surviving instances. Whatever sets the trap
appears to want the third device — so a two-device reproducer is not just weaker
here, it may be extinct.

Diagnosis corrected twice and narrowed; no design.

What WAS built from the original spec — the `upload_new` recovery and the
stranded-park repair — is in
`specs/implemented/drive_sync_contested_name_recovery.md`. This is the remainder.

---

## Defect 3 — two spellings on the server, one slot on the disk

Found 2026-08-27; **diagnosis corrected after review, see below.** Committed arm
`hunt-normalisation` (99600..100000) in `scratch_fresh_hunt_sweep`.

```
SEED=99674 STEPS=60 DEVS=2 CHAOS=1 PLATFORMS=mac,hfs NAMES=mac,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

### What is actually happening

**The server legitimately holds two live files whose names differ only in
normalisation**, in one folder, with different content:

```
Sub 40 renamed/Sub 12/cafe\u{301}-17.txt   sha 24650884…   (entity 935)
Sub 40 renamed/Sub 12/café-17.txt          sha 90abe64a…
```

On the macOS device those are **one slot** — APFS keeps the bytes it is given
but compares without regard to spelling, so only one of them can sit there. The
device's entry 935 is `Synced`, claiming the decomposed name, while the single
disk file it can hold wears the composed bytes. Every pass pairs the two and
plans `move_remote` onto a name the composed twin already holds. Refused,
dropped, replanned, for ever. The HFS+ device is quiet.

### The earlier diagnosis in this document was wrong

It claimed the composed spelling was a rewrite the HFS+ device propagated. That
is false and was falsified three ways:

- **The workload cannot mint it.** `leaf(i)` selects spelling by `i % 5`, and
  `17 % 5 == 2` — the decomposed arm. `café-17.txt` was never the original
  name of file 17; it entered the world as a second file.
- **The HFS+ device renames nothing.** Across the run it plans zero move or
  rename ops for these files. What it does is `UploadAsNew { name: "café-17.txt" }`
  after a folder-move tangle costs both devices their entries for the original —
  and an upload-as-new from a decomposing volume legitimately carries NFC,
  because that volume's engine works in the composed spelling by design.
- **Both files are live on the server at the end.** The composed twin sits on
  both sides of the comparison, so it never appears in the convergence diff.

**The methodological error worth keeping:** a diff that prints only differences
was read as though it showed the whole picture. The composed file was present
and invisible. Dump both trees before concluding what a convergence failure
means.

### Where to actually look

The engine already has the designed answer for two server names that are one
local slot: `resolve_siblings` parks the loser `Unsyncable(UnicodeClash)`, and
the oracle's `held_back` excuses parked content by hash — so the intended end
state (winner materialised, loser parked and named) passes the strict oracle
with no weakening. The question is **why entry 935 is not on the parked side of
that decision.** The trace shows it `Synced` and claiming the slot on every
round.

**Narrowed 2026-08-27; the obvious suspect is eliminated.** The mac personality
is correct — `case_insensitive: true`, `decomposes_unicode: false`,
`normalization_insensitive: true` (the APFS constructor in `personality.rs`). So
`comparison_key` DOES fold NFC on that device, the two spellings DO share a key,
and `resolve_siblings` already has the branch that parks the loser
`UnicodeClash` when two remote names collide on one key. Nothing is missing in
either.

So the fault sits upstream of the decision rather than inside it: something
stops the two names being offered to `resolve_siblings` as siblings of one
folder.

**Narrowed again, and the naming layer is ruled out entirely.** `apply_naming`
groups by `competing_placement(entry).parent` over `all_entries`, skipping only
`OutOfScope` and remote-deleted — the grouping is right. The reason the resolver
never sees a clash is simpler: **mac holds only ONE of the two entries.** Dumped
at the end state, its store has `918` for the composed spelling in folder 504
and nothing for the decomposed one, while the server holds both, live, in that
same folder.

A resolver handed one name has nothing to park. So the question is no longer
about naming at all — it is why a device ends up with no record of one of two
live server files in one folder. That is a change-feed and absorb question.
Look there: the personality, `resolve_siblings` and `apply_naming`'s grouping
are all ruled out by inspection.

**One more candidate eliminated.** `merge_duplicate_files` folds only
PROVISIONAL entries into real ones, keyed byte-exact on `(parent, remote.name)`,
so it cannot fold one real entry into another and cannot see two spellings as
one key. Not the culprit.

**Where the next session should start.** `scratch_trace` with `WATCH` shows mac
holding the decomposed entry mid-run, and `scratch_dump` shows it absent at the
end — so the entry is being LOST rather than never learned. Find the round it
disappears and what ran in it. The remaining removers are `delete_subtree`,
`delete_entry` (reached from `trash_remote`, `forget` and `merge_file`) and the
stranded sweep; instrumenting those four is likely faster than reading them.

### A second reproducer, found 2026-08-27

```
SEED=111740 STEPS=70 DEVS=3 CHAOS=1 PLATFORMS=mac,windows,hfs NAMES=mac,pc,disk \
  cargo test --release -p jd-sim --test zz_sweep scratch_one -- --ignored --nocapture
```

Same shape, different file index: mac plans `ApplyLocalMove` from
`cafe\u{301}-7.txt` to `café-7.txt` for ever, the other two devices quiet. Not a
regression — it fails identically against the engine before f81388e6.

Two things this settles. The class RECURS, so it is not one unlucky seed. And a
mac+HFS+ pair alone did not find it: 800 seeds of exactly that two-device shape
came back clean in the same batch, while the three-way mac/Windows/HFS+ arm hit
it. Whatever sets the trap seems to want the third device, which is worth
knowing before anyone builds a two-device reproducer and concludes the fix works.

### How to prove it

- Seed 99674, from the committed `hunt-normalisation` arm.
- A deterministic scenario needs **two live server files differing only in
  normalisation, in one folder, plus a normalisation-insensitive device.** The
  scenario previously sketched here — one decomposing device and one
  precomposing device holding one file — does not reproduce this and should not
  be written.
- The Linux counter-scenario stands unchanged: where the two spellings are
  genuinely two files, both must materialise and a rename between them must
  still propagate. That is the half a careless fix breaks silently.

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

## Still open

- **This defect**, above: corrected diagnosis, no design. The personality and
  `resolve_siblings` are both ruled out; start at `apply_naming`'s sibling
  grouping.
- **The move-aside duplicate**, ranked behind it. The immediate disk-follow
  shipped in the implemented half shrank its mainline case to the crash window,
  and that window is now closed by adoption, so what remains is residual
  hardening for the occupant-arrival path.

The second-copy-on-disk churn that sat here is FIXED — it turned out to be the
same crash window, and the occupied-path arm disposes of a provably-redundant
blocker via the trash. See the implemented spec.

---

## The estate's blind spot is its name generator

Twice in one day a defect turned out to live outside what the harness can
produce, and both times it was the same cause: the workload's `leaf(i)` mints
names from a fixed table, so anything the table cannot spell is a class the
sweeps can never bite.

- It never mints a user file named `.jd-*`. So the first form of the oracle fix
  above — keyed on the park verdict rather than on where the name lives — would
  have failed convergence for ever over a file a user named themselves, and
  **no sweep would have shown the difference.** Only review caught it.
- It never mints a user file already wearing a conflict-copy-shaped name.
  (`(conflicted copy ` appears in the sweep file only as a filter that counts
  them.) That is a thing real users do — they copy a conflict file, or restore
  one from a backup — and it interacts directly with the conflict-name adoption
  the move-aside fix needs.

Both are worth an arm. `reference_sim_kinder_than_reality` says the same thing
in general: check the mock first.

**The constraint that matters more than the suggestion.** `leaf(step)` selects by
`step % 5`, and the workload is seed-addressed throughout. Adding arms to that
table changes the modulus, remaps every name in every world, and **silently
orphans every pinned seed** — 93128, 96223, 99674, the Phase 2 seeds, all of the
regression cover this document rests on. They would still run; they would simply
no longer be the worlds they were pinned for, and nothing would say so.

So any hostile-name work goes behind a dial that leaves existing seed-to-world
mappings untouched, in the `NOPLAIN` pattern: off by default, set only by new
arms over new ranges. A workload change that orphans the pinned reproducers
costs more than the blind spot it closes.

---

## Noticed in passing, not in scope

Neither is blocking and both may be known. Recorded so they are not lost:

- Seed 99674's world holds `Sub 9 (10)` **and** `Sub 9 (10) (10b)` as live server
  folders — a shape suggesting the folder land-beside can re-land beside its own
  earlier attempt under chaos.
- In the same run the mac device plans `TrashRemote 903` while its disk still
  holds that file. `no-loss` held only because both devices re-uploaded the
  content; that is luck, not design.
