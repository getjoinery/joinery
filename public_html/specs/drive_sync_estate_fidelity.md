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

There is a second version of the same blindness, found later and worth as much:
**an oracle that reads only the end state will bless a defect whose damage is in
how the state was reached.** Defect I is the proof. A recycled inode made one
document continue as another's next version, and the file tree that resulted was
byte-for-byte identical to the correct one — same paths, same contents. Every
tree assertion passed. The server's version rows were the only witness, and the
first test written for that defect passed with the defect restored because it
asserted on the tree. Where a wrong answer and a right one can produce the same
picture, the picture is not the evidence.

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

## Defect C — the destination evicts the file already standing there

The one the estate could never have found, because it converges. A file whose
name needs escaping on one device is moved into a folder where the escape
collides with a file already there. Naming resolves the folder, the newcomer
wins the slot, and the sitting file — a real file, with a name the user chose
and every other device is perfectly happy with — is renamed to a conflict copy
and that rename is propagated fleet-wide.

Every device then agrees, which is exactly why 38,000 seeds blessed it. Seed
42348 was *green* on HEAD while doing this:

```
kept_aside: café-37.txt was moved aside to
            café-37 (conflicted copy 2026-07-31 from mac).txt
```

A settled wrong answer, not a loop. The estate finds loops.

The rule the resolution was breaking: a device's own inability to hold a name
is not a warrant to rename another device's file. The escape is local; the
eviction was global.

**Fixed, in two halves.**

*Ranking.* `resolution_order` ranked by whether an entry was materialized, which
let an arriving file outrank a settled one. It now ranks by settled placement
first — a file sitting where the server agrees it sits outranks a file still
moving toward that folder. The sitting file keeps its name.

*The loser.* The newcomer that loses the slot cannot simply flip to
`Unsyncable`: that strands its bytes on disk and breaks the unstated invariant
that a parked entry holds no local file. It un-materializes first —
`UnmaterializeAndPark`, a journalled operation that verifies the bytes are on
the server, trashes the local copy to the OS trash, and clears the local half
and parks atomically. A local edit not yet uploaded stops it: the op returns
`Retry` and the ordinary upload runs first. The result is a state the rest of
the engine already understands, which is why no oracle change was needed.

Two things only tests caught:

- A move journalled in an earlier pass runs *ahead* of the park and evicts the
  file the park exists to protect. The decision now cancels queued ops for the
  parking entity, with a guard in `move_local` behind it.
- The first version parked anything whose server name was unusable, including
  the engine's own `.jd-swap-*` scratch names — cancelling the recovery that
  renames those back and stranding them on the server forever. Three scenarios
  failed. Destination judging now acts only on a *collision*
  (`CaseClash`, `UnicodeClash`, `DuplicateName`), never on whether a name is
  intrinsically holdable; the main loop already owns that question.

Pinned by `a_file_arriving_in_a_folder_does_not_evict_the_one_already_there` and
`a_rename_onto_a_siblings_escaped_name_does_not_evict_the_sibling`, both
asserting `assert_converged` — the gap that let a first, non-convergent attempt
pass while asserting only the absence of a spurious rename.

Cleared seed 42348. Estate after the fix: 16 arms, 40,070 seeds, zero failures.

---

## Defect D — the park that changed a status and left the file

Every oracle above passed on a device holding a file nothing owned.

`café-57.txt` and its decomposed twin are two names on the server and one name
on a Mac. The twin that loses the slot has to stop being held there, and the
engine says so by parking its entry `Unsyncable(UnicodeClash)`. In the case the
estate found, that entry was **materialized**: an arriving file had taken the
slot, `make_room` had moved the loser's copy aside under a conflict-copy name,
and naming then parked it by assigning a status and nothing else.

