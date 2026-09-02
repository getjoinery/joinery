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

## Defect J — the park that fought another file for its name

The bill for defect D, found by the first estate run against the fix that
followed it.

A park's path is derived from an agreement the naming pass has just overruled,
so it can name a spot another entry now holds. On Windows the escaped spelling
of a reserved stem — `CON.28.txt` becoming `%43ON.28.txt` — lands on the same
string as a different file whose real name is literally that, and the loser's
path resolves to the winner's file. What is standing there is not the loser's
copy at all, and it has nothing of its own to give up.

The park read it as "this copy has work the server does not have yet" and stood
down, correctly refusing to trash bytes it could not account for. Then naming
derived the same park on the next pass, and the next: a device that never went
quiet, with an empty queue, no error and nothing raised. Before defect D routed
materialized parks through the park operation this seed settled; it is that
change's consequence rather than an old fault the estate happened to reach.
Defect H's stand-down did not cause it and did not cure it — it changed the
failure from a wedge into a livelock, which is why the seed failed either side
of that fix.

The question that separates the two cases is not about the bytes alone. A
stranger's file and this entry's own unsent edit both differ from what was
agreed, and no hash tells them apart by itself.

It takes two conditions, and the first is not enough on its own. Another live
entry must say it lives at this slot — **and** the file actually standing there
must hold that entry's agreed content. A claimant alone proves nothing: the
naming pass ranks by records rather than by disk, so an entry whose own file has
already left can win a name while the loser's edited copy is still lying at it,
and disowning on the claim alone would throw away work nobody has sent. That is
the one thing this operation exists to refuse.

By content, and deliberately not by fingerprint, which is the same doctrine as
defect I one layer along. A fingerprint match is anchored on the inode —
`unchanged_from` requires equal file ids — so deciding whose file this is from
one would fund an identity claim with a recycled inode. It would also buy
nothing: a claimant's genuinely unedited file matches by content too, so the
fingerprint test has no true positive of its own and only a false one. That
false one is the worst state here: a recycled inode, a matching size and a
write in a tick the clock has not moved would read this entry's unsent edit as
the claimant's file — disowning the edit AND leaving the claimant's record
fingerprint-matching bytes that are not its agreed content, which is exactly
what frozen seed 2024110 pins. A content match cannot produce that: bytes equal
to the claimant's last agreement are bytes the server already holds, so nothing
this branch gives up can be lost.

The slot comparison is folded per path component rather than compared as
strings, because the clashes that produce a park are decided folded: a
case-insensitive volume and a normalizing one each hand one slot to two
spellings, and the naming pass groups them by exactly that key. Comparing raw
would answer "different slot" for the very collisions that put a stranger's file
in front of a park — this seed is only visible at all because its two spellings
happen to be byte-identical.

When both conditions hold, the entry parks by record and leaves the file where
it is; nothing is stranded, because the file has an owner already. Otherwise the
stand-down stands and the upload goes first.

Two residuals, both stated rather than fixed. A file whose bytes are exactly
equal to a claimant's agreed content — two empty files, most likely — is
disowned even when it was this entry's own unsent edit: no byte is lost, since
those bytes are on the server, but the edit's intent is, and the case is
genuinely undecidable from local state. Standing down instead would livelock in
the far commoner stranger case, so this is the right side to err on. And a
claimant that has never synced, or whose own file has been edited since its
agreement, matches neither condition, so the park stands down until that
entry's own work updates its record — bounded by somebody else's ordinary
progress rather than by this operation's existence, which is what separates it
from the deadlock defect H was about.

Pinned by frozen seed 4123847, which never settles without the fix.

The content-only choice is argued in the code and **not yet pinned**, which
leaves it in the shape this project has already paid for once: a property
stated in a comment and enforced nowhere. Nothing has to be hunted to fix that —
the false positive can be placed rather than found. Stage the clash with the
loser materialized and edited, turn on `reuse_file_ids`, write twice inside one
tick the clock has not moved so the standing file wears the claimant's old id,
size and mtime, run one pass, and assert the loser stood down and still owns its
edit. Green with the content test, red the day somebody re-adds the fingerprint
one as an optimisation. It is left open because the state cannot currently be placed, and the reason is
structural rather than a matter of effort. `resolution_order` ranks a settled,
materialized entry 0 and everything else below it, so a materialized entry never
loses a name to an unmaterialized one — which means a materialized LOSER needs
two rank-0 entries at one slot. Two entries cannot both be settled at one slot
by construction, so the collision only appears when a server-side rename folds
an already-settled name onto another already-settled one, and the loser's
derived path only becomes the WINNER's file if the winner materialized over the
slot while the loser's agreement still pointed at it. That ordering is what seed
4123847 supplies and what three hand-built attempts could not: the missing piece
is a way to stage a rank-0 collision, not a scenario nobody has bothered to
write.

