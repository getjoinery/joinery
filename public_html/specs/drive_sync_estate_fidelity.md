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

### The real defect underneath, traced 2026-08-30

`path_for` was not the disease. Two seeds still fail — 123010 and 123212 — and
instrumenting `download()` and naming's `put_entry` on 123212 gives the whole
mechanism:

```
download id=904 remote_name=cafe-9.txt   local_name=None  synced=None
download id=904 remote_name=CON.36.txt   local_name=None  synced=Some(CON.36.txt)
NAMETRACE  id=904 remote=CON.36.txt      local_name None -> Some(%43ON.36.txt)
```

Entry 904 was `cafe-9.txt`; the server renamed it to `CON.36.txt`. Naming runs
at the top of the pass (`pass.rs:184`) and resolved against the **old** name,
which is `AsIs` and yields `None`. The rename was then agreed, so
`synced_placement` said `CON.36.txt` while `local_name` still said nothing. The
download resolved through `effective_local_name()`, got the raw `CON.36.txt`,
and wrote the file there. Naming produced `%43ON.36.txt` only on the **next**
pass, by which time the file was already on disk under the raw name.

**The defect in one line: `effective_local_name()` combines a fresh placement
with a stale name mapping.**

`local_placement()` is deliberately the last *agreed* placement, and its doc
comment spells out why reaching for the remote one is a bug with teeth — the
scanner finds the file where its records say it is not and reads it as a local
move. `local_name` has no equivalent discipline. Combining the two yields a path
that is neither the old one nor the right new one, and the scanner then does
exactly what that doc comment predicts: in both seeds it mints a provisional
upload for the orphaned raw name, which the server refuses every pass.

This explains 123010 too, without contradiction. There the move landed at the
escaped name because `path_for` derives forwards, while a download in the same
window still resolved raw through the stale mapping — hence **two** files where
before there was one. `path_for` shortened the window on the move path and left
it wide open on the download path.

**`local_name: None` is ambiguous**, and that ambiguity is what makes the bug
undetectable from inside the engine: it means both *this name needs no escaping*
and *naming has not looked yet*. Nothing can tell those apart, so nothing can
wait for the second.

Verified by A/B: with the `path_for` escape reverted, 123010 ends with ONE file
at the raw name while the record says escaped — the record and the disk already
disagreed before the change. `reconcile.rs` and `round.rs` never read
`local_name` at all, so a change to it plans nothing and no rename is ever
issued. `local_index` is keyed by inode and caches hashes, so nothing anywhere
records the name a file was materialised under.

### Defer was the wrong fix, and reading the code says why

The first candidate was to record which server name `local_name` was resolved
for and **defer** any op whose mapping is stale. Reviewed and rejected: it
deadlocks on the move path, which is the case the sweep exists for.

`competing_placement` (`naming.rs:169`) resolves an entry that holds a local
file against `local_placement()` — the last *agreed* placement, deliberately,
because until the move applies the file is still competing with its old
siblings. So naming never resolves the DESTINATION name; a move deferred until
that name has a verdict waits for a verdict that only its own execution can
produce.

A second shape was rejected for a symmetric reason: pre-recording the
destination mapping into the single `local_name` the scanner reads. The scan
resolves through `relative_path` → `effective_local_name` (`pass.rs:1813`), so
the mapping would flip while the file still sat at the old name — the tracked
file reads as missing and the file on disk reads as untracked, which is the same
provisional loop reached by a new route.

### Fixed: the move records the name it landed under

The window is closed where it opens. `apply_local_move` computes `dest`, which
is where it actually put the file, and now writes the resulting leaf into
`local_name` in the same `put_entry` that sets `synced_placement` — the
atomicity `synced_placement` already had. Equal to the server's spelling in the
ordinary case, which is recorded as no mapping at all.

`dest` is the one answer that cannot be stale, because the operation just used
it. Nothing derives forwards and nothing waits.

Pinned by `a_rename_into_a_hostile_name_carrying_new_content_lands_once`, which
fails without the change by **never settling** — the world does not go quiet,
which is how both remaining sweep seeds present. The content edit is what makes
it bite: a rename alone is carried correctly by the move path, and it takes a
download landing inside the same window to expose the disagreement.

### The count was wrong: five seeds, not two