What that left: a file on the user's disk, at a name the engine invented, that
no entry claims. Every later scan pairs it back to the parked entry and reports
the same local move; nothing acts on a parked entry's move, so the report is
made again next pass, and the pass still calls itself quiet because no operation
was planned. The file is never scanned, never uploaded, never renamed and never
removed. An edit to it goes nowhere.

Nothing saw it, and the reason is worth stating: `assert_converged` **excuses
declined content by hash**, and it drops that hash from the disk side as
readily as from the server side. The exemption written to excuse the file being
absent excused it being present. There is a second reason the estate could not
find it either way — the world converges, and a converged wrong answer is not
what a sweep looks for.

Two halves, as with defect C:

- **A new oracle.** `assert_no_disk_file_is_unclaimed`: every file on a disk is
  claimed by some entry. Only a park releases a claim — `PendingKey` and
  `OutOfScope` both answer no to `holds_a_local_file` while legitimately keeping
  the bytes, a keyless device holding local-only files under a vault folder it
  cannot read being the case that proves it. Called from `assert_converged`,
  before the exemption gets a chance to hide anything.
- **The fix.** A materialized entry is not parked by changing a status.
  `apply_naming` hands it to `give_up_local_copy`, and the park OPERATION does
  the work: it checks the server already has the bytes, trashes the local copy,
  clears the record and sets the status, so the disk and the record change
  together or not at all. The operation already existed — destination judging
  used it — and only the in-place verdict was still taking the shortcut.

`holds_a_local_file` had stated the rule the whole time: *no bytes of its are at
that path and none ever were*. It was enforced nowhere.

Pinned by frozen seed 111120 (`mac`/`pc`/`disk`, 70 steps, chaos), which fails
on the old code with the new oracle in and passes with the fix. A hand-written
scenario would not reach it: the settled twin always wins, so the loser is
normally parked before it ever materializes, and only a race that asides the
loser's copy first produces a materialized loser.

---

## Defect E — the guard was refreshed by the thing it guards against

The first defect the fresh seeds found, and the first content loss in this
spec: a download destroyed seven bytes that were on no server and in no trash.

A download may only overwrite a local file if that file is still the one the
download was decided against, and the engine asks that question by comparing a
recorded fingerprint — size, modification time, inode — with the disk. The
answer is only as good as the record.

Applying a rename the server made refreshes that record. It re-stamped the
fingerprint from whatever now stood at the destination and left the recorded
CONTENT untouched, so the two halves of one agreement came to describe two
different files. In the seed the inode had moved as well: the record asserted
*unchanged since we agreed, and what we agreed is X* about a file holding
neither. That runs moments before the guard, and it is the guard's only
reference point — so the rename handed the guard a reference that matched by
construction and the guard could no longer fire at all. The scan does compare
content and was honest throughout; it simply ran at the top of the pass, before
the record was made to lie.

Two fixes, and each stops it alone:

- **A fingerprint is recorded only about bytes that have been read.** The
  destination is hashed and compared with the content agreement; no match, no
  fingerprint. `synced_content` is deliberately left alone — clearing it would
  strip the hash the scan compares against, and a genuine local edit would then
  arrive as a stranger (delete-plus-create, version chain gone) instead of as
  the conflict it is.
- **The commit reads the file before overwriting it.** The last gate before
  bytes are destroyed, and the only one no history can defeat: every cheaper
  discriminator is itself one of the suspects. Neither what arrived nor what was
  agreed means nobody has seen these bytes, and the operation stands down for
  the scan to meet them as a conflict.

Pinned by frozen seed 2024110. A hand-built version was attempted and does not
reproduce it: staging a user's file at the name a rename is about to claim ends
correctly, with `make_room` moving it aside, so an occupied destination is not
the ingredient. What the seed has is an entry whose own local file had been
replaced while the content agreement still described the file before it. That
state has not been staged by hand; the seed stands until it is.

---

## Defect F — the file the user dragged into their private folder, that nothing owned