---

## Defect K — the respelling the server could not grant

Two estate seeds from the v9 run (shift 5,000,000): 5096132 and 5121445. The
handover described the second as a separate oracle defect — a parked twin that
never synced, with no content to excuse its server path. Instrumenting the
oracle at the failure showed otherwise. The parked twin (entity 912) *was*
excused, by its server content hash, which is what `declined` has always
matched on. The path the oracle complained about belonged to the held twin
(entity 944): synced, agreed at the decomposed spelling, sitting on the disk
under the composed one. Both seeds are one class.

### The mechanism

Two spellings of one word are two files on the server and one slot on a volume
that folds them, and both are legal: a device that composes on the way out
uploads the composed twin of a name minted decomposed. A Mac then agrees on one
spelling while its disk holds the other. The scanner pairs by exact path, so
the file is not where the record says; it is found by content and reported as
a rename onto the twin's byte-name. Pushed, the server answers `name_taken` —
the name is a different live file's — the op is dropped, the record is
untouched, and the next pass derives the same move. For ever — and in one of the two seeds an issue to the user on
every pass.

### The fix, and the half of it that was missing

There is nothing to send: the file is where the agreement says, only its
spelling differs, and this filesystem cannot tell the two apart. So the pass
writes the spelling down as `local_name` — the field for exactly this, already
how a decomposing volume's mapping is described — and plans nothing. The
placement stays the server's.

That was the shape handed over, and it passed the seed. It was not a fix. The
naming pass runs first every pass and recomputes `local_name` for every
materialized entry from the server's spelling; on APFS that answer is "no
mapping", so it erased the record, the scan rediscovered the rename, and the
respell wrote it again. A probe in the respell branch fired **16 times for one
entry** across the seed, every time finding `local_name` back at `None`. Two
store writes per pass for good — a livelock nobody sees, and the seed passed
because the oracle reads only the end state. Naming now keeps a recorded spelling the volume
cannot tell from the resolved one; the probe fires once.

### One rule, three cases

The block that already said "a move that lands where the agreement is, is not a
move" compared the destination against the server's spelling byte for byte.
For a name held under a mapping that is never equal — and that was a third
defect, pre-existing and independent of the seeds: **renaming a folder on a
decomposing volume pushed a rename of every accented file inside it** to the
composed spelling, the server granted it, and every other device applied it.
`renaming_a_folder_on_a_decomposing_volume_does_not_respell_the_files_inside`
fails on the previous code with the server holding `Moved/café.txt` for a file
the user typed decomposed.

`same_slot_spelling` now judges the destination against the slot. The parent
must be the same; then, in order:

1. byte-equal to the name the record already describes
   (`effective_local_name`, so an escaped or decomposed mapping counts) — a
   displaced folder, nothing to change. The Windows sibling of the folder test
   pins the escape half: on the previous code `Sub/a:b.txt` renamed with its
   folder reached the server as `Moved/a%3Ab.txt`, the escape becoming the
   file's real name everywhere;
2. byte-equal to the server's own spelling — a mapping gone stale, cleared,
   because a record that still names the old spelling pairs the file by
   content alone and the next edit at that path reads as a deletion;
3. only then the fold test: a name this volume cannot tell from the agreed one
   whose byte-name belongs to a live entry with no open op that is not
   materialized here — written down as the local name. If the holder *is* on
   this disk the two are fighting over one slot, which is a naming clash for
   naming to park.

A rename the server *can* grant carries the same obligation from the other
side: once `move_remote` succeeds with a new name, the disk wears that name byte
for byte and any recorded spelling is cleared with it. The reviewer found the
gap — a case respell under a recorded normalization spelling was granted and
left the old spelling on the record; rule 2 heals that on the next scan, but an
edit made before that scan pairs by neither path nor content and reads as a
deletion plus a stranger. And when the rival a parked file clashes with is
renamed, naming re-raises the verdict with the new name in it; the older
complaint is now false and is withdrawn, the same way a complaint about an
entry no longer parked already was.

