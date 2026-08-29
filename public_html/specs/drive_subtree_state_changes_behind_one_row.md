# Drive: a subtree changes state behind a single change row

**Status: BOTH BUILT and verified 2026-08-28.** Two defects, one cause. The
first is a production data-visibility bug reachable by two ordinary clicks, with
no race and no fault injection. Found while fixing sweep seed 93128, which now
passes.

---

## What the user loses

**Trash a folder, then restore it. Every synced device stops being able to see
anything that was inside it.** The files are safe — they are on the server, they
are live, the web UI lists them. But no synced computer has any record of them
any more, and nothing will ever offer them again. The folder comes back empty
on every device and stays that way.

The second defect is narrower and needs a race: **a file moved out of a folder
just before that folder is deleted can disappear from the device that did the
deleting**, even though the server correctly kept it.

Neither says anything. No error, no issue, nothing to click.

---

## The one cause

The change feed is the only way a device learns what happened. A cascade on the
server touches many entities and reports **one** of them.

Verified in the PHP, 2026-08-28:

| verb | what the server does | what the feed says |
|---|---|---|
| trash a folder | `DriveHelper::soft_delete_folder_cascade` soft-deletes every descendant, recursively | one `KIND_TRASHED` row, for the folder (`logic/drive_trash_logic.php:41`) |
| restore a folder | `DriveHelper::restore_folder_cascade` un-deletes every descendant at/after the cutoff | one `KIND_RESTORED` row, for the folder (`logic/drive_restore_logic.php:114`) |

The cascade helpers call `$file->soft_delete()` / `$file->undelete()` directly.
Neither records a change. **Every one of the sixteen `FileChange::record`
callsites is single-entity and lives in `logic/`** — there is no cascade
recording anywhere in the codebase, so silence is structural rather than an
oversight in one verb.

A device cannot recover from a row it never received. The descendants' own
change rows are older than the device's cursor, and nothing enumerates the
children of a folder the device already knows about. The miss is permanent.

### Why trash alone looks fine

On trash the client guesses, and the guess is usually right: `trash_local` and
`trash_remote` forget the whole subtree from the client's own parent pointers
(`store.rs`, `delete_subtree`). The server did trash them all, so forgetting
them all matches.

It is the **restore** that has no guess available, and the **spared child** that
makes the guess wrong.

---

## Defect 1 — restore leaves the contents recordless

1. User trashes `Work/` in the web UI. One `trashed` row.
2. Every device forgets `Work/` and everything under it. Correct.
3. User restores `Work/`. The server un-deletes the whole subtree. One
   `restored` row.
4. Every device recreates the record for `Work/` **and nothing else**.

The contents are live on the server and recordless on every client, with all
their change rows behind the cursor. Permanently invisible on every synced
device.

Nothing rescues it. The strand sweep (`pass.rs`, `sweep_stranded_entries`) finds
entries whose parent is missing — but these entries do not exist to be found. A
deleted record is the opposite of a stranded one.

**This needs no race, no chaos and no second device.** Two clicks by one user.

### Built

`poll_remote` kept only the entity from each change row and threw the kind away.
It now reads the kind, and a `restored` row on a **folder** is answered the way
a feed reset is answered: walk the index. That is what the row actually means —
a hole in coverage — and the kind is the only thing that separates it from a
folder that was merely created, whose contents are still arriving as rows of
their own.

A restore is a rare deliberate act, so one walk each is a bounded price for the
only thing that makes the contents visible again.

Pinned by `a_restored_folder_brings_its_contents_back_on_every_device` in
`jd-sim/tests/scenarios.rs`, verified to fail without the change (`laptop never
got the restored file back`) and pass with it.

The simulator's restore had to be corrected first — it flipped the one entity
and did not cascade at all, so it could not produce the defect. It now restores
the subtree and still reports one row, as the platform does. Nothing in any
sweep exercises restore, so this changed no seed's world.

---

## Defect 2 — a folder trash forgets a child the server spared

Both cascades read their children **at the moment they run**, under a placement
lock (`MultiFile(folder_id => X, deleted => false)`). A child that has already
moved out is correctly spared. The client forgets it anyway, because it forgets
from its own last belief.

Sweep seed 93128 is this, via a lost answer: the device moves `Sub 8` out of
`Contested Folder` and deletes `Contested Folder` in the same batch. The
`drive_move` commits on the server and the **answer is lost**, so the client's
parent pointer still says `Sub 8` is inside the doomed folder. The trash lands,
the server spares `Sub 8`, and `delete_subtree` forgets `Sub 8`, the file
beneath it, **and the queued retry of the move**.

The folder comes back — its own move row is still ahead of the cursor and
re-creates it. The file inside it never does. It has no row of its own, because
it never moved; only its parent did.

**The lost answer is one way in, not the defect.** A second device moving a
child out between this device's poll and its trash landing opens the same
window with no fault at all. It is a time-of-check problem, and the general
shape is: *the server's cascade takes descendants as they are at trash time;
the client forgets descendants as it last believed them.*

### The invariant

> A record may not be deleted on the strength of a belief the server has not
> confirmed.

---

## The fix, as built

**Confirm before forgetting, by stat.** When forgetting a folder's subtree,
enumerate the believed descendants from the local store, stat them, absorb the
answers through the ordinary path, and delete only what the server confirms gone
— `deleted: true`, or absent. A spared child stats back alive at its new parent,
absorption re-parents it, and it stops being a descendant. It survives
structurally rather than by exemption, which is why this covers the lost
answer, the second device and a lost stat identically.

