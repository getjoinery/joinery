# Drive sync: what the estate could not see

**Status: mostly BUILT and verified 2026-08-29; one change HELD, see below.**
Four fidelity defects and two new name classes. Found by auditing the estate's own blind spots rather than by a
failing seed — every one of these was green in a 36,170-seed run.

---

## The pattern worth keeping

Three separate defects this week came from the same place: **the simulator
answering more generously than the real thing.** Not a mock that is too harsh
and produces false failures, which is loud and gets fixed. A mock that is too
kind, which produces false *confidence* and never gets fixed, because a green
sweep looks exactly like a correct client.

The estate cannot find a defect in a class it cannot produce. So the question
that pays is not "what failed" but "what could this harness never have shown
me". All three below answer that, and none was reachable by running more seeds.

---

## Defect 1 — a recovery that could not fire in production

**What the user loses.** A file left on their disk under a name nobody chose:
`.jd-swap-{key}`, the scratch name the engine uses for one step of breaking a
rename cycle. If the operation behind it dies, the file stays there. It never
uploads, because the server refuses the reserved prefix for a real file. It is
never cleaned up. It is a dotfile, so they will not find it.

**The mechanism.** `observe` (`pass.rs`) walks the disk and has the recovery:
anything wearing `SWAP_PREFIX` that is not a live swap gets trashed. It walked
`read_dir` — and `OsVfs::read_dir` filters out every name starting with `.jd-`
before returning. The branch tests for exactly the names the listing has already
removed, so **on a real filesystem it can never be entered.**

`MemFs` had no such filter. So in the simulator the branch fired, the recovery
worked, and every sweep reported it working — for a path that was dead in
production for its whole life.

**The fix.** A separate `read_dir_all` on the `Vfs` trait, unfiltered, used by
the one caller whose job is to see what the filter hides.

Relaxing `read_dir` instead was considered and rejected. It would have worked
today — `observe` already self-handles internal names, `observed_dirs`
self-filters, and the four other callers are existence checks indifferent to
extra children. But it silently changes `rescue_unsynced`, a callsite this work
never set out to touch, and more importantly it converts safety from a property
of the default listing into an unenforced convention every future caller has to
remember.

Pinned by `an_abandoned_scratch_file_is_cleared_off_this_disk`, verified to fail
without the change.

---

## Defect 2 — the simulator did not hide what the disk hides

`MemFs::read_dir` now filters reserved names exactly as `OsVfs` does. Without
it, defect 1 is untestable: the harness hands the engine files a real disk never
shows it, so the fix and the bug look identical from inside a sweep.

**It cost nothing to align.** The pinned seeds — 111740, 111201, 111120, 93128,
96223, 99674 — are unchanged, and the full estate is clean behind it. The sim's
spool never lived in the tree, so the only names the filter touches are park
names.

---

## Defect 3 — the oracle was blind by folder depth

`disk_tree` skipped a path when `is_internal` matched **the whole path string**,
which is only ever true of a root-level name. A lingering scratch file at the
root was therefore invisible to convergence, while the identical file one folder
down failed it.

Blindness that varies with depth is worse than blindness: it makes a defect look
like a flaky seed. Now split per component, which is what the sibling filter a
few hundred lines below in the same file was already doing.

---

## The name generator, and a negative result

`leaf` mints names from a fixed table by `step % 5`, so a class the table cannot
spell is a class no seed can ever reach. Two were named as missing: a file the
user calls `.jd-*`, and a file already wearing a conflict copy's name.

**The constraint that shapes the fix.** Adding an arm to that table changes the
modulus, remaps every name in every world, and silently orphans every pinned
seed. They keep passing; they are simply no longer the worlds they were pinned
for, and nothing says so.

So the new class arrives as `Names::Hostile`, a **parameter** rather than an
environment variable — arms share a process, and an env var would silently
re-world every arm in the run. The ordinary table stays frozen. Verified: all
six pinned seeds produce identical results with the dial present.