That withdrawal compares the complaint's wording with the reason on the record,
which is only sound if every `unsyncable` complaint is worded one way. The park
operations were not: they raised prose ("was moved to the trash…") under the
same kind, and a materialized park — which naming hands to the operation and
never re-raises — would have had its only complaint withdrawn on the next pass,
leaving the copy gone and the panel silent. The reviewer caught it. The park
operations now raise the state complaint in the same `{reason:?}` shape as
naming, and what they *did* — a copy moved to the trash — is an event under its
own kind, `parked`, which no pass withdraws.
`a_park_that_gave_up_a_copy_keeps_its_complaint_open` pins it: red with the
prose complaint, green with the split.

Anything else is a rename the server can grant, and it goes up: `report.txt` to
`Report.txt` on a case-folding volume is a rename the user meant.

### The oracle

Convergence expects each server path at the spelling the device was right to
keep, read from `local_name` and honoured only where it folds equal to the
server name — a local name that means anything else is still a divergence.
Keyed by the entry's whole server path, not its bare name: the name-keyed
version lent one file's spelling to every same-named file in every folder, and
failed 5121445 at a later checkpoint on a device that was right. And two server
files may not be expected at one disk path. The parked one is excused by
content, so a collision is either an entry the engine failed to park or an
expectation this oracle built wrongly; the later key used to win silently, and
now it panics naming both server paths.

### Pinned

- `a_spelling_the_disk_holds_is_kept_when_the_volume_cannot_tell_it_apart` —
  red without the naming keep.
- `a_twin_spelling_the_disk_already_holds_is_written_down_not_pushed` — the
  respell, an edit under it, a granted case rename with an edit inside the one
  pass before the record heals, the way back, an edit after it; red without the
  respell branch ("never settles"), red without the way-back clearing (the
  mapping survives), red without the `move_remote` clearing (the old spelling
  is still on the record after the grant).
- `renaming_a_folder_on_a_decomposing_volume_does_not_respell_the_files_inside`
  and `renaming_a_folder_on_windows_does_not_push_the_escaped_name_to_the_server`
  — both red on the previous code.
- Seeds 5096132 and 5121445 pass. The name-keyed oracle was also run against
  5121445 and failed a later checkpoint on a device that was right; the
  path-keyed one passes with the held twin's path rewritten and the parked
  twin's excused.

---

## Defect L — the keyless device that minted a vault folder per pass

Estate v10 (shift 6,000,000), seed 6092348, the kill-vault-platform hunt arm:
"never settled" with an empty queue. Pre-existing — it reproduces on a clean
build of HEAD.