This was nearly rejected on a false cost. `drive_stat` is batched — 500 per call
server-side (`DRIVE_STAT_MAX`), and the client already chunks at 500 — so
confirmation costs **one extra call for almost any real folder**, bounded by
subtree size and paid only on deletes. A stat that fails takes the whole
operation with it rather than falling back on belief — it retries, and trashing
is idempotent.

Built in `forget_folder_the_server_confirms` (`execute.rs`), used by
`trash_local` and `trash_remote`. Pinned by
`a_folder_trash_must_not_forget_what_the_server_spared`, verified to fail
without it with `a record was deleted on the strength of a belief the server
never confirmed`, and by seed 93128 itself.

**Provisional descendants are forgotten with the root, never statted.** A
provisional has no server side to ask about, and asking is actively harmful:
both `drive_stat` implementations drop every id at or below zero, so a
provisional comes back in neither `items` nor `missing` — and a request that is
*all* provisional leaves nothing to ask, which both refuse with a 400 that
classifies as `Withdrawn` and puts a spurious warning in front of the user.
Belief is the only account of a provisional that exists. Nothing is lost by it:
a local file that never reached the server is rescued out of the folder before
the trash, not forgotten with it.

`store.forget_entry` forgets ONE id across all four tables, so a confirmed
deletion leaves nothing behind without ever widening the set from belief the way
`delete_subtree` does.

### Not applied to the `forget` verb, deliberately

The same stale-belief argument applies to `forget`'s folder arm on paper, and it
was built, measured and reverted.

`forget` is reached when the folder is gone from the SERVER, and asking about
the children then invites the opposite failure: a child the server still answers
for is kept, under a parent the op is about to delete, and is stranded — the
state `delete_subtree` exists to prevent, which cost soak run 209 six live
files. `forgetting_a_folder_takes_what_was_under_it` holds that line and fails
the moment the confirmed forget is wired in.

It is also the weaker case in practice, and a test for it could not be made to
bite. Stale belief has to survive the Move stage, which runs first and repairs
the pointer through `server_view_after_retry` on any retry. That is exactly why
the trash paths can lose a spared child and this one could not be made to: in
seed 93128 the move's FIRST attempt loses its answer in the same pass as the
trash, so no retry-repair has happened yet.

This section is about defect 2 only. Defect 1's fix — reading the change kind
and walking on a restored folder — is built; see **Defect 1 → Built** above.

### Rejected: forget only the folder and let the strand sweep repair it

Correct, and expensive in exactly the place that matters. It works in the
simulator — where descendants get their own rows — and costs a **full index walk
on every folder delete** against the real one-row feed, because each descendant
is left with a live record and a missing parent. This is the option the
simulator's divergence made look cheap.

### Optional, not required: emit a row per cascaded descendant

It would buy convergence latency rather than correctness, and it is a real cost:
N rows written inside a cascade that holds a placement lock across its
direct-children sweep, feed growth, and grant-scoped fan-out. If it is ever
done, **restore must emit them too**, or defect 1 survives untouched. Owner's
call, and not a prerequisite for either fix above.

---

## The simulator does not model any of this

`jd-sim`'s mock diverges from the verified platform three ways, and its comment
claims it mirrors the real endpoints:

1. it emits a trash row **per descendant** where reality emits one;
2. its restore does not cascade at all — it flips the one entity;
3. it has no re-root or rename-on-collision behaviour on restore, which the real
   `restore_folder_cascade` has.

This is `reference_sim_kinder_than_reality` inverted: the mock tells the client
**more** than production does, so a fix that leans on descendant rows goes green
in the estate and is wrong in the field. Defect 1 is invisible to every sweep
because the mock cannot produce it.

**Fixed.** The mock's trash now cascades the flags and records one row, and its
restore cascades. Every existing scenario still passes without the descendant
rows, which is the evidence that nothing was leaning on the over-reporting.

### It re-baselined the estate

Changing the trash from per-descendant rows to one row changes server behaviour
for every seed that trashes a folder, which is most of them, so the estate was
re-run from scratch behind it. The restore correction cost nothing by contrast:
no sweep restores anything.

---

## Related, already built

Seed 93128's **other** half is fixed and is not part of this spec: `upload()`
could not act on a refusal carrying no machine-readable reason, so its
land-beside was unreachable against a server that answers in prose and the
device looped for ever. Fixed by widening the wait-for-an-owed-rename arm to
`may_be_about_the_name()` and adding a separate plain-refusal arm capped at two
attempts, mirroring `create_remote_folder`. That fix closed the loop and exposed
defect 2 underneath it.

---

## Still open

- `trash_local`'s AncestorMissing arm and its file-standing-at-the-folder-path
  arm, and the strand sweep's `remote_deleted` arm, all still forget a subtree
  from belief. Each needs a degraded precondition first, and each may turn out
  to be `forget`-like rather than `trash`-like — reachable only where the
  stranding cure is worse. None has a reproduction yet.
- `missing` is not `trashed`. The stat contract says missing means gone OR no
  longer visible, so an entity this caller merely lost access to counts as gone.
  Right for a record whose purpose is to tie a local file to something
  reachable, but two server statements wearing one flag. Noted at the filter.
- The mock's restore still does not model the platform's selective-restore
  cutoff, or its re-root-and-rename-on-collision.
- `DriveHelper::can_read` on a soft-deleted entity for its owner — whether a
  trashed item stats as `deleted: true` or as absent. Either answer feeds the
  same delete decision, so nothing above depends on it, but the fix should look.
