# Drive sync: a refused name read as a vanished file

**Status: BUILT and verified 2026-08-28.** Seeds 111740, 111201 and 111120 all
settle; all three looped before. Pinned as `frozen_contested_name_loop_seeds` in
`zz_sweep.rs`, verified to fail when the fix is removed.

What was built from the original spec — the `upload_new` recovery and the
stranded-park repair — is in
`specs/implemented/drive_sync_contested_name_recovery.md`.

---

## Defect 3 — a refused name is read as a vanished file

### What the user loses

The device never goes quiet. Nothing fails, nothing is queued, no issue is
raised, and the client burns a pass every cycle for ever. Each cycle can mint
another server copy, so the file count climbs without bound.

### The mechanism

When the upload executor looks for the bytes it is meant to send, it tries three
candidate placements. A candidate is skipped for either of two quite different
reasons:

- **no file is there** — the file has been moved or deleted; or
- **`mine()` refused it** — another entry already holds that name.

If no candidate survives, a *provisional* identity (one that exists only because
the scan found an untracked file) is **deleted**, on the reasoning that a
provisional with no file left anywhere can never be about anything.

That reasoning holds for the first case and fails for the second. When every
candidate was refused on the NAME, the file is still on the disk. Deleting the
identity says the opposite of what is true, and the next scan finds the same
untracked file, mints another identity, plans the same upload, and arrives back
here — silently, for ever.

Measured directly: at the moment of the drop, `file_present=true` for every
single dropped identity in seed 111120.

The refusal had **no action attached**. It did not park, warn or rename; it only
withheld — which is what made it a loop rather than a verdict.

### Why naming never rescued it

Deciding who gets a contested name is naming's job, and naming would have parked
the loser visibly. It never got the chance: the provisional is minted after
naming has already run for that pass, and the executor deletes it before the
next pass can offer it to naming. The identity never survived long enough to be
judged.

### The fix

Distinguish the two cases. If any candidate was refused because another entry
holds the name, do not delete:

```rust
if vetoed {
    return Ok(OpOutcome::Overtaken(
        "another file already holds this name".into(),
    ));
}
```

`Overtaken` drops the operation and leaves the record alone, so the identity
survives to the next pass, naming sees it, and the loser is parked visibly.

**Deliberately silent, and that is the load-bearing part.** `Withdrawn` was
tried first, on the reasoning that a file which cannot be uploaded under its own
name is something the user should be told about. It is the wrong outcome here:
only `unsyncable` issues are withdrawn again when their state ends (the
dismissal block at the top of a pass), so an issue raised from the executor
would describe a state that resolves one pass later and would then sit in the
user's list for ever with nothing able to clear it but a hand. The pass file
states that doctrine itself — a permanent warning about a file that is now
perfectly fine.

The durable state already has a voice: naming raises `unsyncable` when it parks,
and that issue has the full lifecycle. The transient one does not need a row.

**The cost of that choice, recorded rather than hidden.** Silence means a future
bug in which naming never arbitrates a contested name would also be silent in
production: a device that never goes quiet with no issue raised, caught only by
a sweep's `settle()`. That is the standing trade for every drop-and-replan path
in the engine, so this branch is consistent with the rest — but if the estate
ever grows a device-never-goes-quiet watchdog, this is one of the states it
should be watching for.

### It is not a normalisation defect

This document previously read the class as composed-versus-decomposed spellings
and pointed at `apply_naming`'s sibling grouping. That was wrong. The same loop
fires on `doc-30.txt`, `contested.txt`, `doc-50.txt` and a plain conflict copy.
The fold is only a neighbourhood that makes two records and one disk slot
common; it is not the cause, and a fix aimed at naming would have missed most
instances.

### A second fix was built, measured, and thrown away

Worth recording, because the argument for it was persuasive and the estate
refuted it.

`mine()` asks `holds_a_local_file()`, which excludes only `Unsyncable`,
`PendingKey` and `OutOfScope` — so an entry that came from the server and has
**never been downloaded here** answers yes and can refuse a name it has no file
for. The scan (`known_local`) correctly skips exactly those entries. Two layers,
one question, two answers.