The arm was reported as failing on two seeds. It failed on **five** — the
earlier figure covered only the first two of the five sub-arms. `winname-linux-pc`
and `winname-hostile` are now 0 of 400 each behind the fix above; the remaining
three were pre-existing and were confirmed so by A/B (all three fail identically
with the fix disabled).

---

## The escape reaching the server as a real name

Three seeds, one cause, and the worst class found in this work: **`memo-47%20`
and `a%3Ab-37.txt` ended up on the server as genuine file names.**

That is not cosmetic. `memo-47%20` is the escape OF `memo-47 `, so a server
holding both holds two names that are one name on any disk that has to escape.
They collide there permanently, one is parked `UnicodeClash`, and each spelling
goes on to spawn conflict copies of its own — seed 124149 reached **ten files
where two belonged**.

`to_local_name` has no inverse, deliberately: the mapping recorded on an entry
is authoritative precisely because an escape cannot be reliably undone, and a
user is entitled to a file genuinely called `%43ON.txt`. So once the escape is
on the server there is nothing that can tell it from a real name again.

### Defect A — a conflict copy inherits the hostility it copies

`conflict_copy_name` built `CON.12 (conflicted copy … from pc).txt` from
`CON.12.txt`: still a reserved DOS stem, because the reservation is on the stem
before the first dot. `a:b-37.txt` kept its colon.

A conflict copy is written to this disk and then **deliberately left for the
scanner to adopt as a new file** — that is how it reaches the server. The
scanner reads it under the name the DISK holds, so a conflict copy that needed
escaping went up under its escaped spelling.

**Fixed.** The engine chooses these names, so it now chooses ones that never
need escaping: the assembled name is normalized once, against
`Personality::windows()` — strictly the most restrictive of the supported
personalities on name shape, so `AsIs` there is `AsIs` everywhere. Ordinary
names are untouched. Pinned by `a_conflict_copy_never_needs_escaping_anywhere`
and `making_conflict_names_safe_leaves_ordinary_ones_alone`.

Cleared seeds 124427 and 124574.

### Defect B — a reserved slot adopted from under the file that reserved it

The general form, and the one that produced the leak without any conflict copy
involved. Traced by instrumenting the adoption site:

```
ADOPT path="Contested Folder/memo-47%20"
   RIVAL id=901 remote="memo-47 " local_name=Some("memo-47%20")
         synced=None status=PendingDownload
```

Entry 901 had already reserved that escaped name; its bytes had not arrived.
`known_local` (`pass.rs:1476`) deliberately excludes an entry with no
`synced_placement` — there is no local file to have moved away from, and
counting one would read it as deleted — so the scan cannot see the reservation.
Whatever stands at that path is adopted as a brand new file and uploaded under
the escaped name.

`holds_a_local_file` already states the rule the scan was breaking: a
`PendingDownload` entry holds its slot, *because those bytes are on their way to
that path*. Naming honours it; the scan did not. The two disagreed about the
same question.

**Fixed.** The scan collects the paths reserved by entries awaiting a first
download and does not adopt anything standing in one. Nothing is lost: the
arriving download treats an occupant as an occupant and moves it aside as a
conflict copy, which is the designed path and keeps the user's bytes under a
name that says what happened. Pinned by
`a_slot_reserved_for_an_arriving_file_is_not_adopted_from_under_it`, which
without the guard fails with the server holding
`["memo-47 ", "memo-47%20", "memo-47%20 (conflicted copy …)"]`.

Cleared seed 124149.

---

## Still open on this axis

- **Naming cannot see the destination folder's siblings.** `path_for`'s forward
  derivation stays for now, and it is a strictly weaker computation than naming:
  a move whose escaped leaf case-clashes with a file already in the destination
  is resolved wrongly, before and after these fixes. Closing that means naming
  resolving `remote` against the destination folder's sibling set and carrying
  a second, target-side mapping — at which point the forward derivation should
  be removed in the same change, not before.
- **Nothing renames a file already on disk when naming re-maps it.** Reachable
  when `duplicate_losers` re-maps an already-materialized file. Separate from
  everything above, and still unfixed.
- **A user file colliding with an escape is renamed to a conflict copy.** The
  consequence of defect B's fix, and the right trade — `make_room` exists to
  keep a copy rather than destroy one — but it is a conflict name for something
  that was not a conflict.

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