A device without a vault key finds a directory on its own disk inside the
vault (the user made a folder of the vault's name and a folder inside it) and
adopts it. A **file** in that position is held `PendingKey` at adoption and
never pushed; a **folder** was pushed. Created on the server, it was parked
`PendingKey` by naming on the next pass, and from then on its record and the
directory drift apart, because nothing keyless ever applies a rename to a
parked entry. Rename the directory to a name the server already holds and it
is adopted again as a brand-new folder every pass: the create is refused over
the name its own twin holds, the executor steps aside with a conflict name, and
the server gains one more folder per pass for as long as it is up. The settle
trace showed a fresh provisional id every round and the server collecting
`Sub 38 renamed (conflicted copy …) 2`, `… 3`, and so on.

A folder made inside a vault this device cannot open now waits for a key at
adoption, exactly as a file made there does, and goes up when the key arrives.
`a_folder_made_in_a_vault_with_no_key_here_waits_and_mints_nothing` is red on
the previous code at the first assertion (the folder reached the server);
`a_folder_waiting_for_a_key_goes_up_when_the_key_arrives` pins the release.
The seed's exact loop needs the device's own pushed folder renamed on the
server, which the new rule makes impossible, so the seed itself is the pin for
the loop.

The drift this left open -- a held folder whose directory is renamed, or whose
vault folder is renamed on both sides, before the key arrives -- was staged
and does not reproduce: `a_folder_waiting_for_a_key_follows_a_vault_folder_renamed_meanwhile`
and `a_folder_waiting_for_a_key_renamed_while_it_waits_goes_up_under_its_new_name`
both pass and now pin it. Staging the first of them found Defect Q instead.

---

## Defect M — the original held hostage by a vault it had already left

Estate v10, seed 6091570, the one-key-holder hunt arm: the guest holding a file
no entry claims, `contested (conflicted copy … from guest).txt` at the root.
Pre-existing; reproduces on HEAD.

Traced by watching the path on every pass. The file's bytes belonged to entry
903, which the keyless guest had once dragged into the vault. That minted a
claimant waiting for a key, with `replaces = 903`, and put 903 on hold: its
path was empty, which reads as a deletion, and acting on that would trash the
last copy anyone could reach. Then the user brought the bytes back out under a
new name and saved something else at the vault path. The claimant still had a
file at its path -- a different one, which it read as an edit of the file it
was waiting to upload -- so it was never swept, and the hold never lapsed. Every
pass the scan paired 903 with its file at the root, by inode and by content,
and every pass the pairing was thrown away at the hold. Nothing scanned, sent,
moved or removed it.

The hold rests on the source path being empty. When the scan finds the
entry's own file on the disk anyway, the premise is gone: the bytes waiting
inside are a new file, not this one's replacement. The hold now lapses in that
case -- the claimant's `replaces` is cleared and the entry goes on as the file
it is. `a_file_brought_back_out_of_a_vault_under_a_new_name_is_not_held_hostage`
is red on the previous code with the guest holding a file no entry claims.

The review of this fix raised two more, both then traced through the sim and
both real: Defects N and O.

---

## Defect N — the released claimant that took the original back

Found by the review of Defect M, written as a scenario, red on the first run.

A claimant released by Defect M stands at the vault path with nothing to
replace. It has never uploaded, so it has no content of its own: whatever
stands at its path is its file, and the scan reads any change of bytes there
as an edit. When the user later drags the released original back INTO the
vault over that path, the scan's same-path step gives the file to the stale
claimant, the original pairs with nothing, reads deleted, and is trashed on
the server while the only bytes wait under an entry that cannot upload. Trash,
not loss, and the keyed path's own order -- but the one thing the stricter
keyless hold exists to prevent.

The hold now follows the bytes. The adoption path already answers "which
server copy do these bytes stand in for" by the inode -- a plaintext entry that
agreed on this file id and whose own path is now empty -- and that rule is
`plaintext_source_of`, asked once more of every claimant already standing
whose file the scan reports edited. The claimant takes over holding whichever
copy the inode says it now stands in for, and if that is a different copy
than before, the previous one is released by the same act: the user
overwrote its bytes, and its server copy goes to the trash exactly as a
plaintext overwrite's would.

`a_released_file_dragged_back_into_the_vault_is_held_again` is red on the
previous code with the original trashed on the server; with a key handed over
afterwards, the upload lands and the replaced original goes.

---

## Defect O — the folder that crossed into a vault with no key here

Found by the same review, and the same shape: written as a scenario, red on
the first run.

`crossing_a_vault_edge` answers Convert for a folder dragged into the vault,
and the keyless mint made a FILE claimant for it. A file claimant for a folder
source has nothing at its path, is swept on the next pass, and is minted again
on the one after -- the folder never held, never sent, never told about, and
the directory it stands in claimed by nothing. The device reported itself
quiet throughout.

The claimant is now the same kind of thing as its source. A folder claimant
stands at the directory, `folder_paths` knows it, and the files inside then
cross the edge one by one as moves under it, each minting its own claimant by
the existing file rule. When a key arrives the folder goes up first, then its
files, and the holds lapse in the order the uploads land.

The hold's lapse had to learn about folders too. Defect M releases a source
when the scan finds its own file; a folder is absent from the file scan, so
that test said nothing, and a folder brought back OUT of the vault under a new
name while a fresh folder of the old name was made inside stayed held for
ever -- a move thrown away every pass. The folder scan answers the same
question, standing at its path or found under another by its files, and the
release now asks it.

The convergence oracle excused a held FILE by its content and had no way to
excuse a held folder. It now excuses a folder awaiting replacement by its
exact server path, on the server's side only: the directory itself stands
under the vault, where a keyless device is not asked to account for it at
all. The files inside are each held by a claimant of their own and excused by
content already; a child the user deleted that the engine failed to trash has
no such excuse and goes on failing, which is why the excuse is not a prefix.

`a_folder_dragged_into_a_vault_on_a_keyless_device_waits_for_a_key` and
`a_folder_brought_back_out_of_a_vault_under_a_new_name_is_not_held_hostage`
are each red with their own fix removed.

---

## Defect P — the park that nobody recognised as their own

Estate v12 (shift 8,000,000, the first estate on the Defect N/O tree), seed
8060024 in the long-hostile two-device arm: converged with the server holding
`Sub 1 (75) (75b)/Sub 19/.jd-swap-laptop-914`. Reproduced on a clean build of
HEAD, and the mechanism is as old as the planner's cycle breaker; nothing in
Defects K to O touches it. Rare in the workload because a rename cycle is
rare in it: this one was a cross-folder swap of two files that only became a
cycle because one of the two names was spelled in decomposed form.

**What the user loses.** A file left on the server under the engine's own
scratch name, for ever. Every other device sees a dotfile it is told not to
sync; the device that made it sits parked and quiet.

**The mechanism, in two halves.**

The planner breaks a rename cycle by parking one mover under
`.jd-swap-{token}`, and the mover's own move finishes the dance from there.
The park was journaled as an operation of its own, named by a token the
planner minted, and `move_remote` recognised a park as its own only when the
scratch name carried *its* idempotency key -- the rule written for the park it
takes itself, inside one operation, when both intermediate orders are refused.
The planner's park never satisfied it. So the finisher found `remote` under a
name that was not where it had been planned from, read that as somebody else
having moved the file, and dropped itself as overtaken. No pass boundary was
needed; the two ops ran back to back and the second stood down.

That alone would have been repaired: the recovery in `pass` for a park nobody
comes back for puts the file back where both sides last agreed. But the park
runs through `move_remote`, whose success path records the destination as the
agreement -- so after a park the agreement *was* the scratch name. The recovery
found nothing real to put the file back under and could only say so; the
naming pass then judged the agreed name, found the reserved prefix, gave up the
local copy, and parked the entry `Unsyncable(ReservedPrefix)`. Sixty-nine
passes of silence in the seed.

**The fixes.**

- The journal names a cycle-breaking park after the key of the move that
  finishes it. That is the only name the finisher can recognise, and it is the
  same name `move_remote` would mint for its own in-flight park, so the two
  parks are one rule. The planner no longer mints scratch names at all:
  `Plan::broken_cycles` is a list of entities and `token_for` is gone from
  `plan`, `run_round` and `run_pass` -- a token the journal would then ignore
  had no reason to exist.
- A `park_remote` records where the server has the file and nothing else. The
  agreement survives the park, which is what the abandoned-park recovery reads.
  A local park is deliberately unchanged: its finisher looks for the parked
  file by the agreement, and a kill cannot land between two local operations
  in the simulator, so that window is open and named below rather than half
  closed here.
- `move_remote`'s in-flight park stands down when the entry already wears this
  op's scratch name.

`a_cycle_park_is_finished_by_the_move_that_planned_it` (a case-folding swap
across two folders on one Mac, the only device -- a second device would see
the abandoned park and put it back itself) fails with the journal naming the
park off the finisher's key, at the same assertion the estate seed failed.
`a_park_op_does_not_overwrite_the_agreed_placement` runs one pass over a park
journaled on its own and fails with the park recording the agreement.

---

## Defect Q — the vault that was renamed out of existence

Found while staging the Defect L open note, not by the estate: the workload
never renames a vault's root folder, and had it done so no oracle would have
minded, because what follows is not a loss. It is worse than one.

**What the user loses.** Their vault. Rename an empty vault folder on the
device that holds the key and the server trashes the vault and gains a plain
folder under the new name. The user sees the folder they renamed, where they
put it, called what they called it. Every file they save into it from then on
goes up in the clear, on every device, and nothing anywhere says so.

**The mechanism, in two halves, on the holder alone.**

A folder is found on the disk by what is inside it: a directory nothing tracks
that holds files the engine knows by their identity on the volume is the
folder those files were in. The scanner says in its own words that an empty
folder cannot be matched, that it reads as one removed and one created, and
that "nothing is lost by that -- an empty folder holds nothing". For a vault
folder the last part is false. Its emptiness is not nothing: the encryption
is the thing. So the rename of an empty vault read as trash the vault, create
a plain folder.

Fixing that alone did not rename the vault; it merely stopped trashing it. The
folder scan's answer -- the user renamed the vault -- was then thrown away by
the vault-edge check, which judges a move by whether the destination parent's
protection matches the entry's own. A vault root is an encrypted folder in a
plain parent; that is what a vault root IS. So every rename of one read as a
move out of the vault, was refused as out of reach, and raised an issue
telling the user to change the folder's protection level first. The server
refuses only a reparent across the edge; it takes a rename.

**The fixes.**

- `detect_folder_moves` credits every folder above a file with it, at the
  file's path relative to that folder. A rename keeps the shape inside, so
  `Sub/f.txt` under the new name is the same evidence `f.txt` would be. Until
  this the matcher knew only a folder's direct files, so the commonest shape
  of a folder -- subfolders and nothing loose -- could not be matched at all:
  the subfolders paired, their parent did not, and the parent was trashed.
  The review of the first version of this fix traced that on a vault
  (`renaming_a_vault_folder_whose_files_are_in_subfolders_keeps_it_a_vault`
  was red), and it was true of plain folders too, which lost their identity
  on every such rename.
- `detect_folder_moves`, for the vault that is empty even of that: an
  encrypted folder gone from its path, that stood on this disk
  (`synced_placement` set, the same line the folder-deleted reading draws),
  with exactly one unaccounted directory beside where it stood holding nothing
  the engine knows, is that folder renamed. One missing vault and one such
  directory per parent, or nothing: several candidates, or several vaults
  gone from the same parent at once, gets what a plain folder gets -- and
  says so in an issue naming the folders, whichever side is plural, because
  what follows is a vault trashed and a plain folder under a name the user
  gave a vault, and guessing instead would undo the user's deletion of one
  vault and carry its grants onto the folder they kept
  (`two_empty_vaults_leaving_at_once_are_not_guessed_at` and
  `an_empty_vault_leaving_beside_two_new_folders_is_not_guessed_at`, both
  from the review).
  The materialized condition is load-bearing:
  without it a vault the server has announced and nothing has created here
  yet paired with the user's next new folder, and what they put in that
  folder went up encrypted under the vault's name
  (`a_folder_dragged_into_a_vault_takes_its_files_in_with_it` caught it). A
  wrong pairing -- the user deleted the empty vault and made a plain folder
  beside it in the same pass -- keeps the new folder's bytes private, and
  carries the vault's sharing grants onto a folder the user meant as new.
  That is the direction chosen, and it is a trade, not a free one.
- `crossing_a_vault_edge`: a move whose destination parent is the agreed
  parent crosses nothing and is not judged.

`renaming_an_empty_vault_folder_keeps_it_a_vault` is red with either of the
last two removed: without the scan rule the vault is trashed, without the edge
rule it survives under its old name with the rename refused. It also checks
no issue was raised, the holder's directory stands, and a file saved into the
renamed vault goes up under the server's placeholder name, not in the clear.

**Still open on this axis.** A plain empty folder renamed is still trash plus
create, by design, and a vault folder with several new empty siblings made in
the same pass falls back to that. The rule pairs by position and emptiness,
not by name; a directory identity from the filesystem -- an inode for
directories -- would settle both properly, and that is a filesystem-layer
change not made here. A vault root dragged into a plain folder is refused
as out of reach, and rightly: the server takes a vault folder only at the
drive root or inside another vault (`protection_boundary`), so there is no
plain-to-plain reparent of a vault root to make. What was wrong was the
advice: the refusal told the vault's owner to change its protection level,
which is the way out for a folder INSIDE a vault and no way at all for the
vault itself. The issue is split by the agreed parent's protection: a folder
inside a vault is still sent to the level change; a vault root is told a
vault sits only at the drive root or inside another vault, and that the
server kept it where it was. Pinned by
`moving_a_vault_root_into_a_plain_folder_is_refused_and_said`.

The review found the same loss one level up, and it is open: a PLAIN folder
whose only content is an empty vault, renamed. The plain folder has no file
beneath it to be matched by, and the vault rule wants a candidate whose
parent is the vault's agreed parent, which the renamed plain folder is not
yet. Both are trashed, the vault's name is minted plain under the new parent,
and the next file saved there goes up in the clear. Pairing the plain parent
by the shape inside it -- a vault of that name at that relative path -- is
the identity-by-shape trade declined for plain folders above; the owner
decides whether a vault inside changes that. Pinned red, ignored, as
`renaming_a_plain_folder_whose_only_content_is_an_empty_vault_keeps_the_vault`.

The same trade, plain folders only, one axis over: a parent and the folder
inside it renamed in one go. `A/B/f.txt`, `A` renamed to `X` and `B` to `C`
before a pass. `B` is found under `X/C` by its file. `A` is credited with
`B/f.txt`, but the relative path changed with `B`'s name, so nothing under
`X` matches it: `A` is trashed, `X` is minted plain, `C` is moved into it.
Nothing is lost and the trees agree; what was granted on `A` is gone with it.
The rule that would find `A` -- pair a missing folder with the one new
directory its relocated child folders now share -- cannot tell this shape
from the user moving `B` into a brand-new `X` and deleting `A`, and in that
reading it carries `A`'s grants onto a folder the user made fresh. Trashing
on rename loses grants; pairing on deletion leaks them. Owner's call. Pinned
red, ignored, as
`renaming_a_folder_and_its_subfolder_together_keeps_both_identities` and
`renaming_a_folder_and_its_subfolder_with_the_old_name_rebuilt_keeps_both_identities`.
Probed green in the same session, on the staged tree, and kept as pins:
an empty vault renamed by case only on a folding disk; a file saved into
the renamed vault before the pass; one holder renaming the empty vault while
another fills it (the rename wins, the file lands inside, encrypted); two
holders renaming it to different names at once (one vault survives); and a
plain parent left empty by its child leaving, beside a new empty folder,
which is not paired with it.

---

## Defect R — the keyless guest whose vault directory stopped being the vault (OPEN)

Found by the Defect Q review, staged, red, and not fixed here because the fix
is a design choice.

**What the user loses.** Privacy, from the device that has no key. A guest
with no vault key makes a directory of the vault's name and puts files in it;
the engine holds them (Defect L), waiting for a key. The holder then renames
the vault on the server. On the guest's next pass the directory no longer
matches the vault by name, is adopted as a NEW plain folder under the old
name, the held claimants under it are swept as pointing at nothing, and the
files go up in the clear -- into a plain folder the user never asked for,
beside the vault they thought they were using.

**The mechanism.** A keyless device never materializes a vault folder, so the
folder's record carries no agreement; its local path is derived from the
server's name alone. The user's directory is the vault's only while the names
match. Nothing is written down when the match is made.

**The choice, with the obvious fix probed and rejected.** Recording the
directory as the held folder's agreed placement at the moment it is matched
does keep the tie: under that patch the red pin passes and the whole suite
stays green. It was then probed the other way: the guest makes the
placeholder, removes it again (it never held a byte of the vault), and later
gets the key. Traced: the vault is trashed on the server for everyone, and
worse, the holder's file inside it comes back at the drive root in the clear
-- the guest's pass reads the folder as deleted and materializes the released
file in the same pass, the file lands with no folder to stand in, and is
adopted as a new plain file at the root. Deleting the vault outright from a
key-holding device, by contrast, is clean today (probed too: both disks and
the server end empty).

So the tie cannot be the ordinary agreement. Either the deleted reading
learns that a folder never materialized with content cannot be deleted from
here, or the directory is tied to the held folder by a record that is not an
agreement. Owner's call. Pinned red, ignored, as
`a_keyless_guests_vault_directory_survives_the_vault_being_renamed`.


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

## Defect S — the held file whose record stayed where the file was not

Estate v15, seed 11091499, one of 40,070; the harness's stale-agreement
invariant, not a loss. A keyless guest and a holder. Pre-existing at
dcc95b23, before Defects P and Q.

**What happened.** The guest moved `Sub/Report 13.docx` to the root. The
move landed on the server and its answer was lost. Before the next pass the
workload's swap persona moved the guest's copy again, and it ended inside
the guest's hand-made `Private` directory, the vault this device holds no
key for. The queued move was retried, found the server already had the
file at the root, and was dropped as overtaken, which is right. The scan
then found the file's inode inside the vault and took the keyless-crossing
exit: a claimant waiting for the key was minted for the bytes, and the
source was held so the server's copy would not be trashed for a replacement
this device cannot send. Both waiting exits skip reconcile, so nothing
recorded that the server had moved the source. Its agreement kept naming
`Sub/Report 13.docx`.

The holder then made a new file under exactly that name. On the guest it
was parked `Unsyncable(DuplicateName)` against a name nobody was using, and
never reached the guest's disk. Reported, at least: an `unsyncable` issue
naming a duplicate that does not exist.

**Traced, with the sweep's trace runner.** `ROUNDS`, a per-round print of
the watched entity's record, its queued ops, the disk path holding its
agreed inode, and every record sharing the name, were added to
`scratch_onekey_trace` for this and stay. The swap persona picks a random
device's disk on any device's upload completing, which is how the guest's
file moved during the holder's pass.

**The fix.** A source waiting on a keyless vault is held, not frozen. The
hold is about the bytes and says nothing about the name, so both waiting
exits -- the hold standing, and the crossing that mints the claimant --
write the server's placement down as the agreement (`follow_the_server`).
The scan then looks for the source where the server has it, which is as
empty as the old path was, and the hold stands on the same premise as
before; the name the file left is free for whatever comes to take it.

**Pinned.** `a_held_file_moved_on_the_server_does_not_keep_its_old_name`
builds the four steps deterministically -- a lost move answer, the drag into
the hand-made vault directory, the holder following the move, the holder's
new file under the old name -- and asserts the new file reaches the guest,
the held file's record names the root, the held bytes are still on the
server, and no duplicate is reported. Red on the tree before the fix. Seed
11091499 passes and joins the pinned seeds in the workspace gate.

**The review found the other half, and it is fixed too.** A stranger saved
under the held file's name. The scan pairs by path first -- a file standing
at a path a live record is synced at is that record's -- so the stranger
became the held file edited: its bytes went up as a version of a file whose
real bytes were waiting in the vault, the hold lapsed, and nothing said so.
Pre-existing at the old path; following the server merely moved which path
was dangerous. Two changes, both from the same fact -- a held record's
bytes are known to stand somewhere else, so its path proves nothing:

- `KnownLocal::held` marks a source some provisional claimant `replaces`,
  and `pair` settles a held record by path only for the same inode brought
  back. Anything else at the path is a creation.
- The upload's ownership check (`mine`) no longer counts a held source's
  claim on a placement, the same carve-out it already makes for a parked
  record: nothing of the held file's is at that path, and counting it
  minted a fresh provisional for the stranger every scan and dropped it
  every pass, for ever.

Pinned by `a_stranger_saved_later_at_a_held_files_path_is_a_new_file` (red
with the scan mark ignored: the version chain takes the stranger's bytes)
and `a_held_file_does_not_take_over_a_stranger_at_the_servers_new_path`
(the stranger standing there first, its upload refused once). Both assert
the held file's server copy is live under its own bytes, the stranger goes
up on its own, and the claimant still stands. A held record with NO
fingerprint -- its upload finished while the user was already moving it,
so the agreement was written without one -- has nothing to match an inode
against and pairs by path with nobody; the bytes brought back are still
found by hash. Pinned by
`a_stranger_at_a_held_files_path_is_new_even_without_a_fingerprint`, red
when such a record was let through the gate.

**Still open on this axis.** `a_move_whose_answer_was_lost_is_finished_on_the_retry`
pins the plain shape -- the same lost answer with the file left where the
user put it -- as green on one and two devices: reconcile's same-target rule
writes the agreement there. The move op itself still reads its own landed
move as somebody else's and stands down; that costs a pass, not the record.

## Still open on this axis

- **`path_for`'s forward derivation is still weaker than naming.** Destination
  judging now catches the *collision* case — a move whose escaped leaf clashes
  with a file already in the destination parks the mover instead of evicting the
  sitter — but it decides only who yields, not what the mover should have been
  called. Closing that means naming resolving `remote` against the destination
  folder's sibling set and carrying a second, target-side mapping, at which
  point the forward derivation should be removed in the same change, not
  before.
- **A file already on disk IS renamed when naming re-maps it.** Recorded here
  earlier as unfixed; staged on 2026-09-02 with two Mac holders writing
  `notes.txt` and `Notes.txt` into one vault, and the loser's own disk copy is
  moved to `Notes (2).txt` on both devices. Pinned by
  `a_vault_files_case_twin_already_on_a_folding_disk_is_renamed_there`.
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
- **A local park a kill leaves standing is re-downloaded rather than
  finished.** `park_local` runs through `move_local`, which writes the scratch
  placement into `synced_placement`; the finisher then finds the parked file
  by that agreement, so the write is load-bearing there in a way the remote
  one (Defect P) was not. After a real process death between the local park
  and its finisher, the next pass's `observe` trashes the parked file (nothing
  on the server wears the name), the naming pass judges the agreed name, finds
  the reserved prefix, gives the local copy up and cancels the finisher, and
  the pass after materializes the file again under the server's name. A
  re-download, not a loss, and
  `a_local_park_a_kill_left_standing_costs_a_redownload_not_the_file` stages
  the aftermath and pins that the feared reading -- the empty slot taken for a
  deletion and sent up -- does not happen. Counting the agreement's scratch
  name as live in the sweep was tried and changes nothing: the naming verdict
  gives the copy up anyway. Finishing the park instead would mean the naming
  pass not judging an entry whose finisher is still queued. The simulator
  stages deaths only at network calls and a local move makes none, so no seed
  reaches this on its own.
- **A peer's abandoned-park recovery has no grace period.** Any device that
  polls between a park and its finisher sees the scratch name with a real
  agreement of its own and puts the file back, under the parker's feet; the
  parker's finisher then finds the file moved and is dropped, and the dance is
  re-planned. It converges, with an "unfinished operation" issue on the peer
  for a park that was never abandoned. Raised by the Defect P review;
  pre-existing. Swept on 2026-09-02 across the first twelve server calls of
  the parking pass with a Mac peer racing
  (`a_peer_putting_a_park_back_does_not_break_the_parkers_finish`): finished
  and nothing lost at every kill point. One more cost seen there: the
  put-back lands `x.txt` beside the `X.txt` the swap had already moved in,
  a case clash on the peer's folding disk, so the peer trashes its own copy,
  re-downloads it when the swap resolves, and keeps the "parked" issue that
  records the trashing. A grace period would remove all three.