Aligning them behind a shared `believed_on_this_disk()` predicate looked
obviously right, and it did fix seeds 111740 and 111201. It was dropped anyway:

- **It is not load-bearing.** The refusal fix above fixes all three seeds on its
  own.
- **It causes a vault regression.** Estate arms `kill-vault-2dev` (seed 80410)
  and `kill-vault-hostile` (seed 80563) pass without it and FAIL with it. Both
  are vault arms under kills, both were clean on the pre-fix baseline.

The reasoning that recommended it — that inside a vault the server cannot see a
real-name collision, so the old veto was a silent loop there too and there was
"no behaviour to regress from" — is contradicted by those two seeds. The veto
was doing real work in the vault case. Recorded so nobody rebuilds it from the
same argument.

What survives is the wording: the message no longer claims the rival file is "on
this computer", because a refusal does not prove that.

### The vault question that remains open

`mine()` is still the only thing that can see a real-name collision inside a
vault, where the server enforces uniqueness on an opaque per-file title and
cannot refuse a duplicate. The proper answer is a separate, small build:
**client-side land-beside for encrypted uploads** — before an encrypted upload,
check the real name against tracked siblings in that folder and mint a
conflict-copy name if taken. The client performs the role the server performs
for plaintext. Deliberately not bolted onto this fix.

### Two facts about the SIMULATOR, verified 2026-08-28

Both matter before anyone builds a fix, and the second may mean part of this
defect is the rig rather than the client.

**Sticky spelling is FAITHFUL, not a bug.** `MemFs::key_for` resolves every path
segment through `resolve_case`, which returns THE EXISTING KEY on a fold match
(`vfs.rs`). So once a node exists at a folded slot under one spelling, every
later write to the other spelling lands on it and inherits the stored one. Real
APFS behaves the same way — opening the decomposed name finds the existing file.
This dissolves what looked like a contradiction: a landing can be byte-perfect
decomposed and still leave a composed file, because the landing did not choose
the spelling; an earlier insertion did.

**A same-slot respell is a NO-OP in the mock, and is not on a real disk.**
`rename` resolves both `from` and `to` through `key_for`; for two spellings of
one folded slot those are the SAME key, so the remove-and-reinsert changes
nothing. Real APFS `rename(2)` does respell. Two consequences:

- any fix that works by respelling a file is silently eaten here, and its test
  proves nothing — **the mock must be corrected first**;
- and if the engine already attempts a respell somewhere, the mock is hiding it,
  which would make part of this defect a simulator artifact rather than a client
  one. Worth settling before designing anything.

**A latent trap next door.** `key_for` gates fold resolution on
`case_insensitive` ALONE. A personality with `normalization_insensitive: true`
and `case_insensitive: false` would silently not fold at all. No such
personality exists today and nothing at the gate says so.

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

- **Client-side land-beside for encrypted uploads**, above. Ranked first. Small and
  self-contained; the vault is the one place the server cannot referee a name.
- **The move-aside duplicate**, ranked behind it. The immediate disk-follow
  shipped in the implemented half shrank its mainline case to the crash window,
  and that window is now closed by adoption, so what remains is residual
  hardening for the occupant-arrival path.

Seed 115974, previously listed here as slow convergence, **does not reproduce**
under `STEPS=70` or `80` with the three-platform mix — it settles in under three
seconds. Either an earlier change closed it or the parameters it was found with
were never written down. It is NOT closed by this fix: it passes with the fix
removed as well. Recorded as unreproducible rather than fixed, because those are
different claims.

### The range is now swept

All three seeds came from an ad-hoc hunt over 111xxx, which sat in no committed
arm — and the sweep file's own doctrine is that a reproducer outside a sweep is
a reproducer that rots. Added `hunt-contested-name`, 111100..111800, 70 steps,
mac/Windows/HFS+, hostile. A new arm over a new range, which is the pattern that
leaves every existing seed-to-world mapping untouched.

---

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