On a device with no key for the vault, dragging a file into the private folder
left the bytes owned by nobody, for ever. No scan adopted them, no upload sent
them, no rename moved them, no delete removed them, and the next thing to want
that name would have written straight over them. The device reported itself
perfectly quiet the whole time.

The engine's answer to that gesture was already decided and already stated in a
comment: this device cannot do the conversion, so the file stays where the user
put it and the entry waits, visibly, for a key. The wait was written on the
entry for the file's OLD identity — and the name resolver cleared it on the very
next pass, because it asks whether the ENTRY is encrypted and the entry was
still recorded in the plaintext folder the file came from. Set by one part
because of where the file went, cleared by another because of where the record
said it was. Round it went: the scan re-derived the same move by inode every
pass, the move was declined every pass, and nothing ever claimed the file.

Even a wait that survived would not have been enough. `local_placement` is the
agreement or the server's placement, and there is no third place to say "the
local copy is over here now" — the model tolerates that gap only while an
operation is queued to close it, and here no operation ever could be.

So the memory goes on the record that is true. The bytes are inside the vault
now, so they get an entry that says exactly that, at the path they are actually
at, minted already waiting for a key — the same bargain the creation path makes
for a file the user saves into a vault this device cannot open. Beyond that it
carries one fact, `replaces`: the server-side file its first upload supersedes.

Only a plaintext entry can reach that mint, and nothing at the mint says so:
the crossing predicate answers the same for a file going either way, and a
LOCKED vault is also "no key here". What prevents an encrypted file on its way
out from reaching it is ordering in two other functions — the name resolver runs
at the top of the same pass and parks every encrypted entry while there is no
key, so the wait-skip takes it first. A real guarantee, and an invisible one, so
it is asserted at the mint rather than assumed.

That fact is what protects the original. Once the new entry claims the bytes,
the old one has no local file at all, which reads as the user deleting it —
and trashing on the strength of that would remove the last copy anyone else can
reach in favour of a replacement this device cannot upload. So the source is
held until the replacement's create has LANDED, which is stricter than the keyed
path: that one trashes first and uploads after. The hold is recomputed from
`replaces` every pass rather than remembered, so it lapses by itself the moment
the new entry stops being provisional, and it disappears with that entry if the
user drags the file back out.

One thing had to move for the lapse to work. A provisional entry whose file is
gone is now forgotten BEFORE the skips that mean "wait", not after: every one of
those skips is a reason to wait, and waiting needs a file to wait for. A parked
or key-pending entry whose file the user has since taken away used to sit in the
store for its whole life, and anything derived from its existence sat with it —
which would have left a file dragged in and straight back out again never moving
at all.

That ordering was already wrong on its own, and it had a second victim nothing
was looking for. Rename a local-only file inside a vault this device cannot
open: the file has no server identity, so the scan mints a fresh one at the new
path and the old record is left describing a file that is not there. It goes on
claiming a name, it is counted by anything asking what the device holds, and no
oracle could see it — convergence compares the disk against the SERVER, and an
entry the server has never heard of is on neither side of that comparison.

So there is now a mirror invariant. `assert_no_disk_file_is_unclaimed` finds
bytes no record owns; `assert_no_provisional_entry_is_a_ghost` finds records
that own no bytes. It fails without the reordering, naming `Private/memo.txt`.

Pinned by hand, not by seed:
`a_file_dragged_into_a_vault_with_no_key_here_waits_instead_of_trashing_it` now
also asserts that something owns the bytes, and fails without the fix naming
`Private/memo.txt`. Three more cover the edges the design turns on — dragged in
and back out again, the key arriving mid-wait, and the server deleting the
original while the wait is on.

Found by seeds 2078473, 2078482 and 2078533 in `onekey-longhostile`, all three
caught by the unclaimed-file oracle from defect D.

---

## Defect G — the server's placeholder recorded as the file's name

