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

The review then asked the same question one level up -- a PLAIN folder whose
only content is an empty vault, renamed -- and it does not arise: the server
takes a protection level only at the root and a folder below inherits its
parent's (`drive_folder_create_logic`), so the parent of a vault is the root
or another vault. The sim's `seed_encrypted_folder` refuses a plain parent
now, so no scenario can be built on a state the platform cannot reach. The
reachable shape, a vault whose only content is an empty vault, keeps both
identities through a rename: the outer is placed first and the inner is
found beside where it stood inside the outer's new directory. Pinned by
`renaming_a_vault_whose_only_content_is_an_empty_vault_keeps_both`.

The same trade, plain folders only, one axis over, is DECIDED (owner,
2026-09-02): a parent and the folder inside it renamed in one go.
`A/B/f.txt`, `A` renamed to `X` and `B` to `C` before a pass. `B` is found
under `X/C` by its file and keeps its identity. `A` is credited with
`B/f.txt`, but the relative path changed with `B`'s name, so nothing under
`X` matches it: `A` is trashed, `X` is minted plain, `C` is moved into it.
Nothing is lost and the trees agree; what was granted on `A` is gone with it.
The rule that would find `A` -- pair a missing folder with the one new
directory its relocated child folders now share -- cannot tell this shape
from the user moving `B` into a brand-new `X` and deleting `A`, and in that
reading it carries `A`'s grants onto a folder the user made fresh. Trashing
on rename loses grants; pairing on deletion leaks them. A grant lost is
visible and given again; a grant leaked is neither. Trashing stays.

Pinning the decision found that `B` was being trashed too (Defect V below):
its move into `X` could not be placed while `X` had no identity, and the
trash of `A` then took `B` with it. Pinned green, as decided, by
`renaming_a_folder_and_its_subfolder_together_keeps_the_subfolder_and_remints_the_parent`
and `renaming_a_folder_and_its_subfolder_with_the_old_name_rebuilt_keeps_the_subfolder`
(the rebuilt `A` is `A`: a folder standing at its path is not deleted).
Probed green in the same session, on the staged tree, and kept as pins:
an empty vault renamed by case only on a folding disk; a file saved into
the renamed vault before the pass; one holder renaming the empty vault while
another fills it (the rename wins, the file lands inside, encrypted); two
holders renaming it to different names at once (one vault survives); and a
plain parent left empty by its child leaving, beside a new empty folder,
which is not paired with it.

---

## Defect R — the keyless guest whose vault directory stopped being the vault

Found by the Defect Q review; fixed 2026-09-02 evening on the owner's call.

**What the user lost.** Privacy, from the device that has no key. A guest
with no vault key makes a directory of the vault's name and puts files in it;
the engine holds them (Defect L), waiting for a key. The holder then renames
the vault on the server. On the guest's next pass the directory no longer
matched the vault by name, was adopted as a NEW plain folder under the old
name, the held claimants under it were swept as pointing at nothing, and the
files went up in the clear -- into a plain folder the user never asked for,
beside the vault they thought they were using.

**The mechanism.** A keyless device never materializes a vault folder, so the
folder's record carried no agreement; its local path was derived from the
server's name alone. The user's directory was the vault's only while the
names matched. Nothing was written down when the match was made.

**The choice, and why the obvious fix was wrong.** Recording the directory as
the held folder's agreed placement keeps the tie, and was probed: the guest
makes the placeholder, removes it again (it never held a byte of the vault),
and later gets the key. Under that patch the vault is trashed on the server
for everyone, and the holder's file inside it comes back at the drive root in
the clear. So the tie cannot be the agreement.

**The fix.** The tie is its own record, `Entry::stand_in`: the placement of
the directory standing in for a vault folder this device cannot open. Set
the first time a directory is found at the folder's derived path while the
folder waits for a key; read by `local_placement` when nothing has been
agreed, so the files held under it resolve their paths through it; never
read as an agreement, so the folder-deleted reading (which keys on
`synced_placement`) cannot fire on it. From then on:

- **The directory follows the server** (`placeholders_follow_the_server`,
  before the disk is walked, for the reason naming runs there). Renamed or
  moved on the server, the directory is renamed here and the held files stay
  held under the vault's current name. Parents before children, so a stand-in
  inside a stand-in resolves through a parent already moved. Not journaled,
  because it needs no journal: a death between the rename and the record
  leaves the directory at the server's path, where the next pass finds it at
  the folder's derived path and ties it again from nothing.
- **Removed by the user, the tie lapses.** The directory never held a byte of
  the vault, so nothing is deleted anywhere and nothing is said to anyone.
- **Materialized for real, the agreement takes over.** `create_local_folder`
  turns the stand-in into the folder -- renaming it to the server's path if
  the two still differ -- rather than making a second directory beside it,
  and clears the tie.
- **Something in the way at the server's path.** A folder this device tracks
  keeps the tie waiting (its own record says where it goes). Anything else --
  a directory the user just made, a file -- is moved aside under a conflict
  name first, as anything in the way of a synced copy is. Left in place it
  was adopted as a folder of its own, refused by the server for the name the
  vault holds, and the record and the directory parted company for ever
  (Defect U). A respelling of the same slot -- a case-only rename on a disk
  that folds case -- is neither: compared raw, the stand-in found ITSELF at
  the destination, was moved aside under a conflict name, and the tie lapsed
  on a directory that had merely changed case, with its files then adopted
  plain. Both rename sites compare with `same_slot` (the executor's
  per-component `comparison_key` test) and rename in place. Review finding;
  pinned on macOS by
  `a_case_only_vault_rename_on_a_folding_disk_respells_the_guests_placeholder`.