`scratch_hostile_name_sweep` covers 120000..122100 across six arms — clean,
hostile, three-device, cross-platform, killing and vault.

**Result: 1,900 seeds, zero failures.** A file already wearing a conflict copy's
name does not break the conflict machinery, which was the specific worry — that
machinery had been free to assume it minted every name of that shape itself. A
negative result, and worth pinning precisely because the class was unreachable
before.

---

## The second name class: names one computer can hold and another cannot

A reserved DOS stem, a character Windows forbids, a trailing dot Windows
strips. All legal on Linux, none writable on Windows, and `to_local_name`
escapes them — so the file lands under a name that is NOT the one the server
holds, and everything downstream has to keep the two apart.

Added as `Names::WindowsHostile` on 123000..124800. Finding it required fixing
two things in the harness first, and the ratio is the point: **the first run was
307 failures out of 400, and not one of them was a defect.**

**The oracle could not express an escape.** `assert_converged` compared raw
server paths against the disk, which is only valid when no escaping is needed —
true of every previous sweep because the ordinary table cannot spell a name any
filesystem objects to. It already transformed the server path for one platform
rule (Unicode normalisation); escaping is the same idea, and now goes through
the same `to_local_name` the engine uses. A component that cannot be
materialised at all drops its whole path from the expectation, since refusing to
write it is the designed end state.

**The workload created files that cannot exist.** It minted `CON.txt` ON the
Windows device. Real Windows refuses that at the door; `MemFs` enforces no
naming rules at all, so it accepted the name and uploaded it raw, leaving the
peer to sync down a name its own record said it should be escaping. `leaf` now
takes the device that is about to create the file and only mints a hostile name
where it is legal — so it reaches the filesystem that objects the way it does in
life, by syncing.

### Defect found: a move ignored the escape

`path_for` built a probe entry with `local_name: None`, so `effective_local_name`
fell back to the placement's raw server name and **every move destination was
the unescaped name**. Fresh downloads were unaffected, which is why nothing had
ever caught it: a file created hostile landed escaped, and only a file RENAMED
into a hostile name landed raw.

On a real Windows volume that write is silently altered — `plain.` becomes
`plain` — so the file ends up somewhere the engine never looks while the record
insists it is elsewhere.

Ten of the twelve failing seeds went green with the leaf escaped in `path_for`.

### Held for review, because the fix may be implicated

Seed 123010 does not settle, and it now holds TWO files where before the fix it
held one: the escaped name and a leftover raw one, with the raw one looping as a
provisional upload that is refused every pass.

The underlying defect is clear and independent: **naming records an escape, but
nothing renames a file already on disk to match it.** The record changes and the
disk does not follow — the same shape as the trash work above. What is NOT clear
is whether `path_for` is the right home for the escape, or whether it papers
over that gap and manufactures the duplicate. That is a placement-path design
call and it is unreviewed.

Treat the `path_for` change as provisional. The oracle fix, the workload fix and
the reserved-prefix issue stand on their own.

---

## Still open

- **The `.jd-*` user-file class is still not swept.** The engine's answer is
  defined — refuse the name, park `Unsyncable(ReservedPrefix)` — but on a real
  disk the scanner never sees the file at all, so it simply never syncs and
  nothing says why. Silence is the one failure this client is not allowed, and
  this is a case of it. Not yet added to the hostile table, because the oracle
  skips those names too and would have to learn the difference between the
  engine's litter and a file the user named.
- **Windows-hostile names** — trailing dot, trailing space, reserved device
  names. Another class the table cannot spell.
- **Sharing is not modelled at all.** The mock has one owner. On the platform a
  `missing` stat means gone OR no longer visible, so a revoked share reads to
  the client as a deletion — and the client trashes the local copy. Whether
  that is right is a product question, but nothing in the estate can currently
  ask it.
- The mock's restore still does not model the platform's selective-restore
  cutoff or its re-root-and-rename-on-collision.