The server never learns what a file inside a vault is called: it stores
`enc-{content id}` for the life of the file and the real name lives sealed in
the metadata blob beside it. `move_remote` asks the server where a file ended up
— which it only does on a RETRY, so a healthy network never reaches it — and
recorded the answer whole, placeholder and all, into the agreement.

`local_placement` prefers the agreement over everything else, so from that
moment the user's file WAS called `enc-...`. A later download landed under that
name, the scan met a file no entry knew, and the engine offered it back to the
server as a brand new file whose real name was another file's placeholder. The
one name the vault exists to keep secret, stored in the clear.

The rule was already written down at the other place that adopts a server view
— the upload path says in as many words that the server's language has no name
in it for an encrypted file — and enforced nowhere else. The blob rides along
with the stat, so the fix opens it exactly as the change feed does, and the
agreement records the name the user gave.

One thing the fix cannot do is open a locked vault. A queued operation is
retried by the executor whether or not the vault is open — the skip that holds
an encrypted entry back gates PLANNING, not the queue — so the recovery can
arrive at a moment when there is no key to do it with. Adopting anyway would put
the placeholder into the agreement exactly as before, and nothing ever re-stats
an entry that reads as settled, so it would never repair itself. So it waits
instead, which is what every other wait here does. Scoped to files: a folder
inside a vault wears its real name on the server and has no blob to open, so
asking it to produce one would be a wait with nothing to wait for.

Pinned by frozen seed 1073449, and frozen rather than hand-built for a stated
reason: three scenarios were written and all three passed with the fix in and
out, because none could get `move_remote` to run with an attempt already behind
it. Losing the answer to the rename lets the next change poll absorb it and the
op never re-runs; refusing it before the server sees it leaves no retry either;
and a blanket server error fails the change poll at the top of the pass, so the
rename is never attempted and the attempt counter never moves. The aimed fault
this needs — one operation retried while everything around it succeeds — is a
state a long hostile run reaches by accident and a scenario cannot yet ask for.

---

## Defect H — the park that waited for work it was preventing

A regression from defect D, found by the first estate run against the fix.

A park gives up a local copy, and refuses while that copy holds bytes the server
has not got — correctly, because trashing it would be exactly the loss the
operation exists to avoid. The comment says what happens next: the op retries,
the ordinary upload runs first, and the park happens on a later pass once the
work is safe.

The upload never runs. A retry keeps the operation in the journal; an entity
with an open operation is skipped by the round; and the round is what plans
uploads. So the park waited for work its own presence prevented anyone from
doing — two thousand attempts, the device never once quiet, and nothing said to
the user. The refusal was right and the way it refused made it permanent.

This was unreachable before defect D. A park only met a file on the disk once
materialized entries started going through the park operation instead of a
status flip; before that there was never a copy standing there to refuse over.
Two of the three faults in a 40,070-seed estate were this, and one of them is a
CLEAN world — no chaos, no kills, two ordinary devices — so it is not a
fault-injection artifact.

The fix is one outcome, not one condition: stand down instead of retrying. The
premise no longer holds, nothing here is anybody's problem, and the next round
decides afresh from what is actually there — which is what `Overtaken` is for.
Standing down is not giving up: the clash that provoked the park is still there,
so naming derives it again a pass later, by which time the entity is free, the
upload has run, and the copy on the disk is one the server holds. That is the
order the operation always meant to run in; it just could not get there from
inside its own retry.

Pinned by frozen seed 3072116. Hand-building it runs into the same wall as
defect D — a settled twin wins the name before the loser ever materializes, so
the ingredient that makes this reachable cannot be staged directly.

---

## Defect I — the inode that was allowed to say who a file was

A real disk hands a deleted file's inode straight to whatever asks for one next.
The scan had a rule that read that as the tracked file having moved and been
edited, on the grounds that the inode was all there was to go on. Its own
comment said so.