- **Trashed on the server while files are held under it.** The ordinary
  reading of an unmaterialized folder's deletion is to forget it, which
  forgot the claimants, left the directory and the user's never-uploaded
  files on the disk, and the next pass adopted the lot as a plain folder and
  uploaded every file in the clear. A deletion made elsewhere is no
  permission to publish them. `park_stand_ins_of_trashed_vaults`, every
  pass, after the disk has been walked and before the round: a trashed
  folder with files held under its stand-in is parked `OutOfScope` (skipped
  by the round, so nothing forgets it; the tie keeps the directory from
  being adopted; the claimants stay held) and the user is told
  (`vault_deleted_upstream`); with none held it goes back to waiting, the
  complaint is withdrawn, and the ordinary deletion reading forgets it in
  that pass's round. Restored from the trash, it goes back to waiting for a
  key the same way. Decided after the walk and from what is on the disk
  now, for two reasons the second review found: decided when the deletion
  was absorbed, a guest whose daemon was down while it saved into the
  placeholder had no claimants yet, and the folder was forgotten on the very
  pass that minted them; and a user who followed the complaint left a
  folder parked for ever. What is held is read from the walk -- the files
  the scan found under the stand-in's path -- not from the store. Nothing
  under a stand-in has ever been sent, so every file there is the user's and
  unsent, whether it was saved there and has a claimant or was moved there
  from a synced path and gets its claimant in the round loop, after the
  decision; counting entries missed the second kind (third review), and a
  folder was forgotten on the very pass that found the file moved into it,
  with the plaintext source then moved into a plain folder of the vault's
  name. Reading the walk also asks nothing of a placeholder the user has
  replaced with a file of the same name, which a stat under it refused and
  failed the whole pass on. Everything under a parked folder is skipped by
  the round with it (`shadowed`), so a claimant released by a key that
  arrives while the folder is still trashed is not planned as an upload into
  a trashed parent and refused every pass. An EMPTY placeholder of a trashed
  vault goes with the vault -- trashed here, as a materialized empty vault
  folder would be -- before the round forgets the folder; left standing it
  became a plain folder of the vault's name, everything saved into it went
  up in the clear, and the holder's restore met a plain sibling of the same
  name. The held count compares paths the way the disk does, component by
  component through `comparison_key`: read raw, a placeholder the user had
  respelled by case on a folding disk counted as empty and was trashed with
  the user's files in it (fourth review). "Empty" is the disk's own listing
  to any depth (`dir_is_empty`), not the scan's, since the scan leaves out
  what it cannot sync -- a symlink, a file that vanished mid-walk -- and a
  placeholder holding only those is not empty: it stays parked and tied
  (fifth review: with the tie dropped it was adopted as a plain folder of
  the vault's name on the next pass). Pinned by
  `a_vault_trashed_upstream_keeps_the_guests_held_files_unsent_and_says_so`,
  `a_vault_trashed_while_the_guest_was_down_still_parks_and_the_complaint_clears`,
  `a_key_arriving_while_the_vault_is_trashed_waits_quietly_for_the_restore`,
  `a_synced_file_moved_into_a_placeholder_of_a_trashed_vault_is_held_not_forgotten`,
  `an_empty_placeholder_of_a_trashed_vault_goes_with_it`,
  `an_empty_placeholder_with_empty_subfolders_goes_with_its_vault`,
  `a_placeholder_replaced_by_a_file_while_its_vault_is_trashed_does_not_break_the_pass`
  and `a_parked_placeholder_respelled_by_case_on_a_folding_disk_keeps_its_files`.
- **Respelled by the user on a folding disk.** The walk matches a directory
  to a folder not yet on the disk in its own right by the disk's comparison
  key, not by spelling: matched raw, a stand-in the user had respelled by
  case was adopted as a new plain folder beside the vault and everything
  under it went up in the clear. Matched, the record takes the disk's
  spelling. Two such folders folding to one key -- the server keeps names
  by exact spelling, so `Private` and `private` can share a parent -- match
  nothing by key, since a pick between them would be a pick by hash order
  and a directory tied to two vaults at once. Pinned by
  `a_placeholder_respelled_by_case_on_a_folding_disk_stays_the_vaults`.
- **A held file taken away again.** A file the guest saves inside its
  placeholder is a local-only record waiting for a key; when the vault is
  parked the round skips everything under it, and the check that forgets a
  local-only record whose file is gone from the disk ran below that skip. So
  a file saved under the placeholder -- before the trash or after the park --
  and removed again left a record with no file behind it and no server that
  ever heard of it, for good. The forget check runs before the park skip;
  only an operation in flight comes ahead of it. Estate seed 15091598, pinned
  by `a_file_removed_from_a_parked_placeholder_is_forgotten`.

The store gains two columns (`stand_in_parent_id`, `stand_in_name`; schema
version 6).

**Pins.** `a_keyless_guests_vault_directory_survives_the_vault_being_renamed`
(the rename, then the key: the files go up encrypted under the new name);
`a_keyless_guest_removing_its_placeholder_deletes_nothing` (the probe that
rejected the agreement, now green);
`a_placeholder_whose_new_name_is_taken_here_waits_and_then_follows` (the
user's plain folder is moved aside, the placeholder follows, the key
arrives, nothing ever in the clear);
`a_nested_placeholder_follows_each_rename_above_and_of_it`.

**Noted, pre-existing, outside this defect.** `create_local_folder` records
the plan's spelling as the agreement without reading the disk's: on a
folding disk, a directory the user made as `docs` before the server's `Docs`
arrived is agreed as `Docs`, and the next walk reads a case rename the user
never made (or, empty, a deletion). The same read-back `same_slot_spelling`
makes would close it. And the scan does not ignore `.DS_Store`, so a
placeholder Finder has touched counts as holding a file.

**Still open on this axis.** A placeholder whose ANCESTOR the user renames
(not respells) while it is parked: only a vault inside a vault has such an
ancestor, the outer placeholder's tie lapses on the rename (the case below),
and the inner's path then leads nowhere, so it reads as held-nothing and is
forgotten. The same loss as the case below, by way of the parent.

Two stand-ins whose names the holder swaps on
the server: each finds the other's record holding the name it wants and
both wait, and when the key arrives neither can be materialized where the
server has it. The materialized path breaks this cycle with a scratch park;
the stand-in path has none. Nothing goes up in the clear. Pinned red,
ignored, as `two_stand_ins_whose_names_are_swapped_on_the_server_follow`.

The guest renames its own placeholder while
keyless. The tie names the directory by path, and a directory has no
identity of its own to be found by once it leaves that path; the files held
under it have never been uploaded, so there is no agreed fingerprint or
content to find them by either. The renamed directory is adopted as a plain
folder of the new name and the held files go up in the clear under it -- the
same reading as the user dragging them out of the vault, which from the
outside it is. Directory identity from the filesystem, or a fingerprint
recorded for a held claimant, would settle it; neither is made here. Pinned
red, ignored, as `a_keyless_guest_renaming_its_placeholder_keeps_the_tie`.

---

## Defect T — the device keyed later never opened the files it already knew

Found pinning Defect R: the guest that removes its placeholder and later
gets the key never received the holder's file.

**What the user lost.** Availability, on every device linked second. A
file's real name and content id live in metadata only the key opens, and the
feed mentions each file once. A device that heard about a file while keyless
recorded the grant and the placeholder name and nothing else, and nothing
came back to it: the key arrived, the folder was materialized, and every
file inside stayed parked `PendingKey` for good. Link a second laptop, unlock
the vault on it, and the vault's files never come down. Independent of
Defect R; it needs only a key that arrives after the feed.

**The fix.** `open_what_the_key_unlocks`, after the feed is absorbed: the
encrypted files whose grant this key opens and whose metadata has never been
read are asked about again in one batched stat, and absorbed, which opens
them. Bounded: a file whose grant opens but whose metadata still will not is
asked about every pass and said once (`metadata_unreadable`, withdrawn the
pass it opens), which is visible, where a file never asked about is neither.

**Pin.** `a_device_given_the_vault_later_opens_the_files_it_already_knew`.

---

## Defect U — a folder landing under a conflict name left its directory behind

Found pinning Defect R's occupied-name case.

**The mechanism.** `create_remote_folder` answers a `name_taken` refusal by
creating the folder under a conflict name, and recorded that name as the
agreement while the directory on disk kept the planned one. The next pass
read the folder as gone from its path and trashed it, adopted the directory
as new, and landed it beside again: a folder minted on the server every
pass, for ever, and a device that never went quiet. Unreachable while every
name the server refuses is one this store can fold into (two devices making
one folder name at once fold the provisional into the real folder when it
arrives -- pinned green by
`two_devices_making_one_folder_name_at_once_keep_both_files`); reachable
the moment the name is held by something this store cannot fold into, which
a stand-in's server name is.

**The fix.** The disk follows, as it does for a file that lands beside an
occupant: the directory is renamed to the conflict name when the create
lands under one.

---

## Defect V — a folder trashed while its contents were still moving out

Found pinning the parent-and-subfolder decision: `B` was trashed along with
`A`, not merely re-minted.

**The mechanism.** `B`'s move into `X` was journaled with `X`'s provisional
id as its destination, since `X` had no identity until the untracked
directories were adopted (the move itself could not even be placed until
then: `FolderScan::deferred`, resolved by `place_deferred` after adoption).
`X` was created first in the round and took a real id, but the move still
named the provisional one, was turned back as overtaken, and the round went
on: the trash of `A` ran after the moves, and the server's cascade took `B`
with it. Deletes run last in a round so that a folder is empty before it
goes; a move that had not landed left it not empty.

**The fix.** Three parts, each a rule in its own right. A provisional folder
taking its real id redirects every queued operation that named it as a
parent (`redirect_queued_parent`), so the move runs in its turn -- safe under
the idempotency rule because an operation naming a provisional parent has
never been sent. Both places a provisional folder takes a real id do it: the
create landing, and `merge_duplicate_folders` folding it into a folder the
feed brought (a create whose answer was lost reaches the fold; pinned by
`a_folded_provisional_parent_still_receives_the_move_into_it`). The queue
runner reads each op fresh before running it (`Store::get_op`), since an op
earlier in the run can now rewrite a later one. And `trash_remote` will not
trash a folder while a queued move's entity still sits under it, which holds
across the retries a round cannot see.

A folder found under a directory that never gets an identity -- refused
adoption for a name this disk cannot hold -- cannot be placed at all. It is
on the disk, so its record stands, and `place_deferred` marks every folder
it is still recorded under as present with it: reading one of those as
deleted would trash it on the server and take this folder in the cascade.

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

## Defect W — the abandoned park put back into a folder that was gone

Estate v20, seed 16062180 (`longhostile-mixed`: mac, pc, hfs; a server that
refuses in prose alone). Three devices, never settling, over one folder.

**Shape.** mac moved `Sub 4/Sub 22` (508) into `Sub 4 (56) (56b)` (511)
under a name pc had already created there, and its move parked 508 under
`.jd-swap-mac-…` as the last resort, then lost its answer. mac's trash of
`Sub 4` (502) landed the same round. pc and disk then saw a park nobody was
coming back for and put it back "where both sides last agreed": (502,
`Sub 22`). 502 was in the server's trash and forgotten in every store. The
put-back's rename was refused (`Sub 22` taken in 511), its reparent was
refused with "that folder is in the trash" -- in prose, which reads as
possibly-about-the-name -- so the put-back parked 508 again under its own
key, was refused again, and was withdrawn with the park standing. Every
device rescued that park, into the same trashed parent, every pass: a fresh
scratch name per device per pass, for ever.

**Three roots, each pinned red on its own.**

- **The put-back aimed at a parent that was gone.** The rescue reads the
  agreement's parent as the destination without asking whether that folder
  is still a live folder in this store. It is not, here: trashed on the
  server and forgotten, with only this agreement naming it. The put-back
  now goes where the server has the folder NOW when the agreed parent is
  not live (missing, or marked deleted), under the agreed name or the next
  free one among that parent's known children. Pinned by
  `a_park_whose_agreed_parent_is_gone_is_put_back_beside_where_it_stands`,
  which runs with and without the prose-only server and never settled
  before.
- **The put-back was written as an agreement.** It is queued as a
  `move_remote`, and a completed `move_remote` records the new placement as
  agreed with the disk -- true for a move derived from this disk, false for
  a put-back: the directory has not moved, and beside where it stands is a
  different folder from the one the agreement names. Written, the record
  said the directory was already there; the pass read the directory where
  it really was as the user moving it back, and once its parent's trash had
  taken it, as the user deleting it, and the server's copy followed into
  the trash. The rescue's op carries `disk_follows`, and `move_remote` then
  records `remote` alone, as it does for a park; the ordinary local move
  brings the directory along next pass. Both pins go red without it (the
  folder ends in the server's trash).
- **The local trash took a child that lives elsewhere on the server.** A
  folder's local trash rescues what never reached the server and lets what
  the server took go with it. A child the server has MOVED OUT -- live,
  under another folder, its directory still here because its new name (a
  scratch name, until the put-back) cannot be placed yet -- is neither, and
  went into the local trash with the parent, leaving a record with an
  agreement and no directory. `trash_local` now waits, as `trash_remote`
  already does for a move on its way out, while any tracked live entry's
  LOCAL placement chain passes through the folder and its server placement
  does not (`local_chain_passes`, the twin of `sits_under`). Pinned by
  `a_folder_the_server_moved_out_of_a_trashed_parent_is_not_trashed_with_it`
  (the child arrives under a scratch name; without the guard its file is
  pulled out to the root as unsynced and the folder is trashed on the
  server).

**Considered and not done.** A move that parks as its last resort and is
then withdrawn (a refusal that was never about the name, on a prose-only
server) leaves its park standing until the next pass puts it back; undoing
the park before withdrawing was built and dropped, because the simulator
has no hook to trash a folder between the feed read and the op run, so it
could not be pinned red on its own, and the rescue already owns that park.
The cost is one pass and a scratch name peers may see between passes.

**Review round (public-html-74), 2026-09-03.** All four fixes (B1 and W a/b/c)
graded genuine and root-fixed; two non-blocking gaps, both closed:

- *The fallback parent may itself be in the trash* (feed marks the row it
  names, the cascade is silent, the child reads live until its parent's
  local trash asks the server). Read further: a put-back beside where the
  folder stands is a rename alone, never a reparent, so it cannot reach the
  last-resort park; it is refused and withdrawn, one pass, no scratch name.
  A skip for that case was built, could not be made red, and was dropped.
  Pinned as a guard by
  `no_put_back_is_queued_into_a_folder_that_is_itself_in_the_trash`: no
  scratch name of this device's is ever minted and nothing is queued.
- *The local trash guard can wait for good in silence.* A child parked as
  far as this device can see (waiting for a key, out of scope, a name this
  disk cannot hold) holds its trashed parent here until that ends, and a
  queued `trash_local` retries with no ceiling and raises nothing. The wait
  is the right trade (trashing the child's copy is the loss this guard
  exists to refuse), and it is now said: `trash_local` raises a state issue
  `trash_waits` naming the folder and the child when anything on the
  child's local chain up to the folder is not `Synced`
  (`local_chain_parked`), dismisses it the moment the trash proceeds, and
  the top of every pass dismisses one whose folder is forgotten or no
  longer deleted (forgetting an entry does not take its issues with it).
  Pinned by the ordinary shape the reviewer named, with no scratch name in
  it: `a_locked_vaults_folder_trash_waits_for_a_child_the_server_moved_out`
  -- this device's vault locked (`World::lock_vault`, new in the sim), a
  peer moves `Private/A/Sub` to `Private/B` and trashes `A`; the trash
  waits and says so, the unlock finishes the move, nothing is lost. Before
  the guard, `Sub` went to the local trash with `A` and its server copy
  followed on unlock.

## Defect X — the file disowned for the name it was leaving, and the move that did not know its own half

Estate v21, seed 17121565 (`hostilename-kill`: two Linux devices, chaos,
kills). At convergence the server held `Sub 37 renamed/.jd-swap-desktop-…`
with the same bytes as `Report 27.docx` beside it, and both devices carried
a `reconcile` issue saying there was no recorded name to put it back to.

**Shape.** The desktop renamed a file into a rename cycle; the planner parked
it under a scratch name on the server and its finishing move was refused by a
server hiccup and left queued. Before the retry, the laptop created a file
under the parked file's OLD name. On the desktop's next pass the parked
file's agreement still named that slot, the in-place naming verdict read the
newcomer as a duplicate the parked file must lose, gave up its local copy and
cleared its agreement, the finisher found nothing to finish, and the desktop
uploaded the same bytes again as a new file. The park stood for good.

**Two roots, each pinned red on its own.**

- **Naming released an entry mid-operation.** The round loop already leaves
  busy entries to their operation; the naming verdict did not, and read a
  collision against the slot the entry was leaving. `apply_naming` now
  leaves a materialized entry with an open operation alone (the verdict
  waits a pass, and is usually moot by then). The same guard on
  `judge_destinations` was built, made no pin red, and was dropped. The
  guard covers collision verdicts only (duplicate, case and Unicode clashes):
  a verdict about the entry's own name, such as the reserved prefix of a
  local park a kill left standing, is about this disk now and has to stand,
  or the entry reads as deleted once that park is swept and the server copy
  goes with it (`a_local_park_a_kill_left_standing_costs_a_redownload_not_the_file`
  went red under the broad guard). **Pinned by the seed itself**, in the
  gate script (red with the guard reverted, green with it) and not by a
  scenario: isolating it needs the finisher held open across two passes
  while the newcomer is downloaded to the slot it names, and the simulator's
  fault knobs cannot hold one operation open that long deterministically (a
  lost answer closes on the next pass; a rate is blind). Three scenario
  shapes were tried and each was green without the guard for a reason of its
  own: the record heals once the server has done the move, or the newcomer
  is not yet materialized when the verdict runs.
  `a_file_parked_mid_swap_is_not_disowned_for_the_name_it_is_leaving` keeps
  the combined shape (park recorded, finisher refused by a searched 5xx
  rate, the laptop's newcomer), which the second root below carries.

  **Second sighting, estate v23 seed 19124426** (`winname-kill`: Linux box,
  Windows pc): the same disowning by way of the PUT-BACK rather than a
  finisher. The box's own cycle park stood after a kill dropped the
  finisher; the pass's put-back was refused twice by chaos and once by the
  name, and between passes no operation was open on the entry, so the busy
  guard never saw it: the cycle partner's agreement and disk file were at
  the parked file's slot, the verdict made the parked file the loser, and
  its agreement was cleared. A scratch-named entry with no operation open is
  the put-back's, and the put-back is queued after naming and answered
  before the next pass, so it is never open when naming looks. The guard
  now also covers an entry whose server name is a scratch name. Pinned by
  the seed in the gate script; a deterministic scenario needs the
  same-folder swap read as a rename cycle, which the workload reaches and a
  hand-written swap through a held name does not (the scan reads it as two
  content edits) -- two shapes tried, neither exercised the park.
- **A half-applied move was not its own.** `move_remote` is rename then
  reparent (or the reverse), two calls; a death or a lost answer between them
  leaves the entity under the new name in the old folder or the old name in
  the new one, and the resumed op read that as somebody else's move and stood
  down. The next pass re-derived the move from the disk against a record a
  pass stale, and naming disowned it: the bytes uploaded again beside the
  half-moved copy. Either half is now recognised as this op's own, exactly as
  its scratch-name park already was, and the op finishes from there -- on a
  retry only, and only while the move is still half done. A first attempt
  has done nothing, so a half-shape it meets is a peer's move that merely
  looks like one, and stands down as before. A move the server has already
  completed (a lost answer to a move that changed only the folder, where the
  half is the whole) has nothing to finish, and proceeding wrote the
  agreement blind to what stood at the new path on this disk
  (`a_held_file_does_not_take_over_a_stranger_at_the_servers_new_path` went
  red under each of the two broader conditions). Pinned by
  `a_half_applied_move_is_finished_as_ours_after_a_kill` (found while
  pinning the first root: the death one call later).

## Defect Y — a parent moved under its own child, applied in the wrong order

Estate v25, seed 21093056 (`hunt-platform-longhostile`: mac, pc, and a
decomposing disk). The disk device never settled, and at the end it reported
itself quiet with two folders each agreed inside the other.

**Shape.** Another device swapped a parent and its child on the server: the
child (`Sub 22`) moved out to the grandparent, then the parent (`Contested
Folder`) moved in under it. The disk device read both moves in one feed and
planned both `ApplyRemoteMove`s at the same rank. The parent's ran first,
while the child still sat inside it on the disk: a directory moved into
itself. The simulated filesystem allowed it and the record agreed to it; the
child's move then failed with `folder tree has a loop in it`, was withdrawn,
and was never planned again, because every path under the loop refused to
resolve. Quiet, and wrong.

**Three parts.**

- **The simulator lied.** `MemFs::rename` moved a directory into its own
  subtree. Every real filesystem refuses that (`EINVAL`), so the disaster
  could not happen on a real disk in this form; it now refuses too, and with
  only that the seed still never settles, because the parent's refused move
  is retried first every round and the child's, ranked beside it, never
  gets its turn. Pinned by
  `a_parent_swapped_under_its_own_child_on_the_server_is_applied_child_first`
  (red on the pristine tree: never settled).
- **The planner orders moves by name slot only.** A move whose destination
  is inside the mover's own subtree now waits for the nearest move on the
  chain up from the destination that takes that folder out, and a move with
  nothing on the chain moving is left out of the round (`FolderParents`,
  built by the pass from every tracked folder's placement on each side and
  handed to `plan`). Pinned by the planner unit test
  `a_folder_moving_into_its_own_subtree_waits_for_the_subtree_to_leave`, and
  by that test ALONE: with this part removed and the other two kept, the
  scenario pin above is green and only the unit test goes red. So the scenario
  pins the simulator's refusal and the `move_local` guard, not the ordering,
  and the "never settled with only the simulator fixed" claim made for it here
  was about a tree that also lacked the guard. Re-checked 2026-09-04.
- **`move_local` refuses the shape itself** (`Retry`, waiting for the
  subtree to move out) before asking the disk or writing an agreement.
  Described here first as an unpinned belt to the planner's braces; it is the
  opposite. Removing the ordering leaves the scenario pin green, so this guard
  and the simulator's refusal are what that pin actually rests on. It stands
  between a wrong parents map and a record with a loop in it, and it is not
  redundant with the ordering it was meant to be redundant with.

The same swap made by the user on this disk and pushed to the server was
tried as a pin and is green on the pristine tree (the server refuses the
crossing and the round re-derives), so it was not kept.

## Defect Z — a folder replaced by a namesake and moved inside it

Estate v26, seeds 22081285 (`killplat-mac-pc`) and 22081684
(`killplat-mac-hfs`): kills, no network chaos. Found the day Defect Y's
belt-and-braces guard went in, and it was that guard's forever-wait that
surfaced it -- the same shape reached the same deadlock before, through the
simulator's permissive rename.

**Shape.** Another device replaced a folder on the server with a new folder
of the same name and moved the old one inside it (renamed): three changes in
one feed. On this disk the old directory wears the name the new folder needs,
and the old folder's move needs the new folder to exist. Naming parked the
new folder as a duplicate of the old one's agreed name, so it was never
created; the old folder's queued move waited for it, keeping the entry busy so
no round could plan anything else; both waited on each other for ever. Pinned
by `a_folder_replaced_by_a_namesake_and_moved_inside_it_steps_aside_first`
(red before: never settled), and by both seeds in the gate script.

**The resolution is a park.** The old folder steps aside under a scratch
name, the new folder is created at the freed name, the old one moves in. That
already existed for a cycle of renames; it was not reachable for a
create-versus-move cycle, and a local park was recorded wrong. Six parts,
each shown load-bearing for the pin unless noted:

- **Naming: an entrant does not lose to a leaver.** A materialized entry the
  server has moved elsewhere still competes for its old slot (its directory
  is still there), but an entry never materialized that wants that slot is
  next in line, not a duplicate. Parked, it is never created. The verdict is
  dropped for that pair; the round orders them.
- **The planner sees a create as an arrival.** `CreateLocalFolder` takes a
  slot (`PlanItem::arriving`); it waits for the mover holding that slot, and a
  move into a folder being created waits for the create. That closes the
  cycle, and the cycle breaker picks its victim among the PARKABLE members
  only (those with somewhere to leave) -- a folder being created has nowhere
  to go.
- **A queued move into a folder not yet on this disk stands down**
  (`Overtaken`) instead of waiting: its destination path would resolve by the
  server's name alone to whatever directory wears that name here -- its own
  -- and waiting keeps the entry busy so the round never plans the park.
  Defect Y's Retry guard was the forever-wait that found this.
- **A local park is in place.** `park_local` renamed within the entry's
  REMOTE parent, which is the folder that does not exist yet; it renames
  within the folder the directory is in now.
- **A local park is recorded as the spelling on disk, never the
  agreement**, as a remote park is recorded as `remote` alone. Into the
  agreement, the scratch name became the entry's real name: naming judged it
  unholdable, parked the entry, and swept the directory it had just stepped
  into. With it, a parked entry competes for its scratch name and no other
  (`competing_placement`), naming leaves it alone entirely
  (`parked_locally`, while the finisher is open -- a park nobody is coming
  back for is still judged, swept and re-downloaded, the abandoned-park
  path), and its finisher reads the parked path first rather than the
  pre-park path something else now stands at.
- **The walk's litter sweep knows a live local park.** It counted live
  scratch names by server names alone, so a local park with its finisher
  queued read as litter and was trashed with everything inside it one pass
  after it was made. A local park with an operation open on it is live; one
  with nothing queued is the abandoned kind and is still swept.
- **A parked entry's finisher waits for the create** it is queued behind
  (`Retry`) rather than standing down when the folder is not on this disk
  yet: standing down left the park with no operation open, which reads as
  abandoned.

Estate v26 also flagged `night-long-2dev` seed 22035030 on the tree before
this defect; it settles on the tree with it and is in the gate script.

The last three are pinned together by
`a_park_mid_replacement_survives_its_create_being_refused_once` (the new
folder's create refused once by the disk after the park lands: red without
any one of them, the directory swept or the file gone), the others by the
first pin. The busy gate on the naming skip cannot be exercised by a death,
because the park, the create and the move make no network call between
them and the simulator's deaths land on network calls.

### Review round (2026-09-04)

The fix above was reviewed before it was committed, and two of its seams were
holes. Both reproduce on this tree and both are GREEN on a pristine HEAD: as
first built, the cure cost more than the deadlock it cured. The shapes are
ordinary -- rename a folder, then make a new folder with the old name.

**The newcomer adopted the leaver's directory.** Dropping the duplicate
verdict removed a wait, and nothing in the executor replaced it.
`create_local_folder` treats a directory standing at its path as its own --
right for a stand-in, right for a re-run of the same op, wrong for a directory
another tracked folder still holds. With the old folder's rename slow to land,
the create adopted the old directory, both entries agreed on the one
directory, and the leaver's rename then carried it away; the newcomer read as
locally deleted and the folder the user had just made was trashed on the
server, entry and all. Fixed where the question belongs: a create waits while
another live folder entry's local path resolves to the directory in its way.
The file case one block above says the same thing in the other voice -- a FILE
in the way is moved aside, because nothing else is coming for it; a tracked
FOLDER is waited for, because something is. Pinned by
`a_new_folder_does_not_adopt_the_directory_of_the_one_it_replaced`.

**The finisher asked the disk instead of the plan.** Park, create and finisher
are three ops of one operation and they fail independently. The finisher
decided whether it was parked by looking for the scratch name on the disk, so
a park whose rename was refused once had not landed when the finisher ran: it
stood down as overtaken and was dropped, and the park landed a pass later with
nothing coming for it. The abandoned-park give-up then trashed the directory
with the file inside it, and the next scan sent that deletion to the server.
The finisher now counts a park still QUEUED exactly as much as one already
worn. Pinned by `a_finisher_waits_for_its_own_park_before_the_park_has_landed`.

**A put-back for local parks was built and removed.** The remote mirror
exists: an entry left wearing a scratch name on the server with no operation
open is put back to its agreed name. The local one was written and then taken
out again, and the argument for taking it out needs stating carefully, because
the obvious version of it is wrong.

A finisher CAN leave the queue without finishing. There is no cap on attempts,
but the error classification withdraws an op on any 4xx that is not 404, 409,
423 or 429 and on a contract or unknown-op error, and overtakes it on a 404, a
missing local path or a destination whose ancestor is no longer tracked; and
`move_local` has early exits of its own. So "nothing withdraws an op" is false
and is not the reason.

The reason is narrower: every one of those exits needs the parked entry, or
the folder it is going into, to be FORGOTTEN rather than merely trashed or
renamed -- and nothing forgets an entity while it has ops queued, which a park
and its finisher both are. Attempts to construct the state failed on both
sides of the review: the server undoing the replacement under a standing park,
with the namesake trashed and with it kept, converges with the file live and
no scratch name left. The instrumented put-back fired zero times, which is
true but weaker evidence than it looks -- a pass loop that does not advance the
clock leaves ops in retry backoff, so part of that zero is the harness rather
than the engine.

An unreachable rescue for a folder is worse than none, because it reads as
cover for a case nobody has proven. What replaces it is the pin
`a_park_whose_destination_is_trashed_keeps_its_finisher_and_its_files`, which
holds the exposed state -- park standing, destination gone -- and fails if a
later edit to `move_local` lets a finisher out while its park stands. If a
shape is ever found that does leave a local FOLDER park with no operation
open, the give-up path is the thing to change rather than a rescue bolted
beside it: its safety argument is a file's -- the server still holds the bytes,
so the cost is a re-download -- and that has never been true of a subtree.

`create_local_folder` does not check `remote_deleted` on its own entry, so a
create for a folder the server has already trashed builds the directory anyway
and the following pass takes it away again. Harmless, and noted so the next
reader does not take it for a hole.

**The leaver rule fires a pass late, on purpose.** The rule that keeps an
entrant from losing to a leaver asks the entry's STATUS, and a folder the
server has only just announced still holds a local file by that test. So the
newcomer is parked as a duplicate once and the rule undoes it on the pass
after: it reads like a rule that prevents the park and it is a rule that
undoes one. Asked of the agreement instead -- `synced_placement.is_none()`,
which is what "never materialized here" really means -- it fires a pass
earlier and swallows verdicts two pins depend on (9_280 and 9_285, both seen
red that way). The one-pass park costs nothing now that the executor refuses
to adopt a directory another folder still holds.

## Defect AA -- a folder rename undone for the folder's contents

Pre-existing: red on a pristine HEAD as well as on the tree above, so it is
not Defect Z's and not this round's to fix. Recorded here so it is not found a
third time.

**Shape** (traced; the mechanism below is read, not executed). One device, no
faults, no kills. The server renames folder `X` to `Y` and creates a new
folder `X` in the same feed -- nothing moves into anything. The device settles
with `note.txt`, which was inside the renamed folder, sitting inside the NEW
folder on BOTH the server and the disk; `Y` is left empty, and the entry
raises `DeleteLostToEdit{Local}`. Both sides agree, so convergence is
satisfied and the estate oracle passes it: the file is in the wrong folder,
consistently, everywhere. The user renamed a folder and made another with the
old name, and their file followed the name rather than the folder.

## Defect AB -- the parked twin's files poured into the folder that won the slot

**Shape** (traced and reproduced). One Mac, no faults, no kills. The server
holds two folders in the same parent whose names differ only in case --
`readme` and `README` -- each with a file in it. The volume folds case, so the
two are one slot. Naming does the right thing: the second parks
`Unsyncable(CaseClash)` with an issue raised, because only the user can say
which one they meant.

Its FILE does not stop. A parked folder has no directory but still has a name,
and a child's path is built by walking parent names, so `README/b.txt`
resolves to `readme/b.txt` and materializes there. The device settles with one
folder on the disk holding both folders' contents, nothing saying so, and an
issue that describes the folder clash and not the merge.

**And it does not stay local.** The child's record is rewritten to the folder it
landed in and that is pushed up: the run ends with the SERVER holding
`readme/b.txt` and `README` empty. The file has been reassigned to a different
folder for every device the user owns, permanently, and deleting the surviving
folder now takes it with them.

Nothing is lost, both sides agree about every file, and the estate oracle
passes it -- the same way it passed Defect AA. This was found by asking what
the sweeps could not mint rather than by a seed: the workload names folders
`Sub {step}`, `Shared` and `Contested Folder`, so **no arm can produce two
folders whose names are case twins**, and no number of seeds would have
reached it.

**Fix.** The naming pass already parks everything under a folder parked out of
scope (`shadowed`). It now does the same for a folder parked with no directory
of any kind: its children are skipped for exactly as long as it is, and the
parent's issue stays the only thing the user is asked to act on.

The condition is "no directory" because that is the whole reason to hold a
child -- there is nowhere right for it to go. A parked folder that DOES have a
directory holds the user's files for real and its children must not be held.
No construction found so far reaches that case: a folder stranded under a
scratch name is put back rather than parked, and a naming clash parks the
entrant, which never had a directory. So the condition states the reason and
nothing pins it -- a test written for it passed with the condition removed and
was dropped rather than kept as false protection.

The same hole is reachable with no folding at all, on every platform: a FILE
called `Notes` that already holds the slot and a FOLDER called `Notes` arriving
after it. The folder parks `DuplicateName`, and without the fix its child was
written into the place the user's file occupies. No sweep arm can mint that
either -- files are `doc-{i}.txt` and folders are `Sub {step}`, so the two
never share a name.

Pinned three times, all red without the fix:
`a_child_of_a_case_clashed_folder_does_not_land_in_the_twin` and
`a_child_of_a_unicode_clashed_folder_does_not_land_in_the_twin` and
`a_folder_parked_behind_a_file_of_the_same_name_holds_its_children`. The second
is the same situation through a different verdict -- `UnicodeClash` rather than
`CaseClash` -- and the third has no folding in it at all, which together show
the hold is about a parent having no directory rather than about case.

**Still open:** the workload cannot mint a case-twin FOLDER at all, and cannot
mint a folder name that needs escaping either -- folders are only ever
`Sub {step}`, `Shared` or `Contested Folder`. Closing that means another sweep
arm, which changes the draw sequence and re-rolls every pinned seed, so it is a
deliberate cost to pay rather than a free addition. Two halves were checked by hand instead
and are both sound. A folder the user named to look like one of the engine's
own conflict copies survives a round trip to a second device untouched, with
nothing raised (`a_folder_named_like_a_conflict_copy_keeps_its_name`) -- the
hostile arm mints that shape for files only. And the escape half: a server folder named `CON` lands on Windows as
`%43ON`, its children and grandchildren resolve inside it, the server is never
told the escape is a rename, and a file the user writes into that directory
uploads into the folder it stands for. Covered by
`a_folder_windows_cannot_name_carries_its_escape_to_its_children`.

## Defect AC -- a folder waiting for a namesake's subtree to empty

Found by estate v30, one seed in 4,100 of its arm
(`hunt-longhostile-2dev/26090313`, two Linux devices, 80 steps, chaos, no
kills). Pre-existing: the same seed fails identically on the tree before
`6ef36a51`.

**Shape** (traced and reproduced). Folder 520 is `PendingDownload` with nothing
agreed -- no directory of its own anywhere on the disk. Its old placement, root
`Sub 44 renamed`, has since been taken over by a different folder, 501. The
queued `move_local` for 520 names that old path as its source, and the source
test asks the disk whether a directory stands there. One does: 501's. A
directory carries no identity, so the move adopts it, sees its own destination
`Sub 44 renamed/Sub 6` sitting underneath, and refuses as a move into its own
subtree -- then waits for contents to leave a folder that was never its. Three
hundred and forty-one attempts and no way out. Forever-loop shape 1: a retry
whose premise expired.

**Fix.** Before any test is made against `from`, ask whose directory it is --
and ask a RECORD, because the disk cannot answer. If another folder holds a
directory by its own agreement at exactly this path, it owns what stands there
and this move stands down; the next round decides again from the whole tree.
This is the same question `create_local_folder` asks before adopting a
directory, in the layer that can see the answer.

Pinned by the seed, in the gate script. Three narrower-looking fixes were tried
and abandoned, all plausible and all wrong:

- "a folder with no recorded placement has no directory, so it cannot be
  moved" -- broke `a_folder_the_server_moved_stops_saying_it_is_waiting_for_bytes`,
  where the directory is genuinely the folder's and only the record has lagged.
- "if another tracked folder's record puts it at my source path, stand down",
  filtered on `holds_a_local_file` and applied whether or not the move was a
  real move -- five failures: during a park and replace another folder standing
  at the source is ordinary. The shape was right; the filter and the missing
  `from != dest` guard were not.
- "if this folder's own path is already the destination and the destination is
  there, the move landed" -- **this one passed every gate and was still
  wrong**, caught in review by public-html-0e. For an entry with nothing agreed
  `local_path` falls back to the REMOTE placement, so its own path IS the
  destination by construction: the test collapsed to "is a directory there",
  the very question a directory cannot answer, and the comment claiming the
  record as tiebreak described a check the code never made. What it read was a
  directory `download` had minted on the way to a file (B3 below). Proof:
  adding a parent-materialized gate to `download` makes the seed loop again at
  349 attempts under that fix, and settle under this one.

## Defect AD -- swapping two folder names carries a vault's contents out in the clear

**Severity: a file the user put in a vault reaches the server as plaintext,
under its real name, as the result of an ordinary rename.** Pre-existing:
reproduced identically on `6ef36a51^`, before any of this round's changes.
FIXED.

**Shape** (traced and reproduced). One device. An encrypted folder `Private`
holding `memo.txt`, an ordinary folder `Public` holding `notes.txt`, both
settled. The user swaps the two names the way anybody would, and the way a file
manager does it: `Private` to a scratch name, `Public` to `Private`, the scratch
name to `Public`. No file is touched. After settling, the server holds
`Public/memo.txt` -- the vault file's real name and its plaintext bytes -- while
`notes.txt` has been encrypted into the vault it never belonged to.

**Mechanism.** The engine resolves a name swap as CONTENTS MOVING BETWEEN
FOLDERS rather than as two folders being renamed. Probed directly on a plain
pair with subtrees: after a swap, both folder entities keep their original
names, and every file plus a whole subfolder is re-parented from one to the
other. So the vault folder entity stays where it is wearing its own name, the
memo is re-parented into the plain folder, and is then uploaded exactly as any
file arriving in a non-vault folder is -- in the clear.

**Scope, established by probe rather than assumed.** Two neighbouring shapes are
both CORRECT, which is what makes the defect narrow and precise:

- A vault folder renamed out of the way, settled, and its old name then taken by
  a plain folder: safe. Identity survives, the memo stays encrypted under the
  new name. So the engine tracks identity through a rename perfectly well -- it
  loses it only when both names are occupied.
- A plain folder moved INTO a vault: safe. Its contents are encrypted on the way
  up.

The trigger is a swap that completes between syncs, so the engine only ever sees
the end state, in which each name is held by the other folder's contents.

**Why no seed could find it, corrected.** The claim below was half wrong and is
kept because the correction matters. The workload DOES trade names -- action 13
in `zz_sweep.rs` swaps two, or three-way rotates, over a small shared set both
devices work on -- but the slots are FILES (`slot-1.dat` and friends). It has
never once traded FOLDER names, which is the whole of this defect's territory.

**Seed inertness, measured rather than argued.** The claim is that adding the
arm re-rolls no pinned seed. There are three touch points in shared paths -- the
`matches!` in `into_the_vault` (first term false for every other vault, so
`rng.below(2)` is short-circuited exactly as before), the `13 if` guard (a
comparison, no draw), and the ring seeding (entirely inside
`if vault == FolderRings`). That reasoning was checked by review and holds, but
it is an argument, so it was also measured.

Method: an ISOLATED copy of the sync tree with its own target directory, so the
shared checkout is never modified -- the earlier attempt swapped the file in
place, which parks the working tree in a reverted state while it runs and is not
worth doing on a machine other people are using. `zz_sweep.rs` is the only file
differing from HEAD, so replacing just that one file in the copy reproduces HEAD
exactly, and both halves are built in the same environment. The comparator is
the workload's own per-seed dials (`DIALS=1`: swaps during uploads, landing
saves, folder renames, kills), which are directly sensitive to the random
stream.

Result: **identical across 60 runs** (VAULT=0, 1 and 2, twenty seeds each). Not
vacuous -- the 60 lines are all distinct, and `swaps` and `folder_renames` both
range 0 to 4, so a shifted stream would have shown. No pinned seed re-rolls.

**Two follow-ups on the arm, from review (public-html-0e), neither a blocker.**

*It goes quiet exactly where it matters.* In the sim `user_rename` cannot fail,
so the workload's rotation always completes on the disk -- but the moment the
ENGINE renames a ring aside, which is what AF does, that device has fewer than
three rings standing at their names and the arm becomes a silent no-op on that
device for the rest of the run. So it stops trading on precisely the seeds where
the trade is interesting, and after AF is fixed the same will be true on any
seed where a park or an aside is in flight. Fix: trade among whatever rings
still stand when at least two do, and count trades per seed so a run that made
none reports itself vacuous, in the spirit of `no-ciphertext: vacuous`.

*A ring world never runs the FILE name trades.* The ring arm of action 13 sits
ahead of the file arm and takes the whole action number, so `Shared/slot-N.dat`
is never traded in a ring world. Either say so where the arm is written or give
the ring trade an action number of its own so both fire.

*And a hazard to pin while it is still true:* `same_side_of_the_vault` knows
only `VAULT_ROOT`, so the encrypted ring is a second vault the crossing refusal
has never heard of. The sealed-content assertion is sound ONLY because nothing in
the generator can produce a path under a ring -- `dirs` starts at the root,
`files` starts empty, and no arm enumerates the disk. An arm that ever picks
paths from the disk would break that silently, so the constraint belongs in a
comment at `same_side_of_the_vault` naming the rings.

The fix for the coverage, when it is built: a parameter switching action 13
between file slots and folder slots. `Names` is already threaded through
`workload_core` explicitly, so a flag that is off by default consumes no
different randomness and re-rolls no pinned seed, and the folder variant runs as
its OWN estate arm rather than being folded into the default action set. Two
requirements on that arm, both learned here rather than guessed
(public-html-0e, 2026-09-07): it has to run under the FAULT matrix and not just
the clean workload, because the happy path was green while a single refused
rename leaked the vault (B4 is unfindable by a sweep that never refuses a
rename); and at least one slot has to be a VAULT folder, because the plain
shape converges and only the identity and sealing assertions can see the
difference. An arm that asserts nothing about identity will pass forty thousand
seeds exactly the way AA, AB and AC did.

The original reasoning, which still holds for the vault half: the workload
refuses to generate vault-edge crossings on purpose -- "the server refuses an in-place crossing and the client
cannot yet make one for itself, so generating one would test a feature nobody
has written". That guard rail has a hole in it: the user never crosses the edge,
a folder rename carries them across. No sweep arm swaps two names either, for
the separate reason that a swap takes a scratch name and two renames and no arm
does that.

**The fix: a name trade is read as a closed permutation, or not at all.**

Two things were wrong, and only the first turned out to matter.

`detect_folder_moves` never ran on a swap. It opens with a cheap exit --
nothing has gone from where it was, and there is no unaccounted directory for
anything to have moved TO -- and a swap satisfies both while having very much
happened: each name still holds a directory, and each directory is one the
engine already has a folder for. So it returned without looking, and the pass
fell through to the reading that re-parents every file. That is the whole
mechanism.

The exit is completed rather than removed, because it earns its keep:
`relative_path` is a store read per ancestor per file, and the settled case has
to stay cheap. The question added to it is whether any tracked file has changed
folders, which no swap can be true without, and which is answered from the
parent id on the record and the path map -- a lookup per file on the disk and
not one path resolved. An ordinary single file moved between two folders
answers yes too and pays for the full evidence; that is a scan where something
really did move, not the settled case the exit exists for.

The rule itself takes a closed permutation and nothing less. A `contested` set
holds the tracked folders whose directory is standing, is not corroborated, and
holds files the engine knows -- precisely the shape `displaced` refuses,
because on its own it is also what one file moved out of a folder looks like.
Each member's directory must hold exactly one other member's contents
WHOLESALE, and following who-left-where must return to the start. Two folders
whose contents both landed under one directory cannot be told apart and are
refused. `displaced` is untouched: widening it drags a folder after a single
file, which is how two earlier attempts at AD regressed
`a_rename_refused_onto_a_siblings_name_is_re_derived_once_the_name_frees_up`.

**The second half was not needed, and that is worth recording.** The plan was to
feed the name walk from both sides -- the scan's intended placement for local
movers alongside `remote` for feed movers -- because a local mover's `remote` is
its ORIGIN, not its destination. It turned out no such change is required:
by the time `judge_destinations` runs on a local ring the local names are
already the new ones, so there is no clash left to resolve, and the planner's
cycle-breaker sequences the two renames on the server by itself. AE is the
reverse direction -- the feed bringing names that clash with what this disk is
standing on -- and that half is already fixed.
`three_folders_rotating_names_locally_keep_their_identities` is the test that
tells the directions apart, since a two-folder swap is symmetric and a walk
following the ring the wrong way round still closes on it.

**Estate.** v38 on the B2 and B6 tree: 16 arms, 89 sweeps, 40,070 seeds at
shift 34000000, ONE failure -- seed 34121769, which is Defect B8 and reproduces
identically on the committed engine with all of this reverted. v36 on this tree: 16 arms, 89 sweeps, 40,070 seeds at shift
32000000, zero failures; v35 on `d2c04fbe` immediately before it, the same
totals at shift 30000000. What that is evidence OF is worth stating, because it
is easy to over-read: it shows the change causes no regression, which matters
because `detect_folder_moves` now runs in cases it used to exit from. It is NOT
evidence about AD itself -- no arm trades folder names, which is the whole
reason forty thousand seeds never found this. The fix is carried by the pins
below and by the fault-injected review, not by the estate.

Pins, each proven red on the pre-fix `pass.rs` first:
`a_vault_and_a_plain_folder_trading_names_keep_their_own_contents` (the vault
follows the folder, not the name), `two_folders_trading_names_keep_their_identities`
(which fails pre-fix with exactly the prediction above -- the folder that was A
is still called A), `three_folders_rotating_names_locally_keep_their_identities`
and `a_local_swap_and_a_feed_rename_in_one_round_both_land`. Green companions
that establish the scope:
`probe_a_plain_folder_taking_a_vault_folders_old_name`,
`probe_a_plain_folder_moved_into_a_vault`,
`probe_two_folders_trade_names_with_a_peer_watching`,
`probe_a_file_and_a_folder_trade_names`.

**The limit of the evidence, established under review (public-html-0e,
2026-09-07).** The ring reading cannot be told apart from a CONTENTS EXCHANGE,
and this is inherent rather than a gap in the rule. If the user moves every file
of `A` into `B` and every file of `B` into `A` and renames nothing, the disk and
the server end up in exactly the state a name trade produces; this engine reads
it as the trade and gives the two folders each other's identities, where before
it read it as file moves. Same disk, same server tree, opposite identities, no
issues raised either way. No lesser file move counterfeits it -- partial,
one-way, and emptied-into all fail the wholesale test -- but a total exchange
does, and nothing in a scan can separate them, because a directory carries no
identity.

It is two-sided across a vault edge, and neither side is free. Dragging a
vault's contents out and a plain folder's contents in, with no rename, leaves
the ring reading calling the vault `Public` and calling the PLAIN folder
`Private` -- so everything the user saves into `Private` afterwards goes up in
the clear. Reading it as file moves instead publishes the memo immediately,
which is the pinned drag-out policy. The rule ships as it is: a name trade is
the plausible act, and of the two readings the ring one errs toward keeping the
vault sealed.

**Still open around it**, none blocking:

- **A swap where one side is EMPTY.** An empty folder holds no contents, so a
  ring cannot close through it, and
  `probe_a_vault_swapped_against_an_empty_plain_folder` stays red and ignored.
- **Say the ambiguity out loud.** When a ring includes an encrypted folder,
  raise a reconcile issue naming the trade, the way the empty-vault rule already
  does for its own ambiguity.
- **The real close is directory identity.** `DirEntry` already carries
  `fingerprint: Option<Fingerprint>`, and a directory has an inode or file index
  on every platform this runs on. A folder record that remembers its directory's
  file id at materialization makes a swap and an exchange exact rather than
  inferred, and closes the empty-side gap as a fact rather than a guess. A store
  field and a scanner change: its own spec, not this one.

## Defect AE -- a folder-name swap made elsewhere is silently undone

**Blocks Defect AD.** Pre-existing, at HEAD, with nothing local involved.

**Shape** (traced and reproduced, probe
`a_folder_name_swap_made_on_the_server_survives`). One device, settled. The user
swaps two folder names somewhere else -- another device, or the web UI -- so the
server holds the swap before this device syncs:

    server before sync:  A/b.txt   B/a.txt
    server after sync:   A/a.txt   B/b.txt

The device put its own stale layout back and undid the rename, raising nothing.
`assert_converged` and `assert_nothing_lost` both pass: the two sides agree
perfectly on the wrong answer, which is the AA/AB/AC blindness again on the
plainest possible input -- no vault, no local action, no chaos, one device.

**Why it blocks AD.** AD's fix is to read a local swap as two renames instead of
as a mass file move. The reading can be made correct -- it was built, and traced
to `intended={A -> B, B -> A}` with exactly the two renames emitted -- but the
renames are then handed to machinery that cannot apply a rename cycle. With the
detection in place the same server-side case fails DIFFERENTLY: conflict copies,
parked issues and `MoveRaceServerWon` instead of a silent revert. Neither is
right, so the detection was reverted rather than shipped. Until two cyclic
folder renames can be applied -- from the feed and from a local scan -- no
amount of better detection fixes AD.

**Answered, traced by public-html-0e.** It is the same machinery and it is
reached -- the planner had `broken_cycles` right on the two-folder case -- and
its ops were refused because a pre-round name verdict had already parked the
entities. `judge_destinations` judges each moved folder's new server name
against its settled siblings' CURRENT names: in a swap each wants the name the
other is still standing on, so both are given up as duplicates, both flip
`Unsyncable`, and park and moves alike are dropped on entries naming has already
parked. The next pass then "recovers" both with `synced_placement` cleared,
plans a create for each, and each ADOPTS the other's directory by name -- after
which the files read as moved and the server is told. Hence the silent revert.

**Fix.** A holder about to vacate its name does not hold it against the arrival
that takes it. "About to vacate" cannot be read from the record alone -- that
also describes a holder whose move is half-finished, stuck, or being put back by
a peer, and exempting one of those hands the arrival a name that never comes
free (the peer-put-back kill sweep fails exactly there). The safe case is a
CLOSED one: the name this entry wants is held by somebody who wants a name held
by somebody, and the chain comes back here. Then every name in it is vacated by
the same round, which is precisely what the cycle-breaker sequences.

The exemption is a PAIRING, not a pass. A name in a closed chain is free for
exactly one entity -- the member that takes it -- so `trading` maps each arrival
to the one holder it displaces. Written as a set it let an unrelated newcomer at
the same slot past a clash nobody resolved: on a folding volume, with the server
holding `A`, `B` and `b` while `A` and `B` traded, the user's third folder was
conflict-renamed ON THE SERVER by a device that had only been told about
renames. Found in review, pinned by
`a_swap_does_not_let_an_unrelated_case_twin_past_the_clash`.

Pins: `a_folder_name_swap_made_on_the_server_survives`,
`a_three_folder_name_rotation_from_the_server_is_applied`, and the case-twin pin
above. `two_stand_ins_whose_names_are_swapped_on_the_server_follow` stays red and
ignored -- a stand-in's competing placement is its remote, so no duplicate is
ever raised and that one is the "each waits for the other" its own comment
describes.

**Still open on this axis.** The exemption assumes the round plans every chain
member's move. A member the round holds instead -- parent shadowed, or parent
parked without a directory (the AB hold) -- breaks that assumption. A same-parent
swap holds both, plans nothing, and is fine; a cross-parent swap into a parked
parent is Overtaken by `move_local`'s parent gate and retried: not destructive,
not quiet. Worth a probe. And `move_local` has a source-holder guard but NO
destination-holder guard -- `make_room` moves whatever stands at the destination
aside -- so any future widening of this exemption lands on `make_room` rather
than on a refusal.

**Decision already taken, for when this is unblocked.** Where the client cannot
tell a folder rename from a mass file move, the owner chose the RENAME reading:
publish nothing, and raise an issue naming both folders ("Private and Plain
traded names; the vault is now called Plain"). The catch accepted with it is
that a vault can end up wearing the other folder's name until the user acts.

## Defect AF -- two devices trading folder names publish the vault's contents

**Severity: the plaintext of a file the user sealed reaches the server, under
its real name, from an ordinary rename made on two computers at once.** Found
2026-09-08 by the new folder-ring sweep arm, on the tree WITH the AD fix in, at
a rate of **11 seeds in 40** on a clean network with no faults and no kills.
NOT FIXED.

**Shape.** Three folders side by side, one of them encrypted, and both devices
trading their names. Seed 74000, two devices, forty steps, clean network. The
server ends holding:

    ring-1/enc-469d...                                    (encrypted, fine)
    ring-2 (conflicted copy ... from desktop)/sealed.txt  (IN THE CLEAR)

`sealed.txt` is the file written into the encrypted ring before the workload
started. It is now in a plain conflict-copy folder as plaintext, and the plain
folder's file has been carried the other way and encrypted. The same inversion
as Defect AD, reached by a different route: AD's ring detection resolves a trade
one device makes, and this is two devices trading at once, where the engine
mints a conflict copy and resolves the collision by moving CONTENTS between the
folders again.

**Not the workload's doing, established rather than assumed.** The ring folders
are never in the generator's `dirs` or `files` -- those start as `[root]` and
empty, and only ever collect what the workload itself creates -- so no action
can move, rename or write a ring's CONTENTS. The only thing done to them is
trading the three folder names. Every byte that crossed the vault edge was moved
by the engine.

**Not B4.** B4 needs a refused rename; this reproduces with no fault injection at
all.

**Why nothing found it before.** Nothing could. See the corrected note under
Defect AD: action 13 trades names, but its slots are FILES. In forty thousand
seeds per estate the workload has never traded a folder name.

**Mechanism, traced by public-html-0e on seed 74000.** 502 is the vault ring
holding `sealed.txt` at inode 1001. The laptop trades ring-2 with ring-3; the
desktop trades ring-1 with ring-2, so the VAULT's directory now wears the name
`ring-2` while its record still says `ring-1`. The laptop's trade arrives at the
desktop as a remote move of 504 onto `ring-2` -- which is occupied by the
vault's directory. `make_room` moves it aside to a conflict name. That directory
is now claimed by no record and holds the vault's plaintext. The file is then no
longer at 502's agreed path, which reads as the user deleting it, so the
CIPHERTEXT copy is trashed on the server; the scan meets the conflict-copy
directory as a brand new plain folder, and inode 1001 under a plain folder reads
as a drag out of the vault, which converts by design. The sealed file is
uploaded in the clear, and the plain ring's file goes the other way and is
encrypted.

**Where it is.** `make_room`'s directory arm moves ANY directory aside without
asking whose it is. `move_local` has a SOURCE-holder guard, added for Defect AC,
and no DESTINATION-holder guard -- which the AE review named at the time and
this is the cost of. AD's ring rule is not involved and does the right thing on
the laptop: AF is an arriving remote move colliding with a local trade the
record has not absorbed yet.

**Fix shape, two layers.**

1. Near-term, in `make_room` for a DIRECTORY: ask the records before moving
   anything aside. Where a live folder record owns what stands at the
   destination -- the vault's directory holds an inode a record knows, so
   `holds_nothing_known` is false -- the arriving move stands down `Overtaken`
   and the round decides again once the local trade is absorbed. The engine must
   never mint a conflict-copy DIRECTORY out of a directory whose contents it
   knows; that is the same shape `displaced` already refuses, one level up. If a
   move-aside is ever unavoidable, the record that owned the directory has to
   follow it, so the scan meets 502 under the conflict name instead of a
   stranger.
2. The family's close: **directory identity**. A folder record that remembered
   its directory's `file_id` would have told `make_room` whose directory it was
   outright. AF is the FOURTH defect -- AC, AD, AE, AF -- whose cheapest honest
   answer is to ask the directory who it is, and it settles AD's
   contents-exchange counterfeit and the empty-side ambiguity at the same time.
   That spec should come before any further per-site guard.

**A refusal in `make_room` was tried and does NOT fix it -- measured, and the
negative result narrows the cause usefully.** The near-term shape above was
built: `make_room` refuses to move a directory aside when it holds files the
engine knows AND no record resolves to it. The ring arm stayed at exactly 11
failures in 40, the same seeds. Instrumented, the guard is reached once per run,
at the destination, and declines to refuse -- `known = true, claimed = TRUE`. A
record does claim that path: the vault folder itself, whose local rename the
scan HAS absorbed.

So the premise was wrong. The engine is not failing to recognise the occupant;
it recognises it and moves it aside anyway, and the record does not follow the
directory. It is 0e's fallback clause that is the fix, not the refusal:

> If a move-aside is ever unavoidable, the record that owned the directory must
> follow it, so the scan sees 502 at the conflict name rather than a stranger.

The refusal was reverted rather than kept -- it never fires in this case, so it
is dead weight on a hot path, and an inert guard on a security-sensitive route
invites the belief that something is protecting it.

**And the pin that refusal broke turns out to BLESS this defect.** A first,
broader version (contents alone, no "claimed" test) failed
`a_second_folder_conflict_at_one_name_gets_its_own_name`, which reads as moving
a tracked folder aside being designed behaviour. Verified here on what that test
actually leaves behind, at 0e's prompting:

    server:  Docs (conflicted copy ...)/f1.txt      -- and no `Docs` at all
    store:   folder 501 is GONE
             folder 504 is NEW, wearing the conflict name

So "designed" is true only of the BYTES. The mechanism underneath is IDENTITY
LOSS: the displaced folder is deleted on the server and re-minted under a new
id, with its contents re-uploaded as new content. The test asserts all three
files still exist and never asks whether the folder that held them survived, so
it has been green over this the whole time.

That is the same mechanism as AF, and it is why AF was reachable at all. With a
plain folder it costs a server id nobody looks at. With a VAULT, the re-upload
of "new content" into a folder the engine no longer knows is encrypted is
exactly how the sealed bytes reach the server in the clear. **When AF is fixed,
that pin must also assert the displaced folder keeps its server id** -- as it
stands, a green run there is not evidence of anything but the byte count.

**Order to build it in** (public-html-0e, and the reason not to start at the
belt):

1. **The vault-conversion gate** -- but NOT where it was first specified. The
   policy below is right; `crossing_a_vault_edge` is the wrong site, and it was
   measured rather than assumed. Traced on seed 74000: the leak is not a
   conversion at all. The two uploads of `sealed.txt` are

       UploadAsNew entity=File(-3)  provisional=true  parent=502   (correct, encrypted)
       UploadAsNew entity=File(-12) provisional=true  parent=515   (the leak, in the clear)

   The second is a PROVISIONAL entity -- a brand new record the scan minted --
   into folder 515, itself minted from the orphaned conflict-copy directory. The
   engine has entirely forgotten these bytes were ever sealed: there is no move,
   no encrypted entry and no edge to cross, so `crossing_a_vault_edge` is never
   consulted and gating it changes nothing.

   The enforceable form of the policy is one step later, where a PROVISIONAL
   file is about to be uploaded as new content: ask whether these bytes are
   already held under encryption. The inode is the link -- 1001 belongs to the
   sealed file's record -- and `entity_for_file_id` can answer it, which it
   could not have done before Defect B6 was fixed, because the scan was NULLing
   the entity out. If the answer is yes, the upload stalls with an issue instead
   of publishing.

   **BUILT, MEASURED, AND IT DOES NOT FIX AF. Reverted.** The gate was written
   exactly as specified -- a new `UnsyncableReason::AlreadySealed`, a
   `sealed_source_of` reading the ENTRIES rather than the hash index, keyed on
   the inode alone, placed at the file mint so no upload is ever planned. All 11
   known AF seeds still leak, and instrumenting the mint says why in one line:

       minting "ring-2 (conflicted copy ...)/sealed.txt" inode=1001 sealed_source=None

   The encrypted records at that moment contain no entry for inode 1001 at all.
   The sealed record is not deleted-but-present, it is already FORGOTTEN by the
   time the provisional is minted. The fail-open window this gate was known to
   have is not an edge case: measured per seed, it is 11 of 11.

   So the gate cannot be the first layer. It only ever fires while the sealed
   record still exists, and on the AF path it never does. Whatever is built
   first has to be something that stops the record being forgotten -- the
   planner's two-claimants rule, or the record following its directory -- and
   the gate is worth revisiting only afterwards, when there is a record left for
   it to match. Reverted rather than kept: it costs a full entry scan per
   created file and fires in no known case, and an inert guard on a route that
   publishes vault contents invites the belief that something is protecting it.
2. **The planner's two-claimants rule.** The first-order defect is upstream of
   `make_room`: one round planned an ApplyLocalMove and an ApplyRemoteMove onto
   the SAME slot, which the AE review already recorded the planner cannot see.
   The honest resolution is the verdict the engine already has for files --
   `MoveRaceServerWon` -- applied to folders: the local rename lost, so revert
   the directory and tell the user, then let the remote moves land in dependency
   order. With that, `make_room` never meets a claimed directory in AF at all.
3. **The move-aside as a park**, as the belt for what the planner still misses:
   the record follows the directory in `local_name`, in the shape `park_local`
   already uses, wearing a `.jd-swap-` SCRATCH name rather than a user-facing
   conflict copy -- so the stranded-park put-back returns it to its agreed name
   if the round dies, and the oracle's "no `.jd-` name survives" assertion
   covers it. NOT `synced_placement`, which is the agreement and a lie there
   becomes a delete pushed at the server; NOT `stand_in`, whose lapse rule is
   written for a keyless device's placeholder and would silently drop a
   directory holding the user's real files.
4. **Directory identity**, as the close -- and the thing that lets the pin above
   assert the id survives.

Do NOT build 3 before 2: a belt on a route the planner should never send anyone
down is the same inert-guard problem as the refusal that was just reverted.

**What actually forgets the sealed record, measured on seed 74000.** Neither
reading that was proposed. Every operation the run plans was logged:

    20 create_local_folder   19 upload_new   19 download
    11 create_remote_folder   4 move_remote   2 trash_local
     2 move_local             1 unmaterialize_and_park

There is **no `TrashRemote` at all**. The record is destroyed by `trash_local`
on file 901 -- the LOCAL application of a deletion, which forgets the entry.
And `trash_local` has an arm that forgets an entry with no file found:
`Placed::Not(Unplaced::AncestorMissing)`, whose comment reads *"there is
provably nothing on this disk to put in the trash -- and the record has to go
with that conclusion"*. Here that conclusion is FALSE: 901's ancestor is folder
502, whose directory was moved aside without its record, so the chain cannot
resolve -- while the bytes are sitting on the disk under the conflict name the
whole time.

That is the forgetting site, and it explains every measurement: the record is
gone before the mint (11 of 11), the inode is still on the disk, and the scan
then adopts those bytes as brand new plaintext content.

**Who marks the sealed file deleted, measured.** A `trash_local` is only ever
planned for (local None, remote Deleted), so 901 carried `remote_deleted` before
that operation existed -- and a directory rename on this disk cannot make the
server say a file is gone. Instrumented at every writer of that flag, both flips
in the run come from **`absorb_remote`** (pass.rs), which is the feed or a stat
reporting a genuine server deletion. Not the parent-trashed cascade, not the
download's `gone` closure, and not either `server_view_after_retry` site. Both
`trash_local` operations are on FILES -- 901 and 902 -- and neither is folder
502.

**That does NOT establish a genuine server deletion, and the first reading of it
here was wrong.** `absorb_remote` has four callers and only one is the feed:
`poll_remote` (the feed, a real server event), `walk_index` (a full index
re-derivation, reached exactly when a folder's chain breaks -- which is the
state AF is in), `open_what_the_key_unlocks` (a `stat_all` on sealed files
waiting for a key), and `forget_folder_the_server_confirms` (a stat on a trashed
folder's children). The last three all carry the `missing` flag that means "gone
OR no longer visible" -- two server statements wearing one flag, as that
function's own comment says. So the trap above is not ruled out; it may simply
have re-entered wearing `absorb_remote`.

**A second fact, established from the run already taken.** The operation counts
were FLEET-WIDE -- the instrumentation sat at the op-queue point with no device
filter, and the sweep runs both devices in one process -- and there was **no
`trash_remote` at all**, for a file or a folder, on either device. So no device
asked the server to delete anything. "The other device trashed a sealed file"
is therefore NOT the open question. The open question is why the server's answer
for a live file was read as gone.

**Where the next session starts** -- these two lines before any build:

1. **Which of the four `absorb_remote` callers wrote 901 and 902.** If it is
   `poll_remote` and the other device's log shows a trash, the aside fix goes
   first and that device's reason is the open defect. If it is `walk_index` or
   either stat, the root is a sealed file read as deleted from a `missing`
   answer, the aside fix is SECOND, and nothing should be built on the
   forgetting site at all.
2. Confirm (1) against the fleet-wide fact above rather than a single device's
   view.

Nothing is to be built on the forgetting site until line 1 is answered.

**Line 1 answered, and it corrects two facts recorded above (2026-09-09).**
Instrumented at all four `absorb_remote` callers, with the device named, across
all 11 failing seeds and with an exact test filter -- the first attempt used
`scratch_one` as a substring, which also matched `scratch_onekey_one` and mixed
a second test's devices into the log.

Of the sealed-record flips from live to deleted: **12 `poll_remote`, 4
`forget_folder_the_server_confirms`**, and none from `walk_index` or
`open_what_the_key_unlocks`. So the dominant path is the feed reporting a
GENUINE server deletion, not a `missing` answer misread.

**Which makes the next question who deleted it on the server, and the answer
overturns the fleet-wide note above.** Instrumented inside the mock's
`drive_trash` -- where nothing can hide, since it is the only site that sets
`trashed` -- the desktop trashes the sealed file outright:

    SRVTRASH dev=desktop kind=file id=901

There IS a `trash_remote`, on both a file and a folder, on every failing seed.
The earlier count that found none was taken at the op-queue point and missed it,
because this trash is **queued directly in `pass.rs` and never passes through
`reconcile`** -- a `PLAN_TRASH` probe on the `(Deleted, None)` arm stays silent
through the whole run.

**The whole chain, traced on seed 74000, in the order it happens:**

    ASIDE         dev=desktop dir=true from="ring-2"
                  to="ring-2 (conflicted copy ... from desktop)"
    CONVERT_TRASH dev=desktop id=File:901 enc=true
                  remote=Placement { parent: Some(502), name: "sealed.txt" }
                  local=Moved { to: Placement { parent: Some(-11), ... } }
    SRVTRASH      dev=desktop kind=file id=901
    AFTRACE       dev=laptop who=poll_remote id=File:901
                  was_deleted=Some(false) enc=Some(true)

`make_room` moves the vault's directory aside; the scan reports the sealed file
as MOVED, to parent **-11** -- a negative id, so a PROVISIONAL folder, the
conflict-copy directory the engine minted from its own aside. That reads as a
drag out of the vault, the conversion trashes the server's ciphertext, and the
laptop's feed then tells it the sealed file is gone.

**This revives item 1, which was retired as unfixable.** The note above says
`crossing_a_vault_edge` is never consulted and gating it changes nothing. That
was measured at the MINT, one step too late, where the record is already
forgotten -- `sealed_source=None`, 11 of 11. At the CROSSING the record is still
in hand and says `enc=true` outright. So the gate has a site where it can fire,
and the enforceable predicate is visible in the same line: **the destination
parent is a folder the ENGINE minted, not one the user moved the file into.**

Note the predicate needs care: a user dragging a sealed file into a folder they
have just created also gives a provisional parent. Refusing there stalls a
legitimate conversion, which raises an issue and loses nothing; publishing loses
the seal. The asymmetry is what justifies the refusal, and any version of this
must be measured against the pinned suite, not reasoned about.

**What this does NOT change.** The build order stands: the record being
forgotten is still upstream, and a gate is still a belt. What it changes is that
item 1 is no longer dead -- it is a belt with a working buckle, and worth
carrying once 2 lands.

**Item 1 revived, built at the crossing, measured -- and it does NOT fix AF
either. Third attempt, reverted (2026-09-09).** The gate was written exactly as
the line above specifies: at the conversion site, before the trash is queued,
refuse when the entry is encrypted AND the destination parent is provisional (a
negative id, so a folder the engine minted rather than one the user moved the
file into).

    baseline    failures=11 of 40
    with gate   failures=11 of 40   -- the same eleven seeds

**Unlike the mint gate, this one FIRES** -- six refusals across the arm, on both
devices, always on the sealed file into a provisional parent:

    GATE_REFUSED dev=desktop id=File:901 dest=Some(-11)
    GATE_REFUSED dev=laptop  id=File:901 dest=Some(-19)

So the conversion trash is real, and stopping it is not sufficient. The leak
survives it, which means the plaintext upload does not depend on the sealed
record being destroyed first.

**And measuring at the mint found the thing this whole section had backwards.**
The mint gate was shelved on the reading that the inode link was empty --
`sealed_source=None`, taken to mean the index had nothing for inode 1001.
Instrumented with the link and the entry printed SEPARATELY, at the moment the
leaking copy is minted:

    MINT dev=desktop path="ring-2 (conflicted copy ...)/sealed.txt"
         inode=1001
         index_link=Some(File:901)              <- the link IS there
         entry=None                             <- the ENTRY is gone
         rows=[(Some("file"), Some(901), "1fe4a751")]

`entity_for_file_id` answers correctly and names 901. What does not exist is the
ENTRY it names. The earlier gate was built to read *the entries* -- the half
that has been destroyed -- which is precisely why it measured as fail-open 11 of
11. The half that SURVIVES is the index link, and it survives because of the B6
COALESCE fix.

**So `forget_entry` leaves a dangling inode link behind, and that dangling link
is evidence.** A row in `local_index` naming an entity that no longer exists
means: these bytes on this disk belonged to a record the engine destroyed while
they were still here. Nothing else in the store says that.

**The invariant that follows is wider than the vault**, which is what makes it
worth having: *bytes whose record the engine destroyed while they were still on
the disk are not new content, and must not be uploaded as new content.* On the
AF path the bytes are a sealed file and the upload is a plaintext publication;
on any other path it is a file silently re-created as a stranger.

**And the obvious tidy-up is actively wrong.** Making `forget_entry` clear the
index link would leave the store self-consistent and destroy the only surviving
evidence that these bytes were ever tracked -- turning AF from detectable into
silent. The dangling link must be kept and read, not cleaned up.

This does NOT displace the ordering already written down -- the record should
not be forgotten in the first place, so the planner (item 2) and the record
following its directory (item 3) remain the fix, with directory identity (4) as
the close. What changes is that the belt is no longer a measured dead end: it
has a signal that outlives the defect, and it is worth building once the
upstream work lands.

**The belt was then BUILT and measured, and it stops the leak (2026-09-09).**
At the file mint, refuse when the inode's index link names an entity that no
longer exists. On the ring arm the failure count did not move -- 11 of 40 either
way -- and the first reading of that was WRONG, because the arm records only
THAT a seed failed. Asked what the failure actually says:

    belt off:  seed 74000: the plaintext of a file the user sealed reached
               the server
    belt on:   desktop is holding files no entry claims, so nothing will ever
               scan, send, move or remove them:
               ["ring-1/ring-2.txt",
                "ring-2 (conflicted copy ...)/sealed.txt"]

Different defect. The vault leak is GONE -- traced at the upload site, the only
uploads in the run with the belt on are the three correct seeding uploads, and
no plaintext copy is ever sent. What is left is the refused bytes sitting on the
disk unclaimed, which is the belt's own `continue` with nothing after it, and
the orphan assertion catching it loudly.

It also catches the other half of the inversion, unprompted:

    BELT_REFUSED desktop "ring-2 (conflicted copy ...)/sealed.txt" was=File:901
    BELT_REFUSED desktop "ring-1/ring-2.txt"                      was=File:902

**So the belt is the first intervention of the three that works, and what it
needs is a disposition, not a rethink.** Refusing forever is not a fix; the
refused bytes need parking with an issue raised, the way the engine already
handles content it cannot place. The upstream work (items 2 and 3) remains the
real fix -- the record should never have been forgotten -- but the belt is the
thing that turns a silent publication into something a person can see, and that
is worth having on a route that publishes vault contents.

**A measurement lesson worth more than the result.** Counting failures without
their REASON hid a working fix behind an unchanged number, and this campaign has
now been bitten by the same shape twice in one day -- a `head -12` that cut the
interesting lines off a trace, and a probe that printed one variable under
another's name. Any arm used to judge a fix has to report what the failure SAYS,
not just how many there were.

**How precise the belt's predicate is, measured against the pinned suite.**
Run log-only over the 201 scenarios, the predicate -- an index row naming an
entity that no longer exists -- fires **8 times on healthy, passing code**, on
three paths: `report.txt`, a conflict copy of it, and `X/Y/note.txt`. Turned
into a hard refusal it breaks **7 of 201**:

    a_crash_window_that_left_two_copies_of_our_own_bytes_settles_on_one
    a_finisher_waits_for_its_own_park_before_the_park_has_landed
    a_land_beside_that_died_before_its_rename_heals_on_the_next_run
    a_park_mid_replacement_survives_its_create_being_refused_once
    a_park_whose_destination_is_trashed_keeps_its_finisher_and_its_files
    an_upload_of_bytes_the_server_already_has_adopts_rather_than_duplicates
    an_upload_onto_a_name_the_server_gave_away_lands_beside_it

Every one is a RECOVERY scenario -- a crash window, a park, a land-beside, an
adoption. Those are precisely the cases where the engine forgets a record on
purpose and re-adopts the bytes afterwards, and they are indistinguishable from
AF on the dangling link alone. So the cheap predicate is dead as a refusal.

**What that measurement buys is the shape of the real one.** The belt cannot
tell a sealed record from an ordinary one because the entry it would have to ask
is the thing that was destroyed. So `forget_entry` has to leave a TOMBSTONE
saying what the record was -- at minimum whether it was encrypted. That is the
counterpart to the `trash_local` comment that ends *"and the record has to go
with that conclusion"*: the record may go, but not without saying what it was.

The prediction this makes, and the way to test it: all seven pinned failures
above are PLAINTEXT paths, and AF's forgotten record is `enc=true`. A belt gated
on *the forgotten record was encrypted* should therefore fire on AF and on none
of the seven. That is one measurement away once the tombstone exists, and it
should be taken before the belt is trusted, not after.

**Build order corrected by measurement (2026-09-10): item 2 is not the
first-order fix, and item 3 is not a belt.** The order above says build the
planner's two-claimants rule first and explicitly *"Do NOT build 3 before 2: a
belt on a route the planner should never send anyone down is the same
inert-guard problem"*. Measured, that reasoning does not hold.

Probing the planner directly -- every round, every item with a destination slot,
reported when two DISTINCT entities claim one slot -- seed 74000 shows the
predicted collision exactly as specified:

    TWO_CLAIMANTS dev=desktop slot=(None, "ring-2") claimants=[
        (Folder:502, ApplyLocalMove  { to: ring-2 }),   <- the vault
        (Folder:504, ApplyRemoteMove { to: ring-2 })]

But across the 11 red seeds it fires on **9, not 11**. Seeds **74019 and 74037
leak with no same-round slot collision anywhere in the run** -- and both still
move a VAULT directory aside:

    74000  TWO_CLAIMANTS -> ASIDE ring-2 (desktop)
    74019  no collision  -> ASIDE ring-1 (desktop)
    74037  no collision  -> ASIDE ring-2 (laptop)

So the aside is the choke point and the planner race is one of its causes, not
the cause. A planner rule would close nine seeds and leave two, which is the
worst possible outcome for a defect of this kind: the arm goes from 11 red to 2
red and looks nearly fixed while still publishing vault contents.

**Why the planner cannot be the whole answer, and it is structural.** `run_round`
resolves one round; `dependency_graph` builds `occupant` from items moving OUT of
a slot in THAT round. A local move that landed in an earlier round and a remote
move arriving in a later one never appear in the same `items` vector, so a
cross-round race is invisible to the planner by construction. (Traced: the
collision-free asides are real and on the vault. That a cross-round race is the
mechanism behind them is INFERENCE from the planner's shape, not yet traced --
it wants the round number logged beside the aside before anyone relies on it.)

**Revised order.** Item 3 -- the aside carrying the owner's record -- is the
first-order fix, not a belt. Item 2 remains worth doing: a lost move race is a
real defect and `MoveRaceServerWon` is the honest verdict for folders. But it is
a correctness fix in its own right, not AF's fix, and it must not be measured as
one.

**Item 3 BUILT and measured (2026-09-10): 11 red seeds become 5.** In
`make_room`'s directory arm, the folder record whose resolved local path IS the
displaced directory is found, the directory goes to `.jd-swap-dir-{id}` rather
than a user-facing conflict copy, and the record's `local_name` follows it -- so
the chain from the files inside back to the root keeps resolving.

    baseline        failures=11 of 40
    record follows  failures=5  of 40   (74014, 74018, 74019, 74023, 74033)

All five remaining failures are still the genuine leak, checked by REASON and
not by count. Six seeds closed, and 74037 is among them -- one of the two with
no planner collision at all, which is direct evidence that item 2 could not have
closed them and item 3 can.

**AF IS NOT ONE DEFECT, and the claim written above that "the aside is the choke
point all 11 seeds pass through" was an overreach from three seeds. Corrected
here by measuring all of them.** With item 3 on, the five survivors split:

    74019                        OWNED_ASIDE fires (ring-1 -> .jd-swap-dir-502)
                                 and the seed STILL leaks
    74014, 74018, 74023, 74033   NO directory aside of any kind -- only file
                                 asides -- and they leak anyway

So four of the eleven never displace a directory at all. There is a **second
leak route that does not pass through `make_room`'s directory arm**, and nothing
built on the aside can reach it. That route is unidentified and is the next
thing to trace; the AD/AE/AF family's shared root (a directory carries no
identity) is a reasonable suspect but has NOT been shown to be this.

**What that means for the plan.** Item 3 is worth having on its own -- it halves
a vault disclosure and it is the honest behaviour regardless -- but it must not
be described as AF's fix, and the ring arm must not be read as nearly-green at
5 of 40. Directory identity (item 4) should now be specified against BOTH routes,
not just the aside, and the second route needs tracing before it is.

**ROUTE 2 TRACED (2026-09-10): `create_remote_folder` steps aside on the
SERVER.** The four seeds that leak with no directory aside do not go through
`make_room` at all. Tagging all eight conflict-name minting sites, exactly one
fires on 74014:

    CONFLICT_NAME dev=desktop who=create_remote_folder placement="ring-3" attempt=1

The ordered chain, with item 3 on:

    CONFLICT_NAME desktop create_remote_folder "ring-3" attempt=1
    MINT   desktop "ring-3 (conflicted copy ...)/sealed.txt"
           inode=1001 link=Some(File:901) entry=None
    UPLOAD desktop File:-7 enc=false -> parent 511 "sealed.txt"   <- published
    UPLOAD desktop File:-8 enc=true  -> parent 502 "ring-2.txt"   <- the inversion

The conflict-copy directory is minted on the SERVER by the create, not by any
local move-aside. **The guard already there is the right shape and too narrow:**

    Err(e) if e.name_taken()
        && held_by_a_rename_this_device_owes(env, &wanted, placement.parent)? =>
        Retry("the name is spoken for by something this device is renaming")

It waits when THIS device owes the rename. In a ring trade the holder is a name
the OTHER device renamed, arriving by feed, so the guard is silent and the
device steps aside into a plain folder.

**Both routes end on the same line**, which is the reason to work at the mint
rather than add a second site guard:

    MINT ... inode=1001 link=Some(File:901) entry=None

Route 1 reaches it through `make_room`'s aside, route 2 through
`create_remote_folder`'s step-aside. The record is destroyed and the bytes are
adopted as new plaintext content either way.

**The objection that decides whether a belt there can work at all**
(public-html-0e, 2026-09-10, read from the tree): a user's designed drag-out of
an UNEDITED sealed file may be byte-for-byte identical to AF at that mint --
`pass.rs` ~1024's own comment says the conversion works by trashing the remote,
leaving the local bytes, and letting the next scan adopt them at the new path.
Same inode, same hash, same dangling link, same tombstone. If that holds, NO
predicate on what the record was can separate them, and the separating fact has
to be whether the FILE's directory changed (a user drag-out moves the file
between directories; AF re-attributes the directory around a file that never
moved) -- which is item 4, directory identity.

Evidence so far cuts against strict byte-identity, and it is worth stating
because it was nearly assumed away: pinned drag-outs DO exist
(`a_file_dragged_into_a_vault_and_back_out_again_still_moves`,
`a_vault_folder_dragged_out_is_not_published_in_the_clear`,
`a_swap_across_a_vault_edge_converts_both_ways`), and the plain dangling-link
belt broke 7 pinned scenarios with NONE of those three among them. Something
already tells them apart. What, is the open question, and it is being measured
rather than argued.

**Two corrections to the tombstone taken from the same review, both right:**
- The tombstone belongs in `delete_entry` (written from the row being removed),
  not `forget_entry`. And `forget_entry` CLEARS `local_index`, so a belt keyed on
  a dangling link is structurally blind on the `forget_folder_the_server_confirms`
  route -- 4 of the 16 sealed-record flips -- however well it works elsewhere.
- The predicate is *last held sealed AND about to be minted plain*, not *the
  forgotten record was encrypted*. A crash-window or park re-adopted INSIDE a
  vault is `enc=true` forgotten-and-re-adopted, and the bare form stalls it. The
  vault arms generate exactly that.

## Defect AG -- dragging one file out of a vault swaps the vault's name onto a plain folder

**Severity: after an ordinary drag, the folder called `Private` protects
nothing, and everything the user saves into it afterwards is published in the
clear. One device, one drag, no trades, no faults, no second actor.** Found
2026-09-10 while building public-html-0e's C1 control.

**The reproduction.** One keyed device. A vault `Private` on the server holding
one sealed file, downloaded. The user makes a folder `Plain` and drags the file
into it. Settled, stable over six further rounds:

    disk    ["Plain", "Plain/y.txt", "Private"]
    server  ["Plain", "Plain/enc-4c1a9da0...", "Private"]
    folders (501, "Plain",   encrypted=true,  parent=None)
            (502, "Private", encrypted=false, parent=None)

**501 is the vault, and it is now called `Plain`. 502 is a brand new folder with
no protection, and it is now called `Private`.** The names have exchanged
meanings. The user's own vault answers to the name of the plain folder they just
made, and the name they trust protects nothing.

**Mechanism, traced.** The file never moved:

    XING id=Folder:501 enc=true  local=Moved { to: {parent:None,name:"Plain"} }
                                 agreed={parent:None,name:"Private"}  verdict=None
    XING id=File:901   enc=true  local=None

Dragging the file out left `Private` empty and `Plain` holding 501's only known
child. A directory carries no identity, so the folder scan paired 501 with
whichever directory held its child and reported the VAULT as renamed to `Plain`.
`crossing_a_vault_edge` is then asked about a folder whose parent has not
changed -- root to root -- and returns `None` at its first test, so no crossing
is seen and no conversion happens. The engine renames the vault on the server
and mints a new plain folder for the empty directory left behind, which takes
the name `Private`.

**The trigger is the vault losing its LAST KNOWN CHILD -- not provenance.**
`detect_folder_moves` guards folder pairing with `holds_nothing_known`, and its
own comment says why: *without it, one file moved out of a folder reads as the
folder having moved*. That guard protects a folder only while some tracked
content still stands under it. A vault holding ONE file has none left the moment
that file leaves, so its standing directory reads as a rebuilt shell and the
record is paired with whichever directory now holds its child.

Measured, not reasoned: the same drag with the sealed file WRITTEN locally
rather than downloaded fails identically.

    before  [(501, "Private", true, None)]
    after   [(501, "Plain", true, None), (502, "Private", false, None)]

So provenance -- which decides the AF belt's reach -- has nothing to do with AG.
The first reading here blamed the download because C1 happened to use one.

**Why the pins never caught it.** `a_swap_across_a_vault_edge_converts_both_ways`
is green because its vault never empties -- a file swaps in as the other swaps
out. `a_file_dragged_into_a_vault_and_back_out_again_still_moves` is a keyless
device on the claimant path. C1 is the first single-file vault drag-out in the
suite.

**Why this matters beyond itself.** It is the AC/AD/AE/AF root cause -- *a
directory carries no identity* -- reproduced with ONE device, no name trade, no
fault injection and no concurrency. Everything else in the family needed two
devices racing. This needs a user dragging a file.

**The obvious near-term fix is wrong, and the reason is worth keeping**
(public-html-0e). The tempting rule is *a standing directory at an encrypted
folder's agreed path keeps its identity; contents decide only when the directory
is gone*. That is right for AG and wrong for its mirror: the user renames the
vault `Private` -> `Plain` and makes a NEW empty `Private`. Same disk, same
server, opposite intent. Under the standing-directory rule 501 stays at
`Private` (the new empty directory), `Plain` is adopted as a new plain folder
holding the sealed inode, the file reads as dragged OUT, `Convert` fires, and
the sealed file is published in the clear -- from a rename that never asked for
disclosure. Under today's rule, AG inverts the protection of everything saved
afterwards. **Neither reading keeps the vault sealed in both worlds**, which is
AD's contents-exchange counterfeit reachable on one device with one drag.

So the only safe thing to land before directory identity is a HOLD: an encrypted
folder whose pairing rests on contents alone -- directory standing empty at its
agreed path, its known child under another directory -- parks folder and file
`Unsyncable` with one issue naming both readings (*"Private was emptied and
Plain holds its file: was Private renamed, or was the file moved out?"*). No
rename pushed, no conversion, no adoption of either directory. The
`empty_and_encrypted` ambiguous arm already says exactly this for its own case.
A hold costs a stall with a sentence; both alternatives cost a vault.

**And it makes directory identity urgent rather than the family's close.** With
the directory's `file_id` on the folder record, AG's `Private` directory keeps
501's id, so 501 is `Private`, the file moved out, `Convert` -- correct. The
mirror: the directory now named `Plain` carries 501's id, so 501 was renamed,
the new `Private` is adopted plain, nothing published -- also correct. Every
hold above becomes a decision.

**Verified on content, not just names.** The name proves the metadata blob
opened; only the hash proves the bytes did. `Plain/y.txt` on the disk hashes to
exactly the plaintext written:

    disk y.txt = 102c117196d66d419cf9e7c77c75edf2982809ae6429e312cc891c3521a3ae91
    written    = 102c117196d66d419cf9e7c77c75edf2982809ae6429e312cc891c3521a3ae91

So the user holds their readable file locally while the server holds only the
ciphertext, under the vault's new name.

**Still open.** Whether the same swap happens with a locally-written sealed file
(provenance may not matter here at all, unlike AF); whether a second device
inverts the same way; and what a real server does with the rename, since
`drive_move_logic.php` converts a file in place across the boundary and refuses
an out-of-vault move without a vault window, which the mock does not model.

**ROUTE 3 TRACED: the pre-trash rescue carries the sealed file to the root.**
Predicted by public-html-0e from `is_on_the_server`, confirmed on both seeds
that mint at the sync root:

    RESCUE dev=laptop file="ring-3/sealed.txt" inode=1001
           on_server=false agreed_here_has=false link=None

Before a folder is trashed locally, the rescue saves work nobody has uploaded.
It decides that with `agreed_here` -- a map the caller builds from the folder's
LOCAL CHAIN -- and then the index link. In AF's state the chain is exactly what
does not resolve, so `agreed_here` misses the file; and on the device that WROTE
the file there is no link. Both answers empty means "unsent work", so the sealed
file is carried out to the sync root and uploaded in the clear.

Its fix is the family's sentence again: the rescue must ask the RECORDS by
fingerprint over every entry, not a map scoped to a chain that is broken in
precisely the state the rescue runs in, and a sealed record's bytes are never
carried anywhere while the inode is on the disk.

**ALL 11 SEEDS LABELLED BY ROUTE.** The routes OVERLAP; they do not partition.

    74000  aside            desktop link=Some
    74014  create           desktop link=Some
    74017  aside            laptop  link=None
    74018  create + rescue  laptop  link=None
    74019  aside  + rescue  laptop  link=None
    74023  create           desktop link=Some
    74026  aside            desktop link=Some
    74031  aside            desktop link=Some
    74033  create           laptop  link=None
    74035  aside            desktop link=Some
    74037  aside            laptop  link=None

aside 7, create 4, rescue 2. **Report closed SEEDS, never closed routes** -- with
two seeds firing two routes, a per-route count overstates any fix. And the honest
line for item 3 is *6 of 11 closed; 74017 and 74037 are aside seeds it does NOT
close, both laptop/link=None* -- so "item 3 fixes route 1" is already too strong.
Unresolved: which route fires FIRST on those two. The label records which routes
fired, not their order, and that is what would explain them.

**THE ORACLE NEEDS TWO FORMS, and the arm's header has to say which it uses.**
Convergence asks about bytes and agreement, so a folder that stops being the
vault passes it -- custody blindness hiding a PROTECTION LEVEL rather than an
identity, which is new.

- Where the workload never renames a folder slot: for each seeded vault, the
  folder with that ID still wears that name and is encrypted, and no plain
  folder wears a name a vault was seeded with. There the name IS the evidence,
  and this is exactly AG's shape.
- **In the ring arm neither name clause is decidable**, and the first draft of
  this oracle got that wrong twice. After the user trades ring-1 (the vault)
  with ring-2, a plain folder legitimately wears `ring-1`; with two devices
  trading at once the vault's right final name is race-dependent. What IS exact,
  because that workload never creates or removes a folder: the server holds
  exactly the three seeded ring ids, all live, the vault's still encrypted and
  the other two still plain, and no root folder beyond them. That catches every
  re-mint whatever the trades did to the names -- AF's blessed identity loss,
  AG's minted namesake, and route 2's step-aside.

**Tombstone belt, measured: it closes ZERO of the 40 ring seeds.**

    A baseline        failures=11 of 40
    B tombstone only  failures=11 of 40

Provenance blindness was its CEILING, not its result. The argument above says it
could reach at most the six link-bearing seeds; measured, it reaches none.

**And the reason is in the code, not a mystery** (public-html-0e): this build
parked the mint `PendingKey`, which is not a stall on a device that HOLDS the
key. The mint's own comment says so -- the file waits and `apply_naming`
releases it by itself the moment a key arrives -- and `no_key_for` parks
encrypted entries only *while there is no key*. The desktop holds the vault key
in every link-bearing seed, so the provisional was released at the top of the
next pass and uploaded as planned. The belt fired and was undone one pass later.
The earlier dangling-link belt skipped the mint outright, which is why that one
stopped the leak (and left the bytes unclaimed, and broke 7 pins).

With the right stall shape the belt returns to exactly its known ceiling: blind
on five seeds, breaks seven pins. It stays retired on the provenance argument
alone; the disposition detail changes nothing about that.

**ITEM 3 IS INERT UNDER FAULTS, and every number above describes the GENTLE arm
(2026-09-10).** Measured on a probe-free copy built fresh from HEAD with only
item 3 in it, run uncontended:

    clean arm    (no faults)                 11 -> 5  of 40
    hostile arm  (chaos + kills + platforms) 40 -> 40 of 60

So `11 -> 5` is a clean-arm-only result. Where the faults are -- the conditions
that found AD and B4 in the first place -- the aside fix closes nothing.

*(Caveat on that run: the per-seed REASONS were truncated by a `head` in the
capture, so only 19 of each 40 were seen; all 19 in both halves are the sealed
leak. The 40/40 totals are sound, the claim that all 40 fail the same way is
not. Third truncation of the night -- see the measurement lesson above -- and
the rule now is that no measurement output passes through `head`.)*

**Two consequences, and the second is the uncomfortable one.**

1. On landing item 3: a real improvement on the clean arm, no regression under
   kills, inert where it matters most. Worth having, never to be described as
   AF's fix.
2. **The hostile arm leaks at 67% against the clean arm's 27.5%, so the arm this
   campaign has been measuring on is the easy one.** Every figure above -- 11 of
   40, six closed, and the whole route table -- was taken on clean seeds. The
   route distribution under faults is simply not known, and "aside 7 / create 4
   / rescue 2" should not be read as the shape of the defect until the hostile
   arm is labelled the same way.

**The kill condition on item 3 is NOT satisfied by this run.** The worry
public-html-0e raised is specific: `.jd-swap-dir-` carries `SWAP_PREFIX`, so a
death between the rename and the record update leaves the stranded-park put-back
to return the directory to its AGREED name -- which is the contested one, and
that is B4's mechanism. A baseline already saturated at 40 of 60 has nowhere for
such a regression to show. Random kills approximate the window; they do not test
it. What is needed is a targeted test: force the aside, kill between the rename
and the `put_entry`, restart, and assert the directory is not standing at the
contested agreed name and no ring id was re-minted.

**B9 -- a killing arm where EVERY seed fails reports nothing at all.**
Found 2026-09-10 while trying to run the identity oracle over the hostile ring
arm. `sweep_core` ends with

    assert!(!kills || kills_made > 0,
            "SWEEP {label}: the killing arm never killed anything");

and `kills_made` accumulates only from seeds that PASS -- a failing seed's kill
count is discarded with its panic. So an arm in which every seed fails has
`kills_made == 0`, and this assertion fires BEFORE the sweep prints its failure
list. The run reports `FAILED` with no seeds named, no `SWEEP` line, and no
count. Worse in the arms that suppress the panic hook to keep per-seed output
quiet: there the message itself is swallowed and the arm is simply silent for
ten minutes and then red.

This is the same class as the guard already pinned by
`a_sweep_with_a_failing_seed_fails_the_test` -- a reporting path that is silent
exactly when the news is worst. The fix is to count kills from every seed
attempted rather than every seed that passed, and to make the check a reported
warning rather than an assertion that pre-empts the failure list.

It matters beyond tidiness: it is how the hostile arm's identity result nearly
went unmeasured tonight.

**THE HOSTILE ARM LEAKS WITHOUT RE-MINTING ANYTHING, which says where its fix
lives (2026-09-10).** 30 hostile ring seeds, sealed oracle and identity oracle
both armed (counted from `af3/split6.log`):

    22 of 30 failed (22 kills)
    22  the plaintext of a file the user sealed reached the server
     0  identity failures

Every seeded ring id survives with the protection it was seeded with. So under
faults the vault is published **with no folder re-mint at all** -- which is not
the reassuring reading. It points the hostile arm's leak at route 3 (the
pre-trash rescue) and the crossing convert, and away from the aside (route 1)
and the create step-aside (route 2), both of which work by re-minting.

**That explains item 3's inertness under kills.** Item 3 is a re-mint fix -- it
makes the record follow a directory the engine moves aside -- and the hostile
arm does not move directories aside. Same for the adoption guard, when it is
built. The fix that reaches the hostile arm is the rescue asking the RECORDS by
fingerprint rather than a chain-scoped map plus a provenance-decided link.

**The oracle was wrong twice before it was right, and both were FALSE POSITIVES
of mine.** Recorded because the corrections are the useful part:

- First run, 8 "identity failures" naming folder 501 `Private` -- the VAULT ROOT
  that `sweep_world` seeds for every vault mode. My allowed set held the three
  rings only.
- Second run, 6 more naming `Contested Folder`, `Sub 9`, `Sub 26` -- folders the
  WORKLOAD creates. The premise that this workload never creates a folder is
  true of the rings and false of the ordinary actions running beside them, so
  the *nothing beyond the seeded ids* clause was unsound from the start and is
  gone.

What is exact, and all that is left, is that the seeded ids survive with their
seeded protection. The oracle now takes those ids from `sweep_world` rather than
a retyped list, so it cannot drift a third time.

**Non-vacuity is NOT established, and worse: the ring form CANNOT catch AG --
the defect that inspired it.** Read off AG's end state against the oracle's own
predicate:

    AG leaves   (501, "Plain",   encrypted=true)   <- the vault, renamed
                (502, "Private", encrypted=false)  <- new, unprotected

The ring form asks only that each seeded id still exists with its seeded
protection. 501 does. **It passes.** The clause that catches AG is the NAME
clause, which is precisely the one that is undecidable where names are traded;
and the *nothing beyond the seeded ids* clause that would have caught 502 had to
go, because workloads legitimately create folders.

So the ring arm's identity oracle detects exactly two things: a seeded id
vanishing, and a seeded id's protection flipping. A name swap of AG's shape goes
straight through it. Its zero above means "no ring folder was destroyed or had
its protection changed" and nothing more; it is NOT evidence that identity held.

**What IS decidable in a name-trading arm** is the assertion 0e wrote for the
K1/K2 list: *the sealed inode sits under the directory the store resolves to the
vault's ID*. That holds whatever the trades did to the names, and it is the
check that would see AG. It should be added before the identity result from this
arm is quoted again.

**The NAME form does have a true positive, taken as its positive control.** Run
against AG's reproduction it goes red naming the vault outright:

    c1b: assertion `left == right` failed:
         the vault kept neither its name nor its protection
           left:  Some(("Plain", true))
           right: Some(("Private", true))

So the two forms have different powers and the difference must be stated
wherever either is used: the NAME form catches AG and is admissible only in arms
that never rename a folder slot; the RING form catches a seeded id vanishing or
its protection flipping, has no demonstrated true positive at all, and provably
cannot see AG.

**ROUTE 4, and it is the dominant shape under faults: a folder NAME TRADE read
as a file moved across the vault edge (2026-09-10).** Traced on seed 75100,
counted over all 22 from `af2/hostile_logs/`:

    XING   pc File:901 enc=true
           local=Moved { to: { parent: Some(504), name: "sealed.txt" } }
           agreed={ parent: Some(502), name: "sealed.txt" }
           verdict=Some(Convert)
    MINT   pc "ring-3/sealed.txt" inode=1001 link=Some(901) entry=None
    UPLOAD pc File:-7 enc=false -> parent 504 "sealed.txt"

The destination parent is **504 -- a real, existing plain ring**, not a
provisional conflict copy. The devices trade the ring NAMES, so the sealed file's
directory ends up wearing a different name, and the scan reports the FILE as
having moved from the vault ring into a plain one. `crossing_a_vault_edge` then
answers `Convert` on a move the user never made, and the conversion publishes.

No aside, no re-mint, no conflict directory -- which is exactly why the identity
oracle returned zero on this arm, and why item 3 (a re-mint fix) is inert here.

**Accounting over the 22 failing hostile seeds:**

    Convert verdict with a real-folder destination   10
    aside (route 1)                                   1
    create step-aside (route 2)                       1
    rescue (route 3)                                  2
    union accounted for                              13
    STILL UNEXPLAINED                                 9

The nine are 75104, 75108, 75111, 75114, 75116, 75120, 75122, 75126, 75128.
None of the four probes fires on them and they leak anyway. That is the next
trace, and nothing should be built for the hostile arm until it is done: on this
evidence the hostile arm is at least two shapes, and possibly three.

**Two counting mistakes on the way to this, both mine, both caught by checking
the probes were alive rather than trusting a zero.** The first tally used
`CONVERT_TRASH` as route 2's marker when route 2 is `CONFLICT_NAME` from
`create_remote_folder`, and reported "19 of 22 through an unknown route". A
probe census on one seed showed 13 ASIDE, 24 MINT, 329 UPLOAD and 1356 XING
lines, so the zeros could only have been bad greps. The rule that caught it: a
zero from a probe is only evidence once that probe has been shown to fire.

**B10 -- the sweep is not trace-reproducible: the same seed runs a different
action sequence each time.** Found 2026-09-10 while chasing the nine unexplained
hostile seeds. Seed 75104, same binary, two consecutive runs:

    run 1  120 uploads  22 mints  aside=0 create=0 rescue=0 convert=0
    run 2  120 uploads  22 mints  aside=0 create=0 rescue=0 convert=0
    but:   "Report 13.docx" content 2bcdab30 in one run, fb04850e in the other

Totals and route markers match; the BYTES do not. Workload bodies are
`format!("body {step} {}", device.name)`, wholly determined by step and device,
so a different body at the same path means a different STEP or DEVICE wrote it.
The action sequence therefore differs between processes for one seed.

**What this does and does not cost.** Outcome-level stability has held in
practice all campaign -- the same eleven clean seeds and the same twenty-two
hostile seeds reproduce run after run -- and route attribution reproduced
exactly on the seed tested. What is NOT reproducible is the trace: a failing
seed re-run for diagnosis executes a different sequence, so a line number, an
entity id, or a step index taken from one run may not exist in the next. Every
per-seed trace in this document should be read as "a run of that seed", not
"the run".

Cause not established. A shared RNG consumed in a device order that is not fixed
is the obvious candidate and has NOT been confirmed.

**THE NINE, ANSWERED AT THE LEVEL OF *WHERE*: the sealed bytes are adopted into
ORDINARY WORKLOAD FILES' identities.** The oracle now names the path it found
the plaintext at (public-html-0e's probe -- one line, reads the END STATE, so it
is immune to B10's trace nondeterminism, and strictly better than the
upload-site hash it replaced):

    75104  "Contested Folder/contested (conflicted copy ... from pc).txt"
    75108  "contested.txt"                      <- the sync root
    75111  "ring-2/ring-3.txt"

Not a ring folder, not a conflict-copy directory, not the vault. The vault's
content ends up wearing ANOTHER FILE'S NAME -- `contested.txt`, a conflict copy
of it, `ring-3.txt` standing in `ring-2` -- and goes up in the clear under that
identity.

That is why none of the four publishing-site probes fires on these seeds: there
is no mint of a sealed record and no crossing consulted. Some other file's
record simply comes to hold the sealed bytes, and uploading them is then
ordinary correct behaviour for that record. It is the contents-exchange
counterfeit of Defect AD, wider than the rings: any workload file whose path the
sealed bytes come to occupy can inherit them.

**Still open: WHICH VERB puts the sealed bytes under that other identity.** The
end state names the entity; its history names the verb, and that history has NOT
been read. Do that before building anything for these seeds.

**Also fixed on the way: the oracle's failure message.** It said only "the
plaintext of a file the user sealed reached the server", which sent a session
hunting through upload logs for a fact the end state already held. It now names
the paths.

## Defect AH -- the scan gives one record another record's bytes

**This is the verb behind the nine, traced 2026-09-10.** Not a move, not a mint,
not a crossing: the SCAN hands a plaintext record the sealed file's bytes, and
uploading them is then ordinary correct behaviour for that record.

    ADOPT_STRANGER entry=File:905 path="Contested Folder/in-27-pc.txt"
                   took inode=1001 which belongs to File:901
    ADOPT_STRANGER entry=File:901 path="ring-2/sealed.txt"
                   took inode=1003 which belongs to File:905

Inode 1001 is the sealed file. Record 905 -- an ordinary workload file -- adopts
the vault's bytes as an EDIT OF ITSELF, and 901 takes 905's bytes in exchange.
Thirteen such adoptions on seed 75104 alone, and the oracle finds the sealed
plaintext at exactly the path 905 owns.

**Where, and why it is there on purpose.** `scan::pair` rule 1, whose own
comment says it: *"Same path, for every entry ... Checked WITHOUT CONSULTING THE
INODE, because the safe-save dance replaces the inode at a stable path and that
is an edit, not a new file."* That is a good reason and the rule is right for
the case it was written for.

**The guard that exists, and the gap.** The same loop already refuses this for
one class of record:

    if k.held && k.fingerprint.is_none_or(|fp| fp.file_id != obs.fingerprint.file_id) {
        continue;
    }

with a comment describing precisely this hazard -- *"a stranger saved under the
old name became the held file edited: its bytes went up as a version of a file
whose real bytes were waiting in a vault this device cannot open"*. So the
danger was seen, and the guard was scoped to HELD records. A non-held record
gets no inode test at all.

**Why the rings reach it.** The workload trades ring folder names with
`user_rename` on the DISK -- the user's own act, never through `move_local`,
which is why four of the nine seeds show no engine move of any kind. A renamed
directory carries everything inside it, so the sealed file arrives at a path
some other entry owns, and rule 1 pairs them.

**Fix shape.** Extend the existing test past `held`: bytes at an entry's path
whose inode belongs to a DIFFERENT live record are not that entry's edit. The
safe-save case is untouched, because a safe-save leaves no other record owning
the new inode. It is the family sentence from a fourth direction -- the engine
never treats bytes it cannot name as content it owns.

**PREDICTION, written before it is measured (public-html-0e, 2026-09-10): AH's
guard ALONE will not close the nine -- it will move them to route 4.** With rule
1 refused, 905's path holds a stranger's inode, so 905 falls through to the
by-hash rule and finds its own bytes at 901's path: `Moved` INTO the vault. 901
finds its bytes at 905's path: `Moved` OUT -- a `Convert` at the crossing, with
502 left holding no known child. That is route 4's exact line, reached from the
scan instead of from a trade.

    AH guard alone     hostile count unchanged; route-4 CONVERT count
                       rises by roughly nine
    AH guard + hold    those nine become holds

Recorded in advance so the number reads correctly when it arrives, and so the
non-additivity this campaign has been bitten by twice is predicted rather than
discovered.

**The guard is consistent with the doctrine** because the inode is used to
REFUSE a claim, never to make one -- the mirror of
`the_file_here_is_another_entrys`. Its cost, which its pin should state: a
recycled inode inside one scan window (A deleted, its inode reused by a save at
B's path, A's record still live) refuses B's edit, and B's chain is lost as
delete-plus-create. That is the degradation this engine already chooses over
corruption, and the same price rule 4's deletion pays. A tighter and equally
cheap form: refuse only when the OTHER record's own path does not currently hold
its own inode -- in AH both paths hold the other's inode so it fires, and a
plain safe-save beside a live neighbour never trips it.

**MEASURED ON ALL NINE: AH fires on every one, and the wider damage is in the
same numbers.**

    seed   adoptions   of the SEALED inode
    75104     13            2
    75108     13            7
    75111      7            2
    75114     17            0     <- leaks, never adopts the sealed file
    75116     19           12
    75120     12            2
    75122     21           17
    75126     15           10
    75128     14           10

**That second column was wrong, and 75114 is not unexplained (corrected
2026-09-10).** It was produced by grepping for inode **1001** -- the sealed
file's inode ON SEED 75104 -- across every seed. The sealed record has a
different inode on every seed; on 75114 it is 1005. Re-counted from the enriched
probe, which prints `owner_path`, by asking whether either side of the adoption
is the sealed file rather than whether a fixed number appears:

    seed   adoptions   involving the SEALED file   poisoned chains
    75104     13                 6                        7
    75108     13                 8                        4
    75111      7                 4                        3
    75114     17                 4                        5
    75116     19                18                        3
    75120     12                 3                        3
    75122     21                19                        4
    75126     15                12                        3
    75128     14                12                        2

**All nine adopt the sealed file, 3 to 19 times each.** AH accounts for the nine
with nothing left over. On 75114 the line is explicit:

    ADOPT_STRANGER entry=File:903 path="ring-3/ring-3.txt" took inode=1005
                   owner=File:901 owner_path="ring-1/sealed.txt"

A plaintext record takes the sealed file's bytes as its own edit; the reverse
line is there too, 901 taking 903's. The lesson is the one this campaign keeps
paying for: **a measurement that hardcodes a value read off one seed is not a
measurement of the other seeds.**

**The first column is the finding with the wider reach: 7 to 21 adoptions PER
SEED, overwhelmingly between ORDINARY FILES.** On 75114, seventeen ordinary
files took each other's bytes and nothing objected. So the poisoned-journey
shape is not a hypothesis needing a synthetic test -- it is already happening,
at volume, in every hostile seed. The end-state oracles cannot see it because
the server finishes with the right bytes under the right names; only the version
CHAINS are wrong, and nothing asserts on those. The estate's file-slot trades
have been running this green for forty thousand seeds a version.

**Not established:** what 75114 does instead, and the PLAIN-WORLD version -- two ordinary files trading names on disk, where
rule 1 pairs each with the other's bytes as an edit, the server ends with the
right bytes at the right names, and every end-state oracle is GREEN while both
version chains carry the other file's history. That is the poisoned-journey
shape, and the estate's file-slot trades may have been exercising it green all
campaign. AH's pin must assert the VERSION CHAINS, not only the vault.

### The control, and what it names as the verb (measured 2026-09-10)

The nine were counted under faults only, so the count could not say whether the
probe fires everywhere or whether faults are what produce it. Both questions are
now answered, and the second one names the cause.

**Control 1 -- the clean arm, 40 seeds.** `scratch_clean_one` over 74000..74039,
40 steps, two Linux devices, no chaos and no kills: **11 red, and ZERO
adoptions in all forty**. The probe is not firing everywhere, so the hostile
counts are not noise. It also says something about the clean arm that the AH
section did not: **the clean 11 leak by some other verb entirely**, since AH is
completely absent from them.

**Control 2 -- one seed band, one dial at a time.** `scratch_factor_one` runs
one ring workload with CHAOS, KILLS and PLATS set independently, so the hostile
arm stops being three changes at once. Seeds 75100..75129, 30 steps:

    dials                        adoptions   red of 30
    none                              0          4
    chaos                           415         21
    chaos + kills                   402         18
    chaos + kills + platforms       285         22   <- the hostile arm

**Chaos alone reproduces the whole of it.** Kills and platform disagreement add
nothing to the leak rate. Every earlier sentence in this campaign that credits
the hostile arm's severity to kills or to computers disagreeing about names is
crediting the wrong dial.

**Control 3 -- inode recycling is NOT the source.** `chaos` also turns on
`reuse_file_ids`, which hands a deleted file's inode to the next file that wants
one. That would produce ADOPT_STRANGER lines that mean nothing: a record whose
recorded inode was freed and re-handed to an unrelated new file. Chaos with
`NOREUSE=1` gives **400 adoptions against 415 with recycling on** -- unchanged.
Every one of those 400 is a live record's real inode standing at another live
record's path.

**Control 4 -- which chaos ingredient.** Subtractively, with `NOREUSE=1` held:

    dial turned off      adoptions   red of 30
    NOSWAP                    0         13
    NOFOLDER                400         21
    NOLAND                  527         26

**`NOSWAP` takes it to zero.** All 400 adoptions come from
`user_rearranges_names_during_uploads` -- the workload exchanging two filenames
on disk. Turning it off also takes 8 of the 21 red seeds with it; the other 13
leak without a swap and are a separate verb, unmeasured here.

`NOFOLDER` changes nothing. `NOLAND` going UP is seed divergence, not
causation -- each dial changes what the RNG is asked for, so the worlds are no
longer the same worlds. Zero across thirty seeds is categorical and survives
that objection; a number moving by a third does not.

**So the verb behind AH is the name swap, and Defect AI is the same event with
no vault in it.** A swap hands each file the other one is inode at the other one
is path; rule 1 pairs by path; each record reads the other one is bytes as its
own edit. With a sealed file on one side that is the plaintext leak. With two
ordinary files it is two poisoned version chains and every end-state oracle
green -- which is why forty thousand seeds a version never saw it.

**Probe, current form** (scratch only, `jd-core/src/scan.rs` rule 1):

    ADOPT_STRANGER entry=... path=... took inode=N owner=... owner_path=...
                   owner_deleted=B owner_held=B entry_held=B entry_deleted=B

`owner_path` is what makes a line classifiable without guessing; the earlier
`of the SEALED inode` column was derived from a line that did not carry it.

## The chain oracle -- built and RED estate-wide (2026-09-10)

Every oracle this campaign has ever run reads the END STATE: the right bytes
under the right names, both devices agreeing, nothing stranded. A poisoned
version history passes all of them, because the last version is correct. This is
the oracle that reads the JOURNEY.

**What it asserts.** A swap destroys nothing -- both bodies are still on the
disk when it returns, standing at each other is names -- so from that instant the
two contents belong to two DIFFERENT files, permanently. **No single server
entity may hold both sides of one swap in its version history.**

**Why that narrow.** The obvious form -- no chain may hold a stranger is body --
has an innocent reading: a conflict copy legitimately duplicates one body into a
second entity. Holding BOTH SIDES of a swap has none.

**The control it needed first.** Workload bodies were not unique: `saved 12`
could be written twice, and the oracle would read that coincidence as
provenance. `UNIQ=1` stamps every user write with a counter. It changes the
count by a third -- 110 poisoned falls to 95 on the same band, and seed 75100
alone falls from 11 to 4 -- so **every chain number in this section is a UNIQ
number**, and any taken without it is inflated.

**B11 -- the harness demanded back bytes it had never written.**
`user_saves_while_downloads_land` recorded the hash of the body it was ABOUT to
write, then wrote it; with a stamp applied on the way in, the hash it demanded
back did not exist on any disk and every seed failed as data loss. Fixed by
recording what the disk actually holds after the write. Worth keeping in mind
beyond this oracle: the harness asserting on its own intention rather than on
the disk is the same class of mistake as an oracle asserting on the end state
rather than the journey.

**Results.** Two Linux devices, 30 steps, `UNIQ=1`:

    arm                       seeds   swaps   poisoned chains   green
    no faults, no vault        15       0            0          n/a  <- inert
    chaos, NO VAULT            30     210           95          30/30
    chaos, ring vault          15     105           55           7/15

**Every one of the thirty no-vault seeds is GREEN under every oracle the estate
runs today, and twenty-nine of them have poisoned version chains.** This is not
a vault problem and never was. The vault arms merely gave it a way to be seen:
when one of the two swapped files is sealed, the same event that poisons a
history also publishes plaintext, and only then does anything fail.

**The no-fault row is INERT, not clean.** No swaps happen without chaos, so the
oracle has nothing to check there. A green line from that arm is evidence of
nothing, and must never be quoted as a control.

**This is the baseline.** The number to beat is 95 across 30 no-vault seeds; the
hold at the crossing and AH is guard are both expected to move it, and neither
has been measured against it yet.

## AH's guard, built and measured (2026-09-10)

Built behind `AHGUARD` in d2's af2 copy, in `scan::pair` rule 1, in the tighter
form 0e proposed:

    bytes at this entry's path, whose content differs from what it recorded,
    whose inode is not its own, and whose inode belongs to a DIFFERENT LIVE
    RECORD THAT IS NOT AT HOME, are not this entry's edit -- fall through.

"Not at home" is the clause that keeps a recycled inode safe: if the owner's own
path still holds the owner's own inode, the match here is a coincidence rather
than a claim, and rule 1 proceeds. Safe-save is untouched -- it leaves no other
record owning the new inode -- and identical content is never an adoption.

**It needed one more clause than the form above, and finding it took a
regression, not a sweep.** See the next subsection. The clause: **the entry must
already KNOW which inode is its file.** An entry with no fingerprint knows
nothing, and it is not being handed a stranger -- it is the fresh observation,
and the other record's claim on that inode is the stale one. Written out, the
guard that stands:

    bytes at this entry's path, whose content differs from what it recorded,
    where the entry ALREADY KNOWS a different inode is its own, and where the
    inode standing here belongs to a different LIVE record that is NOT AT HOME,
    are not this entry's edit -- fall through.

**Measured, against the baselines above:**

    measurement                          guard off    guard on
    AI's minimal pin                       RED         GREEN
    scenario suite (204 tests)            201 pass    202 pass, 0 new failures
    chain oracle, no vault, 30 seeds       95           68      poisoned chains
    seeds with a poisoned chain            29           27
    those seeds still green today          30/30        30/30
    hostile ring arm, 30 seeds             22           20      red
    ADOPT_STRANGER on the hostile arm      285          190

**AI closes completely, and the way it closes is the prediction.** With the
guard on, the swap produces **no new versions at all** -- the server's version
list is byte-identical before and after. Both records fall to the by-hash rule,
each finds its own bytes at the other's path, and the two Moved deltas go
through naming's `.jd-swap` park. Two renames, no upload, no conflict copy.

**0e's other prediction also holds: the guard alone does not close the vault
leak.** 22 red becomes 19, not 0. The hold at the crossing is still required.

### How the missing clause was found

The first form of the guard REGRESSED THREE EXISTING TESTS. The sweep arms
showed no seed going green to red, which is exactly why a sweep is not the gate:

    a_stranger_saved_later_at_a_held_files_path_is_a_new_file
        the held file's version chain took the stranger's bytes
    a_file_dragged_into_a_vault_with_no_key_here_waits_instead_of_trashing_it
        guest did not converge -- only on the server: ["memo.txt"]
    a_released_file_dragged_back_into_the_vault_is_held_again
        guest did not converge -- only on the server: ["memo-again.txt"]

The first is the one to understand, because it is the guard causing the very
damage it was built to prevent: refusing the pairing in rule 1 leaves the entry
to the search rules below, and one of THOSE adopts the stranger instead. 0e's
prediction assumed the by-hash rule would find the entry's own bytes somewhere
else. When they are nowhere, the fall-through is not safe.

**The refusal was not the problem; the premise was.** Traced with an `AHREFUSE`
probe, the refused entry in that test is a LOCAL-ONLY record (`server_id: -1`)
freshly minted at `Private/Report.docx`, holding inode 1001, which the held
record 901 still names from its recorded path `Report.docx`. The guard read 901
as the owner and the mint as the thief. It is the other way round: 901's
fingerprint is a memory, the mint is what the disk says now. Requiring the
entry to already know its own inode fixes exactly that, and the three tests come
back green.

**The other candidate refusal was measured and is worse.** Answering
`Unchanged` instead of falling through (`AHGUARD=quiet`) breaks FOUR tests and
does not even close AI -- the record keeps its old content, the swap becomes
invisible, and the other file's bytes are read as a creation. Falling through is
right; it was the condition that was wrong.

### The strict variant is not worth its cost

The guard exempts an owner the SERVER has deleted, following rule 1's own
reasoning. On the first form of the guard, 106 of the 107 adoptions that
survived it were exactly those. Refusing them too (`AHGUARD=strict`) took
ADOPT_STRANGER to **zero** -- and moved nothing else:

    AHGUARD=strict     adoptions 0 (from 107), red 19 (unchanged),
                       poisoned chains 61 (unchanged)

(Those three numbers are from the first form, before the known-inode clause; the
comparison between lenient and strict is what they are for, and it does not
depend on it.)

So those 106 were harmless, and buying them costs the version chain of any file
whose safe-save collides with a deleted record's stale inode. **Take the lenient
form.** This is also a warning about the probe as a metric: driving
ADOPT_STRANGER to zero is not the same as fixing anything -- red and poisoned
chains both sat still while it went to nothing.

### The residue was the SAME verb, asked the wrong question -- and it closes

`known` left 68 poisoned chains, and the shapes suggested a second verb. Tracing
one (seed 75124, file 905) showed otherwise:

    SCAN_EDIT entry=File:905 path="Shared/slot-3.dat"
              inode_recorded=Some(1001) inode_here=1005

Rule 1 again -- but no ADOPT_STRANGER, and no refusal, because **no live record
currently names inode 1005**. The guard was asking *"does somebody else own
these bytes?"* when the question is *"are these bytes mine?"* Bytes nobody
claims are still not this entry's bytes.

**Ask it the other way (`AHGUARD=mine`):**

    my file is still on this disk somewhere, so whatever is standing at my
    path is not me.

That is also exactly what separates a swap from the safe-save rule 1 exists
for. **A safe-save leaves the old inode GONE** -- nothing on disk carries it any
more. **A rename-over leaves the entry's own file alive under another name, and
the scan can see it.** No owning record needs to be found.

**And one clause more (`AHGUARD=mine2`).** A record can reach rule 1 with no
fingerprint at all -- its upload finished while the user was already moving it,
which the held guard's own comment describes. The content it last agreed on
answers the same question: if those bytes are still standing somewhere else on
this disk, its file is alive elsewhere.

    let my_file_is_elsewhere = k.fingerprint.is_some_and(|f| {
        f.file_id != obs.fingerprint.file_id
            && observed.iter().any(|o| o.fingerprint.file_id == f.file_id)
    }) || (k.fingerprint.is_none()
        && k.sha256.as_deref().is_some_and(|mine| {
            observed.iter().any(|o| o.sha256 == mine && o.path != k.path)
        }));
    if k.sha256.as_deref() != Some(obs.sha256.as_str()) && my_file_is_elsewhere {
        continue;
    }

**AND IT IS NOT LANDABLE. `mine2` breaks ordinary editors** (public-html-0e,
2026-09-11, six unit probes against `scan::pair`, reproduced here). The claim
this whole form rests on -- *a safe-save leaves the old inode gone* -- is true
of write-temp-then-rename-over and **false of rename-the-original-away-then-
write**, which is Emacs' default and vim's on Unix. That shape is structurally
identical to the first half of a swap: my inode alive under another name.

    probe                                   off        mine2            mine4
    p1 backup-by-rename save (Emacs)      Edited    Moved to the ~     Edited
    p2 hardlinked twin, safe-save one     Edited    DELETED + create   Edited
    p3 Windows file_id 0 (handle failed)  Edited    DELETED + create   Edited
    p4 no-fingerprint edit, loose copy    Edited    Moved to the copy  Edited
    p5 no-fingerprint edit, synced copy   Edited    DELETED + create   Edited
    p6 the name swap (Defect AI)          Edited    Moved  (closed)    Moved

Every Emacs save of a synced file becomes move-plus-create, and on the second
save the working record is DELETED. A hardlinked twin and a Windows file whose
handle could not be opened are deleted outright -- `real.rs` records `file_id`
0 there, so 0 pairs with 0 across unrelated files, and 0 must be excluded
whatever form lands.

**The numbers below therefore measure a guard that cannot ship.** They are kept
because they bound what is available: zero is reachable, and this is the price.

**Measured. The chain oracle goes to zero.**

    guard        poisoned chains   seeds with one   green   new test failures
    off                95               29 of 30    30/30        --
    known              68               27 of 30    30/30         0
    mine               20               16 of 30    30/30         0
    mine2               0                0 of 30    30/30         0

**Confirmed on 100 seeds never used to build it** (75200..75299, no vault):

    guard off    700 swaps    324 poisoned chains in 94 of 100 seeds   100/100 green
    mine2        700 swaps      0 poisoned chains in  0 of 100 seeds   100/100 green

Not vacuous: the same 700 swaps are recorded and 768 entities carry versions in
the band above; the oracle has the same work to do and finds nothing wrong.

**The vault leak is untouched, as predicted.** The hostile ring arm goes 22 red
to 19. The hold at the crossing is still the fix for that, and this guard does
not stand in for it.

### Where it actually stands: safety and completeness trade off

Two more forms were built after the probes. Neither is both safe and complete.

**`mine3` (0e).** `mine2` AND *the thing standing at my path is something the
store already knows* -- another record's inode (nonzero) or another record's
agreed bytes. An editor writing a fresh file leaves a never-seen inode with
never-seen bytes, so p1 to p5 read as edits again.

**`mine4`.** `mine2` AND (*here is known* OR *my own file is now standing on a
path another record owns, and that record is not at home*). The second half
comes from the traces: in 106 of 109 cases `mine3` declines, the entry's file
has landed on another record's path. An Emacs backup never does that -- it
lands on `notes.txt~`, which nothing is tracking. "And that record is not at
home" is what keeps the hardlinked twin an edit: the twin's own inode is still
at the twin's own path, so nobody was displaced.

**All three pass the six probes except `mine2`, and none regresses anything:**

    form     six probes   scenario suite      100 unseen seeds (poisoned chains)
    off         --        201 pass            324  in 94 of 100
    mine3     all pass    202, 0 new fails    206  in 86 of 100
    mine4     all pass    202, 0 new fails    132  in 74 of 100
    mine2     p1-p5 FAIL  202, 0 new fails      0  in  0 of 100

Every arm is 100 of 100 green under today's oracles, and the hostile ring arm
is 22 red off, 19 under both `mine2` and `mine4`.

**The residue looks irreducible from one scan, and that is the finding.**
Classifying what `mine4` still allows: 562 are a genuine safe-save (my file is
gone from the disk), 51 are *my file alive at a path nobody tracks, with an
unknown file at my path* -- **which is the Emacs case and the edited-then-
swapped case wearing the same disk state.** A single scan cannot tell them
apart, because they are not different states. 0e predicted exactly this case
before it was measured.

So the choice is not between a right answer and a wrong one:

    mine4   a swap that edited first still poisons a chain
            (132 in 100 seeds, down from 324)
    mine2   every backup-by-rename save loses its version chain, and a
            hardlink or an unreadable Windows handle is DELETED

**The residue classified, which kills the one-scan hold (2026-09-11).** 0e
proposed holding for one scan on exactly this state, on the reasoning that a
swap caught mid-flight has my file under the via name and resolves by the next
scan, while a backup-by-rename persists. Measured over 20 seeds, 179 residue
observations:

    my file is standing at...                                      count
    a path another record owns, that record recorded MY inode,
    agrees with me on the content, and IS the file there            156
    a path nothing tracks                                            23
    a swap via name (.swap-N.tmp / .jd-swap-)                         0

**That second row first said "content disagrees", which was never measured.**
Both flags in the probe that produced it were read through the record at that
path, and for these 23 there IS no record there, so both came back false
vacuously. 0e caught it. Asked directly -- do the bytes standing at my inode's
new path match what I last agreed? -- the answer inverts the conclusion it was
about to support:

    my inode carries my agreed bytes,  path untracked      21
    my inode carries different bytes,  path untracked       2

**21 of the 23 are the Emacs shape exactly**: my original file, untouched,
under a name nothing is tracking.

**What produces that shape HERE, since this workload has no editors** (the
phrase will be read literally later, so say it). On seed 75105 the partner file
at 904's path carries an inode and bytes **no record knows**, while 904's own
inode stands under a fresh name for which the engine has minted a NEW local-only
record:

    SCAN_EDIT entry=File:-4  path="cafe-17.txt" inode_recorded=None
              inode_here=1008 was=None now="e92024e6"

1008 is 904's own inode. So a second record was minted for a file that already
has one -- the same mint-versus-record confusion that broke the first form of
the guard on `a_stranger_saved_later_at_a_held_files_path_is_a_new_file`.

0e read this as store lag behind the disk from a kill between the two. **It is
not a kill: these runs have `kills` false**, and the chaos-alone result says the
same thing from the other side. What a later fix would target is real and worth
pointing at -- **the gap that lets a live file's inode be unknown to every
record at scan time** -- but WHY that gap opens here is not traced, and should
not be written down as though it were. Nobody is proposing that build. 0e proposed a fifth clause for precisely the
other case -- refuse when the bytes at my inode's new path disagree with my
agreed content, trading poisoned for degraded the way rule 4 already does for
moved-and-edited. It is sound and it would close **two**. The residue does not
move.

**None of it is mid-swap.** A one-scan hold separates nothing, so that fourth
option is not real and nobody should build the `LocalChange` variant it would
need. Record this as a property of the WORKLOAD, not of disks (0e): the swap's
three renames sit inside one upload callback, so no scan in this simulator can
observe the parked state. A real scan can. Under `mine4` a mid-swap observation
reads as an edit and does not correct itself on the next scan -- a known limit,
written down rather than built for.

And the 156 are not the ambiguity at all -- they are **p2's hardlinked twin
exactly**: two records, one inode, agreeing on the bytes, and the record at that
path correctly describes what is standing there. `mine4` is right to decline
them. A simulator with no hardlinks in it still manufactures that state, and
**103 of the 156 are standing at a conflicted-copy name**, which is where p2's
pin should say the shape comes from. The pin's comment must ALSO say that a
hardlink is the real-disk twin of the same state, or it gets deleted the day
somebody notices this simulator has no hardlinks in it (0e).

**Traced end to end on seed 75105**, matching the poisoned bodies back to the
scan verdict that produced them: the surviving poisonings really are this
state. 904 adopts inode 1013 at its path while its own inode 1008 stands
untouched under a name only a local-only record knows. That is the ambiguous
case doing the damage, not a separate mechanism hiding behind it.

**That is an owner decision, not an engineering one.** And the third option as
first written here was wrong: the crossing and a rule-1 form are **not
alternatives** (0e). The crossing closes the vault leak; AI is the plain world
with no vault and no crossing in it, 324 poisoned chains per 100 seeds, and no
crossing guard will ever see one of them. The honest pairing is **crossing PLUS
(`mine4` or nothing)**, and choosing "leave rule 1 alone" is choosing to leave
AI open at 324, not choosing a fix that covers it.

**Landing blockers noted whatever ships** (0e): the `ADOPT_STRANGER` eprintln in
rule 1 has no env gate and would print from the daemon; `file_id` 0 must be
excluded; ONE form lands with no `AHGUARD` dial at all, the rest belong in this
spec; and the inode set and sha map should be built once beside `by_path`.

### Three measurement traps this session fell into, all the same defect

**1. `is_ok()` made alternatives additive.** `AH_GUARD` was
`env::var("AHGUARD").is_ok()`, so `AHGUARD=mine` switched on the FIRST form as
well as the one under test, and `mine` was recorded as regressing three tests
when it regresses none.

**2. An exclusion list has the same defect.** Replacing it with
`v != "mine" && v != "mine2"` left `mine3` -- 0e's form, arriving later -- back
in the additive case, and it too was read as regressing three tests. Name the
values that mean THIS form, never the ones that do not.

**3. A flag read through a `None` reports false, not unknown.** The residue's
"content disagrees" column was two `rec_there.is_some_and(...)` reads where
`rec_there` was `None`, so both came back false and the pair read as a finding.
It would have sent the next measurement in the wrong direction.

All three are one mistake in three costumes: **a measurement whose negative
answer and whose absent answer are the same value.** Every probe in this
campaign should be read for it before its number is quoted.

### What is left, and it is not this

The "second verb" this section carried for most of a day was wrong, and it is
worth saying why it looked real. Under the first two forms of the guard, half
the surviving poisonings had `saved while a download was landing` on one side --
a suggestive, nameable ingredient. It was a coincidence of which cases the
guard's narrow question happened to miss. Tracing one instead of counting them
settled it in a single run.

What is genuinely left is the **vault leak**: the hostile ring arm still ends 19
red of 30 with the chain oracle at zero. Poisoned histories and published
plaintext were never the same failure -- they were two consequences of one act,
and only one of them is now closed. The hold at the crossing remains the fix for
the other.

## Defect AJ -- the engine forgets a record while its file is still on the disk

**This is the clean ring arm's whole leak: 11 seeds in 40, none of them AH.**
Traced 2026-09-11. No faults, no kills, no platform disagreement -- two Linux
devices and a user trading folder names.

**What the user sees.** A file they sealed is published on the server in the
clear, under its real name, sometimes twice -- once inside a plain folder and
once at the root of the drive. Both the bytes and the filename, which a vault
is supposed to hide.

**It is not an adoption.** `SCAN_GONE` never fires for the sealed record and
`ADOPT_STRANGER` never fires at all on this arm. The scan simply reports the
sealed file as a CREATION:

    SCAN_NEW path="ring-3/sealed.txt" inode=1001 sha=fe4bf2f8 known_records=13
             any_record_has_this_inode=false any_record_has_these_bytes=false

Nothing in the store claims inode 1001, so the engine mints a new plain entity
for it and uploads it. Uploading it is then correct behaviour for a file the
engine believes it has never seen -- the same shape as AH, reached from the
other end.

**Why nothing claims it.** The user's folder-name trade reaches the server as
the encrypted ring being deleted. `forget_folder_the_server_confirms`
(execute.rs ~4500) then forgets folder 502 and its sealed child 901, checking
the path it REMEMBERS, finding nothing there, and concluding nothing is being
orphaned. Measured across all eleven seeds, at the moment of forgetting:

    believed_path_has_a_file = false      my_inode_is_still_on_this_disk = true    x8
    believed_path_has_a_file = false      my_inode_is_still_on_this_disk = false   x2

**Eight of ten forget the record while the file is lying right there**, wearing
the name the trade gave it.

**From there the plaintext reaches the server by TWO independent routes**, and
the upload probe names both:

    UPBYTES dev=laptop  id=File:-3  enc=true  path="/sync/ring-1/sealed.txt"   correct
    UPBYTES dev=laptop  id=File:-11 enc=false path="/sync/sealed.txt"          leak 1
    UPBYTES dev=desktop id=File:-13 enc=false path="/sync/ring-3/sealed.txt"   leak 2

**Leak 1 is the rescue publishing the vault.** `rescue_unsynced` exists to
carry a local file out of a folder on its way to the trash. It asks
`is_on_the_server`, is told NO -- because the link from inode 1001 to entity 901
is already gone -- and carries the sealed file out to the sync root:

    RESCUE dev=laptop file="/sync/ring-3/sealed.txt" inode=1001
           on_server=false agreed_here_has=false link=None

A rescue is housekeeping the user never asked for, and this one takes a file out
of its vault and stands it in a plain directory, where the next scan mints it
plain and uploads it under its real name. **The rescue saves the bytes and loses
the protection.**

**Leak 2 needs no rescue at all.** On the other device the file simply stays
where it is, untracked, and is minted as a new plain entity in place.

**Three guards were built and measured. None closes it, and the reasons are the
useful part:**

    keep the record when its inode is still on this disk    11 red, unchanged
      -- the record is kept but its PARENT is still forgotten, so
         `known_local` cannot build a path for it and drops it anyway
    also keep the parent folder                             11 red, unchanged
      -- one of the two mints disappears; the record was still marked
         `remote_deleted` by the caller, so the next pass read it as a
         server deletion (an earlier draft here said absorb had emptied
         the record; it had not -- see G4 below)
    also skip that absorb                                   11 red, unchanged
      -- the link from inode to entity is gone by then regardless
    never carry a file out of an ENCRYPTED folder           11 red, leak 1 GONE
      -- closes the rescue route on the measured seed and leaves leak 2,
         which never rescues anything

**The link is NOT always lost -- and that is the finding.** On seed 74023 the
mint probe says it outright:

    MINT dev=desktop path="ring-3/sealed.txt" inode=1001
         link=Some(File:901) entry=None

**The local index still names the entity. Only the entry row is gone.** The
engine looks up which file this is, is told File:901, and mints a fresh plain
provisional anyway. For a sealed file the fresh provisional carries none of the
original's protection, so the bytes and the real filename go up in the clear.
The store's own schema comment already names this state -- *a `local_index` row
pointing at an entity that no longer exists*.

**A fourth guard: never mint a fresh identity for a file the index can name.**
Measured together with the other two (`AJGUARD=all`), over the same 40 clean
seeds:

    guard off     11 plaintext leaks    0 orphans
    all three      3 plaintext leaks    6 orphans

**Eight disclosures become six files that never sync**, and two close outright.
An orphan is caught by an oracle that already exists -- *"holding files no entry
claims, so nothing will ever scan, send, move or remove them"* -- so the
degradation is loud rather than silent. Trading a disclosure for a stranded file
is the same call this engine already makes for corruption against degradation,
and it is a wider margin here: a stranded file is still on the user's disk, and
a published one cannot be recalled.

**But refusing to mint is not the fix either, and the trace says what is.** The
engine had the answer in its hand: `link=Some(File:901)`. When the index can
NAME the file, the engine must **recover that identity** -- re-derive the entry
from the server it is already talking to -- not invent a second one and not
refuse. Minting duplicates a file it can name; refusing strands a file it can
name. Neither is warranted when the name is right there.

### The recovery path is INERT, measured before it was built (2026-09-11)

The fix this file called for -- when the index can name the file, recover the
identity instead of minting -- **cannot work, and one measurement says so.**
public-html-0e predicted it: every route to the lost record passes through the
server having already confirmed the entity gone, so there is nothing live to
recover. Probed at the mint, statting the linked entity:

    server's verdict on the linked entity, at every nameable mint
    across the 40 clean seeds:   deleted = 9 of 9

`absorb_remote` declines to rebuild a deleted entity -- correctly, that is the
resurrection guard -- so recovery in any of its three proposed shapes recovers
nothing. **It was not built. The measurement cost one probe and one run.**

**Which call site kills the record** (0e's Q1), seed 74023:

    laptop   File:901   trash_remote forget_here (execute.rs ~4812)
    desktop  File:901   forget_here (execute.rs ~4837)

Not the provisional arm and not `forget_folder_the_server_confirms` on this
seed: **this device trashed the file on the server itself**, because the trade
had moved it and its old path read empty. So the engine is not losing a record
it should have kept -- it is deleting a file it should not have deleted, and the
record loss is downstream of that.

**What the state at the mint actually is.** Not a recovery problem: a
**provenance** problem. `link=Some(id)` + `entry=None` + the server says gone is
three different histories wearing one shape -- a deliberate drag out of the
vault (where minting plain is CORRECT), a folder the server confirmed gone, and
this device's own trash verdict. Only the verb that killed the record separates
them, and nothing records the verb. The response that fits all three is a HOLD,
not a mint and not a recovery.

**Corroboration is free here, measured:** at all 9 nameable mints the local
index still holds a cached hash for the file and it MATCHES what is on disk.
So a rule that re-anchors only on unchanged content would decline in none of
them -- the cost I had priced for it is zero on these seeds.

### It is the CROSSING, on nine of the eleven -- measured 2026-09-11

0e proposed a guard at the scan's deletion verdict (rule 5) and asked for one
print before it was built: was the sealed file's `trash_remote` queued from a
Convert, or from a Deleted verdict? Measured on all eleven clean leaking seeds:

    seed     Convert verdict for 901     scan ever said Deleted for 901
    74000            yes                          no
    74014            yes                          no
    74017            yes                          no
    74018            no                           no
    74019            no                           no
    74023            yes                          no
    74026            yes                          no
    74031            yes                          no
    74033            yes                          no
    74035            yes                          no
    74037            yes                          no

**The scan never says Deleted on any of them, so the rule-5 guard is inert and
was not built.** Nine of eleven are a Convert at the crossing. And the two that
are not -- 74018 and 74019 -- are exactly the two seeds the keep-the-record
guard already closed. The split is clean: **the crossing accounts for the whole
remainder.**

**The defect in one line:**

    XING dev=desktop id=File:901 enc=true
         local=Moved { to: Placement { parent: Some(504), name: "sealed.txt" } }
         agreed=Some(Placement { parent: Some(502), name: "sealed.txt" })
         verdict=Some(Convert)

502 is the vault folder; 504 is a plain one. **The user never moved the file.
They renamed a folder.** The engine reads a renamed directory as its contents
moving, and converting a file out of a vault is -- by design, correctly, for a
real drag-out -- re-uploading it in the clear and trashing the sealed original.
Every other symptom in this section is downstream of that one verdict: the trash
is what loses the record, and the lost record is what makes the file mint plain.

This is 0e's R8 arriving with a number on it. The root is the directory read as
content (`specs/drive_directory_identity.md`), and the crossing is where it
becomes a disclosure.

### The precondition, measured: the folder map is mid-rotation

0e proposed gating the hold on a symmetry -- a trade reparents the vault's
children into the plain folder AND the plain folder's children into the vault,
and a drag-out can never produce the mirror. They graded the "no pin trips it"
half instinct and asked for it to be measured. **Measured, the mirror is absent
on all nine**, so that gate would never have fired. What IS present on all nine
is sharper.

The scan already knows the folders were renamed. On seed 74023, in the same
pass that converts the sealed file:

    Folder:502 (the vault) local=Moved -> name "ring-3"   agreed "ring-1"
    Folder:503             local=Moved -> name "ring-1"   agreed "ring-2"
    Folder:504             local=Moved -> name "ring-2"   agreed "ring-3"

A clean three-way rotation, correctly detected. And simultaneously it decides
`File:901` moved from parent 502 to parent 504. **Both cannot be true.** If 502
is now called ring-3, the file sitting at `ring-3/sealed.txt` never moved at
all: it is exactly where it has always been, inside 502, which is wearing a new
name.

**Why it gets it wrong** -- the map that turns a path into a folder id, probed
live at the moment of each Convert:

    seed    the ring entries in the map      of 3
    74000   ring-1 -> 502, ring-2 -> 504      2
    74014   ring-1 -> 502, ring-2 -> 504      2
    74017   ring-1 -> 502, ring-3 -> 504      2
    74023   ring-1 -> 503, ring-3 -> 504      2
    74026   ring-1 -> 502, ring-3 -> 504      2
    74031   ring-1 -> 503, ring-3 -> 504      2
    74033   ring-1 -> 502, ring-3 -> 504      2
    74035   ring-1 -> 503, ring-3 -> 504      2
    74037   ring-2 -> 504, ring-3 -> 502      2

A healthy map holds all three. **At every leaking Convert it holds exactly two.**
The engine decides that a file has crossed the boundary of its vault while it
does not know where one of the three folders currently is -- and the file's path
resolves, through that gap, to a folder that is not its parent.

**And the map framing above is itself superseded, one probe later.** 0e read
the insert/remove sites and predicted a STALE entry -- a path in the map naming
a folder the scan had already concluded moved away. Probed at each Convert:

    scan_concluded_the_destination_moved:  None, 9 of 9
    folder moves concluded in that pass:   ZERO, 9 of 9

**Refuted.** The scan concludes no folder moves at all in the pass that decides
the Convert; the rotation was detected in an earlier pass and is not in hand
when the file is judged. So nothing is stale in 0e's sense. Asking what the
destination actually IS splits the nine cleanly:

    seed    destination of the "move"        the file's own parent present?
    74000   a FRESHLY MINTED folder (-11)    yes
    74014   a FRESHLY MINTED folder (-6)     yes
    74017   a FRESHLY MINTED folder (-19)    yes
    74023   real plain folder 504            NO
    74026   a FRESHLY MINTED folder (-11)    yes
    74031   real plain folder 504            NO
    74033   a FRESHLY MINTED folder (-11)    yes
    74035   real plain folder 504            NO
    74037   a FRESHLY MINTED folder (-15)    yes

**Six of the nine are the engine inventing a folder and moving the vault's file
into it.** The vault is present, the file is inside it, and the engine mints a
NEW folder for the directory the file is standing in -- because the directory
is wearing a name it does not recognise -- and then reads the file as having
moved into that new folder. A minted folder is plain. Moving a sealed file into
a plain folder is a Convert, and the Convert publishes it.

The other three are the same sentence with the other half missing: the vault is
not present, so the file's directory resolves to the real folder that used to
own the name.

**So the site is the folder mint, not the crossing.** `detect_folder_moves`
already has the machinery to recognise a renamed directory by the files inside
it -- *"Tracked folders found under a directory nothing is tracking yet... found
by its file"* -- and it did not fire here. **This is `specs/drive_directory_
identity.md` item 4 exactly, with nine seeds and a disclosure attached to it.**
The guard sentence is one step further up than the last version of this section
claimed:

    the engine never mints a folder for a directory that a folder it already
    knows is standing in

**This is the fix, and it costs nothing anybody will notice.** Not a hold on
drag-outs, not a confirmation step, no change to `c1` or `c1b` -- a single-vault
drag-out has a complete map (both folders present) and converts exactly as
today. The sentence is the family one again:

    the engine never decides a file has left its vault from a picture that
    cannot say where the vault currently is

**The earlier framing in this file was wrong** and is withdrawn: the owner is
not being asked to rank the vault's promise against drag-out convenience. There
is no trade to make. This is also 0e's R8 from the other side -- item 4's
directory identity would make the map complete by construction, and this gate is
what protects the estate until it exists.

### The mechanism, confirmed to the line -- and a second fix that landed

0e read the pool machinery and predicted that the evidence identifying the
renamed vault is computable at the moment it is thrown away. **Measured, and
true on exactly the six seeds it applies to:**

    seed    vault 502's pool    moved_wholesale into
    74000   contested           "ring-2 (conflicted copy ... from desktop)"
    74014   contested           "ring-3"
    74017   contested           "ring-3 (conflicted copy ... from laptop)"
    74026   contested           "ring-3 (conflicted copy ... from desktop)"
    74033   contested           "ring-2"
    74037   contested           "ring-2 (conflicted copy ... from laptop)"
    74023, 74031, 74035  -- no hit, and 0e's account says why: there the
                            vault is `missing` and its contents sit at a
                            TRACKED path, so that directory is not a candidate

**The walk, confirmed against the source:**

1. The vault's believed path holds a directory full of somebody else's known
   files, so it lands in the `contested` pool.
2. `contested` is resolved by one mechanism only -- the closed-ring walk -- and
   every leg must land on a tracked path. The vault's own contents are sitting
   in an untracked conflict-copy directory, so that leg leaves the tracked set
   and the ring does not close.
3. The candidate loop is `for (pool, whole_only) in [(&missing, false),
   (&displaced, true)]` -- **`contested` is not in it.** Nobody claims the vault.
4. The untracked directory reaches the folder mint, which has no gate at all,
   and gets a brand-new PLAIN identity.
5. The sealed file is now inside a plain folder. Convert. Published.

`moved_wholesale` would have answered this at step 3. It is never asked.

**The belt, landed.** At the folder mint: never mint a folder for a directory
that holds a file the engine holds an ENCRYPTED record for.

    scoped to sealed files on purpose. The unscoped form -- any known file --
    breaks FIVE tests, one of them named
    `renaming_a_folder_and_its_subfolder_together_keeps_the_subfolder_and_
    remints_the_parent`. Reminting a directory of ordinary known files is a
    DESIGNED behaviour with pins of its own. Minting a plain folder around a
    file the user sealed has no innocent reading.

**Why the narrow form is the TRUE sentence and not a compromise** (0e, and the
pin says it in its own words): a minted folder is temporary. *"The next pass
learns the folder from the index and FOLDS THE PROVISIONAL INTO IT"* --
`a_folded_provisional_parent_still_receives_the_move_into_it`. So for an
ordinary file the wrong identity costs nothing; the engine knows how to undo it.
**A sealed file has no such fold: by the time anything folds, the plaintext is
already on the server.** The general belt was refusing a mint the engine can
undo. The narrow one refuses the only mint it cannot. Nobody should widen it
later.

Safe by the check 0e asked for: left unminted, `placement_of` cannot answer for
the files inside, `local_delta` returns `Delta::None` for their moves -- the
existing *"its folder is not tracked yet"* wait, not a deletion -- and the
pairing still claims their observations, so nothing reads as a creation either.

    measured                          before      after
    clean ring arm, 40 seeds          11 red      7 red
    distinct leaking entities         11          7
    scenario suite                    208 pass    208 pass, 0 new failures

**And it reports itself.** A refused mint is a wait, and a wait nobody can see
is a file that silently stops syncing for as long as the chain stays open --
for ever, if the folder at the far end is deleted meanwhile. So the guard raises
`sealed_folder_unplaced` on each sealed file it is holding, saying nothing was
uploaded and nothing was deleted, and withdraws it the pass the directory is
placed -- the same shape as the `unsyncable` dismissal, where what is held NOW
is the whole truth. Measured: no change to the 208 and no change to the arm.

**It closes four of the six mint-shape seeds.** The other two (74014, 74033) and
the three `missing`-shape seeds convert into a REAL folder rather than a minted
one, which the belt does not touch; 74018 and 74019 are the `forget_folder`
route. That remainder is 0e's fix (b) -- resolve wholesale moves as one
assignment over chains, not only closed rings, and match `contested` against
candidates exactly as `displaced` is -- and (c) item 4, which makes all of it
moot by giving the directory an identity.

### The one fix that landed

**The rescue net, unconditional, with no dial and a visible issue.** A rescue
carries a file out of a folder on its way to the trash; out of an ENCRYPTED
folder that is a disclosure, so it does not happen. The file goes to the trash
with its folder -- which is a rename, not a shredder, so it is recoverable from
there -- and the user is told, beside the existing `rescued_from_trash` issue:

    sealed_not_rescued: N file(s) here are inside a vault and were left in the
    folder rather than moved out of it, because moving them out would publish
    them. They went to the trash with the folder and can be recovered from
    there: ...

    measured, unconditional          before        after
    clean seeds 74000-74039          13 leaking    11 leaking    (11 red both)
    clean seeds 74040-74119          18 leaking    16 leaking    (16 red both)
    scenario suite                   208 pass      208 pass, 0 new failures

**It is a net, not the fix**, and the red count does not move: the other route
mints in place and never rescues anything. Also landed with it, from 0e's
landing blockers: the `ADOPT_STRANGER`, `RESCUE` and `MINT` probes are now
env-gated, where before they would have printed from the daemon.

**Build order from here (0e's, and it is right):** the hold at the crossing
first -- it is upstream of both AJ routes and already owed to AF -- then a
tombstone that records WHY a record died so the three histories above can be
told apart, with the hold living in a side table beside the entry rather than as
a status naming recomputes every pass.

### How far AJ reaches: five shapes that are SAFE

AJ was found through the ring arm, whose folder-name trade is a synthetic act.
Before anyone reads it as "renaming a folder publishes your vault", five pins
were written and all five are GREEN (one device unless stated, no faults, no
concurrency beyond what is named):

    1. rename a vault folder                                        safe
    2. a vault folder and a plain folder trade names                safe
    3. the same trade, with a second computer syncing               safe
    4. the same, with the other computer writing in the same round  safe
    5. THREE folders rotating names, one of them the vault          safe
    6. two computers renaming folders onto ONE name at once,
       so the server must make a conflict copy of a folder          safe

A three-cycle is not two swaps -- no pair ever exchanges names -- so shape 5
rules out the most likely remaining structural explanation. **Whatever AJ needs,
it is not any of the obvious user actions**, and the ordinary person renaming
their private folder is not exposed by anything measured here.

Shape 6 was written because every leak path carries a conflicted-copy name --
`ring-2 (conflicted copy ... from desktop)/sealed.txt` -- so a folder conflict
looked like the missing ingredient. On its own it is not.

**Shrinking the real seed instead of guessing:** seed 74018 leaks at 30 and 40
steps and passes at 20 and below. So it is not a two-or-three-action affair; it
needs a run long enough to accumulate state. (Step count also moves the RNG, so
this bounds rather than bisects.)

Two more were written and both are GREEN as well:

    7. the three names traded over TEN rounds, alternating computers   safe
    8. traded over twelve rounds MID-SYNC -- one pass each between
       rounds, never a settle, so nobody is allowed to finish          safe

**Eight shapes, all safe. The minimal repro is NOT found**, and those greens are
the bound on the claim until it is.

**What the failing seed actually does**, read off with an action probe: seed
74018 at 30 steps performs only TWO ring trades, at step 2 and step 29, with
twenty-seven steps of ordinary workload in between -- writes, moves, folder
creation, conflicts. So the trade is necessary and nowhere near sufficient, and
the missing ingredient is something the intervening activity leaves behind.

**A bisect was attempted and is NOT reportable.** Disabling one action class at
a time made 12 of 20 runs pass -- but skipping an action also skips the random
draws inside it, so every later step gets different inputs and the world
diverges. Only `skip 13` is interpretable, and it says nothing new: remove the
trades and the leak goes with them. **The method is wrong for this question**,
and the numbers it produced are recorded nowhere on purpose. A sound version has
to keep the random stream identical -- perform every action, neutralise only its
effect on the rings -- and that is a harness change, not a run.

**None of this is landable except one piece, and the scenario suite is what
says so.** Seed counts hid it completely -- the clean arm moved 11 to 9 and
looked like progress:

    guard            scenario suite      new failures   what it breaks
    rescue net       201 pass            0              nothing
    keep the record  198 pass            3              telling_the_server_to_trash_a_folder_
                                                        forgets_what_was_under_it_too, and two
                                                        folder-into-vault tests -- it disables
                                                        that behaviour by design
    refuse the mint  194 pass            7              the park / land-beside / adopt machinery,
                                                        all of which mints on purpose
    all three        191 pass            10

**The rescue net is the one clean result: zero regressions, and it removes a
disclosure route on its own.** Counted as DISTINCT ENTITIES that published the
plaintext -- the honest unit, since one seed can leak twice and a retrying
upload logs many times for one disclosure:

    distinct entities that uploaded the sealed plaintext
    clean arm, 40 seeds       guard off 13      rescue net 11
    hostile arm, 30 seeds     guard off 14      rescue net 14

**A caution about the unit, because it nearly became a finding.** Counted as
upload ATTEMPTS the hostile arm reads 234 against the clean arm's 13, which
looks like an order-of-magnitude difference and is not one: retries under faults
log the same disclosure many times. By distinct entity the two arms are the same
rate. **AJ is not a faults phenomenon** -- unlike AH, which chaos alone
reproduces, this one leaks just as readily with no faults at all.

**And the rescue net does not help the hostile arm** (14 either way), so it
closes a route the clean arm happens to take.

**State, honestly:** the defect is characterised end to end and its two routes
are named. Of four guards, ONE is clean and closes part of it; the other two are
not viable as written, and the combination that gets disclosures from 11 to 3
costs 10 regressions and 6 stranded files. The recovery path -- when the index
can name the file, re-derive its entry rather than minting or refusing -- is
designed and NOT built, because building a store-repair path overnight with no
owner awake is how a sync engine learns to resurrect files nobody asked for.

**The fix is a question this campaign already asked somewhere else.** The
comment above that code already states the intent -- *"a local file that has not
reached the server is rescued out of the folder before the trash, not forgotten
with it"* -- and the rescue is scoped to UNSYNCED files. The same rescue is owed
to a synced one whose bytes are still here. And the test for "still here" is not
the remembered path; it is **is my file still on this disk**, which is exactly
the question the rule-1 guard asks in `scan::pair`.

**One invariant, two sites:**

    scan::pair rule 1   bytes at my path are not mine if MY FILE is elsewhere
    forget_folder...    a record is not disownable if MY FILE is still here

That AH and AJ are the same sentence asked at two moments is the strongest
evidence yet that the invariant is the real one and not a patch fitted to a
symptom.

**Severity ranking against AI.** AI corrupts version history and the current
file stays right; AJ publishes the plaintext and the filename of something the
user sealed. AJ is the worse of the two, it needs no faults, and it was sitting
underneath a number this campaign has quoted for weeks as "the clean arm leaks
11 in 40" without anyone asking what the verb was.

## AJ closed to one seed (2026-09-11)

    clean ring arm, 40 seeds        red     distinct leaking entities
    no guards                        11              13
    + rescue net                     11              11
    + folder-mint belt                7               7
    + chain assignment                3               3
    + sealed-scoped keep-record       1               1

208 scenario tests pass throughout, with the same three pre-existing failures
and none added. No seed livelocks.

**The four guards, three of them scoped to sealed records, each measured alone:**

1. **Never carry a file out of an encrypted folder.** A rescue saves bytes and
   would lose the protection.
2. **Never mint a plain folder around a sealed file.** A minted folder is
   normally temporary -- the engine folds it into the real one a pass later --
   but a sealed file has no fold: the plaintext is up before anything folds.
3. **Let the `contested` pool be matched against candidates like `displaced`.**
   The pools differ only in what the folder's OLD path holds, which is evidence
   about somebody else, never about where my files went. **This one is NOT
   sealed-scoped: it is a general change to how renamed plain folders are
   placed** (c6's F1). Its three refusals were measured applied to `contested`
   alone and to `contested` + `displaced`, and every number is identical, so the
   landing form applies them to `contested` only.
4. **Never forget a sealed record whose file is still on this disk.**

**Three guards were needed to make (3) safe, and each was found by a failure
the arm could not see:**

    a match must not be taken when somebody has moved wholesale INTO my old
      path and is not yet placed
        -- else my half of an exchange settles and theirs is stranded under a
           conflict name (a_rename_refused_onto_a_siblings_name...)
    a match must not take a directory another tracked folder still calls home
        -- earlier matches drop their old keys, so a home looks free
    a match must not take a name the SERVER is already moving a folder onto
        -- else the two arrive at one slot and neither yields: seed 74033 ran
           2000 passes reporting TWO_CLAIMANTS on "ring-2" every one of them

**That third one is the important lesson of the day.** Before it, the chain
assignment read as a clean 7 -> 3. It had converted a disclosure into a device
that never syncs again -- measured only because the arm reported `never
settled` and I checked whether the seed did that WITHOUT the change. It did
not. **A fix that trades a leak for a livelock is not a fix, and the seed count
said it was.**

**Scoping to sealed records is what makes (2) and (4) land.** Unscoped, (2)
breaks five tests and (4) breaks three -- reminting and forgetting are designed
behaviours the engine knows how to undo. Sealed is the case it cannot undo. The
accepted debt, said out loud (c6): plain files in these same shapes still get
re-minted and re-uploaded, which costs transfers and version noise, not secrets.

### Review round (c6, 2026-09-12) -- what the port carries beyond the scratch form

    F1  fix 3 is general, not sealed-scoped; refusals narrowed to `contested`
        (measured identical either way, above)
    F2  the server-side-rename refusal keys by FULL remote path, not bare name;
        a trade one level down is covered; that case is unmeasured
    F3  the sealed-file set skips file_id 0 (a real disk's answer when a
        handle cannot be opened; one such record would make every directory
        holding a file_id-0 file unmintable)
    F5  fix 4's two halves use one test and one disk walk; the walk is
        `inodes_on_disk`, documented as load-bearing
    F6  MEASURED: with fix 4, 74018 and 74019 settle in 5 rounds (6 without),
        forget_folder re-enters once more and stops, all oracles green --
        "kept" means settled, not a quiet forever-walk
    G1  MEASURED, and it corrects a reason this file gave. The root is
        filtered out of `real` before the stat, so it is never absorbed in
        forget_folder; c6's "skip absorbing the root too" is a no-op (run,
        identical). After the keep the sealed file is in the LOCAL TRASH on
        both devices, inside its folder -- sealed, recoverable, never in the
        tree as a stranger. The kept record describes a file the trash holds.
        Harmless there; not the thing that publishes.
    G4  the skip-absorb half's stated reason was WRONG in this file and in
        the comment: absorb's deleted branch writes only `remote_deleted` and
        strips nothing. What the skip preserves is that flag staying false.
        Corrected in both places. A wrong reason is what a later session
        widens.
    G2  one held file raised one issue per ancestor directory; a held-dirs
        set now skips a candidate under one already refused
    G3  remote_wants can be one pass stale when the parent is mid-rename;
        both directions named in the comment, no wider
    F4  a vault nested under a PLAIN folder: the server refuses the state
        ("a vault folder can sit only at the drive root or inside another
        vault", jd-sim/src/server.rs:882, mirroring drive_folder_create_logic),
        so the pin cannot be built. The rescue re-asks `sealed` at every
        directory anyway, so the refusal does not depend on that rule.

**Ported into a pristine copy of the tree, no probes, no dials, checkpointed
after each hunk, and re-run after the second review round with the same
numbers:**

    pristine     201 pass   11 red
    + fix 1      201 pass   11 red
    + fix 2      201 pass    7 red
    + fix 3      201 pass    3 red
    + fix 4      201 pass    1 red    (74033)
    livelocks at every step: 0

Every step matches the scratch numbers. Diff: scratchpad/port/aj_port.diff.

**Deliberately NOT in the port:** the upload-link gap fix (the scan caches a
hash with no entity, so a file this computer WROTE never gets its inode->entity
link; `upload` should record it even when the hash is cached). Real gap, six
lines, no measured effect on this arm. On the running to-do until it lands with
a pin that shows it doing something.

**74033 is the one that remains**, leaking exactly as it did before any of this.
Four more things were built for it. All four are SAFE -- 208 tests, no new
failures -- and all four are **INERT on this arm**, which is stated plainly here
because an inert change must never be quoted as a fix:

    a file whose folder moved wholesale is read as having stayed put
        -- fires, and closes nothing here. Its first form was WORSE than
           nothing: unscoped it broke two held-file pins and took the arm from
           1 red to 2. Scoped to sealed files it is harmless and idle.
    `forget_entry` no longer purges `local_index`
        -- the row says "these bytes were last agreed with entity X" and stays
           true after the entry is gone. Right on its own terms; changes no
           number here.
    `upload` records the link even when the hash was already cached
        -- the scan caches a hash with NO entity, so the upload's `Some(s)`
           branch left the link unwritten for ever for any file this computer
           WROTE rather than downloaded. Real gap, real fix, no measured effect
           on this arm.

**Why 74033 resists all of it:** its mint reads `link=None` -- the inode was
never tied to an entity on that device at all -- and the two link fixes above do
not reach the moment that matters on this seed. What it needs is the full
assignment over chains that 0e's fix (b) describes, or item 4. **Six guards is
already five more than a defect should need, and the seventh is not another
guard.**

## Defect AI -- swapping two filenames poisons both files' version histories

**Severity: after an ordinary rename swap, restoring either file to its previous
version gives the user the OTHER file's content. No vault, no faults, no second
device, no concurrency -- one person renaming two files past each other.**
Reproduced 2026-09-10 in `two_files_trading_names_keep_their_own_histories`.

**And it reaches further than restore, which is the form the owner will care
about** (public-html-0e; REASONED from how sharing is addressed, not measured --
the simulator models no sharing, so this needs confirming against the platform
before it is repeated as fact): sharing follows the ENTITY. A public link or a
member grant issued on 901 would, after the swap, serve 902's bytes -- the
person sent a draft is reading the other document -- and a tier grant or key
wrap held on the entity would cover content it was never issued for. "Restore
gives you the other file" becomes "the link you sent gives someone the other
file".

Write `a.txt` and `b.txt`, settle, then swap the names through a scratch name
the way anyone swaps a draft for a final. Result:

    tree      a.txt = A's content    b.txt = B's content     <- CORRECT
    versions  901 (a.txt): A, then B
              902 (b.txt): B, then A

The tree is right, so **every end-state oracle in the estate is green**. What is
wrong is invisible to all of them: each file's history now contains the other
file's bytes. A user restoring a previous version gets a stranger's document.

**Same mechanism as AH**, and the probe says so directly -- exactly two
adoptions, one each way:

    ADOPT_STRANGER entry=File:901 path="b.txt" took inode=1001 (belongs to 902)
    ADOPT_STRANGER entry=File:902 path="a.txt" took inode=1002 (belongs to 901)

`scan::pair` rule 1 matches by path without the inode, so each record reads the
other's bytes as an edit of itself and uploads them as its own next version.

**What this settles about scope.** AH was found in a vault ring under faults, and
the obvious question was whether the exchange is a faults-only phenomenon. It is
not: this needs no faults, no vault and no second device. The estate's file-slot
trades have been running this shape green for forty thousand seeds a version,
because nothing in the estate asserts on version rows.

**Still to measure, and NOT yet claimed:** how OFTEN this happens in ordinary
arms. The hostile ring seeds show 7-21 adoptions each, but the clean arms have
not been probed, so "ordinary files exchange histories continuously" is
unsupported as written and must not go further until the control is run
(public-html-0e). The control is the ADOPT_STRANGER probe on the clean ring arm
and on a plain file-slot-trade arm, checking it stays quiet where it should --
in particular that it does not count the legitimate case of a download landing
new bytes at a record's path after `make_room` moved the old file aside.

**PREDICTION FOR AI UNDER AH'S GUARD, written before it is measured**
(public-html-0e): with rule 1 refusing a path whose inode another live record
owns, both records fall through to the by-hash rule and each finds its own bytes
at the other's path -- two `Moved` deltas, a swap, which is the case naming
already handles through a `.jd-swap` park and which the swap pins are green on.
So the expected result is: two moves through a scratch name, 901's chain
carrying A only, 902's carrying B only, **no upload at all**, no conflict copy.
Anything else -- an upload, a park that does not finish, a conflict name -- is
the guard interacting with naming and must be traced before the guard lands.
AI is the right FIRST measurement of the guard, ahead of the hostile arm,
because it is minimal: two adoptions, one each way, no faults.

**The oracle this needs** is the chain assertion: give each PATH a lineage by
following the workload's own renames, and assert per server entity that every
version it received is a body the workload wrote at THAT lineage. Estate-wide,
not AH's pin alone. Its first run will be red, and that red is the baseline any
fix is measured against.

**The invariant the fix has to restore**, and the one sentence to test against:
*the engine never forgets a sealed record while its inode is still on the disk.*

It also says the move-aside fix reaches this, though by a different route than
predicted: if the aside carried 502's record, 901's ancestor chain would resolve
to the conflict path, the file would be found, and the belief-based forget would
never be reached.

**A policy line this defect earns, wider than itself.** *A drag out of a vault
converts by design* is a statement about something THE USER did. Here it was
applied to a move the ENGINE made. Conversion must be gated on the user having
moved the bytes: a path the engine minted for its own purposes -- a `make_room`
move-aside, a rescue, a park -- must never count as consent to publish. That
gate alone turns AF from a leak into a stall with an issue raised, which is the
failure this engine should have whenever identity is uncertain.

**Operational note: the ring arm must NOT join the estate until AF is fixed.**
It fails 11 seeds in 40, so adding it now would make every estate red and bury
the signal from the other fifteen arms. It runs on demand
(`VAULT=3 ... scratch_one`, or `scratch_ring_sweep`) until then.

## The oracle is blind to custody

The estate oracle proves two things: both sides converge, and no bytes are
lost. Custody -- which folder a file belongs to, and which record owns a
directory -- is neither. Defects AA, AB and AC are all custody defects, and
every one of them passes the oracle: the bytes are right, both sides agree, and
the file is in the wrong folder consistently everywhere. That is why the whole
family was found by reading the code and probing rather than by running seeds,
and why a clean estate of forty thousand seeds said nothing about it.

Named by public-html-0e, 2026-09-05. Building it was attempted the same day and
the attempt is what pinned down why it is hard.

**A custody oracle cannot be built from runtime state.** Three formulations were
tried against the Defect AB world with the fix reverted, and each one passed
while the defect was present:

- *no two folder records claim one directory* -- the parked rival holds no
  directory at all, so only one record ever claims the slot.
- *nothing materialized hangs off a parent that holds no directory* -- the
  child's record is rewritten to the folder it actually landed in, so its
  parent is the winner and perfectly materialized.
- *an entry is filed under the folder the server says* -- and this is the one
  that settles it: **the device pushes the misplacement to the server.** Left
  unfixed, the run ends with the server itself holding `readme/b.txt` and
  `README` empty. The file is reassigned to a different folder for every
  device, permanently, and there is no disagreement left anywhere to detect.

So the oracle has to remember what the workload INTENDED, the way `Committed`
remembers bytes: every file the workload creates records the server id of the
folder it was created in, every folder move carries that forward, and the check
after settling is that each file still belongs to the folder it was put in.
That is a change to the workload, not an assertion that can be bolted on after
it.

What is in the sweep today is `assert_no_two_records_on_one_directory`, the
first formulation above -- kept because it is true and free, labelled in its own
doc comment as NOT a custody check, because it has never fired on a known
defect. Until the intent-tracking oracle exists, a green estate remains no
evidence at all about this class.

**Intent tracking costs no seed re-roll.** This is worth stating next to the
other outstanding workload question, because the two have very different
prices. Adding a sweep ARM -- for the case-twin and escape-needing folder names
no seed can mint -- changes `rng.below(20)` and re-rolls every pinned seed into
a different world. Intent tracking draws no random numbers: it records what the
workload was already doing, alongside the `Committed` bookkeeping that already
runs. Every pinned seed keeps its meaning. So of the two, this is the one to
build first.

**Built on 2026-09-05, and it does not work. Here is exactly why, so the next
attempt starts further along.**

The bookkeeping half is fine and was not the problem. User actions were recorded
at the four `MemFs` entry points (`user_write`, `user_mkdir`, `user_rename`,
`user_remove`) and shared across the devices of one world, so the log is the
order the user acted in across all their computers -- no instrumentation in the
twenty-odd workload arms, where a missed site would have meant a hole. Replaying
that log gives each directory a synthetic id, carries it through the renames and
moves the user performs, and records for each file the id of the directory it
was created in. Directory identity survives renames, which is the whole point:
an oracle that identified a folder by its name would be blind to precisely the
family it is hunting.

**The check then needs to find each file again after settling, and there is no
handle that works.** Three were tried, and each is defeated by a deliberate
feature of the workload:

- **By content hash.** Defeated by the copy arm, which exists to make two files
  with byte-identical content, and by edits: when the original is edited, the
  only file left carrying those bytes is the copy, so the original's custody
  resolves onto a file the user put somewhere else. Every seed tried failed
  this way, including seeds with no defect in them.
- **By content hash, excluding engine-made files.** Conflict copies are
  recognisable by name and were excluded. It does not help: the collisions that
  matter are between two files the USER made.
- **By path.** A file the engine has relocated is exactly a file whose path no
  longer matches, so it reads as absent and is skipped -- and a relocated file
  is the entire defect. The check becomes tautological for everything it can
  see and blind to everything it is for.

**What is left is entity identity.** The server's own file id is the only handle
that survives a rename, a copy with the same bytes, and a relocation. Getting it
means the workload learning the id of each file it creates, which means observing
sync state mid-workload -- a bigger change than the oracle itself, and one that
would have to avoid disturbing the draw sequence. That is the next attempt, and
it should not be started until someone has decided that cost is worth paying.

Reverted rather than shipped: an oracle that fails healthy seeds is worse than
none, because it trains everyone to ignore it.

## Still open, found by review probes (2026-09-05, public-html-0e)

B1 to B3 are pre-existing and none is caused by the AB or AC fixes. B4 and B5
were added on 2026-09-07 from the AD review, and B6 and B8 on 2026-09-08. B6 is
fixed; B1, B3, B4, B5 and B8 remain open. B8 is the one to look at first: it is
the only one where the device tells the user it is finished while a file exists
on one machine only. Recorded here so they are not
rediscovered a fourth time.

**B1 -- a child the server moves INTO a parked folder loops for ever.** The
hold added for Defect AB keys on the folder a child is LEAVING
(`local_placement().parent`), so a child whose new parent is the parked folder
is not held: the round plans a `move_local` into it, the parent gate answers
Overtaken, the record is unchanged, and it is planned again every pass --
forever-loop shape 2, never quiet. `shadowed` has the same blind spot for
`OutOfScope`. The fix consistent with "children wait with the parent" is to
hold an entry whose REMOTE parent is in the set as well as its local one.

**B2 -- `unmaterialize_and_park` abandons a FOLDER's directory and lies about
it. FIXED 2026-09-08.** `vfs.fingerprint` of a directory is None, so the park never moves the
directory: it clears `synced_placement`, sets `Unsyncable`, and raises "moved
to the trash" while nothing was trashed. The record then says there is no
directory while the directory stands at the old name with the children inside
it. On HEAD a later edit to one of those children is uploaded as a NEW file in
a NEW server folder and the original is deleted server-side; with the AB hold
in place the edit is never synced at all and the device reports itself quiet.
Neither is right, and this is the state that makes AB's `no_directory`
condition unpinnable today.

Reproduced minimally on 2026-09-07 while probing AD's neighbours, and it needs
none of AD's ingredients -- one device, no swap, nothing local:
`probe_a_folder_renamed_into_a_case_twin_parks_cleanly` (ignored, red). The
server renames `C` to `b` while this disk already holds `B`; the disk cannot
hold both, so the park is correct. What follows it is not. The user is told `C`
was moved to the trash, which it was not, and the directory `C` with `c.txt`
inside it is left on the disk claimed by no entry at all --
`assert_no_entry_is_stranded` names it: *holding files no entry claims, so
nothing will ever scan, send, move or remove them*. B2 blocked one AD test,
`a_local_swap_leaves_an_unrelated_parked_case_twin_alone`, which is ignored for
that reason and not for anything to do with the trade: the trade lands and the
parked twin is left alone, and then the abandoned directory fails the run.

Traced to the line: `unmaterialize_and_park` guards everything it does to the
disk behind `if let Some(now) = env.vfs.fingerprint(&path)`, and a directory
has no fingerprint. So a folder park skips the disk entirely, clears the
record, and then raises the trash issue anyway. The machinery to do it properly
is already in the file and already used for folders --
`rescue_unsynced(env, folder, into)` moves out anything the server does not
have, and `env.vfs.trash` takes the rest -- so the fix is to route a folder
through that instead of past it, and to raise the trash issue only when a trash
actually happened.

**The fix, and the two things it turned up.**

A folder is now given up the way a folder is given up everywhere else here:
work the server does not have is rescued out beside it, the directory goes to
the OS trash with what remains, and the descendants' records go with it. They
are put back to `PendingDownload` rather than marked `Unsyncable` -- there is
nothing wrong with THEIR names, it is their parent that cannot be held on this
disk, and saying otherwise tells the user their file is broken when it is not.
They are not forgotten either, because the server still has them and the park
is supposed to come back. The trash issue is raised only when a trash happened;
claiming one that did not sends the user hunting through their trash for
something that was never in it, and that goes for the file path too.

**The rescue was asking the wrong oracle.** Routed through `rescue_unsynced`
unchanged, the park carried the folder's files OUT to the sync root and
uploaded them as new content, leaving the user a duplicate at the top of their
tree and two copies on the server. `is_on_the_server` answers from
`entity_for_file_id`, which reads `local_index` -- a SCAN artifact -- and a
folder given up in the same pass that scanned it has children the index holds
no row for. So the check answered "never seen it" about a file the server was
holding. It now takes what the caller knows from the RECORDS first, and the
callers that know a subtree pass it; the freshness test is unchanged, so an
edit nobody has uploaded is still work worth saving. Same lesson as AA to AE:
ask a record, not the disk.

**The convergence oracle could not describe the correct answer.** With the park
fixed, `assert_converged` failed on `b` and `b/c.txt` being only on the server.
The content exemption excuses a parked entry by its own bytes; a folder has
none, and nothing reached the FILES under a parked folder, which have no
claimant on this disk and cannot get one. Established as an oracle gap rather
than a bad end state by removing the park entirely: a device syncing a server
that ALREADY holds a name this volume cannot add lands in exactly the same
place (`a_case_twin_that_was_never_holdable_converges`), so it is a designed end
state. It had never been seen because the workload never mints a folder name
that folds onto another, so no sweep has ever produced the shape. The exemption
added is against a RECORD and on the server's side only -- a path under a park
is forgiven when this device has an entry for it holding no local copy, so an
entry the engine has lost track of, or one still claiming a copy, goes on
failing, and anything the device wrongly HAS still shows up as
only-on-the-disk.

**Three corrections from review (public-html-0e, 2026-09-08), all reproduced
here before acting.**

*The descendant sweep was keyed on the wrong tree.* `subtree_ids` walks the
REMOTE parent, and in a round that also moved something the two trees disagree.
A child the server had just moved INTO the folder was in the remote subtree
while its copy still sat where it was, so resetting its record handed the scan a
stranger and the next pass uploaded a second copy. A child the server had just
moved OUT was not in the remote subtree at all, while its copy was in the
directory and about to go to the trash with it. The sweep now asks the LOCAL
chain -- `local_chain_passes`, the same question the server-side trash asks --
and a copy in here that the server keeps elsewhere is waited for rather than
trashed: `Retry`, with a `park_waits` issue while `local_chain_parked` says the
wait has no end in sight, withdrawn the moment it stops waiting. Retrying is
safe here where it is not in the file arm, because the move being waited for is
planned on the CHILD, which has no open operation.

Worth recording: keyed on the remote tree the park SETTLED, by minting the
duplicate. That masked **B1**, whose whole signature is never settling. Keying
it on the local chain removes the mask and the honest B1 loop comes back, which
is the better failure of the two.

*The folder arm had no ownership guard -- and this one is load-bearing, not
tidiness.* It was proposed and taken as a cheap fix on principle. It is the only
thing standing between an honest hash index and a destroyed file: with B6 fixed
and this guard absent, `a_second_folder_conflict_at_one_name_gets_its_own_name`
loses `f3.txt` from the disk and the server both. Note the pin discriminates it
only while the index is honest -- let the index lie again and the over-rescue
hides the hole, which is how it survived this long. `local_path` resolves from this entry's
record and naming ranks by records, so the slot it names can be another live
folder's directory -- the escaped spelling of one name and the literal spelling
of another landing on one string. The file arm has
`the_file_here_is_another_entrys` for exactly this; the folder arm would have
rescued another folder's files out from under it and trashed its directory. Now
the same source-holder rule that fixed Defect AC: another live, non-parked
folder entry with a directory resolving to this slot means disown the records
and touch nothing.

*The oracle exemption did not make the excuse earned.* Keyed on any unsyncable
folder, a naming regression that parked folders wrongly would hide their whole
subtree -- and parking on a stale reading is precisely what Defect AE was. A
folder parked for a CLASH is now only excused where the oracle can see the clash
itself: a live sibling in the same parent whose name folds onto the parked one
under that volume's rule. Reasons that are self-evident from the name -- too
long, a forbidden character -- pass through, because `expected_path` already
drops those paths on its own.

**How much the oracle was actually loosened, measured rather than argued.**
Loosening an oracle cannot be defended by a green run -- a looser oracle can
only pass more often -- so the exemption was instrumented and counted instead.
Across the whole scenario suite it fires SIX times, and all six are the three
tests that exist to need it: two server paths each, the parked folder and the
file under it. It fires zero times in the sweep arms, which is the estate's own
code, and zero times in the executor suite. So it is inert for every piece of
existing coverage and cannot be hiding anything those tests would have caught.

Pins, each proven red against the half that fixes it:
`a_folder_parked_for_a_case_clash_does_not_abandon_its_directory` (red without
the engine change, with the abandoned directory named),
`a_case_twin_that_was_never_holdable_converges` (red without the oracle change),
and `a_local_swap_leaves_an_unrelated_parked_case_twin_alone`, which was ignored
on B2 rather than on anything to do with the name trade and is now live.

**B4 is unblocked but NOT fixed by this**, which is worth recording because it
was expected to be: B2 was necessary and not sufficient. What remains is the
rescue's choice of name -- for a locally driven park the disk knows the
destination, and the rescue asks the agreement instead.

**B4 -- a local swap whose first server rename is refused leaks the vault.**
Found under fault injection by public-html-0e, 2026-09-07; probe set in that
session's scratchpad as `zz_probe_ad_review.rs`, and pinned here as
`probe_a_local_vault_swap_whose_first_rename_is_refused` (ignored, red). The
plan parks one folder on the server, the finisher is refused and withdrawn, and
the orphaned park is put back to its AGREED name -- the origin of the journey,
which the other folder has since taken -- so it lands as a conflict copy. The
directory it wore is parked `DuplicateName`, **B2** leaves it standing on the
disk, and its files are adopted as new content into a MINTED plain folder. With
a vault on one side the memo reaches the server in the clear. For a locally
driven park the disk knows the destination; the rescue asks the agreement
instead. The fix needs B2, so it is taken together with B2.

Not caused by the ring rule, and measured rather than argued: on the pre-ring
`pass.rs` this same probe leaks too, and three further fault shapes fail there
which the ring rule fixes -- a lost rename answer, and chaos over the swap. Four
of the review probes fail without the rule and two with it.

**B5 -- a parent renamed while its two children swap is trashed and re-minted.**
Pre-existing on both trees. `P` becomes `Q` while `Q/A` and `Q/B` trade names in
the same go. The children are identified correctly; `P` is not -- it is trashed
and re-minted under a new id, with the children re-homed under it. `children[]`
credits a folder with its descendants at their OLD relative paths, and those
paths no longer exist under the new name, so `P` matches nothing and reads as
gone.

**B6 -- a folder deleted on one device resurrects its contents at the ROOT of
every other device, and pushes them back to the server. FIXED 2026-09-08.**
Pre-existing, traced
by public-html-0e on 2026-09-08 and reproduced here on the committed tree with
nothing local involved. One device, a server folder fully synced, the server
trashes it. The device rescues the file out beside the folder, tells the user it
*had not reached the server yet* -- which is false -- and the next pass uploads
it as new content, so the original is deleted server-side and a loose copy
appears at the top of the tree on every device. `assert_converged` passes
throughout, because the resurrected copy is on both sides: the custody blind
spot again.

The cause is one line. `is_on_the_server` asks `entity_for_file_id`, which reads
`local_index`; the scan rehashes whatever it cannot vouch for and calls
`cache_hash(fp, sha, None, now)`, whose upsert sets
`entity_type = excluded.entity_type` -- NULL over the entity a download recorded
moments earlier. It needs no unusual timing on a real disk: the download caches
at `now_ms` floored to milliseconds while the mtime is in nanoseconds, so any
download whose cache write lands in the same millisecond as its own write is
clobbered.

**FIXED**: the upsert now `COALESCE`s the incoming NULL, so a caller that does
not know the entity cannot forget one. Pinned by
`a_folder_the_server_trashed_takes_its_contents_with_it`, which asserts the disk
is empty, the server is empty, and no `rescued_from_trash` issue was raised --
red without the fix on all three.

**What this nearly became, recorded because the reasoning is the lesson.** With
the index made honest and the folder arm's ownership guard NOT yet in,
`a_second_folder_conflict_at_one_name_gets_its_own_name` goes red with `f3.txt
was displaced out of existence` -- destroyed on the disk AND on the server, with
a further settle converging on the loss. That was written up here as a separate
and more serious defect, on the strength of the failure alone. It was not one.
The cause is the missing guard: a `DuplicateName` park runs the folder arm on a
directory that is not that entry's, an honest index correctly tells the rescue
there is nothing to carry out, and the park then trashes a directory whose
contents are not what the records say. Attributed by measuring one variable at a
time -- guard out: red; guard in: green -- and confirmed independently by
public-html-0e on a fresh copy by making the guard's slot test never true.

A red test proves a failure, not its cause. The entry that was opened for the
cause has been withdrawn rather than left in this list for somebody to chase.

**B8 -- a device reports itself settled while holding a file the server never
got.** Found by estate v38, arm `hostilename-kill`, seed 34121769 (1 of 300).
PRE-EXISTING: reproduces identically on the committed engine with B2, B6 and the
oracle change all reverted, so it is not caused by any of them -- it is a fresh
seed shift reaching it, not a regression. NOT FIXED, and only partly
characterised.

Reproduce:

    SEED=34121769 STEPS=40 DEVS=2 CHAOS=1 KILLS=1 NAMECLASS=hostile \
      PLATFORMS=linux,linux cargo test -p jd-sim --release --test zz_sweep \
      -- --ignored --exact scratch_one

`laptop did not converge with the server; only on the disk: ["Sub 23
renamed/in-6-laptop.txt"]`. The device has no queued work and believes it is
finished, so this is a SILENT divergence: the user's file lives on one machine
only, and the client says everything is synced.

**Both sides, asked directly.** The device's entry 902 says `in-6-laptop.txt`,
`status = Synced`, `synced_content` present, `remote_deleted` FALSE, agreed and
remote-placed under folder 508. The server, asked about the same two entities in
the same run, says folder 508 is alive at the root -- and that file 902 is
`deleted: true`, under folder **501**.

So the record disagrees with the server about two separate things at once, and
believes it agrees about both:

- **the deletion was never absorbed** -- the server trashed the file, the device
  still has `remote_deleted = false`;
- **the parent** -- the device has it under 508, the server under 501.

That makes this a lost-deletion race rather than a missing upload, and explains
why nothing recovers: no upload is ever planned for a record that already claims
to be agreed, and no deletion is ever applied for a record that does not know
about one. The engine HAS a concept for this collision -- `DeleteLostToEdit` is
raised elsewhere -- and here nothing was raised at all. The file is not
re-uploaded, not trashed locally, and not complained about: it simply lives on
one machine while the client reports itself finished.

Kills are in the arm, so the likely shape is a feed event lost across a death
and never re-derived, but that last step is inferred and not traced.

**B3 -- `download` has no parent-materialized gate and MINTS the parent
directory.** `create_local_folder` and `move_local` both refuse to act when the
parent is not on this disk; `download` does not, and committing a file creates
its missing parents under whatever name the parent record wears. This is the
file half of Defect AB -- reachable with a merely `PendingDownload` parent, not
only an `Unsyncable` one -- and it is what the abandoned third AC fix was
unknowingly reading.

## Windows, on a real NTFS disk

A Windows 11 ARM64 test VM (UTM on the Mac mini; see the memory note
`reference_windows_test_vm_on_mini`) first built the workspace on 2026-09-03.
The `cfg(windows)` paths in jd-vfs and jd-platform had never been compiled
anywhere. The whole workspace builds natively with no errors or warnings, and
every crate's own tests pass there: jd-vfs on real NTFS, jd-core,
jd-platform, jd-proto, jd-crypto, jd-daemon, jd-shell.

**Defect, found by the jd-platform tests on Windows, fixed in
`jd-platform/src/control.rs`** (two roots, one fix):

- The control server read through a `try_clone` of the socket. On Windows a
  read timeout belongs to the handle it was set on, not to the connection,
  so the 200 ms drain timeout set on the original never reached the reader,
  and a caller claiming four gigabytes held the control thread for the full
  15 s serve timeout. One socket now, read and written through the same
  handle.
- The body drain stopped at the 64 KB request cap, so an over-cap body left
  its tail unread, the close sent RST, and Windows (like macOS) discarded
  the refusal already in the client's buffer: the refusal arrived as no
  answer, which every caller reads as "the daemon is not running". The drain
  is bounded by the wall-clock deadline alone, which is the only bound that
  serves its purpose.

Pinned by the existing jd-platform control tests, which fail on Windows
without it and pass on both operating systems with it. The Windows toolchain
needs the VC LLVM Clang component (ring assembles its ARM64 code with clang);
the mini's `post-install.ps1` includes it.

**Live pairing, 2026-09-04.** The VM's daemon is linked to
dev.getjoinery.com as device `WINTEST` (admin account, plaintext only) and
runs under a full-logon scheduled task so the Credential Manager holds the
keys. Probes against the real disk and the real server, all as designed with
nothing raised:

- Files created on NTFS upload; a file on the server downloads.
- Server renames to names Windows cannot hold land escaped on NTFS
  (`CON.docx` → `%43ON.docx`, `hello:from windows.txt` →
  `hello%3Afrom windows.txt`), no issue raised, status up to date.
- A local rename of an escaped file to a plain name reaches the server as
  that plain name.
- A folder chain past the 260-character limit (356 characters) is created on
  NTFS through extended-length paths, and a file written inside it uploads.
- A file held open with no sharing while the server renames it: the daemon
  reports "Syncing — 1 to go" and waits, and the rename lands when the lock
  releases.
- A case-only rename on the case-insensitive disk (`Report Q3.docx` →
  `report q3.docx`) reaches the server as the new spelling.
- An EICAR test file written into the sync root with Defender's real-time
  protection on was not quarantined and uploaded like any other file; no
  Defender interference was observed in this session.

With a second real device (`DEVBOX-LINUX`, a Linux daemon on the dev box
linked to the same account): an edit made on Linux arrives on Windows; a
file deleted on Linux goes to the Windows Recycle Bin (the shell API reached
through the extended-length path conversion); files named `CON.txt` and
`a:b.txt` created on Linux land on Windows as `%43ON.txt` and `a%3Ab.txt`.
The reverse holds too: an edit, a delete of an escaped-name file, and a rename
of one made on Windows reach Linux as the plain names. A file edited on both
devices in the same window keeps both versions, the loser beside the winner
as `… (conflicted copy 2026-09-04 from WINTEST).txt`, and the Windows daemon
raises the conflict as an issue. The pair (`WINTEST` on the VM,
`DEVBOX-LINUX` on the dev box) stays linked for further real-disk work.

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

- **The planner remembers one reason to wait, and there are three.**
  `waits_for` is one blocker per mover, and three kinds of edge now write to
  it: the slot the mover is moving into is occupied, its destination folder is
  being created this round, and the folder it is moving into is still inside
  it. Later edges overwrite earlier ones (ancestry over create over slot), so
  a mover under two constraints keeps one. Bounded -- the executor refuses an
  arrival at an occupied name rather than overwriting anything, and the next
  round derives the ordering again -- but the order produced is not the order
  the code reads as producing, and the comment that said one edge was the whole
  graph has been corrected rather than left to mislead. The fix is a multi-edge
  map: ranks take the highest blocker, and the cycle search walks a graph
  instead of a chain. Held back as its own change, because it is surgery on the
  ordering core and an estate cannot tell its faults from a Z fault if both
  land together.

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


---

## NEXT SESSION STARTS HERE (2026-09-11, overnight)

**What changed tonight.** Defect AJ was found, traced end to end, and bounded.
It is the clean ring arm's entire leak -- 11 seeds in 40 that this campaign has
quoted for weeks without anyone asking what the verb was. It publishes sealed
PLAINTEXT and the real FILENAME, needs no faults, and is not AH.

**Standing state of the three open defects:**

    AI   version histories   guard measured, 324 -> 132 on 100 unseen seeds,
                             0 regressions.  OWNER DECISION pending.
    AJ   vault disclosure    4 guards measured; only the rescue net is clean
                             (0 regressions, 13 -> 11 disclosures). Real fix
                             designed, NOT built.
    AF   the vault leak      hostile arm 19-22 red. Untouched. The hold at
                             the crossing is still owed.

**The one thing to do first next session**, because everything else is
downstream of it: build the recovery path AJ needs -- when the local index can
NAME the file (`link=Some(id)`, `entry=None`), re-derive the entry from the
server instead of minting a second identity or refusing. Both alternatives are
measured and both are wrong: minting publishes, refusing strands. This was not
built overnight on purpose, because a store-repair path invented without an
owner awake is how a sync engine learns to resurrect files nobody asked for.

**Do not repeat these.** Five shapes of ordinary folder renaming are GREEN, so
AJ is not "renaming a folder publishes your vault". The one-scan hold is dead
(zero of the residue is mid-swap). The strict AH variant buys nothing. `mine2`
deletes hardlinked files.

**Read every probe before quoting its number.** Three separate findings this
session came from a measurement whose NEGATIVE answer and whose ABSENT answer
were the same value, and a fourth from counting upload attempts as disclosures.