It was wrong in both directions at once. Applied, it renamed the entry onto the
stranger's name and sent the stranger's bytes up as the next version of the
entry's server content — one document's history continuing with another
document's contents, which is the very version chain the rule existed to
preserve. Not applied, its claim on the observed file stopped that file being
adopted by anyone, and nothing owned those bytes again. The estate found the
second half: a conflict copy on one disk that no entry claimed.

The engine had already decided this question one rule earlier. Pairing by
content refuses a bare inode and says why: an inode alone can be recycled by an
unrelated file, and pairing on that would silently swap two files' identities.
Two rules disagreeing about what an inode is worth is worse than either answer,
so the doctrine now sits where the rule was. **A bare inode may fund order — a
hold, a wait, a hint that costs only time when it is wrong — but never identity:
a name, a file's contents, a claim on somebody's data.**

The price is chosen, not overlooked: a file both moved and edited between two
scans has neither its path nor its content left to recognise it by, so it reads
as a delete plus a creation. The version chain is lost and no bytes are.
Corruption against degradation is not a close call. The unit test that used to
bless the old behaviour keeps its name and its comment and now asserts the safe
outcome; the other one is renamed for what it records — a move the scan cannot
confirm reads as a delete plus a create.

One consequence had to be paid for separately. A file MOVED into a vault this
device cannot open still arrives as a move, so defect F's claimant and its hold
are untouched. A file moved AND edited now arrives as a creation instead — owned,
but with no memory of where it came from, so the source read as deleted and the
server's plaintext copy was trashed during a wait for a key that may never end.
That is a durability regression hiding inside a correctness fix, and no oracle
asserts the server copy survives the wait.

So the provenance is recovered at adoption, and the inode is what recovers it —
which is the doctrine applied, not an exception to it. It is not being asked who
the file is; the file has its own identity either way. It is asked which server
copy to hold on to a little longer, where a wrong answer delays one delete until
a key arrives and a right one keeps the copy everybody else can still reach.
Order, not identity.

The engine's other reader of inodes was audited against the doctrine and left
alone. The folder-displacement detector asks where a folder went, which is an
order question, and it never asks on an inode alone: a child corroborates only
when its inode AND its name land where the folder is supposed to have moved,
and an inode found anywhere else vetoes the wholesale reading rather than
supporting it. A recycled inode there produces a refusal to recognise a move —
the folder is handled as a delete plus a creation — which is the same
degradation-shaped wrong answer chosen above, not a claim on anyone's data.

The hint costs one field and no machinery. Because the adopted entry is
provisional, the hold on the source, the convergence excuse for the divergence
it creates, and the lapse when the file goes all apply to it by construction —
it inherits the whole `replaces` contract from defect F without a line of its
own.

Pinned by two scenarios, both verified to fail without their fix: a stranger
inheriting an inode is adopted rather than mistaken, asserted against the server
version rows so the poisoned history is caught and not just the tree; and a file
dragged into a vault and edited still holds the server's copy, which fails with
the plaintext original already trashed.

---

## A redundancy removed, and no hole closed

Worth writing down because it was nearly recorded as a defect, and it is not
one.

The read-before-overwrite gate at the download commit asked for a recorded
fingerprint before it would read the file. That looked like a hole: an upload
that finishes against a file the user has already saved over records the
agreement with no fingerprint ON PURPOSE, so the next scan re-hashes and sends
the newer save — the record at its least trustworthy, and exactly where the gate
was skipping itself.

It was not a hole. Underneath the gate, `spool.commit` is handed the same
absent expectation, and both filesystems refuse a `None` expectation over any
standing file, because a file the engine has never seen may be the only copy of
it. Every content case lands in the same place either way: the refusal arm
adopts, refreshes or stands down exactly as the gate does, one call later.
That equivalence is also why no scenario can be written that fails without the
change — there is no behavioural difference to witness, which is a different
thing from a difference that is hard to stage.

The clause is gone anyway, for what the code SAYS. The gate's own comment calls
it the one gate that cannot be defeated by history; with the clause in place
that sentence was true only by borrowing from a guard two crates away. A gate
should state its own totality. No behaviour changed, and the state that provoked
the question is now named at the gate so the next reader finds the answer
instead of the doubt.

The general lesson, since this round has been collecting them: **a gate's own
comment is not the whole truth about a gate.** Reading it and concluding that a
skip is a hole stops one layer above the code that decides. It is the same
mistake as reading a loss from the shape of its aftermath — in both cases the
answer was one layer further down.

---

## Still open on this axis

- **`path_for`'s forward derivation is still weaker than naming.** Destination
  judging now catches the *collision* case — a move whose escaped leaf clashes
  with a file already in the destination parks the mover instead of evicting the
  sitter — but it decides only who yields, not what the mover should have been
  called. Closing that means naming resolving `remote` against the destination
  folder's sibling set and carrying a second, target-side mapping, at which
  point the forward derivation should be removed in the same change, not
  before.
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
- **Windows-hostile names** are now a sweep arm of their own
  (`scratch_windows_hostile_name_sweep`, 1,800 seeds across five sub-arms) and
  are green. The generator still cannot spell them into the shared hostile
  table, so they live in that arm rather than throughout the estate.
- **A sealed blob that opens but carries no name still adopts the
  placeholder.** `open_metadata` reports that it opened the blob, not that it
  recovered a name, so a blob with an empty name passes the check that waits for
  a locked vault. Engine-written blobs always carry a name and the change-feed
  path has the same property, so this is a shape shared with `absorb_remote`
  rather than anything defect G introduced — and if it is ever fixed it belongs
  in `open_metadata`, for both callers at once. Worth saying why it was not
  simply folded into the wait: waiting on a blob that will never have a name is
  an unbounded wedge, which is a worse failure than a bounded wrong name.
- **Two soak-rig losses that no mechanism here explains.** Runs 341 and 463,
  both under the same client build, both a persona rewriting a hot file in
  place. In each the scan read the user's bytes within seconds and they never
  became the agreement, and in each the overwrite landed a minute or more later
  — 64 seconds and 125 seconds — so it is not the instruction-width window
  below, which is microseconds wide. The guard present in that build compares
  size and mtime, the standing file mismatched on both, and the refusal arm
  should have hashed it, found bytes that were neither what arrived nor what was
  agreed, and stood down. In run 341 a rescue did fire — but its conflict copy
  holds 623,009 bytes and was raised twelve minutes before the lost write, so it
  is an earlier intermediate and innocent.

  The inode history in run 463 says what happened at the landing. Every cached
  row at that inode, in order: the file the user's own device wrote there
  earlier; the user's in-place rewrite, 1,105,548 bytes, read by the scan one
  second after the save; and then, half a second AFTER the download landed, the
  same inode carrying a sibling file's downloaded content. An inode only comes
  back after an unlink, and the unlink is the spool rename landing on the path.
  The user's file was standing there and the landing replaced it.

  Which means the guard was handed something that let it through. The entry had
  a fingerprint — `make_room` runs only when there is none, so its silence in
  the issues table says the fingerprint existed, not that the path was empty —
  and `commit` compares that fingerprint against the standing file, which
  differed in size by nearly three hundred kilobytes. That must refuse. It did
  not.

  Five mechanisms were proposed and all five are dead, each killed by a query
  rather than an opinion: the code the download-guard fixes address (no move
  operation exists on either entity); a fingerprint coincidence (the sizes
  differ by hundreds of kilobytes); the check-then-rename window (the landings
  were 64 and 125 seconds after the saves, not microseconds); a hallucinated
  pairing carrying the bytes off under a sibling's identity (the sibling's
  content is the wrong size and its fingerprint postdates the landing); and a
  mid-window upload that would have made the guard match honestly (it would have
  put the bytes on the server, where the oracle looked and did not find them).

  What survives is not a mechanism but a contradiction, and it points at one
  place. Every precondition was verified: the comparator is strict on size,
  inode and mtime; the expectation passed is the agreement itself; the guard was
  present in that build. Those facts cannot coexist with a landing that
  succeeded over a standing file — unless the guard did not run. And it could
  not: the commit read its stat with `if let Ok(..)`, so a stat that failed for
  any reason other than absence skipped the check and let the rename proceed.
  The last gate before the one irreversible act in this engine, silently absent
  for exactly the commit that could not be checked.

  That is a **candidate** for these losses and nothing more — the sixth
  hypothesis after five corpses, and it is unproven. But the fail-open is true
  regardless of whether it is the mechanism, so it is fixed: both branches now
  treat an unanswerable stat as a refusal. Absence is an answer; so is a path
  component that turns out to be a file, which has its own handling that names
  the blocker. Everything else means the question was not answered, and an
  unanswerable question at a gate is a no.

  No simulator can reach this. Its map is in memory, so its stat cannot fail —
  which is why thousands of seeds ran past it. Nor can a test on a real disk:
  every stat failure that can be provoked from outside — an unsearchable parent,
  a symlink loop, an over-long name — fails the rename that follows too, so the
  file survives whether the guard ran or not. The state where the fix does
  something is reachable only by injection, so `jd-vfs` now has a test-only seam
  that makes the guard's next look fail once, and two tests that use it: with an
  agreement and without. Both fail with the fix reverted, naming the commit that
  went ahead over a file it had not been able to check. The one fact
  that would settle the wild losses — what the entry's fingerprint held at the
  landing — is not in these bundles, because the database in the evidence is the
  state after it. Named and open, with two independent instances.
- **The commit's check-then-rename window cannot exist in the simulator.**
  `jd-vfs`'s real spool refuses to land on an occupied path, but says in its own
  comment that this is still a check followed by a rename rather than one atomic
  step, and that `renameat2(RENAME_NOREPLACE)` would close the remaining
  instruction-width window. The simulator's map is behind a lock, so a save can
  never land between its check and its rename — which means every argument that
  leans on that refusal is true by construction in the estate and true on real
  hardware only while the window stays shut. A persona rewriting a hot file in
  place under churn is the load that would open it. This is the one candidate
  that would explain a rig losing bytes the estate cannot lose; it needs a
  VFS-level test that interleaves a real writer with a real commit, which does
  not exist yet.
- **The commit guard still skips a target it does not understand.** The stat
  fail-open is fixed, but inside the answered branch the comparison runs only
  `if md.is_file()`: anything else standing at the destination — a symlink, most
  plainly — is renamed over without a word. The harm is bounded (the link dies,
  its referent does not) and the fix is not a one-liner, because refusing at the
  gate only moves the question: the refusal reaches the caller's arm, whose
  fingerprint call answers None for a non-file, which reads today as "the file
  changed here" and drops the operation — replanned next pass, refused next
  pass, without end. So the real question is who moves a standing non-file aside
  and how the loop terminates, which is a design item rather than a guard patch.
- **An oracle went partially blind under the conditions that produce losses.**
  Soak run 458 reported that it could not read three file histories and so could
  not say whether anything was lost — honest, and correct behaviour for an
  oracle that must not guess. Why the histories were unreadable is unexplained,
  and an oracle that loses its sight exactly when the interesting thing happens
  is a finding about the oracle.
- **Sharing is not modelled at all.** The mock has one owner. On the platform a
  `missing` stat means gone OR no longer visible, so a revoked share reads to
  the client as a deletion — and the client trashes the local copy. Whether
  that is right is a product question, but nothing in the estate can currently
  ask it.
- The mock's restore still does not model the platform's selective-restore
  cutoff or its re-root-and-rename-on-collision.
